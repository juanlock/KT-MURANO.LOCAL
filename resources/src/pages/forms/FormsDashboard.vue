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

    <!-- Tabs (user's request, 2026-11-10): one screen per subject instead of one long page. -->
    <a-spin :spinning="loading">
      <a-tabs v-model:activeKey="tab" class="fd-tabs" @change="saveTab">
        <template #rightExtra>
          <PresetRangeFilter v-model:value="range" value-format="YYYY-MM-DD" apply-default compact @change="load" />
        </template>

        <!-- Contacts (2026-11-23): the website contact forms as a first contact -- by date and by topic, still unread,
             and which topics have an approved automatic reply. Never mixed with the complaints. -->
        <a-tab-pane key="contacts" :tab="es ? 'Contactos' : 'Contacts'">
          <a-empty v-if="!(cx.forms || []).length" :image="simple"
            :description="es ? 'No hay formularios de contacto. Márquelo como Contacto en la configuración del formulario.' : 'No contact forms. Mark one as Contact in the form settings.'" />
          <template v-else>
            <div class="fd-group-filters">
              <a-select v-if="cx.forms.length > 1" v-model:value="contactForm" :options="contactFormOptions" allow-clear style="width: 320px; max-width: 100%"
                :placeholder="es ? 'Todos los formularios de contacto' : 'Every contact form'" @change="loadContacts" />
              <span v-else class="fd-strong">{{ cx.forms[0].name }}</span>
              <a v-if="contactOne" class="fd-small" @click="router.push(`/forms/${contactOne.id}/edit`)">
                {{ es ? 'Respuestas automáticas' : 'Automatic replies' }}: {{ contactOne.replies_active }}/{{ contactOne.replies }} {{ es ? 'activas' : 'on' }}
              </a>
            </div>
            <div class="fd-grid4">
              <div v-for="k in contactCards" :key="k.key" class="fd-card4 fd-small-card">
                <span class="fd-icon" :style="{ background: k.color }"><component :is="k.icon" /></span>
                <div class="fd-row-main"><div class="fd-strong">{{ k.value }}</div><div class="fd-muted fd-ellipsis">{{ k.label }}</div></div>
              </div>
            </div>
            <a-row :gutter="16">
              <a-col :xs="24" :lg="9">
                <a-card size="small" class="fd-card fd-h-blue" :title="es ? 'Por motivo' : 'By topic'">
                  <a-empty v-if="!cx.by_topic.length" :image="simple" :description="es ? 'Sin mensajes en el período' : 'No messages in the period'" />
                  <div v-for="t in cx.by_topic" :key="t.topic" class="fd-hot">
                    <span class="fd-hot-name">{{ t.topic === '—' ? (es ? 'Sin motivo' : 'No topic') : t.topic }}</span>
                    <a-tooltip v-if="t.reply" :title="t.reply === 'active' ? (es ? 'Respuesta automática activa' : 'Automatic reply on') : (es ? 'Respuesta sugerida: revisar y activar en el formulario' : 'Suggested reply: review and turn on in the form')">
                      <a-tag :color="t.reply === 'active' ? 'green' : 'gold'" class="fd-tag">{{ t.reply === 'active' ? (es ? 'Auto' : 'Auto') : (es ? 'Sugerida' : 'Draft') }}</a-tag>
                    </a-tooltip>
                    <a-progress :percent="t.percent" :format="() => t.count" size="small" style="flex: 0 0 40%; margin: 0" />
                  </div>
                </a-card>
              </a-col>
              <a-col :xs="24" :lg="15">
                <ReportChart :data="cx.series" type="bar" :height="170" :title="es ? 'Mensajes por día' : 'Messages per day'" :fields="[{ key: 'messages', label: es ? 'Mensajes' : 'Messages' }]" />
                <a-card size="small" class="fd-card fd-h-green" :title="es ? 'Últimos mensajes' : 'Latest messages'">
                  <div class="fd-scroll">
                    <a-empty v-if="!cx.latest.length" :image="simple" :description="es ? 'Sin datos' : 'No data'" />
                    <!-- One line per message, grouped by form (user, 2026-11-23). -->
                    <template v-for="g in latestByForm" :key="g.key">
                      <div class="fd-lm-head">{{ g.form }}<span v-if="isSystem && g.company"> · {{ g.company }}</span> <span class="fd-muted">({{ g.items.length }})</span></div>
                      <div v-for="s in g.items" :key="s.id" class="fd-lm" @click="openSub(s, cx.latest)">
                        <span class="fd-dot" :class="{ read: s.is_read }" />
                        <b class="fd-lm-ref">{{ s.ref }}</b>
                        <span class="fd-muted fd-lm-date">{{ shortDate(s.created_at) }}</span>
                        <span class="fd-lm-name">{{ s.name || '—' }}</span>
                        <a-tag class="fd-tag fd-lm-topic">{{ s.topic === '—' ? (es ? 'Sin motivo' : 'No topic') : s.topic }}</a-tag>
                        <span class="fd-lm-msg" :title="s.message">{{ s.message || '—' }}</span>
                      </div>
                    </template>
                  </div>
                </a-card>
              </a-col>
            </a-row>
          </template>
        </a-tab-pane>

        <!-- Analysis: one form's submissions grouped by an answer, and where they come from
             (agency, route) when the form asks it (e.g. the complaints book). -->
        <a-tab-pane key="analysis" :tab="es ? 'Reclamos' : 'Complaints'">
          <div class="fd-group-filters">
            <a-select v-model:value="groupForm" :options="groupFormOptions" style="width: 300px; max-width: 100%" @change="groupField = undefined; loadGroup()" />
            <a-select v-model:value="groupField" :options="groupFieldOptions" style="width: 260px; max-width: 100%" :placeholder="es ? 'Agrupar por' : 'Group by'" @change="loadGroup" />
            <span class="fd-muted">{{ num(group.total) }} {{ es ? 'envíos en el período' : 'submissions in the period' }}</span>
          </div>
          <!-- Left 35 %: grouped, then locations and routes; right: the latest, in columns (user, 2026-11-23). -->
          <div class="fd-cl">
            <div class="fd-cl-left">
              <a-card size="small" :title="es ? 'Envíos agrupados' : 'Grouped submissions'" class="fd-card fd-h-blue">
                <a-empty v-if="!groupLoading && !group.fields.length" :image="simple"
                  :description="es ? 'Este formulario no tiene preguntas de opciones para agrupar.' : 'This form has no choice questions to group by.'" />
                <a-table v-else :columns="groupColumns" :data-source="group.rows" row-key="value" size="small" :loading="groupLoading"
                  :pagination="{ pageSize: groupPageSize, hideOnSinglePage: true, size: 'small' }" :custom-row="groupRow">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'percent'">
                      <a-progress :percent="record.percent" size="small" style="margin: 0; min-width: 70px" />
                    </template>
                  </template>
                </a-table>
              </a-card>
              <a-card v-if="hot.has_agency" size="small" class="fd-card fd-h-red" :title="(es ? 'Locales con más ' : 'Locations with most ') + groupFormName">
                <div class="fd-scroll fd-scroll-short">
                  <a-empty v-if="!hot.agencies.length" :image="simple" :description="es ? 'Sin datos' : 'No data'" />
                  <div v-for="(r, i) in hot.agencies" :key="r.value" class="fd-hot">
                    <span class="fd-rank">{{ i + 1 }}</span><span class="fd-hot-name">{{ r.value }}</span>
                    <a-progress :percent="r.percent" :format="() => r.count" size="small" status="exception" style="flex: 0 0 45%; margin: 0" />
                  </div>
                </div>
              </a-card>
              <a-card v-if="hot.has_route" size="small" class="fd-card fd-h-purple" :title="(es ? 'Rutas con más ' : 'Routes with most ') + groupFormName">
                <div class="fd-scroll fd-scroll-short">
                  <a-empty v-if="!hot.routes.length" :image="simple" :description="es ? 'Sin datos todavía: el formulario ya pregunta origen y destino.' : 'No data yet: the form now asks origin and destination.'" />
                  <div v-for="(r, i) in hot.routes" :key="r.value" class="fd-hot">
                    <span class="fd-rank">{{ i + 1 }}</span><span class="fd-hot-name">{{ r.value }}</span>
                    <a-progress :percent="r.percent" :format="() => r.count" size="small" status="exception" style="flex: 0 0 45%; margin: 0" />
                  </div>
                </div>
              </a-card>
            </div>
            <div class="fd-cl-right">
              <a-card size="small" class="fd-card fd-h-green" :title="(es ? 'Últimos ' : 'Latest ') + groupFormName">
                <a-empty v-if="!(group.latest || []).length" :image="simple" :description="es ? 'Sin datos' : 'No data'" />
                <div v-else class="fd-scroll fd-scroll-tall">
                  <div class="fd-lt fd-lt-head">
                    <span /><span>N°</span><span>{{ es ? 'Fecha' : 'Date' }}</span><span>{{ es ? 'Nombre' : 'Name' }}</span>
                    <span>{{ groupFieldLabel || (es ? 'Tipo' : 'Type') }}</span><span>{{ es ? 'Agencia / ruta' : 'Agency / route' }}</span><span>{{ es ? 'Detalle' : 'Detail' }}</span>
                  </div>
                  <div v-for="s in group.latest" :key="s.id" class="fd-lt" @click="openSub(s, group.latest)">
                    <span class="fd-dot" :class="{ read: s.is_read }" />
                    <b>{{ s.ref }}</b>
                    <span class="fd-muted">{{ shortDate(s.created_at) }}</span>
                    <span :title="s.name">{{ s.name || '—' }}</span>
                    <span :title="s.topic">{{ s.topic }}</span>
                    <span class="fd-muted" :title="s.where">{{ s.where || '—' }}</span>
                    <span class="fd-lt-msg" :title="s.message">{{ s.message || '—' }}</span>
                  </div>
                </div>
              </a-card>
            </div>
          </div>
        </a-tab-pane>


        <!-- Web traffic: visits, starts and conversions of every form (formerly "Summary"). -->
        <a-tab-pane key="summary" :tab="es ? 'Tráfico web' : 'Web traffic'">
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
          <div class="fd-grid4">
            <div v-for="k in smallCards" :key="k.key" class="fd-card4 fd-small-card" :style="k.to ? 'cursor: pointer' : ''" @click="k.to && router.push(k.to)">
              <span class="fd-icon" :style="{ background: k.color }"><component :is="k.icon" /></span>
              <div class="fd-row-main">
                <div class="fd-strong">{{ k.value }}</div>
                <div class="fd-muted fd-ellipsis">{{ k.label }}</div>
              </div>
            </div>
          </div>
          <ReportChart
            :data="d.series" type="area" :height="190" :title="es ? 'Resumen de rendimiento' : 'Performance summary'"
            :fields="[
              { key: 'users', label: es ? 'Visitantes' : 'Users' },
              { key: 'starts', label: es ? 'Iniciados' : 'Starters' },
              { key: 'submissions', label: es ? 'Conversiones' : 'Conversions' },
            ]"
          />
        </a-tab-pane>

        <!-- Forms: rankings and the performance of each one -->
        <a-tab-pane key="forms" :tab="es ? 'Formularios' : 'Forms'">
          <div class="fd-grid4">
            <a-card v-for="l in lists" :key="l.key" size="small" :title="l.title" class="fd-card fd-list-card" :class="'fd-h-' + ['blue', 'green', 'purple', 'orange'][lists.indexOf(l) % 4]">
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
          <a-card size="small" :title="es ? 'Rendimiento por formulario' : 'Performance per form'" class="fd-card fd-h-teal">
            <a-table :columns="formColumns" :data-source="d.forms" row-key="id" size="small" :pagination="{ pageSize: 6, hideOnSinglePage: true, size: 'small' }" :scroll="{ x: 'max-content' }">
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
        </a-tab-pane>
      </a-tabs>
    </a-spin>

    <a-modal v-model:open="rowModal.open" :footer="null" width="98vw" :style="{ top: '12px', maxWidth: '98vw' }"
      :title="`${groupFieldLabel}: ${rowModal.value} (${rowModal.loading ? '…' : rowModal.items.length})`">
      <a-table :columns="rowColumns" :data-source="rowModal.items" row-key="id" size="small" :loading="rowModal.loading"
        :pagination="{ pageSize: 10, hideOnSinglePage: true, size: 'small' }"
        :custom-row="r => ({ style: 'cursor: pointer', onClick: () => openSub(r, rowModal.items) })">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'ref'">
            <span class="fd-dot" :class="{ read: record.is_read }" style="margin-right: 6px" /><b>{{ record.ref }}</b>
          </template>
          <template v-else-if="column.key === 'created_at'">{{ fmt(record.created_at) }}</template>
          <template v-else-if="column.key === 'message'"><div style="white-space: pre-line">{{ record.message || '—' }}</div></template>
        </template>
      </a-table>
    </a-modal>

    <a-modal v-model:open="sub.open" :footer="null" width="min(1100px, 96vw)" :style="{ top: '24px' }"
      :title="sub.s ? `${es ? 'Envío' : 'Submission'} ${sub.s.ref}` : (es ? 'Envío' : 'Submission')">
      <a-spin :spinning="sub.loading">
        <div class="fd-sub-nav">
          <a-button :disabled="sub.idx <= 0" @click="showSub(sub.idx - 1)">‹ {{ es ? 'Anterior' : 'Previous' }}</a-button>
          <span class="fd-muted">{{ sub.idx + 1 }} / {{ sub.list.length }}<span v-if="sub.s"> · {{ fmt(sub.s.created_at) }}</span></span>
          <a-button :disabled="sub.idx >= sub.list.length - 1" @click="showSub(sub.idx + 1)">{{ es ? 'Siguiente' : 'Next' }} ›</a-button>
        </div>
        <template v-if="sub.s">
          <a-alert v-if="sub.s.attended_at" type="success" style="margin-bottom: 8px; padding: 4px 10px; white-space: pre-wrap"
            :message="(es ? 'Atendido ' : 'Attended ') + fmt(sub.s.attended_at) + (sub.s.attended_by_name ? ' · ' + sub.s.attended_by_name : '') + (sub.s.attended_note ? ' — ' + sub.s.attended_note : '')" />
          <div class="fd-sub-grid">
            <template v-for="(x, i) in subItems" :key="x.key || 'h' + i">
              <div v-if="x.heading" class="fd-sub-section">{{ x.heading }}</div>
              <div v-else class="fd-sub-cell" :class="{ full: x.width === 'full' || subByKey[x.key]?.type === 'textarea' || x.extra }">
                <div class="fd-sub-label">{{ (subByKey[x.key]?.label || x.key).replace(/:$/, '') }}</div>
                <div class="fd-sub-value">{{ subText(sub.s.data[x.key]) || '—' }}</div>
              </div>
            </template>
          </div>
          <div style="margin-top: 10px; text-align: right">
            <a @click="openSubPage">{{ es ? 'Abrir en Envíos (atender, responder, imprimir)' : 'Open in Submissions (attend, reply, print)' }} ›</a>
          </div>
        </template>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { PlusOutlined, RiseOutlined, EyeOutlined, CheckSquareOutlined, SendOutlined, ClockCircleOutlined, InfoCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/es';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import ReportChart from '../../components/ReportChart.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { useAuthStore } from '../../stores/auth';
import { useSystemContext } from '../../lib/systemContext';
import { useFormat } from '../../composables/useFormat';

dayjs.extend(relativeTime);
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const es = computed(() => ui.locale === 'es');
const { isSystem } = useSystemContext();
const simple = Empty.PRESENTED_IMAGE_SIMPLE;

// The tab this person left open (only a convenience: a blocked storage just opens Summary).
const TAB_KEY = 'forms-dashboard-tab';
const tab = ref((() => { try { const t = localStorage.getItem(TAB_KEY); return ['contacts', 'analysis', 'summary', 'forms'].includes(t) ? t : 'contacts'; } catch (e) { return 'contacts'; } })());
function saveTab(k) { try { localStorage.setItem(TAB_KEY, k); } catch (e) { /* ignore */ } }

const range = ref([dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')]);
const loading = ref(false);
const d = ref({ cards: {}, series: [], forms: [], most_visited: [], most_started: [], most_conversions: [], last_updated: [], unread: [], latest: [], pages: [] });
const fmt = x => (x ? dayjs(x).format('YYYY-MM-DD HH:mm') : '');
// "hace 21 días" in Spanish screens (2026-11-23: it showed "21 days ago").
const ago = x => (x ? dayjs(x).locale(es.value ? 'es' : 'en').fromNow() : '');
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
// The submissions report filtered by read / unread, same dates as the dashboard.
const reportLink = read => ({ path: '/forms/report', query: { read, from: range.value?.[0] || d.value.from, to: range.value?.[1] || d.value.to } });
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
    { key: 'read', icon: CheckSquareOutlined, color: '#10b981', value: num(c.period_read), label: es.value ? 'Leídos' : 'Read', to: reportLink('1') },
    { key: 'unread', icon: EyeOutlined, color: '#ef4444', value: num(c.period_unread), label: es.value ? 'Por leer' : 'Unread', to: reportLink('0') },
  ];
});
const groupPageSize = 5; // the latest complaints take the lower half (2026-11-23)
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
    // Analysis: the complaints book when there is one (it has answers to group by), else the
    // form with the most submissions -- unless one was picked.
    if (!groupForm.value || !complaintForms.value.some(f => f.id === groupForm.value)) {
      groupForm.value = complaintForms.value[0]?.id;
    }
    loadGroup();
    loadContacts();
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    loading.value = false;
  }
}

// ---- grouped submissions
const groupForm = ref(undefined);
const groupField = ref(undefined);
const groupLoading = ref(false);
const group = ref({ fields: [], rows: [], total: 0 });
// Complaints (2026-11-23): only the forms marked as a complaints book; every form when there is none.
const complaintForms = computed(() => { const c = (d.value.forms || []).filter(f => f.kind === 'complaints'); return c.length ? c : (d.value.forms || []); });
const groupFormOptions = computed(() => complaintForms.value.map(f => ({ value: f.id, label: f.name })));
// A generic question label ("Seleccione una opción") reads as what it groups (user, 2026-11-23: "Tipo de reclamo").
const fieldLabel = f => (!f ? '' : /^(seleccione|elija|escoja) una opci|^choose an option/i.test(f.label || '') ? (es.value ? 'Tipo de reclamo' : 'Complaint type') : f.label);
const groupFieldOptions = computed(() => group.value.fields.map(f => ({ value: f.key, label: fieldLabel(f) })));
const groupColumns = computed(() => [
  { title: fieldLabel(group.value.fields.find(f => f.key === groupField.value)) || (es.value ? 'Valor' : 'Value'), dataIndex: 'value', key: 'value', ellipsis: true },
  { title: es.value ? 'Envíos' : 'Submissions', dataIndex: 'count', key: 'count', align: 'right', width: 80 },
  { title: '%', key: 'percent', width: 130 },
]);
// Hotspots of the selected form: by agency and by route.
const hot = ref({ has_agency: false, has_route: false, agencies: [], routes: [] });
const groupFormName = computed(() => {
  const n = (d.value.forms || []).find(f => f.id === groupForm.value)?.name || '';
  return /reclam|queja|complaint/i.test(n) ? (es.value ? 'reclamos' : 'complaints') : (es.value ? 'envíos' : 'submissions');
});
async function loadHot() {
  if (!groupForm.value) return;
  try {
    hot.value = await http.get(`forms/${groupForm.value}/hotspots`, { from: range.value?.[0], to: range.value?.[1] });
  } catch (e) { hot.value = { has_agency: false, has_route: false, agencies: [], routes: [] }; }
}

async function loadGroup() {
  loadHot();
  if (!groupForm.value) { group.value = { fields: [], rows: [], total: 0 }; return; }
  groupLoading.value = true;
  try {
    group.value = await http.get(`forms/${groupForm.value}/group`, { field: groupField.value, from: range.value?.[0], to: range.value?.[1] });
    groupField.value = group.value.field || undefined;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    groupLoading.value = false;
  }
}

// ---- contacts (2026-11-23)
const cx = ref({ forms: [], by_topic: [], series: [], latest: [], total: 0, unread: 0, visits: 0 });
const contactForm = ref(undefined);
const contactFormOptions = computed(() => (cx.value.forms || []).map(f => ({ value: f.id, label: f.name + (isSystem.value && f.company_name ? ' · ' + f.company_name : '') })));
const contactOne = computed(() => (cx.value.forms || []).find(f => f.id === contactForm.value) || ((cx.value.forms || []).length === 1 ? cx.value.forms[0] : null));
const contactCards = computed(() => {
  const c = cx.value;
  const conv = c.visits ? Math.round(Math.min(100, (c.total * 100) / c.visits)) : null;
  return [
    { key: 'msgs', icon: SendOutlined, color: '#1677ff', value: num(c.total), label: es.value ? 'Mensajes en el período' : 'Messages in the period' },
    { key: 'unread', icon: EyeOutlined, color: '#ef4444', value: num(c.unread), label: es.value ? 'Sin leer' : 'Unread' },
    { key: 'visits', icon: RiseOutlined, color: '#8b5cf6', value: num(c.visits), label: es.value ? 'Visitantes del formulario' : 'Form visitors' },
    { key: 'conv', icon: CheckSquareOutlined, color: '#10b981', value: conv === null ? '—' : conv + ' %', label: es.value ? 'Visitantes que escribieron' : 'Visitors who wrote' },
  ];
});
const latestByForm = computed(() => {
  const m = new Map();
  for (const s of cx.value.latest || []) {
    if (!m.has(s.form_id)) m.set(s.form_id, { key: s.form_id, form: s.form, company: s.company_name, items: [] });
    m.get(s.form_id).items.push(s);
  }
  return [...m.values()];
});
const { dayMonthTime } = useFormat();
const shortDate = x => (x ? dayMonthTime(x) : '');
async function loadContacts() {
  try {
    cx.value = await http.get('forms/contacts', { form_id: contactForm.value || '', from: range.value?.[0], to: range.value?.[1] });
  } catch (e) { /* the tab shows empty */ }
}

// A submission opens in a window ON the dashboard (user, 2026-11-23: "que no me envíe a otro formulario, sino se pierde
// el objetivo de investigar en el panel"); Previous / Next walk the list it was opened from.
const sub = ref({ open: false, loading: false, list: [], idx: 0, s: null, fields: [], layout: [] });
const subByKey = computed(() => Object.fromEntries((sub.value.fields || []).map(f => [f.key, f])));
const subItems = computed(() => {
  const s = sub.value.s;
  if (!s) return [];
  const known = new Set((sub.value.fields || []).map(f => f.key));
  const rows = (sub.value.layout || []).filter(x => x.heading || known.has(x.key));
  const extra = Object.keys(s.data || {}).filter(k => !known.has(k) && !k.startsWith('_')).map(k => ({ key: k, extra: true, width: 'full' }));
  return [...rows, ...extra];
});
const subText = v => (v === null || v === undefined ? '' : Array.isArray(v) ? v.join(', ') : typeof v === 'object' ? (v.name || JSON.stringify(v)) : String(v));
async function showSub(i) {
  const row = sub.value.list[i];
  if (!row) return;
  sub.value.idx = i;
  sub.value.loading = true;
  try {
    const r = await http.get(`forms/submissions/${row.id}`);
    sub.value.s = r.submission;
    sub.value.fields = r.fields || [];
    sub.value.layout = r.layout || [];
    row.is_read = true;
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    sub.value.loading = false;
  }
}
function openSub(s, list) {
  const l = list && list.length ? list : [s];
  sub.value = { open: true, loading: true, list: l, idx: 0, s: null, fields: [], layout: [] };
  showSub(Math.max(0, l.findIndex(x => x.id === s.id)));
}
function openSubPage() {
  const s = sub.value.s;
  if (s) router.push({ path: `/forms/${s.form_id}/submissions`, query: { open: s.id } });
}

// ---- a grouped row -> its submissions in a modal (user, 2026-11-23)
const rowModal = ref({ open: false, loading: false, value: '', items: [] });
const groupRow = record => ({ style: 'cursor: pointer', onClick: () => openGroupRow(record) });
async function openGroupRow(record) {
  rowModal.value = { open: true, loading: true, value: record.value, items: [] };
  try {
    const r = await http.get(`forms/${groupForm.value}/group`, { field: groupField.value, value: record.value, from: range.value?.[0], to: range.value?.[1] });
    rowModal.value.items = r.items || [];
  } catch (e) {
    message.error(e?.data?.message || e.message);
  } finally {
    rowModal.value.loading = false;
  }
}
const rowColumns = computed(() => [
  { title: 'N°', dataIndex: 'ref', key: 'ref', width: 90 },
  { title: es.value ? 'Fecha' : 'Date', dataIndex: 'created_at', key: 'created_at', width: 140 },
  { title: es.value ? 'Nombre' : 'Name', dataIndex: 'name', key: 'name', width: 180, ellipsis: true },
  ...(rowModal.value.items.some(i => i.where) ? [{ title: es.value ? 'Agencia / ruta' : 'Agency / route', dataIndex: 'where', key: 'where', width: 220, ellipsis: true }] : []),
  { title: es.value ? 'Detalle' : 'Detail', dataIndex: 'message', key: 'message' },
]);
const groupFieldLabel = computed(() => fieldLabel(group.value.fields.find(f => f.key === groupField.value)));

load();
</script>

<style scoped>
.fd-tabs :deep(.ant-tabs-nav) { margin-bottom: 12px; }
.fd-tab-badge { margin-inline-start: 6px; }
.fd-tab-badge :deep(.ant-badge-count) { box-shadow: none; }
/* Long lists scroll inside their card: the page itself stays on one screen. */
.fd-scroll { max-height: calc(100vh - 330px); min-height: 160px; overflow-y: auto; }
.fd-list-card :deep(.ant-card-body) { max-height: 230px; overflow-y: auto; }
.fd-grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
@media (max-width: 1100px) { .fd-grid4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .fd-grid4 { grid-template-columns: 1fr; } }
.fd-card4 { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 10px; padding: 12px 16px; background: rgba(128, 128, 128, 0.03); min-width: 0; }
.fd-sub { font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.6; font-weight: 600; }
.fd-big { font-size: 26px; font-weight: 700; line-height: 1.2; margin: 4px 0 6px; }
.fd-rate { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 2px; }
.fd-rate-v.good { color: #10b981; font-weight: 600; }
.fd-rate-v.bad { color: #ef4444; font-weight: 600; }
.fd-small-card { display: flex; align-items: center; gap: 12px; padding: 10px 16px; }
.fd-icon { width: 40px; height: 40px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; flex: none; }
.fd-strong { font-weight: 600; }
.fd-card { margin-bottom: 12px; }
.fd-lm-head { font-weight: 600; font-size: 12px; padding: 6px 4px 2px; border-bottom: 1px solid #f0f0f0; position: sticky; top: 0; background: var(--ant-color-bg-container, #fff); z-index: 1; }
.fd-lm { display: flex; align-items: center; gap: 8px; padding: 3px 4px; cursor: pointer; white-space: nowrap; font-size: 13px; }
.fd-lm:nth-child(even) { background: rgba(0, 0, 0, 0.02); }
.fd-lm:hover { background: rgba(22, 119, 255, 0.08); }
.fd-lm-ref { flex: 0 0 auto; min-width: 44px; }
.fd-lm-date { flex: 0 0 auto; font-size: 12px; }
.fd-lm-name { flex: 0 1 160px; min-width: 60px; overflow: hidden; text-overflow: ellipsis; }
.fd-lm-topic { flex: 0 0 auto; margin: 0; }
.fd-lm-where { flex: 0 1 200px; min-width: 0; overflow: hidden; text-overflow: ellipsis; font-size: 12px; }
.fd-h-blue :deep(.ant-card-head) { background: #e6f4ff; color: #0958d9; }
.fd-h-green :deep(.ant-card-head) { background: #f6ffed; color: #389e0d; }
.fd-h-red :deep(.ant-card-head) { background: #fff1f0; color: #cf1322; }
.fd-h-purple :deep(.ant-card-head) { background: #f9f0ff; color: #531dab; }
.fd-h-orange :deep(.ant-card-head) { background: #fff7e6; color: #d46b08; }
.fd-h-teal :deep(.ant-card-head) { background: #e6fffb; color: #08979c; }
.fd-cl { display: flex; gap: 16px; align-items: flex-start; }
.fd-cl-left { flex: 0 0 35%; min-width: 0; }
.fd-cl-right { flex: 1 1 auto; min-width: 0; }
@media (max-width: 991px) { .fd-h-blue :deep(.ant-card-head) { background: #e6f4ff; color: #0958d9; }
.fd-h-green :deep(.ant-card-head) { background: #f6ffed; color: #389e0d; }
.fd-h-red :deep(.ant-card-head) { background: #fff1f0; color: #cf1322; }
.fd-h-purple :deep(.ant-card-head) { background: #f9f0ff; color: #531dab; }
.fd-h-orange :deep(.ant-card-head) { background: #fff7e6; color: #d46b08; }
.fd-h-teal :deep(.ant-card-head) { background: #e6fffb; color: #08979c; }
.fd-cl { flex-direction: column; } .fd-cl-left { flex-basis: auto; width: 100%; } .fd-cl-right { width: 100%; } }
.fd-scroll-short { max-height: 150px; min-height: 0; }
.fd-scroll-tall { max-height: calc(100vh - 290px); min-height: 300px; }
.fd-lt { display: grid; grid-template-columns: 12px 42px 78px minmax(80px, 1fr) minmax(80px, 1fr) minmax(80px, 0.9fr) minmax(140px, 2.6fr);
  gap: 10px; align-items: center; padding: 3px 6px; cursor: pointer; font-size: 13px; }
.fd-lt > span, .fd-lt > b { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.fd-lt:nth-child(odd):not(.fd-lt-head) { background: rgba(0, 0, 0, 0.025); }
.fd-lt:not(.fd-lt-head):hover { background: rgba(22, 119, 255, 0.08); }
.fd-lt-head { position: sticky; top: 0; z-index: 1; cursor: default; font-size: 12px; font-weight: 600; color: #8c8c8c;
  background: var(--ant-color-bg-container, #fff); border-bottom: 1px solid #f0f0f0; }
.fd-lt-msg { color: #595959; }
@media (max-width: 768px) { .fd-lt { grid-template-columns: 14px 56px 90px 1fr; } .fd-lt > :nth-child(n+5) { display: none; } }
.fd-lm-msg { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; color: #595959; }
.fd-sub-nav { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.fd-sub-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 16px; }
.fd-sub-cell.full, .fd-sub-section { grid-column: 1 / -1; }
.fd-sub-section { font-weight: 600; border-bottom: 1px solid #f0f0f0; padding-top: 6px; }
.fd-sub-label { font-size: 12px; color: #8c8c8c; }
.fd-sub-value { white-space: pre-line; word-break: break-word; }
@media (max-width: 640px) { .fd-sub-grid { grid-template-columns: 1fr; } }
.fd-group-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; }
.fd-hot { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
.fd-rank { width: 22px; height: 22px; border-radius: 50%; background: rgba(239, 68, 68, 0.12); color: #dc2626; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.fd-hot-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
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
