<template>
  <div class="page">
    <PageHeader :title="$t('Profit_Loss_Title')" :breadcrumb="[$t('Accounting'), $t('Profit_Loss_Title')]" />

    <a-alert type="info" show-icon closable style="margin-bottom: 16px" :message="$t('Accounting_PL_Notice')">
      <template #description>
        {{ $t('Accounting_PL_Full_Hint') }}
        <router-link :to="{ name: 'profit-and-loss-report' }">{{ $t('Profit_Loss_Title') }}</router-link>
      </template>
    </a-alert>

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Income')" :value="number(summary.income)" :value-style="{ color: '#3f8600' }" /></a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Expense')" :value="number(summary.expense)" :value-style="{ color: '#cf1322' }" /></a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small">
          <a-statistic
            :title="$t('Net_Profit')" :value="number(summary.net_profit)"
            :value-style="{ color: summary.net_profit >= 0 ? '#3f8600' : '#cf1322' }"
          />
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]" align="bottom">
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('From') }}</div>
          <a-input v-model:value="filters.from" type="date" />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('To') }}</div>
          <a-input v-model:value="filters.to" type="date" />
        </a-col>
        <a-col :xs="12" :md="6">
          <div class="filter-label">{{ $t('Type') }}</div>
          <a-select v-model:value="filters.type" style="width: 100%">
            <a-select-option value="">{{ $t('All') }}</a-select-option>
            <a-select-option value="income">{{ $t('Income') }}</a-select-option>
            <a-select-option value="expense">{{ $t('Expense') }}</a-select-option>
          </a-select>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-button type="primary" @click="crud.reload()">
            <template #icon><ReloadOutlined /></template>
            {{ $t('Apply') }}
          </a-button>
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'type'">
          <span style="text-transform: capitalize">{{ record.type }}</span>
        </template>
        <template v-else-if="column.key === 'amount'">{{ number(record.amount) }}</template>
      </template>
    </DataTable>

    <a-card size="small" style="margin-top: 16px">
      <div class="totals">
        <div><span>{{ $t('Total_Income') }}</span><strong style="color: #3f8600">{{ number(summary.income) }}</strong></div>
        <div><span>{{ $t('Total_Expense') }}</span><strong style="color: #cf1322">{{ number(summary.expense) }}</strong></div>
        <div>
          <span>{{ $t('Net_Profit') }}</span>
          <strong :style="{ color: summary.net_profit >= 0 ? '#3f8600' : '#cf1322' }">{{ number(summary.net_profit) }}</strong>
        </div>
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Profit & loss (v2) — GET accounting/v2/reports/profit-loss (server-side +
 * from/to/type filters) → {data, totalRows, summary{income, expense,
 * net_profit}}. Summary rides in the same response; shown as cards + footer.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import DataTable from '../../../components/DataTable.vue';
import { useCrudTable } from '../../../composables/useCrudTable';
import { useFormat } from '../../../composables/useFormat';

const { t } = useI18n();
const { number } = useFormat();

const filters = ref({ from: '', to: '', type: '' });
const summary = ref({ income: 0, expense: 0, net_profit: 0 });

const crud = useCrudTable('accounting/v2/reports/profit-loss', {
  sortField: 'code',
  sortType: 'asc',
  params: () => ({
    from: filters.value.from || '',
    to: filters.value.to || '',
    type: filters.value.type || '',
  }),
  select: p => {
    if (p && p.summary) summary.value = p.summary;
    const rows = (p && p.data) || [];
    return { rows, total: (p && (p.totalRows ?? p.total ?? rows.length)) || 0 };
  },
});

const columns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Type'), key: 'type' },
  { title: t('Amount'), key: 'amount', align: 'right', sorter: true },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
.totals {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
}
.totals > div {
  display: flex;
  gap: 24px;
}
</style>
