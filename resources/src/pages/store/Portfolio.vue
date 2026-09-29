<template>
  <div class="page">
    <PageHeader :title="$t('Portfolio')" :breadcrumb="[$t('Store'), $t('Portfolio')]">
      <template #extra>
        <a-space wrap>
          <a-button v-if="canImport" :loading="importing" @click="importSite">
            <template #icon><CloudDownloadOutlined /></template>
            {{ $t('Import_From_Website') }}
          </a-button>
          <a-upload :show-upload-list="false" multiple accept="image/jpeg,image/png,image/webp,image/gif" :before-upload="queueUpload">
            <a-button type="primary" :loading="uploading">
              <template #icon><UploadOutlined /></template>
              {{ $t('Upload_Photos') }}{{ currentGroupName ? ` → ${currentGroupName}` : '' }}
            </a-button>
          </a-upload>
        </a-space>
      </template>
    </PageHeader>

    <a-alert
      v-if="canImport && siteCandidates && !items.length" type="info" show-icon style="margin-bottom: 12px"
      :message="$t('Portfolio_Import_Hint', { n: siteCandidates })"
    />
    <a-progress v-if="uploading" :percent="progress" size="small" style="margin-bottom: 12px" />

    <PortfolioSectionCard ref="sectionCard" style="margin-bottom: 12px" />

    <a-row :gutter="12">
      <!-- groups -->
      <a-col :xs="24" :md="7" :xl="6">
        <a-card size="small" :title="$t('Portfolio_Groups')" :body-style="{ padding: '6px' }">
          <div class="pf-g" :class="{ on: groupFilter === 'all' }" @click="groupFilter = 'all'">
            <span class="pf-g-name">{{ $t('Portfolio_All') }}</span><a-tag>{{ items.length }}</a-tag>
          </div>
          <div v-for="(g, i) in groups" :key="g.id" class="pf-g" :class="{ on: groupFilter === g.id }" @click="groupFilter = g.id">
            <template v-if="editingGroup === g.id">
              <a-input v-model:value="g.name" size="small" autofocus @click.stop @press-enter="renameGroup(g)" @blur="renameGroup(g)" />
            </template>
            <template v-else>
              <span class="pf-g-name">{{ g.name }}</span>
              <a-tag>{{ countIn(g.id) }}</a-tag>
              <span class="pf-g-act" @click.stop>
                <a-button size="small" type="text" :disabled="i === 0" @click="moveGroup(i, -1)">↑</a-button>
                <a-button size="small" type="text" :disabled="i === groups.length - 1" @click="moveGroup(i, 1)">↓</a-button>
                <a-button size="small" type="text" @click="editingGroup = g.id"><template #icon><EditOutlined /></template></a-button>
                <a-popconfirm title="Delete this group? Its pictures stay, without a group." @confirm="deleteGroup(g)">
                  <a-button size="small" type="text" danger><template #icon><DeleteOutlined /></template></a-button>
                </a-popconfirm>
              </span>
            </template>
          </div>
          <div class="pf-g" :class="{ on: groupFilter === 'none' }" @click="groupFilter = 'none'">
            <span class="pf-g-name pf-muted">{{ $t('Portfolio_No_Group') }}</span><a-tag>{{ countIn(null) }}</a-tag>
          </div>
          <div class="pf-add">
            <a-input v-model:value="newGroup" size="small" :placeholder="$t('Portfolio_Add_Group')" @press-enter="addGroup" />
            <a-button size="small" type="primary" :disabled="!newGroup.trim()" @click="addGroup"><template #icon><PlusOutlined /></template></a-button>
          </div>
        </a-card>
      </a-col>

      <!-- pictures -->
      <a-col :xs="24" :md="17" :xl="18">
        <a-card size="small" :loading="isLoading">
          <a-empty v-if="!shown.length" :description="$t('NodataAvailable')" />
          <div v-else class="pf-grid">
            <div v-for="it in shown" :key="it.id" class="pf-card" :class="{ off: !it.active }">
              <a :href="it.image_url" target="_blank" class="pf-thumb">
                <img :src="it.image_url" :alt="it.title" loading="lazy" />
                <a-tag v-if="it.source === 'imported'" color="blue" class="pf-src">{{ $t('Imported') }}</a-tag>
              </a>
              <a-input v-model:value="it.title" size="small" :placeholder="$t('Title')" @blur="saveItem(it)" @press-enter="$event.target.blur()" />
              <a-input v-model:value="it.subtitle" size="small" :placeholder="$t('Subtitle')" @blur="saveItem(it)" @press-enter="$event.target.blur()" />
              <a-select
                :value="it.group_id || 0" size="small" :options="groupOptions"
                @change="v => { it.group_id = v || null; saveItem(it, true); }"
              />
              <div class="pf-actions">
                <a-tooltip :title="it.active ? $t('Visible') : $t('Hidden')">
                  <a-switch size="small" :checked="it.active" @change="v => { it.active = v; saveItem(it, true); }" />
                </a-tooltip>
                <a-space :size="4">
                  <a-button size="small" :disabled="indexOf(it) === 0" @click="moveItem(it, -1)"><template #icon><ArrowLeftOutlined /></template></a-button>
                  <a-button size="small" :disabled="indexOf(it) === items.length - 1" @click="moveItem(it, 1)"><template #icon><ArrowRightOutlined /></template></a-button>
                  <a-button size="small" danger @click="removeItem(it)"><template #icon><DeleteOutlined /></template></a-button>
                </a-space>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Store -> Portfolio (per company): groups (the filter tabs on the site) +
 * pictures. Upload goes into the group picked on the left. The front-page
 * placement is in PortfolioSectionCard (Homepage Block on our store front
 * page, or inserted into the imported website's front page).
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  UploadOutlined, CloudDownloadOutlined, DeleteOutlined, EditOutlined, PlusOutlined, ArrowLeftOutlined, ArrowRightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PortfolioSectionCard from './PortfolioSectionCard.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { sortByLabel } from '../../lib/filterOptions';

const { t } = useI18n();

const isLoading = ref(true);
const groups = ref([]);
const items = ref([]);
const canImport = ref(false);
const siteCandidates = ref(0);
const importing = ref(false);
const uploading = ref(false);
const progress = ref(0);
const groupFilter = ref('all'); // 'all' | 'none' | group id
const newGroup = ref('');
const editingGroup = ref(null);
const sectionCard = ref(null);
const saved = new Map();

const shown = computed(() => items.value.filter(it => groupFilter.value === 'all'
  || (groupFilter.value === 'none' ? !it.group_id : it.group_id === groupFilter.value)));
const currentGroupName = computed(() => (typeof groupFilter.value === 'number' ? groups.value.find(g => g.id === groupFilter.value)?.name : ''));
const groupOptions = computed(() => [{ value: 0, label: `— ${t('Portfolio_No_Group')} —` }, ...sortByLabel(groups.value.map(g => ({ value: g.id, label: g.name })))]);
const countIn = id => items.value.filter(it => (id === null ? !it.group_id : it.group_id === id)).length;
const indexOf = it => items.value.indexOf(it);
const snap = it => JSON.stringify([it.title || '', it.subtitle || '', it.group_id || null, !!it.active]);

async function fetch() {
  isLoading.value = true;
  try {
    const r = await http.get('store/portfolio');
    groups.value = r.groups || [];
    items.value = r.items || [];
    items.value.forEach(it => saved.set(it.id, snap(it)));
    canImport.value = !!r.can_import;
    siteCandidates.value = r.site_candidates || 0;
  } finally {
    isLoading.value = false;
  }
}

/* groups */
async function addGroup() {
  const name = newGroup.value.trim();
  if (!name) return;
  try {
    const g = await http.post('store/portfolio/groups', { name });
    groups.value.push(g);
    newGroup.value = '';
    groupFilter.value = g.id;
  } catch (e) { message.error(e?.data?.message || t('InvalidData')); }
}
async function renameGroup(g) {
  editingGroup.value = null;
  if (!(g.name || '').trim()) { fetch(); return; }
  try { await http.put(`store/portfolio/groups/${g.id}`, { name: g.name.trim() }); } catch (e) { message.error(e?.data?.message || t('InvalidData')); fetch(); }
}
async function deleteGroup(g) {
  try {
    await http.delete(`store/portfolio/groups/${g.id}`);
    if (groupFilter.value === g.id) groupFilter.value = 'all';
    fetch();
  } catch (e) { message.error(e?.data?.message || t('Delete_Therewassomethingwronge')); }
}
async function moveGroup(i, dir) {
  const list = groups.value;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  try { await http.post('store/portfolio/groups/reorder', { ids: list.map(g => g.id) }); } catch (e) { fetch(); }
}

/* pictures */
let pending = [];
let timer = null;
function queueUpload(file) {
  pending.push(file);
  clearTimeout(timer);
  timer = setTimeout(sendUploads, 50);
  return false;
}
async function sendUploads() {
  const files = pending;
  pending = [];
  if (!files.length) return;
  const fd = new FormData();
  files.forEach(f => fd.append('images[]', f));
  if (typeof groupFilter.value === 'number') fd.append('group_id', groupFilter.value);
  uploading.value = true;
  progress.value = 0;
  try {
    const { status, data } = await uploadForm('store/portfolio/items', fd, p => { progress.value = p; });
    if (status >= 200 && status < 300) { message.success(t('Saved_successfully')); fetch(); } else {
      message.error((data?.errors && Object.values(data.errors).flat().join(' ')) || data?.message || t('InvalidData'));
    }
  } catch (e) { message.error(e.message); } finally { uploading.value = false; }
}
async function saveItem(it, force = false) {
  if (!force && saved.get(it.id) === snap(it)) return;
  try {
    await http.put(`store/portfolio/items/${it.id}`, { title: it.title || null, subtitle: it.subtitle || null, group_id: it.group_id || null, active: !!it.active });
    saved.set(it.id, snap(it));
  } catch (e) { message.error(e?.data?.message || t('InvalidData')); fetch(); }
}
async function moveItem(it, dir) {
  const list = items.value;
  const i = list.indexOf(it);
  const j = i + dir;
  if (j < 0 || j >= list.length) return;
  [list[i], list[j]] = [list[j], list[i]];
  try { await http.post('store/portfolio/items/reorder', { ids: list.map(x => x.id) }); } catch (e) { fetch(); }
}
function removeItem(it) {
  Modal.confirm({
    title: t('Delete_Title'), content: it.title, okText: t('Delete_confirmButtonText'), okType: 'danger', cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try { await http.delete(`store/portfolio/items/${it.id}`); message.success(t('Deleted_in_successfully')); fetch(); } catch (e) { message.error(t('Delete_Therewassomethingwronge')); }
    },
  });
}
async function importSite() {
  importing.value = true;
  try {
    const r = await http.post('store/portfolio/import-site');
    message.success(t('Portfolio_Import_Done', { added: r.added, groups: r.groups, skipped: r.skipped }), 5);
    fetch();
  } catch (e) { message.error(e?.data?.message || t('InvalidData')); } finally { importing.value = false; }
}

onMounted(fetch);
</script>

<style scoped>
.pf-g { display: flex; align-items: center; gap: 6px; padding: 6px 8px; border-radius: 6px; cursor: pointer; min-height: 34px; }
.pf-g:hover { background: rgba(0, 0, 0, 0.03); }
.pf-g.on { background: rgba(22, 119, 255, 0.08); }
.pf-g-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
.pf-g .ant-tag { margin: 0; }
.pf-g-act { display: none; }
.pf-g:hover .pf-g-act { display: inline-flex; }
.pf-muted { opacity: 0.6; font-weight: normal; }
.pf-add { display: flex; gap: 6px; padding: 8px; border-top: 1px solid rgba(5, 5, 5, 0.06); margin-top: 4px; }
.pf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }
.pf-card { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 8px; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
.pf-card.off .pf-thumb img { opacity: 0.4; }
.pf-thumb { position: relative; display: block; aspect-ratio: 4 / 3; border-radius: 6px; overflow: hidden; background: #f5f5f5; }
.pf-thumb img { width: 100%; height: 100%; object-fit: cover; }
.pf-src { position: absolute; top: 6px; left: 6px; margin: 0; }
.pf-actions { display: flex; justify-content: space-between; align-items: center; }
</style>
