<template>
  <div class="page">
    <PageHeader :title="product.name || $t('stock_report')" :breadcrumb="[$t('Reports'), $t('stock_report')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-if="product.type === 'is_single'" :xs="24" :lg="10">
        <a-card size="small" :title="$t('Quantity')">
          <a-table
            :columns="warehouseQtyColumns" :data-source="product.CountQTY || []"
            :pagination="false" size="small" :row-key="(r, i) => i"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'qte'">{{ number(record.qte, 2) }} {{ product.unit }}</template>
            </template>
            <template v-if="(product.CountQTY || []).length" #summary>
              <a-table-summary-row>
                <a-table-summary-cell :index="0"><b>{{ $t('Total') }}</b></a-table-summary-cell>
                <a-table-summary-cell :index="1" align="right">
                  <b>{{ number(warehouseQtyTotal, 2) }} {{ product.unit }}</b>
                </a-table-summary-cell>
              </a-table-summary-row>
            </template>
          </a-table>
        </a-card>
      </a-col>
      <a-col v-if="product.is_variant === 'yes'" :xs="24" :lg="14">
        <a-card size="small" :title="$t('Variant')">
          <a-table
            :columns="variantQtyColumns" :data-source="product.CountQTY_variants || []"
            :pagination="false" size="small" :row-key="(r, i) => i"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'qte'">{{ number(record.qte, 2) }} {{ product.unit }}</template>
            </template>
            <template v-if="(product.CountQTY_variants || []).length" #summary>
              <a-table-summary-row>
                <a-table-summary-cell :index="0"><b>{{ $t('Total') }}</b></a-table-summary-cell>
                <a-table-summary-cell :index="1" />
                <a-table-summary-cell :index="2" align="right">
                  <b>{{ number(variantQtyTotal, 2) }} {{ product.unit }}</b>
                </a-table-summary-cell>
              </a-table-summary-row>
            </template>
          </a-table>
        </a-card>
      </a-col>
    </a-row>

    <a-tabs v-model:activeKey="tab">
      <a-tab-pane v-for="pane in panes" :key="pane.key" :tab="$t(pane.label)">
        <ReportTab
          :endpoint="pane.endpoint" :rows-key="pane.rowsKey" row-key="Ref"
          :columns="pane.columns" :extra-params="{ id }" :title="titleFor(pane.label)"
          :sums="pane.sums"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref' && pane.link">
              <router-link :to="pane.link(record)">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
          </template>
        </ReportTab>
      </a-tab-pane>
    </a-tabs>
  </div>
</template>

<script setup>
/**
 * Stock (per-product) drill-down report — legacy detail_stock_report.vue.
 * GET get_product_detail/{id} drives the heading and the two warehouse-quantity
 * tables; seven server-paginated tabs hit
 * report/get_{sales|quotations|purchases|sales_return|purchase_return|transfer|
 * adjustment}_by_product with page/limit/search/id.
 *
 * The tabs are data-driven because legacy repeated the same table markup seven
 * times, which is how `PageChangePurchases` ended up calling `Get_Sales` — the
 * Purchases pager silently refetched Sales. That defect is not reproduced, nor
 * is the spinner that hid the whole page until the Purchases call returned.
 *
 * Totals under SubTotal cover the current page only, as in legacy (its
 * headerField summed the loaded rows, not the full result set).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import ReportTab from '../../components/ReportTab.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money, number } = useFormat();
const route = useRoute();
const id = route.params.id;

const tab = ref('sales');
const product = ref({});

const titleFor = key => `${t('Reports')} / ${t('stock_report')} / ${t(key)}`;

const warehouseQtyColumns = computed(() => [
  { title: t('warehouse'), dataIndex: 'mag', key: 'mag' },
  { title: t('Quantity'), dataIndex: 'qte', key: 'qte', align: 'right' },
]);
const variantQtyColumns = computed(() => [
  { title: t('warehouse'), dataIndex: 'mag', key: 'mag' },
  { title: t('Variant'), dataIndex: 'variant', key: 'variant' },
  { title: t('Quantity'), dataIndex: 'qte', key: 'qte', align: 'right' },
]);

const sumQte = rows => (rows || []).reduce((s, r) => s + (Number(r.qte) || 0), 0);
const warehouseQtyTotal = computed(() => sumQte(product.value.CountQTY));
const variantQtyTotal = computed(() => sumQte(product.value.CountQTY_variants));

const TOTAL_SUM = [{ key: 'quantity' }, { key: 'total', money: true }];

/** date · Ref · product · party · warehouse · qty · subtotal */
function docColumns(partyTitle, partyField) {
  return [
    { title: t('date'), dataIndex: 'date', key: 'date' },
    { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
    { title: t('product_name'), dataIndex: 'product_name', key: 'product_name' },
    { title: t(partyTitle), dataIndex: partyField, key: partyField },
    { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
    { title: t('Quantity'), dataIndex: 'quantity', key: 'quantity', align: 'right' },
    { title: t('SubTotal'), dataIndex: 'total', key: 'total', align: 'right', exportValue: r => money(r.total) },
  ];
}

const panes = computed(() => [
  {
    key: 'sales', label: 'Sales', endpoint: 'report/get_sales_by_product', rowsKey: 'sales',
    columns: docColumns('Customer', 'client_name'), sums: TOTAL_SUM,
    link: r => `/sales/${r.sale_id}`,
  },
  {
    key: 'quotations', label: 'Quotations', endpoint: 'report/get_quotations_by_product', rowsKey: 'quotations',
    columns: docColumns('Customer', 'client_name'), sums: TOTAL_SUM,
    link: r => `/quotations/${r.quotation_id}`,
  },
  {
    key: 'purchases', label: 'Purchases', endpoint: 'report/get_purchases_by_product', rowsKey: 'purchases',
    columns: docColumns('Supplier', 'provider_name'), sums: TOTAL_SUM,
    link: r => `/purchases/${r.purchase_id}`,
  },
  {
    key: 'sales_return', label: 'SalesReturn', endpoint: 'report/get_sales_return_by_product', rowsKey: 'sales_return',
    columns: docColumns('Customer', 'client_name'), sums: TOTAL_SUM,
    link: r => `/sale-returns/${r.return_sale_id}`,
  },
  {
    key: 'purchases_return', label: 'PurchasesReturn', endpoint: 'report/get_purchase_return_by_product', rowsKey: 'purchases_return',
    columns: docColumns('Supplier', 'provider_name'), sums: TOTAL_SUM,
    link: r => `/purchase-returns/${r.return_purchase_id}`,
  },
  {
    key: 'transfers', label: 'StockTransfers', endpoint: 'report/get_transfer_by_product', rowsKey: 'transfers',
    sums: [], link: null,
    columns: [
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('product_name'), dataIndex: 'product_name', key: 'product_name' },
      { title: t('FromWarehouse'), dataIndex: 'from_warehouse', key: 'from_warehouse' },
      { title: t('ToWarehouse'), dataIndex: 'to_warehouse', key: 'to_warehouse' },
    ],
  },
  {
    key: 'adjustments', label: 'Adjustment', endpoint: 'report/get_adjustment_by_product', rowsKey: 'adjustments',
    sums: [], link: null,
    columns: [
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('product_name'), dataIndex: 'product_name', key: 'product_name' },
      { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
    ],
  },
]);

onMounted(async () => {
  try {
    product.value = await http.get(`get_product_detail/${id}`) || {};
  } catch (e) { /* tabs still work without the header tables */ }
});
</script>
