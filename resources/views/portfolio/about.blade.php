{{-- Portfolio module: About page. --}}
@extends('portfolio.layout')

@section('title', $site->about_title ?: 'About')

@section('content')
  @php
    // The text in two columns, like the example (split at the paragraph closest to the middle).
    $paras = preg_split('/\R\s*\R/', trim((string) $site->about_text)) ?: [];
    $paras = array_values(array_filter($paras, fn ($p) => trim($p) !== ''));
    $half = (int) ceil(count($paras) / 2);
    $cols = count($paras) > 1 ? [array_slice($paras, 0, $half), array_slice($paras, $half)] : [$paras];
  @endphp
  <section class="about-me">
    <div class="container">
      <div class="section-title">
        <h2>{{ $site->about_title ?: 'About' }}</h2>
        @if ($site->about_subtitle)<p>{{ $site->about_subtitle }}</p>@endif
      </div>
      @if ($site->about_image)
        <div class="col-md-6 offset-md-3">
          <img src="{{ asset($site->about_image) }}" alt="{{ $site->about_name ?: $site->name }}" class="img-fluid img-center">
        </div>
      @endif
      <div class="row">
        @if ($site->about_name)
          <div class="col-md-12 my-name"><h2>{{ $site->about_name }}</h2></div>
        @endif
        @foreach ($cols as $i => $col)
          <div class="{{ count($cols) > 1 ? 'col-md-4' : 'col-md-8' }} {{ $i === 0 ? 'offset-md-2' : '' }}">
            @foreach ($col as $p)
              <p class="text-justify">{!! nl2br(e(trim($p))) !!}</p>
            @endforeach
          </div>
        @endforeach

        @if (count($site->skills))
          <div class="col-md-8 offset-md-2">
            <div class="progress-bars">
              @foreach ($site->skills as $s)
                <div class="p-bar" style="width:{{ max(0, min(100, (int) ($s['pct'] ?? 0))) }}%">
                  <p>{{ $s['label'] ?? '' }}</p>
                  <p class="text-right">{{ (int) ($s['pct'] ?? 0) }}%</p>
                </div>
              @endforeach
            </div>
          </div>
        @endif
      </div>
    </div>
  </section>
@endsection
