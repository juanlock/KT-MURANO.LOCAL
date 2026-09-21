<template>
  <div>
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <div class="wc-title">{{ $t('Connection') }}</div>
          <div
            class="wc-value"
            :style="{ fontSize: '18px', color: connectionOk === true ? '#52c41a' : connectionOk === false ? '#ff4d4f' : '#8c8c8c' }"
          >
            <a-spin v-if="loading" size="small" />
            <template v-else>
              {{ connectionOk === true ? $t('Connected') : connectionOk === false ? $t('Disconnected') : $t('Unknown') }}
            </template>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <div class="wc-title">{{ $t('Products') }}</div>
          <div class="wc-value">
            <a-spin v-if="countsLoading" size="small" />
            <template v-else>{{ totalProducts ?? '—' }}</template>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <div class="wc-title">{{ $t('Synced') }}</div>
          <div class="wc-value" style="color: #52c41a">
            <a-spin v-if="countsLoading" size="small" />
            <template v-else>{{ syncedDisplay }}</template>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <div class="wc-title">{{ $t('Not_Synced') }}</div>
          <div class="wc-value" style="color: #faad14">
            <a-spin v-if="countsLoading" size="small" />
            <template v-else>{{ unsyncedCount ?? '—' }}</template>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" :title="'Recent activity'">
      <a-table
        :columns="columns" :data-source="lastFiveLogs" :loading="loading"
        size="small" :pagination="false" :row-key="(_r, i) => i"
        :locale="{ emptyText: $t('NodataAvailable') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'date'">{{ formatLogDate(record.created_at) }}</template>
          <template v-else-if="column.key === 'action'">{{ formatAction(record.action, $t) }}</template>
          <template v-else-if="column.key === 'direction'">{{ formatDirection(record.action, $t) }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="levelColor(record.level)">{{ formatStatus(record.level, $t) }}</a-tag>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * WooCommerce status overview — connection probe (POST test-connection) +
 * last five log entries (GET woocommerce/logs, client-sorted desc).
 * Product counts come from the hub via props (GET products?limit=1
 * totalRows and woocommerce/unsynced-count).
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import http from '../../../lib/http';
import {
  formatLogDate, formatAction, formatDirection, formatStatus, levelColor,
} from './wooLog';

const { t } = useI18n();
const props = defineProps({
  totalProducts: { type: Number, default: null },
  unsyncedCount: { type: Number, default: null },
  countsLoading: { type: Boolean, default: false },
});
const emit = defineEmits(['ready']);

const connectionOk = ref(null);
const logs = ref([]);
const loading = ref(true);

const syncedDisplay = computed(() => {
  if (props.totalProducts == null || props.unsyncedCount == null) return '—';
  return Math.max(0, (props.totalProducts || 0) - (props.unsyncedCount || 0));
});

const lastFiveLogs = computed(() => {
  const list = logs.value.slice();
  list.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  return list.slice(0, 5);
});

const columns = computed(() => [
  { title: t('date'), key: 'date', width: 150 },
  { title: t('Action'), key: 'action', width: 110 },
  { title: t('Direction'), key: 'direction', width: 180 },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('Message'), dataIndex: 'message', key: 'message', ellipsis: true },
]);

onMounted(async () => {
  try {
    const [conn, logData] = await Promise.all([
      http.post('woocommerce/test-connection').catch(() => ({ ok: false })),
      http.get('woocommerce/logs').catch(() => ({ data: [] })),
    ]);
    connectionOk.value = !!conn.ok;
    logs.value = logData.data || [];
  } finally {
    loading.value = false;
    emit('ready');
  }
});
</script>

<style scoped>
/* Mirrors the a-statistic look so the inline spinner can replace the value. */
.wc-title {
  color: rgba(0, 0, 0, 0.45);
  font-size: 14px;
  margin-bottom: 4px;
}
.wc-value {
  font-size: 24px;
  line-height: 32px;
}
</style>
