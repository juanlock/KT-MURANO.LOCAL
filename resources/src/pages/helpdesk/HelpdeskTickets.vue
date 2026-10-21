<template>
  <div class="page">
    <PageHeader :title="$t('Helpdesk_Tickets')" :breadcrumb="[$t('Helpdesk'), $t('Helpdesk_Tickets')]">
      <template #extra>
        <a-button type="primary" @click="newOpen = true"><template #icon><PlusOutlined /></template>{{ $t('Helpdesk_New_Ticket') }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="hd-filters">
        <a-input-search v-model:value="f.q" placeholder="Number, subject, name, email…" allow-clear style="width: 240px" @search="reload" />
        <a-select v-model:value="f.status" mode="multiple" placeholder="Status" style="min-width: 170px" :options="options(STATUS)" allow-clear @change="reload" />
        <a-select v-model:value="f.priority" placeholder="Priority" style="width: 130px" :options="options(PRIORITY)" allow-clear @change="reload" />
        <a-select v-model:value="f.requester_type" placeholder="Requester" style="width: 160px" :options="sortByLabel(options(REQUESTER))" allow-clear @change="reload" />
        <a-select v-model:value="f.assigned" placeholder="Assigned" style="width: 170px" :options="assignedOptions" allow-clear @change="reload" />
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle"
        :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: true }"
        :custom-row="r => ({ onClick: () => $router.push(`/helpdesk/tickets/${r.id}`), style: 'cursor:pointer' })"
        :locale="{ emptyText: 'No tickets' }" @change="onChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'number'"><span class="mono">{{ record.number }}</span></template>
          <template v-else-if="column.key === 'subject'">
            <div class="subj">{{ record.subject }}</div>
            <div class="sub">{{ record.requester_name || record.requester_email }} · {{ REQUESTER[record.requester_type] }} · {{ SOURCE[record.source] }}</div>
          </template>
          <template v-else-if="column.key === 'status'"><a-tag :color="STATUS[record.status]?.color">{{ STATUS[record.status]?.label }}</a-tag></template>
          <template v-else-if="column.key === 'priority'"><a-tag :color="PRIORITY[record.priority]?.color">{{ PRIORITY[record.priority]?.label }}</a-tag></template>
          <template v-else-if="column.key === 'assignee'">{{ record.assignee_name || '—' }}</template>
          <template v-else-if="column.key === 'last_message_at'">{{ (record.last_message_at || '').replace('T', ' ').substring(0, 16) }}</template>
        </template>
      </a-table>
    </a-card>

    <!-- agent opens a ticket for someone -->
    <a-modal v-model:open="newOpen" :title="$t('Helpdesk_New_Ticket')" :confirm-loading="creating" ok-text="Create" @ok="create" width="640px">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item label="Requester name"><a-input v-model:value="nf.requester_name" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item label="Requester email" extra="Replies are emailed to this address"><a-input v-model:value="nf.requester_email" type="email" /></a-form-item></a-col>
        </a-row>
        <a-form-item label="Subject" required><a-input v-model:value="nf.subject" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item label="Category"><a-select v-model:value="nf.category" :options="categoryOptions" allow-clear /></a-form-item></a-col>
          <a-col :span="12"><a-form-item label="Priority"><a-select v-model:value="nf.priority" :options="options(PRIORITY)" /></a-form-item></a-col>
        </a-row>
        <a-form-item label="Message" required><a-textarea v-model:value="nf.body" :auto-size="{ minRows: 5, maxRows: 14 }" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** Helpdesk -> Tickets: GET helpdesk/tickets (filters, paging); POST helpdesk/tickets. */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { STATUS, PRIORITY, REQUESTER, SOURCE, options } from './helpdeskLabels';
import { useSystemContext } from '../../lib/systemContext';

const route = useRoute();
const router = useRouter();
const loading = ref(true);
const rows = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(20);
const agents = ref([]);
const categories = ref([]);
const q0 = route.query;
const f = ref({
  q: q0.q || '',
  status: q0.status ? [].concat(q0.status) : (q0.assigned || q0.priority ? ['open', 'pending'] : ['open', 'pending']),
  priority: q0.priority || undefined,
  requester_type: q0.requester_type || undefined,
  assigned: q0.assigned || undefined,
});

const { isSystem } = useSystemContext();
const columns = computed(() => [
  { title: 'Ticket', key: 'number', width: 110 },
  { title: 'Subject', key: 'subject' },
  { title: 'Status', key: 'status', width: 180 },
  { title: 'Priority', key: 'priority', width: 100 },
  { title: 'Assigned', key: 'assignee', width: 160 },
  { title: 'Last activity', key: 'last_message_at', width: 150 },
  // superadmin/AdminSystem: every company (2026-10-21) -- whose is each row.
  ...(isSystem.value ? [{ title: 'Company', key: 'company_name', dataIndex: 'company_name', exportValue: r => r.company_name || '' }] : []),
]);
const assignedOptions = computed(() => [
  { value: 'me', label: 'Assigned to me' },
  { value: 'none', label: 'Unassigned' },
  ...sortByLabel(agents.value.map(a => ({ value: String(a.id), label: a.name }))),
]);
const categoryOptions = computed(() => sortByLabel(categories.value.map(c => ({ value: c, label: c }))));

async function load() {
  loading.value = true;
  try {
    const r = await http.get('helpdesk/tickets', { ...f.value, page: page.value, per_page: perPage.value });
    rows.value = r.data || [];
    total.value = r.meta?.total || 0;
    agents.value = r.agents || [];
    categories.value = r.categories || [];
  } finally { loading.value = false; }
}
function reload() { page.value = 1; load(); }
function onChange(p) { page.value = p.current; perPage.value = p.pageSize; load(); }

const newOpen = ref(!!q0.new);
const creating = ref(false);
const nf = ref({ requester_name: '', requester_email: '', subject: '', category: undefined, priority: 'normal', body: '' });
async function create() {
  if (!nf.value.subject.trim() || !nf.value.body.trim()) { message.warning('Subject and message are required.'); return; }
  creating.value = true;
  try {
    const r = await http.post('helpdesk/tickets', nf.value);
    message.success(`Ticket ${r.number} created.`);
    newOpen.value = false;
    router.push(`/helpdesk/tickets/${r.id}`);
  } catch (e) {
    message.error((e?.data?.errors && Object.values(e.data.errors).flat().join(' ')) || e?.data?.message || 'Could not create the ticket.');
  } finally { creating.value = false; }
}

onMounted(load);
</script>

<style scoped>
.hd-filters { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px; border-bottom: 1px solid rgba(5, 5, 5, 0.06); }
.mono { font-family: ui-monospace, Menlo, monospace; font-size: 12px; }
.subj { font-weight: 500; }
.sub { font-size: 12px; opacity: 0.6; }
</style>
