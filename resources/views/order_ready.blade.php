<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $labels['OrderReadyScreen'] ?? 'Order Ready' }}</title>
    <style>
      /* Self-contained customer-facing token board — no app bundle required. */
      * { box-sizing: border-box; }
      html, body {
        margin: 0; padding: 0; height: 100%;
        background: #0b0c10; color: #fff;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      }
      .head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 18px 28px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      }
      .head .brand { font-size: 22px; font-weight: 700; opacity: 0.9; }
      .head .clock { font-size: 22px; font-variant-numeric: tabular-nums; opacity: 0.7; }
      .board { display: flex; height: calc(100% - 65px); }
      .col { flex: 1; padding: 20px 28px; overflow: hidden; }
      .col + .col { border-left: 1px solid rgba(255, 255, 255, 0.1); }
      .col h2 {
        margin: 0 0 18px; font-size: 26px; letter-spacing: 0.06em;
        text-transform: uppercase; display: flex; align-items: center; gap: 12px;
      }
      .dot { width: 14px; height: 14px; border-radius: 50%; display: inline-block; }
      .col.preparing h2 { color: #93c5fd; }
      .col.preparing .dot { background: #3b82f6; }
      .col.ready h2 { color: #86efac; }
      .col.ready .dot { background: #22c55e; animation: blink 1.6s infinite; }
      @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
      .tokens { display: flex; flex-wrap: wrap; gap: 14px; align-content: flex-start; }
      .token {
        font-size: 44px; font-weight: 800; padding: 10px 22px; border-radius: 14px;
        background: rgba(255, 255, 255, 0.07); font-variant-numeric: tabular-nums;
      }
      .col.ready .token { background: rgba(34, 197, 94, 0.16); color: #86efac; }
      .token.new { animation: pop 1s ease 6; background: #22c55e; color: #06270f; }
      @keyframes pop {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.12); }
      }
      .empty { opacity: 0.35; font-size: 20px; margin-top: 10px; }
      .offline {
        position: fixed; bottom: 14px; right: 18px; font-size: 13px;
        color: #fca5a5; display: none;
      }
      .offline.on { display: block; }
      @media (max-width: 720px) {
        .board { flex-direction: column; }
        .col + .col { border-left: none; border-top: 1px solid rgba(255, 255, 255, 0.1); }
        .token { font-size: 32px; }
      }
    </style>
</head>
<body>
  <div class="head">
    <div class="brand">{{ $company !== '' ? $company : ($labels['OrderReadyScreen'] ?? 'Order Ready') }}</div>
    <div class="clock" id="clock"></div>
  </div>

  <div class="board">
    <div class="col preparing">
      <h2><span class="dot"></span>{{ $labels['PreparingOrders'] ?? 'Preparing' }}</h2>
      <div class="tokens" id="preparing"></div>
      <div class="empty" id="preparing-empty" hidden>—</div>
    </div>
    <div class="col ready">
      <h2><span class="dot"></span>{{ $labels['ReadyOrders'] ?? 'Ready' }}</h2>
      <div class="tokens" id="ready"></div>
      <div class="empty" id="ready-empty" hidden>—</div>
    </div>
  </div>

  <div class="offline" id="offline">⚠ offline</div>

  <script>
    (function () {
      var TOKEN = @json($token);
      var URL = '/api/kitchen/ready-screen/data?token=' + encodeURIComponent(TOKEN);
      var knownReady = null; // null until first successful poll, so boot doesn't chime
      var audioCtx = null;

      function chime() {
        try {
          var Ctx = window.AudioContext || window.webkitAudioContext;
          if (!Ctx) return;
          if (!audioCtx) audioCtx = new Ctx();
          [880, 1174].forEach(function (freq, i) {
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.connect(gain); gain.connect(audioCtx.destination);
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.12, audioCtx.currentTime + i * 0.18);
            osc.start(audioCtx.currentTime + i * 0.18);
            osc.stop(audioCtx.currentTime + i * 0.18 + 0.16);
          });
        } catch (e) { /* autoplay policy */ }
      }

      function render(id, list, newSet) {
        var box = document.getElementById(id);
        var empty = document.getElementById(id + '-empty');
        box.innerHTML = '';
        list.forEach(function (label) {
          var el = document.createElement('div');
          el.className = 'token' + (newSet && newSet.has(label) ? ' new' : '');
          el.textContent = label;
          box.appendChild(el);
        });
        empty.hidden = list.length > 0;
      }

      function poll() {
        fetch(URL, { cache: 'no-store' })
          .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
          .then(function (data) {
            document.getElementById('offline').classList.remove('on');
            var ready = data.ready || [];
            var newSet = new Set();
            if (knownReady) {
              ready.forEach(function (t) { if (!knownReady.has(t)) newSet.add(t); });
              if (newSet.size) chime();
            }
            knownReady = new Set(ready);
            render('preparing', data.preparing || [], null);
            render('ready', ready, newSet);
          })
          .catch(function () {
            document.getElementById('offline').classList.add('on');
          });
      }

      function tick() {
        var d = new Date();
        document.getElementById('clock').textContent =
          ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
      }

      tick();
      poll();
      setInterval(poll, 5000);
      setInterval(tick, 15000);
    })();
  </script>
</body>
</html>
