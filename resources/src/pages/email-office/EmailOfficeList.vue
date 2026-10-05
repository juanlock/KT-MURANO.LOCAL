<template>
  <div class="page">
    <PageHeader :title="incoming ? $t('Email_Received') : $t('Email_Sent')" :breadcrumb="[$t('Email_Office'), incoming ? $t('Email_Received') : $t('Email_Sent')]">
      <template #actions>
        <a-space>
          <a-button v-if="!incoming" type="primary" @click="composeOpen = true">
            <template #icon><EditOutlined /></template>
            New email
          </a-button>
          <a-button v-if="auth.can('email_office_manage')" @click="$router.push('/email-office/settings')">
            <template #icon><SettingOutlined /></template>
            Settings
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Cards: click one to show only those emails. -->
    <a-row :gutter="[12, 12]" style="margin-bottom: 12px">
      <a-col v-for="c in cards" :key="c.key" :xs="12" :md="6" :lg="4">
        <a-card size="small" hoverable :class="['eo-card', { 'eo-card--on': c.active }]" @click="c.click">
          <div class="eo-card__label">{{ c.label }}</div>
          <div class="eo-card__value" :style="{ color: c.color }">{{ c.value }}</div>
          <div class="eo-card__hint">{{ c.hint }}</div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 12px">
      <a-row :gutter="[12, 8]">
        <a-col :span="24">
          <PresetRangeFilter v-model:value="range" @change="crud.reload()" />
        </a-col>
        <!-- From (received) / To (sent): one address = that conversation only. -->
        <a-col :xs="24" :md="6">
          <a-select
            v-model:value="filters.address" allow-clear show-search :filter-option="false" style="width: 100%"
            :placeholder="incoming ? 'From (sender)' : 'To (recipient)'" :options="addressOptions" :loading="addressLoading"
            @search="searchAddresses" @focus="searchAddresses('')" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="6">
          <a-select v-model:value="filters.category" allow-clear placeholder="Type" style="width: 100%" :options="CATEGORY_OPTIONS" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="6">
          <a-select v-model:value="filters.party_type" allow-clear placeholder="From / to" style="width: 100%" :options="PARTY_OPTIONS" @change="crud.reload()" />
        </a-col>
        <a-col v-if="incoming" :xs="12" :md="6">
          <a-select v-model:value="filters.linked" allow-clear placeholder="Linked?" style="width: 100%" :options="[{ value: 0, label: 'Not linked yet' }, { value: 1, label: 'Linked' }]" @change="crud.reload()" />
        </a-col>
        <a-col v-else :xs="12" :md="6">
          <a-select v-model:value="filters.source" allow-clear placeholder="Sent by" style="width: 100%" :options="SOURCE_OPTIONS" @change="crud.reload()" />
        </a-col>
      </a-row>
    </a-card>

    <a-alert
      v-if="!crud.loading.value && stats.total === 0 && !anyFilter" type="info" show-icon style="margin-bottom: 12px"
      :message="incoming ? 'No emails yet.' : 'No sent emails yet.'"
      :description="incoming ? 'Connect the company mailbox in Email Office → Settings; new emails are imported automatically.' : 'Every email Murano sends from now on (invoices, quotations, reminders…) appears here, plus the Sent folder of the connected mailbox.'"
    />

    <DataTable :crud="crud" :columns="columns" :table-key="`email-office|${direction}`">
      <!-- Help on the header: what "Recorded in Murano" means. -->
      <template #headerCell="{ column, title }">
        <template v-if="column.key === 'linked'">
          {{ title }}
          <a-tooltip>
            <template #title>
              Is this email already entered in Murano?<br>
              <b>Purchase / Expense + number</b> — yes, the bill was recorded (Create expense or Link).<br>
              <b>Ticket + number</b> — the claim became a helpdesk ticket.<br>
              <b>To record</b> — a bill not entered yet: open the email to record it.<br>
              <b>—</b> — nothing to record.
            </template>
            <QuestionCircleOutlined style="margin-inline-start: 4px; opacity: 0.6; cursor: help" />
          </a-tooltip>
        </template>
        <template v-else>{{ title }}</template>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'sent_at'">
          <span :class="{ 'eo-unread': !record.is_read }">{{ dateTime(record.sent_at) }}</span>
        </template>
        <template v-else-if="column.key === 'who'">
          <a :class="{ 'eo-unread': !record.is_read }" @click="openEmail(record)">{{ incoming ? (record.from_name || record.from_email) : record.to_emails }}</a>
          <a-tooltip :title="`Only emails with ${addressOf(record)}`">
            <FilterOutlined class="eo-addr-filter" @click.stop="filterAddress(addressOf(record))" />
          </a-tooltip>
          <div v-if="incoming && record.from_name" class="eo-muted">{{ record.from_email }}</div>
        </template>
        <template v-else-if="column.key === 'subject'">
          <a :class="{ 'eo-unread': !record.is_read }" @click="openEmail(record)">{{ record.subject }}</a>
          <PaperClipOutlined v-if="record.attachments_count" style="margin-inline-start: 6px; opacity: 0.6" />
          <div class="eo-muted eo-snippet">{{ record.snippet }}</div>
        </template>
        <template v-else-if="column.key === 'category'">
          <a-tag :color="CATEGORY_COLOR[record.category]">{{ CATEGORY_LABEL[record.category] }}</a-tag>
        </template>
        <template v-else-if="column.key === 'party'">
          <template v-if="record.party_name">
            <div>{{ record.party_name }}</div>
            <div class="eo-muted">{{ PARTY_LABEL[record.party_type] }}</div>
          </template>
          <span v-else class="eo-muted">—</span>
        </template>
        <template v-else-if="column.key === 'linked'">
          <a-tooltip v-if="record.linked_type" :title="`This email is already entered in Murano as ${LINKED_LABEL[record.linked_type].toLowerCase()} ${record.linked_label}`">
            <a-tag color="purple">{{ LINKED_LABEL[record.linked_type] }} {{ record.linked_label }}</a-tag>
          </a-tooltip>
          <a-tooltip v-else-if="record.category === 'bill' && incoming" title="A bill not entered yet: open the email → Create expense, or Link it to its purchase / expense">
            <a-tag color="orange">To record</a-tag>
          </a-tooltip>
          <span v-else class="eo-muted">—</span>
        </template>
        <template v-else-if="column.key === 'source'">{{ SOURCE_LABEL[record.source] || record.source }}</template>
      </template>
    </DataTable>

    <EmailViewerDrawer v-model:open="viewerOpen" :email-id="viewerId" @changed="crud.fetchRows()" @filter-address="filterAddress" />

    <!-- New email -->
    <a-modal v-model:open="composeOpen" title="New email" :confirm-loading="sending" ok-text="Send" width="680px" @ok="send">
      <a-form layout="vertical">
        <a-form-item label="Customer (optional)" extra="Pick a customer to fill in their email.">
          <a-select
            v-model:value="compose.client_id" show-search allow-clear :filter-option="false" placeholder="Search a customer…"
            :options="clientOptions" @search="searchClients" @focus="searchClients('')" @change="pickClient"
          />
        </a-form-item>
        <a-form-item label="To" required>
          <a-select v-model:value="compose.to" mode="tags" :token-separators="[',', ';', ' ']" placeholder="name@example.com" />
        </a-form-item>
        <a-form-item label="Cc">
          <a-select v-model:value="compose.cc" mode="tags" :token-separators="[',', ';', ' ']" />
        </a-form-item>
        <a-form-item label="Subject" required><a-input v-model:value="compose.subject" /></a-form-item>
        <a-form-item label="Message" required><a-textarea v-model:value="compose.body" :rows="8" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Email Office -> Email received (direction in) / Email sent (out):
 * GET email-office/emails -> {emails, totalRows, stats}. Cards filter by
 * type (bills to record, claims, reminders...); the drawer shows one email
 * and its actions (EmailViewerDrawer). Sent: "New email" (POST
 * email-office/compose).
 */
import { ref, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { EditOutlined, SettingOutlined, PaperClipOutlined, QuestionCircleOutlined, FilterOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import EmailViewerDrawer from '../../components/EmailViewerDrawer.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const props = defineProps({ direction: { type: String, default: 'in' } });
const incoming = computed(() => props.direction === 'in');
const auth = useAuthStore();
const route = useRoute();
const { dateTime } = useFormat();

const CATEGORY_LABEL = { bill: 'Bill', claim: 'Claim', reminder: 'Reminder', general: 'General' };
const CATEGORY_COLOR = { bill: 'orange', claim: 'red', reminder: 'blue', general: 'default' };
const CATEGORY_OPTIONS = sortByLabel(Object.entries(CATEGORY_LABEL).map(([value, label]) => ({ value, label })));
const PARTY_LABEL = { client: 'Customer', provider: 'Supplier', employee: 'Employee' };
const PARTY_OPTIONS = sortByLabel([...Object.entries(PARTY_LABEL).map(([value, label]) => ({ value, label })), { value: 'none', label: 'Unknown sender' }]);
const LINKED_LABEL = { sale: 'Sale', purchase: 'Purchase', expense: 'Expense', helpdesk_ticket: 'Ticket' };
const SOURCE_LABEL = { system: 'Murano', compose: 'Email Office', mailbox: 'Mailbox' };
const SOURCE_OPTIONS = sortByLabel([
  { value: 'system', label: 'Murano (invoices, reminders…)' }, { value: 'compose', label: 'Email Office (New email)' }, { value: 'mailbox', label: 'Mailbox (webmail / phone)' },
]);

const filters = ref({ from: '', to: '', category: undefined, party_type: undefined, linked: undefined, source: undefined, unread: undefined, address: route.query.address ? String(route.query.address) : undefined });
const range = computed({
  get: () => (filters.value.from ? [filters.value.from, filters.value.to] : null),
  set: r => { filters.value.from = r ? r[0] : ''; filters.value.to = r ? r[1] : ''; },
});
const anyFilter = computed(() => Object.values(filters.value).some(v => v !== '' && v !== undefined && v !== null));

const crud = useCrudTable('email-office/emails', {
  rowsKey: 'emails',
  sortField: 'sent_at',
  sortType: 'desc',
  params: () => ({
    direction: props.direction,
    from: filters.value.from || '', to: filters.value.to || '',
    category: filters.value.category || '', party_type: filters.value.party_type || '',
    linked: filters.value.linked ?? '', source: filters.value.source || '', unread: filters.value.unread ? 1 : '',
    address: filters.value.address || '',
  }),
});
crud.fetchRows();
watch(() => props.direction, () => {
  filters.value = { from: '', to: '', category: undefined, party_type: undefined, linked: undefined, source: undefined, unread: undefined, address: undefined };
  crud.reload();
});

const stats = computed(() => crud.payload.value?.stats || { total: 0 });
function setFilter(patch) {
  const same = Object.entries(patch).every(([k, v]) => filters.value[k] === v);
  Object.keys(patch).forEach(k => { filters.value[k] = same ? undefined : patch[k]; });
  if (!same) Object.assign(filters.value, patch);
  crud.reload();
}
const cards = computed(() => {
  const s = stats.value;
  const f = filters.value;
  const all = { key: 'all', label: incoming.value ? 'Received' : 'Sent', value: s.total ?? 0, hint: 'all emails', color: '#1f1f2c', active: !f.category && f.linked === undefined && !f.unread, click: () => { f.category = undefined; f.linked = undefined; f.unread = undefined; crud.reload(); } };
  if (incoming.value) {
    return [
      all,
      { key: 'unlinked', label: 'Bills to record', value: s.unlinked_bills ?? 0, hint: 'not linked to a purchase / expense', color: '#d46b08', active: f.category === 'bill' && f.linked === 0, click: () => setFilter({ category: 'bill', linked: 0 }) },
      { key: 'bill', label: 'Bills', value: s.bill ?? 0, hint: 'invoices, receipts', color: '#fa8c16', active: f.category === 'bill' && f.linked === undefined, click: () => setFilter({ category: 'bill', linked: undefined }) },
      { key: 'claim', label: 'Claims', value: s.claim ?? 0, hint: 'complaints, refunds', color: '#cf1322', active: f.category === 'claim', click: () => setFilter({ category: 'claim', linked: undefined }) },
      { key: 'general', label: 'General', value: s.general ?? 0, hint: 'everything else', color: '#595959', active: f.category === 'general', click: () => setFilter({ category: 'general', linked: undefined }) },
      { key: 'unread', label: 'Unread', value: s.unread ?? 0, hint: 'not opened here yet', color: '#1677ff', active: f.unread === 1, click: () => setFilter({ unread: 1 }) },
    ];
  }
  return [
    all,
    { key: 'reminder', label: 'Reminders sent', value: s.reminder ?? 0, hint: 'payment / appointment reminders', color: '#1677ff', active: f.category === 'reminder', click: () => setFilter({ category: 'reminder' }) },
    { key: 'bill', label: 'Bills', value: s.bill ?? 0, hint: 'about invoices / bills', color: '#fa8c16', active: f.category === 'bill', click: () => setFilter({ category: 'bill' }) },
    { key: 'claim', label: 'Claims', value: s.claim ?? 0, hint: 'helpdesk / support', color: '#cf1322', active: f.category === 'claim', click: () => setFilter({ category: 'claim' }) },
    { key: 'general', label: 'General', value: s.general ?? 0, hint: 'everything else', color: '#595959', active: f.category === 'general', click: () => setFilter({ category: 'general' }) },
  ];
});

const columns = computed(() => [
  { title: 'Date', dataIndex: 'sent_at', key: 'sent_at', sorter: true, width: 150 },
  { title: incoming.value ? 'From' : 'To', key: 'who', width: 220 },
  { title: 'Subject', dataIndex: 'subject', key: 'subject', sorter: true },
  { title: 'Type', dataIndex: 'category', key: 'category', sorter: true, width: 100 },
  { title: 'Customer / supplier', key: 'party', width: 170 },
  ...(incoming.value ? [{ title: 'Recorded in Murano', key: 'linked', width: 170 }] : [{ title: 'Sent by', key: 'source', width: 120 }]),
]);

// ---- From / To address filter ----
const addressOptions = ref([]);
const addressLoading = ref(false);
let addressTimer = null;
function searchAddresses(q) {
  clearTimeout(addressTimer);
  addressTimer = setTimeout(async () => {
    addressLoading.value = true;
    try {
      const rows = (await http.get('email-office/addresses', { direction: props.direction, search: q || '' })).addresses || [];
      addressOptions.value = rows.map(a => ({ value: a.address, label: a.label }));
      if (filters.value.address && !rows.some(a => a.address === filters.value.address)) {
        addressOptions.value.unshift({ value: filters.value.address, label: filters.value.address });
      }
    } finally {
      addressLoading.value = false;
    }
  }, 250);
}
// The other side of a row: its sender (received) or first recipient (sent).
const addressOf = r => (incoming.value ? r.from_email : String(r.to_emails || '').split(',')[0].trim());
function filterAddress(addr) {
  if (!addr) return;
  filters.value.address = addr;
  if (!addressOptions.value.some(o => o.value === addr)) addressOptions.value = [{ value: addr, label: addr }, ...addressOptions.value];
  crud.reload();
}
if (filters.value.address) addressOptions.value = [{ value: filters.value.address, label: filters.value.address }];

// ---- viewer ----
const viewerOpen = ref(false);
const viewerId = ref(null);
function openEmail(r) {
  viewerId.value = r.id;
  viewerOpen.value = true;
  r.is_read = true;
}
if (route.query.open) openEmail({ id: Number(route.query.open) });

// ---- new email ----
const composeOpen = ref(false);
const sending = ref(false);
const compose = ref({ client_id: undefined, to: [], cc: [], subject: '', body: '' });
const clientOptions = ref([]);
const clientEmails = new Map();
let clientTimer = null;
function searchClients(q) {
  clearTimeout(clientTimer);
  clientTimer = setTimeout(async () => {
    const data = await http.get('clients', { search: q || '', limit: 20, page: 1, SortField: 'name', SortType: 'asc' });
    const rows = (data.clients || []).filter(c => c.email);
    rows.forEach(c => clientEmails.set(c.id, c.email));
    clientOptions.value = sortByLabel(rows.map(c => ({ value: c.id, label: `${c.name} — ${c.email}` })));
  }, 250);
}
function pickClient(id) {
  const e = clientEmails.get(id);
  if (e && !compose.value.to.includes(e)) compose.value.to = [...compose.value.to, e];
}
async function send() {
  const c = compose.value;
  if (!c.to.length || !c.subject.trim() || !c.body.trim()) {
    message.error('To, subject and message are required.');
    return;
  }
  sending.value = true;
  try {
    await http.post('email-office/compose', { to: c.to, cc: c.cc, subject: c.subject, body: c.body });
    message.success('Sent.');
    composeOpen.value = false;
    compose.value = { client_id: undefined, to: [], cc: [], subject: '', body: '' };
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not send.');
  } finally {
    sending.value = false;
  }
}
</script>

<style scoped>
.eo-card {
  cursor: pointer;
  height: 100%;
}
.eo-card--on {
  border-color: #6d28d9;
  box-shadow: 0 0 0 1px #6d28d9 inset;
}
.eo-card__label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
}
.eo-card__value {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.3;
}
.eo-card__hint {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.45);
}
.eo-muted {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}
.eo-snippet {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.eo-addr-filter {
  margin-inline-start: 6px;
  opacity: 0.45;
  cursor: pointer;
}
.eo-addr-filter:hover {
  opacity: 1;
  color: #6d28d9;
}
.eo-unread {
  font-weight: 600;
}
</style>
