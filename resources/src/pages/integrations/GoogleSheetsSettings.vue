<template>
  <div class="page">
    <PageHeader title="Google Sheets" :breadcrumb="['Integrations', 'Google Sheets']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.connected ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.connected ? $t('Connected') : 'Not connected' }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(24,128,56,0.14)', color: '#188038' }">
          <template #icon><TableOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">Google Sheets exports</div>
          <div class="muted">Export your sales, products and customers into a live Google spreadsheet — one tab each, refreshed on demand.</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" tab="Connection">
          <a-row :gutter="[16, 16]">
            <a-col :xs="24" :lg="12">
              <a-card size="small" title="Google OAuth credentials" style="height: 100%">
                <a-form layout="vertical">
                  <a-form-item label="Client ID">
                    <a-input v-model:value="form.client_id" placeholder="…apps.googleusercontent.com" />
                  </a-form-item>
                  <a-form-item label="Client Secret">
                    <a-input-password
                      v-model:value="form.client_secret"
                      :placeholder="state.has_client_secret ? 'Saved — paste a new value to replace it' : 'From your Google Cloud OAuth client'"
                      autocomplete="off"
                    />
                  </a-form-item>
                  <a-space direction="vertical" size="small" style="margin-bottom: 16px">
                    <a-space align="center">
                      <a-switch v-model:checked="form.auto_export" />
                      <span>Auto-export nightly <span class="muted">(all tabs refreshed around 02:30 server time)</span></span>
                    </a-space>
                    <a-space align="center">
                      <a-switch v-model:checked="form.enabled" />
                      <span>Enable Google Sheets exports</span>
                    </a-space>
                  </a-space>
                  <div>
                    <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                  </div>
                </a-form>
              </a-card>
            </a-col>

            <a-col :xs="24" :lg="12">
              <a-card size="small" title="Account & spreadsheet" style="margin-bottom: 16px">
                <a-space wrap style="margin-bottom: 16px">
                  <a-button type="primary" :disabled="!state.client_id || !state.has_client_secret" @click="connect">
                    <template #icon><LinkOutlined /></template>
                    {{ state.connected ? 'Reconnect Google account' : 'Connect Google account' }}
                  </a-button>
                  <a-button :disabled="!state.connected" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
                  <a-popconfirm
                    v-if="state.connected"
                    title="Disconnect the Google account? Exports stop until you reconnect."
                    :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="disconnect"
                  >
                    <a-button danger>Disconnect</a-button>
                  </a-popconfirm>
                </a-space>

                <a-form layout="vertical">
                  <a-form-item label="Target spreadsheet (ID or full URL)">
                    <a-input-group compact style="display: flex">
                      <a-input v-model:value="form.spreadsheet" placeholder="Paste a spreadsheet URL, or create one →" style="flex: 1" />
                      <a-button :disabled="!state.connected" :loading="creating" @click="createSpreadsheet">Create new</a-button>
                    </a-input-group>
                    <div v-if="state.spreadsheet_url" style="margin-top: 6px">
                      <a :href="state.spreadsheet_url" target="_blank" rel="noopener">Open the spreadsheet ↗</a>
                      <span class="muted" style="margin-left: 8px" v-if="state.last_sync_at">Last export {{ date(state.last_sync_at) }}</span>
                    </div>
                  </a-form-item>
                </a-form>
              </a-card>

              <a-card size="small" title="Redirect URI for the Google OAuth client">
                <a-input-group compact style="display: flex">
                  <a-input :value="state.callback_url" readonly style="flex: 1" />
                  <a-button @click="copy(state.callback_url)"><CopyOutlined /></a-button>
                </a-input-group>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ============================ Export ============================ -->
        <a-tab-pane key="export" tab="Export" :disabled="!state.connected || !state.spreadsheet_id">
          <StatsRow :stats="stats" :items="[['sales_total', 'Sales'], ['products_total', 'Products'], ['customers_total', 'Customers']]" />
          <p class="muted" style="max-width: 760px">
            Each export rewrites its tab from scratch (header + all rows), so the sheet is always a complete, current snapshot.
            Large datasets are sent in batches — leave the page open until the counter stops.
          </p>
          <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
            <a-col v-for="job in exportJobs" :key="job.key" :xs="24" :md="8">
              <a-card size="small" :title="job.title" style="height: 100%">
                <p class="muted" style="min-height: 40px">{{ job.desc }}</p>
                <a-button type="primary" :disabled="sync.running.value" @click="runSync(job.url, {}, 'push')">
                  <template #icon><UploadOutlined /></template>Export {{ job.title.toLowerCase() }}
                </a-button>
              </a-card>
            </a-col>
          </a-row>
          <a-button v-if="sync.running.value" danger style="margin-bottom: 12px" @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
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
            <a-popconfirm title="Clear all Google Sheets logs?" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <li>Create a project on <a href="https://console.cloud.google.com" target="_blank" rel="noopener">console.cloud.google.com</a> and enable the <strong>Google Sheets API</strong> (APIs &amp; Services → Library).</li>
              <li>Configure the <strong>OAuth consent screen</strong> (External). While it stays in "Testing", add your Google account as a test user — note that testing-mode refresh tokens expire after 7 days, so <strong>publish</strong> the app for a permanent connection.</li>
              <li>Create an <strong>OAuth client ID</strong> of type Web application and register the redirect URI shown on the Connection tab.</li>
              <li>Paste the Client ID and Secret here, save, and click <strong>Connect Google account</strong>.</li>
              <li>Create a spreadsheet with the button (or paste the URL of one your Google account can edit), then run the exports.</li>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              message="Snapshots, not live sync"
              description="Exports rewrite the Sales / Products / Customers tabs on demand. For live row-by-row updates on every sale, connect Zapier — it can append rows the moment events fire."
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Google Sheets integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:google_sheets): GET/POST google-sheets/settings,
 * POST google-sheets/{disconnect|test-connection|create-spreadsheet},
 * GET google-sheets/stats, POST google-sheets/export/{sales|products|customers},
 * GET/DELETE google-sheets/logs. OAuth over web routes:
 * /google-sheets/connect -> Google -> /google-sheets/callback -> ?connected=1 | ?gs_error=...
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  TableOutlined, UploadOutlined, LinkOutlined, CopyOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useBatchSync, COUNTER_LABELS } from '../../composables/useBatchSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const route = useRoute();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const creating = ref(false);
const tab = ref('connection');
const state = ref({ connected: false, enabled: false });
const form = ref({ enabled: false, client_id: '', client_secret: '', spreadsheet: '', auto_export: false });
const stats = ref({});
const logs = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const logLevel = ref('');

const sync = useBatchSync();

const exportJobs = [
  { key: 'sales', title: 'Sales', url: 'google-sheets/export/sales', desc: 'Every sale with customer, totals, payment status and warehouse.' },
  { key: 'products', title: 'Products', url: 'google-sheets/export/products', desc: 'The full catalog with prices, costs and current stock quantities.' },
  { key: 'customers', title: 'Customers', url: 'google-sheets/export/customers', desc: 'All customers with their contact details.' },
];

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
    state.value = await http.get('google-sheets/settings');
    form.value = {
      enabled: !!state.value.enabled,
      client_id: state.value.client_id || '',
      client_secret: '',
      spreadsheet: state.value.spreadsheet_id || '',
      auto_export: !!state.value.auto_export,
    };
    if (state.value.connected) loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function loadStats() {
  try {
    stats.value = await http.get('google-sheets/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      client_id: (form.value.client_id || '').trim() || null,
      spreadsheet: (form.value.spreadsheet || '').trim() || null,
      auto_export: !!form.value.auto_export,
    };
    // The secret is write-only: send it only when the admin typed a new value.
    if (form.value.client_secret) payload.client_secret = form.value.client_secret.trim();
    await http.post('google-sheets/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function connect() {
  window.location.href = state.value.connect_url || '/google-sheets/connect';
}

async function testConnection() {
  testing.value = true;
  try {
    const res = await http.post('google-sheets/test-connection');
    message.success(res?.spreadsheet_title
      ? 'Connected — spreadsheet: ' + res.spreadsheet_title
      : 'Connected to Google.');
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || 'Connection test failed');
  } finally {
    testing.value = false;
  }
}

async function disconnect() {
  try {
    await http.post('google-sheets/disconnect');
    message.success('Disconnected');
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function createSpreadsheet() {
  creating.value = true;
  try {
    const res = await http.post('google-sheets/create-spreadsheet');
    message.success('Spreadsheet created.');
    form.value.spreadsheet = res.spreadsheet_id;
    await load();
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
  } finally {
    creating.value = false;
  }
}

async function runSync(url, params, kind) {
  await sync.run(url, params, kind);
  load();
}

async function loadLogs(page = 1) {
  try {
    const query = 'google-sheets/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('google-sheets/logs');
    loadLogs(1);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text || '');
    message.success(t('Copied'));
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

onMounted(() => {
  load();
  loadLogs(1);
  if (route.query.connected === '1') message.success('Google account connected.');
  if (route.query.gs_error) message.error(String(route.query.gs_error));
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
