<template>
  <div class="sch">
    <div class="sch-bar">
      <a-select v-model:value="planId" class="sch-plan" :options="planOptions" :placeholder="L('Pick a plan', 'Elija un plan')" @change="load" />
      <a-button type="primary" @click="openNew"><PlusOutlined /> {{ L('New plan', 'Nuevo plan') }}</a-button>
      <a-button @click="imp.open = true"><UploadOutlined /> {{ L('Import Excel', 'Importar Excel') }}</a-button>
      <template v-if="data">
        <a-button :loading="busy === 'export'" @click="exportPlan"><FileExcelOutlined /> {{ L('Export', 'Exportar') }}</a-button>
        <a-button @click="openEdit"><EditOutlined /></a-button>
        <a-popconfirm :title="L('Remove this plan?', '¿Quitar este plan?')" @confirm="removePlan"><a-button danger><DeleteOutlined /></a-button></a-popconfirm>
        <span class="sch-info">
          <b>{{ data.plan.kind === 'base' ? L('Base timetable', 'Horario base') : `${date(data.plan.date_from)} – ${date(data.plan.date_to)}` }}</b>
          <span v-if="data.plan.buses"> · {{ L('planned on', 'en base de') }} {{ data.plan.buses }} {{ L('buses', 'buses') }}</span>
          <span v-if="totalAlerts" class="sch-red"> · {{ totalAlerts }} {{ L('warnings', 'alertas') }}</span>
          <a-tooltip v-if="data.plan.notes || data.plan.source_file" placement="bottomRight">
            <template #title>
              <div v-if="data.plan.source_file">{{ L('From', 'De') }}: {{ data.plan.source_file }}</div>
              <div v-for="(n, i) in noteLines" :key="i">{{ n }}</div>
            </template>
            <InfoCircleOutlined class="sch-i" />
          </a-tooltip>
        </span>
      </template>
    </div>

    <a-empty v-if="!plans.length && !loading" :description="L('No plan yet: import the base timetable or a season Excel.', 'Aún no hay planes: importe el horario base o el Excel de una temporada.')" />
    <a-spin :spinning="loading">
      <div v-if="data" class="sch-grid" :class="{ wide: data.plan.kind === 'base' }">
        <div v-for="d in data.days" :key="d.key" class="sch-day" :class="{ sun: d.weekday === 7, bad: (data.stats[d.key]?.alerts || []).length }">
          <div class="sch-dh">
            <b>{{ d.label }}</b>
            <span class="sch-cnt">{{ data.stats[d.key]?.lima || 0 }} {{ L('from Lima', 'de Lima') }}<template v-if="sides.includes('chiclayo')"> · {{ data.stats[d.key]?.chiclayo || 0 }} {{ L('from Chiclayo', 'de Chiclayo') }}</template></span>
          </div>
          <div v-for="side in sides" :key="side" class="sch-side">
            <div v-if="sides.length > 1" class="sch-sl">{{ side === 'lima' ? L('Leaves Lima', 'Sale de Lima') : L('Leaves Chiclayo', 'Sale de Chiclayo') }}</div>
            <div class="sch-cols" :style="{ gridTemplateColumns: `repeat(${data.terminals[side].length}, 1fr)` }">
              <div v-for="t in data.terminals[side]" :key="t" class="sch-col">
                <div class="sch-th">{{ t }} <PlusOutlined class="sch-add" @click="openSlot(d, side, t)" /></div>
                <div v-for="s in slotsOf(d.key, side, t)" :key="`${s.id}-${s.ghost ? t : 'm'}`" class="sch-chip" :class="[chipClass(s), { ghost: s.ghost }]"
                     :title="s.ghost ? L(`Same bus as ${hm(s.mainTime)} ${s.mainTerminal}`, `El mismo bus de ${hm(s.mainTime)} en ${s.mainTerminal}`) : ''"
                     @click="openSlot(d, side, s.ghost ? s.mainTerminal : t, s.ghost ? s.main : s)">
                  <span class="sch-time">{{ s.ghost ? '↳ ' : '' }}{{ hm(s.time) }}</span>
                  <span v-if="s.tag && !s.ghost" class="sch-tag">{{ s.tag }}</span>
                  <span v-if="s.bus && !s.ghost" class="sch-bus">{{ s.bus.replace(/^Bus\s*/i, '') }}</span>
                  <span v-if="isTrux(s) && !s.ghost" class="sch-tru">TRU</span>
                </div>
              </div>
            </div>
          </div>
          <div class="sch-foot">
            <table class="sch-pr">
              <tr><th></th><th>{{ L('Semi', 'Semicama') }}</th><th>{{ L('Sofa', 'Sofacama') }}</th></tr>
              <tr v-for="rk in ['CIX', 'TRUX']" :key="rk">
                <td>{{ rk === 'CIX' ? 'Chiclayo' : 'Trujillo' }}</td>
                <td v-for="cls in ['SEMICAMA', 'SOFACAMA']" :key="cls">
                  <input class="sch-in" :value="priceOf(d.key, rk, cls)" @change="e => savePrice(d.key, rk, cls, e.target.value)" />
                </td>
              </tr>
            </table>
            <div class="sch-fleet">
              <span>{{ L('Buses', 'Buses') }}:</span>
              <label>Lima <input class="sch-in sm" :value="data.stats[d.key]?.fleet?.lima || ''" @change="e => saveFleet(d.key, 'lima', e.target.value)" /></label>
              <label>Chiclayo <input class="sch-in sm" :value="data.stats[d.key]?.fleet?.chiclayo || ''" @change="e => saveFleet(d.key, 'chiclayo', e.target.value)" /></label>
            </div>
            <div v-for="(a, i) in data.stats[d.key]?.alerts || []" :key="i" class="sch-alert"><WarningOutlined /> {{ a }}</div>
          </div>
        </div>
      </div>
    </a-spin>

    <!-- A departure -->
    <a-modal v-model:open="slot.open" :title="slot.form.id ? L('Departure', 'Salida') : L('New departure', 'Nueva salida')" :ok-text="L('Save', 'Guardar')"
             :cancel-text="L('Cancel', 'Cancelar')" :confirm-loading="busy === 'slot'" width="480px" @ok="saveSlot">
      <a-form layout="vertical" class="sch-form">
        <div class="sch-day-label">{{ slot.dayLabel }} · {{ slot.form.side === 'lima' ? L('leaves Lima', 'sale de Lima') : L('leaves Chiclayo', 'sale de Chiclayo') }}</div>
        <a-row :gutter="10">
          <a-col :span="12"><a-form-item :label="L('Terminal', 'Terminal')"><a-select v-model:value="slot.form.terminal" :options="terminalOptions" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="L('Time', 'Hora')"><a-time-picker v-model:value="slot.form.time" format="h:mm A" value-format="HH:mm" :minute-step="5" use12-hours style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <a-row :gutter="10">
          <a-col :span="12"><a-form-item :label="L('Route', 'Ruta')"><a-select v-model:value="slot.form.route_id" allow-clear :options="routeOptions" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="L('Service / tag', 'Servicio / marca')"><a-auto-complete v-model:value="slot.form.tag" :options="tagOptions" allow-clear /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="L('Bus', 'Bus')"><a-select v-model:value="slot.form.vehicle_id" allow-clear show-search option-filter-prop="label" :options="busOptions" /></a-form-item>
        <a-form-item :label="L('Also boards at', 'También embarca en')">
          <div v-for="(b, i) in slot.form.boardings" :key="i" class="sch-brow">
            <a-select v-model:value="b.terminal" :options="terminalOptions" style="width: 150px" />
            <a-time-picker v-model:value="b.time" format="h:mm A" value-format="HH:mm" :minute-step="5" use12-hours style="width: 130px" />
            <a-button type="text" danger @click="slot.form.boardings.splice(i, 1)"><DeleteOutlined /></a-button>
          </div>
          <a-button size="small" @click="slot.form.boardings.push({ terminal: null, time: null })"><PlusOutlined /> {{ L('Add a stop', 'Agregar embarque') }}</a-button>
        </a-form-item>
        <a-form-item :label="L('Note', 'Nota')"><a-input v-model:value="slot.form.note" :maxlength="255" /></a-form-item>
      </a-form>
      <template v-if="slot.form.id" #footer>
        <a-popconfirm :title="L('Remove this departure?', '¿Quitar esta salida?')" @confirm="removeSlot"><a-button danger style="float: left">{{ L('Remove', 'Quitar') }}</a-button></a-popconfirm>
        <a-button @click="slot.open = false">{{ L('Cancel', 'Cancelar') }}</a-button>
        <a-button type="primary" :loading="busy === 'slot'" @click="saveSlot">{{ L('Save', 'Guardar') }}</a-button>
      </template>
    </a-modal>

    <!-- New / edit plan -->
    <a-modal v-model:open="pf.open" :title="pf.id ? L('Plan', 'Plan') : L('New plan', 'Nuevo plan')" :ok-text="L('Save', 'Guardar')" :cancel-text="L('Cancel', 'Cancelar')"
             :confirm-loading="busy === 'plan'" @ok="savePlan">
      <a-form layout="vertical">
        <a-form-item v-if="!pf.id && !hasBase" :label="L('Kind', 'Tipo')">
          <a-radio-group v-model:value="pf.kind"><a-radio value="season">{{ L('Season (dates)', 'Temporada (fechas)') }}</a-radio><a-radio value="base">{{ L('Base timetable', 'Horario base') }}</a-radio></a-radio-group>
        </a-form-item>
        <a-form-item :label="L('Name', 'Nombre')"><a-input v-model:value="pf.name" :placeholder="L('e.g. December 2026', 'p. ej. Diciembre 2026')" /></a-form-item>
        <a-row v-if="pf.kind === 'season'" :gutter="10">
          <a-col :span="12"><a-form-item :label="L('From', 'Desde')"><a-date-picker v-model:value="pf.date_from" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="L('To', 'Hasta')"><a-date-picker v-model:value="pf.date_to" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="L('Buses the plan counts on', 'Buses con los que se programa')"><a-input-number v-model:value="pf.buses" :min="1" :max="200" style="width: 140px" /></a-form-item>
        <a-form-item v-if="!pf.id && pf.kind === 'season' && hasBase"><a-checkbox v-model:checked="pf.copy_base">{{ L('Start from the base timetable', 'Empezar con el horario base') }}</a-checkbox></a-form-item>
        <a-form-item :label="L('Notes', 'Notas')"><a-textarea v-model:value="pf.notes" :rows="3" /></a-form-item>
      </a-form>
    </a-modal>

    <!-- Import -->
    <a-modal v-model:open="imp.open" :title="L('Import Excel', 'Importar Excel')" :ok-text="L('Import', 'Importar')" :cancel-text="L('Cancel', 'Cancelar')"
             :confirm-loading="busy === 'import'" @ok="doImport">
      <a-form layout="vertical">
        <a-form-item :label="L('What is it?', '¿Qué es?')">
          <a-radio-group v-model:value="imp.kind">
            <a-radio value="season">{{ L('Season grid (July, December...)', 'Programación de temporada (julio, diciembre...)') }}</a-radio>
            <a-radio value="base">{{ L('Base timetable (sheet Salidas)', 'Horario base (hoja Salidas)') }}</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item :label="L('File', 'Archivo')"><input type="file" accept=".xlsx,.xls" @change="e => (imp.file = e.target.files[0] || null)" /></a-form-item>
        <a-row v-if="imp.kind === 'season'" :gutter="10">
          <a-col :span="8"><a-form-item :label="L('Month of the first day', 'Mes del primer día')"><a-select v-model:value="imp.month" allow-clear :options="monthOptions" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item :label="L('Year', 'Año')"><a-input-number v-model:value="imp.year" :min="2020" :max="2100" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="10"><a-form-item :label="L('Name', 'Nombre')"><a-input v-model:value="imp.name" :placeholder="L('from the file name', 'del nombre del archivo')" /></a-form-item></a-col>
        </a-row>
        <div class="sch-help">{{ L('Month and year are taken from the file name (DICIEMBRE 2025, FIESTAS PATRIAS 2026) when left empty.', 'Si no los pone, el mes y el año salen del nombre del archivo (DICIEMBRE 2025, FIESTAS PATRIAS 2026).') }}</div>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Programación de buses (2026-11-25, Turismo Cautivo's Operaciones): the base timetable (Monday-Saturday / Sunday) and the
 * season plans (Fiestas Patrias, December) that were made in Excel and then typed into the ticket system. The grid looks
 * like their workbook: a card per day, a column per terminal, the departures by time with their tag (EJEC, PREM, TRUX...)
 * and bus; below, the day's prices per seat class and route and the buses each city has; warnings in red (more departures
 * than buses, a bus leaving twice). Server: TransportScheduleController / App\Services\TransportSchedule.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, UploadOutlined, FileExcelOutlined, EditOutlined, DeleteOutlined, InfoCircleOutlined, WarningOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useFormat } from '../../composables/useFormat';
import { useBarTitle } from '../../composables/useBarTitle';
import { getActingCompanyId } from '../../lib/actingCompany';
import { sortByLabel } from '../../lib/filterOptions';

const ui = useUiStore();
const L = (en, es) => (String(ui.locale || 'en').startsWith('es') ? es : en);
const { date } = useFormat();
useBarTitle(L('Bus programming', 'Programación de buses'));

const plans = ref([]);
const planId = ref(null);
const data = ref(null);
const loading = ref(false);
const busy = ref('');

const err = e => message.error(e?.data?.message || L('Could not save.', 'No se pudo guardar.'));
const hm = t => { const [h, m] = String(t || '').split(':').map(Number); const ap = h >= 12 ? 'PM' : 'AM'; return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${ap}`; };
const hasBase = computed(() => plans.value.some(p => p.kind === 'base'));
// The plans keep their own order (base first, then the latest season): the order means something here.
const planOptions = computed(() => plans.value.map(p => ({ value: p.id, label: p.kind === 'base' ? `${p.name}` : `${p.name} (${date(p.date_from)} – ${date(p.date_to)})` })));
const sides = computed(() => (data.value && (data.value.plan.kind === 'base' || data.value.has_chiclayo) ? ['lima', 'chiclayo'] : ['lima']));
const totalAlerts = computed(() => Object.values(data.value?.stats || {}).reduce((n, s) => n + (s.alerts?.length || 0), 0));
const noteLines = computed(() => String(data.value?.plan.notes || '').split('\n').filter(Boolean));
const routeById = computed(() => Object.fromEntries((data.value?.routes || []).map(r => [r.id, r])));
const isTrux = s => /TRUJILLO/i.test(routeById.value[s.route_id]?.destination || '') || /TRUX/.test(s.tag || '');
const chipClass = s => {
  const t = String(s.tag || '');
  return { prem: /PREM/.test(t), ejec: /EJEC/.test(t), opt: /OPC|\?/.test(t), ref: /(^|\s)R(\s|$)/.test(t), nobus: !s.vehicle_id };
};
// A terminal's column: the departures that start there, and (faded, "↳") the buses that board there later.
const slotsOf = (day, side, terminal) => {
  const out = [];
  for (const s of data.value?.slots[day] || []) {
    if (s.side !== side) continue;
    if (s.terminal === terminal) out.push(s);
    for (const b of s.boardings || []) {
      if (b.terminal === terminal) out.push({ ...s, ghost: true, time: b.time, main: s, mainTime: s.time, mainTerminal: s.terminal });
    }
  }
  return out.sort((a, b) => a.time.localeCompare(b.time));
};
const priceOf = (day, rk, cls) => { const v = data.value?.prices?.[day]?.[rk]?.[cls]; return v === undefined || v === null ? '' : v; };

async function loadPlans() {
  const r = await http.get('transport/schedule');
  plans.value = r.plans || [];
  if (!plans.value.some(p => p.id === planId.value)) planId.value = plans.value.find(p => p.kind === 'season')?.id || plans.value[0]?.id || null;
}
async function load() {
  if (!planId.value) { data.value = null; return; }
  loading.value = true;
  try { data.value = await http.get(`transport/schedule/${planId.value}`); } catch (e) { err(e); } finally { loading.value = false; }
}
async function refresh() { await loadPlans(); await load(); }

// --- a departure
const slot = reactive({ open: false, dayLabel: '', form: {} });
const terminalOptions = computed(() => (data.value?.terminals[slot.form.side] || []).map(t => ({ value: t, label: t })));
const routeOptions = computed(() => sortByLabel((data.value?.routes || [])
  .filter(r => (slot.form.side === 'lima' ? /LIMA/i.test(r.origin) : /CHICLAYO/i.test(r.origin))).map(r => ({ value: r.id, label: r.label }))));
const tagOptions = computed(() => (data.value?.tags || []).map(t => ({ value: t })));
const busOptions = computed(() => (data.value?.vehicles || []).map(v => ({ value: v.id, label: `${v.name} · ${v.plate || ''}${v.seats ? ` · ${v.seats} ${L('seats', 'asientos')}` : ''}` })));
function openSlot(d, side, terminal, s = null) {
  const defRoute = (data.value.routes || []).find(r => (side === 'lima' ? /LIMA/i.test(r.origin) && /CHICLAYO/i.test(r.destination) : /CHICLAYO/i.test(r.origin) && /LIMA/i.test(r.destination)));
  slot.dayLabel = data.value.plan.kind === 'base' ? d.label : `${d.label} · ${date(d.key)}`;
  slot.form = s ? { ...s, day: d.key, boardings: (s.boardings || []).map(b => ({ ...b })) }
    : { id: null, day: d.key, side, terminal, time: null, route_id: defRoute?.id || null, tag: '', vehicle_id: null, note: '', boardings: [] };
  slot.open = true;
}
async function saveSlot() {
  if (!slot.form.time) { message.warning(L('Give the time.', 'Ponga la hora.')); return; }
  busy.value = 'slot';
  try {
    const body = { day: slot.form.day, side: slot.form.side, terminal: slot.form.terminal, time: slot.form.time, route_id: slot.form.route_id || null,
      tag: slot.form.tag || null, vehicle_id: slot.form.vehicle_id || null, note: slot.form.note || null,
      boardings: (slot.form.boardings || []).filter(b => b.terminal && b.time) };
    if (slot.form.id) await http.put(`transport/schedule/${planId.value}/slots/${slot.form.id}`, body);
    else await http.post(`transport/schedule/${planId.value}/slots`, body);
    slot.open = false;
    await load();
  } catch (e) { err(e); } finally { busy.value = ''; }
}
async function removeSlot() {
  busy.value = 'slot';
  try { await http.delete(`transport/schedule/${planId.value}/slots/${slot.form.id}`); slot.open = false; await load(); } catch (e) { err(e); } finally { busy.value = ''; }
}

// --- prices and buses per city (saved as they are typed, on leaving the box)
async function saveDay(day, body) {
  try { await http.put(`transport/schedule/${planId.value}/day/${day}`, body); await load(); } catch (e) { err(e); }
}
const savePrice = (day, rk, cls, v) => saveDay(day, { prices: { [rk]: { [cls]: v } } });
function saveFleet(day, side, v) {
  const f = { ...(data.value.stats[day]?.fleet || {}), [side]: v };
  return saveDay(day, { fleet: { lima: f.lima || '', chiclayo: f.chiclayo || '' } });
}

// --- plans
const pf = reactive({ open: false, id: null, kind: 'season', name: '', date_from: null, date_to: null, buses: null, notes: '', copy_base: true });
function openNew() { Object.assign(pf, { open: true, id: null, kind: 'season', name: '', date_from: null, date_to: null, buses: null, notes: '', copy_base: hasBase.value }); }
function openEdit() {
  const p = data.value.plan;
  Object.assign(pf, { open: true, id: p.id, kind: p.kind, name: p.name, date_from: p.date_from, date_to: p.date_to, buses: p.buses, notes: p.notes || '' });
}
async function savePlan() {
  if (!pf.name) { message.warning(L('Give the name.', 'Ponga el nombre.')); return; }
  busy.value = 'plan';
  try {
    const body = { name: pf.name, kind: pf.kind, date_from: pf.date_from, date_to: pf.date_to, buses: pf.buses || null, notes: pf.notes || null, copy_base: pf.copy_base };
    const r = pf.id ? await http.put(`transport/schedule/${pf.id}`, body) : await http.post('transport/schedule', body);
    pf.open = false;
    planId.value = r.id;
    await refresh();
  } catch (e) { err(e); } finally { busy.value = ''; }
}
async function removePlan() {
  try { await http.delete(`transport/schedule/${planId.value}`); planId.value = null; await refresh(); } catch (e) { err(e); }
}

// --- import / export
const imp = reactive({ open: false, kind: 'season', file: null, month: null, year: null, name: '' });
const monthOptions = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'].map((m, i) => ({ value: i + 1, label: m }));
async function doImport() {
  if (!imp.file) { message.warning(L('Pick the file.', 'Elija el archivo.')); return; }
  busy.value = 'import';
  try {
    const fd = new FormData();
    fd.append('file', imp.file);
    fd.append('kind', imp.kind);
    if (imp.month) fd.append('month', imp.month);
    if (imp.year) fd.append('year', imp.year);
    if (imp.name) fd.append('name', imp.name);
    const r = await http.postForm('transport/schedule/import', fd);
    message.success(L(`Imported: ${r.slots} departures.`, `Importado: ${r.slots} salidas.`));
    imp.open = false;
    planId.value = r.plan_id;
    await refresh();
  } catch (e) { err(e); } finally { busy.value = ''; }
}
async function exportPlan() {
  busy.value = 'export';
  try {
    const cid = getActingCompanyId();
    await http.download(`transport/schedule/${planId.value}/export${cid ? `?company_id=${cid}` : ''}`, `Programación - ${data.value.plan.name}.xlsx`);
  } catch (e) { err(e); } finally { busy.value = ''; }
}

onMounted(async () => { loading.value = true; try { await loadPlans(); } finally { loading.value = false; } await load(); });
</script>

<style scoped>
.sch-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
.sch-plan { min-width: 320px; }
.sch-info { margin-left: 6px; color: var(--ant-color-text-secondary, #666); }
.sch-red { color: #cf1322; font-weight: 600; }
.sch-i { margin-left: 6px; cursor: help; }
.sch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(232px, 1fr)); gap: 8px; }
.sch-grid.wide { grid-template-columns: repeat(auto-fill, minmax(460px, 1fr)); }
.sch-day { border: 1px solid var(--ant-color-border, #d9d9d9); border-radius: 6px; background: var(--ant-color-bg-container, #fff); display: flex; flex-direction: column; }
.sch-day.sun .sch-dh { background: #fff7e6; }
.sch-day.bad { border-color: #ffa39e; }
.sch-dh { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; padding: 4px 8px; background: var(--ant-color-fill-quaternary, #fafafa); border-bottom: 1px solid var(--ant-color-border-secondary, #f0f0f0); border-radius: 6px 6px 0 0; }
.sch-cnt { font-size: 11px; color: var(--ant-color-text-secondary, #888); white-space: nowrap; }
.sch-sl { font-size: 11px; font-weight: 600; padding: 2px 8px 0; color: var(--ant-color-primary, #1677ff); }
.sch-cols { display: grid; gap: 4px; padding: 4px 6px; }
.sch-th { font-size: 10px; font-weight: 700; letter-spacing: .02em; color: var(--ant-color-text-secondary, #888); display: flex; justify-content: space-between; border-bottom: 1px dashed var(--ant-color-border-secondary, #eee); margin-bottom: 2px; }
.sch-add { cursor: pointer; color: var(--ant-color-primary, #1677ff); }
.sch-chip { display: flex; flex-wrap: wrap; gap: 3px; align-items: center; padding: 1px 4px; margin-bottom: 2px; border-radius: 4px; cursor: pointer; font-size: 11.5px; line-height: 17px;
  background: #e6f4ff; border-left: 3px solid #1677ff; }
.sch-chip:hover { filter: brightness(.95); }
.sch-chip.ghost { background: transparent; border-left-style: dotted; opacity: .7; font-style: italic; }
.sch-brow { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }
.sch-chip.prem { background: #f9f0ff; border-left-color: #722ed1; }
.sch-chip.ejec { background: #f6ffed; border-left-color: #389e0d; }
.sch-chip.opt { background: #fffbe6; border-left-color: #d48806; border-style: dashed; }
.sch-chip.ref { background: #fff1f0; border-left-color: #cf1322; }
.sch-time { font-weight: 600; font-variant-numeric: tabular-nums; }
.sch-tag { font-size: 10px; padding: 0 3px; border-radius: 3px; background: rgba(0, 0, 0, .06); }
.sch-bus { font-size: 10px; font-weight: 700; color: #08979c; }
.sch-bus::before { content: '#'; opacity: .6; }
.sch-tru { font-size: 9px; font-weight: 700; color: #fff; background: #fa8c16; padding: 0 3px; border-radius: 3px; }
.sch-foot { margin-top: auto; padding: 4px 6px 6px; border-top: 1px solid var(--ant-color-border-secondary, #f0f0f0); font-size: 11px; }
.sch-pr { width: 100%; border-collapse: collapse; }
.sch-pr th { font-weight: 600; font-size: 10px; color: var(--ant-color-text-secondary, #888); text-align: left; }
.sch-pr td { padding: 1px 2px 1px 0; }
.sch-in { width: 100%; border: 1px solid var(--ant-color-border, #d9d9d9); border-radius: 3px; padding: 0 4px; font-size: 11px; height: 20px; background: transparent; color: inherit; }
.sch-in.sm { width: 46px; }
.sch-fleet { display: flex; gap: 6px; align-items: center; margin-top: 3px; flex-wrap: wrap; }
.sch-fleet label { display: inline-flex; gap: 3px; align-items: center; }
.sch-alert { color: #cf1322; font-weight: 600; margin-top: 2px; }
.sch-day-label { font-weight: 600; margin-bottom: 8px; }
.sch-help { font-size: 12px; color: var(--ant-color-text-secondary, #888); }
.sch-form :deep(.ant-form-item) { margin-bottom: 8px; }
</style>
