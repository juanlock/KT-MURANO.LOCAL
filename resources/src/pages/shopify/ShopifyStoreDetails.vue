<template>
  <div class="page">
    <PageHeader
      :title="store.name || 'Store'"
      :subtitle="store.shop_domain"
      :breadcrumb="['Shopify', 'Stores', store.shop_domain || '']"
    >
      <template #actions>
        <a-button @click="$router.push('/shopify/stores')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button :loading="testing" @click="test">
          <template #icon><ApiOutlined /></template>
          Test connection
        </a-button>
        <a-button type="primary" @click="$router.push(`/shopify/sync?store_id=${id}`)">
          <template #icon><SyncOutlined /></template>
          Sync
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert
        v-if="store.status === 'error'"
        type="error" show-icon style="margin-bottom: 16px"
        message="This store is not reachable"
        :description="store.last_error"
      />

      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">Status</span>
          <span class="kpi-value">
            <a-tag :color="optionOf(STORE_STATUSES, store.status).color">
              {{ labelOf(STORE_STATUSES, store.status) }}
            </a-tag>
          </span>
          <span class="kpi-sub">{{ store.last_connected_at ? dateTime(store.last_connected_at) : 'Never verified' }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Shop</span>
          <span class="kpi-value kpi-value--sm">{{ store.shop_name || '—' }}</span>
          <span class="kpi-sub">{{ store.currency || '' }} {{ store.shop_email || '' }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Warehouse</span>
          <span class="kpi-value kpi-value--sm">{{ store.warehouse_name || 'Not set' }}</span>
          <span class="kpi-sub">Shopify location {{ store.location_id || '—' }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Linked records</span>
          <span class="kpi-value">{{ number(totalLinked, 0) }}</span>
          <span class="kpi-sub">{{ links.order || 0 }} orders imported</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Last sync</span>
          <span class="kpi-value kpi-value--sm">{{ store.last_sync_at ? dateTime(store.last_sync_at) : 'Never' }}</span>
          <span class="kpi-sub">{{ store.auto_sync ? `Auto every ${store.sync_interval_minutes} min` : 'Manual only' }}</span>
        </div>
      </div>

      <a-card size="small">
        <a-tabs v-model:activeKey="tab">
          <!-- ------------------------------------------------ overview -->
          <a-tab-pane key="overview" tab="Overview">
            <a-row :gutter="16">
              <a-col :xs="24" :xl="10">
                <a-descriptions bordered size="small" :column="1" title="Mapped records">
                  <a-descriptions-item v-for="(count, type) in links" :key="type" :label="linkLabel(type)">
                    <a class="link" @click="$router.push(`/shopify/mappings?store_id=${id}&entity_type=${type}`)">
                      {{ number(count, 0) }}
                    </a>
                  </a-descriptions-item>
                </a-descriptions>
              </a-col>
              <a-col :xs="24" :xl="14">
                <a-card size="small" title="What this store syncs" class="inner">
                  <div class="ent-list">
                    <div v-for="entity in ENTITIES" :key="entity.value" class="ent-row">
                      <component :is="entity.icon" :size="15" class="ent-ic" />
                      <span class="ent-name">{{ entity.label }}</span>
                      <a-tag :color="store[`sync_${entity.value}`] ? 'success' : 'default'">
                        {{ store[`sync_${entity.value}`] ? 'On' : 'Off' }}
                      </a-tag>
                    </div>
                  </div>
                </a-card>
              </a-col>
            </a-row>
          </a-tab-pane>

          <!-- ------------------------------------------------ runs -->
          <a-tab-pane key="runs">
            <template #tab>
              <span>Sync history <a-badge :count="runs.length" :number-style="badgeStyle" /></span>
            </template>
            <a-empty v-if="!runs.length" :image="simpleImage" description="Nothing has run for this store" />
            <a-table
              v-else size="small" :columns="runColumns" :data-source="runs" row-key="id"
              :pagination="false" :scroll="{ x: 820 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'entity'">{{ entityOf(record.entity).label }}</template>
                <template v-else-if="column.key === 'direction'">
                  <a-tag :color="optionOf(DIRECTIONS, record.direction).color">
                    {{ labelOf(DIRECTIONS, record.direction) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'status'">
                  <a-tag :color="optionOf(RUN_STATUSES, record.status).color">
                    {{ labelOf(RUN_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'result'">
                  +{{ record.created }} · {{ record.updated }} upd · {{ record.skipped }} skip
                  <template v-if="record.failed"> · <span class="bad">{{ record.failed }} fail</span></template>
                </template>
                <template v-else-if="column.key === 'started_at'">
                  {{ record.started_at ? dateTime(record.started_at) : '—' }}
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------------ webhooks -->
          <a-tab-pane key="webhooks" tab="Webhooks">
            <a-alert
              type="info" show-icon style="margin-bottom: 14px"
              message="Point Shopify at this URL and paste the signing secret into the store settings."
            >
              <template #description>
                <div class="hook-url">
                  <code>{{ webhookUrl }}</code>
                  <a-button size="small" @click="copyUrl">Copy</a-button>
                </div>
                <p class="hook-note">
                  Deliveries without a valid signature are refused, so the secret must be set before webhooks do anything.
                  Shopify retries until it gets a 2xx, and each delivery is recorded once — a repeated event is a no-op, not a duplicate order.
                </p>
              </template>
            </a-alert>

            <div class="topics">
              <span class="topics-label">Topics worth subscribing to:</span>
              <a-tag v-for="topic in WEBHOOK_TOPICS" :key="topic" class="topic">{{ topic }}</a-tag>
            </div>

            <a-table
              size="small" :columns="eventColumns" :data-source="events" row-key="id"
              :loading="loadingEvents" :pagination="{ pageSize: 10 }" :scroll="{ x: 760 }"
              style="margin-top: 14px"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'status'">
                  <a-tooltip :title="record.error || ''">
                    <a-tag :color="optionOf(WEBHOOK_STATUSES, record.status).color">
                      {{ labelOf(WEBHOOK_STATUSES, record.status) }}
                    </a-tag>
                  </a-tooltip>
                </template>
                <template v-else-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
                <template v-else-if="column.key === 'actions'">
                  <a-button
                    v-if="record.status === 'failed'" type="link" size="small"
                    :loading="replaying === record.id" @click="replay(record)"
                  >
                    Replay
                  </a-button>
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------------ errors -->
          <a-tab-pane key="errors">
            <template #tab>
              <span>Errors <a-badge :count="errors.length" :number-style="badgeStyle" /></span>
            </template>
            <a-empty v-if="!errors.length" :image="simpleImage" description="No errors recorded" />
            <a-list v-else size="small" :data-source="errors">
              <template #renderItem="{ item }">
                <a-list-item>
                  <a-list-item-meta
                    :title="item.message"
                    :description="`${item.action}${item.entity ? ' · ' + item.entity : ''} · ${item.created_at}`"
                  />
                </a-list-item>
              </template>
            </a-list>
            <div style="margin-top: 12px">
              <a-button size="small" @click="$router.push(`/shopify/logs?store_id=${id}`)">All logs for this store</a-button>
            </div>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * One store: how it is configured, what it has mapped, what it has run and what
 * webhooks it has received.
 *
 * Editing lives back on the list page's modal rather than being duplicated here
 * — one place to change a connection, several places to look at one.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { ArrowLeftOutlined, ApiOutlined, SyncOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import {
  STORE_STATUSES, RUN_STATUSES, DIRECTIONS, WEBHOOK_STATUSES, WEBHOOK_TOPICS,
  ENTITIES, LINK_TYPES, entityOf, labelOf, optionOf,
} from './shopifyOptions';
import http from '../../lib/http';

const route = useRoute();
const { number, dateTime } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,0.25)', color: 'inherit', boxShadow: 'none' };

const id = route.params.id;
const tab = ref('overview');
const loading = ref(false);
const testing = ref(false);

const store = ref({});
const links = ref({});
const runs = ref([]);
const errors = ref([]);
const webhookUrl = ref('');

const totalLinked = computed(() => Object.values(links.value).reduce((sum, n) => sum + (n || 0), 0));

const runColumns = [
  { title: 'Entity', key: 'entity', dataIndex: 'entity', width: 130 },
  { title: 'Direction', key: 'direction', dataIndex: 'direction', width: 150 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 130 },
  { title: 'Result', key: 'result', width: 250 },
  { title: 'Started', key: 'started_at', dataIndex: 'started_at', width: 160 },
];

const eventColumns = [
  { title: 'Topic', dataIndex: 'topic', key: 'topic', width: 180 },
  { title: 'Event', dataIndex: 'event_id', key: 'event_id' },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 120 },
  { title: 'Received', key: 'created_at', dataIndex: 'created_at', width: 160 },
  { title: '', key: 'actions', width: 90, align: 'center' },
];

function linkLabel(type) {
  return labelOf(LINK_TYPES, type);
}

// ---------------- webhooks ----------------

const events = ref([]);
const loadingEvents = ref(false);
const replaying = ref(null);

async function loadEvents() {
  loadingEvents.value = true;
  try {
    const res = await http.get('shopify/webhook-events', { store_id: id, limit: 50 });
    events.value = res?.events || [];
  } catch (e) {
    events.value = [];
  } finally {
    loadingEvents.value = false;
  }
}

async function replay(record) {
  replaying.value = record.id;
  try {
    await http.post(`shopify/webhook-events/${record.id}/replay`);
    message.success('Replayed');
    loadEvents();
    load();
  } catch (e) {
    message.error(e?.data?.message || 'Replay failed');
  } finally {
    replaying.value = null;
  }
}

function copyUrl() {
  navigator.clipboard?.writeText(webhookUrl.value)
    .then(() => message.success('URL copied'))
    .catch(() => message.info(webhookUrl.value));
}

// ---------------- load ----------------

async function test() {
  testing.value = true;
  try {
    const res = await http.post(`shopify/stores/${id}/test`);
    message.success(`Connected to ${res?.shop?.name || store.value.shop_domain}`);
  } catch (e) {
    message.error(e?.data?.error || 'Could not reach that shop');
  } finally {
    testing.value = false;
    load();
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get(`shopify/stores/${id}/overview`);
    store.value = res?.store || {};
    links.value = res?.links || {};
    runs.value = res?.runs || [];
    errors.value = res?.errors || [];
  } catch (e) {
    message.error('Could not load this store');
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  load();
  loadEvents();
  try {
    const meta = await http.get('shopify/meta');
    webhookUrl.value = meta?.webhook_url || '';
  } catch (e) { /* the URL box stays empty */ }
});
</script>

<style scoped>
.bad {
  color: #dc2626;
}
.link {
  color: #5f9e3f;
  cursor: pointer;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 13px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.kpi-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.kpi-value {
  font-size: 19px;
  font-weight: 600;
  line-height: 1.35;
}
.kpi-value--sm {
  font-size: 14.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.kpi-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.inner {
  height: 100%;
}
.ent-list {
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.ent-row {
  display: flex;
  align-items: center;
  gap: 9px;
}
.ent-ic {
  opacity: 0.7;
  flex: none;
}
.ent-name {
  flex: 1;
  font-size: 13px;
}
.hook-url {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 6px;
}
.hook-url code {
  padding: 3px 8px;
  border-radius: 6px;
  background: rgba(128, 128, 128, 0.14);
  font-size: 12px;
  word-break: break-all;
}
.hook-note {
  margin: 8px 0 0;
  font-size: 12px;
  opacity: 0.75;
}
.topics {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
}
.topics-label {
  font-size: 12.5px;
  opacity: 0.65;
  margin-inline-end: 4px;
}
.topic {
  font-family: monospace;
  font-size: 11px;
}
</style>
