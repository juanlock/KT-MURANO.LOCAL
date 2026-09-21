{{--
  Optional proof-of-payment fields shown inside a manual method on checkout.
  The shopper may skip them here and upload later from the order page.

  @param string $code  payment method code (gcash|bank_transfer)
--}}
<div class="rounded-xl border border-line-subtle p-3">
  <div class="flex items-center justify-between gap-2 mb-2">
    <span class="text-xs font-semibold text-fg-secondary">{{ __('messages.ProofOfPayment') }}</span>
    <span class="text-[11px] text-fg-muted">{{ __('messages.Optional') }}</span>
  </div>
  <p class="text-xs text-fg-muted mb-3">{{ __('messages.ProofOfPaymentCheckoutHint') }}</p>

  <div class="grid sm:grid-cols-2 gap-3">
    <div>
      <label class="form-label text-xs" for="proof-ref-{{ $code }}">{{ __('messages.ReferenceNumber') }}</label>
      <input type="text" id="proof-ref-{{ $code }}" class="input js-proof-ref" data-method="{{ $code }}"
             autocomplete="off" placeholder="{{ __('messages.ReferenceNumberPlaceholder') }}">
    </div>
    <div>
      <label class="form-label text-xs" for="proof-amount-{{ $code }}">{{ __('messages.AmountSent') }}</label>
      <input type="number" step="0.01" min="0" id="proof-amount-{{ $code }}"
             class="input js-proof-amount" data-method="{{ $code }}" placeholder="0.00">
    </div>
    <div class="sm:col-span-2">
      <label class="form-label text-xs" for="proof-file-{{ $code }}">{{ __('messages.Screenshot') }}</label>
      <input type="file" id="proof-file-{{ $code }}" class="input js-proof-file" data-method="{{ $code }}"
             accept="image/jpeg,image/png,image/webp,application/pdf">
      <p class="text-[11px] text-fg-muted mt-1">{{ __('messages.ProofFileHint') }}</p>
    </div>
  </div>
  <div class="text-danger text-xs mt-2 hidden js-proof-error" data-method="{{ $code }}"></div>
</div>
