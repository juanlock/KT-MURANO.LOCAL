<template>
  <!-- Change history (2026-11-12): who changed what, when and from where. SuperAdmin only (AuditLogController). -->
  <div class="page">
    <PageHeader :title="tx('Change history', 'Historial de cambios')" :breadcrumb="[tx('Settings', 'Configuración'), tx('Change history', 'Historial de cambios')]" />

    <a-card size="small">
      <div class="al-filters">
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" apply-default compact @change="reload" />
        <a-select v-model:value="filters.user_id" :options="userOptions" allow-clear show-search option-filter-prop="label" :placeholder="tx('User', 'Usuario')" style="width: 200px" @change="reload" />
        <a-select v-model:value="filters.table" :options="tableOptions" allow-clear show-search option-filter-prop="label" :placeholder="tx('Module (table)', 'Módulo (tabla)')" style="width: 200px" @change="reload" />
        <a-select v-model:value="filters.event" :options="eventOptions" allow-clear :placeholder="tx('Action', 'Acción')" style="width: 140px" @change="reload" />
        <a-input v-model:value="filters.record_id" allow-clear :placeholder="tx('Record #', 'Registro #')" style="width: 110px" @press-enter="reload" @change="e => !e.target.value && reload()" />
        <a-input-search v-model:value="filters.search" allow-clear :placeholder="tx('Search a value, user or page', 'Buscar un valor, usuario o página')" style="width: 240px" @search="reload" />
      </div>

      <a-table :data-source="rows" :columns="columns" row-key="id" size="small" :loading="loading" :scroll="{ x: 'max-content' }"
               :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: true, pageSizeOptions: ['25', '50', '100', '200'], size: 'small',
                              showTotal: n => tx(`${n} changes`, `${n} cambios`) }"
               @change="onTable">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'when'">{{ fmt(record.created_at) }}</template>
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
        </template>
      </a-table>
    </a-card>

    <a-modal :open="!!detail" :footer="null" width="760px" :title="detail ? `${detail.model} #${detail.record_id} · ${eventLabel(detail.event)}` : ''" @cancel="detail = null">
      <template v-if="detail">
        <div class="muted" style="margin-bottom: 8px">{{ fmt(detail.created_at) }} · {{ detail.user_name || tx('Public / no session', 'Público / sin sesión') }} · {{ detail.method }} {{ detail.url }}</div>
        <a-table :data-source="detailRows" :columns="detailColumns" row-key="field" size="small" :pagination="false" :scroll="{ y: 420 }" />
      </template>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useSystemContext } from '../../lib/systemContext';
import { sortByLabel } from '../../lib/filterOptions';
import { useListState } from '../../composables/useListState';

const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);
const { isSystem } = useSystemContext();

const range = ref([dayjs().subtract(6, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const filters = ref({ user_id: undefined, table: undefined, event: undefined, record_id: '', search: '' });
const page = ref(1);
const perPage = ref(50);
useListState('audit-log', { filters, range, page, perPage });

const loading = ref(false);
const rows = ref([]);
const total = ref(0);
const users = ref([]);
const tables = ref([]);
const detail = ref(null);

const EVENTS = { created: ['Created', 'Creado'], updated: ['Changed', 'Modificado'], deleted: ['Deleted', 'Eliminado'], restored: ['Restored', 'Restaurado'] };
const eventLabel = e => (EVENTS[e] ? tx(...EVENTS[e]) : e);
const eventOptions = computed(() => sortByLabel(Object.keys(EVENTS).map(k => ({ value: k, label: eventLabel(k) }))));
const userOptions = computed(() => sortByLabel(users.value.map(u => ({ value: u.user_id, label: u.user_name || `#${u.user_id}` }))));
const tableOptions = computed(() => sortByLabel(tables.value.map(t => ({ value: t, label: t }))));

const columns = computed(() => [
  { title: tx('When', 'Fecha'), key: 'when', width: 150 },
  ...(isSystem.value ? [{ title: tx('Company', 'Empresa'), dataIndex: 'company_name', key: 'company', width: 150 }] : []),
  { title: tx('User', 'Usuario'), key: 'user', width: 170 },
  { title: tx('Action', 'Acción'), key: 'event', width: 105 },
  { title: tx('Record', 'Registro'), key: 'what', width: 190 },
  { title: tx('What changed', 'Qué cambió'), key: 'changes', width: 420 },
  { title: tx('From', 'Desde'), key: 'where', width: 210 },
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

async function load() {
  loading.value = true;
  try {
    const res = await http.get('audit-logs', {
      from: range.value?.[0], to: range.value?.[1], page: page.value, per_page: perPage.value,
      ...Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== undefined && v !== null && v !== '')),
    });
    rows.value = res.rows || [];
    total.value = res.total || 0;
    users.value = res.users || [];
    tables.value = res.tables || [];
  } catch (e) {
    message.error(e?.data?.message || tx('Could not load', 'No se pudo cargar'));
  } finally {
    loading.value = false;
  }
}
function reload() { page.value = 1; load(); }
function onTable(p) { page.value = p.current; perPage.value = p.pageSize; load(); }
load();
</script>

<style scoped>
.al-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.muted { opacity: 0.6; font-size: 12px; }
.chg { font-size: 12.5px; line-height: 1.45; overflow-wrap: anywhere; max-width: 420px; }
.chg code { opacity: 0.75; margin-right: 4px; }
.sql-tag { font-size: 10px; line-height: 16px; padding: 0 4px; margin-left: 4px; cursor: help; }
.old { color: #cf1322; text-decoration: line-through; opacity: 0.8; }
.new { color: #389e0d; }
</style>
