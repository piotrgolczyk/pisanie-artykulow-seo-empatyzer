<?php

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
      'current_step_status' => null,
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
