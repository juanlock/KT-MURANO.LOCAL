{{--
  Account details for one manual payment method (GCash / bank transfer).
  Shared by checkout, the thank-you page and the order page so the numbers
  the shopper pays to are never written twice.

  @param array $method  a row from StorePaymentMethodService::enabled()
--}}
@if($method)
  @if(!empty($method['instructions']))
    <div class="alert alert-info text-xs mb-3 flex items-start gap-2">
      <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
      <span>{{ $method['instructions'] }}</span>
    </div>
  @endif

  @if(!empty($method['details']))
    <div class="mb-3">
      @foreach($method['details'] as $d)
        <div class="pay-detail-row">
          <span class="min-w-0">
            <span class="block text-[11px] uppercase tracking-wide text-fg-muted">{{ $d['label'] }}</span>
            <span class="block text-sm font-semibold break-all">{{ $d['value'] }}</span>
          </span>
          @if(!empty($d['copy']))
            <button type="button" class="btn btn-ghost btn-sm pay-copy-btn js-copy"
                    data-copy="{{ $d['value'] }}" title="{{ __('messages.Copy') }}">
              <x-store.icon name="copy" class="w-4 h-4" />
            </button>
          @endif
        </div>
      @endforeach
    </div>
  @endif

  @if(!empty($method['qr_url']))
    <div class="mb-3">
      <div class="text-[11px] uppercase tracking-wide text-fg-muted mb-1">{{ __('messages.ScanToPay') }}</div>
      <img src="{{ $method['qr_url'] }}" alt="{{ $method['label'] }} QR" class="pay-qr">
    </div>
  @endif
@endif
