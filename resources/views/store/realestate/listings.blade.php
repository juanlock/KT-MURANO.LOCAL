@extends('store.realestate.layout')

@section('title', __('messages.Properties') . ' — ' . ($s->store_name ?? ''))

@section('content')
@php
  use Illuminate\Support\Str;
  $f = $filters;
  $reImg = fn ($p) => $p ? (Str::startsWith($p, ['http://', 'https://', '/']) ? $p : asset($p)) : null;
  $pageTitle = $f['purpose'] === 'rent' ? __('messages.PropertiesForRent') : ($f['purpose'] === 'sale' ? __('messages.PropertiesForSale') : __('messages.PropertyListings'));
@endphp

<section style="background:var(--re-navy);color:#fff;padding:44px 0;position:relative;overflow:hidden">
  <div style="position:absolute;inset:0;background:radial-gradient(600px 300px at 90% 0%,rgba(var(--re-gold-rgb),.25),transparent 70%)"></div>
  <div class="re-container" style="position:relative">
    <nav style="font-size:13px;color:rgba(255,255,255,.7);display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
      <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span>/</span><span style="color:#fff">{{ __('messages.Properties') }}</span>
    </nav>
    <h1 style="margin:0;font-size:34px;font-weight:600">{{ $pageTitle }}</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.8)">{{ $properties->total() }} {{ __('messages.PropertiesFound') }}@if($f['location']) · <x-store.icon name="map-pin" class="w-3.5 h-3.5" style="display:inline;vertical-align:-2px;color:var(--re-gold)" /> {{ $f['location'] }}@endif</p>
  </div>
</section>

<section class="re-section" style="padding-top:34px">
  <div class="re-container re-listing-layout" style="display:grid;grid-template-columns:300px 1fr;gap:28px;align-items:start">
    <style>@media(max-width:900px){.re-listing-layout{grid-template-columns:1fr !important}.re-listing-layout aside{position:static !important}}</style>

    {{-- FILTERS --}}
    <aside class="re-card-pad" style="position:sticky;top:96px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <h3 style="margin:0;font-size:17px;font-family:'Inter',sans-serif;font-weight:700;display:flex;align-items:center;gap:8px"><x-store.icon name="sliders" class="re-icon" style="color:var(--re-accent)" />{{ __('messages.SearchFilters') }}</h3>
        <a href="{{ route('store.realestate.listings') }}" style="font-size:13px;color:var(--re-accent);font-weight:600">{{ __('messages.Reset') }}</a>
      </div>
      <form method="GET" action="{{ route('store.realestate.listings') }}">
        <input type="hidden" name="view" value="{{ $f['view'] }}">
        <div class="re-field">
          <label class="re-label">{{ __('messages.Keyword') }}</label>
          <input class="re-input" type="text" name="q" value="{{ $f['q'] }}" placeholder="{{ __('messages.PropertyTitle') }}">
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.Purpose') }}</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
            @foreach(['' => __('messages.All'), 'sale' => __('messages.Buy'), 'rent' => __('messages.Rent')] as $val => $label)
              <label style="display:block">
                <input type="radio" name="purpose" value="{{ $val }}" @checked($f['purpose'] === $val) style="display:none" onchange="this.form.submit()">
                <span class="re-btn re-btn-block re-btn-sm {{ $f['purpose'] === $val ? 're-btn-dark' : 're-btn-ghost' }}">{{ $label }}</span>
              </label>
            @endforeach
          </div>
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.PropertyType') }}</label>
          <select class="re-select" name="category">
            <option value="">{{ __('messages.AllTypes') }}</option>
            @foreach($categories as $c)
              <option value="{{ $c->slug }}" @selected($f['category'] == $c->slug || $f['category'] == $c->id)>{{ $c->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.Location') }}</label>
          <input class="re-input" type="text" name="location" value="{{ $f['location'] }}" placeholder="{{ __('messages.CityOrRegion') }}">
        </div>
        <div class="re-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div>
            <label class="re-label">{{ __('messages.MinPrice') }}</label>
            <input class="re-input" type="number" name="min_price" value="{{ $f['minPrice'] }}" min="0" placeholder="0">
          </div>
          <div>
            <label class="re-label">{{ __('messages.MaxPrice') }}</label>
            <input class="re-input" type="number" name="max_price" value="{{ $f['maxPrice'] }}" min="0" placeholder="∞">
          </div>
        </div>
        <div class="re-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div>
            <label class="re-label">{{ __('messages.Bedrooms') }}</label>
            <select class="re-select" name="bedrooms">
              <option value="">{{ __('messages.Any') }}</option>
              @foreach([1,2,3,4,5] as $n)
                <option value="{{ $n }}" @selected($f['bedrooms']==$n)>{{ $n }}+</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="re-label">{{ __('messages.Bathrooms') }}</label>
            <select class="re-select" name="bathrooms">
              <option value="">{{ __('messages.Any') }}</option>
              @foreach([1,2,3,4] as $n)
                <option value="{{ $n }}" @selected($f['bathrooms']==$n)>{{ $n }}+</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.MinArea') }}</label>
          <input class="re-input" type="number" name="min_area" value="{{ $f['minArea'] }}" min="0">
        </div>
        <button class="re-btn re-btn-primary re-btn-block" type="submit"><x-store.icon name="filter" />{{ __('messages.ApplyFilters') }}</button>
      </form>
    </aside>

    {{-- RESULTS --}}
    <div>
      <div class="re-card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;padding:10px 14px;flex-wrap:wrap">
        <div style="display:flex;gap:6px">
          @php
            $gridQs = array_merge(request()->query(), ['view'=>'grid']);
            $listQs = array_merge(request()->query(), ['view'=>'list']);
          @endphp
          <a class="re-btn re-btn-sm {{ $f['view']==='grid' ? 're-btn-dark':'re-btn-ghost' }}" href="?{{ http_build_query($gridQs) }}"><x-store.icon name="grid" />{{ __('messages.Grid') }}</a>
          <a class="re-btn re-btn-sm {{ $f['view']==='list' ? 're-btn-dark':'re-btn-ghost' }}" href="?{{ http_build_query($listQs) }}"><x-store.icon name="list" />{{ __('messages.List') }}</a>
        </div>
        <form method="GET" action="{{ route('store.realestate.listings') }}" style="display:flex;align-items:center;gap:8px">
          @foreach(['q','category','purpose','location','min_price','max_price','bedrooms','bathrooms','min_area','view'] as $hk)
            @if(request()->filled($hk))<input type="hidden" name="{{ $hk }}" value="{{ request($hk) }}">@endif
          @endforeach
          <label class="re-label" style="margin:0;color:var(--re-muted)">{{ __('messages.SortBy') }}</label>
          <select class="re-select" name="sort" onchange="this.form.submit()" style="width:auto;padding-top:8px;padding-bottom:8px">
            <option value="latest" @selected($f['sort']==='latest')>{{ __('messages.Newest') }}</option>
            <option value="price_asc" @selected($f['sort']==='price_asc')>{{ __('messages.PriceLowToHigh') }}</option>
            <option value="price_desc" @selected($f['sort']==='price_desc')>{{ __('messages.PriceHighToLow') }}</option>
          </select>
        </form>
      </div>

      @if($properties->count())
        @if($f['view']==='list')
          <div style="display:flex;flex-direction:column;gap:18px">
            @foreach($properties as $property)
              @php
                $img = $reImg($property->featured_image) ?: (is_array($property->gallery) && count($property->gallery) ? $reImg($property->gallery[0]) : null);
                $loc = collect([$property->city,$property->region])->filter()->implode(', ');
              @endphp
              <article class="re-card re-list-row">
                <a class="re-card-media" href="{{ route('store.realestate.show', $property->slug) }}">
                  @if($img)<img src="{{ $img }}" alt="{{ $property->title }}" loading="lazy">@endif
                  <div class="re-card-tags">
                    @if($property->featured)<span class="re-badge re-badge-featured">{{ __('messages.Featured') }}</span>@endif
                    <span class="re-badge {{ $property->purpose==='rent' ? 're-badge-rent' : 're-badge-sale' }}">{{ $property->purpose==='rent' ? __('messages.ForRent') : __('messages.ForSale') }}</span>
                  </div>
                </a>
                <div class="re-card-body" style="padding:20px 22px">
                  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
                    <span class="re-card-cat">{{ $property->category->name ?? '' }}</span>
                    <span style="font-weight:800;color:var(--re-accent);font-size:20px">{{ $currency }}{{ number_format((float)$property->price) }}@if($property->purpose==='rent')<span style="font-weight:500;color:var(--re-muted);font-size:13px"> /{{ __('messages.Month') }}</span>@endif</span>
                  </div>
                  <h3 class="re-card-title" style="font-size:19px"><a href="{{ route('store.realestate.show', $property->slug) }}">{{ $property->title }}</a></h3>
                  @if($loc)<div class="re-card-loc"><x-store.icon name="map-pin" />{{ $loc }}</div>@endif
                  <p style="color:var(--re-muted);font-size:14px;margin:0;line-height:1.6">{{ Str::limit(strip_tags($property->description), 170) }}</p>
                  <div class="re-specs">
                    @if(!is_null($property->bedrooms))<span><x-store.icon name="bed" /><b>{{ $property->bedrooms }}</b> {{ __('messages.Beds') }}</span>@endif
                    @if(!is_null($property->bathrooms))<span><x-store.icon name="bath" /><b>{{ $property->bathrooms }}</b> {{ __('messages.Baths') }}</span>@endif
                    @if(!is_null($property->area))<span><x-store.icon name="ruler" /><b>{{ rtrim(rtrim(number_format((float)$property->area,2),'0'),'.') }} {{ $property->area_unit }}</b></span>@endif
                    <a href="{{ route('store.realestate.show', $property->slug) }}" style="margin-inline-start:auto;color:var(--re-accent);font-weight:700;display:inline-flex;align-items:center;gap:4px">{{ __('messages.ViewDetails') }}<x-store.icon name="arrow-right" /></a>
                  </div>
                </div>
              </article>
            @endforeach
          </div>
        @else
          <div class="re-grid cols-2">
            @foreach($properties as $property)
              @include('store.realestate.partials.card', ['property' => $property, 'currency' => $currency])
            @endforeach
          </div>
        @endif

        @if($properties->hasPages())
          <nav class="re-pager" style="margin-top:30px">
            @if($properties->onFirstPage())
              <span class="re-page disabled">‹</span>
            @else
              <a class="re-page" href="{{ $properties->previousPageUrl() }}">‹</a>
            @endif
            @foreach($properties->getUrlRange(max(1,$properties->currentPage()-2), min($properties->lastPage(),$properties->currentPage()+2)) as $page => $url)
              <a class="re-page {{ $page == $properties->currentPage() ? 'active' : '' }}" href="{{ $url }}">{{ $page }}</a>
            @endforeach
            @if($properties->hasMorePages())
              <a class="re-page" href="{{ $properties->nextPageUrl() }}">›</a>
            @else
              <span class="re-page disabled">›</span>
            @endif
          </nav>
        @endif
      @else
        <div class="re-card-pad" style="text-align:center;padding:64px 20px;color:var(--re-muted)">
          <x-store.icon name="search" style="width:42px;height:42px;margin:0 auto;color:var(--re-accent)" />
          <h3 style="margin:14px 0 4px;color:var(--re-ink);font-family:'Inter',sans-serif">{{ __('messages.NoPropertiesFound') }}</h3>
          <p style="margin:0">{{ __('messages.TryAdjustingFilters') }}</p>
        </div>
      @endif
    </div>
  </div>
</section>
@endsection
