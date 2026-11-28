@php
    // Daily attendance sheet (2026-11-28): the day's employees by location / department, present or absent with the type.
    $L = fn ($en, $sp) => $es ? $sp : $en;
    $groupName = fn ($g) => $g['label'] ?? ($data['group_by'] === 'location' ? $L('Head office', 'Oficina central') : $L('No department', 'Sin departamento'));
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 22px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
    h1 { font-size: 16px; margin: 0; color: #1e3a8a; }
    .sub { color: #6b7280; margin: 2px 0 10px; }
    h2 { font-size: 11px; margin: 10px 0 4px; color: #1e3a8a; }
    table.t { width: 100%; border-collapse: collapse; }
    table.t th { background: #1e3a8a; color: #fff; text-align: left; padding: 4px 6px; font-size: 9px; }
    table.t td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; }
    .p { color: #047857; font-weight: bold; }
    .a { color: #b91c1c; font-weight: bold; }
    .foot { margin-top: 12px; color: #6b7280; font-size: 9px; }
</style>
</head>
<body>
    <h1>{{ $L('Daily attendance', 'Asistencia del día') }} · {{ $company }}</h1>
    <div class="sub">
        {{ ucfirst($day->isoFormat('dddd')) }} {{ $day->format($es ? 'd-m-Y' : 'm-d-Y') }}
        · {{ $data['stats']['total'] }} {{ $L('employees', 'empleados') }}
        · {{ $data['stats']['present'] }} {{ $L('present', 'presentes') }}
        · {{ $data['stats']['absent'] }} {{ $L('absent', 'ausentes') }}
        @if ($data['holiday']) · {{ $L('Holiday', 'Feriado') }}: {{ $data['holiday'] }} @endif
        @if (! $data['taken']) · {{ $L('NOT SAVED YET', 'AÚN NO GUARDADA') }} @endif
    </div>

    @foreach ($data['groups'] as $g)
        <h2>{{ $groupName($g) }} ({{ count($g['employees']) }})</h2>
        <table class="t">
            <thead><tr>
                <th style="width:34%">{{ $L('Employee', 'Empleado') }}</th>
                <th style="width:12%">{{ $L('Status', 'Estado') }}</th>
                <th style="width:22%">{{ $L('Type', 'Tipo') }}</th>
                <th>{{ $L('Reason', 'Motivo') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($g['employees'] as $e)
                <tr>
                    <td>{{ $e['name'] }}</td>
                    @if ($e['status'] === 'absent')
                        <td class="a">{{ $L('Absent', 'Ausente') }}</td>
                        <td>{{ $typeLabel($e['leave_type_id']) }}@if ($e['half_day']) · {{ $L('half day', 'medio día') }}@endif</td>
                        <td>{{ $e['reason'] }}</td>
                    @else
                        <td class="p">{{ $L('Present', 'Presente') }}</td>
                        <td>@if ($e['clock_in']) {{ $L('Clock in', 'Entrada') }} {{ $e['clock_in'] }} @endif</td>
                        <td></td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach

    <div class="foot">
        @if ($data['taken'])
            {{ $L('Saved by', 'Guardada por') }} {{ $data['taken']['by'] }} · {{ \Carbon\Carbon::parse($data['taken']['at'])->format($es ? 'd-m-Y H:i' : 'm-d-Y H:i') }}
        @endif
    </div>
</body>
</html>
