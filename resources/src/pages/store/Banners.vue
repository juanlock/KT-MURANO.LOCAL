<template>
  <div class="page">
    <PageHeader :title="$t('Banners')" :breadcrumb="[$t('Store'), $t('Banners')]">
      <template #extra>
        <a-button type="primary" @click="$router.push('/store/banners/edit/new')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="pagination" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('NodataAvailable') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'preview'">
            <img :src="record.image_url" style="height: 32px; border-radius: 4px" />
          </template>
          <template v-else-if="column.key === 'active'">
            <a-tag :color="record.active ? 'success' : 'default'">
              {{ record.active ? $t('Active') : $t('Disabled') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="$router.push(`/store/banners/edit/${record.id}`)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Store banners — GET store/banners?page&per_page&sort&dir → {data,
 * meta{total}}; DELETE store/banners/{id}. Create/edit on a dedicated page.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { PAGE_SIZE_OPTIONS, buildPageSizeOptionText } from '../../composables/useCrudTable';

const { t } = useI18n();

const isLoading = ref(true);
const rows = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(10);
const sort = ref({ field: 'updated_at', dir: 'desc' });

const columns = computed(() => [
  { title: t('Preview'), key: 'preview', width: 90 },
  { title: t('Title'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('Position'), dataIndex: 'position', key: 'position', sorter: true },
  { title: t('Active'), key: 'active', width: 100, sorter: true },
  { title: t('Updated'), dataIndex: 'updated_at', key: 'updated_at', sorter: true },
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

async function fetch() {
  isLoading.value = true;
  try {
    const data = await http.get('store/banners', {
      page: page.value,
      per_page: perPage.value,
      sort: sort.value.field,
      dir: sort.value.dir,
    });
    rows.value = data.data || [];
    total.value = data.meta?.total || rows.value.length;
  } finally {
    isLoading.value = false;
  }
}
function onTableChange(pag, _f, sorter) {
  page.value = pag.current;
  perPage.value = pag.pageSize;
  if (sorter && sorter.field) {
    sort.value = { field: sorter.field, dir: sorter.order === 'ascend' ? 'asc' : 'desc' };
  }
  fetch();
}
function remove(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    content: record.title,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`store/banners/${record.id}`);
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
