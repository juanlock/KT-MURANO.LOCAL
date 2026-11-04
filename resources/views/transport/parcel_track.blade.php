<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Encomienda {{ $parcel->number }}</title>
<style>
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background: #f1f5f9; color: #0f172a; }
  .card { max-width: 460px; margin: 24px auto; background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  h1 { font-size: 20px; margin: 0 0 2px; } .muted { color: #64748b; font-size: 13px; }
  .route { font-size: 16px; font-weight: 700; margin: 14px 0; }
  ol { list-style: none; margin: 0; padding: 0; }
  li { position: relative; padding: 0 0 18px 26px; color: #94a3b8; }
  li:before { content: ''; position: absolute; left: 4px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #cbd5e1; }
  li:not(:last-child):after { content: ''; position: absolute; left: 9px; top: 18px; width: 2px; bottom: 0; background: #e2e8f0; }
  li.done { color: #0f172a; font-weight: 600; } li.done:before { background: #16a34a; }
  li small { display: block; font-weight: 400; color: #64748b; }
  .cancelled { background: #fef2f2; color: #b91c1c; padding: 10px 12px; border-radius: 8px; font-weight: 600; }
</style>
</head>
<body>
<div class="card">
  <div class="muted">{{ $company }}</div>
  <h1>Encomienda {{ $parcel->number }}</h1>
  <div class="route">{{ $from }} → {{ $to }}</div>
  @if ($eta && $parcel->status !== 'cancelled' && $parcel->status !== 'delivered')
    <p class="muted">Llegada estimada: del {{ $eta }}</p>
  @endif
  @if ($parcel->status === 'cancelled')
    <div class="cancelled">Esta encomienda fue anulada.</div>
  @else
    <ol>
      @foreach ($steps as $step)
        <li class="{{ $step['at'] ? 'done' : '' }}">{{ $step['label'] }}@if ($step['at'])<small>{{ $step['at'] }}</small>@endif</li>
      @endforeach
    </ol>
  @endif
</div>
</body>
</html>
