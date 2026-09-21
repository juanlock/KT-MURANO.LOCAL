<template>
  <div class="page">
    <PageHeader
      title="Shopify"
      subtitle="Every connected shop, what is linked and what has been moving."
      :breadcrumb="['Shopify', 'Dashboard']"
    >
      <template #actions>
        <a-button @click="$router.push('/shopify/sync')">
          <template #icon><SyncOutlined /></template>
          Sync centre
        </a-button>
        <a-button type="primary" @click="$router.push('/shopify/stores')">
          <template #icon><ShopOutlined /></template>
          Manage stores
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert
        v-if="!loading && !data.stores_total"
        type="info" show-icon style="margin-bottom: 16px"
        message="No Shopify store is connected yet."
        description="Connect a shop with its Admin API access token to start syncing products, stock, customers and orders."
      >
        <template #action>
          <a-button size="small" type="primary" @click="$router.push('/shopify/stores')">Connect a store</a-button>
        </template>
      </a-alert>

      <div class="kpis">
        <button type="button" class="kpi" @click="$router.push('/shopify/stores')">
          <span class="kpi-ic kpi-ic--brand"><ShopOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.stores_connected || 0 }}<span class="kpi-of">/{{ data.stores_total || 0 }}</span></span>
            <span class="kpi-label">Stores connected</span>
            <span v-if="data.stores_error" class="kpi-sub kpi-sub--bad">{{ data.stores_error }} with errors</span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/shopify/mappings')">
          <span class="kpi-ic kpi-ic--info"><LinkOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ number(data.linked_records || 0, 0) }}</span>
            <span class="kpi-label">Linked records</span>
          </span>
        </button>
        <div class="kpi kpi--static">
          <span class="kpi-ic kpi-ic--ok"><ReceiptTextIcon :size="18" /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ number(data.orders_imported || 0, 0) }}</span>
            <span class="kpi-label">Orders imported</span>
          </span>
        </div>
        <button type="button" class="kpi" @click="$router.push('/shopify/sync')">
          <span class="kpi-ic kpi-ic--run"><SyncOutlined :spin="!!data.running_now" /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.runs_30d || 0 }}</span>
            <span class="kpi-label">Syncs (30 days)</span>
            <span v-if="data.running_now" class="kpi-sub">{{ data.running_now }} running now</span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/shopify/logs?level=error')">
          <span class="kpi-ic kpi-ic--bad"><WarningOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.errors_7d || 0 }}</span>
            <span class="kpi-label">Errors (7 days)</span>
            <span v-if="data.runs_failed_30d" class="kpi-sub kpi-sub--bad">{{ data.runs_failed_30d }} failed runs</span>
          </span>
        </button>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="15">
          <ReportChart
            :data="data.activity || []"
            :fields="[
              { key: 'records', label: 'Records synced' },
              { key: 'runs', label: 'Runs' },
            ]"
            title="Sync activity — last 14 days"
            type="bar"
            x-key="d"
            :height="300"
          />
        </a-col>
        <a-col :xs="24" :xl="9">
          <a-card size="small" title="By entity" class="panel">
            <a-empty v-if="!data.by_entity?.length" :image="simpleImage" />
            <ul v-else class="entities">
              <li v-for="row in data.by_entity" :key="row.entity">
                <span class="ent-name">
                  <component :is="entityOf(row.entity).icon" :size="15" class="ent-ic" />
                  {{ entityOf(row.entity).label }}
                </span>
                <span class="ent-meta">
                  <a-tag>{{ number(row.linked, 0) }} linked</a-tag>
                  <a-tag v-if="row.failed" color="error">{{ row.failed }} failed</a-tag>
                </span>
              </li>
            </ul>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="16" style="margin-top: 16px">
        <a-col :xs="24" :xl="12">
          <a-card size="small" title="Stores" class="panel">
            <template #extra>
              <a class="link" @click="$router.push('/shopify/stores')">Manage</a>
            </template>
            <a-empty v-if="!data.stores?.length" :image="simpleImage" description="Nothing connected" />
            <a-list v-else size="small" :data-source="data.stores">
              <template #renderItem="{ item }">
                <a-list-item class="row" @click="$router.push(`/shopify/stores/${item.id}`)">
                  <a-list-item-meta :title="item.name" :description="item.shop_domain" />
                  <a-space :size="4">
                    <a-tag>{{ number(item.linked_records, 0) }} linked</a-tag>
                    <a-tag :color="optionOf(STORE_STATUSES, item.status).color">
                      {{ labelOf(STORE_STATUSES, item.status) }}
                    </a-tag>
                  </a-space>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card size="small" title="Recent syncs" class="panel">
            <template #extra>
              <a class="link" @click="$router.push('/shopify/sync')">Sync centre</a>
            </template>
            <a-empty v-if="!data.recent_runs?.length" :image="simpleImage" description="Nothing has run yet" />
            <a-list v-else size="small" :data-source="data.recent_runs">
              <template #renderItem="{ item }">
                <a-list-item>
                  <a-list-item-meta
                    :title="`${entityOf(item.entity).label} · ${labelOf(DIRECTIONS, item.direction)}`"
                    :description="`${item.store_name || 'Store'} · ${item.started_at || ''}`"
                  />
                  <a-space :size="4">
                    <a-tag v-if="item.dry_run">Dry run</a-tag>
                    <a-tag :color="optionOf(RUN_STATUSES, item.status).color">
                      {{ labelOf(RUN_STATUSES, item.status) }}
                    </a-tag>
                  </a-space>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
      </a-row>

      <a-card v-if="data.recent_errors?.length" size="small" title="Recent errors" style="margin-top: 16px">
        <template #extra>
          <a class="link" @click="$router.push('/shopify/logs?level=error')">All logs</a>
        </template>
        <a-list size="small" :data-source="data.recent_errors">
          <template #renderItem="{ item }">
            <a-list-item>
              <a-list-item-meta :title="item.message" :description="`${item.action} · ${item.created_at}`" />
            </a-list-item>
          </template>
        </a-list>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Shopify landing page. Answers the three questions worth asking before
 * touching anything: are the shops connected, is data actually flowing, and did
 * anything fail.
 */
import { ref, onMounted } from 'vue';
import { Empty } from 'ant-design-vue';
import {
  ShopOutlined, SyncOutlined, LinkOutlined, WarningOutlined,
} from '@ant-design/icons-vue';
import { ReceiptText as ReceiptTextIcon } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { STORE_STATUSES, RUN_STATUSES, DIRECTIONS, entityOf, labelOf, optionOf } from './shopifyOptions';
import http from '../../lib/http';

const { number } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const loading = ref(false);
const data = ref({});

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('shopify/dashboard');
  } catch (e) {
    data.value = {};
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
  transition: border-color 0.15s ease, transform 0.12s ease;
}
.kpi:hover {
  border-color: rgba(95, 158, 63, 0.55);
  transform: translateY(-1px);
}
.kpi--static,
.kpi--static:hover {
  cursor: default;
  border-color: rgba(128, 128, 128, 0.2);
  transform: none;
}
.kpi-ic {
  width: 40px;
  height: 40px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 18px;
}
/* Shopify green, so the module reads as itself at a glance. */
.kpi-ic--brand {
  color: #5f9e3f;
  background: rgba(95, 158, 63, 0.14);
}
.kpi-ic--info {
  color: #0891b2;
  background: rgba(8, 145, 178, 0.12);
}
.kpi-ic--ok {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.12);
}
.kpi-ic--run {
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.12);
}
.kpi-ic--bad {
  color: #dc2626;
  background: rgba(220, 38, 38, 0.12);
}
.kpi-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.kpi-value {
  font-size: 20px;
  font-weight: 600;
  line-height: 1.2;
}
.kpi-of {
  font-size: 14px;
  opacity: 0.5;
  font-weight: 500;
}
.kpi-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.kpi-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.kpi-sub--bad {
  color: #dc2626;
  opacity: 0.9;
}
.panel {
  height: 100%;
}
.row {
  cursor: pointer;
}
.row:hover {
  background: rgba(128, 128, 128, 0.06);
}
.link {
  color: #5f9e3f;
  cursor: pointer;
  font-size: 12.5px;
}
.entities {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.entities li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.ent-name {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
}
.ent-ic {
  opacity: 0.7;
  flex: none;
}
.ent-meta {
  display: inline-flex;
  gap: 4px;
  flex: none;
}
</style>
