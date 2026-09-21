<template>
  <div class="page">
    <PageHeader :title="$t('ListSales')" :breadcrumb="[$t('Sales'), $t('ListSales')]">
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
          <a-button v-if="auth.can('Sales_add')" type="primary" @click="$router.push('/sales/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('AddSale') }}
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
        <a-col :xs="12" :md="8" :xl="4">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8" :xl="4">
          <div class="filter-label">{{ $t('Customer') }}</div>
          <a-select
            v-model:value="filters.client_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Customer')" :options="customerOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="4">
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
        <a-col :xs="12" :md="8" :xl="4">
          <div class="filter-label">{{ $t('PaymentStatus') }}</div>
          <a-select
            v-model:value="filters.payment_statut" style="width: 100%" allow-clear
            :placeholder="$t('PaymentStatus')" :options="paymentStatusOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8" :xl="4">
          <div class="filter-label">{{ $t('Shipping_status') }}</div>
          <a-select
            v-model:value="filters.shipping_status" style="width: 100%" allow-clear
            :placeholder="$t('Shipping_status')" :options="shippingStatusOptions" @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :selectable="auth.can('Sales_delete')">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/sales/${record.id}`)">{{ record.Ref }}</a>
          <a-tooltip v-if="record.sale_has_return === 'yes'" :title="$t('Sell_Return')">
            <span style="color: #ff4d4f; margin-left: 4px">↩</span>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'statut'">
          <a-tag :color="docStatusColor(record.statut)">
            {{ statusKey(SALE_STATUSES, record.statut) ? $t(statusKey(SALE_STATUSES, record.statut)) : record.statut }}
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
        <template v-else-if="column.key === 'shipping_status'">
          <a-tag v-if="record.shipping_status" :color="shipStatusColor(record.shipping_status)">
            {{ statusKey(SHIPPING_STATUSES, record.shipping_status) ? $t(statusKey(SHIPPING_STATUSES, record.shipping_status)) : record.shipping_status }}
          </a-tag>
          <span v-else>—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-dropdown :trigger="['click']">
            <a-button type="text" size="small">
              <template #icon><MoreOutlined style="font-size: 18px" /></template>
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <a-menu-item key="detail">
                  <EyeOutlined /> {{ $t('SaleDetail') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Sales_edit') && record.sale_has_return === 'no'" key="edit">
                  <EditOutlined /> {{ $t('EditSale') }}
                </a-menu-item>
                <a-menu-item
                  v-if="auth.can('Sale_Returns_add') && record.sale_has_return === 'no' && record.statut === 'completed'"
                  key="return-add"
                >
                  <RollbackOutlined /> {{ $t('Sell_Return') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Sale_Returns_add') && record.sale_has_return === 'yes'" key="return-edit">
                  <RollbackOutlined /> {{ $t('Sell_Return') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('payment_sales_view')" key="payments">
                  <WalletOutlined /> {{ $t('ShowPayment') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('payment_sales_add') && record.statut === 'completed'" key="pay">
                  <PlusOutlined /> {{ $t('AddPayment') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('shipment')" key="shipment">
                  <CarOutlined /> {{ $t('Edit_Shipping') }}
                </a-menu-item>
                <a-menu-item key="invoice-pos">
                  <FileTextOutlined /> {{ $t('Invoice_POS') }}
                </a-menu-item>
                <a-menu-item key="pdf">
                  <FilePdfOutlined /> {{ $t('DownloadPdf') }}
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
                <a-menu-divider v-if="auth.can('Sales_delete') && record.sale_has_return === 'no'" />
                <a-menu-item v-if="auth.can('Sales_delete') && record.sale_has_return === 'no'" key="delete" danger>
                  <DeleteOutlined /> {{ $t('Del') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>

    <!-- ============ Payments list modal ============ -->
    <a-modal v-model:open="paymentsOpen" :title="`${$t('ShowPayment')} — ${activeSale?.Ref || ''}`" :footer="null" width="820px">
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
                <a-button type="text" size="small" @click="paymentNotify('payment_sale_send_email', record)">
                  <template #icon><MailOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('sms_notification')">
                <a-button type="text" size="small" @click="paymentNotify('payment_sale_send_sms', record)">
                  <template #icon><MessageOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('payment_sales_edit')" :title="$t('Edit')">
                <a-button type="text" size="small" @click="openEditPayment(record)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="auth.can('payment_sales_delete')" :title="$t('Del')">
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
      :title="`${payEditing ? $t('Edit') : $t('AddPayment')} — ${activeSale?.Ref || ''}`"
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
              <a-input :value="money(activeSale?.due)" disabled />
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

    <!-- ============ Shipment modal ============ -->
    <a-modal
      v-model:open="shipmentOpen"
      :title="$t('Edit_Shipping')"
      :confirm-loading="shipmentSaving"
      :ok-text="$t('submit')"
      @ok="submitShipment"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Status')">
          <a-select v-model:value="shipment.status" :options="shippingStatusOptions" :placeholder="$t('Choose_Status')" />
        </a-form-item>
        <a-form-item :label="$t('delivered_to')">
          <a-input v-model:value="shipment.delivered_to" />
        </a-form-item>
        <a-form-item :label="$t('Adress')">
          <a-input v-model:value="shipment.shipping_address" />
        </a-form-item>
        <a-form-item :label="$t('Please_provide_any_details')">
          <a-textarea v-model:value="shipment.shipping_details" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- ============ Documents modal ============ -->
    <a-modal v-model:open="documentsOpen" :title="`${$t('Attach_Documents')} — ${activeSale?.Ref || ''}`" :footer="null" width="640px">
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
 * Full legacy action surface for the sales list. Contracts:
 * - list GET sales (+filters) → {sales, customers, accounts, warehouses,
 *   payment_methods, totalRows}; bulk delete POST sales_delete_by_selection
 * - list exports refetch with limit=-1, then exporters.js (legacy exported
 *   only the visible page — full set is strictly better)
 * - payments: GET get_payments_by_sale/{id} → {payments, due};
 *   POST payment_sale (payments[] lines — we send one), PUT payment_sale/{id},
 *   DELETE payment_sale/{id} — the payment Ref is assigned server-side,
 *   receipt PDF GET payment_sale_pdf/{id}, notify POST
 *   payment_sale_send_email|payment_sale_send_sms {id}
 * - shipment: GET shipments/{sale_id} → {shipment}; POST shipments (upsert)
 * - invoice: GET sale_pdf/{id} (blob) → Sale-{Ref}.pdf; POS receipt data from
 *   GET sales_print_invoice/{id} rendered into a print window styled by
 *   /css/pos_print.css via lib/posReceiptHtml (all five receipt_layout
 *   variants from Settings → POS Receipt), honoring every pos_settings
 *   show_* toggle, note_customer, logo/paper size, and the ZATCA +
 *   invoice-URL QRs (same flag semantics as the PosPage receipt)
 * - notify: POST sales_send_email|sales_send_sms {id}; WhatsApp POST
 *   sales_send_whatsapp {id} → {phone, message} → web.whatsapp.com link
 * - documents: GET sales/{id}/documents; POST sales/{id}/documents
 *   (documents[] + sale_id); GET sales/documents/{id}/download (blob);
 *   DELETE sales/documents/{id}
 */
import { ref, computed, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, MoreOutlined,
  FilePdfOutlined, FileExcelOutlined, FileTextOutlined, MailOutlined,
  MessageOutlined, WalletOutlined, CarOutlined, RollbackOutlined,
  PaperClipOutlined, UploadOutlined, DownloadOutlined, WhatsAppOutlined,
  ExclamationCircleOutlined, ShoppingCartOutlined, DollarOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { docStatusColor, payStatusColor } from '../../lib/statusColors';
import { exportExcel, exportPdf } from '../../lib/exporters';
import {
  SALE_STATUSES, PAYMENT_STATUSES, SHIPPING_STATUSES, statusKey, shipStatusColor,
} from './saleVocab';
import http from '../../lib/http';
import { receiptFontHeadTags, whenPrintFontsReady } from '../../lib/receiptFont';
import { buildPosReceiptHtml } from '../../lib/posReceiptHtml';

const { t } = useI18n();
const { money, number, dateTime, roundMoney } = useFormat();
const auth = useAuthStore();
const router = useRouter();

const filters = ref({
  date: null, client_id: undefined, warehouse_id: undefined,
  statut: undefined, payment_statut: undefined, shipping_status: undefined,
});

const filterParams = () => ({
  Ref: '',
  date: filters.value.date || '',
  client_id: filters.value.client_id || '',
  warehouse_id: filters.value.warehouse_id || '',
  statut: filters.value.statut || '',
  payment_statut: filters.value.payment_statut || '',
  shipping_status: filters.value.shipping_status || '',
});

const crud = useCrudTable('sales', {
  rowsKey: 'sales',
  bulkDeleteEndpoint: 'sales_delete_by_selection',
  params: filterParams,
});
crud.fetchRows();

// Summary cards — `stats` ships with the list payload (sums over ALL pages of
// the current filtered set, not just the visible page).
const statTiles = computed(() => {
  const s = crud.payload.value?.stats || {};
  const n = k => Number(s[k]) || 0;
  return [
    { label: t('Sales'), value: n('count'), icon: ShoppingCartOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Total'), value: money(n('total')), icon: DollarOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Paid'), value: money(n('paid')), icon: CheckCircleOutlined, color: '#52c41a', tint: 'rgba(82, 196, 26, 0.12)' },
    {
      label: t('Due'), value: money(n('due')), icon: ExclamationCircleOutlined,
      color: '#ff4d4f', tint: 'rgba(255, 77, 79, 0.12)',
      style: n('due') > 0 ? { color: '#ff4d4f' } : undefined,
    },
  ];
});

const customerOptions = computed(() =>
  (crud.payload.value?.customers || []).map(c => ({ value: c.id, label: c.name }))
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
const statusOptions = computed(() => SALE_STATUSES.map(s => ({ value: s.value, label: t(s.key) })));
const paymentStatusOptions = computed(() => PAYMENT_STATUSES.map(s => ({ value: s.value, label: t(s.key) })));
const shippingStatusOptions = computed(() => SHIPPING_STATUSES.map(s => ({ value: s.value, label: t(s.key) })));

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
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
  { title: t('Shipping_status'), dataIndex: 'shipping_status', key: 'shipping_status', exportValue: r => r.shipping_status || '' },
]);

// ---------------- list exports (PDF / Excel) ----------------

const exporting = ref(null);

async function exportList(kind) {
  exporting.value = kind;
  try {
    const data = await http.get('sales', {
      page: 1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      limit: -1,
      ...filterParams(),
    });
    const rows = data.sales || [];
    if (kind === 'xlsx') await exportExcel('sales', columns.value, rows);
    else await exportPdf(t('ListSales'), columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = null;
  }
}

// ---------------- row action dispatch ----------------

const activeSale = ref(null);

function onAction(key, record) {
  activeSale.value = record;
  const go = {
    detail: () => router.push(`/sales/${record.id}`),
    edit: () => router.push(`/sales/${record.id}/edit`),
    'return-add': () => router.push(`/sale-returns/create/${record.id}`),
    'return-edit': () => router.push(`/sale-returns/${record.salereturn_id}/edit/${record.id}`),
    payments: () => showPayments(record),
    pay: () => openAddPayment(record),
    shipment: () => openShipment(record),
    'invoice-pos': () => invoicePos(record),
    pdf: () => invoicePdf(record),
    whatsapp: () => sendWhatsApp(record),
    email: () => notify('sales_send_email', record.id, 'SendEmail', 'SMTPIncorrect'),
    sms: () => notify('sales_send_sms', record.id, 'sms_send_successfully', 'sms_config_invalid'),
    documents: () => openDocuments(record),
    delete: () => crud.remove(record, { label: record.Ref }),
  };
  go[key]?.();
}

// ---------------- notifications (email / SMS / WhatsApp) ----------------

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
    const data = await http.post('sales_send_whatsapp', { id: record.id });
    const url = `https://web.whatsapp.com/send/?phone=${encodeURIComponent(data.phone)}&text=${encodeURIComponent(data.message)}`;
    window.open(url, '_blank');
  } catch (e) {
    message.error(t('Failed'));
  }
}

// ---------------- invoice PDF + POS receipt ----------------

function invoicePdf(record) {
  http.download(`sale_pdf/${record.id}`, `Sale-${record.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}

// qrcodejs loader shared by all receipt prints on this page. Same source
// chain as PosPage.loadQRCodeLib (CDN → repaired local copy → setup copy);
// receipts still print without QRs if none of them loads.
let qrLibPromise = null;
function loadQrLib() {
  if (window.QRCode) return Promise.resolve();
  if (qrLibPromise) return qrLibPromise;
  const sources = [
    'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
    '/vendor/qrcode/qrcode.min.js?v=full-3',
    '/assets_setup/js/qrcode.js',
  ];
  qrLibPromise = new Promise(resolve => {
    const tryNext = i => {
      if (i >= sources.length || window.QRCode) return resolve();
      const el = document.createElement('script');
      el.src = sources[i];
      el.onload = () => (window.QRCode ? resolve() : tryNext(i + 1));
      el.onerror = () => tryNext(i + 1);
      document.head.appendChild(el);
    };
    tryNext(0);
  }).then(() => { if (!window.QRCode) qrLibPromise = null; });
  return qrLibPromise;
}

// Renders `text` as a QR into a detached container and returns the canvas
// pixels as a data URL (survives document.write, unlike a live canvas).
function qrDataUrl(text) {
  if (!window.QRCode || !text) return null;
  const host = document.createElement('div');
  host.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
  document.body.appendChild(host);
  try {
    new window.QRCode(host, {
      text: String(text),
      width: 100,
      height: 100,
      correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : 0,
    });
    const canvas = host.querySelector('canvas');
    return canvas ? canvas.toDataURL('image/png') : null;
  } catch (e) {
    return null;
  } finally {
    try { document.body.removeChild(host); } catch (e) {}
  }
}

async function invoicePos(record) {
  try {
    const data = await http.get(`sales_print_invoice/${record.id}`);
    const s = data.sale || {};
    const setting = data.setting || {};
    const ps = data.pos_settings || {};

    // QR data URLs are rendered up-front — the popup document can't host the
    // Vue QR refs the POS uses. Skipped silently if the lib can't load.
    const onByDefault = f => Number(ps[f] ?? 1) !== 0;
    const wantZatca = setting.zatca_enabled && data.zatca_qr && onByDefault('show_zatca_qr');
    const wantInvoiceQr = onByDefault('show_barcode') && s.Ref;
    let zatcaUrl = null;
    let invoiceUrl = null;
    if (wantZatca || wantInvoiceQr) {
      await loadQrLib();
      if (wantZatca) zatcaUrl = qrDataUrl(data.zatca_qr);
      if (wantInvoiceQr) invoiceUrl = qrDataUrl(data.public_invoice_url || s.Ref);
    }

    // Same layout the POS prints (Settings → POS Receipt → layout 1-5),
    // via the shared string port of PosPage's receipt DOM.
    const body = buildPosReceiptHtml({
      t,
      sale: s,
      setting,
      ps,
      symbol: data.symbol || '',
      details: data.details || [],
      payments: data.payments || [],
      formatPrice: v => number(v),
      qr: { zatca: zatcaUrl, invoice: invoiceUrl },
    });

    const paperSize = [58, 80, 88].includes(Number(ps.receipt_paper_size)) ? Number(ps.receipt_paper_size) : 80;
    const fontTags = receiptFontHeadTags(ps, '#invoice-POS');
    const title = String(s.Ref ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    const html = `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title>
<link rel="stylesheet" href="/css/pos_print.css">
<style>
  #invoice-POS .receipt-qr-row{display:flex;flex-direction:row;flex-wrap:nowrap;justify-content:center;align-items:flex-start;gap:10px;width:100%;margin-top:8px;}
  #invoice-POS .receipt-qr-block{display:flex;flex-direction:column;align-items:center;flex:0 0 auto;width:100px;margin:0;}
  #invoice-POS .receipt-qr-title{font-weight:700;font-size:10px;letter-spacing:1px;text-transform:uppercase;text-align:center;margin:0 0 4px;line-height:1.2;display:block;width:100%;}
  #invoice-POS .receipt-qr-canvas img{display:block;margin:0 auto;width:100px;height:100px;}
</style>${fontTags}</head><body class="receipt-${paperSize}"><div id="invoice-POS">
<div style="max-width:400px;margin:0px auto">${body}</div>
</div></body></html>`;
    const w = window.open('', '_blank', 'width=420,height=640');
    if (!w) return;
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.focus();
    whenPrintFontsReady(w, () => { try { w.print(); } catch (e) {} });
  } catch (e) {
    message.error(t('InvalidData'));
  }
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

async function loadPayments(saleId) {
  const data = await http.get(`get_payments_by_sale/${saleId}`);
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
  http.download(`payment_sale_pdf/${payment.id}`, `Payment-${payment.Ref}.pdf`)
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
        await http.delete(`payment_sale/${payment.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadPayments(activeSale.value.id);
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
    : activeSale.value?.due
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
  try {
    if (payEditing.value) {
      await http.put(`payment_sale/${payEditing.value.id}`, {
        sale_id: activeSale.value.id,
        date: f.date,
        montant: (Number(f.montant) || 0).toFixed(2),
        received_amount: (Number(f.received_amount) || 0).toFixed(2),
        change: payChange.value.toFixed(2),
        payment_method_id: f.payment_method_id,
        account_id: f.account_id || null,
        notes: f.notes,
      });
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('payment_sale', {
        sale_id: activeSale.value.id,
        date: f.date,
        payments: [{
          montant: Number(f.montant) || 0,
          payment_method_id: f.payment_method_id,
          account_id: f.account_id || null,
        }],
        montant: (Number(f.montant) || 0).toFixed(2),
        received_amount: (Number(f.received_amount) || 0).toFixed(2),
        change: payChange.value.toFixed(2),
        payment_method_id: f.payment_method_id,
        account_id: f.account_id || null,
        notes: f.notes,
      });
      message.success(t('Successfully_Created'));
    }
    payFormOpen.value = false;
    crud.fetchRows();
    if (paymentsOpen.value) await loadPayments(activeSale.value.id);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paySaving.value = false;
  }
}

// ---------------- shipment ----------------

const shipmentOpen = ref(false);
const shipmentSaving = ref(false);
const shipment = ref({});

async function openShipment(record) {
  try {
    const data = await http.get(`shipments/${record.id}`);
    shipment.value = data.shipment || {};
    shipmentOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function submitShipment() {
  shipmentSaving.value = true;
  try {
    await http.post('shipments', {
      Ref: shipment.value.Ref,
      sale_id: shipment.value.sale_id,
      shipping_address: shipment.value.shipping_address,
      delivered_to: shipment.value.delivered_to,
      shipping_details: shipment.value.shipping_details,
      status: shipment.value.status,
    });
    message.success(t('Successfully_Updated'));
    shipmentOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    shipmentSaving.value = false;
  }
}

// ---------------- documents ----------------

const documentsOpen = ref(false);
const documents = ref([]);
const docFiles = ref([]);
const docUploading = ref(false);

async function loadDocuments(saleId) {
  const data = await http.get(`sales/${saleId}/documents`);
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
  fd.append('sale_id', activeSale.value.id);
  try {
    await http.postForm(`sales/${activeSale.value.id}/documents`, fd);
    message.success(t('Documents_uploaded_successfully'));
    docFiles.value = [];
    await loadDocuments(activeSale.value.id);
    crud.fetchRows();
  } catch (e) {
    message.error(t('Failed_to_upload_documents'));
  } finally {
    docUploading.value = false;
  }
}

function downloadDocument(doc) {
  http.download(`sales/documents/${doc.id}/download`, doc.name || doc.file_name || 'document')
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
        await http.delete(`sales/documents/${doc.id}`);
        message.success(t('Deleted_in_successfully'));
        await loadDocuments(activeSale.value.id);
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

/* Summary cards — same design as the customers/suppliers lists. */
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
