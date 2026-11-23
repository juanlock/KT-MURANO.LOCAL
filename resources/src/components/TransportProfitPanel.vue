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
          <template v-if="column.key === 'route'"><b>{{ record.route }}</b></template>
          <template v-else-if="column.key === 'trips'">
            <span class="pos">{{ record.gain_trips }}</span> / <span class="neg">{{ record.loss_trips }}</span>
            <span v-if="record.trips_pending" class="pp-muted"> · {{ record.trips_pending }} {{ es ? 'por salir' : 'to leave' }}</span>
          </template>
          <template v-else-if="column.key === 'occupancy'">{{ record.occupancy != null ? record.occupancy + ' %' : '—' }}</template>
          <template v-else-if="column.key === 'income'">{{ money(record.income) }}</template>
          <template v-else-if="column.key === 'costs'">{{ money(record.costs) }}</template>
          <template v-else-if="column.key === 'profit'"><b :class="record.profit >= 0 ? 'pos' : 'neg'">{{ record.trips_run ? money(record.profit) : '—' }}</b></template>
        </template>
        <template #expandedRowRender="{ record }">
          <a-table :columns="tripColumns" :data-source="record.trips" row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content' }">
            <template #bodyCell="{ column, record: t }">
              <template v-if="column.key === 'departure'">{{ fmt(t.departure_at) }}<div class="pp-muted">{{ t.vehicle }}</div></template>
              <template v-else-if="column.key === 'seats'">
                {{ t.sold }} {{ es ? 'boletos' : 'tickets' }} · {{ t.occupancy != null ? t.occupancy + ' %' : '—' }}
                <div v-if="t.vip" class="pp-muted">{{ t.vip }} VIP</div>
                <div v-if="t.break_even_seats != null" class="pp-muted">{{ es ? 'se paga con' : 'covered at' }} {{ t.break_even_seats }} {{ es ? 'boletos' : 'tickets' }}</div>
                <div v-for="l in t.legs" :key="l.leg" class="pp-muted">{{ l.leg }}: {{ l.tickets }}</div>
              </template>
              <template v-else-if="column.key === 'income'">{{ money(t.income.total) }}<div class="pp-muted">{{ es ? 'Enc.' : 'Parcels' }} {{ money(t.income.parcels) }} ({{ t.parcels }})</div></template>
              <template v-else-if="column.key === 'staff'">{{ money(t.costs.staff) }}</template>
              <template v-else-if="column.key === 'payroll'">{{ money(t.costs.payroll) }}</template>
              <template v-else-if="column.key === 'fuel'">{{ money(t.costs.fuel) }}<div v-if="t.estimated.includes('fuel')" class="pp-muted">{{ es ? 'estimado' : 'estimated' }}</div></template>
              <template v-else-if="column.key === 'maintenance'">{{ money(t.costs.maintenance) }}</template>
              <template v-else-if="column.key === 'depreciation'">{{ money(t.costs.depreciation) }}</template>
              <template v-else-if="column.key === 'tolls'">{{ money(t.costs.tolls) }}<div v-if="t.estimated.includes('tolls')" class="pp-muted">{{ es ? 'esperado' : 'expected' }}</div></template>
              <template v-else-if="column.key === 'agency'">{{ money(t.costs.agency) }}</template>
              <template v-else-if="column.key === 'meals'">{{ money(t.costs.meals) }}</template>
              <template v-else-if="column.key === 'profit'"><b :class="t.result === 'pending' ? '' : (t.profit >= 0 ? 'pos' : 'neg')">{{ money(t.profit) }}</b></template>
              <template v-else-if="column.key === 'result'"><a-tag :color="RESULT[t.result].color">{{ es ? RESULT[t.result].es : RESULT[t.result].en }}</a-tag></template>
            </template>
          </a-table>
        </template>
      </a-table>

      </div>
      <!-- The period at a glance, Power BI style (2026-11-23): how the income turns into profit (waterfall), where each route
           stands (bubbles: occupancy x profit, size = tickets) and what each stretch brings in (treemap). One at a time. -->
      <div v-if="totals.income" class="pp-side" :style="sideHeight ? { height: sideHeight + 'px' } : null">
        <a-segmented v-model:value="view" size="small" block :options="viewOptions" class="pp-seg" />
        <apexchart v-if="view === 'waterfall'" :key="`w${chartH}`" type="rangeBar" :height="chartH" :options="waterfall.options" :series="waterfall.series" />
        <apexchart v-else-if="view === 'bubbles'" :key="`b${chartH}`" type="bubble" :height="chartH" :options="bubbles.options" :series="bubbles.series" />
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
const tableScroll = computed(() => (fit.value ? { x: 'max-content', y: fit.value - 16 - 58 - 40 } : { x: 'max-content' }));

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
  { value: 'waterfall', label: es.value ? 'Cascada' : 'Waterfall' },
  { value: 'bubbles', label: es.value ? 'Rutas' : 'Routes' },
  ...(data.value.legs?.length ? [{ value: 'legs', label: es.value ? 'Tramos' : 'Stretches' }] : []),
]);
const chartH = computed(() => (sideHeight.value ? Math.max(220, sideHeight.value - 44) : 320));
const COST_KINDS = [['staff', 'Pay per trip', 'Pago por viaje'], ['payroll', 'Payroll', 'Planilla'], ['fuel', 'Fuel', 'Combustible'],
  ['maintenance', 'Maintenance', 'Mantenimiento'], ['depreciation', 'Depreciation', 'Depreciación'], ['tolls', 'Tolls', 'Peajes'],
  ['agency', 'Agencies', 'Agencias'], ['meals', 'Snack and drink', 'Snack y bebida']];
// Waterfall: the income as the first column, each cost taking its step down, the profit at the end (green or red).
const waterfall = computed(() => {
  const t = totals.value;
  const pts = [{ x: es.value ? 'Ingresos' : 'Income', y: [0, Math.round(t.income || 0)], fillColor: BLUE }];
  let run = t.income || 0;
  COST_KINDS.forEach(([k, en, sp]) => {
    const c = Number(t[k] || 0);
    if (c > 0) { pts.push({ x: es.value ? sp : en, y: [Math.round(run - c), Math.round(run)], fillColor: ORANGE }); run -= c; }
  });
  const p = Math.round(t.profit || 0);
  pts.push({ x: es.value ? 'Ganancia' : 'Profit', y: [Math.min(0, p), Math.max(0, p)], fillColor: p >= 0 ? GOOD : BAD });
  const o = base('rangeBar');
  return {
    series: [{ name: 'S/', data: pts }],
    options: { ...o, plotOptions: { bar: { horizontal: false, columnWidth: '62%', borderRadius: 3 } },
      xaxis: { labels: { rotate: -40, style: { fontSize: '10px' }, trim: true, hideOverlappingLabels: false } },
      yaxis: { labels: { formatter: v => short(v), style: { fontSize: '10px' } } },
      dataLabels: { enabled: true, formatter: (v, op) => { const r = op.w.config.series[0].data[op.dataPointIndex].y; return short(r[1] - r[0]); },
        style: { fontSize: '9.5px', colors: [ui.dark ? '#f0efec' : '#0b0b0b'] }, offsetY: -14 },
      tooltip: { ...o.tooltip, custom: ({ dataPointIndex, w }) => { const d = w.config.series[0].data[dataPointIndex]; return `<div style="padding:6px 8px"><b>${d.x}</b><br>${money(d.y[1] - d.y[0])}</div>`; } },
      legend: { show: false } },
  };
});
// Bubbles: one per route -- across, its occupancy; up, its profit; size, its tickets. Losing routes fall under the line.
const bubbles = computed(() => {
  const rows = (data.value.routes || []).filter(r => r.trips_run && r.occupancy != null);
  const pts = rows.map(r => ({ x: r.occupancy, y: Math.round(r.profit), z: (r.trips || []).reduce((s, t) => s + (t.sold || 0), 0) || 1, name: r.route }));
  const o = base('bubble');
  return {
    series: [{ name: es.value ? 'Rutas' : 'Routes', data: pts }],
    options: { ...o, colors: [BLUE], fill: { opacity: 0.6 }, stroke: { width: 1, colors: [ui.dark ? '#1a1a19' : '#fff'] },
      plotOptions: { bubble: { minBubbleRadius: 5, maxBubbleRadius: 26 } },
      xaxis: { type: 'numeric', min: 0, max: 100, tickAmount: 5, title: { text: es.value ? 'Ocupación %' : 'Occupancy %', style: { fontSize: '10px', fontWeight: 400 } }, labels: { style: { fontSize: '10px' } } },
      yaxis: { labels: { formatter: v => short(v), style: { fontSize: '10px' } }, title: { text: es.value ? 'Ganancia S/' : 'Profit S/', style: { fontSize: '10px', fontWeight: 400 } } },
      annotations: { yaxis: [{ y: 0, borderColor: BAD, strokeDashArray: 4 }] },
      dataLabels: { enabled: true, formatter: (v, op) => (pts[op.dataPointIndex]?.name || '').split(' ')[0], style: { fontSize: '9px', colors: [ui.dark ? '#f0efec' : '#0b0b0b'] }, offsetY: -12, background: { enabled: false } },
      tooltip: { ...o.tooltip, custom: ({ dataPointIndex }) => { const d = pts[dataPointIndex]; return d ? `<div style="padding:6px 8px"><b>${d.name}</b><br>${es.value ? 'Ocupación' : 'Occupancy'} ${d.x} %<br>${es.value ? 'Ganancia' : 'Profit'} ${money(d.y)}<br>${d.z} ${es.value ? 'boletos' : 'tickets'}</div>` : ''; } },
      legend: { show: false } },
  };
});
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
@media (min-width: 900px) {
  .pp-grid { grid-template-columns: minmax(0, 1.65fr) minmax(0, 1.35fr); }
}
</style>
