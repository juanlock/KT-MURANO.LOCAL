<template>
  <div class="page">
    <PageHeader
      title="Fleet Dashboard"
      subtitle="Vehicles, running costs and everything falling due."
      :breadcrumb="['Fleet Management', 'Dashboard']"
    >
      <template #actions>
        <a-button @click="$router.push('/fleet/vehicles')">
          <template #icon><UnorderedListOutlined /></template>
          All vehicles
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/fleet/vehicles/create')">
          <template #icon><PlusOutlined /></template>
          Add vehicle
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- KPI strip -->
      <div class="kpis">
        <button type="button" class="kpi" @click="$router.push('/fleet/vehicles')">
          <span class="kpi-ic kpi-ic--brand"><CarOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.total || 0 }}</span>
            <span class="kpi-label">Vehicles</span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/fleet/vehicles?status=active')">
          <span class="kpi-ic kpi-ic--ok"><CheckCircleOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.active || 0 }}</span>
            <span class="kpi-label">Active</span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/fleet/vehicles?status=maintenance')">
          <span class="kpi-ic kpi-ic--warn"><ToolOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.in_maintenance || 0 }}</span>
            <span class="kpi-label">In maintenance</span>
          </span>
        </button>
        <button type="button" class="kpi" @click="$router.push('/fleet/assignments?status=active')">
          <span class="kpi-ic kpi-ic--info"><UserOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.assigned || 0 }}</span>
            <span class="kpi-label">Out with drivers</span>
          </span>
        </button>
        <div class="kpi kpi--static">
          <span class="kpi-ic kpi-ic--fuel"><FireOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ money(data.fuel_cost_month || 0) }}</span>
            <span class="kpi-label">Fuel this month</span>
          </span>
        </div>
        <div class="kpi kpi--static">
          <span class="kpi-ic kpi-ic--cost"><DollarOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ money(data.maintenance_cost_month || 0) }}</span>
            <span class="kpi-label">Maintenance this month</span>
          </span>
        </div>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="15">
          <ReportChart
            :data="data.cost_trend || []"
            :fields="[
              { key: 'fuel', label: 'Fuel' },
              { key: 'maintenance', label: 'Maintenance' },
            ]"
            title="Running costs — last 6 months"
            type="bar"
            x-key="month"
            :height="300"
            :format="money"
          />

          <a-card size="small" title="Fleet composition" style="margin-bottom: 16px">
            <div v-if="composition.length" class="breakdown">
              <div v-for="row in composition" :key="row.type" class="breakdown-row">
                <span class="breakdown-label">{{ labelOf(VEHICLE_TYPES, row.type) }}</span>
                <a-progress
                  :percent="row.percent" :show-info="false" size="small"
                  stroke-color="#6d28d9" class="breakdown-bar"
                />
                <span class="breakdown-count">{{ row.count }}</span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" description="No vehicles yet" />
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="9">
          <!-- Alerts: the reason this page exists -->
          <a-card size="small" style="margin-bottom: 16px">
            <template #title>
              <span class="alert-title">
                <WarningOutlined :style="{ color: alerts.length ? '#d97706' : undefined }" />
                Needs attention
              </span>
            </template>
            <template #extra>
              <a-tag v-if="overdueCount" color="error">{{ overdueCount }} overdue</a-tag>
            </template>

            <div v-if="alerts.length" class="alert-list">
              <button
                v-for="(alert, i) in alerts" :key="`${alert.kind}-${alert.vehicle_id}-${i}`"
                type="button" class="alert-row"
                @click="$router.push(`/fleet/vehicles/${alert.vehicle_id}`)"
              >
                <span class="alert-dot" :class="alert.days < 0 ? 'overdue' : 'soon'"></span>
                <span class="alert-body">
                  <span class="alert-vehicle">{{ alert.vehicle_name }}</span>
                  <span class="alert-meta">{{ alert.label }} · {{ date(alert.date) }}</span>
                </span>
                <a-tag :color="alert.days < 0 ? 'error' : 'warning'">
                  {{ alert.days < 0 ? `${Math.abs(alert.days)}d overdue` : `${alert.days}d` }}
                </a-tag>
              </button>
            </div>
            <a-empty
              v-else :image="simpleEmptyImage"
              description="Nothing due in the next 30 days"
              style="padding: 20px 0"
            />
          </a-card>

          <a-card size="small" title="Recent maintenance">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/fleet/maintenance')">View all</a-button>
            </template>
            <a-list :data-source="data.recent_maintenance || []" size="small">
              <template #renderItem="{ item }">
                <a-list-item>
                  <a-list-item-meta :description="`${item.vehicle_name} · ${date(item.service_date)}`">
                    <template #title>
                      <span class="maint-title">
                        {{ item.title }}
                        <a-tag :color="optionOf(MAINTENANCE_TYPES, item.type).color">
                          {{ labelOf(MAINTENANCE_TYPES, item.type) }}
                        </a-tag>
                      </span>
                    </template>
                  </a-list-item-meta>
                  <span class="maint-cost">{{ money(item.cost) }}</span>
                </a-list-item>
              </template>
              <template #empty>
                <a-empty :image="simpleEmptyImage" description="No maintenance recorded" />
              </template>
            </a-list>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Fleet dashboard — one GET (fleet/dashboard) feeding every panel, because the
 * numbers have to agree with each other; six separate endpoints would let the
 * tiles drift apart mid-render.
 *
 * The KPI tiles are buttons: each one navigates to the filtered list it counts,
 * so a number on screen is always one click from the rows behind it.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import {
  PlusOutlined, UnorderedListOutlined, CarOutlined, CheckCircleOutlined,
  ToolOutlined, UserOutlined, FireOutlined, DollarOutlined, WarningOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { VEHICLE_TYPES, MAINTENANCE_TYPES, labelOf, optionOf } from './fleetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const auth = useAuthStore();
const { money, date } = useFormat();

const canAdd = computed(() => auth.can('fleet_vehicles_add'));
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const data = ref({});
const loading = ref(false);

const alerts = computed(() => data.value.alerts || []);
const overdueCount = computed(() => alerts.value.filter(a => a.days < 0).length);

/** Type counts as percentages of the fleet, biggest first. */
const composition = computed(() => {
  const rows = data.value.by_type || [];
  const total = rows.reduce((sum, r) => sum + Number(r.count || 0), 0);
  if (!total) return [];
  return [...rows]
    .sort((a, b) => b.count - a.count)
    .map(r => ({ ...r, percent: Math.round((r.count / total) * 100) }));
});

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('fleet/dashboard');
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the fleet dashboard'));
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
.kpi--static {
  cursor: default;
}
.kpi--static:hover {
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
.kpi-ic--ok {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.12);
}
.kpi-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.kpi-ic--info {
  color: #0891b2;
  background: rgba(8, 145, 178, 0.12);
}
.kpi-ic--fuel {
  color: #ea580c;
  background: rgba(234, 88, 12, 0.12);
}
.kpi-ic--cost {
  color: #4f46e5;
  background: rgba(79, 70, 229, 0.12);
}
.kpi-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.kpi-value {
  font-size: 18px;
  font-weight: 600;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.kpi-label {
  font-size: 12px;
  opacity: 0.6;
}

/* Fleet composition */
.breakdown {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.breakdown-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.breakdown-label {
  width: 90px;
  flex: none;
  font-size: 13px;
}
.breakdown-bar {
  flex: 1;
  margin: 0;
}
.breakdown-count {
  width: 32px;
  flex: none;
  text-align: right;
  font-weight: 600;
  font-size: 13px;
}

/* Alerts */
.alert-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.alert-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 340px;
  overflow-y: auto;
}
.alert-row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 6px;
  border: 0;
  border-radius: 9px;
  background: none;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  transition: background 0.15s ease;
}
.alert-row:hover {
  background: rgba(128, 128, 128, 0.1);
}
.alert-dot {
  width: 8px;
  height: 8px;
  flex: none;
  border-radius: 50%;
}
.alert-dot.overdue {
  background: #ff4d4f;
}
.alert-dot.soon {
  background: #faad14;
}
.alert-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.alert-vehicle {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.alert-meta {
  font-size: 12px;
  opacity: 0.6;
}
.maint-title {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.maint-cost {
  font-weight: 600;
  white-space: nowrap;
}
</style>
