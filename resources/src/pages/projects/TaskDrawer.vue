<template>
  <a-drawer
    :open="open" placement="right" :width="drawerWidth" :closable="false" destroy-on-close
    class="task-drawer" :body-style="{ padding: '12px 16px' }" @close="close"
  >
    <template #title>
      <div class="td-head">
        <CheckSquareOutlined class="td-ic" />
        <span class="td-title">{{ form.title || tr('New task', 'Nueva tarea') }}</span>
      </div>
    </template>
    <template #extra>
      <a-space :size="6">
        <a-button size="small" @click="close">{{ tr('Close', 'Cerrar') }}</a-button>
        <template v-if="!ro">
        <a-button size="small" :loading="saving" @click="save()">{{ tr('Save', 'Guardar') }}</a-button>
        <a-button type="primary" size="small" :loading="saving" @click="saveAndClose">{{ tr('Save and close', 'Guardar y cerrar') }}</a-button>
        </template>
      </a-space>
    </template>

    <a-spin :spinning="loading">
      <a-row :gutter="14" align="stretch">
        <!-- What the task is: both texts end level with the box on the right (2026-11-22) -->
        <a-col :xs="24" :md="14" class="left-col">
          <div class="lbl task-lbl"><span>{{ tr('Task', 'Tarea') }}</span><a-checkbox v-model:checked="form.is_meeting" class="is-meet" :disabled="ro">{{ tr('It is a meeting', 'Es una reunión') }}</a-checkbox></div>
          <a-input :disabled="ro" v-model:value="form.title" class="title-in" :placeholder="tr('Task name', 'Nombre de la tarea')" />
          <div class="lbl mt fold" @click="toggle('req')"><component :is="fold.req ? RightOutlined : DownOutlined" class="caret" />{{ tr('Requirement / What we seek', 'Requerimiento / Qué buscamos') }}</div>
          <a-textarea :disabled="ro" v-show="!fold.req" v-model:value="form.requirement" class="desc grow" />
          <div class="lbl mt fold" @click="toggle('why')"><component :is="fold.why ? RightOutlined : DownOutlined" class="caret" />{{ tr('Why', 'Por qué') }}</div>
          <a-textarea :disabled="ro" v-show="!fold.why" v-model:value="form.reason" class="desc grow why" />
        </a-col>
        <!-- Informative, grouped on the right -->
        <a-col :xs="24" :md="10">
          <div class="info" :class="'st-' + (form.status || 'not_started')">
            <div class="row"><span class="k">{{ tr('Objective', 'Objetivo') }}</span>
              <a-select :disabled="ro" v-model:value="form.objective_id" size="small" allow-clear :bordered="false" class="v" :options="objectiveOptions" :placeholder="tr('No objective', 'Sin objetivo')" />
            </div>
            <div class="row"><span class="k">{{ tr('Stage', 'Etapa') }}</span>
              <a-select :disabled="ro" v-model:value="form.milestone_id" size="small" allow-clear :bordered="false" class="v" :options="stageOptions" :placeholder="tr('No stage', 'Sin etapa')" />
            </div>
            <div class="row top"><span class="k">{{ tr('Assigned', 'Asignada') }}</span>
              <a-select :disabled="ro"
                v-model:value="form.assigned_to" mode="multiple" size="small" :bordered="false" class="v" option-filter-prop="label"
                :options="memberOptions" :placeholder="tr('Nobody', 'Nadie')"
              />
            </div>
            <div class="row"><span class="k">{{ tr('Status', 'Estado') }}</span>
              <a-select :disabled="ro" v-model:value="form.status" size="small" :bordered="false" class="v" :options="PROJECT_STATUSES()" />
            </div>
            <div class="row"><span class="k">{{ tr('Priority', 'Prioridad') }}</span>
              <a-select :disabled="ro" v-model:value="form.priority" size="small" :bordered="false" class="v" :options="TASK_PRIORITIES()" />
            </div>
            <div class="row"><span class="k">{{ tr('Starts', 'Inicio') }}</span>
              <a-date-picker :disabled="ro" v-model:value="form.start_date" size="small" :bordered="false" class="v" value-format="YYYY-MM-DD" />
            </div>
            <div class="row"><span class="k">{{ tr('Due', 'Vence') }}</span>
              <a-date-picker :disabled="ro" v-model:value="form.end_date" size="small" :bordered="false" class="v" value-format="YYYY-MM-DD" />
            </div>
            <div class="row"><span class="k">{{ tr('Hours', 'Horas') }}</span>
              <a-input-number :disabled="ro" v-model:value="form.estimated_hour" size="small" :bordered="false" class="v" :min="0" :step="0.5" />
            </div>
            <div class="row"><span class="k">{{ tr('Progress', 'Avance') }}</span>
              <span class="v prog"><a-progress :percent="form.task_progress || 0" size="small" :stroke-color="progressColor(form.task_progress || 0)" /></span>
            </div>
          </div>
        </a-col>
      </a-row>

      <!-- A meeting (user, 2026-11-22): date and time, medium, link or place, the Outlook invitation. -->
      <div v-if="form.is_meeting" class="sec">
        <div class="sec-title fold" @click="toggle('meet')"><component :is="fold.meet ? RightOutlined : DownOutlined" class="caret" /><VideoCameraOutlined /> {{ tr('Meeting', 'Reunión') }}
          <span v-if="form.meeting_at" class="cnt">{{ dateTime(form.meeting_at) }}</span>
        </div>
        <div v-show="!fold.meet" class="meet">
          <div class="meet-row">
            <div class="m-f">
              <div class="lbl">{{ tr('Date and time', 'Fecha y hora') }}</div>
              <a-date-picker :disabled="ro" v-model:value="form.meeting_at" show-time :minute-step="5" size="small" class="w100" value-format="YYYY-MM-DD HH:mm" />
            </div>
            <div class="m-f">
              <div class="lbl">{{ tr('Medium', 'Medio') }}</div>
              <a-select :disabled="ro" v-model:value="form.meeting_medium" size="small" class="w100" allow-clear :options="mediumOptions" :placeholder="tr('Choose', 'Elegir')" />
            </div>
            <div class="m-f wide">
              <div class="lbl">{{ isPlace ? tr('Place', 'Lugar') : tr('Link (Zoom, Teams, Meet…)', 'Link (Zoom, Teams, Meet…)') }}</div>
              <a-input :disabled="ro" v-model:value="form.meeting_link" size="small" allow-clear :placeholder="isPlace ? tr('Address or office', 'Dirección u oficina') : 'https://zoom.us/j/…'">
                <template v-if="meetingUrl" #suffix>
                  <a :href="meetingUrl" target="_blank" rel="noopener" @click.stop><ExportOutlined /> {{ tr('Open', 'Abrir') }}</a>
                </template>
              </a-input>
            </div>
          </div>
          <div class="meet-inv">
            <a-upload :before-upload="onInvitation" :show-upload-list="false" accept=".ics,.msg,.eml,.vcs">
              <a-button size="small" :disabled="!taskId" :loading="invBusy"><template #icon><CalendarOutlined /></template>{{ tr('Outlook invitation (.ics / .msg)', 'Invitación de Outlook (.ics / .msg)') }}</a-button>
            </a-upload>
            <span class="muted small">{{ taskId ? tr('A .ics fills the date, time and link by itself; the file stays in Documents.', 'Un .ics llena solo la fecha, la hora y el link; el archivo queda en Documentos.') : tr('Save the task first.', 'Guarda la tarea primero.') }}</span>
          </div>
        </div>
      </div>

      <!-- The meeting's points / questions, each with its answer beside it (user, 2026-11-22). -->
      <div v-if="form.is_meeting" class="sec">
        <div class="sec-title fold" @click="toggle('points')"><component :is="fold.points ? RightOutlined : DownOutlined" class="caret" /><QuestionCircleOutlined /> {{ tr('Points to cover / Questions', 'Puntos a tratar / Consultas') }}
          <span v-if="form.meeting_points.length" class="cnt">{{ answeredCount }}/{{ form.meeting_points.length }} {{ tr('answered', 'respondidas') }}</span>
        </div>
        <div v-show="!fold.points" class="pts">
          <div v-if="form.meeting_points.length" class="pt-head"><span /><span>{{ tr('Point / question', 'Punto / pregunta') }}</span><span>{{ tr('Answer', 'Respuesta') }}</span><span /></div>
          <div v-for="(p, i) in form.meeting_points" :key="i" class="pt" :class="{ done: !!(p.a || '').trim() }">
            <span class="pt-n">{{ i + 1 }}</span>
            <a-textarea v-model:value="p.q" :disabled="ro" :auto-size="{ minRows: 1, maxRows: 6 }" :placeholder="tr('What to ask or cover', 'Qué preguntar o tratar')" />
            <a-textarea v-model:value="p.a" :disabled="ro" :auto-size="{ minRows: 1, maxRows: 8 }" :placeholder="tr('Answer given at the meeting', 'Respuesta dada en la reunión')" />
            <a-button v-if="!ro" type="text" size="small" danger @click="form.meeting_points.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
            <span v-else />
          </div>
          <a-button v-if="!ro" size="small" type="dashed" @click="form.meeting_points.push({ q: '', a: '' })"><template #icon><PlusOutlined /></template>{{ tr('Add point', 'Agregar punto') }}</a-button>
        </div>
      </div>

      <div class="sec">
        <div class="sec-title fold" @click="toggle('log')"><component :is="fold.log ? RightOutlined : DownOutlined" class="caret" /><MessageOutlined /> {{ tr('Agreements / Activity log', 'Acuerdos / Registro de actividades') }}<span v-if="activities.length" class="cnt">{{ activities.length }}</span></div>
        <div v-if="!taskId && !fold.log" class="muted small">{{ tr('Save the task first.', 'Guarda la tarea primero.') }}</div>
        <template v-else-if="!fold.log">
          <div v-if="canWork" class="act-new">
            <a-textarea
              v-model:value="act.note" :auto-size="{ minRows: 4, maxRows: 12 }" class="act-note"
              :placeholder="tr('What was done or agreed…', 'Qué se hizo o qué se acordó…')"
            />
            <a-tooltip :title="tr('Hours spent on this (they add up in the Hours tab)', 'Horas dedicadas a esto (se suman en la pestaña Horas)')">
              <a-input-number v-model:value="act.hours" size="small" :min="0" :max="24" :step="0.5" class="act-pct" addon-after="h" />
            </a-tooltip>
            <a-tooltip :title="tr('Task progress after this', 'Avance de la tarea después de esto')">
              <a-input-number v-model:value="act.progress" size="small" :min="0" :max="100" :step="5" class="act-pct" addon-after="%" />
            </a-tooltip>
            <a-button type="primary" size="small" :loading="actSaving" :disabled="!act.note" @click="addActivity">
              <template #icon><PlusOutlined /></template>{{ tr('Record', 'Registrar') }}
            </a-button>
          </div>
          <div v-if="!activities.length" class="muted small pad">{{ tr('Nothing recorded yet.', 'Nada registrado todavía.') }}</div>
          <div v-for="a in activities" :key="a.id" class="act">
            <div class="act-meta">
              <span class="act-date">{{ dateTime(a.created_at) }}</span>
              <span class="act-user">{{ a.user }}</span>
              <a-tag v-if="a.hours" class="act-tag">{{ a.hours }} h</a-tag>
              <a-tag v-if="a.progress !== null" :color="a.progress >= 100 ? 'success' : 'processing'" class="act-tag">{{ a.progress }}%</a-tag>
              <a-button v-if="a.mine" type="text" size="small" danger class="act-del" @click="removeActivity(a)"><template #icon><DeleteOutlined /></template></a-button>
            </div>
            <!-- eslint-disable-next-line vue/no-v-html -- escaped + DOMPurify (lib/markdown) -->
            <div class="act-text md" :class="{ clip: !expanded[a.id] && a.note.length > 400 }" v-html="markdown(a.note)" />
            <a v-if="a.note.length > 400" class="more" @click="expanded[a.id] = !expanded[a.id]">{{ expanded[a.id] ? tr('See less', 'Ver menos') : tr('See more', 'Ver más') }}</a>
          </div>
        </template>
      </div>

      <!-- Documents, links and gallery -->
      <div class="sec">
        <div class="sec-title fold" @click="toggle('docs')"><component :is="fold.docs ? RightOutlined : DownOutlined" class="caret" /><PaperClipOutlined /> {{ tr('Documents, links and gallery', 'Documentos, enlaces y galería') }}</div>
        <div v-if="!taskId && !fold.docs" class="muted small">{{ tr('Save the task first.', 'Guarda la tarea primero.') }}</div>
        <ProjectDocuments v-else-if="!fold.docs" :key="docsKey" :readonly="!canWork" :project-id="projectId" :task-id="taskId" />
      </div>
    </a-spin>
  </a-drawer>
</template>

<script setup>
/**
 * A task in a panel from the right, half the screen (user, 2026-11-22): name; objective and description on the left; the
 * informative fields (stage, assigned, status, dates) grouped on the right; the agreements / activity log the assigned
 * person fills (date and time + progress, task_activities); documents, links and gallery. Compact, like Hordex.
 */
import { ref, reactive, computed, watch } from 'vue';
import { markdown } from '../../lib/markdown';
import { Modal, message } from 'ant-design-vue';
import { CheckSquareOutlined, MessageOutlined, PaperClipOutlined, PlusOutlined, DeleteOutlined, RightOutlined, DownOutlined, VideoCameraOutlined, CalendarOutlined, ExportOutlined, QuestionCircleOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useFormat } from '../../composables/useFormat';
import { useIsPhone } from '../../lib/useIsPhone';
import { PROJECT_STATUSES } from './projectStatus';
import { TASK_PRIORITIES, progressColor } from './workspaceOptions';
import ProjectDocuments from './ProjectDocuments.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  projectId: { type: Number, required: true },
  taskId: { type: Number, default: null },
  // A new task: its stage / objective when opened from one.
  milestoneId: { type: Number, default: null },
  objectiveId: { type: Number, default: null },
  // Opened from Mis proyectos by a member (2026-11-22): the task's data read only unless they edit the project.
  member: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'saved', 'update:taskId']);
const tr = useTr();
const { dateTime } = useFormat();
const isPhone = useIsPhone();
const drawerWidth = computed(() => (isPhone.value ? '100%' : 'max(70vw, 760px)')); // 70 % (user, 2026-11-22)

const loading = ref(false);
// Read only (a member who doesn't edit the project) / may record and upload (assigned to the task).
const ro = ref(false);
const canWork = ref(true);
const saving = ref(false);
const meta = ref({ stages: [], objectives: [], members: [], staff: [], manager_id: null, project: {} });
const form = ref({});
const dirty = ref(false);

const stageOptions = computed(() => (meta.value.stages || []).map(s => ({ value: s.id, label: s.title })));
const objectiveOptions = computed(() => (meta.value.objectives || []).map(o => ({ value: o.id, label: o.title })));
const memberOptions = computed(() => [
  ...(meta.value.members || []).map(m => ({ value: m.id, label: `${m.name}${m.role ? ' · ' + m.role : ''}` })),
  ...(meta.value.staff || []).map(m => ({ value: m.id, label: m.name })),
]);

function blank() {
  const stage = (meta.value.stages || []).find(s => s.id === props.milestoneId);
  const today = new Date().toISOString().slice(0, 10);
  const due = stage?.due_date ? String(stage.due_date).slice(0, 10) : today;
  return {
    is_meeting: false, meeting_at: null, meeting_medium: undefined, meeting_link: '', meeting_points: [],
    title: '', requirement: '', reason: '', objective_id: props.objectiveId || undefined, milestone_id: props.milestoneId || undefined,
    assigned_to: meta.value.manager_id ? [meta.value.manager_id] : [], status: 'not_started', priority: 'medium',
    start_date: today <= due ? today : due, end_date: due, estimated_hour: null, task_progress: 0,
  };
}

async function load() {
  loading.value = true;
  try {
    meta.value = await http.get(`projects/${props.projectId}/task-meta`);
    if (props.taskId) {
      const d = await http.get(props.member ? `my/tasks/${props.taskId}` : `tasks/${props.taskId}/edit`);
      ro.value = props.member && !d.can_edit;
      canWork.value = d.can_work !== undefined ? !!d.can_work : true;
      const x = d.task || {};
      form.value = {
        is_meeting: !!Number(x.is_meeting), meeting_at: x.meeting_at ? String(x.meeting_at).slice(0, 16) : null,
        meeting_medium: x.meeting_medium || undefined, meeting_link: x.meeting_link || '', meeting_points: parsePoints(x.meeting_points),
        title: x.title || '', requirement: x.requirement ?? (x.description || ''), reason: x.reason || '', objective_id: x.objective_id || undefined,
        milestone_id: x.milestone_id || undefined, assigned_to: d.assigned_employees || [], status: x.status || 'not_started',
        priority: x.priority || 'medium', start_date: x.start_date || null, end_date: x.end_date || null,
        estimated_hour: x.estimated_hour === null ? null : Number(x.estimated_hour), task_progress: Number(x.task_progress) || 0,
      };
      loadActivities();
    } else {
      form.value = blank();
      activities.value = [];
    }
    dirty.value = false;
  } catch (e) {
    message.error(tr('This task is not available.', 'Esta tarea no está disponible.'));
    emit('close');
  } finally { loading.value = false; }
}
watch(() => [props.open, props.taskId], ([o]) => { if (o) load(); }, { immediate: true });
watch(form, () => { dirty.value = true; }, { deep: true, flush: 'sync' });

async function saveAndClose() {
  if (await save()) emit('close');
}

/** Saves the task; true when it was saved. */
async function save() {
  if (!form.value.title) { message.warning(tr('Write the task name.', 'Escribe el nombre de la tarea.')); return false; }
  if (!form.value.start_date || !form.value.end_date) { message.warning(tr('Give the start and due dates.', 'Indica las fechas de inicio y fin.')); return false; }
  if (form.value.end_date < form.value.start_date) { message.warning(tr('The due date cannot be before the start.', 'La fecha de fin no puede ser anterior a la de inicio.')); return false; }
  saving.value = true;
  const body = {
    ...form.value, project_id: props.projectId, company_id: meta.value.project?.company_id,
    milestone_id: form.value.milestone_id || null, objective_id: form.value.objective_id || null,
    task_progress: form.value.status === 'completed' ? 100 : form.value.task_progress,
  };
  try {
    if (props.taskId) await http.put(`tasks/${props.taskId}`, body);
    else {
      const r = await http.post('tasks', body);
      if (r?.id) emit('update:taskId', r.id); // stays open: the log and documents become available
    }
    dirty.value = false;
    message.success(tr('Task saved.', 'Tarea guardada.'));
    emit('saved');
    return true;
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat() : [];
    message.error(errs[0] || e?.data?.message || tr('Could not save.', 'No se pudo guardar.'), 6);
    return false;
  } finally { saving.value = false; }
}

function close() {
  if (!dirty.value) { emit('close'); return; }
  Modal.confirm({
    title: tr('Close without saving?', '¿Cerrar sin guardar?'),
    content: tr('The changes to this task will be lost.', 'Se perderán los cambios de esta tarea.'),
    okText: tr('Close', 'Cerrar'), cancelText: tr('Keep editing', 'Seguir editando'), okType: 'danger',
    onOk: () => emit('close'),
  });
}

// ---- activity log
// Collapsible sections, remembered in this browser (2026-11-22).
const FOLD_KEY = 'murano.taskDrawer.fold';
const fold = reactive((() => { try { return { req: false, why: false, log: false, docs: false, meet: false, points: false, ...JSON.parse(localStorage.getItem(FOLD_KEY) || '{}') }; } catch (e) { return { req: false, why: false, log: false, docs: false }; } })());
function toggle(k) { fold[k] = !fold[k]; try { localStorage.setItem(FOLD_KEY, JSON.stringify(fold)); } catch (e) { /* private window */ } }

// ---- meeting
const mediumOptions = computed(() => [
  { value: 'Zoom', label: 'Zoom' }, { value: 'Teams', label: 'Microsoft Teams' }, { value: 'Meet', label: 'Google Meet' },
  { value: 'WhatsApp', label: 'WhatsApp' }, { value: 'Teléfono', label: tr('Phone', 'Teléfono') }, { value: 'Presencial', label: tr('In person', 'Presencial') },
]);
const isPlace = computed(() => form.value.meeting_medium === 'Presencial');
// Points / questions of the meeting with their answers (2026-11-22).
function parsePoints(v) {
  try { const a = typeof v === 'string' ? JSON.parse(v || '[]') : (v || []); return Array.isArray(a) ? a.map(p => ({ q: p.q || '', a: p.a || '' })) : []; } catch (e) { return []; }
}
const answeredCount = computed(() => (form.value.meeting_points || []).filter(p => (p.a || '').trim()).length);
const meetingUrl = computed(() => (/^https?:\/\/\S+$/i.test((form.value.meeting_link || '').trim()) ? form.value.meeting_link.trim() : null));
// Typing / pasting a link names its medium.
watch(() => form.value.meeting_link, v => {
  if (!v || form.value.meeting_medium) return;
  if (/zoom\.us/i.test(v)) form.value.meeting_medium = 'Zoom';
  else if (/teams\.(microsoft|live)\.com/i.test(v)) form.value.meeting_medium = 'Teams';
  else if (/meet\.google\.com/i.test(v)) form.value.meeting_medium = 'Meet';
});
// An Outlook / calendar invitation: kept in the task's documents; a .ics fills date, time and link.
const invBusy = ref(false);
function icsField(text, name) {
  const m = text.replace(/\r?\n[ \t]/g, '').match(new RegExp('^' + name + '(?:;[^:\\n]*)?:(.*)$', 'mi'));
  return m ? m[1].trim() : '';
}
function icsDate(v) {
  const m = String(v).match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z)?$/);
  if (!m) return null;
  const d = m[7] ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5])) : new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}
async function onInvitation(file) {
  invBusy.value = true;
  try {
    if (/\.(ics|vcs)$/i.test(file.name)) {
      const text = await file.text();
      const at = icsDate(icsField(text, 'DTSTART'));
      const all = text.replace(/\\n/g, ' ');
      const link = icsField(text, 'URL') || (all.match(/https?:\/\/[^\s\\"<>]*(zoom\.us|teams\.microsoft\.com|teams\.live\.com|meet\.google\.com)[^\s\\"<>]*/i) || [])[0] || '';
      const loc = icsField(text, 'LOCATION').replace(/\\,/g, ',');
      if (at) form.value.meeting_at = at;
      if (link) form.value.meeting_link = link;
      else if (loc && !form.value.meeting_link) { form.value.meeting_link = loc; if (!/^https?:/i.test(loc)) form.value.meeting_medium = 'Presencial'; }
    }
    const fd = new FormData();
    fd.append('files[]', file);
    fd.append('task_id', props.taskId);
    fd.append('note', tr('Meeting invitation', 'Invitación de la reunión'));
    await http.postForm(`projects/${props.projectId}/documents`, fd);
    message.success(tr('Invitation saved; save the task to keep the date and link.', 'Invitación guardada; guarda la tarea para conservar la fecha y el link.'));
    docsKey.value += 1;
  } catch (e) {
    message.error(e?.data?.message || tr('Could not read the invitation.', 'No se pudo leer la invitación.'));
  } finally { invBusy.value = false; }
  return false;
}
const docsKey = ref(0);

const activities = ref([]);
const expanded = reactive({});
const act = ref({ note: '', progress: null, hours: null });
const actSaving = ref(false);
async function loadActivities() {
  const r = await http.get(`tasks/${props.taskId}/activities`);
  activities.value = r.activities || [];
  act.value = { note: '', progress: r.progress ?? null, hours: null };
}
async function addActivity() {
  actSaving.value = true;
  try {
    await http.post(`tasks/${props.taskId}/activities`, { note: act.value.note, progress: act.value.progress, hours: act.value.hours });
    const keepDirty = dirty.value;
    const r = await http.get(`tasks/${props.taskId}/activities`);
    activities.value = r.activities || [];
    form.value.task_progress = r.progress;
    form.value.status = r.status;
    act.value = { note: '', progress: r.progress, hours: null };
    dirty.value = keepDirty;
    emit('saved');
  } catch (e) {
    message.error(e?.data?.message || tr('Could not record it.', 'No se pudo registrar.'));
  } finally { actSaving.value = false; }
}
function removeActivity(a) {
  Modal.confirm({
    title: tr('Delete this entry?', '¿Eliminar este registro?'), okType: 'danger', okText: tr('Delete', 'Eliminar'), cancelText: tr('Cancel', 'Cancelar'),
    async onOk() { await http.delete(`task_activities/${a.id}`); await loadActivities(); },
  });
}
</script>

<style scoped>
.td-head { display: flex; align-items: center; gap: 6px; min-width: 0; }
/* Meeting points: number, question, answer beside it, delete (2026-11-22). */
.pts { display: flex; flex-direction: column; gap: 6px; }
.pt-head, .pt { display: grid; grid-template-columns: 22px 1fr 1fr 28px; gap: 8px; align-items: start; }
.pt-head { font-size: 11.5px; font-weight: 600; opacity: 0.6; }
.pt-n { font-weight: 700; padding-top: 5px; text-align: right; opacity: 0.6; }
.pt.done .pt-n { color: #16a34a; opacity: 1; }
@media (max-width: 767px) { .pt-head { display: none; } .pt { grid-template-columns: 22px 1fr 28px; } .pt > :nth-child(3) { grid-column: 2; } }
/* A long task name ends in … instead of running under the buttons. */
.task-drawer :deep(.ant-drawer-title) { min-width: 0; overflow: hidden; }
.td-ic { color: #6366f1; font-size: 18px; }
.td-title { font-size: 16px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.title-in { font-weight: 600; }
.w100 { width: 100%; }
.lbl { font-size: 11.5px; font-weight: 600; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 3px; }
.mt { margin-top: 10px; }
.desc { font-size: 13px; }
.left-col { display: flex; flex-direction: column; }
.grow { flex: 1 1 0; min-height: 64px; resize: none; }
.task-lbl { display: flex; align-items: center; justify-content: space-between; }
.is-meet { font-size: 12px; text-transform: none; letter-spacing: 0; font-weight: 500; opacity: 1; }
.meet { background: rgba(99, 102, 241, 0.05); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 8px; padding: 8px 10px; }
.meet-row { display: flex; gap: 10px; flex-wrap: wrap; }
.m-f { flex: 1 1 160px; min-width: 150px; }
.m-f.wide { flex: 2 1 280px; }
.meet-inv { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.grow.why { flex-grow: 0.6; }
.info { background: rgba(128, 128, 128, 0.06); border: 1px solid rgba(128, 128, 128, 0.15); border-radius: 8px; padding: 6px 8px; transition: background 0.2s; }
/* The box takes its status colour (user, 2026-11-22): green done, blue in progress, red cancelled, light grey otherwise. */
.info.st-completed { background: rgba(22, 163, 74, 0.10); border-color: rgba(22, 163, 74, 0.35); }
.info.st-progress { background: rgba(37, 99, 235, 0.09); border-color: rgba(37, 99, 235, 0.32); }
.info.st-cancelled { background: rgba(220, 38, 38, 0.09); border-color: rgba(220, 38, 38, 0.32); }
.info.st-not_started, .info.st-hold { background: rgba(100, 116, 139, 0.10); border-color: rgba(100, 116, 139, 0.28); }
.row { display: flex; align-items: center; gap: 6px; min-height: 30px; border-bottom: 1px dashed rgba(128, 128, 128, 0.15); }
.row:last-child { border-bottom: 0; }
.row.top { align-items: flex-start; padding-top: 3px; }
.k { width: 74px; flex: none; font-size: 12px; opacity: 0.65; }
.v { flex: 1; min-width: 0; width: 100%; }
/* Objective and assigned people shown whole, on several lines if needed (2026-11-22). */
.v :deep(.ant-select-selector) { height: auto !important; }
.v.ant-select-single :deep(.ant-select-selection-item) { white-space: normal !important; line-height: 1.35 !important; padding-top: 3px; padding-bottom: 3px; }
.v.ant-select-multiple :deep(.ant-select-selection-item) { height: auto; line-height: 20px; }
.v :deep(.ant-select-selection-overflow) { flex-wrap: wrap; }
.prog { padding: 0 6px; }
.sec { margin-top: 14px; padding-top: 10px; border-top: 1px solid rgba(128, 128, 128, 0.18); }
.sec-title { font-weight: 600; font-size: 13px; margin-bottom: 8px; color: #4f46e5; }
.fold { cursor: pointer; user-select: none; }
.fold:hover { opacity: 0.85; }
.caret { font-size: 10px; margin-inline-end: 5px; }
.cnt { margin-inline-start: 6px; font-size: 11px; background: rgba(99, 102, 241, 0.15); border-radius: 8px; padding: 0 6px; }
.act-new { display: flex; gap: 6px; align-items: flex-start; margin-bottom: 8px; }
.act-note { flex: 1; }
.act-pct { width: 96px; flex: none; }
.act { padding: 6px 8px; border-left: 3px solid #6366f1; background: rgba(99, 102, 241, 0.05); border-radius: 4px; margin-bottom: 6px; }
.act-meta { display: flex; align-items: center; gap: 8px; font-size: 11.5px; }
.act-date { font-weight: 600; }
.act-user { opacity: 0.7; }
.act-tag { margin: 0; }
.act-del { margin-inline-start: auto; }
.act-text { font-size: 13px; margin-top: 2px; overflow-wrap: anywhere; }
.act-text.clip { max-height: 132px; overflow: hidden; -webkit-mask-image: linear-gradient(#000 70%, transparent); mask-image: linear-gradient(#000 70%, transparent); }
.more { font-size: 12px; }
.md :deep(p) { margin: 0 0 6px; }
.md :deep(ul), .md :deep(ol) { margin: 0 0 6px; padding-inline-start: 18px; }
.md :deep(.md-h) { font-size: 13px; font-weight: 700; margin: 4px 0; }
.md :deep(.md-table) { border-collapse: collapse; margin: 4px 0 8px; font-size: 12px; }
.md :deep(.md-table th), .md :deep(.md-table td) { border: 1px solid rgba(128, 128, 128, 0.25); padding: 2px 6px; text-align: left; }
.md :deep(.md-table th) { background: rgba(128, 128, 128, 0.08); }
.md :deep(code) { font-size: 12px; background: rgba(128, 128, 128, 0.12); padding: 0 3px; border-radius: 3px; }
.muted { opacity: 0.6; }
.small { font-size: 12px; }
.pad { padding: 4px 0; }
</style>
