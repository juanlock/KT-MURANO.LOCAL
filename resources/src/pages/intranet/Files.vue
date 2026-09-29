<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_Files')" :breadcrumb="[$t('Intranet'), $t('Intranet_Files')]">
      <template #extra>
        <a-input-search v-model:value="search" :placeholder="$t('Intranet_Search')" allow-clear style="width: 220px" @search="load" />
        <a-select v-model:value="folder" :options="folderOptions" style="width: 200px" @change="load" />
      </template>
    </PageHeader>

    <a-card size="small">
      <a-table :data-source="rows" :columns="columns" :loading="loading" row-key="id" size="middle" :pagination="{ pageSize: 25, hideOnSinglePage: true }" :scroll="{ x: 700 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'title'">
            <div class="file-cell">
              <span class="ext" :style="{ background: fileIconColor(record.extension) }">{{ (record.extension || '?').toUpperCase() }}</span>
              <div class="file-info">
                <div class="file-title">{{ record.title }}</div>
                <div v-if="record.description" class="muted">{{ record.description }}</div>
                <div class="muted">{{ record.original_name }}</div>
              </div>
            </div>
          </template>
          <template v-else-if="column.key === 'folder'">
            <a-tag v-if="record.folder"><FolderOutlined /> {{ record.folder }}</a-tag>
          </template>
          <template v-else-if="column.key === 'size'">{{ formatBytes(record.size) }}</template>
          <template v-else-if="column.key === 'created_at'">{{ fmtDate(record.created_at) }}</template>
          <template v-else-if="column.key === 'action'">
            <a-button type="primary" size="small" :loading="busy === record.id" @click="download(record)">
              <template #icon><DownloadOutlined /></template>{{ $t('Intranet_Download') }}
            </a-button>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/** Intranet -> document library (intranet_view). Downloads go through the authenticated API. */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DownloadOutlined, FolderOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel, withAllOption } from '../../lib/filterOptions';
import { formatBytes, fmtDate, fileIconColor, downloadFile, apiError } from './intranetShared';

const { t } = useI18n();
const loading = ref(true);
const rows = ref([]);
const folders = ref([]);
const search = ref('');
const folder = ref('');
const busy = ref(null);

const folderOptions = computed(() => withAllOption(sortByLabel(folders.value.map(f => ({ value: f, label: f })))));
const columns = computed(() => [
  { title: t('Intranet_Title'), key: 'title' },
  { title: t('Intranet_Folder'), key: 'folder', width: 180 },
  { title: t('Intranet_File_Size'), key: 'size', width: 110 },
  { title: t('Intranet_Date'), key: 'created_at', width: 120 },
  { title: '', key: 'action', width: 140, align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    const r = await http.get('intranet/files', { search: search.value || undefined, folder: folder.value || undefined });
    rows.value = r.data || [];
    folders.value = r.folders || [];
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

async function download(f) {
  busy.value = f.id;
  try { await downloadFile(f); } catch (e) { message.error(apiError(e)); } finally { busy.value = null; }
}

onMounted(load);
</script>

<style scoped>
.file-cell { display: flex; align-items: center; gap: 10px; }
.ext { flex: 0 0 44px; height: 30px; border-radius: 4px; color: #fff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.file-info { min-width: 0; }
.file-title { font-weight: 500; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
</style>
