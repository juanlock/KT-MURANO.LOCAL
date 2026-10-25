<template>
  <div class="page">
    <PageHeader :title="es ? 'Panel de formularios' : 'Forms dashboard'" :breadcrumb="[$t('Forms_Module'), $t('Forms_Dashboard')]">
      <template #actions>
        <a-space>
          <a-button @click="$router.push('/forms')">{{ $t('Forms_All') }}</a-button>
          <a-button v-if="auth.can('forms_manage')" type="primary" @click="$router.push({ path: '/forms', query: { new: 1 } })">
            <template #icon><PlusOutlined /></template>{{ es ? 'Nuevo formulario' : 'New form' }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div class="fd-filter">
      <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" apply-default @change="load" />
    </div>

    <a-spin :spinning="loading">
      <div class="fd-kpis">
        <div v-for="k in kpis" :key="k.key" class="fd-kpi" :class="{ 'fd-kpi--link': k.to }" @click="k.to && $router.push(k.to)">
          <div class="fd-kpi-label">{{ k.label }}</div>
          <div class="fd-kpi-value">{{ k.value }}</div>
          <div v-if="k.hint" class="fd-kpi-hint">{{ k.hint }}</div>
        </div>
      </div>

      <ReportChart
        :data="d.series" type="area" :height="300" :title="es ? 'Por día' : 'Per day'"
        :fields="[
          { key: 'views', label: es ? 'Vistas' : 'Views' },
          { key: 'starts', label: es ? 'Iniciados' : 'Started' },
          { key: 'submissions', label: es ? 'Enviados' : 'Submissions' },
        ]"
      />
      <div class="fd-note">{{ es ? 'Vistas e iniciados se cuentan desde el ' : 'Views and starts are counted since ' }}{{ d.counting_since }}{{ es ? '; los envíos incluyen los importados.' : '; submissions include the imported ones.' }}</div>

      <a-card size="small" :title="es ? 'Rendimiento por formulario' : 'Performance per form'" class="fd-card">
        <a-table :columns="formColumns" :data-source="d.forms" row-key="id" size="small" :pagination="{ pageSize: 10, hideOnSinglePage: true }" :scroll="{ x: 'max-content' }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
              <router-link :to="`/forms/${record.id}/submissions`">{{ record.name }}</router-link>
              <a-tag v-if="!record.status" class="fd-tag">{{ es ? 'Borrador' : 'Draft' }}</a-tag>
            </template>
            <template v-else-if="column.key === 'conversion'">
              <template v-if="record.conversion !== null">
                <a-progress :percent="record.conversion" size="small" :stroke-color="'#10b981'" style="width: 120px; margin: 0" />
              </template>
              <span v-else class="fd-muted">—</span>
            </template>
            <template v-else-if="column.key === 'unread'">
              <a-badge v-if="record.unread" :count="record.unread" :number-style="{ backgroundColor: '#1677ff' }" />
              <span v-else class="fd-muted">0</span>
            </template>
          </template>
        </a-table>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="es ? 'Sin leer' : 'Unread'" class="fd-card">
            <a-empty v-if="!d.unread.length" :description="es ? 'Todo leído.' : 'All read.'" />
            <div v-for="s in d.unread" :key="s.id" class="fd-row" @click="openSub(s)">
              <span class="fd-dot" />
              <div class="fd-row-main">
                <div><b>{{ s.ref }}</b> · {{ s.summary || '—' }}</div>
                <div class="fd-muted fd-small">{{ s.form }}<span v-if="isSystem"> · {{ s.company_name }}</span> · {{ fmt(s.created_at) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="es ? 'Últimos envíos' : 'Latest submissions'" class="fd-card">
            <a-empty v-if="!d.latest.length" />
            <div v-for="s in d.latest" :key="s.id" class="fd-row" @click="openSub(s)">
              <span class="fd-dot" :class="{ read: s.is_read }" />
              <div class="fd-row-main">
                <div><b>{{ s.ref }}</b> · {{ s.summary || '—' }}</div>
                <div class="fd-muted fd-small">{{ s.form }}<span v-if="isSystem"> · {{ s.company_name }}</span> · {{ fmt(s.created_at) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small" :title="es ? 'Páginas desde donde llegan los envíos' : 'Pages the submissions come from'" class="fd-card">
        <a-empty v-if="!d.pages.length" />
        <div v-for="p in d.pages" :key="p.page" class="fd-page">
          <span class="fd-page-name">{{ p.page }}</span>
          <a-progress :percent="pct(p.submissions)" :format="() => p.submissions" size="small" style="flex: 0 0 200px; margin: 0" />
        </div>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import ReportChart from '../../components/ReportChart.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useSystemContext } from '../../lib/systemContext';
import { useAuthStore } from '../../stores/auth';
import { PlusOutlined } from '@ant-design/icons-vue';

const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const { isSystem } = useSystemContext();

const range = ref([dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const loading = ref(false);
const d = ref({ totals: {}, series: [], forms: [], unread: [], latest: [], pages: [], counting_since: '' });
const fmt = x => (x ? dayjs(x).format('YYYY-MM-DD HH:mm') : '');
const num = n => Number(n || 0).toLocaleString();
const pct = n => { const max = Math.max(...d.value.pages.map(p => p.submissions), 1); return Math.round(n / max * 100); };

const kpis = computed(() => {
  const t = d.value.totals || {};
  return [
    { key: 'views', label: es.value ? 'Vistas' : 'Views', value: num(t.views) },
    { key: 'starts', label: es.value ? 'Iniciados' : 'Started', value: num(t.starts) },
    { key: 'submissions', label: es.value ? 'Envíos' : 'Submissions', value: num(t.submissions) },
    { key: 'conversion', label: es.value ? 'Conversión' : 'Conversion', value: t.conversion === null || t.conversion === undefined ? '—' : `${t.conversion}%`, hint: es.value ? 'envíos / vistas' : 'submissions / views' },
    { key: 'unread', label: es.value ? 'Sin leer' : 'Unread', value: num(t.unread) },
    { key: 'forms', label: es.value ? 'Formularios' : 'Forms', value: `${num(t.published)} / ${num(t.forms)}`, hint: es.value ? 'publicados / total' : 'published / total', to: '/forms' },
  ];
});

const formColumns = computed(() => [
  ...(isSystem.value ? [{ title: es.value ? 'Empresa' : 'Company', dataIndex: 'company_name', key: 'company', sorter: (a, b) => (a.company_name || '').localeCompare(b.company_name || '') }] : []),
  { title: es.value ? 'Formulario' : 'Form', key: 'name', sorter: (a, b) => a.name.localeCompare(b.name) },
  { title: es.value ? 'Vistas' : 'Views', dataIndex: 'views', key: 'views', align: 'right', sorter: (a, b) => a.views - b.views },
  { title: es.value ? 'Iniciados' : 'Started', dataIndex: 'starts', key: 'starts', align: 'right', sorter: (a, b) => a.starts - b.starts },
  { title: es.value ? 'Envíos' : 'Submissions', dataIndex: 'submissions', key: 'submissions', align: 'right', sorter: (a, b) => a.submissions - b.submissions, defaultSortOrder: 'descend' },
  { title: es.value ? 'Conversión' : 'Conversion', key: 'conversion', sorter: (a, b) => (a.conversion ?? -1) - (b.conversion ?? -1) },
  { title: es.value ? 'Sin leer' : 'Unread', key: 'unread', align: 'center', sorter: (a, b) => a.unread - b.unread },
]);

async function load() {
  loading.value = true;
  try {
    d.value = await http.get('forms/dashboard', { from: range.value?.[0], to: range.value?.[1] });
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}

function openSub(s) {
  router.push({ path: `/forms/${s.form_id}/submissions`, query: { open: s.id } });
}

load();
</script>

<style scoped>
.fd-filter { margin-bottom: 16px; }
.fd-kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
@media (max-width: 1100px) { .fd-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 560px) { .fd-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.fd-kpi { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 10px; padding: 12px 14px; background: rgba(128, 128, 128, 0.03); }
.fd-kpi--link { cursor: pointer; }
.fd-kpi-label { font-size: 12.5px; opacity: 0.65; }
.fd-kpi-value { font-size: 24px; font-weight: 700; line-height: 1.3; }
.fd-kpi-hint { font-size: 11.5px; opacity: 0.5; }
.fd-note { font-size: 12px; opacity: 0.55; margin: -8px 0 16px; }
.fd-card { margin-bottom: 16px; }
.fd-tag { margin-inline-start: 6px; }
.fd-muted { opacity: 0.55; }
.fd-small { font-size: 12px; }
.fd-row { display: flex; gap: 10px; align-items: flex-start; padding: 7px 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.12); cursor: pointer; }
.fd-row:last-child { border-bottom: 0; }
.fd-row:hover { background: rgba(128, 128, 128, 0.06); }
.fd-row-main { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.fd-dot { width: 8px; height: 8px; border-radius: 50%; background: #1677ff; margin-top: 7px; flex: none; }
.fd-dot.read { background: rgba(128, 128, 128, 0.35); }
.fd-page { display: flex; align-items: center; gap: 12px; padding: 4px 0; }
.fd-page-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
