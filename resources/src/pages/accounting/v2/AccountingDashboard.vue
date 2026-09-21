<template>
  <div class="page">
    <PageHeader :title="$t('Accounting_Dashboard_Title')" :breadcrumb="[$t('Accounting'), $t('Accounting_Dashboard_Title')]" />

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-statistic :title="$t('Accounts')" :value="kpi.accounts" /></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-statistic :title="$t('Journal_Entries_30d')" :value="kpi.journals" /></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-statistic :title="$t('Income_30d')" :value="number(kpi.income)" :value-style="{ color: '#3f8600' }" /></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-statistic :title="$t('Expense_30d')" :value="number(kpi.expense)" :value-style="{ color: '#cf1322' }" /></a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]">
        <a-col v-for="link in quickLinks" :key="link.to" :xs="12" :md="8" :xl="4">
          <a-button block @click="$router.push(link.to)">{{ $t(link.label) }}</a-button>
        </a-col>
      </a-row>
    </a-card>

    <a-card size="small" :title="$t('Income_30d') + ' / ' + $t('Expense_30d')">
      <apexchart type="area" height="320" :options="chartOptions" :series="chartSeries" :key="chartKey" />
    </a-card>
  </div>
</template>

<script setup>
/**
 * Accounting v2 dashboard — GET accounting/v2/dashboard → {kpi{accounts,
 * journals_30d, income_30d, expense_30d}, chart{labels, income, expense}}.
 * Money via useFormat().number() (same priceFormat helper legacy used).
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../../components/PageHeader.vue';
import { useFormat } from '../../../composables/useFormat';
import http from '../../../lib/http';

const { t } = useI18n();
const { number } = useFormat();

const kpi = ref({ accounts: 0, journals: 0, income: 0, expense: 0 });
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
  noData: { text: t('No_Data') },
}));

onMounted(async () => {
  try {
    const data = await http.get('accounting/v2/dashboard');
    const k = (data && data.kpi) || {};
    const chart = (data && data.chart) || {};
    kpi.value = {
      accounts: k.accounts || 0,
      journals: k.journals_30d || 0,
      income: k.income_30d || 0,
      expense: k.expense_30d || 0,
    };
    chartLabels.value = chart.labels || [];
    chartSeries.value = [
      { name: t('Income_30d'), data: chart.income || [] },
      { name: t('Expense_30d'), data: chart.expense || [] },
    ];
    chartKey.value++;
  } catch (e) { /* dashboard stays empty */ }
});
</script>
