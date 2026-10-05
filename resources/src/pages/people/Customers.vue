<template>
  <div class="page">
    <PageHeader :title="$t('Customers')" :breadcrumb="[$t('People'), $t('Customers')]">
      <template #actions>
        <a-space wrap :size="12">
          <!-- Date buttons: Total Sales / # Sales / Due / Return Due count only
               documents dated in the range; every customer stays listed. -->
          <DateRangeFields :value="rangeFields" @change="onRangeEdit" />
          <DatePresetButtons v-model:value="datePreset" @change="onDatePreset" />
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            EXCEL
          </a-button>
          <a-button v-if="auth.can('Customers_add')" type="primary" @click="$router.push('/customers/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Summary cards — real global totals across ALL customers (a separate
         unpaginated fetch), not just the loaded page. One row on desktop
         (however many cards the feature switches leave), wrapping on mobile. -->
    <a-row :gutter="[16, 16]" class="stat-row" style="margin-bottom: 16px">
      <a-col :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(109, 40, 217, 0.12); color: #6d28d9"><TeamOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Total_Customers') }}</div>
              <div class="stat-value">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ customerCount }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(82, 196, 26, 0.12); color: #52c41a"><DollarOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Total_Sales') }}</div>
              <div class="stat-value">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ money(totals.sales) }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(255, 77, 79, 0.12); color: #ff4d4f"><DollarOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Total_Due') }}</div>
              <div class="stat-value" :style="{ color: totals.due > 0 ? '#ff4d4f' : undefined }">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ money(totals.due) }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(250, 173, 20, 0.14); color: #faad14"><RollbackOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Total_Sell_Return_Due') }}</div>
              <div class="stat-value" :style="{ color: totals.returnDue > 0 ? '#faad14' : undefined }">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ money(totals.returnDue) }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
      <a-col v-if="auth.customerCreditLimitEnabled" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(22, 119, 255, 0.12); color: #1677ff"><CreditCardOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Credit_Limit') }}</div>
              <div class="stat-value">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ money(totals.credit) }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <!-- Name opens the customer's details; Total Sales / # Sales / Due open
             the Sales list filtered to exactly the sales behind that number. -->
        <template v-if="column.key === 'name'">
          <a @click="router.push(`/customers/${record.id}/details`)">{{ record.name }}</a>
        </template>
        <template v-else-if="column.key === 'total_amount'">
          <a @click="openSales(record)">{{ money(record.total_amount) }}</a>
        </template>
        <template v-else-if="column.key === 'sales_count'">
          <a @click="openSales(record)">{{ record.sales_count || 0 }}</a>
        </template>
        <template v-else-if="column.key === 'contracts_count'">
          <a v-if="record.contracts_count" @click="router.push(`/customers/${record.id}/details?tab=contracts`)">{{ record.contracts_count }}</a>
          <span v-else style="opacity: 0.45">0</span>
        </template>
        <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
        <template v-else-if="column.key === 'due'">
          <a v-if="Number(record.due) > 0" @click="openSales(record, true)">
            <strong style="color: #ff4d4f">{{ money(record.due) }}</strong>
          </a>
          <strong v-else>{{ money(record.due) }}</strong>
        </template>
        <template v-else-if="column.key === 'service_due'">
          <strong :style="{ color: Number(record.service_due) > 0 ? '#ff4d4f' : undefined }">
            {{ money(record.service_due) }}
          </strong>
        </template>
        <template v-else-if="column.key === 'return_Due'">
          <strong :style="{ color: Number(record.return_Due) > 0 ? '#faad14' : undefined }">
            {{ money(record.return_Due) }}
          </strong>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-dropdown :trigger="['click']">
            <a-button type="text" size="small">
              <template #icon><MoreOutlined style="font-size: 18px" /></template>
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <a-menu-item
                  v-if="record.client_ecommerce === 'yes' && auth.can('Customers_edit')"
                  key="online-store"
                >
                  <ShopOutlined /> {{ $t('Edit_Online_Store_Account') }}
                </a-menu-item>
                <!-- Opening balance and open service jobs count: clients_pay_due
                     allocates opening balance first, then sales, then service jobs,
                     same as the details page's Pay Due button. -->
                <a-menu-item
                  v-if="(Number(record.due_all ?? record.due) || 0) + (Number(record.service_due) || 0) + (Number(record.opening_balance) || 0) > 0 && auth.can('pay_due')"
                  key="pay-due"
                >
                  <DollarOutlined /> {{ $t('pay_all_sell_due_at_a_time') }}
                </a-menu-item>
                <a-menu-item v-if="Number(record.return_due_all ?? record.return_Due) > 0 && auth.can('pay_sale_return_due')" key="pay-return">
                  <DollarOutlined /> {{ $t('pay_all_sell_return_due_at_a_time') }}
                </a-menu-item>
                <a-menu-item key="details">
                  <EyeOutlined /> {{ $t('Customer_details') }}
                </a-menu-item>
                <a-menu-item v-if="auth.customerLoyaltyEnabled" key="points">
                  <StarOutlined /> {{ $t('Adjust_Customer_Points') }}
                </a-menu-item>
                <a-menu-item v-if="auth.customerOpeningBalanceEnabled" key="opening-balance">
                  <CalculatorOutlined /> {{ $t('Adjust_Opening_Balance') }}
                </a-menu-item>
                <a-menu-item key="portal">
                  <KeyOutlined /> {{ $t('Portal_Client') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Customers_edit')" key="edit">
                  <EditOutlined /> {{ $t('Edit_Customer') }}
                </a-menu-item>
                <a-menu-divider v-if="auth.can('Customers_delete')" />
                <a-menu-item v-if="auth.can('Customers_delete')" key="delete" danger>
                  <DeleteOutlined /> {{ $t('Delete_Customer') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>

    <!-- Pay due — the same modal + printable credit note as the details page -->
    <PayDueModal
      ref="payDueModal"
      :client="payTarget"
      :opening-balance="Number(payTarget?.opening_balance) || 0"
      :sales-due="Number(payTarget?.due_all ?? payTarget?.due) || 0"
      :service-due="Number(payTarget?.service_due) || 0"
      :payment-methods="crud.payload.value?.payment_methods || []"
      :accounts="crud.payload.value?.accounts || []"
      :company="crud.payload.value?.company_info || {}"
      @paid="onPaid"
    />

    <!-- Pay return due -->
    <a-modal
      v-model:open="payOpen"
      :title="$t('Total_Sell_Return_Due')"
      :confirm-loading="paying"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitPay"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Customer')">
          <a-input :value="payTarget?.name" disabled />
        </a-form-item>
        <a-form-item :label="$t('Amount')" required>
          <a-input-number v-model:value="payForm.amount" style="width: 100%" :min="0" :max="payMax" />
          <a-typography-text type="secondary" style="font-size: 12px">
            {{ $t('Total_Sell_Return_Due') }}: {{ money(payMax) }}
          </a-typography-text>
        </a-form-item>
        <a-form-item :label="$t('ModePaiement')">
          <a-select v-model:value="payForm.payment_method_id" :options="opts('payment_methods')" allow-clear />
        </a-form-item>
        <a-form-item :label="$t('Account')">
          <a-select v-model:value="payForm.account_id" :options="opts('accounts')" allow-clear />
        </a-form-item>
        <a-form-item :label="$t('Note')">
          <a-textarea v-model:value="payForm.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Adjust customer points -->
    <a-modal
      v-if="auth.customerLoyaltyEnabled"
      v-model:open="pointsOpen"
      :title="$t('Adjust_Customer_Points')"
      :confirm-loading="pointsSaving"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="savePoints"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Customer')">
          <a-input :value="pointsForm.customer_name" disabled />
        </a-form-item>
        <a-form-item :label="$t('Points')">
          <a-input-number v-model:value="pointsForm.points" style="width: 100%" :min="0" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Adjust opening balance -->
    <a-modal
      v-if="auth.customerOpeningBalanceEnabled"
      v-model:open="obOpen"
      :title="$t('Adjust_Opening_Balance')"
      :confirm-loading="obSaving"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitOpeningBalance"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Customer')">
          <a-input :value="obForm.customer_name" disabled />
        </a-form-item>
        <a-form-item :label="$t('Opening_Balance')">
          <a-input :value="money(obForm.current)" disabled />
        </a-form-item>
        <a-form-item :label="$t('Mode') || 'Mode'">
          <a-radio-group v-model:value="obForm.mode" button-style="solid">
            <a-radio-button value="add">{{ $t('Add') }}</a-radio-button>
            <a-radio-button value="subtract">{{ $t('Subtract') || 'Subtract' }}</a-radio-button>
            <a-radio-button value="set">{{ $t('Set') || 'Set' }}</a-radio-button>
          </a-radio-group>
        </a-form-item>
        <a-form-item :label="$t('Amount')" required>
          <a-input-number v-model:value="obForm.amount" style="width: 100%" :min="0" />
        </a-form-item>
        <a-alert
          v-if="obForm.amount !== null && obForm.amount !== ''"
          type="info" show-icon
          :message="`${$t('Opening_Balance')}: ${money(obForm.current)} → ${money(obPreview)}`"
        />
      </a-form>
    </a-modal>

    <!-- Portal client access -->
    <a-modal
      v-model:open="portalOpen"
      :title="$t('Portal_Client')"
      :confirm-loading="portalSending"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      :ok-button-props="{ disabled: portalForm.enabled && !(portalForm.email || '').trim() }"
      :width="480"
      @ok="submitPortal"
    >
      <a-spin :spinning="portalLoading">
        <div class="portal-modal">
          <!-- Customer header -->
          <div class="portal-head">
            <a-avatar :size="46" style="background: #1677ff; flex: 0 0 auto">
              <template #icon><UserOutlined /></template>
            </a-avatar>
            <div class="portal-head__text">
              <div class="portal-head__name">{{ portalForm.client_name }}</div>
              <div class="portal-head__sub">{{ $t('Customer') }}</div>
            </div>
          </div>

          <!-- Status / toggle card -->
          <div class="portal-status" :class="portalForm.enabled ? 'is-on' : 'is-off'">
            <div class="portal-status__left">
              <div class="portal-status__icon">
                <component :is="portalForm.enabled ? KeyOutlined : LockOutlined" />
              </div>
              <div class="portal-status__text">
                <div class="portal-status__title">
                  {{ portalForm.enabled ? $t('Portal_Enabled') : $t('Portal_Disabled') }}
                </div>
                <div v-if="portalForm.portal_email" class="portal-status__mail">{{ portalForm.portal_email }}</div>
              </div>
            </div>
            <a-switch v-model:checked="portalForm.enabled" />
          </div>

          <!-- Credentials -->
          <a-form v-if="portalForm.enabled" layout="vertical" class="portal-form">
            <a-form-item :label="$t('Email')" required :extra="$t('Portal_Email_Help')">
              <a-input v-model:value="portalForm.email" type="email" :placeholder="$t('Email')">
                <template #prefix><MailOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Password')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="portalForm.password" autocomplete="new-password" :placeholder="$t('LeaveBlank')">
                <template #prefix><LockOutlined style="color: rgba(0,0,0,0.25)" /></template>
              </a-input-password>
            </a-form-item>
          </a-form>
          <a-alert v-else type="warning" show-icon :message="$t('Portal_Disable_Confirm')" class="portal-disable-note" />
        </div>
      </a-spin>
    </a-modal>

    <!-- Edit online store account -->
    <a-modal
      v-model:open="storeOpen"
      :title="$t('Edit_Online_Store_Account')"
      :confirm-loading="storeSaving"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitStore"
    >
      <a-spin :spinning="storeLoading">
        <a-form layout="vertical" style="margin-top: 8px">
          <a-form-item :label="$t('Email')" required>
            <a-input v-model:value="storeForm.email" type="email" />
          </a-form-item>
          <a-form-item :label="$t('Password')" :extra="$t('LeaveBlank')">
            <a-input-password v-model:value="storeForm.NewPassword" autocomplete="new-password" />
          </a-form-item>
        </a-form>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * WAVE C REFERENCE LIST — customers core: server table, pay-due / pay-return-
 * due modals, full-page create/edit routes, delete + bulk delete.
 * Deliberately still in legacy (deep-linked): CSV import,
 * points adjustment, opening-balance adjustment, ecommerce sub-lists.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, DollarOutlined, RollbackOutlined,
  EyeOutlined, MoreOutlined, TeamOutlined, CreditCardOutlined,
  ShopOutlined, StarOutlined, CalculatorOutlined, KeyOutlined,
  UserOutlined, LockOutlined, MailOutlined, FilePdfOutlined, FileExcelOutlined,
} from '@ant-design/icons-vue';
import { exportExcel, exportPdf } from '../../lib/exporters';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PayDueModal from '../../components/PayDueModal.vue';
import DatePresetButtons from '../../components/DatePresetButtons.vue';
import DateRangeFields from '../../components/DateRangeFields.vue';
import { presetDates, presetOf } from '../../lib/datePresets';
import { startPreset } from '../../lib/datePresetDefault';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, roundMoney } = useFormat();
const auth = useAuthStore();
const router = useRouter();

const MONEY_KEYS = ['credit_limit', 'opening_balance', 'total_amount'];

// Date buttons (shared presets, user's default ★ -- else All = lifetime
// totals, the list's previous behavior). Sent as date_from/date_to: the
// server limits each customer's sales/returns figures to that range.
// Editing Start date / to = a custom range (datePreset null).
const datePreset = ref(startPreset('all'));
const customRange = ref({ from: '', to: '' });
const activeDates = () => (datePreset.value ? presetDates(datePreset.value) : customRange.value);
const dateParams = () => {
  const { from, to } = activeDates();
  return { date_from: from, date_to: to };
};
const rangeFields = computed(() => {
  const { from, to } = activeDates();
  return from ? [from, to] : null;
});
function onDatePreset() {
  crud.reload();
  loadTotals();
}
function onRangeEdit(range) {
  datePreset.value = range ? presetOf(range[0], range[1]) : 'all';
  if (!datePreset.value) customRange.value = { from: range[0], to: range[1] };
  onDatePreset();
}

// Payload: { clients, totalRows, company_info, accounts, payment_methods }
const crud = useCrudTable('clients', { rowsKey: 'clients', params: dateParams });

// Accounts come from the API as { id, account_name }, payment methods as { id, name }.
const opts = key =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name ?? x.account_name }));

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('CustomerCode'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('CustomerName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  // Columns also drive the exports, so a disabled feature drops out of both.
  ...(auth.customerCreditLimitEnabled
    ? [{ title: t('Credit_Limit'), key: 'credit_limit', dataIndex: 'credit_limit', align: 'right', exportValue: r => money(r.credit_limit) }]
    : []),
  ...(auth.customerOpeningBalanceEnabled
    ? [{ title: t('Opening_Balance'), key: 'opening_balance', dataIndex: 'opening_balance', align: 'right', exportValue: r => money(r.opening_balance) }]
    : []),
  { title: t('Total_Sales'), key: 'total_amount', dataIndex: 'total_amount', align: 'right', exportValue: r => money(r.total_amount) },
  { title: '# ' + t('Sales'), key: 'sales_count', dataIndex: 'sales_count', align: 'center', width: 90, exportValue: r => String(r.sales_count || 0) },
  // Sales Contracts of the customer (all, not date-filtered); opens their Contracts tab.
  { title: 'Contracts', key: 'contracts_count', dataIndex: 'contracts_count', align: 'center', width: 100, exportValue: r => String(r.contracts_count || 0) },
  { title: t('Due'), key: 'due', dataIndex: 'due', sorter: true, align: 'right', exportValue: r => money(r.due) },
  { title: t('Service_Due'), key: 'service_due', dataIndex: 'service_due', align: 'right', exportValue: r => money(r.service_due) },
  { title: t('Total_Sell_Return_Due'), key: 'return_Due', dataIndex: 'return_Due', align: 'right', exportValue: r => money(r.return_Due) },
]);

// Sales list filtered to this customer's completed sales (the set Total Sales
// and # Sales count), in the same date range; dueOnly narrows it to the ones
// making up Due.
function openSales(record, dueOnly = false) {
  router.push({
    path: '/sales',
    query: {
      client_id: record.id, statut: 'completed',
      ...(datePreset.value && datePreset.value !== 'all' ? { preset: datePreset.value } : {}),
      ...(!datePreset.value ? { date_from: customRange.value.from, date_to: customRange.value.to } : {}),
      ...(dueOnly ? { due_only: '1' } : {}),
    },
  });
}

/* ------------------------------------------------- list exports (PDF / Excel) */
// Whole filtered list (not just the loaded page), same pattern as Sales.
const exporting = ref(null);
async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('clients', {
      page: 1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      limit: -1,
      ...dateParams(),
    });
    const rows = data.clients || [];
    const cols = columns.value.filter(c => c.key !== 'actions');
    if (kind === 'xlsx') await exportExcel('customers', cols, rows);
    else await exportPdf(t('Customers'), cols, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

/* ------------------------------------------------- summary card totals */
// Global sums across every customer (not the current page): one unpaginated
// fetch that reuses the same list endpoint the exports use.
const customerCount = computed(() => crud.total.value);
const totals = ref({ sales: 0, due: 0, returnDue: 0, credit: 0 });
const totalsLoading = ref(false);
// Keeps the stat cards in a skeleton state from first mount until both the row
// fetch and the totals fetch have completed — including direct navigation.
const initialLoading = ref(true);
const cardsLoading = computed(() => initialLoading.value || crud.loading.value || totalsLoading.value);

async function loadTotals() {
  totalsLoading.value = true;
  try {
    const data = await http.get('clients', { page: 1, limit: -1, ...dateParams() });
    const rows = data.clients || [];
    totals.value = {
      sales: rows.reduce((s, r) => s + (Number(r.total_amount) || 0), 0),
      due: rows.reduce((s, r) => s + (Number(r.due) || 0) + (Number(r.service_due) || 0), 0),
      returnDue: rows.reduce((s, r) => s + (Number(r.return_Due) || 0), 0),
      credit: rows.reduce((s, r) => s + (Number(r.credit_limit) || 0), 0),
    };
  } catch (e) {
    // Leave the last-known totals in place on a transient failure.
  } finally {
    totalsLoading.value = false;
  }
}

// Refresh the sums whenever the customer set changes size (add, delete, bulk
// delete, filtered search) — pagination alone leaves total intact. The first
// load is handled explicitly in onMounted, so skip it here (a total that stays
// 0 would otherwise never trigger and leave the cards stuck loading).
watch(() => crud.total.value, () => { if (!initialLoading.value) loadTotals(); });

/* --------------------------------------------------- row action dispatch */
function onAction(key, record) {
  const go = {
    'online-store': () => openStore(record),
    'pay-due': () => openPay(record, 'due'),
    'pay-return': () => openPay(record, 'return'),
    details: () => router.push(`/customers/${record.id}/details`),
    points: () => openPoints(record),
    'opening-balance': () => openOpeningBalance(record),
    portal: () => openPortal(record),
    edit: () => router.push(`/customers/${record.id}/edit`),
    delete: () => crud.remove(record),
  };
  go[key]?.();
}

/* --------------------------------------------- adjust customer points */
const pointsOpen = ref(false);
const pointsSaving = ref(false);
const pointsForm = ref({ customer_id: null, customer_name: '', points: 0 });

function openPoints(record) {
  pointsForm.value = { customer_id: record.id, customer_name: record.name, points: Number(record.points) || 0 };
  pointsOpen.value = true;
}
async function savePoints() {
  pointsSaving.value = true;
  try {
    await http.post(`customers/${pointsForm.value.customer_id}/update-points`, { points: pointsForm.value.points });
    message.success(t('Successfully_Updated'));
    pointsOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    pointsSaving.value = false;
  }
}

/* ------------------------------------------- adjust opening balance */
const obOpen = ref(false);
const obSaving = ref(false);
const obForm = ref({ customer_id: null, customer_name: '', current: 0, mode: 'add', amount: null });

const obPreview = computed(() => {
  const amt = parseFloat(obForm.value.amount) || 0;
  if (obForm.value.mode === 'add') return obForm.value.current + Math.abs(amt);
  if (obForm.value.mode === 'subtract') return obForm.value.current - Math.abs(amt);
  return amt;
});

function openOpeningBalance(record) {
  obForm.value = {
    customer_id: record.id, customer_name: record.name,
    current: parseFloat(record.opening_balance) || 0, mode: 'add', amount: null,
  };
  obOpen.value = true;
}
async function submitOpeningBalance() {
  const amount = parseFloat(obForm.value.amount);
  if (isNaN(amount)) {
    message.warning(t('Amount'));
    return;
  }
  obSaving.value = true;
  try {
    await http.post(`customers/${obForm.value.customer_id}/adjust-opening-balance`, { mode: obForm.value.mode, amount });
    message.success(t('Successfully_Updated'));
    obOpen.value = false;
    crud.fetchRows();
    loadTotals();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    obSaving.value = false;
  }
}

/* --------------------------------------------------- portal client */
const portalOpen = ref(false);
const portalLoading = ref(false);
const portalSending = ref(false);
const portalForm = ref({ client_id: null, client_name: '', email: '', password: '', enabled: false, portal_email: '' });

async function openPortal(record) {
  portalForm.value = { client_id: record.id, client_name: record.name, email: record.email || '', password: '', enabled: false, portal_email: '' };
  portalOpen.value = true;
  portalLoading.value = true;
  try {
    const data = await http.get(`clients/${record.id}/portal-status`);
    portalForm.value.enabled = !!data.portal_enabled;
    portalForm.value.portal_email = data.portal_email || '';
    if (!portalForm.value.email && data.portal_email) portalForm.value.email = data.portal_email;
  } catch (e) {
    portalForm.value.enabled = false;
  } finally {
    portalLoading.value = false;
  }
}
async function submitPortal() {
  const f = portalForm.value;
  if (f.enabled && !(f.email || '').trim()) {
    message.warning(t('Email'));
    return;
  }
  portalSending.value = true;
  try {
    if (f.enabled) {
      await http.post(`clients/${f.client_id}/portal-enable`, {
        email: f.email.trim(),
        password: f.password ? f.password : undefined,
      });
    } else {
      await http.post(`clients/${f.client_id}/portal-disable`);
    }
    message.success(t('Successfully_Updated'));
    portalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    portalSending.value = false;
  }
}

/* --------------------------------------- edit online store account */
const storeOpen = ref(false);
const storeLoading = ref(false);
const storeSaving = ref(false);
const storeForm = ref({ client_id: null, email: '', NewPassword: null });

async function openStore(record) {
  storeForm.value = { client_id: record.id, email: record.email || '', NewPassword: null };
  storeOpen.value = true;
  storeLoading.value = true;
  try {
    const data = await http.get(`get_client_store_data/${record.id}`);
    storeForm.value = { client_id: data.client_id ?? record.id, email: data.email || '', NewPassword: null };
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    storeLoading.value = false;
  }
}
async function submitStore() {
  storeSaving.value = true;
  try {
    await http.put(`clients_without_ecommerce/${storeForm.value.client_id}`, {
      client_id: storeForm.value.client_id,
      email: storeForm.value.email,
      NewPassword: storeForm.value.NewPassword,
    });
    message.success(t('Successfully_Updated'));
    storeOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    storeSaving.value = false;
  }
}

/* ------------------------------------------------------------- payments */
// 'due' opens the shared PayDueModal (same flow as the details page, opening
// balance included); 'return' keeps the inline sell-return-due modal.
const payDueModal = ref();
const payOpen = ref(false);
const paying = ref(false);
const payTarget = ref(null);
const payForm = ref({ amount: 0, payment_method_id: undefined, account_id: undefined, notes: '' });

// Rounded: this is the paying-amount input's :max and prefill — a raw float
// due would surface as e.g. 121.99999999999994 in the field.
// Lifetime return due (return_due_all), not the date range's: a payment
// settles the whole balance.
const payMax = computed(() => roundMoney(payTarget.value?.return_due_all ?? payTarget.value?.return_Due));

function openPay(record, kind) {
  payTarget.value = record;
  if (kind === 'due') {
    payDueModal.value?.open();
    return;
  }
  payForm.value = { amount: payMax.value, payment_method_id: undefined, account_id: undefined, notes: '' };
  payOpen.value = true;
}

function onPaid() {
  crud.fetchRows();
  loadTotals(); // dues changed but the customer count didn't — refresh sums
}

async function submitPay() {
  if (!(Number(payForm.value.amount) > 0)) {
    message.warning(t('Amount'));
    return;
  }
  paying.value = true;
  try {
    await http.post('clients_pay_return_due', {
      client_id: payTarget.value.id,
      amount: payForm.value.amount,
      notes: payForm.value.notes,
      payment_method_id: payForm.value.payment_method_id,
      account_id: payForm.value.account_id,
    });
    message.success(t('Successfully_Created'));
    payOpen.value = false;
    onPaid();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    paying.value = false;
  }
}

onMounted(async () => {
  await crud.fetchRows();
  await loadTotals();
  initialLoading.value = false;
});
</script>

<style scoped>
/* Portal client modal --------------------------------------------------- */
.portal-modal { padding-top: 4px; }
.portal-head {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 18px;
}
.portal-head__name {
  font-size: 16px;
  font-weight: 600;
  line-height: 1.2;
}
.portal-head__sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
.portal-status {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-radius: 12px;
  border: 1px solid #f0f0f0;
  margin-bottom: 18px;
  transition: background 0.2s, border-color 0.2s;
}
.portal-status.is-on {
  background: linear-gradient(120deg, rgba(82, 196, 26, 0.10), rgba(82, 196, 26, 0.02));
  border-color: rgba(82, 196, 26, 0.30);
}
.portal-status.is-off {
  background: #fafafa;
}
.portal-status__left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.portal-status__icon {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 17px;
  flex: 0 0 auto;
  background: #bfbfbf;
  transition: background 0.2s;
}
.portal-status.is-on .portal-status__icon { background: #52c41a; }
.portal-status__title {
  font-weight: 600;
  line-height: 1.2;
}
.portal-status__mail {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 240px;
}
.portal-form { margin-bottom: -8px; }
.portal-disable-note { margin-bottom: 4px; }

.stat-card {
  border-radius: 10px;
}
/* All summary cards share one row from md up, equal widths. */
@media (min-width: 768px) {
  .stat-row { flex-wrap: nowrap; }
  .stat-row > :deep(.ant-col) { flex: 1 1 0; max-width: none; min-width: 0; }
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
/* opacity + inherit so the label/value follow the card's theme text colour. */
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .stat-inner {
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}
</style>
