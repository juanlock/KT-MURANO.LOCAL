<template>
  <div class="page">
    <PageHeader :title="$t('User_report')" :breadcrumb="[$t('Reports'), $t('User_report')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <a-tabs v-model:activeKey="tab">
      <a-tab-pane key="sales" :tab="$t('Sales')">
        <ReportTab
          endpoint="report/get_sales_by_user" rows-key="sales" row-key="sale_id"
          :columns="salesColumns" :extra-params="{ id }" :title="titleFor('Sales')" :sums="MONEY_SUMS"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/sales/${record.sale_id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('sale', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="quotations" :tab="$t('Quotations')">
        <ReportTab
          endpoint="report/get_quotations_by_user" rows-key="quotations" row-key="id"
          :columns="quotationColumns" :extra-params="{ id }" :title="titleFor('Quotations')"
          :sums="[{ key: 'GrandTotal', money: true }]"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/quotations/${record.id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('quotation', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="purchases" :tab="$t('Purchases')">
        <ReportTab
          endpoint="report/get_purchases_by_user" rows-key="purchases" row-key="purchase_id"
          :columns="purchaseColumns" :extra-params="{ id }" :title="titleFor('Purchases')" :sums="MONEY_SUMS"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/purchases/${record.purchase_id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('purchase', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="sales_return" :tab="$t('SalesReturn')">
        <ReportTab
          endpoint="report/get_sales_return_by_user" rows-key="sales_return" row-key="return_sale_id"
          :columns="saleReturnColumns" :extra-params="{ id }" :title="titleFor('SalesReturn')" :sums="MONEY_SUMS"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/sale-returns/${record.return_sale_id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('sale_return', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="purchases_return" :tab="$t('PurchasesReturn')">
        <ReportTab
          endpoint="report/get_purchase_return_by_user" rows-key="purchases_return" row-key="return_purchase_id"
          :columns="purchaseReturnColumns" :extra-params="{ id }" :title="titleFor('PurchasesReturn')" :sums="MONEY_SUMS"
        >
          <!-- Legacy's template for THIS tab was missing its fallback branch,
               so username / supplier / warehouse / the three money columns all
               rendered blank on screen while the footer totals were correct.
               Rendering them is a fix, not a divergence in intent. -->
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/purchase-returns/${record.return_purchase_id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('sale_return', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="transfers" :tab="$t('StockTransfers')">
        <ReportTab
          endpoint="report/get_transfer_by_user" rows-key="transfers" row-key="Ref"
          :columns="transferColumns" :extra-params="{ id }" :title="titleFor('StockTransfers')"
          :sums="[{ key: 'items' }, { key: 'GrandTotal', money: true }]"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('transfer', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="adjustments" :tab="$t('Adjustment')">
        <ReportTab
          endpoint="report/get_adjustment_by_user" rows-key="adjustments" row-key="Ref"
          :columns="adjustmentColumns" :extra-params="{ id }" :title="titleFor('Adjustment')"
          :sums="[{ key: 'items' }]"
        />
      </a-tab-pane>
    </a-tabs>
  </div>
</template>

<script setup>
/**
 * User drill-down report — legacy detail_user_report.vue (2,023 lines of seven
 * near-identical tables). Seven server-paginated tabs on
 * report/get_{sales|quotations|purchases|sales_return|purchase_return|transfer|
 * adjustment}_by_user, each taking page/limit/search/id.
 *
 * Legacy fetched all seven up-front and hid the entire page behind a spinner
 * that only the PURCHASES call cleared — one slow endpoint blanked the report.
 * Here each tab loads independently. Two legacy defects also dropped: paging
 * the Purchases table refetched Sales, and the Purchase Return tab rendered six
 * of its nine columns blank (see the note on that tab).
 */
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import ReportTab from '../../components/ReportTab.vue';
import StatusTag from '../../components/StatusTag.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { TAG_KEYS, documentTag } from '../../lib/statusTags';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { money } = useFormat();
const route = useRoute();
const id = route.params.id;

const tab = ref('sales');
const titleFor = key => `${t('Reports')} / ${t('User_report')} / ${t(key)}`;

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due'];
const MONEY_SUMS = [
  { key: 'GrandTotal', money: true },
  { key: 'paid_amount', money: true },
  { key: 'due', money: true },
];

const userCol = () => ({ title: t('username'), dataIndex: 'username', key: 'username' });
const refCol = () => ({ title: t('Reference'), dataIndex: 'Ref', key: 'Ref' });
const whCol = () => ({ title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' });
const moneyCols = () => [
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
];
const statusCol = () => ({ title: t('Status'), dataIndex: 'statut', key: 'statut' });
const payCol = () => ({ title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' });

const salesColumns = computed(() => [
  userCol(), refCol(),
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  whCol(), statusCol(), ...moneyCols(), payCol(),
  { title: t('Shipping_status'), dataIndex: 'shipping_status', key: 'shipping_status' },
]);

const quotationColumns = computed(() => [
  userCol(),
  { title: t('date'), dataIndex: 'date', key: 'date' },
  refCol(),
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  whCol(),
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right', exportValue: r => money(r.GrandTotal) },
  statusCol(),
]);

const purchaseColumns = computed(() => [
  userCol(), refCol(),
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  whCol(), statusCol(), ...moneyCols(), payCol(),
]);

const saleReturnColumns = computed(() => [
  userCol(), refCol(),
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  whCol(), statusCol(), ...moneyCols(), payCol(),
]);

const purchaseReturnColumns = computed(() => [
  userCol(), refCol(),
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  whCol(), statusCol(), ...moneyCols(), payCol(),
]);

const transferColumns = computed(() => [
  userCol(),
  { title: t('date'), dataIndex: 'date', key: 'date' },
  refCol(),
  { title: t('FromWarehouse'), dataIndex: 'from_warehouse', key: 'from_warehouse' },
  { title: t('ToWarehouse'), dataIndex: 'to_warehouse', key: 'to_warehouse' },
  { title: t('Items'), dataIndex: 'items', key: 'items', align: 'right' },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right', exportValue: r => money(r.GrandTotal) },
  statusCol(),
]);

const adjustmentColumns = computed(() => [
  userCol(),
  { title: t('date'), dataIndex: 'date', key: 'date' },
  refCol(), whCol(),
  { title: t('TotalProducts'), dataIndex: 'items', key: 'items', align: 'right' },
]);
</script>
