<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_dir() }}">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @php
        // Same dual-format handling as the logo below: a bare legacy filename
        // ("favicon.ico") lives under the shared images/ root; a full relative
        // path (uploaded via Login Page Settings, e.g. "images/loginpage/xxx.ico")
        // already includes it.
        $loginFavicon = $app_settings->favicon ?? 'favicon.ico';
        $loginFaviconUrl = str_contains($loginFavicon, '/') ? asset($loginFavicon) : asset('images/'.$loginFavicon);
    @endphp
    <link rel="icon" href="{{ $loginFaviconUrl }}">

    {{-- PWA --}}
    @include('partials.pwa-head')

    <title>{{ $app_settings->app_name ?? 'KcolTech - Murano | Ultimate Inventory With POS' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
      /* Login design (2026-10-25, user's mockup KT-TOOLS/files/login/KCOLTECH SaaS
         Dashboard Login Showcase.png): light blue page, brand + headline + features +
         laptop scene on the left, white sign-in card on the right. Colors sampled
         from the mockup; pictures in public/images/login/. */
      :root {
        --surface: #ffffff;
        --primary: #0b4fa0;
        --primary-hover: #083f82;
        --primary-light: #dbe8f8;
        --primary-glow: rgba(11, 79, 160, 0.35);
        --navy: #03387b;
        --accent: #05c2de;
        --link: #0b5cc0;
        --text: #0f2747;
        --text-secondary: #4a5d78;
        --text-muted: #94a3b8;
        --border: #dbe5ef;
        --border-focus: #0b5cc0;
        --bg-input: #ffffff;
        --bg-input-focus: #ffffff;
        --danger: #ef4444;
        --danger-soft: rgba(239, 68, 68, 0.08);
        --danger-border: rgba(239, 68, 68, 0.25);
        --success: #10b981;
        --success-soft: rgba(16, 185, 129, 0.08);
        --success-border: rgba(16, 185, 129, 0.25);
        --radius-sm: 10px;
        --radius-md: 14px;
        --radius-lg: 24px;
        --radius-full: 999px;
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
        --shadow-lg: 0 20px 50px -12px rgba(0,0,0,0.12);
        --shadow-xl: 0 25px 60px -15px rgba(0,0,0,0.15);
        --transition: 200ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

      body {
        font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        color: var(--text);
        background: linear-gradient(160deg, #f7fbfe 0%, #f1f8fe 40%, #e3f0fd 75%, #d4e8fc 100%);
        background-attachment: fixed;
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
      }

      /* ─── LAYOUT: brand on the left, sign-in card on the right ─── */
      .auth-page {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(380px, 560px);
        align-items: center;
        gap: clamp(1.5rem, 3vw, 3rem);
        min-height: 100dvh;
        padding: clamp(1.5rem, 4vw, 3.5rem) clamp(1.5rem, 5vw, 5.5rem);
        overflow: hidden;
        isolation: isolate;
      }
      /* Soft curved light bands of the mockup (bottom-left white, top-right blue). */
      .auth-page::before,
      .auth-page::after {
        content: '';
        position: absolute;
        z-index: -1;
        border-radius: 50%;
        pointer-events: none;
      }
      .auth-page::before {
        width: 140vw; height: 70vw;
        left: -35vw; bottom: -52vw;
        background: radial-gradient(closest-side, rgba(255,255,255,0.85), rgba(255,255,255,0) 100%);
        box-shadow: 0 -30px 80px rgba(150, 196, 245, 0.25);
      }
      .auth-page::after {
        width: 60vw; height: 60vw;
        right: -26vw; top: -30vw;
        background: radial-gradient(closest-side, rgba(150, 200, 248, 0.55), rgba(150, 200, 248, 0) 100%);
      }

      /* ─── HERO (left) ─── */
      .auth-hero {
        position: relative;
        align-self: stretch;
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-width: 0;
      }

      .hero-content {
        position: relative;
        z-index: 1;
        max-width: 560px;
      }

      .hero-brand img {
        display: block;
        width: min(100%, 500px);
        height: auto;
        margin-bottom: clamp(1.75rem, 4vh, 3rem);
      }

      .hero-title {
        font-size: clamp(2rem, 3.3vw, 3.5rem);
        font-weight: 800;
        line-height: 1.08;
        letter-spacing: -0.035em;
        color: var(--navy);
        margin-bottom: 1.4rem;
      }
      .hero-title .accent { color: var(--accent); }

      .hero-subtitle {
        font-size: clamp(0.98rem, 1.15vw, 1.12rem);
        color: var(--text-secondary);
        line-height: 1.6;
        max-width: 460px;
      }

      /* Three features in columns with thin dividers, icon above the text. */
      .hero-features {
        display: flex;
        align-items: flex-start;
        margin-top: clamp(1.5rem, 4vh, 2.5rem);
      }
      .hero-feature {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 0.55rem;
        width: 8.5rem;
        padding: 0 0.75rem;
        font-size: 0.95rem;
        line-height: 1.25;
        color: var(--text);
      }
      .hero-feature:first-child { padding-inline-start: 0; width: 7.75rem; }
      .hero-feature + .hero-feature { border-inline-start: 1px solid #c9d8ea; }
      .hero-feature-icon svg {
        width: 38px; height: 38px;
        stroke: #2c66a8;
        fill: none;
        stroke-width: 1.5;
        stroke-linecap: round;
        stroke-linejoin: round;
      }

      /* Laptop scene between the text and the card; its edges fade into the page. */
      .hero-scene {
        position: absolute;
        z-index: 0;
        right: max(-4vw, -60px);
        bottom: 4%;
        width: min(46vw, 700px);
        pointer-events: none;
        -webkit-mask-image: radial-gradient(ellipse 72% 70% at 55% 52%, #000 62%, transparent 100%);
        mask-image: radial-gradient(ellipse 72% 70% at 55% 52%, #000 62%, transparent 100%);
      }
      .hero-scene img { display: block; width: 100%; height: auto; mix-blend-mode: multiply; }

      /* ─── FORM PANEL (right) ─── */
      .auth-panel {
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        justify-content: center;
      }

      /* Guest language switcher -- picks the locale BEFORE signing in (the
         page otherwise has no way to know which company/user is about to
         log in, so it can't infer a language any other way). */
      .auth-lang-switch {
        position: fixed;
        z-index: 5;
        top: 1.1rem;
        inset-inline-end: 1.5rem;
        display: flex;
        gap: 0.5rem;
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        padding: 0.25rem;
        border-radius: 10px;
      }
      .auth-lang-switch a {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-muted, #6b7280);
        text-decoration: none;
        padding: 0.3rem 0.6rem;
        border-radius: 8px;
        border: 1px solid transparent;
      }
      .auth-lang-switch a img {
        width: 22px;
        height: 16px;
        border-radius: 3px;
        box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.12);
        object-fit: cover;
      }
      .auth-lang-switch a:hover { background: rgba(0, 0, 0, 0.05); }
      .auth-lang-switch a.active {
        color: var(--primary);
        background: rgba(11, 79, 160, 0.08);
        border-color: rgba(11, 79, 160, 0.25);
      }
      /* Phones: the switcher sits above the card (it used to overlap the card's top). */
      @media (max-width: 720px) {
        .auth-panel { flex-direction: column; justify-content: flex-start; }
        .auth-lang-switch {
          position: static;
          align-self: flex-end;
          margin-bottom: 0.75rem;
        }
      }

      .auth-card {
        background: var(--surface);
        border: 1px solid rgba(219, 229, 239, 0.8);
        border-radius: 18px;
        width: 100%;
        max-width: 545px;
        margin-inline-start: auto;
        padding: clamp(2rem, 3.6vw, 3.5rem) clamp(1.75rem, 3vw, 3rem) clamp(1.5rem, 2.6vw, 2.5rem);
        box-shadow: 0 30px 70px -25px rgba(16, 64, 128, 0.25), 0 8px 24px -12px rgba(16, 64, 128, 0.12);
        display: flex;
        flex-direction: column;
        gap: 1.6rem;
      }

      .auth-card-header {
        text-align: center;
      }

      .auth-logo {
        display: flex;
        justify-content: center;
        margin-bottom: 1.4rem;
      }

      .auth-logo img {
        max-height: 96px;
        max-width: 70%;
        object-fit: contain;
      }

      .auth-card-title {
        font-size: clamp(1.75rem, 2.3vw, 2.3rem);
        font-weight: 800;
        letter-spacing: -0.025em;
        color: var(--navy);
      }

      .auth-card-subtitle {
        font-size: 1.05rem;
        color: var(--text-secondary);
        margin-top: 0.6rem;
        line-height: 1.5;
      }

      /* ─── FORM ─── */
      .auth-form { display: flex; flex-direction: column; gap: 1.3rem; }

      .form-group { display: flex; flex-direction: column; gap: 0.5rem; }

      /* The mockup has no labels above the fields (icon + placeholder): kept for screen readers only. */
      .form-label {
        position: absolute;
        width: 1px; height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
      }

      .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
        min-height: 58px;
        border: 1.5px solid var(--border);
        border-radius: 10px;
        background: var(--bg-input);
        transition: border-color var(--transition), background var(--transition), box-shadow var(--transition);
      }

      .input-wrapper:focus-within {
        border-color: var(--border-focus);
        background: var(--bg-input-focus);
        box-shadow: 0 0 0 3px var(--primary-light);
      }

      .input-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        padding-inline-start: 1.1rem;
        color: #5b6b82;
        transition: color var(--transition);
        flex-shrink: 0;
      }

      .input-wrapper:focus-within .input-icon {
        color: var(--primary);
      }

      .input-icon svg {
        width: 22px; height: 22px;
        stroke: currentColor;
        fill: none;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
      }

      .form-input {
        flex: 1;
        border: none;
        background: transparent;
        padding: 0.8rem 1rem;
        font-size: 1.05rem;
        font-family: inherit;
        color: var(--text);
        outline: none;
        min-width: 0;
      }

      .form-input::placeholder {
        color: var(--text-muted);
      }

      .toggle-password {
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        background: none;
        padding: 0 0.75rem;
        cursor: pointer;
        color: var(--text-muted);
        transition: color var(--transition);
      }

      .toggle-password:hover {
        color: var(--primary);
      }

      .toggle-password svg {
        width: 22px; height: 22px;
        stroke: currentColor;
        fill: none;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
      }

      .form-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
      }

      .remember-check {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        cursor: pointer;
        font-size: 0.98rem;
        color: var(--text-secondary);
        user-select: none;
      }

      .remember-check input[type="checkbox"] {
        width: 20px; height: 20px;
        accent-color: var(--primary);
        border-radius: 4px;
        cursor: pointer;
      }

      .forgot-link {
        font-size: 0.98rem;
        font-weight: 500;
        color: var(--link);
        text-decoration: none;
        transition: color var(--transition);
      }

      .forgot-link:hover { color: var(--primary-hover); }

      /* ─── BUTTON ─── */
      .auth-btn {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        min-height: 57px;
        padding: 0.85rem 1.5rem;
        border: none;
        border-radius: 10px;
        background: var(--primary);
        color: #fff;
        font-size: 1.15rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background var(--transition), box-shadow var(--transition), transform 100ms ease;
        overflow: hidden;
        margin-top: 0.25rem;
      }

      .auth-btn:hover {
        background: var(--primary-hover);
        box-shadow: 0 4px 16px var(--primary-glow);
      }

      .auth-btn:active {
        transform: scale(0.985);
      }

      .auth-btn:disabled {
        cursor: not-allowed;
        opacity: 0.8;
      }

      .auth-btn .btn-arrow {
        transition: transform var(--transition);
      }

      .auth-btn:hover .btn-arrow {
        transform: translateX(3px);
      }

      .btn-loading {
        display: none;
        align-items: center;
        gap: 0.5rem;
      }

      .spinner {
        width: 18px; height: 18px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
      }

      @keyframes spin { to { transform: rotate(360deg); } }

      /* ─── DIVIDER ─── */
      .auth-divider {
        display: flex;
        align-items: center;
        gap: 1rem;
        color: var(--text-muted);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .auth-divider::before,
      .auth-divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--border);
      }

      /* ─── ALERTS ─── */
      .auth-alert {
        padding: 0.75rem 1rem;
        border-radius: var(--radius-sm);
        border: 1px solid var(--border);
        font-size: 0.875rem;
        line-height: 1.5;
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
      }

      .auth-alert ul {
        margin: 0;
        padding-inline-start: 1rem;
        list-style: none;
      }

      .auth-alert ul li::before {
        content: '\2022';
        color: currentColor;
        opacity: 0.5;
        margin-inline-end: 0.4rem;
      }

      .auth-alert.error {
        background: var(--danger-soft);
        border-color: var(--danger-border);
        color: #991b1b;
      }

      .auth-alert.success {
        background: var(--success-soft);
        border-color: var(--success-border);
        color: #065f46;
      }

      .alert-icon {
        flex-shrink: 0;
        margin-top: 1px;
      }

      .alert-icon svg {
        width: 18px; height: 18px;
        stroke: currentColor;
        fill: none;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
      }

      /* ─── FOOTER ─── */
      .auth-footer {
        text-align: center;
        font-size: 0.85rem;
        color: #6b7a90;
        padding-top: 0.25rem;
      }

      /* ─── RESPONSIVE ─── */
      /* Narrower screens: the laptop scene goes first (it would cover the text). */
      @media (max-width: 1500px), (max-height: 860px) {
        .hero-scene { display: none; }
      }
      /* Tablets / phones: only the card, centered. */
      @media (max-width: 1024px) {
        .auth-page { grid-template-columns: 1fr; padding: 4rem 1.25rem 1.5rem; }
        .auth-hero { display: none; }
        .auth-card { margin-inline: auto; }
      }

      @media (max-width: 640px) {
        .auth-page {
          padding: 4rem 1rem 1.25rem;
          padding-bottom: max(1.25rem, env(safe-area-inset-bottom));
          align-items: flex-start;
        }
        .auth-card {
          padding: 1.75rem 1.25rem 1.25rem;
          border-radius: 16px;
          gap: 1.25rem;
        }
        .auth-logo { margin-bottom: 1rem; }
        .auth-logo img { max-height: 64px; }
        .auth-card-title { font-size: 1.55rem; }
        .auth-card-subtitle { font-size: 0.92rem; }
        .auth-form { gap: 1rem; }
        /* iOS: 16px+ prevents auto-zoom on focus */
        .input-wrapper { min-height: 50px; }
        .form-input { font-size: 16px; padding: 0.75rem 0.75rem; }
        .input-icon { padding-inline-start: 0.85rem; }
        .input-icon svg { width: 19px; height: 19px; }
        .toggle-password { padding: 0 0.625rem; }
        .auth-btn {
          padding: 0.95rem 1.25rem;
          font-size: 1rem;
          min-height: 48px;
        }
        .form-row {
          flex-wrap: wrap;
          gap: 0.5rem 1rem;
        }
        .remember-check, .forgot-link { font-size: 0.85rem; }
      }

      @media (max-width: 380px) {
        .auth-panel { padding: 1rem 0.75rem; }
        .auth-card {
          padding: 1.25rem 1rem;
          border-radius: 16px;
          gap: 1rem;
        }
        .auth-card-title { font-size: 1.15rem; }
        .form-row {
          flex-direction: column;
          align-items: flex-start;
          gap: 0.625rem;
        }
      }

      @media (max-height: 560px) and (orientation: landscape) {
        .auth-panel { padding: 1rem; align-items: flex-start; }
        .auth-card {
          padding: 1.25rem;
          gap: 1rem;
          margin: 0.5rem 0;
        }
        .auth-logo { margin-bottom: 0.5rem; }
        .auth-logo img { max-height: 36px; }
      }

      /* ─── RTL (Arabic) ───────────────────────────────────────────────
         <html dir> is set from the active locale, so the grid columns, the
         flex rows (input icon / toggle button) and the text alignment all
         mirror themselves. What can't mirror on its own is listed here: the
         arrow glyph and the transform that nudges it. Padding/margins use
         logical properties above, so they need nothing. */

      /* Inter carries no Arabic glyphs — name the fallbacks instead of
         leaving the pick to the browser's default serif-ish Arabic face. */
      [dir="rtl"] body {
        font-family: 'Segoe UI', Tahoma, 'Geeza Pro', 'Noto Sans Arabic', 'Arabic Typesetting', system-ui, sans-serif;
      }

      /* Arrows point at the direction of travel — flip them, and flip the
         nudge with them (scaleX comes last, so the translate is mirrored). */
      [dir="rtl"] .auth-btn .btn-arrow { transform: scaleX(-1); }
      [dir="rtl"] .auth-btn:hover .btn-arrow { transform: scaleX(-1) translateX(3px); }
    </style>

    @if (!empty($app_settings->login_bg_image))
    <style>
      /* Admin-configured login background image (Settings → Login Page Settings)
         -- takes priority over the solid color below, mutually exclusive
         (same rule the settings page itself enforces on save). */
      body {
        background-image: url({{ str_contains($app_settings->login_bg_image, '/') ? asset($app_settings->login_bg_image) : asset('images/'.$app_settings->login_bg_image) }});
        background-size: cover;
        background-position: center;
      }
    </style>
    @endif
    {{-- The background COLOR setting (made for the old dark panel) is not applied by this
         light design (2026-10-25): a dark color would hide the navy headline. --}}
  </head>

  <body>
    <div class="auth-page">
      <!-- HERO -->
      <section class="auth-hero">
        <div class="hero-content">
          <div class="hero-brand">
            <img src="{{ asset('images/login/kcoltech-logo.png') }}" alt="KCOLTECH - IT Advisory, Development, Security" width="505" height="102">
          </div>

          @php
            // The headline's last word in the accent color ("Manage your business smarter.").
            $heroTitle = (string) login_locale_text($app_settings->login_hero_title);
            $heroSplit = preg_match('/^(.*\s)(\S+)$/su', trim($heroTitle), $hm) ? [$hm[1], $hm[2]] : ['', $heroTitle];
          @endphp
          <h1 class="hero-title">{{ $heroSplit[0] }}<span class="accent">{{ $heroSplit[1] }}</span></h1>
          <p class="hero-subtitle">
            {{ login_locale_text($app_settings->login_hero_subtitle) }}
          </p>

          <div class="hero-features">
            @php
              // Login Page Settings' text fields are per-locale JSON maps
              // (2026_09_24_160000) -- login_locale_text() picks the current
              // visitor's language, falling back to English. A SuperAdmin can
              // still blank a feature (for the CURRENT locale) to show fewer
              // than 3 (the @if below), same as always.
              $features = [
                login_locale_text($app_settings->login_hero_feature_1),
                login_locale_text($app_settings->login_hero_feature_2),
                login_locale_text($app_settings->login_hero_feature_3),
              ];
            @endphp
            @php
              // One icon per position (mockup): box = inventory, pin = locations / POS, bars = reports.
              $featureIcons = [
                '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
                '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
                '<line x1="4" y1="21" x2="20" y2="21"/><rect x="5" y="11" width="3" height="10" rx="1"/><rect x="10.5" y="4" width="3" height="17" rx="1"/><rect x="16" y="8" width="3" height="13" rx="1"/>',
              ];
            @endphp
            @foreach ($features as $i => $feature)
              @if ($feature)
              <div class="hero-feature">
                <span class="hero-feature-icon">
                  <svg viewBox="0 0 24 24">{!! $featureIcons[$i] ?? $featureIcons[0] !!}</svg>
                </span>
                {{ $feature }}
              </div>
              @endif
            @endforeach
          </div>
        </div>
        <div class="hero-scene" aria-hidden="true">
          <img src="{{ asset('images/login/login-scene.jpg') }}" alt="" width="694" height="410">
        </div>
      </section>

      <!-- FORM -->
      <section class="auth-panel">
        {{-- Sign-in languages: English and Spanish only, with flags (the app may support more). --}}
        <div class="auth-lang-switch">
          @foreach (['en' => ['us.svg', 'EN', 'English'], 'es' => ['es.svg', 'ES', 'Español']] as $code => [$flag, $short, $label])
            @continue(! array_key_exists($code, \App\Http\Middleware\SetLocale::SUPPORTED))
            <a
              href="{{ route('locale.guest', ['locale' => $code, 'redirect' => url()->full()]) }}"
              class="{{ app()->getLocale() === $code ? 'active' : '' }}"
              title="{{ $label }}" lang="{{ $code }}"
            ><img src="{{ asset('images/vendor/flag-icon-css/flags/4x3/'.$flag) }}" alt="">{{ $short }}</a>
          @endforeach
        </div>
        <div class="auth-card">
          <header class="auth-card-header">
            @php
                // Bare filename (legacy default, e.g. "logo.png") lives under
                // the shared images/ root; a full relative path (uploaded via
                // Login Page Settings, e.g. "images/loginpage/xxx.png")
                // already includes it -- same dual-format handling as every
                // other company-owned image path in this app.
                $loginLogo = $app_settings->logo ?? 'logo.png';
                $loginLogoUrl = str_contains($loginLogo, '/') ? asset($loginLogo) : asset('images/'.$loginLogo);
                // The stock product logo (never customized) -> the KCOLTECH mark of the login design.
                if (str_contains($loginLogo, 'logo-default') || $loginLogo === 'logo.png') {
                    $loginLogoUrl = asset('images/login/kcoltech-mark.png');
                }
            @endphp
            <div class="auth-logo">
              <img src="{{ $loginLogoUrl }}" alt="{{ $app_settings->app_name ?? 'KcolTech - Murano' }}">
            </div>
            <h2 class="auth-card-title">{{ login_locale_text($app_settings->login_panel_title) }}</h2>
            <p class="auth-card-subtitle">
              {{ login_locale_text($app_settings->login_panel_subtitle) }}
            </p>
          </header>

          @if (session('status'))
          <div class="auth-alert success">
            <span class="alert-icon">
              <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <span>{{ session('status') }}</span>
          </div>
          @endif

          @if ($errors->any())
          <div class="auth-alert error">
            <span class="alert-icon">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </span>
            <div>
              <ul>
                @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          </div>
          @endif

          {{-- Signed out by the server: disabled login, or not on an allowed network (Network security). --}}
          @if (session('erro_login') && ! $errors->any())
          <div class="auth-alert error">
            <span class="alert-icon">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </span>
            <div>{{ session('erro_login') }}</div>
          </div>
          @endif

          <form id="login_form" method="POST" action="{{ route('login') }}" class="auth-form">
            @csrf

            <div class="form-group">
              <label class="form-label" for="email">{{ tdb('Email_Address', 'Email address') }}</label>
              <div class="input-wrapper">
                <span class="input-icon">
                  <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </span>
                <input id="email" class="form-input" type="email" name="email" value="{{ old('email') }}" placeholder="{{ tdb('Email_placeholder', 'you@company.com') }}" required autofocus />
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="password">{{ tdb('Password', 'Password') }}</label>
              <div class="input-wrapper">
                <span class="input-icon">
                  <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <input id="password" class="form-input" type="password" name="password" placeholder="{{ tdb('Password_placeholder', 'Enter your password') }}" required />
                <button type="button" class="toggle-password" data-target="password" aria-label="{{ tdb('Toggle_password_visibility', 'Toggle password visibility') }}">
                  <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="eye-closed" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
            </div>

            <div class="form-row">
              <label class="remember-check">
                <input type="checkbox" name="remember">
                {{ tdb('Remember_me', 'Remember me') }}
              </label>
              <a class="forgot-link" href="{{ route('password.request') }}">{{ tdb('Forgot_Password', 'Forgot password?') }}</a>
            </div>

            <button type="submit" class="auth-btn" id="login_submit_btn">
              <span class="btn-text">{{ login_locale_text($app_settings->login_btn_text) }}</span>
              <svg class="btn-arrow btn-text" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              <span class="btn-loading"><span class="spinner"></span> {{ tdb('Signing_in', 'Signing in...') }}</span>
            </button>
          </form>

          <div class="auth-footer">
            {{ login_locale_text($app_settings->login_footer_text) ?: '© ' . date('Y') . ' ' . ($app_settings->app_name ?? 'KcolTech - Murano') . '. ' . tdb('All_rights_reserved', 'All rights reserved') . '.' }}
          </div>
        </div>
      </section>
    </div>

    <script>
      // A new sign-in gets its start page again (lib/startPage.js).
      try { sessionStorage.removeItem('start_page_opened'); } catch (e) {}
      // A previous user's "close your register before signing out" note must
      // never carry over to whoever signs in next in this tab.
      try { sessionStorage.removeItem('pos_logout_closeout'); } catch (e) {}
      (function() {
        const form = document.getElementById('login_form');
        const submitBtn = document.getElementById('login_submit_btn');

        document.querySelectorAll('.toggle-password').forEach(function(btn) {
          btn.addEventListener('click', function() {
            var target = document.getElementById(btn.dataset.target);
            var isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            btn.querySelector('.eye-open').style.display = isHidden ? 'none' : 'block';
            btn.querySelector('.eye-closed').style.display = isHidden ? 'block' : 'none';
          });
        });

        if (!form || !submitBtn) return;

        function setBusy() {
          submitBtn.disabled = true;
          submitBtn.setAttribute('aria-busy', 'true');
          submitBtn.querySelectorAll('.btn-text').forEach(function(el) { el.style.display = 'none'; });
          submitBtn.querySelector('.btn-loading').style.display = 'inline-flex';
        }

        function clearBusy() {
          submitBtn.disabled = false;
          submitBtn.removeAttribute('aria-busy');
          submitBtn.querySelectorAll('.btn-text').forEach(function(el) { el.style.display = ''; });
          submitBtn.querySelector('.btn-loading').style.display = 'none';
        }

        var submitted = false;
        form.addEventListener('submit', function(e) {
          if (submitted) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
          }

          // Always refresh the CSRF token right before submit. This protects
          // against stale tokens from cached HTML (bfcache, browser cache,
          // service-worker shell) which were causing 419 Page Expired.
          e.preventDefault();
          submitted = true;
          setBusy();

          fetch('/csrf-token', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
          })
          .then(function(r) { return r.ok ? r.json() : null; })
          .then(function(data) {
            if (data && data.token) {
              var input = form.querySelector('input[name="_token"]');
              if (input) input.value = data.token;
              var meta = document.querySelector('meta[name="csrf-token"]');
              if (meta) meta.setAttribute('content', data.token);
            }
          })
          .catch(function() {})
          .finally(function() {
            form.submit();
          });
        });

        submitBtn.addEventListener('click', function(e) {
          if (submitted) {
            e.preventDefault();
            e.stopImmediatePropagation();
          }
        });

        // If the browser restores this page from bfcache (back/forward), the
        // CSRF token inside the form may be stale. Reset UI so the submit
        // handler runs again and refreshes the token.
        window.addEventListener('pageshow', function(ev) {
          if (ev.persisted) {
            submitted = false;
            clearBusy();
          }
        });

        // Self-heal: drop only service workers that are NOT the one this
        // install currently ships. /login, /logout and /password are
        // network-only inside public/sw.js, so the current worker can no
        // longer hold a stale login shell (the 419-on-submit bug this block
        // was written for). Unregistering it here anyway meant every single
        // login tore down the worker and wiped the POS offline shell, then
        // the app shell re-installed and claimed the page mid-boot — which
        // is exactly when a lazily-loaded /next/* route chunk goes missing.
        try {
          if ('serviceWorker' in navigator && navigator.serviceWorker.getRegistrations) {
            var currentSW = new URL(@json(store_sw_url()), location.origin).href;
            navigator.serviceWorker.getRegistrations().then(function(regs) {
              regs.forEach(function(reg) {
                var script = (reg.active || reg.waiting || reg.installing || {}).scriptURL;
                if (script && script !== currentSW) reg.unregister().catch(function() {});
              });
            }).catch(function() {});
          }
        } catch (e) {}
      })();
    </script>

    {{-- Login page is intentionally NOT a PWA surface: no service worker is
         registered here. The SW is registered on the authenticated app shell
         instead, so the login HTML (which carries a session-specific CSRF
         token) is always served fresh from the network. --}}
  </body>
</html>
