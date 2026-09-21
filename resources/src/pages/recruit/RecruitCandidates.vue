<template>
  <div class="page">
    <PageHeader :title="$t('Candidates')" :breadcrumb="[$t('Recruit'), $t('Candidates')]">
      <template #extra>
        <a-button type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="filter-label">{{ $t('Source') }}</div>
      <a-select v-model:value="sourceFilter" style="width: 240px; max-width: 100%" @change="crud.reload()">
        <a-select-option value="">{{ $t('All') }}</a-select-option>
        <a-select-option v-for="s in SOURCES" :key="s" :value="s">
          <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
        </a-select-option>
      </a-select>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'full_name'">
          {{ record.first_name }} {{ record.last_name }}
        </template>
        <template v-else-if="column.key === 'source'">
          <a-tag color="cyan" style="text-transform: capitalize">{{ formatLabel(record.source) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button size="small" @click="openModal(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="crud.remove(record, { label: `${record.first_name} ${record.last_name}` })">
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
      <a-form ref="modalFormRef" :model="candidate" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Firstname') + ' *'" name="first_name">
              <a-input v-model:value="candidate.first_name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('lastname') + ' *'" name="last_name">
              <a-input v-model:value="candidate.last_name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Email') + ' *'" name="email">
              <a-input v-model:value="candidate.email" type="email" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Phone')">
              <a-input v-model:value="candidate.phone" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Source')">
              <a-select v-model:value="candidate.source">
                <a-select-option v-for="s in SOURCES" :key="s" :value="s">
                  <span style="text-transform: capitalize">{{ formatLabel(s) }}</span>
                </a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Current_Position')">
              <a-input v-model:value="candidate.current_position" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Experience_Years')">
              <a-input-number v-model:value="candidate.experience_years" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Current_Company')">
              <a-input v-model:value="candidate.current_company" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Current_Salary')">
              <a-input-number v-model:value="candidate.current_salary" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Expected_Salary')">
              <a-input-number v-model:value="candidate.expected_salary" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('City')">
              <a-input v-model:value="candidate.city" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Country')">
              <a-input v-model:value="candidate.country" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('LinkedIn_URL')">
              <a-input v-model:value="candidate.linkedin_url" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Portfolio_URL')">
              <a-input v-model:value="candidate.portfolio_url" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Resume')">
              <a-upload
                :file-list="resumeList" :before-upload="() => false" :max-count="1"
                accept=".pdf,.doc,.docx" @change="({ fileList }) => (resumeList = fileList)"
              >
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Choose_a_file') }}
                </a-button>
              </a-upload>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Photo')">
              <a-upload
                :file-list="photoList" :before-upload="() => false" :max-count="1"
                accept="image/*" @change="({ fileList }) => (photoList = fileList)"
              >
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Choose_a_file') }}
                </a-button>
              </a-upload>
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Skills')">
              <a-textarea v-model:value="candidate.skills" :rows="2" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Notes')">
              <a-textarea v-model:value="candidate.notes" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Recruit candidates — GET recruit/candidates (+source filter) →
 * {candidates, totalRows}. Save is MULTIPART (resume + photo files): legacy
 * builds FormData skipping empty values, POST recruit/candidates for create
 * and POST recruit/candidates/{id} with _method=PUT for update. Bulk delete
 * recruit/candidates/delete/by_selection.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();

const SOURCES = ['website', 'referral', 'linkedin', 'job_board', 'agency', 'walk_in', 'other'];

const sourceFilter = ref('');
const crud = useCrudTable('recruit/candidates', {
  rowsKey: 'candidates',
  params: () => ({ source: sourceFilter.value }),
});

const modalOpen = ref(false);
const editmode = ref(false);
const saving = ref(false);
const modalFormRef = ref();
const resumeList = ref([]);
const photoList = ref([]);

const emptyCandidate = () => ({
  id: '', first_name: '', last_name: '', email: '', phone: '', source: 'website',
  current_position: '', current_company: '', current_salary: null, expected_salary: null,
  experience_years: null, city: '', country: '', linkedin_url: '', portfolio_url: '',
  skills: '', notes: '',
});
const candidate = ref(emptyCandidate());

const rules = computed(() => ({
  first_name: [{ required: true, message: t('Field_is_required') }],
  last_name: [{ required: true, message: t('Field_is_required') }],
  email: [
    { required: true, message: t('Field_is_required') },
    { type: 'email', message: t('InvalidData') },
  ],
}));

const columns = computed(() => [
  { title: t('Name'), key: 'full_name' },
  { title: t('Email'), dataIndex: 'email', key: 'email', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Source'), key: 'source' },
  { title: t('Applications'), dataIndex: 'applications_count', key: 'applications_count', align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function formatLabel(v) { return v ? v.replace(/_/g, ' ') : '-'; }

function openModal(row = null) {
  editmode.value = !!row;
  candidate.value = row ? { ...emptyCandidate(), ...row } : emptyCandidate();
  resumeList.value = [];
  photoList.value = [];
  modalFormRef.value?.clearValidate();
  modalOpen.value = true;
}

// Legacy build_form_data: skip id and empty values, then attach files.
function buildFormData() {
  const fd = new FormData();
  Object.keys(candidate.value).forEach(key => {
    if (key === 'id') return;
    const val = candidate.value[key];
    if (val !== null && val !== undefined && val !== '') fd.append(key, val);
  });
  const resume = resumeList.value[0];
  const photo = photoList.value[0];
  if (resume) fd.append('resume', resume.originFileObj || resume);
  if (photo) fd.append('photo', photo.originFileObj || photo);
  return fd;
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
    let res;
    if (editmode.value) {
      const fd = buildFormData();
      fd.append('_method', 'PUT');
      res = await uploadForm(`recruit/candidates/${candidate.value.id}`, fd);
    } else {
      res = await uploadForm('recruit/candidates', buildFormData());
    }
    if (res.status >= 200 && res.status < 300) {
      message.success(editmode.value ? t('Updated_in_successfully') : t('Created_in_successfully'));
      modalOpen.value = false;
      await crud.fetchRows();
    } else {
      message.error(t('InvalidData'));
    }
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
