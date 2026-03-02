<?php
/**
 * Single-file app: Article Generator + Translator (PHP 8.3 + HTML/CSS/JS)
 * -----------------------------------------------------------------------------
 * What this does (high level):
 * - Upload 2 JSON files:
 *   A) "articles" JSON: { "meta": {...}, "articles": [...] }
 *   B) "topics" JSON:   { "batch_no":..., "artykuly":[{index,tytul,opis_ogolny,opis_szczegolowy}, ...] }
 * - Paste OpenAI API key (kept ONLY in PHP session), auto-test it, show green/red status with diagnostics.
 * - Click Start to generate:
 *   - WRITE_PL with reasoning effort "medium" and max_output_tokens 12000
 *   - TRANSLATE per language with effort "low" and max_output_tokens 6000
 * - Saves progress after each successful step to disk, so refresh/resume works.
 *
 * Notes on OpenAI API usage:
 * - Uses Responses API: POST https://api.openai.com/v1/responses
 * - Sets reasoning.effort and max_output_tokens (official docs show these fields). citeturn1view0turn2search1turn2search4
 *
 * IMPORTANT:
 * - This file is intended as a working baseline. You may want to harden security, add auth, and tune prompts.
 */

declare(strict_types=1);
session_start();
// Release PHP session lock early for read-only AJAX actions.
// PHP file sessions are exclusive: any request holding the session (e.g. run_step during a 60s API call)
// blocks ALL other requests from the same browser — including previews.
$_ro = ['get_articles','get_article_preview','get_article_full','get_current_prompt','get_status','get_topic_prompt','force_skip_translation'];
if (isset($_GET['action']) && in_array($_GET['action'], $_ro, true)) {
    session_write_close();
}

header_remove('X-Powered-By');

const APP_VERSION = '1.0.0';
const STATE_FILE = APP_ROOT . '/state.json';
const LOCK_FILE  = APP_ROOT . '/.app.lock';
const SKIP_SIGNAL_FILE  = APP_ROOT . '/.skip_translation';
const BACKUP_FILE       = APP_ROOT . '/deleted_articles.json';

function now_iso(): string {
  return gmdate('c');
}

function json_response($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json_file(string $path): array {
  if (!is_file($path)) {
    throw new RuntimeException("File not found: {$path}");
  }
  $raw = file_get_contents($path);
  if ($raw === false) throw new RuntimeException("Unable to read file: {$path}");
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    $err = json_last_error_msg();
    throw new RuntimeException("Invalid JSON in {$path}: {$err}");
  }
  return $data;
}

/**
 * Read a topics file that may contain one OR multiple concatenated JSON objects.
 * Each object must have: { "artykuly": [...] }
 * All artykuly arrays are merged into a single unified array.
 */
function read_topics_file(string $path): array {
  if (!is_file($path)) throw new RuntimeException("File not found: {$path}");
  $raw = file_get_contents($path);
  if ($raw === false) throw new RuntimeException("Unable to read file: {$path}");

  $objects = [];
  $pos     = 0;
  $len     = strlen($raw);

  while ($pos < $len) {
    // skip whitespace
    while ($pos < $len && ctype_space($raw[$pos])) $pos++;
    if ($pos >= $len) break;

    // extract one JSON object manually (brace-counting, string-aware)
    $start  = strpos($raw, '{', $pos);
    if ($start === false) break;

    $depth   = 0;
    $inStr   = false;
    $escape  = false;
    $end     = null;

    for ($i = $start; $i < $len; $i++) {
      $ch = $raw[$i];
      if ($inStr) {
        if ($escape)        { $escape = false; continue; }
        if ($ch === '\\')   { $escape = true;  continue; }
        if ($ch === '"')    { $inStr  = false;  continue; }
        continue;
      }
      if ($ch === '"')  { $inStr = true;  continue; }
      if ($ch === '{')  { $depth++;        continue; }
      if ($ch === '}') {
        $depth--;
        if ($depth === 0) { $end = $i; break; }
      }
    }

    if ($end === null) break; // malformed remainder

    $chunk  = substr($raw, $start, $end - $start + 1);
    $parsed = json_decode($chunk, true);
    if (is_array($parsed)) {
      $objects[] = $parsed;
    }
    $pos = $end + 1;
  }

  if (!$objects) {
    throw new RuntimeException("No valid JSON object found in topics file: {$path}");
  }

  // Merge all artykuly arrays into one object
  $merged = $objects[0];
  if (count($objects) > 1) {
    foreach (array_slice($objects, 1) as $obj) {
      if (isset($obj['artykuly']) && is_array($obj['artykuly'])) {
        $merged['artykuly'] = array_merge($merged['artykuly'] ?? [], $obj['artykuly']);
      }
    }
    // Reflect merged totals
    $merged['batch_count'] = count($objects);
  }

  return $merged;
}

function write_json_atomic(string $path, array $data): void {
  $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException("json_encode failed: " . json_last_error_msg());
  if (file_put_contents($tmp, $json) === false) throw new RuntimeException("Failed to write temp file: {$tmp}");
  // verify we can read it back
  $check = json_decode(file_get_contents($tmp), true);
  if (!is_array($check)) {
    @unlink($tmp);
    throw new RuntimeException("Atomic write verification failed for: {$path}");
  }
  // backup previous
  if (is_file($path)) {
    @copy($path, $path . '.backup.json');
  }
  if (!rename($tmp, $path)) {
    @unlink($tmp);
    throw new RuntimeException("Failed to replace file: {$path}");
  }
}

function with_lock(callable $fn) {
  $fp = fopen(LOCK_FILE, 'c+');
  if (!$fp) throw new RuntimeException("Unable to open lock file.");
  try {
    if (!flock($fp, LOCK_EX)) throw new RuntimeException("Unable to acquire lock.");
    $res = $fn();
    flock($fp, LOCK_UN);
    fclose($fp);
    return $res;
  } catch (Throwable $e) {
    flock($fp, LOCK_UN);
    fclose($fp);
    throw $e;
  }
}

function default_prompts(): array {
  $write = <<<PROMPT
SYSTEM / DEVELOPER CONTEXT (do not output): You generate a Polish article for healthcare professionals. Output MUST be valid JSON only.
========================
INPUT VARIABLES (provided by app)
========================
ARTICLE_ID: {{ARTICLE_ID}} (string, 5 digits e.g. "00037")
TRANSLATION_GROUP: {{TRANSLATION_GROUP}} (string e.g. "pll_abcd1234...")
TOPIC_INDEX: {{TOPIC_INDEX}} (number)
TOPIC_TITLE: {{TOPIC_TITLE}} (string)
TOPIC_SHORT_DESC: {{TOPIC_SHORT_DESC}} (string)
TOPIC_LONG_DESC: {{TOPIC_LONG_DESC}} (string; may include element_1... and sources:)
========================
GOAL
========================
Write ONE new Polish (pl) article for a medical worker (doctor / nurse / healthcare staff) based on the topic. Make it practical and structured. Focus on behaviors, scripts, and small steps that work under time pressure.
IMPORTANT:
- The app will insert <section class="warto-zapamietac"> later. So DO NOT output that section at all.
- You MUST choose ONE category from the list below and output it in JSON (categories[]).
========================
HARD OUTPUT RULES (non-negotiable)
========================
1) Output ONLY JSON. No Markdown. No explanations. No comments.
2) JSON must be parseable and complete.
3) Keep the JSON schema exactly as required (keys, nesting, types).
4) All text must be Polish (pl).
5) Do NOT paste the "sources:" list or raw links into content_html.
6) Do NOT claim medical diagnosis, treatment decisions, or therapy. Keep it educational and communication-focused.
7) Do NOT claim Empatyzer is used for recruitment, performance evaluation, or therapy.
STYLE (overall)
========================
- Write in simple, plain Polish that is easy to understand for most readers.
- Avoid jargon where possible; if a term is necessary, explain it briefly in normal words.
- This is an educational article (third-person, instructional). Do NOT write as "I, as a doctor/nurse...".
========================
CATEGORY SELECTION (choose ONE)
========================
Pick the best matching category for this article and output it in categories[0].
Allowed categories (slug => name):
- komunikacja-lekarz-pacjent => komunikacja lekarz-pacjent
- trudne-rozmowy             => trudne rozmowy
- edukacja-pacjenta          => edukacja pacjenta
- telemedycyna               => telemedycyna
- komunikacja-w-zespole      => komunikacja w zespole
- bezpieczenstwo-komunikacji => bezpieczenstwo komunikacji
- komunikacja-i-bledy-medyczne => komunikacja i bledy medyczne
- empatia-na-uniwersytetach-medycznych => empatia na uniwersytetach medycznych
Rules:
- Output EXACTLY one category object in categories array.
- Use ONLY the slugs above and EXACT names above (do not invent).
========================
SEO GUIDELINES (use ranges; do not be overly strict)
========================
- Article title (translations.pl.title):
  * target: 50-120 characters
  * hard max: 120 characters
- SEO meta title (translations.pl.seo.meta_title):
  * target: 50-60 characters
  * hard max: 65 characters
- SEO meta description (translations.pl.seo.meta_description):
  * target: 135-155 characters
  * hard max: 170 characters
- SEO-friendly URL slug (translations.pl.seo_friendly_url):
  * target: 25-60 characters
  * lowercase + digits + hyphens only
  * no Polish diacritics (transliterate)
  * avoid filler words when possible
If you are slightly outside the target range, prefer clarity and correctness.
Still try to stay close to targets.
========================
ARTICLE STRUCTURE (HTML) - REQUIRED SHAPE
========================
content_html must include, in this order:
1) H1 title at the top (same meaning as translations.pl.title):
<h1>...</h1>
- title should clearly show that this is a topic related to the context of health, doctor-patient communication, empathy in medicine, etc.
2) TL;DR section (this is the ONLY <section> tag you should use):
<section class="tldr">
  <p><strong>TL;DR:</strong> [2-4 sentences: what the article is about, for medical staff]</p>
  <ul>
    <li>[bullet]</li>
    <li>[bullet]</li>
    ...
  </ul>
</section>
TL;DR guidelines:
- 2-4 sentences in the TL;DR paragraph.
- 3-6 bullets in the list.
- Each bullet: short, concrete, 5-12 words.
3) Main body (NO <section> wrappers here - just h2+p pairs directly):
Use 3-6 blocks (recommended: ~5).
Each block must be exactly:
<h2>[Specific subheading]</h2>
<p>[ONE paragraph, recommended 6-8 sentences; compact and practical]</p>
Guidelines:
- Prefer 4-6 blocks if the topic is rich; 3 is acceptable if topic is narrow.
- Each H2 must be specific and tied to the topic (avoid generic like "Podsumowanie").
- Each paragraph should focus on one idea and end with a clear takeaway.
4) Optional summary paragraph (recommended, not mandatory):
<p class="summary">[4-7 sentences summarizing the most important takeaways]</p>
5) Final Empatyzer block (required; NO <section> wrapper - just h2+p):
<h2>Empatyzer - [topic-specific phrasing about how Empatyzer can help with this particular topic]</h2>
<p>[6-8 sentences, see Empatyzer facts and realism rules below]</p>
Empatyzer heading rules:
- Must include the word "Empatyzer".
- Must be directly connected to the article problem (reuse 1-3 key words from TOPIC_TITLE / TOPIC_SHORT_DESC).
- MUST NOT be generic like: "Empatyzer - jak moze pomoc", "Empatyzer - jak moze pomoc w tym temacie".
- Make it concrete, e.g. "Empatyzer a domykanie planu po wizycie", "Empatyzer w pracy z napieciem i konfliktem", etc.
Empatyzer paragraph rules (important):
- 6-8 sentences (this is the only "strict" sentence rule).
- Write from the perspective of medical staff working in an organization (hospital/clinic).
- Tie it to the topic: explain realistic help related to the same problem.
- Empatyzer helps mainly through team communication and self-awareness; patient impact is indirect.
- You MAY say it helps prepare conversations and reduce friction, but do not claim it replaces clinical training or gives medical advice.
Empatyzer paragraph - CONTENT STRATEGY (critical):
- Do NOT repeat or list all Empatyzer functionalities "one by one".
- Choose ONLY the 1-2 most relevant capabilities for this specific case and explain HOW they help in this exact situation.
- Always lead with the AI assistant "Em" (24/7) as the main, practical help: preparing conversations, phrasing support, de-escalation, scripts, next steps under stress.
- Mention personal diagnosis only as a supporting foundation (self-understanding, preferences, patterns) and use it only if it strengthens the topic fit.
- If relevant, briefly mention comparing oneself to team/department (aggregated view, what stands out) as context for better cooperation - but only when it directly helps the topic.
- Micro-lessons are supportive: mention them briefly as reinforcement of habits, not as the main point.
- If you add "more", do it as a short add-on ("Dodatkowo..."), without expanding into a full feature list.
========================
CONTENT REQUIREMENTS
========================
- Use TOPIC_LONG_DESC as the backbone. If it contains element_1..n, convert them into your H2 blocks.
- Practical style: concrete behaviors, short scripts, checklists, "what to say/do".
- Keep the reader in mind: medical worker under time pressure, often with limited time per patient.
- Avoid fluff and motivational filler. Use calm, professional language.
- Do not include raw "sources" links.
========================
EMPATYZER FACTS (use realistically; do not contradict)
========================
What Empatyzer is:
- A multilingual SaaS assistant based on psychometrics + AI, used long-term (pilot usually full year support).
- It supports communication and cooperation at work (teams, departments, whole organizations).
Core components:
- Personal diagnosis (personality patterns, motivators, culture/work style, communication preferences).
- Short micro-lessons 2x/week: Self Awareness + Soft Skills (actionable habits).
- AI assistant "Em" available 24/7 for practical hyper-personalised coaching (personalised to both the user AND the specific person/team/department they are working with), phrasing support, preparing difficult conversations, personal development and self-understanding.
Privacy & limits (must be reflected):
- Privacy-by-design: organization sees aggregated results only.
- Not used for recruitment, performance evaluation, or therapy.
- Quick start, no heavy integrations; EU/AWS; no training public models on client data.
- Pilot usually ~180 days.
How to describe Empatyzer in medical context (realism rules):
- Primary value: clearer cooperation inside the medical team (handoffs, feedback, 1:1, conflict de-escalation, shared expectations).
- Secondary / indirect value: when staff communicate better internally and build cognitive empathy, patient communication tends to become calmer, clearer, and less tense.
- Do not claim "Empatyzer tells you exactly what to say to a patient in clinical terms".
- You can claim it supports practicing communication habits and building self-awareness that helps under stress.
========================
OUTPUT JSON SCHEMA (MUST MATCH)
========================
Return ONE object with keys: article_id, translation_group, categories, translations
- article_id: use ARTICLE_ID exactly
- translation_group: use TRANSLATION_GROUP exactly
- categories: array with ONE object: { "slug": "<one of allowed slugs>", "name": "<matching name>" }
translations MUST include keys: pl, en, de, fr, it, cs, es
For translations.pl:
{
  "lang": "pl",
  "wp_id": null,
  "title": "...",
  "slug_old": null,
  "url_old": null,
  "seo_friendly_url": "...",
  "excerpt_html": "",
  "content_html": "...",
  "seo": { "meta_title": "...", "meta_description": "..." },
  "metrics": { "words": 0, "chars": 0 }
}
For placeholders translations.en/de/fr/it/cs/es:
Keep same keys/shape, but set:
- "lang": "<target lang code>"
- wp_id: null
- title: null
- slug_old: null
- url_old: null
- seo_friendly_url: null
- excerpt_html: ""
- content_html: null
- seo: { "meta_title": null, "meta_description": null }
- metrics: { "words": 0, "chars": 0 }
========================
FINAL SELF-CHECK (quick)
========================
- JSON only, parseable.
- categories[0] uses allowed slug+name.
- No <section class="warto-zapamietac"> (the app inserts it later).
- H1 + TL;DR + 3-6 H2+P blocks (+ optional summary) + Empatyzer H2+P.
- Empatyzer paragraph is 6-8 sentences, topic-fitted (selected features only), and realistic.
- meta_description <= 170 chars; title/meta_title not too long.
UWAGA: pamiętaj, pisz po polsku, unikaj anglicyzów, unikaj angielskich kalek, pisz tak jak pisałby Polak, pisz zrozumiale, w prosty sposób - nie skracaj zdań, pisz swobodnie, prostym językiem, unikaj specjalistycznego żargonu. Tytuł musi mieć słowa, które jasno dają do zrozumienia, ze to jest temat medyczny. zawsze po polsku np.: zamiast tech-back mów -> powtórzenie własnymi słowami (parafraza), a zamiast safety net -> zabezpieczeniem na wypadek pogorszenia (planem awaryjnym). Każdy profesjonalny skrót rozwiń i wytłumacz w nawiasie. Nigdy nie zakładaj, że czytelnik rozumie kontekst artykulu. Zawsze daj w TLDR jedno zdanie kontekstu i wytluamczenia o co w ogóle chodzi. A potem w pierwszym zdaniu pierwszego akapitu rozwin to wprowadzenie, zeby bylo dla kazdego jasne o czym rozmawiamy
PROMPT;

  $translate = <<<PROMPT
SYSTEM / DEVELOPER CONTEXT (do not output):
You localize Polish content into a target language as if it was originally written by a native speaker. Output MUST be valid JSON only.

========================
INPUT VARIABLES (provided by app)
========================
TARGET_LANG: {{TARGET_LANG}}              (one of: "en","de","fr","it","cs","es")

SOURCE_PL_JSON: {{SOURCE_PL_JSON}}
(This is the full object translations.pl with keys:
lang, wp_id, title, slug_old, url_old, seo_friendly_url, excerpt_html, content_html, seo{meta_title,meta_description}, metrics{words,chars})

========================
GOAL (IMPORTANT — READ CAREFULLY)
========================
Produce a TARGET_LANG version that reads like it was originally written in TARGET_LANG by a native professional writer.

This is NOT a literal translation.
This is localization + native rewrite:
- Keep meaning, intent, and structure.
- Rewrite sentences so they sound natural in TARGET_LANG.
- Use idioms, word order, and phrasing typical for native TARGET_LANG writing.
- Avoid Polish sentence rhythm, Polish connectors, and Polish-style punctuation mapped into TARGET_LANG.

IMPORTANT: The app inserts <section class="warto-zapamietac"> later.
SOURCE_PL_JSON.content_html does NOT include that section. DO NOT add it.

========================
ANTI-CALQUE REQUIREMENT (non-negotiable)
========================
You must actively prevent “Polish-looking” text in TARGET_LANG.
Do not mirror:
- Polish word order,
- Polish sentence length patterns,
- Polish connector phrases copied into TARGET_LANG,
- unnatural literal equivalents,
- stiff, “schoolbook” translation style.

Quality test (do mentally before output):
"If a native editor read this, would they believe it was written originally in TARGET_LANG?"
If not, rewrite until yes.

========================
HARD OUTPUT RULES (non-negotiable)
========================
1) Output ONLY JSON. No Markdown. No explanations. No comments.
2) JSON must be parseable and complete.
3) Output MUST be a full translation object for TARGET_LANG with the exact keys required (same shape as translations.pl).
4) Do NOT change HTML structure: keep the same tags, attributes, classes, and ordering.
   - Keep <h1>, <section class="tldr">, <h2>, <p>, <ul>, <li> exactly as in the source.
   - Rewrite/translate ONLY the visible text nodes inside these tags.
5) Do NOT add or remove sections, headings, bullets, or paragraphs.
6) Do NOT include any raw "sources" links.
7) Keep compliance: no medical advice, no therapy claims, no recruitment/evaluation claims for Empatyzer.

========================
STRUCTURE LOCK (keep counts exactly)
========================
- Keep the same number of TL;DR sentences as in the source.
- Keep the same number of TL;DR bullets as in the source.
- Keep the same number of <section> blocks, H2 headings, and paragraphs as in the source.

========================
SEO GUIDELINES (keep close; do not bloat)
========================
You must localize:
- title
- seo.meta_title
- seo.meta_description
- seo_friendly_url (slug)

Targets:
- title:
  * target: 50–60 characters
  * hard max: 65
- seo.meta_title:
  * target: 50–60 characters
  * hard max: 65
- seo.meta_description:
  * target: 135–155 characters
  * hard max: 170
- seo_friendly_url (slug):
  * target: 25–60 characters
  * lowercase + digits + hyphens only
  * no diacritics
  * should read like a natural SEO slug in TARGET_LANG (not a Polish calque)

If a natural native version would exceed limits:
- shorten smartly while keeping the core promise.
- prefer clarity and idiomatic phrasing.

========================
HTML LOCALIZATION RULES
========================
Translate/rewrite the text inside:
- <h1>...</h1>
- TL;DR label and paragraph (you may localize “TL;DR:” label to a natural equivalent in TARGET_LANG)
- TL;DR bullets
- all <h2> headings
- all body <p> paragraphs
- Empatyzer section heading and paragraph

Keep:
- HTML tags
- class names
- quotes and attributes
Exactly as in the source.

========================
EMPATYZER CONSISTENCY (do not contradict)
========================
Empatyzer supports communication and cooperation at work (teams, departments, organizations).
Primary: internal teamwork/communication habits; patient benefit is indirect.
It provides:
- personal diagnosis (personality patterns, motivators, culture/work style, communication preferences)
- micro-lessons 2x/week (Self Awareness + Soft Skills)
- AI assistant "Em" 24/7 for practical coaching and preparing difficult conversations
Privacy & limits:
- aggregated results for the organization; employer does not see conversations
- not for recruitment, evaluation, or therapy
- quick start, no heavy integrations; EU/AWS; no training public models on client data
Pilot usually ~180 days.

When localizing Empatyzer paragraph:
- keep it realistic and aligned to the article topic,
- do not claim it replaces training or gives clinical advice.

========================
OUTPUT JSON SCHEMA (MUST MATCH EXACTLY)
========================
Return ONE object:

{
  "lang": "<TARGET_LANG>",
  "wp_id": null,
  "title": "...",
  "slug_old": null,
  "url_old": null,
  "seo_friendly_url": "...",
  "excerpt_html": "",
  "content_html": "...(same HTML structure; localized native text only)...",
  "seo": {
    "meta_title": "...",
    "meta_description": "..."
  },
  "metrics": {
    "words": 0,
    "chars": 0
  }
}
PROMPT;

  return ['write_pl' => $write, 'translate_lang' => $translate];
}

function default_state(): array {
  $p = default_prompts();
  return [
    'app' => [
      'version' => APP_VERSION,
      'created_at' => now_iso(),
    ],
    'files' => [
      'articles_path' => null,
      'topics_path' => null,
      'articles_original' => null,
      'topics_original' => null,
    ],
    'config' => [
      'model_write' => 'gpt-5',         // you can change to gpt-5.0 if your account supports it
      'model_translate' => 'gpt-5',
      'write_reasoning_effort' => 'medium',
      'translate_reasoning_effort' => 'low',
      'write_max_output_tokens' => 12000,
      'translate_max_output_tokens' => 6000,
      'language_order' => ['en','de','fr','it','cs','es'],
      'prompt_write_pl' => $p['write_pl'],
      'prompt_translate_lang' => $p['translate_lang'],
    ],
    'runtime' => [
      'status' => 'idle', // idle|running|paused|stopped|waiting_user_decision|done
      'current_topic_index' => null,
      'current_topic_uid' => null,
      'current_topic_title' => null,
      'current_article_id' => null,
      'current_stage' => null, // WRITE_PL|TRANSLATE_EN|...
      'current_lang' => null,
      'last_action' => null,
      'last_error' => null,
      'last_output_preview' => null,
      'current_step_start_ts' => null,
      'current_prompt' => null,
      'current_prompt_vars' => null,
      'retry_count' => 0,
      'auto_retry_limit' => 1,
      'completed_topics' => [], // list of topic UIDs
      'skipped_topics' => [],
      'skipped_langs' => [], // map article_id => [lang=>true]
      'rewrite_queue' => [], // [{orig_article_id, title, notes, orig_pl_json, topic_index}]
      'current_is_rewrite' => false,
      'phase' => 'write', // write | translate
    ],
    'metrics' => [
      'global_done'  => 0,
      'global_total' => null,
      'timing'       => [
        'write_times'     => [],
        'translate_times' => [],
        'avg_write'       => null,
        'avg_translate'   => null,
      ],
    ],
    'log' => [],
  ];
}

function load_state(): array {
  if (!is_file(STATE_FILE)) return default_state();
  $raw = file_get_contents(STATE_FILE);
  if ($raw === false) return default_state();
  $data = json_decode($raw, true);
  if (!is_array($data)) return default_state();
  // merge with defaults for new keys
  $def = default_state();
  return array_replace_recursive($def, $data);
}

function save_state(array $state): void {
  write_json_atomic(STATE_FILE, $state);
}

function log_event(array &$state, string $level, string $scope, string $message, array $extra = []): void {
  $entry = array_merge([
    'ts' => now_iso(),
    'level' => $level, // info|success|warn|error
    'scope' => $scope, // global|sprint|action
    'message' => $message,
  ], $extra);
  $state['log'][] = $entry;
  // keep last 400
  if (count($state['log']) > 400) {
    $state['log'] = array_slice($state['log'], -400);
  }
}

function sanitize_filename(string $name): string {
  $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
  $name = trim($name, '._-');
  if ($name === '') $name = 'file_' . bin2hex(random_bytes(3)) . '.json';
  return $name;
}

function extract_output_text(array $response): string {
  // Responses API typically returns output items. We try to extract all output_text.
  $texts = [];
  if (isset($response['output']) && is_array($response['output'])) {
    foreach ($response['output'] as $item) {
      if (!is_array($item)) continue;
      if (($item['type'] ?? null) === 'message' && isset($item['content']) && is_array($item['content'])) {
        foreach ($item['content'] as $c) {
          if (is_array($c) && ($c['type'] ?? null) === 'output_text' && isset($c['text'])) {
            $texts[] = (string)$c['text'];
          }
        }
      }
    }
  }
  // fallback common fields
  if (!$texts && isset($response['output_text'])) {
    $texts[] = (string)$response['output_text'];
  }
  return trim(implode("\n", $texts));
}

function find_first_json_object(string $text): ?string {
  // Best-effort extraction of a JSON object from the model output.
  $start = strpos($text, '{');
  if ($start === false) return null;
  $substr = substr($text, $start);

  $depth = 0;
  $inStr = false;
  $escape = false;
  $len = strlen($substr);
  for ($i=0; $i<$len; $i++) {
    $ch = $substr[$i];
    if ($inStr) {
      if ($escape) { $escape = false; continue; }
      if ($ch === '\\') { $escape = true; continue; }
      if ($ch === '"') { $inStr = false; continue; }
      continue;
    } else {
      if ($ch === '"') { $inStr = true; continue; }
      if ($ch === '{') $depth++;
      if ($ch === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($substr, 0, $i+1);
        }
      }
    }
  }
  return null;
}

function openai_post_responses(string $apiKey, array $payload): array {
  $ch = curl_init('https://api.openai.com/v1/responses');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_CONNECTTIMEOUT => 15,
  ]);

  $tStart   = microtime(true);
  $body     = curl_exec($ch);
  $elapsed  = round(microtime(true) - $tStart, 2);

  $curlErrNo  = curl_errno($ch);
  $curlErrStr = curl_error($ch);
  $code       = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $connTime   = round((float)curl_getinfo($ch, CURLINFO_CONNECT_TIME), 3);
  $totalTime  = round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3);
  curl_close($ch);

  if ($body === false || $curlErrNo !== 0) {
    throw new RuntimeException(
      "cURL failed [errno={$curlErrNo}]: {$curlErrStr} " .
      "(connect={$connTime}s total={$totalTime}s elapsed={$elapsed}s)"
    );
  }

  $json = json_decode($body, true);
  if (!is_array($json)) {
    $bodyPreview = mb_substr($body, 0, 600);
    throw new RuntimeException(
      "OpenAI returned non-JSON. HTTP={$code} elapsed={$elapsed}s. " .
      "json_err=" . json_last_error_msg() . ". Body: {$bodyPreview}"
    );
  }

  if ($code >= 400) {
    $errType    = $json['error']['type']    ?? 'unknown_type';
    $errCode    = $json['error']['code']    ?? 'no_code';
    $errMsg     = $json['error']['message'] ?? "HTTP {$code}";
    $errParam   = $json['error']['param']   ?? null;
    $detail = "type={$errType} code={$errCode}" . ($errParam ? " param={$errParam}" : '');
    throw new RuntimeException(
      "OpenAI API error (HTTP {$code}): {$errMsg} [{$detail}] elapsed={$elapsed}s",
      $code
    );
  }

  // attach timing info so callers can log it
  $json['_meta'] = [
    'http_code'    => $code,
    'elapsed_s'    => $elapsed,
    'connect_s'    => $connTime,
    'usage'        => $json['usage'] ?? null,
  ];
  return $json;
}

function compute_metrics_from_html(string $html): array {
  $text = trim(html_entity_decode(strip_tags($html)));
  $chars = mb_strlen($text);
  $words = 0;
  if ($text !== '') {
    $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
  }
  return ['words' => $words, 'chars' => $chars];
}

function validate_pl_article_object(array $obj): array {
  // returns list of issues; empty => ok
  $issues = [];

  foreach (['article_id','translation_group','categories','translations'] as $k) {
    if (!array_key_exists($k, $obj)) $issues[] = "Missing key: {$k}";
  }

  // Validate categories
  $allowedCategorySlugs = [
    'komunikacja-lekarz-pacjent',
    'trudne-rozmowy',
    'edukacja-pacjenta',
    'telemedycyna',
    'komunikacja-w-zespole',
    'bezpieczenstwo-komunikacji',
    'komunikacja-i-bledy-medyczne',
    'empatia-na-uniwersytetach-medycznych',
  ];
  if (!isset($obj['categories']) || !is_array($obj['categories']) || count($obj['categories']) === 0) {
    $issues[] = "categories must be a non-empty array";
  } else {
    $cat = $obj['categories'][0];
    if (!is_array($cat) || empty($cat['slug']) || empty($cat['name'])) {
      $issues[] = "categories[0] must have slug and name";
    } elseif (!in_array($cat['slug'], $allowedCategorySlugs, true)) {
      $issues[] = "categories[0].slug '{$cat['slug']}' is not in the allowed list: " . implode(', ', $allowedCategorySlugs);
    }
  }
  if (!isset($obj['translations']['pl']) || !is_array($obj['translations']['pl'])) {
    $issues[] = "Missing translations.pl object";
    return $issues;
  }
  $pl = $obj['translations']['pl'];
  $requiredPl = ['lang','wp_id','title','slug_old','url_old','seo_friendly_url','excerpt_html','content_html','seo','metrics'];
  foreach ($requiredPl as $k) if (!array_key_exists($k, $pl)) $issues[] = "translations.pl missing: {$k}";
  if (($pl['lang'] ?? null) !== 'pl') $issues[] = "translations.pl.lang must be 'pl'";

  $title = (string)($pl['title'] ?? '');
  if (trim($title) === '') $issues[] = "translations.pl.title empty";

  $seo = $pl['seo'] ?? null;
  if (!is_array($seo)) $issues[] = "translations.pl.seo missing/invalid";
  else {
    $mt = (string)($seo['meta_title'] ?? '');
    $md = (string)($seo['meta_description'] ?? '');
    if (trim($mt) === '') $issues[] = "seo.meta_title empty";
    if (trim($md) === '') $issues[] = "seo.meta_description empty";
    // Length checks are warnings only — reported via seo_report in UI, not blocking
  }

  $slug = (string)($pl['seo_friendly_url'] ?? '');
  if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) $issues[] = "seo_friendly_url invalid (allowed: a-z0-9-)";

  $html = (string)($pl['content_html'] ?? '');
  if (trim($html) === '') $issues[] = "content_html empty";
  else {
    if (strpos($html, '<section class="tldr">') === false) $issues[] = "content_html missing <section class=\"tldr\">";
    if (strpos($html, '<h1>') === false) $issues[] = "content_html missing <h1>";
    if (strpos($html, 'warto-zapamietac') !== false) $issues[] = "content_html must NOT include warto-zapamietac (app inserts later)";
  }

  return $issues;
}

function validate_translation_object(string $lang, array $obj): array {
  $issues = [];
  $required = ['lang','wp_id','title','slug_old','url_old','seo_friendly_url','excerpt_html','content_html','seo','metrics'];
  foreach ($required as $k) if (!array_key_exists($k, $obj)) $issues[] = "Missing key: {$k}";
  if (($obj['lang'] ?? null) !== $lang) $issues[] = "lang mismatch: expected {$lang}";
  $seo = $obj['seo'] ?? null;
  if (!is_array($seo)) $issues[] = "seo missing/invalid";
  else {
    $md = (string)($seo['meta_description'] ?? '');
    if (trim((string)($seo['meta_title'] ?? '')) === '') $issues[] = "seo.meta_title empty";
    if (trim($md) === '') $issues[] = "seo.meta_description empty";
    // Length checks are warnings only — reported via seo_report in UI, not blocking
  }
  $slug = $obj['seo_friendly_url'] ?? null;
  if (!is_string($slug) || $slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) $issues[] = "seo_friendly_url invalid (allowed: a-z0-9-)";
  $html = $obj['content_html'] ?? null;
  if (!is_string($html) || trim($html) === '') $issues[] = "content_html empty";
  else {
    if (strpos($html, '<section class="tldr">') === false) $issues[] = "content_html missing <section class=\"tldr\">";
    if (strpos($html, '<h1>') === false) $issues[] = "content_html missing <h1>";
    if (strpos($html, 'warto-zapamietac') !== false) $issues[] = "content_html must NOT include warto-zapamietac (app inserts later)";
  }
  return $issues;
}

function seo_report(array $translation): array {
  // Produce simple numeric report for UI (lengths vs targets/hard max).
  $title = (string)($translation['title'] ?? '');
  $mt = (string)($translation['seo']['meta_title'] ?? '');
  $md = (string)($translation['seo']['meta_description'] ?? '');
  $slug = (string)($translation['seo_friendly_url'] ?? '');
  return [
    'title_len' => mb_strlen($title),
    'meta_title_len' => mb_strlen($mt),
    'meta_description_len' => mb_strlen($md),
    'slug_len' => mb_strlen($slug),
    'limits' => [
      'title_target' => '50–60',
      'title_hard_max' => 65,
      'meta_title_target' => '50–60',
      'meta_title_hard_max' => 65,
      'meta_description_target' => '135–155',
      'meta_description_hard_max' => 170,
      'slug_target' => '25–60',
      'slug_hard_max' => 80
    ]
  ];
}

function update_timing(array &$state, string $type, float $duration): void {
  $key = $type . '_times'; // write_times | translate_times
  if (!isset($state['metrics']['timing'][$key]) || !is_array($state['metrics']['timing'][$key])) {
    $state['metrics']['timing'][$key] = [];
  }
  $state['metrics']['timing'][$key][] = round($duration, 2);
  if (count($state['metrics']['timing'][$key]) > 20) {
    $state['metrics']['timing'][$key] = array_slice($state['metrics']['timing'][$key], -20);
  }
  $arr = $state['metrics']['timing'][$key];
  $state['metrics']['timing']['avg_' . $type] = round(array_sum($arr) / count($arr), 1);
}

function render_template(string $template, array $vars): string {
  foreach ($vars as $k => $v) {
    $template = str_replace('{{' . $k . '}}', (string)$v, $template);
  }
  return $template;
}

function archive_article_to_backup(array $article): void {
  $backup = [];
  if (is_file(BACKUP_FILE)) {
    $raw = file_get_contents(BACKUP_FILE);
    if ($raw !== false) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $backup = $decoded;
    }
  }
  $article['_deleted_at'] = gmdate('c');
  $backup[] = $article;
  file_put_contents(BACKUP_FILE,
    json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
  );
}

function build_rewrite_prompt(string $notes, array $plJson, array $topic, string $newArticleId, string $newGroup): string {
  $origJson    = json_encode($plJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  $topicTitle  = (string)($topic['tytul'] ?? '');
  $topicShort  = (string)($topic['opis_ogolny'] ?? '');
  $topicLong   = (string)($topic['opis_szczegolowy'] ?? '');
  return
    "Poniższy artykuł musi być przepisany zgodnie z uwagami poniżej. Zachowaj dokładnie ten sam format JSON i strukturę HTML co oryginał.\n\n" .
    "UWAGI DO PRZEPISANIA:\n" . $notes . "\n\n" .
    "TREŚĆ ARTYKUŁU DO PRZEPISANIA (kompletny JSON artykułu po polsku):\n" . $origJson . "\n\n" .
    "ZRODŁOWE MATERIAŁY DO ARTYKUŁU:\n" .
    "Tytuł tematu: " . $topicTitle . "\n" .
    "Opis ogólny: " . $topicShort . "\n" .
    "Opis szczegółowy:\n" . $topicLong . "\n\n" .
    "NOWE DANE IDENTYFIKACYJNE (użyj tych wartości w outputcie):\n" .
    "ARTICLE_ID: " . $newArticleId . "\n" .
    "TRANSLATION_GROUP: " . $newGroup . "\n\n" .
    'OCZEKIWANY OUTPUT:' . "\n" .
    'Zwróć TYLKO JSON (bez Markdown, bez komentarzy), w dokładnie tym samym formacie co oryginał:' . "\n" .
    '{"article_id":"' . $newArticleId . '","translation_group":"' . $newGroup . '","categories":[{"slug":"...","name":"..."}],' .
    '"translations":{"pl":{"lang":"pl","wp_id":null,"title":"...","slug_old":null,"url_old":null,"seo_friendly_url":"...","excerpt_html":"","content_html":"...(pełny HTML)...","seo":{"meta_title":"...","meta_description":"..."},"metrics":{"words":0,"chars":0}},' .
    '"en":{"lang":"en","wp_id":null,"title":null,"slug_old":null,"url_old":null,"seo_friendly_url":null,"excerpt_html":"","content_html":null,"seo":{"meta_title":null,"meta_description":null},"metrics":{"words":0,"chars":0}},' .
    '"de":{"lang":"de",...},"fr":{"lang":"fr",...},"it":{"lang":"it",...},"cs":{"lang":"cs",...},"es":{"lang":"es",...}}}';
}

function consume_skip_signal(string $lang, string $articleId): bool {
  // Returns true if a matching skip signal exists (and deletes it).
  if (!is_file(SKIP_SIGNAL_FILE)) return false;
  $raw = @file_get_contents(SKIP_SIGNAL_FILE);
  if ($raw === false) return false;
  $sig = json_decode($raw, true);
  if (!is_array($sig)) {
    @unlink(SKIP_SIGNAL_FILE);
    return false;
  }
  // Accept if lang matches (article_id may be empty in edge cases — still honour)
  if (($sig['lang'] ?? '') === $lang) {
    @unlink(SKIP_SIGNAL_FILE);
    return true;
  }
  return false;
}

function find_article_needing_translation(array $articles, array $langs, array $skippedLangs = []): ?array {
  // Finds first article that has PL content_html but is missing a translation in any of $langs.
  // Skips languages that are in $skippedLangs[$articleId][$lang] to prevent infinite retry loops.
  // Returns ['article_idx' => int, 'article_id' => string, 'lang' => string] or null.
  // NOTE: $articles is the flat array of article objects (already extracted from ['articles'] key by caller).
  foreach ($articles as $i => $a) {
    if (!is_array($a)) continue;
    $plHtml = $a['translations']['pl']['content_html'] ?? null;
    if (empty($plHtml)) continue; // no PL article yet
    $artId = (string)($a['article_id'] ?? '');
    foreach ($langs as $lg) {
      // Skip if this lang was permanently skipped for this article
      if (!empty($skippedLangs[$artId][$lg])) continue;
      $tr = $a['translations'][$lg] ?? null;
      if (!is_array($tr) || empty($tr['content_html'])) {
        return [
          'article_idx' => $i,
          'article_id'  => $artId,
          'lang'        => $lg,
        ];
      }
    }
  }
  return null;
}

function get_next_topic(array $topics, array $completed, array $skipped): ?array {
  $done = array_flip(array_map('strval', $completed));
  $skip = array_flip(array_map('strval', $skipped));
  $list = $topics['artykuly'] ?? [];
  if (!is_array($list)) return null;

  // sort by 'index' if present
  usort($list, function($a,$b){
    $ai = is_array($a) ? ($a['index'] ?? PHP_INT_MAX) : PHP_INT_MAX;
    $bi = is_array($b) ? ($b['index'] ?? PHP_INT_MAX) : PHP_INT_MAX;
    return $ai <=> $bi;
  });

  foreach ($list as $t) {
    if (!is_array($t)) continue;
    $idx = (string)($t['index'] ?? '');
    $uid = (string)($t['_uid'] ?? '');
    if ($idx === '') continue;
    // Backward compatibility: older states stored only numeric topic index,
    // while current versions store stable _uid (index#position).
    if (
      isset($done[$idx]) || isset($skip[$idx]) ||
      ($uid !== '' && (isset($done[$uid]) || isset($skip[$uid])))
    ) {
      continue;
    }
    return $t;
  }
  return null;
}


function normalize_topics_list(array $topics): array {
  $list = $topics['artykuly'] ?? [];
  if (!is_array($list)) return [];
  $normalized = [];
  foreach (array_values($list) as $pos => $topic) {
    if (!is_array($topic)) continue;
    $idx = trim((string)($topic['index'] ?? ''));
    if ($idx === '') continue;
    $topic['_uid'] = $idx . '#' . ($pos + 1);
    $normalized[] = $topic;
  }
  return $normalized;
}

function find_topic_by_uid(array $topics, string $uid): ?array {
  foreach (normalize_topics_list($topics) as $topic) {
    if ((string)($topic['_uid'] ?? '') === $uid) return $topic;
  }
  return null;
}

function ensure_global_total(array &$state, array $topics): void {
  if ($state['metrics']['global_total'] !== null) return;
  $n = 0;
  if (isset($topics['artykuly']) && is_array($topics['artykuly'])) $n = count($topics['artykuly']);
  $state['metrics']['global_total'] = $n;
}

function generate_next_article_id(array $articlesJson): string {
  $max = 0;
  foreach (($articlesJson['articles'] ?? []) as $a) {
    if (!is_array($a)) continue;
    $id = (string)($a['article_id'] ?? '');
    if (preg_match('/^\d+$/', $id)) {
      $num = intval($id, 10);
      if ($num > $max) $max = $num;
    }
  }
  $next = $max + 1;
  return str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

function new_translation_group(): string {
  return 'pll_' . bin2hex(random_bytes(8));
}

function build_placeholder_translation(string $lang): array {
  return [
    'lang' => $lang,
    'wp_id' => null,
    'title' => null,
    'slug_old' => null,
    'url_old' => null,
    'seo_friendly_url' => null,
    'excerpt_html' => '',
    'content_html' => null,
    'seo' => ['meta_title' => null, 'meta_description' => null],
    'metrics' => ['words' => 0, 'chars' => 0],
  ];
}

function call_model_json(string $apiKey, string $model, string $reasoningEffort, int $maxOutputTokens, string $prompt): array {
  // Call Responses API, parse first JSON object from output text, return [parsed, rawText, meta].
  $payload = [
    'model'            => $model,
    'input'            => $prompt,
    'reasoning'        => ['effort' => $reasoningEffort],
    'max_output_tokens'=> $maxOutputTokens,
  ];
  $resp    = openai_post_responses($apiKey, $payload);
  $meta    = $resp['_meta'] ?? [];
  $outText = extract_output_text($resp);

  if (trim($outText) === '') {
    $usage = $meta['usage'] ?? [];
    throw new RuntimeException(
      "Model returned empty output. elapsed={$meta['elapsed_s']}s " .
      "tokens_in=" . ($usage['input_tokens'] ?? '?') .
      " tokens_out=" . ($usage['output_tokens'] ?? '?')
    );
  }

  $jsonStr = find_first_json_object($outText);
  if ($jsonStr === null) {
    throw new RuntimeException(
      "No JSON object found in model output. elapsed={$meta['elapsed_s']}s. " .
      "Output preview (" . mb_strlen($outText) . " chars): " . mb_substr($outText, 0, 800)
    );
  }

  $parsed = json_decode($jsonStr, true);
  if (!is_array($parsed)) {
    $err = json_last_error_msg();
    throw new RuntimeException(
      "Model output is not valid JSON. json_error={$err}. elapsed={$meta['elapsed_s']}s. " .
      "JSON candidate (" . mb_strlen($jsonStr) . " chars): " . mb_substr($jsonStr, 0, 800)
    );
  }
  return [$parsed, $outText, $meta];
}

/** =========================
 *  AJAX actions
 *  ========================= */
$action = $_GET['action'] ?? null;
if ($action) {

  // ── read-only actions (no exclusive lock needed) ──────────────────────────
  if ($action === 'get_articles') {
    $state = load_state();
    $aPath = $state['files']['articles_path'] ?? null;
    if (!$aPath || !is_file($aPath)) {
      json_response(['ok'=>true,'articles'=>[],'timing'=>$state['metrics']['timing'] ?? []]);
    }
    $articles = read_json_file($aPath);
    $list = [];
    foreach (($articles['articles'] ?? []) as $a) {
      if (!is_array($a)) continue;
      $row = [
        'article_id' => $a['article_id'] ?? null,
        'title_pl'   => $a['translations']['pl']['title'] ?? null,
        'langs'      => [],
      ];
      foreach (['pl','en','de','fr','it','cs','es'] as $lg) {
        $t = $a['translations'][$lg] ?? null;
        $isDone = is_array($t) && !empty($t['content_html']);
        $row['langs'][$lg] = [
          'status'          => $isDone ? 'done' : 'empty',
          'title'           => $t['title'] ?? null,
          'seo_friendly_url'=> $t['seo_friendly_url'] ?? null,
          'seo'             => $t['seo'] ?? null,
          'metrics'         => $t['metrics'] ?? null,
        ];
      }
      $list[] = $row;
    }
    $topicsList = [];
    $bPath = $state['files']['topics_path'] ?? null;
    if ($bPath && is_file($bPath)) {
      try {
        $topicsData = read_topics_file($bPath);
        $topicsList = normalize_topics_list($topicsData);
      } catch (Throwable $e) {}
    }
    json_response([
      'ok'      => true,
      'articles'=> $list,
      'topics'  => $topicsList,
      'runtime' => $state['runtime'],
      'timing'  => $state['metrics']['timing'] ?? [],
    ]);
  }

  if ($action === 'get_article_preview') {
    $articleId = trim((string)($_GET['article_id'] ?? ''));
    $lang      = trim((string)($_GET['lang'] ?? 'pl'));
    if ($articleId === '') json_response(['ok'=>false,'error'=>'Missing article_id'], 400);
    $state = load_state();
    $aPath = $state['files']['articles_path'] ?? null;
    if (!$aPath || !is_file($aPath)) json_response(['ok'=>false,'error'=>'No articles file'], 400);
    $articles = read_json_file($aPath);
    foreach (($articles['articles'] ?? []) as $a) {
      if (!is_array($a)) continue;
      if ((string)($a['article_id'] ?? '') === $articleId) {
        $tr = $a['translations'][$lang] ?? null;
        if (!$tr) json_response(['ok'=>false,'error'=>"Language '{$lang}' not found"], 404);
        json_response(['ok'=>true,'translation'=>$tr,'article_id'=>$articleId,'lang'=>$lang]);
      }
    }
    json_response(['ok'=>false,'error'=>"Article {$articleId} not found"], 404);
  }
  // ── get_topic_prompt: preview full write prompt for a pending topic ────────
  if ($action === 'get_topic_prompt') {
    $topicUid = trim((string)($_GET['topic_uid'] ?? ''));
    if ($topicUid === '') json_response(['ok'=>false,'error'=>'Missing topic_uid'], 400);
    $state = load_state();
    $bPath = $state['files']['topics_path'] ?? null;
    $aPath = $state['files']['articles_path'] ?? null;
    if (!$bPath || !is_file($bPath)) json_response(['ok'=>false,'error'=>'No topics file configured'], 400);
    try { $topics = read_topics_file($bPath); }
    catch (Throwable $e) { json_response(['ok'=>false,'error'=>'Cannot read topics: '.$e->getMessage()], 500); }
    $topic = find_topic_by_uid($topics, $topicUid);
    if (!$topic) json_response(['ok'=>false,'error'=>"Topic {$topicUid} not found"], 404);
    // Placeholder IDs for preview only (not saved anywhere)
    $articles = [];
    if ($aPath && is_file($aPath)) {
      try { $articles = read_json_file($aPath); } catch (Throwable $e) { $articles = ['articles'=>[]]; }
    }
    $articleId = generate_next_article_id($articles);
    $group = 'pll_preview_' . substr(md5($topicUid . 'x'), 0, 8);
    $vars = [
      'ARTICLE_ID'       => $articleId,
      'TRANSLATION_GROUP'=> $group,
      'TOPIC_INDEX'      => (string)($topic['index'] ?? ''),
      'TOPIC_TITLE'      => (string)($topic['tytul'] ?? ''),
      'TOPIC_SHORT_DESC' => (string)($topic['opis_ogolny'] ?? ''),
      'TOPIC_LONG_DESC'  => (string)($topic['opis_szczegolowy'] ?? ''),
    ];
    $prompt = render_template($state['config']['prompt_write_pl'], $vars);
    json_response(['ok'=>true,'prompt'=>$prompt,'vars'=>$vars,'topic_uid'=>$topicUid,'topic_title'=>(string)($topic['tytul']??'')]);
  }

  if ($action === 'get_current_prompt') {
    $state = load_state();
    json_response([
      'ok'=>true,
      'prompt'=>$state['runtime']['current_prompt'],
      'vars'=>$state['runtime']['current_prompt_vars'],
      'stage'=>$state['runtime']['current_stage'],
    ]);
  }

  // ── get_article_full ─────────────────────────────────────────────────────
  if ($action === 'get_article_full') {
    $articleId = trim((string)($_GET['article_id'] ?? ''));
    if ($articleId === '') json_response(['ok'=>false,'error'=>'Missing article_id'], 400);
    $state = load_state();
    $aPath = $state['files']['articles_path'] ?? null;
    if (!$aPath || !is_file($aPath)) json_response(['ok'=>false,'error'=>'No articles file'], 400);
    $articles = read_json_file($aPath);
    $found = null;
    foreach (($articles['articles'] ?? []) as $a) {
      if (is_array($a) && (string)($a['article_id'] ?? '') === $articleId) { $found = $a; break; }
    }
    if (!$found) json_response(['ok'=>false,'error'=>"Article {$articleId} not found"], 404);
    $topic = null;
    $tUid = (string)($found['_topic_uid'] ?? '');
    $tIdx = (string)($found['_topic_index'] ?? '');
    $bPath = $state['files']['topics_path'] ?? null;
    if ($bPath && is_file($bPath)) {
      try {
        $topicsData = read_topics_file($bPath);
        if ($tUid !== '') $topic = find_topic_by_uid($topicsData, $tUid);
        if (!$topic && $tIdx !== '') {
          foreach (normalize_topics_list($topicsData) as $tt) {
            if ((string)($tt['index'] ?? '') === $tIdx) { $topic = $tt; break; }
          }
        }
      } catch (Throwable $e) {}
    }
    json_response(['ok'=>true,'article'=>$found,'topic'=>$topic]);
  }

  // ── force_skip_translation: no lock needed — writes signal file only ──────
  if ($action === 'force_skip_translation') {
    $state = load_state();
    $stage = (string)($state['runtime']['current_stage'] ?? '');
    if (!preg_match('/^TRANSLATE_([A-Z]{2})$/', $stage, $m)) {
      json_response(['ok'=>false,'error'=>'Not currently in a translation stage'], 400);
    }
    $lang      = strtolower($m[1]);
    $articleId = (string)($state['runtime']['current_article_id'] ?? '');
    $signal    = json_encode(['lang'=>$lang,'article_id'=>$articleId,'ts'=>now_iso()]);
    file_put_contents(SKIP_SIGNAL_FILE, $signal);
    json_response(['ok'=>true,'lang'=>$lang,'article_id'=>$articleId]);
  }

  // ── end read-only actions ─────────────────────────────────────────────────

  try {
    with_lock(function() use ($action) {
      $state = load_state();

      // ----- set_api_key -----
      if ($action === 'set_api_key') {
        $key = trim((string)($_POST['api_key'] ?? ''));
        if ($key === '') {
          unset($_SESSION['openai_key']);
          json_response(['ok'=>false, 'message'=>'Empty key']);
        }
        $_SESSION['openai_key'] = $key;
        json_response(['ok'=>true]);
      }

      // ----- test_api_key -----
      if ($action === 'test_api_key') {
        $key = $_SESSION['openai_key'] ?? null;
        if (!$key) json_response(['ok'=>false, 'error'=>'No key in session'], 400);

        $model = $state['config']['model_translate'] ?? 'gpt-5';
        try {
          $payload = [
            'model' => $model,
            'input' => 'Return ONLY this JSON: {"ok":true}',
            'reasoning' => ['effort' => 'minimal'],
            'max_output_tokens' => 256,
          ];
          $resp = openai_post_responses($key, $payload);
          $txt = extract_output_text($resp);
          $jsonStr = find_first_json_object($txt);
          $parsed = $jsonStr ? json_decode($jsonStr, true) : null;
          if (is_array($parsed) && ($parsed['ok'] ?? null) === true) {
            log_event($state, 'success', 'action', 'API key test OK.');
            $state['runtime']['last_error'] = null;
            save_state($state);
            json_response(['ok'=>true, 'preview'=>$txt]);
          }
          log_event($state, 'warn', 'action', 'API key test did not return expected JSON.', ['preview'=>mb_substr($txt,0,500)]);
          $state['runtime']['last_error'] = ['type'=>'test_failed','preview'=>$txt];
          save_state($state);
          json_response(['ok'=>false, 'error'=>'Unexpected response', 'preview'=>$txt, 'raw'=>$resp], 400);
        } catch (Throwable $e) {
          log_event($state, 'error', 'action', 'API key test failed: ' . $e->getMessage());
          $state['runtime']['last_error'] = ['type'=>'api_error','message'=>$e->getMessage(),'code'=>$e->getCode()];
          save_state($state);
          json_response(['ok'=>false, 'error'=>$e->getMessage(), 'code'=>$e->getCode()], 400);
        }
      }

      // ----- save_config -----
      if ($action === 'save_config') {
        $cfg = $state['config'];
        $cfg['model_write'] = trim((string)($_POST['model_write'] ?? $cfg['model_write']));
        $cfg['model_translate'] = trim((string)($_POST['model_translate'] ?? $cfg['model_translate']));
        $cfg['write_reasoning_effort'] = trim((string)($_POST['write_reasoning_effort'] ?? $cfg['write_reasoning_effort']));
        $cfg['translate_reasoning_effort'] = trim((string)($_POST['translate_reasoning_effort'] ?? $cfg['translate_reasoning_effort']));
        $cfg['write_max_output_tokens'] = max(256, (int)($_POST['write_max_output_tokens'] ?? $cfg['write_max_output_tokens']));
        $cfg['translate_max_output_tokens'] = max(256, (int)($_POST['translate_max_output_tokens'] ?? $cfg['translate_max_output_tokens']));
        $cfg['prompt_write_pl'] = (string)($_POST['prompt_write_pl'] ?? $cfg['prompt_write_pl']);
        $cfg['prompt_translate_lang'] = (string)($_POST['prompt_translate_lang'] ?? $cfg['prompt_translate_lang']);

        // language order fixed here (could be made editable)
        $state['config'] = $cfg;
        log_event($state, 'success', 'action', 'Configuration saved.');
        save_state($state);
        json_response(['ok'=>true]);
      }

      // ----- upload_files -----
      if ($action === 'upload_files') {
        if (!isset($_FILES['articles']) || !isset($_FILES['topics'])) {
          json_response(['ok'=>false,'error'=>'Missing files'], 400);
        }
        $a = $_FILES['articles'];
        $b = $_FILES['topics'];
        if ($a['error'] !== UPLOAD_ERR_OK || $b['error'] !== UPLOAD_ERR_OK) {
          json_response(['ok'=>false,'error'=>'Upload error'], 400);
        }

        $aName = sanitize_filename($a['name']);
        $bName = sanitize_filename($b['name']);
        $aPath = __DIR__ . '/articles_' . time() . '_' . $aName;
        $bPath = __DIR__ . '/topics_'   . time() . '_' . $bName;

        if (!move_uploaded_file($a['tmp_name'], $aPath)) json_response(['ok'=>false,'error'=>'Failed saving articles file'], 500);
        if (!move_uploaded_file($b['tmp_name'], $bPath)) json_response(['ok'=>false,'error'=>'Failed saving topics file'], 500);

        // validate JSON
        $articles = read_json_file($aPath);
        $topics   = read_topics_file($bPath);

        if (!isset($articles['articles']) || !is_array($articles['articles'])) {
          json_response(['ok'=>false,'error'=>'Articles JSON must contain root.articles[]'], 400);
        }
        if (!isset($topics['artykuly']) || !is_array($topics['artykuly'])) {
          json_response(['ok'=>false,'error'=>'Topics JSON must contain root.artykuly[]'], 400);
        }

        $state['files']['articles_path'] = $aPath;
        $state['files']['topics_path'] = $bPath;
        $state['files']['articles_original'] = $aName;
        $state['files']['topics_original'] = $bName;

        ensure_global_total($state, $topics);
        log_event($state, 'success', 'action', 'Files uploaded and validated.', [
          'articles_count' => count($articles['articles']),
          'topics_count' => count($topics['artykuly'])
        ]);
        save_state($state);

        json_response(['ok'=>true,
          'articles_count'=>count($articles['articles']),
          'topics_count'=>count($topics['artykuly']),
          'articles_path'=>basename($aPath),
          'topics_path'=>basename($bPath)
        ]);
      }

      // ----- get_status -----
      if ($action === 'get_status') {
        $keyOk = isset($_SESSION['openai_key']) && trim((string)$_SESSION['openai_key']) !== '';
        // Exclude large prompt from frequent polling — served via get_current_prompt
        $stateLight = $state;
        $stateLight['runtime']['current_prompt'] = !empty($state['runtime']['current_prompt']) ? '(available)' : null;
        $stateLight['runtime']['current_prompt_vars'] = null;
        $status = [
          'ok'=>true,
          'key_present'=>$keyOk,
          'state'=>$stateLight,
          'server_time'=>now_iso(),
        ];
        json_response($status);
      }

      // ----- start/pause/resume/stop -----
      if ($action === 'start') {
        $state['runtime']['status'] = 'running';
        $state['runtime']['phase']  = 'write';
        $state['runtime']['last_error'] = null;
        $state['runtime']['last_output_preview'] = null;
        log_event($state, 'info', 'global', 'Started (faza: pisanie PL).');
        save_state($state);
        json_response(['ok'=>true]);
      }
      if ($action === 'pause') {
        $state['runtime']['status'] = 'paused';
        log_event($state, 'info', 'global', 'Paused.');
        save_state($state);
        json_response(['ok'=>true]);
      }
      if ($action === 'resume') {
        // if waiting user decision, resume doesn't override
        if ($state['runtime']['status'] === 'waiting_user_decision') {
          json_response(['ok'=>false,'error'=>'Waiting for user decision'], 400);
        }
        $state['runtime']['status'] = 'running';
        log_event($state, 'info', 'global', 'Resumed.');
        save_state($state);
        json_response(['ok'=>true]);
      }
      if ($action === 'stop') {
        $state['runtime']['status'] = 'stopped';
        log_event($state, 'warn', 'global', 'Stopped by user.');
        save_state($state);
        json_response(['ok'=>true]);
      }

      // ----- start_translate -----
      if ($action === 'start_translate') {
        $state['runtime']['phase']  = 'translate';
        $state['runtime']['status'] = 'running';
        // Reset current sprint so run_step picks next untranslated article
        $state['runtime']['current_topic_index']  = null;
        $state['runtime']['current_topic_uid']    = null;
        $state['runtime']['current_topic_title']  = null;
        $state['runtime']['current_article_id']   = null;
        $state['runtime']['current_stage']        = null;
        $state['runtime']['current_lang']         = null;
        $state['runtime']['current_is_rewrite']   = false;
        $state['runtime']['retry_count']          = 0;
        $state['runtime']['last_error']           = null;
        $state['runtime']['last_output_preview']  = null;
        $state['runtime']['current_step_start_ts']= null;
        $state['runtime']['current_prompt']       = null;
        $state['runtime']['current_prompt_vars']  = null;
        log_event($state, 'info', 'global', 'Rozpoczęto fazę tłumaczenia.');
        save_state($state);
        json_response(['ok'=>true]);
      }

      // ----- user decision handlers -----
      if ($action === 'decision_retry') {
        if ($state['runtime']['status'] !== 'waiting_user_decision') json_response(['ok'=>false,'error'=>'Not waiting'], 400);
        $state['runtime']['status'] = 'running';
        $state['runtime']['retry_count'] = 0;
        log_event($state, 'info', 'action', 'User chose: retry.');
        save_state($state);
        json_response(['ok'=>true]);
      }
      if ($action === 'decision_skip_lang') {
        if ($state['runtime']['status'] !== 'waiting_user_decision') json_response(['ok'=>false,'error'=>'Not waiting'], 400);
        $lang = $state['runtime']['current_lang'];
        $articleId = $state['runtime']['current_article_id'];
        if (!$lang || !$articleId) json_response(['ok'=>false,'error'=>'Missing lang/article context'], 400);
        $state['runtime']['status'] = 'running';
        $state['runtime']['retry_count'] = 0;
        $state['runtime']['last_error'] = null;
        $state['runtime']['last_output_preview'] = null;
        $state['runtime']['skipped_langs'][$articleId][$lang] = true;
        log_event($state, 'warn', 'sprint', "User skipped language: {$lang} for article {$articleId}.");
        // advance stage
        $order = $state['config']['language_order'];
        $pos = array_search($lang, $order, true);
        if ($pos === false || $pos === count($order)-1) {
          $state['runtime']['current_stage'] = 'DONE';
        } else {
          $nextLang = $order[$pos+1];
          $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
          $state['runtime']['current_lang'] = $nextLang;
        }
        save_state($state);
        json_response(['ok'=>true]);
      }
      if ($action === 'decision_stop_sprint') {
        if ($state['runtime']['status'] !== 'waiting_user_decision') json_response(['ok'=>false,'error'=>'Not waiting'], 400);
        $state['runtime']['status'] = 'paused';
        log_event($state, 'warn', 'sprint', 'User stopped sprint (paused for manual intervention).');
        save_state($state);
        json_response(['ok'=>true]);
      }
      if ($action === 'decision_skip_topic') {
        if ($state['runtime']['status'] !== 'waiting_user_decision') json_response(['ok'=>false,'error'=>'Not waiting'], 400);
        $idx = $state['runtime']['current_topic_index'];
        if ($idx === null) json_response(['ok'=>false,'error'=>'No topic'], 400);
        $state['runtime']['skipped_topics'][] = $idx;
        $state['runtime']['status'] = 'running';
        $state['runtime']['current_topic_index'] = null;
        $state['runtime']['current_topic_title'] = null;
        $state['runtime']['current_article_id'] = null;
        $state['runtime']['current_stage'] = null;
        $state['runtime']['current_lang'] = null;
        $state['runtime']['last_error'] = null;
        $state['runtime']['last_output_preview'] = null;
        $state['runtime']['retry_count'] = 0;
        log_event($state, 'warn', 'global', "User skipped topic index: {$idx}.");
        save_state($state);
        json_response(['ok'=>true]);
      }

      // ----- delete_article -----
      if ($action === 'delete_article') {
        $articleId = trim((string)($_POST['article_id'] ?? ''));
        if ($articleId === '') json_response(['ok'=>false,'error'=>'Missing article_id'], 400);
        $aPath = $state['files']['articles_path'] ?? null;
        if (!$aPath || !is_file($aPath)) json_response(['ok'=>false,'error'=>'No articles file'], 400);
        $articles = read_json_file($aPath);
        $idx = null;
        foreach (($articles['articles'] ?? []) as $i => $a) {
          if (is_array($a) && (string)($a['article_id'] ?? '') === $articleId) { $idx = $i; break; }
        }
        if ($idx === null) json_response(['ok'=>false,'error'=>"Article {$articleId} not found"], 404);
        archive_article_to_backup($articles['articles'][$idx]);
        array_splice($articles['articles'], $idx, 1);
        write_json_atomic($aPath, $articles);
        log_event($state, 'warn', 'action', "DELETE article_id={$articleId} — zarchiwizowano do deleted_articles.json.");
        save_state($state);
        json_response(['ok'=>true,'article_id'=>$articleId]);
      }

      // ----- enqueue_rewrite -----
      if ($action === 'enqueue_rewrite') {
        $articleId = trim((string)($_POST['article_id'] ?? ''));
        $notes     = trim((string)($_POST['notes'] ?? ''));
        if ($articleId === '') json_response(['ok'=>false,'error'=>'Missing article_id'], 400);
        $aPath = $state['files']['articles_path'] ?? null;
        $bPath = $state['files']['topics_path'] ?? null;
        if (!$aPath || !is_file($aPath)) json_response(['ok'=>false,'error'=>'No articles file'], 400);
        $articles = read_json_file($aPath);
        $foundIdx = null;
        foreach (($articles['articles'] ?? []) as $i => $a) {
          if (is_array($a) && (string)($a['article_id'] ?? '') === $articleId) { $foundIdx = $i; break; }
        }
        if ($foundIdx === null) json_response(['ok'=>false,'error'=>"Article {$articleId} not found"], 404);
        $article   = $articles['articles'][$foundIdx];
        $plJson    = $article['translations']['pl'] ?? null;
        if (!is_array($plJson) || empty($plJson['content_html'])) {
          json_response(['ok'=>false,'error'=>'Article has no PL content to rewrite'], 400);
        }
        $topicIndex = (string)($article['_topic_index'] ?? '');
        $topic = [];
        if ($topicIndex !== '' && $bPath && is_file($bPath)) {
          try {
            $topicsData = read_topics_file($bPath);
            foreach (($topicsData['artykuly'] ?? []) as $tt) {
              if (is_array($tt) && (string)($tt['index'] ?? '') === $topicIndex) { $topic = $tt; break; }
            }
          } catch (Throwable $e) {}
        }
        // Archive & delete original
        archive_article_to_backup($article);
        array_splice($articles['articles'], $foundIdx, 1);
        write_json_atomic($aPath, $articles);
        // Prepare queue item
        $qItem = [
          'orig_article_id' => $articleId,
          'title'           => (string)($plJson['title'] ?? $articleId),
          'notes'           => $notes,
          'orig_pl_json'    => $plJson,
          'topic_index'     => $topicIndex,
          'topic'           => $topic,
        ];
        $state['runtime']['rewrite_queue'][] = $qItem;
        if ($state['runtime']['status'] === 'idle' || $state['runtime']['status'] === 'stopped' || $state['runtime']['status'] === 'done') {
          $state['runtime']['status'] = 'running';
        }
        log_event($state, 'info', 'global', "REWRITE enqueued for article_id={$articleId} — original zarchiwizowany.");
        save_state($state);
        json_response(['ok'=>true,'article_id'=>$articleId]);
      }

      // ----- run_step -----
      if ($action === 'run_step') {
        $key = $_SESSION['openai_key'] ?? null;
        if (!$key) json_response(['ok'=>false, 'error'=>'No API key in session'], 400);
        if (($state['runtime']['status'] ?? 'idle') !== 'running') {
          json_response(['ok'=>true,'skipped'=>true,'reason'=>'Not running','state'=>$state]);
        }

        $aPath = $state['files']['articles_path'];
        $bPath = $state['files']['topics_path'];
        if (!$aPath || !$bPath) json_response(['ok'=>false,'error'=>'Upload files first'], 400);

        $articles = read_json_file($aPath);
        $topics = read_topics_file($bPath);
        ensure_global_total($state, $topics);

        // Ensure we have a current topic
        if ($state['runtime']['current_topic_index'] === null) {
          // Rewrite queue takes priority in any phase
          if (!empty($state['runtime']['rewrite_queue'])) {
            $rw = array_shift($state['runtime']['rewrite_queue']);
            $state['runtime']['current_topic_index'] = '__rewrite__' . ($rw['orig_article_id'] ?? 'unknown');
            $state['runtime']['current_topic_title'] = ($rw['title'] ?? '') . ' [PRZEPISANIE]';
            $state['runtime']['current_stage'] = 'WRITE_PL';
            $state['runtime']['current_lang'] = 'pl';
            $state['runtime']['current_article_id'] = null;
            $state['runtime']['current_is_rewrite'] = $rw;
            $state['runtime']['retry_count'] = 0;
            log_event($state, 'info', 'sprint', 'REWRITE: rozpoczynam przepisywanie — ' . ($rw['title'] ?? '') . ' (orig=' . ($rw['orig_article_id'] ?? '') . ')');

          } elseif (($state['runtime']['phase'] ?? 'write') === 'translate') {
            // Translate phase: find next article with PL but missing translations
            $langOrder = $state['config']['language_order'] ?? ['en','de','fr','it','cs','es'];
            $missing = find_article_needing_translation($articles['articles'] ?? [], $langOrder, $state['runtime']['skipped_langs'] ?? []);
            if (!$missing) {
              // All translations complete — return to write phase and continue
              $state['runtime']['phase'] = 'write';
              log_event($state, 'success', 'global', 'POBOCZNE: wszystkie brakujące tłumaczenia uzupełnione — kontynuuję pisanie kolejnych artykułów.');
              save_state($state);
              json_response(['ok'=>true,'state'=>$state]);
            }
            // Find article title
            $tArtTitle = '';
            foreach (($articles['articles'] ?? []) as $tA) {
              if ((string)($tA['article_id'] ?? '') === $missing['article_id']) {
                $tArtTitle = (string)($tA['translations']['pl']['title'] ?? $missing['article_id']);
                break;
              }
            }
            $state['runtime']['current_topic_index']  = '__translate__' . $missing['article_id'];
            $state['runtime']['current_topic_title']  = $tArtTitle;
            $state['runtime']['current_article_id']   = $missing['article_id'];
            $state['runtime']['current_stage']        = 'TRANSLATE_' . strtoupper($missing['lang']);
            $state['runtime']['current_lang']         = $missing['lang'];
            $state['runtime']['current_is_rewrite']   = false;
            $state['runtime']['retry_count']          = 0;
            $state['runtime']['status']               = 'running';
            log_event($state, 'info', 'sprint', "TRANSLATE POBOCZNE: artykuł {$missing['article_id']} — {$tArtTitle} — brakujący język: {$missing['lang']}");

          } else {
            // Write phase: pick next topic to write in PL
            $topicsNorm = ['artykuly' => normalize_topics_list($topics)];
            $t = get_next_topic($topicsNorm, $state['runtime']['completed_topics'], $state['runtime']['skipped_topics']);
            if (!$t) {
              $state['runtime']['status'] = 'done';
              log_event($state, 'success', 'global', 'Wszystkie artykuły napisane po polsku. Możesz teraz uruchomić tłumaczenia.');
              save_state($state);
              json_response(['ok'=>true,'done'=>true,'state'=>$state]);
            }
            $state['runtime']['current_topic_index'] = $t['index'];
            $state['runtime']['current_topic_uid'] = (string)($t['_uid'] ?? ((string)($t['index'] ?? '') . '#1'));
            $state['runtime']['current_topic_title'] = $t['tytul'] ?? '';
            $state['runtime']['current_stage'] = 'WRITE_PL';
            $state['runtime']['current_lang'] = 'pl';
            $state['runtime']['current_article_id'] = null;
            $state['runtime']['current_is_rewrite'] = false;
            $state['runtime']['retry_count'] = 0;
            log_event($state, 'info', 'sprint', 'Picked next topic: #' . $t['index'] . ' — ' . ($t['tytul'] ?? ''));
          }
        }

        // Load fresh state after modifications
        $stage = $state['runtime']['current_stage'];

        // Stage: WRITE_PL
        if ($stage === 'WRITE_PL') {
          $rewriteData = $state['runtime']['current_is_rewrite'];
          $tIdx = (string)$state['runtime']['current_topic_index'];

          // Determine IDs for this article now
          $articleId = generate_next_article_id($articles);
          $group = new_translation_group();

          if ($rewriteData) {
            // REWRITE mode — use custom rewrite prompt
            $prompt = build_rewrite_prompt(
              (string)($rewriteData['notes'] ?? ''),
              (array)($rewriteData['orig_pl_json'] ?? []),
              (array)($rewriteData['topic'] ?? []),
              $articleId,
              $group
            );
          } else {
            // Normal mode — look up topic from file
            $topic = null;
            $topicUid = (string)($state['runtime']['current_topic_uid'] ?? '');
            if ($topicUid !== '') {
              $topic = find_topic_by_uid($topics, $topicUid);
            }
            if (!$topic) {
              foreach (normalize_topics_list($topics) as $tt) {
                if ((string)($tt['index'] ?? '') === $tIdx) { $topic = $tt; break; }
              }
            }
            if (!$topic) {
              $state['runtime']['status'] = 'waiting_user_decision';
              $state['runtime']['last_error'] = ['type'=>'topic_not_found','message'=>"Topic index {$tIdx} not found in topics file."];
              log_event($state, 'error', 'sprint', 'Topic not found in topics JSON.');
              save_state($state);
              json_response(['ok'=>true,'state'=>$state]);
            }

            $vars = [
              'ARTICLE_ID' => $articleId,
              'TRANSLATION_GROUP' => $group,
              'TOPIC_INDEX' => (string)($topic['index'] ?? ''),
              'TOPIC_TITLE' => (string)($topic['tytul'] ?? ''),
              'TOPIC_SHORT_DESC' => (string)($topic['opis_ogolny'] ?? ''),
              'TOPIC_LONG_DESC' => (string)($topic['opis_szczegolowy'] ?? ''),
            ];
            $prompt = render_template($state['config']['prompt_write_pl'], $vars);
          }

          $state['runtime']['current_prompt'] = $prompt;
          $state['runtime']['current_prompt_vars'] = $rewriteData ? null : ($vars ?? null);
          $state['runtime']['current_step_start_ts'] = now_iso();
          $state['runtime']['last_action'] = 'WRITE_PL';
          $state['runtime']['current_lang'] = 'pl';
          $rewriteLabel = $rewriteData ? ' [REWRITE orig=' . ($rewriteData['orig_article_id'] ?? '') . ']' : '';
          log_event($state, 'info', 'action',
            "WRITE_PL START{$rewriteLabel}: model={$state['config']['model_write']} effort={$state['config']['write_reasoning_effort']} " .
            "max_tokens={$state['config']['write_max_output_tokens']} topic_idx={$tIdx}."
          );
          // CRITICAL: save state BEFORE the API call so current_topic_index + WRITE_PL stage are on disk.
          // If the API call succeeds but something fails after (save crash, network drop),
          // the next run_step will see stage=WRITE_PL and retry - NOT pick a new topic.
          save_state($state);

          $tWriteStart = microtime(true);

          try {
            [$obj, $rawText, $apiMeta] = call_model_json(
              $key,
              $state['config']['model_write'],
              $state['config']['write_reasoning_effort'],
              (int)$state['config']['write_max_output_tokens'],
              $prompt
            );
            $writeDuration = round(microtime(true) - $tWriteStart, 2);

            $issues = validate_pl_article_object($obj);
            if ($issues) {
              $htmlPreview = mb_substr((string)($obj['translations']['pl']['content_html'] ?? ''), 0, 300);
              throw new RuntimeException(
                "Validation failed (" . count($issues) . " issue(s)): " . implode(' | ', $issues) .
                ($htmlPreview ? " | content_html_preview: {$htmlPreview}" : "")
              );
            }

            // Ensure correct IDs (keep model-selected categories as-is)
            $obj['article_id'] = $articleId;
            $obj['translation_group'] = $group;
            $obj['_topic_index'] = (string)$state['runtime']['current_topic_index'];
            $obj['_topic_uid'] = (string)($state['runtime']['current_topic_uid'] ?? '');

            // Ensure all language placeholders
            $obj['translations'] ??= [];
            $obj['translations']['pl']['wp_id'] = null;
            $obj['translations']['pl']['slug_old'] = null;
            $obj['translations']['pl']['url_old'] = null;
            $obj['translations']['pl']['excerpt_html'] = '';
            $obj['translations']['pl']['metrics'] = compute_metrics_from_html((string)$obj['translations']['pl']['content_html']);
            $obj['translations']['pl']['seo_report'] = seo_report($obj['translations']['pl']); // internal info; safe
            foreach (['en','de','fr','it','cs','es'] as $lg) {
              if (!isset($obj['translations'][$lg]) || !is_array($obj['translations'][$lg])) {
                $obj['translations'][$lg] = build_placeholder_translation($lg);
              } else {
                // normalize placeholder
                $obj['translations'][$lg] = array_replace(build_placeholder_translation($lg), $obj['translations'][$lg]);
              }
            }

            // Append to articles
            $articles['articles'][] = $obj;
            write_json_atomic($aPath, $articles);
            $fileSizeKb = round(filesize($aPath) / 1024, 1);

            // Update timing
            update_timing($state, 'write', $writeDuration);

            // Update state
            $state['runtime']['current_article_id'] = $articleId;
            // Always proceed to translations immediately after writing PL
            $state['runtime']['current_stage'] = 'TRANSLATE_EN';
            $state['runtime']['current_lang']  = 'en';
            $state['runtime']['retry_count'] = 0;
            $state['runtime']['last_error'] = null;
            $state['runtime']['last_output_preview'] = mb_substr($rawText, 0, 4000);
            $state['runtime']['current_step_start_ts'] = null;
            $state['runtime']['current_prompt'] = null;
            $state['runtime']['current_prompt_vars'] = null;

            $usageIn  = $apiMeta['usage']['input_tokens']  ?? '?';
            $usageOut = $apiMeta['usage']['output_tokens'] ?? '?';
            $cat0     = $obj['categories'][0]['slug'] ?? '?';
            $plMet    = $obj['translations']['pl']['metrics'];
            log_event($state, 'success', 'sprint', "WRITE_PL OK — article_id={$articleId} cat={$cat0} words={$plMet['words']} chars={$plMet['chars']} dur={$writeDuration}s tokens={$usageIn}/{$usageOut} file={$fileSizeKb}KB.", [
              'seo' => $obj['translations']['pl']['seo_report']
            ]);
            log_event($state, 'info', 'action', "FILE SAVED — articles.json {$fileSizeKb}KB article_id={$articleId}.");

            save_state($state);
            json_response(['ok'=>true,'state'=>$state]);

          } catch (Throwable $e) {
            $state['runtime']['retry_count'] = (int)$state['runtime']['retry_count'] + 1;
            $state['runtime']['last_error'] = ['type'=>'write_pl_failed','message'=>$e->getMessage()];
            log_event($state, 'error', 'action', 'WRITE_PL failed: ' . $e->getMessage(), ['retry'=>$state['runtime']['retry_count']]);

            if ((int)$state['runtime']['retry_count'] <= (int)$state['runtime']['auto_retry_limit']) {
              log_event($state, 'warn', 'action', 'Auto-retrying WRITE_PL once...');
              save_state($state);
              json_response(['ok'=>true,'state'=>$state,'auto_retry'=>true]);
            }

            // Continue automatically: skip this topic, log where/why it failed, and move on.
            $idx = (string)($state['runtime']['current_topic_uid'] ?? $state['runtime']['current_topic_index']);
            if ($idx !== '') {
              $idxStr = (string)$idx;
              $already = array_flip(array_map('strval', $state['runtime']['skipped_topics'] ?? []));
              if (!isset($already[$idxStr])) $state['runtime']['skipped_topics'][] = $idx;
              log_event($state, 'warn', 'global', "AUTO-SKIP TOPIC #{$idxStr} — WRITE_PL error: " . $e->getMessage());
            } else {
              log_event($state, 'warn', 'global', 'AUTO-SKIP (unknown topic) — WRITE_PL error: ' . $e->getMessage());
            }

            $state['runtime']['status'] = 'running';
            $state['runtime']['last_output_preview'] = mb_substr((string)$e->getMessage(), 0, 4000);

            // reset sprint so next run_step picks a new topic
            $state['runtime']['current_topic_index'] = null;
            $state['runtime']['current_topic_uid'] = null;
            $state['runtime']['current_topic_title'] = null;
            $state['runtime']['current_article_id'] = null;
            $state['runtime']['current_stage'] = null;
            $state['runtime']['current_lang'] = null;
            $state['runtime']['retry_count'] = 0;
            $state['runtime']['current_step_start_ts'] = null;
            $state['runtime']['current_prompt'] = null;
            $state['runtime']['current_prompt_vars'] = null;

            save_state($state);
            json_response(['ok'=>true,'state'=>$state,'auto_skipped'=>true]);
          }
        }

        // Translation stages
        if (preg_match('/^TRANSLATE_([A-Z]{2})$/', (string)$stage, $m)) {
          $lang = strtolower($m[1]);
          $articleId = $state['runtime']['current_article_id'];
          if (!$articleId) {
            $state['runtime']['status'] = 'waiting_user_decision';
            $state['runtime']['last_error'] = ['type'=>'missing_article','message'=>'No current_article_id in state.'];
            log_event($state, 'error', 'sprint', 'Missing current_article_id.');
            save_state($state);
            json_response(['ok'=>true,'state'=>$state,'waiting'=>true]);
          }

          // load article object
          $foundIdx = null;
          foreach (($articles['articles'] ?? []) as $i => $a) {
            if (is_array($a) && (string)($a['article_id'] ?? '') === (string)$articleId) { $foundIdx = $i; break; }
          }
          if ($foundIdx === null) {
            $state['runtime']['status'] = 'waiting_user_decision';
            $state['runtime']['last_error'] = ['type'=>'article_not_found','message'=>"Article {$articleId} not found in articles file."];
            log_event($state, 'error', 'sprint', "Article {$articleId} not found.");
            save_state($state);
            json_response(['ok'=>true,'state'=>$state,'waiting'=>true]);
          }

          // If language already translated or skipped, advance
          $existing = $articles['articles'][$foundIdx]['translations'][$lang] ?? null;
          $skipped = $state['runtime']['skipped_langs'][$articleId][$lang] ?? false;
          if ($skipped === true || (is_array($existing) && !empty($existing['content_html']))) {
            log_event($state, 'info', 'action', "Skipping {$lang} (already done or skipped).");
            // advance
            $order = $state['config']['language_order'];
            $pos = array_search($lang, $order, true);
            if ($pos === false || $pos === count($order)-1) {
              $state['runtime']['current_stage'] = 'DONE';
              $state['runtime']['current_lang'] = null;
            } else {
              $nextLang = $order[$pos+1];
              $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
              $state['runtime']['current_lang'] = $nextLang;
            }
            save_state($state);
            json_response(['ok'=>true,'state'=>$state]);
          }

          $plObj = $articles['articles'][$foundIdx]['translations']['pl'] ?? null;
          if (!is_array($plObj) || empty($plObj['content_html'])) {
            $state['runtime']['status'] = 'waiting_user_decision';
            $state['runtime']['last_error'] = ['type'=>'missing_pl','message'=>'Missing PL translation in article.'];
            log_event($state, 'error', 'sprint', 'Missing PL translation in article.');
            save_state($state);
            json_response(['ok'=>true,'state'=>$state,'waiting'=>true]);
          }

          $vars = [
            'TARGET_LANG' => $lang,
            'SOURCE_PL_JSON' => json_encode($plObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          ];
          $prompt = render_template($state['config']['prompt_translate_lang'], $vars);

          $state['runtime']['current_prompt'] = $prompt;
          $state['runtime']['current_prompt_vars'] = $vars;
          $state['runtime']['current_step_start_ts'] = now_iso();

          $state['runtime']['last_action'] = 'TRANSLATE_' . strtoupper($lang);
          $state['runtime']['current_lang'] = $lang;
          log_event($state, 'info', 'action',
            "TRANSLATE {$lang} START: model={$state['config']['model_translate']} effort={$state['config']['translate_reasoning_effort']} " .
            "max_tokens={$state['config']['translate_max_output_tokens']} article_id={$articleId}."
          );
          // Save state BEFORE the API call so current_article_id + TRANSLATE_XX stage are on disk.
          save_state($state);

          $tTransStart = microtime(true);

          try {
            [$tr, $rawText, $apiMeta] = call_model_json(
              $key,
              $state['config']['model_translate'],
              $state['config']['translate_reasoning_effort'],
              (int)$state['config']['translate_max_output_tokens'],
              $prompt
            );
            $transDuration = round(microtime(true) - $tTransStart, 2);

            // Check if user requested skip while cURL was running
            if (consume_skip_signal($lang, (string)$articleId)) {
              $state['runtime']['skipped_langs'][$articleId][$lang] = true;
              $order = $state['config']['language_order'];
              $pos = array_search($lang, $order, true);
              if ($pos === false || $pos === count($order)-1) {
                $state['runtime']['current_stage'] = 'DONE';
                $state['runtime']['current_lang'] = null;
              } else {
                $nextLang = $order[$pos+1];
                $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
                $state['runtime']['current_lang'] = $nextLang;
              }
              $state['runtime']['retry_count'] = 0;
              $state['runtime']['last_error'] = null;
              $state['runtime']['current_step_start_ts'] = null;
              $state['runtime']['current_prompt'] = null;
              $state['runtime']['current_prompt_vars'] = null;
              log_event($state, 'warn', 'sprint', "USER FORCE-SKIP TRANSLATE {$lang} OK (pominięto po zakończeniu cURL) — article_id={$articleId}.");
              save_state($state);
              json_response(['ok'=>true,'state'=>$state,'force_skipped'=>true]);
            }

            $issues = validate_translation_object($lang, $tr);
            if ($issues) {
              $htmlPreview = mb_substr((string)($tr['content_html'] ?? ''), 0, 300);
              throw new RuntimeException(
                "Validation failed (" . count($issues) . " issue(s)): " . implode(' | ', $issues) .
                ($htmlPreview ? " | content_html_preview: {$htmlPreview}" : "")
              );
            }

            $tr['wp_id'] = null;
            $tr['slug_old'] = null;
            $tr['url_old'] = null;
            $tr['excerpt_html'] = '';
            $tr['metrics'] = compute_metrics_from_html((string)$tr['content_html']);
            $tr['seo_report'] = seo_report($tr); // internal for UI

            // Save translation
            $articles['articles'][$foundIdx]['translations'][$lang] = $tr;
            write_json_atomic($aPath, $articles);
            $fileSizeKb = round(filesize($aPath) / 1024, 1);

            // Update timing
            update_timing($state, 'translate', $transDuration);

            // advance stage
            $order = $state['config']['language_order'];
            $pos = array_search($lang, $order, true);
            if ($pos === false || $pos === count($order)-1) {
              $state['runtime']['current_stage'] = 'DONE';
              $state['runtime']['current_lang'] = null;
            } else {
              $nextLang = $order[$pos+1];
              $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
              $state['runtime']['current_lang'] = $nextLang;
            }
            $state['runtime']['retry_count'] = 0;
            $state['runtime']['last_error'] = null;
            $state['runtime']['last_output_preview'] = mb_substr($rawText, 0, 4000);
            $state['runtime']['current_step_start_ts'] = null;
            $state['runtime']['current_prompt'] = null;
            $state['runtime']['current_prompt_vars'] = null;

            $usageIn  = $apiMeta['usage']['input_tokens']  ?? '?';
            $usageOut = $apiMeta['usage']['output_tokens'] ?? '?';
            $trMet    = $tr['metrics'];
            log_event($state, 'success', 'sprint', "TRANSLATE {$lang} OK — article_id={$articleId} words={$trMet['words']} dur={$transDuration}s tokens={$usageIn}/{$usageOut} file={$fileSizeKb}KB.", [
              'seo' => $tr['seo_report']
            ]);
            log_event($state, 'info', 'action', "FILE SAVED — articles.json {$fileSizeKb}KB article_id={$articleId} lang={$lang}.");

            save_state($state);
            json_response(['ok'=>true,'state'=>$state]);

          } catch (Throwable $e) {
            $transDurationCatch = round(microtime(true) - $tTransStart, 2);

            // Check if user requested skip while cURL was running (e.g. during timeout)
            if (consume_skip_signal($lang, (string)$articleId)) {
              $state['runtime']['skipped_langs'][$articleId][$lang] = true;
              $order = $state['config']['language_order'];
              $pos = array_search($lang, $order, true);
              if ($pos === false || $pos === count($order)-1) {
                $state['runtime']['current_stage'] = 'DONE';
                $state['runtime']['current_lang'] = null;
              } else {
                $nextLang = $order[$pos+1];
                $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
                $state['runtime']['current_lang'] = $nextLang;
              }
              $state['runtime']['status'] = 'running';
              $state['runtime']['retry_count'] = 0;
              $state['runtime']['last_error'] = null;
              $state['runtime']['current_step_start_ts'] = null;
              $state['runtime']['current_prompt'] = null;
              $state['runtime']['current_prompt_vars'] = null;
              log_event($state, 'warn', 'sprint', "USER FORCE-SKIP TRANSLATE {$lang} OK (pominięto po błędzie/timeout cURL) — article_id={$articleId}.");
              save_state($state);
              json_response(['ok'=>true,'state'=>$state,'force_skipped'=>true]);
            }

            $isCurlTimeout = ($transDurationCatch >= 295
              || stripos($e->getMessage(), 'timed out') !== false
              || stripos($e->getMessage(), 'Operation timed out') !== false
              || stripos($e->getMessage(), 'cURL failed') !== false && stripos($e->getMessage(), 'Timeout') !== false
            );

            if ($isCurlTimeout) {
              // Timeout > 300s — pomiń to tłumaczenie bez retry
              $state['runtime']['skipped_langs'][$articleId][$lang] = true;
              log_event($state, 'warn', 'sprint', "TIMEOUT SKIP LANG {$lang} — article_id={$articleId} dur={$transDurationCatch}s (>300s limit). Pomijam bez retry.");
              // advance stage
              $order = $state['config']['language_order'];
              $pos = array_search($lang, $order, true);
              if ($pos === false || $pos === count($order)-1) {
                $state['runtime']['current_stage'] = 'DONE';
                $state['runtime']['current_lang'] = null;
              } else {
                $nextLang = $order[$pos+1];
                $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
                $state['runtime']['current_lang'] = $nextLang;
              }
              $state['runtime']['status'] = 'running';
              $state['runtime']['retry_count'] = 0;
              $state['runtime']['last_error'] = null;
              $state['runtime']['last_output_preview'] = "TIMEOUT: " . mb_substr($e->getMessage(), 0, 500);
              $state['runtime']['current_step_start_ts'] = null;
              $state['runtime']['current_prompt'] = null;
              $state['runtime']['current_prompt_vars'] = null;
              save_state($state);
              json_response(['ok'=>true,'state'=>$state,'auto_skipped'=>true,'timeout'=>true]);
            }

            $state['runtime']['retry_count'] = (int)$state['runtime']['retry_count'] + 1;
            $state['runtime']['last_error'] = ['type'=>'translate_failed','lang'=>$lang,'message'=>$e->getMessage()];
            log_event($state, 'error', 'action', "TRANSLATE {$lang} failed: " . $e->getMessage(), ['retry'=>$state['runtime']['retry_count']]);

            if ((int)$state['runtime']['retry_count'] <= (int)$state['runtime']['auto_retry_limit']) {
              log_event($state, 'warn', 'action', "Auto-retrying TRANSLATE {$lang} once...");
              save_state($state);
              json_response(['ok'=>true,'state'=>$state,'auto_retry'=>true]);
            }

            // Continue automatically: skip this language for this article, log where/why it failed, and move on.
            $state['runtime']['skipped_langs'][$articleId][$lang] = true;
            $reason = $e->getMessage();
            log_event($state, 'warn', 'sprint', "AUTO-SKIP LANG {$lang} — article_id={$articleId} error: {$reason}");

            // advance stage
            $order = $state['config']['language_order'];
            $pos = array_search($lang, $order, true);
            if ($pos === false || $pos === count($order)-1) {
              $state['runtime']['current_stage'] = 'DONE';
              $state['runtime']['current_lang'] = null;
            } else {
              $nextLang = $order[$pos+1];
              $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
              $state['runtime']['current_lang'] = $nextLang;
            }

            $state['runtime']['status'] = 'running';
            $state['runtime']['retry_count'] = 0;
            $state['runtime']['last_output_preview'] = mb_substr((string)$e->getMessage(), 0, 4000);
            $state['runtime']['current_step_start_ts'] = null;
            $state['runtime']['current_prompt'] = null;
            $state['runtime']['current_prompt_vars'] = null;

            save_state($state);
            json_response(['ok'=>true,'state'=>$state,'auto_skipped'=>true]);
          }
        }

        // DONE stage
        if ($stage === 'DONE') {
          $idx = (string)($state['runtime']['current_topic_uid'] ?? $state['runtime']['current_topic_index']);
          $isRewrite = !empty($state['runtime']['current_is_rewrite']);
          $phase = $state['runtime']['phase'] ?? 'write';
          // Only count towards completed_topics for real write-phase topics
          if ($phase === 'write' && !$isRewrite && $idx !== '' && !str_starts_with((string)$idx, '__')) {
            $state['runtime']['completed_topics'][] = $idx;
            $state['metrics']['global_done'] = count(array_unique($state['runtime']['completed_topics']));
          }
          log_event($state, 'success', 'global', "Sprint done — topic_index={$idx}" . ($isRewrite ? ' [REWRITE]' : '') . ($phase === 'translate' ? ' [TRANSLATE PHASE]' : '') . '.');

          // reset sprint
          $state['runtime']['current_topic_index'] = null;
          $state['runtime']['current_topic_uid'] = null;
          $state['runtime']['current_topic_title'] = null;
          $state['runtime']['current_article_id'] = null;
          $state['runtime']['current_stage'] = null;
          $state['runtime']['current_lang'] = null;
          $state['runtime']['current_is_rewrite'] = false;
          $state['runtime']['retry_count'] = 0;
          $state['runtime']['last_error'] = null;
          $state['runtime']['last_output_preview'] = null;
          $state['runtime']['current_step_start_ts'] = null;
          $state['runtime']['current_prompt'] = null;
          $state['runtime']['current_prompt_vars'] = null;

          // After a full sprint (PL + all translations): check for missing translations in other articles
          $langOrder = $state['config']['language_order'] ?? ['en','de','fr','it','cs','es'];
          if ($phase === 'translate') {
            // Side-task translate phase: check if more articles still need translations
            $missing = find_article_needing_translation($articles['articles'] ?? [], $langOrder, $state['runtime']['skipped_langs'] ?? []);
            if (!$missing) {
              // All caught up — return to write phase and continue
              $state['runtime']['phase'] = 'write';
              log_event($state, 'success', 'global', 'POBOCZNE: wszystkie brakujące tłumaczenia uzupełnione — kontynuuję pisanie.');
            }
          } else {
            // Normal write-phase sprint just finished (PL + 6 translations) — check for side tasks
            $missing = find_article_needing_translation($articles['articles'] ?? [], $langOrder, $state['runtime']['skipped_langs'] ?? []);
            if ($missing) {
              $state['runtime']['phase'] = 'translate';
              log_event($state, 'info', 'global', 'POBOCZNE: znaleziono artykuły z brakującymi tłumaczeniami — uzupełniam przed kolejnym sprintem.');
            }
            // status stays 'running' in both cases — no pause
          }

          save_state($state);
          json_response(['ok'=>true,'state'=>$state]);
        }

        // Unknown stage — this should never happen in normal flow.
        // If we have an active article_id it means we're mid-translate; stopping is safer than guessing.
        // If we have a topic_index but no article yet, reset the sprint so next run_step picks it fresh.
        $activeArticle = $state['runtime']['current_article_id'];
        if ($activeArticle) {
          // Mid-translate sprint with unknown stage — stop for user review
          $state['runtime']['status'] = 'waiting_user_decision';
          $state['runtime']['last_error'] = ['type'=>'unknown_stage','message'=>"Unknown stage '{$stage}' with active article {$activeArticle}. Manual intervention needed."];
          log_event($state, 'error', 'action', "Unknown stage '{$stage}' with active article {$activeArticle}. Stopping for review.");
        } else {
          // No active article — safe to reset the sprint; next run_step picks new topic
          $state['runtime']['current_topic_index'] = null;
          $state['runtime']['current_topic_uid'] = null;
          $state['runtime']['current_topic_title'] = null;
          $state['runtime']['current_stage'] = null;
          $state['runtime']['current_lang'] = null;
          log_event($state, 'warn', 'action', "Unknown stage '{$stage}' (no active article). Sprint reset — will pick next topic.");
        }
        save_state($state);
        json_response(['ok'=>true,'state'=>$state]);
      }

      json_response(['ok'=>false,'error'=>'Unknown action'], 400);
    });
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()], 500);
  }
}

/** =========================
 *  UI page
 *  ========================= */
$state = load_state();
$p = default_prompts();
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Generator artykułów — v<?= htmlspecialchars(APP_VERSION) ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= htmlspecialchars(APP_VERSION) ?>">

</head>
<body>
<div class="wrap">
  <div class="app-header">
    <h1>Generator artykułów + tłumaczenia</h1>
    <span class="ver">v<?= htmlspecialchars(APP_VERSION) ?></span>
  </div>

  <div class="grid-2">
    <!-- 1) API Key -->
    <div class="card">
      <div class="card-title"><span class="step-num">1</span> Klucz API OpenAI</div>
      <label>Wklej klucz (sesja — nie zapisuje na dysk)</label>
      <input id="apiKey" type="password" placeholder="sk-..." autocomplete="off"/>
      <div class="row" style="margin-top:8px">
        <div class="dot" id="keyDot"></div>
        <span class="small" id="keyStatus">brak klucza</span>
        <button class="btn btn-sm secondary" id="btnTestKey">Testuj</button>
      </div>
      <div id="diag" class="small" style="margin-top:6px"></div>
    </div>

    <!-- 2) Upload -->
    <div class="card">
      <div class="card-title"><span class="step-num">2</span> Pliki JSON</div>
      <form id="uploadForm">
        <div class="two">
          <div>
            <label>Plik A — artykuły (meta + articles[])</label>
            <input type="file" name="articles" accept="application/json" required style="font-size:12px"/>
          </div>
          <div>
            <label>Plik B — tematy (artykuly[])</label>
            <input type="file" name="topics" accept="application/json" required style="font-size:12px"/>
          </div>
        </div>
        <div class="row" style="margin-top:10px">
          <button class="btn btn-sm" type="submit">Wgraj i zweryfikuj</button>
          <span class="small" id="fileInfo"></span>
        </div>
      </form>
      <div class="small" style="margin-top:6px">Pliki zapisywane obok index.php. Postęp w <span class="mono">state.json</span>.</div>
    </div>
  </div>

  <div class="grid-2">
    <!-- 3) Config -->
    <div class="card">
      <div class="card-title"><span class="step-num">3</span> Konfiguracja</div>
      <div class="two">
        <div><label>Model: pisanie</label><input id="modelWrite" type="text" value="<?= htmlspecialchars($state['config']['model_write'] ?? 'gpt-5') ?>"/></div>
        <div><label>Model: tłumaczenie</label><input id="modelTranslate" type="text" value="<?= htmlspecialchars($state['config']['model_translate'] ?? 'gpt-5') ?>"/></div>
      </div>
      <div class="two" style="margin-top:8px">
        <div><label>Reasoning: pisanie</label>
          <select id="effWrite"><?php foreach(['minimal','low','medium','high','xhigh','none'] as $v): ?><option value="<?=$v?>" <?=(($state['config']['write_reasoning_effort']??'medium')===$v?'selected':'')?>><?=$v?></option><?php endforeach;?></select>
        </div>
        <div><label>Reasoning: tłumaczenie</label>
          <select id="effTranslate"><?php foreach(['minimal','low','medium','high','xhigh','none'] as $v): ?><option value="<?=$v?>" <?=(($state['config']['translate_reasoning_effort']??'low')===$v?'selected':'')?>><?=$v?></option><?php endforeach;?></select>
        </div>
      </div>
      <div class="two" style="margin-top:8px">
        <div><label>max_output_tokens: pisanie</label><input id="tokWrite" type="number" min="256" value="<?=(int)($state['config']['write_max_output_tokens']??12000)?>"/></div>
        <div><label>max_output_tokens: tłumaczenie</label><input id="tokTranslate" type="number" min="256" value="<?=(int)($state['config']['translate_max_output_tokens']??6000)?>"/></div>
      </div>
      <div class="info-box" style="margin-top:8px">
        <strong>Kategoria:</strong> wybierana automatycznie. Dozwolone: <span class="mono" style="font-size:11px">komunikacja-lekarz-pacjent, trudne-rozmowy, edukacja-pacjenta, telemedycyna, komunikacja-w-zespole, bezpieczenstwo-komunikacji</span>
      </div>
      <div class="row" style="margin-top:10px">
        <button class="btn btn-sm secondary" id="btnSaveCfg">Zapisz konfigurację</button>
        <span class="small" id="cfgInfo"></span>
      </div>
    </div>

    <!-- 4) Controls -->
    <div class="card">
      <div class="card-title"><span class="step-num">4</span> Sterowanie</div>
      <div class="row" style="flex-wrap:wrap;gap:6px">
        <button class="btn" id="btnStart">▶ Pisz artykuły (PL)</button>
        <button class="btn" id="btnStartTranslate" style="background:rgba(63,185,80,.15);border-color:rgba(63,185,80,.4);color:var(--ok)">🌐 Tłumacz wszystkie języki</button>
        <button class="btn secondary" id="btnPause">Pauza</button>
        <button class="btn secondary" id="btnResume">Wznów</button>
        <button class="btn danger btn-sm" id="btnStop">Stop</button>
      </div>
      <div class="kpi-row">
        <div class="kpi-box">
          <div class="kpi-label">Postęp globalny</div>
          <div class="kpi-value" id="kpiGlobal">—</div>
          <div style="margin-top:4px"><div class="progress-bar" style="height:6px"><div id="barGlobal" class="progress-fill" style="width:0%"></div></div></div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Aktualny temat</div>
          <div class="kpi-value" id="kpiSprint">—</div>
          <div class="kpi-sub" id="kpiSprint2">—</div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Etap</div>
          <div class="kpi-value" id="kpiAction">—</div>
          <div class="kpi-sub" id="kpiAction2">—</div>
        </div>
      </div>
      <div class="small" style="margin-top:8px"><span id="phaseLabel" class="pill info">faza: pisanie PL</span> &nbsp; Tłumaczenia: <span class="mono">en → de → fr → it → cs → es</span></div>
      <div id="skipTransWrap" style="display:none;margin-top:10px">
        <button class="btn btn-sm secondary" id="btnSkipTrans">⏭ Pomiń tłumaczenie: <span id="skipTransLabel">…</span></button>
      </div>
    </div>
  </div>

  <!-- 5) Prompts -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-title collapsible-toggle" id="promptToggle" onclick="toggleCollapsible('promptBody',this)">
      <span class="step-num">5</span> Prompty (kliknij aby rozwinąć/zwinąć)
    </div>
    <div id="promptBody" class="collapsible-body collapsed" style="max-height:0">
      <div class="two" style="margin-top:10px">
        <div><label>Prompt WRITE_PL</label><textarea id="promptWrite" style="min-height:180px"><?= htmlspecialchars($state['config']['prompt_write_pl'] ?? $p['write_pl']) ?></textarea></div>
        <div><label>Prompt TRANSLATE_LANG</label><textarea id="promptTranslate" style="min-height:180px"><?= htmlspecialchars($state['config']['prompt_translate_lang'] ?? $p['translate_lang']) ?></textarea></div>
      </div>
      <div class="row" style="margin-top:10px">
        <button class="btn btn-sm secondary" id="btnSavePrompts">Zapisz prompty</button>
        <span class="small" id="promptInfo"></span>
      </div>
    </div>
  </div>

  <!-- 6) Articles -->
  <div class="card" style="margin-bottom:16px" id="articlesCard">
    <div class="card-title" style="margin-bottom:8px">
      <span class="step-num">6</span> Artykuły
      <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
        <span class="small" id="articlesInfo"></span>
        <button class="btn btn-sm secondary" id="btnRefreshArticles">Odśwież</button>
      </div>
    </div>

    <!-- Progress overview -->
    <div class="progress-wrap" id="progressWrap" style="display:none">
      <div class="progress-header">
        <span><strong id="progressPct">0%</strong> ukończono</span>
        <span class="small" id="progressCount">0 / 0</span>
      </div>
      <div class="progress-bar"><div id="progressFill" class="progress-fill" style="width:0%"></div></div>
      <div class="progress-stats" id="progressStats" style="display:none"></div>
    </div>

    <!-- Table -->
    <div class="articles-scroll">
      <table id="articlesTable" style="min-width:700px">
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th style="width:52px"></th>
            <th>Tytuł</th>
            <th style="width:50px;text-align:center">PL</th>
            <th style="width:50px;text-align:center">EN</th>
            <th style="width:50px;text-align:center">DE</th>
            <th style="width:50px;text-align:center">FR</th>
            <th style="width:50px;text-align:center">IT</th>
            <th style="width:50px;text-align:center">CS</th>
            <th style="width:50px;text-align:center">ES</th>
          </tr>
        </thead>
        <tbody id="articlesBody">
          <tr><td colspan="10" class="small">Wczytaj pliki JSON, aby zobaczyć artykuły.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 7) Log -->
  <div class="card">
    <div class="card-title"><span class="step-num">7</span> Log zdarzeń</div>
    <div class="log-scroll">
      <table>
        <thead>
          <tr>
            <th style="width:145px">Czas</th>
            <th style="width:70px">Poziom</th>
            <th style="width:70px">Zakres</th>
            <th>Opis</th>
            <th style="width:180px">Szczegóły</th>
          </tr>
        </thead>
        <tbody id="logBody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Decision modal -->
<div class="modal" id="modal">
  <div class="panel">
    <div class="modal-header">
      <h2>Wymagana decyzja</h2>
      <span class="pill warn" id="modalHint">oczekuje</span>
    </div>
    <div class="small" id="modalMsg" style="margin-bottom:10px"></div>
    <div class="two">
      <div><label>Błąd / powód</label><pre id="modalErr" style="max-height:200px"></pre></div>
      <div><label>Podgląd output</label><pre id="modalOut" style="max-height:200px"></pre></div>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="btn btn-sm" id="btnDecisionRetry">Ponów</button>
      <button class="btn btn-sm secondary" id="btnDecisionSkipLang">Pomiń język</button>
      <button class="btn btn-sm secondary" id="btnDecisionSkipTopic">Pomiń temat</button>
      <button class="btn btn-sm danger" id="btnDecisionStopSprint">Stop (pauza)</button>
      <button class="btn btn-sm secondary" id="btnDecisionClose">Zamknij</button>
    </div>
    <div class="small" style="margin-top:6px">"Pomiń język" — tylko przy TRANSLATE. "Pomiń temat" — przy WRITE_PL lub ogólnym błędzie.</div>
  </div>
</div>

<!-- Preview modal -->
<div class="modal" id="previewModal">
  <div class="panel" style="max-width:980px">
    <div class="modal-header">
      <h2 id="previewModalTitle">Podgląd artykułu</h2>
      <button class="btn btn-sm secondary" onclick="closePreview()">Zamknij</button>
    </div>
    <div style="overflow-x:auto;margin-bottom:12px;border:1px solid var(--border);border-radius:var(--radius)">
      <table style="min-width:500px">
        <thead><tr><th>Pole SEO</th><th>Wartość</th><th style="width:50px;text-align:center">Dł.</th><th style="width:70px;text-align:center">Cel</th><th style="width:80px;text-align:center">Status</th></tr></thead>
        <tbody id="previewSeoBody"><tr><td colspan="5" class="small">Ładowanie…</td></tr></tbody>
      </table>
    </div>
    <div id="previewArticleContent" style="background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px;max-height:55vh;overflow-y:auto"></div>
  </div>
</div>

<!-- Prompt modal -->
<div class="modal" id="promptModal">
  <div class="panel" style="max-width:1100px">
    <div class="modal-header">
      <h2 id="promptModalTitle">Prompt</h2>
      <button class="btn btn-sm secondary" onclick="closePromptModal()">Zamknij</button>
    </div>
    <div class="prompt-section">
      <div>
        <label>Pełny prompt (szablon + dane)</label>
        <div class="prompt-content" id="promptModalContent"></div>
      </div>
      <div>
        <label>Zmienne (dane z JSON)</label>
        <div style="overflow:auto;max-height:65vh">
          <table class="vars-table" id="promptModalVars"></table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Delete article modal -->
<div class="modal" id="deleteModal">
  <div class="panel" style="max-width:800px">
    <div class="modal-header">
      <h2 id="deleteModalTitle">Usuń artykuł</h2>
      <button class="btn btn-sm secondary" onclick="closeDeleteModal()">Zamknij</button>
    </div>
    <div style="background:rgba(248,81,73,.08);border:1px solid rgba(248,81,73,.3);border-radius:var(--radius);padding:12px;margin-bottom:12px">
      <p style="margin:0 0 10px;font-size:13px">Artykuł zostanie <strong>trwale usunięty</strong> z bazy i przeniesiony do pliku kopii zapasowej <span class="mono">deleted_articles.json</span>. Tej operacji nie można cofnąć z poziomu aplikacji.</p>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text)">
        <input type="checkbox" id="deleteConfirmCheck" onchange="$('btnDeleteConfirm').disabled=!this.checked" style="width:auto"/>
        Rozumiem i chcę usunąć ten artykuł wraz ze wszystkimi tłumaczeniami
      </label>
    </div>
    <div style="overflow-x:auto;margin-bottom:12px;border:1px solid var(--border);border-radius:var(--radius)">
      <table style="min-width:500px">
        <thead><tr><th>Pole SEO</th><th>Wartość</th><th style="width:50px;text-align:center">Dł.</th><th style="width:70px;text-align:center">Cel</th><th style="width:80px;text-align:center">Status</th></tr></thead>
        <tbody id="deleteSeoBody"><tr><td colspan="5" class="small">Ładowanie…</td></tr></tbody>
      </table>
    </div>
    <div id="deleteArticleContent" style="background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px;max-height:40vh;overflow-y:auto;margin-bottom:12px"></div>
    <div class="row">
      <button class="btn danger" id="btnDeleteConfirm" disabled onclick="confirmDeleteArticle()">Tak, usuń artykuł</button>
      <button class="btn btn-sm secondary" onclick="closeDeleteModal()">Anuluj</button>
    </div>
  </div>
</div>

<!-- Rewrite article modal -->
<div class="modal" id="rewriteModal">
  <div class="panel" style="max-width:900px">
    <div class="modal-header">
      <h2 id="rewriteModalTitle">Przepisz artykuł</h2>
      <button class="btn btn-sm secondary" onclick="closeRewriteModal()">Zamknij</button>
    </div>
    <p class="small" style="margin:0 0 12px">Poniżej znajdziesz prompt do edycji. Wpisz swoje uwagi i rozpocznij proces pisania i tłumaczenia jeszcze raz. Oryginalny artykuł zostanie zarchiwizowany i zastąpiony nową wersją.</p>
    <label>Twoje uwagi (co zmienić w artykule)</label>
    <textarea id="rewriteNotes" style="min-height:80px;margin-bottom:12px" placeholder="np. artykuł jest za długi, zbyt skomplikowany język, brakuje przykładów praktycznych..." oninput="updateRewritePromptPreview()"></textarea>
    <div>
      <label>Podgląd prompta (tylko do wglądu — prompt końcowy generowany jest przez serwer)</label>
      <div class="prompt-content" id="rewritePromptPreview" style="max-height:35vh;font-size:11px"></div>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="btn" id="btnRewriteConfirm" onclick="confirmRewriteArticle()">Przepisz i przetłumacz ponownie</button>
      <button class="btn btn-sm secondary" onclick="closeRewriteModal()">Anuluj</button>
    </div>
  </div>
</div>

<script src="assets/js/app.js?v=<?= htmlspecialchars(APP_VERSION) ?>"></script>

</body>
</html>
