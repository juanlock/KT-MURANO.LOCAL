@extends('layouts.store')

@section('content')
@php
  $currency = store_currency()['symbol'];
  $endsAt = optional($sale?->ends_at)->toIso8601String();
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .08), rgb(var(--color-bg-surface)));">
  <div class="container py-6">
    <span class="section-kicker inline-flex items-center gap-1">
      <x-store.icon name="lightning" class="w-4 h-4" />{{ __('messages.FlashSale') }}
    </span>
    <h1 class="section-title mt-1">{{ $sale?->localizedName() ?: __('messages.FlashSale') }}</h1>
    @if($endsAt)
      <div class="text-sm text-fg-muted mt-1" data-flash-ends="{{ $endsAt }}">
        {{ __('messages.EndsIn') }} <span class="flash-countdown font-semibold text-accent-500">—</span>
      </div>
    @endif
  </div>
</section>

<div class="container py-8">
  @if($products->count())
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
      @foreach($products as $p)
        @include('store.partials.product-card', ['p' => $p, 'currency' => $currency])
      @endforeach
    </div>
  @else
    <div class="empty-state py-16 text-center">
      <div class="empty-icon"><x-store.icon name="lightning" class="w-10 h-10" /></div>
      <h3 class="mt-2">{{ __('messages.NoFlashSaleRunning') }}</h3>
      <a href="{{ route('store.shop') }}" class="btn btn-outline mt-4">{{ __('messages.GoToShop') }}</a>
    </div>
  @endif
</div>

<script>
(function(){
  var DAY = @json(__('messages.DayShort'));
  function pad(n){ return String(n).padStart(2,'0'); }
  function tick(){
    document.querySelectorAll('[data-flash-ends]').forEach(function(el){
      var ends = new Date(el.getAttribute('data-flash-ends')).getTime();
      var out = el.querySelector('.flash-countdown');
      if (!out || isNaN(ends)) return;
      var diff = Math.max(0, Math.floor((ends - Date.now())/1000));
      var d = Math.floor(diff/86400), h = Math.floor((diff%86400)/3600), m = Math.floor((diff%3600)/60), s = diff%60;
      out.textContent = (d>0 ? d+DAY+' ' : '') + pad(h)+':'+pad(m)+':'+pad(s);
    });
  }
  tick(); setInterval(tick, 1000);
})();
</script>
@endsection
