<template>
  <div class="page">
    <PageHeader
      :title="vehicle ? vehicle.name : $t('Vehicle')"
      :subtitle="vehicle ? [vehicle.make, vehicle.model, vehicle.year].filter(Boolean).join(' · ') : ''"
      :breadcrumb="['Fleet Management', 'Vehicles', vehicle ? vehicle.plate_number : '']"
    >
      <template #actions>
        <a-button @click="$goBack('/fleet/vehicles')">
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
                <a-descriptions-item :label="$t('type')">
                  <span class="desc-type">
                    <component :is="typeIcon(vehicle.type)" :size="15" :stroke-width="1.7" />
                    {{ labelOf(VEHICLE_TYPES, vehicle.type) }}
                  </span>
                </a-descriptions-item>
                <a-descriptions-item :label="$t('Fuel')">{{ labelOf(FUEL_TYPES, vehicle.fuel_type) }}</a-descriptions-item>
                <a-descriptions-item :label="$t('tx_Odometer_5c059')">{{ number(vehicle.odometer, 0) }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Driver')">{{ vehicle.driver_name || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Warehouse')">{{ vehicle.warehouse_name || '—' }}</a-descriptions-item>
                <a-descriptions-item label="VIN">{{ vehicle.vin || '—' }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Purchased')">
                  {{ vehicle.purchase_date ? date(vehicle.purchase_date) : '—' }}
                  <template v-if="vehicle.purchase_price"> · {{ money(vehicle.purchase_price) }}</template>
                </a-descriptions-item>
              </a-descriptions>

              <p v-if="vehicle.notes" class="hero-notes">{{ vehicle.notes }}</p>
            </div>
          </a-card>

          <a-card size="small" :title="$t('tx_Compliance_9c040')">
            <div class="renewals">
              <div v-for="r in renewals" :key="r.key" class="renewal">
                <span class="renewal-label">{{ r.label }}</span>
                <span class="renewal-date">{{ r.date ? date(r.date) : $t('Not_Set') }}</span>
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
              <span class="stat-label">{{ $t('TotalCost') }}</span>
              <span class="stat-value">{{ money(stats.total_cost || 0) }}</span>
              <span class="stat-note">{{ tr('fuel + maintenance', 'combustible + mantenimiento') }}</span>
            </div>
            <div class="stat">
              <span class="stat-label">{{ $t('tx_Distance_logged_60d62') }}</span>
              <span class="stat-value">{{ number(stats.distance || 0, 0) }}</span>
              <span class="stat-note">{{ stats.fuel_source === 'trips' || (!stats.fuel_count && stats.trip_count_run) ? tr('km of the trips run', 'km de los viajes realizados') : tr('from fuel readings', 'de las lecturas de combustible') }}</span>
            </div>
            <div class="stat">
              <span class="stat-label">{{ $t('Cost_per_distance') }}</span>
              <span class="stat-value">{{ stats.cost_per_distance !== null ? money(stats.cost_per_distance) : '—' }}</span>
              <span class="stat-note">{{ tr('per km driven', 'por km recorrido') }}</span>
            </div>
            <div class="stat">
              <span class="stat-label">{{ $t('Efficiency') }}</span>
              <span class="stat-value">{{ stats.efficiency !== null ? number(stats.efficiency, 2) : '—' }}</span>
              <span class="stat-note">{{ tr('km per unit of fuel (needs refuels)', 'km por unidad de combustible (requiere cargas)') }}</span>
            </div>
          </div>

          <a-card size="small">
            <a-tabs v-model:activeKey="tab" @change="onTabChange">
              <a-tab-pane key="maintenance">
                <template #tab>
                  {{ $t('Maintenance') }} <a-badge :count="stats.maintenance_count || 0" :number-style="badgeStyle" />
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
                    <a-empty :image="simpleEmptyImage" :description="$t('tx_No_maintenance_recorded_d5452')" />
                  </template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="fuel">
                <template #tab>
                  {{ $t('Fuel') }} <a-badge :count="stats.fuel_count || stats.trip_fuel_count || 0" :overflow-count="999" :number-style="badgeStyle" />
                </template>
                <!-- No refuels but trips with fuel (transport, 2026-11-14): the fuel of each trip, the same figure Profit by route uses. -->
                <template v-if="tripFuelMode">
                  <div class="trip-fuel-head">
                    <span>{{ tr('Fuel of the trips run', 'Combustible de los viajes realizados') }}: <b>{{ money(stats.fuel_cost || 0) }}</b></span>
                    <span class="muted">{{ stats.trip_fuel_count }} {{ tr('trips with fuel', 'viajes con combustible') }}<template v-if="avgTripFuel"> · {{ tr('average', 'promedio') }} {{ money(avgTripFuel) }} {{ tr('per trip', 'por viaje') }}</template> · {{ tr('latest 50', 'últimos 50') }}</span>
                    <a-tooltip :title="tr('This bus has no refuels recorded: its fuel is recorded on each trip (Daily departures or the trip itself). Litres, odometer and efficiency appear when refuels are recorded in Fleet -> Fuel.', 'Este bus no tiene cargas registradas: su combustible se registra en cada viaje (Salidas del día o el propio viaje). Litros, odómetro y rendimiento aparecen cuando se registran cargas en Flota -> Combustible.')">
                      <InfoCircleOutlined class="muted" />
                    </a-tooltip>
                  </div>
                  <a-table
                    :columns="tripFuelColumns" :data-source="tripFuel" :loading="tabLoading"
                    :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                  >
                    <template #bodyCell="{ column, record }">
                      <template v-if="column.key === 'departure_at'">{{ dateTime(record.departure_at) }}</template>
                      <template v-else-if="column.key === 'km'">{{ record.km !== null ? number(record.km, 0) : '—' }}</template>
                      <template v-else-if="column.key === 'fuel_cost'">{{ record.fuel_cost !== null ? money(record.fuel_cost) : '—' }}</template>
                      <template v-else-if="column.key === 'per_km'">{{ record.per_km !== null ? money(record.per_km) : '—' }}</template>
                    </template>
                  </a-table>
                </template>
                <a-table
                  v-else
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
                        {{ record.full_tank ? $t('tx_Full_bbd47') : $t('partial') }}
                      </a-tag>
                    </template>
                  </template>
                  <template #emptyText>
                    <a-empty :image="simpleEmptyImage" :description="$t('tx_No_fuel_logged_6253e')" />
                  </template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="assignments">
                <template #tab>
                  {{ $t('Assignments') }} <a-badge :count="stats.trip_count || 0" :number-style="badgeStyle" />
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
                    <a-empty :image="simpleEmptyImage" :description="$t('tx_No_assignments_recorded_a3892')" />
                  </template>
                </a-table>
              </a-tab-pane>
            </a-tabs>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <a-empty v-else :description="$t('tx_Vehicle_not_found_7c581')" style="padding: 64px 0" />
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
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
import { EditOutlined, ArrowLeftOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import { useUiStore } from '../../stores/ui';
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
const ui = useUiStore();
const tr = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
// A bus with no refuels but with trips that carry their fuel (2026-11-14).
const tripFuelMode = computed(() => !stats.value.fuel_count && (stats.value.trip_fuel_count || 0) > 0);
const tripFuel = ref([]);
const avgTripFuel = computed(() => (stats.value.trip_fuel_count ? (stats.value.fuel_cost || 0) / stats.value.trip_fuel_count : 0));
const tripFuelColumns = computed(() => [
  { title: tr('Departure', 'Salida'), key: 'departure_at', dataIndex: 'departure_at', width: 150 },
  { title: tr('Route', 'Ruta'), key: 'route', dataIndex: 'route' },
  { title: 'Km', key: 'km', dataIndex: 'km', width: 80, align: 'right' },
  { title: tr('Fuel', 'Combustible'), key: 'fuel_cost', dataIndex: 'fuel_cost', width: 120, align: 'right' },
  { title: tr('Per km', 'Por km'), key: 'per_km', dataIndex: 'per_km', width: 90, align: 'right' },
]);

const renewals = computed(() => {
  const v = vehicle.value;
  if (!v) return [];
  return [
    { key: 'insurance', label: tEs('Insurance', 'Insurance'), date: v.insurance_expiry, days: v.days_to_insurance },
    { key: 'registration', label: tEs('Owner_Alert_Registration', 'Registration'), date: v.registration_expiry, days: v.days_to_registration },
    { key: 'inspection', label: tEs('Owner_Alert_Inspection', 'Inspection'), date: v.inspection_expiry, days: v.days_to_inspection },
  ].map(r => ({ ...r, tone: expiryTone(r.days) }));
});

const maintenanceColumns = [
  { title: tEs('date', 'Date'), key: 'service_date', dataIndex: 'service_date', width: 110 },
  { title: tEs('tx_Work_0f926', 'Work'), dataIndex: 'title', key: 'title' },
  { title: tEs('type', 'Type'), key: 'type', dataIndex: 'type', width: 110 },
  { title: tEs('tx_Odometer_5c059', 'Odometer'), key: 'odometer', dataIndex: 'odometer', width: 100 },
  { title: tEs('tx_Vendor_ec9ce', 'Vendor'), dataIndex: 'vendor', key: 'vendor', width: 140 },
  { title: tEs('Serial_Status', 'Status'), key: 'status', dataIndex: 'status', width: 120 },
  { title: tEs('Cost', 'Cost'), key: 'cost', dataIndex: 'cost', width: 110 },
];

const fuelColumns = [
  { title: tEs('date', 'Date'), key: 'log_date', dataIndex: 'log_date', width: 110 },
  { title: tEs('tx_Odometer_5c059', 'Odometer'), key: 'odometer', dataIndex: 'odometer', width: 100 },
  { title: tEs('Quantity', 'Quantity'), key: 'quantity', dataIndex: 'quantity', width: 100 },
  { title: tEs('UnitPrice', 'Unit price'), key: 'unit_price', dataIndex: 'unit_price', width: 110 },
  { title: 'Total', key: 'total_cost', dataIndex: 'total_cost', width: 110 },
  { title: tEs('Tray', 'Station'), dataIndex: 'station', key: 'station' },
  { title: tEs('tx_Tank_e4419', 'Tank'), key: 'full_tank', width: 90 },
];

const assignmentColumns = [
  { title: tEs('Driver', 'Driver'), dataIndex: 'driver_name', key: 'driver_name' },
  { title: tEs('From', 'From'), key: 'start_date', dataIndex: 'start_date', width: 150 },
  { title: tEs('to', 'To'), key: 'end_date', dataIndex: 'end_date', width: 150 },
  { title: tEs('Distance', 'Distance'), key: 'distance', dataIndex: 'distance', width: 100 },
  { title: tEs('Purpose', 'Purpose'), dataIndex: 'purpose', key: 'purpose' },
  { title: tEs('Serial_Status', 'Status'), key: 'status', dataIndex: 'status', width: 110 },
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
    fuel: tripFuelMode.value ? [`fleet/vehicles/${id.value}/trip-fuel`, 'trips', tripFuel] : ['fleet/fuel-logs', 'fuel_logs', fuelLogs],
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
.trip-fuel-head { display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: center; font-size: 13px; margin-bottom: 8px; }
.trip-fuel-head .muted { opacity: 0.65; font-size: 12px; }
.stat-note {
  font-size: 11.5px;
  opacity: 0.5;
}
</style>
