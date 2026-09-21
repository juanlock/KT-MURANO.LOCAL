<template>
  <div>
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="$t('Customers')" :value="stats.total ?? '—'" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="$t('Synced')" :value="stats.synced ?? '—'" :value-style="{ color: '#52c41a' }" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="$t('Not_Synced')" :value="stats.unsynced ?? '—'" :value-style="{ color: '#faad14' }" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small" style="height: 100%; display: flex; align-items: center">
          <a-space direction="vertical" style="width: 100%">
            <a-button type="primary" block :loading="syncing && syncMode === 'push'" :disabled="syncing" @click="manualSync('push')">
              POS → WooCommerce
            </a-button>
            <a-button block :loading="syncing && syncMode === 'pull'" :disabled="syncing" @click="manualSync('pull')">
              WooCommerce → POS
            </a-button>
          </a-space>
        </a-card>
      </a-col>
    </a-row>

    <a-alert
      v-if="lastSyncResult" type="info" show-icon style="margin-bottom: 16px" closable
      :message="`Created ${lastSyncResult.created} · Updated ${lastSyncResult.updated} · Linked by email ${lastSyncResult.linked_by_email} · Linked by phone ${lastSyncResult.linked_by_phone} · Skipped ${lastSyncResult.skipped} · Errors ${lastSyncResult.errors}`"
      @close="lastSyncResult = null"
    />

    <a-tabs v-model:activeKey="activeList">
      <!-- ============================ POS customers ============================ -->
      <a-tab-pane key="stocky" :tab="'POS'">
        <a-input-search
          v-model:value="stockySearch" :placeholder="$t('Search')" style="max-width: 280px; margin-bottom: 12px"
          @search="() => { stockyPage = 1; loadStockyCustomers(); }"
        />
        <a-table
          :columns="stockyColumns" :data-source="stockyCustomers" :loading="loadingStocky"
          size="small" row-key="id"
          :pagination="{
            current: stockyPage, total: stockyTotalRows, pageSize: stockyPerPage, showSizeChanger: true,
            onChange: (p, ps) => { stockyPage = p; stockyPerPage = ps; loadStockyCustomers(); },
          }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'sync_status'">
              <a-tag :color="record.sync_status === 'synced' ? 'success' : 'default'">
                {{ record.sync_status === 'synced' ? $t('Synced') : $t('Not_Synced') }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button
                size="small" :loading="syncingCustomerId === record.id"
                :disabled="!record.email" @click="syncOne(record, 'push')"
              >
                {{ $t('Sync') }}
              </a-button>
            </template>
          </template>
        </a-table>
      </a-tab-pane>

      <!-- ========================= WooCommerce customers ========================= -->
      <a-tab-pane key="woo" :tab="'WooCommerce'">
        <a-input-search
          v-model:value="wooSearch" :placeholder="$t('Search')" style="max-width: 280px; margin-bottom: 12px"
          @search="() => { wooPage = 1; loadWooCustomers(); }"
        />
        <a-table
          :columns="wooColumns" :data-source="wooCustomers" :loading="loadingWoo"
          size="small" row-key="id"
          :pagination="{
            current: wooPage, total: wooTotalRows, pageSize: wooPerPage, showSizeChanger: true,
            onChange: (p, ps) => { wooPage = p; wooPerPage = ps; loadWooCustomers(); },
          }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'sync_status'">
              <a-tag :color="record.sync_status === 'synced' ? 'success' : 'default'">
                {{ record.sync_status === 'synced' ? $t('Synced') : $t('Not_Synced') }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button
                size="small" :loading="syncingCustomerId === record.id"
                :disabled="!record.email" @click="syncOne(record, 'pull')"
              >
                {{ $t('Sync') }}
              </a-button>
            </template>
          </template>
        </a-table>
      </a-tab-pane>

      <!-- ============================== Sync issues ============================== -->
      <a-tab-pane key="issues" :tab="'Issues (' + issuesTotalRows + ')'">
        <a-input-search
          v-model:value="issuesSearch" :placeholder="$t('Search')" style="max-width: 280px; margin-bottom: 12px"
          @search="() => { issuesPage = 1; loadSyncIssues(); }"
        />
        <a-table
          :columns="issueColumns" :data-source="syncIssues" :loading="loadingIssues"
          size="small" :row-key="(_r, i) => i" :scroll="{ x: 'max-content' }"
          :pagination="{
            current: issuesPage, total: issuesTotalRows, pageSize: issuesPerPage, showSizeChanger: false,
            onChange: p => { issuesPage = p; loadSyncIssues(); },
          }"
          :locale="{ emptyText: $t('NodataAvailable') }"
        />
      </a-tab-pane>
    </a-tabs>

    <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetSync">
      <a-button danger :loading="resetting" style="margin-top: 8px">{{ $t('Reset') }}</a-button>
    </a-popconfirm>
  </div>
</template>

<script setup>
/**
 * WooCommerce customers sync — legacy CustomersTab contracts:
 * - stats GET woocommerce/customers/stats → {total, synced} (unsynced
 *   recomputed client-side)
 * - POS list GET clients (page/limit/SortField/SortType/search); a row is
 *   "synced" when woocommerce_id > 0
 * - Woo list GET woocommerce/customers?page&per_page&search → {ok,
 *   customers, totalRows}; matched against ALL POS clients (limit 10000) by
 *   woocommerce_id then email to derive sync status (legacy logic verbatim)
 * - issues GET woocommerce/customers/sync-issues (paged) → {ok, issues,
 *   totalRows}
 * - bulk POST woocommerce/sync/customers?mode=push|pull → {ok, result
 *   {created, updated, linked_by_email, linked_by_phone, errors, skipped}}
 * - single POST ...?mode=X&customer_id={id}; requires an email
 * - reset POST woocommerce/reset-customers-sync
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../../../lib/http';

const { t } = useI18n();
const emit = defineEmits(['ready', 'refreshed']);

const activeList = ref('stocky');
const syncing = ref(false);
const syncMode = ref(null);
const resetting = ref(false);
const syncingCustomerId = ref(null);
const lastSyncResult = ref(null);

const stats = ref({ total: null, synced: null, unsynced: null });
const statsLoading = ref(true);

const stockyCustomers = ref([]);
const stockyTotalRows = ref(0);
const stockySearch = ref('');
const stockyPage = ref(1);
const stockyPerPage = ref(10);
const loadingStocky = ref(false);

const wooCustomers = ref([]);
const wooTotalRows = ref(0);
const wooSearch = ref('');
const wooPage = ref(1);
const wooPerPage = ref(10);
const loadingWoo = ref(false);

const syncIssues = ref([]);
const issuesTotalRows = ref(0);
const issuesSearch = ref('');
const issuesPage = ref(1);
const issuesPerPage = ref(10);
const loadingIssues = ref(false);

const stockyColumns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', width: 100 },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone', width: 130 },
  { title: t('Sync'), key: 'sync_status', width: 110, align: 'center' },
  { title: t('Actions'), key: 'actions', width: 90, align: 'center' },
]);
const wooColumns = computed(() => [
  { title: 'ID', dataIndex: 'id', key: 'id', width: 80 },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Sync'), key: 'sync_status', width: 110, align: 'center' },
  { title: t('Actions'), key: 'actions', width: 90, align: 'center' },
]);
const issueColumns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('Message'), dataIndex: 'sync_issue', key: 'sync_issue', ellipsis: true },
  { title: t('date'), dataIndex: 'sync_issue_at', key: 'sync_issue_at', width: 160 },
]);

async function loadStats() {
  try {
    const data = await http.get('woocommerce/customers/stats');
    const total = parseInt(data.total, 10) || 0;
    const synced = parseInt(data.synced, 10) || 0;
    stats.value = { total, synced, unsynced: Math.max(0, total - synced) };
  } catch (e) {
    stats.value = { total: null, synced: null, unsynced: null };
  }
}

async function loadStockyCustomers() {
  loadingStocky.value = true;
  try {
    const data = await http.get('clients', {
      page: stockyPage.value, limit: stockyPerPage.value,
      SortField: 'id', SortType: 'desc', search: stockySearch.value,
    });
    stockyCustomers.value = (data.clients || []).map(c => ({
      ...c,
      sync_status: (c.woocommerce_id && parseInt(c.woocommerce_id, 10) > 0) ? 'synced' : 'not_synced',
    }));
    stockyTotalRows.value = data.totalRows || 0;
  } catch (e) {
    stockyCustomers.value = [];
    stockyTotalRows.value = 0;
  } finally {
    loadingStocky.value = false;
  }
}

async function loadWooCustomers() {
  loadingWoo.value = true;
  try {
    const data = await http.get('woocommerce/customers', {
      page: wooPage.value, per_page: wooPerPage.value, search: wooSearch.value,
    });
    if (!data.ok) {
      wooCustomers.value = [];
      wooTotalRows.value = 0;
      return;
    }
    let allStocky = [];
    try {
      const stockyData = await http.get('clients', { limit: 10000 });
      allStocky = stockyData.clients || [];
    } catch (e) { /* fall back to unmatched */ }
    wooCustomers.value = (data.customers || []).map(c => {
      const wooId = parseInt(c.id, 10) || 0;
      const wooEmail = (c.email || '').trim().toLowerCase();
      const fullName = `${(c.first_name || '').trim()} ${(c.last_name || '').trim()}`.trim();
      const displayName = fullName || (c.name || '').trim() || (c.username || '').trim();
      let match = null;
      if (wooId > 0) match = allStocky.find(s => (parseInt(s.woocommerce_id, 10) || 0) === wooId) || null;
      if (!match && wooEmail) match = allStocky.find(s => (s.email || '').trim().toLowerCase() === wooEmail) || null;
      return {
        ...c,
        name: displayName,
        stocky_id: match ? match.id : null,
        sync_status: (wooId > 0 && match && (parseInt(match.woocommerce_id, 10) || 0) === wooId) ? 'synced' : 'not_synced',
      };
    });
    wooTotalRows.value = data.totalRows || 0;
  } catch (e) {
    wooCustomers.value = [];
    wooTotalRows.value = 0;
  } finally {
    loadingWoo.value = false;
  }
}

async function loadSyncIssues() {
  loadingIssues.value = true;
  try {
    const data = await http.get('woocommerce/customers/sync-issues', {
      page: issuesPage.value, limit: issuesPerPage.value,
      SortField: 'sync_issue_at', SortType: 'desc', search: issuesSearch.value,
    });
    if (data.ok) {
      syncIssues.value = data.issues || [];
      issuesTotalRows.value = data.totalRows || 0;
    } else {
      syncIssues.value = [];
      issuesTotalRows.value = 0;
    }
  } catch (e) {
    syncIssues.value = [];
    issuesTotalRows.value = 0;
  } finally {
    loadingIssues.value = false;
  }
}

function loadAll() {
  return Promise.all([loadStats(), loadStockyCustomers(), loadWooCustomers(), loadSyncIssues()]);
}

async function manualSync(mode) {
  if (syncing.value) return;
  syncing.value = true;
  syncMode.value = mode;
  lastSyncResult.value = null;
  try {
    const data = await http.post(`woocommerce/sync/customers?mode=${mode}`);
    if (data.ok) {
      const result = data.result || {};
      lastSyncResult.value = {
        created: Math.max(0, parseInt(result.created, 10) || 0),
        updated: Math.max(0, parseInt(result.updated, 10) || 0),
        linked_by_email: Math.max(0, parseInt(result.linked_by_email, 10) || 0),
        linked_by_phone: Math.max(0, parseInt(result.linked_by_phone, 10) || 0),
        errors: Math.max(0, parseInt(result.errors, 10) || 0),
        skipped: Math.max(0, parseInt(result.skipped, 10) || 0),
      };
      const direction = mode === 'push' ? 'POS → WooCommerce' : 'WooCommerce → POS';
      message.success(`${direction}: Created ${lastSyncResult.value.created}, Updated ${lastSyncResult.value.updated}`);
    } else {
      message.error(t('Sync_Failed') + ': ' + (data.error || 'Unknown error'));
    }
  } catch (e) {
    message.error(t('Sync_Failed') + ': ' + (e.message || 'Network error'));
  } finally {
    syncing.value = false;
    syncMode.value = null;
    loadAll();
    emit('refreshed');
  }
}

async function syncOne(customer, mode) {
  if (!customer.email) {
    message.warning('Customer must have an email to sync');
    return;
  }
  if (syncingCustomerId.value) return;
  syncingCustomerId.value = customer.id;
  try {
    const data = await http.post(`woocommerce/sync/customers?mode=${mode}&customer_id=${customer.id}`);
    if (data.ok) {
      const action = data.created > 0 ? 'created' : 'updated';
      message.success(`Customer "${customer.name}" synced successfully (${action})`);
      loadStockyCustomers();
      loadStats();
      if (mode === 'pull') loadWooCustomers();
      loadSyncIssues();
    } else {
      message.error(`Sync failed: ${data.error || 'Unknown error'}`);
      loadSyncIssues();
    }
  } catch (e) {
    message.error(`Sync failed: ${e.message || 'Network error'}`);
    loadSyncIssues();
  } finally {
    syncingCustomerId.value = null;
  }
}

async function resetSync() {
  if (resetting.value) return;
  resetting.value = true;
  lastSyncResult.value = null;
  try {
    await http.post('woocommerce/reset-customers-sync');
    message.success(t('Successfully_Updated'));
    loadAll();
    emit('refreshed');
  } catch (e) {
    message.error(t('Sync_Failed'));
  } finally {
    resetting.value = false;
  }
}

onMounted(async () => {
  await loadAll();
  statsLoading.value = false;
  emit('ready');
});
</script>
