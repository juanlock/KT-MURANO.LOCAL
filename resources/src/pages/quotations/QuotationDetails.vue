<template>
  <div class="page">
    <PageHeader :title="$t('DetailQuote')" :breadcrumb="[$t('ListQuotations'), $t('DetailQuote')]">
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
          <a-button @click="$router.push('/quotations')">
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
          <a-button v-if="auth.can('Quotations_edit')" @click="$router.push(`/quotations/${quote.id}/edit`)">
            <template #icon><EditOutlined /></template>
            {{ $t('EditQuote') }}
          </a-button>
          <!-- Already converted: show a link to the sale instead of the convert button. -->
          <a-button
            v-if="quote.converted_sale_id"
            @click="$router.push(`/sales/${quote.converted_sale_id}`)"
          >
            <template #icon><SwapOutlined /></template>
            {{ $t('Already_Converted') }}{{ quote.converted_sale_ref ? ` — ${quote.converted_sale_ref}` : '' }}
          </a-button>
          <a-button
            v-else-if="auth.can('Quotations_edit')"
            @click="$router.push(`/sales/from-quotation/${quote.id}`)"
          >
            <template #icon><SwapOutlined /></template>
            {{ $t('Convert_to_Invoice') }}
          </a-button>
          <a-button v-if="auth.can('Quotations_delete')" danger @click="removeQuote">
            <template #icon><DeleteOutlined /></template>
            {{ $t('Del') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else id="quote-invoice">
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
          <div class="inv-ref-badge">{{ quote.Ref }}</div>
          <table class="inv-meta">
            <tr><td>{{ $t('date') }}</td><td>{{ dateTime(quote.date) }}</td></tr>
            <tr>
              <td>{{ $t('Status') }}</td>
              <td>
                <a-tag :color="quote.statut === 'sent' ? 'success' : 'processing'">
                  {{ quote.statut === 'sent' ? $t('Sent') : $t('Pending') }}
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
            <div class="inv-box-name">{{ quote.client_name }}</div>
            <div v-if="quote.client_phone">{{ $t('Phone') }}: {{ quote.client_phone }}</div>
            <div v-if="quote.client_email">{{ $t('Email') }}: {{ quote.client_email }}</div>
            <div v-if="quote.client_adr">{{ $t('Adress') }}: {{ quote.client_adr }}</div>
            <div v-if="quote.client_tax">Tax #: {{ quote.client_tax }}</div>
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
          </template>
          <template v-else-if="column.key === 'price'">{{ docMoney(record.price) }}</template>
          <template v-else-if="column.key === 'quantity'">
            {{ num(record.quantity) }} {{ record.unit_sale || record.unitSale }}
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
          <tr><td>Order Tax:</td><td>{{ docMoney(quote.TaxNet) }}</td></tr>
          <tr v-if="Number(quote.discount) > 0">
            <td>Discount:</td>
            <td class="neg">- {{ docMoney(quote.discount) }}</td>
          </tr>
          <tr><td>Shipping:</td><td>{{ docMoney(quote.shipping) }}</td></tr>
          <tr class="grand"><td>{{ $t('Total') }}:</td><td>{{ docMoney(quote.GrandTotal) }}</td></tr>
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
 * GET quotations/{id} → {quote, details, company}. The simplest of the
 * document invoices: no payments, no paid/due — just lines and totals.
 * Convert to Invoice deep-links to the legacy sale-from-quote form.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, PrinterOutlined, EditOutlined, DeleteOutlined,
  FilePdfOutlined, SwapOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useDetailsCurrency } from '../../composables/useDetailsCurrency';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { date, dateTime } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const quote = ref({});

// Multi-Currency: amounts arrive converted into the document's currency;
// the header selector re-expresses them in any other currency (view-only).
const { docMoney, currencyOptions, currencySelectId, showCurrencySelect } = useDetailsCurrency(quote);
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
    await http.download(`quote_pdf/${quote.value.id}`, `Quotation_${quote.value.Ref}.pdf`);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    downloadingPdf.value = false;
  }
}

function removeQuote() {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Delete_Text')} — ${quote.value.Ref}`,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`quotations/${quote.value.id}`);
        message.success(t('Deleted_in_successfully'));
        router.push('/quotations');
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

onMounted(async () => {
  try {
    const data = await http.get(`quotations/${route.params.id}`);
    quote.value = data.quote || {};
    details.value = data.details || [];
    company.value = data.company || {};
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/quotations');
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
  #quote-invoice {
    box-shadow: none !important;
    border: none !important;
  }
}
</style>
