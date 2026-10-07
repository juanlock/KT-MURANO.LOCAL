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
      <span class="md-title"><BookOutlined /> {{ L('Manual', 'Manual') }}</span>
    </template>
    <template #extra>
      <a-segmented v-model:value="lang" size="small" :options="[{ value: 'en', label: 'EN' }, { value: 'es', label: 'ES' }]" />
    </template>

    <div class="md-bar">
      <a-segmented
        v-model:value="mode"
        block
        :options="[{ value: 'page', label: L('This page', 'Esta página') }, { value: 'all', label: L('Whole manual', 'Manual completo') }]"
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
import { BookOutlined } from '@ant-design/icons-vue';
import { MANUAL, MANUAL_UPDATED } from '../manual/manual';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

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
const textOf = (o, l) => [o.title?.[l], o.intro?.[l], o.note?.[l], ...(o.steps?.[l] || [])].join(' ').toLowerCase();
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

const ManualSection = defineComponent({
  props: { section: { type: Object, required: true }, lang: { type: String, required: true }, bare: Boolean },
  setup(p) {
    return () => {
      const s = p.section; const l = p.lang;
      return h('div', { class: ['md-section', { 'md-section--bare': p.bare }] }, [
        p.bare ? null : h('h3', { class: 'md-h3' }, s.title[l]),
        h('ol', { class: 'md-steps' }, (s.steps?.[l] || []).map(t => h('li', { innerHTML: fmt(t) }))),
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
.md-label { margin: 18px 0 8px; font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #6d28d9; }
.md-panel-title { font-weight: 600; }
.md-here { margin-left: 8px; }
.md-foot { padding: 10px 20px; font-size: 12px; color: rgba(0, 0, 0, 0.45); border-top: 1px solid rgba(0, 0, 0, 0.06); }
:deep(.md-section) { margin-bottom: 16px; }
:deep(.md-section--bare) { margin-bottom: 0; }
:deep(.md-h3) { margin: 0 0 6px; font-size: 15px; font-weight: 600; }
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
