<template>
  <div>
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="$t('Products')" :value="totalProducts ?? '—'" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="$t('Synced')" :value="syncedDisplay" :value-style="{ color: '#52c41a' }" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="$t('Not_Synced')" :value="unsyncedCount ?? '—'" :value-style="{ color: '#faad14' }" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <a-statistic
            :title="'WooCommerce → POS'"
            :value="(pullStats.imported ?? '—') + ' / ' + (pullStats.total_woo ?? '—')"
            :value-style="{ fontSize: '18px' }"
          />
        </a-card>
      </a-col>
    </a-row>

    <!-- Progress -->
    <a-card size="small" v-if="isSyncActive" style="margin-bottom: 16px">
      <a-progress :percent="displayPercentage" status="active" />
      <div style="font-size: 12px; color: #8c8c8c; margin-top: 8px">
        {{ (syncMode === 'pull' ? 'WooCommerce → POS' : 'POS → WooCommerce') }} ·
        {{ displayProcessed }} / {{ displayTotal }}
        <template v-if="progress.current_sku"> · SKU {{ progress.current_sku }}</template>
        <template v-if="progress.stage"> · {{ progress.stage }}</template>
      </div>
    </a-card>

    <a-space wrap style="margin-bottom: 16px">
      <a-button type="primary" :disabled="isSyncActive" @click="manualSync('push', syncOnlyUnsynced)">
        <UploadOutlined /> {{ $t('Sync') }} — POS → WooCommerce
      </a-button>
      <a-checkbox v-model:checked="syncOnlyUnsynced" :disabled="!unsyncedAvailable">
        {{ $t('Not_Synced') }} ({{ unsyncedCount ?? 0 }})
      </a-checkbox>
      <a-button :disabled="isSyncActive" @click="manualSync('pull', false)">
        <DownloadOutlined /> {{ $t('Sync') }} — WooCommerce → POS
      </a-button>
      <a-button v-if="showStopSync" danger :loading="stopping" @click="stopSync">{{ $t('Cancel') }}</a-button>
      <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetSync">
        <a-button danger :loading="resetting">{{ $t('Reset') }}</a-button>
      </a-popconfirm>
    </a-space>

    <a-divider style="margin: 8px 0 16px" />

    <a-space wrap>
      <a-button :loading="fixingCategories" @click="fixProductCategories">Fix product categories</a-button>
      <a-button :loading="autoLinking" @click="autoLinkBySku">Auto-link by SKU</a-button>
      <a-button :loading="loadingUnmapped" @click="loadUnmapped">Unmapped report</a-button>
    </a-space>

    <!-- Unmapped report -->
    <a-modal v-model:open="unmappedModal" title="Unmapped report" :footer="null" width="860px">
      <template v-if="unmappedReport">
        <h4>Order import failures</h4>
        <a-table
          :columns="failureColumns" :data-source="unmappedReport.order_failures || []"
          size="small" :pagination="{ pageSize: 8 }" :row-key="(_r, i) => i"
          :locale="{ emptyText: $t('NodataAvailable') }" :scroll="{ x: 'max-content' }"
        />
        <h4 style="margin-top: 16px">Unlinked products</h4>
        <a-table
          :columns="unlinkedProductColumns" :data-source="unmappedReport.unlinked_products || []"
          size="small" :pagination="{ pageSize: 8 }" row-key="id"
          :locale="{ emptyText: $t('NodataAvailable') }"
        />
        <h4 style="margin-top: 16px">Unlinked variants</h4>
        <a-table
          :columns="unlinkedVariantColumns" :data-source="unmappedReport.unlinked_variants || []"
          size="small" :pagination="{ pageSize: 8 }" row-key="id"
          :locale="{ emptyText: $t('NodataAvailable') }"
        />
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * WooCommerce products sync — the heaviest tab. Push/pull runs as either a
 * server JOB (woo-sync/status/{id}, cancel via woo-sync/{id}/cancel; job id
 * persisted in localStorage woo_products_{push|pull}_job_id and restored on
 * mount) or a TOKEN run (woocommerce/sync/products?mode=push|pull
 * [&only_unsynced=1] → {ok, token|sync_job_id}; progress via
 * woocommerce/sync/products/progress?token; stop via .../stop {token}).
 * Legacy safeguards kept: fast poll 4s ×8 then 10s; token runs abort as
 * failed if progress is unchanged for 60s. Extra tools: fix-categories,
 * auto-link by SKU, unmapped report (limit 100), reset-products-sync.
 * only_unsynced preference persists in woo_products_push_only_unsynced.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { UploadOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import http from '../../../lib/http';

const { t } = useI18n();
const emit = defineEmits(['ready', 'refreshed']);

const EMPTY = {
  total_products: 0, processed: 0, synced_products: 0, failed_products: 0, percentage: 0, created: 0, updated: 0,
};

const syncMode = ref(null);
const syncing = ref(false);
const resetting = ref(false);
const fixingCategories = ref(false);
const autoLinking = ref(false);
const loadingUnmapped = ref(false);
const stopping = ref(false);
const pendingCancel = ref(false);
const syncOnlyUnsynced = ref(false);
const token = ref('');
const syncJobId = ref(null);
const syncStatus = ref(null);
const progress = ref({ ...EMPTY });
const totalProducts = ref(null);
const unsyncedCount = ref(null);
const statsLoading = ref(true);
const pullStats = ref({ total_woo: null, imported: null, not_imported: null });
const unmappedModal = ref(false);
const unmappedReport = ref(null);

let poller = null;
let fastPoller = null;
let fastPollsRemaining = 0;
let refreshing = false;
let lastFetchStartedAt = 0;
let lastProgressSignature = '';
let lastProgressChangeAt = 0;

const syncedDisplay = computed(() => {
  if (totalProducts.value == null || unsyncedCount.value == null) return '—';
  return Math.max(0, (totalProducts.value || 0) - (unsyncedCount.value || 0));
});
const unsyncedAvailable = computed(() => unsyncedCount.value != null && unsyncedCount.value > 0);

const displayTotal = computed(() => Number(progress.value?.total_products ?? 0) || 0);
const displayProcessed = computed(() => Number(progress.value?.processed ?? progress.value?.synced_products ?? 0) || 0);
const displayPercentage = computed(() => {
  const direct = Number(progress.value?.percentage);
  if (Number.isFinite(direct) && direct > 0) return Math.max(0, Math.min(100, direct));
  if (!displayTotal.value) return 0;
  return Math.max(0, Math.min(100, Math.floor((displayProcessed.value / displayTotal.value) * 100)));
});
const showStopSync = computed(() => {
  const st = String(syncStatus.value || '').toLowerCase();
  if (syncing.value && !progress.value?.finished) return true;
  if (syncJobId.value) {
    if (st === 'running' || st === 'cancelling') return true;
    return !st && syncing.value && !progress.value?.finished;
  }
  return !!token.value && syncing.value && !progress.value?.finished;
});
const isSyncActive = computed(() => !!syncJobId.value || syncing.value);

const failureColumns = [
  { title: 'Woo Product ID', dataIndex: 'woo_product_id', key: 'woo_product_id' },
  { title: 'Woo Variation ID', dataIndex: 'woo_variation_id', key: 'woo_variation_id' },
  { title: 'SKU', dataIndex: 'sku', key: 'sku' },
  { title: 'Failures', dataIndex: 'count', key: 'count' },
  { title: 'Last Order', dataIndex: 'last_order_id', key: 'last_order_id' },
  { title: 'Last Seen', dataIndex: 'last_seen_at', key: 'last_seen_at' },
];
const unlinkedProductColumns = [
  { title: 'ID', dataIndex: 'id', key: 'id', width: 80 },
  { title: 'Name', dataIndex: 'name', key: 'name' },
  { title: 'SKU / Code', dataIndex: 'code', key: 'code' },
];
const unlinkedVariantColumns = [
  { title: 'ID', dataIndex: 'id', key: 'id', width: 80 },
  { title: 'Product ID', dataIndex: 'product_id', key: 'product_id', width: 100 },
  { title: 'Variant', dataIndex: 'name', key: 'name' },
  { title: 'SKU / Code', dataIndex: 'code', key: 'code' },
];

watch(syncOnlyUnsynced, (val) => {
  try { localStorage.setItem('woo_products_push_only_unsynced', val ? '1' : '0'); } catch (e) { /* ignore */ }
});

function storageKey() {
  return syncMode.value === 'pull' ? 'woo_products_pull_job_id' : 'woo_products_push_job_id';
}

function normalizeProgressState(state) {
  const s = state || {};
  const total = Number(s.total_products ?? s.total ?? 0);
  const processed = Number(s.processed ?? s.synced_products ?? s.synced ?? 0);
  let percentage = Number(s.percentage ?? s.percent);
  if (!Number.isFinite(percentage)) percentage = total > 0 ? Math.floor((processed / total) * 100) : 0;
  return {
    ...s,
    total_products: Number.isFinite(total) ? total : 0,
    processed: Number.isFinite(processed) ? processed : 0,
    percentage: Math.max(0, Math.min(100, Number.isFinite(percentage) ? percentage : 0)),
  };
}

async function load() {
  const p1 = http.get('products', { limit: 1 })
    .then(data => { totalProducts.value = data.totalRows ?? null; })
    .catch(() => { totalProducts.value = null; });
  const p2 = http.get('woocommerce/unsynced-count')
    .then(data => {
      unsyncedCount.value = data.count;
      if (!(unsyncedCount.value != null && unsyncedCount.value > 0)) syncOnlyUnsynced.value = false;
    })
    .catch(() => { unsyncedCount.value = null; });
  const p3 = http.get('woocommerce/products/pull-stats')
    .then(data => {
      pullStats.value = {
        total_woo: data.total_woo ?? null,
        imported: data.imported ?? 0,
        not_imported: data.not_imported ?? null,
      };
    })
    .catch(() => { pullStats.value = { total_woo: null, imported: null, not_imported: null }; });
  await Promise.all([p1, p2, p3]);
}

function stopPollers() {
  if (poller) { clearInterval(poller); poller = null; }
  if (fastPoller) { clearInterval(fastPoller); fastPoller = null; }
}

function finishRun() {
  stopPollers();
  syncing.value = false;
  token.value = '';
  syncJobId.value = null;
  syncStatus.value = null;
  stopping.value = false;
  pendingCancel.value = false;
  try { localStorage.removeItem(storageKey()); } catch (e) { /* ignore */ }
  syncMode.value = null;
  progress.value = { ...EMPTY };
}

function startPolling(immediate = true) {
  stopPollers();
  lastProgressSignature = '';
  lastProgressChangeAt = Date.now();
  fastPollsRemaining = 8;
  fastPoller = setInterval(() => {
    if (fastPollsRemaining <= 0) { clearInterval(fastPoller); fastPoller = null; return; }
    fastPollsRemaining -= 1;
    fetchProgress();
  }, 4000);
  poller = setInterval(fetchProgress, 10000);
  if (immediate) fetchProgress();
}

async function fetchProgress() {
  if (refreshing) {
    if (lastFetchStartedAt && (Date.now() - lastFetchStartedAt) < 30000) return;
    refreshing = false;
  }
  refreshing = true;
  lastFetchStartedAt = Date.now();

  // Job-based run.
  if (syncJobId.value) {
    if (!syncMode.value) syncMode.value = 'push';
    try {
      const st = await http.get(`woo-sync/status/${syncJobId.value}`) || {};
      syncStatus.value = st.status || null;
      progress.value = normalizeProgressState({
        total_products: st.total_items || 0,
        processed: st.processed_items || 0,
        failed_products: st.failed_items || 0,
        synced_products: st.success_items || 0,
        percentage: st.percentage || 0,
        stage: st.stage || null,
        current_sku: st.current_sku || null,
        finished: ['completed', 'failed', 'cancelled'].includes(String(st.status || '').toLowerCase()),
        error: st.last_error || null,
      });
      const status = String(st.status || '').toLowerCase();
      if (['completed', 'failed', 'cancelled'].includes(status)) {
        finishRun();
        if (status === 'completed') message.success(t('Sync_Completed'));
        else if (status === 'cancelled') message.warning(t('Cancelled') || 'Cancelled');
        else message.error(t('Sync_Failed'));
        load();
        emit('refreshed');
      }
    } catch (e) { /* keep polling */ }
    refreshing = false;
    return;
  }

  // Token-based run.
  if (!token.value) { refreshing = false; return; }
  try {
    const data = await http.get('woocommerce/sync/products/progress', { token: token.value });
    if (data && data.state) {
      progress.value = normalizeProgressState(data.state);
      const signature = JSON.stringify({
        finished: !!progress.value.finished,
        percentage: displayPercentage.value,
        processed: displayProcessed.value,
        total: displayTotal.value,
        stage: progress.value.stage || null,
        sku: progress.value.current_sku || null,
        err: progress.value.error || null,
      });
      if (signature !== lastProgressSignature) {
        lastProgressSignature = signature;
        lastProgressChangeAt = Date.now();
      } else if (Date.now() - lastProgressChangeAt > 60000) {
        // Stalled for 60s — legacy treats this as a failed run.
        finishRun();
        message.error(t('Sync_Failed'));
        refreshing = false;
        return;
      }
      if (progress.value.finished) {
        const hadError = !!progress.value.error;
        finishRun();
        if (hadError) message.error(t('Sync_Failed'));
        else message.success(t('Sync_Completed'));
        load();
        emit('refreshed');
      }
    } else {
      finishRun();
    }
  } catch (e) {
    finishRun();
  } finally {
    refreshing = false;
  }
}

async function manualSync(mode, onlyUnsynced) {
  if (syncing.value) return;
  syncMode.value = mode === 'pull' ? 'pull' : 'push';
  syncing.value = true;
  stopping.value = false;
  pendingCancel.value = false;
  syncStatus.value = 'starting';
  progress.value = { ...EMPTY };
  startPolling(true);
  let url = `woocommerce/sync/products?mode=${syncMode.value}`;
  if (onlyUnsynced) url += '&only_unsynced=1';
  try {
    const data = await http.post(url);
    const jobId = data ? (data.sync_job_id ?? data.syncJobId ?? null) : null;
    if (data && data.ok && (jobId || data.token)) {
      token.value = data.token || '';
      syncJobId.value = jobId || null;
      syncStatus.value = 'running';
      if (syncJobId.value) {
        try { localStorage.setItem(storageKey(), String(syncJobId.value)); } catch (e) { /* ignore */ }
      }
      fetchProgress();
      if (pendingCancel.value) stopSync();
    } else {
      message.error(t('Sync_Failed'));
      finishRun();
    }
  } catch (e) {
    message.error(t('Sync_Failed'));
    finishRun();
  }
}

function stopSync() {
  if (stopping.value && !pendingCancel.value) return;
  stopping.value = true;
  syncStatus.value = 'cancelling';
  if (syncJobId.value) {
    http.post(`woo-sync/${syncJobId.value}/cancel`).catch(() => {});
    pendingCancel.value = false;
  } else if (token.value) {
    http.post('woocommerce/sync/products/stop', { token: token.value }).catch(() => {});
    pendingCancel.value = false;
  } else {
    pendingCancel.value = true;
  }
}

function restoreRunningJob() {
  try {
    const pullId = Number(localStorage.getItem('woo_products_pull_job_id')) || null;
    const pushId = Number(localStorage.getItem('woo_products_push_job_id')) || null;
    if (pullId && pullId > 0) {
      syncMode.value = 'pull';
      syncJobId.value = pullId;
    } else if (pushId && pushId > 0) {
      syncMode.value = 'push';
      syncJobId.value = pushId;
    }
    if (syncJobId.value) {
      syncing.value = true;
      syncStatus.value = 'running';
      startPolling();
    }
  } catch (e) { /* storage unavailable */ }
}

async function resetSync() {
  if (resetting.value) return;
  resetting.value = true;
  try {
    await http.post('woocommerce/reset-products-sync');
    message.success(t('Successfully_Updated'));
    load();
    emit('refreshed');
  } catch (e) {
    message.error(t('Sync_Failed'));
  } finally {
    resetting.value = false;
  }
}

async function fixProductCategories() {
  if (fixingCategories.value) return;
  fixingCategories.value = true;
  try {
    const data = await http.post('woocommerce/products/fix-categories');
    if (data && data.ok) {
      const b = data.skipped_breakdown || {};
      const extra = (b.missing_category_mapping != null || b.already_categorized != null)
        ? ` (no mapping: ${b.missing_category_mapping || 0}, already ok: ${b.already_categorized || 0})`
        : '';
      message.success(`Fixed: ${data.fixed || 0} · Skipped: ${data.skipped || 0}${extra} · Errors: ${data.errors || 0}`);
    } else {
      message.error(`Fix failed: ${data?.error || 'Unknown error'}`);
    }
  } catch (e) {
    message.error(`Fix failed: ${e.message || 'Network error'}`);
  } finally {
    fixingCategories.value = false;
  }
}

async function autoLinkBySku() {
  if (autoLinking.value) return;
  autoLinking.value = true;
  try {
    const data = await http.post('woocommerce/products/auto-link');
    if (data && data.ok) {
      message.success(`Linked products: ${data.linked_products || 0} · Linked variants: ${data.linked_variants || 0}`);
      load();
      emit('refreshed');
    } else {
      message.error(`Auto-link failed: ${data?.error || 'Unknown error'}`);
    }
  } catch (e) {
    message.error(`Auto-link failed: ${e.message || 'Network error'}`);
  } finally {
    autoLinking.value = false;
  }
}

async function loadUnmapped() {
  if (loadingUnmapped.value) return;
  loadingUnmapped.value = true;
  try {
    const data = await http.get('woocommerce/products/unmapped-report', { limit: 100 });
    if (data && data.ok) {
      unmappedReport.value = data;
      unmappedModal.value = true;
    } else {
      message.error(`Failed to load report: ${data?.error || 'Unknown error'}`);
    }
  } catch (e) {
    message.error(`Failed to load report: ${e.message || 'Network error'}`);
  } finally {
    loadingUnmapped.value = false;
  }
}

onMounted(async () => {
  try {
    const pref = localStorage.getItem('woo_products_push_only_unsynced');
    if (pref === '1' || pref === 'true') syncOnlyUnsynced.value = true;
  } catch (e) { /* ignore */ }
  restoreRunningJob();
  await load();
  statsLoading.value = false;
  emit('ready');
});
onBeforeUnmount(stopPollers);
</script>
