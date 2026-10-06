<template>
  <div class="page">
    <PageHeader :title="$t('Daily_Tasks')" :breadcrumb="[$t('Service_Maintenance'), $t('Daily_Tasks')]" />

    <!-- Period: Week (default) / Day / Month + previous / today / next + any date. -->
    <a-card size="small" style="margin-bottom: 12px">
      <div class="dt-bar">
        <a-space wrap>
          <a-segmented v-model:value="view" :options="viewOptions" @change="load" />
          <a-button @click="shift(-1)"><template #icon><LeftOutlined /></template></a-button>
          <a-button :type="isToday ? 'primary' : 'default'" @click="goToday">Today</a-button>
          <a-button @click="shift(1)"><template #icon><RightOutlined /></template></a-button>
          <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
        </a-space>
        <a-space wrap>
          <a-select
            v-if="canAll" v-model:value="techFilter" allow-clear style="min-width: 180px"
            placeholder="Everyone" :options="techFilterOptions"
          />
          <a-segmented v-model:value="statusFilter" :options="statusFilterOptions" />
          <!-- The period's tasks as shown (filters applied), with their status. -->
          <a-button :disabled="!shown.length" :loading="pdfBusy" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template> PDF
          </a-button>
        </a-space>
      </div>
      <div class="dt-summary">
        <strong>{{ dayLabel }}</strong>
        <span>{{ shown.length }} task{{ shown.length === 1 ? '' : 's' }}</span>
        <span v-if="counts.completed">· {{ counts.completed }} completed</span>
        <span v-if="counts.open">· {{ counts.open }} to do</span>
      </div>
    </a-card>

    <a-alert
      v-if="!loading && !canAll && !linked" type="warning" show-icon style="margin-bottom: 12px"
      message="Your user isn't linked to an operator yet, so no jobs can be shown. Ask your supervisor to link your employee record in Service -> Setup -> Operators."
    />

    <a-spin :spinning="loading">
      <!-- DAY: the detailed list (best when one operator has many tasks a day). -->
      <template v-if="view === 'day'">
        <a-empty v-if="!loading && !shown.length" description="No tasks for this day." style="padding: 40px 0" />
        <div class="dt-list">
          <TaskCard
            v-for="task in shown" :key="task.id" :task="task" :can-assign="canAssign" :can-open-job="canOpenJob" :assign-options="assignOptions"
            @start="t => setStatus(t, 'in_progress')" @reopen="t => setStatus(t, 'in_progress')" @complete="openComplete" @note="openNote" @assign="assign"
          />
        </div>
      </template>

      <!-- WEEK: Monday to Sunday side by side (rows on a phone); compact cards, click for details. -->
      <div v-else-if="view === 'week'" class="wk">
        <div v-for="d in weekDays" :key="d" class="wk-col" :class="{ 'wk-col--today': d === todayStr, 'wk-col--weekend': isWeekend(d) }">
          <div class="wk-head" @click="openDate(d)">
            <span class="wk-dow">{{ dayjs(d).format('ddd') }}</span>
            <span class="wk-date">{{ dayjs(d).format('MMM D') }}</span>
            <span v-if="byDay[d]?.length" class="wk-count">{{ byDay[d].length }}</span>
          </div>
          <div v-if="!byDay[d]?.length" class="wk-empty">—</div>
          <div v-for="t in byDay[d] || []" :key="t.id" class="mini" :class="`mini--${t.status}`" @click="select(t)">
            <div class="mini-top">
              <span class="mini-time">{{ timeLabel(t) }}</span>
              <span class="mini-dot" :class="`mini-dot--${t.status}`" :title="statusLabel(t.status)" />
            </div>
            <div class="mini-customer">{{ t.client || 'No customer' }}</div>
            <div class="mini-title">{{ t.service_item || 'Service job' }}</div>
            <div v-if="canAll" class="mini-op">{{ operatorLabel(t) }}</div>
          </div>
        </div>
      </div>

      <!-- MONTH: calendar; a chip per task (click for details), click a day number for its Day view. -->
      <div v-else class="cal">
        <div v-for="w in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="w" class="cal-dow">{{ w }}</div>
        <div
          v-for="d in monthCells" :key="d" class="cal-cell"
          :class="{ 'cal-cell--out': !inMonth(d), 'cal-cell--today': d === todayStr }"
        >
          <div class="cal-num" @click="openDate(d)">{{ dayjs(d).date() }}</div>
          <div
            v-for="t in (byDay[d] || []).slice(0, 3)" :key="t.id" class="chip" :class="`chip--${t.status}`"
            :title="`${timeLabel(t)} · ${t.client || ''} · ${t.service_item || ''}`" @click="select(t)"
          >{{ t.client || t.service_item || t.Ref }}</div>
          <div v-if="(byDay[d] || []).length > 3" class="chip-more" @click="openDate(d)">+{{ byDay[d].length - 3 }} more</div>
        </div>
      </div>
    </a-spin>

    <!-- Still open from earlier, and what's coming: click a row to open that day. -->
    <a-row :gutter="12" style="margin-top: 12px">
      <a-col v-if="overdue.length" :xs="24" :md="12">
        <a-card size="small" :title="`Overdue -- not completed (${overdue.length})`" class="dt-side dt-side--late">
          <div v-for="j in overdue" :key="j.id" class="dt-row" @click="openDay(j)">
            <span class="dt-row__date">{{ shortDate(j.scheduled_date) }}</span>
            <span class="dt-row__title">{{ j.client || j.Ref }}</span>
            <span class="muted">{{ j.service_item }}{{ canAll ? ` · ${operatorLabel(j)}` : '' }}</span>
          </div>
        </a-card>
      </a-col>
      <a-col v-if="upcoming.length" :xs="24" :md="12">
        <a-card size="small" :title="`Coming up -- next 14 days (${upcoming.length})`" class="dt-side">
          <div v-for="j in upcoming" :key="j.id" class="dt-row" @click="openDay(j)">
            <span class="dt-row__date">{{ shortDate(j.scheduled_date) }}</span>
            <span class="dt-row__title">{{ j.client || j.Ref }}</span>
            <span class="muted">{{ j.service_item }}{{ canAll ? ` · ${operatorLabel(j)}` : '' }}</span>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Week / Month: the clicked task's full card (details + actions). -->
    <a-drawer :open="!!selected" :title="selected ? dayjs(selected.scheduled_date).format('dddd, MMMM D') : ''" width="440" @close="selectedId = null">
      <TaskCard
        v-if="selected" :task="selected" :can-assign="canAssign" :can-open-job="canOpenJob" :assign-options="assignOptions"
        @start="t => setStatus(t, 'in_progress')" @reopen="t => setStatus(t, 'in_progress')" @complete="openComplete" @note="openNote" @assign="assign"
      />
    </a-drawer>

    <!-- Complete / add a note. -->
    <a-modal
      v-model:open="modal.open" :title="modal.complete ? `Complete ${modal.task?.Ref || ''}` : `Note on ${modal.task?.Ref || ''}`"
      :ok-text="modal.complete ? 'Complete' : 'Save note'" :confirm-loading="modal.saving" @ok="saveModal"
    >
      <p v-if="modal.task" style="margin-bottom: 8px">{{ modal.task.service_item }} — {{ modal.task.client }}</p>
      <!-- Hours worked: the job's labor cost and the hourly payroll. -->
      <a-form-item v-if="modal.complete" label="Hours worked" extra="Used for the job's cost and the weekly payroll." style="margin-bottom: 10px">
        <a-input-number v-model:value="modal.hours" :min="0" :max="336" :step="0.25" style="width: 160px" placeholder="e.g. 2.5" />
      </a-form-item>
      <a-textarea v-model:value="modal.note" :rows="4" :placeholder="modal.complete ? 'What was done (optional)' : 'Note'" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Tasks: the service jobs for the people doing them (DailyTasksController).
 *  - Week (default): Mon-Sun side by side, compact cards -- for operators with
 *    a few tasks a week; Day: the detailed list -- for busy days; Month: calendar.
 *  - daily_tasks = own jobs (+ "All operators" crew jobs), start / complete with
 *    hours and a note; daily_tasks_all = everyone's; daily_tasks_assign = assign.
 * Role home page for field staff.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { LeftOutlined, RightOutlined, FilePdfOutlined } from '@ant-design/icons-vue';
import { exportPdf } from '../../lib/exporters';
import PageHeader from '../../components/PageHeader.vue';
import TaskCard from '../../components/TaskCard.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { statusLabel, timeLabel, address, operatorLabel } from '../../lib/taskFormat';

const date = ref(dayjs().format('YYYY-MM-DD'));
const tasks = ref([]);
const loading = ref(false);
const canAll = ref(false);
const canAssign = ref(false);
const canOpenJob = ref(false);
const linked = ref(true);
const technicians = ref([]);
const techFilter = ref(undefined);
const overdue = ref([]);
const upcoming = ref([]);
const statusFilter = ref('all');
const shortDate = d => (d ? dayjs(d).format('ddd MMM D') : '');

// Week (default) / Day / Month; the server sends the period's from / to.
const view = ref('week');
const viewOptions = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
];
const period = ref({ from: date.value, to: date.value });
const todayStr = dayjs().format('YYYY-MM-DD');
const isToday = computed(() => todayStr >= period.value.from && todayStr <= period.value.to);
const isWeekend = d => [0, 6].includes(dayjs(d).day());
const dayLabel = computed(() => {
  const f = dayjs(period.value.from);
  const t = dayjs(period.value.to);
  if (view.value === 'month') return f.format('MMMM YYYY');
  if (view.value === 'week') return `Week of ${f.format('MMM D')} – ${t.format(f.month() === t.month() ? 'D, YYYY' : 'MMM D, YYYY')}`;
  return dayjs(date.value).format('dddd, MMMM D, YYYY');
});

/** Open a day (from the week / month / lists) in the Day view. */
function openDate(d) {
  date.value = d;
  view.value = 'day';
  load();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function openDay(j) {
  openDate(dayjs(j.scheduled_date).format('YYYY-MM-DD'));
}

const techOptions = computed(() => sortByLabel(technicians.value.map(t => ({ value: t.id, label: t.name }))));
const techFilterOptions = computed(() => [{ value: 0, label: 'Not assigned' }, { value: 'all', label: 'All operators' }, ...techOptions.value]);
// Assign: "All operators" (crew job) first, then each operator.
const assignOptions = computed(() => [{ value: 'all', label: 'All operators' }, ...techOptions.value]);
const statusFilterOptions = [
  { value: 'all', label: 'All' },
  { value: 'open', label: 'To do' },
  { value: 'completed', label: 'Completed' },
];
const shown = computed(() => tasks.value
  .filter(t => techFilter.value === undefined || techFilter.value === null
    || (techFilter.value === 'all' ? !!t.assign_all : techFilter.value === 0 ? (!t.technician_id && !t.assign_all) : (t.technician_id === techFilter.value || !!t.assign_all)))
  .filter(t => statusFilter.value === 'all' || (statusFilter.value === 'completed' ? t.status === 'completed' : t.status !== 'completed')));
const counts = computed(() => ({
  completed: shown.value.filter(t => t.status === 'completed').length,
  open: shown.value.filter(t => t.status !== 'completed').length,
}));

// Tasks per day (a multi-day job that began before the period shows on its first day).
const taskDay = t => {
  const d = String(t.scheduled_date || '').slice(0, 10);
  return d < period.value.from ? period.value.from : d;
};
const byDay = computed(() => {
  const map = {};
  shown.value.forEach(t => { (map[taskDay(t)] ||= []).push(t); });
  return map;
});
const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => dayjs(period.value.from).add(i, 'day').format('YYYY-MM-DD')));
// Month grid: whole weeks (Mon-Sun) around the month.
const monthCells = computed(() => {
  const first = dayjs(period.value.from);
  const start = first.subtract((first.day() + 6) % 7, 'day');
  const last = dayjs(period.value.to);
  const end = last.add((7 - last.day()) % 7, 'day');
  const cells = [];
  for (let d = start; !d.isAfter(end); d = d.add(1, 'day')) cells.push(d.format('YYYY-MM-DD'));
  return cells;
});
const inMonth = d => d >= period.value.from && d <= period.value.to;

// Week / Month: the clicked task in a side panel (kept in sync after each action).
const selectedId = ref(null);
const selected = computed(() => tasks.value.find(t => t.id === selectedId.value) || null);
const select = t => { selectedId.value = t.id; };

// PDF of the period's tasks (as filtered on screen), with a Status column.
const pdfBusy = ref(false);
async function downloadPdf() {
  pdfBusy.value = true;
  try {
    const cols = [
      ...(view.value !== 'day' ? [{ title: 'Date', dataIndex: 'x_date' }] : []),
      { title: 'Time', dataIndex: 'x_time' },
      { title: 'Customer', dataIndex: 'client' },
      { title: 'Job', dataIndex: 'x_job' },
      { title: 'Address / phone', dataIndex: 'x_where' },
      { title: 'Operator', dataIndex: 'x_tech' },
      { title: 'Status', dataIndex: 'x_status' },
      { title: 'Work requested', dataIndex: 'reported_issue' },
    ];
    const rows = [...shown.value].sort((a, b) => taskDay(a).localeCompare(taskDay(b))).map(t => ({
      ...t,
      x_date: dayjs(taskDay(t)).format('ddd MMM D'),
      x_time: timeLabel(t),
      x_job: `${t.Ref} - ${t.service_item || 'Service job'}`,
      x_where: [address(t), t.client_phone].filter(Boolean).join('\n'),
      x_tech: operatorLabel(t),
      x_status: statusLabel(t.status),
    }));
    await exportPdf(`Tasks - ${dayLabel.value}`, cols, rows, { landscape: true });
  } catch (e) {
    message.error('Could not create the PDF.');
  } finally {
    pdfBusy.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('daily_tasks', { date: date.value, view: view.value });
    period.value = { from: data.from || date.value, to: data.to || date.value };
    tasks.value = data.tasks || [];
    overdue.value = data.overdue || [];
    upcoming.value = data.upcoming || [];
    canAll.value = !!data.can_all;
    canAssign.value = !!data.can_assign;
    canOpenJob.value = !!data.can_open_job;
    linked.value = !!data.linked;
    technicians.value = data.technicians || [];
  } catch (e) {
    message.error(e?.data?.message || 'Could not load the tasks.');
  } finally {
    loading.value = false;
  }
}
function shift(n) {
  // One day / week / month back or forward.
  date.value = dayjs(date.value).add(n, view.value).format('YYYY-MM-DD');
  load();
}
function goToday() {
  date.value = dayjs().format('YYYY-MM-DD');
  load();
}

async function setStatus(task, status, note = '', hours = null) {
  try {
    await http.post(`daily_tasks/${task.id}/status`, { status, note, ...(hours !== null && hours !== undefined ? { hours } : {}) });
    message.success(status === 'completed' ? `${task.Ref} completed.` : status === 'in_progress' ? `${task.Ref} started.` : 'Note saved.');
    await load();
    return true;
  } catch (e) {
    message.error(e?.data?.message || 'Could not update the task.');
    return false;
  }
}
async function assign(task, technicianId) {
  try {
    await http.post(`daily_tasks/${task.id}/assign`, { technician_id: technicianId || null });
    message.success(technicianId === 'all' ? 'Assigned to all operators.' : technicianId ? 'Assigned.' : 'Unassigned.');
    await load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not assign the task.');
  }
}

const modal = ref({ open: false, complete: false, task: null, note: '', hours: null, saving: false });
// Suggested hours: already entered, else the time since Start (rounded to a quarter hour, same day only).
function suggestHours(task) {
  if (task.work_hours !== null && task.work_hours !== undefined) return Number(task.work_hours);
  if (!task.started_at) return null;
  const h = dayjs().diff(dayjs(task.started_at), 'minute') / 60;
  return h > 0 && h <= 16 ? Math.round(h * 4) / 4 : null;
}
function openComplete(task) {
  modal.value = { open: true, complete: true, task, note: '', hours: suggestHours(task), saving: false };
}
function openNote(task) {
  modal.value = { open: true, complete: false, task, note: '', hours: null, saving: false };
}
async function saveModal() {
  const m = modal.value;
  if (!m.complete && !m.note.trim()) { message.warning('Write a note first.'); return; }
  m.saving = true;
  // A note alone keeps the job's status (status sent empty).
  const ok = await setStatus(m.task, m.complete ? 'completed' : '', m.note, m.complete ? m.hours : null);
  m.saving = false;
  if (ok) m.open = false;
}

onMounted(load);
</script>

<style scoped>
.dt-bar { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.dt-summary { margin-top: 10px; display: flex; gap: 6px; flex-wrap: wrap; opacity: 0.85; }
.dt-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
@media (max-width: 600px) { .dt-list { grid-template-columns: 1fr; } }

/* Week: 7 columns; rows on a phone. */
.wk { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 8px; }
.wk-col { background: rgba(128, 128, 128, 0.05); border-radius: 8px; padding: 6px; min-height: 140px; }
.wk-col--weekend { background: rgba(128, 128, 128, 0.09); }
/* Inset ring: an outline drew outside the column and touched the screen edges on phones. */
.wk-col--today { box-shadow: inset 0 0 0 2px #1677ff; }
/* Phone / tablet: days as rows; an empty day is just its header line. */
@media (max-width: 900px) {
  .wk { grid-template-columns: 1fr; gap: 6px; }
  .wk-col { min-height: 0; }
  .wk-col:not(:has(.mini)) { display: flex; align-items: center; padding: 4px 6px; }
  .wk-col:not(:has(.mini)) .wk-head { padding: 2px 4px; }
  .wk-empty { display: none; }
}
.wk-head { display: flex; align-items: baseline; gap: 6px; padding: 2px 4px 6px; cursor: pointer; }
.wk-dow { font-weight: 700; }
.wk-date { opacity: 0.7; font-size: 12px; }
.wk-count { margin-left: auto; font-size: 11px; background: #1677ff; color: #fff; border-radius: 10px; padding: 0 7px; }
.wk-empty { opacity: 0.35; text-align: center; padding: 8px 0; }
.mini { background: var(--ant-color-bg-container, #fff); border-left: 4px solid #faad14; border-radius: 6px; padding: 6px 8px; margin-bottom: 6px; cursor: pointer; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06); }
.mini:hover { box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15); }
.mini--in_progress { border-left-color: #1677ff; }
.mini--completed { border-left-color: #52c41a; opacity: 0.75; }
.mini-top { display: flex; justify-content: space-between; align-items: center; font-size: 11px; font-weight: 600; }
.mini-dot { width: 8px; height: 8px; border-radius: 50%; background: #faad14; }
.mini-dot--in_progress { background: #1677ff; }
.mini-dot--completed { background: #52c41a; }
.mini-customer { font-weight: 700; font-size: 14px; line-height: 1.2; margin-top: 2px; word-break: break-word; }
.mini-title { font-size: 12px; opacity: 0.8; word-break: break-word; }
.mini-op { font-size: 11px; opacity: 0.6; margin-top: 2px; }

/* Month calendar. */
.cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.cal-dow { text-align: center; font-weight: 700; font-size: 12px; opacity: 0.7; padding: 4px 0; }
.cal-cell { min-height: 92px; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 6px; padding: 4px; overflow: hidden; }
.cal-cell--out { opacity: 0.4; }
.cal-cell--today { outline: 2px solid #1677ff; }
.cal-num { font-weight: 700; font-size: 12px; cursor: pointer; margin-bottom: 2px; }
.chip { font-size: 11px; padding: 1px 6px; border-radius: 4px; margin-bottom: 2px; background: #fff7e6; border-left: 3px solid #faad14; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
.chip--in_progress { background: #e6f4ff; border-left-color: #1677ff; }
.chip--completed { background: #f6ffed; border-left-color: #52c41a; }
.chip-more { font-size: 11px; color: #1677ff; cursor: pointer; }
@media (max-width: 600px) { .cal-cell { min-height: 60px; } .chip { font-size: 10px; padding: 0 3px; } }

.dt-side { margin-bottom: 12px; }
.dt-side--late { border-top: 3px solid #ff4d4f; }
.dt-row { display: flex; gap: 8px; flex-wrap: wrap; padding: 6px 0; border-top: 1px solid rgba(128, 128, 128, 0.12); cursor: pointer; }
.dt-row:first-child { border-top: 0; }
.dt-row:hover { opacity: 0.8; }
.dt-row__date { font-weight: 600; min-width: 90px; }
.dt-row__title { flex: 1 1 160px; font-weight: 600; }
</style>
