@extends('setup.main')
@section('content')

    <div class="finish-wrap">

        <div class="success-mark">
            <i class="fa fa-check"></i>
        </div>

        <p class="eyebrow">Setup complete</p>
        <h1>Installation successful</h1>
        <p class="lead-text">Stocky is installed and ready to use. Sign in with the default administrator account.</p>

        <div class="credentials-card">
            <h5><i class="fa fa-key"></i> Default login credentials</h5>

            <div class="credential-row">
                <span class="credential-label"><i class="fa fa-envelope"></i> Email</span>
                <span class="credential-value">admin@example.com</span>
            </div>

            <div class="credential-row">
                <span class="credential-label"><i class="fa fa-lock"></i> Password</span>
                <span class="credential-value">123456</span>
            </div>
        </div>

        <div class="alert-banner warning" style="text-align:left;">
            <i class="fa fa-exclamation-triangle"></i>
            <span><strong>Important:</strong> change the default password right after your first login.</span>
        </div>

        <a href="/login" class="btn btn-accent">Go to login <i class="fa fa-arrow-right"></i></a>

    </div>

@endsection
