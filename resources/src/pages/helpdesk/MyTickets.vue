<template>
  <div class="page">
    <PageHeader :title="$t('Helpdesk_My_Tickets')" :breadcrumb="[$t('Helpdesk'), $t('Helpdesk_My_Tickets')]">
      <template #extra>
        <a-button type="primary" @click="openNew"><template #icon><PlusOutlined /></template>{{ $t('Helpdesk_New_Ticket') }}</a-button>
      </template>
    </PageHeader>

    <a-row :gutter="12">
      <a-col :xs="24" :lg="9">
        <a-card size="small" :body-style="{ padding: '4px' }" :loading="loading">
          <a-empty v-if="!rows.length" description="You have no tickets yet." />
          <div v-for="r in rows" :key="r.id" class="mt-row" :class="{ on: current && current.ticket.id === r.id }" @click="open(r.id)">
            <div class="mt-top"><span class="mono">{{ r.number }}</span><a-tag :color="STATUS[r.status]?.color">{{ STATUS[r.status]?.label }}</a-tag></div>
            <div class="mt-subj">{{ r.subject }}</div>
            <div class="mt-date">{{ fmt(r.last_message_at) }}</div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="15">
        <a-card v-if="current" size="small" :title="`${current.ticket.number} · ${current.ticket.subject}`">
          <TicketThread
            :ticket-id="current.ticket.id" :messages="current.messages" base="helpdesk/my"
            :can-reply="current.ticket.status !== 'closed'" closed-note="This ticket is closed. Open a new one if you need more help."
            @updated="r => { current = r; load(false); }"
          />
        </a-card>
        <a-card v-else size="small"><a-empty description="Pick a ticket, or open a new one." /></a-card>
      </a-col>
    </a-row>

    <a-modal v-model:open="newOpen" :title="$t('Helpdesk_New_Ticket')" :confirm-loading="creating" ok-text="Send" width="620px" @ok="create">
      <a-form layout="vertical">
        <a-form-item label="Subject" required><a-input v-model:value="nf.subject" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item label="Category"><a-select v-model:value="nf.category" :options="categoryOptions" allow-clear /></a-form-item></a-col>
          <a-col :span="12"><a-form-item label="Priority"><a-select v-model:value="nf.priority" :options="options(PRIORITY)" /></a-form-item></a-col>
        </a-row>
        <a-form-item label="Describe what you need" required><a-textarea v-model:value="nf.body" :auto-size="{ minRows: 5, maxRows: 14 }" /></a-form-item>
        <a-upload :file-list="nfFiles" :before-upload="f => { nfFiles = [...nfFiles, f]; return false; }" multiple @remove="f => nfFiles = nfFiles.filter(x => x.uid !== f.uid)">
          <a-button size="small">Attach files</a-button>
        </a-upload>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * "My tickets" -- any staff user of the company (employees too) asks the
 * company's helpdesk for help: GET/POST helpdesk/my, GET helpdesk/my/{id},
 * replies through TicketThread (base helpdesk/my).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import TicketThread from './TicketThread.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { sortByLabel } from '../../lib/filterOptions';
import { STATUS, PRIORITY, options } from './helpdeskLabels';

const loading = ref(true);
const rows = ref([]);
const categories = ref([]);
const current = ref(null);
const fmt = d => (d ? String(d).replace('T', ' ').substring(0, 16) : '');
const categoryOptions = computed(() => sortByLabel(categories.value.map(c => ({ value: c, label: c }))));

async function load(spin = true) {
  if (spin) loading.value = true;
  try { const r = await http.get('helpdesk/my'); rows.value = r.data || []; categories.value = r.categories || []; } finally { loading.value = false; }
}
async function open(id) {
  try { current.value = await http.get(`helpdesk/my/${id}`); } catch (e) { message.error('Could not open the ticket.'); }
}

const newOpen = ref(false);
const creating = ref(false);
const nf = ref({});
const nfFiles = ref([]);
function openNew() { nf.value = { subject: '', category: undefined, priority: 'normal', body: '' }; nfFiles.value = []; newOpen.value = true; }
async function create() {
  if (!nf.value.subject?.trim() || !nf.value.body?.trim()) { message.warning('Subject and description are required.'); return; }
  creating.value = true;
  try {
    const fd = new FormData();
    Object.entries(nf.value).forEach(([k, v]) => { if (v) fd.append(k, v); });
    nfFiles.value.forEach(f => fd.append('files[]', f));
    const { status, data } = await uploadForm('helpdesk/my', fd);
    if (status >= 200 && status < 300) {
      message.success(`Ticket ${data.number} sent. The helpdesk will answer here.`);
      newOpen.value = false;
      await load(false);
      open(data.id);
    } else {
      message.error((data?.errors && Object.values(data.errors).flat().join(' ')) || data?.message || 'Could not send.');
    }
  } finally { creating.value = false; }
}
onMounted(load);
</script>

<style scoped>
.mt-row { padding: 8px 10px; border-radius: 6px; cursor: pointer; }
.mt-row:hover { background: rgba(0, 0, 0, 0.03); }
.mt-row.on { background: rgba(22, 119, 255, 0.08); }
.mt-top { display: flex; justify-content: space-between; align-items: center; }
.mono { font-family: ui-monospace, Menlo, monospace; font-size: 12px; opacity: .7; }
.mt-subj { font-weight: 500; }
.mt-date { font-size: 12px; opacity: .6; }
</style>
