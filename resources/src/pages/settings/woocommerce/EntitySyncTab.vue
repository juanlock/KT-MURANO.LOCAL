<template>
  <div>
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="statsLoading"><a-statistic :title="$t('Total')" :value="total ?? '—'" /></a-spin></a-card>
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

    <a-space wrap>
      <a-button type="primary" :loading="syncing" @click="manualSync('push', false)">
        <UploadOutlined v-if="!syncing" /> {{ $t('Sync') }} — POS → WooCommerce
      </a-button>
      <a-button :loading="syncing" :disabled="!unsyncedAvailable" @click="manualSync('push', true)">
        {{ $t('Not_Synced') }} ({{ unsyncedCount ?? 0 }})
      </a-button>
      <a-button :loading="syncing" @click="manualSync('pull', false)">
        <DownloadOutlined v-if="!syncing" /> {{ $t('Sync') }} — WooCommerce → POS
      </a-button>
      <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetSync">
        <a-button danger :loading="resetting">{{ $t('Reset') }}</a-button>
      </a-popconfirm>
    </a-space>
  </div>
</template>

<script setup>
/**
 * Shared push/pull sync tab for WooCommerce categories and brands (legacy
 * CategoriesTab/BrandsTab were twins). Contracts per entity:
 * GET {listEndpoint}?limit=1 → totalRows; GET woocommerce/{entity}/
 * unsynced-count → {count}; GET woocommerce/{entity}/pull-stats →
 * {total_woo, imported, not_imported}; POST woocommerce/sync/{entity}
 * ?mode=push|pull[&only_unsynced=1]; POST woocommerce/reset-{entity}-sync.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { UploadOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import http from '../../../lib/http';

const { t } = useI18n();
const props = defineProps({
  // 'categories' | 'brands'
  entity: { type: String, required: true },
  listEndpoint: { type: String, required: true },
});
const emit = defineEmits(['ready', 'refreshed']);

const syncing = ref(false);
const resetting = ref(false);
const total = ref(null);
const unsyncedCount = ref(null);
const statsLoading = ref(true);
const pullStats = ref({ total_woo: null, imported: null, not_imported: null });

const syncedDisplay = computed(() => {
  if (total.value == null || unsyncedCount.value == null) return '—';
  return Math.max(0, (total.value || 0) - (unsyncedCount.value || 0));
});
const unsyncedAvailable = computed(() => unsyncedCount.value != null && unsyncedCount.value > 0);

async function load() {
  const p1 = http.get(props.listEndpoint, { limit: 1, SortField: 'id', SortType: 'desc' })
    .then(data => { total.value = data.totalRows ?? null; })
    .catch(() => { total.value = null; });
  const p2 = http.get(`woocommerce/${props.entity}/unsynced-count`)
    .then(data => { unsyncedCount.value = data.count; })
    .catch(() => { unsyncedCount.value = null; });
  const p3 = http.get(`woocommerce/${props.entity}/pull-stats`)
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

async function manualSync(mode, onlyUnsynced) {
  syncing.value = true;
  const m = mode === 'pull' ? 'pull' : 'push';
  let url = `woocommerce/sync/${props.entity}?mode=${m}`;
  if (m === 'push' && onlyUnsynced) url += '&only_unsynced=1';
  try {
    const data = await http.post(url);
    if (data.ok) message.success(t('Sync_Completed'));
    else message.error(t('Sync_Failed'));
  } catch (e) {
    message.error(t('Sync_Failed'));
  } finally {
    syncing.value = false;
    load();
    emit('refreshed');
  }
}

async function resetSync() {
  if (resetting.value) return;
  resetting.value = true;
  try {
    await http.post(`woocommerce/reset-${props.entity}-sync`);
    message.success(t('Successfully_Updated'));
    load();
    emit('refreshed');
  } catch (e) {
    message.error(t('Sync_Failed'));
  } finally {
    resetting.value = false;
  }
}

onMounted(async () => {
  await load();
  statsLoading.value = false;
  emit('ready');
});
</script>
