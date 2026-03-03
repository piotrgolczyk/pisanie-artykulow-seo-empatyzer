<?php

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
    CURLOPT_TIMEOUT        => 120,
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


function call_model_json(string $apiKey, string $model, string $reasoningEffort, int $maxOutputTokens, string $prompt, ?callable $statusCb = null): array {
  // Call Responses API, parse first JSON object from output text, return [parsed, rawText, meta].
  if ($statusCb) $statusCb('request_prepared');
  $payload = [
    'model'            => $model,
    'input'            => $prompt,
    'reasoning'        => ['effort' => $reasoningEffort],
    'max_output_tokens'=> $maxOutputTokens,
  ];
  if ($statusCb) $statusCb('request_sent');
  $resp    = openai_post_responses($apiKey, $payload);
  if ($statusCb) $statusCb('response_received');
  $meta    = $resp['_meta'] ?? [];
  $outText = extract_output_text($resp);
  if ($statusCb) $statusCb('response_text_extracted');

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

  if ($statusCb) $statusCb('response_json_found');
  $parsed = json_decode($jsonStr, true);
  if (!is_array($parsed)) {
    $err = json_last_error_msg();
    throw new RuntimeException(
      "Model output is not valid JSON. json_error={$err}. elapsed={$meta['elapsed_s']}s. " .
      "JSON candidate (" . mb_strlen($jsonStr) . " chars): " . mb_substr($jsonStr, 0, 800)
    );
  }
  if ($statusCb) $statusCb('response_json_parsed');
  return [$parsed, $outText, $meta];
}

/** =========================
 *  AJAX actions
 *  ========================= */
