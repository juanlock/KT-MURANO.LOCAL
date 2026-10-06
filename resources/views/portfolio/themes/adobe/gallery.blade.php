{{-- Themes 2 / 3 gallery (MixItUp, same filters as theme 1). $projects, $categories, $showAll, $uid.
     grid: 2 columns, thin gutter, dark veil + title on hover. showcase: one full-width photo per work. --}}
<section class="ap-gallery">
  @if ($categories->count() > 1)
    <div class="controls ap-filters" data-for="mix-{{ $uid }}">
      @if ($showAll)
        <button type="button" class="control" data-filter="all">{{ $site->template === 'grid' ? 'ALL' : 'all' }}</button>
      @endif
      @foreach ($categories as $c)
        <button type="button" class="control" data-filter=".cat-{{ $c->slug }}">{{ $site->template === 'grid' ? mb_strtoupper($c->name) : mb_strtolower($c->name) }}</button>
      @endforeach
    </div>
  @endif

  @if ($projects->isEmpty())
    <div class="pf-empty">No works published yet.</div>
  @else
    <div class="mix-container ap-covers" id="mix-{{ $uid }}">
      @foreach ($projects as $p)
        <a href="{{ route('portfolio.project', [$site->slug, $p->slug]) }}" class="mix ap-cover {{ collect($p->category_slugs)->map(fn ($s) => 'cat-'.$s)->implode(' ') }}">
          <img src="{{ asset($p->cover) }}" alt="{{ $p->title }}" loading="lazy">
          <span class="ap-cover__veil"><span class="ap-cover__title">{{ $p->title }}</span></span>
        </a>
      @endforeach
    </div>
  @endif
</section>
