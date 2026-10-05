{{-- Portfolio module: Contact page (form -> portfolio_messages, read in Portfolio -> Messages). --}}
@extends('portfolio.layout')

@section('title', 'Contact')

@section('content')
  @php
    $lines = fn ($t) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $t))));
    $phones = $lines($site->contact_phones);
    $emails = $lines($site->contact_emails);
  @endphp
  <section class="contact">
    <div class="container">
      <div class="section-title">
        <h2>Contact</h2>
        <p>{{ $site->contact_subtitle ?: 'Get in touch with us' }}</p>
      </div>
      <div class="row">
        <div class="{{ $site->map_url ? 'col-md-6' : 'col-md-8 offset-md-2' }}">
          @if (session('pf_sent'))
            <div class="alert alert-success">Thank you — your message was sent. We'll get back to you soon.</div>
          @endif
          @if (isset($errors) && $errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
          @endif
          <form method="POST" action="{{ route('portfolio.contact.send', $site->slug) }}">
            @csrf
            {{-- Spam guards: a field people never see + when the form was opened. --}}
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
            <input type="hidden" name="ts" value="{{ time() }}">
            <div class="form-group">
              <label for="pf-name" class="sr-only">Full name</label>
              <input type="text" class="form-control" id="pf-name" name="name" value="{{ old('name') }}" placeholder="Enter your name" required maxlength="150">
            </div>
            <div class="form-group">
              <label for="pf-email" class="sr-only">Email address</label>
              <input type="email" class="form-control" id="pf-email" name="email" value="{{ old('email') }}" placeholder="Enter email" maxlength="190">
            </div>
            <div class="form-group">
              <label for="pf-phone" class="sr-only">Phone number</label>
              <input type="text" class="form-control" id="pf-phone" name="phone" value="{{ old('phone') }}" placeholder="Enter phone number" maxlength="60">
            </div>
            <div class="form-group">
              <label for="pf-message" class="sr-only">Your message</label>
              <textarea class="form-control" id="pf-message" name="message" rows="4" placeholder="Enter your message" required maxlength="5000">{{ old('message') }}</textarea>
            </div>
            <button type="submit" class="btn btn-lg btn-primary">Submit</button>
          </form>
        </div>
        @if ($site->map_url)
          <div class="col-md-6">
            <iframe src="{{ $site->map_url }}" style="border:0;width:100%;min-height:340px" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
          </div>
        @endif
      </div>

      @if ($site->contact_address || $phones || $emails)
        <div class="contact-info">
          <div class="row">
            @if ($site->contact_address)
              <div class="col-md-4">
                <div class="card">
                  <i class="fas fa-map"></i>
                  <p>{!! nl2br(e($site->contact_address)) !!}</p>
                </div>
              </div>
            @endif
            @if ($phones)
              <div class="col-md-4">
                <div class="card">
                  <i class="fas fa-mobile"></i>
                  @foreach ($phones as $ph)
                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $ph) }}">{{ $ph }}</a>
                  @endforeach
                </div>
              </div>
            @endif
            @if ($emails)
              <div class="col-md-4">
                <div class="card">
                  <i class="fas fa-envelope-open"></i>
                  @foreach ($emails as $em)
                    <a href="mailto:{{ $em }}">{{ $em }}</a>
                  @endforeach
                </div>
              </div>
            @endif
          </div>
        </div>
      @endif
    </div>
  </section>
@endsection
