@extends('layouts.store')

@section('content')
@php
  $chip = ['open' => 'chip-info', 'pending' => 'chip-warning', 'resolved' => 'chip-success', 'closed' => ''];
@endphp
<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <span class="section-kicker">{{ __('messages.Account') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.SupportTickets') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.SupportTicketsHelp') }}</div>
    </div>
    <a href="{{ route('account.tickets.create') }}" class="btn btn-primary">
      <x-store.icon name="plus" class="w-4 h-4" />{{ __('messages.NewTicket') }}
    </a>
  </div>
</section>

<div class="container py-8">
  <div class="account-layout">
    @include('store.partials.account-nav')

    <div class="space-y-4" style="min-width:0">
      @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
      @endif

      <div class="card">
        <div class="card-body">
          @if($tickets->isEmpty())
            <div class="empty-state py-12 text-center">
              <div class="empty-icon"><x-store.icon name="message" class="w-10 h-10" /></div>
              <h3 class="mt-2">{{ __('messages.NoTicketsYet') }}</h3>
              <a href="{{ route('account.tickets.create') }}" class="btn btn-outline mt-4">
                <x-store.icon name="plus" class="w-4 h-4" />{{ __('messages.NewTicket') }}
              </a>
            </div>
          @else
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-bg-muted text-fg-secondary">
                  <tr>
                    <th class="text-start font-medium px-4 py-2">{{ __('messages.TicketNumber') }}</th>
                    <th class="text-start font-medium px-4 py-2">{{ __('messages.TicketSubject') }}</th>
                    <th class="text-center font-medium px-4 py-2">{{ __('messages.Status') }}</th>
                    <th class="text-start font-medium px-4 py-2">{{ __('messages.TicketLastActivity') }}</th>
                    <th class="text-end font-medium px-4 py-2">{{ __('messages.Action') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-line-subtle">
                  @foreach($tickets as $t)
                    <tr>
                      <td class="px-4 py-3"><a class="text-accent-500 hover:underline font-medium" href="{{ route('account.tickets.show', $t->id) }}">{{ $t->number }}</a></td>
                      <td class="px-4 py-3" style="overflow-wrap:anywhere">
                        {{ $t->subject }}
                        @if($t->category)<div class="text-xs text-fg-muted">{{ $t->category }}</div>@endif
                      </td>
                      <td class="px-4 py-3 text-center"><span class="chip {{ $chip[$t->status] ?? '' }}">{{ __('messages.TicketStatus'.ucfirst($t->status)) }}</span></td>
                      <td class="px-4 py-3 text-fg-muted">{{ store_date($t->last_message_at ?? $t->created_at) }}</td>
                      <td class="px-4 py-3 text-end"><a class="text-accent-500 hover:underline" href="{{ route('account.tickets.show', $t->id) }}">{{ __('messages.View') }}</a></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
