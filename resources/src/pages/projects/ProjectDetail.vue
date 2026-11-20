<template>
  <div class="page">
    <PageHeader :title="p.title || tr('Project', 'Proyecto')" :breadcrumb="[$t('Projects'), p.title || '']">
      <template #actions>
        <a-button @click="$router.push('/projects')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ tr('All projects', 'Todos los proyectos') }}
        </a-button>
        <a-button v-if="p.id" @click="$router.push(`/projects/${p.id}/edit?back=detail`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- The project's identity line, always visible above the tabs (like a project header in Asana). -->
      <div v-if="p.id" class="head">
        <a-tag :color="projectStatusColor(p.status)">{{ projectStatusLabel(p.status) }}</a-tag>
        <span v-if="p.client_name" class="head-item"><UserOutlined /> {{ p.client_name }}</span>
        <span class="head-item">
          <CalendarOutlined /> {{ p.start_date ? date(p.start_date) : '—' }} → {{ p.end_date ? date(p.end_date) : '—' }}
          <a-tag v-if="p.is_late" color="error" class="tight">{{ tr('Late', 'Atrasado') }}</a-tag>
        </span>
        <span v-if="isSystem && p.company_name" class="head-item"><BankOutlined /> {{ p.company_name }}</span>
        <span class="head-progress">
          <a-progress :percent="stats.progress || 0" size="small" :stroke-color="progressColor(stats.progress || 0)" />
        </span>
      </div>

      <a-tabs v-model:activeKey="tab" class="tabs" @change="onTab">
        <!-- Overview -->
        <a-tab-pane key="overview" :tab="tr('Overview', 'Resumen')">
          <a-row :gutter="[16, 16]">
            <a-col v-for="k in kpis" :key="k.label" :xs="12" :md="8" :xl="4">
              <a-card size="small" class="kpi">
                <div class="kpi-label">{{ k.label }}</div>
                <div class="kpi-value" :class="k.cls">{{ k.value }}</div>
                <div v-if="k.note" class="kpi-note">{{ k.note }}</div>
              </a-card>
            </a-col>
          </a-row>

          <a-row :gutter="[16, 16]" style="margin-top: 16px">
            <a-col :xs="24" :lg="14">
              <a-card size="small" :title="tr('Coming up', 'Próximos vencimientos')">
                <a-empty v-if="!upcoming.length" :description="tr('Nothing due', 'Nada por vencer')" />
                <div v-for="it in upcoming" :key="it.key" class="up" @click="openItem(it)">
                  <span class="up-icon" :class="it.kind"><FlagOutlined v-if="it.kind === 'milestone'" /><CheckSquareOutlined v-else /></span>
                  <span class="up-title">{{ it.title }}</span>
                  <span class="up-date" :class="{ late: it.late }">{{ date(it.date) }}</span>
                </div>
              </a-card>
            </a-col>
            <a-col :xs="24" :lg="10">
              <a-card size="small" :title="tr('About this project', 'Acerca del proyecto')" style="margin-bottom: 16px">
                <div v-if="p.description" class="desc">{{ p.description }}</div>
                <div v-else class="muted">{{ tr('No description yet.', 'Sin descripción todavía.') }}</div>
              </a-card>
              <a-card size="small" :title="tr('Team', 'Equipo')">
                <div v-if="!team.length" class="muted">{{ tr('Nobody assigned yet.', 'Nadie asignado todavía.') }}</div>
                <a-space wrap>
                  <a-tag v-for="m in team" :key="m.id" class="member">
                    <a-avatar size="small" class="av">{{ (m.name || '?').charAt(0).toUpperCase() }}</a-avatar> {{ m.name }}
                  </a-tag>
                </a-space>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- List -->
        <a-tab-pane v-if="can('tasks')" key="list" :tab="tr('List', 'Lista')">
          <DataTable v-if="tab === 'list'" :crud="taskCrud" :columns="taskColumns">
            <template #filters>
              <a-button type="primary" @click="newTask">
                <template #icon><PlusOutlined /></template>
                {{ tr('New task', 'Nueva tarea') }}
              </a-button>
            </template>
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'title'">
                <a class="link" @click="editTask(record.id)">{{ record.title }}</a>
              </template>
              <template v-else-if="column.key === 'start_date'">{{ record.start_date ? date(record.start_date) : '—' }}</template>
              <template v-else-if="column.key === 'end_date'">{{ record.end_date ? date(record.end_date) : '—' }}</template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="projectStatusColor(record.status)">{{ projectStatusLabel(record.status) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space :size="0">
                  <a-tooltip :title="$t('Edit')">
                    <a-button type="text" size="small" @click="editTask(record.id)"><template #icon><EditOutlined /></template></a-button>
                  </a-tooltip>
                  <a-tooltip :title="$t('Delete')">
                    <a-button type="text" size="small" danger @click="removeTask(record)"><template #icon><DeleteOutlined /></template></a-button>
                  </a-tooltip>
                </a-space>
              </template>
            </template>
          </DataTable>
        </a-tab-pane>

        <!-- Board -->
        <a-tab-pane v-if="can('tasks')" key="board" :tab="tr('Board', 'Tablero')">
          <TaskBoard v-if="tab === 'board'" :project-id="id" />
        </a-tab-pane>

        <!-- Calendar -->
        <a-tab-pane key="calendar" :tab="tr('Calendar', 'Calendario')">
          <a-row v-if="tab === 'calendar'" :gutter="16">
            <a-col :xs="24" :xl="17">
              <a-card size="small">
                <a-calendar v-model:value="calDay">
                  <template #dateCellRender="{ current: day }">
                    <ul class="cell-items">
                      <li
                        v-for="it in itemsOn(day).slice(0, 3)" :key="it.key" :class="[it.kind, { late: it.late, done: it.done }]"
                        :title="it.title" @click.stop="openItem(it)"
                      >
                        {{ it.title }}
                      </li>
                      <li v-if="itemsOn(day).length > 3" class="more">+{{ itemsOn(day).length - 3 }}</li>
                    </ul>
                  </template>
                </a-calendar>
              </a-card>
            </a-col>
            <a-col :xs="24" :xl="7">
              <a-card size="small" :title="date(calDay.format('YYYY-MM-DD'))">
                <a-empty v-if="!itemsOn(calDay).length" :description="tr('Nothing on this day', 'Nada este día')" />
                <div v-for="it in itemsOn(calDay)" :key="'d' + it.key" class="up" @click="openItem(it)">
                  <span class="up-icon" :class="it.kind"><FlagOutlined v-if="it.kind === 'milestone'" /><CheckSquareOutlined v-else-if="it.kind === 'task'" /><CalendarOutlined v-else /></span>
                  <span class="up-title">{{ it.title }}</span>
                  <span class="up-date">{{ it.what }}</span>
                </div>
              </a-card>
              <div class="legend">
                <span><i class="sw task" /> {{ tr('Task due', 'Vence tarea') }}</span>
                <span><i class="sw milestone" /> {{ tr('Milestone', 'Hito') }}</span>
                <span><i class="sw project" /> {{ tr('Project start / end', 'Inicio / fin del proyecto') }}</span>
              </div>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- Milestones -->
        <a-tab-pane v-if="can('project_milestones')" key="milestones" :tab="tr('Milestones', 'Hitos')">
          <Milestones v-if="tab === 'milestones'" :project-id="id" @changed="load" />
        </a-tab-pane>

        <!-- Time -->
        <a-tab-pane v-if="can('project_timesheets')" key="time" :tab="tr('Timesheets', 'Horas')">
          <Timesheets v-if="tab === 'time'" :project-id="id" @changed="load" />
        </a-tab-pane>

        <!-- Report -->
        <a-tab-pane v-if="can('project_reports')" key="report" :tab="tr('Report', 'Informe')">
          <ProjectReports v-if="tab === 'report'" :project-id="id" />
        </a-tab-pane>
      </a-tabs>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * One project as its own workspace (user, 2026-11-19: "like Asana, independent projects"): tasks, board, calendar,
 * milestones, hours and the report are tabs of the project, not cross-project lists. The tab lives in ?tab= so Back /
 * a task form returns to it. GET projects/{id} -> {project, team, stats, tasks, milestones}.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Modal, message } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  ArrowLeftOutlined, EditOutlined, DeleteOutlined, PlusOutlined, UserOutlined, CalendarOutlined, BankOutlined,
  FlagOutlined, CheckSquareOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import TaskBoard from './TaskBoard.vue';
import Milestones from './Milestones.vue';
import Timesheets from './Timesheets.vue';
import ProjectReports from './ProjectReports.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useSystemContext } from '../../lib/systemContext';
import { projectStatusLabel, projectStatusColor } from './projectStatus';
import { progressColor } from './workspaceOptions';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useAuthStore } from '../../stores/auth';

const TABS = ['overview', 'list', 'board', 'calendar', 'milestones', 'time', 'report'];

const tr = useTr();
const auth = useAuthStore();
const can = perm => auth.can(perm);
const route = useRoute();
const router = useRouter();
const { date, number, money } = useFormat();
const { isSystem } = useSystemContext();

const id = computed(() => Number(route.params.id));
const tab = ref(TABS.includes(route.query.tab) ? route.query.tab : 'overview');
const loading = ref(false);
const data = ref({});

const p = computed(() => data.value.project || {});
const stats = computed(() => data.value.stats || {});
const team = computed(() => data.value.team || []);

function onTab(key) {
  router.replace({ query: { ...route.query, tab: key } });
  if (key === 'overview' || key === 'calendar') load();
}

async function load() {
  loading.value = !data.value.project;
  try {
    data.value = await http.get(`projects/${id.value}`);
  } catch (e) {
    message.error(tr('This project is not available.', 'Este proyecto no está disponible.'));
    router.push('/projects');
  } finally {
    loading.value = false;
  }
}

const kpis = computed(() => {
  const s = stats.value;
  return [
    { label: tr('Progress', 'Avance'), value: `${s.progress || 0}%`, note: tr(`${s.tasks_done || 0} of ${s.tasks || 0} tasks`, `${s.tasks_done || 0} de ${s.tasks || 0} tareas`) },
    { label: tr('Open tasks', 'Tareas abiertas'), value: Math.max(0, (s.tasks || 0) - (s.tasks_done || 0)) },
    { label: tr('Overdue tasks', 'Tareas atrasadas'), value: s.tasks_overdue || 0, cls: s.tasks_overdue ? 'bad' : '' },
    { label: tr('Milestones', 'Hitos'), value: `${s.milestones_done || 0} / ${s.milestones || 0}`, note: tr('completed', 'completados') },
    { label: tr('Hours', 'Horas'), value: number(s.logged_hours || 0, 2), note: s.estimated_hours ? tr(`of ${number(s.estimated_hours, 1)} estimated`, `de ${number(s.estimated_hours, 1)} estimadas`) : '' },
    { label: tr('Billable value', 'Valor facturable'), value: money(s.billable_amount || 0), cls: 'ok' },
  ];
});

// Every dated thing of the project, for the calendar and "Coming up".
const items = computed(() => {
  const out = [];
  const today = dayjs().format('YYYY-MM-DD');
  (data.value.tasks || []).forEach(t => {
    if (!t.end_date) return;
    const done = ['completed', 'cancelled'].includes(t.status);
    out.push({ key: `t${t.id}`, kind: 'task', id: t.id, title: t.title, date: String(t.end_date).slice(0, 10), late: t.is_overdue, done, what: projectStatusLabel(t.status) });
  });
  (data.value.milestones || []).forEach(m => {
    if (!m.due_date) return;
    const done = m.status === 'completed';
    out.push({ key: `m${m.id}`, kind: 'milestone', id: m.id, title: m.title, date: m.due_date, late: !done && m.due_date < today, done, what: tr('Milestone', 'Hito') });
  });
  if (p.value.start_date) out.push({ key: 'ps', kind: 'project', title: tr('Project starts', 'Inicio del proyecto'), date: String(p.value.start_date).slice(0, 10), what: '' });
  if (p.value.end_date) out.push({ key: 'pe', kind: 'project', title: tr('Project ends', 'Fin del proyecto'), date: String(p.value.end_date).slice(0, 10), what: '' });
  return out;
});

const byDay = computed(() => {
  const map = {};
  items.value.forEach(it => { (map[it.date] = map[it.date] || []).push(it); });
  return map;
});
const itemsOn = day => byDay.value[day.format('YYYY-MM-DD')] || [];

const upcoming = computed(() => items.value
  .filter(it => it.kind !== 'project' && !it.done)
  .sort((a, b) => a.date.localeCompare(b.date))
  .slice(0, 8));

const calDay = ref(dayjs());

function openItem(it) {
  if (it.kind === 'task') editTask(it.id);
  else if (it.kind === 'milestone') { tab.value = 'milestones'; onTab('milestones'); }
}

// List tab: the standard table on the tasks endpoint, narrowed to this project.
const taskCrud = useCrudTable('tasks', { rowsKey: 'tasks', params: () => ({ project_id: id.value }) });
const taskColumns = computed(() => [
  { title: tr('Task', 'Tarea'), key: 'title', dataIndex: 'title', sorter: true },
  { title: tr('Start', 'Inicio'), key: 'start_date', dataIndex: 'start_date', sorter: true, width: 140 },
  { title: tr('Due', 'Vence'), key: 'end_date', dataIndex: 'end_date', sorter: true, width: 140 },
  { title: tr('Status', 'Estado'), key: 'status', dataIndex: 'status', width: 140 },
  { title: '', key: 'actions', width: 90 },
]);
watch(tab, v => { if (v === 'list') taskCrud.fetchRows(); });

function newTask() { router.push(`/tasks/create?project_id=${id.value}`); }
function editTask(taskId) { router.push(`/tasks/${taskId}/edit?project_id=${id.value}`); }
function removeTask(record) {
  Modal.confirm({
    title: tr('Delete this task?', '¿Eliminar esta tarea?'),
    content: record.title,
    okType: 'danger',
    okText: tr('Delete', 'Eliminar'),
    cancelText: tr('Cancel', 'Cancelar'),
    async onOk() {
      await http.delete(`tasks/${record.id}`);
      taskCrud.fetchRows();
      load();
    },
  });
}

onMounted(() => {
  load();
  if (tab.value === 'list') taskCrud.fetchRows();
});
</script>

<style scoped>
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 18px;
  margin: -4px 0 6px;
}
.head-item {
  opacity: 0.8;
  white-space: nowrap;
}
.head-progress {
  flex: 1 1 200px;
  max-width: 320px;
  margin-inline-start: auto;
}
.tight {
  margin-inline-start: 6px;
}
.tabs :deep(.ant-tabs-nav) {
  margin-bottom: 14px;
}
.kpi-label {
  font-size: 12px;
  opacity: 0.6;
}
.kpi-value {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.3;
}
.kpi-value.bad {
  color: #dc2626;
}
.kpi-value.ok {
  color: #16a34a;
}
.kpi-note {
  font-size: 11.5px;
  opacity: 0.55;
}
.up {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
  cursor: pointer;
}
.up:last-child {
  border-bottom: 0;
}
.up:hover {
  background: rgba(128, 128, 128, 0.06);
}
.up-icon.task {
  color: #2563eb;
}
.up-icon.milestone {
  color: #9333ea;
}
.up-icon.project {
  color: #64748b;
}
.up-title {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.up-date {
  font-size: 12px;
  opacity: 0.65;
  white-space: nowrap;
}
.up-date.late {
  color: #dc2626;
  opacity: 1;
}
.desc {
  white-space: pre-wrap;
}
.muted {
  opacity: 0.55;
}
.member {
  padding: 2px 8px 2px 2px;
  border-radius: 14px;
}
.av {
  background: #6366f1;
  margin-inline-end: 4px;
}
.link {
  cursor: pointer;
}
.cell-items {
  list-style: none;
  margin: 0;
  padding: 0;
}
.cell-items li {
  font-size: 11.5px;
  line-height: 1.5;
  padding: 0 4px;
  margin-bottom: 2px;
  border-left: 3px solid #2563eb;
  border-radius: 2px;
  background: rgba(37, 99, 235, 0.08);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: pointer;
}
.cell-items li.milestone {
  border-left-color: #9333ea;
  background: rgba(147, 51, 234, 0.08);
}
.cell-items li.project {
  border-left-color: #64748b;
  background: rgba(100, 116, 139, 0.1);
}
.cell-items li.late {
  border-left-color: #dc2626;
  color: #dc2626;
}
.cell-items li.done {
  opacity: 0.5;
  text-decoration: line-through;
}
.cell-items li.more {
  border: 0;
  background: none;
  opacity: 0.6;
}
.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 14px;
  margin-top: 10px;
  font-size: 12px;
  opacity: 0.75;
}
.sw {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 2px;
  margin-inline-end: 4px;
  vertical-align: -1px;
}
.sw.task {
  background: #2563eb;
}
.sw.milestone {
  background: #9333ea;
}
.sw.project {
  background: #64748b;
}
</style>
