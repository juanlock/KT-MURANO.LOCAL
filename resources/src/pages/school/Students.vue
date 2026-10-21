<template>
  <div class="page">
    <PageHeader :title="s('Students')" :breadcrumb="[s('School'), s('Students')]">
      <template #actions>
        <a-button @click="$router.push('/school/enrollments')">
          <template #icon><SwapOutlined /></template>
          {{ s('Enrolment') }}
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/school/students/create')">
          <template #icon><UserAddOutlined /></template>
          {{ s('Admit student') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Highlights of the year (2026-10-21): best student, class, section, attendance... -->
    <div v-if="hl && hl.graded" class="hl-grid">
      <div class="hl-card hl-gold" @click="hl.top[0] && open(hl.top[0])">
        <div class="hl-label">🥇 {{ s('Best student') }}</div>
        <div class="hl-value">{{ hl.top[0]?.name }}</div>
        <div class="hl-sub">{{ hl.top[0]?.place }} · <b :class="markClass(hl.top[0]?.average)">{{ hl.top[0]?.average }}</b></div>
        <div v-for="(t, i) in hl.top.slice(1)" :key="t.id" class="hl-sub">{{ i === 0 ? '🥈' : '🥉' }} {{ t.name }} · {{ t.average }}</div>
      </div>
      <div class="hl-card">
        <div class="hl-label">🏫 {{ s('Best grade') }}</div>
        <div class="hl-value">{{ hl.best_class?.label || '—' }}</div>
        <div class="hl-sub">{{ s('Average {a} · {n} students', { a: hl.best_class?.average, n: hl.best_class?.students }) }}</div>
      </div>
      <div class="hl-card">
        <div class="hl-label">👥 {{ s('Best section') }}</div>
        <div class="hl-value">{{ hl.best_section?.label || '—' }}</div>
        <div class="hl-sub">{{ s('Average {a} · {n} students', { a: hl.best_section?.average, n: hl.best_section?.students }) }}</div>
      </div>
      <div class="hl-card">
        <div class="hl-label">📊 {{ s('School average') }}</div>
        <div class="hl-value" :class="markClass(hl.school_average)">{{ hl.school_average }}</div>
        <div class="hl-sub">{{ s('{n} students with marks · pass mark {p}', { n: hl.graded, p: hl.pass_mark }) }}</div>
      </div>
      <div class="hl-card" :class="{ 'hl-risk': hl.at_risk }">
        <div class="hl-label">⚠️ {{ s('Below the pass mark') }}</div>
        <div class="hl-value">{{ hl.at_risk }}</div>
        <div class="hl-sub">{{ s('students with a weighted average under {p}', { p: hl.pass_mark }) }}</div>
      </div>
      <div v-if="hl.best_attendance" class="hl-card" @click="open(hl.best_attendance)">
        <div class="hl-label">✅ {{ s('Best attendance') }}</div>
        <div class="hl-value">{{ hl.best_attendance.name }}</div>
        <div class="hl-sub">{{ hl.best_attendance.place }} · {{ hl.best_attendance.rate }}%</div>
      </div>
      <div v-if="hl.most_absences" class="hl-card hl-risk" @click="open(hl.most_absences)">
        <div class="hl-label">🚩 {{ s('Most absences') }}</div>
        <div class="hl-value">{{ hl.most_absences.name }}</div>
        <div class="hl-sub">{{ hl.most_absences.place }} · {{ s('{n} absences', { n: hl.most_absences.absent }) }}</div>
      </div>
    </div>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value"
          :placeholder="s('Search name, admission no., guardian…')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.class_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="s('All classes')"
          :options="classOptions" @change="onClassChange"
        />
        <a-select
          v-model:value="filters.section_id" class="tb-item-sm" allow-clear show-search
          option-filter-prop="label" :placeholder="s('Section')"
          :options="sectionOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item-sm" allow-clear
          :placeholder="s('Status')" :options="statusOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.gender" class="tb-item-sm" allow-clear
          :placeholder="s('Gender')" :options="genderOptions" @change="crud.reload"
        />
        <a-tooltip :title="s('Students with no class for the current year')">
          <a-checkbox v-model:checked="filters.unassigned" class="tb-check" @change="crud.reload">
            {{ s('Not placed') }}
          </a-checkbox>
        </a-tooltip>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" :selectable="canDelete">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="cell-s" @click="open(record)">
            <a-avatar :size="36" :src="record.image_url" class="cell-avatar">
              {{ initials(record.name) }}
            </a-avatar>
            <span class="cell-text">
              <span class="cell-name">{{ record.name }}</span>
              <span class="cell-adm">{{ record.admission_number }}</span>
            </span>
          </button>
        </template>

        <template v-else-if="column.key === 'class'">
          <template v-if="record.class_name">
            <a-tag color="purple">{{ record.class_name }}</a-tag>
            <a-tag v-if="record.section_name">{{ record.section_name }}</a-tag>
          </template>
          <a-tag v-else color="warning">{{ s('Not placed') }}</a-tag>
        </template>

        <template v-else-if="column.key === 'age'">
          <span v-if="record.age !== null">{{ record.age }}</span>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'gender'">{{ s(labelOf(GENDERS, record.gender)) }}</template>

        <template v-else-if="column.key === 'guardian'">
          <div v-if="record.guardian_name">{{ record.guardian_name }}</div>
          <div v-if="record.guardian_phone" class="cell-sub">{{ record.guardian_phone }}</div>
          <span v-if="!record.guardian_name && !record.guardian_phone" class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(STUDENT_STATUSES, record.status).color">
            {{ s(labelOf(STUDENT_STATUSES, record.status)) }}
          </a-tag>
        </template>

        <!-- Performance: weighted average of the year + each term's average. -->
        <template v-else-if="column.key === 'performance'">
          <template v-if="record.performance !== null && record.performance !== undefined">
            <b :class="markClass(record.performance)" class="perf-avg">{{ record.performance }}</b>
            <div class="cell-sub">
              <span v-for="t in terms" :key="t.id" class="perf-term">
                {{ t.label }} <span :class="markClass(record.performance_terms?.[t.id])">{{ record.performance_terms?.[t.id] ?? '—' }}</span>
              </span>
            </div>
          </template>
          <span v-else class="muted">—</span>
        </template>

        <!-- Report card in a popup, with print / download (2026-10-21). -->
        <template v-else-if="column.key === 'report_card'">
          <a-button size="small" @click="openCard(record)">
            <template #icon><FileTextOutlined /></template>
            {{ s('View') }}
          </a-button>
        </template>

        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="s('Open record')">
              <a-button type="text" size="small" @click="open(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/school/students/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canDelete" :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- The student's report card (Libreta) with Download PDF / Print. -->
    <a-modal
      v-model:open="cardOpen" :title="cardStudent ? s('Report card — {name}', { name: cardStudent.name }) : ''"
      :footer="null" width="1000px" destroy-on-close
    >
      <ReportCard v-if="cardStudent" :student-id="cardStudent.id" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Student roll. Class and section filters run through the ENROLMENT for the
 * current academic year, not a column on the student — which is why "not
 * placed" is a meaningful filter rather than an empty cell.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import {
  UserAddOutlined, EditOutlined, DeleteOutlined, EyeOutlined, SwapOutlined, FileTextOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportCard from './ReportCard.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useAuthStore } from '../../stores/auth';
import { GENDERS, STUDENT_STATUSES, labelOf, optionOf } from './schoolOptions';
import http from '../../lib/http';
import { useSchoolText } from './lang/useSchoolText';
import ES from './lang/Students.es.js';
import { useSystemContext } from '../../lib/systemContext';

const { s } = useSchoolText(ES);

// Report card popup (column "Report card").
const cardOpen = ref(false);
const cardStudent = ref(null);
function openCard(record) {
  cardStudent.value = record;
  cardOpen.value = true;
}

const auth = useAuthStore();
const router = useRouter();

const canAdd = computed(() => auth.can('school_students_add'));
const canEdit = computed(() => auth.can('school_students_edit'));
const canDelete = computed(() => auth.can('school_students_delete'));

const filters = reactive({
  class_id: undefined, section_id: undefined, status: undefined,
  gender: undefined, unassigned: false,
});

const crud = useCrudTable('school/students', {
  rowsKey: 'students',
  // Best student first (2026-10-21).
  sortField: 'performance',
  sortType: 'desc',
  bulkDeleteEndpoint: 'school/students/delete/by_selection',
  params: () => ({
    class_id: filters.class_id || '',
    section_id: filters.section_id || '',
    status: filters.status || '',
    gender: filters.gender || '',
    unassigned: filters.unassigned ? 1 : '',
  }),
});

const meta = ref({});
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const sectionOptions = computed(() => (meta.value.sections || [])
  .filter(sec => !filters.class_id || sec.class_id === filters.class_id)
  .map(sec => ({ value: sec.id, label: sec.name })));
const statusOptions = computed(() => STUDENT_STATUSES.map(o => ({ ...o, label: s(o.label) })));
const genderOptions = computed(() => GENDERS.map(o => ({ ...o, label: s(o.label) })));

function onClassChange() {
  // A section from the previous class would filter everything away.
  filters.section_id = undefined;
  crud.reload();
}

const { isSystem } = useSystemContext();
const columns = computed(() => [
  { title: s('Student'), key: 'name', dataIndex: 'name', sorter: true },
  { title: s('Class'), key: 'class', width: 170 },
  { title: s('Roll'), dataIndex: 'roll_number', key: 'roll_number', width: 90 },
  { title: s('Age'), key: 'age', dataIndex: 'age', width: 80 },
  { title: s('Gender'), key: 'gender', dataIndex: 'gender', sorter: true, width: 110 },
  { title: s('Guardian'), key: 'guardian', width: 180 },
  { title: s('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 130 },
  { title: s('Performance'), key: 'performance', dataIndex: 'performance', sorter: true, defaultSortOrder: 'descend', width: 230 },
  { title: s('Report card'), key: 'report_card', width: 110 },
  // superadmin/AdminSystem: every company (2026-10-21) -- whose is each row.
  ...(isSystem.value ? [{ title: s('Company'), key: 'company_name', dataIndex: 'company_name', exportValue: r => r.company_name || '' }] : []),
  { title: '', key: 'actions', width: 120 },
]);

/* Performance column + highlight cards (2026-10-21). */
const terms = computed(() => crud.payload?.value?.terms || []);
const passMark = computed(() => crud.payload?.value?.pass_mark ?? hl.value?.pass_mark ?? 11);
function markClass(v) {
  if (typeof v !== 'number') return '';
  return v < passMark.value ? 'mark-bad' : 'mark-ok';
}
const hl = ref(null);
async function loadHighlights() {
  try {
    hl.value = await http.get('school/students-highlights');
  } catch (e) {
    hl.value = null;
  }
}

function open(student) {
  router.push(`/school/students/${student.id}`);
}

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2)
    .map(part => part[0].toUpperCase()).join('');
}

onMounted(async () => {
  crud.fetchRows();
  loadHighlights();
  try {
    meta.value = await http.get('school/meta');
  } catch (e) { /* filters stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.mark-bad { color: #d4380d; font-weight: 600; }
.mark-ok { color: #1d39c4; font-weight: 600; }
.perf-avg { font-size: 15px; }
.perf-term { margin-inline-end: 8px; white-space: nowrap; }
.hl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.hl-card {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 10px;
  padding: 10px 14px;
  background: var(--ant-color-bg-container, #fff);
  cursor: default;
}
.hl-card[class*='hl-'] { cursor: pointer; }
.hl-gold { background: linear-gradient(135deg, rgba(250, 219, 20, 0.16), transparent); border-color: rgba(250, 173, 20, 0.5); }
.hl-risk { border-color: rgba(212, 56, 13, 0.45); }
.hl-label { font-size: 12px; opacity: 0.7; }
.hl-value { font-size: 17px; font-weight: 700; line-height: 1.3; }
.hl-sub { font-size: 12px; opacity: 0.8; }
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
  width: 160px;
}
.tb-item-sm {
  width: 120px;
}
.tb-check {
  white-space: nowrap;
}
.cell-s {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  min-width: 190px;
}
.cell-avatar {
  flex: none;
  background: rgba(109, 40, 217, 0.15);
  color: #6d28d9;
  font-size: 13px;
}
.cell-name {
  display: block;
  font-weight: 500;
}
.cell-adm {
  display: block;
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.cell-s:hover .cell-name {
  color: #6d28d9;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-item-sm {
    width: 100%;
  }
}
</style>
