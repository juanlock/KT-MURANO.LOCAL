{{--
  Shared look of the sign-in pages (2026-10-25, the KCOLTECH login mockup): light
  blue page with soft curves, white card, navy title, 58px fields with an icon,
  navy button. Used by passwords/email, passwords/reset and choose-login; the
  login page itself (auth/login.blade.php) carries the full version with the
  brand column. Include it inside <head>.
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --kt-primary: #0b4fa0; --kt-primary-hover: #083f82; --kt-primary-light: #dbe8f8;
    --kt-navy: #03387b; --kt-accent: #05c2de; --kt-link: #0b5cc0;
    --kt-text: #0f2747; --kt-text-2: #4a5d78; --kt-muted: #94a3b8; --kt-border: #dbe5ef;
  }
  *, *::before, *::after { box-sizing: border-box; }
  body.kt-auth {
    margin: 0; min-height: 100dvh; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--kt-text);
    background: linear-gradient(160deg, #f7fbfe 0%, #f1f8fe 40%, #e3f0fd 75%, #d4e8fc 100%) fixed;
    -webkit-font-smoothing: antialiased; overflow-x: hidden;
  }
  .kt-page { position: relative; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 3rem 1rem; isolation: isolate; overflow: hidden; }
  .kt-page::before, .kt-page::after { content: ''; position: absolute; z-index: -1; border-radius: 50%; pointer-events: none; }
  .kt-page::before { width: 140vw; height: 70vw; left: -35vw; bottom: -52vw; background: radial-gradient(closest-side, rgba(255,255,255,.85), rgba(255,255,255,0)); box-shadow: 0 -30px 80px rgba(150,196,245,.25); }
  .kt-page::after { width: 60vw; height: 60vw; right: -26vw; top: -30vw; background: radial-gradient(closest-side, rgba(150,200,248,.55), rgba(150,200,248,0)); }
  .kt-card {
    width: 100%; max-width: 500px; background: #fff; border: 1px solid rgba(219,229,239,.8); border-radius: 18px;
    padding: clamp(1.75rem, 4vw, 2.75rem) clamp(1.25rem, 4vw, 2.5rem) clamp(1.25rem, 3vw, 2rem);
    box-shadow: 0 30px 70px -25px rgba(16,64,128,.25), 0 8px 24px -12px rgba(16,64,128,.12);
    display: flex; flex-direction: column; gap: 1.35rem;
  }
  .kt-head { text-align: center; }
  .kt-mark { display: block; margin: 0 auto 1.1rem; max-height: 80px; max-width: 60%; }
  .kt-title { margin: 0; font-size: clamp(1.55rem, 2.6vw, 2rem); font-weight: 800; letter-spacing: -.025em; color: var(--kt-navy); }
  .kt-sub { margin: .5rem 0 0; color: var(--kt-text-2); font-size: 1rem; line-height: 1.5; }
  .kt-form { display: flex; flex-direction: column; gap: 1.1rem; margin: 0; }
  .kt-label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .kt-field { display: flex; align-items: center; min-height: 56px; border: 1.5px solid var(--kt-border); border-radius: 10px; background: #fff; transition: border-color .2s, box-shadow .2s; }
  .kt-field:focus-within { border-color: var(--kt-link); box-shadow: 0 0 0 3px var(--kt-primary-light); }
  .kt-field svg { flex: none; width: 21px; height: 21px; margin-inline-start: 1rem; stroke: #5b6b82; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
  .kt-field:focus-within svg { stroke: var(--kt-primary); }
  .kt-input { flex: 1; min-width: 0; border: 0; outline: none; background: transparent; padding: .8rem 1rem; font: inherit; font-size: 16px; color: var(--kt-text); }
  .kt-input::placeholder { color: var(--kt-muted); }
  .kt-btn { display: flex; align-items: center; justify-content: center; gap: .65rem; min-height: 54px; width: 100%; border: 0; border-radius: 10px; background: var(--kt-primary); color: #fff; font: inherit; font-size: 1.08rem; font-weight: 600; cursor: pointer; transition: background .2s, box-shadow .2s; }
  .kt-btn:hover { background: var(--kt-primary-hover); box-shadow: 0 4px 16px rgba(11,79,160,.35); }
  .kt-link { color: var(--kt-link); text-decoration: none; font-weight: 500; }
  .kt-link:hover { text-decoration: underline; }
  .kt-center { text-align: center; }
  .kt-alert { padding: .75rem 1rem; border-radius: 10px; font-size: .9rem; line-height: 1.5; border: 1px solid; }
  .kt-alert ul { margin: 0; padding-inline-start: 1.1rem; }
  .kt-alert.error { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.25); color: #991b1b; }
  .kt-alert.success { background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.25); color: #065f46; }
  .kt-foot { text-align: center; font-size: .85rem; color: #6b7a90; }
</style>
