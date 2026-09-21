<template>
  <div class="page">
    <PageHeader :title="$t('SuppliersReport')" :breadcrumb="[$t('Reports'), $t('SuppliersReport')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in cards" :key="card.label" :xs="12" :sm="12" :md="8" :xl="8">
        <a-card :bordered="false" class="kpi-card" :loading="loading">
          <div class="kpi-inner">
            <div class="kpi-icon" :style="{ background: card.tint, color: card.color }">
              <component :is="card.icon" />
            </div>
            <div class="kpi-text">
              <div class="kpi-label">{{ card.label }}</div>
              <div class="kpi-value" :style="card.style">{{ card.value }}</div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-tabs v-model:activeKey="tab">
      <a-tab-pane key="purchases" :tab="$t('Purchases')">
        <ReportTab
          endpoint="report/provider_purchases" rows-key="purchases" row-key="id"
          :columns="purchaseColumns" :extra-params="{ id }" :title="titleFor('Purchases')"
          :sums="MONEY_SUMS"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/purchases/${record.id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('purchase', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="returns" :tab="$t('Returns')">
        <ReportTab
          endpoint="report/provider_returns" rows-key="returns_supplier" row-key="id"
          :columns="returnColumns" :extra-params="{ id }" :title="titleFor('Returns')"
          :sums="MONEY_SUMS"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/purchase-returns/${record.id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="column.key === 'purchase_ref'">
              <!-- Blank when the return has no parent purchase, as in legacy. -->
              <router-link v-if="record.purchase_id" :to="`/purchases/${record.purchase_id}`">
                {{ record.purchase_ref }}
              </router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('purchase_return', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="payments" :tab="$t('PurchaseInvoice')">
        <ReportTab
          endpoint="report/provider_payments" rows-key="payments" row-key="Ref"
          :columns="paymentColumns" :extra-params="{ id }" :title="titleFor('PurchaseInvoice')"
          :sums="[{ key: 'montant', money: true }]"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'montant'">{{ money(record.montant) }}</template>
          </template>
        </ReportTab>
      </a-tab-pane>
    </a-tabs>
  </div>
</template>

<script setup>
/**
 * Supplier drill-down report — legacy detail_Supplier_Report.vue.
 * Summary GET report/provider/{id} → {report}; three server-paginated tabs
 * (report/provider_purchases | provider_returns | provider_payments), each
 * taking page/limit/search/id.
 *
 * Three legacy defects are NOT reproduced: paging the Purchases table called a
 * method that does not exist (`Get_Sales`), so page 2 never loaded; the Returns
 * table sent the Payments tab's page size instead of its own; and the payments
 * / returns requests swallowed every error silently. The shared ReportTab
 * handles paging for all three identically.
 *
 * Note the field-name trap kept verbatim: payments carry `purchase_Ref`
 * (capital R), returns carry `purchase_ref`.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  ShoppingCartOutlined, DollarOutlined, CheckCircleOutlined, ExclamationCircleOutlined,
  CalculatorOutlined, CreditCardOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportTab from '../../components/ReportTab.vue';
import StatusTag from '../../components/StatusTag.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';
import { TAG_KEYS, documentTag } from '../../lib/statusTags';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const route = useRoute();
const id = route.params.id;

const tab = ref('purchases');
const loading = ref(true);
const provider = ref({ total_purchase: 0, total_amount: 0, total_paid: 0, due: 0, opening_balance: 0, credit_limit: 0 });

// Accent colours + matching soft tints for the KPI icon chips (same set as the
// customer ledger).
const C = {
  blue: ['#1677ff', 'rgba(22, 119, 255, 0.12)'],
  green: ['#52c41a', 'rgba(82, 196, 26, 0.12)'],
  red: ['#ff4d4f', 'rgba(255, 77, 79, 0.12)'],
  purple: ['#722ed1', 'rgba(114, 46, 209, 0.12)'],
  teal: ['#13c2c2', 'rgba(19, 194, 194, 0.12)'],
};

const cards = computed(() => {
  const p = provider.value;
  const n = k => Number(p[k]) || 0;
  const tile = (label, value, color, icon, style) => ({ label, value, color: color[0], tint: color[1], icon, style });
  return [
    tile(t('Purchases'), n('total_purchase'), C.blue, ShoppingCartOutlined),
    tile(t('TotalAmount'), money(n('total_amount')), C.teal, DollarOutlined),
    tile(t('TotalPaid'), money(n('total_paid')), C.green, CheckCircleOutlined),
    tile(t('Due'), money(n('due')), C.red, ExclamationCircleOutlined, n('due') > 0 ? { color: '#ff4d4f' } : { color: '#52c41a' }),
    tile(t('Opening_Balance'), money(n('opening_balance')), C.purple, CalculatorOutlined, n('opening_balance') > 0 ? { color: '#ff4d4f' } : { color: '#52c41a' }),
    tile(t('Credit_Limit'), n('credit_limit') > 0 ? money(n('credit_limit')) : t('No_limit'), C.blue, CreditCardOutlined, { color: '#1677ff' }),
  ];
});

const titleFor = key => `${t('Reports')} / ${t('SuppliersReport')} / ${t(key)}`;

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due', 'montant'];
const MONEY_SUMS = [
  { key: 'GrandTotal', money: true },
  { key: 'paid_amount', money: true },
  { key: 'due', money: true },
];
const moneyCols = () => [
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
];

const purchaseColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  ...moneyCols(),
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' },
]);

const returnColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Purchase_Ref'), dataIndex: 'purchase_ref', key: 'purchase_ref' },
  ...moneyCols(),
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' },
]);

const paymentColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Purchase'), dataIndex: 'purchase_Ref', key: 'purchase_Ref' },
  { title: t('ModePaiement'), dataIndex: 'payment_method', key: 'payment_method' },
  { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right', exportValue: r => money(r.montant) },
]);

onMounted(async () => {
  try {
    const data = await http.get(`report/provider/${id}`);
    if (data?.report) provider.value = data.report;
  } catch (e) { /* cards stay at zero, tabs still work */ } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
/* KPI tiles — shared design with the customer ledger. */
.kpi-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
}
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
.kpi-text {
  min-width: 0;
  flex: 1 1 auto;
}
.kpi-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  line-height: 1.3;
  margin-bottom: 3px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.kpi-value {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
