<template>
  <div class="page">
    <!-- The doctor's day -->
    <template v-if="!emr">
      <PageHeader :title="s('My consultation')" :breadcrumb="[s('Clinic'), s('My consultation')]">
        <template #actions>
          <a-select v-if="day.doctors.length" v-model:value="doctorId" style="width: 260px" show-search option-filter-prop="label" :options="doctorOptions"
            :placeholder="s('Doctor')" @change="loadDay" />
          <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="loadDay" />
          <a-button @click="loadDay"><ReloadOutlined /></a-button>
        </template>
      </PageHeader>
      <a-alert v-if="!day.doctor" type="info" show-icon :message="s('Pick the doctor.')" />
      <template v-else>
        <div class="muted" style="margin-bottom: 10px">{{ day.doctor.name }} · {{ day.doctor.specialty }}</div>
        <a-empty v-if="!day.appointments.length" :description="s('No appointments this day')" />
        <div v-for="a in day.appointments" :key="a.id" class="day-row" :class="['st-' + (a.flow || a.status)]">
          <div class="d-time">{{ hm(a.scheduled_at) }}</div>
          <div class="d-who">
            <b>{{ a.patient }}</b> <span class="muted">{{ a.age != null ? `${a.age} ${s('y')}` : '' }} · {{ a.mrn }}</span>
            <div class="muted">{{ a.reason || '—' }} <span v-if="a.first_visit" class="pill">{{ s('First visit') }}</span></div>
          </div>
          <a-tag :color="FLOW[a.flow || a.status]?.color">{{ s(FLOW[a.flow || a.status]?.label || a.status) }}</a-tag>
          <span v-if="a.flow === 'ready'" class="wait">{{ s('waiting') }} {{ mins(a.ready_at) }}</span>
          <a-button v-if="a.flow === 'ready' || a.flow === 'checked_in'" type="primary" @click="openEmr(a, true)">{{ s('Call in') }}</a-button>
          <a-button v-else-if="a.flow === 'in_consult'" type="primary" @click="openEmr(a)">{{ s('Continue') }}</a-button>
          <a-button v-else-if="a.flow === 'finished'" @click="openEmr(a)">{{ s('View') }}</a-button>
        </div>
      </template>
    </template>

    <!-- The EMR -->
    <template v-else>
      <div class="emr-head">
        <a-button @click="closeEmr"><ArrowLeftOutlined /></a-button>
        <div class="who">
          <b>{{ emr.patient.name }}</b>
          <span class="muted">{{ emr.patient.mrn }} · {{ emr.patient.age }} {{ s('y') }} · {{ s(GENDER[emr.patient.gender] || '') }}<template v-if="emr.patient.blood_group"> · {{ emr.patient.blood_group }}</template></span>
        </div>
        <a-tag v-if="emr.patient.allergies" color="red"><WarningOutlined /> {{ s('Allergies') }}: {{ emr.patient.allergies }}</a-tag>
        <div class="head-actions">
          <a-button :loading="saving" @click="save()">{{ s('Save') }}</a-button>
          <a-button :disabled="!f.prescription.length" @click="printRx">{{ s('Print prescription') }}</a-button>
          <a-popconfirm v-if="!finished" :title="s('Finish the consultation? The patient gets the satisfaction survey by email.')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="finish">
            <a-button type="primary">{{ s('Finish consultation') }}</a-button>
          </a-popconfirm>
        </div>
      </div>

      <a-row :gutter="12">
        <!-- Left: problem-oriented summary -->
        <a-col :xs="24" :lg="7">
          <a-card size="small" :title="s('Problems')" class="side-card">
            <div v-for="p in problems" :key="p.id || p.problem" class="prob" :class="{ resolved: p.status === 'resolved' }">
              <span>{{ p.problem }}<span v-if="p.code" class="muted"> · {{ p.code }}</span></span>
              <a-button size="small" type="link" @click="p.status = p.status === 'resolved' ? 'active' : 'resolved'">
                {{ p.status === 'resolved' ? s('Reactivate') : s('Resolve') }}
              </a-button>
            </div>
            <a-input-search v-model:value="newProblem" :placeholder="s('Add a problem / diagnosis')" :enter-button="s('Add')" size="small" @search="addProblem" />
          </a-card>
          <a-card size="small" :title="s('Background')" class="side-card">
            <div><b>{{ s('Chronic conditions') }}:</b> {{ emr.patient.chronic_conditions || '—' }}</div>
            <div><b>{{ s('Insurance') }}:</b> {{ emr.patient.insurance_provider || '—' }}</div>
            <template v-if="emr.pre_registration">
              <div class="pre-title">{{ s('Pre-registration from home') }}</div>
              <div v-for="(v, k) in emr.pre_registration" :key="k"><b>{{ s(PRE_LABEL[k] || k) }}:</b> {{ v }}</div>
            </template>
          </a-card>
          <a-card size="small" :title="`${s('History')} (${emr.history.length})`" class="side-card">
            <a-empty v-if="!emr.history.length" :description="s('First consultation')" />
            <a-collapse ghost>
              <a-collapse-panel v-for="h in emr.history" :key="h.id" :header="`${d(h.date)} · ${h.doctor || ''}`">
                <div><b>{{ s('Diagnosis') }}:</b> {{ h.diagnosis || '—' }}</div>
                <div><b>{{ s('Plan') }}:</b> {{ h.treatment_plan || '—' }}</div>
                <div class="muted" v-if="h.weight">{{ h.weight }} kg · {{ h.height }} cm · {{ h.bp_systolic }}/{{ h.bp_diastolic }}</div>
              </a-collapse-panel>
            </a-collapse>
          </a-card>
        </a-col>

        <!-- Right: this consultation -->
        <a-col :xs="24" :lg="17">
          <div class="vitals" v-if="emr.visit">
            <span v-for="v in vitalChips" :key="v.k" class="vchip" :class="{ bad: v.bad }">{{ s(v.k) }} <b>{{ v.v }}</b></span>
            <span v-if="!vitalChips.length" class="muted">{{ s('No vital signs yet') }}</span>
          </div>
          <a-card size="small">
            <a-tabs v-model:activeKey="tab">
              <a-tab-pane key="note" :tab="s('Note')">
                <div v-for="fld in SOAP" :key="fld.key" class="soap">
                  <div class="soap-label">
                    <b>{{ s(fld.label) }}</b>
                    <a-button size="small" :type="dictating === fld.key ? 'primary' : 'default'" :danger="dictating === fld.key" @click="dictate(fld.key)">
                      <AudioOutlined /> {{ dictating === fld.key ? s('Stop') : s('Dictate') }}
                    </a-button>
                  </div>
                  <a-textarea v-model:value="f[fld.key]" :rows="fld.rows" :placeholder="s(fld.hint)" />
                </div>
                <div v-if="!speechOk" class="muted">{{ s('Voice dictation works in Chrome / Edge.') }}</div>
              </a-tab-pane>

              <a-tab-pane key="specialty" :tab="s(TEMPLATES[f.template]?.label || 'Specialty')">
                <a-select v-model:value="f.template" style="width: 260px; margin-bottom: 10px" :options="templateOptions" />
                <div v-for="sec in visibleSections" :key="sec.title" class="sec">
                  <div class="sec-title">{{ s(sec.title) }}</div>
                  <a-row :gutter="10">
                    <a-col v-for="fd in sec.fields" :key="fd.key" :xs="24" :md="fd.type === 'textarea' ? 24 : 12">
                      <div class="fl">{{ s(fd.label) }}<span v-if="fd.unit" class="muted"> ({{ fd.unit }})</span></div>
                      <a-input-number v-if="fd.type === 'number'" v-model:value="f.spec[fd.key]" style="width: 100%" :status="fd.alert && fd.alert(f.spec[fd.key]) ? 'warning' : ''" />
                      <a-input v-else-if="fd.type === 'text'" v-model:value="f.spec[fd.key]" :placeholder="fd.placeholder" />
                      <a-textarea v-else-if="fd.type === 'textarea'" v-model:value="f.spec[fd.key]" :rows="2" />
                      <a-date-picker v-else-if="fd.type === 'date'" v-model:value="f.spec[fd.key]" value-format="YYYY-MM-DD" style="width: 100%" />
                      <a-select v-else-if="fd.type === 'select'" v-model:value="f.spec[fd.key]" allow-clear style="width: 100%"
                        :options="fd.options.map(o => ({ value: o, label: s(o) }))" :status="fd.alert && fd.alert(f.spec[fd.key]) ? 'warning' : ''" />
                      <a-checkbox-group v-else-if="fd.type === 'check'" v-model:value="f.spec[fd.key]" :options="fd.options.map(o => ({ value: o, label: s(o) }))" />
                      <div v-else-if="fd.type === 'computed'" class="computed">{{ translateComputed(fd.fn(f.spec, { visit: emr.visit })) || '—' }}</div>
                      <div v-if="fd.alert && fd.alert(f.spec[fd.key])" class="warn-text">{{ s('Out of range') }}</div>
                    </a-col>
                  </a-row>
                </div>
                <GrowthChart v-if="TEMPLATES[f.template]?.growth" :points="growthPoints" :gender="emr.patient.gender" :s="s" />
                <div v-if="TEMPLATES[f.template]?.bpChart && bpSeries.length > 1" class="sec">
                  <div class="sec-title">{{ s('Blood pressure over time') }}</div>
                  <svg viewBox="0 0 520 160" class="chart">
                    <polyline :points="bpLine('sys')" fill="none" stroke="#dc2626" stroke-width="2" />
                    <polyline :points="bpLine('dia')" fill="none" stroke="#2563eb" stroke-width="2" />
                    <line x1="0" :y1="bpY(140)" x2="520" :y2="bpY(140)" stroke="#dc2626" stroke-dasharray="4" opacity="0.4" />
                    <text v-for="(p, i) in bpSeries" :key="i" :x="bpX(i)" y="156" font-size="9" text-anchor="middle" fill="currentColor" opacity="0.6">{{ p.label }}</text>
                  </svg>
                </div>
              </a-tab-pane>

              <a-tab-pane key="rx" :tab="`${s('Prescription')} (${f.prescription.length})`">
                <div v-for="(it, i) in f.prescription" :key="i" class="rx-row">
                  <a-select v-model:value="it.product_id" show-search allow-clear option-filter-prop="label" :options="medicineOptions" style="flex: 2; min-width: 200px"
                    :placeholder="s('Medicine (vademecum)')" @change="v => onMed(it, v)" />
                  <a-input v-if="!it.product_id" v-model:value="it.medicine" :placeholder="s('Or type it')" style="flex: 2; min-width: 160px" />
                  <a-input v-model:value="it.dosage" :placeholder="s('Dose')" style="flex: 1; min-width: 90px" />
                  <a-input v-model:value="it.frequency" :placeholder="s('Every')" style="flex: 1; min-width: 90px" />
                  <a-input v-model:value="it.duration" :placeholder="s('For')" style="flex: 1; min-width: 80px" />
                  <a-input-number v-model:value="it.quantity" :min="0" :placeholder="s('Qty')" style="width: 70px" />
                  <a-input v-model:value="it.instructions" :placeholder="s('Instructions')" style="flex: 2; min-width: 140px" />
                  <a-button type="text" danger @click="f.prescription.splice(i, 1)"><DeleteOutlined /></a-button>
                </div>
                <a-button @click="f.prescription.push({ product_id: undefined, medicine: '', dosage: '', frequency: '', duration: '', quantity: 1, instructions: '' })">
                  <PlusOutlined /> {{ s('Add medicine') }}
                </a-button>
                <a-textarea v-model:value="f.prescription_notes" :rows="2" :placeholder="s('Prescription notes')" style="margin-top: 10px" />
              </a-tab-pane>

              <a-tab-pane key="orders" :tab="`${s('Orders')} (${f.test_ids.length + (f.referral.doctor_id ? 1 : 0)})`">
                <div class="sec-title">{{ s('Lab and imaging studies') }}</div>
                <div v-for="(tests, cat) in testsByCategory" :key="cat" class="cat">
                  <div class="muted">{{ cat }}</div>
                  <a-checkbox-group v-model:value="f.test_ids" :options="tests.map(t => ({ value: t.id, label: t.name }))" />
                </div>
                <div class="sec-title" style="margin-top: 12px">{{ s('Referral to another specialist') }}</div>
                <a-row :gutter="8">
                  <a-col :xs="24" :md="10"><a-select v-model:value="f.referral.doctor_id" allow-clear show-search option-filter-prop="label" :options="referralOptions" style="width: 100%" :placeholder="s('Specialist')" /></a-col>
                  <a-col :xs="24" :md="14"><a-input v-model:value="f.referral.reason" :placeholder="s('Reason for the referral')" /></a-col>
                </a-row>
                <div v-if="emr.labs.length" class="sec">
                  <div class="sec-title">{{ s('Previous results') }}</div>
                  <div v-for="o in emr.labs" :key="o.id" class="lab">
                    <span class="muted">{{ d(o.ordered_at) }} · {{ o.reference }} · {{ o.status }}</span>
                    <div v-for="(it, i) in o.items" :key="i">{{ it.test }}: <b :class="{ 'warn-text': it.flag && it.flag !== 'normal' }">{{ it.result || '—' }} {{ it.unit || '' }}</b> <span class="muted">{{ it.range }}</span></div>
                  </div>
                </div>
              </a-tab-pane>

              <a-tab-pane key="follow" :tab="s('Follow-up')">
                <div class="fl">{{ s('Come back in') }}</div>
                <a-radio-group v-model:value="f.follow.weeks" button-style="solid" @change="f.follow.date = null">
                  <a-radio-button :value="0">{{ s('No follow-up') }}</a-radio-button>
                  <a-radio-button v-for="w in [1, 2, 4, 8, 12, 24, 52]" :key="w" :value="w">{{ w < 4 ? `${w} ${s('wk')}` : `${Math.round(w / 4.33)} ${s('mo')}` }}</a-radio-button>
                </a-radio-group>
                <a-row :gutter="8" style="margin-top: 10px">
                  <a-col :xs="24" :md="8"><div class="fl">{{ s('Or a date') }}</div><a-date-picker v-model:value="f.follow.date" value-format="YYYY-MM-DD" style="width: 100%" /></a-col>
                  <a-col :xs="24" :md="16"><div class="fl">{{ s('Studies to bring') }}</div><a-input v-model:value="f.follow.studies" :placeholder="s('e.g. Lipid profile and ECG')" /></a-col>
                </a-row>
                <div class="fl" style="margin-top: 10px">{{ s('Note for reception') }}</div>
                <a-input v-model:value="f.follow.note" />
                <p class="muted" style="margin-top: 8px">{{ s('Reception gets the follow-up in "To schedule" and books it.') }}</p>
              </a-tab-pane>
            </a-tabs>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * My consultation (specialist clinic, 2026-10-26): the doctor's day (who is
 * ready, waiting how long) and the problem-oriented EMR of a consultation:
 * SOAP note with voice dictation, the specialty template (pediatrics growth
 * curves, ophthalmology visual acuity, cardiology BP chart, gynecology /
 * obstetrics gestational age, psychology / psychiatry scales), prescription
 * from the pharmacy vademecum, lab / imaging orders, referral, follow-up;
 * Finish = visit completed + survey email.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount, h } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { ReloadOutlined, ArrowLeftOutlined, WarningOutlined, AudioOutlined, PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { sortByLabel } from '../../lib/filterOptions';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';
import { TEMPLATES, TEMPLATE_KEYS, WHO, WHO_MONTHS } from './specialtyTemplates';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/SpecialistConsult.es.js';

const { s } = useHospitalText(ES);
const ui = useUiStore();
const GENDER = { male: 'Male', female: 'Female', other: 'Other' };
const FLOW = {
  scheduled: { label: 'Booked', color: 'default' }, confirmed: { label: 'Confirmed', color: 'blue' }, checked_in: { label: 'At the nursing station', color: 'orange' },
  ready: { label: 'Ready to go in', color: 'green' }, in_consult: { label: 'In consultation', color: 'purple' }, finished: { label: 'Attended', color: 'cyan' },
  no_show: { label: 'No-show', color: 'red' }, cancelled: { label: 'Cancelled', color: 'default' }, arrived: { label: 'Arrived', color: 'orange' },
};
const SOAP = [
  { key: 'complaint', label: 'Subjective: reason and history', hint: 'What the patient tells, since when, how...', rows: 3 },
  { key: 'examination', label: 'Objective: examination', hint: 'Physical examination findings', rows: 3 },
  { key: 'diagnosis', label: 'Assessment: diagnosis', hint: 'Diagnosis (with its code if you use one)', rows: 2 },
  { key: 'treatment_plan', label: 'Plan', hint: 'Treatment, indications, recommendations', rows: 3 },
];
const PRE_LABEL = { reason: 'Reason', symptoms_since: 'Since when', current_medication: 'Current medication', allergies: 'Allergies', history: 'Medical history' };

// ---------------------------------------------------------------- the day
const date = ref(dayjs().format('YYYY-MM-DD'));
const doctorId = ref(undefined);
const day = ref({ doctor: null, doctors: [], appointments: [] });
const doctorOptions = computed(() => sortByLabel(day.value.doctors.map(x => ({ value: x.id, label: x.specialty ? `${x.name} (${x.specialty})` : x.name }))));
const hm = iso => dayjs(iso).format('HH:mm');
const d = iso => (iso ? dayjs(iso).format('DD-MM-YYYY') : '');
const mins = iso => (iso ? `${dayjs().diff(dayjs(iso), 'minute')} min` : '');

async function loadDay() {
  try {
    day.value = await http.get('hospital/clinic/my-queue', { date: date.value, doctor_id: doctorId.value || '' });
    if (day.value.doctor && !doctorId.value) doctorId.value = day.value.doctor.id;
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  }
}

// ---------------------------------------------------------------- the EMR
const emr = ref(null);
const tab = ref('note');
const saving = ref(false);
const problems = ref([]);
const newProblem = ref('');
const f = reactive({ complaint: '', examination: '', diagnosis: '', treatment_plan: '', template: 'general', spec: {}, prescription: [], prescription_notes: '',
  test_ids: [], referral: { doctor_id: undefined, reason: '' }, follow: { weeks: 0, date: null, note: '', studies: '' } });
const finished = computed(() => emr.value?.appointment?.flow === 'finished');

async function openEmr(a, start = false) {
  try {
    if (start) await http.post(`hospital/clinic/consult/${a.id}/start`, {});
    const r = await http.get(`hospital/clinic/consult/${a.id}`);
    emr.value = r;
    const v = r.visit || {};
    Object.assign(f, {
      complaint: v.complaint || a.reason || '', examination: v.examination || '', diagnosis: v.diagnosis || '', treatment_plan: v.treatment_plan || '',
      template: v.specialty_template || day.value.doctor?.specialty_template || 'general', spec: { ...(v.specialty_data || {}) },
      prescription: (v.prescription || []).map(x => ({ ...x })), prescription_notes: v.prescription_notes || '',
      test_ids: [], referral: { doctor_id: undefined, reason: '' },
      follow: { weeks: 0, date: v.follow_up_date || null, note: v.follow_up_note || '', studies: '' },
    });
    const thisOrder = (r.labs || []).find(o => o.visit_id === v.id);
    if (thisOrder) f.test_ids = [...thisOrder.test_ids];
    problems.value = (r.problems || []).map(p => ({ ...p }));
    tab.value = 'note';
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  }
}
function closeEmr() { stopDictation(); emr.value = null; loadDay(); }

function addProblem() {
  const t = newProblem.value.trim();
  if (!t) return;
  problems.value.push({ problem: t, status: 'active' });
  newProblem.value = '';
}

async function save(silent = false) {
  saving.value = true;
  try {
    await http.put(`hospital/clinic/consult/${emr.value.appointment.id}`, {
      complaint: f.complaint, examination: f.examination, diagnosis: f.diagnosis, treatment_plan: f.treatment_plan,
      specialty_template: f.template, specialty_data: f.spec,
      prescription_items: f.prescription.filter(x => x.medicine || x.product_id).map(x => ({ ...x, medicine: x.medicine || medName(x.product_id) })),
      prescription_notes: f.prescription_notes, problems: problems.value, test_ids: f.test_ids,
      referral: f.referral.doctor_id ? f.referral : null,
      follow_up: { weeks: f.follow.date ? null : f.follow.weeks, date: f.follow.date, note: f.follow.note, studies: f.follow.studies },
    });
    if (!silent) message.success(s('Saved'));
    return true;
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
    return false;
  } finally {
    saving.value = false;
  }
}
async function finish() {
  if (!(await save(true))) return;
  try {
    const r = await http.post(`hospital/clinic/consult/${emr.value.appointment.id}/finish`, {});
    message.success(r.survey_sent_to ? `${s('Consultation finished. Survey sent to')} ${r.survey_sent_to}` : s('Consultation finished.'));
    closeEmr();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}

// Vital signs from the nursing station
const vitalChips = computed(() => {
  const v = emr.value?.visit || {};
  const out = [];
  if (v.weight) out.push({ k: 'Weight', v: `${v.weight} kg` });
  if (v.height) out.push({ k: 'Height', v: `${v.height} cm` });
  if (v.bp_systolic) out.push({ k: 'BP', v: `${v.bp_systolic}/${v.bp_diastolic || '—'}`, bad: v.bp_systolic >= 140 || v.bp_diastolic >= 90 });
  if (v.temperature) out.push({ k: 'Temp.', v: `${v.temperature} °C`, bad: v.temperature >= 38 });
  if (v.pulse) out.push({ k: 'Pulse', v: v.pulse });
  if (v.spo2) out.push({ k: 'SpO₂', v: `${v.spo2}%`, bad: v.spo2 < 92 });
  if (v.respiratory_rate) out.push({ k: 'RR', v: v.respiratory_rate });
  if (v.specialty_data?.nursing_note) out.push({ k: 'Nursing note', v: v.specialty_data.nursing_note });
  return out;
});

// Template
const templateOptions = computed(() => TEMPLATE_KEYS.map(k => ({ value: k, label: s(TEMPLATES[k].label) })));
const visibleSections = computed(() => (TEMPLATES[f.template]?.sections || []).filter(sec => !sec.when || sec.when(f.spec)));
const translateComputed = txt => String(txt || '').split(' · ').map(p => s(p)).join(' · ').replace(/ w /, ` ${s('w')} `).replace(/ d$/, ` ${s('d')}`);

// Pediatric growth points (weight / height by age in months)
const growthPoints = computed(() => {
  if (!emr.value) return [];
  const dob = emr.value.patient.date_of_birth;
  if (!dob) return [];
  const visits = [...emr.value.history, emr.value.visit].filter(Boolean);
  return visits.filter(v => v.weight || v.height).map(v => ({ m: dayjs(v.date).diff(dayjs(dob), 'month', true), weight: v.weight, height: v.height }))
    .filter(p => p.m >= 0 && p.m <= 60).sort((a, b) => a.m - b.m);
});
// Cardiology BP series
const bpSeries = computed(() => {
  if (!emr.value) return [];
  return [...emr.value.history].reverse().concat(emr.value.visit ? [emr.value.visit] : []).filter(v => v.bp_systolic)
    .slice(-10).map(v => ({ sys: v.bp_systolic, dia: v.bp_diastolic || 0, label: dayjs(v.date).format('DD/MM') }));
});
const bpX = i => 20 + (i * 480) / Math.max(1, bpSeries.value.length - 1);
const bpY = v => 145 - ((v - 40) * 135) / 160;
const bpLine = k => bpSeries.value.map((p, i) => `${bpX(i)},${bpY(p[k])}`).join(' ');

// Prescription
const medicineOptions = computed(() => sortByLabel((emr.value?.catalog?.medicines || []).map(m => ({ value: m.id,
  label: [m.name, m.generic_name, m.strength, m.prescription_required ? 'Rx' : ''].filter(Boolean).join(' · ') }))));
const medName = id => (emr.value?.catalog?.medicines || []).find(m => m.id === id)?.name || '';
function onMed(it, id) { if (id) it.medicine = medName(id); }
const referralOptions = computed(() => sortByLabel((emr.value?.catalog?.doctors || []).map(x => ({ value: x.id, label: x.specialty ? `${x.name} (${x.specialty})` : x.name }))));
const testsByCategory = computed(() => {
  const out = {};
  (emr.value?.catalog?.lab_tests || []).forEach(t => { (out[t.category || s('Other')] ||= []).push(t); });
  return out;
});

function printRx() {
  const w = window.open('', '_blank');
  if (!w) return message.warning(s('Allow pop-up windows to print.'));
  const p = emr.value.patient;
  const rows = f.prescription.map((x, i) => `<tr><td>${i + 1}</td><td><b>${esc(x.medicine || medName(x.product_id))}</b></td><td>${esc(x.dosage)}</td><td>${esc(x.frequency)}</td><td>${esc(x.duration)}</td><td>${esc(x.quantity ?? '')}</td><td>${esc(x.instructions)}</td></tr>`).join('');
  w.document.write(`<html><head><title>${s('Prescription')}</title><style>body{font-family:Arial;padding:24px;font-size:13px}h2{margin:0}table{width:100%;border-collapse:collapse;margin-top:14px}td,th{border-bottom:1px solid #ccc;padding:6px;text-align:left}.sign{margin-top:70px;border-top:1px solid #000;width:260px;text-align:center;padding-top:4px}</style></head><body>
    <h2>${esc(day.value.doctor?.name || '')}</h2><div>${esc(day.value.doctor?.specialty || '')}</div><hr>
    <div><b>${s('Patient')}:</b> ${esc(p.name)} · ${esc(p.mrn)} · ${p.age ?? ''} ${s('y')}</div><div><b>${s('Date')}:</b> ${dayjs().format('DD-MM-YYYY')}</div>
    ${p.allergies ? `<div style="color:#b91c1c"><b>${s('Allergies')}:</b> ${esc(p.allergies)}</div>` : ''}
    <div><b>${s('Diagnosis')}:</b> ${esc(f.diagnosis)}</div>
    <table><tr><th>#</th><th>${s('Medicine')}</th><th>${s('Dose')}</th><th>${s('Every')}</th><th>${s('For')}</th><th>${s('Qty')}</th><th>${s('Instructions')}</th></tr>${rows}</table>
    ${f.prescription_notes ? `<p>${esc(f.prescription_notes)}</p>` : ''}
    <div class="sign">${esc(day.value.doctor?.name || '')}</div><script>window.print()<\/script></body></html>`);
  w.document.close();
}
const esc = v => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// ---------------------------------------------------------------- voice dictation (Web Speech API, free, in the browser)
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
const speechOk = !!SR;
const dictating = ref(null);
let rec = null;
function dictate(key) {
  if (!speechOk) return message.info(s('Voice dictation works in Chrome / Edge.'));
  if (dictating.value === key) return stopDictation();
  stopDictation();
  rec = new SR();
  rec.lang = (ui.locale || 'es') === 'es' ? 'es-PE' : 'en-US';
  rec.continuous = true;
  rec.interimResults = false;
  rec.onresult = ev => {
    for (let i = ev.resultIndex; i < ev.results.length; i++) {
      if (ev.results[i].isFinal) {
        const txt = ev.results[i][0].transcript.trim();
        f[key] = (f[key] ? `${f[key].trimEnd()} ` : '') + txt.charAt(0).toUpperCase() + txt.slice(1);
      }
    }
  };
  rec.onend = () => { if (dictating.value === key) dictating.value = null; };
  rec.start();
  dictating.value = key;
}
function stopDictation() {
  try { rec?.stop(); } catch (e) { /* already stopped */ }
  rec = null;
  dictating.value = null;
}

// ---------------------------------------------------------------- growth chart (WHO references, approximate)
const GrowthChart = {
  props: ['points', 'gender', 's'],
  setup(props) {
    const W = 520; const H = 220;
    return () => {
      const g = props.gender === 'female' ? 'female' : 'male';
      const charts = [['weight', 'Weight for age (kg)', 0, 26], ['height', 'Height for age (cm)', 40, 125]];
      return h('div', { class: 'sec' }, charts.map(([kind, title, lo, hi]) => {
        const x = m => 30 + (m * (W - 40)) / 60;
        const y = v => H - 20 - ((v - lo) * (H - 30)) / (hi - lo);
        const ref = WHO[kind][g];
        const line = (arr, color, dash) => h('polyline', { points: WHO_MONTHS.map((m, i) => `${x(m)},${y(arr[i])}`).join(' '), fill: 'none', stroke: color, 'stroke-width': 1.5, 'stroke-dasharray': dash || '' });
        const pts = props.points.filter(p => p[kind]);
        return h('div', { style: 'margin-top:10px' }, [
          h('div', { class: 'sec-title' }, `${props.s(title)} · ${props.s('WHO reference (approx.)')}`),
          h('svg', { viewBox: `0 0 ${W} ${H}`, class: 'chart' }, [
            line(ref.p97, '#94a3b8', '4'), line(ref.p50, '#16a34a'), line(ref.p3, '#94a3b8', '4'),
            ...[0, 12, 24, 36, 48, 60].map(m => h('text', { x: x(m), y: H - 4, 'font-size': 9, 'text-anchor': 'middle', fill: 'currentColor', opacity: 0.6 }, `${m}m`)),
            pts.length > 1 ? h('polyline', { points: pts.map(p => `${x(p.m)},${y(p[kind])}`).join(' '), fill: 'none', stroke: '#2563eb', 'stroke-width': 2 }) : null,
            ...pts.map(p => h('circle', { cx: x(p.m), cy: y(p[kind]), r: 4, fill: '#2563eb' })),
          ]),
          pts.length ? null : h('div', { class: 'muted' }, props.s('No measurements in the first 5 years.')),
        ]);
      }));
    };
  },
};

let timer = null;
onMounted(() => { loadDay(); timer = setInterval(() => { if (!emr.value) loadDay(); }, 30000); });
onBeforeUnmount(() => { clearInterval(timer); stopDictation(); });
</script>

<style scoped>
.muted { opacity: 0.6; font-size: 12px; }
.day-row { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 10px; margin-bottom: 8px; border-left: 4px solid #94a3b8; }
.day-row.st-ready { border-left-color: #16a34a; background: rgba(22, 163, 74, 0.06); }
.day-row.st-in_consult { border-left-color: #7c3aed; }
.day-row.st-finished { opacity: 0.7; }
.d-time { font-weight: 700; width: 50px; }
.d-who { flex: 1; min-width: 0; }
.wait { color: #ea580c; font-weight: 600; font-size: 12px; }
.pill { font-size: 10px; padding: 0 6px; border-radius: 8px; background: rgba(37, 99, 235, 0.15); }
.emr-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.emr-head .who { display: flex; flex-direction: column; }
.head-actions { margin-left: auto; display: flex; gap: 8px; }
.side-card { margin-bottom: 10px; }
.prob { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed rgba(128, 128, 128, 0.2); }
.prob.resolved span { text-decoration: line-through; opacity: 0.6; }
.pre-title { font-weight: 700; margin-top: 8px; }
.vitals { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.vchip { background: rgba(128, 128, 128, 0.1); padding: 3px 10px; border-radius: 12px; font-size: 13px; }
.vchip.bad { background: rgba(220, 38, 38, 0.12); color: #dc2626; }
.soap { margin-bottom: 10px; }
.soap-label { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.sec { margin-bottom: 12px; }
.sec-title { font-weight: 700; margin: 6px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.2); }
.fl { font-size: 12px; margin: 6px 0 2px; }
.computed { padding: 4px 0; font-weight: 600; }
.warn-text { color: #dc2626; font-size: 12px; }
.chart { width: 100%; max-width: 620px; background: rgba(128, 128, 128, 0.04); border-radius: 8px; }
.rx-row { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; align-items: center; }
.cat { margin-bottom: 8px; }
.lab { padding: 4px 0; border-bottom: 1px dashed rgba(128, 128, 128, 0.2); }
</style>
