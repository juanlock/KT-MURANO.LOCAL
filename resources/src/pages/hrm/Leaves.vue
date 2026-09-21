<template>
  <div class="page">
    <PageHeader :title="$t('Leave_request')" :breadcrumb="[$t('hrm'), $t('Leave_request')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'status'">
          <a-tag :color="leaveStatusColor(record.status)">{{ leaveStatusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.employee_name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editing ? $t('Edit') : $t('Add')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      width="720px"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Company')" name="company_id">
              <a-select
                v-model:value="form.company_id"
                :placeholder="$t('Choose_Company')"
                show-search
                option-filter-prop="label"
                :options="companyOptions"
                @change="onCompanyChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Department')" name="department_id">
              <a-select
                v-model:value="form.department_id"
                :placeholder="$t('Department')"
                show-search
                option-filter-prop="label"
                :options="departmentOptions"
                @change="onDepartmentChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Employee')" name="employee_id">
              <a-select
                v-model:value="form.employee_id"
                :placeholder="$t('Choose_Employee')"
                show-search
                option-filter-prop="label"
                :options="employeeOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Leave_Type')" name="leave_type_id">
              <a-select
                v-model:value="form.leave_type_id"
                :placeholder="$t('Choose_leave_type')"
                show-search
                option-filter-prop="label"
                :options="leaveTypeOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('start_date')" name="start_date">
              <a-date-picker v-model:value="form.start_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Finish_Date')" name="end_date">
              <a-date-picker v-model:value="form.end_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Status')" name="status">
              <a-select v-model:value="form.status" :placeholder="$t('Choose_status')" :options="LEAVE_STATUSES" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Attachment')">
              <a-upload
                :file-list="fileList"
                :before-upload="onFileSelected"
                :max-count="1"
                accept="image/*"
                @remove="fileList = []"
              >
                <a-button><UploadOutlined /> {{ $t('Attachment') }}</a-button>
              </a-upload>
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Leave_Reason')" name="reason">
              <a-textarea v-model:value="form.reason" :placeholder="$t('Enter_Reason_Leave')" :rows="3" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET leave → {leaves, totalRows} (employee_name, company_name,
 * department_name, leave_type_title, start_date, end_date, days, status).
 * Bootstrap GET leave/create → {companies, leave_types}; edit
 * GET leave/{id}/edit → {leave, companies, leave_types} (attachment blanked).
 * Cascades: company → core/get_departments_by_company; department →
 * get_employees_by_department (NOT under core/). Save is multipart:
 * POST leave, edit POST leave/{id} + _method=put. A 200 with
 * {isvalid:false} means the employee's remaining leave balance is
 * insufficient — surfaced with its own message, like legacy.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { LEAVE_STATUSES, leaveStatusColor, leaveStatusLabel } from './hrmVocab';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('leave', { rowsKey: 'leaves' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('Employee'), dataIndex: 'employee_name', key: 'employee_name' },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('Department'), dataIndex: 'department_name', key: 'department_name' },
  { title: t('Leave_Type'), dataIndex: 'leave_type_title', key: 'leave_type_title' },
  { title: t('start_date'), dataIndex: 'start_date', key: 'start_date', sorter: true },
  { title: t('Finish_Date'), dataIndex: 'end_date', key: 'end_date', sorter: true },
  { title: t('Days'), dataIndex: 'days', key: 'days', align: 'right' },
  { title: t('Status'), dataIndex: 'status', key: 'status' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const fileList = ref([]);

const companies = ref([]);
const departments = ref([]);
const employees = ref([]);
const leaveTypes = ref([]);

const form = ref(emptyForm());

function emptyForm() {
  return {
    company_id: undefined, department_id: undefined, employee_id: undefined,
    leave_type_id: undefined, start_date: null, end_date: null,
    reason: '', status: undefined, half_day: 0,
  };
}

const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));
const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.department })));
const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.username })));
const leaveTypeOptions = computed(() => leaveTypes.value.map(x => ({ value: x.id, label: x.title })));

const formRules = computed(() => ({
  company_id: [{ required: true, message: t('Field_is_required') }],
  department_id: [{ required: true, message: t('Field_is_required') }],
  employee_id: [{ required: true, message: t('Field_is_required') }],
  leave_type_id: [{ required: true, message: t('Field_is_required') }],
  start_date: [{ required: true, message: t('Field_is_required') }],
  status: [{ required: true, message: t('Field_is_required') }],
}));

function onFileSelected(file) {
  fileList.value = [file];
  return false;
}

async function loadBootstrap() {
  try {
    const data = await http.get('leave/create');
    companies.value = data.companies || [];
    leaveTypes.value = data.leave_types || [];
  } catch (e) { /* selects stay empty */ }
}

async function loadDepartments(companyId) {
  try {
    departments.value = await http.get('core/get_departments_by_company', { id: companyId }) || [];
  } catch (e) { departments.value = []; }
}

async function loadEmployees(departmentId) {
  try {
    employees.value = await http.get('get_employees_by_department', { id: departmentId }) || [];
  } catch (e) { employees.value = []; }
}

function onCompanyChange(value) {
  form.value.department_id = undefined;
  form.value.employee_id = undefined;
  departments.value = [];
  employees.value = [];
  if (value) loadDepartments(value);
}

function onDepartmentChange(value) {
  form.value.employee_id = undefined;
  employees.value = [];
  if (value) loadEmployees(value);
}

function openCreate() {
  editing.value = null;
  form.value = emptyForm();
  fileList.value = [];
  departments.value = [];
  employees.value = [];
  loadBootstrap();
  modalOpen.value = true;
}

async function openEdit(record) {
  editing.value = record;
  fileList.value = [];
  try {
    const data = await http.get(`leave/${record.id}/edit`);
    companies.value = data.companies || [];
    leaveTypes.value = data.leave_types || [];
    const l = data.leave || {};
    form.value = {
      company_id: l.company_id || undefined, department_id: l.department_id || undefined,
      employee_id: l.employee_id || undefined, leave_type_id: l.leave_type_id || undefined,
      start_date: l.start_date || null, end_date: l.end_date || null,
      reason: l.reason || '', status: l.status || undefined, half_day: l.half_day ? 1 : 0,
    };
    if (l.company_id) loadDepartments(l.company_id);
    if (l.department_id) loadEmployees(l.department_id);
    modalOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;

  const fd = new FormData();
  fd.append('company_id', form.value.company_id);
  fd.append('department_id', form.value.department_id);
  fd.append('employee_id', form.value.employee_id);
  fd.append('leave_type_id', form.value.leave_type_id);
  fd.append('start_date', form.value.start_date || '');
  fd.append('end_date', form.value.end_date || '');
  fd.append('reason', form.value.reason || '');
  fd.append('attachment', fileList.value.length ? (fileList.value[0].originFileObj || fileList.value[0]) : '');
  fd.append('half_day', form.value.half_day ? 1 : 0);
  fd.append('status', form.value.status);

  try {
    let data;
    if (editing.value) {
      fd.append('_method', 'put');
      data = await http.postForm(`leave/${editing.value.id}`, fd);
    } else {
      data = await http.postForm('leave', fd);
    }
    if (data && data.isvalid === false) {
      message.error(t('remaining_leaves_are_insufficient'));
      return;
    }
    message.success(t(editing.value ? 'Successfully_Updated' : 'Successfully_Created'));
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
