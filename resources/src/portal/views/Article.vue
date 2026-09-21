<template>
  <div>
    <PageActions>
      <router-link to="/help" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_help') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_article') }}</div>
    </div>

    <article v-else-if="article.id" class="card card-lg mx-auto" style="max-width: 900px">
      <div class="card-body">
        <div v-if="article.group" class="rst-pretitle">{{ article.group.name }}</div>
        <h1 class="mb-1">{{ article.title }}</h1>
        <div v-if="article.updated_at" class="text-secondary small mb-4">{{ $t('updated_on', { date: formatDate(article.updated_at) }) }}</div>
        <div class="markdown" v-html="article.content || ''"></div>
      </div>
    </article>

    <div v-else class="card">
      <EmptyState icon="file-off" :title="$t('article_not_found')">
        <router-link to="/help" class="btn btn-primary">{{ $t('back_to_help') }}</router-link>
      </EmptyState>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import EmptyState from '../components/EmptyState.vue';

export default {
  components: { PageActions, EmptyState },
  data() { return { article: {}, loading: true }; },
  watch: { '$route.params.slug': { handler() { this.fetch(); }, immediate: false } },
  mounted() { this.fetch(); },
  methods: {
    pageMeta() {
      return { title: this.article.title || this.$t('help_center'), pretitle: this.$t('nav_support'), crumbs: [{ label: this.$t('help_center'), to: '/help' }] };
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get(`/portal/knowledge-base/${this.$route.params.slug}`);
        this.article = data || {};
      } catch (_) {
        this.article = {};
      }
      this.loading = false;
      this.applyPage();
    },
    formatDate(iso) {
      if (!iso) return '';
      try { return new Date(iso).toLocaleDateString(); } catch (_) { return iso; }
    },
  },
};
</script>
