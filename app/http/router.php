<?php

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
    // Best effort: reflect skip intent in state immediately for UX visibility
    try {
      with_lock(function() use ($lang, $articleId) {
        $st = load_state();
        $st['runtime']['current_step_status'] = 'user_skip_requested';
        if ($articleId !== '') {
          $st['runtime']['skipped_langs'][$articleId][$lang] = true;
        }
        save_state($st);
      });
    } catch (Throwable $e) {}
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
        $aPath = APP_ROOT . '/articles_' . time() . '_' . $aName;
        $bPath = APP_ROOT . '/topics_'   . time() . '_' . $bName;

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
              $state['runtime']['current_step_status'] = 'idle';
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
          $state['runtime']['current_step_status'] = 'prepare_request';
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
              $prompt,
              function(string $stg) use (&$state) {
                static $last = null;
                $state['runtime']['current_step_status'] = $stg;
                if ($stg !== $last) {
                  log_event($state, 'info', 'action', 'WRITE_PL STATUS: ' . $stg);
                  $last = $stg;
                }
              }
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
            $state['runtime']['current_step_status'] = null;

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
            $state['runtime']['current_step_status'] = null;

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

          // ASAP watchdog: if translation step is already too old, skip immediately.
          $elapsedNow = 0;
          if (!empty($state['runtime']['current_step_start_ts'])) {
            $elapsedNow = max(0, time() - strtotime((string)$state['runtime']['current_step_start_ts']));
          }
          if ($elapsedNow > 120) {
            $state['runtime']['skipped_langs'][$articleId][$lang] = true;
            log_event($state, 'warn', 'sprint', "WATCHDOG SKIP LANG {$lang} — article_id={$articleId} elapsed={$elapsedNow}s (>120s limit).");
            $nextLang = null;
            foreach (($state['config']['language_order'] ?? []) as $lg2) {
              if ($lg2 === $lang) continue;
              if (!empty($article['translations'][$lg2]['content_html'])) continue;
              if (!empty($state['runtime']['skipped_langs'][$articleId][$lg2])) continue;
              $nextLang = $lg2;
              break;
            }
            if ($nextLang !== null) {
              $state['runtime']['current_lang'] = $nextLang;
              $state['runtime']['current_stage'] = 'TRANSLATE_' . strtoupper($nextLang);
            } else {
              $state['runtime']['current_stage'] = 'DONE';
              $state['runtime']['current_lang'] = null;
            }
            $state['runtime']['current_step_start_ts'] = null;
            $state['runtime']['current_step_status'] = 'auto_skipped_timeout';
            save_state($state);
            json_response(['ok'=>true,'state'=>$state,'auto_skipped'=>true]);
          }

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
            $state['runtime']['current_step_status'] = null;
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
            $state['runtime']['current_step_status'] = null;

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
            $state['runtime']['current_step_status'] = null;
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
              // Timeout > 120s — pomiń to tłumaczenie bez retry
              $state['runtime']['skipped_langs'][$articleId][$lang] = true;
              log_event($state, 'warn', 'sprint', "TIMEOUT SKIP LANG {$lang} — article_id={$articleId} dur={$transDurationCatch}s (>120s limit). Pomijam bez retry.");
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
            $state['runtime']['current_step_status'] = null;
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
            $state['runtime']['current_step_status'] = null;

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
            $state['runtime']['current_step_status'] = null;

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
