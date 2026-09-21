<template>
  <div class="page">
    <PageHeader title="Salla" :breadcrumb="['Ecommerce Platforms', 'Salla']">
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
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(0,165,142,0.14)', color: '#00a58e' }">
          <template #icon><ShopOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">Salla store sync</div>
          <div class="muted">Sync products, stock and orders with your Salla store — orders arrive automatically via webhooks.</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" tab="Connection">
          <a-row :gutter="[16, 16]">
            <a-col :xs="24" :lg="12">
              <a-card size="small" title="App credentials" style="height: 100%">
                <a-form layout="vertical">
                  <a-form-item label="Client ID">
                    <a-input v-model:value="form.client_id" placeholder="From your Salla Partners app" />
                  </a-form-item>
                  <a-form-item label="Client Secret">
                    <a-input-password
                      v-model:value="form.client_secret"
                      :placeholder="state.has_client_secret ? 'Saved — paste a new value to replace it' : 'From your Salla Partners app'"
                      autocomplete="off"
                    />
                  </a-form-item>
                  <a-form-item label="Webhook Secret">
                    <a-input-password
                      v-model:value="form.webhook_secret"
                      :placeholder="state.has_webhook_secret ? 'Saved — paste a new value to replace it' : 'Signature secret from the Webhooks page of your app'"
                      autocomplete="off"
                    />
                  </a-form-item>
                  <a-form-item label="Stock warehouse">
                    <a-select
                      v-model:value="form.warehouse_id"
                      :options="[{ value: null, label: 'All warehouses (aggregate)' }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                    />
                  </a-form-item>
                  <a-space align="center" style="margin-bottom: 16px">
                    <a-switch v-model:checked="form.enabled" />
                    <span>Enable Salla sync</span>
                  </a-space>
                  <div>
                    <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                  </div>
                </a-form>
              </a-card>
            </a-col>

            <a-col :xs="24" :lg="12">
              <a-card size="small" title="Store connection" style="margin-bottom: 16px">
                <a-descriptions :column="1" size="small" bordered>
                  <a-descriptions-item label="Store">
                    {{ state.store?.name || '—' }}
                    <span v-if="state.store?.domain" class="muted" style="margin-left: 6px">({{ state.store.domain }})</span>
                  </a-descriptions-item>
                  <a-descriptions-item label="Token expires">
                    {{ state.access_token_expires_at ? date(state.access_token_expires_at) : '—' }}
                  </a-descriptions-item>
                  <a-descriptions-item label="Last sync">
                    {{ state.last_sync_at ? date(state.last_sync_at) : '—' }}
                  </a-descriptions-item>
                </a-descriptions>
                <a-space wrap style="margin-top: 16px">
                  <a-button type="primary" :disabled="!state.client_id || !state.has_client_secret" @click="connect">
                    <template #icon><LinkOutlined /></template>
                    {{ state.connected ? 'Reconnect with Salla' : 'Connect with Salla' }}
                  </a-button>
                  <a-button :disabled="!state.connected" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
                  <a-popconfirm
                    v-if="state.connected"
                    title="Disconnect the Salla store? Sync stops until you reconnect."
                    :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="disconnect"
                  >
                    <a-button danger>Disconnect</a-button>
                  </a-popconfirm>
                </a-space>
              </a-card>

              <a-card size="small" title="URLs for the Salla Partners portal">
                <div class="url-row">
                  <span class="muted">OAuth callback</span>
                  <a-input-group compact style="display: flex">
                    <a-input :value="state.callback_url" readonly style="flex: 1" />
                    <a-button @click="copy(state.callback_url)"><CopyOutlined /></a-button>
                  </a-input-group>
                </div>
                <div class="url-row">
                  <span class="muted">Webhook URL (all events)</span>
                  <a-input-group compact style="display: flex">
                    <a-input :value="state.webhook_url" readonly style="flex: 1" />
                    <a-button @click="copy(state.webhook_url)"><CopyOutlined /></a-button>
                  </a-input-group>
                </div>
                <div class="muted" style="margin-top: 8px">
                  Set both in your app on <a href="https://salla.partners" target="_blank" rel="noopener">salla.partners</a>,
                  choose the <strong>Signature</strong> webhook security strategy, and subscribe to the order, product and app events.
                </div>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ============================ Products ============================ -->
        <a-tab-pane key="products" tab="Products" :disabled="!state.connected">
          <StatsRow :stats="stats" :items="[['products_total', 'Total products'], ['products_mapped', 'Linked to Salla'], ['variants_mapped', 'Variants linked']]" />
          <a-card size="small" title="Push products to Salla" style="margin-bottom: 16px">
            <p class="muted">Creates or updates simple products on Salla by SKU. Variant products are skipped in this version — pull them from Salla instead, or match them by SKU.</p>
            <a-checkbox v-model:checked="onlyUnsynced" :disabled="sync.running.value" style="margin-bottom: 12px">Only products not linked yet</a-checkbox>
            <div>
              <a-space>
                <a-button type="primary" :disabled="sync.running.value" @click="runSync('salla/sync/products?mode=push', { only_unsynced: onlyUnsynced }, 'push')">
                  <template #icon><UploadOutlined /></template>Push to Salla
                </a-button>
                <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
              </a-space>
            </div>
          </a-card>
          <a-card size="small" title="Pull products from Salla" style="margin-bottom: 16px">
            <p class="muted">Imports Salla products (variants included), matching existing ones by SKU and creating the rest.</p>
            <a-space>
              <a-button :disabled="sync.running.value" @click="runSync('salla/sync/products?mode=pull', {}, 'pull')">
                <template #icon><DownloadOutlined /></template>Pull from Salla
              </a-button>
              <a-popconfirm title="Reset product & variant links? Order history keeps its links." :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetMappings('product')">
                <a-button :disabled="sync.running.value">Reset product links</a-button>
              </a-popconfirm>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Stock ============================ -->
        <a-tab-pane key="stock" tab="Stock" :disabled="!state.connected">
          <a-card size="small" title="Push stock levels to Salla" style="margin-bottom: 16px">
            <p class="muted">
              Sends current quantities for every linked product (by SKU) and linked variant.
              {{ state.warehouse_id ? 'Counting stock from the selected warehouse only.' : 'Counting stock across all warehouses.' }}
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('salla/sync/inventory', {}, 'push')">
                <template #icon><UploadOutlined /></template>Push stock
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Orders ============================ -->
        <a-tab-pane key="orders" tab="Orders" :disabled="!state.connected">
          <StatsRow :stats="stats" :items="[['orders_imported', 'Orders imported'], ['customers_mapped', 'Customers linked']]" />
          <a-card size="small" title="Pull orders from Salla" style="margin-bottom: 16px">
            <p class="muted">
              New Salla orders normally arrive on their own through webhooks and become sales.
              Use this pull to backfill orders placed before the webhook was set up — already-imported orders are skipped.
            </p>
            <a-form layout="inline" style="margin-bottom: 12px">
              <a-form-item label="Import into warehouse">
                <a-select
                  v-model:value="ordersWarehouseId" style="min-width: 220px"
                  :options="[{ value: null, label: 'Default (settings / first warehouse)' }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                />
              </a-form-item>
            </a-form>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('salla/sync/orders', ordersWarehouseId ? { warehouse_id: ordersWarehouseId } : {}, 'pull')">
                <template #icon><DownloadOutlined /></template>Pull orders
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
            <a-popconfirm title="Clear all Salla logs?" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <li>Create a (private) app on <a href="https://salla.partners" target="_blank" rel="noopener">salla.partners</a> and pick the <strong>Custom Mode</strong> authorization flow.</li>
              <li>Copy the app's <strong>Client ID</strong> and <strong>Client Secret</strong> into the Connection tab and save.</li>
              <li>In the app's settings, set the OAuth callback URL shown on the Connection tab.</li>
              <li>On the app's Webhooks page: choose the <strong>Signature</strong> security strategy, copy its secret into the Webhook Secret field here, set the Webhook URL shown on the Connection tab, and subscribe at least to <code>order.created</code>, <code>order.updated</code>, <code>product.updated</code>, <code>product.deleted</code>, <code>app.store.authorize</code> and <code>app.uninstalled</code>.</li>
              <li>Click <strong>Connect with Salla</strong> and approve the app on your store. (Apps in Easy Mode connect automatically through the <code>app.store.authorize</code> webhook instead.)</li>
              <li>Pull or push products, then push stock. New orders arrive automatically and appear under Sales with references like <code>SLA-12345</code>.</li>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              message="Product identity is the SKU"
              description="Stocky's product code is matched to Salla's SKU. Keep SKUs consistent on both sides; variant products are matched on pull and in orders, but are not pushed in this version."
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Salla integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:salla): GET/POST salla/settings, POST salla/disconnect,
 * POST salla/test-connection, GET salla/stats, POST salla/reset-mappings,
 * POST salla/sync/{products|inventory|orders}, GET/DELETE salla/logs.
 * OAuth runs over web routes: /salla/connect -> Salla -> /salla/callback,
 * which redirects back here with ?connected=1 or ?salla_error=...
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ShopOutlined, UploadOutlined, DownloadOutlined, LinkOutlined, CopyOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useSallaSync, COUNTER_LABELS } from '../../composables/useSallaSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const route = useRoute();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const tab = ref('connection');
const state = ref({ connected: false, enabled: false, warehouses: [], store: {} });
const form = ref({ enabled: false, client_id: '', client_secret: '', webhook_secret: '', warehouse_id: null });
const stats = ref({});
const onlyUnsynced = ref(true);
const ordersWarehouseId = ref(null);
const logs = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const logLevel = ref('');

const sync = useSallaSync();

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
    state.value = await http.get('salla/settings');
    form.value = {
      enabled: !!state.value.enabled,
      client_id: state.value.client_id || '',
      client_secret: '',
      webhook_secret: '',
      warehouse_id: state.value.warehouse_id || null,
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
    stats.value = await http.get('salla/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      client_id: (form.value.client_id || '').trim() || null,
      warehouse_id: form.value.warehouse_id,
    };
    // Secrets are write-only: send them only when the admin typed a new value.
    if (form.value.client_secret) payload.client_secret = form.value.client_secret.trim();
    if (form.value.webhook_secret) payload.webhook_secret = form.value.webhook_secret.trim();
    await http.post('salla/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function connect() {
  window.location.href = state.value.connect_url || '/salla/connect';
}

async function testConnection() {
  testing.value = true;
  try {
    const res = await http.post('salla/test-connection');
    message.success('Connected to ' + (res?.store?.name || 'Salla'));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || 'Connection test failed');
  } finally {
    testing.value = false;
  }
}

async function disconnect() {
  try {
    await http.post('salla/disconnect');
    message.success('Disconnected');
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function resetMappings(entityType) {
  try {
    const res = await http.post('salla/reset-mappings', { entity_type: entityType });
    message.success(`Removed ${res.deleted} links`);
    loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function runSync(url, params, kind) {
  await sync.run(url, params, kind);
  loadStats();
}

async function loadLogs(page = 1) {
  try {
    const query = 'salla/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('salla/logs');
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
  if (route.query.connected === '1') message.success('Salla store connected.');
  if (route.query.salla_error) message.error(String(route.query.salla_error));
});

// ---- small local render components (same pattern as ShopifySettings.vue) ----

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
.url-row {
  margin-bottom: 12px;
}
.url-row > .muted {
  display: block;
  margin-bottom: 4px;
}
.guide {
  padding-left: 20px;
  line-height: 2;
  margin: 0 0 12px;
}
</style>
