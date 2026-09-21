<template>
  <div class="page">
    <PageHeader
      title="Asset Dashboard"
      subtitle="What you own, what it is worth and what needs attention."
      :breadcrumb="['Asset Management', 'Dashboard']"
    >
      <template #actions>
        <a-button @click="$router.push('/assets')">
          <template #icon><UnorderedListOutlined /></template>
          All assets
        </a-button>
        <a-button type="primary" @click="$router.push('/assets/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add_Asset') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- KPI strip. Every tile that represents a filterable set is a button. -->
      <div class="kpis">
        <button type="button" class="kpi" @click="$router.push('/assets')">
          <span class="kpi-ic kpi-ic--brand"><AppstoreOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.total || 0 }}</span>
            <span class="kpi-label">Assets</span>
          </span>
        </button>
        <div class="kpi kpi--static">
          <span class="kpi-ic kpi-ic--cost"><DollarOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ money(data.book_value || 0) }}</span>
            <span class="kpi-label">Book value</span>
            <span class="kpi-sub">of {{ money(data.purchase_value || 0) }} cost</span>
          </span>
        </div>
        <button type="button" class="kpi" @click="$router.push('/assets/assignments?status=assigned')">
          <span class="kpi-ic kpi-ic--info"><UserOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.assigned || 0 }}</span>
            <span class="kpi-label">Checked out</span>
            <span class="kpi-sub">{{ data.unassigned || 0 }} on the shelf</span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/assets/maintenance?status=in_progress')">
          <span class="kpi-ic kpi-ic--warn"><ToolOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.open_maintenance || 0 }}</span>
            <span class="kpi-label">Open jobs</span>
            <span v-if="data.overdue_maintenance" class="kpi-sub kpi-sub--bad">
              {{ data.overdue_maintenance }} overdue
            </span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/assets/due')">
          <span class="kpi-ic kpi-ic--due"><SafetyCertificateOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.due_validation || 0 }}</span>
            <span class="kpi-label">Validations due</span>
            <span v-if="data.overdue_validation" class="kpi-sub kpi-sub--bad">
              {{ data.overdue_validation }} overdue
            </span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/assets/assignments?status=overdue')">
          <span class="kpi-ic kpi-ic--bad"><ClockCircleOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.overdue_returns || 0 }}</span>
            <span class="kpi-label">Overdue returns</span>
          </span>
        </button>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="15">
          <ReportChart
            :data="data.spend_trend || []"
            :fields="[{ key: 'cost', label: 'Maintenance spend', money: true }]"
            title="Maintenance spend — last 12 months"
            type="bar"
            x-key="d"
            :height="300"
            :format="money"
          />
        </a-col>
        <a-col :xs="24" :xl="9">
          <a-card size="small" title="Value by category" class="panel">
            <a-empty v-if="!data.by_category?.length" :image="simpleImage" />
            <ul v-else class="bars">
              <li v-for="row in data.by_category" :key="row.label">
                <div class="bar-head">
                  <span class="bar-label">
                    <component :is="categoryIcon(row.label)" :size="15" class="bar-ic" />
                    {{ row.label }}
                  </span>
                  <span class="bar-value">{{ money(row.value) }}</span>
                </div>
                <div class="bar-track">
                  <div class="bar-fill" :style="{ width: pct(row.value, maxCategoryValue) }" />
                </div>
                <span class="bar-sub">{{ row.count }} asset{{ row.count === 1 ? '' : 's' }}</span>
              </li>
            </ul>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="16" style="margin-top: 16px">
        <a-col :xs="24" :xl="12">
          <a-card size="small" title="Validations coming up" class="panel">
            <template #extra>
              <a class="link" @click="$router.push('/assets/due')">View all</a>
            </template>
            <a-empty v-if="!data.upcoming_validations?.length" :image="simpleImage" description="Nothing due" />
            <a-list v-else size="small" :data-source="data.upcoming_validations">
              <template #renderItem="{ item }">
                <a-list-item class="row" @click="$router.push(`/assets/${item.id}`)">
                  <a-list-item-meta :title="item.name" :description="`${item.tag}${item.category_name ? ' · ' + item.category_name : ''}`" />
                  <a-tag :color="dueColor(item.days)">{{ dueLabel(item.days) }}</a-tag>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card size="small" title="Overdue returns" class="panel">
            <template #extra>
              <a class="link" @click="$router.push('/assets/assignments?status=overdue')">View all</a>
            </template>
            <a-empty v-if="!data.overdue_returns_rows?.length" :image="simpleImage" description="Everything is back on time" />
            <a-list v-else size="small" :data-source="data.overdue_returns_rows">
              <template #renderItem="{ item }">
                <a-list-item class="row" @click="$router.push(`/assets/${item.asset_id}`)">
                  <a-list-item-meta :title="item.name" :description="`${item.tag} · ${item.user_name || 'Unknown holder'}`" />
                  <a-tag color="error">{{ item.days_late }} day{{ item.days_late === 1 ? '' : 's' }} late</a-tag>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="16" style="margin-top: 16px">
        <a-col :xs="24" :xl="12">
          <a-card size="small" title="Open maintenance" class="panel">
            <template #extra>
              <a class="link" @click="$router.push('/assets/maintenance')">View all</a>
            </template>
            <a-empty v-if="!data.open_jobs?.length" :image="simpleImage" description="No open jobs" />
            <a-list v-else size="small" :data-source="data.open_jobs">
              <template #renderItem="{ item }">
                <a-list-item class="row" @click="$router.push(`/assets/${item.asset_id}`)">
                  <a-list-item-meta
                    :title="item.title"
                    :description="`${item.tag} · ${item.asset_name}`"
                  />
                  <a-space :size="4">
                    <a-tag :color="optionOf(MAINTENANCE_TYPES, item.type).color">
                      {{ labelOf(MAINTENANCE_TYPES, item.type) }}
                    </a-tag>
                    <a-tag :color="item.is_overdue ? 'error' : 'default'">{{ date(item.scheduled_date) }}</a-tag>
                  </a-space>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="12">
          <a-card size="small" title="By warehouse" class="panel">
            <a-empty v-if="!data.by_warehouse?.length" :image="simpleImage" />
            <a-table
              v-else
              size="small"
              :pagination="false"
              :columns="warehouseColumns"
              :data-source="data.by_warehouse"
              row-key="label"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'value'">{{ money(record.value) }}</template>
              </template>
            </a-table>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Asset Management landing page: the register at a glance, plus the three
 * things that actually need chasing — validations falling due, kit that has
 * not come back, and jobs still open.
 *
 * Book value is summed server-side from each asset's own depreciation method,
 * so the headline figure here and the one on a single asset's page can never
 * disagree.
 */
import { ref, computed, onMounted } from 'vue';
import { Empty } from 'ant-design-vue';
import {
  PlusOutlined, UnorderedListOutlined, AppstoreOutlined, DollarOutlined,
  UserOutlined, ToolOutlined, SafetyCertificateOutlined, ClockCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import {
  MAINTENANCE_TYPES, labelOf, optionOf, categoryIcon, dueColor, dueLabel,
} from './assetOptions';
import http from '../../lib/http';

const { money, date } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const loading = ref(false);
const data = ref({});

const maxCategoryValue = computed(
  () => Math.max(1, ...(data.value.by_category || []).map(r => r.value || 0)),
);

const warehouseColumns = [
  { title: 'Warehouse', dataIndex: 'label', key: 'label' },
  { title: 'Assets', dataIndex: 'count', key: 'count', width: 90, align: 'right' },
  { title: 'Cost', key: 'value', dataIndex: 'value', width: 130, align: 'right' },
];

function pct(value, max) {
  return `${Math.max(2, Math.round(((value || 0) / max) * 100))}%`;
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('assets/workspace/dashboard');
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
  border-color: rgba(109, 40, 217, 0.5);
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
.kpi-ic--brand {
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.12);
}
.kpi-ic--cost {
  color: #4f46e5;
  background: rgba(79, 70, 229, 0.12);
}
.kpi-ic--info {
  color: #0891b2;
  background: rgba(8, 145, 178, 0.12);
}
.kpi-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.kpi-ic--due {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.12);
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
  color: #6d28d9;
  cursor: pointer;
  font-size: 12.5px;
}
.bars {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.bar-head {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 13px;
}
.bar-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.bar-ic {
  flex: none;
  opacity: 0.7;
}
.bar-value {
  flex: none;
  font-weight: 600;
}
.bar-track {
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.15);
  margin: 5px 0 3px;
  overflow: hidden;
}
.bar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #6d28d9, #a855f7);
}
.bar-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
</style>
