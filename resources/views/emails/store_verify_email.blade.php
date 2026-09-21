@component('mail::message')

<span>Thank you for creating an account{{ $storeName ? ' at '.$storeName : '' }}.</span>

<span>Please click the button below to verify your email address and activate your account.</span>

@component('mail::button', ['url' => $url])
Verify Email Address
@endcomponent

<span>This verification link will expire in 24 hours.</span>

<span>If you did not create an account, no further action is required.</span>

<span>Regards,<span><br>
{{ $storeName ?: config('app.name') }}
@endcomponent
