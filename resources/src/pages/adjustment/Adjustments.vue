<template>
  <div class="page">
    <PageHeader :title="$t('ListAdjustments')" :breadcrumb="[$t('Adjustment'), $t('ListAdjustments')]">
      <template #actions>
        <a-button v-if="auth.can('adjustment_add')" type="primary" @click="$router.push('/adjustments/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('CreateAdjustment') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/adjustments/${record.id}`)">{{ record.Ref }}</a>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('DownloadPdf')">
              <a-button type="text" size="small" @click="downloadPdf(record)">
                <template #icon><FilePdfOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('AdjustmentDetail')">
              <a-button type="text" size="small" @click="$router.push(`/adjustments/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('adjustment_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/adjustments/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('adjustment_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.Ref })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * GET adjustments → {adjustments, warehouses, totalRows}; filter params Ref
 * (always-sent empty), warehouse_id, date. No bulk delete in legacy. Rows:
 * date, Ref, warehouse_name, items (product count). PDF adjustment_pdf/{id}.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, FilePdfOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime } = useFormat();
const auth = useAuthStore();

const filters = ref({ date: null, warehouse_id: undefined });

const crud = useCrudTable('adjustments', {
  rowsKey: 'adjustments',
  params: () => ({
    Ref: '',
    warehouse_id: filters.value.warehouse_id || '',
    date: filters.value.date || '',
  }),
});
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('TotalProducts'), dataIndex: 'items', key: 'items', align: 'right' },
  { title: t('Action'), key: 'actions', width: 160, align: 'center' },
]);

function downloadPdf(record) {
  http.download(`adjustment_pdf/${record.id}`, `Adjustment_${record.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
