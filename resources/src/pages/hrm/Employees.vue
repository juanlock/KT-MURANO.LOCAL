<template>
  <div class="page">
    <PageHeader :title="$t('Employees')" :breadcrumb="[$t('hrm'), $t('Employees')]">
      <template #actions>
        <a-button v-if="auth.can('add_employee')" type="primary" @click="$router.push('/hrm/employees/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="24" :md="6">
          <div class="filter-label">{{ $t('username') }}</div>
          <a-input v-model:value="filterUsername" allow-clear @press-enter="crud.reload()" />
        </a-col>
        <a-col :xs="24" :md="6">
          <div class="filter-label">{{ $t('Employment_type') }}</div>
          <a-select
            v-model:value="filterEmploymentType"
            style="width: 100%"
            allow-clear
            :options="EMPLOYMENT_TYPES"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="6">
          <div class="filter-label">{{ $t('Company') }}</div>
          <a-select
            v-model:value="filterCompany"
            style="width: 100%"
            allow-clear
            show-search
            option-filter-prop="label"
            :options="companyOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="24" :md="6" style="display: flex; align-items: flex-end; gap: 8px">
          <a-button type="primary" @click="crud.reload()">
            <template #icon><SearchOutlined /></template>
            {{ $t('Filter') }}
          </a-button>
          <a-button @click="resetFilters">{{ $t('Reset') }}</a-button>
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :selectable="auth.can('delete_employee')">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'actions'">
          <a-space>
            <!-- Details page (1,500-line tabbed profile) stays legacy. -->
            <a-tooltip v-if="auth.can('view_employee')" :title="$t('View_Details')">
              <a-button type="text" size="small" @click="$router.push(`/hrm/employees/${record.id}/details`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('edit_employee')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/hrm/employees/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('delete_employee')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.username })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * GET employees → {employees, companies, totalRows}; extra filters username,
 * employment_type, company_id (company options ship in the list payload).
 * Per-action gates match legacy: add/edit/delete/view_employee. Sorting on the
 * name columns maps to the *_id fields, as legacy did.
 */
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, SearchOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import { EMPLOYMENT_TYPES } from './hrmVocab';

const { t } = useI18n();
const auth = useAuthStore();

const filterUsername = ref('');
const filterEmploymentType = ref(undefined);
const filterCompany = ref(undefined);

const crud = useCrudTable('employees', {
  rowsKey: 'employees',
  params: () => ({
    username: filterUsername.value || '',
    employment_type: filterEmploymentType.value || '',
    company_id: filterCompany.value || '',
  }),
});
crud.fetchRows();

const companyOptions = computed(() =>
  (crud.payload.value?.companies || []).map(c => ({ value: c.id, label: c.name }))
);

// The *_name columns are joined display values, not DB columns — sorting them
// would feed a bad field into orderBy() (500). Sort only real columns.
const columns = computed(() => [
  { title: t('FirstName'), dataIndex: 'firstname', key: 'firstname', sorter: true },
  { title: t('LastName'), dataIndex: 'lastname', key: 'lastname', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone', sorter: true },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('Department'), dataIndex: 'department_name', key: 'department_name' },
  { title: t('Designation'), dataIndex: 'designation_name', key: 'designation_name' },
  { title: t('Office_Shift'), dataIndex: 'office_shift_name', key: 'office_shift_name' },
  { title: t('Action'), key: 'actions', width: 130, align: 'center' },
]);

function resetFilters() {
  filterUsername.value = '';
  filterEmploymentType.value = undefined;
  filterCompany.value = undefined;
  crud.reload();
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
