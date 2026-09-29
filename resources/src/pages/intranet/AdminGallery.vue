<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_Gallery')" :breadcrumb="[$t('Intranet_Management'), $t('Intranet_Gallery')]">
      <template #extra>
        <a-button @click="openAlbum()"><template #icon><PlusOutlined /></template>{{ $t('Intranet_Add_Album') }}</a-button>
        <a-upload v-if="album" :show-upload-list="false" multiple accept="image/jpeg,image/png,image/webp,image/gif" :before-upload="queueUpload">
          <a-button type="primary" :loading="uploading"><template #icon><UploadOutlined /></template>{{ $t('Upload_Photos') }} → {{ album.title }}</a-button>
        </a-upload>
      </template>
    </PageHeader>
    <a-progress v-if="uploading" :percent="progress" size="small" style="margin-bottom: 12px" />

    <a-row :gutter="12">
      <a-col :xs="24" :md="8" :xl="6">
        <a-card size="small" :title="$t('Intranet_Albums')" :body-style="{ padding: '6px' }" :loading="loading">
          <a-empty v-if="!albums.length" :description="$t('Intranet_Nothing_Yet')" />
          <div v-for="(a, i) in albums" :key="a.id" class="al" :class="{ on: album && album.id === a.id }" @click="selectAlbum(a)">
            <div class="al-thumb"><img v-if="a.cover_url" :src="a.cover_url" alt="" /><PictureOutlined v-else /></div>
            <div class="al-info">
              <div class="al-name">{{ a.title }}</div>
              <div class="muted">{{ a.photos_count }} {{ $t('Intranet_Photos') }}<template v-if="!a.published"> · {{ $t('Hidden') }}</template></div>
            </div>
            <span class="al-act" @click.stop>
              <a-button size="small" type="text" :disabled="i === 0" @click="moveAlbum(i, -1)">↑</a-button>
              <a-button size="small" type="text" :disabled="i === albums.length - 1" @click="moveAlbum(i, 1)">↓</a-button>
              <a-button size="small" type="text" @click="openAlbum(a)"><template #icon><EditOutlined /></template></a-button>
              <a-popconfirm :title="$t('Delete_Title')" :ok-text="$t('Delete_confirmButtonText')" :cancel-text="$t('Delete_cancelButtonText')" @confirm="removeAlbum(a)">
                <a-button size="small" type="text" danger><template #icon><DeleteOutlined /></template></a-button>
              </a-popconfirm>
            </span>
          </div>
        </a-card>
      </a-col>

      <a-col :xs="24" :md="16" :xl="18">
        <a-card size="small" :loading="photosLoading" :title="album ? album.title : ''">
          <a-empty v-if="!album" :description="$t('Intranet_Select_Album')" />
          <a-empty v-else-if="!photos.length" :description="$t('Intranet_Nothing_Yet')" />
          <div v-else class="grid">
            <div v-for="(p, i) in photos" :key="p.id" class="ph" :class="{ cover: album.cover_photo_id === p.id }">
              <a :href="p.image_url" target="_blank" class="ph-thumb"><img :src="p.image_url" :alt="p.title || ''" loading="lazy" /></a>
              <a-input v-model:value="p.title" size="small" :maxlength="190" :placeholder="$t('Intranet_Title')" @blur="savePhoto(p)" @press-enter="$event.target.blur()" />
              <div class="ph-actions">
                <a-tooltip :title="$t('Intranet_Set_Cover')">
                  <a-button size="small" :type="album.cover_photo_id === p.id ? 'primary' : 'default'" @click="setCover(p)"><template #icon><StarOutlined /></template></a-button>
                </a-tooltip>
                <a-space :size="4">
                  <a-button size="small" :disabled="i === 0" @click="movePhoto(i, -1)"><template #icon><ArrowLeftOutlined /></template></a-button>
                  <a-button size="small" :disabled="i === photos.length - 1" @click="movePhoto(i, 1)"><template #icon><ArrowRightOutlined /></template></a-button>
                  <a-popconfirm :title="$t('Delete_Title')" :ok-text="$t('Delete_confirmButtonText')" :cancel-text="$t('Delete_cancelButtonText')" @confirm="removePhoto(p)">
                    <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
                  </a-popconfirm>
                </a-space>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-modal :open="albumFormOpen" :title="albumForm.id ? $t('Intranet_Edit_Album') : $t('Intranet_Add_Album')" :confirm-loading="saving" :ok-text="$t('Intranet_Save')" :cancel-text="$t('Intranet_Cancel')" @ok="saveAlbum" @cancel="albumFormOpen = false">
      <a-form layout="vertical">
        <a-form-item :label="$t('Intranet_Title')" required><a-input v-model:value="albumForm.title" :maxlength="190" /></a-form-item>
        <a-form-item :label="$t('Intranet_Description')"><a-textarea v-model:value="albumForm.description" :maxlength="2000" :auto-size="{ minRows: 2, maxRows: 5 }" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="14"><a-form-item :label="$t('Intranet_Album_Date')"><a-date-picker v-model:value="albumForm.album_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="10"><a-form-item :label="$t('Intranet_Published')"><a-switch v-model:checked="albumForm.published" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Intranet Management -> Gallery (intranet_manage): albums (left) and the
 * selected album's photos (right) -- upload several at once, caption,
 * reorder, pick the cover.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, UploadOutlined, EditOutlined, DeleteOutlined, PictureOutlined, StarOutlined, ArrowLeftOutlined, ArrowRightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { apiError } from './intranetShared';

const { t } = useI18n();
const loading = ref(true);
const albums = ref([]);
const album = ref(null);
const photosLoading = ref(false);
const uploading = ref(false);
const progress = ref(0);
const saving = ref(false);
const photos = computed(() => album.value?.photos || []);
const savedTitles = new Map();

async function loadAlbums() {
  loading.value = true;
  try {
    albums.value = (await http.get('intranet-admin/albums')).data || [];
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

async function selectAlbum(a) {
  photosLoading.value = true;
  try {
    album.value = await http.get(`intranet-admin/albums/${a.id}`);
    album.value.photos.forEach(p => savedTitles.set(p.id, p.title || ''));
  } catch (e) {
    message.error(apiError(e));
  } finally {
    photosLoading.value = false;
  }
}

/* album form */
const albumFormOpen = ref(false);
const albumForm = ref({});
function openAlbum(a) {
  albumForm.value = a
    ? { id: a.id, title: a.title, description: a.description || '', album_date: a.album_date || null, published: !!a.published, cover_photo_id: a.cover_photo_id }
    : { id: null, title: '', description: '', album_date: null, published: true };
  albumFormOpen.value = true;
}
async function saveAlbum() {
  if (!albumForm.value.title.trim()) { message.warning(`${t('Intranet_Title')}: ${t('Required')}`); return; }
  saving.value = true;
  try {
    const body = {
      title: albumForm.value.title.trim(), description: albumForm.value.description || null,
      album_date: albumForm.value.album_date || null, published: albumForm.value.published,
    };
    let saved;
    if (albumForm.value.id) {
      saved = await http.put(`intranet-admin/albums/${albumForm.value.id}`, { ...body, cover_photo_id: albumForm.value.cover_photo_id || null });
    } else {
      saved = await http.post('intranet-admin/albums', body);
    }
    albumFormOpen.value = false;
    message.success(t('Saved_successfully'));
    await loadAlbums();
    selectAlbum(saved);
  } catch (e) {
    message.error(apiError(e));
  } finally {
    saving.value = false;
  }
}
async function removeAlbum(a) {
  try {
    await http.delete(`intranet-admin/albums/${a.id}`);
    if (album.value?.id === a.id) album.value = null;
    message.success(t('Deleted_in_successfully'));
    loadAlbums();
  } catch (e) {
    message.error(apiError(e, t('Delete_Therewassomethingwronge')));
  }
}
async function moveAlbum(i, dir) {
  const list = albums.value;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  try { await http.post('intranet-admin/albums/reorder', { ids: list.map(a => a.id) }); } catch (e) { loadAlbums(); }
}

/* photos */
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
  if (!files.length || !album.value) return;
  const fd = new FormData();
  files.forEach(f => fd.append('images[]', f));
  uploading.value = true;
  progress.value = 0;
  try {
    const { status, data } = await uploadForm(`intranet-admin/albums/${album.value.id}/photos`, fd, p => { progress.value = p; });
    if (status >= 200 && status < 300) {
      message.success(t('Saved_successfully'));
      selectAlbum(album.value);
      loadAlbums();
    } else {
      message.error(apiError({ data }));
    }
  } catch (e) {
    message.error(e.message);
  } finally {
    uploading.value = false;
  }
}
async function savePhoto(p) {
  if ((p.title || '') === savedTitles.get(p.id)) return;
  try {
    await http.put(`intranet-admin/photos/${p.id}`, { title: p.title || null });
    savedTitles.set(p.id, p.title || '');
  } catch (e) {
    message.error(apiError(e));
  }
}
async function setCover(p) {
  const a = album.value;
  try {
    await http.put(`intranet-admin/albums/${a.id}`, {
      title: a.title, description: a.description || null, album_date: a.album_date || null, published: !!a.published, cover_photo_id: p.id,
    });
    a.cover_photo_id = p.id;
    loadAlbums();
  } catch (e) {
    message.error(apiError(e));
  }
}
async function movePhoto(i, dir) {
  const list = album.value.photos;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  try { await http.post(`intranet-admin/albums/${album.value.id}/photos/reorder`, { ids: list.map(p => p.id) }); } catch (e) { selectAlbum(album.value); }
}
async function removePhoto(p) {
  try {
    await http.delete(`intranet-admin/photos/${p.id}`);
    album.value.photos = album.value.photos.filter(x => x.id !== p.id);
    if (album.value.cover_photo_id === p.id) album.value.cover_photo_id = null;
    loadAlbums();
  } catch (e) {
    message.error(apiError(e, t('Delete_Therewassomethingwronge')));
  }
}

onMounted(async () => {
  await loadAlbums();
  if (albums.value.length) selectAlbum(albums.value[0]);
});
</script>

<style scoped>
.al { display: flex; align-items: center; gap: 8px; padding: 6px; border-radius: 6px; cursor: pointer; }
.al:hover { background: rgba(0, 0, 0, 0.03); }
.al.on { background: rgba(22, 119, 255, 0.08); }
.al-thumb { flex: 0 0 48px; height: 36px; border-radius: 4px; overflow: hidden; background: #f0f0f0; display: flex; align-items: center; justify-content: center; color: #bfbfbf; }
.al-thumb img { width: 100%; height: 100%; object-fit: cover; }
.al-info { flex: 1; min-width: 0; }
.al-name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.al-act { display: none; }
.al:hover .al-act { display: inline-flex; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
.ph { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.ph.cover { border-color: #1677ff; box-shadow: 0 0 0 1px #1677ff; }
.ph-thumb { display: block; aspect-ratio: 4 / 3; border-radius: 6px; overflow: hidden; background: #f5f5f5; }
.ph-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ph-actions { display: flex; justify-content: space-between; align-items: center; }
</style>
