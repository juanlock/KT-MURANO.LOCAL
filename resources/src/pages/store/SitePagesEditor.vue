<template>
  <div>
    <a-spin v-if="loadingList" style="display: block; padding: 48px 0" />
    <a-empty v-else-if="!available" description="This company's front page is not an imported website." />

    <a-card v-else size="small" :body-style="{ padding: '10px' }" :class="['sp-root', { full }]">
      <!-- toolbar -->
      <div class="sp-bar">
        <a-space wrap>
          <a-tooltip :title="full ? 'Exit full screen' : 'Full screen'">
            <a-button @click="full = !full">
              <template #icon><FullscreenExitOutlined v-if="full" /><FullscreenOutlined v-else /></template>
            </a-button>
          </a-tooltip>
          <span class="sp-bar-label">Page</span>
          <a-select :value="current?.file" style="min-width: 240px" :options="pageOptions" @change="f => open(f)" />
          <a-segmented v-model:value="view" :options="[{ label: 'Visual', value: 'visual' }, { label: 'List', value: 'list' }]" />
          <a-segmented
            v-if="view === 'visual'" v-model:value="device"
            :options="[{ label: 'Desktop', value: 'desktop' }, { label: 'Mobile', value: 'mobile' }]"
          />
        </a-space>
        <a-space wrap>
          <a-button v-if="current" :href="current.url" target="_blank" rel="noopener">
            <template #icon><EyeOutlined /></template> View live
          </a-button>
          <a-button v-if="dirtyCount" @click="discard">Discard changes</a-button>
          <a-popconfirm
            v-if="current?.edited" title="Undo ALL saved edits of this page and put back the imported version?"
            ok-text="Restore" ok-type="danger" @confirm="restore"
          >
            <a-button danger :loading="restoring">Restore original</a-button>
          </a-popconfirm>
          <a-badge :count="dirtyCount" :offset="[-4, 2]">
            <a-button type="primary" :loading="saving" :disabled="!dirtyCount" @click="save">
              <template #icon><SaveOutlined /></template> {{ $t('Save') }}
            </a-button>
          </a-badge>
        </a-space>
      </div>

      <a-spin :spinning="loadingPage">
        <!-- ===================== VISUAL ===================== -->
        <div v-show="view === 'visual'" class="sp-visual">
          <div class="sp-stage" :class="device">
            <iframe
              v-if="current?.edit_html" ref="frame" class="sp-frame" :srcdoc="current.edit_html"
              sandbox="allow-same-origin allow-scripts" title="Page editor" @load="wireFrame"
            />
          </div>

          <!-- side panel -->
          <div class="sp-side">
            <template v-if="sel?.type === 'text'">
              <div class="sp-side-title">Text</div>
              <a-textarea v-model:value="edits.texts[sel.id]" :auto-size="{ minRows: 3, maxRows: 12 }" @input="pushText(sel.id)" />
              <div class="sp-side-hint">Or type directly on the page.</div>
              <template v-if="selLink">
                <div class="sp-side-title" style="margin-top: 14px">This text is a link — it goes to</div>
                <a-auto-complete v-model:value="edits.links[selLink.id]" :options="linkTargets" style="width: 100%" placeholder="page.html, #section, https://…" />
              </template>
              <a-button v-if="isTextChanged(sel.id)" size="small" style="margin-top: 12px" @click="undoText(sel.id)">Undo this text</a-button>
            </template>

            <template v-else-if="sel?.type === 'image'">
              <div class="sp-side-title">Picture</div>
              <div class="sp-side-img"><img :src="previewOf(selImage)" alt="" /></div>
              <a-space direction="vertical" style="width: 100%">
                <a-upload :show-upload-list="false" accept="image/jpeg,image/png,image/webp,image/gif" :before-upload="f => upload(selImage, f)">
                  <a-button block :loading="uploadingId === selImage.id"><template #icon><UploadOutlined /></template> Upload a new picture</a-button>
                </a-upload>
                <a-button block @click="pickFromGallery(selImage)"><template #icon><PictureOutlined /></template> Choose from Gallery</a-button>
                <template v-if="!selImage.bg">
                  <div class="sp-side-title" style="margin-top: 6px">{{ selImage.popup ? 'Caption' : 'Description (for Google / blind visitors)' }}</div>
                  <a-input v-model:value="edits.images[selImage.id].alt" />
                </template>
                <template v-if="selLink && !selImage.popup">
                  <div class="sp-side-title" style="margin-top: 6px">Clicking the picture goes to</div>
                  <a-auto-complete v-model:value="edits.links[selLink.id]" :options="linkTargets" style="width: 100%" />
                </template>
                <a-button v-if="isImageChanged(selImage)" size="small" @click="undoImage(selImage)">Undo this picture</a-button>
              </a-space>
            </template>

            <template v-else-if="sel?.type === 'link'">
              <div class="sp-side-title">Link (button / icon)</div>
              <div class="sp-side-hint" style="margin-bottom: 8px">{{ selLink.label || 'No text — an icon or button.' }}</div>
              <a-auto-complete v-model:value="edits.links[selLink.id]" :options="linkTargets" style="width: 100%" placeholder="page.html, #section, https://…" />
              <a-button v-if="edits.links[selLink.id] !== selLink.href" size="small" style="margin-top: 12px" @click="edits.links[selLink.id] = selLink.href">Undo this link</a-button>
            </template>

            <template v-else>
              <div class="sp-steps">
                <div><span>1</span> <b>Click any text</b> on the page and type over it.</div>
                <div><span>2</span> <b>Click a picture</b> to upload a new one or choose one from the Gallery.</div>
                <div><span>3</span> Press <b>Save</b>. Changed spots are outlined in <em class="sp-orange">orange</em>.</div>
              </div>
              <div v-if="titleText" style="margin-top: 16px">
                <div class="sp-side-title">Browser tab title</div>
                <a-input v-model:value="edits.texts[titleText.id]" />
              </div>
              <div class="sp-side-hint" style="margin-top: 16px">
                The menu at the top is edited in the <b>Website menu</b> tab.
              </div>
              <a-button v-if="current?.sections?.length" block style="margin-top: 14px" @click="view = 'list'; tab = 'sections'">
                Page sections &amp; embeds ({{ current.sections.length }})
              </a-button>
              <div v-if="current?.sections?.length" class="sp-side-hint">Change a whole block of the page, e.g. replace a booking calendar.</div>
            </template>
            <a-button v-if="sel" type="link" size="small" style="padding: 0; margin-top: 16px" @click="select(null)">← Back to help</a-button>
          </div>
        </div>

        <!-- ===================== LIST ===================== -->
        <div v-if="view === 'list' && current">
          <a-tabs v-model:activeKey="tab" size="small">
            <a-tab-pane key="texts" :tab="`Texts (${current.texts.length})`">
              <a-input-search v-model:value="filter" placeholder="Find text…" allow-clear size="small" style="max-width: 280px; margin-bottom: 10px" />
              <div v-for="t in filteredTexts" :key="t.id" class="sp-text">
                <a-tag class="sp-el">{{ t.tag || 'text' }}</a-tag>
                <a-textarea v-model:value="edits.texts[t.id]" :auto-size="{ minRows: 1, maxRows: 8 }" :class="{ changed: isTextChanged(t.id) }" />
              </div>
              <a-empty v-if="!filteredTexts.length" />
            </a-tab-pane>
            <a-tab-pane key="images" :tab="`Pictures (${current.images.length})`">
              <div class="sp-images">
                <div v-for="img in current.images" :key="img.id" class="sp-img" :class="{ changed: isImageChanged(img) }">
                  <a :href="previewOf(img)" target="_blank" rel="noopener" class="sp-thumb"><img :src="previewOf(img)" alt="" loading="lazy" /></a>
                  <a-space :size="4" wrap>
                    <a-upload :show-upload-list="false" accept="image/jpeg,image/png,image/webp,image/gif" :before-upload="f => upload(img, f)">
                      <a-button size="small" :loading="uploadingId === img.id"><template #icon><UploadOutlined /></template>Upload</a-button>
                    </a-upload>
                    <a-button size="small" @click="pickFromGallery(img)"><template #icon><PictureOutlined /></template>Gallery</a-button>
                    <a-button v-if="isImageChanged(img)" size="small" @click="undoImage(img)">Undo</a-button>
                  </a-space>
                </div>
              </div>
            </a-tab-pane>
            <a-tab-pane key="links" :tab="`Links (${current.links.length})`">
              <a-input-search v-model:value="filter" placeholder="Find link…" allow-clear size="small" style="max-width: 280px; margin-bottom: 10px" />
              <div v-for="l in filteredLinks" :key="l.id" class="sp-link">
                <span class="sp-link-label" :title="l.label">{{ l.label || '(no text)' }}</span>
                <a-input v-model:value="edits.links[l.id]" size="small" :class="{ changed: edits.links[l.id] !== l.href }" />
              </div>
            </a-tab-pane>
            <a-tab-pane key="sections" :tab="`Page sections (${(current.sections || []).length})`">
              <div class="sp-side-hint" style="margin-bottom: 10px">
                The blocks the page's menu jumps to (e.g. <b>#booking</b>) and every block with an embedded frame or script.
                Edit a block's HTML, or swap an old booking calendar for ours.
              </div>
              <a-select
                v-if="sectionKeys.length > 1" v-model:value="sectionKey" allow-clear placeholder="All sections"
                :options="sectionKeys" style="min-width: 220px; margin-bottom: 10px" size="small"
              />
              <div v-for="s in filteredSections" :key="s.id" class="sp-sec">
                <div class="sp-sec-head">
                  <a-tag color="blue">{{ s.key ? `#${s.key}` : 'embed' }}</a-tag>
                  <b>{{ s.label }}</b>
                  <code class="sp-sec-el">{{ s.element }}</code>
                </div>
                <div v-if="s.embeds.length" class="sp-sec-embeds">
                  <a-tag v-for="(e, n) in s.embeds" :key="n" :color="e.kind === 'iframe' ? 'purple' : 'orange'">
                    {{ e.kind === 'iframe' ? 'Frame' : 'Script' }}{{ e.src ? `: ${e.src}` : '' }}
                  </a-tag>
                </div>
                <div class="sp-sec-text">{{ sectionText(s) }}</div>
                <a-space wrap :size="6">
                  <a-button size="small" @click="editSection(s)">Edit HTML</a-button>
                  <a-button v-if="s.embeds.length" size="small" type="primary" ghost @click="editSection(s, true)">Use our booking calendar</a-button>
                </a-space>
              </div>
              <a-empty v-if="!filteredSections.length" description="No sections found on this page." />
            </a-tab-pane>
          </a-tabs>
        </div>
      </a-spin>
    </a-card>

    <!-- one section's HTML -->
    <a-modal
      v-model:open="secOpen" :title="secEdit ? `Section: ${secEdit.label}${secEdit.key ? ` (#${secEdit.key})` : ''}` : ''"
      width="900px" ok-text="Save section" :confirm-loading="secSaving" :ok-button-props="{ disabled: !secChanged }" @ok="saveSection"
    >
      <template v-if="secEdit">
        <a-space wrap style="margin-bottom: 8px">
          <a-button v-if="secEdit.embeds.length" size="small" @click="secHtml = withBooking(secHtml)">Replace the embed with our booking calendar</a-button>
          <a-button v-if="secChanged" size="small" @click="secHtml = secEdit.html">Undo changes</a-button>
        </a-space>
        <a-textarea v-model:value="secHtml" :auto-size="{ minRows: 12, maxRows: 26 }" class="sp-code" spellcheck="false" />
        <div class="sp-side-hint">
          Keep the outer tag and close every tag you open. Saved at once — "Restore original" undoes it.
          Only a SuperAdmin can add scripts; anyone can remove one or add a frame (https:// or a page of this site).
        </div>
      </template>
    </a-modal>

    <!-- pick a picture from Store -> Gallery -->
    <a-modal v-model:open="galleryOpen" title="Choose a picture from the Gallery" :footer="null" width="760px">
      <a-empty v-if="!gallery.length" description="The Gallery has no pictures yet (Store → Gallery)." />
      <div v-else class="sp-gallery">
        <img v-for="g in gallery" :key="g.id" :src="g.image_url" :title="g.title" @click="chooseGallery(g)" />
      </div>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Store Settings -> Homepage Blocks -> Website pages.
 *
 * VISUAL (default): the page itself, in a sandboxed frame (the site's own
 * scripts run so it looks exactly as live; no navigation / pop-ups / forms).
 * Click a text to type over it, click a picture to replace it (upload or
 * Store -> Gallery); the side panel shows the details (link target, caption).
 * LIST: every text / picture / link as a form.
 *
 * GET admin/store/site-pages -> {available, pages}; GET .../{file}?edit=1 ->
 * segments + edit_html (texts wrapped in [data-mu-t], pictures [data-mu-i],
 * links [data-mu-l]); PUT .../{file} sends ONLY changed values + version;
 * POST .../{file}/restore; POST admin/store/site-pages/upload.
 */
import { ref, computed, onMounted, nextTick } from 'vue';
import { message, Modal } from 'ant-design-vue';
import {
  EyeOutlined, UploadOutlined, PictureOutlined, SaveOutlined, FullscreenOutlined, FullscreenExitOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { sortByLabel } from '../../lib/filterOptions';

const loadingList = ref(true);
const loadingPage = ref(false);
const available = ref(false);
const pages = ref([]);
const current = ref(null);
const edits = ref({ texts: {}, images: {}, links: {} });
const view = ref('visual');
const device = ref('desktop');
const full = ref(false); // full-screen editor (the page gets the whole window)
const tab = ref('texts');
const filter = ref('');
const saving = ref(false);
const restoring = ref(false);
const uploadingId = ref(null);
const gallery = ref([]);
const galleryOpen = ref(false);
const frame = ref(null);
const sel = ref(null); // {type: 'text'|'image'|'link', id, linkId?}
let galleryTarget = null;
let keepScroll = 0;

const pageOptions = computed(() => pages.value.map(p => ({
  value: p.file,
  label: `${p.file}${p.is_front ? '  (front page)' : ''}${p.edited ? '  • edited' : ''}`,
})));
const linkTargets = computed(() => [
  ...pages.value.map(p => ({ value: p.file, label: `Website page: ${p.file}` })),
  { value: '/online_store/shop', label: 'Online store: Shop' },
  { value: '/online_store/gallery', label: 'Online store: Gallery' },
  { value: '/online_store/contact', label: 'Online store: Contact' },
]);

const byId = (list, id) => (list || []).find(x => x.id === id);
const titleText = computed(() => (current.value?.texts || []).find(t => t.tag === 'title'));
const selImage = computed(() => (sel.value?.type === 'image' ? byId(current.value?.images, sel.value.id) : null));
const selLink = computed(() => (sel.value?.linkId != null ? byId(current.value?.links, sel.value.linkId) : null));

const baseUrl = computed(() => (current.value ? current.value.url.replace(/[^/]*$/, '') : ''));
function previewOf(img) {
  if (!img) return '';
  const src = edits.value.images[img.id]?.src || '';
  if (src === img.src) return img.preview;
  if (/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(src)) return src;
  return src.startsWith('/') ? src : baseUrl.value + src.replace(/^\.\//, '');
}
const norm = s => String(s ?? '').replace(/\r/g, '').replace(/[ \t\n]+/g, ' ').trim();
const isTextChanged = id => norm(edits.value.texts[id]) !== byId(current.value?.texts, id)?.text;
const isImageChanged = img => edits.value.images[img.id].src !== img.src || (edits.value.images[img.id].alt || '') !== (img.alt || '');

const q = computed(() => filter.value.trim().toLowerCase());
const filteredTexts = computed(() => (current.value?.texts || []).filter(t => !q.value || t.text.toLowerCase().includes(q.value) || (edits.value.texts[t.id] || '').toLowerCase().includes(q.value)));
const filteredLinks = computed(() => (current.value?.links || []).filter(l => !q.value || (l.label || '').toLowerCase().includes(q.value) || (l.href || '').toLowerCase().includes(q.value)));

const dirty = computed(() => {
  const c = current.value;
  const d = { texts: {}, images: {}, links: {} };
  if (!c) return d;
  c.texts.forEach(t => { if (isTextChanged(t.id)) d.texts[t.id] = norm(edits.value.texts[t.id]); });
  c.images.forEach(i => { if (isImageChanged(i)) d.images[i.id] = { ...edits.value.images[i.id] }; });
  c.links.forEach(l => { if (edits.value.links[l.id] !== l.href) d.links[l.id] = edits.value.links[l.id] ?? ''; });
  return d;
});
const dirtyCount = computed(() => Object.keys(dirty.value.texts).length + Object.keys(dirty.value.images).length + Object.keys(dirty.value.links).length);

/* ---------------- the page frame ---------------- */
const doc = () => frame.value?.contentDocument || null;
const nodes = (attr, id) => Array.from(doc()?.querySelectorAll(`[${attr}="${id}"]`) || []);

function select(s) {
  const d = doc();
  d?.querySelectorAll('.mu-selected').forEach(n => n.classList.remove('mu-selected'));
  sel.value = s;
  if (s?.type === 'image') nodes('data-mu-i', s.id).forEach(n => n.classList.add('mu-selected'));
  if (s?.type === 'link') nodes('data-mu-l', s.id).forEach(n => n.classList.add('mu-selected'));
}

function wireFrame() {
  const d = doc();
  if (!d) return;
  const w = frame.value.contentWindow;
  // Bring the edits (not yet saved) back onto a freshly loaded page.
  current.value.texts.forEach(t => { if (isTextChanged(t.id)) pushText(t.id); });
  current.value.images.forEach(i => { if (isImageChanged(i)) pushImage(i); });
  if (keepScroll) { try { w.scrollTo(0, keepScroll); } catch (e) { /* ignore */ } keepScroll = 0; }

  // Capture phase: runs before the site's own handlers (lightboxes, sliders,
  // smooth-scroll) -- the editor decides what a click does, never the site.
  d.addEventListener('click', e => {
    const el = e.target;
    const t = el.closest?.('[data-mu-t]');
    const i = el.closest?.('[data-mu-i]');
    const a = el.closest?.('[data-mu-l]');
    if (el.closest?.('a, button, [onclick], input[type=submit]')) e.preventDefault();
    e.stopPropagation();
    const linkId = a ? Number(a.getAttribute('data-mu-l')) : null;
    if (t) {
      editText(t, linkId);
    } else if (i) {
      select({ type: 'image', id: Number(i.getAttribute('data-mu-i')), linkId });
    } else if (a) {
      select({ type: 'link', id: linkId, linkId });
    }
  }, true);
  ['mousedown', 'touchstart', 'pointerdown'].forEach(ev => d.addEventListener(ev, e => {
    if (e.target.closest?.('[data-mu-t]')) e.stopPropagation(); // sliders must not swallow the caret
  }, true));
  d.addEventListener('submit', e => e.preventDefault(), true);
}

function editText(span, linkId) {
  const id = Number(span.getAttribute('data-mu-t'));
  select({ type: 'text', id, linkId });
  if (span.isContentEditable) return;
  try { span.contentEditable = 'plaintext-only'; } catch (e) { span.contentEditable = 'true'; }
  if (span.contentEditable !== 'plaintext-only') span.contentEditable = 'true';
  span.spellcheck = true;
  span.focus();
  const onInput = () => {
    edits.value.texts[id] = span.innerText;
    syncTwins(id, span);
    markText(id);
  };
  const onKey = e => { if (e.key === 'Enter') e.preventDefault(); if (e.key === 'Escape') span.blur(); };
  const onPaste = e => {
    e.preventDefault();
    const txt = norm((e.clipboardData || window.clipboardData).getData('text'));
    span.ownerDocument.execCommand('insertText', false, txt);
  };
  const onBlur = () => {
    span.contentEditable = 'false';
    span.removeEventListener('input', onInput);
    span.removeEventListener('keydown', onKey);
    span.removeEventListener('paste', onPaste);
    span.removeEventListener('blur', onBlur);
  };
  span.addEventListener('input', onInput);
  span.addEventListener('keydown', onKey);
  span.addEventListener('paste', onPaste);
  span.addEventListener('blur', onBlur);
}

// Sliders clone slides: keep every copy of a text in step.
function syncTwins(id, except) {
  nodes('data-mu-t', id).forEach(n => { if (n !== except && n.innerText !== edits.value.texts[id]) n.innerText = edits.value.texts[id]; });
}
function markText(id) {
  const changed = isTextChanged(id);
  nodes('data-mu-t', id).forEach(n => n.classList.toggle('mu-changed', changed));
}
function pushText(id) {
  syncTwins(id, null);
  markText(id);
}
function undoText(id) {
  edits.value.texts[id] = byId(current.value.texts, id).text;
  pushText(id);
}

function pushImage(img) {
  const url = previewOf(img);
  const changed = isImageChanged(img);
  nodes('data-mu-i', img.id).forEach(n => {
    n.classList.toggle('mu-changed', changed);
    if (n.tagName === 'IMG') {
      n.removeAttribute('srcset');
      n.src = url;
    } else if (img.popup) {
      const inner = n.firstElementChild;
      if (inner) { inner.style.backgroundImage = `url("${url}")`; inner.style.backgroundSize = 'cover'; inner.style.backgroundPosition = 'center'; }
    } else {
      n.style.backgroundImage = `url("${url}")`;
    }
  });
}
function undoImage(img) {
  edits.value.images[img.id] = { src: img.src, alt: img.alt };
  pushImage(img);
}

/* ---------------- load / save ---------------- */
function apply(page) {
  current.value = page;
  edits.value = {
    texts: Object.fromEntries(page.texts.map(t => [t.id, t.text])),
    images: Object.fromEntries(page.images.map(i => [i.id, { src: i.src, alt: i.alt }])),
    links: Object.fromEntries(page.links.map(l => [l.id, l.href])),
  };
  sel.value = null;
  const row = pages.value.find(p => p.file === page.file);
  if (row) row.edited = page.edited;
}

async function loadList() {
  loadingList.value = true;
  try {
    const r = await http.get('admin/store/site-pages');
    available.value = !!r.available;
    pages.value = r.pages || [];
    if (pages.value.length && !current.value) open(pages.value[0].file, true);
  } finally {
    loadingList.value = false;
  }
}

async function confirmDiscard() {
  if (!current.value || !dirtyCount.value) return true;
  return new Promise(res => Modal.confirm({
    title: 'Discard unsaved changes?', content: `${dirtyCount.value} change(s) in ${current.value.file} are not saved.`,
    okText: 'Discard', okType: 'danger', onOk: () => res(true), onCancel: () => res(false),
  }));
}

async function open(file, force = false) {
  if (!force && !(await confirmDiscard())) return;
  loadingPage.value = true;
  filter.value = '';
  try {
    apply(await http.get(`admin/store/site-pages/${encodeURIComponent(file)}`, { edit: 1 }));
  } catch (e) {
    message.error(e?.data?.message || 'Could not open the page.');
  } finally {
    loadingPage.value = false;
  }
}

async function reloadCurrent() {
  try { keepScroll = frame.value?.contentWindow?.scrollY || 0; } catch (e) { keepScroll = 0; }
  apply(await http.get(`admin/store/site-pages/${encodeURIComponent(current.value.file)}`, { edit: 1 }));
}

async function discard() {
  if (!(await confirmDiscard())) return;
  loadingPage.value = true;
  try { await reloadCurrent(); } finally { loadingPage.value = false; }
}

async function save() {
  saving.value = true;
  try {
    // A text being typed in the page: take its latest value.
    doc()?.activeElement?.blur?.();
    await nextTick();
    const r = await http.put(`admin/store/site-pages/${encodeURIComponent(current.value.file)}`, { version: current.value.version, ...dirty.value });
    await reloadCurrent();
    message.success(`Saved — ${r.changed} change(s) are live on ${r.file}.`);
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat().join(' ') : '';
    message.error(errs || e?.data?.message || 'Could not save the page.', 6);
  } finally {
    saving.value = false;
  }
}

async function restore() {
  restoring.value = true;
  try {
    await http.post(`admin/store/site-pages/${encodeURIComponent(current.value.file)}/restore`);
    await reloadCurrent();
    message.success('The imported version of the page is back.');
  } catch (e) {
    message.error(e?.data?.message || 'Could not restore the page.');
  } finally {
    restoring.value = false;
  }
}

/* ---------------- sections (whole blocks of HTML) ---------------- */
const sectionKey = ref(null);
const secOpen = ref(false);
const secEdit = ref(null);
const secHtml = ref('');
const secSaving = ref(false);
const secChanged = computed(() => !!secEdit.value && secHtml.value.trim() !== secEdit.value.html.trim());
const sectionKeys = computed(() => {
  const seen = new Map();
  (current.value?.sections || []).forEach(s => { if (!seen.has(s.key)) seen.set(s.key, s.key ? `#${s.key} — ${s.label}` : 'Embeds'); });
  return sortByLabel([...seen].map(([value, label]) => ({ value, label })));
});
const filteredSections = computed(() => (current.value?.sections || []).filter(s => sectionKey.value == null || s.key === sectionKey.value));
function sectionText(s) {
  const t = s.html.replace(/<script\b[\s\S]*?<\/script\s*>|<style\b[\s\S]*?<\/style\s*>|<!--[\s\S]*?-->/gi, ' ').replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
  return t.length > 160 ? `${t.slice(0, 160)}…` : t || '(no text — an embed or pictures)';
}
// Our booking page in place of the block's first embed (frame / inline script); other embeds go.
function withBooking(html) {
  const url = current.value.booking_url.replace(/&/g, '&amp;');
  const frame = `<iframe src="${url}" title="Book an appointment" loading="lazy" style="width:100%;height:700px;border:0;display:block"></iframe>`;
  let done = false;
  return html.replace(/<script\b(?![^>]*\ssrc\s*=)[^>]*>[\s\S]*?<\/script\s*>|<iframe\b[\s\S]*?<\/iframe\s*>/gi, () => {
    if (done) return '';
    done = true;
    return frame;
  });
}
async function editSection(s, booking = false) {
  if (dirtyCount.value) {
    message.warning('Save or discard the other changes of this page first.');
    return;
  }
  secEdit.value = s;
  secHtml.value = booking ? withBooking(s.html) : s.html;
  secOpen.value = true;
}
async function saveSection() {
  secSaving.value = true;
  try {
    await http.put(`admin/store/site-pages/${encodeURIComponent(current.value.file)}/sections/${secEdit.value.id}`, { version: current.value.version, html: secHtml.value });
    secOpen.value = false;
    await reloadCurrent();
    view.value = 'list';
    tab.value = 'sections';
    message.success(`Saved — the section is live on ${current.value.file}.`);
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat().join(' ') : '';
    message.error(errs || e?.data?.message || 'Could not save the section.', 8);
  } finally {
    secSaving.value = false;
  }
}

/* ---------------- pictures ---------------- */
async function upload(img, file) {
  uploadingId.value = img.id;
  try {
    const fd = new FormData();
    fd.append('image', file);
    const { status, data } = await uploadForm('admin/store/site-pages/upload', fd);
    if (status >= 200 && status < 300 && data?.src) {
      edits.value.images[img.id].src = data.src;
      pushImage(img);
      message.info('Picture changed — press Save to publish it.');
    } else {
      message.error((data?.errors && Object.values(data.errors).flat().join(' ')) || data?.message || 'Upload failed.');
    }
  } finally {
    uploadingId.value = null;
  }
  return false;
}

async function pickFromGallery(img) {
  galleryTarget = img;
  galleryOpen.value = true;
  if (!gallery.value.length) {
    try { gallery.value = (await http.get('store/gallery')).data || []; } catch (e) { gallery.value = []; }
  }
}
function chooseGallery(g) {
  const e = edits.value.images[galleryTarget.id];
  e.src = '/' + g.image.replace(/^\/+/, '');
  if (!galleryTarget.bg && !e.alt && g.title) e.alt = g.title;
  pushImage(galleryTarget);
  galleryOpen.value = false;
}

onMounted(loadList);
</script>

<style scoped>
.sp-root.full { position: fixed; inset: 0; z-index: 999; border-radius: 0; overflow: auto; }
.sp-root.full .sp-frame { height: calc(100vh - 80px); }
.sp-root.full .sp-side { top: 10px; }
.sp-bar { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.sp-bar-label { font-weight: 500; }
.sp-visual { display: flex; gap: 12px; align-items: stretch; }
.sp-stage { flex: 1; min-width: 0; display: flex; justify-content: center; background: #f0f2f5; border-radius: 8px; padding: 0; }
.sp-stage.mobile { padding: 12px 0; }
.sp-frame { width: 100%; height: calc(100vh - 250px); min-height: 560px; border: 1px solid rgba(5, 5, 5, 0.1); border-radius: 6px; background: #fff; }
.sp-stage.mobile .sp-frame { width: 390px; }
.sp-side { flex: 0 0 280px; border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 12px; align-self: flex-start; position: sticky; top: 72px; }
.sp-side-title { font-weight: 600; margin-bottom: 6px; }
.sp-side-hint { font-size: 12px; opacity: 0.65; margin-top: 4px; }
.sp-side-img { aspect-ratio: 4 / 3; background: #f5f5f5; border-radius: 6px; overflow: hidden; margin-bottom: 10px; }
.sp-side-img img { width: 100%; height: 100%; object-fit: contain; }
.sp-steps > div { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 10px; line-height: 1.4; }
.sp-steps span { flex: none; width: 22px; height: 22px; border-radius: 50%; background: #1677ff; color: #fff; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
.sp-orange { color: #fa8c16; font-style: normal; font-weight: 600; }
@media (max-width: 991px) {
  .sp-visual { flex-direction: column; }
  .sp-side { flex: none; position: static; }
}
.sp-text { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 6px; }
.sp-el { flex: none; width: 64px; text-align: center; margin: 4px 0 0; font-size: 11px; }
.changed, .changed :deep(textarea), .sp-img.changed { border-color: #fa8c16 !important; }
.sp-images { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
.sp-img { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.sp-thumb { display: block; aspect-ratio: 4 / 3; background: #f5f5f5; border-radius: 6px; overflow: hidden; }
.sp-thumb img { width: 100%; height: 100%; object-fit: contain; }
.sp-link { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
.sp-link-label { flex: 0 0 36%; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-size: 13px; }
.sp-sec { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 10px; margin-bottom: 8px; }
.sp-sec-head { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 4px; }
.sp-sec-el { font-size: 11px; opacity: 0.6; overflow-wrap: anywhere; }
.sp-sec-embeds { margin-bottom: 4px; }
.sp-sec-embeds :deep(.ant-tag) { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sp-sec-text { font-size: 12px; opacity: 0.75; margin-bottom: 8px; overflow-wrap: anywhere; }
.sp-code :deep(textarea), .sp-code { font-family: Consolas, Menlo, monospace; font-size: 12px; }
.sp-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; }
.sp-gallery img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 6px; cursor: pointer; border: 2px solid transparent; }
.sp-gallery img:hover { border-color: #1677ff; }
</style>
