<template>
  <!-- Programación (2026-11-26): the week like Operaciones' Excel -- a column per day, Lima's side then the north's, a card
       per departure with Jelaf's sold seats, the forecast and what to do (App\Services\TransportProgramming). -->
  <div class="pg">
    <div class="pg-top">
      <div class="pg-nav">
        <a-button size="small" @click="move(-7)"><LeftOutlined /></a-button>
        <b class="pg-week">{{ weekLabel }}</b>
        <a-button size="small" @click="move(7)"><RightOutlined /></a-button>
        <a-button size="small" @click="go(today)">{{ L('This week', 'Esta semana') }}</a-button>
      </div>
      <div class="pg-chips">
        <span class="pg-chip c-hi" :title="L('Forecast at 90 % of the seats or more', 'Pronóstico de 90 % de los asientos o más')"><b>{{ s.reinforce }}</b> {{ L('reinforce', 'reforzar') }}</span>
        <span class="pg-chip c-lo" :title="L('Forecast under 30 % of the seats', 'Pronóstico bajo 30 % de los asientos')"><b>{{ s.low }}</b> {{ L('low', 'baja ocup.') }}</span>
        <span class="pg-chip" :class="s.conflicts ? 'c-bad' : 'c-ok'"><b>{{ s.conflicts }}</b> {{ L('bus clashes', 'choques') }}</span>
        <span class="pg-chip c-plain" :title="L('In the plan, nothing sold / not open in Jelaf yet', 'En el plan, sin ventas / aún no abierta en Jelaf')"><b>{{ s.plan_only }}</b> {{ L('plan only', 'solo plan') }}</span>
        <span class="pg-chip c-plain" :title="L('Open in Jelaf, not in the plan', 'Abierta en Jelaf, no está en el plan')"><b>{{ s.jelaf_only }}</b> {{ L('Jelaf only', 'fuera de plan') }}</span>
      </div>
      <div class="pg-tools">
        <span class="pg-at" :title="L('Seats sold ahead come from Jelaf each night at 22:00', 'Los pasajes vendidos por adelantado llegan de Jelaf cada noche a las 22:00')">
          {{ L('Sold as of', 'Vendidos al') }} {{ data.ahead_at ? fmt.dateTime(data.ahead_at) : '—' }}
        </span>
        <a-button size="small" :loading="exporting" @click="exportWeek"><FileExcelOutlined /> Excel</a-button>
      </div>
    </div>

    <a-spin :spinning="loading">
      <div class="pg-grid">
        <div v-for="d in data.days" :key="d.date" class="pg-day" :class="{ past: d.past, today: d.today }">
          <div class="pg-dh">
            <div class="pg-dname">{{ d.label }}<span v-if="d.today" class="pg-tag-today">{{ L('today', 'hoy') }}</span></div>
            <div class="pg-dsub">
              <template v-if="d.past">{{ d.sold }} {{ L('pax', 'pax') }} · {{ pct(d.sold, d.seats) }}</template>
              <template v-else>{{ d.sold }} {{ L('sold', 'vend.') }} → {{ d.forecast }} · {{ pct(d.forecast, d.seats) }}</template>
            </div>
            <div class="pg-dbar"><i :style="{ width: w(d.past ? d.sold : d.forecast, d.seats) }" /></div>
          </div>
          <div class="pg-dbody">
            <template v-for="side in SIDES" :key="side.key">
              <div class="pg-side">
                <span>{{ L(side.en, side.es) }}</span>
                <small>{{ d.sides?.[side.key]?.departures || 0 }}</small>
                <a v-if="canAdd(d)" class="pg-add" :title="L('Add a departure', 'Agregar salida')" @click="openAdd(d, side.key)"><PlusOutlined /></a>
              </div>
              <div
                v-for="x in d.departures.filter(x => x.side === side.key)" :key="x.key" class="pg-card"
                :class="[cls(x, d), { removed: x.removed, planonly: x.source === 'plan', outplan: x.source === 'jelaf' }]" @click="open(x, d)"
              >
                <div class="pg-l1">
                  <b class="pg-time">{{ ampm(x.time) }}</b>
                  <span class="pg-bus" :class="{ tentative: x.bus_from === 'base', none: !x.bus }">{{ x.bus ? busNo(x.bus) : L('bus?', 'bus ?') }}</span>
                  <span v-if="x.tag" class="pg-svc">{{ x.tag }}</span>
                  <WarningFilled v-if="x.alerts.length" class="pg-warn" />
                  <span v-if="x.added" class="pg-flag">{{ L('Reinf.', 'Refuerzo') }}</span>
                  <span v-else-if="x.changed" class="pg-flag">{{ L('Changed', 'Cambio') }}</span>
                </div>
                <div class="pg-l2">
                  <div class="pg-bar">
                    <i class="fc" :style="{ width: w(x.forecast, x.seats) }" /><i class="sd" :style="{ width: w(x.sold, x.seats) }" />
                  </div>
                  <span class="pg-num">{{ x.sold }}<template v-if="!d.past && x.forecast !== x.sold">→{{ x.forecast }}</template><small v-if="x.seats">/{{ x.seats }}</small></span>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>
    </a-spin>

    <!-- One departure: what it carries, why the suggestion, and the date's change. -->
    <a-drawer :open="!!sel" :width="420" :title="sel ? `${selDay.label} · ${ampm(sel.time)} · ${L(sideName(sel.side).en, sideName(sel.side).es)}` : ''" @close="sel = null">
      <template v-if="sel">
        <div v-if="sel.suggest" class="pg-sug" :class="sel.suggest">
          <b>{{ sel.suggest === 'reinforce' ? L('Reinforce', 'Reforzar') : L('Low occupancy', 'Baja ocupación') }}</b>
          — {{ sel.suggest === 'reinforce'
            ? L(`about ${sel.forecast} passengers expected for ${sel.seats} seats: add a bus or move it to a bigger one.`, `se esperan unos ${sel.forecast} pasajeros para ${sel.seats} asientos: agregar un bus o pasar a uno más grande.`)
            : L(`about ${sel.forecast} passengers expected for ${sel.seats} seats: merge with a near departure or use a smaller bus.`, `se esperan unos ${sel.forecast} pasajeros para ${sel.seats} asientos: juntar con una salida cercana o usar un bus más chico.`) }}
        </div>
        <a-alert v-for="(a, i) in sel.alerts" :key="i" type="error" show-icon :message="a" style="margin-bottom: 6px; padding: 4px 10px" />
        <dl class="pg-dl">
          <dt>{{ L('Bus', 'Bus') }}</dt>
          <dd>{{ sel.bus || L('not assigned yet', 'aún sin asignar') }}<small v-if="sel.plate"> · {{ sel.plate }}</small>
            <small v-if="sel.bus_from === 'base'"> · {{ L('usual bus of the base timetable', 'bus habitual del horario base') }}</small>
            <small v-else-if="sel.bus_from === 'jelaf'"> · Jelaf</small></dd>
          <dt>{{ L('Service', 'Servicio') }}</dt><dd>{{ sel.tag || '—' }}</dd>
          <dt>{{ L('Terminal', 'Terminal') }}</dt><dd>{{ sel.terminal || '—' }}<small v-if="sel.boardings?.length"> · {{ sel.boardings.map(b => `${b.terminal} ${ampm(b.time)}`).join(', ') }}</small></dd>
          <dt>{{ L('Route', 'Ruta') }}</dt><dd>{{ sel.route || '—' }}</dd>
          <dt>{{ L('Sold', 'Vendidos') }}</dt><dd><b>{{ sel.sold }}</b><template v-if="sel.seats"> / {{ sel.seats }} {{ L('seats', 'asientos') }}</template><template v-if="selDay.past"> · {{ sel.boarded }} {{ L('boarded', 'embarcaron') }}</template></dd>
          <template v-if="!selDay.past"><dt>{{ L('Forecast', 'Pronóstico') }}</dt><dd><b>{{ sel.forecast }}</b> ({{ pct(sel.forecast, sel.seats) }})
            <a-tooltip :title="L('Sold so far + what usually comes in the days left (the 2026 sales curve, same side, weekday and hour)', 'Lo vendido + lo que suele venderse en los días que faltan (curva de ventas de 2026, mismo lado, día y hora)')"><InfoCircleOutlined /></a-tooltip></dd></template>
          <dt>{{ L('Amount', 'Monto') }}</dt><dd>S/ {{ money(sel.amount) }}</dd>
          <dt>{{ L('Boarding points', 'Embarques') }}</dt><dd>{{ sel.points || '—' }}</dd>
          <dt>{{ L('Source', 'Origen') }}</dt>
          <dd>{{ sel.source === 'both' ? L('Plan + Jelaf', 'Plan + Jelaf') : sel.source === 'plan' ? L('Plan only (not open in Jelaf / nothing sold)', 'Solo plan (sin abrir en Jelaf / sin ventas)') : L('Jelaf only (not in the plan)', 'Solo Jelaf (no está en el plan)') }}
            <small v-if="sel.programacion"> · {{ L('programming', 'programación') }} {{ sel.programacion }}</small></dd>
          <template v-if="sel.note"><dt>{{ L('Note', 'Nota') }}</dt><dd>{{ sel.note }}</dd></template>
        </dl>

        <template v-if="data.can_edit && !selDay.past">
          <a-divider style="margin: 12px 0 8px">{{ L('Program this date', 'Programar esta fecha') }}</a-divider>
          <a-form layout="vertical" size="small">
            <div class="pg-row">
              <a-form-item :label="L('Bus', 'Bus')" style="flex: 1"><a-select v-model:value="edit.vehicle_id" :options="busOptions" allow-clear show-search option-filter-prop="label" /></a-form-item>
              <a-form-item :label="L('Time', 'Hora')" style="width: 90px"><a-input v-model:value="edit.time" placeholder="18:30" /></a-form-item>
              <a-form-item :label="L('Service', 'Servicio')" style="width: 110px"><a-select v-model:value="edit.tag" :options="tagOptions" allow-clear /></a-form-item>
            </div>
            <a-form-item :label="L('Note (why)', 'Nota (por qué)')"><a-input v-model:value="edit.note" :maxlength="250" /></a-form-item>
            <div class="pg-actions">
              <a-button v-if="sel.slot_id" type="primary" :loading="saving" @click="save('change')">{{ L('Save change', 'Guardar cambio') }}</a-button>
              <a-button :loading="saving" @click="reinforce">{{ L('Add reinforcement', 'Agregar refuerzo') }}</a-button>
              <a-popconfirm v-if="sel.slot_id && !sel.removed" :title="L('Remove this departure on this date?', '¿Quitar esta salida en esta fecha?')" @confirm="save('remove')">
                <a-button danger :loading="saving">{{ L('Remove for the day', 'Quitar del día') }}</a-button>
              </a-popconfirm>
              <a-button v-if="sel.change_id" :loading="saving" @click="undo(sel.change_id)">{{ L('Undo change', 'Deshacer cambio') }}</a-button>
            </div>
            <div v-if="!sel.slot_id && !sel.added" class="pg-hint">{{ L('Not in the plan: add it to the plan in Programación de buses to change it.', 'No está en el plan: agréguela en Programación de buses para cambiarla.') }}</div>
          </a-form>
        </template>
      </template>
    </a-drawer>

    <!-- A new departure (reinforcement) on a date and side. -->
    <a-modal :open="!!adding" :title="adding ? `${L('New departure', 'Nueva salida')} · ${adding.day.label} · ${L(sideName(adding.side).en, sideName(adding.side).es)}` : ''"
      :confirm-loading="saving" :ok-text="L('Add', 'Agregar')" @ok="saveAdd" @cancel="adding = null">
      <a-form v-if="adding" layout="vertical" size="small">
        <div class="pg-row">
          <a-form-item :label="L('Time', 'Hora')" style="width: 100px"><a-input v-model:value="adding.time" placeholder="18:30" /></a-form-item>
          <a-form-item :label="L('Terminal', 'Terminal')" style="flex: 1"><a-select v-model:value="adding.terminal" :options="terminalOptions(adding.side)" /></a-form-item>
          <a-form-item :label="L('Service', 'Servicio')" style="width: 120px"><a-select v-model:value="adding.tag" :options="tagOptions" allow-clear /></a-form-item>
        </div>
        <a-form-item :label="L('Bus', 'Bus')"><a-select v-model:value="adding.vehicle_id" :options="busOptions" allow-clear show-search option-filter-prop="label" /></a-form-item>
        <a-form-item :label="L('Note (why)', 'Nota (por qué)')"><a-input v-model:value="adding.note" :maxlength="250" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { LeftOutlined, RightOutlined, FileExcelOutlined, PlusOutlined, WarningFilled, InfoCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { getActingCompanyId } from '../lib/actingCompany';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';
import { sortByLabel } from '../lib/filterOptions';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const L = (e, s) => (es.value ? s : e);
const fmt = useFormat();
const SIDES = [{ key: 'lima', en: 'From Lima', es: 'Desde Lima' }, { key: 'chiclayo', en: 'From the north', es: 'Desde el norte' }];
const sideName = k => SIDES.find(x => x.key === k) || SIDES[0];

const today = dayjs().format('YYYY-MM-DD');
const start = ref(today);
const data = ref({ days: [], summary: {}, vehicles: [], tags: [], terminals: {} });
const loading = ref(false);
const s = computed(() => data.value.summary || {});
const weekLabel = computed(() => (data.value.from ? `${dayjs(data.value.from).format('DD/MM')} – ${dayjs(data.value.to).format('DD/MM/YYYY')}` : ''));

async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/programming', { start: start.value }); } catch (e) { message.error(e?.data?.message || L('Could not load.', 'No se pudo cargar.')); }
  loading.value = false;
}
function go(d) { start.value = d; load(); }
function move(n) { go(dayjs(data.value.from || start.value).add(n, 'day').format('YYYY-MM-DD')); }
onMounted(load);

const ampm = t => (t ? dayjs(`2000-01-01 ${t}`).format('h:mm A') : '');
const busNo = b => String(b).replace(/^Bus\s+/i, '');
const pct = (a, b) => (b ? `${Math.round((a / b) * 100)} %` : '—');
const w = (a, b) => (b ? `${Math.min(100, Math.round((a / b) * 100))}%` : '0%');
const money = v => Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function cls(x, d) {
  if (x.alerts.length) return 'st-bad';
  if (x.suggest === 'reinforce') return 'st-hi';
  if (x.suggest === 'low') return 'st-lo';
  const p = x.seats ? (d.past ? x.sold : x.forecast) / x.seats : null;
  return p === null ? 'st-na' : 'st-ok';
}
const canAdd = d => data.value.can_edit && !d.past;

const busOptions = computed(() => sortByLabel((data.value.vehicles || []).map(v => ({ value: v.id, label: `${v.name}${v.seats ? ` · ${v.seats} as.` : ''}${v.plate ? ` · ${v.plate}` : ''}` }))));
const tagOptions = computed(() => (data.value.tags || []).map(t => ({ value: t, label: t })));
const terminalOptions = side => (data.value.terminals?.[side] || []).map(t => ({ value: t, label: t }));

// ---- one departure
const sel = ref(null);
const selDay = ref(null);
const edit = reactive({ vehicle_id: null, time: '', tag: null, note: '' });
const saving = ref(false);
function open(x, d) {
  sel.value = x; selDay.value = d;
  Object.assign(edit, { vehicle_id: x.vehicle_id || null, time: x.time || '', tag: x.tag || null, note: x.note || '' });
}
async function post(body) {
  saving.value = true;
  try { await http.post('transport/programming/changes', body); message.success(L('Saved.', 'Guardado.')); sel.value = null; adding.value = null; await load(); } catch (e) { message.error(e?.data?.message || L('Could not save.', 'No se pudo guardar.')); }
  saving.value = false;
}
function save(action) {
  post({ day: selDay.value.date, action, slot_id: sel.value.slot_id, side: sel.value.side, vehicle_id: edit.vehicle_id, time: edit.time, tag: edit.tag, note: edit.note });
}
function reinforce() {
  // A reinforcement next to this departure: same side, terminal and service, a quarter of an hour later.
  const t = dayjs(`2000-01-01 ${sel.value.time}`).add(15, 'minute').format('HH:mm');
  adding.value = { day: selDay.value, side: sel.value.side, time: t, terminal: sel.value.terminal || terminalOptions(sel.value.side)[0]?.value, tag: sel.value.tag, vehicle_id: null,
    note: L(`Reinforcement of the ${ampm(sel.value.time)}`, `Refuerzo de la de ${ampm(sel.value.time)}`) };
  sel.value = null;
}
async function undo(id) {
  saving.value = true;
  try { await http.delete(`transport/programming/changes/${id}`); message.success(L('Undone.', 'Deshecho.')); sel.value = null; await load(); } catch (e) { message.error(e?.data?.message || 'Error'); }
  saving.value = false;
}

// ---- new departure
const adding = ref(null);
function openAdd(d, side) {
  adding.value = { day: d, side, time: '', terminal: terminalOptions(side)[0]?.value, tag: null, vehicle_id: null, note: '' };
}
function saveAdd() {
  const a = adding.value;
  post({ day: a.day.date, action: 'add', side: a.side, terminal: a.terminal, time: a.time, tag: a.tag, vehicle_id: a.vehicle_id, note: a.note });
}

const exporting = ref(false);
async function exportWeek() {
  exporting.value = true;
  try {
    const cid = getActingCompanyId();
    const q = new URLSearchParams({ start: data.value.from || start.value, ...(cid ? { company_id: cid } : {}) });
    await http.download(`transport/programming/export?${q}`, `Programacion ${dayjs(data.value.from).format('DD-MM-YYYY')}.xlsx`);
  } catch (e) { message.error(L('The file could not be made.', 'No se pudo generar el archivo.')); }
  exporting.value = false;
}
</script>

<style scoped>
/* Navy and orange, the board bar's palette (2026-11-26). */
.pg { --navy: #152d4a; --navy2: #27507d; --orange: #f28c13; --orange2: #f9a83a; --line: #e3e8ef; }
.pg-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.pg-nav { display: flex; align-items: center; gap: 6px; }
.pg-week { color: var(--navy); font-size: 14px; min-width: 140px; text-align: center; }
.pg-chips { display: flex; gap: 5px; flex-wrap: wrap; flex: 1; justify-content: center; min-width: 0; }
.pg-chip { font-size: 11.5px; padding: 2px 7px; border-radius: 999px; background: #f2f5f9; color: #475467; white-space: nowrap; }
.pg-chip b { font-size: 13px; }
.pg-chip.c-hi { background: #fff1e0; color: #b45f06; }
.pg-chip.c-lo { background: #eaf0f7; color: #344e6f; }
.pg-chip.c-bad { background: #fde8e8; color: #b42318; }
.pg-chip.c-ok { background: #e8f6ee; color: #1f7a45; }
.pg-tools { display: flex; align-items: center; gap: 8px; }
.pg-at { font-size: 11.5px; color: #667085; }

.pg-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.pg-day { border: 1px solid var(--line); border-radius: 10px; background: #fafbfd; display: flex; flex-direction: column; min-width: 0; overflow: hidden; }
.pg-day.today { border-color: var(--orange); box-shadow: 0 0 0 1px var(--orange); }
.pg-day.past { opacity: .78; }
.pg-dh { background: linear-gradient(100deg, var(--navy), var(--navy2)); color: #fff; padding: 5px 8px 6px; }
.pg-day.today .pg-dh { background: linear-gradient(180deg, var(--orange2), var(--orange)); }
.pg-dname { font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 6px; }
.pg-tag-today { font-size: 10px; text-transform: uppercase; background: rgba(255, 255, 255, .25); border-radius: 4px; padding: 0 4px; }
.pg-dsub { font-size: 11px; opacity: .9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-dbar { height: 3px; background: rgba(255, 255, 255, .25); border-radius: 2px; margin-top: 3px; }
.pg-dbar i { display: block; height: 100%; background: var(--orange2); border-radius: 2px; }
.pg-day.today .pg-dbar i { background: #fff; }
.pg-dbody { padding: 4px; overflow-y: auto; max-height: calc(100vh - 360px); min-height: 220px; }
.pg-side { display: flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--orange); margin: 4px 2px 3px; }
.pg-side small { color: #98a2b3; font-weight: 600; }
.pg-add { margin-left: auto; color: var(--navy2); }
.pg-card { background: #fff; border: 1px solid var(--line); border-left: 3px solid #98a2b3; border-radius: 6px; padding: 3px 5px 4px; margin-bottom: 3px; cursor: pointer; transition: box-shadow .15s, transform .15s; }
.pg-card:hover { box-shadow: 0 2px 8px rgba(21, 45, 74, .14); transform: translateY(-1px); }
.pg-card.st-ok { border-left-color: #2e9e5b; }
.pg-card.st-hi { border-left-color: var(--orange); background: #fffaf3; }
.pg-card.st-lo { border-left-color: #8aa0bd; }
.pg-card.st-bad { border-left-color: #d92d20; background: #fff6f5; }
.pg-card.planonly { border-style: dashed; border-left-style: solid; }
.pg-card.removed { opacity: .5; text-decoration: line-through; }
.pg-l1 { display: flex; align-items: center; gap: 4px; font-size: 11.5px; white-space: nowrap; overflow: hidden; }
.pg-time { color: var(--navy); font-size: 12px; }
.pg-bus { font-weight: 600; color: #344054; }
.pg-bus.tentative { font-weight: 500; color: #667085; font-style: italic; }
.pg-bus.none { color: #b42318; font-weight: 500; }
.pg-svc { font-size: 9.5px; background: #eef2f7; color: var(--navy2); border-radius: 3px; padding: 0 3px; overflow: hidden; text-overflow: ellipsis; }
.pg-warn { color: #d92d20; font-size: 11px; }
.pg-flag { font-size: 9.5px; background: var(--orange); color: #fff; border-radius: 3px; padding: 0 3px; }
.pg-l2 { display: flex; align-items: center; gap: 5px; margin-top: 2px; }
.pg-bar { position: relative; flex: 1; height: 6px; background: #edf1f6; border-radius: 3px; overflow: hidden; }
.pg-bar i { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 3px; }
.pg-bar .fc { background: rgba(242, 140, 19, .35); }
.pg-bar .sd { background: var(--navy2); }
.pg-num { font-size: 11px; color: #344054; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pg-num small { color: #98a2b3; }

.pg-sug { border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; font-size: 13px; }
.pg-sug.reinforce { background: #fff1e0; color: #7a3e00; }
.pg-sug.low { background: #eaf0f7; color: #233b57; }
.pg-dl { display: grid; grid-template-columns: 110px 1fr; gap: 4px 10px; margin: 0; font-size: 13px; }
.pg-dl dt { color: #667085; }
.pg-dl dd { margin: 0; }
.pg-row { display: flex; gap: 8px; }
.pg-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.pg-hint { margin-top: 8px; font-size: 12px; color: #667085; }
@media (max-width: 900px) { .pg-grid { grid-template-columns: 1fr; } .pg-dbody { max-height: none; } }
</style>
