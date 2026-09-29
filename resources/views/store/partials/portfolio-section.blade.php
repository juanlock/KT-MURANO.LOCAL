{{--
  Store -> Portfolio as a front-page section: group filter tabs, picture
  grid, lightbox. Self-contained (own scoped styles + script, no Tailwind or
  framework) so it looks the same inside the store AND dropped into an
  imported website's front page (App\Services\Portfolio::injectInto()).
  $groups, $items, $title, $subtitle, $sectionId, $inStore
--}}
@php
  $uid = 'mupf'.substr(md5($sectionId.microtime()), 0, 6);
  $pfData = $items->map(fn ($it) => ['src' => asset($it->image), 'title' => $it->title, 'sub' => $it->subtitle, 'link' => $it->link])->values();
@endphp
<section id="{{ $sectionId }}" class="mu-pf" data-mu-pf="{{ $uid }}"
  @if($inStore) style="--mu-accent: rgb(var(--color-accent-500)); --mu-fg: rgb(var(--color-fg-primary)); --mu-muted: rgb(var(--color-fg-muted));" @endif>
  <style>
    .mu-pf{padding:56px 16px;font-family:inherit;color:var(--mu-fg,inherit);clear:both}
    .mu-pf *{box-sizing:border-box}
    .mu-pf .mu-pf-wrap{max-width:1160px;margin:0 auto}
    .mu-pf .mu-pf-head{text-align:center;margin-bottom:24px}
    .mu-pf .mu-pf-title{font-size:2rem;line-height:1.2;font-weight:700;margin:0;color:inherit;letter-spacing:normal;text-transform:none}
    .mu-pf .mu-pf-sub{margin:8px 0 0;opacity:.7;font-size:1rem}
    .mu-pf .mu-pf-tabs{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:0 0 24px;padding:0;list-style:none}
    .mu-pf .mu-pf-tab{border:1px solid currentColor;background:transparent;color:inherit;opacity:.75;border-radius:999px;padding:7px 16px;font:inherit;font-size:.9rem;cursor:pointer;line-height:1.2}
    .mu-pf .mu-pf-tab:hover{opacity:1}
    .mu-pf .mu-pf-tab.is-on{opacity:1;background:var(--mu-accent,#1677ff);border-color:var(--mu-accent,#1677ff);color:#fff}
    .mu-pf .mu-pf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:0;padding:0;list-style:none}
    .mu-pf .mu-pf-item{position:relative;margin:0;border-radius:10px;overflow:hidden;aspect-ratio:4/3;background:#eee;cursor:zoom-in}
    .mu-pf .mu-pf-item[hidden]{display:none}
    .mu-pf .mu-pf-item img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease;max-width:none;margin:0;border:0}
    .mu-pf .mu-pf-item:hover img{transform:scale(1.05)}
    .mu-pf .mu-pf-cap{position:absolute;left:0;right:0;bottom:0;padding:28px 14px 12px;color:#fff;background:linear-gradient(transparent,rgba(0,0,0,.75));opacity:0;transition:opacity .25s;text-align:left}
    .mu-pf .mu-pf-item:hover .mu-pf-cap,.mu-pf .mu-pf-item:focus-visible .mu-pf-cap{opacity:1}
    .mu-pf .mu-pf-cap b{display:block;font-size:1rem;font-weight:600;color:#fff}
    .mu-pf .mu-pf-cap span{display:block;font-size:.8rem;opacity:.85;color:#fff}
    @media (hover:none){.mu-pf .mu-pf-cap{opacity:1}}
    .mu-pf-lb{position:fixed;inset:0;z-index:2147483600;background:rgba(0,0,0,.9);display:flex;align-items:center;justify-content:center;padding:3rem 4rem}
    .mu-pf-lb[hidden]{display:none}
    .mu-pf-lb figure{margin:0;text-align:center;max-width:100%}
    .mu-pf-lb img{max-width:100%;max-height:calc(100vh - 9rem);object-fit:contain;border-radius:8px}
    .mu-pf-lb figcaption{color:#fff;margin-top:12px;font-size:1rem}
    .mu-pf-lb figcaption small{display:block;opacity:.75;font-size:.85rem}
    .mu-pf-lb button{position:absolute;color:#fff;background:rgba(255,255,255,.14);border:0;border-radius:999px;width:44px;height:44px;font-size:28px;line-height:44px;cursor:pointer;padding:0}
    .mu-pf-lb button:hover{background:rgba(255,255,255,.28)}
    .mu-pf-lb .x{top:16px;right:16px}.mu-pf-lb .p{left:16px;top:50%;transform:translateY(-50%)}.mu-pf-lb .n{right:16px;top:50%;transform:translateY(-50%)}
    @media (max-width:640px){.mu-pf-lb{padding:3.5rem .5rem}.mu-pf-lb .p,.mu-pf-lb .n{top:auto;bottom:16px;transform:none}}
  </style>
  <div class="mu-pf-wrap">
    @if($title || $subtitle)
      <div class="mu-pf-head">
        @if($title)<h2 class="mu-pf-title">{{ $title }}</h2>@endif
        @if($subtitle)<p class="mu-pf-sub">{{ $subtitle }}</p>@endif
      </div>
    @endif

    @if($groups->count() > 1)
      <div class="mu-pf-tabs" role="tablist">
        <button type="button" class="mu-pf-tab is-on" data-g="">{{ __('messages.All') !== 'messages.All' ? __('messages.All') : 'All' }}</button>
        @foreach($groups as $g)
          <button type="button" class="mu-pf-tab" data-g="{{ $g->id }}">{{ $g->name }}</button>
        @endforeach
      </div>
    @endif

    <ul class="mu-pf-grid">
      @foreach($items as $i => $it)
        <li class="mu-pf-item" tabindex="0" data-g="{{ $it->group_id }}" data-i="{{ $i }}">
          <img src="{{ asset($it->image) }}" alt="{{ $it->title }}" loading="lazy">
          @if($it->title || $it->subtitle)
            <div class="mu-pf-cap">
              @if($it->title)<b>{{ $it->title }}</b>@endif
              @if($it->subtitle)<span>{{ $it->subtitle }}</span>@endif
            </div>
          @endif
        </li>
      @endforeach
    </ul>
  </div>

  <div class="mu-pf-lb" hidden role="dialog" aria-modal="true">
    <button type="button" class="x" aria-label="Close">&times;</button>
    <button type="button" class="p" aria-label="Previous">&#8249;</button>
    <figure><img alt=""><figcaption></figcaption></figure>
    <button type="button" class="n" aria-label="Next">&#8250;</button>
  </div>

  <script>
  (function () {
    var root = document.querySelector('[data-mu-pf="{{ $uid }}"]');
    if (!root) return;
    var data = @json($pfData);
    var tiles = [].slice.call(root.querySelectorAll('.mu-pf-item'));
    var lb = root.querySelector('.mu-pf-lb'), img = lb.querySelector('img'), cap = lb.querySelector('figcaption');
    document.body.appendChild(lb); // above any site header / transformed parent
    var cur = 0;
    function visible() { return tiles.filter(function (t) { return !t.hidden; }).map(function (t) { return +t.getAttribute('data-i'); }); }
    function show(i) {
      var d = data[i]; cur = i; img.src = d.src; img.alt = d.title || '';
      cap.innerHTML = '';
      if (d.title) cap.appendChild(document.createTextNode(d.title));
      if (d.sub) { var s = document.createElement('small'); s.textContent = d.sub; cap.appendChild(s); }
    }
    function step(dir) { var v = visible(); var k = v.indexOf(cur); show(v[(k + dir + v.length) % v.length]); }
    function open(i) {
      if (data[i].link) { window.location.href = data[i].link; return; }
      show(i); lb.hidden = false; document.documentElement.style.overflow = 'hidden';
    }
    function close() { lb.hidden = true; document.documentElement.style.overflow = ''; }
    tiles.forEach(function (t) {
      t.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); open(+t.getAttribute('data-i')); });
      t.addEventListener('keydown', function (e) { if (e.key === 'Enter') open(+t.getAttribute('data-i')); });
    });
    [].forEach.call(root.querySelectorAll('.mu-pf-tab'), function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        [].forEach.call(root.querySelectorAll('.mu-pf-tab'), function (x) { x.classList.toggle('is-on', x === b); });
        var g = b.getAttribute('data-g');
        tiles.forEach(function (t) { t.hidden = !!g && t.getAttribute('data-g') !== g; });
      });
    });
    lb.querySelector('.x').addEventListener('click', close);
    lb.querySelector('.p').addEventListener('click', function () { step(-1); });
    lb.querySelector('.n').addEventListener('click', function () { step(1); });
    lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
    document.addEventListener('keydown', function (e) {
      if (lb.hidden) return;
      if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') step(-1); else if (e.key === 'ArrowRight') step(1);
    });
  })();
  </script>
</section>
