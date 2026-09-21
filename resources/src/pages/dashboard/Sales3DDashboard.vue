<template>
  <div class="page">
    <PageHeader :title="$t('sales_3d_dashboard')" :breadcrumb="[$t('Reports'), $t('sales_3d_dashboard')]">
      <template #extra>
        <a-space wrap>
          <a-select
            v-model:value="warehouseId" :placeholder="$t('Filter_by_warehouse')"
            :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
            show-search option-filter-prop="label" allow-clear
            style="width: 220px" @change="fetchData"
          />
          <a-range-picker v-model:value="dateRange" value-format="YYYY-MM-DD" @change="fetchData" />
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" :tip="$t('sales_3d_dashboard')" />
    </div>

    <template v-else>
      <!-- KPI cards -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="12" :md="6">
          <a-card size="small"><a-statistic :title="$t('Revenue')" :value="formatCurrency(kpis.revenue)" :value-style="{ color: '#8b5cf6' }" /></a-card>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-card size="small"><a-statistic :title="$t('Orders')" :value="kpis.orders" :value-style="{ color: '#3b82f6' }" /></a-card>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-card size="small"><a-statistic :title="$t('Avg_Order')" :value="formatCurrency(kpis.avg_order)" :value-style="{ color: '#06b6d4' }" /></a-card>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-card size="small"><a-statistic :title="$t('Customers')" :value="kpis.customers" :value-style="{ color: '#ec4899' }" /></a-card>
        </a-col>
      </a-row>

      <!-- Charts grid -->
      <a-row :gutter="[16, 16]">
        <a-col :span="24">
          <a-card size="small" :title="$t('Sales_by_Month_and_Warehouse')">
            <template #extra><a-tag color="purple">3D · drag to rotate</a-tag></template>
            <div ref="chartSalesMatrix" class="echart-3d echart-3d--lg"></div>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card size="small" :title="$t('Top_Products_by_Month')">
            <template #extra><a-tag color="blue">3D</a-tag></template>
            <div ref="chartTopProducts" class="echart-3d"></div>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card size="small" :title="$t('Product_Quantity_vs_Price_vs_Revenue')">
            <template #extra><a-tag color="cyan">3D · auto-rotate</a-tag></template>
            <div ref="chartScatter" class="echart-3d"></div>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card size="small" :title="$t('Payment_Methods')">
            <template #extra><a-tag color="pink">Pie</a-tag></template>
            <div ref="chartPayments" class="echart-3d echart-3d--md"></div>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card size="small" :title="$t('Sales_Heatmap_Hour_DayOfWeek')">
            <template #extra><a-tag color="cyan">3D</a-tag></template>
            <div ref="chartHeatmap" class="echart-3d echart-3d--md"></div>
          </a-card>
        </a-col>
        <a-col :span="24">
          <a-card size="small" :title="$t('Top_customers')">
            <template #extra><a-tag color="purple">Bar</a-tag></template>
            <div ref="chartClients" class="echart-3d echart-3d--md"></div>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * 3D sales dashboard — GET sales_3d_dashboard_data?warehouse_id&from&to →
 * {warehouses, kpis, sales_by_month_warehouse, top_products_by_month,
 * product_scatter, payment_methods, hour_dow_heatmap, top_clients}. echarts +
 * echarts-gl loaded lazily (dynamic import — NEW DEPS in vue3-app
 * package.json: echarts ^5.5.1, echarts-gl ^2.0.9 — run npm install). All
 * chart option builders are legacy verbatim (bar3D/scatter3D/rose pie/
 * heatmap bar3D/gradient bar). Default range: last 30 days.
 */
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { currency, decimals } = useFormat();

let echartsLib = null;
const charts = {};
let resizeHandler = null;
let fetchSeq = 0;

const loading = ref(true);
const warehouses = ref([]);
const warehouseId = ref(null);
const payload = ref(null);
const kpis = ref({ orders: 0, revenue: 0, avg_order: 0, customers: 0 });

function daysAgo(n) {
  const d = new Date();
  d.setDate(d.getDate() - n);
  const p = x => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
const dateRange = ref([daysAgo(29), daysAgo(0)]);

const chartSalesMatrix = ref(null);
const chartTopProducts = ref(null);
const chartScatter = ref(null);
const chartPayments = ref(null);
const chartHeatmap = ref(null);
const chartClients = ref(null);
const chartRefs = { chartSalesMatrix, chartTopProducts, chartScatter, chartPayments, chartHeatmap, chartClients };

const tooltipStyle = {
  backgroundColor: 'rgba(15, 23, 42, 0.92)',
  borderColor: 'rgba(139, 92, 246, 0.4)',
  borderWidth: 1,
  textStyle: { color: '#e2e8f0', fontSize: 12 },
  extraCssText: 'backdrop-filter: blur(8px); border-radius: 10px; box-shadow: 0 8px 32px rgba(15,23,42,0.35);',
};
const axis3DName = { textStyle: { color: '#1e293b', fontSize: 13, fontWeight: 700 } };
const axis3DStyle = {
  axisLine: { lineStyle: { color: '#94a3b8' } },
  axisLabel: { color: '#334155', fontSize: 12, fontWeight: 500 },
  splitLine: { lineStyle: { color: 'rgba(100, 116, 139, 0.25)' } },
};
const pieColors = [
  '#8b5cf6', '#06b6d4', '#ec4899', '#f59e0b',
  '#10b981', '#3b82f6', '#f43f5e', '#6366f1',
  '#14b8a6', '#a855f7',
];

function formatCurrency(v) {
  const n = Number(v || 0);
  const dec = decimals.value;
  return `${currency.value || '$'} ${n.toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec })}`;
}
function shortNumber(v) {
  const n = Number(v) || 0;
  if (Math.abs(n) >= 1e6) return `${(n / 1e6).toFixed(1)}M`;
  if (Math.abs(n) >= 1e3) return `${(n / 1e3).toFixed(1)}K`;
  return n.toString();
}

async function loadEcharts() {
  if (echartsLib) return echartsLib;
  const echarts = await import('echarts');
  await import('echarts-gl');
  echartsLib = echarts;
  return echartsLib;
}
function initChart(refName) {
  const el = chartRefs[refName]?.value;
  if (!el) return null;
  if (charts[refName]) charts[refName].dispose();
  charts[refName] = echartsLib.init(el, null, { renderer: 'canvas' });
  return charts[refName];
}
function handleResize() {
  Object.values(charts).forEach(c => c && c.resize && c.resize());
}

async function fetchData() {
  const seq = ++fetchSeq;
  loading.value = payload.value === null;
  try {
    const data = await http.get('sales_3d_dashboard_data', {
      warehouse_id: warehouseId.value || 0,
      from: dateRange.value?.[0] || daysAgo(29),
      to: dateRange.value?.[1] || daysAgo(0),
    });
    if (seq !== fetchSeq) return;
    warehouses.value = data.warehouses || [];
    kpis.value = data.kpis || kpis.value;
    payload.value = data;
    loading.value = false;
    await nextTick();
    renderAll();
  } catch (e) {
    if (seq !== fetchSeq) return;
    loading.value = false;
  }
}

function renderAll() {
  if (!echartsLib || !payload.value) return;
  renderSalesMatrix();
  renderTopProducts();
  renderScatter();
  renderPayments();
  renderHeatmap();
  renderClients();
}

function renderSalesMatrix() {
  const chart = initChart('chartSalesMatrix');
  if (!chart) return;
  const d = payload.value.sales_by_month_warehouse || { months: [], warehouses: [], data: [] };
  const max = Math.max(1, ...d.data.map(p => p[2]));
  chart.setOption({
    tooltip: {
      ...tooltipStyle,
      formatter: p => `<b>${d.warehouses[p.value[1]]}</b> · ${d.months[p.value[0]]}<br/><span style="color:#a78bfa">${formatCurrency(p.value[2])}</span>`,
    },
    visualMap: {
      max, show: true, right: 10, top: 'middle', itemWidth: 10, itemHeight: 110,
      inRange: { color: ['#1e1b4b', '#4f46e5', '#8b5cf6', '#ec4899', '#f59e0b'] },
      textStyle: { color: '#334155', fontSize: 11, fontWeight: 500 },
    },
    xAxis3D: { type: 'category', data: d.months, name: t('Month'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    yAxis3D: { type: 'category', data: d.warehouses, name: t('Warehouses'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    zAxis3D: { type: 'value', name: t('Sales'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    grid3D: {
      boxWidth: 200, boxDepth: 80, environment: 'rgba(248, 250, 252, 0)',
      viewControl: { autoRotate: false, projection: 'perspective', distance: 200 },
      light: { main: { intensity: 1.4, shadow: true, shadowQuality: 'high', alpha: 30, beta: 40 }, ambient: { intensity: 0.4 } },
      postEffect: { enable: true, bloom: { enable: true, bloomIntensity: 0.1 }, SSAO: { enable: true, intensity: 1.2, radius: 5 } },
    },
    series: [{
      type: 'bar3D',
      data: d.data.map(p => ({ value: p })),
      shading: 'realistic',
      realisticMaterial: { roughness: 0.3, metalness: 0.2 },
      label: { show: false },
      itemStyle: { opacity: 0.95 },
      emphasis: {
        label: {
          show: true,
          formatter: p => formatCurrency(p.value[2]),
          textStyle: { color: '#fff', backgroundColor: 'rgba(15,23,42,0.85)', padding: [4, 8], borderRadius: 4 },
        },
      },
    }],
  });
}

function renderTopProducts() {
  const chart = initChart('chartTopProducts');
  if (!chart) return;
  const d = payload.value.top_products_by_month || { months: [], products: [], data: [] };
  const max = Math.max(1, ...d.data.map(p => p[2]));
  chart.setOption({
    tooltip: {
      ...tooltipStyle,
      formatter: p => `<b>${d.products[p.value[1]]}</b><br/>${d.months[p.value[0]]} · <span style="color:#22d3ee">${formatCurrency(p.value[2])}</span>`,
    },
    visualMap: {
      max, show: true, right: 10, top: 'middle', itemWidth: 10, itemHeight: 110,
      inRange: { color: ['#0c1638', '#1e40af', '#0ea5e9', '#06b6d4', '#a78bfa', '#ec4899'] },
      textStyle: { color: '#334155', fontSize: 11, fontWeight: 500 },
    },
    xAxis3D: { type: 'category', data: d.months, name: t('Month'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    yAxis3D: { type: 'category', data: d.products, name: t('Product'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    zAxis3D: { type: 'value', name: t('Revenue'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    grid3D: {
      boxWidth: 200, boxDepth: 120,
      viewControl: { autoRotate: false, projection: 'perspective', distance: 220 },
      light: { main: { intensity: 1.3, shadow: true, alpha: 30, beta: 30 }, ambient: { intensity: 0.4 } },
      postEffect: { enable: true, SSAO: { enable: true, intensity: 1.0 } },
    },
    series: [{
      type: 'bar3D',
      data: d.data.map(p => ({ value: p })),
      shading: 'realistic',
      realisticMaterial: { roughness: 0.4, metalness: 0.15 },
      itemStyle: { opacity: 0.95 },
    }],
  });
}

function renderScatter() {
  const chart = initChart('chartScatter');
  if (!chart) return;
  const data = payload.value.product_scatter || [];
  const maxRev = Math.max(1, ...data.map(p => p[2]));
  chart.setOption({
    tooltip: {
      ...tooltipStyle,
      formatter: p => `<b>${p.value[3]}</b><br/>${t('Quantity')}: ${p.value[0]}<br/>${t('Price')}: ${formatCurrency(p.value[1])}<br/><span style="color:#22d3ee">${t('Revenue')}: ${formatCurrency(p.value[2])}</span>`,
    },
    visualMap: { show: false, dimension: 2, max: maxRev, inRange: { color: ['#06b6d4', '#8b5cf6', '#ec4899'] } },
    xAxis3D: { type: 'value', name: t('Quantity'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    yAxis3D: { type: 'value', name: t('Price'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    zAxis3D: { type: 'value', name: t('Revenue'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    grid3D: {
      viewControl: { autoRotate: true, autoRotateSpeed: 6, projection: 'perspective', distance: 220 },
      light: { main: { intensity: 1.2 }, ambient: { intensity: 0.5 } },
      postEffect: { enable: true, bloom: { enable: true, bloomIntensity: 0.4 } },
    },
    series: [{
      type: 'scatter3D',
      data,
      symbolSize: 14,
      itemStyle: { opacity: 0.85 },
      emphasis: { itemStyle: { color: '#f43f5e', borderColor: '#fff', borderWidth: 2 } },
    }],
  });
}

function renderPayments() {
  const chart = initChart('chartPayments');
  if (!chart) return;
  const data = payload.value.payment_methods || [];
  chart.setOption({
    color: pieColors,
    tooltip: {
      ...tooltipStyle,
      trigger: 'item',
      formatter: p => `<b>${p.name}</b><br/>${formatCurrency(p.value)} <span style="color:#a78bfa">(${p.percent}%)</span>`,
    },
    legend: { bottom: 0, textStyle: { color: '#1e293b', fontSize: 12, fontWeight: 500 }, itemWidth: 10, itemHeight: 10, icon: 'circle' },
    series: [{
      type: 'pie',
      radius: ['42%', '72%'],
      center: ['50%', '44%'],
      roseType: 'radius',
      avoidLabelOverlap: true,
      itemStyle: { borderRadius: 10, borderColor: '#fff', borderWidth: 3, shadowBlur: 12, shadowColor: 'rgba(139, 92, 246, 0.18)' },
      label: { color: '#1e293b', fontSize: 12, fontWeight: 600, formatter: '{b}\n{d}%' },
      labelLine: { length: 8, length2: 6 },
      emphasis: { scale: true, scaleSize: 8, itemStyle: { shadowBlur: 20, shadowColor: 'rgba(139,92,246,0.45)' } },
      data,
    }],
  });
}

function renderHeatmap() {
  const chart = initChart('chartHeatmap');
  if (!chart) return;
  const data = payload.value.hour_dow_heatmap || [];
  const days = [t('Sun'), t('Mon'), t('Tue'), t('Wed'), t('Thu'), t('Fri'), t('Sat')];
  const hours = Array.from({ length: 24 }, (_, i) => `${String(i).padStart(2, '0')}h`);
  const max = Math.max(1, ...data.map(p => p[2]));
  chart.setOption({
    tooltip: {
      ...tooltipStyle,
      formatter: p => `<b>${days[p.value[1]]}</b> · ${hours[p.value[0]]}<br/><span style="color:#22d3ee">${formatCurrency(p.value[2])}</span>`,
    },
    visualMap: {
      max, calculable: true, orient: 'horizontal', left: 'center', bottom: 0, itemWidth: 12, itemHeight: 110,
      inRange: { color: ['#0c4a6e', '#0891b2', '#22d3ee', '#a78bfa', '#f472b6'] },
      textStyle: { color: '#334155', fontSize: 11, fontWeight: 500 },
    },
    xAxis3D: { type: 'category', data: hours, name: t('Hour'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    yAxis3D: { type: 'category', data: days, name: t('Day'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    zAxis3D: { type: 'value', name: t('Sales'), nameTextStyle: axis3DName.textStyle, ...axis3DStyle },
    grid3D: {
      boxWidth: 220, boxDepth: 100,
      viewControl: { autoRotate: false, projection: 'perspective', distance: 220 },
      light: { main: { intensity: 1.2, shadow: true, alpha: 30, beta: 30 }, ambient: { intensity: 0.45 } },
      postEffect: { enable: true, SSAO: { enable: true, intensity: 1.0 } },
    },
    series: [{
      type: 'bar3D',
      data: data.map(p => ({ value: p })),
      shading: 'realistic',
      realisticMaterial: { roughness: 0.4, metalness: 0.1 },
      itemStyle: { opacity: 0.92 },
    }],
  });
}

function renderClients() {
  const chart = initChart('chartClients');
  if (!chart) return;
  const data = payload.value.top_clients || [];
  chart.setOption({
    tooltip: {
      ...tooltipStyle,
      trigger: 'axis',
      axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(139, 92, 246, 0.08)' } },
      formatter: p => {
        const it = p[0];
        const row = data[it.dataIndex] || {};
        return `<b>${it.name}</b><br/><span style="color:#a78bfa">${t('Revenue')}: ${formatCurrency(it.value)}</span><br/>${t('Orders')}: ${row.orders || 0}`;
      },
    },
    grid: { left: 140, right: 30, top: 10, bottom: 20, containLabel: false },
    xAxis: {
      type: 'value',
      axisLine: { show: false },
      axisTick: { show: false },
      splitLine: { lineStyle: { color: 'rgba(100, 116, 139, 0.25)', type: 'dashed' } },
      axisLabel: { color: '#475569', fontSize: 12, fontWeight: 500, formatter: v => shortNumber(v) },
    },
    yAxis: {
      type: 'category',
      inverse: true,
      data: data.map(x => x.name),
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: { color: '#1e293b', fontSize: 13, fontWeight: 600 },
    },
    series: [{
      type: 'bar',
      data: data.map(x => x.value),
      barWidth: 16,
      itemStyle: {
        borderRadius: [0, 8, 8, 0],
        color: {
          type: 'linear', x: 0, y: 0, x2: 1, y2: 0,
          colorStops: [
            { offset: 0, color: '#8b5cf6' },
            { offset: 0.5, color: '#6366f1' },
            { offset: 1, color: '#06b6d4' },
          ],
        },
        shadowBlur: 10,
        shadowColor: 'rgba(139,92,246,0.25)',
      },
      emphasis: {
        itemStyle: {
          color: {
            type: 'linear', x: 0, y: 0, x2: 1, y2: 0,
            colorStops: [
              { offset: 0, color: '#ec4899' },
              { offset: 1, color: '#f59e0b' },
            ],
          },
        },
      },
      label: {
        show: true, position: 'right', color: '#0f172a', fontSize: 12, fontWeight: 700,
        formatter: p => shortNumber(p.value),
      },
    }],
  });
}

onMounted(async () => {
  await loadEcharts();
  await fetchData();
  resizeHandler = handleResize;
  window.addEventListener('resize', resizeHandler);
});
onBeforeUnmount(() => {
  if (resizeHandler) window.removeEventListener('resize', resizeHandler);
  Object.keys(charts).forEach(k => {
    if (charts[k] && charts[k].dispose) charts[k].dispose();
    delete charts[k];
  });
});
</script>

<style scoped>
.echart-3d {
  width: 100%;
  height: 420px;
}
.echart-3d--lg {
  height: 520px;
}
.echart-3d--md {
  height: 380px;
}
</style>
