<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="[$t('Projects'), isEdit ? $t('Edit') : $t('Add')]"
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
            <a-form-item :label="$t('Customer')" name="client_id">
              <a-select v-model:value="form.client_id" show-search option-filter-prop="label" :options="clientOptions" />
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
          <a-col :span="24">
            <a-form-item :label="$t('Description')" name="description">
              <a-textarea v-model:value="form.description" :rows="3" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/projects')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Create: GET projects/create → {clients, companies}; POST projects.
 * Edit: GET projects/{id}/edit → {project, clients, companies, employees,
 * assigned_employees}; PUT projects/{id}.
 * TRAPS: payload sends the client id under key `client` (not client_id), and
 * the per-company employee list is `GET Get_employees_by_company?id=`
 * (capital G — a DIFFERENT endpoint from HRM's core/get_employees_by_company).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { PROJECT_STATUSES } from './projectStatus';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const loadingEmployees = ref(false);
const submitting = ref(false);
const formRef = ref();

const clients = ref([]);
const companies = ref([]);
const employees = ref([]);

const form = ref({
  title: '', client_id: undefined, company_id: undefined, assigned_to: [],
  start_date: null, end_date: null, status: 'not_started', description: '',
});

const clientOptions = computed(() => clients.value.map(c => ({ value: c.id, label: c.name })));
const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));
const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.username || e.name })));

const rules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
  client_id: [{ required: true, message: t('Field_is_required') }],
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
    const data = await http.get('Get_employees_by_company', { id: companyId }); // sic — capital G
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
  // Legacy payload key is `client`, not client_id.
  const body = {
    title: form.value.title,
    description: form.value.description,
    client: form.value.client_id,
    company_id: form.value.company_id,
    assigned_to: form.value.assigned_to,
    start_date: form.value.start_date,
    end_date: form.value.end_date,
    status: form.value.status,
  };
  try {
    if (isEdit.value) {
      await http.put(`projects/${id.value}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('projects', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/projects');
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
      const data = await http.get(`projects/${id.value}/edit`);
      clients.value = data.clients || [];
      companies.value = data.companies || [];
      employees.value = data.employees || [];
      const p = data.project || {};
      form.value = {
        title: p.title || '',
        client_id: p.client_id,
        company_id: p.company_id,
        assigned_to: data.assigned_employees || [],
        start_date: p.start_date || null,
        end_date: p.end_date || null,
        status: p.status || 'not_started',
        description: p.description || '',
      };
    } else {
      const data = await http.get('projects/create');
      clients.value = data.clients || [];
      companies.value = data.companies || [];
    }
  } catch (e) {
    if (isEdit.value) {
      message.error(t('InvalidData'));
      router.push('/projects');
    } else {
      message.warning(t('InvalidData'));
    }
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>
