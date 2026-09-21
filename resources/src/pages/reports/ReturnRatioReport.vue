<template>
  <div class="page">
    <PageHeader :title="$t('Return_Ratio_Report')" :breadcrumb="[$t('Reports'), $t('Return_Ratio_Report')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <DateRangePicker v-model:value="range" :allow-clear="false" @change="load" />
        <a-select
          v-model:value="warehouseId"
          style="width: 200px"
          allow-clear
          show-search
          option-filter-prop="label"
          :placeholder="$t('warehouse')"
          :options="warehouseOptions"
          @change="load"
        />
      </a-space>
    </a-card>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="[16, 16]">
      <a-col v-for="side in sides" :key="side.title" :xs="24" :lg="12">
        <a-card :title="side.title">
          <a-row :gutter="16">
            <a-col :span="12">
              <a-statistic :title="side.totalLabel" :value="money(side.total)" />
            </a-col>
            <a-col :span="12">
              <a-statistic
                :title="side.returnsLabel"
                :value="money(side.returns)"
                :value-style="{ color: '#ff4d4f' }"
              />
            </a-col>
          </a-row>
          <a-divider style="margin: 16px 0 12px" />
          <a-typography-text type="secondary">{{ $t('Return_Ratio_Report') }}</a-typography-text>
          <a-progress
            :percent="round2(side.ratio)"
            :status="side.ratio >= 10 ? 'exception' : 'normal'"
            :stroke-color="side.ratio >= 10 ? '#ff4d4f' : side.ratio >= 5 ? '#faad14' : '#52c41a'"
          />
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * KPI page, not a table. Contract:
 * GET report/return_ratio_report?from&to&warehouse_id →
 *   { data: { sales_sum, returns_sales_sum, sales_return_ratio_pct,
 *             purchases_sum, returns_purchases_sum, purchase_return_ratio_pct },
 *     warehouses }
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money } = useFormat();

const loading = ref(true);
const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);
const data = ref({});
const warehouses = ref([]);

const warehouseOptions = computed(() =>
  warehouses.value.map(w => ({ value: w.id, label: w.name }))
);

const round2 = v => Math.round((Number(v) || 0) * 100) / 100;

const sides = computed(() => [
  {
    title: t('Sales'),
    totalLabel: t('TotalSales'),
    total: data.value.sales_sum,
    returnsLabel: t('SalesReturn'),
    returns: data.value.returns_sales_sum,
    ratio: Number(data.value.sales_return_ratio_pct) || 0,
  },
  {
    title: t('Purchases'),
    totalLabel: t('TotalPurchases'),
    total: data.value.purchases_sum,
    returnsLabel: t('PurchasesReturn'),
    returns: data.value.returns_purchases_sum,
    ratio: Number(data.value.purchase_return_ratio_pct) || 0,
  },
]);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('report/return_ratio_report', {
      from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
      to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
      warehouse_id: warehouseId.value || '',
    });
    data.value = res?.data || {};
    warehouses.value = res?.warehouses || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
