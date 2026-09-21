@extends('setup.main')
@section('content')
    <meta name="csrf_token" content="{{ csrf_token() }}" />

    <div class="loader d-none">Loading...</div>
    <p class="loader loader-caption d-none">Installing Stocky — writing configuration and preparing the database. This can take a few minutes, keep this tab open.</p>

    <div class="d-block" id="content">

        <p class="eyebrow">Step 4 of 4</p>
        <h1>Review &amp; install</h1>
        <p class="lead-text">These settings will be written to your .env file, then the database will be prepared.</p>

        <form action="{{ route('lastStep') }}" method="post">
            @csrf

            <div class="summary-list" id="tochange">

                @if($data['APP_NAME'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Application name</div>
                            <div class="col-12 col-md-7">{{ $data['APP_NAME'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['APP_KEY'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Application key</div>
                            <div class="col-12 col-md-7">{{ $data['APP_KEY'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['APP_DEBUG'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Debug mode</div>
                            <div class="col-12 col-md-7">{{ $data['APP_DEBUG'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_HOST'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Database host</div>
                            <div class="col-12 col-md-7">{{ $data['DB_HOST'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_DATABASE'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Database name</div>
                            <div class="col-12 col-md-7">{{ $data['DB_DATABASE'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_USERNAME'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Database username</div>
                            <div class="col-12 col-md-7">{{ $data['DB_USERNAME'] }}</div>
                        </div>
                    </div>
                @endif

            </div>

            <div class="pane-nav">
                <a href="/setup/step-2" class="btn btn-ghost"><i class="fa fa-angle-left"></i> Back</a>
                <button type="submit" class="btn btn-success" id="lastStep">Install Stocky <i class="fa fa-check"></i></button>
            </div>
        </form>
    </div>

@endsection
