<template>
  <div class="page">
    <a-spin :spinning="loading">
      <!-- The project's identity line, always visible above the tabs (like a project header in Asana). -->
      <div v-if="p.id" class="head">
        <a-tag :color="projectStatusColor(p.status)">{{ projectStatusLabel(p.status) }}</a-tag>
        <span v-if="p.client_name" class="head-item"><UserOutlined /> {{ p.client_name }}</span>
        <span class="head-item">
          <CalendarOutlined /> {{ p.start_date ? date(p.start_date) : '—' }} → {{ p.end_date ? date(p.end_date) : tr('no end date', 'sin fecha final') }}
          <a-tag v-if="p.is_late" color="error" class="tight">{{ tr('Late', 'Atrasado') }}</a-tag>
        </span>
        <span v-if="isSystem && p.company_name" class="head-item"><BankOutlined /> {{ p.company_name }}</span>
        <span class="head-progress">
          <a-progress :percent="stats.progress || 0" size="small" :stroke-color="progressColor(stats.progress || 0)" />
        </span>
        <span class="sec-btns">
          <a-tooltip v-for="s in secondary" :key="s.key" :title="hints[s.key]">
            <a-button size="small" :type="tab === s.key ? 'primary' : 'text'" @click="tab = s.key; onTab(s.key)">
              <template #icon><component :is="s.icon" /></template>{{ s.label }}
            </a-button>
          </a-tooltip>
        </span>
        <a-tooltip :title="tr('All projects', 'Todos los proyectos')">
          <a-button size="small" @click="$router.push('/projects')"><template #icon><ArrowLeftOutlined /></template></a-button>
        </a-tooltip>
        <a-tooltip :title="tr('Edit the project', 'Editar el proyecto')">
          <a-button size="small" @click="$router.push(`/projects/${p.id}/edit?back=detail`)"><template #icon><EditOutlined /></template></a-button>
        </a-tooltip>
      </div>

      <a-tabs v-model:activeKey="tab" class="tabs tabs-strong" @change="onTab">
        <!-- Overview -->
        <a-tab-pane key="overview"><template #tab><a-tooltip :title="hints['overview']" placement="bottom" :mouse-enter-delay="0.5"><span><AppstoreOutlined />{{ tr('Overview', 'Resumen') }}</span></a-tooltip></template>
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
              <!-- The project's description, edited here (2026-11-21: moved from the objectives tab). -->
              <a-card size="small" :title="tr('Description', 'Descripción')" style="margin-bottom: 16px">
                <template #extra>
                  <a v-if="!descEditing" @click="descDraft = p.description || ''; descEditing = true"><EditOutlined /> {{ $t('Edit') }}</a>
                  <a-space v-else :size="4">
                    <a-button size="small" @click="descEditing = false">{{ $t('Cancel') }}</a-button>
                    <a-button type="primary" size="small" :loading="descSaving" @click="saveDesc">{{ tr('Save', 'Guardar') }}</a-button>
                  </a-space>
                </template>
                <a-textarea v-if="descEditing" v-model:value="descDraft" :auto-size="{ minRows: 6, maxRows: 20 }" />
                <div v-else-if="p.description" class="desc">{{ p.description }}</div>
                <div v-else class="muted">{{ tr('No description yet.', 'Sin descripción todavía.') }}</div>
              </a-card>
              <a-card size="small" :title="tr('Team', 'Equipo')">
                <template #extra><a @click="tab = 'members'; onTab('members')">{{ tr('Manage', 'Gestionar') }}</a></template>
                <div v-if="!team.length" class="muted">{{ tr('Nobody assigned yet.', 'Nadie asignado todavía.') }}</div>
                <a-space wrap>
                  <a-tooltip v-for="m in team" :key="m.id" :title="[m.role, m.company_name].filter(Boolean).join(' · ')">
                    <a-tag class="member" :color="m.is_manager ? 'gold' : undefined">
                      <a-avatar size="small" class="av" :class="m.side">{{ (m.name || '?').charAt(0).toUpperCase() }}</a-avatar> {{ m.name }}
                      <CrownOutlined v-if="m.is_manager" />
                    </a-tag>
                  </a-tooltip>
                </a-space>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- Objectives (2026-11-21) -->
        <a-tab-pane key="goals"><template #tab><a-tooltip :title="hints['goals']" placement="bottom" :mouse-enter-delay="0.5"><span><AimOutlined />{{ tr('Objectives', 'Objetivos') }}</span></a-tooltip></template>
          <ProjectObjectives v-if="tab === 'goals'" :project-id="id" :data="data" @changed="load" @open-task="editTask" />
        </a-tab-pane>

        <!-- Stages (the old "Hitos", 2026-11-21): each stage with its tasks + templates -->
        <a-tab-pane v-if="can('project_milestones')" key="milestones"><template #tab><a-tooltip :title="hints['milestones']" placement="bottom" :mouse-enter-delay="0.5"><span><FlagOutlined />{{ tr('Project stages', 'Etapas del proyecto') }}</span></a-tooltip></template>
          <ProjectStages v-if="tab === 'milestones'" :project-id="id" :data="data" @changed="load" @open-task="editTask" @new-task="newTask" />
        </a-tab-pane>

        <!-- Board -->
        <a-tab-pane v-if="can('tasks')" key="board"><template #tab><a-tooltip :title="hints['board']" placement="bottom" :mouse-enter-delay="0.5"><span><ProjectOutlined />{{ tr('Status', 'Status') }}</span></a-tooltip></template>
          <TaskBoard v-if="tab === 'board'" :key="boardKey" :project-id="id" @open-task="editTask" @new-task="newTask()" />
        </a-tab-pane>

        <!-- Timeline (Smartsheet's project timeline templates, 2026-11-20) -->
        <a-tab-pane key="timeline"><template #tab><a-tooltip :title="hints['timeline']" placement="bottom" :mouse-enter-delay="0.5"><span><FieldTimeOutlined />{{ tr('Timeline', 'Línea de tiempo') }}</span></a-tooltip></template>
          <ProjectTimeline
            v-if="tab === 'timeline'" :project="p" :tasks="data.tasks || []" :milestones="data.milestones || []"
            @open-task="editTask"
          />
        </a-tab-pane>

        <!-- Calendar -->
        <a-tab-pane key="calendar"><template #tab><a-tooltip :title="hints['calendar']" placement="bottom" :mouse-enter-delay="0.5"><span><CalendarOutlined />{{ tr('Calendar', 'Calendario') }}</span></a-tooltip></template>
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
                <span><i class="sw milestone" /> {{ tr('Stage ends', 'Fin de etapa') }}</span>
                <span><i class="sw project" /> {{ tr('Project start / end', 'Inicio / fin del proyecto') }}</span>
              </div>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- Report -->
        <a-tab-pane v-if="can('project_reports')" key="report"><template #tab><a-tooltip :title="hints['report']" placement="bottom" :mouse-enter-delay="0.5"><span><BarChartOutlined />{{ tr('Report', 'Informe') }}</span></a-tooltip></template>
          <ProjectReports v-if="tab === 'report'" :project-id="id" />
        </a-tab-pane>

      </a-tabs>

      <!-- Secondary views (user, 2026-11-21: at hand but not among the main tabs): opened from the buttons on the project line. -->
      <div v-if="isSecondary" class="secondary">
        <div class="sec-head">
          <component :is="secondaryNow.icon" /> <b>{{ secondaryNow.label }}</b>
          <a class="sec-close" @click="tab = lastMain; onTab(lastMain)"><CloseOutlined /> {{ tr('Close', 'Cerrar') }}</a>
        </div>
        <template v-if="tab === 'list'">
              <DataTable :crud="taskCrud" :columns="taskColumns">
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
        </template>
        <ProjectMembers v-else-if="tab === 'members'" :project-id="id" @changed="load" />
        <ProjectDocuments v-else-if="tab === 'documents'" :project-id="id" :tasks="data.tasks || []" />
        <Timesheets v-else-if="tab === 'time'" :project-id="id" @changed="load" />
        <ProjectConfig v-else-if="tab === 'config'" :project-id="id" :title="p.title || ''" />
      </div>
    </a-spin>

    <TaskDrawer
      :open="drawer.open" :project-id="id" :task-id="drawer.taskId" :milestone-id="drawer.milestoneId" :objective-id="drawer.objectiveId"
      @update:task-id="v => (drawer.taskId = v)" @saved="onTaskSaved" @close="onTaskClosed"
    />
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
  FlagOutlined, CheckSquareOutlined, AppstoreOutlined, UnorderedListOutlined, ProjectOutlined, FieldTimeOutlined,
  ClockCircleOutlined, BarChartOutlined, TeamOutlined, PaperClipOutlined, CrownOutlined, AimOutlined, CloseOutlined, SettingOutlined,
} from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import TaskBoard from './TaskBoard.vue';
import ProjectStages from './ProjectStages.vue';
import ProjectConfig from './ProjectConfig.vue';
import TaskDrawer from './TaskDrawer.vue';
import ProjectObjectives from './ProjectObjectives.vue';
import { useBarTitle } from '../../composables/useBarTitle';
import ProjectMembers from './ProjectMembers.vue';
import ProjectDocuments from './ProjectDocuments.vue';
import Timesheets from './Timesheets.vue';
import ProjectReports from './ProjectReports.vue';
import ProjectTimeline from './ProjectTimeline.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useSystemContext } from '../../lib/systemContext';
import { projectStatusLabel, projectStatusColor } from './projectStatus';
import { progressColor } from './workspaceOptions';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useAuthStore } from '../../stores/auth';

const TABS = ['overview', 'goals', 'list', 'board', 'calendar', 'timeline', 'milestones', 'members', 'documents', 'time', 'config', 'report'];

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
// The project's name in the top bar, next to the menu buttons (user, 2026-11-21: more room, like Hordex).
useBarTitle(() => p.value.title || tr('Project', 'Proyecto'));
// Secondary views: at hand on the project line, not among the main tabs (2026-11-21).
const secondary = computed(() => [
  can('tasks') && { key: 'list', icon: UnorderedListOutlined, label: tr('List', 'Lista') },
  { key: 'members', icon: TeamOutlined, label: tr('Members', 'Miembros') },
  can('tasks') && { key: 'documents', icon: PaperClipOutlined, label: tr('Documents', 'Documentos') },
  can('project_timesheets') && { key: 'time', icon: ClockCircleOutlined, label: tr('Timesheets', 'Horas') },
  can('projects') && { key: 'config', icon: SettingOutlined, label: tr('Configuration', 'Configuración') },
].filter(Boolean));
const isSecondary = computed(() => secondary.value.some(s => s.key === tab.value));
const secondaryNow = computed(() => secondary.value.find(s => s.key === tab.value) || {});
const lastMain = ref('overview');
watch(tab, v => { if (!['list', 'members', 'documents', 'time', 'config'].includes(v)) lastMain.value = v; }, { immediate: true });

// The description, edited in the overview.
const descEditing = ref(false);
const descDraft = ref('');
const descSaving = ref(false);
async function saveDesc() {
  descSaving.value = true;
  try {
    await http.put(`projects/${id.value}/description`, { description: descDraft.value });
    descEditing.value = false;
    await load();
  } catch (e) {
    message.error(tr('Could not save.', 'No se pudo guardar.'));
  } finally { descSaving.value = false; }
}
// What each tab is for (hover help).
const hints = computed(() => ({
  overview: tr('Key figures, what falls due next, the description and the team.', 'Cifras clave, lo próximo por vencer, la descripción y el equipo.'),
  goals: tr('The objectives the project must meet; each objective has its tasks.', 'Los objetivos que el proyecto debe cumplir; cada objetivo tiene sus tareas.'),
  board: tr('The tasks as cards by status: drag them to move them.', 'Las tareas como tarjetas por estado: arrástralas para moverlas.'),
  timeline: tr('Stages and tasks on a time bar.', 'Etapas y tareas en una barra de tiempo.'),
  calendar: tr('What falls due each day.', 'Lo que vence cada día.'),
  report: tr('Hours, progress and delays of the project.', 'Horas, avance y atrasos del proyecto.'),
  list: tr('Every task in a table, with search and sorting.', 'Todas las tareas en una tabla, con búsqueda y orden.'),
  milestones: tr('The phases of the project with their tasks; templates by type of project.', 'Las fases del proyecto con sus tareas; plantillas por tipo de proyecto.'),
  members: tr('Who takes part, from our company and the client, and their role.', 'Quiénes participan, de nuestra empresa y del cliente, y su rol.'),
  documents: tr('Files, videos and links attached to the tasks.', 'Archivos, videos y enlaces adjuntos a las tareas.'),
  time: tr('Hours worked on the project.', 'Horas trabajadas en el proyecto.'),
  config: tr('Links of the project, how to get in from the web and each member\'s credentials.', 'Enlaces del proyecto, cómo entrar desde la web y las credenciales de cada miembro.'),
  __sep: '',
}));

function onTab(key) {
  router.replace({ query: { ...route.query, tab: key } });
  if (['overview', 'calendar', 'timeline', 'milestones', 'documents'].includes(key)) load();
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
    { label: tr('Stages', 'Etapas'), value: `${s.milestones_done || 0} / ${s.milestones || 0}`, note: tr('completed', 'completados') },
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
    out.push({ key: `m${m.id}`, kind: 'milestone', id: m.id, title: m.title, date: m.due_date, late: !done && m.due_date < today, done, what: tr('Stage', 'Etapa') });
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

// A task opens in the side panel, half the screen (user, 2026-11-22), from every tab.
const drawer = ref({ open: false, taskId: null, milestoneId: null, objectiveId: null });
const boardKey = ref(0);
function newTask(milestoneId = null, objectiveId = null) {
  drawer.value = { open: true, taskId: null, milestoneId: typeof milestoneId === 'number' ? milestoneId : null, objectiveId };
}
function editTask(taskId) { drawer.value = { open: true, taskId: Number(taskId), milestoneId: null, objectiveId: null }; }
function onTaskSaved() {
  load();
  if (tab.value === 'list') taskCrud.fetchRows();
  if (tab.value === 'board') boardKey.value += 1;
}
function onTaskClosed() {
  drawer.value = { ...drawer.value, open: false };
  onTaskSaved();
}
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
.sec-btns {
  display: inline-flex;
  gap: 2px;
  padding-inline-start: 8px;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.25);
}
.secondary {
  margin-top: 4px;
}
.sec-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  padding-bottom: 6px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.sec-close {
  margin-inline-start: auto;
  font-size: 12.5px;
}
.head-progress {
  flex: 1 1 200px;
  max-width: 320px;
  margin-inline-start: auto;
}
.tight {
  margin-inline-start: 6px;
}
.tabs :deep(.ant-tabs-tab) {
  padding: 8px 7px;
  font-size: 13px;
}
.tabs :deep(.ant-tabs-tab + .ant-tabs-tab) {
  margin-inline-start: 2px;
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
.av.client {
  background: #0d9488 !important;
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
