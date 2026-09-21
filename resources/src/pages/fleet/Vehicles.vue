<template>
  <div class="page">
    <PageHeader title="Vehicles" :breadcrumb="['Fleet Management', 'Vehicles']">
      <template #actions>
        <a-button @click="$router.push('/fleet/dashboard')">
          <template #icon><DashboardOutlined /></template>
          Dashboard
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/fleet/vehicles/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value"
          placeholder="Search name, plate, VIN or model…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          placeholder="All statuses" :options="VEHICLE_STATUSES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.type" class="tb-item" allow-clear
          placeholder="All types" :options="VEHICLE_TYPES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.warehouse_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Warehouse')"
          :options="warehouseOptions" @change="crud.reload"
        />
        <a-tooltip title="Insurance, registration or inspection due within 30 days">
          <a-checkbox v-model:checked="filters.expiring" class="tb-check" @change="crud.reload">
            Needs attention
          </a-checkbox>
        </a-tooltip>
        <a-segmented v-model:value="view" :options="VIEW_OPTIONS" class="tb-view">
          <template #label="{ value, label }">
            <span class="seg-label">
              <AppstoreOutlined v-if="value === 'grid'" />
              <UnorderedListOutlined v-else />
              {{ label }}
            </span>
          </template>
        </a-segmented>
      </div>
    </a-card>

    <!-- Card view: a fleet is easier to recognise by photo and plate -->
    <template v-if="view === 'grid'">
      <a-spin :spinning="crud.loading.value">
        <div v-if="crud.rows.value.length" class="grid">
          <article
            v-for="v in crud.rows.value" :key="v.id"
            class="vcard" @click="open(v)"
          >
            <div class="vcard-media">
              <img v-if="v.image_url" :src="v.image_url" :alt="v.name" loading="lazy" />
              <!-- No photo: the type glyph at least says what kind of vehicle it is. -->
              <span v-else class="vcard-placeholder">
                <component :is="typeIcon(v.type)" :size="44" :stroke-width="1.5" />
              </span>
              <a-tag class="vcard-status" :color="optionOf(VEHICLE_STATUSES, v.status).color">
                {{ labelOf(VEHICLE_STATUSES, v.status) }}
              </a-tag>
              <span v-if="worstAlert(v)" class="vcard-alert" :class="worstAlert(v).level">
                {{ worstAlert(v).label }} · {{ worstAlert(v).text }}
              </span>
            </div>

            <div class="vcard-body">
              <div class="vcard-head">
                <h3 class="vcard-name" :title="v.name">{{ v.name }}</h3>
                <span class="vcard-plate">{{ v.plate_number }}</span>
              </div>
              <p class="vcard-sub">
                {{ [v.make, v.model, v.year].filter(Boolean).join(' · ') || labelOf(VEHICLE_TYPES, v.type) }}
              </p>

              <dl class="vcard-facts">
                <div>
                  <dt>Odometer</dt>
                  <dd>{{ number(v.odometer, 0) }}</dd>
                </div>
                <div>
                  <dt>Driver</dt>
                  <dd :title="v.driver_name || ''">{{ v.driver_name || '—' }}</dd>
                </div>
                <div>
                  <dt>{{ $t('Warehouse') }}</dt>
                  <dd :title="v.warehouse_name || ''">{{ v.warehouse_name || '—' }}</dd>
                </div>
              </dl>
            </div>

            <footer class="vcard-foot" @click.stop>
              <a-button type="text" size="small" @click="open(v)">
                <template #icon><EyeOutlined /></template>
              </a-button>
              <a-button v-if="canEdit" type="text" size="small" @click="$router.push(`/fleet/vehicles/${v.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button v-if="canDelete" type="text" size="small" danger @click="crud.remove(v, { label: v.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </footer>
          </article>
        </div>

        <a-card v-else>
          <a-empty :description="hasFilters ? 'No vehicles match these filters.' : 'No vehicles yet.'">
            <a-button v-if="canAdd && !hasFilters" type="primary" @click="$router.push('/fleet/vehicles/create')">
              <template #icon><PlusOutlined /></template>
              Add your first vehicle
            </a-button>
            <a-button v-else-if="hasFilters" @click="clearFilters">Clear filters</a-button>
          </a-empty>
        </a-card>
      </a-spin>

      <div v-if="crud.total.value > crud.limit.value" class="pager">
        <a-pagination
          :current="crud.page.value" :page-size="crud.limit.value" :total="crud.total.value"
          :page-size-options="['12', '24', '48', '96']" show-size-changer @change="onPageChange"
        />
      </div>
    </template>

    <!-- Table view -->
    <DataTable v-else :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="cell-v" @click="open(record)">
            <img v-if="record.image_url" class="cell-thumb" :src="record.image_url" :alt="record.name" loading="lazy" />
            <span v-else class="cell-thumb cell-thumb--empty">
              <component :is="typeIcon(record.type)" :size="18" :stroke-width="1.6" />
            </span>
            <span class="cell-text">
              <span class="cell-name">{{ record.name }}</span>
              <span class="cell-plate">{{ record.plate_number }}</span>
            </span>
          </button>
        </template>

        <template v-else-if="column.key === 'type'">
          <span class="cell-type">
            <component :is="typeIcon(record.type)" :size="15" :stroke-width="1.7" />
            {{ labelOf(VEHICLE_TYPES, record.type) }}
          </span>
        </template>

        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(VEHICLE_STATUSES, record.status).color">
            {{ labelOf(VEHICLE_STATUSES, record.status) }}
          </a-tag>
        </template>

        <template v-else-if="column.key === 'odometer'">
          {{ number(record.odometer, 0) }}
        </template>

        <template v-else-if="column.key === 'driver'">
          <span v-if="record.driver_name">{{ record.driver_name }}</span>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'renewals'">
          <a-space :size="4" wrap>
            <a-tooltip v-for="r in renewals(record)" :key="r.key" :title="`${r.label}: ${date(r.date)}`">
              <a-tag :color="r.tone.color">{{ r.label }} {{ r.tone.text }}</a-tag>
            </a-tooltip>
            <span v-if="!renewals(record).length" class="muted">—</span>
          </a-space>
        </template>

        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Open">
              <a-button type="text" size="small" @click="open(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/fleet/vehicles/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canDelete" :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Vehicle register. Two views over the SAME useCrudTable instance: photo cards
 * (how people recognise a vehicle) and a table (how people scan a fleet).
 *
 * Status/type filters can arrive as query params — the dashboard KPI tiles link
 * straight into a filtered list.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined,
  DashboardOutlined, AppstoreOutlined, UnorderedListOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { VEHICLE_TYPES, VEHICLE_STATUSES, labelOf, optionOf, expiryTone, typeIcon } from './fleetOptions';
import http from '../../lib/http';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { number, date } = useFormat();

const canAdd = computed(() => auth.can('fleet_vehicles_add'));
const canEdit = computed(() => auth.can('fleet_vehicles_edit'));
const canDelete = computed(() => auth.can('fleet_vehicles_delete'));

const view = ref('grid');
const VIEW_OPTIONS = [
  { value: 'grid', label: 'Cards' },
  { value: 'list', label: 'Table' },
];

const filters = reactive({
  status: route.query.status || undefined,
  type: undefined,
  warehouse_id: undefined,
  expiring: route.query.expiring === '1',
});

const crud = useCrudTable('fleet/vehicles', {
  rowsKey: 'vehicles',
  limit: 12,
  sortField: 'created_at',
  params: () => ({
    status: filters.status || '',
    type: filters.type || '',
    warehouse_id: filters.warehouse_id || '',
    expiring: filters.expiring ? 1 : '',
  }),
});

const hasFilters = computed(() =>
  !!filters.status || !!filters.type || !!filters.warehouse_id || filters.expiring || !!crud.search.value);

function clearFilters() {
  filters.status = undefined;
  filters.type = undefined;
  filters.warehouse_id = undefined;
  filters.expiring = false;
  crud.search.value = '';
  crud.reload();
}

function onPageChange(page, pageSize) {
  crud.page.value = page;
  crud.limit.value = pageSize;
  crud.fetchRows();
}

function open(vehicle) {
  router.push(`/fleet/vehicles/${vehicle.id}`);
}

const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const columns = computed(() => [
  { title: 'Vehicle', key: 'name', dataIndex: 'name', sorter: true },
  { title: 'Type', key: 'type', dataIndex: 'type', sorter: true, width: 110 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 130 },
  { title: 'Odometer', key: 'odometer', dataIndex: 'odometer', sorter: true, width: 110 },
  { title: 'Driver', key: 'driver', width: 160 },
  { title: 'Branch', dataIndex: 'warehouse_name', key: 'warehouse', width: 140 },
  { title: 'Renewals', key: 'renewals', width: 220 },
  { title: '', key: 'actions', width: 120 },
]);

/** Renewal dates that are due or overdue, as {label, date, tone}. */
function renewals(v) {
  return [
    { key: 'insurance', label: 'Insurance', days: v.days_to_insurance, date: v.insurance_expiry },
    { key: 'registration', label: 'Registration', days: v.days_to_registration, date: v.registration_expiry },
    { key: 'inspection', label: 'Inspection', days: v.days_to_inspection, date: v.inspection_expiry },
  ]
    .map(r => ({ ...r, tone: expiryTone(r.days) }))
    .filter(r => r.tone);
}

/** The single most urgent renewal, for the card ribbon. */
function worstAlert(v) {
  const list = renewals(v);
  if (!list.length) return null;
  const worst = list.reduce((a, b) => (a.days <= b.days ? a : b));
  return { label: worst.label, text: worst.tone.text, level: worst.tone.level };
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const data = await http.get('fleet/meta');
    warehouses.value = data?.warehouses || [];
  } catch (e) { /* filter stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 240px;
  min-width: 200px;
}
.tb-item {
  width: 160px;
}
.tb-check {
  white-space: nowrap;
}
.tb-view {
  margin-inline-start: auto;
}
.seg-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

/* ---------------- card grid ---------------- */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}
.vcard {
  display: flex;
  flex-direction: column;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 16px;
  overflow: hidden;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.12s ease;
}
.vcard:hover {
  border-color: rgba(109, 40, 217, 0.55);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.09);
  transform: translateY(-2px);
}
.vcard-media {
  position: relative;
  height: 150px;
  background: rgba(128, 128, 128, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}
.vcard-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.vcard-placeholder {
  font-size: 40px;
  opacity: 0.25;
}
.vcard-status {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-end: 10px;
  margin: 0;
}
.vcard-alert {
  position: absolute;
  inset-block-end: 0;
  inset-inline: 0;
  padding: 5px 10px;
  font-size: 11.5px;
  font-weight: 500;
  color: #fff;
}
.vcard-alert.overdue {
  background: rgba(255, 77, 79, 0.92);
}
.vcard-alert.soon {
  background: rgba(217, 119, 6, 0.92);
}
.vcard-body {
  padding: 12px 14px 4px;
  flex: 1;
}
.vcard-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}
.vcard-name {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.vcard-plate {
  flex: none;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11.5px;
  padding: 1px 6px;
  border-radius: 5px;
  background: rgba(128, 128, 128, 0.16);
}
.vcard-sub {
  margin: 2px 0 10px;
  font-size: 12.5px;
  opacity: 0.6;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.vcard-facts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin: 0;
}
.vcard-facts dt {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.5;
}
.vcard-facts dd {
  margin: 1px 0 0;
  font-size: 13px;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.vcard-foot {
  display: flex;
  justify-content: flex-end;
  gap: 2px;
  padding: 6px 10px 8px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

/* ---------------- table cells ---------------- */
.cell-v {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  min-width: 200px;
}
.cell-thumb {
  width: 40px;
  height: 32px;
  flex: none;
  border-radius: 7px;
  object-fit: cover;
  background: rgba(128, 128, 128, 0.14);
}
.cell-thumb--empty {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  opacity: 0.45;
}
.cell-type {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.cell-text {
  min-width: 0;
}
.cell-name {
  display: block;
  font-weight: 500;
}
.cell-plate {
  display: block;
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.cell-v:hover .cell-name {
  color: #6d28d9;
}

@media (max-width: 767px) {
  .tb-item,
  .tb-view {
    width: 100%;
    margin-inline-start: 0;
  }
}
</style>
