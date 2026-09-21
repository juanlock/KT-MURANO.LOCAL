{{-- Default theme polish. Embedded so it needs no Tailwind rebuild: refines
     the shared tokens/components and adds the `df-*` homepage/footer pieces. --}}
<style>
  :root {
    --df-radius: 16px;
    --df-shadow: 0 1px 2px rgba(17,24,39,.04), 0 10px 26px -14px rgba(17,24,39,.16);
    --df-shadow-lg: 0 26px 50px -20px rgba(17,24,39,.28);
  }
  html:not(.dark) {
    --color-bg-base: 250 250 252;
    --color-bg-surface: 255 255 255;
    --color-bg-muted: 243 244 248;
    --color-border-subtle: 231 233 240;
  }
  .dark { --df-shadow: none; --df-shadow-lg: 0 26px 50px -20px rgba(0,0,0,.6); }
  .btn { border-radius: 12px; }
  .btn-primary { box-shadow: 0 8px 18px -8px rgb(var(--color-accent-500) / .6); }
  .card, .product-card { border-radius: var(--df-radius); }
  .product-card { box-shadow: var(--df-shadow); border-color: rgb(var(--color-border-subtle)); }
  .product-card:hover { box-shadow: var(--df-shadow-lg); border-color: rgb(var(--color-accent-500) / .35); transform: translateY(-3px); }
  .product-media img { transition: transform .45s; }
  .product-card:hover .product-media img { transform: scale(1.04); }
  .price, .price-lg, .price-xl, .price-sm, .price-compare { font-family: 'Inter', system-ui, sans-serif; letter-spacing: -0.01em; }
  .input, .select, .textarea { border-radius: 12px; }
  .drawer-panel, .dialog-panel, .auth-panel { border-radius: 18px; }
  .newsletter-panel { border-radius: 22px; }
  .hero-title { letter-spacing: -0.03em; }
  .hero-media { border-radius: 22px; box-shadow: var(--df-shadow-lg); }
  .announce-bar { background: rgb(var(--color-accent-500) / .08); }

  /* Header: keep the category bar on one line, rest in "More" */
  .df-more { position: relative; }
  .df-more-menu { display: none; position: absolute; top: 100%; inset-inline-end: 0; min-width: 220px; padding: 6px; background: rgb(var(--color-bg-elevated)); border: 1px solid rgb(var(--color-border-subtle)); border-radius: 12px; box-shadow: var(--df-shadow-lg); z-index: 40; }
  .df-more:hover .df-more-menu, .df-more:focus-within .df-more-menu { display: block; }
  .df-more-menu a { display: block; padding: 8px 10px; border-radius: 8px; font-size: 14px; color: rgb(var(--color-fg-primary)); }
  .df-more-menu a:hover { background: rgb(var(--color-bg-muted)); color: rgb(var(--color-accent-500)); }

  /* Card extras */
  .df-stars { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: rgb(var(--color-fg-muted)); margin-top: 2px; }
  .df-stars-icons { display: inline-flex; gap: 1px; color: #f59e0b; }
  .df-stars-icons svg { width: 12px; height: 12px; }
  .df-stars-icons .is-empty { color: rgb(var(--color-border-strong)); }
  .df-stars b { color: rgb(var(--color-fg-primary)); font-weight: 600; }
  .product-badge-sale { background: #e11d48; color: #fff; }

  /* Homepage sections */
  .df-section { padding: 44px 0; }
  @media (min-width: 1024px) { .df-section { padding: 56px 0; } }
  .df-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
  .df-link { display: inline-flex; align-items: center; gap: 4px; font-size: 14px; font-weight: 600; color: rgb(var(--color-accent-500)); white-space: nowrap; }
  .df-link:hover { text-decoration: underline; }
  .df-link svg { width: 16px; height: 16px; }
  .df-hero-stats { display: flex; flex-wrap: wrap; gap: 22px; margin-top: 8px; }
  .df-hero-stat b { display: block; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; color: rgb(var(--color-fg-primary)); line-height: 1.1; }
  .df-hero-stat span { font-size: 12.5px; color: rgb(var(--color-fg-muted)); }
  .df-cats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
  @media (min-width: 640px) { .df-cats { grid-template-columns: repeat(4, 1fr); } }
  @media (min-width: 1024px) { .df-cats { grid-template-columns: repeat(var(--df-cat-cols, 6), 1fr); gap: 16px; } }
  .df-cat { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 12px 10px 14px; background: rgb(var(--color-bg-surface)); border: 1px solid rgb(var(--color-border-subtle)); border-radius: var(--df-radius); text-align: center; transition: transform .2s, box-shadow .2s, border-color .2s; }
  .df-cat:hover { transform: translateY(-3px); box-shadow: var(--df-shadow-lg); border-color: rgb(var(--color-accent-500) / .4); }
  .df-cat-media { width: 100%; aspect-ratio: 1 / 1; border-radius: 12px; overflow: hidden; background: rgb(var(--color-bg-muted)); display: grid; place-items: center; color: rgb(var(--color-accent-500)); font-weight: 800; font-size: 22px; }
  .df-cat-media img { width: 100%; height: 100%; object-fit: cover; transition: transform .35s; }
  .df-cat:hover .df-cat-media img { transform: scale(1.06); }
  .df-cat strong { font-size: 13.5px; font-weight: 600; color: rgb(var(--color-fg-primary)); line-height: 1.2; }
  .df-cat small { font-size: 11.5px; color: rgb(var(--color-fg-muted)); }
  .df-trust { display: grid; grid-template-columns: repeat(2, 1fr); background: rgb(var(--color-bg-surface)); border: 1px solid rgb(var(--color-border-subtle)); border-radius: var(--df-radius); overflow: hidden; }
  @media (min-width: 1024px) { .df-trust { grid-template-columns: repeat(4, 1fr); } }
  .df-trust-item { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-inline-end: 1px solid rgb(var(--color-border-subtle)); border-bottom: 1px solid rgb(var(--color-border-subtle)); }
  .df-trust-icon { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: rgb(var(--color-accent-500) / .1); color: rgb(var(--color-accent-500)); flex-shrink: 0; }
  .df-trust-icon svg { width: 20px; height: 20px; }
  .df-trust-item strong { display: block; font-size: 13.5px; font-weight: 700; color: rgb(var(--color-fg-primary)); }
  .df-trust-item span { display: block; font-size: 12px; color: rgb(var(--color-fg-muted)); }
  .df-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
  @media (min-width: 768px) { .df-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; } }
  @media (min-width: 1024px) { .df-grid { grid-template-columns: repeat(4, 1fr); gap: 20px; } }
  .df-reviews { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 768px) { .df-reviews { grid-template-columns: repeat(3, 1fr); } }
  .df-review { display: flex; flex-direction: column; gap: 12px; padding: 22px; background: rgb(var(--color-bg-surface)); border: 1px solid rgb(var(--color-border-subtle)); border-radius: var(--df-radius); box-shadow: var(--df-shadow); }
  .df-review p { margin: 0; font-size: 14px; line-height: 1.65; color: rgb(var(--color-fg-secondary)); flex: 1; }
  .df-review-who { display: flex; align-items: center; gap: 10px; }
  .df-avatar { width: 40px; height: 40px; border-radius: 999px; background: rgb(var(--color-accent-500) / .12); color: rgb(var(--color-accent-500)); font-weight: 800; display: grid; place-items: center; }
  .df-review-who strong { display: block; font-size: 13.5px; color: rgb(var(--color-fg-primary)); }
  .df-review-who small { display: block; font-size: 11.5px; color: rgb(var(--color-fg-muted)); }
  .df-brands { display: flex; flex-wrap: wrap; gap: 10px; }
  .df-brand { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 54px; padding: 0 20px; background: rgb(var(--color-bg-surface)); border: 1px solid rgb(var(--color-border-subtle)); border-radius: 12px; font-weight: 700; color: rgb(var(--color-fg-secondary)); font-size: 14px; flex: 1 1 130px; transition: border-color .15s, color .15s; }
  .df-brand:hover { border-color: rgb(var(--color-accent-500)); color: rgb(var(--color-accent-500)); }
  .df-brand img { max-height: 30px; max-width: 100px; object-fit: contain; }
  .df-promo { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 768px) { .df-promo { grid-template-columns: 1fr 1fr; } }
  .df-promo a { position: relative; display: flex; align-items: flex-end; min-height: 200px; border-radius: var(--df-radius); overflow: hidden; color: #fff; background: rgb(var(--color-fg-primary)) center / cover no-repeat; isolation: isolate; }
  .df-promo a::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(10,14,23,.8), rgba(10,14,23,.15)); z-index: -1; }
  .df-promo-copy { padding: 22px; }
  .df-promo-copy small { display: block; font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; opacity: .85; margin-bottom: 6px; }
  .df-promo-copy h3 { margin: 0 0 4px; font-size: 24px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; }
  .df-promo-copy p { margin: 0 0 12px; font-size: 13px; opacity: .9; }

  /* Footer extras */
  .df-foot-contact li { display: flex; align-items: flex-start; gap: 8px; }
  .df-foot-contact svg { width: 15px; height: 15px; color: rgb(var(--color-accent-500)); flex-shrink: 0; margin-top: 2px; }
  .df-pay { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .df-pay span { display: inline-flex; align-items: center; justify-content: center; height: 24px; min-width: 42px; padding: 0 7px; border: 1px solid rgb(var(--color-border-subtle)); border-radius: 6px; background: #fff; font-size: 9.5px; font-weight: 800; letter-spacing: .02em; color: #1a1f71; }
  .df-pay .df-pay-mc i { width: 13px; height: 13px; border-radius: 50%; display: inline-block; }
  .df-pay .df-pay-mc i:first-child { background: #eb001b; }
  .df-pay .df-pay-mc i:last-child { background: #f79e1b; margin-left: -5px; }
  .df-pay .df-pay-pp { color: #003087; font-style: italic; }
  .df-pay .df-pay-ap, .df-pay .df-pay-gp { color: #111; }
  .df-pay .df-pay-gp b { color: #4285f4; }
</style>
