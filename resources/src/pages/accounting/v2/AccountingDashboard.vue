<template>
  <div class="page">
    <PageHeader :title="$t('Accounting_Dashboard_Title')" :breadcrumb="[$t('Accounting'), $t('Accounting_Dashboard_Title')]" />

    <!-- The system date filter (presets + editable Start date / to); the
         KPIs and the chart follow it. Opens on the last 30 days. -->
    <a-card size="small" style="margin-bottom: 16px">
      <PresetRangeFilter v-model:value="range" @change="load" />
    </a-card>

    <a-spin :spinning="loading">
      <!-- Each card opens its details for the same dates: the chart of
           accounts, the journal entries, and Profit & Loss narrowed to the
           income / expense accounts. -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="c in cards" :key="c.key" :xs="12" :md="6">
          <a-card size="small" hoverable class="kpi-card" @click="$router.push(c.to)">
            <a-statistic :title="c.title" :value="c.value" :value-style="c.style" />
            <div class="kpi-more">{{ tf('View_details', 'View details') }} <RightOutlined /></div>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]">
        <a-col v-for="link in quickLinks" :key="link.to" :xs="12" :md="8" :xl="4">
          <a-button block @click="$router.push(link.to)">{{ $t(link.label) }}</a-button>
        </a-col>
      </a-row>
    </a-card>

    <a-card size="small" :title="chartTitle">
      <apexchart type="area" height="320" :options="chartOptions" :series="chartSeries" :key="chartKey" />
    </a-card>
  </div>
</template>

<script setup>
/**
 * Accounting v2 dashboard -- GET accounting/v2/dashboard?from=&to= ->
 * {kpi{accounts, journals_30d, income_30d, expense_30d} (for the range,
 * despite the legacy key names), chart{labels, income, expense},
 * range{from, to, group: day|month}}. No range ("All") = from=all, since the
 * company's first entry. Company-scoped server side.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { RightOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import PresetRangeFilter from '../../../components/PresetRangeFilter.vue';
import { useFormat } from '../../../composables/useFormat';
import { t as tf } from '../../../i18n';
import http from '../../../lib/http';

const { number } = useFormat();

const range = ref([dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const loading = ref(false);
const kpi = ref({ accounts: 0, journals: 0, income: 0, expense: 0 });
const shown = ref({ from: '', to: '', group: 'day' });
const chartLabels = ref([]);
const chartSeries = ref([
  { name: 'Income', data: [] },
  { name: 'Expense', data: [] },
]);
const chartKey = ref(0);

const quickLinks = [
  { to: '/accounting-v2/chart-of-accounts', label: 'Chart_of_Accounts_Link' },
  { to: '/accounting-v2/journal-entries', label: 'Journal_Entries_Link' },
  { to: '/accounting-v2/reports/trial-balance', label: 'Trial_Balance_Link' },
  { to: '/accounting-v2/reports/profit-and-loss', label: 'Profit_Loss_Link' },
  { to: '/accounting-v2/reports/balance-sheet', label: 'Balance_Sheet_Link' },
  { to: '/accounting-v2/reports/tax-report', label: 'Tax_Summary_Link' },
];

// The range the figures cover (as resolved by the server -- "All" included).
const rangeQuery = computed(() => (shown.value.from ? { from: shown.value.from, to: shown.value.to } : {}));
const cards = computed(() => [
  { key: 'accounts', title: tf('Accounts', 'Accounts'), value: kpi.value.accounts, to: '/accounting-v2/chart-of-accounts' },
  { key: 'journals', title: tf('Journal_Entries', 'Journal Entries'), value: kpi.value.journals, to: { path: '/accounting-v2/journal-entries', query: rangeQuery.value } },
  { key: 'income', title: tf('Income', 'Income'), value: number(kpi.value.income), style: { color: '#3f8600' }, to: { path: '/accounting-v2/reports/profit-and-loss', query: { ...rangeQuery.value, type: 'income' } } },
  { key: 'expense', title: tf('Expense', 'Expense'), value: number(kpi.value.expense), style: { color: '#cf1322' }, to: { path: '/accounting-v2/reports/profit-and-loss', query: { ...rangeQuery.value, type: 'expense' } } },
]);

const chartTitle = computed(() => {
  const base = `${tf('Income', 'Income')} / ${tf('Expense', 'Expense')}`;
  const per = shown.value.group === 'month' ? ` (${tf('Monthly', 'by month')})` : '';
  return shown.value.from ? `${base}${per} -- ${shown.value.from} → ${shown.value.to}` : base;
});

const chartOptions = computed(() => ({
  chart: { type: 'area', fontFamily: 'inherit', toolbar: { show: false } },
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2 },
  fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 95, 100] } },
  xaxis: { categories: chartLabels.value, labels: { rotate: -45 } },
  legend: { position: 'top' },
  tooltip: { shared: true, intersect: false, y: { formatter: val => number(val) } },
  yaxis: [{ labels: { formatter: val => number(val) } }],
  colors: ['#4CAF50', '#EF5350'],
  noData: { text: tf('No_Data', 'No data') },
}));

async function load() {
  loading.value = true;
  try {
    const params = range.value?.[0] ? { from: range.value[0], to: range.value[1] } : { from: 'all' };
    const data = await http.get('accounting/v2/dashboard', params);
    const k = (data && data.kpi) || {};
    const chart = (data && data.chart) || {};
    kpi.value = {
      accounts: k.accounts || 0,
      journals: k.journals_30d || 0,
      income: k.income_30d || 0,
      expense: k.expense_30d || 0,
    };
    shown.value = { from: '', to: '', group: 'day', ...((data && data.range) || {}) };
    chartLabels.value = chart.labels || [];
    chartSeries.value = [
      { name: tf('Income', 'Income'), data: chart.income || [] },
      { name: tf('Expense', 'Expense'), data: chart.expense || [] },
    ];
    chartKey.value++;
  } catch (e) { /* dashboard stays as it was */ } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.kpi-card {
  cursor: pointer;
  height: 100%;
}
.kpi-more {
  margin-top: 6px;
  font-size: 12px;
  color: #1677ff;
}
</style>
