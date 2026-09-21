<template>
  <div class="page">
    <PageHeader :title="$t('SaleDetail')" :breadcrumb="[$t('Sales'), $t('SaleDetail')]">
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
          <a-button @click="$router.push('/sales')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button
            v-if="auth.can('Sales_edit') && sale.sale_has_return === 'no'"
            @click="$router.push(`/sales/${sale.id}/edit`)"
          >
            <template #icon><EditOutlined /></template>
            {{ $t('EditSale') }}
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
            v-if="auth.can('Sales_delete') && sale.sale_has_return === 'no'"
            danger
            @click="removeSale"
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

    <a-card v-else id="sale-invoice">
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
        <div class="inv-ref">
          <div class="inv-ref-badge">{{ sale.Ref }}</div>
          <table class="inv-meta">
            <tr><td>{{ $t('date') }}</td><td>{{ dateTime(sale.date) }}</td></tr>
            <tr>
              <td>{{ $t('Status') }}</td>
              <td>
                <a-tag :color="docStatusColor(sale.statut)">
                  {{ statusKey(SALE_STATUSES, sale.statut) ? $t(statusKey(SALE_STATUSES, sale.statut)) : sale.statut }}
                </a-tag>
              </td>
            </tr>
            <tr>
              <td>{{ $t('PaymentStatus') }}</td>
              <td>
                <a-tag :color="payStatusColor(sale.payment_status)">
                  {{ statusKey(PAYMENT_STATUSES, sale.payment_status) ? $t(statusKey(PAYMENT_STATUSES, sale.payment_status)) : sale.payment_status }}
                </a-tag>
              </td>
            </tr>
          </table>
        </div>
      </div>

      <a-row :gutter="[16, 16]" style="margin: 16px 0">
        <a-col :xs="24" :md="12">
          <div class="inv-box">
            <div class="inv-box-title">{{ $t('Customer') }}</div>
            <div class="inv-box-name">{{ sale.client_name }}</div>
            <div v-if="sale.client_phone">{{ $t('Phone') }}: {{ sale.client_phone }}</div>
            <div v-if="sale.client_email">{{ $t('Email') }}: {{ sale.client_email }}</div>
            <div v-if="sale.client_adr">{{ $t('Adress') }}: {{ sale.client_adr }}</div>
            <div v-if="sale.client_tax">Tax #: {{ sale.client_tax }}</div>
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
            <div v-if="record.is_batch_tracked && (record.batches || []).length">
              <a-tag v-for="(b, i) in record.batches" :key="i" style="margin-top: 2px">
                {{ b.batch_number || b.batch_no || b }}
              </a-tag>
            </div>
          </template>
          <template v-else-if="column.key === 'price'">{{ docMoney(record.price) }}</template>
          <template v-else-if="column.key === 'quantity'">
            {{ num(record.quantity) }} {{ record.pack_name || record.unit_sale }}
            <div v-if="record.pack_name && Number(record.pack_multiplier) > 1" class="muted">
              (×{{ record.pack_multiplier }}) = {{ num(record.quantity * record.pack_multiplier) }} {{ record.unit_sale }}
            </div>
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
          <tr><td>Order Tax:</td><td>{{ docMoney(sale.TaxNet) }}</td></tr>
          <tr v-if="Number(sale.discount) > 0">
            <td>Discount:</td>
            <td class="neg">
              <template v-if="String(sale.discount_Method || '2') === '1'">
                - {{ num(sale.discount) }}% ({{ docMoney(discountAmount) }})
              </template>
              <template v-else>- {{ docMoney(discountAmount) }}</template>
            </td>
          </tr>
          <tr v-if="Number(sale.discount_from_points) > 0">
            <td>Discount from Points:</td>
            <td class="neg">- {{ docMoney(sale.discount_from_points) }}</td>
          </tr>
          <tr><td>Shipping:</td><td>{{ docMoney(sale.shipping) }}</td></tr>
          <tr class="grand"><td>{{ $t('Total') }}:</td><td>{{ docMoney(sale.GrandTotal) }}</td></tr>
          <tr><td>{{ $t('Paid') }}:</td><td style="color: #52c41a">{{ docMoney(sale.paid_amount) }}</td></tr>
          <tr><td>{{ $t('Due') }}:</td><td style="color: #ff4d4f">{{ docMoney(sale.due) }}</td></tr>
          <tr v-if="Number(sale.previous_dues) > 0">
            <td>{{ $t('Previous_Dues') }}:</td><td>{{ docMoney(sale.previous_dues) }}</td>
          </tr>
          <tr v-if="Number(sale.previous_dues) > 0">
            <td>{{ $t('Net_Balance') }}:</td>
            <td>{{ docMoney(Number(sale.previous_dues) + Number(sale.due)) }}</td>
          </tr>
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
 * GET sales/{id} → {sale, details, company}. Invoice-style page; discount math
 * mirrors legacy: subtotal = Σ line totals, percent discounts (discount_Method
 * '1') apply to that subtotal, fixed ones are capped at it. Edit/Delete hidden
 * once the sale has a return. Print uses the browser with a print-scoped
 * stylesheet — the PDF/email/SMS actions stay legacy for now.
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
import { SALE_STATUSES, PAYMENT_STATUSES, statusKey } from './saleVocab';
import http from '../../lib/http';

const { t } = useI18n();
const { date, dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const sale = ref({});

// Multi-Currency: amounts arrive converted into the document's currency;
// the header selector re-expresses them in any other currency (view-only).
const { docMoney, currencyOptions, currencySelectId, showCurrencySelect } = useDetailsCurrency(sale);
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

const discountAmount = computed(() => {
  const method = String(sale.value.discount_Method || '2');
  const val = Number(sale.value.discount) || 0;
  if (subtotal.value <= 0) return 0;
  if (method === '1') return +(subtotal.value * (val / 100)).toFixed(2);
  return +Math.min(val, subtotal.value).toFixed(2);
});

const itemColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Price'), key: 'price', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
  { title: t('Discount'), key: 'discount', align: 'right' },
  { title: t('Tax'), key: 'tax', align: 'right' },
  { title: t('Total'), key: 'total', align: 'right' },
]);

function printInvoice() {
  window.print();
}

// Same endpoints as legacy detail_sale.vue.
async function sendEmail() {
  sendingEmail.value = true;
  try {
    await http.post('sales_send_email', { id: sale.value.id });
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
    await http.post('sales_send_sms', { id: sale.value.id });
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
    await http.download(`sale_pdf/${sale.value.id}`, `Sale_${sale.value.Ref}.pdf`);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloadingPdf.value = false;
  }
}

function removeSale() {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Delete_Text')} — ${sale.value.Ref}`,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`sales/${sale.value.id}`);
        message.success(t('Deleted_in_successfully'));
        router.push('/sales');
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

onMounted(async () => {
  try {
    const data = await http.get(`sales/${route.params.id}`);
    sale.value = data.sale || {};
    details.value = data.details || [];
    company.value = data.company || {};
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/sales');
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
  /* Print just the invoice card. */
  :global(.ant-layout-sider),
  :global(.ant-layout-header),
  :global(.page-header) {
    display: none !important;
  }
  #sale-invoice {
    box-shadow: none !important;
    border: none !important;
  }
}
</style>
