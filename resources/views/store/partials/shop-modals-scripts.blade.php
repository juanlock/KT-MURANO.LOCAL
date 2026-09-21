@php
  $currency = $currency ?? store_currency()['symbol'];
@endphp
@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => __('messages.Subscribe')])
<script>
  document.addEventListener('DOMContentLoaded', function(){ var f = document.getElementById('newsletterForm'); if (f) f.remove(); });
</script>
