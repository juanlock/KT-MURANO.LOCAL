<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="30">
    <title>System Update in Progress</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f0f2f5;
            color: #1f1f1f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, .08);
            max-width: 440px;
            width: 100%;
            padding: 48px 40px;
            text-align: center;
        }
        .spinner {
            width: 56px;
            height: 56px;
            margin: 0 auto 28px;
            border: 4px solid #e6f4ff;
            border-top-color: #1677ff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        h1 { font-size: 22px; font-weight: 600; margin-bottom: 12px; }
        p { font-size: 14px; line-height: 1.7; color: #595959; }
        .note { margin-top: 24px; font-size: 12px; color: #8c8c8c; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner" aria-hidden="true"></div>
        <h1>We&rsquo;ll be right back</h1>
        <p>
            The system is being updated to a new version.
            Your data is safe &mdash; a full backup was taken before the update started.
        </p>
        <p style="margin-top:10px">
            This usually takes a few minutes. This page refreshes automatically.
        </p>
        <div class="note">
            Thank you for your patience.<br>
            <a href="{{ url('/system-update/recovery') }}" style="color:#bfbfbf; font-size:11px; text-decoration:none">Administrator access</a>
        </div>
    </div>
</body>
</html>
