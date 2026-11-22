<template>
  <div>
    <div class="bar">
      <a-button type="primary" @click="openStage(null)">
        <template #icon><PlusOutlined /></template>
        {{ tr('New stage', 'Nueva etapa') }}
      </a-button>
      <a-button @click="applyOpen = true">
        <template #icon><CopyOutlined /></template>
        {{ tr('Apply a template', 'Aplicar plantilla') }}
      </a-button>
      <a-button :disabled="!stages.length" @click="saveTplOpen = true">
        <template #icon><SaveOutlined /></template>
        {{ tr('Save as template', 'Guardar como plantilla') }}
      </a-button>
      <span class="grow" />
      <a-button size="small" type="link" @click="toggleAll">
        {{ allOpen ? tr('Collapse all', 'Contraer todo') : tr('Expand all', 'Expandir todo') }}
      </a-button>
    </div>

    <a-empty v-if="!stages.length && !loose.length" :description="tr('No stages yet. Create one or apply a template.', 'Sin etapas todavía. Crea una o aplica una plantilla.')" />

    <div v-for="(s, i) in groups" :key="s.key" class="stage">
      <div class="stage-head" @click="toggle(s.key)">
        <component :is="open[s.key] ? DownOutlined : RightOutlined" class="caret" />
        <span class="num">{{ s.id ? i + 1 : '—' }}</span>
        <span class="stage-title">{{ s.title }}</span>
        <a-tag v-if="s.id" :color="stageColor(s.status)" class="tight">{{ stageLabel(s.status) }}</a-tag>
        <span class="meta">{{ s.done }}/{{ s.tasks.length }} {{ tr('tasks', 'tareas') }}</span>
        <span v-if="s.from || s.due_date" class="meta">
          <CalendarOutlined /> {{ s.from ? date(s.from) : '—' }} → {{ s.due_date ? date(s.due_date) : '—' }}
        </span>
        <span class="prog"><a-progress :percent="s.pct" size="small" :stroke-color="progressColor(s.pct)" /></span>
        <span class="acts" @click.stop>
          <a-tooltip :title="tr('Add task to this stage', 'Agregar tarea a esta etapa')">
            <a-button type="text" size="small" @click="newTask(s.id)"><template #icon><PlusOutlined /></template></a-button>
          </a-tooltip>
          <template v-if="s.id">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openStage(s.raw)"><template #icon><EditOutlined /></template></a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="removeStage(s)"><template #icon><DeleteOutlined /></template></a-button>
            </a-tooltip>
          </template>
        </span>
      </div>
      <div v-if="open[s.key]" class="stage-body">
        <div v-if="s.raw && s.raw.description" class="stage-desc">{{ s.raw.description }}</div>
        <div v-if="!s.tasks.length" class="muted pad">{{ tr('No tasks in this stage.', 'Sin tareas en esta etapa.') }}</div>
        <div v-for="t in s.tasks" :key="t.id" class="task" @click="editTask(t.id)">
          <CheckCircleFilled v-if="t.status === 'completed'" class="ic done" />
          <ClockCircleOutlined v-else-if="t.status === 'progress'" class="ic prog-ic" />
          <BorderOutlined v-else class="ic" />
          <span class="t-title" :class="{ striked: t.status === 'completed' }">{{ t.title }}</span>
          <span class="t-who">{{ t.assignees || '—' }}</span>
          <span class="t-date" :class="{ late: t.is_overdue }">{{ t.start_date ? date(t.start_date) : '' }} → {{ t.end_date ? date(t.end_date) : '' }}</span>
          <a-tag :color="projectStatusColor(t.status)" class="t-tag">{{ projectStatusLabel(t.status) }}</a-tag>
        </div>
      </div>
    </div>

    <!-- Stage form -->
    <a-modal
      :open="stageOpen" :title="editing ? tr('Edit stage', 'Editar etapa') : tr('New stage', 'Nueva etapa')" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')" @ok="saveStage" @cancel="stageOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item :label="tr('Name *', 'Nombre *')">
          <a-input v-model:value="form.title" :placeholder="tr('e.g. Analysis and design', 'p. ej. Análisis y diseño')" />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="8">
            <a-form-item :label="tr('Ends on', 'Termina el')">
              <a-date-picker v-model:value="form.due_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item :label="tr('Status', 'Estado')">
              <a-select v-model:value="form.status" :options="MILESTONE_STATUSES()" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item :label="tr('Order', 'Orden')">
              <a-input-number v-model:value="form.position" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Description')" style="margin-bottom: 0">
          <a-textarea v-model:value="form.description" :rows="3" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Apply a template -->
    <a-modal
      :open="applyOpen" :title="tr('Apply a project template', 'Aplicar una plantilla de proyecto')" :width="860"
      :confirm-loading="applying" :ok-text="tr('Apply', 'Aplicar')" :cancel-text="$t('Cancel')"
      :ok-button-props="{ disabled: !tplId }" @ok="applyTemplate" @cancel="applyOpen = false"
    >
      <a-row :gutter="16">
        <a-col :xs="24" :md="10">
          <div class="tpl-list">
            <div v-for="t in templates" :key="t.id" class="tpl" :class="{ sel: t.id === tplId }" @click="tplId = t.id">
              <div class="tpl-name">{{ tplName(t) }}</div>
              <div class="tpl-sub">
                {{ t.stages_count }} {{ tr('stages', 'etapas') }} · {{ t.tasks_count }} {{ tr('tasks', 'tareas') }} · ~{{ t.duration_days }} {{ tr('days', 'días') }}
                <a-tag v-if="!t.standard" color="blue" class="tight">{{ tr('Own', 'Propia') }}</a-tag>
              </div>
            </div>
          </div>
        </a-col>
        <a-col :xs="24" :md="14">
          <template v-if="tpl">
            <div class="muted" style="margin-bottom: 8px">{{ tpl.description }}</div>
            <a-row :gutter="8">
              <a-col :span="12">
                <div class="lbl">{{ tr('Starts on', 'Empieza el') }}</div>
                <a-date-picker v-model:value="applyStart" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-col>
              <a-col :span="12">
                <div class="lbl">&nbsp;</div>
                <a-checkbox v-model:checked="applyFit" :disabled="!projectEnd">
                  {{ tr('Fit to the project end', 'Ajustar al fin del proyecto') }}
                </a-checkbox>
              </a-col>
            </a-row>
            <div class="preview">
              <div v-for="(s, i) in tpl.stages" :key="i" class="pv-stage">
                <div class="pv-head">{{ i + 1 }}. {{ s.title }} <span class="muted">· {{ tr('day', 'día') }} {{ s.start }}–{{ s.start + s.days }}</span></div>
                <div v-for="(t, j) in s.tasks" :key="j" class="pv-task">
                  <span>{{ t[0] }}</span>
                  <span class="pv-role" :class="{ miss: t[3] && !roleTaken(t[3]) }">{{ t[3] || '' }}</span>
                </div>
              </div>
            </div>
            <a-alert
              v-if="missingRoles.length" type="warning" show-icon style="margin-top: 8px"
              :message="tr('No member has these roles; their tasks go to the project manager: ', 'Ningún miembro tiene estos roles; sus tareas van al jefe de proyecto: ') + missingRoles.join(', ')"
            />
          </template>
          <a-empty v-else :description="tr('Pick a template', 'Elige una plantilla')" />
        </a-col>
      </a-row>
    </a-modal>

    <!-- Save as template -->
    <a-modal
      :open="saveTplOpen" :title="tr('Save as template', 'Guardar como plantilla')" :width="480"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')" @ok="saveTemplate" @cancel="saveTplOpen = false"
    >
      <p class="muted">{{ tr('The stages, tasks, roles and days of this project become a template of your company.', 'Las etapas, tareas, roles y días de este proyecto se guardan como una plantilla de tu empresa.') }}</p>
      <a-form layout="vertical">
        <a-form-item :label="tr('Name *', 'Nombre *')"><a-input v-model:value="tplForm.name" /></a-form-item>
        <a-form-item :label="$t('Description')" style="margin-bottom: 0"><a-textarea v-model:value="tplForm.description" :rows="2" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * "Etapas del proyecto" (user, 2026-11-21; the old "Hitos"): each stage with its tasks, a task added straight into a
 * stage, and project templates (apply one / save this project as one). Reads the project payload the parent loaded.
 */
import { ref, reactive, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Modal, message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined, SaveOutlined, DownOutlined, RightOutlined, CalendarOutlined,
  CheckCircleFilled, ClockCircleOutlined, BorderOutlined,
} from '@ant-design/icons-vue';
import { useFormat } from '../../composables/useFormat';
import { MILESTONE_STATUSES, progressColor } from './workspaceOptions';
import { projectStatusLabel, projectStatusColor } from './projectStatus';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useUiStore } from '../../stores/ui';

const props = defineProps({
  projectId: { type: Number, required: true },
  data: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['changed', 'open-task', 'new-task']);
const tr = useTr();
const router = useRouter();
const { date } = useFormat();

const stages = computed(() => props.data.milestones || []);
const tasks = computed(() => props.data.tasks || []);
const loose = computed(() => tasks.value.filter(t => !t.milestone_id || !stages.value.some(s => s.id === t.milestone_id)));
const projectEnd = computed(() => (props.data.project || {}).end_date || null);

const stageLabel = s => (MILESTONE_STATUSES().find(o => o.value === s) || {}).label || s;
const stageColor = s => ({ completed: 'success', in_progress: 'processing', delayed: 'error' }[s] || 'default');

function summarize(key, raw, list) {
  const sorted = [...list].sort((a, b) => String(a.start_date || '').localeCompare(String(b.start_date || '')));
  const counted = sorted.filter(t => t.status !== 'cancelled');
  const done = counted.filter(t => t.status === 'completed').length;
  const starts = sorted.map(t => t.start_date).filter(Boolean).sort();
  return {
    key, raw, id: raw ? raw.id : null, title: raw ? raw.title : tr('Tasks without a stage', 'Tareas sin etapa'),
    status: raw ? raw.status : null, due_date: raw ? raw.due_date : null, from: starts[0] || null,
    tasks: sorted, done, pct: raw && raw.status === 'completed' ? 100 : (counted.length ? Math.round(done * 100 / counted.length) : (raw ? raw.progress || 0 : 0)),
  };
}

const groups = computed(() => {
  const out = stages.value.map(s => summarize(`s${s.id}`, s, tasks.value.filter(t => t.milestone_id === s.id)));
  if (loose.value.length) out.push(summarize('loose', null, loose.value));
  return out;
});

const open = reactive({});
const allOpen = computed(() => groups.value.length && groups.value.every(g => open[g.key]));
watch(groups, gs => {
  // Open the stages in progress (and the first not completed) the first time.
  if (Object.keys(open).length) return;
  const firstOpen = gs.find(g => g.id && g.status !== 'completed');
  gs.forEach(g => { open[g.key] = g.status === 'in_progress' || g === firstOpen || !g.id; });
}, { immediate: true });
function toggle(k) { open[k] = !open[k]; }
function toggleAll() { const v = !allOpen.value; groups.value.forEach(g => { open[g.key] = v; }); }

function newTask(stageId) {
  emit('new-task', stageId || null);
}
function editTask(id) { emit('open-task', id); }

// ---- stage form
const stageOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref({});
function openStage(raw) {
  editing.value = raw;
  form.value = raw
    ? { title: raw.title, description: raw.description || '', due_date: raw.due_date, status: raw.status, position: raw.position || 0, progress: raw.progress || 0, budget: raw.budget }
    : { title: '', description: '', due_date: null, status: 'pending', position: stages.value.length + 1, progress: 0, budget: null };
  stageOpen.value = true;
}
async function saveStage() {
  if (!form.value.title) { message.warning(tr('The name is required.', 'El nombre es obligatorio.')); return; }
  saving.value = true;
  try {
    const body = { ...form.value, project_id: props.projectId };
    if (editing.value) await http.put(`project_milestones/${editing.value.id}`, body);
    else await http.post('project_milestones', body);
    stageOpen.value = false;
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || tr('Could not save.', 'No se pudo guardar.'));
  } finally { saving.value = false; }
}
function removeStage(s) {
  Modal.confirm({
    title: tr('Delete this stage?', '¿Eliminar esta etapa?'),
    content: tr('Its tasks are kept, without a stage.', 'Sus tareas se conservan, sin etapa.'),
    okType: 'danger', okText: tr('Delete', 'Eliminar'), cancelText: tr('Cancel', 'Cancelar'),
    async onOk() { await http.delete(`project_milestones/${s.id}`); emit('changed'); },
  });
}

// ---- templates
const templates = ref([]);
const tplId = ref(null);
const applyOpen = ref(false);
const applying = ref(false);
const applyStart = ref(null);
const applyFit = ref(false);
const members = ref([]);
const tpl = computed(() => templates.value.find(t => t.id === tplId.value) || null);
const ui = useUiStore();
const tplName = t => (ui.locale !== 'es' && t.name_en ? t.name_en : t.name);
const roleTaken = r => members.value.some(m => (m.role || '').trim().toLowerCase() === String(r).trim().toLowerCase());
const missingRoles = computed(() => (tpl.value ? tpl.value.roles.filter(r => !roleTaken(r)) : []));

watch(applyOpen, async v => {
  if (!v) return;
  applyStart.value = (props.data.project || {}).start_date ? String(props.data.project.start_date).slice(0, 10) : null;
  try {
    const [a, b] = await Promise.all([
      http.get('project_templates', { project_id: props.projectId }),
      http.get(`projects/${props.projectId}/members`),
    ]);
    templates.value = a.templates || [];
    members.value = b.members || [];
  } catch (e) { /* empty list */ }
});

async function applyTemplate() {
  if (!applyStart.value) { message.warning(tr('Pick the start date.', 'Elige la fecha de inicio.')); return; }
  applying.value = true;
  try {
    const r = await http.post(`projects/${props.projectId}/apply-template`, { template_id: tplId.value, start_date: applyStart.value, fit: applyFit.value });
    message.success(tr(`${r.stages} stages and ${r.tasks} tasks added.`, `Se agregaron ${r.stages} etapas y ${r.tasks} tareas.`));
    applyOpen.value = false;
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || tr('Could not apply the template.', 'No se pudo aplicar la plantilla.'));
  } finally { applying.value = false; }
}

const saveTplOpen = ref(false);
const tplForm = ref({ name: '', description: '' });
watch(saveTplOpen, v => { if (v) tplForm.value = { name: (props.data.project || {}).title || '', description: '' }; });
async function saveTemplate() {
  if (!tplForm.value.name) return;
  saving.value = true;
  try {
    await http.post(`projects/${props.projectId}/save-template`, tplForm.value);
    message.success(tr('Template saved.', 'Plantilla guardada.'));
    saveTplOpen.value = false;
  } catch (e) {
    message.error(e?.data?.message || tr('Could not save.', 'No se pudo guardar.'));
  } finally { saving.value = false; }
}
</script>

<style scoped>
.bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.grow { flex: 1; }
.stage { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; margin-bottom: 10px; overflow: hidden; }
.stage-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; padding: 8px 12px; cursor: pointer; background: rgba(128, 128, 128, 0.05); }
.stage-head:hover { background: rgba(128, 128, 128, 0.1); }
.caret { font-size: 11px; opacity: 0.6; }
.num { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 11px; background: #6366f1; color: #fff; font-size: 12px; font-weight: 600; }
.stage-title { font-weight: 600; font-size: 14px; }
.meta { font-size: 12px; opacity: 0.65; white-space: nowrap; }
.prog { width: 160px; margin-inline-start: auto; }
.acts { white-space: nowrap; }
.tight { margin: 0; }
.stage-body { padding: 4px 12px 8px 40px; }
.stage-desc { font-size: 12.5px; opacity: 0.7; padding: 4px 0 6px; white-space: pre-wrap; }
.task { display: flex; align-items: center; gap: 10px; padding: 6px 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.1); cursor: pointer; }
.task:last-child { border-bottom: 0; }
.task:hover { background: rgba(128, 128, 128, 0.06); }
.ic { opacity: 0.5; }
.ic.done { color: #16a34a; opacity: 1; }
.ic.prog-ic { color: #2563eb; opacity: 1; }
.t-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.striked { text-decoration: line-through; opacity: 0.6; }
.t-who { width: 200px; font-size: 12px; opacity: 0.75; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.t-date { width: 190px; font-size: 12px; opacity: 0.7; white-space: nowrap; text-align: right; }
.t-date.late { color: #dc2626; opacity: 1; }
.t-tag { margin: 0; min-width: 90px; text-align: center; }
.muted { opacity: 0.6; }
.pad { padding: 8px 0; }
.tpl-list { max-height: 460px; overflow: auto; }
.tpl { padding: 8px 10px; border-radius: 6px; cursor: pointer; border: 1px solid transparent; }
.tpl:hover { background: rgba(128, 128, 128, 0.08); }
.tpl.sel { border-color: #6366f1; background: rgba(99, 102, 241, 0.08); }
.tpl-name { font-weight: 600; }
.tpl-sub { font-size: 12px; opacity: 0.65; }
.lbl { font-size: 12px; opacity: 0.7; margin-bottom: 2px; }
.preview { margin-top: 10px; max-height: 330px; overflow: auto; border: 1px solid rgba(128, 128, 128, 0.15); border-radius: 6px; padding: 6px 10px; }
.pv-stage { margin-bottom: 6px; }
.pv-head { font-weight: 600; font-size: 13px; }
.pv-task { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 1px 0 1px 14px; }
.pv-role { opacity: 0.6; white-space: nowrap; }
.pv-role.miss { color: #d97706; opacity: 1; }
@media (max-width: 768px) {
  .t-who, .t-date { display: none; }
  .prog { width: 100%; }
}
</style>
