<template>
  <div class="page">
    <PageHeader :title="$t('Attendances')" :breadcrumb="[$t('hrm'), $t('Attendance')]">
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
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.employee_username })">
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
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
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
        <a-form-item :label="$t('Employee')" name="employee_id">
          <a-select
            v-model:value="form.employee_id"
            :placeholder="$t('Choose_Employee')"
            show-search
            option-filter-prop="label"
            :options="employeeOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('date')" name="date">
          <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Time_In')" name="clock_in">
              <a-time-picker v-model:value="form.clock_in" format="HH:mm" value-format="HH:mm" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Time_Out')" name="clock_out">
              <a-time-picker v-model:value="form.clock_out" format="HH:mm" value-format="HH:mm" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET attendances → {attendances, totalRows} (employee_username,
 * company_name, date, clock_in, clock_out, total_work). Companies from
 * GET attendances/create; employees per company from
 * core/get_employees_by_company?id=. POST/PUT attendances with
 * {company_id, employee_id, date, clock_in "HH:mm", clock_out "HH:mm"};
 * the backend computes total_work.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const crud = useCrudTable('attendances', { rowsKey: 'attendances' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('Employee'), dataIndex: 'employee_username', key: 'employee_username' },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Time_In'), dataIndex: 'clock_in', key: 'clock_in' },
  { title: t('Time_Out'), dataIndex: 'clock_out', key: 'clock_out' },
  { title: t('Work_Duration'), dataIndex: 'total_work', key: 'total_work' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();
const companies = ref([]);
const employees = ref([]);

const form = ref({
  company_id: undefined, employee_id: undefined,
  date: null, clock_in: null, clock_out: null,
});

const companyOptions = computed(() => companies.value.map(c => ({ value: c.id, label: c.name })));
const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.username })));

const formRules = computed(() => ({
  company_id: [{ required: true, message: t('Field_is_required') }],
  employee_id: [{ required: true, message: t('Field_is_required') }],
  date: [{ required: true, message: t('Field_is_required') }],
  clock_in: [{ required: true, message: t('Field_is_required') }],
  clock_out: [{ required: true, message: t('Field_is_required') }],
}));

async function loadCompanies() {
  try {
    const data = await http.get('attendances/create');
    companies.value = data.companies || [];
  } catch (e) { /* select stays empty */ }
}

async function loadEmployees(companyId) {
  try {
    employees.value = await http.get('core/get_employees_by_company', { id: companyId }) || [];
  } catch (e) { employees.value = []; }
}

function onCompanyChange(value) {
  form.value.employee_id = undefined;
  employees.value = [];
  if (value) loadEmployees(value);
}

function openCreate() {
  editing.value = null;
  form.value = { company_id: undefined, employee_id: undefined, date: null, clock_in: null, clock_out: null };
  employees.value = [];
  loadCompanies();
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = {
    company_id: record.company_id,
    employee_id: record.employee_id,
    date: record.date || null,
    clock_in: record.clock_in || null,
    clock_out: record.clock_out || null,
  };
  loadCompanies();
  loadEmployees(record.company_id);
  modalOpen.value = true;
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      await http.put(`attendances/${editing.value.id}`, form.value);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('attendances', form.value);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
