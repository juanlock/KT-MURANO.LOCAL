<template>
  <div class="page">
    <PageHeader :title="$t('Departments')" :breadcrumb="[$t('HRM'), $t('Departments')]">
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
        <a-form-item :label="$t('Department')" name="department">
          <a-input v-model:value="form.department" />
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
        <a-form-item :label="$t('Department_Head')" name="department_head">
          <a-select
            v-model:value="form.department_head"
            allow-clear
            show-search
            option-filter-prop="label"
            :options="employeeOptions"
            :loading="loadingEmployees"
            :disabled="!form.company_id"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Departments: list GET departments → {departments, totalRows}; companies from
 * GET /departments/create; department-head options load per company via
 * GET /core/get_employees_by_company?id= (plain array). Edit populates from
 * the row (it carries company_id + department_head), same as legacy.
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
const crud = useCrudTable('departments', { rowsKey: 'departments' });

const columns = computed(() => [
  { title: t('Department'), dataIndex: 'department', key: 'department', sorter: true },
  { title: t('Department_Head'), dataIndex: 'employee_head', key: 'employee_head' },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const companies = ref([]);
const employees = ref([]);
const loadingEmployees = ref(false);

const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));
const employeeOptions = computed(() =>
  employees.value.map(e => ({ value: e.id, label: e.username || e.name }))
);

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const emptyForm = () => ({ id: null, department: '', company_id: undefined, department_head: undefined });
const form = ref(emptyForm());

const rules = computed(() => ({
  department: [{ required: true, message: t('Field_is_required') }],
  company_id: [{ required: true, message: t('Field_is_required') }],
}));

async function loadCompanies() {
  try {
    const data = await http.get('departments/create');
    companies.value = data.companies || [];
  } catch (e) { /* select stays empty */ }
}

async function loadEmployees(companyId) {
  if (!companyId) {
    employees.value = [];
    return;
  }
  loadingEmployees.value = true;
  try {
    const data = await http.get('core/get_employees_by_company', { id: companyId });
    employees.value = Array.isArray(data) ? data : [];
  } catch (e) {
    employees.value = [];
  } finally {
    loadingEmployees.value = false;
  }
}

function onCompanyChange() {
  form.value.department_head = undefined;
  loadEmployees(form.value.company_id);
}

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  employees.value = [];
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    id: record.id,
    department: record.department || '',
    company_id: record.company_id,
    department_head: record.department_head || undefined,
  };
  loadEmployees(record.company_id);
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  const body = {
    department: form.value.department,
    company_id: form.value.company_id,
    department_head: form.value.department_head || '',
  };
  try {
    if (editMode.value) {
      await http.put(`departments/${form.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('departments', body);
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
