<template>
  <!-- Call log (2026-11-17): every call of every agent, from the screen or the telephone exchange. -->
  <div class="page">
    <PageHeader :title="tr('Call log', 'Registro de llamadas')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Call log', 'Registro de llamadas')]" />

    <DataTable :crud="crud" :columns="columns" compact-toolbar>
      <template #filters>
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" apply-default @change="crud.reload" />
        <a-popover trigger="click" placement="bottomLeft">
          <template #content>
            <div class="more">
              <a-select v-model:value="filters.campaign_id" :options="campaignOptions" allow-clear :placeholder="tr('Campaign', 'Campaña')" @change="crud.reload" />
              <a-select v-model:value="filters.user_id" :options="agentOptions" allow-clear show-search option-filter-prop="label" :placeholder="tr('Agent', 'Agente')" @change="crud.reload" />
              <a-select v-model:value="filters.result" :options="resultOptions" allow-clear :placeholder="tr('Result', 'Resultado')" @change="crud.reload" />
              <a-select v-model:value="filters.source" :options="[{ value: 'screen', label: tr('Screen', 'Pantalla') }, { value: 'telephony', label: tr('Exchange', 'Central') }]" allow-clear :placeholder="tr('Source', 'Origen')" @change="crud.reload" />
              <a-select v-model:value="filters.reviewed" :options="[{ value: '1', label: tr('Reviewed', 'Evaluadas') }, { value: '0', label: tr('Not reviewed', 'Sin evaluar') }]" allow-clear :placeholder="tr('Quality', 'Calidad')" @change="crud.reload" />
            </div>
          </template>
          <a-badge :count="activeFilters" size="small"><a-button><FilterOutlined /> {{ tr('Filters', 'Filtros') }}</a-button></a-badge>
        </a-popover>
        <span v-if="summary" class="small sum">
          <b>{{ summary.calls }}</b> {{ tr('calls', 'llamadas') }} · <b>{{ hours(summary.talk_sec) }}</b> {{ tr('talking', 'hablando') }} ·
          <b class="ok">{{ summary.sales }}</b> {{ tr('sales', 'ventas') }}<span v-if="summary.amount"> ({{ fmt.money(summary.amount) }})</span>
        </span>
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'when'">{{ fmt.dateTime(record.started_at) }}</template>
        <template v-else-if="column.key === 'lead'">
          <div>{{ record.lead || '—' }}</div>
          <div class="muted small">{{ record.phone }}</div>
        </template>
        <template v-else-if="column.key === 'result'">
          <a-tag v-if="record.result" :color="RESULT_COLORS[record.result]">{{ record.result_label }}</a-tag>
          <span v-else class="muted">{{ tr('Answered (no result yet)', 'Contestada (sin resultado aún)') }}</span>
          <div v-if="record.callback_at" class="muted small">→ {{ fmt.dateTime(record.callback_at) }}</div>
        </template>
        <template v-else-if="column.key === 'duration'">{{ duration(record.duration_sec) }}</template>
        <template v-else-if="column.key === 'amount'">{{ record.sale_amount != null ? fmt.money(record.sale_amount) : '' }}</template>
        <template v-else-if="column.key === 'source'">
          <a-tag>{{ record.source === 'telephony' ? tr('Exchange', 'Central') : tr('Screen', 'Pantalla') }}</a-tag>
          <a v-if="record.recording_url" :href="record.recording_url" target="_blank" rel="noopener"><SoundOutlined /></a>
        </template>
        <template v-else-if="column.key === 'notes'"><span class="small">{{ record.notes }}</span></template>
        <template v-else-if="column.key === 'qa'">
          <a-tag v-if="record.qa_score != null" :color="record.qa_score >= 80 ? 'green' : record.qa_score >= 60 ? 'gold' : 'red'">{{ record.qa_score }} %</a-tag>
          <a-button v-else-if="canReview && record.result" size="small" @click="reviewing = record">{{ tr('Review', 'Evaluar') }}</a-button>
        </template>
      </template>
    </DataTable>

    <CcReviewModal :call="reviewing" @close="reviewing = null" @saved="crud.fetchRows()" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { FilterOutlined, SoundOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import CcReviewModal from '../../components/CcReviewModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr, duration, hours, RESULT_COLORS } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const auth = useAuthStore();
const range = ref([dayjs().subtract(6, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const filters = ref({ campaign_id: undefined, user_id: undefined, result: undefined, source: undefined, reviewed: undefined });
const crud = useCrudTable('callcenter/calls', {
  rowsKey: 'rows', limit: 50,
  params: () => ({ from: range.value?.[0] || '', to: range.value?.[1] || '',
    ...Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== undefined && v !== null && v !== '')) }),
});
useListState('cc-calls', { filters, range }, crud);
const opts = ref({ campaigns: [], agents: [], results: [] });
const reviewing = ref(null);
const summary = computed(() => crud.payload.value?.summary);
const canReview = computed(() => auth.can ? auth.can('callcenter_quality') : true);

const activeFilters = computed(() => Object.values(filters.value).filter(v => v !== undefined && v !== null && v !== '').length);
const campaignOptions = computed(() => sortByLabel(opts.value.campaigns.map(c => ({ value: c.id, label: c.name }))));
const agentOptions = computed(() => sortByLabel(opts.value.agents.map(a => ({ value: a.id, label: a.username }))));
const resultOptions = computed(() => sortByLabel(opts.value.results.map(r => ({ value: r.value, label: r.label }))));

const columns = computed(() => [
  { title: tr('When', 'Fecha'), key: 'when', dataIndex: 'started_at', sorter: true, width: 140 },
  { title: tr('Agent', 'Agente'), dataIndex: 'username', sorter: true, width: 140 },
  { title: tr('Campaign', 'Campaña'), dataIndex: 'campaign', sorter: true, width: 160 },
  { title: 'Lead', key: 'lead', width: 180 },
  { title: tr('Result', 'Resultado'), key: 'result', dataIndex: 'result', sorter: true, width: 150 },
  { title: tr('Duration', 'Duración'), key: 'duration', dataIndex: 'duration_sec', sorter: true, width: 90 },
  { title: tr('Sale', 'Venta'), key: 'amount', dataIndex: 'sale_amount', sorter: true, width: 100 },
  { title: tr('Source', 'Origen'), key: 'source', width: 100 },
  { title: tr('Notes', 'Notas'), key: 'notes', width: 240 },
  { title: tr('Quality', 'Calidad'), key: 'qa', dataIndex: 'qa_score', sorter: true, width: 100 },
]);

onMounted(async () => {
  crud.fetchRows();
  try {
    opts.value = await http.get('callcenter/campaigns/options');
  } catch (e) { /* filters stay empty */ }
});
</script>

<style scoped>
.muted { opacity: 0.65; }
.small { font-size: 12px; }
.more { display: grid; gap: 8px; width: 240px; }
.sum { margin-left: 4px; }
.ok { color: #389e0d; }
</style>
