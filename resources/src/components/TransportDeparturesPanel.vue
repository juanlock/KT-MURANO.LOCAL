<template>
  <!-- The content of the client's daily report as a board (2026-11-05): per agency and day (App\Services\TransportDepartures). -->
  <div ref="root">
    <a-card v-if="data.enabled" class="td" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <a-radio-group v-model:value="agency" size="small" button-style="solid" class="td-agencies" @change="load">
          <a-radio-button v-for="a in agencies" :key="a.id" :value="a.id">{{ a.name }} · {{ a.departures }}</a-radio-button>
        </a-radio-group>
        <TransportStrip :items="tiles" :toggle="false" />
        <a-empty v-if="!rows.length" :description="tr('No departures from this agency on this day.', 'Sin salidas de esta agencia este día.')" />
        <div v-else class="td-grid">
          <div class="td-main">
            <div class="td-box" :style="{ '--td-line': `${lineHeight}px` }">
              <a-table class="td-table" :columns="columns" :data-source="rows" row-key="id" size="small" :pagination="false" :row-class-name="(_, i) => (i % 2 ? '' : 'td-tint')" :scroll="fit ? undefined : { x: 'max-content' }">
                <template #headerCell="{ column }">
                  <a-tooltip v-if="column.key === 'free_trips'" title="P.C 10mo B.G"><span>{{ column.title }}</span></a-tooltip>
                  <template v-else>{{ column.title }}</template>
                </template>
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'bus'"><b>{{ busName(record.bus) }}</b></template>
                  <template v-else-if="column.key === 'points'">
                    <div class="td-lines"><div v-for="(p, i) in record.points" :key="i"><b :title="p.full">{{ p.name }}</b></div></div>
                  </template>
                  <template v-else-if="column.key === 'times'">
                    <div class="td-lines"><div v-for="(p, i) in record.points" :key="i">{{ time(p.at) }}</div></div>
                  </template>
                  <template v-else-if="column.key === 'load'">
                    <div class="td-load" :title="record.seats ? `${occupancy(record)} %` : ''">
                      <div v-if="record.seats" class="td-bar"><i :style="{ width: `${occupancy(record)}%` }" /></div>
                      <span class="td-count">{{ record.passengers }}<template v-if="record.seats">/{{ record.seats }}</template></span>
                    </div>
                  </template>
                  <template v-else-if="column.key === 'free'">{{ record.free ?? '—' }}</template>
                  <template v-else-if="column.key === 'free_trips'">{{ record.free_trips || '—' }}</template>
                  <template v-else-if="column.key === 'amount'">
                    <b :class="{ 'td-low': record.amount < data.low_amount }"><ExclamationCircleFilled v-if="record.amount < data.low_amount" class="td-low-icon" /> {{ plain(record.amount) }}</b>
                  </template>
                  <template v-else-if="column.key === 'toll'">{{ record.toll != null ? plain(record.toll) : '—' }}</template>
                  <template v-else-if="column.key === 'fuel'">{{ record.fuel != null ? plain(record.fuel) : '—' }}</template>
                </template>
              </a-table>
            </div>
          </div>
          <div class="td-side">
            <div class="td-side-top">
              <TransportChartBlock :key="`income-${sheet}`" :chart="incomeChart" />
              <TransportChartBlock :key="`load-${sheet}`" :chart="loadChart" />
            </div>
            <TransportChartBlock v-if="hourChart" :key="`hours-${sheet}`" :chart="hourChart" />
          </div>
        </div>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import { ExclamationCircleFilled } from '@ant-design/icons-vue';
import http from '../lib/http';
import { BLUE, GRAY, RED, useFitHeight, useTransportCharts } from '../lib/transportCharts';
import TransportChartBlock from './TransportChartBlock.vue';
import TransportStrip from './TransportStrip.vue';

// The dashboard's date filter: a report is one day, the last of the range. Without dates, today.
const props = defineProps({ range: { type: Array, default: null } });

const { ui, money, tr, small, short, base, hbar } = useTransportCharts();
const root = ref(null);
const { fit, measure } = useFitHeight(root);

const agency = ref(null);
const loading = ref(false);
const data = ref({ enabled: false, agencies: [], rows: [] });
const rows = computed(() => data.value.rows || []);
// Charts are redrawn for another agency or day: ApexCharts keeps a donut's center label across an update.
const sheet = computed(() => `${data.value.agency_id}-${data.value.date}`);

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('transport/departures', { date: props.range?.[1], agency_id: agency.value });
    agency.value = data.value.agency_id;
  } catch (e) {
    data.value = { enabled: false, agencies: [], rows: [] };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
}
onMounted(load);
watch(() => props.range?.[1], load);

const time = d => dayjs(d).format('hh:mm A');
const busName = name => String(name).replace(/^Bus\s+/i, '');
const occupancy = r => Math.min(100, Math.round((r.passengers / r.seats) * 100));
// In the table the currency is left to the figures above it, so half the board is enough.
const plain = v => money(v).replace(/^[^\d-]+/, '');
const sum = key => rows.value.reduce((s, r) => s + (r[key] || 0), 0);

// Agencies with departures that day (and the one in use).
const agencies = computed(() => (data.value.agencies || []).filter(a => a.departures > 0 || a.id === agency.value));
// A report without costs (Chiclayo's) has no toll or fuel to show.
const hasCosts = computed(() => rows.value.some(r => r.toll != null || r.fuel != null));

const tiles = computed(() => {
  const d = data.value;
  const p = d.price;
  return [
    { label: tr('Day', 'Día'), value: d.date ? dayjs(d.date).format('DD/MM/YYYY') : '—', help: tr("The end date of the filter above; if it has no real departures, the client's latest report.", 'La fecha final del filtro de arriba; si no tiene salidas reales, el último reporte del cliente.') },
    { label: tr("Day's price", 'Precio del día'), value: p ? `${p.high} / ${p.low}` : '—' },
    { label: tr('Departures', 'Salidas'), value: rows.value.length },
    { label: tr('Passengers', 'Pasajeros'), value: sum('passengers') },
    { label: tr('Free seats', 'Asientos libres'), value: sum('free') },
    { label: tr('Income', 'Ingreso'), value: money(sum('amount')), help: tr(`Marked in red, a bus under ${money(d.low_amount)}.`, `Marcado en rojo, un bus con menos de ${money(d.low_amount)}.`) },
    ...(hasCosts.value ? [
      { label: tr('Toll', 'Peaje'), value: money(sum('toll')) },
      { label: tr('Fuel', 'Combustible'), value: money(sum('fuel')) },
    ] : []),
  ];
});

const columns = computed(() => [
  { title: 'Bus', key: 'bus', width: 34 },
  { title: tr('Boarding points', 'Puntos de embarque'), key: 'points', width: 84 },
  { title: tr('Time', 'Horario'), key: 'times', width: 62 },
  { title: tr('Pass. / seats', 'Pas. / asientos'), key: 'load', width: 84 },
  { title: tr('Free', 'Libres'), key: 'free', align: 'right', width: 40 },
  { title: tr('10th', '10mo'), key: 'free_trips', align: 'right', width: 36 },
  { title: tr('Amount', 'Monto'), key: 'amount', align: 'right', width: 72 },
  ...(hasCosts.value ? [
    { title: tr('Toll', 'Peaje'), key: 'toll', align: 'right', width: 48 },
    { title: tr('Fuel', 'Combustible'), key: 'fuel', align: 'right', width: 74 },
  ] : []),
]);

// What the board has left once the card's padding, the agencies and the strip are out.
const room = computed(() => (fit.value ? fit.value - 16 - 32 - 58 : null));
const BLOCK = 36; // what a chart's block adds around it
const TABLE_HEAD = 44; // two lines: a long title wraps rather than widen its column
const MIN_ROW = 24;
// One line per boarding point. The table has its half of the board to itself, so its lines take the height there is.
const lineHeight = computed(() => {
  const lines = rows.value.reduce((s, r) => s + r.points.length, 0);
  if (!room.value || !lines) return 18;
  return Math.max(17, Math.min(38, Math.floor((room.value - TABLE_HEAD - 2 - rows.value.length) / lines)));
});
const topHeight = computed(() => (room.value ? Math.floor((room.value - 8) * 0.55) - BLOCK : 240));
const hourHeight = computed(() => {
  if (!room.value) return 180;
  return Math.floor((room.value - 8) * 0.45) - BLOCK;
});

const incomeChart = computed(() => {
  const list = [...rows.value].sort((a, b) => b.amount - a.amount);
  const height = topHeight.value;
  return {
    key: 'income', type: 'bar', height,
    title: tr('Income per bus', 'Ingreso por bus'),
    help: tr(`In red, under ${money(data.value.low_amount)}.`, `En rojo, menos de ${money(data.value.low_amount)}.`),
    series: [{ name: tr('Income', 'Ingreso'), data: list.map(r => r.amount) }],
    options: hbar(list.map(r => busName(r.bus)), [{ data: list.map(r => r.amount) }], {
      height, colors: [BLUE], labelWidth: 40,
      ranges: [{ from: 0, to: data.value.low_amount - 0.001, color: RED }, { from: data.value.low_amount, to: 1e12, color: BLUE }],
    }),
  };
});

const loadChart = computed(() => {
  const passengers = sum('passengers');
  const free = sum('free');
  const total = passengers + free;
  const label = () => (total ? `${Math.round((passengers / total) * 100)} %` : '—');
  const o = base('donut');
  return {
    key: 'load', type: 'donut', height: room.value ? topHeight.value : 200,
    title: tr('Occupancy', 'Ocupación'),
    help: tr("Passengers over the seats of the day's departures.", 'Pasajeros sobre los asientos de las salidas del día.'),
    series: [passengers, free],
    options: {
      ...o, colors: [BLUE, GRAY], labels: [tr('Passengers', 'Pasajeros'), tr('Free', 'Libres')],
      stroke: { width: 2, colors: [ui.dark ? '#1a1a19' : '#fff'] },
      legend: { ...o.legend, position: 'bottom', horizontalAlign: 'center', offsetY: 0, height: 18 },
      tooltip: { ...o.tooltip, y: { formatter: v => v } },
      plotOptions: { pie: { donut: { size: '68%', labels: { show: true, name: { show: false }, value: { show: true, fontSize: '16px', fontWeight: 700, offsetY: 5, formatter: label }, total: { show: true, showAlways: true, formatter: label } } } } },
    },
  };
});

// Passengers by the hour each bus starts boarding, with the empty hours between them.
const hourChart = computed(() => {
  const height = hourHeight.value;
  if (!rows.value.length || height < 80) return null;
  const hours = rows.value.map(r => dayjs(r.points[0].at).hour());
  const first = Math.min(...hours);
  const last = Math.max(...hours);
  const axis = Array.from({ length: last - first + 1 }, (_, i) => first + i);
  const values = axis.map(h => rows.value.filter((_, i) => hours[i] === h).reduce((s, r) => s + r.passengers, 0));
  const o = base('bar');
  return {
    key: 'hours', type: 'bar', height,
    title: tr('Passengers by departure time', 'Pasajeros por hora de salida'),
    help: tr("The hour of each bus's first boarding point.", 'La hora del primer punto de embarque de cada bus.'),
    series: [{ name: tr('Passengers', 'Pasajeros'), data: values }],
    options: {
      ...o, colors: [BLUE],
      plotOptions: { bar: { columnWidth: '24%', borderRadius: 3, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
      dataLabels: { enabled: true, offsetY: -16, formatter: v => (v ? short(v) : ''), style: { fontSize: '10px', fontWeight: 500, colors: [ui.dark ? '#f0efec' : '#0b0b0b'] } },
      xaxis: { categories: axis.map(h => dayjs().hour(h).format('hh A')), labels: { style: small }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
      yaxis: { show: false, max: Math.ceil(Math.max(1, ...values) * 1.25) },
      grid: { ...o.grid, padding: { ...o.grid.padding, top: 0 }, yaxis: { lines: { show: false } } },
      tooltip: { ...o.tooltip, y: { formatter: v => v } },
    },
  };
});
</script>

<style scoped>
.td { margin-bottom: 0; }
.td-agencies { margin-bottom: 8px; display: block; }
.td-grid { display: grid; gap: 8px; grid-template-columns: 1fr; }
.td-main, .td-side { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.td-side-top { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.td-box { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; overflow: hidden; }
.td-table :deep(.ant-table-thead > tr > th) { padding: 3px 4px !important; line-height: 1.25; font-size: 11.5px; }
.td-table :deep(.ant-table-tbody > tr > td) { padding: 2px 4px !important; height: 24px; font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.td-table :deep(.ant-table-tbody > tr:last-child > td) { border-bottom: none; }
.td-table :deep(tr.td-tint > td) { background: rgba(42, 120, 214, 0.09); }
.td-lines { margin: -2px -4px; }
.td-lines > div { height: var(--td-line, 18px); line-height: var(--td-line, 18px); padding: 0 4px; box-sizing: border-box; }
.td-lines > div + div { border-top: 1px dashed rgba(128, 128, 128, 0.25); }
.td-lines b { font-weight: 600; }
.td-load { display: flex; align-items: center; gap: 6px; }
.td-bar { flex: 1; min-width: 24px; height: 6px; border-radius: 3px; background: rgba(128, 128, 128, 0.2); overflow: hidden; }
.td-bar i { display: block; height: 100%; border-radius: 3px; background: #2a78d6; }
.td-count { min-width: 34px; text-align: right; }
.td-low { color: #d03b3b; }
.td-low-icon { font-size: 11px; }
@media (min-width: 900px) {
  .td-grid { grid-template-columns: minmax(500px, 1fr) minmax(0, 1fr); }
  .td-side-top { display: grid; gap: 8px; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); }
}
@media (min-width: 1600px) {
  .td-table :deep(.ant-table-tbody > tr > td) { font-size: 13px; }
}
</style>
