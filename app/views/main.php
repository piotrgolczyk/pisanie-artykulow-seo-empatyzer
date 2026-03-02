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
