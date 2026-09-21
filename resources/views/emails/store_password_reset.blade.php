@component('mail::message')

<span>You are receiving this email because we received a password reset request for your account{{ $storeName ? ' at '.$storeName : '' }}.</span>

@component('mail::button', ['url' => $url])
Reset Password
@endcomponent

<span>This password reset link will expire in 60 minutes.</span>

<span>If you did not request a password reset, no further action is required.</span>

<span>Regards,<span><br>
{{ $storeName ?: config('app.name') }}
@endcomponent
