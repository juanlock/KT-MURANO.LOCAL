<template>
  <div class="page">
    <PageHeader
      title="Sync Centre"
      subtitle="Move products, stock, customers, orders and collections in either direction."
      :breadcrumb="['Shopify', 'Sync Centre']"
    >
      <template #actions>
        <a-button @click="$router.push('/shopify/logs')">
          <template #icon><FileTextOutlined /></template>
          Logs
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-select
          v-model:value="storeId" class="tb-store" show-search option-filter-prop="label"
          :options="storeOptions" placeholder="Select a store" @change="onStoreChange"
        />
        <a-checkbox v-model:checked="dryRun" class="tb-check">
          Dry run
        </a-checkbox>
        <span class="tb-hint">Reports what would change without writing anything.</span>
      </div>
    </a-card>

    <a-alert
      v-if="!storeOptions.length"
      type="info" show-icon
      message="No store to sync yet."
      description="Connect a Shopify store first."
    >
      <template #action>
        <a-button size="small" type="primary" @click="$router.push('/shopify/stores')">Connect a store</a-button>
      </template>
    </a-alert>

    <template v-else-if="store">
      <a-alert
        v-if="blockers.length"
        type="warning" show-icon style="margin-bottom: 16px"
        message="This store is not ready to sync"
      >
        <template #description>
          <ul class="blockers">
            <li v-for="b in blockers" :key="b">{{ b }}</li>
          </ul>
          <a-button size="small" @click="$router.push(`/shopify/stores/${store.id}`)">Open store settings</a-button>
        </template>
      </a-alert>

      <div class="entity-grid">
        <a-card
          v-for="entity in ENTITIES" :key="entity.value"
          size="small" class="entity-card" :class="{ 'entity-card--off': !store[`sync_${entity.value}`] }"
        >
          <div class="ec-head">
            <span class="ec-ic"><component :is="entity.icon" :size="18" /></span>
            <span class="ec-title">
              <strong>{{ entity.label }}</strong>
              <span class="ec-hint">{{ entity.hint }}</span>
            </span>
            <a-tag v-if="!store[`sync_${entity.value}`]" class="ec-off">Off</a-tag>
          </div>

          <!-- live progress, or the last result -->
          <div class="ec-status">
            <template v-if="running[entity.value]">
              <a-progress
                :percent="running[entity.value].percentage || 0"
                :status="running[entity.value].status === 'failed' ? 'exception' : 'active'"
                size="small"
              />
              <div class="ec-stage">
                {{ running[entity.value].stage || 'working' }} ·
                {{ running[entity.value].processed }}/{{ running[entity.value].total || '?' }}
              </div>
            </template>
            <template v-else-if="latest[entity.value]">
              <div class="ec-last">
                <a-tag :color="optionOf(RUN_STATUSES, latest[entity.value].status).color">
                  {{ labelOf(RUN_STATUSES, latest[entity.value].status) }}
                </a-tag>
                <a-tag v-if="latest[entity.value].dry_run">Dry run</a-tag>
                <span class="ec-counts">
                  +{{ latest[entity.value].created }} new ·
                  {{ latest[entity.value].updated }} updated ·
                  {{ latest[entity.value].skipped }} skipped<template v-if="latest[entity.value].failed">
                    · <span class="bad">{{ latest[entity.value].failed }} failed</span>
                  </template>
                </span>
              </div>
              <div v-if="latest[entity.value].last_error" class="ec-error">
                {{ latest[entity.value].last_error }}
              </div>
              <div v-else-if="latest[entity.value].finished_at" class="ec-when">
                {{ dateTime(latest[entity.value].finished_at) }}
              </div>
            </template>
            <div v-else class="ec-never">Never run</div>
          </div>

          <div class="ec-actions">
            <template v-if="running[entity.value]">
              <a-button size="small" danger :loading="cancelling === entity.value" @click="cancel(entity.value)">
                Stop
              </a-button>
            </template>
            <template v-else>
              <a-button
                v-for="dir in entity.directions" :key="dir"
                size="small"
                :type="dir === 'pull' ? 'primary' : 'default'"
                :disabled="!canRun(entity)"
                :loading="starting === `${entity.value}:${dir}`"
                @click="start(entity.value, dir)"
              >
                <template #icon>
                  <component :is="dir === 'push' ? UploadOutlined : DownloadOutlined" />
                </template>
                {{ optionOf(DIRECTIONS, dir).short }}
              </a-button>
            </template>
          </div>

          <div v-if="entity.value === 'orders'" class="ec-extra">
            <a-date-picker
              v-model:value="since" size="small" value-format="YYYY-MM-DD"
              placeholder="Only orders since…" style="width: 100%"
            />
          </div>
        </a-card>
      </div>

      <a-card size="small" title="Recent runs" style="margin-top: 16px">
        <a-table
          size="small" :columns="runColumns" :data-source="runs" row-key="id"
          :loading="loadingRuns" :pagination="{ pageSize: 10 }" :scroll="{ x: 900 }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'entity'">
              {{ entityOf(record.entity).label }}
            </template>
            <template v-else-if="column.key === 'direction'">
              <a-tag :color="optionOf(DIRECTIONS, record.direction).color">
                {{ labelOf(DIRECTIONS, record.direction) }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-space :size="4">
                <a-tag :color="optionOf(RUN_STATUSES, record.status).color">
                  {{ labelOf(RUN_STATUSES, record.status) }}
                </a-tag>
                <a-tag v-if="record.dry_run">Dry</a-tag>
              </a-space>
            </template>
            <template v-else-if="column.key === 'result'">
              <span class="ec-counts">
                +{{ record.created }} · {{ record.updated }} upd · {{ record.skipped }} skip
                <template v-if="record.failed"> · <span class="bad">{{ record.failed }} fail</span></template>
              </span>
            </template>
            <template v-else-if="column.key === 'duration'">
              {{ record.duration !== null ? `${record.duration}s` : '—' }}
            </template>
            <template v-else-if="column.key === 'started_at'">
              {{ record.started_at ? dateTime(record.started_at) : '—' }}
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button type="link" size="small" @click="$router.push(`/shopify/logs?run_id=${record.id}`)">
                Logs
              </a-button>
            </template>
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Where syncs are started and watched.
 *
 * A run happens inside the request that starts it, so the button stays busy
 * until the server answers. While it waits, a poll refreshes the run row every
 * couple of seconds — that is what shows progress on a catalogue big enough to
 * take minutes, and what lets Stop work at all.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  FileTextOutlined, UploadOutlined, DownloadOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import {
  ENTITIES, RUN_STATUSES, DIRECTIONS, entityOf, labelOf, optionOf, isLive,
} from './shopifyOptions';
import http from '../../lib/http';

const route = useRoute();
const { dateTime } = useFormat();

const stores = ref([]);
const storeId = ref(route.query.store_id ? Number(route.query.store_id) : undefined);
const dryRun = ref(false);
const since = ref(null);

const store = computed(() => stores.value.find(s => s.id === storeId.value));
const storeOptions = computed(() => stores.value.map(s => ({
  value: s.id,
  label: `${s.name} — ${s.shop_domain}`,
})));

/** Everything that must be true before a sync can do anything useful. */
const blockers = computed(() => {
  const s = store.value;
  if (!s) return [];
  const out = [];
  if (s.status !== 'connected') out.push('The connection has not been verified — test it from the store page.');
  if (!s.warehouse_id) out.push('No ERP warehouse is set, so stock and orders have nowhere to go.');
  if (!s.location_id) out.push('No Shopify location is set, so inventory cannot be matched.');
  return out;
});

function canRun(entity) {
  const s = store.value;
  if (!s || !s[`sync_${entity.value}`]) return false;
  if (s.status !== 'connected') return false;
  // Inventory and orders are the two that genuinely cannot work without both
  // ends configured; products and customers are fine without them.
  if (['inventory', 'orders', 'fulfillments'].includes(entity.value)) {
    return !!s.warehouse_id && (entity.value !== 'inventory' || !!s.location_id);
  }
  return true;
}

// ---------------- runs ----------------

const latest = reactive({});
const running = reactive({});
const runs = ref([]);
const loadingRuns = ref(false);
const starting = ref(null);
const cancelling = ref(null);
let poller = null;

const runColumns = [
  { title: 'Entity', key: 'entity', dataIndex: 'entity', width: 130 },
  { title: 'Direction', key: 'direction', dataIndex: 'direction', width: 150 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 150 },
  { title: 'Result', key: 'result', width: 260 },
  { title: 'Took', key: 'duration', dataIndex: 'duration', width: 90, align: 'right' },
  { title: 'Started', key: 'started_at', dataIndex: 'started_at', width: 160 },
  { title: '', key: 'actions', width: 80, align: 'center' },
];

async function loadLatest() {
  if (!storeId.value) return;
  try {
    const res = await http.get('shopify/sync/latest', { store_id: storeId.value });
    Object.keys(latest).forEach(k => delete latest[k]);
    Object.keys(running).forEach(k => delete running[k]);

    Object.entries(res?.latest || {}).forEach(([entity, run]) => {
      if (!run) return;
      latest[entity] = run;
      if (isLive(run.status)) running[entity] = run;
    });

    syncPoller();
  } catch (e) { /* leave the cards showing whatever they had */ }
}

async function loadRuns() {
  if (!storeId.value) return;
  loadingRuns.value = true;
  try {
    const res = await http.get('shopify/sync/runs', { store_id: storeId.value, limit: 25 });
    runs.value = res?.runs || [];
  } catch (e) {
    runs.value = [];
  } finally {
    loadingRuns.value = false;
  }
}

/** Poll only while something is actually live. */
function syncPoller() {
  const live = Object.keys(running).length > 0;
  if (live && !poller) {
    poller = setInterval(refreshRunning, 2000);
  } else if (!live && poller) {
    clearInterval(poller);
    poller = null;
  }
}

async function refreshRunning() {
  const entries = Object.entries(running);
  if (!entries.length) {
    syncPoller();
    return;
  }

  await Promise.all(entries.map(async ([entity, run]) => {
    try {
      const res = await http.get(`shopify/sync/status/${run.id}`);
      const fresh = res?.run;
      if (!fresh) return;
      latest[entity] = fresh;
      if (isLive(fresh.status)) running[entity] = fresh;
      else delete running[entity];
    } catch (e) {
      delete running[entity];
    }
  }));

  syncPoller();
  if (!Object.keys(running).length) loadRuns();
}

async function start(entity, direction) {
  starting.value = `${entity}:${direction}`;
  // Show progress from the first tick rather than after the request returns.
  running[entity] = { id: null, percentage: 0, processed: 0, total: 0, stage: 'starting', status: 'running' };
  syncPoller();

  try {
    const res = await http.post('shopify/sync/start', {
      store_id: storeId.value,
      entity,
      direction,
      dry_run: dryRun.value,
      since: entity === 'orders' ? since.value : null,
    });

    const run = res?.run;
    if (run) {
      latest[entity] = run;
      if (isLive(run.status)) running[entity] = run;
      else delete running[entity];
    }

    if (run?.status === 'completed') {
      message.success(`${entityOf(entity).label}: ${run.created} created, ${run.updated} updated`
        + (run.failed ? `, ${run.failed} failed` : ''));
    } else if (run?.status === 'failed') {
      message.error(run.last_error || 'That sync failed — check the logs');
    } else if (run?.status === 'cancelled') {
      message.warning('Sync stopped');
    }
  } catch (e) {
    delete running[entity];
    message.error(e?.data?.message || 'Could not start that sync');
    // 409: something is already running. Re-read so the card shows it.
    if (e?.status === 409) loadLatest();
  } finally {
    starting.value = null;
    syncPoller();
    loadRuns();
  }
}

async function cancel(entity) {
  const run = running[entity];
  if (!run?.id) return;

  cancelling.value = entity;
  try {
    await http.post(`shopify/sync/cancel/${run.id}`);
    message.info('Stopping after the current batch');
  } catch (e) {
    message.error(e?.data?.message || 'Could not stop that run');
  } finally {
    cancelling.value = null;
  }
}

function onStoreChange() {
  loadLatest();
  loadRuns();
}

onMounted(async () => {
  try {
    const meta = await http.get('shopify/meta');
    stores.value = meta?.stores || [];
    if (!storeId.value && stores.value.length) storeId.value = stores.value[0].id;
  } catch (e) { /* the select stays empty */ }

  if (storeId.value) {
    loadLatest();
    loadRuns();
  }
});

onBeforeUnmount(() => {
  if (poller) clearInterval(poller);
});
</script>

<style scoped>
.bad {
  color: #dc2626;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.tb-store {
  flex: 1 1 280px;
  min-width: 220px;
  max-width: 420px;
}
.tb-check {
  white-space: nowrap;
}
.tb-hint {
  font-size: 12px;
  opacity: 0.55;
}
.blockers {
  margin: 0 0 10px;
  padding-inline-start: 18px;
}
.entity-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 12px;
}
.entity-card {
  display: flex;
  flex-direction: column;
}
.entity-card--off {
  opacity: 0.55;
}
.ec-head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.ec-ic {
  width: 36px;
  height: 36px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 11px;
  color: #5f9e3f;
  background: rgba(95, 158, 63, 0.13);
}
.ec-title {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.ec-hint {
  font-size: 11.5px;
  opacity: 0.6;
}
.ec-off {
  flex: none;
}
.ec-status {
  margin-top: 12px;
  min-height: 46px;
}
.ec-stage {
  font-size: 11.5px;
  opacity: 0.6;
}
.ec-last {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}
.ec-counts {
  font-size: 11.5px;
  opacity: 0.7;
}
.ec-error {
  margin-top: 4px;
  font-size: 11.5px;
  color: #dc2626;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.ec-when,
.ec-never {
  font-size: 11.5px;
  opacity: 0.5;
  margin-top: 4px;
}
.ec-actions {
  display: flex;
  gap: 6px;
  margin-top: 10px;
}
.ec-extra {
  margin-top: 8px;
}
@media (max-width: 767px) {
  .tb-store {
    max-width: none;
  }
}
</style>
