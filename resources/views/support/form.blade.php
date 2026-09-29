@extends('support.layout')

@section('title', __('messages.OpenSupportTicket'))

@section('content')
<div class="card">
  <h1>{{ __('messages.OpenSupportTicket') }}</h1>
  <p class="sub">{{ __('messages.OpenSupportTicketHelp') }}</p>

  <form method="POST" action="{{ route('support.store', $companyQuery) }}" enctype="multipart/form-data"
        onsubmit="var b=this.querySelector('button[type=submit]');if(b){b.disabled=true;}">
    @csrf
    <input type="hidden" name="_ts" value="{{ $formTs }}">
    {{-- Honeypot: hidden from people, bots fill it in. --}}
    <div class="hp" aria-hidden="true">
      <label for="hp-website">Website</label>
      <input type="text" id="hp-website" name="website" tabindex="-1" autocomplete="off">
    </div>

    <div class="row">
      <div class="field">
        <label for="f-name">{{ __('messages.YourName') }} *</label>
        <input type="text" id="f-name" name="name" value="{{ old('name') }}" maxlength="120" required autocomplete="name">
      </div>
      <div class="field">
        <label for="f-email">{{ __('messages.EmailAddress') }} *</label>
        <input type="email" id="f-email" name="email" value="{{ old('email') }}" maxlength="190" required autocomplete="email">
      </div>
    </div>

    <div class="row">
      <div class="field">
        <label for="f-subject">{{ __('messages.TicketSubject') }} *</label>
        <input type="text" id="f-subject" name="subject" value="{{ old('subject') }}" maxlength="250" required>
      </div>
      <div class="field">
        <label for="f-category">{{ __('messages.TicketCategory') }}</label>
        <select id="f-category" name="category">
          <option value="">—</option>
          @foreach($ticketCategories as $c)
            <option value="{{ $c }}" @selected(old('category') === $c)>{{ $c }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div class="field">
      <label for="f-body">{{ __('messages.TicketMessage') }} *</label>
      <textarea id="f-body" name="body" maxlength="60000" required>{{ old('body') }}</textarea>
    </div>

    <div class="field">
      <label for="f-files">{{ __('messages.TicketAttachments') }}</label>
      <input type="file" id="f-files" name="files[]" multiple>
      <div class="hint">{{ __('messages.TicketAttachmentsHint') }}</div>
    </div>

    <button type="submit" class="btn">{{ __('messages.TicketSubmit') }}</button>
  </form>
</div>
@endsection
