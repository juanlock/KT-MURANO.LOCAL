@component('mail::message')

<span>Good news! Your account{{ $storeName ? ' at '.$storeName : '' }} has been approved by our team.</span>

<span>You can now sign in and start shopping.</span>

@component('mail::button', ['url' => $loginUrl])
Sign In
@endcomponent

<span>Regards,<span><br>
{{ $storeName ?: config('app.name') }}
@endcomponent
