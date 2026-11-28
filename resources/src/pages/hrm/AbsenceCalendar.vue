<template>
  <div class="page ac-page">
    <!-- Absence calendar (2026-11-28, from HTD-IT): who is out each day -- approved and pending leaves, absences, holidays. -->
    <div class="ac-bar no-print">
      <div class="ac-nav">
        <a-button @click="shift(-1)"><LeftOutlined /></a-button>
        <div class="ac-month">{{ monthTitle }}</div>
        <a-button @click="shift(1)"><RightOutlined /></a-button>
        <a-button v-if="month !== thisMonth" type="primary" ghost @click="setMonth(thisMonth)">{{ L('This month', 'Este mes') }}</a-button>
      </div>
      <a-select v-model:value="group" :options="groupOptions" style="min-width: 200px" />
      <div class="ac-legend">
        <span v-for="t in legend" :key="t.key"><i :style="{ background: t.color }" /> {{ t.label }}</span>
        <span><i class="pend" /> {{ L('Pending request', 'Solicitud pendiente') }}</span>
        <span><i class="hol" /> {{ L('Holiday', 'Feriado') }}</span>
      </div>
      <a-button @click="printIt"><PrinterOutlined /> {{ L('Print', 'Imprimir') }}</a-button>
    </div>
    <h2 class="print-only">{{ L('Absence calendar', 'Calendario de ausencias') }} · {{ monthTitle }}</h2>

    <a-spin :spinning="loading">
      <div class="ac-body">
        <div class="ac-grid">
          <div v-for="n in weekOrder" :key="'h' + n" class="ac-dow">{{ dowName(n) }}</div>
          <div v-for="c in cells" :key="c.date" class="ac-cell"
               :class="{ out: !c.inMonth, off: !c.workday, today: c.date === data?.today, hol: c.holiday }">
            <div class="ac-num">
              <span>{{ Number(c.date.slice(8, 10)) }}</span>
              <a-tooltip v-if="c.taken" :title="L('Attendance taken', 'Asistencia tomada')"><CheckCircleFilled class="ac-taken" /></a-tooltip>
            </div>
            <div v-if="c.holiday" class="ac-holiday">{{ c.holiday }}</div>
            <a-tooltip v-for="it in c.items.slice(0, MAX)" :key="it.kind + it.employee_id" :title="itemTitle(it)">
              <div class="ac-item" :class="it.kind" :style="itemStyle(it)">{{ it.name }}<span v-if="it.half_day"> ½</span></div>
            </a-tooltip>
            <a-popover v-if="c.items.length > MAX" trigger="click" :title="longDate(c.date)">
              <template #content>
                <div v-for="it in c.items" :key="'p' + it.kind + it.employee_id" class="ac-item" :class="it.kind" :style="itemStyle(it)">{{ itemTitle(it) }}</div>
              </template>
              <a class="ac-more">+{{ c.items.length - MAX }} {{ L('more', 'más') }}</a>
            </a-popover>
          </div>
        </div>

        <div class="ac-side no-print">
          <div class="ac-side-t">{{ L('Pending requests', 'Solicitudes pendientes') }} ({{ pending.length }})</div>
          <div v-if="!pending.length" class="ac-muted">{{ L('None.', 'Ninguna.') }}</div>
          <div v-for="p in pending" :key="p.id" class="ac-pend">
            <b>{{ p.name }}</b>
            <div :style="{ color: color(p.leave_type_id) }">{{ typeName(p.leave_type_id) }}</div>
            <div class="ac-muted">{{ date(p.start) }}<template v-if="p.end !== p.start"> → {{ date(p.end) }}</template> · {{ p.days }} {{ L('days', 'días') }}</div>
          </div>
          <PermLink v-if="pending.length" to="/hrm/leaves" permission="leave">{{ L('Review in Leave requests', 'Revisar en Solicitudes de permiso') }}</PermLink>
        </div>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Absence calendar (hrm/absence-calendar, AttendanceSheetController::calendar): a month, Monday first, each day with its
 * holiday and the people out -- approved leaves (planned ahead), pending requests (dashed) and the daily sheet's
 * absences -- coloured by leave type; a filter by agency / department; print = the browser's, the grid only.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { CheckCircleFilled, LeftOutlined, PrinterOutlined, RightOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { ABSENT_COLOR, leaveTypeColor, leaveTypeLabel } from '../../lib/leaveTypes';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const L = (en, sp) => (es.value ? sp : en);
useBarTitle(() => L('Absence calendar', 'Calendario de ausencias'));
const { date } = useFormat();
const MAX = 4;
const thisMonth = dayjs().format('YYYY-MM');
const month = ref(thisMonth);
const group = ref('');
const data = ref(null);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('hrm/absence-calendar', { month: month.value });
    if (group.value && !data.value.groups.some(g => g.key === group.value)) group.value = '';
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}
const setMonth = m => { month.value = m; };
const shift = n => setMonth(dayjs(month.value + '-01').add(n, 'month').format('YYYY-MM'));
watch(month, load);

const groupName = g => g.label || (data.value?.group_by === 'location' ? L('Head office', 'Oficina central') : L('No department', 'Sin departamento'));
// The server's order (agencies along the line, then the head office), "All" first.
const groupOptions = computed(() => [{ value: '', label: data.value?.group_by === 'location' ? L('All agencies', 'Todas las agencias') : L('All departments', 'Todos los departamentos') },
  ...(data.value?.groups || []).map(g => ({ value: g.key, label: groupName(g) }))]);

const types = computed(() => data.value?.leave_types || []);
const typeName = id => (id ? leaveTypeLabel(types.value.find(t => t.id === id)?.title, es.value) : L('Absence (no leave)', 'Falta (sin permiso)'));
const color = id => leaveTypeColor(types.value, id);
const legend = computed(() => [...types.value.map(t => ({ key: t.id, label: leaveTypeLabel(t.title, es.value), color: color(t.id) })),
  { key: 'abs', label: L('Absence (no leave)', 'Falta (sin permiso)'), color: ABSENT_COLOR }]);
const itemStyle = it => (it.kind === 'pending' ? { borderColor: color(it.leave_type_id), color: color(it.leave_type_id) } : { background: color(it.leave_type_id) });
const itemTitle = it => `${it.name} · ${typeName(it.leave_type_id)}${it.half_day ? ' · ' + L('half day', 'medio día') : ''}${it.kind === 'pending' ? ' · ' + L('pending', 'pendiente') : ''}${it.reason ? ' — ' + it.reason : ''}`;

const weekOrder = [1, 2, 3, 4, 5, 6, 0];
const DOW = { en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], es: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] };
const MONTHS = { en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
  es: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'] };
const lg = () => (es.value ? 'es' : 'en');
const dowName = n => DOW[lg()][n];
const monthTitle = computed(() => { const d = dayjs(month.value + '-01'); return `${MONTHS[lg()][d.month()]} ${d.year()}`; });
const longDate = d => `${DOW[lg()][dayjs(d).day()]} ${date(d)}`;

const cells = computed(() => {
  const first = dayjs(month.value + '-01');
  const start = first.subtract((first.day() + 6) % 7, 'day');
  const last = first.endOf('month');
  const end = last.add((7 - last.day()) % 7, 'day');
  const out = [];
  const days = data.value?.days || {};
  const work = data.value?.workdays || [1, 2, 3, 4, 5];
  for (let d = start; !d.isAfter(end, 'day'); d = d.add(1, 'day')) {
    const k = d.format('YYYY-MM-DD');
    const inMonth = d.month() === first.month();
    const day = inMonth ? days[k] : null;
    out.push({
      date: k, inMonth, workday: work.includes(d.day()), holiday: day?.holiday || null, taken: !!day?.taken,
      items: (day?.items || []).filter(it => !group.value || it.group === group.value),
    });
  }
  return out;
});
const pending = computed(() => data.value?.pending || []);
const printIt = () => window.print();

onMounted(load);
</script>

<style scoped>
.ac-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
.ac-nav { display: flex; align-items: center; gap: 6px; }
.ac-month { font-size: 20px; font-weight: 800; min-width: 170px; text-align: center; }
.ac-legend { display: flex; flex-wrap: wrap; gap: 10px; font-size: 12px; color: #4b5563; flex: 1; }
.ac-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; vertical-align: -1px; }
.ac-legend i.pend { border: 1.5px dashed #6b7280; }
.ac-legend i.hol { background: #fef3c7; border: 1px solid #f59e0b; }
.ac-body { display: grid; grid-template-columns: 1fr 230px; gap: 12px; }
.ac-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; background: #e5e7eb; gap: 1px; }
.ac-dow { background: #f3f4f6; text-align: center; font-size: 12px; font-weight: 700; padding: 4px 0; }
.ac-cell { background: #fff; min-height: calc((100vh - 230px) / 6); padding: 3px 4px; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.ac-cell.out { background: #f9fafb; }
.ac-cell.off:not(.out) { background: #fafafa; }
.ac-cell.hol { background: #fffbeb; }
.ac-cell.today { box-shadow: inset 0 0 0 2px var(--ant-color-primary, #1677ff); }
.ac-num { display: flex; justify-content: space-between; font-size: 12px; font-weight: 700; color: #374151; }
.ac-cell.out .ac-num { color: #d1d5db; }
.ac-taken { color: #10b981; font-size: 11px; }
.ac-holiday { font-size: 10px; font-weight: 700; color: #b45309; }
.ac-item { font-size: 11px; color: #fff; border-radius: 4px; padding: 0 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border: 1.5px solid transparent; line-height: 17px; margin-bottom: 2px; }
.ac-item.pending { background: #fff !important; border-style: dashed; }
.ac-more { font-size: 11px; }
.ac-side { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; background: #fff; align-self: start; max-height: calc(100vh - 170px); overflow-y: auto; }
.ac-side-t { font-weight: 700; margin-bottom: 6px; }
.ac-pend { border-bottom: 1px solid #f3f4f6; padding: 6px 0; font-size: 12px; }
.ac-muted { color: #6b7280; font-size: 12px; }
.print-only { display: none; }
@media (max-width: 900px) {
  .ac-body { grid-template-columns: 1fr; }
  .ac-cell { min-height: 70px; }
  .ac-item { font-size: 9px; padding: 0 2px; }
}
@media print {
  .no-print { display: none !important; }
  .print-only { display: block; }
  .ac-body { grid-template-columns: 1fr; }
  .ac-cell { min-height: 90px; }
  .ac-item { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
