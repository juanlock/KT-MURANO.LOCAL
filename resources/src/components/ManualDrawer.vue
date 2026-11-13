<template>
  <a-drawer
    :open="open"
    placement="right"
    :width="drawerWidth"
    :root-class-name="ui.dark ? 'manual-drawer manual-drawer--dark' : 'manual-drawer'"
    :body-style="{ padding: '0' }"
    @close="$emit('close')"
  >
    <template #title>
      <span class="md-title"><OpenBookIcon style="font-size: 20px" /> {{ L('Manual', 'Manual') }}</span>
    </template>
    <template #extra>
      <a-segmented v-model:value="lang" size="small" :options="[{ value: 'en', label: 'EN' }, { value: 'es', label: 'ES' }]" />
    </template>

    <div class="md-bar">
      <a-segmented
        v-model:value="mode"
        block
        :options="[{ value: 'page', label: L('This page', 'Esta página') }, { value: 'all', label: L('Whole manual', 'Manual completo') }, { value: 'company', label: L('Your company', 'Su empresa') }]"
      />
      <a-input-search
        v-if="mode === 'all'"
        v-model:value="query"
        allow-clear
        class="md-search"
        :placeholder="L('Search the manual', 'Buscar en el manual')"
      />
    </div>

    <div class="md-body">
      <!-- ---------------- This page ---------------- -->
      <template v-if="mode === 'page'">
        <template v-if="pageEntry">
          <h2 class="md-h2">{{ pageEntry.title[lang] }}</h2>
          <p v-if="pageEntry.intro" class="md-intro">{{ pageEntry.intro[lang] }}</p>

          <div class="md-label">{{ L('On this screen', 'En esta pantalla') }}</div>
          <ManualSection v-for="s in pageSections.here" :key="s.id" :section="s" :lang="lang" />

          <template v-if="pageSections.more.length">
            <div class="md-label">{{ L('More in this module', 'Más en este módulo') }}</div>
            <a-collapse ghost class="md-more">
              <a-collapse-panel v-for="s in pageSections.more" :key="s.id" :header="s.title[lang]">
                <ManualSection :section="s" :lang="lang" bare />
              </a-collapse-panel>
            </a-collapse>
          </template>
        </template>

        <a-empty v-else :description="L('There is no manual page for this screen yet.', 'Todavía no hay manual para esta pantalla.')">
          <a-button type="primary" @click="mode = 'all'">{{ L('Open the whole manual', 'Abrir el manual completo') }}</a-button>
        </a-empty>
      </template>

      <!-- ---------------- Your company (2026-11-01): advantages for its business type + the PDF manual ---------------- -->
      <template v-else-if="mode === 'company'">
        <a-select v-if="isSystemView" v-model:value="pickedType" :options="typeOptions" style="width: 100%; margin-bottom: 12px" />
        <h2 class="md-h2">{{ L('Murano for', 'Murano para') }} {{ adv.label[lang] }}</h2>
        <p class="md-intro"><strong>{{ adv.tagline[lang] }}</strong> {{ adv.intro[lang] }}</p>
        <!-- The 2-page promotional flyer of the type (with its graphic) and the full manual. -->
        <!-- The company's own manual (files/company_{id}/manual/, e.g. Turismo Cautivo's guide), when it has one. -->
        <!-- The company's own flyer and manual (2026-11-13: Turismo Cautivo's), opened by a signed .pdf address. -->
        <a-button v-if="hasOwnFlyer" type="primary" block :loading="ownLoading === 'flyer'" style="margin-bottom: 8px" @click="openOwn('flyer')">
          <DownloadOutlined /> {{ L(`${company}'s flyer (PDF)`, `Flyer de ${company} (PDF)`) }}
        </a-button>
        <a-button v-if="hasOwnManual" :type="hasOwnFlyer ? 'default' : 'primary'" block :loading="ownLoading === 'manual'" style="margin-bottom: 8px" @click="openOwn('manual')">
          <DownloadOutlined /> {{ L(`${company}'s manual (PDF)`, `Manual de ${company} (PDF)`) }}
        </a-button>
        <a-button v-if="!hasOwnFlyer" :type="hasOwnManual ? 'default' : 'primary'" block :href="flyerUrl" target="_blank" style="margin-bottom: 8px">
          <DownloadOutlined /> {{ L('Download the flyer (2 pages, PDF)', 'Descargar el flyer (2 páginas, PDF)') }}
        </a-button>
        <!-- The type's generic manual: not when the company has its own, and not for tourism (removed 2026-11-13: too basic). -->
        <a-button v-if="!hasOwnManual && !NO_TYPE_MANUAL.includes(shownType)" block :href="pdfUrl" target="_blank" style="margin-bottom: 14px">
          <DownloadOutlined /> {{ L('Download the manual (PDF)', 'Descargar el manual (PDF)') }}
        </a-button>
        <div class="md-label">{{ L('What Murano does for you', 'Lo que Murano hace por usted') }}</div>
        <ul class="md-adv">
          <li v-for="(p, i) in adv.points" :key="'p' + i"><strong>{{ p.t[lang] }}.</strong> {{ p.d[lang] }}</li>
        </ul>
        <div class="md-label">{{ L('And every company gets', 'Y toda empresa tiene') }}</div>
        <ul class="md-adv">
          <li v-for="(p, i) in adv.common" :key="'c' + i"><strong>{{ p.t[lang] }}.</strong> {{ p.d[lang] }}</li>
        </ul>
      </template>

      <!-- ---------------- Whole manual ---------------- -->
      <template v-else>
        <a-collapse v-if="allEntries.length" v-model:active-key="openKeys" class="md-all">
          <a-collapse-panel v-for="m in allEntries" :key="m.key">
            <template #header>
              <span class="md-panel-title">{{ m.title[lang] }}</span>
              <a-tag v-if="m.key === pageEntry?.key" color="purple" class="md-here">{{ L('This page', 'Esta página') }}</a-tag>
            </template>
            <p v-if="m.intro" class="md-intro">{{ m.intro[lang] }}</p>
            <ManualSection v-for="s in m.sections" :key="s.id" :section="s" :lang="lang" />
          </a-collapse-panel>
        </a-collapse>
        <a-empty v-else :description="L('Nothing matches your search.', 'Nada coincide con su búsqueda.')" />
      </template>
    </div>

    <div class="md-foot">
      {{ L(
        `Showing only what ${company} uses and your role allows.`,
        `Solo se muestra lo que ${company} usa y su rol permite.`,
      ) }}
      · {{ L('Updated', 'Actualizado') }} {{ MANUAL_UPDATED }}
    </div>
  </a-drawer>
</template>

<script setup>
/**
 * The in-app user manual (header book icon). Content: manual/manual.js.
 * "This page" = the manual entry and sections for the screen being used;
 * "Whole manual" = every entry, with a search. Both show only the modules the
 * company has turned on (auth.moduleEnabled) and the parts the user's role
 * allows (auth.can). Language follows the app (Spanish when the app is in
 * Spanish, else English) and can be switched in the drawer.
 */
import { ref, computed, watch, defineComponent, h } from 'vue';
import { useRoute } from 'vue-router';
import {
  CalculatorOutlined, SettingOutlined, DownloadOutlined, PieChartOutlined, FullscreenOutlined,
  GlobalOutlined, BellOutlined, FieldTimeOutlined, LogoutOutlined, UserOutlined, BankOutlined,
} from '@ant-design/icons-vue';
import OpenBookIcon from './icons/OpenBookIcon.vue';
import { MANUAL, MANUAL_UPDATED } from '../manual/manual';
import { TYPES, advantagesFor } from '../manual/advantages';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';
import { message } from 'ant-design-vue';
import http from '../lib/http';
import { getActingCompanyId } from '../lib/actingCompany';

const props = defineProps({ open: { type: Boolean, default: false } });
defineEmits(['close']);

const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();

const appLang = () => (String(ui.locale || 'en').startsWith('es') ? 'es' : 'en');
const lang = ref(appLang());
const mode = ref('page');
const query = ref('');
const openKeys = ref([]);
const L = (en, es) => (lang.value === 'es' ? es : en);

const company = computed(() => auth.companyName || auth.user?.company || 'your company');
const drawerWidth = computed(() => Math.min(560, window.innerWidth));

// ---------------- your company: advantages + PDF per business type ----------------
// The company's business type (UserController business_type); AdminSystem (SuperAdmin) picks any type.
const companyType = computed(() => (TYPES[auth.user?.business_type] ? auth.user.business_type : 'general'));
const isSystemView = computed(() => auth.user?.type === 'SuperAdmin');
const pickedType = ref(null);
const shownType = computed(() => pickedType.value || companyType.value);
const typeOptions = computed(() => Object.entries(TYPES).map(([value, t]) => ({ value, label: t.label[lang.value] }))
  .sort((a, b) => a.label.localeCompare(b.label)));
const adv = computed(() => {
  const a = advantagesFor(shownType.value);
  // The company's own modules decide which common advantages show (the PDF uses the type's).
  return pickedType.value ? a : { ...a, common: a.common.filter(c => !c.module || auth.moduleEnabled(c.module)) };
});
const pdfUrl = computed(() => `/manual/murano-${shownType.value}-${lang.value}.pdf`);
const flyerUrl = computed(() => `/manual/flyer-${shownType.value}-${lang.value}.pdf`);
// The company's own manual (private file, dashboard permission; not when AdminSystem browses another type).
const hasOwnManual = computed(() => !!auth.user?.company_manual && !pickedType.value
  && (auth.user?.type === 'SuperAdmin' || auth.can('dashboard')));
const hasOwnFlyer = computed(() => !!auth.user?.company_flyer && !pickedType.value
  && (auth.user?.type === 'SuperAdmin' || auth.can('dashboard')));
const NO_TYPE_MANUAL = ['tourism'];
const ownLoading = ref(null);
// A signed address ending in .pdf (not a "blob:"), so the document opens and saves with its name.
async function openOwn(kind) {
  ownLoading.value = kind;
  const win = window.open('', '_blank'); // opened now, before the request, so the browser doesn't block it
  try {
    const cid = getActingCompanyId();
    const res = await http.get('company-manual', { lang: lang.value, kind, ...(cid ? { company_id: cid } : {}) });
    if (win) win.location.href = res.url; else window.location.href = res.url;
  } catch (e) {
    if (win) win.close();
    message.error(L('The document could not be opened.', 'No se pudo abrir el documento.'));
  } finally {
    ownLoading.value = null;
  }
}

// ---------------- what this user may see ----------------
const anyPerm = list => !list || !list.length || list.some(p => auth.can(p));
const moduleOn = key => !key || auth.moduleEnabled(key);
const sectionVisible = s => moduleOn(s.module) && anyPerm(s.perms);

const visible = computed(() => MANUAL
  .filter(m => moduleOn(m.module) && anyPerm(m.perms))
  .map(m => ({ ...m, sections: m.sections.filter(sectionVisible) }))
  .filter(m => m.sections.length));

// ---------------- the current screen ----------------
const current = computed(() => String(route.path || '').replace(/^\/+/, '').replace(/\/+$/, ''));
// Longest matching prefix wins ('service/daily' beats 'service').
function score(paths) {
  let best = 0;
  for (const p of paths || []) {
    if (current.value === p || current.value.startsWith(p + '/')) best = Math.max(best, p.length);
  }
  return best;
}
const pageEntry = computed(() => {
  let best = null; let bestScore = 0;
  for (const m of visible.value) {
    const sc = Math.max(score(m.paths), ...m.sections.map(s => score(s.paths)));
    if (sc > bestScore) { best = m; bestScore = sc; }
  }
  return best;
});
const pageSections = computed(() => {
  const m = pageEntry.value;
  if (!m) return { here: [], more: [] };
  const scored = m.sections.map(s => ({ s, sc: score(s.paths) }));
  const top = Math.max(0, ...scored.map(x => x.sc));
  const here = top ? scored.filter(x => x.sc === top).map(x => x.s) : m.sections;
  return { here, more: m.sections.filter(s => !here.includes(s)) };
});

// ---------------- whole manual + search ----------------
const textOf = (o, l) => [o.title?.[l], o.intro?.[l], o.note?.[l], ...(o.steps?.[l] || []),
  ...(o.icons || []).flatMap(i => [i.title?.[l], i.text?.[l]])].join(' ').toLowerCase();
const allEntries = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return visible.value;
  return visible.value
    .map(m => (textOf(m, lang.value).includes(q) ? m : { ...m, sections: m.sections.filter(s => textOf(s, lang.value).includes(q)) }))
    .filter(m => m.sections.length);
});
watch(query, q => { if (q) openKeys.value = allEntries.value.map(m => m.key); });

// Each opening: the app's language, "This page" when the screen has a manual entry.
watch(() => props.open, isOpen => {
  if (!isOpen) return;
  lang.value = appLang();
  mode.value = pageEntry.value ? 'page' : 'all';
  query.value = '';
  openKeys.value = pageEntry.value ? [pageEntry.value.key] : [];
});

// ---------------- one section ----------------
// Text is escaped first; only **bold** becomes markup.
const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = t => esc(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

// A section's `icons` list (e.g. the top bar): each key drawn with the same
// icon and colors as in AppTopbar, next to its name and what it does.
const ICON_LOOK = {
  company: { comp: BankOutlined, color: '#475569', bg: 'rgba(71, 85, 105, 0.10)' },
  pos: { comp: CalculatorOutlined, color: '#fff', bg: '#1677ff', label: 'POS' },
  customize: { comp: SettingOutlined, color: '#1677ff', bg: 'rgba(22, 119, 255, 0.09)' },
  install: { comp: DownloadOutlined, color: '#6d28d9', bg: 'rgba(109, 40, 217, 0.08)' },
  summary: { comp: PieChartOutlined, color: '#7c3aed', bg: 'rgba(124, 58, 237, 0.09)' },
  fullscreen: { comp: FullscreenOutlined, color: '#0d9488', bg: 'rgba(13, 148, 136, 0.09)' },
  manual: { comp: OpenBookIcon, color: '#4f46e5', bg: 'rgba(79, 70, 229, 0.09)' },
  language: { comp: GlobalOutlined, color: '#16a34a', bg: 'rgba(22, 163, 74, 0.09)' },
  notifications: { comp: BellOutlined, color: '#ea580c', bg: 'rgba(234, 88, 12, 0.09)' },
  clock: { comp: FieldTimeOutlined, color: '#64748b', bg: 'rgba(100, 116, 139, 0.10)' },
  logout: { comp: LogoutOutlined, color: '#dc2626', bg: 'rgba(220, 38, 38, 0.09)' },
  user: { comp: UserOutlined, color: '#64748b', bg: 'rgba(100, 116, 139, 0.10)', round: true },
};
const iconBadge = key => {
  const look = ICON_LOOK[key] || { comp: SettingOutlined, color: '#64748b', bg: 'rgba(100,116,139,.1)' };
  return h('span', {
    class: ['md-ic', { 'md-ic--round': look.round, 'md-ic--pill': look.label }],
    style: { color: look.color, background: look.bg },
  }, [h(look.comp), look.label ? h('span', { class: 'md-ic-label' }, look.label) : null]);
};

const ManualSection = defineComponent({
  props: { section: { type: Object, required: true }, lang: { type: String, required: true }, bare: Boolean },
  setup(p) {
    return () => {
      const s = p.section; const l = p.lang;
      return h('div', { class: ['md-section', { 'md-section--bare': p.bare }] }, [
        p.bare ? null : h('h3', { class: 'md-h3' }, s.title[l]),
        s.intro ? h('p', { class: 'md-intro', innerHTML: fmt(s.intro[l]) }) : null,
        s.icons?.length
          ? h('div', { class: 'md-icons' }, s.icons.map(ic => h('div', { class: 'md-icon-row' }, [
            iconBadge(ic.key),
            h('div', { class: 'md-icon-text' }, [
              h('strong', null, ic.title[l]),
              h('span', { innerHTML: fmt(ic.text[l]) }),
            ]),
          ])))
          : null,
        s.steps?.[l]?.length ? h('ol', { class: 'md-steps' }, s.steps[l].map(t => h('li', { innerHTML: fmt(t) }))) : null,
        s.note ? h('div', { class: 'md-note', innerHTML: fmt(s.note[l]) }) : null,
      ]);
    };
  },
});
</script>

<style scoped>
.md-title { display: inline-flex; align-items: center; gap: 8px; }
.md-bar { position: sticky; top: 0; z-index: 2; display: grid; gap: 10px; padding: 12px 20px; background: #fff; border-bottom: 1px solid rgba(0, 0, 0, 0.06); }
.md-body { padding: 16px 20px 24px; min-width: 0; }
.md-h2 { margin: 0 0 6px; font-size: 20px; font-weight: 600; }
.md-intro { margin: 0 0 14px; color: rgba(0, 0, 0, 0.6); }
.md-adv { margin: 0 0 14px; padding-left: 18px; display: grid; gap: 7px; line-height: 1.5; }
.md-label { margin: 18px 0 8px; font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #6d28d9; }
.md-panel-title { font-weight: 600; }
.md-here { margin-left: 8px; }
.md-foot { padding: 10px 20px; font-size: 12px; color: rgba(0, 0, 0, 0.45); border-top: 1px solid rgba(0, 0, 0, 0.06); }
:deep(.md-section) { margin-bottom: 16px; }
:deep(.md-section--bare) { margin-bottom: 0; }
:deep(.md-h3) { margin: 0 0 6px; font-size: 15px; font-weight: 600; }
/* `icons` list: badge (same look as the top bar) + name + what it does */
:deep(.md-intro) { margin: 0 0 8px; line-height: 1.55; }
:deep(.md-icons) { display: grid; gap: 10px; margin-bottom: 10px; }
:deep(.md-icon-row) { display: flex; align-items: flex-start; gap: 12px; }
:deep(.md-ic) { flex: 0 0 auto; width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 17px; }
:deep(.md-ic--round) { border-radius: 50%; }
:deep(.md-ic--pill) { width: auto; padding: 0 12px; border-radius: 999px; gap: 6px; font-size: 14px; font-weight: 600; }
:deep(.md-icon-text) { display: flex; flex-direction: column; line-height: 1.5; }
:deep(.md-icon-text strong) { font-weight: 600; }
:deep(.md-steps) { margin: 0; padding-left: 20px; display: grid; gap: 5px; line-height: 1.55; }
:deep(.md-steps li::marker) { color: #6d28d9; font-weight: 600; }
:deep(.md-note) { margin-top: 8px; padding: 8px 12px; border-left: 3px solid #6d28d9; background: rgba(109, 40, 217, 0.07); border-radius: 0 8px 8px 0; font-size: 13px; }

/* Dark theme (the drawer teleports to <body>: darkness comes from the ui store). */
.manual-drawer--dark .md-intro { color: rgba(255, 255, 255, 0.6); }
.manual-drawer--dark .md-foot { color: rgba(255, 255, 255, 0.45); border-top-color: rgba(255, 255, 255, 0.08); }
.manual-drawer--dark .md-bar { background: #1f1f1f; border-bottom-color: rgba(255, 255, 255, 0.08); }
.manual-drawer--dark .md-label { color: #c4b5fd; }
.manual-drawer--dark .md-body :deep(.md-steps li::marker) { color: #c4b5fd; }
.manual-drawer--dark .md-body :deep(.md-note) { border-left-color: #a78bfa; background: rgba(167, 139, 250, 0.12); }
</style>
