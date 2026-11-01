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
      <!-- The 4 big cards: number + its rate + bar -->
      <div class="fd-grid4">
        <div v-for="k in bigCards" :key="k.key" class="fd-card4">
          <div class="fd-sub">{{ k.label }}</div>
          <div class="fd-big">{{ num(k.value) }}</div>
          <div class="fd-rate">
            <span>{{ k.rateLabel }}</span>
            <span :class="['fd-rate-v', k.bad ? 'bad' : 'good']">{{ k.rate }}% <RiseOutlined v-if="!k.bad" /></span>
          </div>
          <a-progress :percent="k.rate" :show-info="false" size="small" :stroke-color="k.color" />
        </div>
      </div>

      <!-- The 4 small cards: icon + value -->
      <div class="fd-grid4">
        <div v-for="k in smallCards" :key="k.key" class="fd-card4 fd-small-card" :style="k.to ? 'cursor: pointer' : ''" @click="k.to && router.push(k.to)">
          <span class="fd-icon" :style="{ background: k.color }"><component :is="k.icon" /></span>
          <div>
            <div class="fd-strong">{{ k.value }}</div>
            <div class="fd-muted">{{ k.label }}</div>
          </div>
        </div>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="14">
          <ReportChart
            :data="d.series" type="area" :height="300" :title="es ? 'Resumen de rendimiento' : 'Performance summary'"
            :fields="[
              { key: 'users', label: es ? 'Visitantes' : 'Users' },
              { key: 'starts', label: es ? 'Iniciados' : 'Starters' },
              { key: 'submissions', label: es ? 'Conversiones' : 'Conversions' },
            ]"
          />
        </a-col>
        <a-col :xs="24" :lg="10">
          <a-card size="small" :title="es ? 'Páginas de origen de los envíos' : 'Where submissions come from'" class="fd-card">
            <template #extra>
              <a-tooltip :title="es ? 'Easy Forms mostraba un mapa de ubicaciones, pero nunca tuvo geolocalización activa (todas vacías). Aquí: la página del sitio donde se llenó.' : 'Easy Forms showed a locations map, but geolocation was never active there (all empty). Here: the site page where it was filled.'">
                <InfoCircleOutlined class="fd-muted" />
              </a-tooltip>
            </template>
            <a-empty v-if="!d.pages.length" />
            <div v-for="p in d.pages" :key="p.page" class="fd-page">
              <span class="fd-page-name" :title="p.page">{{ p.page }}</span>
              <a-progress :percent="pct(p.submissions)" :format="() => p.submissions" size="small" style="flex: 0 0 150px; margin: 0" />
            </div>
          </a-card>
        </a-col>
      </a-row>

      <!-- Most visited / most started / most conversions / last updated -->
      <div class="fd-grid4 fd-lists">
        <a-card v-for="l in lists" :key="l.key" size="small" :title="l.title" class="fd-card">
          <a-empty v-if="!d[l.key].length" :image="simple" :description="es ? 'Sin datos' : 'No data'" />
          <div v-for="f in d[l.key]" :key="f.id" class="fd-row" @click="$router.push(`/forms/${f.id}/submissions`)">
            <div class="fd-row-main">
              <div class="fd-ellipsis">{{ f.name }}</div>
              <div v-if="isSystem" class="fd-muted fd-small">{{ f.company_name }}</div>
            </div>
            <b v-if="l.value">{{ num(f[l.value]) }}</b>
            <span v-else class="fd-muted fd-small">{{ ago(f.updated_at) }}</span>
          </div>
        </a-card>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="es ? 'Nuevos envíos' : 'New submissions'" class="fd-card">
            <a-empty v-if="!d.unread.length" :image="simple" :description="es ? 'No hay envíos nuevos.' : 'No new submissions.'" />
            <div v-for="s in d.unread" :key="s.id" class="fd-row" @click="openSub(s)">
              <span class="fd-dot" />
              <div class="fd-row-main">
                <div class="fd-ellipsis"><b>{{ s.ref }}</b> · {{ s.summary || '—' }}</div>
                <div class="fd-muted fd-small">{{ s.form }}<span v-if="isSystem"> · {{ s.company_name }}</span> · {{ fmt(s.created_at) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="es ? 'Últimos envíos' : 'Latest submissions'" class="fd-card">
            <a-empty v-if="!d.latest.length" :image="simple" />
            <div v-for="s in d.latest" :key="s.id" class="fd-row" @click="openSub(s)">
              <span class="fd-dot" :class="{ read: s.is_read }" />
              <div class="fd-row-main">
                <div class="fd-ellipsis"><b>{{ s.ref }}</b> · {{ s.summary || '—' }}</div>
                <div class="fd-muted fd-small">{{ s.form }}<span v-if="isSystem"> · {{ s.company_name }}</span> · {{ fmt(s.created_at) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small" :title="es ? 'Rendimiento por formulario' : 'Performance per form'" class="fd-card">
        <a-table :columns="formColumns" :data-source="d.forms" row-key="id" size="small" :pagination="{ pageSize: 10, hideOnSinglePage: true }" :scroll="{ x: 'max-content' }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
              <router-link :to="`/forms/${record.id}/submissions`">{{ record.name }}</router-link>
              <a-tag v-if="!record.status" class="fd-tag">{{ es ? 'Borrador' : 'Draft' }}</a-tag>
            </template>
            <template v-else-if="column.key === 'conversion'">
              <a-progress v-if="record.conversion !== null" :percent="record.conversion" size="small" stroke-color="#10b981" style="width: 120px; margin: 0" />
              <span v-else class="fd-muted">—</span>
            </template>
            <template v-else-if="column.key === 'unread'">
              <a-badge v-if="record.unread" :count="record.unread" :number-style="{ backgroundColor: '#1677ff' }" />
              <span v-else class="fd-muted">0</span>
            </template>
          </template>
        </a-table>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { PlusOutlined, RiseOutlined, EyeOutlined, CheckSquareOutlined, SendOutlined, ClockCircleOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import ReportChart from '../../components/ReportChart.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useAuthStore } from '../../stores/auth';
import { useSystemContext } from '../../lib/systemContext';

dayjs.extend(relativeTime);
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const { isSystem } = useSystemContext();
const simple = Empty.PRESENTED_IMAGE_SIMPLE;

const range = ref([dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const loading = ref(false);
const d = ref({ cards: {}, series: [], forms: [], most_visited: [], most_started: [], most_conversions: [], last_updated: [], unread: [], latest: [], pages: [] });
const fmt = x => (x ? dayjs(x).format('YYYY-MM-DD HH:mm') : '');
const ago = x => (x ? dayjs(x).fromNow() : '');
const num = n => Number(n || 0).toLocaleString();
const pct = n => { const max = Math.max(...d.value.pages.map(p => p.submissions), 1); return Math.round(n / max * 100); };
const duration = s => {
  s = Number(s || 0);
  if (!s) return '—';
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
  return [h ? `${h} h` : '', m ? `${m} min` : '', !h && r ? `${r} s` : ''].filter(Boolean).join(' ');
};

const bigCards = computed(() => {
  const c = d.value.cards || {};
  return [
    { key: 'users', label: es.value ? 'Visitantes únicos' : 'Unique users', value: c.users, rateLabel: es.value ? 'Tasa de inicio' : 'Starter rate', rate: c.starter_rate || 0, color: '#1677ff' },
    { key: 'starters', label: es.value ? 'Iniciaron el formulario' : 'Form starters', value: c.starters, rateLabel: es.value ? 'Tasa de finalización' : 'Completion rate', rate: c.completion_rate || 0, color: '#1677ff' },
    { key: 'conversions', label: es.value ? 'Conversiones' : 'Conversions', value: c.conversions, rateLabel: es.value ? 'Tasa de conversión' : 'Conversion rate', rate: c.conversion_rate || 0, color: '#10b981' },
    { key: 'abandons', label: es.value ? 'Abandonos' : 'Abandons', value: c.abandons, rateLabel: es.value ? 'Tasa de abandono' : 'Abandonment rate', rate: c.abandonment_rate || 0, color: '#ef4444', bad: true },
  ];
});
// Small cards (user's request, 2026-11-01): the submissions of each form in the
// period (e.g. contacts, complaints -- up to two, most used first), then read / unread.
const smallCards = computed(() => {
  const c = d.value.cards || {};
  const s = es.value ? 'envíos' : 'submissions';
  const perForm = (d.value.forms || []).slice(0, 2).map(f => ({
    key: 'form-' + f.id, icon: SendOutlined, color: '#1677ff', value: `${num(f.submissions)} ${s}`,
    label: f.name, to: `/forms/${f.id}/submissions`,
  }));
  return [
    ...perForm,
    { key: 'read', icon: CheckSquareOutlined, color: '#10b981', value: num(c.period_read), label: es.value ? 'Leídos' : 'Read' },
    { key: 'unread', icon: EyeOutlined, color: '#ef4444', value: num(c.period_unread), label: es.value ? 'Por leer' : 'Unread' },
  ];
});
const lists = computed(() => [
  { key: 'most_visited', title: es.value ? 'Más visitados' : 'Most visited forms', value: 'users' },
  { key: 'most_started', title: es.value ? 'Más iniciados' : 'Most starts', value: 'starts' },
  { key: 'most_conversions', title: es.value ? 'Más conversiones' : 'Most conversions', value: 'conversions' },
  { key: 'last_updated', title: es.value ? 'Actualizados' : 'Last updated', value: null },
]);

const formColumns = computed(() => [
  ...(isSystem.value ? [{ title: es.value ? 'Empresa' : 'Company', dataIndex: 'company_name', key: 'company', sorter: (a, b) => (a.company_name || '').localeCompare(b.company_name || '') }] : []),
  { title: es.value ? 'Formulario' : 'Form', key: 'name', sorter: (a, b) => a.name.localeCompare(b.name) },
  { title: es.value ? 'Visitantes' : 'Users', dataIndex: 'users', key: 'users', align: 'right', sorter: (a, b) => a.users - b.users },
  { title: es.value ? 'Iniciados' : 'Starters', dataIndex: 'starts', key: 'starts', align: 'right', sorter: (a, b) => a.starts - b.starts },
  { title: es.value ? 'Conversiones' : 'Conversions', dataIndex: 'conversions', key: 'conversions', align: 'right', sorter: (a, b) => a.conversions - b.conversions },
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
.fd-grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
@media (max-width: 1100px) { .fd-grid4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .fd-grid4 { grid-template-columns: 1fr; } }
.fd-card4 { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 10px; padding: 16px; background: rgba(128, 128, 128, 0.03); }
.fd-sub { font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.6; font-weight: 600; }
.fd-big { font-size: 30px; font-weight: 700; line-height: 1.25; margin: 6px 0 10px; }
.fd-rate { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 2px; }
.fd-rate-v.good { color: #10b981; font-weight: 600; }
.fd-rate-v.bad { color: #ef4444; font-weight: 600; }
.fd-small-card { display: flex; align-items: center; gap: 12px; padding: 12px 16px; }
.fd-icon { width: 40px; height: 40px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; flex: none; }
.fd-strong { font-weight: 600; }
.fd-card { margin-bottom: 16px; }
.fd-lists { margin-bottom: 0; }
.fd-tag { margin-inline-start: 6px; }
.fd-muted { opacity: 0.55; }
.fd-small { font-size: 12px; }
.fd-ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fd-row { display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 7px 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.12); cursor: pointer; }
.fd-row:last-child { border-bottom: 0; }
.fd-row:hover { background: rgba(128, 128, 128, 0.06); }
.fd-row-main { min-width: 0; flex: 1; }
.fd-dot { width: 8px; height: 8px; border-radius: 50%; background: #1677ff; flex: none; }
.fd-dot.read { background: rgba(128, 128, 128, 0.35); }
.fd-page { display: flex; align-items: center; gap: 12px; padding: 4px 0; }
.fd-page-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
