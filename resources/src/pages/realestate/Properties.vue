<template>
  <div class="page">
    <PageHeader :title="$t('Properties')" :breadcrumb="[$t('Real_Estate'), $t('Properties')]">
      <template #extra>
        <a-button type="primary" @click="$router.push('/realestate/properties/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add_Property') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]">
        <a-col :xs="24" :md="8">
          <div class="filter-label">{{ $t('Property_Type') }}</div>
          <a-select v-model:value="categoryFilter" style="width: 100%" @change="crud.reload()">
            <a-select-option value="">{{ $t('All') }}</a-select-option>
            <a-select-option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</a-select-option>
          </a-select>
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('Purpose') }}</div>
          <a-select v-model:value="purposeFilter" style="width: 100%" @change="crud.reload()">
            <a-select-option value="">{{ $t('All') }}</a-select-option>
            <a-select-option value="sale">{{ $t('For_Sale') }}</a-select-option>
            <a-select-option value="rent">{{ $t('For_Rent') }}</a-select-option>
          </a-select>
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select v-model:value="statusFilter" style="width: 100%" @change="crud.reload()">
            <a-select-option value="">{{ $t('All') }}</a-select-option>
            <a-select-option value="available">{{ $t('Available') }}</a-select-option>
            <a-select-option value="sold">{{ $t('Sold') }}</a-select-option>
            <a-select-option value="rented">{{ $t('Rented') }}</a-select-option>
          </a-select>
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'featured_image'">
          <img
            v-if="record.featured_image" :src="'/' + record.featured_image"
            style="width: 54px; height: 42px; object-fit: cover; border-radius: 6px"
          />
          <span v-else style="color: #999">—</span>
        </template>
        <template v-else-if="column.key === 'title'">
          <a style="font-weight: 600" @click="$router.push(`/realestate/properties/edit/${record.id}`)">{{ record.title }}</a>
          <div style="font-size: 12px; color: #999">
            {{ [record.city, record.region].filter(Boolean).join(', ') }}
          </div>
        </template>
        <template v-else-if="column.key === 'category'">
          {{ record.category ? record.category.name : '—' }}
        </template>
        <template v-else-if="column.key === 'purpose'">
          <a-tag :color="record.purpose === 'rent' ? 'cyan' : 'blue'">
            {{ record.purpose === 'rent' ? $t('For_Rent') : $t('For_Sale') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'price'">
          {{ formatPrice(record.price) }}
        </template>
        <template v-else-if="column.key === 'featured'">
          <StarFilled v-if="record.featured" style="color: #faad14" />
          <span v-else style="color: #999">—</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button size="small" @click="$router.push(`/realestate/properties/edit/${record.id}`)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="crud.remove(record, { label: record.title })">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Properties — GET realestate/properties (+category/purpose/status filters)
 * → {properties, totalRows}; categories for the filter from
 * realestate/categories_all → {categories}. Bulk delete standard
 * by_selection. Price displayed with plain Intl.NumberFormat like legacy.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, StarFilled } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

const categoryFilter = ref('');
const purposeFilter = ref('');
const statusFilter = ref('');
const categories = ref([]);

const crud = useCrudTable('realestate/properties', {
  rowsKey: 'properties',
  params: () => ({
    category: categoryFilter.value,
    purpose: purposeFilter.value,
    status: statusFilter.value,
  }),
});

const columns = computed(() => [
  { title: t('Image'), key: 'featured_image', width: 80 },
  { title: t('Property_Title'), key: 'title' },
  { title: t('Property_Type'), key: 'category' },
  { title: t('Purpose'), key: 'purpose', width: 110 },
  { title: t('Price'), key: 'price', align: 'right', sorter: true },
  { title: t('Featured'), key: 'featured', width: 90, align: 'center' },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('Action'), key: 'actions', width: 100 },
]);

function formatPrice(v) {
  return new Intl.NumberFormat().format(Number(v || 0));
}
function statusLabel(s) {
  return { available: t('Available'), sold: t('Sold'), rented: t('Rented') }[s] || s;
}
function statusColor(s) {
  return { available: 'success', sold: 'error', rented: 'warning' }[s] || 'default';
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const data = await http.get('realestate/categories_all');
    categories.value = data.categories || [];
  } catch (e) { /* filter stays empty */ }
});
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
