<template>
  <div class="page">
    <PageHeader :title="$t('Warehouse_report')" :breadcrumb="[$t('Reports'), $t('Warehouse_report')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <a-select
          v-model:value="warehouseId"
          style="width: 240px"
          allow-clear
          show-search
          option-filter-prop="label"
          :placeholder="$t('warehouse')"
          :options="warehouseOptions"
          @change="onWarehouseChange"
        />
      </a-space>
    </a-card>

    <!-- Overview totals -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :md="12">
        <a-card size="small">
          <div class="wh-title">{{ $t('TotalSales') }}</div>
          <div class="wh-value">
            <a-spin v-if="overviewLoading" size="small" />
            <template v-else>{{ money(totals.sales) }}</template>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-card size="small">
          <div class="wh-title">{{ $t('TotalPurchases') }}</div>
          <div class="wh-value">
            <a-spin v-if="overviewLoading" size="small" />
            <template v-else>{{ money(totals.purchases) }}</template>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- One tab per document family; each loads on first activation. -->
    <a-tabs v-model:activeKey="activeTab" @change="onTabChange">
      <a-tab-pane v-for="tab in TABS" :key="tab.key" :tab="$t(tab.labelKey)">
        <DataTable :crud="cruds[tab.key]" :columns="tabColumns[tab.key]">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="column.key === 'statut'">
              <a-tag :color="docStatusColor(record.statut)">{{ record.statut }}</a-tag>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <a-tab-pane key="count_stock" :tab="$t('CountStock')">
        <ReportChart
          :data="countStock"
          :fields="countFields"
          :title="$t('CountStock')"
          type="bar"
          x-key="name"
        />
      </a-tab-pane>
    </a-tabs>
  </div>
</template>

<script setup>
/**
 * Multi-endpoint page (user chose "one page with tabs"):
 * - Overview: report/warehouse_report?warehouse_id → { data: {sales, purchases}, warehouses }
 * - Six table tabs, all `page/limit/warehouse_id/search` → { <key>, totalRows }
 * - Chart: report/warhouse_count_stock (TYPO IS IN THE API — keep it) →
 *   { count_labels, count_items, count_qty } (or stock_count fallback)
 * Tabs fetch lazily on first activation; changing warehouse refetches the
 * overview and every already-visited tab.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ReportChart from '../../components/ReportChart.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { docStatusColor } from '../../lib/statusColors';
import http from '../../lib/http';

const { t } = useI18n();
const { money, date } = useFormat();

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due', 'amount'];

const warehouseId = ref(undefined);
const totals = ref({ sales: 0, purchases: 0 });
const overviewLoading = ref(true);
const warehouses = ref([]);
const activeTab = ref('sales');
const visited = new Set(['sales']);

const warehouseOptions = computed(() =>
  warehouses.value.map(w => ({ value: w.id, label: w.name }))
);

const whParams = () => ({ warehouse_id: warehouseId.value || '' });

const TABS = [
  { key: 'sales', endpoint: 'report/sales_warehouse', rowsKey: 'sales', labelKey: 'Sales' },
  { key: 'purchases', endpoint: 'report/purchases_warehouse', rowsKey: 'purchases', labelKey: 'Purchases' },
  { key: 'quotations', endpoint: 'report/quotations_warehouse', rowsKey: 'quotations', labelKey: 'Quotations' },
  { key: 'returns_sale', endpoint: 'report/returns_sale_warehouse', rowsKey: 'returns_sale', labelKey: 'SalesReturn' },
  { key: 'returns_purchase', endpoint: 'report/returns_purchase_warehouse', rowsKey: 'returns_purchase', labelKey: 'PurchasesReturn' },
  { key: 'expenses', endpoint: 'report/expenses_warehouse', rowsKey: 'expenses', labelKey: 'Expenses' },
];

const cruds = {};
for (const tab of TABS) {
  cruds[tab.key] = useCrudTable(tab.endpoint, { rowsKey: tab.rowsKey, params: whParams });
}

const col = (titleKey, dataIndex, extra = {}) => ({
  title: t(titleKey), dataIndex, key: dataIndex, ...extra,
});
const moneyCol = (titleKey, dataIndex) =>
  ({ title: t(titleKey), dataIndex, key: dataIndex, align: 'right', sum: 'money', exportValue: r => money(r[dataIndex]) });

const tabColumns = computed(() => ({
  sales: [
    col('Reference', 'Ref'), col('Customer', 'client_name'), col('warehouse', 'warehouse_name'),
    moneyCol('Total', 'GrandTotal'), moneyCol('Paid', 'paid_amount'), moneyCol('Due', 'due'),
  ],
  purchases: [
    col('date', 'date'), col('Reference', 'Ref'), col('Supplier', 'provider_name'),
    col('warehouse', 'warehouse_name'), moneyCol('Total', 'GrandTotal'), moneyCol('Paid', 'paid_amount'),
  ],
  quotations: [
    col('date', 'date'), col('Reference', 'Ref'), col('Customer', 'client_name'),
    col('warehouse', 'warehouse_name'), moneyCol('Total', 'GrandTotal'),
    { title: t('Status'), dataIndex: 'statut', key: 'statut', exportValue: r => r.statut },
  ],
  returns_sale: [
    col('Reference', 'Ref'), col('Customer', 'client_name'), col('Sale', 'sale_ref'),
    col('warehouse', 'warehouse_name'), moneyCol('Total', 'GrandTotal'), moneyCol('Paid', 'paid_amount'),
  ],
  returns_purchase: [
    col('Reference', 'Ref'), col('Supplier', 'provider_name'), col('Purchase', 'purchase_ref'),
    col('warehouse', 'warehouse_name'), moneyCol('Total', 'GrandTotal'), moneyCol('Paid', 'paid_amount'),
  ],
  expenses: [
    col('date', 'date'), col('Reference', 'Ref'), col('warehouse', 'warehouse_name'),
    col('Details', 'details'), moneyCol('Amount', 'amount'),
  ],
}));

/* ------------------------------------------------------------- overview */
async function loadOverview() {
  overviewLoading.value = true;
  try {
    const data = await http.get('report/warehouse_report', whParams());
    totals.value = {
      sales: Number(data?.data?.sales || 0),
      purchases: Number(data?.data?.purchases || 0),
    };
    warehouses.value = data?.warehouses || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    overviewLoading.value = false;
  }
}

/* ----------------------------------------------------------- count stock */
const countStock = ref([]);
const countFields = computed(() => [
  { key: 'items', label: t('Products') },
  { key: 'qty', label: t('Quantity') },
]);
let countLoaded = false;

async function loadCountStock() {
  if (countLoaded) return;
  countLoaded = true;
  try {
    const d = await http.get('report/warhouse_count_stock'); // sic — API path typo
    const labels = d.count_labels?.length ? d.count_labels : (d.stock_count || []).map(x => x.name);
    const items = d.count_items?.length ? d.count_items : (d.stock_count || []).map(x => Number(x?.value || 0));
    const qty = d.count_qty?.length ? d.count_qty : (d.stock_count || []).map(x => Number(x?.value1 || 0));
    countStock.value = labels.map((name, i) => ({ name, items: items[i] ?? 0, qty: qty[i] ?? 0 }));
  } catch (e) {
    // Chart shows its empty state.
  }
}

/* --------------------------------------------------------------- events */
function onTabChange(key) {
  if (key === 'count_stock') return loadCountStock();
  if (!visited.has(key)) {
    visited.add(key);
    cruds[key].fetchRows();
  }
}

function onWarehouseChange() {
  loadOverview();
  // Refetch every tab the user has already opened; unvisited ones will fetch
  // with the new warehouse when first activated.
  visited.forEach(key => cruds[key].reload());
}

onMounted(() => {
  loadOverview();
  cruds.sales.fetchRows();
});
</script>

<style scoped>
/* Mirrors the a-statistic look so the inline spinner can replace the value. */
.wh-title {
  color: rgba(0, 0, 0, 0.45);
  font-size: 14px;
  margin-bottom: 4px;
}
.wh-value {
  font-size: 24px;
  line-height: 32px;
}
</style>
