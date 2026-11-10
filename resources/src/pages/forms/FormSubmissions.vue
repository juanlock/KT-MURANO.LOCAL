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
          <template v-else-if="column.key.startsWith('f:')">{{ cell(record.data[column.key.slice(2)]) }}</template>
        </template>
      </a-table>
    </a-card>

    <!-- One submission (2026-11-01): a large window, Previous / Next through the list,
         Print, PDF, Send by email, Attended with a note; Delete only with Manage forms. -->
    <a-modal v-model:open="drawerOpen" :width="modalWidth" :footer="null" :title="openSub ? `${es ? 'Envío' : 'Submission'} ${openSub.ref}` : ''" class="fs-modal">
      <template v-if="openSub">
        <div class="fs-nav">
          <a-button :disabled="!hasPrev" @click="step(-1)"><LeftOutlined /> {{ es ? 'Anterior' : 'Previous' }}</a-button>
          <span class="fs-meta">
            <span class="fs-muted">{{ position }}</span> · <span>{{ fmt(openSub.created_at) }}</span>
            <span v-if="openSub.page_url" class="fs-muted"> · <a :href="openSub.page_url" target="_blank" rel="noopener">{{ shortUrl(openSub.page_url) }}</a></span>
            <a-tag v-if="openSub.attended_at" color="green" style="margin-left: 8px">{{ es ? 'Atendido' : 'Attended' }} {{ fmt(openSub.attended_at) }}<span v-if="openSub.attended_by_name"> · {{ openSub.attended_by_name }}</span></a-tag>
          </span>
          <a-button :disabled="!hasNext" @click="step(1)">{{ es ? 'Siguiente' : 'Next' }} <RightOutlined /></a-button>
        </div>
        <a-alert v-if="openSub.attended_note" type="success" :message="openSub.attended_note" style="margin-bottom: 6px; padding: 4px 10px; white-space: pre-wrap" />
        <div class="fs-grid" :class="{ 'fs-grid-1': descColumns === 1 }">
          <template v-for="(x, i) in viewItems" :key="x.key || 'h' + i">
            <div v-if="x.heading" class="fs-section">{{ x.heading }}</div>
            <div v-else class="fs-cell" :style="{ gridColumn: 'span ' + cellSpan(x) }">
              <div class="fs-label">{{ (byKey[x.key].label || x.key).replace(/:$/, '') }}</div>
              <a v-if="byKey[x.key].type === 'file' && isFile(openSub.data[x.key])" @click="downloadFile(openSub, x.key)"><PaperClipOutlined /> {{ openSub.data[x.key].name }}</a>
              <span v-else class="fs-value">{{ text(openSub.data[x.key]) || '—' }}</span>
            </div>
          </template>
          <div v-for="k in extraKeys" :key="k" class="fs-cell fs-full">
            <div class="fs-label">{{ k }}</div>
            <span class="fs-value">{{ text(openSub.data[k]) || '—' }}</span>
          </div>
        </div>
        <div class="fs-muted fs-small">IP {{ openSub.ip || '—' }}<span v-if="openSub.user_agent"> · {{ openSub.user_agent }}</span></div>
        <!-- Imported rows corrected on 2026-11-10: the data as it was imported, to compare. -->
        <div v-if="canManage && originalOf(openSub)" class="fs-original">
          <a @click="showOriginal = !showOriginal">{{ showOriginal ? (es ? 'Ocultar original' : 'Hide original') : (es ? 'Ver original (antes de la corrección)' : 'See original (before the correction)') }}</a>
          <div v-if="showOriginal" class="fs-original-box">
            <div class="fs-muted fs-small">{{ es ? 'Así se importó, con cada valor bajo la clave antigua. Se corrigió el 10-11-2026.' : 'As imported, each value under its old key. Corrected on 2026-11-10.' }}</div>
            <div v-for="(v, k) in originalOf(openSub)" :key="k" class="fs-original-row"><code>{{ k }}</code> <span>{{ text(v) || '—' }}</span></div>
          </div>
        </div>
        <a-space wrap style="margin-top: 8px">
          <a-button v-if="!openSub.attended_at" type="primary" @click="attendOpen = true"><CheckOutlined /> {{ es ? 'Marcar atendido' : 'Mark attended' }}</a-button>
          <a-button v-else @click="setAttended(false)">{{ es ? 'Quitar atendido' : 'Not attended' }}</a-button>
          <a-tooltip :title="openSub.reply_to ? openSub.reply_to : (es ? 'El envío no tiene un correo válido' : 'The submission has no valid email')">
            <a-button :disabled="!openSub.reply_to" @click="openReply"><SendOutlined /> {{ es ? 'Responder' : 'Reply' }}</a-button>
          </a-tooltip>
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

    <!-- Reply (2026-11-03): an email to the person who sent the form; marks it attended. -->
    <a-modal v-model:open="replyOpen" :title="es ? 'Responder al remitente' : 'Reply to the sender'" :ok-text="es ? 'Enviar' : 'Send'"
             :confirm-loading="replyBusy" :width="620" @ok="sendReply">
      <a-form layout="vertical">
        <a-form-item :label="es ? 'Para' : 'To'"><a-input :value="openSub && openSub.reply_to" disabled /></a-form-item>
        <a-form-item :label="es ? 'Asunto' : 'Subject'" required><a-input v-model:value="replySubject" :maxlength="190" /></a-form-item>
        <a-form-item :label="es ? 'Mensaje' : 'Message'" required>
          <a-textarea v-model:value="replyMsg" :auto-size="{ minRows: 6, maxRows: 14 }" :maxlength="10000" />
        </a-form-item>
        <div style="font-size: 12px; color: #888">{{ es ? 'Su mensaje original va citado debajo. El envío queda marcado como atendido.' : 'Their original message is quoted below. The submission is marked attended.' }}</div>
      </a-form>
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
  LeftOutlined, RightOutlined, CheckOutlined, PrinterOutlined, FilePdfOutlined, MailOutlined, SendOutlined,
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
// The original import data a corrected row keeps in its notes ("... correction): {json}"), or null.
const showOriginal = ref(false);
function originalOf(s) {
  const n = s && s.notes;
  const i = n ? n.indexOf('{') : -1;
  if (i < 0) return null;
  try { return JSON.parse(n.slice(i)); } catch (e) { return null; }
}
const short = v => { const t = text(v); return t.length > 60 ? t.slice(0, 57) + '…' : t; };
const shortUrl = u => u.replace(/^https?:\/\//, '').slice(0, 60);
const modalWidth = computed(() => (window.innerWidth < 700 ? '100%' : Math.min(1000, window.innerWidth - 80)));
const descColumns = computed(() => (window.innerWidth < 900 ? 1 : 2));

// The first columns of the form (no files / hidden), then the date; plus (2026-11-03)
// the agency, the destination and the email fields wherever they are in the form.
const isExtraCol = f => f.type === 'email' || /agenc|destin/i.test(`${f.key} ${f.label}`);
const columns = computed(() => {
  const plain = fields.value.filter(f => !['file', 'hidden', 'textarea'].includes(f.type));
  const first = plain.slice(0, 5);
  const extra = plain.filter(f => isExtraCol(f) && !first.includes(f))
    .sort((a, b) => (a.type === 'email') - (b.type === 'email'));
  return [
    { title: '#', key: 'ref', width: 90, fixed: 'left' },
    { title: es.value ? 'Fecha' : 'Date', key: 'date', width: 150 },
    { title: es.value ? 'Atendido' : 'Attended', key: 'attended', width: 100 },
    ...[...first, ...extra].map(f => ({ title: (f.label || f.key).replace(/:$/, '').replace(/^Seleccione\s+/i, ''), key: 'f:' + f.key, ellipsis: true })),
  ];
});
// Long document types shortened in the list (2026-11-03).
const DOC_SHORT = { 'documento nacional de identidad': 'DNI', 'documento de identidad': 'DNI', 'carnet de extranjeria': 'CE', 'carnet de extranjería': 'CE', 'registro unico de contribuyente': 'RUC', 'registro único de contribuyente': 'RUC' };
const cell = v => { const t = short(v); return DOC_SHORT[t.trim().toLowerCase()] || t; };

// The window repeats the form's layout (2026-11-03): its order, section headings and
// full / half widths (`layout` from the submission endpoint).
const layout = ref([]);
const byKey = computed(() => Object.fromEntries(fields.value.map(f => [f.key, f])));
const viewItems = computed(() => (layout.value.length ? layout.value : fields.value.map(f => ({ key: f.key, width: 'full' })))
  .filter(x => x.heading || byKey.value[x.key]));
// Compact (2026-11-03, no scrolling): a 4-column grid -- long texts the whole row, a
// full-width text field half of it, everything else (selects, dates, numbers) one column.
function cellSpan(x) {
  const t = byKey.value[x.key].type;
  const span = t === 'textarea' || t === 'file' ? 4 : x.width !== 'half' && ['text', 'email', 'phone', 'url'].includes(t) ? 2 : 1;
  return descColumns.value === 1 ? Math.min(span, 2) : span;
}

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
    showOriginal.value = false;
    layout.value = res.layout || [];
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
// Reply to the sender (2026-11-03)
const replyOpen = ref(false);
const replyBusy = ref(false);
const replySubject = ref('');
const replyMsg = ref('');
function openReply() {
  replySubject.value = 'Re: ' + formName.value + ' - ' + openSub.value.ref;
  replyMsg.value = '';
  replyOpen.value = true;
}
async function sendReply() {
  if (!replySubject.value.trim() || !replyMsg.value.trim()) return message.warning(es.value ? 'Escribe el asunto y el mensaje.' : 'Write the subject and the message.');
  replyBusy.value = true;
  try {
    await http.post(`forms/submissions/${openSub.value.id}/reply`, { subject: replySubject.value, message: replyMsg.value });
    message.success(es.value ? 'Respuesta enviada a ' + openSub.value.reply_to : 'Reply sent to ' + openSub.value.reply_to);
    replyOpen.value = false;
    const res = await http.get(`forms/submissions/${openSub.value.id}`);
    keepRow(res.submission);
  } catch (e) {
    message.error(e?.data?.message || (es.value ? 'No se pudo enviar.' : 'Could not send it.'));
  } finally {
    replyBusy.value = false;
  }
}

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
.fs-meta { flex: 1; text-align: center; font-size: 13px; }
.fs-nav { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
.fs-value { white-space: pre-wrap; }
.fs-grid { display: grid; grid-template-columns: repeat(4, 1fr); border: 1px solid #f0f0f0; border-radius: 6px; overflow: hidden; margin-bottom: 6px; line-height: 1.35; }
.fs-grid-1 { grid-template-columns: 1fr 1fr; }
.fs-section { grid-column: 1 / -1; padding: 3px 10px; background: #e6f4ff; font-weight: 600; font-size: 13px; }
.fs-cell { padding: 3px 10px; border-bottom: 1px solid #f0f0f0; border-right: 1px solid #f0f0f0; min-width: 0; overflow-wrap: anywhere; }
.fs-full { grid-column: 1 / -1; }
.fs-label { font-size: 11px; color: #888; }
.fs-original { margin-top: 6px; font-size: 12px; }
.fs-original-box { margin-top: 4px; padding: 6px 10px; border: 1px dashed rgba(128, 128, 128, 0.35); border-radius: 6px; max-height: 220px; overflow-y: auto; }
.fs-original-row { display: flex; gap: 8px; padding: 1px 0; overflow-wrap: anywhere; }
.fs-original-row code { flex: none; min-width: 90px; opacity: 0.7; }
:deep(.fs-unread) td { font-weight: 600; }
:deep(.ant-table-row) { cursor: pointer; }
</style>
