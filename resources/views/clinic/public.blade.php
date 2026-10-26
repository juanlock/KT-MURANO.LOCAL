<!doctype html>
<html lang="{{ $es ? 'es' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f1f5f9; color: #1e293b; }
        .box { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 16px; padding: 28px 24px; box-shadow: 0 6px 24px rgba(0,0,0,.08); }
        h1 { font-size: 20px; margin: 0 0 6px; }
        .muted { color: #64748b; font-size: 14px; }
        .msg { font-size: 17px; margin: 18px 0 0; }
        .scale { display: grid; grid-template-columns: repeat(11, 1fr); gap: 6px; margin: 16px 0 6px; }
        .scale label { display: block; }
        .scale input { display: none; }
        .scale span { display: block; text-align: center; padding: 10px 0; border-radius: 8px; border: 2px solid #cbd5e1; cursor: pointer; font-weight: 700; }
        .scale input:checked + span { background: #2563eb; border-color: #2563eb; color: #fff; }
        .ends { display: flex; justify-content: space-between; font-size: 12px; color: #64748b; }
        textarea { width: 100%; box-sizing: border-box; min-height: 90px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; font: inherit; margin-top: 14px; }
        button { margin-top: 14px; width: 100%; padding: 12px; border: 0; border-radius: 8px; background: #2563eb; color: #fff; font-size: 16px; cursor: pointer; }
        @media (max-width: 420px) { .scale { grid-template-columns: repeat(6, 1fr); } }
    </style>
</head>
<body>
<div class="box">
    <h1>{{ $company }}</h1>
    @if($detail)<div class="muted">{{ $detail }}</div>@endif
    @if($message)<p class="msg">{{ $message }}</p>@endif
    @if($survey)
        @if($survey->answered_at)
            <p class="msg">{{ $es ? 'Esta encuesta ya fue respondida. ¡Gracias!' : 'This survey was already answered. Thank you!' }}</p>
        @else
            <form method="post" action="{{ url('/survey/'.$survey->token) }}">
                @csrf
                <p class="msg">{{ $es ? '¿Qué tan probable es que nos recomiende a un familiar o amigo?' : 'How likely are you to recommend us to a friend or relative?' }}</p>
                <div class="scale">
                    @for($i = 0; $i <= 10; $i++)
                        <label><input type="radio" name="score" value="{{ $i }}" required @checked((string) ($score ?? '') === (string) $i)><span>{{ $i }}</span></label>
                    @endfor
                </div>
                <div class="ends"><span>{{ $es ? 'Nada probable' : 'Not likely' }}</span><span>{{ $es ? 'Muy probable' : 'Very likely' }}</span></div>
                <textarea name="comment" placeholder="{{ $es ? '¿Algo que quiera contarnos? (opcional)' : 'Anything you want to tell us? (optional)' }}"></textarea>
                <button type="submit">{{ $es ? 'Enviar' : 'Send' }}</button>
            </form>
        @endif
    @endif
</div>
</body>
</html>
