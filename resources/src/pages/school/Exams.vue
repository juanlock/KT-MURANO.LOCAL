<template>
  <div class="page">
    <PageHeader title="Exams" subtitle="Schedules, mark sheets and report cards." :breadcrumb="['School', 'Exams']">
      <template #actions>
        <a-button type="primary" @click="openExam(null)">
          <template #icon><PlusOutlined /></template>
          New exam
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search exam…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.academic_year_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All years" :options="yearOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.term" class="tb-item-sm" allow-clear
          placeholder="Term" :options="EXAM_TERMS" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item-sm" allow-clear
          placeholder="Status" :options="EXAM_STATUSES" @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="link-cell" @click="openPapers(record)">
            <div class="cell-name">{{ record.name }}</div>
            <div class="cell-sub">{{ record.year_name }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'term'">{{ labelOf(EXAM_TERMS, record.term) }}</template>
        <template v-else-if="column.key === 'dates'">
          <span v-if="record.start_date">
            {{ date(record.start_date) }}{{ record.end_date ? ' → ' + date(record.end_date) : '' }}
          </span>
          <span v-else class="muted">Not scheduled</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(EXAM_STATUSES, record.status).color">
            {{ labelOf(EXAM_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip title="Papers & marks">
              <a-button type="text" size="small" @click="openPapers(record)">
                <template #icon><FileTextOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip title="Report card">
              <a-button type="text" size="small" @click="openReportCard(record)">
                <template #icon><TrophyOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openExam(record)">
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

    <!-- Exam form -->
    <a-modal
      :open="examOpen" :title="editingExam ? 'Edit exam' : 'New exam'" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitExam" @cancel="examOpen = false"
    >
      <a-form ref="examRef" :model="examForm" :rules="examRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="14">
            <a-form-item label="Name *" name="name">
              <a-input v-model:value="examForm.name" placeholder="e.g. Mid-term 2026" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="10">
            <a-form-item label="Term *" name="term">
              <a-select v-model:value="examForm.term" :options="EXAM_TERMS" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Academic year *" name="academic_year_id">
              <a-select v-model:value="examForm.academic_year_id" :options="yearOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Status *" name="status">
              <a-select v-model:value="examForm.status" :options="EXAM_STATUSES" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="12">
            <a-form-item label="Starts">
              <a-date-picker v-model:value="examForm.start_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="12">
            <a-form-item label="Ends">
              <a-date-picker v-model:value="examForm.end_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')" style="margin-bottom: 0">
              <a-textarea v-model:value="examForm.notes" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Papers drawer -->
    <a-drawer :open="papersOpen" :width="880" :title="`${currentExam?.name || ''} — papers`" @close="papersOpen = false">
      <template #extra>
        <a-space>
          <a-select
            v-model:value="paperClass" style="width: 170px" allow-clear show-search
            option-filter-prop="label" placeholder="All classes" :options="classOptions" @change="loadPapers"
          />
          <a-button :disabled="!paperClass" @click="generateOpen = true">Generate all</a-button>
          <a-button type="primary" @click="openPaper(null)">Add paper</a-button>
        </a-space>
      </template>

      <a-table
        :columns="paperColumns" :data-source="papers" :loading="papersLoading"
        :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'exam_date'">
            <span v-if="record.exam_date">{{ date(record.exam_date) }}{{ record.start_time ? ' ' + record.start_time : '' }}</span>
            <span v-else class="muted">—</span>
          </template>
          <template v-else-if="column.key === 'marks'">{{ record.max_marks }} (pass {{ record.pass_marks }})</template>
          <template v-else-if="column.key === 'entered'">
            <a-tag :color="record.results_entered ? 'success' : 'default'">
              {{ record.results_entered }} entered
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="0">
              <a-tooltip title="Enter marks">
                <a-button type="text" size="small" @click="openSheet(record)">
                  <template #icon><EditOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip title="Edit paper">
                <a-button type="text" size="small" @click="openPaper(record)">
                  <template #icon><SettingOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Delete')">
                <a-button type="text" size="small" danger @click="removePaper(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
        <template #emptyText>
          <a-empty :image="simpleEmptyImage" description="No papers scheduled" />
        </template>
      </a-table>
    </a-drawer>

    <!-- Paper form -->
    <a-modal
      :open="paperOpen" :title="editingPaper ? 'Edit paper' : 'Add paper'" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitPaper" @cancel="paperOpen = false"
    >
      <a-form ref="paperRef" :model="paperForm" :rules="paperRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Class *" name="class_id">
              <a-select
                v-model:value="paperForm.class_id" show-search option-filter-prop="label"
                :options="classOptions" @change="paperForm.subject_id = undefined"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Subject *" name="subject_id">
              <a-select
                v-model:value="paperForm.subject_id" show-search option-filter-prop="label"
                :options="paperSubjectOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Date">
              <a-date-picker v-model:value="paperForm.exam_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Starts">
              <a-time-picker v-model:value="paperForm.start_time" format="HH:mm" value-format="HH:mm" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Minutes">
              <a-input-number v-model:value="paperForm.duration_minutes" :min="5" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Max marks *" name="max_marks">
              <a-input-number v-model:value="paperForm.max_marks" :min="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Pass marks *" name="pass_marks">
              <a-input-number v-model:value="paperForm.pass_marks" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Room" style="margin-bottom: 0">
              <a-input v-model:value="paperForm.room" allow-clear />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Generate papers -->
    <a-modal
      :open="generateOpen" title="Generate papers" :width="440"
      :confirm-loading="saving" ok-text="Generate" :cancel-text="$t('Cancel')"
      @ok="submitGenerate" @cancel="generateOpen = false"
    >
      <p class="hint">
        Schedules every active subject of the selected class. Subjects already scheduled are skipped.
      </p>
      <a-form layout="vertical">
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item label="Max marks">
              <a-input-number v-model:value="generateForm.max_marks" :min="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="Pass marks" extra="Blank = each subject's own pass %">
              <a-input-number v-model:value="generateForm.pass_marks" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Mark sheet -->
    <a-drawer :open="sheetOpen" :width="760" :title="sheetTitle" @close="sheetOpen = false">
      <template #extra>
        <a-button type="primary" :loading="savingSheet" @click="saveSheet">{{ $t('submit') }}</a-button>
      </template>

      <a-alert
        v-if="sheet.paper" type="info" show-icon
        :message="`Out of ${sheet.paper.max_marks}, pass mark ${sheet.paper.pass_marks}. Grades are derived on save.`"
        style="margin-bottom: 12px"
      />

      <div v-for="row in sheetRows" :key="row.student_id" class="mark-row">
        <div class="mark-id">
          <div class="mark-name">{{ row.name }}</div>
          <div class="mark-sub">
            {{ row.roll_number ? `Roll ${row.roll_number} · ` : '' }}{{ row.admission_number }}
          </div>
        </div>
        <a-input-number
          v-model:value="row.marks" :min="0" :max="sheet.paper?.max_marks"
          :disabled="row.is_absent" placeholder="Marks" class="mark-input"
        />
        <a-checkbox v-model:checked="row.is_absent" class="mark-abs">Absent</a-checkbox>
        <a-tag v-if="row.grade" :color="gradeColor(row.grade)" class="mark-grade">{{ row.grade }}</a-tag>
        <a-input v-model:value="row.remarks" size="small" placeholder="Remarks" class="mark-remarks" allow-clear />
      </div>
      <a-empty v-if="!sheetRows.length" description="No students enrolled in that class" />
    </a-drawer>

    <!-- Report card -->
    <a-drawer :open="cardOpen" :width="960" :title="`${currentExam?.name || ''} — report card`" @close="cardOpen = false">
      <template #extra>
        <a-space>
          <a-select
            v-model:value="cardClass" style="width: 180px" show-search option-filter-prop="label"
            placeholder="Class" :options="classOptions" @change="loadReportCard"
          />
        </a-space>
      </template>

      <div v-if="card.rows" class="card-summary">
        <div class="sum">
          <span class="sum-label">Class average</span>
          <span class="sum-value">{{ card.class_average !== null ? card.class_average + '%' : '—' }}</span>
        </div>
        <div class="sum">
          <span class="sum-label">Pass rate</span>
          <span class="sum-value">{{ card.pass_rate !== null ? card.pass_rate + '%' : '—' }}</span>
        </div>
        <div class="sum">
          <span class="sum-label">Students</span>
          <span class="sum-value">{{ card.rows.length }}</span>
        </div>
      </div>

      <a-table
        :columns="cardColumns" :data-source="card.rows || []" :loading="cardLoading"
        :row-key="r => r.student_id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'position'">
            <a-tag :color="record.position <= 3 ? 'gold' : 'default'">#{{ record.position }}</a-tag>
          </template>
          <template v-else-if="column.key === 'student'">
            <div class="cell-name">{{ record.name }}</div>
            <div class="cell-sub">{{ record.admission_number }}</div>
          </template>
          <template v-else-if="column.key === 'obtained'">{{ record.obtained }} / {{ record.total }}</template>
          <template v-else-if="column.key === 'percentage'">
            {{ record.percentage !== null ? record.percentage + '%' : '—' }}
          </template>
          <template v-else-if="column.key === 'grade'">
            <a-tag :color="gradeColor(record.grade)">{{ record.grade || '—' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'result'">
            <a-tag :color="record.result === 'Pass' ? 'success' : 'error'">{{ record.result }}</a-tag>
          </template>
          <template v-else-if="column.key.startsWith('subj_')">
            <span v-if="subjectMark(record, column.subjectIndex) !== null">
              {{ subjectMark(record, column.subjectIndex) }}
            </span>
            <span v-else class="muted">—</span>
          </template>
        </template>
        <template #emptyText>
          <a-empty :image="simpleEmptyImage" description="Pick a class to build its report card" />
        </template>
      </a-table>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Exams: the list, the paper schedule, mark entry and the report card.
 *
 * Marks are entered a whole paper at a time and the GRADE is derived on save by
 * the backend — one scale applied identically, so nobody argues about a 79.5.
 * The report card ranks by percentage with ties sharing a place (1, 2, 2, 4),
 * the convention that is defensible to a parent.
 */
import { ref, reactive, computed, onMounted, createVNode } from 'vue';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, FileTextOutlined,
  TrophyOutlined, SettingOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { EXAM_TERMS, EXAM_STATUSES, labelOf, optionOf, gradeColor } from './schoolOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { date } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const filters = reactive({ academic_year_id: undefined, term: undefined, status: undefined });

const crud = useCrudTable('school/exams', {
  rowsKey: 'exams',
  sortField: 'start_date',
  params: () => ({
    academic_year_id: filters.academic_year_id || '',
    term: filters.term || '',
    status: filters.status || '',
  }),
});

const meta = ref({});
const yearOptions = computed(() => (meta.value.academic_years || []).map(y => ({
  value: y.id, label: y.is_current ? `${y.name} (current)` : y.name,
})));
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const paperSubjectOptions = computed(() => (meta.value.subjects || [])
  .filter(s => !s.class_id || s.class_id === paperForm.value.class_id)
  .map(s => ({ value: s.id, label: s.name })));

const columns = computed(() => [
  { title: 'Exam', key: 'name', dataIndex: 'name', sorter: true },
  { title: 'Term', key: 'term', dataIndex: 'term', sorter: true, width: 120 },
  { title: 'Dates', key: 'dates', width: 240 },
  { title: 'Papers', dataIndex: 'papers', key: 'papers', width: 100 },
  { title: 'Status', key: 'status', dataIndex: 'status', sorter: true, width: 140 },
  { title: '', key: 'actions', width: 150 },
]);

const saving = ref(false);

// ---------------- exam form ----------------

const examRef = ref();
const examOpen = ref(false);
const editingExam = ref(null);
const examForm = ref(emptyExam());

function emptyExam() {
  return {
    name: '', term: 'term_1', academic_year_id: meta.value.current_year_id,
    start_date: null, end_date: null, status: 'draft', notes: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const examRules = computed(() => ({
  name: required(), term: required(), academic_year_id: required(), status: required(),
}));

function openExam(record) {
  editingExam.value = record;
  examForm.value = record ? { ...emptyExam(), ...record } : emptyExam();
  examOpen.value = true;
  examRef.value?.clearValidate?.();
}

async function submitExam() {
  try {
    await examRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editingExam.value) await http.put(`school/exams/${editingExam.value.id}`, examForm.value);
    else await http.post('school/exams', examForm.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    examOpen.value = false;
    editingExam.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this exam'));
  } finally {
    saving.value = false;
  }
}

// ---------------- papers ----------------

const papersOpen = ref(false);
const papersLoading = ref(false);
const currentExam = ref(null);
const papers = ref([]);
const paperClass = ref(undefined);

const paperColumns = [
  { title: 'Class', dataIndex: 'class_name', key: 'class_name', width: 140 },
  { title: 'Subject', dataIndex: 'subject_name', key: 'subject_name' },
  { title: 'When', key: 'exam_date', dataIndex: 'exam_date', width: 170 },
  { title: 'Marks', key: 'marks', width: 160 },
  { title: 'Room', dataIndex: 'room', key: 'room', width: 100 },
  { title: 'Results', key: 'entered', width: 130 },
  { title: '', key: 'actions', width: 120 },
];

async function openPapers(exam) {
  currentExam.value = exam;
  papersOpen.value = true;
  loadPapers();
}

async function loadPapers() {
  if (!currentExam.value) return;
  papersLoading.value = true;
  try {
    const data = await http.get(`school/exams/${currentExam.value.id}/papers`, {
      class_id: paperClass.value || '',
    });
    papers.value = data?.papers || [];
  } catch (e) {
    papers.value = [];
  } finally {
    papersLoading.value = false;
  }
}

const paperRef = ref();
const paperOpen = ref(false);
const editingPaper = ref(null);
const paperForm = ref(emptyPaper());

function emptyPaper() {
  return {
    class_id: paperClass.value, subject_id: undefined, exam_date: null,
    start_time: null, duration_minutes: 60, max_marks: 100, pass_marks: 40, room: '',
  };
}

const paperRules = computed(() => ({
  class_id: required(), subject_id: required(), max_marks: required(), pass_marks: required(),
}));

function openPaper(record) {
  editingPaper.value = record;
  paperForm.value = record ? { ...emptyPaper(), ...record } : emptyPaper();
  paperOpen.value = true;
  paperRef.value?.clearValidate?.();
}

async function submitPaper() {
  try {
    await paperRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    const base = `school/exams/${currentExam.value.id}/papers`;
    if (editingPaper.value) await http.put(`${base}/${editingPaper.value.id}`, paperForm.value);
    else await http.post(base, paperForm.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    paperOpen.value = false;
    editingPaper.value = null;
    loadPapers();
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this paper'));
  } finally {
    saving.value = false;
  }
}

function removePaper(record) {
  Modal.confirm({
    title: `Delete ${record.subject_name}?`,
    icon: createVNode(ExclamationCircleOutlined),
    content: record.results_entered
      ? `${record.results_entered} mark(s) are recorded on this paper and will be deleted with it.`
      : 'This paper has no marks recorded.',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`school/exams/${currentExam.value.id}/papers/${record.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        loadPapers();
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData', 'Could not delete this paper'));
      }
    },
  });
}

const generateOpen = ref(false);
const generateForm = reactive({ max_marks: 100, pass_marks: null });

async function submitGenerate() {
  saving.value = true;
  try {
    const data = await http.post(`school/exams/${currentExam.value.id}/papers/generate`, {
      class_id: paperClass.value,
      max_marks: generateForm.max_marks,
      pass_marks: generateForm.pass_marks,
    });
    message.success(`${data.count} paper(s) scheduled.`);
    generateOpen.value = false;
    loadPapers();
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not generate the papers'));
  } finally {
    saving.value = false;
  }
}

// ---------------- mark sheet ----------------

const sheetOpen = ref(false);
const savingSheet = ref(false);
const sheet = ref({});
const sheetRows = ref([]);
const currentPaper = ref(null);

const sheetTitle = computed(() => {
  const p = sheet.value.paper;
  return p ? `${p.subject_name} — ${p.class_name}` : 'Mark sheet';
});

async function openSheet(paper) {
  currentPaper.value = paper;
  try {
    const data = await http.get(`school/exams/${currentExam.value.id}/papers/${paper.id}/sheet`);
    sheet.value = data || {};
    sheetRows.value = (data?.students || []).map(s => ({ ...s }));
    sheetOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the mark sheet'));
  }
}

async function saveSheet() {
  savingSheet.value = true;
  try {
    const data = await http.post(
      `school/exams/${currentExam.value.id}/papers/${currentPaper.value.id}/results`,
      {
        results: sheetRows.value.map(r => ({
          student_id: r.student_id,
          marks: r.is_absent ? null : r.marks,
          is_absent: r.is_absent,
          remarks: r.remarks || null,
        })),
      }
    );
    message.success(`${data.saved} mark(s) saved.`);
    sheetOpen.value = false;
    loadPapers();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save the marks'));
  } finally {
    savingSheet.value = false;
  }
}

// ---------------- report card ----------------

const cardOpen = ref(false);
const cardLoading = ref(false);
const cardClass = ref(undefined);
const card = ref({});

const cardColumns = computed(() => {
  const base = [
    { title: '#', key: 'position', dataIndex: 'position', width: 70 },
    { title: 'Student', key: 'student', dataIndex: 'name' },
  ];
  const subjects = (card.value.papers || []).map((p, i) => ({
    title: p.subject_name,
    key: `subj_${p.id}`,
    subjectIndex: i,
    width: 110,
  }));
  return [
    ...base,
    ...subjects,
    { title: 'Total', key: 'obtained', dataIndex: 'obtained', width: 130 },
    { title: '%', key: 'percentage', dataIndex: 'percentage', width: 90 },
    { title: 'Grade', key: 'grade', dataIndex: 'grade', width: 90 },
    { title: 'Result', key: 'result', dataIndex: 'result', width: 100 },
  ];
});

function subjectMark(record, index) {
  const subject = record.subjects?.[index];
  if (!subject) return null;
  if (subject.is_absent) return 'AB';
  return subject.marks;
}

function openReportCard(exam) {
  currentExam.value = exam;
  card.value = {};
  cardOpen.value = true;
  if (cardClass.value) loadReportCard();
}

async function loadReportCard() {
  if (!cardClass.value || !currentExam.value) return;
  cardLoading.value = true;
  try {
    card.value = await http.get(`school/exams/${currentExam.value.id}/report-card`, {
      class_id: cardClass.value,
    });
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not build the report card'));
  } finally {
    cardLoading.value = false;
  }
}

onMounted(async () => {
  try {
    meta.value = await http.get('school/meta');
  } catch (e) { /* selects stay empty */ }
  crud.fetchRows();
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.tb-item-sm {
  width: 130px;
}
.link-cell {
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.cell-name {
  font-weight: 500;
}
.link-cell:hover .cell-name {
  color: #6d28d9;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.hint {
  margin: 0 0 12px;
  font-size: 12.5px;
  opacity: 0.7;
}
.mark-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 2px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.14);
  flex-wrap: wrap;
}
.mark-id {
  flex: 1 1 180px;
  min-width: 150px;
}
.mark-name {
  font-weight: 500;
  font-size: 13.5px;
}
.mark-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.mark-input {
  width: 110px;
  flex: none;
}
.mark-abs {
  flex: none;
  white-space: nowrap;
}
.mark-grade {
  flex: none;
  margin-inline-end: 0;
}
.mark-remarks {
  flex: 1 1 140px;
  min-width: 120px;
  max-width: 220px;
}
.card-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
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
</style>
