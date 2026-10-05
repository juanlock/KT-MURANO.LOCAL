<template>
  <div class="page">
    <PageHeader :title="$t('Tax_Summary_Report')" :breadcrumb="[$t('Accounting'), $t('Tax_Summary_Report')]" />

    <a-card size="small" style="margin-bottom: 16px">
      <!-- The system date filter: presets + editable Start date / to. -->
      <a-row :gutter="[12, 12]" align="middle">
        <a-col flex="auto">
          <PresetRangeFilter v-model:value="range" @change="fetch" />
        </a-col>
        <a-col>
          <a-button type="primary" :loading="isLoading" @click="fetch">
            <template #icon><ReloadOutlined /></template>
            {{ $t('Refresh') }}
          </a-button>
        </a-col>
      </a-row>
    </a-card>

    <a-alert v-if="error" type="error" show-icon :message="error" style="margin-bottom: 16px" />

    <a-spin :spinning="isLoading">
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('Sales_Tax') + ' (' + $t('Output_Tax') + ')'" style="height: 100%">
            <div class="line muted"><span>{{ $t('Total_Sales') }}</span><span>{{ number(data.sales) }}</span></div>
            <div class="line muted"><span>{{ $t('Sale_Returns') }}</span><span style="color: #cf1322">- {{ number(data.sale_returns) }}</span></div>
            <a-divider style="margin: 10px 0" />
            <div class="line"><strong>{{ $t('Net_Sales') }}</strong><strong>{{ number(data.taxable_sales) }}</strong></div>
            <div class="line"><strong>{{ $t('Output_Tax') }}</strong><strong style="color: #3f8600">{{ number(data.output_tax) }}</strong></div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-card size="small" :title="$t('Purchase_Tax') + ' (' + $t('Input_Tax') + ')'" style="height: 100%">
            <div class="line muted"><span>{{ $t('Total_Purchases') }}</span><span>{{ number(data.purchases) }}</span></div>
            <div class="line muted"><span>{{ $t('Purchase_Returns') }}</span><span style="color: #cf1322">- {{ number(data.purchase_returns) }}</span></div>
            <a-divider style="margin: 10px 0" />
            <div class="line"><strong>{{ $t('Net_Purchases') }}</strong><strong>{{ number(data.taxable_purchases) }}</strong></div>
            <div class="line"><strong>{{ $t('Input_Tax') }}</strong><strong style="color: #1677ff">{{ number(data.input_tax) }}</strong></div>
          </a-card>
        </a-col>
      </a-row>

      <a-alert
        :type="data.net_tax >= 0 ? 'warning' : 'success'"
        show-icon
        :message="$t('Net_Tax') + ': ' + number(data.net_tax)"
        :description="data.net_tax >= 0 ? $t('Tax_Payable') : $t('Tax_Refund')"
      />
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Tax summary (v2) — GET accounting/v2/reports/tax-summary?from&to →
 * output/input tax breakdown + net_tax. Defaults to the current month
 * (legacy initializeDates).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import PresetRangeFilter from '../../../components/PresetRangeFilter.vue';
import { presetDates } from '../../../lib/datePresets';
import { useFormat } from '../../../composables/useFormat';
import http from '../../../lib/http';

const { t } = useI18n();
const { number } = useFormat();

const isLoading = ref(false);
const error = ref(null);
// Opens on this month (legacy default); null = All dates.
const thisMonth = presetDates('month');
const range = ref([thisMonth.from, thisMonth.to]);
const filters = computed(() => ({ from: range.value?.[0] || '', to: range.value?.[1] || '' }));
const data = ref({
  sales: 0, sale_returns: 0, taxable_sales: 0, output_tax: 0,
  purchases: 0, purchase_returns: 0, taxable_purchases: 0, input_tax: 0, net_tax: 0,
});

async function fetch() {
  isLoading.value = true;
  error.value = null;
  try {
    const r = await http.get('accounting/v2/reports/tax-summary', filters.value);
    if (r) {
      data.value = {
        sales: parseFloat(r.sales || 0),
        sale_returns: parseFloat(r.sale_returns || 0),
        taxable_sales: parseFloat(r.taxable_sales || 0),
        output_tax: parseFloat(r.output_tax || 0),
        purchases: parseFloat(r.purchases || 0),
        purchase_returns: parseFloat(r.purchase_returns || 0),
        taxable_purchases: parseFloat(r.taxable_purchases || 0),
        input_tax: parseFloat(r.input_tax || 0),
        net_tax: parseFloat(r.net_tax || 0),
      };
    }
  } catch (e) {
    error.value = e?.data?.message || t('Failed_Load_Tax_Summary');
    message.error(error.value);
  } finally {
    isLoading.value = false;
  }
}

onMounted(fetch);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
.line {
  display: flex;
  justify-content: space-between;
  margin-bottom: 8px;
}
.muted {
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
