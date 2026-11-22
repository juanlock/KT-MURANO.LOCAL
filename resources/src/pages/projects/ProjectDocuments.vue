<template>
  <div ref="root">
    <!-- Three ways to add (user, 2026-11-22): drag and drop, paste (Ctrl+V: a screenshot, a copied picture or a link), a link with its preview. -->
    <a-spin v-if="!readonly" :spinning="uploading" :tip="tr('Uploading…', 'Subiendo…')">
      <div class="adders">
        <a-upload-dragger :before-upload="queue" :show-upload-list="false" multiple class="add-drop">
          <CloudUploadOutlined class="add-ic" />
          <div class="add-t">{{ tr('Drag files here', 'Arrastra archivos aquí') }}</div>
          <div class="add-s">{{ tr('or click to choose them (videos up to 200 MB)', 'o haz clic para elegirlos (videos hasta 200 MB)') }}</div>
        </a-upload-dragger>
        <div class="add-paste" :class="{ on: pasteFocus }" tabindex="0" @paste.prevent.stop="onPaste" @focus="pasteFocus = true" @blur="pasteFocus = false">
          <SnippetsOutlined class="add-ic" />
          <div class="add-t">{{ pasteFocus ? tr('Now paste (Ctrl+V)', 'Ahora pega (Ctrl+V)') : tr('Click here and paste', 'Haz clic aquí y pega') }}</div>
          <div class="add-s">{{ tr('a screenshot, a copied picture or a link', 'una captura, una imagen copiada o un enlace') }}</div>
        </div>
        <div class="add-link">
          <div class="add-t"><LinkOutlined /> {{ tr('Link', 'Enlace') }}</div>
          <a-input
            v-model:value="linkUrl" size="small" allow-clear :placeholder="tr('Paste a link: YouTube, Drive, Zoom…', 'Pega un enlace: YouTube, Drive, Zoom…')"
            @update:value="onLinkInput" @press-enter="saveLink"
          />
          <div v-if="linkLoading" class="add-s">{{ tr('Reading the link…', 'Leyendo el enlace…') }}</div>
          <div v-else-if="linkPv" class="lp">
            <img v-if="linkPv.thumbnail" :src="linkPv.thumbnail" class="lp-img" referrerpolicy="no-referrer" alt="">
            <div class="lp-body">
              <a-tag :color="platformColor(linkPv.platform)" class="lp-tag">{{ linkPv.site }}</a-tag>
              <div class="lp-title">{{ linkPv.title || linkUrl }}</div>
              <div v-if="linkPv.description || linkPv.author" class="lp-desc">{{ linkPv.description || linkPv.author }}</div>
            </div>
          </div>
          <a-button type="primary" size="small" block :disabled="!validUrl" @click="saveLink">{{ tr('Save link', 'Guardar enlace') }}</a-button>
        </div>
      </div>
    </a-spin>
    <div class="bar">
      <a-select
        v-if="!taskId" v-model:value="uploadTask" allow-clear show-search option-filter-prop="label" class="sel" size="small"
        :options="taskOptions" :placeholder="tr('For the task… (optional)', 'Para la tarea… (opcional)')"
      />
      <a-input v-model:value="note" class="note" size="small" allow-clear :placeholder="tr('Note for what you add (optional)', 'Nota para lo que agregues (opcional)')" />
      <span class="grow" />
      <a-select
        v-if="!taskId" v-model:value="filterTask" allow-clear show-search option-filter-prop="label" class="sel" size="small"
        :options="taskOptions" :placeholder="tr('All tasks', 'Todas las tareas')"
      />
      <a-radio-group v-model:value="view" size="small" button-style="solid">
        <a-radio-button value="grid"><AppstoreOutlined /></a-radio-button>
        <a-radio-button value="list"><UnorderedListOutlined /></a-radio-button>
      </a-radio-group>
    </div>

    <a-spin :spinning="loading">
      <a-empty v-if="!shown.length" :image="simpleEmpty" :image-style="{ height: '36px' }" :description="tr('No documents yet.', 'Sin documentos todavía.')" />
      <div v-else-if="view === 'grid'" class="grid">
        <div v-for="d in shown" :key="d.id" class="card">
          <div class="thumb" @click="preview(d)">
            <img v-if="d.is_image && thumbs[d.id]" :src="thumbs[d.id]" :alt="d.name">
            <template v-else-if="d.url">
              <img v-if="thumbs[d.id] || (d.meta && d.meta.thumbnail)" :src="thumbs[d.id] || d.meta.thumbnail" referrerpolicy="no-referrer" alt="">
              <LinkOutlined v-else class="big link" />
              <PlayCircleFilled v-if="d.meta && d.meta.kind === 'video'" class="play" />
              <a-tag :color="platformColor(d.meta && d.meta.platform)" class="plat">{{ (d.meta && d.meta.site) || tr('Link', 'Enlace') }}</a-tag>
            </template>
            <VideoCameraOutlined v-else-if="d.is_video" class="big video" />
            <FilePdfOutlined v-else-if="isPdf(d)" class="big pdf" />
            <FileImageOutlined v-else-if="d.is_image" class="big" />
            <FileOutlined v-else class="big" />
          </div>
          <div class="c-name" :title="d.name">{{ d.name }}</div>
          <div class="c-sub">
            <span v-if="d.task_title && !taskId" class="c-task" :title="d.task_title">{{ d.task_title }}</span>
            <span v-if="d.url && d.meta && (d.meta.description || d.meta.author)" class="c-note" :title="d.meta.description || d.meta.author">{{ d.meta.description || d.meta.author }}</span>
            <span v-if="d.note" class="c-note" :title="d.note">{{ d.note }}</span>
            <span>{{ d.url ? tr('Link', 'Enlace') : size(d.size) }} · {{ dateTime(d.created_at) }}</span>
          </div>
          <div class="c-acts">
            <a-button v-if="!d.url" type="text" size="small" @click="downloadDoc(d)"><template #icon><DownloadOutlined /></template></a-button>
            <a-button v-if="canDelete && !readonly" type="text" size="small" danger @click="remove(d)"><template #icon><DeleteOutlined /></template></a-button>
          </div>
        </div>
      </div>
      <a-table v-else :data-source="shown" :columns="columns" row-key="id" size="small" :pagination="false">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><a @click="preview(record)">{{ record.name }}</a><div v-if="record.note" class="c-sub">{{ record.note }}</div></template>
          <template v-else-if="column.key === 'size'">{{ record.url ? tr('Link', 'Enlace') : size(record.size) }}</template>
          <template v-else-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
          <template v-else-if="column.key === 'acts'">
            <a-button v-if="!record.url" type="text" size="small" @click="downloadDoc(record)"><template #icon><DownloadOutlined /></template></a-button>
            <a-button v-if="canDelete && !readonly" type="text" size="small" danger @click="remove(record)"><template #icon><DeleteOutlined /></template></a-button>
          </template>
        </template>
      </a-table>
    </a-spin>

    <a-modal :open="!!pv" :title="pv?.name" :footer="null" width="80vw" @cancel="closePreview">
      <img v-if="pv && pv.is_image" :src="pvUrl" class="pv-img" :alt="pv.name">
      <video v-else-if="pv && pv.is_video && pvUrl" :src="pvUrl" class="pv-img" controls />
      <iframe v-else-if="pv && pvUrl" :src="pvUrl" class="pv-frame" />
    </a-modal>

  </div>
</template>

<script setup>
/**
 * Documents tab (user, 2026-11-21): files and images attached to the project's tasks while they are worked on. Also
 * used inside the task form (taskId: that task's files only). Private files: read through the API as blobs.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Modal, message, Empty } from 'ant-design-vue';
import {
  UploadOutlined, DownloadOutlined, DeleteOutlined, FileOutlined, FilePdfOutlined, FileImageOutlined,
  AppstoreOutlined, UnorderedListOutlined, LinkOutlined, VideoCameraOutlined, CloudUploadOutlined, SnippetsOutlined, PlayCircleFilled,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';

const props = defineProps({
  projectId: { type: Number, required: true },
  taskId: { type: Number, default: null },
  tasks: { type: Array, default: () => [] },
  // Only to look (a member not assigned to the task).
  readonly: { type: Boolean, default: false },
});
const tr = useTr();
const simpleEmpty = Empty.PRESENTED_IMAGE_SIMPLE;
const { dateTime } = useFormat();
const auth = useAuthStore();
const canDelete = computed(() => auth.can('projects'));

const docs = ref([]);
const loading = ref(false);
const uploading = ref(false);
const uploadTask = ref(undefined);
const filterTask = ref(undefined);
const note = ref('');
const view = ref('grid');
const thumbs = ref({});

const taskOptions = computed(() => sortByLabel(props.tasks.map(t => ({ value: t.id, label: t.title }))));
const shown = computed(() => (filterTask.value ? docs.value.filter(d => d.task_id === filterTask.value) : docs.value));
const columns = computed(() => [
  { title: tr('File', 'Archivo'), key: 'name', dataIndex: 'name' },
  ...(props.taskId ? [] : [{ title: tr('Task', 'Tarea'), key: 'task', dataIndex: 'task_title', width: 240 }]),
  { title: tr('Size', 'Tamaño'), key: 'size', width: 90 },
  { title: tr('Uploaded by', 'Subido por'), key: 'by', dataIndex: 'uploaded_by', width: 140 },
  { title: tr('Date', 'Fecha'), key: 'created_at', width: 150 },
  { title: '', key: 'acts', width: 90 },
]);

const isPdf = d => d.mime === 'application/pdf';
const size = b => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

async function load() {
  loading.value = !docs.value.length;
  try {
    const r = await http.get(`projects/${props.projectId}/documents`, props.taskId ? { task_id: props.taskId } : {});
    docs.value = r.documents || [];
    docs.value.filter(d => d.is_image && !thumbs.value[d.id]).slice(0, 60).forEach(async d => {
      try { const u = URL.createObjectURL(await http.blob(`project_files/${d.id}`, true)); thumbs.value = { ...thumbs.value, [d.id]: u }; } catch (e) { /* icon */ }
    });
    // Links: their stored picture copy (the platform's address may have expired).
    docs.value.filter(d => d.url && d.has_thumb && !thumbs.value[d.id]).slice(0, 60).forEach(async d => {
      try { const u = URL.createObjectURL(await http.blob(`project_files/${d.id}?thumb=1`, true)); thumbs.value = { ...thumbs.value, [d.id]: u }; } catch (e) { /* remote picture */ }
    });
  } finally { loading.value = false; }
}

// a-upload calls before-upload once per file: collect them and send one request.
let pending = [];
let timer = null;
function queue(file) {
  pending.push(file);
  clearTimeout(timer);
  timer = setTimeout(send, 50);
  return false;
}
async function send() {
  const files = pending;
  pending = [];
  if (!files.length) return;
  const fd = new FormData();
  files.forEach(f => fd.append('files[]', f));
  const task = props.taskId || uploadTask.value;
  if (task) fd.append('task_id', task);
  if (note.value) fd.append('note', note.value);
  uploading.value = true;
  try {
    await http.postForm(`projects/${props.projectId}/documents`, fd);
    message.success(tr(`${files.length} file(s) uploaded.`, `${files.length} archivo(s) subido(s).`));
    note.value = '';
    await load();
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat() : [];
    message.error(errs[0] || e?.data?.message || tr('Could not upload.', 'No se pudo subir.'));
  } finally { uploading.value = false; }
}

// Paste (Ctrl+V): pictures / files from the clipboard go up at once; a link fills the link box (2026-11-22).
const pasteFocus = ref(false);
function stamp() {
  const d = new Date();
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
}
function onPaste(e) {
  const cd = e.clipboardData;
  if (!cd) return;
  const files = [...(cd.items || [])].filter(i => i.kind === 'file').map(i => i.getAsFile()).filter(Boolean)
    .map(f => (/^image\.\w+$/i.test(f.name) ? new File([f], `captura-${stamp()}.${(f.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`, { type: f.type }) : f));
  if (files.length) {
    pending.push(...files);
    send();
    return;
  }
  const text = (cd.getData('text') || '').trim();
  if (/^https?:\/\/\S+$/i.test(text)) {
    linkUrl.value = text;
    onLinkInput(text);
  } else if (text) {
    message.info(tr('Paste a picture, a file or a link.', 'Pega una imagen, un archivo o un enlace.'));
  }
}
// Pasting anywhere on the page (not in a text field) works too.
// Only once per paste (2026-11-22: the box and the page both took it, every capture went up twice), only by the
// documents block that is on screen, never inside a text field.
const root = ref(null);
function onDocPaste(e) {
  if (props.readonly || e.defaultPrevented || e.__muranoPaste) return;
  const t = e.target;
  if (t && (t.closest?.('input, textarea, [contenteditable="true"]'))) return;
  if (!root.value || !root.value.offsetParent) return;
  e.__muranoPaste = true;
  e.preventDefault();
  onPaste(e);
}

// A link with its preview: platform, title, description, picture.
const linkUrl = ref('');
const linkPv = ref(null);
const linkLoading = ref(false);
const validUrl = computed(() => /^https?:\/\/\S+\.\S+/i.test((linkUrl.value || '').trim()));
let pvTimer = null;
function onLinkInput(v) {
  clearTimeout(pvTimer);
  linkPv.value = null;
  const url = (v || '').trim();
  if (!/^https?:\/\/\S+\.\S+/i.test(url)) return;
  pvTimer = setTimeout(async () => {
    linkLoading.value = true;
    try {
      const r = await http.get(`projects/${props.projectId}/link-preview`, { url });
      if ((linkUrl.value || '').trim() === url) linkPv.value = r.preview;
    } catch (e) { linkPv.value = null; } finally { linkLoading.value = false; }
  }, 450);
}
const PLATFORM_COLORS = { YouTube: 'red', Vimeo: 'cyan', TikTok: 'default', Facebook: 'blue', Instagram: 'magenta', Zoom: 'geekblue', 'Google Drive': 'green', 'Google Docs': 'blue', OneDrive: 'blue', Dropbox: 'blue', LinkedIn: 'blue', WhatsApp: 'green' };
const platformColor = p => PLATFORM_COLORS[p] || 'purple';
async function saveLink() {
  if (!validUrl.value) { message.warning(tr('Write the full address (https://...).', 'Escribe la dirección completa (https://...).')); return; }
  uploading.value = true;
  try {
    await http.post(`projects/${props.projectId}/documents`, { url: linkUrl.value.trim(), task_id: props.taskId || uploadTask.value || null, note: note.value || null });
    linkUrl.value = '';
    linkPv.value = null;
    note.value = '';
    await load();
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat() : [];
    message.error(errs[0] || e?.data?.message || tr('Could not save.', 'No se pudo guardar.'));
  } finally { uploading.value = false; }
}
async function downloadDoc(d) { await http.download(`project_files/${d.id}?download=1`, d.name); }

const pv = ref(null);
const pvUrl = ref('');
async function preview(d) {
  if (d.url) { window.open(d.url, '_blank', 'noopener'); return; }
  if (!d.is_image && !d.is_video && !isPdf(d)) { downloadDoc(d); return; }
  pv.value = d;
  pvUrl.value = thumbs.value[d.id] || URL.createObjectURL(await http.blob(`project_files/${d.id}`, true));
}
function closePreview() { pv.value = null; }

function remove(d) {
  Modal.confirm({
    title: tr('Delete this document?', '¿Eliminar este documento?'), content: d.name,
    okType: 'danger', okText: tr('Delete', 'Eliminar'), cancelText: tr('Cancel', 'Cancelar'),
    async onOk() { await http.delete(`project_files/${d.id}`); await load(); },
  });
}

watch(() => props.taskId, load);
onMounted(() => { load(); document.addEventListener('paste', onDocPaste); });
onBeforeUnmount(() => {
  document.removeEventListener('paste', onDocPaste);
  Object.values(thumbs.value).forEach(u => URL.revokeObjectURL(u));
});
</script>

<style scoped>
.bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0 10px; }
.adders { display: grid; grid-template-columns: 1fr 1fr 1.4fr; gap: 8px; }
.add-drop :deep(.ant-upload-drag) { height: 100%; }
.add-drop :deep(.ant-upload-btn) { padding: 8px !important; }
.add-paste, .add-link { border: 1px dashed rgba(128, 128, 128, 0.45); border-radius: 8px; padding: 8px; text-align: center; background: rgba(128, 128, 128, 0.03); }
.add-paste { cursor: pointer; outline: none; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.add-paste:hover, .add-paste.on { border-color: #6366f1; background: rgba(99, 102, 241, 0.06); }
.add-link { text-align: left; display: flex; flex-direction: column; gap: 6px; }
.add-ic { font-size: 24px; color: #6366f1; }
.add-t { font-size: 12.5px; font-weight: 600; }
.add-s { font-size: 11px; opacity: 0.6; }
.lp { display: flex; gap: 8px; align-items: flex-start; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 6px; padding: 5px; }
.lp-img { width: 84px; height: 48px; object-fit: cover; border-radius: 4px; flex: none; }
.lp-body { min-width: 0; }
.lp-tag { margin: 0 0 2px; font-size: 10.5px; }
.lp-title { font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lp-desc { font-size: 11px; opacity: 0.65; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.thumb { position: relative; }
.play { position: absolute; font-size: 34px; color: #fff; filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.6)); }
.plat { position: absolute; top: 6px; left: 6px; margin: 0; font-size: 10.5px; }
@media (max-width: 768px) { .adders { grid-template-columns: 1fr; } }
.grow { flex: 1; }
.sel { width: 240px; }
.note { width: 200px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
.card { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; }
.thumb { height: 120px; display: flex; align-items: center; justify-content: center; background: rgba(128, 128, 128, 0.07); cursor: pointer; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }
.big { font-size: 42px; opacity: 0.5; }
.big.pdf { color: #dc2626; opacity: 0.8; }
.big.link { color: #2563eb; opacity: 0.8; }
.big.video { color: #9333ea; opacity: 0.8; }
.c-note { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-style: italic; }
.c-name { font-size: 12.5px; font-weight: 500; padding: 6px 8px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.c-sub { font-size: 11px; opacity: 0.6; padding: 0 8px; display: flex; flex-direction: column; }
.c-task { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #6366f1; opacity: 1; }
.c-acts { display: flex; justify-content: flex-end; padding: 0 4px 4px; }
.pv-img { max-width: 100%; max-height: 75vh; display: block; margin: 0 auto; }
.pv-frame { width: 100%; height: 75vh; border: 0; }
@media (max-width: 768px) { .sel, .note { width: 100%; } }
</style>