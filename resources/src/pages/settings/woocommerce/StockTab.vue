<template>
  <div>
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="8">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="'In stock'" :value="metrics.in_stock" :value-style="{ color: '#52c41a' }" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="8">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="'Out of stock'" :value="metrics.out_stock" :value-style="{ color: '#ff4d4f' }" /></a-spin></a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small">
          <a-spin :spinning="statsLoading">
            <a-statistic :title="'Last sync'" :value="metrics.last_sync ? formatLogDate(metrics.last_sync) : '—'" :value-style="{ fontSize: '16px' }" />
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" v-if="syncing" style="margin-bottom: 16px">
      <a-progress :percent="displayPercentage" :status="'active'" />
      <div style="font-size: 12px; color: #8c8c8c; margin-top: 8px">
        {{ displayProcessed }} / {{ displayTotal }} ·
        {{ $t('Synced') }}: {{ progress.synced_products || 0 }} ·
        {{ $t('Failed') }}: {{ progress.failed_products || 0 }}
      </div>
    </a-card>

    <a-space wrap>
      <a-button type="primary" :loading="syncing" @click="syncStock">
        <SyncOutlined v-if="!syncing" /> {{ $t('Sync') }} {{ $t('Stock') }}
      </a-button>
      <a-button v-if="syncing" danger :loading="stopping" @click="stopStock">{{ $t('Pause') }}</a-button>
      <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetSync">
        <a-button danger :loading="resetting">{{ $t('Reset') }}</a-button>
      </a-popconfirm>
      <a-button @click="$emit('view-logs')">Logs</a-button>
    </a-space>
  </div>
</template>

<script setup>
/**
 * WooCommerce stock sync — background job with 5s progress polling:
 * GET woocommerce/stock-metrics → {in_stock, out_stock, last_sync};
 * POST woocommerce/sync/stock → {ok, token}; GET
 * woocommerce/sync/stock/progress?token → {state: {total_products,
 * processed, synced_products, failed_products, percentage, finished,
 * error?}}; POST woocommerce/sync/stock/stop {token}; POST
 * woocommerce/reset-stock-sync. Percentage falls back to
 * processed/total when the server doesn't compute it (legacy logic).
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SyncOutlined } from '@ant-design/icons-vue';
import http from '../../../lib/http';
import { formatLogDate } from './wooLog';

const { t } = useI18n();
const emit = defineEmits(['ready', 'refreshed', 'view-logs']);

const syncing = ref(false);
const stopping = ref(false);
const refreshing = ref(false);
const resetting = ref(false);
const token = ref('');
let poller = null;

const EMPTY = { total_products: 0, processed: 0, synced_products: 0, failed_products: 0, percentage: 0 };
const progress = ref({ ...EMPTY });
const metrics = ref({ in_stock: 0, out_stock: 0, last_sync: null });
const statsLoading = ref(true);

const displayTotal = computed(() => {
  const v = Number(progress.value?.total_products ?? 0);
  return Number.isFinite(v) ? v : 0;
});
const displayProcessed = computed(() => {
  const direct = Number(progress.value?.processed);
  if (Number.isFinite(direct) && direct > 0) return direct;
  const s = Number(progress.value?.synced_products ?? 0);
  const f = Number(progress.value?.failed_products ?? 0);
  return (Number.isFinite(s) ? s : 0) + (Number.isFinite(f) ? f : 0);
});
const displayPercentage = computed(() => {
  const direct = Number(progress.value?.percentage);
  if (Number.isFinite(direct) && direct > 0) return Math.max(0, Math.min(100, direct));
  if (!displayTotal.value) return 0;
  return Math.max(0, Math.min(100, Math.floor((displayProcessed.value / displayTotal.value) * 100)));
});

async function fetchMetrics() {
  try {
    metrics.value = await http.get('woocommerce/stock-metrics') || { in_stock: 0, out_stock: 0, last_sync: null };
  } catch (e) {
    metrics.value = { in_stock: 0, out_stock: 0, last_sync: null };
  }
}

function stopPolling() {
  if (poller) { clearInterval(poller); poller = null; }
}

function endRun() {
  stopPolling();
  token.value = '';
  syncing.value = false;
  progress.value = { ...EMPTY };
}

async function fetchProgress() {
  if (refreshing.value) return;
  if (!token.value) { endRun(); return; }
  refreshing.value = true;
  try {
    const data = await http.get('woocommerce/sync/stock/progress', { token: token.value });
    if (data && data.state) {
      progress.value = data.state;
      if (data.state.finished) {
        const hadError = !!data.state.error;
        endRun();
        if (hadError) message.error(t('Sync_Failed'));
        else message.success(t('Sync_Completed'));
        fetchMetrics();
        emit('refreshed');
      }
    } else {
      endRun();
    }
  } catch (e) {
    endRun();
  } finally {
    refreshing.value = false;
  }
}

async function syncStock() {
  if (syncing.value) return;
  syncing.value = true;
  stopping.value = false;
  progress.value = { ...EMPTY };
  try {
    const data = await http.post('woocommerce/sync/stock');
    if (data.ok && data.token) {
      token.value = data.token;
      stopPolling();
      poller = setInterval(fetchProgress, 5000);
      fetchProgress();
    } else {
      message.error(t('Sync_Failed'));
      syncing.value = false;
    }
  } catch (e) {
    message.error(t('Sync_Failed'));
    syncing.value = false;
  }
}

async function stopStock() {
  if (!token.value || stopping.value) return;
  stopping.value = true;
  try {
    await http.post('woocommerce/sync/stock/stop', { token: token.value });
  } catch (e) { /* the poller will observe the final state */ }
  stopping.value = false;
}

async function resetSync() {
  if (resetting.value) return;
  resetting.value = true;
  try {
    await http.post('woocommerce/reset-stock-sync');
    message.success(t('Successfully_Updated'));
    fetchMetrics();
    emit('refreshed');
  } catch (e) {
    message.error(t('Sync_Failed'));
  } finally {
    resetting.value = false;
  }
}

onMounted(async () => {
  await fetchMetrics();
  statsLoading.value = false;
  emit('ready');
});
onBeforeUnmount(stopPolling);
</script>
