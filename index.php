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
const STATE_FILE = __DIR__ . '/state.json';
const LOCK_FILE  = __DIR__ . '/.app.lock';
const SKIP_SIGNAL_FILE  = __DIR__ . '/.skip_translation';
const BACKUP_FILE       = __DIR__ . '/deleted_articles.json';

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
      'completed_topics' => [], // list of topic indices
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
    if ($idx === '') continue;
    if (isset($done[$idx]) || isset($skip[$idx])) continue;
    return $t;
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
        $topicsList = $topicsData['artykuly'] ?? [];
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
    $topicIdx = trim((string)($_GET['topic_index'] ?? ''));
    if ($topicIdx === '') json_response(['ok'=>false,'error'=>'Missing topic_index'], 400);
    $state = load_state();
    $bPath = $state['files']['topics_path'] ?? null;
    $aPath = $state['files']['articles_path'] ?? null;
    if (!$bPath || !is_file($bPath)) json_response(['ok'=>false,'error'=>'No topics file configured'], 400);
    try { $topics = read_topics_file($bPath); }
    catch (Throwable $e) { json_response(['ok'=>false,'error'=>'Cannot read topics: '.$e->getMessage()], 500); }
    $topic = null;
    foreach (($topics['artykuly'] ?? []) as $tt) {
      if (is_array($tt) && (string)($tt['index'] ?? '') === $topicIdx) { $topic = $tt; break; }
    }
    if (!$topic) json_response(['ok'=>false,'error'=>"Topic index {$topicIdx} not found"], 404);
    // Placeholder IDs for preview only (not saved anywhere)
    $articles = [];
    if ($aPath && is_file($aPath)) {
      try { $articles = read_json_file($aPath); } catch (Throwable $e) { $articles = ['articles'=>[]]; }
    }
    $articleId = generate_next_article_id($articles);
    $group = 'pll_preview_' . substr(md5($topicIdx . 'x'), 0, 8);
    $vars = [
      'ARTICLE_ID'       => $articleId,
      'TRANSLATION_GROUP'=> $group,
      'TOPIC_INDEX'      => (string)($topic['index'] ?? ''),
      'TOPIC_TITLE'      => (string)($topic['tytul'] ?? ''),
      'TOPIC_SHORT_DESC' => (string)($topic['opis_ogolny'] ?? ''),
      'TOPIC_LONG_DESC'  => (string)($topic['opis_szczegolowy'] ?? ''),
    ];
    $prompt = render_template($state['config']['prompt_write_pl'], $vars);
    json_response(['ok'=>true,'prompt'=>$prompt,'vars'=>$vars,'topic_index'=>$topicIdx,'topic_title'=>(string)($topic['tytul']??'')]);
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
    $tIdx = (string)($found['_topic_index'] ?? '');
    $bPath = $state['files']['topics_path'] ?? null;
    if ($tIdx !== '' && $bPath && is_file($bPath)) {
      try {
        $topicsData = read_topics_file($bPath);
        foreach (($topicsData['artykuly'] ?? []) as $tt) {
          if (is_array($tt) && (string)($tt['index'] ?? '') === $tIdx) { $topic = $tt; break; }
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
            $t = get_next_topic($topics, $state['runtime']['completed_topics'], $state['runtime']['skipped_topics']);
            if (!$t) {
              $state['runtime']['status'] = 'done';
              log_event($state, 'success', 'global', 'Wszystkie artykuły napisane po polsku. Możesz teraz uruchomić tłumaczenia.');
              save_state($state);
              json_response(['ok'=>true,'done'=>true,'state'=>$state]);
            }
            $state['runtime']['current_topic_index'] = $t['index'];
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
            foreach (($topics['artykuly'] ?? []) as $tt) {
              if (is_array($tt) && (string)($tt['index'] ?? '') === $tIdx) { $topic = $tt; break; }
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
            $idx = $state['runtime']['current_topic_index'];
            if ($idx !== null) {
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
          $idx = $state['runtime']['current_topic_index'];
          $isRewrite = !empty($state['runtime']['current_is_rewrite']);
          $phase = $state['runtime']['phase'] ?? 'write';
          // Only count towards completed_topics for real write-phase topics
          if ($phase === 'write' && !$isRewrite && $idx !== null && !str_starts_with((string)$idx, '__')) {
            $state['runtime']['completed_topics'][] = $idx;
            $state['metrics']['global_done'] = count(array_unique($state['runtime']['completed_topics']));
          }
          log_event($state, 'success', 'global', "Sprint done — topic_index={$idx}" . ($isRewrite ? ' [REWRITE]' : '') . ($phase === 'translate' ? ' [TRANSLATE PHASE]' : '') . '.');

          // reset sprint
          $state['runtime']['current_topic_index'] = null;
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
  <style>
    :root{--bg:#0d1117;--card:#161b22;--border:#30363d;--text:#e6edf3;--muted:#8b949e;--ok:#3fb950;--bad:#f85149;--warn:#d29922;--info:#58a6ff;--btn:#388bfd;--btn-hover:#58a6ff;--btn2:#21262d;--input-bg:#0d1117;--radius:8px;--radius-lg:12px;--shadow:0 1px 3px rgba(0,0,0,.3)}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5}
    .wrap{max-width:1200px;margin:0 auto;padding:20px}
    .app-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
    .app-header h1{font-size:18px;font-weight:600;margin:0;color:var(--text)}
    .app-header .ver{font-size:12px;color:var(--muted);background:var(--btn2);padding:2px 8px;border-radius:12px}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px;box-shadow:var(--shadow)}
    .card-title{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:14px;font-weight:600}
    .step-num{width:24px;height:24px;border-radius:50%;background:var(--btn);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    label{font-size:12px;color:var(--muted);display:block;margin-bottom:4px;font-weight:500}
    input[type="text"],input[type="password"],input[type="number"],textarea,select{width:100%;background:var(--input-bg);border:1px solid var(--border);color:var(--text);border-radius:var(--radius);padding:8px 10px;outline:none;font-size:13px;transition:border-color .15s}
    input:focus,textarea:focus,select:focus{border-color:var(--btn)}
    textarea{min-height:120px;font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;font-size:12px;line-height:1.4;resize:vertical}
    .btn{border:0;border-radius:var(--radius);padding:8px 14px;color:#fff;background:var(--btn);cursor:pointer;font-size:13px;font-weight:500;transition:background .15s;display:inline-flex;align-items:center;gap:6px}
    .btn:hover{background:var(--btn-hover)}
    .btn.secondary{background:var(--btn2);color:var(--text)}
    .btn.secondary:hover{background:#30363d}
    .btn.danger{background:#da3633}
    .btn.danger:hover{background:#f85149}
    .btn:disabled{opacity:.4;cursor:not-allowed;pointer-events:none}
    .btn-sm{padding:5px 10px;font-size:12px}
    .dot{width:10px;height:10px;border-radius:50%;display:inline-block;background:var(--bad);flex-shrink:0}
    .dot.ok{background:var(--ok)}
    .dot.warn{background:var(--warn)}
    .small{font-size:12px;color:var(--muted)}
    .mono{font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .info-box{background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);padding:10px;font-size:12px;color:var(--muted)}
    /* KPIs */
    .kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}
    .kpi-box{background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);padding:10px}
    .kpi-label{font-size:11px;color:var(--muted);margin-bottom:2px;text-transform:uppercase;letter-spacing:.5px}
    .kpi-value{font-size:15px;font-weight:700}
    .kpi-sub{font-size:11px;color:var(--muted);margin-top:2px}
    /* Progress bar */
    .progress-wrap{margin-bottom:16px}
    .progress-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:13px}
    .progress-bar{height:8px;background:var(--input-bg);border:1px solid var(--border);border-radius:99px;overflow:hidden}
    .progress-fill{height:100%;background:linear-gradient(90deg,var(--btn),var(--ok));border-radius:99px;transition:width .4s ease}
    .progress-stats{display:flex;flex-wrap:wrap;gap:16px;margin-top:10px;padding:10px 12px;background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);font-size:12px}
    .progress-stats .stat{display:flex;align-items:center;gap:4px}
    .progress-stats .stat strong{color:var(--text)}
    .progress-stats .stat .label{color:var(--muted)}
    /* Tables */
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid var(--border);padding:8px 6px;font-size:12px;vertical-align:middle}
    th{color:var(--muted);text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;position:sticky;top:0;background:var(--card);z-index:1}
    /* Articles */
    .articles-scroll{overflow:auto;max-height:500px;border:1px solid var(--border);border-radius:var(--radius-lg)}
    .separator-row td{padding:0;border:0}
    .separator-line{display:flex;align-items:center;gap:12px;padding:12px 8px;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:1px}
    .separator-line::before,.separator-line::after{content:'';flex:1;height:1px;background:var(--border)}
    /* Status icons */
    .st{display:inline-flex;align-items:center;justify-content:center;gap:4px;min-width:28px;height:24px;border-radius:6px;font-size:11px;font-weight:600;cursor:default;position:relative;transition:background .15s}
    .st-pending{color:#484f58}
    .st-pending::before{content:'—'}
    .st-writing,.st-translating{background:rgba(210,153,34,.15);color:var(--warn);cursor:pointer;padding:0 6px}
    .st-writing::before{content:'✍';animation:pulse 1.5s infinite}
    .st-translating::before{content:'⟳';animation:spin 2s linear infinite;display:inline-block}
    .st-error{background:rgba(248,81,73,.15);color:var(--bad)}
    .st-error::before{content:'✗'}
    .st-warning{background:rgba(210,153,34,.15);color:var(--warn)}
    .st-warning::before{content:'⚠'}
    .st-saving{background:rgba(56,139,253,.15);color:var(--info)}
    .st-saving::before{content:'💾';animation:pulse 1s infinite}
    .st-done{background:rgba(63,185,80,.1);color:var(--ok);cursor:pointer;padding:0 6px}
    .st-done::before{content:'✓'}
    .st-done:hover{background:rgba(63,185,80,.25)}
    .st-skipped{background:rgba(210,153,34,.1);color:#e3b341;padding:0 6px;font-size:10px}
    .st-skipped::before{content:'skip'}
    .elapsed{font-size:10px;color:var(--warn);margin-left:2px;font-variant-numeric:tabular-nums}
    .st-skip-btn{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:3px;border:1px solid rgba(210,153,34,.4);background:rgba(210,153,34,.1);color:var(--warn);font-size:10px;cursor:pointer;margin-left:3px;line-height:1;padding:0;flex-shrink:0}
    .st-skip-btn:hover{background:rgba(210,153,34,.3)}
    .art-actions{display:inline-flex;gap:4px;align-items:center}
    .art-btn{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:5px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:13px;cursor:pointer;padding:0;transition:background .15s,color .15s,border-color .15s}
    .art-btn:hover{border-color:var(--btn);color:var(--btn);background:rgba(56,139,253,.1)}
    .art-btn.danger:hover{border-color:var(--bad);color:var(--bad);background:rgba(248,81,73,.1)}
    /* Tooltip */
    [data-tip]{position:relative}
    [data-tip]:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#1c2129;color:var(--text);padding:4px 8px;border-radius:6px;font-size:11px;white-space:nowrap;z-index:100;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.4);pointer-events:none}
    /* Pill */
    .pill{padding:2px 8px;border-radius:99px;border:1px solid var(--border);font-size:11px;color:var(--muted);font-weight:500}
    .pill.ok{border-color:rgba(63,185,80,.4);color:var(--ok)}
    .pill.bad{border-color:rgba(248,81,73,.4);color:var(--bad)}
    .pill.warn{border-color:rgba(210,153,34,.4);color:var(--warn)}
    .pill.info{border-color:rgba(88,166,255,.4);color:var(--info)}
    /* Modal */
    .modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;z-index:1000;backdrop-filter:blur(2px)}
    .modal.open{display:flex}
    .modal .panel{max-width:980px;width:100%;background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;box-shadow:0 8px 30px rgba(0,0,0,.5);max-height:90vh;overflow-y:auto}
    .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
    .modal-header h2{margin:0;font-size:16px}
    pre{background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);padding:12px;overflow:auto;max-height:320px;font-size:12px;line-height:1.5}
    /* Preview */
    .preview-wrap{font-size:13px;line-height:1.7;color:#c0d0ec}
    .preview-wrap h1{font-size:18px;margin:0 0 12px;color:var(--text)}
    .preview-wrap h2{font-size:15px;margin:16px 0 6px;color:var(--info);font-weight:600}
    .preview-wrap p{margin:0 0 10px}
    .preview-wrap ul{margin:0 0 10px;padding-left:18px}
    .preview-wrap li{margin-bottom:3px}
    .preview-wrap section.tldr{background:#0a1530;border-left:3px solid var(--btn);padding:10px 14px;border-radius:0 8px 8px 0;margin-bottom:14px}
    .preview-wrap p.summary{border-top:1px solid var(--border);padding-top:10px;color:var(--info);font-style:italic}
    /* Prompt modal */
    .prompt-section{display:grid;grid-template-columns:1fr 300px;gap:14px;margin-top:10px}
    .prompt-content{background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);padding:14px;overflow:auto;max-height:65vh;font-family:"SFMono-Regular",Consolas,monospace;font-size:11px;line-height:1.6;white-space:pre-wrap;word-break:break-word}
    .prompt-var{background:rgba(210,153,34,.2);color:#e3b341;padding:1px 4px;border-radius:3px;border:1px solid rgba(210,153,34,.3)}
    .prompt-sys{color:#8b949e}
    .vars-table{font-size:12px}
    .vars-table td{padding:6px 8px;vertical-align:top;word-break:break-all}
    .vars-table .vname{color:var(--info);font-weight:600;white-space:nowrap}
    .vars-table .vval{color:var(--warn);max-width:200px;overflow:hidden;text-overflow:ellipsis}
    /* SEO */
    .seo-ok{color:var(--ok)}.seo-warn{color:var(--warn)}.seo-bad{color:var(--bad)}
    /* Collapsible */
    .collapsible-toggle{cursor:pointer;user-select:none}
    .collapsible-toggle::after{content:'▾';margin-left:6px;font-size:10px;transition:transform .2s}
    .collapsible-toggle.collapsed::after{transform:rotate(-90deg)}
    .collapsible-body{transition:max-height .3s ease;overflow:hidden}
    .collapsible-body.collapsed{max-height:0!important;padding-top:0!important;padding-bottom:0!important}
    /* Log */
    .log-scroll{overflow:auto;max-height:400px;border:1px solid var(--border);border-radius:var(--radius-lg)}
    .log-row-error{background:rgba(248,81,73,.04)}
    .log-row-success{background:rgba(63,185,80,.04)}
    .log-row-warn{background:rgba(210,153,34,.04)}
    /* Animations */
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
    @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
    /* Responsive */
    @media(max-width:980px){.grid-2{grid-template-columns:1fr}.two{grid-template-columns:1fr}.kpi-row{grid-template-columns:1fr}.prompt-section{grid-template-columns:1fr}}
  </style>
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

<script>
/* ═══════════════════════════════════════════════════════════════
   API + STATE
   ═══════════════════════════════════════════════════════════════ */
const api = async (action, formData=null) => {
  const opts = {method: formData ? 'POST' : 'GET'};
  if (formData) opts.body = formData;
  const res = await fetch(`?action=${encodeURIComponent(action)}`, opts);
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch(e){ throw new Error('Nieprawidłowy JSON: ' + text.slice(0,300)); }
  if (!res.ok) throw new Error(json.error || ('HTTP ' + res.status));
  return json;
};

const $ = id => document.getElementById(id);
const esc = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

let stateCache = null;
let keyOk = false;
let runnerActive = false;
let _articleSync = 0;
let _elapsedInterval = null;
let _deleteArticleId = null;
let _rewriteArticleId = null;
let _rewriteArticleData = null; // {article, topic}

/* ═══════════════════════════════════════════════════════════════
   COLLAPSIBLE
   ═══════════════════════════════════════════════════════════════ */
function toggleCollapsible(bodyId, toggle) {
  const body = $(bodyId);
  if (!body) return;
  const collapsed = body.classList.toggle('collapsed');
  if (toggle) toggle.classList.toggle('collapsed', collapsed);
  if (!collapsed) body.style.maxHeight = body.scrollHeight + 'px';
}

/* ═══════════════════════════════════════════════════════════════
   KEY
   ═══════════════════════════════════════════════════════════════ */
const setKeyUi = (ok, msg='', diag='') => {
  keyOk = ok;
  $('keyDot').classList.toggle('ok', ok);
  $('keyStatus').textContent = ok ? 'Klucz OK' : (msg || 'brak klucza');
  $('diag').textContent = diag || '';
  refreshControls();
};

const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; };

const testKey = async () => {
  try { await api('test_api_key'); setKeyUi(true, 'OK', 'Test zakończony pomyślnie'); }
  catch(e) { setKeyUi(false, 'błąd', e.message); }
  finally { await syncStatus(); }
};

const setKey = debounce(async () => {
  const v = $('apiKey').value.trim();
  if (!v) { setKeyUi(false, 'brak klucza'); return; }
  try { const fd = new FormData(); fd.append('api_key', v); await api('set_api_key', fd); await testKey(); }
  catch(e) { setKeyUi(false, 'błąd', e.message); }
}, 800);

$('apiKey').addEventListener('input', setKey);
$('btnTestKey').addEventListener('click', testKey);

/* ═══════════════════════════════════════════════════════════════
   UPLOAD
   ═══════════════════════════════════════════════════════════════ */
$('uploadForm').addEventListener('submit', async ev => {
  ev.preventDefault();
  try {
    const r = await api('upload_files', new FormData($('uploadForm')));
    $('fileInfo').textContent = `Wgrano: ${r.articles_count} art., ${r.topics_count} tematów`;
    $('fileInfo').style.color = 'var(--ok)';
    await syncStatus();
    await fetchArticles();
  } catch(e) { $('fileInfo').textContent = e.message; $('fileInfo').style.color = 'var(--bad)'; }
});

/* ═══════════════════════════════════════════════════════════════
   CONFIG + PROMPTS
   ═══════════════════════════════════════════════════════════════ */
const saveCfg = async () => {
  const fd = new FormData();
  fd.append('model_write', $('modelWrite').value.trim());
  fd.append('model_translate', $('modelTranslate').value.trim());
  fd.append('write_reasoning_effort', $('effWrite').value);
  fd.append('translate_reasoning_effort', $('effTranslate').value);
  fd.append('write_max_output_tokens', $('tokWrite').value);
  fd.append('translate_max_output_tokens', $('tokTranslate').value);
  fd.append('prompt_write_pl', $('promptWrite').value);
  fd.append('prompt_translate_lang', $('promptTranslate').value);
  try {
    await api('save_config', fd);
    $('cfgInfo').textContent = 'Zapisano';
    $('cfgInfo').style.color = 'var(--ok)';
    if ($('promptInfo')) { $('promptInfo').textContent = 'Zapisano'; $('promptInfo').style.color = 'var(--ok)'; }
    await syncStatus();
    setTimeout(()=>{ $('cfgInfo').textContent=''; if ($('promptInfo')) $('promptInfo').textContent=''; }, 2000);
  } catch(e) { $('cfgInfo').textContent = 'Błąd: '+e.message; $('cfgInfo').style.color = 'var(--bad)'; if ($('promptInfo')) { $('promptInfo').textContent = 'Błąd'; $('promptInfo').style.color = 'var(--bad)'; } }
};
$('btnSaveCfg').addEventListener('click', saveCfg);
$('btnSavePrompts').addEventListener('click', saveCfg);

/* ═══════════════════════════════════════════════════════════════
   CONTROLS
   ═══════════════════════════════════════════════════════════════ */
const refreshControls = () => {
  const hasFiles = !!(stateCache?.state?.files?.articles_path && stateCache?.state?.files?.topics_path);
  const status = stateCache?.state?.runtime?.status || 'idle';
  const idle = ['idle','stopped','done','paused'].includes(status);
  $('btnStart').disabled          = !(keyOk && hasFiles && idle);
  $('btnStartTranslate').disabled = !(keyOk && hasFiles && idle);
  $('btnPause').disabled          = status !== 'running';
  $('btnResume').disabled         = !(keyOk && hasFiles && status === 'paused');
  $('btnStop').disabled           = !['running','paused'].includes(status);
};

$('btnStart').addEventListener('click', async () => { await saveCfg(); await api('start'); await syncStatus(); startRunner(); });
$('btnStartTranslate').addEventListener('click', async () => { await saveCfg(); await api('start_translate'); await syncStatus(); startRunner(); });
$('btnPause').addEventListener('click', async () => { await api('pause'); await syncStatus(); });
$('btnResume').addEventListener('click', async () => { await api('resume'); await syncStatus(); startRunner(); });
$('btnStop').addEventListener('click', async () => { await api('stop'); await syncStatus(); });

const forceSkipTranslation = async () => {
  try {
    await api('force_skip_translation');
    await syncStatus();
    await fetchArticles();
  } catch(e) { console.error('forceSkipTranslation:', e); }
};
$('btnSkipTrans').addEventListener('click', forceSkipTranslation);

/* ═══════════════════════════════════════════════════════════════
   DECISION MODAL
   ═══════════════════════════════════════════════════════════════ */
$('btnDecisionRetry').addEventListener('click', async ()=>{ await api('decision_retry'); await syncStatus(); startRunner(); });
$('btnDecisionSkipLang').addEventListener('click', async ()=>{ await api('decision_skip_lang'); await syncStatus(); startRunner(); });
$('btnDecisionSkipTopic').addEventListener('click', async ()=>{ await api('decision_skip_topic'); await syncStatus(); startRunner(); });
$('btnDecisionStopSprint').addEventListener('click', async ()=>{ await api('decision_stop_sprint'); await syncStatus(); });
$('btnDecisionClose').addEventListener('click', ()=>{ $('modal').classList.remove('open'); });

const maybeOpenModal = st => {
  if (st.runtime?.status !== 'waiting_user_decision') { $('modal').classList.remove('open'); return; }
  $('modal').classList.add('open');
  $('modalMsg').textContent = `Etap: ${st.runtime?.current_stage||'—'} | Język: ${st.runtime?.current_lang||'—'} | Artykuł: ${st.runtime?.current_article_id||'—'}`;
  $('modalErr').textContent = st.runtime?.last_error ? JSON.stringify(st.runtime.last_error, null, 2) : '(brak)';
  $('modalOut').textContent = st.runtime?.last_output_preview || '(brak)';
  $('btnDecisionSkipLang').disabled = !String(st.runtime?.current_stage||'').startsWith('TRANSLATE_');
};

/* ═══════════════════════════════════════════════════════════════
   KPIs
   ═══════════════════════════════════════════════════════════════ */
const renderKpis = st => {
  const total = st.metrics?.global_total ?? 0;
  const done = st.metrics?.global_done ?? 0;
  const pct = total ? Math.round(done/total*100) : 0;
  $('kpiGlobal').textContent = `${done}/${total} (${pct}%)`;
  $('barGlobal').style.width = pct+'%';

  const tIdx = st.runtime?.current_topic_index;
  $('kpiSprint').textContent = tIdx != null ? `#${tIdx}` : '—';
  $('kpiSprint2').textContent = st.runtime?.current_topic_title || '—';

  $('kpiAction').textContent = st.runtime?.current_stage || '—';
  const status = st.runtime?.status || '—';
  const lang = st.runtime?.current_lang || '';
  $('kpiAction2').textContent = `${status}${lang ? ' · '+lang : ''}`;

  // Phase label
  const phase = st.runtime?.phase || 'write';
  const phaseLbl = $('phaseLabel');
  if (phaseLbl) {
    phaseLbl.textContent = phase === 'translate' ? 'faza: tłumaczenie' : 'faza: pisanie PL';
    phaseLbl.className = 'pill ' + (phase === 'translate' ? 'ok' : 'info');
  }

  // Skip translation button visibility
  const isTranslating = status === 'running' && String(st.runtime?.current_stage||'').startsWith('TRANSLATE_');
  $('skipTransWrap').style.display = isTranslating ? '' : 'none';
  if (isTranslating) {
    const title = st.runtime?.current_topic_title || st.runtime?.current_article_id || '?';
    const langUp = lang.toUpperCase();
    $('skipTransLabel').textContent = `${title} [${langUp}]`;
  }
};

/* ═══════════════════════════════════════════════════════════════
   LOG
   ═══════════════════════════════════════════════════════════════ */
const renderLog = (log=[]) => {
  const body = $('logBody');
  if (!log.length) { body.innerHTML = '<tr><td colspan="5" class="small">Brak zdarzeń.</td></tr>'; return; }

  body.innerHTML = log.slice().reverse().map(e => {
    const lvl = e.level || 'info';
    const pillCls = lvl==='success'?'ok':lvl==='error'?'bad':lvl==='warn'?'warn':'info';
    const rowCls = lvl==='error'?'log-row-error':lvl==='success'?'log-row-success':lvl==='warn'?'log-row-warn':'';

    // Build a clear, short extra description
    let extra = '';
    if (e.seo) {
      const s = e.seo;
      const parts = [];
      if (s.title_len != null) parts.push(`tytuł:${s.title_len}zn`);
      if (s.meta_title_len != null) parts.push(`meta:${s.meta_title_len}zn`);
      if (s.meta_description_len != null) parts.push(`opis:${s.meta_description_len}zn`);
      if (s.slug_len != null) parts.push(`slug:${s.slug_len}zn`);
      extra = parts.join(', ');
    } else if (e.articles_count != null) {
      extra = `${e.articles_count} art., ${e.topics_count} tem.`;
    } else if (e.retry != null) {
      extra = `próba #${e.retry}`;
    } else if (e.preview) {
      extra = e.preview.slice(0, 60) + (e.preview.length > 60 ? '…' : '');
    }

    // Format timestamp nicely
    const ts = e.ts ? e.ts.replace('T',' ').replace(/\.\d+/,'').replace('Z',' UTC') : '';

    // Shorten message: make it more user-friendly
    let msg = (e.message || '').replace(/</g,'&lt;');

    return `<tr class="${rowCls}">
      <td class="mono small" style="white-space:nowrap">${esc(ts)}</td>
      <td><span class="pill ${pillCls}">${lvl}</span></td>
      <td class="small">${esc(e.scope||'')}</td>
      <td style="font-size:12px">${msg}</td>
      <td class="mono small" style="color:var(--muted)" title="${esc(extra)}">${esc(extra.length>50?extra.slice(0,50)+'…':extra)}</td>
    </tr>`;
  }).join('');
};

/* ═══════════════════════════════════════════════════════════════
   ARTICLES TABLE
   ═══════════════════════════════════════════════════════════════ */
const langCols = ['pl','en','de','fr','it','cs','es'];
let _lastTopics = [];

const fetchArticles = async () => {
  try {
    const r = await api('get_articles');
    _lastTopics = r.topics || [];
    renderArticlesTable(r.articles||[], _lastTopics, r.runtime||{}, r.timing||{});
  } catch(e) { console.warn('fetchArticles:', e); }
};

$('btnRefreshArticles').addEventListener('click', fetchArticles);

const renderArticlesTable = (articles, topics, runtime, timing) => {
  const body = $('articlesBody');
  if (!body) return;

  const curId = runtime.current_article_id;
  const curStage = runtime.current_stage || '';
  const skippedLangs = runtime.skipped_langs || {};
  const completedTopics = (runtime.completed_topics || []).map(String);
  const skippedTopics = (runtime.skipped_topics || []).map(String);
  const doneSet = new Set(completedTopics);
  const skipSet = new Set(skippedTopics);
  const stepStartTs = runtime.current_step_start_ts;

  // Pending topics (not yet completed or skipped)
  const pendingTopics = (topics || []).filter(t => {
    const idx = String(t.index ?? '');
    return idx && !doneSet.has(idx) && !skipSet.has(idx);
  }).sort((a,b) => (a.index??0)-(b.index??0));

  // Update progress
  renderProgress(articles, pendingTopics, runtime, timing);

  let html = '';
  let rowNum = 0;

  // ── Pending section ──
  if (pendingTopics.length > 0) {
    pendingTopics.forEach(t => {
      rowNum++;
      const tIdx = String(t.index ?? '');
      const isCurrentTopic = tIdx === String(runtime.current_topic_index ?? '');
      const title = esc(t.tytul || '(bez tytułu)');
      const tIdxSafe = tIdx.replace(/'/g, "\\'");
      const titleRaw = (t.tytul || '(bez tytułu)').replace(/'/g, "\\'");
      const bgStyle = isCurrentTopic ? 'background:rgba(210,153,34,.06)' : '';

      const cells = langCols.map(lg => {
        let icon;
        if (isCurrentTopic && lg === 'pl' && curStage === 'WRITE_PL') {
          const el = elapsedHtml(stepStartTs);
          icon = `<span class="st st-writing" data-tip="Trwa pisanie artykułu w języku polskim" onclick="openPromptModal()">${el}</span>`;
        } else if (isCurrentTopic && curId && curStage === 'TRANSLATE_'+lg.toUpperCase()) {
          const el = elapsedHtml(stepStartTs);
          icon = `<span style="display:inline-flex;align-items:center;gap:2px"><span class="st st-translating" data-tip="Trwa tłumaczenie na ${lg.toUpperCase()}" onclick="openPromptModal()">${el}</span><button class="st-skip-btn" title="Pomiń tłumaczenie ${lg.toUpperCase()}" onclick="forceSkipTranslation()">×</button></span>`;
        } else if (isCurrentTopic && curId) {
          // Check if this language is already done for current article (from articles array)
          const curArt = articles.find(a => a.article_id === curId);
          if (curArt?.langs?.[lg]?.status === 'done') {
            icon = `<span class="st st-done" data-tip="Gotowe — kliknij aby podejrzeć" onclick="openPreview('${curId}','${lg}')"></span>`;
          } else {
            icon = `<span class="st st-pending" data-tip="Nie rozpoczęto — oczekuje w kolejce"></span>`;
          }
        } else {
          icon = `<span class="st st-pending" data-tip="Nie rozpoczęto — oczekuje w kolejce"></span>`;
        }
        return `<td style="text-align:center">${icon}</td>`;
      }).join('');

      html += `<tr style="${bgStyle}">
        <td class="mono small" style="text-align:right;padding-right:8px;color:var(--muted)">${rowNum}</td>
        <td></td>
        <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;cursor:pointer;color:var(--info)" title="Kliknij aby zobaczyć prompt dla tego tematu" onclick="openTopicPromptModal('${tIdxSafe}','${titleRaw}')">${title} <span style="font-size:10px;opacity:.6">[→ prompt]</span></td>
        ${cells}
      </tr>`;
    });
  }

  // ── Separator ──
  if (pendingTopics.length > 0 && articles.length > 0) {
    html += `<tr class="separator-row"><td colspan="10"><div class="separator-line">Ukończone artykuły</div></td></tr>`;
  }

  // ── Completed articles (already in JSON) ──
  if (articles.length > 0) {
    articles.forEach(a => {
      rowNum++;
      const isActive = a.article_id === curId;
      const bgStyle = isActive ? 'background:rgba(210,153,34,.06)' : '';

      const cells = langCols.map(lg => {
        const info = a.langs?.[lg] || {};
        const isSkipped = skippedLangs[a.article_id]?.[lg];
        let icon;

        if (info.status === 'done') {
          icon = `<span class="st st-done" data-tip="Gotowe — kliknij aby podejrzeć" onclick="openPreview('${a.article_id}','${lg}')"></span>`;
        } else if (isSkipped) {
          icon = `<span class="st st-skipped" data-tip="Pominięto (ręcznie lub automatycznie po błędzie)"></span>`;
        } else if (isCurrentTopic && lg === 'pl' && curStage === 'WRITE_PL') {
          const el = elapsedHtml(stepStartTs);
          icon = `<span class="st st-writing" data-tip="Trwa pisanie artykułu" onclick="openPromptModal()">${el}</span>`;
        } else if (isActive && curStage === 'TRANSLATE_'+lg.toUpperCase()) {
          const el = elapsedHtml(stepStartTs);
          icon = `<span style="display:inline-flex;align-items:center;gap:2px"><span class="st st-translating" data-tip="Trwa tłumaczenie na ${lg.toUpperCase()}" onclick="openPromptModal()">${el}</span><button class="st-skip-btn" title="Pomiń tłumaczenie ${lg.toUpperCase()}" onclick="forceSkipTranslation()">×</button></span>`;
        } else {
          icon = `<span class="st st-pending" data-tip="Nie rozpoczęto"></span>`;
        }
        return `<td style="text-align:center">${icon}</td>`;
      }).join('');

      const hasPl = a.langs?.pl?.status === 'done';
      const titleEsc = esc(a.title_pl || '');
      const titleSafe = (a.title_pl || '').replace(/'/g,"\\'").replace(/"/g,'&quot;').slice(0,60);
      const actionsCell = hasPl
        ? `<td style="text-align:center;white-space:nowrap"><span class="art-actions">` +
          `<button class="art-btn danger" title="Usuń artykuł" onclick="openDeleteModal('${a.article_id}','${titleSafe}')">🗑</button>` +
          `<button class="art-btn" title="Przepisz artykuł" onclick="openRewriteModal('${a.article_id}','${titleSafe}')">↩</button>` +
          `</span></td>`
        : `<td></td>`;

      html += `<tr style="${bgStyle}">
        <td class="mono small" style="text-align:right;padding-right:8px;color:var(--muted)">${rowNum}</td>
        ${actionsCell}
        <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="${titleEsc}">${titleEsc || '<em style="color:var(--muted)">bez tytułu</em>'}</td>
        ${cells}
      </tr>`;
    });
  }

  if (!html) {
    html = '<tr><td colspan="10" class="small" style="color:var(--muted)">Wczytaj pliki JSON, aby zobaczyć artykuły.</td></tr>';
  }

  body.innerHTML = html;
  $('articlesInfo').textContent = `${articles.length} gotowych, ${pendingTopics.length} oczekujących`;

  // Update elapsed timers
  startElapsedTimer();
};

/* ── Elapsed time ── */
function elapsedHtml(startTs) {
  if (!startTs) return '';
  return `<span class="elapsed" data-start="${startTs}"></span>`;
}

function updateElapsedDisplays() {
  document.querySelectorAll('.elapsed[data-start]').forEach(el => {
    const start = new Date(el.dataset.start).getTime();
    if (isNaN(start)) return;
    const secs = Math.floor((Date.now() - start) / 1000);
    el.textContent = secs + 's';
  });
}

function startElapsedTimer() {
  if (_elapsedInterval) clearInterval(_elapsedInterval);
  _elapsedInterval = setInterval(updateElapsedDisplays, 1000);
  updateElapsedDisplays();
}

/* ═══════════════════════════════════════════════════════════════
   PROGRESS BAR + STATS
   ═══════════════════════════════════════════════════════════════ */
const fmtSec = s => {
  s = Math.round(s);
  if (s < 60) return s + 's';
  if (s < 3600) return Math.floor(s/60) + 'min ' + (s%60) + 's';
  const h = Math.floor(s/3600);
  const m = Math.floor((s%3600)/60);
  return h + 'h ' + m + 'min';
};

const fmtTime = d => d.toLocaleTimeString('pl-PL',{hour:'2-digit',minute:'2-digit',second:'2-digit'});

const renderProgress = (articles, pendingTopics, runtime, timing) => {
  const wrap = $('progressWrap');
  const total = (runtime.completed_topics||[]).length + pendingTopics.length + (runtime.skipped_topics||[]).length;
  const done = (runtime.completed_topics||[]).length;

  if (total === 0) { wrap.style.display='none'; return; }
  wrap.style.display='';

  const pct = Math.round(done/total*100);
  $('progressPct').textContent = pct + '%';
  $('progressCount').textContent = `${done} / ${total} tematów`;
  $('progressFill').style.width = pct + '%';

  // Stats
  const wt = timing.write_times || [];
  const tt = timing.translate_times || [];
  const statsBox = $('progressStats');

  if (!wt.length && !tt.length) { statsBox.style.display='none'; return; }
  statsBox.style.display='';

  const minW = wt.length ? Math.min(...wt).toFixed(1) : '—';
  const maxW = wt.length ? Math.max(...wt).toFixed(1) : '—';
  const avgW = timing.avg_write ? timing.avg_write.toFixed(1) : '—';

  const minT = tt.length ? Math.min(...tt).toFixed(1) : '—';
  const maxT = tt.length ? Math.max(...tt).toFixed(1) : '—';
  const avgT = timing.avg_translate ? timing.avg_translate.toFixed(1) : '—';

  // ETA calculation
  const remaining = pendingTopics.length;
  const hasCur = runtime.current_topic_index != null;
  const stage = runtime.current_stage || '';
  const langOrd = ['en','de','fr','it','cs','es'];
  const aW = timing.avg_write || 0;
  const aT = timing.avg_translate || 0;
  const perTopic = aW + 6 * aT;

  let secsLeft = remaining * perTopic;
  if (hasCur) {
    if (stage === 'WRITE_PL') secsLeft += aW + 6*aT;
    else if (stage.startsWith('TRANSLATE_')) {
      const cur = stage.replace('TRANSLATE_','').toLowerCase();
      const pos = langOrd.indexOf(cur);
      secsLeft += (pos >= 0 ? langOrd.length - pos : 0) * aT;
    }
  }

  const eta = new Date(Date.now() + secsLeft*1000);
  const now = new Date();
  const etaTime = fmtTime(eta);
  const etaDateStr = eta.toLocaleDateString('pl-PL',{day:'2-digit',month:'2-digit'});
  const todDateStr = now.toLocaleDateString('pl-PL',{day:'2-digit',month:'2-digit'});
  const etaFull = etaDateStr === todDateStr ? `dziś ${etaTime}` : `${etaDateStr} ${etaTime}`;

  const status = runtime.status || 'idle';
  const showEta = ['running','paused'].includes(status) && secsLeft > 0;

  statsBox.innerHTML = [
    `<div class="stat"><span class="label">Pisanie:</span> <strong>${avgW}s</strong> <span class="label">(min ${minW}s / max ${maxW}s)</span></div>`,
    `<div class="stat"><span class="label">Tłumaczenie:</span> <strong>${avgT}s</strong> <span class="label">(min ${minT}s / max ${maxT}s)</span></div>`,
    showEta ? `<div class="stat"><span class="label">Pozostało:</span> <strong id="etaCountdown">${fmtSec(secsLeft)}</strong></div>` : '',
    showEta ? `<div class="stat"><span class="label">Koniec ok.:</span> <strong>${etaFull}</strong></div>` : '',
  ].filter(Boolean).join('');

  // Live countdown
  if (showEta) startEtaCountdown(secsLeft);
};

let _etaInterval = null;
let _etaSecsLeft = 0;
function startEtaCountdown(secs) {
  _etaSecsLeft = secs;
  if (_etaInterval) clearInterval(_etaInterval);
  _etaInterval = setInterval(() => {
    _etaSecsLeft = Math.max(0, _etaSecsLeft - 1);
    const el = $('etaCountdown');
    if (el) el.textContent = fmtSec(_etaSecsLeft);
    if (_etaSecsLeft <= 0) clearInterval(_etaInterval);
  }, 1000);
}

/* ═══════════════════════════════════════════════════════════════
   PREVIEW MODAL
   ═══════════════════════════════════════════════════════════════ */
const openPreview = async (articleId, lang) => {
  $('previewModal').classList.add('open');
  $('previewModalTitle').textContent = `Podgląd: #${articleId} [${lang.toUpperCase()}]`;
  $('previewSeoBody').innerHTML = '<tr><td colspan="5" class="small">Ładowanie…</td></tr>';
  $('previewArticleContent').innerHTML = '';

  try {
    const res = await fetch(`?action=get_article_preview&article_id=${encodeURIComponent(articleId)}&lang=${encodeURIComponent(lang)}`);
    const data = await res.json();
    if (!data.ok || !data.translation) {
      $('previewSeoBody').innerHTML = `<tr><td colspan="5" class="seo-bad small">${esc(data.error||'Brak danych')}</td></tr>`;
      return;
    }
    const t = data.translation;
    const seoFields = [
      {field:'title', val:t.title, min:50, max:65},
      {field:'meta_title', val:t.seo?.meta_title, min:50, max:65},
      {field:'meta_description', val:t.seo?.meta_description, min:135, max:170},
      {field:'slug', val:t.seo_friendly_url, min:25, max:60},
    ];
    const rows = seoFields.map(({field,val,min,max})=>{
      const v=val||'';
      const len=[...v].length;
      const bad=len>max, warn=!bad&&len<min;
      const cls=bad?'seo-bad':warn?'seo-warn':'seo-ok';
      const label=bad?'za długie':warn?`za krótkie (min ${min})`:'OK';
      return `<tr>
        <td class="mono small">${field}</td>
        <td style="max-width:350px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="${esc(v)}">${esc(v)}</td>
        <td class="mono ${cls}" style="text-align:center">${len}</td>
        <td class="small" style="text-align:center;color:var(--muted)">${min}–${max}</td>
        <td class="${cls}" style="text-align:center;font-size:12px">${label}</td>
      </tr>`;
    });
    rows.push(`<tr><td class="mono small">metryki</td><td class="small" colspan="4">słów: <strong>${t.metrics?.words??'—'}</strong> | znaków: <strong>${t.metrics?.chars??'—'}</strong></td></tr>`);
    $('previewSeoBody').innerHTML = rows.join('');
    $('previewArticleContent').innerHTML = `<div class="preview-wrap">${t.content_html||'<em class="small">brak treści</em>'}</div>`;
  } catch(e) {
    $('previewSeoBody').innerHTML = `<tr><td colspan="5" class="seo-bad small">${esc(e.message)}</td></tr>`;
  }
};

const closePreview = () => $('previewModal').classList.remove('open');
$('previewModal').addEventListener('click', e => { if (e.target===$('previewModal')) closePreview(); });

/* ═══════════════════════════════════════════════════════════════
   DELETE MODAL
   ═══════════════════════════════════════════════════════════════ */
const openDeleteModal = async (articleId, titleHint) => {
  _deleteArticleId = articleId;
  $('deleteModalTitle').textContent = `Usuń artykuł: ${titleHint||('#'+articleId)}`;
  $('deleteConfirmCheck').checked = false;
  $('btnDeleteConfirm').disabled = true;
  $('deleteSeoBody').innerHTML = '<tr><td colspan="5" class="small">Ładowanie…</td></tr>';
  $('deleteArticleContent').innerHTML = '';
  $('deleteModal').classList.add('open');
  try {
    const res = await fetch(`?action=get_article_preview&article_id=${encodeURIComponent(articleId)}&lang=pl`);
    const data = await res.json();
    if (!data.ok || !data.translation) {
      $('deleteSeoBody').innerHTML = `<tr><td colspan="5" class="seo-bad small">${esc(data.error||'Brak danych')}</td></tr>`;
      return;
    }
    const t = data.translation;
    const seoFields = [
      {field:'title', val:t.title, min:50, max:65},
      {field:'meta_title', val:t.seo?.meta_title, min:50, max:65},
      {field:'meta_description', val:t.seo?.meta_description, min:135, max:170},
      {field:'slug', val:t.seo_friendly_url, min:25, max:60},
    ];
    $('deleteSeoBody').innerHTML = seoFields.map(({field,val,min,max})=>{
      const v=val||''; const len=[...v].length;
      const bad=len>max, warn=!bad&&len<min;
      const cls=bad?'seo-bad':warn?'seo-warn':'seo-ok';
      return `<tr><td class="mono small">${field}</td><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="${esc(v)}">${esc(v)}</td><td class="mono ${cls}" style="text-align:center">${len}</td><td class="small" style="text-align:center;color:var(--muted)">${min}–${max}</td><td class="${cls}" style="text-align:center;font-size:12px">${bad?'za długie':warn?'za krótkie':'OK'}</td></tr>`;
    }).join('');
    $('deleteArticleContent').innerHTML = `<div class="preview-wrap">${t.content_html||'<em class="small">brak treści</em>'}</div>`;
  } catch(e) {
    $('deleteSeoBody').innerHTML = `<tr><td colspan="5" class="seo-bad small">${esc(e.message)}</td></tr>`;
  }
};
const closeDeleteModal = () => { $('deleteModal').classList.remove('open'); _deleteArticleId = null; };
$('deleteModal').addEventListener('click', e => { if (e.target===$('deleteModal')) closeDeleteModal(); });
const confirmDeleteArticle = async () => {
  if (!_deleteArticleId) return;
  const id = _deleteArticleId;
  $('btnDeleteConfirm').disabled = true;
  $('btnDeleteConfirm').textContent = 'Usuwanie…';
  try {
    const fd = new FormData(); fd.append('article_id', id);
    await api('delete_article', fd);
    closeDeleteModal();
    await fetchArticles(); await syncStatus();
  } catch(e) {
    alert('Błąd: ' + e.message);
    $('btnDeleteConfirm').disabled = false;
    $('btnDeleteConfirm').textContent = 'Tak, usuń artykuł';
  }
};

/* ═══════════════════════════════════════════════════════════════
   REWRITE MODAL
   ═══════════════════════════════════════════════════════════════ */
const openRewriteModal = async (articleId, titleHint) => {
  _rewriteArticleId = articleId;
  _rewriteArticleData = null;
  $('rewriteModalTitle').textContent = `Przepisz: ${titleHint||('#'+articleId)}`;
  $('rewriteNotes').value = '';
  $('rewritePromptPreview').textContent = 'Ładowanie danych artykułu…';
  $('btnRewriteConfirm').disabled = true;
  $('rewriteModal').classList.add('open');
  try {
    const res = await fetch(`?action=get_article_full&article_id=${encodeURIComponent(articleId)}`);
    const data = await res.json();
    if (!data.ok) { $('rewritePromptPreview').textContent = 'Błąd: ' + (data.error||'?'); return; }
    _rewriteArticleData = data;
    $('btnRewriteConfirm').disabled = false;
    updateRewritePromptPreview();
  } catch(e) { $('rewritePromptPreview').textContent = 'Błąd: ' + e.message; }
};
const closeRewriteModal = () => { $('rewriteModal').classList.remove('open'); _rewriteArticleId = null; _rewriteArticleData = null; };
$('rewriteModal').addEventListener('click', e => { if (e.target===$('rewriteModal')) closeRewriteModal(); });
function updateRewritePromptPreview() {
  if (!_rewriteArticleData) return;
  const notes = $('rewriteNotes').value || '(brak uwag)';
  const a = _rewriteArticleData.article || {};
  const t = _rewriteArticleData.topic || {};
  const plJson = JSON.stringify(a.translations?.pl || {}, null, 2);
  const preview =
    `Poniższy artykuł musi być przepisany zgodnie z uwagami poniżej.\n\n` +
    `UWAGI DO PRZEPISANIA:\n${notes}\n\n` +
    `TREŚĆ ARTYKUŁU DO PRZEPISANIA (JSON):\n${plJson.slice(0,800)}${plJson.length>800?'\n… (skrócono dla podglądu)':''}\n\n` +
    `ZRODŁOWE MATERIAŁY DO ARTYKUŁU:\n` +
    `Tytuł: ${t.tytul||'(brak — temat nie znaleziony)'}\n` +
    `Opis ogólny: ${(t.opis_ogolny||'(brak)').slice(0,200)}\n` +
    `Opis szczegółowy: ${(t.opis_szczegolowy||'(brak)').slice(0,300)}…\n\n` +
    `OCZEKIWANY OUTPUT:\nJSON w tym samym formacie co oryginał z nowym article_id i translation_group.`;
  $('rewritePromptPreview').textContent = preview;
}
const confirmRewriteArticle = async () => {
  if (!_rewriteArticleId) return;
  const notes = $('rewriteNotes').value.trim();
  $('btnRewriteConfirm').disabled = true;
  $('btnRewriteConfirm').textContent = 'Dodawanie do kolejki…';
  try {
    const fd = new FormData();
    fd.append('article_id', _rewriteArticleId);
    fd.append('notes', notes);
    await api('enqueue_rewrite', fd);
    closeRewriteModal();
    await fetchArticles(); await syncStatus();
    startRunner();
  } catch(e) {
    alert('Błąd: ' + e.message);
    $('btnRewriteConfirm').disabled = false;
    $('btnRewriteConfirm').textContent = 'Przepisz i przetłumacz ponownie';
  }
};

/* ═══════════════════════════════════════════════════════════════
   PROMPT MODAL
   ═══════════════════════════════════════════════════════════════ */
async function openPromptModal() {
  $('promptModal').classList.add('open');
  $('promptModalContent').textContent = 'Ładowanie…';
  $('promptModalVars').innerHTML = '';

  try {
    const data = await api('get_current_prompt');
    const prompt = data.prompt;
    const vars = data.vars;
    const stage = data.stage || '';

    if (!prompt) {
      $('promptModalContent').textContent = '(brak danych — prompt nie został jeszcze wygenerowany)';
      $('promptModalTitle').textContent = 'Prompt';
      return;
    }

    $('promptModalTitle').textContent = `Prompt: ${stage}`;

    // Render prompt with highlighted variables
    let htmlPrompt = esc(prompt);
    if (vars) {
      // Sort by value length desc so longer values get replaced first (avoid partial matches)
      const entries = Object.entries(vars).sort((a,b) => String(b[1]).length - String(a[1]).length);
      entries.forEach(([key, value]) => {
        const valEsc = esc(String(value));
        if (valEsc.length > 3) {
          const regex = new RegExp(valEsc.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
          htmlPrompt = htmlPrompt.replace(regex, `<span class="prompt-var">${valEsc}</span>`);
        }
      });
    }

    $('promptModalContent').innerHTML = htmlPrompt;

    // Vars table
    if (vars) {
      $('promptModalVars').innerHTML = '<tr><th class="small" style="text-align:left">Zmienna</th><th class="small" style="text-align:left">Wartość</th></tr>' +
        Object.entries(vars).map(([k,v]) => {
          const val = String(v);
          const short = val.length > 200 ? val.slice(0,200)+'…' : val;
          return `<tr><td class="vname">${esc(k)}</td><td class="vval" title="${esc(val)}">${esc(short)}</td></tr>`;
        }).join('');
    } else {
      $('promptModalVars').innerHTML = '<tr><td class="small" colspan="2">Brak zmiennych</td></tr>';
    }
  } catch(e) {
    $('promptModalContent').textContent = 'Błąd: ' + e.message;
  }
}

function closePromptModal() { $('promptModal').classList.remove('open'); }
$('promptModal').addEventListener('click', e => { if (e.target===$('promptModal')) closePromptModal(); });

// Open prompt preview for a pending (not yet written) topic
async function openTopicPromptModal(topicIndex, topicTitle) {
  $('promptModal').classList.add('open');
  $('promptModalTitle').textContent = `Prompt dla tematu #${topicIndex}: ${topicTitle}`;
  $('promptModalContent').textContent = 'Ładowanie…';
  $('promptModalVars').innerHTML = '';
  try {
    const res = await fetch(`?action=get_topic_prompt&topic_index=${encodeURIComponent(topicIndex)}`);
    const data = await res.json();
    if (!data.ok) {
      $('promptModalContent').textContent = 'Błąd: ' + (data.error || 'Nieznany błąd');
      return;
    }
    const prompt = data.prompt;
    const vars = data.vars || {};
    let htmlPrompt = esc(prompt);
    // Highlight variable values in prompt text
    const entries = Object.entries(vars).sort((a,b) => String(b[1]).length - String(a[1]).length);
    entries.forEach(([key, value]) => {
      const valEsc = esc(String(value));
      if (valEsc.length > 3) {
        const regex = new RegExp(valEsc.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
        htmlPrompt = htmlPrompt.replace(regex, `<span class="prompt-var">${valEsc}</span>`);
      }
    });
    $('promptModalContent').innerHTML = htmlPrompt;
    $('promptModalVars').innerHTML = '<tr><th class="small" style="text-align:left">Zmienna</th><th class="small" style="text-align:left">Wartość (podgląd)</th></tr>' +
      entries.map(([k,v]) => {
        const val = String(v);
        const short = val.length > 300 ? val.slice(0,300)+'…' : val;
        return `<tr><td class="vname">${esc(k)}</td><td class="vval" title="${esc(val)}">${esc(short)}</td></tr>`;
      }).join('');
  } catch(e) {
    $('promptModalContent').textContent = 'Błąd: ' + e.message;
  }
}

/* ═══════════════════════════════════════════════════════════════
   SYNC + RUNNER
   ═══════════════════════════════════════════════════════════════ */
const syncStatus = async () => {
  try {
    const s = await api('get_status');
    stateCache = s;
    renderKpis(s.state);
    renderLog(s.state.log || []);
    refreshControls();
    maybeOpenModal(s.state);
    _articleSync++;
    if (_articleSync % 4 === 0) fetchArticles();
  } catch(e) { console.error('syncStatus:', e); }
};

const startRunner = () => {
  if (runnerActive) return;
  runnerActive = true;

  const tick = async () => {
    await syncStatus();
    const st = stateCache?.state;
    if (!st) { runnerActive=false; return; }

    if (st.runtime?.status === 'running') {
      try { await api('run_step'); } catch(e) { console.error('run_step:', e); }
      await fetchArticles();
      setTimeout(tick, 600);
      return;
    }
    runnerActive = false;
  };
  tick();
};

/* ═══════════════════════════════════════════════════════════════
   INIT
   ═══════════════════════════════════════════════════════════════ */
(async function init(){
  await syncStatus();
  if (stateCache?.key_present) {
    $('keyStatus').textContent = 'klucz w sesji (nietestowany)';
  }
  await fetchArticles();
})();
</script>
</body>
</html>
