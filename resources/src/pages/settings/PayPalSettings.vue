<template>
  <div class="page">
    <PageHeader
      :title="$t('PayPal_Settings')"
      subtitle="Customers pay their invoices with PayPal (the &quot;Pay now&quot; link). The money goes to this company's own PayPal account and the payment is recorded on the invoice by itself."
      :breadcrumb="[$t('Settings'), $t('PayPal_Settings')]"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 64px 0"><a-spin size="large" /></div>

    <template v-else>
      <a-card size="small" title="PayPal account" style="margin-bottom: 16px">
        <div class="pp-row">
          <div>
            <div class="pp-label">Accept PayPal payments</div>
            <div class="pp-help">On: invoices with a balance get a "Pay now" link ({pay_link} in the invoice email / SMS templates, and "PayPal pay link" in the sale's menu).</div>
          </div>
          <a-switch v-model:checked="form.enabled" />
        </div>
        <div class="pp-row">
          <div>
            <div class="pp-label">Mode</div>
            <div class="pp-help">Sandbox = PayPal's test site (no real money) -- use it first with sandbox keys. Live = real payments.</div>
          </div>
          <a-radio-group v-model:value="form.sandbox" button-style="solid">
            <a-radio-button :value="true">Sandbox (test)</a-radio-button>
            <a-radio-button :value="false">Live</a-radio-button>
          </a-radio-group>
        </div>

        <a-form layout="vertical" style="margin-top: 14px; max-width: 640px">
          <a-form-item label="Client ID" extra="developer.paypal.com -> Apps & Credentials -> your app (Sandbox or Live, same as the mode).">
            <a-input v-model:value="form.client_id" autocomplete="off" />
          </a-form-item>
          <a-form-item label="Secret" :extra="data.has_secret ? 'Saved (hidden). Leave it blank to keep it, or type a new one to replace it.' : 'Not set yet.'">
            <a-input-password v-model:value="form.client_secret" autocomplete="new-password" :placeholder="data.has_secret ? '••••••••  (saved)' : ''" />
          </a-form-item>
          <a-form-item label="Deposit account" extra="The Murano account the PayPal payments are added to (e.g. a PayPal account in Accounting).">
            <a-select v-model:value="form.account_id" allow-clear show-search option-filter-prop="label" :options="accountOptions" placeholder="No account" />
          </a-form-item>
          <a-form-item label="Webhook ID (optional)">
            <a-input v-model:value="form.webhook_id" autocomplete="off" />
            <div class="pp-help" style="margin-top: 4px">
              Records the payment even if the customer closes the page before coming back. In your PayPal app add a webhook with this URL and the event "Payment capture completed", then paste its ID here:
              <div class="pp-url"><code>{{ data.webhook_url }}</code> <a-button type="link" size="small" @click="copy(data.webhook_url)">Copy</a-button></div>
            </div>
          </a-form-item>
        </a-form>

        <a-space wrap>
          <a-button type="primary" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
          <a-button :loading="testing" @click="test">Test connection</a-button>
          <span class="pp-muted">Currency: {{ data.currency }}</span>
        </a-space>
      </a-card>

      <a-card size="small" title="PayPal payments">
        <a-table :data-source="payments" :columns="columns" row-key="id" size="small" :pagination="{ pageSize: 20 }" :scroll="{ x: 760 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'sale'">
              <PermLink :to="`/sales/${record.sale_id}`" permission="Sales_view">{{ record.Ref || ('#' + record.sale_id) }}</PermLink>
              <div class="pp-muted">{{ record.client_name }}</div>
            </template>
            <template v-else-if="column.key === 'amount'">{{ record.currency }} {{ Number(record.amount).toFixed(2) }}</template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="statusColor[record.status] || 'default'">{{ statusLabel[record.status] || record.status }}</a-tag>
            </template>
            <template v-else-if="column.key === 'payer'">
              {{ record.payer_name || '-' }}
              <div class="pp-muted">{{ record.payer_email }}</div>
            </template>
            <template v-else-if="column.key === 'date'">{{ dayjs(record.created_at).format('YYYY-MM-DD HH:mm') }}</template>
          </template>
          <template #emptyText>No PayPal payments yet.</template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Settings -> PayPal (PayPal module, 2026-10-19), permission paypal_manage, for
 * the acting company. API: paypal/settings, paypal/payments
 * (PayPalSettingsController). The customer's page: /pay/{sale} (PayPalInvoiceController).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const data = ref({ accounts: [], has_secret: false, webhook_url: '', currency: 'USD' });
const form = ref({ enabled: false, sandbox: true, client_id: '', client_secret: '', webhook_id: '', account_id: null });
const payments = ref([]);

const accountOptions = computed(() => sortByLabel((data.value.accounts || []).map(a => ({
  value: a.id, label: a.account_num ? `${a.account_name} (${a.account_num})` : a.account_name,
}))));

const statusLabel = { created: 'Started', captured: 'Paid', failed: 'Failed', cancelled: 'Cancelled', refunded: 'Refunded' };
const statusColor = { created: 'blue', captured: 'green', failed: 'red', cancelled: 'default', refunded: 'orange' };
const columns = [
  { title: 'Date', key: 'date', width: 140 },
  { title: 'Invoice', key: 'sale' },
  { title: 'Amount', key: 'amount', width: 120 },
  { title: 'Payer', key: 'payer' },
  { title: 'PayPal capture', dataIndex: 'capture_id', key: 'capture' },
  { title: 'Status', key: 'status', width: 100 },
];

function apply(d) {
  data.value = d;
  form.value = {
    enabled: !!d.enabled, sandbox: !!d.sandbox, client_id: d.client_id || '', client_secret: '',
    webhook_id: d.webhook_id || '', account_id: d.account_id || null,
  };
}

async function load() {
  loading.value = true;
  try {
    apply(await http.get('paypal/settings'));
    payments.value = (await http.get('paypal/payments')).payments || [];
  } catch (e) {
    message.error(e?.data?.message || 'Could not load PayPal settings.');
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const res = await http.put('paypal/settings', form.value);
    if (res?.warning) message.warning(res.warning);
    else message.success('PayPal settings saved.');
    apply(await http.get('paypal/settings'));
  } catch (e) {
    message.error(e?.data?.message || 'Could not save.');
  } finally {
    saving.value = false;
  }
}

async function test() {
  testing.value = true;
  try {
    const res = await http.post('paypal/settings/test');
    if (res?.ok) message.success(`Connected to PayPal (${res.mode}).`);
    else message.error(res?.message || 'PayPal refused the credentials.');
  } catch (e) {
    message.error(e?.data?.message || 'Could not test.');
  } finally {
    testing.value = false;
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    message.success('Copied.');
  } catch {
    message.info(text);
  }
}

onMounted(load);
</script>

<style scoped>
.pp-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 8px 0; border-bottom: 1px solid rgba(0, 0, 0, 0.05); flex-wrap: wrap; }
.pp-label { font-weight: 600; }
.pp-help { font-size: 12.5px; color: rgba(0, 0, 0, 0.55); max-width: 720px; }
.pp-muted { font-size: 12px; color: rgba(0, 0, 0, 0.45); }
.pp-url { margin-top: 4px; word-break: break-all; }
:global(.dark) .pp-help, :global(.dark) .pp-muted { color: rgba(255, 255, 255, 0.55); }
</style>
