<template>
  <div class="page">
    <PageHeader :title="s('Appointments')" :breadcrumb="[s('Hospital'), s('Appointments')]">
      <template #actions>
        <a-segmented v-model:value="view" :options="viewOptions" />
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ s('Book appointment') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- List view -->
    <template v-if="view === 'list'">
      <a-card size="small" style="margin-bottom: 16px">
        <div class="toolbar">
          <a-input-search
            v-model:value="crud.search.value" :placeholder="s('Search patient, MRN or reference…')"
            allow-clear class="tb-search" @search="crud.reload"
          />
          <a-select
            v-model:value="filters.doctor_id" class="tb-item" allow-clear show-search
            option-filter-prop="label" :placeholder="s('All doctors')"
            :options="doctorOptions" @change="crud.reload"
          />
          <a-select
            v-model:value="filters.status" class="tb-item" allow-clear
            :placeholder="s('All statuses')" :options="appointmentStatuses" @change="crud.reload"
          />
          <a-select
            v-model:value="filters.type" class="tb-item" allow-clear
            :placeholder="s('All types')" :options="appointmentTypes" @change="crud.reload"
          />
          <PresetRangeFilter v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
          <a-checkbox v-model:checked="filters.today" class="tb-check" @change="crud.reload">{{ s('Today') }}</a-checkbox>
        </div>
      </a-card>

      <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'scheduled_at'">
            <div class="when">{{ dateTime(record.scheduled_at) }}</div>
            <div class="when-sub">{{ record.duration_minutes }} min</div>
          </template>
          <template v-else-if="column.key === 'patient'">
            <button type="button" class="link-cell" @click="$router.push(`/hospital/patients/${record.patient_id}`)">
              <div class="cell-name">{{ record.patient_name }}</div>
              <div class="cell-mrn">{{ record.patient_mrn }}</div>
            </button>
          </template>
          <template v-else-if="column.key === 'type'">
            <a-tag :color="optionOf(APPOINTMENT_TYPES, record.type).color">
              {{ s(labelOf(APPOINTMENT_TYPES, record.type)) }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-select
              :value="record.status" size="small" class="status-select"
              :options="appointmentStatuses" @change="v => setStatus(record, v)"
            />
          </template>
          <template v-else-if="column.key === 'fee'">{{ money(record.fee) }}</template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="0">
              <a-tooltip v-if="!record.has_visit" :title="s('Start consultation')">
                <a-button type="text" size="small" @click="$router.push(`/hospital/visits/create?appointment=${record.id}`)">
                  <template #icon><FileAddOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="s('Edit')">
                <a-button type="text" size="small" @click="openForm(record)">
                  <template #icon><EditOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="s('Delete')">
                <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.reference })">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
      </DataTable>
    </template>

    <!-- Day board -->
    <template v-else>
      <a-card size="small" style="margin-bottom: 16px">
        <div class="toolbar">
          <a-button @click="shiftDay(-1)"><template #icon><LeftOutlined /></template></a-button>
          <a-date-picker v-model:value="boardDate" value-format="YYYY-MM-DD" class="tb-item" @change="loadBoard" />
          <a-button @click="shiftDay(1)"><template #icon><RightOutlined /></template></a-button>
          <a-button @click="today">{{ s('Today') }}</a-button>
          <a-select
            v-model:value="boardDepartment" class="tb-item" allow-clear show-search
            option-filter-prop="label" :placeholder="s('All departments')"
            :options="departmentOptions" @change="loadBoard"
          />
        </div>
      </a-card>

      <a-spin :spinning="boardLoading">
        <div v-if="(board.columns || []).length" class="board">
          <div v-for="col in board.columns" :key="col.doctor_id" class="board-col">
            <div class="board-head">
              <div class="board-doctor">{{ col.doctor_name }}</div>
              <div class="board-hours">
                <span v-if="col.working">{{ col.hours[0] }} – {{ col.hours[1] }}</span>
                <span v-else class="off">{{ s('Not in clinic') }}</span>
              </div>
            </div>
            <div v-if="col.appointments.length" class="board-slots">
              <button
                v-for="a in col.appointments" :key="a.id" type="button"
                class="slot" :class="a.status" @click="openForm(a)"
              >
                <span class="slot-time">{{ clock(a.scheduled_at) }}</span>
                <span class="slot-name">{{ a.patient_name }}</span>
                <span class="slot-type">{{ s(labelOf(APPOINTMENT_TYPES, a.type)) }}</span>
              </button>
            </div>
            <div v-else class="board-empty">{{ s('No bookings') }}</div>
          </div>
        </div>
        <a-card v-else>
          <a-empty :description="s('No active doctors to show')" />
        </a-card>
      </a-spin>
    </template>

    <!-- Booking form -->
    <a-modal
      :open="formOpen" :title="editing ? s('Edit appointment') : s('Book appointment')" :width="640"
      :confirm-loading="saving" :ok-text="s('Save')" :cancel-text="s('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item :label="s('Patient') + ' *'" name="patient_id">
          <PatientPicker v-model="form.patient_id" :initial-option="editingPatient" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="s('Doctor') + ' *'" name="doctor_id">
              <a-select
                v-model:value="form.doctor_id" show-search option-filter-prop="label"
                :options="doctorOptions" :placeholder="s('Select a doctor')" @change="onDoctorChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="s('Department')">
              <a-select
                v-model:value="form.department_id" allow-clear show-search
                option-filter-prop="label" :options="departmentOptions" :placeholder="s('None')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="s('When') + ' *'" name="scheduled_at">
              <a-date-picker
                v-model:value="form.scheduled_at" show-time style="width: 100%"
                value-format="YYYY-MM-DD HH:mm:ss" @change="checkHours"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="8" :md="4">
            <a-form-item :label="s('Minutes')">
              <a-input-number v-model:value="form.duration_minutes" :min="5" :max="480" :step="5" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="8" :md="4">
            <a-form-item :label="s('Type') + ' *'" name="type">
              <a-select v-model:value="form.type" :options="appointmentTypes" />
            </a-form-item>
          </a-col>
          <a-col :xs="8" :md="4">
            <a-form-item :label="s('Fee')">
              <a-input-number v-model:value="form.fee" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-alert v-if="hoursWarning" type="warning" show-icon :message="hoursWarning" style="margin-bottom: 16px" />
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="s('Status') + ' *'" name="status">
              <a-select v-model:value="form.status" :options="appointmentStatuses" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="s('Reason')">
              <a-input v-model:value="form.reason" :placeholder="s('e.g. Chest pain')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="s('Note')" style="margin-bottom: 0">
              <a-textarea v-model:value="form.notes" :rows="2" allow-clear />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
/**
 * Appointment diary, as a list or a per-doctor day board.
 *
 * The backend refuses overlapping bookings for one doctor, so a clash surfaces
 * as a message rather than two patients arriving at once. Clinic hours are only
 * a warning here — the booker is told, and decides.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, FileAddOutlined,
  LeftOutlined, RightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PatientPicker from './PatientPicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { APPOINTMENT_TYPES, APPOINTMENT_STATUSES, labelOf, optionOf } from './hospitalOptions';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/Appointments.es.js';

const { s } = useHospitalText(ES);

const route = useRoute();
const { money, dateTime } = useFormat();

const view = ref('list');
const viewOptions = computed(() => [
  { value: 'list', label: s('List') },
  { value: 'board', label: s('Day board') },
]);
// Shared option lists with their labels in the active language (values unchanged).
const appointmentTypes = computed(() => APPOINTMENT_TYPES.map(o => ({ ...o, label: s(o.label) })));
const appointmentStatuses = computed(() => APPOINTMENT_STATUSES.map(o => ({ ...o, label: s(o.label) })));

const filters = reactive({
  doctor_id: undefined,
  status: undefined,
  type: undefined,
  range: null,
  today: route.query.today === '1',
});

const crud = useCrudTable('hospital/appointments', {
  rowsKey: 'appointments',
  sortField: 'scheduled_at',
  params: () => ({
    doctor_id: filters.doctor_id || '',
    status: filters.status || '',
    type: filters.type || '',
    today: filters.today ? 1 : '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const columns = computed(() => [
  { title: s('When'), key: 'scheduled_at', dataIndex: 'scheduled_at', sorter: true, width: 190 },
  { title: s('Patient'), key: 'patient', dataIndex: 'patient_name' },
  { title: s('Doctor'), dataIndex: 'doctor_name', key: 'doctor_name', width: 160 },
  { title: s('Type'), key: 'type', dataIndex: 'type', sorter: true, width: 130 },
  { title: s('Reason'), dataIndex: 'reason', key: 'reason', width: 180 },
  { title: s('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 150 },
  { title: s('Fee'), key: 'fee', dataIndex: 'fee', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 120 },
]);

const doctors = ref([]);
const departments = ref([]);
const doctorOptions = computed(() => doctors.value.map(d => ({
  value: d.id,
  label: d.specialty ? `${d.name} · ${d.specialty}` : d.name,
})));
const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.name })));

function clock(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

async function setStatus(record, status) {
  try {
    await http.post(`hospital/appointments/${record.id}/status`, { status });
    record.status = status;
    if (view.value === 'board') loadBoard();
  } catch (e) {
    message.error(s('Could not update the status'));
  }
}

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const editingPatient = ref(null);
const hoursWarning = ref('');
const form = ref(empty());

function stamp(d = new Date()) {
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

function empty() {
  return {
    patient_id: undefined, doctor_id: undefined, department_id: undefined,
    scheduled_at: stamp(), duration_minutes: 15, type: 'consultation',
    status: 'scheduled', reason: '', fee: null, notes: '',
  };
}

const required = () => [{ required: true, message: s('This field is required') }];
const rules = computed(() => ({
  patient_id: required(),
  doctor_id: required(),
  scheduled_at: required(),
  type: required(),
  status: required(),
}));

function openForm(record) {
  editing.value = record;
  hoursWarning.value = '';
  editingPatient.value = record
    ? { id: record.patient_id, name: record.patient_name, mrn: record.patient_mrn }
    : null;
  form.value = record
    ? {
        patient_id: record.patient_id,
        doctor_id: record.doctor_id,
        department_id: record.department_id || undefined,
        scheduled_at: toStamp(record.scheduled_at),
        duration_minutes: record.duration_minutes,
        type: record.type,
        status: record.status,
        reason: record.reason || '',
        fee: record.fee,
        notes: record.notes || '',
      }
    : empty();
  formOpen.value = true;
  formRef.value?.clearValidate?.();
  checkHours();
}

/** The API sends ISO-8601; the picker's value-format wants "Y-m-d H:i:s". */
function toStamp(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : stamp(d);
}

function onDoctorChange(id) {
  const doctor = doctors.value.find(d => d.id === id);
  if (doctor) {
    if (!form.value.fee) form.value.fee = doctor.consultation_fee || null;
    if (!form.value.department_id) form.value.department_id = doctor.department_id || undefined;
  }
  checkHours();
}

/** Advisory only — the backend never blocks on clinic hours. */
async function checkHours() {
  hoursWarning.value = '';
  if (!form.value.doctor_id || !form.value.scheduled_at) return;

  const date = String(form.value.scheduled_at).slice(0, 10);
  try {
    const data = await http.get(`hospital/doctors/${form.value.doctor_id}/schedule`, { date });
    if (!data?.working) {
      hoursWarning.value = s('This doctor has no clinic hours set for that day.');
      return;
    }
    const time = String(form.value.scheduled_at).slice(11, 16);
    if (time < data.hours[0] || time >= data.hours[1]) {
      hoursWarning.value = s('Outside clinic hours ({from} – {to}). You can still book it.', { from: data.hours[0], to: data.hours[1] });
    }
  } catch (e) { /* leave the warning empty */ }
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`hospital/appointments/${editing.value.id}`, form.value);
    else await http.post('hospital/appointments', form.value);
    message.success(s('Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    if (view.value === 'board') loadBoard();
  } catch (e) {
    // The clash guard answers 422 with a plain message.
    message.error(e?.data?.message || s('Could not save this appointment'));
  } finally {
    saving.value = false;
  }
}

// ---------------- board ----------------

const board = ref({});
const boardLoading = ref(false);
const boardDate = ref(new Date().toISOString().slice(0, 10));
const boardDepartment = ref(undefined);

function shiftDay(days) {
  const d = new Date(boardDate.value);
  d.setDate(d.getDate() + days);
  boardDate.value = d.toISOString().slice(0, 10);
  loadBoard();
}
function today() {
  boardDate.value = new Date().toISOString().slice(0, 10);
  loadBoard();
}

async function loadBoard() {
  boardLoading.value = true;
  try {
    board.value = await http.get('hospital/appointments/board', {
      date: boardDate.value,
      department_id: boardDepartment.value || '',
    });
  } catch (e) {
    board.value = {};
  } finally {
    boardLoading.value = false;
  }
}

onMounted(async () => {
  crud.fetchRows();
  loadBoard();
  try {
    const meta = await http.get('hospital/meta');
    doctors.value = meta?.doctors || [];
    departments.value = meta?.departments || [];
  } catch (e) { /* selects stay empty */ }
});
</script>

<style scoped>
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
  width: 170px;
}
.tb-range {
  width: 240px;
}
.tb-check {
  white-space: nowrap;
}
.when {
  font-weight: 500;
}
.when-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.link-cell {
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.cell-name {
  font-weight: 500;
}
.link-cell:hover .cell-name {
  color: #6d28d9;
}
.cell-mrn {
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.status-select {
  width: 130px;
}

/* Day board */
.board {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 12px;
}
.board-col {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  overflow: hidden;
}
.board-head {
  padding: 10px 12px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.18);
  background: rgba(128, 128, 128, 0.06);
}
.board-doctor {
  font-weight: 600;
  font-size: 13.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.board-hours {
  font-size: 11.5px;
  opacity: 0.6;
}
.off {
  color: #d97706;
  opacity: 1;
}
.board-slots {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px;
}
.slot {
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 8px 10px;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-inline-start-width: 3px;
  border-radius: 9px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
}
.slot:hover {
  border-color: rgba(109, 40, 217, 0.5);
}
.slot.arrived {
  border-inline-start-color: #d97706;
}
.slot.confirmed {
  border-inline-start-color: #1677ff;
}
.slot.completed {
  border-inline-start-color: #16a34a;
  opacity: 0.7;
}
.slot.cancelled,
.slot.no_show {
  border-inline-start-color: #ff4d4f;
  opacity: 0.6;
  text-decoration: line-through;
}
.slot-time {
  font-weight: 600;
  font-size: 12.5px;
  font-variant-numeric: tabular-nums;
}
.slot-name {
  font-size: 12.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.slot-type {
  font-size: 11px;
  opacity: 0.55;
}
.board-empty {
  padding: 18px 12px;
  text-align: center;
  font-size: 12px;
  opacity: 0.45;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
