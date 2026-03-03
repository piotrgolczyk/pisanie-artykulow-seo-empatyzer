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

