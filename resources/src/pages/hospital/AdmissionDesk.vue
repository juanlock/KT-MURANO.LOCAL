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
      <!-- New arrival (2026-10-25): the three steps on ONE line; each opens a short window. -->
      <a-tab-pane v-if="canDesk" key="intake" :tab="s('New arrival')">
        <div class="flow">
          <!-- 1. Patient -->
          <div class="step" :class="{ done: patientReady }">
            <div class="st-h"><span class="num">{{ patientReady ? '✓' : '1' }}</span>{{ s('Patient') }}</div>
            <template v-if="form.patientMode === 'new'">
              <div class="sum"><b>{{ form.new_patient.name }}</b> <a-tag color="blue">{{ s('New patient') }}</a-tag></div>
              <a-space :size="12"><a @click="openNewPatient">{{ s('Edit') }}</a><a @click="clearNewPatient">{{ s('Change') }}</a></a-space>
            </template>
            <template v-else>
              <a-select v-model:value="form.patient_id" show-search :filter-option="false" :placeholder="s('Type a name, MRN or phone')"
                :options="patientOptions" style="width: 100%" @search="searchPatients" @focus="searchPatients('')" />
              <a-button type="link" size="small" class="np" @click="openNewPatient"><PlusOutlined /> {{ s('New patient') }}</a-button>
            </template>
          </div>
          <div class="arrow">›</div>

          <!-- 2. Triage: picking a level asks the consultation data -->
          <div class="step" :class="{ done: triageReady }">
            <div class="st-h"><span class="num">{{ triageReady ? '✓' : '2' }}</span>{{ s('Triage') }}</div>
            <div class="triage-pick compact">
              <a-tooltip v-for="t in TRIAGE" :key="t.value" :title="s(t.label)">
                <button type="button" class="tp" :class="{ on: form.triage_level === t.value }" :style="{ '--c': t.color }" @click="pickTriage(t.value)">
                  <b>{{ t.value }}</b>
                </button>
              </a-tooltip>
            </div>
            <div v-if="triageReady" class="sum small">
              {{ s(TRIAGE.find(t => t.value === form.triage_level).label) }}<template v-if="form.complaint"> · {{ form.complaint }}</template>
              <a @click="consultOpen = true"> · {{ s('Edit') }}</a>
            </div>
          </div>
          <div class="arrow">›</div>

          <!-- 3. Destination: a bed opens the bed picker -->
          <div class="step" :class="{ done: destReady }">
            <div class="st-h"><span class="num">{{ destReady ? '✓' : '3' }}</span>{{ s('Destination') }}</div>
            <div class="dest-pick">
              <button v-for="d in DESTS" :key="d.value" type="button" class="dp" :class="{ on: form.destination === d.value }" @click="pickDestination(d.value)">
                <span class="dp-i">{{ d.icon }}</span><span>{{ s(d.label) }}</span>
              </button>
            </div>
            <div v-if="form.destination !== 'waiting_room' && form.bed_id" class="sum small">
              {{ s('Bed') }}: <b>{{ bedName(form.bed_id) }}</b> <a @click="openPicker(form.destination === 'er_bed' ? 'er' : 'ward', b => (form.bed_id = b.id))">· {{ s('Change') }}</a>
            </div>
          </div>

          <div class="go">
            <a-button type="primary" size="large" :disabled="!ready" :loading="saving" @click="submitIntake">{{ s('Register arrival') }}</a-button>
          </div>
        </div>
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
            <template v-else-if="column.key === 'pass'">
              <a-button v-if="!record.checked_out_at" size="small" @click="printPass(record.id)"><PrinterOutlined /> {{ s('Pass') }}</a-button>
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
            <template v-else-if="column.key === 'band'"><a-button size="small" @click="printBand(record.patient_id)"><PrinterOutlined /> {{ s('Wristband') }}</a-button></template>
          </template>
        </a-table>
      </a-tab-pane>
    </a-tabs>

    <!-- New patient: the basic data only -->
    <a-modal :open="newOpen" :title="s('New patient')" :ok-text="s('Continue')" :cancel-text="s('Cancel')" @ok="saveNewPatient" @cancel="newOpen = false">
      <a-form layout="vertical">
        <a-form-item :label="s('Name') + ' *'"><a-input ref="newName" v-model:value="np.name" @press-enter="saveNewPatient" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="s('Gender')"><a-select v-model:value="np.gender" :options="genderOptions" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="s('Date of birth')"><a-date-picker v-model:value="np.date_of_birth" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="s('ID document')"><a-input v-model:value="np.national_id" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="s('Phone')"><a-input v-model:value="np.phone" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Triage picked: the consultation data -->
    <a-modal :open="consultOpen" :title="triageTitle" :ok-text="s('Continue')" :cancel-text="s('Cancel')" width="620px" @ok="consultOpen = false; consultDone = true" @cancel="consultOpen = false">
      <a-form layout="vertical">
        <a-form-item :label="s('Chief complaint')"><a-textarea v-model:value="form.complaint" :rows="2" :placeholder="s('What brings the patient')" /></a-form-item>
        <a-row :gutter="10">
          <a-col :span="8"><a-form-item :label="s('Temp. °C')"><a-input-number v-model:value="form.temperature" :min="25" :max="45" :step="0.1" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="s('Pulse')"><a-input-number v-model:value="form.pulse" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item label="SpO₂ %"><a-input-number v-model:value="form.spo2" :min="0" :max="100" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="s('BP sys.')"><a-input-number v-model:value="form.bp_systolic" :min="0" :max="300" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="s('BP dia.')"><a-input-number v-model:value="form.bp_diastolic" :min="0" :max="250" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="s('Arrival')"><a-select v-model:value="form.arrival_mode" :options="arrivalOptions" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="s('Doctor')">
          <a-select v-model:value="form.doctor_id" allow-clear show-search option-filter-prop="label" :options="doctorOptions" :placeholder="s('Assign later')" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Bed picker: the floor maps (free beds of the right kind are bright), and the beds not on a map yet -->
    <a-modal :open="picker.open" :title="picker.kind === 'er' ? s('Pick an ER bed') : s('Pick a ward bed')" :footer="null" width="min(1100px, 96vw)" @cancel="picker.open = false">
      <a-spin :spinning="picker.loading">
        <a-alert v-if="picker.suggested" type="success" show-icon style="margin-bottom: 10px"
          :message="s('Suggested: {ward} · bed {bed}', { ward: picker.suggested.ward, bed: picker.suggested.bed })"
          :description="s('By the severity (triage) and the free beds of each ward.')">
          <template #action><a-button size="small" type="primary" @click="pickBed({ id: picker.suggested.bed_id })">{{ s('Use it') }}</a-button></template>
        </a-alert>
        <template v-if="pickFloors.length">
          <a-segmented v-model:value="picker.floorId" :options="pickFloors.map(f => ({ value: f.id, label: `${f.name} (${freeOn(f)})` }))" style="margin-bottom: 10px" />
          <FloorMap v-if="pickFloor" :floor="pickFloor" :pickable="canPickBed" :selected-bed-id="picker.suggested?.bed_id ?? null" :status-label="st => s(BED_LABEL[st] || st)" @pick-bed="pickBed" />
          <div class="legend">
            <span v-for="st in ['available', 'occupied', 'cleaning', 'reserved', 'maintenance']" :key="st"><i :class="'s-' + st" /> {{ s(BED_LABEL[st]) }}</span>
          </div>
        </template>
        <div v-if="offMapBeds.length" class="off-map">
          <div class="h">{{ pickFloors.length ? s('Free beds not on a map') : s('Free beds') }}</div>
          <div class="bed-icons">
            <button v-for="b in offMapBeds" :key="b.id" type="button" class="bi" @click="pickBed(b)"><span class="bi-i">🛏</span><span>{{ b.label }}</span></button>
          </div>
        </div>
        <a-empty v-if="!picker.loading && !offMapBeds.length && !pickFloors.some(f => freeOn(f))" :description="s('No free bed of this kind right now.')" />
      </a-spin>
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
import { message, Modal } from 'ant-design-vue';
import { ReloadOutlined, PlusOutlined, PrinterOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import FloorMap from './FloorMap.vue';
import { printVisitorPass, printWristband } from './hospitalPrint';
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
  { title: s('In'), key: 'in', width: 120 }, { title: s('Out'), key: 'out', width: 120 }, { title: '', key: 'pass', width: 90 },
]);
const censusColumns = computed(() => [
  { title: s('Patient'), dataIndex: 'name' }, { title: 'HC', dataIndex: 'mrn', width: 120 },
  { title: s('Where'), key: 'area' }, { title: s('Since'), key: 'since', width: 140 }, { title: '', key: 'band', width: 110 },
]);

function emptyForm() {
  return {
    patientMode: 'existing', patient_id: undefined, new_patient: { name: '', gender: 'male', date_of_birth: null, national_id: '', phone: '' },
    triage_level: null, complaint: '', temperature: null, pulse: null, spo2: null, bp_systolic: null, bp_diastolic: null, arrival_mode: 'walk_in',
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
    const res = await http.post('hospital/desk/intake', body);
    message.success(s('Arrival registered'));
    form.value = emptyForm();
    consultDone.value = false;
    load();
    // The identification wristband is put on at admission: offer it right away.
    if (res?.patient_id) {
      Modal.confirm({
        title: s('Print the wristband?'), content: s('The identification wristband with the record number (barcode) and the allergies.'),
        okText: s('Print'), cancelText: s('Later'), onOk: () => printBand(res.patient_id),
      });
    }
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

// ---- the three steps (2026-10-25)
const DESTS = [
  { value: 'waiting_room', label: 'Waiting room', icon: '🪑' },
  { value: 'er_bed', label: 'ER bed', icon: '🚑' },
  { value: 'ward', label: 'Admit (ward bed)', icon: '🛏' },
];
const BED_LABEL = { available: 'Available', occupied: 'Occupied', cleaning: 'Cleaning', reserved: 'Reserved', maintenance: 'Maintenance' };
const consultOpen = ref(false);
const consultDone = ref(false);
const patientReady = computed(() => (form.value.patientMode === 'new' ? !!form.value.new_patient.name.trim() : !!form.value.patient_id));
const triageReady = computed(() => !!form.value.triage_level && consultDone.value);
const destReady = computed(() => form.value.destination === 'waiting_room' || !!form.value.bed_id);
const ready = computed(() => patientReady.value && triageReady.value && destReady.value);
const triageTitle = computed(() => {
  const t = TRIAGE.find(x => x.value === form.value.triage_level);
  return t ? `${s('Triage')} ${t.value} · ${s(t.label)} — ${s('Consultation data')}` : '';
});
function pickTriage(v) { form.value.triage_level = v; consultOpen.value = true; }
function pickDestination(v) {
  if (form.value.destination !== v) form.value.bed_id = undefined;
  form.value.destination = v;
  if (v !== 'waiting_room') openPicker(v === 'er_bed' ? 'er' : 'ward', b => { form.value.bed_id = b.id; });
}

// New patient: a short window with the basic data.
const newOpen = ref(false);
const np = reactive({ name: '', gender: 'male', date_of_birth: null, national_id: '', phone: '' });
function openNewPatient() { Object.assign(np, form.value.new_patient); newOpen.value = true; }
function saveNewPatient() {
  if (!np.name.trim()) return message.warning(s("Type the patient's name."));
  form.value.new_patient = { ...np, name: np.name.trim() };
  form.value.patientMode = 'new';
  form.value.patient_id = undefined;
  newOpen.value = false;
}
function clearNewPatient() {
  form.value.patientMode = 'existing';
  form.value.new_patient = emptyForm().new_patient;
}

// ---- bed picker: the floor maps + the free beds not on a map (hospital/bed-map)
const picker = reactive({ open: false, kind: 'er', loading: false, floors: [], floorId: null, onPick: null, suggested: null });
const isErBed = b => b.ward_type === 'emergency';
const canPickBed = b => b.status === 'available' && (picker.kind === 'er' ? isErBed(b) : !isErBed(b));
const freeOn = f => f.beds.filter(canPickBed).length;
const pickFloors = computed(() => picker.floors.filter(f => f.beds.length));
const pickFloor = computed(() => pickFloors.value.find(f => f.id === picker.floorId) || null);
const offMapBeds = computed(() => {
  const onMap = new Set(picker.floors.flatMap(f => f.beds.map(b => b.id)));
  return (picker.kind === 'er' ? desk.value.er_beds : desk.value.ward_beds).filter(b => !onMap.has(b.id));
});
const bedLabels = computed(() => {
  const m = {};
  [...desk.value.er_beds, ...desk.value.ward_beds].forEach(b => { m[b.id] = b.label; });
  picker.floors.forEach(f => f.beds.forEach(b => { m[b.id] = `${b.ward_name ? b.ward_name + ' · ' : ''}${b.bed_number}`; }));
  return m;
});
const bedName = id => bedLabels.value[id] || `#${id}`;
async function openPicker(kind, onPick) {
  Object.assign(picker, { open: true, kind, onPick, loading: true, suggested: null });
  try {
    const [map, tower] = await Promise.all([
      http.get('hospital/bed-map'),
      http.get('hospital/control-tower', { kind, triage: form.value.triage_level || 3 }).catch(() => null),
    ]);
    picker.floors = map?.floors || [];
    picker.suggested = tower?.suggested || null;
    // Start on the floor of the suggested bed, else the one with the most free beds of this kind.
    const sugFloor = picker.suggested && pickFloors.value.find(f => f.beds.some(b => b.id === picker.suggested.bed_id));
    const best = sugFloor || [...pickFloors.value].sort((a, b) => freeOn(b) - freeOn(a))[0];
    picker.floorId = best ? best.id : null;
  } catch (e) {
    picker.floors = [];
  } finally {
    picker.loading = false;
  }
}
function pickBed(b) {
  picker.open = false;
  if (picker.onPick) picker.onPick(b);
}

// Waiting room -> a bed: ER bed, or admitted to a ward bed.
function openCall(w) {
  Modal.confirm({
    title: `${s('Call to a bed')}: ${w.name}`,
    content: s('An ER bed, or admit the patient to a ward bed?'),
    okText: s('ER bed'), cancelText: s('Ward bed (admit)'), closable: true, maskClosable: true,
    onOk: () => openPicker('er', b => callIn(w, b)),
    onCancel: e => { if (e?.triggerCancel) return; openPicker('ward', b => callIn(w, b)); },
  });
}
async function callIn(w, bed) {
  saving.value = true;
  try {
    await http.post(`hospital/desk/waiting/${w.id}/call`, { bed_id: bed.id });
    message.success(`${w.name} → ${bedName(bed.id)}`);
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
    printPass(d.id);
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}
// ---- printouts: the visitor pass and the patient wristband
async function printPass(visitorId) {
  try {
    const d = await http.get(`hospital/visitors/${visitorId}/pass`);
    if (!printVisitorPass(d, s)) message.warning(s('Allow pop-up windows to print.'));
  } catch (e) { message.error(s('Could not load')); }
}
async function printBand(patientId) {
  try {
    const d = await http.get(`hospital/patients/${patientId}/wristband`);
    if (!printWristband(d, s)) message.warning(s('Allow pop-up windows to print.'));
  } catch (e) { message.error(s('Could not load')); }
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
/* New arrival: three steps on one line (wraps on narrow screens) */
.flow { display: flex; align-items: stretch; gap: 8px; flex-wrap: wrap; }
.step { flex: 1 1 240px; min-width: 220px; border: 1.5px solid rgba(128, 128, 128, 0.25); border-radius: 12px; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px; }
.step.done { border-color: #16a34a; background: rgba(22, 163, 74, 0.04); }
.st-h { display: flex; align-items: center; gap: 8px; font-weight: 700; }
.num { width: 24px; height: 24px; border-radius: 50%; background: #1677ff; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; }
.step.done .num { background: #16a34a; }
.arrow { align-self: center; font-size: 26px; opacity: 0.35; }
.go { align-self: center; flex: 0 0 auto; }
.np { padding: 0; height: auto; align-self: flex-start; }
.sum { font-size: 13px; }
.sum.small { font-size: 12.5px; opacity: 0.85; }
.triage-pick.compact { grid-template-columns: repeat(5, minmax(0, 1fr)); margin: 0; max-width: none; }
.triage-pick.compact .tp { padding: 6px 0; }
.dest-pick { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.dp { border: 1.5px solid rgba(128, 128, 128, 0.3); background: transparent; color: inherit; border-radius: 10px; padding: 6px 4px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 2px; font: inherit; font-size: 12px; line-height: 1.2; }
.dp-i { font-size: 20px; }
.dp.on { border-color: #1677ff; background: rgba(22, 119, 255, 0.08); }
@media (max-width: 900px) { .arrow { display: none; } .go { flex-basis: 100%; } .go .ant-btn { width: 100%; } }
/* bed picker */
.legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 8px; font-size: 12.5px; }
.legend i { display: inline-block; width: 11px; height: 11px; border-radius: 3px; vertical-align: -1px; margin-inline-end: 4px; }
.s-available { background: #16a34a; } .s-occupied { background: #ef4444; } .s-cleaning { background: #7c3aed; } .s-reserved { background: #d97706; } .s-maintenance { background: #94a3b8; }
.off-map { margin-top: 14px; }
.off-map .h { font-weight: 600; margin-bottom: 8px; }
.bed-icons { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; }
.bi { border: 1.5px solid #16a34a; background: rgba(22, 163, 74, 0.06); color: inherit; border-radius: 10px; padding: 8px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 2px; font: inherit; font-size: 12px; }
.bi:hover { background: rgba(22, 163, 74, 0.15); }
.bi-i { font-size: 22px; }
</style>
