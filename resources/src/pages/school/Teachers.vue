<template>
  <div class="page">
    <PageHeader title="Teachers" :breadcrumb="['School', 'Teachers']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search name, code or specialism…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.gender" class="tb-item-sm" allow-clear
          placeholder="Gender" :options="GENDERS" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.active" class="tb-item-sm" allow-clear placeholder="All"
          :options="[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]"
          @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div class="cell-t">
            <a-avatar :size="36" :src="record.image_url" class="cell-avatar">
              {{ initials(record.name) }}
            </a-avatar>
            <div>
              <div class="cell-name">{{ record.name }}</div>
              <div class="cell-sub">{{ record.specialization || record.qualification || '—' }}</div>
            </div>
          </div>
        </template>
        <template v-else-if="column.key === 'workload'">
          <a-space :size="4">
            <a-tooltip title="Periods a week">
              <a-tag :color="record.weekly_periods > 30 ? 'error' : record.weekly_periods > 20 ? 'warning' : 'default'">
                {{ record.weekly_periods }} periods
              </a-tag>
            </a-tooltip>
            <a-tag v-if="record.form_classes">{{ record.form_classes }} form</a-tag>
          </a-space>
        </template>
        <template v-else-if="column.key === 'joining_date'">
          {{ record.joining_date ? date(record.joining_date) : '—' }}
        </template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? 'Active' : 'Inactive' }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Weekly timetable">
              <a-button type="text" size="small" @click="openTimetable(record)">
                <template #icon><CalendarOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? 'Edit teacher' : 'New teacher'" :width="680"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Name *" name="name">
              <a-input v-model:value="form.name" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Staff code">
              <a-input v-model:value="form.employee_code" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Gender *" name="gender">
              <a-select v-model:value="form.gender" :options="GENDERS" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Specialism">
              <a-input v-model:value="form.specialization" placeholder="e.g. Mathematics" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Qualification">
              <a-input v-model:value="form.qualification" placeholder="e.g. BSc, PGCE" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="Phone">
              <a-input v-model:value="form.phone" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="10">
            <a-form-item label="Email" name="email">
              <a-input v-model:value="form.email" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Joined on">
              <a-date-picker v-model:value="form.joining_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item label="Address">
              <a-input v-model:value="form.address" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Photo">
              <a-space>
                <a-upload :show-upload-list="false" accept="image/*" :before-upload="pickImage">
                  <a-button>
                    <template #icon><UploadOutlined /></template>
                    {{ previewUrl ? 'Replace' : 'Upload' }}
                  </a-button>
                </a-upload>
                <a-avatar v-if="previewUrl" :size="40" :src="previewUrl" />
              </a-space>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Status">
              <a-checkbox v-model:checked="form.is_active">On the staff</a-checkbox>
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')" style="margin-bottom: 0">
              <a-textarea v-model:value="form.notes" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <a-drawer :open="ttOpen" :width="520" :title="`${timetable.teacher_name || ''} — weekly timetable`" @close="ttOpen = false">
      <a-empty v-if="!timetable.total" description="No periods assigned" />
      <div v-else>
        <div v-for="day in WEEK_DAYS" :key="day.value" class="tt-day">
          <div class="tt-day-name">{{ day.label }}</div>
          <div v-if="(timetable.days?.[day.value] || []).length" class="tt-slots">
            <div v-for="slot in timetable.days[day.value]" :key="slot.id" class="tt-slot">
              <span class="tt-time">{{ slot.start_time }}–{{ slot.end_time }}</span>
              <span class="tt-subject">{{ slot.subject_name }}</span>
              <span class="tt-class">
                {{ slot.class_name }}{{ slot.section_name ? ' — ' + slot.section_name : '' }}
              </span>
            </div>
          </div>
          <div v-else class="tt-free">Free</div>
        </div>
      </div>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Teaching staff. The workload column is the point of this list: a teacher on
 * 30+ periods a week is flagged, because that is the number a timetabler needs
 * before adding one more.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined, CalendarOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { uploadForm } from '../../lib/upload';
import { GENDERS, WEEK_DAYS } from './schoolOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { date } = useFormat();
const filters = reactive({ gender: undefined, active: undefined });

const crud = useCrudTable('school/teachers', {
  rowsKey: 'teachers',
  sortField: 'name',
  sortType: 'asc',
  bulkDeleteEndpoint: 'school/teachers/delete/by_selection',
  params: () => ({ gender: filters.gender || '', active: filters.active || '' }),
});

const columns = computed(() => [
  { title: 'Teacher', key: 'name', dataIndex: 'name', sorter: true },
  { title: 'Phone', dataIndex: 'phone', key: 'phone', width: 140 },
  { title: 'Email', dataIndex: 'email', key: 'email', width: 200 },
  { title: 'Workload', key: 'workload', width: 200 },
  { title: 'Joined', key: 'joining_date', dataIndex: 'joining_date', sorter: true, width: 130 },
  { title: 'Status', key: 'is_active', dataIndex: 'is_active', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 120 },
]);

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2)
    .map(p => p[0].toUpperCase()).join('');
}

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const imageFile = ref(null);
const previewUrl = ref('');
const form = ref(empty());

function empty() {
  return {
    name: '', employee_code: '', gender: 'male', phone: '', email: '',
    qualification: '', specialization: '', joining_date: null, address: '',
    notes: '', is_active: true,
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  name: required(),
  gender: required(),
  email: [{ type: 'email', message: t('InvalidData', 'Enter a valid email address') }],
}));

function openForm(record) {
  editing.value = record;
  form.value = record ? { ...empty(), ...record } : empty();
  previewUrl.value = record?.image_url || '';
  imageFile.value = null;
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

function pickImage(file) {
  imageFile.value = file;
  previewUrl.value = window.URL.createObjectURL(file);
  return false;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  const fd = new FormData();
  Object.entries(form.value).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;
    if (['id', 'image', 'image_url', 'weekly_periods', 'form_classes'].includes(key)) return;
    fd.append(key, value);
  });
  fd.append('is_active', form.value.is_active ? 1 : 0);
  if (imageFile.value) fd.append('image', imageFile.value);

  saving.value = true;
  try {
    const url = editing.value ? `school/teachers/${editing.value.id}/update` : 'school/teachers';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(t('Created_in_successfully', 'Saved successfully'));
      formOpen.value = false;
      editing.value = null;
      crud.fetchRows();
      return;
    }
    message.error(firstError(res.data) || t('InvalidData', 'Could not save this teacher'));
  } catch (e) {
    message.error(t('InvalidData', 'Could not save this teacher'));
  } finally {
    saving.value = false;
  }
}

function firstError(data) {
  const errors = data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return data?.message || '';
}

const ttOpen = ref(false);
const timetable = ref({});

async function openTimetable(teacher) {
  try {
    timetable.value = await http.get(`school/teachers/${teacher.id}/timetable`);
    ttOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the timetable'));
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-search {
  flex: 1 1 240px;
  min-width: 200px;
}
.tb-item-sm {
  width: 130px;
}
.cell-t {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 190px;
}
.cell-avatar {
  flex: none;
  background: rgba(13, 148, 136, 0.15);
  color: #0d9488;
  font-size: 13px;
}
.cell-name {
  font-weight: 500;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.tt-day {
  margin-bottom: 14px;
}
.tt-day-name {
  font-weight: 600;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.6;
  margin-bottom: 6px;
}
.tt-slots {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.tt-slot {
  display: flex;
  gap: 10px;
  align-items: baseline;
  padding: 8px 10px;
  border-radius: 9px;
  background: rgba(128, 128, 128, 0.08);
}
.tt-time {
  flex: none;
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  font-weight: 600;
}
.tt-subject {
  font-weight: 500;
  font-size: 13px;
}
.tt-class {
  margin-inline-start: auto;
  font-size: 11.5px;
  opacity: 0.6;
}
.tt-free {
  font-size: 12px;
  opacity: 0.4;
  padding-inline-start: 4px;
}
</style>
