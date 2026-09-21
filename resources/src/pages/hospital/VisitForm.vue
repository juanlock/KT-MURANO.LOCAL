<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? 'Edit consultation' : 'New consultation'"
      :breadcrumb="['Hospital', 'Consultations', isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #actions>
        <a-button @click="$router.push('/hospital/visits')">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-alert
        v-if="patientAlerts" type="error" show-icon class="alert"
        :message="patientAlerts"
      />

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <a-card size="small" title="Encounter" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :span="24">
                <a-form-item label="Patient *" name="patient_id">
                  <PatientPicker
                    v-model="form.patient_id" :initial-option="initialPatient"
                    @select="onPatientSelect"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Doctor">
                  <a-select
                    v-model:value="form.doctor_id" allow-clear show-search option-filter-prop="label"
                    :options="doctorOptions" placeholder="Select" @change="onDoctorChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Department">
                  <a-select
                    v-model:value="form.department_id" allow-clear show-search
                    option-filter-prop="label" :options="departmentOptions" placeholder="None"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item label="Date & time *" name="visit_date">
                  <a-date-picker
                    v-model:value="form.visit_date" show-time style="width: 100%"
                    value-format="YYYY-MM-DD HH:mm:ss"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="8" :md="8">
                <a-form-item label="Type *" name="type">
                  <a-select v-model:value="form.type" :options="VISIT_TYPES" />
                </a-form-item>
              </a-col>
              <a-col :xs="8" :md="8">
                <a-form-item label="Status *" name="status">
                  <a-select v-model:value="form.status" :options="VISIT_STATUSES" />
                </a-form-item>
              </a-col>
              <a-col :xs="8" :md="8">
                <a-form-item label="Fee">
                  <a-input-number v-model:value="form.fee" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" title="Clinical notes" style="margin-bottom: 16px">
            <a-form-item label="Presenting complaint">
              <a-textarea v-model:value="form.complaint" :rows="2" placeholder="What the patient reports" />
            </a-form-item>
            <a-form-item label="Examination">
              <a-textarea v-model:value="form.examination" :rows="3" placeholder="Findings on examination" />
            </a-form-item>
            <a-form-item label="Diagnosis">
              <a-textarea v-model:value="form.diagnosis" :rows="2" />
            </a-form-item>
            <a-row :gutter="16">
              <a-col :xs="24" :md="16">
                <a-form-item label="Treatment plan" style="margin-bottom: 0">
                  <a-textarea v-model:value="form.treatment_plan" :rows="2" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Follow-up date" style="margin-bottom: 0">
                  <a-date-picker v-model:value="form.follow_up_date" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" title="Prescription">
            <template #extra>
              <a-button size="small" type="primary" ghost @click="addItem">
                <template #icon><PlusOutlined /></template>
                Add medicine
              </a-button>
            </template>

            <div v-if="items.length" class="rx-rows">
              <div v-for="(item, i) in items" :key="i" class="rx-row">
                <a-select
                  v-model:value="item.medicine" show-search :filter-option="false"
                  :options="medicineOptions" class="rx-med" placeholder="Medicine"
                  :not-found-content="medLoading ? undefined : 'Type to search the pharmacy, or enter any name'"
                  @search="searchMedicines" @change="(v, o) => onMedicineChange(item, v, o)"
                >
                  <template v-if="medLoading" #notFoundContent><a-spin size="small" /></template>
                </a-select>
                <a-input v-model:value="item.dosage" placeholder="Dose" class="rx-sm" />
                <a-input v-model:value="item.frequency" placeholder="Frequency" class="rx-sm" />
                <a-input v-model:value="item.duration" placeholder="Duration" class="rx-sm" />
                <a-input-number v-model:value="item.quantity" :min="0" placeholder="Qty" class="rx-qty" />
                <a-input v-model:value="item.instructions" placeholder="Instructions" class="rx-note" />
                <a-button type="text" danger @click="items.splice(i, 1)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" description="Nothing prescribed" style="padding: 16px 0" />

            <a-form-item label="Prescription notes" style="margin: 12px 0 0">
              <a-textarea v-model:value="form.prescription_notes" :rows="2" />
            </a-form-item>
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card size="small" title="Vitals" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :span="12">
                <a-form-item label="Temperature °C" name="temperature">
                  <a-input-number v-model:value="form.temperature" :min="20" :max="50" :step="0.1" style="width: 100%" />
                  <VitalHint :tone="tone('temperature', form.temperature)" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="Pulse bpm" name="pulse">
                  <a-input-number v-model:value="form.pulse" :min="0" :max="300" style="width: 100%" />
                  <VitalHint :tone="tone('pulse', form.pulse)" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="BP systolic" name="bp_systolic">
                  <a-input-number v-model:value="form.bp_systolic" :min="0" :max="300" style="width: 100%" />
                  <VitalHint :tone="tone('bp_systolic', form.bp_systolic)" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="BP diastolic" name="bp_diastolic">
                  <a-input-number v-model:value="form.bp_diastolic" :min="0" :max="200" style="width: 100%" />
                  <VitalHint :tone="tone('bp_diastolic', form.bp_diastolic)" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="Resp. rate" name="respiratory_rate">
                  <a-input-number v-model:value="form.respiratory_rate" :min="0" :max="120" style="width: 100%" />
                  <VitalHint :tone="tone('respiratory_rate', form.respiratory_rate)" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="SpO₂ %" name="spo2">
                  <a-input-number v-model:value="form.spo2" :min="0" :max="100" style="width: 100%" />
                  <VitalHint :tone="tone('spo2', form.spo2)" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="Weight kg" name="weight">
                  <a-input-number v-model:value="form.weight" :min="0" :max="600" :step="0.1" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="Height cm" name="height">
                  <a-input-number v-model:value="form.height" :min="0" :max="300" :step="0.5" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>

            <div v-if="bmi" class="bmi">
              <span class="bmi-label">BMI</span>
              <span class="bmi-value">{{ bmi }}</span>
              <a-tag v-if="band" :color="band.color">{{ band.label }}</a-tag>
            </div>
          </a-card>

          <a-card v-if="patientSummary" size="small" title="Patient">
            <a-descriptions :column="1" size="small">
              <a-descriptions-item label="MRN">{{ patientSummary.mrn }}</a-descriptions-item>
              <a-descriptions-item label="Age">{{ patientSummary.age ?? '—' }}</a-descriptions-item>
              <a-descriptions-item label="Gender">{{ patientSummary.gender || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Phone">{{ patientSummary.phone || '—' }}</a-descriptions-item>
            </a-descriptions>
            <a-button type="link" size="small" style="padding: 0" @click="$router.push(`/hospital/patients/${form.patient_id}`)">
              Open full record
            </a-button>
          </a-card>
        </a-col>
      </a-row>

      <div class="form-foot">
        <a-button @click="$router.push('/hospital/visits')">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" size="large" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </div>
    </a-form>
  </div>
</template>

<script setup>
/**
 * The consultation screen: encounter details, clinical notes, vitals and the
 * prescription, saved together in one request (the backend wraps it in a
 * transaction — notes without the drugs just prescribed is a clinical hazard).
 *
 * Vitals are tinted against adult reference ranges as they are typed. That is a
 * prompt to look, not a diagnosis, and it stays silent for under-16s where
 * adult ranges do not apply.
 *
 * Medicine lines search the PHARMACY catalogue and keep the product id when one
 * matches, so billing can price the line and stock knows what left the shelf —
 * but any free-text drug name is still accepted for off-formulary items.
 */
import { ref, computed, onMounted, h } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PatientPicker from './PatientPicker.vue';
import { VISIT_TYPES, VISIT_STATUSES, vitalTone, bmiBand } from './hospitalOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const formRef = ref();
const loading = ref(false);
const saving = ref(false);

const form = ref(empty());
const items = ref([]);
const initialPatient = ref(null);
const patientSummary = ref(null);
const patientAlerts = ref('');

function stamp(d = new Date()) {
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

function empty() {
  return {
    patient_id: undefined, doctor_id: undefined, department_id: undefined,
    appointment_id: undefined, visit_date: stamp(), type: 'opd', status: 'completed',
    temperature: null, pulse: null, bp_systolic: null, bp_diastolic: null,
    respiratory_rate: null, spo2: null, weight: null, height: null,
    complaint: '', examination: '', diagnosis: '', treatment_plan: '',
    follow_up_date: null, fee: null, prescription_notes: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  patient_id: required(),
  visit_date: required(),
  type: required(),
  status: required(),
}));

const doctors = ref([]);
const departments = ref([]);
const doctorOptions = computed(() => doctors.value.map(d => ({ value: d.id, label: d.name })));
const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.name })));

// ---------------- vitals ----------------

const patientAge = computed(() => patientSummary.value?.age ?? null);

function tone(key, value) {
  return vitalTone(key, value, patientAge.value);
}

const bmi = computed(() => {
  const h = Number(form.value.height);
  const w = Number(form.value.weight);
  if (!h || !w) return null;
  return Math.round((w / ((h / 100) ** 2)) * 10) / 10;
});
const band = computed(() => bmiBand(bmi.value));

/** Tiny inline flag under a vital input; renders nothing when in range. */
const VitalHint = {
  props: { tone: { type: String, default: null } },
  setup(props) {
    return () => {
      if (!props.tone || props.tone === 'normal') return null;
      return h('span', { class: `vital-flag ${props.tone}` }, props.tone === 'low' ? 'Below range' : 'Above range');
    };
  },
};

// ---------------- prescription ----------------

const medicines = ref([]);
const medLoading = ref(false);
let medTimer = null;

const medicineOptions = computed(() =>
  medicines.value.map(m => ({ value: m.name, label: m.code ? `${m.name} · ${m.code}` : m.name, product: m })));

function searchMedicines(term) {
  clearTimeout(medTimer);
  medTimer = setTimeout(async () => {
    medLoading.value = true;
    try {
      const data = await http.get('hospital/search/medicines', { search: term });
      medicines.value = data?.medicines || [];
    } catch (e) {
      medicines.value = [];
    } finally {
      medLoading.value = false;
    }
  }, 300);
}

function onMedicineChange(item, value, option) {
  item.medicine = value;
  // Remember the catalogue id when the pick came from the pharmacy.
  item.product_id = option?.product?.id || null;
}

function addItem() {
  items.value.push({
    medicine: undefined, product_id: null, dosage: '', frequency: '',
    duration: '', quantity: 1, instructions: '',
  });
}

// ---------------- load & save ----------------

function onPatientSelect(patient) {
  patientSummary.value = patient;
  patientAlerts.value = '';
  if (!patient?.id) return;
  // Allergies must be on screen before anything is prescribed.
  http.get(`hospital/patients/${patient.id}`).then(data => {
    const p = data?.patient;
    patientSummary.value = { ...patient, age: p?.age ?? patient.age };
    const alerts = [p?.allergies ? `Allergies: ${p.allergies}` : null,
      p?.chronic_conditions ? `Chronic: ${p.chronic_conditions}` : null].filter(Boolean);
    patientAlerts.value = alerts.join(' · ');
  }).catch(() => {});
}

function onDoctorChange(id) {
  const doctor = doctors.value.find(d => d.id === id);
  if (!doctor) return;
  if (!form.value.fee) form.value.fee = doctor.consultation_fee || null;
  if (!form.value.department_id) form.value.department_id = doctor.department_id || undefined;
}

async function load() {
  loading.value = true;
  try {
    const meta = await http.get('hospital/meta').catch(() => null);
    doctors.value = meta?.doctors || [];
    departments.value = meta?.departments || [];

    if (isEdit.value) {
      const data = await http.get(`hospital/visits/${route.params.id}`);
      const v = data?.visit;
      if (v) {
        form.value = {
          ...empty(),
          patient_id: v.patient_id,
          doctor_id: v.doctor_id || undefined,
          department_id: v.department_id || undefined,
          appointment_id: v.appointment_id || undefined,
          visit_date: toStamp(v.visit_date),
          type: v.type,
          status: v.status,
          ...v.vitals,
          complaint: v.complaint || '',
          examination: v.examination || '',
          diagnosis: v.diagnosis || '',
          treatment_plan: v.treatment_plan || '',
          follow_up_date: v.follow_up_date,
          fee: v.fee,
          prescription_notes: v.prescription?.notes || '',
        };
        initialPatient.value = { id: v.patient_id, name: v.patient_name, mrn: v.patient_mrn };
        onPatientSelect(initialPatient.value);
        items.value = (v.prescription?.items || []).map(i => ({ ...i }));
      }
    } else if (route.query.appointment) {
      // Prefilled from a booked appointment.
      const data = await http.get(`hospital/visits/from-appointment/${route.query.appointment}`);
      const p = data?.prefill;
      if (p) {
        form.value = { ...form.value, ...p, visit_date: p.visit_date || stamp() };
        initialPatient.value = { id: p.patient_id, name: p.patient_name };
        onPatientSelect(initialPatient.value);
      }
    } else if (route.query.patient) {
      form.value.patient_id = Number(route.query.patient);
      onPatientSelect({ id: Number(route.query.patient) });
    }
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this consultation'));
  } finally {
    loading.value = false;
  }
}

function toStamp(iso) {
  if (!iso) return stamp();
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? stamp() : stamp(d);
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  const payload = {
    ...form.value,
    prescription_items: items.value.filter(i => i.medicine),
  };

  saving.value = true;
  try {
    if (isEdit.value) await http.put(`hospital/visits/${route.params.id}`, payload);
    else await http.post('hospital/visits', payload);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    router.push('/hospital/visits');
  } catch (e) {
    message.error(firstError(e) || t('InvalidData', 'Could not save this consultation'));
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

onMounted(load);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.alert {
  margin-bottom: 12px;
}
.rx-rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.rx-row {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
}
.rx-med {
  flex: 2 1 200px;
  min-width: 160px;
}
.rx-sm {
  flex: 1 1 90px;
  min-width: 80px;
}
.rx-qty {
  width: 80px;
  flex: none;
}
.rx-note {
  flex: 2 1 160px;
  min-width: 140px;
}
.bmi {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-top: 10px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}
.bmi-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.bmi-value {
  font-size: 17px;
  font-weight: 600;
}
.form-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}
:deep(.vital-flag) {
  display: block;
  margin-top: 2px;
  font-size: 11px;
  font-weight: 500;
}
:deep(.vital-flag.low) {
  color: #1677ff;
}
:deep(.vital-flag.high) {
  color: #ff4d4f;
}
</style>
