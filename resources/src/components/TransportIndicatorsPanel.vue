<template>
  <!-- Two boards (2026-11-05), each fitting one screen with no scroll: view "profit" and view "risks".
       Same figures as "Profit by route" (App\Services\TransportIndicators); only trips already run count. -->
  <div ref="root">
    <a-card v-if="data.enabled" class="ti" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <a-empty v-if="!totals.trips_run" :description="tr('No trips run on these dates. Pick a longer period above.', 'No hay viajes realizados en estas fechas. Elige un período mayor en el filtro de arriba.')" />
        <template v-else>
          <TransportStrip v-model:as-table="asTables" :items="view === 'risks' ? riskItems : tiles" />
          <div :class="view === 'risks' ? 'ti-risks' : 'ti-profit'">
            <TransportChartBlock v-for="c in charts" :key="c.key" :chart="c" :as-table="asTables" />
          </div>
        </template>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import http from '../lib/http';
import { BLUE, GRAY, ORANGE, RED, YELLOW, useFitHeight, useTransportCharts } from '../lib/transportCharts';
const GREEN = '#16a34a';
import TransportChartBlock from './TransportChartBlock.vue';
import TransportStrip from './TransportStrip.vue';

// The dashboard's own date filter: [from, to], null = its "All" before it has dates.
const props = defineProps({ range: { type: Array, default: null }, view: { type: String, default: 'profit' } });

const { ui, money, tr, short, small, base, hbar } = useTransportCharts();
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// Card padding + the strip, and what a block adds around its chart.
const CHROME = 16 + 58;
const BLOCK = 36;
const height = computed(() => {
  if (!fit.value) return 250;
  return props.view === 'risks' ? Math.floor((fit.value - CHROME - 8) / 2) - BLOCK : fit.value - CHROME - BLOCK;
});

const asTables = ref(false);
const loading = ref(false);
const data = ref({ enabled: false, totals: {} });
const totals = computed(() => data.value.totals || {});

onMounted(async () => {
  loading.value = true;
  try {
    data.value = { totals: {}, ...(await http.get('transport/indicators', { from: props.range?.[0], to: props.range?.[1] })) };
  } catch (e) {
    data.value = { enabled: false, totals: {} };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
});

const pct = (v, of) => (of ? `${Math.round((v / of) * 100)} %` : '—');
const period = computed(() => `${dayjs(data.value.from).format('DD/MM/YYYY')} – ${dayjs(data.value.to).format('DD/MM/YYYY')}`);

const tiles = computed(() => {
  const t = totals.value;
  const trips = t.trips_run || 0;
  return [
    { label: tr('Margin', 'Margen'), value: data.value.margin != null ? `${data.value.margin} %` : '—', help: tr('Profit over income.', 'Ganancia sobre ingresos.') },
    { label: tr('Profit', 'Ganancia'), value: money(t.profit), help: `${period.value} · ${trips} ${tr('trips run', 'viajes realizados')}` },
    { label: tr('Income', 'Ingresos'), value: money(t.income), help: `${tr('Tickets', 'Pasajes')} ${money(t.tickets_income)} · ${tr('Parcels', 'Encomiendas')} ${money(t.parcels_income)}` },
    { label: tr('Occupancy', 'Ocupación'), value: t.occupancy != null ? `${t.occupancy} %` : '—', help: `${t.tickets || 0} ${tr('tickets', 'boletos')} · ${t.vip || 0} VIP` },
    { label: tr('Profit per trip', 'Ganancia por viaje'), value: money(trips ? t.profit / trips : 0) },
    { label: tr('Trips +/-', 'Viajes +/-'), value: `${t.gain_trips || 0} / ${t.loss_trips || 0}`, help: tr('With profit / with loss.', 'Con ganancia / con pérdida.') },
  ];
});

const IF = {
  tickets: ['Tickets -10 %', 'Boletos -10 %'], parcels: ['Parcels -10 %', 'Encomiendas -10 %'], fuel: ['Diesel +10 %', 'Diésel +10 %'],
  payroll: ['Crew and payroll +10 %', 'Planilla y tripulación +10 %'], maintenance: ['Maintenance +10 %', 'Mantenimiento +10 %'], tolls: ['Tolls +10 %', 'Peajes +10 %'],
};

const profitCharts = computed(() => {
  const d = data.value;
  const out = [];
  const points = d.trend?.points || [];
  if (points.length) {
    const label = p => (d.trend.unit === 'month' ? dayjs(p.date).format('MM/YYYY') : dayjs(p.date).format('DD/MM'));
    const o = base('line');
    const unit = { day: ['By day', 'Por día'], week: ['By week (Monday)', 'Por semana (lunes)'], month: ['By month', 'Por mes'] }[d.trend.unit];
    out.push({
      key: 'trend', type: 'line', height: height.value,
      title: tr('Income and costs', 'Ingresos y gastos'),
      help: `${tr(...unit)}. ${tr('Where costs run above income, that period lost money.', 'Donde los gastos pasan a los ingresos, ese período perdió.')}`
        + (d.idle_costs > 0 ? ` ${tr('Costs of days with no departure are not on this line', 'Los gastos de días sin salidas no están en esta línea')} (${money(d.idle_costs)}), ${tr('but they are in the total profit.', 'pero sí en la ganancia total.')}` : ''),
      series: [{ name: tr('Income', 'Ingresos'), data: points.map(p => p.income) }, { name: tr('Costs', 'Gastos'), data: points.map(p => p.costs) }],
      options: { ...o, colors: [BLUE, ORANGE], stroke: { width: 2, curve: 'straight' }, markers: { size: points.length > 20 ? 0 : 3, strokeColors: ui.dark ? '#1a1a19' : '#fff', strokeWidth: 1, hover: { size: 5 } },
        xaxis: { categories: points.map(label), labels: { rotate: 0, hideOverlappingLabels: true, style: small }, axisTicks: { show: false }, tooltip: { enabled: false } },
        yaxis: { labels: { formatter: short, style: small } }, tooltip: { ...o.tooltip, shared: true, intersect: false } },
      table: { cols: [tr('Date', 'Fecha'), tr('Income', 'Ingresos'), tr('Costs', 'Gastos'), tr('Difference', 'Diferencia')], rows: points.map(p => [label(p), money(p.income), money(p.costs), money(p.income - p.costs)]) },
    });
  }
  const routes = [...(d.routes || [])].sort((a, b) => b.profit - a.profit);
  if (routes.length) {
    out.push({
      key: 'route_profit', type: 'bar', height: height.value,
      title: tr('Profit by route', 'Ganancia por ruta'), help: tr('To the left of zero, the route lost money.', 'A la izquierda del cero, la ruta perdió.'),
      series: [{ name: tr('Profit', 'Ganancia'), data: routes.map(r => r.profit) }],
      options: hbar(routes.map(r => r.route), [{ data: routes.map(r => r.profit) }], { height: height.value, colors: [BLUE], labelWidth: 110, ranges: [{ from: -1e12, to: -0.001, color: RED }, { from: 0, to: 1e12, color: BLUE }] }),
      table: { cols: [tr('Route', 'Ruta'), tr('Income', 'Ingresos'), tr('Profit', 'Ganancia')], rows: routes.map(r => [r.route, money(r.income), money(r.profit)]) },
    });
  }
  // Where the profit comes from (user's request, 2026-11-12, replacing "Where the money goes", which repeated the Expenses
  // tab): only the profit, in its two parts (the user, after trying a waterfall: costs on it made it "not profit"). The
  // tickets' part = tickets - every trip cost; the parcels ride on the same bus with no cost of their own.
  const t = totals.value;
  if (t.income) {
    const P = +t.parcels_income || 0;
    const fromTrips = (+t.tickets_income || 0) - (+t.costs || 0);
    const profit = fromTrips + P;
    const PURPLE = '#7c3aed';
    const help = tr('Tickets = what the tickets leave after paying every cost of the trips (crew, payroll, fuel, maintenance, depreciation, tolls, agencies, meals). Parcels = what they bring: they ride on the same bus with no cost of their own. Both together are the profit.',
      'Pasajes = lo que dejan los boletos después de pagar todos los gastos de los viajes (tripulación, planilla, combustible, mantenimiento, depreciación, peajes, agencias, refrigerios). Encomiendas = lo que cobran: van en el mismo bus sin gasto propio. Las dos juntas son la ganancia.');
    const parts = [
      { name: tr('Tickets', 'Pasajes'), value: fromTrips, color: BLUE },
      { name: tr('Parcels', 'Encomiendas'), value: P, color: PURPLE },
    ];
    const table = { cols: ['', tr('Amount', 'Monto'), tr('Of the profit', 'De la ganancia')], rows: [
      ...parts.map(p => [p.name, `${p.value < 0 ? '−' : ''}${money(Math.abs(p.value))}`, profit > 0 ? pct(p.value, profit) : '']),
      [tr('Profit', 'Ganancia'), money(profit), profit > 0 ? '100 %' : ''],
    ] };
    // The profit split in two (user, 2026-11-12: "si la ganancia es 100, 23 es pasajes, 87 encomiendas", no costs on the chart):
    // a donut with the profit in the middle. Only when both parts are positive; if the tickets don't cover the costs, a donut
    // can't show it, so two bars (the tickets' part below zero, in red).
    if (fromTrips >= 0 && P >= 0 && profit > 0) {
      const o = base('donut');
      out.push({
        key: 'sources', type: 'donut', height: height.value,
        title: tr('Where the profit comes from', 'De dónde viene la ganancia'), help,
        series: parts.map(p => p.value),
        options: {
          ...o,
          labels: parts.map(p => p.name), colors: parts.map(p => p.color),
          legend: { position: 'bottom', fontSize: '12px', formatter: (n, { seriesIndex: i }) => `${n}: ${money(parts[i].value)} · ${pct(parts[i].value, profit)}` },
          dataLabels: { enabled: true, formatter: v => `${Math.round(v)} %`, style: { fontSize: '14px', fontWeight: 700 }, dropShadow: { enabled: false } },
          stroke: { width: 2, colors: [ui.dark ? '#1a1a19' : '#fff'] },
          plotOptions: { pie: { donut: { size: '58%', labels: { show: true,
            name: { show: true, fontSize: '12px', offsetY: -4 },
            value: { show: true, fontSize: '18px', fontWeight: 700, offsetY: 4, formatter: v => short(+v) },
            total: { show: true, showAlways: true, label: tr('Profit', 'Ganancia'), fontSize: '12px', formatter: () => short(profit) } } } } },
          tooltip: { ...o.tooltip, y: { formatter: v => `${money(v)} · ${pct(v, profit)}` } },
        },
        table,
      });
    } else {
      const o = base('bar');
      out.push({
        key: 'sources', type: 'bar', height: height.value,
        title: tr('Where the profit comes from', 'De dónde viene la ganancia'), help,
        series: [{ name: tr('Profit', 'Ganancia'), data: [...parts, { name: tr('Profit', 'Ganancia'), value: profit, color: GREEN }].map(p => ({ x: p.name, y: p.value, fillColor: p.value < 0 ? RED : p.color })) }],
        options: { ...o, legend: { show: false }, plotOptions: { bar: { columnWidth: '50%', borderRadius: 3 } },
          dataLabels: { enabled: true, formatter: v => short(v), style: { fontSize: '11px' } },
          xaxis: { labels: { style: small }, axisTicks: { show: false } }, yaxis: { labels: { formatter: short, style: small } },
          annotations: { yaxis: [{ y: 0, borderColor: GRAY }] } },
        table,
      });
    }
  }
  return out;
});

const riskCharts = computed(() => {
  const d = data.value;
  const out = [];
  const known = (d.routes || []).filter(r => r.needed != null).sort((a, b) => (b.needed - b.sold) - (a.needed - a.sold));
  if (known.length) {
    const seats = known[0].seats;
    out.push({
      key: 'tickets', type: 'bar', height: height.value,
      title: tr('Tickets per trip: sold and needed', 'Boletos por viaje: vendidos y necesarios'),
      help: `${tr('Needed = what covers the trip\'s costs, parcels counted. The route with the widest gap comes first.', 'Necesarios = los que cubren los gastos del viaje, contando encomiendas. Primero la ruta con más diferencia.')}${seats ? ` ${tr('Seats per bus', 'Asientos por bus')}: ${seats}.` : ''}`,
      series: [{ name: tr('Sold', 'Vendidos'), data: known.map(r => r.sold) }, { name: tr('Needed', 'Necesarios'), data: known.map(r => r.needed) }],
      options: hbar(known.map(r => r.route), [{ data: known.map(r => r.sold) }, { data: known.map(r => r.needed) }], { height: height.value, colors: [BLUE, ORANGE], labels: v => v, tooltip: v => v, offset: 16 }),
      table: { cols: [tr('Route', 'Ruta'), tr('Sold', 'Vendidos'), tr('Needed', 'Necesarios')], rows: known.map(r => [r.route, r.sold, r.needed]) },
    });
  }
  const byLoss = [...(d.routes || [])].sort((a, b) => (b.loss_trips / b.trips) - (a.loss_trips / a.trips));
  if (byLoss.length) {
    out.push({
      key: 'trips', type: 'bar', height: height.value,
      title: tr('Trips with profit and with loss', 'Viajes con ganancia y con pérdida'), help: tr('By route; the one that loses most often comes first.', 'Por ruta; primero la que pierde más seguido.'),
      series: [{ name: tr('With loss', 'Con pérdida'), data: byLoss.map(r => r.loss_trips) }, { name: tr('With profit', 'Con ganancia'), data: byLoss.map(r => r.gain_trips) }],
      options: hbar(byLoss.map(r => r.route), [{ data: byLoss.map(r => r.loss_trips) }, { data: byLoss.map(r => r.gain_trips) }], { height: height.value, colors: [RED, BLUE], stacked: true, labels: v => v, tooltip: v => v }),
      table: { cols: [tr('Route', 'Ruta'), tr('With loss', 'Con pérdida'), tr('With profit', 'Con ganancia')], rows: byLoss.map(r => [r.route, r.loss_trips, r.gain_trips]) },
    });
  }
  const ifs = d.sensitivity || [];
  if (ifs.length) {
    out.push({
      key: 'sensitivity', type: 'bar', height: height.value,
      title: tr('How much profit falls if...', 'Cuánto baja la ganancia si...'), help: tr('Arithmetic on this period\'s figures, one change at a time. Not a forecast.', 'Cuenta sobre las cifras de este período, un cambio a la vez. No es un pronóstico.'),
      series: [{ name: tr('Profit falls by', 'La ganancia baja'), data: ifs.map(s => s.amount) }],
      options: hbar(ifs.map(s => tr(...IF[s.key])), [{ data: ifs.map(s => s.amount) }], { height: height.value, colors: [RED], labelWidth: 150 }),
      table: { cols: [tr('If...', 'Si...'), tr('Profit falls by', 'La ganancia baja')], rows: ifs.map(s => [tr(...IF[s.key]), money(s.amount)]) },
    });
  }
  const fleet = d.fleet || [];
  if (fleet.length) {
    out.push({
      key: 'fleet', type: 'bar', height: height.value,
      title: tr('Buses: what is due', 'Buses: qué está por vencer'), help: tr('Papers, services and the maintenance plan, as of today (the date filter does not apply).', 'Documentos, servicios y plan de mantenimiento, a hoy (no depende del filtro de fechas).'),
      series: [{ name: tr('Overdue', 'Vencido'), data: fleet.map(b => b.overdue) }, { name: tr('Soon', 'Por vencer'), data: fleet.map(b => b.soon) }],
      options: hbar(fleet.map(b => b.bus), [{ data: fleet.map(b => b.overdue) }, { data: fleet.map(b => b.soon) }], { height: height.value, colors: [RED, YELLOW], stacked: true, labels: v => v, tooltip: v => v }),
      table: { cols: [tr('Bus', 'Bus'), tr('Overdue', 'Vencido'), tr('Soon', 'Por vencer')], rows: fleet.map(b => [b.bus, b.overdue, b.soon]) },
    });
  }
  return out;
});
const charts = computed(() => (props.view === 'risks' ? riskCharts.value : profitCharts.value));

const WORD = { ok: ['OK', 'Bien'], warn: ['Watch', 'Atención'], bad: ['Serious', 'Grave'] };
const riskItems = computed(() => {
  const L = data.value.limits || {};
  return (data.value.risks || []).map((r) => {
    const out = { level: r.level, note: tr(...WORD[r.level]) };
    switch (r.key) {
      case 'margin': return { ...out, label: tr('Margin', 'Margen'), value: `${r.value} %`, help: tr(`Serious below ${L.margin.bad} %, watch below ${L.margin.warn} %.`, `Grave bajo ${L.margin.bad} %, atención bajo ${L.margin.warn} %.`) };
      case 'loss_trips': return { ...out, label: tr('Trips with loss', 'Viajes con pérdida'), value: `${r.value} %`, help: tr(`Serious from ${L.loss_trips.bad} %, watch from ${L.loss_trips.warn} %.`, `Grave desde ${L.loss_trips.bad} %, atención desde ${L.loss_trips.warn} %.`) };
      case 'coverage': return { ...out, label: tr('Tickets / break-even', 'Boletos / equilibrio'), value: `${r.sold} / ${r.needed}`, help: tr(`Sold / needed per trip, on average. Serious below ${L.coverage.bad} % of what is needed, watch below ${L.coverage.warn} %.`, `Vendidos / necesarios por viaje, en promedio. Grave bajo ${L.coverage.bad} % de lo necesario, atención bajo ${L.coverage.warn} %.`) };
      case 'concentration': return { ...out, label: tr('Main route', 'Ruta principal'), value: `${r.value} %`, help: tr(`Of income comes from ${r.route}. Watch from ${L.concentration.warn} %.`, `De los ingresos viene de ${r.route}. Atención desde ${L.concentration.warn} %.`) };
      case 'fleet': return { ...out, label: tr('Buses due', 'Buses por vencer'), value: `${r.overdue} / ${r.soon}`, help: tr('Overdue / falling due soon, today. Serious with anything overdue.', 'Vencidos / por vencer, a hoy. Grave si hay algo vencido.') };
      default: return { ...out, label: tr('Estimated figures', 'Cifras estimadas'), value: `${r.value} %`, help: `${tr(`Of trips use estimated fuel or tolls. Watch from ${L.estimated.warn} %.`, `De los viajes usan combustible o peajes estimados. Atención desde ${L.estimated.warn} %.`)}${r.gaps ? ` ${tr('Data is also missing: see Profit by route.', 'También faltan datos: ver Rentabilidad por ruta.')}` : ''}` };
    }
  });
});
</script>

<style scoped>
.ti { margin-bottom: 0; }
.ti-profit, .ti-risks { display: grid; gap: 8px; grid-template-columns: 1fr; }
@media (min-width: 900px) {
  .ti-profit { grid-template-columns: 1.35fr 1fr 1.15fr; }
  .ti-risks { grid-template-columns: 1fr 1fr; }
}
</style>
