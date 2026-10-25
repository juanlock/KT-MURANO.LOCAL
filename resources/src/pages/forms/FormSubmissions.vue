<template>
  <div class="page">
    <PageHeader :title="formName || (es ? 'Envíos' : 'Submissions')" :breadcrumb="[$t('Forms_Module'), $t('Forms_Submissions')]">
      <template #actions>
        <a-space wrap>
          <a-button @click="goBack($router, '/forms')">{{ es ? 'Volver' : 'Back' }}</a-button>
          <a-button v-if="canManage" @click="$router.push(`/forms/${formId}/edit`)"><template #icon><EditOutlined /></template>{{ es ? 'Editar formulario' : 'Edit form' }}</a-button>
          <a-button :loading="exporting" @click="exportCsv"><template #icon><DownloadOutlined /></template>{{ es ? 'Exportar Excel (CSV)' : 'Export Excel (CSV)' }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card :body-style="{ padding: '12px' }">
      <div class="fs-filters">
        <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" allow-all compact @change="reload" />
        <a-input-search v-model:value="search" :placeholder="es ? 'Buscar (texto o número)' : 'Search (text or number)'" allow-clear style="max-width: 280px" @search="reload" />
        <a-checkbox v-model:checked="unreadOnly" @change="reload">{{ es ? 'Solo sin leer' : 'Unread only' }}</a-checkbox>
        <span class="fs-muted">{{ total }} {{ es ? 'envíos' : 'submissions' }}</span>
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle" :scroll="{ x: 'max-content' }"
        :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: true, pageSizeOptions: ['20', '50', '100'] }"
        :custom-row="r => ({ onClick: () => openRow(r), class: r.is_read ? '' : 'fs-unread' })"
        @change="onTable"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'ref'"><b>{{ record.ref }}</b></template>
          <template v-else-if="column.key === 'date'">{{ fmt(record.created_at) }}</template>
          <template v-else-if="column.key.startsWith('f:')">{{ short(record.data[column.key.slice(2)]) }}</template>
        </template>
      </a-table>
    </a-card>

    <a-drawer v-model:open="drawerOpen" :width="drawerWidth" :title="openSub ? `${es ? 'Envío' : 'Submission'} ${openSub.ref}` : ''">
      <template v-if="openSub">
        <div class="fs-meta">
          <span>{{ fmt(openSub.created_at) }}</span>
          <span v-if="openSub.page_url" class="fs-muted">· <a :href="openSub.page_url" target="_blank" rel="noopener">{{ shortUrl(openSub.page_url) }}</a></span>
        </div>
        <a-descriptions :column="1" bordered size="small">
          <a-descriptions-item v-for="f in fields" :key="f.key" :label="f.label || f.key">
            <template v-if="f.type === 'file' && isFile(openSub.data[f.key])">
              <a @click="downloadFile(openSub, f.key)"><PaperClipOutlined /> {{ openSub.data[f.key].name }}</a>
            </template>
            <span v-else class="fs-value">{{ text(openSub.data[f.key]) || '—' }}</span>
          </a-descriptions-item>
          <a-descriptions-item v-for="k in extraKeys" :key="k" :label="k">
            <span class="fs-value">{{ text(openSub.data[k]) || '—' }}</span>
          </a-descriptions-item>
        </a-descriptions>
        <div class="fs-muted fs-small">IP {{ openSub.ip || '—' }}<span v-if="openSub.user_agent"> · {{ openSub.user_agent }}</span></div>
        <a-space style="margin-top: 16px">
          <a-button @click="markUnread(openSub)">{{ es ? 'Marcar sin leer' : 'Mark unread' }}</a-button>
          <a-button v-if="canManage" danger @click="removeSub(openSub)"><template #icon><DeleteOutlined /></template>{{ es ? 'Eliminar' : 'Delete' }}</a-button>
        </a-space>
      </template>
    </a-drawer>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { EditOutlined, DownloadOutlined, DeleteOutlined, PaperClipOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { goBack } from '../../lib/goBack';
import { useListState } from '../../composables/useListState';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const canManage = computed(() => auth.can('forms_manage'));
const formId = computed(() => Number(route.params.id));

const rows = ref([]);
const fields = ref([]);
const formName = ref('');
const total = ref(0);
const loading = ref(false);
const page = ref(1);
const perPage = ref(20);
const search = ref('');
const unreadOnly = ref(false);
const range = ref(null);
// Back from a submission / the form editor brings the same filters (useListState).
const listState = useListState('form-submissions', { page, perPage, search, unreadOnly, range });

const fmt = d => (d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '');
const isFile = v => v && typeof v === 'object' && !Array.isArray(v) && v.path;
const text = v => (Array.isArray(v) ? v.join(', ') : isFile(v) ? v.name : (v ?? '').toString());
const short = v => { const t = text(v); return t.length > 60 ? t.slice(0, 57) + '…' : t; };
const shortUrl = u => u.replace(/^https?:\/\//, '').slice(0, 60);
const drawerWidth = computed(() => (window.innerWidth < 700 ? '100%' : 560));

// The first columns of the form (no files / hidden), then the date.
const columns = computed(() => [
  { title: '#', key: 'ref', width: 90, fixed: 'left' },
  { title: es.value ? 'Fecha' : 'Date', key: 'date', width: 150 },
  ...fields.value.filter(f => !['file', 'hidden', 'textarea'].includes(f.type)).slice(0, 5)
    .map(f => ({ title: (f.label || f.key).replace(/:$/, ''), key: 'f:' + f.key, ellipsis: true })),
]);

function params(extra = {}) {
  return {
    search: search.value || undefined, unread: unreadOnly.value ? 1 : undefined,
    from: range.value?.[0] || undefined, to: range.value?.[1] || undefined, ...extra,
  };
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get(`forms/${formId.value}/submissions`, params({ page: page.value, per_page: perPage.value }));
    rows.value = res.submissions;
    total.value = res.total;
    fields.value = res.fields;
    formName.value = res.form.name;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}
function reload() {
  page.value = 1;
  load();
}
function onTable(p) {
  page.value = p.current;
  perPage.value = p.pageSize;
  load();
}

// ---- one submission
const drawerOpen = ref(false);
const openSub = ref(null);
const extraKeys = computed(() => {
  if (!openSub.value) return [];
  const known = new Set(fields.value.map(f => f.key));
  return Object.keys(openSub.value.data).filter(k => !known.has(k));
});
async function openRow(r) {
  try {
    const res = await http.get(`forms/submissions/${r.id}`);
    openSub.value = res.submission;
    drawerOpen.value = true;
    r.is_read = true;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  }
}
async function markUnread(sub) {
  await http.put(`forms/submissions/${sub.id}`, { is_read: false });
  const r = rows.value.find(x => x.id === sub.id);
  if (r) r.is_read = false;
  drawerOpen.value = false;
}
function removeSub(sub) {
  Modal.confirm({
    title: (es.value ? '¿Eliminar el envío ' : 'Delete submission ') + sub.ref + '?',
    icon: createVNode(ExclamationCircleOutlined),
    okType: 'danger',
    okText: es.value ? 'Eliminar' : 'Delete',
    async onOk() {
      await http.delete(`forms/submissions/${sub.id}`);
      drawerOpen.value = false;
      load();
    },
  });
}
async function downloadFile(sub, key) {
  try {
    const blob = await http.blob(`forms/submissions/${sub.id}/file/${key}`, true);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = sub.data[key].name || 'file';
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    message.error(e.message);
  }
}

// ---- export: every submission matching the filters, as CSV that Excel opens (UTF-8 BOM).
const exporting = ref(false);
async function exportCsv() {
  exporting.value = true;
  try {
    const res = await http.get(`forms/${formId.value}/submissions`, params({ all: 1 }));
    const cols = res.fields.filter(f => f.type !== 'hidden');
    const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const head = ['#', es.value ? 'Fecha' : 'Date', ...cols.map(f => (f.label || f.key).replace(/:$/, '')), es.value ? 'Página' : 'Page', 'IP'];
    const lines = [head.map(esc).join(',')];
    for (const s of res.submissions) {
      lines.push([s.ref, fmt(s.created_at), ...cols.map(f => text(s.data[f.key])), s.page_url || '', s.ip || ''].map(esc).join(','));
    }
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${(res.form.name || 'form').replace(/[^\w\- ]+/g, '').trim() || 'form'} ${dayjs().format('YYYY-MM-DD')}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    exporting.value = false;
  }
}

onMounted(async () => {
  await load();
  // ?open=ID (link in the notification email)
  const open = Number(route.query.open);
  if (open) openRow({ id: open });
  if (listState && listState.restored) { /* filters already restored before the first load */ }
});
</script>

<style scoped>
.fs-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 12px; }
.fs-muted { opacity: 0.6; }
.fs-small { font-size: 12px; margin-top: 10px; word-break: break-all; }
.fs-meta { margin-bottom: 12px; }
.fs-value { white-space: pre-wrap; }
:deep(.fs-unread) td { font-weight: 600; }
:deep(.ant-table-row) { cursor: pointer; }
</style>
