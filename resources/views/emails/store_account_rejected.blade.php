@component('mail::message')

<span>We are sorry to inform you that your registration request{{ $storeName ? ' at '.$storeName : '' }} was not approved.</span>

<span>If you believe this is a mistake, please contact us for assistance.</span>

<span>Regards,<span><br>
{{ $storeName ?: config('app.name') }}
@endcomponent
