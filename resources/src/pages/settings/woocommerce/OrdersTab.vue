<template>
  <div>
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="loadingTab"><a-statistic :title="'WooCommerce ' + $t('Orders')" :value="wooTotalRows" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="loadingTab"><a-statistic :title="'Imported'" :value="importedStats.total_imported ?? '—'" :value-style="{ color: '#52c41a' }" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-spin :spinning="loadingTab"><a-statistic :title="$t('Today') || 'Imported today'" :value="importedStats.imported_today ?? '—'" /></a-spin></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small" style="height: 100%; display: flex; align-items: center">
          <a-button type="primary" block :loading="syncing" @click="syncOrders">
            <SyncOutlined v-if="!syncing" /> {{ $t('Sync') }} {{ $t('Orders') }}
          </a-button>
        </a-card>
      </a-col>
    </a-row>

    <a-alert
      v-if="lastResult && !lastResult.ok" type="error" show-icon style="margin-bottom: 12px"
      :message="'Orders sync failed: ' + (lastResult.error || 'Unknown error')"
    />

    <a-input-search
      v-model:value="wooSearch" :placeholder="$t('Search')" style="max-width: 280px; margin-bottom: 12px"
      @search="() => { page = 1; loadWooOrders(); }"
    />

    <a-table
      :columns="columns" :data-source="wooOrders" :loading="loadingTab"
      size="small" row-key="id" :scroll="{ x: 'max-content' }"
      :pagination="{
        current: page, total: wooTotalRows, pageSize: perPage, showSizeChanger: true,
        onChange: (p, ps) => { page = p; perPage = ps; loadWooOrders(); },
      }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'status'">
          <a-tag>{{ record.status }}</a-tag>
        </template>
        <template v-else-if="column.key === 'sync_status'">
          <a-tag :color="record.imported ? 'success' : 'default'">
            {{ record.imported ? $t('Synced') : $t('Not_Synced') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button
            size="small" :loading="syncingOrderId === record.id"
            :disabled="!!record.imported" @click="syncWooOrder(record)"
          >
            {{ $t('Sync') }}
          </a-button>
        </template>
      </template>
    </a-table>
  </div>
</template>

<script setup>
/**
 * WooCommerce orders — GET woocommerce/orders?page&per_page&search →
 * {ok, orders, totalRows}; GET woocommerce/orders/imported/stats →
 * {total_imported, imported_today}; POST woocommerce/sync/orders (all) or
 * ?order_id={id} (single) → {ok, error?}. Sync errors surface the server's
 * `error` string verbatim, like legacy.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SyncOutlined } from '@ant-design/icons-vue';
import http from '../../../lib/http';

const { t } = useI18n();
const emit = defineEmits(['ready', 'refreshed']);

const syncing = ref(false);
const syncingOrderId = ref(null);
const lastResult = ref(null);
const loadingTab = ref(true);

const wooOrders = ref([]);
const wooTotalRows = ref(0);
const wooSearch = ref('');
const page = ref(1);
const perPage = ref(10);

const importedStats = ref({ total_imported: null, imported_today: null });

const columns = computed(() => [
  { title: 'ID', dataIndex: 'id', key: 'id', width: 80 },
  { title: 'Number', dataIndex: 'number', key: 'number', width: 100 },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('date'), dataIndex: 'date_created', key: 'date_created', width: 150 },
  { title: t('Total'), dataIndex: 'total', key: 'total', align: 'right', width: 100 },
  { title: t('Customer'), dataIndex: 'customer_display', key: 'customer_display' },
  { title: t('Email'), dataIndex: 'billing_email', key: 'billing_email' },
  { title: t('Items') || 'Items', dataIndex: 'items_count', key: 'items_count', align: 'right', width: 80 },
  { title: t('Sync'), key: 'sync_status', width: 110, align: 'center' },
  { title: t('Actions'), key: 'actions', width: 90, align: 'center' },
]);

async function loadImportedStats() {
  try {
    const data = await http.get('woocommerce/orders/imported/stats');
    importedStats.value = {
      total_imported: data.total_imported ?? 0,
      imported_today: data.imported_today ?? 0,
    };
  } catch (e) {
    importedStats.value = { total_imported: null, imported_today: null };
  }
}

async function loadWooOrders() {
  try {
    const data = await http.get('woocommerce/orders', {
      page: page.value, per_page: perPage.value, search: wooSearch.value,
    });
    if (data.ok) {
      wooOrders.value = data.orders || [];
      wooTotalRows.value = data.totalRows || 0;
    } else {
      wooOrders.value = [];
      wooTotalRows.value = 0;
    }
  } catch (e) {
    wooOrders.value = [];
    wooTotalRows.value = 0;
  }
}

async function load() {
  loadingTab.value = true;
  await Promise.all([loadImportedStats(), loadWooOrders()]);
  loadingTab.value = false;
}

async function syncWooOrder(order) {
  const orderId = parseInt(order.id, 10) || 0;
  if (orderId <= 0 || syncingOrderId.value) return;
  syncingOrderId.value = orderId;
  try {
    const data = await http.post(`woocommerce/sync/orders?order_id=${orderId}`);
    if (data && data.ok) message.success('Order synced');
    else message.error(`Order sync failed: ${data?.error || 'Unknown error'}`);
  } catch (e) {
    message.error(`Order sync failed: ${e.message || 'Network error'}`);
  } finally {
    syncingOrderId.value = null;
    load();
  }
}

async function syncOrders() {
  if (syncing.value) return;
  syncing.value = true;
  lastResult.value = null;
  try {
    const data = await http.post('woocommerce/sync/orders');
    lastResult.value = data;
    if (data && data.ok) message.success('Orders sync completed');
    else message.error(`Orders sync failed: ${data?.error || 'Unknown error'}`);
  } catch (e) {
    lastResult.value = { ok: false, error: e.message || 'Network error' };
    message.error(`Orders sync failed: ${e.message || 'Network error'}`);
  } finally {
    syncing.value = false;
    load();
    emit('refreshed');
  }
}

onMounted(async () => {
  await load();
  emit('ready');
});
</script>
