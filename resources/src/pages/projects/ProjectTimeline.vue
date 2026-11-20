<template>
  <div class="tl">
    <!-- Template picker (the user's Smartsheet project timeline templates, 2026-11-20) + the view's own controls. -->
    <div class="bar">
      <a-segmented v-model:value="view" :options="viewOptions" />
      <template v-if="view === 'gantt'">
        <a-segmented v-model:value="scale" size="small" :options="scaleOptions" />
        <a-select v-model:value="windowMonths" size="small" style="width: 150px" :options="windowOptions" />
        <a-button-group v-if="windowMonths" size="small">
          <a-button @click="shift(-1)"><LeftOutlined /></a-button>
          <a-button @click="shift(0)">{{ tr('Today', 'Hoy') }}</a-button>
          <a-button @click="shift(1)"><RightOutlined /></a-button>
        </a-button-group>
        <a-segmented v-model:value="colorBy" size="small" :options="colorOptions" />
      </template>
      <span class="grow" />
      <a-tooltip :title="tr('How the phases are made', 'Cómo se arman las fases')">
        <a-popover trigger="click" placement="bottomRight">
          <template #content>
            <div class="help">{{ tr(
              'Each milestone closes a phase: a task belongs to the phase of the first milestone due on or after its end date. Tasks after the last milestone form the closing phase.',
              'Cada hito cierra una fase: una tarea pertenece a la fase del primer hito que vence el mismo día o después de su fecha de fin. Las tareas posteriores al último hito forman la fase de cierre.') }}</div>
          </template>
          <a-button size="small" type="text"><InfoCircleOutlined /></a-button>
        </a-popover>
      </a-tooltip>
      <a-button size="small" @click="print"><template #icon><PrinterOutlined /></template>{{ tr('Print / PDF', 'Imprimir / PDF') }}</a-button>
    </div>

    <a-empty v-if="!rows.length && !miles.length" :description="tr('Add tasks with dates or milestones to see the timeline.', 'Agregue tareas con fechas o hitos para ver la línea de tiempo.')" />

    <div v-else ref="sheet" class="sheet">
      <div class="print-title">{{ project.title }} — {{ tr('Timeline', 'Línea de tiempo') }} ({{ viewLabel }})</div>

      <!-- 1. Simple: task, owner, start, end, duration, status + its bar (Smartsheet "simple project timeline"). -->
      <table v-if="view === 'simple'" class="simple">
        <thead>
          <tr>
            <th>{{ tr('Phase / task', 'Fase / tarea') }}</th>
            <th>{{ tr('Owner', 'Responsable') }}</th>
            <th class="d">{{ tr('Start', 'Inicio') }}</th>
            <th class="d">{{ tr('End', 'Fin') }}</th>
            <th class="n">{{ tr('Days', 'Días') }}</th>
            <th>{{ tr('Status', 'Estado') }}</th>
            <th class="bar-col">
              <span>{{ date(range.start) }}</span><span>{{ date(range.end) }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="ph in phases" :key="ph.key">
            <tr class="ph-row">
              <td colspan="2"><i class="dot" :style="{ background: ph.color }" /> {{ ph.title }}</td>
              <td class="d">{{ ph.start ? date(ph.start) : '—' }}</td>
              <td class="d">{{ ph.end ? date(ph.end) : '—' }}</td>
              <td class="n">{{ ph.start && ph.end ? days(ph.start, ph.end) : '' }}</td>
              <td>{{ ph.done }}/{{ ph.tasks.length }}</td>
              <td class="bar-col"><div class="mini"><i v-if="ph.start && ph.end" :style="miniStyle(ph.start, ph.end, ph.color)" /></div></td>
            </tr>
            <tr v-for="t in ph.tasks" :key="t.key" class="t-row" @click="$emit('open-task', t.id)">
              <td class="indent">{{ t.title }}</td>
              <td class="muted">{{ t.assignees || '—' }}</td>
              <td class="d">{{ t.start ? date(t.start) : '—' }}</td>
              <td class="d" :class="{ late: t.late }">{{ t.end ? date(t.end) : '—' }}</td>
              <td class="n">{{ t.start && t.end ? days(t.start, t.end) : '' }}</td>
              <td><a-tag :color="statusTag(t.status)" class="tag">{{ projectStatusLabel(t.status) }}</a-tag></td>
              <td class="bar-col"><div class="mini"><i v-if="t.start && t.end" :style="miniStyle(t.start, t.end, ph.color, t.progress)" /></div></td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- 2. Gantt by week / month over the whole project or a 3 / 6 / 12-month window (Smartsheet weekly, 3-, 6-month and annual timelines). -->
      <div v-else-if="view === 'gantt'" class="gantt">
        <div class="g-head">
          <div class="g-label" />
          <div class="g-scale">
            <div v-for="tk in ticks" :key="tk.key" class="tick" :class="{ minor: tk.minor }" :style="{ left: tk.left + '%', width: tk.width + '%' }">{{ tk.label }}</div>
          </div>
        </div>
        <div class="g-body">
          <div class="g-grid">
            <div class="g-label" />
            <div class="g-lines">
              <i v-for="tk in ticks" :key="'l' + tk.key" :style="{ left: tk.left + '%' }" />
              <b v-if="todayPos !== null" class="today" :style="{ left: todayPos + '%' }"><span>{{ tr('Today', 'Hoy') }}</span></b>
            </div>
          </div>
          <template v-for="ph in phases" :key="'g' + ph.key">
            <div class="g-row ph">
              <div class="g-label"><i class="dot" :style="{ background: ph.color }" /> {{ ph.title }}</div>
              <div class="g-track">
                <div v-if="visible(ph.start, ph.end)" class="g-phase" :style="barStyle(ph.start, ph.end, ph.color)" />
                <a-tooltip v-if="ph.milestone && posOf(ph.milestone.date) !== null" :title="`${ph.milestone.title} · ${date(ph.milestone.date)}`">
                  <span class="diamond" :class="{ done: ph.milestone.done, late: ph.milestone.late }" :style="{ left: posOf(ph.milestone.date) + '%' }" />
                </a-tooltip>
              </div>
            </div>
            <div v-for="t in ph.tasks" :key="'g' + t.key" class="g-row" @click="$emit('open-task', t.id)">
              <div class="g-label indent" :title="t.title">{{ t.title }}</div>
              <div class="g-track">
                <a-tooltip v-if="t.start && t.end && visible(t.start, t.end)" :title="taskTip(t)">
                  <div class="g-bar" :class="{ late: t.late, done: t.status === 'completed', cancelled: t.status === 'cancelled' }" :style="barStyle(t.start, t.end, barColor(t, ph))">
                    <i class="fill" :style="{ width: (t.progress || 0) + '%' }" />
                  </div>
                </a-tooltip>
              </div>
            </div>
          </template>
        </div>
        <div class="legend">
          <template v-if="colorBy === 'status'">
            <span v-for="s in STATUS_COLORS" :key="s.v"><i class="sw" :style="{ background: s.c }" /> {{ projectStatusLabel(s.v) }}</span>
          </template>
          <template v-else>
            <span v-for="ph in phases" :key="'lg' + ph.key"><i class="sw" :style="{ background: ph.color }" /> {{ ph.title }}</span>
          </template>
          <span><i class="sw dia" /> {{ tr('Milestone', 'Hito') }}</span>
          <span><i class="sw late" /> {{ tr('Overdue', 'Atrasada') }}</span>
        </div>
      </div>

      <!-- 3. Milestones on one line (Smartsheet "project milestones chart"). -->
      <div v-else-if="view === 'milestones'" class="ms">
        <a-empty v-if="!miles.length" :description="tr('This project has no milestones yet.', 'Este proyecto todavía no tiene hitos.')" />
        <div v-else class="ms-line">
          <div class="ms-axis" />
          <b v-if="msToday !== null" class="today" :style="{ left: msToday + '%' }"><span>{{ tr('Today', 'Hoy') }}</span></b>
          <div class="ms-end start"><small>{{ tr('Start', 'Inicio') }}</small>{{ date(range.start) }}</div>
          <div class="ms-end end"><small>{{ tr('End', 'Fin') }}</small>{{ date(range.end) }}</div>
          <div
            v-for="(m, i) in miles" :key="m.key" class="ms-item" :class="[i % 2 ? 'down' : 'up', { done: m.done, late: m.late }]"
            :style="{ left: msPos(m.date) + '%' }"
          >
            <span class="ms-dia" />
            <span class="ms-stem" />
            <div class="ms-card" :style="{ borderColor: phaseColor(i) }">
              <div class="ms-date">{{ date(m.date) }}</div>
              <div class="ms-title">{{ m.title }}</div>
              <div class="ms-state">{{ m.done ? tr('Completed', 'Completado') : (m.late ? tr('Overdue', 'Atrasado') : `${m.progress || 0}%`) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 4. Phases as bands with their tasks (Smartsheet PowerPoint / strategic / marketing timelines: stages in colour). -->
      <div v-else class="phases">
        <div class="ph-band">
          <div v-for="(ph, i) in phases" :key="'b' + ph.key" class="ph-seg" :style="{ background: ph.color }">
            <div class="ph-num">{{ tr('Phase', 'Fase') }} {{ i + 1 }}</div>
            <div class="ph-name" :title="ph.title">{{ ph.title }}</div>
            <div class="ph-dates">
              {{ ph.start ? dayMonth(ph.start) : '' }} – {{ ph.end ? dayMonth(ph.end) : '' }}
              <template v-if="ph.start && ph.end"> · {{ days(ph.start, ph.end) }} {{ tr('days', 'días') }}</template>
            </div>
          </div>
        </div>
        <div class="ph-cols">
          <div v-for="ph in phases" :key="'c' + ph.key" class="ph-col">
            <div class="ph-prog"><a-progress :percent="ph.tasks.length ? Math.round(ph.done * 100 / ph.tasks.length) : 0" size="small" :stroke-color="ph.color" /></div>
            <div v-for="t in ph.tasks" :key="'c' + t.key" class="ph-task" :class="{ done: t.status === 'completed', late: t.late }" @click="$emit('open-task', t.id)">
              <CheckCircleFilled v-if="t.status === 'completed'" class="ok" />
              <ClockCircleOutlined v-else />
              <span>{{ t.title }}</span>
            </div>
            <div v-if="!ph.tasks.length" class="muted small">{{ tr('No tasks', 'Sin tareas') }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Project -> "Línea de tiempo" tab (user, 2026-11-20: the Smartsheet project timeline templates). Four views built from the
 * project's own tasks and milestones, nothing typed twice:
 *   simple     -- table: task, owner, start, end, days, status + a bar       (simple / weekly timeline)
 *   gantt      -- bars by week or month, whole project or a 3/6/12-month window (3-, 6-month and annual timelines)
 *   milestones -- the milestones on one line, above / below                    (milestones chart)
 *   phases     -- coloured stages with their tasks                             (PowerPoint / strategic / marketing)
 * A milestone closes a phase: a task goes to the first milestone due on or after its end.
 */
import { ref, computed, watch } from 'vue';
import dayjs from 'dayjs';
import {
  LeftOutlined, RightOutlined, PrinterOutlined, InfoCircleOutlined, CheckCircleFilled, ClockCircleOutlined,
} from '@ant-design/icons-vue';
import { useFormat } from '../../composables/useFormat';
import { useTr } from '../../lib/useTr';
import { projectStatusLabel } from './projectStatus';

const props = defineProps({
  project: { type: Object, default: () => ({}) },
  tasks: { type: Array, default: () => [] },
  milestones: { type: Array, default: () => [] },
});
defineEmits(['open-task']);

const tr = useTr();
const { date, dayMonth } = useFormat();

const VIEW_KEY = 'project_timeline_view';
const readPref = (k, d) => { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };
const view = ref(readPref(VIEW_KEY, 'gantt'));
watch(view, v => { try { localStorage.setItem(VIEW_KEY, v); } catch (e) { /* private window */ } });
const scale = ref('week');
const windowMonths = ref(0);
const colorBy = ref('phase');
const offset = ref(0);

const viewOptions = computed(() => [
  { value: 'simple', label: tr('Simple', 'Simple') },
  { value: 'gantt', label: tr('Gantt', 'Gantt') },
  { value: 'milestones', label: tr('Milestones', 'Hitos') },
  { value: 'phases', label: tr('Phases', 'Fases') },
]);
const viewLabel = computed(() => viewOptions.value.find(o => o.value === view.value)?.label);
const scaleOptions = computed(() => [{ value: 'week', label: tr('Weeks', 'Semanas') }, { value: 'month', label: tr('Months', 'Meses') }]);
const windowOptions = computed(() => [
  { value: 0, label: tr('Whole project', 'Todo el proyecto') },
  { value: 3, label: tr('3 months', '3 meses') },
  { value: 6, label: tr('6 months', '6 meses') },
  { value: 12, label: tr('12 months', '12 meses') },
]);
const colorOptions = computed(() => [{ value: 'phase', label: tr('By phase', 'Por fase') }, { value: 'status', label: tr('By status', 'Por estado') }]);
watch(windowMonths, () => { offset.value = 0; });
watch(windowMonths, m => { if (m >= 6) scale.value = 'month'; });

// Fixed categorical order for the phases (never cycled by rank: phase i keeps its colour).
const PALETTE = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#0891b2', '#db2777', '#65a30d', '#7c3aed'];
const phaseColor = i => PALETTE[i % PALETTE.length];
const STATUS_COLORS = [
  { v: 'not_started', c: '#94a3b8' }, { v: 'progress', c: '#2563eb' }, { v: 'hold', c: '#d97706' },
  { v: 'completed', c: '#16a34a' }, { v: 'cancelled', c: '#b91c1c' },
];
const statusTag = s => ({ not_started: 'default', progress: 'processing', hold: 'warning', on_hold: 'warning', completed: 'success', cancelled: 'error' }[s] || 'default');

const d10 = v => (v ? String(v).slice(0, 10) : null);
const today = dayjs().format('YYYY-MM-DD');

const rows = computed(() => props.tasks.map(t => {
  const start = d10(t.start_date) || d10(t.end_date);
  const end = d10(t.end_date) || start;
  return { key: `t${t.id}`, id: t.id, title: t.title, status: t.status, progress: t.status === 'completed' ? 100 : (t.progress || 0), assignees: t.assignees, start, end, late: !!t.is_overdue };
}).sort((a, b) => (a.start || '9999').localeCompare(b.start || '9999') || (a.end || '').localeCompare(b.end || '')));

const miles = computed(() => props.milestones.filter(m => m.due_date).map(m => ({
  key: `m${m.id}`, id: m.id, title: m.title, date: d10(m.due_date), progress: m.progress, done: m.status === 'completed',
  late: m.status !== 'completed' && d10(m.due_date) < today,
})).sort((a, b) => a.date.localeCompare(b.date)));

const phases = computed(() => {
  const list = miles.value.map((m, i) => ({ key: m.key, title: m.title, milestone: m, color: phaseColor(i), tasks: [] }));
  const closing = { key: 'close', title: list.length ? tr('Closing', 'Cierre') : (props.project.title || tr('Project', 'Proyecto')), milestone: null, color: phaseColor(list.length), tasks: [] };
  rows.value.forEach(t => {
    const ref = t.end || t.start;
    const ph = ref ? list.find(p => p.milestone.date >= ref) : null;
    (ph || closing).tasks.push(t);
  });
  const all = closing.tasks.length || !list.length ? [...list, closing] : list;
  let prevEnd = d10(props.project.start_date);
  return all.map(ph => {
    const starts = ph.tasks.map(t => t.start).filter(Boolean);
    const ends = ph.tasks.map(t => t.end).filter(Boolean);
    if (ph.milestone) ends.push(ph.milestone.date);
    const start = starts.length ? starts.reduce((a, b) => (a < b ? a : b)) : (prevEnd || (ends[0] || null));
    const end = ends.length ? ends.reduce((a, b) => (a > b ? a : b)) : start;
    prevEnd = end;
    return { ...ph, start, end, done: ph.tasks.filter(t => t.status === 'completed').length };
  });
});

// Whole span: project dates, tasks and milestones.
const range = computed(() => {
  const dates = [d10(props.project.start_date), d10(props.project.end_date), ...rows.value.flatMap(t => [t.start, t.end]), ...miles.value.map(m => m.date)].filter(Boolean).sort();
  const s = dates[0] || today;
  let e = dates[dates.length - 1] || today;
  if (e === s) e = dayjs(s).add(7, 'day').format('YYYY-MM-DD');
  return { start: s, end: e };
});
const days = (a, b) => dayjs(b).diff(dayjs(a), 'day') + 1;

// The Gantt's visible window.
const win = computed(() => {
  if (!windowMonths.value) {
    const unit = scale.value === 'week' ? 'week' : 'month';
    return { start: dayjs(range.value.start).startOf(unit), end: dayjs(range.value.end).endOf(unit) };
  }
  const base = offset.value === null ? dayjs().startOf('month') : dayjs(range.value.start).startOf('month');
  const start = base.add((offset.value || 0) * windowMonths.value, 'month');
  return { start, end: start.add(windowMonths.value, 'month').subtract(1, 'day').endOf('day') };
});
function shift(dir) {
  if (dir === 0) {
    const m = dayjs().startOf('month').diff(dayjs(range.value.start).startOf('month'), 'month');
    offset.value = Math.floor(m / windowMonths.value);
  } else {
    offset.value = (offset.value || 0) + dir;
  }
}
const spanMs = computed(() => win.value.end.valueOf() - win.value.start.valueOf());
const pct = d => ((dayjs(d).valueOf() - win.value.start.valueOf()) / spanMs.value) * 100;
const posOf = d => { const p = pct(d); return p < 0 || p > 100 ? null : p; };
const visible = (s, e) => s && e && dayjs(e).endOf('day').isAfter(win.value.start) && dayjs(s).isBefore(win.value.end);
function barStyle(s, e, color) {
  const l = Math.max(0, pct(s));
  const r = Math.min(100, pct(dayjs(e).endOf('day')));
  return { left: `${l}%`, width: `${Math.max(0.6, r - l)}%`, '--c': color };
}
const todayPos = computed(() => posOf(today));

// Month names in the screen's language (dayjs here has no locale loaded).
const monthName = (d, withYear) => {
  const lang = document.documentElement.lang || 'en';
  const m = new Intl.DateTimeFormat(lang, { month: 'short' }).format(d.toDate()).replace('.', '');
  const cap = m.charAt(0).toUpperCase() + m.slice(1);
  return withYear ? `${cap} ${d.year()}` : cap;
};

const ticks = computed(() => {
  const out = [];
  const unit = scale.value;
  let c = win.value.start.startOf(unit === 'week' ? 'week' : 'month');
  let i = 0;
  while (c.isBefore(win.value.end) && i < 160) {
    const n = c.add(1, unit);
    const l = Math.max(0, pct(c));
    const r = Math.min(100, pct(n));
    const label = unit === 'week' ? c.format('DD/MM') : monthName(c, i === 0 || c.month() === 0);
    out.push({ key: c.valueOf(), left: l, width: r - l, label, minor: unit === 'week' && i % 2 === 1 && out.length > 16 });
    c = n;
    i++;
  }
  return out;
});

const barColor = (t, ph) => (colorBy.value === 'status' ? (STATUS_COLORS.find(s => s.v === t.status)?.c || '#94a3b8') : ph.color);
const taskTip = t => `${t.title} · ${date(t.start)} → ${date(t.end)} (${days(t.start, t.end)} ${tr('days', 'días')}) · ${projectStatusLabel(t.status)} · ${t.progress}%${t.assignees ? ' · ' + t.assignees : ''}`;

// Simple view's small bar, on the whole project span.
function miniStyle(s, e, color, progress) {
  const total = dayjs(range.value.end).diff(dayjs(range.value.start), 'day') + 1;
  const l = (dayjs(s).diff(dayjs(range.value.start), 'day') / total) * 100;
  const w = (days(s, e) / total) * 100;
  return { left: `${l}%`, width: `${Math.max(1, w)}%`, background: progress === undefined ? color : `linear-gradient(90deg, ${color} ${progress}%, ${color}55 ${progress}%)` };
}

// Milestones line: over the whole span with a margin so the cards don't touch the ends.
const msPos = d => 6 + (dayjs(d).diff(dayjs(range.value.start), 'day') / Math.max(1, dayjs(range.value.end).diff(dayjs(range.value.start), 'day'))) * 88;
const msToday = computed(() => (today >= range.value.start && today <= range.value.end ? msPos(today) : null));

const sheet = ref(null);
function print() {
  const el = sheet.value;
  if (!el) return;
  const w = window.open('', '_blank');
  if (!w) return;
  const styles = [...document.querySelectorAll('style, link[rel="stylesheet"]')].map(n => n.outerHTML).join('');
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${props.project.title || ''}</title>${styles}
    <style>body{background:#fff;color:#111;padding:16px;-webkit-print-color-adjust:exact;print-color-adjust:exact}@page{size:landscape;margin:10mm}.print-title{display:block!important}</style>
    </head><body><div class="tl">${el.outerHTML}</div></body></html>`);
  w.document.close();
  setTimeout(() => { w.focus(); w.print(); }, 400);
}
</script>

<style scoped>
.bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
  margin-bottom: 12px;
}
.grow {
  flex: 1;
}
.help {
  max-width: 320px;
  font-size: 12.5px;
}
.print-title {
  display: none;
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 10px;
}
.muted {
  opacity: 0.6;
}
.small {
  font-size: 12px;
}
.dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  margin-inline-end: 6px;
}

/* Simple */
.simple {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.simple th {
  text-align: left;
  font-weight: 600;
  font-size: 12px;
  opacity: 0.7;
  padding: 6px 8px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.25);
}
.simple td {
  padding: 5px 8px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.1);
  vertical-align: middle;
}
.simple .d {
  white-space: nowrap;
  width: 105px;
}
.simple .n {
  text-align: right;
  width: 55px;
}
.simple .late {
  color: #dc2626;
}
.simple .bar-col {
  width: 32%;
  min-width: 160px;
}
.simple th.bar-col {
  display: flex;
  justify-content: space-between;
  font-weight: 400;
}
.ph-row td {
  font-weight: 600;
  background: rgba(128, 128, 128, 0.07);
}
.t-row {
  cursor: pointer;
}
.t-row:hover td {
  background: rgba(128, 128, 128, 0.05);
}
.indent {
  padding-inline-start: 24px !important;
}
.tag {
  margin: 0;
}
.mini {
  position: relative;
  height: 10px;
  border-radius: 4px;
  background: rgba(128, 128, 128, 0.1);
}
.mini i {
  position: absolute;
  top: 0;
  bottom: 0;
  border-radius: 4px;
}

/* Gantt */
.gantt {
  --label: 230px;
  font-size: 12.5px;
}
.g-head,
.g-row,
.g-grid {
  display: flex;
}
.g-label {
  flex: 0 0 var(--label);
  padding: 0 8px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.g-scale,
.g-track,
.g-lines {
  position: relative;
  flex: 1;
}
.g-head {
  height: 26px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.25);
}
.tick {
  position: absolute;
  top: 5px;
  font-size: 11px;
  opacity: 0.65;
  white-space: nowrap;
  overflow: hidden;
  padding-inline-start: 3px;
}
.tick.minor {
  color: transparent;
}
.g-body {
  position: relative;
}
.g-grid {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.g-lines i {
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 1px solid rgba(128, 128, 128, 0.12);
}
.today {
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 2px solid #dc2626;
  z-index: 2;
}
.today span {
  position: absolute;
  top: -2px;
  left: 3px;
  font-size: 10px;
  font-weight: 600;
  color: #dc2626;
}
.g-row {
  height: 28px;
  align-items: center;
  border-bottom: 1px solid rgba(128, 128, 128, 0.07);
}
.g-row:not(.ph) {
  cursor: pointer;
}
.g-row:not(.ph):hover {
  background: rgba(128, 128, 128, 0.05);
}
.g-row.ph {
  font-weight: 600;
  background: rgba(128, 128, 128, 0.06);
}
.g-track {
  height: 100%;
}
.g-phase {
  position: absolute;
  top: 11px;
  height: 6px;
  border-radius: 3px;
  background: var(--c);
  opacity: 0.45;
}
.g-bar {
  position: absolute;
  top: 5px;
  height: 18px;
  border-radius: 4px;
  background: color-mix(in srgb, var(--c) 30%, transparent);
  border: 1px solid var(--c);
  overflow: hidden;
}
.g-bar .fill {
  position: absolute;
  inset: 0 auto 0 0;
  background: var(--c);
  opacity: 0.85;
}
.g-bar .g-txt {
  position: relative;
  padding: 0 5px;
  font-size: 11px;
  line-height: 16px;
  white-space: nowrap;
  color: #fff;
  text-shadow: 0 0 2px rgba(0, 0, 0, 0.55);
}
.g-bar.late {
  box-shadow: 0 0 0 2px #dc2626;
}
.g-bar.cancelled {
  opacity: 0.4;
  text-decoration: line-through;
}
.diamond {
  position: absolute;
  top: 7px;
  width: 13px;
  height: 13px;
  margin-left: -6px;
  transform: rotate(45deg);
  background: #9333ea;
  border: 2px solid #fff;
  z-index: 3;
}
.diamond.done {
  background: #16a34a;
}
.diamond.late {
  background: #dc2626;
}
.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 14px;
  margin-top: 10px;
  font-size: 12px;
  opacity: 0.8;
}
.sw {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 2px;
  margin-inline-end: 4px;
  vertical-align: -1px;
}
.sw.dia {
  background: #9333ea;
  transform: rotate(45deg) scale(0.85);
}
.sw.late {
  border: 2px solid #dc2626;
}

/* Milestones line */
.ms-line {
  position: relative;
  height: 330px;
  margin: 8px 0;
}
.ms-axis {
  position: absolute;
  left: 2%;
  right: 2%;
  top: 50%;
  height: 4px;
  margin-top: -2px;
  border-radius: 2px;
  background: rgba(128, 128, 128, 0.35);
}
.ms-line .today {
  top: 30%;
  bottom: 30%;
}
.ms-end {
  position: absolute;
  top: calc(50% + 10px);
  font-size: 11.5px;
  opacity: 0.7;
  display: flex;
  flex-direction: column;
}
.ms-end.start {
  left: 2%;
}
.ms-end.end {
  right: 2%;
  text-align: right;
}
.ms-end small {
  font-size: 10px;
  text-transform: uppercase;
  opacity: 0.8;
}
.ms-item {
  position: absolute;
  top: 50%;
  width: 0;
}
.ms-dia {
  position: absolute;
  left: -8px;
  top: -8px;
  width: 16px;
  height: 16px;
  transform: rotate(45deg);
  background: #9333ea;
  border: 2px solid #fff;
  z-index: 2;
}
.ms-item.done .ms-dia {
  background: #16a34a;
}
.ms-item.late .ms-dia {
  background: #dc2626;
}
.ms-stem {
  position: absolute;
  left: -1px;
  width: 2px;
  height: 46px;
  background: rgba(128, 128, 128, 0.4);
}
.ms-item.up .ms-stem {
  bottom: 0;
}
.ms-item.down .ms-stem {
  top: 0;
}
.ms-card {
  position: absolute;
  left: -70px;
  width: 140px;
  padding: 6px 8px;
  border-radius: 6px;
  border-top: 3px solid;
  background: rgba(128, 128, 128, 0.08);
  font-size: 12px;
}
.ms-item.up .ms-card {
  bottom: 46px;
}
.ms-item.down .ms-card {
  top: 46px;
}
.ms-date {
  font-weight: 600;
}
.ms-title {
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.ms-state {
  font-size: 11px;
  opacity: 0.7;
}
.ms-item.late .ms-state {
  color: #dc2626;
  opacity: 1;
}

/* Phases */
.ph-band {
  display: flex;
  gap: 3px;
}
.ph-seg {
  flex: 1 1 0;
  min-width: 0;
  padding: 10px 12px;
  color: #fff;
  clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%, 12px 50%);
  padding-inline: 22px 18px;
}
.ph-seg:first-child {
  clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%);
  border-radius: 6px 0 0 6px;
}
.ph-num {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.85;
}
.ph-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ph-dates {
  font-size: 11.5px;
  opacity: 0.9;
}
.ph-cols {
  display: flex;
  gap: 3px;
  margin-top: 8px;
}
.ph-col {
  flex: 1 1 0;
  min-width: 0;
  padding: 4px 6px;
}
.ph-prog {
  margin-bottom: 4px;
}
.ph-task {
  display: flex;
  gap: 6px;
  align-items: baseline;
  padding: 3px 0;
  font-size: 12.5px;
  cursor: pointer;
}
.ph-task span {
  overflow: hidden;
  text-overflow: ellipsis;
}
.ph-task.done span {
  opacity: 0.55;
  text-decoration: line-through;
}
.ph-task.late {
  color: #dc2626;
}
.ok {
  color: #16a34a;
}
@media (max-width: 760px) {
  .gantt {
    --label: 120px;
  }
  .ph-band,
  .ph-cols {
    flex-direction: column;
  }
}
</style>
