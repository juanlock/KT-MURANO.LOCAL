@component('mail::message')
# {{ __('New Online Order') }}

@php
  // A real Markdown list: these used to be consecutive "**Label:** value"
  // lines, and Markdown collapses single newlines into one run-on paragraph.
  $orderedAt = trim(
      ($order->date instanceof \Carbon\CarbonInterface ? $order->date->format('Y-m-d') : (string) $order->date)
      .' '.(string) $order->time
  );

  $shipTo = store_address_lines([
      'name' => $order->customer_name,
      'address' => $order->shipping_address,
      'city' => $order->shipping_city ?? null,
      'state' => $order->shipping_state ?? null,
      'zip' => $order->shipping_zip ?? null,
      'country' => $order->shipping_country ?? null,
  ]);
@endphp

- **{{ __('Order Reference') }}:** {{ $order->ref }}
- **{{ __('Date') }}:** {{ $orderedAt }}
- **{{ __('Customer') }}:** {{ $order->customer_name }} ({{ $order->customer_email }})
- **{{ __('Phone') }}:** {{ $order->customer_phone }}
- **{{ __('Payment Method') }}:** {{ $order->payment_method }} — {{ $order->payment_status }}
- **{{ __('Total') }}:** {{ number_format((float) $order->total, 2) }}

@if($order->is_flagged)
> ⚠️ **{{ __('Flagged for review') }}:** {{ $order->flag_reason }}
@endif

**{{ __('Ship to') }}:**

<span>{!! implode('<br>', array_map('e', $shipTo)) !!}</span>

@component('mail::button', ['url' => url('/app/Store/Orders')])
{{ __('Review Order') }}
@endcomponent

{{ $storeName ?: config('app.name') }}
@endcomponent
