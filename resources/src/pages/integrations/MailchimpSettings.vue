<template>
  <div class="page">
    <PageHeader title="Mailchimp" :breadcrumb="['Integrations', 'Mailchimp']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.ready && state.enabled ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.ready ? (state.enabled ? $t('Enabled') : $t('Disabled')) : 'Not configured' }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(226,164,0,0.14)', color: '#e2a400' }">
          <template #icon><MailOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">Mailchimp audience sync</div>
          <div class="muted">Keep a Mailchimp audience filled with your customers, so you can run email campaigns on your real customer base.</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" tab="Connection">
          <a-card size="small" title="Account & audience" style="max-width: 640px">
            <a-form layout="vertical">
              <a-form-item label="API key">
                <a-input-password
                  v-model:value="form.api_key"
                  :placeholder="state.has_api_key ? 'Saved — paste a new key to replace it' : 'e.g. a1b2c3…-us14 (Account → Extras → API keys)'"
                  autocomplete="off"
                />
              </a-form-item>
              <a-form-item label="Audience">
                <a-input-group compact style="display: flex">
                  <a-select
                    v-model:value="form.list_id" style="flex: 1"
                    :options="lists.map(l => ({ value: l.id, label: l.name + (l.member_count != null ? ` (${l.member_count} members)` : '') }))"
                    :placeholder="state.list_name || 'Load audiences to pick one'"
                  />
                  <a-button :disabled="!state.has_api_key && !form.api_key" :loading="loadingLists" @click="loadLists">Load audiences</a-button>
                </a-input-group>
              </a-form-item>
              <a-form-item>
                <a-space direction="vertical" size="small">
                  <a-space align="center">
                    <a-switch v-model:checked="form.double_opt_in" />
                    <span>Double opt-in — new members get a confirmation email before joining <span class="muted">(recommended for GDPR)</span></span>
                  </a-space>
                  <a-space align="center">
                    <a-switch v-model:checked="form.auto_sync" />
                    <span>Auto-sync new customers as they are created</span>
                  </a-space>
                  <a-space align="center">
                    <a-switch v-model:checked="form.enabled" />
                    <span>Enable Mailchimp sync</span>
                  </a-space>
                </a-space>
              </a-form-item>
              <a-space wrap>
                <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                <a-button :disabled="!state.has_api_key" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
              </a-space>
            </a-form>
          </a-card>
        </a-tab-pane>

        <!-- ============================ Sync ============================ -->
        <a-tab-pane key="sync" tab="Sync" :disabled="!state.ready">
          <StatsRow :stats="stats" :items="[['customers_total', 'Total customers'], ['customers_with_email', 'With an email']]" />
          <a-card size="small" title="Push customers to Mailchimp" style="margin-bottom: 16px">
            <p class="muted">
              Upserts every customer that has an email address into the selected audience (name and phone included).
              Existing members keep their subscription status — nobody who unsubscribed is re-subscribed.
              Customers without an email are left out.
            </p>
            <a-alert
              v-if="!form.double_opt_in" type="warning" show-icon style="margin-bottom: 12px; max-width: 720px"
              message="Double opt-in is off"
              description="New members will be added as subscribed without a confirmation email. Only do this for customers who already agreed to receive marketing."
            />
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync">
                <template #icon><UploadOutlined /></template>Push customers
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Logs ============================ -->
        <a-tab-pane key="logs" tab="Logs">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 8px; flex-wrap: wrap">
            <a-select
              v-model:value="logLevel" style="min-width: 160px" :options="[
                { value: '', label: 'All levels' },
                { value: 'info', label: 'Info' },
                { value: 'warning', label: 'Warning' },
                { value: 'error', label: 'Error' },
              ]" @change="loadLogs(1)"
            />
            <a-popconfirm title="Clear all Mailchimp logs?" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
              <a-button danger size="small">Clear logs</a-button>
            </a-popconfirm>
          </div>
          <a-table
            size="small" row-key="id" :data-source="logs.data" :columns="logColumns"
            :pagination="{ current: logs.current_page, pageSize: logs.per_page, total: logs.total, showSizeChanger: false }"
            :scroll="{ x: 900 }" @change="p => loadLogs(p.current)"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'level'">
                <a-tag :color="record.level === 'error' ? 'error' : record.level === 'warning' ? 'warning' : 'default'">{{ record.level }}</a-tag>
              </template>
              <template v-else-if="column.key === 'created_at'">{{ date(record.created_at) }}</template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ============================ Guide ============================ -->
        <a-tab-pane key="guide" tab="Guide">
          <a-card size="small" style="max-width: 860px">
            <ol class="guide">
              <li>In Mailchimp, open <strong>Account → Extras → API keys</strong> and create a key (it ends in <code>-usX</code> — that suffix is your datacenter).</li>
              <li>Paste the key here, save, then click <strong>Load audiences</strong> and pick the audience to fill.</li>
              <li>Leave <strong>double opt-in</strong> on unless your customers have already consented to marketing emails.</li>
              <li>Run <strong>Push customers</strong> for the initial fill; turn on <strong>auto-sync</strong> so new customers join automatically.</li>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              message="Consent matters"
              description="Emailing people who never agreed to marketing can violate GDPR/CAN-SPAM and get your Mailchimp account suspended. Double opt-in shifts the confirmation to Mailchimp — the safe default."
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Mailchimp integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:mailchimp): GET/POST mailchimp/settings,
 * POST mailchimp/test-connection, GET mailchimp/lists, GET mailchimp/stats,
 * POST mailchimp/sync/customers, GET/DELETE mailchimp/logs.
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { MailOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useBatchSync, COUNTER_LABELS } from '../../composables/useBatchSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const loadingLists = ref(false);
const tab = ref('connection');
const state = ref({ configured: false, ready: false, enabled: false });
const form = ref({ enabled: false, api_key: '', list_id: null, double_opt_in: true, auto_sync: false });
const lists = ref([]);
const stats = ref({});
const logs = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const logLevel = ref('');

const sync = useBatchSync();

const logColumns = [
  { title: 'ID', dataIndex: 'id', width: 70 },
  { title: 'Action', dataIndex: 'action', width: 180 },
  { title: 'Level', key: 'level', width: 100 },
  { title: 'Message', dataIndex: 'message', ellipsis: true },
  { title: t('Date'), key: 'created_at', width: 170 },
];

async function load() {
  isLoading.value = true;
  try {
    state.value = await http.get('mailchimp/settings');
    form.value = {
      enabled: !!state.value.enabled,
      api_key: '',
      list_id: state.value.list_id || null,
      double_opt_in: state.value.double_opt_in !== false,
      auto_sync: !!state.value.auto_sync,
    };
    if (state.value.ready) loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function loadStats() {
  try {
    stats.value = await http.get('mailchimp/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const chosen = lists.value.find(l => l.id === form.value.list_id);
    const payload = {
      enabled: !!form.value.enabled,
      list_id: form.value.list_id || null,
      list_name: chosen ? chosen.name : state.value.list_name || null,
      double_opt_in: !!form.value.double_opt_in,
      auto_sync: !!form.value.auto_sync,
    };
    // The key is write-only: send it only when the admin typed a new one.
    if (form.value.api_key) payload.api_key = form.value.api_key.trim();
    await http.post('mailchimp/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  try {
    await http.post('mailchimp/test-connection');
    message.success('Connected to Mailchimp.');
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || 'Connection test failed');
  } finally {
    testing.value = false;
  }
}

async function loadLists() {
  // Persist a freshly pasted key first so the lookup uses it.
  if (form.value.api_key) await save();
  loadingLists.value = true;
  try {
    const res = await http.get('mailchimp/lists');
    lists.value = res.lists || [];
    if (!lists.value.length) message.warning('No audiences found on this account.');
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
  } finally {
    loadingLists.value = false;
  }
}

async function runSync() {
  await sync.run('mailchimp/sync/customers', {}, 'push');
  loadStats();
}

async function loadLogs(page = 1) {
  try {
    const query = 'mailchimp/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('mailchimp/logs');
    loadLogs(1);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

onMounted(() => {
  load();
  loadLogs(1);
});

// ---- small local render components (same pattern as SallaSettings.vue) ----

const tileStyle = {
  border: '1px solid rgba(128,128,128,0.2)', borderRadius: '10px',
  padding: '10px 18px', minWidth: '130px', textAlign: 'center',
};

const StatsRow = defineComponent({
  props: { stats: Object, items: Array },
  setup(p) {
    return () => h(
      'div',
      { style: { display: 'flex', gap: '12px', flexWrap: 'wrap', marginBottom: '16px' } },
      (p.items || []).map(([key, label]) => h('div', { style: tileStyle, key }, [
        h('div', { style: { fontSize: '20px', fontWeight: 600 } }, p.stats?.[key] ?? '—'),
        h('div', { style: { fontSize: '12px', opacity: 0.6 } }, label),
      ]))
    );
  },
});

const SyncProgress = defineComponent({
  props: { sync: Object },
  setup(p) {
    return () => {
      const s = p.sync;
      if (!s || (!s.hasCounters.value && !s.running.value)) return null;
      const pills = Object.entries(s.counters.value).map(([k, v]) =>
        h(ATagC, { key: k, color: k === 'failed' && v ? 'error' : 'default' }, () => `${COUNTER_LABELS[k] || k}: ${v}`));
      const rows = [h('div', { style: { display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' } }, [
        ...(s.running.value ? [h(ASpinC, { size: 'small', style: { marginRight: '6px' } })] : []),
        ...pills,
      ])];
      if (s.batchErrors.value.length) {
        rows.push(h('div', { style: { marginTop: '10px', fontSize: '12px', maxHeight: '160px', overflow: 'auto' } },
          s.batchErrors.value.map((err, i) => h('div', { key: i, style: { opacity: 0.75 } }, JSON.stringify(err)))));
      }
      return h('div', {
        style: { border: '1px dashed rgba(128,128,128,0.35)', borderRadius: '10px', padding: '12px 14px' },
      }, rows);
    };
  },
});
</script>

<style scoped>
.hero {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
}
.hero-title {
  font-weight: 600;
  font-size: 16px;
}
.muted {
  opacity: 0.65;
  font-size: 13px;
}
.guide {
  padding-left: 20px;
  line-height: 2;
  margin: 0 0 12px;
}
</style>
