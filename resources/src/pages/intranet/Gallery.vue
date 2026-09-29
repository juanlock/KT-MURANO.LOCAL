<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_Gallery')" :breadcrumb="[$t('Intranet'), $t('Intranet_Gallery')]" />

    <a-spin :spinning="loading">
      <a-empty v-if="!loading && !albums.length" :description="$t('Intranet_Nothing_Yet')" />
      <div class="album-grid">
        <router-link v-for="a in albums" :key="a.id" :to="{ name: 'intranet-album', params: { id: a.id } }" class="album">
          <div class="album-cover" :class="{ empty: !a.cover_url }">
            <img v-if="a.cover_url" :src="a.cover_url" :alt="a.title" loading="lazy" />
            <PictureOutlined v-else />
            <span class="count">{{ a.photos_count }} {{ $t('Intranet_Photos') }}</span>
          </div>
          <div class="album-title">{{ a.title }}</div>
          <div class="muted">{{ a.album_date ? fmtDate(a.album_date) : '' }}</div>
        </router-link>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/** Intranet -> photo albums (intranet_view). */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PictureOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { fmtDate, apiError } from './intranetShared';

const loading = ref(true);
const albums = ref([]);

onMounted(async () => {
  try {
    albums.value = (await http.get('intranet/albums')).data || [];
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.album-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
.album { color: inherit; display: block; }
.album-cover { position: relative; aspect-ratio: 4 / 3; border-radius: 10px; overflow: hidden; background: #f0f0f0; display: flex; align-items: center; justify-content: center; }
.album-cover.empty { font-size: 40px; color: #bfbfbf; }
.album-cover img { width: 100%; height: 100%; object-fit: cover; transition: transform .25s; }
.album:hover .album-cover img { transform: scale(1.04); }
.count { position: absolute; right: 8px; bottom: 8px; background: rgba(0, 0, 0, .6); color: #fff; font-size: 12px; border-radius: 10px; padding: 1px 8px; }
.album-title { font-weight: 600; margin-top: 8px; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
</style>
