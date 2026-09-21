@extends('setup.main')
@section('content')

    <div class="finish-wrap">

        <div class="error-mark">
            <i class="fa fa-times"></i>
        </div>

        <p class="eyebrow">Step 4 of 4</p>
        <h1>Installation failed</h1>
        <p class="lead-text">The installer stopped because of the error below. Fix the cause, then retry — the installation always restarts from a clean state.</p>

        <div class="error-box">{{ $message }}</div>

        <div class="alert-banner warning" style="text-align:left;">
            <i class="fa fa-info-circle"></i>
            <span><strong>Common causes:</strong> the database user is missing privileges (needs full access, including CREATE and DROP), the MySQL server version is too old, or the connection dropped mid-install. Your settings were saved — retrying will not lose them.</span>
        </div>

        <div class="pane-nav">
            <a href="/setup/step-2" class="btn btn-ghost"><i class="fa fa-angle-left"></i> Database settings</a>
            <a href="/setup/step-3" class="btn btn-accent"><i class="fa fa-refresh"></i> Retry installation</a>
        </div>

    </div>

@endsection
