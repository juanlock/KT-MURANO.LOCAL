{{-- Grocery & Supermarket theme skin. Embedded CSS (no Tailwind rebuild):
     fresh green + orange deals on white; `gr-*` components for header, footer
     and homepage; shared tokens re-tinted so every store page matches. --}}
@php
  $grAccent = $s->primary_color ?: '#16a34a';
  $grDeal = $s->secondary_color ?: '#f97316';
  if (in_array(strtolower($grAccent), ['#6c5ce7', '#3b82f6', '#2563eb', '#7b6fd0', '#0f766e', '#1a7f6b'], true)) { $grAccent = '#16a34a'; }
  if (in_array(strtolower($grDeal), ['#00c2ff', '#22d3ee', '#1d4ed8', '#e8557a', '#c9a24d', '#0f5a4c'], true)) { $grDeal = '#f97316'; }
  $grHex = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '22 163 74';
      return hexdec(substr($hex,0,2)).' '.hexdec(substr($hex,2,2)).' '.hexdec(substr($hex,4,2));
  };
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap">
<style>
  :root {
    --color-bg-base: 255 255 255;
    --color-bg-surface: 249 250 251;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted: 243 245 247;
    --color-border-subtle: 231 234 238;
    --color-border-strong: 209 214 221;
    --color-fg-primary:   20 30 40;
    --color-fg-secondary: 71 84 98;
    --color-fg-muted:     107 118 130;
    --color-accent-400: {{ $grHex($grAccent) }};
    --color-accent-500: {{ $grHex($grAccent) }};
    --color-accent-600: {{ $grHex($grAccent) }};
    --color-accent-glow: rgba({{ str_replace(' ', ',', $grHex($grAccent)) }}, 0.25);
    --gr-green: {{ $grAccent }};
    --gr-green-rgb: {{ str_replace(' ', ',', $grHex($grAccent)) }};
    --gr-orange: {{ $grDeal }};
    --gr-orange-rgb: {{ str_replace(' ', ',', $grHex($grDeal)) }};
    --gr-ink: #141e28;
    --gr-muted: #6b7682;
    --gr-line: #e7eaee;
    --gr-card: #ffffff;
    --gr-page: #ffffff;
    --gr-soft: #eef8f1;
    --gr-radius: 14px;
    --gr-shadow: 0 1px 2px rgba(20,30,40,.04), 0 10px 24px -14px rgba(20,30,40,.16);
    --gr-shadow-lg: 0 24px 46px -18px rgba(20,30,40,.25);
  }
  .dark { --gr-ink: #e6ebf0; --gr-muted: #9aa6b2; --gr-line: #263140; --gr-card: #111a24; --gr-page: #0b131b; --gr-soft: #122a1c; --gr-shadow: none; --gr-shadow-lg: 0 24px 48px -16px rgba(0,0,0,.6); }
  body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--gr-page); }
  .section-title, .hero-title, .gr-h2, .gr-display { font-family: 'Manrope', 'Inter', system-ui, sans-serif; letter-spacing: -0.02em; }
  .btn { border-radius: 10px; }
  .btn-primary { box-shadow: none; }
  .card, .product-card { border-radius: var(--gr-radius); }
  .product-card { box-shadow: none; }
  .input, .select, .textarea { border-radius: 10px; }
  .drawer-panel, .dialog-panel, .auth-panel { border-radius: 16px; }
  .newsletter-panel { border-radius: 18px; }
  .price, .price-lg, .price-xl, .price-sm, .price-compare { font-family: 'Manrope', system-ui, sans-serif; letter-spacing: -0.01em; }

  /* ---------- layout ---------- */
  .gr-container { width: 100%; max-width: 1320px; margin: 0 auto; padding: 0 16px; }
  @media (min-width: 1024px) { .gr-container { padding: 0 24px; } }
  .gr-section { padding: 26px 0; }
  @media (min-width: 1024px) { .gr-section { padding: 34px 0; } }
  .gr-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
  .gr-h2 { font-size: 20px; font-weight: 800; color: var(--gr-ink); margin: 0; display: inline-flex; align-items: center; gap: 10px; }
  @media (min-width: 768px) { .gr-h2 { font-size: 24px; } }
  .gr-h2 svg { width: 22px; height: 22px; color: var(--gr-green); }
  .gr-h2 .gr-h2-deal { color: var(--gr-orange); }
  .gr-link { display: inline-flex; align-items: center; gap: 4px; font-size: 13.5px; font-weight: 700; color: var(--gr-green); white-space: nowrap; }
  .gr-link:hover { text-decoration: underline; }
  .gr-link svg { width: 15px; height: 15px; }
  .gr-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 20px; border-radius: 12px; font-size: 14px; font-weight: 700; border: 1.5px solid transparent; cursor: pointer; transition: transform .15s, background .15s, color .15s, border-color .15s; white-space: nowrap; font-family: inherit; }
  .gr-btn svg { width: 17px; height: 17px; }
  .gr-btn:active { transform: translateY(1px); }
  .gr-btn-primary { background: var(--gr-green); color: #fff; }
  .gr-btn-primary:hover { filter: brightness(1.07); color: #fff; }
  .gr-btn-orange { background: var(--gr-orange); color: #fff; }
  .gr-btn-orange:hover { filter: brightness(1.07); color: #fff; }
  .gr-btn-white { background: #fff; color: var(--gr-ink); border-color: var(--gr-line); }
  .gr-btn-white:hover { border-color: var(--gr-green); color: var(--gr-green); }
  .gr-btn-sm { height: 36px; padding: 0 14px; font-size: 13px; border-radius: 10px; }
  .gr-panel { background: var(--gr-card); border: 1px solid var(--gr-line); border-radius: var(--gr-radius); }

  /* ---------- top bar ---------- */
  .gr-topbar { background: var(--gr-green); color: #fff; font-size: 12.5px; }
  .gr-topbar .gr-container { display: flex; align-items: center; justify-content: space-between; gap: 12px; height: 36px; }
  .gr-topbar-left { display: flex; align-items: center; gap: 8px; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
  .gr-topbar-left svg { width: 15px; height: 15px; flex-shrink: 0; }
  .gr-topbar-right { display: none; align-items: center; gap: 2px; }
  @media (min-width: 768px) { .gr-topbar-right { display: flex; } }
  .gr-topbar-right a, .gr-topbar-right button { color: #fff; padding: 4px 9px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; background: transparent; border: 0; cursor: pointer; font-family: inherit; }
  .gr-topbar-right a:hover, .gr-topbar-right button:hover { background: rgba(255,255,255,.16); }
  .gr-topbar-right svg { width: 13px; height: 13px; }

  /* ---------- header ---------- */
  .gr-header { position: sticky; top: 0; z-index: 40; background: var(--gr-card); border-bottom: 1px solid var(--gr-line); }
  .gr-header-main { display: flex; align-items: center; gap: 12px; height: 66px; }
  @media (min-width: 1024px) { .gr-header-main { height: 78px; gap: 18px; } }
  .gr-logo { display: inline-flex; align-items: center; gap: 10px; flex-shrink: 0; font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 22px; letter-spacing: -0.03em; color: var(--gr-ink); }
  .gr-logo img { height: 38px; max-width: 160px; object-fit: contain; }
  .gr-logo-mark { width: 38px; height: 38px; border-radius: 11px; background: var(--gr-green); color: #fff; display: grid; place-items: center; }
  .gr-logo-mark svg { width: 20px; height: 20px; }
  .gr-logo em { font-style: normal; color: var(--gr-green); }
  .gr-deliver { display: none; align-items: center; gap: 10px; padding: 6px 12px 6px 8px; border-radius: 12px; background: var(--gr-soft); color: var(--gr-ink); flex-shrink: 0; }
  @media (min-width: 1200px) { .gr-deliver { display: inline-flex; } }
  .gr-deliver-ic { width: 32px; height: 32px; border-radius: 9px; background: var(--gr-card); display: grid; place-items: center; color: var(--gr-green); }
  .gr-deliver-ic svg { width: 17px; height: 17px; }
  .gr-deliver small { display: block; font-size: 10.5px; color: var(--gr-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
  .gr-deliver strong { display: block; font-size: 13px; font-weight: 700; line-height: 1.15; }
  .gr-search { display: none; flex: 1; max-width: 720px; height: 48px; border: 2px solid rgba(var(--gr-green-rgb), .35); border-radius: 12px; overflow: hidden; background: var(--gr-card); position: relative; transition: border-color .15s, box-shadow .15s; }
  .gr-search:focus-within { border-color: var(--gr-green); box-shadow: 0 0 0 4px rgba(var(--gr-green-rgb), .12); }
  @media (min-width: 1024px) { .gr-search { display: flex; } }
  .gr-search select { appearance: none; border: 0; border-inline-end: 1px solid var(--gr-line); background: var(--gr-soft); color: var(--gr-ink); font-size: 13px; font-weight: 600; padding: 0 30px 0 14px; max-width: 170px; cursor: pointer; outline: none; font-family: inherit;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7682' stroke-width='2.5'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
  .gr-search input { flex: 1; min-width: 0; border: 0; outline: none; padding: 0 14px; font-size: 14px; background: transparent; color: var(--gr-ink); font-family: inherit; }
  .gr-search button { width: 54px; border: 0; background: var(--gr-green); color: #fff; display: grid; place-items: center; cursor: pointer; }
  .gr-search button svg { width: 19px; height: 19px; }
  .gr-search-results { position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 60; background: var(--gr-card); border: 1px solid var(--gr-line); border-radius: 12px; box-shadow: var(--gr-shadow-lg); overflow: hidden; max-height: 420px; overflow-y: auto; }
  .gr-search-results a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; }
  .gr-search-results a:hover { background: rgb(var(--color-bg-muted)); }
  .gr-search-results img { width: 42px; height: 42px; border-radius: 8px; object-fit: cover; background: rgb(var(--color-bg-muted)); }
  .gr-actions { display: flex; align-items: center; gap: 2px; margin-left: auto; }
  @media (min-width: 1024px) { .gr-actions { gap: 6px; } }
  .gr-action { display: inline-flex; align-items: center; gap: 9px; padding: 6px; border-radius: 12px; color: var(--gr-ink); cursor: pointer; background: transparent; border: 0; font-family: inherit; text-align: start; }
  .gr-action:hover { background: rgb(var(--color-bg-muted)); }
  @media (min-width: 1024px) { .gr-action { padding: 6px 10px; } }
  .gr-action-icon { position: relative; width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; background: rgb(var(--color-bg-muted)); color: var(--gr-ink); flex-shrink: 0; }
  .gr-action-icon svg { width: 20px; height: 20px; }
  .gr-action-text { display: none; line-height: 1.15; }
  @media (min-width: 1024px) { .gr-action-text { display: block; } }
  .gr-action-text small { display: block; font-size: 11px; color: var(--gr-muted); font-weight: 500; }
  .gr-action-text strong { display: block; font-size: 13px; font-weight: 700; max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .gr-badge { position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--gr-orange); color: #fff; font-size: 10px; font-weight: 800; display: grid; place-items: center; border: 2px solid var(--gr-card); line-height: 1; }
  .gr-action-cart .gr-action-icon { background: var(--gr-green); color: #fff; }
  .gr-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); min-width: 210px; background: var(--gr-card); border: 1px solid var(--gr-line); border-radius: 12px; box-shadow: var(--gr-shadow-lg); padding: 6px; z-index: 60; }
  .gr-menu a, .gr-menu button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 10px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--gr-ink); background: transparent; border: 0; cursor: pointer; text-align: start; font-family: inherit; }
  .gr-menu a:hover, .gr-menu button:hover { background: rgb(var(--color-bg-muted)); }
  .gr-menu a svg, .gr-menu button svg { width: 16px; height: 16px; color: var(--gr-muted); }
  .gr-menu hr { border: 0; border-top: 1px solid var(--gr-line); margin: 6px 0; }
  .gr-menu .gr-danger { color: #dc2626; }
  .gr-icon-btn { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; background: transparent; border: 0; color: var(--gr-ink); cursor: pointer; }
  .gr-icon-btn:hover { background: rgb(var(--color-bg-muted)); }

  /* category nav */
  .gr-nav { display: none; border-top: 1px solid var(--gr-line); background: var(--gr-card); }
  @media (min-width: 1024px) { .gr-nav { display: block; } }
  .gr-nav ul { display: flex; align-items: center; gap: 2px; height: 48px; margin: 0; padding: 0; list-style: none; }
  .gr-nav li { position: relative; }
  .gr-nav-all { display: inline-flex; align-items: center; gap: 8px; height: 36px; padding: 0 14px; border-radius: 10px; background: var(--gr-green); color: #fff; font-size: 13.5px; font-weight: 700; margin-inline-end: 6px; }
  .gr-nav-all svg { width: 16px; height: 16px; }
  .gr-nav-link { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; font-size: 13.5px; font-weight: 600; color: rgb(var(--color-fg-secondary)); border-radius: 8px; white-space: nowrap; transition: color .15s, background .15s; }
  .gr-nav-link svg { width: 15px; height: 15px; color: var(--gr-green); }
  .gr-nav-link:hover { color: var(--gr-green); background: var(--gr-soft); }
  .gr-nav-link.is-active { color: var(--gr-green); background: var(--gr-soft); }
  .gr-nav-link.gr-deal { color: var(--gr-orange); }
  .gr-nav-link.gr-deal svg { color: var(--gr-orange); }
  .gr-nav-link.gr-deal:hover { background: rgba(var(--gr-orange-rgb), .1); color: var(--gr-orange); }
  .gr-mega { display: none; position: absolute; top: 100%; inset-inline-start: 0; min-width: 240px; background: var(--gr-card); border: 1px solid var(--gr-line); border-radius: 12px; box-shadow: var(--gr-shadow-lg); padding: 8px; z-index: 50; }
  .gr-nav li:hover > .gr-mega, .gr-nav li:focus-within > .gr-mega { display: block; }
  .gr-mega a { display: block; padding: 8px 10px; border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--gr-ink); }
  .gr-mega a:hover { background: rgb(var(--color-bg-muted)); color: var(--gr-green); }
  .gr-mega-grid { display: grid; grid-template-columns: repeat(3, minmax(170px, 1fr)); gap: 2px 8px; min-width: 560px; }
  .gr-mobile-search { display: block; padding: 0 0 10px; }
  @media (min-width: 1024px) { .gr-mobile-search { display: none; } }
  .gr-mobile-search form { position: relative; }
  .gr-mobile-search input { width: 100%; height: 42px; border: 2px solid rgba(var(--gr-green-rgb), .35); border-radius: 11px; padding: 0 44px 0 14px; font-size: 14px; background: var(--gr-card); color: var(--gr-ink); outline: none; font-family: inherit; }
  .gr-mobile-search button { position: absolute; inset-inline-end: 4px; top: 4px; width: 34px; height: 34px; border-radius: 9px; border: 0; background: var(--gr-green); color: #fff; display: grid; place-items: center; }
  .gr-mobile-search button svg { width: 16px; height: 16px; }

  /* ---------- hero ---------- */
  .gr-hero { display: grid; grid-template-columns: 1fr; gap: 14px; margin-top: 18px; }
  @media (min-width: 1024px) { .gr-hero { grid-template-columns: 2fr 1fr; } }
  .gr-hero-main { position: relative; border-radius: 22px; overflow: hidden; min-height: 300px; background: var(--gr-green); color: #fff; display: flex; align-items: center; isolation: isolate; }
  @media (min-width: 1024px) { .gr-hero-main { min-height: 380px; } }
  .gr-hero-main img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: -2; }
  .gr-hero-main::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(10,40,20,.86) 0%, rgba(10,40,20,.55) 50%, rgba(10,40,20,.15) 100%); z-index: -1; }
  .gr-hero-copy { padding: 34px 28px; max-width: 560px; }
  @media (min-width: 1024px) { .gr-hero-copy { padding: 44px 48px; } }
  .gr-hero-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: var(--gr-orange); color: #fff; font-size: 12px; font-weight: 800; margin-bottom: 14px; }
  .gr-hero-badge svg { width: 13px; height: 13px; }
  .gr-hero-kicker { font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; opacity: .9; margin-bottom: 8px; }
  .gr-hero-title { font-family: 'Manrope', sans-serif; font-size: 32px; line-height: 1.08; font-weight: 800; letter-spacing: -0.03em; margin: 0 0 12px; }
  @media (min-width: 768px) { .gr-hero-title { font-size: 42px; } }
  @media (min-width: 1280px) { .gr-hero-title { font-size: 48px; } }
  .gr-hero-sub { font-size: 15px; line-height: 1.55; opacity: .92; margin: 0 0 22px; max-width: 460px; }
  .gr-hero-actions { display: flex; flex-wrap: wrap; gap: 10px; }
  .gr-hero-dots { position: absolute; bottom: 16px; inset-inline-start: 28px; display: flex; gap: 6px; z-index: 2; }
  @media (min-width: 1024px) { .gr-hero-dots { inset-inline-start: 48px; } }
  .gr-hero-dots button { width: 8px; height: 8px; border-radius: 99px; background: rgba(255,255,255,.45); border: 0; cursor: pointer; padding: 0; transition: width .2s, background .2s; }
  .gr-hero-dots button.is-active { width: 22px; background: #fff; }
  .gr-slide { position: absolute; inset: 0; display: flex; align-items: center; opacity: 0; transition: opacity .6s; pointer-events: none; }
  .gr-slide.is-active { opacity: 1; pointer-events: auto; position: relative; width: 100%; flex: 1 1 100%; min-height: inherit; }
  .gr-hero-side { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  @media (min-width: 1024px) { .gr-hero-side { grid-template-columns: 1fr; } }
  .gr-tile { position: relative; display: flex; flex-direction: column; justify-content: flex-end; min-height: 150px; border-radius: 18px; overflow: hidden; padding: 18px; color: var(--gr-ink); transition: transform .2s, box-shadow .2s; }
  @media (min-width: 1024px) { .gr-tile { min-height: 183px; } }
  .gr-tile:hover { transform: translateY(-3px); box-shadow: var(--gr-shadow-lg); }
  .gr-tile img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0; }
  .gr-tile::before { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(255,255,255,0) 25%, rgba(255,255,255,.96) 70%); z-index: 1; }
  .gr-tile > * { position: relative; z-index: 2; }
  .gr-tile small { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
  .gr-tile h3 { margin: 2px 0 6px; font-family: 'Manrope', sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; color: var(--gr-ink); }
  .gr-tile span.gr-tile-cta { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 700; }
  .gr-tile span.gr-tile-cta svg { width: 14px; height: 14px; }

  /* ---------- category strip ---------- */
  .gr-cats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
  @media (min-width: 640px) { .gr-cats { grid-template-columns: repeat(5, 1fr); } }
  @media (min-width: 1024px) { .gr-cats { grid-template-columns: repeat(var(--gr-cat-cols, 10), 1fr); gap: 12px; } }
  .gr-cat { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; }
  .gr-cat-circle { width: 84px; height: 84px; border-radius: 999px; overflow: hidden; background: var(--gr-soft); display: grid; place-items: center; color: var(--gr-green); border: 3px solid var(--gr-card); box-shadow: 0 0 0 1px var(--gr-line); transition: transform .2s, box-shadow .2s; }
  @media (min-width: 1024px) { .gr-cat-circle { width: 96px; height: 96px; } }
  .gr-cat:hover .gr-cat-circle { transform: translateY(-3px) scale(1.04); box-shadow: 0 0 0 2px var(--gr-green); }
  .gr-cat-circle img { width: 100%; height: 100%; object-fit: cover; }
  .gr-cat-circle svg { width: 32px; height: 32px; }
  .gr-cat strong { font-size: 13px; font-weight: 700; color: var(--gr-ink); line-height: 1.2; }
  .gr-cat small { font-size: 11px; color: var(--gr-muted); }

  /* ---------- product grid / card ---------- */
  .gr-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
  @media (min-width: 768px) { .gr-grid { grid-template-columns: repeat(3, 1fr); gap: 14px; } }
  @media (min-width: 1024px) { .gr-grid { grid-template-columns: repeat(var(--gr-cols, 6), 1fr); } }
  .product-card.gr-card { position: relative; display: flex; flex-direction: column; background: var(--gr-card); border: 1px solid var(--gr-line); border-radius: var(--gr-radius); box-shadow: none; overflow: hidden; transition: box-shadow .2s, border-color .2s; }
  .product-card.gr-card:hover { box-shadow: var(--gr-shadow-lg); border-color: rgba(var(--gr-green-rgb), .45); }
  .gr-card-media { position: relative; display: block; aspect-ratio: 1 / 1; overflow: hidden; }
  .gr-card-media img { position: absolute; inset: 10px; width: calc(100% - 20px); height: calc(100% - 20px); object-fit: cover; border-radius: 10px; background: rgb(var(--color-bg-muted)); transition: transform .35s; }
  .product-card.gr-card:hover .gr-card-media img { transform: scale(1.04); }
  .gr-card-badges { position: absolute; top: 14px; inset-inline-start: 14px; display: flex; flex-direction: column; gap: 4px; z-index: 2; }
  .gr-pill { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; color: #fff; background: var(--gr-orange); }
  .gr-pill.gr-pill-new { background: var(--gr-green); }
  .gr-pill.gr-pill-out { background: #6b7682; }
  .gr-pill.gr-pill-pre { background: #d97706; }
  .gr-pill.gr-pill-hot { background: #2563eb; }
  .gr-card-actions { position: absolute; top: 14px; inset-inline-end: 14px; display: flex; flex-direction: column; gap: 6px; z-index: 2; opacity: 0; transform: translateX(6px); transition: opacity .2s, transform .2s; }
  .product-card.gr-card:hover .gr-card-actions, .product-card.gr-card:focus-within .gr-card-actions { opacity: 1; transform: none; }
  @media (hover: none) { .gr-card-actions { opacity: 1; transform: none; } }
  .gr-card-action { width: 32px; height: 32px; border-radius: 999px; background: var(--gr-card); border: 1px solid var(--gr-line); color: var(--gr-ink); display: grid; place-items: center; cursor: pointer; box-shadow: var(--gr-shadow); }
  .gr-card-action:hover { background: var(--gr-green); color: #fff; border-color: var(--gr-green); }
  .gr-card-action[aria-pressed="true"] { background: #e11d48; color: #fff; border-color: #e11d48; }
  .gr-card-action svg { width: 15px; height: 15px; }
  .gr-card-body { display: flex; flex-direction: column; gap: 4px; padding: 2px 12px 12px; flex: 1; }
  .gr-card-unit { font-size: 11.5px; color: var(--gr-muted); font-weight: 600; }
  .gr-card-title { font-size: 13.5px; font-weight: 600; line-height: 1.35; color: var(--gr-ink); margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em; }
  .gr-card-title a:hover { color: var(--gr-green); }
  .gr-stars { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--gr-muted); }
  .gr-stars-icons { display: inline-flex; gap: 1px; color: #f59e0b; }
  .gr-stars-icons svg { width: 11px; height: 11px; }
  .gr-stars-icons .is-empty { color: rgb(var(--color-border-strong)); }
  .gr-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; padding-top: 6px; }
  .gr-price { display: flex; flex-direction: column; line-height: 1.1; }
  .gr-price b { font-family: 'Manrope', sans-serif; font-size: 16px; font-weight: 800; color: var(--gr-ink); }
  .gr-price s { font-size: 11.5px; color: var(--gr-muted); }
  .gr-price .gr-price-deal { color: var(--gr-orange); }
  .gr-add { height: 36px; padding: 0 12px; border-radius: 10px; border: 1.5px solid var(--gr-green); background: var(--gr-card); color: var(--gr-green); display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; cursor: pointer; flex-shrink: 0; transition: background .15s, color .15s; font-family: inherit; }
  .gr-add:hover:not(:disabled) { background: var(--gr-green); color: #fff; }
  .gr-add:disabled { opacity: .45; cursor: not-allowed; }
  .gr-add svg { width: 15px; height: 15px; }
  .gr-add.is-pre { border-color: #d97706; color: #d97706; }
  .gr-add.is-pre:hover:not(:disabled) { background: #d97706; color: #fff; }
  .gr-card .js-add-status { font-size: 11px; color: var(--gr-green); min-height: 14px; }

  /* deals row */
  .gr-deals { border: 2px solid rgba(var(--gr-orange-rgb), .35); background: rgba(var(--gr-orange-rgb), .05); border-radius: 20px; padding: 18px; }
  @media (min-width: 1024px) { .gr-deals { padding: 22px; } }
  .gr-deals .gr-head { margin-bottom: 14px; }
  .gr-timer { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: var(--gr-orange); color: #fff; font-size: 12.5px; font-weight: 800; }
  .gr-timer svg { width: 14px; height: 14px; }

  /* aisle rows */
  .gr-aisle { margin-top: 26px; }
  .gr-aisle-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
  .gr-aisle-head h3 { margin: 0; font-family: 'Manrope', sans-serif; font-size: 18px; font-weight: 800; display: inline-flex; align-items: center; gap: 10px; color: var(--gr-ink); }
  .gr-aisle-head h3 img { width: 34px; height: 34px; border-radius: 999px; object-fit: cover; }
  .gr-aisle-head h3 svg { width: 20px; height: 20px; color: var(--gr-green); }

  /* promo tiles (3) */
  .gr-promos { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 768px) { .gr-promos { grid-template-columns: repeat(3, 1fr); } }
  .gr-promo { display: grid; grid-template-columns: 1.1fr 1fr; align-items: center; gap: 8px; min-height: 150px; border-radius: 18px; padding: 18px; overflow: hidden; transition: transform .2s, box-shadow .2s; }
  .gr-promo:hover { transform: translateY(-3px); box-shadow: var(--gr-shadow-lg); }
  .gr-promo small { display: block; font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; opacity: .8; }
  .gr-promo h3 { margin: 2px 0 8px; font-family: 'Manrope', sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; }
  .gr-promo span.gr-tile-cta { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 800; }
  .gr-promo span.gr-tile-cta svg { width: 14px; height: 14px; }
  .gr-promo img { width: 100%; height: 120px; object-fit: cover; border-radius: 14px; justify-self: end; }

  /* trust */
  .gr-trust { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
  @media (min-width: 1024px) { .gr-trust { grid-template-columns: repeat(var(--gr-trust-cols, 4), 1fr); } }
  .gr-trust-item { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--gr-soft); border-radius: 14px; }
  .gr-trust-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--gr-card); color: var(--gr-green); display: grid; place-items: center; flex-shrink: 0; }
  .gr-trust-icon svg { width: 22px; height: 22px; }
  .gr-trust-item strong { display: block; font-size: 13.5px; font-weight: 800; color: var(--gr-ink); }
  .gr-trust-item span { display: block; font-size: 12px; color: var(--gr-muted); }

  /* newsletter + brands */
  .gr-newsletter { display: grid; grid-template-columns: 1fr; gap: 16px; align-items: center; padding: 26px; border-radius: 20px; background: var(--gr-green); color: #fff; position: relative; overflow: hidden; }
  @media (min-width: 1024px) { .gr-newsletter { grid-template-columns: auto 1fr auto; gap: 26px; padding: 30px 36px; } }
  .gr-newsletter-icon { width: 64px; height: 64px; border-radius: 18px; background: rgba(255,255,255,.16); display: grid; place-items: center; }
  .gr-newsletter-icon svg { width: 30px; height: 30px; }
  .gr-newsletter h3 { margin: 0 0 4px; font-family: 'Manrope', sans-serif; font-size: 21px; font-weight: 800; }
  .gr-newsletter p { margin: 0; font-size: 14px; opacity: .92; }
  .gr-newsletter form { display: flex; gap: 8px; width: 100%; }
  @media (min-width: 1024px) { .gr-newsletter form { width: 420px; } }
  .gr-newsletter input { flex: 1; min-width: 0; height: 46px; border: 0; border-radius: 11px; padding: 0 14px; font-size: 14px; background: #fff; color: var(--gr-ink); outline: none; font-family: inherit; }
  .gr-newsletter-note { grid-column: 1 / -1; font-size: 11.5px; opacity: .85; margin-top: -8px; }
  .gr-brands { display: flex; flex-wrap: wrap; gap: 10px; }
  .gr-brand { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 52px; padding: 0 20px; background: var(--gr-card); border: 1px solid var(--gr-line); border-radius: 12px; font-weight: 700; color: rgb(var(--color-fg-secondary)); font-size: 14px; flex: 1 1 130px; }
  .gr-brand:hover { border-color: var(--gr-green); color: var(--gr-green); }
  .gr-brand img { max-height: 30px; max-width: 100px; object-fit: contain; }

  /* footer */
  .gr-footer { margin-top: 44px; background: #0f1f16; color: #b8c6bd; }
  .dark .gr-footer { background: #08120d; }
  .gr-footer-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 28px 20px; padding: 44px 0 34px; }
  @media (min-width: 768px) { .gr-footer-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (min-width: 1024px) { .gr-footer-grid { grid-template-columns: 1.6fr 1fr 1fr 1fr 1.3fr; } }
  .gr-footer-brand { grid-column: 1 / -1; }
  @media (min-width: 1024px) { .gr-footer-brand { grid-column: auto; } }
  .gr-footer .gr-logo { color: #fff; }
  .gr-footer .gr-logo em { color: #6ee7a0; }
  .gr-footer-brand p { font-size: 13.5px; line-height: 1.65; margin: 12px 0 16px; max-width: 320px; }
  .gr-footer h6 { margin: 0 0 14px; font-size: 13px; font-weight: 800; color: #fff; letter-spacing: .04em; text-transform: uppercase; }
  .gr-footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
  .gr-footer li a { font-size: 13.5px; color: #b8c6bd; }
  .gr-footer li a:hover { color: #fff; }
  .gr-footer-contact li { display: flex; align-items: flex-start; gap: 9px; font-size: 13.5px; }
  .gr-footer-contact svg { width: 15px; height: 15px; color: #6ee7a0; flex-shrink: 0; margin-top: 2px; }
  .gr-social { display: flex; gap: 8px; }
  .gr-social a { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; background: rgba(255,255,255,.08); color: #fff; }
  .gr-social a:hover { background: var(--gr-green); }
  .gr-social svg { width: 15px; height: 15px; }
  .gr-hours { display: flex; align-items: center; gap: 8px; font-size: 13px; margin-top: 12px; }
  .gr-hours svg { width: 15px; height: 15px; color: #6ee7a0; }
  .gr-footer-bottom { border-top: 1px solid rgba(255,255,255,.1); padding: 18px 0; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; font-size: 12.5px; color: #8fa197; }
  .gr-pay { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .gr-pay span { display: inline-flex; align-items: center; justify-content: center; height: 26px; min-width: 44px; padding: 0 8px; border-radius: 6px; background: #fff; font-size: 10px; font-weight: 800; letter-spacing: .02em; color: #1a1f71; }
  .gr-pay .gr-pay-mc i { width: 14px; height: 14px; border-radius: 50%; display: inline-block; }
  .gr-pay .gr-pay-mc i:first-child { background: #eb001b; }
  .gr-pay .gr-pay-mc i:last-child { background: #f79e1b; margin-left: -5px; }
  .gr-pay .gr-pay-pp { color: #003087; font-style: italic; }
  .gr-pay .gr-pay-cod { color: #15803d; }

  .gr-empty { padding: 28px; text-align: center; color: var(--gr-muted); font-size: 13px; }
  [x-cloak] { display: none !important; }
  @media (max-width: 1023.98px) { .gr-footer { padding-bottom: 5rem; } }
</style>
