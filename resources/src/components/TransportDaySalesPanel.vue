<template>
  <!-- "Reporte del día de ventas" (2026-11-23): Mayte's daily report as a board -- one day, Lima's and Chiclayo's departures
       side by side, as on her sheet (App\Services\TransportProduction::daySales). -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <div class="ds-day">
          <a-button size="small" :disabled="!data.prev" @click="go(data.prev)"><LeftOutlined /></a-button>
          <b>{{ data.date ? longDate(data.date) : '—' }}</b>
          <a-button size="small" :disabled="!data.next" @click="go(data.next)"><RightOutlined /></a-button>
          <a-tooltip :title="tr('The end date of the filter above (today at most); the arrows go to the day before / after with departures.', 'La fecha final del filtro de arriba (como máximo hoy); las flechas van al día anterior / siguiente con salidas.')">
            <InfoCircleOutlined class="ds-i" />
          </a-tooltip>
        </div>
        <TransportStrip :items="tiles" :toggle="false" />
        <div class="ds-grid">
          <div v-for="h in halves" :key="h.key" class="ds-half">
            <div class="ds-head" :class="'ds-' + h.key">{{ h.title }} <span>{{ h.rows.length }} {{ tr('departures', 'salidas') }}</span></div>
            <a-empty v-if="!h.rows.length" :description="tr('No departures.', 'Sin salidas.')" />
            <table v-else class="ds-table">
              <colgroup><col v-for="(w, k) in colWidths(h)" :key="k" :style="{ width: w + '%' }" /></colgroup>
              <thead>
                <tr>
                  <th>Bus</th><th>{{ tr('Boarding', 'Embarque') }}</th><th>{{ tr('Time', 'Horario') }}</th><th class="r">Pax</th><th class="r">{{ tr('Free', 'Libres') }}</th>
                  <th class="r">{{ tr('Amount', 'Monto') }}</th><th class="r">{{ tr('Parcels', 'Encom.') }}</th><th class="r">{{ tr('Excess', 'Exceso') }}</th>
                  <th v-if="h.costs" class="r">{{ tr('Toll', 'Peaje') }}</th><th v-if="h.costs" class="r">{{ tr('Fuel', 'Comb.') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in h.rows" :key="r.id" :class="{ tint: i % 2 === 0 }">
                  <td><b>{{ busName(r.bus) }}</b><a-tooltip v-if="r.source" :title="sourceLabel(r.source)"><span class="ds-src">{{ r.source === 'aurasoft' ? 'A' : r.source === 'report' ? 'R' : '' }}</span></a-tooltip></td>
                  <td><div v-for="(p, k) in r.points" :key="k" class="ln">{{ p.name || '—' }}</div></td>
                  <td><div v-for="(p, k) in r.points" :key="k" class="ln">{{ hm(p.at) }}</div></td>
                  <td class="r">{{ r.pax }}<span v-if="r.seats" class="mut">/{{ r.seats }}</span></td>
                  <td class="r">{{ r.free ?? '—' }}</td>
                  <td class="r"><b :class="{ low: r.amount < lowAmount }">{{ plain(r.amount) }}</b></td>
                  <td class="r">{{ r.parcels ? plain(r.parcels) : '—' }}</td>
                  <td class="r">{{ r.excess ? plain(r.excess) : '—' }}</td>
                  <td v-if="h.costs" class="r">{{ r.toll != null ? plain(r.toll) : '—' }}</td>
                  <td v-if="h.costs" class="r">{{ r.fuel != null ? plain(r.fuel) : '—' }}</td>
                </tr>
              </tbody>
            </table>
            <div v-if="h.channels.length" class="ds-chips">
              <span class="mut">{{ tr('Seats by seller (AuraSoft)', 'Pasajes por vendedor (AuraSoft)') }}:</span>
              <span v-for="c in h.channels" :key="c[0]" class="chip">{{ c[0] }} <b>{{ c[1] }}</b></span>
            </div>
            <!-- The totals at the bottom of both halves, on the same line (user, 2026-11-23). -->
            <div class="ds-fill" />
            <table v-if="h.rows.length" class="ds-table ds-foot">
              <colgroup><col v-for="(w, k) in colWidths(h)" :key="k" :style="{ width: w + '%' }" /></colgroup>
              <tfoot>
                <tr>
                  <td colspan="3"><b>{{ tr('Total', 'Total') }}</b></td>
                  <td class="r"><b>{{ sum(h.rows, 'pax') }}</b></td><td class="r"><b>{{ sum(h.rows, 'free') }}</b></td>
                  <td class="r"><b>{{ plain(sum(h.rows, 'amount')) }}</b></td><td class="r"><b>{{ plain(sum(h.rows, 'parcels')) }}</b></td>
                  <td class="r"><b>{{ plain(sum(h.rows, 'excess')) }}</b></td>
                  <td v-if="h.costs" class="r"><b>{{ plain(sum(h.rows, 'toll')) }}</b></td><td v-if="h.costs" class="r"><b>{{ plain(sum(h.rows, 'fuel')) }}</b></td>
                </tr>
              </tfoot>
            </table>

          </div>
        </div>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import 'dayjs/locale/es';
import { LeftOutlined, RightOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useTransportCharts } from '../lib/transportCharts';
import { useFormat } from '../composables/useFormat';
import TransportStrip from './TransportStrip.vue';

const props = defineProps({ range: { type: Array, default: null } });
const { money, tr, ui } = useTransportCharts();
const { date: fmtDate } = useFormat();
const lowAmount = 2000; // as Reporte de salidas (TransportDepartures::LOW_AMOUNT)

const loading = ref(false);
const day = ref(null);
const data = ref({ enabled: false, lima: [], chiclayo: [], channels: {} });
async function load() {
  loading.value = true;
  try {
    const today = dayjs().format('YYYY-MM-DD');
    const end = day.value || (props.range?.[1] && props.range[1] < today ? props.range[1] : today);
    data.value = await http.get('transport/day-sales', { date: end });
  } catch (e) { data.value = { enabled: false, lima: [], chiclayo: [], channels: {} }; } finally { loading.value = false; }
}
function go(d) { day.value = d; load(); }
onMounted(load);
watch(() => props.range?.[1], () => { day.value = null; load(); });

const plain = v => money(Number(v || 0)).replace(/^[^\d-]+/, '');
const busName = n => String(n).replace(/^Bus\s+/i, '');
const hm = d => (d ? dayjs(d).format('hh:mm A') : '');
const sum = (rows, k) => rows.reduce((s, r) => s + Number(r[k] || 0), 0);
const longDate = d => `${dayjs(d).locale(String(ui.locale || 'en').startsWith('es') ? 'es' : 'en').format('dddd')} ${fmtDate(d)}`;
const sourceLabel = s => ({ aurasoft: tr('Figures from AuraSoft', 'Cifras de AuraSoft'), report: tr("From the client's daily report", 'Del reporte del día del cliente') }[s] || s);

// The same column widths for the rows and the totals table below them.
const colWidths = h => (h.costs ? [9, 14, 12, 8, 6, 12, 8, 8, 11, 12] : [10, 18, 15, 11, 8, 14, 12, 12]);
const chans = k => Object.entries(data.value.channels?.[k] || {}).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]);
const halves = computed(() => [
  { key: 'lima', title: tr('Departures from Lima', 'Salidas de Lima'), rows: data.value.lima || [], channels: chans('lima') },
  { key: 'chiclayo', title: tr('Departures from Chiclayo', 'Salidas de Chiclayo'), rows: data.value.chiclayo || [], channels: chans('chiclayo') },
].map(h => ({ ...h, costs: h.rows.some(r => r.toll != null || r.fuel != null) })));

const tiles = computed(() => {
  const all = [...(data.value.lima || []), ...(data.value.chiclayo || [])];
  const income = sum(all, 'amount') + sum(all, 'parcels') + sum(all, 'excess');
  const seats = sum(all, 'seats');
  return [
    { label: tr('Departures', 'Salidas'), value: all.length },
    { label: tr('Passengers', 'Pasajeros'), value: sum(all, 'pax') },
    { label: tr('Occupancy', 'Ocupación'), value: seats ? `${Math.round((sum(all, 'pax') / seats) * 100)} %` : '—' },
    { label: tr('Lima', 'Lima'), value: money(sum(data.value.lima || [], 'amount')) },
    { label: tr('Chiclayo', 'Chiclayo'), value: money(sum(data.value.chiclayo || [], 'amount')) },
    { label: tr('Parcels + excess', 'Encom. + exceso'), value: money(sum(all, 'parcels') + sum(all, 'excess')) },
    { label: tr("Day's income", 'Ingreso del día'), value: money(income) },
    { label: tr('Tolls', 'Peaje'), value: money(sum(all, 'toll')) },
    { label: tr('Fuel', 'Combustible'), value: money(sum(all, 'fuel')) },
  ];
});
</script>

<style scoped>
.ds-day { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 14px; text-transform: capitalize; }
.ds-i { opacity: 0.6; cursor: help; }
.ds-grid { display: grid; gap: 10px; grid-template-columns: 1fr; }
@media (min-width: 1100px) { .ds-grid { grid-template-columns: 1fr 1fr; } }
.ds-half { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; overflow: hidden; min-width: 0; display: flex; flex-direction: column; }
.ds-fill { flex: 1 1 auto; }
.ds-foot td { overflow: visible !important; text-overflow: clip !important; }
.ds-table { table-layout: fixed; }
.ds-table td, .ds-table th { overflow: hidden; text-overflow: ellipsis; }
.ds-head { padding: 5px 10px; font-weight: 600; font-size: 13px; }
.ds-head span { font-weight: 400; opacity: 0.75; font-size: 12px; margin-left: 6px; }
.ds-lima { background: #e6f4ff; color: #0958d9; }
.ds-chiclayo { background: #f9f0ff; color: #531dab; }
.ds-table { width: 100%; border-collapse: collapse; font-size: 12.5px; font-variant-numeric: tabular-nums; }
.ds-table th { font-size: 11.5px; font-weight: 600; padding: 3px 5px; text-align: left; border-bottom: 1px solid rgba(128, 128, 128, 0.25); white-space: nowrap; }
.ds-table td { padding: 2px 5px; vertical-align: top; white-space: nowrap; border-bottom: 1px solid rgba(128, 128, 128, 0.1); }
.ds-table .r { text-align: right; }
.ds-table tr.tint td { background: rgba(42, 120, 214, 0.06); }
.ds-table tfoot td { background: rgba(42, 120, 214, 0.12); border-top: 1px solid rgba(128, 128, 128, 0.3); border-bottom: none; }
.ds-table .ln + .ln { border-top: 1px dashed rgba(128, 128, 128, 0.25); }
.mut { opacity: 0.55; font-size: 11px; }
.low { color: #d03b3b; }
.ds-src { font-size: 9px; margin-left: 3px; padding: 0 3px; border-radius: 3px; background: rgba(128, 128, 128, 0.15); vertical-align: super; cursor: help; }
.ds-chips { display: flex; flex-wrap: wrap; gap: 4px 6px; align-items: center; padding: 6px 8px; font-size: 12px; }
.chip { padding: 1px 7px; border-radius: 10px; background: rgba(128, 128, 128, 0.12); }
</style>
