<template>
  <div class="page">
    <PageHeader :title="c ? `${c.contract_number} — ${c.subject}` : $t('tx_Sales_Contract_4f479')" :breadcrumb="[$t('Sales'), 'Sales Contracts', c ? c.contract_number : '']">
      <template #actions>
        <a-space wrap>
          <a-tooltip :title="c && c.status !== 'active' ? $t('tx_Only_active_contracts_are_invoiced_60c21') : ''">
            <a-button
              v-if="can('sales_contracts_invoice')" type="primary" :loading="invoicing"
              :disabled="!c || c.status !== 'active' || !c.items.length" @click="generate"
            >
              <template #icon><FileAddOutlined /></template>
              {{ $t('tx_Generate_invoice_80cb3') }}{{ c && c.billing_enabled && c.next_invoice_date ? ` (${date(c.next_invoice_date)} period)` : '' }}
            </a-button>
          </a-tooltip>
          <a-button v-if="can('sales_contracts_edit') && c" @click="$router.push(`/sales-contracts/${c.id}/edit`)">
            <template #icon><EditOutlined /></template>{{ $t('Edit') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0"><a-spin size="large" /></div>

    <template v-else-if="c">
      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :xl="16">
          <a-card size="small" style="margin-bottom: 16px">
            <div class="sc-head">
              <a-tag :color="STATUS_COLORS[c.status]">{{ statusLabel(c.status) }}</a-tag>
              <a-tag v-if="c.type">{{ c.type }}</a-tag>
              <a-tag v-if="c.hide_from_customer">{{ $t('tx_Hidden_from_portal_013e1') }}</a-tag>
            </div>
            <a-descriptions :column="{ xs: 1, md: 2 }" size="small">
              <a-descriptions-item :label="$t('Customer')">{{ c.client?.name }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Location')">{{ c.warehouse?.name || 'Company default' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('tx_Start_a6122')">{{ date(c.start_date) }}</a-descriptions-item>
              <a-descriptions-item :label="$t('tx_End_87557')">{{ c.end_date ? date(c.end_date) : $t('tx_Open_ended_6102b') }}</a-descriptions-item>
              <a-descriptions-item :label="$t('tx_Value_per_invoice_68956')"><b>{{ money(c.value) }}</b><span v-if="c.order_tax_rate > 0" style="opacity: 0.7"> {{ $t('tx_incl_order_tax_23b85') }} {{ c.order_tax_rate }}%)</span></a-descriptions-item>
              <a-descriptions-item :label="$t('tx_Signed_71fed')">{{ c.signed_at ? `${c.signer_name || ''} · ${date(c.signed_at)}` : '—' }}</a-descriptions-item>
            </a-descriptions>
            <div v-if="c.description" class="sc-desc" v-html="c.description"></div>
          </a-card>

          <a-card size="small" :title="$t('tx_Products_services_billed_41188')" style="margin-bottom: 16px">
            <a-table :data-source="c.items" :columns="lineColumns" :pagination="false" size="small" row-key="id">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'name'">
                  {{ record.name }}
                  <div v-if="record.note" style="font-size: 12px; opacity: 0.65">{{ $t('tx_Invoice_note_b8469') }} {{ record.note }}</div>
                </template>
                <template v-else-if="column.key === 'price'">{{ money(record.price) }}</template>
                <template v-else-if="column.key === 'tax'">{{ record.tax_percent ? record.tax_percent + '%' : '—' }}</template>
                <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
              </template>
            </a-table>
          </a-card>

          <a-card size="small" :title="`Invoices generated (${c.invoices.length})`">
            <a-table :data-source="c.invoices" :columns="invoiceColumns" :pagination="{ pageSize: 10 }" size="small" row-key="id">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'ref'">
                  <PermLink permission="Sales_view" v-if="record.sale_id" :to="`/sales/${record.sale_id}`">{{ record.ref }}</PermLink>
                </template>
                <template v-else-if="column.key === 'date'">{{ record.date ? date(record.date) : '—' }}</template>
                <template v-else-if="column.key === 'period'">{{ record.period_start ? `${date(record.period_start)} – ${date(record.period_end)}` : 'One-off' }}</template>
                <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
                <template v-else-if="column.key === 'payment'">
                  <a-tag :color="record.payment_status === 'paid' ? 'green' : record.payment_status === 'partial' ? 'orange' : 'red'">{{ record.payment_status }}</a-tag>
                </template>
                <template v-else-if="column.key === 'how'">{{ record.automatic ? $t('tx_Automatic_08624') : 'Manual' }}</template>
              </template>
            </a-table>
          </a-card>

          <!-- Service jobs (visits) this contract created. -->
          <a-card v-if="c.jobs_enabled || (c.service_jobs || []).length" size="small" :title="`Service jobs created (${(c.service_jobs || []).length})`" style="margin-top: 16px">
            <a-table :data-source="c.service_jobs || []" :columns="jobColumns" :pagination="{ pageSize: 10 }" size="small" row-key="id">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'ref'">
                  <PermLink permission="service_jobs" :to="`/service/jobs/details/${record.id}`">{{ record.ref }}</PermLink>
                </template>
                <template v-else-if="column.key === 'date'">{{ record.date ? `${date(record.date)} ${String(record.date).slice(11, 16)}` : '—' }}</template>
                <template v-else-if="column.key === 'status'"><a-tag>{{ record.status }}</a-tag></template>
              </template>
            </a-table>
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card size="small" :title="$t('tx_Recurring_invoices_3f486')">
            <template v-if="c.billing_enabled">
              <div class="sc-big"><SyncOutlined /> {{ billingLabel(c) }}</div>
              <a-descriptions :column="1" size="small" style="margin-top: 8px">
                <a-descriptions-item :label="$t('tx_Next_invoice_421a0')">
                  <a-tag v-if="c.next_invoice_date && c.next_invoice_date <= today" color="orange">{{ date(c.next_invoice_date) }} {{ $t('tx_due_9a1ee') }}</a-tag>
                  <template v-else>{{ c.next_invoice_date ? date(c.next_invoice_date) : '—' }}</template>
                </a-descriptions-item>
                <a-descriptions-item :label="$t('tx_Last_invoice_735c8')">{{ c.last_invoice_date ? date(c.last_invoice_date) : '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('tx_Invoices_created_8fe78')">
                  {{ c.invoices_created || 0 }}{{ c.billing_count ? ` of ${c.billing_count}` : '' }}
                </a-descriptions-item>
                <a-descriptions-item :label="$t('Amount')">{{ money(c.value) }}</a-descriptions-item>
                <a-descriptions-item v-if="c.invoice_note" :label="$t('tx_Invoice_note_93f39')">{{ c.invoice_note }}</a-descriptions-item>
              </a-descriptions>
              <div v-if="c.status !== 'active'" style="color: #f97316; font-size: 12px">{{ $t('tx_Paused_the_contract_is_not_Active_87a49') }}</div>
            </template>
            <div v-else style="opacity: 0.6">{{ $t('tx_One_off_no_automatic_invoices_Use_Generate_invoice_when_need_a0ed8') }}</div>
          </a-card>

          <a-card v-if="c.jobs_enabled" size="small" :title="$t('Contract_Service_Jobs')" style="margin-top: 16px">
            <div class="sc-big"><SyncOutlined /> {{ $t('tx_Every_ffcf7') }} {{ c.jobs_interval > 1 ? `${c.jobs_interval} ` : '' }}{{ { weekly: 'week', monthly: 'month', quarterly: 'quarter', yearly: 'year' }[c.jobs_frequency] }}{{ c.jobs_interval > 1 ? 's' : '' }}{{ c.jobs_time ? ` at ${c.jobs_time}` : '' }}</div>
            <a-descriptions :column="1" size="small" style="margin-top: 8px">
              <a-descriptions-item :label="$t('tx_Next_visit_7c5f6')">{{ c.next_job_date ? date(c.next_job_date) : '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('tx_Visits_created_f53f6')">{{ c.jobs_created || 0 }}{{ c.jobs_count ? ` of ${c.jobs_count}` : '' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Operator')">{{ c.jobs_technician || 'Not assigned' }}</a-descriptions-item>
              <a-descriptions-item v-if="c.jobs_service_item" :label="$t('tx_Visit_title_78f3b')">{{ c.jobs_service_item }}</a-descriptions-item>
              <a-descriptions-item :label="$t('tx_Created_ahead_2ec9f')">{{ c.jobs_lead_days }} day(s) before each visit</a-descriptions-item>
            </a-descriptions>
            <div v-if="c.status !== 'active'" style="color: #f97316; font-size: 12px">{{ $t('tx_Paused_the_contract_is_not_Active_87a49') }}</div>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
/**
 * Sales Contract detail (GET sales_contracts/{id}): contract, lines,
 * generated invoices (links to the sales) and "Generate invoice" (POST
 * sales_contracts/{id}/invoice, sales_contracts_invoice permission).
 */
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import dayjs from 'dayjs';
import { message, Modal } from 'ant-design-vue';
import { EditOutlined, FileAddOutlined, SyncOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { STATUS_COLORS, statusLabel, billingLabel } from './salesContractVocab';

const route = useRoute();
const auth = useAuthStore();
const can = p => auth.user?.type === 'SuperAdmin' || auth.can(p);
const { money, date } = useFormat();
const today = dayjs().format('YYYY-MM-DD');

const loading = ref(true);
const invoicing = ref(false);
const c = ref(null);

const lineColumns = [
  { title: tEs('Description', 'Description'), dataIndex: 'name', key: 'name' },
  { title: tEs('Qty', 'Qty'), dataIndex: 'quantity', key: 'quantity', width: 80 },
  { title: tEs('Price', 'Price'), key: 'price', width: 110, align: 'right' },
  { title: tEs('Tax', 'Tax'), key: 'tax', width: 80 },
  { title: 'Total', key: 'total', width: 110, align: 'right' },
];
const invoiceColumns = [
  { title: tEs('Invoice', 'Invoice'), key: 'ref' },
  { title: tEs('date', 'Date'), key: 'date' },
  { title: tEs('Period', 'Period'), key: 'period' },
  { title: 'Total', key: 'total', align: 'right' },
  { title: tEs('Payment', 'Payment'), key: 'payment' },
  { title: tEs('tx_How_13257', 'How'), key: 'how' },
];

const jobColumns = [
  { title: tEs('Job', 'Job'), key: 'ref' },
  { title: '#', dataIndex: 'number', key: 'number', width: 60 },
  { title: tEs('tx_Visit_5e706', 'Visit'), key: 'date' },
  { title: tEs('title', 'Title'), dataIndex: 'title', key: 'title', ellipsis: true },
  { title: tEs('Operator', 'Operator'), dataIndex: 'technician', key: 'technician' },
  { title: tEs('Serial_Status', 'Status'), key: 'status' },
];

async function load() {
  loading.value = true;
  try {
    c.value = (await http.get(`sales_contracts/${route.params.id}`)).contract;
  } catch (e) {
    message.error(e?.data?.message || 'Contract not found');
  } finally {
    loading.value = false;
  }
}

function generate() {
  Modal.confirm({
    title: tEs('tx_Generate_invoice_9a4c6', 'Generate invoice?'),
    content: `Creates an unpaid sale of ${money(c.value.value)} for ${c.value.client?.name}${c.value.billing_enabled ? ' and moves the next invoice date one period on' : ''}.`,
    okText: tEs('Generate', 'Generate'),
    async onOk() {
      invoicing.value = true;
      try {
        const r = await http.post(`sales_contracts/${c.value.id}/invoice`, {});
        message.success(`Invoice ${r.ref} created (${money(r.total)})`);
        await load();
      } catch (e) {
        message.error(e?.data?.message || 'Could not generate the invoice');
      } finally {
        invoicing.value = false;
      }
    },
  });
}

onMounted(load);
</script>

<style scoped>
.sc-head {
  display: flex;
  gap: 6px;
  margin-bottom: 10px;
}
.sc-desc {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid rgba(128, 128, 128, 0.15);
}
.sc-big {
  font-size: 18px;
  font-weight: 600;
  color: #6d28d9;
}
</style>
