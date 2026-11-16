<template>
  <!-- Change history (2026-11-12): who changed what, when and from where. SuperAdmin only (AuditLogController). -->
  <div class="page">
    <PageHeader :title="tx('Change history', 'Historial de cambios')" :breadcrumb="[tx('Settings', 'Configuración'), tx('Change history', 'Historial de cambios')]" />

    <!-- Our standard table (2026-11-15, user: sort by column, choose the columns, density...): DataTable + useCrudTable;
         the search box is DataTable's, the filters sit beside it. -->
    <DataTable :crud="crud" :columns="columns">
      <template #filters>
        <div class="al-filters">
          <!-- The main history holds 6 months; older changes are in the archive (never deleted). -->
          <a-segmented v-model:value="source" :options="sourceOptions" @change="crud.reload" />
          <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" apply-default @change="crud.reload" />
          <a-select v-model:value="filters.user_id" :options="userOptions" allow-clear show-search option-filter-prop="label" :placeholder="tx('User', 'Usuario')" style="width: 200px" @change="crud.reload" />
          <a-select v-model:value="filters.table" :options="tableOptions" allow-clear show-search option-filter-prop="label" :placeholder="tx('Module (table)', 'Módulo (tabla)')" style="width: 200px" @change="crud.reload" />
          <a-select v-model:value="filters.event" :options="eventOptions" allow-clear :placeholder="tx('Action', 'Acción')" style="width: 140px" @change="crud.reload" />
          <a-input v-model:value="filters.record_id" allow-clear :placeholder="tx('Record #', 'Registro #')" style="width: 110px" @press-enter="crud.reload" @change="e => !e.target.value && crud.reload()" />
        </div>
      </template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'when'">{{ fmt(record.created_at) }}</template>
          <template v-else-if="column.key === 'company'">{{ record.company_name || '—' }}</template>
          <template v-else-if="column.key === 'user'">
            <div>{{ record.user_name || tx('Public / no session', 'Público / sin sesión') }}</div>
            <div v-if="record.as_admin" class="muted">{{ tx('opened by', 'abierto por') }} {{ record.as_admin }}</div>
          </template>
          <template v-else-if="column.key === 'event'">
            <a-tag :color="{ created: 'green', updated: 'blue', deleted: 'red', restored: 'purple' }[record.event]">{{ eventLabel(record.event) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'what'">
            <div>
              <b>{{ record.model === 'SQL' ? record.table : record.model }}</b> <span v-if="record.record_id" class="muted">#{{ record.record_id }}</span>
              <a-tooltip v-if="record.model === 'SQL'" :title="tx('Recorded from the database statement: the previous value is not known.', 'Registrado desde la instrucción a la base de datos: no se conoce el valor anterior.')">
                <a-tag class="sql-tag">SQL</a-tag>
              </a-tooltip>
            </div>
            <div class="muted">{{ record.table }}</div>
          </template>
          <template v-else-if="column.key === 'changes'">
            <div v-for="(pair, field) in preview(record)" :key="field" class="chg">
              <code>{{ field }}</code>
              <template v-if="record.event === 'updated' && record.model !== 'SQL'"><span class="old">{{ short(pair[0]) }}</span> → <span class="new">{{ short(pair[1]) }}</span></template>
              <template v-else-if="record.event === 'updated'">→ <span class="new">{{ short(pair[1]) }}</span></template>
              <span v-else>{{ short(record.event === 'deleted' ? (pair[0] ?? pair[1]) : pair[1]) }}</span>
            </div>
            <a v-if="Object.keys(record.changes).length > 3 || hasLong(record)" @click="detail = record">{{ tx('See all', 'Ver todo') }} ({{ Object.keys(record.changes).length }})</a>
          </template>
          <template v-else-if="column.key === 'where'">
            <div class="muted">{{ record.method }} {{ record.url }}</div>
            <div class="muted">{{ record.ip }}</div>
          </template>
          <!-- The code file that made the change and the git commit the server ran (2026-11-15; older rows show —). -->
          <template v-else-if="column.key === 'source'">
            <code v-if="record.source_file" class="src">{{ record.source_file }}</code>
            <span v-else class="muted">—</span>
          </template>
          <template v-else-if="column.key === 'commit'">
            <code v-if="record.code_commit" class="src">{{ record.code_commit }}</code>
            <span v-else class="muted">—</span>
          </template>
        </template>
    </DataTable>

    <a-modal :open="!!detail" :footer="null" width="760px" :title="detail ? `${detail.model} #${detail.record_id} · ${eventLabel(detail.event)}` : ''" @cancel="detail = null">
      <template v-if="detail">
        <div class="muted" style="margin-bottom: 8px">{{ fmt(detail.created_at) }} · {{ detail.user_name || tx('Public / no session', 'Público / sin sesión') }} · {{ detail.method }} {{ detail.url }}</div>
        <a-table :data-source="detailRows" :columns="detailColumns" row-key="field" size="small" :pagination="false" :scroll="{ y: 420 }" />
      </template>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useUiStore } from '../../stores/ui';
import { useSystemContext } from '../../lib/systemContext';
import { sortByLabel } from '../../lib/filterOptions';
import { useListState } from '../../composables/useListState';

const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);
const { isSystem } = useSystemContext();

const range = ref([dayjs().subtract(6, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const filters = ref({ user_id: undefined, table: undefined, event: undefined, record_id: '' });
const source = ref('recent');
const sourceOptions = computed(() => [
  { value: 'recent', label: tx('Last 6 months', 'Últimos 6 meses') },
  { value: 'archive', label: tx('Archive (older)', 'Archivo (más antiguo)') },
]);
const crud = useCrudTable('audit-logs', {
  rowsKey: 'rows',
  limit: 50,
  params: () => ({
    from: range.value?.[0] || '', to: range.value?.[1] || '', archive: source.value === 'archive' ? 1 : 0,
    ...Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== undefined && v !== null && v !== '')),
  }),
});
const listState = useListState('audit-log', { filters, range, source }, crud);
const users = computed(() => crud.payload.value?.users || []);
const tables = computed(() => crud.payload.value?.tables || []);
const detail = ref(null);

const EVENTS = { created: ['Created', 'Creado'], updated: ['Changed', 'Modificado'], deleted: ['Deleted', 'Eliminado'], restored: ['Restored', 'Restaurado'] };
const eventLabel = e => (EVENTS[e] ? tx(...EVENTS[e]) : e);
const eventOptions = computed(() => sortByLabel(Object.keys(EVENTS).map(k => ({ value: k, label: eventLabel(k) }))));
const userOptions = computed(() => sortByLabel(users.value.map(u => ({ value: u.user_id, label: u.user_name || `#${u.user_id}` }))));
const tableOptions = computed(() => sortByLabel(tables.value.map(t => ({ value: t, label: t }))));

// dataIndex = the column the server sorts by (AuditLogController $sortable).
const columns = computed(() => [
  { title: tx('When', 'Fecha'), key: 'when', dataIndex: 'created_at', width: 150, sorter: true },
  ...(isSystem.value ? [{ title: tx('Company', 'Empresa'), dataIndex: 'company_id', key: 'company', width: 150, sorter: true }] : []),
  { title: tx('User', 'Usuario'), key: 'user', dataIndex: 'user_name', width: 170, sorter: true },
  { title: tx('Action', 'Acción'), key: 'event', dataIndex: 'event', width: 105, sorter: true },
  { title: tx('Record', 'Registro'), key: 'what', dataIndex: 'table_name', width: 190, sorter: true },
  { title: tx('What changed', 'Qué cambió'), key: 'changes', width: 420 },
  { title: tx('From', 'Desde'), key: 'where', dataIndex: 'url', width: 210, sorter: true },
  { title: tx('Code file', 'Archivo de código'), key: 'source', dataIndex: 'source_file', width: 260, sorter: true },
  { title: 'Commit', key: 'commit', dataIndex: 'code_commit', width: 100, sorter: true },
]);
const detailColumns = computed(() => [
  { title: tx('Field', 'Campo'), dataIndex: 'field', width: 180 },
  { title: tx('Before', 'Antes'), dataIndex: 'before' },
  { title: tx('After', 'Después'), dataIndex: 'after' },
]);
const detailRows = computed(() => Object.entries(detail.value?.changes || {}).map(([field, [b, a]]) => ({
  field, before: detail.value.model === 'SQL' && detail.value.event === 'updated' ? tx('(not recorded)', '(no registrado)') : show(b), after: show(a),
})));

const fmt = v => (v ? dayjs(v).format('DD-MM-YYYY HH:mm:ss') : '');
const show = v => (v === null || v === undefined || v === '' ? '—' : String(v));
const preview = r => Object.fromEntries(Object.entries(r.changes).slice(0, 3));
// Long values (JSON preferences, texts) are cut in the list; "See all" shows them whole.
const short = v => { const s = show(v); return s.length > 90 ? s.slice(0, 90) + '…' : s; };
const hasLong = r => Object.values(r.changes).some(([b, a]) => show(b).length > 90 || show(a).length > 90);

onMounted(() => crud.fetchRows());
void listState;
</script>

<style scoped>
.al-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.muted { opacity: 0.6; font-size: 12px; }
.chg { font-size: 12.5px; line-height: 1.45; overflow-wrap: anywhere; max-width: 420px; }
.chg code { opacity: 0.75; margin-right: 4px; }
.sql-tag { font-size: 10px; line-height: 16px; padding: 0 4px; margin-left: 4px; cursor: help; }
.src { font-size: 11.5px; padding: 1px 5px; border-radius: 4px; background: rgba(128, 128, 128, 0.12); word-break: break-all; }
.old { color: #cf1322; text-decoration: line-through; opacity: 0.8; }
.new { color: #389e0d; }
</style>
