<template>
  <a-card size="small" :title="title" style="margin-bottom: 16px">
    <apexchart v-if="hasData" :key="renderKey" :type="type" :height="height" :options="options" :series="series" />
    <!-- Always render the panel: hiding it when empty is indistinguishable from
         a broken chart. An empty state says "no data for this range" instead. -->
    <a-empty v-else :description="$t('NodataAvailable')" :image="simpleImage" style="padding: 24px 0" />
  </a-card>
</template>

<script setup>
/**
 * Trend panel for reports whose payload includes a `timeseries` array
 * (e.g. [{ d: '2026-07-01', tax_collected: 12.3, taxable_base: 100 }]).
 *
 * The page passes the rows plus which fields to plot:
 *   <ReportChart :data="crud.payload.value?.timeseries"
 *                :fields="[{ key: 'tax_collected', label: t('Tax_Collected') }]" />
 *
 * Rendered only when there is data, so an empty report shows no empty chart.
 * Colours/grid are theme-neutral so they read on light and dark surfaces.
 */
import { computed } from 'vue';
import { Empty } from 'ant-design-vue';

const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const props = defineProps({
  data: { type: Array, default: () => [] },
  // [{ key, label, money? }] — one series per entry
  fields: { type: Array, required: true },
  title: { type: String, default: '' },
  type: { type: String, default: 'line' }, // line | bar | area
  height: { type: [Number, String], default: 280 },
  xKey: { type: String, default: 'd' },
  // Optional formatter for the y-axis / tooltip (e.g. money from useFormat)
  format: { type: Function, default: null },
});

const rows = computed(() => {
  const d = props.data;
  if (!Array.isArray(d)) {
    // Says whether the endpoint sent no trend data at all vs an empty range.
    if (d !== undefined && d !== null) {
      console.warn('[stocky-next] ReportChart: `data` is not an array —', typeof d, d);
    }
    return [];
  }
  return d;
});
const hasData = computed(() => rows.value.length > 0);

// ApexCharts mutates its own DOM; remount it when the dataset changes rather
// than letting Vue patch around it (the dashboard hit this too).
const renderKey = computed(() => `${props.type}-${rows.value.length}-${rows.value[0]?.[props.xKey] ?? ''}`);

const PALETTE = ['#6d28d9', '#0ea5e9', '#10b981', '#f59e0b', '#f43f5e'];

const series = computed(() =>
  props.fields.map(f => ({
    name: f.label,
    data: rows.value.map(r => Number(r[f.key] || 0)),
  }))
);

const options = computed(() => ({
  chart: {
    type: props.type,
    background: 'transparent',
    foreColor: '#8c8c8c',
    fontFamily: 'inherit',
    toolbar: { show: false },
    zoom: { enabled: false },
  },
  colors: PALETTE,
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: props.type === 'bar' ? 0 : 2 },
  fill:
    props.type === 'area'
      ? { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } }
      : { opacity: 1 },
  legend: { labels: { colors: '#8c8c8c' } },
  xaxis: {
    categories: rows.value.map(r => r[props.xKey]),
    labels: { rotate: -45, style: { fontSize: '11px' } },
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: {
    labels: { formatter: v => (props.format ? props.format(v) : v) },
  },
  grid: { borderColor: 'rgba(128,128,128,0.2)', strokeDashArray: 4 },
  tooltip: {
    theme: 'light',
    y: { formatter: v => (props.format ? props.format(v) : v) },
  },
}));
</script>
