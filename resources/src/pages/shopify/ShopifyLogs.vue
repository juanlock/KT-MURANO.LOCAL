<template>
  <div class="page">
    <PageHeader
      title="Shopify Logs"
      subtitle="Every write, warning and failure, with the payload that caused it."
      :breadcrumb="['Shopify', 'Logs']"
    >
      <template #actions>
        <a-button danger :loading="clearing" @click="confirmClear">
          <template #icon><DeleteOutlined /></template>
          Clear old logs
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search message or action…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.store_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All stores"
          :options="storeOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.level" class="tb-item" allow-clear
          placeholder="All levels" :options="LOG_LEVELS" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.entity" class="tb-item" allow-clear
          placeholder="All entities" :options="entityOptions" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-tag v-if="filters.run_id" closable color="purple" class="tb-run" @close="clearRunFilter">
          Run #{{ filters.run_id }}
        </a-tag>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'level'">
          <a-tag :color="optionOf(LOG_LEVELS, record.level).color">
            {{ labelOf(LOG_LEVELS, record.level) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'store_id'">
          {{ storeName(record.store_id) }}
        </template>
        <template v-else-if="column.key === 'action'">
          <code class="code">{{ record.action }}</code>
        </template>
        <template v-else-if="column.key === 'message'">
          <div class="msg" :class="{ 'msg--bad': record.level === 'error' }">{{ record.message }}</div>
        </template>
        <template v-else-if="column.key === 'created_at'">
          {{ dateTime(record.created_at) }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button
            v-if="record.context && Object.keys(record.context).length"
            type="link" size="small" @click="inspect(record)"
          >
            Details
          </a-button>
        </template>
      </template>
    </DataTable>

    <a-drawer
      :open="drawerOpen" title="Log entry" placement="right" :width="520"
      @close="drawerOpen = false"
    >
      <template v-if="selected">
        <a-descriptions bordered size="small" :column="1">
          <a-descriptions-item label="Level">
            <a-tag :color="optionOf(LOG_LEVELS, selected.level).color">
              {{ labelOf(LOG_LEVELS, selected.level) }}
            </a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="Action">{{ selected.action }}</a-descriptions-item>
          <a-descriptions-item label="Entity">{{ selected.entity || '—' }}</a-descriptions-item>
          <a-descriptions-item label="Store">{{ storeName(selected.store_id) }}</a-descriptions-item>
          <a-descriptions-item label="Run">
            <a v-if="selected.run_id" class="link" @click="filterByRun(selected.run_id)">#{{ selected.run_id }}</a>
            <span v-else>—</span>
          </a-descriptions-item>
          <a-descriptions-item label="When">{{ dateTime(selected.created_at) }}</a-descriptions-item>
        </a-descriptions>

        <p class="drawer-msg">{{ selected.message }}</p>

        <a-typography-title :level="5">Context</a-typography-title>
        <pre class="context">{{ JSON.stringify(selected.context, null, 2) }}</pre>
      </template>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * The audit trail.
 *
 * The context payload lives behind a drawer rather than in the table: it is what
 * you need when diagnosing one failure, and noise on every other row. Clearing
 * defaults to logs older than a week so a stray click cannot wipe the evidence
 * of the failure someone is currently debugging.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { LOG_LEVELS, ENTITIES, labelOf, optionOf } from './shopifyOptions';
import http from '../../lib/http';

const route = useRoute();
const { dateTime } = useFormat();

const filters = reactive({
  store_id: route.query.store_id ? Number(route.query.store_id) : undefined,
  level: route.query.level || undefined,
  entity: undefined,
  run_id: route.query.run_id ? Number(route.query.run_id) : undefined,
  range: null,
});

const crud = useCrudTable('shopify/logs', {
  rowsKey: 'logs',
  params: () => ({
    store_id: filters.store_id || '',
    level: filters.level || '',
    entity: filters.entity || '',
    run_id: filters.run_id || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
});

const entityOptions = ENTITIES.map(e => ({ value: e.value, label: e.label }))
  .concat([{ value: 'webhooks', label: 'Webhooks' }]);

const columns = computed(() => [
  { title: 'When', key: 'created_at', dataIndex: 'created_at', width: 160 },
  { title: 'Level', key: 'level', dataIndex: 'level', width: 100 },
  { title: 'Store', key: 'store_id', dataIndex: 'store_id', width: 150 },
  { title: 'Action', key: 'action', dataIndex: 'action', width: 170 },
  { title: 'Message', key: 'message', dataIndex: 'message' },
  { title: '', key: 'actions', width: 90, align: 'center' },
]);

const stores = ref([]);
const storeOptions = computed(() => stores.value.map(s => ({ value: s.id, label: s.label || s.name })));

function storeName(storeId) {
  if (!storeId) return '—';
  const store = stores.value.find(s => s.id === storeId);
  return store ? store.name : `#${storeId}`;
}

// ---------------- drawer ----------------

const drawerOpen = ref(false);
const selected = ref(null);

function inspect(record) {
  selected.value = record;
  drawerOpen.value = true;
}

function filterByRun(runId) {
  filters.run_id = runId;
  drawerOpen.value = false;
  crud.reload();
}

function clearRunFilter() {
  filters.run_id = undefined;
  crud.reload();
}

// ---------------- clear ----------------

const clearing = ref(false);

function confirmClear() {
  Modal.confirm({
    title: 'Clear logs older than 7 days?',
    content: 'Anything from the last week is kept, so a failure you are still investigating stays readable.',
    okText: 'Clear',
    okType: 'danger',
    cancelText: 'Cancel',
    async onOk() {
      clearing.value = true;
      try {
        // http.delete takes no params argument, so the filter has to travel in
        // the URL. Without this the store filter is silently dropped and the
        // clear applies to every store's logs, not the one on screen.
        const query = filters.store_id ? `?store_id=${filters.store_id}` : '';
        const res = await http.delete(`shopify/logs${query}`);
        message.success(`Removed ${res?.deleted ?? 0} entries`);
        crud.fetchRows();
      } catch (e) {
        message.error('Could not clear the logs');
      } finally {
        clearing.value = false;
      }
    },
  });
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('shopify/meta');
    stores.value = meta?.stores || [];
  } catch (e) { /* the store select stays empty */ }
});
</script>

<style scoped>
.link {
  color: #5f9e3f;
  cursor: pointer;
}
.code {
  font-size: 11.5px;
  padding: 1px 6px;
  border-radius: 5px;
  background: rgba(128, 128, 128, 0.14);
}
.msg {
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  font-size: 12.5px;
}
.msg--bad {
  color: #dc2626;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 200px;
  min-width: 170px;
}
.tb-item {
  width: 160px;
}
.tb-range {
  width: 230px;
}
.tb-run {
  font-size: 12px;
}
.drawer-msg {
  margin: 16px 0;
  font-size: 13px;
}
.context {
  background: rgba(128, 128, 128, 0.1);
  padding: 12px;
  border-radius: 8px;
  font-size: 11.5px;
  overflow-x: auto;
  max-height: 420px;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
