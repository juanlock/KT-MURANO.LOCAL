<template>
  <div class="page">
    <PageHeader :title="$t('Subscribers')" :breadcrumb="[$t('Store'), $t('Subscribers')]" />

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading"
        :pagination="pagination" size="middle" row-key="id"
        :locale="{ emptyText: $t('NodataAvailable') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'actions'">
            <a-button size="small" danger @click="remove(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Store subscribers — GET store/subscribers?page&per_page&sort&dir →
 * {data, meta{total}}; DELETE store/subscribers/{id}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { PAGE_SIZE_OPTIONS, buildPageSizeOptionText } from '../../composables/useCrudTable';

const { t } = useI18n();

const isLoading = ref(true);
const rows = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(10);
const sort = ref({ field: 'created_at', dir: 'desc' });

const columns = computed(() => [
  { title: t('Email'), dataIndex: 'email', key: 'email', sorter: true },
  { title: t('date'), dataIndex: 'created_at', key: 'created_at', sorter: true },
  { title: t('Actions'), key: 'actions', width: 80 },
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
    const data = await http.get('store/subscribers', {
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
    content: record.email,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`store/subscribers/${record.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(t('Delete_Therewassomethingwronge'));
      }
    },
  });
}

onMounted(fetch);
</script>
