<template>
  <div class="page">
    <PageHeader title="Timetable" :breadcrumb="['School', 'Timetable']">
      <template #actions>
        <a-button type="primary" :disabled="!filters.class_id" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          Add period
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-select
          v-model:value="filters.academic_year_id" class="tb-item" show-search
          option-filter-prop="label" :options="yearOptions" @change="load"
        />
        <a-select
          v-model:value="filters.class_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="Class" :options="classOptions" @change="onClassChange"
        />
        <a-select
          v-model:value="filters.section_id" class="tb-item-sm" allow-clear show-search
          option-filter-prop="label" placeholder="Section" :options="sectionOptions" @change="load"
        />
        <a-select
          v-model:value="filters.teacher_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="Any teacher" :options="teacherOptions" @change="load"
        />
      </div>
    </a-card>

    <a-spin :spinning="loading">
      <div class="grid">
        <div v-for="day in WEEK_DAYS" :key="day.value" class="col">
          <div class="col-head">{{ day.short }}</div>
          <div class="col-body">
            <button
              v-for="slot in days[day.value] || []" :key="slot.id" type="button"
              class="slot" @click="openForm(slot)"
            >
              <span class="slot-time">{{ slot.start_time }}–{{ slot.end_time }}</span>
              <span class="slot-subject">{{ slot.subject_name }}</span>
              <span class="slot-meta">
                {{ slot.teacher_name || 'No teacher' }}
                <template v-if="!filters.class_id">
                  · {{ slot.class_name }}{{ slot.section_name ? ' ' + slot.section_name : '' }}
                </template>
              </span>
              <span v-if="slot.room" class="slot-room">{{ slot.room }}</span>
            </button>
            <div v-if="!(days[day.value] || []).length" class="col-empty">—</div>
          </div>
        </div>
      </div>

      <a-card v-if="!total" style="margin-top: 16px">
        <a-empty :description="filters.class_id ? 'No periods scheduled yet' : 'Pick a class to build its timetable'">
          <a-button v-if="filters.class_id" type="primary" @click="openForm(null)">Add the first period</a-button>
        </a-empty>
      </a-card>
    </a-spin>

    <a-modal
      :open="formOpen" :title="editing ? 'Edit period' : 'Add period'" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-alert
        type="info" show-icon banner
        message="A section cannot sit two subjects at once, and a teacher cannot be in two rooms at once — both are checked across the whole year."
        style="margin-bottom: 16px"
      />
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Class *" name="class_id">
              <a-select
                v-model:value="form.class_id" show-search option-filter-prop="label"
                :options="classOptions" @change="form.section_id = undefined"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Section">
              <a-select
                v-model:value="form.section_id" allow-clear show-search option-filter-prop="label"
                :options="formSectionOptions" :disabled="!form.class_id" placeholder="Whole class"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Subject *" name="subject_id">
              <a-select
                v-model:value="form.subject_id" show-search option-filter-prop="label"
                :options="formSubjectOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Teacher">
              <a-select
                v-model:value="form.teacher_id" allow-clear show-search option-filter-prop="label"
                :options="teacherOptions" placeholder="Unassigned"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Day *" name="day_of_week">
              <a-select v-model:value="form.day_of_week" :options="dayOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="From *" name="start_time">
              <a-time-picker v-model:value="form.start_time" format="HH:mm" value-format="HH:mm" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="To *" name="end_time">
              <a-time-picker v-model:value="form.end_time" format="HH:mm" value-format="HH:mm" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item label="Room" style="margin-bottom: 0">
              <a-input v-model:value="form.room" allow-clear />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>

      <template #footer>
        <a-button v-if="editing" danger style="float: left" @click="remove">{{ $t('Delete') }}</a-button>
        <a-button @click="formOpen = false">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The weekly timetable, as a day-column grid.
 *
 * With no class selected it shows the whole school's week (useful for spotting
 * a teacher's gaps); with a class selected it is that class's timetable. The
 * clash rules live on the backend, so a conflict is reported with the lesson it
 * collides with rather than silently accepted.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { WEEK_DAYS } from './schoolOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const filters = reactive({
  academic_year_id: undefined, class_id: undefined, section_id: undefined, teacher_id: undefined,
});

const days = ref({});
const total = ref(0);
const loading = ref(false);

const meta = ref({});
const yearOptions = computed(() => (meta.value.academic_years || []).map(y => ({
  value: y.id, label: y.is_current ? `${y.name} (current)` : y.name,
})));
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const teacherOptions = computed(() => (meta.value.teachers || []).map(x => ({ value: x.id, label: x.name })));
const sectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => s.class_id === filters.class_id)
  .map(s => ({ value: s.id, label: s.name })));
const formSectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => s.class_id === form.value.class_id)
  .map(s => ({ value: s.id, label: s.name })));
const formSubjectOptions = computed(() => (meta.value.subjects || [])
  .filter(s => !s.class_id || s.class_id === form.value.class_id)
  .map(s => ({ value: s.id, label: s.name })));
const dayOptions = WEEK_DAYS.map(d => ({ value: d.value, label: d.label }));

function onClassChange() {
  filters.section_id = undefined;
  load();
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('school/timetable', {
      academic_year_id: filters.academic_year_id || '',
      class_id: filters.class_id || '',
      section_id: filters.section_id || '',
      teacher_id: filters.teacher_id || '',
    });
    days.value = data?.days || {};
    total.value = data?.total || 0;
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the timetable'));
  } finally {
    loading.value = false;
  }
}

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(empty());

function empty() {
  return {
    academic_year_id: filters.academic_year_id,
    class_id: filters.class_id,
    section_id: filters.section_id,
    subject_id: undefined, teacher_id: undefined,
    day_of_week: 'mon', start_time: '08:00', end_time: '09:00', room: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  class_id: required(),
  subject_id: required(),
  day_of_week: required(),
  start_time: required(),
  end_time: required(),
}));

function openForm(slot) {
  editing.value = slot;
  form.value = slot
    ? {
        academic_year_id: slot.academic_year_id || filters.academic_year_id,
        class_id: slot.class_id,
        section_id: slot.section_id || undefined,
        subject_id: slot.subject_id,
        teacher_id: slot.teacher_id || undefined,
        day_of_week: slot.day_of_week,
        start_time: slot.start_time,
        end_time: slot.end_time,
        room: slot.room || '',
      }
    : empty();
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
    if (editing.value) await http.put(`school/timetable/${editing.value.id}`, form.value);
    else await http.post('school/timetable', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    load();
  } catch (e) {
    // The clash guard answers 422 with a message naming the conflict.
    message.error(e?.data?.message || t('InvalidData', 'Could not save this period'));
  } finally {
    saving.value = false;
  }
}

async function remove() {
  try {
    await http.delete(`school/timetable/${editing.value.id}`);
    message.success(t('Deleted_in_successfully', 'Deleted successfully'));
    formOpen.value = false;
    editing.value = null;
    load();
  } catch (e) {
    message.error(t('InvalidData', 'Could not delete this period'));
  }
}

onMounted(async () => {
  try {
    meta.value = await http.get('school/meta');
    filters.academic_year_id = meta.value.current_year_id;
  } catch (e) { /* selects stay empty */ }
  load();
});
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-item {
  width: 180px;
}
.tb-item-sm {
  width: 140px;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 10px;
}
.col {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  overflow: hidden;
}
.col-head {
  padding: 9px 12px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.18);
  background: rgba(128, 128, 128, 0.07);
  font-weight: 600;
  font-size: 12.5px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.col-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px;
  min-height: 90px;
}
.slot {
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 8px 10px;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-inline-start: 3px solid #6d28d9;
  border-radius: 9px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
  transition: border-color 0.15s ease, transform 0.12s ease;
}
.slot:hover {
  border-color: rgba(109, 40, 217, 0.6);
  transform: translateY(-1px);
}
.slot-time {
  font-size: 11.5px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  opacity: 0.75;
}
.slot-subject {
  font-size: 13px;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.slot-meta {
  font-size: 11px;
  opacity: 0.6;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.slot-room {
  font-size: 10.5px;
  opacity: 0.5;
}
.col-empty {
  text-align: center;
  opacity: 0.3;
  padding: 14px 0;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-item-sm {
    width: 100%;
  }
}
</style>
