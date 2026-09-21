{{-- "My Account" sidebar navigation. Only lists account features that exist in
     the app; add new items here as their pages are built. --}}
@php
  use App\Models\StoreSetting;
  $accNav = $accNav ?? StoreSetting::first();
  $walletEnabled = (bool) optional($accNav)->wallet_enabled;

  $items = [
    ['label' => __('messages.Dashboard'),      'url' => route('account'),          'active' => request()->routeIs('account'),        'show' => true],
    ['label' => __('messages.Orders'),         'url' => route('account.orders'),   'active' => request()->routeIs('account.orders'), 'show' => true],
    ['label' => __('messages.MyReturns'),      'url' => route('account.returns'),  'active' => request()->routeIs('account.returns'),'show' => true],
    ['label' => __('messages.MyRewards'),      'url' => route('account.rewards'),  'active' => request()->routeIs('account.rewards'),'show' => true],
    ['label' => __('messages.MyWallet'),       'url' => route('account.wallet'),   'active' => request()->routeIs('account.wallet'), 'show' => $walletEnabled],
    ['label' => __('messages.MyWishlist'),     'url' => route('store.wishlist'),   'active' => request()->routeIs('store.wishlist'), 'show' => true],
    ['label' => __('messages.Addresses'),      'url' => route('account').'#address',          'active' => false,               'show' => true],
    ['label' => __('messages.AccountDetails'), 'url' => route('account').'#account-details', 'active' => false,               'show' => true],
    ['label' => __('messages.MyQuestions'),   'url' => route('account.questions'), 'active' => request()->routeIs('account.questions'), 'show' => true],
    ['label' => __('messages.Preferences'),    'url' => route('account').'#preferences',     'active' => false,               'show' => true],
  ];
@endphp

<aside class="account-nav">
  <div class="account-nav-title">{{ __('messages.MyAccount') }}</div>
  <nav class="account-nav-list">
    @foreach($items as $it)
      @if($it['show'])
        <a href="{{ $it['url'] }}" class="account-nav-link {{ $it['active'] ? 'is-active' : '' }}">{{ $it['label'] }}</a>
      @endif
    @endforeach
    <form method="POST" action="{{ route('store.logout') }}" class="m-0">
      @csrf
      <button type="submit" class="account-nav-link account-nav-logout">{{ __('messages.Logout') }}</button>
    </form>
  </nav>
</aside>

<style>
  .account-layout { display: grid; grid-template-columns: 250px 1fr; gap: 24px; align-items: start; }
  @media (max-width: 860px) { .account-layout { grid-template-columns: 1fr; } }
  .account-nav { background: rgb(var(--color-bg-surface)); border: 1px solid rgb(var(--color-line-subtle)); border-radius: 12px; padding: 8px; }
  .account-nav-title { font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: rgb(var(--color-fg-muted)); padding: 12px 14px 8px; }
  .account-nav-list { display: flex; flex-direction: column; }
  .account-nav-link { display: block; width: 100%; text-align: start; padding: 10px 14px; border-radius: 8px; font-size: .95rem;
                      color: rgb(var(--color-fg-primary)); background: none; border: 0; cursor: pointer; text-decoration: none; }
  .account-nav-link:hover { background: rgb(var(--color-bg-muted)); }
  .account-nav-link.is-active { background: rgb(var(--color-bg-muted)); font-weight: 600; }
  .account-nav-logout { color: rgb(var(--color-danger, #dc2626)); }
</style>
