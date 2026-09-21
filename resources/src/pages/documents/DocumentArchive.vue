<template>
  <div class="page">
    <PageHeader
      title="Document Archive"
      subtitle="Store, organise and find every business document in one place."
    >
      <template #actions>
        <a-button v-if="canAdd" @click="newFolder(null)">
          <template #icon><FolderAddOutlined /></template>
          New folder
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="openUpload">
          <template #icon><CloudUploadOutlined /></template>
          Upload
        </a-button>
      </template>
    </PageHeader>

    <!-- Overview tiles double as the top-level filters. -->
    <div class="stats">
      <button
        type="button" class="stat" :class="{ active: scope === 'all' && folderId === null }"
        @click="showAll"
      >
        <span class="stat-ic stat-ic--brand"><FolderOpenOutlined /></span>
        <span class="stat-text">
          <span class="stat-value">{{ stats.total || 0 }}</span>
          <span class="stat-label">Documents</span>
        </span>
        <span v-if="stats.added_this_month" class="stat-note">+{{ stats.added_this_month }} this month</span>
      </button>

      <div class="stat stat--static">
        <span class="stat-ic stat-ic--info"><DatabaseOutlined /></span>
        <span class="stat-text">
          <span class="stat-value">{{ stats.storage_label || '0 B' }}</span>
          <span class="stat-label">Storage used</span>
        </span>
        <span class="stat-note">{{ stats.folders || 0 }} folders</span>
      </div>

      <button
        type="button" class="stat" :class="{ active: scope === 'expiring' }"
        @click="setScope('expiring')"
      >
        <span class="stat-ic stat-ic--warn"><ClockCircleOutlined /></span>
        <span class="stat-text">
          <span class="stat-value">{{ stats.expiring_soon || 0 }}</span>
          <span class="stat-label">Expiring in 30 days</span>
        </span>
        <span v-if="stats.expired" class="stat-note stat-note--danger">{{ stats.expired }} expired</span>
      </button>

      <button
        type="button" class="stat" :class="{ active: scope === 'starred' }"
        @click="setScope('starred')"
      >
        <span class="stat-ic stat-ic--star"><StarFilled /></span>
        <span class="stat-text">
          <span class="stat-value">{{ stats.starred || 0 }}</span>
          <span class="stat-label">Starred</span>
        </span>
      </button>
    </div>

    <a-row :gutter="16">
      <!-- Folder rail -->
      <a-col :xs="24" :lg="7" :xl="6" :xxl="5">
        <a-card class="rail" :body-style="{ padding: '12px' }">
          <nav class="rail-nav">
            <button type="button" class="rail-item" :class="{ active: folderId === null && scope === 'all' }" @click="showAll">
              <InboxOutlined /><span>All documents</span>
              <span class="rail-count">{{ stats.total || 0 }}</span>
            </button>
            <button type="button" class="rail-item" :class="{ active: scope === 'starred' }" @click="setScope('starred')">
              <StarOutlined /><span>Starred</span>
              <span class="rail-count">{{ stats.starred || 0 }}</span>
            </button>
            <button type="button" class="rail-item" :class="{ active: scope === 'expiring' }" @click="setScope('expiring')">
              <ClockCircleOutlined /><span>Expiring soon</span>
              <span class="rail-count">{{ stats.expiring_soon || 0 }}</span>
            </button>
            <button type="button" class="rail-item" :class="{ active: folderId === 'none' }" @click="selectFolder('none')">
              <FileOutlined /><span>Uncategorised</span>
              <span class="rail-count">{{ uncategorised }}</span>
            </button>
          </nav>

          <div class="rail-head">
            <span>Folders</span>
            <a-tooltip v-if="canAdd" title="New folder">
              <a-button size="small" type="text" @click="newFolder(null)">
                <template #icon><PlusOutlined /></template>
              </a-button>
            </a-tooltip>
          </div>

          <a-tree
            v-if="folderTree.length"
            :tree-data="folderTree"
            :selected-keys="treeSelectedKeys"
            :expanded-keys="expandedKeys"
            block-node
            class="rail-tree"
            @select="onTreeSelect"
            @expand="keys => (expandedKeys = keys)"
          >
            <template #title="node">
              <span class="tree-row">
                <span class="tree-dot" :style="{ background: node.color || '#94a3b8' }"></span>
                <span class="tree-name" :title="node.title">{{ node.title }}</span>
                <span class="tree-count">{{ node.count }}</span>
                <a-dropdown v-if="canEdit" :trigger="['click']">
                  <span class="tree-more" @click.stop><MoreOutlined /></span>
                  <template #overlay>
                    <a-menu @click="({ key }) => folderAction(key, node)">
                      <a-menu-item key="rename">
                        <EditOutlined /> Rename
                      </a-menu-item>
                      <a-menu-item v-if="canAdd" key="child">
                        <FolderAddOutlined /> New sub-folder
                      </a-menu-item>
                      <a-menu-divider v-if="canDelete" />
                      <a-menu-item v-if="canDelete" key="delete" danger>
                        <DeleteOutlined /> {{ $t('Delete') }}
                      </a-menu-item>
                    </a-menu>
                  </template>
                </a-dropdown>
              </span>
            </template>
          </a-tree>

          <a-empty
            v-else :image="simpleEmptyImage" description="No folders yet"
            class="rail-empty"
          >
            <a-button v-if="canAdd" size="small" @click="newFolder(null)">Create one</a-button>
          </a-empty>
        </a-card>
      </a-col>

      <!-- Documents -->
      <a-col :xs="24" :lg="17" :xl="18" :xxl="19">
        <a-card :body-style="{ padding: '16px' }">
          <div class="toolbar">
            <a-input-search
              v-model:value="crud.search.value"
              placeholder="Search title, file name, reference or tag…"
              allow-clear class="tb-search" @search="crud.reload"
            />
            <a-select
              v-model:value="filters.kind" class="tb-item" allow-clear
              placeholder="All types" :options="KIND_OPTIONS" @change="crud.reload"
            />
            <a-select
              v-model:value="filters.tag" class="tb-item" allow-clear show-search
              placeholder="All tags" :options="tagOptions" @change="crud.reload"
            />
            <a-range-picker
              v-model:value="filters.range" class="tb-range"
              value-format="YYYY-MM-DD" @change="crud.reload"
            />
            <a-select v-model:value="sortKey" class="tb-item" :options="SORTS" @change="applySort" />
            <a-segmented v-model:value="view" :options="VIEW_OPTIONS">
              <template #label="{ value, label }">
                <span class="seg-label">
                  <AppstoreOutlined v-if="value === 'grid'" />
                  <UnorderedListOutlined v-else />
                  {{ label }}
                </span>
              </template>
            </a-segmented>
          </div>

          <div v-if="activeChips.length" class="chips">
            <a-tag v-for="chip in activeChips" :key="chip.key" closable @close="chip.clear()">
              {{ chip.label }}
            </a-tag>
            <a-button type="link" size="small" @click="clearFilters">Clear all</a-button>
          </div>

          <!-- Bulk actions -->
          <div v-if="crud.selectedIds.value.length" class="bulkbar">
            <span class="bulk-count">{{ crud.selectedIds.value.length }} selected</span>
            <a-tree-select
              v-if="canEdit"
              v-model:value="moveTarget" class="bulk-move" allow-clear
              placeholder="Move to folder…" :tree-data="folderSelectOptions"
              tree-default-expand-all
              :field-names="{ label: 'title', value: 'value', children: 'children' }"
            />
            <a-button v-if="canEdit" :loading="moving" @click="moveSelected">Move</a-button>
            <a-button v-if="canDelete" danger @click="crud.removeSelected">
              <template #icon><DeleteOutlined /></template>
              {{ $t('Delete') }}
            </a-button>
            <a-button type="text" @click="crud.selectedIds.value = []">{{ $t('Cancel') }}</a-button>
          </div>

          <!-- Grid -->
          <template v-if="view === 'grid'">
            <a-spin :spinning="crud.loading.value">
              <div v-if="crud.rows.value.length" class="grid">
                <article
                  v-for="record in crud.rows.value" :key="record.id"
                  class="doc" :class="{ selected: isSelected(record.id) }"
                  @click="openDocument(record)"
                >
                  <header class="doc-head">
                    <!-- Image files are served from public/, so show the real
                         thing instead of a generic icon. -->
                    <img
                      v-if="record.kind === 'image' && record.url"
                      class="doc-ic doc-thumb" :src="record.url" :alt="record.title" loading="lazy"
                    />
                    <span v-else class="doc-ic" :style="{ color: kindOf(record).color, background: tint(kindOf(record).color) }">
                      <component :is="kindOf(record).icon" />
                    </span>
                    <span class="doc-tools" @click.stop>
                      <a-checkbox :checked="isSelected(record.id)" class="doc-check" @change="toggleSelect(record.id)" />
                      <a-button
                        v-if="canEdit" type="text" size="small" class="doc-star"
                        :class="{ on: record.is_starred }" @click="toggleStar(record)"
                      >
                        <template #icon>
                          <StarFilled v-if="record.is_starred" />
                          <StarOutlined v-else />
                        </template>
                      </a-button>
                      <a-dropdown :trigger="['click']">
                        <a-button type="text" size="small"><template #icon><MoreOutlined /></template></a-button>
                        <template #overlay>
                          <a-menu @click="({ key }) => rowAction(key, record)">
                            <a-menu-item key="open"><EyeOutlined /> Preview</a-menu-item>
                            <a-menu-item key="download"><DownloadOutlined /> Download</a-menu-item>
                            <a-menu-item v-if="canEdit" key="edit"><EditOutlined /> {{ $t('Edit') }}</a-menu-item>
                            <a-menu-divider v-if="canDelete" />
                            <a-menu-item v-if="canDelete" key="delete" danger><DeleteOutlined /> {{ $t('Delete') }}</a-menu-item>
                          </a-menu>
                        </template>
                      </a-dropdown>
                    </span>
                  </header>

                  <h3 class="doc-title" :title="record.title">{{ record.title }}</h3>
                  <p class="doc-file" :title="record.file_name">{{ record.file_name }}</p>

                  <div v-if="record.tags && record.tags.length" class="doc-tags">
                    <a-tag v-for="tg in record.tags.slice(0, 3)" :key="tg" color="purple">{{ tg }}</a-tag>
                    <a-tag v-if="record.tags.length > 3">+{{ record.tags.length - 3 }}</a-tag>
                  </div>

                  <footer class="doc-foot">
                    <span class="doc-ext">{{ (record.extension || '—').toUpperCase() }}</span>
                    <span>{{ record.size_label }}</span>
                    <span class="doc-sep">·</span>
                    <span>{{ timeAgo(record.created_at) }}</span>
                  </footer>

                  <span v-if="expiryTag(record)" class="doc-expiry" :class="expiryTag(record).tone">
                    {{ expiryTag(record).text }}
                  </span>
                  <span v-else-if="record.folder_name" class="doc-folder">
                    <FolderOutlined /> {{ record.folder_name }}
                  </span>
                </article>
              </div>

              <a-empty v-else :description="emptyText" class="grid-empty">
                <a-button v-if="canAdd && !hasFilters" type="primary" @click="openUpload">
                  <template #icon><CloudUploadOutlined /></template>
                  Upload your first document
                </a-button>
                <a-button v-else-if="hasFilters" @click="clearFilters">Clear filters</a-button>
              </a-empty>
            </a-spin>

            <div v-if="crud.total.value > crud.limit.value" class="grid-pager">
              <a-pagination
                :current="crud.page.value"
                :page-size="crud.limit.value"
                :total="crud.total.value"
                :page-size-options="['12', '24', '48', '96']"
                show-size-changer
                @change="onPageChange"
              />
            </div>
          </template>

          <!-- List -->
          <a-table
            v-else
            :columns="columns"
            :data-source="crud.rows.value"
            :loading="crud.loading.value"
            :pagination="crud.pagination.value"
            :row-key="r => r.id"
            :row-selection="crud.rowSelection.value"
            :scroll="{ x: 'max-content' }"
            @change="crud.onChange"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'title'">
                <button type="button" class="cell-doc" @click="openDocument(record)">
                  <img
                    v-if="record.kind === 'image' && record.url"
                    class="cell-ic cell-thumb" :src="record.url" :alt="record.title" loading="lazy"
                  />
                  <span v-else class="cell-ic" :style="{ color: kindOf(record).color, background: tint(kindOf(record).color) }">
                    <component :is="kindOf(record).icon" />
                  </span>
                  <span class="cell-text">
                    <span class="cell-title">
                      <StarFilled v-if="record.is_starred" class="cell-star" />
                      {{ record.title }}
                    </span>
                    <span class="cell-file">{{ record.file_name }}</span>
                  </span>
                </button>
              </template>

              <template v-else-if="column.key === 'folder'">
                <a-tag v-if="record.folder_name">{{ record.folder_name }}</a-tag>
                <span v-else class="muted">—</span>
              </template>

              <template v-else-if="column.key === 'tags'">
                <template v-if="record.tags && record.tags.length">
                  <a-tag v-for="tg in record.tags.slice(0, 2)" :key="tg" color="purple">{{ tg }}</a-tag>
                  <a-tooltip v-if="record.tags.length > 2" :title="record.tags.slice(2).join(', ')">
                    <a-tag>+{{ record.tags.length - 2 }}</a-tag>
                  </a-tooltip>
                </template>
                <span v-else class="muted">—</span>
              </template>

              <template v-else-if="column.key === 'expiry'">
                <a-tag v-if="expiryTag(record)" :color="expiryTag(record).color">{{ expiryTag(record).text }}</a-tag>
                <span v-else-if="record.expiry_date">{{ date(record.expiry_date) }}</span>
                <span v-else class="muted">—</span>
              </template>

              <template v-else-if="column.key === 'added'">
                <a-tooltip :title="dateTime(record.created_at)">{{ timeAgo(record.created_at) }}</a-tooltip>
              </template>

              <template v-else-if="column.key === 'actions'">
                <a-space :size="0">
                  <a-tooltip title="Preview">
                    <a-button type="text" size="small" @click="openDocument(record)">
                      <template #icon><EyeOutlined /></template>
                    </a-button>
                  </a-tooltip>
                  <a-tooltip title="Download">
                    <a-button type="text" size="small" @click="downloadDocument(record)">
                      <template #icon><DownloadOutlined /></template>
                    </a-button>
                  </a-tooltip>
                  <a-tooltip v-if="canEdit" :title="$t('Edit')">
                    <a-button type="text" size="small" @click="editDocument(record)">
                      <template #icon><EditOutlined /></template>
                    </a-button>
                  </a-tooltip>
                  <a-tooltip v-if="canDelete" :title="$t('Delete')">
                    <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.title })">
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </a-tooltip>
                </a-space>
              </template>
            </template>

            <template #emptyText>
              <a-empty :description="emptyText" style="padding: 32px 0" />
            </template>
          </a-table>
        </a-card>
      </a-col>
    </a-row>

    <DocumentFormModal
      :open="formOpen"
      :document="editing"
      :folders="folders"
      :known-tags="stats.tags || []"
      :default-folder-id="typeof folderId === 'number' ? folderId : null"
      @close="formOpen = false"
      @saved="onSaved"
    />

    <FolderModal
      :open="folderOpen"
      :folder="editingFolder"
      :folders="folders"
      :default-parent-id="newFolderParent"
      @close="folderOpen = false"
      @saved="onFolderSaved"
    />

    <DocumentPreviewDrawer
      ref="drawerRef"
      :open="drawerOpen"
      :document-id="previewId"
      :can-edit="canEdit"
      :can-delete="canDelete"
      @close="drawerOpen = false"
      @edit="editDocument"
      @changed="refreshAll"
      @deleted="onPreviewDeleted"
    />
  </div>
</template>

<script setup>
/**
 * Document Archive — the whole module on one screen: folder rail, filters,
 * grid/list browsing, upload, preview and versions.
 *
 * The list runs on the standard useCrudTable contract
 * (page/SortField/SortType/search/limit -> { documents, totalRows }), with the
 * archive-specific filters passed through `params`. Sorting is driven by ONE
 * control (the sort select) rather than table headers, because the grid has no
 * headers and two sources of truth for sort order would drift apart.
 */
import { ref, reactive, computed, onMounted, createVNode } from 'vue';
import { message, Empty, Modal } from 'ant-design-vue';
import {
  CloudUploadOutlined, FolderAddOutlined, FolderOpenOutlined, FolderOutlined,
  DatabaseOutlined, ClockCircleOutlined, StarFilled, StarOutlined, InboxOutlined,
  FileOutlined, PlusOutlined, MoreOutlined, EditOutlined, DeleteOutlined,
  DownloadOutlined, EyeOutlined, AppstoreOutlined, UnorderedListOutlined,
  ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DocumentFormModal from './DocumentFormModal.vue';
import DocumentPreviewDrawer from './DocumentPreviewDrawer.vue';
import FolderModal from './FolderModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { kindOf, KIND_OPTIONS, timeAgo } from './documentKinds';
import http from '../../lib/http';
import { t } from '../../i18n';

const auth = useAuthStore();
const { date, dateTime } = useFormat();

const canAdd = computed(() => auth.can('documents_add'));
const canEdit = computed(() => auth.can('documents_edit'));
const canDelete = computed(() => auth.can('documents_delete'));

const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

// ---------------- filters ----------------

const folderId = ref(null);            // null | number | 'none'
const scope = ref('all');              // all | starred | expiring
// `range` stays null rather than [] — a-range-picker treats an empty array as
// a half-set value and renders a stray placeholder.
const filters = reactive({ kind: undefined, tag: undefined, range: null });
const view = ref('grid');
const sortKey = ref('newest');

const VIEW_OPTIONS = [
  { value: 'grid', label: 'Grid' },
  { value: 'list', label: 'List' },
];

const SORTS = [
  { value: 'newest', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'name', label: 'Name A–Z' },
  { value: 'name_desc', label: 'Name Z–A' },
  { value: 'largest', label: 'Largest first' },
  { value: 'expiry', label: 'Expiry soonest' },
];

const SORT_MAP = {
  newest: ['created_at', 'desc'],
  oldest: ['created_at', 'asc'],
  name: ['title', 'asc'],
  name_desc: ['title', 'desc'],
  largest: ['size', 'desc'],
  expiry: ['expiry_date', 'asc'],
};

const crud = useCrudTable('documents', {
  rowsKey: 'documents',
  limit: 12,
  sortField: 'created_at',
  sortType: 'desc',
  params: () => ({
    folder_id: folderId.value === null ? '' : folderId.value,
    kind: filters.kind || '',
    tag: filters.tag || '',
    starred: scope.value === 'starred' ? 1 : '',
    expiring: scope.value === 'expiring' ? 30 : '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

function applySort() {
  const [field, dir] = SORT_MAP[sortKey.value] || SORT_MAP.newest;
  crud.sortField.value = field;
  crud.sortType.value = dir;
  crud.reload();
}

function onPageChange(page, pageSize) {
  crud.page.value = page;
  crud.limit.value = pageSize;
  crud.fetchRows();
}

const hasFilters = computed(() =>
  folderId.value !== null || scope.value !== 'all' || !!filters.kind
  || !!filters.tag || !!crud.search.value || !!filters.range?.length);

const emptyText = computed(() => (hasFilters.value
  ? 'No documents match these filters.'
  : 'The archive is empty.'));

const activeChips = computed(() => {
  const chips = [];
  if (folderId.value === 'none') {
    chips.push({ key: 'folder', label: 'Uncategorised', clear: () => selectFolder(null) });
  } else if (folderId.value !== null) {
    const folder = folders.value.find(f => f.id === folderId.value);
    chips.push({ key: 'folder', label: `Folder: ${folder ? folder.name : folderId.value}`, clear: () => selectFolder(null) });
  }
  if (scope.value === 'starred') chips.push({ key: 'scope', label: 'Starred', clear: () => setScope('all') });
  if (scope.value === 'expiring') chips.push({ key: 'scope', label: 'Expiring in 30 days', clear: () => setScope('all') });
  if (filters.kind) {
    const kind = KIND_OPTIONS.find(k => k.value === filters.kind);
    chips.push({ key: 'kind', label: kind ? kind.label : filters.kind, clear: () => { filters.kind = undefined; crud.reload(); } });
  }
  if (filters.tag) chips.push({ key: 'tag', label: `#${filters.tag}`, clear: () => { filters.tag = undefined; crud.reload(); } });
  if (filters.range?.length === 2) {
    chips.push({
      key: 'range',
      label: `${date(filters.range[0])} → ${date(filters.range[1])}`,
      clear: () => { filters.range = null; crud.reload(); },
    });
  }
  return chips;
});

function clearFilters() {
  folderId.value = null;
  scope.value = 'all';
  filters.kind = undefined;
  filters.tag = undefined;
  filters.range = null;
  crud.search.value = '';
  crud.reload();
}

function showAll() {
  folderId.value = null;
  scope.value = 'all';
  crud.reload();
}

function setScope(next) {
  scope.value = scope.value === next ? 'all' : next;
  crud.reload();
}

function selectFolder(id) {
  folderId.value = id;
  if (id !== null) scope.value = 'all';
  crud.reload();
}

// ---------------- folders ----------------

const folders = ref([]);
const uncategorised = ref(0);
const expandedKeys = ref([]);

const treeSelectedKeys = computed(() => (typeof folderId.value === 'number' ? [folderId.value] : []));

/** Tree nodes for the rail; `count` and `color` are read by the title slot. */
const folderTree = computed(() => {
  const build = parentId => folders.value
    .filter(f => (f.parent_id || null) === parentId)
    .map(f => ({
      key: f.id, title: f.name, count: f.count, color: f.color,
      children: build(f.id),
    }));
  return build(null);
});

/** Same tree, shaped for a-tree-select (bulk move + forms). */
const folderSelectOptions = computed(() => {
  const build = parentId => folders.value
    .filter(f => (f.parent_id || null) === parentId)
    .map(f => ({ title: f.name, value: f.id, children: build(f.id) }));
  return build(null);
});

function onTreeSelect(keys) {
  selectFolder(keys.length ? keys[0] : null);
}

const folderOpen = ref(false);
const editingFolder = ref(null);
const newFolderParent = ref(null);

function newFolder(parentId) {
  editingFolder.value = null;
  newFolderParent.value = parentId;
  folderOpen.value = true;
}

function folderAction(action, node) {
  const folder = folders.value.find(f => f.id === node.key);
  if (!folder) return;

  if (action === 'rename') {
    editingFolder.value = folder;
    newFolderParent.value = null;
    folderOpen.value = true;
  } else if (action === 'child') {
    newFolder(folder.id);
  } else if (action === 'delete') {
    deleteFolder(folder);
  }
}

/** Documents survive: the backend detaches them to the root before deleting. */
function deleteFolder(folder) {
  Modal.confirm({
    title: `Delete “${folder.name}”?`,
    icon: createVNode(ExclamationCircleOutlined),
    content: folder.count
      ? `${folder.count} document${folder.count === 1 ? '' : 's'} will move to Uncategorised — nothing is deleted with the folder.`
      : 'Sub-folders move to the top level. No documents are deleted.',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`documents/folders/${folder.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        if (folderId.value === folder.id) folderId.value = null;
        refreshAll();
      } catch (e) {
        message.error(t('InvalidData', 'Could not delete this folder'));
      }
    },
  });
}

async function onFolderSaved() {
  folderOpen.value = false;
  await loadFolders();
  loadStats();
}

async function loadFolders() {
  try {
    const data = await http.get('documents/folders');
    folders.value = data?.folders || [];
    uncategorised.value = data?.uncategorised || 0;
    // Open every branch on first load: a collapsed tree hides the archive.
    if (!expandedKeys.value.length) expandedKeys.value = folders.value.map(f => f.id);
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the folders'));
  }
}

// ---------------- stats ----------------

const stats = ref({});
const tagOptions = computed(() => (stats.value.tags || []).map(x => ({ value: x.tag, label: `${x.tag} (${x.count})` })));

async function loadStats() {
  try {
    stats.value = await http.get('documents/stats');
  } catch (e) { /* tiles simply stay at zero */ }
}

function refreshAll() {
  crud.fetchRows();
  loadFolders();
  loadStats();
}

// ---------------- rows ----------------

const columns = computed(() => [
  { title: 'Document', key: 'title', dataIndex: 'title' },
  { title: 'Folder', key: 'folder', width: 150 },
  { title: 'Size', dataIndex: 'size_label', key: 'size', width: 100 },
  { title: 'Tags', key: 'tags', width: 180 },
  { title: 'Expiry', key: 'expiry', width: 150 },
  { title: 'Added', key: 'added', width: 130 },
  { title: '', key: 'actions', width: 140 },
]);

function tint(hex) {
  return `${hex}1f`;
}

/** null when there is nothing worth flagging — used by both views. */
function expiryTag(record) {
  const days = record.days_to_expiry;
  if (days === null || days === undefined) return null;
  if (days < 0) return { text: 'Expired', tone: 'danger', color: 'error' };
  if (days === 0) return { text: 'Expires today', tone: 'danger', color: 'error' };
  if (days <= 30) return { text: `${days}d left`, tone: 'warn', color: 'warning' };
  return null;
}

function isSelected(id) {
  return crud.selectedIds.value.includes(id);
}
function toggleSelect(id) {
  const list = crud.selectedIds.value;
  crud.selectedIds.value = list.includes(id) ? list.filter(x => x !== id) : [...list, id];
}

async function toggleStar(record) {
  try {
    const data = await http.post(`documents/${record.id}/star`);
    record.is_starred = !!data.is_starred;
    loadStats();
  } catch (e) {
    message.error(t('InvalidData', 'Could not update this document'));
  }
}

function downloadDocument(record) {
  http.download(`documents/${record.id}/download`, record.file_name)
    .catch(() => message.error('Could not download this file.'));
}

function rowAction(action, record) {
  if (action === 'open') openDocument(record);
  else if (action === 'download') downloadDocument(record);
  else if (action === 'edit') editDocument(record);
  else if (action === 'delete') crud.remove(record, { label: record.title });
}

// ---------------- bulk move ----------------

const moveTarget = ref(undefined);
const moving = ref(false);

async function moveSelected() {
  moving.value = true;
  try {
    await http.post('documents/move/by_selection', {
      selectedIds: crud.selectedIds.value,
      folder_id: moveTarget.value || null,
    });
    message.success('Moved.');
    moveTarget.value = undefined;
    crud.selectedIds.value = [];
    refreshAll();
  } catch (e) {
    message.error(t('InvalidData', 'Could not move the selection'));
  } finally {
    moving.value = false;
  }
}

// ---------------- modals / drawer ----------------

const formOpen = ref(false);
const editing = ref(null);
const drawerOpen = ref(false);
const drawerRef = ref();
const previewId = ref(null);

function openUpload() {
  editing.value = null;
  formOpen.value = true;
}

function editDocument(record) {
  editing.value = record;
  formOpen.value = true;
}

function openDocument(record) {
  previewId.value = record.id;
  drawerOpen.value = true;
}

function onSaved() {
  formOpen.value = false;
  const wasEditing = !!editing.value;
  editing.value = null;
  refreshAll();
  // Keep the drawer in step when the edit came from inside it.
  if (wasEditing && drawerOpen.value) drawerRef.value?.reload?.();
}

function onPreviewDeleted() {
  drawerOpen.value = false;
  previewId.value = null;
  refreshAll();
}

onMounted(() => {
  crud.fetchRows();
  loadFolders();
  loadStats();
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}

/* ---------------- overview tiles ---------------- */
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.stat {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
  transition: border-color 0.15s ease, transform 0.12s ease, box-shadow 0.15s ease;
}
.stat:hover {
  border-color: rgba(109, 40, 217, 0.5);
  transform: translateY(-1px);
}
.stat.active {
  border-color: #6d28d9;
  box-shadow: 0 0 0 1px #6d28d9 inset;
}
.stat--static {
  cursor: default;
}
.stat--static:hover {
  border-color: rgba(128, 128, 128, 0.2);
  transform: none;
}
.stat-ic {
  width: 40px;
  height: 40px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 18px;
}
.stat-ic--brand {
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.12);
}
.stat-ic--info {
  color: #0891b2;
  background: rgba(8, 145, 178, 0.12);
}
.stat-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.stat-ic--star {
  color: #eab308;
  background: rgba(234, 179, 8, 0.16);
}
.stat-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.stat-value {
  font-size: 19px;
  font-weight: 600;
  line-height: 1.2;
}
.stat-label {
  font-size: 12px;
  opacity: 0.6;
}
.stat-note {
  margin-inline-start: auto;
  font-size: 11px;
  opacity: 0.6;
  white-space: nowrap;
}
.stat-note--danger {
  color: #ff4d4f;
  opacity: 1;
}

/* ---------------- folder rail ---------------- */
.rail {
  position: sticky;
  top: 16px;
  margin-bottom: 16px;
}
.rail-nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.rail-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 10px;
  border: 0;
  border-radius: 9px;
  background: none;
  cursor: pointer;
  color: inherit;
  font: inherit;
  font-size: 13.5px;
  text-align: left;
  transition: background 0.15s ease, color 0.15s ease;
}
.rail-item:hover {
  background: rgba(128, 128, 128, 0.12);
}
.rail-item.active {
  background: rgba(109, 40, 217, 0.12);
  color: #6d28d9;
  font-weight: 500;
}
.rail-count {
  margin-inline-start: auto;
  font-size: 11.5px;
  opacity: 0.6;
}
.rail-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 14px 0 4px;
  padding: 0 10px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  opacity: 0.5;
}
.rail-empty {
  padding: 8px 0 4px;
}
.tree-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-width: 0;
}
.tree-dot {
  width: 8px;
  height: 8px;
  flex: none;
  border-radius: 3px;
}
.tree-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tree-count {
  margin-inline-start: auto;
  font-size: 11px;
  opacity: 0.55;
}
.tree-more {
  opacity: 0;
  padding: 0 2px;
  transition: opacity 0.15s ease;
}
.tree-row:hover .tree-more {
  opacity: 0.7;
}

/* ---------------- toolbar ---------------- */
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.tb-search {
  flex: 1 1 240px;
  min-width: 200px;
}
.tb-item {
  width: 150px;
}
.tb-range {
  width: 240px;
}
.seg-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-bottom: 12px;
}
.bulkbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(109, 40, 217, 0.09);
}
.bulk-count {
  font-weight: 500;
}
.bulk-move {
  width: 220px;
}

/* ---------------- grid ---------------- */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
}
.doc {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 14px;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 14px;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.12s ease;
}
.doc:hover {
  border-color: rgba(109, 40, 217, 0.55);
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
  transform: translateY(-2px);
}
.doc.selected {
  border-color: #6d28d9;
  box-shadow: 0 0 0 1px #6d28d9 inset;
}
.doc-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}
.doc-ic {
  width: 42px;
  height: 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 20px;
}
.doc-thumb {
  object-fit: cover;
  background: rgba(128, 128, 128, 0.12);
}
.doc-tools {
  display: flex;
  align-items: center;
  gap: 2px;
}
/* Chrome only when it is useful: on hover, when starred, or when selected. */
.doc-check,
.doc-star {
  opacity: 0;
  transition: opacity 0.15s ease;
}
.doc:hover .doc-check,
.doc:hover .doc-star,
.doc.selected .doc-check,
.doc-star.on {
  opacity: 1;
}
.doc-star.on {
  color: #faad14;
}
.doc-title {
  margin: 2px 0 0;
  font-size: 14px;
  font-weight: 500;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.doc-file {
  margin: 0;
  font-size: 11.5px;
  opacity: 0.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.doc-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.doc-foot {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: auto;
  padding-top: 8px;
  font-size: 11.5px;
  opacity: 0.65;
}
.doc-ext {
  font-weight: 600;
  letter-spacing: 0.03em;
}
.doc-sep {
  opacity: 0.5;
}
.doc-expiry,
.doc-folder {
  position: absolute;
  inset-block-start: 14px;
  inset-inline-end: 14px;
  font-size: 10.5px;
  padding: 2px 7px;
  border-radius: 999px;
  pointer-events: none;
  opacity: 1;
  transition: opacity 0.15s ease;
}
.doc:hover .doc-expiry,
.doc:hover .doc-folder {
  opacity: 0;
}
.doc-expiry.danger {
  color: #ff4d4f;
  background: rgba(255, 77, 79, 0.12);
}
.doc-expiry.warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.doc-folder {
  opacity: 0.55;
  background: rgba(128, 128, 128, 0.14);
  max-width: 45%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.grid-empty {
  padding: 48px 0;
}
.grid-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

/* ---------------- list cells ---------------- */
.cell-doc {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  min-width: 220px;
}
.cell-ic {
  width: 32px;
  height: 32px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  font-size: 15px;
}
.cell-thumb {
  object-fit: cover;
  background: rgba(128, 128, 128, 0.12);
}
.cell-text {
  min-width: 0;
}
.cell-title {
  display: block;
  font-weight: 500;
}
.cell-star {
  color: #faad14;
  font-size: 11px;
  margin-inline-end: 2px;
}
.cell-file {
  display: block;
  font-size: 11.5px;
  opacity: 0.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 320px;
}
.cell-doc:hover .cell-title {
  color: #6d28d9;
}

@media (max-width: 767px) {
  .tb-item,
  .tb-range,
  .bulk-move {
    width: 100%;
  }
  .rail {
    position: static;
  }
}
</style>
