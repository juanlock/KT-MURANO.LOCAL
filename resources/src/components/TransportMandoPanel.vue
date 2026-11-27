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
      <div class="md-wrap">
        <table class="md-t">
          <thead>
            <tr>
              <th rowspan="2">{{ L('Departure', 'Salida') }}</th>
              <th rowspan="2">{{ L('Route', 'Ruta') }}</th>
              <th rowspan="2">Bus</th>
              <th rowspan="2" class="r">{{ L('Seats', 'Asientos') }}</th>
              <th :colspan="channelKeys.length + 1" class="c md-grp">{{ L('Sold', 'Vendidos') }}</th>
              <th rowspan="2" class="r" :title="L('Passengers expected when it leaves (the 2026 sales curve)', 'Pasajeros que se esperan cuando salga (curva de ventas de 2026)')">{{ L('Expected', 'Esperados') }}</th>
              <th rowspan="2" class="r" :title="L('Tickets still to sell to cover its expenses', 'Pasajes que faltan vender para cubrir sus egresos')">{{ L('To cover', 'Faltan') }}</th>
              <template v-if="data.money">
                <th rowspan="2" class="r" :title="L('Expected passengers × its ticket price', 'Esperados × el precio de su pasaje')">{{ L('Income', 'Ingresos') }}</th>
                <th rowspan="2" class="r" :title="L('The average expenses of its route over the last 30 days', 'Los egresos promedio de su ruta en los últimos 30 días')">{{ L('Expenses', 'Egresos') }}</th>
                <th rowspan="2" class="r">{{ L('Possible profit / loss', 'Posible ganancia / pérdida') }}</th>
              </template>
            </tr>
            <tr>
              <th v-for="k in channelKeys" :key="k" class="r md-sub">{{ chName(k) }}</th>
              <th class="r md-sub">Total</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="g in groups" :key="g.date">
              <tr class="md-day"><td :colspan="colCount">{{ g.label }} <small>· {{ g.rows.length }} {{ L('departures', 'salidas') }}</small></td></tr>
              <tr v-for="r in g.rows" :key="g.date + r.time + r.side + r.route">
                <td class="md-time">{{ ampm(r.time) }}</td>
                <td class="md-route">{{ r.route }}</td>
                <td><span :class="{ 'md-tent': r.bus_from === 'base' }">{{ r.bus || '—' }}</span> <small v-if="r.tag" class="md-tag">{{ r.tag }}</small></td>
                <td class="r">{{ r.seats || '—' }}</td>
                <td v-for="k in channelKeys" :key="k" class="r md-ch">{{ r.channels?.[k]?.n || '' }}</td>
                <td class="r"><b>{{ r.sold }}</b></td>
                <td class="r">{{ r.forecast }}<small v-if="r.seats" class="md-pct">{{ pct(r.forecast, r.seats) }}</small><span v-if="r.over" class="md-over" :title="L(`About ${r.over} more passengers than seats: reinforce`, `Unos ${r.over} pasajeros más que asientos: reforzar`)">↑{{ r.over }}</span></td>
                <td class="r" :class="{ 'md-miss': r.missing > 0 }">{{ r.missing == null ? '—' : (r.missing || '✓') }}</td>
                <template v-if="data.money">
                  <td class="r">{{ r.income == null ? '—' : 'S/ ' + money0(r.income) }}</td>
                  <td class="r">{{ r.cost == null ? '—' : 'S/ ' + money0(r.cost) }}</td>
                  <td class="r"><span v-if="r.result != null" class="md-res" :class="r.result < 0 ? 'neg' : 'pos'">{{ r.result < 0 ? '−' : '+' }} S/ {{ money0(Math.abs(r.result)) }}</span><span v-else>—</span></td>
                </template>
              </tr>
            </template>
            <tr v-if="!rows.length"><td :colspan="colCount" class="md-empty">{{ L('No departures in these days yet.', 'Aún no hay salidas en estos días.') }}</td></tr>
          </tbody>
          <tfoot v-if="rows.length">
            <tr>
              <td colspan="3">Total</td>
              <td class="r">{{ s.seats }}</td>
              <td v-for="k in channelKeys" :key="k" class="r">{{ s.channels?.[k] || 0 }}</td>
              <td class="r">{{ s.sold }}</td>
              <td class="r">{{ s.forecast }}</td>
              <td class="r">{{ s.missing }}</td>
              <template v-if="data.money">
                <td class="r">S/ {{ money0(s.income) }}</td>
                <td class="r">S/ {{ money0(s.cost) }}</td>
                <td class="r"><span class="md-res" :class="s.result < 0 ? 'neg' : 'pos'">{{ s.result < 0 ? '−' : '+' }} S/ {{ money0(Math.abs(s.result)) }}</span></td>
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
const channelKeys = computed(() => {
  const seen = new Set(Object.keys(s.value.channels || {}));
  return Object.keys(CH).filter(k => seen.has(k) || ['Agencia', 'Web', 'PeruBus'].includes(k));
});
const colCount = computed(() => 7 + channelKeys.value.length + (data.value.money ? 3 : 0));
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
.md-wrap { overflow: auto; max-height: calc(100vh - 400px); border: 1px solid #e3e8ef; border-radius: 10px; }
.md-t { width: 100%; border-collapse: collapse; font-size: 12.5px; color: #344054; }
.md-t thead th { position: sticky; top: 0; background: #152d4a; color: #fff; font-weight: 600; font-size: 11.5px; padding: 6px 8px; text-align: left; white-space: nowrap; z-index: 1; }
.md-t thead tr:nth-child(2) th { top: 29px; background: #27507d; font-weight: 500; }
.md-t th.r, .md-t td.r { text-align: right; }
.md-t th.c { text-align: center; }
.md-t th.md-grp { background: #f28c13; }
.md-t td { padding: 4px 8px; border-bottom: 1px solid #f0f2f5; white-space: nowrap; font-variant-numeric: tabular-nums; }
.md-t tbody tr:hover td { background: #f5f8fc; }
.md-day td { background: #fff6ea !important; color: #152d4a; font-weight: 700; padding: 6px 8px; border-top: 1px solid #f6d7ae; }
.md-day small { color: #8a94a6; font-weight: 500; }
.md-time { font-weight: 700; color: #152d4a; }
.md-route { font-size: 11.5px; }
.md-tent { color: #8a94a6; font-style: italic; }
.md-tag { color: #8a94a6; font-size: 10px; }
.md-ch { color: #667085; }
.md-pct { color: #98a2b3; font-size: 10.5px; margin-left: 5px; }
.md-over { margin-left: 5px; color: #c46a06; font-weight: 700; font-size: 11px; cursor: help; }
.md-weeks { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 8px; }
.md-week { border: 1px solid #e3e8ef; border-radius: 8px; padding: 6px 10px; background: #fbfcfe; }
.md-week-h { font-size: 12.5px; color: #152d4a; }
.md-week-h span { color: #8a94a6; margin-left: 4px; }
.md-week-b { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 12px; color: #475467; margin-top: 2px; }
.md-week-n { font-size: 10.5px; color: #98a2b3; margin-top: 2px; }
.md-bad { color: #b42318; }
.md-good { color: #1f7a45; }
@media (max-width: 900px) { .md-weeks { grid-template-columns: 1fr; } }
.md-miss { color: #b42318; font-weight: 700; }
.md-res { display: inline-block; min-width: 92px; padding: 1px 8px; border-radius: 5px; color: #fff; font-weight: 700; }
.md-res.neg { background: #d92d20; }
.md-res.pos { background: #2e9e5b; }
.md-t tfoot td { position: sticky; bottom: 0; background: #eef2f7; font-weight: 700; padding: 6px 8px; border-top: 2px solid #c9d2de; }
.md-empty { text-align: center; color: #98a2b3; padding: 24px; }
</style>
