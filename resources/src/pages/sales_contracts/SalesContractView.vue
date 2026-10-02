<template>
  <div class="page">
    <PageHeader :title="c ? `${c.contract_number} — ${c.subject}` : 'Sales Contract'" :breadcrumb="[$t('Sales'), 'Sales Contracts', c ? c.contract_number : '']">
      <template #actions>
        <a-space wrap>
          <a-tooltip :title="c && c.status !== 'active' ? 'Only active contracts are invoiced' : ''">
            <a-button
              v-if="can('sales_contracts_invoice')" type="primary" :loading="invoicing"
              :disabled="!c || c.status !== 'active' || !c.items.length" @click="generate"
            >
              <template #icon><FileAddOutlined /></template>
              Generate invoice{{ c && c.billing_enabled && c.next_invoice_date ? ` (${date(c.next_invoice_date)} period)` : '' }}
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
              <a-tag v-if="c.hide_from_customer">Hidden from portal</a-tag>
            </div>
            <a-descriptions :column="{ xs: 1, md: 2 }" size="small">
              <a-descriptions-item :label="$t('Customer')">{{ c.client?.name }}</a-descriptions-item>
              <a-descriptions-item label="Location">{{ c.warehouse?.name || 'Company default' }}</a-descriptions-item>
              <a-descriptions-item label="Start">{{ date(c.start_date) }}</a-descriptions-item>
              <a-descriptions-item label="End">{{ c.end_date ? date(c.end_date) : 'Open-ended' }}</a-descriptions-item>
              <a-descriptions-item label="Value per invoice"><b>{{ money(c.value) }}</b></a-descriptions-item>
              <a-descriptions-item label="Signed">{{ c.signed_at ? `${c.signer_name || ''} · ${date(c.signed_at)}` : '—' }}</a-descriptions-item>
            </a-descriptions>
            <div v-if="c.description" class="sc-desc" v-html="c.description"></div>
          </a-card>

          <a-card size="small" title="Products / services billed" style="margin-bottom: 16px">
            <a-table :data-source="c.items" :columns="lineColumns" :pagination="false" size="small" row-key="id">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'price'">{{ money(record.price) }}</template>
                <template v-else-if="column.key === 'tax'">{{ record.tax_percent ? record.tax_percent + '%' : '—' }}</template>
                <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
              </template>
            </a-table>
          </a-card>

          <a-card size="small" :title="`Invoices generated (${c.invoices.length})`">
            <a-table :data-source="c.invoices" :columns="invoiceColumns" :pagination="{ pageSize: 10 }" size="small" row-key="id">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'ref'">
                  <router-link v-if="record.sale_id" :to="`/sales/${record.sale_id}`">{{ record.ref }}</router-link>
                </template>
                <template v-else-if="column.key === 'date'">{{ record.date ? date(record.date) : '—' }}</template>
                <template v-else-if="column.key === 'period'">{{ record.period_start ? `${date(record.period_start)} – ${date(record.period_end)}` : 'One-off' }}</template>
                <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
                <template v-else-if="column.key === 'payment'">
                  <a-tag :color="record.payment_status === 'paid' ? 'green' : record.payment_status === 'partial' ? 'orange' : 'red'">{{ record.payment_status }}</a-tag>
                </template>
                <template v-else-if="column.key === 'how'">{{ record.automatic ? 'Automatic' : 'Manual' }}</template>
              </template>
            </a-table>
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card size="small" title="Recurring invoices">
            <template v-if="c.billing_enabled">
              <div class="sc-big"><SyncOutlined /> {{ billingLabel(c) }}</div>
              <a-descriptions :column="1" size="small" style="margin-top: 8px">
                <a-descriptions-item label="Next invoice">
                  <a-tag v-if="c.next_invoice_date && c.next_invoice_date <= today" color="orange">{{ date(c.next_invoice_date) }} · due</a-tag>
                  <template v-else>{{ c.next_invoice_date ? date(c.next_invoice_date) : '—' }}</template>
                </a-descriptions-item>
                <a-descriptions-item label="Last invoice">{{ c.last_invoice_date ? date(c.last_invoice_date) : '—' }}</a-descriptions-item>
                <a-descriptions-item label="Amount">{{ money(c.value) }}</a-descriptions-item>
              </a-descriptions>
              <div v-if="c.status !== 'active'" style="color: #f97316; font-size: 12px">Paused: the contract is not Active.</div>
            </template>
            <div v-else style="opacity: 0.6">One-off: no automatic invoices. Use "Generate invoice" when needed.</div>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
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
  { title: 'Description', dataIndex: 'name', key: 'name' },
  { title: 'Qty', dataIndex: 'quantity', key: 'quantity', width: 80 },
  { title: 'Price', key: 'price', width: 110, align: 'right' },
  { title: 'Tax', key: 'tax', width: 80 },
  { title: 'Total', key: 'total', width: 110, align: 'right' },
];
const invoiceColumns = [
  { title: 'Invoice', key: 'ref' },
  { title: 'Date', key: 'date' },
  { title: 'Period', key: 'period' },
  { title: 'Total', key: 'total', align: 'right' },
  { title: 'Payment', key: 'payment' },
  { title: 'How', key: 'how' },
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
    title: 'Generate invoice?',
    content: `Creates an unpaid sale of ${money(c.value.value)} for ${c.value.client?.name}${c.value.billing_enabled ? ' and moves the next invoice date one period on' : ''}.`,
    okText: 'Generate',
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
