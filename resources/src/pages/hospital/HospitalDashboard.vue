<template>
  <div class="page">
    <PageHeader
      :title="s('Hospital Dashboard')"
      :subtitle="s('Census, activity and revenue at a glance.')"
      :breadcrumb="[s('Hospital'), s('Dashboard')]"
    >
      <template #actions>
        <a-button @click="$router.push('/hospital/appointments')">
          <template #icon><CalendarOutlined /></template>
          {{ s('Appointments') }}
        </a-button>
        <a-button type="primary" @click="$router.push('/hospital/patients/create')">
          <template #icon><UserAddOutlined /></template>
          {{ s('Register patient') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- Census -->
      <div class="kpis">
        <button type="button" class="kpi" @click="$router.push('/hospital/patients')">
          <span class="kpi-ic kpi-ic--brand"><TeamOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.patients_total) }}</span>
            <span class="kpi-label">{{ s('Patients') }}</span>
          </span>
          <span v-if="data.patients_new_today" class="kpi-note">{{ s('+{n} today', { n: data.patients_new_today }) }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/hospital/appointments?today=1')">
          <span class="kpi-ic kpi-ic--info"><CalendarOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.appointments_today) }}</span>
            <span class="kpi-label">{{ s('Appointments today') }}</span>
          </span>
          <span v-if="data.appointments_pending" class="kpi-note">{{ s('{n} pending', { n: data.appointments_pending }) }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/hospital/admissions?status=admitted')">
          <span class="kpi-ic kpi-ic--warn"><MedicineBoxOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.admissions_current) }}</span>
            <span class="kpi-label">{{ s('Inpatients') }}</span>
          </span>
          <span class="kpi-note">{{ s('{a} in / {b} out', { a: data.admissions_today || 0, b: data.discharges_today || 0 }) }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/hospital/wards')">
          <span class="kpi-ic kpi-ic--bed"><HomeOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ data.occupancy_rate || 0 }}%</span>
            <span class="kpi-label">{{ s('Bed occupancy') }}</span>
          </span>
          <span class="kpi-note">{{ s('{n} free', { n: data.beds_available || 0 }) }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/hospital/lab-orders?pending=1')">
          <span class="kpi-ic kpi-ic--lab"><ExperimentOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.lab_pending) }}</span>
            <span class="kpi-label">{{ s('Lab pending') }}</span>
          </span>
          <span v-if="data.lab_critical" class="kpi-note kpi-note--danger">{{ s('{n} critical', { n: data.lab_critical }) }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/hospital/invoices?outstanding=1')">
          <span class="kpi-ic kpi-ic--money"><DollarOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ money(data.revenue_today || 0) }}</span>
            <span class="kpi-label">{{ s('Collected today') }}</span>
          </span>
          <span v-if="data.outstanding" class="kpi-note kpi-note--danger">{{ s('{amount} due', { amount: money(data.outstanding) }) }}</span>
        </button>
      </div>

      <!-- Efficiency, last 30 days (2026-10-25) -->
      <div v-if="data.efficiency" class="eff">
        <div class="eff-h">{{ s('Efficiency — last 30 days') }}</div>
        <div class="eff-row">
          <div class="eff-i"><b>{{ data.efficiency.avg_wait_minutes != null ? mins(data.efficiency.avg_wait_minutes) : '—' }}</b><span>{{ s('Order to bed') }}</span></div>
          <div class="eff-i"><b>{{ data.efficiency.avg_stay_days != null ? s('{n} days', { n: data.efficiency.avg_stay_days }) : '—' }}</b><span>{{ s('Average stay') }}</span></div>
          <div class="eff-i"><b>{{ data.efficiency.bed_turnover != null ? data.efficiency.bed_turnover : '—' }}</b><span>{{ s('Bed turnover (discharges per bed)') }}</span></div>
          <div class="eff-i"><b>{{ data.efficiency.readmission_rate != null ? data.efficiency.readmission_rate + '%' : '—' }}</b><span>{{ s('Readmissions within 30 days') }}</span></div>
          <div class="eff-i" :class="{ bad: data.efficiency.cleaning_late }" @click="$router.push('/hospital/wards')"><b>{{ data.efficiency.cleaning_late }}</b><span>{{ s('Beds waiting for cleaning +30 min') }}</span></div>
        </div>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <ReportChart
            :data="data.trend || []"
            :fields="[
              { key: 'visits', label: s('Consultations') },
              { key: 'admissions', label: s('Admissions') },
            ]"
            :title="s('Activity — last 14 days')"
            type="area"
            :height="280"
          />

          <ReportChart
            :data="data.trend || []"
            :fields="[{ key: 'revenue', label: s('Collected') }]"
            :title="s('Collections — last 14 days')"
            type="bar"
            :height="240"
            :format="money"
          />

          <a-card size="small" :title="s('Ward occupancy')" style="margin-bottom: 16px">
            <div v-if="(data.ward_occupancy || []).length" class="wards">
              <div v-for="w in data.ward_occupancy" :key="w.id" class="ward">
                <div class="ward-head">
                  <span class="ward-name">{{ w.name }}</span>
                  <span class="ward-count">{{ w.occupied }}/{{ w.total }}</span>
                </div>
                <a-progress
                  :percent="w.rate" size="small"
                  :stroke-color="w.rate >= 90 ? '#ff4d4f' : w.rate >= 70 ? '#faad14' : '#6d28d9'"
                />
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="s('No beds configured yet')">
              <a-button size="small" @click="$router.push('/hospital/wards')">{{ s('Set up wards') }}</a-button>
            </a-empty>
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card size="small" :title="s('Next up today')" style="margin-bottom: 16px">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/hospital/appointments?today=1')">{{ s('All') }}</a-button>
            </template>
            <div v-if="(data.upcoming || []).length" class="queue">
              <button
                v-for="a in data.upcoming" :key="a.id" type="button" class="queue-row"
                @click="$router.push(`/hospital/patients/${a.patient_id}`)"
              >
                <span class="queue-time">{{ time(a.scheduled_at) }}</span>
                <span class="queue-body">
                  <span class="queue-name">{{ a.patient_name }}</span>
                  <span class="queue-meta">{{ a.doctor_name }}</span>
                </span>
                <a-tag :color="optionOf(APPOINTMENT_STATUSES, a.status).color">
                  {{ s(labelOf(APPOINTMENT_STATUSES, a.status)) }}
                </a-tag>
              </button>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="s('Nothing else booked today')" style="padding: 16px 0" />
          </a-card>

          <a-card size="small" :title="s('Busiest departments')" style="margin-bottom: 16px">
            <template #extra><span class="card-hint">{{ s('last 30 days') }}</span></template>
            <div v-if="(data.by_department || []).length" class="depts">
              <div v-for="d in data.by_department" :key="d.name" class="dept">
                <span class="dept-name">{{ d.name }}</span>
                <a-progress
                  :percent="deptPercent(d.visits)" :show-info="false" size="small"
                  stroke-color="#6d28d9" class="dept-bar"
                />
                <span class="dept-count">{{ d.visits }}</span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="s('No consultations yet')" />
          </a-card>

          <a-card size="small" :title="s('This month')">
            <a-descriptions :column="1" size="small">
              <a-descriptions-item :label="s('Consultations today')">{{ n(data.visits_today) }}</a-descriptions-item>
              <a-descriptions-item :label="s('Collected')">{{ money(data.revenue_month || 0) }}</a-descriptions-item>
              <a-descriptions-item :label="s('Outstanding')">
                <span :class="{ danger: data.outstanding > 0 }">{{ money(data.outstanding || 0) }}</span>
              </a-descriptions-item>
              <a-descriptions-item :label="s('Active doctors')">{{ n(data.doctors_active) }}</a-descriptions-item>
              <a-descriptions-item :label="s('Departments')">{{ n(data.departments) }}</a-descriptions-item>
              <a-descriptions-item :label="s('Beds')">
                {{ s('{a} / {b} occupied', { a: n(data.beds_occupied), b: n(data.beds_total) }) }}
              </a-descriptions-item>
            </a-descriptions>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Hospital dashboard — one GET (hospital/dashboard) feeding every panel,
 * because the census, occupancy and revenue figures have to agree with each
 * other; separate calls would let the panels drift apart mid-render.
 *
 * The KPI tiles are buttons: each navigates to the filtered list it counts, so
 * a number on screen is always one click from the rows behind it.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import {
  UserAddOutlined, TeamOutlined, CalendarOutlined, MedicineBoxOutlined,
  HomeOutlined, ExperimentOutlined, DollarOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { APPOINTMENT_STATUSES, labelOf, optionOf } from './hospitalOptions';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/HospitalDashboard.es.js';

const { s } = useHospitalText(ES);
const { money, number, dateTime } = useFormat();

const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const data = ref({});
const loading = ref(false);

const n = value => number(value || 0, 0);
const mins = m => (m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`);

/** Time only — the whole panel is already scoped to today. */
function time(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? ''
    : `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

const maxDeptVisits = computed(() =>
  Math.max(1, ...(data.value.by_department || []).map(d => Number(d.visits) || 0)));

function deptPercent(visits) {
  return Math.round(((Number(visits) || 0) / maxDeptVisits.value) * 100);
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hospital/dashboard');
  } catch (e) {
    message.error(s('Could not load the hospital dashboard'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.eff { margin: 4px 0 16px; padding: 12px 14px; border-radius: 12px; background: rgba(128, 128, 128, 0.06); }
.eff-h { font-weight: 700; margin-bottom: 8px; }
.eff-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.eff-i { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border-radius: 10px; background: rgba(255, 255, 255, 0.6); }
.eff-i b { font-size: 20px; }
.eff-i span { font-size: 12px; opacity: 0.7; }
.eff-i.bad { background: rgba(220, 38, 38, 0.1); color: #b91c1c; cursor: pointer; }
.danger {
  color: #ff4d4f;
}
.card-hint {
  font-size: 11.5px;
  opacity: 0.55;
}

/* KPI strip */
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
.kpi-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.kpi-ic--bed {
  color: #4f46e5;
  background: rgba(79, 70, 229, 0.12);
}
.kpi-ic--lab {
  color: #0d9488;
  background: rgba(13, 148, 136, 0.13);
}
.kpi-ic--money {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.12);
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

/* Wards */
.wards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
}
.ward-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 2px;
}
.ward-name {
  font-size: 13px;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ward-count {
  font-size: 12px;
  opacity: 0.6;
  white-space: nowrap;
}

/* Today's queue */
.queue {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 320px;
  overflow-y: auto;
}
.queue-row {
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
.queue-row:hover {
  background: rgba(128, 128, 128, 0.1);
}
.queue-time {
  flex: none;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  font-size: 13px;
}
.queue-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.queue-name {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.queue-meta {
  font-size: 11.5px;
  opacity: 0.6;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Departments */
.depts {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.dept {
  display: flex;
  align-items: center;
  gap: 12px;
}
.dept-name {
  width: 110px;
  flex: none;
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dept-bar {
  flex: 1;
  margin: 0;
}
.dept-count {
  width: 32px;
  flex: none;
  text-align: right;
  font-weight: 600;
  font-size: 13px;
}
</style>
