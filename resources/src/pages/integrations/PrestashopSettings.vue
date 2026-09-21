<template>
  <div class="page">
    <PageHeader title="PrestaShop" :breadcrumb="['Ecommerce Platforms', 'PrestaShop']">
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
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(223,0,103,0.12)', color: '#df0067' }">
          <template #icon><GlobalOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">PrestaShop store sync</div>
          <div class="muted">Sync products, stock and orders with your PrestaShop store over its webservice API.</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" tab="Connection">
          <a-card size="small" title="Store connection" style="max-width: 640px">
            <a-form layout="vertical">
              <a-form-item label="Store URL">
                <a-input v-model:value="form.store_url" placeholder="https://store.example.com" />
              </a-form-item>
              <a-form-item label="Webservice key">
                <a-input-password
                  v-model:value="form.api_key"
                  :placeholder="state.has_api_key ? 'Saved — paste a new key to replace it' : 'From Advanced Parameters → Webservice'"
                  autocomplete="off"
                />
                <div class="muted" style="margin-top: 4px">
                  Enable the webservice in PrestaShop (Advanced Parameters → Webservice), create a key with
                  permissions on products, stock_availables, orders and customers, and paste it here. Stored write-only.
                </div>
              </a-form-item>
              <a-form-item label="Shop language ID">
                <a-input-number v-model:value="form.default_language_id" :min="1" style="width: 120px" />
                <div class="muted" style="margin-top: 4px">Language id used for product names when pushing (1 = the shop's first language).</div>
              </a-form-item>
              <a-form-item label="Stock warehouse">
                <a-select
                  v-model:value="form.warehouse_id"
                  :options="[{ value: null, label: 'All warehouses (aggregate)' }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                />
              </a-form-item>
              <a-space align="center" style="margin-bottom: 16px">
                <a-switch v-model:checked="form.enabled" />
                <span>Enable PrestaShop sync</span>
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

        <!-- ============================ Products ============================ -->
        <a-tab-pane key="products" tab="Products" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['products_total', 'Total products'], ['products_mapped', 'Linked to PrestaShop']]" />
          <a-card size="small" title="Push products to PrestaShop" style="margin-bottom: 16px">
            <p class="muted">
              Creates missing simple products on PrestaShop (with their current stock). Already-linked products and variant
              products are skipped — keep editing product details in PrestaShop, or pull to refresh the local copy.
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('prestashop/sync/products?mode=push', {}, 'push')">
                <template #icon><UploadOutlined /></template>Push to PrestaShop
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <a-card size="small" title="Pull products from PrestaShop" style="margin-bottom: 16px">
            <p class="muted">Imports PrestaShop products, matching existing ones by reference (SKU) and creating the rest. Combinations are imported as their parent product.</p>
            <a-space>
              <a-button :disabled="sync.running.value" @click="runSync('prestashop/sync/products?mode=pull', {}, 'pull')">
                <template #icon><DownloadOutlined /></template>Pull from PrestaShop
              </a-button>
              <a-popconfirm title="Reset product links? Order history keeps its links." :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetMappings('product')">
                <a-button :disabled="sync.running.value">Reset product links</a-button>
              </a-popconfirm>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Stock ============================ -->
        <a-tab-pane key="stock" tab="Stock" :disabled="!state.configured">
          <a-card size="small" title="Push stock levels to PrestaShop" style="margin-bottom: 16px">
            <p class="muted">
              Updates the shop's stock (stock_availables) for every linked product.
              {{ state.warehouse_id ? 'Counting stock from the selected warehouse only.' : 'Counting stock across all warehouses.' }}
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('prestashop/sync/inventory', {}, 'push')">
                <template #icon><UploadOutlined /></template>Push stock
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Orders ============================ -->
        <a-tab-pane key="orders" tab="Orders" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['orders_imported', 'Orders imported'], ['customers_mapped', 'Customers linked']]" />
          <a-card size="small" title="Pull orders from PrestaShop" style="margin-bottom: 16px">
            <p class="muted">
              Imports shop orders as sales (references like <code>PS-XKBKZAB…</code>), newest first. Already-imported and
              cancelled orders are skipped, so pulling regularly is safe. PrestaShop has no webhooks — run this after busy periods.
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
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('prestashop/sync/orders', ordersWarehouseId ? { warehouse_id: ordersWarehouseId } : {}, 'pull')">
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
            <a-popconfirm title="Clear all PrestaShop logs?" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <li>In your PrestaShop back office, open <strong>Advanced Parameters → Webservice</strong> and enable the webservice.</li>
              <li>Add a new key, granting <strong>View/Add/Edit</strong> on <code>products</code> and <code>stock_availables</code>, and <strong>View</strong> on <code>orders</code> and <code>customers</code>.</li>
              <li>Paste the store URL and the key on the Connection tab, save, and run <strong>Test connection</strong>.</li>
              <li>Pull products first if the shop already has a catalog (matches by reference/SKU); push to create missing simple products.</li>
              <li>Push stock whenever quantities change, and pull orders regularly — PrestaShop has no webhooks, so orders only arrive when you pull.</li>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              message="Product identity is the reference (SKU)"
              description="Stocky's product code is matched to PrestaShop's reference. Keep references consistent on both sides; combinations (variants) are matched in orders by reference but are not pushed in this version."
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * PrestaShop integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:prestashop): GET/POST prestashop/settings,
 * POST prestashop/test-connection, GET prestashop/stats,
 * POST prestashop/reset-mappings, POST prestashop/sync/{products|inventory|orders},
 * GET/DELETE prestashop/logs. No OAuth: HTTP Basic with the webservice key.
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  GlobalOutlined, UploadOutlined, DownloadOutlined,
} from '@ant-design/icons-vue';
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
const form = ref({ enabled: false, store_url: '', api_key: '', default_language_id: 1, warehouse_id: null });
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
    state.value = await http.get('prestashop/settings');
    form.value = {
      enabled: !!state.value.enabled,
      store_url: state.value.store_url || '',
      api_key: '',
      default_language_id: state.value.default_language_id || 1,
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
    stats.value = await http.get('prestashop/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      store_url: (form.value.store_url || '').trim() || null,
      default_language_id: form.value.default_language_id || 1,
      warehouse_id: form.value.warehouse_id,
    };
    // The key is write-only: send it only when the admin typed a new one.
    if (form.value.api_key) payload.api_key = form.value.api_key.trim();
    await http.post('prestashop/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.errors?.store_url?.[0] || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  try {
    await http.post('prestashop/test-connection');
    message.success('Connected to PrestaShop.');
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || 'Connection test failed');
  } finally {
    testing.value = false;
  }
}

async function resetMappings(entityType) {
  try {
    const res = await http.post('prestashop/reset-mappings', { entity_type: entityType });
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
    const query = 'prestashop/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('prestashop/logs');
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
