{{-- Portfolio module: one work -- a single image, a slider (Swiper) or a video, then its details. --}}
@extends($site->layout_view)

@section('title', $project->title)

@push('head')
  @if ($project->type === 'slider' && count($slides) > 1)
    <link rel="stylesheet" href="{{ asset('portfolio-theme/css/swiper.min.css') }}">
  @endif
@endpush

@section('content')
  <section class="single-project">
    <div class="container">
      <div class="row">
        <div class="col-md-12">
          <div class="project-holder">
            @if ($project->type === 'video' && $project->video_url)
              <iframe class="project-video" src="{{ $project->video_url }}" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
            @elseif ($project->type === 'slider' && count($slides) > 1)
              <div class="swiper-container">
                <div class="swiper-wrapper">
                  @foreach ($slides as $img)
                    <div class="swiper-slide"><img src="{{ asset($img) }}" alt="{{ $project->title }}" class="img-fluid"></div>
                  @endforeach
                </div>
                <div class="swiper-button-next"><i class="fas fa-chevron-right"></i></div>
                <div class="swiper-button-prev"><i class="fas fa-chevron-left"></i></div>
              </div>
            @elseif (count($slides))
              <img src="{{ asset($slides[0]) }}" alt="{{ $project->title }}" class="img-fluid">
            @endif
          </div>
        </div>
      </div>

      <div class="project-details">
        <div class="row">
          <div class="col-md-7">
            <div class="details-left">
              <h2>{{ $project->title }}</h2>
              @if ($project->description)
                <p>{!! nl2br(e($project->description)) !!}</p>
              @endif
            </div>
          </div>
          <div class="offset-md-1 col-md-4">
            <div class="details-right">
              @if ($project->client)
                <ul><li>Client</li><li class="devider">:</li><li>{{ $project->client }}</li></ul>
              @endif
              @if ($project->location)
                <ul><li>Location</li><li class="devider">:</li><li>{{ $project->location }}</li></ul>
              @endif
              @if ($project->project_date)
                <ul><li>Date</li><li class="devider">:</li><li>{{ \Carbon\Carbon::parse($project->project_date)->format('F j, Y') }}</li></ul>
              @endif
              @if ($categoryNames)
                <ul><li>Category</li><li class="devider">:</li><li>{{ implode(', ', $categoryNames) }}</li></ul>
              @endif
              @if ($count > 1)
                <div class="project-btns">
                  <a href="{{ route('portfolio.project', [$site->slug, $prev->slug]) }}" class="btn btn-invert mgR-20px">Previous</a>
                  <a href="{{ route('portfolio.project', [$site->slug, $next->slug]) }}" class="btn btn-invert">Next</a>
                </div>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection

@push('scripts')
  @if ($project->type === 'slider' && count($slides) > 1)
    <script src="{{ asset('portfolio-theme/js/swiper.min.js') }}"></script>
    <script>
      new Swiper('.swiper-container', { navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' } });
    </script>
  @endif
@endpush
