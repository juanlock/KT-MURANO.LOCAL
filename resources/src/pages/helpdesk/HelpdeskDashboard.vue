<template>
  <div class="page">
    <PageHeader :title="$t('Helpdesk')" :breadcrumb="[$t('Helpdesk'), $t('Helpdesk_Dashboard')]">
      <template #extra>
        <a-space>
          <a-button @click="$router.push('/helpdesk/tickets')">{{ $t('Helpdesk_Tickets') }}</a-button>
          <a-button type="primary" @click="$router.push('/helpdesk/tickets?new=1')"><template #icon><PlusOutlined /></template>{{ $t('Helpdesk_New_Ticket') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert
        v-if="d && d.mailbox && d.mailbox.last_error" type="warning" show-icon style="margin-bottom: 12px"
        :message="'Mailbox: ' + d.mailbox.last_error"
      />
      <a-alert
        v-else-if="d && d.mailbox && !d.mailbox.enabled" type="info" show-icon style="margin-bottom: 12px"
        message="Email import is off. Set up the helpdesk mailbox in Helpdesk settings to turn incoming emails into tickets automatically."
      />
      <div class="hd-cards" v-if="d">
        <div class="hd-card" @click="go({ status: 'open' })"><div class="n">{{ d.status.open || 0 }}</div><div>Open</div></div>
        <div class="hd-card" @click="go({ status: 'pending' })"><div class="n">{{ d.status.pending || 0 }}</div><div>Waiting on requester</div></div>
        <div class="hd-card" @click="go({ assigned: 'me' })"><div class="n">{{ d.mine_open }}</div><div>Assigned to me</div></div>
        <div class="hd-card" @click="go({ assigned: 'none' })"><div class="n">{{ d.unassigned_open }}</div><div>Unassigned</div></div>
        <div class="hd-card urgent" @click="go({ priority: 'urgent' })"><div class="n">{{ d.urgent_open }}</div><div>Urgent</div></div>
        <div class="hd-card"><div class="n">{{ d.created_7d }} / {{ d.resolved_7d }}</div><div>New / resolved (7 days)</div></div>
        <div class="hd-card"><div class="n">{{ d.avg_first_response_min != null ? fmtMin(d.avg_first_response_min) : '—' }}</div><div>Avg. first response (30 d)</div></div>
      </div>

      <a-row :gutter="12" v-if="d">
        <a-col :xs="24" :lg="14">
          <a-card size="small" title="Latest open tickets">
            <a-empty v-if="!d.recent.length" description="No open tickets" />
            <div v-for="t in d.recent" :key="t.id" class="hd-row" @click="$router.push(`/helpdesk/tickets/${t.id}`)">
              <span class="num">{{ t.number }}</span>
              <span class="subj">{{ t.subject }}</span>
              <a-tag :color="PRIORITY[t.priority]?.color">{{ PRIORITY[t.priority]?.label }}</a-tag>
              <a-tag :color="STATUS[t.status]?.color">{{ STATUS[t.status]?.label }}</a-tag>
              <span class="who">{{ t.requester_name || t.requester_email }}</span>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="10">
          <a-card size="small" title="Where tickets come from" style="margin-bottom: 12px">
            <div v-for="(n, k) in d.source" :key="k" class="hd-bar"><span>{{ SOURCE[k] || k }}</span><a-progress :percent="pct(n, d.source)" :format="() => n" size="small" /></div>
            <a-empty v-if="!Object.keys(d.source).length" />
          </a-card>
          <a-card size="small" title="Who opens them">
            <div v-for="(n, k) in d.requester" :key="k" class="hd-bar"><span>{{ REQUESTER[k] || k }}</span><a-progress :percent="pct(n, d.requester)" :format="() => n" size="small" /></div>
            <a-empty v-if="!Object.keys(d.requester).length" />
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/** Helpdesk -> Dashboard: GET helpdesk/dashboard (per company). */
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { STATUS, PRIORITY, REQUESTER, SOURCE } from './helpdeskLabels';

const router = useRouter();
const loading = ref(true);
const d = ref(null);
const go = q => router.push({ path: '/helpdesk/tickets', query: q });
const pct = (n, map) => { const tot = Object.values(map).reduce((a, b) => a + Number(b), 0); return tot ? Math.round((n * 100) / tot) : 0; };
const fmtMin = m => (m < 60 ? `${m} min` : m < 1440 ? `${Math.round(m / 60)} h` : `${Math.round(m / 1440)} d`);

onMounted(async () => { try { d.value = await http.get('helpdesk/dashboard'); } finally { loading.value = false; } });
</script>

<style scoped>
.hd-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-bottom: 12px; }
.hd-card { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 12px; cursor: pointer; background: var(--ant-color-bg-container, #fff); }
.hd-card:hover { border-color: #1677ff; }
.hd-card .n { font-size: 22px; font-weight: 700; }
.hd-card.urgent .n { color: #cf1322; }
.hd-row { display: flex; gap: 8px; align-items: center; padding: 8px 4px; border-bottom: 1px solid rgba(5, 5, 5, 0.05); cursor: pointer; }
.hd-row:hover { background: rgba(0, 0, 0, 0.02); }
.hd-row .num { font-family: ui-monospace, Menlo, monospace; font-size: 12px; opacity: .7; flex: none; }
.hd-row .subj { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
.hd-row .who { font-size: 12px; opacity: .65; flex: none; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hd-bar { display: grid; grid-template-columns: 130px 1fr; gap: 8px; align-items: center; }
</style>
