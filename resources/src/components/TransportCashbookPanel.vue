<template>
  <!-- "Caja y clientes" (2026-11-24): AuraSoft's Liquidación Detallada as a board (App\Services\TransportAuraBoards::cashbook):
       cancelled documents, cash expenses, how the customers paid and the customers who buy most. By sale date. -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <TransportStrip :items="tiles" :toggle="false" />
        <a-empty v-if="!data.totals?.docs" :description="emptyText" />
        <div v-else class="cb-grid">
          <!-- How the customers paid -->
          <div class="cb-box">
            <div class="cb-h">{{ tr('How it was paid', 'Cómo se pagó') }}</div>
            <div v-for="m in methods" :key="m.key" class="cb-bar">
              <span class="cb-l">{{ m.label }}</span>
              <span class="cb-track"><span class="cb-fill" :style="{ width: m.w + '%', background: m.color }" /></span>
              <span class="cb-v">{{ plain(m.amount) }}</span><span class="cb-p">{{ m.pct }} %</span>
            </div>
          </div>
          <!-- Cancellations -->
          <div class="cb-box">
            <div class="cb-h">{{ tr('Cancelled documents by cashier', 'Documentos anulados por cajero') }}
              <a-tooltip :title="tr('A cancellation is normal now and then; many at one cashier is worth a look.', 'Anular de vez en cuando es normal; muchas en un mismo cajero merecen una revisión.')"><InfoCircleOutlined class="cb-i" /></a-tooltip>
            </div>
            <div v-if="!data.cancelled_by?.length" class="cb-mut">{{ tr('None in this period.', 'Ninguno en este período.') }}</div>
            <table v-else class="cb-t">
              <tbody>
                <tr v-for="(c, i) in data.cancelled_by" :key="i">
                  <td class="cb-name">{{ c.cashier }}<span class="cb-mut"> · {{ c.point }}</span></td>
                  <td class="cb-num"><span class="cb-mini" :style="{ width: (c.n / maxCancel) * 60 + 'px' }" />{{ c.n }}</td>
                  <td class="cb-num">{{ plain(c.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <!-- Cash expenses -->
          <div class="cb-box">
            <div class="cb-h">{{ tr('Cash expenses by point of sale', 'Egresos de caja por punto de venta') }}</div>
            <table class="cb-t">
              <thead><tr><th>{{ tr('Point', 'Punto') }}</th><th>{{ tr('Count', 'Cant.') }}</th><th>S/</th><th>{{ tr('Biggest', 'Mayor') }}</th></tr></thead>
              <tbody>
                <tr v-for="(e, i) in data.expenses_by" :key="i">
                  <td class="cb-name">{{ e.point }}<span class="cb-mut"> · {{ e.branch }}</span></td>
                  <td class="cb-num">{{ e.n }}</td>
                  <td class="cb-num"><b>{{ plain(e.amount) }}</b></td>
                  <td class="cb-num">{{ plain(e.biggest) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <!-- Customers -->
          <div class="cb-box">
            <div class="cb-h">{{ tr('Customers who buy most', 'Clientes que más compran') }}
              <span class="cb-mut">· {{ data.totals.ruc }} {{ tr('companies (RUC)', 'empresas (RUC)') }}, {{ data.totals.dni }} {{ tr('people (DNI)', 'personas (DNI)') }}</span>
            </div>
            <table class="cb-t">
              <tbody>
                <tr v-for="c in data.clients" :key="c.doc">
                  <td class="cb-name"><span class="cb-tag" :class="c.company ? 'ruc' : 'dni'">{{ c.company ? 'RUC' : 'DNI' }}</span> {{ c.name || c.doc }}</td>
                  <td class="cb-num cb-mut">{{ c.n }} {{ tr('docs', 'docs') }}<template v-if="c.parcels"> · {{ c.parcels }} {{ tr('parc.', 'enc.') }}</template></td>
                  <td class="cb-num"><b>{{ plain(c.amount) }}</b></td>
                </tr>
              </tbody>
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
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { useTransportCharts } from '../lib/transportCharts';
import { useFormat } from '../composables/useFormat';
import TransportStrip from './TransportStrip.vue';

const props = defineProps({ range: { type: Array, default: null } });
const { money, tr } = useTransportCharts();
const { date } = useFormat();
const loading = ref(false);
const data = ref({ enabled: false });
const period = () => {
  const today = dayjs().format('YYYY-MM-DD');
  return { from: props.range?.[0] || dayjs().startOf('month').format('YYYY-MM-DD'), to: props.range?.[1] && props.range[1] < today ? props.range[1] : today };
};
async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/cashbook', period()); } catch (e) { data.value = { enabled: false }; } finally { loading.value = false; }
}
onMounted(load);
watch(() => props.range, load, { deep: true });

const plain = v => money(Number(v || 0)).replace(/^[^\d-]+/, '');
const emptyText = computed(() => (data.value.available?.from
  ? tr('No documents in this period.', 'Sin documentos en este período.')
  : tr('Upload AuraSoft\'s "Liquidación Detallada" to see this board.', 'Suba la «Liquidación Detallada» de AuraSoft para ver este tablero.')));
const maxCancel = computed(() => Math.max(1, ...(data.value.cancelled_by || []).map(c => c.n)));

const tiles = computed(() => {
  const t = data.value.totals || {};
  return [
    { label: tr('Sales', 'Ventas'), value: money(t.sales || 0), help: tr('Tickets, parcels, excess and collections at destination.', 'Pasajes, encomiendas, exceso y cobros en destino.') },
    { label: tr('Cash', 'Efectivo'), value: money(t.cash || 0) },
    { label: tr('Cards / Yape', 'Tarjetas / Yape'), value: money(t.cards || 0) },
    { label: tr('Online', 'En línea'), value: money(t.online || 0), help: 'Web + RedBus + delivery' },
    { label: tr('Cash expenses', 'Egresos de caja'), value: money(t.expenses || 0), help: `${t.expense_n || 0} ${tr('expenses', 'egresos')}` },
    { label: tr('Cancelled', 'Anulados'), value: `${t.cancelled || 0} · ${money(t.cancelled_amount || 0)}`, help: `${t.cancelled_pct || 0} % ${tr('of the documents', 'de los documentos')}` },
    { label: tr('Companies (RUC)', 'Empresas (RUC)'), value: money(t.ruc_amount || 0), help: `${t.ruc || 0} ${tr('companies bought', 'empresas compraron')}` },
  ];
});
const COLORS = { cash: '#3A4FC4', VISA: '#0F9D8A', 'BCP YAPE': '#722ED1', MAESTRO: '#B08F00', MASTERCARD: '#D64545', WEB: '#2A78D6', REDBUS: '#C2410C', DELIVERY: '#64748B' };
const methods = computed(() => {
  const list = data.value.methods || [];
  const total = list.reduce((s, m) => s + Number(m.amount || 0), 0) || 1;
  const max = Math.max(1, ...list.map(m => Number(m.amount || 0)));
  const names = { cash: tr('Cash', 'Efectivo'), 'BCP YAPE': 'Yape', CARD_PARCELS: tr('Card (parcels)', 'Tarjeta (encom.)'), REDBUS: 'RedBus', WEB: 'Web' };
  return list.map(m => ({ ...m, label: names[m.key] || m.key.charAt(0) + m.key.slice(1).toLowerCase(),
    pct: Math.round((m.amount / total) * 1000) / 10, w: (m.amount / max) * 100, color: COLORS[m.key] || '#94A3B8' }));
});
</script>

<style scoped>
.cb-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 6px; }
.cb-box { border: 1px solid rgba(128, 128, 128, 0.14); border-radius: 4px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.07); padding: 6px 8px; min-width: 0; max-height: calc(50vh - 120px); min-height: 180px; overflow: auto; }
.cb-h { font-weight: 600; font-size: 12.5px; margin-bottom: 4px; position: sticky; top: 0; background: var(--ant-color-bg-container, transparent); }
.cb-i { opacity: 0.55; margin-left: 4px; cursor: help; }
.cb-bar { display: grid; grid-template-columns: 90px 1fr 84px 44px; align-items: center; gap: 6px; font-size: 12px; margin: 3px 0; }
.cb-track { height: 12px; background: rgba(128, 128, 128, 0.12); border-radius: 3px; overflow: hidden; }
.cb-fill { display: block; height: 100%; border-radius: 0 3px 3px 0; }
.cb-v, .cb-p, .cb-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.cb-p { opacity: 0.6; font-size: 11px; }
.cb-t { width: 100%; border-collapse: collapse; font-size: 12px; }
.cb-t th { font-weight: 500; opacity: 0.6; font-size: 11px; text-align: right; padding: 1px 4px; }
.cb-t th:first-child { text-align: left; }
.cb-t td { padding: 2px 4px; border-top: 1px solid rgba(128, 128, 128, 0.12); }
.cb-name { max-width: 0; width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cb-mut { opacity: 0.55; font-size: 11px; font-weight: 400; }
.cb-mini { display: inline-block; height: 8px; background: #D64545; border-radius: 2px; margin-right: 6px; vertical-align: middle; opacity: 0.75; }
.cb-tag { font-size: 10px; padding: 0 4px; border-radius: 3px; }
.cb-tag.ruc { background: rgba(58, 79, 196, 0.14); color: #3A4FC4; }
.cb-tag.dni { background: rgba(128, 128, 128, 0.16); }
.cb-note { font-size: 11.5px; opacity: 0.65; margin-top: 6px; }
@media (max-width: 900px) { .cb-grid { grid-template-columns: 1fr; } .cb-box { max-height: none; } }
</style>
