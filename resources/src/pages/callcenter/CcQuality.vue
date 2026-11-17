<template>
  <!-- Quality (2026-11-17): calls reviewed with the company's scorecard, the agents' averages, and the scorecard itself. -->
  <div class="page">
    <PageHeader :title="tr('Quality', 'Calidad')" :breadcrumb="[tr('Call center', 'Centro de llamadas'), tr('Quality', 'Calidad')]" />

    <a-tabs v-model:activeKey="tab">
      <a-tab-pane key="reviews" :tab="tr('Reviews', 'Evaluaciones')">
        <div v-if="byAgent.length" class="agents">
          <div v-for="a in byAgent" :key="a.user_id" class="agent">
            <div class="muted small">{{ a.agent }}</div>
            <div class="score" :class="cls(a.avg)">{{ a.avg }} %</div>
            <div class="muted small">{{ a.reviews }} {{ tr('reviews', 'evaluaciones') }}</div>
          </div>
        </div>
        <DataTable :crud="reviews" :columns="reviewColumns" compact-toolbar>
          <template #filters>
            <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="reviews.reload" />
            <a-select v-model:value="agentId" :options="agentOptions" allow-clear :placeholder="tr('Agent', 'Agente')" style="width: 170px" @change="reviews.reload" />
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'when'">{{ fmt.dateTime(record.started_at) }}</template>
            <template v-else-if="column.key === 'score'"><a-tag :color="tagColor(record.score)">{{ record.score }} %</a-tag></template>
            <template v-else-if="column.key === 'call'">
              <div>{{ record.lead }} · {{ record.result_label }}</div>
              <div class="muted small">{{ record.campaign }} · {{ duration(record.duration_sec) }}</div>
            </template>
            <template v-else-if="column.key === 'comments'"><span class="small">{{ record.comments }}</span></template>
            <template v-else-if="column.key === 'actions'">
              <a-space size="small">
                <a-button size="small" @click="edit(record)"><EditOutlined /></a-button>
                <a-button size="small" danger @click="reviews.remove(record, { label: record.agent, deleteEndpoint: `callcenter/quality/reviews/${record.id}` })"><DeleteOutlined /></a-button>
              </a-space>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <a-tab-pane key="pending" :tab="tr('Calls to review', 'Llamadas por evaluar')">
        <DataTable :crud="pending" :columns="pendingColumns" compact-toolbar>
          <template #filters>
            <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" @change="pending.reload" />
            <a-select v-model:value="agentId" :options="agentOptions" allow-clear :placeholder="tr('Agent', 'Agente')" style="width: 170px" @change="pending.reload" />
            <a-select v-model:value="result" :options="resultOptions" allow-clear :placeholder="tr('Result', 'Resultado')" style="width: 160px" @change="pending.reload" />
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'when'">{{ fmt.dateTime(record.started_at) }}</template>
            <template v-else-if="column.key === 'result'"><a-tag :color="RESULT_COLORS[record.result]">{{ record.result_label }}</a-tag></template>
            <template v-else-if="column.key === 'duration'">{{ duration(record.duration_sec) }}</template>
            <template v-else-if="column.key === 'notes'"><span class="small">{{ record.notes }}</span></template>
            <template v-else-if="column.key === 'actions'"><a-button size="small" type="primary" @click="reviewing = record">{{ tr('Review', 'Evaluar') }}</a-button></template>
          </template>
        </DataTable>
      </a-tab-pane>

      <a-tab-pane key="criteria" :tab="tr('Scorecard', 'Ficha de evaluación')">
        <a-card size="small" style="max-width: 720px">
          <a-alert type="info" show-icon class="mb8" :message="tr('Each criterion is scored 0 to 5; the weight says how much it counts in the %. A criterion already used in reviews is switched off, not removed.', 'Cada criterio se califica de 0 a 5; el peso dice cuánto cuenta en el %. Un criterio ya usado en evaluaciones se desactiva, no se borra.')" />
          <div v-for="(c, i) in criteria" :key="c.id || 'n' + i" class="crit-row">
            <a-input v-model:value="c.name" :placeholder="tr('Criterion', 'Criterio')" />
            <span class="muted small">{{ tr('weight', 'peso') }}</span>
            <a-input-number v-model:value="c.weight" :min="1" :max="10" style="width: 70px" />
            <a-switch v-model:checked="c.active" size="small" />
            <a-button size="small" :disabled="i === 0" @click="moveCrit(i, -1)"><ArrowUpOutlined /></a-button>
            <a-button size="small" @click="criteria.splice(i, 1)"><DeleteOutlined /></a-button>
          </div>
          <div class="foot">
            <a-button @click="criteria.push({ name: '', weight: 1, active: true })"><PlusOutlined /> {{ tr('Add', 'Agregar') }}</a-button>
            <a-button type="primary" :loading="savingCrit" @click="saveCriteria">{{ tr('Save', 'Guardar') }}</a-button>
          </div>
        </a-card>
      </a-tab-pane>
    </a-tabs>

    <CcReviewModal :call="reviewing" :review="reviewingReview" @close="reviewing = null; reviewingReview = null" @saved="reviews.fetchRows(); pending.fetchRows()" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, DeleteOutlined, EditOutlined, ArrowUpOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import CcReviewModal from '../../components/CcReviewModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useTr, duration, RESULT_COLORS, errText } from '../../lib/callcenter';

const tr = useTr();
const fmt = useFormat();
const tab = ref('reviews');
const range = ref([dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const agentId = ref(undefined);
const result = ref(undefined);
const opts = ref({ agents: [], results: [] });
const criteria = ref([]);
const savingCrit = ref(false);
const reviewing = ref(null);
const reviewingReview = ref(null);

const reviews = useCrudTable('callcenter/quality/reviews', { rowsKey: 'rows', params: () => ({ from: range.value?.[0] || '', to: range.value?.[1] || '', user_id: agentId.value }) });
const pending = useCrudTable('callcenter/calls', { rowsKey: 'rows', params: () => ({ from: range.value?.[0] || '', to: range.value?.[1] || '', user_id: agentId.value, result: result.value, reviewed: '0' }) });
useListState('cc-quality', { range, agentId, result, tab }, reviews);
const byAgent = computed(() => reviews.payload.value?.by_agent || []);

const agentOptions = computed(() => sortByLabel(opts.value.agents.map(a => ({ value: a.id, label: a.username }))));
const resultOptions = computed(() => sortByLabel(opts.value.results.filter(r => r.contact).map(r => ({ value: r.value, label: r.label }))));
const cls = v => (v >= 80 ? 'ok' : v >= 60 ? 'mid' : 'bad');
const tagColor = v => (v >= 80 ? 'green' : v >= 60 ? 'gold' : 'red');

const reviewColumns = computed(() => [
  { title: tr('Call', 'Llamada'), key: 'when', dataIndex: 'started_at', sorter: true, width: 140 },
  { title: tr('Agent', 'Agente'), dataIndex: 'agent', sorter: true, width: 140 },
  { title: tr('Lead / result', 'Lead / resultado'), key: 'call', width: 230 },
  { title: tr('Score', 'Puntaje'), key: 'score', dataIndex: 'score', sorter: true, width: 90 },
  { title: tr('Comments', 'Comentarios'), key: 'comments', width: 260 },
  { title: tr('Reviewer', 'Evaluador'), dataIndex: 'reviewer', width: 130 },
  { title: '', key: 'actions', width: 90 },
]);
const pendingColumns = computed(() => [
  { title: tr('When', 'Fecha'), key: 'when', dataIndex: 'started_at', sorter: true, width: 140 },
  { title: tr('Agent', 'Agente'), dataIndex: 'username', sorter: true, width: 140 },
  { title: tr('Campaign', 'Campaña'), dataIndex: 'campaign', width: 160 },
  { title: 'Lead', dataIndex: 'lead', width: 170 },
  { title: tr('Result', 'Resultado'), key: 'result', dataIndex: 'result', sorter: true, width: 140 },
  { title: tr('Duration', 'Duración'), key: 'duration', dataIndex: 'duration_sec', sorter: true, width: 90 },
  { title: tr('Notes', 'Notas'), key: 'notes', width: 240 },
  { title: '', key: 'actions', width: 90 },
]);

function edit(r) {
  reviewingReview.value = r;
  reviewing.value = { id: r.call_id, username: r.agent, lead: r.lead, result_label: r.result_label, duration_sec: r.duration_sec, campaign: r.campaign, started_at: r.started_at };
}

function moveCrit(i, d) {
  const [x] = criteria.value.splice(i, 1);
  criteria.value.splice(i + d, 0, x);
}

async function loadCriteria() {
  criteria.value = (await http.get('callcenter/quality/criteria')).rows.map(c => ({ ...c, active: !!c.active }));
}

async function saveCriteria() {
  if (criteria.value.some(c => !c.name?.trim())) return message.warning(tr('Every criterion needs a name.', 'Cada criterio necesita un nombre.'));
  savingCrit.value = true;
  try {
    criteria.value = (await http.post('callcenter/quality/criteria', { rows: criteria.value })).rows.map(c => ({ ...c, active: !!c.active }));
    message.success(tr('Saved.', 'Guardado.'));
  } catch (e) {
    message.error(errText(e, tr('Could not save.', 'No se pudo guardar.')));
  } finally {
    savingCrit.value = false;
  }
}

onMounted(async () => {
  reviews.fetchRows();
  pending.fetchRows();
  loadCriteria();
  try {
    opts.value = await http.get('callcenter/campaigns/options');
  } catch (e) { /* filters stay empty */ }
});
</script>

<style scoped>
.muted { opacity: 0.65; }
.small { font-size: 12px; }
.mb8 { margin-bottom: 8px; }
.agents { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.agent { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 6px 12px; min-width: 130px; }
.score { font-size: 20px; font-weight: 700; }
.score.ok { color: #389e0d; } .score.mid { color: #d48806; } .score.bad { color: #cf1322; }
.crit-row { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
.foot { display: flex; justify-content: space-between; margin-top: 10px; }
</style>
