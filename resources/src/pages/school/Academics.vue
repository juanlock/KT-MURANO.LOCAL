<template>
  <div class="page">
    <PageHeader
      :title="s('Academic Setup')"
      :subtitle="s('Years, classes, sections and subjects — configure these first.')"
      :breadcrumb="[s('School'), s('Academics')]"
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
        <a-tab-pane key="years" :tab="s('Academic years')" />
        <a-tab-pane key="classes" :tab="s('Classes')" />
        <a-tab-pane key="sections" :tab="s('Sections')" />
        <a-tab-pane key="subjects" :tab="s('Subjects')" />
      </a-tabs>

      <div v-if="tab !== 'years'" class="toolbar">
        <a-input-search
          v-model:value="search" :placeholder="s('Search…')" allow-clear class="tb-search" @search="load"
        />
        <a-select
          v-if="tab === 'sections' || tab === 'subjects'"
          v-model:value="classFilter" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="s('All classes')"
          :options="classOptions" @change="load"
        />
      </div>

      <a-table
        :columns="columns" :data-source="rows" :loading="loading"
        :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'is_current'">
            <a-tag v-if="record.is_current" color="success">{{ s('Current') }}</a-tag>
            <a-button v-else size="small" type="link" @click="makeCurrent(record)">{{ s('Make current') }}</a-button>
          </template>
          <template v-else-if="column.key === 'dates'">
            {{ date(record.start_date) }} → {{ date(record.end_date) }}
          </template>
          <template v-else-if="column.key === 'is_active'">
            <a-tag :color="record.is_active ? 'success' : 'default'">
              {{ record.is_active ? s('Active') : s('Inactive') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'type'">
            <a-tag :color="optionOf(SUBJECT_TYPES, record.type).color">
              {{ s(labelOf(SUBJECT_TYPES, record.type)) }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'occupancy'">
            <span v-if="record.capacity">
              {{ record.students }} / {{ record.capacity }}
              <a-tag v-if="record.is_full" color="error" class="tag-tight">{{ s('Full') }}</a-tag>
            </span>
            <span v-else>{{ record.students }}</span>
          </template>
          <template v-else-if="column.key === 'contains'">
            <a-space :size="4">
              <a-tag>{{ s('{n} sections', { n: record.sections }) }}</a-tag>
              <a-tag>{{ s('{n} subjects', { n: record.subjects }) }}</a-tag>
              <a-tag color="purple">{{ s('{n} students', { n: record.students }) }}</a-tag>
            </a-space>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="0">
              <a-tooltip :title="s('Edit')">
                <a-button type="text" size="small" @click="openForm(record)">
                  <template #icon><EditOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="s('Delete')">
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
      :confirm-loading="saving" :ok-text="s('Submit')" :cancel-text="s('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <template v-if="tab === 'years'">
          <a-form-item :label="s('Name *')" name="name">
            <a-input v-model:value="form.name" :placeholder="s('e.g. 2026 / 2027')" allow-clear />
          </a-form-item>
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item :label="s('Starts *')" name="start_date">
                <a-date-picker v-model:value="form.start_date" style="width: 100%" value-format="YYYY-MM-DD" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="s('Ends *')" name="end_date">
                <a-date-picker v-model:value="form.end_date" style="width: 100%" value-format="YYYY-MM-DD" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_current">
              {{ s('Current year — everything defaults to it') }}
            </a-checkbox>
          </a-form-item>
        </template>

        <template v-else-if="tab === 'classes'">
          <a-row :gutter="16">
            <a-col :xs="24" :md="14">
              <a-form-item :label="s('Name *')" name="name">
                <a-input v-model:value="form.name" :placeholder="s('e.g. Grade 5')" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="10">
              <a-form-item :label="s('Code')">
                <a-input v-model:value="form.code" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="s('Level')" :extra="s('Used for promotion order')">
                <a-input-number v-model:value="form.level" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item :label="s('Description')">
            <a-textarea v-model:value="form.description" :rows="2" />
          </a-form-item>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">{{ s('Active') }}</a-checkbox>
          </a-form-item>
        </template>

        <template v-else-if="tab === 'sections'">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="s('Class *')" name="class_id">
                <a-select
                  v-model:value="form.class_id" show-search option-filter-prop="label"
                  :options="classOptions" :placeholder="s('Select a class')"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="s('Section name *')" name="name">
                <a-input v-model:value="form.name" :placeholder="s('e.g. A')" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="s('Capacity')">
                <a-input-number v-model:value="form.capacity" :min="1" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="s('Room')">
                <a-input v-model:value="form.room" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="s('Form teacher')">
                <a-select
                  v-model:value="form.teacher_id" allow-clear show-search
                  option-filter-prop="label" :options="teacherOptions" :placeholder="s('None')"
                />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">{{ s('Active') }}</a-checkbox>
          </a-form-item>
        </template>

        <template v-else>
          <a-row :gutter="16">
            <a-col :xs="24" :md="14">
              <a-form-item :label="s('Subject *')" name="name">
                <a-input v-model:value="form.name" :placeholder="s('e.g. Mathematics')" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="10">
              <a-form-item :label="s('Code')">
                <a-input v-model:value="form.code" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="s('Class')">
                <a-select
                  v-model:value="form.class_id" allow-clear show-search option-filter-prop="label"
                  :options="classOptions" :placeholder="s('All classes')"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="s('Type *')" name="type">
                <a-select v-model:value="form.type" :options="subjectTypeOptions" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="s('Credit')" :extra="s('Weight when averaging a term')">
                <a-input-number v-model:value="form.credit" :min="0" :step="0.5" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="s('Pass mark %')" :extra="s('Used to seed exam pass marks')">
                <a-input-number v-model:value="form.pass_mark" :min="0" :max="100" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">{{ s('Active') }}</a-checkbox>
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
import { useSchoolText } from './lang/useSchoolText';
import ES from './lang/Academics.es.js';

const { s } = useSchoolText(ES);
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
const subjectTypeOptions = computed(() => SUBJECT_TYPES.map(o => ({ ...o, label: s(o.label) })));

const ENDPOINTS = {
  years: { url: 'school/years', key: 'academic_years', label: 'New year', newTitle: 'New academic year', editTitle: 'Edit academic year', empty: 'No academic years yet' },
  classes: { url: 'school/classes', key: 'classes', label: 'New class', newTitle: 'New class', editTitle: 'Edit class', empty: 'No classes yet' },
  sections: { url: 'school/sections', key: 'sections', label: 'New section', newTitle: 'New section', editTitle: 'Edit section', empty: 'No sections yet' },
  subjects: { url: 'school/subjects', key: 'subjects', label: 'New subject', newTitle: 'New subject', editTitle: 'Edit subject', empty: 'No subjects yet' },
};

const current = computed(() => ENDPOINTS[tab.value]);
const addLabel = computed(() => s(current.value.label));
const emptyText = computed(() => s(current.value.empty));
const modalTitle = computed(() =>
  s(editing.value ? current.value.editTitle : current.value.newTitle));

const COLUMNS = () => ({
  years: [
    { title: s('Year'), dataIndex: 'name', key: 'name' },
    { title: s('Runs'), key: 'dates', width: 240 },
    { title: s('Students'), dataIndex: 'students', key: 'students', width: 110 },
    { title: '', key: 'is_current', width: 150 },
    { title: '', key: 'actions', width: 90 },
  ],
  classes: [
    { title: s('Class'), dataIndex: 'name', key: 'name' },
    { title: s('Code'), dataIndex: 'code', key: 'code', width: 110 },
    { title: s('Level'), dataIndex: 'level', key: 'level', width: 90 },
    { title: s('Contains'), key: 'contains', width: 300 },
    { title: s('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
  sections: [
    { title: s('Class'), dataIndex: 'class_name', key: 'class_name', width: 150 },
    { title: s('Section'), dataIndex: 'name', key: 'name', width: 120 },
    { title: s('Occupancy'), key: 'occupancy', width: 160 },
    { title: s('Room'), dataIndex: 'room', key: 'room', width: 110 },
    { title: s('Form teacher'), dataIndex: 'teacher_name', key: 'teacher_name' },
    { title: s('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
  subjects: [
    { title: s('Subject'), dataIndex: 'name', key: 'name' },
    { title: s('Code'), dataIndex: 'code', key: 'code', width: 110 },
    { title: s('Class'), dataIndex: 'class_name', key: 'class_name', width: 150 },
    { title: s('Type'), key: 'type', dataIndex: 'type', width: 120 },
    { title: s('Credit'), dataIndex: 'credit', key: 'credit', width: 90 },
    { title: s('Pass %'), dataIndex: 'pass_mark', key: 'pass_mark', width: 90 },
    { title: s('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
});

const columns = computed(() => COLUMNS()[tab.value]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get(current.value.url, {
      search: search.value,
      class_id: classFilter.value || '',
    });
    rows.value = data?.[current.value.key] || [];
  } catch (e) {
    message.error(s('Could not load this list'));
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

const required = () => [{ required: true, message: s('This field is required') }];
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
    message.success(s('Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    load();
    loadMeta();
  } catch (e) {
    message.error(e?.data?.message || s('Could not save this record'));
  } finally {
    saving.value = false;
  }
}

/** Flip which year everything defaults to. */
async function makeCurrent(record) {
  try {
    await http.put(`school/years/${record.id}`, { ...record, is_current: true });
    message.success(s('{name} is now the current year.', { name: record.name }));
    load();
    loadMeta();
  } catch (e) {
    message.error(e?.data?.message || s('Could not update the year'));
  }
}

function remove(record) {
  Modal.confirm({
    title: s('Delete {name}?', { name: record.name }),
    icon: createVNode(ExclamationCircleOutlined),
    content: s('Records still holding students are refused — nothing is deleted silently.'),
    okText: s('Yes, delete it!'),
    okType: 'danger',
    cancelText: s('Cancel'),
    async onOk() {
      try {
        await http.delete(`${current.value.url}/${record.id}`);
        message.success(s('Deleted successfully'));
        load();
        loadMeta();
      } catch (e) {
        message.error(e?.data?.message || s('Could not delete this record'));
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
