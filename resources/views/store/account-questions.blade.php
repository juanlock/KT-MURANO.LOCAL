@extends('layouts.store')

@section('content')
<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6">
    <span class="section-kicker">{{ __('messages.Account') }}</span>
    <h1 class="section-title mt-1">{{ __('messages.MyQuestions') }}</h1>
    <div class="text-fg-muted text-sm mt-1">{{ __('messages.MyQuestionsHelp') }}</div>
  </div>
</section>

<div class="container py-8">
  <div class="account-layout">
    @include('store.partials.account-nav')

    <div class="space-y-4">
      @if($questions->isEmpty())
        <div class="empty-state py-16 text-center">
          <div class="empty-icon"><x-store.icon name="message" class="w-10 h-10" /></div>
          <h3 class="mt-2">{{ __('messages.NoQuestionsYet') }}</h3>
          <a href="{{ route('store.shop') }}" class="btn btn-outline mt-4">{{ __('messages.GoToShop') }}</a>
        </div>
      @else
        @foreach($questions as $q)
          <div class="card">
            <div class="card-body">
              <div class="flex items-start justify-between gap-3 flex-wrap">
                <a href="{{ route('store.product.show', $q->product_id) }}"
                   class="font-semibold text-fg-primary hover:text-accent-500">
                  {{ optional($q->product)->name ?? '#'.$q->product_id }}
                </a>
                @if($q->answer)
                  <span class="badge badge-success">{{ __('messages.Answered') }}</span>
                @elseif($q->status === 'rejected')
                  <span class="badge">{{ __('messages.ReviewRejected') }}</span>
                @else
                  <span class="badge badge-warning">{{ __('messages.AwaitingAnswer') }}</span>
                @endif
              </div>

              <div class="flex items-start gap-2 mt-3">
                <span class="font-semibold text-accent-500 shrink-0">Q</span>
                <div class="min-w-0">
                  <div class="text-fg-primary">{{ $q->question }}</div>
                  <div class="text-xs text-fg-muted mt-1">{{ store_date($q->created_at) }}</div>
                </div>
              </div>

              @if($q->answer)
                <div class="flex items-start gap-2 mt-3 pt-3 border-t border-line-subtle">
                  <span class="font-semibold text-fg-muted shrink-0">A</span>
                  <div class="min-w-0">
                    <div class="text-fg-secondary">{{ $q->answer }}</div>
                    <div class="text-xs text-fg-muted mt-1">
                      {{ __('messages.AnsweredByStore') }}
                      @if($q->answered_at) · {{ store_date($q->answered_at) }} @endif
                    </div>
                  </div>
                </div>
              @endif
            </div>
          </div>
        @endforeach
      @endif
    </div>
  </div>
</div>
@endsection
