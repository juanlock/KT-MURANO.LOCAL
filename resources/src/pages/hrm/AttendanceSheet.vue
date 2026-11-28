<template>
  <div class="page as-page">
    <!-- Daily attendance (2026-11-28, from HTD-IT): everyone of the day on one screen; a tap marks an absence. -->
    <div class="as-bar">
      <div class="as-jump">
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" :disabled-date="d => d.format('YYYY-MM-DD') > today" @change="load()" />
        <a-button v-if="date !== today" type="primary" ghost @click="go(today)"><ThunderboltOutlined /> {{ L('Today', 'Hoy') }}</a-button>
      </div>
      <div class="as-day">{{ bigDate }}</div>
      <div v-if="data" class="as-strip">
        <a-button size="small" class="as-arrow" @click="shiftStrip(-1)"><LeftOutlined /></a-button>
        <a-tooltip v-for="s in data.strip" :key="s.date" :title="stripTitle(s)">
          <button type="button" class="as-sday" :class="['st-' + s.state, { cur: s.date === data.date }]" @click="go(s.date, stripEnd)">
            <span>{{ dow(s.dow) }}</span><b>{{ s.date.slice(8, 10) }}</b><span class="m">{{ mon(s.date) }}</span>
          </button>
        </a-tooltip>
        <a-button v-if="stripEnd < today" size="small" class="as-arrow" @click="shiftStrip(1)"><RightOutlined /></a-button>
      </div>
    </div>

    <a-spin :spinning="loading">
      <template v-if="data">
        <div class="as-tools">
          <div class="as-stats">
            <span><TeamOutlined /> <b>{{ stats.total }}</b> {{ L('employees', 'empleados') }}</span>
            <span class="ok"><b>{{ stats.present }}</b> {{ L('present', 'presentes') }}</span>
            <span class="ko"><b>{{ stats.absent }}</b> {{ L('absent', 'ausentes') }}</span>
            <a-tag v-if="data.taken" color="green">{{ L('Saved', 'Guardada') }} · {{ data.taken.by }} · {{ dateTime(data.taken.at) }}</a-tag>
            <a-tag v-else color="blue">{{ L('Not taken yet', 'Aún no tomada') }}</a-tag>
          </div>
          <a-input v-model:value="search" allow-clear class="as-search" :placeholder="L('Search employee…', 'Buscar empleado…')"><template #prefix><SearchOutlined /></template></a-input>
          <div class="as-actions">
            <a-button v-if="data.taken" @click="openPdf"><FilePdfOutlined /> {{ L('Print', 'Imprimir') }}</a-button>
            <a-button v-if="data.taken" danger @click="absentOpen = true"><UserDeleteOutlined /> {{ L('Absent', 'Ausentes') }} ({{ stats.absent }})</a-button>
            <a-button v-if="dirty" @click="load()">{{ L('Cancel', 'Cancelar') }}</a-button>
            <a-button type="primary" :loading="saving" :disabled="blocked || (data.taken && !dirty)" @click="save">
              <CheckOutlined /> {{ data.taken ? L('Update attendance', 'Actualizar asistencia') : L('Save attendance', 'Guardar asistencia') }}
            </a-button>
          </div>
        </div>

        <div v-if="data.holiday" class="as-note hol"><CoffeeOutlined /> {{ L('Holiday', 'Feriado') }}: <b>{{ data.holiday }}</b> — {{ L('no attendance is taken.', 'no se toma asistencia.') }}</div>
        <div v-else-if="data.future" class="as-note">{{ L('This day has not come yet.', 'Este día aún no llega.') }}</div>
        <div v-else-if="!stats.total" class="as-note">{{ L('No employees for the sheet.', 'No hay empleados para la hoja.') }}</div>
        <template v-else>
          <div v-if="!data.is_workday" class="as-note warn">{{ L('Not a working day of the company: save only if someone worked.', 'No es día laborable de la compañía: guarda solo si alguien trabajó.') }}</div>
          <div class="as-groups">
            <button v-for="g in shownGroups" :key="g.key" type="button" class="as-gcard" :class="{ open: openGroup === g.key || search }" @click="openGroup = openGroup === g.key ? null : g.key">
              <div class="t"><ShopOutlined /> <b>{{ groupName(g) }}</b><DownOutlined class="chev" /></div>
              <div class="n">{{ g.employees.length }} {{ L('employees', 'empleados') }}</div>
              <a-tag v-if="absentIn(g)" color="red">{{ absentIn(g) }} {{ L('absent', 'ausentes') }}</a-tag>
              <a-tag v-else color="green">{{ L('All present', 'Todos presentes') }}</a-tag>
            </button>
          </div>
          <template v-for="g in shownGroups" :key="'b' + g.key">
            <div v-if="openGroup === g.key || search" class="as-tiles">
              <div class="as-gname">{{ groupName(g) }}</div>
              <div v-for="e in matching(g)" :key="e.id" class="as-wrap">
                <button type="button" class="as-tile" :class="[e.status, { locked: e.locked }]" @click="toggle(e)">
                  <a-avatar :size="40" :src="e.photo || undefined">{{ initials(e.name) }}</a-avatar>
                  <span class="nm">{{ e.name }}</span>
                  <span class="st">{{ e.status === 'present' ? L('Present', 'Presente') : L('Absent', 'Ausente') }}</span>
                  <span v-if="e.locked === 'clock'" class="lk"><ClockCircleOutlined /> {{ e.clock_in }}</span>
                  <span v-else-if="e.leave" class="lk">{{ L('Approved leave', 'Permiso aprobado') }}</span>
                </button>
                <div v-if="e.status === 'absent'" class="as-abs">
                  <a-select v-model:value="e.leave_type_id" size="small" :options="typeOptions" style="width: 100%" @change="dirty = true" />
                  <a-checkbox v-model:checked="e.half_day" @change="dirty = true">{{ L('Half day', 'Medio día') }}</a-checkbox>
                  <a-input v-model:value="e.reason" size="small" :placeholder="L('Reason', 'Motivo')" @change="dirty = true" />
                </div>
              </div>
            </div>
          </template>
        </template>
      </template>
    </a-spin>

    <a-modal v-model:open="absentOpen" :title="L('Absent', 'Ausentes') + ' — ' + bigDate" :footer="null" width="680px">
      <a-table :data-source="absentRows" :columns="absentCols" :pagination="false" size="small" row-key="id">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'type'">
            <span :style="{ color: color(record.leave_type_id) }">{{ typeName(record.leave_type_id) }}</span><span v-if="record.half_day"> · {{ L('half day', 'medio día') }}</span>
            <div v-if="record.reason" class="as-muted">{{ record.reason }}</div>
          </template>
          <template v-else-if="column.key === 'act'">
            <a-popconfirm :title="L('Mark as present on this day?', '¿Marcar presente en este día?')" @confirm="markPresent(record)">
              <a-button size="small"><UserAddOutlined /> {{ L('Present', 'Presente') }}</a-button>
            </a-popconfirm>
          </template>
        </template>
      </a-table>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Daily attendance (hrm/attendance-sheet, AttendanceSheetController): the day's employees by agency / department, all
 * present by default; a tap on a tile marks an absence (type, half day, reason). Save writes the whole day and goes on to
 * the next workday still to take. The strip shows the last 10 workdays: green taken, red skipped, blue pending, grey holiday.
 */
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  CheckOutlined, ClockCircleOutlined, CoffeeOutlined, DownOutlined, FilePdfOutlined, LeftOutlined, RightOutlined, SearchOutlined,
  ShopOutlined, TeamOutlined, ThunderboltOutlined, UserAddOutlined, UserDeleteOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { getActingCompanyId } from '../../lib/actingCompany';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { sortByLabel } from '../../lib/filterOptions';
import { leaveTypeColor, leaveTypeLabel } from '../../lib/leaveTypes';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const L = (en, sp) => (es.value ? sp : en);
useBarTitle(() => L('Daily attendance', 'Asistencia del día'));
const { dateTime } = useFormat();
const route = useRoute();
const router = useRouter();
const today = dayjs().format('YYYY-MM-DD');
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const date = ref(typeof route.query.date === 'string' ? route.query.date : today);
const stripEnd = ref(date.value);
const data = ref(null);
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const search = ref('');
const openGroup = ref(null);
const absentOpen = ref(false);

async function load(end) {
  loading.value = true;
  try {
    const d = await http.get('hrm/attendance-sheet', { date: date.value, end: end || date.value });
    // 0 = "Falta (sin permiso)" in the select (a null value would show the placeholder); sent back as no type.
    d.groups.forEach(g => g.employees.forEach(e => { if (e.leave_type_id == null) e.leave_type_id = 0; }));
    data.value = d;
    stripEnd.value = data.value.strip.length ? data.value.strip[data.value.strip.length - 1].date : date.value;
    dirty.value = false;
    if (!data.value.groups.some(g => g.key === openGroup.value)) openGroup.value = data.value.groups.length === 1 ? data.value.groups[0].key : null;
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}
function go(d, end) {
  date.value = d;
  router.replace({ query: { ...route.query, date: d } });
  load(end);
}
function shiftStrip(dir) {
  const s = data.value.strip;
  const end = dir < 0 ? dayjs(s[0].date).subtract(1, 'day') : dayjs(s[s.length - 1].date).add(14, 'day');
  load(end.format('YYYY-MM-DD') > today ? today : end.format('YYYY-MM-DD'));
}

const blocked = computed(() => !data.value || !!data.value.holiday || data.value.future || !data.value.groups.length);
const all = computed(() => (data.value?.groups || []).flatMap(g => g.employees));
const stats = computed(() => {
  const a = all.value.filter(e => e.status === 'absent').length;
  return { total: all.value.length, present: all.value.length - a, absent: a };
});
const term = computed(() => search.value.trim().toLowerCase());
const matching = g => (term.value ? g.employees.filter(e => e.name.toLowerCase().includes(term.value)) : g.employees);
const shownGroups = computed(() => (data.value?.groups || []).filter(g => !term.value || matching(g).length));
const absentIn = g => g.employees.filter(e => e.status === 'absent').length;
const groupName = g => g.label || (data.value?.group_by === 'location' ? L('Head office', 'Oficina central') : L('No department', 'Sin departamento'));
const initials = n => n.split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase();

const types = computed(() => data.value?.leave_types || []);
const typeName = id => (id ? leaveTypeLabel(types.value.find(t => t.id === id)?.title, es.value) : L('Absence (no leave)', 'Falta (sin permiso)'));
const color = id => leaveTypeColor(types.value, id);
// "Falta" first (the usual case), then the leave types alphabetically.
const typeOptions = computed(() => [{ value: 0, label: L('Absence (no leave)', 'Falta (sin permiso)') },
  ...sortByLabel(types.value.map(t => ({ value: t.id, label: leaveTypeLabel(t.title, es.value) })))]);

function toggle(e) {
  if (e.locked === 'clock') {
    message.info(L('Clocked in at ', 'Marcó entrada a las ') + e.clock_in);
    return;
  }
  if (blocked.value) return;
  if (e.status === 'present') {
    e.status = 'absent';
    e.leave_type_id = e.default_leave_type_id ?? 0;
  } else {
    e.status = 'present';
    e.leave_type_id = 0;
    e.half_day = false;
    e.reason = '';
  }
  dirty.value = true;
}

async function save() {
  saving.value = true;
  try {
    const rows = all.value.filter(e => e.locked !== 'clock').map(e => ({
      employee_id: e.id, present: e.status === 'present', leave_type_id: e.leave_type_id || null, half_day: !!e.half_day, reason: e.reason || '',
    }));
    const res = await http.post('hrm/attendance-sheet', { date: date.value, rows });
    message.success(res.next
      ? L('Saved. Now the next day to take: ', 'Guardada. Ahora el siguiente día por tomar: ') + longDate(res.next)
      : L('Saved. Every working day up to today is taken.', 'Guardada. Todos los días laborables hasta hoy están tomados.'), 4);
    if (res.next) go(res.next); else load();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}

async function markPresent(r) {
  try {
    await http.post('hrm/attendance-sheet/present', { date: date.value, employee_id: r.id });
    message.success(r.name + ': ' + L('present', 'presente'));
    await load(stripEnd.value);
  } catch (e) {
    message.error(errorOf(e));
  }
}

async function openPdf() {
  try {
    const cid = getActingCompanyId();
    const b = await http.blob(`hrm/attendance-sheet/pdf?date=${date.value}${cid ? `&company_id=${cid}` : ''}`);
    window.open(URL.createObjectURL(b), '_blank');
  } catch (e) {
    message.error(L('The PDF could not be opened.', 'No se pudo abrir el PDF.'));
  }
}

const absentRows = computed(() => (data.value?.groups || []).flatMap(g => g.employees.filter(e => e.status === 'absent').map(e => ({ ...e, group: groupName(g) }))));
const absentCols = computed(() => [
  { title: data.value?.group_by === 'location' ? L('Agency', 'Agencia') : L('Department', 'Departamento'), dataIndex: 'group', key: 'group' },
  { title: L('Name', 'Nombre'), dataIndex: 'name', key: 'name' },
  { title: L('Type / reason', 'Tipo / motivo'), key: 'type' },
  { title: '', key: 'act', width: 110, align: 'right' },
]);

const DOW = { en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], es: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] };
const MON = { en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], es: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Set', 'Oct', 'Nov', 'Dic'] };
const DAYS = { en: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], es: ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'] };
const MONTHS = { en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
  es: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'] };
const lg = () => (es.value ? 'es' : 'en');
const dow = n => DOW[lg()][n];
const mon = d => MON[lg()][Number(d.slice(5, 7)) - 1];
function longDate(d) {
  const x = dayjs(d);
  return es.value ? `${DAYS.es[x.day()]} ${x.date()} de ${MONTHS.es[x.month()]} ${x.year()}` : `${DAYS.en[x.day()]}, ${MONTHS.en[x.month()]} ${x.date()} ${x.year()}`;
}
const bigDate = computed(() => longDate(date.value));
function stripTitle(s) {
  const st = { taken: L('Attendance taken', 'Asistencia tomada'), gap: L('Skipped: a later day was taken', 'Saltado: se tomó un día posterior'),
    pending: L('Pending', 'Pendiente'), holiday: L('Holiday', 'Feriado') + (s.holiday ? ': ' + s.holiday : '') }[s.state];
  return longDate(s.date) + ' — ' + st;
}

onMounted(() => load());
</script>

<style scoped>
.as-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; background: var(--ant-color-bg-container, #fff); border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; }
.as-jump { display: flex; gap: 8px; align-items: center; }
.as-day { font-size: 22px; font-weight: 800; letter-spacing: -0.3px; }
.as-strip { display: flex; align-items: center; gap: 4px; }
.as-arrow { height: 42px; }
.as-sday { width: 38px; height: 42px; border: 0; border-radius: 7px; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 10px; line-height: 1.1; cursor: pointer; }
.as-sday b { font-size: 13px; }
.as-sday .m { font-size: 8px; text-transform: uppercase; opacity: .9; }
.as-sday.st-taken { background: #10b981; }
.as-sday.st-gap { background: #f43f5e; }
.as-sday.st-pending { background: #2563eb; }
.as-sday.st-holiday { background: #94a3b8; }
.as-sday.cur { outline: 2px solid #111827; outline-offset: 1px; }
.as-tools { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 10px 0; }
.as-stats { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; font-size: 13px; }
.as-stats .ok { color: #047857; }
.as-stats .ko { color: #b91c1c; }
.as-search { flex: 1; min-width: 200px; max-width: 360px; }
.as-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
.as-note { padding: 14px; border-radius: 8px; background: #f8fafc; border: 1px solid #e5e7eb; text-align: center; margin-bottom: 10px; }
.as-note.hol { background: #fffbeb; border-color: #fde68a; }
.as-note.warn { background: #fff7ed; border-color: #fed7aa; padding: 6px; text-align: left; }
.as-groups { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 2px; }
.as-gcard { flex: 1; min-width: 160px; text-align: left; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; cursor: pointer; display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.as-gcard:hover, .as-gcard.open { border-color: var(--ant-color-primary, #1677ff); box-shadow: 0 2px 6px rgba(0, 0, 0, .06); }
.as-gcard .t { display: flex; align-items: center; gap: 6px; width: 100%; }
.as-gcard .chev { margin-left: auto; font-size: 10px; color: #9ca3af; transition: transform .2s; }
.as-gcard.open .chev { transform: rotate(180deg); }
.as-gcard .n { font-size: 12px; color: #6b7280; }
.as-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin-top: 10px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; }
.as-gname { grid-column: 1 / -1; font-weight: 700; font-size: 12px; color: #374151; }
.as-tile { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 8px 6px; border-radius: 8px; border: 2px solid; cursor: pointer; background: #fff; }
.as-tile.present { border-color: #6ee7b7; background: #ecfdf5; }
.as-tile.absent { border-color: #fb7185; background: #fff1f2; }
.as-tile.locked { cursor: default; opacity: .9; }
.as-tile .nm { font-size: 12px; font-weight: 600; text-align: center; line-height: 1.2; }
.as-tile .st { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
.as-tile.present .st { color: #047857; }
.as-tile.absent .st { color: #be123c; }
.as-tile .lk { font-size: 10px; color: #6b7280; }
.as-abs { margin-top: 4px; display: flex; flex-direction: column; gap: 4px; padding: 6px; border: 1px solid #fecdd3; border-radius: 6px; background: #fff; }
.as-muted { color: #6b7280; font-size: 12px; }
@media (max-width: 700px) {
  .as-day { font-size: 18px; width: 100%; order: -1; }
  .as-strip { overflow-x: auto; max-width: 100%; }
  .as-actions { margin-left: 0; }
}
</style>
