{{-- A works gallery with its filter buttons (MixItUp). $projects, $categories, $showAll, $uid. --}}
<section class="gallery">
  <div class="container-fluid">
    @if ($categories->count())
      <div class="row">
        <div class="controls" data-for="mix-{{ $uid }}">
          @if ($showAll)
            <button type="button" class="control" data-filter="all">All</button>
          @endif
          @foreach ($categories as $c)
            <button type="button" class="control" data-filter=".cat-{{ $c->slug }}">{{ $c->name }}</button>
          @endforeach
        </div>
      </div>
    @endif

    <div class="row">
      @if ($projects->isEmpty())
        <div class="col-12 pf-empty">No works published yet.</div>
      @else
        <div class="fw mix-container home-gallery" id="mix-{{ $uid }}">
          @foreach ($projects as $p)
            <div class="mix {{ collect($p->category_slugs)->map(fn ($s) => 'cat-'.$s)->implode(' ') }}">
              <a href="{{ route('portfolio.project', [$site->slug, $p->slug]) }}" class="thumb-a">
                <div class="item-hover">
                  <div class="hover-text"><h3>{{ $p->title }}</h3></div>
                </div>
                <div class="item-img">
                  <img src="{{ asset($p->cover) }}" alt="{{ $p->title }}" loading="lazy">
                </div>
              </a>
            </div>
          @endforeach
        </div>
      @endif
    </div>
  </div>
</section>
