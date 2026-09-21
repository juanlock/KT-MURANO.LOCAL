<template>
  <div class="page">
    <PageHeader :title="$t('Knowledge_Base')" :breadcrumb="[$t('Knowledge_Base')]">
      <template #actions>
        <a-button @click="$router.push('/kb/groups')">
          <template #icon><FolderOutlined /></template>
          {{ $t('Article_Groups') }}
        </a-button>
        <a-button type="primary" @click="$router.push('/kb/articles/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Hero: centered search on the brand gradient. -->
    <div class="kb-hero">
      <div class="kb-hero-inner">
        <a-typography-title :level="4" class="kb-hero-title">
          {{ tf('KB_Hero_Title', 'How can we help?') }}
        </a-typography-title>
        <a-typography-text class="kb-hero-subtitle">
          {{ tf('KB_Hero_Subtitle', 'Search guides and answers from your team’s knowledge base.') }}
        </a-typography-text>
        <a-input
          v-model:value="q"
          class="kb-hero-search"
          size="large"
          allow-clear
          :placeholder="$t('Search_this_table')"
          @input="onSearchInput"
        >
          <template #prefix><SearchOutlined /></template>
        </a-input>
      </div>
    </div>

    <!-- Group filter chips with live counts. -->
    <div class="kb-chips">
      <button
        type="button"
        class="kb-chip"
        :class="{ active: groupId === undefined }"
        @click="selectGroup(undefined)"
      >
        {{ $t('All_Groups') }}
        <span class="kb-chip-count">{{ totalAll }}</span>
      </button>
      <button
        v-for="g in groups"
        :key="g.id"
        type="button"
        class="kb-chip"
        :class="{ active: groupId === g.id }"
        @click="selectGroup(groupId === g.id ? undefined : g.id)"
      >
        {{ g.name }}
        <span class="kb-chip-count">{{ countByGroup[g.id] || 0 }}</span>
      </button>
    </div>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else-if="!articles.length" class="kb-empty">
      <a-empty :description="null">
        <template #image><ReadOutlined class="kb-empty-icon" /></template>
        <a-typography-title :level="5" style="margin: 0 0 4px">
          {{ q || groupId ? $t('NodataAvailable') : tf('KB_Empty_Title', 'No articles yet') }}
        </a-typography-title>
        <a-typography-text type="secondary">
          {{ q || groupId
            ? tf('KB_Empty_Filtered', 'Try a different search or group.')
            : tf('KB_Empty_Hint', 'Write the first article so your team can find answers here.') }}
        </a-typography-text>
        <div v-if="!q && !groupId" style="margin-top: 16px">
          <a-button type="primary" @click="$router.push('/kb/articles/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
        </div>
      </a-empty>
    </a-card>

    <a-row v-else :gutter="[16, 16]">
      <a-col v-for="article in articles" :key="article.id" :xs="24" :md="12" :xl="8">
        <a-card
          class="kb-card"
          role="link"
          tabindex="0"
          @click="$router.push(`/kb/articles/${article.id}`)"
          @keydown.enter="$router.push(`/kb/articles/${article.id}`)"
        >
          <div class="kb-card-head">
            <span class="kb-card-icon"><FileTextOutlined /></span>
            <a-space :size="4" wrap>
              <a-tag v-if="article.group?.name" :bordered="false">{{ article.group.name }}</a-tag>
              <a-tag v-if="Number(article.is_internal)" :bordered="false" color="warning">{{ $t('Internal') }}</a-tag>
              <a-tag v-if="!article.published_at" :bordered="false" color="default">
                {{ tf('Draft', 'Draft') }}
              </a-tag>
            </a-space>
          </div>
          <a-typography-text strong class="kb-card-title">{{ article.title }}</a-typography-text>
          <a-typography-paragraph
            type="secondary"
            :content="excerpt(article)"
            :ellipsis="{ rows: 2 }"
            class="kb-card-excerpt"
          />
          <div class="kb-card-foot">
            <a-typography-text type="secondary" style="font-size: 12px">
              <template v-if="article.published_at">{{ date(article.published_at) }}</template>
            </a-typography-text>
            <span class="kb-card-arrow"><ArrowRightOutlined /></span>
          </div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * KB browsing list: GET knowledge-base/articles {per_page, page, q?, group_id?}
 * → Laravel paginator (`data.data`) or plain array; groups list is array-or-
 * `.data` too. Search is debounced and both filters stay server-side (legacy
 * parity); chip counts come from the latest unfiltered load.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import {
  PlusOutlined, FolderOutlined, SearchOutlined, ReadOutlined,
  FileTextOutlined, ArrowRightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { t as tf } from '../../i18n';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { date } = useFormat();

const loading = ref(true);
const q = ref('');
const groupId = ref(undefined);
const articles = ref([]);
const groups = ref([]);
const countByGroup = ref({});
const totalAll = ref(0);

function excerpt(article) {
  // Content is Quill HTML; strip tags for the card preview.
  const div = document.createElement('div');
  div.innerHTML = article.content || '';
  return (div.textContent || '').trim().slice(0, 180);
}

async function load() {
  loading.value = true;
  try {
    const params = { per_page: 100, page: 1 };
    if (q.value) params.q = q.value;
    if (groupId.value) params.group_id = groupId.value;
    const data = await http.get('knowledge-base/articles', params);
    articles.value = Array.isArray(data) ? data : data.data || [];
    // Chip counts track the latest unfiltered result set.
    if (!groupId.value) {
      totalAll.value = articles.value.length;
      const counts = {};
      for (const a of articles.value) {
        const gid = a.knowledge_base_article_group_id ?? a.group?.id;
        if (gid != null) counts[gid] = (counts[gid] || 0) + 1;
      }
      countByGroup.value = counts;
    }
  } catch (e) {
    articles.value = [];
  } finally {
    loading.value = false;
  }
}

function selectGroup(id) {
  groupId.value = id;
  load();
}

let searchTimer = null;
function onSearchInput() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(load, 350);
}
onBeforeUnmount(() => clearTimeout(searchTimer));

async function loadGroups() {
  try {
    const data = await http.get('knowledge-base/groups');
    groups.value = Array.isArray(data) ? data : data.data || [];
  } catch (e) { /* filter stays empty */ }
}

onMounted(() => {
  load();
  loadGroups();
});
</script>

<style scoped>
/* Brand accent is theme-fixed (colorPrimary #6d28d9); surfaces stay neutral
   rgba so antd's light/dark algorithm keeps owning the page background. */
.kb-hero {
  background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 60%, #a78bfa 100%);
  border-radius: 14px;
  padding: 36px 24px 40px;
  margin-bottom: 16px;
  text-align: center;
}
.kb-hero-inner {
  max-width: 560px;
  margin: 0 auto;
}
.kb-hero-title.ant-typography {
  color: #fff;
  margin: 0 0 4px;
}
.kb-hero-subtitle.ant-typography {
  color: rgba(255, 255, 255, 0.85);
  display: block;
  margin-bottom: 20px;
}
.kb-hero-search {
  border-radius: 10px;
}

.kb-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
}
.kb-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 1px solid rgba(128, 128, 128, 0.3);
  background: transparent;
  color: inherit;
  border-radius: 999px;
  padding: 4px 12px;
  font-size: 13px;
  cursor: pointer;
  transition: border-color 0.2s, color 0.2s, background 0.2s;
}
.kb-chip:hover { border-color: #8b5cf6; color: #8b5cf6; }
.kb-chip:focus-visible { outline: 2px solid #8b5cf6; outline-offset: 2px; }
.kb-chip.active {
  background: #6d28d9;
  border-color: #6d28d9;
  color: #fff;
}
.kb-chip-count {
  font-size: 11px;
  opacity: 0.75;
  background: rgba(128, 128, 128, 0.18);
  border-radius: 999px;
  padding: 0 6px;
  line-height: 16px;
}
.kb-chip.active .kb-chip-count {
  background: rgba(255, 255, 255, 0.25);
  opacity: 1;
}

.kb-card {
  height: 100%;
  border-radius: 12px;
  cursor: pointer;
  transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
}
.kb-card :deep(.ant-card-body) {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
}
.kb-card:hover,
.kb-card:focus-visible {
  border-color: #8b5cf6;
  box-shadow: 0 6px 18px rgba(109, 40, 217, 0.14);
  transform: translateY(-2px);
  outline: none;
}
@media (prefers-reduced-motion: reduce) {
  .kb-card, .kb-chip { transition: none; }
  .kb-card:hover, .kb-card:focus-visible { transform: none; }
}
.kb-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}
.kb-card-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 9px;
  flex: none;
  font-size: 16px;
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.1);
}
.kb-card-title { font-size: 15px; }
.kb-card-excerpt.ant-typography {
  margin: 0;
  font-size: 13px;
  flex: 1;
}
.kb-card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.kb-card-arrow {
  color: #8b5cf6;
  opacity: 0;
  transform: translateX(-4px);
  transition: opacity 0.2s, transform 0.2s;
}
.kb-card:hover .kb-card-arrow,
.kb-card:focus-visible .kb-card-arrow {
  opacity: 1;
  transform: translateX(0);
}
[dir='rtl'] .kb-card-arrow { transform: translateX(4px) scaleX(-1); }
[dir='rtl'] .kb-card:hover .kb-card-arrow,
[dir='rtl'] .kb-card:focus-visible .kb-card-arrow { transform: translateX(0) scaleX(-1); }

.kb-empty { padding: 32px 0; text-align: center; }
.kb-empty-icon { font-size: 42px; color: rgba(128, 128, 128, 0.45); }
</style>
