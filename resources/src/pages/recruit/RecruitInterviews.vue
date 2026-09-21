<template>
  <div class="page">
    <PageHeader :title="$t('Interviews')" :breadcrumb="[$t('Recruit'), $t('Interviews')]">
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
        <a-select-option v-for="s in STATUSES" :key="s" :value="s">
          <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
        </a-select-option>
      </a-select>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'candidate'">
          {{ candidateName(record) }}
        </template>
        <template v-else-if="column.key === 'job'">
          {{ record.application && record.application.job ? record.application.job.title : '-' }}
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag color="cyan" style="text-transform: capitalize">{{ formatLabel(record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)" style="text-transform: capitalize">{{ formatLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button size="small" @click="openModal(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="crud.remove(record, { label: candidateName(record) })">
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
      <a-form ref="modalFormRef" :model="interview" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Application') + ' *'" name="application_id">
          <a-select
            v-model:value="interview.application_id" :placeholder="$t('Choose_Application')"
            :options="applications.map(a => ({ label: applicationLabel(a), value: a.id }))"
            show-search option-filter-prop="label"
          />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('Type') + ' *'">
              <a-select v-model:value="interview.type">
                <a-select-option v-for="x in TYPES" :key="x" :value="x">
                  <span style="text-transform: capitalize">{{ formatLabel(x) }}</span>
                </a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Scheduled_At') + ' *'" name="scheduled_at">
              <a-input v-model:value="interview.scheduled_at" type="datetime-local" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Duration_Minutes')">
              <a-input-number v-model:value="interview.duration_minutes" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Status')">
              <a-select v-model:value="interview.status">
                <a-select-option v-for="s in STATUSES" :key="s" :value="s">
                  <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
                </a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Location')">
              <a-input v-model:value="interview.location" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Meeting_Link')">
              <a-input v-model:value="interview.meeting_link" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Rating')">
          <a-rate v-model:value="interview.rating" :count="5" />
        </a-form-item>
        <a-form-item :label="$t('Feedback')">
          <a-textarea v-model:value="interview.feedback" :rows="2" />
        </a-form-item>
        <a-form-item :label="$t('Notes')">
          <a-textarea v-model:value="interview.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Recruit interviews — GET recruit/interviews (+status filter) →
 * {interviews, totalRows}; applications for the select from
 * recruit/applications_all (label "candidate - job title"). Save POST/PUT
 * recruit/interviews[/{id}]; scheduled_at normalized to datetime-local
 * format on edit ("YYYY-MM-DDTHH:mm"). Bulk delete standard by_selection.
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

const TYPES = ['phone', 'video', 'in_person', 'technical', 'panel', 'group'];
const STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show', 'rescheduled'];

const statusFilter = ref('');
const crud = useCrudTable('recruit/interviews', {
  rowsKey: 'interviews',
  params: () => ({ status: statusFilter.value }),
});

const applications = ref([]);
const modalOpen = ref(false);
const editmode = ref(false);
const saving = ref(false);
const modalFormRef = ref();

const emptyInterview = () => ({
  id: '', application_id: null, type: 'in_person', scheduled_at: '', duration_minutes: 60,
  location: '', meeting_link: '', status: 'scheduled', rating: 0, feedback: '', notes: '',
});
const interview = ref(emptyInterview());

const rules = computed(() => ({
  application_id: [{ required: true, message: t('Field_is_required') }],
  scheduled_at: [{ required: true, message: t('Field_is_required') }],
}));

const columns = computed(() => [
  { title: t('Candidate'), key: 'candidate' },
  { title: t('Job'), key: 'job' },
  { title: t('Type'), key: 'type' },
  { title: t('Scheduled_At'), dataIndex: 'scheduled_at', key: 'scheduled_at', sorter: true },
  { title: t('Status'), key: 'status' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function formatLabel(v) { return v ? v.replace(/_/g, ' ') : '-'; }
function statusColor(s) {
  return {
    scheduled: 'processing', completed: 'success', cancelled: 'error',
    no_show: 'default', rescheduled: 'warning',
  }[s] || 'default';
}
function candidateName(row) {
  const c = row.application && row.application.candidate;
  return c ? `${c.first_name} ${c.last_name}` : '-';
}
function applicationLabel(a) {
  const c = a.candidate ? `${a.candidate.first_name} ${a.candidate.last_name}` : '?';
  const j = a.job ? a.job.title : '?';
  return `${c} - ${j}`;
}

async function loadFormData() {
  try { applications.value = await http.get('recruit/applications_all'); } catch (e) { applications.value = []; }
}
function openModal(row = null) {
  editmode.value = !!row;
  interview.value = row
    ? { ...emptyInterview(), ...row, rating: Number(row.rating) || 0 }
    : emptyInterview();
  // datetime-local expects "YYYY-MM-DDTHH:mm" (legacy normalization)
  if (interview.value.scheduled_at) {
    interview.value.scheduled_at = String(interview.value.scheduled_at).replace(' ', 'T').substring(0, 16);
  }
  loadFormData();
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
      await http.put(`recruit/interviews/${interview.value.id}`, interview.value);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('recruit/interviews', interview.value);
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
