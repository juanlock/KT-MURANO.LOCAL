<template>
  <ReportPage
    :title="$t('SuppliersReport')"
    :breadcrumb="[$t('Reports'), $t('SuppliersReport')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/provider"
    export-rows-key="report"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <!-- KPI tiles + top-10 chart (whole-set aggregates from the payload). -->
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
                <div class="kpi-value" :style="k.style">
                  <a-spin v-if="crud.loading.value" size="small" />
                  <template v-else>{{ k.value }}</template>
                </div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>
      <a-card size="small" class="chart-card" style="margin-bottom: 16px" :title="$t('Top_10_Suppliers_By_Purchase_Amount')">
        <ReportChart
          :data="chartData"
          :fields="[{ key: 'amount', label: t('TotalAmount') }]"
          :title="$t('SuppliersReport')"
          type="bar"
          x-key="name"
          :format="money"
          :height="300"
        />
      </a-card>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
      <template v-else-if="column.key === 'due'">
        <strong :style="{ color: Number(record.due) > 0 ? '#ff4d4f' : undefined }">
          {{ money(record.due) }}
        </strong>
      </template>
      <template v-else-if="column.key === 'actions'">
        <a-tooltip :title="$t('Reports')">
          <a-button type="text" size="small" @click="$router.push(`/reports/suppliers/${record.id}`)">
            <template #icon><EyeOutlined /></template>
          </a-button>
        </a-tooltip>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  EyeOutlined, ShopOutlined, ShoppingOutlined, DollarOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { money } = useFormat();

const MONEY_KEYS = ['total_amount', 'total_paid'];

// Payload: { report, totalRows }
const crud = useCrudTable('report/provider', {
  rowsKey: 'report',
  sortField: 'id',
  sortType: 'desc',
});

// Summary cards + chart — from the payload's whole-set aggregates.
const stats = computed(() => crud.payload.value?.stats || {});
const chartData = computed(() => crud.payload.value?.chart || []);

const kpiTiles = computed(() => {
  const n = k => Number(stats.value[k]) || 0;
  return [
    { label: t('Suppliers'), value: n('suppliers'), icon: ShopOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Purchases'), value: n('purchases'), icon: ShoppingOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('TotalAmount'), value: money(n('total')), icon: DollarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    {
      label: t('Total_Purchase_Due'), value: money(n('due')), icon: ExclamationCircleOutlined,
      color: '#f43f5e', tint: 'rgba(244, 63, 94, 0.12)',
      style: n('due') > 0 ? { color: '#f43f5e' } : undefined,
    },
  ];
});

const columns = computed(() => [
  { title: t('SupplierName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Purchases'), dataIndex: 'total_purchase', key: 'total_purchase', sorter: true, align: 'right', sum: true },
  { title: t('TotalAmount'), key: 'total_amount', dataIndex: 'total_amount', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total_amount) },
  { title: t('Paid'), key: 'total_paid', dataIndex: 'total_paid', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total_paid) },
  { title: t('Total_Purchase_Due'), key: 'due', dataIndex: 'due', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.due) },
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
