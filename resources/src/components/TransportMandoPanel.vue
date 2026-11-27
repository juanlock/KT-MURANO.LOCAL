<template>
  <!-- Mando operativo on Jelaf's data (the user, 2026-11-26): today and the next 2 days, both directions, what each departure
       has sold by channel, the passengers expected, its projected income, its expenses and the possible profit or loss.
       App\Services\TransportProgramming::outlook. -->
  <div class="md">
    <a-spin :spinning="loading">
      <TransportStrip :items="tiles" :toggle="false" />
      <!-- The weeks ahead (Jelaf sells up to 14 days ahead): this week, the next and the one after. -->
      <div class="md-weeks">
        <div v-for="(w, i) in data.weeks || []" :key="w.from" class="md-week">
          <div class="md-week-h"><b>{{ [L('This week', 'Esta semana'), L('Next week', 'Próxima semana'), L('The week after', 'Subsiguiente')][i] }}</b> <span>{{ dm(w.from) }} – {{ dm(w.to) }}</span></div>
          <div class="md-week-b">
            <span><b>{{ w.departures }}</b> {{ L('departures', 'salidas') }}</span>
            <span><b>{{ w.sold }}</b> {{ L('sold', 'vendidos') }}</span>
            <span><b>{{ pct(w.forecast, w.seats) }}</b> {{ L('expected', 'esperada') }}</span>
            <span :class="{ 'md-bad': w.loss }"><b>{{ w.loss }}</b> {{ L('at a loss', 'en pérdida') }}</span>
            <span v-if="data.money" :class="w.result < 0 ? 'md-bad' : 'md-good'"><b>{{ w.result < 0 ? '−' : '+' }}S/ {{ money0(Math.abs(w.result)) }}</b></span>
          </div>
          <div v-if="w.days_loaded < 7" class="md-week-n">{{ L(`${w.days_loaded} of 7 days with sales loaded`, `${w.days_loaded} de 7 días con ventas cargadas`) }}</div>
        </div>
      </div>
      <!-- The table (the user, 2026-11-27): soft Power BI colours (navy / orange only on the tabs), seats centred, "Pasajes
           vendidos por medio" and "Egresos" as groups with their columns, the estimated profit / loss last, a loss row on 10 % red. -->
      <div class="md-wrap">
        <table class="md-t">
          <thead>
            <tr>
              <th rowspan="2">{{ L('Time', 'Hora') }}</th>
              <th rowspan="2">{{ L('Route', 'Ruta') }}</th>
              <th rowspan="2">Bus</th>
              <th rowspan="2" class="c">{{ L('Seats', 'Asient.') }}</th>
              <th :colspan="channelKeys.length + 1" class="c md-g-sold">{{ L('Tickets sold by channel', 'Pasajes vendidos por medio') }}</th>
              <th rowspan="2" class="c" :title="L('Passengers expected when it leaves (the 2026 sales curve)', 'Pasajeros que se esperan cuando salga (curva de ventas de 2026)')">{{ L('Expected', 'Esperados') }}</th>
              <th rowspan="2" class="c" :title="L('Tickets still to sell to cover its expenses', 'Pasajes que faltan vender para cubrir sus egresos')">{{ L('To cover', 'Faltan') }}</th>
              <template v-if="data.money">
                <th rowspan="2" class="r" :title="L('Expected passengers × its ticket price', 'Esperados × el precio de su pasaje')">{{ L('Income', 'Ingresos') }}</th>
                <th :colspan="costCols.length + 1" class="c md-g-cost" :title="L('What this trip will spend: the average of its route over the last 30 days', 'Lo que gastará este viaje: el promedio de su ruta en los últimos 30 días')">{{ L('Expenses', 'Egresos') }}</th>
                <th rowspan="2" class="r md-res" :title="L('Estimated: income − expenses', 'Estimada: ingresos − egresos')">{{ L('Profit /', 'Ganancia /') }}<br>{{ L('Loss', 'Pérdida') }}</th>
              </template>
            </tr>
            <tr>
              <th v-for="k in channelKeys" :key="k" class="c md-sub md-g-sold">{{ chName(k) }}</th>
              <th class="c md-sub md-g-sold">Total</th>
              <template v-if="data.money">
                <th v-for="k in costCols" :key="k.key" class="r md-sub md-g-cost">{{ L(k.en, k.es) }}</th>
                <th class="r md-sub md-g-cost">Total</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template v-for="g in groups" :key="g.date">
              <tr class="md-day"><td :colspan="colCount">{{ g.label }} <small>· {{ g.rows.length }} {{ L('departures', 'salidas') }}</small></td></tr>
              <tr v-for="r in g.rows" :key="g.date + r.time + r.side + r.route" :class="{ 'md-loss': r.result != null && r.result < 0 }">
                <td class="md-time">{{ ampm(r.time) }}</td>
                <td class="md-route" :title="r.route">{{ shortRoute(r.route) }}</td>
                <td><span :class="{ 'md-tent': r.bus_from === 'base' }">{{ r.bus || '—' }}</span> <small v-if="r.tag" class="md-tag">{{ r.tag }}</small></td>
                <td class="c">{{ r.seats || '—' }}</td>
                <td v-for="k in channelKeys" :key="k" class="c md-ch">{{ r.channels?.[k]?.n || '' }}</td>
                <td class="c"><b>{{ r.sold }}</b></td>
                <td class="c">{{ r.forecast }}<small v-if="r.seats" class="md-pct">{{ pct(r.forecast, r.seats) }}</small><span v-if="r.over" class="md-over" :title="L(`About ${r.over} more passengers than seats: reinforce`, `Unos ${r.over} pasajeros más que asientos: reforzar`)">↑{{ r.over }}</span></td>
                <td class="c" :class="{ 'md-miss': r.missing > 0 }">{{ r.missing == null ? '—' : (r.missing || '✓') }}</td>
                <template v-if="data.money">
                  <td class="r">{{ r.income == null ? '—' : money0(r.income) }}</td>
                  <td v-for="k in costCols" :key="k.key" class="r md-cost">{{ costOf(r, k) }}</td>
                  <td class="r md-cost-t">{{ r.cost == null ? '—' : money0(r.cost) }}</td>
                  <td class="r"><b v-if="r.result != null" :class="r.result < 0 ? 'md-neg' : 'md-pos'">{{ r.result < 0 ? '−' : '+' }}{{ money0(Math.abs(r.result)) }}</b><span v-else>—</span></td>
                </template>
              </tr>
            </template>
            <tr v-if="!rows.length"><td :colspan="colCount" class="md-empty">{{ L('No departures in these days yet.', 'Aún no hay salidas en estos días.') }}</td></tr>
          </tbody>
          <tfoot v-if="rows.length">
            <tr>
              <td colspan="3">Total</td>
              <td class="c">{{ s.seats }}</td>
              <td v-for="k in channelKeys" :key="k" class="c">{{ s.channels?.[k] || 0 }}</td>
              <td class="c">{{ s.sold }}</td>
              <td class="c">{{ s.forecast }}</td>
              <td class="c">{{ s.missing }}</td>
              <template v-if="data.money">
                <td class="r">{{ money0(s.income) }}</td>
                <td v-for="k in costCols" :key="k.key" class="r">{{ money0(rows.reduce((n, r) => n + costRaw(r, k), 0)) }}</td>
                <td class="r">{{ money0(s.cost) }}</td>
                <td class="r"><b :class="s.result < 0 ? 'md-neg' : 'md-pos'">{{ s.result < 0 ? '−' : '+' }} S/ {{ money0(Math.abs(s.result)) }}</b></td>
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
import http from '../lib/http';
import TransportStrip from './TransportStrip.vue';
import { useUiStore } from '../stores/ui';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const L = (e, s) => (es.value ? s : e);
const data = ref({ rows: [], summary: {}, money: false });
const loading = ref(false);
onMounted(async () => {
  loading.value = true;
  try { data.value = await http.get('transport/programming/outlook', { days: 3 }); } catch (e) { /* the board shows empty */ }
  loading.value = false;
});

const rows = computed(() => data.value.rows || []);
const s = computed(() => data.value.summary || {});
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
  { key: 'payroll', en: 'Payroll', es: 'Planilla', keys: ['payroll', 'staff'] },
  { key: 'depreciation', en: 'Deprec.', es: 'Deprec.', keys: ['depreciation'] },
  { key: 'other', en: 'Other', es: 'Otros', keys: ['maintenance', 'agency', 'meals'] },
];
const costRaw = (r, k) => k.keys.reduce((n, x) => n + Number(r.costs?.[x] || 0), 0);
const costOf = (r, k) => (r.costs ? (costRaw(r, k) ? money0(costRaw(r, k)) : '—') : '—');
const colCount = computed(() => 7 + channelKeys.value.length + (data.value.money ? 3 + costCols.length + 0 : 0));
const groups = computed(() => {
  const out = [];
  for (const r of rows.value) {
    let g = out.find(x => x.date === r.date);
    if (!g) { g = { date: r.date, label: r.day, rows: [] }; out.push(g); }
    g.rows.push(r);
  }
  return out;
});
const ampm = t => (t ? dayjs(`2000-01-01 ${t}`).format('h:mm A') : '');
const pct = (a, b) => (b ? `${Math.round((a / b) * 100)} %` : '');
const dm = d => dayjs(d).format('DD/MM');
const money0 = v => Number(v || 0).toLocaleString('es-PE', { maximumFractionDigits: 0 });
const tiles = computed(() => {
  const x = s.value;
  const t = [
    { label: L('Departures', 'Salidas'), value: x.departures ?? 0 },
    { label: L('At a loss', 'En pérdida'), value: x.loss ?? 0 },
    { label: L('Sold', 'Vendidos'), value: x.sold ?? 0 },
    { label: L('Expected occupancy', 'Ocupación esperada'), value: x.seats ? `${Math.round((x.forecast / x.seats) * 100)} %` : '—' },
    { label: L('Tickets to cover', 'Pasajes que faltan'), value: x.missing ?? 0 },
  ];
  if (data.value.money) {
    t.push({ label: L('Projected income', 'Ingresos proyectados'), value: `S/ ${money0(x.income)}` });
    t.push({ label: L('Possible result', 'Posible resultado'), value: `${x.result < 0 ? '−' : ''}S/ ${money0(Math.abs(x.result || 0))}` });
  }
  return t;
});
</script>

<style scoped>
/* Power BI-like soft colours (2026-11-27: navy and orange only on the tabs). */
.md-wrap { overflow: auto; max-height: calc(100vh - 400px); border: 1px solid #e1e4e8; border-radius: 8px; }
.md-t { width: 100%; border-collapse: collapse; font-size: 12px; color: #252423; }
.md-t thead th { position: sticky; top: 0; background: #f3f4f6; color: #252423; font-weight: 600; font-size: 11px; padding: 5px 6px; text-align: left; white-space: nowrap; z-index: 1; border-bottom: 1px solid #e1e4e8; }
.md-t thead tr:nth-child(2) th { top: 29px; font-weight: 500; font-size: 11px; }
.md-t th.r, .md-t td.r { text-align: right; }
.md-t th.c, .md-t td.c { text-align: center; }
.md-t th.md-g-sold { background: #e8f2fe; color: #0b5cad; }
.md-t th.md-g-cost { background: #fdeee8; color: #a5401a; }
.md-t td { padding: 3px 5px; border-bottom: 1px solid #f0f1f3; white-space: nowrap; font-variant-numeric: tabular-nums; }
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
@media (max-width: 900px) { .md-weeks { grid-template-columns: 1fr; } }</style>
