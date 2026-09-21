<template>
  <div class="page">
    <PageHeader :title="$t('Subscription_Product') || $t('Subscriptions')" :breadcrumb="[$t('Subscriptions')]">
      <template #actions>
        <a-button type="primary" @click="$router.push('/subscriptions/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'next_billing_date'">{{ date(record.next_billing_date) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-switch
            :checked="record.status === true || record.status === 'active'"
            size="small"
            @change="v => toggleStatus(record, v)"
          />
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Subscription_details') || $t('View')">
              <a-button type="text" size="small" @click="$router.push(`/subscriptions/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.product_name })">
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
 * Subscriptions list — GET subscriptions → {subscriptions, totalRows};
 * status toggle PUT subscriptions/{id}/status {status: active|canceled}
 * (legacy maps the switch boolean); DELETE subscriptions/{id}. Details is a
 * separate page; there is no edit in legacy.
 */
import { computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EyeOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const crud = useCrudTable('subscriptions', { rowsKey: 'subscriptions' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('product_name'), dataIndex: 'product_name', key: 'product_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Billing_Cycle'), dataIndex: 'billing_cycle', key: 'billing_cycle', width: 120 },
  { title: t('total_cycles'), dataIndex: 'total_cycles', key: 'total_cycles', align: 'right', width: 100 },
  { title: t('remaining_cycles'), dataIndex: 'remaining_cycles', key: 'remaining_cycles', align: 'right', width: 110 },
  { title: t('next_billing_date'), dataIndex: 'next_billing_date', key: 'next_billing_date', width: 140 },
  { title: t('Status'), key: 'status', width: 90, align: 'center' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function toggleStatus(record, enabled) {
  record.status = enabled;
  try {
    await http.put(`subscriptions/${record.id}/status`, { status: enabled ? 'active' : 'canceled' });
    message.success(t('Subscription_status_updated_successfully'));
  } catch (e) {
    record.status = !enabled;
    message.warning(t('Failed_to_update_subscription_status'));
  }
}
</script>
