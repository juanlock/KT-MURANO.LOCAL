{{-- Page behind the "Confirm appointment" button of the admins' new-booking email
     (BookingEmailConfirmController). GET shows the booking + a Confirm button;
     the POST (same signed URL) confirms it. --}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Confirm appointment</title>
  <style>
    body { margin: 0; background: #f5f5f7; font-family: Arial, sans-serif; color: #222; }
    .card { max-width: 440px; margin: 48px auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
    h1 { font-size: 20px; margin: 0 0 16px; }
    table { border-collapse: collapse; width: 100%; margin: 8px 0 20px; }
    td { padding: 5px 0; vertical-align: top; }
    td:first-child { color: #666; width: 110px; }
    td:last-child { font-weight: 600; }
    button { width: 100%; background: #2e7d32; color: #fff; border: 0; border-radius: 8px; padding: 12px; font-size: 16px; cursor: pointer; }
    .ok { color: #2e7d32; font-weight: 700; }
    .muted { color: #888; font-size: 13px; }
    a { color: #b8860b; }
  </style>
</head>
<body>
  <div class="card">
    @if (! $b)
      <h1>Booking not found</h1>
      <p>This appointment no longer exists.</p>
    @else
      @if ($done)
        <h1 class="ok">✓ Appointment confirmed</h1>
      @elseif ($b->status === 'pending')
        <h1>Confirm this appointment?</h1>
      @else
        <h1>This appointment is already {{ $b->status }}</h1>
      @endif
      <table>
        <tr><td>Booking</td><td>{{ $b->Ref }}</td></tr>
        <tr><td>Customer</td><td>{{ optional($b->customer)->name ?: '-' }}</td></tr>
        <tr><td>Service</td><td>{{ optional($b->product)->name ?: ($b->product_name ?: '-') }}</td></tr>
        <tr><td>Date</td><td>{{ $b->booking_date ? \Carbon\Carbon::parse($b->booking_date)->format('l, F j, Y') : '' }} {{ substr((string) $b->booking_time, 0, 5) }}</td></tr>
        <tr><td>Status</td><td>{{ ucfirst($b->status) }}</td></tr>
      </table>
      @if (! $done && $b->status === 'pending')
        <form method="POST" action="{{ request()->fullUrl() }}">
          @csrf
          <button type="submit">Confirm appointment</button>
        </form>
      @elseif ($done)
        <p class="muted">The admins were notified and the customer's account is set up, same as confirming from the system.</p>
      @endif
      <p class="muted" style="margin-top:18px"><a href="{{ url('/next/bookings/'.$b->id.'/edit') }}">Open the booking in the system</a> (sign-in required)</p>
    @endif
  </div>
</body>
</html>
