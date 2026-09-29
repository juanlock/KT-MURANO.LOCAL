<template>
  <div class="page">
    <PageHeader :title="page?.title || $t('Intranet_Pages')" :breadcrumb="[$t('Intranet'), $t('Intranet_Pages')]">
      <template #extra>
        <a-button @click="$router.push({ name: 'intranet-home' })"><template #icon><ArrowLeftOutlined /></template>{{ $t('Intranet_Back') }}</a-button>
      </template>
    </PageHeader>

    <a-row :gutter="16">
      <a-col :xs="24" :md="17" :xl="18">
        <a-spin :spinning="loading">
          <a-result v-if="notFound" status="404" :title="$t('Intranet_Nothing_Yet')" />
          <a-card v-else-if="page">
            <!-- eslint-disable-next-line vue/no-v-html -- purified server-side AND by DOMPurify -->
            <div class="rich ql-editor" v-html="safeHtml(page.content)"></div>
          </a-card>
        </a-spin>
      </a-col>
      <a-col :xs="24" :md="7" :xl="6">
        <a-card size="small" :title="$t('Intranet_Company_Pages')">
          <a-empty v-if="!nav.length" :description="$t('Intranet_Nothing_Yet')" />
          <div v-for="p in nav" :key="p.id" class="nav-item" :class="{ on: p.slug === route.params.slug }">
            <router-link :to="{ name: 'intranet-page', params: { slug: p.slug } }">{{ p.title }}</router-link>
          </div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/** Intranet -> static page by slug (intranet_view), with the pages navigation. */
import { ref, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeftOutlined } from '@ant-design/icons-vue';
import 'quill/dist/quill.snow.css';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { safeHtml } from './intranetShared';

const route = useRoute();
const page = ref(null);
const nav = ref([]);
const loading = ref(true);
const notFound = ref(false);

async function load() {
  loading.value = true;
  notFound.value = false;
  try {
    page.value = await http.get(`intranet/pages/${encodeURIComponent(route.params.slug)}`);
  } catch (e) {
    page.value = null;
    notFound.value = true;
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  try { nav.value = (await http.get('intranet/pages')).data || []; } catch (e) { nav.value = []; }
});
watch(() => route.params.slug, s => { if (s) load(); }, { immediate: true });
</script>

<style scoped>
.rich { padding: 0; height: auto; overflow: visible; }
.rich :deep(img) { max-width: 100%; height: auto; }
.nav-item { padding: 6px 8px; border-radius: 6px; }
.nav-item.on { background: rgba(22, 119, 255, 0.08); font-weight: 600; }
</style>
