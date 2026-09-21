<template>
  <div class="page">
    <PageHeader :title="$t('Customer_details')" :breadcrumb="[$t('Customers'), $t('Customer_details')]">
      <template #actions>
        <a-button @click="$router.push('/customers')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
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
      <a-card size="small" style="margin-bottom: 16px">
        <a-descriptions :title="client.name" :column="{ xs: 1, sm: 2, lg: 3 }" size="small">
          <a-descriptions-item :label="$t('Code')">{{ client.code }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Email')">{{ client.email || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Phone')">{{ client.phone || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('City')">{{ client.city || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Country')">{{ client.country || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Tax_Number')">{{ client.tax_number || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Credit_Limit')">{{ creditLimitText }}</a-descriptions-item>
        </a-descriptions>
      </a-card>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic
              :title="$t('Opening_Balance')" :value="money(openingBalance)"
              :value-style="{ color: openingBalance > 0 ? '#ff4d4f' : '#52c41a' }"
            />
            <div class="muted">{{ $t('Previous_Dues') }}</div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic
              :title="$t('Sales_Due')" :value="money(salesDue)"
              :value-style="{ color: salesDue > 0 ? '#ff4d4f' : '#52c41a' }"
            />
            <div class="muted">{{ $t('Current_Sales') }}</div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic
              :title="$t('Service_Due')" :value="money(serviceDue)"
              :value-style="{ color: serviceDue > 0 ? '#ff4d4f' : '#52c41a' }"
            />
            <div class="muted">{{ $t('Open_Service_Jobs') }}</div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic :title="$t('Credit_Limit')" :value="creditLimitText" :value-style="{ color: '#1677ff' }" />
            <div class="muted">{{ $t('Maximum_credit_amount_allowed_for_this_customer') }}</div>
          </a-card>
        </a-col>
        <a-col v-if="walletEnabled" :xs="24" :md="8">
          <a-card size="small">
            <a-statistic
              :title="$t('WalletBalance')" :value="money(walletBalance)"
              :value-style="{ color: walletBalance < 0 ? '#ff4d4f' : '#52c41a' }"
            />
            <div class="muted">{{ $t('E_Wallet') }}</div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic :title="$t('Loyalty_Points')" :value="loyaltyPoints" :value-style="{ color: '#faad14' }" />
            <div class="muted">{{ $t('Points') }}</div>
          </a-card>
        </a-col>
      </a-row>

      <a-tabs v-model:activeKey="tab">
        <a-tab-pane v-for="pane in panes" :key="pane.key" :tab="$t(pane.label)">
          <a-card size="small">
            <a-input-search
              v-model:value="pane.state.search"
              :placeholder="$t('Search')"
              allow-clear style="max-width: 280px; margin-bottom: 12px"
              @search="() => reload(pane)"
            />
            <a-table
              :columns="pane.columns" :data-source="pane.state.rows" :loading="pane.state.loading"
              :pagination="{ current: pane.state.page, pageSize: pane.state.limit, total: pane.state.total, showSizeChanger: false }"
              size="middle" :row-key="r => r.id ?? r.Ref" :scroll="{ x: 'max-content' }"
              @change="p => { pane.state.page = p.current; fetchPane(pane); }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="MONEY_KEYS.includes(column.key)">
                  <span :class="dueClass(column.key, record)">{{ money(record[column.key]) }}</span>
                </template>
                <template v-else-if="column.key === 'payment_status'">
                  <a-tag :color="paymentStatusColor(record.payment_status)">{{ record.payment_status }}</a-tag>
                </template>
                <template v-else-if="column.key === 'payment_type'">
                  <a-tag :color="paymentTypeColor(record.payment_type)">
                    {{ paymentTypeLabel(record.payment_type) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'statut' && pane.key === 'service_jobs'">
                  <a-tag :color="jobStatusColor(record.statut)">{{ record.statut }}</a-tag>
                  <a-tooltip v-if="!record.counts_toward_due" :title="$t('Not_Counted_In_Due')">
                    <a-tag color="default">{{ $t('Not_Counted_In_Due') }}</a-tag>
                  </a-tooltip>
                </template>
                <template v-else-if="column.key === 'Ref' && pane.key === 'service_jobs'">
                  <router-link :to="`/service/jobs/details/${record.id}`">{{ record.Ref }}</router-link>
                </template>
                <template v-else-if="column.key === 'Sale_Ref'">
                  <span v-if="record.Sale_Ref">{{ record.Sale_Ref }}</span>
                  <span v-else class="muted">-</span>
                </template>
              </template>
              <template #emptyText>
                <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
              </template>
            </a-table>
          </a-card>
        </a-tab-pane>
      </a-tabs>

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
 * Customer details — legacy CustomerDetails.vue. Header + figures, five
 * independently paginated tabs (sales / service jobs / payments / returns /
 * payment returns), custom fields, and the pay-due flow with its printable
 * credit note. Service jobs are part of what the customer owes: the brief
 * endpoint returns service_due (accepted / in-progress / delivered jobs only)
 * and clients_pay_due allocates to open jobs after sales.
 *
 * TWO legacy defects are deliberately not reproduced, both of which mislead
 * about money:
 *
 * 1. Sales Due was summed from the LOADED PAGE of the sales table, so a
 *    customer with more than ten sales showed too small a due — and that same
 *    figure capped the pay-due amount and decided whether the Pay Due button
 *    appeared at all. The true aggregate comes from GET clients/{id}/brief
 *    (sale_due / netBalance), the endpoint the ledger page already uses, so
 *    this needs no new permission.
 * 2. The payment-status badge tested `includes('paid')` before `'unpaid'`,
 *    and "unpaid" contains "paid" — every unpaid row rendered green. Ordering
 *    the check on 'unpaid' first fixes it.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { LeftOutlined, DollarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PayDueModal from '../../components/PayDueModal.vue';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money, roundMoney } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const id = route.params.id;

const loading = ref(true);
const tab = ref('sales');
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
/** Opening balance + sales due + service due. netBalance also nets off return dues. */
// Rounded: the raw float sum is the :max of the paying-amount input, and the
// clamp would otherwise display something like 121.99999999999994.
const totalDue = computed(() => roundMoney(openingBalance.value + salesDue.value + serviceDue.value));
const creditLimitText = computed(() =>
  Number(client.value.credit_limit) > 0 ? money(client.value.credit_limit) : t('No_limit'));
const walletEnabled = computed(() => !!brief.value.wallet_enabled);
const walletBalance = computed(() => Number(brief.value.wallet_balance) || 0);
const loyaltyPoints = computed(() => Number(brief.value.points ?? client.value.points) || 0);

function paymentStatusColor(status) {
  const s = String(status || '').toLowerCase();
  // 'unpaid' contains 'paid' — test it first or unpaid renders green.
  if (s.includes('unpaid')) return 'error';
  if (s.includes('partial')) return 'warning';
  if (s.includes('paid')) return 'success';
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

const makeState = () => reactive({ rows: [], total: 0, page: 1, limit: 10, search: '', loading: false });

const panes = computed(() => [
  {
    key: 'sales', label: 'Sales', endpoint: 'sales_client', rowsKey: 'sales', state: states.sales,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
      { title: t('Grand_Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right' },
      { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right' },
      { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
      { title: t('Payment_Status'), dataIndex: 'payment_status', key: 'payment_status' },
      { title: t('Status'), dataIndex: 'statut', key: 'statut' },
    ],
  },
  {
    key: 'service_jobs', label: 'Service_Jobs', endpoint: 'service_jobs_client', rowsKey: 'service_jobs',
    state: states.service_jobs,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Service_Item'), dataIndex: 'service_item', key: 'service_item' },
      { title: t('Technician'), dataIndex: 'technician_name', key: 'technician_name' },
      { title: t('Grand_Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right' },
      { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right' },
      { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
      { title: t('Payment_Status'), dataIndex: 'payment_status', key: 'payment_status' },
      { title: t('Status'), dataIndex: 'statut', key: 'statut' },
    ],
  },
  {
    key: 'payments', label: 'Payments', endpoint: 'payments_client', rowsKey: 'payments', state: states.payments,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Type'), dataIndex: 'payment_type', key: 'payment_type' },
      { title: t('Document_Ref'), dataIndex: 'Sale_Ref', key: 'Sale_Ref' },
      { title: t('Payment_Method'), dataIndex: 'payment_method', key: 'payment_method' },
      { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right' },
    ],
  },
  {
    key: 'returns', label: 'Returns', endpoint: 'returns_client', rowsKey: 'returns', state: states.returns,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Sale_Ref'), dataIndex: 'sale_ref', key: 'sale_ref' },
      { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
      { title: t('Grand_Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right' },
      { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right' },
      { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
      { title: t('Payment_Status'), dataIndex: 'payment_status', key: 'payment_status' },
      { title: t('Status'), dataIndex: 'statut', key: 'statut' },
    ],
  },
  {
    key: 'payment_returns', label: 'Payment_Returns', endpoint: 'payment_returns_client',
    rowsKey: 'payment_returns', state: states.payment_returns,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Sale_Return_Ref'), dataIndex: 'Sale_Return_Ref', key: 'Sale_Return_Ref' },
      { title: t('Payment_Method'), dataIndex: 'payment_method', key: 'payment_method' },
      { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right' },
    ],
  },
]);

const states = {
  sales: makeState(), service_jobs: makeState(), payments: makeState(), returns: makeState(), payment_returns: makeState(),
};

async function fetchPane(pane) {
  const s = pane.state;
  s.loading = true;
  try {
    const data = await http.get(pane.endpoint, {
      id, page: s.page, limit: s.limit, search: s.search,
    });
    s.rows = data?.[pane.rowsKey] || [];
    s.total = Number(data?.totalRows) || 0;
  } catch (e) {
    s.rows = [];
  } finally {
    s.loading = false;
  }
}
/** A new search starts from page 1 — legacy kept the old page and showed nothing. */
function reload(pane) {
  pane.state.page = 1;
  fetchPane(pane);
}

// ---------------- pay due ----------------

const payModal = ref();

function openPayDue() {
  payModal.value?.open();
}

async function onPaid() {
  await loadCustomer();
  panes.value.forEach(fetchPane);
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

    panes.value.forEach(fetchPane);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.due-open {
  color: #ff4d4f;
  font-weight: 600;
}
</style>
