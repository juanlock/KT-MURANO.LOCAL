<template>
  <div class="page">
    <PageHeader :title="$t('Online_Orders')" :breadcrumb="[$t('Store'), $t('Online_Orders')]">
      <template #extra>
        <a-button @click="guideOpen = true">
          <template #icon><BookOutlined /></template>
          {{ $t('Orders_Guide') }}
        </a-button>
        <a-button @click="clearFilters">
          <template #icon><ReloadOutlined /></template>
          {{ $t('Clear') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      v-if="errorTitle || errors.length"
      type="error" show-icon closable style="margin-bottom: 16px"
      :message="errorTitle || $t('Failed')"
      @close="clearErrors"
    >
      <template v-if="errors.length" #description>
        <ul style="margin: 0; padding-left: 18px">
          <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
        </ul>
      </template>
    </a-alert>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]">
        <a-col :xs="24" :md="5">
          <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear @search="reload" />
        </a-col>
        <a-col :xs="12" :md="5">
          <a-select v-model:value="status" style="width: 100%" @change="reload">
            <a-select-option value="">{{ $t('All_Status') }}</a-select-option>
            <a-select-option value="pending">{{ $t('pending') }}</a-select-option>
            <a-select-option value="confirmed">{{ $t('confirmed') }}</a-select-option>
            <a-select-option value="cancelled">{{ $t('cancelled') }}</a-select-option>
          </a-select>
        </a-col>
        <a-col :xs="12" :md="5">
          <a-select v-model:value="proofFilter" style="width: 100%" @change="reload">
            <a-select-option value="">{{ $t('All') }} {{ $t('Payments') }}</a-select-option>
            <a-select-option value="pending">{{ $t('AwaitingVerification') }}</a-select-option>
          </a-select>
        </a-col>
        <a-col :xs="12" :md="5">
          <a-select v-model:value="preorderFilter" style="width: 100%" @change="reload">
            <a-select-option value="">{{ $t('All') }} {{ $t('Orders') }}</a-select-option>
            <a-select-option value="yes">{{ $t('HasPreorderItems') }}</a-select-option>
            <a-select-option value="no">{{ $t('No') }} {{ $t('PreOrder') }}</a-select-option>
          </a-select>
        </a-col>
        <a-col :xs="12" :md="4">
          <a-input v-model:value="dateFrom" type="date" @change="reload" />
        </a-col>
        <a-col :xs="12" :md="4">
          <a-input v-model:value="dateTo" type="date" @change="reload" />
        </a-col>
      </a-row>
    </a-card>

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="pagination" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('NodataAvailable') }"
        @change="onTableChange"
      >
        <template #headerCell="{ column }">
          <template v-if="column.key === 'payment_status'">
            {{ column.title }}
            <a-tooltip :title="$t('Ord_Guide_PaymentStatus_Tip')">
              <QuestionCircleOutlined class="head-help" @click.stop="guideOpen = true" />
            </a-tooltip>
          </template>
          <template v-else>{{ column.title }}</template>
        </template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
            <a-tag v-if="record.has_preorder_items" color="warning">{{ $t('PreOrder') }}</a-tag>
            <a-tooltip v-if="record.is_flagged" :title="record.flag_reason">
              <a-tag color="error"><FlagOutlined /> {{ $t('Flagged') }}</a-tag>
            </a-tooltip>
          </template>
          <template v-else-if="column.key === 'payment_method'">
            <a-tag :color="paymentMethodColor(record.payment_method)">{{ paymentMethodLabel(record.payment_method) }}</a-tag>
            <a-tag v-if="record.delivery_method === 'pickup'" color="orange" style="margin-inline-start: 4px">
              {{ $t('Pickup') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'payment_status'">
            <a-tag :color="record.payment_status === 'paid' ? 'success' : 'warning'">{{ record.payment_status || 'pending' }}</a-tag>
            <a-tag v-if="record.has_pending_proof" color="processing" style="margin-inline-start: 4px">
              {{ $t('ProofToVerify') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'total'">
            {{ currency(record.total) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <template v-if="record.status === 'pending'">
                <a-button size="small" type="primary" :loading="actionBusyId === record.id" @click="confirmOrder(record)">
                  {{ $t('Confirm') }}
                </a-button>
                <a-button size="small" danger :loading="actionBusyId === record.id" @click="cancelOrder(record)">
                  {{ $t('Cancel') }}
                </a-button>
              </template>
              <a-button v-if="record.status === 'confirmed'" size="small" :loading="actionBusyId === record.id" @click="changeStatus(record, 'shipped')">
                {{ $t('Mark_Shipped') }}
              </a-button>
              <a-button v-if="record.status === 'shipped'" size="small" :loading="actionBusyId === record.id" @click="changeStatus(record, 'delivered')">
                {{ $t('Mark_Delivered') }}
              </a-button>
              <a-tooltip :title="$t('Details')">
                <a-button size="small" @click="$router.push(`/store/orders/${record.id}`)">
                  <template #icon><EyeOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="confirmDlg.open"
      :title="$t('Confirm_this_order_Q')"
      :ok-text="$t('Confirm')"
      :cancel-text="$t('Cancel')"
      :confirm-loading="confirmDlg.busy"
      :ok-button-props="{ disabled: confirmDlg.loading || !confirmDlg.warehouseId }"
      @ok="submitConfirm"
    >
      <p style="margin-bottom: 12px">{{ $t('Confirm_to_sale_hint') }}</p>
      <a-spin :spinning="confirmDlg.loading">
        <a-form layout="vertical">
          <a-form-item :label="$t('warehouse')">
            <a-select
              v-model:value="confirmDlg.warehouseId"
              show-search option-filter-prop="label" style="width: 100%"
            >
              <a-select-option
                v-for="w in confirmDlg.warehouses" :key="w.id" :value="w.id" :label="w.name"
              >
                {{ w.name }}
                <a-tag :color="w.can_fulfil ? 'success' : 'error'" style="margin-left: 8px">
                  {{ w.can_fulfil ? $t('Available') : $t('Out_of_Stock') }}
                </a-tag>
              </a-select-option>
            </a-select>
          </a-form-item>
        </a-form>
        <a-alert
          v-if="confirmShortages.length"
          type="warning" show-icon
          :message="$t('LowStockAlert')"
        >
          <template #description>
            <div v-for="(s, i) in confirmShortages" :key="i">
              {{ s.name }} — {{ $t('Qty') }}: {{ s.required }} / {{ $t('Available') }}: {{ s.available }}
            </div>
          </template>
        </a-alert>
      </a-spin>
    </a-modal>

    <!-- How online orders work: lifecycle + where payment is actually recorded.
         Opened from the header button and from the Payment Status column help. -->
    <a-drawer
      v-model:open="guideOpen"
      :title="$t('Orders_Guide')"
      placement="right"
      width="min(560px, 100vw)"
      class="orders-guide"
    >
      <p class="guide-intro">{{ $t('Ord_Guide_Intro') }}</p>

      <div class="guide-section">
        <div class="guide-title">
          <ShoppingCartOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Ord_Guide_Lifecycle_Title') }}
        </div>
        <ul class="guide-list">
          <li v-for="s in lifecycle" :key="s.key">
            <a-tag :color="statusColor(s.key)" class="guide-tag">{{ s.key }}</a-tag>
            <span>{{ $t(s.text) }}</span>
          </li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <CreditCardOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Ord_Guide_Payment_Title') }}
        </div>
        <p class="guide-intro">{{ $t('Ord_Guide_Payment_Intro') }}</p>
        <ul class="guide-list">
          <li>
            <a-tag color="success" class="guide-tag">paid</a-tag>
            <span>{{ $t('Ord_Guide_Payment_Paid') }}</span>
          </li>
          <li>
            <a-tag color="warning" class="guide-tag">pending</a-tag>
            <span>{{ $t('Ord_Guide_Payment_Pending') }}</span>
          </li>
          <li>
            <a-tag color="error" class="guide-tag">failed</a-tag>
            <span>{{ $t('Ord_Guide_Payment_Failed') }}</span>
          </li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <DollarOutlined :style="{ color: token.colorSuccess }" />
          {{ $t('Ord_Guide_MarkPaid_Title') }}
        </div>
        <a-steps direction="vertical" size="small" :current="-1" class="guide-steps">
          <a-step v-for="(k, i) in markPaidSteps" :key="i" :title="$t(k)" />
        </a-steps>
        <a-alert type="info" show-icon :message="$t('Ord_Guide_MarkPaid_Note')" />
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <InfoCircleOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Ord_Guide_Notes_Title') }}
        </div>
        <ul class="guide-list">
          <li v-for="k in notes" :key="k">
            <ExclamationCircleOutlined :style="{ color: token.colorWarning }" />
            <span>{{ $t(k) }}</span>
          </li>
        </ul>
      </div>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Online orders — GET store/orders?page&per_page&sort&dir&q&status&preorder
 * &from&to → {data, meta{total}} (business errors can ride in the payload:
 * {error, items[{name, required, available}]} — legacy's error parsing is
 * kept: title + per-item shortage lines shown in a dismissible alert).
 * Status transitions PATCH store/orders/{id} {status}: pending → confirmed
 * (creates a Sale + deducts stock, hence the stock-shortage errors) or
 * cancelled; confirmed → shipped → delivered.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal, theme } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ReloadOutlined, EyeOutlined, FlagOutlined, BookOutlined, QuestionCircleOutlined,
  ShoppingCartOutlined, CreditCardOutlined, DollarOutlined, InfoCircleOutlined,
  ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { PAGE_SIZE_OPTIONS, buildPageSizeOptionText } from '../../composables/useCrudTable';

const { t } = useI18n();
const { token } = theme.useToken();
const auth = useAuthStore();

const isLoading = ref(true);
const rows = ref([]);
const total = ref(0);
const errorTitle = ref('');
const errors = ref([]);
const search = ref('');
const status = ref('');
const proofFilter = ref('');
const preorderFilter = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const actionBusyId = ref(null);
const page = ref(1);
const perPage = ref(10);
const sort = ref({ field: 'created_at', dir: 'desc' });

/* ---------- Guide ---------- */
const guideOpen = ref(false);
const lifecycle = [
  { key: 'pending', text: 'Ord_Guide_Lifecycle_Pending' },
  { key: 'confirmed', text: 'Ord_Guide_Lifecycle_Confirmed' },
  { key: 'shipped', text: 'Ord_Guide_Lifecycle_Shipped' },
  { key: 'delivered', text: 'Ord_Guide_Lifecycle_Delivered' },
  { key: 'cancelled', text: 'Ord_Guide_Lifecycle_Cancelled' },
];
const markPaidSteps = [
  'Ord_Guide_MarkPaid_Step1',
  'Ord_Guide_MarkPaid_Step2',
  'Ord_Guide_MarkPaid_Step3',
  'Ord_Guide_MarkPaid_Step4',
];
const notes = [
  'Ord_Guide_Note_Warehouse',
  'Ord_Guide_Note_Preorder',
  'Ord_Guide_Note_Flagged',
  'Ord_Guide_Note_Cancel',
];

const columns = computed(() => [
  { title: t('Order'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Customer'), dataIndex: 'customer_name', key: 'customer_name', sorter: true },
  { title: t('Status'), key: 'status' },
  { title: t('PaymentMethod'), key: 'payment_method' },
  { title: t('PaymentStatus'), key: 'payment_status' },
  { title: t('Total'), key: 'total', align: 'right', sorter: true },
  { title: t('date'), dataIndex: 'created_at', key: 'created_at', sorter: true },
  { title: t('Actions'), key: 'actions', width: 260 },
]);
const pagination = computed(() => ({
  current: page.value,
  pageSize: perPage.value,
  total: total.value,
  showSizeChanger: true,
  pageSizeOptions: PAGE_SIZE_OPTIONS,
  buildOptionText: buildPageSizeOptionText,
}));

function currency(n) {
  const code = auth.currency;
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).format(n || 0);
  } catch (e) {
    return `${code} ${Number(n || 0).toFixed(2)}`;
  }
}
function statusColor(s) {
  return { pending: 'warning', confirmed: 'success', shipped: 'cyan', delivered: 'blue', cancelled: 'error' }[s] || 'default';
}
function paymentMethodLabel(m) {
  return {
    credit_card: t('CreditCard'), paypal: 'PayPal', paystack: 'Paystack', flutterwave: 'Flutterwave',
    razorpay: 'Razorpay', bkash: 'bKash', sslcommerz: 'SSLCommerz', mobile_money: t('MobileMoney'), cod: t('CashOnDelivery'),
    gcash: t('GCash'), bank_transfer: t('BankTransfer'), cash_on_pickup: t('CashOnPickup'),
  }[m] || m || 'N/A';
}
function paymentMethodColor(m) {
  return {
    credit_card: 'blue', paypal: 'geekblue', paystack: 'cyan', flutterwave: 'orange', razorpay: 'blue',
    bkash: 'magenta', sslcommerz: 'geekblue',
    mobile_money: 'cyan', cod: 'default', gcash: 'blue', bank_transfer: 'geekblue', cash_on_pickup: 'orange',
  }[m] || 'default';
}

/* ---------- Legacy error parsing ---------- */
function clearErrors() {
  errorTitle.value = '';
  errors.value = [];
}
function stripHtml(s) {
  try { return String(s).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(); } catch (e) { return String(s); }
}
function flattenErrors(e) {
  const out = [];
  if (!e) return out;
  if (Array.isArray(e)) e.forEach(x => x && out.push(String(x)));
  else if (typeof e === 'object') {
    Object.values(e).forEach(v => {
      if (Array.isArray(v)) v.forEach(x => x && out.push(String(x)));
      else if (v) out.push(String(v));
    });
  } else if (typeof e === 'string') out.push(e);
  return [...new Set(out.map(s => String(s).trim()).filter(Boolean))];
}
function parseItems(items) {
  if (!Array.isArray(items)) return [];
  return items.map(x => {
    const name = x?.name || `#${x?.product_id ?? ''}`;
    const need = x?.required !== undefined ? x.required : '-';
    const have = x?.available !== undefined ? x.available : '-';
    return `${name}: Required ${need} — Available ${have}`;
  });
}
function parsePayload(data) {
  let title = '';
  let list = [];
  if (!data) return { title, list };
  if (typeof data === 'string') {
    const txt = stripHtml(data);
    if (txt && txt.toLowerCase() !== 'validation failed') title = txt;
    return { title, list };
  }
  if (data.errors) {
    title = typeof data.message === 'string' && data.message.toLowerCase() !== 'validation failed'
      ? stripHtml(data.message) : t('Validation_failed');
    return { title, list: flattenErrors(data.errors) };
  }
  if (typeof data.error === 'string' && data.error.trim()) title = data.error.trim();
  if (data.items) list = parseItems(data.items);
  if (!list.length && Array.isArray(data.messages)) list = data.messages.filter(Boolean).map(String);
  if (!list.length && data.details) {
    list = Array.isArray(data.details) ? data.details.filter(Boolean).map(String) : [String(data.details)];
  }
  if (!title && typeof data.message === 'string') {
    const msg = stripHtml(data.message);
    if (msg.toLowerCase() !== 'validation failed') title = msg;
  }
  return { title, list: [...new Set(list.map(s => String(s).trim()).filter(Boolean))] };
}
function showError(data) {
  const { title, list } = parsePayload(data);
  errorTitle.value = title || t('Failed');
  errors.value = list;
}

/* ---------- Data ---------- */
async function fetch() {
  isLoading.value = true;
  clearErrors();
  try {
    const data = await http.get('store/orders', {
      page: page.value,
      per_page: perPage.value,
      sort: sort.value.field,
      dir: sort.value.dir,
      q: search.value || '',
      status: status.value || '',
      proof: proofFilter.value || '',
      preorder: preorderFilter.value || '',
      from: dateFrom.value || '',
      to: dateTo.value || '',
    });
    // business error riding in a 200 payload (legacy looksError check)
    if (data && (data.errors || data.items || (typeof data.error === 'string' && data.error.trim()))) {
      showError(data);
    } else {
      rows.value = (data.data || data.rows) || [];
      total.value = data.meta?.total || rows.value.length;
    }
  } catch (e) {
    showError(e?.data);
  } finally {
    isLoading.value = false;
  }
}
function reload() {
  page.value = 1;
  fetch();
}
function onTableChange(pag, _f, sorter) {
  page.value = pag.current;
  perPage.value = pag.pageSize;
  if (sorter && sorter.field) {
    sort.value = { field: sorter.field, dir: sorter.order === 'ascend' ? 'asc' : 'desc' };
  }
  fetch();
}
function clearFilters() {
  search.value = '';
  status.value = '';
  proofFilter.value = '';
  preorderFilter.value = '';
  dateFrom.value = '';
  dateTo.value = '';
  clearErrors();
  reload();
}

/* ---------- Actions ---------- */
async function patchStatus(row, newStatus, successMsg) {
  actionBusyId.value = row.id;
  try {
    await http.patch(`store/orders/${row.id}`, { status: newStatus });
    row.status = newStatus;
    clearErrors();
    message.success(successMsg);
  } catch (e) {
    showError(e?.data);
  } finally {
    actionBusyId.value = null;
  }
}
/* Confirm dialog: the admin picks the warehouse the sale is booked under;
 * options come from GET store/orders/{id}/confirm-options with per-warehouse
 * availability for this order's items. */
const confirmDlg = ref({ open: false, row: null, warehouseId: null, warehouses: [], loading: false, busy: false });
const confirmShortages = computed(() => {
  const w = confirmDlg.value.warehouses.find(x => x.id === confirmDlg.value.warehouseId);
  return w && !w.can_fulfil ? (w.shortages || []) : [];
});

async function confirmOrder(row) {
  if (!row || row.status !== 'pending') return;
  confirmDlg.value = { open: true, row, warehouseId: null, warehouses: [], loading: true, busy: false };
  try {
    const data = await http.get(`store/orders/${row.id}/confirm-options`);
    const list = Array.isArray(data?.warehouses) ? data.warehouses : [];
    confirmDlg.value.warehouses = list;
    // Default: the order's booked warehouse when it can fulfil, else the
    // first warehouse that can, else the booked one anyway.
    const booked = list.find(w => w.id === Number(data?.order_warehouse_id));
    const firstOk = list.find(w => w.can_fulfil);
    confirmDlg.value.warehouseId = ((booked && booked.can_fulfil) ? booked : (firstOk || booked || list[0]))?.id ?? null;
  } catch (e) {
    confirmDlg.value.open = false;
    showError(e?.data);
  } finally {
    confirmDlg.value.loading = false;
  }
}
async function submitConfirm() {
  const d = confirmDlg.value;
  if (!d.row || !d.warehouseId) return;
  d.busy = true;
  try {
    await http.patch(`store/orders/${d.row.id}`, { status: 'confirmed', warehouse_id: d.warehouseId });
    d.row.status = 'confirmed';
    clearErrors();
    message.success(t('Order_confirmed'));
    d.open = false;
  } catch (e) {
    // Keep the dialog open so another warehouse can be picked; toast the
    // reason since the page-level alert sits behind the modal.
    message.error(stripHtml(e?.data?.error || '') || t('Failed'));
    showError(e?.data);
  } finally {
    d.busy = false;
  }
}
function cancelOrder(row) {
  if (!row || row.status !== 'pending') return;
  Modal.confirm({
    title: t('Cancel_this_order_Q'),
    content: t('Cancel_order_hint'),
    okText: t('Cancel'),
    okType: 'danger',
    cancelText: t('Keep'),
    onOk: () => patchStatus(row, 'cancelled', t('Order_cancelled')),
  });
}
function changeStatus(row, newStatus) {
  patchStatus(row, newStatus, t('Status_updated'));
}

// The pending-work bell links here as /store/orders?proof=pending.
const route = useRoute();
onMounted(() => {
  if (route.query.proof === 'pending') proofFilter.value = 'pending';
  fetch();
});
</script>

<style scoped>
.head-help {
  margin-inline-start: 4px;
  cursor: pointer;
  opacity: 0.55;
}
.head-help:hover {
  opacity: 1;
}

.guide-section + .guide-section {
  margin-top: 4px;
}

.guide-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 10px;
}

.guide-intro {
  font-size: 13px;
  line-height: 1.6;
  margin: 0 0 10px;
  opacity: 0.85;
}

.guide-divider {
  margin: 16px 0;
}

.guide-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.guide-list li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 5px 0;
  line-height: 1.6;
  font-size: 13px;
}

.guide-list li .anticon {
  margin-top: 4px;
  flex-shrink: 0;
}

/* Keep the status chips in one column so the sentences line up. */
.guide-tag {
  flex-shrink: 0;
  min-width: 82px;
  margin: 1px 0 0;
  text-align: center;
}

.guide-steps {
  margin-bottom: 12px;
}

.guide-steps :deep(.ant-steps-item-title) {
  font-size: 13px;
  line-height: 1.6;
  white-space: normal;
}

/* Every step is "wait" (nothing is done yet), which antd greys out — the
   numbered circles are the point here, so keep the text readable. */
.guide-steps :deep(.ant-steps-item-wait .ant-steps-item-title) {
  color: inherit;
}
</style>
