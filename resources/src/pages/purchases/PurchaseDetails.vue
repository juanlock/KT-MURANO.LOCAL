<template>
  <div class="page">
    <PageHeader :title="$t('PurchaseDetail')" :breadcrumb="[$t('ListPurchases'), $t('PurchaseDetail')]">
      <template #actions>
        <a-space v-if="!loading" wrap>
          <!-- Multi-Currency: view this document's amounts in another currency -->
          <a-select
            v-if="showCurrencySelect"
            v-model:value="currencySelectId"
            :options="currencyOptions"
            :title="$t('Currency')"
            style="min-width: 92px"
          />
          <a-button @click="$router.push('/purchases')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button
            v-if="auth.can('Purchases_edit') && purchase.purchase_has_return === 'no'"
            @click="$router.push(`/purchases/${purchase.id}/edit`)"
          >
            <template #icon><EditOutlined /></template>
            {{ $t('EditPurchase') }}
          </a-button>
          <a-button :loading="sendingEmail" @click="sendEmail">
            <template #icon><MailOutlined /></template>
            {{ $t('Email') }}
          </a-button>
          <a-button :loading="sendingSms" @click="sendSms">
            <template #icon><MessageOutlined /></template>
            SMS
          </a-button>
          <a-button :loading="downloadingPdf" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template>
            PDF
          </a-button>
          <a-button @click="printInvoice">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
          <a-button
            v-if="auth.can('Purchases_delete') && purchase.purchase_has_return === 'no'"
            danger
            @click="removePurchase"
          >
            <template #icon><DeleteOutlined /></template>
            {{ $t('Del') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else id="purchase-invoice">
      <div class="inv-head">
        <div>
          <img
            v-if="company.logo"
            :src="'/images/' + company.logo"
            :style="{
              maxWidth: (company.invoice_logo_width || 180) + 'px',
              maxHeight: (company.invoice_logo_height || 60) + 'px',
            }"
            alt=""
          />
        </div>
        <div>
          <div class="inv-ref-badge">{{ purchase.Ref }}</div>
          <table class="inv-meta">
            <tr><td>{{ $t('date') }}</td><td>{{ dateTime(purchase.date) }}</td></tr>
            <tr>
              <td>{{ $t('Status') }}</td>
              <td>
                <a-tag :color="docStatusColor(purchase.statut)">
                  {{ statusKey(PURCHASE_STATUSES, purchase.statut) ? $t(statusKey(PURCHASE_STATUSES, purchase.statut)) : purchase.statut }}
                </a-tag>
              </td>
            </tr>
            <tr>
              <td>{{ $t('PaymentStatus') }}</td>
              <td>
                <a-tag :color="payStatusColor(purchase.payment_status)">
                  {{ statusKey(PAYMENT_STATUSES, purchase.payment_status) ? $t(statusKey(PAYMENT_STATUSES, purchase.payment_status)) : purchase.payment_status }}
                </a-tag>
              </td>
            </tr>
          </table>
        </div>
      </div>

      <a-row :gutter="[16, 16]" style="margin: 16px 0">
        <a-col :xs="24" :md="12">
          <div class="inv-box">
            <div class="inv-box-title">{{ $t('Supplier') }}</div>
            <div class="inv-box-name">{{ purchase.supplier_name }}</div>
            <div v-if="purchase.supplier_phone">{{ $t('Phone') }}: {{ purchase.supplier_phone }}</div>
            <div v-if="purchase.supplier_email">{{ $t('Email') }}: {{ purchase.supplier_email }}</div>
            <div v-if="purchase.supplier_adr">{{ $t('Adress') }}: {{ purchase.supplier_adr }}</div>
            <div v-if="purchase.supplier_tax">Tax #: {{ purchase.supplier_tax }}</div>
          </div>
        </a-col>
        <a-col :xs="24" :md="12">
          <div class="inv-box">
            <div class="inv-box-title">{{ $t('Company') }}</div>
            <div class="inv-box-name">{{ company.CompanyName }}</div>
            <div v-if="company.CompanyPhone">{{ $t('Phone') }}: {{ company.CompanyPhone }}</div>
            <div v-if="company.email">{{ $t('Email') }}: {{ company.email }}</div>
            <div v-if="company.CompanyAdress">{{ $t('Adress') }}: {{ company.CompanyAdress }}</div>
          </div>
        </a-col>
      </a-row>

      <a-table
        :columns="itemColumns"
        :data-source="details"
        :pagination="false"
        size="middle"
        :row-key="(r, i) => i"
        :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'product'">
            <div style="font-weight: 500">{{ record.name }}</div>
            <div class="muted">{{ record.code }}</div>
            <div v-if="record.is_imei && record.imei_number" class="muted">SN: {{ record.imei_number }}</div>
            <div v-if="(record.batches || []).length">
              <a-tag v-for="(b, i) in record.batches" :key="i" style="margin-top: 2px">
                {{ b.batch_number || b.batch_no || b }}
              </a-tag>
            </div>
          </template>
          <template v-else-if="column.key === 'cost'">{{ docMoney(record.cost) }}</template>
          <template v-else-if="column.key === 'quantity'">
            {{ num(record.quantity) }} {{ record.unit_purchase }}
          </template>
          <!-- DiscountNet is per unit; this column is a line total like Tax below. -->
          <template v-else-if="column.key === 'discount'">{{ docMoney(record.DiscountNet * record.quantity) }}</template>
          <template v-else-if="column.key === 'tax'">{{ docMoney(record.taxe * record.quantity) }}</template>
          <template v-else-if="column.key === 'total'">{{ docMoney(record.total) }}</template>
        </template>
      </a-table>

      <div class="inv-summary">
        <!-- Summary labels hardcoded English in legacy's invoice too. -->
        <table>
          <tr><td>Subtotal:</td><td>{{ docMoney(subtotal) }}</td></tr>
          <tr><td>Order Tax:</td><td>{{ docMoney(purchase.TaxNet) }}</td></tr>
          <tr v-if="Number(purchase.discount) > 0">
            <td>Discount:</td>
            <td class="neg">- {{ docMoney(purchase.discount) }}</td>
          </tr>
          <tr><td>Shipping:</td><td>{{ docMoney(purchase.shipping) }}</td></tr>
          <tr class="grand"><td>{{ $t('Total') }}:</td><td>{{ docMoney(purchase.GrandTotal) }}</td></tr>
          <tr><td>{{ $t('Paid') }}:</td><td style="color: #52c41a">{{ docMoney(purchase.paid_amount) }}</td></tr>
          <tr><td>{{ $t('Due') }}:</td><td style="color: #ff4d4f">{{ docMoney(purchase.due) }}</td></tr>
        </table>
      </div>

      <div v-if="company.is_invoice_footer && company.invoice_footer" class="inv-footer">
        {{ company.invoice_footer }}
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * GET purchases/{id} → {purchase, details, company}. Simpler than the sale
 * invoice: lines carry cost/unit_purchase (no packs), the order discount is a
 * plain fixed amount, and there are no points/previous-dues rows. Edit/Delete
 * hidden once the purchase has a return.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, PrinterOutlined, EditOutlined, DeleteOutlined,
  ExclamationCircleOutlined, MailOutlined, MessageOutlined, FilePdfOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useDetailsCurrency } from '../../composables/useDetailsCurrency';
import { useAuthStore } from '../../stores/auth';
import { docStatusColor, payStatusColor } from '../../lib/statusColors';
import { PAYMENT_STATUSES, statusKey } from '../sales/saleVocab';
import { PURCHASE_STATUSES } from './purchaseVocab';
import http from '../../lib/http';

const { t } = useI18n();
const { date, dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const purchase = ref({});

// Multi-Currency: amounts arrive converted into the document's currency;
// the header selector re-expresses them in any other currency (view-only).
const { docMoney, currencyOptions, currencySelectId, showCurrencySelect } = useDetailsCurrency(purchase);
const details = ref([]);
const company = ref({});
const sendingEmail = ref(false);
const sendingSms = ref(false);
const downloadingPdf = ref(false);

const num = v => {
  const n = Number(v);
  return Number.isFinite(n) ? +n.toFixed(2) : 0;
};

const subtotal = computed(() =>
  details.value.reduce((sum, d) => sum + (Number(d?.total) || 0), 0)
);

const itemColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Cost'), key: 'cost', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
  { title: t('Discount'), key: 'discount', align: 'right' },
  { title: t('Tax'), key: 'tax', align: 'right' },
  { title: t('Total'), key: 'total', align: 'right' },
]);

function printInvoice() {
  window.print();
}

// Same endpoints as legacy detail_purchase.vue.
async function sendEmail() {
  sendingEmail.value = true;
  try {
    await http.post('purchase_send_email', { id: purchase.value.id });
    message.success(t('SendEmail'));
  } catch (e) {
    message.error(t('SMTPIncorrect'));
  } finally {
    sendingEmail.value = false;
  }
}

async function sendSms() {
  sendingSms.value = true;
  try {
    await http.post('purchase_send_sms', { id: purchase.value.id });
    message.success(t('Send_SMS'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    sendingSms.value = false;
  }
}

async function downloadPdf() {
  downloadingPdf.value = true;
  try {
    await http.download(`purchase_pdf/${purchase.value.id}`, `Purchase_${purchase.value.Ref}.pdf`);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloadingPdf.value = false;
  }
}

function removePurchase() {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Delete_Text')} — ${purchase.value.Ref}`,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`purchases/${purchase.value.id}`);
        message.success(t('Deleted_in_successfully'));
        router.push('/purchases');
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

onMounted(async () => {
  try {
    const data = await http.get(`purchases/${route.params.id}`);
    purchase.value = data.purchase || {};
    details.value = data.details || [];
    company.value = data.company || {};
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/purchases');
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.inv-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
}
.inv-ref-badge {
  display: inline-block;
  background: #6d28d9;
  color: #fff;
  font-weight: 600;
  padding: 4px 14px;
  border-radius: 6px;
  margin-bottom: 8px;
}
.inv-meta td {
  padding: 2px 8px 2px 0;
  color: rgba(0, 0, 0, 0.65);
  font-size: 13px;
}
.inv-meta td:first-child {
  font-weight: 500;
}
.inv-box {
  border: 1px solid rgba(5, 5, 5, 0.08);
  border-radius: 8px;
  padding: 12px 16px;
  height: 100%;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.65);
}
.inv-box-title {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 4px;
}
.inv-box-name {
  font-weight: 600;
  font-size: 15px;
  color: rgba(0, 0, 0, 0.88);
  margin-bottom: 4px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.inv-summary {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
.inv-summary table {
  min-width: 320px;
  border-collapse: collapse;
}
.inv-summary td {
  padding: 6px 8px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
  font-size: 13px;
}
.inv-summary td:last-child {
  text-align: right;
  font-weight: 500;
}
.inv-summary .neg {
  color: #ff4d4f;
}
.inv-summary .grand td {
  font-size: 15px;
  font-weight: 700;
  border-top: 2px solid rgba(5, 5, 5, 0.15);
}
.inv-footer {
  margin-top: 24px;
  padding-top: 12px;
  border-top: 1px dashed rgba(5, 5, 5, 0.15);
  color: rgba(0, 0, 0, 0.45);
  font-size: 13px;
  text-align: center;
}

@media print {
  :global(.ant-layout-sider),
  :global(.ant-layout-header),
  :global(.page-header) {
    display: none !important;
  }
  #purchase-invoice {
    box-shadow: none !important;
    border: none !important;
  }
}
</style>
