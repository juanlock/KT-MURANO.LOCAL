@extends('store.realestate.layout')

@section('title', ($property->seo_title ?: $property->title) . ' — ' . ($s->store_name ?? ''))
@section('meta_description', $property->seo_description ?: \Illuminate\Support\Str::limit(strip_tags($property->description), 160))

@push('head')
  @if($property->seo_keywords)<meta name="keywords" content="{{ $property->seo_keywords }}">@endif
@endpush

@section('content')
@php
  use Illuminate\Support\Str;
  $reImg = fn ($p) => $p ? (Str::startsWith($p, ['http://', 'https://', '/']) ? $p : asset($p)) : null;
  $images = collect();
  if($property->featured_image) $images->push($property->featured_image);
  if(is_array($property->gallery)) foreach($property->gallery as $g){ if($g) $images->push($g); }
  $images = $images->unique()->values()->map($reImg);
  $loc = collect([$property->address, $property->city, $property->region])->filter()->implode(', ');
  $amenities = is_array($property->amenities) ? array_values(array_filter($property->amenities)) : [];
  $waNumber = preg_replace('/[^0-9]/', '', (string) $property->agent_whatsapp);
  $areaTxt = !is_null($property->area) ? rtrim(rtrim(number_format((float)$property->area,2),'0'),'.').' '.$property->area_unit : '—';
  $pricePerUnit = ($property->area > 0 && $property->purpose === 'sale') ? $currency.number_format((float)$property->price / (float)$property->area) : null;
@endphp

<div class="re-container" style="padding-top:18px">
  <nav style="font-size:13px;color:var(--re-muted);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span>/</span>
    <a href="{{ route('store.realestate.listings') }}">{{ __('messages.Properties') }}</a><span>/</span>
    @if($property->category)<a href="{{ route('store.realestate.listings', ['category' => $property->category->slug ?? $property->property_category_id]) }}">{{ $property->category->name }}</a><span>/</span>@endif
    <span style="color:var(--re-ink)">{{ Str::limit($property->title, 60) }}</span>
  </nav>
</div>

{{-- GALLERY --}}
<section style="padding:18px 0 0">
  <div class="re-container">
    @if($images->count())
      <div class="re-gallery" style="display:grid;grid-template-columns:2fr 1fr;grid-template-rows:260px 260px;gap:10px;border-radius:20px;overflow:hidden">
        <style>
          .re-gallery > a{position:relative;display:block;background:#e9eef0;overflow:hidden}
          .re-gallery > a img{width:100%;height:100%;object-fit:cover;transition:transform .5s}
          .re-gallery > a:hover img{transform:scale(1.04)}
          .re-gallery > a:first-child{grid-row:1/3}
          @media(max-width:860px){.re-gallery{grid-template-columns:1fr !important;grid-template-rows:auto !important}.re-gallery > a{aspect-ratio:16/10}.re-gallery > a:first-child{grid-row:auto}.re-gallery > a:nth-child(n+3){display:none}}
          .re-lightbox{position:fixed;inset:0;background:rgba(11,26,43,.92);z-index:100;display:none;align-items:center;justify-content:center;padding:20px}
          .re-lightbox.open{display:flex}
          .re-lightbox img{max-width:100%;max-height:90vh;border-radius:12px;object-fit:contain}
          .re-lightbox button{position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.12);border:0;color:#fff;width:46px;height:46px;border-radius:999px;cursor:pointer;font-size:22px}
          .re-lightbox .prev{left:20px}.re-lightbox .next{right:20px}.re-lightbox .close{top:20px;right:20px;transform:none;font-size:18px}
        </style>
        @foreach($images->take(3) as $i => $img)
          <a href="{{ $img }}" onclick="reOpen({{ $i }});return false;">
            <img src="{{ $img }}" alt="{{ $property->title }}" loading="{{ $i === 0 ? 'eager' : 'lazy' }}">
            @if($i === 2 && $images->count() > 3)
              <span style="position:absolute;inset:0;background:rgba(11,26,43,.55);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;gap:8px"><x-store.icon name="image" class="re-icon" />+{{ $images->count() - 3 }} {{ __('messages.Photos') }}</span>
            @endif
          </a>
        @endforeach
      </div>
      <div class="re-lightbox" id="reLightbox" onclick="if(event.target===this)reClose()">
        <button class="close" onclick="reClose()">✕</button>
        <button class="prev" onclick="reStep(-1)">‹</button>
        <img id="reLbImg" src="" alt="">
        <button class="next" onclick="reStep(1)">›</button>
      </div>
      <script>
        var RE_IMGS=@json($images->values()), reIdx=0;
        function reOpen(i){reIdx=i;document.getElementById('reLbImg').src=RE_IMGS[i];document.getElementById('reLightbox').classList.add('open');}
        function reClose(){document.getElementById('reLightbox').classList.remove('open');}
        function reStep(d){reIdx=(reIdx+d+RE_IMGS.length)%RE_IMGS.length;document.getElementById('reLbImg').src=RE_IMGS[reIdx];}
        document.addEventListener('keydown',function(e){if(e.key==='Escape')reClose();if(e.key==='ArrowRight')reStep(1);if(e.key==='ArrowLeft')reStep(-1);});
      </script>
    @else
      <div style="border-radius:20px;background:#e9eef0;height:340px;display:flex;align-items:center;justify-content:center;color:#9aa7af"><x-store.icon name="home" style="width:54px;height:54px" /></div>
    @endif
  </div>
</section>

{{-- HEADER --}}
<section style="padding:26px 0 0">
  <div class="re-container">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
          @if($property->featured)<span class="re-badge re-badge-featured"><x-store.icon name="star-fill" class="w-3 h-3" />{{ __('messages.Featured') }}</span>@endif
          <span class="re-badge {{ $property->purpose==='rent' ? 're-badge-soft-blue' : 're-badge-soft' }}">{{ $property->purpose==='rent' ? __('messages.ForRent') : __('messages.ForSale') }}</span>
          @if($property->status==='sold')<span class="re-badge re-badge-sold">{{ __('messages.Sold') }}</span>@endif
          @if($property->status==='rented')<span class="re-badge re-badge-rented">{{ __('messages.Rented') }}</span>@endif
          @if($property->category)<span class="re-badge" style="background:var(--re-bg);color:var(--re-muted)">{{ $property->category->name }}</span>@endif
        </div>
        <h1 style="margin:0;font-size:32px;font-weight:600;line-height:1.15">{{ $property->title }}</h1>
        @if($loc)<div class="re-card-loc" style="font-size:15px;margin-top:8px"><x-store.icon name="map-pin" />{{ $loc }}</div>@endif
      </div>
      <div style="text-align:end">
        <div style="font-size:32px;font-weight:800;color:var(--re-accent);letter-spacing:-.01em">{{ $currency }}{{ number_format((float)$property->price) }}@if($property->purpose==='rent')<span style="font-size:15px;color:var(--re-muted);font-weight:500"> /{{ __('messages.Month') }}</span>@endif</div>
        @if($pricePerUnit)<div style="color:var(--re-muted);font-size:13px">{{ $pricePerUnit }} / {{ $property->area_unit }}</div>@endif
      </div>
    </div>
    {{-- key facts strip --}}
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
      @foreach([['bed', $property->bedrooms, __('messages.Bedrooms')], ['bath', $property->bathrooms, __('messages.Bathrooms')], ['ruler', $areaTxt, __('messages.Area')], ['package', $property->garage, __('messages.Garage')]] as [$ic, $val, $lab])
        @if(!is_null($val) && $val !== '—')
          <div style="display:inline-flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--re-line);border-radius:12px;padding:10px 14px">
            <x-store.icon :name="$ic" style="width:20px;height:20px;color:var(--re-accent)" />
            <div style="line-height:1.15"><b style="font-size:15px">{{ $val }}</b><div style="font-size:11.5px;color:var(--re-muted)">{{ $lab }}</div></div>
          </div>
        @endif
      @endforeach
    </div>
  </div>
</section>

{{-- BODY --}}
<section class="re-section" style="padding-top:26px">
  <div class="re-container re-show-layout" style="display:grid;grid-template-columns:1fr 380px;gap:28px;align-items:start">
    <style>@media(max-width:960px){.re-show-layout{grid-template-columns:1fr !important}.re-show-layout aside{position:static !important}}</style>

    <div>
      {{-- Description --}}
      @if($property->description)
        <div class="re-card-pad" style="margin-bottom:22px">
          <h3 style="margin:0 0 12px;font-size:20px">{{ __('messages.Description') }}</h3>
          <div style="color:#3a4653;font-size:15px;white-space:pre-line;line-height:1.75">{{ $property->description }}</div>
        </div>
      @endif

      {{-- Details --}}
      <div class="re-card-pad" style="margin-bottom:22px">
        <h3 style="margin:0 0 16px;font-size:20px">{{ __('messages.PropertyDetails') }}</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 24px">
          @php
            $rows = [
              [__('messages.PropertyType'), $property->category->name ?? '—'],
              [__('messages.Price'), $currency.number_format((float)$property->price).($property->purpose==='rent' ? ' /'.__('messages.Month') : '')],
              [__('messages.Area'), $areaTxt],
              [__('messages.Bedrooms'), $property->bedrooms ?? '—'],
              [__('messages.Bathrooms'), $property->bathrooms ?? '—'],
              [__('messages.Garage'), $property->garage ?? '—'],
              [__('messages.Location'), $property->city ?: '—'],
              [__('messages.Status'), __('messages.'.ucfirst($property->status))],
              [__('messages.Purpose'), $property->purpose === 'rent' ? __('messages.ForRent') : __('messages.ForSale')],
              ['ID', '#'.$property->id],
            ];
          @endphp
          @foreach($rows as $r)
            <div style="display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px dashed var(--re-line);font-size:14px"><span style="color:var(--re-muted)">{{ $r[0] }}</span><b>{{ $r[1] }}</b></div>
          @endforeach
        </div>
      </div>

      {{-- Amenities --}}
      @if(count($amenities))
        <div class="re-card-pad" style="margin-bottom:22px">
          <h3 style="margin:0 0 14px;font-size:20px">{{ __('messages.FeaturesAmenities') }}</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px">
            @foreach($amenities as $a)
              <div style="display:flex;align-items:center;gap:9px;font-size:14px;background:var(--re-bg);border-radius:10px;padding:9px 12px"><x-store.icon name="check-circle" style="width:16px;height:16px;color:var(--re-accent)" />{{ $a }}</div>
            @endforeach
          </div>
        </div>
      @endif

      {{-- Map --}}
      @if($loc)
        <div class="re-card-pad">
          <h3 style="margin:0 0 12px;font-size:20px">{{ __('messages.LocationMap') }}</h3>
          <div style="border-radius:14px;overflow:hidden;aspect-ratio:16/9;background:var(--re-bg)">
            @php $mapQ = ($property->latitude && $property->longitude) ? $property->latitude.','.$property->longitude : $loc; @endphp
            <iframe src="https://www.google.com/maps?q={{ urlencode($mapQ) }}&z=14&output=embed" style="width:100%;height:100%;border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
          </div>
        </div>
      @endif
    </div>

    {{-- RIGHT: agent + inquiry --}}
    <aside style="position:sticky;top:96px;display:flex;flex-direction:column;gap:18px">
      <div class="re-card-pad" style="padding:0;overflow:hidden">
        <div style="background:var(--re-navy);color:#fff;padding:18px 22px;display:flex;align-items:center;gap:14px">
          <div style="width:54px;height:54px;border-radius:50%;background:var(--re-gold);color:#1d1a10;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;flex-shrink:0">
            {{ strtoupper(mb_substr($property->agent_name ?: ($s->store_name ?? 'A'), 0, 1)) }}
          </div>
          <div>
            <div style="font-weight:700;font-size:16px">{{ $property->agent_name ?: ($s->store_name ?? __('messages.SalesTeam')) }}</div>
            <div style="font-size:13px;opacity:.8">{{ __('messages.PropertyAgent') }}</div>
          </div>
        </div>
        <div style="padding:16px 22px 20px;display:flex;flex-direction:column;gap:8px">
          @php $phone = $property->agent_phone ?: ($s->contact_phone ?? null); $email = $property->agent_email ?: ($s->contact_email ?? null); @endphp
          @if($phone)<a class="re-btn re-btn-ghost re-btn-block" href="tel:{{ preg_replace('/\s+/','',$phone) }}"><x-store.icon name="phone" />{{ $phone }}</a>@endif
          @if($email)<a class="re-btn re-btn-ghost re-btn-block" href="mailto:{{ $email }}"><x-store.icon name="mail" />{{ __('messages.EmailAgent') }}</a>@endif
          @if($waNumber)
            <a class="re-btn re-btn-wa re-btn-block" target="_blank" rel="noopener" href="https://wa.me/{{ $waNumber }}?text={{ urlencode(__('messages.WhatsAppInquiryPrefix').' '.$property->title) }}"><x-store.icon name="whatsapp" />{{ __('messages.WhatsAppInquiry') }}</a>
          @endif
        </div>
      </div>

      <div class="re-card-pad">
        <h3 style="margin:0 0 4px;font-size:18px;font-family:'Inter',sans-serif;font-weight:700">{{ __('messages.SendInquiry') }}</h3>
        <p style="margin:0 0 14px;font-size:13px;color:var(--re-muted)">{{ __('messages.InquiryIntro') }}</p>
        <div id="reInquiryAlert" class="re-alert re-hidden"></div>
        <form id="reInquiryForm" method="POST" action="{{ route('store.realestate.inquiry') }}">
          @csrf
          <input type="hidden" name="property_id" value="{{ $property->id }}">
          <div class="re-field">
            <label class="re-label">{{ __('messages.YourName') }} *</label>
            <input class="re-input" type="text" name="name" required>
          </div>
          <div class="re-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <div><label class="re-label">{{ __('messages.Phone') }}</label><input class="re-input" type="text" name="phone"></div>
            <div><label class="re-label">{{ __('messages.Email') }}</label><input class="re-input" type="email" name="email"></div>
          </div>
          <div class="re-field">
            <label class="re-label">{{ __('messages.Message') }} *</label>
            <textarea class="re-textarea" name="message" rows="4" required>{{ __('messages.InquiryDefaultMessage') }} "{{ $property->title }}".</textarea>
          </div>
          <div style="position:absolute;left:-10000px"><input type="text" name="company" tabindex="-1" autocomplete="off"></div>
          <button class="re-btn re-btn-primary re-btn-block" type="submit" id="reInquiryBtn"><x-store.icon name="send" />{{ __('messages.SendInquiry') }}</button>
        </form>
      </div>

      <div style="display:flex;gap:8px">
        <button type="button" class="re-btn re-btn-ghost re-btn-block re-btn-sm" onclick="navigator.share ? navigator.share({title: @json($property->title), url: location.href}) : navigator.clipboard.writeText(location.href)"><x-store.icon name="link" />{{ __('messages.Share') }}</button>
        <button type="button" class="re-btn re-btn-ghost re-btn-block re-btn-sm" onclick="window.print()"><x-store.icon name="printer" />{{ __('messages.Print') }}</button>
      </div>
    </aside>
  </div>
</section>

{{-- RELATED --}}
@if($related->count())
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.YouMayAlsoLike') }}</span><h2 class="re-title">{{ __('messages.RelatedProperties') }}</h2></div>
      <a class="re-btn re-btn-ghost" href="{{ route('store.realestate.listings') }}">{{ __('messages.ViewAll') }}<x-store.icon name="arrow-right" /></a>
    </div>
    <div class="re-grid">
      @foreach($related as $rp)
        @include('store.realestate.partials.card', ['property' => $rp, 'currency' => $currency])
      @endforeach
    </div>
  </div>
</section>
@endif

@push('scripts')
<script>
(function(){
  var form = document.getElementById('reInquiryForm');
  var btn  = document.getElementById('reInquiryBtn');
  var box  = document.getElementById('reInquiryAlert');
  if(!form) return;
  form.addEventListener('submit', function(e){
    e.preventDefault();
    box.className = 're-alert re-hidden';
    var original = btn.innerHTML;
    btn.disabled = true; btn.textContent = '{{ __('messages.Sending') }}';
    fetch(form.action, {
      method:'POST',
      headers:{ 'Accept':'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name=_token]').value },
      body: new FormData(form)
    })
    .then(function(r){ return r.json().then(function(d){ return {ok:r.ok, status:r.status, data:d}; }); })
    .then(function(res){
      if(res.ok){
        box.className = 're-alert re-alert-ok';
        box.textContent = (res.data && res.data.message) || '{{ __('messages.InquirySent') }}';
        form.reset();
        var pid = form.querySelector('input[name=property_id]'); if(pid) pid.value = '{{ $property->id }}';
      } else if(res.status === 422 && res.data && res.data.errors){
        var first = Object.values(res.data.errors)[0];
        box.className = 're-alert re-alert-err';
        box.textContent = (first && first[0]) || '{{ __('messages.SomethingWentWrong') }}';
      } else {
        box.className = 're-alert re-alert-err';
        box.textContent = '{{ __('messages.SomethingWentWrong') }}';
      }
    })
    .catch(function(){ box.className = 're-alert re-alert-err'; box.textContent = '{{ __('messages.NetworkError') }}'; })
    .finally(function(){ btn.disabled = false; btn.innerHTML = original; });
  });
})();
</script>
@endpush
@endsection
