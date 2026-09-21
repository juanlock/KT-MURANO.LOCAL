<template>
  <div class="page">
    <PageHeader title="Jumia" :breadcrumb="['Ecommerce Platforms', 'Jumia']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.configured && state.enabled ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.configured ? (state.enabled ? $t('Enabled') : $t('Disabled')) : 'Not configured' }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(246,139,30,0.14)', color: '#f68b1e' }">
          <template #icon><ShoppingOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">Jumia marketplace sync</div>
          <div class="muted">Push prices and stock to your Jumia Seller Center listings and import marketplace orders as sales.</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" tab="Connection">
          <a-card size="small" title="Seller Center API" style="max-width: 640px">
            <a-form layout="vertical">
              <a-form-item label="API URL (your country's Seller Center endpoint)">
                <a-input v-model:value="form.api_url" placeholder="https://sellercenter-api.jumia.com.ng" />
              </a-form-item>
              <a-form-item label="Account email (User ID)">
                <a-input v-model:value="form.user_email" placeholder="seller@example.com" />
              </a-form-item>
              <a-form-item label="API key">
                <a-input-password
                  v-model:value="form.api_key"
                  :placeholder="state.has_api_key ? 'Saved — paste a new key to replace it' : 'Seller Center → Settings → Integration Management'"
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
                <span>Enable Jumia sync</span>
              </a-space>
              <div>
                <a-space wrap>
                  <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                  <a-button :disabled="!state.configured" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
                </a-space>
              </div>
            </a-form>
          </a-card>
        </a-tab-pane>

        <!-- ============================ Price & Stock ============================ -->
        <a-tab-pane key="stock" tab="Prices & Stock" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['products_total', 'Total products']]" />
          <a-card size="small" title="Push prices & stock to Jumia" style="margin-bottom: 16px">
            <p class="muted">
              Sends the current price and quantity for every product (and variant) that has a SKU matching the
              Jumia listing's <strong>Seller SKU</strong>. Updates are submitted as Seller Center feeds and applied
              asynchronously — the feed ID is recorded in the Logs tab, and per-item rejections appear in
              Seller Center's feed status screen.
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('jumia/sync/price-stock', {}, 'push')">
                <template #icon><UploadOutlined /></template>Push prices & stock
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Orders ============================ -->
        <a-tab-pane key="orders" tab="Orders" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['orders_imported', 'Orders imported']]" />
          <a-card size="small" title="Pull orders from Jumia" style="margin-bottom: 16px">
            <p class="muted">
              Imports marketplace orders as sales (references like <code>JM-402…</code>), matched to your products by
              Seller SKU. Already-imported and cancelled orders are skipped, so pulling regularly is safe. Payments are
              marked paid only for delivered orders (Jumia is COD-dominant).
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
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('jumia/sync/orders', ordersWarehouseId ? { warehouse_id: ordersWarehouseId } : {}, 'pull')">
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
            <a-popconfirm title="Clear all Jumia logs?" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <li>In Jumia <strong>Seller Center</strong>, open Settings → <strong>Integration Management</strong> and generate an API key.</li>
              <li>Enter your country's API URL (e.g. <code>https://sellercenter-api.jumia.com.ng</code> for Nigeria, <code>.co.ke</code> for Kenya, <code>.com.eg</code> for Egypt), your Seller Center account email, and the key — then run <strong>Test connection</strong>.</li>
              <li>Make sure each Jumia listing's <strong>Seller SKU</strong> equals the Stocky product code — that is the identity used for both stock pushes and order matching.</li>
              <li>Push prices &amp; stock after inventory changes; pull orders regularly (Jumia has no webhooks in this API).</li>
              <li>Creating new listings stays in Seller Center — Jumia requires category-specific attributes and image review that only their portal handles well.</li>
            </ol>
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Jumia integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:jumia): GET/POST jumia/settings, POST jumia/test-connection,
 * GET jumia/stats, POST jumia/sync/{price-stock|orders}, GET/DELETE jumia/logs.
 * Auth is a signed Seller Center API key — no OAuth.
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ShoppingOutlined, UploadOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useBatchSync, COUNTER_LABELS } from '../../composables/useBatchSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const tab = ref('connection');
const state = ref({ configured: false, enabled: false, warehouses: [] });
const form = ref({ enabled: false, api_url: '', user_email: '', api_key: '', warehouse_id: null });
const stats = ref({});
const ordersWarehouseId = ref(null);
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
    state.value = await http.get('jumia/settings');
    form.value = {
      enabled: !!state.value.enabled,
      api_url: state.value.api_url || '',
      user_email: state.value.user_email || '',
      api_key: '',
      warehouse_id: state.value.warehouse_id || null,
    };
    if (state.value.configured) loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function loadStats() {
  try {
    stats.value = await http.get('jumia/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      api_url: (form.value.api_url || '').trim() || null,
      user_email: (form.value.user_email || '').trim() || null,
      warehouse_id: form.value.warehouse_id,
    };
    // The key is write-only: send it only when the admin typed a new one.
    if (form.value.api_key) payload.api_key = form.value.api_key.trim();
    await http.post('jumia/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.errors?.api_url?.[0] || e?.data?.errors?.user_email?.[0] || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  try {
    await http.post('jumia/test-connection');
    message.success('Connected to Jumia Seller Center.');
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || 'Connection test failed');
  } finally {
    testing.value = false;
  }
}

async function runSync(url, params, kind) {
  await sync.run(url, params, kind);
  loadStats();
}

async function loadLogs(page = 1) {
  try {
    const query = 'jumia/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('jumia/logs');
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
