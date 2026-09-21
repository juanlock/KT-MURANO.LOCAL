<template>
  <div class="page">
    <PageHeader :title="$t('Trial_Balance_Title')" :breadcrumb="[$t('Accounting'), $t('Trial_Balance_Title')]" />

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
            <a-select-option value="asset">{{ $t('Asset') }}</a-select-option>
            <a-select-option value="liability">{{ $t('Liability') }}</a-select-option>
            <a-select-option value="equity">{{ $t('Equity') }}</a-select-option>
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
        <template v-else-if="column.key === 'debit'">{{ number(record.debit) }}</template>
        <template v-else-if="column.key === 'credit'">{{ number(record.credit) }}</template>
      </template>
    </DataTable>

    <a-card size="small" style="margin-top: 16px">
      <div style="display: flex; justify-content: flex-end; gap: 32px">
        <span>{{ $t('Total') }} {{ $t('Debit') }}: <strong>{{ number(pageTotalDebit) }}</strong></span>
        <span>{{ $t('Total') }} {{ $t('Credit') }}: <strong>{{ number(pageTotalCredit) }}</strong></span>
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Trial balance (v2) — GET accounting/v2/reports/trial-balance (server-side
 * page/sort/search + from/to/type filters) → {data, totalRows}. Page totals
 * (current page only, like legacy's footer row) shown under the table.
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

const crud = useCrudTable('accounting/v2/reports/trial-balance', {
  sortField: 'code',
  sortType: 'asc',
  params: () => ({
    from: filters.value.from || '',
    to: filters.value.to || '',
    type: filters.value.type || '',
  }),
  select: p => {
    const rows = (p && p.data) || [];
    return { rows, total: (p && (p.totalRows ?? p.total ?? rows.length)) || 0 };
  },
});

const columns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Type'), key: 'type' },
  { title: t('Debit'), key: 'debit', align: 'right', sorter: true },
  { title: t('Credit'), key: 'credit', align: 'right', sorter: true },
]);

const pageTotalDebit = computed(() => crud.rows.value.reduce((a, b) => a + Number(b.debit || 0), 0));
const pageTotalCredit = computed(() => crud.rows.value.reduce((a, b) => a + Number(b.credit || 0), 0));

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}
</style>
