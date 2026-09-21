@php
  use App\Models\StoreSetting;
  use Illuminate\Support\Str;
  $currency = $currency ?? optional(StoreSetting::first())->currency_code ?? '$';
  $reImg = function ($p) {
      if (!$p) return null;
      return Str::startsWith($p, ['http://', 'https://', '/']) ? $p : asset($p);
  };
  $img = $reImg($property->featured_image) ?: (is_array($property->gallery) && count($property->gallery) ? $reImg($property->gallery[0]) : null);
  $loc = collect([$property->city, $property->region])->filter()->implode(', ');
  $statusKey = $property->status ?: 'available';
  $areaTxt = !is_null($property->area) ? rtrim(rtrim(number_format((float) $property->area, 2), '0'), '.').' '.$property->area_unit : null;
@endphp
<article class="re-card">
  <a class="re-card-media" href="{{ route('store.realestate.show', $property->slug) }}" aria-label="{{ $property->title }}">
    @if($img)
      <img src="{{ $img }}" alt="{{ $property->title }}" loading="lazy">
    @else
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9aa7af"><x-store.icon name="home" class="w-10 h-10" /></div>
    @endif
    <div class="re-card-tags">
      @if($property->featured)<span class="re-badge re-badge-featured"><x-store.icon name="star-fill" class="w-3 h-3" />{{ __('messages.Featured') }}</span>@endif
      @if($property->purpose === 'rent')
        <span class="re-badge re-badge-rent">{{ __('messages.ForRent') }}</span>
      @else
        <span class="re-badge re-badge-sale">{{ __('messages.ForSale') }}</span>
      @endif
      @if($statusKey === 'sold')<span class="re-badge re-badge-sold">{{ __('messages.Sold') }}</span>@endif
      @if($statusKey === 'rented')<span class="re-badge re-badge-rented">{{ __('messages.Rented') }}</span>@endif
    </div>
    <div class="re-card-price">
      {{ $currency }}{{ number_format((float) $property->price) }}@if($property->purpose === 'rent')<span> /{{ __('messages.Month') }}</span>@endif
    </div>
  </a>
  <div class="re-card-body">
    @if($property->category)
      <span class="re-card-cat">{{ $property->category->name }}</span>
    @endif
    <h3 class="re-card-title">
      <a href="{{ route('store.realestate.show', $property->slug) }}">{{ $property->title }}</a>
    </h3>
    @if($loc)<div class="re-card-loc"><x-store.icon name="map-pin" />{{ $loc }}</div>@endif
    <div class="re-specs">
      @if(!is_null($property->bedrooms))<span><x-store.icon name="bed" /><b>{{ $property->bedrooms }}</b> {{ __('messages.Beds') }}</span>@endif
      @if(!is_null($property->bathrooms))<span><x-store.icon name="bath" /><b>{{ $property->bathrooms }}</b> {{ __('messages.Baths') }}</span>@endif
      @if($areaTxt)<span><x-store.icon name="ruler" /><b>{{ $areaTxt }}</b></span>@endif
    </div>
  </div>
</article>
