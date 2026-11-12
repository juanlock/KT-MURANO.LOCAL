<template>
  <!-- The owner's figures, one screen with no scroll (2026-11-05). No date filter: always today and the month in progress. -->
  <div ref="root">
    <a-card v-if="data.enabled" class="op" :bordered="false" :loading="loading" :body-style="{ padding: '8px' }">
      <TransportStrip :items="cards" :toggle="false" />
      <div class="op-grid" :style="gridHeight ? { maxHeight: gridHeight + 'px' } : null">
        <div class="op-box">
          <div class="op-sub-title">{{ tf('Owner_By_Agency', 'By agency') }}</div>
          <div class="op-list">
            <table class="op-table">
              <thead>
                <tr>
                  <th>{{ tf('Transport_Agency', 'Agency') }}</th>
                  <th class="num">{{ tf('Owner_Income_Today', 'Income today') }}</th>
                  <th class="num">{{ tf('Owner_Income_Month', 'Income this month') }}</th>
                  <th class="num">{{ tf('Owner_Tickets_Month', 'Tickets this month') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in data.agencies" :key="a.name || 'other'">
                  <td>{{ a.name || tf('Owner_Other_Locations', 'Other locations') }}</td>
                  <td class="num">{{ money(a.today) }}</td>
                  <td class="num">{{ money(a.month) }}</td>
                  <td class="num">{{ a.tickets_month.toLocaleString() }}</td>
                </tr>
                <tr v-if="!data.agencies.length"><td colspan="4" class="op-muted">{{ tf('Owner_No_Sales', 'No sales this month') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="op-box">
          <div class="op-sub-title">{{ tf('Owner_Top_Routes', 'Best-selling routes') }}</div>
          <div class="op-list">
            <div v-for="r in data.routes" :key="r.route" class="op-route" :title="money(r.income)">
              <div class="op-route-row">
                <span class="op-route-name">{{ r.route }}</span>
                <b>{{ r.tickets.toLocaleString() }}</b>
              </div>
              <div class="op-bar"><span :style="{ width: `${Math.round((r.tickets / topRoute) * 100)}%` }" /></div>
            </div>
            <div v-if="!data.routes.length" class="op-muted">{{ tf('Owner_No_Sales', 'No sales this month') }}</div>
          </div>
        </div>

        <div class="op-box">
          <div class="op-sub-title">
            {{ tf('Owner_Fleet_Alerts', 'Falling due on the buses') }}
            <a-tag v-if="data.alerts_total" :color="overdue ? 'error' : 'warning'" class="op-tag">{{ data.alerts_total }}</a-tag>
          </div>
          <div class="op-list">
            <PermLink v-for="(a, i) in data.alerts" :key="`${a.kind}-${a.vehicle_id}-${i}`" :to="`/fleet/vehicles/${a.vehicle_id}`" permission="fleet_vehicles_view" class="op-alert">
              <span class="op-dot" :class="a.days < 0 ? 'late' : 'soon'" />
              <span class="op-alert-body" :title="`${a.kind === 'plan' ? a.label : alertLabel(a.kind)} · ${alertWhen(a.days)}`">
                <span class="op-alert-name">{{ a.vehicle_name }}</span>
                <span class="op-alert-what">{{ a.kind === 'plan' ? a.label : alertLabel(a.kind) }}</span>
                <span class="op-alert-when">{{ alertWhen(a.days) }}</span>
              </span>
            </PermLink>
            <div v-if="!data.alerts.length" class="op-muted">{{ tf('Owner_No_Alerts', 'Nothing due in the next 30 days') }}</div>
          </div>
        </div>
      </div>
      <!-- How the business goes (user's request, 2026-11-11): the last 12 months, the current one so far. -->
      <div v-if="data.months?.length" class="op-box op-trend">
        <div class="op-sub-title">
          {{ L('Last 12 months', 'Últimos 12 meses') }}
          <a-tooltip :title="L('Each month: income (tickets and parcels) and costs (crew, payroll, fuel, maintenance, depreciation, tolls, agencies, meals) of the trips run; the line is the profit. Same figures as Profit by route. The current month counts up to today.', 'Cada mes: ingresos (pasajes y encomiendas) y gastos (tripulación, planilla, combustible, mantenimiento, depreciación, peajes, agencias, refrigerios) de los viajes realizados; la línea es la ganancia. Mismas cifras que Rentabilidad por ruta. El mes en curso cuenta hasta hoy.')">
            <InfoCircleOutlined class="op-info" />
          </a-tooltip>
        </div>
        <apexchart type="line" :height="trendHeight" :options="trendOptions" :series="trendSeries" />
      </div>
    </a-card>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import http from '../lib/http';
import { t as tf } from '../i18n';
import { useFormat } from '../composables/useFormat';
import { useFitHeight } from '../lib/transportCharts';
import TransportStrip from './TransportStrip.vue';
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import { useUiStore } from '../stores/ui';

const { money } = useFormat();
const loading = ref(true);
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// The 12-month chart takes its part of the screen; the boxes above keep the rest (one screen, 2026-11-05).
const trendHeight = 230;
const gridHeight = computed(() => (fit.value ? fit.value - 16 - 58 - (data.value.months?.length ? trendHeight + 40 : 0) : null));
const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const MONTHS = { en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], es: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Set', 'Oct', 'Nov', 'Dic'] };
const monthLabel = m => { const d = dayjs(m + '-01'); return `${MONTHS[L('en', 'es')][d.month()]} ${d.format('YY')}`; };
// Income and costs per month as bars, the profit as the line (user's request, 2026-11-11).
const trendSeries = computed(() => {
  const ms = data.value.months || [];
  return [
    { name: L('Income', 'Ingresos'), type: 'column', data: ms.map(m => m.income) },
    { name: L('Costs', 'Gastos'), type: 'column', data: ms.map(m => m.costs) },
    { name: L('Profit', 'Ganancia'), type: 'line', data: ms.map(m => m.profit) },
  ];
});const short = v => (Math.abs(v) >= 1e6 ? `${(v / 1e6).toFixed(1)}M` : Math.abs(v) >= 1e3 ? `${Math.round(v / 1e3)}K` : `${Math.round(v)}`);
const trendOptions = computed(() => ({
  chart: { toolbar: { show: false }, fontFamily: 'inherit' },
  colors: ['#2563eb', '#94a3b8', '#16a34a'],
  stroke: { width: [0, 0, 3], curve: 'smooth' },
  markers: { size: [0, 0, 4], colors: ['#16a34a'], strokeWidth: 0 },
  plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
  dataLabels: { enabled: false },
  xaxis: { categories: (data.value.months || []).map(m => monthLabel(m.month)), labels: { style: { fontSize: '11px' } } },
  yaxis: { title: { text: 'S/', style: { fontWeight: 400 } }, labels: { formatter: v => short(v) }, forceNiceScale: true },
  annotations: { yaxis: [{ y: 0, borderColor: '#64748b', strokeDashArray: 0 }] },
  tooltip: { shared: true, intersect: false, y: { formatter: v => money(v) } },
  legend: { position: 'bottom', fontSize: '12px' },
  grid: { borderColor: 'rgba(128,128,128,0.15)' },
}));
const data = ref({ enabled: false, agencies: [], routes: [], alerts: [], alerts_total: 0 });

onMounted(async () => {
  try {
    const res = await http.get('transport/owner');
    data.value = { ...data.value, ...res };
  } catch (e) {
    data.value = { ...data.value, enabled: false }; // no access: the dashboard simply goes without it
  } finally {
    loading.value = false;
    nextTick(measure);
  }
});

const cards = computed(() => {
  const d = data.value;
  const trips = d.trips || {};
  const period = tf('Owner_Panel_Period', 'Today and the month in progress');
  return [
    { label: tf('Owner_Income_Today', 'Income today'), value: money(d.income?.today ?? 0), note: `${(d.tickets?.today ?? 0).toLocaleString()} ${tf('Owner_Tickets', 'tickets')}`, help: period },
    { label: tf('Owner_Income_Month', 'Income this month'), value: money(d.income?.month ?? 0), note: `${(d.tickets?.month ?? 0).toLocaleString()} ${tf('Owner_Tickets', 'tickets')}` },
    { label: tf('Owner_Trips_Today', 'Trips today'), value: trips.total ?? 0, help: `${trips.completed ?? 0} ${tf('Owner_Done', 'done')} · ${trips.in_progress ?? 0} ${tf('Owner_On_Road', 'on the road')} · ${trips.scheduled ?? 0} ${tf('Owner_To_Leave', 'to leave')}` },
    { label: tf('Owner_Fleet_Costs', 'Fleet costs this month'), value: money(d.costs?.total ?? 0), help: `${tf('Owner_Fuel', 'Fuel')} ${money(d.costs?.fuel ?? 0)} · ${tf('Owner_Maintenance', 'Maintenance')} ${money(d.costs?.maintenance ?? 0)}` },
    { label: tf('Owner_Fleet_Alerts', 'Falling due on the buses'), value: d.alerts_total ?? 0, help: tf('Owner_Next_30', 'overdue or within 30 days') },
  ];
});
const topRoute = computed(() => Math.max(1, ...data.value.routes.map(r => r.tickets)));
const overdue = computed(() => data.value.alerts.some(a => a.days < 0));

const alertLabel = kind => ({
  insurance_expiry: tf('Owner_Alert_Insurance', 'Insurance'),
  registration_expiry: tf('Owner_Alert_Registration', 'Registration'),
  inspection_expiry: tf('Owner_Alert_Inspection', 'Inspection'),
  service: tf('Owner_Alert_Service', 'Next service'),
})[kind] || kind;
const alertWhen = days => (days < 0
  ? `${tf('Owner_Overdue', 'overdue')} ${Math.abs(days)} d`
  : `${tf('Owner_In', 'in')} ${days} d`);
</script>

<style scoped>
.op { margin-bottom: 0; }
.op-grid { display: grid; gap: 8px; grid-template-columns: 1fr; align-items: start; }
.op-box { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 8px; display: flex; flex-direction: column; min-height: 0; min-width: 0; max-height: 100%; }
.op-list { flex: 1; min-height: 0; overflow: auto; }
.op-sub-title { font-weight: 600; font-size: 13px; line-height: 20px; margin-bottom: 2px; }
.op-tag { margin-left: 4px; line-height: 16px; }
.op-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.op-table th { text-align: left; font-size: 11px; font-weight: 400; opacity: 0.7; padding: 2px 6px; white-space: nowrap; }
.op-table td { padding: 3px 6px; border-top: 1px solid rgba(128, 128, 128, 0.15); white-space: nowrap; }
.op-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.op-muted { font-size: 12px; opacity: 0.68; }
.op-route { margin-bottom: 6px; }
.op-route-row { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; }
.op-route-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.op-bar { height: 4px; border-radius: 2px; background: rgba(128, 128, 128, 0.2); margin-top: 2px; overflow: hidden; }
.op-bar span { display: block; height: 100%; background: #0369a1; border-radius: 2px; }
.op-alert { display: flex; gap: 6px; align-items: center; padding: 3px 0; border-top: 1px solid rgba(128, 128, 128, 0.15); font-size: 11.5px; }
.op-alert-body { display: flex; gap: 6px; min-width: 0; flex: 1; align-items: baseline; }
.op-alert-name { font-weight: 600; white-space: nowrap; }
.op-alert-what { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: 0.8; }
.op-alert-when { white-space: nowrap; opacity: 0.7; }
.op-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.op-dot.late { background: #dc2626; }
.op-dot.soon { background: #f59e0b; }
.op-trend { margin-top: 8px; }
.op-info { opacity: 0.55; margin-left: 4px; font-size: 12px; cursor: help; }
@media (min-width: 900px) {
  .op-grid { grid-template-columns: minmax(0, 1.25fr) minmax(0, 0.85fr) minmax(0, 1.1fr); }
}
</style>
