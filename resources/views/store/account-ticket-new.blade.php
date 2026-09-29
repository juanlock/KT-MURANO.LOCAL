@extends('layouts.store')

@section('content')
<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <span class="section-kicker">{{ __('messages.SupportTickets') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.NewTicket') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.OpenSupportTicketHelp') }}</div>
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
      @if ($errors->any())
        <div class="alert alert-danger">
          <ul class="m-0 ps-5 list-disc">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
          </ul>
        </div>
      @endif

      <div class="card">
        <div class="card-body">
          <form method="POST" action="{{ route('account.tickets.store') }}" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-4"
                onsubmit="var b=this.querySelector('button[type=submit]');if(b){b.disabled=true;}">
            @csrf
            <div>
              <label class="form-label" for="t-subject">{{ __('messages.TicketSubject') }} *</label>
              <input type="text" id="t-subject" name="subject" class="input" value="{{ old('subject') }}" maxlength="250" required>
            </div>
            <div>
              <label class="form-label" for="t-category">{{ __('messages.TicketCategory') }}</label>
              <select id="t-category" name="category" class="input">
                <option value="">—</option>
                @foreach($ticketCategories as $c)
                  <option value="{{ $c }}" @selected(old('category') === $c)>{{ $c }}</option>
                @endforeach
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="form-label" for="t-body">{{ __('messages.TicketMessage') }} *</label>
              <textarea id="t-body" name="body" rows="7" class="input" maxlength="60000" required>{{ old('body') }}</textarea>
            </div>
            <div class="md:col-span-2">
              <label class="form-label" for="t-files">{{ __('messages.TicketAttachments') }}</label>
              <input type="file" id="t-files" name="files[]" multiple class="text-sm">
              <div class="text-xs text-fg-muted mt-1">{{ __('messages.TicketAttachmentsHint') }}</div>
            </div>
            <div class="md:col-span-2 flex items-center justify-end gap-2">
              <a href="{{ route('account.tickets') }}" class="btn btn-outline">{{ __('messages.Cancel') }}</a>
              <button type="submit" class="btn btn-primary">
                <x-store.icon name="send" class="w-4 h-4" />{{ __('messages.TicketSubmit') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
