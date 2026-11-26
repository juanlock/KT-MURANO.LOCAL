<template>
  <div class="page">
    <PageHeader :title="es ? 'Últimos mensajes' : 'Latest messages'" :breadcrumb="[$t('Forms_Module'), es ? 'Últimos mensajes' : 'Latest messages']">
      <template #actions>
        <a-button :loading="exporting" @click="exportCsv"><template #icon><DownloadOutlined /></template>{{ es ? 'Exportar Excel (CSV)' : 'Export Excel (CSV)' }}</a-button>
      </template>
    </PageHeader>

    <!-- Reclamos / Contáctenos (web): the complaints-book forms apart from the contact forms (2026-11-26). -->
    <div class="fr-seg-row">
      <a-segmented v-model:value="group" :options="groupOptions" @change="onGroup" />
      <!-- Contáctenos (web): the automatic reply per topic, assigned here and changed or turned off later (2026-11-26). -->
      <a-button v-if="group === 'contact' && replyForm" @click="repliesOpen = true"><MailOutlined /> {{ es ? 'Respuestas automáticas' : 'Automatic replies' }}</a-button>
    </div>
    <FormTopicReplies v-model:open="repliesOpen" :form="replyForm" />

    <!-- Our standard table (user's rule, 2026-11-15 / 2026-11-26): DataTable + useCrudTable, the full date filter and the
         other filters beside the search. -->
    <DataTable :crud="crud" :columns="columns" compact-toolbar table-key="forms-report">
      <template #filters>
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" allow-all @change="crud.reload" />
        <a-select v-model:value="formId" :options="formOptions" allow-clear show-search option-filter-prop="label" style="width: 240px" :placeholder="es ? 'Todos los formularios' : 'All forms'" @change="crud.reload" />
        <a-select v-model:value="readFilter" :options="readOptions" allow-clear style="width: 150px" :placeholder="es ? 'Leídos y por leer' : 'Read and unread'" @change="crud.reload" />
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'ref'"><b>{{ record.ref }}</b><a-badge v-if="!record.is_read" status="processing" class="fr-dot" /></template>
        <template v-else-if="column.key === 'date'">{{ fmt(record.created_at) }}</template>
        <template v-else-if="column.key === 'form'">
          <router-link :to="`/forms/${record.form_id}/submissions`">{{ record.form }}</router-link>
        </template>
        <template v-else-if="column.key === 'summary'"><span class="fr-ellipsis">{{ record.summary || '—' }}</span></template>
        <template v-else-if="column.key === 'page'">
          <a v-if="record.page_url" :href="record.page_url" target="_blank" rel="noopener" class="fr-ellipsis">{{ host(record.page_url) }}</a>
          <span v-else class="fr-muted">—</span>
        </template>
      </template>
      <template #expandedRowRender="{ record }">
        <a-descriptions :column="{ xs: 1, md: 2 }" size="small" bordered>
          <a-descriptions-item v-for="(d, i) in record.details" :key="i" :label="d.label"><span class="fr-value">{{ d.value || '—' }}</span></a-descriptions-item>
        </a-descriptions>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Formularios -> Últimos mensajes (ex "Reporte de envíos", 2026-11-26): every message the company's forms received, split into
 * Reclamos and Contáctenos (web); on Contáctenos (web) the automatic reply per topic. Server: FormsController@report.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { DownloadOutlined, MailOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import DataTable from '../../components/DataTable.vue';
import FormTopicReplies from '../../components/FormTopicReplies.vue';
import http from '../../lib/http';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useUiStore } from '../../stores/ui';
import { useSystemContext } from '../../lib/systemContext';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const { isSystem } = useSystemContext();
const { dateTime } = useFormat();

const formId = ref(undefined);
const range = ref(null);
// Read / unread (the dashboard's cards link here with ?read=1|0&from&to).
const readFilter = ref(undefined);
const readOptions = computed(() => sortByLabel([{ value: '1', label: es.value ? 'Leídos' : 'Read' }, { value: '0', label: es.value ? 'Por leer' : 'Unread' }]));
// Reclamos | Contáctenos (web) | Todos.
const group = ref('complaints');
const route = useRoute();
if (['0', '1'].includes(String(route.query.read))) {
  readFilter.value = String(route.query.read);
  if (route.query.from && route.query.to) range.value = [String(route.query.from), String(route.query.to)];
}

function params(extra = {}) {
  return { group: group.value !== 'all' ? group.value : undefined, form_id: formId.value || undefined, read: readFilter.value ?? undefined,
    from: range.value?.[0] || undefined, to: range.value?.[1] || undefined, ...extra };
}
const crud = useCrudTable('forms/report', { rowsKey: 'submissions', sortField: 'created_at', sortType: 'desc', limit: 25, params: () => params() });
useListState('forms-report', { formId, range, readFilter, group }, crud);

const forms = computed(() => crud.payload.value?.forms || []);
const groups = computed(() => crud.payload.value?.groups || { complaints: 0, contact: 0 });
const groupOptions = computed(() => [
  { value: 'complaints', label: `${es.value ? 'Reclamos' : 'Complaints'} (${groups.value.complaints ?? 0})` },
  { value: 'contact', label: `${es.value ? 'Contáctenos (web)' : 'Contact us (web)'} (${groups.value.contact ?? 0})` },
  { value: 'all', label: es.value ? 'Todos' : 'All' },
]);
// The contact form whose automatic replies the button opens: the one picked, else the first contact form.
const repliesOpen = ref(false);
const replyForm = computed(() => {
  const contact = forms.value.filter(f => f.group === 'contact');
  const f = contact.find(x => x.id === formId.value) || contact[0];
  return f ? { id: f.id, name: f.name } : null;
});
function onGroup() {
  if (formId.value && !forms.value.some(f => f.id === formId.value && (group.value === 'all' || f.group === group.value))) formId.value = undefined;
  crud.reload();
}

const fmt = d => (d ? dateTime(d) : '');
const host = u => u.replace(/^https?:\/\/(www\.)?/, '').slice(0, 50);
const formOptions = computed(() => sortByLabel(forms.value.filter(f => group.value === 'all' || f.group === group.value)
  .map(f => ({ value: f.id, label: isSystem.value ? `${f.name} · ${f.company_name}` : f.name }))));

const columns = computed(() => [
  ...(isSystem.value ? [{ title: es.value ? 'Empresa' : 'Company', dataIndex: 'company_name', key: 'company' }] : []),
  { title: es.value ? 'Formulario' : 'Form', key: 'form', dataIndex: 'form' },
  { title: '#', key: 'ref', dataIndex: 'ref', width: 110, sorter: true },
  { title: es.value ? 'Fecha' : 'Date', key: 'date', dataIndex: 'created_at', width: 150, sorter: true },
  { title: es.value ? 'Resumen' : 'Summary', key: 'summary', dataIndex: 'summary' },
  { title: es.value ? 'Página' : 'Page', key: 'page', dataIndex: 'page_url' },
]);

// Every message matching the filters: one row each, its fields as "Label: value" columns.
const exporting = ref(false);
async function exportCsv() {
  exporting.value = true;
  try {
    const res = await http.get('forms/report', params({ all: 1, search: crud.search.value || undefined }));
    const labels = [];
    res.submissions.forEach(s => s.details.forEach(d => { if (!labels.includes(d.label)) labels.push(d.label); }));
    const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const head = [...(isSystem.value ? [es.value ? 'Empresa' : 'Company'] : []), es.value ? 'Formulario' : 'Form', '#', es.value ? 'Fecha' : 'Date', ...labels, es.value ? 'Página' : 'Page', 'IP'];
    const lines = [head.map(esc).join(',')];
    for (const s of res.submissions) {
      const byLabel = Object.fromEntries(s.details.map(d => [d.label, d.value]));
      lines.push([...(isSystem.value ? [s.company_name] : []), s.form, s.ref, fmt(s.created_at), ...labels.map(l => byLabel[l] ?? ''), s.page_url || '', s.ip || ''].map(esc).join(','));
    }
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${es.value ? 'mensajes' : 'messages'} ${group.value !== 'all' ? group.value + ' ' : ''}${dayjs().format('YYYY-MM-DD')}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    exporting.value = false;
  }
}

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.fr-seg-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
.fr-muted { opacity: 0.6; }
.fr-dot { margin-inline-start: 6px; }
.fr-ellipsis { display: inline-block; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
.fr-value { white-space: pre-wrap; }
</style>
