{{-- Distribución B2B: the invoice as Hordex's earlier system shows it (parciales/detalleFactura), 2026-11-20. --}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $f->Ref }}</title>
<style>
  @page { margin: 18mm 14mm; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10.5px; color: #111; }
  .head { position: relative; height: 92px; }
  .co { width: 62%; }
  .co img { max-height: 46px; max-width: 200px; margin-bottom: 4px; }
  .co .name { font-weight: bold; font-size: 12px; }
  .box { position: absolute; right: 0; top: 0; width: 200px; border: 2px solid #000; text-align: center; padding: 10px 6px; }
  .box .t { font-weight: bold; font-size: 11px; }
  .box .n { font-weight: bold; font-size: 13px; margin-top: 8px; }
  .state { position: absolute; right: 0; top: 74px; width: 214px; text-align: center; font-weight: bold; font-size: 12px; }
  .ANULADA { color: #6b7280; } .PENDIENTE { color: #2563eb; } .VENCIDA { color: #dc2626; } .CANCELADA { color: #16a34a; }
  table.l { margin-top: 10px; border-collapse: collapse; }
  table.l td { padding: 1px 0; vertical-align: top; }
  table.l td.k { width: 130px; }
  table.l td.c { width: 14px; }
  table.d { width: 100%; border-collapse: collapse; margin-top: 10px; }
  table.d th { background: #000; color: #fff; font-weight: normal; text-align: left; padding: 3px 6px; }
  table.d td { padding: 2px 6px; }
  .r { text-align: right; } .ctr { text-align: center; }
  table.t { width: 100%; border-collapse: collapse; margin-top: 4px; }
  table.t td { padding: 2px 6px; }
  table.t td.lb { width: 150px; text-align: right; font-style: italic; }
  table.t td.v { width: 90px; text-align: right; }
  .ret { color: #c2410c; font-weight: bold; text-transform: uppercase; }
</style>
</head>
<body>
  <div class="head">
    <div class="co">
      @if($logo)<img src="{{ $logo }}" alt="">@endif
      <div class="name">{{ $co->CompanyName ?? '' }}</div>
      <div>RUC {{ $co->vat_number ?? '' }}</div>
      <div>{{ $co->CompanyAdress ?? '' }}</div>
    </div>
    <div class="box">
      <div class="t">{{ str_starts_with($f->Ref, 'E') ? 'FACTURA ELECTRONICA' : 'FACTURA' }}</div>
      <div class="n">Nº {{ $f->Ref }}</div>
    </div>
    <div class="state {{ $f->state }}">{{ $f->state }}</div>
  </div>

  <table class="l">
    <tr><td class="k">Fecha de Emisión</td><td class="c">:</td><td>{{ \Carbon\Carbon::parse($f->date)->format('d/m/Y') }}</td></tr>
    <tr><td class="k">Fecha de Vencimiento</td><td class="c">:</td><td>{{ $f->due_date ? \Carbon\Carbon::parse($f->due_date)->format('d/m/Y') : '' }}</td></tr>
    <tr><td class="k">Señor(es)</td><td class="c">:</td><td>{{ $f->client_name }}</td></tr>
    <tr><td class="k">RUC</td><td class="c">:</td><td>{{ $f->tax_number }}</td></tr>
    <tr><td class="k">Tipo de Moneda</td><td class="c">:</td><td>SOLES</td></tr>
    <tr><td class="k">Forma de Pago</td><td class="c">:</td><td>{{ $f->term }}</td></tr>
  </table>

  <table class="d">
    <thead><tr><th class="r" style="width:60px">Cantidad</th><th class="ctr" style="width:80px">Unidad Medida</th><th>Descripción</th><th class="r" style="width:80px">Valor Unitario</th><th class="r" style="width:80px">Sub Total</th></tr></thead>
    <tbody>
      @foreach($f->lines as $l)
        <tr><td class="r">{{ number_format((float) $l->quantity, 2) }}</td><td class="ctr">{{ mb_strtoupper($l->unit_name ?: $l->unit ?: '') }}</td><td>{{ $l->name }}</td>
          <td class="r">{{ number_format((float) $l->price, 2) }}</td><td class="r">{{ number_format((float) $l->total, 2) }}</td></tr>
      @endforeach
    </tbody>
  </table>

  <table class="t">
    <tr><td></td><td class="lb">Neto</td><td class="v">{{ number_format($f->GrandTotal - $f->TaxNet, 2) }}</td></tr>
    <tr><td><strong>SON: {{ $f->words }}</strong></td><td class="lb">IGV</td><td class="v">{{ number_format($f->TaxNet, 2) }}</td></tr>
    <tr><td></td><td class="lb">Total</td><td class="v">{{ number_format($f->GrandTotal, 2) }}</td></tr>
    @if(! empty($f->retentions))
      <tr><td></td><td colspan="2" class="ret">Retenciones realizadas por el Cliente</td></tr>
      @foreach($f->retentions as $r)
        <tr><td></td><td class="lb">{{ $r->name }}</td><td class="v">{{ number_format($r->amount, 2) }}</td></tr>
      @endforeach
      <tr><td></td><td class="lb">Total a Percibir</td><td class="v">{{ number_format($f->to_receive, 2) }}</td></tr>
    @endif
  </table>
</body>
</html>
