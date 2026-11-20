<template>
  <div class="page">
    <PageHeader
      :title="tr('Projects Dashboard', 'Panel de proyectos')"
      :subtitle="tr('Delivery, milestones and effort across every project.', 'Entregas, hitos y esfuerzo de todos los proyectos.')"
      :breadcrumb="[tr('Projects Management', 'Gestión de proyectos'), tr('Dashboard', 'Panel')]"
    >
      <template #actions>
        <a-button @click="$router.push('/projects')">
          <template #icon><AppstoreOutlined /></template>
          {{ tr('Projects', 'Proyectos') }}
        </a-button>
        <a-button type="primary" @click="$router.push('/projects/create')">
          <template #icon><PlusOutlined /></template>
          {{ tr('New project', 'Nuevo proyecto') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <div class="kpis">
        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--brand"><ProjectOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.projects_open) }}</span>
            <span class="kpi-label">{{ tr('Open projects', 'Proyectos abiertos') }}</span>
          </span>
          <span class="kpi-note">{{ n(data.projects_total) }} {{ tr('total', 'en total') }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--warn"><ClockCircleOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.projects_overdue) }}</span>
            <span class="kpi-label">{{ tr('Projects past due', 'Proyectos atrasados') }}</span>
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--info"><CheckSquareOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">
              {{ data.tasks_completion !== null && data.tasks_completion !== undefined
                ? data.tasks_completion + '%' : '—' }}
            </span>
            <span class="kpi-label">{{ tr('Tasks complete', 'Tareas completadas') }}</span>
          </span>
          <span class="kpi-note">{{ n(data.tasks_done) }} / {{ n(data.tasks_total) }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--danger"><ExclamationCircleOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.tasks_overdue) }}</span>
            <span class="kpi-label">{{ tr('Tasks past due', 'Tareas atrasadas') }}</span>
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--flag"><FlagOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.milestones_open) }}</span>
            <span class="kpi-label">{{ tr('Open milestones', 'Hitos abiertos') }}</span>
          </span>
          <span v-if="data.milestones_overdue" class="kpi-note kpi-note--danger">
            {{ data.milestones_overdue }} {{ tr('late', 'atrasados') }}
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--time"><FieldTimeOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ number(data.hours_month || 0, 1) }}h</span>
            <span class="kpi-label">{{ tr('Logged this month', 'Registrado este mes') }}</span>
          </span>
          <span v-if="data.billable_month" class="kpi-note">{{ money(data.billable_month) }}</span>
        </button>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <ReportChart
            :data="data.hours_trend || []"
            :fields="[{ key: 'hours', label: tr('Hours logged', 'Horas registradas') }]"
            :title="tr('Effort — last 14 days', 'Esfuerzo — últimos 14 días')"
            type="bar"
            :height="260"
          />

          <a-card size="small" :title="tr('Active projects', 'Proyectos activos')" style="margin-bottom: 16px">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/projects')">{{ tr('All', 'Todos') }}</a-button>
            </template>
            <div v-if="(data.active_projects || []).length" class="plist">
              <button
                v-for="p in data.active_projects" :key="p.id" type="button" class="prow"
                @click="$router.push(`/projects/${p.id}`)"
              >
                <span class="prow-head">
                  <span class="prow-title">{{ p.title }}</span>
                  <a-tag :color="optionOf(PROJECT_STATUSES, p.status).color">
                    {{ labelOf(PROJECT_STATUSES, p.status) }}
                  </a-tag>
                </span>
                <span class="prow-meta">
                  {{ p.client_name || tr('No client', 'Sin cliente') }} · {{ p.tasks_done }}/{{ p.tasks }} {{ tr('tasks', 'tareas') }}
                  · {{ number(p.hours, 1) }}h
                </span>
                <a-progress
                  :percent="p.progress" size="small" :stroke-color="progressColor(p.progress)"
                  class="prow-bar"
                />
              </button>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="tr('No open projects', 'No hay proyectos abiertos')" />
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card size="small" :title="tr('Upcoming milestones', 'Próximos hitos')" style="margin-bottom: 16px">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/projects')">{{ tr('All', 'Todos') }}</a-button>
            </template>
            <div v-if="(data.upcoming_milestones || []).length" class="mlist">
              <button
                v-for="m in data.upcoming_milestones" :key="m.id" type="button" class="mrow"
                @click="$router.push(`/projects/${m.project_id}?tab=milestones`)"
              >
                <span class="mrow-body">
                  <span class="mrow-title">{{ m.title }}</span>
                  <span class="mrow-meta">{{ m.project_title }} · {{ date(m.due_date) }}</span>
                </span>
                <a-tag v-if="dueLabel(m.days_to_due)" :color="dueLabel(m.days_to_due).color">
                  {{ dueLabel(m.days_to_due).text }}
                </a-tag>
              </button>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="tr('Nothing scheduled', 'Nada programado')" style="padding: 16px 0" />
          </a-card>

          <a-card size="small" :title="tr('Workload this month', 'Carga de trabajo del mes')" style="margin-bottom: 16px">
            <div v-if="(data.workload || []).length" class="wlist">
              <div v-for="w in data.workload" :key="w.id" class="wrow">
                <span class="wrow-name">{{ w.name }}</span>
                <a-progress
                  :percent="workloadPercent(w.hours)" :show-info="false" size="small"
                  stroke-color="#6d28d9" class="wrow-bar"
                />
                <span class="wrow-hours">{{ number(w.hours, 1) }}h</span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="tr('No time logged yet', 'Aún no hay horas registradas')" />
          </a-card>

          <a-card size="small" :title="tr('Task breakdown', 'Tareas por estado')">
            <div v-if="(data.tasks_by_status || []).length" class="breakdown">
              <div v-for="row in data.tasks_by_status" :key="row.status" class="brow">
                <a-tag :color="optionOf(TASK_STATUSES, row.status).color">
                  {{ labelOf(TASK_STATUSES, row.status) }}
                </a-tag>
                <span class="bcount">{{ row.count }}</span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="tr('No tasks yet', 'Aún no hay tareas')" />
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Projects Management dashboard — one GET (projects/dashboard) feeding every
 * panel, because the project, task and effort figures have to agree with each
 * other.
 *
 * The KPI tiles are buttons: each navigates to the list it counts, so a number
 * on screen is always one click from the rows behind it.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import {
  PlusOutlined, ProjectOutlined, ClockCircleOutlined, CheckSquareOutlined,
  ExclamationCircleOutlined, FlagOutlined, FieldTimeOutlined, AppstoreOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import {
  PROJECT_STATUSES, TASK_STATUSES, labelOf, optionOf, progressColor, dueLabel,
} from './workspaceOptions';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';

const tr = useTr();
const { money, number, date } = useFormat();

const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const data = ref({});
const loading = ref(false);

const n = value => number(value || 0, 0);

const maxWorkload = computed(() =>
  Math.max(1, ...(data.value.workload || []).map(w => Number(w.hours) || 0)));

function workloadPercent(hours) {
  return Math.round(((Number(hours) || 0) / maxWorkload.value) * 100);
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('projects/dashboard');
  } catch (e) {
    message.error(tr('Could not load the projects dashboard', 'No se pudo cargar el panel de proyectos'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
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
.kpi-ic--danger {
  color: #ff4d4f;
  background: rgba(255, 77, 79, 0.12);
}
.kpi-ic--flag {
  color: #4f46e5;
  background: rgba(79, 70, 229, 0.12);
}
.kpi-ic--time {
  color: #0d9488;
  background: rgba(13, 148, 136, 0.13);
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
.plist {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.prow {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  padding: 10px 8px;
  border: 0;
  border-radius: 10px;
  background: none;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  transition: background 0.15s ease;
}
.prow:hover {
  background: rgba(128, 128, 128, 0.09);
}
.prow-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.prow-title {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prow-meta {
  font-size: 11.5px;
  opacity: 0.6;
}
.prow-bar {
  margin: 2px 0 0;
}
.mlist {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 300px;
  overflow-y: auto;
}
.mrow {
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
.mrow:hover {
  background: rgba(128, 128, 128, 0.1);
}
.mrow-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.mrow-title {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mrow-meta {
  font-size: 11.5px;
  opacity: 0.6;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wlist {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.wrow {
  display: flex;
  align-items: center;
  gap: 12px;
}
.wrow-name {
  width: 110px;
  flex: none;
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wrow-bar {
  flex: 1;
  margin: 0;
}
.wrow-hours {
  width: 52px;
  flex: none;
  text-align: right;
  font-weight: 600;
  font-size: 12.5px;
}
.breakdown {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.brow {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.bcount {
  font-weight: 600;
}
</style>
