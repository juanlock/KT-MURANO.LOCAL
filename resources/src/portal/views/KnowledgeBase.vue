<template>
  <div class="card">
    <div class="card-header d-block">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mb-0">{{ $t('help_center') }}</h3>
        <form class="ms-auto d-print-none" @submit.prevent>
          <div class="input-icon">
            <span class="input-icon-addon"><i class="ti ti-search"></i></span>
            <input v-model="search" type="search" class="form-control" :placeholder="$t('search_articles')" @input="debounceFetch" />
          </div>
        </form>
      </div>
    </div>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_articles') }}</div>
    </div>

    <template v-else>
      <EmptyState v-if="!articles.length" icon="book-off" :title="$t('no_articles')" />

      <div v-else-if="search.trim()" class="list-group list-group-flush">
        <router-link v-for="a in articles" :key="a.id" :to="`/help/${a.slug}`" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
          <i class="ti ti-file-text fs-2 text-secondary"></i>
          <span class="flex-fill">
            <span class="d-block fw-medium">{{ a.title }}</span>
            <small v-if="a.group" class="text-secondary">{{ a.group.name }}</small>
          </span>
          <i class="ti ti-chevron-right text-secondary"></i>
        </router-link>
      </div>

      <div v-else class="card-body">
        <div v-for="g in nonEmptyGroups" :key="g.id" class="mb-4">
          <h3 class="mb-1">{{ g.name }}</h3>
          <p v-if="g.description" class="text-secondary mb-2">{{ g.description }}</p>
          <div class="list-group list-group-flush border rounded">
            <router-link v-for="a in articlesByGroup[g.id]" :key="a.id" :to="`/help/${a.slug}`" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
              <i class="ti ti-file-text fs-2 text-secondary"></i>
              <span class="flex-fill">{{ a.title }}</span>
              <i class="ti ti-chevron-right text-secondary"></i>
            </router-link>
          </div>
        </div>
        <div v-if="ungrouped.length" class="mb-2">
          <h3 class="mb-2">{{ $t('other_articles') }}</h3>
          <div class="list-group list-group-flush border rounded">
            <router-link v-for="a in ungrouped" :key="a.id" :to="`/help/${a.slug}`" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
              <i class="ti ti-file-text fs-2 text-secondary"></i>
              <span class="flex-fill">{{ a.title }}</span>
              <i class="ti ti-chevron-right text-secondary"></i>
            </router-link>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import EmptyState from '../components/EmptyState.vue';

export default {
  components: { EmptyState },
  data() { return { groups: [], articles: [], search: '', loading: false, debounce: null }; },
  computed: {
    articlesByGroup() {
      const map = {};
      for (const a of this.articles) {
        const gid = a.knowledge_base_article_group_id;
        if (!gid) continue;
        if (!map[gid]) map[gid] = [];
        map[gid].push(a);
      }
      return map;
    },
    nonEmptyGroups() { return this.groups.filter((g) => (this.articlesByGroup[g.id] || []).length > 0); },
    ungrouped() { return this.articles.filter((a) => !a.knowledge_base_article_group_id); },
  },
  mounted() { this.fetch(); },
  methods: {
    pageMeta() { return { title: this.$t('help_center'), pretitle: this.$t('nav_support'), crumbs: [] }; },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/knowledge-base', { params: { q: this.search || undefined } });
        this.groups = data.groups || [];
        this.articles = data.articles || [];
      } catch (_) {}
      this.loading = false;
    },
    debounceFetch() {
      clearTimeout(this.debounce);
      this.debounce = setTimeout(() => { this.fetch(); }, 300);
    },
  },
};
</script>
