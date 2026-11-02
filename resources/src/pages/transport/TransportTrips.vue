<template>
  <div class="page">
    <PageHeader :title="es ? 'Viajes' : 'Trips'" :breadcrumb="[es ? 'Transporte' : 'Transport', es ? 'Viajes' : 'Trips']">
      <template #actions>
        <a-button v-if="canManage" type="primary" @click="openForm(null)"><PlusOutlined /> {{ es ? 'Programar viaje' : 'Schedule a trip' }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="tt-filters">
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" allow-all compact @change="reload" />
        <a-select v-model:value="filters.route_id" :options="routeOptions" allow-clear show-search option-filter-prop="label" style="width: 220px" :placeholder="es ? 'Todas las rutas' : 'All routes'" @change="reload" />
        <a-select v-model:value="filters.vehicle_id" :options="vehicleOptions" allow-clear show-search option-filter-prop="label" style="width: 200px" :placeholder="es ? 'Todos los vehículos' : 'All vehicles'" @change="reload" />
        <a-select v-model:value="filters.status" :options="statusOptions" allow-clear style="width: 160px" :placeholder="es ? 'Todos los estados' : 'All statuses'" @change="reload" />
        <span class="tt-muted">{{ total }} {{ es ? 'viajes' : 'trips' }}</span>
      </div>
      <a-table :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle" :scroll="{ x: 'max-content' }"
        :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: true }" @change="onTable">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'departure'"><b>{{ fmt(record.departure_at) }}</b><div v-if="record.arrival_at" class="tt-muted">→ {{ fmt(record.arrival_at) }}</div></template>
          <template v-else-if="column.key === 'crew'">
            <div v-for="c in record.crew" :key="c.employee_id" class="tt-crew">
              <a-tag :color="c.role === 'pilot' ? 'blue' : 'magenta'">{{ c.role === 'pilot' ? (es ? 'Piloto' : 'Pilot') : (es ? 'Azafata' : 'Hostess') }}</a-tag>{{ c.name }}
            </div>
          </template>
          <template v-else-if="column.key === 'status'"><a-tag :color="STATUS_COLOR[record.status]">{{ statusLabel(record.status) }}</a-tag></template>
          <template v-else-if="column.key === 'actions'">
            <a-space v-if="canManage" :size="0">
              <a-button type="text" size="small" @click="openForm(record)"><EditOutlined /></a-button>
              <a-popconfirm :title="es ? '¿Eliminar este viaje?' : 'Delete this trip?'" @confirm="remove(record)">
                <a-button type="text" size="small" danger><DeleteOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="formOpen" :title="editing ? (es ? 'Editar viaje' : 'Edit trip') : (es ? 'Programar viaje' : 'Schedule a trip')" :width="680"
      :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="save">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="12">
            <a-form-item :label="es ? 'Ruta *' : 'Route *'"><a-select v-model:value="form.route_id" :options="routeOptions" show-search option-filter-prop="label" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="es ? 'Vehículo *' : 'Vehicle *'" :extra="!vehicleOptions.length ? (es ? 'Agregue los buses en Flota › Vehículos.' : 'Add the buses in Fleet › Vehicles.') : ''">
              <a-select v-model:value="form.vehicle_id" :options="vehicleOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="es ? 'Salida *' : 'Departure *'"><a-date-picker v-model:value="form.departure_at" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="es ? 'Llegada' : 'Arrival'"><a-date-picker v-model:value="form.arrival_at" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="es ? 'Estado' : 'Status'"><a-select v-model:value="form.status" :options="statusOptions" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="es ? 'Pilotos (2) *' : 'Pilots (2) *'" :extra="!pilotOptions.length ? (es ? 'Marque a los pilotos en RR. HH. › Empleados › Tipo de personal.' : 'Mark the pilots in HRM › Employees › Staff type.') : ''">
              <a-select v-model:value="form.pilots" mode="multiple" :max-count="2" :options="pilotOptions" option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="es ? 'Azafatas (1 o 2) *' : 'Hostesses (1 or 2) *'">
              <a-select v-model:value="form.hostesses" mode="multiple" :max-count="2" :options="hostessOptions" option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="es ? 'Nota' : 'Note'" style="margin-bottom: 0"><a-textarea v-model:value="form.notes" :rows="2" /></a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transport -> Trips (2026-11-01): each trip = a route, a vehicle, 2 pilots and 1 or 2
 * hostesses (TransportController checks the crew and that nobody / no vehicle is on
 * two trips at once). Completed trips are what the per-trip payroll pays.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import { useListState } from '../../composables/useListState';
import { sortByLabel } from '../../lib/filterOptions';

const auth = useAuthStore();
const es = computed(() => useUiStore().locale === 'es');
const canManage = computed(() => auth.can('transport_manage'));

const STATUS = [['scheduled', 'Scheduled', 'Programado'], ['in_progress', 'On the road', 'En ruta'], ['completed', 'Completed', 'Completado'], ['cancelled', 'Cancelled', 'Cancelado']];
const STATUS_COLOR = { scheduled: 'blue', in_progress: 'orange', completed: 'green', cancelled: 'default' };
const statusLabel = s => { const x = STATUS.find(r => r[0] === s); return x ? (es.value ? x[2] : x[1]) : s; };
const statusOptions = computed(() => STATUS.map(s => ({ value: s[0], label: es.value ? s[2] : s[1] })));
const fmt = d => (d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '');

const meta = ref({ routes: [], vehicles: [], crew: [] });
const routeOptions = computed(() => sortByLabel(meta.value.routes.map(r => ({ value: r.id, label: r.label }))));
const vehicleOptions = computed(() => sortByLabel(meta.value.vehicles.map(v => ({ value: v.id, label: v.label }))));
const pilotOptions = computed(() => sortByLabel(meta.value.crew.filter(c => c.staff_type === 'pilot').map(c => ({ value: c.id, label: c.name }))));
const hostessOptions = computed(() => sortByLabel(meta.value.crew.filter(c => c.staff_type === 'hostess').map(c => ({ value: c.id, label: c.name }))));

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(20);
const range = ref(null);
const filters = ref({ route_id: undefined, vehicle_id: undefined, status: undefined });
useListState('transport-trips', { page, perPage, range, filters });

const columns = computed(() => [
  { title: es.value ? 'Salida' : 'Departure', key: 'departure', width: 170 },
  { title: es.value ? 'Ruta' : 'Route', dataIndex: 'route', key: 'route' },
  { title: es.value ? 'Vehículo' : 'Vehicle', dataIndex: 'vehicle', key: 'vehicle' },
  { title: es.value ? 'Tripulación' : 'Crew', key: 'crew' },
  { title: es.value ? 'Estado' : 'Status', key: 'status', width: 120 },
  { title: '', key: 'actions', width: 90 },
]);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('transport/trips', { ...filters.value, from: range.value?.[0], to: range.value?.[1], page: page.value, limit: perPage.value });
    rows.value = res.trips;
    total.value = res.totalRows;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}
function reload() { page.value = 1; load(); }
function onTable(p) { page.value = p.current; perPage.value = p.pageSize; load(); }

// ---- form
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const empty = () => ({ route_id: undefined, vehicle_id: undefined, departure_at: dayjs().add(1, 'hour').startOf('hour').format('YYYY-MM-DD HH:mm:ss'), arrival_at: null, status: 'scheduled', pilots: [], hostesses: [], notes: '' });
const form = ref(empty());
function openForm(r) {
  editing.value = r;
  form.value = r
    ? { route_id: r.route_id, vehicle_id: r.vehicle_id, departure_at: r.departure_at, arrival_at: r.arrival_at, status: r.status, notes: r.notes || '',
        pilots: r.crew.filter(c => c.role === 'pilot').map(c => c.employee_id), hostesses: r.crew.filter(c => c.role === 'hostess').map(c => c.employee_id) }
    : empty();
  formOpen.value = true;
}
async function save() {
  saving.value = true;
  try {
    if (editing.value) await http.put(`transport/trips/${editing.value.id}`, form.value);
    else await http.post('transport/trips', form.value);
    message.success(es.value ? 'Guardado.' : 'Saved.');
    formOpen.value = false;
    load();
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message);
  } finally {
    saving.value = false;
  }
}
async function remove(r) {
  try { await http.delete(`transport/trips/${r.id}`); load(); } catch (e) { message.error(e?.data?.message || e.message); }
}

onMounted(async () => {
  try { meta.value = await http.get('transport/meta'); } catch (e) { /* empty pickers */ }
  load();
});
</script>

<style scoped>
.tt-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px; }
.tt-muted { opacity: 0.6; font-size: 12px; }
.tt-crew { white-space: nowrap; line-height: 1.9; }
</style>
