<template>
  <!-- "Gastos" (user's request, 2026-11-11): what the business spends, by kind, in the dashboard's period, one screen.
       App\Services\TransportExpenses: the trips' costs (same as Profit by route) and the services paid (Accounting -> Expenses). -->
  <div ref="root">
    <a-card v-if="data.enabled" class="te" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <a-empty v-if="!data.total" :description="tr('No expenses on these dates.', 'No hay gastos en estas fechas.')" />
        <template v-else>
          <TransportStrip :items="tiles" :toggle="false" />
          <div class="te-grid">
            <TransportChartBlock :chart="donutChart" class="te-donut" />
            <TransportChartBlock :chart="trendChart" class="te-trend" />
            <div class="te-box te-table">
              <div class="te-title">{{ tr('By kind', 'Por rubro') }}</div>
              <div class="te-wrap" :style="{ maxHeight: `${tall}px` }">
                <table>
                  <thead>
                    <tr><th>{{ tr('Kind', 'Rubro') }}</th><th class="num">S/</th><th class="num">%</th><th class="num" :title="tr('Against the same number of days just before', 'Frente a los mismos días justo antes')">{{ tr('vs prev.', 'vs ant.') }}</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="k in data.kinds" :key="k.key">
                      <td><i class="te-dot" :style="{ background: colorOf(k.key) }" />{{ k.name }}<span v-if="k.group === 'services'" class="te-tag">{{ tr('service', 'servicio') }}</span></td>
                      <td class="num">{{ whole(k.amount) }}</td>
                      <td class="num">{{ k.share }}</td>
                      <td class="num" :class="k.change > 0 ? 'up' : k.change < 0 ? 'down' : ''">{{ k.change === null ? '—' : (k.change > 0 ? '+' : '') + k.change + ' %' }}</td>
                    </tr>
                    <!-- Kinds without real data yet (2026-11-27): listed with 0 and what is missing. -->
                    <tr v-for="k in missingKinds" :key="'no-' + k.key" class="te-nodata">
                      <td><i class="te-dot" style="background: #d0d5dd" />{{ k.name }}
                        <a-tooltip :title="noDataHelp(k.key, isEs)"><InfoCircleOutlined class="te-ni" /></a-tooltip></td>
                      <td class="num">0</td><td class="num">0</td><td class="num">—</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </template>
      </a-spin>
    </a-card>
  </div>
</template>

<script setup>
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import { noDataHelp, COST_KINDS_ALWAYS } from '../lib/transportNoData';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import http from '../lib/http';
import { useFitHeight, useTransportCharts } from '../lib/transportCharts';
import { useFormat } from '../composables/useFormat';
import TransportChartBlock from './TransportChartBlock.vue';
import TransportStrip from './TransportStrip.vue';

// The dashboard's own date filter: [from, to], null = this month.
const props = defineProps({ range: { type: Array, default: null } });

const { money, tr, short, small, base } = useTransportCharts();
const { dayMonth } = useFormat();
// Whole soles in the table: the cents only widen it.
const whole = v => Math.round(v).toLocaleString('es-PE');
const root = ref(null);
const { fit, measure } = useFitHeight(root);
const tall = computed(() => (fit.value ? fit.value - 16 - 58 - 36 : 300));

const loading = ref(false);
const data = ref({ enabled: false });
async function load() {
  loading.value = true;
  try {
    data.value = await http.get('transport/expenses', { from: props.range?.[0], to: props.range?.[1] });
  } catch (e) {
    data.value = { enabled: false };
  } finally {
    loading.value = false;
    nextTick(measure);
  }
}
onMounted(load);
watch(() => props.range, load);

// One colour per kind, the same in the ring, the trend and the table.
// Turismo's cost kinds with no real data yet (2026-11-27): always listed, with 0 and the helper.
const KIND_NAMES = { payroll: ['Payroll', 'Planilla'], depreciation: ['Depreciation', 'Depreciación'], maintenance: ['Maintenance', 'Mantenimiento'], agency: ['Agencies (daily cost)', 'Agencias (costo diario)'] };
const isEs = computed(() => tr('en', 'es') === 'es');
const missingKinds = computed(() => (data.value.kinds ? COST_KINDS_ALWAYS.filter(k => !(data.value.kinds || []).some(x => x.key === k && Number(x.amount) > 0))
  .map(k => ({ key: k, name: tr(...KIND_NAMES[k]) })) : []));// Power BI's palette (2026-11-23); past 8 kinds the rest fold into "Otros" (gray), never a made-up hue.
const PALETTE = ['#118DFF', '#3A4FC4', '#E66C37', '#8E2C9E', '#E044A7', '#744EC2', '#B08F00', '#D64550'];
// Never a repeated hue: past the 8th kind, gray (it is in "Otros" on the chart anyway).
const colorOf = (key) => { const i = Math.max(0, (data.value.kinds || []).findIndex(k => k.key === key)); return i < PALETTE.length ? PALETTE[i] : '#9aa0a6'; };

const tiles = computed(() => {
  const d = data.value;
  // Only against a comparable period (with 'All' the days before hold almost nothing).
  const change = d.previous >= d.total * 0.2 ? Math.round((d.total - d.previous) / d.previous * 100) : null;
  const top = (d.kinds || [])[0];
  return [
    { label: tr('Expenses', 'Gastos'), value: money(d.total), note: change === null ? '' : `${change > 0 ? '+' : ''}${change} % ${tr('vs before', 'vs anterior')}`,
      help: tr('The trips run (crew, payroll, fuel, maintenance, depreciation, tolls, agencies, meals) and the services paid in Accounting -> Expenses. Compared with the same number of days just before.', 'Los viajes realizados (tripulación, planilla, combustible, mantenimiento, depreciación, peajes, agencias, refrigerios) y los servicios pagados en Contabilidad -> Gastos. Comparado con los mismos días justo antes.') },
    { label: tr('Of the income', 'De los ingresos'), value: d.of_income === null ? '—' : `${d.of_income} %`, note: money(d.income) },
    { label: tr('Per trip', 'Por viaje'), value: d.per_trip === null ? '—' : money(d.per_trip), note: `${d.trips} ${tr('trips', 'viajes')}` },
    { label: tr('Largest', 'Mayor rubro'), value: top ? top.name : '—', note: top ? `${top.share} %` : '' },
  ];
});

// Where the money goes (2026-11-23: a treemap instead of the ring, Power BI style): each kind a box sized by its amount,
// with its name, share and amount inside; the small ones together in Others.
const donutChart = computed(() => {
  const ks = data.value.kinds || [];
  const main = ks.filter(k => k.share >= 2);
  const rest = ks.filter(k => k.share < 2);
  const total = data.value.total || 1;
  const items = [...main.map(k => ({ name: k.name, value: k.amount, color: colorOf(k.key) })),
    ...(rest.length ? [{ name: tr('Others', 'Otros'), value: rest.reduce((s, k) => s + k.amount, 0), color: '#9aa0a6' }] : [])];
  const o = base('treemap');
  return {
    key: 'donut', title: tr('Where the money goes', 'En qué se va el dinero'), type: 'treemap', height: tall.value,
    help: tr('Each box is a kind, sized by what it cost in the period. Kinds under 2 % go together in Others.', 'Cada recuadro es un rubro, del tamaño de lo que costó en el periodo. Los rubros de menos del 2 % van juntos en Otros.'),
    series: [{ data: items.map(i => ({ x: i.name, y: Math.round(i.value) })) }],
    options: { ...o, legend: { show: false }, colors: items.map(i => i.color),
      plotOptions: { treemap: { distributed: true, enableShades: false, borderRadius: 3 } },
      dataLabels: { enabled: true, style: { fontSize: '11px', fontWeight: 600 }, offsetY: -2,
        formatter: (text, op) => [text, `${Math.round((op.value / total) * 100)} % · ${short(op.value)}`] },
      stroke: { width: 2, colors: ['#ffffff'] },
      tooltip: { ...o.tooltip, y: { formatter: v => `${money(v)} (${Math.round((v / total) * 100)} %)` } } },
    table: { cols: [tr('Kind', 'Rubro'), 'S/'], rows: items.map(i => [i.name, money(i.value)]) },
  };
});

// The trend: stacked by kind (the 6 largest, the rest together), per day / week / month.
const trendChart = computed(() => {
  const d = data.value;
  const ks = d.kinds || [];
  const top = ks.slice(0, 6);
  const rest = ks.slice(6);
  const points = d.trend || [];
  const series = [
    ...top.map(k => ({ name: k.name, data: points.map(p => Math.round(p.values[k.key] || 0)) })),
    ...(rest.length ? [{ name: tr('Others', 'Otros'), data: points.map(p => Math.round(rest.reduce((s, k) => s + (p.values[k.key] || 0), 0))) }] : []),
  ];
  const label = p => (d.unit === 'month' ? dayjs(p.date).format('MM-YY') : dayMonth(p.date));
  // Stacked areas (2026-11-23, instead of stacked bars): the whole band is the total spend, each layer a kind.
  const o = base('area');
  return {
    key: 'trend', title: tr('Trend', 'Tendencia') + ` · ${d.unit === 'day' ? tr('per day', 'por día') : d.unit === 'week' ? tr('per week', 'por semana') : tr('per month', 'por mes')}`,
    type: 'area', height: tall.value,
    help: tr('Expenses of each period, stacked by kind (the 6 largest; the rest in Others): the top edge is the total.', 'Gastos de cada periodo, apilados por rubro (los 6 mayores; el resto en Otros): el borde de arriba es el total.'),
    series,
    options: { ...o, chart: { ...o.chart, stacked: true }, colors: [...top.map(k => colorOf(k.key)), '#9aa0a6'],
      stroke: { width: 1.5, curve: 'smooth' }, fill: { type: 'solid', opacity: 0.72 }, markers: { size: 0 },
      xaxis: { categories: points.map(label), labels: { style: small, rotate: -45, hideOverlappingLabels: true }, axisTicks: { show: false } },
      yaxis: { labels: { style: small, formatter: v => short(v) } },
      tooltip: { ...o.tooltip, shared: true, intersect: false } },
    table: { cols: [tr('Period', 'Periodo'), ...series.map(s => s.name)], rows: points.map((p, i) => [label(p), ...series.map(s => money(s.data[i]))]) },
  };
});
</script>

<style scoped>
.te { margin-bottom: 0; }
.te-grid { display: grid; gap: 8px; grid-template-columns: 1fr; margin-top: 8px; }
.te-box { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 8px; min-width: 0; }
.te-title { font-weight: 600; font-size: 13px; line-height: 20px; margin-bottom: 4px; }
.te-wrap { overflow: auto; }
.te-table table { width: 100%; border-collapse: collapse; font-size: 12px; font-variant-numeric: tabular-nums; }
.te-table th { text-align: left; font-weight: 400; opacity: 0.7; font-size: 11px; padding: 2px 6px; white-space: nowrap; }
.te-table td { padding: 3px 5px; border-top: 1px solid rgba(128, 128, 128, 0.15); white-space: nowrap; }
.te-table td:first-child { white-space: normal; }
.te-table .num { text-align: right; }
.te-table .up { color: #dc2626; }
.te-table .down { color: #16a34a; }
.te-dot { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 6px; }
.te-tag { margin-left: 6px; font-size: 10px; padding: 0 5px; border-radius: 6px; background: rgba(128, 128, 128, 0.15); opacity: 0.8; }
@media (min-width: 900px) {
  .te-grid { grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.4fr) minmax(0, 1fr); }
}
.te-nodata td { color: #98a2b3; }
.te-ni { margin-left: 4px; cursor: help; }
</style>
