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
                  <th class="num">{{ incomeLabel }}</th>
                  <th class="num">{{ ticketsLabel }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in data.agencies" :key="a.name || 'other'">
                  <td>{{ a.name || tf('Owner_Other_Locations', 'Other locations') }}</td>
                  <td class="num">{{ money(a.today) }}</td>
                  <td class="num">{{ money(a.month) }}</td>
                  <td class="num">{{ a.tickets_month.toLocaleString() }}</td>
                </tr>
                <tr v-if="!data.agencies.length"><td colspan="4" class="op-muted">{{ filtered ? L('No sales in the period', 'Sin ventas en el período') : tf('Owner_No_Sales', 'No sales this month') }}</td></tr>
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
            <div v-if="!data.routes.length" class="op-muted">{{ filtered ? L('No sales in the period', 'Sin ventas en el período') : tf('Owner_No_Sales', 'No sales this month') }}</div>
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
          {{ chartTitle }}
          <a-tooltip :title="L('Each month: income (tickets and parcels) and costs (crew, payroll, fuel, maintenance, depreciation, tolls, agencies, meals) of the trips run; the line is the profit. Same figures as Profit by route. The current month counts up to today.', 'Cada mes: ingresos (pasajes y encomiendas) y gastos (tripulación, planilla, combustible, mantenimiento, depreciación, peajes, agencias, refrigerios) de los viajes realizados; la línea es la ganancia. Mismas cifras que Rentabilidad por ruta. El mes en curso cuenta hasta hoy.')">
            <InfoCircleOutlined class="op-info" />
          </a-tooltip>
          <span class="op-hint">{{ daily ? L('click a day to see why', 'clic en un día para ver el porqué') : L('click a month to see why', 'clic en un mes para ver el porqué') }}</span>
        </div>
        <div class="op-chart">
          <apexchart type="line" :height="trendHeight" :options="trendOptions" :series="trendSeries" />
        </div>
      </div>
    </a-card>

    <!-- Why a month reaches its amount (user's request, 2026-11-12): a click or double click on a bar or the line. -->
    <a-modal v-model:open="whyOpen" :title="whyTitle" :footer="null" width="760px" class="op-why">
      <template v-if="why">
        <p class="why-story">{{ story }}</p>
        <div class="why-cols">
          <div class="why-box" :class="{ on: whyFocus === 0 }">
            <div class="why-head"><span>{{ L('Income', 'Ingresos') }}</span><b>{{ money(why.income) }}</b></div>
            <div v-for="r in incomeRows" :key="r.key" class="why-row">
              <span class="why-name">{{ r.label }}</span>
              <span class="why-bar"><span :style="{ width: r.pct + '%', background: '#2563eb' }" /></span>
              <span class="why-val">{{ money(r.value) }}</span>
              <span class="why-delta" :class="deltaClass(r.delta, false)">{{ deltaText(r.delta) }}</span>
            </div>
            <div class="why-facts">
              <span>{{ why.trips }} {{ L('trips', 'viajes') }}</span>
              <span>{{ (why.detail?.sold ?? 0).toLocaleString() }} {{ L('passengers', 'pasajeros') }}</span>
              <span v-if="why.detail?.occupancy != null">{{ L('occupancy', 'ocupación') }} {{ why.detail.occupancy }} %</span>
              <span v-if="avgFare">{{ L('avg. ticket', 'pasaje prom.') }} {{ money(avgFare) }}</span>
            </div>
          </div>
          <div class="why-box" :class="{ on: whyFocus === 1 }">
            <div class="why-head"><span>{{ L('Costs', 'Gastos') }}</span><b>{{ money(why.costs) }}</b></div>
            <div v-for="r in costRows" :key="r.key" class="why-row">
              <span class="why-name">{{ r.label }}</span>
              <span class="why-bar"><span :style="{ width: r.pct + '%', background: '#94a3b8' }" /></span>
              <span class="why-val">{{ money(r.value) }}</span>
              <span class="why-delta" :class="deltaClass(r.delta, true)">{{ deltaText(r.delta) }}</span>
            </div>
          </div>
        </div>
        <div class="why-profit" :class="{ on: whyFocus === 2, neg: why.profit < 0 }">
          <span>{{ L('Profit', 'Ganancia') }} = {{ money(why.income) }} − {{ money(why.costs) }}</span>
          <b>{{ money(why.profit) }}</b>
          <span v-if="why.income" class="why-muted">{{ L('margin', 'margen') }} {{ Math.round(why.profit / why.income * 100) }} %</span>
          <span class="why-muted">{{ why.detail?.gain_trips ?? 0 }} {{ L('trips with profit', 'viajes con ganancia') }} · {{ why.detail?.loss_trips ?? 0 }} {{ L('with loss', 'con pérdida') }}</span>
        </div>
        <div v-if="routeRows.length" class="why-routes">
          <div class="why-sub">{{ L('Profit by route', 'Ganancia por ruta') }}</div>
          <table class="op-table">
            <thead><tr>
              <th>{{ L('Route', 'Ruta') }}</th><th class="num">{{ L('Trips', 'Viajes') }}</th><th class="num">{{ L('Passengers', 'Pasajeros') }}</th>
              <th class="num">{{ L('Income', 'Ingresos') }}</th><th class="num">{{ L('Costs', 'Gastos') }}</th><th class="num">{{ L('Profit', 'Ganancia') }}</th>
            </tr></thead>
            <tbody>
              <tr v-for="r in routeRows" :key="r.route">
                <td>{{ r.route }}</td><td class="num">{{ r.trips }}</td><td class="num">{{ r.sold.toLocaleString() }}</td>
                <td class="num">{{ money(r.income) }}</td><td class="num">{{ money(r.costs) }}</td>
                <td class="num" :class="r.profit < 0 ? 'why-neg' : 'why-pos'">{{ money(r.profit) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="why-note">{{ whyNote }}</p>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import http from '../lib/http';
import { t as tf } from '../i18n';
import { useFormat } from '../composables/useFormat';
import { useFitHeight } from '../lib/transportCharts';
import TransportStrip from './TransportStrip.vue';
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import { useUiStore } from '../stores/ui';

const { money, date: fmtDate, dayMonth } = useFormat();
const loading = ref(true);
const root = ref(null);
const { fit, measure } = useFitHeight(root);
// The 12-month chart takes its part of the screen; the boxes above keep the rest (one screen, 2026-11-05).
const trendHeight = 230;
const gridHeight = computed(() => (fit.value ? fit.value - 16 - 58 - (data.value.months?.length ? trendHeight + 40 : 0) : null));
const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const MONTHS = { en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], es: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Set', 'Oct', 'Nov', 'Dic'] };
// A bucket is a month (YYYY-MM) or, with a filter of up to 62 days, a day (YYYY-MM-DD).
const monthLabel = m => { if (m.length > 7) return dayMonth(m); const d = dayjs(m + '-01'); return `${MONTHS[L('en', 'es')][d.month()]} ${d.format('YY')}`; };
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
  colors: ['#2563eb', '#94a3b8', '#16a34a'],
  stroke: { width: [0, 0, 3], curve: 'smooth' },
  markers: { size: [0, 0, 4], colors: ['#16a34a'], strokeWidth: 0 },
  // A filter of one or two days gives one or two groups: narrow bars instead of a wall.
  plotOptions: { bar: { borderRadius: 3, columnWidth: (data.value.months || []).length <= 3 ? '18%' : '60%' } },
  dataLabels: { enabled: false },
  xaxis: { categories: (data.value.months || []).map(m => monthLabel(m.month)), labels: { style: { fontSize: '11px' } } },
  yaxis: { title: { text: 'S/', style: { fontWeight: 400 } }, labels: { formatter: v => short(v) }, forceNiceScale: true },
  annotations: { yaxis: [{ y: 0, borderColor: '#64748b', strokeDashArray: 0 }] },
  tooltip: { shared: true, intersect: false, y: { formatter: v => money(v) } },
  legend: { position: 'bottom', fontSize: '12px' },
  grid: { borderColor: 'rgba(128,128,128,0.15)' },
  chart: {
    toolbar: { show: false }, fontFamily: 'inherit',
    // A click (or a double click: both land here, the second one waits for the first) opens the month's explanation.
    events: { click: (e, ctx, cfg) => pickMonth(cfg?.dataPointIndex, cfg?.seriesIndex) },
  },
}));

// ---- Why a month reaches its amount (2026-11-12) ----
const whyOpen = ref(false);
const whyIdx = ref(-1);
const whyFocus = ref(-1); // 0 income, 1 costs, 2 profit: the series clicked, when the chart tells
let pickTimer = null;
function pickMonth(idx, series) {
  if (idx == null || idx < 0) return;
  clearTimeout(pickTimer);
  pickTimer = setTimeout(() => {
    whyIdx.value = idx;
    whyFocus.value = series ?? -1;
    whyOpen.value = true;
  }, 250);
}
const MONTH_NAMES = {
  en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
  es: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'],
};
const DAY_NAMES = { en: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], es: ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'] };
const monthName = m => { if (m.length > 7) return ` ${fmtDate(m)}`; const d = dayjs(m + '-01'); return `${MONTH_NAMES[L('en', 'es')][d.month()]} ${d.year()}`; };
const why = computed(() => (data.value.months || [])[whyIdx.value] || null);
const prev = computed(() => (data.value.months || [])[whyIdx.value - 1] || null);
const isCurrent = computed(() => why.value?.month === dayjs().format('YYYY-MM') || !!why.value?.detail?.partial);
const unit = computed(() => (daily.value ? L('day', 'día') : L('month', 'mes')));
const whyTitle = computed(() => (why.value ? `${monthName(why.value.month)} · ${L('why this amount', 'por qué este monto')}` : ''));
const INCOME_LABELS = { tickets: ['Tickets', 'Pasajes'], parcels: ['Parcels', 'Encomiendas'] };
const COST_LABELS = {
  staff: ['Crew pay', 'Pago a tripulación'], payroll: ['Payroll', 'Planilla'], fuel: ['Fuel', 'Combustible'], maintenance: ['Maintenance', 'Mantenimiento'],
  depreciation: ['Depreciation', 'Depreciación'], tolls: ['Tolls', 'Peajes'], agency: ['Agencies', 'Agencias'], meals: ['Meals (deluxe)', 'Refrigerios (VIP)'],
};
const rowsOf = (part, labels) => {
  const cur = why.value?.detail?.[part] || {};
  const old = prev.value?.detail?.[part] || null;
  const max = Math.max(1, ...Object.values(cur));
  return Object.keys(labels).filter(k => (cur[k] ?? 0) || (old?.[k] ?? 0)).map(k => ({
    key: k, label: L(...labels[k]), value: cur[k] ?? 0, pct: Math.round(((cur[k] ?? 0) / max) * 100),
    delta: old ? (cur[k] ?? 0) - (old[k] ?? 0) : null,
  })).sort((a, b) => b.value - a.value);
};
const incomeRows = computed(() => rowsOf('income', INCOME_LABELS));
const costRows = computed(() => rowsOf('costs', COST_LABELS));
const routeRows = computed(() => why.value?.detail?.routes || []);
const avgFare = computed(() => { const d = why.value?.detail; return d?.sold ? (d.income?.tickets ?? 0) / d.sold : 0; });
const deltaText = d => (d == null || Math.round(d) === 0 ? '' : `${d > 0 ? '▲' : '▼'} ${short(Math.abs(d))}`);
// Up is good for income, bad for costs.
const deltaClass = (d, cost) => (d == null || Math.round(d) === 0 ? '' : (d > 0) !== cost ? 'why-pos' : 'why-neg');
// One sentence that tells the story: the profit vs the month before and what moved it most.
const story = computed(() => {
  const w = why.value;
  if (!w) return '';
  if (!w.trips) return (daily.value ? L('No trips were run this day.', 'Este día no se realizaron viajes.') : L('No trips were run this month.', 'Este mes no se realizaron viajes.'));
  const base = L(`${w.trips} trips carried ${(w.detail?.sold ?? 0).toLocaleString()} passengers: they brought ${money(w.income)} and cost ${money(w.costs)}, leaving ${money(w.profit)}.`,
    `${w.trips} viajes llevaron ${(w.detail?.sold ?? 0).toLocaleString()} pasajeros: ingresaron ${money(w.income)} y costaron ${money(w.costs)}; quedan ${money(w.profit)}.`);
  const p = prev.value;
  if (!p || !p.trips) return base;
  const dp = w.profit - p.profit;
  const moves = [...incomeRows.value.map(r => ({ ...r, eff: r.delta ?? 0 })), ...costRows.value.map(r => ({ ...r, eff: -(r.delta ?? 0) }))]
    .filter(r => Math.round(r.eff) !== 0).sort((a, b) => Math.abs(b.eff) - Math.abs(a.eff)).slice(0, 2);
  const why2 = moves.map(r => `${r.label.toLowerCase()} ${r.delta > 0 ? '+' : '−'}${money(Math.abs(r.delta))}`).join(L(' and ', ' y '));
  const prevName = monthName(p.month);
  const verb = dp >= 0 ? L('more', 'más') : L('less', 'menos');
  return `${base} ${L(`That is ${money(Math.abs(dp))} ${verb} than ${prevName}`, `Son ${money(Math.abs(dp))} ${verb} que en ${prevName}`)}${why2 ? `${L(', mainly by ', ', sobre todo por ')}${why2}` : ''}.`;
});
const whyNote = computed(() => (isCurrent.value
  ? L(`Part of the month: ${why.value?.detail?.days ?? ''} days (up to today or the filter), compared with the month before. Same figures as Profit by route; ▲▼ = change vs the month before.`,
    `Mes parcial: ${why.value?.detail?.days ?? ''} días (hasta hoy o hasta el filtro), comparado con el mes anterior. Mismas cifras que Rentabilidad por ruta; ▲▼ = cambio frente al mes anterior.`)
  : L(`Same figures as Profit by route (trips run). ▲▼ = change vs the ${daily.value ? 'day' : 'month'} before.`, `Mismas cifras que Rentabilidad por ruta (viajes realizados). ▲▼ = cambio frente al ${daily.value ? 'día' : 'mes'} anterior.`)));
const data = ref({ enabled: false, agencies: [], routes: [], alerts: [], alerts_total: 0 });

// The dashboard's date filter (2026-11-12); null = All: the month in progress and the last 12 months.
const props = defineProps({ range: { type: Array, default: null } });
async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/owner', props.range ? { from: props.range[0], to: props.range[1] } : {});
    data.value = { ...data.value, ...res };
  } catch (e) {
    data.value = { ...data.value, enabled: false }; // no access: the dashboard simply goes without it
  } finally {
    loading.value = false;
    nextTick(measure);
  }
}
onMounted(load);
watch(() => props.range, load);
const filtered = computed(() => !!data.value.period?.filtered);
const daily = computed(() => data.value.granularity === 'day');
const periodLabel = computed(() => {
  const p = data.value.period;
  return p ? `${fmtDate(p.from)} – ${fmtDate(p.to)}` : '';
});
const incomeLabel = computed(() => (filtered.value ? L('Income in the period', 'Ingresos del período') : tf('Owner_Income_Month', 'Income this month')));
const ticketsLabel = computed(() => (filtered.value ? L('Tickets in the period', 'Pasajes del período') : tf('Owner_Tickets_Month', 'Tickets this month')));
const chartTitle = computed(() => (!filtered.value ? L('Last 12 months', 'Últimos 12 meses')
  : `${periodLabel.value} · ${daily.value ? L('by day', 'por día') : L('by month', 'por mes')}`));

const cards = computed(() => {
  const d = data.value;
  const trips = d.trips || {};
  const period = filtered.value ? periodLabel.value : tf('Owner_Panel_Period', 'Today and the month in progress');
  return [
    { label: tf('Owner_Income_Today', 'Income today'), value: money(d.income?.today ?? 0), note: `${(d.tickets?.today ?? 0).toLocaleString()} ${tf('Owner_Tickets', 'tickets')}`, help: period },
    { label: incomeLabel.value, value: money(d.income?.month ?? 0), note: `${(d.tickets?.month ?? 0).toLocaleString()} ${tf('Owner_Tickets', 'tickets')}` },
    { label: tf('Owner_Trips_Today', 'Trips today'), value: trips.total ?? 0, help: `${trips.completed ?? 0} ${tf('Owner_Done', 'done')} · ${trips.in_progress ?? 0} ${tf('Owner_On_Road', 'on the road')} · ${trips.scheduled ?? 0} ${tf('Owner_To_Leave', 'to leave')}` },
    { label: filtered.value ? L('Fleet costs in the period', 'Costos de flota del período') : tf('Owner_Fleet_Costs', 'Fleet costs this month'), value: money(d.costs?.total ?? 0), help: `${tf('Owner_Fuel', 'Fuel')} ${money(d.costs?.fuel ?? 0)} · ${tf('Owner_Maintenance', 'Maintenance')} ${money(d.costs?.maintenance ?? 0)}` },
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
.op-chart { cursor: pointer; }
.op-hint { font-weight: 400; font-size: 11px; opacity: 0.6; margin-left: 8px; }
.why-story { font-size: 13.5px; margin: 0 0 10px; }
.why-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.why-box, .why-profit { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 8px 10px; }
.why-box.on, .why-profit.on { border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb inset; }
.why-head { display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 4px; }
.why-row { display: grid; grid-template-columns: 1fr 70px auto 64px; gap: 6px; align-items: center; font-size: 12px; padding: 2px 0; }
.why-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.why-bar { height: 6px; border-radius: 3px; background: rgba(128, 128, 128, 0.15); overflow: hidden; }
.why-bar span { display: block; height: 100%; border-radius: 3px; }
.why-val { text-align: right; font-variant-numeric: tabular-nums; }
.why-delta { text-align: right; font-size: 11px; white-space: nowrap; }
.why-pos { color: #16a34a; }
.why-neg { color: #dc2626; }
.why-facts { display: flex; flex-wrap: wrap; gap: 4px 10px; font-size: 11.5px; opacity: 0.75; margin-top: 6px; }
.why-profit { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: baseline; }
.why-profit b { font-size: 16px; color: #16a34a; }
.why-profit.neg b { color: #dc2626; }
.why-muted { font-size: 12px; opacity: 0.7; }
.why-routes { margin-top: 10px; max-height: 220px; overflow: auto; }
.why-sub { font-weight: 600; font-size: 13px; margin-bottom: 2px; }
.why-note { font-size: 11px; opacity: 0.6; margin: 8px 0 0; }
@media (max-width: 700px) { .why-cols { grid-template-columns: 1fr; } }
.op-info { opacity: 0.55; margin-left: 4px; font-size: 12px; cursor: help; }
@media (min-width: 900px) {
  .op-grid { grid-template-columns: minmax(0, 1.25fr) minmax(0, 0.85fr) minmax(0, 1.1fr); }
}
</style>
