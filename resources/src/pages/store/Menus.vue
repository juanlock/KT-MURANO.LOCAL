<template>
  <div :class="{ page: !embedded }">
    <PageHeader v-if="!embedded" :title="$t('Menus')" :breadcrumb="[$t('Store'), $t('Menus')]">
      <template #extra>
        <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Imported website menu: the imported front page's OWN navigation,
           rebuilt in every page of the site with its original markup. -->
      <a-empty
        v-if="mode === 'site' && !site.available"
        description="This company's front page is not an imported website."
      />
      <a-card v-if="mode !== 'store' && site.available" size="small" style="margin-bottom: 16px">
        <template #title>
          Imported website menu
          <a-tag color="purple" style="margin-left: 8px">{{ site.items.length }}</a-tag>
          <span v-if="site.pages" class="site-sub">used in {{ site.pages }} page(s) of the imported site</span>
        </template>
        <template #extra>
          <a-space>
            <a-button size="small" :loading="site.detecting" @click="detectSite">Re-read from website</a-button>
            <a-button size="small" type="primary" :loading="site.saving" :disabled="!site.detected" @click="saveSite">Save website menu</a-button>
          </a-space>
        </template>

        <a-alert
          v-if="!site.detected" type="warning" show-icon
          message="No navigation menu was found in the imported front page. Press “Re-read from website” after re-importing, or edit the page itself."
        />
        <template v-else>
          <div class="site-help">
            Rename, re-link, add, remove, reorder or nest the website's menu items. The site's own design is kept —
            new items copy the style of the existing ones. Links starting with <code>#</code> jump to a section of the front page.
          </div>
          <div v-for="(item, idx) in site.items" :key="'s' + idx" class="menu-item">
            <div class="site-row">
              <a-input v-model:value="item.label" size="small" placeholder="Label" style="flex: 1" />
              <a-auto-complete
                v-model:value="item.href" size="small" style="flex: 1.4" placeholder="Link (#section, page, https://…)"
                :options="linkOptions" :filter-option="false"
              />
              <a-button size="small" :disabled="idx === 0" @click="moveIn(site.items, idx, -1)">↑</a-button>
              <a-button size="small" :disabled="idx === site.items.length - 1" @click="moveIn(site.items, idx, 1)">↓</a-button>
              <a-tooltip title="Add sub-item">
                <a-button size="small" @click="item.children.push({ label: '', href: '', children: [] })"><template #icon><PlusOutlined /></template></a-button>
              </a-tooltip>
              <a-button size="small" danger @click="site.items.splice(idx, 1)"><template #icon><DeleteOutlined /></template></a-button>
            </div>
            <div v-for="(child, cidx) in item.children" :key="'s' + idx + '-' + cidx" class="site-row site-child">
              <span class="site-branch">↳</span>
              <a-input v-model:value="child.label" size="small" placeholder="Label" style="flex: 1" />
              <a-auto-complete
                v-model:value="child.href" size="small" style="flex: 1.4" placeholder="Link"
                :options="linkOptions" :filter-option="false"
              />
              <a-button size="small" :disabled="cidx === 0" @click="moveIn(item.children, cidx, -1)">↑</a-button>
              <a-button size="small" :disabled="cidx === item.children.length - 1" @click="moveIn(item.children, cidx, 1)">↓</a-button>
              <a-button size="small" danger @click="item.children.splice(cidx, 1)"><template #icon><DeleteOutlined /></template></a-button>
            </div>
          </div>
          <a-button size="small" @click="site.items.push({ label: '', href: '', children: [] })">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add_Item') }}
          </a-button>
        </template>
      </a-card>

      <template v-if="mode !== 'site'">
      <div v-if="embedded" style="display: flex; justify-content: flex-end; margin-bottom: 12px">
        <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }} — {{ $t('Menus') }}</a-button>
      </div>
      <a-alert type="info" show-icon :message="$t('Menus_Help')" style="margin-bottom: 16px" />

      <a-row :gutter="[16, 16]">
        <a-col v-for="loc in LOCATIONS" :key="loc.key" :xs="24" :lg="8">
          <a-card size="small" style="height: 100%">
            <template #title>
              {{ $t(loc.label) }}
              <a-tag style="margin-left: 8px">{{ menus[loc.key].length }}</a-tag>
            </template>
            <a-empty v-if="!menus[loc.key].length" :description="$t('No_items')" />
            <div v-for="(item, idx) in menus[loc.key]" :key="loc.key + '-' + idx" class="menu-item">
              <div style="display: flex; gap: 6px; margin-bottom: 6px">
                <a-input v-model:value="item.label" size="small" :placeholder="$t('Label')" />
                <a-button
                  v-if="localeList.length" size="small"
                  :type="item._i18nOpen ? 'primary' : 'default'"
                  :title="$t('Translations')"
                  @click="item._i18nOpen = !item._i18nOpen"
                >
                  <template #icon><GlobalOutlined /></template>
                </a-button>
                <a-button size="small" :disabled="idx === 0" @click="move(loc.key, idx, -1)">↑</a-button>
                <a-button size="small" :disabled="idx === menus[loc.key].length - 1" @click="move(loc.key, idx, 1)">↓</a-button>
                <a-button size="small" danger @click="menus[loc.key].splice(idx, 1)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </div>
              <!-- Per-language label; blank falls back to the label above. -->
              <div v-if="item._i18nOpen" class="menu-i18n">
                <div v-for="l in localeList" :key="l.code" class="menu-i18n-row">
                  <span class="menu-i18n-code">{{ l.code.toUpperCase() }}</span>
                  <a-input
                    v-model:value="item.label_translations[l.code]"
                    size="small" :placeholder="item.label || $t('Label')"
                  />
                </div>
              </div>

              <div style="display: flex; gap: 6px">
                <a-select v-model:value="item.type" size="small" style="width: 44%" @change="item.value = ''">
                  <a-select-option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</a-select-option>
                </a-select>
                <a-select v-if="item.type === 'page'" v-model:value="item.value" size="small" style="flex: 1">
                  <a-select-option value="">— {{ $t('Select') }} —</a-select-option>
                  <a-select-option v-for="p in pages" :key="p.slug" :value="p.slug">{{ p.title }}</a-select-option>
                </a-select>
                <a-select v-else-if="item.type === 'collection'" v-model:value="item.value" size="small" style="flex: 1">
                  <a-select-option value="">— {{ $t('Select') }} —</a-select-option>
                  <a-select-option v-for="c in collections" :key="c.slug" :value="c.slug">{{ c.title }}</a-select-option>
                </a-select>
                <a-input v-else-if="item.type === 'url'" v-model:value="item.value" size="small" placeholder="https://…" style="flex: 1" />
                <span v-else style="flex: 1; font-size: 12px; color: #999; align-self: center">{{ $t('Built_in_link') }}</span>
              </div>
            </div>
            <a-button size="small" style="margin-top: 10px" @click="menus[loc.key].push({ label: '', label_translations: {}, type: 'url', value: '' })">
              <template #icon><PlusOutlined /></template>
              {{ $t('Add_Item') }}
            </a-button>
          </a-card>
        </a-col>
      </a-row>
      </template>
    </template>
  </div>
</template>

<script setup>
/**
 * Store menus — GET admin/store/menus → {menus{header, footer_shop,
 * footer_support}, pages, collections}; save POST admin/store/menus with
 * {menus: JSON string} after dropping items with empty labels (legacy).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DeleteOutlined, GlobalOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

// Also used inside Store Settings -> Homepage Blocks: `embedded` drops the
// page header, `mode` shows only the store's own menus ('store') or only the
// imported website's menu ('site').
const props = defineProps({
  embedded: { type: Boolean, default: false },
  mode: { type: String, default: 'all' }, // all | store | site
  // Whose menus: the Online Store's (store_settings) or Website Management's own (website_settings).
  owner: { type: String, default: 'store' }, // store | website
});
const menusUrl = computed(() => (props.owner === 'website' ? 'admin/website/menus' : 'admin/store/menus'));

const { t } = useI18n();

const ALL_LOCATIONS = [
  { key: 'header', label: 'Main_Navigation' },
  { key: 'footer_shop', label: 'Footer_Shop' },
  { key: 'footer_support', label: 'Footer_Support' },
];
// The website has no shop footer column (hidden on website pages).
const LOCATIONS = computed(() => ALL_LOCATIONS.filter(l => props.owner !== 'website' || l.key !== 'footer_shop'));

const isLoading = ref(true);
const saving = ref(false);
const menus = ref({ header: [], footer_shop: [], footer_support: [] });
const pages = ref([]);
const collections = ref([]);

const typeOptions = computed(() => [
  { value: 'home', label: t('Home') },
  { value: 'shop', label: t('Shop') },
  { value: 'flash_sales', label: t('Flash_Sales') },
  { value: 'contact', label: t('Contact') },
  { value: 'wishlist', label: t('MyWishlist') },
  { value: 'compare', label: t('Compare') },
  { value: 'page', label: t('Page') },
  { value: 'collection', label: t('Collection') },
  { value: 'url', label: t('Custom_URL') },
]);

// Storefront locales, from the API: [{code: 'fr', label: 'Français'}, …].
const localeList = ref([]);
function setLocales(map) {
  if (!map) return;
  localeList.value = Object.keys(map).map(code => ({ code, label: map[code] }));
}

function normalizeLoc(arr) {
  return (Array.isArray(arr) ? arr : []).map(it => ({
    label: (it && it.label) || '',
    // The API sends this as an object ({} when empty); copy so edits stay local.
    label_translations: { ...((it && it.label_translations) || {}) },
    _i18nOpen: false,
    type: (it && it.type) || 'url',
    value: (it && it.value) || '',
  }));
}
/* ---------------- imported website menu ---------------- */
const site = ref({ available: false, detected: false, items: [], pages: 0, links: [], saving: false, detecting: false });
const linkOptions = computed(() => site.value.links.map(l => ({ value: l.href, label: `${l.label}  (${l.href})` })));

function applySite(r) {
  site.value.available = !!r?.available;
  site.value.detected = !!r?.detected;
  site.value.pages = r?.pages || 0;
  // tpl (the site's markup for each item) travels back untouched on save.
  site.value.items = (r?.items || []).map(i => ({ ...i, href: i.href || '', children: (i.children || []).map(c => ({ ...c, href: c.href || '' })) }));
  if (r?.links) site.value.links = r.links;
}
async function fetchSite() {
  try { applySite(await http.get('admin/store/site-menu')); } catch (e) { /* no imported page */ }
}
async function saveSite() {
  site.value.saving = true;
  try {
    const items = site.value.items
      .filter(i => (i.label || '').trim())
      .map(i => ({ ...i, children: (i.children || []).filter(c => (c.label || '').trim()) }));
    const r = await http.put('admin/store/site-menu', { items });
    applySite(r);
    message.success(`Website menu saved in ${r.pages} page(s).`);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    site.value.saving = false;
  }
}
async function detectSite() {
  site.value.detecting = true;
  try {
    applySite(await http.post('admin/store/site-menu/detect'));
    message.success(site.value.detected ? 'Menu re-read from the website.' : 'No menu found in the website.');
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    site.value.detecting = false;
  }
}
function moveIn(arr, idx, dir) {
  const j = idx + dir;
  if (j < 0 || j >= arr.length) return;
  const [it] = arr.splice(idx, 1);
  arr.splice(j, 0, it);
}

function move(key, idx, dir) {
  const j = idx + dir;
  if (j < 0 || j >= menus.value[key].length) return;
  const arr = menus.value[key];
  const [it] = arr.splice(idx, 1);
  arr.splice(j, 0, it);
}

async function fetch() {
  try {
    const r = await http.get(menusUrl.value);
    const m = (r && r.menus) || {};
    menus.value = {
      header: normalizeLoc(m.header),
      footer_shop: normalizeLoc(m.footer_shop),
      footer_support: normalizeLoc(m.footer_support),
    };
    pages.value = (r && r.pages) || [];
    collections.value = (r && r.collections) || [];
    setLocales(r && r.locales);
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
async function save() {
  saving.value = true;
  try {
    const clean = {};
    ['header', 'footer_shop', 'footer_support'].forEach(k => {
      clean[k] = menus.value[k]
        .filter(it => (it.label || '').trim() !== '')
        // _i18nOpen is editor-only UI state and must not be persisted.
        .map(({ _i18nOpen, ...it }) => it);
    });
    await http.post(menusUrl.value, { menus: JSON.stringify(clean) });
    message.success(t('Successfully_Updated'));
    fetch();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  if (props.mode === 'site') fetchSite().finally(() => { isLoading.value = false; });
  else { fetch(); if (props.mode !== 'store') fetchSite(); }
});
</script>

<style scoped>
.site-sub { font-size: 12px; font-weight: normal; opacity: 0.6; margin-left: 8px; }
.site-help { font-size: 12px; opacity: 0.7; margin-bottom: 10px; }
.site-row { display: flex; gap: 6px; align-items: center; }
.site-child { margin-top: 6px; padding-left: 18px; }
.site-branch { opacity: 0.5; width: 14px; flex: none; }
.menu-i18n {
  display: grid;
  gap: 4px;
  margin: 0 0 6px;
  padding: 6px 8px;
  background: rgba(0, 0, 0, 0.02);
  border-radius: 6px;
}
.menu-i18n-row {
  display: flex;
  align-items: center;
  gap: 6px;
}
.menu-i18n-code {
  width: 26px;
  flex: none;
  font-size: 11px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.45);
}
.menu-item {
  background: rgba(0, 0, 0, 0.02);
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
  padding: 8px;
  margin-bottom: 8px;
}
</style>
