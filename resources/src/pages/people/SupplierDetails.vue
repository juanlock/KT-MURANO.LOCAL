<template>
  <div class="page">
    <PageHeader :title="$t('Provider_details')" :breadcrumb="[$t('People'), $t('Suppliers'), $t('Provider_details')]">
      <template #actions>
        <a-space wrap>
          <a-button @click="$goBack('/suppliers')">
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

      <!-- Supplier info: contact details, address, contact person. -->
      <a-tab-pane key="info" :tab="$t('Supplier_Info')">
        <a-card size="small" :loading="!info">
          <template #extra>
            <a-button v-if="auth.can('Suppliers_edit')" size="small" @click="$router.push(`/suppliers/${id}/edit`)">
              <template #icon><EditOutlined /></template>
              {{ $t('Edit') }}
            </a-button>
          </template>
          <a-descriptions v-if="info" :column="{ xs: 1, md: 2 }" size="small" bordered>
            <a-descriptions-item :label="$t('SupplierName')">{{ info.name }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Code')">{{ info.code || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Email')">
              <a v-if="info.email" :href="`mailto:${info.email}`">{{ info.email }}</a><span v-else>—</span>
              <div v-if="(info.additional_emails || []).length" class="muted">{{ info.additional_emails.join(', ') }}</div>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Phone')">{{ info.phone || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Adress')" :span="2">{{ addressLine || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Tax_Number')">{{ info.tax_number || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Credit_Limit')">{{ Number(info.credit_limit) > 0 ? money(info.credit_limit) : $t('No_limit') }}</a-descriptions-item>
            <a-descriptions-item :label="$t('tx_Contact_person_25754')" :span="2">
              <template v-if="info.contact_name || info.contact_email || info.contact_phone">
                <b>{{ info.contact_name || '—' }}</b>
                <span v-if="info.contact_email"> · <a :href="`mailto:${info.contact_email}`">{{ info.contact_email }}</a></span>
                <span v-if="info.contact_phone"> · {{ info.contact_phone }}</span>
              </template>
              <span v-else class="muted">{{ $t('tx_Not_set_add_it_with_Edit_3dced') }}</span>
            </a-descriptions-item>
          </a-descriptions>
        </a-card>
      </a-tab-pane>

      <!-- Email Office: every email from / to this supplier, one row per conversation. -->
      <a-tab-pane v-if="showEmails" key="emails" :tab="`Email conversation${emailsCrud.total.value ? ` (${emailsCrud.total.value})` : ''}`">
        <DataTable
          :crud="emailsCrud" :columns="emailColumns" :table-key="'supplier-details|emails'"
          :row-expandable="r => r.thread_count > 1"
        >
          <template #expandedRowRender="{ record }">
            <div class="thread-list">
              <div v-for="m in record.messages" :key="m.id" class="thread-msg" @click="openEmail(m, record.messages)">
                <a-tag :color="m.direction === 'in' ? 'green' : 'blue'" style="margin: 0">{{ m.direction === 'in' ? $t('tx_From_supplier_54d71') : $t('tx_To_supplier_cd893') }}</a-tag>
                <span class="thread-date">{{ dateTime(m.sent_at) }}</span>
                <span class="thread-text"><b>{{ m.from_name || m.from_email }}</b> — {{ m.snippet }}</span>
              </div>
            </div>
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'email_date'">{{ dateTime(record.sent_at) }}</template>
            <template v-else-if="column.key === 'email_direction'">
              <a-tag :color="record.direction === 'in' ? 'green' : 'blue'">{{ record.direction === 'in' ? $t('tx_From_supplier_54d71') : $t('tx_To_supplier_cd893') }}</a-tag>
            </template>
            <template v-else-if="column.key === 'email_subject'">
              <a @click="openEmail(record, record.messages || [])">{{ record.thread_count > 1 ? record.thread_subject : record.subject }}</a>
              <a-tag v-if="record.thread_count > 1" color="purple" style="margin-left: 6px">{{ record.thread_count }}</a-tag>
              <div class="muted email-snippet">{{ record.thread_count > 1 ? $t('tx_Latest_244f9') : '' }}{{ record.snippet }}</div>
            </template>
            <template v-else-if="column.key === 'email_category'">{{ emailCategoryLabel(record.category) }}</template>
            <template v-else-if="column.key === 'email_linked'">
              <a-tag v-if="record.linked_label" color="purple">{{ record.linked_label }}</a-tag>
              <span v-else class="muted">—</span>
            </template>
          </template>
        </DataTable>
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

    <!-- Read-only: the email and its conversation (classify in Email Office). -->
    <EmailViewerDrawer v-model:open="emailOpen" :email-id="emailId" :thread="emailThread" read-only />
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
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
  ExclamationCircleOutlined, CalculatorOutlined, CreditCardOutlined, EditOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportTab from '../../components/ReportTab.vue';
import DataTable from '../../components/DataTable.vue';
import EmailViewerDrawer from '../../components/EmailViewerDrawer.vue';
import { emailCategoryLabel, loadEmailFolders } from '../../lib/emailCategories';
import { useCrudTable } from '../../composables/useCrudTable';
import StatusTag from '../../components/StatusTag.vue';
import { TAG_KEYS, documentTag } from '../../lib/statusTags';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, roundMoney, dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const id = route.params.id;

const tab = ref(String(route.query.tab || 'purchases'));

/* ---- Supplier info tab (GET providers/{id}) ---- */
const info = ref(null);
const addressLine = computed(() => {
  const p = info.value || {};
  const cityLine = [p.city, [p.state, p.zip].filter(Boolean).join(' ')].filter(Boolean).join(', ');
  return [p.adresse, cityLine, p.country].filter(Boolean).join(', ');
});
async function loadInfo() {
  try {
    const d = await http.get(`providers/${id}`);
    info.value = d.provider || d;
  } catch (e) { info.value = { name: provider.value.name }; }
}

/* ---- Email conversation tab (Email Office, one row per conversation) ---- */
const showEmails = computed(() => auth.moduleEnabled('email_office') && auth.can('email_office_view'));
if (showEmails.value) loadEmailFolders(); // names of the company's own email folders
const emailsCrud = useCrudTable('email-office/party', { rowsKey: 'emails', limit: 10, params: () => ({ type: 'provider', id, threads: 1 }) });
const emailColumns = [
  { title: tEs('date', 'Date'), dataIndex: 'sent_at', key: 'email_date', width: 170 },
  { title: '', dataIndex: 'direction', key: 'email_direction', width: 120 },
  // A default width: the preview below the subject wraps inside it (2 lines).
  { title: tEs('Subject', 'Subject'), dataIndex: 'subject', key: 'email_subject', width: 480 },
  { title: tEs('type', 'Type'), dataIndex: 'category', key: 'email_category', width: 100 },
  { title: tEs('tx_Recorded_in_Murano_52c13', 'Recorded in Murano'), key: 'email_linked', width: 160 },
];
const emailOpen = ref(false);
const emailId = ref(null);
const emailThread = ref([]);
function openEmail(r, thread = []) {
  emailThread.value = thread;
  emailId.value = r.id;
  emailOpen.value = true;
}
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

onMounted(() => {
  loadSummary();
  loadInfo();
  if (showEmails.value) emailsCrud.fetchRows();
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}

/* Email conversation: the preview under the subject -- 2 lines, wrapping
   inside the column (never stretching it). */
.email-snippet {
  white-space: normal;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Email conversation: the emails of one conversation (expanded row). */
.thread-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding-left: 8px;
  border-left: 3px solid #d3adf7;
}
.thread-msg {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px;
  border-radius: 6px;
  cursor: pointer;
  min-width: 0;
}
.thread-msg:hover {
  background: rgba(114, 46, 209, 0.06);
}
.thread-date {
  flex: none;
  color: #888;
  font-size: 12px;
  width: 130px;
}
.thread-text {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
