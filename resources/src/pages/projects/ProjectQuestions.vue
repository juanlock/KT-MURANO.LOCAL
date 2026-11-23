<template>
  <!-- Project questions for the team (user, 2026-11-23: "¿cómo genero una nueva pregunta para los miembros del proyecto..
       desde las tareas?"): in a task's panel (only that task's) and as the project's "Preguntas" view (all). -->
  <div class="pq">
    <div class="pq-bar">
      <span class="muted">{{ tr(`${pendingCount} pending · ${rows.length - pendingCount} answered`, `${pendingCount} pendientes · ${rows.length - pendingCount} respondidas`) }}</span>
      <a-button v-if="!readonly" type="primary" size="small" @click="openNew"><PlusOutlined /> {{ tr('New question', 'Nueva pregunta') }}</a-button>
    </div>
    <a-spin :spinning="loading">
      <div v-for="q in rows" :key="q.id" class="pq-row" :class="q.status">
        <div class="pq-top">
          <a-tag :color="q.status === 'pending' ? 'red' : 'green'">{{ q.status === 'pending' ? tr('Pending', 'Pendiente') : tr('Answered', 'Respondida') }}</a-tag>
          <a-tag v-if="q.urgent" color="volcano">{{ tr('Urgent', 'Urgente') }}</a-tag>
          <span class="pq-who"><UserOutlined /> {{ q.person }}<span v-if="q.person_company" class="muted"> · {{ q.person_company }}</span></span>
          <span v-if="!taskId && q.task" class="muted pq-task">· {{ q.task }}</span>
          <span class="grow" />
          <a-space v-if="!readonly" :size="0">
            <a-tooltip v-if="q.status === 'pending'" :title="$t('Edit')"><a-button type="text" size="small" @click="openEdit(q)"><EditOutlined /></a-button></a-tooltip>
            <a-tooltip v-else :title="tr('Ask again', 'Volver a preguntar')"><a-button type="text" size="small" @click="reopen(q)"><RedoOutlined /></a-button></a-tooltip>
            <a-popconfirm :title="tr('Delete this question?', '¿Eliminar esta pregunta?')" @confirm="remove(q)">
              <a-button type="text" size="small" danger><DeleteOutlined /></a-button>
            </a-popconfirm>
          </a-space>
        </div>
        <div class="pq-q">{{ q.question }} <span class="muted kind">({{ kindLabel(q.kind) }})</span></div>
        <div v-if="q.status === 'answered'" class="pq-a">
          <CheckCircleFilled /> {{ q.answer === 'Correcto' && q.current_value ? `${q.answer} (${q.current_value})` : q.answer }}
          <span class="muted"> · {{ dateTime(q.answered_at) }}</span>
          <div v-if="q.comment" class="muted">{{ q.comment }}</div>
        </div>
      </div>
      <a-empty v-if="!loading && !rows.length" :description="tr('No questions yet', 'Todavía no hay preguntas')" />
    </a-spin>

    <a-modal v-model:open="form.open" :title="form.id ? tr('Edit question', 'Editar pregunta') : tr('New question', 'Nueva pregunta')"
      :ok-text="tr('Save', 'Guardar')" :cancel-text="tr('Cancel', 'Cancelar')" :confirm-loading="form.saving" width="560px" @ok="save">
      <a-form layout="vertical" class="tight">
        <a-form-item :label="tr('Ask to', 'Preguntar a')" required>
          <a-select v-model:value="form.user_id" show-search option-filter-prop="label" :options="memberOptions" :placeholder="tr('A member of the project', 'Un miembro del proyecto')" />
        </a-form-item>
        <a-form-item :label="tr('Task (the answer is recorded there)', 'Tarea (ahí queda la respuesta)')">
          <a-select v-model:value="form.task_id" allow-clear show-search option-filter-prop="label" :options="taskOptions" />
        </a-form-item>
        <a-row :gutter="8">
          <a-col :span="14"><a-form-item :label="tr('Kind', 'Tipo')"><a-select v-model:value="form.kind" :options="kindOptions" /></a-form-item></a-col>
          <a-col :span="10"><a-form-item :label="tr('Urgent', 'Urgente')"><a-switch v-model:checked="form.urgent" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="tr('Question', 'Pregunta')" required>
          <a-textarea v-model:value="form.question" :auto-size="{ minRows: 2, maxRows: 6 }" />
        </a-form-item>
        <a-form-item v-if="form.kind === 'choice'" :label="tr('Options (type and press Enter)', 'Opciones (escriba y presione Enter)')" required>
          <a-select v-model:value="form.options" mode="tags" :open="false" :token-separators="[';']" />
        </a-form-item>
        <a-form-item v-if="form.kind === 'confirm'" :label="tr('The value we have (they confirm or correct it)', 'El dato que tenemos (lo confirma o lo corrige)')" required>
          <a-input v-model:value="form.current_value" />
        </a-form-item>
        <a-form-item :label="tr('Help (optional)', 'Ayuda (opcional)')">
          <a-input v-model:value="form.help" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The project team's questions (ProjectQuestionController index / store / update / destroy): who was asked what, its
 * state and the answer; "Nueva pregunta" asks a member of the project (any company), tied to a task. The member answers
 * from the PREGUNTAS window (ProjectQuestionsModal) and the answer becomes an activity of the task.
 */
import { ref, reactive, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, RedoOutlined, UserOutlined, CheckCircleFilled } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';

const props = defineProps({
  projectId: { type: Number, required: true },
  taskId: { type: Number, default: null },
  readonly: { type: Boolean, default: false },
});
const tr = useTr();
const { dateTime } = useFormat();

const loading = ref(false);
const rows = ref([]);
const members = ref([]);
const tasks = ref([]);
const pendingCount = computed(() => rows.value.filter(q => q.status === 'pending').length);
const KINDS = [
  ['text', 'Text', 'Texto'], ['number', 'Number', 'Número'], ['yesno', 'Yes / No', 'Sí / No'],
  ['choice', 'Choice', 'Opciones'], ['confirm', 'Confirm a value we have', 'Confirmar un dato que tenemos'],
];
const kindOptions = computed(() => KINDS.map(([v, en, es]) => ({ value: v, label: tr(en, es) })));
const kindLabel = k => { const x = KINDS.find(r => r[0] === k); return x ? tr(x[1], x[2]) : k; };
const memberOptions = computed(() => sortByLabel(members.value.map(m => ({ value: m.user_id, label: m.company ? `${m.name} · ${m.company}` : m.name }))));
const taskOptions = computed(() => sortByLabel(tasks.value.map(t => ({ value: t.id, label: t.title }))));

async function load() {
  loading.value = true;
  try {
    const r = await http.get(`projects/${props.projectId}/questions`, props.taskId ? { task_id: props.taskId } : {});
    rows.value = r.questions || [];
    members.value = r.members || [];
    tasks.value = r.tasks || [];
  } catch (e) {
    rows.value = [];
  } finally { loading.value = false; }
}
watch(() => [props.projectId, props.taskId], load, { immediate: true });

const blank = () => ({ open: true, saving: false, id: null, user_id: undefined, task_id: props.taskId || undefined, kind: 'text', urgent: false,
  question: '', options: [], current_value: '', help: '' });
const form = reactive({ ...blank(), open: false });
function openNew() { Object.assign(form, blank()); }
function openEdit(q) {
  Object.assign(form, { open: true, saving: false, id: q.id, user_id: q.user_id, task_id: q.task_id || undefined, kind: q.kind, urgent: !!q.urgent,
    question: q.question, options: q.options || [], current_value: q.current_value || '', help: q.help || '' });
}
async function save() {
  if (!form.user_id || !form.question.trim()) { message.warning(tr('Choose who and write the question', 'Elija a quién y escriba la pregunta')); return; }
  form.saving = true;
  const body = { user_id: form.user_id, task_id: form.task_id || null, kind: form.kind, urgent: form.urgent, question: form.question.trim(),
    options: form.kind === 'choice' ? form.options : null, current_value: form.kind === 'confirm' ? form.current_value : null, help: form.help || null };
  try {
    if (form.id) await http.put(`project-questions/${form.id}`, body);
    else await http.post(`projects/${props.projectId}/questions`, body);
    message.success(tr('Question saved: it shows in their PREGUNTAS window', 'Pregunta guardada: aparece en su ventana PREGUNTAS'));
    form.open = false;
    load();
  } catch (e) { message.error(e?.data?.message || tr('Could not save', 'No se pudo guardar')); } finally { form.saving = false; }
}
async function reopen(q) {
  try { await http.put(`project-questions/${q.id}`, { reopen: 1 }); load(); } catch (e) { message.error(e?.data?.message || 'Error'); }
}
async function remove(q) {
  try { await http.delete(`project-questions/${q.id}`); load(); } catch (e) { message.error(e?.data?.message || 'Error'); }
}
</script>

<style scoped>
.pq-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 8px; }
.muted { opacity: 0.65; font-size: 12px; }
.pq-row { border: 1px solid rgba(128, 128, 128, 0.22); border-radius: 6px; padding: 6px 10px; margin-bottom: 6px; }
.pq-row.pending { border-left: 3px solid #e5484d; }
.pq-row.answered { border-left: 3px solid #52c41a; }
.pq-top { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pq-who { font-size: 12.5px; font-weight: 600; }
.pq-task { max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.grow { flex: 1; }
.pq-q { margin-top: 3px; }
.kind { font-size: 11px; }
.pq-a { margin-top: 4px; color: #389e0d; font-weight: 600; }
.pq-a .muted { color: inherit; font-weight: 400; }
.tight :deep(.ant-form-item) { margin-bottom: 8px; }
</style>
