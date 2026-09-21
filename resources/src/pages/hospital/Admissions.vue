<template>
  <div class="page">
    <PageHeader title="Admissions" :breadcrumb="['Hospital', 'Admissions']">
      <template #actions>
        <a-button @click="$router.push('/hospital/wards')">
          <template #icon><HomeOutlined /></template>
          Bed board
        </a-button>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          Admit patient
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search patient, MRN or reference…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          placeholder="All statuses" :options="ADMISSION_STATUSES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.ward_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All wards" :options="wardOptions" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'patient'">
          <button type="button" class="link-cell" @click="$router.push(`/hospital/patients/${record.patient_id}`)">
            <div class="cell-name">{{ record.patient_name }}</div>
            <div class="cell-mrn">{{ record.patient_mrn }} · {{ record.reference }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'bed'">
          <span v-if="record.ward_name">{{ record.ward_name }} / {{ record.bed_number || '—' }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'admitted_at'">{{ dateTime(record.admitted_at) }}</template>
        <template v-else-if="column.key === 'discharged_at'">
          <span v-if="record.discharged_at">{{ dateTime(record.discharged_at) }}</span>
          <span v-else class="muted">Still in</span>
        </template>
        <template v-else-if="column.key === 'charge'">
          <div>{{ money(record.bed_charge) }}</div>
          <div class="cell-sub">{{ record.nights }} × {{ money(record.daily_rate) }}</div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(ADMISSION_STATUSES, record.status).color">
            {{ labelOf(ADMISSION_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <template v-if="record.status === 'admitted'">
              <a-tooltip title="Transfer bed">
                <a-button type="text" size="small" @click="openTransfer(record)">
                  <template #icon><SwapOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip title="Discharge">
                <a-button type="text" size="small" @click="openDischarge(record)">
                  <template #icon><LogoutOutlined /></template>
                </a-button>
              </a-tooltip>
            </template>
            <a-tooltip title="Bill this stay">
              <a-button type="text" size="small" @click="bill(record)">
                <template #icon><DollarOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.reference })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Admit / edit -->
    <a-modal
      :open="formOpen" :title="editing ? 'Edit admission' : 'Admit patient'" :width="620"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item label="Patient *" name="patient_id">
          <PatientPicker v-model="form.patient_id" :initial-option="editingPatient" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item v-if="!editing" label="Bed *" name="bed_id">
              <a-select
                v-model:value="form.bed_id" show-search option-filter-prop="label"
                :options="bedOptions" placeholder="Pick a free bed" @change="onBedChange"
              />
            </a-form-item>
            <a-form-item v-else label="Bed" extra="Use Transfer to move the patient">
              <a-input :value="`${editing.ward_name || ''} / ${editing.bed_number || ''}`" disabled />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Consultant">
              <a-select
                v-model:value="form.doctor_id" allow-clear show-search option-filter-prop="label"
                :options="doctorOptions" placeholder="Select"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Admitted at *" name="admitted_at">
              <a-date-picker
                v-model:value="form.admitted_at" show-time style="width: 100%"
                value-format="YYYY-MM-DD HH:mm:ss"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Rate / night" extra="Defaults to the bed's rate">
              <a-input-number v-model:value="form.daily_rate" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item label="Reason for admission">
              <a-input v-model:value="form.reason" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item label="Working diagnosis" style="margin-bottom: 0">
              <a-textarea v-model:value="form.diagnosis" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Discharge -->
    <a-modal
      :open="dischargeOpen" title="Discharge patient" :width="520"
      :confirm-loading="working" ok-text="Discharge" :cancel-text="$t('Cancel')"
      @ok="submitDischarge" @cancel="dischargeOpen = false"
    >
      <p v-if="target" class="target">
        {{ target.patient_name }} — {{ target.ward_name }} / {{ target.bed_number }},
        {{ target.nights }} night(s) at {{ money(target.daily_rate) }}
        = <b>{{ money(target.bed_charge) }}</b>
      </p>
      <a-form layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Discharged at">
              <a-date-picker
                v-model:value="dischargeForm.discharged_at" show-time style="width: 100%"
                value-format="YYYY-MM-DD HH:mm:ss"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Outcome">
              <a-select v-model:value="dischargeForm.status" :options="dischargeOutcomes" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item label="Discharge summary" style="margin-bottom: 0">
          <a-textarea v-model:value="dischargeForm.discharge_summary" :rows="4" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Transfer -->
    <a-modal
      :open="transferOpen" title="Transfer to another bed" :width="460"
      :confirm-loading="working" ok-text="Transfer" :cancel-text="$t('Cancel')"
      @ok="submitTransfer" @cancel="transferOpen = false"
    >
      <p v-if="target" class="target">
        {{ target.patient_name }} is currently in {{ target.ward_name }} / {{ target.bed_number }}.
      </p>
      <a-form layout="vertical">
        <a-form-item label="New bed">
          <a-select
            v-model:value="transferForm.bed_id" show-search option-filter-prop="label"
            :options="bedOptions" placeholder="Pick a free bed" @change="onTransferBedChange"
          />
        </a-form-item>
        <a-form-item label="Rate / night" style="margin-bottom: 0">
          <a-input-number v-model:value="transferForm.daily_rate" :min="0" style="width: 100%" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Inpatient admissions.
 *
 * The bed list only ever offers FREE beds, and the backend locks the row before
 * taking it — so two clerks admitting at the same moment cannot both win. A
 * patient already admitted is refused rather than duplicated.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, DollarOutlined,
  LogoutOutlined, SwapOutlined, HomeOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PatientPicker from './PatientPicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { ADMISSION_STATUSES, labelOf, optionOf } from './hospitalOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const router = useRouter();
const { money, dateTime } = useFormat();

const filters = reactive({ status: undefined, ward_id: undefined, range: null });

const crud = useCrudTable('hospital/admissions', {
  rowsKey: 'admissions',
  sortField: 'admitted_at',
  params: () => ({
    status: filters.status || '',
    ward_id: filters.ward_id || '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const columns = computed(() => [
  { title: 'Patient', key: 'patient', dataIndex: 'patient_name' },
  { title: 'Ward / bed', key: 'bed', width: 180 },
  { title: 'Consultant', dataIndex: 'doctor_name', key: 'doctor_name', width: 150 },
  { title: 'Admitted', key: 'admitted_at', dataIndex: 'admitted_at', sorter: true, width: 175 },
  { title: 'Discharged', key: 'discharged_at', dataIndex: 'discharged_at', sorter: true, width: 175 },
  { title: 'Bed charge', key: 'charge', width: 150 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 130 },
  { title: '', key: 'actions', width: 170 },
]);

const doctors = ref([]);
const wards = ref([]);
const beds = ref([]);
const doctorOptions = computed(() => doctors.value.map(d => ({ value: d.id, label: d.name })));
const wardOptions = computed(() => wards.value.map(w => ({ value: w.id, label: w.name })));
const bedOptions = computed(() => beds.value.map(b => ({ value: b.id, label: b.label, bed: b })));

const dischargeOutcomes = [
  { value: 'discharged', label: 'Discharged' },
  { value: 'transferred', label: 'Transferred out' },
  { value: 'deceased', label: 'Deceased' },
];

// ---------------- admit / edit ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const editingPatient = ref(null);
const form = ref(empty());

function stamp(d = new Date()) {
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

function empty() {
  return {
    patient_id: undefined, doctor_id: undefined, bed_id: undefined,
    admitted_at: stamp(), daily_rate: null, reason: '', diagnosis: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  patient_id: required(),
  bed_id: editing.value ? [] : required(),
  admitted_at: required(),
}));

function openForm(record) {
  editing.value = record;
  editingPatient.value = record
    ? { id: record.patient_id, name: record.patient_name, mrn: record.patient_mrn }
    : null;
  form.value = record
    ? {
        patient_id: record.patient_id,
        doctor_id: record.doctor_id || undefined,
        bed_id: record.bed_id || undefined,
        admitted_at: toStamp(record.admitted_at),
        daily_rate: record.daily_rate,
        reason: record.reason || '',
        diagnosis: record.diagnosis || '',
      }
    : empty();
  formOpen.value = true;
  formRef.value?.clearValidate?.();
  loadBeds();
}

function toStamp(iso) {
  if (!iso) return stamp();
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? stamp() : stamp(d);
}

function onBedChange(id) {
  const bed = beds.value.find(b => b.id === id);
  if (bed && !form.value.daily_rate) form.value.daily_rate = bed.daily_rate;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`hospital/admissions/${editing.value.id}`, form.value);
    else await http.post('hospital/admissions', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    loadBeds();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this admission'));
  } finally {
    saving.value = false;
  }
}

// ---------------- discharge / transfer ----------------

const working = ref(false);
const target = ref(null);
const dischargeOpen = ref(false);
const transferOpen = ref(false);
const dischargeForm = reactive({ discharged_at: stamp(), status: 'discharged', discharge_summary: '' });
const transferForm = reactive({ bed_id: undefined, daily_rate: null });

function openDischarge(record) {
  target.value = record;
  Object.assign(dischargeForm, {
    discharged_at: stamp(),
    status: 'discharged',
    discharge_summary: record.discharge_summary || '',
  });
  dischargeOpen.value = true;
}

async function submitDischarge() {
  working.value = true;
  try {
    await http.post(`hospital/admissions/${target.value.id}/discharge`, { ...dischargeForm });
    message.success('Patient discharged.');
    dischargeOpen.value = false;
    crud.fetchRows();
    loadBeds();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not discharge this patient'));
  } finally {
    working.value = false;
  }
}

function openTransfer(record) {
  target.value = record;
  Object.assign(transferForm, { bed_id: undefined, daily_rate: null });
  transferOpen.value = true;
  loadBeds();
}

function onTransferBedChange(id) {
  const bed = beds.value.find(b => b.id === id);
  if (bed) transferForm.daily_rate = bed.daily_rate;
}

async function submitTransfer() {
  if (!transferForm.bed_id) {
    message.warning('Pick a bed to transfer into.');
    return;
  }
  working.value = true;
  try {
    await http.post(`hospital/admissions/${target.value.id}/transfer`, { ...transferForm });
    message.success('Patient transferred.');
    transferOpen.value = false;
    crud.fetchRows();
    loadBeds();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not transfer this patient'));
  } finally {
    working.value = false;
  }
}

function bill(record) {
  router.push(`/hospital/invoices?draft=admission&id=${record.id}`);
}

async function loadBeds() {
  try {
    const data = await http.get('hospital/available-beds');
    beds.value = data?.beds || [];
  } catch (e) {
    beds.value = [];
  }
}

onMounted(async () => {
  crud.fetchRows();
  loadBeds();
  try {
    const meta = await http.get('hospital/meta');
    doctors.value = meta?.doctors || [];
    wards.value = meta?.wards || [];
  } catch (e) { /* selects stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
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
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.target {
  margin: 0 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.1);
  font-size: 13px;
}
</style>
