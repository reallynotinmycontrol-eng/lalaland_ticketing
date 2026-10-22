/* Gate scanner: camera → /api/checkin.php. The server alone decides entry. */
(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var resultEl = document.getElementById('result');
  var hint = document.getElementById('scan-hint');
  var retryBtn = document.getElementById('cam-retry');
  var showing = false;       // a result is on screen
  var inflight = false;      // a check-in request is running
  var lastCode = '', lastAt = 0;
  var hideTimer = null;
  var scanner = null;

  // ---- feedback ---------------------------------------------------------------
  var audioCtx = null;
  function tone(freq, ms, type) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.type = type || 'sine'; o.frequency.value = freq;
      g.gain.setValueAtTime(0.25, audioCtx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + ms / 1000);
      o.connect(g); g.connect(audioCtx.destination);
      o.start(); o.stop(audioCtx.currentTime + ms / 1000);
    } catch (e) { /* audio not available */ }
  }
  function okFeedback() { tone(1320, 140); if (navigator.vibrate) navigator.vibrate(80); }
  function badFeedback() { tone(180, 450, 'square'); if (navigator.vibrate) navigator.vibrate([200, 80, 200]); }
  // Unlock audio on iOS with the first tap.
  document.addEventListener('touchstart', function unlock() {
    tone(1, 1); document.removeEventListener('touchstart', unlock);
  }, { passive: true });

  // ---- counter ----------------------------------------------------------------
  function setCounts(c) {
    if (!c) return;
    document.getElementById('c-entered').textContent = c.entered;
    document.getElementById('c-sold').textContent = c.sold;
  }
  function refreshCounts() {
    fetch('/api/stats.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; }).then(setCounts).catch(function () {});
  }
  setInterval(refreshCounts, 20000);

  // ---- result screen ----------------------------------------------------------
  var LABELS = {
    allowed: 'ALLOWED',
    already_used: 'ALREADY USED',
    cancelled: 'CANCELLED / REFUNDED',
    not_paid: 'PAYMENT NOT COMPLETE',
    invalid: 'INVALID TICKET',
    error: 'NOT CHECKED'
  };
  function show(res) {
    var ok = res.result === 'allowed';
    resultEl.className = 'result ' + (ok ? 'is-ok' : 'is-bad') + ' r-' + res.result;
    document.getElementById('r-title').textContent = LABELS[res.result] || res.result;
    document.getElementById('r-admit').textContent = ok ? 'Admit ' + res.quantity : '';
    document.getElementById('r-name').textContent = res.name || '';
    var sub = '';
    if (res.result === 'already_used' && res.checked_in_time) {
      sub = 'Entered at ' + res.checked_in_time + (res.checked_in_by ? ' · by ' + res.checked_in_by : '');
    } else if (res.result === 'error') {
      sub = res.message || 'Network problem — scan again.';
    }
    if (res.ref) sub = (sub ? sub + ' · ' : '') + res.ref + (ok && res.quantity > 1 ? ' · ' + res.quantity + ' people' : '');
    document.getElementById('r-sub').textContent = sub;
    resultEl.hidden = false;
    showing = true;
    ok ? okFeedback() : badFeedback();
    clearTimeout(hideTimer);
    // Red results stay a little longer so staff can read them.
    hideTimer = setTimeout(hide, ok ? 2000 : 3500);
  }
  function hide() {
    resultEl.hidden = true;
    showing = false;
  }
  resultEl.addEventListener('click', hide);

  function checkin(payload) {
    if (inflight) return;
    inflight = true;
    fetch('/api/checkin.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload)
    }).then(function (r) {
      if (r.status === 401) { location.href = '/login.php?next=/scan.php'; return null; }
      return r.json().then(function (b) { if (!r.ok && !b.result) throw new Error(b.error || 'Error'); return b; });
    }).then(function (res) {
      if (!res) return;
      show(res);
      setCounts(res.counts);
      if (payload.booking_id) runSearch(true);
    }).catch(function (err) {
      show({ result: 'error', message: navigator.onLine ? (err.message || 'Server problem — try again.') : 'No internet connection.' });
    }).then(function () { inflight = false; });
  }

  // ---- camera -----------------------------------------------------------------
  function extractToken(text) {
    text = (text || '').trim();
    if (/^[a-f0-9]{32}$/i.test(text)) return text.toLowerCase();
    var m = text.match(/[?&]t=([a-f0-9]{32})/i);
    return m ? m[1].toLowerCase() : text;
  }
  function onScan(text) {
    if (showing || inflight) return;
    var now = Date.now();
    // Ignore the same code held in front of the camera within 3 s.
    if (text === lastCode && now - lastAt < 3000) return;
    lastCode = text; lastAt = now;
    checkin({ token: extractToken(text) });
  }
  function startCamera() {
    if (!window.Html5Qrcode) {
      hint.textContent = 'Scanner library failed to load. Check internet and reload.';
      return;
    }
    if (!window.isSecureContext) {
      hint.textContent = 'Camera needs HTTPS. Open this page with https://';
      return;
    }
    retryBtn.hidden = true;
    hint.textContent = 'Starting camera…';
    scanner = scanner || new Html5Qrcode('reader', { verbose: false });
    scanner.start(
      { facingMode: 'environment' },
      {
        fps: 12,
        qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.72); return { width: s, height: s }; },
        aspectRatio: 1.0
      },
      onScan,
      function () { /* no code in frame */ }
    ).then(function () {
      hint.textContent = 'Point at the ticket QR code';
    }).catch(function (err) {
      hint.textContent = 'Camera not available: ' + (err && err.message ? err.message : err) + '. Allow camera access, then tap Start camera.';
      retryBtn.hidden = false;
    });
  }
  retryBtn.addEventListener('click', startCamera);

  // ---- tabs -------------------------------------------------------------------
  document.querySelectorAll('.scan-tabs [data-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tab = btn.dataset.tab;
      document.querySelectorAll('.scan-tabs [data-tab]').forEach(function (b) { b.setAttribute('aria-selected', String(b === btn)); });
      document.getElementById('pane-camera').hidden = tab !== 'camera';
      document.getElementById('pane-search').hidden = tab !== 'search';
      if (tab === 'search') {
        if (scanner && scanner.isScanning) scanner.pause(true);
        document.getElementById('search-q').focus();
      } else if (scanner && scanner.isScanning) {
        scanner.resume();
      } else {
        startCamera();
      }
    });
  });

  // ---- manual search ----------------------------------------------------------
  var qInput = document.getElementById('search-q');
  var list = document.getElementById('search-results');
  var searchTimer = null, searchSeq = 0;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function runSearch(keepFocus) {
    var q = qInput.value.trim();
    if (q.length < 2) { list.innerHTML = ''; return; }
    var seq = ++searchSeq;
    fetch('/api/search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (seq !== searchSeq) return;
        var rows = data.results || [];
        if (!rows.length) { list.innerHTML = '<li class="empty">No paid bookings found</li>'; return; }
        list.innerHTML = rows.map(function (b) {
          var done = !!b.checked_in_time;
          return '<li class="' + (done ? 'done' : '') + '">' +
            '<div><strong>' + esc(b.name) + '</strong> <span class="pill">' + b.quantity + '</span>' +
            '<div class="muted small">' + esc(b.ref) + ' · ' + esc(b.phone) + ' · ' + esc(b.source) + '</div>' +
            (done ? '<div class="small used">Entered ' + esc(b.checked_in_time) + (b.checked_in_by ? ' by ' + esc(b.checked_in_by) : '') + '</div>' : '') +
            '</div>' +
            (done ? '' : '<button class="btn btn-primary" data-checkin="' + b.id + '">Check in</button>') +
            '</li>';
        }).join('');
      }).catch(function () { list.innerHTML = '<li class="empty">Search failed — check internet</li>'; });
  }
  qInput.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(runSearch, 250); });
  list.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-checkin]');
    if (!btn) return;
    if (!confirm('Check in ' + btn.closest('li').querySelector('strong').textContent + '?')) return;
    checkin({ booking_id: parseInt(btn.dataset.checkin, 10) });
  });

  // ---- connectivity -----------------------------------------------------------
  var offline = document.getElementById('offline');
  function net() { offline.hidden = navigator.onLine; }
  window.addEventListener('online', net); window.addEventListener('offline', net); net();

  // Keep the screen awake on supported phones.
  if ('wakeLock' in navigator) {
    var lock = null;
    var req = function () { navigator.wakeLock.request('screen').then(function (l) { lock = l; }).catch(function () {}); };
    req();
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') req(); });
  }

  startCamera();
})();
