<template>
  <div class="page">
    <PageHeader :title="$t('Assets_List')" :breadcrumb="[$t('Assets'), $t('Assets_List')]">
      <template #actions>
        <a-button @click="$router.push('/assets/dashboard')">
          <template #icon><DashboardOutlined /></template>
          {{ $t('Dashboard') }}
        </a-button>
        <a-button type="primary" @click="$router.push('/assets/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add_Asset') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a class="link" @click="$router.push(`/assets/${record.id}`)">{{ record.name }}</a>
        </template>
        <template v-else-if="column.key === 'book_value'">
          {{ record.book_value === null || record.book_value === undefined ? '—' : money(record.book_value) }}
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'last_verification'">
          {{ record.last_verification ? date(record.last_verification) : '—' }}
        </template>
        <template v-else-if="column.key === 'next_validation'">
          {{ record.next_validation ? date(record.next_validation) : '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Details')">
              <a-button type="text" size="small" @click="$router.push(`/assets/${record.id}`)">
                <template #icon><EyeOutlined style="color: #6d28d9" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/assets/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
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
/** Assets list: GET assets → {assets, totalRows}; DELETE assets/{id}. */
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined, DashboardOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { date, money } = useFormat();
const crud = useCrudTable('assets', { rowsKey: 'assets' });

const columns = computed(() => [
  { title: t('Tag'), dataIndex: 'tag', key: 'tag', sorter: true },
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Category'), dataIndex: 'asset_category_name', key: 'asset_category_name' },
  { title: t('Serial'), dataIndex: 'serial_number', key: 'serial_number' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => statusLabel(r.status) },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Book_Value', 'Book value'), key: 'book_value', dataIndex: 'book_value', align: 'right',
    exportValue: r => (r.book_value ?? '') },
  { title: t('Last_Verification'), key: 'last_verification', dataIndex: 'last_verification', sorter: true, exportValue: r => (r.last_verification ? date(r.last_verification) : '') },
  { title: t('Next_Validation'), key: 'next_validation', dataIndex: 'next_validation', sorter: true, exportValue: r => (r.next_validation ? date(r.next_validation) : '') },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

function statusLabel(s) {
  const map = { in_use: t('In_Use'), maintenance: t('Maintenance'), retired: t('Retired') };
  return map[s] || s;
}

function statusColor(s) {
  if (s === 'in_use') return 'success';
  if (s === 'maintenance') return 'warning';
  if (s === 'retired') return 'default';
  return 'default';
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.link {
  color: #6d28d9;
  cursor: pointer;
}
</style>
