<template>
  <div class="page">
    <PageHeader :title="t ? `${t.number} · ${t.subject}` : $t('Helpdesk_Tickets')" :breadcrumb="[$t('Helpdesk'), $t('Helpdesk_Tickets'), t?.number || '']">
      <template #extra>
        <a-space>
          <a-button @click="$router.push('/helpdesk/tickets')">← {{ $t('Helpdesk_Tickets') }}</a-button>
          <a-popconfirm v-if="canManage" title="Delete this ticket and its conversation for good?" ok-type="danger" @confirm="remove">
            <a-button danger>Delete</a-button>
          </a-popconfirm>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-row v-if="t" :gutter="12">
        <a-col :xs="24" :lg="16">
          <TicketThread :ticket-id="t.id" :messages="messages" agent base="helpdesk" @updated="apply" />
        </a-col>
        <a-col :xs="24" :lg="8">
          <a-card size="small" title="Ticket">
            <a-form layout="vertical">
              <a-form-item label="Status" style="margin-bottom: 8px">
                <a-select :value="t.status" :options="options(STATUS)" @change="v => save({ status: v })" />
              </a-form-item>
              <a-form-item label="Priority" style="margin-bottom: 8px">
                <a-select :value="t.priority" :options="options(PRIORITY)" @change="v => save({ priority: v })" />
              </a-form-item>
              <a-form-item label="Assigned to" style="margin-bottom: 8px">
                <a-select :value="t.assigned_to || null" :options="agentOptions" allow-clear placeholder="Unassigned" @change="v => save({ assigned_to: v || null })" />
              </a-form-item>
              <a-form-item label="Category" style="margin-bottom: 8px">
                <a-select :value="t.category || null" :options="categoryOptions" allow-clear @change="v => save({ category: v || null })" />
              </a-form-item>
            </a-form>
          </a-card>
          <a-card size="small" title="Requester" style="margin-top: 12px">
            <div><b>{{ t.requester_name || '—' }}</b></div>
            <div v-if="t.requester_email"><a :href="`mailto:${t.requester_email}`">{{ t.requester_email }}</a></div>
            <div class="muted">{{ REQUESTER[t.requester_type] }} · via {{ SOURCE[t.source] }}</div>
            <div v-if="t.client_id" style="margin-top: 6px"><a @click="$router.push(`/customers/${t.client_id}/details`)">Open customer</a></div>
            <div v-if="publicUrl && ['guest', 'supplier'].includes(t.requester_type)" class="muted" style="margin-top: 6px">
              The requester follows the ticket with a private link sent by email.
            </div>
          </a-card>
          <a-card size="small" title="Timeline" style="margin-top: 12px" class="muted">
            <div>Opened {{ fmt(t.created_at) }}</div>
            <div>First response {{ t.first_response_at ? fmt(t.first_response_at) : '—' }}</div>
            <div>Last activity {{ fmt(t.last_message_at) }}</div>
            <div v-if="t.resolved_at">Resolved {{ fmt(t.resolved_at) }}</div>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/** One ticket for agents: GET/PUT helpdesk/tickets/{id}, reply via TicketThread. */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import TicketThread from './TicketThread.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import { STATUS, PRIORITY, REQUESTER, SOURCE, options } from './helpdeskLabels';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const loading = ref(true);
const t = ref(null);
const messages = ref([]);
const agents = ref([]);
const categories = ref([]);
const publicUrl = ref('');

const canManage = computed(() => auth.can('helpdesk_manage'));
const agentOptions = computed(() => sortByLabel(agents.value.map(a => ({ value: a.id, label: a.name }))));
const categoryOptions = computed(() => sortByLabel(categories.value.map(c => ({ value: c, label: c }))));
const fmt = d => (d ? String(d).replace('T', ' ').substring(0, 16) : '—');

function apply(r) {
  t.value = r.ticket;
  messages.value = r.messages || [];
  agents.value = r.agents || agents.value;
  categories.value = r.categories || categories.value;
  publicUrl.value = r.public_url || publicUrl.value;
}
async function load() {
  loading.value = true;
  try { apply(await http.get(`helpdesk/tickets/${route.params.id}`)); } catch (e) { message.error('Ticket not found.'); } finally { loading.value = false; }
}
async function save(patch) {
  try { apply(await http.put(`helpdesk/tickets/${t.value.id}`, patch)); message.success('Saved.'); } catch (e) { message.error(e?.data?.message || 'Could not save.'); }
}
async function remove() {
  try { await http.delete(`helpdesk/tickets/${t.value.id}`); message.success('Ticket deleted.'); router.push('/helpdesk/tickets'); } catch (e) { message.error('Could not delete.'); }
}
onMounted(load);
</script>

<style scoped>
.muted { opacity: 0.7; font-size: 13px; }
</style>
