<template>
  <div class="page">
    <PageHeader title="Fuel Logs" :breadcrumb="['Fleet Management', 'Fuel Logs']">
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
          v-model:value="crud.search.value" placeholder="Search station or vehicle…"
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
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-space class="tb-totals" :size="6">
          <a-tag color="purple">{{ money(totals.cost || 0) }}</a-tag>
          <a-tag>{{ number(totals.quantity || 0, 2) }} units</a-tag>
        </a-space>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'vehicle'">
          <a class="link" @click="$router.push(`/fleet/vehicles/${record.vehicle_id}`)">{{ record.vehicle_name }}</a>
        </template>
        <template v-else-if="column.key === 'log_date'">{{ date(record.log_date) }}</template>
        <template v-else-if="column.key === 'odometer'">{{ number(record.odometer, 0) }}</template>
        <template v-else-if="column.key === 'quantity'">{{ number(record.quantity, 2) }}</template>
        <template v-else-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
        <template v-else-if="column.key === 'total_cost'"><b>{{ money(record.total_cost) }}</b></template>
        <template v-else-if="column.key === 'driver'">
          <span v-if="record.driver_name">{{ record.driver_name }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'full_tank'">
          <a-tag :color="record.full_tank ? 'success' : 'default'">
            {{ record.full_tank ? 'Full' : 'Partial' }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
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

    <a-modal
      :open="formOpen" :title="editing ? 'Edit fuel entry' : 'Record fuel'" :width="620"
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
            <a-form-item label="Driver">
              <a-select
                v-model:value="form.employee_id" allow-clear show-search option-filter-prop="label"
                :options="employeeOptions" placeholder="Not recorded"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Date *" name="log_date">
              <a-date-picker v-model:value="form.log_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Odometer *" name="odometer">
              <a-input-number v-model:value="form.odometer" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="4">
            <a-form-item label="Quantity *" name="quantity">
              <a-input-number v-model:value="form.quantity" :min="0" :step="0.01" style="width: 100%" @change="recalcTotal" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="4">
            <a-form-item label="Unit price">
              <a-input-number v-model:value="form.unit_price" :min="0" :step="0.01" style="width: 100%" @change="recalcTotal" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="4">
            <a-form-item label="Total" extra="Auto">
              <a-input-number v-model:value="form.total_cost" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Station">
              <a-input v-model:value="form.station" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Tank" extra="Consumption maths only works between full tanks">
              <a-switch v-model:checked="form.full_tank" checked-children="Full" un-checked-children="Partial" />
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
/**
 * Fuel log. Quantity x unit price keeps the total in step as you type, but the
 * total stays editable — a receipt rounds differently than the maths does, and
 * the receipt is what the accounts have to match.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { money, number, date } = useFormat();

const filters = reactive({
  vehicle_id: route.query.vehicle_id ? Number(route.query.vehicle_id) : undefined,
  employee_id: undefined,
  range: null,
});

const crud = useCrudTable('fleet/fuel-logs', {
  rowsKey: 'fuel_logs',
  sortField: 'log_date',
  params: () => ({
    vehicle_id: filters.vehicle_id || '',
    employee_id: filters.employee_id || '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const totals = computed(() => crud.payload.value?.totals || {});

const columns = computed(() => [
  { title: 'Date', key: 'log_date', dataIndex: 'log_date', sorter: true, width: 110 },
  { title: 'Vehicle', key: 'vehicle', dataIndex: 'vehicle_name', width: 200 },
  { title: 'Odometer', key: 'odometer', dataIndex: 'odometer', sorter: true, width: 110 },
  { title: 'Quantity', key: 'quantity', dataIndex: 'quantity', sorter: true, width: 110 },
  { title: 'Unit price', key: 'unit_price', dataIndex: 'unit_price', sorter: true, width: 120 },
  { title: 'Total', key: 'total_cost', dataIndex: 'total_cost', sorter: true, width: 120 },
  { title: 'Driver', key: 'driver', dataIndex: 'driver_name', width: 150 },
  { title: 'Station', dataIndex: 'station', key: 'station' },
  { title: 'Tank', key: 'full_tank', width: 100 },
  { title: '', key: 'actions', width: 90 },
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

function emptyForm() {
  return {
    vehicle_id: undefined, employee_id: undefined,
    log_date: new Date().toISOString().slice(0, 10),
    odometer: null, quantity: null, unit_price: null, total_cost: null,
    station: '', full_tank: true, notes: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  vehicle_id: required(),
  log_date: required(),
  odometer: required(),
  quantity: required(),
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        vehicle_id: record.vehicle_id,
        employee_id: record.employee_id || undefined,
        log_date: record.log_date,
        odometer: record.odometer,
        quantity: record.quantity,
        unit_price: record.unit_price,
        total_cost: record.total_cost,
        station: record.station || '',
        full_tank: !!record.full_tank,
        notes: record.notes || '',
      }
    : { ...emptyForm(), vehicle_id: filters.vehicle_id };
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

function onVehicleChange(id) {
  if (editing.value || form.value.odometer) return;
  const vehicle = vehicles.value.find(v => v.id === id);
  if (vehicle) form.value.odometer = vehicle.odometer || null;
}

/** Keep the total in step while both inputs are filled. */
function recalcTotal() {
  const q = Number(form.value.quantity || 0);
  const p = Number(form.value.unit_price || 0);
  if (q > 0 && p > 0) form.value.total_cost = Math.round(q * p * 100) / 100;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`fleet/fuel-logs/${editing.value.id}`, form.value);
    else await http.post('fleet/fuel-logs', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    loadMeta();
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
.tb-totals {
  margin-inline-start: auto;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
  .tb-totals {
    margin-inline-start: 0;
  }
}
</style>
