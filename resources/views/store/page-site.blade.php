{{-- Store -> Pages: an imported website shown in its ORIGINAL design,
     full-screen at the store's page URL (see StoreFrontController::page). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <style>
        html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #000; }
        iframe { border: 0; width: 100%; height: 100%; display: block; }
    </style>
</head>
<body>
    <iframe src="{{ $src }}" title="{{ $title }}" allowfullscreen></iframe>
</body>
</html>
