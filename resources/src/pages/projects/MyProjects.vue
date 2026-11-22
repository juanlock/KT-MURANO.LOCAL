<template>
  <div class="page">
    <a-spin :spinning="loading">
      <!-- The list: the projects I take part in -->
      <template v-if="!projectId">
        <a-empty v-if="!loading && !projects.length" :description="tr('You are not a member of any project yet.', 'Todavía no eres miembro de ningún proyecto.')" />
        <a-row :gutter="[16, 16]">
          <a-col v-for="p in projects" :key="p.id" :xs="24" :md="12" :xl="8">
            <a-card size="small" hoverable class="pcard" @click="$router.push(`/mis-proyectos/${p.id}`)">
              <div class="p-title">{{ p.title }}</div>
              <div class="p-sub">{{ p.company_name }} · {{ p.role || tr('Member', 'Miembro') }} <a-tag v-if="p.is_manager" color="gold" class="tight">{{ tr('Manager', 'Jefe de proyecto') }}</a-tag></div>
              <a-progress :percent="p.progress" size="small" :stroke-color="progressColor(p.progress)" />
              <div class="p-tasks">
                <span><b>{{ p.my_open }}</b> {{ tr('open tasks of mine', 'tareas mías abiertas') }}</span>
                <span class="muted">{{ tr('of', 'de') }} {{ p.my_tasks }}</span>
              </div>
            </a-card>
          </a-col>
        </a-row>
      </template>

      <!-- One project -->
      <template v-else-if="data.project">
        <div class="head">
          <a-button size="small" @click="$router.push('/mis-proyectos')"><template #icon><ArrowLeftOutlined /></template></a-button>
          <span class="h-co">{{ data.project.company_name }}</span>
          <span class="h-dates"><CalendarOutlined /> {{ data.project.start_date ? date(data.project.start_date) : '—' }} → {{ data.project.end_date ? date(data.project.end_date) : tr('no end date', 'sin fecha final') }}</span>
          <span class="grow" />
          <a v-for="(l, i) in data.project.links" :key="i" :href="l.url" target="_blank" rel="noopener" class="plink"><LinkOutlined /> {{ l.label || l.url }}</a>
        </div>

        <!-- My tasks first -->
        <a-card size="small" class="mine">
          <template #title><CheckSquareOutlined class="ic" /> {{ tr('My tasks', 'Mis tareas') }} <span class="cnt">{{ myTasks.length }}</span></template>
          <a-empty v-if="!myTasks.length" :image="simpleEmpty" :description="tr('No task assigned to you.', 'No tienes tareas asignadas.')" />
          <div v-for="t in myTasks" :key="t.id" class="mt" :class="{ late: isLate(t) }" @click="openTask(t.id)">
            <a-tag :color="projectStatusColor(t.status)" class="t-tag">{{ projectStatusLabel(t.status) }}</a-tag>
            <span class="t-title">{{ t.title }}</span>
            <span class="t-stage">{{ t.stage || '' }}</span>
            <span class="t-prog"><a-progress :percent="t.status === 'completed' ? 100 : t.progress" size="small" :stroke-color="progressColor(t.progress)" /></span>
            <span class="t-date">{{ t.end_date ? date(t.end_date) : '' }}</span>
          </div>
        </a-card>

        <!-- The whole project, to look -->
        <ProjectObjectives class="all" :project-id="projectId" :data="data" readonly :mine="data.my_task_ids || []" @open-task="openTask" />
      </template>
    </a-spin>

    <TaskDrawer :open="drawer.open" :project-id="projectId || 0" :task-id="drawer.taskId" member @close="drawer.open = false; load()" @saved="load" />
  </div>
</template>

<script setup>
/**
 * "Mis proyectos" (user, 2026-11-22): what a member sees -- their projects, their tasks first, the whole project read
 * only. They record their progress and upload documents on the tasks assigned to them (TaskDrawer, member mode),
 * whatever company they belong to (the client's staff included).
 */
import { ref, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Empty } from 'ant-design-vue';
import { ArrowLeftOutlined, CalendarOutlined, LinkOutlined, CheckSquareOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { progressColor } from './workspaceOptions';
import { projectStatusLabel, projectStatusColor } from './projectStatus';
import ProjectObjectives from './ProjectObjectives.vue';
import TaskDrawer from './TaskDrawer.vue';

const tr = useTr();
const route = useRoute();
const { date } = useFormat();
const simpleEmpty = Empty.PRESENTED_IMAGE_SIMPLE;

const projectId = computed(() => (route.params.id ? Number(route.params.id) : null));
const loading = ref(false);
const projects = ref([]);
const data = ref({});
useBarTitle(() => (projectId.value && data.value.project ? data.value.project.title : tr('My projects', 'Mis proyectos')));

const myTasks = computed(() => (data.value.tasks || []).filter(t => (data.value.my_task_ids || []).includes(t.id))
  .sort((a, b) => String(a.end_date || '9').localeCompare(String(b.end_date || '9'))));
const today = new Date().toISOString().slice(0, 10);
const isLate = t => t.end_date && String(t.end_date).slice(0, 10) < today && !['completed', 'cancelled'].includes(t.status);

async function load() {
  loading.value = true;
  try {
    if (projectId.value) data.value = await http.get(`my/projects/${projectId.value}`);
    else projects.value = (await http.get('my/projects')).projects || [];
  } finally { loading.value = false; }
}
const drawer = ref({ open: false, taskId: null });
function openTask(id) { drawer.value = { open: true, taskId: Number(id) }; }

watch(projectId, () => { data.value = {}; load(); }, { immediate: true });
</script>

<style scoped>
.pcard { cursor: pointer; }
.p-title { font-weight: 600; font-size: 15px; }
.p-sub { font-size: 12px; opacity: 0.65; margin-bottom: 6px; }
.p-tasks { display: flex; gap: 6px; font-size: 12.5px; }
.muted { opacity: 0.55; }
.tight { margin-inline-start: 6px; }
.head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-bottom: 12px; }
.h-co { font-weight: 600; }
.h-dates { font-size: 12.5px; opacity: 0.75; }
.grow { flex: 1; }
.plink { font-size: 12.5px; }
.mine { margin-bottom: 16px; }
.ic { color: #6366f1; }
.cnt { margin-inline-start: 6px; font-size: 11px; background: rgba(99, 102, 241, 0.15); border-radius: 8px; padding: 0 6px; }
.mt { display: flex; align-items: center; gap: 10px; padding: 6px 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.12); cursor: pointer; }
.mt:hover { background: rgba(128, 128, 128, 0.06); }
.mt.late .t-date { color: #dc2626; font-weight: 600; }
.t-tag { margin: 0; min-width: 84px; text-align: center; }
.t-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.t-stage { width: 150px; font-size: 12px; opacity: 0.65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.t-prog { width: 130px; }
.t-date { width: 86px; font-size: 12px; text-align: right; opacity: 0.75; }
@media (max-width: 768px) { .t-stage, .t-prog { display: none; } }
</style>
