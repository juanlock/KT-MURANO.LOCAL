<template>
  <div class="page">
    <PageHeader
      title="KT-Log"
      :subtitle="tx('Every request worked with Claude (Protocol Kelsy), newest first.', 'Cada pedido trabajado con Claude (Protocolo Kelsy), lo más reciente primero.')"
      :breadcrumb="[tx('Settings', 'Ajustes'), 'KT-Log']"
    />

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <PresetRangeFilter v-model:value="filters.range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-select v-model:value="filters.location" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="tx('All locations', 'Todas las ubicaciones')" :options="opt('locations')" @change="crud.reload" />
        <a-select v-model:value="filters.category" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="tx('All categories', 'Todas las categorías')" :options="opt('categories')" @change="crud.reload" />
        <a-select v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="tx('All statuses', 'Todos los estados')" :options="opt('statuses')" @change="crud.reload" />
        <a-select v-model:value="filters.claude_user" class="tb-item tb-user" allow-clear show-search option-filter-prop="label"
          :placeholder="tx('All users', 'Todos los usuarios')" :options="opt('users')" @change="crud.reload" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'id'">
          <a class="link" @click="openLog(record.id)">#{{ record.id }}</a>
          <div class="muted small">v{{ record.version }}</div>
        </template>
        <template v-else-if="column.key === 'log_date'">
          {{ date(record.log_date) }}
          <div class="muted small">{{ time(record.created_at) }}</div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ record.status || '—' }}</a-tag>
        </template>
        <template v-else-if="column.key === 'summary'">
          <a class="clamp clamp--link" @click="openLog(record.id)">{{ record.summary }}</a>
          <div v-if="record.company_context" class="muted small clamp1">{{ record.company_context }}</div>
        </template>
        <template v-else-if="column.key === 'request_user'">
          <div class="clamp req">{{ record.request_user }}</div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button type="link" size="small" @click="openLog(record.id)">{{ tx('Open', 'Ver') }}</a-button>
        </template>
      </template>
    </DataTable>

    <!-- The whole row, in kt_logs' own column order (Protocol Kelsy). -->
    <a-drawer :open="!!log || loadingLog" :width="drawerWidth" placement="right" @close="log = null">
      <template #title>
        <span v-if="log">KT-Log #{{ log.id }} · v{{ log.version }}</span>
      </template>
      <template #extra>
        <a-space v-if="log">
          <a-tooltip :title="tx('Newer', 'Más reciente')"><a-button :disabled="!neighbor(-1)" @click="openLog(neighbor(-1))"><UpOutlined /></a-button></a-tooltip>
          <a-tooltip :title="tx('Older', 'Anterior')"><a-button :disabled="!neighbor(1)" @click="openLog(neighbor(1))"><DownOutlined /></a-button></a-tooltip>
        </a-space>
      </template>
      <a-spin :spinning="loadingLog">
        <template v-if="log">
          <a-descriptions bordered size="small" :column="{ xs: 1, md: 2 }" class="head">
            <a-descriptions-item :label="tx('Date', 'Fecha')">{{ date(log.log_date) }} · {{ time(log.created_at) }}</a-descriptions-item>
            <a-descriptions-item :label="tx('Location', 'Ubicación')">{{ log.location || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="tx('Status', 'Estado')"><a-tag :color="statusColor(log.status)">{{ log.status || '—' }}</a-tag></a-descriptions-item>
            <a-descriptions-item :label="tx('Category', 'Categoría')">{{ log.category || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="tx('Company', 'Empresa')" :span="2">{{ log.company_context || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="tx('User', 'Usuario')" :span="2">{{ log.claude_user || '—' }}</a-descriptions-item>
          </a-descriptions>

          <section class="sec">
            <h4>{{ tx('Summary', 'Resumen') }}</h4>
            <p class="plain">{{ log.summary || '—' }}</p>
          </section>
          <section class="sec">
            <h4>{{ tx('What the user asked', 'Lo que pidió el usuario') }}</h4>
            <blockquote class="plain quote">{{ log.request_user || '—' }}</blockquote>
          </section>
          <section class="sec">
            <h4>{{ tx('What Claude understood', 'Lo que entendió Claude') }}</h4>
            <p class="plain">{{ log.claude_understand || '—' }}</p>
          </section>
          <section class="sec sec--done">
            <h4>
              {{ tx('Closing message (as sent in the chat)', 'Mensaje final (tal como se envió en el chat)') }}
              <a-button v-if="log.done" type="link" size="small" @click="copy(log.done)"><CopyOutlined /> {{ tx('Copy', 'Copiar') }}</a-button>
            </h4>
            <!-- eslint-disable-next-line vue/no-v-html -- escaped + DOMPurify (lib below) -->
            <div v-if="log.done" class="md" v-html="markdown(log.done)"></div>
            <p v-else class="muted">—</p>
          </section>
          <section class="sec">
            <h4>{{ tx('Changes', 'Cambios') }}</h4>
            <p class="plain">{{ log.changes_detail || '—' }}</p>
          </section>
          <section class="sec">
            <h4>{{ tx('Files', 'Archivos') }} <span class="muted small">({{ files(log.files_modified).length }})</span></h4>
            <ul v-if="files(log.files_modified).length" class="files">
              <li v-for="f in files(log.files_modified)" :key="f"><code>{{ f }}</code></li>
            </ul>
            <p v-else class="muted">—</p>
          </section>
          <section class="sec">
            <h4>{{ tx('What was done', 'Qué se hizo') }}</h4>
            <p class="plain">{{ log.actions || '—' }}</p>
          </section>
          <section class="sec">
            <h4>{{ tx('Conversation', 'Conversación') }}</h4>
            <p class="plain">{{ log.chat_transcript || '—' }}</p>
          </section>
        </template>
      </a-spin>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * KT-Log (2026-10-26): the kt_logs history -- every request worked with Claude
 * (Protocol Kelsy) -- with our standard filters (date presets, location,
 * category, status, user, search) and the whole row in a drawer, the closing
 * message shown with its own format. superadmin/AdminSystem only (route
 * systemOnly + superAdminOnly, KtLogController::authorizeSystem).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { UpOutlined, DownOutlined, CopyOutlined } from '@ant-design/icons-vue';
import DOMPurify from 'dompurify';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useListState } from '../../composables/useListState';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';

const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);
const { date, dateTime } = useFormat();
const time = v => (v ? String(dateTime(v)).split(' ').slice(1).join(' ') : '');

const filters = ref({ range: null, location: undefined, category: undefined, status: undefined, claude_user: undefined });

const crud = useCrudTable('kt_logs', {
  rowsKey: 'logs',
  limit: 25,
  params: () => ({
    from: filters.value.range?.[0] || '',
    to: filters.value.range?.[1] || '',
    location: filters.value.location || '',
    category: filters.value.category || '',
    status: filters.value.status || '',
    claude_user: filters.value.claude_user || '',
  }),
});
// Back from elsewhere = the same filters, page and search (Frontend Conventions).
const listState = useListState('kt-log', { filters }, crud);

const opt = key => sortByLabel((crud.payload.value?.options?.[key] || []).map(v => ({ value: v, label: v })));

const columns = computed(() => [
  { title: '#', key: 'id', dataIndex: 'id', width: 90, sorter: true },
  { title: tx('Date', 'Fecha'), key: 'log_date', dataIndex: 'log_date', width: 120, sorter: true },
  { title: tx('Location', 'Ubicación'), key: 'location', dataIndex: 'location', width: 110, sorter: true },
  { title: tx('Status', 'Estado'), key: 'status', dataIndex: 'status', width: 115, sorter: true },
  { title: tx('Category', 'Categoría'), key: 'category', dataIndex: 'category', width: 120, sorter: true },
  { title: tx('Summary', 'Resumen'), key: 'summary', dataIndex: 'summary' },
  { title: tx('Request', 'Pedido'), key: 'request_user', dataIndex: 'request_user', width: '28%' },
  { title: '', key: 'actions', width: 70, align: 'center' },
]);

const statusColor = s => ({ completed: 'green', 'in progress': 'blue', blocked: 'red' }[String(s || '').toLowerCase()] || 'default');
const files = v => String(v || '').split(/[\n,]+/).map(x => x.trim()).filter(Boolean);

// ---------------- drawer ----------------
const log = ref(null);
const loadingLog = ref(false);
const drawerWidth = computed(() => (typeof window !== 'undefined' && window.innerWidth < 900 ? '100%' : 860));

async function openLog(id) {
  if (!id) return;
  loadingLog.value = true;
  try {
    log.value = (await http.get(`kt_logs/${id}`))?.log || null;
  } catch (e) {
    message.error(tx('Could not open that entry', 'No se pudo abrir ese registro'));
  } finally {
    loadingLog.value = false;
  }
}
// Newer (-1) / older (+1) entry on the current page of the list.
function neighbor(step) {
  const rows = crud.rows.value || [];
  const i = rows.findIndex(r => r.id === log.value?.id);
  return i < 0 ? null : rows[i + step]?.id || null;
}

async function copy(text) {
  try { await navigator.clipboard.writeText(text); message.success(tx('Copied', 'Copiado')); } catch (e) { /* clipboard blocked */ }
}

// ---------------- markdown (the closing message's own format) ----------------
// Small subset -- paragraphs, **bold**, `code`, - bullets (nested by indent), 1. lists.
// The text is HTML-escaped first and the result goes through DOMPurify.
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const inline = s => esc(s)
  .replace(/`([^`]+)`/g, '<code>$1</code>')
  .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
function markdown(src) {
  const out = [];
  const stack = []; // open lists: { indent, tag }
  const closeTo = (indent) => {
    while (stack.length && stack[stack.length - 1].indent >= indent) out.push(`</li></${stack.pop().tag}>`);
  };
  let para = [];
  const flush = () => { if (para.length) { out.push(`<p>${para.map(inline).join('<br>')}</p>`); para = []; } };
  for (const raw of String(src).replace(/\r/g, '').split('\n')) {
    const m = raw.match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
    if (m) {
      flush();
      const indent = m[1].length;
      const tag = /\d/.test(m[2]) ? 'ol' : 'ul';
      const top = stack[stack.length - 1];
      if (!top || indent > top.indent) {
        out.push(`<${tag}><li>`);
        stack.push({ indent, tag });
      } else {
        closeTo(indent + 1);
        const cur = stack[stack.length - 1];
        if (cur && cur.indent === indent && cur.tag === tag) out.push('</li><li>');
        else { closeTo(indent); out.push(`<${tag}><li>`); stack.push({ indent, tag }); }
      }
      out.push(inline(m[3]));
    } else if (!raw.trim()) {
      flush();
      closeTo(0);
    } else if (stack.length && /^\s+/.test(raw)) {
      out.push(`<br>${inline(raw.trim())}`); // a continuation line inside a list item
    } else {
      closeTo(0);
      para.push(raw.trim());
    }
  }
  flush();
  closeTo(0);
  return DOMPurify.sanitize(out.join(''));
}

onMounted(() => {
  // Restored (Back) = its own filters; otherwise PresetRangeFilter applies the user's
  // default preset and its @change loads. First load in any case:
  if (listState.restored || !filters.value.range) crud.fetchRows();
});
</script>

<style scoped>
.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.tb-item { width: 170px; }
.tb-user { width: 220px; }
.link { cursor: pointer; font-weight: 600; }
.muted { opacity: 0.6; }
.small { font-size: 12px; }
.clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: 13px; }
.clamp--link { color: inherit; }
.clamp--link:hover { color: #1677ff; }
.clamp1 { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 420px; }
.req { opacity: 0.8; font-style: italic; }
.head { margin-bottom: 8px; }
.sec { margin-top: 18px; }
.sec h4 { margin: 0 0 6px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75; display: flex; align-items: center; gap: 6px; }
.plain { white-space: pre-wrap; margin: 0; font-size: 13.5px; line-height: 1.55; }
.quote { border-left: 3px solid #1677ff; padding: 6px 12px; background: rgba(22, 119, 255, 0.06); border-radius: 0 6px 6px 0; }
.sec--done { padding: 12px 14px; border: 1px solid rgba(22, 163, 74, 0.35); border-radius: 10px; background: rgba(22, 163, 74, 0.05); }
.md { font-size: 14px; line-height: 1.6; }
.md :deep(p) { margin: 0 0 10px; }
.md :deep(ul), .md :deep(ol) { margin: 0 0 10px; padding-left: 22px; }
.md :deep(li) { margin: 2px 0; }
.md :deep(code), .files code { font-size: 12px; padding: 1px 5px; border-radius: 4px; background: rgba(128, 128, 128, 0.15); }
.files { margin: 0; padding-left: 18px; font-size: 12.5px; columns: 2; column-gap: 24px; }
@media (max-width: 767px) {
  .tb-item, .tb-user { width: 100%; }
  .files { columns: 1; }
}
</style>
