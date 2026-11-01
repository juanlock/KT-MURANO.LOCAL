<template>
  <div class="page">
    <PageHeader :title="es ? 'Manuales por empresa' : 'Company manuals'" :breadcrumb="[$t('Settings'), es ? 'Manuales por empresa' : 'Company manuals']" />

    <a-row :gutter="16">
      <a-col :xs="24" :lg="8">
        <a-card size="small" :body-style="{ padding: '8px' }">
          <a-segmented v-model:value="by" block :options="[{ value: 'company', label: es ? 'Empresas' : 'Companies' }, { value: 'type', label: es ? 'Tipos' : 'Types' }]" style="margin-bottom: 8px" />
          <a-input-search v-model:value="search" allow-clear size="small" :placeholder="es ? 'Buscar' : 'Search'" style="margin-bottom: 8px" />
          <a-spin :spinning="loading">
            <div class="cm-list">
              <div v-for="r in list" :key="r.key" class="cm-row" :class="{ active: r.type === type && (by === 'type' || r.key === picked) }" @click="pick(r)">
                <div class="cm-name">{{ r.name }}</div>
                <div v-if="by === 'company'" class="cm-sub">{{ typeLabel(r.type) }}</div>
              </div>
              <a-empty v-if="!list.length && !loading" />
            </div>
          </a-spin>
        </a-card>
      </a-col>

      <a-col :xs="24" :lg="16">
        <a-card size="small">
          <template #title>{{ title }}</template>
          <template #extra>
            <a-space wrap>
              <a-segmented v-model:value="doc" :options="[{ value: 'flyer', label: es ? 'Flyer (2 páginas)' : 'Flyer (2 pages)' }, { value: 'manual', label: es ? 'Manual textual' : 'Text manual' }]" />
              <a-segmented v-model:value="lang" :options="[{ value: 'es', label: 'ES' }, { value: 'en', label: 'EN' }]" />
              <a-button :href="url" target="_blank"><DownloadOutlined /> {{ es ? 'Abrir / descargar' : 'Open / download' }}</a-button>
            </a-space>
          </template>
          <iframe v-if="type" :key="url" :src="url" class="cm-frame" :title="title" />
          <a-empty v-else :description="es ? 'Elija una empresa o un tipo.' : 'Pick a company or a type.'" />
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Settings -> Company manuals (superadmin/AdminSystem, 2026-11-01): each company (or
 * business type) with its type's 2-page flyer and its textual manual, in Spanish or
 * English, shown here (the PDFs in public/manual, KT-TOOLS/Manual/build_type_pdfs.mjs).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const es = computed(() => ui.locale === 'es');

const loading = ref(false);
const companies = ref([]);
const types = ref([]);
const by = ref('company');
const search = ref('');
const picked = ref(null);
const type = ref(null);
const doc = ref('flyer');
const lang = ref(es.value ? 'es' : 'en');

const typeLabel = t => types.value.find(x => x.value === t)?.label || t;
const list = computed(() => {
  const q = search.value.trim().toLowerCase();
  const rows = by.value === 'company'
    ? companies.value.map(c => ({ key: 'c' + c.id, name: c.name, type: c.type }))
    : types.value.map(t => ({ key: 't' + t.value, name: t.label, type: t.value }));
  return rows.filter(r => !q || r.name.toLowerCase().includes(q) || typeLabel(r.type).toLowerCase().includes(q));
});
const title = computed(() => {
  if (!type.value) return es.value ? 'Manual' : 'Manual';
  const c = companies.value.find(x => 'c' + x.id === picked.value);
  return (by.value === 'company' && c ? `${c.name} · ` : '') + typeLabel(type.value);
});
const url = computed(() => (type.value ? `/manual/${doc.value === 'flyer' ? 'flyer' : 'murano'}-${type.value}-${lang.value}.pdf` : ''));

function pick(r) {
  picked.value = r.key;
  type.value = r.type;
}

onMounted(async () => {
  loading.value = true;
  try {
    const res = await http.get('company-manuals');
    companies.value = res.companies;
    types.value = res.types;
    if (res.companies.length) pick({ key: 'c' + res.companies[0].id, type: res.companies[0].type });
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.cm-list { max-height: calc(100vh - 260px); overflow: auto; }
.cm-row { padding: 7px 10px; border-radius: 6px; cursor: pointer; }
.cm-row:hover { background: rgba(128, 128, 128, 0.08); }
.cm-row.active { background: rgba(79, 70, 229, 0.12); }
.cm-name { font-weight: 600; }
.cm-sub { font-size: 12px; opacity: 0.6; }
.cm-frame { width: 100%; height: calc(100vh - 230px); min-height: 520px; border: 0; border-radius: 6px; background: #f4f6f8; }
</style>
