<template>
  <div class="page">
    <PageHeader :title="post?.title || $t('Intranet_News')" :breadcrumb="[$t('Intranet'), $t('Intranet_News')]">
      <template #extra>
        <a-button @click="$router.push({ name: 'intranet-news' })"><template #icon><ArrowLeftOutlined /></template>{{ $t('Intranet_Back') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-result v-if="notFound" status="404" :title="$t('Intranet_Nothing_Yet')" />
      <a-card v-else-if="post" class="article">
        <div class="meta">
          <a-tag v-if="post.pinned" color="red"><PushpinFilled /> {{ $t('Intranet_Pinned') }}</a-tag>
          <a-tag :color="categoryColor(post.category)">{{ categoryLabel(post.category) }}</a-tag>
          <span class="muted">{{ fmtDateTime(post.published_at || post.created_at) }}<template v-if="post.author"> · {{ personName(post.author) }}</template></span>
        </div>
        <img v-if="post.cover_url" :src="post.cover_url" :alt="post.title" class="cover" />
        <p v-if="post.excerpt" class="lead">{{ post.excerpt }}</p>
        <!-- eslint-disable-next-line vue/no-v-html -- purified server-side AND by DOMPurify -->
        <div class="rich ql-editor" v-html="safeHtml(post.body)"></div>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/** Intranet -> one news post (intranet_view). */
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeftOutlined, PushpinFilled } from '@ant-design/icons-vue';
import 'quill/dist/quill.snow.css';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { safeHtml, categoryLabel, categoryColor, fmtDateTime, personName } from './intranetShared';

const route = useRoute();
const post = ref(null);
const loading = ref(true);
const notFound = ref(false);

async function load() {
  loading.value = true;
  notFound.value = false;
  try {
    post.value = await http.get(`intranet/news/${route.params.id}`);
  } catch (e) {
    post.value = null;
    notFound.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => route.params.id, id => { if (id) load(); }, { immediate: true });
</script>

<style scoped>
.article { max-width: 900px; }
.meta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-bottom: 12px; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
.cover { width: 100%; max-height: 380px; object-fit: cover; border-radius: 8px; margin-bottom: 16px; }
.lead { font-size: 16px; color: var(--ant-color-text-secondary, #595959); }
.rich { padding: 0; height: auto; overflow: visible; }
.rich :deep(img) { max-width: 100%; height: auto; }
</style>
