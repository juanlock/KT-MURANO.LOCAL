<template>
  <div class="page">
    <PageHeader
      :title="vehicle ? vehicle.name : 'Vehicle'"
      :subtitle="vehicle ? [vehicle.make, vehicle.model, vehicle.year].filter(Boolean).join(' · ') : ''"
      :breadcrumb="['Fleet Management', 'Vehicles', vehicle ? vehicle.plate_number : '']"
    >
      <template #actions>
        <a-button @click="$router.push('/fleet/vehicles')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button v-if="canEdit" type="primary" @click="$router.push(`/fleet/vehicles/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <template v-else-if="vehicle">
      <a-row :gutter="16">
        <!-- Identity card -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" style="margin-bottom: 16px" :body-style="{ padding: 0 }">
            <div class="hero">
              <img v-if="vehicle.image_url" :src="vehicle.image_url" :alt="vehicle.name" />
              <span v-else class="hero-empty">
                <component :is="typeIcon(vehicle.type)" :size="52" :stroke-width="1.4" />
              </span>
            </div>
            <div class="hero-body">
              <div class="hero-head">
                <span class="hero-plate">{{ vehicle.plate_number }}</span>
                <a-tag :color="optionOf(VEHICLE_STATUSES, vehicle.status).color">
                  {{ labelOf(VEHICLE_STATUSES, vehicle.status) }}
                </a-tag>
              </div>

              <a-descriptions :column="1" size="small" class="hero-desc">
                <a-descriptions-item label="Type">
                  <span class="desc-type">
                    <component :is="typeIcon(vehicle.type)" :size="15" :stroke-width="1.7" />
                    {{ labelOf(VEHICLE_TYPES, vehicle.type) }}
                  </span>
                </a-descriptions-item>
                <a-descriptions-item label="Fuel">{{ labelOf(FUEL_TYPES, vehicle.fuel_type) }}</a-descriptions-item>
                <a-descriptions-item label="Odometer">{{ number(vehicle.odometer, 0) }}</a-descriptions-item>
                <a-descriptions-item label="Driver">{{ vehicle.driver_name || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Warehouse')">{{ vehicle.warehouse_name || '—' }}</a-descriptions-item>
                <a-descriptions-item label="VIN">{{ vehicle.vin || '—' }}</a-descriptions-item>
                <a-descriptions-item label="Purchased">
                  {{ vehicle.purchase_date ? date(vehicle.purchase_date) : '—' }}
                  <template v-if="vehicle.purchase_price"> · {{ money(vehicle.purchase_price) }}</template>
                </a-descriptions-item>
              </a-descriptions>

              <p v-if="vehicle.notes" class="hero-notes">{{ vehicle.notes }}</p>
            </div>
          </a-card>

          <a-card size="small" title="Compliance">
            <div class="renewals">
              <div v-for="r in renewals" :key="r.key" class="renewal">
                <span class="renewal-label">{{ r.label }}</span>
                <span class="renewal-date">{{ r.date ? date(r.date) : 'Not set' }}</span>
                <a-tag v-if="r.tone" :color="r.tone.color">{{ r.tone.text }}</a-tag>
                <a-tag v-else-if="r.date" color="success">OK</a-tag>
                <a-tag v-else color="default">—</a-tag>
              </div>
            </div>
            <p v-if="vehicle.insurance_provider || vehicle.insurance_policy" class="policy">
              {{ [vehicle.insurance_provider, vehicle.insurance_policy].filter(Boolean).join(' · ') }}
            </p>
          </a-card>
        </a-col>

        <!-- Numbers + logs -->
        <a-col :xs="24" :lg="16">
          <div class="stats">
            <div class="stat">
              <span class="stat-label">Total cost</span>
              <span class="stat-value">{{ money(stats.total_cost || 0) }}</span>
              <span class="stat-note">fuel + maintenance</span>
            </div>
            <div class="stat">
              <span class="stat-label">Distance logged</span>
              <span class="stat-value">{{ number(stats.distance || 0, 0) }}</span>
              <span class="stat-note">from fuel readings</span>
            </div>
            <div class="stat">
              <span class="stat-label">Cost / distance</span>
              <span class="stat-value">{{ stats.cost_per_distance !== null ? money(stats.cost_per_distance) : '—' }}</span>
              <span class="stat-note">per unit driven</span>
            </div>
            <div class="stat">
              <span class="stat-label">Efficiency</span>
              <span class="stat-value">{{ stats.efficiency !== null ? number(stats.efficiency, 2) : '—' }}</span>
              <span class="stat-note">distance per unit of fuel</span>
            </div>
          </div>

          <a-card size="small">
            <a-tabs v-model:activeKey="tab" @change="onTabChange">
              <a-tab-pane key="maintenance">
                <template #tab>
                  Maintenance <a-badge :count="stats.maintenance_count || 0" :number-style="badgeStyle" />
                </template>
                <a-table
                  :columns="maintenanceColumns" :data-source="maintenance" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'type'">
                      <a-tag :color="optionOf(MAINTENANCE_TYPES, record.type).color">
                        {{ labelOf(MAINTENANCE_TYPES, record.type) }}
                      </a-tag>
                    </template>
                    <template v-else-if="column.key === 'status'">
                      <a-tag :color="optionOf(MAINTENANCE_STATUSES, record.status).color">
                        {{ labelOf(MAINTENANCE_STATUSES, record.status) }}
                      </a-tag>
                    </template>
                    <template v-else-if="column.key === 'service_date'">{{ date(record.service_date) }}</template>
                    <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
                    <template v-else-if="column.key === 'odometer'">
                      {{ record.odometer ? number(record.odometer, 0) : '—' }}
                    </template>
                  </template>
                  <template #emptyText>
                    <a-empty :image="simpleEmptyImage" description="No maintenance recorded" />
                  </template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="fuel">
                <template #tab>
                  Fuel <a-badge :count="stats.fuel_count || 0" :number-style="badgeStyle" />
                </template>
                <a-table
                  :columns="fuelColumns" :data-source="fuelLogs" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'log_date'">{{ date(record.log_date) }}</template>
                    <template v-else-if="column.key === 'odometer'">{{ number(record.odometer, 0) }}</template>
                    <template v-else-if="column.key === 'quantity'">{{ number(record.quantity, 2) }}</template>
                    <template v-else-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
                    <template v-else-if="column.key === 'total_cost'">{{ money(record.total_cost) }}</template>
                    <template v-else-if="column.key === 'full_tank'">
                      <a-tag :color="record.full_tank ? 'success' : 'default'">
                        {{ record.full_tank ? 'Full' : 'Partial' }}
                      </a-tag>
                    </template>
                  </template>
                  <template #emptyText>
                    <a-empty :image="simpleEmptyImage" description="No fuel logged" />
                  </template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="assignments">
                <template #tab>
                  Assignments <a-badge :count="stats.trip_count || 0" :number-style="badgeStyle" />
                </template>
                <a-table
                  :columns="assignmentColumns" :data-source="assignments" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'status'">
                      <a-tag :color="optionOf(ASSIGNMENT_STATUSES, record.status).color">
                        {{ labelOf(ASSIGNMENT_STATUSES, record.status) }}
                      </a-tag>
                    </template>
                    <template v-else-if="column.key === 'start_date'">{{ dateTime(record.start_date) }}</template>
                    <template v-else-if="column.key === 'end_date'">
                      {{ record.end_date ? dateTime(record.end_date) : '—' }}
                    </template>
                    <template v-else-if="column.key === 'distance'">
                      {{ record.distance !== null ? number(record.distance, 0) : '—' }}
                    </template>
                  </template>
                  <template #emptyText>
                    <a-empty :image="simpleEmptyImage" description="No assignments recorded" />
                  </template>
                </a-table>
              </a-tab-pane>
            </a-tabs>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <a-empty v-else description="Vehicle not found" style="padding: 64px 0" />
  </div>
</template>

<script setup>
/**
 * One vehicle: identity, compliance, lifetime numbers and its three logs.
 *
 * Log tabs load lazily — opening a vehicle shouldn't pull three tables the user
 * may never look at. Each tab fetches its own slice, capped at the most recent
 * 50 entries; the full history lives on the dedicated log pages.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import {
  VEHICLE_TYPES, VEHICLE_STATUSES, FUEL_TYPES, MAINTENANCE_TYPES,
  MAINTENANCE_STATUSES, ASSIGNMENT_STATUSES, labelOf, optionOf, expiryTone, typeIcon,
} from './fleetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const auth = useAuthStore();
const { money, number, date, dateTime } = useFormat();

const id = computed(() => route.params.id);
const canEdit = computed(() => auth.can('fleet_vehicles_edit'));
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: '#6d28d9' };

const vehicle = ref(null);
const loading = ref(false);
const tab = ref('maintenance');
const tabLoading = ref(false);

const maintenance = ref([]);
const fuelLogs = ref([]);
const assignments = ref([]);
const loadedTabs = ref([]);

const stats = computed(() => vehicle.value?.stats || {});

const renewals = computed(() => {
  const v = vehicle.value;
  if (!v) return [];
  return [
    { key: 'insurance', label: 'Insurance', date: v.insurance_expiry, days: v.days_to_insurance },
    { key: 'registration', label: 'Registration', date: v.registration_expiry, days: v.days_to_registration },
    { key: 'inspection', label: 'Inspection', date: v.inspection_expiry, days: v.days_to_inspection },
  ].map(r => ({ ...r, tone: expiryTone(r.days) }));
});

const maintenanceColumns = [
  { title: 'Date', key: 'service_date', dataIndex: 'service_date', width: 110 },
  { title: 'Work', dataIndex: 'title', key: 'title' },
  { title: 'Type', key: 'type', dataIndex: 'type', width: 110 },
  { title: 'Odometer', key: 'odometer', dataIndex: 'odometer', width: 100 },
  { title: 'Vendor', dataIndex: 'vendor', key: 'vendor', width: 140 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 120 },
  { title: 'Cost', key: 'cost', dataIndex: 'cost', width: 110 },
];

const fuelColumns = [
  { title: 'Date', key: 'log_date', dataIndex: 'log_date', width: 110 },
  { title: 'Odometer', key: 'odometer', dataIndex: 'odometer', width: 100 },
  { title: 'Quantity', key: 'quantity', dataIndex: 'quantity', width: 100 },
  { title: 'Unit price', key: 'unit_price', dataIndex: 'unit_price', width: 110 },
  { title: 'Total', key: 'total_cost', dataIndex: 'total_cost', width: 110 },
  { title: 'Station', dataIndex: 'station', key: 'station' },
  { title: 'Tank', key: 'full_tank', width: 90 },
];

const assignmentColumns = [
  { title: 'Driver', dataIndex: 'driver_name', key: 'driver_name' },
  { title: 'From', key: 'start_date', dataIndex: 'start_date', width: 150 },
  { title: 'To', key: 'end_date', dataIndex: 'end_date', width: 150 },
  { title: 'Distance', key: 'distance', dataIndex: 'distance', width: 100 },
  { title: 'Purpose', dataIndex: 'purpose', key: 'purpose' },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 110 },
];

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`fleet/vehicles/${id.value}`);
    vehicle.value = data?.vehicle || null;
    if (vehicle.value) loadTab('maintenance');
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this vehicle'));
  } finally {
    loading.value = false;
  }
}

function onTabChange(key) {
  loadTab(key);
}

async function loadTab(key) {
  if (loadedTabs.value.includes(key)) return;

  const endpoints = {
    maintenance: ['fleet/maintenances', 'maintenances', maintenance],
    fuel: ['fleet/fuel-logs', 'fuel_logs', fuelLogs],
    assignments: ['fleet/assignments', 'assignments', assignments],
  };
  const entry = endpoints[key];
  if (!entry) return;
  const [endpoint, rowsKey, target] = entry;

  tabLoading.value = true;
  try {
    const data = await http.get(endpoint, { vehicle_id: id.value, limit: 50, page: 1 });
    target.value = data?.[rowsKey] || [];
    loadedTabs.value.push(key);
  } catch (e) {
    // A missing log permission 403s here; the tab just stays empty.
    target.value = [];
  } finally {
    tabLoading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.hero {
  height: 180px;
  background: rgba(128, 128, 128, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.hero img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.hero-empty {
  font-size: 48px;
  opacity: 0.25;
}
.hero-body {
  padding: 14px 16px 8px;
}
.hero-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
}
.hero-plate {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 14px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 7px;
  background: rgba(128, 128, 128, 0.16);
}
.desc-type {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.hero-desc :deep(.ant-descriptions-item) {
  padding-bottom: 6px !important;
}
.hero-notes {
  margin: 10px 0 6px;
  padding-top: 10px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
  font-size: 12.5px;
  opacity: 0.7;
  white-space: pre-wrap;
}
.renewals {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.renewal {
  display: flex;
  align-items: center;
  gap: 8px;
}
.renewal-label {
  width: 96px;
  flex: none;
  font-size: 13px;
}
.renewal-date {
  flex: 1;
  font-size: 13px;
  opacity: 0.7;
}
.policy {
  margin: 12px 0 0;
  padding-top: 10px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
  font-size: 12px;
  opacity: 0.6;
}

/* Lifetime numbers */
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.stat-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.stat-value {
  font-size: 18px;
  font-weight: 600;
  line-height: 1.25;
}
.stat-note {
  font-size: 11.5px;
  opacity: 0.5;
}
</style>
