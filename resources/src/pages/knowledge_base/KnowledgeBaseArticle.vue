<template>
  <div class="page">
    <PageHeader :title="$t('Knowledge_Base')" :breadcrumb="[$t('Knowledge_Base'), article?.title || '…']">
      <template #actions>
        <a-button @click="$router.push('/kb')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Knowledge_Base') }}
        </a-button>
        <a-button type="primary" @click="$router.push(`/kb/articles/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else-if="article" class="kb-article-card">
      <article class="kb-article">
        <header class="kb-article-header">
          <a-space :size="6" wrap>
            <a-tag v-if="article.group?.name" :bordered="false" color="purple">{{ article.group.name }}</a-tag>
            <a-tag v-if="Number(article.is_internal)" :bordered="false" color="warning">{{ $t('Internal') }}</a-tag>
            <a-tag v-if="!article.published_at" :bordered="false">{{ tf('Draft', 'Draft') }}</a-tag>
          </a-space>
          <a-typography-title :level="2" class="kb-article-title">{{ article.title }}</a-typography-title>
          <a-typography-text v-if="article.published_at" type="secondary" class="kb-article-meta">
            <CalendarOutlined /> {{ date(article.published_at) }}
          </a-typography-text>
          <a-divider class="kb-article-divider" />
        </header>
        <!-- Quill HTML, rendered like the legacy view page. -->
        <div class="kb-content" v-html="article.content"></div>
      </article>
    </a-card>

    <a-result v-else status="404" :title="$t('NodataAvailable')">
      <template #extra>
        <a-button type="primary" @click="$router.push('/kb')">{{ $t('Knowledge_Base') }}</a-button>
      </template>
    </a-result>
  </div>
</template>

<script setup>
/** Article view: GET knowledge-base/articles/{id} → article (or `.data`). */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowLeftOutlined, EditOutlined, CalendarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { t as tf } from '../../i18n';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const route = useRoute();
const id = computed(() => route.params.id);

const loading = ref(true);
const article = ref(null);

onMounted(async () => {
  try {
    const data = await http.get(`knowledge-base/articles/${id.value}`);
    article.value = data?.data || data || null;
  } catch (e) {
    article.value = null;
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
/* Reading column: comfortable measure, surfaces stay antd-theme neutral. */
.kb-article {
  max-width: 760px;
  margin: 0 auto;
  padding: 8px 0 16px;
}
.kb-article-title.ant-typography { margin: 10px 0 6px; }
.kb-article-meta { font-size: 13px; }
.kb-article-divider { margin: 18px 0 22px; }

.kb-content { font-size: 15px; line-height: 1.75; }
.kb-content :deep(h1), .kb-content :deep(h2), .kb-content :deep(h3) {
  margin: 24px 0 10px;
  line-height: 1.3;
}
.kb-content :deep(p) { margin: 0 0 14px; }
.kb-content :deep(img) {
  max-width: 100%;
  border-radius: 10px;
}
.kb-content :deep(ul), .kb-content :deep(ol) {
  margin: 0 0 14px;
  padding-inline-start: 22px;
}
.kb-content :deep(li) { margin-bottom: 6px; }
.kb-content :deep(a) { color: #6d28d9; }
.kb-content :deep(blockquote) {
  margin: 0 0 14px;
  padding: 8px 16px;
  border-inline-start: 3px solid #8b5cf6;
  background: rgba(109, 40, 217, 0.06);
  border-radius: 0 8px 8px 0;
}
[dir='rtl'] .kb-content :deep(blockquote) { border-radius: 8px 0 0 8px; }
.kb-content :deep(pre) {
  background: rgba(128, 128, 128, 0.1);
  padding: 12px 14px;
  border-radius: 8px;
  overflow-x: auto;
  font-size: 13px;
}
.kb-content :deep(code) {
  background: rgba(128, 128, 128, 0.12);
  padding: 1px 5px;
  border-radius: 5px;
  font-size: 0.9em;
}
.kb-content :deep(pre code) { background: none; padding: 0; }
.kb-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 0 0 14px;
}
.kb-content :deep(th), .kb-content :deep(td) {
  border: 1px solid rgba(128, 128, 128, 0.25);
  padding: 8px 10px;
  text-align: start;
}
</style>
