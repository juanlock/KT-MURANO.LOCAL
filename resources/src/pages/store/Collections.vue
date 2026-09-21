<template>
  <div class="page">
    <PageHeader :title="$t('Collections')" :breadcrumb="[$t('Store'), $t('Collections')]">
      <template #extra>
        <a-button type="primary" @click="$router.push('/store/collections/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('New') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="q" :placeholder="$t('Search')" allow-clear style="max-width: 260px" />
        <a-button @click="fetch">
          <template #icon><ReloadOutlined /></template>
        </a-button>
      </div>
      <a-table
        :columns="columns" :data-source="filtered" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :locale="{ emptyText: $t('No_items') }"
      >
        <template #bodyCell="{ column, record, index }">
          <template v-if="column.key === 'order'">
            <a-tag style="font-family: ui-monospace, Menlo, monospace">#{{ index + 1 }}</a-tag>
          </template>
          <template v-else-if="column.key === 'title'">
            <div style="font-weight: 600">{{ record.title }}</div>
            <div v-if="record.description" style="font-size: 12px; color: #999; max-width: 520px">{{ record.description }}</div>
          </template>
          <template v-else-if="column.key === 'slug'">
            <a-tag style="font-family: ui-monospace, Menlo, monospace">{{ record.slug }}</a-tag>
          </template>
          <template v-else-if="column.key === 'limit'">
            {{ record.limit != null ? record.limit : 8 }}
          </template>
          <template v-else-if="column.key === 'products_count'">
            <a-tag>{{ record.products_count != null ? record.products_count : '—' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="$router.push(`/store/collections/edit/${record.id}`)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger :loading="busyId === record.id" @click="destroy(record)">
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
 * Collections — GET admin/store/collections (array or {data}); client-side
 * search/sort by sort_order then title (legacy); DELETE
 * admin/store/collections/{id}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const busyId = ref(null);
const q = ref('');
const collections = ref([]);

const columns = computed(() => [
  { title: '#', key: 'order', width: 70 },
  { title: t('Title'), key: 'title' },
  { title: 'Slug', key: 'slug' },
  { title: t('Limit'), key: 'limit', width: 90, align: 'center' },
  { title: t('Products'), key: 'products_count', width: 110, align: 'center' },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

const sorted = computed(() =>
  collections.value.slice().sort((a, b) => {
    const ao = a.sort_order != null ? a.sort_order : 0;
    const bo = b.sort_order != null ? b.sort_order : 0;
    if (ao !== bo) return ao - bo;
    return String(a.title || '').localeCompare(String(b.title || ''));
  }));
const filtered = computed(() => {
  const term = (q.value || '').toLowerCase();
  if (!term) return sorted.value;
  return sorted.value.filter(c =>
    String(c.title || '').toLowerCase().includes(term)
    || String(c.slug || '').toLowerCase().includes(term));
});

async function fetch() {
  isLoading.value = true;
  try {
    const resp = await http.get('admin/store/collections');
    let payload = resp && Array.isArray(resp.data) ? resp.data : resp;
    if (!Array.isArray(payload)) payload = [];
    collections.value = payload;
  } catch (e) {
    message.error(t('Failed_to_load'));
  } finally {
    isLoading.value = false;
  }
}
function destroy(c) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: c.title,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      busyId.value = c.id;
      try {
        await http.delete(`admin/store/collections/${c.id}`);
        message.success(t('Deleted_successfully'));
        collections.value = collections.value.filter(x => x.id !== c.id);
      } catch (e) {
        message.error(t('Delete_failed'));
      } finally {
        busyId.value = null;
      }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.toolbar {
  display: flex;
  gap: 12px;
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
</style>
