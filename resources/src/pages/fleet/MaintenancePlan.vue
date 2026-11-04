<template>
  <div class="page">
    <PageHeader :title="es ? 'Plan de mantenimiento' : 'Maintenance plan'" :breadcrumb="[es ? 'Flota' : 'Fleet', es ? 'Plan de mantenimiento' : 'Maintenance plan']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)"><PlusOutlined /> {{ es ? 'Nueva tarea' : 'New task' }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="mp-filters">
        <a-select v-model:value="vehicleId" :options="vehicleOptions" allow-clear show-search option-filter-prop="label" style="width: 240px" :placeholder="es ? 'Todos los vehículos' : 'All vehicles'" @change="load" />
        <a-radio-group v-model:value="stateFilter" button-style="solid" size="small">
          <a-radio-button value="">{{ es ? 'Todas' : 'All' }} ({{ rows.length }})</a-radio-button>
          <a-radio-button value="overdue">{{ es ? 'Vencidas' : 'Overdue' }} ({{ count('overdue') }})</a-radio-button>
          <a-radio-button value="soon">{{ es ? 'Por vencer' : 'Due soon' }} ({{ count('soon') }})</a-radio-button>
        </a-radio-group>
      </div>
      <a-table :columns="columns" :data-source="shown" :loading="loading" row-key="id" size="middle" :pagination="false" :scroll="{ x: 'max-content' }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'vehicle'"><b>{{ record.vehicle }}</b><div class="mp-muted">{{ record.plate_number }}</div></template>
          <template v-else-if="column.key === 'every'">
            <span v-if="record.every_km">{{ Number(record.every_km).toLocaleString() }} km</span>
            <span v-if="record.every_km && record.every_days"> · </span>
            <span v-if="record.every_days">{{ record.every_days }} {{ es ? 'días' : 'days' }}</span>
          </template>
          <template v-else-if="column.key === 'next'">
            <div v-if="record.km_left != null">{{ record.km_left > 0 ? `${es ? 'faltan' : ''} ${record.km_left.toLocaleString()} km` : `${es ? 'pasado por' : 'over by'} ${Math.abs(record.km_left).toLocaleString()} km` }}</div>
            <div v-if="record.days_left != null">{{ record.next_date }} ({{ record.days_left >= 0 ? `${es ? 'en' : 'in'} ${record.days_left} d` : `${es ? 'hace' : ''} ${Math.abs(record.days_left)} d` }})</div>
          </template>
          <template v-else-if="column.key === 'state'"><a-tag :color="STATE[record.state].color">{{ es ? STATE[record.state].es : STATE[record.state].en }}</a-tag></template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="4">
              <PermLink :to="{ path: '/fleet/maintenance', query: { vehicle_id: record.vehicle_id, plan_id: record.id, title: record.title, type: record.type } }" permission="fleet_maintenance">{{ es ? 'Registrar servicio' : 'Record service' }}</PermLink>
              <a-button type="text" size="small" @click="openForm(record)"><EditOutlined /></a-button>
              <a-popconfirm :title="es ? '¿Eliminar esta tarea?' : 'Delete this task?'" @confirm="remove(record)">
                <a-button type="text" size="small" danger><DeleteOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="formOpen" :title="form.id ? (es ? 'Editar tarea' : 'Edit task') : (es ? 'Nueva tarea' : 'New task')" :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="save">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="24"><a-form-item :label="es ? 'Vehículo *' : 'Vehicle *'"><a-select v-model:value="form.vehicle_id" :options="vehicleOptions" show-search option-filter-prop="label" /></a-form-item></a-col>
          <a-col :xs="24" :md="16"><a-form-item :label="es ? 'Tarea *' : 'Task *'"><a-input v-model:value="form.title" :maxlength="150" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="es ? 'Tipo' : 'Type'"><a-select v-model:value="form.type" :options="MAINTENANCE_TYPES" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="es ? 'Cada (km)' : 'Every (km)'"><a-input-number v-model:value="form.every_km" :min="1" :step="1000" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="es ? 'Cada (días)' : 'Every (days)'"><a-input-number v-model:value="form.every_days" :min="1" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="es ? 'Último, en km' : 'Last, at km'" :extra="es ? 'Vacío = el odómetro de hoy.' : 'Empty = today\'s odometer.'"><a-input-number v-model:value="form.last_km" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="es ? 'Último, fecha' : 'Last, date'"><a-date-picker v-model:value="form.last_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Fleet -> Maintenance plan (2026-11-04): what each vehicle needs, every so many km and / or days. Overdue or due soon =
 * alerts on the fleet and owner dashboards; a finished job of the plan restarts its count.
 */
import { computed, onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { MAINTENANCE_TYPES } from './fleetOptions';
import { sortByLabel } from '../../lib/filterOptions';
import { useUiStore } from '../../stores/ui';

const es = computed(() => useUiStore().locale === 'es');
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };
const STATE = {
  ok: { color: 'green', en: 'OK', es: 'Al día' },
  soon: { color: 'gold', en: 'Due soon', es: 'Por vencer' },
  overdue: { color: 'red', en: 'Overdue', es: 'Vencida' },
};

const rows = ref([]);
const vehicles = ref([]);
const vehicleId = ref(undefined);
const stateFilter = ref('');
const loading = ref(false);
const saving = ref(false);
const vehicleOptions = computed(() => sortByLabel(vehicles.value.map(v => ({ value: v.id, label: v.label }))));
const shown = computed(() => (stateFilter.value ? rows.value.filter(r => r.state === stateFilter.value) : rows.value));
const count = s => rows.value.filter(r => r.state === s).length;
const columns = computed(() => [
  { title: es.value ? 'Vehículo' : 'Vehicle', key: 'vehicle' },
  { title: es.value ? 'Tarea' : 'Task', dataIndex: 'title', key: 'title' },
  { title: es.value ? 'Cada' : 'Every', key: 'every' },
  { title: es.value ? 'Próximo' : 'Next', key: 'next' },
  { title: es.value ? 'Estado' : 'Status', key: 'state' },
  { title: '', key: 'actions' },
]);

async function load() {
  loading.value = true;
  try {
    rows.value = (await http.get('fleet/plans', { vehicle_id: vehicleId.value })).plans;
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
const formOpen = ref(false);
const form = ref({});
function openForm(r) {
  form.value = r ? { id: r.id, vehicle_id: r.vehicle_id, title: r.title, type: r.type, every_km: r.every_km, every_days: r.every_days, last_km: r.last_km != null ? Number(r.last_km) : null, last_date: r.last_date }
    : { id: null, vehicle_id: vehicleId.value, title: '', type: 'service', every_km: null, every_days: null, last_km: null, last_date: null };
  formOpen.value = true;
}
async function save() {
  saving.value = true;
  try {
    if (form.value.id) await http.put(`fleet/plans/${form.value.id}`, form.value); else await http.post('fleet/plans', form.value);
    formOpen.value = false;
    load();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}
async function remove(r) {
  try { await http.delete(`fleet/plans/${r.id}`); load(); } catch (e) { message.error(errorOf(e)); }
}

onMounted(async () => {
  try {
    const meta = await http.get('fleet/meta');
    vehicles.value = (meta.vehicles || []).map(v => ({ id: v.id, label: v.label || v.display_name || v.name }));
  } catch (e) { /* the picker stays empty */ }
  load();
});
</script>

<style scoped>
.mp-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 12px; }
.mp-muted { font-size: 12px; color: #64748b; }
</style>
