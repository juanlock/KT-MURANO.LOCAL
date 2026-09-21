<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { color: #222; font-size: 12px; }
  .header { width: 100%; margin-bottom: 20px; }
  .header td { vertical-align: top; }
  .company { font-size: 18px; font-weight: bold; }
  .muted { color: #777; }
  .title { font-size: 22px; font-weight: bold; text-align: right; }
  .box { margin-top: 10px; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
  table.items th { background: #f2f2f2; text-align: left; padding: 8px; border-bottom: 2px solid #ddd; }
  table.items td { padding: 8px; border-bottom: 1px solid #eee; }
  .right { text-align: right; }
  table.totals { width: 40%; float: right; margin-top: 12px; border-collapse: collapse; }
  table.totals td { padding: 6px 8px; }
  table.totals tr.grand td { border-top: 2px solid #333; font-weight: bold; font-size: 14px; }
  .paid { color: #157347; font-weight: bold; }
  .pending { color: #b58105; font-weight: bold; }
</style>
</head>
<body>
  @php
    $companyName = $settings->CompanyName ?? ($store->store_name ?? config('app.name'));
    $companyEmail = $settings->email ?? ($store->contact_email ?? '');
    $companyPhone = $settings->phone ?? '';
    $companyAddr = $settings->adress ?? '';
    $sym = $symbol;
    $money = fn ($v) => $sym . number_format((float) $v, 2);
  @endphp

  <table class="header">
    <tr>
      <td>
        <div class="company">{{ $companyName }}</div>
        @if($companyAddr)<div class="muted">{{ $companyAddr }}</div>@endif
        @if($companyPhone)<div class="muted">{{ $companyPhone }}</div>@endif
        @if($companyEmail)<div class="muted">{{ $companyEmail }}</div>@endif
      </td>
      <td>
        <div class="title">{{ __('messages.Invoice') }}</div>
        <div class="right box">
          <div><strong>{{ $order->ref }}</strong></div>
          <div class="muted">{{ $order->date }} {{ $order->time }}</div>
          <div>
            @if(($order->payment_status ?? '') === 'paid')
              <span class="paid">{{ __('messages.Paid') }}</span>
            @else
              <span class="pending">{{ __('messages.Pending') }}</span>
            @endif
          </div>
        </div>
      </td>
    </tr>
  </table>

  <table class="header">
    <tr>
      <td>
        <div class="muted">{{ __('messages.BillTo') }}</div>
        <div><strong>{{ $customer['name'] }}</strong></div>
        @if($customer['email'])<div>{{ $customer['email'] }}</div>@endif
        @if($customer['phone'])<div>{{ $customer['phone'] }}</div>@endif
      </td>
      <td>
        <div class="muted">{{ __('messages.ShipTo') }}</div>
        <div>{{ $customer['address'] }}</div>
        <div>{{ trim(($customer['city'] ?? '').' '.($customer['state'] ?? '').' '.($customer['zip'] ?? '')) }}</div>
        <div>{{ $customer['country'] }}</div>
        @if($order->shipping_method_name)<div class="muted">{{ __('messages.Via') }} {{ $order->shipping_method_name }}</div>@endif
      </td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr>
        <th>{{ __('messages.Product') }}</th>
        <th class="right">{{ __('messages.Price') }}</th>
        <th class="right">{{ __('messages.Qty') }}</th>
        <th class="right">{{ __('messages.Subtotal') }}</th>
      </tr>
    </thead>
    <tbody>
      @foreach($lines as $l)
      <tr>
        <td>{{ $l['name'] }}@if($l['is_preorder']) <span class="muted">({{ __('messages.PreOrder') }})</span>@endif</td>
        <td class="right">{{ $money($l['price']) }}</td>
        <td class="right">{{ rtrim(rtrim(number_format($l['qty'], 2), '0'), '.') }}</td>
        <td class="right">{{ $money($l['line_total']) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr>
      <td>{{ __('messages.Subtotal') }}</td>
      <td class="right">{{ $money($subtotal) }}</td>
    </tr>
    @if($tax > 0)
    <tr>
      <td>{{ __('messages.Tax') }} @if($tax_rate > 0)({{ rtrim(rtrim(number_format($tax_rate, 3), '0'), '.') }}%)@endif</td>
      <td class="right">{{ $money($tax) }}</td>
    </tr>
    @endif
    @if($shipping > 0)
    <tr>
      <td>{{ __('messages.Shipping') }}</td>
      <td class="right">{{ $money($shipping) }}</td>
    </tr>
    @endif
    <tr class="grand">
      <td>{{ __('messages.GrandTotal') }}</td>
      <td class="right">{{ $money($total) }}</td>
    </tr>
  </table>
</body>
</html>
