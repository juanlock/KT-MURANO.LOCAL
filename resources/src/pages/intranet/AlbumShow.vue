<template>
  <div class="page">
    <PageHeader :title="album?.title || $t('Intranet_Album')" :subtitle="album?.description || ''" :breadcrumb="[$t('Intranet'), $t('Intranet_Gallery'), album?.title || '']">
      <template #extra>
        <a-button @click="$router.push({ name: 'intranet-gallery' })"><template #icon><ArrowLeftOutlined /></template>{{ $t('Intranet_Back') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-result v-if="notFound" status="404" :title="$t('Intranet_Nothing_Yet')" />
      <a-card v-else size="small">
        <a-empty v-if="!loading && !photos.length" :description="$t('Intranet_Nothing_Yet')" />
        <a-image-preview-group>
          <div class="photo-grid">
            <div v-for="p in photos" :key="p.id" class="photo">
              <a-image :src="p.image_url" :alt="p.title || ''" width="100%" height="100%" />
              <div v-if="p.title" class="caption">{{ p.title }}</div>
            </div>
          </div>
        </a-image-preview-group>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/** Intranet -> one album's photos with a lightbox (intranet_view). */
import { ref, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeftOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const route = useRoute();
const album = ref(null);
const loading = ref(true);
const notFound = ref(false);
const photos = computed(() => album.value?.photos || []);

async function load() {
  loading.value = true;
  notFound.value = false;
  try {
    album.value = await http.get(`intranet/albums/${route.params.id}`);
  } catch (e) {
    album.value = null;
    notFound.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => route.params.id, id => { if (id) load(); }, { immediate: true });
</script>

<style scoped>
.photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
.photo { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; background: #f5f5f5; }
.photo :deep(.ant-image) { width: 100%; height: 100%; }
.photo :deep(.ant-image-img) { width: 100%; height: 100%; object-fit: cover; }
.caption { position: absolute; left: 0; right: 0; bottom: 0; padding: 4px 8px; font-size: 12px; color: #fff; background: linear-gradient(transparent, rgba(0, 0, 0, .65)); pointer-events: none; }
</style>
