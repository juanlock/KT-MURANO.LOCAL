<template>
  <div class="page">
    <PageHeader :title="$t('Quickbooks_Sync')" :breadcrumb="[$t('Settings'), $t('Quickbooks_Sync')]" />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-tabs>
        <!-- ============================== Connection ============================== -->
        <a-tab-pane key="connection" :tab="$t('Connection')">
          <a-descriptions :column="1" bordered size="small" style="max-width: 720px; margin-bottom: 16px">
            <a-descriptions-item :label="$t('Environment')">{{ status.env || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Redirect_URI_active')">{{ status.redirect || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Callback')">{{ status.callback || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Status')">
              <a-tag :color="status.has_token ? 'success' : 'error'">
                {{ status.has_token ? $t('Connected') : $t('Disconnected') }}
              </a-tag>
            </a-descriptions-item>
            <a-descriptions-item v-if="status.token_realm" :label="$t('Realm_ID')">{{ status.token_realm }}</a-descriptions-item>
          </a-descriptions>
          <a-space wrap>
            <a-button v-if="!status.has_token" type="primary" @click="connectBlank">{{ $t('Connect_to_QuickBooks') }}</a-button>
            <a-popconfirm v-else :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="disconnect">
              <a-button danger :loading="busy">{{ $t('Disconnect') }}</a-button>
            </a-popconfirm>
            <a-button @click="clearCache">{{ $t('Clear_Cache') }}</a-button>
          </a-space>
        </a-tab-pane>

        <!-- ============================== Settings ============================== -->
        <a-tab-pane key="settings" :tab="$t('Settings')">
          <a-form layout="vertical" style="max-width: 720px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Client_ID')" :help="$t('From_Intuit_Keys_Identifies_App')">
                  <a-input v-model:value="form.client_id" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Client_Secret')" :help="$t('From_Intuit_Keys_Keep_private_rotate')">
                  <a-input-password v-model:value="form.client_secret" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Redirect_URI')">
                  <a-input v-model:value="form.redirect" />
                  <template #help>
                    {{ $t('Must_match_Intuit_redirect_exactly') }}
                    {{ $t('Typical') }}: <a-typography-text code>{{ callbackExample }}</a-typography-text>
                  </template>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Environment')">
                  <a-select
                    v-model:value="form.env"
                    :options="[
                      { label: $t('Development'), value: 'Development' },
                      { label: $t('Production'), value: 'Production' },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Realm_ID')" :help="$t('Realm_ID_Hint')">
                  <a-input v-model:value="form.realm_id" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Income_Account_Name')" :help="$t('Income_Account_Name_Hint')">
                  <a-input v-model:value="form.income_account_name" :placeholder="$t('Income_Account_Name')" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-button type="primary" :loading="busy" @click="save">{{ $t('Save') }}</a-button>
          </a-form>
        </a-tab-pane>

        <!-- ============================== Clients sync ============================== -->
        <a-tab-pane key="clients" :tab="$t('Clients_Sync')">
          <a-alert
            v-if="!status.has_token" type="warning" show-icon style="margin-bottom: 16px"
            :message="$t('Not_connected_to_QuickBooks')"
            :description="$t('Please_connect_before_syncing_clients')"
          >
            <template #action>
              <a-button size="small" type="primary" @click="connectBlank">{{ $t('Connect') }}</a-button>
            </template>
          </a-alert>

          <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
            <a-col :xs="12" :md="6"><a-card size="small"><a-statistic :title="$t('Total_Clients')" :value="clientStats.total" /></a-card></a-col>
            <a-col :xs="12" :md="6"><a-card size="small"><a-statistic :title="$t('Synced')" :value="clientStats.synced" :value-style="{ color: '#52c41a' }" /></a-card></a-col>
            <a-col :xs="12" :md="6"><a-card size="small"><a-statistic :title="$t('Not_Synced')" :value="clientStats.not_synced" :value-style="{ color: '#ff4d4f' }" /></a-card></a-col>
            <a-col :xs="12" :md="6">
              <a-card size="small" style="height: 100%; display: flex; align-items: center">
                <a-button type="primary" block :loading="syncingClients" :disabled="!status.has_token" @click="syncAllClients">
                  {{ $t('Sync_All_Clients') }}
                </a-button>
              </a-card>
            </a-col>
          </a-row>

          <div style="margin-bottom: 12px">
            <span style="font-size: 12px; color: #8c8c8c">{{ $t('Overall_progress') }}</span>
            <a-progress :percent="syncPercent" size="small" />
          </div>

          <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px">
            <a-input-search
              v-model:value="search" :placeholder="$t('Search')" style="max-width: 280px"
              @search="loadUnsynced(1)"
            />
            <a-button @click="loadClientStats">{{ $t('Refresh_Stats') }}</a-button>
            <a-button @click="loadUnsynced(page)">{{ $t('Refresh_List') }}</a-button>
            <a-button
              v-if="selectedIds.length" type="primary" :loading="syncingClients"
              @click="syncSelected"
            >
              {{ $t('Sync_Selected') }} ({{ selectedIds.length }})
            </a-button>
          </div>

          <a-table
            :columns="unsyncedColumns" :data-source="unsynced.items"
            size="small" :loading="unsyncedBusy" row-key="id"
            :row-selection="{ selectedRowKeys: selectedIds, onChange: keys => (selectedIds = keys) }"
            :pagination="{
              current: page, total: unsynced.total, pageSize: 15, showSizeChanger: false,
              onChange: p => loadUnsynced(p),
            }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'created_at'">{{ fmtDate(record.created_at) }}</template>
              <template v-else-if="column.key === 'actions'">
                <a-button size="small" :loading="syncingRowId === record.id" :disabled="!status.has_token" @click="syncOne(record)">
                  {{ $t('Sync') }}
                </a-button>
              </template>
            </template>
          </a-table>

          <a-alert v-if="syncReport" type="info" style="margin-top: 12px" show-icon>
            <template #message>
              {{ $t('Synced') }}: <strong>{{ syncReport.synced_count }}</strong> ·
              {{ $t('Failed') }}: <strong>{{ syncReport.failed_count || 0 }}</strong>
            </template>
            <template #description>
              <ul v-if="(syncReport.failures || []).length" style="margin: 0; padding-left: 18px">
                <li v-for="(f, i) in syncReport.failures" :key="i">{{ f.name || f.id }}: {{ f.error }}</li>
              </ul>
            </template>
          </a-alert>

          <ul style="margin-top: 16px; padding-left: 18px; font-size: 12px; color: #8c8c8c">
            <li>{{ $t('Only_clients_without_quickbooks_customer_id_are_candidates') }}</li>
            <li>{{ $t('If_matching_email_exists_reuse_instead_of_duplicate') }}</li>
          </ul>
        </a-tab-pane>

        <!-- ============================== Audit log ============================== -->
        <a-tab-pane key="audits" :tab="'Audit Log'">
          <div style="display: flex; gap: 8px; margin-bottom: 12px">
            <a-select
              v-model:value="level" style="width: 160px"
              :options="[
                { label: $t('All'), value: '' },
                { label: $t('Errors'), value: 'error' },
                { label: $t('Warnings'), value: 'warning' },
                { label: $t('Info'), value: 'info' },
              ]"
              @change="loadAudits(1)"
            />
            <a-button @click="loadAudits(page)">{{ $t('Refresh') }}</a-button>
            <span style="margin-left: auto; align-self: center; font-size: 12px; color: #8c8c8c">
              {{ $t('Total') }}: {{ audits.total }}
            </span>
          </div>
          <a-table
            :columns="auditColumns" :data-source="audits.data"
            size="small" row-key="id" :scroll="{ x: 'max-content' }"
            :pagination="{
              current: page, total: audits.total, pageSize: 15, showSizeChanger: false,
              onChange: p => loadAudits(p),
            }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'when'">{{ fmtWhen(record.created_at) }}</template>
              <template v-else-if="column.key === 'level'">
                <a-tag :color="{ error: 'error', warning: 'warning', info: 'success' }[record.level] || 'default'">
                  {{ record.level }}
                </a-tag>
              </template>
            </template>
          </a-table>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * QuickBooks sync — endpoints verbatim from legacy quickbooks_sync.vue:
 * GET /quickbooks/status → {env, redirect, callback, has_token, token_realm,
 * connect_url}; GET/POST /quickbooks/settings (client_id/secret/redirect/
 * env Development|Production/realm_id/income_account_name — saved to .env);
 * POST /quickbooks/disconnect; GET /quickbooks/audits?page&level;
 * GET /quickbooks/clients-stats → {total, synced, not_synced};
 * GET /quickbooks/clients-unsynced?page&q → paginator {items? data?};
 * POST /quickbooks/sync-clients [{ids}] → {synced_count, failed_count,
 * failures, note}. Connect = full-page redirect to connect_url.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const busy = ref(false);

const status = ref({ env: '', redirect: '', callback: '', has_token: false, token_realm: null, connect_url: '' });
const form = ref({ client_id: '', client_secret: '', redirect: '', env: 'Development', realm_id: '', income_account_name: '' });

const level = ref('');
const audits = ref({ data: [], total: 0, last_page: 1 });
const page = ref(1);

const loadingClients = ref(false);
const syncingClients = ref(false);
const syncingRowId = ref(null);
const clientStats = ref({ total: 0, synced: 0, not_synced: 0 });
const unsyncedBusy = ref(false);
const unsynced = ref({ items: [], last_page: 1, total: 0, current_page: 1 });
const search = ref('');
const selectedIds = ref([]);
const syncReport = ref(null);

const callbackExample = computed(() => `${window.location.origin}/quickbooks/callback`);
const syncPercent = computed(() => {
  const total = clientStats.value.total || 0;
  const synced = clientStats.value.synced || 0;
  return total === 0 ? 0 : Math.round((synced / total) * 100);
});

const unsyncedColumns = computed(() => [
  { title: t('ID'), dataIndex: 'id', key: 'id', width: 80 },
  { title: t('Client'), dataIndex: 'name', key: 'name' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Added'), key: 'created_at', width: 120 },
  { title: t('Actions'), key: 'actions', width: 100, align: 'center' },
]);
const auditColumns = computed(() => [
  { title: '#', dataIndex: 'id', key: 'id', width: 70 },
  { title: t('When'), key: 'when', width: 160 },
  { title: t('Operation'), dataIndex: 'operation', key: 'operation' },
  { title: t('Level'), key: 'level', width: 100 },
  { title: t('Sale'), dataIndex: 'sale_id', key: 'sale_id', width: 80 },
  { title: t('Realm_ID'), dataIndex: 'realm_id', key: 'realm_id' },
  { title: 'Env', dataIndex: 'env', key: 'env', width: 110 },
  { title: t('Message'), dataIndex: 'message', key: 'message', ellipsis: true },
  { title: 'HTTP', dataIndex: 'http_status', key: 'http_status', width: 70 },
]);

// Legacy pins these formats to Africa/Casablanca.
function fmtWhen(iso) {
  if (!iso) return '-';
  try {
    return new Intl.DateTimeFormat(undefined, {
      timeZone: 'Africa/Casablanca', dateStyle: 'medium', timeStyle: 'short',
    }).format(new Date(iso));
  } catch (e) { return iso; }
}
function fmtDate(iso) {
  if (!iso) return '-';
  try {
    return new Intl.DateTimeFormat(undefined, {
      timeZone: 'Africa/Casablanca', year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date(iso));
  } catch (e) { return iso; }
}

async function loadStatus() {
  try {
    status.value = await http.get('quickbooks/status');
  } catch (e) {
    message.error(t('Failed_to_load_status'));
  }
}
function connectBlank() {
  window.location.href = status.value.connect_url || '/quickbooks/connect';
}
async function disconnect() {
  busy.value = true;
  try {
    await http.post('quickbooks/disconnect');
    message.success(t('Disconnected'));
    await loadStatus();
  } catch (e) {
    message.error(t('Failed_to_disconnect'));
  } finally {
    busy.value = false;
  }
}
async function clearCache() {
  try {
    await http.get('clear_cache');
    message.success(t('Cache_cleared_successfully'));
  } catch (e) {
    message.error(t('Failed_to_clear_cache'));
  }
}

async function loadSettings() {
  try {
    const data = await http.get('quickbooks/settings', { time: Date.now() });
    form.value = { ...form.value, ...data };
  } catch (e) {
    message.error(t('Failed_to_load_settings'));
  }
}
async function save() {
  busy.value = true;
  try {
    await http.post('quickbooks/settings', form.value);
    message.success(t('Settings_saved_to_env'));
    await loadStatus();
  } catch (e) {
    message.error(t('Failed_to_save_settings'));
  } finally {
    busy.value = false;
  }
}

async function loadAudits(p = 1) {
  page.value = p;
  try {
    audits.value = await http.get('quickbooks/audits', { page: p, level: level.value });
  } catch (e) {
    message.error(t('Failed_to_load_audit_logs'));
  }
}

async function loadClientStats() {
  loadingClients.value = true;
  try {
    clientStats.value = await http.get('quickbooks/clients-stats');
  } catch (e) {
    message.error(t('Failed_to_load_client_stats'));
  } finally {
    loadingClients.value = false;
  }
}

async function loadUnsynced(p = 1) {
  unsyncedBusy.value = true;
  try {
    const data = await http.get('quickbooks/clients-unsynced', { page: p, q: search.value });
    unsynced.value = { ...data, items: data.items || data.data || [] };
    page.value = data.current_page || p;
  } catch (e) {
    message.error(t('Failed_to_load_unsynced_clients'));
  } finally {
    unsyncedBusy.value = false;
  }
}

async function runSync(ids) {
  if (!status.value.has_token) {
    message.warning(t('Connect_to_QuickBooks_first'));
    return null;
  }
  syncingClients.value = true;
  syncReport.value = null;
  try {
    const data = await http.post('quickbooks/sync-clients', ids ? { ids } : {});
    syncReport.value = data;
    await Promise.all([loadClientStats(), loadUnsynced(page.value)]);
    return data;
  } catch (e) {
    return undefined;
  } finally {
    syncingClients.value = false;
  }
}
async function syncAllClients() {
  const data = await runSync(null);
  if (data === undefined) { message.error(t('Failed_to_sync_clients')); return; }
  if (!data) return;
  if ((data.synced_count || 0) > 0) message.success(`${t('Synced')} ${data.synced_count} ${t('Clients')}`);
  else message.warning(data.note || t('No_clients_were_synced'));
}
async function syncSelected() {
  if (!selectedIds.value.length) return;
  const data = await runSync(selectedIds.value);
  if (data === undefined) { message.error(t('Sync_selected_failed')); return; }
  if (!data) return;
  selectedIds.value = [];
  const fn = (data.synced_count || 0) > 0 ? message.success : message.warning;
  fn(`${t('Synced')} ${data.synced_count} ${t('Selected')}`);
}
async function syncOne(row) {
  syncingRowId.value = row.id;
  const data = await runSync([row.id]);
  syncingRowId.value = null;
  if (data === undefined) { message.error(t('Client_sync_failed')); return; }
  if (!data) return;
  const fn = (data.synced_count || 0) > 0 ? message.success : message.warning;
  fn(t('Client_sync_attempted'));
}

onMounted(async () => {
  try {
    await Promise.all([loadStatus(), loadSettings(), loadAudits(1), loadClientStats(), loadUnsynced(1)]);
  } finally {
    isLoading.value = false;
  }
});
</script>
