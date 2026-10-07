@extends('layouts.store')

@section('content')
<div class="container py-8 lg:py-12">
  <nav class="text-sm text-fg-muted mb-4 flex items-center gap-1 flex-wrap">
    <a href="{{ !empty($website) ? \App\Support\WebsitePaths::url() : route('store.index') }}" class="hover:text-accent-500">{{ __('messages.Home') }}</a>
    <x-store.icon name="chevron-right" class="w-4 h-4" />
    <span class="text-fg-secondary">{{ __('messages.Gallery') }}</span>
  </nav>

  <h1 class="text-3xl lg:text-4xl font-bold text-fg-primary mb-6">{{ __('messages.Gallery') }}</h1>

  @if($images->isEmpty())
    <p class="text-fg-muted">{{ __('messages.NoPhotosYet') }}</p>
  @else
    <div class="sg-grid">
      @foreach($images as $i => $img)
        <figure class="sg-item">
          <button type="button" class="sg-open" data-index="{{ $i }}" aria-label="{{ $img->title ?: __('messages.Gallery') }}">
            <img src="{{ asset($img->image) }}" alt="{{ $img->title }}" loading="lazy"
                 @if($img->width && $img->height) width="{{ $img->width }}" height="{{ $img->height }}" @endif>
          </button>
          @if($img->title)
            <figcaption>{{ $img->title }}</figcaption>
          @endif
        </figure>
      @endforeach
    </div>

    <div id="sg-lightbox" class="sg-lightbox" hidden role="dialog" aria-modal="true">
      <button type="button" class="sg-close" aria-label="Close">&times;</button>
      <button type="button" class="sg-nav sg-prev" aria-label="Previous">&#8249;</button>
      <figure>
        <img alt="">
        <figcaption></figcaption>
      </figure>
      <button type="button" class="sg-nav sg-next" aria-label="Next">&#8250;</button>
    </div>
  @endif
</div>

<style>
  .sg-grid { columns: 1; column-gap: 1rem; }
  @media (min-width: 640px) { .sg-grid { columns: 2; } }
  @media (min-width: 1024px) { .sg-grid { columns: 3; } }
  .sg-item { break-inside: avoid; margin: 0 0 1rem; }
  .sg-open { display: block; width: 100%; padding: 0; border: 0; background: none; cursor: zoom-in; border-radius: .75rem; overflow: hidden; }
  .sg-open img { display: block; width: 100%; height: auto; transition: transform .3s ease; }
  .sg-open:hover img, .sg-open:focus-visible img { transform: scale(1.03); }
  .sg-item figcaption { margin-top: .4rem; font-size: .875rem; color: rgb(var(--color-fg-secondary)); }
  .sg-lightbox { position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.9); display: flex; align-items: center; justify-content: center; padding: 3rem 4rem; }
  .sg-lightbox[hidden] { display: none; }
  .sg-lightbox figure { margin: 0; max-width: 100%; max-height: 100%; text-align: center; }
  .sg-lightbox img { max-width: 100%; max-height: calc(100vh - 8rem); object-fit: contain; border-radius: .5rem; }
  .sg-lightbox figcaption { color: #fff; margin-top: .75rem; font-size: .95rem; }
  .sg-close, .sg-nav { position: absolute; color: #fff; background: rgba(255,255,255,.12); border: 0; border-radius: 999px; width: 44px; height: 44px; font-size: 28px; line-height: 44px; cursor: pointer; }
  .sg-close:hover, .sg-nav:hover { background: rgba(255,255,255,.25); }
  .sg-close { top: 1rem; right: 1rem; }
  .sg-prev { left: 1rem; top: 50%; transform: translateY(-50%); }
  .sg-next { right: 1rem; top: 50%; transform: translateY(-50%); }
  @media (max-width: 640px) { .sg-lightbox { padding: 3.5rem .5rem; } .sg-nav { top: auto; bottom: 1rem; transform: none; } }
</style>

@if($images->isNotEmpty())
<script>
(function () {
  var items = @json($images->map(fn ($g) => ['src' => asset($g->image), 'title' => $g->title])->values());
  var box = document.getElementById('sg-lightbox'), img = box.querySelector('img'), cap = box.querySelector('figcaption'), cur = 0;
  function show(i) { cur = (i + items.length) % items.length; img.src = items[cur].src; img.alt = items[cur].title || ''; cap.textContent = items[cur].title || ''; }
  function open(i) { show(i); box.hidden = false; document.body.style.overflow = 'hidden'; box.querySelector('.sg-close').focus(); }
  function close() { box.hidden = true; document.body.style.overflow = ''; }
  document.querySelectorAll('.sg-open').forEach(function (b) { b.addEventListener('click', function () { open(Number(b.dataset.index)); }); });
  box.querySelector('.sg-close').addEventListener('click', close);
  box.querySelector('.sg-prev').addEventListener('click', function () { show(cur - 1); });
  box.querySelector('.sg-next').addEventListener('click', function () { show(cur + 1); });
  box.addEventListener('click', function (e) { if (e.target === box) close(); });
  document.addEventListener('keydown', function (e) {
    if (box.hidden) return;
    if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') show(cur - 1); else if (e.key === 'ArrowRight') show(cur + 1);
  });
})();
</script>
@endif
@endsection
