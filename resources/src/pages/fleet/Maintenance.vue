<template>
  <div class="page">
    <PageHeader :title="$t('Maintenance')" :breadcrumb="['Fleet Management', 'Maintenance']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('tx_Search_work_or_vendor_94927')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.vehicle_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('tx_All_vehicles_e54db')"
          :options="vehicleOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.type" class="tb-item" allow-clear
          :placeholder="$t('All_types')" :options="MAINTENANCE_TYPES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="$t('All_Status')" :options="MAINTENANCE_STATUSES" @change="crud.reload"
        />
        <PresetRangeFilter v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-tag v-if="totalCost" color="purple" class="tb-total">Total: {{ money(totalCost) }}</a-tag>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'vehicle'">
          <a class="link" @click="$router.push(`/fleet/vehicles/${record.vehicle_id}`)">{{ record.vehicle_name }}</a>
        </template>
        <template v-else-if="column.key === 'type'">
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
        <template v-else-if="column.key === 'odometer'">
          {{ record.odometer ? number(record.odometer, 0) : '—' }}
        </template>
        <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
        <template v-else-if="column.key === 'done_by'">
          <a-tag :color="record.performed_by === 'workshop' ? 'orange' : 'blue'">{{ record.performed_by === 'workshop' ? (es ? 'Taller' : 'Workshop') : (es ? 'En casa' : 'In house') }}</a-tag>
          <div class="muted">{{ record.performed_by === 'workshop' ? record.provider_name : record.mechanic_name }}</div>
        </template>
        <template v-else-if="column.key === 'next'">
          <div v-if="record.next_service_date || record.next_service_odometer" class="next">
            <span v-if="record.next_service_date">{{ date(record.next_service_date) }}</span>
            <span v-if="record.next_service_odometer" class="next-odo">
              @ {{ number(record.next_service_odometer, 0) }}
            </span>
          </div>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.title })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? $t('tx_Edit_maintenance_e0495') : $t('tx_Record_maintenance_add73')" :width="640"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('tx_Vehicle_8bcbd')" name="vehicle_id">
              <a-select
                v-model:value="form.vehicle_id" show-search option-filter-prop="label"
                :options="vehicleOptions" :placeholder="$t('tx_Select_a_vehicle_97294')" @change="onVehicleChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('tx_Work_301e1')" name="title">
              <a-input v-model:value="form.title" :placeholder="$t('tx_e_g_60_000_km_service_8ba8a')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('tx_Type_fa404')" name="type">
              <a-select v-model:value="form.type" :options="MAINTENANCE_TYPES" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('tx_Status_f553a')" name="status">
              <a-select v-model:value="form.status" :options="MAINTENANCE_STATUSES" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('tx_Date_3c56d')" name="service_date">
              <a-date-picker v-model:value="form.service_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('tx_Odometer_5c059')" name="odometer">
              <a-input-number v-model:value="form.odometer" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="form.parts?.length ? (es ? 'Mano de obra' : 'Labour') : 'Cost'" name="cost" :extra="form.parts?.length ? `${es ? 'Repuestos' : 'Parts'}: ${money(partsCost)}` : ''">
              <a-input-number v-model:value="form.cost" :min="0" :step="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="es ? 'Hecho por' : 'Done by'">
              <a-radio-group v-model:value="form.performed_by" button-style="solid" size="small">
                <a-radio-button value="in_house">{{ es ? 'En casa' : 'In house' }}</a-radio-button>
                <a-radio-button value="workshop">{{ es ? 'Taller' : 'Workshop' }}</a-radio-button>
              </a-radio-group>
            </a-form-item>
          </a-col>
          <a-col v-if="form.performed_by === 'workshop'" :xs="24" :md="12">
            <a-form-item :label="es ? 'Taller (proveedor) *' : 'Workshop (supplier) *'" name="provider_id"
              :extra="es ? 'Los talleres se registran en Proveedores.' : 'Workshops are added in Suppliers.'">
              <a-select v-model:value="form.provider_id" show-search option-filter-prop="label" :options="providerOptions" allow-clear />
            </a-form-item>
          </a-col>
          <a-col v-else :xs="24" :md="12">
            <a-form-item :label="es ? 'Mecánico' : 'Mechanic'">
              <a-select v-model:value="form.mechanic_id" show-search option-filter-prop="label" :options="mechanicOptions" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('tx_Vendor_garage_37de0')">
              <a-input v-model:value="form.vendor" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('tx_Next_service_date_b5d3e')" :extra="$t('tx_Drives_the_dashboard_alert_18093')">
              <a-date-picker v-model:value="form.next_service_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('tx_Next_service_odometer_5d1fc')">
              <a-input-number v-model:value="form.next_service_odometer" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <!-- The parts used (2026-11-04): out of the stock when the job isn't just scheduled; their cost adds to the job's. -->
          <a-col v-if="parts.length" :span="24">
            <a-form-item :label="es ? 'Repuestos usados' : 'Parts used'">
              <a-row v-for="(p, i) in form.parts" :key="i" :gutter="8" style="margin-bottom: 6px">
                <a-col :span="15"><a-select v-model:value="p.part_id" :options="partOptions" show-search option-filter-prop="label" style="width: 100%" /></a-col>
                <a-col :span="6"><a-input-number v-model:value="p.quantity" :min="0.01" style="width: 100%" /></a-col>
                <a-col :span="3"><a-button type="text" danger @click="form.parts.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button></a-col>
              </a-row>
              <a-button size="small" @click="form.parts.push({ part_id: undefined, quantity: 1 })">{{ es ? 'Agregar repuesto' : 'Add a part' }}</a-button>
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')" style="margin-bottom: 0">
              <a-textarea v-model:value="form.notes" :rows="2" allow-clear />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
/**
 * Service and repair log for the whole fleet, with a per-vehicle filter that
 * the vehicle detail page links into.
 *
 * Picking a vehicle prefills the odometer with its current reading — the number
 * is nearly always "whatever the dashboard says right now", and typing it again
 * is how logs end up with typos.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { MAINTENANCE_TYPES, MAINTENANCE_STATUSES, labelOf, optionOf } from './fleetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';
import { sortByLabel } from '../../lib/filterOptions';
import { useUiStore } from '../../stores/ui';

const route = useRoute();
const es = computed(() => useUiStore().locale === 'es');
const { money, number, date } = useFormat();

const filters = reactive({
  vehicle_id: route.query.vehicle_id ? Number(route.query.vehicle_id) : undefined,
  type: undefined,
  status: undefined,
  range: null,
});

const crud = useCrudTable('fleet/maintenances', {
  rowsKey: 'maintenances',
  sortField: 'service_date',
  params: () => ({
    vehicle_id: filters.vehicle_id || '',
    type: filters.type || '',
    status: filters.status || '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const totalCost = computed(() => crud.payload.value?.total_cost || 0);

const columns = computed(() => [
  { title: tEs('date', 'Date'), key: 'service_date', dataIndex: 'service_date', sorter: true, width: 110 },
  { title: tEs('Vehicle', 'Vehicle'), key: 'vehicle', dataIndex: 'vehicle_name', width: 200 },
  { title: tEs('tx_Work_0f926', 'Work'), dataIndex: 'title', key: 'title' },
  { title: tEs('type', 'Type'), key: 'type', dataIndex: 'type', sorter: true, width: 110 },
  { title: tEs('tx_Odometer_5c059', 'Odometer'), key: 'odometer', dataIndex: 'odometer', sorter: true, width: 100 },
  { title: es.value ? 'Hecho por' : 'Done by', key: 'done_by', width: 150 },
  { title: tEs('Serial_Status', 'Status'), key: 'status', dataIndex: 'status', sorter: true, width: 120 },
  { title: tEs('tx_Next_due_8c202', 'Next due'), key: 'next', width: 140 },
  { title: tEs('Cost', 'Cost'), key: 'cost', dataIndex: 'cost', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 90 },
]);

// ---------------- form ----------------

const vehicles = ref([]);
const vehicleOptions = computed(() => vehicles.value.map(v => ({ value: v.id, label: v.label })));
// In house (a mechanic: maintenance staff first) or a workshop (a supplier), 2026-11-01.
const providers = ref([]);
const employees = ref([]);
const providerOptions = computed(() => sortByLabel(providers.value.map(p => ({ value: p.id, label: p.name }))));
const mechanicOptions = computed(() => {
  const maint = employees.value.filter(e => e.staff_type === 'maintenance');
  return sortByLabel((maint.length ? maint : employees.value).map(e => ({ value: e.id, label: e.name })));
});

// The workshop's shelf (a role without it just doesn't see the section).
const parts = ref([]);
const partOptions = computed(() => sortByLabel(parts.value.map(p => ({ value: p.id, label: `${p.name} (${Number(p.stock)} ${p.unit})` }))));
const partsCost = computed(() => (form.value.parts || []).reduce((sum, row) => sum + Number(row.quantity || 0) * Number(parts.value.find(p => p.id === row.part_id)?.unit_cost || 0), 0));
async function loadParts() {
  try { parts.value = (await http.get('fleet/parts')).parts; } catch (e) { parts.value = []; }
}
onMounted(async () => {
  await loadParts();
  // Coming from the maintenance plan: the job already filled in.
  if (route.query.plan_id) {
    openForm(null);
    Object.assign(form.value, { plan_id: Number(route.query.plan_id), vehicle_id: Number(route.query.vehicle_id), title: String(route.query.title || ''), type: String(route.query.type || 'service') });
    onVehicleChange(form.value.vehicle_id);
  }
});

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(emptyForm());

function emptyForm() {
  return {
    vehicle_id: undefined, title: '', type: 'service', status: 'completed',
    service_date: new Date().toISOString().slice(0, 10),
    odometer: null, cost: null, vendor: '', performed_by: 'in_house', provider_id: undefined, mechanic_id: undefined,
    next_service_date: null, next_service_odometer: null, notes: '', parts: [], plan_id: null,
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  vehicle_id: required(),
  title: required(),
  type: required(),
  status: required(),
  service_date: required(),
  provider_id: form.value.performed_by === 'workshop' ? required() : [],
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        vehicle_id: record.vehicle_id,
        title: record.title,
        type: record.type,
        status: record.status,
        service_date: record.service_date,
        odometer: record.odometer,
        cost: record.labor_cost,
        parts: (record.parts || []).map(p => ({ part_id: p.part_id, quantity: Number(p.quantity) })),
        plan_id: record.plan_id,
        vendor: record.vendor || '',
        performed_by: record.performed_by || 'in_house',
        provider_id: record.provider_id || undefined,
        mechanic_id: record.mechanic_id || undefined,
        next_service_date: record.next_service_date,
        next_service_odometer: record.next_service_odometer,
        notes: record.notes || '',
      }
    : { ...emptyForm(), vehicle_id: filters.vehicle_id };
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

/** Prefill the odometer from the vehicle's current reading (new entries only). */
function onVehicleChange(id) {
  if (editing.value || form.value.odometer) return;
  const vehicle = vehicles.value.find(v => v.id === id);
  if (vehicle) form.value.odometer = vehicle.odometer || null;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`fleet/maintenances/${editing.value.id}`, form.value);
    else await http.post('fleet/maintenances', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    loadVehicles();
  } catch (e) {
    message.error(firstError(e) || t('InvalidData', 'Could not save this entry'));
  } finally {
    saving.value = false;
  }
}

function firstError(e) {
  const errors = e?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e?.data?.message || '';
}

async function loadVehicles() {
  try {
    const data = await http.get('fleet/meta');
    vehicles.value = data?.vehicles || [];
    providers.value = data?.providers || [];
    employees.value = data?.employees || [];
  } catch (e) { /* the select stays empty */ }
}

onMounted(() => {
  crud.fetchRows();
  loadVehicles();
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.link {
  color: #6d28d9;
  cursor: pointer;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 160px;
}
.tb-range {
  width: 240px;
}
.tb-total {
  margin-inline-start: auto;
  margin-inline-end: 0;
  font-size: 13px;
  padding: 3px 10px;
}
.next {
  display: flex;
  flex-direction: column;
  font-size: 12.5px;
}
.next-odo {
  opacity: 0.6;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
  .tb-total {
    margin-inline-start: 0;
  }
}
</style>
