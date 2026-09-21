<template>
  <div class="page">
    <PageHeader
      title="Academic Setup"
      subtitle="Years, classes, sections and subjects — configure these first."
      :breadcrumb="['School', 'Academics']"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ addLabel }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <a-tabs v-model:activeKey="tab" @change="load">
        <a-tab-pane key="years" tab="Academic years" />
        <a-tab-pane key="classes" tab="Classes" />
        <a-tab-pane key="sections" tab="Sections" />
        <a-tab-pane key="subjects" tab="Subjects" />
      </a-tabs>

      <div v-if="tab !== 'years'" class="toolbar">
        <a-input-search
          v-model:value="search" placeholder="Search…" allow-clear class="tb-search" @search="load"
        />
        <a-select
          v-if="tab === 'sections' || tab === 'subjects'"
          v-model:value="classFilter" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All classes"
          :options="classOptions" @change="load"
        />
      </div>

      <a-table
        :columns="columns" :data-source="rows" :loading="loading"
        :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'is_current'">
            <a-tag v-if="record.is_current" color="success">Current</a-tag>
            <a-button v-else size="small" type="link" @click="makeCurrent(record)">Make current</a-button>
          </template>
          <template v-else-if="column.key === 'dates'">
            {{ date(record.start_date) }} → {{ date(record.end_date) }}
          </template>
          <template v-else-if="column.key === 'is_active'">
            <a-tag :color="record.is_active ? 'success' : 'default'">
              {{ record.is_active ? 'Active' : 'Inactive' }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'type'">
            <a-tag :color="optionOf(SUBJECT_TYPES, record.type).color">
              {{ labelOf(SUBJECT_TYPES, record.type) }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'occupancy'">
            <span v-if="record.capacity">
              {{ record.students }} / {{ record.capacity }}
              <a-tag v-if="record.is_full" color="error" class="tag-tight">Full</a-tag>
            </span>
            <span v-else>{{ record.students }}</span>
          </template>
          <template v-else-if="column.key === 'contains'">
            <a-space :size="4">
              <a-tag>{{ record.sections }} sections</a-tag>
              <a-tag>{{ record.subjects }} subjects</a-tag>
              <a-tag color="purple">{{ record.students }} students</a-tag>
            </a-space>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="0">
              <a-tooltip :title="$t('Edit')">
                <a-button type="text" size="small" @click="openForm(record)">
                  <template #icon><EditOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Delete')">
                <a-button type="text" size="small" danger @click="remove(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
        <template #emptyText>
          <a-empty :image="simpleEmptyImage" :description="emptyText">
            <a-button type="primary" size="small" @click="openForm(null)">{{ addLabel }}</a-button>
          </a-empty>
        </template>
      </a-table>
    </a-card>

    <!-- One modal, four shapes: the fields differ but the flow does not. -->
    <a-modal
      :open="formOpen" :title="modalTitle" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <template v-if="tab === 'years'">
          <a-form-item label="Name *" name="name">
            <a-input v-model:value="form.name" placeholder="e.g. 2026 / 2027" allow-clear />
          </a-form-item>
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item label="Starts *" name="start_date">
                <a-date-picker v-model:value="form.start_date" style="width: 100%" value-format="YYYY-MM-DD" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item label="Ends *" name="end_date">
                <a-date-picker v-model:value="form.end_date" style="width: 100%" value-format="YYYY-MM-DD" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_current">
              Current year — everything defaults to it
            </a-checkbox>
          </a-form-item>
        </template>

        <template v-else-if="tab === 'classes'">
          <a-row :gutter="16">
            <a-col :xs="24" :md="14">
              <a-form-item label="Name *" name="name">
                <a-input v-model:value="form.name" placeholder="e.g. Grade 5" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="10">
              <a-form-item label="Code">
                <a-input v-model:value="form.code" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item label="Level" extra="Used for promotion order">
                <a-input-number v-model:value="form.level" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item :label="$t('Description')">
            <a-textarea v-model:value="form.description" :rows="2" />
          </a-form-item>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">Active</a-checkbox>
          </a-form-item>
        </template>

        <template v-else-if="tab === 'sections'">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item label="Class *" name="class_id">
                <a-select
                  v-model:value="form.class_id" show-search option-filter-prop="label"
                  :options="classOptions" placeholder="Select a class"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item label="Section name *" name="name">
                <a-input v-model:value="form.name" placeholder="e.g. A" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item label="Capacity">
                <a-input-number v-model:value="form.capacity" :min="1" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item label="Room">
                <a-input v-model:value="form.room" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item label="Form teacher">
                <a-select
                  v-model:value="form.teacher_id" allow-clear show-search
                  option-filter-prop="label" :options="teacherOptions" placeholder="None"
                />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">Active</a-checkbox>
          </a-form-item>
        </template>

        <template v-else>
          <a-row :gutter="16">
            <a-col :xs="24" :md="14">
              <a-form-item label="Subject *" name="name">
                <a-input v-model:value="form.name" placeholder="e.g. Mathematics" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="10">
              <a-form-item label="Code">
                <a-input v-model:value="form.code" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Class">
                <a-select
                  v-model:value="form.class_id" allow-clear show-search option-filter-prop="label"
                  :options="classOptions" placeholder="All classes"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item label="Type *" name="type">
                <a-select v-model:value="form.type" :options="SUBJECT_TYPES" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item label="Credit" extra="Weight when averaging a term">
                <a-input-number v-model:value="form.credit" :min="0" :step="0.5" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item label="Pass mark %" extra="Used to seed exam pass marks">
                <a-input-number v-model:value="form.pass_mark" :min="0" :max="100" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">Active</a-checkbox>
          </a-form-item>
        </template>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The academic structure, as one setup screen with four tabs — a school
 * configures years, classes, sections and subjects together at the start of a
 * year, and they share one permission.
 *
 * Deletes refuse rather than cascade wherever students are attached; the
 * backend enforces it and the error explains what to move first.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal, Empty } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { SUBJECT_TYPES, labelOf, optionOf } from './schoolOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { date } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const tab = ref('years');
const rows = ref([]);
const loading = ref(false);
const search = ref('');
const classFilter = ref(undefined);

const meta = ref({});
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const teacherOptions = computed(() => (meta.value.teachers || []).map(x => ({ value: x.id, label: x.name })));

const ENDPOINTS = {
  years: { url: 'school/years', key: 'academic_years', label: 'New year', title: 'academic year' },
  classes: { url: 'school/classes', key: 'classes', label: 'New class', title: 'class' },
  sections: { url: 'school/sections', key: 'sections', label: 'New section', title: 'section' },
  subjects: { url: 'school/subjects', key: 'subjects', label: 'New subject', title: 'subject' },
};

const current = computed(() => ENDPOINTS[tab.value]);
const addLabel = computed(() => current.value.label);
const emptyText = computed(() => `No ${current.value.title}s yet`);
const modalTitle = computed(() =>
  `${editing.value ? 'Edit' : 'New'} ${current.value.title}`);

const COLUMNS = {
  years: [
    { title: 'Year', dataIndex: 'name', key: 'name' },
    { title: 'Runs', key: 'dates', width: 240 },
    { title: 'Students', dataIndex: 'students', key: 'students', width: 110 },
    { title: '', key: 'is_current', width: 150 },
    { title: '', key: 'actions', width: 90 },
  ],
  classes: [
    { title: 'Class', dataIndex: 'name', key: 'name' },
    { title: 'Code', dataIndex: 'code', key: 'code', width: 110 },
    { title: 'Level', dataIndex: 'level', key: 'level', width: 90 },
    { title: 'Contains', key: 'contains', width: 300 },
    { title: 'Status', key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
  sections: [
    { title: 'Class', dataIndex: 'class_name', key: 'class_name', width: 150 },
    { title: 'Section', dataIndex: 'name', key: 'name', width: 120 },
    { title: 'Occupancy', key: 'occupancy', width: 160 },
    { title: 'Room', dataIndex: 'room', key: 'room', width: 110 },
    { title: 'Form teacher', dataIndex: 'teacher_name', key: 'teacher_name' },
    { title: 'Status', key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
  subjects: [
    { title: 'Subject', dataIndex: 'name', key: 'name' },
    { title: 'Code', dataIndex: 'code', key: 'code', width: 110 },
    { title: 'Class', dataIndex: 'class_name', key: 'class_name', width: 150 },
    { title: 'Type', key: 'type', dataIndex: 'type', width: 120 },
    { title: 'Credit', dataIndex: 'credit', key: 'credit', width: 90 },
    { title: 'Pass %', dataIndex: 'pass_mark', key: 'pass_mark', width: 90 },
    { title: 'Status', key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
};

const columns = computed(() => COLUMNS[tab.value]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get(current.value.url, {
      search: search.value,
      class_id: classFilter.value || '',
    });
    rows.value = data?.[current.value.key] || [];
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this list'));
  } finally {
    loading.value = false;
  }
}

async function loadMeta() {
  try {
    meta.value = await http.get('school/meta');
  } catch (e) { /* selects stay empty */ }
}

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref({});

function blank() {
  return {
    years: { name: '', start_date: null, end_date: null, is_current: false },
    classes: { name: '', code: '', level: 0, description: '', is_active: true },
    sections: { class_id: classFilter.value, name: '', capacity: null, room: '', teacher_id: undefined, is_active: true },
    subjects: { name: '', code: '', class_id: classFilter.value, type: 'core', credit: 1, pass_mark: 40, is_active: true },
  }[tab.value];
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => {
  const base = { name: required() };
  if (tab.value === 'years') return { ...base, start_date: required(), end_date: required() };
  if (tab.value === 'sections') return { ...base, class_id: required() };
  if (tab.value === 'subjects') return { ...base, type: required() };
  return base;
});

function openForm(record) {
  editing.value = record;
  form.value = record ? { ...blank(), ...record } : blank();
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`${current.value.url}/${editing.value.id}`, form.value);
    else await http.post(current.value.url, form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    load();
    loadMeta();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this record'));
  } finally {
    saving.value = false;
  }
}

/** Flip which year everything defaults to. */
async function makeCurrent(record) {
  try {
    await http.put(`school/years/${record.id}`, { ...record, is_current: true });
    message.success(`${record.name} is now the current year.`);
    load();
    loadMeta();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not update the year'));
  }
}

function remove(record) {
  Modal.confirm({
    title: `Delete ${record.name}?`,
    icon: createVNode(ExclamationCircleOutlined),
    content: 'Records still holding students are refused — nothing is deleted silently.',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`${current.value.url}/${record.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        load();
        loadMeta();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData', 'Could not delete this record'));
      }
    },
  });
}

onMounted(() => {
  load();
  loadMeta();
});
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}
.tb-search {
  flex: 1 1 220px;
  max-width: 320px;
}
.tb-item {
  width: 180px;
}
.tag-tight {
  margin-inline-start: 6px;
}
</style>
