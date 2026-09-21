<template>
  <div class="page">
    <PageHeader :title="$t('List_Deposit')" :breadcrumb="[$t('Deposits'), $t('List_Deposit')]">
      <template #actions>
        <a-button v-if="auth.can('deposit_add')" type="primary" @click="$router.push('/deposits/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Create_deposit') }}
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
          <div class="filter-label">{{ $t('Deposit_Category') }}</div>
          <a-select
            v-model:value="filters.deposit_category_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Category')" :options="categoryOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('Account') }}</div>
          <a-select
            v-model:value="filters.account_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Account')" :options="accountOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip v-if="auth.can('deposit_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/deposits/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('deposit_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.deposit_ref })">
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
 * GET deposits → {deposits, Deposits_category (capital D), accounts,
 * totalRows}; filter params deposit_ref (always-sent empty), account_id,
 * date, deposit_category_id. Fully migrated module — create/edit are Vue 3
 * pages, no legacy deep-links.
 */
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const { money, date } = useFormat();
const auth = useAuthStore();

const filters = ref({ date: null, deposit_category_id: undefined, account_id: undefined });

const crud = useCrudTable('deposits', {
  rowsKey: 'deposits',
  params: () => ({
    deposit_ref: '',
    account_id: filters.value.account_id || '',
    date: filters.value.date || '',
    deposit_category_id: filters.value.deposit_category_id || '',
  }),
});
crud.fetchRows();

const categoryOptions = computed(() =>
  (crud.payload.value?.Deposits_category || []).map(c => ({ value: c.id, label: c.title }))
);
const accountOptions = computed(() =>
  (crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name }))
);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'deposit_ref', key: 'deposit_ref', sorter: true },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, align: 'right', exportValue: r => money(r.amount) },
  { title: t('Categorie'), dataIndex: 'category_name', key: 'category_name' },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name' },
  { title: t('Details'), dataIndex: 'description', key: 'description' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
