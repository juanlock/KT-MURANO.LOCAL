{{-- Toys & Baby theme skin. Self-contained CSS (no Tailwind rebuild): re-tints
     the shared design tokens (soft, rounded, pastel) so every storefront page
     matches, and defines the `ty-*` components used by this theme's header,
     footer and homepage. --}}
@php
  $tyAccent = $s->primary_color ?: '#7b6fd0';
  $tyPink = $s->secondary_color ?: '#e8557a';
  $legacySecondary = ['#00c2ff', '#22d3ee', '#1d4ed8'];
  if (in_array(strtolower($tyPink), $legacySecondary, true)) { $tyPink = '#e8557a'; }
  if (in_array(strtolower($tyAccent), ['#6c5ce7', '#3b82f6', '#2563eb'], true)) { $tyAccent = '#7b6fd0'; }
  $tyHex = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '123 111 208';
      return hexdec(substr($hex,0,2)).' '.hexdec(substr($hex,2,2)).' '.hexdec(substr($hex,4,2));
  };
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Fredoka:wght@500;600;700&display=swap">
<style>
  :root {
    --color-bg-base:     255 253 250;
    --color-bg-surface:  255 255 255;
    --color-bg-elevated: 255 255 255;
    --color-bg-muted:    246 243 252;
    --color-border-subtle: 236 233 246;
    --color-border-strong: 214 209 235;
    --color-fg-primary:   45 42 74;
    --color-fg-secondary: 92 88 122;
    --color-fg-muted:     122 118 150;
    --color-accent-400: {{ $tyHex($tyAccent) }};
    --color-accent-500: {{ $tyHex($tyAccent) }};
    --color-accent-600: {{ $tyHex($tyAccent) }};
    --color-accent-glow: rgba({{ str_replace(' ', ',', $tyHex($tyAccent)) }}, 0.25);
    --color-danger: 232 85 122;
    --ty-accent: {{ $tyAccent }};
    --ty-accent-rgb: {{ str_replace(' ', ',', $tyHex($tyAccent)) }};
    --ty-pink: {{ $tyPink }};
    --ty-pink-rgb: {{ str_replace(' ', ',', $tyHex($tyPink)) }};
    --ty-yellow: #ffd66b;
    --ty-yellow-soft: #fff3cf;
    --ty-mint: #dff3ea;
    --ty-lavender: #ece9fb;
    --ty-blush: #fde4e8;
    --ty-ink: #2d2a4a;
    --ty-muted: #7a7696;
    --ty-line: #ece9f6;
    --ty-card: #ffffff;
    --ty-page: #fffdfa;
    --ty-star: #f5a623;
    --ty-radius: 22px;
    --ty-shadow: 0 2px 4px rgba(45, 42, 74, .04), 0 12px 28px -14px rgba(123, 111, 208, .25);
    --ty-shadow-lg: 0 24px 50px -18px rgba(45, 42, 74, .28);
  }
  .dark {
    --ty-ink: #ecebf7; --ty-muted: #a7a3c4; --ty-line: #2e2a4f; --ty-card: #1b1832; --ty-page: #14122a;
    --ty-lavender: #262048; --ty-blush: #3a2236; --ty-mint: #1f3a31; --ty-yellow-soft: #3d341a;
    --ty-shadow: none; --ty-shadow-lg: 0 24px 48px -16px rgba(0,0,0,.6);
  }
  html { scroll-behavior: smooth; }
  body { font-family: 'Nunito', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--ty-page); }
  .ty-display, .section-title, .hero-title, h1.ty-slide-title, .ty-h2, .ty-logo { font-family: 'Fredoka', 'Nunito', system-ui, sans-serif; }

  /* Shared components, softened for this theme */
  .btn { border-radius: 999px; font-weight: 700; }
  .btn-primary { box-shadow: 0 8px 18px -8px rgb(var(--color-accent-500) / .7); }
  .card, .product-card { border-radius: 20px; }
  .product-card { box-shadow: var(--ty-shadow); }
  .input, .select, .textarea { border-radius: 14px; }
  .drawer-panel, .dialog-panel, .auth-panel { border-radius: 22px; }
  .newsletter-panel { border-radius: 24px; }
  .price, .price-lg, .price-xl, .price-sm, .price-compare { font-family: 'Nunito', system-ui, sans-serif; }
  .chip, .badge, .product-badge { border-radius: 999px; }

  /* ---------- Layout ---------- */
  .ty-container { width: 100%; max-width: 1240px; margin: 0 auto; padding: 0 16px; }
  @media (min-width: 1024px) { .ty-container { padding: 0 24px; } }
  .ty-section { padding: 26px 0; }
  @media (min-width: 1024px) { .ty-section { padding: 34px 0; } }
  .ty-section-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
  .ty-h2 { display: inline-flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 600; color: var(--ty-ink); margin: 0; letter-spacing: -0.01em; }
  @media (min-width: 768px) { .ty-h2 { font-size: 26px; } }
  .ty-h2 svg { width: 22px; height: 22px; color: var(--ty-pink); }
  .ty-link-all { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 700; color: var(--ty-accent); white-space: nowrap; }
  .ty-link-all:hover { text-decoration: underline; }
  .ty-link-all svg { width: 16px; height: 16px; }
  .ty-muted { color: var(--ty-muted); }
  .ty-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 46px; padding: 0 24px; border-radius: 999px; font-size: 15px; font-weight: 800; border: 2px solid transparent; cursor: pointer; transition: transform .15s, background .15s, color .15s, border-color .15s, box-shadow .15s; white-space: nowrap; font-family: 'Nunito', sans-serif; }
  .ty-btn:active { transform: translateY(1px); }
  .ty-btn svg { width: 18px; height: 18px; }
  .ty-btn-primary { background: var(--ty-accent); color: #fff; box-shadow: 0 10px 22px -10px rgba(var(--ty-accent-rgb), .8); }
  .ty-btn-primary:hover { filter: brightness(1.07); color: #fff; }
  .ty-btn-outline { background: #fff; color: var(--ty-ink); border-color: var(--ty-yellow); }
  .ty-btn-outline:hover { background: var(--ty-yellow-soft); }
  .ty-btn-pink { background: var(--ty-pink); color: #fff; }
  .ty-btn-sm { height: 38px; padding: 0 16px; font-size: 13px; }

  /* ---------- Top bar ---------- */
  .ty-topbar { background: linear-gradient(90deg, var(--ty-accent), rgba(var(--ty-accent-rgb), .82)); color: #fff; font-size: 13px; }
  .ty-topbar .ty-container { display: flex; align-items: center; justify-content: space-between; gap: 12px; height: 40px; }
  .ty-topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; white-space: nowrap; overflow: hidden; }
  .ty-topbar-left svg { width: 15px; height: 15px; flex-shrink: 0; }
  .ty-coupon { display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px; border-radius: 999px; background: var(--ty-yellow); color: #4a3a00; font-weight: 800; font-size: 12px; white-space: nowrap; }
  .ty-topbar-right { display: none; align-items: center; gap: 2px; }
  @media (min-width: 768px) { .ty-topbar-right { display: flex; } }
  .ty-topbar-right a, .ty-topbar-right button { color: #fff; padding: 4px 10px; border-radius: 999px; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; background: transparent; border: 0; cursor: pointer; }
  .ty-topbar-right a:hover, .ty-topbar-right button:hover { background: rgba(255,255,255,.16); }
  .ty-topbar-right svg { width: 14px; height: 14px; }

  /* ---------- Header ---------- */
  .ty-header { position: sticky; top: 0; z-index: 40; background: var(--ty-card); box-shadow: 0 1px 0 var(--ty-line); }
  .ty-header-main { display: flex; align-items: center; gap: 12px; height: 66px; }
  @media (min-width: 1024px) { .ty-header-main { height: 84px; gap: 26px; } }
  .ty-logo { display: inline-flex; flex-direction: column; align-items: flex-start; line-height: 1; flex-shrink: 0; }
  .ty-logo-word { font-family: 'Fredoka', 'Nunito', sans-serif; font-weight: 700; font-size: 26px; letter-spacing: .04em; text-transform: uppercase; display: inline-flex; direction: ltr; unicode-bidi: isolate; }
  @media (min-width: 1024px) { .ty-logo-word { font-size: 32px; } }
  .ty-logo-word span { display: inline-block; }
  .ty-logo-tag { font-size: 11px; font-weight: 700; color: var(--ty-accent); margin-top: 3px; letter-spacing: .01em; padding-inline-start: 2px; }
  .ty-logo img { height: 40px; max-width: 170px; object-fit: contain; }
  .ty-search { display: none; flex: 1; max-width: 640px; height: 50px; border: 2px solid var(--ty-line); border-radius: 999px; background: var(--ty-card); align-items: center; padding: 0 6px 0 22px; position: relative; transition: border-color .15s, box-shadow .15s; }
  .ty-search:focus-within { border-color: rgba(var(--ty-accent-rgb), .6); box-shadow: 0 0 0 4px rgba(var(--ty-accent-rgb), .12); }
  @media (min-width: 1024px) { .ty-search { display: flex; } }
  .ty-search input { flex: 1; min-width: 0; border: 0; outline: none; background: transparent; font-size: 14.5px; color: var(--ty-ink); font-family: inherit; }
  .ty-search input::placeholder { color: var(--ty-muted); }
  .ty-search button { width: 40px; height: 40px; border-radius: 999px; border: 0; background: var(--ty-accent); color: #fff; display: grid; place-items: center; cursor: pointer; flex-shrink: 0; }
  .ty-search button svg { width: 18px; height: 18px; }
  .ty-search-results { position: absolute; top: calc(100% + 8px); left: 0; right: 0; z-index: 60; background: var(--ty-card); border: 1px solid var(--ty-line); border-radius: 18px; box-shadow: var(--ty-shadow-lg); overflow: hidden; max-height: 420px; overflow-y: auto; }
  .ty-search-results a { display: flex; align-items: center; gap: 12px; padding: 10px 14px; }
  .ty-search-results a:hover { background: rgb(var(--color-bg-muted)); }
  .ty-search-results img { width: 44px; height: 44px; border-radius: 12px; object-fit: cover; background: rgb(var(--color-bg-muted)); }
  .ty-actions { display: flex; align-items: center; gap: 4px; margin-left: auto; }
  @media (min-width: 1024px) { .ty-actions { gap: 14px; } }
  .ty-action { display: inline-flex; flex-direction: column; align-items: center; gap: 4px; padding: 4px 6px; border-radius: 14px; color: var(--ty-ink); cursor: pointer; background: transparent; border: 0; text-decoration: none; font-family: inherit; }
  .ty-action:hover { background: rgb(var(--color-bg-muted)); }
  .ty-action-icon { position: relative; width: 30px; height: 30px; display: grid; place-items: center; }
  .ty-action-icon svg { width: 24px; height: 24px; stroke-width: 1.8; }
  .ty-action-text { display: none; font-size: 12px; font-weight: 700; color: var(--ty-muted); max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  @media (min-width: 1024px) { .ty-action-text { display: block; } }
  .ty-badge { position: absolute; top: -4px; right: -6px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--ty-pink); color: #fff; font-size: 10px; font-weight: 800; display: grid; place-items: center; line-height: 1; border: 2px solid var(--ty-card); }
  .ty-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); min-width: 210px; background: var(--ty-card); border: 1px solid var(--ty-line); border-radius: 18px; box-shadow: var(--ty-shadow-lg); padding: 8px; z-index: 60; }
  .ty-menu a, .ty-menu button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border-radius: 12px; font-size: 13.5px; font-weight: 700; color: var(--ty-ink); background: transparent; border: 0; cursor: pointer; text-align: start; font-family: inherit; }
  .ty-menu a:hover, .ty-menu button:hover { background: rgb(var(--color-bg-muted)); }
  .ty-menu a svg, .ty-menu button svg { width: 16px; height: 16px; color: var(--ty-muted); }
  .ty-menu hr { border: 0; border-top: 1px solid var(--ty-line); margin: 6px 0; }
  .ty-menu .ty-danger { color: var(--ty-pink); }
  .ty-icon-btn { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: transparent; border: 0; color: var(--ty-ink); cursor: pointer; }
  .ty-icon-btn:hover { background: rgb(var(--color-bg-muted)); }

  /* Category nav */
  .ty-nav { display: none; border-top: 1px solid var(--ty-line); }
  @media (min-width: 1024px) { .ty-nav { display: block; } }
  .ty-nav ul { display: flex; align-items: center; justify-content: space-between; gap: 4px; height: 56px; margin: 0; padding: 0; list-style: none; }
  .ty-nav li { position: relative; }
  .ty-nav-link { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; font-size: 14px; font-weight: 700; color: var(--ty-ink); border-radius: 12px; white-space: nowrap; transition: color .15s, background .15s; }
  .ty-nav-link svg { width: 20px; height: 20px; stroke-width: 1.8; color: var(--ty-ink); opacity: .85; }
  .ty-nav-link:hover { color: var(--ty-accent); }
  .ty-nav-link:hover svg { color: var(--ty-accent); }
  .ty-nav-link.is-active { color: var(--ty-accent); }
  .ty-nav-link.is-active svg { color: var(--ty-accent); }
  .ty-nav-link.is-active::after { content: ''; position: absolute; left: 12px; right: 12px; bottom: 0; height: 3px; border-radius: 3px 3px 0 0; background: var(--ty-accent); }
  .ty-nav-link.ty-deal { background: var(--ty-yellow-soft); color: #8a5a00; border-radius: 999px; padding: 8px 16px; }
  .ty-nav-link.ty-deal svg { color: var(--ty-pink); fill: var(--ty-pink); }
  .ty-nav-link.ty-deal:hover { background: var(--ty-yellow); }
  .ty-mega { display: none; position: absolute; top: 100%; inset-inline-start: 0; min-width: 220px; background: var(--ty-card); border: 1px solid var(--ty-line); border-radius: 18px; box-shadow: var(--ty-shadow-lg); padding: 8px; z-index: 50; }
  .ty-nav li:hover > .ty-mega, .ty-nav li:focus-within > .ty-mega { display: block; }
  .ty-mega a { display: block; padding: 8px 12px; border-radius: 10px; font-size: 13.5px; font-weight: 600; color: var(--ty-ink); }
  .ty-mega a:hover { background: rgb(var(--color-bg-muted)); color: var(--ty-accent); }
  .ty-mobile-search { display: block; padding: 0 0 10px; }
  @media (min-width: 1024px) { .ty-mobile-search { display: none; } }
  .ty-mobile-search form { position: relative; }
  .ty-mobile-search input { width: 100%; height: 44px; border: 2px solid var(--ty-line); border-radius: 999px; padding: 0 48px 0 18px; font-size: 14px; background: var(--ty-card); color: var(--ty-ink); outline: none; font-family: inherit; }
  .ty-mobile-search button { position: absolute; inset-inline-end: 4px; top: 4px; width: 36px; height: 36px; border-radius: 999px; border: 0; background: var(--ty-accent); color: #fff; display: grid; place-items: center; }
  .ty-mobile-search button svg { width: 16px; height: 16px; }

  /* ---------- Hero ---------- */
  .ty-hero { position: relative; margin-top: 18px; border-radius: 30px; background: linear-gradient(135deg, #fde6ea 0%, #fbe0e6 55%, #f7dbe4 100%); min-height: 360px; overflow: hidden; }
  .dark .ty-hero { background: linear-gradient(135deg, #3a2236, #2b1f3f); }
  @media (min-width: 1024px) { .ty-hero { min-height: 420px; } }
  /* scalloped top & bottom edges */
  .ty-hero::before, .ty-hero::after { content: ''; position: absolute; left: 0; right: 0; height: 14px; background: radial-gradient(circle at 12px 0, transparent 11px, var(--ty-page) 12px) repeat-x; background-size: 24px 14px; z-index: 3; pointer-events: none; }
  .ty-hero::before { top: -1px; }
  .ty-hero::after { bottom: -1px; transform: scaleY(-1); }
  .ty-slide { position: absolute; inset: 0; display: grid; grid-template-columns: 1fr; align-items: center; gap: 18px; padding: 40px 24px 56px; opacity: 0; transition: opacity .6s ease; pointer-events: none; }
  .ty-slide.is-active { opacity: 1; pointer-events: auto; position: relative; }
  @media (min-width: 768px) { .ty-slide { grid-template-columns: 1fr 1.1fr; padding: 48px 48px 60px; gap: 24px; } }
  @media (min-width: 1024px) { .ty-slide { padding: 56px 64px 64px; } }
  .ty-slide-kicker { display: inline-flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; color: var(--ty-ink); margin-bottom: 8px; }
  .ty-slide-kicker svg { width: 20px; height: 20px; color: var(--ty-pink); }
  .ty-slide-title { font-size: 40px; line-height: 1.02; font-weight: 700; color: var(--ty-ink); margin: 0 0 16px; letter-spacing: -0.01em; }
  .ty-slide-title em { font-style: normal; color: var(--ty-pink); display: block; }
  @media (min-width: 768px) { .ty-slide-title { font-size: 52px; } }
  @media (min-width: 1280px) { .ty-slide-title { font-size: 62px; } }
  .ty-slide-sub { font-size: 16px; line-height: 1.55; color: rgb(var(--color-fg-secondary)); margin: 0 0 24px; max-width: 420px; font-weight: 600; }
  .ty-slide-actions { display: flex; flex-wrap: wrap; gap: 12px; }
  .ty-slide-media { position: relative; }
  .ty-slide-media img { width: 100%; height: 220px; object-fit: cover; border-radius: 26px; box-shadow: var(--ty-shadow-lg); }
  @media (min-width: 768px) { .ty-slide-media img { height: 320px; } }
  @media (min-width: 1024px) { .ty-slide-media img { height: 340px; } }
  .ty-blob { position: absolute; top: -14px; inset-inline-end: -6px; width: 116px; height: 116px; border-radius: 46% 54% 52% 48% / 55% 45% 55% 45%; background: var(--ty-accent); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; line-height: 1.05; font-weight: 800; box-shadow: 0 14px 30px -12px rgba(var(--ty-accent-rgb), .8); animation: ty-wobble 6s ease-in-out infinite; z-index: 2; }
  .ty-blob small { font-size: 12px; font-weight: 700; }
  .ty-blob b { font-size: 30px; font-family: 'Fredoka', sans-serif; font-weight: 700; }
  @keyframes ty-wobble { 0%,100% { border-radius: 46% 54% 52% 48% / 55% 45% 55% 45%; } 50% { border-radius: 54% 46% 48% 52% / 45% 55% 45% 55%; } }
  .ty-deco { position: absolute; color: var(--ty-accent); opacity: .55; pointer-events: none; }
  .ty-deco svg { width: 100%; height: 100%; }
  .ty-hero-dots { position: absolute; left: 50%; transform: translateX(-50%); bottom: 20px; display: flex; gap: 6px; z-index: 5; }
  .ty-hero-dots button { width: 8px; height: 8px; border-radius: 99px; background: rgba(var(--ty-accent-rgb), .3); border: 0; cursor: pointer; padding: 0; transition: width .2s, background .2s; }
  .ty-hero-dots button.is-active { width: 22px; background: var(--ty-accent); }

  /* ---------- Category circles ---------- */
  .ty-cats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 8px; }
  @media (min-width: 640px) { .ty-cats { grid-template-columns: repeat(5, 1fr); } }
  @media (min-width: 1024px) { .ty-cats { grid-template-columns: repeat(var(--ty-cat-cols, 9), 1fr); } }
  .ty-cat { display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center; }
  .ty-cat-circle { width: 78px; height: 78px; border-radius: 999px; display: grid; place-items: center; transition: transform .2s, box-shadow .2s; }
  @media (min-width: 1024px) { .ty-cat-circle { width: 84px; height: 84px; } }
  .ty-cat:hover .ty-cat-circle { transform: translateY(-4px) scale(1.04); box-shadow: var(--ty-shadow-lg); }
  .ty-cat-circle svg { width: 34px; height: 34px; stroke-width: 1.7; }
  .ty-cat strong { font-size: 13.5px; font-weight: 700; color: var(--ty-ink); }

  /* ---------- Promo tiles ---------- */
  .ty-tiles { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 640px) { .ty-tiles { grid-template-columns: repeat(2, 1fr); } }
  @media (min-width: 1024px) { .ty-tiles { grid-template-columns: repeat(4, 1fr); gap: 16px; } }
  .ty-tile { position: relative; display: grid; grid-template-columns: 1.1fr 1fr; align-items: center; gap: 8px; min-height: 160px; border-radius: 24px; padding: 20px 18px; overflow: hidden; transition: transform .2s, box-shadow .2s; }
  .ty-tile:hover { transform: translateY(-3px); box-shadow: var(--ty-shadow-lg); }
  .ty-tile h3 { margin: 0 0 4px; font-family: 'Fredoka', sans-serif; font-weight: 600; font-size: 20px; line-height: 1.1; }
  .ty-tile p { margin: 0 0 14px; font-size: 13.5px; font-weight: 600; color: var(--ty-ink); opacity: .8; }
  .ty-tile-cta { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; color: var(--ty-ink); }
  .ty-tile-cta svg { width: 14px; height: 14px; }
  .ty-tile img { width: 100%; height: 124px; object-fit: cover; border-radius: 18px; justify-self: end; }

  /* ---------- Product grid + card ---------- */
  .ty-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
  @media (min-width: 768px) { .ty-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; } }
  @media (min-width: 1024px) { .ty-grid { grid-template-columns: repeat(var(--ty-cols, 6), 1fr); } }
  .product-card.ty-card { position: relative; display: flex; flex-direction: column; background: var(--ty-card); border: 1px solid var(--ty-line); border-radius: 20px; box-shadow: none; overflow: hidden; transition: transform .2s, box-shadow .2s; }
  .product-card.ty-card:hover { transform: translateY(-4px); box-shadow: var(--ty-shadow-lg); }
  .ty-card-media { position: relative; display: block; aspect-ratio: 1 / 1; overflow: hidden; }
  .ty-card-media img { position: absolute; inset: 10px; width: calc(100% - 20px); height: calc(100% - 20px); object-fit: cover; border-radius: 14px; background: rgb(var(--color-bg-muted)); transition: transform .35s; }
  .product-card.ty-card:hover .ty-card-media img { transform: scale(1.04); }
  .ty-card-badges { position: absolute; top: 16px; inset-inline-start: 16px; display: flex; flex-direction: column; gap: 4px; z-index: 2; }
  .ty-pill { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 800; color: #fff; background: var(--ty-pink); }
  .ty-pill.ty-pill-new { background: #2f9e6e; }
  .ty-pill.ty-pill-out { background: #8b88a6; }
  .ty-pill.ty-pill-pre { background: #e2703a; }
  .ty-pill.ty-pill-hot { background: var(--ty-accent); }
  .ty-card-actions { position: absolute; top: 16px; inset-inline-end: 16px; display: flex; flex-direction: column; gap: 6px; z-index: 2; }
  .ty-card-action { width: 32px; height: 32px; border-radius: 999px; background: var(--ty-card); border: 1px solid var(--ty-line); color: var(--ty-ink); display: grid; place-items: center; cursor: pointer; box-shadow: var(--ty-shadow); transition: background .15s, color .15s, border-color .15s; }
  .ty-card-action:hover { background: var(--ty-pink); color: #fff; border-color: var(--ty-pink); }
  .ty-card-action[aria-pressed="true"] { background: var(--ty-pink); color: #fff; border-color: var(--ty-pink); }
  .ty-card-action svg { width: 15px; height: 15px; }
  .ty-card-action.ty-qv { opacity: 0; transform: translateX(6px); transition: opacity .2s, transform .2s; }
  .product-card.ty-card:hover .ty-card-action.ty-qv { opacity: 1; transform: none; }
  @media (hover: none) { .ty-card-action.ty-qv { opacity: 1; transform: none; } }
  .ty-card-body { display: flex; flex-direction: column; gap: 5px; padding: 2px 14px 14px; flex: 1; }
  .ty-card-title { font-size: 14px; font-weight: 700; line-height: 1.35; color: var(--ty-ink); margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em; }
  .ty-card-title a:hover { color: var(--ty-accent); }
  .ty-price { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
  .ty-price b { font-size: 17px; font-weight: 900; color: var(--ty-ink); }
  .ty-price s { font-size: 12px; color: var(--ty-muted); }
  .ty-stars { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--ty-muted); font-weight: 600; }
  .ty-stars-icons { display: inline-flex; gap: 1px; color: var(--ty-star); }
  .ty-stars-icons svg { width: 12px; height: 12px; }
  .ty-stars-icons .is-empty { color: rgb(var(--color-border-strong)); }
  .ty-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; padding-top: 4px; }
  .ty-cart-btn { width: 36px; height: 36px; border-radius: 999px; border: 0; background: var(--ty-lavender); color: var(--ty-accent); display: grid; place-items: center; cursor: pointer; flex-shrink: 0; transition: background .15s, color .15s; }
  .ty-cart-btn:hover:not(:disabled) { background: var(--ty-accent); color: #fff; }
  .ty-cart-btn:disabled { opacity: .45; cursor: not-allowed; }
  .ty-cart-btn svg { width: 17px; height: 17px; }
  .ty-cart-btn.is-pre { background: #ffe6da; color: #e2703a; }
  .ty-card .js-add-status { font-size: 11px; color: #2f9e6e; min-height: 14px; }
  .ty-card-stock { font-size: 11.5px; color: var(--ty-muted); font-weight: 600; }

  /* ---------- Trust band ---------- */
  .ty-trust { position: relative; display: grid; grid-template-columns: 1fr; gap: 14px; padding: 22px 26px; border-radius: 24px; background: var(--ty-accent); color: #fff; }
  .ty-trust::before { content: ''; position: absolute; inset: 8px; border: 2px dashed rgba(255,255,255,.45); border-radius: 18px; pointer-events: none; }
  @media (min-width: 640px) { .ty-trust { grid-template-columns: repeat(2, 1fr); } }
  @media (min-width: 1024px) { .ty-trust { grid-template-columns: repeat(var(--ty-trust-cols, 4), 1fr); padding: 26px 34px; } }
  .ty-trust-item { display: flex; align-items: center; gap: 14px; position: relative; }
  @media (min-width: 1024px) { .ty-trust-item + .ty-trust-item::before { content: ''; position: absolute; inset-inline-start: -18px; top: 6px; bottom: 6px; width: 1px; background: rgba(255,255,255,.35); } }
  .ty-trust-icon { width: 46px; height: 46px; flex-shrink: 0; display: grid; place-items: center; }
  .ty-trust-icon svg { width: 34px; height: 34px; stroke-width: 1.5; }
  .ty-trust-item strong { display: block; font-size: 15px; font-weight: 800; }
  .ty-trust-item span { display: block; font-size: 12.5px; opacity: .9; font-weight: 600; }

  /* ---------- Newsletter ---------- */
  .ty-newsletter { position: relative; display: grid; grid-template-columns: 1fr; gap: 16px; align-items: center; padding: 26px; border-radius: 26px; background: var(--ty-yellow-soft); overflow: hidden; }
  @media (min-width: 1024px) { .ty-newsletter { grid-template-columns: auto 1fr auto; gap: 26px; padding: 28px 40px; } }
  .ty-newsletter-icon { width: 76px; height: 76px; border-radius: 22px; background: var(--ty-accent); color: #fff; display: grid; place-items: center; box-shadow: var(--ty-shadow); transform: rotate(-6deg); }
  .ty-newsletter-icon svg { width: 36px; height: 36px; }
  .ty-newsletter h3 { margin: 0 0 4px; font-family: 'Fredoka', sans-serif; font-weight: 600; font-size: 22px; color: var(--ty-ink); }
  .ty-newsletter p { margin: 0; font-size: 14px; font-weight: 600; color: rgb(var(--color-fg-secondary)); max-width: 420px; }
  .ty-newsletter form { display: flex; gap: 8px; width: 100%; }
  @media (min-width: 1024px) { .ty-newsletter form { width: 400px; } }
  .ty-newsletter input { flex: 1; min-width: 0; height: 48px; border: 2px solid transparent; border-radius: 999px; padding: 0 18px; font-size: 14px; background: var(--ty-card); color: var(--ty-ink); outline: none; font-family: inherit; }
  .ty-newsletter input:focus { border-color: rgba(var(--ty-accent-rgb), .5); }
  .ty-newsletter-note { grid-column: 1 / -1; font-size: 11.5px; color: var(--ty-muted); margin-top: -8px; font-weight: 600; }
  .ty-newsletter .ty-deco { opacity: .7; }

  /* ---------- Testimonials ---------- */
  .ty-reviews { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 768px) { .ty-reviews { grid-template-columns: repeat(3, 1fr); } }
  .ty-review { display: flex; flex-direction: column; gap: 10px; padding: 20px; background: var(--ty-card); border: 1px solid var(--ty-line); border-radius: 22px; }
  .ty-review p { margin: 0; font-size: 14px; line-height: 1.6; color: rgb(var(--color-fg-secondary)); font-weight: 600; flex: 1; }
  .ty-avatar { width: 40px; height: 40px; border-radius: 999px; background: var(--ty-lavender); color: var(--ty-accent); font-weight: 900; display: grid; place-items: center; }
  .ty-review-who { display: flex; align-items: center; gap: 10px; }
  .ty-review-who strong { display: block; font-size: 13.5px; color: var(--ty-ink); }
  .ty-review-who small { display: block; font-size: 11.5px; color: var(--ty-muted); }

  /* ---------- Footer ---------- */
  .ty-footer { position: relative; margin-top: 46px; background: var(--ty-accent); color: #fff; }
  .ty-footer-wave { display: block; width: 100%; height: 36px; background: var(--ty-page); }
  .ty-footer-wave svg { display: block; width: 100%; height: 36px; }
  .ty-footer-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 28px 20px; padding: 26px 0 30px; }
  @media (min-width: 768px) { .ty-footer-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (min-width: 1024px) { .ty-footer-grid { grid-template-columns: 1.5fr 1fr 1fr 1fr 1.2fr; gap: 24px; } }
  .ty-footer-brand { grid-column: 1 / -1; }
  @media (min-width: 1024px) { .ty-footer-brand { grid-column: auto; } }
  .ty-footer .ty-logo-word span { color: #fff !important; }
  .ty-footer .ty-logo-word span:nth-child(odd) { color: var(--ty-yellow) !important; }
  .ty-footer .ty-logo-tag { color: rgba(255,255,255,.85); }
  .ty-footer-brand p { font-size: 13.5px; line-height: 1.6; opacity: .92; margin: 12px 0 16px; max-width: 300px; font-weight: 600; }
  .ty-social { display: flex; gap: 8px; }
  .ty-social a { width: 34px; height: 34px; border-radius: 999px; display: grid; place-items: center; background: rgba(255,255,255,.16); color: #fff; transition: background .15s; }
  .ty-social a:hover { background: rgba(255,255,255,.3); }
  .ty-social svg { width: 15px; height: 15px; }
  .ty-footer h6 { margin: 0 0 14px; font-size: 15px; font-weight: 800; color: #fff; }
  .ty-footer ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
  .ty-footer li a { font-size: 13.5px; color: rgba(255,255,255,.88); font-weight: 600; }
  .ty-footer li a:hover { color: #fff; text-decoration: underline; }
  .ty-pay { display: flex; flex-wrap: wrap; gap: 8px; max-width: 220px; }
  .ty-pay span { display: inline-flex; align-items: center; justify-content: center; height: 30px; min-width: 52px; padding: 0 9px; border-radius: 8px; background: #fff; font-size: 10.5px; font-weight: 900; letter-spacing: .02em; color: #1a1f71; }
  .ty-pay .ty-pay-mc i { width: 15px; height: 15px; border-radius: 50%; display: inline-block; }
  .ty-pay .ty-pay-mc i:first-child { background: #eb001b; }
  .ty-pay .ty-pay-mc i:last-child { background: #f79e1b; margin-left: -5px; }
  .ty-pay .ty-pay-amex { background: #2e77bc; color: #fff; }
  .ty-pay .ty-pay-pp { color: #003087; font-style: italic; }
  .ty-pay .ty-pay-ap { background: #111; color: #fff; }
  .ty-pay .ty-pay-gp { color: #111; }
  .ty-pay .ty-pay-gp b { color: #4285f4; }
  .ty-pay .ty-pay-shop { background: #5a31f4; color: #fff; }
  .ty-footer-bottom { border-top: 1px solid rgba(255,255,255,.25); padding: 16px 0; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; font-size: 12.5px; opacity: .92; font-weight: 600; }
  .ty-footer-bottom svg { width: 14px; height: 14px; vertical-align: -2px; color: var(--ty-yellow); }
  .ty-footer-bottom > span { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }

  .ty-empty { padding: 28px; text-align: center; color: var(--ty-muted); font-size: 13px; }
  [x-cloak] { display: none !important; }
  @media (max-width: 1023.98px) { .ty-footer { padding-bottom: 5rem; } }
</style>
