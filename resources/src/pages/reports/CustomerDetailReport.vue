<template>
  <div class="page">
    <PageHeader :title="$t('CustomersReport')" :breadcrumb="[$t('Reports'), $t('CustomersReport')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in cards" :key="card.label" :xs="24" :sm="12" :lg="6">
        <a-card size="small">
          <a-statistic :title="card.label" :value="card.value" />
        </a-card>
      </a-col>
    </a-row>

    <a-tabs v-model:activeKey="tab">
      <a-tab-pane key="sales" :tab="$t('Sales')">
        <ReportTab
          endpoint="report/client_sales" rows-key="sales" row-key="id"
          :columns="salesColumns" :extra-params="{ id }" :title="titleFor('Sales')"
          :sums="[{ key: 'GrandTotal', money: true }, { key: 'paid_amount', money: true }, { key: 'due', money: true }]"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/sales/${record.id}`">{{ record.Ref }}</router-link>
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
          endpoint="report/client_quotations" rows-key="quotations" row-key="id"
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

      <a-tab-pane key="returns" :tab="$t('Returns')">
        <ReportTab
          endpoint="report/client_returns" rows-key="returns_customer" row-key="id"
          :columns="returnColumns" :extra-params="{ id }" :title="titleFor('Returns')"
          :sums="[{ key: 'GrandTotal', money: true }, { key: 'paid_amount', money: true }, { key: 'due', money: true }]"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/sale-returns/${record.id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="column.key === 'sale_ref'">
              <!-- Legacy left this blank when the return has no parent sale. -->
              <router-link v-if="record.sale_id" :to="`/sales/${record.sale_id}`">{{ record.sale_ref }}</router-link>
            </template>
            <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
            <template v-else-if="TAG_KEYS.includes(column.key)">
              <StatusTag :tag="documentTag('sale_return', column.key, record)" />
            </template>
          </template>
        </ReportTab>
      </a-tab-pane>

      <a-tab-pane key="payments" :tab="$t('SalesInvoice')">
        <ReportTab
          endpoint="report/client_payments" rows-key="payments" row-key="Ref"
          :columns="paymentColumns" :extra-params="{ id }" :title="titleFor('SalesInvoice')"
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
 * Customer drill-down report — legacy detail_Customer_Report.vue.
 * Summary GET report/client/{id} → {report}; four independent server-paginated
 * tabs (report/client_sales | client_quotations | client_returns |
 * client_payments), each taking page/limit/search/id.
 *
 * Two legacy behaviours deliberately NOT carried over, both defects rather
 * than intent: the page hid every tab behind `isLoading` that only the
 * quotations call ever cleared (one failed request blanked the whole report),
 * and search terms were concatenated into the URL unencoded. Money is now
 * formatted consistently — legacy rendered the same values three different
 * ways across screen, print and PDF.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
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

const tab = ref('sales');
const client = ref({ total_sales: 0, total_amount: 0, total_paid: 0, due: 0 });

const cards = computed(() => [
  { label: t('Sales'), value: client.value.total_sales ?? 0 },
  { label: t('TotalAmount'), value: money(client.value.total_amount) },
  { label: t('TotalPaid'), value: money(client.value.total_paid) },
  { label: t('Due'), value: money(client.value.due) },
]);

const titleFor = key => `${t('Reports')} / ${t('CustomersReport')} / ${t(key)}`;

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due', 'montant'];
const moneyCols = () => [
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
];

const salesColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  ...moneyCols(),
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' },
  { title: t('Shipping_status'), dataIndex: 'shipping_status', key: 'shipping_status' },
]);

const quotationColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
]);

const returnColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Sale_Ref'), dataIndex: 'sale_ref', key: 'sale_ref' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  ...moneyCols(),
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' },
]);

const paymentColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Sale'), dataIndex: 'Sale_Ref', key: 'Sale_Ref' },
  { title: t('ModePaiement'), dataIndex: 'payment_method', key: 'payment_method' },
  { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right', exportValue: r => money(r.montant) },
]);

onMounted(async () => {
  try {
    const data = await http.get(`report/client/${id}`);
    if (data?.report) client.value = data.report;
  } catch (e) { /* cards stay at zero, tabs still work */ }
});
</script>
