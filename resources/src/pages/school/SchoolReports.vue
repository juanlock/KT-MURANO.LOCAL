<template>
  <div class="page">
    <PageHeader :title="$t('School Reports')" :breadcrumb="[$t('School'), $t('Reports')]">
      <template #actions>
        <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
          <template #icon><FileExcelOutlined /></template>
          {{ $t('Export') }} {{ $t('EXCEL') }}
        </a-button>
        <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
          <template #icon><FilePdfOutlined /></template>
          {{ $t('Export') }} {{ $t('PDF') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-tabs v-model:activeKey="report" @change="onReportChange">
        <a-tab-pane v-for="r in REPORTS" :key="r.key" :tab="r.title" />
      </a-tabs>

      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="current.searchHint"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="academicYearId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Year')" :options="yearOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="classId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('All_Classes')" :options="classOptions" @change="crud.reload"
        />
        <a-select
          v-if="report === 'performance'"
          v-model:value="examId" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Latest_Exam')" :options="examOptions" @change="crud.reload"
        />
        <a-range-picker
          v-if="current.dateRange"
          v-model:value="range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload"
        />
        <a-input-number
          v-if="report === 'absentees'"
          v-model:value="threshold" :min="1" :max="100" class="tb-item-sm"
          addon-after="%" @change="crud.reload"
        />
      </div>

      <div v-if="totalTiles.length" class="totals">
        <div v-for="tile in totalTiles" :key="tile.label" class="total">
          <span class="total-label">{{ tile.label }}</span>
          <span class="total-value">{{ tile.value }}</span>
        </div>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.money">{{ money(record[column.dataIndex] || 0) }}</template>
        <template v-else-if="column.percent">
          <span v-if="record[column.dataIndex] === null || record[column.dataIndex] === undefined" class="muted">—</span>
          <a-tag v-else :color="percentColor(column.dataIndex, record[column.dataIndex])">
            {{ record[column.dataIndex] }}%
          </a-tag>
        </template>
        <template v-else-if="column.decimal">
          {{ record[column.dataIndex] === null || record[column.dataIndex] === undefined
            ? '—' : number(record[column.dataIndex], 1) }}
        </template>
        <template v-else-if="column.key === 'student_name'">
          <button type="button" class="link-cell" @click="$router.push(`/school/students/${record.id}`)">
            <div class="cell-name">{{ record.student_name }}</div>
            <div class="cell-sub">{{ record.admission_number }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'earliest_due'">
          <span v-if="record.earliest_due">
            {{ date(record.earliest_due) }}
            <a-tag v-if="record.days_overdue > 0" color="error" class="tag-tight">
              {{ record.days_overdue }}{{ $t('d_late') }}
            </a-tag>
          </span>
          <span v-else class="muted">—</span>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * School reports — six views over one shell, because they share every filter
 * and differ only in columns and endpoint. Switching tabs swaps the endpoint on
 * the SAME crud instance so the year, class and search survive the switch.
 *
 * Totals come from the server for the whole filtered set, never summed from the
 * page on screen — a 10-row page would otherwise report a 10-row total.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import { t } from '../../i18n';

const { money, number, date } = useFormat();

const REPORTS = [
  { key: 'enrollment', title: t('Enrolment', 'Enrolment'), endpoint: 'school/reports/enrollment', sort: 'students', searchHint: t('Search_Class', 'Search class…'), dateRange: false },
  { key: 'attendance', title: t('Attendance', 'Attendance'), endpoint: 'school/reports/attendance', sort: 'rate', searchHint: t('Search_Class', 'Search class…'), dateRange: true },
  { key: 'absentees', title: t('Poor_Attendance', 'Poor attendance'), endpoint: 'school/reports/absentees', sort: 'rate', searchHint: t('Search_Student', 'Search student…'), dateRange: true },
  { key: 'performance', title: t('Exam_Performance', 'Exam performance'), endpoint: 'school/reports/performance', sort: 'average_pct', searchHint: t('Search', 'Search…'), dateRange: false },
  { key: 'fees', title: t('Fee_Collection', 'Fee collection'), endpoint: 'school/reports/fees', sort: 'outstanding', searchHint: t('Search_Class', 'Search class…'), dateRange: true },
  { key: 'defaulters', title: t('Fee_Defaulters', 'Fee defaulters'), endpoint: 'school/reports/defaulters', sort: 'due', searchHint: t('Search_Student_Or_Guardian', 'Search student or guardian…'), dateRange: false },
  { key: 'teachers', title: t('Teacher_Workload', 'Teacher workload'), endpoint: 'school/reports/teachers', sort: 'weekly_periods', searchHint: t('Search_Teacher', 'Search teacher…'), dateRange: false },
];

const report = ref('enrollment');
const current = computed(() => REPORTS.find(r => r.key === report.value) || REPORTS[0]);

const academicYearId = ref(undefined);
const classId = ref(undefined);
const examId = ref(undefined);
const range = ref(null);
const threshold = ref(75);
const endpoint = ref(REPORTS[0].endpoint);

const crud = useCrudTable(() => endpoint.value, {
  sortField: 'students',
  select: p => ({ rows: p?.rows || [], total: p?.totalRows || 0 }),
  params: () => ({
    academic_year_id: academicYearId.value || '',
    class_id: classId.value || '',
    exam_id: examId.value || '',
    threshold: threshold.value || '',
    start_date: range.value?.[0] || '',
    end_date: range.value?.[1] || '',
  }),
});

function onReportChange() {
  endpoint.value = current.value.endpoint;
  crud.sortField.value = current.value.sort;
  // "Poor attendance" and "defaulters" are worst-first lists.
  crud.sortType.value = ['absentees'].includes(report.value) ? 'asc' : 'desc';
  crud.reload();
}

const COLUMNS = {
  enrollment: [
    { title: t('Class', 'Class'), dataIndex: 'class_name', key: 'class_name', sorter: true },
    { title: t('Level', 'Level'), dataIndex: 'level', key: 'level', sorter: true, width: 90 },
    { title: t('Sections', 'Sections'), dataIndex: 'sections', key: 'sections', sorter: true, width: 110 },
    { title: t('Students', 'Students'), dataIndex: 'students', key: 'students', sorter: true, width: 110 },
    { title: t('Male', 'Male'), dataIndex: 'male', key: 'male', sorter: true, width: 90 },
    { title: t('Female', 'Female'), dataIndex: 'female', key: 'female', sorter: true, width: 100 },
    { title: t('Capacity', 'Capacity'), dataIndex: 'capacity', key: 'capacity', width: 110 },
    { title: t('Utilisation', 'Utilisation'), dataIndex: 'utilisation', key: 'utilisation', sorter: true, percent: true, width: 130 },
  ],
  attendance: [
    { title: t('Class', 'Class'), dataIndex: 'class_name', key: 'class_name', sorter: true },
    { title: t('Marked', 'Marked'), dataIndex: 'marked_days', key: 'marked_days', sorter: true, width: 110 },
    { title: t('Present', 'Present'), dataIndex: 'present', key: 'present', sorter: true, width: 110 },
    { title: t('Absent', 'Absent'), dataIndex: 'absent', key: 'absent', sorter: true, width: 110 },
    { title: t('Late', 'Late'), dataIndex: 'late', key: 'late', sorter: true, width: 100 },
    { title: t('Excused', 'Excused'), dataIndex: 'excused', key: 'excused', sorter: true, width: 110 },
    { title: t('Rate', 'Rate'), dataIndex: 'rate', key: 'rate', sorter: true, percent: true, width: 110 },
  ],
  absentees: [
    { title: t('Student', 'Student'), dataIndex: 'student_name', key: 'student_name', sorter: true },
    { title: t('Class', 'Class'), dataIndex: 'class_name', key: 'class_name', width: 140 },
    { title: t('Section', 'Section'), dataIndex: 'section_name', key: 'section_name', width: 100 },
    { title: t('Guardian_Phone', 'Guardian phone'), dataIndex: 'guardian_phone', key: 'guardian_phone', width: 160 },
    { title: t('Marked', 'Marked'), dataIndex: 'marked_days', key: 'marked_days', sorter: true, width: 110 },
    { title: t('Absent', 'Absent'), dataIndex: 'absent', key: 'absent', sorter: true, width: 100 },
    { title: t('Rate', 'Rate'), dataIndex: 'rate', key: 'rate', sorter: true, percent: true, width: 110 },
  ],
  performance: [
    { title: t('Class', 'Class'), dataIndex: 'class_name', key: 'class_name', sorter: true, width: 140 },
    { title: t('Subject', 'Subject'), dataIndex: 'subject_name', key: 'subject_name', sorter: true },
    { title: t('Out_Of', 'Out of'), dataIndex: 'max_marks', key: 'max_marks', width: 100 },
    { title: t('Entered', 'Entered'), dataIndex: 'entered', key: 'entered', sorter: true, width: 100 },
    { title: t('Absent', 'Absent'), dataIndex: 'absent', key: 'absent', sorter: true, width: 100 },
    { title: t('Average', 'Average'), dataIndex: 'average', key: 'average', sorter: true, decimal: true, width: 110 },
    { title: t('Avg_Percent', 'Avg %'), dataIndex: 'average_pct', key: 'average_pct', sorter: true, percent: true, width: 110 },
    { title: t('Highest', 'Highest'), dataIndex: 'highest', key: 'highest', sorter: true, decimal: true, width: 110 },
    { title: t('Lowest', 'Lowest'), dataIndex: 'lowest', key: 'lowest', sorter: true, decimal: true, width: 110 },
    { title: t('Pass_Rate', 'Pass rate'), dataIndex: 'pass_rate', key: 'pass_rate', sorter: true, percent: true, width: 120 },
  ],
  fees: [
    { title: t('Class', 'Class'), dataIndex: 'class_name', key: 'class_name', sorter: true },
    { title: t('Invoices', 'Invoices'), dataIndex: 'invoices', key: 'invoices', sorter: true, width: 110 },
    { title: t('Billed', 'Billed'), dataIndex: 'billed', key: 'billed', sorter: true, money: true, width: 140 },
    { title: t('Collected', 'Collected'), dataIndex: 'collected', key: 'collected', sorter: true, money: true, width: 140 },
    { title: t('Outstanding', 'Outstanding'), dataIndex: 'outstanding', key: 'outstanding', sorter: true, money: true, width: 150 },
    { title: t('Collection_Rate', 'Collection rate'), dataIndex: 'collection_rate', key: 'collection_rate', sorter: true, percent: true, width: 150 },
  ],
  defaulters: [
    { title: t('Student', 'Student'), dataIndex: 'student_name', key: 'student_name', sorter: true },
    { title: t('Class', 'Class'), dataIndex: 'class_name', key: 'class_name', width: 140 },
    { title: t('Guardian', 'Guardian'), dataIndex: 'guardian_name', key: 'guardian_name', width: 160 },
    { title: t('Phone', 'Phone'), dataIndex: 'guardian_phone', key: 'guardian_phone', width: 150 },
    { title: t('Invoices', 'Invoices'), dataIndex: 'invoices', key: 'invoices', sorter: true, width: 110 },
    { title: t('Owed', 'Owed'), dataIndex: 'due', key: 'due', sorter: true, money: true, width: 140 },
    { title: t('Oldest_Due', 'Oldest due'), key: 'earliest_due', dataIndex: 'earliest_due', sorter: true, width: 180 },
  ],
  teachers: [
    { title: t('Teacher', 'Teacher'), dataIndex: 'teacher_name', key: 'teacher_name', sorter: true },
    { title: t('Specialism', 'Specialism'), dataIndex: 'specialization', key: 'specialization', width: 170 },
    { title: t('Periods_Per_Week', 'Periods / week'), dataIndex: 'weekly_periods', key: 'weekly_periods', sorter: true, width: 150 },
    { title: t('Subjects', 'Subjects'), dataIndex: 'subjects', key: 'subjects', sorter: true, width: 110 },
    { title: t('Classes', 'Classes'), dataIndex: 'classes', key: 'classes', sorter: true, width: 110 },
    { title: t('Form_Classes', 'Form classes'), dataIndex: 'form_classes', key: 'form_classes', sorter: true, width: 140 },
    { title: t('Busiest_Day', 'Busiest day'), dataIndex: 'busiest_day', key: 'busiest_day', width: 130 },
  ],
};

const columns = computed(() => COLUMNS[report.value] || COLUMNS.enrollment);

/** Higher is better for most rates; utilisation over 100 is overcrowding. */
function percentColor(field, value) {
  if (field === 'utilisation') return value > 100 ? 'error' : value >= 85 ? 'warning' : 'success';
  if (field === 'rate' || field === 'pass_rate' || field === 'collection_rate' || field === 'average_pct') {
    return value >= 90 ? 'success' : value >= 75 ? 'warning' : 'error';
  }
  return 'default';
}

const TOTAL_LABELS = {
  students: t('Students', 'Students'), male: t('Male', 'Male'), female: t('Female', 'Female'),
  present: t('Present', 'Present'), absent: t('Absent', 'Absent'), marked_days: t('Marked_Days', 'Marked days'),
  entered: t('Marks_Entered', 'Marks entered'), passed: t('Passed', 'Passed'),
  billed: t('Billed', 'Billed'), collected: t('Collected', 'Collected'), outstanding: t('Outstanding', 'Outstanding'),
  due: t('Owed', 'Owed'), weekly_periods: t('Periods_Per_Week', 'Periods / week'),
};
const MONEY_TOTALS = ['billed', 'collected', 'outstanding', 'due'];

const totalTiles = computed(() => {
  const totals = crud.payload.value?.totals || {};
  return Object.entries(totals).map(([key, value]) => ({
    label: TOTAL_LABELS[key] || key,
    value: MONEY_TOTALS.includes(key) ? money(value || 0) : number(value || 0, 0),
  }));
});

const meta = ref({});
const exams = ref([]);
const yearOptions = computed(() => (meta.value.academic_years || []).map(y => ({
  value: y.id, label: y.is_current ? `${y.name} (${t('Current', 'current')})` : y.name,
})));
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const examOptions = computed(() => exams.value.map(e => ({ value: e.id, label: e.name })));

const exporting = ref('');

async function doExport(kind) {
  exporting.value = kind;
  try {
    // limit=-1 is the legacy "all rows" convention, so the export covers the
    // whole filtered set rather than the visible page.
    const data = await http.get(endpoint.value, {
      page: 1,
      limit: -1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      academic_year_id: academicYearId.value || '',
      class_id: classId.value || '',
      exam_id: examId.value || '',
      threshold: threshold.value || '',
      start_date: range.value?.[0] || '',
      end_date: range.value?.[1] || '',
    });
    const rows = data?.rows || [];
    if (!rows.length) {
      message.warning(t('NodataAvailable', 'Nothing to export'));
      return;
    }
    const title = `${t('School', 'School')} — ${current.value.title}`;
    if (kind === 'excel') await exportExcel(title, columns.value, rows);
    else await exportPdf(title, columns.value, rows);
  } catch (e) {
    message.error(t('InvalidData', 'Could not export this report'));
  } finally {
    exporting.value = '';
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    meta.value = await http.get('school/meta');
    academicYearId.value = meta.value.current_year_id;
    const examData = await http.get('school/exams', { limit: -1, page: 1 });
    exams.value = examData?.exams || [];
  } catch (e) { /* filters stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
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
  width: 120px;
}
.tb-range {
  width: 230px;
}
.totals {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}
.total {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.total-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.total-value {
  font-size: 17px;
  font-weight: 600;
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
.tag-tight {
  margin-inline-start: 6px;
}
</style>
