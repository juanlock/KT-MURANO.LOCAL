<template>
  <!--
    The chart of a stay (user's analysis, 2026-10-25, phase 3): Kardex (doses
    confirmed by scanning the wristband, care / diet orders due, vital signs),
    Progress (daily SOAP), Orders, Interconsultations, Lab, Discharge (checklist
    24-48 h before, epicrisis, "Empty bed").
  -->
  <a-drawer :open="open" :width="920" :title="title" @close="$emit('close')">
    <a-spin :spinning="loading">
      <template v-if="c">
        <div class="head">
          <div>
            <b class="pn">{{ a.patient_name }}</b>
            <span class="muted" style="margin-left: 8px">{{ a.mrn }} · {{ a.ward }}{{ a.bed ? ' / ' + a.bed : '' }} · {{ s('day {n}', { n: a.nights }) }}<template v-if="a.doctor"> · {{ a.doctor }}</template></span>
            <div class="muted">{{ a.diagnosis || a.reason || '' }}</div>
          </div>
          <a-tag v-if="a.blood_group" color="red">{{ a.blood_group }}</a-tag>
        </div>
        <a-alert v-if="a.allergies" type="error" show-icon :message="s('Allergies') + ': ' + a.allergies" style="margin-bottom: 10px" />
        <a-alert v-if="a.status !== 'admitted' && !a.left_at" type="warning" show-icon :message="s('Discharged — the patient is still in the room.')" style="margin-bottom: 10px">
          <template #action><a-button size="small" type="primary" danger @click="emptyBed">{{ s('Empty bed') }}</a-button></template>
        </a-alert>

        <!-- What the family sees today in the patient portal (no clinical data) -->
        <div v-if="a.status === 'admitted'" class="family">
          <span class="fam-l"><TeamOutlined /> {{ s('For the family (portal)') }}</span>
          <a-select v-model:value="fam.status" :options="portalOptions" allow-clear :placeholder="s('Status of the day')" style="width: 200px" />
          <a-input v-model:value="fam.note" :maxlength="255" :placeholder="s('A short message, e.g. Ate well and walked')" style="flex: 1; min-width: 200px" />
          <a-button :loading="saving" @click="saveFamily">{{ s('Publish') }}</a-button>
          <span v-if="c.portal?.at" class="muted">{{ s('updated {t}', { t: dt(c.portal.at) }) }}</span>
        </div>

        <a-tabs v-model:activeKey="tab">
          <!-- ============ Kardex ============ -->
          <a-tab-pane key="kardex" :tab="s('Kardex')">
            <div class="h">{{ s('Medication (24 h before and after)') }}</div>
            <a-empty v-if="!c.doses.length" :image="simple" :description="s('No medication scheduled.')" />
            <div v-for="d in c.doses" :key="d.id" class="row" :class="['st-' + d.status, { late: d.status === 'pending' && isPast(d.scheduled_at) }]">
              <span class="time">{{ hm(d.scheduled_at) }}</span>
              <span class="what">{{ d.medicine }} · {{ d.dose }} · {{ d.route }}</span>
              <a-tag v-if="!d.dispensed && d.status === 'pending'" color="orange">{{ s('Not dispensed') }}</a-tag>
              <!-- Only today's doses are recorded; past days' unrecorded ones are "not given". -->
              <template v-if="d.status === 'pending' && d.today">
                <a-button size="small" type="primary" @click="openGive(d)"><ScanOutlined /> {{ s('Give') }}</a-button>
                <a-button size="small" @click="dose(d, 'omitted')">{{ s('Omitted') }}</a-button>
                <a-button size="small" @click="dose(d, 'refused')">{{ s('Refused') }}</a-button>
              </template>
              <span v-else-if="d.status === 'pending'" class="muted">{{ s('Pending · another day') }}</span>
              <a-tag v-else-if="d.status === 'missed'" color="volcano">{{ s('Not given (not recorded)') }}</a-tag>
              <span v-else class="muted">{{ s(DOSE_LABEL[d.status] || d.status) }} · {{ hm(d.given_at) }}<template v-if="d.by"> · {{ d.by }}</template></span>
            </div>

            <div class="h">{{ s('Care and diet') }}</div>
            <a-empty v-if="!activeOrders.length" :image="simple" :description="s('No active orders.')" />
            <div v-for="o in activeOrders" :key="o.id" class="row" :class="{ due: o.due }">
              <a-tag :color="KIND_COLOR[o.kind]">{{ s(KIND_LABEL[o.kind]) }}</a-tag>
              <span class="what">{{ o.description }}<template v-if="o.every_hours"> · {{ s('every {n} h', { n: o.every_hours }) }}</template></span>
              <span class="muted">{{ o.last_done ? s('last {t}', { t: dt(o.last_done) }) + (o.last_by ? ' · ' + o.last_by : '') : s('not done yet') }}</span>
              <a-button size="small" :type="o.due ? 'primary' : 'default'" @click="careDone(o)">{{ s('Done') }}</a-button>
            </div>

            <div class="h">{{ s('Vital signs') }}</div>
            <div class="vit-form">
              <a-input-number v-model:value="vit.temperature" :placeholder="'T °C'" :min="25" :max="45" :step="0.1" />
              <a-input-number v-model:value="vit.pulse" :placeholder="s('Pulse')" :min="0" :max="300" />
              <a-input-number v-model:value="vit.bp_systolic" :placeholder="s('BP sys.')" :min="0" :max="300" />
              <a-input-number v-model:value="vit.bp_diastolic" :placeholder="s('BP dia.')" :min="0" :max="250" />
              <a-input-number v-model:value="vit.respiratory_rate" :placeholder="s('Resp.')" :min="0" :max="80" />
              <a-input-number v-model:value="vit.spo2" placeholder="SpO₂" :min="0" :max="100" />
              <a-input-number v-model:value="vit.pain" :placeholder="s('Pain 0-10')" :min="0" :max="10" />
              <a-button type="primary" :loading="saving" @click="saveVitals">{{ s('Record') }}</a-button>
            </div>
            <a-table :data-source="c.vitals" :columns="vitalColumns" size="small" :pagination="{ pageSize: 8, hideOnSinglePage: true }" row-key="id" />
          </a-tab-pane>

          <!-- ============ Progress ============ -->
          <a-tab-pane key="progress" :tab="s('Progress')">
            <div class="soap">
              <a-textarea v-model:value="note.subjective" :rows="2" :placeholder="s('S — what the patient says')" />
              <a-textarea v-model:value="note.objective" :rows="2" :placeholder="s('O — exam, findings')" />
              <a-textarea v-model:value="note.assessment" :rows="2" :placeholder="s('A — assessment')" />
              <a-textarea v-model:value="note.plan" :rows="2" :placeholder="s('P — plan')" />
              <a-button type="primary" :loading="saving" @click="saveNote">{{ s('Save progress') }}</a-button>
            </div>
            <a-timeline style="margin-top: 16px">
              <a-timeline-item v-for="n in c.notes" :key="n.id">
                <div class="muted">{{ dt(n.noted_at) }} · <a-tag v-if="n.kind === 'nursing'" color="green">{{ s('Nursing') }}</a-tag>{{ n.kind === 'nursing' ? n.by : (n.doctor || n.by) }}</div>
                <div v-if="n.subjective"><b>S:</b> {{ n.subjective }}</div>
                <div v-if="n.objective"><b>O:</b> {{ n.objective }}</div>
                <div v-if="n.assessment"><b>A:</b> {{ n.assessment }}</div>
                <div v-if="n.plan"><b>P:</b> {{ n.plan }}</div>
              </a-timeline-item>
            </a-timeline>
          </a-tab-pane>

          <!-- ============ Orders ============ -->
          <a-tab-pane key="orders" :tab="s('Orders')">
            <div class="ord-form">
              <a-select v-model:value="ord.kind" :options="kindOptions" style="width: 150px" />
              <a-input v-model:value="ord.description" :placeholder="s('e.g. Soft diet, no salt / Change dressing / Walk 3 times a day')" style="flex: 1; min-width: 240px" />
              <a-input-number v-model:value="ord.every_hours" :min="1" :max="168" :placeholder="s('every h')" style="width: 110px" />
              <a-button type="primary" :loading="saving" @click="saveOrder">{{ s('Order') }}</a-button>
            </div>
            <div class="muted small">{{ s('Diet, care and activity orders go to the nursing kardex. "Every h" makes them due again after those hours.') }}</div>
            <div v-for="o in c.care_orders" :key="o.id" class="row" :class="{ off: o.status !== 'active' }">
              <a-tag :color="KIND_COLOR[o.kind]">{{ s(KIND_LABEL[o.kind]) }}</a-tag>
              <span class="what">{{ o.description }}<template v-if="o.every_hours"> · {{ s('every {n} h', { n: o.every_hours }) }}</template></span>
              <span class="muted">{{ s('done {n} times', { n: o.done_count }) }}</span>
              <a-button v-if="o.status === 'active'" size="small" danger @click="stopOrder(o)">{{ s('Stop') }}</a-button>
              <a-tag v-else>{{ s('Stopped') }}</a-tag>
            </div>
          </a-tab-pane>

          <!-- ============ Consults ============ -->
          <a-tab-pane key="consults" :tab="s('Interconsultations')">
            <div class="ord-form">
              <a-select v-model:value="con.to_doctor_id" :options="doctorOptions" allow-clear show-search option-filter-prop="label" :placeholder="s('Specialist')" style="width: 230px" />
              <a-input v-model:value="con.specialty" :placeholder="s('or the specialty')" style="width: 170px" />
              <a-select v-model:value="con.priority" :options="[{ value: 'normal', label: s('Normal') }, { value: 'urgent', label: s('Urgent') }]" style="width: 110px" />
            </div>
            <a-textarea v-model:value="con.reason" :rows="2" :placeholder="s('Reason for the interconsultation')" style="margin-top: 6px" />
            <a-button type="primary" :loading="saving" style="margin-top: 6px" @click="saveConsult">{{ s('Request') }}</a-button>
            <div v-for="x in c.consults" :key="x.id" class="consult" :class="'cs-' + x.status">
              <div><a-tag :color="x.priority === 'urgent' ? 'red' : 'blue'">{{ s(x.priority === 'urgent' ? 'Urgent' : 'Normal') }}</a-tag>
                <b>{{ x.to_doctor || x.specialty }}</b> <span class="muted">· {{ s(CONSULT_LABEL[x.status]) }} · {{ dt(x.created_at) }}</span></div>
              <div>{{ x.reason }}</div>
              <div v-if="x.answer" class="ans"><b>{{ s('Answer') }}:</b> {{ x.answer }}</div>
              <a-space v-if="x.status === 'requested' || x.status === 'accepted'" style="margin-top: 4px">
                <a-button v-if="x.status === 'requested'" size="small" @click="answer(x, 'accept')">{{ s('Accept') }}</a-button>
                <a-button size="small" type="primary" @click="openAnswer(x)">{{ s('Answer') }}</a-button>
                <a-button size="small" danger @click="answer(x, 'cancel')">{{ s('Cancel it') }}</a-button>
              </a-space>
            </div>
          </a-tab-pane>

          <!-- ============ Lab ============ -->
          <a-tab-pane key="lab" :tab="s('Lab')">
            <a-table :data-source="c.labs" :columns="labColumns" size="small" :pagination="{ pageSize: 15, hideOnSinglePage: true }" :row-key="(r, i) => i">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'flag'">
                  <a-tag v-if="record.flag" :color="record.flag === 'critical' ? 'red' : record.flag === 'normal' ? 'green' : 'orange'">{{ s(FLAG_LABEL[record.flag] || record.flag) }}</a-tag>
                  <a-button v-if="record.flag === 'critical' && !record.critical_seen_at" size="small" type="primary" danger @click="labSeen(record)">{{ s('Seen') }}</a-button>
                </template>
                <template v-else-if="column.key === 'when'">{{ dt(record.ordered_at) }}</template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ============ Discharge ============ -->
          <a-tab-pane key="discharge" :tab="s('Discharge')">
            <div class="h">{{ s('Discharge plan (24-48 h before)') }}</div>
            <a-space style="margin-bottom: 8px">
              <span>{{ s('Planned discharge') }}</span>
              <a-date-picker v-model:value="planAt" show-time format="DD/MM/YYYY HH:mm" value-format="YYYY-MM-DD HH:mm" />
            </a-space>
            <div v-for="i in c.plan.items" :key="i.key" class="chk">
              <a-checkbox v-model:checked="planDone[i.key]">{{ s(i.label) }}</a-checkbox>
              <span v-if="i.done && i.by" class="muted">{{ i.by }} · {{ dt(i.at) }}</span>
            </div>

            <div class="h">{{ s('For the patient at home (shown in the patient portal)') }}</div>
            <a-form layout="vertical">
              <a-form-item :label="s('Discharge prescriptions')">
                <a-textarea v-model:value="home.home_prescriptions" :rows="3" :placeholder="s('One medicine per line: name, dose, how often, for how many days')" />
              </a-form-item>
              <a-form-item :label="s('Home care')">
                <a-textarea v-model:value="home.home_care" :rows="3" :placeholder="s('Diet, wound care, activity, warning signs to come back')" />
              </a-form-item>
              <a-row :gutter="12">
                <a-col :xs="24" :md="14">
                  <a-form-item :label="s('Home-care videos')">
                    <a-select v-model:value="home.video_ids" mode="multiple" :options="videoOptions" :placeholder="s('Hospital -> Configuration -> Patient portal')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="10">
                  <a-form-item :label="s('Follow-up appointment')">
                    <a-date-picker v-model:value="home.follow_up_at" show-time format="DD/MM/YYYY HH:mm" value-format="YYYY-MM-DD HH:mm" style="width: 100%" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>
            <a-button type="primary" :loading="saving" style="margin-top: 6px" @click="savePlan">{{ s('Save plan') }}</a-button>

            <div class="h">{{ s('Epicrisis') }}</div>
            <a-space style="margin-bottom: 6px" wrap>
              <a-button @click="draftEpicrisis"><ThunderboltOutlined /> {{ s('Draft from the stay') }}</a-button>
              <a-button type="primary" :disabled="!epi" :loading="saving" @click="saveEpicrisis">{{ s('Save epicrisis') }}</a-button>
              <a-button :disabled="!epi" @click="printEpicrisis"><PrinterOutlined /> {{ s('Print') }}</a-button>
            </a-space>
            <a-textarea v-model:value="epi" :rows="16" class="epi" />
          </a-tab-pane>
        </a-tabs>
      </template>
    </a-spin>

    <!-- Give a dose: scan the wristband -->
    <a-modal :open="give.open" :title="s('Scan the wristband')" :ok-text="s('Given')" :cancel-text="s('Cancel')" @ok="confirmGive" @cancel="give.open = false">
      <p>{{ give.dose?.medicine }} · {{ give.dose?.dose }} — <b>{{ a.patient_name }}</b></p>
      <a-input ref="scanInput" v-model:value="give.scan" :placeholder="s('Scan the barcode of the wristband')" size="large" @press-enter="confirmGive">
        <template #prefix><ScanOutlined /></template>
      </a-input>
      <a-checkbox v-model:checked="give.noScanner" style="margin-top: 8px">{{ s('No scanner: I checked the wristband by eye') }}</a-checkbox>
    </a-modal>

    <a-modal :open="ansOpen" :title="s('Answer')" :ok-text="s('Send')" :cancel-text="s('Cancel')" @ok="sendAnswer" @cancel="ansOpen = false">
      <a-textarea v-model:value="ansText" :rows="5" />
    </a-modal>
  </a-drawer>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import dayjs from 'dayjs';
import { message, Modal, Empty } from 'ant-design-vue';
import { ScanOutlined, ThunderboltOutlined, PrinterOutlined, TeamOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/ClinicalDrawer.es.js';

const props = defineProps({ open: Boolean, admissionId: { type: Number, default: null }, startTab: { type: String, default: 'kardex' } });
const emit = defineEmits(['close', 'changed']);
const { s } = useHospitalText(ES);
const simple = Empty.PRESENTED_IMAGE_SIMPLE;

const loading = ref(false);
const saving = ref(false);
const c = ref(null);
const a = computed(() => c.value?.admission || {});
const tab = ref(props.startTab);
const title = computed(() => (c.value ? `${s('Chart of the stay')} · ${a.value.reference}` : ''));
const activeOrders = computed(() => (c.value?.care_orders || []).filter(o => o.status === 'active'));
const doctorOptions = computed(() => sortByLabel((c.value?.doctors || []).map(d => ({ value: d.id, label: d.specialty ? `${d.name} (${d.specialty})` : d.name }))));

const KIND_LABEL = { diet: 'Diet', care: 'Care', vitals: 'Vital signs', activity: 'Activity', other: 'Other' };
const KIND_COLOR = { diet: 'green', care: 'blue', vitals: 'purple', activity: 'cyan', other: 'default' };
const kindOptions = computed(() => Object.entries(KIND_LABEL).map(([v, l]) => ({ value: v, label: s(l) })));
const DOSE_LABEL = { given: 'Given', omitted: 'Omitted', refused: 'Refused' };
const CONSULT_LABEL = { requested: 'Requested', accepted: 'Accepted', answered: 'Answered', cancelled: 'Cancelled' };
const FLAG_LABEL = { normal: 'Normal', high: 'High', low: 'Low', critical: 'Critical' };
const dt = v => (v ? dayjs(v).format('DD/MM HH:mm') : '');
const hm = v => (v ? dayjs(v).format('DD/MM HH:mm') : '');
const isPast = v => dayjs(v).isBefore(dayjs());
const err = e => message.error(s(e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || 'Could not save'));
const vitalColumns = computed(() => [
  { title: s('When'), dataIndex: 'taken_at', customRender: ({ text }) => dt(text) }, { title: 'T °C', dataIndex: 'temperature' },
  { title: s('Pulse'), dataIndex: 'pulse' }, { title: s('BP'), customRender: ({ record }) => (record.bp_systolic ? `${record.bp_systolic}/${record.bp_diastolic || ''}` : '') },
  { title: s('Resp.'), dataIndex: 'respiratory_rate' }, { title: 'SpO₂', dataIndex: 'spo2' }, { title: s('Pain'), dataIndex: 'pain' }, { title: s('By'), dataIndex: 'by' },
]);
const labColumns = computed(() => [
  { title: s('When'), key: 'when', width: 110 }, { title: s('Test'), dataIndex: 'test_name' }, { title: s('Result'), dataIndex: 'result_value' },
  { title: s('Unit'), dataIndex: 'unit', width: 80 }, { title: s('Normal range'), dataIndex: 'normal_range' }, { title: '', key: 'flag', width: 100 },
]);

const PORTAL_LABEL = {
  stable: 'Stable', improving: 'Improving', observation: 'Under observation', resting: 'Resting',
  in_procedure: 'In a procedure', preparing_discharge: 'Preparing the discharge',
};
const portalOptions = computed(() => Object.entries(PORTAL_LABEL).map(([v, l]) => ({ value: v, label: s(l) })));
const videoOptions = computed(() => sortByLabel((c.value?.videos || []).map(v => ({ value: v.id, label: v.topic ? `${v.title} · ${v.topic}` : v.title }))));
const fam = reactive({ status: undefined, note: '' });
const home = reactive({ home_prescriptions: '', home_care: '', video_ids: [], follow_up_at: null });

const planAt = ref(null);
const planDone = reactive({});
const epi = ref('');
function take(data) {
  c.value = data;
  planAt.value = data.plan.planned_at ? dayjs(data.plan.planned_at).format('YYYY-MM-DD HH:mm') : null;
  data.plan.items.forEach(i => { planDone[i.key] = !!i.done; });
  Object.assign(fam, { status: data.portal?.status || undefined, note: data.portal?.note || '' });
  Object.assign(home, {
    home_prescriptions: data.plan.home_prescriptions || '', home_care: data.plan.home_care || '', video_ids: data.plan.video_ids || [],
    follow_up_at: data.plan.follow_up_at ? dayjs(data.plan.follow_up_at).format('YYYY-MM-DD HH:mm') : null,
  });
  if (epi.value === '' && data.admission.discharge_summary) epi.value = data.admission.discharge_summary;
}
async function load() {
  if (!props.admissionId) return;
  loading.value = true;
  epi.value = '';
  try { take(await http.get(`hospital/admissions/${props.admissionId}/chart`)); } catch (e) { err(e); } finally { loading.value = false; }
}
watch(() => [props.open, props.admissionId], ([o]) => { if (o) { tab.value = props.startTab; load(); } }, { immediate: true });
async function post(url, body, ok) {
  saving.value = true;
  try { take(await http.post(url, body)); if (ok) message.success(s(ok)); emit('changed'); return true; } catch (e) { err(e); return false; } finally { saving.value = false; }
}

// kardex
const give = reactive({ open: false, dose: null, scan: '', noScanner: false });
const scanInput = ref(null);
function openGive(d) { Object.assign(give, { open: true, dose: d, scan: '', noScanner: false }); nextTick(() => scanInput.value?.focus()); }
async function confirmGive() {
  if (!give.scan.trim() && !give.noScanner) return message.warning(s('Scan the wristband, or tick that you checked it by eye.'));
  try {
    await http.post(`hospital/doses/${give.dose.id}`, { status: 'given', scan: give.noScanner ? undefined : give.scan.trim() });
    give.open = false;
    message.success(s('Dose recorded'));
    load();
  } catch (e) { err(e); }
}
async function dose(d, status) {
  try { await http.post(`hospital/doses/${d.id}`, { status }); load(); } catch (e) { err(e); }
}
const careDone = o => post(`hospital/care-orders/${o.id}/done`, {}, 'Recorded');
const vit = reactive({});
async function saveVitals() {
  if (await post(`hospital/admissions/${props.admissionId}/vitals`, { ...vit }, 'Recorded')) Object.keys(vit).forEach(k => { vit[k] = null; });
}
// progress
const note = reactive({ subjective: '', objective: '', assessment: '', plan: '' });
async function saveNote() {
  if (await post(`hospital/admissions/${props.admissionId}/notes`, { ...note }, 'Saved')) Object.assign(note, { subjective: '', objective: '', assessment: '', plan: '' });
}
// orders
const ord = reactive({ kind: 'diet', description: '', every_hours: null });
async function saveOrder() {
  if (!ord.description.trim()) return message.warning(s('Write the order.'));
  if (await post(`hospital/admissions/${props.admissionId}/care-orders`, { ...ord }, 'Saved')) Object.assign(ord, { description: '', every_hours: null });
}
const stopOrder = o => post(`hospital/care-orders/${o.id}/stop`, {}, 'Stopped');
// consults
const con = reactive({ to_doctor_id: undefined, specialty: '', priority: 'normal', reason: '' });
async function saveConsult() {
  if (!con.reason.trim()) return message.warning(s('Write the reason.'));
  if (await post(`hospital/admissions/${props.admissionId}/consults`, { ...con, to_doctor_id: con.to_doctor_id || null }, 'Interconsultation requested')) Object.assign(con, { to_doctor_id: undefined, specialty: '', priority: 'normal', reason: '' });
}
const answer = (x, action) => post(`hospital/consults/${x.id}/answer`, { action });
const ansOpen = ref(false);
const ansText = ref('');
const ansTarget = ref(null);
function openAnswer(x) { ansTarget.value = x; ansText.value = ''; ansOpen.value = true; }
async function sendAnswer() {
  if (!ansText.value.trim()) return;
  if (await post(`hospital/consults/${ansTarget.value.id}/answer`, { action: 'answer', answer: ansText.value.trim() }, 'Saved')) ansOpen.value = false;
}
// discharge
async function savePlan() {
  if (!planAt.value && a.value.status === 'admitted') return message.warning(s('Pick the planned discharge time.'));
  saving.value = true;
  try {
    take(await http.put(`hospital/admissions/${props.admissionId}/discharge-plan`, { planned_at: planAt.value, items: { ...planDone }, ...home }));
    message.success(s('Saved'));
    emit('changed');
  } catch (e) { err(e); } finally { saving.value = false; }
}
// family status in the portal
async function saveFamily() {
  saving.value = true;
  try {
    take(await http.put(`hospital/admissions/${props.admissionId}/portal-status`, { portal_status: fam.status || null, portal_note: fam.note || null }));
    message.success(s('Published in the patient portal'));
  } catch (e) { err(e); } finally { saving.value = false; }
}
// lab: critical value acknowledged
async function labSeen(r) {
  try { await http.post(`hospital/lab-orders/${r.order_id}/critical-seen`, { item_id: r.id }); load(); } catch (e) { err(e); }
}
async function draftEpicrisis() {
  try { epi.value = (await http.get(`hospital/admissions/${props.admissionId}/epicrisis`)).text; } catch (e) { err(e); }
}
async function saveEpicrisis() {
  saving.value = true;
  try { await http.put(`hospital/admissions/${props.admissionId}/epicrisis`, { text: epi.value }); message.success(s('Saved')); } catch (e) { err(e); } finally { saving.value = false; }
}
function printEpicrisis() {
  const w = window.open('', '_blank', 'width=800,height=900');
  if (!w) return message.warning(s('Allow pop-up windows to print.'));
  const esc = v => String(v ?? '').replace(/[&<>]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(s('Epicrisis'))} ${esc(a.value.reference)}</title>
    <style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:24mm 18mm;color:#0f172a;font-size:11.5pt;line-height:1.5}pre{white-space:pre-wrap;font-family:inherit}
    .sig{margin-top:30mm;display:flex;justify-content:flex-end}.sig div{border-top:1px solid #334155;padding-top:4px;min-width:70mm;text-align:center}</style></head>
    <body><pre>${esc(epi.value)}</pre><div class="sig"><div>${esc(a.value.doctor || s('Attending doctor'))}</div></div>
    <script>window.onload=function(){setTimeout(function(){window.print()},200)}<\/script></body></html>`);
  w.document.close();
}
function emptyBed() {
  Modal.confirm({
    title: s('The patient left the room?'), content: s('The bed goes to cleaning; the bed manager and the cleaning staff see it on the bed board.'),
    okText: s('Empty bed'), cancelText: s('Cancel'),
    async onOk() {
      try { await http.post(`hospital/admissions/${props.admissionId}/left`); message.success(s('Bed sent to cleaning')); load(); emit('changed'); } catch (e) { err(e); }
    },
  });
}
</script>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.pn { font-size: 17px; }
.muted { opacity: 0.65; font-size: 12.5px; }
.small { font-size: 12px; margin: 4px 0 10px; }
.h { font-weight: 700; margin: 14px 0 8px; }
.row { display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 8px; border: 1px solid rgba(128, 128, 128, 0.18); margin-bottom: 6px; flex-wrap: wrap; }
.row .what { flex: 1; min-width: 180px; }
.row .time { font-weight: 700; min-width: 86px; }
.row.late { border-color: #dc2626; background: rgba(220, 38, 38, 0.05); }
.row.due { border-color: #1677ff; background: rgba(22, 119, 255, 0.05); }
.row.st-given { opacity: 0.6; }
.row.off { opacity: 0.5; }
.vit-form { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.vit-form .ant-input-number { width: 92px; }
.soap { display: grid; gap: 6px; }
.ord-form { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.consult { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 8px 10px; margin-top: 8px; }
.consult.cs-answered { border-color: rgba(22, 163, 74, 0.5); }
.consult .ans { margin-top: 4px; background: rgba(22, 163, 74, 0.06); padding: 6px; border-radius: 6px; }
.chk { display: flex; align-items: center; gap: 10px; padding: 3px 0; }
.family { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 10px; border-radius: 10px; background: rgba(22, 119, 255, 0.06); margin-bottom: 8px; }
.fam-l { font-weight: 600; }
.epi { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }
</style>
