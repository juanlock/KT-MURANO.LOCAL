<template>
  <div class="page">
    <PageHeader :title="$t('PurchasesReturn')" :breadcrumb="[$t('PurchasesReturn'), $t('ListReturns')]">
      <template #actions>
        <a-space wrap>
          <a-button :loading="exporting === 'pdf'" @click="exportList('pdf')">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
          <a-button :loading="exporting === 'xlsx'" @click="exportList('xlsx')">
            <template #icon><FileExcelOutlined /></template>
            EXCEL
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8" :xl="4">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('Supplier') }}</div>
          <a-select
            v-model:value="filters.provider_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Supplier')" :options="supplierOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('Purchase_Ref') }}</div>
          <a-select
            v-model:value="filters.purchase_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Purchase_Ref')" :options="purchaseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('PaymentStatus') }}</div>
          <a-select
            v-model:value="filters.payment_statut" style="width: 100%" allow-clear
            :placeholder="$t('PaymentStatus')" :options="paymentStatusOptions" @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :selectable="auth.can('Purchase_Returns_delete')">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/purchase-returns/${record.id}`)">{{ record.Ref }}</a>
        </template>
        <template v-else-if="column.key === 'purchase_ref'">
          <a v-if="record.purchase_id" @click="$router.push(`/purchases/${record.purchase_id}`)">{{ record.purchase_ref }}</a>
          <span v-else>—</span>
        </template>
        <template v-else-if="column.key === 'statut'">
          <a-tag :color="record.statut === 'received' ? 'success' : 'processing'">
            {{ record.statut === 'received' ? $t('Received') : $t('Pending') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'GrandTotal'">{{ money(record.GrandTotal) }}</template>
        <template v-else-if="column.key === 'paid_amount'">{{ money(record.paid_amount) }}</template>
        <template v-else-if="column.key === 'due'">
          <span :style="{ color: Number(record.due) > 0 ? '#ff4d4f' : undefined }">{{ money(record.due) }}</span>
        </template>
        <template v-else-if="column.key === 'payment_status'">
          <a-tag :color="payStatusColor(record.payment_status)">
            {{ statusKey(PAYMENT_STATUSES, record.payment_status) ? $t(statusKey(PAYMENT_STATUSES, record.payment_status)) : record.payment_status }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-dropdown :trigger="['click']">
            <a-button type="text" size="small">
              <template #icon><MoreOutlined style="font-size: 18px" /></template>
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <a-menu-item key="detail">
                  <EyeOutlined /> {{ $t('ReturnDetail') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Purchase_Returns_edit')" key="edit">
                  <EditOutlined /> {{ $t('EditReturn') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('payment_returns_view')" key="payments">
                  <WalletOutlined /> {{ $t('ShowPayment') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('payment_returns_add')" key="pay">
                  <PlusOutlined /> {{ $t('AddPayment') }}
                </a-menu-item>
                <a-menu-item key="pdf">
                  <FilePdfOutlined /> {{ $t('DownloadPdf') }}
                </a-menu-item>
                <a-menu-divider v-if="auth.can('Purchase_Returns_delete')" />
                <a-menu-item v-if="auth.can('Purchase_Returns_delete')" key="delete" danger>
                  <DeleteOutlined /> {{ $t('DeleteReturn') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>

    <ReturnPaymentsModal
      v-model:open="paymentsOpen"
      :active="activeReturn"
      :payments="payments"
      :due="paymentsDue"
      :can-edit="auth.can('payment_returns_edit')"
      :can-delete="auth.can('payment_returns_delete')"
      pdf-endpoint="payment_return_purchase_pdf"
      pdf-prefix="Payment_Purchase_Return"
      delete-endpoint="payment/returns_purchase"
      @edit="openEditPayment"
      @removed="onPaymentRemoved"
    />

    <ReturnPaymentFormModal
      v-model:open="payFormOpen"
      :active="activeReturn"
      :editing="payEditing"
      :form="payForm"
      :method-options="paymentMethodOptions"
      :account-options="accountOptions"
      :saving="paySaving"
      :max-due="payMaxDue"
      @submit="submitPayment"
    />
  </div>
</template>

<script setup>
/**
 * GET returns/purchase → {purchase_returns, suppliers, purchases, warehouses,
 * accounts, payment_methods, totalRows}; params Ref/date/purchase_id/
 * provider_id/statut/warehouse_id/payment_statut. Payments:
 * returns/purchase/payment/{id}; POST/PUT payment/returns_purchase (flat, key
 * purchase_return_id); the payment Ref is assigned server-side; receipt
 * payment_return_purchase_pdf. Return PDF return_purchase_pdf/{id}. Same
 * payment_returns_* permission family as sale returns.
 */
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, MoreOutlined,
  FilePdfOutlined, FileExcelOutlined, WalletOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ReturnPaymentsModal from '../../components/ReturnPaymentsModal.vue';
import ReturnPaymentFormModal from '../../components/ReturnPaymentFormModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { payStatusColor } from '../../lib/statusColors';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { PAYMENT_STATUSES, statusKey } from '../sales/saleVocab';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime, roundMoney } = useFormat();
const auth = useAuthStore();
const router = useRouter();

const filters = ref({
  date: null, provider_id: undefined, purchase_id: undefined,
  warehouse_id: undefined, payment_statut: undefined,
});

const filterParams = () => ({
  Ref: '',
  date: filters.value.date || '',
  purchase_id: filters.value.purchase_id || '',
  provider_id: filters.value.provider_id || '',
  statut: '',
  warehouse_id: filters.value.warehouse_id || '',
  payment_statut: filters.value.payment_statut || '',
});

const crud = useCrudTable('returns/purchase', {
  rowsKey: 'purchase_returns',
  params: filterParams,
});
crud.fetchRows();

const supplierOptions = computed(() =>
  (crud.payload.value?.suppliers || []).map(s => ({ value: s.id, label: s.name }))
);
const purchaseOptions = computed(() =>
  (crud.payload.value?.purchases || []).map(p => ({ value: p.id, label: p.Ref }))
);
const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const paymentMethodOptions = computed(() =>
  (crud.payload.value?.payment_methods || []).map(m => ({ value: m.id, label: m.name }))
);
const accountOptions = computed(() =>
  (crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name }))
);
const paymentStatusOptions = computed(() => PAYMENT_STATUSES.map(s => ({ value: s.value, label: t(s.key) })));

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('Purchase_Ref'), dataIndex: 'purchase_ref', key: 'purchase_ref' },
  { title: t('Status'), dataIndex: 'statut', key: 'statut', sorter: true, exportValue: r => r.statut },
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', sorter: true, align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status', exportValue: r => r.payment_status },
]);

const exporting = ref(null);

async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('returns/purchase', {
      page: 1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      limit: -1,
      ...filterParams(),
    });
    const rows = data.purchase_returns || [];
    if (kind === 'xlsx') await exportExcel('purchase_returns', columns.value, rows);
    else await exportPdf(t('PurchasesReturn'), columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

const activeReturn = ref(null);

function onAction(key, record) {
  activeReturn.value = record;
  const go = {
    detail: () => router.push(`/purchase-returns/${record.id}`),
    edit: () => router.push(`/purchase-returns/${record.id}/edit/${record.purchase_id}`),
    payments: () => showPayments(record),
    pay: () => openAddPayment(record),
    pdf: () =>
      http.download(`return_purchase_pdf/${record.id}`, `Purchase_Return_${record.Ref}.pdf`)
        .catch(() => message.error(t('InvalidData'))),
    delete: () => crud.remove(record, { label: record.Ref }),
  };
  go[key]?.();
}

// ---------------- payments ----------------

const paymentsOpen = ref(false);
const payments = ref([]);
const paymentsDue = ref(0);

async function loadPayments(id) {
  const data = await http.get(`returns/purchase/payment/${id}`);
  payments.value = data.payments || [];
  paymentsDue.value = data.due || 0;
}

async function showPayments(record) {
  try {
    await loadPayments(record.id);
    paymentsOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function onPaymentRemoved() {
  await loadPayments(activeReturn.value.id);
  crud.fetchRows();
}

const payFormOpen = ref(false);
const payEditing = ref(null);
const paySaving = ref(false);
const payForm = ref({});

function openAddPayment(record) {
  if (record.payment_status === 'paid') {
    message.warning(t('PaymentComplete'));
    return;
  }
  payEditing.value = null;
  payForm.value = {
    date: dayjs().format('YYYY-MM-DD'),
    montant: roundMoney(record.due),
    received_amount: roundMoney(record.due),
    payment_method_id: 2,
    account_id: undefined,
    notes: '',
  };
  payFormOpen.value = true;
}

function openEditPayment(payment) {
  payEditing.value = payment;
  payForm.value = {
    date: payment.date,
    montant: Number(payment.montant) || 0,
    received_amount: +((Number(payment.montant) || 0) + (Number(payment.change) || 0)).toFixed(2),
    payment_method_id: payment.payment_method_id,
    account_id: payment.account_id || undefined,
    notes: payment.notes || '',
  };
  payFormOpen.value = true;
}

// Old-admin rule: a payment cannot exceed what's still owed. When editing, the
// payment's own amount is owed again (it is being replaced), so it re-enters
// the cap. Rounded so the input's clamp never shows a raw float artifact.
const payMaxDue = computed(() => roundMoney(
  payEditing.value
    ? (Number(paymentsDue.value) || 0) + (Number(payEditing.value.montant) || 0)
    : activeReturn.value?.due
));

async function submitPayment() {
  const f = payForm.value;
  // Same guards as the pre-migration admin (Verified_paidAmount / Submit_Payment).
  const montant = Number(f.montant) || 0;
  if (montant <= 0 || !f.payment_method_id) {
    message.warning(t('Please_fill_the_form_correctly'));
    return;
  }
  if (montant > (Number(f.received_amount) || 0)) {
    message.warning(t('Paying_amount_is_greater_than_Received_amount'));
    return;
  }
  if (montant > payMaxDue.value) {
    message.warning(t('Paying_amount_is_greater_than_Grand_Total'));
    return;
  }
  paySaving.value = true;
  const change = Math.max(0, (Number(f.received_amount) || 0) - (Number(f.montant) || 0));
  const body = {
    purchase_return_id: activeReturn.value.id,
    date: f.date,
    montant: (Number(f.montant) || 0).toFixed(2),
    received_amount: (Number(f.received_amount) || 0).toFixed(2),
    change: change.toFixed(2),
    payment_method_id: f.payment_method_id,
    account_id: f.account_id || null,
    notes: f.notes,
  };
  try {
    if (payEditing.value) {
      await http.put(`payment/returns_purchase/${payEditing.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('payment/returns_purchase', body);
      message.success(t('Successfully_Created'));
    }
    payFormOpen.value = false;
    crud.fetchRows();
    if (paymentsOpen.value) await loadPayments(activeReturn.value.id);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paySaving.value = false;
  }
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
