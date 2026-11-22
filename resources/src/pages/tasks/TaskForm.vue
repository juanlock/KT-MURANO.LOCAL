<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="[projectTitle || $t('Tasks'), isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :span="24">
            <a-form-item :label="$t('Title')" name="title">
              <a-input v-model:value="form.title" />
            </a-form-item>
          </a-col>
          <a-col v-if="!fromProject" :span="24">
            <a-form-item :label="tr('Project', 'Proyecto')" name="project_id">
              <a-select v-model:value="form.project_id" show-search option-filter-prop="label" :options="projectOptions" @change="onProjectChange" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="7">
            <a-form-item :label="tr('Project stage', 'Etapa del proyecto')" name="milestone_id">
              <a-select v-model:value="form.milestone_id" allow-clear :options="stageOptions" :placeholder="tr('No stage', 'Sin etapa')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="7">
            <a-form-item :label="tr('Objective', 'Objetivo')" name="objective_id">
              <a-select v-model:value="form.objective_id" allow-clear :options="objectiveOptions" :placeholder="tr('No objective', 'Sin objetivo')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="10">
            <a-form-item :label="tr('Assigned to', 'Asignada a')" name="assigned_to" :extra="!isEdit && managerId ? tr('By default, the project manager.', 'Por defecto, el jefe de proyecto.') : ''">
              <a-select
                v-model:value="form.assigned_to" mode="multiple" option-filter-prop="label"
                :options="employeeOptions" :loading="loadingEmployees" :disabled="!form.company_id" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('start_date')" name="start_date">
              <a-date-picker v-model:value="form.start_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Finish_Date')" name="end_date">
              <a-date-picker v-model:value="form.end_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Status')" name="status">
              <a-select v-model:value="form.status" :options="PROJECT_STATUSES()" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Priority', 'Prioridad')">
              <a-select v-model:value="form.priority" :options="TASK_PRIORITIES()" allow-clear :placeholder="tr('None', 'Ninguna')" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="tr('Estimated hours', 'Horas estimadas')">
              <a-input-number v-model:value="form.estimated_hour" :min="0" :step="0.5" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="tr('Progress %', 'Avance %')" :extra="tr('Set to 100 automatically when completed', 'Se pone en 100 automáticamente al completarse')">
              <a-slider v-model:value="form.task_progress" :min="0" :max="100" :step="5" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')" name="description">
              <a-textarea v-model:value="form.description" :rows="3" />
            </a-form-item>
          </a-col>
        </a-row>

        <div v-if="isEdit && form.project_id" class="docs">
          <div class="docs-title">{{ tr('Documents of this task', 'Documentos de esta tarea') }}</div>
          <ProjectDocuments :project-id="Number(form.project_id)" :task-id="Number(id)" />
        </div>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="goBack">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Mirror of ProjectForm with project_id instead of client (no key trap here).
 * Create: GET tasks/create → {projects, companies}; POST tasks.
 * Edit: GET tasks/{id}/edit; PUT tasks/{id}. Employees per company via
 * Get_employees_by_company (capital G, shared with projects).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { PROJECT_STATUSES } from '../projects/projectStatus';
import http from '../../lib/http';
import { TASK_PRIORITIES } from '../projects/workspaceOptions';
import ProjectDocuments from '../projects/ProjectDocuments.vue';
import { useTr } from '../../lib/useTr';

const { t } = useI18n();
const tr = useTr();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const loadingEmployees = ref(false);
const submitting = ref(false);
const formRef = ref();

const projects = ref([]);
const companies = ref([]);
const employees = ref([]);

const form = ref({
  title: '', project_id: undefined, company_id: undefined, assigned_to: [], milestone_id: undefined, objective_id: undefined,
  start_date: null, end_date: null, status: 'not_started', description: '',
  // Columns that exist in the tasks table but were never written until now.
  priority: 'medium', estimated_hour: null, task_progress: 0,
});

// Opened from a project (?project_id=): the task belongs to it, no picker; Save / Cancel go back to the project.
const fromProject = computed(() => Number(route.query.project_id) || null);
const projectTitle = computed(() => (projects.value.find(p => p.id === form.value.project_id) || {}).title || '');
const projectOptions = computed(() => projects.value.map(p => ({ value: p.id, label: p.title || p.name })));
// The project's members first (with their role; the client's people too), then the rest of the project company's staff.
const meta = ref({ members: [], staff: [], stages: [], manager_id: null });
const managerId = computed(() => meta.value.manager_id);
const objectiveOptions = computed(() => (meta.value.objectives || []).map(o => ({ value: o.id, label: o.title })));
const stageOptions = computed(() => (meta.value.stages || []).map(s => ({ value: s.id, label: s.title })));
const employeeOptions = computed(() => {
  const m = meta.value.members || [];
  if (!m.length && !(meta.value.staff || []).length) return employees.value.map(e => ({ value: e.id, label: e.username || e.name }));
  const crown = x => (x.is_manager ? ' ★' : '');
  return [
    ...m.map(x => ({ value: x.id, label: `${x.name}${x.role ? ' · ' + x.role : ''}${x.side === 'client' && x.company_name ? ' (' + x.company_name + ')' : ''}${crown(x)}` })),
    ...(meta.value.staff || []).map(x => ({ value: x.id, label: x.name })),
  ];
});

async function loadMeta(pid, isNew) {
  if (!pid) { meta.value = { members: [], staff: [], stages: [], manager_id: null }; return; }
  loadingEmployees.value = true;
  try {
    meta.value = await http.get(`projects/${pid}/task-meta`);
    if (isNew && !form.value.assigned_to.length && meta.value.manager_id) form.value.assigned_to = [meta.value.manager_id];
    // A task added into a stage: by default it runs from today to the stage's end.
    const stage = (meta.value.stages || []).find(s => s.id === form.value.milestone_id);
    if (isNew && stage && stage.due_date && !form.value.end_date) {
      const today = new Date().toISOString().slice(0, 10);
      const due = String(stage.due_date).slice(0, 10);
      form.value.start_date = form.value.start_date || (today <= due ? today : due);
      form.value.end_date = due;
    }
  } catch (e) { /* the old employee list stays */ } finally { loadingEmployees.value = false; }
}

const rules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
  project_id: [{ required: true, message: t('Field_is_required') }],
  status: [{ required: true, message: t('Field_is_required') }],
  // The server needs both dates (2026-11-19).
  start_date: [{ required: true, message: t('Field_is_required') }],
  end_date: [
    { required: true, message: t('Field_is_required') },
    { validator: (_r, v) => (!v || !form.value.start_date || v >= form.value.start_date ? Promise.resolve()
      : Promise.reject(tr('The end date cannot be before the start date', 'La fecha de fin no puede ser anterior a la de inicio'))) },
  ],
}));

async function loadEmployees(companyId) {
  if (!companyId) {
    employees.value = [];
    return;
  }
  loadingEmployees.value = true;
  try {
    const data = await http.get('Get_employees_by_company', { id: companyId });
    employees.value = Array.isArray(data) ? data : [];
  } catch (e) {
    employees.value = [];
  } finally {
    loadingEmployees.value = false;
  }
}

// The task's company is its project's (the server sets it the same way).
function companyOf(projectId) {
  return (projects.value.find(p => p.id === projectId) || {}).company_id;
}

function onProjectChange() {
  form.value.assigned_to = [];
  form.value.company_id = companyOf(form.value.project_id);
  form.value.milestone_id = Number(route.query.milestone_id) || undefined;
  loadEmployees(form.value.company_id);
  loadMeta(form.value.project_id, !isEdit.value);
}

function goBack() {
  const pid = fromProject.value;
  const back = ['milestones', 'list', 'board', 'documents'].includes(route.query.back) ? route.query.back : 'board';
  router.push(pid ? `/projects/${pid}?tab=${back}` : '/tasks');
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const body = {
    title: form.value.title,
    description: form.value.description,
    project_id: form.value.project_id,
    company_id: form.value.company_id,
    assigned_to: form.value.assigned_to,
    milestone_id: form.value.milestone_id || null,
    objective_id: form.value.objective_id || null,
    start_date: form.value.start_date,
    end_date: form.value.end_date,
    status: form.value.status,
    priority: form.value.priority || 'medium',
    estimated_hour: form.value.estimated_hour,
    task_progress: form.value.status === 'completed' ? 100 : form.value.task_progress,
  };
  try {
    if (isEdit.value) {
      await http.put(`tasks/${id.value}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('tasks', body);
      message.success(t('Successfully_Created'));
    }
    goBack();
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat() : [];
    message.error(errs[0] || e?.data?.message || t('InvalidData'), 6);
  } finally {
    submitting.value = false;
  }
}

async function bootstrap() {
  loadingRecord.value = true;
  try {
    if (isEdit.value) {
      const data = await http.get(`tasks/${id.value}/edit`);
      projects.value = data.projects || [];
      companies.value = data.companies || [];
      employees.value = data.employees || [];
      const x = data.task || {};
      form.value = {
        title: x.title || '',
        project_id: x.project_id,
        company_id: x.company_id,
        assigned_to: data.assigned_employees || [],
        milestone_id: x.milestone_id || undefined,
        objective_id: x.objective_id || undefined,
        start_date: x.start_date || null,
        end_date: x.end_date || null,
        status: x.status || 'not_started',
        description: x.description || '',
        priority: x.priority || 'medium',
        estimated_hour: x.estimated_hour,
        task_progress: Number(x.task_progress) || 0,
      };
      loadMeta(x.project_id, false);
    } else {
      const data = await http.get('tasks/create');
      projects.value = data.projects || [];
      companies.value = data.companies || [];
      if (fromProject.value) {
        form.value.project_id = fromProject.value;
        onProjectChange();
      }
    }
  } catch (e) {
    if (isEdit.value) {
      message.error(t('InvalidData'));
      goBack();
    } else {
      message.warning(t('InvalidData'));
    }
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>

<style scoped>
.docs { margin: 4px 0 12px; padding-top: 10px; border-top: 1px solid rgba(128, 128, 128, 0.15); }
.docs-title { font-weight: 600; margin-bottom: 8px; }
</style>
