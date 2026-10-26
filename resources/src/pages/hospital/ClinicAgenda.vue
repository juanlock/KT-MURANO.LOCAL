<template>
  <div class="page">
    <PageHeader :title="s('Reception and schedule')" :breadcrumb="[s('Clinic'), s('Reception and schedule')]">
      <template #actions>
        <!-- Ready patients waiting too long for the doctor: one icon with the count, the list on click. -->
        <a-popover v-if="data.late.length" trigger="click" placement="bottomRight" :title="s('Waiting too long for the doctor')">
          <template #content>
            <div class="late-list">
              <div v-for="l in data.late" :key="l.appointment_id" class="late-row" @click="openLate(l)">
                <div><b>{{ l.patient }}</b><div class="late-doc">{{ l.doctor }}</div></div>
                <a-tag color="orange">{{ l.minutes }} min</a-tag>
              </div>
            </div>
          </template>
          <a-tooltip :title="s('Waiting too long for the doctor')">
            <a-badge :count="data.late.length" :offset="[-4, 4]">
              <a-button class="late-btn"><WarningOutlined /></a-button>
            </a-badge>
          </a-tooltip>
        </a-popover>
        <a-button @click="moveDay(-1)"><LeftOutlined /></a-button>
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
        <a-button @click="moveDay(1)"><RightOutlined /></a-button>
        <a-button @click="goToday">{{ s('Today') }}</a-button>
        <a-button type="primary" @click="openNew(null, null)"><PlusOutlined /> {{ s('New appointment') }}</a-button>
      </template>
    </PageHeader>

    <div class="kpis">
      <div v-for="k in KPI" :key="k.key" class="kpi"><div class="kpi-v" :style="{ color: k.color }">{{ data.kpis[k.key] ?? 0 }}</div><div class="kpi-l">{{ s(k.label) }}</div></div>
    </div>

    <div class="layout">
      <!-- Schedule grid: one column per doctor -->
      <div class="grid-wrap">
        <div class="grid" :style="{ gridTemplateColumns: `64px repeat(${doctors.length}, minmax(170px, 1fr))` }">
          <div class="corner"></div>
          <div v-for="d in doctors" :key="d.id" class="doc-head" :class="{ 'doc-off': !d.is_active }">
            <b>{{ d.name }}</b><div class="muted">{{ d.specialty }}</div>
            <a-tag v-if="!d.is_active" color="red" class="off-tag">{{ s('Removed') }}</a-tag>
          </div>
          <template v-for="t in slots" :key="t">
            <div class="time">{{ t }}</div>
            <div v-for="d in doctors" :key="d.id + t" class="cell" :class="{ over: dragOver === d.id + t, past: isPast(t) }"
              @dragover.prevent="dragOver = d.id + t" @dragleave="dragOver = null" @drop="d.is_active && drop(d, t)" @dblclick="d.is_active && openNew(d, t)">
              <div v-for="a in at(d.id, t)" :key="a.id" class="appt" :class="['st-' + state(a)]" :draggable="!a.flow && !['completed','cancelled','no_show'].includes(a.status)"
                @dragstart="dragging = a" @dragend="dragging = null; dragOver = null" @click.stop="openAppt(a)">
                <div class="a-top"><b>{{ a.patient }}</b><span class="a-time">{{ hm(a.scheduled_at) }}</span></div>
                <div class="a-sub">
                  <a-tag :color="STATE[state(a)].color" class="a-tag">{{ s(STATE[state(a)].label) }}</a-tag>
                  <span v-if="a.first_visit" class="pill">{{ s('1st') }}</span>
                  <span v-if="a.pre_registered" class="pill ok" :title="s('Pre-registered from home')">✓</span>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Requests to schedule -->
      <div class="side">
        <a-card size="small" :title="`${s('To schedule')} (${data.requests.length})`">
          <a-empty v-if="!data.requests.length" :description="s('Nothing to schedule')" />
          <div v-for="r in data.requests" :key="r.id" class="req" :class="{ overdue: r.overdue }">
            <div><b>{{ r.patient }}</b> <span class="muted">{{ r.phone }}</span></div>
            <div v-if="r.kind === 'reassign'" class="muted">
              <a-tag color="red" style="margin: 0 4px 0 0">{{ s(KIND.reassign) }}</a-tag>{{ r.from_specialty || s('Any doctor') }} · {{ r.wanted_on }}
            </div>
            <div v-else class="muted">{{ s(KIND[r.kind] || r.kind) }} · {{ r.doctor || s('Any doctor') }} · {{ r.wanted_on }}</div>
            <div v-if="r.reason" class="muted">{{ r.reason }}</div>
            <div v-if="r.studies" class="muted">{{ s('Bring') }}: {{ r.studies }}</div>
            <a-space style="margin-top: 4px">
              <a-button size="small" type="primary" @click="scheduleRequest(r)">{{ s('Schedule') }}</a-button>
              <a-popconfirm :title="s('Discard this request?')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="cancelRequest(r)">
                <a-button size="small">{{ s('Discard') }}</a-button>
              </a-popconfirm>
            </a-space>
          </div>
        </a-card>
      </div>
    </div>

    <!-- New appointment -->
    <a-modal v-model:open="newOpen" :title="s('New appointment')" :ok-text="s('Save')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="saveNew">
      <a-form layout="vertical">
        <a-radio-group v-model:value="nf.mode" button-style="solid" style="margin-bottom: 10px">
          <a-radio-button value="existing">{{ s('Registered patient') }}</a-radio-button>
          <a-radio-button value="new">{{ s('New patient') }}</a-radio-button>
        </a-radio-group>
        <a-form-item v-if="nf.mode === 'existing'" :label="s('Patient')">
          <a-select v-model:value="nf.patient_id" show-search :filter-option="false" :options="patientOptions" :placeholder="s('Type a name, record number or phone')"
            @search="searchPatients" @focus="searchPatients('')" />
        </a-form-item>
        <template v-else>
          <a-form-item :label="s('Name')"><a-input v-model:value="nf.new_patient.name" /></a-form-item>
          <a-row :gutter="8">
            <a-col :span="12"><a-form-item :label="s('Phone')"><a-input v-model:value="nf.new_patient.phone" /></a-form-item></a-col>
            <a-col :span="12"><a-form-item :label="s('Email')"><a-input v-model:value="nf.new_patient.email" /></a-form-item></a-col>
          </a-row>
        </template>
        <a-row :gutter="8">
          <a-col :span="12"><a-form-item :label="s('Doctor')"><a-select v-model:value="nf.doctor_id" :options="doctorOptions" show-search option-filter-prop="label" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="s('Date and time')"><a-date-picker v-model:value="nf.scheduled_at" show-time format="DD-MM-YYYY HH:mm" value-format="YYYY-MM-DD HH:mm:00" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="s('Channel')"><a-select v-model:value="nf.channel" :options="channelOptions" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="s('Minutes')"><a-input-number v-model:value="nf.duration_minutes" :min="5" :max="240" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="s('Reason')"><a-input v-model:value="nf.reason" /></a-form-item>
      </a-form>
    </a-modal>

    <!-- Appointment actions -->
    <a-drawer v-model:open="apptOpen" :width="420" :title="sel ? `${sel.patient} · ${sel.reference}` : ''">
      <template v-if="sel">
        <a-descriptions :column="1" size="small" bordered>
          <a-descriptions-item :label="s('Status')"><a-tag :color="STATE[state(sel)].color">{{ s(STATE[state(sel)].label) }}</a-tag></a-descriptions-item>
          <a-descriptions-item :label="s('When')">{{ dt(sel.scheduled_at) }} · {{ sel.duration }} min</a-descriptions-item>
          <a-descriptions-item :label="s('Doctor')">{{ doctorName(sel.doctor_id) }}</a-descriptions-item>
          <a-descriptions-item :label="s('Visit')">{{ sel.first_visit ? s('First visit') : s('Follow-up') }} · {{ s(CHANNEL[sel.channel] || sel.channel || '—') }}</a-descriptions-item>
          <a-descriptions-item :label="s('Reason')">{{ sel.reason || '—' }}</a-descriptions-item>
          <a-descriptions-item :label="s('Phone')">{{ sel.phone || '—' }}</a-descriptions-item>
          <a-descriptions-item :label="s('Fee')">{{ money(sel.fee) }} · {{ s(PAYMENT[sel.payment] || 'Not paid') }}</a-descriptions-item>
          <a-descriptions-item :label="s('Pre-registration')">{{ sel.pre_registered ? s('Done from home') : s('Not done') }}</a-descriptions-item>
        </a-descriptions>
        <div class="actions">
          <template v-if="!sel.flow && ['scheduled', 'confirmed'].includes(sel.status)">
            <a-button type="primary" @click="checkOpen = true">{{ s('Check in') }}</a-button>
            <a-button v-if="sel.status === 'scheduled'" @click="setStatus('confirmed')">{{ s('Confirm') }}</a-button>
            <a-button danger @click="setStatus('no_show')">{{ s('No-show') }}</a-button>
            <a-popconfirm :title="s('Cancel this appointment?')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="setStatus('cancelled')">
              <a-button>{{ s('Cancel appointment') }}</a-button>
            </a-popconfirm>
          </template>
          <a-button v-if="sel.status === 'no_show'" @click="setStatus('scheduled')">{{ s('Back to scheduled') }}</a-button>
          <PermLink :to="`/hospital/patients/${sel.patient_id}`" permission="hms_patients_view">{{ s('Patient record') }}</PermLink>
          <PermLink v-if="sel.invoice_id" to="/hospital/invoices" permission="hms_billing">{{ s('Bill') }}</PermLink>
        </div>
      </template>
    </a-drawer>

    <!-- Check-in -->
    <a-modal v-model:open="checkOpen" :title="s('Check in')" :ok-text="s('Check in')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="checkIn">
      <a-form layout="vertical">
        <a-form-item :label="s('Payment')">
          <a-radio-group v-model:value="ci.payment" button-style="solid">
            <a-radio-button value="pay">{{ s('Pays now') }}</a-radio-button>
            <a-radio-button value="insurance">{{ s('Insurance') }}</a-radio-button>
            <a-radio-button value="later">{{ s('Pays at the end') }}</a-radio-button>
          </a-radio-group>
        </a-form-item>
        <a-row :gutter="8">
          <a-col :span="12"><a-form-item :label="s('Fee')"><a-input-number v-model:value="ci.fee" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col v-if="ci.payment === 'pay'" :span="12"><a-form-item :label="s('Method')"><a-select v-model:value="ci.method" :options="methodOptions" /></a-form-item></a-col>
        </a-row>
        <template v-if="ci.payment === 'insurance'">
          <a-form-item :label="s('Insurer')"><a-input v-model:value="ci.insurance_provider" /></a-form-item>
          <a-form-item :label="s('Policy / card number')"><a-input v-model:value="ci.insurance_number" /></a-form-item>
        </template>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Reception and schedule (specialist clinic, 2026-10-26): the day's schedule
 * by doctor -- drag an appointment to another time / doctor, double-click an
 * empty slot to book --, check-in with payment / insurance, confirm, no-show,
 * the follow-ups and referrals the doctors left to schedule, and the alert
 * when a ready patient waits too long for the doctor.
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { LeftOutlined, RightOutlined, PlusOutlined, WarningOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { sortByLabel } from '../../lib/filterOptions';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/ClinicFlow.es.js';

const { s } = useHospitalText(ES);
const { money } = useFormat();

const KPI = [
  { key: 'booked', label: 'Booked today', color: '#2563eb' },
  { key: 'confirmed', label: 'Confirmed', color: '#0891b2' },
  { key: 'waiting', label: 'In the waiting room', color: '#ea580c' },
  { key: 'in_consult', label: 'In consultation', color: '#7c3aed' },
  { key: 'finished', label: 'Attended', color: '#16a34a' },
  { key: 'no_show', label: 'No-show', color: '#dc2626' },
];
const STATE = {
  booked: { label: 'Booked', color: 'default' }, confirmed: { label: 'Confirmed', color: 'blue' },
  checked_in: { label: 'Checked in', color: 'orange' }, ready: { label: 'Ready to go in', color: 'green' },
  in_consult: { label: 'In consultation', color: 'purple' }, finished: { label: 'Attended', color: 'cyan' },
  no_show: { label: 'No-show', color: 'red' }, cancelled: { label: 'Cancelled', color: 'default' },
};
// reassign: the doctor was removed (EmployeeRemoval) -- give the patient another one of that specialty.
const KIND = { follow_up: 'Follow-up', referral: 'Referral', portal: 'Requested from the portal', reassign: 'Reassign doctor' };
const CHANNEL = { phone: 'Phone', web: 'Web / portal', whatsapp: 'WhatsApp', walk_in: 'Walk-in', email: 'Email' };
const PAYMENT = { paid: 'Paid', insurance: 'Insurance', pending: 'Pays at the end' };

const date = ref(dayjs().format('YYYY-MM-DD'));
const data = ref({ doctors: [], appointments: [], requests: [], late: [], kpis: {} });
const saving = ref(false);
const dragging = ref(null);
const dragOver = ref(null);

const doctors = computed(() => data.value.doctors);
// Only doctors who still take appointments (a removed one may keep a column for today's history).
const doctorOptions = computed(() => sortByLabel(doctors.value.filter(d => d.is_active).map(d => ({ value: d.id, label: d.specialty ? `${d.name} (${d.specialty})` : d.name }))));
const channelOptions = computed(() => Object.keys(CHANNEL).map(k => ({ value: k, label: s(CHANNEL[k]) })));
const methodOptions = computed(() => sortByLabel([{ value: 'cash', label: s('Cash') }, { value: 'card', label: s('Card') }, { value: 'transfer', label: s('Transfer') }]));
const doctorName = id => (doctors.value.find(d => d.id === id) || {}).name;
const hm = iso => dayjs(iso).format('HH:mm');
const dt = iso => dayjs(iso).format('DD-MM-YYYY HH:mm');

// 07:00 - 20:00 in 20-minute slots.
const slots = computed(() => {
  const out = [];
  for (let m = 7 * 60; m < 20 * 60; m += 20) out.push(`${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`);
  return out;
});
const slotOf = iso => {
  const d = dayjs(iso);
  const m = Math.floor((d.hour() * 60 + d.minute()) / 20) * 20;
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
};
const at = (doctorId, t) => data.value.appointments.filter(a => a.doctor_id === doctorId && slotOf(a.scheduled_at) === t && a.status !== 'cancelled');
const isPast = t => dayjs(`${date.value} ${t}`).isBefore(dayjs());
function state(a) {
  if (a.status === 'no_show') return 'no_show';
  if (a.status === 'cancelled') return 'cancelled';
  if (a.flow) return a.flow;
  return a.status === 'confirmed' || a.confirmed ? 'confirmed' : 'booked';
}

async function load() {
  try {
    data.value = await http.get('hospital/clinic/agenda', { date: date.value });
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  }
}
function moveDay(n) { date.value = dayjs(date.value).add(n, 'day').format('YYYY-MM-DD'); load(); }
function goToday() { date.value = dayjs().format('YYYY-MM-DD'); load(); }

async function drop(d, t) {
  const a = dragging.value;
  dragOver.value = null;
  if (!a) return;
  try {
    await http.put(`hospital/clinic/appointments/${a.id}/move`, { doctor_id: d.id, scheduled_at: `${date.value} ${t}:00` });
    message.success(s('Appointment moved'));
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}

// New appointment
const newOpen = ref(false);
const patientOptions = ref([]);
const nf = reactive({ mode: 'existing', patient_id: undefined, new_patient: { name: '', phone: '', email: '' }, doctor_id: undefined, scheduled_at: null,
  channel: 'phone', duration_minutes: 20, reason: '', request_id: null });
function openNew(d, t, req = null) {
  Object.assign(nf, { mode: req ? 'existing' : 'existing', patient_id: req ? req.patient_id : undefined, new_patient: { name: '', phone: '', email: '' },
    doctor_id: d ? d.id : (req?.doctor_id || undefined), scheduled_at: t ? `${date.value} ${t}:00` : (req?.wanted_on ? `${req.wanted_on} 09:00:00` : null),
    channel: 'phone', duration_minutes: 20, reason: req ? (req.reason || '') : '', request_id: req ? req.id : null });
  patientOptions.value = req ? [{ value: req.patient_id, label: req.patient }] : [];
  newOpen.value = true;
}
let st = null;
function searchPatients(q) {
  clearTimeout(st);
  st = setTimeout(async () => {
    try {
      const d = await http.get('hospital/search/patients', { search: q || '' });
      patientOptions.value = (d?.patients || []).map(p => ({ value: p.id, label: `${p.name} · ${p.mrn}${p.phone ? ' · ' + p.phone : ''}` }));
    } catch (e) { /* empty */ }
  }, 250);
}
async function saveNew() {
  if (nf.mode === 'existing' && !nf.patient_id) return message.warning(s('Pick the patient.'));
  if (nf.mode === 'new' && !nf.new_patient.name.trim()) return message.warning(s("Type the patient's name."));
  if (!nf.doctor_id || !nf.scheduled_at) return message.warning(s('Pick the doctor, the date and the time.'));
  saving.value = true;
  try {
    const body = { doctor_id: nf.doctor_id, scheduled_at: nf.scheduled_at, channel: nf.channel, duration_minutes: nf.duration_minutes, reason: nf.reason, request_id: nf.request_id };
    if (nf.mode === 'existing') body.patient_id = nf.patient_id; else body.new_patient = nf.new_patient;
    await http.post('hospital/clinic/appointments', body);
    newOpen.value = false;
    message.success(s('Appointment booked'));
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}
function scheduleRequest(r) {
  if (r.wanted_on) date.value = r.wanted_on;
  load();
  openNew(null, null, r);
}
async function cancelRequest(r) {
  await http.post(`hospital/clinic/requests/${r.id}/cancel`, {});
  load();
}

// Appointment actions
const apptOpen = ref(false);
const sel = ref(null);
function openAppt(a) { sel.value = a; apptOpen.value = true; }
function openLate(l) {
  const a = data.value.appointments.find(x => x.id === l.appointment_id);
  if (a) openAppt(a);
}
async function setStatus(st2) {
  try {
    await http.post(`hospital/clinic/appointments/${sel.value.id}/status`, { status: st2 });
    apptOpen.value = false;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}
const checkOpen = ref(false);
const ci = reactive({ payment: 'pay', method: 'cash', fee: 0, insurance_provider: '', insurance_number: '' });
async function checkIn() {
  saving.value = true;
  try {
    await http.post(`hospital/clinic/appointments/${sel.value.id}/check-in`, { ...ci });
    checkOpen.value = false;
    apptOpen.value = false;
    message.success(s('Checked in: the patient goes to the nursing station.'));
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}
// The fee / insurance of the patient when the check-in opens.
watch(checkOpen, v => {
  if (v && sel.value) Object.assign(ci, { payment: sel.value.insurance_provider ? 'insurance' : 'pay', method: 'cash', fee: sel.value.fee,
    insurance_provider: sel.value.insurance_provider || '', insurance_number: '' });
});

let timer = null;
onMounted(() => { load(); timer = setInterval(load, 45000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.late-btn { color: #d97706; border-color: #fcd34d; }
.late-list { width: 280px; max-height: 320px; overflow-y: auto; }
.late-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 6px; border-radius: 6px; cursor: pointer; }
.late-row + .late-row { border-top: 1px solid rgba(128, 128, 128, 0.15); }
.late-row:hover { background: rgba(217, 119, 6, 0.08); }
.late-doc { font-size: 12px; opacity: 0.65; }
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 12px; }
.kpi { padding: 10px 12px; border-radius: 12px; background: rgba(128, 128, 128, 0.08); }
.kpi-v { font-size: 22px; font-weight: 700; }
.kpi-l { font-size: 12px; opacity: 0.7; }
.layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 12px; }
@media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }
.grid-wrap { overflow: auto; max-height: calc(100vh - 260px); border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 10px; }
.grid { display: grid; }
.corner, .doc-head { position: sticky; top: 0; z-index: 2; background: var(--ant-color-bg-container, #fff); border-bottom: 1px solid rgba(128, 128, 128, 0.25); padding: 6px 8px; }
.doc-off b { text-decoration: line-through; opacity: 0.6; }
.off-tag { margin-top: 2px; font-size: 11px; }
.time { font-size: 12px; opacity: 0.6; padding: 4px 6px; border-top: 1px dashed rgba(128, 128, 128, 0.2); }
.cell { min-height: 46px; border-top: 1px dashed rgba(128, 128, 128, 0.2); border-left: 1px solid rgba(128, 128, 128, 0.12); padding: 2px; }
.cell.past { background: rgba(128, 128, 128, 0.04); }
.cell.over { background: rgba(37, 99, 235, 0.12); }
.appt { border-radius: 8px; padding: 4px 6px; margin-bottom: 2px; font-size: 12px; cursor: pointer; border-left: 4px solid #94a3b8; background: rgba(148, 163, 184, 0.15); }
.appt[draggable='true'] { cursor: grab; }
.st-confirmed { border-left-color: #2563eb; background: rgba(37, 99, 235, 0.1); }
.st-checked_in { border-left-color: #ea580c; background: rgba(234, 88, 12, 0.12); }
.st-ready { border-left-color: #16a34a; background: rgba(22, 163, 74, 0.14); }
.st-in_consult { border-left-color: #7c3aed; background: rgba(124, 58, 237, 0.12); }
.st-finished { border-left-color: #0891b2; background: rgba(8, 145, 178, 0.1); opacity: 0.8; }
.st-no_show { border-left-color: #dc2626; background: rgba(220, 38, 38, 0.08); text-decoration: line-through; }
.a-top { display: flex; justify-content: space-between; gap: 4px; }
.a-time { opacity: 0.6; }
.a-sub { display: flex; gap: 4px; align-items: center; margin-top: 2px; }
.a-tag { margin: 0; font-size: 10px; line-height: 16px; }
.pill { font-size: 10px; padding: 0 5px; border-radius: 8px; background: rgba(128, 128, 128, 0.2); }
.pill.ok { background: #16a34a; color: #fff; }
.muted { opacity: 0.6; font-size: 12px; }
.side .req { padding: 8px 0; border-bottom: 1px dashed rgba(128, 128, 128, 0.25); }
.req.overdue b { color: #dc2626; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; align-items: center; }
</style>
