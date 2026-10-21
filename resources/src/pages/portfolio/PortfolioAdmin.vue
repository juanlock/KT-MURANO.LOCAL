<template>
  <div class="page">
    <PageHeader title="Portfolio" :breadcrumb="['Portfolio']">
      <template #extra>
        <a-space wrap>
          <a-tag v-if="site" :color="site.published ? 'green' : 'orange'">{{ site.published ? 'Published' : 'Not published' }}</a-tag>
          <a-button v-if="site" :href="site.public_url" target="_blank">
            <template #icon><ExportOutlined /></template> {{ site.published ? 'Open website' : 'Preview website' }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-tabs :active-key="tab" @change="goTab">
        <!-- ============ Home sections (the Home page, top to bottom) ============ -->
        <a-tab-pane key="sections" :tab="`Home sections (${sections.length})`">
          <div class="pa-bar">
            <span class="pa-muted">The Home page of the website, top to bottom. Add, edit, hide, move or remove sections.</span>
            <a-dropdown :trigger="['click']">
              <a-button type="primary"><template #icon><PlusOutlined /></template> Add section</a-button>
              <template #overlay>
                <a-menu @click="({ key }) => addSection(key)">
                  <a-menu-item v-for="t in SECTION_TYPES" :key="t.value">{{ t.label }} <span class="pa-muted">— {{ t.help }}</span></a-menu-item>
                </a-menu>
              </template>
            </a-dropdown>
          </div>
          <a-empty v-if="!sections.length" description="No sections — the Home page is empty. Add one (e.g. Photos gallery)." />
          <div v-for="(s, i) in sections" :key="s.id" class="pa-sec" :class="{ off: !s.visible }">
            <div class="pa-sec__head">
              <a-tag color="purple">{{ sectionLabel(s.type) }}</a-tag>
              <b class="pa-sec__title">{{ s.title || '(no title)' }}</b>
              <a-space :size="4" style="margin-left: auto">
                <a-tooltip title="Shown on the website"><a-switch size="small" :checked="s.visible" @change="v => saveSection({ ...s, visible: v })" /></a-tooltip>
                <a-button size="small" :disabled="i === 0" @click="moveSection(i, -1)">↑</a-button>
                <a-button size="small" :disabled="i === sections.length - 1" @click="moveSection(i, 1)">↓</a-button>
                <a-button size="small" @click="editingSection = editingSection && editingSection.id === s.id ? null : { ...s, settings: { ...s.settings } }"><template #icon><EditOutlined /></template></a-button>
                <a-popconfirm title="Remove this section from the Home page?" @confirm="removeSection(s)">
                  <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
                </a-popconfirm>
              </a-space>
            </div>
            <!-- Section editor -->
            <a-form v-if="editingSection && editingSection.id === s.id" layout="vertical" class="pa-sec__form">
              <a-row :gutter="12">
                <a-col :xs="24" :md="12"><a-form-item label="Title"><a-input v-model:value="editingSection.title" :maxlength="150" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item label="Subtitle"><a-input v-model:value="editingSection.subtitle" :maxlength="255" /></a-form-item></a-col>
                <a-col v-if="['text', 'image_text'].includes(s.type)" :span="24">
                  <a-form-item label="Text" extra="Leave an empty line between paragraphs."><a-textarea v-model:value="editingSection.body" :rows="5" :maxlength="10000" /></a-form-item>
                </a-col>
                <a-col v-if="s.type === 'video'" :span="24">
                  <a-form-item label="Video link (YouTube / Vimeo)"><a-input v-model:value="editingSection.video_url" :maxlength="255" /></a-form-item>
                </a-col>
                <template v-if="s.type === 'gallery'">
                  <a-col :xs="12" :md="8"><a-form-item label="Only photos marked 'On the Home page'"><a-switch v-model:checked="editingSection.settings.featured_only" /></a-form-item></a-col>
                  <a-col :xs="12" :md="8"><a-form-item label="'All' filter button"><a-switch v-model:checked="editingSection.settings.show_all" /></a-form-item></a-col>
                </template>
                <template v-if="['text', 'image', 'image_text', 'contact'].includes(s.type)">
                  <a-col :xs="24" :md="8"><a-form-item label="Button text"><a-input v-model:value="editingSection.button_label" :maxlength="60" placeholder="(no button)" /></a-form-item></a-col>
                  <a-col v-if="s.type !== 'contact'" :xs="24" :md="10">
                    <a-form-item label="Button link"><a-input v-model:value="editingSection.button_link" :maxlength="255" :placeholder="`/portfolio/${site?.slug || ''}/work`" /></a-form-item>
                  </a-col>
                </template>
                <a-col :xs="24" :md="6">
                  <a-form-item label="Background color">
                    <div class="pa-color">
                      <input v-model="editingSection.bg_color" type="color" />
                      <a v-if="editingSection.bg_color" @click="editingSection.bg_color = null">none</a>
                    </div>
                  </a-form-item>
                </a-col>
                <a-col v-if="['image', 'image_text'].includes(s.type)" :span="24">
                  <a-form-item label="Picture">
                    <ImageSlot :url="s.image_url" :busy="secImgBusy === s.id" @upload="f => uploadSectionImage(s, f)" @remove="removeSectionImage(s)" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-space>
                <a-button type="primary" :loading="savingSection" @click="saveSection(editingSection, true)">Save section</a-button>
                <a-button @click="editingSection = null">Close</a-button>
              </a-space>
            </a-form>
          </div>
        </a-tab-pane>

        <!-- ============ Photos ============ -->
        <a-tab-pane key="projects" :tab="`Photos (${projects.length})`">
          <div class="pa-bar">
            <a-segmented v-model:value="catFilter" :options="catFilterOptions" />
            <a-button type="primary" @click="openProject(null)"><template #icon><PlusOutlined /></template> New photo</a-button>
          </div>
          <a-empty v-if="!shownProjects.length" description="No photos yet — press New photo, then add its pictures." />
          <div v-else class="pa-grid">
            <div v-for="p in shownProjects" :key="p.id" class="pa-card" :class="{ off: !p.published }">
              <div class="pa-thumb" @click="openProject(p)">
                <img v-if="p.cover_url" :src="p.cover_url" :alt="p.title" loading="lazy" />
                <div v-else class="pa-nothumb">No photo yet</div>
                <a-tag class="pa-type">{{ TYPE_LABEL[p.type] }}</a-tag>
                <a-tag v-if="p.featured" color="gold" class="pa-feat">Home</a-tag>
              </div>
              <div class="pa-title" :title="p.title">{{ p.title }}</div>
              <div class="pa-muted">{{ catNames(p.categories) || 'No category' }}</div>
              <div class="pa-actions">
                <a-switch size="small" :checked="p.published" checked-children="On" un-checked-children="Off" @change="v => quickSave(p, { published: v })" />
                <a-space :size="4">
                  <a-button size="small" :disabled="indexOf(p) === 0" @click="moveProject(p, -1)"><template #icon><ArrowLeftOutlined /></template></a-button>
                  <a-button size="small" :disabled="indexOf(p) === projects.length - 1" @click="moveProject(p, 1)"><template #icon><ArrowRightOutlined /></template></a-button>
                  <a-button size="small" @click="openProject(p)"><template #icon><EditOutlined /></template></a-button>
                  <a-popconfirm title="Delete this photo and its pictures?" @confirm="removeProject(p)">
                    <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
                  </a-popconfirm>
                </a-space>
              </div>
            </div>
          </div>
        </a-tab-pane>

        <!-- ============ Categories (the gallery filter buttons) ============ -->
        <a-tab-pane key="categories" :tab="`Categories (${categories.length})`">
          <a-card size="small" style="max-width: 620px">
            <div class="pa-muted" style="margin-bottom: 10px">The filter buttons above the gallery (e.g. Hair Style, Nails, Barber, Skin Care), in this order.</div>
            <div v-for="(c, i) in categories" :key="c.id" class="pa-cat">
              <a-input v-model:value="c.name" size="small" style="flex: 1" @blur="renameCategory(c)" @press-enter="$event.target.blur()" />
              <a-tag>{{ projects.filter(p => p.categories.includes(c.id)).length }} photos</a-tag>
              <a-button size="small" :disabled="i === 0" @click="moveCategory(i, -1)">↑</a-button>
              <a-button size="small" :disabled="i === categories.length - 1" @click="moveCategory(i, 1)">↓</a-button>
              <a-popconfirm title="Delete this category? The photos stay." @confirm="removeCategory(c)">
                <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
              </a-popconfirm>
            </div>
            <div class="pa-cat" style="margin-top: 10px">
              <a-input v-model:value="newCategory" size="small" placeholder="New category, e.g. Hair Style" style="flex: 1" @press-enter="addCategory" />
              <a-button size="small" type="primary" :disabled="!newCategory.trim()" @click="addCategory"><template #icon><PlusOutlined /></template> Add</a-button>
            </div>
          </a-card>
        </a-tab-pane>

        <!-- ============ Messages (Contact form) ============ -->
        <a-tab-pane key="messages">
          <template #tab>Messages <a-badge v-if="unread" :count="unread" :offset="[6, -2]" /></template>
          <a-table :columns="msgColumns" :data-source="messages" size="small" :row-key="r => r.id" :loading="msgLoading" :pagination="{ pageSize: 20 }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'created_at'">{{ String(record.created_at).slice(0, 16) }}</template>
              <template v-else-if="column.key === 'from'">
                <b :style="record.is_read ? '' : 'color: #1677ff'">{{ record.name }}</b>
                <div class="pa-muted">{{ [record.email, record.phone].filter(Boolean).join(' · ') }}</div>
              </template>
              <template v-else-if="column.key === 'message'"><div class="pa-msg">{{ record.message }}</div></template>
              <template v-else-if="column.key === 'actions'">
                <a-space :size="4">
                  <a-button size="small" @click="markRead(record, !record.is_read)">{{ record.is_read ? 'Unread' : 'Read' }}</a-button>
                  <a-button v-if="record.email" size="small" :href="`mailto:${record.email}`">Reply</a-button>
                  <a-popconfirm title="Delete this message?" @confirm="removeMessage(record)">
                    <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-tab-pane>
      </a-tabs>
    </a-spin>

    <!-- Photo editor -->
    <a-drawer v-model:open="editorOpen" :title="editing && editing.id ? 'Edit photo' : 'New photo'" :width="720" destroy-on-close>
      <a-form v-if="editing" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="16"><a-form-item label="Title" required><a-input v-model:value="editing.title" :maxlength="150" /></a-form-item></a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Shown as">
              <a-select v-model:value="editing.type" :options="TYPE_OPTIONS" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item label="Categories (filter buttons)">
              <a-select v-model:value="editing.categories" mode="multiple" :options="categoryOptions" placeholder="Pick one or more" />
            </a-form-item>
          </a-col>
          <a-col v-if="editing.type === 'video'" :span="24">
            <a-form-item label="Video link" extra="YouTube or Vimeo link (it's turned into the embedded player).">
              <a-input v-model:value="editing.video_url" :maxlength="255" placeholder="https://www.youtube.com/watch?v=…" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item label="Website link" extra="Optional: shows a “Visit website” button on the project page (https://… or /path).">
              <a-input v-model:value="editing.website_url" :maxlength="255" placeholder="https://www.example.com" />
            </a-form-item>
          </a-col>
          <a-col :span="24"><a-form-item label="Description"><a-textarea v-model:value="editing.description" :rows="5" :maxlength="10000" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item label="Client"><a-input v-model:value="editing.client" :maxlength="150" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item label="Location"><a-input v-model:value="editing.location" :maxlength="150" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item label="Date"><a-date-picker v-model:value="editing.project_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="8"><a-form-item label="On the Home page"><a-switch v-model:checked="editing.featured" /></a-form-item></a-col>
          <a-col :xs="12" :md="8"><a-form-item label="Published"><a-switch v-model:checked="editing.published" /></a-form-item></a-col>
        </a-row>
        <a-button type="primary" :loading="savingProject" @click="saveProject">{{ editing.id ? 'Save' : 'Create, then add photos' }}</a-button>

        <template v-if="editing.id">
          <a-divider />
          <div class="pa-subtitle">Cover (the picture in the gallery)</div>
          <ImageSlot :url="editing.cover_url" :busy="coverBusy" :removable="false" @upload="uploadCover" />

          <div class="pa-subtitle" style="margin-top: 16px">
            Photos ({{ editing.media.length }})
            <span class="pa-muted"> — {{ editing.type === 'slider' ? 'shown as a slider, in this order' : 'with "Slider" chosen above they are shown one after another' }}</span>
          </div>
          <a-upload :show-upload-list="false" multiple accept="image/*" :before-upload="queueMedia">
            <a-button :loading="mediaBusy"><template #icon><UploadOutlined /></template> Add photos</a-button>
          </a-upload>
          <div class="pa-media">
            <div v-for="(m, i) in editing.media" :key="m.id" class="pa-media__item">
              <a-image :src="m.url" :width="120" :height="90" style="object-fit: cover; border-radius: 6px" />
              <a-space :size="2">
                <a-button size="small" :disabled="i === 0" @click="moveMedia(i, -1)"><template #icon><ArrowLeftOutlined /></template></a-button>
                <a-button size="small" :disabled="i === editing.media.length - 1" @click="moveMedia(i, 1)"><template #icon><ArrowRightOutlined /></template></a-button>
                <a-popconfirm title="Remove this photo?" @confirm="removeMedia(m)">
                  <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
                </a-popconfirm>
              </a-space>
            </div>
          </div>
        </template>
      </a-form>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Portfolio module (back office): the company's public portfolio website at
 * /portfolio/{slug} -- works (cover + photos / slider / video + details),
 * categories (gallery filter buttons), website texts (logo, About, Contact,
 * social links, dark / light) and the Contact form messages.
 * API: /api/portfolio/* (PortfolioController). Permission portfolio_manage.
 */
import { ref, computed, onMounted, h, defineComponent } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined, ExportOutlined, ArrowLeftOutlined, ArrowRightOutlined,
} from '@ant-design/icons-vue';
import { Upload as AUpload, Button as AButton, Image as AImage, Space as ASpace } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';

// A picture with Upload / Change and Remove buttons.
// a-image merges `style` into the <img> with Object.assign, so it must be an
// object: a string crashed the whole Website tab (blank page) once a logo existed.
const SLOT_IMG_STYLE = { objectFit: 'contain', borderRadius: '6px', background: '#111' };
const ImageSlot = defineComponent({
  props: { url: String, busy: Boolean, removable: { type: Boolean, default: true } },
  emits: ['upload', 'remove'],
  setup(props, { emit }) {
    return () => h('div', { class: 'pa-slot' }, [
      props.url ? h(AImage, { src: props.url, width: 120, height: 90, style: SLOT_IMG_STYLE }) : h('div', { class: 'pa-slot__empty' }, 'No picture'),
      h(ASpace, { direction: 'vertical', size: 4 }, () => [
        h(AUpload, { showUploadList: false, accept: 'image/*', beforeUpload: f => { emit('upload', f); return false; } },
          () => h(AButton, { size: 'small', loading: props.busy }, () => [h(UploadOutlined), props.url ? ' Change' : ' Upload'])),
        props.url && props.removable ? h(AButton, { size: 'small', danger: true, onClick: () => emit('remove') }, () => 'Remove') : null,
      ]),
    ]);
  },
});

const TYPE_LABEL = { image: 'Image', slider: 'Slider', video: 'Video' };
const TYPE_OPTIONS = [
  { value: 'image', label: 'One image' }, { value: 'slider', label: 'Slider (several photos)' }, { value: 'video', label: 'Video' },
];
// One menu entry per tab (Portfolio -> Home sections / Photos / Categories / Messages; Settings is its own page).
const TAB_PATHS = { sections: '/portfolio/sections', projects: '/portfolio/photos', categories: '/portfolio/categories', messages: '/portfolio/messages' };
const route = useRoute();
const router = useRouter();
const tab = computed(() => route.meta.tab || 'sections');
function goTab(key) {
  if (TAB_PATHS[key] && route.path !== TAB_PATHS[key]) router.push(TAB_PATHS[key]);
}

const loading = ref(true);
const site = ref(null);
const categories = ref([]);
const projects = ref([]);
const sections = ref([]);
const unread = ref(0);

async function load() {
  loading.value = true;
  try {
    const r = await http.get('portfolio');
    site.value = r.site;
    categories.value = r.categories || [];
    projects.value = r.projects || [];
    sections.value = r.sections || [];
    unread.value = r.unread || 0;
  } catch (e) {
    message.error(e?.data?.message || 'Could not load the portfolio.');
  } finally {
    loading.value = false;
  }
}

/* -------- Home sections */
const SECTION_TYPES = [
  { value: 'gallery', label: 'Photos gallery', help: 'your photos with the filter buttons' },
  { value: 'text', label: 'Text', help: 'a title and paragraphs' },
  { value: 'image', label: 'Image', help: 'a wide picture with a caption' },
  { value: 'image_text', label: 'Image + text', help: 'picture on the left, text on the right' },
  { value: 'video', label: 'Video', help: 'YouTube / Vimeo' },
  { value: 'contact', label: 'Contact', help: 'address, phones, emails' },
];
const sectionLabel = t => SECTION_TYPES.find(x => x.value === t)?.label || t;
const editingSection = ref(null);
const savingSection = ref(false);
const sectionBody = s => ({
  type: s.type, title: s.title || null, subtitle: s.subtitle || null, body: s.body || null, video_url: s.video_url || null,
  button_label: s.button_label || null, button_link: s.button_link || null, bg_color: s.bg_color || null,
  settings: s.settings || {}, visible: !!s.visible,
});
async function addSection(type) {
  try {
    const r = await http.post('portfolio/sections', { type, visible: true, settings: { featured_only: true, show_all: false } });
    sections.value.push(r.section);
    editingSection.value = { ...r.section, settings: { ...r.section.settings } };
  } catch (e) { message.error(firstError(e) || 'Could not add the section.'); }
}
async function saveSection(s, close = false) {
  savingSection.value = true;
  try {
    const r = await http.put(`portfolio/sections/${s.id}`, sectionBody(s));
    const i = sections.value.findIndex(x => x.id === s.id);
    if (i >= 0) sections.value[i] = r.section;
    if (close) { editingSection.value = null; message.success('Section saved.'); }
  } catch (e) { message.error(firstError(e) || 'Could not save the section.'); } finally { savingSection.value = false; }
}
async function moveSection(i, dir) {
  const list = sections.value;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  try { await http.post('portfolio/sections/reorder', { ids: list.map(s => s.id) }); } catch (e) { load(); }
}
async function removeSection(s) {
  try {
    await http.delete(`portfolio/sections/${s.id}`);
    sections.value = sections.value.filter(x => x.id !== s.id);
    if (editingSection.value?.id === s.id) editingSection.value = null;
  } catch (e) { message.error('Could not remove it.'); }
}
const secImgBusy = ref(null);
async function uploadSectionImage(s, file) {
  const fd = new FormData();
  fd.append('image', file);
  secImgBusy.value = s.id;
  try {
    const r = await http.postForm(`portfolio/sections/${s.id}/image`, fd);
    s.image_url = r.url;
  } catch (e) { message.error(firstError(e) || 'Could not upload it.'); } finally { secImgBusy.value = null; }
}
async function removeSectionImage(s) {
  try {
    await http.delete(`portfolio/sections/${s.id}/image`);
    s.image_url = null;
  } catch (e) { message.error('Could not remove it.'); }
}

/* -------- works */
const catFilter = ref('all');
const catFilterOptions = computed(() => [{ value: 'all', label: 'All' }, ...categories.value.map(c => ({ value: c.id, label: c.name }))]);
const categoryOptions = computed(() => sortByLabel(categories.value.map(c => ({ value: c.id, label: c.name }))));
const shownProjects = computed(() => (catFilter.value === 'all' ? projects.value : projects.value.filter(p => p.categories.includes(catFilter.value))));
const catNames = ids => categories.value.filter(c => ids.includes(c.id)).map(c => c.name).join(', ');
const indexOf = p => projects.value.indexOf(p);

const editorOpen = ref(false);
const editing = ref(null);
const savingProject = ref(false);
function openProject(p) {
  editing.value = p
    ? { ...p, categories: [...p.categories], media: [...p.media] }
    : { id: null, title: '', type: 'image', categories: typeof catFilter.value === 'number' ? [catFilter.value] : [], description: '', client: '', location: '', project_date: null, video_url: '', website_url: '', featured: true, published: true, media: [], cover_url: null };
  editorOpen.value = true;
}
const projectBody = p => ({
  title: p.title, type: p.type, categories: p.categories, description: p.description || null, client: p.client || null,
  location: p.location || null, project_date: p.project_date || null, video_url: p.video_url || null, website_url: p.website_url || null,
  featured: !!p.featured, published: !!p.published,
});
async function saveProject() {
  const p = editing.value;
  if (!String(p.title || '').trim()) { message.error('Give the photo a title.'); return; }
  savingProject.value = true;
  try {
    const r = p.id ? await http.put(`portfolio/projects/${p.id}`, projectBody(p)) : await http.post('portfolio/projects', projectBody(p));
    const saved = r.project;
    const i = projects.value.findIndex(x => x.id === saved.id);
    if (i >= 0) projects.value[i] = saved; else projects.value.push(saved);
    editing.value = { ...saved, categories: [...saved.categories], media: [...saved.media] };
    message.success(p.id ? 'Saved.' : 'Created — now add its cover / photos.');
  } catch (e) {
    message.error(firstError(e) || 'Could not save the photo.');
  } finally {
    savingProject.value = false;
  }
}
async function quickSave(p, change) {
  try {
    const r = await http.put(`portfolio/projects/${p.id}`, projectBody({ ...p, ...change }));
    Object.assign(p, r.project);
  } catch (e) { message.error(firstError(e) || 'Could not save.'); }
}
async function moveProject(p, dir) {
  const list = projects.value;
  const i = list.indexOf(p);
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  try { await http.post('portfolio/projects/reorder', { ids: list.map(x => x.id) }); } catch (e) { load(); }
}
async function removeProject(p) {
  try {
    await http.delete(`portfolio/projects/${p.id}`);
    projects.value = projects.value.filter(x => x.id !== p.id);
  } catch (e) { message.error('Could not delete it.'); }
}
function syncEditing() {
  const i = projects.value.findIndex(x => x.id === editing.value.id);
  if (i >= 0) projects.value[i] = { ...projects.value[i], cover_url: editing.value.cover_url, cover: editing.value.cover, media: [...editing.value.media] };
}
const coverBusy = ref(false);
async function uploadCover(file) {
  const fd = new FormData();
  fd.append('image', file);
  coverBusy.value = true;
  try {
    const r = await http.postForm(`portfolio/projects/${editing.value.id}/cover`, fd);
    editing.value.cover_url = r.url;
    syncEditing();
  } catch (e) { message.error(firstError(e) || 'Could not upload the cover.'); } finally { coverBusy.value = false; }
}
const mediaBusy = ref(false);
let pending = [];
let timer = null;
function queueMedia(file) {
  pending.push(file);
  clearTimeout(timer);
  timer = setTimeout(sendMedia, 60);
  return false;
}
async function sendMedia() {
  const files = pending;
  pending = [];
  if (!files.length || !editing.value?.id) return;
  const fd = new FormData();
  files.forEach(f => fd.append('images[]', f));
  mediaBusy.value = true;
  try {
    const r = await http.postForm(`portfolio/projects/${editing.value.id}/media`, fd);
    editing.value.media.push(...(r.media || []));
    if (!editing.value.cover_url && r.media?.length) editing.value.cover_url = r.media[0].url;
    syncEditing();
    message.success(`${r.media.length} photo${r.media.length === 1 ? '' : 's'} added.`);
  } catch (e) { message.error(firstError(e) || 'Could not upload the photos.'); } finally { mediaBusy.value = false; }
}
async function moveMedia(i, dir) {
  const list = editing.value.media;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  syncEditing();
  try { await http.post(`portfolio/projects/${editing.value.id}/media/reorder`, { ids: list.map(m => m.id) }); } catch (e) { load(); }
}
async function removeMedia(m) {
  try {
    await http.delete(`portfolio/media/${m.id}`);
    editing.value.media = editing.value.media.filter(x => x.id !== m.id);
    syncEditing();
  } catch (e) { message.error('Could not remove it.'); }
}

/* -------- categories */
const newCategory = ref('');
async function addCategory() {
  const name = newCategory.value.trim();
  if (!name) return;
  try {
    categories.value.push(await http.post('portfolio/categories', { name }));
    newCategory.value = '';
  } catch (e) { message.error(firstError(e) || 'Could not add it.'); }
}
async function renameCategory(c) {
  if (!String(c.name || '').trim()) { load(); return; }
  try { await http.put(`portfolio/categories/${c.id}`, { name: c.name.trim() }); } catch (e) { message.error(firstError(e) || 'Could not rename it.'); load(); }
}
async function moveCategory(i, dir) {
  const list = categories.value;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  try { await http.post('portfolio/categories/reorder', { ids: list.map(c => c.id) }); } catch (e) { load(); }
}
async function removeCategory(c) {
  try {
    await http.delete(`portfolio/categories/${c.id}`);
    categories.value = categories.value.filter(x => x.id !== c.id);
    projects.value.forEach(p => { p.categories = p.categories.filter(id => id !== c.id); });
    if (catFilter.value === c.id) catFilter.value = 'all';
  } catch (e) { message.error('Could not delete it.'); }
}

/* -------- messages */
const messages = ref([]);
const msgLoading = ref(false);
const msgColumns = [
  { title: 'Date', key: 'created_at', width: 140 },
  { title: 'From', key: 'from', width: 230 },
  { title: 'Message', key: 'message' },
  { title: '', key: 'actions', width: 210 },
];
async function loadMessages() {
  msgLoading.value = true;
  try {
    messages.value = (await http.get('portfolio/messages')).messages || [];
    unread.value = messages.value.filter(m => !m.is_read).length;
  } catch (e) { /* tab stays empty */ } finally { msgLoading.value = false; }
}
async function markRead(m, read) {
  try {
    await http.put(`portfolio/messages/${m.id}`, { is_read: read });
    m.is_read = read;
    unread.value = messages.value.filter(x => !x.is_read).length;
  } catch (e) { message.error('Could not update it.'); }
}
async function removeMessage(m) {
  try {
    await http.delete(`portfolio/messages/${m.id}`);
    messages.value = messages.value.filter(x => x.id !== m.id);
    unread.value = messages.value.filter(x => !x.is_read).length;
  } catch (e) { message.error('Could not delete it.'); }
}

function firstError(e) {
  const errs = e?.data?.errors;
  return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message;
}

onMounted(() => { load(); loadMessages(); });
</script>

<style scoped>
.pa-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.pa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.pa-card { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.pa-card.off .pa-thumb img { opacity: 0.4; }
.pa-thumb { position: relative; aspect-ratio: 4 / 3; border-radius: 6px; overflow: hidden; background: #f0f0f0; cursor: pointer; }
.pa-thumb img { width: 100%; height: 100%; object-fit: cover; }
.pa-nothumb { display: flex; align-items: center; justify-content: center; height: 100%; color: #999; font-size: 13px; }
.pa-type { position: absolute; top: 6px; left: 6px; margin: 0; }
.pa-feat { position: absolute; top: 6px; right: 6px; margin: 0; }
.pa-title { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pa-muted { color: #8c8c8c; font-size: 12px; }
.pa-actions { display: flex; justify-content: space-between; align-items: center; }
.pa-cat { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.pa-subtitle { font-weight: 600; margin-bottom: 8px; }
.pa-media { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.pa-media__item { display: flex; flex-direction: column; gap: 4px; align-items: center; }
.pa-msg { white-space: pre-wrap; max-height: 120px; overflow: auto; }
.pa-sec { border: 1px solid rgba(5, 5, 5, 0.1); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
.pa-sec.off { opacity: 0.55; }
.pa-sec__head { display: flex; align-items: center; gap: 8px; }
.pa-sec__title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pa-sec__form { margin-top: 12px; padding-top: 12px; border-top: 1px dashed rgba(5, 5, 5, 0.12); }
.pa-color { display: flex; align-items: center; gap: 8px; }
.pa-color input[type='color'] { width: 44px; height: 30px; padding: 0; border: 1px solid #d9d9d9; border-radius: 4px; background: none; cursor: pointer; }
:deep(.pa-slot) { display: flex; align-items: center; gap: 12px; }
:deep(.pa-slot__empty) { width: 120px; height: 90px; border: 1px dashed #d9d9d9; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 12px; }
</style>
