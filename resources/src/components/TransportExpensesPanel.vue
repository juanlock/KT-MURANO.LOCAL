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
const PALETTE = ['#2563eb', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569', '#a16207', '#4f46e5', '#0d9488'];
const colorOf = key => PALETTE[Math.max(0, (data.value.kinds || []).findIndex(k => k.key === key)) % PALETTE.length];

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

// The ring: every kind, the small ones together.
const donutChart = computed(() => {
  const ks = data.value.kinds || [];
  const main = ks.filter(k => k.share >= 2);
  const rest = ks.filter(k => k.share < 2);
  const items = [...main.map(k => ({ name: k.name, value: k.amount, color: colorOf(k.key) })),
    ...(rest.length ? [{ name: tr('Others', 'Otros'), value: rest.reduce((s, k) => s + k.amount, 0), color: '#cbd5e1' }] : [])];
  const o = base('donut');
  return {
    key: 'donut', title: tr('Where the money goes', 'En qué se va el dinero'), type: 'donut', height: tall.value,
    help: tr('Share of each kind in the period. Kinds under 2 % go together in Others.', 'Parte de cada rubro en el periodo. Los rubros de menos del 2 % van juntos en Otros.'),
    series: items.map(i => Math.round(i.value)),
    options: { ...o, labels: items.map(i => i.name), colors: items.map(i => i.color), legend: { ...o.legend, position: 'bottom', horizontalAlign: 'center', height: undefined },
      dataLabels: { enabled: true, formatter: v => `${Math.round(v)}%`, style: { fontSize: '10px' }, dropShadow: { enabled: false } },
      plotOptions: { pie: { donut: { size: '58%', labels: { show: true, total: { show: true, label: tr('Total', 'Total'), formatter: () => short(data.value.total) } } } } },
      stroke: { width: 1 } },
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
  const o = base('bar');
  return {
    key: 'trend', title: tr('Trend', 'Tendencia') + ` · ${d.unit === 'day' ? tr('per day', 'por día') : d.unit === 'week' ? tr('per week', 'por semana') : tr('per month', 'por mes')}`,
    type: 'bar', height: tall.value,
    help: tr('Expenses of each period, stacked by kind (the 6 largest; the rest in Others).', 'Gastos de cada periodo, apilados por rubro (los 6 mayores; el resto en Otros).'),
    series,
    options: { ...o, chart: { ...o.chart, stacked: true }, colors: [...top.map(k => colorOf(k.key)), '#cbd5e1'],
      plotOptions: { bar: { columnWidth: '70%', borderRadius: 2, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
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
</style>
