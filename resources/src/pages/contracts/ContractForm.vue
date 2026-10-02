<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="['Employee Contracts', isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="#" name="contract_number">
              <a-input v-model:value="form.contract_number" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Subject')" name="subject">
              <a-input v-model:value="form.subject" />
            </a-form-item>
          </a-col>

          <!-- Employee contracts only: customer contracts are Sales > Sales Contracts. -->
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Employee')" name="employee_id">
              <a-select v-model:value="form.employee_id" show-search option-filter-prop="label" :options="employeeOptions" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Projects')" name="project_id">
              <a-select v-model:value="form.project_id" allow-clear show-search option-filter-prop="label" :options="projectOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Categorie')" name="type">
              <a-select v-model:value="form.type" :options="CONTRACT_TYPES" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Amount')" name="value">
              <a-input-number v-model:value="form.value" style="width: 100%" :min="0" />
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

          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Status')" name="status">
              <a-select v-model:value="form.status" :options="CONTRACT_STATUSES" />
            </a-form-item>
          </a-col>


          <a-col :span="24">
            <a-form-item :label="$t('Description')" name="description">
              <RichTextEditor v-model="form.description" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/contracts')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * HRM > Employee Contracts (employees only -- customer contracts are Sales >
 * Sales Contracts). Create: GET contracts/create → {employees, projects,
 * next_contract_number}; POST contracts (blank contract_number is omitted so
 * the backend numbers it). Edit: GET contracts/{id}/edit → {contract,
 * employees, projects}; PUT contracts/{id}. Employees are the acting
 * company's. Backend 422s carry field errors — surfaced as messages.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import { CONTRACT_TYPES, CONTRACT_STATUSES } from './contractVocab';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();

const employees = ref([]);
const projects = ref([]);

const form = ref({
  contract_number: '', subject: '',
  employee_id: undefined, project_id: undefined,
  type: 'service', value: 0, start_date: null, end_date: null,
  status: 'draft', description: '',
});

const employeeOptions = computed(() => sortByLabel(employees.value.map(e => ({ value: e.id, label: [e.firstname, e.lastname].filter(Boolean).join(' ') || e.username || e.name }))));
const projectOptions = computed(() => sortByLabel(projects.value.map(p => ({ value: p.id, label: p.title || p.name }))));

const rules = computed(() => ({
  subject: [{ required: true, message: t('Field_is_required') }],
  employee_id: [{ required: true, message: t('Field_is_required') }],
  status: [{ required: true, message: t('Field_is_required') }],
}));

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const payload = { ...form.value };
  if (!payload.contract_number) delete payload.contract_number;
  try {
    if (isEdit.value) {
      await http.put(`contracts/${id.value}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('contracts', payload);
      message.success(t('Successfully_Created'));
    }
    router.push('/contracts');
  } catch (e) {
    // Surface backend validation errors like legacy does.
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function bootstrap() {
  loadingRecord.value = true;
  try {
    if (isEdit.value) {
      const data = await http.get(`contracts/${id.value}/edit`);
      employees.value = data.employees || [];
      projects.value = data.projects || [];
      const c = data.contract || {};
      form.value = { ...form.value, ...c };
    } else {
      const data = await http.get('contracts/create');
      employees.value = data.employees || [];
      projects.value = data.projects || [];
      form.value.contract_number = data.next_contract_number || '';
    }
  } catch (e) {
    if (isEdit.value) {
      message.error(t('InvalidData'));
      router.push('/contracts');
    } else {
      message.warning(t('InvalidData'));
    }
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>
