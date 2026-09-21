<template>
  <div class="page">
    <PageHeader :title="$t('Subscription_details')" :breadcrumb="[$t('Subscriptions'), $t('Subscription_details')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px">
          <a-avatar :size="44">{{ (subscription.client || '?').charAt(0) }}</a-avatar>
          <div>
            <div style="font-weight: 600">{{ subscription.client }}</div>
            <a-tag :color="statusColor(subscription.status)">{{ subscription.status }}</a-tag>
          </div>
        </div>
        <a-descriptions :column="{ xs: 1, md: 3 }" size="small" bordered>
          <a-descriptions-item :label="$t('ProductName')">{{ subscription.product }}</a-descriptions-item>
          <a-descriptions-item :label="$t('warehouse')">{{ subscription.warehouse }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Billing_Cycle')">{{ subscription.billing_cycle }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Price_Per_Cycle')">
            <span style="color: #52c41a">{{ subscription.price_per_cycle }}</span>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('next_billing_date')">
            <a-tag color="processing">{{ subscription.next_billing_date }}</a-tag>
          </a-descriptions-item>
        </a-descriptions>
      </a-card>

      <a-card size="small" :title="'Invoices'">
        <a-table
          :columns="invoiceColumns" :data-source="invoices"
          size="small" :row-key="(_r, i) => i"
          :locale="{ emptyText: $t('NodataAvailable') }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'ref'">
              <a @click="$router.push(`/sales/${record.sale_id}`)">{{ record.ref }}</a>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="statusColor(record.status)">{{ record.status }}</a-tag>
            </template>
          </template>
        </a-table>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Subscription details — GET subscriptions/{id} → {subscription, invoices,
 * invoiceFields}. Invoice refs link to the migrated sale detail page.
 * Status colors: active/paid=green, unpaid=red, partial=orange (legacy map).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();

const loading = ref(true);
const subscription = ref({});
const invoices = ref([]);

function statusColor(status) {
  return { active: 'success', unpaid: 'error', partial: 'warning', paid: 'success' }[status] || 'default';
}

const invoiceColumns = computed(() => [
  { title: t('Reference'), key: 'ref' },
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Total'), dataIndex: 'total', key: 'total', align: 'right' },
  { title: t('Status'), key: 'status', width: 110 },
]);

onMounted(async () => {
  try {
    const data = await http.get(`subscriptions/${route.params.id}`);
    subscription.value = data.subscription || {};
    invoices.value = data.invoices || [];
  } catch (e) {
    message.error('Unable to load subscription details');
  } finally {
    loading.value = false;
  }
});
</script>
