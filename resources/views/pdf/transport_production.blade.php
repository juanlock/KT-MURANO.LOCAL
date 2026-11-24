@php
    // Mayte's two reports as PDF (Turismo Cautivo, 2026-11-24; Spanish, the company's language): "Producción por bus" (the
    // period's summary per bus) or "Reporte del día de ventas" (one day, Lima and Chiclayo). Landscape A4.
    $n = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $bus = fn ($b) => preg_replace('/^Bus\s+/i', '', (string) $b);
    $hm = fn ($d) => $d ? date('h:i A', strtotime($d)) : '';
    $sum = fn ($rows, $k) => array_sum(array_map(fn ($r) => (float) ($r[$k] ?? 0), $rows));
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 22px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; }
    h1 { font-size: 15px; margin: 0; color: #1f4e78; }
    .sub { color: #6b7280; margin: 2px 0 8px; }
    table.t { width: 100%; border-collapse: collapse; }
    table.t th { background: #1f4e78; color: #fff; font-weight: bold; padding: 4px 4px; font-size: 8px; text-align: center; }
    table.t th.grp { background: #2e75b6; }
    table.t td { border-bottom: 1px solid #e5e7eb; padding: 3px 4px; vertical-align: top; }
    table.t tbody tr:nth-child(even) td { background: #f3f8fc; }
    table.t tfoot td { font-weight: bold; background: #ddebf7; border-top: 2px solid #1f4e78; }
    .r { text-align: right; white-space: nowrap; }
    .neg { color: #b91c1c; }
    .est { color: #9ca3af; }
    .halves { width: 100%; border-collapse: separate; border-spacing: 0; }
    .halves > tbody > tr > td { width: 50%; vertical-align: top; padding: 0 4px; }
    .hd { font-weight: bold; font-size: 11px; padding: 4px 6px; margin-bottom: 3px; }
    .lima { background: #e6f4ff; color: #0958d9; }
    .cix { background: #f9f0ff; color: #531dab; }
    .pt { display: block; }
    .foot { margin-top: 8px; color: #6b7280; font-size: 8px; }
</style>
</head>
<body>
@if ($kind === 'production')
    <h1>Producción por bus</h1>
    <div class="sub">{{ $company }} · del {{ date('d/m/Y', strtotime($data['from'])) }} al {{ date('d/m/Y', strtotime($data['to'])) }}</div>
    <table class="t">
        <thead>
            <tr><th rowspan="2">Bus</th><th rowspan="2">Servicio</th><th colspan="5" class="grp">Salidas de Lima</th><th colspan="5" class="grp">Salidas de Chiclayo</th>
                <th rowspan="2">Total ingresos</th><th rowspan="2">Combustible</th><th rowspan="2">Peaje</th><th rowspan="2">Ingr. - comb. - peaje</th><th rowspan="2">Ocup.</th></tr>
            <tr>@for ($i = 0; $i < 2; $i++)<th>Pax</th><th>Pasajes</th><th>Encom.</th><th>Exceso</th><th>Total</th>@endfor</tr>
        </thead>
        <tbody>
        @foreach ($data['buses'] as $b)
            <tr>
                <td><b>{{ $bus($b['bus']) }}</b></td><td>{{ $b['category'] }}</td>
                @foreach (['lima', 'chiclayo'] as $s)
                    <td class="r">{{ $b[$s]['pax'] }}</td><td class="r">{{ $n($b[$s]['tickets']) }}</td><td class="r">{{ $n($b[$s]['parcels']) }}</td>
                    <td class="r">{{ $n($b[$s]['excess']) }}</td><td class="r">{{ $n($b[$s]['total']) }}</td>
                @endforeach
                <td class="r">{{ $n($b['income']) }}</td><td class="r">{{ $n($b['fuel']) }}@if ($b['missing_fuel']) *@endif</td><td class="r">{{ $n($b['toll']) }}</td>
                <td class="r {{ $b['net'] < 0 ? 'neg' : '' }}"><b>{{ $n($b['net']) }}</b></td><td class="r">{{ $b['occupancy'] !== null ? $b['occupancy'].' %' : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                @foreach (['lima', 'chiclayo'] as $s)
                    @php $rows = array_map(fn ($b) => $b[$s], $data['buses']); @endphp
                    <td class="r">{{ (int) $sum($rows, 'pax') }}</td><td class="r">{{ $n($sum($rows, 'tickets')) }}</td><td class="r">{{ $n($sum($rows, 'parcels')) }}</td>
                    <td class="r">{{ $n($sum($rows, 'excess')) }}</td><td class="r">{{ $n($sum($rows, 'total')) }}</td>
                @endforeach
                <td class="r">{{ $n($data['totals']['income']) }}</td><td class="r">{{ $n($data['totals']['fuel']) }}</td><td class="r">{{ $n($data['totals']['toll']) }}</td>
                <td class="r">{{ $n($data['totals']['net']) }}</td><td class="r">{{ $data['totals']['occupancy'] !== null ? $data['totals']['occupancy'].' %' : '' }}</td>
            </tr>
        </tfoot>
    </table>
    <div class="foot">
        @if ($data['totals']['missing_fuel']) * {{ $data['totals']['missing_fuel'] }} salidas sin combustible registrado. @endif
        Pasajes, encomiendas y exceso: AuraSoft. Peaje: el registrado, o el de la ruta cuando no hay registro.
    </div>
@else
    <h1>Reporte del día de ventas</h1>
    <div class="sub">{{ $company }} · {{ date('d/m/Y', strtotime($day['date'])) }}</div>
    <table class="halves"><tr>
    @foreach (['lima' => 'Salidas de Lima', 'chiclayo' => 'Salidas de Chiclayo'] as $k => $title)
        @php $rows = $day[$k]; @endphp
        <td>
            <div class="hd {{ $k === 'lima' ? 'lima' : 'cix' }}">{{ $title }} · {{ count($rows) }} salidas</div>
            <table class="t">
                <thead><tr><th>Asientos</th><th>Bus</th><th>Embarque</th><th>Horario</th><th>Pax</th><th>Libres</th><th>Monto</th><th>Peaje</th><th>Comb.</th><th>Encom.</th><th>Exceso</th></tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td class="r">{{ $r['seats'] ?: '' }}</td><td><b>{{ $bus($r['bus']) }}</b></td>
                        <td class="{{ $r['points_timetable'] ? 'est' : '' }}">@foreach ($r['points'] as $p)<span class="pt">{{ $p['name'] ?: '—' }}</span>@endforeach</td>
                        <td class="{{ $r['points_timetable'] ? 'est' : '' }}">@foreach ($r['points'] as $p)<span class="pt">{{ $hm($p['at']) }}</span>@endforeach</td>
                        <td class="r">{{ $r['pax'] }}</td><td class="r">{{ $r['free'] ?? '—' }}</td><td class="r"><b>{{ $n($r['amount']) }}</b></td>
                        <td class="r">{{ $n($r['toll']) }}</td><td class="r">{{ $n($r['fuel']) }}</td>
                        <td class="r">{{ $r['parcels'] ? $n($r['parcels']) : '—' }}</td><td class="r">{{ $r['excess'] ? $n($r['excess']) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <td class="r">{{ (int) $sum($rows, 'seats') }}</td><td colspan="3">Total</td><td class="r">{{ (int) $sum($rows, 'pax') }}</td><td class="r">{{ (int) $sum($rows, 'free') }}</td>
                    <td class="r">{{ $n($sum($rows, 'amount')) }}</td><td class="r">{{ $n($sum($rows, 'toll')) }}</td><td class="r">{{ $n($sum($rows, 'fuel')) }}</td>
                    <td class="r">{{ $n($sum($rows, 'parcels')) }}</td><td class="r">{{ $n($sum($rows, 'excess')) }}</td>
                </tr></tfoot>
            </table>
            @if (! empty($day['channels'][$k]))
                <div class="foot">Pasajes por vendedor (AuraSoft):
                    @foreach (collect($day['channels'][$k])->filter()->sortDesc() as $c => $q) {{ $c }} {{ $q }} · @endforeach
                </div>
            @endif
        </td>
    @endforeach
    </tr></table>
    <div class="foot">Puntos de embarque en gris: tomados del horario (la última salida igual). Peaje: el registrado, o el de la ruta cuando no hay registro.</div>
@endif
</body>
</html>
