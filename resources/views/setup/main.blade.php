<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stocky | Setup</title>

    <link rel="stylesheet" href="/assets_setup/css/bootstrap.css">
    <link rel="icon" href="/assets_setup/favicon.ico">
    <link rel="stylesheet" href="/assets_setup/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets_setup/css/font-awesome-animation.css">
    <link rel="stylesheet" href="/assets_setup/css/style.css">
</head>
<body>

@php
    $setupSteps = [
        1 => ['label' => 'Server requirements', 'desc' => 'PHP version & extensions', 'url' => '/setup'],
        2 => ['label' => 'Application', 'desc' => 'Name, environment & key', 'url' => '/setup/step-1'],
        3 => ['label' => 'Database', 'desc' => 'Connection & credentials', 'url' => '/setup/step-2'],
        4 => ['label' => 'Review & install', 'desc' => 'Confirm and finish', 'url' => '/setup/step-3'],
    ];
    $stepByPath = ['setup' => 1, 'setup/step-1' => 2, 'setup/step-2' => 3, 'setup/step-3' => 4];
    // A view may pin the step explicitly (e.g. the install-error page, rendered
    // from POST /setup/lastStep). Otherwise derive it from the path; the finish
    // page (and anything unmapped) shows every step as done.
    $currentStep = $setupCurrentStep ?? $stepByPath[request()->path()] ?? count($setupSteps) + 1;
@endphp

<div class="setup-shell">
    <div class="setup-card">

        <aside class="setup-rail">
            <div class="brand">
                <div class="brand-mark">S</div>
                <div class="brand-name">Stocky<small>Installer</small></div>
            </div>

            <ol class="rail-steps">
                @foreach($setupSteps as $n => $step)
                    <li class="rail-step {{ $n < $currentStep ? 'is-done' : ($n == $currentStep ? 'is-active' : '') }}">
                        <span class="rail-bullet">
                            @if($n < $currentStep)
                                <i class="fa fa-check"></i>
                            @else
                                {{ $n }}
                            @endif
                        </span>
                        <span class="rail-text">
                            @if($n < $currentStep && $currentStep <= count($setupSteps))
                                <a href="{{ $step['url'] }}">{{ $step['label'] }}</a>
                            @else
                                {{ $step['label'] }}
                            @endif
                            <small>{{ $step['desc'] }}</small>
                        </span>
                    </li>
                @endforeach
            </ol>

            <div class="rail-foot">Your settings are saved to the <strong>.env</strong> file at the project root.</div>
        </aside>

        <main class="setup-pane">
            @yield('content')
        </main>

    </div>

    <div class="setup-foot">Stocky — Ultimate Inventory with POS</div>
</div>

<script src="/assets_setup/js/jquery.min.js"></script>
<script src="/assets_setup/js/tippy.all.min.js"></script>
<script src="/assets_setup/js/scripts.js"></script>

</body>
</html>
