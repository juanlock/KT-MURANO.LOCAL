<template>
  <div class="page">
    <PageHeader :title="'Promotion Usage Report'" :breadcrumb="['Promotions', 'Usage Report']">
      <template #actions>
        <a-button @click="$router.push('/promotions')">
          <template #icon><ArrowLeftOutlined /></template>
          Back to Promotions
        </a-button>
      </template>
    </PageHeader>

    <!-- Filters -->
    <a-card size="small" style="margin-bottom: 16px">
      <div class="filters">
        <a-range-picker
          v-model:value="filters.range" value-format="YYYY-MM-DD"
          class="filter-range" @change="reload"
        />
        <a-select
          v-model:value="filters.promotion_id" class="filter-select"
          allow-clear show-search option-filter-prop="label"
          placeholder="All promotions" :options="promotionOptions"
          @change="reload"
        />
        <a-select
          v-model:value="filters.warehouse_id" class="filter-select"
          allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Warehouse')" :options="warehouseOptions"
          @change="reload"
        />
        <a-button @click="resetFilters">
          <template #icon><ReloadOutlined /></template>
          Reset
        </a-button>
      </div>
    </a-card>

    <!-- KPIs -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :sm="8">
        <a-card size="small" class="kpi">
          <div class="kpi-icon uses"><NumberOutlined /></div>
          <a-statistic title="Total uses" :value="totals.uses || 0" />
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="8">
        <a-card size="small" class="kpi">
          <div class="kpi-icon discount"><PercentageOutlined /></div>
          <a-statistic title="Total discount" :value="money(totals.total_discount || 0)" />
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="8">
        <a-card size="small" class="kpi">
          <div class="kpi-icon promos"><GiftOutlined /></div>
          <a-statistic title="Promotions used" :value="totals.promotions_with_usage || 0" />
        </a-card>
      </a-col>
    </a-row>

    <!-- Per-promotion summary -->
    <a-card size="small" title="Summary" style="margin-bottom: 16px">
      <a-table
        :columns="summaryColumns" :data-source="summary"
        :pagination="false" size="small" :row-key="r => r.promotion_id"
        :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'code'">
            <a-tag v-if="record.code" class="code-tag">{{ record.code }}</a-tag>
            <span v-else class="muted">—</span>
          </template>
          <template v-else-if="column.key === 'kind'">
            <a-tag :color="record.kind === 'discount' ? 'processing' : 'success'">{{ record.kind || '—' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'total_discount'">
            <span class="amount">{{ money(record.total_discount) }}</span>
          </template>
          <template v-else-if="column.key === 'cap'">
            <template v-if="record.usage_limit_total !== null">
              <div class="cap-cell">
                <span>{{ record.uses }} / {{ record.usage_limit_total }}</span>
                <a-progress
                  :percent="Math.min(100, Math.round((record.uses / record.usage_limit_total) * 100))"
                  :show-info="false" size="small"
                  :stroke-color="record.uses >= record.usage_limit_total ? '#ff4d4f' : '#52c41a'"
                />
              </div>
            </template>
            <span v-else class="muted">Unlimited</span>
          </template>
        </template>
        <template #emptyText>
          <a-empty description="No usage recorded in this window." style="padding: 24px 0" />
        </template>
      </a-table>
    </a-card>

    <!-- Details -->
    <a-card size="small" title="Details">
      <a-input-search
        v-model:value="crud.search.value" :placeholder="$t('Search')"
        allow-clear style="max-width: 280px; margin-bottom: 12px"
        @search="crud.reload"
      />
      <a-table
        :columns="detailColumns"
        :data-source="crud.rows.value"
        :loading="crud.loading.value"
        :pagination="crud.pagination.value"
        :row-key="r => r.id"
        :scroll="{ x: 'max-content' }"
        size="small"
        @change="crud.onChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'used_at'">{{ dateTime(record.used_at) }}</template>
          <template v-else-if="column.key === 'promotion'">
            <strong>{{ record.promotion ? record.promotion.name : '—' }}</strong>
          </template>
          <template v-else-if="column.key === 'code'">
            <a-tag v-if="record.code" class="code-tag">{{ record.code }}</a-tag>
            <span v-else class="muted">—</span>
          </template>
          <template v-else-if="column.key === 'sale'">
            <span v-if="record.sale">{{ record.sale.Ref }}</span>
            <span v-else class="muted">—</span>
          </template>
          <template v-else-if="column.key === 'client'">
            <span v-if="record.client">{{ record.client.name }}</span>
            <span v-else class="muted">Guest</span>
          </template>
          <template v-else-if="column.key === 'warehouse'">
            <span v-if="record.warehouse && record.warehouse.name">{{ record.warehouse.name }}</span>
            <span v-else class="muted">—</span>
          </template>
          <template v-else-if="column.key === 'discount_amount'">
            <span class="amount-negative">−{{ money(record.discount_amount) }}</span>
          </template>
        </template>
        <template #emptyText>
          <a-empty description="No usage recorded yet." style="padding: 24px 0" />
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Promotion usage report — ported from the ksa project's promotions/usages.vue.
 * GET promotions/usages (paginated, from/to/promotion_id/warehouse_id filters)
 * → { usages, totalRows }; GET promotions/usages_summary → { summary, totals }.
 */
import { ref, computed, onMounted } from 'vue';
import {
  ArrowLeftOutlined, ReloadOutlined, NumberOutlined, PercentageOutlined,
  GiftOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { money, dateTime } = useFormat();

const filters = ref({ range: null, promotion_id: undefined, warehouse_id: undefined });

const filterParams = () => ({
  from: filters.value.range?.[0] || '',
  to: filters.value.range?.[1] || '',
  promotion_id: filters.value.promotion_id || '',
  warehouse_id: filters.value.warehouse_id || '',
});

const crud = useCrudTable('promotions/usages', {
  rowsKey: 'usages',
  sortField: 'used_at',
  limit: 15,
  params: filterParams,
});

const summary = ref([]);
const totals = ref({ uses: 0, total_discount: 0, promotions_with_usage: 0 });
const promotionsList = ref([]);
const warehouses = ref([]);

const promotionOptions = computed(() => promotionsList.value.map(p => ({
  value: p.id,
  label: p.code ? `${p.name} (${p.code})` : p.name,
})));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const summaryColumns = [
  { title: 'Promotion', dataIndex: 'name', key: 'name' },
  { title: 'Code', key: 'code', width: 130 },
  { title: 'Type', key: 'kind', width: 110 },
  { title: 'Uses', dataIndex: 'uses', key: 'uses', width: 80, align: 'right' },
  { title: 'Unique customers', dataIndex: 'unique_customers', key: 'unique_customers', width: 140, align: 'right' },
  { title: 'Total discount', key: 'total_discount', width: 130, align: 'right' },
  { title: 'Cap progress', key: 'cap', width: 160 },
];

const detailColumns = [
  { title: 'Date', dataIndex: 'used_at', key: 'used_at', sorter: true, width: 160 },
  { title: 'Promotion', key: 'promotion' },
  { title: 'Code', key: 'code', width: 120 },
  { title: 'Sale', key: 'sale', width: 110 },
  { title: 'Client', key: 'client', width: 150 },
  { title: 'Warehouse', key: 'warehouse', width: 150 },
  { title: 'Discount', dataIndex: 'discount_amount', key: 'discount_amount', sorter: true, width: 110, align: 'right' },
];

async function fetchSummary() {
  try {
    const p = filterParams();
    const data = await http.get('promotions/usages_summary', {
      from: p.from, to: p.to, warehouse_id: p.warehouse_id,
    });
    summary.value = data?.summary || [];
    totals.value = data?.totals || { uses: 0, total_discount: 0, promotions_with_usage: 0 };
  } catch (e) { /* KPI row keeps its zeros */ }
}

function reload() {
  crud.reload();
  fetchSummary();
}

function resetFilters() {
  filters.value = { range: null, promotion_id: undefined, warehouse_id: undefined };
  crud.search.value = '';
  reload();
}

onMounted(async () => {
  reload();
  try {
    const data = await http.get('promotions', { page: 1, SortField: 'name', SortType: 'asc', search: '', limit: -1 });
    promotionsList.value = data?.promotions || [];
  } catch (e) { /* filter stays empty */ }
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
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.filter-range {
  width: 260px;
}
.filter-select {
  width: 220px;
}
.kpi :deep(.ant-card-body) {
  display: flex;
  align-items: center;
  gap: 14px;
}
.kpi-icon {
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 18px;
}
.kpi-icon.uses {
  color: #1677ff;
  background: rgba(22, 119, 255, 0.1);
}
.kpi-icon.discount {
  color: #7c3aed;
  background: rgba(124, 58, 237, 0.1);
}
.kpi-icon.promos {
  color: #52c41a;
  background: rgba(82, 196, 26, 0.12);
}
.code-tag {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
}
.amount {
  font-weight: 600;
}
.amount-negative {
  font-weight: 600;
  color: #ff4d4f;
}
.cap-cell {
  min-width: 120px;
  font-size: 12px;
}
@media (max-width: 640px) {
  .filter-range,
  .filter-select {
    width: 100%;
  }
}
</style>
