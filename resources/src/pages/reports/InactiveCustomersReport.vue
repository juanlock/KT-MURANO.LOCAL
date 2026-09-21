<template>
  <ReportPage
    :title="$t('Inactive_Customers_Report')"
    :breadcrumb="[$t('Reports'), $t('Inactive_Customers_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/inactive_customers"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <a-select v-model:value="period" style="width: 200px" :options="periodOptions" @change="crud.reload()" />
    </template>

    <!-- KPI tiles + longest-inactive chart (whole-set aggregates). -->
    <template #chart>
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpiTiles" :key="k.label" :xs="12" :sm="12" :md="6">
          <a-card size="small" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
                <component :is="k.icon" />
              </div>
              <div class="kpi-text">
                <div class="kpi-label">{{ k.label }}</div>
                <div class="kpi-value">
                  <a-spin v-if="crud.loading.value" size="small" />
                  <template v-else>{{ k.value }}</template>
                </div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>
      <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="$t('Longest_Inactive_Customers')">
        <ReportChart
          :data="chartData"
          :fields="[{ key: 'days', label: t('DaysInactive') }]"
          :title="$t('Inactive_Customers_Report')"
          type="bar"
          x-key="name"
          :height="300"
        />
      </a-card>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'total_sales'">{{ money(record.total_sales) }}</template>
      <template v-else-if="column.key === 'last_sale_at'">
        {{ record.last_sale_at ? date(record.last_sale_at) : '—' }}
      </template>
      <template v-else-if="column.key === 'days_inactive'">
        <!-- 99999 is the backend's "never purchased" sorting sentinel. -->
        <a-tag v-if="isNever(record)" color="default">—</a-tag>
        <a-tag v-else :color="Number(record.days_inactive) >= 180 ? 'error' : 'warning'">
          {{ record.days_inactive }}
        </a-tag>
      </template>
      <template v-else-if="column.key === 'actions'">
        <a-tooltip :title="$t('Reports')">
          <a-button type="text" size="small" @click="$router.push(`/reports/customers/${record.id}`)">
            <template #icon><EyeOutlined /></template>
          </a-button>
        </a-tooltip>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  EyeOutlined, UserDeleteOutlined, StopOutlined, FieldTimeOutlined, HourglassOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { money, date } = useFormat();

const period = ref(90);
const filterParams = () => ({ period: period.value });

const crud = useCrudTable('report/inactive_customers', {
  rowsKey: 'report',
  sortField: 'days_inactive',
  sortType: 'desc',
  params: filterParams,
});

const periodOptions = computed(() =>
  [30, 60, 90, 180, 365].map(d => ({ value: d, label: `${d} ${t('Days')}` }))
);

// "Never purchased" rows carry no last sale; their days_inactive is the
// backend's 99999 sorting sentinel, shown as "—".
const isNever = r => !r.last_sale_at || Number(r.days_inactive) >= 99999;

// Summary cards + chart — from the payload's whole-set aggregates.
const stats = computed(() => crud.payload.value?.stats || {});
const chartData = computed(() => crud.payload.value?.chart || []);

const kpiTiles = computed(() => {
  const n = k => Number(stats.value[k]) || 0;
  return [
    { label: t('Inactive_Customers_Report'), value: n('inactive'), icon: UserDeleteOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
    { label: t('Never_Purchased'), value: n('never'), icon: StopOutlined, color: '#f43f5e', tint: 'rgba(244, 63, 94, 0.12)' },
    { label: t('Avg_Days_Inactive'), value: n('avg_days'), icon: FieldTimeOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Longest_Inactive'), value: `${n('max_days')} ${t('Days')}`, icon: HourglassOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
  ];
});

const columns = computed(() => [
  { title: t('CustomerName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('TotalSales'), key: 'total_sales', dataIndex: 'total_sales', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total_sales) },
  {
    title: t('LastPurchase'),
    key: 'last_sale_at',
    dataIndex: 'last_sale_at',
    sorter: true,
    exportValue: r => (r.last_sale_at ? date(r.last_sale_at) : ''),
  },
  { title: t('DaysInactive'), key: 'days_inactive', dataIndex: 'days_inactive', sorter: true, align: 'right', exportValue: r => (isNever(r) ? '' : r.days_inactive) },
  { title: t('Action'), key: 'actions', align: 'center', width: 80, exportable: false },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* KPI tiles — shared design with the other report pages. */
.kpi-card { border-radius: 10px; }
.kpi-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: 0 0 auto;
}
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
