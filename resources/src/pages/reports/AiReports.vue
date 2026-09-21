<template>
  <div class="page">
    <PageHeader :title="$t('AI_Reports')" :breadcrumb="[$t('Reports'), $t('AI_Reports')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <!-- Question picker -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]" align="bottom">
        <a-col :xs="24" :md="10">
          <div class="filter-label">{{ $t('Question') }}</div>
          <a-select
            v-model:value="selectedQuestionId" :placeholder="$t('Select_a_question')"
            :options="questions.map(q => ({ label: q.title, value: q.id }))"
            show-search option-filter-prop="label" style="width: 100%"
          />
        </a-col>
        <a-col :xs="24" :md="8">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="warehouseId" :placeholder="$t('All_Warehouses')"
            :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
            show-search option-filter-prop="label" allow-clear style="width: 100%"
          />
        </a-col>
        <a-col :xs="24" :md="6">
          <a-button type="primary" block :disabled="!selectedQuestionId" :loading="isLoading" @click="runReport">
            <template #icon><PlayCircleOutlined /></template>
            {{ isLoading ? $t('Running') + '...' : $t('Run') }}
          </a-button>
        </a-col>
      </a-row>
    </a-card>

    <!-- Loading -->
    <a-card v-if="isLoading" style="text-align: center; padding: 32px 0">
      <a-spin size="large" />
      <h3 style="margin-top: 24px">{{ $t('Generating_Your_Report') }}</h3>
      <p style="color: #999">{{ $t('Analyzing_data_and_preparing_insights') }}</p>
      <a-steps
        :current="loadingStep - 1" size="small" style="max-width: 640px; margin: 24px auto 0"
        :items="[{ title: $t('Collecting_Data') }, { title: $t('Analyzing') }, { title: $t('Generating_Insights') }]"
      />
    </a-card>

    <!-- Results -->
    <template v-else-if="reportResult">
      <a-alert
        type="success" show-icon style="margin-bottom: 16px"
        :message="$t('Report_Generated_Successfully')"
        :description="$t('Your_AI_powered_insights_are_ready') + ' · ' + getCurrentTime()"
      />

      <!-- Filters summary -->
      <a-card size="small" :title="$t('Report_Filters')" style="margin-bottom: 16px">
        <a-space wrap>
          <a-tag v-for="(f, i) in activeFilters" :key="i" color="blue">
            <strong>{{ f.label }}:</strong> {{ f.value }}
          </a-tag>
        </a-space>
      </a-card>

      <!-- AI insights -->
      <a-alert
        v-if="reportResult.insights"
        type="info" show-icon style="margin-bottom: 16px"
        :message="$t('AI_Analysis_And_Insights')"
      >
        <template #description>
          <p style="margin-bottom: 8px">{{ reportResult.insights }}</p>
          <template v-if="aiSuggestions.length">
            <strong style="font-size: 12px">{{ $t('Key_Recommendations') }}:</strong>
            <ul style="margin: 4px 0 0; padding-left: 18px; font-size: 12px">
              <li v-for="(s, i) in aiSuggestions" :key="i">{{ s }}</li>
            </ul>
          </template>
        </template>
      </a-alert>

      <!-- Actions -->
      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px">
          <div>
            <div style="font-size: 16px; font-weight: 600">{{ reportResult.question.title }}</div>
            <div style="font-size: 12px; color: #999">{{ questionUi.subtitle }}</div>
          </div>
          <a-space>
            <a-button size="small" @click="exportToPDF(reportKind)">
              <template #icon><FilePdfOutlined /></template>
              {{ $t('PDF') }}
            </a-button>
            <a-button size="small" @click="exportToExcel(reportKind)">
              <template #icon><FileExcelOutlined /></template>
              {{ $t('EXCEL') }}
            </a-button>
            <a-button size="small" @click="printReport(reportKind)">
              <template #icon><PrinterOutlined /></template>
              {{ $t('print') }}
            </a-button>
          </a-space>
        </div>
      </a-card>

      <!-- DAILY SALES -->
      <template v-if="reportKey === 'daily_sales_summary'">
        <a-card size="small" :title="$t('Key_Performance_Indicators')" style="margin-bottom: 16px">
          <a-row :gutter="[16, 16]">
            <a-col v-for="(stat, i) in dailySalesStats" :key="i" :xs="12" :md="8" :xl="4">
              <a-card size="small">
                <a-statistic :title="stat.label" :value="stat.value" :value-style="{ color: stat.color, fontSize: '18px' }" />
              </a-card>
            </a-col>
          </a-row>
        </a-card>
        <a-card size="small" :title="questionUi.chartTitle" style="margin-bottom: 16px">
          <apexchart
            :key="'ds-' + chartKey" :type="questionUi.chart.type" :height="questionUi.chart.height"
            :options="dailySalesChartOptions" :series="dailySalesChartSeries"
          />
        </a-card>
      </template>

      <!-- PRODUCTS -->
      <template v-else-if="reportKey === 'sales_by_product'">
        <a-card size="small" :title="questionUi.chartTitle" style="margin-bottom: 16px">
          <apexchart
            :key="'pr-' + chartKey" type="bar" :height="questionUi.chart.height"
            :options="productChartOptions" :series="productChartSeries"
          />
        </a-card>
        <a-card size="small" :title="questionUi.tableTitle" :body-style="{ padding: 0 }" style="margin-bottom: 16px">
          <div style="padding: 12px 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
            <a-input-search v-model:value="searchTerm" :placeholder="$t('Search_this_table')" allow-clear style="max-width: 260px" />
          </div>
          <a-table
            :columns="productColumns" :data-source="filteredRows"
            :pagination="{ pageSize: questionUi.table.perPage, showSizeChanger: true, pageSizeOptions: questionUi.table.perPageDropdown.map(String) }"
            size="middle" :row-key="(_r, i) => i" :scroll="{ x: 'max-content' }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">{{ productDisplayName(record) }}</template>
              <template v-else-if="column.key === 'revenue'">
                <span style="color: #3f8600; font-weight: 600">{{ money(record.revenue) }}</span>
              </template>
              <template v-else-if="column.key === 'cost'">
                <span style="color: #cf1322">{{ money(record.cost) }}</span>
              </template>
              <template v-else-if="column.key === 'profit'">
                <span style="color: #1677ff; font-weight: 700">{{ money(record.profit) }}</span>
              </template>
              <template v-else-if="column.key === 'margin_percent'">
                <a-tag :color="record.margin_percent >= 30 ? 'success' : record.margin_percent >= 15 ? 'warning' : 'error'">
                  {{ record.margin_percent }}%
                </a-tag>
              </template>
            </template>
            <template v-if="filteredRows.length" #summary>
              <a-table-summary-row>
                <a-table-summary-cell :index="0"><b>{{ $t('Total') }}</b></a-table-summary-cell>
                <a-table-summary-cell :index="1" align="right"><b>{{ productTotals.qty }}</b></a-table-summary-cell>
                <a-table-summary-cell :index="2" align="right">
                  <b style="color: #3f8600">{{ money(productTotals.revenue) }}</b>
                </a-table-summary-cell>
                <a-table-summary-cell :index="3" align="right">
                  <b style="color: #cf1322">{{ money(productTotals.cost) }}</b>
                </a-table-summary-cell>
                <a-table-summary-cell :index="4" align="right">
                  <b style="color: #1677ff">{{ money(productTotals.profit) }}</b>
                </a-table-summary-cell>
                <a-table-summary-cell :index="5" />
              </a-table-summary-row>
            </template>
          </a-table>
        </a-card>
      </template>

      <!-- LATE PAYMENTS -->
      <template v-else-if="reportKey === 'late_payments'">
        <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
          <a-col :xs="24" :md="8">
            <a-card size="small"><a-statistic :title="$t('Total_Outstanding')" :value="totalOutstanding" :value-style="{ color: '#cf1322' }" /></a-card>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-card size="small"><a-statistic :title="$t('Affected_Customers')" :value="rows.length" :value-style="{ color: '#d48806' }" /></a-card>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-card size="small"><a-statistic :title="$t('Avg_Days_Overdue')" :value="avgDaysOverdue + ' ' + $t('days')" :value-style="{ color: '#1677ff' }" /></a-card>
          </a-col>
        </a-row>
        <a-card size="small" :title="questionUi.chartTitle" style="margin-bottom: 16px">
          <apexchart
            :key="'lp-' + chartKey" :type="questionUi.chart.type" :height="questionUi.chart.height"
            :options="latePaymentsChartOptions" :series="latePaymentsChartSeries"
          />
        </a-card>
        <a-card size="small" :title="$t('Customer_Payment_Details')" :body-style="{ padding: 0 }" style="margin-bottom: 16px">
          <div style="padding: 12px 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
            <a-input-search v-model:value="searchTerm" :placeholder="$t('Search_this_table')" allow-clear style="max-width: 260px" />
          </div>
          <a-table
            :columns="latePaymentsColumns" :data-source="filteredRows"
            :pagination="{ pageSize: 10, showSizeChanger: true, pageSizeOptions: ['10', '20', '50'] }"
            size="middle" :row-key="(_r, i) => i" :scroll="{ x: 'max-content' }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'outstanding_amount'">
                <span style="color: #cf1322; font-weight: 700">{{ money(record.outstanding_amount) }}</span>
              </template>
              <template v-else-if="column.key === 'max_days_overdue'">
                <a-tag :color="record.max_days_overdue > 60 ? 'error' : record.max_days_overdue > 30 ? 'warning' : 'blue'">
                  {{ record.max_days_overdue }} {{ $t('days') }}
                </a-tag>
              </template>
            </template>
            <template v-if="filteredRows.length" #summary>
              <a-table-summary-row>
                <a-table-summary-cell :index="0"><b>{{ $t('Total') }}</b></a-table-summary-cell>
                <a-table-summary-cell :index="1" align="center"><b>{{ latePaymentTotals.invoices }}</b></a-table-summary-cell>
                <a-table-summary-cell :index="2" align="right">
                  <b style="color: #cf1322">{{ money(latePaymentTotals.outstanding) }}</b>
                </a-table-summary-cell>
                <a-table-summary-cell :index="3" />
              </a-table-summary-row>
            </template>
          </a-table>
        </a-card>
      </template>
    </template>

    <!-- Empty state -->
    <a-card v-else style="text-align: center; padding: 32px 0">
      <a-empty :description="$t('No_Report_Selected')">
        <template #image><BulbOutlined style="font-size: 56px; color: #d9d9d9" /></template>
      </a-empty>
      <p style="color: #999; margin-top: 8px">{{ $t('Select_a_question_from_above_and_click_Run_to_generate_your_report') }}</p>
    </a-card>
  </div>
</template>

<script setup>
/**
 * AI Reports — GET report-questions → {questions, warehouses}; POST
 * report-questions/run {question_id, warehouse_id|null} → {question, filters,
 * data, insights, compare?}. Three report kinds by question.report_key:
 * daily_sales_summary (object data + KPI cards), sales_by_product (array +
 * margin table), late_payments (array + aging summary). The per-question UI
 * config (IDs 1..20 → chart type/variant/metric/topN/paging) is legacy
 * verbatim, as are the PDF (jsPDF+autotable), CSV, and print exports.
 * Money = currency + priceFormat at 2 decimals (legacy pinned dec 2 here).
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import {
  PlayCircleOutlined, FilePdfOutlined, FileExcelOutlined, PrinterOutlined, BulbOutlined,
} from '@ant-design/icons-vue';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import PageHeader from '../../components/PageHeader.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';

const { number, currency } = useFormat();

const isLoading = ref(false);
const loadingStep = ref(1);
let loadingInterval = null;
const questions = ref([]);
const warehouses = ref([]);
const selectedQuestionId = ref(null);
const warehouseId = ref(null);
const reportResult = ref(null);
const searchTerm = ref('');

function num(v) {
  const n = parseFloat(v || 0);
  return Number.isNaN(n) ? 0 : n;
}
function money(v) {
  return `${currency.value || 'USD'} ${number(num(v), 2)}`;
}
function getCurrentTime() {
  return new Date().toLocaleString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}
function productDisplayName(row) {
  if (!row) return '';
  return row.name || row.product_name || row.label || t('Unknown', 'Unknown');
}

const reportKey = computed(() => String(reportResult.value?.question?.report_key || ''));
const reportKind = computed(() => {
  if (reportKey.value === 'sales_by_product') return 'products';
  if (reportKey.value === 'late_payments') return 'late_payments';
  return 'daily_sales';
});
const rows = computed(() =>
  Array.isArray(reportResult.value?.data) ? reportResult.value.data : []);
const filteredRows = computed(() => {
  const q = searchTerm.value.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter(r => JSON.stringify(r).toLowerCase().includes(q));
});
const chartKey = computed(() => {
  if (!reportResult.value) return 'none';
  const q = reportResult.value.question || {};
  const names = rows.value.slice(0, 10).map(p => p.name || p.product_name || '').join('|');
  return `q${q.id}-n${rows.value.length}-${names}`;
});

const activeFilters = computed(() => {
  if (!reportResult.value || !reportResult.value.filters) return [];
  const filters = [];
  if (reportResult.value.question?.title) {
    filters.push({ label: t('Question', 'Question'), value: reportResult.value.question.title });
  }
  if (reportResult.value.filters.warehouse_id) {
    const w = warehouses.value.find(x => x.id === reportResult.value.filters.warehouse_id);
    filters.push({ label: t('Warehouse', 'Warehouse'), value: w ? w.name : '' });
  }
  return filters;
});

const AI_SUGGESTIONS = {
  daily_sales: [
    ['Consider_analyzing_peak_sales_hours_to_optimize_staffing', 'Consider analyzing peak sales hours to optimize staffing'],
    ['Review_discount_patterns_to_maximize_profitability', 'Review discount patterns to maximize profitability'],
    ['Monitor_tax_collection_efficiency', 'Monitor tax collection efficiency'],
    ['Compare_with_previous_periods_for_trend_analysis', 'Compare with previous periods for trend analysis'],
  ],
  products: [
    ['Focus_marketing_efforts_on_high_profit_margin_products', 'Focus marketing efforts on high-profit margin products'],
    ['Review_low_performing_products_for_discontinuation', 'Review low-performing products for discontinuation'],
    ['Consider_bulk_purchasing_for_high_volume_items', 'Consider bulk purchasing for high-volume items'],
    ['Optimize_pricing_strategy_based_on_margin_analysis', 'Optimize pricing strategy based on margin analysis'],
  ],
  late_payments: [
    ['Implement_stricter_payment_terms_for_high_risk_customers', 'Implement stricter payment terms for high-risk customers'],
    ['Send_automated_payment_reminders', 'Send automated payment reminders'],
    ['Consider_offering_early_payment_discounts', 'Consider offering early payment discounts'],
    ['Review_credit_limits_for_customers_with_extended_overdue_periods', 'Review credit limits for customers with extended overdue periods'],
  ],
};
const aiSuggestions = computed(() =>
  reportResult.value ? (AI_SUGGESTIONS[reportKind.value] || []).map(([key, fallback]) => t(key, fallback)) : []);

/* ---------- Per-question UI config (legacy verbatim, IDs 1..20) ---------- */
const questionUi = computed(() => {
  const q = reportResult.value?.question || {};
  const id = Number(q.id || 0);
  const key = String(q.report_key || '');
  const base = {
    subtitle: t('Advanced_report_with_AI_insights_charts_export_and_print', 'Advanced report with AI insights, charts, export & print.'),
    chartTitle: t('Visualization', 'Visualization'),
    tableTitle: t('Detailed_Data', 'Detailed Data'),
    chart: { type: 'bar', height: 380, variant: 'default', metric: 'profit', topN: 10, horizontal: false, stacked: false },
    table: { perPage: 10, perPageDropdown: [10, 20, 50] },
  };

  if (key === 'daily_sales_summary') {
    const daily = { ...base, chart: { ...base.chart, type: 'donut', height: 350, variant: 'breakdown_donut' } };
    switch (id) {
      case 1: return { ...daily, subtitle: t('Yesterday_snapshot_with_revenue_mix_and_KPIs', 'Yesterday snapshot with revenue mix & KPIs.'), chartTitle: t('Revenue_Mix_Yesterday', 'Revenue Mix (Yesterday)') };
      case 2: return { ...daily, subtitle: t('Profit_drop_investigation_vs_previous_day_compare_view', 'Profit-drop investigation vs previous day (compare view).'), chartTitle: t('Current_vs_Previous_Day_Compare', 'Current vs Previous Day (Compare)'), chart: { ...daily.chart, type: 'bar', height: 360, variant: 'compare_bar' } };
      case 3: return { ...daily, subtitle: t('Today_snapshot_with_profitability_gauges', 'Today snapshot with profitability gauges.'), chartTitle: t('Performance_Gauges_Today', 'Performance Gauges (Today)'), chart: { ...daily.chart, type: 'radialBar', height: 360, variant: 'margin_radial' } };
      case 4: return { ...daily, subtitle: t('Week_to_date_KPI_overview_with_metrics_bar', 'Week-to-date KPI overview with metrics bar.'), chartTitle: t('Week_Metrics_Overview', 'Week Metrics Overview'), chart: { ...daily.chart, type: 'bar', height: 360, variant: 'metrics_bar' } };
      case 5: return { ...daily, subtitle: t('Last_week_snapshot_with_a_different_mix_view', 'Last week snapshot with a different mix view.'), chartTitle: t('Revenue_Mix_Last_Week', 'Revenue Mix (Last Week)'), chart: { ...daily.chart, type: 'donut', height: 350, variant: 'breakdown_donut_alt' } };
      case 6: return { ...daily, subtitle: t('This_week_vs_last_week_comparison_AI_insights_ready', 'This week vs last week comparison (AI insights ready).'), chartTitle: t('This_Week_vs_Last_Week_Compare', 'This Week vs Last Week (Compare)'), chart: { ...daily.chart, type: 'bar', height: 360, variant: 'compare_bar' } };
      case 7: return { ...daily, subtitle: t('Month_to_date_performance_summary', 'Month-to-date performance summary.'), chartTitle: t('Month_Metrics_Overview', 'Month Metrics Overview'), chart: { ...daily.chart, type: 'bar', height: 360, variant: 'metrics_bar' } };
      case 8: return { ...daily, subtitle: t('Last_month_snapshot_with_profitability_gauges', 'Last month snapshot with profitability gauges.'), chartTitle: t('Performance_Gauges_Last_Month', 'Performance Gauges (Last Month)'), chart: { ...daily.chart, type: 'radialBar', height: 360, variant: 'margin_radial' } };
      case 20: return { ...daily, subtitle: t('Profit_change_analysis_yesterday_vs_day_before_compare_view', 'Profit change analysis: yesterday vs day before (compare view).'), chartTitle: t('Profit_Change_Compare', 'Profit Change (Compare)'), chart: { ...daily.chart, type: 'bar', height: 360, variant: 'compare_bar' } };
      default: return daily;
    }
  }

  if (key === 'sales_by_product') {
    const prod = {
      ...base,
      subtitle: t('Product_performance_with_charts_plus_detailed_margin_table', 'Product performance with charts + detailed margin table.'),
      chartTitle: t('Top_Products_Chart', 'Top Products (Chart)'),
      tableTitle: t('Product_Performance_Table', 'Product Performance Table'),
      chart: { ...base.chart, type: 'bar', height: 420, variant: 'product', metric: 'profit', topN: 10 },
    };
    switch (id) {
      case 9: return { ...prod, subtitle: t('Top_profit_winners_vertical_ranking', 'Top profit winners (vertical ranking).'), chartTitle: t('Top_Profit_Products', 'Top Profit Products'), chart: { ...prod.chart, metric: 'profit' } };
      case 10: return { ...prod, subtitle: t('This_month_profit_vs_revenue_stacked_comparison', 'This month: profit vs revenue (stacked comparison).'), chartTitle: t('Revenue_vs_Profit_Top_Products', 'Revenue vs Profit (Top Products)'), chart: { ...prod.chart, metric: 'profit_vs_revenue', stacked: true } };
      case 11: return { ...prod, subtitle: t('Revenue_leaders_focus_on_top_line', 'Revenue leaders (focus on top-line).'), chartTitle: t('Top_Revenue_Products', 'Top Revenue Products'), chart: { ...prod.chart, metric: 'revenue' } };
      case 12: return { ...prod, subtitle: t('Best_sellers_by_quantity_volume_focus', 'Best-sellers by quantity (volume focus).'), chartTitle: t('Top_Quantity_Sold', 'Top Quantity Sold'), chart: { ...prod.chart, metric: 'qty' } };
      case 13: return { ...prod, subtitle: t('Last_month_profit_ranking_with_a_compact_view', 'Last month profit ranking with a compact view.'), chartTitle: t('Last_Month_Profit_Ranking', 'Last Month Profit Ranking'), chart: { ...prod.chart, metric: 'profit' } };
      case 19: return { ...prod, subtitle: t('Extended_list_top_25_plus_chart_top_15', 'Extended list (top 25) + chart (top 15).'), chartTitle: t('Top_Profit_Products_Top_15_Chart', 'Top Profit Products (Top 15 Chart)'), chart: { ...prod.chart, metric: 'profit', topN: 15 }, table: { perPage: 25, perPageDropdown: [10, 25, 50] } };
      default: return prod;
    }
  }

  if (key === 'late_payments') {
    const late = {
      ...base,
      subtitle: t('Overdue_customers_with_distribution_plus_collection_guidance', 'Overdue customers with distribution + collection guidance.'),
      chartTitle: t('Outstanding_Distribution', 'Outstanding Distribution'),
      chart: { ...base.chart, type: 'pie', height: 350, variant: 'amount_pie', topN: 10 },
    };
    switch (id) {
      case 14: return { ...late, subtitle: t('30_plus_days_overdue_top_outstanding_customers_pie', '30+ days overdue: top outstanding customers (pie).'), chartTitle: t('Outstanding_Amount_Top_10', 'Outstanding Amount (Top 10)') };
      case 15: return { ...late, subtitle: t('60_plus_days_overdue_see_who_is_most_overdue_bar', '60+ days overdue: see who is most overdue (bar).'), chartTitle: t('Days_Overdue_Top_10', 'Days Overdue (Top 10)'), chart: { ...late.chart, type: 'bar', height: 380, variant: 'days_bar', horizontal: true } };
      case 16: return { ...late, subtitle: t('90_plus_days_overdue_aging_buckets_overview_donut', '90+ days overdue: aging buckets overview (donut).'), chartTitle: t('Aging_Buckets_Counts', 'Aging Buckets (Counts)'), chart: { ...late.chart, type: 'donut', variant: 'aging_bucket', topN: 0 } };
      case 17: return { ...late, subtitle: t('7_plus_days_overdue_quick_action_list_bar_by_amount', '7+ days overdue: quick action list (bar by amount).'), chartTitle: t('Outstanding_Amount_Bar', 'Outstanding Amount (Bar)'), chart: { ...late.chart, type: 'bar', height: 380, variant: 'amount_bar', topN: 12 } };
      case 18: return { ...late, subtitle: t('14_plus_days_overdue_focus_on_biggest_balances_donut', '14+ days overdue: focus on biggest balances (donut).'), chartTitle: t('Outstanding_Amount_Top_12', 'Outstanding Amount (Top 12)'), chart: { ...late.chart, type: 'donut', variant: 'amount_pie', topN: 12 } };
      default: return late;
    }
  }

  return base;
});

/* ---------- Daily sales chart ---------- */
const dailySalesStats = computed(() => {
  const data = reportResult.value?.data;
  if (!data) return [];
  return [
    { label: t('Transactions', 'Transactions'), value: num(data.transactions), color: '#1677ff' },
    { label: t('Revenue', 'Revenue'), value: money(data.revenue), color: '#3f8600' },
    { label: t('Tax', 'Tax'), value: money(data.tax), color: '#d48806' },
    { label: t('Discount', 'Discount'), value: money(data.discount), color: '#722ed1' },
    { label: t('Profit', 'Profit'), value: money(data.profit), color: '#08979c' },
  ];
});
const dailySalesChartOptions = computed(() => {
  const data = reportResult.value?.data;
  if (!data) return {};
  const variant = questionUi.value.chart.variant || 'breakdown_donut';

  if (variant === 'compare_bar') {
    return {
      chart: { type: 'bar', toolbar: { show: true } },
      plotOptions: { bar: { horizontal: false, columnWidth: '55%', endingShape: 'rounded' } },
      dataLabels: { enabled: false },
      xaxis: { categories: [t('Revenue', 'Revenue'), t('Profit', 'Profit'), t('Transactions', 'Transactions')] },
      tooltip: {
        y: {
          formatter: (val, opts) => {
            const idx = opts && typeof opts.dataPointIndex === 'number' ? opts.dataPointIndex : -1;
            if (idx === 2) return `${Math.round(num(val))}`;
            return money(val);
          },
        },
      },
      colors: ['#667eea', '#11998e'],
      legend: { position: 'top' },
    };
  }
  if (variant === 'metrics_bar') {
    const fmt = (val, opts) => {
      const label = opts?.w?.globals?.labels ? opts.w.globals.labels[opts.dataPointIndex] : '';
      if (label === t('Transactions', 'Transactions')) return `${Math.round(num(val))}`;
      return money(val);
    };
    return {
      chart: { type: 'bar', toolbar: { show: true } },
      plotOptions: { bar: { horizontal: true, barHeight: '55%', distributed: true } },
      dataLabels: { enabled: true, formatter: fmt },
      xaxis: { categories: [t('Revenue', 'Revenue'), t('Profit', 'Profit'), t('Tax', 'Tax'), t('Discount', 'Discount'), t('Transactions', 'Transactions')] },
      colors: ['#28a745', '#17a2b8', '#ffc107', '#6f42c1', '#667eea'],
      tooltip: { y: { formatter: fmt } },
    };
  }
  if (variant === 'margin_radial') {
    const revenue = num(data.revenue || 0);
    const profit = num(data.profit || 0);
    const pct = n => (revenue > 0 ? Math.max(0, Math.min(100, (n / revenue) * 100)) : 0);
    return {
      chart: { type: 'radialBar', toolbar: { show: true } },
      plotOptions: {
        radialBar: {
          hollow: { size: '40%' },
          dataLabels: {
            name: { fontSize: '14px' },
            value: { formatter: v => `${Number(v || 0).toFixed(1)}%` },
            total: { show: true, label: t('Profit_Margin', 'Profit Margin'), formatter: () => `${pct(profit).toFixed(1)}%` },
          },
        },
      },
      labels: [t('Profit_Percent', 'Profit %'), t('Tax_Percent', 'Tax %'), t('Discount_Percent', 'Discount %')],
      colors: ['#17a2b8', '#ffc107', '#6f42c1'],
    };
  }
  return {
    chart: { type: 'donut', toolbar: { show: true } },
    labels: [t('Revenue', 'Revenue'), t('Tax', 'Tax'), t('Discount', 'Discount'), t('Profit', 'Profit')],
    colors: ['#28a745', '#ffc107', '#6f42c1', '#17a2b8'],
    legend: { position: 'bottom', fontSize: '14px' },
    dataLabels: { enabled: true, formatter: val => `${val.toFixed(1)}%` },
    tooltip: { y: { formatter: val => money(val) } },
    plotOptions: {
      pie: {
        donut: {
          size: '65%',
          labels: {
            show: true,
            name: { show: true, fontSize: '16px', fontWeight: 600 },
            value: { show: true, fontSize: '20px', fontWeight: 700, formatter: val => money(val) },
            total: { show: true, label: t('Total', 'Total'), formatter: () => money(data.revenue || 0) },
          },
        },
      },
    },
  };
});
const dailySalesChartSeries = computed(() => {
  const data = reportResult.value?.data;
  if (!data) return [];
  const variant = questionUi.value.chart.variant || 'breakdown_donut';

  if (variant === 'compare_bar') {
    const prev = reportResult.value?.compare || null;
    const cur = [num(data.revenue || 0), num(data.profit || 0), num(data.transactions || 0)];
    return prev
      ? [
          { name: t('Current', 'Current'), data: cur },
          { name: t('Previous', 'Previous'), data: [num(prev.revenue || 0), num(prev.profit || 0), num(prev.transactions || 0)] },
        ]
      : [{ name: t('Current', 'Current'), data: cur }];
  }
  if (variant === 'metrics_bar') {
    return [{
      name: t('Metrics', 'Metrics'),
      data: [num(data.revenue || 0), num(data.profit || 0), num(data.tax || 0), num(data.discount || 0), num(data.transactions || 0)],
    }];
  }
  if (variant === 'margin_radial') {
    const revenue = num(data.revenue || 0);
    const pct = n => (revenue > 0 ? Math.max(0, Math.min(100, (n / revenue) * 100)) : 0);
    return [pct(num(data.profit || 0)), pct(num(data.tax || 0)), pct(num(data.discount || 0))];
  }
  return [num(data.revenue || 0), num(data.tax || 0), num(data.discount || 0), num(data.profit || 0)];
});

/* ---------- Products chart + table ---------- */
const productColumns = [
  { title: t('Product', 'Product'), key: 'name' },
  { title: t('Quantity', 'Quantity'), dataIndex: 'qty', key: 'qty', align: 'right' },
  { title: t('Revenue', 'Revenue'), key: 'revenue', align: 'right' },
  { title: t('Cost', 'Cost'), key: 'cost', align: 'right' },
  { title: t('Profit', 'Profit'), key: 'profit', align: 'right' },
  { title: t('Margin_Percent', 'Margin %'), key: 'margin_percent', align: 'right' },
];
const productTotals = computed(() => ({
  qty: filteredRows.value.reduce((s, item) => s + num(item.qty || 0), 0),
  revenue: filteredRows.value.reduce((s, item) => s + num(item.revenue || 0), 0),
  cost: filteredRows.value.reduce((s, item) => s + num(item.cost || 0), 0),
  profit: filteredRows.value.reduce((s, item) => s + num(item.profit || 0), 0),
}));
const productChartOptions = computed(() => {
  if (!rows.value.length) return {};
  const cfg = questionUi.value.chart;
  const topProducts = rows.value.slice(0, Number(cfg.topN || 10));
  const metric = String(cfg.metric || 'profit');
  const horizontal = !!cfg.horizontal;
  const isQty = metric === 'qty';
  const formatVal = val => (isQty ? `${Math.round(num(val))}` : money(val));
  return {
    chart: { type: 'bar', toolbar: { show: true }, stacked: !!cfg.stacked },
    plotOptions: { bar: { horizontal, columnWidth: '55%', endingShape: 'rounded', dataLabels: { position: 'top' } } },
    dataLabels: { enabled: true, formatter: formatVal, offsetY: horizontal ? 0 : -20, style: { fontSize: '12px', colors: ['#304758'] } },
    grid: { padding: { left: horizontal ? 16 : 8, right: 8 }, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
    xaxis: {
      categories: topProducts.map((p, i) => productDisplayName(p) || `${t('Product', 'Product')} ${i + 1}`),
      labels: { rotate: horizontal ? 0 : -45, style: { fontSize: horizontal ? '13px' : '12px' } },
    },
    yaxis: { labels: { show: true, formatter: formatVal } },
    tooltip: { y: { formatter: formatVal } },
    colors:
      metric === 'profit' ? ['#667eea']
        : metric === 'revenue' ? ['#28a745']
          : metric === 'qty' ? ['#fd7e14']
            : ['#667eea', '#28a745'],
    legend: { position: 'top' },
  };
});
const productChartSeries = computed(() => {
  if (!rows.value.length) return [];
  const cfg = questionUi.value.chart;
  const topProducts = rows.value.slice(0, Number(cfg.topN || 10));
  const metric = String(cfg.metric || 'profit');
  if (metric === 'profit_vs_revenue') {
    return [
      { name: t('Revenue', 'Revenue'), data: topProducts.map(p => num(p.revenue || 0)) },
      { name: t('Profit', 'Profit'), data: topProducts.map(p => num(p.profit || 0)) },
    ];
  }
  if (metric === 'revenue') return [{ name: t('Revenue', 'Revenue'), data: topProducts.map(p => num(p.revenue || 0)) }];
  if (metric === 'qty') return [{ name: t('Quantity', 'Quantity'), data: topProducts.map(p => num(p.qty || 0)) }];
  return [{ name: t('Profit', 'Profit'), data: topProducts.map(p => num(p.profit || 0)) }];
});

/* ---------- Late payments chart + table ---------- */
const latePaymentsColumns = [
  { title: t('Customer', 'Customer'), dataIndex: 'name', key: 'name' },
  { title: t('Invoices_Count', 'Invoices Count'), dataIndex: 'invoices_count', key: 'invoices_count', align: 'center' },
  { title: t('Outstanding_Amount', 'Outstanding Amount'), key: 'outstanding_amount', align: 'right' },
  { title: t('Max_Days_Overdue', 'Max Days Overdue'), key: 'max_days_overdue', align: 'center' },
];
const latePaymentTotals = computed(() => ({
  invoices: filteredRows.value.reduce((s, item) => s + num(item.invoices_count || 0), 0),
  outstanding: filteredRows.value.reduce((s, item) => s + num(item.outstanding_amount || 0), 0),
}));
const totalOutstanding = computed(() =>
  money(rows.value.reduce((s, item) => s + num(item.outstanding_amount || 0), 0)));
const avgDaysOverdue = computed(() => {
  if (!rows.value.length) return 0;
  return Math.round(rows.value.reduce((s, item) => s + num(item.max_days_overdue || 0), 0) / rows.value.length);
});
const latePaymentsChartOptions = computed(() => {
  if (!rows.value.length) return {};
  const cfg = questionUi.value.chart;
  const variant = String(cfg.variant || 'amount_pie');
  const topN = Number(cfg.topN || 10);
  const topCustomers = topN > 0 ? rows.value.slice(0, topN) : rows.value;

  if (variant === 'days_bar') {
    return {
      chart: { type: 'bar', toolbar: { show: true } },
      plotOptions: { bar: { horizontal: true, barHeight: '55%', endingShape: 'rounded' } },
      dataLabels: { enabled: true, formatter: v => `${Math.round(num(v))} d` },
      xaxis: { categories: topCustomers.map(c => c.name || t('Unknown', 'Unknown')) },
      colors: ['#fd7e14'],
      tooltip: { y: { formatter: v => `${Math.round(num(v))} ${t('days', 'days')}` } },
    };
  }
  if (variant === 'amount_bar') {
    const horizontal = !!cfg.horizontal;
    return {
      chart: { type: 'bar', toolbar: { show: true } },
      plotOptions: { bar: { horizontal, barHeight: '55%', columnWidth: '55%', endingShape: 'rounded' } },
      dataLabels: { enabled: false },
      xaxis: { categories: topCustomers.map(c => c.name || t('Unknown', 'Unknown')), labels: { rotate: horizontal ? 0 : -45 } },
      yaxis: { labels: { formatter: v => money(v) } },
      colors: ['#dc3545'],
      tooltip: { y: { formatter: v => money(v) } },
    };
  }
  if (variant === 'aging_bucket') {
    return {
      chart: { type: 'donut', toolbar: { show: true } },
      labels: [`< 30 ${t('days', 'days')}`, `30–60 ${t('days', 'days')}`, `60–90 ${t('days', 'days')}`, `90+ ${t('days', 'days')}`],
      colors: ['#17a2b8', '#ffc107', '#fd7e14', '#dc3545'],
      legend: { position: 'bottom', fontSize: '12px' },
      dataLabels: { enabled: true, formatter: val => `${val.toFixed(1)}%` },
    };
  }
  return {
    chart: { type: cfg.type || 'pie', toolbar: { show: true } },
    labels: topCustomers.map(c => c.name || t('Unknown', 'Unknown')),
    colors: ['#dc3545', '#fd7e14', '#ffc107', '#20c997', '#0dcaf0', '#6610f2', '#6f42c1', '#e83e8c', '#f8d7da', '#d1ecf1'],
    legend: { position: 'bottom', fontSize: '12px' },
    dataLabels: { enabled: true, formatter: val => `${val.toFixed(1)}%` },
    tooltip: { y: { formatter: val => money(val) } },
  };
});
const latePaymentsChartSeries = computed(() => {
  if (!rows.value.length) return [];
  const cfg = questionUi.value.chart;
  const variant = String(cfg.variant || 'amount_pie');
  const topN = Number(cfg.topN || 10);
  const topCustomers = topN > 0 ? rows.value.slice(0, topN) : rows.value;

  if (variant === 'days_bar') return [{ name: t('Days_Overdue', 'Days Overdue'), data: topCustomers.map(c => num(c.max_days_overdue || 0)) }];
  if (variant === 'amount_bar') return [{ name: t('Outstanding', 'Outstanding'), data: topCustomers.map(c => num(c.outstanding_amount || 0)) }];
  if (variant === 'aging_bucket') {
    const buckets = [0, 0, 0, 0];
    rows.value.forEach(c => {
      const d = num(c.max_days_overdue || 0);
      if (d < 30) buckets[0] += 1;
      else if (d < 60) buckets[1] += 1;
      else if (d < 90) buckets[2] += 1;
      else buckets[3] += 1;
    });
    return buckets;
  }
  return topCustomers.map(c => num(c.outstanding_amount || 0));
});

/* ---------- Data loading ---------- */
async function loadQuestions() {
  try {
    const data = await http.get('report-questions');
    questions.value = data.questions || [];
    warehouses.value = data.warehouses || [];
  } catch (e) {
    message.error(t('Failed_to_load_questions', 'Failed to load questions'));
  }
}
async function runReport() {
  if (!selectedQuestionId.value) {
    message.warning(t('Please_select_a_question', 'Please select a question'));
    return;
  }
  isLoading.value = true;
  reportResult.value = null;
  searchTerm.value = '';
  loadingStep.value = 1;
  loadingInterval = setInterval(() => {
    if (loadingStep.value < 3) loadingStep.value++;
  }, 800);
  try {
    const data = await http.post('report-questions/run', {
      question_id: selectedQuestionId.value,
      warehouse_id: warehouseId.value || null,
    });
    reportResult.value = data;
  } catch (e) {
    message.error(e?.data?.message || t('Failed_to_run_report', 'Failed to run report'));
  } finally {
    if (loadingInterval) {
      clearInterval(loadingInterval);
      loadingInterval = null;
    }
    isLoading.value = false;
    loadingStep.value = 1;
  }
}

/* ---------- Exports (legacy verbatim) ---------- */
function todayStamp() {
  const d = new Date();
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function exportToPDF(reportType) {
  try {
    const rr = reportResult.value;
    const pdf = new jsPDF('p', 'pt', 'a4');
    const pageWidth = pdf.internal.pageSize.getWidth();
    let yPos = 40;

    pdf.setFontSize(18);
    pdf.setFont('helvetica', 'bold');
    pdf.text(rr.question.title || 'AI Report', pageWidth / 2, yPos, { align: 'center' });
    yPos += 30;

    pdf.setFontSize(10);
    pdf.setFont('helvetica', 'normal');
    pdf.text(`Generated: ${getCurrentTime()}`, 40, yPos);
    yPos += 20;

    if (rr.filters.date_from && rr.filters.date_to) {
      pdf.text(`Date Range: ${rr.filters.date_from} - ${rr.filters.date_to}`, 40, yPos);
      yPos += 20;
    }
    if (rr.insights) {
      yPos += 10;
      pdf.setFont('helvetica', 'bold');
      pdf.setFontSize(12);
      pdf.text('AI Insights:', 40, yPos);
      yPos += 20;
      pdf.setFont('helvetica', 'normal');
      pdf.setFontSize(10);
      const insightsLines = pdf.splitTextToSize(rr.insights, pageWidth - 80);
      pdf.text(insightsLines, 40, yPos);
      yPos += insightsLines.length * 15 + 20;
    }

    if (rr.data && Array.isArray(rr.data)) {
      if (reportType === 'products') {
        autoTable(pdf, {
          head: [['Product', 'Quantity', 'Revenue', 'Cost', 'Profit', 'Margin %']],
          body: rr.data.map(item => [
            String(productDisplayName(item)).substring(0, 30),
            String(num(item.qty || 0)),
            money(item.revenue || 0),
            money(item.cost || 0),
            money(item.profit || 0),
            `${num(item.margin_percent || 0)}%`,
          ]),
          startY: yPos,
          styles: { fontSize: 8 },
          headStyles: { fillColor: [102, 126, 234] },
        });
      } else if (reportType === 'late_payments') {
        autoTable(pdf, {
          head: [['Customer', 'Invoices', 'Outstanding', 'Days Overdue']],
          body: rr.data.map(item => [
            String(item.name || '').substring(0, 30),
            String(num(item.invoices_count || 0)),
            money(item.outstanding_amount || 0),
            `${num(item.max_days_overdue || 0)} days`,
          ]),
          startY: yPos,
          styles: { fontSize: 8 },
          headStyles: { fillColor: [220, 53, 69] },
        });
      }
    } else if (reportType === 'daily_sales' && rr.data) {
      const data = rr.data;
      autoTable(pdf, {
        body: [
          ['Metric', 'Value'],
          ['Transactions', String(num(data.transactions || 0))],
          ['Revenue', money(data.revenue || 0)],
          ['Tax', money(data.tax || 0)],
          ['Discount', money(data.discount || 0)],
          ['Profit', money(data.profit || 0)],
        ],
        startY: yPos,
        styles: { fontSize: 10 },
        headStyles: { fillColor: [102, 126, 234] },
      });
    }

    pdf.save(`AI_Report_${reportType}_${todayStamp()}.pdf`);
    message.success(t('PDF_exported_successfully', 'PDF exported successfully'));
  } catch (e) {
    message.error(t('Failed_to_export_PDF', 'Failed to export PDF'));
  }
}
function exportToExcel(reportType) {
  try {
    const rr = reportResult.value;
    let csvContent = '';
    csvContent += `${rr.question.title || t('AI_Report', 'AI Report')}\n`;
    csvContent += `${t('Generated', 'Generated')}: ${getCurrentTime()}\n`;
    if (rr.filters.date_from && rr.filters.date_to) {
      csvContent += `${t('DateRange', 'Date Range')}: ${rr.filters.date_from} - ${rr.filters.date_to}\n`;
    }
    csvContent += '\n';
    if (rr.data && Array.isArray(rr.data)) {
      if (reportType === 'products') {
        csvContent += `${[t('Product', 'Product'), t('Quantity', 'Quantity'), t('Revenue', 'Revenue'), t('Cost', 'Cost'), t('Profit', 'Profit'), t('Margin_Percent', 'Margin %')].join(',')}\n`;
        rr.data.forEach(item => {
          csvContent += `"${productDisplayName(item)}",${num(item.qty || 0)},${num(item.revenue || 0)},${num(item.cost || 0)},${num(item.profit || 0)},${num(item.margin_percent || 0)}\n`;
        });
      } else if (reportType === 'late_payments') {
        csvContent += `${[t('Customer', 'Customer'), t('Invoices_Count', 'Invoices Count'), t('Outstanding_Amount', 'Outstanding Amount'), t('Max_Days_Overdue', 'Max Days Overdue')].join(',')}\n`;
        rr.data.forEach(item => {
          csvContent += `"${item.name || ''}",${num(item.invoices_count || 0)},${num(item.outstanding_amount || 0)},${num(item.max_days_overdue || 0)}\n`;
        });
      }
    } else if (reportType === 'daily_sales' && rr.data) {
      const data = rr.data;
      csvContent += `${t('Metric', 'Metric')},${t('Value', 'Value')}\n`;
      csvContent += `${t('Transactions', 'Transactions')},${num(data.transactions || 0)}\n`;
      csvContent += `${t('Revenue', 'Revenue')},${num(data.revenue || 0)}\n`;
      csvContent += `${t('Tax', 'Tax')},${num(data.tax || 0)}\n`;
      csvContent += `${t('Discount', 'Discount')},${num(data.discount || 0)}\n`;
      csvContent += `${t('Profit', 'Profit')},${num(data.profit || 0)}\n`;
    }
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `AI_Report_${reportType}_${todayStamp()}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
    message.success(t('Excel_file_exported_successfully', 'Excel file exported successfully'));
  } catch (e) {
    message.error(t('Failed_to_export_Excel_file', 'Failed to export Excel file'));
  }
}
function generatePrintTable(reportType) {
  const rr = reportResult.value;
  if (!rr.data) return '';
  if (reportType === 'products') {
    let table = `<table><thead><tr><th>${t('Product', 'Product')}</th><th>${t('Quantity', 'Quantity')}</th><th>${t('Revenue', 'Revenue')}</th><th>${t('Cost', 'Cost')}</th><th>${t('Profit', 'Profit')}</th><th>${t('Margin_Percent', 'Margin %')}</th></tr></thead><tbody>`;
    rr.data.forEach(item => {
      table += `<tr><td>${productDisplayName(item)}</td><td>${num(item.qty || 0)}</td><td>${money(item.revenue || 0)}</td><td>${money(item.cost || 0)}</td><td>${money(item.profit || 0)}</td><td>${num(item.margin_percent || 0)}%</td></tr>`;
    });
    return table + '</tbody></table>';
  }
  if (reportType === 'late_payments') {
    let table = `<table><thead><tr><th>${t('Customer', 'Customer')}</th><th>${t('Invoices_Count', 'Invoices Count')}</th><th>${t('Outstanding_Amount', 'Outstanding Amount')}</th><th>${t('Max_Days_Overdue', 'Max Days Overdue')}</th></tr></thead><tbody>`;
    rr.data.forEach(item => {
      table += `<tr><td>${item.name || ''}</td><td>${num(item.invoices_count || 0)}</td><td>${money(item.outstanding_amount || 0)}</td><td>${num(item.max_days_overdue || 0)} ${t('days', 'days')}</td></tr>`;
    });
    return table + '</tbody></table>';
  }
  if (reportType === 'daily_sales') {
    const data = rr.data;
    return `<table><tr><th>${t('Metric', 'Metric')}</th><th>${t('Value', 'Value')}</th></tr><tr><td>${t('Transactions', 'Transactions')}</td><td>${num(data.transactions || 0)}</td></tr><tr><td>${t('Revenue', 'Revenue')}</td><td>${money(data.revenue || 0)}</td></tr><tr><td>${t('Tax', 'Tax')}</td><td>${money(data.tax || 0)}</td></tr><tr><td>${t('Discount', 'Discount')}</td><td>${money(data.discount || 0)}</td></tr><tr><td>${t('Profit', 'Profit')}</td><td>${money(data.profit || 0)}</td></tr></table>`;
  }
  return '';
}
function printReport(reportType) {
  try {
    const rr = reportResult.value;
    const printWindow = window.open('', '_blank');
    const reportTitle = rr.question.title || t('AI_Report', 'AI Report');
    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>${reportTitle}</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 20px; }
          h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
          .header-info { margin: 20px 0; color: #666; }
          .insights { background: #f8f9fa; padding: 15px; border-left: 4px solid #667eea; margin: 20px 0; }
          table { width: 100%; border-collapse: collapse; margin: 20px 0; }
          th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
          th { background-color: #667eea; color: white; }
          tr:nth-child(even) { background-color: #f2f2f2; }
          @media print { body { padding: 0; } }
        </style>
      </head>
      <body>
        <h1>${reportTitle}</h1>
        <div class="header-info">
          <p><strong>${t('Generated', 'Generated')}:</strong> ${getCurrentTime()}</p>
          ${rr.filters.date_from && rr.filters.date_to
            ? `<p><strong>${t('DateRange', 'Date Range')}:</strong> ${rr.filters.date_from} - ${rr.filters.date_to}</p>` : ''}
        </div>
        ${rr.insights ? `<div class="insights"><h3>${t('AI_Insights', 'AI Insights')}</h3><p>${rr.insights}</p></div>` : ''}
        ${generatePrintTable(reportType)}
      </body>
      </html>
    `);
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 250);
  } catch (e) {
    message.error(t('Failed_to_print_report', 'Failed to print report'));
  }
}

loadQuestions();
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
