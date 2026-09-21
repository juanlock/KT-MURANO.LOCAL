<template>
  <div class="page">
    <PageHeader :title="$t('ListPurchases')" :breadcrumb="[$t('Purchases'), $t('ListPurchases')]">
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
          <a-button v-if="auth.can('Purchases_add')" type="primary" @click="$router.push('/purchases/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('AddPurchase') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Summary cards — global sums over the whole filtered set (all pages). -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in statTiles" :key="tile.label" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: tile.tint, color: tile.color }">
              <component :is="tile.icon" />
            </div>
            <div class="stat-meta">
              <div class="stat-label">{{ tile.label }}</div>
              <div class="stat-value" :style="tile.style">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else>{{ tile.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8" :xl="5">
          <div class="filter-label">{{ $t('Supplier') }}</div>
          <a-select
            v-model:value="filters.provider_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Supplier')" :options="supplierOptions"
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
        <a-col :xs="12" :md="8" :xl="4">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="filters.statut" style="width: 100%" allow-clear
            :placeholder="$t('Choose_Status')" :options="statusOptions" @change="crud.reload()"
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

    <DataTable :crud="crud" :columns="columns" :selectable="auth.can('Purchases_delete')">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/purchases/${record.id}`)">{{ record.Ref }}</a>
          <a-tooltip v-if="record.purchase_has_return === 'yes'" :title="$t('Purchase_Return')">
            <span style="color: #ff4d4f; margin-left: 4px">↩</span>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'statut'">
          <a-tag :color="docStatusColor(record.statut)">
            {{ statusKey(PURCHASE_STATUSES, record.statut) ? $t(statusKey(PURCHASE_STATUSES, record.statut)) : record.statut }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'currency_code'">
          <a-tag v-if="record.currency_code" color="blue">{{ record.currency_code }}</a-tag>
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
                  <EyeOutlined /> {{ $t('PurchaseDetail') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Purchases_edit') && record.purchase_has_return === 'no'" key="edit">
                  <EditOutlined /> {{ $t('EditPurchase') }}
                </a-menu-item>
                <a-menu-item
                  v-if="auth.can('Purchase_Returns_add') && record.purchase_has_return === 'no' && record.statut === 'received'"
                  key="return-add"
                >
                  <RollbackOutlined /> {{ $t('Purchase_Return') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Purchase_Returns_add') && record.purchase_has_return === 'yes'" key="return-edit">
                  <RollbackOutlined /> {{ $t('Purchase_Return') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('payment_purchases_view')" key="payments">
                  <WalletOutlined /> {{ $t('ShowPayment') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('payment_purchases_add') && record.statut === 'received'" key="pay">
                  <PlusOutlined /> {{ $t('AddPayment') }}
                </a-menu-item>
                <a-menu-item key="pdf">
                  <FilePdfOutlined /> {{ $t('DownloadPdf') }}
                </a-menu-item>
                <a-menu-item key="barcode">
                  <BarcodeOutlined /> {{ $t('Printbarcode') }}
                </a-menu-item>
                <a-menu-item key="whatsapp">
                  <WhatsAppOutlined /> WhatsApp Notification
                </a-menu-item>
                <a-menu-item key="email">
                  <MailOutlined /> {{ $t('email_notification') }}
                </a-menu-item>
                <a-menu-item key="sms">
                  <MessageOutlined /> {{ $t('sms_notification') }}
                </a-menu-item>
                <a-menu-item key="documents">
                  <PaperClipOutlined /> {{ $t('Attach_Documents') }}
                </a-menu-item>
                <a-menu-divider v-if="auth.can('Purchases_delete') && record.purchase_has_return === 'no'" />
                <a-menu-item v-if="auth.can('Purchases_delete') && record.purchase_has_return === 'no'" key="delete" danger>
                  <DeleteOutlined /> {{ $t('DeletePurchase') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>

    <!-- ============ Payments list modal ============ -->
    <a-modal v-model:open="paymentsOpen" :title="`${$t('ShowPayment')} — ${activePurchase?.Ref || ''}`" :footer="null" width="820px">
      <a-table :columns="paymentColumns" :data-source="payments" :pagination="false" row-key="id" size="small" :scroll="{ x: 'max-content' }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'montant'">{{ money(record.montant) }}</template>
          <template v-else-if="column.key === 'method'">{{ record.payment_method?.name || '---' }}</template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('DownloadPdf')">
                <a-button type="text" size="small" @click="paymentPdf(record)">
                  <template #icon><FilePdfOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('email_notification')">
                <a-button type="text" size="small" @click="paymentNotify('payment_purchase_send_email', record)">
                  <template #icon><MailOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('sms_notification')">
                <a-button type="text" size="small" @click="paymentNotify('payment_purchase_send_sms', record)">
                  <template #icon><MessageOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('payment_purchases_edit')" :title="$t('Edit')">
                <a-button type="text" size="small" @click="openEditPayment(record)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('payment_purchases_delete')" :title="$t('Del')">
                <a-button type="text" size="small" danger @click="removePayment(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
        </template>
      </a-table>
      <div style="text-align: right; margin-top: 12px; font-weight: 600">
        {{ $t('Due') }}: <span style="color: #ff4d4f">{{ money(paymentsDue) }}</span>
      </div>
    </a-modal>

    <!-- ============ Add / edit payment modal ============ -->
    <a-modal
      v-model:open="payFormOpen"
      :title="`${payEditing ? $t('Edit') : $t('AddPayment')} — ${activePurchase?.Ref || ''}`"
      :confirm-loading="paySaving"
      :ok-text="$t('submit')"
      @ok="submitPayment"
    >
      <a-form layout="vertical">
        <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t('Payment_Amounts_Help')" />
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('date')">
              <a-date-picker v-model:value="payForm.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Amount_Paid')">
              <a-input-number v-model:value="payForm.montant" style="width: 100%" :min="0" :max="payMaxDue" />
              <a-typography-text type="secondary" style="font-size: 12px; display: block">
                {{ $t('Amount_Paid_Help') }}
              </a-typography-text>
              <a-typography-text type="secondary" style="font-size: 12px; display: block">
                {{ $t('Maximum_payment') }}: {{ money(payMaxDue) }}
              </a-typography-text>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Received_Amount')">
              <a-input-number v-model:value="payForm.received_amount" style="width: 100%" :min="0" />
              <a-typography-text type="secondary" style="font-size: 12px; display: block">
                {{ $t('Received_Amount_Help') }}
              </a-typography-text>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Paymentchoice')">
              <a-select v-model:value="payForm.payment_method_id" :options="paymentMethodOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Account')">
              <a-select v-model:value="payForm.account_id" allow-clear :placeholder="$t('Choose_Account')" :options="accountOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Change')">
              <a-input :value="money(payChange)" disabled />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Due')">
              <a-input :value="money(activePurchase?.due)" disabled />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="payForm.notes" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- ============ Documents modal ============ -->
    <a-modal v-model:open="documentsOpen" :title="`${$t('Attach_Documents')} — ${activePurchase?.Ref || ''}`" :footer="null" width="640px">
      <a-upload
        :file-list="docFiles"
        :before-upload="f => { docFiles = [...docFiles, f]; return false; }"
        multiple
        @remove="f => { docFiles = docFiles.filter(x => x !== f); }"
      >
        <a-button><UploadOutlined /> {{ $t('Attach_Documents') }}</a-button>
      </a-upload>
      <a-button
        type="primary"
        style="margin-top: 8px"
        :loading="docUploading"
        :disabled="!docFiles.length"
        @click="uploadDocuments"
      >
        {{ $t('submit') }}
      </a-button>
      <a-divider style="margin: 16px 0" />
      <a-list :data-source="documents" size="small">
        <template #renderItem="{ item }">
          <a-list-item>
            <span>{{ item.name || item.file_name || item.original_name }}</span>
            <template #actions>
              <a-button type="text" size="small" @click="downloadDocument(item)">
                <template #icon><DownloadOutlined /></template>
              </a-button>
              <a-button type="text" size="small" danger @click="removeDocument(item)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </template>
          </a-list-item>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 16px 0" />
        </template>
      </a-list>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Full legacy action surface, mirroring Sales.vue with the purchase endpoint
 * family. Differences that matter:
 * - payments: get_payments_by_purchase/{id}; POST payment_purchase is FLAT
 *   (no payments[] lines array, unlike sales); the payment Ref is assigned
 *   server-side; receipt payment_purchase_pdf/{id}
 * - notify: purchase_send_email|purchase_send_sms|purchase_send_whatsapp
 * - invoice PDF: purchase_pdf/{id} → Purchase-{Ref}.pdf
 * - Printbarcode replaces sales' shipment/POS actions — deep-links to the
 *   legacy barcode page with ?purchase_id
 * - documents: purchases/{id}/documents family (purchase_id field)
 * - AddPayment only when statut received; edit/delete/return hidden when
 *   purchase_has_return != 'no'
 */
import { ref, computed, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, MoreOutlined,
  FilePdfOutlined, FileExcelOutlined, MailOutlined, MessageOutlined,
  WalletOutlined, RollbackOutlined, PaperClipOutlined, UploadOutlined,
  DownloadOutlined, WhatsAppOutlined, BarcodeOutlined, ExclamationCircleOutlined,
  ShoppingOutlined, DollarOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { docStatusColor, payStatusColor } from '../../lib/statusColors';
import { exportExcel, exportPdf } from '../../lib/exporters';
import { PAYMENT_STATUSES, statusKey } from '../sales/saleVocab';
import { PURCHASE_STATUSES } from './purchaseVocab';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime, roundMoney } = useFormat();
const auth = useAuthStore();
const router = useRouter();

const filters = ref({
  date: null, provider_id: undefined, warehouse_id: undefined,
  statut: undefined, payment_statut: undefined,
});

const filterParams = () => ({
  Ref: '',
  date: filters.value.date || '',
  provider_id: filters.value.provider_id || '',
  warehouse_id: filters.value.warehouse_id || '',
  statut: filters.value.statut || '',
  payment_statut: filters.value.payment_statut || '',
});

const crud = useCrudTable('purchases', {
  rowsKey: 'purchases',
  bulkDeleteEndpoint: 'purchases_delete_by_selection',
  params: filterParams,
});
crud.fetchRows();

// Summary cards — `stats` ships with the list payload (sums over ALL pages of
// the current filtered set, not just the visible page).
const statTiles = computed(() => {
  const s = crud.payload.value?.stats || {};
  const n = k => Number(s[k]) || 0;
  return [
    { label: t('Purchases'), value: n('count'), icon: ShoppingOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Total'), value: money(n('total')), icon: DollarOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Paid'), value: money(n('paid')), icon: CheckCircleOutlined, color: '#52c41a', tint: 'rgba(82, 196, 26, 0.12)' },
    {
      label: t('Due'), value: money(n('due')), icon: ExclamationCircleOutlined,
      color: '#ff4d4f', tint: 'rgba(255, 77, 79, 0.12)',
      style: n('due') > 0 ? { color: '#ff4d4f' } : undefined,
    },
  ];
});

const supplierOptions = computed(() =>
  (crud.payload.value?.suppliers || []).map(s => ({ value: s.id, label: s.name }))
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
const statusOptions = computed(() => PURCHASE_STATUSES.map(s => ({ value: s.value, label: t(s.key) })));
const paymentStatusOptions = computed(() => PAYMENT_STATUSES.map(s => ({ value: s.value, label: t(s.key) })));

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('Status'), dataIndex: 'statut', key: 'statut', sorter: true, exportValue: r => r.statut },
  // Multi-Currency: badge with the document's currency code (amounts in the
  // list stay base-currency). Hidden when the module is off.
  ...(auth.multiCurrencyEnabled
    ? [{ title: t('Currency'), dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', exportValue: r => r.currency_code || '' }]
    : []),
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', sorter: true, align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status', exportValue: r => r.payment_status },
]);

// ---------------- list exports (PDF / Excel) ----------------

const exporting = ref(null);

async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('purchases', {
      page: 1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      limit: -1,
      ...filterParams(),
    });
    const rows = data.purchases || [];
    if (kind === 'xlsx') await exportExcel('purchases', columns.value, rows);
    else await exportPdf(t('ListPurchases'), columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

// ---------------- row action dispatch ----------------

const activePurchase = ref(null);

function onAction(key, record) {
  activePurchase.value = record;
  const go = {
    detail: () => router.push(`/purchases/${record.id}`),
    edit: () => router.push(`/purchases/${record.id}/edit`),
    'return-add': () => router.push(`/purchase-returns/create/${record.id}`),
    'return-edit': () => router.push(`/purchase-returns/${record.purchasereturn_id}/edit/${record.id}`),
    payments: () => showPayments(record),
    pay: () => openAddPayment(record),
    pdf: () => invoicePdf(record),
    barcode: () => router.push(`/products/barcode?purchase_id=${record.id}`),
    whatsapp: () => sendWhatsApp(record),
    email: () => notify('purchase_send_email', record.id, 'SendEmail', 'SMTPIncorrect'),
    sms: () => notify('purchase_send_sms', record.id, 'sms_send_successfully', 'sms_config_invalid'),
    documents: () => openDocuments(record),
    delete: () => crud.remove(record, { label: record.Ref }),
  };
  go[key]?.();
}

// ---------------- notifications ----------------

async function notify(endpoint, id, okKey, failKey) {
  try {
    await http.post(endpoint, { id });
    message.success(t(okKey));
  } catch (e) {
    message.error(t(failKey));
  }
}

async function sendWhatsApp(record) {
  try {
    const data = await http.post('purchase_send_whatsapp', { id: record.id });
    const url = `https://web.whatsapp.com/send/?phone=${encodeURIComponent(data.phone)}&text=${encodeURIComponent(data.message)}`;
    window.open(url, '_blank');
  } catch (e) {
    message.error(t('Failed'));
  }
}

function invoicePdf(record) {
  http.download(`purchase_pdf/${record.id}`, `Purchase-${record.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

// ---------------- payments ----------------

const paymentsOpen = ref(false);
const payments = ref([]);
const paymentsDue = ref(0);

const paymentColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right' },
  { title: t('PayeBy'), key: 'method' },
  { title: t('Action'), key: 'actions', width: 180, align: 'center' },
]);

async function loadPayments(purchaseId) {
  const data = await http.get(`get_payments_by_purchase/${purchaseId}`);
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

function paymentPdf(payment) {
  http.download(`payment_purchase_pdf/${payment.id}`, `Payment-${payment.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

async function paymentNotify(endpoint, payment) {
  try {
    await http.post(endpoint, { id: payment.id });
    message.success(t('Success'));
  } catch (e) {
    message.error(t('Failed'));
  }
}

function removePayment(payment) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Delete_Text'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`payment_purchase/${payment.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadPayments(activePurchase.value.id);
        crud.fetchRows();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

const payFormOpen = ref(false);
const payEditing = ref(null);
const paySaving = ref(false);
const payForm = ref({});

const payChange = computed(() =>
  Math.max(0, (Number(payForm.value.received_amount) || 0) - (Number(payForm.value.montant) || 0))
);

// Old-admin rule: a payment cannot exceed what's still owed. When editing, the
// payment's own amount is owed again (it is being replaced), so it re-enters
// the cap. Rounded so the input's clamp never shows a raw float artifact.
const payMaxDue = computed(() => roundMoney(
  payEditing.value
    ? (Number(paymentsDue.value) || 0) + (Number(payEditing.value.montant) || 0)
    : activePurchase.value?.due
));

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
  const body = {
    purchase_id: activePurchase.value.id,
    date: f.date,
    montant: (Number(f.montant) || 0).toFixed(2),
    received_amount: (Number(f.received_amount) || 0).toFixed(2),
    change: payChange.value.toFixed(2),
    payment_method_id: f.payment_method_id,
    account_id: f.account_id || null,
    notes: f.notes,
  };
  try {
    if (payEditing.value) {
      await http.put(`payment_purchase/${payEditing.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('payment_purchase', body);
      message.success(t('Successfully_Created'));
    }
    payFormOpen.value = false;
    crud.fetchRows();
    if (paymentsOpen.value) await loadPayments(activePurchase.value.id);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paySaving.value = false;
  }
}

// ---------------- documents ----------------

const documentsOpen = ref(false);
const documents = ref([]);
const docFiles = ref([]);
const docUploading = ref(false);

async function loadDocuments(purchaseId) {
  const data = await http.get(`purchases/${purchaseId}/documents`);
  documents.value = data.documents || [];
}

async function openDocuments(record) {
  docFiles.value = [];
  try {
    await loadDocuments(record.id);
    documentsOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function uploadDocuments() {
  if (!docFiles.value.length) {
    message.warning(t('Please_select_files'));
    return;
  }
  docUploading.value = true;
  const fd = new FormData();
  docFiles.value.forEach(f => fd.append('documents[]', f.originFileObj || f));
  fd.append('purchase_id', activePurchase.value.id);
  try {
    await http.postForm(`purchases/${activePurchase.value.id}/documents`, fd);
    message.success(t('Documents_uploaded_successfully'));
    docFiles.value = [];
    await loadDocuments(activePurchase.value.id);
    crud.fetchRows();
  } catch (e) {
    message.error(t('Failed_to_upload_documents'));
  } finally {
    docUploading.value = false;
  }
}

function downloadDocument(doc) {
  http.download(`purchases/documents/${doc.id}/download`, doc.name || doc.file_name || 'document')
    .catch(() => message.error(t('Failed_to_download_document')));
}

function removeDocument(doc) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Delete_Text'),
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`purchases/documents/${doc.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadDocuments(activePurchase.value.id);
        crud.fetchRows();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}

/* Summary cards — same design as the sales list. */
.stat-card {
  border-radius: 10px;
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
