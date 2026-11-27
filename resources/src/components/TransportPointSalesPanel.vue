<template>
  <!-- "Ventas por punto de venta" (2026-11-24): AuraSoft's Liquidación General as a board -- by branch, point of sale and
       cashier, with the cash each one hands in (App\Services\TransportPointSales). By sale date. -->
  <div>
    <a-card v-if="data.enabled" :bordered="false" :body-style="{ padding: '8px' }">
      <a-spin :spinning="loading">
        <TransportStrip :items="tiles" :toggle="false" />
        <!-- Where the tickets are sold: the agencies vs AuraSoft's online sellers. -->
        <div v-if="shareItems.length" class="ps-share">
          <span class="ps-lbl">{{ tr('Tickets sold through', 'Pasajes vendidos por') }}:</span>
          <div class="ps-bar">
            <a-tooltip v-for="s in shareItems" :key="s.key" :title="`${s.label}: ${money(s.value)} (${s.pct} %)`">
              <span class="ps-seg" :style="{ width: s.pct + '%', background: s.color }">{{ s.pct >= 7 ? `${s.label} ${s.pct} %` : '' }}</span>
            </a-tooltip>
          </div>
        </div>
        <a-empty v-if="!rows.length" :description="emptyText" />
        <a-table v-else class="ps-table" :columns="columns" :data-source="rows" size="small" :pagination="false" bordered
          :scroll="{ x: 'max-content', y: 'calc(100vh - 330px)' }" :default-expanded-row-keys="expandedDefault" :row-class-name="r => 'ps-' + r.level">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
              <span :class="'ps-n' + record.level">{{ record.name }}</span>
              <span v-if="record.level === 'cashier' && record.days" class="ps-mut"> · {{ record.days }} {{ tr('days', 'días') }}</span>
            </template>
            <template v-else-if="column.key === 'net'"><b>{{ plain(record.net) }}</b></template>
            <template v-else-if="column.money">{{ record[column.key] ? plain(record[column.key]) : '—' }}</template>
          </template>
          <template #summary>
            <a-table-summary fixed>
              <a-table-summary-row class="ps-total">
                <a-table-summary-cell :index="0"><b>{{ tr('Total', 'Total') }}</b></a-table-summary-cell>
                <a-table-summary-cell v-for="(c, i) in columns.slice(1)" :key="c.key" :index="i + 1" align="right"><b>{{ plain(data.totals?.[c.key]) }}</b></a-table-summary-cell>
              </a-table-summary-row>
            </a-table-summary>
          </template>
        </a-table>
      </a-spin>
    </a-card>
    <a-empty v-else-if="!loading" :description="tr('Pick a company with Transport in the top bar to see this.', 'Elige una compañía con Transporte en la barra superior para ver esto.')" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import http from '../lib/http';
import { useTransportCharts } from '../lib/transportCharts';
import { useFormat } from '../composables/useFormat';
import TransportStrip from './TransportStrip.vue';

const props = defineProps({ range: { type: Array, default: null } });
const { money, tr } = useTransportCharts();
const { date } = useFormat();

const loading = ref(false);
const data = ref({ enabled: false, branches: [], totals: {} });
const period = () => {
  const today = dayjs().format('YYYY-MM-DD');
  return { from: props.range?.[0] || dayjs().startOf('month').format('YYYY-MM-DD'), to: props.range?.[1] && props.range[1] < today ? props.range[1] : today };
};
async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/point-sales', period()); } catch (e) { data.value = { enabled: false, branches: [], totals: {} }; } finally { loading.value = false; }
}
onMounted(load);
watch(() => props.range, load, { deep: true });

const plain = v => money(Number(v || 0)).replace(/^[^\d-]+/, '');
const emptyText = computed(() => (data.value.available?.from
  ? tr('No liquidation in this period.', 'Sin liquidación en este período.')
  : tr('Upload AuraSoft\'s "Liquidación General" to see the sales by point of sale.', 'Suba la «Liquidación General» de AuraSoft para ver las ventas por punto de venta.')));

// Branch > point of sale > cashier, as a tree table (the branches open).
const rows = computed(() => (data.value.branches || []).map((b, i) => ({
  ...b, key: 'b' + i, level: 'branch',
  children: b.points.map((p, j) => ({ ...p, key: `b${i}p${j}`, level: 'point',
    children: p.cashiers.length > 1 || (p.cashiers[0] && p.cashiers[0].name !== p.name) ? p.cashiers.map((c, k) => ({ ...c, key: `b${i}p${j}c${k}`, level: 'cashier' })) : undefined })),
})));
const expandedDefault = computed(() => rows.value.filter(r => !r.online).map(r => r.key));

const columns = computed(() => [
  { title: tr('Branch / point of sale / cashier', 'Sucursal / punto de venta / cajero'), key: 'name', dataIndex: 'name', width: 270, fixed: 'left' },
  { title: tr('Tickets', 'Pasajes'), key: 'tickets', dataIndex: 'tickets', align: 'right', width: 96, money: true },
  { title: tr('Parcels', 'Encom.'), key: 'parcels', dataIndex: 'parcels', align: 'right', width: 86, money: true },
  { title: tr('Excess', 'Exceso'), key: 'excess', dataIndex: 'excess', align: 'right', width: 74, money: true },
  { title: tr('Collected at dest.', 'Cobro destino'), key: 'collected', dataIndex: 'collected', align: 'right', width: 90, money: true },
  { title: tr('Total income', 'Total ingresos'), key: 'income', dataIndex: 'income', align: 'right', width: 100, money: true },
  { title: tr('Cards', 'Tarjetas'), key: 'card', dataIndex: 'card', align: 'right', width: 90, money: true },
  { title: 'Web', key: 'web', dataIndex: 'web', align: 'right', width: 86, money: true },
  { title: tr('Other channels', 'Otros canales'), key: 'channel', dataIndex: 'channel', align: 'right', width: 90, money: true },
  { title: tr('Cash expenses', 'Egresos caja'), key: 'cash_out', dataIndex: 'cash_out', align: 'right', width: 86, money: true },
  { title: tr('Total expenses', 'Total egresos'), key: 'expenses', dataIndex: 'expenses', align: 'right', width: 96, money: true },
  { title: tr('Net (income - expenses)', 'Neto (ingr. - egr.)'), key: 'net', dataIndex: 'net', align: 'right', width: 104, money: true },
]);

const tiles = computed(() => {
  const t = data.value.totals || {};
  return [
    { label: tr('Tickets', 'Pasajes'), value: money(t.tickets || 0) },
    { label: tr('Parcels', 'Encomiendas'), value: money(t.parcels || 0) },
    { label: tr('Excess', 'Exceso'), value: money(t.excess || 0) },
    { label: tr('Total income', 'Total ingresos'), value: money(t.income || 0) },
    { label: tr('Cards', 'Tarjetas'), value: money(t.card || 0) },
    { label: tr('Cash expenses', 'Egresos de caja'), value: money(t.cash_out || 0), help: tr('Expenses paid from the offices\' cash.', 'Gastos pagados con la caja de las oficinas.') },
    { label: tr('Net to hand in', 'Neto a liquidar'), value: money(t.net || 0), help: tr('Income - expenses (AuraSoft: TOTAL (ING - EGR)).', 'Ingresos - egresos (AuraSoft: TOTAL (ING - EGR)).') },
    { label: tr('Sold online', 'Vendido en línea'), value: data.value.online_pct != null ? `${data.value.online_pct} %` : '—', help: tr('Web + RedBus + Kupos over all tickets.', 'Web + RedBus + Kupos sobre el total de pasajes.') },
  ];
});

const COLORS = { agencies: '#3A4FC4', Web: '#0F9D8A', RedBus: '#D64545', Kupos: '#B08F00' };
const shareItems = computed(() => {
  const s = data.value.share || {};
  const total = Object.values(s).reduce((a, v) => a + Number(v || 0), 0);
  if (!total) return [];
  return Object.entries(s).filter(([, v]) => v > 0).map(([k, v]) => ({
    key: k, label: k === 'agencies' ? tr('Agencies', 'Agencias') : k, value: v, pct: Math.round((v / total) * 1000) / 10, color: COLORS[k] || '#888',
  }));
});
</script>

<style scoped>
.ps-share { display: flex; align-items: center; gap: 8px; margin: 6px 0; font-size: 12px; }
.ps-lbl { white-space: nowrap; opacity: 0.7; }
.ps-bar { flex: 1; display: flex; height: 20px; border-radius: 4px; overflow: hidden; }
.ps-seg { color: #fff; font-size: 11px; line-height: 20px; text-align: center; white-space: nowrap; overflow: hidden; border-right: 2px solid var(--ant-color-bg-container, #fff); }
.ps-table :deep(.ant-table-thead > tr > th) { padding: 3px 5px !important; font-size: 11.5px; line-height: 1.25; text-align: center; }
.ps-table :deep(.ant-table-tbody > tr > td), .ps-table :deep(.ant-table-summary td) { padding: 2px 5px !important; font-size: 12.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.ps-table :deep(tr.ps-branch > td) { background: rgba(42, 120, 214, 0.08); font-weight: 600; }
.ps-table :deep(tr.ps-cashier > td) { font-size: 12px; }
.ps-table :deep(.ps-total td) { background: rgba(42, 120, 214, 0.14) !important; }
.ps-ncashier { opacity: 0.85; }
.ps-mut { opacity: 0.55; font-size: 11px; }
.ps-note { font-size: 11.5px; opacity: 0.65; margin-top: 6px; }
</style>
