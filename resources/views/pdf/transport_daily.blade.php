@php
    // Daily departures report (2026-11-10): the closed day of one agency, as the client's own report. Landscape A4.
    $L = fn ($en, $sp) => $es ? $sp : $en;
    $money = fn ($v) => $v === null ? '—' : 'S/ '.number_format((float) $v, 2);
    $live = array_values(array_filter($rows, fn ($r) => ($r['status'] ?? '') !== 'cancelled'));
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 22px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
    h1 { font-size: 16px; margin: 0; color: #4c1d95; }
    .sub { color: #6b7280; margin: 2px 0 10px; }
    .kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 10px; }
    .kpis td { background: #f5f3ff; border-radius: 6px; padding: 6px 8px; width: 14%; }
    .kpis .v { font-size: 14px; font-weight: bold; color: #111827; }
    .kpis .l { color: #6b7280; font-size: 9px; }
    table.t { width: 100%; border-collapse: collapse; }
    table.t th { background: #4c1d95; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; font-size: 9px; }
    table.t td { border-bottom: 1px solid #e5e7eb; padding: 5px 6px; vertical-align: top; }
    table.t tr.low td.amt { color: #b91c1c; font-weight: bold; }
    table.t tr.cancel td { color: #9ca3af; text-decoration: line-through; }
    table.t tfoot td { font-weight: bold; border-top: 2px solid #4c1d95; border-bottom: 0; }
    .r { text-align: right; white-space: nowrap; }
    .pt { display: inline-block; background: #ede9fe; border-radius: 3px; padding: 1px 4px; margin: 0 2px 2px 0; font-size: 9px; }
    .foot { margin-top: 12px; color: #6b7280; font-size: 9px; }
</style>
</head>
<body>
    <h1>{{ $L('Departures report', 'Reporte de salidas') }} · {{ $agency }}</h1>
    <div class="sub">
        {{ $company }} · {{ $day->format($es ? 'd-m-Y' : 'm-d-Y') }}
        @if ($price) · {{ $L('Price of the day', 'Precio del día') }}: {{ $money($price->price_high) }} / {{ $money($price->price_low) }} @endif
    </div>

    <table class="kpis"><tr>
        <td><div class="v">{{ $totals['departures'] ?? 0 }}</div><div class="l">{{ $L('Departures', 'Salidas') }}</div></td>
        <td><div class="v">{{ $totals['passengers'] ?? 0 }}</div><div class="l">{{ $L('Passengers', 'Pasajeros') }}</div></td>
        <td><div class="v">{{ ($totals['occupancy'] ?? null) !== null ? $totals['occupancy'].' %' : '—' }}</div><div class="l">{{ $L('Occupancy', 'Ocupación') }}</div></td>
        <td><div class="v">{{ $money($totals['amount'] ?? 0) }}</div><div class="l">{{ $L('Amount', 'Monto') }}</div></td>
        <td><div class="v">{{ $money($totals['tolls'] ?? 0) }}</div><div class="l">{{ $L('Tolls', 'Peajes') }}</div></td>
        <td><div class="v">{{ $money($totals['fuel'] ?? 0) }}</div><div class="l">{{ $L('Fuel', 'Combustible') }}</div></td>
        <td><div class="v">{{ $money($totals['net'] ?? 0) }}</div><div class="l">{{ $L('Amount - tolls - fuel', 'Monto - peajes - combustible') }}</div></td>
    </tr></table>

    <table class="t">
        <thead><tr>
            <th>{{ $L('Time', 'Hora') }}</th><th>{{ $L('Bus', 'Bus') }}</th><th>{{ $L('Destination', 'Destino') }}</th>
            <th>{{ $L('Boarding points', 'Puntos de embarque') }}</th><th class="r">{{ $L('Passengers', 'Pasajeros') }}</th>
            <th class="r">{{ $L('Free', 'Libres') }}</th><th class="r">{{ $L('Amount', 'Monto') }}</th><th class="r">{{ $L('Toll', 'Peaje') }}</th>
            <th class="r">{{ $L('Fuel', 'Combustible') }}</th><th>{{ $L('Crew', 'Tripulación') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($rows as $r)
            @php $cancel = ($r['status'] ?? '') === 'cancelled'; @endphp
            <tr class="{{ $cancel ? 'cancel' : (($r['amount'] ?? 0) < \App\Services\TransportDepartures::LOW_AMOUNT ? 'low' : '') }}">
                <td><b>{{ $r['time'] }}</b>@if ($cancel) <br>{{ $L('Cancelled', 'Cancelada') }}@endif</td>
                <td>{{ $r['bus'] ?: '—' }}</td>
                <td>{{ $r['destination'] }}</td>
                <td>@forelse ($r['points'] as $p)<span class="pt">{{ $p['label'] ?: $p['name'] }} {{ $p['time'] }}</span>@empty — @endforelse</td>
                <td class="r">{{ $r['passengers'] }}{{ $r['seats'] ? ' / '.$r['seats'] : '' }}</td>
                <td class="r">{{ $r['seats'] ? max(0, $r['seats'] - $r['passengers']) : '—' }}</td>
                <td class="r amt">{{ $money($r['amount']) }}</td>
                <td class="r">{{ $money($r['toll']) }}</td>
                <td class="r">{{ $money($r['fuel']) }}</td>
                <td>{{ implode(', ', $r['crew'] ?? []) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr>
            <td colspan="4">{{ $L('Total', 'Total') }} ({{ count($live) }} {{ $L('departures', 'salidas') }})</td>
            <td class="r">{{ $totals['passengers'] ?? 0 }}{{ !empty($totals['seats']) ? ' / '.$totals['seats'] : '' }}</td>
            <td class="r">{{ !empty($totals['seats']) ? max(0, $totals['seats'] - ($totals['passengers'] ?? 0)) : '—' }}</td>
            <td class="r">{{ $money($totals['amount'] ?? 0) }}</td>
            <td class="r">{{ $money($totals['tolls'] ?? 0) }}</td>
            <td class="r">{{ $money($totals['fuel'] ?? 0) }}</td>
            <td></td>
        </tr></tfoot>
    </table>

    <div class="foot">
        {{ $L('Closed by', 'Cerrado por') }} {{ $closedBy ?: '—' }} · {{ $closedAt ? \Carbon\Carbon::parse($closedAt)->format($es ? 'd-m-Y H:i' : 'm-d-Y H:i') : '' }}
        @if (($totals['missing_toll'] ?? 0) || ($totals['missing_fuel'] ?? 0))
            · {{ $L('Without toll', 'Sin peaje') }}: {{ $totals['missing_toll'] ?? 0 }} · {{ $L('Without fuel', 'Sin combustible') }}: {{ $totals['missing_fuel'] ?? 0 }}
        @endif
        · {{ $L('Amounts in red: under', 'Montos en rojo: menos de') }} S/ {{ number_format(\App\Services\TransportDepartures::LOW_AMOUNT) }}.
    </div>
</body>
</html>
