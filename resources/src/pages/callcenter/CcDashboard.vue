<template>
  <!-- Floor indicators (2026-11-17): one screen, refreshed every 30 s. -->
  <div class="page cc-dash">
    <PageHeader :title="tr('Floor indicators', 'Indicadores del piso')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Indicators', 'Indicadores')]" />

    <div class="filters">
      <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="load" />
      <a-select v-model:value="campaignId" :options="campaignOptions" allow-clear :placeholder="tr('All campaigns', 'Todas las campañas')" style="width: 220px" @change="load" />
      <a-button :loading="loading" @click="load"><ReloadOutlined /></a-button>
    </div>

    <a-spin :spinning="loading && !d">
      <template v-if="d">
        <div class="tiles">
          <div v-for="t in tiles" :key="t.key" class="tile">
            <div class="t-label">{{ t.label }}
              <a-tooltip v-if="t.help" :title="t.help"><InfoCircleOutlined class="info" /></a-tooltip>
            </div>
            <div class="t-value" :class="t.cls">{{ t.value }}</div>
            <div v-if="t.sub" class="t-sub">{{ t.sub }}</div>
          </div>
        </div>

        <a-row :gutter="10" class="mt10">
          <a-col :xs="24" :lg="8">
            <a-card size="small" :title="tr('Agents now', 'Agentes ahora')" class="fill">
              <a-empty v-if="!d.agents_now.length" :description="tr('No agents (role with My campaign).', 'Sin agentes (rol con Mi campaña).')" />
              <div v-for="a in d.agents_now" :key="a.user_id" class="agent">
                <a-badge :status="{ calling: 'processing', waiting: 'success', pause: 'warning', offline: 'default' }[a.state]" />
                <div class="grow">
                  <div><b>{{ a.name }}</b> <span class="muted small">{{ stateText(a) }}</span></div>
                  <div class="muted small">
                    <template v-if="a.state === 'calling'">{{ a.lead }} · {{ since(a.since) }}</template>
                    <template v-else-if="a.state === 'pause'">{{ a.reason }} · {{ since(a.since) }}</template>
                    <template v-else-if="a.state === 'waiting'">{{ a.campaign }}</template>
                  </div>
                </div>
                <div class="right small">{{ a.calls_today }} <span class="muted">{{ tr('calls', 'llam.') }}</span><br><b class="ok">{{ a.sales_today }}</b> <span class="muted">{{ tr('sales', 'ventas') }}</span></div>
              </div>
            </a-card>
          </a-col>
          <a-col :xs="24" :lg="10">
            <a-card size="small" :title="tr('Calls by hour', 'Llamadas por hora')" class="fill">
              <apexchart v-if="d.by_hour.length" type="bar" height="230" :options="hourOptions" :series="hourSeries" />
              <a-empty v-else />
            </a-card>
          </a-col>
          <a-col :xs="24" :lg="6">
            <a-card size="small" :title="tr('Campaigns', 'Campañas')" class="fill">
              <div v-for="c in d.campaigns" :key="c.id" class="camp">
                <div class="row"><b>{{ c.name }}</b><a-tag :color="c.status === 'active' ? 'green' : 'orange'" class="t">{{ c.status_label }}</a-tag></div>
                <a-progress :percent="c.progress?.percent || 0" size="small" />
                <div class="muted small">{{ tr('to call', 'por llamar') }} {{ (c.progress?.pending || 0) + (c.progress?.callback || 0) }} / {{ c.progress?.total || 0 }} · {{ tr('sales', 'ventas') }} {{ c.progress?.sales || 0 }}</div>
              </div>
              <a-divider style="margin: 8px 0" />
              <div class="muted small mb4">{{ tr('Results', 'Resultados') }}</div>
              <div v-for="r in d.results" :key="r.result" class="res">
                <span class="grow">{{ r.label }}</span>
                <div class="bar"><div :style="{ width: (100 * r.n / maxResult) + '%' }" :class="'c-' + (RESULT_COLORS[r.result] || 'default')" /></div>
                <span class="n">{{ r.n }}</span>
              </div>
            </a-card>
          </a-col>
        </a-row>

        <a-card size="small" :title="tr('By agent', 'Por agente')" class="mt10">
          <a-table :data-source="d.by_agent" :columns="agentColumns" row-key="user_id" size="small" :pagination="false">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'conversion'">{{ record.conversion != null ? record.conversion + ' %' : '—' }}</template>
              <template v-else-if="column.key === 'aht'">{{ duration(record.aht_sec) }}</template>
              <template v-else-if="column.key === 'amount'">{{ record.amount ? fmt.money(record.amount) : '' }}</template>
              <template v-else-if="column.key === 'qa'">
                <a-tag v-if="record.qa != null" :color="record.qa >= 80 ? 'green' : record.qa >= 60 ? 'gold' : 'red'">{{ record.qa }} %</a-tag>
              </template>
            </template>
          </a-table>
        </a-card>
      </template>
    </a-spin>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import dayjs from 'dayjs';
import { ReloadOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr, duration, RESULT_COLORS } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const range = ref([dayjs().format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const campaignId = ref(undefined);
const d = ref(null);
const loading = ref(false);
const campaigns = ref([]);
const now = ref(Date.now());

const campaignOptions = computed(() => sortByLabel(campaigns.value.map(c => ({ value: c.id, label: c.name }))));
const k = computed(() => d.value?.kpis || {});
const pct = v => (v == null ? '—' : `${v} %`);
const tiles = computed(() => [
  { key: 'calls', label: tr('Calls', 'Llamadas'), value: k.value.calls },
  { key: 'contacts', label: tr('Contacts', 'Contactos'), value: k.value.contacts, sub: pct(k.value.contact_rate), help: tr('The person answered (sale, interested, not interested, callback...).', 'La persona contestó (venta, interesado, no interesado, volver a llamar...).') },
  { key: 'sales', label: tr('Sales', 'Ventas'), value: k.value.sales, cls: 'ok', sub: k.value.amount ? fmt.money(k.value.amount) : '' },
  { key: 'conv', label: tr('Conversion', 'Conversión'), value: pct(k.value.conversion), help: tr('Sales out of contacts.', 'Ventas sobre contactos.') },
  { key: 'aht', label: tr('Average talk', 'Tiempo medio'), value: duration(k.value.aht_sec), help: tr('Average length of the calls answered (AHT).', 'Duración media de las llamadas contestadas (TMO).') },
  { key: 'cph', label: tr('Calls / hour', 'Llamadas / hora'), value: k.value.calls_per_hour ?? '—', sub: `${k.value.work_h ?? 0} h ${tr('worked', 'trabajadas')}` },
  { key: 'occ', label: tr('Occupancy', 'Ocupación'), value: pct(k.value.occupancy), help: tr('Time talking out of the time on shift.', 'Tiempo hablando sobre el tiempo en turno.') },
  { key: 'pause', label: tr('Pauses', 'Pausas'), value: pct(k.value.pause_pct), cls: (k.value.pause_pct || 0) > 15 ? 'warn' : '' },
  { key: 'pending', label: tr('Leads to call', 'Leads por llamar'), value: k.value.pending },
]);
const maxResult = computed(() => Math.max(1, ...(d.value?.results || []).map(r => r.n)));

const hourSeries = computed(() => [
  { name: tr('Calls', 'Llamadas'), data: d.value.by_hour.map(h => h.calls) },
  { name: tr('Contacts', 'Contactos'), data: d.value.by_hour.map(h => h.contacts) },
  { name: tr('Sales', 'Ventas'), data: d.value.by_hour.map(h => h.sales) },
]);
const hourOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent' },
  plotOptions: { bar: { columnWidth: '70%', borderRadius: 3 } },
  dataLabels: { enabled: false },
  colors: ['#8c8c8c', '#1677ff', '#389e0d'],
  xaxis: { categories: d.value.by_hour.map(h => `${String(h.hour).padStart(2, '0')}h`) },
  legend: { position: 'top', fontSize: '11px' },
  grid: { borderColor: 'rgba(128,128,128,0.15)' },
}));

const agentColumns = computed(() => [
  { title: tr('Agent', 'Agente'), dataIndex: 'name', width: 160 },
  { title: tr('Calls', 'Llamadas'), dataIndex: 'calls', width: 90, sorter: (a, b) => a.calls - b.calls },
  { title: tr('Contacts', 'Contactos'), dataIndex: 'contacts', width: 90 },
  { title: tr('Sales', 'Ventas'), dataIndex: 'sales', width: 80, sorter: (a, b) => a.sales - b.sales },
  { title: tr('Conversion', 'Conversión'), key: 'conversion', width: 100 },
  { title: tr('Amount', 'Monto'), key: 'amount', width: 110 },
  { title: tr('Avg. talk', 'T. medio'), key: 'aht', width: 90 },
  { title: tr('Hours', 'Horas'), dataIndex: 'work_h', width: 80 },
  { title: tr('Pauses (h)', 'Pausas (h)'), dataIndex: 'pause_h', width: 90 },
  { title: tr('Calls / h', 'Llam. / h'), dataIndex: 'calls_per_hour', width: 90 },
  { title: tr('Quality', 'Calidad'), key: 'qa', width: 90 },
]);

function stateText(a) {
  return { calling: tr('calling', 'llamando'), waiting: tr('ready', 'disponible'), pause: tr('on a pause', 'en pausa'), offline: tr('offline', 'desconectado') }[a.state];
}
const since = v => (v ? duration((now.value - dayjs(v).valueOf()) / 1000) : '');

async function load() {
  loading.value = true;
  try {
    d.value = await http.get('callcenter/dashboard', { from: range.value?.[0] || '', to: range.value?.[1] || '', campaign_id: campaignId.value });
  } finally {
    loading.value = false;
  }
}

let refresh = null;
let tick = null;
onMounted(async () => {
  load();
  refresh = setInterval(load, 30000);
  tick = setInterval(() => { now.value = Date.now(); }, 1000);
  try {
    campaigns.value = (await http.get('callcenter/campaigns/options')).campaigns || [];
  } catch (e) { /* the filter stays empty */ }
});
onBeforeUnmount(() => { clearInterval(refresh); clearInterval(tick); });
</script>

<style scoped>
.filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; }
.tile { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 8px 10px; background: var(--card-bg, transparent); }
.t-label { font-size: 12px; opacity: 0.7; }
.t-value { font-size: 22px; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
.t-value.ok { color: #389e0d; }
.t-value.warn { color: #d46b08; }
.t-sub { font-size: 12px; opacity: 0.7; }
.info { opacity: 0.5; margin-left: 2px; }
.mt10 { margin-top: 10px; }
.mb4 { margin-bottom: 4px; }
.fill { height: 100%; }
.fill :deep(.ant-card-body) { max-height: 300px; overflow: auto; }
.agent { display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.grow { flex: 1; min-width: 0; }
.right { text-align: right; }
.muted { opacity: 0.65; }
.small { font-size: 12px; }
.ok { color: #389e0d; }
.camp { margin-bottom: 8px; }
.camp .row { display: flex; justify-content: space-between; align-items: center; }
.t { font-size: 11px; line-height: 16px; padding: 0 4px; }
.res { display: flex; align-items: center; gap: 6px; font-size: 12px; padding: 1px 0; }
.res .bar { width: 70px; height: 7px; background: rgba(128, 128, 128, 0.15); border-radius: 4px; overflow: hidden; }
.res .bar div { height: 100%; background: #8c8c8c; }
.res .n { width: 30px; text-align: right; font-variant-numeric: tabular-nums; }
.c-green { background: #389e0d !important; } .c-cyan { background: #13c2c2 !important; } .c-blue { background: #1677ff !important; }
.c-orange { background: #fa8c16 !important; } .c-gold { background: #faad14 !important; } .c-red { background: #cf1322 !important; } .c-magenta { background: #c41d7f !important; }
</style>
