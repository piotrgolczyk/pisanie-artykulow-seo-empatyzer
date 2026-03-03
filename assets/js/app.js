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

const stepStatusLabel = st => {
  const map = {
    prepare_request: 'przygotowanie requestu',
    request_prepared: 'request przygotowany',
    request_sent: 'request wysłany do API',
    response_received: 'odpowiedź HTTP odebrana',
    response_text_extracted: 'wyodrębnianie treści odpowiedzi',
    response_json_found: 'znaleziono JSON w odpowiedzi',
    response_json_parsed: 'JSON poprawnie sparsowany',
    user_skip_requested: 'użytkownik wymusił pominięcie',
    auto_skipped_timeout: 'auto-skip po przekroczeniu limitu czasu',
    idle: 'bez aktywnego kroku',
  };
  return map[st] || (st || 'brak statusu kroku');
};

const stepElapsedSec = startTs => {
  if (!startTs) return 0;
  const start = new Date(startTs).getTime();
  if (isNaN(start)) return 0;
  return Math.max(0, Math.floor((Date.now() - start) / 1000));
};


let stateCache = null;
let keyOk = false;
let runnerActive = false;
let _articleSync = 0;
let _lastRuntimeSig = null;

// Keep screen awake during long processing (like YouTube behavior)
let _wakeLock = null;
let _wakeLockEnabled = false;

async function requestWakeLock() {
  try {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return false;
    if (_wakeLock) return true;
    _wakeLock = await navigator.wakeLock.request('screen');
    _wakeLockEnabled = true;
    _wakeLock.addEventListener('release', () => {
      _wakeLock = null;
      _wakeLockEnabled = false;
    });
    return true;
  } catch (e) {
    _wakeLock = null;
    _wakeLockEnabled = false;
    return false;
  }
}

async function releaseWakeLock() {
  try {
    if (_wakeLock) await _wakeLock.release();
  } catch (_) {
  } finally {
    _wakeLock = null;
    _wakeLockEnabled = false;
  }
}

async function syncWakeLock() {
  const status = stateCache?.state?.runtime?.status || 'idle';
  const shouldKeepAwake = ['running', 'paused', 'waiting_user_decision'].includes(status);
  if (shouldKeepAwake) await requestWakeLock();
  else await releaseWakeLock();
}

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
}, 300);

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
    setTimeout(syncStatus, 120);
    setTimeout(fetchArticles, 150);
  } catch(e) {
    console.error('forceSkipTranslation:', e);
    alert('Nie udało się pominąć tłumaczenia. Sprawdź, czy aktualnie trwa etap TRANSLATE_XX.');
  }
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

  const tIdx = st.runtime?.current_topic_uid || st.runtime?.current_topic_index;
  $('kpiSprint').textContent = tIdx != null ? `#${tIdx}` : '—';
  $('kpiSprint2').textContent = st.runtime?.current_topic_title || '—';

  $('kpiAction').textContent = st.runtime?.current_stage || '—';
  const status = st.runtime?.status || '—';
  const lang = st.runtime?.current_lang || '';
  const step = stepStatusLabel(st.runtime?.current_step_status || '');
  $('kpiAction2').textContent = `${status}${lang ? ' · '+lang : ''} · ${step}`;

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
let _lastArticles = [];
const _previewCache = new Map(); // key: articleId:lang
const _previewWarmInFlight = new Set();
const _topicPromptCache = new Map(); // key: topicUid

const fetchArticles = async () => {
  try {
    const r = await api('get_articles');
    _lastTopics = r.topics || [];
    const articles = r.articles || [];
    _lastArticles = articles;
    const runtime = r.runtime || {};
    renderArticlesTable(articles, _lastTopics, runtime, r.timing||{});
    warmPreviewCache(articles, runtime);
    warmTopicPromptCache(_lastTopics, articles);
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
  const currentStepStatus = stepStatusLabel(runtime.current_step_status || '');
  const stepElapsed = stepElapsedSec(stepStartTs);
  const currentStage = runtime.current_stage || '—';
  const stepStarted = stepStartTs ? new Date(stepStartTs).toLocaleTimeString('pl-PL') : '—';

  // Pending topics (not yet completed or skipped)
  const pendingTopics = (topics || []).filter(t => {
    const uid = String(t._uid ?? '');
    const idx = String(t.index ?? '');
    const done = (uid && doneSet.has(uid)) || (idx && doneSet.has(idx));
    const skipped = (uid && skipSet.has(uid)) || (idx && skipSet.has(idx));
    return uid && !done && !skipped;
  }).sort((a,b) => (a.index??0)-(b.index??0));

  // Update progress
  renderProgress(articles, pendingTopics, runtime, timing);

  let html = '';
  let rowNum = 0;

  const phase = runtime.phase || 'write';
  const priorityArticle = (phase === 'translate' && curId) ? (articles || []).find(a => a.article_id === curId) : null;

  // ── Priority row: currently translated article on very top ──
  if (priorityArticle) {
    rowNum++;
    const a = priorityArticle;
    const bgStyle = 'background:rgba(210,153,34,.10)';
    const cells = langCols.map(lg => {
      const info = a.langs?.[lg] || {};
      const isSkipped = skippedLangs[a.article_id]?.[lg];
      let icon;
      if (info.status === 'done') icon = `<span class="st st-done" data-tip="Gotowe — kliknij aby podejrzeć" onclick="openPreview('${a.article_id}','${lg}')"></span>`;
      else if (isSkipped) icon = `<span class="st st-skipped" data-tip="Pominięto"></span>`;
      else if (curStage === 'TRANSLATE_'+lg.toUpperCase()) {
        const el = elapsedHtml(stepStartTs);
        icon = `<span style="display:inline-flex;align-items:center;gap:2px"><span class="st st-translating" data-tip="Trwa tłumaczenie na ${lg.toUpperCase()} · phase=${phase} · article=${curId||'-'} · etap=${currentStage} · status=${currentStepStatus}${stepStartTs?` · elapsed=${stepElapsed}s · start=${stepStarted}`:''}${stepElapsed>120?' · auto-skip >120s':''}" onclick="openPromptModal()">${el}</span><button class="st-skip-btn" title="Pomiń tłumaczenie ${lg.toUpperCase()}" onclick="forceSkipTranslation()">×</button></span>`;
      } else icon = `<span class="st st-pending" data-tip="Oczekuje"></span>`;
      return `<td style="text-align:center">${icon}</td>`;
    }).join('');
    const titleEsc = esc(a.title_pl || '');
    html += `<tr style="${bgStyle}"><td class="mono small" style="text-align:right;padding-right:8px;color:var(--warn)">${rowNum}</td><td style="text-align:center" title="Aktualnie tłumaczony">⬆</td><td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="${titleEsc}">${titleEsc || '<em style="color:var(--muted)">bez tytułu</em>'}</td>${cells}</tr>`;
  }

  // ── Pending section ──
  if (pendingTopics.length > 0) {
    pendingTopics.forEach(t => {
      rowNum++;
      const tIdx = String(t.index ?? '');
      const isCurrentTopic = t._uid === String(runtime.current_topic_uid ?? '');
      const title = esc(t.tytul || '(bez tytułu)');
      const titleRaw = (t.tytul || '(bez tytułu)').replace(/'/g, "\\'");
      const topicUidSafe = String(t._uid || '').replace(/'/g, "\'");
      const bgStyle = isCurrentTopic ? 'background:rgba(210,153,34,.06)' : '';

      const cells = langCols.map(lg => {
        let icon;
        if (isCurrentTopic && lg === 'pl' && curStage === 'WRITE_PL') {
          const el = elapsedHtml(stepStartTs);
          icon = `<span class="st st-writing" data-tip="Trwa pisanie artykułu w języku polskim · ${currentStepStatus}${stepStartTs?` · ${stepElapsed}s`:''}" onclick="openPromptModal()">${el}</span>`;
        } else if (isCurrentTopic && curId && curStage === 'TRANSLATE_'+lg.toUpperCase()) {
          const el = elapsedHtml(stepStartTs);
          icon = `<span style="display:inline-flex;align-items:center;gap:2px"><span class="st st-translating" data-tip="Trwa tłumaczenie na ${lg.toUpperCase()} · phase=${phase} · article=${curId||'-'} · etap=${currentStage} · status=${currentStepStatus}${stepStartTs?` · elapsed=${stepElapsed}s · start=${stepStarted}`:''}${stepElapsed>120?' · auto-skip >120s':''}" onclick="openPromptModal()">${el}</span><button class="st-skip-btn" title="Pomiń tłumaczenie ${lg.toUpperCase()}" onclick="forceSkipTranslation()">×</button></span>`;
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
        <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;cursor:pointer;color:var(--info)" title="Kliknij aby zobaczyć prompt dla tego tematu" onclick="openTopicPromptModal('${topicUidSafe}','${titleRaw}')">${title} <span style="font-size:10px;opacity:.6">[→ prompt]</span></td>
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
    const sortedArticles = [...articles].filter(a => !priorityArticle || a.article_id !== priorityArticle.article_id);

    sortedArticles.forEach(a => {
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
        } else if (isActive && lg === 'pl' && curStage === 'WRITE_PL') {
          const el = elapsedHtml(stepStartTs);
          icon = `<span class="st st-writing" data-tip="Trwa pisanie artykułu · ${currentStepStatus}${stepStartTs?` · ${stepElapsed}s`:''}" onclick="openPromptModal()">${el}</span>`;
        } else if (isActive && curStage === 'TRANSLATE_'+lg.toUpperCase()) {
          const el = elapsedHtml(stepStartTs);
          icon = `<span style="display:inline-flex;align-items:center;gap:2px"><span class="st st-translating" data-tip="Trwa tłumaczenie na ${lg.toUpperCase()} · phase=${phase} · article=${curId||'-'} · etap=${currentStage} · status=${currentStepStatus}${stepStartTs?` · elapsed=${stepElapsed}s · start=${stepStarted}`:''}${stepElapsed>120?' · auto-skip >120s':''}" onclick="openPromptModal()">${el}</span><button class="st-skip-btn" title="Pomiń tłumaczenie ${lg.toUpperCase()}" onclick="forceSkipTranslation()">×</button></span>`;
        } else {
          icon = `<span class="st st-pending" data-tip="Nie rozpoczęto"></span>`;
        }
        return `<td style="text-align:center">${icon}</td>`;
      }).join('');

      const hasPl = a.langs?.pl?.status === 'done';
      const titleEsc = esc(a.title_pl || '');
      const titleSafe = (a.title_pl || '').replace(/'/g,"\'").replace(/"/g,'&quot;').slice(0,60);
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
  const phaseInfo = runtime.phase || 'write';
  const transArticles = articles.filter(a => a.langs?.pl?.status === 'done');
  const incompleteArticles = transArticles.filter(a => langCols.some(lg => lg !== 'pl' && a.langs?.[lg]?.status !== 'done' && !skippedLangs[a.article_id]?.[lg]));
  const missingPairs = incompleteArticles.reduce((sum, a) => sum + langCols.filter(lg => lg !== 'pl' && a.langs?.[lg]?.status !== 'done' && !skippedLangs[a.article_id]?.[lg]).length, 0);
  $('articlesInfo').textContent = `${articles.length} gotowych, ${pendingTopics.length} oczekujących`;
  const tInfo = $('translationLiveInfo');
  if (tInfo) {
    if (phaseInfo === 'translate') {
      const cur = runtime.current_article_id || '—';
      const stage = runtime.current_stage || '—';
      const step = currentStepStatus;
      tInfo.textContent = `Tryb tłumaczeń zaległych: ${incompleteArticles.length}/${transArticles.length} artykułów bez kompletu, braków językowych: ${missingPairs}. Teraz: ${stage} dla #${cur} · ${step}${stepStartTs?` · ${stepElapsed}s`:''}.`;
    } else {
      tInfo.textContent = `Do pełnych kompletów tłumaczeń: ${incompleteArticles.length}/${transArticles.length} artykułów, braków językowych: ${missingPairs}.`;
    }
  }

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
  const hasCur = (runtime.current_topic_uid || runtime.current_topic_index) != null;
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


function previewKey(articleId, lang) {
  return `${articleId}:${lang}`;
}

function renderPreviewData(articleId, lang, t) {
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
}

function warmPreviewCache(articles, runtime) {
  const candidates = [];
  (articles || []).forEach(a => {
    const aid = a.article_id;
    if (!aid) return;
    langCols.forEach(lg => {
      if (a.langs?.[lg]?.status === 'done') {
        const k = previewKey(aid, lg);
        if (!_previewCache.has(k) && !_previewWarmInFlight.has(k)) candidates.push([aid, lg, k]);
      }
    });
  });
  candidates.slice(0, 60).forEach(([aid, lg, k], idx) => {
    _previewWarmInFlight.add(k);
    setTimeout(async () => {
      try {
        const res = await fetch(`?action=get_article_preview&article_id=${encodeURIComponent(aid)}&lang=${encodeURIComponent(lg)}`);
        const data = await res.json();
        if (data?.ok && data.translation) _previewCache.set(k, data.translation);
      } catch(_) {
      } finally {
        _previewWarmInFlight.delete(k);
      }
    }, Math.floor(idx/6) * 10);
  });
}


function maxArticleIdNum(articles) {
  let max = 0;
  (articles || []).forEach(a => {
    const id = String(a.article_id || '');
    if (/^\d+$/.test(id)) max = Math.max(max, parseInt(id, 10));
  });
  return max;
}

function renderTemplateLocal(template, vars) {
  let out = String(template || '');
  Object.entries(vars || {}).forEach(([k, v]) => {
    const re = new RegExp(`\{\{${k}\}\}`, 'g');
    out = out.replace(re, String(v ?? ''));
  });
  return out;
}

function buildTopicPromptLocal(topicUid) {
  const topic = (_lastTopics || []).find(t => String(t._uid || '') === String(topicUid || ''));
  if (!topic) return null;
  const nextId = String(maxArticleIdNum(_lastArticles) + 1).padStart(5, '0');
  const vars = {
    ARTICLE_ID: nextId,
    TRANSLATION_GROUP: `pll_preview_${String(topicUid).replace(/[^a-zA-Z0-9]/g,'').slice(0,12) || 'local'}`,
    TOPIC_INDEX: String(topic.index ?? ''),
    TOPIC_TITLE: String(topic.tytul ?? ''),
    TOPIC_SHORT_DESC: String(topic.opis_ogolny ?? ''),
    TOPIC_LONG_DESC: String(topic.opis_szczegolowy ?? ''),
  };
  const prompt = renderTemplateLocal($('promptWrite')?.value || '', vars);
  return { prompt, vars, topic };
}

function warmTopicPromptCache(topics, articles) {
  (topics || []).slice(0, 80).forEach((t, idx) => {
    const uid = String(t._uid || '');
    if (!uid || _topicPromptCache.has(uid)) return;
    setTimeout(() => {
      const local = buildTopicPromptLocal(uid);
      if (local) _topicPromptCache.set(uid, local);
    }, Math.floor(idx/10) * 5);
  });
}

/* ═══════════════════════════════════════════════════════════════
   PREVIEW MODAL
   ═══════════════════════════════════════════════════════════════ */
const openPreview = async (articleId, lang) => {
  $('previewModal').classList.add('open');
  $('previewModalTitle').textContent = `Podgląd: #${articleId} [${lang.toUpperCase()}]`;
  $('previewSeoBody').innerHTML = '<tr><td colspan="5" class="small">Ładowanie…</td></tr>';
  $('previewArticleContent').innerHTML = '';

  const cacheKey = previewKey(articleId, lang);
  const cached = _previewCache.get(cacheKey);
  if (cached) {
    renderPreviewData(articleId, lang, cached);
  }

  try {
    const res = await fetch(`?action=get_article_preview&article_id=${encodeURIComponent(articleId)}&lang=${encodeURIComponent(lang)}`);
    const data = await res.json();
    if (!data.ok || !data.translation) {
      if (!cached) {
        $('previewSeoBody').innerHTML = `<tr><td colspan="5" class="seo-bad small">${esc(data.error||'Brak danych')}</td></tr>`;
      }
      return;
    }
    _previewCache.set(cacheKey, data.translation);
    renderPreviewData(articleId, lang, data.translation);
  } catch(e) {
    if (!cached) {
      $('previewSeoBody').innerHTML = `<tr><td colspan="5" class="seo-bad small">${esc(e.message)}</td></tr>`;
    }
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
async function openTopicPromptModal(topicUid, topicTitle) {
  $('promptModal').classList.add('open');
  $('promptModalTitle').textContent = `Prompt dla tematu ${topicTitle}`;
  $('promptModalContent').textContent = 'Ładowanie…';
  $('promptModalVars').innerHTML = '';

  const cached = _topicPromptCache.get(topicUid) || buildTopicPromptLocal(topicUid);
  if (cached?.prompt) {
    _topicPromptCache.set(topicUid, cached);
    const vars = cached.vars || {};
    let htmlPrompt = esc(cached.prompt);
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
  }

  try {
    const res = await fetch(`?action=get_topic_prompt&topic_uid=${encodeURIComponent(topicUid)}`);
    const data = await res.json();
    if (!data.ok) {
      if (!cached) $('promptModalContent').textContent = 'Błąd: ' + (data.error || 'Nieznany błąd');
      return;
    }
    const vars = data.vars || {};
    _topicPromptCache.set(topicUid, {prompt: data.prompt, vars});
    let htmlPrompt = esc(data.prompt);
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
    if (!cached) $('promptModalContent').textContent = 'Błąd: ' + e.message;
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
    await syncWakeLock();

    const rt = s.state?.runtime || {};
    const sig = [rt.status || '', rt.phase || '', rt.current_stage || '', rt.current_article_id || '', rt.current_topic_uid || '', rt.current_step_start_ts || ''].join('|');
    const changed = sig !== _lastRuntimeSig;
    _lastRuntimeSig = sig;

    _articleSync++;
    if (changed || rt.status === 'running' || (_articleSync % 2 === 0)) {
      fetchArticles();
    }
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
      // UX first: show latest queued/active row before requesting next heavy step
      await fetchArticles();
      try { await api('run_step'); } catch(e) { console.error('run_step:', e); }
      await fetchArticles();
      setTimeout(tick, 50);
      return;
    }
    runnerActive = false;
  };
  tick();
};


document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') {
    syncWakeLock();
  }
});

window.addEventListener('beforeunload', () => {
  releaseWakeLock();
});

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
