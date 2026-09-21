<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="[$t('Tasks'), isEdit ? $t('Edit') : $t('Add')]"
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
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Projects')" name="project_id">
              <a-select v-model:value="form.project_id" show-search option-filter-prop="label" :options="projectOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Company')" name="company_id">
              <a-select
                v-model:value="form.company_id" show-search option-filter-prop="label"
                :options="companyOptions" @change="onCompanyChange" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Employee')" name="assigned_to">
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
              <a-select v-model:value="form.status" :options="PROJECT_STATUSES" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Priority">
              <a-select v-model:value="form.priority" :options="TASK_PRIORITIES" allow-clear placeholder="None" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Estimated hours">
              <a-input-number v-model:value="form.estimated_hour" :min="0" :step="0.5" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Progress %" extra="Set to 100 automatically when completed">
              <a-slider v-model:value="form.task_progress" :min="0" :max="100" :step="5" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')" name="description">
              <a-textarea v-model:value="form.description" :rows="3" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/tasks')">{{ $t('Cancel') }}</a-button>
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

const { t } = useI18n();
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
  title: '', project_id: undefined, company_id: undefined, assigned_to: [],
  start_date: null, end_date: null, status: 'not_started', description: '',
  // Columns that exist in the tasks table but were never written until now.
  priority: undefined, estimated_hour: null, task_progress: 0,
});

const projectOptions = computed(() => projects.value.map(p => ({ value: p.id, label: p.title || p.name })));
const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));
const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.username || e.name })));

const rules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
  project_id: [{ required: true, message: t('Field_is_required') }],
  company_id: [{ required: true, message: t('Field_is_required') }],
  status: [{ required: true, message: t('Field_is_required') }],
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

function onCompanyChange() {
  form.value.assigned_to = [];
  loadEmployees(form.value.company_id);
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
    start_date: form.value.start_date,
    end_date: form.value.end_date,
    status: form.value.status,
  };
  try {
    if (isEdit.value) {
      await http.put(`tasks/${id.value}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('tasks', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/tasks');
  } catch (e) {
    message.error(t('InvalidData'));
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
        start_date: x.start_date || null,
        end_date: x.end_date || null,
        status: x.status || 'not_started',
        description: x.description || '',
      };
    } else {
      const data = await http.get('tasks/create');
      projects.value = data.projects || [];
      companies.value = data.companies || [];
    }
  } catch (e) {
    if (isEdit.value) {
      message.error(t('InvalidData'));
      router.push('/tasks');
    } else {
      message.warning(t('InvalidData'));
    }
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>
