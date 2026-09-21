<template>
  <div class="page">
    <PageHeader :title="$t('Real_time_Sales_Counter')" :breadcrumb="[$t('Sales'), $t('Real_time_Sales_Counter')]">
      <template #extra>
        <a-space wrap>
          <a-tag :color="hasError ? 'error' : (paused ? 'default' : 'success')">
            <span class="live-dot" :class="{ paused: paused || hasError }"></span>
            {{ hasError ? $t('Failed') : (paused ? $t('Pause') : 'LIVE') }}
          </a-tag>
          <span style="font-variant-numeric: tabular-nums">{{ serverClock }}</span>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Controls -->
      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end">
          <div style="min-width: 220px">
            <div class="filter-label">{{ $t('warehouse') }}</div>
            <a-select
              v-model:value="warehouseId" style="width: 100%" allow-clear show-search
              option-filter-prop="label" :placeholder="$t('All_Warehouses')"
              :options="warehouseOptions" @change="fetchCounterData(true)"
            />
          </div>
          <div style="min-width: 160px">
            <div class="filter-label">{{ $t('Updates_every') }}</div>
            <a-select
              v-model:value="refreshSeconds" style="width: 100%"
              :options="[10, 30, 60, 120].map(s => ({ value: s, label: s + ' ' + $t('Seconds') }))"
              @change="restartTimer"
            />
          </div>
          <a-space>
            <a-button @click="togglePause">
              <template #icon><PauseOutlined v-if="!paused" /><CaretRightOutlined v-else /></template>
            </a-button>
            <a-button type="primary" :loading="isFetching" @click="fetchCounterData(true)">
              <template #icon><ReloadOutlined /></template>
            </a-button>
            <a-button :type="soundEnabled ? 'primary' : 'default'" @click="toggleSound">
              <template #icon><SoundOutlined /></template>
            </a-button>
          </a-space>
          <span style="margin-left: auto; font-size: 12px; color: #8c8c8c">
            {{ $t('Updated') || 'Updated' }}: {{ lastUpdatedRelative }}
          </span>
        </div>
      </a-card>

      <!-- KPI cards -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="12" :lg="6">
          <a-card size="small" :class="{ bump: bumpCount }">
            <a-statistic :title="$t('Sales_today')" :value="todayCount" />
          </a-card>
        </a-col>
        <a-col :xs="12" :lg="6">
          <a-card size="small" :class="{ bump: bumpTotal }">
            <a-statistic :title="$t('Total_Today')" :value="money(todayTotal)" />
            <div style="font-size: 12px; margin-top: 4px" :style="{ color: trendPct > 0 ? '#52c41a' : trendPct < 0 ? '#ff4d4f' : '#8c8c8c' }">
              <RiseOutlined v-if="trendPct > 0" /><FallOutlined v-else-if="trendPct < 0" />
              {{ trendLabel }} <span style="color: #8c8c8c">{{ $t('vs') }} {{ $t('yesterday') }}</span>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="12" :lg="6">
          <a-card size="small">
            <a-statistic :title="$t('Average_Sale')" :value="money(averageSale)" />
            <div style="font-size: 12px; color: #8c8c8c; margin-top: 4px">{{ money(todayPaid) }} {{ $t('paid') }}</div>
          </a-card>
        </a-col>
        <a-col :xs="12" :lg="6">
          <a-card size="small">
            <a-statistic :title="$t('Last_Sale')" :value="lastSaleRelative" />
            <div v-if="lastSaleAbsolute" style="font-size: 12px; color: #8c8c8c; margin-top: 4px">{{ lastSaleAbsolute }}</div>
          </a-card>
        </a-col>
      </a-row>

      <!-- Payment status bar -->
      <a-card size="small" style="margin-bottom: 16px">
        <a-space size="large" wrap>
          <span><a-badge status="success" /> {{ $t('paid') }} <strong>{{ paymentStatus.paid }}</strong></span>
          <span><a-badge status="warning" /> {{ $t('partial') }} <strong>{{ paymentStatus.partial }}</strong></span>
          <span><a-badge status="error" /> {{ $t('unpaid') }} <strong>{{ paymentStatus.unpaid }}</strong></span>
          <span style="color: #8c8c8c">{{ $t('Sales_Due') }}: {{ money(todayDue) }}</span>
        </a-space>
      </a-card>

      <a-row :gutter="[16, 16]">
        <!-- Hourly chart -->
        <a-col :xs="24" :lg="14">
          <a-card size="small" :title="$t('Hourly_Sales_Today')" style="height: 100%">
            <apexchart type="bar" :height="300" :options="hourlyOptions" :series="hourlySeries" />
          </a-card>
        </a-col>

        <!-- Top products -->
        <a-col :xs="24" :lg="10">
          <a-card size="small" :title="$t('Top_Products_Today')" style="height: 100%">
            <a-empty v-if="!topProducts.length" :description="$t('No_sales_today')" />
            <div v-else>
              <div v-for="(p, idx) in topProducts" :key="p.product_id || idx" style="margin-bottom: 12px">
                <div style="display: flex; justify-content: space-between; font-size: 13px">
                  <span style="font-weight: 500">{{ idx + 1 }}. {{ p.product_name }}</span>
                  <span style="color: #8c8c8c">{{ p.quantity }} {{ $t('units') || 'units' }} · {{ money(p.total) }}</span>
                </div>
                <a-progress :percent="topBarWidth(p)" :show-info="false" size="small" />
              </div>
            </div>
          </a-card>
        </a-col>

        <!-- Recent sales -->
        <a-col :xs="24" :lg="14">
          <a-card size="small" :title="$t('Recent_Sales')">
            <a-table
              :columns="recentColumns" :data-source="recentSales"
              size="small" :pagination="false" row-key="id"
              :row-class-name="r => (newSaleIds.has(r.id) ? 'row-new' : '')"
              :locale="{ emptyText: $t('No_sales_today') }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'client_name'">{{ record.client_name || '-' }}</template>
                <template v-else-if="column.key === 'grand_total'">{{ money(record.grand_total) }}</template>
                <template v-else-if="column.key === 'payment_status'">
                  <a-tag :color="payStatusColor(record.payment_status)">{{ record.payment_status }}</a-tag>
                </template>
                <template v-else-if="column.key === 'time'">{{ formatHM(record.date) }}</template>
              </template>
            </a-table>
          </a-card>
        </a-col>

        <!-- Sales by location -->
        <a-col :xs="24" :lg="10">
          <a-card size="small" :title="$t('warehouse')">
            <a-table
              :columns="locationColumns" :data-source="salesByLocation"
              size="small" :pagination="false" :row-key="(_r, i) => i"
              :locale="{ emptyText: $t('No_sales_today') }"
            >
              <template #bodyCell="{ column, record, index }">
                <template v-if="column.key === 'sn'">{{ index + 1 }}</template>
                <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
                <template v-else-if="column.key === 'last_sale'">{{ formatDateTime(record.last_sale) }}</template>
              </template>
            </a-table>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Real-time sales counter — single endpoint GET /real_time_sales_counter_data
 * (?warehouse_id=) polled on a user-selectable interval (10/30/60/120s,
 * default 30). Payload: today_count/total/paid/due, yesterday_total,
 * last_sale_at, payment_status_counts, hourly[24], recent_sales,
 * top_products, sales_by_location, warehouses, server_time (anchors the
 * ticking clock). New-sale detection diffs recent_sales ids → pulse/bump +
 * optional WebAudio beep (persisted in localStorage rts_sound_enabled).
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  PauseOutlined, CaretRightOutlined, ReloadOutlined, SoundOutlined, RiseOutlined, FallOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../components/PageHeader.vue';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';
import { payStatusColor } from '../lib/statusColors';
import http from '../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const ui = useUiStore();

const loading = ref(true);
const isFetching = ref(false);
const paused = ref(false);
const hasError = ref(false);
const soundEnabled = ref(false);

const todayCount = ref(0);
const todayTotal = ref(0);
const todayPaid = ref(0);
const todayDue = ref(0);
const yesterdayTotal = ref(0);
const lastSaleAt = ref(null);
const paymentStatus = ref({ paid: 0, partial: 0, unpaid: 0 });
const hourly = ref([]);
const recentSales = ref([]);
const topProducts = ref([]);
const salesByLocation = ref([]);
const warehouses = ref([]);
const warehouseId = ref(undefined);

const refreshSeconds = ref(30);
let refreshTimer = null;
let tickTimer = null;

const lastUpdatedAt = ref(null);
const serverTimeAt = ref(null);
const serverTimeFetchedAt = ref(0);
const now = ref(Date.now());

let knownSaleIds = new Set();
const newSaleIds = ref(new Set());
let newSaleClearTimer = null;
const bumpCount = ref(false);
const bumpTotal = ref(false);
let audioCtx = null;

const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const averageSale = computed(() => (todayCount.value ? todayTotal.value / todayCount.value : 0));

const serverClock = computed(() => {
  if (serverTimeAt.value && serverTimeFetchedAt.value) {
    const elapsed = Math.max(0, now.value - serverTimeFetchedAt.value);
    return new Date(serverTimeAt.value.getTime() + elapsed).toLocaleTimeString();
  }
  return new Date(now.value).toLocaleTimeString();
});

const trendPct = computed(() => {
  if (!yesterdayTotal.value) return todayTotal.value > 0 ? 100 : 0;
  return ((todayTotal.value - yesterdayTotal.value) / yesterdayTotal.value) * 100;
});
const trendLabel = computed(() => {
  const v = trendPct.value;
  if (!Number.isFinite(v)) return '—';
  const abs = Math.abs(v);
  const formatted = abs >= 100 ? abs.toFixed(0) : abs.toFixed(1);
  return `${v >= 0 ? '+' : '-'}${formatted}%`;
});

function relativeFromNow(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  if (Number.isNaN(d.getTime())) return '—';
  const secs = Math.max(0, Math.floor((now.value - d.getTime()) / 1000));
  if (secs < 60) return secs + 's';
  if (secs < 3600) return Math.floor(secs / 60) + 'm';
  if (secs < 86400) return Math.floor(secs / 3600) + 'h';
  return Math.floor(secs / 86400) + 'd';
}
const lastSaleRelative = computed(() => relativeFromNow(lastSaleAt.value));
const lastSaleAbsolute = computed(() => {
  if (!lastSaleAt.value) return '';
  const d = new Date(lastSaleAt.value);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString();
});
const lastUpdatedRelative = computed(() => relativeFromNow(lastUpdatedAt.value));

function formatHM(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function formatDateTime(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}
function topBarWidth(p) {
  const max = topProducts.value.reduce((m, x) => Math.max(m, Number(x.quantity) || 0), 0);
  if (!max) return 0;
  return Math.min(100, Math.round((Number(p.quantity) / max) * 100));
}

/* ------------------------------------------------------------------ chart */
const hourlySeries = computed(() => [
  { name: t('Sales'), data: hourly.value.map(h => Number(h.count) || 0) },
]);
const hourlyOptions = computed(() => {
  const totals = hourly.value.map(h => Number(h.total) || 0);
  const dark = ui.dark;
  const labelColor = dark ? 'rgba(216,216,216,0.7)' : '#6b7280';
  return {
    chart: { toolbar: { show: false }, animations: { enabled: true, speed: 300 }, fontFamily: 'inherit', background: 'transparent' },
    plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
    dataLabels: { enabled: false },
    colors: ['#6d28d9'],
    xaxis: {
      categories: Array.from({ length: 24 }, (_, h) => `${String(h).padStart(2, '0')}h`),
      labels: { style: { colors: labelColor, fontSize: '11px' } },
      axisBorder: { show: false }, axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: labelColor, fontSize: '11px' }, formatter: v => Math.round(v) } },
    grid: { borderColor: dark ? '#2a2a2a' : '#e5e7eb', strokeDashArray: 4 },
    tooltip: {
      theme: dark ? 'dark' : 'light',
      y: { formatter: (val, { dataPointIndex }) => `${val} (${money(totals[dataPointIndex] || 0)})` },
    },
  };
});

/* ----------------------------------------------------------------- tables */
const recentColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Customer'), key: 'client_name' },
  { title: t('Total'), key: 'grand_total', align: 'right' },
  { title: t('payment_status'), key: 'payment_status', width: 110 },
  { title: t('Time') || 'Time', key: 'time', width: 90 },
]);
const locationColumns = computed(() => [
  { title: 'S/N', key: 'sn', width: 50 },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Total_Invoice'), dataIndex: 'total_invoice', key: 'total_invoice', align: 'right' },
  { title: t('Amount'), key: 'amount', align: 'right' },
  { title: t('Last_Sale'), key: 'last_sale', width: 160, align: 'right' },
]);

/* ------------------------------------------------------------------ fetch */
async function fetchCounterData(manual = false) {
  if (isFetching.value && !manual) return;
  isFetching.value = true;
  try {
    const params = {};
    if (warehouseId.value) params.warehouse_id = warehouseId.value;
    const d = await http.get('real_time_sales_counter_data', params) || {};

    const previousCount = todayCount.value;
    const previousTotal = todayTotal.value;
    const previousIds = knownSaleIds;

    todayCount.value = Number(d.today_count) || 0;
    todayTotal.value = Number(d.today_total) || 0;
    todayPaid.value = Number(d.today_paid) || 0;
    todayDue.value = Number(d.today_due) || 0;
    yesterdayTotal.value = Number(d.yesterday_total) || 0;
    lastSaleAt.value = d.last_sale_at || null;
    paymentStatus.value = {
      paid: Number(d.payment_status_counts?.paid) || 0,
      partial: Number(d.payment_status_counts?.partial) || 0,
      unpaid: Number(d.payment_status_counts?.unpaid) || 0,
    };
    hourly.value = Array.isArray(d.hourly) ? d.hourly : [];
    recentSales.value = Array.isArray(d.recent_sales) ? d.recent_sales : [];
    topProducts.value = Array.isArray(d.top_products) ? d.top_products : [];
    salesByLocation.value = Array.isArray(d.sales_by_location) ? d.sales_by_location : [];
    warehouses.value = Array.isArray(d.warehouses) ? d.warehouses : [];

    const parsed = d.server_time ? new Date(d.server_time) : null;
    serverTimeAt.value = parsed && !Number.isNaN(parsed.getTime()) ? parsed : new Date();
    serverTimeFetchedAt.value = Date.now();
    lastUpdatedAt.value = new Date().toISOString();
    hasError.value = false;

    const currentIds = new Set(recentSales.value.map(s => s.id));
    const fresh = [];
    if (previousIds.size > 0) {
      recentSales.value.forEach(s => { if (!previousIds.has(s.id)) fresh.push(s.id); });
    }
    knownSaleIds = currentIds;

    if (fresh.length > 0 || (previousCount > 0 && todayCount.value > previousCount)) {
      triggerPulse(fresh, previousCount, previousTotal);
    }
  } catch (e) {
    hasError.value = true;
  } finally {
    loading.value = false;
    isFetching.value = false;
  }
}

function triggerPulse(freshIds, previousCount, previousTotal) {
  bumpCount.value = todayCount.value > previousCount;
  bumpTotal.value = todayTotal.value > previousTotal;
  if (freshIds.length > 0) {
    newSaleIds.value = new Set(freshIds);
    if (newSaleClearTimer) clearTimeout(newSaleClearTimer);
    newSaleClearTimer = setTimeout(() => { newSaleIds.value = new Set(); }, 6000);
  }
  setTimeout(() => { bumpCount.value = false; bumpTotal.value = false; }, 1200);
  if (soundEnabled.value) playBeep();
}

function playBeep() {
  try {
    if (!audioCtx) {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      audioCtx = new Ctx();
    }
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, audioCtx.currentTime);
    gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.18, audioCtx.currentTime + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.35);
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start();
    osc.stop(audioCtx.currentTime + 0.4);
  } catch (e) { /* audio unsupported */ }
}

/* ----------------------------------------------------------------- timers */
function startTimer() {
  stopTimer();
  refreshTimer = setInterval(() => { if (!paused.value) fetchCounterData(); }, refreshSeconds.value * 1000);
}
function stopTimer() {
  if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
}
function restartTimer() {
  if (!paused.value) startTimer();
}
function togglePause() {
  paused.value = !paused.value;
  if (paused.value) stopTimer();
  else { fetchCounterData(); startTimer(); }
}
function toggleSound() {
  soundEnabled.value = !soundEnabled.value;
  try { localStorage.setItem('rts_sound_enabled', soundEnabled.value ? '1' : '0'); } catch (e) { /* ignore */ }
  if (soundEnabled.value) playBeep();
}

onMounted(() => {
  try { soundEnabled.value = localStorage.getItem('rts_sound_enabled') === '1'; } catch (e) { /* ignore */ }
  fetchCounterData();
  startTimer();
  tickTimer = setInterval(() => { now.value = Date.now(); }, 1000);
});

onBeforeUnmount(() => {
  stopTimer();
  if (tickTimer) clearInterval(tickTimer);
  if (newSaleClearTimer) clearTimeout(newSaleClearTimer);
  try { audioCtx?.close(); } catch (e) { /* ignore */ }
});
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
.live-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #52c41a;
  margin-right: 4px;
  animation: pulse 1.5s infinite;
}
.live-dot.paused { background: #bfbfbf; animation: none; }
@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(82, 196, 26, 0.5); }
  70% { box-shadow: 0 0 0 6px rgba(82, 196, 26, 0); }
  100% { box-shadow: 0 0 0 0 rgba(82, 196, 26, 0); }
}
.bump { animation: bump 0.6s ease; }
@keyframes bump {
  0% { transform: scale(1); }
  30% { transform: scale(1.03); }
  100% { transform: scale(1); }
}
:deep(.row-new) td { background: rgba(109, 40, 217, 0.08) !important; }
</style>
