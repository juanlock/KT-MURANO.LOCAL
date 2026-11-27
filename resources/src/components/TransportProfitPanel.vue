<template>
  <!-- Was each route worth it? (2026-11-04): income minus costs by route and, opening a route,
       by trip. A trip still to leave is "pending", not a loss, and stays out of the totals. -->
  <div ref="root">
  <a-card v-if="data.enabled" class="pp" :bordered="false" :body-style="{ padding: '8px' }">
    <a-spin :spinning="loading">
      <TransportStrip :items="tiles" :toggle="false" />
      <div class="pp-grid">
      <div class="pp-main">
      <a-table :columns="columns" :data-source="data.routes" row-key="route_id" size="small" :pagination="false" :scroll="tableScroll">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'route'"><b class="pp-nowrap">{{ record.route }}</b></template>
          <template v-else-if="column.key === 'trips'">
            <span class="pos">{{ record.gain_trips }}</span> / <span class="neg">{{ record.loss_trips }}</span>
            <span v-if="record.trips_pending" class="pp-muted"> · {{ record.trips_pending }} {{ es ? 'por salir' : 'to leave' }}</span>
          </template>
          <template v-else-if="column.key === 'occupancy'">{{ record.occupancy != null ? record.occupancy + ' %' : '—' }}</template>
          <template v-else-if="column.key === 'income'">{{ money(record.income) }}</template>
          <template v-else-if="column.key === 'costs'">{{ money(record.costs) }}</template>
          <template v-else-if="column.key === 'profit'"><b :class="record.profit >= 0 ? 'pos' : 'neg'">{{ record.trips_run ? money(record.profit) : '—' }}</b></template>
        </template>
        <!-- One line per trip, no side scroll (the user, 2026-11-27): the small costs together in "Otros", the detail on hover. -->
        <template #expandedRowRender="{ record }">
          <table class="pp-trips">
            <colgroup><col style="width: 21%" /><col style="width: 14%" /><col style="width: 13%" /><col style="width: 12%" /><col style="width: 11%" /><col style="width: 11%" /><col style="width: 13%" /><col style="width: 5%" /></colgroup>
            <thead>
              <tr>
                <th>{{ es ? 'Salida · bus' : 'Departure · bus' }}</th><th class="r">{{ es ? 'Pasajes' : 'Tickets' }}</th><th class="r">{{ es ? 'Ingresos' : 'Income' }}</th>
                <th class="r">{{ es ? 'Comb.' : 'Fuel' }}</th><th class="r">{{ es ? 'Peajes' : 'Tolls' }}</th>
                <th class="r"><a-tooltip :title="otherHelp"><span class="pp-dot">{{ es ? 'Otros' : 'Other' }}</span></a-tooltip></th>
                <th class="r">{{ es ? 'Ganancia' : 'Profit' }}</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in record.trips" :key="t.id">
                <td class="ell">{{ fmt(t.departure_at) }} <span class="pp-muted">· {{ String(t.vehicle || '').replace(/^Bus\s+/i, '') }}</span></td>
                <td class="r" :title="t.break_even_seats != null ? (es ? `Se paga con ${t.break_even_seats} pasajes` : `Covered at ${t.break_even_seats} tickets`) : ''">{{ t.sold }} <span class="pp-muted">{{ t.occupancy != null ? t.occupancy + ' %' : '' }}</span></td>
                <td class="r" :title="`${es ? 'Pasajes' : 'Tickets'} ${money(t.income.tickets)} · ${es ? 'Encomiendas' : 'Parcels'} ${money(t.income.parcels)}`">{{ whole(t.income.total) }}</td>
                <td class="r" :class="{ 'pp-est': t.estimated.includes('fuel') }" :title="t.estimated.includes('fuel') ? (es ? 'Estimado: el combustible se ingresa a mano' : 'Estimated: fuel is typed by hand') : ''">{{ whole(t.costs.fuel) }}</td>
                <td class="r" :class="{ 'pp-muted': t.estimated.includes('tolls') }">{{ whole(t.costs.tolls) }}</td>
                <td class="r">{{ whole(otherOf(t)) }}</td>
                <td class="r"><b :class="t.result === 'pending' ? '' : (t.profit >= 0 ? 'pos' : 'neg')">{{ t.result === 'pending' ? '—' : whole(t.profit) }}</b></td>
                <td class="c"><a-tooltip :title="es ? RESULT[t.result].es : RESULT[t.result].en"><i class="pp-res" :class="t.result" /></a-tooltip></td>
              </tr>
            </tbody>
          </table>
        </template>
      </a-table>

      </div>
      <!-- The period at a glance, Power BI style (2026-11-23): how the income turns into profit (waterfall), where each route
           stands (bubbles: occupancy x profit, size = tickets) and what each stretch brings in (treemap). One at a time. -->
      <div v-if="totals.income" class="pp-side" :style="sideHeight ? { height: sideHeight + 'px' } : null">
        <!-- Real tabs (the user, 2026-11-27: "Cascada / Rutas no se ve como un tab") and charts read at a glance. -->
        <a-tabs v-model:active-key="view" size="small" class="pp-tabs">
          <a-tab-pane v-for="o in viewOptions" :key="o.value" :tab="o.label" />
        </a-tabs>
        <div class="pp-hint">{{ view === 'waterfall'
          ? (es ? 'De lo que entra, cuánto se va en cada egreso y cuánto queda (el % es sobre los ingresos).' : 'Of what comes in, how much each cost takes and what is left (% of income).')
          : view === 'bubbles' ? (es ? 'Cada ruta: lo que ingresó, lo que gastó y lo que ganó.' : 'Each route: income, costs and profit.') : '' }}</div>
        <apexchart v-if="view === 'waterfall'" :key="`w${chartH}`" type="bar" :height="chartH" :options="waterfall.options" :series="waterfall.series" />
        <apexchart v-else-if="view === 'bubbles'" :key="`b${chartH}`" type="bar" :height="chartH" :options="bubbles.options" :series="bubbles.series" />
        <apexchart v-else-if="data.legs?.length" :key="`l${chartH}`" type="treemap" :height="chartH" :options="legsMap.options" :series="legsMap.series" />
      </div>
      </div>
    </a-spin>
  </a-card>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import http from '../lib/http';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';
import { useFitHeight, useTransportCharts, BLUE, ORANGE, GOOD, BAD, GRAY, POWERBI } from '../lib/transportCharts';
import TransportStrip from './TransportStrip.vue';

// The dashboard's own date filter (2026-11-04): [from, to], null = its "All" before it has dates.
const props = defineProps({ range: { type: Array, default: null } });
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// One screen: what doesn't fit (a route opened to its trips) scrolls inside the table.
const sideHeight = computed(() => (fit.value ? fit.value - 16 - 58 : null));
const tableScroll = computed(() => (fit.value ? { y: fit.value - 16 - 58 - 40 } : undefined));

const { money } = useFormat();
const es = computed(() => useUiStore().locale === 'es');
const RESULT = {
  gain: { color: 'green', en: 'Profit', es: 'Ganancia' },
  loss: { color: 'red', en: 'Loss', es: 'Pérdida' },
  pending: { color: 'blue', en: 'To leave', es: 'Por salir' },
};
const fmt = d => (d ? dayjs(d).format('DD/MM HH:mm') : '');

const loading = ref(false);
const data = ref({ enabled: false, routes: [], totals: {}, missing: {} });
const totals = computed(() => data.value.totals || {});

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/profit', { from: props.range?.[0], to: props.range?.[1] });
    data.value = { routes: [], totals: {}, missing: {}, ...res };
  } catch (e) {
    data.value = { enabled: false, routes: [], totals: {}, missing: {} };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
}
onMounted(load);

const missingLines = computed(() => {
  const m = data.value.missing || {};
  const line = (list, en, sp) => (list?.length ? `${es.value ? sp : en}: ${list.join(', ')}` : null);
  return [
    line(m.seats, 'Buses without seats (Fleet › Vehicles)', 'Buses sin asientos (Flota › Vehículos)'),
    line(m.depreciation, 'Buses without price or useful life: no depreciation counted', 'Buses sin precio o vida útil: no se cuenta depreciación'),
    line(m.fuel, 'Buses without fuel records: no fuel counted', 'Buses sin registros de combustible: no se cuenta combustible'),
    line(m.tolls, 'Routes without tolls: none counted', 'Rutas sin peajes: no se cuentan'),
    line(m.distance, 'Routes without distance', 'Rutas sin distancia'),
  ].filter(Boolean);
});

const tiles = computed(() => {
  const t = totals.value;
  const out = [
    { label: es.value ? 'Ganancia' : 'Profit', value: money(t.profit), level: t.profit < 0 ? 'bad' : 'ok', help: `${t.trips_run || 0} ${es.value ? 'viajes realizados' : 'trips run'}${t.trips_pending ? ` · ${t.trips_pending} ${es.value ? 'por salir' : 'to leave'}` : ''}` },
    { label: es.value ? 'Ingresos' : 'Income', value: money(t.income), help: `${es.value ? 'Pasajes' : 'Tickets'} ${money(t.tickets_income)} · ${es.value ? 'Encomiendas' : 'Parcels'} ${money(t.parcels_income)}` },
    { label: es.value ? 'Gastos' : 'Costs', value: money(t.costs), help: `${es.value ? 'Por viaje' : 'Per trip'} ${money(t.staff)} · ${es.value ? 'Planilla' : 'Payroll'} ${money(t.payroll)} · ${es.value ? 'Combustible' : 'Fuel'} ${money(t.fuel)} · ${es.value ? 'Mant.' : 'Maint.'} ${money(t.maintenance)} · Deprec. ${money(t.depreciation)} · ${es.value ? 'Peajes' : 'Tolls'} ${money(t.tolls)} · ${es.value ? 'Agencias' : 'Agencies'} ${money(t.agency)} · ${es.value ? 'Snack y bebida' : 'Snack and drink'} ${money(t.meals)}` },
    { label: es.value ? 'Viajes +/-' : 'Trips +/-', value: `${t.gain_trips || 0} / ${t.loss_trips || 0}`, help: es.value ? 'Con ganancia / con pérdida.' : 'With profit / with loss.' },
    { label: es.value ? 'Ocupación' : 'Occupancy', value: t.occupancy != null ? `${t.occupancy} %` : '—', help: `${t.tickets || 0} ${es.value ? 'boletos' : 'tickets'} · ${t.vip || 0} VIP` },
  ];
  if (missingLines.value.length) {
    out.push({ label: es.value ? 'Faltan datos' : 'Data missing', value: missingLines.value.length, level: 'warn', warn: true, help: missingLines.value.join(' · ') });
  }
  return out;
});

// ---- the side charts (2026-11-23)
const { base, short } = useTransportCharts();
const ui = useUiStore();
const view = ref('waterfall');
const viewOptions = computed(() => [
  { value: 'waterfall', label: es.value ? 'De ingresos a ganancia' : 'Income to profit' },
  { value: 'bubbles', label: es.value ? 'Por ruta' : 'By route' },
  ...(data.value.legs?.length ? [{ value: 'legs', label: es.value ? 'Tramos' : 'Stretches' }] : []),
]);
const chartH = computed(() => (sideHeight.value ? Math.max(220, Math.min(380, sideHeight.value - 80)) : 300));
const COST_KINDS = [['staff', 'Pay per trip', 'Pago por viaje'], ['payroll', 'Payroll', 'Planilla'], ['fuel', 'Fuel', 'Combustible'],
  ['maintenance', 'Maintenance', 'Mantenimiento'], ['depreciation', 'Depreciation', 'Depreciación'], ['tolls', 'Tolls', 'Peajes'],
  ['agency', 'Agencies', 'Agencias'], ['meals', 'Snack and drink', 'Snack y bebida']];
// "From income to profit" (2026-11-27, the user: the waterfall was hard to read): one horizontal bar per line -- income,
// each cost with data, profit -- the amount and its share of the income at the end of the bar.
const waterfall = computed(() => {
  const t = totals.value;
  const inc = Number(t.income || 0);
  const rows = [{ x: es.value ? 'Ingresos' : 'Income', y: Math.round(inc), fillColor: BLUE }];
  COST_KINDS.forEach(([k, en, sp]) => {
    const c = Number(t[k] || 0);
    if (c > 0) rows.push({ x: `− ${es.value ? sp : en}`, y: Math.round(c), fillColor: ORANGE });
  });
  const p = Math.round(t.profit || 0);
  rows.push({ x: `= ${es.value ? 'Ganancia' : 'Profit'}`, y: Math.abs(p), fillColor: p >= 0 ? GOOD : BAD, neg: p < 0 });
  const o = base('bar');
  return {
    series: [{ name: 'S/', data: rows }],
    options: { ...o, colors: rows.map(r => r.fillColor), plotOptions: { bar: { horizontal: true, barHeight: '62%', borderRadius: 3, distributed: true, dataLabels: { position: 'top' } } },
      xaxis: { labels: { formatter: v => short(v), style: { fontSize: '10px' } } },
      yaxis: { labels: { style: { fontSize: '11.5px', fontWeight: 600 }, maxWidth: 140 } },
      grid: { ...(o.grid || {}), padding: { right: 70 } },
      dataLabels: { enabled: true, offsetX: 60, textAnchor: 'end', style: { fontSize: '11px', fontWeight: 600, colors: [ui.dark ? '#f0efec' : '#252423'] },
        formatter: (v, op) => { const r = rows[op.dataPointIndex]; return `${r.neg ? '−' : ''}${short(v)}${inc ? ` · ${Math.round((v / inc) * 100)} %` : ''}`; } },
      tooltip: { ...o.tooltip, custom: ({ dataPointIndex }) => { const d = rows[dataPointIndex]; return `<div style="padding:6px 8px"><b>${d.x}</b><br>${d.neg ? '−' : ''}${money(d.y)}${inc ? ` (${Math.round((d.y / inc) * 100)} % ${es.value ? 'de los ingresos' : 'of income'})` : ''}</div>`; } },
      legend: { show: false } },
  };
});
// Routes: each route's income, costs and profit side by side.
const bubbles = computed(() => {
  const rows = (data.value.routes || []).filter(r => r.trips_run);
  const o = base('bar');
  return {
    series: [
      { name: es.value ? 'Ingresos' : 'Income', data: rows.map(r => Math.round(r.income || 0)) },
      { name: es.value ? 'Gastos' : 'Costs', data: rows.map(r => Math.round(r.costs || 0)) },
      { name: es.value ? 'Ganancia' : 'Profit', data: rows.map(r => Math.round(r.profit || 0)) },
    ],
    options: { ...o, colors: [BLUE, ORANGE, GOOD], plotOptions: { bar: { horizontal: true, barHeight: '70%', borderRadius: 2 } },
      xaxis: { categories: rows.map(r => r.route), labels: { formatter: v => short(v), style: { fontSize: '10px' } } },
      yaxis: { labels: { style: { fontSize: '11px', fontWeight: 600 }, maxWidth: 130 } },
      dataLabels: { enabled: true, formatter: v => short(v), style: { fontSize: '10px' } },
      tooltip: { ...o.tooltip, y: { formatter: v => money(v) } },
      legend: { show: true, position: 'top', fontSize: '11px' } },
  };
});
// The trips' small costs together (2026-11-27): payroll, crew, maintenance, depreciation, agency, meals.
const OTHER_KEYS = ['staff', 'payroll', 'maintenance', 'depreciation', 'agency', 'meals'];
const otherOf = t => OTHER_KEYS.reduce((n, k) => n + Number(t.costs?.[k] || 0), 0);
const otherHelp = computed(() => (es.value ? 'Planilla, pago por viaje, mantenimiento, depreciación, agencia y refrigerios. Hoy sin datos: se muestran en 0.' : 'Payroll, crew pay, maintenance, depreciation, agency and meals. No data yet: shown as 0.'));
const whole = v => Math.round(Number(v || 0)).toLocaleString('es-PE');
// Stretches: a treemap of what each one brings in.
const legsMap = computed(() => {
  const legs = data.value.legs || [];
  const total = legs.reduce((s, l) => s + (l.income || 0), 0) || 1;
  const o = base('treemap');
  return {
    series: [{ data: legs.map(l => ({ x: l.leg, y: Math.round(l.income || 0) })) }],
    options: { ...o, legend: { show: false }, colors: legs.map((_, i) => (i < POWERBI.length ? POWERBI[i] : GRAY)),
      plotOptions: { treemap: { distributed: true, enableShades: false, borderRadius: 3 } }, stroke: { width: 2, colors: ['#ffffff'] },
      dataLabels: { enabled: true, style: { fontSize: '11px', fontWeight: 600 }, formatter: (text, op) => [text, `${Math.round((op.value / total) * 100)} %`] },
      tooltip: { ...o.tooltip, y: { formatter: v => money(v) } } },
  };
});

const columns = computed(() => [
  { title: es.value ? 'Ruta' : 'Route', key: 'route' },
  { title: es.value ? 'Viajes +/-' : 'Trips +/-', key: 'trips' },
  { title: es.value ? 'Ocupación' : 'Occupancy', key: 'occupancy', align: 'right' },
  { title: es.value ? 'Ingresos' : 'Income', key: 'income', align: 'right' },
  { title: es.value ? 'Gastos' : 'Costs', key: 'costs', align: 'right' },
  { title: es.value ? 'Ganancia' : 'Profit', key: 'profit', align: 'right' },
]);
const tripColumns = computed(() => [
  { title: es.value ? 'Salida' : 'Departure', key: 'departure' },
  { title: es.value ? 'Boletos' : 'Tickets', key: 'seats' },
  { title: es.value ? 'Ingresos' : 'Income', key: 'income', align: 'right' },
  { title: es.value ? 'Pago por viaje' : 'Pay per trip', key: 'staff', align: 'right' },
  { title: es.value ? 'Planilla' : 'Payroll', key: 'payroll', align: 'right' },
  { title: es.value ? 'Combustible' : 'Fuel', key: 'fuel', align: 'right' },
  { title: es.value ? 'Mantenimiento' : 'Maintenance', key: 'maintenance', align: 'right' },
  { title: es.value ? 'Depreciación' : 'Depreciation', key: 'depreciation', align: 'right' },
  { title: es.value ? 'Peajes' : 'Tolls', key: 'tolls', align: 'right' },
  { title: es.value ? 'Agencia' : 'Agency', key: 'agency', align: 'right' },
  { title: es.value ? 'Snack y bebida' : 'Snack and drink', key: 'meals', align: 'right' },
  { title: es.value ? 'Ganancia' : 'Profit', key: 'profit', align: 'right' },
  { title: '', key: 'result' },
]);
</script>

<style scoped>
.pp { margin-bottom: 0; }
.pp-grid { display: grid; gap: 8px; grid-template-columns: 1fr; }
.pp-main, .pp-side { min-width: 0; }
.pp-main :deep(.ant-table-thead > tr > th) { padding: 3px 6px !important; white-space: nowrap; font-size: 11.5px; }
.pp-main :deep(.ant-table-tbody > tr > td) { padding: 2px 6px !important; font-size: 12px; }
.pp-side { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 8px; overflow: hidden; }
.pp-seg { margin-bottom: 4px; }
.pp-sub-title { font-weight: 600; font-size: 13px; margin-bottom: 2px; }
.pp-legs { font-size: 11.5px; }
.pp-leg { display: flex; align-items: baseline; gap: 6px; border-top: 1px solid rgba(128, 128, 128, 0.15); padding: 2px 0; }
.pp-leg-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pp-muted { font-size: 11.5px; opacity: 0.68; font-weight: 400; }
.pos { color: #15803d; }
.neg { color: #b91c1c; }
.pp-tabs :deep(.ant-tabs-nav) { margin-bottom: 2px; }
.pp-tabs :deep(.ant-tabs-tab) { padding: 4px 2px; font-size: 12.5px; }
.pp-hint { font-size: 11.5px; color: #8a94a6; margin-bottom: 2px; }
.pp-trips { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 11.5px; font-variant-numeric: tabular-nums; }
.pp-trips th { font-weight: 600; color: #605e5c; padding: 3px 5px; border-bottom: 1px solid #e1e4e8; white-space: nowrap; text-align: left; }
.pp-trips td { padding: 2px 5px; border-bottom: 1px solid #f0f1f3; white-space: nowrap; }
.pp-trips .r { text-align: right; }
.pp-trips .c { text-align: center; }
.pp-trips .ell { overflow: hidden; text-overflow: ellipsis; }
.pp-est { color: #d64550; font-style: italic; }
.pp-nowrap { white-space: nowrap; }
.pp-main :deep(.ant-table-tbody > tr > td) { white-space: nowrap; }
.pp-dot { cursor: help; border-bottom: 1px dotted #98a2b3; }
.pp-res { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #98a2b3; }
.pp-res.gain { background: #1aab40; }
.pp-res.loss { background: #d64550; }
.pp-res.pending { background: #118dff; }
@media (min-width: 900px) {
  .pp-grid { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
}
</style>
