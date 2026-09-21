@extends('update.main')
@section('content')
    <div class="card">
        <div class="eyebrow">Final step</div>
        <h1>Update your database</h1>
        <p class="lead">
            Your new application files are in place. This last step upgrades the
            database schema and refreshes system permissions.
        </p>
        @if (!empty($version))
            <span class="chip"><span class="dot"></span> Installing v{{ $version }}</span>
        @endif

        <hr class="divider">

        <ul class="steps" id="steps">
            <li class="step">
                <span class="step-bullet"></span>
                <span>Clear configuration cache<small>Rebuilds cached settings from your .env file</small></span>
            </li>
            <li class="step">
                <span class="step-bullet"></span>
                <span>Run database migrations<small>Adds new tables and columns — existing data is untouched</small></span>
            </li>
            <li class="step">
                <span class="step-bullet"></span>
                <span>Sync roles &amp; permissions<small>Registers permissions for new modules</small></span>
            </li>
            <li class="step">
                <span class="step-bullet"></span>
                <span>Finish up<small>Final checks before you're redirected</small></span>
            </li>
        </ul>

        <hr class="divider">

        <div class="note" id="backup-note">
            <span class="note-icon">&#9888;</span>
            <span><strong>Back up first.</strong> If you haven't already, export your database
            (e.g. from phpMyAdmin) before running the update. Large databases can take a few minutes to migrate.</span>
        </div>

        <form action="{{ route('update_lastStep') }}" method="post" id="update-form" style="margin-top: 20px">
            @csrf
            <button class="btn" type="submit" id="update-btn">Update database</button>
        </form>
    </div>

    <script>
        (function () {
            var form = document.getElementById('update-form');
            var btn = document.getElementById('update-btn');
            var steps = document.querySelectorAll('#steps .step');
            var started = false;

            form.addEventListener('submit', function () {
                if (started) return;
                started = true;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span> Updating&nbsp;&mdash;&nbsp;this can take a few minutes';

                // The request is synchronous, so the rail advances on a timer to
                // show the page is alive; the last step pulses until the server responds.
                var i = 0;
                steps[0].classList.add('is-active');
                var timer = setInterval(function () {
                    if (i >= steps.length - 1) { clearInterval(timer); return; }
                    steps[i].classList.remove('is-active');
                    steps[i].classList.add('is-done');
                    steps[i].querySelector('.step-bullet').innerHTML = '&#10003;';
                    i++;
                    steps[i].classList.add('is-active');
                }, 8000);
            });
        })();
    </script>
@endsection
