@component('mail::message')

<span>{{ __('messages.Hello') }} {{ $order->customer_name }},</span>

@if($approved)
<span>{{ __('messages.PaymentProofApprovedBody', ['ref' => $order->ref]) }}</span>
@else
<span>{{ __('messages.PaymentProofRejectedBody', ['ref' => $order->ref]) }}</span>
@endif

@if(! $approved && $proof->reject_reason)

<span>{{ __('messages.Reason') }}: {{ $proof->reject_reason }}</span>
@endif

<span>{{ __('messages.ReferenceNumber') }}: {{ $proof->reference_number }}</span><br>
<span>{{ __('messages.Amount') }}: {{ store_money($proof->amount) }}</span>

@component('mail::button', ['url' => $orderUrl])
{{ __('messages.ViewOrder') }}
@endcomponent

<span>{{ __('messages.Regards') }},</span><br>
{{ $storeName }}
@endcomponent
