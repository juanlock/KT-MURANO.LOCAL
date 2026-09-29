<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_News')" :breadcrumb="[$t('Intranet'), $t('Intranet_News')]">
      <template #extra>
        <a-input-search v-model:value="search" :placeholder="$t('Intranet_Search')" allow-clear style="width: 220px" @search="load(1)" />
        <a-select v-model:value="category" :options="categoryOptions" style="width: 170px" @change="load(1)" />
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-empty v-if="!loading && !rows.length" :description="$t('Intranet_Nothing_Yet')" />
      <a-row :gutter="[16, 16]">
        <a-col v-for="p in rows" :key="p.id" :xs="24" :sm="12" :xl="8">
          <router-link :to="{ name: 'intranet-news-show', params: { id: p.id } }" class="card-link">
            <a-card hoverable size="small" class="news-card">
              <template #cover>
                <div class="cover" :class="{ empty: !p.cover_url }">
                  <img v-if="p.cover_url" :src="p.cover_url" :alt="p.title" loading="lazy" />
                  <NotificationOutlined v-else />
                </div>
              </template>
              <div class="meta">
                <a-tag v-if="p.pinned" color="red"><PushpinFilled /> {{ $t('Intranet_Pinned') }}</a-tag>
                <a-tag :color="categoryColor(p.category)">{{ categoryLabel(p.category) }}</a-tag>
              </div>
              <div class="title">{{ p.title }}</div>
              <div class="summary">{{ p.summary }}</div>
              <div class="muted">{{ fmtDate(p.published_at || p.created_at) }}<template v-if="p.author"> · {{ personName(p.author) }}</template></div>
            </a-card>
          </router-link>
        </a-col>
      </a-row>
      <div v-if="total > perPage" class="pager">
        <a-pagination :current="page" :page-size="perPage" :total="total" :show-size-changer="false" @change="load" />
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/** Intranet -> all news (intranet_view): pinned first, then newest; filter by category / search. */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PushpinFilled, NotificationOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel, withAllOption } from '../../lib/filterOptions';
import { POST_CATEGORIES, categoryLabel, categoryColor, fmtDate, personName, apiError } from './intranetShared';

const { t } = useI18n();
const loading = ref(true);
const rows = ref([]);
const page = ref(1);
const perPage = ref(12);
const total = ref(0);
const search = ref('');
const category = ref('');
const categoryOptions = computed(() => withAllOption(sortByLabel(POST_CATEGORIES.map(c => ({ value: c, label: categoryLabel(c) })))));

async function load(p = 1) {
  loading.value = true;
  try {
    const r = await http.get('intranet/news', { page: p, category: category.value || undefined, search: search.value || undefined });
    rows.value = r.data || [];
    page.value = r.current_page || 1;
    perPage.value = r.per_page || 12;
    total.value = r.total || 0;
  } catch (e) {
    message.error(apiError(e, t('InvalidData')));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.card-link { display: block; color: inherit; height: 100%; }
.news-card { height: 100%; }
.cover { height: 160px; background: #f5f5f5; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.cover img { width: 100%; height: 100%; object-fit: cover; }
.cover.empty { font-size: 42px; color: #bfbfbf; }
.meta { display: flex; gap: 4px; margin-bottom: 6px; }
.title { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
.summary { color: var(--ant-color-text-secondary, #595959); font-size: 13px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; min-height: 3em; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; margin-top: 6px; }
.pager { display: flex; justify-content: center; margin-top: 16px; }
</style>
