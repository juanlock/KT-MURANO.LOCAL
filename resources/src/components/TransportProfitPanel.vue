<template>
  <!-- Was each route worth it? (2026-11-04): income minus costs by route and, opening a route,
       by trip. A trip still to leave is "pending", not a loss, and stays out of the totals. -->
  <div ref="root">
  <a-card v-if="data.enabled" class="pp" :bordered="false" :body-style="{ padding: '8px' }">
    <a-spin :spinning="loading">
      <TransportStrip :items="tiles" :toggle="false" />
      <div class="pp-grid">
      <div class="pp-main">
      <!-- The standard money table (the user, 2026-11-27; lib/moneyColumns.js): Ingresos (Pasajes, Encomiendas, Excesos, Total),
           Egresos (Combustible, Peajes, Personal, Depreciación, Otros, Total), Ganancia. A route is one line; a click opens its
           departures below, one line each, same columns. No side scroll. -->
      <div class="pp-wrap" :style="tableScroll ? { maxHeight: tableScroll.y + 40 + 'px' } : null">
        <table class="pp-std">
          <thead>
            <tr>
              <th rowspan="2" class="pp-first">{{ es ? 'Ruta / salida · bus' : 'Route / departure · bus' }}</th>
              <th rowspan="2" class="r">{{ es ? 'Pasajes' : 'Tickets' }}<br><small>{{ es ? 'ocup.' : 'occ.' }}</small></th>
              <th :colspan="INCOME_COLS.length + 1" class="c pp-gi">{{ es ? 'Ingresos' : 'Income' }}</th>
              <th :colspan="EXPENSE_COLS.length + 1" class="c pp-ge">{{ es ? 'Egresos' : 'Expenses' }}</th>
              <th rowspan="2" class="r">{{ es ? 'Ganancia' : 'Profit' }}</th>
              <th rowspan="2"></th>
            </tr>
            <tr>
              <th v-for="c in INCOME_COLS" :key="c.key" class="r pp-gi pp-sub">{{ es ? c.es : c.en }}</th>
              <th class="r pp-gi pp-sub">Total</th>
              <th v-for="c in EXPENSE_COLS" :key="c.key" class="r pp-ge pp-sub">
                <a-tooltip v-if="noDataCol(c)" :title="noDataHelp(c.key === 'other' ? 'other' : c.key === 'staff' ? 'payroll' : c.key, es)"><span class="pp-dot">{{ es ? c.es : c.en }}</span></a-tooltip>
                <a-tooltip v-else-if="c.key === 'fuel'" :title="es ? 'El combustible se ingresa a mano; en rojo, estimado.' : 'Fuel is typed by hand; in red, estimated.'"><span class="pp-dot">{{ es ? c.es : c.en }}</span></a-tooltip>
                <template v-else>{{ es ? c.es : c.en }}</template>
              </th>
              <th class="r pp-ge pp-sub">Total</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in routeRows" :key="r.route_id">
              <tr class="pp-route" @click="toggle(r.route_id)">
                <td class="pp-first"><span class="pp-tg">{{ open[r.route_id] ? '−' : '+' }}</span> <b>{{ r.route }}</b>
                  <span class="pp-muted"> · <span class="pos">{{ r.gain_trips }}</span>/<span class="neg">{{ r.loss_trips }}</span><template v-if="r.trips_pending"> · {{ r.trips_pending }} {{ es ? 'por salir' : 'to leave' }}</template></span></td>
                <td class="r">{{ r.sold.toLocaleString() }} <span class="pp-muted">{{ r.occupancy != null ? r.occupancy + ' %' : '' }}</span></td>
                <td v-for="c in INCOME_COLS" :key="c.key" class="r">{{ whole(incomeOf(r.m.income, c.key)) }}</td>
                <td class="r pp-tot">{{ whole(r.m.income.total) }}</td>
                <td v-for="c in EXPENSE_COLS" :key="c.key" class="r" :class="{ 'pp-est': c.key === 'fuel' }">{{ whole(expenseOf(r.m.costs, c)) }}</td>
                <td class="r pp-tot">{{ whole(r.costs) }}</td>
                <td class="r"><b :class="r.profit >= 0 ? 'pos' : 'neg'">{{ r.trips_run ? whole(r.profit) : '—' }}</b></td>
                <td></td>
              </tr>
              <template v-if="open[r.route_id]">
                <tr v-for="t in r.trips" :key="t.id" class="pp-trip">
                  <td class="pp-first ell">{{ fmt(t.departure_at) }} <span class="pp-muted">· {{ String(t.vehicle || '').replace(/^Bus\s+/i, '') }}</span></td>
                  <td class="r" :title="t.break_even_seats != null ? (es ? `Se paga con ${t.break_even_seats} pasajes` : `Covered at ${t.break_even_seats} tickets`) : ''">{{ t.sold }} <span class="pp-muted">{{ t.occupancy != null ? t.occupancy + ' %' : '' }}</span></td>
                  <td v-for="c in INCOME_COLS" :key="c.key" class="r">{{ whole(incomeOf(t.income, c.key)) }}</td>
                  <td class="r pp-tot">{{ whole(t.income.total) }}</td>
                  <td v-for="c in EXPENSE_COLS" :key="c.key" class="r" :class="{ 'pp-est': c.key === 'fuel' && t.estimated.includes('fuel'), 'pp-muted': c.key === 'tolls' && t.estimated.includes('tolls') }">{{ whole(expenseOf(t.costs, c)) }}</td>
                  <td class="r pp-tot">{{ whole(t.costs.total) }}</td>
                  <td class="r"><b :class="t.result === 'pending' ? '' : (t.profit >= 0 ? 'pos' : 'neg')">{{ t.result === 'pending' ? '—' : whole(t.profit) }}</b></td>
                  <td class="c"><a-tooltip :title="es ? RESULT[t.result].es : RESULT[t.result].en"><i class="pp-res" :class="t.result" /></a-tooltip></td>
                </tr>
              </template>
            </template>
          </tbody>
        </table>
      </div>

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
        <!-- Plain bars (2026-11-27): readable in a narrow column. -->
        <div v-if="view === 'waterfall'" class="pp-bars">
          <div v-for="b in flowBars" :key="b.label" class="pp-bar-row">
            <div class="pp-bar-l"><span>{{ b.label }}</span><b :class="b.cls">{{ b.sign }}{{ short(b.value) }}</b> <small>{{ b.pct }} %</small></div>
            <div class="pp-bar-t"><i :style="{ width: Math.min(100, b.pct) + '%', background: b.color }" /></div>
          </div>
        </div>
        <div v-else-if="view === 'bubbles'" class="pp-bars">
          <div v-for="r in routeBars" :key="r.route" class="pp-rb">
            <div class="pp-rb-h">{{ r.route }}</div>
            <div v-for="b in r.bars" :key="b.label" class="pp-bar-row">
              <div class="pp-bar-l"><span>{{ b.label }}</span><b :class="b.cls">{{ short(b.value) }}</b></div>
              <div class="pp-bar-t"><i :style="{ width: b.w + '%', background: b.color }" /></div>
            </div>
          </div>
        </div>
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
import { INCOME_COLS, EXPENSE_COLS, incomeOf, expenseOf, sumMoney } from '../lib/moneyColumns';
import { noDataHelp } from '../lib/transportNoData';

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
    { label: es.value ? 'Egresos' : 'Expenses', value: money(t.costs), help: `${es.value ? 'Por viaje' : 'Per trip'} ${money(t.staff)} · ${es.value ? 'Planilla' : 'Payroll'} ${money(t.payroll)} · ${es.value ? 'Combustible' : 'Fuel'} ${money(t.fuel)} · ${es.value ? 'Mant.' : 'Maint.'} ${money(t.maintenance)} · Deprec. ${money(t.depreciation)} · ${es.value ? 'Peajes' : 'Tolls'} ${money(t.tolls)} · ${es.value ? 'Agencias' : 'Agencies'} ${money(t.agency)} · ${es.value ? 'Snack y bebida' : 'Snack and drink'} ${money(t.meals)}` },
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
  { value: 'waterfall', label: es.value ? 'Resultado' : 'Result' },
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
      yaxis: { labels: { style: { fontSize: '10.5px', fontWeight: 600 }, maxWidth: 82 } },
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
      { name: es.value ? 'Egresos' : 'Expenses', data: rows.map(r => Math.round(r.costs || 0)) },
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
// The side bars (2026-11-27): from income to profit, each line its share of the income; and each route.
const flowBars = computed(() => {
  const t = totals.value;
  const inc = Number(t.income || 0);
  const pct = v => (inc ? Math.round((v / inc) * 100) : 0);
  const out = [{ label: es.value ? 'Ingresos' : 'Income', value: inc, pct: 100, color: BLUE, sign: '' }];
  EXPENSE_COLS.forEach(c => {
    const v = expenseOf(t, c);
    if (v > 0) out.push({ label: `− ${es.value ? c.es : c.en}`, value: v, pct: pct(v), color: ORANGE, sign: '' });
  });
  const p = Number(t.profit || 0);
  out.push({ label: `= ${es.value ? 'Ganancia' : 'Profit'}`, value: Math.abs(p), pct: pct(Math.abs(p)), color: p >= 0 ? GOOD : BAD, sign: p < 0 ? '−' : '', cls: p >= 0 ? 'pos' : 'neg' });
  return out;
});
const routeBars = computed(() => {
  const rows = (data.value.routes || []).filter(r => r.trips_run);
  const max = Math.max(1, ...rows.map(r => Number(r.income || 0)));
  return rows.map(r => ({ route: r.route, bars: [
    { label: es.value ? 'Ingresos' : 'Income', value: r.income, w: (r.income / max) * 100, color: BLUE },
    { label: es.value ? 'Egresos' : 'Expenses', value: r.costs, w: (r.costs / max) * 100, color: ORANGE },
    { label: es.value ? 'Ganancia' : 'Profit', value: r.profit, w: (Math.abs(r.profit) / max) * 100, color: r.profit >= 0 ? GOOD : BAD, cls: r.profit >= 0 ? 'pos' : 'neg' },
  ] }));
});// The standard money table (2026-11-27).
const open = ref({});
const toggle = id => { open.value = { ...open.value, [id]: !open.value[id] }; };
const routeRows = computed(() => (data.value.routes || []).map(r => {
  const run = (r.trips || []).filter(t => t.result !== 'pending');
  return { ...r, m: sumMoney(run), sold: run.reduce((n, t) => n + Number(t.sold || 0), 0) };
}));
// A cost column empty on every route = no data for it yet: its helper says what is missing.
const noDataCol = c => c.key !== 'fuel' && c.key !== 'tolls' && !routeRows.value.some(r => expenseOf(r.m.costs, c) > 0);// The trips' small costs together (2026-11-27): payroll, crew, maintenance, depreciation, agency, meals.
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
  .pp-grid { grid-template-columns: minmax(0, 3.6fr) minmax(0, 1fr); }
}
.pp-wrap { overflow-y: auto; overflow-x: hidden; border: 1px solid #e1e4e8; border-radius: 8px; }
.pp-std { width: 100%; border-collapse: collapse; font-size: 11px; font-variant-numeric: tabular-nums; color: #252423; }
.pp-std th { position: sticky; top: 0; background: #f3f4f6; font-weight: 700; font-size: 12px; padding: 4px 4px; white-space: nowrap; border-bottom: 1px solid #e1e4e8; z-index: 1; }
.pp-std thead tr:nth-child(2) th { top: 26px; font-weight: 500; font-size: 10px; }
.pp-std th small { font-weight: 400; color: #8a94a6; }
.pp-std th.pp-gi { background: #eaf7ee; color: #1f7a45; }
.pp-std th.pp-ge { background: #fdeee8; color: #a5401a; }
.pp-std td { padding: 3px 4px; border-bottom: 1px solid #f0f1f3; white-space: nowrap; }
.pp-std .r { text-align: right; }
.pp-std .c { text-align: center; }
.pp-std .pp-first { text-align: left; max-width: 200px; overflow: hidden; text-overflow: ellipsis; }
.pp-std .pp-tot { font-weight: 600; }
.pp-route { cursor: pointer; background: #fafbfc; }
.pp-route:hover td { background: #f0f5fc; }
.pp-tg { display: inline-block; width: 14px; text-align: center; color: #118dff; font-weight: 700; }
.pp-trip td.pp-first { padding-left: 22px; }
.pp-bars { display: flex; flex-direction: column; gap: 8px; padding: 4px 2px; }
.pp-bar-l { display: flex; align-items: baseline; gap: 6px; font-size: 11.5px; }
.pp-bar-l span { flex: 1; color: #475467; font-weight: 600; }
.pp-bar-l b { font-variant-numeric: tabular-nums; }
.pp-bar-l small { color: #8a94a6; min-width: 34px; text-align: right; }
.pp-bar-t { height: 8px; background: #eef1f5; border-radius: 4px; overflow: hidden; margin-top: 2px; }
.pp-bar-t i { display: block; height: 100%; border-radius: 4px; }
.pp-rb { border-top: 1px solid #eef1f5; padding-top: 6px; }
.pp-rb:first-child { border-top: none; padding-top: 0; }
.pp-rb-h { font-size: 12px; font-weight: 700; color: #12239e; margin-bottom: 4px; }
</style>
