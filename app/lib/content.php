<?php

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

