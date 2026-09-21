<template>
  <div class="page">
    <PageHeader :title="$t('Jobs')" :breadcrumb="[$t('Recruit'), $t('Jobs')]">
      <template #extra>
        <a-button type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="filter-label">{{ $t('Status') }}</div>
      <a-select v-model:value="statusFilter" style="width: 240px; max-width: 100%" @change="crud.reload()">
        <a-select-option value="">{{ $t('All') }}</a-select-option>
        <a-select-option value="draft">{{ $t('Draft') }}</a-select-option>
        <a-select-option value="open">{{ $t('Open') }}</a-select-option>
        <a-select-option value="on_hold">{{ $t('On_Hold') }}</a-select-option>
        <a-select-option value="closed">{{ $t('Closed') }}</a-select-option>
      </a-select>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'category'">
          {{ record.category ? record.category.name : '-' }}
        </template>
        <template v-else-if="column.key === 'job_type'">
          <a-tag color="cyan" style="text-transform: capitalize">{{ formatLabel(record.job_type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)" style="text-transform: capitalize">{{ formatLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button size="small" @click="openModal(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="crud.remove(record, { label: record.title })">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen" :title="editmode ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" width="860px" @ok="submit"
    >
      <a-form ref="modalFormRef" :model="job" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Job_Title') + ' *'" name="title">
              <a-input v-model:value="job.title" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Categorie')">
              <a-select
                v-model:value="job.category_id" :placeholder="$t('Choose_Category')"
                :options="categories.map(c => ({ label: c.name, value: c.id }))"
                show-search option-filter-prop="label" allow-clear
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Job_Type') + ' *'">
              <a-select v-model:value="job.job_type">
                <a-select-option value="full_time">{{ $t('Full_Time') }}</a-select-option>
                <a-select-option value="part_time">{{ $t('Part_Time') }}</a-select-option>
                <a-select-option value="contract">{{ $t('Contract') }}</a-select-option>
                <a-select-option value="internship">{{ $t('Internship') }}</a-select-option>
                <a-select-option value="remote">{{ $t('Remote') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Experience_Level')">
              <a-select v-model:value="job.experience_level">
                <a-select-option value="entry">{{ $t('Entry') }}</a-select-option>
                <a-select-option value="mid">{{ $t('Mid') }}</a-select-option>
                <a-select-option value="senior">{{ $t('Senior') }}</a-select-option>
                <a-select-option value="lead">{{ $t('Lead') }}</a-select-option>
                <a-select-option value="manager">{{ $t('Manager') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Status')">
              <a-select v-model:value="job.status">
                <a-select-option value="draft">{{ $t('Draft') }}</a-select-option>
                <a-select-option value="open">{{ $t('Open') }}</a-select-option>
                <a-select-option value="on_hold">{{ $t('On_Hold') }}</a-select-option>
                <a-select-option value="closed">{{ $t('Closed') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Location')">
              <a-input v-model:value="job.location" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Vacancies')">
              <a-input-number v-model:value="job.vacancies" :min="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Deadline')">
              <a-input v-model:value="job.deadline" type="date" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Salary_Min')">
              <a-input-number v-model:value="job.salary_min" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Salary_Max')">
              <a-input-number v-model:value="job.salary_max" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Currency')">
              <a-input v-model:value="job.currency" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description') + ' *'" name="description">
              <a-textarea v-model:value="job.description" :rows="3" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Requirements')">
              <a-textarea v-model:value="job.requirements" :rows="3" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Benefits')">
              <a-textarea v-model:value="job.benefits" :rows="3" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Recruit jobs — GET recruit/jobs (+status filter) → {jobs, totalRows};
 * categories for the select from recruit/categories_all (plain array). Save
 * POST/PUT recruit/jobs[/{id}] with the whole job object; bulk delete uses
 * the standard recruit/jobs/delete/by_selection.
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

const statusFilter = ref('');
const crud = useCrudTable('recruit/jobs', {
  rowsKey: 'jobs',
  params: () => ({ status: statusFilter.value }),
});

const categories = ref([]);
const modalOpen = ref(false);
const editmode = ref(false);
const saving = ref(false);
const modalFormRef = ref();

const emptyJob = () => ({
  id: '', title: '', category_id: null, job_type: 'full_time', experience_level: 'entry',
  status: 'draft', location: '', vacancies: 1, deadline: '', salary_min: null, salary_max: null,
  currency: 'USD', description: '', requirements: '', benefits: '',
});
const job = ref(emptyJob());

const rules = computed(() => ({
  title: [{ required: true, message: t('Field_is_required') }],
  description: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Job_Title'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('Categorie'), key: 'category' },
  { title: t('Job_Type'), key: 'job_type' },
  { title: t('Location'), dataIndex: 'location', key: 'location', sorter: true },
  { title: t('Status'), key: 'status' },
  { title: t('Applications'), dataIndex: 'applications_count', key: 'applications_count', align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function formatLabel(v) { return v ? v.replace(/_/g, ' ') : '-'; }
function statusColor(s) {
  return { open: 'success', draft: 'warning', on_hold: 'cyan', closed: 'default' }[s] || 'default';
}

async function loadCategories() {
  try {
    categories.value = await http.get('recruit/categories_all');
  } catch (e) {
    categories.value = [];
  }
}
function openModal(row = null) {
  editmode.value = !!row;
  job.value = row ? { ...emptyJob(), ...row, category_id: row.category_id || null } : emptyJob();
  loadCategories();
  modalFormRef.value?.clearValidate();
  modalOpen.value = true;
}
async function submit() {
  try {
    await modalFormRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  saving.value = true;
  try {
    if (editmode.value) {
      await http.put(`recruit/jobs/${job.value.id}`, job.value);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('recruit/jobs', job.value);
      message.success(t('Created_in_successfully'));
    }
    modalOpen.value = false;
    await crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
