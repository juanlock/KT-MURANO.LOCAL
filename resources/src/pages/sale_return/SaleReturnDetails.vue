<template>
  <div class="page">
    <PageHeader :title="$t('ReturnDetail')" :breadcrumb="[$t('SalesReturn'), $t('ReturnDetail')]">
      <template #actions>
        <a-space wrap>
          <!-- Multi-Currency: view this document's amounts in another currency -->
          <a-select
            v-if="showCurrencySelect"
            v-model:value="currencySelectId"
            :options="currencyOptions"
            :title="$t('Currency')"
            style="min-width: 92px"
          />
          <a-button @click="$router.push('/sale-returns')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button @click="printInvoice">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
          <a-button :loading="downloadingPdf" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('DownloadPdf') }}
          </a-button>
          <a-button v-if="auth.can('Sale_Returns_edit')" @click="$router.push(`/sale-returns/${ret.id}/edit/${ret.sale_id}`)">
            <template #icon><EditOutlined /></template>
            {{ $t('EditReturn') }}
          </a-button>
          <a-button v-if="auth.can('Sale_Returns_delete')" danger @click="removeReturn">
            <template #icon><DeleteOutlined /></template>
            {{ $t('Del') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else id="return-invoice">
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
          <div class="inv-ref-badge">{{ ret.Ref }}</div>
          <table class="inv-meta">
            <tr><td>{{ $t('date') }}</td><td>{{ dateTime(ret.date) }}</td></tr>
            <tr>
              <td>{{ $t('Status') }}</td>
              <td>
                <a-tag :color="ret.statut === 'received' ? 'success' : 'processing'">
                  {{ ret.statut === 'received' ? $t('Received') : $t('Pending') }}
                </a-tag>
              </td>
            </tr>
            <tr>
              <td>{{ $t('PaymentStatus') }}</td>
              <td>
                <a-tag :color="payStatusColor(ret.payment_status)">
                  {{ statusKey(PAYMENT_STATUSES, ret.payment_status) ? $t(statusKey(PAYMENT_STATUSES, ret.payment_status)) : ret.payment_status }}
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
            <div class="inv-box-name">{{ ret.client_name }}</div>
            <div v-if="ret.client_phone">{{ $t('Phone') }}: {{ ret.client_phone }}</div>
            <div v-if="ret.client_email">{{ $t('Email') }}: {{ ret.client_email }}</div>
            <div v-if="ret.client_adr">{{ $t('Adress') }}: {{ ret.client_adr }}</div>
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
          <template v-else-if="column.key === 'quantity'">{{ num(record.quantity) }} {{ record.unit_sale }}</template>
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
          <tr><td>Order Tax:</td><td>{{ docMoney(ret.TaxNet) }}</td></tr>
          <tr v-if="Number(ret.discount) > 0">
            <td>Discount:</td>
            <td class="neg">- {{ docMoney(ret.discount) }}</td>
          </tr>
          <tr v-if="Number(ret.shipping) > 0"><td>Shipping:</td><td>{{ docMoney(ret.shipping) }}</td></tr>
          <tr class="grand"><td>{{ $t('Total') }}:</td><td>{{ docMoney(ret.GrandTotal) }}</td></tr>
          <tr><td>{{ $t('Paid') }}:</td><td style="color: #52c41a">{{ docMoney(ret.paid_amount) }}</td></tr>
          <tr><td>{{ $t('Due') }}:</td><td style="color: #ff4d4f">{{ docMoney(ret.due) }}</td></tr>
        </table>
      </div>

      <div v-if="ret.note" class="inv-footer">{{ ret.note }}</div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * GET returns/sale/{id} → {sale_Return (odd casing), details, company}.
 * Same invoice layout as the sale detail; lines use price/unit_sale.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, PrinterOutlined, EditOutlined, DeleteOutlined,
  FilePdfOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useDetailsCurrency } from '../../composables/useDetailsCurrency';
import { useAuthStore } from '../../stores/auth';
import { payStatusColor } from '../../lib/statusColors';
import { PAYMENT_STATUSES, statusKey } from '../sales/saleVocab';
import http from '../../lib/http';

const { t } = useI18n();
const { date, dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const ret = ref({});

// Multi-Currency: amounts arrive converted into the document's currency;
// the header selector re-expresses them in any other currency (view-only).
const { docMoney, currencyOptions, currencySelectId, showCurrencySelect } = useDetailsCurrency(ret);
const details = ref([]);
const company = ref({});

const num = v => {
  const n = Number(v);
  return Number.isFinite(n) ? +n.toFixed(2) : 0;
};

const subtotal = computed(() =>
  details.value.reduce((sum, d) => sum + (Number(d?.total) || 0), 0)
);

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

const downloadingPdf = ref(false);
async function downloadPdf() {
  downloadingPdf.value = true;
  try {
    await http.download(`return_sale_pdf/${ret.value.id}`, `Sale_Return_${ret.value.Ref}.pdf`);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloadingPdf.value = false;
  }
}

function removeReturn() {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Delete_Text')} — ${ret.value.Ref}`,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`returns/sale/${ret.value.id}`);
        message.success(t('Deleted_in_successfully'));
        router.push('/sale-returns');
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

onMounted(async () => {
  try {
    const data = await http.get(`returns/sale/${route.params.id}`);
    ret.value = data.sale_Return || {};
    details.value = data.details || [];
    company.value = data.company || {};
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/sale-returns');
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped src="./returnInvoice.css"></style>
