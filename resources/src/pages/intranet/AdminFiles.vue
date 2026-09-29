<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_Files')" :breadcrumb="[$t('Intranet_Management'), $t('Intranet_Files')]">
      <template #extra>
        <a-input-search v-model:value="search" :placeholder="$t('Intranet_Search')" allow-clear style="width: 200px" @search="load" />
        <a-select v-model:value="folderFilter" :options="filterFolderOptions" style="width: 180px" @change="load" />
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 12px">
      <a-row :gutter="12" align="middle">
        <a-col :xs="24" :md="8">
          <a-auto-complete
            v-model:value="uploadFolder" :options="folderOptions" :placeholder="$t('Intranet_Folder')" allow-clear style="width: 100%"
            :filter-option="(input, opt) => String(opt.value).toLowerCase().includes(input.toLowerCase())"
          />
        </a-col>
        <a-col :xs="24" :md="16">
          <a-upload :show-upload-list="false" multiple :accept="accept" :before-upload="queueUpload">
            <a-button type="primary" :loading="uploading"><template #icon><UploadOutlined /></template>{{ $t('Intranet_Upload_Files') }}</a-button>
          </a-upload>
          <span class="muted hint">{{ $t('Intranet_Files_Hint') }}</span>
        </a-col>
      </a-row>
      <a-progress v-if="uploading" :percent="progress" size="small" style="margin-top: 8px" />
    </a-card>

    <a-card size="small">
      <a-table :data-source="rows" :columns="columns" :loading="loading" row-key="id" size="middle" :pagination="{ pageSize: 25, hideOnSinglePage: true }" :scroll="{ x: 900 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'title'">
            <div class="file-cell">
              <span class="ext" :style="{ background: fileIconColor(record.extension) }">{{ (record.extension || '?').toUpperCase() }}</span>
              <div class="file-info">
                <div class="file-title">{{ record.title }}</div>
                <div class="muted">{{ record.original_name }}</div>
              </div>
            </div>
          </template>
          <template v-else-if="column.key === 'folder'"><a-tag v-if="record.folder"><FolderOutlined /> {{ record.folder }}</a-tag></template>
          <template v-else-if="column.key === 'size'">{{ formatBytes(record.size) }}</template>
          <template v-else-if="column.key === 'downloads'">{{ record.downloads }}</template>
          <template v-else-if="column.key === 'created_at'">{{ fmtDate(record.created_at) }}<div class="muted">{{ personName(record.uploader) }}</div></template>
          <template v-else-if="column.key === 'action'">
            <a-space>
              <a-button size="small" @click="download(record)"><template #icon><DownloadOutlined /></template></a-button>
              <a-button size="small" @click="openEdit(record)"><template #icon><EditOutlined /></template></a-button>
              <a-popconfirm :title="$t('Delete_Title')" :ok-text="$t('Delete_confirmButtonText')" :cancel-text="$t('Delete_cancelButtonText')" @confirm="remove(record)">
                <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal :open="!!editing" :title="editing?.original_name" :confirm-loading="saving" :ok-text="$t('Intranet_Save')" :cancel-text="$t('Intranet_Cancel')" @ok="saveEdit" @cancel="editing = null">
      <a-form v-if="editing" layout="vertical">
        <a-form-item :label="$t('Intranet_Title')" required><a-input v-model:value="editing.title" :maxlength="190" /></a-form-item>
        <a-form-item :label="$t('Intranet_Folder')">
          <a-auto-complete v-model:value="editing.folder" :options="folderOptions" allow-clear style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Intranet_Description')"><a-textarea v-model:value="editing.description" :maxlength="2000" :auto-size="{ minRows: 3, maxRows: 6 }" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Intranet Management -> Files (intranet_manage): the document library.
 * Files are private (storage/app/intranet/company_{id}/files); downloads go
 * through GET intranet/files/{id}/download.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { UploadOutlined, DownloadOutlined, EditOutlined, DeleteOutlined, FolderOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { sortByLabel, withAllOption } from '../../lib/filterOptions';
import { formatBytes, fmtDate, fileIconColor, downloadFile, personName, apiError } from './intranetShared';

const { t } = useI18n();
const loading = ref(true);
const rows = ref([]);
const folders = ref([]);
const extensions = ref(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip']);
const maxKb = ref(20480);
const search = ref('');
const folderFilter = ref('');
const uploadFolder = ref('');
const uploading = ref(false);
const progress = ref(0);

const accept = computed(() => extensions.value.map(e => `.${e}`).join(','));
const folderOptions = computed(() => sortByLabel(folders.value.map(f => ({ value: f, label: f }))));
const filterFolderOptions = computed(() => withAllOption(folderOptions.value));
const columns = computed(() => [
  { title: t('Intranet_Title'), key: 'title' },
  { title: t('Intranet_Folder'), key: 'folder', width: 170 },
  { title: t('Intranet_File_Size'), key: 'size', width: 100 },
  { title: t('Intranet_Downloads'), key: 'downloads', width: 110, align: 'center' },
  { title: t('Intranet_Date'), key: 'created_at', width: 150 },
  { title: t('Intranet_Actions'), key: 'action', width: 140, align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    const r = await http.get('intranet-admin/files', { search: search.value || undefined, folder: folderFilter.value || undefined });
    rows.value = r.data || [];
    folders.value = r.folders || [];
    if (r.extensions) extensions.value = r.extensions;
    if (r.max_kb) maxKb.value = r.max_kb;
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

// a-upload calls beforeUpload once per file; batch them into one request.
let pending = [];
let timer = null;
function queueUpload(file) {
  if (file.size > maxKb.value * 1024) {
    message.error(`${file.name}: > ${Math.round(maxKb.value / 1024)} MB`);
    return false;
  }
  pending.push(file);
  clearTimeout(timer);
  timer = setTimeout(sendUploads, 50);
  return false;
}
async function sendUploads() {
  const files = pending;
  pending = [];
  if (!files.length) return;
  const fd = new FormData();
  files.forEach(f => fd.append('files[]', f));
  if (uploadFolder.value) fd.append('folder', uploadFolder.value);
  uploading.value = true;
  progress.value = 0;
  try {
    const { status, data } = await uploadForm('intranet-admin/files', fd, p => { progress.value = p; });
    if (status >= 200 && status < 300) {
      message.success(t('Saved_successfully'));
      load();
    } else {
      message.error(apiError({ data }));
    }
  } catch (e) {
    message.error(e.message);
  } finally {
    uploading.value = false;
  }
}

async function download(f) {
  try { await downloadFile(f); } catch (e) { message.error(apiError(e)); }
}

const editing = ref(null);
const saving = ref(false);
function openEdit(row) {
  editing.value = { id: row.id, original_name: row.original_name, title: row.title, folder: row.folder || '', description: row.description || '' };
}
async function saveEdit() {
  if (!editing.value.title.trim()) { message.warning(`${t('Intranet_Title')}: ${t('Required')}`); return; }
  saving.value = true;
  try {
    await http.put(`intranet-admin/files/${editing.value.id}`, {
      title: editing.value.title.trim(), folder: editing.value.folder || null, description: editing.value.description || null,
    });
    message.success(t('Saved_successfully'));
    editing.value = null;
    load();
  } catch (e) {
    message.error(apiError(e));
  } finally {
    saving.value = false;
  }
}

async function remove(row) {
  try {
    await http.delete(`intranet-admin/files/${row.id}`);
    message.success(t('Deleted_in_successfully'));
    load();
  } catch (e) {
    message.error(apiError(e, t('Delete_Therewassomethingwronge')));
  }
}

onMounted(load);
</script>

<style scoped>
.file-cell { display: flex; align-items: center; gap: 10px; }
.ext { flex: 0 0 44px; height: 30px; border-radius: 4px; color: #fff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.file-info { min-width: 0; }
.file-title { font-weight: 500; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
.hint { margin-left: 12px; }
</style>
