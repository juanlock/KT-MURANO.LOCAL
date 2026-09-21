<template>
  <div class="page">
    <PageHeader :title="$t('Provider_details')" :breadcrumb="[$t('People'), $t('Suppliers'), $t('Provider_details')]">
      <template #actions>
        <a-space wrap>
          <a-button @click="$router.push('/suppliers')">
            <template #icon><LeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button
            v-if="auth.can('pay_supplier_due') && Number(provider.due) > 0"
            type="primary" @click="openPay('due')"
          >
            <template #icon><DollarOutlined /></template>
            {{ $t('Pay_Due') }}
          </a-button>
          <a-button
            v-if="auth.can('pay_purchase_return_due') && Number(provider.return_Due) > 0"
            @click="openPay('return')"
          >
            <template #icon><DollarOutlined /></template>
            {{ $t('pay_all_purchase_return_due_at_a_time') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Same pay-due dialog as the Suppliers list. -->
    <a-modal
      v-model:open="payOpen"
      :title="payKind === 'due' ? $t('Pay_Due') : $t('Total_Purchase_Return_Due')"
      width="720px"
      :footer="null"
    >
      <a-alert
        v-if="payKind === 'due'"
        type="info" show-icon style="margin-bottom: 16px"
        :message="`${$t('Payment_Allocation')}: ${$t('Payment_Allocation_description')}`"
      />
      <a-row :gutter="16" style="margin-bottom: 16px">
        <template v-if="payKind === 'due'">
          <a-col :span="8">
            <a-statistic :title="$t('Opening_Balance')" :value="money(openingBalance)" />
          </a-col>
          <a-col :span="8">
            <a-statistic :title="$t('Due')" :value="money(dueAmount)" />
          </a-col>
          <a-col :span="8">
            <a-statistic
              :title="$t('Total_Due')" :value="money(totalDue)"
              :value-style="{ color: totalDue > 0 ? '#ff4d4f' : '#52c41a' }"
            />
          </a-col>
        </template>
        <a-col v-else :span="24">
          <a-statistic
            :title="$t('Total_Purchase_Return_Due')" :value="money(totalDue)"
            :value-style="{ color: totalDue > 0 ? '#ff4d4f' : '#52c41a' }"
          />
        </a-col>
      </a-row>

      <a-form layout="vertical">
        <a-form-item :label="$t('Supplier')">
          <a-input :value="provider.name" disabled />
        </a-form-item>
        <a-form-item :label="$t('Paying_Amount') + ' *'">
          <a-input-number v-model:value="payForm.amount" :min="0" :max="totalDue" style="width: 100%" />
          <div class="muted">{{ $t('Maximum_payment') }}: <strong>{{ money(totalDue) }}</strong></div>
        </a-form-item>
        <a-form-item :label="$t('Paymentchoice') + ' *'">
          <a-select
            v-model:value="payForm.payment_method_id" :placeholder="$t('PleaseSelect')"
            :options="paymentMethodOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('Account')">
          <a-select
            v-model:value="payForm.account_id" allow-clear :placeholder="$t('Choose_Account')"
            :options="accountOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('Please_provide_any_details')">
          <a-textarea v-model:value="payForm.notes" :rows="3" />
        </a-form-item>
        <a-button type="primary" :loading="paying" @click="submitPay">{{ $t('submit') }}</a-button>
      </a-form>
    </a-modal>

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
          endpoint="provider_details/purchases" rows-key="purchases" row-key="id"
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
          endpoint="provider_details/returns" rows-key="returns_supplier" row-key="id"
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
          endpoint="provider_details/payments" rows-key="payments" row-key="Ref"
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
 * Supplier details — a People-scoped view of a single supplier (gated by
 * Suppliers_view, not a reports permission). Same summary + tabs as the reports
 * drill-down, reached from the Suppliers list "details" action.
 *
 * Summary GET provider_details/summary/{id} → {report}; three server-paginated
 * tabs (provider_details/purchases | payments | returns), each taking
 * page/limit/search/id. These ProvidersController endpoints are gated by
 * Suppliers_view (the `view` ability), separate from the reports endpoints.
 *
 * Note the field-name trap kept verbatim: payments carry `purchase_Ref`
 * (capital R), returns carry `purchase_ref`.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  LeftOutlined, ShoppingCartOutlined, DollarOutlined, CheckCircleOutlined,
  ExclamationCircleOutlined, CalculatorOutlined, CreditCardOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportTab from '../../components/ReportTab.vue';
import StatusTag from '../../components/StatusTag.vue';
import { TAG_KEYS, documentTag } from '../../lib/statusTags';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, roundMoney } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const id = route.params.id;

const tab = ref('purchases');
const loading = ref(true);
const provider = ref({ total_purchase: 0, total_amount: 0, total_paid: 0, due: 0, opening_balance: 0, credit_limit: 0, return_Due: 0, name: '' });
const accounts = ref([]);
const paymentMethods = ref([]);

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

const titleFor = key => `${t('Provider_details')} / ${t(key)}`;

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

async function loadSummary() {
  try {
    const data = await http.get(`provider_details/summary/${id}`);
    if (data?.report) provider.value = data.report;
    accounts.value = data?.accounts || [];
    paymentMethods.value = data?.payment_methods || [];
  } catch (e) { /* cards stay at zero, tabs still work */ } finally {
    loading.value = false;
  }
}

/* ------------------------------------------------------------- payments
 * Same dialog + endpoints as the Suppliers list (pay_supplier_due /
 * pay_purchase_return_due); the target is this page's supplier. */
const payOpen = ref(false);
const payKind = ref('due');
const paying = ref(false);
const payForm = ref({ amount: 0, payment_method_id: undefined, account_id: undefined, notes: '' });

const paymentMethodOptions = computed(() =>
  paymentMethods.value.map(x => ({ value: x.id, label: x.name })));
const accountOptions = computed(() =>
  accounts.value.map(a => ({ value: a.id, label: a.account_name })));

// Opening balance is paid first, then purchases — so the due total for a
// payment is opening_balance + purchase due (matches pay_supplier_due).
const openingBalance = computed(() => Number(provider.value.opening_balance) || 0);
const dueAmount = computed(() =>
  Number(payKind.value === 'due' ? provider.value.due : provider.value.return_Due) || 0
);
const totalDue = computed(() =>
  roundMoney(payKind.value === 'due' ? openingBalance.value + dueAmount.value : dueAmount.value)
);

function openPay(kind) {
  payKind.value = kind;
  payForm.value = { amount: totalDue.value, payment_method_id: undefined, account_id: undefined, notes: '' };
  payOpen.value = true;
}

async function submitPay() {
  if (!(Number(payForm.value.amount) > 0)) {
    message.warning(t('Amount'));
    return;
  }
  if (!payForm.value.payment_method_id) {
    message.warning(t('Paymentchoice'));
    return;
  }
  paying.value = true;
  try {
    const endpoint = payKind.value === 'due' ? 'pay_supplier_due' : 'pay_purchase_return_due';
    await http.post(endpoint, {
      provider_id: id,
      amount: payForm.value.amount,
      notes: payForm.value.notes,
      payment_method_id: payForm.value.payment_method_id,
      account_id: payForm.value.account_id,
    });
    message.success(t('Successfully_Created'));
    payOpen.value = false;
    loadSummary(); // refresh the KPI cards with the new due
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    paying.value = false;
  }
}

onMounted(loadSummary);
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}

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
