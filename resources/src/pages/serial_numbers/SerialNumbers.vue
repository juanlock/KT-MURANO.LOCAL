<template>
  <div class="page">
    <PageHeader :title="$t('Serial_Numbers')" :breadcrumb="[$t('Products'), $t('Serial_Numbers')]" />

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('Serial_Status') }}</div>
          <a-select
            v-model:value="filters.status" style="width: 100%" allow-clear
            :placeholder="$t('All')" :options="statusOptions" @change="crud.reload()"
          />
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
        <template v-if="column.key === 'serial_number'">
          <a @click="$router.push(`/serial-numbers/${record.id}`)">{{ record.serial_number }}</a>
        </template>
        <template v-else-if="column.key === 'product_name'">
          {{ record.product_name }}
          <a-tag v-if="record.variant_name" style="margin-left: 4px">{{ record.variant_name }}</a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="serialStatusColor(record.status)">{{ $t(`Status_${record.status}`) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-tooltip :title="$t('History')">
            <a-button type="text" size="small" @click="$router.push(`/serial-numbers/${record.id}`)">
              <template #icon><EyeOutlined style="color: #1677ff" /></template>
            </a-button>
          </a-tooltip>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * GET serial_numbers → {serials, totalRows, warehouses}; filters status +
 * warehouse_id. Statuses: available/sold/returned_customer/returned_supplier/
 * damaged/reserved (i18n keys Status_*). Detail page carries the movement log.
 */
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { EyeOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { SERIAL_STATUSES, serialStatusColor } from './serialVocab';

const { t } = useI18n();

const filters = ref({ status: undefined, warehouse_id: undefined });

const crud = useCrudTable('serial_numbers', {
  rowsKey: 'serials',
  params: () => ({
    status: filters.value.status || '',
    warehouse_id: filters.value.warehouse_id || '',
  }),
});
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const statusOptions = computed(() =>
  SERIAL_STATUSES.map(s => ({ value: s, label: t(`Status_${s}`) }))
);

const columns = computed(() => [
  { title: t('Serial_Number'), dataIndex: 'serial_number', key: 'serial_number', sorter: true },
  { title: t('Name_product'), dataIndex: 'product_name', key: 'product_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Serial_Status'), dataIndex: 'status', key: 'status', sorter: true },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
