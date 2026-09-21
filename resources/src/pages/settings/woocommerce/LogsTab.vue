<template>
  <div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px">
      <a-select
        v-model:value="filterAction" style="width: 140px"
        :options="[
          { label: $t('All'), value: 'all' },
          { label: $t('Product'), value: 'products' },
          { label: $t('Stock'), value: 'stock' },
          { label: $t('Order'), value: 'orders' },
        ]"
      />
      <a-select
        v-model:value="filterStatus" style="width: 140px"
        :options="[
          { label: $t('All'), value: 'all' },
          { label: $t('Success'), value: 'info' },
          { label: $t('Warning'), value: 'warning' },
          { label: $t('Failed'), value: 'error' },
        ]"
      />
      <a-date-picker v-model:value="filterFrom" value-format="YYYY-MM-DD" :placeholder="$t('From')" />
      <a-date-picker v-model:value="filterTo" value-format="YYYY-MM-DD" :placeholder="$t('To')" />
      <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
        <a-button danger :loading="processing" style="margin-left: auto">
          <DeleteOutlined /> {{ $t('Clear') || $t('Reset') }}
        </a-button>
      </a-popconfirm>
    </div>

    <a-table
      :columns="columns" :data-source="filteredLogs" :loading="loading"
      size="small" :row-key="(_r, i) => i"
      :pagination="{ pageSize: 10, showSizeChanger: false }"
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
  </div>
</template>

<script setup>
/**
 * WooCommerce sync logs — GET woocommerce/logs → {data}; DELETE
 * woocommerce/logs clears. Filters are CLIENT-SIDE like legacy: action
 * prefix (products/stock/orders), level, from/to date range; sorted desc.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined } from '@ant-design/icons-vue';
import http from '../../../lib/http';
import {
  formatLogDate, formatAction, formatDirection, formatStatus, levelColor,
} from './wooLog';

const { t } = useI18n();
const emit = defineEmits(['ready']);

const loading = ref(true);
const processing = ref(false);
const logs = ref([]);
const filterAction = ref('all');
const filterStatus = ref('all');
const filterFrom = ref(null);
const filterTo = ref(null);

const filteredLogs = computed(() => {
  let out = logs.value.slice();
  if (filterAction.value !== 'all') out = out.filter(l => (l.action || '').startsWith(filterAction.value));
  if (filterStatus.value !== 'all') out = out.filter(l => (l.level || '') === filterStatus.value);
  if (filterFrom.value) {
    const from = new Date(filterFrom.value + 'T00:00:00');
    out = out.filter(l => new Date(l.created_at) >= from);
  }
  if (filterTo.value) {
    const to = new Date(filterTo.value + 'T23:59:59');
    out = out.filter(l => new Date(l.created_at) <= to);
  }
  out.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  return out;
});

const columns = computed(() => [
  { title: t('date'), key: 'date', width: 150 },
  { title: t('Action'), key: 'action', width: 110 },
  { title: t('Direction'), key: 'direction', width: 180 },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('Message'), dataIndex: 'message', key: 'message', ellipsis: true },
]);

async function load() {
  try {
    const data = await http.get('woocommerce/logs');
    logs.value = data.data || [];
  } catch (e) {
    logs.value = [];
  }
}

async function clearLogs() {
  processing.value = true;
  try {
    await http.delete('woocommerce/logs');
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(t('Not_Available'));
  } finally {
    processing.value = false;
  }
}

onMounted(async () => {
  await load();
  loading.value = false;
  emit('ready');
});
</script>
