<template>
  <ReportPage
    :title="$t('Stock_Aging_Report')"
    :breadcrumb="[$t('Reports'), $t('Stock_Aging_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/stock_aging"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #filters>
      <a-select v-model:value="dimension" style="width: 140px" :options="dimensionOptions" @change="crud.reload()" />
      <a-select
        v-model:value="warehouseId" style="width: 170px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('warehouse')" :options="filterOpts.warehouses" @change="crud.reload()" />
      <a-select
        v-model:value="brandId" style="width: 160px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Brand')" :options="filterOpts.brands" @change="crud.reload()" />
      <a-select
        v-model:value="categoryId" style="width: 160px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Categorie')" :options="filterOpts.categories" @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'last_inbound_at'">
        {{ record.last_inbound_at ? date(record.last_inbound_at) : '—' }}
      </template>
      <template v-else-if="column.key === 'age_days'">
        <a-tag :color="ageColor(record.age_days)">{{ record.age_days ?? '—' }}</a-tag>
      </template>
      <template v-else-if="column.key === 'age_bucket'">
        <a-tag>{{ record.age_bucket }}</a-tag>
      </template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * Two endpoints: `report/stock_aging/filters` loads the filter option lists
 * once; `report/stock_aging` serves the rows with dimension (product|variant)
 * and comma-joined bucket boundaries (legacy default 30,60,90).
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const dimension = ref('product');
const warehouseId = ref(undefined);
const brandId = ref(undefined);
const categoryId = ref(undefined);
const BUCKETS = [30, 60, 90]; // legacy default boundaries

const filterParams = () => ({
  dimension: dimension.value,
  buckets: BUCKETS.join(','),
  ...(warehouseId.value != null ? { warehouse_id: warehouseId.value } : {}),
  ...(brandId.value != null ? { brand_id: brandId.value } : {}),
  ...(categoryId.value != null ? { category_id: categoryId.value } : {}),
});

const crud = useCrudTable('report/stock_aging', {
  rowsKey: 'report',
  sortField: 'age_days',
  sortType: 'desc',
  params: filterParams,
});

// Filter options come from their own endpoint, loaded once.
const filterOpts = ref({ warehouses: [], brands: [], categories: [] });
async function loadFilters() {
  try {
    const data = await http.get('report/stock_aging/filters');
    const map = list => (list || []).map(x => ({ value: x.id, label: x.name }));
    filterOpts.value = {
      warehouses: map(data.warehouses),
      brands: map(data.brands),
      categories: map(data.categories),
    };
  } catch (e) {
    // Filters stay empty; the table itself still works.
  }
}

const dimensionOptions = computed(() => [
  { value: 'product', label: t('By Product') },
  { value: 'variant', label: t('By Variant') },
]);

const columns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Product'), dataIndex: 'product_name', key: 'product_name', sorter: true },
  { title: t('Variant'), dataIndex: 'variant_name', key: 'variant_name' },
  { title: t('OnHand'), dataIndex: 'on_hand', key: 'on_hand', sorter: true, align: 'right', sum: true },
  {
    title: t('LastInbound'),
    key: 'last_inbound_at',
    dataIndex: 'last_inbound_at',
    sorter: true,
    exportValue: r => (r.last_inbound_at ? date(r.last_inbound_at) : ''),
  },
  { title: t('AgeDays'), key: 'age_days', dataIndex: 'age_days', sorter: true, align: 'right', exportValue: r => r.age_days ?? '' },
  { title: t('Bucket'), key: 'age_bucket', dataIndex: 'age_bucket', exportValue: r => r.age_bucket },
]);

function ageColor(days) {
  const n = Number(days);
  if (!Number.isFinite(n)) return 'default';
  if (n >= 90) return 'error';
  if (n >= 60) return 'warning';
  if (n >= 30) return 'gold';
  return 'success';
}

onMounted(() => {
  crud.fetchRows();
  loadFilters();
});
</script>
