<template>
  <div class="page">
    <PageHeader :title="s('Operating room')" :breadcrumb="[s('Hospital'), s('Operating room')]">
      <template #actions>
        <a-button type="primary" @click="openForm(null)"><template #icon><PlusOutlined /></template>{{ s('Schedule surgery') }}</a-button>
      </template>
    </PageHeader>

    <div class="rooms">
      <span class="r-title">{{ s('Operating rooms') }}:</span>
      <a-tag v-for="r in data.rooms" :key="r.id" :color="ROOM_COLOR[r.status]" class="room" @click="openRoom(r)">{{ r.name }} · {{ s(ROOM_LABEL[r.status]) }}</a-tag>
      <a-button size="small" type="dashed" @click="openRoom(null)"><PlusOutlined /> {{ s('Room') }}</a-button>
    </div>

    <a-card size="small" style="margin-bottom: 12px">
      <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="load" />
    </a-card>

    <a-spin :spinning="loading">
      <div class="board">
        <div v-for="col in COLUMNS" :key="col" class="col">
          <div class="col-head">{{ s(STATUS_LABEL[col]) }} <a-badge :count="byStatus(col).length" show-zero :number-style="{ backgroundColor: STATUS_COLOR[col] }" /></div>
          <div v-for="x in byStatus(col)" :key="x.id" class="card" :style="{ borderLeftColor: PRIORITY_COLOR[x.priority] }" @click="openDetail(x.id)">
            <div class="c-top"><b>{{ x.patient_name }}</b> <span class="muted">{{ x.reference }}</span></div>
            <div>{{ x.planned_procedure || '—' }}</div>
            <div class="muted">{{ dt(x.scheduled_at) }} · {{ x.room || s('No room') }} · {{ x.surgeon || s('No surgeon') }}</div>
            <a-tag v-if="x.priority !== 'elective'" :color="PRIORITY_COLOR[x.priority]">{{ s(PRIORITY_LABEL[x.priority]) }}</a-tag>
            <a-tag v-if="!x.consent_signed && ['scheduled', 'pre_op'].includes(x.status)" color="orange">{{ s('Consent pending') }}</a-tag>
          </div>
        </div>
      </div>
    </a-spin>

    <!-- Schedule / edit -->
    <a-drawer v-model:open="formOpen" :width="drawerWidth" :title="editing ? `${editing.reference} · ${editing.patient_name}` : s('Schedule surgery')" destroy-on-close>
      <template v-if="editing">
        <div class="moves">
          <a-tag :color="STATUS_COLOR[editing.status]">{{ s(STATUS_LABEL[editing.status]) }}</a-tag>
          <a-button v-for="n in editing.next" :key="n" :type="n === 'cancelled' ? 'default' : 'primary'" :danger="n === 'cancelled'" size="small" @click="move(n)">
            {{ s(MOVE_LABEL[n]) }}
          </a-button>
          <a-button v-if="editing.status === 'completed' && !editing.invoice_id" size="small" @click="bill">{{ s('Bill surgery') }}</a-button>
          <PermLink v-if="editing.invoice_id" to="/hospital/invoices" permission="hms_billing">{{ s('Billed') }}</PermLink>
          <PermLink v-if="editing.treatment_id" :to="`/hospital/treatments?id=${editing.treatment_id}`" :permission="['hms_treatments', 'hms_treatments_administer']">{{ s('Post-op treatment') }}</PermLink>
        </div>
        <div v-if="editing.allergies" class="allergy"><WarningOutlined /> {{ s('Allergies') }}: {{ editing.allergies }}</div>
      </template>
      <a-tabs v-model:activeKey="ftab">
        <a-tab-pane key="pre" :tab="s('Pre-op')">
          <a-form layout="vertical">
            <a-form-item v-if="!editing" :label="s('Patient')" required>
              <a-select v-model:value="f.patient_id" show-search option-filter-prop="label" :options="censusOptions" :placeholder="s('Patients in the hospital')" />
            </a-form-item>
            <a-row :gutter="8">
              <a-col :xs="24" :md="12"><a-form-item :label="s('Date and time')" required><a-date-picker v-model:value="f.scheduled_at" show-time format="DD/MM/YYYY HH:mm" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('Minutes')"><a-input-number v-model:value="f.estimated_minutes" :min="5" :max="1440" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :md="6"><a-form-item :label="s('Priority')"><a-select v-model:value="f.priority" :options="priorityOptions" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item :label="s('Operating room')"><a-select v-model:value="f.operating_room_id" allow-clear :options="roomOptions" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item :label="s('Surgeon')"><a-select v-model:value="f.surgeon_id" allow-clear show-search option-filter-prop="label" :options="doctorOptions" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item :label="s('Anesthetist')"><a-select v-model:value="f.anesthetist_id" allow-clear show-search option-filter-prop="label" :options="doctorOptions" /></a-form-item></a-col>
            </a-row>
            <a-form-item :label="s('Team (assistants, instrument nurse...)')"><a-input v-model:value="f.team" /></a-form-item>
            <a-form-item :label="s('Pre-op diagnosis')"><a-textarea v-model:value="f.pre_op_diagnosis" :rows="2" /></a-form-item>
            <a-form-item :label="s('Planned procedure')"><a-textarea v-model:value="f.planned_procedure" :rows="2" /></a-form-item>
            <a-form-item :label="s('Risks')"><a-textarea v-model:value="f.risks" :rows="2" /></a-form-item>
            <a-row :gutter="8">
              <a-col :span="12"><a-checkbox v-model:checked="f.consent_signed">{{ s('Consent signed') }}</a-checkbox></a-col>
              <a-col :span="12"><a-form-item :label="s('Fee')"><a-input-number v-model:value="f.fee" :min="0" style="width: 100%" /></a-form-item></a-col>
            </a-row>
          </a-form>
        </a-tab-pane>
        <a-tab-pane v-if="editing" key="surgery" :tab="s('Surgery')">
          <a-form layout="vertical">
            <div class="muted" style="margin-bottom: 8px">{{ s('Start') }}: {{ dt(editing.started_at) }} · {{ s('End') }}: {{ dt(editing.ended_at) }}</div>
            <a-form-item :label="s('Procedure performed')"><a-textarea v-model:value="f.procedure_performed" :rows="3" /></a-form-item>
            <a-form-item :label="s('Anesthesia')"><a-select v-model:value="f.anesthesia" allow-clear :options="anesthesiaOptions" /></a-form-item>
            <a-form-item :label="s('Findings')"><a-textarea v-model:value="f.findings" :rows="3" /></a-form-item>
            <a-form-item :label="s('Complications')"><a-textarea v-model:value="f.complications" :rows="2" /></a-form-item>
          </a-form>
        </a-tab-pane>
        <a-tab-pane v-if="editing" key="post" :tab="s('Post-op')">
          <a-form layout="vertical">
            <a-form-item :label="s('Post-op diagnosis')"><a-textarea v-model:value="f.post_op_diagnosis" :rows="2" /></a-form-item>
            <a-form-item :label="s('Post-op instructions (they go to the treatment)')"><a-textarea v-model:value="f.post_op_instructions" :rows="5" /></a-form-item>
          </a-form>
        </a-tab-pane>
        <a-tab-pane v-if="editing" key="log" :tab="s('Patient movements')">
          <a-timeline>
            <a-timeline-item v-for="(e, i) in editing.events" :key="i" :color="STATUS_COLOR[e.event]">
              <b>{{ s(STATUS_LABEL[e.event] || e.event) }}</b> · {{ dt(e.at) }}<template v-if="e.by"> · {{ e.by }}</template>
              <div v-if="e.notes" class="muted">{{ e.notes }}</div>
            </a-timeline-item>
          </a-timeline>
        </a-tab-pane>
      </a-tabs>
      <template #footer>
        <a-space style="float: right">
          <a-popconfirm v-if="editing && ['scheduled', 'cancelled'].includes(editing.status)" :title="s('Delete this surgery?')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="remove">
            <a-button danger>{{ s('Delete') }}</a-button>
          </a-popconfirm>
          <a-button @click="formOpen = false">{{ s('Close') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save">{{ s('Save') }}</a-button>
        </a-space>
      </template>
    </a-drawer>

    <a-modal v-model:open="roomOpen" :title="room.id ? s('Operating room') : s('New operating room')" :ok-text="s('Save')" :cancel-text="s('Cancel')" @ok="saveRoom">
      <a-form layout="vertical">
        <a-form-item :label="s('Name')"><a-input v-model:value="room.name" /></a-form-item>
        <a-form-item :label="s('Status')"><a-select v-model:value="room.status" :options="Object.keys(ROOM_LABEL).map(k => ({ value: k, label: s(ROOM_LABEL[k]) }))" /></a-form-item>
        <a-form-item :label="s('Notes')"><a-input v-model:value="room.notes" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Operating room (2026-10-21): rooms and surgeries on a board by status
 * (scheduled -> pre-op -> in surgery -> recovery -> completed). Each surgery:
 * pre-op, the surgery itself, post-op (opens / updates the patient's
 * treatment) and the log of the patient's moves; billable as a sale.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { PlusOutlined, WarningOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/Surgery.es.js';

const { s } = useHospitalText(ES);
const COLUMNS = ['scheduled', 'pre_op', 'in_surgery', 'recovery', 'completed'];
const STATUS_LABEL = { scheduled: 'Scheduled', pre_op: 'Pre-op', in_surgery: 'In surgery', recovery: 'Recovery', completed: 'Completed', cancelled: 'Cancelled' };
const STATUS_COLOR = { scheduled: '#64748b', pre_op: '#ca8a04', in_surgery: '#dc2626', recovery: '#7c3aed', completed: '#16a34a', cancelled: '#9ca3af' };
const MOVE_LABEL = { pre_op: 'To pre-op', in_surgery: 'Into the operating room', recovery: 'To recovery', completed: 'Back to the bed (finish)', cancelled: 'Cancel', scheduled: 'Back to scheduled' };
const PRIORITY_LABEL = { elective: 'Elective', urgent: 'Urgent', emergency: 'Emergency' };
const PRIORITY_COLOR = { elective: '#64748b', urgent: '#ea580c', emergency: '#dc2626' };
const ROOM_LABEL = { available: 'Available', in_use: 'In use', cleaning: 'Cleaning', maintenance: 'Maintenance' };
const ROOM_COLOR = { available: 'success', in_use: 'error', cleaning: 'warning', maintenance: 'default' };
const ANESTHESIA = ['general', 'regional', 'spinal', 'epidural', 'local', 'sedation'];
const ANESTHESIA_LABEL = { general: 'General', regional: 'Regional', spinal: 'Spinal', epidural: 'Epidural', local: 'Local', sedation: 'Sedation' };

const loading = ref(false);
const saving = ref(false);
const range = ref([dayjs().subtract(1, 'day').format('YYYY-MM-DD'), dayjs().add(7, 'day').format('YYYY-MM-DD')]);
const data = ref({ surgeries: [], rooms: [], doctors: [], census: [] });
const dt = iso => (iso ? dayjs(iso).format('DD/MM HH:mm') : '—');
const drawerWidth = computed(() => (window.innerWidth < 900 ? '100%' : 760));
const byStatus = st => data.value.surgeries.filter(x => x.status === st);
const doctorOptions = computed(() => sortByLabel(data.value.doctors.map(d => ({ value: d.id, label: d.specialty ? `${d.name} (${d.specialty})` : d.name }))));
const roomOptions = computed(() => sortByLabel(data.value.rooms.map(r => ({ value: r.id, label: r.name }))));
const censusOptions = computed(() => sortByLabel(data.value.census.map(c => ({ value: c.patient_id, label: `${c.name} · ${c.mrn}${c.where ? ' · ' + c.where : ''}` }))));
const priorityOptions = computed(() => Object.keys(PRIORITY_LABEL).map(k => ({ value: k, label: s(PRIORITY_LABEL[k]) })));
const anesthesiaOptions = computed(() => ANESTHESIA.map(k => ({ value: k, label: s(ANESTHESIA_LABEL[k]) })));

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hospital/surgeries', { start_date: range.value?.[0] || '', end_date: range.value?.[1] || '' });
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  } finally {
    loading.value = false;
  }
}

const formOpen = ref(false);
const ftab = ref('pre');
const editing = ref(null);
function emptyForm() {
  return { patient_id: undefined, scheduled_at: dayjs().add(1, 'day').hour(8).minute(0).second(0).format('YYYY-MM-DD HH:mm:ss'), estimated_minutes: 90,
    priority: 'elective', operating_room_id: undefined, surgeon_id: undefined, anesthetist_id: undefined, team: '', pre_op_diagnosis: '',
    planned_procedure: '', risks: '', consent_signed: false, fee: 0, procedure_performed: '', anesthesia: undefined, findings: '',
    complications: '', post_op_diagnosis: '', post_op_instructions: '' };
}
const f = reactive(emptyForm());
function openForm() {
  editing.value = null;
  Object.assign(f, emptyForm());
  ftab.value = 'pre';
  formOpen.value = true;
}
async function openDetail(id, keepTab = false) {
  try {
    const d = await http.get(`hospital/surgeries/${id}`);
    editing.value = d.surgery;
    const x = d.surgery;
    Object.assign(f, emptyForm(), {
      scheduled_at: dayjs(x.scheduled_at).format('YYYY-MM-DD HH:mm:ss'), estimated_minutes: x.estimated_minutes, priority: x.priority,
      operating_room_id: x.operating_room_id || undefined, surgeon_id: x.surgeon_id || undefined, anesthetist_id: x.anesthetist_id || undefined,
      team: x.team || '', pre_op_diagnosis: x.pre_op_diagnosis || '', planned_procedure: x.planned_procedure || '', risks: x.risks || '',
      consent_signed: x.consent_signed, fee: x.fee, procedure_performed: x.procedure_performed || '', anesthesia: x.anesthesia || undefined,
      findings: x.findings || '', complications: x.complications || '', post_op_diagnosis: x.post_op_diagnosis || '', post_op_instructions: x.post_op_instructions || '',
    });
    if (!keepTab) ftab.value = { in_surgery: 'surgery', recovery: 'post', completed: 'post' }[x.status] || 'pre';
    formOpen.value = true;
  } catch (e) {
    message.error(s('Could not load'));
  }
}
async function save() {
  if (!editing.value && !f.patient_id) return message.warning(s('Pick the patient.'));
  saving.value = true;
  try {
    if (editing.value) await http.put(`hospital/surgeries/${editing.value.id}`, { ...f });
    else {
      const d = await http.post('hospital/surgeries', { ...f });
      editing.value = { id: d.id };
    }
    message.success(s('Saved'));
    await openDetail(editing.value.id, true);
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}
async function move(st) {
  try {
    await http.put(`hospital/surgeries/${editing.value.id}`, { ...f }); // keep what was typed
    const d = await http.post(`hospital/surgeries/${editing.value.id}/move`, { status: st });
    if (d.treatment_id) message.success(s('Post-op treatment opened.'));
    await openDetail(editing.value.id);
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}
async function bill() {
  try {
    const d = await http.post(`hospital/surgeries/${editing.value.id}/bill`, {});
    message.success(`${s('Billed')}: ${d.reference}`);
    openDetail(editing.value.id, true);
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}
async function remove() {
  try {
    await http.delete(`hospital/surgeries/${editing.value.id}`);
    formOpen.value = false;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not delete'));
  }
}

const roomOpen = ref(false);
const room = reactive({ id: null, name: '', status: 'available', notes: '' });
function openRoom(r) {
  Object.assign(room, r ? { id: r.id, name: r.name, status: r.status, notes: r.notes || '' } : { id: null, name: '', status: 'available', notes: '' });
  roomOpen.value = true;
}
async function saveRoom() {
  if (!room.name.trim()) return message.warning(s('Type the name.'));
  try {
    if (room.id) await http.put(`hospital/operating-rooms/${room.id}`, { ...room });
    else await http.post('hospital/operating-rooms', { ...room });
    roomOpen.value = false;
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}

onMounted(load);
</script>

<style scoped>
.rooms { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 12px; }
.r-title { font-weight: 600; }
.room { cursor: pointer; }
.board { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 1100px) { .board { grid-template-columns: 1fr; } }
.col { background: rgba(128, 128, 128, 0.06); border-radius: 12px; padding: 8px; min-height: 140px; }
.col-head { font-weight: 600; display: flex; justify-content: space-between; margin-bottom: 8px; }
.card { background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(128, 128, 128, 0.18); border-left: 4px solid #64748b; border-radius: 8px; padding: 8px; margin-bottom: 8px; cursor: pointer; font-size: 13px; }
.c-top { display: flex; justify-content: space-between; gap: 6px; }
.muted { opacity: 0.6; font-size: 12px; }
.moves { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; }
.allergy { color: #dc2626; margin-bottom: 8px; }
</style>
