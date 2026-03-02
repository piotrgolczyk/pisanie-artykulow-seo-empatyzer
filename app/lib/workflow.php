<?php

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

