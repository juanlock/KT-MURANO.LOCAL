@extends('update.main')
@section('content')
    <div class="card" style="text-align: center">
        <div class="success-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 12.5l5.5 5.5L20 6.5"></path>
            </svg>
        </div>
        <div class="eyebrow">All done</div>
        <h1>Update complete</h1>
        <p class="lead">
            Your database is up to date and permissions have been refreshed.
        </p>

        <hr class="divider">

        <div class="note" style="text-align: left">
            <span class="note-icon">&#9888;</span>
            <span><strong>Hard-refresh your browser</strong> after opening the app
            (Ctrl&nbsp;+&nbsp;Shift&nbsp;+&nbsp;R, or Cmd&nbsp;+&nbsp;Shift&nbsp;+&nbsp;R on Mac).
            If a page looks blank or broken, clear your browser cache — it's still serving old files.</span>
        </div>

        <a href="/" class="btn" style="margin-top: 20px; text-decoration: none">Open Stocky</a>
    </div>
@endsection
