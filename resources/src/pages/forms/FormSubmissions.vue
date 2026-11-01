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
          <template v-else-if="column.key === 'attended'"><a-tag v-if="record.attended_at" color="green">{{ es ? 'Sí' : 'Yes' }}</a-tag></template>
          <template v-else-if="column.key.startsWith('f:')">{{ short(record.data[column.key.slice(2)]) }}</template>
        </template>
      </a-table>
    </a-card>

    <!-- One submission (2026-11-01): a large window, Previous / Next through the list,
         Print, PDF, Send by email, Attended with a note; Delete only with Manage forms. -->
    <a-modal v-model:open="drawerOpen" :width="modalWidth" :footer="null" :title="openSub ? `${es ? 'Envío' : 'Submission'} ${openSub.ref}` : ''" class="fs-modal">
      <template v-if="openSub">
        <div class="fs-nav">
          <a-button :disabled="!hasPrev" @click="step(-1)"><LeftOutlined /> {{ es ? 'Anterior' : 'Previous' }}</a-button>
          <span class="fs-muted">{{ position }}</span>
          <a-button :disabled="!hasNext" @click="step(1)">{{ es ? 'Siguiente' : 'Next' }} <RightOutlined /></a-button>
        </div>
        <div class="fs-meta">
          <span>{{ fmt(openSub.created_at) }}</span>
          <span v-if="openSub.page_url" class="fs-muted">· <a :href="openSub.page_url" target="_blank" rel="noopener">{{ shortUrl(openSub.page_url) }}</a></span>
          <a-tag v-if="openSub.attended_at" color="green" style="margin-left: 8px">{{ es ? 'Atendido' : 'Attended' }} {{ fmt(openSub.attended_at) }}<span v-if="openSub.attended_by_name"> · {{ openSub.attended_by_name }}</span></a-tag>
        </div>
        <a-alert v-if="openSub.attended_note" type="success" :message="openSub.attended_note" style="margin-bottom: 12px; white-space: pre-wrap" />
        <a-descriptions :column="descColumns" bordered size="small">
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
        <a-space wrap style="margin-top: 16px">
          <a-button v-if="!openSub.attended_at" type="primary" @click="attendOpen = true"><CheckOutlined /> {{ es ? 'Marcar atendido' : 'Mark attended' }}</a-button>
          <a-button v-else @click="setAttended(false)">{{ es ? 'Quitar atendido' : 'Not attended' }}</a-button>
          <a-button @click="printSub"><PrinterOutlined /> {{ es ? 'Imprimir' : 'Print' }}</a-button>
          <a-button :loading="pdfBusy" @click="pdfSub"><FilePdfOutlined /> PDF</a-button>
          <a-button @click="sendOpen = true"><MailOutlined /> {{ es ? 'Enviar por correo' : 'Send by email' }}</a-button>
          <a-button @click="markUnread(openSub)">{{ es ? 'Marcar sin leer' : 'Mark unread' }}</a-button>
          <a-button v-if="canManage" danger @click="removeSub(openSub)"><template #icon><DeleteOutlined /></template>{{ es ? 'Eliminar' : 'Delete' }}</a-button>
        </a-space>
      </template>
    </a-modal>

    <a-modal v-model:open="attendOpen" :title="es ? 'Marcar atendido' : 'Mark attended'" :ok-text="es ? 'Guardar' : 'Save'" :confirm-loading="attendBusy" @ok="setAttended(true)">
      <a-textarea v-model:value="attendNote" :auto-size="{ minRows: 3, maxRows: 8 }" :placeholder="es ? 'Nota: qué se hizo (opcional)' : 'Note: what was done (optional)'" />
    </a-modal>

    <a-modal v-model:open="sendOpen" :title="es ? 'Enviar el envío por correo' : 'Send the submission by email'" :ok-text="es ? 'Enviar' : 'Send'" :confirm-loading="sendBusy" @ok="resend">
      <a-form layout="vertical">
        <a-form-item :label="es ? 'Para' : 'To'">
          <a-select v-model:value="sendTo" mode="tags" :token-separators="[',', ' ', ';']" :placeholder="es ? 'correo@empresa.com' : 'email@company.com'" />
        </a-form-item>
        <a-form-item :label="es ? 'Mensaje (opcional)' : 'Message (optional)'">
          <a-textarea v-model:value="sendMsg" :auto-size="{ minRows: 2, maxRows: 6 }" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import {
  EditOutlined, DownloadOutlined, DeleteOutlined, PaperClipOutlined, ExclamationCircleOutlined,
  LeftOutlined, RightOutlined, CheckOutlined, PrinterOutlined, FilePdfOutlined, MailOutlined,
} from '@ant-design/icons-vue';
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
const modalWidth = computed(() => (window.innerWidth < 700 ? '100%' : Math.min(1000, window.innerWidth - 80)));
const descColumns = computed(() => (window.innerWidth < 900 ? 1 : 2));

// The first columns of the form (no files / hidden), then the date.
const columns = computed(() => [
  { title: '#', key: 'ref', width: 90, fixed: 'left' },
  { title: es.value ? 'Fecha' : 'Date', key: 'date', width: 150 },
  { title: es.value ? 'Atendido' : 'Attended', key: 'attended', width: 100 },
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
// Previous / Next through the list (crosses to the next / previous page).
const index = computed(() => (openSub.value ? rows.value.findIndex(r => r.id === openSub.value.id) : -1));
const hasPrev = computed(() => index.value > 0 || page.value > 1);
const hasNext = computed(() => (index.value >= 0 && index.value < rows.value.length - 1) || page.value * perPage.value < total.value);
const position = computed(() => (index.value >= 0 ? `${(page.value - 1) * perPage.value + index.value + 1} / ${total.value}` : ''));
async function step(d) {
  const i = index.value + d;
  if (i >= 0 && i < rows.value.length) return openRow(rows.value[i]);
  page.value += d;
  await load();
  if (rows.value.length) openRow(d > 0 ? rows.value[0] : rows.value[rows.value.length - 1]);
}
function keepRow(sub) {
  openSub.value = sub;
  const r = rows.value.find(x => x.id === sub.id);
  if (r) Object.assign(r, { is_read: sub.is_read, attended_at: sub.attended_at, attended_note: sub.attended_note });
}

// Attended + note
const attendOpen = ref(false);
const attendBusy = ref(false);
const attendNote = ref('');
async function setAttended(on) {
  attendBusy.value = true;
  try {
    const res = await http.put(`forms/submissions/${openSub.value.id}`, { attended: on, note: on ? attendNote.value : null });
    keepRow(res.submission);
    attendOpen.value = false;
    attendNote.value = '';
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    attendBusy.value = false;
  }
}

// PDF / Print (the same PDF, opened for the browser's print dialog)
const pdfBusy = ref(false);
async function pdfBlob() {
  return http.blob(`forms/submissions/${openSub.value.id}/pdf`, true);
}
async function pdfSub() {
  pdfBusy.value = true;
  try {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(await pdfBlob());
    a.download = `${formName.value || 'form'} ${openSub.value.ref}.pdf`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    message.error(e.message);
  } finally {
    pdfBusy.value = false;
  }
}
async function printSub() {
  const w = window.open('', '_blank');
  try {
    const url = URL.createObjectURL(await pdfBlob());
    if (w) w.location.href = url; else window.open(url, '_blank');
  } catch (e) {
    if (w) w.close();
    message.error(e.message);
  }
}

// Send by email
const sendOpen = ref(false);
const sendBusy = ref(false);
const sendTo = ref([]);
const sendMsg = ref('');
async function resend() {
  if (!sendTo.value.length) return message.warning(es.value ? 'Escribe al menos un correo.' : 'Type at least one email.');
  sendBusy.value = true;
  try {
    const res = await http.post(`forms/submissions/${openSub.value.id}/resend`, { to: sendTo.value, message: sendMsg.value });
    message.success((es.value ? 'Enviado a ' : 'Sent to ') + res.sent.join(', '));
    sendOpen.value = false;
    sendTo.value = [];
    sendMsg.value = '';
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || (es.value ? 'No se pudo enviar.' : 'Could not send it.'));
  } finally {
    sendBusy.value = false;
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
.fs-nav { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
.fs-value { white-space: pre-wrap; }
:deep(.fs-unread) td { font-weight: 600; }
:deep(.ant-table-row) { cursor: pointer; }
</style>
