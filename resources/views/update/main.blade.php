<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Stocky — System Update</title>
    {{-- Deliberately self-contained: this page runs mid-upgrade, when the app's
         normal assets (and possibly the network) can't be trusted. --}}
    <style>
        :root {
            --bg: #0d0b1e;
            --surface: rgba(255, 255, 255, 0.045);
            --line: rgba(255, 255, 255, 0.09);
            --fg: #f4f2fb;
            --fg-soft: #a8a1c0;
            --fg-faint: #6f6890;
            --accent: #7c3aed;
            --accent-soft: #a78bfa;
            --success: #4ade80;
            --danger: #f87171;
            --warning: #fbbf24;
            --radius: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: var(--fg);
            line-height: 1.55;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 24px 16px;
            background-image:
                radial-gradient(ellipse 70% 45% at 50% -5%, rgba(124, 58, 237, 0.28), transparent 70%),
                radial-gradient(ellipse 45% 35% at 85% 110%, rgba(109, 40, 217, 0.14), transparent 70%);
            background-repeat: no-repeat;
        }

        .wrap { width: 100%; max-width: 560px; margin: auto; }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: center;
            margin-bottom: 28px;
        }
        .brand-mark {
            width: 40px; height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), #4c1d95);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 20px; color: #fff;
            box-shadow: 0 8px 24px rgba(124, 58, 237, 0.4);
        }
        .brand-name { font-size: 20px; font-weight: 600; letter-spacing: 0.2px; }
        .brand-name small {
            display: block; font-size: 11px; font-weight: 500;
            color: var(--fg-faint); letter-spacing: 2.5px; text-transform: uppercase;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 32px;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
        }
        @media (max-width: 480px) { .card { padding: 24px 20px; } }

        .eyebrow {
            font-size: 11px; font-weight: 600; letter-spacing: 2.5px;
            text-transform: uppercase; color: var(--accent-soft);
            margin-bottom: 8px;
        }
        h1 { font-size: 26px; font-weight: 650; letter-spacing: -0.3px; margin-bottom: 6px; }
        .lead { color: var(--fg-soft); font-size: 14px; }

        .chip {
            display: inline-flex; align-items: center; gap: 7px;
            border: 1px solid var(--line);
            background: rgba(124, 58, 237, 0.12);
            color: var(--accent-soft);
            border-radius: 999px;
            font-size: 12px; font-weight: 600;
            padding: 4px 12px;
            margin-top: 14px;
        }
        .chip .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent-soft); }

        .divider { border: 0; border-top: 1px solid var(--line); margin: 24px 0; }

        .note {
            display: flex; gap: 10px;
            border: 1px solid rgba(251, 191, 36, 0.25);
            background: rgba(251, 191, 36, 0.07);
            border-radius: var(--radius);
            padding: 12px 14px;
            font-size: 13px; color: #e7d9ae;
        }
        .note strong { color: #fde68a; }
        .note-icon { flex: 0 0 auto; color: var(--warning); margin-top: 1px; }

        .steps { list-style: none; margin-top: 4px; }
        .step {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 9px 0;
            font-size: 14px; color: var(--fg-faint);
            transition: color 0.4s;
        }
        .step-bullet {
            flex: 0 0 auto;
            width: 22px; height: 22px; margin-top: 1px;
            border-radius: 50%;
            border: 2px solid var(--fg-faint);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
            transition: border-color 0.4s, background 0.4s;
        }
        .step small { display: block; font-size: 12px; color: var(--fg-faint); }
        .step.is-active { color: var(--fg); }
        .step.is-active .step-bullet {
            border-color: var(--accent-soft);
            animation: pulse 1.6s ease-in-out infinite;
        }
        .step.is-done { color: var(--fg-soft); }
        .step.is-done .step-bullet {
            border-color: var(--success);
            background: rgba(74, 222, 128, 0.12);
            color: var(--success);
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(167, 139, 250, 0.45); }
            50% { box-shadow: 0 0 0 6px rgba(167, 139, 250, 0); }
        }

        .btn {
            appearance: none; border: 0; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%;
            font: inherit; font-size: 15px; font-weight: 600; color: #fff;
            background: linear-gradient(135deg, #8b5cf6, var(--accent) 55%, #5b21b6);
            border-radius: var(--radius);
            padding: 13px 20px;
            box-shadow: 0 10px 28px rgba(124, 58, 237, 0.35);
            transition: transform 0.15s, box-shadow 0.15s, filter 0.15s;
        }
        .btn:hover { transform: translateY(-1px); filter: brightness(1.08); }
        .btn:focus-visible { outline: 3px solid var(--accent-soft); outline-offset: 2px; }
        .btn[disabled] { cursor: default; filter: saturate(0.6) brightness(0.85); transform: none; }
        .btn-ghost {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--fg-soft);
            box-shadow: none;
        }
        .btn-ghost:hover { color: var(--fg); border-color: var(--fg-faint); }

        .spinner {
            width: 18px; height: 18px; flex: 0 0 auto;
            border-radius: 50%;
            border: 2.5px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .success-mark {
            width: 72px; height: 72px; margin: 0 auto 18px;
            border-radius: 50%;
            border: 2px solid rgba(74, 222, 128, 0.4);
            background: rgba(74, 222, 128, 0.1);
            display: flex; align-items: center; justify-content: center;
        }
        .success-mark svg { width: 34px; height: 34px; stroke: var(--success); }
        .success-mark svg path {
            stroke-dasharray: 48; stroke-dashoffset: 48;
            animation: draw 0.7s 0.25s ease forwards;
        }
        @keyframes draw { to { stroke-dashoffset: 0; } }

        .footer {
            margin-top: 22px;
            text-align: center;
            font-size: 12px;
            color: var(--fg-faint);
        }

        .hidden { display: none !important; }

        @media (prefers-reduced-motion: reduce) {
            .step.is-active .step-bullet { animation: none; }
            .success-mark svg path { animation: none; stroke-dashoffset: 0; }
            .btn, .btn:hover { transition: none; transform: none; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="brand-mark">S</div>
        <div class="brand-name">Stocky<small>System update</small></div>
    </div>
    @yield('content')
    <div class="footer">Keep this tab open until the update finishes.</div>
</div>
</body>
</html>
