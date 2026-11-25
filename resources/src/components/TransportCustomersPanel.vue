<template>
  <!-- "Clientes" (2026-11-24): who buys, from AuraSoft's Liquidación Detallada (DNI / RUC) -- App\Services\TransportAuraBoards::customers.
       The customers themselves are in People -> Customers, type Pasajero (no portal). -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <TransportStrip :items="tiles" :toggle="false" />
        <a-empty v-if="!data.totals?.active" :description="emptyText" />
        <div v-else class="cu-grid">
          <!-- New vs returning by week -->
          <div class="cu-box">
            <div class="cu-h">{{ tr('Customers by week: new and returning', 'Clientes por semana: nuevos y que vuelven') }}</div>
            <apexchart v-if="data.weeks?.length" type="bar" height="200" :options="weekOptions" :series="weekSeries" />
          </div>
          <!-- How often they buy -->
          <div class="cu-box">
            <div class="cu-h">{{ tr('How often they bought in the period', 'Cuántas veces compraron en el período') }}</div>
            <div v-for="f in freq" :key="f.k" class="cu-bar">
              <span class="cu-l">{{ f.label }}</span>
              <span class="cu-track"><span class="cu-fill" :style="{ width: f.w + '%' }" /></span>
              <span class="cu-v">{{ num(f.n) }}</span><span class="cu-p">{{ f.pct }} %</span>
            </div>
          </div>
          <!-- Best customers -->
          <div class="cu-box">
            <div class="cu-h">{{ tr('Best customers in the period', 'Mejores clientes del período') }}</div>
            <table class="cu-t">
              <thead><tr><th>{{ tr('Customer', 'Cliente') }}</th><th>{{ tr('Purch.', 'Compras') }}</th><th>{{ tr('Last', 'Última') }}</th><th>S/</th></tr></thead>
              <tbody>
                <tr v-for="c in data.top" :key="c.doc">
                  <td class="cu-name"><span class="cu-tag" :class="c.company ? 'ruc' : 'dni'">{{ c.company ? 'RUC' : 'DNI' }}</span> {{ c.name || c.doc }}<span v-if="c.first >= data.from" class="cu-new">{{ tr('new', 'nuevo') }}</span></td>
                  <td class="cu-num">{{ c.n }}<span v-if="c.parcels" class="cu-mut"> · {{ c.parcels }} {{ tr('parc.', 'enc.') }}</span></td>
                  <td class="cu-num">{{ date(c.last) }}</td>
                  <td class="cu-num"><b>{{ plain(c.amount) }}</b></td>
                </tr>
              </tbody>
            </table>
          </div>
          <!-- To win back -->
          <div class="cu-box">
            <div class="cu-h">{{ tr('Good customers who stopped buying', 'Buenos clientes que dejaron de comprar') }}
              <a-tooltip :title="tr('3 or more purchases, and none in the last 30 days up to the period\'s end. Worth a call or an offer.', '3 o más compras y ninguna en los últimos 30 días hasta el fin del período. Vale una llamada o una oferta.')"><InfoCircleOutlined class="cu-i" /></a-tooltip>
              <span class="cu-mut">· {{ num(data.totals.lost) }}</span>
            </div>
            <div v-if="!data.lost?.length" class="cu-mut">{{ tr('None.', 'Ninguno.') }}</div>
            <table v-else class="cu-t">
              <thead><tr><th>{{ tr('Customer', 'Cliente') }}</th><th>{{ tr('Purch.', 'Compras') }}</th><th>{{ tr('Last', 'Última') }}</th><th>S/</th></tr></thead>
              <tbody>
                <tr v-for="c in data.lost" :key="c.doc">
                  <td class="cu-name"><span class="cu-tag" :class="c.company ? 'ruc' : 'dni'">{{ c.company ? 'RUC' : 'DNI' }}</span> {{ c.name || c.doc }}</td>
                  <td class="cu-num">{{ c.n }}</td><td class="cu-num">{{ date(c.last) }}</td><td class="cu-num"><b>{{ plain(c.amount) }}</b></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="cu-note">
          {{ tr('From AuraSoft\'s "Liquidación Detallada" (customers with DNI / RUC). They are in People -> Customers as type Passenger, without portal.', 'De la «Liquidación Detallada» de AuraSoft (clientes con DNI / RUC). Están en Personas -> Clientes como tipo Pasajero, sin portal.') }}
          <template v-if="data.available?.from"> {{ tr('Data from', 'Hay datos del') }} {{ date(data.available.from) }} {{ tr('to', 'al') }} {{ date(data.available.to) }}.</template>
        </div>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { BLUE, ORANGE, useTransportCharts } from '../lib/transportCharts';
import { useFormat } from '../composables/useFormat';
import TransportStrip from './TransportStrip.vue';

const props = defineProps({ range: { type: Array, default: null } });
const { money, tr, base, short, small } = useTransportCharts();
const { date } = useFormat();
const loading = ref(false);
const data = ref({ enabled: false });
const period = () => {
  const today = dayjs().format('YYYY-MM-DD');
  return { from: props.range?.[0] || dayjs().startOf('month').format('YYYY-MM-DD'), to: props.range?.[1] && props.range[1] < today ? props.range[1] : today };
};
async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/customers-board', period()); } catch (e) { data.value = { enabled: false }; } finally { loading.value = false; }
}
onMounted(load);
watch(() => props.range, load, { deep: true });

const num = v => Number(v || 0).toLocaleString('en-US');
const plain = v => money(Number(v || 0)).replace(/^[^\d-]+/, '');
const emptyText = computed(() => (data.value.available?.from
  ? tr('No customers in this period.', 'Sin clientes en este período.')
  : tr('Upload AuraSoft\'s "Liquidación Detallada" to see the customers.', 'Suba la «Liquidación Detallada» de AuraSoft para ver los clientes.')));
const tiles = computed(() => {
  const t = data.value.totals || {};
  return [
    { label: tr('Customers who bought', 'Clientes que compraron'), value: num(t.active), help: `${num(data.value.passengers)} ${tr('passengers in Customers', 'pasajeros en Clientes')}` },
    { label: tr('New', 'Nuevos'), value: num(t.new), help: tr('First purchase ever in this period.', 'Primera compra en este período.') },
    { label: tr('Returning', 'Volvieron'), value: num(t.returning) },
    { label: tr('People / companies', 'Personas / empresas'), value: `${num(t.people)} / ${num(t.companies)}` },
    { label: tr('Spend per customer', 'Gasto por cliente'), value: money(t.avg || 0) },
    { label: tr('Companies (RUC)', 'Empresas (RUC)'), value: money(t.company_amount || 0) },
    { label: tr('To win back', 'Por recuperar'), value: num(t.lost), help: tr('Good customers who stopped buying.', 'Buenos clientes que dejaron de comprar.') },
  ];
});
const FREQ = { 1: ['Once', '1 vez'], 2: ['Twice', '2 veces'], '3-5': ['3 to 5', '3 a 5'], '6-10': ['6 to 10', '6 a 10'], '11+': ['11 or more', '11 o más'] };
const freq = computed(() => {
  const f = data.value.frequency || {};
  const total = Object.values(f).reduce((s, n) => s + n, 0) || 1;
  const max = Math.max(1, ...Object.values(f));
  return Object.entries(f).map(([k, n]) => ({ k, n, label: tr(...FREQ[k]), pct: Math.round((n / total) * 1000) / 10, w: (n / max) * 100 }));
});
const weekSeries = computed(() => [
  { name: tr('New', 'Nuevos'), data: (data.value.weeks || []).map(w => w.new) },
  { name: tr('Returning', 'Volvieron'), data: (data.value.weeks || []).map(w => w.returning) },
]);
const weekOptions = computed(() => {
  const o = base('bar');
  return { ...o, chart: { ...o.chart, stacked: true }, colors: [ORANGE, BLUE],
    plotOptions: { bar: { columnWidth: '60%', borderRadius: 3, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
    stroke: { show: true, width: 1, colors: ['transparent'] },
    xaxis: { categories: (data.value.weeks || []).map(w => dayjs(w.week).format('DD/MM')), labels: { style: small, rotate: 0, hideOverlappingLabels: true }, axisTicks: { show: false } },
    yaxis: { labels: { formatter: short, style: small } }, tooltip: { ...o.tooltip, shared: true, intersect: false, y: { formatter: v => num(v) } } };
});
</script>

<style scoped>
.cu-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 6px; }
.cu-box { border: 1px solid rgba(128, 128, 128, 0.14); border-radius: 4px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.07); padding: 6px 8px; min-width: 0;
  max-height: calc(50vh - 120px); min-height: 180px; overflow: auto; }
.cu-h { font-weight: 600; font-size: 12.5px; margin-bottom: 4px; }
.cu-i { opacity: 0.55; margin-left: 4px; cursor: help; }
.cu-mut { opacity: 0.55; font-size: 11px; font-weight: 400; }
.cu-bar { display: grid; grid-template-columns: 90px 1fr 64px 44px; align-items: center; gap: 6px; font-size: 12px; margin: 6px 0; }
.cu-track { height: 14px; background: rgba(128, 128, 128, 0.12); border-radius: 3px; overflow: hidden; }
.cu-fill { display: block; height: 100%; background: #118DFF; border-radius: 0 3px 3px 0; }
.cu-v, .cu-p, .cu-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.cu-p { opacity: 0.6; font-size: 11px; }
.cu-t { width: 100%; border-collapse: collapse; font-size: 12px; }
.cu-t th { font-weight: 500; opacity: 0.6; font-size: 11px; text-align: right; padding: 1px 4px; }
.cu-t th:first-child { text-align: left; }
.cu-t td { padding: 2px 4px; border-top: 1px solid rgba(128, 128, 128, 0.12); }
.cu-name { max-width: 0; width: 58%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cu-tag { font-size: 10px; padding: 0 4px; border-radius: 3px; }
.cu-tag.ruc { background: rgba(58, 79, 196, 0.14); color: #3A4FC4; }
.cu-tag.dni { background: rgba(128, 128, 128, 0.16); }
.cu-new { font-size: 10px; margin-left: 6px; padding: 0 4px; border-radius: 3px; background: rgba(230, 108, 55, 0.15); color: #C2410C; }
.cu-note { font-size: 11.5px; opacity: 0.65; margin-top: 6px; }
@media (max-width: 900px) { .cu-grid { grid-template-columns: 1fr; } .cu-box { max-height: none; } }
</style>
