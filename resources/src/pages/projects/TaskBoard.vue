<template>
  <div class="page">
    <PageHeader title="Task Board" subtitle="Every task, by stage." :breadcrumb="['Projects Management', 'Task Board']">
      <template #actions>
        <a-button @click="$router.push('/tasks')">
          <template #icon><UnorderedListOutlined /></template>
          List view
        </a-button>
        <a-button type="primary" @click="$router.push('/tasks/create')">
          <template #icon><PlusOutlined /></template>
          New task
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="filters.search" placeholder="Search task…"
          allow-clear class="tb-search" @search="load"
        />
        <a-select
          v-model:value="filters.project_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All projects"
          :options="projectOptions" @change="load"
        />
        <a-select
          v-model:value="filters.priority" class="tb-item-sm" allow-clear
          placeholder="Priority" :options="TASK_PRIORITIES" @change="load"
        />
        <a-tag v-if="overdue" color="error" class="tb-flag">{{ overdue }} overdue</a-tag>
        <a-tag>{{ total }} tasks</a-tag>
      </div>
    </a-card>

    <a-spin :spinning="loading">
      <div class="board">
        <section v-for="col in BOARD_COLUMNS" :key="col.key" class="col">
          <header class="col-head" :style="{ borderTopColor: col.color }">
            <span class="col-name">{{ col.label }}</span>
            <span class="col-count">{{ (columns[col.key] || []).length }}</span>
          </header>

          <div class="col-body">
            <article
              v-for="task in columns[col.key] || []" :key="task.id"
              class="card" :class="{ overdue: task.is_overdue }"
              @click="$router.push(`/tasks/${task.id}/edit`)"
            >
              <div class="card-top">
                <span class="card-title">{{ task.title }}</span>
                <a-tag
                  v-if="task.priority" :color="optionOf(TASK_PRIORITIES, task.priority).color"
                  class="card-prio"
                >
                  {{ labelOf(TASK_PRIORITIES, task.priority) }}
                </a-tag>
              </div>

              <div v-if="task.project_title" class="card-project">{{ task.project_title }}</div>

              <a-progress
                v-if="task.progress" :percent="task.progress" size="small"
                :stroke-color="progressColor(task.progress)" class="card-bar"
              />

              <footer class="card-foot" @click.stop>
                <span v-if="task.end_date" class="card-due" :class="{ late: task.is_overdue }">
                  <CalendarOutlined /> {{ date(task.end_date) }}
                </span>
                <span v-else class="card-due muted">No due date</span>

                <a-dropdown :trigger="['click']">
                  <a-button type="text" size="small" class="card-move">
                    <template #icon><SwapOutlined /></template>
                  </a-button>
                  <template #overlay>
                    <a-menu @click="({ key }) => move(task, key)">
                      <a-menu-item
                        v-for="target in BOARD_COLUMNS.filter(c => c.key !== col.key)"
                        :key="target.key"
                      >
                        Move to {{ target.label }}
                      </a-menu-item>
                    </a-menu>
                  </template>
                </a-dropdown>
              </footer>
            </article>

            <div v-if="!(columns[col.key] || []).length" class="col-empty">Nothing here</div>
          </div>
        </section>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Kanban view of every task.
 *
 * Cards move between columns through a menu rather than drag-and-drop: the
 * board has to work on a touch screen and with a keyboard, and a mis-drop that
 * silently reassigns a task is worse than one extra click. Completing a task
 * from here also sets its progress to 100 (the backend does that), so the bar
 * never contradicts the column.
 *
 * Backed by tasks_kanban / task_change_status — routes that already existed in
 * this app but had no implementation until now.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, UnorderedListOutlined, CalendarOutlined, SwapOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { BOARD_COLUMNS, TASK_PRIORITIES, labelOf, optionOf, progressColor } from './workspaceOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { date } = useFormat();

const columns = ref({});
const total = ref(0);
const overdue = ref(0);
const loading = ref(false);

const filters = reactive({ search: '', project_id: undefined, priority: undefined });

const projects = ref([]);
const projectOptions = computed(() => projects.value.map(p => ({ value: p.id, label: p.title })));

async function load() {
  loading.value = true;
  try {
    const data = await http.get('tasks_kanban', {
      search: filters.search || '',
      project_id: filters.project_id || '',
      priority: filters.priority || '',
    });
    columns.value = data?.columns || {};
    total.value = data?.total || 0;
    overdue.value = data?.overdue || 0;
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the board'));
  } finally {
    loading.value = false;
  }
}

async function move(task, status) {
  try {
    await http.post('task_change_status', { id: task.id, status });
    message.success('Task moved.');
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not move this task'));
  }
}

onMounted(async () => {
  load();
  try {
    const meta = await http.get('projects/meta');
    projects.value = meta?.projects || [];
  } catch (e) { /* the filter stays empty */ }
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
  width: 180px;
}
.tb-item-sm {
  width: 140px;
}
.tb-flag {
  margin-inline-start: auto;
}
.board {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 12px;
  align-items: start;
}
.col {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  overflow: hidden;
}
.col-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-top: 3px solid transparent;
  border-bottom: 1px solid rgba(128, 128, 128, 0.18);
  background: rgba(128, 128, 128, 0.06);
}
.col-name {
  font-weight: 600;
  font-size: 13px;
}
.col-count {
  font-size: 12px;
  opacity: 0.6;
  font-variant-numeric: tabular-nums;
}
.col-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px;
  min-height: 100px;
  max-height: 68vh;
  overflow-y: auto;
}
.card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 12px;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 11px;
  cursor: pointer;
  transition: border-color 0.15s ease, transform 0.12s ease, box-shadow 0.15s ease;
}
.card:hover {
  border-color: rgba(109, 40, 217, 0.5);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.07);
}
.card.overdue {
  border-inline-start: 3px solid #ff4d4f;
}
.card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}
.card-title {
  font-weight: 500;
  font-size: 13px;
  line-height: 1.35;
}
.card-prio {
  flex: none;
  margin-inline-end: 0;
  font-size: 10.5px;
}
.card-project {
  font-size: 11.5px;
  opacity: 0.6;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.card-bar {
  margin: 2px 0 0;
}
.card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 2px;
}
.card-due {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  opacity: 0.65;
}
.card-due.late {
  color: #ff4d4f;
  opacity: 1;
  font-weight: 500;
}
.card-move {
  opacity: 0;
  transition: opacity 0.15s ease;
}
.card:hover .card-move {
  opacity: 1;
}
.col-empty {
  text-align: center;
  padding: 20px 0;
  font-size: 12px;
  opacity: 0.35;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-item-sm {
    width: 100%;
  }
  .tb-flag {
    margin-inline-start: 0;
  }
}
</style>
