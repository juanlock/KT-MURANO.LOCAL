<template>
  <div class="page">
    <PageHeader :title="s('Emergency Room')" :breadcrumb="[s('Hospital'), s('Emergency Room')]">
      <template #actions>
        <a-button @click="load">
          <template #icon><ReloadOutlined /></template>
          {{ s('Refresh') }}
        </a-button>
        <a-button type="primary" danger @click="openIntake(null)">
          <template #icon><PlusOutlined /></template>
          {{ s('New arrival') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- KPIs -->
    <div class="kpis">
      <div class="kpi"><div class="kpi-v">{{ kpis.waiting ?? 0 }}</div><div class="kpi-l">{{ s('Waiting') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ kpis.in_treatment ?? 0 }}</div><div class="kpi-l">{{ s('In treatment') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ kpis.observation ?? 0 }}</div><div class="kpi-l">{{ s('Observation') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ kpis.arrivals_today ?? 0 }}</div><div class="kpi-l">{{ s('Arrivals today') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ kpis.avg_wait_minutes != null ? minutes(kpis.avg_wait_minutes) : '—' }}</div><div class="kpi-l">{{ s('Average wait today') }}</div></div>
      <div class="kpi"><div class="kpi-v">{{ kpis.beds_free ?? 0 }} / {{ kpis.beds_total ?? 0 }}</div><div class="kpi-l">{{ s('ER beds free') }}</div></div>
    </div>

    <a-tabs v-model:activeKey="tab">
      <a-tab-pane key="board" :tab="s('Board')">
        <!-- ER beds -->
        <div class="beds">
          <span class="beds-title">{{ s('ER beds') }}:</span>
          <span v-if="!erBeds.length" class="muted">{{ s('No ER beds yet: add a ward of type "Emergency" in Wards & Beds.') }}</span>
          <a-tag v-for="b in erBeds" :key="b.id" :color="b.status === 'available' ? 'success' : (b.status === 'occupied' ? 'error' : 'default')">
            {{ b.bed_number }}<template v-if="bedHolder(b.id)"> · {{ bedHolder(b.id) }}</template>
          </a-tag>
        </div>

        <a-spin :spinning="loading">
          <div class="board">
            <div v-for="col in COLUMNS" :key="col.key" class="col">
              <div class="col-head">
                <span>{{ s(col.label) }}</span>
                <a-badge :count="byStatus(col.key).length" :number-style="{ backgroundColor: col.color }" show-zero />
              </div>
              <div v-if="!byStatus(col.key).length" class="empty">{{ s('No patients') }}</div>
              <div
                v-for="c in byStatus(col.key)" :key="c.id" class="case"
                :style="{ borderLeftColor: triageOf(c.triage_level).color }"
              >
                <div class="case-top">
                  <span class="triage" :style="{ background: triageOf(c.triage_level).color }">
                    {{ c.triage_level || '?' }}
                  </span>
                  <div class="case-who">
                    <div class="case-name">{{ c.patient_name }}</div>
                    <div class="case-sub">
                      {{ c.patient_mrn }}<template v-if="c.patient_age != null"> · {{ c.patient_age }} {{ s('y') }}</template>
                      <template v-if="c.patient_gender"> · {{ s(genderLabel(c.patient_gender)) }}</template>
                    </div>
                  </div>
                  <div class="case-wait" :class="{ late: isLate(c) }" :title="s('Time waiting / in the ER')">
                    <ClockCircleOutlined /> {{ minutes(liveMinutes(c)) }}
                  </div>
                </div>
                <div class="case-complaint">{{ c.complaint || s('No complaint recorded') }}</div>
                <div v-if="c.allergies" class="case-allergy"><WarningOutlined /> {{ s('Allergies') }}: {{ c.allergies }}</div>
                <div class="case-meta">
                  <span>{{ s(triageOf(c.triage_level).label) }}</span>
                  <span>· {{ s(arrivalLabel(c.arrival_mode)) }}</span>
                  <span v-if="c.bed_number">· {{ s('Bed') }} {{ c.bed_number }}</span>
                  <span v-if="c.doctor_name">· {{ c.doctor_name }}</span>
                  <span v-else class="warn">· {{ s('No doctor') }}</span>
                </div>
                <div v-if="hasVitals(c)" class="case-vitals">
                  <span v-if="c.temperature">T {{ c.temperature }}°</span>
                  <span v-if="c.pulse">{{ s('HR') }} {{ c.pulse }}</span>
                  <span v-if="c.bp_systolic">{{ s('BP') }} {{ c.bp_systolic }}/{{ c.bp_diastolic || '—' }}</span>
                  <span v-if="c.respiratory_rate">{{ s('RR') }} {{ c.respiratory_rate }}</span>
                  <span v-if="c.spo2" :class="{ warn: c.spo2 < 92 }">SpO₂ {{ c.spo2 }}%</span>
                  <span v-if="c.pain_score != null">{{ s('Pain') }} {{ c.pain_score }}/10</span>
                </div>
                <div class="case-actions">
                  <a-button v-if="c.status === 'waiting'" size="small" type="primary" @click="setStatus(c, 'in_treatment')">{{ s('Start treatment') }}</a-button>
                  <a-button v-if="c.status === 'in_treatment'" size="small" @click="setStatus(c, 'observation')">{{ s('To observation') }}</a-button>
                  <a-button v-if="c.status === 'observation'" size="small" @click="setStatus(c, 'in_treatment')">{{ s('Back to treatment') }}</a-button>
                  <a-button size="small" @click="openIntake(c)"><EditOutlined /></a-button>
                  <a-button size="small" type="primary" ghost @click="openClose(c)">{{ s('Disposition') }}</a-button>
                </div>
              </div>
            </div>
          </div>
        </a-spin>

        <a-card size="small" :title="s('Closed in the last 24 hours')" style="margin-top: 16px">
          <a-table :data-source="closedRecent" :columns="closedColumns" row-key="id" size="small" :pagination="false" :scroll="{ x: 800 }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'patient'">
                <PermLink :to="`/hospital/patients/${record.patient_id}`" permission="hms_patients_view">{{ record.patient_name }}</PermLink>
                <div class="cell-sub">{{ record.reference }}</div>
              </template>
              <template v-else-if="column.key === 'triage'">
                <span class="triage sm" :style="{ background: triageOf(record.triage_level).color }">{{ record.triage_level || '?' }}</span>
              </template>
              <template v-else-if="column.key === 'disposition'">
                <a-tag :color="dispositionOf(record.disposition).color">{{ s(dispositionOf(record.disposition).label) }}</a-tag>
                <div v-if="record.admission_reference" class="cell-sub">{{ record.admission_reference }} · {{ record.admission_bed }}</div>
              </template>
              <template v-else-if="column.key === 'time'">{{ minutes(record.minutes_in_er) }}</template>
              <template v-else-if="column.key === 'closed_at'">{{ dateTime(record.closed_at) }}</template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>

      <a-tab-pane key="history" :tab="s('History')">
        <a-card size="small" style="margin-bottom: 12px">
          <div class="toolbar">
            <a-input-search v-model:value="hist.search" :placeholder="s('Search patient, MRN or reference…')" allow-clear class="tb-search" @search="loadHistory(1)" />
            <a-select v-model:value="hist.disposition" class="tb-item" allow-clear :placeholder="s('All dispositions')" :options="dispositionOptions" @change="loadHistory(1)" />
            <PresetRangeFilter v-model:value="hist.range" class="tb-range" value-format="YYYY-MM-DD" @change="loadHistory(1)" />
          </div>
        </a-card>
        <a-table
          :data-source="hist.rows" :columns="historyColumns" row-key="id" size="small" :loading="hist.loading" :scroll="{ x: 900 }"
          :pagination="{ current: hist.page, pageSize: 20, total: hist.total, showSizeChanger: false }"
          @change="p => loadHistory(p.current)"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'patient'">
              <PermLink :to="`/hospital/patients/${record.patient_id}`" permission="hms_patients_view">{{ record.patient_name }}</PermLink>
              <div class="cell-sub">{{ record.patient_mrn }} · {{ record.reference }}</div>
            </template>
            <template v-else-if="column.key === 'arrived_at'">{{ dateTime(record.arrived_at) }}</template>
            <template v-else-if="column.key === 'triage'">
              <span class="triage sm" :style="{ background: triageOf(record.triage_level).color }">{{ record.triage_level || '?' }}</span>
            </template>
            <template v-else-if="column.key === 'wait'">{{ minutes(record.wait_minutes) }}</template>
            <template v-else-if="column.key === 'time'">{{ minutes(record.minutes_in_er) }}</template>
            <template v-else-if="column.key === 'disposition'">
              <a-tag :color="dispositionOf(record.disposition).color">{{ s(dispositionOf(record.disposition).label) }}</a-tag>
              <div v-if="record.admission_reference" class="cell-sub">{{ record.admission_reference }}</div>
            </template>
          </template>
        </a-table>
      </a-tab-pane>
    </a-tabs>

    <!-- Intake / edit: everything on one screen -->
    <a-drawer
      v-model:open="intakeOpen" :title="editing ? `${s('Edit case')} ${editing.reference}` : s('New arrival')"
      :width="drawerWidth" destroy-on-close
    >
      <a-form layout="vertical">
        <div class="sec">1. {{ s('Patient') }}</div>
        <template v-if="!editing">
          <a-radio-group v-model:value="form.patientMode" button-style="solid" style="margin-bottom: 12px">
            <a-radio-button value="existing">{{ s('Registered patient') }}</a-radio-button>
            <a-radio-button value="new">{{ s('New patient') }}</a-radio-button>
          </a-radio-group>
          <a-form-item v-if="form.patientMode === 'existing'" :label="s('Patient')" required>
            <a-select
              v-model:value="form.patient_id" show-search :filter-option="false" :placeholder="s('Type a name, MRN or phone')"
              :options="patientOptions" :loading="searching" @search="searchPatients" @focus="searchPatients('')"
            />
          </a-form-item>
          <a-row v-else :gutter="12">
            <a-col :xs="24" :sm="12"><a-form-item :label="s('Name')" required><a-input v-model:value="form.new_patient.name" /></a-form-item></a-col>
            <a-col :xs="12" :sm="6">
              <a-form-item :label="s('Gender')">
                <a-select v-model:value="form.new_patient.gender" :options="genderOptions" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :sm="6"><a-form-item :label="s('Date of birth')"><a-date-picker v-model:value="form.new_patient.date_of_birth" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
            <a-col :xs="24" :sm="12"><a-form-item :label="s('Phone')"><a-input v-model:value="form.new_patient.phone" /></a-form-item></a-col>
          </a-row>
        </template>
        <div v-else class="target">{{ editing.patient_name }} · {{ editing.patient_mrn }}</div>

        <div class="sec">2. {{ s('Triage') }}</div>
        <div class="triage-pick">
          <button
            v-for="t in TRIAGE" :key="t.value" type="button" class="tp" :class="{ on: form.triage_level === t.value }"
            :style="{ '--c': t.color }" @click="form.triage_level = t.value"
          >
            <b>{{ t.value }}</b>
            <span>{{ s(t.label) }}</span>
            <small>{{ s(t.hint) }}</small>
          </button>
        </div>
        <a-row :gutter="12">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="s('Arrival')">
              <a-select v-model:value="form.arrival_mode" :options="arrivalOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="s('Pain (0-10)')">
              <a-slider v-model:value="form.pain_score" :min="0" :max="10" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="s('Chief complaint')">
          <a-textarea v-model:value="form.complaint" :rows="2" />
        </a-form-item>

        <div class="sec">3. {{ s('Vital signs') }}</div>
        <a-row :gutter="12">
          <a-col :xs="12" :sm="8"><a-form-item :label="s('Temperature °C')"><a-input-number v-model:value="form.temperature" :min="25" :max="45" :step="0.1" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="8"><a-form-item :label="s('Pulse (bpm)')"><a-input-number v-model:value="form.pulse" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="8"><a-form-item :label="s('Respiratory rate')"><a-input-number v-model:value="form.respiratory_rate" :min="0" :max="80" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="8"><a-form-item :label="s('BP systolic')"><a-input-number v-model:value="form.bp_systolic" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="8"><a-form-item :label="s('BP diastolic')"><a-input-number v-model:value="form.bp_diastolic" :min="0" :max="250" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="8"><a-form-item label="SpO₂ %"><a-input-number v-model:value="form.spo2" :min="0" :max="100" style="width: 100%" /></a-form-item></a-col>
        </a-row>

        <div class="sec">4. {{ s('Assignment') }}</div>
        <a-row :gutter="12">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="s('Doctor')">
              <a-select v-model:value="form.doctor_id" allow-clear show-search option-filter-prop="label" :options="doctorOptions" :placeholder="s('Assign later')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="s('ER bed')">
              <a-select v-model:value="form.bed_id" allow-clear :options="bedOptions" :placeholder="s('No bed (waiting room)')" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item v-if="editing" :label="s('Working diagnosis')">
          <a-textarea v-model:value="form.diagnosis" :rows="2" />
        </a-form-item>
        <a-form-item :label="s('Notes')">
          <a-textarea v-model:value="form.notes" :rows="2" />
        </a-form-item>
      </a-form>
      <template #footer>
        <a-space style="float: right">
          <a-button @click="intakeOpen = false">{{ s('Cancel') }}</a-button>
          <a-button type="primary" :loading="saving" @click="saveIntake">{{ editing ? s('Save') : s('Register arrival') }}</a-button>
        </a-space>
      </template>
    </a-drawer>

    <!-- Disposition -->
    <a-modal v-model:open="closeOpen" :title="s('Disposition')" :confirm-loading="saving" :ok-text="s('Close case')" :cancel-text="s('Cancel')" @ok="saveClose">
      <div v-if="target" class="target">{{ target.patient_name }} · {{ target.reference }}</div>
      <a-form layout="vertical">
        <a-form-item :label="s('Outcome')" required>
          <a-radio-group v-model:value="closeForm.disposition">
            <a-radio v-for="d in DISPOSITIONS" :key="d.value" :value="d.value" style="display: block; margin-bottom: 6px">{{ s(d.label) }}</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item v-if="closeForm.disposition === 'admitted'" :label="s('Admit to bed')" required>
          <a-select v-model:value="closeForm.admit_bed_id" show-search option-filter-prop="label" :options="admitBedOptions" :placeholder="s('Pick a free ward bed')" />
        </a-form-item>
        <a-form-item :label="s('Doctor')">
          <a-select v-model:value="closeForm.doctor_id" allow-clear show-search option-filter-prop="label" :options="doctorOptions" />
        </a-form-item>
        <a-form-item :label="s('Diagnosis')">
          <a-textarea v-model:value="closeForm.diagnosis" :rows="2" />
        </a-form-item>
        <a-form-item :label="s('Notes / instructions')">
          <a-textarea v-model:value="closeForm.disposition_notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Emergency Room (2026-10-21): the whole ER on one screen -- intake (a
 * registered patient or a quick new one), triage 1-5, vitals, doctor and ER
 * bed, the board (waiting -> in treatment -> observation) and the disposition
 * (discharged, admitted to a ward bed, transferred, left without being seen,
 * deceased). Refreshes every minute.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, ReloadOutlined, ClockCircleOutlined, WarningOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/EmergencyRoom.es.js';

const { s } = useHospitalText(ES);
const { dateTime } = useFormat();

const TRIAGE = [
  { value: 1, label: 'Resuscitation', hint: 'Immediate', color: '#dc2626' },
  { value: 2, label: 'Emergency', hint: '10 min', color: '#ea580c' },
  { value: 3, label: 'Urgent', hint: '30 min', color: '#ca8a04' },
  { value: 4, label: 'Less urgent', hint: '60 min', color: '#16a34a' },
  { value: 5, label: 'Not urgent', hint: '120 min', color: '#2563eb' },
];
const TARGET_MIN = { 1: 0, 2: 10, 3: 30, 4: 60, 5: 120 };
const COLUMNS = [
  { key: 'waiting', label: 'Waiting', color: '#ea580c' },
  { key: 'in_treatment', label: 'In treatment', color: '#2563eb' },
  { key: 'observation', label: 'Observation', color: '#7c3aed' },
];
const DISPOSITIONS = [
  { value: 'discharged', label: 'Discharged home', color: 'success' },
  { value: 'admitted', label: 'Admitted to hospital', color: 'processing' },
  { value: 'transferred', label: 'Transferred to another facility', color: 'warning' },
  { value: 'lwbs', label: 'Left without being seen', color: 'default' },
  { value: 'deceased', label: 'Deceased', color: 'error' },
];
const ARRIVALS = [
  { value: 'walk_in', label: 'Walk-in' },
  { value: 'ambulance', label: 'Ambulance' },
  { value: 'referral', label: 'Referral' },
  { value: 'police', label: 'Police' },
  { value: 'other', label: 'Other' },
];
const GENDERS = [{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }, { value: 'other', label: 'Other' }];

const tab = ref('board');
const loading = ref(false);
const cases = ref([]);
const kpis = ref({});
const doctors = ref([]);
const erBeds = ref([]);
const admitBeds = ref([]);
const now = ref(Date.now());

const triageOf = lvl => TRIAGE.find(t => t.value === lvl) || { label: 'Not triaged', color: '#9ca3af' };
const dispositionOf = v => DISPOSITIONS.find(d => d.value === v) || { label: v || '—', color: 'default' };
const arrivalLabel = v => (ARRIVALS.find(a => a.value === v) || { label: v || '' }).label;
const genderLabel = v => (GENDERS.find(g => g.value === v) || { label: v }).label;

const byStatus = st => cases.value.filter(c => c.status === st);
const closedRecent = computed(() => cases.value.filter(c => c.status === 'closed'));
const bedHolder = id => (cases.value.find(c => c.bed_id === id && c.status !== 'closed') || {}).patient_name;
const hasVitals = c => c.temperature || c.pulse || c.bp_systolic || c.respiratory_rate || c.spo2 || c.pain_score != null;

function liveMinutes(c) {
  if (!c.arrived_at) return 0;
  return Math.max(0, Math.round((now.value - new Date(c.arrived_at).getTime()) / 60000));
}
function isLate(c) {
  if (c.status !== 'waiting' || !c.triage_level) return false;
  return liveMinutes(c) > TARGET_MIN[c.triage_level];
}
function minutes(m) {
  if (m == null) return '—';
  const h = Math.floor(m / 60);
  return h ? `${h} h ${m % 60} min` : `${m} min`;
}

const doctorOptions = computed(() => sortByLabel(doctors.value.map(d => ({ value: d.id, label: d.specialty ? `${d.name} (${d.specialty})` : d.name }))));
const bedOptions = computed(() => sortByLabel(erBeds.value
  .filter(b => b.status === 'available' || (editing.value && b.id === editing.value.bed_id))
  .map(b => ({ value: b.id, label: b.ward_name ? `${b.bed_number} · ${b.ward_name}` : b.bed_number }))));
const admitBedOptions = computed(() => sortByLabel(admitBeds.value.map(b => ({ value: b.id, label: `${b.ward_name} · ${b.bed_number}` }))));
const arrivalOptions = computed(() => ARRIVALS.map(a => ({ value: a.value, label: s(a.label) })));
const genderOptions = computed(() => GENDERS.map(g => ({ value: g.value, label: s(g.label) })));
const dispositionOptions = computed(() => DISPOSITIONS.map(d => ({ value: d.value, label: s(d.label) })));

const closedColumns = computed(() => [
  { title: s('Patient'), key: 'patient' },
  { title: s('Triage'), key: 'triage', width: 70 },
  { title: s('Complaint'), dataIndex: 'complaint', ellipsis: true },
  { title: s('Outcome'), key: 'disposition' },
  { title: s('Time in ER'), key: 'time', width: 110 },
  { title: s('Closed'), key: 'closed_at', width: 160 },
]);
const historyColumns = computed(() => [
  { title: s('Arrival'), key: 'arrived_at', width: 160 },
  { title: s('Patient'), key: 'patient' },
  { title: s('Triage'), key: 'triage', width: 70 },
  { title: s('Complaint'), dataIndex: 'complaint', ellipsis: true },
  { title: s('Doctor'), dataIndex: 'doctor_name' },
  { title: s('Wait'), key: 'wait', width: 100 },
  { title: s('Time in ER'), key: 'time', width: 110 },
  { title: s('Outcome'), key: 'disposition' },
]);

async function load() {
  loading.value = true;
  try {
    const d = await http.get('hospital/er/board');
    cases.value = d?.cases || [];
    kpis.value = d?.kpis || {};
    doctors.value = d?.doctors || [];
    erBeds.value = d?.er_beds || [];
    admitBeds.value = d?.admit_beds || [];
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  } finally {
    loading.value = false;
  }
}

// ---------------- history ----------------
const hist = reactive({ rows: [], total: 0, page: 1, loading: false, search: '', disposition: undefined, range: null });
async function loadHistory(page = 1) {
  hist.page = page;
  hist.loading = true;
  try {
    const d = await http.get('hospital/er/history', {
      page, limit: 20, search: hist.search || '', disposition: hist.disposition || '',
      start_date: hist.range?.[0] || '', end_date: hist.range?.[1] || '',
    });
    hist.rows = d?.cases || [];
    hist.total = d?.totalRows || 0;
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  } finally {
    hist.loading = false;
  }
}

// ---------------- intake ----------------
const intakeOpen = ref(false);
const editing = ref(null);
const saving = ref(false);
const searching = ref(false);
const patientOptions = ref([]);
const drawerWidth = computed(() => (window.innerWidth < 760 ? '100%' : 720));

function empty() {
  return {
    patientMode: 'existing', patient_id: undefined,
    new_patient: { name: '', gender: 'male', date_of_birth: null, phone: '' },
    triage_level: 3, arrival_mode: 'walk_in', complaint: '', pain_score: 0,
    temperature: null, pulse: null, bp_systolic: null, bp_diastolic: null, respiratory_rate: null, spo2: null,
    doctor_id: undefined, bed_id: undefined, diagnosis: '', notes: '',
  };
}
const form = ref(empty());

function openIntake(c) {
  editing.value = c;
  form.value = c
    ? {
        ...empty(), patient_id: c.patient_id, triage_level: c.triage_level, arrival_mode: c.arrival_mode || 'walk_in',
        complaint: c.complaint || '', pain_score: c.pain_score ?? 0, temperature: c.temperature, pulse: c.pulse,
        bp_systolic: c.bp_systolic, bp_diastolic: c.bp_diastolic, respiratory_rate: c.respiratory_rate, spo2: c.spo2,
        doctor_id: c.doctor_id || undefined, bed_id: c.bed_id || undefined, diagnosis: c.diagnosis || '', notes: c.notes || '',
      }
    : empty();
  intakeOpen.value = true;
}

let searchTimer = null;
function searchPatients(q) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    searching.value = true;
    try {
      const d = await http.get('hospital/er/patients', { search: q || '' });
      patientOptions.value = (d?.patients || []).map(p => ({
        value: p.id,
        label: `${p.name} · ${p.mrn}${p.age != null ? ` · ${p.age} ${s('y')}` : ''}`,
      }));
    } catch (e) {
      patientOptions.value = [];
    } finally {
      searching.value = false;
    }
  }, 250);
}

async function saveIntake() {
  const f = form.value;
  if (!editing.value) {
    if (f.patientMode === 'existing' && !f.patient_id) return message.warning(s('Pick the patient.'));
    if (f.patientMode === 'new' && !f.new_patient.name.trim()) return message.warning(s('Type the patient\'s name.'));
  }
  const body = {
    triage_level: f.triage_level, arrival_mode: f.arrival_mode, complaint: f.complaint, pain_score: f.pain_score,
    temperature: f.temperature, pulse: f.pulse, bp_systolic: f.bp_systolic, bp_diastolic: f.bp_diastolic,
    respiratory_rate: f.respiratory_rate, spo2: f.spo2, doctor_id: f.doctor_id || null, bed_id: f.bed_id || null,
    diagnosis: f.diagnosis, notes: f.notes,
  };
  if (!editing.value) {
    if (f.patientMode === 'existing') body.patient_id = f.patient_id;
    else body.new_patient = f.new_patient;
  }
  saving.value = true;
  try {
    if (editing.value) await http.put(`hospital/er/${editing.value.id}`, body);
    else await http.post('hospital/er', body);
    message.success(s('Saved'));
    intakeOpen.value = false;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

async function setStatus(c, status) {
  try {
    await http.post(`hospital/er/${c.id}/status`, { status });
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}

// ---------------- disposition ----------------
const closeOpen = ref(false);
const target = ref(null);
const closeForm = reactive({ disposition: 'discharged', admit_bed_id: undefined, doctor_id: undefined, diagnosis: '', disposition_notes: '' });

function openClose(c) {
  target.value = c;
  Object.assign(closeForm, { disposition: 'discharged', admit_bed_id: undefined, doctor_id: c.doctor_id || undefined, diagnosis: c.diagnosis || '', disposition_notes: '' });
  closeOpen.value = true;
}

async function saveClose() {
  if (closeForm.disposition === 'admitted' && !closeForm.admit_bed_id) return message.warning(s('Pick a free ward bed'));
  saving.value = true;
  try {
    await http.post(`hospital/er/${target.value.id}/close`, { ...closeForm });
    message.success(s('Case closed.'));
    closeOpen.value = false;
    load();
    if (tab.value === 'history') loadHistory(1);
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

let tick = null;
let refresh = null;
onMounted(() => {
  load();
  loadHistory(1);
  tick = setInterval(() => { now.value = Date.now(); }, 30000);
  refresh = setInterval(() => { if (!intakeOpen.value && !closeOpen.value) load(); }, 60000);
});
onBeforeUnmount(() => {
  clearInterval(tick);
  clearInterval(refresh);
});
</script>

<style scoped>
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.kpi {
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.08);
}
.kpi-v {
  font-size: 22px;
  font-weight: 700;
}
.kpi-l {
  font-size: 12px;
  opacity: 0.65;
}
.beds {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin-bottom: 12px;
}
.beds-title {
  font-weight: 600;
  margin-right: 4px;
}
.board {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}
@media (max-width: 1000px) {
  .board {
    grid-template-columns: 1fr;
  }
}
.col {
  background: rgba(128, 128, 128, 0.06);
  border-radius: 12px;
  padding: 10px;
  min-height: 160px;
}
.col-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  margin-bottom: 8px;
}
.empty {
  text-align: center;
  opacity: 0.5;
  padding: 24px 0;
  font-size: 13px;
}
.case {
  background: var(--ant-color-bg-container, #fff);
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-left: 5px solid #9ca3af;
  border-radius: 10px;
  padding: 10px;
  margin-bottom: 8px;
}
.case-top {
  display: flex;
  gap: 8px;
  align-items: flex-start;
}
.triage {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  color: #fff;
  font-weight: 700;
  flex: none;
}
.triage.sm {
  width: 24px;
  height: 24px;
  font-size: 12px;
}
.case-who {
  flex: 1;
  min-width: 0;
}
.case-name {
  font-weight: 600;
}
.case-sub,
.cell-sub {
  font-size: 11.5px;
  opacity: 0.6;
}
.case-wait {
  font-size: 12px;
  white-space: nowrap;
  opacity: 0.8;
}
.case-wait.late {
  color: #dc2626;
  font-weight: 700;
  opacity: 1;
}
.case-complaint {
  margin: 6px 0 4px;
  font-size: 13px;
}
.case-allergy {
  font-size: 12px;
  color: #dc2626;
}
.case-meta,
.case-vitals {
  font-size: 12px;
  opacity: 0.75;
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
}
.case-vitals {
  margin-top: 4px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.warn {
  color: #dc2626;
}
.case-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}
.sec {
  font-weight: 700;
  margin: 6px 0 10px;
  padding-bottom: 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.triage-pick {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 6px;
  margin-bottom: 12px;
}
@media (max-width: 560px) {
  .triage-pick {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
.tp {
  border: 2px solid var(--c);
  background: transparent;
  color: inherit;
  border-radius: 10px;
  padding: 6px 4px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  font: inherit;
  font-size: 12px;
  line-height: 1.25;
}
.tp b {
  font-size: 18px;
  color: var(--c);
}
.tp small {
  opacity: 0.6;
}
.tp.on {
  background: var(--c);
  color: #fff;
}
.tp.on b {
  color: #fff;
}
.target {
  margin: 0 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.1);
  font-size: 13px;
}
.muted {
  opacity: 0.6;
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
  width: 200px;
}
.tb-range {
  width: 240px;
}
</style>
