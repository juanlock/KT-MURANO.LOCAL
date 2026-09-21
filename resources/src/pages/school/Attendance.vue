<template>
  <div class="page">
    <PageHeader title="Attendance" subtitle="Take the register, or review a month." :breadcrumb="['School', 'Attendance']" />

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-select
          v-model:value="picker.class_id" class="tb-item" show-search option-filter-prop="label"
          placeholder="Class" :options="classOptions" @change="onClassChange"
        />
        <a-select
          v-model:value="picker.section_id" class="tb-item-sm" allow-clear show-search
          option-filter-prop="label" placeholder="All sections"
          :options="sectionOptions" @change="loadRegister"
        />
        <a-date-picker
          v-model:value="picker.date" class="tb-item-sm" value-format="YYYY-MM-DD"
          :disabled-date="futureDate" @change="loadRegister"
        />
        <a-select
          v-model:value="picker.subject_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="Whole day" :options="subjectOptions"
          @change="loadRegister"
        />
        <a-button :disabled="!picker.class_id" :loading="loading" @click="loadRegister">
          <template #icon><ReloadOutlined /></template>
          Load
        </a-button>
      </div>
    </a-card>

    <a-card v-if="!picker.class_id">
      <a-empty description="Pick a class to take its register" />
    </a-card>

    <template v-else>
      <a-spin :spinning="loading">
        <div class="summary">
          <div class="sum">
            <span class="sum-label">On roll</span>
            <span class="sum-value">{{ students.length }}</span>
          </div>
          <div v-for="s in ATTENDANCE_STATUSES" :key="s.value" class="sum">
            <span class="sum-label">{{ s.label }}</span>
            <span class="sum-value" :class="`tone-${s.value}`">{{ counts[s.value] || 0 }}</span>
          </div>
          <div class="sum">
            <span class="sum-label">Rate</span>
            <span class="sum-value">{{ rate }}%</span>
          </div>
        </div>

        <a-card size="small">
          <template #title>
            <span>Register — {{ date(picker.date) }}</span>
            <a-tag v-if="alreadyMarked" color="processing" class="tag-tight">Already taken</a-tag>
            <a-tag v-else color="warning" class="tag-tight">Not taken yet</a-tag>
          </template>
          <template #extra>
            <a-space wrap>
              <a-button size="small" @click="markAll('present')">All present</a-button>
              <a-button size="small" @click="markAll('absent')">All absent</a-button>
              <a-button type="primary" :loading="saving" :disabled="!students.length" @click="save">
                {{ $t('submit') }}
              </a-button>
            </a-space>
          </template>

          <div v-if="students.length" class="register">
            <div v-for="student in students" :key="student.student_id" class="row">
              <a-avatar :size="34" :src="student.image_url" class="row-avatar">
                {{ initials(student.name) }}
              </a-avatar>
              <div class="row-id">
                <div class="row-name">{{ student.name }}</div>
                <div class="row-sub">
                  {{ student.roll_number ? `Roll ${student.roll_number} · ` : '' }}{{ student.admission_number }}
                </div>
              </div>
              <a-radio-group
                v-model:value="student.status" size="small" button-style="solid" class="row-status"
              >
                <a-radio-button
                  v-for="s in ATTENDANCE_STATUSES" :key="s.value" :value="s.value"
                  :class="`opt-${s.value}`"
                >
                  {{ s.short }}
                </a-radio-button>
              </a-radio-group>
              <a-input
                v-model:value="student.remarks" size="small" placeholder="Remarks"
                class="row-remarks" allow-clear
              />
            </div>
          </div>
          <a-empty v-else description="No students enrolled in that class for the current year" />
        </a-card>
      </a-spin>

      <a-card size="small" title="This month" style="margin-top: 16px">
        <ReportChart
          :data="summaryDays"
          :fields="[{ key: 'rate', label: 'Attendance %' }]"
          type="area"
          :height="220"
        />
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * The attendance register.
 *
 * Everyone defaults to PRESENT and the teacher only changes the exceptions —
 * that is how a register is actually taken, and it makes a full class two
 * clicks. Saving upserts on (student, date, subject), so re-opening the same
 * day corrects rather than duplicates.
 *
 * Future dates are blocked: a register for tomorrow is a guess, not a record.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { ATTENDANCE_STATUSES } from './schoolOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { date } = useFormat();

const picker = reactive({
  class_id: undefined,
  section_id: undefined,
  subject_id: undefined,
  date: new Date().toISOString().slice(0, 10),
});

const students = ref([]);
const alreadyMarked = ref(false);
const loading = ref(false);
const saving = ref(false);
const summaryDays = ref([]);

const meta = ref({});
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const sectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => s.class_id === picker.class_id)
  .map(s => ({ value: s.id, label: s.name })));
const subjectOptions = computed(() => (meta.value.subjects || [])
  .filter(s => !s.class_id || s.class_id === picker.class_id)
  .map(s => ({ value: s.id, label: s.name })));

const counts = computed(() => {
  const out = {};
  students.value.forEach(s => { out[s.status] = (out[s.status] || 0) + 1; });
  return out;
});

const rate = computed(() => {
  if (!students.value.length) return 0;
  const present = students.value.filter(s => ['present', 'late', 'half_day'].includes(s.status)).length;
  return Math.round((present / students.value.length) * 100);
});

/** A register for a future date would be a guess. */
function futureDate(current) {
  return current && current.valueOf() > Date.now();
}

function onClassChange() {
  picker.section_id = undefined;
  picker.subject_id = undefined;
  loadRegister();
}

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2)
    .map(p => p[0].toUpperCase()).join('');
}

function markAll(status) {
  students.value.forEach(s => { s.status = status; });
}

async function loadRegister() {
  if (!picker.class_id) return;
  loading.value = true;
  try {
    const data = await http.get('school/attendance/register', {
      class_id: picker.class_id,
      section_id: picker.section_id || '',
      subject_id: picker.subject_id || '',
      date: picker.date,
    });
    students.value = (data?.students || []).map(s => ({ ...s }));
    alreadyMarked.value = !!data?.already_marked;
    loadSummary();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not load the register'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const data = await http.post('school/attendance/register', {
      class_id: picker.class_id,
      section_id: picker.section_id || null,
      subject_id: picker.subject_id || null,
      date: picker.date,
      entries: students.value.map(s => ({
        student_id: s.student_id,
        enrollment_id: s.enrollment_id,
        status: s.status,
        remarks: s.remarks || null,
      })),
    });
    message.success(`Register saved — ${data.saved} student(s).`);
    alreadyMarked.value = true;
    loadSummary();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save the register'));
  } finally {
    saving.value = false;
  }
}

async function loadSummary() {
  try {
    const first = picker.date.slice(0, 8) + '01';
    const data = await http.get('school/attendance/summary', {
      class_id: picker.class_id,
      section_id: picker.section_id || '',
      start_date: first,
      end_date: picker.date,
    });
    summaryDays.value = data?.days || [];
  } catch (e) {
    summaryDays.value = [];
  }
}

onMounted(async () => {
  try {
    meta.value = await http.get('school/meta');
  } catch (e) { /* selects stay empty */ }
});
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-item {
  width: 190px;
}
.tb-item-sm {
  width: 150px;
}
.tag-tight {
  margin-inline-start: 8px;
}
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}
.sum {
  display: flex;
  flex-direction: column;
  padding: 10px 14px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
}
.sum-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.sum-value {
  font-size: 18px;
  font-weight: 600;
}
.tone-present {
  color: #16a34a;
}
.tone-absent {
  color: #ff4d4f;
}
.tone-late {
  color: #d97706;
}
.register {
  display: flex;
  flex-direction: column;
}
.row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.14);
  flex-wrap: wrap;
}
.row:last-child {
  border-bottom: 0;
}
.row-avatar {
  flex: none;
  background: rgba(109, 40, 217, 0.15);
  color: #6d28d9;
  font-size: 12.5px;
}
.row-id {
  flex: 1 1 180px;
  min-width: 150px;
}
.row-name {
  font-weight: 500;
  font-size: 13.5px;
}
.row-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.row-status {
  flex: none;
}
.row-remarks {
  flex: 1 1 160px;
  min-width: 140px;
  max-width: 260px;
}

/* Colour the selected option so a register reads at a glance. */
.row-status :deep(.opt-present.ant-radio-button-wrapper-checked) {
  background: #16a34a;
  border-color: #16a34a;
}
.row-status :deep(.opt-absent.ant-radio-button-wrapper-checked) {
  background: #ff4d4f;
  border-color: #ff4d4f;
}
.row-status :deep(.opt-late.ant-radio-button-wrapper-checked) {
  background: #d97706;
  border-color: #d97706;
}
.row-status :deep(.opt-excused.ant-radio-button-wrapper-checked) {
  background: #1677ff;
  border-color: #1677ff;
}
.row-status :deep(.opt-half_day.ant-radio-button-wrapper-checked) {
  background: #8c8c8c;
  border-color: #8c8c8c;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-item-sm {
    width: 100%;
  }
}
</style>
