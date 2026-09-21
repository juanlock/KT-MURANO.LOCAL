{{-- Electronics theme skin. Self-contained CSS (no Tailwind rebuild needed):
     re-tints the shared design tokens so every existing storefront page picks
     up the light, blue, card-based look, and defines the `el-*` components used
     by this theme's header, footer and homepage. --}}
@php
  $elAccent = $s->primary_color ?: '#2563eb';
  $elAccentDark = $s->secondary_color ?: '#1d4ed8';
  // Darken the accent for hover when the admin left secondary as the old cyan.
  if (strtolower($elAccentDark) === '#00c2ff' || strtolower($elAccentDark) === '#22d3ee') { $elAccentDark = '#1d4ed8'; }
  $elHex = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '37 99 235';
      return hexdec(substr($hex,0,2)).' '.hexdec(substr($hex,2,2)).' '.hexdec(substr($hex,4,2));
  };
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
<style>
  /* ---------- Tokens: light, airy, blue ---------- */
  :root {
    --color-bg-base:     246 248 251;
    --color-bg-surface:  255 255 255;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted:    241 245 249;
    --color-border-subtle: 229 233 240;
    --color-border-strong: 203 213 225;
    --color-fg-primary:   15 23 42;
    --color-fg-secondary: 71 85 105;
    --color-fg-muted:     100 116 139;
    --color-accent-400: {{ $elHex($elAccent) }};
    --color-accent-500: {{ $elHex($elAccent) }};
    --color-accent-600: {{ $elHex($elAccentDark) }};
    --color-accent-glow: rgba({{ str_replace(' ', ',', $elHex($elAccent)) }}, 0.25);
    --el-accent: {{ $elAccent }};
    --el-accent-dark: {{ $elAccentDark }};
    --el-accent-soft: rgb(var(--color-accent-500) / .08);
    --el-ink: #0f172a;
    --el-muted: #64748b;
    --el-line: #e5e9f0;
    --el-card: #ffffff;
    --el-page: #f6f8fb;
    --el-star: #f59e0b;
    --el-deal: #e11d48;
    --el-radius: 14px;
    --el-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 24px -12px rgba(15, 23, 42, .12);
    --el-shadow-lg: 0 24px 48px -16px rgba(15, 23, 42, .18);
  }
  .dark {
    --el-ink: #e2e8f0; --el-muted: #94a3b8; --el-line: #263047; --el-card: #111827; --el-page: #0b1020;
    --el-accent-soft: rgb(var(--color-accent-500) / .16);
    --el-shadow: none; --el-shadow-lg: 0 24px 48px -16px rgba(0,0,0,.6);
  }
  html { scroll-behavior: smooth; }
  body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--el-page); }

  /* Shared components, re-tuned for this theme */
  .btn { border-radius: 10px; }
  .btn-primary { box-shadow: 0 6px 16px -6px rgb(var(--color-accent-500) / .55); }
  .card, .product-card { border-radius: var(--el-radius); }
  .section-title, .hero-title { font-family: 'Inter', system-ui, sans-serif; letter-spacing: -0.02em; }
  .input, .select, .textarea { border-radius: 10px; }
  .product-card { box-shadow: var(--el-shadow); }
  .drawer-panel, .dialog-panel, .auth-panel { border-radius: 16px; }
  .newsletter-panel { border-radius: 18px; }
  .price, .price-lg, .price-xl, .price-sm, .price-compare { font-family: 'Inter', system-ui, sans-serif; letter-spacing: -0.01em; }

  /* ---------- Layout helpers ---------- */
  .el-container { width: 100%; max-width: 1280px; margin: 0 auto; padding: 0 16px; }
  @media (min-width: 1024px) { .el-container { padding: 0 24px; } }
  .el-section { padding: 28px 0; }
  @media (min-width: 1024px) { .el-section { padding: 36px 0; } }
  .el-section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
  .el-h2 { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; color: var(--el-ink); margin: 0; }
  @media (min-width: 768px) { .el-h2 { font-size: 24px; } }
  .el-kicker { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--el-accent); margin-bottom: 6px; }
  .el-link-all { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 600; color: var(--el-accent); white-space: nowrap; }
  .el-link-all:hover { text-decoration: underline; }
  .el-muted { color: var(--el-muted); }
  .el-panel { background: var(--el-card); border: 1px solid var(--el-line); border-radius: var(--el-radius); box-shadow: var(--el-shadow); }

  /* ---------- Top bar ---------- */
  .el-topbar { background: linear-gradient(90deg, var(--el-accent), var(--el-accent-dark)); color: #fff; font-size: 12px; }
  .el-topbar .el-container { display: flex; align-items: center; justify-content: space-between; gap: 12px; height: 36px; }
  .el-topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .el-topbar-left b { font-weight: 600; }
  .el-topbar-right { display: none; align-items: center; gap: 4px; }
  @media (min-width: 768px) { .el-topbar-right { display: flex; } }
  .el-topbar-right a, .el-topbar-right button { color: #fff; opacity: .92; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; background: transparent; border: 0; cursor: pointer; }
  .el-topbar-right a:hover, .el-topbar-right button:hover { background: rgba(255,255,255,.14); opacity: 1; }
  .el-topbar-sep { width: 1px; height: 14px; background: rgba(255,255,255,.35); margin: 0 4px; }

  /* ---------- Header ---------- */
  .el-header { position: sticky; top: 0; z-index: 40; background: var(--el-card); border-bottom: 1px solid var(--el-line); }
  .el-header-main { display: flex; align-items: center; gap: 12px; height: 64px; }
  @media (min-width: 1024px) { .el-header-main { height: 78px; gap: 22px; } }
  .el-logo { display: inline-flex; align-items: center; gap: 10px; flex-shrink: 0; font-weight: 900; font-size: 22px; letter-spacing: -0.03em; color: var(--el-ink); }
  .el-logo img { height: 36px; max-width: 160px; object-fit: contain; }
  .el-logo .el-logo-accent { color: var(--el-accent); }
  .el-search { display: none; flex: 1; max-width: 680px; height: 46px; border: 1.5px solid var(--el-line); border-radius: 12px; overflow: hidden; background: var(--el-card); transition: border-color .15s, box-shadow .15s; position: relative; }
  .el-search:focus-within { border-color: var(--el-accent); box-shadow: 0 0 0 3px var(--el-accent-soft); }
  @media (min-width: 1024px) { .el-search { display: flex; } }
  .el-search select { appearance: none; border: 0; border-right: 1px solid var(--el-line); background: rgb(var(--color-bg-muted)); color: var(--el-ink); font-size: 13px; font-weight: 500; padding: 0 30px 0 14px; max-width: 170px; cursor: pointer; outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
  .el-search input { flex: 1; min-width: 0; border: 0; outline: none; padding: 0 14px; font-size: 14px; background: transparent; color: var(--el-ink); }
  .el-search input::placeholder { color: var(--el-muted); }
  .el-search button { width: 52px; border: 0; background: var(--el-accent); color: #fff; display: grid; place-items: center; cursor: pointer; transition: background .15s; }
  .el-search button:hover { background: var(--el-accent-dark); }
  .el-search-results { position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 60; background: var(--el-card); border: 1px solid var(--el-line); border-radius: 12px; box-shadow: var(--el-shadow-lg); overflow: hidden; max-height: 420px; overflow-y: auto; }
  .el-search-results a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; }
  .el-search-results a:hover { background: rgb(var(--color-bg-muted)); }
  .el-search-results img { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; background: rgb(var(--color-bg-muted)); }
  .el-actions { display: flex; align-items: center; gap: 2px; margin-left: auto; }
  @media (min-width: 1024px) { .el-actions { gap: 6px; } }
  .el-action { display: inline-flex; align-items: center; gap: 10px; padding: 6px; border-radius: 12px; color: var(--el-ink); cursor: pointer; background: transparent; border: 0; transition: background .15s; text-align: start; }
  .el-action:hover { background: rgb(var(--color-bg-muted)); }
  @media (min-width: 1024px) { .el-action { padding: 6px 10px; } }
  .el-action-icon { position: relative; width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; background: rgb(var(--color-bg-muted)); color: var(--el-ink); flex-shrink: 0; }
  .el-action-icon svg { width: 20px; height: 20px; }
  .el-action-text { display: none; line-height: 1.15; }
  @media (min-width: 1024px) { .el-action-text { display: block; } }
  .el-action-text small { display: block; font-size: 11px; color: var(--el-muted); font-weight: 500; }
  .el-action-text strong { display: block; font-size: 13px; font-weight: 700; color: var(--el-ink); max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .el-badge { position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--el-accent); color: #fff; font-size: 10px; font-weight: 700; display: grid; place-items: center; border: 2px solid var(--el-card); line-height: 1; }
  .el-action-cart .el-action-icon { background: var(--el-accent); color: #fff; }
  .el-action-cart .el-badge { background: var(--el-deal); }
  .el-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); min-width: 210px; background: var(--el-card); border: 1px solid var(--el-line); border-radius: 12px; box-shadow: var(--el-shadow-lg); padding: 6px; z-index: 60; }
  .el-menu a, .el-menu button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 10px; border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--el-ink); background: transparent; border: 0; cursor: pointer; text-align: start; }
  .el-menu a:hover, .el-menu button:hover { background: rgb(var(--color-bg-muted)); }
  .el-menu a svg, .el-menu button svg { width: 16px; height: 16px; color: var(--el-muted); }
  .el-menu hr { border: 0; border-top: 1px solid var(--el-line); margin: 6px 0; }
  .el-menu .el-danger { color: var(--el-deal); }
  .el-icon-btn { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; background: transparent; border: 0; color: var(--el-ink); cursor: pointer; }
  .el-icon-btn:hover { background: rgb(var(--color-bg-muted)); }

  /* Category nav */
  .el-nav { border-top: 1px solid var(--el-line); display: none; }
  @media (min-width: 1024px) { .el-nav { display: block; } }
  .el-nav ul { display: flex; align-items: center; gap: 2px; height: 46px; margin: 0; padding: 0; list-style: none; }
  .el-nav li { position: relative; }
  .el-nav-link { display: inline-flex; align-items: center; gap: 5px; padding: 8px 13px; font-size: 14px; font-weight: 500; color: rgb(var(--color-fg-secondary)); border-radius: 8px; transition: color .15s, background .15s; white-space: nowrap; }
  .el-nav-link:hover { color: var(--el-accent); background: var(--el-accent-soft); }
  .el-nav-link.is-active { color: var(--el-accent); font-weight: 600; }
  .el-nav-link.is-active::after { content: ''; position: absolute; left: 13px; right: 13px; bottom: -1px; height: 2px; background: var(--el-accent); border-radius: 2px; }
  .el-nav-link.el-deal { color: var(--el-deal); font-weight: 600; }
  .el-nav-link.el-deal:hover { background: rgba(225,29,72,.08); color: var(--el-deal); }
  .el-nav-link svg { width: 13px; height: 13px; opacity: .7; }
  .el-mega { display: none; position: absolute; top: 100%; inset-inline-start: 0; min-width: 230px; background: var(--el-card); border: 1px solid var(--el-line); border-radius: 12px; box-shadow: var(--el-shadow-lg); padding: 8px; z-index: 50; }
  .el-nav li:hover > .el-mega, .el-nav li:focus-within > .el-mega { display: block; }
  .el-mega a { display: block; padding: 8px 10px; border-radius: 8px; font-size: 13px; color: var(--el-ink); }
  .el-mega a:hover { background: rgb(var(--color-bg-muted)); color: var(--el-accent); }
  .el-mega-title { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--el-muted); padding: 6px 10px 4px; }
  .el-mobile-search { display: block; padding: 0 0 10px; }
  @media (min-width: 1024px) { .el-mobile-search { display: none; } }
  .el-mobile-search form { position: relative; }
  .el-mobile-search input { width: 100%; height: 42px; border: 1.5px solid var(--el-line); border-radius: 11px; padding: 0 44px 0 14px; font-size: 14px; background: var(--el-card); color: var(--el-ink); outline: none; }
  .el-mobile-search input:focus { border-color: var(--el-accent); }
  .el-mobile-search button { position: absolute; inset-inline-end: 4px; top: 4px; width: 34px; height: 34px; border-radius: 9px; border: 0; background: var(--el-accent); color: #fff; display: grid; place-items: center; }

  /* ---------- Hero slider ---------- */
  .el-hero { position: relative; margin-top: 18px; border-radius: 22px; overflow: hidden; border: 1px solid var(--el-line); background: linear-gradient(120deg, #eef3ff 0%, #f7f9ff 45%, #e9efff 100%); min-height: 380px; }
  .dark .el-hero { background: linear-gradient(120deg, #0f172a, #111c3a); }
  @media (min-width: 1024px) { .el-hero { min-height: 460px; } }
  .el-slide { position: absolute; inset: 0; display: grid; grid-template-columns: 1fr; align-items: center; padding: 28px 22px 60px; opacity: 0; transition: opacity .6s ease; pointer-events: none; }
  .el-slide.is-active { opacity: 1; pointer-events: auto; position: relative; }
  @media (min-width: 768px) { .el-slide { grid-template-columns: 1.05fr 1fr; padding: 40px 44px 60px; gap: 24px; } }
  @media (min-width: 1024px) { .el-slide { padding: 56px 64px 64px; } }
  .el-slide-copy { max-width: 560px; }
  .el-slide-kicker { font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--el-accent); margin-bottom: 14px; display: inline-flex; align-items: center; gap: 8px; }
  .el-slide-kicker::before { content: ''; width: 22px; height: 2px; background: var(--el-accent); border-radius: 2px; }
  .el-slide-title { font-size: 34px; line-height: 1.05; font-weight: 900; letter-spacing: -0.035em; color: var(--el-ink); margin: 0 0 16px; }
  @media (min-width: 768px) { .el-slide-title { font-size: 46px; } }
  @media (min-width: 1280px) { .el-slide-title { font-size: 56px; } }
  .el-slide-sub { font-size: 15px; line-height: 1.6; color: rgb(var(--color-fg-secondary)); margin: 0 0 24px; max-width: 480px; }
  .el-slide-actions { display: flex; flex-wrap: wrap; gap: 10px; }
  .el-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 46px; padding: 0 22px; border-radius: 11px; font-size: 14px; font-weight: 600; border: 1.5px solid transparent; cursor: pointer; transition: transform .15s, background .15s, color .15s, border-color .15s, box-shadow .15s; white-space: nowrap; }
  .el-btn:active { transform: translateY(1px); }
  .el-btn-primary { background: var(--el-accent); color: #fff; box-shadow: 0 10px 22px -10px rgb(var(--color-accent-500) / .7); }
  .el-btn-primary:hover { background: var(--el-accent-dark); color: #fff; }
  .el-btn-outline { background: var(--el-card); color: var(--el-accent); border-color: rgb(var(--color-accent-500) / .45); }
  .el-btn-outline:hover { border-color: var(--el-accent); background: var(--el-accent-soft); }
  .el-btn-white { background: #fff; color: var(--el-ink); }
  .el-btn-white:hover { background: #f1f5f9; }
  .el-btn-sm { height: 38px; padding: 0 16px; font-size: 13px; border-radius: 9px; }
  .el-slide-media { position: relative; display: block; margin-top: 22px; }
  @media (min-width: 768px) { .el-slide-media { margin-top: 0; } }
  .el-slide-media img { width: 100%; height: 210px; object-fit: cover; border-radius: 18px; box-shadow: var(--el-shadow-lg); }
  @media (min-width: 768px) { .el-slide-media img { height: 300px; } }
  @media (min-width: 1024px) { .el-slide-media img { height: 360px; } }
  .el-slide-media::before { content: ''; position: absolute; inset: -30px; background: radial-gradient(60% 60% at 60% 40%, rgb(var(--color-accent-500) / .18), transparent 70%); z-index: 0; }
  .el-slide-media img { position: relative; z-index: 1; }
  .el-hero-dots { position: absolute; left: 22px; bottom: 22px; display: flex; gap: 6px; z-index: 5; }
  @media (min-width: 1024px) { .el-hero-dots { left: 64px; bottom: 30px; } }
  .el-hero-dots button { width: 8px; height: 8px; border-radius: 99px; background: rgb(var(--color-border-strong)); border: 0; cursor: pointer; transition: width .2s, background .2s; padding: 0; }
  .el-hero-dots button.is-active { width: 22px; background: var(--el-accent); }
  .el-hero-arrows { position: absolute; right: 18px; bottom: 18px; display: none; gap: 6px; z-index: 5; }
  @media (min-width: 768px) { .el-hero-arrows { display: flex; } }
  .el-hero-arrows button { width: 36px; height: 36px; border-radius: 10px; background: var(--el-card); border: 1px solid var(--el-line); color: var(--el-ink); display: grid; place-items: center; cursor: pointer; }
  .el-hero-arrows button:hover { border-color: var(--el-accent); color: var(--el-accent); }
  .el-hero-arrows svg { width: 16px; height: 16px; }

  /* ---------- Trust strip ---------- */
  .el-trust { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; margin-top: 18px; background: var(--el-card); border: 1px solid var(--el-line); border-radius: 16px; overflow: hidden; }
  @media (min-width: 768px) { .el-trust { grid-template-columns: repeat(3, 1fr); } }
  @media (min-width: 1024px) { .el-trust { grid-template-columns: repeat(var(--el-trust-cols, 5), 1fr); } }
  .el-trust-item { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-right: 1px solid var(--el-line); border-bottom: 1px solid var(--el-line); }
  .el-trust-icon { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: var(--el-accent-soft); color: var(--el-accent); flex-shrink: 0; }
  .el-trust-icon svg { width: 20px; height: 20px; }
  .el-trust-item strong { display: block; font-size: 13px; font-weight: 700; color: var(--el-ink); }
  .el-trust-item span { display: block; font-size: 12px; color: var(--el-muted); margin-top: 1px; }

  /* ---------- Category tiles ---------- */
  .el-cats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
  @media (min-width: 640px) { .el-cats { grid-template-columns: repeat(4, 1fr); } }
  @media (min-width: 1024px) { .el-cats { grid-template-columns: repeat(var(--el-cat-cols, 8), 1fr); gap: 14px; } }
  .el-cat { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 12px 10px 14px; background: var(--el-card); border: 1px solid var(--el-line); border-radius: var(--el-radius); text-align: center; transition: transform .2s, box-shadow .2s, border-color .2s; }
  .el-cat:hover { transform: translateY(-3px); box-shadow: var(--el-shadow-lg); border-color: rgb(var(--color-accent-500) / .4); }
  .el-cat-media { width: 100%; aspect-ratio: 1 / 1; border-radius: 10px; overflow: hidden; background: rgb(var(--color-bg-muted)); display: grid; place-items: center; color: var(--el-accent); }
  .el-cat-media img { width: 100%; height: 100%; object-fit: cover; transition: transform .35s; }
  .el-cat:hover .el-cat-media img { transform: scale(1.06); }
  .el-cat-media svg { width: 34px; height: 34px; }
  .el-cat strong { font-size: 13px; font-weight: 600; color: var(--el-ink); line-height: 1.2; }
  .el-cat small { font-size: 11px; color: var(--el-muted); }

  /* ---------- Tabs ---------- */
  .el-tabs { display: flex; align-items: center; gap: 4px; }
  .el-tab { padding: 8px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--el-muted); background: transparent; border: 0; cursor: pointer; position: relative; }
  .el-tab:hover { color: var(--el-ink); }
  .el-tab.is-active { color: var(--el-accent); }
  .el-tab.is-active::after { content: ''; position: absolute; left: 12px; right: 12px; bottom: 2px; height: 2px; background: var(--el-accent); border-radius: 2px; }

  /* ---------- Product grid + card ---------- */
  .el-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
  @media (min-width: 768px) { .el-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; } }
  @media (min-width: 1024px) { .el-grid { grid-template-columns: repeat(var(--el-cols, 5), 1fr); } }
  .product-card.el-card { position: relative; display: flex; flex-direction: column; background: var(--el-card); border: 1px solid var(--el-line); border-radius: var(--el-radius); box-shadow: none; overflow: hidden; transition: transform .2s, box-shadow .2s, border-color .2s; }
  .product-card.el-card:hover { transform: translateY(-3px); box-shadow: var(--el-shadow-lg); border-color: rgb(var(--color-accent-500) / .35); }
  .el-card-media { position: relative; display: block; aspect-ratio: 1 / 1; background: var(--el-card); overflow: hidden; }
  .el-card-media img { position: absolute; inset: 10px; width: calc(100% - 20px); height: calc(100% - 20px); object-fit: cover; border-radius: 10px; background: rgb(var(--color-bg-muted)); transition: transform .35s; }
  .product-card.el-card:hover .el-card-media img { transform: scale(1.04); }
  .el-card-badges { position: absolute; top: 16px; inset-inline-start: 16px; display: flex; flex-direction: column; gap: 4px; z-index: 2; }
  .el-badge-pill { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; color: #fff; background: var(--el-deal); line-height: 1.3; }
  .el-badge-pill.el-badge-new { background: #16a34a; }
  .el-badge-pill.el-badge-out { background: #64748b; }
  .el-badge-pill.el-badge-pre { background: #d97706; }
  .el-badge-pill.el-badge-hot { background: var(--el-accent); }
  .el-card-actions { position: absolute; top: 16px; inset-inline-end: 16px; display: flex; flex-direction: column; gap: 6px; z-index: 2; opacity: 0; transform: translateX(6px); transition: opacity .2s, transform .2s; }
  .product-card.el-card:hover .el-card-actions, .product-card.el-card:focus-within .el-card-actions { opacity: 1; transform: none; }
  @media (hover: none) { .el-card-actions { opacity: 1; transform: none; } }
  .el-card-action { width: 34px; height: 34px; border-radius: 10px; background: var(--el-card); border: 1px solid var(--el-line); color: var(--el-ink); display: grid; place-items: center; cursor: pointer; box-shadow: var(--el-shadow); transition: background .15s, color .15s, border-color .15s; }
  .el-card-action:hover { background: var(--el-accent); color: #fff; border-color: var(--el-accent); }
  .el-card-action[aria-pressed="true"] { background: var(--el-deal); color: #fff; border-color: var(--el-deal); }
  .el-card-action svg { width: 16px; height: 16px; }
  .el-card-body { display: flex; flex-direction: column; gap: 6px; padding: 4px 14px 14px; flex: 1; }
  .el-card-title { font-size: 13.5px; font-weight: 600; line-height: 1.35; color: var(--el-ink); margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em; }
  .el-card-title a:hover { color: var(--el-accent); }
  .el-stars { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--el-muted); }
  .el-stars-icons { display: inline-flex; gap: 1px; color: var(--el-star); }
  .el-stars-icons svg { width: 12px; height: 12px; }
  .el-stars-icons .is-empty { color: rgb(var(--color-border-strong)); }
  .el-stars b { color: var(--el-ink); font-weight: 600; }
  .el-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; padding-top: 4px; }
  .el-price { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
  .el-price b { font-size: 16px; font-weight: 800; color: var(--el-ink); letter-spacing: -0.01em; }
  .el-price s { font-size: 12px; color: var(--el-muted); }
  .el-cart-btn { width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid rgb(var(--color-accent-500) / .35); background: var(--el-accent-soft); color: var(--el-accent); display: grid; place-items: center; cursor: pointer; flex-shrink: 0; transition: background .15s, color .15s, border-color .15s; }
  .el-cart-btn:hover:not(:disabled) { background: var(--el-accent); color: #fff; border-color: var(--el-accent); }
  .el-cart-btn:disabled { opacity: .45; cursor: not-allowed; }
  .el-cart-btn svg { width: 17px; height: 17px; }
  .el-cart-btn.is-pre { border-color: rgba(217,119,6,.4); background: rgba(217,119,6,.1); color: #d97706; }
  .el-cart-btn.is-pre:hover:not(:disabled) { background: #d97706; color: #fff; }
  .el-card .js-add-status { font-size: 11px; color: #16a34a; min-height: 14px; }
  .el-card-quote { margin-top: 4px; }
  .el-card-stock { font-size: 11px; color: var(--el-muted); display: inline-flex; align-items: center; gap: 5px; }

  /* ---------- Promo banners ---------- */
  .el-banners { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 768px) { .el-banners { grid-template-columns: repeat(3, 1fr); } }
  .el-banner { position: relative; display: flex; align-items: flex-end; min-height: 190px; border-radius: 16px; overflow: hidden; background: #0f172a center / cover no-repeat; color: #fff; isolation: isolate; }
  @media (min-width: 1024px) { .el-banner { min-height: 210px; } }
  .el-banner::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(5,10,25,.82) 0%, rgba(5,10,25,.45) 55%, rgba(5,10,25,.1) 100%); z-index: -1; }
  .el-banner.is-light { color: var(--el-ink); }
  .el-banner.is-light::before { background: linear-gradient(90deg, rgba(255,255,255,.92) 0%, rgba(255,255,255,.7) 55%, rgba(255,255,255,.15) 100%); }
  .el-banner-copy { padding: 22px; max-width: 68%; }
  .el-banner-kicker { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; opacity: .85; margin-bottom: 6px; }
  .el-banner-title { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; margin: 0 0 4px; }
  .el-banner-sub { font-size: 13px; opacity: .9; margin: 0 0 14px; }
  .el-banner .el-btn { height: 36px; padding: 0 14px; font-size: 13px; }
  .el-banner:hover .el-btn-primary { background: var(--el-accent-dark); }

  /* ---------- Best sellers + new arrivals ---------- */
  .el-split { display: grid; grid-template-columns: 1fr; gap: 20px; }
  @media (min-width: 1024px) { .el-split { grid-template-columns: 340px 1fr; gap: 24px; } }
  .el-rank { display: flex; flex-direction: column; }
  .el-rank-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-top: 1px solid var(--el-line); }
  .el-rank-row:first-of-type { border-top: 0; }
  .el-rank-row:hover { background: rgb(var(--color-bg-muted) / .6); }
  .el-rank-n { width: 22px; font-size: 13px; font-weight: 800; color: var(--el-accent); text-align: center; flex-shrink: 0; }
  .el-rank-img { width: 64px; height: 64px; border-radius: 10px; object-fit: cover; background: rgb(var(--color-bg-muted)); flex-shrink: 0; }
  .el-rank-body { min-width: 0; flex: 1; }
  .el-rank-name { font-size: 13px; font-weight: 600; color: var(--el-ink); line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .el-rank-name:hover { color: var(--el-accent); }
  .el-rank-price { font-size: 14px; font-weight: 800; color: var(--el-ink); margin-top: 2px; }
  .el-rank-price s { font-size: 11px; color: var(--el-muted); font-weight: 500; margin-inline-start: 6px; }

  /* ---------- Stats ---------- */
  .el-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; padding: 18px; border-radius: 18px; background: linear-gradient(135deg, var(--el-accent-soft), rgb(var(--color-accent-500) / .03)); border: 1px solid rgb(var(--color-accent-500) / .15); }
  @media (min-width: 768px) { .el-stats { grid-template-columns: repeat(3, 1fr); padding: 22px 26px; } }
  @media (min-width: 1024px) { .el-stats { grid-template-columns: repeat(var(--el-stat-cols, 5), 1fr); } }
  .el-stat { display: flex; align-items: center; gap: 12px; }
  .el-stat-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--el-card); border: 1px solid rgb(var(--color-accent-500) / .2); color: var(--el-accent); display: grid; place-items: center; flex-shrink: 0; }
  .el-stat-icon svg { width: 20px; height: 20px; }
  .el-stat strong { display: block; font-size: 18px; font-weight: 800; color: var(--el-accent); letter-spacing: -0.02em; line-height: 1.1; }
  .el-stat span { display: block; font-size: 12px; color: rgb(var(--color-fg-secondary)); margin-top: 2px; }

  /* ---------- Testimonials ---------- */
  .el-reviews { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 768px) { .el-reviews { grid-template-columns: repeat(3, 1fr); } }
  .el-review { display: flex; flex-direction: column; gap: 12px; padding: 20px; background: var(--el-card); border: 1px solid var(--el-line); border-radius: 16px; }
  .el-review p { margin: 0; font-size: 14px; line-height: 1.6; color: rgb(var(--color-fg-secondary)); flex: 1; }
  .el-review-who { display: flex; align-items: center; gap: 10px; }
  .el-avatar { width: 40px; height: 40px; border-radius: 999px; background: var(--el-accent-soft); color: var(--el-accent); font-weight: 800; display: grid; place-items: center; font-size: 14px; flex-shrink: 0; }
  .el-review-who strong { display: block; font-size: 13px; color: var(--el-ink); }
  .el-review-who small { display: block; font-size: 11px; color: var(--el-muted); }
  .el-review-product { font-size: 11px; color: var(--el-accent); font-weight: 600; }

  /* ---------- Newsletter ---------- */
  .el-newsletter { display: grid; grid-template-columns: 1fr; gap: 18px; align-items: center; padding: 26px; border-radius: 20px; background: linear-gradient(120deg, var(--el-accent-soft), rgb(var(--color-accent-500) / .02)); border: 1px solid rgb(var(--color-accent-500) / .18); position: relative; overflow: hidden; }
  @media (min-width: 1024px) { .el-newsletter { grid-template-columns: auto 1fr auto; gap: 28px; padding: 30px 36px; } }
  .el-newsletter-icon { width: 64px; height: 64px; border-radius: 18px; background: var(--el-card); color: var(--el-accent); display: grid; place-items: center; border: 1px solid rgb(var(--color-accent-500) / .2); }
  .el-newsletter-icon svg { width: 30px; height: 30px; }
  .el-newsletter h3 { margin: 0 0 4px; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; color: var(--el-ink); }
  .el-newsletter p { margin: 0; font-size: 14px; color: rgb(var(--color-fg-secondary)); }
  .el-newsletter form { display: flex; gap: 8px; width: 100%; }
  @media (min-width: 1024px) { .el-newsletter form { width: 420px; } }
  .el-newsletter input { flex: 1; min-width: 0; height: 46px; border: 1.5px solid var(--el-line); border-radius: 11px; padding: 0 14px; font-size: 14px; background: var(--el-card); color: var(--el-ink); outline: none; }
  .el-newsletter input:focus { border-color: var(--el-accent); }
  .el-newsletter-note { grid-column: 1 / -1; font-size: 11px; color: var(--el-muted); margin-top: -8px; }

  /* ---------- Brands ---------- */
  .el-brands { display: flex; flex-wrap: wrap; gap: 10px; }
  .el-brand { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 56px; padding: 0 22px; background: var(--el-card); border: 1px solid var(--el-line); border-radius: 12px; font-weight: 700; color: rgb(var(--color-fg-secondary)); font-size: 14px; flex: 1 1 140px; transition: border-color .15s, color .15s; }
  .el-brand:hover { border-color: var(--el-accent); color: var(--el-accent); }
  .el-brand img { max-height: 32px; max-width: 110px; object-fit: contain; }

  /* ---------- Footer ---------- */
  .el-footer { margin-top: 44px; background: var(--el-card); border-top: 1px solid var(--el-line); }
  .el-footer-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 28px 20px; padding: 44px 0 34px; }
  @media (min-width: 768px) { .el-footer-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (min-width: 1024px) { .el-footer-grid { grid-template-columns: 1.6fr 1fr 1fr 1fr 1fr 1.3fr; } }
  .el-footer-brand { grid-column: 1 / -1; }
  @media (min-width: 1024px) { .el-footer-brand { grid-column: auto; } }
  .el-footer-brand p { font-size: 13px; line-height: 1.6; color: rgb(var(--color-fg-secondary)); margin: 12px 0 16px; max-width: 320px; }
  .el-social { display: flex; gap: 8px; }
  .el-social a { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; background: rgb(var(--color-bg-muted)); color: var(--el-ink); transition: background .15s, color .15s; }
  .el-social a:hover { background: var(--el-accent); color: #fff; }
  .el-social svg { width: 15px; height: 15px; }
  .el-footer h6 { margin: 0 0 14px; font-size: 13px; font-weight: 700; color: var(--el-ink); }
  .el-footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
  .el-footer li a { font-size: 13px; color: rgb(var(--color-fg-secondary)); }
  .el-footer li a:hover { color: var(--el-accent); }
  .el-footer-contact li { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; color: rgb(var(--color-fg-secondary)); }
  .el-footer-contact svg { width: 15px; height: 15px; color: var(--el-accent); flex-shrink: 0; margin-top: 2px; }
  .el-footer-bottom { border-top: 1px solid var(--el-line); padding: 18px 0; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; font-size: 12px; color: var(--el-muted); }
  .el-pay { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .el-pay span { display: inline-flex; align-items: center; justify-content: center; height: 26px; min-width: 44px; padding: 0 8px; border: 1px solid var(--el-line); border-radius: 6px; background: #fff; font-size: 10px; font-weight: 800; letter-spacing: .02em; color: #1a1f71; }
  .el-pay .el-pay-mc { gap: 0; }
  .el-pay .el-pay-mc i { width: 14px; height: 14px; border-radius: 50%; display: inline-block; }
  .el-pay .el-pay-mc i:first-child { background: #eb001b; }
  .el-pay .el-pay-mc i:last-child { background: #f79e1b; margin-left: -5px; opacity: .95; }
  .el-pay .el-pay-pp { color: #003087; font-style: italic; }
  .el-pay .el-pay-ap, .el-pay .el-pay-gp { color: #111; }
  .el-pay .el-pay-gp b { color: #4285f4; }
  .el-footer-bottom-links { display: flex; align-items: center; gap: 12px; }
  .el-footer-bottom-links a, .el-footer-bottom-links button { color: var(--el-muted); background: transparent; border: 0; cursor: pointer; font-size: 12px; }
  .el-footer-bottom-links a:hover, .el-footer-bottom-links button:hover { color: var(--el-accent); }

  /* ---------- Misc ---------- */
  .el-empty { padding: 28px; text-align: center; color: var(--el-muted); font-size: 13px; }
  [x-cloak] { display: none !important; }
  @media (max-width: 1023.98px) { .el-footer { padding-bottom: 5rem; } }
</style>
