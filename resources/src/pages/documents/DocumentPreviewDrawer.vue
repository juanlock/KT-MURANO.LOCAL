<template>
  <a-drawer
    :open="open"
    :width="720"
    placement="right"
    :body-style="{ padding: 0 }"
    @close="$emit('close')"
  >
    <template #title>
      <div v-if="doc" class="dv-title">
        <span class="dv-icon" :style="{ color: kind.color, background: tint(kind.color) }">
          <component :is="kind.icon" />
        </span>
        <div class="dv-title-text">
          <div class="dv-name" :title="doc.title">{{ doc.title }}</div>
          <div class="dv-sub">{{ doc.file_name }} · {{ doc.size_label }}</div>
        </div>
      </div>
      <span v-else>Document</span>
    </template>

    <template #extra>
      <a-space v-if="doc">
        <a-tooltip :title="doc.is_starred ? 'Remove star' : 'Star this document'">
          <a-button type="text" :disabled="!canEdit" @click="toggleStar">
            <template #icon>
              <StarFilled v-if="doc.is_starred" style="color: #faad14" />
              <StarOutlined v-else />
            </template>
          </a-button>
        </a-tooltip>
        <a-button :loading="downloading" @click="download">
          <template #icon><DownloadOutlined /></template>
          Download
        </a-button>
        <a-button v-if="canEdit" type="primary" @click="$emit('edit', doc)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
      </a-space>
    </template>

    <a-spin :spinning="loading">
      <div v-if="doc" class="dv-body">
        <!-- Preview surface -->
        <div class="dv-preview">
          <a-spin v-if="previewLoading" />
          <img v-else-if="doc.url && doc.kind === 'image'" :src="doc.url" :alt="doc.title" />
          <iframe v-else-if="doc.url && doc.kind === 'pdf'" :src="doc.url" :title="doc.title"></iframe>
          <pre v-else-if="textBody !== null" class="dv-text">{{ textBody }}</pre>
          <div v-else class="dv-noprev">
            <span class="dv-noprev-icon" :style="{ color: kind.color, background: tint(kind.color) }">
              <component :is="kind.icon" />
            </span>
            <div class="dv-noprev-title">No inline preview for {{ (doc.extension || 'this file').toUpperCase() }}</div>
            <a-button type="primary" ghost :loading="downloading" @click="download">
              <template #icon><DownloadOutlined /></template>
              Download to open
            </a-button>
          </div>
        </div>

        <a-alert
          v-if="expiryAlert" :type="expiryAlert.type" show-icon banner
          :message="expiryAlert.text" class="dv-alert"
        />

        <a-tabs v-model:activeKey="tab" class="dv-tabs">
          <a-tab-pane key="details" tab="Details">
            <a-descriptions :column="{ xs: 1, sm: 2 }" size="small" bordered>
              <a-descriptions-item label="Folder">
                <a-tag v-if="doc.folder_name">{{ doc.folder_name }}</a-tag>
                <span v-else class="muted">Uncategorised</span>
              </a-descriptions-item>
              <a-descriptions-item label="Type">{{ kind.label }} · {{ (doc.extension || '—').toUpperCase() }}</a-descriptions-item>
              <a-descriptions-item label="Size">{{ doc.size_label }}</a-descriptions-item>
              <a-descriptions-item label="Version">v{{ doc.version }}</a-descriptions-item>
              <a-descriptions-item label="Reference">
                <span v-if="doc.reference">{{ doc.reference }}</span>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
              <a-descriptions-item label="Expiry">
                <span v-if="doc.expiry_date">{{ date(doc.expiry_date) }}</span>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
              <a-descriptions-item label="Uploaded by">
                <span v-if="doc.uploaded_by_name">{{ doc.uploaded_by_name }}</span>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
              <a-descriptions-item label="Added">{{ dateTime(doc.created_at) }}</a-descriptions-item>
              <a-descriptions-item label="Tags" :span="2">
                <template v-if="doc.tags && doc.tags.length">
                  <a-tag v-for="tg in doc.tags" :key="tg" color="purple">{{ tg }}</a-tag>
                </template>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Description')" :span="2">
                <span v-if="doc.description" class="dv-desc">{{ doc.description }}</span>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
            </a-descriptions>

            <div v-if="canDelete" class="dv-danger">
              <div>
                <div class="dv-danger-title">Delete this document</div>
                <div class="dv-danger-sub">It leaves the archive; the stored file is kept.</div>
              </div>
              <a-button danger @click="remove">
                <template #icon><DeleteOutlined /></template>
                {{ $t('Delete') }}
              </a-button>
            </div>
          </a-tab-pane>

          <a-tab-pane key="versions">
            <template #tab>
              Versions
              <a-badge :count="versions.length" :number-style="{ backgroundColor: '#6d28d9' }" />
            </template>

            <a-upload
              v-if="canEdit"
              :show-upload-list="false"
              :before-upload="uploadVersion"
              class="dv-replace"
            >
              <a-button :loading="replacing" block>
                <template #icon><UploadOutlined /></template>
                Upload a new version
              </a-button>
            </a-upload>

            <a-list :data-source="versions" item-layout="horizontal" size="small">
              <template #renderItem="{ item }">
                <a-list-item>
                  <a-list-item-meta>
                    <template #title>
                      <span class="dv-ver">
                        v{{ item.version }}
                        <a-tag v-if="item.version === doc.version" color="green">Current</a-tag>
                      </span>
                    </template>
                    <template #description>
                      <div class="dv-ver-meta">
                        <span>{{ item.file_name }} · {{ item.size_label }}</span>
                        <span>{{ dateTime(item.created_at) }}{{ item.uploaded_by_name ? ` · ${item.uploaded_by_name}` : '' }}</span>
                        <span v-if="item.note" class="dv-ver-note">{{ item.note }}</span>
                      </div>
                    </template>
                  </a-list-item-meta>
                  <template #actions>
                    <a-tooltip title="Download this version">
                      <a-button size="small" type="text" @click="downloadVersion(item)">
                        <template #icon><DownloadOutlined /></template>
                      </a-button>
                    </a-tooltip>
                    <a-tooltip v-if="canEdit && item.version !== doc.version" title="Make this the current file">
                      <a-button size="small" type="text" @click="restoreVersion(item)">
                        <template #icon><UndoOutlined /></template>
                      </a-button>
                    </a-tooltip>
                  </template>
                </a-list-item>
              </template>
              <template #empty>
                <a-empty description="No version history yet" />
              </template>
            </a-list>
          </a-tab-pane>
        </a-tabs>
      </div>
    </a-spin>
  </a-drawer>
</template>

<script setup>
/**
 * Document detail: inline preview, metadata and version history.
 *
 * Files live under public/images/documents, so `doc.url` is a plain static URL
 * the browser can render itself — images and PDFs need no fetching at all.
 * Only text files are read ahead of time, so they can be shown as text rather
 * than handed to the browser's download prompt.
 */
import { ref, computed, watch, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import {
  DownloadOutlined, EditOutlined, DeleteOutlined, UploadOutlined, UndoOutlined,
  StarFilled, StarOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { useFormat } from '../../composables/useFormat';
import { kindOf } from './documentKinds';
import { t } from '../../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  documentId: { type: [Number, String], default: null },
  canEdit: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'edit', 'changed', 'deleted']);

const { date, dateTime } = useFormat();

const doc = ref(null);
const versions = ref([]);
const loading = ref(false);
const tab = ref('details');
const downloading = ref(false);
const replacing = ref(false);

const previewLoading = ref(false);
const textBody = ref(null);

const kind = computed(() => kindOf(doc.value));

const expiryAlert = computed(() => {
  const days = doc.value?.days_to_expiry;
  if (days === null || days === undefined) return null;
  if (days < 0) return { type: 'error', text: `This document expired ${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} ago.` };
  if (days <= 30) return { type: 'warning', text: `Expires in ${days} day${days === 1 ? '' : 's'} (${date(doc.value.expiry_date)}).` };
  return null;
});

function tint(hex) {
  return `${hex}1f`;
}

async function load() {
  if (!props.documentId) return;
  loading.value = true;
  textBody.value = null;
  try {
    const data = await http.get(`documents/${props.documentId}`);
    doc.value = data?.document || null;
    versions.value = data?.document?.versions || [];
    loadTextPreview();
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this document'));
  } finally {
    loading.value = false;
  }
}

/** Images and PDFs render from the URL; only text needs reading first. */
async function loadTextPreview() {
  if (!doc.value?.url || doc.value.kind !== 'text') return;
  previewLoading.value = true;
  try {
    const res = await fetch(doc.value.url, { credentials: 'same-origin' });
    if (res.ok) textBody.value = await res.text();
  } catch (e) {
    // Falls through to the "no inline preview" panel, which still downloads.
  } finally {
    previewLoading.value = false;
  }
}

watch(() => [props.open, props.documentId], ([isOpen]) => {
  if (isOpen && props.documentId) {
    tab.value = 'details';
    load();
  }
}, { immediate: true });

async function download() {
  downloading.value = true;
  try {
    await http.download(`documents/${doc.value.id}/download`, doc.value.file_name);
  } catch (e) {
    message.error('Could not download this file.');
  } finally {
    downloading.value = false;
  }
}

function downloadVersion(version) {
  http.download(`documents/${doc.value.id}/versions/${version.id}/download`, version.file_name)
    .catch(() => message.error('Could not download this version.'));
}

async function toggleStar() {
  try {
    const data = await http.post(`documents/${doc.value.id}/star`);
    doc.value.is_starred = !!data.is_starred;
    emit('changed');
  } catch (e) {
    message.error(t('InvalidData', 'Could not update this document'));
  }
}

/** beforeUpload returns false so antd hands us the file instead of posting it. */
function uploadVersion(file) {
  replacing.value = true;
  const fd = new FormData();
  fd.append('file', file);
  fd.append('note', `Replaced ${doc.value.file_name}`);

  uploadForm(`documents/${doc.value.id}/versions`, fd)
    .then(res => {
      if (res.status >= 200 && res.status < 300) {
        message.success('New version uploaded.');
        load();
        emit('changed');
      } else {
        message.error(res?.data?.message || 'Could not upload the new version.');
      }
    })
    .catch(() => message.error('Could not upload the new version.'))
    .finally(() => { replacing.value = false; });

  return false;
}

function restoreVersion(version) {
  Modal.confirm({
    title: `Restore v${version.version}?`,
    icon: createVNode(ExclamationCircleOutlined),
    content: 'The current file stays in history — restoring adds a new version pointing at this file.',
    okText: 'Restore',
    cancelText: t('Cancel', 'Cancel'),
    async onOk() {
      try {
        await http.post(`documents/${doc.value.id}/versions/${version.id}/restore`);
        message.success('Version restored.');
        await load();
        emit('changed');
      } catch (e) {
        message.error(t('InvalidData', 'Could not restore this version'));
      }
    },
  });
}

function remove() {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Delete_Text', "You won't be able to revert this!")} — ${doc.value.title}`,
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`documents/${doc.value.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        emit('deleted');
      } catch (e) {
        message.error(t('InvalidData', 'Could not delete this document'));
      }
    },
  });
}

/** Let the parent refresh this panel after an edit without remounting it. */
defineExpose({ reload: load });
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.dv-title {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.dv-icon {
  width: 38px;
  height: 38px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 11px;
  font-size: 18px;
}
.dv-title-text {
  min-width: 0;
}
.dv-name {
  font-weight: 600;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dv-sub {
  font-size: 12px;
  font-weight: 400;
  opacity: 0.55;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dv-body {
  padding: 0 0 24px;
}

/* Preview surface: a checkerboard-free neutral stage that works in both themes. */
.dv-preview {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 320px;
  background: rgba(128, 128, 128, 0.08);
  border-bottom: 1px solid rgba(128, 128, 128, 0.18);
  overflow: hidden;
}
.dv-preview img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
.dv-preview iframe {
  width: 100%;
  height: 100%;
  border: 0;
}
.dv-text {
  width: 100%;
  height: 100%;
  margin: 0;
  padding: 16px;
  overflow: auto;
  font-size: 12.5px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
}
.dv-noprev {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  text-align: center;
  padding: 0 24px;
}
.dv-noprev-icon {
  width: 64px;
  height: 64px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 18px;
  font-size: 28px;
}
.dv-noprev-title {
  font-weight: 500;
  opacity: 0.75;
}
.dv-alert {
  margin: 0;
}
.dv-tabs {
  padding: 0 24px;
}
.dv-desc {
  white-space: pre-wrap;
}
.dv-replace {
  display: block;
  margin-bottom: 12px;
}
.dv-replace :deep(.ant-upload) {
  display: block;
  width: 100%;
}
.dv-ver {
  font-weight: 600;
}
.dv-ver-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 12px;
}
.dv-ver-note {
  font-style: italic;
  opacity: 0.7;
}
.dv-danger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-top: 20px;
  padding: 14px 16px;
  border: 1px solid rgba(255, 77, 79, 0.35);
  border-radius: 12px;
  background: rgba(255, 77, 79, 0.05);
}
.dv-danger-title {
  font-weight: 500;
}
.dv-danger-sub {
  font-size: 12px;
  opacity: 0.6;
}
</style>
