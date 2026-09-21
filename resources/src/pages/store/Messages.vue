<template>
  <div class="page">
    <PageHeader :title="$t('Messages')" :breadcrumb="[$t('Store'), $t('Messages')]" />

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search
          v-model:value="searchQuery" :placeholder="$t('Search_by_name_email_subject')"
          allow-clear style="max-width: 320px" @search="reload" @change="debouncedSearch"
        />
        <span>
          <a-switch v-model:checked="onlyUnread" @change="reload" />
          <span style="margin-left: 8px">{{ $t('Unread_only') }}</span>
        </span>
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="pagination" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('NodataAvailable') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'is_read'">
            <a-tag :color="record.is_read ? 'default' : 'warning'">
              {{ record.is_read ? $t('Read') : $t('Unread') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="showMessage(record.id)">
                <template #icon><EyeOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="modalOpen"
      :title="selectedMsg ? (selectedMsg.subject || $t('Message')) : $t('Message')"
      :footer="null" width="720px"
    >
      <a-spin :spinning="loadingOne">
        <template v-if="selectedMsg">
          <div class="field">
            <div class="label">{{ $t('From') }}</div>
            <div>
              <strong>{{ selectedMsg.name }}</strong>
              <span style="color: #999"> &lt;{{ selectedMsg.email }}&gt;</span>
              <span v-if="selectedMsg.phone" style="color: #999"> • {{ selectedMsg.phone }}</span>
            </div>
          </div>
          <div class="field">
            <div class="label">{{ $t('Subject') }}</div>
            <div>{{ selectedMsg.subject || '—' }}</div>
          </div>
          <div class="field">
            <div class="label">{{ $t('Received') }}</div>
            <div>{{ selectedMsg.created_at }}</div>
          </div>
          <a-divider style="margin: 12px 0" />
          <div class="label">{{ $t('Message') }}</div>
          <div style="white-space: pre-line">{{ selectedMsg.message }}</div>
        </template>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Contact messages — GET store/messages?page&per_page&sort&dir&q&unread →
 * {data, meta{total}}. Viewing GET store/messages/{id} marks read
 * server-side; the row flips to Read in place (legacy). DELETE .../{id}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EyeOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { PAGE_SIZE_OPTIONS, buildPageSizeOptionText } from '../../composables/useCrudTable';

const { t } = useI18n();

const isLoading = ref(true);
const rows = ref([]);
const total = ref(0);
const searchQuery = ref('');
const onlyUnread = ref(false);
const page = ref(1);
const perPage = ref(10);
const sort = ref({ field: 'created_at', dir: 'desc' });
const modalOpen = ref(false);
const selectedMsg = ref(null);
const loadingOne = ref(false);
let debounceTimer = null;

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Email'), dataIndex: 'email', key: 'email', sorter: true },
  { title: t('Subject'), dataIndex: 'subject', key: 'subject', sorter: true },
  { title: t('Status'), key: 'is_read', width: 100, sorter: true },
  { title: t('Received'), dataIndex: 'created_at', key: 'created_at', sorter: true },
  { title: t('Actions'), key: 'actions', width: 100 },
]);
const pagination = computed(() => ({
  current: page.value,
  pageSize: perPage.value,
  total: total.value,
  showSizeChanger: true,
  pageSizeOptions: PAGE_SIZE_OPTIONS,
  buildOptionText: buildPageSizeOptionText,
}));

function debouncedSearch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(reload, 350);
}
async function fetch() {
  isLoading.value = true;
  try {
    const data = await http.get('store/messages', {
      page: page.value,
      per_page: perPage.value,
      sort: sort.value.field,
      dir: sort.value.dir,
      q: searchQuery.value || '',
      unread: onlyUnread.value ? 1 : 0,
    });
    rows.value = data?.data || [];
    total.value = data?.meta?.total ?? rows.value.length;
  } finally {
    isLoading.value = false;
  }
}
function reload() {
  page.value = 1;
  fetch();
}
function onTableChange(pag, _f, sorter) {
  page.value = pag.current;
  perPage.value = pag.pageSize;
  if (sorter && sorter.field) {
    sort.value = { field: sorter.field, dir: sorter.order === 'ascend' ? 'asc' : 'desc' };
  }
  fetch();
}
async function showMessage(id) {
  loadingOne.value = true;
  selectedMsg.value = null;
  modalOpen.value = true;
  try {
    const data = await http.get(`store/messages/${id}`);
    selectedMsg.value = data;
    const row = rows.value.find(r => r.id === id);
    if (row) row.is_read = true;
  } finally {
    loadingOne.value = false;
  }
}
function remove(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    content: record.subject || record.name,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`store/messages/${record.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(e?.data?.message || e?.data?.error || t('Delete_Therewassomethingwronge'));
      }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.field {
  margin-bottom: 10px;
}
.label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
</style>
