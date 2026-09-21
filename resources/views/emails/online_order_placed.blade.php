@component('mail::message')
# {{ __('Thank you for your order') }}

<span>{{ __('Hi') }} {{ $order->customer_name }}, {{ __('we have received your order.') }}</span>

@php
  // Blade eats the newline after a trailing @endif, so interpolating the
  // address parts inline glued the zip to the country ("31160México") and
  // left stray separators for blank fields. Build the lines in PHP instead.
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
- **{{ __('Payment Method') }}:** {{ $order->payment_method }}
- **{{ __('Payment Status') }}:** {{ $order->payment_status }}

@component('mail::table')
| {{ __('Summary') }} | |
|:--------------------|--------------------:|
| {{ __('Subtotal') }} | {{ number_format((float) $order->subtotal, 2) }} |
| {{ __('Tax') }} ({{ rtrim(rtrim(number_format((float) $order->tax_rate, 3), '0'), '.') }}%) | {{ number_format((float) $order->tax, 2) }} |
| {{ __('Shipping') }}{{ $order->shipping_method_name ? ' — '.$order->shipping_method_name : '' }} | {{ number_format((float) $order->shipping_cost, 2) }} |
| **{{ __('Total') }}** | **{{ number_format((float) $order->total, 2) }}** |
@endcomponent

**{{ __('Shipping to') }}:**

<span>{!! implode('<br>', array_map('e', $shipTo)) !!}</span>

<span>{{ __('We will notify you when your order is processed.') }}</span>

<span>{{ __('Regards') }},<br>
{{ $storeName ?: config('app.name') }}</span>
@endcomponent
