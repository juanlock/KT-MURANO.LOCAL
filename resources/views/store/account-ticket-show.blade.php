@extends('layouts.store')

@section('content')
@php
  $t = $data['ticket'];
  $chip = ['open' => 'chip-info', 'pending' => 'chip-warning', 'resolved' => 'chip-success', 'closed' => ''];
@endphp
<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div class="min-w-0">
      <span class="section-kicker">{{ __('messages.TicketNumber') }} {{ $t['number'] }}</span>
      <h1 class="section-title mt-1" style="overflow-wrap:anywhere">{{ $t['subject'] }}</h1>
      <div class="text-fg-muted text-sm mt-1 flex items-center flex-wrap gap-2">
        <span class="chip {{ $chip[$t['status']] ?? '' }}">{{ __('messages.TicketStatus'.ucfirst($t['status'])) }}</span>
        @if(!empty($t['category']))<span>{{ $t['category'] }}</span>@endif
        <span>{{ __('messages.TicketOpened') }}: {{ store_date($t['created_at']) }}</span>
      </div>
    </div>
    <a href="{{ route('account.tickets') }}" class="btn btn-outline">
      <x-store.icon name="arrow-left" class="w-4 h-4" />{{ __('messages.BackToTickets') }}
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
      @if ($errors->any())
        <div class="alert alert-danger">
          <ul class="m-0 ps-5 list-disc">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
          </ul>
        </div>
      @endif

      @foreach($data['messages'] as $m)
        @php $isAgent = $m['author_type'] === 'agent'; @endphp
        <div class="card" @if($isAgent) style="border-color: rgb(var(--color-accent-500) / .35); background: rgb(var(--color-accent-500) / .04);" @endif>
          <div class="card-body">
            <div class="flex items-start justify-between gap-3 flex-wrap text-sm">
              <span class="font-semibold text-fg-primary">
                {{ $isAgent ? ($m['author_name'] ?: __('messages.TicketSupportTeam')) : __('messages.TicketYou') }}
              </span>
              <span class="text-xs text-fg-muted">{{ store_date($m['created_at'], 'lll') }}</span>
            </div>
            <div class="mt-2 text-fg-primary" style="overflow-wrap:anywhere">{!! nl2br(e($m['body'])) !!}</div>
            @if(count($m['attachments']))
              <div class="mt-3 pt-3 border-t border-line-subtle flex flex-wrap gap-2">
                @foreach($m['attachments'] as $a)
                  <a class="chip" href="{{ route('account.tickets.attachment', [$m['id'], $a['index']]) }}">
                    <x-store.icon name="link" class="w-4 h-4" />{{ $a['name'] }}
                  </a>
                @endforeach
              </div>
            @endif
          </div>
        </div>
      @endforeach

      <div class="card">
        <div class="card-body">
          @if($closed)
            <div class="text-fg-muted">{{ __('messages.TicketClosedNotice') }}</div>
          @else
            <form method="POST" action="{{ route('account.tickets.reply', $t['id']) }}" enctype="multipart/form-data" class="space-y-4"
                  onsubmit="var b=this.querySelector('button[type=submit]');if(b){b.disabled=true;}">
              @csrf
              <div>
                <label class="form-label" for="r-body">{{ __('messages.TicketReply') }}</label>
                <textarea id="r-body" name="body" rows="5" class="input" maxlength="60000" required>{{ old('body') }}</textarea>
              </div>
              <div>
                <label class="form-label" for="r-files">{{ __('messages.TicketAttachments') }}</label>
                <input type="file" id="r-files" name="files[]" multiple class="text-sm">
                <div class="text-xs text-fg-muted mt-1">{{ __('messages.TicketAttachmentsHint') }}</div>
              </div>
              <div class="flex justify-end">
                <button type="submit" class="btn btn-primary">
                  <x-store.icon name="send" class="w-4 h-4" />{{ __('messages.TicketSendReply') }}
                </button>
              </div>
            </form>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
