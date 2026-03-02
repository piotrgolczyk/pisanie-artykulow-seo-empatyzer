<?php

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

