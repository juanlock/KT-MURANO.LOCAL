<template>
  <div class="page">
    <PageHeader :title="$t('Gallery')" :breadcrumb="[$t('Store'), $t('Gallery')]">
      <template #extra>
        <a-space wrap>
          <a-button v-if="publicUrl" :href="publicUrl" target="_blank">
            <template #icon><EyeOutlined /></template>
            {{ $t('View_Gallery') }}
          </a-button>
          <a-button v-if="canImport" :loading="importing" @click="importSite">
            <template #icon><CloudDownloadOutlined /></template>
            {{ $t('Import_From_Website') }}
          </a-button>
          <a-upload
            :show-upload-list="false" multiple accept="image/jpeg,image/png,image/webp,image/gif"
            :before-upload="queueUpload"
          >
            <a-button type="primary" :loading="uploading">
              <template #icon><UploadOutlined /></template>
              {{ $t('Upload_Photos') }}
            </a-button>
          </a-upload>
        </a-space>
      </template>
    </PageHeader>

    <a-alert
      v-if="canImport && siteCandidates"
      type="info" show-icon style="margin-bottom: 12px"
      :message="$t('Gallery_Import_Hint', { n: siteCandidates })"
    />
    <a-progress v-if="uploading" :percent="progress" size="small" style="margin-bottom: 12px" />

    <a-card size="small" :loading="isLoading">
      <a-empty v-if="!rows.length" :description="$t('NodataAvailable')" />
      <div v-else class="gallery-grid">
        <div v-for="(img, i) in rows" :key="img.id" class="gallery-card" :class="{ off: !img.active }">
          <a :href="img.image_url" target="_blank" class="thumb">
            <img :src="img.image_url" :alt="img.title" loading="lazy" />
            <a-tag v-if="img.source === 'imported'" color="blue" class="src-tag">{{ $t('Imported') }}</a-tag>
          </a>
          <a-input
            v-model:value="img.title" size="small" :maxlength="190" :placeholder="$t('Title')"
            @blur="saveTitle(img)" @press-enter="$event.target.blur()"
          />
          <div class="card-actions">
            <a-tooltip :title="img.active ? $t('Visible') : $t('Hidden')">
              <a-switch size="small" :checked="img.active" @change="v => toggle(img, v)" />
            </a-tooltip>
            <span class="dims" v-if="img.width">{{ img.width }}×{{ img.height }}</span>
            <a-space :size="4">
              <a-button size="small" :disabled="i === 0" @click="move(i, -1)"><template #icon><ArrowLeftOutlined /></template></a-button>
              <a-button size="small" :disabled="i === rows.length - 1" @click="move(i, 1)"><template #icon><ArrowRightOutlined /></template></a-button>
              <a-button size="small" danger @click="remove(img)"><template #icon><DeleteOutlined /></template></a-button>
            </a-space>
          </div>
        </div>
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Store -> Gallery (per company): pictures shown on the online store's
 * /gallery page. GET store/gallery -> {data, can_import, site_candidates,
 * public_url}; POST store/gallery (images[]); PUT/DELETE store/gallery/{id};
 * POST store/gallery/reorder {ids}; POST store/gallery/import-site copies the
 * company's imported website pictures in (skips ones already there).
 */
import { ref, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  UploadOutlined, CloudDownloadOutlined, EyeOutlined, DeleteOutlined, ArrowLeftOutlined, ArrowRightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();

const isLoading = ref(true);
const rows = ref([]);
const canImport = ref(false);
const siteCandidates = ref(0);
const publicUrl = ref('');
const importing = ref(false);
const uploading = ref(false);
const progress = ref(0);
const titles = new Map();

async function fetch() {
  isLoading.value = true;
  try {
    const data = await http.get('store/gallery');
    rows.value = data.data || [];
    rows.value.forEach(r => titles.set(r.id, r.title || ''));
    canImport.value = !!data.can_import;
    siteCandidates.value = data.site_candidates || 0;
    publicUrl.value = data.public_url || '';
  } finally {
    isLoading.value = false;
  }
}

// a-upload calls beforeUpload once per file; batch them into one request.
let pending = [];
let timer = null;
function queueUpload(file) {
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
  files.forEach(f => fd.append('images[]', f));
  uploading.value = true;
  progress.value = 0;
  try {
    const { status, data } = await uploadForm('store/gallery', fd, p => { progress.value = p; });
    if (status >= 200 && status < 300) {
      message.success(t('Saved_successfully'));
      fetch();
    } else {
      const errs = data?.errors ? Object.values(data.errors).flat().join(' ') : '';
      message.error(errs || data?.message || t('InvalidData'));
    }
  } catch (e) {
    message.error(e.message);
  } finally {
    uploading.value = false;
  }
}

async function importSite() {
  importing.value = true;
  try {
    const r = await http.post('store/gallery/import-site');
    message.success(t('Gallery_Import_Done', { added: r.added, skipped: r.skipped }));
    fetch();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    importing.value = false;
  }
}

async function saveTitle(img) {
  if ((img.title || '') === titles.get(img.id)) return;
  try {
    await http.put(`store/gallery/${img.id}`, { title: img.title || null, active: img.active });
    titles.set(img.id, img.title || '');
    message.success(t('Saved_successfully'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function toggle(img, v) {
  const before = img.active;
  img.active = v;
  try {
    await http.put(`store/gallery/${img.id}`, { title: img.title || null, active: v });
  } catch (e) {
    img.active = before;
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function move(i, dir) {
  const list = rows.value;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  try {
    await http.post('store/gallery/reorder', { ids: list.map(r => r.id) });
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
    fetch();
  }
}

function remove(img) {
  Modal.confirm({
    title: t('Delete_Title'),
    content: img.title,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`store/gallery/${img.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(e?.data?.message || t('Delete_Therewassomethingwronge'));
      }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.gallery-card { border: 1px solid var(--ant-color-border-secondary, #f0f0f0); border-radius: 8px; padding: 8px; display: flex; flex-direction: column; gap: 8px; }
.gallery-card.off .thumb img { opacity: .4; }
.thumb { position: relative; display: block; aspect-ratio: 4 / 3; border-radius: 6px; overflow: hidden; background: #f5f5f5; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }
.src-tag { position: absolute; top: 6px; left: 6px; margin: 0; }
.card-actions { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.dims { font-size: 11px; color: var(--ant-color-text-tertiary, #999); }
</style>
