@php
  use App\Models\StoreSetting;
  use Illuminate\Support\Str;
  $s = $s ?? StoreSetting::first();
  $accent = $s->primary_color ?: '#0f766e';
  $gold = $s->secondary_color ?: '#c9a24d';
  // Legacy default colours from other themes don't suit this palette.
  if (in_array(strtolower($accent), ['#6c5ce7', '#3b82f6', '#2563eb', '#7b6fd0', '#1a7f6b'], true)) { $accent = '#0f766e'; }
  if (in_array(strtolower($gold), ['#00c2ff', '#22d3ee', '#1d4ed8', '#e8557a', '#0f5a4c'], true)) { $gold = '#c9a24d'; }
  $storeName = $s->store_name ?: 'Estate';
  $logo = $s->logo_path ? (Str::startsWith($s->logo_path, ['http://', 'https://', '/']) ? $s->logo_path : asset($s->logo_path)) : null;
  $currency = $s->currency_code ?? '$';
  $locale = app()->getLocale();
  $isRtl = in_array($locale, ['ar', 'he', 'fa', 'ur']);
  $hexRgb = function ($hex) {
      $hex = ltrim((string) $hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '15,118,110';
      return hexdec(substr($hex,0,2)).','.hexdec(substr($hex,2,2)).','.hexdec(substr($hex,4,2));
  };
  $localeNames = function_exists('store_locales') ? store_locales() : ['en' => 'English'];
  $nameParts = preg_split('/\s+/', trim($storeName), 2);
  $brandLead = $nameParts[0] ?? $storeName; $brandTail = $nameParts[1] ?? '';
  if ($brandTail === '' && preg_match('/^([A-Z][a-z]+)([A-Z].*)$/u', $brandLead, $mm)) { $brandLead = $mm[1]; $brandTail = $mm[2]; }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', $s->seo_meta_title ?? $storeName)</title>
  <meta name="description" content="@yield('meta_description', $s->seo_meta_description ?? '')">
  @if($s && $s->favicon_path)
    <link rel="icon" href="{{ Str::startsWith($s->favicon_path, ['http://', 'https://', '/']) ? $s->favicon_path : asset($s->favicon_path) }}">
  @endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --re-accent: {{ $accent }}; --re-accent-rgb: {{ $hexRgb($accent) }};
      --re-gold: {{ $gold }}; --re-gold-rgb: {{ $hexRgb($gold) }};
      --re-navy:#0b1a2b; --re-navy-2:#122539;
      --re-ink:#12202e; --re-muted:#6b7683; --re-line:#e6eaef; --re-bg:#f6f8fa; --re-card:#ffffff;
      --re-radius:16px; --re-shadow:0 1px 2px rgba(11,26,43,.04), 0 12px 30px -14px rgba(11,26,43,.18); --re-shadow-lg:0 30px 60px -24px rgba(11,26,43,.35);
    }
    *{box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{margin:0;font-family:'Inter',system-ui,Segoe UI,Roboto,Arial,sans-serif;color:var(--re-ink);background:var(--re-bg);line-height:1.55;-webkit-font-smoothing:antialiased}
    a{color:inherit;text-decoration:none}
    img{max-width:100%;display:block}
    h1,h2,h3,.re-serif{font-family:'Playfair Display','Inter',serif;letter-spacing:-.01em}
    .re-container{max-width:1240px;margin:0 auto;padding:0 20px}
    .re-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1.5px solid transparent;cursor:pointer;font-weight:600;border-radius:12px;padding:12px 20px;font-size:14px;transition:.18s;text-align:center;font-family:inherit;line-height:1.2}
    .re-btn svg{width:17px;height:17px}
    .re-btn-primary{background:var(--re-accent);color:#fff;box-shadow:0 10px 22px -10px rgba(var(--re-accent-rgb),.8)}
    .re-btn-primary:hover{filter:brightness(1.08);color:#fff}
    .re-btn-gold{background:var(--re-gold);color:#1d1a10}
    .re-btn-gold:hover{filter:brightness(1.06)}
    .re-btn-ghost{background:#fff;color:var(--re-ink);border-color:var(--re-line)}
    .re-btn-ghost:hover{border-color:var(--re-accent);color:var(--re-accent)}
    .re-btn-dark{background:var(--re-navy);color:#fff}
    .re-btn-dark:hover{background:var(--re-navy-2);color:#fff}
    .re-btn-light{background:rgba(255,255,255,.14);color:#fff;border-color:rgba(255,255,255,.35);backdrop-filter:blur(6px)}
    .re-btn-light:hover{background:rgba(255,255,255,.24);color:#fff}
    .re-btn-wa{background:#25d366;color:#fff}
    .re-btn-block{width:100%}
    .re-btn-sm{padding:9px 14px;font-size:13px;border-radius:10px}
    .re-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:5px 10px;border-radius:999px;line-height:1}
    .re-badge-sale{background:#fff;color:var(--re-accent)}
    .re-badge-rent{background:#fff;color:#2563eb}
    .re-badge-sold{background:#dc2626;color:#fff}
    .re-badge-rented{background:#d97706;color:#fff}
    .re-badge-featured{background:var(--re-gold);color:#1d1a10}
    .re-badge-soft{background:rgba(var(--re-accent-rgb),.1);color:var(--re-accent)}
    .re-badge-soft-blue{background:rgba(37,99,235,.1);color:#2563eb}
    .re-kicker{display:inline-flex;align-items:center;gap:10px;color:var(--re-accent);font-weight:700;font-size:12px;letter-spacing:.14em;text-transform:uppercase}
    .re-kicker::before{content:'';width:22px;height:2px;background:var(--re-gold);border-radius:2px}
    .re-title{font-size:30px;font-weight:600;margin:8px 0 0;line-height:1.15;color:var(--re-ink)}
    @media(min-width:1024px){.re-title{font-size:36px}}
    .re-section{padding:56px 0}
    @media(max-width:768px){.re-section{padding:40px 0}}
    .re-section-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;gap:16px;flex-wrap:wrap}
    .re-section-head p{margin:8px 0 0;color:var(--re-muted);max-width:560px;font-size:15px}
    .re-muted{color:var(--re-muted)}
    .re-card-pad{background:#fff;border:1px solid var(--re-line);border-radius:var(--re-radius);padding:22px;box-shadow:var(--re-shadow)}
    .re-icon{width:18px;height:18px;flex-shrink:0}
    .re-badge svg{width:12px;height:12px;flex-shrink:0}
    .w-3{width:12px}.h-3{height:12px}.w-3\.5{width:14px}.h-3\.5{height:14px}.w-10{width:40px}.h-10{height:40px}

    /* Header */
    .re-topbar{background:var(--re-navy);color:rgba(255,255,255,.85);font-size:12.5px}
    .re-topbar .re-container{display:flex;align-items:center;justify-content:space-between;height:38px;gap:14px}
    .re-topbar-left{display:flex;align-items:center;gap:18px;min-width:0;white-space:nowrap;overflow:hidden}
    .re-topbar-left span{display:inline-flex;align-items:center;gap:6px}
    .re-topbar-left svg{width:14px;height:14px;color:var(--re-gold)}
    .re-topbar-right{display:none;align-items:center;gap:6px}
    @media(min-width:768px){.re-topbar-right{display:flex}}
    .re-topbar-right a,.re-topbar-right button{color:#fff;background:transparent;border:0;cursor:pointer;font:inherit;padding:4px 8px;border-radius:6px;display:inline-flex;align-items:center;gap:6px}
    .re-topbar-right a:hover,.re-topbar-right button:hover{background:rgba(255,255,255,.12)}
    .re-topbar-right svg{width:13px;height:13px}
    .re-header{position:sticky;top:0;z-index:40;background:rgba(255,255,255,.92);backdrop-filter:blur(10px);border-bottom:1px solid var(--re-line)}
    .re-nav{display:flex;align-items:center;justify-content:space-between;height:74px;gap:18px}
    .re-brand{display:flex;align-items:center;gap:11px;font-family:'Playfair Display',serif;font-weight:700;font-size:23px;letter-spacing:-.01em}
    .re-brand .dot{width:38px;height:38px;border-radius:11px;background:var(--re-navy);display:inline-flex;align-items:center;justify-content:center;color:var(--re-gold)}
    .re-brand .dot svg{width:20px;height:20px}
    .re-brand em{font-style:normal;color:var(--re-accent)}
    .re-menu{display:flex;align-items:center;gap:4px;font-weight:600;font-size:14px}
    .re-menu a{padding:9px 13px;border-radius:10px;color:#3a4653;transition:.15s}
    .re-menu a:hover,.re-menu a.is-active{color:var(--re-accent);background:rgba(var(--re-accent-rgb),.08)}
    .re-actions{display:flex;align-items:center;gap:10px}
    .re-burger{display:none;background:#fff;border:1px solid var(--re-line);border-radius:10px;padding:9px 11px;cursor:pointer;color:var(--re-ink)}
    .re-burger svg{width:20px;height:20px}
    .re-mobile{display:none;border-top:1px solid var(--re-line);padding:8px 0 12px;background:#fff}
    .re-mobile a{display:block;padding:11px 20px;font-weight:600}
    .re-mobile .re-mobile-lang{display:flex;gap:6px;padding:10px 20px;flex-wrap:wrap}
    .re-mobile .re-mobile-lang a{padding:6px 10px;border:1px solid var(--re-line);border-radius:8px;font-size:13px}
    .re-dd{position:relative}
    .re-dd-menu{display:none;position:absolute;inset-inline-end:0;top:calc(100% + 6px);background:#fff;color:var(--re-ink);border:1px solid var(--re-line);border-radius:12px;box-shadow:var(--re-shadow-lg);padding:6px;min-width:160px;z-index:50}
    .re-dd.open .re-dd-menu{display:block}
    .re-dd-menu a{display:block;padding:8px 10px;border-radius:8px;font-size:13px;font-weight:500;color:var(--re-ink)}
    .re-dd-menu a:hover{background:var(--re-bg)}

    /* Footer */
    .re-footer{background:var(--re-navy);color:#b9c4cf;margin-top:72px;position:relative}
    .re-footer::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,var(--re-gold),var(--re-accent))}
    .re-foot-grid{display:grid;grid-template-columns:1.6fr 1fr 1fr 1.2fr;gap:36px;padding:56px 0 40px}
    .re-footer h4{color:#fff;font-family:'Inter',sans-serif;font-size:14px;letter-spacing:.06em;text-transform:uppercase;margin:0 0 16px}
    .re-footer a{display:block;color:#b9c4cf;padding:5px 0;font-size:14px}
    .re-footer a:hover{color:#fff}
    .re-footer p{margin:14px 0 0;font-size:14px;max-width:340px;line-height:1.7}
    .re-foot-contact div{display:flex;gap:10px;align-items:flex-start;padding:5px 0;font-size:14px}
    .re-foot-contact svg{width:16px;height:16px;color:var(--re-gold);flex-shrink:0;margin-top:3px}
    .re-social{display:flex;gap:8px;margin-top:18px}
    .re-social a{width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.08);display:inline-flex;align-items:center;justify-content:center;padding:0}
    .re-social a:hover{background:var(--re-accent);color:#fff}
    .re-social svg{width:16px;height:16px}
    .re-foot-bottom{border-top:1px solid rgba(255,255,255,.1);padding:20px 0;font-size:13px;color:#8a98a3;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}

    /* Property card */
    .re-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
    .re-grid.cols-2{grid-template-columns:repeat(2,1fr)}
    .re-grid.cols-4{grid-template-columns:repeat(4,1fr)}
    .re-card{background:var(--re-card);border:1px solid var(--re-line);border-radius:var(--re-radius);overflow:hidden;transition:.22s;display:flex;flex-direction:column;box-shadow:var(--re-shadow)}
    .re-card:hover{box-shadow:var(--re-shadow-lg);transform:translateY(-4px);border-color:rgba(var(--re-accent-rgb),.35)}
    .re-card-media{position:relative;display:block;aspect-ratio:4/3;background:#e9eef0;overflow:hidden}
    .re-card-media img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .5s}
    .re-card:hover .re-card-media img{transform:scale(1.05)}
    .re-card-media::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(11,26,43,0) 45%,rgba(11,26,43,.55) 100%);pointer-events:none}
    .re-card-tags{position:absolute;top:12px;inset-inline-start:12px;display:flex;gap:6px;z-index:2;flex-wrap:wrap}
    .re-card-price{position:absolute;bottom:12px;inset-inline-start:14px;color:#fff;font-weight:800;font-size:19px;z-index:2;letter-spacing:-.01em;text-shadow:0 2px 8px rgba(0,0,0,.35)}
    .re-card-price span{font-weight:500;font-size:13px;opacity:.9}
    .re-card-fav{position:absolute;top:12px;inset-inline-end:12px;width:34px;height:34px;border-radius:999px;background:rgba(255,255,255,.92);display:inline-flex;align-items:center;justify-content:center;color:var(--re-ink);z-index:2}
    .re-card-fav svg{width:16px;height:16px}
    .re-card-body{padding:16px 18px 18px;display:flex;flex-direction:column;gap:8px;flex:1}
    .re-card-cat{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--re-accent)}
    .re-card-title{font-family:'Inter',sans-serif;font-weight:700;font-size:16.5px;margin:0;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .re-card-title a:hover{color:var(--re-accent)}
    .re-card-loc{color:var(--re-muted);font-size:13px;display:flex;align-items:center;gap:6px}
    .re-card-loc svg{width:14px;height:14px;color:var(--re-gold)}
    .re-specs{display:flex;gap:16px;flex-wrap:wrap;border-top:1px solid var(--re-line);margin-top:auto;padding-top:12px;color:var(--re-muted);font-size:13px}
    .re-specs span{display:inline-flex;align-items:center;gap:6px}
    .re-specs svg{width:16px;height:16px;color:var(--re-accent)}
    .re-specs b{color:var(--re-ink);font-weight:700}
    .re-list-row{display:grid;grid-template-columns:320px 1fr}
    .re-list-row .re-card-media{aspect-ratio:auto;min-height:230px}
    @media(max-width:860px){.re-list-row{grid-template-columns:1fr}.re-list-row .re-card-media{aspect-ratio:4/3;min-height:0}}

    /* Category tiles + locations */
    .re-cat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:16px}
    .re-cat{position:relative;border-radius:var(--re-radius);overflow:hidden;aspect-ratio:4/5;background:var(--re-navy-2);color:#fff;display:flex;flex-direction:column;justify-content:flex-end;padding:16px;transition:.22s;box-shadow:var(--re-shadow)}
    .re-cat img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .5s}
    .re-cat::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(11,26,43,.05) 30%,rgba(11,26,43,.85) 100%)}
    .re-cat:hover{transform:translateY(-4px);box-shadow:var(--re-shadow-lg)}
    .re-cat:hover img{transform:scale(1.06)}
    .re-cat .ic{position:relative;z-index:1;width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.14);backdrop-filter:blur(6px);display:inline-flex;align-items:center;justify-content:center;margin-bottom:auto}
    .re-cat .ic svg{width:19px;height:19px}
    .re-cat .nm{position:relative;z-index:1;font-weight:700;font-size:15px;margin-top:8px}
    .re-cat .ct{position:relative;z-index:1;font-size:12px;opacity:.85}
    .re-loc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
    .re-loc{position:relative;border-radius:var(--re-radius);overflow:hidden;background:var(--re-navy-2);color:#fff;min-height:200px;display:flex;flex-direction:column;justify-content:flex-end;padding:22px;box-shadow:var(--re-shadow);transition:.22s}
    .re-loc:first-child{grid-row:span 2;min-height:420px}
    .re-loc img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .5s}
    .re-loc::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(11,26,43,0) 40%,rgba(11,26,43,.8) 100%)}
    .re-loc:hover img{transform:scale(1.05)}
    .re-loc .city{position:relative;z-index:1;font-family:'Playfair Display',serif;font-weight:600;font-size:22px}
    .re-loc .ct{position:relative;z-index:1;font-size:13px;opacity:.9;display:flex;align-items:center;gap:6px}
    .re-loc .ct svg{width:14px;height:14px;color:var(--re-gold)}

    /* Forms */
    .re-field{margin-bottom:14px}
    .re-label{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--re-ink)}
    .re-input,.re-select,.re-textarea{width:100%;border:1.5px solid var(--re-line);border-radius:11px;padding:11px 13px;font-size:14px;font-family:inherit;background:#fff;color:var(--re-ink)}
    .re-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7683' stroke-width='2.5'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-inline-end:34px}
    [dir=rtl] .re-select{background-position:left 12px center}
    .re-input:focus,.re-select:focus,.re-textarea:focus{outline:none;border-color:var(--re-accent);box-shadow:0 0 0 4px rgba(var(--re-accent-rgb),.12)}
    .re-alert{padding:11px 14px;border-radius:10px;font-size:14px;margin-bottom:12px}
    .re-alert-ok{background:rgba(var(--re-accent-rgb),.1);color:var(--re-accent)}
    .re-alert-err{background:rgba(220,38,38,.1);color:#b91c1c}
    .re-hidden{display:none}

    /* Pager */
    .re-pager{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}
    .re-page{min-width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--re-line);border-radius:11px;background:#fff;font-weight:600;font-size:14px;padding:0 12px}
    .re-page:hover{border-color:var(--re-accent);color:var(--re-accent)}
    .re-page.active{background:var(--re-navy);color:#fff;border-color:var(--re-navy)}
    .re-page.disabled{opacity:.4;pointer-events:none}

    /* Stats + why + CTA */
    .re-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
    .re-stat{background:#fff;border:1px solid var(--re-line);border-radius:var(--re-radius);padding:22px 20px;display:flex;align-items:center;gap:14px;box-shadow:var(--re-shadow)}
    .re-stat .ic{width:46px;height:46px;border-radius:12px;background:rgba(var(--re-accent-rgb),.1);color:var(--re-accent);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
    .re-stat .ic svg{width:22px;height:22px}
    .re-stat b{display:block;font-family:'Playfair Display',serif;font-size:26px;font-weight:600;line-height:1.1}
    .re-stat span{font-size:13px;color:var(--re-muted)}
    .re-why{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
    .re-why-item{background:#fff;border:1px solid var(--re-line);border-radius:var(--re-radius);padding:26px;box-shadow:var(--re-shadow)}
    .re-why-item .ic{width:50px;height:50px;border-radius:14px;background:var(--re-navy);color:var(--re-gold);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px}
    .re-why-item .ic svg{width:24px;height:24px}
    .re-why-item h3{font-family:'Inter',sans-serif;font-size:17px;font-weight:700;margin:0 0 6px}
    .re-why-item p{margin:0;font-size:14px;color:var(--re-muted);line-height:1.65}
    .re-cta{position:relative;border-radius:24px;overflow:hidden;background:var(--re-navy);color:#fff;padding:56px 40px;display:grid;grid-template-columns:1.4fr 1fr;gap:30px;align-items:center;isolation:isolate}
    .re-cta img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.32;z-index:-1}
    .re-cta::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(11,26,43,.95) 0%,rgba(11,26,43,.55) 100%);z-index:-1}
    .re-cta h2{font-size:34px;font-weight:600;margin:0 0 10px;line-height:1.15}
    .re-cta p{margin:0;color:rgba(255,255,255,.85);max-width:540px;font-size:15px}
    .re-cta-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}

    @media(max-width:1100px){.re-cat-grid{grid-template-columns:repeat(3,1fr)}}
    @media(max-width:980px){
      .re-grid,.re-grid.cols-4{grid-template-columns:repeat(2,1fr)}
      .re-loc-grid{grid-template-columns:repeat(2,1fr)}.re-loc:first-child{grid-row:auto;min-height:220px}
      .re-foot-grid{grid-template-columns:1fr 1fr}
      .re-stats{grid-template-columns:repeat(2,1fr)}
      .re-why{grid-template-columns:1fr}
      .re-cta{grid-template-columns:1fr;padding:40px 28px}.re-cta-actions{justify-content:flex-start}
    }
    @media(max-width:680px){
      .re-menu{display:none}
      .re-burger{display:inline-flex}
      .re-actions .re-btn-primary{display:none}
      .re-grid,.re-grid.cols-2,.re-grid.cols-4{grid-template-columns:1fr}
      .re-cat-grid{grid-template-columns:repeat(2,1fr)}
      .re-loc-grid{grid-template-columns:1fr}
      .re-foot-grid{grid-template-columns:1fr;gap:26px}
      .re-stats{grid-template-columns:1fr 1fr;gap:10px}.re-stat{padding:16px 14px}.re-stat b{font-size:20px}
      .re-cta h2{font-size:26px}
    }
  </style>
  @stack('head')
</head>
<body>
  <div class="re-topbar">
    <div class="re-container">
      <div class="re-topbar-left">
        @if($s && $s->contact_phone)<span><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}">{{ $s->contact_phone }}</a></span>@endif
        @if($s && $s->contact_email)<span class="hidden-sm"><x-store.icon name="mail" /><a href="mailto:{{ $s->contact_email }}">{{ $s->contact_email }}</a></span>@endif
        @if(!$s || (!$s->contact_phone && !$s->contact_email))<span><x-store.icon name="clock" />{{ $s->localizedText('topbar_text_left') ?? __('messages.RealEstateFooterTagline') }}</span>@endif
      </div>
      <div class="re-topbar-right">
        <a href="{{ route('store.contact') }}"><x-store.icon name="message" />{{ __('messages.ContactUs') }}</a>
        <div class="re-dd" onmouseleave="this.classList.remove('open')">
          <button type="button" onclick="this.parentNode.classList.toggle('open')"><x-store.icon name="globe" />{{ $localeNames[$locale] ?? strtoupper($locale) }}<x-store.icon name="chevron-down" /></button>
          <div class="re-dd-menu">
            @foreach($localeNames as $code => $label)
              <a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>
            @endforeach
          </div>
        </div>
      </div>
    </div>
  </div>

  <header class="re-header">
    <div class="re-container">
      <nav class="re-nav">
        <a class="re-brand" href="{{ route('store.index') }}">
          @if($logo)
            <img src="{{ $logo }}" alt="{{ $storeName }}" style="height:38px;width:auto">
          @else
            <span class="dot"><x-store.icon name="home" /></span>
            <span>{{ $brandLead }}@if($brandTail !== '') <em>{{ $brandTail }}</em>@endif</span>
          @endif
        </a>
        <div class="re-menu">
          <a href="{{ route('store.index') }}" class="{{ request()->routeIs('store.index') ? 'is-active' : '' }}">{{ __('messages.Home') }}</a>
          <a href="{{ route('store.realestate.listings') }}" class="{{ request()->routeIs('store.realestate.listings') && !request('purpose') ? 'is-active' : '' }}">{{ __('messages.Properties') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'sale']) }}" class="{{ request('purpose') === 'sale' ? 'is-active' : '' }}">{{ __('messages.ForSale') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'rent']) }}" class="{{ request('purpose') === 'rent' ? 'is-active' : '' }}">{{ __('messages.ForRent') }}</a>
          <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
        </div>
        <div class="re-actions">
          <a class="re-btn re-btn-primary" href="{{ route('store.realestate.listings') }}"><x-store.icon name="search" />{{ __('messages.BrowseProperties') }}</a>
          <button class="re-burger" onclick="var m=document.getElementById('reMobile');m.style.display=(m.style.display==='block'?'none':'block')" aria-label="Menu"><x-store.icon name="menu" /></button>
        </div>
      </nav>
    </div>
    <div class="re-mobile" id="reMobile">
      <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a>
      <a href="{{ route('store.realestate.listings') }}">{{ __('messages.Properties') }}</a>
      <a href="{{ route('store.realestate.listings', ['purpose' => 'sale']) }}">{{ __('messages.ForSale') }}</a>
      <a href="{{ route('store.realestate.listings', ['purpose' => 'rent']) }}">{{ __('messages.ForRent') }}</a>
      <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
      <div class="re-mobile-lang">
        @foreach($localeNames as $code => $label)
          <a href="{{ route('lang.switch', $code) }}">{{ $label }}</a>
        @endforeach
      </div>
    </div>
  </header>

  <main>
    @yield('content')
  </main>

  <footer class="re-footer">
    <div class="re-container">
      <div class="re-foot-grid">
        <div>
          <a class="re-brand" href="{{ route('store.index') }}" style="color:#fff">
            <span class="dot"><x-store.icon name="home" /></span>
            <span>{{ $brandLead }}@if($brandTail !== '') <em style="color:var(--re-gold)">{{ $brandTail }}</em>@endif</span>
          </a>
          <p>{{ $s->localizedText('footer_text') ?? __('messages.RealEstateFooterTagline') }}</p>
          @php
            $social = $s->social_links ?? [];
            if (is_string($social)) { $social = json_decode($social, true) ?: []; }
            if (is_array($social) && $social && array_keys($social) !== range(0, count($social) - 1)) { $social = collect($social)->map(fn($u,$p)=>['platform'=>$p,'url'=>$u])->values()->all(); }
          @endphp
          @if(is_array($social) && count($social))
            <div class="re-social">
              @foreach($social as $item)
                @php $platform = strtolower(trim($item['platform'] ?? '')); $url = $item['url'] ?? ''; if ($platform === 'x') $platform = 'twitter-x'; @endphp
                @if($platform && $url)<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $platform }}"><x-store.icon :name="$platform" /></a>@endif
              @endforeach
            </div>
          @endif
        </div>
        <div>
          <h4>{{ __('messages.Explore') }}</h4>
          <a href="{{ route('store.realestate.listings') }}">{{ __('messages.AllProperties') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'sale']) }}">{{ __('messages.ForSale') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'rent']) }}">{{ __('messages.ForRent') }}</a>
          <a href="{{ route('store.realestate.listings', ['sort' => 'latest']) }}">{{ __('messages.LatestProperties') }}</a>
        </div>
        <div>
          <h4>{{ __('messages.Company') }}</h4>
          <a href="{{ route('store.contact') }}">{{ __('messages.AboutUs') }}</a>
          <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
          <a href="{{ route('store.contact') }}">{{ __('messages.ListYourProperty') }}</a>
        </div>
        <div class="re-foot-contact">
          <h4>{{ __('messages.GetInTouch') }}</h4>
          @if($s && $s->contact_phone)<div><x-store.icon name="phone" /><a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}" style="padding:0">{{ $s->contact_phone }}</a></div>@endif
          @if($s && $s->contact_email)<div><x-store.icon name="mail" /><a href="mailto:{{ $s->contact_email }}" style="padding:0">{{ $s->contact_email }}</a></div>@endif
          @if($s && $s->contact_address)<div><x-store.icon name="map-pin" /><span>{{ $s->contact_address }}</span></div>@endif
        </div>
      </div>
      <div class="re-foot-bottom">
        <span>© {{ date('Y') }} {{ $storeName }}. {{ __('messages.AllRightsReserved') }}</span>
        <span>{{ __('messages.PoweredByStorefront') }}</span>
      </div>
    </div>
  </footer>
  @stack('scripts')
</body>
</html>
