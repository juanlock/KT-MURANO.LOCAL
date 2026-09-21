<template>
  <div class="page">
    <PageHeader
      title="School Dashboard"
      :subtitle="data.academic_year ? `Academic year ${data.academic_year.name}` : 'No academic year set yet'"
      :breadcrumb="['School', 'Dashboard']"
    >
      <template #actions>
        <a-button @click="$router.push('/school/attendance')">
          <template #icon><CheckSquareOutlined /></template>
          Take register
        </a-button>
        <a-button type="primary" @click="$router.push('/school/students/create')">
          <template #icon><UserAddOutlined /></template>
          Admit student
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      v-if="!loading && !data.academic_year" type="warning" show-icon class="setup"
      message="No academic year is marked current."
      description="Attendance, enrolment, exams and fees all hang off the academic year. Create one before anything else."
    >
      <template #action>
        <a-button size="small" @click="$router.push('/school/academics')">Set one up</a-button>
      </template>
    </a-alert>

    <a-spin :spinning="loading">
      <div class="kpis">
        <button type="button" class="kpi" @click="$router.push('/school/students')">
          <span class="kpi-ic kpi-ic--brand"><TeamOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.students_enrolled) }}</span>
            <span class="kpi-label">Students enrolled</span>
          </span>
          <span class="kpi-note">{{ n(data.students_male) }}M / {{ n(data.students_female) }}F</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/school/teachers')">
          <span class="kpi-ic kpi-ic--info"><SolutionOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.teachers_total) }}</span>
            <span class="kpi-label">Teachers</span>
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/school/academics')">
          <span class="kpi-ic kpi-ic--class"><ApartmentOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.classes_total) }}</span>
            <span class="kpi-label">Classes</span>
          </span>
          <span class="kpi-note">{{ n(data.sections_total) }} sections</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/school/attendance')">
          <span class="kpi-ic kpi-ic--ok"><CheckSquareOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">
              {{ data.attendance_rate_today !== null && data.attendance_rate_today !== undefined
                ? data.attendance_rate_today + '%' : '—' }}
            </span>
            <span class="kpi-label">Attendance today</span>
          </span>
          <span v-if="data.absent_today" class="kpi-note kpi-note--danger">{{ data.absent_today }} absent</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/school/fees?outstanding=1')">
          <span class="kpi-ic kpi-ic--money"><DollarOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ money(data.fees_collected || 0) }}</span>
            <span class="kpi-label">Fees collected</span>
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/school/fees?overdue=1')">
          <span class="kpi-ic kpi-ic--warn"><ExclamationCircleOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ money(data.fees_outstanding || 0) }}</span>
            <span class="kpi-label">Fees outstanding</span>
          </span>
          <span v-if="data.fees_overdue_count" class="kpi-note kpi-note--danger">
            {{ data.fees_overdue_count }} overdue
          </span>
        </button>
      </div>

      <a-alert
        v-if="!loading && data.academic_year && !data.attendance_marked_today"
        type="info" show-icon class="setup"
        message="Today's register has not been taken yet."
      >
        <template #action>
          <a-button size="small" @click="$router.push('/school/attendance')">Take it now</a-button>
        </template>
      </a-alert>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <ReportChart
            :data="data.attendance_trend || []"
            :fields="[{ key: 'rate', label: 'Attendance %' }]"
            title="Attendance — last 14 days"
            type="area"
            :height="270"
          />

          <a-card size="small" title="Students by class" style="margin-bottom: 16px">
            <div v-if="(data.by_class || []).length" class="bars">
              <div v-for="c in data.by_class" :key="c.id" class="bar-row">
                <span class="bar-label">{{ c.name }}</span>
                <a-progress
                  :percent="classPercent(c.students)" :show-info="false" size="small"
                  stroke-color="#6d28d9" class="bar"
                />
                <span class="bar-count">{{ c.students }}</span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" description="No students enrolled yet" />
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card size="small" title="Today's periods" style="margin-bottom: 16px">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/school/timetable')">Timetable</a-button>
            </template>
            <div v-if="(data.today_periods || []).length" class="periods">
              <div v-for="p in data.today_periods" :key="p.id" class="period">
                <span class="period-time">{{ p.start_time }}</span>
                <span class="period-body">
                  <span class="period-subject">{{ p.subject_name }}</span>
                  <span class="period-meta">
                    {{ p.class_name }}{{ p.section_name ? ' — ' + p.section_name : '' }}
                    {{ p.teacher_name ? ' · ' + p.teacher_name : '' }}
                  </span>
                </span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" description="Nothing scheduled today" style="padding: 16px 0" />
          </a-card>

          <a-card size="small" title="Upcoming exams" style="margin-bottom: 16px">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/school/exams')">All</a-button>
            </template>
            <div v-if="(data.upcoming_exams || []).length" class="exams">
              <button
                v-for="e in data.upcoming_exams" :key="e.id" type="button" class="exam"
                @click="$router.push('/school/exams')"
              >
                <span class="exam-body">
                  <span class="exam-name">{{ e.name }}</span>
                  <span class="exam-meta">{{ labelOf(EXAM_TERMS, e.term) }} · {{ date(e.start_date) }}</span>
                </span>
                <a-tag :color="e.days_away < 0 ? 'warning' : 'processing'">
                  {{ e.days_away < 0 ? 'Under way' : e.days_away === 0 ? 'Today' : `in ${e.days_away}d` }}
                </a-tag>
              </button>
            </div>
            <a-empty v-else :image="simpleEmptyImage" description="Nothing scheduled" style="padding: 16px 0" />
          </a-card>

          <a-card size="small" title="Fees this year">
            <a-descriptions :column="1" size="small">
              <a-descriptions-item label="Billed">{{ money(data.fees_billed || 0) }}</a-descriptions-item>
              <a-descriptions-item label="Collected">{{ money(data.fees_collected || 0) }}</a-descriptions-item>
              <a-descriptions-item label="Outstanding">
                <span :class="{ danger: data.fees_outstanding > 0 }">{{ money(data.fees_outstanding || 0) }}</span>
              </a-descriptions-item>
              <a-descriptions-item label="Collected this month">{{ money(data.collected_month || 0) }}</a-descriptions-item>
            </a-descriptions>
            <a-progress
              v-if="data.fees_billed"
              :percent="collectionRate" :stroke-color="collectionRate >= 80 ? '#16a34a' : '#faad14'"
              style="margin-top: 6px"
            />
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * School dashboard — one GET (school/dashboard) feeding every panel, because
 * the roll, attendance and fee figures have to agree with each other.
 *
 * Two nudges are deliberate: no current academic year blocks everything
 * downstream, and an unmarked register is the single most common thing a school
 * forgets before lunch. Both say what to do, with the button to do it.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import {
  UserAddOutlined, TeamOutlined, SolutionOutlined, ApartmentOutlined,
  CheckSquareOutlined, DollarOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { EXAM_TERMS, labelOf } from './schoolOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { money, number, date } = useFormat();

const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const data = ref({});
const loading = ref(false);

const n = value => number(value || 0, 0);

const maxClass = computed(() =>
  Math.max(1, ...(data.value.by_class || []).map(c => Number(c.students) || 0)));

function classPercent(students) {
  return Math.round(((Number(students) || 0) / maxClass.value) * 100);
}

const collectionRate = computed(() => {
  const billed = Number(data.value.fees_billed) || 0;
  if (!billed) return 0;
  return Math.round(((Number(data.value.fees_collected) || 0) / billed) * 100);
});

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('school/dashboard');
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the school dashboard'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.danger {
  color: #ff4d4f;
}
.setup {
  margin-bottom: 16px;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
  transition: border-color 0.15s ease, transform 0.12s ease;
}
.kpi:hover {
  border-color: rgba(109, 40, 217, 0.5);
  transform: translateY(-1px);
}
.kpi-ic {
  width: 40px;
  height: 40px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 18px;
}
.kpi-ic--brand {
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.12);
}
.kpi-ic--info {
  color: #0891b2;
  background: rgba(8, 145, 178, 0.12);
}
.kpi-ic--class {
  color: #4f46e5;
  background: rgba(79, 70, 229, 0.12);
}
.kpi-ic--ok {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.12);
}
.kpi-ic--money {
  color: #0d9488;
  background: rgba(13, 148, 136, 0.13);
}
.kpi-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.kpi-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.kpi-value {
  font-size: 18px;
  font-weight: 600;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.kpi-label {
  font-size: 12px;
  opacity: 0.6;
}
.kpi-note {
  margin-inline-start: auto;
  font-size: 11px;
  opacity: 0.6;
  white-space: nowrap;
}
.kpi-note--danger {
  color: #ff4d4f;
  opacity: 1;
}
.bars {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.bar-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.bar-label {
  width: 110px;
  flex: none;
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.bar {
  flex: 1;
  margin: 0;
}
.bar-count {
  width: 36px;
  flex: none;
  text-align: right;
  font-weight: 600;
  font-size: 13px;
}
.periods {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 300px;
  overflow-y: auto;
}
.period {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}
.period-time {
  flex: none;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 12.5px;
  padding-top: 1px;
}
.period-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.period-subject {
  font-weight: 500;
  font-size: 13px;
}
.period-meta {
  font-size: 11.5px;
  opacity: 0.6;
}
.exams {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.exam {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 6px;
  border: 0;
  border-radius: 9px;
  background: none;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  transition: background 0.15s ease;
}
.exam:hover {
  background: rgba(128, 128, 128, 0.1);
}
.exam-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.exam-name {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.exam-meta {
  font-size: 11.5px;
  opacity: 0.6;
}
</style>
