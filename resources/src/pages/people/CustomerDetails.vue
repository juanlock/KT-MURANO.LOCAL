<template>
  <div class="page">
    <PageHeader :title="$t('Customer_details')" :breadcrumb="[$t('Customers'), $t('Customer_details')]">
      <template #actions>
        <a-button @click="$router.push('/customers')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button :loading="exporting" @click="exportPdf">
          <template #icon><FilePdfOutlined /></template>
          {{ exporting ? $t('Generating') : $t('Download_PDF') }}
        </a-button>
        <a-button v-if="can('Customers_edit')" @click="$router.push(`/customers/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
        <a-button
          v-if="totalDue > 0 && auth.can('pay_due')"
          type="primary" @click="openPayDue"
        >
          <template #icon><DollarOutlined /></template>
          {{ $t('Pay_Due') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Customer profile banner -->
      <a-card :bordered="false" class="ledger-hero" style="margin-bottom: 16px">
        <div class="hero-main">
          <a-avatar :size="60" class="hero-avatar">{{ initials }}</a-avatar>
          <div class="hero-info">
            <div class="hero-name-row">
              <span class="hero-name">{{ client.name || '-' }}</span>
              <a-tag v-if="client.code" color="blue" class="hero-code">#{{ client.code }}</a-tag>
            </div>
            <div class="hero-meta">
              <span v-if="client.email" class="meta-chip"><MailOutlined /> {{ client.email }}</span>
              <span v-if="client.additional_emails?.length" class="meta-chip"><MailOutlined /> {{ client.additional_emails.join(', ') }}</span>
              <span v-if="client.phone" class="meta-chip"><PhoneOutlined /> {{ client.phone }}</span>
              <span v-if="cityCountry" class="meta-chip"><EnvironmentOutlined /> {{ cityCountry }}</span>
              <span v-if="client.tax_number" class="meta-chip"><IdcardOutlined /> {{ client.tax_number }}</span>
            </div>
          </div>
        </div>
      </a-card>

      <!-- KPI tiles -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="kpi in kpis" :key="kpi.label" :xs="12" :sm="8" :md="6" :xl="6">
          <a-card :bordered="false" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: kpi.tint, color: kpi.color }">
                <component :is="kpi.icon" />
              </div>
              <div class="kpi-text">
                <div class="kpi-label">{{ $t(kpi.label) }}</div>
                <div class="kpi-value" :style="kpi.style">{{ kpi.text }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- One DataTable per tab: search, show/hide columns (gear, per user),
           Customize columns (rename / reorder, admins), page totals. -->
      <a-tabs v-model:activeKey="tab">
        <a-tab-pane v-for="pane in panes" :key="pane.key" :tab="$t(pane.label) === pane.label ? (pane.title || pane.label) : $t(pane.label)">
          <DataTable :crud="pane.crud" :columns="pane.columns" :row-key="pane.rowKey || 'id'" :table-key="`customer-details|${pane.key}`"
            :row-expandable="pane.key === 'emails' ? (r => r.thread_count > 1) : undefined">
            <!-- Email conversation: the other emails of a conversation, newest first. -->
            <template v-if="pane.key === 'emails'" #expandedRowRender="{ record }">
              <div class="thread-list">
                <div v-for="m in record.messages" :key="m.id" class="thread-msg" :class="{ unread: m.direction === 'in' && !m.is_read }" @click="openEmail(m, record.messages)">
                  <a-tag :color="m.direction === 'in' ? 'green' : 'blue'" style="margin: 0">{{ m.direction === 'in' ? 'From customer' : 'To customer' }}</a-tag>
                  <span class="thread-date">{{ dateTime(m.sent_at) }}</span>
                  <span class="thread-text"><b>{{ m.from_name || m.from_email }}</b> — {{ m.snippet }}</span>
                  <PaperClipOutlined v-if="m.attachments_count" />
                </div>
              </div>
            </template>
            <template v-if="pane.key === 'contracts' && can('sales_contracts_add')" #toolbar>
              <a-button type="primary" @click="$router.push({ path: '/sales-contracts/create', query: { client_id: id } })">
                <template #icon><PlusOutlined /></template>
                New contract
              </a-button>
            </template>
            <template #bodyCell="{ column, record }">
              <template v-if="MONEY_KEYS.includes(column.key)">
                <span :class="dueClass(column.key, record)">{{ money(record[column.key]) }}</span>
              </template>
              <template v-else-if="column.key === 'payment_status'">
                <a-tag :color="paymentStatusColor(record.payment_status)">{{ record.payment_status }}</a-tag>
              </template>
              <template v-else-if="column.key === 'payment_type'">
                <a-tag :color="paymentTypeColor(record.payment_type)">{{ paymentTypeLabel(record.payment_type) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'statut' && pane.key === 'service_jobs'">
                <a-tag :color="jobStatusColor(record.statut)">{{ record.statut }}</a-tag>
                <a-tooltip v-if="!record.counts_toward_due" :title="$t('Not_Counted_In_Due')">
                  <a-tag color="default">{{ $t('Not_Counted_In_Due') }}</a-tag>
                </a-tooltip>
              </template>
              <template v-else-if="column.key === 'statut'">
                <a-tag :color="docStatusColor(record.statut)">{{ record.statut }}</a-tag>
              </template>
              <template v-else-if="column.key === 'Ref' && pane.link">
                <router-link :to="pane.link(record)">{{ record.Ref }}</router-link>
              </template>
              <template v-else-if="column.key === 'Sale_Ref'">
                <span v-if="record.Sale_Ref">{{ record.Sale_Ref }}</span>
                <span v-else class="muted">-</span>
              </template>
              <!-- Service Jobs tab: visit date + the contract a visit comes from -->
              <template v-else-if="column.key === 'scheduled_date'">
                <span v-if="record.scheduled_date">{{ date(record.scheduled_date) }} {{ String(record.scheduled_date).slice(11, 16) }}</span>
                <span v-else class="muted">—</span>
              </template>
              <template v-else-if="column.key === 'job_contract'">
                <router-link v-if="record.sales_contract_id" :to="`/sales-contracts/${record.sales_contract_id}`">{{ record.contract_number || '#' + record.sales_contract_id }}</router-link>
                <span v-else class="muted">—</span>
              </template>
              <!-- Contracts tab -->
              <template v-else-if="column.key === 'contract_number'">
                <router-link :to="`/sales-contracts/${record.id}`">{{ record.contract_number }}</router-link>
              </template>
              <template v-else-if="column.key === 'value'">{{ money(record.value) }}</template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="STATUS_COLORS[record.status]">{{ statusLabel(record.status) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'billing'">
                <span v-if="record.billing_enabled"><SyncOutlined style="color: #6d28d9" /> {{ billingLabel(record) }}</span>
                <span v-else class="muted">One-off</span>
              </template>
              <template v-else-if="column.key === 'next_invoice_date'">
                <span v-if="record.next_invoice_date">{{ date(record.next_invoice_date) }}</span>
                <span v-else class="muted">—</span>
              </template>
              <!-- Email conversation tab -->
              <template v-else-if="column.key === 'email_date'">{{ dateTime(record.sent_at) }}</template>
              <template v-else-if="column.key === 'email_direction'">
                <a-tag :color="record.direction === 'in' ? 'green' : 'blue'">{{ record.direction === 'in' ? 'From customer' : 'To customer' }}</a-tag>
              </template>
              <template v-else-if="column.key === 'email_subject'">
                <a @click="openEmail(record, record.messages || [])">{{ record.thread_count > 1 ? record.thread_subject : record.subject }}</a>
                <a-tooltip v-if="record.thread_count > 1" title="Emails in this conversation (expand the row to see them all)">
                  <a-tag color="purple" style="margin-left: 6px">{{ record.thread_count }}</a-tag>
                </a-tooltip>
                <a-tag v-if="record.thread_unread" color="red">{{ record.thread_unread }} unread</a-tag>
                <div class="muted email-snippet">{{ record.thread_count > 1 ? 'Latest: ' : '' }}{{ record.snippet }}</div>
              </template>
              <template v-else-if="column.key === 'email_category'">{{ emailCategoryLabel(record.category) }}</template>
              <template v-else-if="column.key === 'start_date'">{{ record.start_date ? date(record.start_date) : '—' }}</template>
              <template v-else-if="column.key === 'end_date'">{{ record.end_date ? date(record.end_date) : 'Open-ended' }}</template>
            </template>
          </DataTable>
        </a-tab-pane>
      </a-tabs>

      <!-- Read-only: see the email and its conversation; classifying is done in Email Office. -->
      <EmailViewerDrawer v-model:open="emailOpen" :email-id="emailId" :thread="emailThread" read-only />

      <a-card v-if="customFields.length" size="small" :title="$t('CustomFields')" style="margin-top: 16px">
        <a-descriptions :column="{ xs: 1, md: 2 }" size="small">
          <a-descriptions-item v-for="f in customFields" :key="f.id" :label="f.name">
            {{ customFieldValue(f) }}
          </a-descriptions-item>
        </a-descriptions>
      </a-card>
    </template>

    <!-- Pay due + printable credit note (shared with the customers list) -->
    <PayDueModal
      ref="payModal"
      :client="client"
      :opening-balance="openingBalance"
      :sales-due="salesDue"
      :service-due="serviceDue"
      :payment-methods="paymentMethods"
      :accounts="accounts"
      :company="company"
      @paid="onPaid"
    />
  </div>
</template>

<script setup>
/**
 * Customer details -- the one customer page (the former Customer Ledger,
 * /customers/:id/ledger, was merged in here and removed; its route now
 * redirects here). Profile banner + KPI tiles from GET clients/{id}/brief,
 * the ledger PDF (client_ledger_pdf), Pay Due, and one tab per document
 * type: sales / service jobs / payments / quotations / returns / payment
 * returns / sales contracts (SalesContractController, ?client_id=).
 * Every tab is a DataTable: search, page size, show/hide columns (per user),
 * Customize columns -- rename / reorder -- (admins, company-wide) and
 * page totals (sum: 'money' columns).
 *
 * Two legacy defects stay fixed (both misled about money): Sales Due comes
 * from the brief aggregate, not the loaded page; the payment-status badge
 * tests 'unpaid' before 'paid'. The ledger's Returns tab read
 * `returns_customer` from a payload keyed `returns` -- useCrudTable reads
 * the `returns` key directly.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  LeftOutlined, DollarOutlined, FilePdfOutlined, MailOutlined, PhoneOutlined, EnvironmentOutlined, IdcardOutlined,
  ShoppingOutlined, CheckCircleOutlined, CalculatorOutlined, ExclamationCircleOutlined, ToolOutlined,
  WalletOutlined, RollbackOutlined, CreditCardOutlined, PayCircleOutlined, StarOutlined,
  PlusOutlined, SyncOutlined, EditOutlined, PaperClipOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PayDueModal from '../../components/PayDueModal.vue';
import DataTable from '../../components/DataTable.vue';
import EmailViewerDrawer from '../../components/EmailViewerDrawer.vue';
import { emailCategoryLabel, loadEmailFolders } from '../../lib/emailCategories';

// Names of the company's own email folders (Email conversation tab "Type").
loadEmailFolders();
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { STATUS_COLORS, statusLabel, billingLabel } from '../sales_contracts/salesContractVocab';

const { t } = useI18n();
const { money, roundMoney, date, dateTime } = useFormat();
const auth = useAuthStore();
const can = p => auth.user?.type === 'SuperAdmin' || auth.can(p);
const route = useRoute();
const router = useRouter();
const id = route.params.id;

const loading = ref(true);
const exporting = ref(false);
// ?tab= opens a given tab (e.g. the Customers list's Contracts count -> contracts).
const tab = ref(String(route.query.tab || 'sales'));
const client = ref({});
const brief = ref({});
const paymentMethods = ref([]);
const accounts = ref([]);
const customFields = ref([]);
const company = ref({ CompanyName: '', CompanyAdress: '', CompanyPhone: '' });

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due', 'montant'];

const openingBalance = computed(() => Number(brief.value.opening_balance ?? client.value.opening_balance) || 0);
const salesDue = computed(() => Number(brief.value.sale_due) || 0);
const serviceDue = computed(() => Number(brief.value.service_due) || 0);
/** Opening balance + sales due + service due -- what Pay Due can settle. */
// Rounded: the raw float sum is the :max of the paying-amount input, and the
// clamp would otherwise display something like 121.99999999999994.
const totalDue = computed(() => roundMoney(openingBalance.value + salesDue.value + serviceDue.value));

const initials = computed(() =>
  (client.value.name || '?')
    .split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?'
);
const cityCountry = computed(() => [client.value.city, client.value.country].filter(Boolean).join(', '));

// Accent colours + matching soft tints for the KPI icon chips.
const C = {
  blue: ['#1677ff', 'rgba(22, 119, 255, 0.12)'],
  green: ['#52c41a', 'rgba(82, 196, 26, 0.12)'],
  purple: ['#722ed1', 'rgba(114, 46, 209, 0.12)'],
  red: ['#ff4d4f', 'rgba(255, 77, 79, 0.12)'],
  indigo: ['#2f54eb', 'rgba(47, 84, 235, 0.12)'],
  amber: ['#faad14', 'rgba(250, 173, 20, 0.14)'],
  teal: ['#13c2c2', 'rgba(19, 194, 194, 0.12)'],
};
const RED = { color: '#ff4d4f' };
const GREEN = { color: '#52c41a' };

const kpis = computed(() => {
  const b = brief.value;
  const n = k => Number(b[k]) || 0;
  const sign = v => (v > 0 ? RED : GREEN);
  const tile = (label, text, color, extra = {}) => ({ label, text, icon: extra.icon, color: color[0], tint: color[1], style: extra.style });
  // Opening balance / credit limit / loyalty tiles follow the per-company
  // feature flags (Total_Due still includes the opening balance -- display only).
  const tiles = [
    tile('Sales_Grand', money(n('salesGrand')), C.blue, { icon: ShoppingOutlined }),
    tile('Sales_Paid', money(n('salesPaid')), C.green, { icon: CheckCircleOutlined }),
    ...(auth.customerOpeningBalanceEnabled
      ? [tile('Opening_Balance', money(openingBalance.value), C.purple, { icon: CalculatorOutlined, style: sign(openingBalance.value) })]
      : []),
    tile('Sales_Due', money(salesDue.value), C.red, { icon: ExclamationCircleOutlined, style: sign(salesDue.value) }),
    tile('Service_Due', money(serviceDue.value), C.red, { icon: ToolOutlined, style: sign(serviceDue.value) }),
    tile('Total_Due', money(n('netBalance')), C.indigo, { icon: WalletOutlined, style: sign(n('netBalance')) }),
    tile('Returns_Due', money(n('return_due')), C.amber, { icon: RollbackOutlined, style: { color: '#faad14' } }),
    tile('Payments_Total', money(n('paymentsTotal')), C.teal, { icon: DollarOutlined }),
    ...(auth.customerCreditLimitEnabled
      ? [tile('Credit_Limit', Number(client.value.credit_limit) > 0 ? money(client.value.credit_limit) : t('No_limit'), C.blue, { icon: CreditCardOutlined, style: { color: '#1677ff' } })]
      : []),
  ];
  if (b.wallet_enabled) {
    tiles.push(tile('WalletBalance', money(n('wallet_balance')), C.green, { icon: PayCircleOutlined, style: { color: n('wallet_balance') < 0 ? '#ff4d4f' : '#52c41a' } }));
  }
  if (auth.customerLoyaltyEnabled) {
    tiles.push(tile('Loyalty_Points', String(Number(b.points ?? client.value.points) || 0), C.amber, { icon: StarOutlined, style: { color: '#faad14' } }));
  }
  return tiles;
});

function paymentStatusColor(status) {
  const s = String(status || '').toLowerCase();
  // 'unpaid' contains 'paid' — test it first or unpaid renders green.
  if (s.includes('unpaid')) return 'error';
  if (s.includes('partial')) return 'warning';
  if (s.includes('paid')) return 'success';
  return 'default';
}
function docStatusColor(st) {
  const s = String(st || '').toLowerCase();
  if (s.includes('completed') || s.includes('approved')) return 'success';
  if (s.includes('pending')) return 'warning';
  if (s.includes('sent')) return 'processing';
  if (s.includes('canceled') || s.includes('cancelled') || s.includes('rejected')) return 'error';
  return 'default';
}
function dueClass(key, record) {
  return key === 'due' && Number(record.due) > 0 ? 'due-open' : '';
}
function jobStatusColor(status) {
  const s = String(status || '').toLowerCase();
  if (['completed', 'delivered'].includes(s)) return 'success';
  if (['declined', 'cancelled'].includes(s)) return 'error';
  if (['approved', 'in_progress', 'ready'].includes(s)) return 'processing';
  return 'default';
}
function paymentTypeColor(type) {
  if (type === 'opening_balance') return 'processing';
  if (type === 'service') return 'warning';
  return 'success';
}
function paymentTypeLabel(type) {
  if (type === 'opening_balance') return t('Opening_Balance');
  if (type === 'service') return t('Service_Job');
  return t('Sale');
}
function customFieldValue(f) {
  if (!f.value && f.value !== 0 && f.value !== false) return '-';
  if (f.field_type === 'checkbox') {
    return ['1', 1, true].includes(f.value) ? t('Yes') : t('No');
  }
  return f.value;
}

// ---------------- tabs ----------------

const tableFor = (endpoint, rowsKey) => useCrudTable(endpoint, { rowsKey, limit: 10, params: () => ({ id }) });
const cruds = {
  sales: tableFor('sales_client', 'sales'),
  service_jobs: tableFor('service_jobs_client', 'service_jobs'),
  payments: tableFor('payments_client', 'payments'),
  quotations: tableFor('quotations_client', 'quotations'),
  returns: tableFor('returns_client', 'returns'),
  payment_returns: tableFor('payment_returns_client', 'payment_returns'),
  contracts: useCrudTable('sales_contracts', { rowsKey: 'contracts', limit: 10, params: () => ({ client_id: id }) }),
  emails: useCrudTable('email-office/party', { rowsKey: 'emails', limit: 10, params: () => ({ type: 'client', id, threads: 1 }) }),
};

const col = (label, key, opts = {}) => ({ title: t(label), dataIndex: key, key, ...opts });
const moneyCol = (label, key) => col(label, key, { align: 'right', sum: 'money' });

const panes = computed(() => [
  {
    key: 'sales', label: 'Sales', crud: cruds.sales, link: r => `/sales/${r.id}`,
    columns: [
      col('Ref', 'Ref'), col('date', 'date'), col('warehouse', 'warehouse_name'), col('Status', 'statut'),
      moneyCol('Grand_Total', 'GrandTotal'), moneyCol('Paid', 'paid_amount'), moneyCol('Due', 'due'),
      col('Payment_Status', 'payment_status'), col('Shipping_Status', 'shipping_status'),
    ],
  },
  // Service Jobs: only when the company has the Service module and the user may see jobs.
  ...(auth.moduleEnabled('service') && can('service_jobs') ? [{
    key: 'service_jobs', label: 'Service_Jobs', crud: cruds.service_jobs, link: r => `/service/jobs/details/${r.id}`,
    columns: [
      col('Ref', 'Ref'), { title: 'Scheduled', dataIndex: 'scheduled_date', key: 'scheduled_date' }, col('date', 'date'),
      col('Service_Item', 'service_item'), col('Technician', 'technician_name'), { title: 'Contract', key: 'job_contract' },
      moneyCol('Grand_Total', 'GrandTotal'), moneyCol('Paid', 'paid_amount'), moneyCol('Due', 'due'),
      col('Payment_Status', 'payment_status'), col('Status', 'statut'),
    ],
  }] : []),
  {
    key: 'payments', label: 'Payments', crud: cruds.payments, rowKey: 'Ref',
    columns: [
      col('Ref', 'Ref'), col('date', 'date'), col('Type', 'payment_type'), col('Document_Ref', 'Sale_Ref'),
      col('Payment_Method', 'payment_method'), moneyCol('Amount', 'montant'),
    ],
  },
  {
    key: 'quotations', label: 'Quotations', crud: cruds.quotations, link: r => `/quotations/${r.id}`,
    columns: [
      col('Ref', 'Ref'), col('date', 'date'), col('warehouse', 'warehouse_name'), col('Status', 'statut'),
      moneyCol('Grand_Total', 'GrandTotal'),
    ],
  },
  {
    key: 'returns', label: 'Returns', crud: cruds.returns, link: r => `/sale-returns/${r.id}`,
    columns: [
      col('Ref', 'Ref'), col('date', 'date'), col('Sale_Ref', 'sale_ref'), col('warehouse', 'warehouse_name'),
      moneyCol('Grand_Total', 'GrandTotal'), moneyCol('Paid', 'paid_amount'), moneyCol('Due', 'due'),
      col('Payment_Status', 'payment_status'), col('Status', 'statut'),
    ],
  },
  {
    key: 'payment_returns', label: 'Payment_Returns', crud: cruds.payment_returns, rowKey: 'Ref',
    columns: [
      col('Ref', 'Ref'), col('date', 'date'), col('Sale_Return_Ref', 'Sale_Return_Ref'),
      col('Payment_Method', 'payment_method'), moneyCol('Amount', 'montant'),
    ],
  },
  // Sales > Sales Contracts of this customer (employee contracts are HRM's).
  ...(can('sales_contracts_view') ? [{
    key: 'contracts', label: 'Contracts', title: 'Contracts', crud: cruds.contracts,
    columns: [
      { title: '#', dataIndex: 'contract_number', key: 'contract_number' },
      { title: 'Subject', dataIndex: 'subject', key: 'subject' },
      { title: 'Value', dataIndex: 'value', key: 'value', align: 'right', sum: 'money' },
      { title: 'Billing', key: 'billing' },
      { title: 'Next invoice', dataIndex: 'next_invoice_date', key: 'next_invoice_date' },
      { title: 'Start', dataIndex: 'start_date', key: 'start_date' },
      { title: 'End', dataIndex: 'end_date', key: 'end_date' },
      { title: 'Status', dataIndex: 'status', key: 'status' },
    ],
  }] : []),
  // Email Office: every email from / to this customer (their address or matched to them).
  ...(auth.moduleEnabled('email_office') && can('email_office_view') ? [{
    key: 'emails', label: 'Email_Conversation', title: 'Email conversation', crud: cruds.emails,
    columns: [
      { title: 'Date', dataIndex: 'sent_at', key: 'email_date', width: 160 },
      { title: '', dataIndex: 'direction', key: 'email_direction', width: 120 },
      // A default width: the preview below the subject wraps inside it (2 lines).
      { title: 'Subject', dataIndex: 'subject', key: 'email_subject', width: 480 },
      { title: 'Type', dataIndex: 'category', key: 'email_category', width: 100 },
    ],
  }] : []),
]);

// Email conversation: one email in the side panel.
const emailOpen = ref(false);
const emailId = ref(null);
const emailThread = ref([]);
function openEmail(r, thread = []) {
  emailThread.value = thread;
  emailId.value = r.id;
  emailOpen.value = true;
}

// ---------------- PDF ----------------

async function exportPdf() {
  exporting.value = true;
  try {
    await http.download(`client_ledger_pdf?id=${id}`, `customer_ledger_${id}.pdf`);
  } catch (e) {
    message.error(t('Failed_to_export'));
  } finally {
    exporting.value = false;
  }
}

// ---------------- pay due ----------------

const payModal = ref();

function openPayDue() {
  payModal.value?.open();
}

async function onPaid() {
  await loadCustomer();
  panes.value.forEach(p => p.crud.fetchRows());
}

// ---------------- load ----------------

async function loadCustomer() {
  const [main, briefRes] = await Promise.allSettled([
    http.get(`clients/${id}`),
    http.get(`clients/${id}/brief`),
  ]);
  if (main.status !== 'fulfilled' || !main.value?.client) {
    message.error(t('Failed_to_load_customer'));
    router.push('/customers');
    return false;
  }
  client.value = main.value.client;
  paymentMethods.value = main.value.payment_methods || [];
  accounts.value = main.value.accounts || [];
  if (briefRes.status === 'fulfilled') brief.value = briefRes.value || {};
  return true;
}

onMounted(async () => {
  try {
    if (!(await loadCustomer())) return;

    const [fieldsRes, valuesRes, settingsRes] = await Promise.allSettled([
      http.get('custom-fields', { entity_type: 'client' }),
      http.get('custom-field-values', { entity_type: 'App\\Models\\Client', entity_id: id }),
      http.get('get_Settings_data'),
    ]);

    if (fieldsRes.status === 'fulfilled' && valuesRes.status === 'fulfilled') {
      const values = valuesRes.value?.success && valuesRes.value?.values ? valuesRes.value.values : {};
      customFields.value = (fieldsRes.value?.custom_fields || []).map(f => ({
        id: f.id, name: f.name, field_type: f.field_type,
        value: values[f.id] ? values[f.id].value : null,
      }));
    }
    if (settingsRes.status === 'fulfilled' && settingsRes.value?.setting) {
      const s = settingsRes.value.setting;
      company.value = {
        CompanyName: s.CompanyName || '', CompanyAdress: s.CompanyAdress || '', CompanyPhone: s.CompanyPhone || '',
      };
    }

    panes.value.forEach(p => p.crud.fetchRows());
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
/* Customer profile banner ---------------------------------------------- */
.ledger-hero {
  border: 1px solid rgba(22, 119, 255, 0.18);
  background: linear-gradient(120deg, rgba(22, 119, 255, 0.08), rgba(114, 46, 209, 0.05));
}
.hero-main {
  display: flex;
  align-items: center;
  gap: 18px;
}
.hero-avatar {
  flex: 0 0 auto;
  background: #1677ff;
  color: #fff;
  font-size: 22px;
  font-weight: 600;
}
.hero-info { min-width: 0; }
.hero-name-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.hero-name {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
}
.hero-code { margin: 0; }
.hero-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin-top: 8px;
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.6);
}
.meta-chip :deep(svg) { color: rgba(0, 0, 0, 0.35); }

/* KPI tiles ------------------------------------------------------------- */
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
  /* allow up to two lines rather than clipping the label */
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
  .hero-main { gap: 12px; }
  .hero-name { font-size: 18px; }
  .kpi-value { font-size: 16px; }
}

/* Email conversation: the preview under the subject -- 2 lines, wrapping
   inside the column (never stretching it). */
.email-snippet {
  font-size: 12px;
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
.thread-msg.unread .thread-text {
  font-weight: 600;
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

.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.due-open {
  color: #ff4d4f;
  font-weight: 600;
}
</style>
