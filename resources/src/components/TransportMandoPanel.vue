<template>
  <!-- Mando operativo on Jelaf's data (the user, 2026-11-26): today and the next 2 days, both directions, what each departure
       has sold by channel, the passengers expected, its projected income, its expenses and the possible profit or loss.
       App\Services\TransportProgramming::outlook. -->
  <div class="md">
    <a-spin :spinning="loading">
      <!-- Which week and which direction (the user, 2026-11-27, for Julio): a date in an earlier week shows that week as it went;
           Lima → Chiclayo and Chiclayo → Lima apart, never only together. -->
      <div class="md-bar">
        <!-- The arrows say what they do (the user, 2026-11-27). -->
        <a-button size="small" @click="shift(-7)"><LeftOutlined /> {{ L('See last week', 'Ver semana pasada') }}</a-button>
        <a-date-picker v-model:value="pick" size="small" value-format="YYYY-MM-DD" :allow-clear="false" class="md-date" @change="load" />
        <a-button size="small" :disabled="!data.past" @click="shift(7)">{{ L('See next week', 'Ver siguiente semana') }} <RightOutlined /></a-button>
        <span class="md-period">{{ data.past ? L(`Week ${dm(data.from)} – ${dm(data.to)} · as it went`, `Semana ${dm(data.from)} – ${dm(data.to)} · cómo le fue`) : L('Today and the next 2 days', 'Hoy y los próximos 2 días') }}</span>
        <a-button v-if="data.past" size="small" type="link" @click="pick = today; load()">{{ L('Back to today', 'Volver a hoy') }}</a-button>
        <a-segmented v-model:value="dir" size="small" :options="dirOptions" class="md-dir" />
      </div>
      <!-- No cards (the user, 2026-11-27: "están de más"): the week boxes and the table say it all. -->
      <!-- The three weeks: today's, the next and the one after -- or, for an earlier date, its week and the two before it.
           Each one per direction; a click opens that week. -->
      <div class="md-weeks">
        <div v-for="(w, i) in data.weeks || []" :key="w.from" class="md-week" :class="{ on: data.past && w.from === data.from }" @click="openWeek(w)">
          <div class="md-week-h"><b>{{ weekName(w, i) }}</b> <span>{{ dm(w.from) }} – {{ dm(w.to) }}</span>
            <span class="md-week-t">{{ w.departures }} {{ L('departures', 'salidas') }} · {{ w.sold }} {{ L('sold', 'vendidos') }}</span>
          </div>
          <div v-for="sd in SIDES" :key="sd.key" class="md-week-s" :class="{ dim: dir !== 'all' && dir !== sd.key }">
            <span class="md-week-r">{{ L(sd.en, sd.es) }}</span>
            <span>{{ w.sides?.[sd.key]?.departures || 0 }} {{ L('dep.', 'sal.') }}</span>
            <span :class="{ 'md-bad': w.sides?.[sd.key]?.loss }">{{ w.sides?.[sd.key]?.loss || 0 }} {{ L('at a loss', 'en pérdida') }}</span>
            <b v-if="data.money && w.sides?.[sd.key]" :class="w.sides[sd.key].result < 0 ? 'md-bad' : 'md-good'">{{ signed(w.sides[sd.key].result) }}</b>
          </div>
          <div class="md-week-n">
            <template v-if="data.money">{{ L('Total', 'Total') }} <b :class="w.result < 0 ? 'md-bad' : 'md-good'">{{ signed(w.result) }}</b></template>
            <template v-if="w.gone && w.gone < w.departures"> · {{ L(`${w.gone} already run (real), the rest projected`, `${w.gone} ya salieron (real), el resto proyectado`) }}</template>
            <template v-if="w.days_loaded < 7"> · {{ L(`${w.days_loaded} of 7 days with sales`, `${w.days_loaded} de 7 días con ventas`) }}</template>
          </div>
        </div>
      </div>
      <!-- The table (the user, 2026-11-27): soft Power BI colours (navy / orange only on the tabs), seats centred, "Pasajes
           vendidos por medio" and "Egresos" as groups with their columns, the estimated profit / loss last, a loss row on 10 % red. -->
      <div class="md-wrap">
        <table class="md-t">
          <thead>
            <tr>
              <th rowspan="2">{{ L('Time', 'Hora') }}</th>
              <th rowspan="2">Bus</th>
              <th rowspan="2" class="c">{{ L('Seats', 'Asien.') }}</th>
              <th :colspan="channelKeys.length + 1" class="c md-g-sold">{{ L('Tickets sold by channel', 'Pasajes vendidos por medio') }}</th>
              <th rowspan="2" class="c" :title="L('Passengers expected when it leaves (the 2026 sales curve)', 'Pasajeros que se esperan cuando salga (curva de ventas de 2026)')">{{ data.past ? L('Travelled', 'Viajaron') : L('Expected', 'Esper.') }}</th>
              <th v-if="!data.past" rowspan="2" class="c" :title="L('Tickets still to sell to cover its expenses', 'Pasajes que faltan vender para cubrir sus egresos')">{{ L('To cover', 'Faltan') }}</th>
              <template v-if="data.money">
                <!-- The standard money groups (2026-11-27, lib/moneyColumns.js): Ingresos then Egresos. -->
                <th :colspan="INCOME_COLS.length + 1" class="c md-g-inc" :title="data.past ? '' : L('Projected: expected passengers × its ticket price; parcels and excess are not projected', 'Proyectado: esperados × el precio de su pasaje; encomiendas y excesos no se proyectan')">{{ L('Income', 'Ingresos') }}</th>
                <th :colspan="costCols.length + 1" class="c md-g-cost" :title="L('What this trip will spend: the average of its route over the last 30 days', 'Lo que gastará este viaje: el promedio de su ruta en los últimos 30 días')">{{ L('Expenses', 'Egresos') }}</th>
                <th rowspan="2" class="r md-res" :title="L('Estimated: income − expenses', 'Estimada: ingresos − egresos')">{{ L('Profit /', 'Ganancia /') }}<br>{{ L('Loss', 'Pérdida') }}</th>
              </template>
            </tr>
            <tr>
              <th v-for="k in channelKeys" :key="k" class="c md-sub md-g-sold">{{ chName(k) }}</th>
              <th class="c md-sub md-g-sold">Total</th>
              <template v-if="data.money">
                <th v-for="c in INCOME_COLS" :key="c.key" class="r md-sub md-g-inc">{{ L(c.en, c.es) }}</th>
                <th class="r md-sub md-g-inc">Total</th>
                <th v-for="k in costCols" :key="k.key" class="r md-sub md-g-cost" :class="{ 'md-hand': k.key === 'fuel' }">
                  <!-- Fuel is typed by hand (the user, 2026-11-27): AuraSoft has no fuel, only Mayte's own Excel. -->
                  <a-tooltip v-if="k.key === 'fuel'" :title="FUEL_HELP"><span>{{ L(k.en, k.es) }} <InfoCircleOutlined /></span></a-tooltip>
                  <!-- No real data for this kind yet (2026-11-27): 0 with what is missing. -->
                  <a-tooltip v-else-if="colEmpty(k)" :title="noDataHelp(k.key, es)"><span class="md-nodata">{{ L(k.en, k.es) }}</span></a-tooltip>
                  <template v-else>{{ L(k.en, k.es) }}</template>
                </th>
                <th class="r md-sub md-g-cost">Total</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template v-for="g in groups" :key="g.date">
              <tr class="md-day"><td :colspan="colCount">{{ g.label }} <small>· {{ g.count }} {{ L('departures', 'salidas') }}</small></td></tr>
              <template v-for="sg in g.sides" :key="g.date + sg.key">
              <!-- Each direction of the day with its own result (the user, 2026-11-27: Julio sees each route apart). -->
              <tr v-if="dir === 'all'" class="md-side"><td :colspan="colCount - (data.money ? 1 : 0)">{{ L(sideOf(sg.key).en, sideOf(sg.key).es) }} <small>· {{ sg.rows.length }} {{ L('departures', 'salidas') }} · {{ sg.loss }} {{ L('at a loss', 'en pérdida') }}</small></td>
                <td v-if="data.money" class="r"><b :class="sg.result < 0 ? 'md-neg' : 'md-pos'">{{ signed(sg.result, false) }}</b></td></tr>
              <tr v-for="r in sg.rows" :key="g.date + r.time + r.side + r.route" :class="{ 'md-loss': r.result != null && r.result < 0 }">
                <td class="md-time">{{ ampm(r.time) }}</td>
                <td><span :class="{ 'md-tent': r.bus_from === 'base' }">{{ r.bus || '—' }}</span> <small v-if="r.tag" class="md-tag">{{ r.tag }}</small></td>
                <td class="c">{{ r.seats || '—' }}</td>
                <td v-for="k in channelKeys" :key="k" class="c md-ch">{{ r.channels?.[k]?.n || '' }}</td>
                <td class="c"><b>{{ r.sold }}</b></td>
                <td class="c">{{ r.forecast }}<small v-if="r.seats" class="md-pct">{{ pct(r.forecast, r.seats) }}</small><span v-if="r.over" class="md-over" :title="L(`About ${r.over} more passengers than seats: reinforce`, `Unos ${r.over} pasajeros más que asientos: reforzar`)">↑{{ r.over }}</span></td>
                <td v-if="!data.past" class="c" :class="{ 'md-miss': r.missing > 0 }">{{ r.missing == null ? '—' : (r.missing || '✓') }}</td>
                <template v-if="data.money">
                  <td v-for="c in INCOME_COLS" :key="c.key" class="r md-cost">{{ r.income == null ? '—' : money0(r.income_parts?.[c.key]) }}</td>
                  <td class="r md-cost-t">{{ r.income == null ? '—' : money0(r.income) }}</td>
                  <td v-for="k in costCols" :key="k.key" class="r md-cost" :class="{ 'md-hand': k.key === 'fuel' }">{{ costOf(r, k) }}</td>
                  <td class="r md-cost-t">{{ r.cost == null ? '—' : money0(r.cost) }}</td>
                  <td class="r"><b v-if="r.result != null" :class="r.result < 0 ? 'md-neg' : 'md-pos'">{{ r.result < 0 ? '−' : '+' }}{{ money0(Math.abs(r.result)) }}</b><span v-else>—</span></td>
                </template>
              </tr>
              </template>
            </template>
            <tr v-if="!rows.length"><td :colspan="colCount" class="md-empty">{{ L('No departures in these days yet.', 'Aún no hay salidas en estos días.') }}</td></tr>
          </tbody>
          <tfoot v-if="rows.length">
            <tr>
              <td colspan="2">Total</td>
              <td class="c">{{ s.seats }}</td>
              <td v-for="k in channelKeys" :key="k" class="c">{{ s.channels?.[k] || 0 }}</td>
              <td class="c">{{ s.sold }}</td>
              <td class="c">{{ s.forecast }}</td>
              <td v-if="!data.past" class="c">{{ s.missing }}</td>
              <template v-if="data.money">
                <td v-for="c in INCOME_COLS" :key="c.key" class="r">{{ money0(rows.reduce((n, r) => n + Number(r.income_parts?.[c.key] || 0), 0)) }}</td>
                <td class="r">{{ money0(s.income) }}</td>
                <td v-for="k in costCols" :key="k.key" class="r" :class="{ 'md-hand': k.key === 'fuel' }">{{ money0(rows.reduce((n, r) => n + costRaw(r, k), 0)) }}</td>
                <td class="r">{{ money0(s.cost) }}</td>
                <td class="r"><b :class="s.result < 0 ? 'md-neg' : 'md-pos'">{{ signed(s.result) }}</b></td>
              </template>
            </tr>
          </tfoot>
        </table>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import dayjs from 'dayjs';
import { LeftOutlined, RightOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import { noDataHelp } from '../lib/transportNoData';
import { INCOME_COLS } from '../lib/moneyColumns';
import http from '../lib/http';
import { useUiStore } from '../stores/ui';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const L = (e, s) => (es.value ? s : e);
const data = ref({ rows: [], summary: {}, money: false });
const loading = ref(false);
const today = dayjs().format('YYYY-MM-DD');
// The date asked for: in this week or later = today and the next days; in an earlier week = that week as it went.
const pick = ref(today);
async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/programming/outlook', { days: 3, ...(pick.value < today ? { start: pick.value } : {}) }); } catch (e) { /* the board shows empty */ }
  loading.value = false;
}
onMounted(load);
// ‹ › a week at a time, from the week shown (never past the current one).
function shift(n) {
  const base = data.value.past ? data.value.from : today;
  const d = dayjs(base).add(n, 'day').format('YYYY-MM-DD');
  pick.value = d > today ? today : d;
  load();
}
function openWeek(w) {
  if (w.from > today) return;
  pick.value = w.from <= today && w.to >= today ? today : w.from;
  load();
}

// The direction (2026-11-27): both, each one under its own heading with its result, or only one.
const SIDES = [{ key: 'lima', en: 'Lima → Chiclayo', es: 'Lima → Chiclayo' }, { key: 'chiclayo', en: 'Chiclayo → Lima', es: 'Chiclayo → Lima' }];
const sideOf = k => SIDES.find(x => x.key === k) || SIDES[0];
const dir = ref('all');
const dirOptions = computed(() => [{ value: 'all', label: L('Both', 'Ambas') }, ...SIDES.map(x => ({ value: x.key, label: L(x.en, x.es) }))]);
const weekName = (w, i) => {
  if (!data.value.past) return [L('This week', 'Esta semana'), L('Next week', 'Próxima semana'), L('The week after', 'Subsiguiente')][i];
  return w.from === data.value.from ? L('Chosen week', 'Semana elegida') : (i === 0 ? L('Two weeks before', 'Dos semanas antes') : L('Week before', 'Semana anterior'));
};
const signed = (v, cur = true) => `${Number(v || 0) < 0 ? '−' : '+'}${cur ? 'S/ ' : ''}${money0(Math.abs(Number(v || 0)))}`;

const rows = computed(() => (data.value.rows || []).filter(r => dir.value === 'all' || r.side === dir.value));
const s = computed(() => (dir.value === 'all' ? data.value.summary : data.value.summary?.sides?.[dir.value]) || {});
// Agency first, then the online channels; PeruBus always shown (the user asked for it).
const CH = { Agencia: ['Agency', 'Agencia'], Web: ['Web', 'Web'], RedBus: ['RedBus', 'RedBus'], Kupos: ['Kupos', 'Kupos'], PeruBus: ['PeruBus', 'PeruBus'], Kiosco: ['Kiosk', 'Kiosco'], Carritoc: ['Carritoc', 'Carritoc'] };
const chName = k => L(...(CH[k] || [k, k]));
// "CHICLAYO → LIMA" -> "CIX → LIM" so the table fits one screen (the full name on hover).
const CODES = { LIMA: 'LIM', CHICLAYO: 'CIX', TRUJILLO: 'TRU', CHEPEN: 'CHE', 'CHEPÉN': 'CHE' };
const shortRoute = r => String(r || '').split('→').map(x => CODES[x.trim()] || x.trim().slice(0, 3)).join(' → ');
const channelKeys = computed(() => {
  const seen = new Set(Object.keys(s.value.channels || {}));
  return Object.keys(CH).filter(k => seen.has(k) || ['Agencia', 'Web', 'PeruBus'].includes(k));
});
// The expenses of a departure by kind (TransportProfit's, averaged per route); the small ones together in "Otros".
const costCols = [
  { key: 'fuel', en: 'Fuel', es: 'Comb.', keys: ['fuel'] },
  { key: 'tolls', en: 'Tolls', es: 'Peajes', keys: ['tolls'] },
  { key: 'payroll', en: 'Staff', es: 'Personal', keys: ['payroll', 'staff'] },
  { key: 'depreciation', en: 'Deprec.', es: 'Deprec.', keys: ['depreciation'] },
  { key: 'other', en: 'Other', es: 'Otros', keys: ['maintenance', 'agency', 'meals'] },
];
const FUEL_HELP = computed(() => L('This figure is typed by hand: AuraSoft (Jelaf) brings no fuel. It is what Mayte notes per round trip in her Excel (October and 1-23 November), half on each leg; a departure without it uses its bus\'s cost per km × the route\'s km, and the coming days the route\'s average of the last 30 days.',
  'Esta información es ingresada a mano: AuraSoft (Jelaf) no trae combustible. Es lo que Mayte anota por vuelta en su Excel (octubre y del 1 al 23 de noviembre), mitad a cada tramo; una salida sin ese dato usa el costo por km de su bus × los km de la ruta, y los próximos días el promedio de la ruta en los últimos 30 días.'));
// A cost column with nothing in any row = no data for it yet (shown 0 with its helper).
const colEmpty = k => !rows.value.some(r => costRaw(r, k) > 0);
const costRaw = (r, k) => k.keys.reduce((n, x) => n + Number(r.costs?.[x] || 0), 0);
const costOf = (r, k) => (r.costs ? money0(costRaw(r, k)) : '—');
const colCount = computed(() => (data.value.past ? 5 : 6) + channelKeys.value.length + (data.value.money ? 3 + INCOME_COLS.length + costCols.length : 0));
// Per day, and inside each day per direction (Lima → Chiclayo first) with its subtotal.
const groups = computed(() => {
  const out = [];
  for (const r of rows.value) {
    let g = out.find(x => x.date === r.date);
    if (!g) { g = { date: r.date, label: r.day, count: 0, sides: [] }; out.push(g); }
    let sg = g.sides.find(x => x.key === r.side);
    if (!sg) { sg = { key: r.side, rows: [], loss: 0, result: 0 }; g.sides.push(sg); }
    sg.rows.push(r);
    sg.loss += r.result != null && r.result < 0 ? 1 : 0;
    sg.result += Number(r.result || 0);
    g.count++;
  }
  out.forEach(g => g.sides.sort((a, b) => (a.key === 'lima' ? 0 : 1) - (b.key === 'lima' ? 0 : 1)));
  return out;
});
const ampm = t => (t ? dayjs(`2000-01-01 ${t}`).format('h:mm A') : '');
const pct = (a, b) => (b ? `${Math.round((a / b) * 100)} %` : '');
const dm = d => dayjs(d).format('DD/MM');
const money0 = v => Number(v || 0).toLocaleString('es-PE', { maximumFractionDigits: 0 });

</script>

<style scoped>
/* Power BI-like soft colours (2026-11-27: navy and orange only on the tabs). */
.md-wrap { overflow: auto; max-height: calc(100vh - 330px); border: 1px solid #e1e4e8; border-radius: 8px; }
.md-t { width: 100%; border-collapse: collapse; font-size: 11px; color: #252423; }
.md-t thead th { position: sticky; top: 0; background: #f3f4f6; color: #252423; font-weight: 600; font-size: 12px; font-weight: 700; padding: 5px 3px; text-align: left; white-space: nowrap; z-index: 1; border-bottom: 1px solid #e1e4e8; }
.md-t thead tr:nth-child(2) th { top: 29px; font-weight: 500; font-size: 10px; }
.md-t th.r, .md-t td.r { text-align: right; }
.md-t th.c, .md-t td.c { text-align: center; }
.md-t th.md-g-sold { background: #e8f2fe; color: #0b5cad; }
.md-t th.md-g-cost { background: #fdeee8; color: #a5401a; }
.md-t th.md-g-inc { background: #eaf7ee; color: #1f7a45; }
.md-t td { padding: 3px 3px; border-bottom: 1px solid #f0f1f3; white-space: nowrap; font-variant-numeric: tabular-nums; }
.md-t tbody tr:hover td { background: #f5f8fc; }
.md-t tbody tr.md-loss td { background: rgba(214, 69, 80, 0.1); }
.md-t tbody tr.md-loss:hover td { background: rgba(214, 69, 80, 0.16); }
.md-day td { background: #f3f6fb !important; color: #12239e; font-weight: 700; padding: 6px 8px; }
.md-day small { color: #8a94a6; font-weight: 500; }
.md-time { font-weight: 700; color: #252423; }
.md-route { font-size: 11.5px; }
.md-tent { color: #8a94a6; font-style: italic; }
.md-tag { color: #8a94a6; font-size: 10px; }
.md-ch { color: #605e5c; }
.md-cost { color: #605e5c; }
.md-cost-t { font-weight: 600; }
.md-pct { color: #98a2b3; font-size: 10.5px; margin-left: 5px; }
.md-over { margin-left: 5px; color: #e66c37; font-weight: 700; font-size: 11px; cursor: help; }
.md-miss { color: #d64550; font-weight: 700; }
.md-neg { color: #d64550; }
.md-pos { color: #1aab40; }
.md-t tfoot td { position: sticky; bottom: 0; background: #eef1f5; font-weight: 700; padding: 6px 8px; border-top: 2px solid #d0d5dd; }
.md-empty { text-align: center; color: #98a2b3; padding: 24px; }
.md-weeks { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 8px; }
.md-week { border: 1px solid #e1e4e8; border-radius: 8px; padding: 6px 10px; background: #fbfcfe; }
.md-week-h { font-size: 12.5px; color: #12239e; }
.md-week-h span { color: #8a94a6; margin-left: 4px; }
.md-week-b { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 12px; color: #475467; margin-top: 2px; }
.md-week-n { font-size: 10.5px; color: #98a2b3; margin-top: 2px; }
.md-bad { color: #d64550; }
.md-good { color: #1aab40; }
@media (max-width: 900px) { .md-weeks { grid-template-columns: 1fr; } }.md-bar { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; flex-wrap: wrap; }
.md-date { width: 130px; }
.md-period { font-size: 12.5px; color: #12239e; font-weight: 600; margin-left: 6px; }
.md-dir { margin-left: auto; }
.md-week { cursor: pointer; }
.md-week.on { border-color: #118dff; background: #eef5ff; }
.md-week-t { color: #605e5c; font-size: 11.5px; margin-left: 6px; }
.md-week-s { display: flex; gap: 10px; font-size: 12px; color: #475467; align-items: baseline; }
.md-week-s.dim { opacity: .35; }
.md-week-r { font-weight: 600; color: #252423; min-width: 108px; }
.md-week-s b { margin-left: auto; }
.md-side td { background: #fafbfc !important; color: #252423; font-weight: 700; font-size: 11.5px; padding: 4px 8px 4px 16px; border-bottom: 1px solid #e1e4e8; }
.md-side small { color: #8a94a6; font-weight: 500; }
.md-t .md-hand { color: #d64550 !important; font-weight: 600; }
.md-t th.md-hand { cursor: help; }
.md-nodata { cursor: help; color: #98a2b3; border-bottom: 1px dotted #98a2b3; }
</style>
