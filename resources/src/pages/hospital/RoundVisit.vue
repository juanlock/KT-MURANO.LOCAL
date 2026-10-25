<template>
  <!--
    The visit of one patient on My round (2026-10-25): Today (what was done and
    what comes, labs, vitals), Nursing (today's doses, care done, vital signs,
    a change seen) and Doctor (progress note, prescription). Uses the clinical
    endpoints the chart uses; "Full chart" opens the stay's chart.
  -->
  <a-modal :open="open" :width="860" :footer="null" :title="title" destroy-on-close @cancel="$emit('close')">
    <template v-if="p">
      <div class="hdr">
        <div>
          <div class="muted">{{ [p.floor_name, p.room, p.bed].filter(Boolean).join(' · ') || p.where }}<template v-if="p.day"> · {{ s('day {n}', { n: p.day }) }}</template></div>
          <div v-if="p.suffers"><b>{{ s('Suffers from') }}:</b> {{ p.suffers }}</div>
          <div v-if="p.chronic" class="muted">{{ s('Chronic') }}: {{ p.chronic }}</div>
        </div>
        <a-tag v-if="p.blood_group" color="red">{{ p.blood_group }}</a-tag>
      </div>
      <a-alert v-if="p.allergies" type="error" show-icon :message="s('Allergies') + ': ' + p.allergies" class="mb" />

      <a-tabs v-model:activeKey="tab">
        <!-- ============ Today ============ -->
        <a-tab-pane key="today" :tab="s('Today')">
          <div class="cols">
            <div>
              <div class="h">{{ s('Done') }}</div>
              <div v-for="e in past" :key="e.key" class="ev" :class="e.cls"><span class="t">{{ e.time }}</span> {{ e.text }}</div>
              <div v-if="!past.length" class="muted">{{ s('Nothing yet today.') }}</div>
            </div>
            <div>
              <div class="h">{{ s('Coming') }}</div>
              <div v-for="e in upcoming" :key="e.key" class="ev" :class="e.cls"><span class="t">{{ e.time }}</span> {{ e.text }}</div>
              <div v-if="!upcoming.length" class="muted">{{ s('Nothing else today.') }}</div>
            </div>
          </div>
          <template v-if="p.vitals">
            <div class="h">{{ s('Last vital signs') }} <span class="muted">· {{ dt(p.vitals.taken_at) }}</span></div>
            <div>{{ vitalsLine(p.vitals) }}</div>
          </template>
          <div class="h">{{ s('Recent lab results') }}</div>
          <a-table v-if="p.labs.length" :data-source="p.labs" :columns="labCols" size="small" :pagination="false" :row-key="(r, i) => i">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'flag'"><a-tag v-if="record.flag" :color="record.flag === 'critical' ? 'red' : record.flag === 'normal' ? 'green' : 'orange'">{{ s(FLAG[record.flag] || record.flag) }}</a-tag></template>
              <template v-else-if="column.key === 'when'">{{ dt(record.ordered_at) }}</template>
            </template>
          </a-table>
          <div v-else class="muted">{{ s('No lab results in the last 3 days.') }}</div>
        </a-tab-pane>

        <!-- ============ Nursing ============ -->
        <a-tab-pane key="nursing" :tab="s('Nursing')">
          <div class="h">{{ s("Today's medication") }}</div>
          <div v-for="d in p.doses" :key="d.id" class="row" :class="doseCls(d)">
            <span class="t">{{ hm(d.scheduled_at) }}</span>
            <span class="what">{{ d.medicine }} · {{ d.dose }} · {{ d.route }}</span>
            <template v-if="d.status === 'pending'">
              <a-button size="small" type="primary" :loading="busy === 'd' + d.id" @click="dose(d, 'given')">{{ s('Given') }}</a-button>
              <a-button size="small" @click="dose(d, 'omitted')">{{ s('Omitted') }}</a-button>
              <a-button size="small" @click="dose(d, 'refused')">{{ s('Refused') }}</a-button>
            </template>
            <span v-else class="muted">{{ s(DOSE[d.status] || d.status) }}<template v-if="d.by"> · {{ d.by }} {{ hm(d.given_at) }}</template></span>
          </div>
          <div v-if="!p.doses.length" class="muted">{{ s('No medication today.') }}</div>

          <template v-if="p.care_due.length">
            <div class="h">{{ s('Care and diet') }}</div>
            <div v-for="o in p.care_due" :key="o.id" class="row" :class="{ due: o.due }">
              <span class="what">{{ o.description }}<template v-if="o.every_hours"> · {{ s('every {n} h', { n: o.every_hours }) }}</template></span>
              <span class="muted">{{ o.last ? s('last {t}', { t: dt(o.last) }) : s('not done yet') }}</span>
              <a-button size="small" :type="o.due ? 'primary' : 'default'" @click="careDone(o)">{{ s('Done') }}</a-button>
            </div>
          </template>

          <template v-if="p.admission_id">
            <div class="h">{{ s('Vital signs') }}</div>
            <div class="vit-form">
              <a-input-number v-model:value="vit.temperature" placeholder="T °C" :min="25" :max="45" :step="0.1" />
              <a-input-number v-model:value="vit.pulse" :placeholder="s('Pulse')" :min="0" :max="300" />
              <a-input-number v-model:value="vit.bp_systolic" :placeholder="s('BP sys.')" :min="0" :max="300" />
              <a-input-number v-model:value="vit.bp_diastolic" :placeholder="s('BP dia.')" :min="0" :max="250" />
              <a-input-number v-model:value="vit.spo2" placeholder="SpO₂" :min="0" :max="100" />
              <a-input-number v-model:value="vit.pain" :placeholder="s('Pain 0-10')" :min="0" :max="10" />
              <a-button :loading="busy === 'vit'" @click="saveVitals">{{ s('Record') }}</a-button>
            </div>
            <div class="h">{{ s('Report a change') }}</div>
            <a-textarea v-model:value="change" :rows="3" :placeholder="s('What you saw: e.g. fever 38.5 at 14:00, did not eat lunch, wound with discharge...')" />
            <a-button type="primary" style="margin-top: 6px" :loading="busy === 'chg'" @click="saveChange">{{ s('Save the change') }}</a-button>
          </template>
          <a-alert v-else type="info" show-icon class="mt" :message="s('Patient in the emergency room: notes and vital signs are recorded in Emergency.')" />
        </a-tab-pane>

        <!-- ============ Doctor ============ -->
        <a-tab-pane v-if="canPrescribe" key="doctor" :tab="s('Doctor')">
          <template v-if="p.admission_id">
            <div class="h">{{ s('Progress of the visit') }}</div>
            <div class="soap">
              <a-textarea v-model:value="note.subjective" :rows="2" :placeholder="s('S — what the patient says')" />
              <a-textarea v-model:value="note.objective" :rows="2" :placeholder="s('O — exam, findings')" />
              <a-textarea v-model:value="note.assessment" :rows="2" :placeholder="s('A — assessment')" />
              <a-textarea v-model:value="note.plan" :rows="2" :placeholder="s('P — plan')" />
            </div>
            <a-button type="primary" style="margin-top: 6px" :loading="busy === 'note'" @click="saveNote">{{ s('Save progress') }}</a-button>
          </template>

          <div class="h">{{ s('Prescription') }}</div>
          <template v-if="p.treatment_id">
            <div class="rx">
              <a-input v-model:value="rx.medicine" :placeholder="s('Medicine')" style="flex: 2; min-width: 180px" />
              <a-input v-model:value="rx.dose" :placeholder="s('Dose, e.g. 500 mg')" style="width: 140px" />
              <a-select v-model:value="rx.route" :options="routeOptions" style="width: 120px" />
              <a-select v-model:value="rx.every_hours" :options="[1, 2, 3, 4, 6, 8, 12, 24].map(h => ({ value: h, label: s('every {n} h', { n: h }) }))" style="width: 120px" />
              <a-input-number v-model:value="rx.days" :min="1" :max="60" :addon-after="s('days')" style="width: 120px" />
            </div>
            <a-input v-model:value="rx.instructions" :placeholder="s('Instructions (optional)')" style="margin-top: 6px" />
            <a-button type="primary" style="margin-top: 6px" :loading="busy === 'rx'" @click="saveRx">{{ s('Prescribe') }}</a-button>
            <div class="muted small">{{ s('The doses go to the medication sheet starting at the next full hour; pharmacy dispatches them.') }}</div>
          </template>
          <a-alert v-else type="info" show-icon :message="s('This patient has no active treatment: start one in Treatments to prescribe.')" />
        </a-tab-pane>
      </a-tabs>

      <div class="foot">
        <a-button v-if="p.assignment_id" :type="p.visited_at ? 'default' : 'primary'" ghost :loading="busy === 'vis'" @click="toggleVisited">
          {{ p.visited_at ? s('Visited ✓ (undo)') : s('Mark as visited') }}
        </a-button>
        <a-button v-if="p.admission_id" @click="$emit('chart', p.admission_id)">{{ s('Full chart') }}</a-button>
      </div>
    </template>
  </a-modal>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/Rounds.es.js';

const props = defineProps({ open: Boolean, patient: { type: Object, default: null }, mode: { type: String, default: 'nurse' }, canPrescribe: Boolean, routes: { type: Array, default: () => [] } });
const emit = defineEmits(['close', 'changed', 'chart']);
const { s } = useHospitalText(ES);

const p = computed(() => props.patient);
const tab = ref('today');
const busy = ref(null);
const title = computed(() => (p.value ? `${p.value.route_no}. ${p.value.name}${p.value.age != null ? ` · ${p.value.age} ${s('y')}` : ''}` : ''));
const DOSE = { given: 'Given', omitted: 'Omitted', refused: 'Refused', missed: 'Not given (not recorded)', pending: 'Pending' };
const FLAG = { normal: 'Normal', high: 'High', low: 'Low', critical: 'Critical' };
const ROUTE = { oral: 'Oral', iv: 'IV', im: 'IM', sc: 'SC', topical: 'Topical', inhaled: 'Inhaled', sublingual: 'Sublingual', rectal: 'Rectal', other: 'Other' };
// Fixed clinical order (most used first), not alphabetical.
const routeOptions = computed(() => props.routes.map(r => ({ value: r, label: s(ROUTE[r] || r) })));
const labCols = computed(() => [
  { title: s('When'), key: 'when', width: 110 }, { title: s('Test'), dataIndex: 'test_name' }, { title: s('Result'), dataIndex: 'result_value' },
  { title: s('Unit'), dataIndex: 'unit', width: 80 }, { title: s('Normal range'), dataIndex: 'normal_range' }, { title: '', key: 'flag', width: 90 },
]);
const hm = v => (v ? dayjs(v).format('HH:mm') : '');
const dt = v => (v ? dayjs(v).format('DD/MM HH:mm') : '');
const err = e => message.error(s(e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || 'Could not save'));
function vitalsLine(v) {
  return [v.temperature ? `T ${v.temperature}°C` : null, v.pulse ? `${s('Pulse')} ${v.pulse}` : null, v.bp_systolic ? `PA ${v.bp_systolic}/${v.bp_diastolic || ''}` : null,
    v.respiratory_rate ? `FR ${v.respiratory_rate}` : null, v.spo2 ? `SpO₂ ${v.spo2}%` : null, v.pain != null ? `${s('Pain')} ${v.pain}/10` : null].filter(Boolean).join(' · ');
}
const doseCls = d => (d.status === 'given' ? 'ok' : ['omitted', 'refused', 'missed'].includes(d.status) ? 'off' : d.late ? 'late' : '');

// Today's timeline: doses + care orders due + notes, split at "now".
const events = computed(() => {
  if (!p.value) return [];
  const list = p.value.doses.map(d => ({
    key: 'd' + d.id, at: d.status === 'given' ? d.given_at : d.scheduled_at, time: hm(d.status === 'given' ? d.given_at : d.scheduled_at),
    text: `💊 ${d.medicine} ${d.dose}${d.status !== 'pending' ? ' — ' + s(DOSE[d.status]) : (d.late ? ' — ' + s('late') : '')}`,
    cls: doseCls(d), done: d.status !== 'pending',
  }));
  p.value.notes_today.forEach((n, i) => list.push({ key: 'n' + i, at: n.noted_at, time: hm(n.noted_at), done: true, cls: 'ok',
    text: `${n.kind === 'nursing' ? '📝 ' + s('Nursing') : '🩺 ' + s('Doctor')}: ${n.assessment || n.subjective || n.objective || n.plan || ''}` }));
  p.value.care_due.forEach(o => list.push({ key: 'c' + o.id, at: o.last || dayjs().format(), time: o.last ? hm(o.last) : '—', done: !o.due && !!o.last,
    cls: o.due ? 'due' : 'ok', text: `🛏 ${o.description}${o.due ? ' — ' + s('due') : ''}` }));
  return list.sort((a, b) => String(a.at).localeCompare(String(b.at)));
});
const past = computed(() => events.value.filter(e => e.done));
const upcoming = computed(() => events.value.filter(e => !e.done));

const vit = reactive({});
const change = ref('');
const note = reactive({ subjective: '', objective: '', assessment: '', plan: '' });
const blankRx = () => ({ medicine: '', dose: '', route: 'oral', every_hours: 8, days: 3, instructions: '' });
const rx = reactive(blankRx());
watch(() => [props.open, p.value?.patient_id], ([o], old) => {
  if (!o) return;
  if (!old || old[1] !== p.value?.patient_id || !old[0]) {
    tab.value = props.mode === 'doctor' && props.canPrescribe ? 'doctor' : 'nursing';
    Object.keys(vit).forEach(k => { vit[k] = null; });
    change.value = '';
    Object.assign(note, { subjective: '', objective: '', assessment: '', plan: '' });
    Object.assign(rx, blankRx());
  }
}, { immediate: true });

async function run(key, fn, ok) {
  busy.value = key;
  try { await fn(); if (ok) message.success(s(ok)); emit('changed'); return true; } catch (e) { err(e); return false; } finally { busy.value = null; }
}
const dose = (d, status) => run('d' + d.id, () => http.post(`hospital/doses/${d.id}`, { status }), 'Recorded');
const careDone = o => run('c' + o.id, () => http.post(`hospital/care-orders/${o.id}/done`), 'Recorded');
async function saveVitals() {
  if (await run('vit', () => http.post(`hospital/admissions/${p.value.admission_id}/vitals`, { ...vit }), 'Recorded')) Object.keys(vit).forEach(k => { vit[k] = null; });
}
async function saveChange() {
  if (!change.value.trim()) return message.warning(s('Write what changed.'));
  if (await run('chg', () => http.post(`hospital/admissions/${p.value.admission_id}/nursing-note`, { text: change.value.trim() }), 'Saved')) change.value = '';
}
async function saveNote() {
  if (await run('note', () => http.post(`hospital/admissions/${p.value.admission_id}/notes`, { ...note }), 'Saved')) Object.assign(note, { subjective: '', objective: '', assessment: '', plan: '' });
}
async function saveRx() {
  if (!rx.medicine.trim() || !rx.dose.trim()) return message.warning(s('Type the medicine and the dose.'));
  const starts = dayjs().add(1, 'hour').minute(0).second(0).format('YYYY-MM-DD HH:mm:ss');
  if (await run('rx', () => http.post(`hospital/treatments/${p.value.treatment_id}/orders`, { ...rx, starts_at: starts }), 'Prescribed')) Object.assign(rx, blankRx());
}
const toggleVisited = () => run('vis', () => http.post(`hospital/my-patients/${p.value.assignment_id}/visited`));
</script>

<style scoped>
.hdr { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
.muted { opacity: 0.65; font-size: 12.5px; }
.small { font-size: 12px; margin-top: 4px; }
.mb { margin-bottom: 8px; }
.mt { margin-top: 10px; }
.h { font-weight: 700; margin: 12px 0 6px; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 640px) { .cols { grid-template-columns: 1fr; } }
.ev { font-size: 13px; padding: 4px 6px; border-radius: 6px; margin-bottom: 3px; background: rgba(100, 116, 139, 0.07); }
.ev .t, .row .t { font-weight: 700; margin-right: 6px; min-width: 44px; display: inline-block; }
.ev.ok { background: rgba(22, 163, 74, 0.08); }
.ev.late { background: rgba(220, 38, 38, 0.1); color: #b91c1c; }
.ev.off { background: rgba(100, 116, 139, 0.12); color: #475569; }
.ev.due { background: rgba(37, 99, 235, 0.08); color: #1d4ed8; }
.row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 6px 8px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 8px; margin-bottom: 5px; }
.row .what { flex: 1; min-width: 180px; }
.row.ok { opacity: 0.7; }
.row.late { border-color: #dc2626; background: rgba(220, 38, 38, 0.05); }
.row.off { opacity: 0.55; }
.row.due { border-color: #1677ff; background: rgba(22, 119, 255, 0.05); }
.vit-form { display: flex; flex-wrap: wrap; gap: 6px; }
.vit-form .ant-input-number { width: 96px; }
.soap { display: grid; gap: 6px; }
.rx { display: flex; flex-wrap: wrap; gap: 6px; }
.foot { display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px; border-top: 1px solid rgba(128, 128, 128, 0.18); padding-top: 10px; }
</style>
