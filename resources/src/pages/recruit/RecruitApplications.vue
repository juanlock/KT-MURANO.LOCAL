<template>
  <div class="page">
    <PageHeader :title="$t('Applications')" :breadcrumb="[$t('Recruit'), $t('Applications')]">
      <template #extra>
        <a-button type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="filter-label">{{ $t('Stage') }}</div>
      <a-select v-model:value="stageFilter" style="width: 240px; max-width: 100%" @change="crud.reload()">
        <a-select-option value="">{{ $t('All') }}</a-select-option>
        <a-select-option v-for="s in STAGES" :key="s" :value="s">
          <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
        </a-select-option>
      </a-select>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'candidate'">
          {{ record.candidate ? record.candidate.first_name + ' ' + record.candidate.last_name : '-' }}
        </template>
        <template v-else-if="column.key === 'job'">
          {{ record.job ? record.job.title : '-' }}
        </template>
        <template v-else-if="column.key === 'stage'">
          <a-select
            :value="record.stage" size="small" style="width: 150px"
            @change="v => changeStage(record.id, v)"
          >
            <a-select-option v-for="s in STAGES" :key="s" :value="s">
              <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
            </a-select-option>
          </a-select>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button size="small" @click="openModal(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="crud.remove(record, { label: record.candidate ? record.candidate.first_name + ' ' + record.candidate.last_name : '' })">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen" :title="editmode ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" @ok="submit"
    >
      <a-form ref="modalFormRef" :model="application" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Candidate') + ' *'" name="candidate_id">
          <a-select
            v-model:value="application.candidate_id" :placeholder="$t('Choose_Candidate')"
            :options="candidates.map(c => ({ label: `${c.first_name} ${c.last_name} (${c.email})`, value: c.id }))"
            show-search option-filter-prop="label"
          />
        </a-form-item>
        <a-form-item :label="$t('Job') + ' *'" name="job_id">
          <a-select
            v-model:value="application.job_id" :placeholder="$t('Choose_Job')"
            :options="jobs.map(j => ({ label: j.title, value: j.id }))"
            show-search option-filter-prop="label"
          />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('Stage')">
              <a-select v-model:value="application.stage">
                <a-select-option v-for="s in STAGES" :key="s" :value="s">
                  <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
                </a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Applied_Date')">
              <a-input v-model:value="application.applied_date" type="date" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Rating')">
          <a-rate v-model:value="application.rating" :count="5" />
        </a-form-item>
        <a-form-item :label="$t('Cover_Letter')">
          <a-textarea v-model:value="application.cover_letter" :rows="2" />
        </a-form-item>
        <a-form-item :label="$t('Notes')">
          <a-textarea v-model:value="application.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Recruit applications — GET recruit/applications (+stage filter) →
 * {applications, totalRows}; selects from recruit/jobs_all +
 * recruit/candidates_all (plain arrays). Inline stage change PUT
 * recruit/applications/{id}/stage {stage}; save POST/PUT
 * recruit/applications[/{id}]; bulk delete standard by_selection.
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

const STAGES = ['applied', 'screening', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];

const stageFilter = ref('');
const crud = useCrudTable('recruit/applications', {
  rowsKey: 'applications',
  params: () => ({ stage: stageFilter.value }),
});

const jobs = ref([]);
const candidates = ref([]);
const modalOpen = ref(false);
const editmode = ref(false);
const saving = ref(false);
const modalFormRef = ref();

const emptyApplication = () => ({
  id: '', candidate_id: null, job_id: null, stage: 'applied',
  applied_date: '', rating: 0, cover_letter: '', notes: '',
});
const application = ref(emptyApplication());

const rules = computed(() => ({
  candidate_id: [{ required: true, message: t('Field_is_required') }],
  job_id: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Candidate'), key: 'candidate' },
  { title: t('Job'), key: 'job' },
  { title: t('Stage'), key: 'stage', width: 170 },
  { title: t('Applied_Date'), dataIndex: 'applied_date', key: 'applied_date', sorter: true },
  { title: t('Rating'), dataIndex: 'rating', key: 'rating', align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function formatLabel(v) { return v ? v.replace(/_/g, ' ') : '-'; }

async function loadFormData() {
  try { jobs.value = await http.get('recruit/jobs_all'); } catch (e) { jobs.value = []; }
  try { candidates.value = await http.get('recruit/candidates_all'); } catch (e) { candidates.value = []; }
}
function openModal(row = null) {
  editmode.value = !!row;
  application.value = row
    ? { ...emptyApplication(), ...row, rating: Number(row.rating) || 0 }
    : emptyApplication();
  loadFormData();
  modalFormRef.value?.clearValidate();
  modalOpen.value = true;
}
async function changeStage(id, stage) {
  try {
    await http.put(`recruit/applications/${id}/stage`, { stage });
    message.success(t('Updated_in_successfully'));
    await crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  }
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
      await http.put(`recruit/applications/${application.value.id}`, application.value);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('recruit/applications', application.value);
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
