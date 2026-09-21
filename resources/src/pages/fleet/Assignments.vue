<template>
  <div class="page">
    <PageHeader title="Assignments" :breadcrumb="['Fleet Management', 'Assignments']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          Assign vehicle
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search purpose or destination…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.vehicle_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All vehicles"
          :options="vehicleOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.employee_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All drivers"
          :options="employeeOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          placeholder="All statuses" :options="ASSIGNMENT_STATUSES" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'vehicle'">
          <a class="link" @click="$router.push(`/fleet/vehicles/${record.vehicle_id}`)">{{ record.vehicle_name }}</a>
        </template>
        <template v-else-if="column.key === 'start_date'">{{ dateTime(record.start_date) }}</template>
        <template v-else-if="column.key === 'end_date'">
          <span v-if="record.end_date">{{ dateTime(record.end_date) }}</span>
          <span v-else class="muted">Still out</span>
        </template>
        <template v-else-if="column.key === 'distance'">
          {{ record.distance !== null ? number(record.distance, 0) : '—' }}
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(ASSIGNMENT_STATUSES, record.status).color">
            {{ labelOf(ASSIGNMENT_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip v-if="record.status === 'active'" title="Return vehicle">
              <a-button type="text" size="small" @click="openClose(record)">
                <template #icon><RollbackOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.vehicle_name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Create / edit -->
    <a-modal
      :open="formOpen" :title="editing ? 'Edit assignment' : 'Assign vehicle'" :width="620"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Vehicle *" name="vehicle_id">
              <a-select
                v-model:value="form.vehicle_id" show-search option-filter-prop="label"
                :options="vehicleOptions" placeholder="Select a vehicle" @change="onVehicleChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Driver *" name="employee_id">
              <a-select
                v-model:value="form.employee_id" show-search option-filter-prop="label"
                :options="employeeOptions" placeholder="Select a driver"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Out from *" name="start_date">
              <a-date-picker
                v-model:value="form.start_date" show-time style="width: 100%"
                value-format="YYYY-MM-DD HH:mm:ss"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Back on" name="end_date">
              <a-date-picker
                v-model:value="form.end_date" show-time style="width: 100%"
                value-format="YYYY-MM-DD HH:mm:ss"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Start odometer">
              <a-input-number v-model:value="form.start_odometer" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="End odometer">
              <a-input-number v-model:value="form.end_odometer" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Status *" name="status">
              <a-select v-model:value="form.status" :options="ASSIGNMENT_STATUSES" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Purpose">
              <a-input v-model:value="form.purpose" placeholder="e.g. Customer delivery" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Destination">
              <a-input v-model:value="form.destination" allow-clear />
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

    <!-- Return the vehicle -->
    <a-modal
      :open="closeOpen" title="Return vehicle" :width="420"
      :confirm-loading="closing" ok-text="Return" :cancel-text="$t('Cancel')"
      @ok="submitClose" @cancel="closeOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item label="Returned on">
          <a-date-picker
            v-model:value="closeForm.end_date" show-time style="width: 100%"
            value-format="YYYY-MM-DD HH:mm:ss"
          />
        </a-form-item>
        <a-form-item label="End odometer" extra="Pushes the vehicle's reading forward">
          <a-input-number v-model:value="closeForm.end_odometer" :min="0" style="width: 100%" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Driver assignments. The backend refuses a second open assignment for the same
 * vehicle (422), so a double-booking surfaces as a message rather than as two
 * drivers turning up for one van.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, RollbackOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { ASSIGNMENT_STATUSES, labelOf, optionOf } from './fleetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { number, dateTime } = useFormat();

const filters = reactive({
  vehicle_id: route.query.vehicle_id ? Number(route.query.vehicle_id) : undefined,
  employee_id: undefined,
  status: route.query.status || undefined,
  range: null,
});

const crud = useCrudTable('fleet/assignments', {
  rowsKey: 'assignments',
  sortField: 'start_date',
  params: () => ({
    vehicle_id: filters.vehicle_id || '',
    employee_id: filters.employee_id || '',
    status: filters.status || '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const columns = computed(() => [
  { title: 'Vehicle', key: 'vehicle', dataIndex: 'vehicle_name', width: 200 },
  { title: 'Driver', dataIndex: 'driver_name', key: 'driver_name', width: 170 },
  { title: 'Out from', key: 'start_date', dataIndex: 'start_date', sorter: true, width: 160 },
  { title: 'Back on', key: 'end_date', dataIndex: 'end_date', sorter: true, width: 160 },
  { title: 'Distance', key: 'distance', dataIndex: 'distance', width: 100 },
  { title: 'Purpose', dataIndex: 'purpose', key: 'purpose' },
  { title: 'Destination', dataIndex: 'destination', key: 'destination', width: 150 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 120 },
  { title: '', key: 'actions', width: 120 },
]);

// ---------------- form ----------------

const vehicles = ref([]);
const employees = ref([]);
const vehicleOptions = computed(() => vehicles.value.map(v => ({ value: v.id, label: v.label })));
const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.name })));

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(emptyForm());

function nowStamp() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

function emptyForm() {
  return {
    vehicle_id: undefined, employee_id: undefined,
    start_date: nowStamp(), end_date: null,
    start_odometer: null, end_odometer: null,
    purpose: '', destination: '', status: 'active', notes: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  vehicle_id: required(),
  employee_id: required(),
  start_date: required(),
  status: required(),
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        vehicle_id: record.vehicle_id,
        employee_id: record.employee_id,
        start_date: toStamp(record.start_date),
        end_date: toStamp(record.end_date),
        start_odometer: record.start_odometer,
        end_odometer: record.end_odometer,
        purpose: record.purpose || '',
        destination: record.destination || '',
        status: record.status,
        notes: record.notes || '',
      }
    : { ...emptyForm(), vehicle_id: filters.vehicle_id };
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

/** The API sends ISO-8601; the picker's value-format wants "Y-m-d H:i:s". */
function toStamp(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function onVehicleChange(id) {
  if (editing.value || form.value.start_odometer) return;
  const vehicle = vehicles.value.find(v => v.id === id);
  if (vehicle) form.value.start_odometer = vehicle.odometer || null;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`fleet/assignments/${editing.value.id}`, form.value);
    else await http.post('fleet/assignments', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    loadMeta();
  } catch (e) {
    // The double-booking guard answers 422 with a plain message.
    message.error(firstError(e) || t('InvalidData', 'Could not save this assignment'));
  } finally {
    saving.value = false;
  }
}

// ---------------- return ----------------

const closeOpen = ref(false);
const closing = ref(false);
const closingRecord = ref(null);
const closeForm = ref({ end_date: null, end_odometer: null });

function openClose(record) {
  closingRecord.value = record;
  closeForm.value = {
    end_date: nowStamp(),
    end_odometer: record.end_odometer || record.start_odometer || null,
  };
  closeOpen.value = true;
}

async function submitClose() {
  closing.value = true;
  try {
    await http.post(`fleet/assignments/${closingRecord.value.id}/close`, closeForm.value);
    message.success('Vehicle returned.');
    closeOpen.value = false;
    crud.fetchRows();
    loadMeta();
  } catch (e) {
    message.error(firstError(e) || t('InvalidData', 'Could not close this assignment'));
  } finally {
    closing.value = false;
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

async function loadMeta() {
  try {
    const data = await http.get('fleet/meta');
    vehicles.value = data?.vehicles || [];
    employees.value = data?.employees || [];
  } catch (e) { /* the selects stay empty */ }
}

onMounted(() => {
  crud.fetchRows();
  loadMeta();
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
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
