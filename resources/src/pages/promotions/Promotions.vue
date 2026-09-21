<template>
  <div class="page">
    <PageHeader :title="'Promotions'" :breadcrumb="[$t('Sales'), 'Promotions']">
      <template #actions>
        <a-button @click="$router.push('/promotions/usages')">
          <template #icon><BarChartOutlined /></template>
          Usage Report
        </a-button>
        <a-button type="primary" @click="$router.push('/promotions/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card>
      <!-- Toolbar: search + filters -->
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value"
          :placeholder="$t('Search')"
          allow-clear
          class="toolbar-search"
          @search="crud.reload"
        />
        <a-select
          v-model:value="filters.kind" class="toolbar-filter" :options="[
            { value: '', label: 'All types' },
            { value: 'discount', label: 'Discount' },
            { value: 'promotion', label: 'Promotion' },
          ]"
          @change="crud.reload"
        />
        <a-select
          v-model:value="filters.active" class="toolbar-filter" :options="[
            { value: '', label: 'All statuses' },
            { value: '1', label: 'Active' },
            { value: '0', label: 'Inactive' },
          ]"
          @change="crud.reload"
        />
        <a-select
          v-model:value="filters.warehouse_id" class="toolbar-filter-wide"
          allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Warehouse')" :options="warehouseOptions"
          @change="crud.reload"
        />
      </div>

      <a-table
        :columns="columns"
        :data-source="crud.rows.value"
        :loading="crud.loading.value"
        :pagination="crud.pagination.value"
        :row-key="r => r.id"
        :scroll="{ x: 'max-content' }"
        @change="crud.onChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <div class="promo-cell">
              <span class="promo-badge" :class="record.kind"><PercentageOutlined v-if="record.kind === 'discount'" /><GiftOutlined v-else /></span>
              <div class="promo-cell-text">
                <div class="promo-name">
                  {{ record.name }}
                  <a-tag v-if="record.code" class="promo-code">{{ record.code }}</a-tag>
                </div>
                <div v-if="record.description" class="promo-desc">{{ record.description }}</div>
              </div>
            </div>
          </template>

          <template v-else-if="column.key === 'kind'">
            <a-tag :color="record.kind === 'discount' ? 'processing' : 'success'">
              {{ record.kind === 'discount' ? 'Discount' : 'Promotion' }}
            </a-tag>
          </template>

          <template v-else-if="column.key === 'value'">
            <span class="promo-value">
              {{ record.discount_type === 'percentage' ? `${Number(record.discount_value)}%` : money(record.discount_value) }}
            </span>
          </template>

          <template v-else-if="column.key === 'warehouses'">
            <template v-if="record.warehouses && record.warehouses.length">
              <a-tag v-for="w in record.warehouses.slice(0, 2)" :key="w.id">{{ w.name }}</a-tag>
              <a-tooltip v-if="record.warehouses.length > 2" :title="record.warehouses.slice(2).map(w => w.name).join(', ')">
                <a-tag>+{{ record.warehouses.length - 2 }}</a-tag>
              </a-tooltip>
            </template>
            <span v-else class="muted">—</span>
          </template>

          <template v-else-if="column.key === 'window'">
            <span v-if="!record.starts_at && !record.ends_at" class="muted">Always</span>
            <span v-else class="window-text">{{ windowLabel(record) }}</span>
          </template>

          <template v-else-if="column.key === 'is_active'">
            <a-switch
              :checked="!!record.is_active" size="small"
              :loading="togglingId === record.id"
              @change="toggle(record)"
            />
          </template>

          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('Edit')">
                <a-button size="small" type="text" @click="$router.push(`/promotions/${record.id}/edit`)">
                  <template #icon><EditOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Delete')">
                <a-button size="small" type="text" danger @click="crud.remove(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>

        <template #emptyText>
          <a-empty style="padding: 40px 0" description="No promotions yet.">
            <a-button type="primary" @click="$router.push('/promotions/create')">
              <template #icon><PlusOutlined /></template>
              Create your first promotion
            </a-button>
          </a-empty>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Promotions list — ported from the ksa project's promotions/index.vue onto the
 * Ant-native stack. Same API contract: GET promotions (page/SortField/SortType/
 * search/limit + kind/active/warehouse_id filters) → { promotions, totalRows };
 * POST promotions/{id}/toggle flips is_active.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, BarChartOutlined, EditOutlined, DeleteOutlined,
  PercentageOutlined, GiftOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { money, date } = useFormat();

const filters = ref({ kind: '', active: '', warehouse_id: undefined });

const crud = useCrudTable('promotions', {
  rowsKey: 'promotions',
  params: () => ({
    kind: filters.value.kind || '',
    active: filters.value.active || '',
    warehouse_id: filters.value.warehouse_id || '',
  }),
});

const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const columns = computed(() => [
  { title: 'Promotion', dataIndex: 'name', key: 'name', sorter: true },
  { title: 'Type', dataIndex: 'kind', key: 'kind', sorter: true, width: 120 },
  { title: 'Value', dataIndex: 'discount_value', key: 'value', sorter: true, width: 110 },
  { title: 'Warehouses', key: 'warehouses', width: 220 },
  { title: 'Window', key: 'window', width: 200 },
  { title: 'Priority', dataIndex: 'priority', key: 'priority', sorter: true, width: 90 },
  { title: 'Active', key: 'is_active', width: 80 },
  { title: '', key: 'actions', width: 90 },
]);

function windowLabel(record) {
  const from = record.starts_at ? date(record.starts_at) : null;
  const to = record.ends_at ? date(record.ends_at) : null;
  if (from && to) return `${from} → ${to}`;
  if (from) return `From ${from}`;
  return `Until ${to}`;
}

const togglingId = ref(null);
async function toggle(record) {
  togglingId.value = record.id;
  try {
    const data = await http.post(`promotions/${record.id}/toggle`);
    record.is_active = !!data.is_active;
  } catch (e) {
    message.error(e?.data?.message || 'Could not update the promotion.');
  } finally {
    togglingId.value = null;
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const data = await http.get('warehouses', { page: 1, SortField: 'id', SortType: 'asc', search: '', limit: -1 });
    warehouses.value = data?.warehouses || [];
  } catch (e) { /* filter stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.65);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 16px;
}
.toolbar-search {
  width: 260px;
  max-width: 100%;
}
.toolbar-filter {
  width: 140px;
}
.toolbar-filter-wide {
  width: 200px;
}
.promo-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 220px;
}
.promo-badge {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  font-size: 15px;
}
.promo-badge.discount {
  color: #1677ff;
  background: rgba(22, 119, 255, 0.1);
}
.promo-badge.promotion {
  color: #52c41a;
  background: rgba(82, 196, 26, 0.12);
}
.promo-cell-text {
  min-width: 0;
}
.promo-name {
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.promo-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
  margin-inline-end: 0;
}
.promo-desc {
  font-size: 12px;
  opacity: 0.55;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.promo-value {
  font-weight: 600;
  color: #7c3aed;
}
.window-text {
  font-size: 12.5px;
}
@media (max-width: 640px) {
  .toolbar-search,
  .toolbar-filter,
  .toolbar-filter-wide {
    width: 100%;
  }
}
</style>
