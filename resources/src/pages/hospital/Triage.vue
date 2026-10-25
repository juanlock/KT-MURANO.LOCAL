<template>
  <!--
    Triage (user's rules, 2026-10-25): after the admission window, the triage
    nurse calls the patient to the office, takes the vital signs, weight and
    height, describes why they came (allergies, chronic illnesses, usual
    medication), sets the level 1-5 and sends them to an ER bed of the shift.
    Critical patients already in a bed are triaged there after being seen.
  -->
  <div class="page">
    <PageHeader :title="s('Triage')" :subtitle="s('Call the patient, take the vital signs and send them to an ER bed.')" :breadcrumb="[s('Hospital'), s('Triage')]">
      <template #actions>
        <a-button :loading="loading" @click="load"><template #icon><ReloadOutlined /></template>{{ s('Refresh') }}</a-button>
      </template>
    </PageHeader>

    <div class="grid">
      <!-- Queue -->
      <a-card size="small" :title="`${s('Waiting for triage')} (${data.queue.length})`">
        <a-empty v-if="!data.queue.length" :description="s('Nobody waiting for triage.')" />
        <div v-for="q in data.queue" :key="q.id" class="q" :class="{ sel: cur?.id === q.id, called: q.triage_status === 'called' }" @click="pick(q)">
          <div class="q-top">
            <b>{{ q.name }}</b>
            <a-tag v-if="q.pathway === 'critical'" color="red">{{ s('Critical · in bed') }} {{ q.bed }}</a-tag>
            <a-tag v-else-if="q.triage_status === 'called'" color="blue">{{ s('In triage') }}</a-tag>
            <span class="wait" :class="{ late: q.wait_minutes > 30 }">{{ mins(q.wait_minutes) }}</span>
          </div>
          <div class="muted">{{ [q.age != null ? q.age + ' ' + s('y') : null, q.mrn].filter(Boolean).join(' · ') }}</div>
          <div class="reason">{{ q.complaint || '—' }}</div>
          <div class="q-act">
            <a-tag v-if="q.registration_pending" color="red">{{ s('Registration pending') }}</a-tag>
            <span v-else class="muted">{{ payerLine(q) }}</span>
            <a-button v-if="q.triage_status === 'pending' && q.pathway !== 'critical'" size="small" @click.stop="call(q)">📣 {{ s('Call') }}</a-button>
            <a-button size="small" type="primary" @click.stop="pick(q)">{{ s('Triage') }}</a-button>
          </div>
        </div>
      </a-card>

      <!-- The triage form -->
      <a-card size="small" :title="cur ? `${s('Triage of')} ${cur.name}` : s('Pick a patient from the queue')">
        <template v-if="cur">
          <a-alert v-if="f.allergies" type="error" show-icon :message="s('Allergies') + ': ' + f.allergies" class="mb" />
          <div class="h">{{ s('Vital signs') }}</div>
          <div class="vit">
            <label>{{ s('Temp. °C') }}<a-input-number v-model:value="f.temperature" :min="25" :max="45" :step="0.1" /></label>
            <label>{{ s('Pulse') }}<a-input-number v-model:value="f.pulse" :min="0" :max="300" /></label>
            <label>{{ s('BP sys.') }}<a-input-number v-model:value="f.bp_systolic" :min="0" :max="300" /></label>
            <label>{{ s('BP dia.') }}<a-input-number v-model:value="f.bp_diastolic" :min="0" :max="250" /></label>
            <label>{{ s('Resp. rate') }}<a-input-number v-model:value="f.respiratory_rate" :min="0" :max="80" /></label>
            <label>SpO₂ %<a-input-number v-model:value="f.spo2" :min="0" :max="100" /></label>
            <label>{{ s('Pain 0-10') }}<a-input-number v-model:value="f.pain_score" :min="0" :max="10" /></label>
            <label>{{ s('Weight kg') }}<a-input-number v-model:value="f.weight_kg" :min="0.3" :max="400" :step="0.1" /></label>
            <label>{{ s('Height cm') }}<a-input-number v-model:value="f.height_cm" :min="20" :max="250" /></label>
            <div v-if="bmi" class="bmi">{{ s('BMI') }} <b>{{ bmi }}</b></div>
          </div>

          <div class="h">{{ s('Why the patient came') }}</div>
          <a-textarea v-model:value="f.complaint" :rows="3" :placeholder="s('What they feel, since when, how it started...')" />
          <a-row :gutter="10" style="margin-top: 8px">
            <a-col :xs="24" :md="8"><div class="lbl">{{ s('Allergies') }}</div><a-input v-model:value="f.allergies" :placeholder="s('None known')" /></a-col>
            <a-col :xs="24" :md="8"><div class="lbl">{{ s('Chronic illnesses') }}</div><a-input v-model:value="f.chronic_conditions" :placeholder="s('e.g. Diabetes, hypertension')" /></a-col>
            <a-col :xs="24" :md="8"><div class="lbl">{{ s('Usual medication') }}</div><a-input v-model:value="f.usual_medication" /></a-col>
          </a-row>
          <div class="lbl" style="margin-top: 8px">{{ s('Triage notes') }}</div>
          <a-textarea v-model:value="f.triage_notes" :rows="2" />

          <div class="h">{{ s('Triage level') }}</div>
          <div class="levels">
            <button v-for="t in TRIAGE" :key="t.value" type="button" class="lv" :class="{ on: f.triage_level === t.value }" :style="{ '--c': t.color }" @click="f.triage_level = t.value">
              <b>{{ t.value }}</b><span>{{ s(t.label) }}</span><small>{{ s(t.wait) }}</small>
            </button>
          </div>

          <template v-if="!cur.bed">
            <div class="h">{{ s('Send to an ER bed') }}</div>
            <div class="beds">
              <button v-for="b in data.er_beds" :key="b.id" type="button" class="bed" :class="{ on: f.bed_id === b.id }" @click="f.bed_id = f.bed_id === b.id ? null : b.id">🛏 {{ b.label }}</button>
              <span v-if="!data.er_beds.length" class="muted">{{ s('No free ER bed now: the patient waits for one (admission calls them).') }}</span>
            </div>
          </template>
          <div v-else class="muted mt">{{ s('Already in ER bed {b}.', { b: cur.bed }) }}</div>

          <div class="foot">
            <a-button type="primary" size="large" :loading="saving" @click="save">{{ f.bed_id ? s('Save and send to the bed') : s('Save triage') }}</a-button>
          </div>
        </template>
        <a-empty v-else :description="s('Pick a patient from the queue')" />
      </a-card>
    </div>

    <a-card v-if="data.done_today.length" size="small" :title="`${s('Triaged today')} (${data.done_today.length})`" style="margin-top: 12px">
      <a-table :data-source="data.done_today" :columns="doneCols" size="small" row-key="id" :pagination="{ pageSize: 10, hideOnSinglePage: true }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'level'"><span class="dot" :style="{ background: (TRIAGE.find(t => t.value === record.triage_level) || {}).color }">{{ record.triage_level }}</span></template>
          <template v-else-if="column.key === 'when'">{{ hm(record.triaged_at) }}</template>
          <template v-else-if="column.key === 'vit'">{{ vitLine(record.vitals) }}</template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/Triage.es.js';

const { s } = useHospitalText(ES);
const TRIAGE = [
  { value: 1, label: 'Resuscitation', color: '#dc2626', wait: 'now' },
  { value: 2, label: 'Emergency', color: '#ea580c', wait: '10 min' },
  { value: 3, label: 'Urgent', color: '#ca8a04', wait: '30 min' },
  { value: 4, label: 'Less urgent', color: '#16a34a', wait: '60 min' },
  { value: 5, label: 'Not urgent', color: '#2563eb', wait: '120 min' },
];
const loading = ref(false);
const saving = ref(false);
const data = ref({ queue: [], done_today: [], er_beds: [] });
const cur = ref(null);
const blank = () => ({ temperature: null, pulse: null, bp_systolic: null, bp_diastolic: null, respiratory_rate: null, spo2: null, pain_score: null,
  weight_kg: null, height_cm: null, complaint: '', allergies: '', chronic_conditions: '', usual_medication: '', triage_notes: '', triage_level: null, bed_id: null });
const f = reactive(blank());
const bmi = computed(() => (f.weight_kg && f.height_cm ? (f.weight_kg / ((f.height_cm / 100) ** 2)).toFixed(1) : null));
const PAYER = { private: 'Private insurance', public: 'Public insurance', self_pay: 'Self-pay' };
const payerLine = q => (q.payer_type ? [s(PAYER[q.payer_type] || q.payer_type), q.insurer, q.card_last4 ? `****${q.card_last4}` : null].filter(Boolean).join(' · ') : '');
const mins = m => (m == null ? '' : m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`);
const hm = v => (v ? dayjs(v).format('HH:mm') : '');
const vitLine = v => [v.temperature ? `${Number(v.temperature)}°C` : null, v.pulse ? `${v.pulse} lpm` : null, v.bp_systolic ? `PA ${v.bp_systolic}/${v.bp_diastolic || ''}` : null,
  v.spo2 ? `SpO₂ ${v.spo2}%` : null, v.weight_kg ? `${v.weight_kg} kg` : null, v.height_cm ? `${v.height_cm} cm` : null].filter(Boolean).join(' · ');
const doneCols = computed(() => [
  { title: '', key: 'level', width: 50 }, { title: s('Patient'), dataIndex: 'name' }, { title: s('Reason'), dataIndex: 'complaint', ellipsis: true },
  { title: s('Vital signs'), key: 'vit' }, { title: s('Bed'), dataIndex: 'bed', width: 90 }, { title: s('At'), key: 'when', width: 80 },
]);

function pick(q) {
  cur.value = q;
  Object.assign(f, blank(), {
    ...Object.fromEntries(Object.entries(q.vitals || {}).filter(([, v]) => v != null).map(([k, v]) => [k, Number(v)])),
    complaint: q.complaint || '', allergies: q.allergies || '', chronic_conditions: q.chronic_conditions || '',
    usual_medication: q.usual_medication || '', triage_notes: q.triage_notes || '', triage_level: q.pathway === 'critical' ? q.triage_level : null,
  });
}
async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hospital/triage');
    if (cur.value && !data.value.queue.some(q => q.id === cur.value.id)) cur.value = null;
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  } finally {
    loading.value = false;
  }
}
async function call(q) {
  try {
    await http.post(`hospital/triage/${q.id}/call`);
    message.success(s('{n}, to the triage office', { n: q.name }));
    await load();
    pick(data.value.queue.find(x => x.id === q.id) || q);
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}
async function save() {
  if (!f.triage_level) return message.warning(s('Pick the triage level.'));
  if (!f.complaint.trim()) return message.warning(s('Describe why the patient came.'));
  saving.value = true;
  try {
    await http.post(`hospital/triage/${cur.value.id}`, { ...f });
    message.success(f.bed_id ? s('Triage saved: the patient goes to the bed.') : s('Triage saved.'));
    cur.value = null;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || 'Could not save'));
  } finally {
    saving.value = false;
  }
}
let timer = null;
onMounted(() => { load(); timer = setInterval(() => { if (!saving.value) load(); }, 30000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(300px, 380px) 1fr; gap: 12px; align-items: start; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
.q { border: 1px solid rgba(128, 128, 128, 0.22); border-radius: 10px; padding: 8px 10px; margin-bottom: 8px; cursor: pointer; }
.q:hover { border-color: #1677ff; }
.q.sel { border-color: #1677ff; box-shadow: 0 0 0 2px rgba(22, 119, 255, 0.2); }
.q.called { background: rgba(22, 119, 255, 0.05); }
.q-top { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.wait { margin-left: auto; font-weight: 700; font-size: 12.5px; }
.wait.late { color: #dc2626; }
.reason { font-size: 13px; margin: 3px 0; }
.q-act { display: flex; align-items: center; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
.q-act .muted { margin-right: auto; }
.muted { opacity: 0.6; font-size: 12px; }
.mb { margin-bottom: 8px; }
.mt { margin-top: 10px; }
.h { font-weight: 700; margin: 12px 0 6px; }
.lbl { font-size: 12.5px; opacity: 0.75; margin-bottom: 2px; }
.vit { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.vit label { display: flex; flex-direction: column; font-size: 12px; opacity: 0.9; }
.vit .ant-input-number { width: 96px; }
.bmi { padding: 4px 10px; border-radius: 8px; background: rgba(22, 119, 255, 0.08); }
.levels { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; }
.lv { border: 2px solid var(--c); border-radius: 10px; background: transparent; padding: 6px 4px; cursor: pointer; display: flex; flex-direction: column; align-items: center; color: inherit; }
.lv b { font-size: 20px; color: var(--c); }
.lv span { font-size: 12px; font-weight: 600; }
.lv small { font-size: 11px; opacity: 0.7; }
.lv.on { background: var(--c); color: #fff; }
.lv.on b { color: #fff; }
.beds { display: flex; flex-wrap: wrap; gap: 6px; }
.bed { border: 1px solid rgba(128, 128, 128, 0.35); border-radius: 8px; background: transparent; padding: 6px 10px; cursor: pointer; color: inherit; }
.bed.on { border-color: #16a34a; background: rgba(22, 163, 74, 0.12); font-weight: 700; }
.foot { margin-top: 14px; display: flex; justify-content: flex-end; }
.dot { display: inline-flex; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-weight: 700; align-items: center; justify-content: center; }
</style>
