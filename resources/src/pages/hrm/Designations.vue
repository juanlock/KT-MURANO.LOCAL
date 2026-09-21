<template>
  <div class="page">
    <PageHeader :title="$t('Designations')" :breadcrumb="[$t('HRM'), $t('Designations')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('Submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Designation')" name="designation">
          <a-input v-model:value="form.designation" />
        </a-form-item>
        <a-form-item :label="$t('Company')" name="company_id">
          <a-select
            v-model:value="form.company_id"
            show-search
            option-filter-prop="label"
            :options="companyOptions"
            @change="onCompanyChange"
          />
        </a-form-item>
        <a-form-item :label="$t('Department')" name="department_id">
          <a-select
            v-model:value="form.department_id"
            show-search
            option-filter-prop="label"
            :options="departmentOptions"
            :loading="loadingDepartments"
            :disabled="!form.company_id"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Designations mirrors Departments with one payload trap: the API expects the
 * department id under the key `department` (not department_id). Cascade:
 * GET core/get_departments_by_company?id= → plain array.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();
const crud = useCrudTable('designations', { rowsKey: 'designations' });

const columns = computed(() => [
  { title: t('Designation'), dataIndex: 'designation', key: 'designation', sorter: true },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('Department'), dataIndex: 'department_name', key: 'department_name' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const companies = ref([]);
const departments = ref([]);
const loadingDepartments = ref(false);

const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));
const departmentOptions = computed(() =>
  departments.value.map(d => ({ value: d.id, label: d.department || d.name }))
);

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, designation: '', company_id: undefined, department_id: undefined });
const form = ref(emptyForm());

const rules = computed(() => ({
  designation: [{ required: true, message: t('Field_is_required') }],
  company_id: [{ required: true, message: t('Field_is_required') }],
  department_id: [{ required: true, message: t('Field_is_required') }],
}));

async function loadCompanies() {
  try {
    const data = await http.get('designations/create');
    companies.value = data.companies || [];
  } catch (e) { /* select stays empty */ }
}

async function loadDepartments(companyId) {
  if (!companyId) {
    departments.value = [];
    return;
  }
  loadingDepartments.value = true;
  try {
    const data = await http.get('core/get_departments_by_company', { id: companyId });
    departments.value = Array.isArray(data) ? data : [];
  } catch (e) {
    departments.value = [];
  } finally {
    loadingDepartments.value = false;
  }
}

function onCompanyChange() {
  form.value.department_id = undefined;
  loadDepartments(form.value.company_id);
}

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  departments.value = [];
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    id: record.id,
    designation: record.designation || '',
    company_id: record.company_id,
    department_id: record.department_id,
  };
  loadDepartments(record.company_id);
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  // API expects `department`, not department_id — legacy payload trap.
  const body = {
    designation: form.value.designation,
    company_id: form.value.company_id,
    department: form.value.department_id,
  };
  try {
    if (editMode.value) {
      await http.put(`designations/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('designations', body);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  crud.fetchRows();
  loadCompanies();
});
</script>
