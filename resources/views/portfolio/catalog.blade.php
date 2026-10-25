{{-- Portfolio tablet catalog (menu / models), full screen and dark, for a tablet handed to the customer. PortfolioCatalogPublicController. --}}
<!DOCTYPE html>
<html lang="{{ $data['language'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0b0b0d">
<title>{{ $data['title'] }}</title>
<style>
:root { --accent: {{ $data['accent'] }}; --bg: #0b0b0d; --panel: #16161a; --panel2: #1f1f25; --text: #f4f4f5; --muted: #a1a1aa; --line: #2a2a31; }
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; height: 100%; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; overscroll-behavior: none; }
body { overflow: hidden; user-select: none; -webkit-user-select: none; }
button { font: inherit; color: inherit; cursor: pointer; border: 0; }
.top { position: fixed; inset: 0 0 auto 0; height: 64px; display: flex; align-items: center; gap: 12px; padding: 0 16px; background: rgba(11,11,13,.92); backdrop-filter: blur(8px); z-index: 20; border-bottom: 1px solid var(--line); }
.top img { height: 40px; max-width: 140px; object-fit: contain; }
.top .ttl { font-weight: 700; font-size: 18px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.tbtn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 999px; background: var(--panel2); font-weight: 600; font-size: 15px; }
.tbtn.accent { background: var(--accent); color: #111; }
.tbtn .badge { background: #111; color: var(--accent); border-radius: 999px; padding: 1px 8px; font-size: 13px; }
.tbtn.accent .badge { background: #111; }
main { position: absolute; inset: 64px 0 0 0; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.hero { position: relative; height: calc(50vh - 32px); min-height: 220px; overflow: hidden; background: linear-gradient(135deg, #1f1f25, #0b0b0d); }
.hero .sl { position: absolute; inset: 0; background-size: cover; background-position: center; opacity: 0; transition: opacity 1s; }
.hero .sl.on { opacity: 1; }
.hero .shade { position: absolute; inset: 0; background: linear-gradient(to top, rgba(11,11,13,.95), rgba(11,11,13,.1) 60%); }
.hero .cap { position: absolute; left: 20px; right: 20px; bottom: 18px; }
.hero h1 { margin: 0; font-size: clamp(26px, 4.5vw, 44px); line-height: 1.1; }
.hero p { margin: 6px 0 0; color: var(--muted); font-size: 17px; }
.dots { position: absolute; right: 20px; bottom: 22px; display: flex; gap: 6px; }
.dots i { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,.35); }
.dots i.on { background: var(--accent); }
.sec { padding: 18px 16px 28px; }
.sec h2 { margin: 0 0 14px; font-size: 20px; }
/* home: step options and groups centered on the screen */
.sec.home h2 { text-align: center; }
.sec.home .chips { justify-content: center; }
.groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 210px)); justify-content: center; gap: 12px; }
.group { position: relative; height: 110px; border-radius: 16px; overflow: hidden; background: var(--panel2); display: flex; align-items: center; justify-content: center; padding: 12px; text-align: center; }
.group .bg { position: absolute; inset: 0; background-size: cover; background-position: center; opacity: .55; }
.group span { position: relative; font-size: 19px; font-weight: 700; text-shadow: 0 2px 8px rgba(0,0,0,.6); }
.group::after { content: ""; position: absolute; inset: auto 0 0 0; height: 4px; background: var(--accent); }
.steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 260px)); justify-content: center; gap: 14px; }
.opt { position: relative; height: 160px; border-radius: 18px; overflow: hidden; background: var(--panel2); font-size: 24px; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 2px solid transparent; }
.opt .bg { position: absolute; inset: 0; background-size: cover; background-position: center; opacity: .5; }
.opt span { position: relative; }
.opt:active { border-color: var(--accent); }
.chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.chip { background: var(--panel2); border-radius: 999px; padding: 6px 12px; font-size: 14px; color: var(--muted); }
.chip b { color: var(--text); }
.chip.reset { background: transparent; border: 1px solid var(--line); color: var(--accent); }
.gh { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.gh h2 { margin: 0; flex: 1; }
.back { width: 44px; height: 44px; border-radius: 50%; background: var(--panel2); font-size: 22px; }
.tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 6px; margin-bottom: 12px; scrollbar-width: none; }
.tabs::-webkit-scrollbar { display: none; }
.tab { flex: none; padding: 9px 16px; border-radius: 999px; background: var(--panel2); font-weight: 600; color: var(--muted); }
.tab.on { background: var(--accent); color: #111; }
.items { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 520px) { .items { grid-template-columns: 1fr; } }
.item { display: flex; gap: 10px; background: var(--panel); border-radius: 14px; overflow: hidden; height: max(112px, calc((100vh - 64px - 130px) / 6)); text-align: left; padding: 0; }
.item .pic { flex: 0 0 42%; background: var(--panel2) center/cover no-repeat; position: relative; }
.item .pic.none { display: flex; align-items: center; justify-content: center; font-size: 34px; color: #3a3a42; }
.item .info { flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 10px 10px 10px 0; }
.item .name { font-weight: 700; font-size: 16px; line-height: 1.2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.item .note { color: var(--muted); font-size: 12.5px; margin-top: 3px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.item .row { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.item .price { color: var(--accent); font-weight: 800; font-size: 17px; }
.add { width: 40px; height: 40px; border-radius: 50%; background: var(--accent); color: #111; font-size: 24px; font-weight: 800; line-height: 1; }
.qty { display: inline-flex; align-items: center; gap: 8px; background: var(--panel2); border-radius: 999px; padding: 3px; }
.qty button { width: 34px; height: 34px; border-radius: 50%; background: #2c2c34; font-size: 20px; font-weight: 700; }
.qty b { min-width: 18px; text-align: center; }
.empty { color: var(--muted); padding: 30px 0; text-align: center; }
/* order panel */
.veil { position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 30; opacity: 0; pointer-events: none; transition: opacity .2s; }
.veil.on { opacity: 1; pointer-events: auto; }
.panel { position: fixed; top: 0; right: 0; bottom: 0; width: min(440px, 100%); background: var(--panel); z-index: 31; transform: translateX(100%); transition: transform .25s; display: flex; flex-direction: column; }
.panel.on { transform: none; }
.ph { display: flex; align-items: center; padding: 16px; border-bottom: 1px solid var(--line); }
.ph h3 { margin: 0; flex: 1; font-size: 20px; }
.pl { flex: 1; overflow-y: auto; padding: 8px 16px; }
.line { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.line .n { flex: 1; min-width: 0; }
.line .n div { font-weight: 600; }
.line .n small { color: var(--muted); }
.pf { padding: 16px; border-top: 1px solid var(--line); }
.pf label { display: block; font-size: 13px; color: var(--muted); margin: 0 0 4px; }
.pf input, .pf textarea { width: 100%; background: var(--panel2); border: 1px solid var(--line); color: var(--text); border-radius: 10px; padding: 11px 12px; font: inherit; margin-bottom: 10px; user-select: text; -webkit-user-select: text; }
.total { display: flex; justify-content: space-between; font-size: 19px; font-weight: 800; margin: 6px 0 12px; }
.send { width: 100%; padding: 15px; border-radius: 14px; background: var(--accent); color: #111; font-weight: 800; font-size: 18px; }
.send[disabled] { opacity: .5; }
.err { color: #ff7a70; font-size: 14px; margin-bottom: 8px; }
.done { position: fixed; inset: 0; z-index: 40; background: rgba(11,11,13,.97); display: none; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; }
.done.on { display: flex; }
.done .big { font-size: 64px; color: var(--accent); }
.done h2 { margin: 8px 0; font-size: 28px; }
.done p { color: var(--muted); font-size: 18px; }
/* model photos of a service: gallery + big view */
.item button.pic { padding: 0; text-align: left; }
.item .pic .cam { position: absolute; left: 8px; bottom: 8px; background: rgba(0,0,0,.7); color: #fff; border-radius: 999px; padding: 4px 10px; font-size: 13px; font-weight: 700; }
.item .pic .cam b { color: var(--accent); }
.gal { position: fixed; inset: 64px 0 0 0; z-index: 15; background: var(--bg); display: none; flex-direction: column; }
.gal.on { display: flex; }
.gal .gh { padding: 12px 16px; margin: 0; border-bottom: 1px solid var(--line); }
.gal .gh small { color: var(--muted); font-weight: 400; display: block; font-size: 14px; }
.gal .grid { flex: 1; overflow-y: auto; padding: 12px 16px 24px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; align-content: start; }
@media (max-width: 700px) { .gal .grid { grid-template-columns: 1fr 1fr; } }
.gal .ph2 { position: relative; aspect-ratio: 3 / 4; border-radius: 14px; background: var(--panel2) center/cover no-repeat; padding: 0; }
.gal .ph2 span { position: absolute; left: 8px; top: 8px; background: rgba(0,0,0,.7); border-radius: 999px; padding: 2px 10px; font-size: 13px; font-weight: 700; }
.gal .ph2.in { outline: 3px solid var(--accent); }
.big { position: fixed; inset: 0; z-index: 36; background: rgba(0,0,0,.96); display: none; flex-direction: column; }
.big.on { display: flex; }
.big .stage { flex: 1; position: relative; display: flex; align-items: center; justify-content: center; min-height: 0; }
.big .stage img { max-width: 100%; max-height: 100%; object-fit: contain; }
.big .nav { position: absolute; top: 50%; transform: translateY(-50%); width: 56px; height: 56px; border-radius: 50%; background: rgba(255,255,255,.12); font-size: 28px; }
.big .nav.l { left: 12px; } .big .nav.r { right: 12px; }
.big .bar { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-top: 1px solid var(--line); }
.big .bar .t { flex: 1; font-weight: 700; font-size: 17px; }
.big .bar .t small { display: block; color: var(--muted); font-weight: 400; }
/* round icon buttons: the symbol in the exact middle */
.add, .back, .qty button, .big .nav { display: inline-flex; align-items: center; justify-content: center; padding: 0; line-height: 1; }
.toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); background: var(--panel2); padding: 10px 18px; border-radius: 999px; z-index: 50; opacity: 0; transition: opacity .2s; pointer-events: none; }
.toast.on { opacity: 1; }
</style>
</head>
<body>
<div class="top">
  @if ($data['logo'])<img src="{{ $data['logo'] }}" alt="">@endif
  <div class="ttl">{{ $data['title'] }}</div>
  <button class="tbtn" id="bHome" type="button">⌂ <span id="tHome"></span></button>
  @if ($data['orders'])
    <button class="tbtn accent" id="bOrder" type="button">🛒 <span id="tOrder"></span> <span class="badge" id="count">0</span></button>
  @endif
</div>
<main id="main"></main>
<div class="veil" id="veil"></div>
<aside class="panel" id="panel" aria-hidden="true"></aside>
<div class="gal" id="gal"></div>
<div class="big" id="big"></div>
<div class="done" id="done"></div>
<div class="toast" id="toast"></div>

<script>
(function () {
  'use strict';
  var D = @json($data);
  var ORDER_URL = @json($orderUrl);
  var es = D.language === 'es';
  var T = es ? {
    home: 'Inicio', order: 'Mi pedido', menu: 'Nuestro menú', services: 'Nuestros servicios', choose: 'Elija una opción', change: 'Cambiar',
    empty: 'No hay productos en esta sección.', none: 'Aún no hay productos en el catálogo.', cartEmpty: 'Su pedido está vacío. Toque + para agregar.',
    notes: 'Notas para el pedido', total: 'Total', send: 'Enviar pedido', sending: 'Enviando…', added: 'Agregado', orderNo: 'Pedido N.º',
    close: 'Listo', remove: 'Quitar', back: 'Volver', models: 'modelos', model: 'Modelo', pickModel: 'Elegir este modelo',
    galHint: 'Toque una foto para verla y elegir su modelo.'
  } : {
    home: 'Home', order: 'My order', menu: 'Our menu', services: 'Our services', choose: 'Choose an option', change: 'Change',
    empty: 'Nothing in this section.', none: 'The catalog has no products yet.', cartEmpty: 'Your order is empty. Tap + to add.',
    notes: 'Notes for the order', total: 'Total', send: 'Send order', sending: 'Sending…', added: 'Added', orderNo: 'Order #',
    close: 'Done', remove: 'Remove', back: 'Back', models: 'models', model: 'Model', pickModel: 'Choose this style',
    galHint: 'Tap a photo to see it and choose your style.'
  };
  var main = document.getElementById('main');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var money = function (n) { return D.currency + Number(n || 0).toFixed(2); };
  var state = { view: 'home', group: null, picks: {}, cart: {}, sending: false };
  var slideTimer = null, idleTimer = null;

  document.getElementById('tHome').textContent = T.home;
  if (document.getElementById('tOrder')) document.getElementById('tOrder').textContent = T.order;

  // ---- models: the customer's answers filter the services
  function stepsDone() { return D.steps.every(function (s) { return state.picks[s.id]; }); }
  function fits(it) {
    return D.steps.every(function (s) {
      var allowed = it.options && it.options[s.id];
      return !allowed || !allowed.length || !state.picks[s.id] || allowed.indexOf(state.picks[s.id]) >= 0;
    });
  }
  function visibleItems(gid) { return D.items.filter(function (it) { return (gid == null || it.group_id === gid) && fits(it); }); }
  function visibleGroups() { return D.groups.filter(function (g) { return visibleItems(g.id).length; }); }
  function optLabel(stepId, optId) {
    var s = D.steps.find(function (x) { return x.id === stepId; });
    var o = s && s.options.find(function (x) { return x.id === optId; });
    return o ? o.label : '';
  }
  function chips() {
    if (!D.steps.length || !Object.keys(state.picks).length) return '';
    var h = '<div class="chips">';
    D.steps.forEach(function (s) { if (state.picks[s.id]) h += '<span class="chip">' + esc(s.name) + ': <b>' + esc(optLabel(s.id, state.picks[s.id])) + '</b></span>'; });
    return h + '<button class="chip reset" data-act="reset">' + T.change + '</button></div>';
  }

  // ---- views
  function hero() {
    var h = '<div class="hero">';
    (D.slides || []).forEach(function (u, i) { h += '<div class="sl' + (i === 0 ? ' on' : '') + '" style="background-image:url(\'' + esc(u) + '\')"></div>'; });
    h += '<div class="shade"></div><div class="cap"><h1>' + esc(D.title) + '</h1>' + (D.subtitle ? '<p>' + esc(D.subtitle) + '</p>' : '') + '</div>';
    if ((D.slides || []).length > 1) { h += '<div class="dots">'; D.slides.forEach(function (u, i) { h += '<i class="' + (i === 0 ? 'on' : '') + '"></i>'; }); h += '</div>'; }
    return h + '</div>';
  }
  function renderHome() {
    var h = hero() + '<div class="sec home">';
    if (D.steps.length && !stepsDone()) {
      var step = D.steps.find(function (s) { return !state.picks[s.id]; });
      h += chips();
      h += '<h2>' + esc(step.name) + '</h2><div class="steps">';
      step.options.forEach(function (o) {
        h += '<button class="opt" data-act="pick" data-step="' + esc(step.id) + '" data-opt="' + esc(o.id) + '">' + (o.image ? '<div class="bg" style="background-image:url(\'' + esc(o.image) + '\')"></div>' : '') + '<span>' + esc(o.label) + '</span></button>';
      });
      h += '</div>';
    } else {
      h += chips() + '<h2>' + (D.type === 'models' ? T.services : T.menu) + '</h2>';
      var gs = visibleGroups();
      if (!gs.length) h += '<div class="empty">' + T.none + '</div>';
      else {
        h += '<div class="groups">';
        gs.forEach(function (g) { h += '<button class="group" data-act="group" data-id="' + g.id + '">' + (g.image ? '<div class="bg" style="background-image:url(\'' + esc(g.image) + '\')"></div>' : '') + '<span>' + esc(g.name) + '</span></button>'; });
        h += '</div>';
      }
    }
    main.innerHTML = h + '</div>';
    startSlides();
  }
  function itemCard(it) {
    var key = it.id + '|0', q = state.cart[key] || 0, n = (it.photos || []).length;
    // A service with model photos: its picture opens them.
    var cam = n ? '<span class="cam">📷 <b>' + n + '</b> ' + T.models + '</span>' : '';
    var pic = it.image || n
      ? '<' + (n ? 'button data-act="gal" data-id="' + it.id + '"' : 'div') + ' class="pic" style="background-image:url(\'' + esc(it.image || it.photos[0]) + '\')">' + cam + '</' + (n ? 'button' : 'div') + '>'
      : '<div class="pic none">◍</div>';
    var ctl = !D.orders ? '' : (q ? '<span class="qty"><button data-act="dec" data-id="' + key + '">−</button><b>' + q + '</b><button data-act="inc" data-id="' + key + '">+</button></span>'
      : '<button class="add" data-act="inc" data-id="' + key + '" aria-label="+">+</button>');
    return '<div class="item">' + pic + '<div class="info"><div class="name">' + esc(it.name) + '</div>' + (it.note ? '<div class="note">' + esc(it.note) + '</div>' : '')
      + '<div class="row"><span class="price">' + (D.show_prices ? money(it.price) : '') + '</span>' + ctl + '</div></div></div>';
  }
  function renderGroup() {
    var gs = visibleGroups();
    var g = gs.find(function (x) { return x.id === state.group; }) || gs[0];
    if (!g) { state.view = 'home'; return renderHome(); }
    state.group = g.id;
    var h = '<div class="sec"><div class="gh"><button class="back" data-act="home" aria-label="' + T.back + '">←</button><h2>' + esc(g.name) + '</h2></div>' + chips();
    h += '<div class="tabs">';
    gs.forEach(function (x) { h += '<button class="tab' + (x.id === g.id ? ' on' : '') + '" data-act="group" data-id="' + x.id + '">' + esc(x.name) + '</button>'; });
    h += '</div>';
    var its = visibleItems(g.id);
    h += its.length ? '<div class="items">' + its.map(itemCard).join('') + '</div>' : '<div class="empty">' + T.empty + '</div>';
    main.innerHTML = h + '</div>';
    stopSlides();
  }
  function render() { if (state.view === 'group') renderGroup(); else renderHome(); updateCount(); }

  // ---- slider
  function startSlides() {
    stopSlides();
    var sl = main.querySelectorAll('.hero .sl'), dots = main.querySelectorAll('.dots i'), i = 0;
    if (sl.length < 2) return;
    slideTimer = setInterval(function () {
      sl[i].classList.remove('on'); if (dots[i]) dots[i].classList.remove('on');
      i = (i + 1) % sl.length;
      sl[i].classList.add('on'); if (dots[i]) dots[i].classList.add('on');
    }, 4500);
  }
  function stopSlides() { if (slideTimer) clearInterval(slideTimer); slideTimer = null; }

  // ---- order
  // Cart keys "itemId|model" (model = number of the picked model photo, 0 = none).
  function cartLines() {
    return Object.keys(state.cart).map(function (k) {
      var p = k.split('|'), m = Number(p[1] || 0), it = D.items.find(function (x) { return String(x.id) === p[0]; });
      return it ? { key: k, it: it, m: m, photo: m ? (it.photos || [])[m - 1] : null, q: state.cart[k] } : null;
    }).filter(Boolean);
  }
  function updateCount() { var c = document.getElementById('count'); if (c) c.textContent = cartLines().reduce(function (s, l) { return s + l.q; }, 0); }
  function setQty(id, d) {
    var q = (state.cart[id] || 0) + d;
    if (q <= 0) delete state.cart[id]; else state.cart[id] = Math.min(q, 99);
    if (d > 0) toast(T.added);
  }
  var panel = document.getElementById('panel'), veil = document.getElementById('veil');
  function openPanel() { renderPanel(); panel.classList.add('on'); veil.classList.add('on'); panel.setAttribute('aria-hidden', 'false'); }
  function closePanel() { panel.classList.remove('on'); veil.classList.remove('on'); panel.setAttribute('aria-hidden', 'true'); }
  function renderPanel(error) {
    var lines = cartLines(), total = lines.reduce(function (s, l) { return s + l.q * l.it.price; }, 0);
    var h = '<div class="ph"><h3>' + T.order + '</h3><button class="back" data-act="close">✕</button></div><div class="pl">';
    if (!lines.length) h += '<div class="empty">' + T.cartEmpty + '</div>';
    lines.forEach(function (l) {
      h += '<div class="line">' + (l.photo ? '<img src="' + esc(l.photo) + '" alt="" style="width:48px;height:60px;object-fit:cover;border-radius:8px">' : '')
        + '<div class="n"><div>' + esc(l.it.name) + '</div><small>' + (l.m ? T.model + ' ' + l.m + (D.show_prices ? ' · ' : '') : '') + (D.show_prices ? money(l.it.price) : '') + '</small></div>'
        + '<span class="qty"><button data-act="dec" data-id="' + l.key + '">−</button><b>' + l.q + '</b><button data-act="inc" data-id="' + l.key + '">+</button></span></div>';
    });
    h += '</div><div class="pf">';
    if (error) h += '<div class="err">' + esc(error) + '</div>';
    if (D.ask_table) h += '<label>' + esc(D.table_label) + '</label><input id="fTable" maxlength="60" value="' + esc(state.table || '') + '">';
    h += '<label>' + T.notes + '</label><textarea id="fNotes" rows="2" maxlength="500">' + esc(state.notes || '') + '</textarea>';
    if (D.show_prices) h += '<div class="total"><span>' + T.total + '</span><span>' + money(total) + '</span></div>';
    h += '<button class="send" data-act="send"' + (!lines.length || state.sending ? ' disabled' : '') + '>' + (state.sending ? T.sending : T.send) + '</button></div>';
    panel.innerHTML = h;
  }
  function send() {
    var t = document.getElementById('fTable'), n = document.getElementById('fNotes');
    state.table = t ? t.value.trim() : ''; state.notes = n ? n.value.trim() : '';
    state.sending = true; renderPanel();
    var body = { table: state.table, notes: state.notes, lines: cartLines().map(function (l) { return l.m ? { item_id: l.it.id, qty: l.q, model: l.m } : { item_id: l.it.id, qty: l.q }; }) };
    fetch(ORDER_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        state.sending = false;
        if (!res.ok || !res.j.ok) {
          var e = res.j.errors ? Object.values(res.j.errors)[0][0] : (res.j.message || 'Error');
          return renderPanel(e);
        }
        closePanel();
        var d = document.getElementById('done');
        d.innerHTML = '<div class="big">✓</div><h2>' + T.orderNo + ' ' + esc(res.j.number) + '</h2><p>' + esc(res.j.message) + '</p><button class="tbtn accent" data-act="finish" style="margin-top:18px">' + T.close + '</button>';
        d.classList.add('on');
        state.cart = {}; state.notes = ''; updateCount();
        setTimeout(finish, 15000);
      })
      .catch(function () { state.sending = false; renderPanel('Error'); });
  }
  function finish() {
    document.getElementById('done').classList.remove('on');
    state.picks = {}; state.view = 'home'; state.group = null; state.table = state.table || '';
    render();
  }

  // ---- model photos of a service: gallery, then one photo big with "Choose this style"
  var gal = document.getElementById('gal'), big = document.getElementById('big');
  function itemById(id) { return D.items.find(function (x) { return String(x.id) === String(id); }); }
  function openGal(id) {
    var it = itemById(id); if (!it) return;
    state.gal = it.id;
    var h = '<div class="gh"><button class="back" data-act="galClose" aria-label="' + T.back + '">←</button><h2>' + esc(it.name)
      + '<small>' + (D.show_prices ? money(it.price) + ' · ' : '') + it.photos.length + ' ' + T.models + ' · ' + T.galHint + '</small></h2></div><div class="grid">';
    it.photos.forEach(function (u, i) {
      var inCart = state.cart[it.id + '|' + (i + 1)];
      h += '<button class="ph2' + (inCart ? ' in' : '') + '" data-act="photo" data-n="' + (i + 1) + '" style="background-image:url(\'' + esc(u) + '\')"><span>' + (i + 1) + '</span></button>';
    });
    gal.innerHTML = h + '</div>';
    gal.classList.add('on');
  }
  function closeGal() { gal.classList.remove('on'); big.classList.remove('on'); state.gal = null; if (state.view === 'group') renderGroup(); }
  function showPhoto(n) {
    var it = itemById(state.gal); if (!it) return;
    var c = it.photos.length; n = ((n - 1 + c) % c) + 1; state.photo = n;
    big.innerHTML = '<div class="stage"><img src="' + esc(it.photos[n - 1]) + '" alt="">'
      + (c > 1 ? '<button class="nav l" data-act="prev">‹</button><button class="nav r" data-act="next">›</button>' : '') + '</div>'
      + '<div class="bar"><button class="back" data-act="bigClose">✕</button><div class="t">' + esc(it.name) + '<small>' + T.model + ' ' + n + ' / ' + c + (D.show_prices ? ' · ' + money(it.price) : '') + '</small></div>'
      + (D.orders ? '<button class="tbtn accent" data-act="pickModel">' + T.pickModel + '</button>' : '') + '</div>';
    big.classList.add('on');
  }

  var toastTimer = null;
  function toast(msg) { var t = document.getElementById('toast'); t.textContent = msg; t.classList.add('on'); clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.classList.remove('on'); }, 900); }

  // Back to the start after 3 minutes untouched (the next customer), order kept only while in use.
  function idle() { clearTimeout(idleTimer); idleTimer = setTimeout(function () { closePanel(); gal.classList.remove('on'); big.classList.remove('on'); state.cart = {}; state.picks = {}; state.view = 'home'; render(); }, 180000); }

  // ---- taps
  document.addEventListener('click', function (e) {
    idle();
    var b = e.target.closest('[data-act]');
    if (!b) return;
    var a = b.getAttribute('data-act'), id = b.getAttribute('data-id');
    if (a === 'group') { state.view = 'group'; state.group = Number(id); render(); main.scrollTop = 0; }
    else if (a === 'home') { state.view = 'home'; render(); main.scrollTop = 0; }
    else if (a === 'pick') { state.picks[b.getAttribute('data-step')] = b.getAttribute('data-opt'); render(); main.scrollTop = 0; }
    else if (a === 'reset') { state.picks = {}; state.view = 'home'; render(); }
    else if (a === 'inc' || a === 'dec') { setQty(id, a === 'inc' ? 1 : -1); if (panel.classList.contains('on')) renderPanel(); if (state.view === 'group') renderGroup(); updateCount(); }
    else if (a === 'gal') openGal(id);
    else if (a === 'galClose') closeGal();
    else if (a === 'photo') showPhoto(Number(b.getAttribute('data-n')));
    else if (a === 'prev' || a === 'next') showPhoto(state.photo + (a === 'next' ? 1 : -1));
    else if (a === 'bigClose') big.classList.remove('on');
    else if (a === 'pickModel') { setQty(state.gal + '|' + state.photo, 1); updateCount(); closeGal(); }
    else if (a === 'close') closePanel();
    else if (a === 'send') send();
    else if (a === 'finish') finish();
  });
  // Swipe left / right on the big photo.
  var sx = null;
  big.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
  big.addEventListener('touchend', function (e) {
    if (sx === null) return;
    var dx = e.changedTouches[0].clientX - sx; sx = null;
    if (Math.abs(dx) > 50) showPhoto(state.photo + (dx < 0 ? 1 : -1));
  });
  document.getElementById('bHome').addEventListener('click', function () { closePanel(); gal.classList.remove('on'); big.classList.remove('on'); state.view = 'home'; render(); main.scrollTop = 0; });
  var bo = document.getElementById('bOrder');
  if (bo) bo.addEventListener('click', function () { gal.classList.remove('on'); big.classList.remove('on'); openPanel(); });
  veil.addEventListener('click', closePanel);

  render();
  idle();
})();
</script>
</body>
</html>
