{{-- PayPal module: the customer's public "Pay now" page of an invoice
     (PayPalInvoiceController). The POST (same signed URL) sends them to PayPal.
     Language: the customer's (browser language, or the EN / ES switch = cookie pay_lang). --}}
@php($money = fn ($v) => $currency.' '.number_format((float) $v, 2))
<!doctype html>
<html lang="{{ $lang }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{{ $sale ? $t('title_pay', ['ref' => $sale->Ref]) : $t('title') }}</title>
  <style>
    body { margin: 0; background: #f5f5f7; font-family: Arial, sans-serif; color: #222; }
    .card { max-width: 440px; margin: 48px auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
    @media (max-width: 480px) { .card { margin: 16px; padding: 20px; } }
    .langs { text-align: right; margin: -8px -8px 8px 0; font-size: 13px; }
    .langs a { color: #888; text-decoration: none; padding: 2px 6px; border-radius: 4px; }
    .langs a.on { color: #111; font-weight: 700; background: #f0f0f0; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .co { color: #666; margin: 0 0 18px; }
    table { border-collapse: collapse; width: 100%; margin: 8px 0 20px; }
    td { padding: 5px 0; vertical-align: top; }
    td:first-child { color: #666; width: 120px; }
    td:last-child { font-weight: 600; text-align: right; }
    tr.due td { border-top: 1px solid #eee; padding-top: 10px; font-size: 18px; }
    button { width: 100%; background: #ffc439; color: #111; border: 0; border-radius: 24px; padding: 13px; font-size: 16px; font-weight: 700; cursor: pointer; }
    button:hover { background: #f2ba36; }
    .ok { color: #2e7d32; }
    .warn { background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 10px 12px; margin: 0 0 16px; }
    .muted { color: #888; font-size: 13px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="langs">
      <a href="#" data-lang="en" class="{{ $lang === 'en' ? 'on' : '' }}">EN</a>
      <a href="#" data-lang="es" class="{{ $lang === 'es' ? 'on' : '' }}">ES</a>
    </div>
    @if (! $sale)
      <h1>{{ $t('not_found') }}</h1>
      <p>{{ $t('not_found_text') }}</p>
    @else
      @if ($state === 'paid')
        <h1 class="ok">&#10003; {{ $t('paid_title') }}</h1>
        <p class="co">{{ $t('paid_text', ['amount' => $money($paidAmount ?? 0), 'ref' => $sale->Ref]) }}</p>
      @else
        <h1>{{ $t('invoice', ['ref' => $sale->Ref]) }}</h1>
        <p class="co">{{ $sale->company_name }}</p>
      @endif

      @if ($message)
        <p class="warn">{{ $message }}</p>
      @elseif ($state === 'cancelled')
        <p class="warn">{{ $t('cancelled') }}</p>
      @endif

      <table>
        <tr><td>{{ $t('customer') }}</td><td>{{ $sale->client_name ?: '-' }}</td></tr>
        <tr><td>{{ $t('date') }}</td><td>{{ \Carbon\Carbon::parse($sale->date)->format($t('date_format')) }}</td></tr>
        <tr><td>{{ $t('total') }}</td><td>{{ $money($sale->GrandTotal) }}</td></tr>
        <tr><td>{{ $t('paid') }}</td><td>{{ $money($sale->paid_amount) }}</td></tr>
        <tr class="due"><td>{{ $t('due') }}</td><td>{{ $money($due) }}</td></tr>
      </table>

      @if ($state !== 'paid')
        @if ($sale->statut === 'cancelled')
          <p class="muted">{{ $t('cancelled_inv') }}</p>
        @elseif ($due <= 0)
          <p class="ok"><strong>{{ $t('paid_full') }}</strong></p>
        @elseif (! $available)
          <p class="muted">{{ $t('unavailable', ['company' => $sale->company_name]) }}</p>
        @else
          <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <button type="submit">{{ $t('button', ['amount' => $money($due)]) }}</button>
          </form>
          <p class="muted" style="margin-top:14px">{{ $t('help') }}</p>
        @endif
      @endif
    @endif
  </div>
  <script>
    // EN / ES switch: remembered for a year (cookie pay_lang), then reload the same (signed) page.
    document.querySelectorAll('.langs a').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        document.cookie = 'pay_lang=' + a.dataset.lang + '; path=/pay; max-age=31536000; SameSite=Lax';
        location.reload();
      });
    });
  </script>
</body>
</html>
