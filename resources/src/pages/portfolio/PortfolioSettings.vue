<template>
  <div class="page">
    <PageHeader title="Portfolio settings" :breadcrumb="['Portfolio', 'Settings']">
      <template #extra>
        <a-space wrap>
          <a-tag v-if="site" :color="site.published ? 'green' : 'orange'">{{ site.published ? 'Published' : 'Not published' }}</a-tag>
          <a-button v-if="site" :href="site.public_url" target="_blank">
            <template #icon><ExportOutlined /></template> {{ site.published ? 'Open website' : 'Preview website' }}
          </a-button>
          <a-button type="primary" :loading="saving" :disabled="!site" @click="save">Save settings</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-tabs v-if="site" v-model:activeKey="tab" tab-position="left" class="ps-tabs">
        <!-- ===== Theme ===== -->
        <a-tab-pane key="theme">
          <template #tab><span class="ps-tab"><BgColorsOutlined /> Theme</span></template>
          <a-card size="small" :bordered="false" title="Theme" class="ps-card">
            <div class="ps-muted" style="margin-bottom: 12px">How the whole website looks. Your works, texts and menu stay the same when you change it.</div>
            <div class="ps-themes">
              <div v-for="t in themes" :key="t.key" class="ps-theme" :class="{ on: form.template === t.key }" @click="pickTheme(t)">
                <div class="ps-theme__shot" :class="`shot-${t.key}`">
                  <template v-if="t.key === 'minimal'"><i class="c tl" /><i class="c tr" /><b class="logo" /><span class="row"><i /><i /><i /></span><span class="row"><i /><i /><i /></span></template>
                  <template v-else-if="t.key === 'grid'"><span class="bar"><b /><em /></span><span class="row two"><i /><i /></span><span class="row two"><i /><i /></span></template>
                  <template v-else><span class="bar center"><em /><b /><em /></span><span class="wide" /><span class="wide" /></template>
                </div>
                <div class="ps-theme__name"><CheckCircleFilled v-if="form.template === t.key" style="color: #52c41a" /> {{ t.name }}</div>
                <div class="ps-muted">{{ t.description }}</div>
              </div>
            </div>
            <a-row :gutter="16" style="margin-top: 16px">
              <a-col :xs="24" :md="8">
                <a-form-item label="Look">
                  <a-radio-group v-model:value="form.theme" button-style="solid">
                    <a-radio-button value="dark">Dark</a-radio-button>
                    <a-radio-button value="light">Light</a-radio-button>
                  </a-radio-group>
                </a-form-item>
              </a-col>
              <a-col v-if="form.template === 'minimal'" :xs="24" :md="16">
                <a-form-item label="Menu on every page" extra="Off = like the example: Home, Portfolio and a work show only the logo; About and Contact show the menu.">
                  <a-switch v-model:checked="form.menu_on_home" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Website ===== -->
        <a-tab-pane key="website">
          <template #tab><span class="ps-tab"><GlobalOutlined /> Website</span></template>
          <a-card size="small" :bordered="false" title="Website" class="ps-card">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12"><a-form-item label="Name" required><a-input v-model:value="form.name" :maxlength="150" /></a-form-item></a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Web address" :extra="`${origin}/portfolio/${form.slug || '…'}`" required>
                  <a-input v-model:value="form.slug" addon-before="/portfolio/" :maxlength="80" @change="form.slug = slugify(form.slug, true)" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12"><a-form-item label="Tagline (search engines / browser)"><a-input v-model:value="form.tagline" :maxlength="255" /></a-form-item></a-col>
              <a-col :xs="24" :md="12"><a-form-item label="Footer text"><a-input v-model:value="form.footer_text" :maxlength="255" :placeholder="`© ${form.name || 'Company'}, ${new Date().getFullYear()}`" /></a-form-item></a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Published" extra="Off: only your team can preview it (signed in).">
                  <a-switch v-model:checked="form.published" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Logo & background ===== -->
        <a-tab-pane key="branding">
          <template #tab><span class="ps-tab"><PictureOutlined /> Logo & background</span></template>
          <a-card size="small" :bordered="false" title="Logo & background" class="ps-card">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item label="Logo" extra="Top of every page. Without a logo the website name is shown.">
                  <ImageSlot :url="site.logo_url" :busy="imgBusy === 'logo'" @upload="f => uploadImage('logo', f)" @remove="removeImage('logo')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Background color" extra="Empty = the Look's own (dark / light).">
                  <div class="ps-color">
                    <input type="color" :value="form.bg_color || '#000000'" @input="e => (form.bg_color = e.target.value)" />
                    <span>{{ form.bg_color || 'default' }}</span>
                    <a v-if="form.bg_color" @click="form.bg_color = null">reset</a>
                  </div>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Background picture" extra="Covers the whole page, behind everything.">
                  <ImageSlot :url="site.bg_image_url" :busy="imgBusy === 'background'" @upload="f => uploadImage('background', f)" @remove="removeImage('background')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Menu ===== -->
        <a-tab-pane key="menu">
          <template #tab><span class="ps-tab"><MenuOutlined /> Menu</span></template>
          <a-card size="small" :bordered="false" class="ps-card">
            <template #title>Menu <span class="ps-muted" style="font-weight: normal">— top of the website, in this order</span></template>
            <template #extra>
              <a-popconfirm title="Back to the default menu (Home, About, Portfolio, Contact)?" @confirm="resetMenu">
                <a-button size="small">Reset to default</a-button>
              </a-popconfirm>
            </template>
            <div class="ps-muted" style="margin-bottom: 10px">
              <b>Internal page</b> = a page of this website (Home, About, Portfolio, Contact, a category, a work).
              <b>External link</b> = any web address (opens in a new tab if you want).
              <b>Group</b> = a menu that opens a list of links.
            </div>
            <div v-for="(it, i) in form.menu" :key="it._k" class="ps-mi" :class="{ off: !it.visible, group: it.type === 'group' }">
              <MenuRow :item="it" :page-options="pageOptions" :first="i === 0" :last="i === form.menu.length - 1" top
                       @up="moveItem(form.menu, i, -1)" @down="moveItem(form.menu, i, 1)" @remove="form.menu.splice(i, 1)" />
              <div v-if="it.type === 'group'" class="ps-children">
                <div v-for="(ch, j) in it.children" :key="ch._k" class="ps-mi child" :class="{ off: !ch.visible }">
                  <MenuRow :item="ch" :page-options="pageOptions" :first="j === 0" :last="j === it.children.length - 1"
                           @up="moveItem(it.children, j, -1)" @down="moveItem(it.children, j, 1)" @remove="it.children.splice(j, 1)" />
                </div>
                <a-button size="small" type="dashed" :disabled="it.children.length >= 15" @click="it.children.push(blankItem('page'))">
                  <template #icon><PlusOutlined /></template> Add link to "{{ it.label || 'group' }}"
                </a-button>
              </div>
            </div>
            <a-space style="margin-top: 10px">
              <a-button :disabled="form.menu.length >= 12" @click="form.menu.push(blankItem('page'))"><template #icon><PlusOutlined /></template> Add internal page</a-button>
              <a-button :disabled="form.menu.length >= 12" @click="form.menu.push(blankItem('link'))"><template #icon><LinkOutlined /></template> Add external link</a-button>
              <a-button :disabled="form.menu.length >= 12" @click="form.menu.push(blankItem('group'))"><template #icon><FolderOutlined /></template> Add group</a-button>
            </a-space>
          </a-card>
        </a-tab-pane>

        <!-- ===== Pages (About / Contact) ===== -->
        <a-tab-pane key="pages">
          <template #tab><span class="ps-tab"><FileTextOutlined /> Pages</span></template>
          <a-card size="small" :bordered="false" title="About page" class="ps-card">
            <template #extra><a-switch v-model:checked="form.pages.about" size="small" /> <span class="ps-muted">On the website</span></template>
            <a-row :gutter="16">
              <a-col :xs="24" :md="8"><a-form-item label="Title"><a-input v-model:value="form.about_title" :maxlength="150" placeholder="About us" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item label="Subtitle"><a-input v-model:value="form.about_subtitle" :maxlength="255" placeholder="Our story" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item label="Heading"><a-input v-model:value="form.about_name" :maxlength="150" placeholder="e.g. We are New Era" /></a-form-item></a-col>
              <a-col :xs="24" :md="16">
                <a-form-item label="Text" extra="Leave an empty line between paragraphs."><a-textarea v-model:value="form.about_text" :rows="7" :maxlength="10000" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Picture">
                  <ImageSlot :url="site.about_image_url" :busy="imgBusy === 'about'" @upload="f => uploadImage('about', f)" @remove="removeImage('about')" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item label="Skills bars (optional)">
                  <div v-for="(s, i) in form.skills" :key="i" class="ps-row">
                    <a-input v-model:value="s.label" size="small" placeholder="e.g. Hair coloring" style="flex: 1" :maxlength="60" />
                    <a-input-number v-model:value="s.pct" size="small" :min="0" :max="100" addon-after="%" style="width: 120px" />
                    <a-button size="small" danger @click="form.skills.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
                  </div>
                  <a-button size="small" :disabled="form.skills.length >= 12" @click="form.skills.push({ label: '', pct: 90 })"><template #icon><PlusOutlined /></template> Add skill</a-button>
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
          <a-card size="small" :bordered="false" title="Contact page" class="ps-card" style="margin-top: 12px">
            <template #extra><a-switch v-model:checked="form.pages.contact" size="small" /> <span class="ps-muted">On the website</span></template>
            <a-row :gutter="16">
              <a-col :xs="24" :md="12"><a-form-item label="Subtitle"><a-input v-model:value="form.contact_subtitle" :maxlength="255" placeholder="Get in touch with us" /></a-form-item></a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Google Maps embed link" extra="Google Maps → Share → Embed a map → copy the https://www.google.com/maps/embed?… address.">
                  <a-input v-model:value="form.map_url" :maxlength="2000" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8"><a-form-item label="Address"><a-textarea v-model:value="form.contact_address" :rows="3" :maxlength="500" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item label="Phones (one per line)"><a-textarea v-model:value="form.contact_phones" :rows="3" :maxlength="500" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item label="Emails (one per line)"><a-textarea v-model:value="form.contact_emails" :rows="3" :maxlength="500" /></a-form-item></a-col>
            </a-row>
          </a-card>
        </a-tab-pane>

        <!-- ===== Social links ===== -->
        <a-tab-pane key="social">
          <template #tab><span class="ps-tab"><ShareAltOutlined /> Social links</span></template>
          <a-card size="small" :bordered="false" title="Social links" class="ps-card">
            <div class="ps-muted" style="margin-bottom: 10px">Shown as icons (footer in theme 1, header in themes 2 and 3). Empty = not shown.</div>
            <a-row :gutter="16">
              <a-col v-for="s in SOCIAL" :key="s" :xs="24" :md="8">
                <a-form-item :label="s[0].toUpperCase() + s.slice(1)">
                  <a-input v-model:value="form.social[s]" :maxlength="255" :placeholder="s === 'whatsapp' ? 'e.g. +1 973 555 0100' : 'https://…'" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Portfolio -> Settings: the website's global settings (like Store -> Settings):
 * theme (1 Minimal = the KT-TOOLS example, 2 Grid / 3 Showcase = Adobe Portfolio
 * styles), look, website name / address / published, logo and background, the
 * menu (internal pages, external links, groups), About / Contact pages, social links.
 * API: GET /api/portfolio, PUT /api/portfolio/site, POST|DELETE /api/portfolio/site/image.
 */
import { ref, reactive, computed, onMounted, h, defineComponent } from 'vue';
import { message } from 'ant-design-vue';
import {
  ExportOutlined, PlusOutlined, DeleteOutlined, UploadOutlined, BgColorsOutlined, GlobalOutlined, PictureOutlined,
  MenuOutlined, FileTextOutlined, ShareAltOutlined, LinkOutlined, FolderOutlined, CheckCircleFilled, ArrowUpOutlined, ArrowDownOutlined,
} from '@ant-design/icons-vue';
import {
  Upload as AUpload, Button as AButton, Image as AImage, Space as ASpace, Input as AInput, Select as ASelect,
  Switch as ASwitch, Tag as ATag, Tooltip as ATooltip,
} from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';

// A picture with Upload / Change and Remove (a-image needs an object style -- see PortfolioAdmin).
const SLOT_IMG_STYLE = { objectFit: 'contain', borderRadius: '6px', background: '#111' };
const ImageSlot = defineComponent({
  props: { url: String, busy: Boolean },
  emits: ['upload', 'remove'],
  setup(props, { emit }) {
    return () => h('div', { class: 'ps-slot' }, [
      props.url ? h(AImage, { src: props.url, width: 120, height: 90, style: SLOT_IMG_STYLE }) : h('div', { class: 'ps-slot__empty' }, 'No picture'),
      h(ASpace, { direction: 'vertical', size: 4 }, () => [
        h(AUpload, { showUploadList: false, accept: 'image/*', beforeUpload: f => { emit('upload', f); return false; } },
          () => h(AButton, { size: 'small', loading: props.busy }, () => [h(UploadOutlined), props.url ? ' Change' : ' Upload'])),
        props.url ? h(AButton, { size: 'small', danger: true, onClick: () => emit('remove') }, () => 'Remove') : null,
      ]),
    ]);
  },
});

const TYPE_TAG = { page: ['blue', 'Internal page'], link: ['purple', 'External link'], group: ['gold', 'Group'] };
// One menu row: label, kind, where it goes, new tab, visible, move / remove.
const MenuRow = defineComponent({
  props: { item: Object, pageOptions: Array, first: Boolean, last: Boolean, top: Boolean },
  emits: ['up', 'down', 'remove'],
  setup(props, { emit }) {
    return () => {
      const it = props.item;
      const kinds = props.top
        ? [{ value: 'page', label: 'Internal page' }, { value: 'link', label: 'External link' }, { value: 'group', label: 'Group' }]
        : [{ value: 'page', label: 'Internal page' }, { value: 'link', label: 'External link' }];
      return h('div', { class: 'ps-mi__row' }, [
        h(ATag, { color: TYPE_TAG[it.type][0], style: 'margin: 0' }, () => TYPE_TAG[it.type][1]),
        h(AInput, { value: it.label, size: 'small', maxlength: 60, placeholder: 'Text in the menu', style: 'width: 180px', 'onUpdate:value': v => (it.label = v) }),
        h(ASelect, { value: it.type, size: 'small', options: kinds, style: 'width: 140px', 'onUpdate:value': v => setType(it, v) }),
        it.type === 'page'
          ? h(ASelect, {
            value: it.page || undefined, size: 'small', options: props.pageOptions, placeholder: 'Pick the page', style: 'flex: 1; min-width: 200px',
            showSearch: true, optionFilterProp: 'label',
            'onUpdate:value': v => { it.page = v; if (!it.label) it.label = labelOfPage(props.pageOptions, v); },
          })
          : it.type === 'link'
            ? h(AInput, { value: it.url, size: 'small', maxlength: 500, placeholder: 'https://… , mailto:… or tel:…', style: 'flex: 1; min-width: 200px', 'onUpdate:value': v => (it.url = v) })
            : h('span', { class: 'ps-muted', style: 'flex: 1' }, `${it.children.length} link${it.children.length === 1 ? '' : 's'} below`),
        it.type === 'link'
          ? h(ATooltip, { title: 'Open in a new tab' }, () => h(ASwitch, { checked: it.new_tab, size: 'small', checkedChildren: 'New tab', unCheckedChildren: 'Same tab', 'onUpdate:checked': v => (it.new_tab = v) }))
          : null,
        h(ATooltip, { title: 'Shown on the website' }, () => h(ASwitch, { checked: it.visible, size: 'small', 'onUpdate:checked': v => (it.visible = v) })),
        h(AButton, { size: 'small', disabled: props.first, onClick: () => emit('up') }, () => h(ArrowUpOutlined)),
        h(AButton, { size: 'small', disabled: props.last, onClick: () => emit('down') }, () => h(ArrowDownOutlined)),
        h(AButton, { size: 'small', danger: true, onClick: () => emit('remove') }, () => h(DeleteOutlined)),
      ]);
    };
  },
});
function setType(it, v) {
  it.type = v;
  if (v === 'group' && !Array.isArray(it.children)) it.children = [];
  if (v === 'link' && it.new_tab === undefined) it.new_tab = true;
}
function labelOfPage(options, v) {
  for (const o of options) {
    if (o.value === v) return o.label;
    for (const c of o.options || []) if (c.value === v) return c.label;
  }
  return '';
}

const SOCIAL = ['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'whatsapp', 'website'];
const origin = window.location.origin;
const tab = ref('theme');
const loading = ref(true);
const saving = ref(false);
const site = ref(null);
const themes = ref([]);
const categories = ref([]);
const projects = ref([]);
let seq = 0;
const key = () => `k${++seq}`;

const form = reactive({ menu: [], social: {}, skills: [], pages: { about: true, contact: true } });
// The menu goes to the server only once it differs from the default one.
const menuCustom = ref(false);
let menuSnapshot = '';

const withKeys = items => (items || []).map(i => ({
  label: i.label || '', type: i.type || 'page', page: i.page || null, url: i.url || '', new_tab: i.new_tab !== false && i.type === 'link',
  visible: i.visible !== false, _k: key(), children: withKeys(i.children),
}));
const plain = items => items.map(i => ({
  label: i.label, type: i.type, page: i.type === 'page' ? i.page : null, url: i.type === 'link' ? i.url : null,
  new_tab: !!i.new_tab, visible: !!i.visible, children: i.type === 'group' ? plain(i.children) : [],
}));
function blankItem(type) {
  return { label: type === 'group' ? 'Services' : '', type, page: null, url: '', new_tab: type === 'link', visible: true, _k: key(), children: [] };
}
function moveItem(list, i, dir) {
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
}
function resetMenu() {
  form.menu = withKeys(site.value.default_menu);
  menuCustom.value = false;
  menuSnapshot = JSON.stringify(plain(form.menu));
}

// Internal pages for the menu: the 4 pages in their usual order, then categories / works A-Z.
const pageOptions = computed(() => [
  { label: 'Pages', options: [
    { value: 'home', label: 'Home' }, { value: 'about', label: form.about_title || 'About' },
    { value: 'work', label: 'Portfolio' }, { value: 'contact', label: 'Contact' },
  ] },
  ...(categories.value.length ? [{ label: 'Categories (gallery filtered)', options: sortByLabel(categories.value.map(c => ({ value: `category:${c.slug}`, label: c.name }))) }] : []),
  ...(projects.value.length ? [{ label: 'Works', options: sortByLabel(projects.value.filter(p => p.slug).map(p => ({ value: `project:${p.slug}`, label: p.title }))) }] : []),
]);

function pickTheme(t) {
  if (form.template === t.key) return;
  form.template = t.key;
  form.theme = t.default_look;
}

function fill(s) {
  Object.assign(form, {
    template: s.template || 'minimal', theme: s.theme || 'dark', menu_on_home: !!s.menu_on_home,
    name: s.name, slug: s.slug, tagline: s.tagline, published: !!s.published, footer_text: s.footer_text, bg_color: s.bg_color || null,
    about_title: s.about_title, about_subtitle: s.about_subtitle, about_name: s.about_name, about_text: s.about_text,
    skills: (s.skills || []).map(x => ({ ...x })), contact_subtitle: s.contact_subtitle, contact_address: s.contact_address,
    contact_phones: s.contact_phones, contact_emails: s.contact_emails, map_url: s.map_url,
    social: Object.fromEntries(SOCIAL.map(k => [k, (s.social || {})[k] || ''])),
    pages: { about: s.pages?.about !== false, contact: s.pages?.contact !== false },
    menu: withKeys(s.menu),
  });
  menuCustom.value = !!s.menu_custom;
  menuSnapshot = JSON.stringify(plain(form.menu));
}

async function load() {
  loading.value = true;
  try {
    const r = await http.get('portfolio');
    site.value = r.site;
    themes.value = r.themes || [];
    categories.value = r.categories || [];
    projects.value = r.projects || [];
    fill(r.site);
  } catch (e) {
    message.error(e?.data?.message || 'Could not load the portfolio settings.');
  } finally {
    loading.value = false;
  }
}

function slugify(v, keepDash = false) {
  const s = String(v || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-');
  return keepDash ? s.replace(/^-+/, '') : s.replace(/^-+|-+$/g, '');
}
function firstError(e) {
  const errs = e?.data?.errors;
  return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message;
}

async function save() {
  const bad = (function check(items) {
    for (const i of items) {
      if (i.type === 'page' && !i.page) return `Pick the page for "${i.label || 'a menu item'}".`;
      if (i.type === 'link' && !/^(https?:\/\/|mailto:|tel:)/i.test(i.url || '')) return `The link of "${i.label || 'a menu item'}" must start with https:// (or mailto: / tel:).`;
      if (!String(i.label || '').trim()) return 'Every menu item needs a text.';
      if (i.type === 'group') { const c = check(i.children); if (c) return c; }
    }
    return null;
  })(form.menu);
  if (bad) { tab.value = 'menu'; message.error(bad); return; }

  const menu = plain(form.menu);
  const changed = menuCustom.value || JSON.stringify(menu) !== menuSnapshot;
  saving.value = true;
  try {
    const { menu: _m, ...rest } = form;
    const body = { ...rest, slug: slugify(form.slug), skills: form.skills.filter(s => String(s.label || '').trim()), menu: changed ? menu : null };
    const r = await http.put('portfolio/site', body);
    site.value = r.site;
    fill(r.site);
    message.success('Settings saved.');
  } catch (e) {
    message.error(firstError(e) || 'Could not save the settings.');
  } finally {
    saving.value = false;
  }
}

const imgBusy = ref(null);
const IMAGE_FIELD = { logo: 'logo_url', about: 'about_image_url', background: 'bg_image_url' };
async function uploadImage(kind, file) {
  const fd = new FormData();
  fd.append('image', file);
  imgBusy.value = kind;
  try {
    const r = await http.postForm(`portfolio/site/image?kind=${kind}`, fd);
    site.value = { ...site.value, [IMAGE_FIELD[kind]]: r.url };
  } catch (e) { message.error(firstError(e) || 'Could not upload it.'); } finally { imgBusy.value = null; }
}
async function removeImage(kind) {
  try {
    await http.delete('portfolio/site/image', { kind });
    site.value = { ...site.value, [IMAGE_FIELD[kind]]: null };
  } catch (e) { message.error('Could not remove it.'); }
}

onMounted(load);
</script>

<style scoped>
.ps-tab { display: inline-flex; align-items: center; gap: 8px; }
.ps-card { max-width: 1100px; }
.ps-muted { color: #8c8c8c; font-size: 12px; }
.ps-row { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.ps-color { display: flex; align-items: center; gap: 8px; }
.ps-color input[type='color'] { width: 44px; height: 30px; padding: 0; border: 1px solid #d9d9d9; border-radius: 4px; background: none; cursor: pointer; }
.ps-themes { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
.ps-theme { border: 2px solid #f0f0f0; border-radius: 10px; padding: 10px; cursor: pointer; transition: border-color .2s; }
.ps-theme:hover { border-color: #91caff; }
.ps-theme.on { border-color: #1677ff; }
.ps-theme__name { font-weight: 600; margin: 8px 0 2px; }
/* Small drawings of each theme. */
.ps-theme__shot { height: 130px; border-radius: 6px; padding: 10px; display: flex; flex-direction: column; gap: 6px; position: relative; overflow: hidden; }
.ps-theme__shot i, .ps-theme__shot b, .ps-theme__shot em, .ps-theme__shot .wide { display: block; }
.shot-minimal { background: #101010; align-items: center; }
.shot-minimal .c { position: absolute; width: 18px; height: 18px; border: 3px solid #333; }
.shot-minimal .tl { top: 4px; left: 4px; border-right: 0; border-bottom: 0; }
.shot-minimal .tr { top: 4px; right: 4px; border-left: 0; border-bottom: 0; }
.shot-minimal .logo { width: 44px; height: 22px; border-radius: 4px; background: #d9d9d9; margin-bottom: 4px; }
.shot-minimal .row, .ps-theme__shot .row { display: flex; gap: 4px; width: 100%; flex: 1; }
.shot-minimal .row i { flex: 1; background: #5c5c5c; }
.shot-grid { background: #fff; border: 1px solid #f0f0f0; }
.ps-theme__shot .bar { display: flex; justify-content: space-between; align-items: center; height: 12px; }
.ps-theme__shot .bar b { width: 54px; height: 6px; background: #222; }
.ps-theme__shot .bar em { width: 40px; height: 6px; background: #bbb; }
.ps-theme__shot .bar.center em { width: 30px; }
.shot-grid .row i { flex: 1; background: #8aa1b4; }
.shot-grid .row i:nth-child(2) { background: #6f8f6a; }
.shot-showcase { background: #fff; border: 1px solid #f0f0f0; padding: 10px 0 0; }
.shot-showcase .bar { padding: 0 10px; }
.shot-showcase .wide { flex: 1; background: linear-gradient(#a9b9c9, #cdb98f); }
.shot-showcase .wide + .wide { background: linear-gradient(#273b2c, #49613b); }
.ps-mi { border: 1px solid #f0f0f0; border-radius: 8px; padding: 8px; margin-bottom: 8px; }
.ps-mi.group { background: #fffbe6; border-color: #ffe58f; }
.ps-mi.off { opacity: .55; }
.ps-mi.child { background: #fff; margin: 0 0 6px; }
.ps-children { margin: 8px 0 0 28px; }
:deep(.ps-mi__row) { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
:deep(.ps-slot) { display: flex; align-items: center; gap: 12px; }
:deep(.ps-slot__empty) { width: 120px; height: 90px; border: 1px dashed #d9d9d9; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 12px; }
@media (max-width: 767px) {
  .ps-tabs :deep(.ant-tabs-nav) { width: 100%; }
}
</style>
