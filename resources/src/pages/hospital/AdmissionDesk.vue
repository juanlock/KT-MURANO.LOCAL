<template>
  <div class="page">
    <PageHeader :title="s('Admission desk')" :breadcrumb="[s('Hospital'), s('Admission desk')]">
      <template #actions>
        <a-button @click="load"><template #icon><ReloadOutlined /></template>{{ s('Refresh') }}</a-button>
      </template>
    </PageHeader>

    <div class="kpis">
      <div class="kpi"><div class="kpi-v">{{ desk.waiting_room.length }}</div><div class="kpi-l">{{ s('In the waiting room') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ desk.census.length }}</div><div class="kpi-l">{{ s('In the hospital') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ desk.er_beds.length }}</div><div class="kpi-l">{{ s('Free ER beds') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ desk.ward_beds.length }}</div><div class="kpi-l">{{ s('Free ward beds') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ desk.today.visitors_in || 0 }}</div><div class="kpi-l">{{ s('Visitors inside') }}</div></div>
    </div>

    <a-tabs v-model:activeKey="tab">
      <!-- New arrival -->
      <a-tab-pane v-if="canDesk" key="intake" :tab="s('New arrival')">
        <a-card size="small">
          <a-form layout="vertical">
            <div class="sec">1. {{ s('Patient') }}</div>
            <a-radio-group v-model:value="form.patientMode" button-style="solid" style="margin-bottom: 12px">
              <a-radio-button value="existing">{{ s('Registered patient') }}</a-radio-button>
              <a-radio-button value="new">{{ s('New patient') }}</a-radio-button>
            </a-radio-group>
            <a-form-item v-if="form.patientMode === 'existing'" :label="s('Patient')" required>
              <a-select v-model:value="form.patient_id" show-search :filter-option="false" :placeholder="s('Type a name, MRN or phone')"
                :options="patientOptions" @search="searchPatients" @focus="searchPatients('')" />
            </a-form-item>
            <a-row v-else :gutter="12">
              <a-col :xs="24" :md="8"><a-form-item :label="s('Name')" required><a-input v-model:value="form.new_patient.name" /></a-form-item></a-col>
              <a-col :xs="12" :md="4"><a-form-item :label="s('Gender')"><a-select v-model:value="form.new_patient.gender" :options="genderOptions" /></a-form-item></a-col>
              <a-col :xs="12" :md="4"><a-form-item :label="s('Date of birth')"><a-date-picker v-model:value="form.new_patient.date_of_birth" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="4"><a-form-item :label="s('ID document')"><a-input v-model:value="form.new_patient.national_id" /></a-form-item></a-col>
              <a-col :xs="12" :md="4"><a-form-item :label="s('Phone')"><a-input v-model:value="form.new_patient.phone" /></a-form-item></a-col>
            </a-row>

            <div class="sec">2. {{ s('Triage (initial assessment)') }}</div>
            <div class="triage-pick">
              <button v-for="t in TRIAGE" :key="t.value" type="button" class="tp" :class="{ on: form.triage_level === t.value }" :style="{ '--c': t.color }" @click="form.triage_level = t.value">
                <b>{{ t.value }}</b><span>{{ s(t.label) }}</span>
              </button>
            </div>
            <a-row :gutter="12">
              <a-col :xs="24" :md="12"><a-form-item :label="s('Chief complaint')"><a-textarea v-model:value="form.complaint" :rows="2" /></a-form-item></a-col>
              <a-col :xs="24" :md="12">
                <a-row :gutter="8">
                  <a-col :span="8"><a-form-item :label="s('Temp. °C')"><a-input-number v-model:value="form.temperature" :min="25" :max="45" :step="0.1" style="width: 100%" /></a-form-item></a-col>
                  <a-col :span="8"><a-form-item :label="s('Pulse')"><a-input-number v-model:value="form.pulse" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
                  <a-col :span="8"><a-form-item label="SpO₂ %"><a-input-number v-model:value="form.spo2" :min="0" :max="100" style="width: 100%" /></a-form-item></a-col>
                  <a-col :span="8"><a-form-item :label="s('BP sys.')"><a-input-number v-model:value="form.bp_systolic" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
                  <a-col :span="8"><a-form-item :label="s('BP dia.')"><a-input-number v-model:value="form.bp_diastolic" :min="0" :max="250" style="width: 100%" /></a-form-item></a-col>
                  <a-col :span="8"><a-form-item :label="s('Arrival')"><a-select v-model:value="form.arrival_mode" :options="arrivalOptions" /></a-form-item></a-col>
                </a-row>
              </a-col>
            </a-row>

            <div class="sec">3. {{ s('Destination') }}</div>
            <a-radio-group v-model:value="form.destination" button-style="solid" style="margin-bottom: 12px">
              <a-radio-button value="waiting_room">{{ s('Waiting room') }}</a-radio-button>
              <a-radio-button value="er_bed">{{ s('ER bed') }}</a-radio-button>
              <a-radio-button value="ward">{{ s('Ward bed (admit)') }}</a-radio-button>
            </a-radio-group>
            <a-row :gutter="12">
              <a-col v-if="form.destination !== 'waiting_room'" :xs="24" :md="12">
                <a-form-item :label="s('Bed')" required>
                  <a-select v-model:value="form.bed_id" show-search option-filter-prop="label" :options="bedOptions" :placeholder="s('Pick a free bed')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Doctor')">
                  <a-select v-model:value="form.doctor_id" allow-clear show-search option-filter-prop="label" :options="doctorOptions" :placeholder="s('Assign later')" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-button type="primary" size="large" :loading="saving" @click="submitIntake">{{ s('Register arrival') }}</a-button>
          </a-form>
        </a-card>
      </a-tab-pane>

      <!-- Waiting room -->
      <a-tab-pane key="waiting" :tab="`${s('Waiting room')} (${desk.waiting_room.length})`">
        <a-empty v-if="!desk.waiting_room.length" :description="s('The waiting room is empty')" />
        <div v-for="w in desk.waiting_room" :key="w.id" class="wait-row">
          <span class="triage" :style="{ background: (TRIAGE.find(t => t.value === w.triage_level) || {}).color || '#9ca3af' }">{{ w.triage_level || '?' }}</span>
          <div class="w-who">
            <b>{{ w.name }}</b> <span class="muted">{{ w.mrn }} · {{ w.reference }}</span>
            <div class="muted">{{ w.complaint || '—' }}</div>
          </div>
          <span class="w-time" :class="{ late: w.wait_minutes > (LIMIT[w.triage_level] ?? 999) }">{{ mins(w.wait_minutes) }}</span>
          <a-button v-if="canDesk" type="primary" @click="openCall(w)">{{ s('Call to a bed') }}</a-button>
        </div>
      </a-tab-pane>

      <!-- Visitors -->
      <a-tab-pane v-if="canVisitors" key="visitors" :tab="s('Visitors')">
        <a-card size="small" :title="s('Check a visitor in')" style="margin-bottom: 12px">
          <a-row :gutter="12">
            <a-col :xs="24" :md="8"><a-form-item :label="s('Patient to visit')"><a-select v-model:value="visit.patient_id" show-search option-filter-prop="label" :options="censusOptions" :placeholder="s('Patients in the hospital')" /></a-form-item></a-col>
            <a-col :xs="24" :md="6"><a-form-item :label="s('Visitor')"><a-input v-model:value="visit.name" /></a-form-item></a-col>
            <a-col :xs="12" :md="4"><a-form-item :label="s('ID document')"><a-input v-model:value="visit.document" /></a-form-item></a-col>
            <a-col :xs="12" :md="3"><a-form-item :label="s('Relationship')"><a-input v-model:value="visit.relation" /></a-form-item></a-col>
            <a-col :xs="12" :md="3"><a-form-item :label="s('Phone')"><a-input v-model:value="visit.phone" /></a-form-item></a-col>
          </a-row>
          <a-button type="primary" :loading="saving" @click="checkIn">{{ s('Check in') }}</a-button>
        </a-card>
        <a-table :data-source="visitors" :columns="visitorColumns" row-key="id" size="small" :pagination="{ pageSize: 20 }" :scroll="{ x: 800 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'in'">{{ dt(record.checked_in_at) }}</template>
            <template v-else-if="column.key === 'out'">
              <span v-if="record.checked_out_at">{{ dt(record.checked_out_at) }}</span>
              <a-button v-else size="small" @click="checkOut(record)">{{ s('Check out') }}</a-button>
            </template>
          </template>
        </a-table>
      </a-tab-pane>

      <!-- Census -->
      <a-tab-pane key="census" :tab="`${s('In the hospital')} (${desk.census.length})`">
        <a-table :data-source="desk.census" :columns="censusColumns" row-key="patient_id" size="small" :pagination="{ pageSize: 30 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'area'">{{ s(AREA_LABEL[record.area] || record.area) }}<template v-if="record.where"> · {{ record.where }}</template></template>
            <template v-else-if="column.key === 'since'">{{ dt(record.since) }}</template>
          </template>
        </a-table>
      </a-tab-pane>
    </a-tabs>

    <a-modal v-model:open="callOpen" :title="callTarget ? `${s('Call to a bed')}: ${callTarget.name}` : ''" :ok-text="s('Assign')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="callIn">
      <a-radio-group v-model:value="callKind" button-style="solid" style="margin-bottom: 10px">
        <a-radio-button value="er">{{ s('ER bed') }}</a-radio-button>
        <a-radio-button value="ward">{{ s('Ward bed (admit)') }}</a-radio-button>
      </a-radio-group>
      <a-select v-model:value="callBed" style="width: 100%" show-search option-filter-prop="label" :placeholder="s('Pick a free bed')"
        :options="callKind === 'er' ? erBedOptions : wardBedOptions" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Admission desk (2026-10-21): the hospital's single front door -- register
 * the patient (registered or new) with a triage and send them to the waiting
 * room, an ER bed or a ward bed (admitted); call the waiting room into beds;
 * check visitors in / out; see who is in the hospital and where.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/AdmissionDesk.es.js';

const { s } = useHospitalText(ES);
const auth = useAuthStore();
const canDesk = computed(() => auth.can('hms_admission_desk'));
const canVisitors = computed(() => auth.can('hms_visitors') || auth.can('hms_admission_desk'));

const TRIAGE = [
  { value: 1, label: 'Resuscitation', color: '#dc2626' },
  { value: 2, label: 'Emergency', color: '#ea580c' },
  { value: 3, label: 'Urgent', color: '#ca8a04' },
  { value: 4, label: 'Less urgent', color: '#16a34a' },
  { value: 5, label: 'Not urgent', color: '#2563eb' },
];
const LIMIT = { 1: 0, 2: 10, 3: 30, 4: 60, 5: 120 };
const AREA_LABEL = { ward: 'Ward', er_bed: 'ER bed', waiting_room: 'Waiting room' };
const ARRIVALS = ['walk_in', 'ambulance', 'referral', 'police', 'other'];
const ARRIVAL_LABEL = { walk_in: 'Walk-in', ambulance: 'Ambulance', referral: 'Referral', police: 'Police', other: 'Other' };

const tab = ref(canDesk.value ? 'intake' : 'waiting');
const saving = ref(false);
const desk = ref({ waiting_room: [], er_beds: [], ward_beds: [], doctors: [], census: [], today: {} });
const visitors = ref([]);
const patientOptions = ref([]);

const mins = m => (m == null ? '—' : m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`);
const dt = iso => (iso ? dayjs(iso).format('DD/MM HH:mm') : '—');
const genderOptions = computed(() => [{ value: 'male', label: s('Male') }, { value: 'female', label: s('Female') }, { value: 'other', label: s('Other') }]);
const arrivalOptions = computed(() => ARRIVALS.map(a => ({ value: a, label: s(ARRIVAL_LABEL[a]) })));
const doctorOptions = computed(() => sortByLabel(desk.value.doctors.map(d => ({ value: d.id, label: d.specialty ? `${d.name} (${d.specialty})` : d.name }))));
const erBedOptions = computed(() => sortByLabel(desk.value.er_beds.map(b => ({ value: b.id, label: b.label }))));
const wardBedOptions = computed(() => sortByLabel(desk.value.ward_beds.map(b => ({ value: b.id, label: b.label }))));
const bedOptions = computed(() => (form.value.destination === 'er_bed' ? erBedOptions.value : wardBedOptions.value));
const censusOptions = computed(() => sortByLabel(desk.value.census.map(c => ({ value: c.patient_id, label: `${c.name} · ${s(AREA_LABEL[c.area] || c.area)}${c.where ? ' · ' + c.where : ''}` }))));
const visitorColumns = computed(() => [
  { title: s('Visitor'), dataIndex: 'name' }, { title: s('ID document'), dataIndex: 'document' }, { title: s('Relationship'), dataIndex: 'relation' },
  { title: s('Patient'), dataIndex: 'patient_name' }, { title: s('Badge'), dataIndex: 'badge', width: 110 },
  { title: s('In'), key: 'in', width: 120 }, { title: s('Out'), key: 'out', width: 120 },
]);
const censusColumns = computed(() => [
  { title: s('Patient'), dataIndex: 'name' }, { title: 'HC', dataIndex: 'mrn', width: 120 },
  { title: s('Where'), key: 'area' }, { title: s('Since'), key: 'since', width: 140 },
]);

function emptyForm() {
  return {
    patientMode: 'existing', patient_id: undefined, new_patient: { name: '', gender: 'male', date_of_birth: null, national_id: '', phone: '' },
    triage_level: 3, complaint: '', temperature: null, pulse: null, spo2: null, bp_systolic: null, bp_diastolic: null, arrival_mode: 'walk_in',
    destination: 'waiting_room', bed_id: undefined, doctor_id: undefined,
  };
}
const form = ref(emptyForm());
const visit = reactive({ patient_id: undefined, name: '', document: '', relation: '', phone: '' });

async function load() {
  try {
    desk.value = await http.get('hospital/desk');
    if (canVisitors.value) visitors.value = (await http.get('hospital/visitors'))?.visitors || [];
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  }
}
let st = null;
function searchPatients(q) {
  clearTimeout(st);
  st = setTimeout(async () => {
    try {
      const d = await http.get('hospital/er/patients', { search: q || '' });
      patientOptions.value = (d?.patients || []).map(p => ({ value: p.id, label: `${p.name} · ${p.mrn}` }));
    } catch (e) { patientOptions.value = []; }
  }, 250);
}
async function submitIntake() {
  const f = form.value;
  if (f.patientMode === 'existing' && !f.patient_id) return message.warning(s('Pick the patient.'));
  if (f.patientMode === 'new' && !f.new_patient.name.trim()) return message.warning(s("Type the patient's name."));
  if (f.destination !== 'waiting_room' && !f.bed_id) return message.warning(s('Pick a free bed'));
  const body = {
    destination: f.destination, bed_id: f.bed_id || null, doctor_id: f.doctor_id || null, triage_level: f.triage_level,
    complaint: f.complaint, temperature: f.temperature, pulse: f.pulse, spo2: f.spo2, bp_systolic: f.bp_systolic, bp_diastolic: f.bp_diastolic,
    arrival_mode: f.arrival_mode,
  };
  if (f.patientMode === 'existing') body.patient_id = f.patient_id; else body.new_patient = f.new_patient;
  saving.value = true;
  try {
    await http.post('hospital/desk/intake', body);
    message.success(s('Arrival registered'));
    form.value = emptyForm();
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

const callOpen = ref(false);
const callTarget = ref(null);
const callKind = ref('er');
const callBed = ref(undefined);
function openCall(w) {
  callTarget.value = w;
  callKind.value = 'er';
  callBed.value = undefined;
  callOpen.value = true;
}
async function callIn() {
  if (!callBed.value) return message.warning(s('Pick a free bed'));
  saving.value = true;
  try {
    await http.post(`hospital/desk/waiting/${callTarget.value.id}/call`, { bed_id: callBed.value });
    callOpen.value = false;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

async function checkIn() {
  if (!visit.patient_id || !visit.name.trim()) return message.warning(s('Pick the patient and type the visitor.'));
  saving.value = true;
  try {
    const d = await http.post('hospital/visitors', { ...visit });
    message.success(`${s('Visitor checked in')} · ${d.badge}`);
    Object.assign(visit, { patient_id: undefined, name: '', document: '', relation: '', phone: '' });
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}
async function checkOut(r) {
  try {
    await http.post(`hospital/visitors/${r.id}/checkout`, {});
    load();
  } catch (e) {
    message.error(s('Could not save'));
  }
}

let timer = null;
onMounted(() => { load(); timer = setInterval(load, 60000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 12px; }
.kpi { padding: 12px 14px; border-radius: 12px; background: rgba(128, 128, 128, 0.08); }
.kpi-v { font-size: 22px; font-weight: 700; }
.kpi-l { font-size: 12px; opacity: 0.65; }
.sec { font-weight: 700; margin: 6px 0 10px; padding-bottom: 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.2); }
.triage-pick { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; margin-bottom: 12px; max-width: 640px; }
.tp { border: 2px solid var(--c); background: transparent; color: inherit; border-radius: 10px; padding: 6px 4px; cursor: pointer; display: flex; flex-direction: column; align-items: center; font: inherit; font-size: 12px; }
.tp b { font-size: 18px; color: var(--c); }
.tp.on { background: var(--c); color: #fff; }
.tp.on b { color: #fff; }
.wait-row { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 10px; margin-bottom: 8px; }
.triage { width: 30px; height: 30px; border-radius: 50%; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; flex: none; }
.w-who { flex: 1; min-width: 0; }
.w-time { white-space: nowrap; }
.w-time.late { color: #dc2626; font-weight: 700; }
.muted { opacity: 0.6; font-size: 12px; }
@media (max-width: 560px) { .triage-pick { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>
