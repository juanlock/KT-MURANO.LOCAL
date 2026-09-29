@extends('support.layout')

@php
  $t = $data['ticket'];
  $statusKey = 'messages.TicketStatus'.ucfirst($t['status'] ?? 'open');
@endphp

@section('title', $t['number'])

@section('content')
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
    <h1 style="overflow-wrap:anywhere">{{ $t['subject'] }}</h1>
    <span class="badge badge-{{ $t['status'] }}">{{ __($statusKey) }}</span>
  </div>
  <div class="meta">
    <span>{{ __('messages.TicketNumber') }} <strong>{{ $t['number'] }}</strong></span>
    @if(!empty($t['category']))<span>{{ __('messages.TicketCategory') }}: {{ $t['category'] }}</span>@endif
    <span>{{ __('messages.TicketOpened') }}: {{ \Illuminate\Support\Carbon::parse($t['created_at'])->format('Y-m-d H:i') }}</span>
  </div>
  <p class="hint" style="margin-top:10px">{{ __('messages.TicketPrivateLinkHint') }}</p>
</div>

<div class="card">
  @foreach($data['messages'] as $m)
    <div class="msg {{ $m['author_type'] === 'agent' ? 'agent' : '' }}">
      <div class="msg-head">
        <strong>{{ $m['author_type'] === 'agent' ? ($m['author_name'] ?: __('messages.TicketSupportTeam')) : ($m['author_name'] ?: __('messages.TicketYou')) }}</strong>
        <span>{{ $m['created_at'] }}</span>
      </div>
      <div class="msg-body">{!! nl2br(e($m['body'])) !!}</div>
      @if(count($m['attachments']))
        <ul class="files">
          @foreach($m['attachments'] as $a)
            <li>📎 <a href="{{ route('support.attachment', [$token, $m['id'], $a['index']]) }}" rel="noreferrer">{{ $a['name'] }}</a>
              @if(!empty($a['size']))<span class="hint">({{ number_format($a['size'] / 1024, 0) }} KB)</span>@endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  @endforeach
</div>

<div class="card">
  @if($closed)
    <p class="sub" style="margin:0">{{ __('messages.TicketClosedNotice') }}</p>
  @else
    <form method="POST" action="{{ route('support.reply', $token) }}" enctype="multipart/form-data"
          onsubmit="var b=this.querySelector('button[type=submit]');if(b){b.disabled=true;}">
      @csrf
      <div class="field">
        <label for="r-body">{{ __('messages.TicketReply') }}</label>
        <textarea id="r-body" name="body" maxlength="60000" required>{{ old('body') }}</textarea>
      </div>
      <div class="field">
        <label for="r-files">{{ __('messages.TicketAttachments') }}</label>
        <input type="file" id="r-files" name="files[]" multiple>
        <div class="hint">{{ __('messages.TicketAttachmentsHint') }}</div>
      </div>
      <button type="submit" class="btn">{{ __('messages.TicketSendReply') }}</button>
    </form>
  @endif
</div>
@endsection
