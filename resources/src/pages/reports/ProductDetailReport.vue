<template>
  <div class="page">
    <PageHeader :title="$t('product_report')" :breadcrumb="[$t('Reports'), $t('product_report')]">
      <template #actions>
        <!-- Multi-Currency: view amounts converted (display-only) -->
        <ViewCurrencySelect />
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <DateRangePicker v-model:value="range" value-format="YYYY-MM-DD" @change="reload" />
        <a-input v-model:value="filters.Ref" :placeholder="$t('Reference')" allow-clear style="width: 160px" />
        <a-select
          v-model:value="filters.client_id" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Choose_Customer')" style="width: 200px"
          :options="customers.map(c => ({ value: c.id, label: c.name }))"
        />
        <a-select
          v-model:value="filters.warehouse_id" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Choose_Warehouse')" style="width: 200px"
          :options="warehouses.map(w => ({ value: w.id, label: w.name }))"
        />
        <a-select
          v-model:value="filters.user_id" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Choose_Seller')" style="width: 200px"
          :options="users.map(u => ({ value: u.id, label: u.username }))"
        />
        <a-button type="primary" @click="reload">{{ $t('Filter') }}</a-button>
        <a-button danger @click="resetFilters">{{ $t('Reset') }}</a-button>
      </a-space>
    </a-card>

    <a-card
      v-if="product.type === 'is_variant'" size="small"
      :title="$t('Variant')" style="margin-bottom: 16px"
    >
      <a-table
        :columns="variantColumns" :data-source="product.products_variants_data || []"
        :pagination="false" size="small" :row-key="(r, i) => r.code || i"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'price'">{{ money(record.price) }}</template>
        </template>
      </a-table>
    </a-card>

    <a-tabs>
      <a-tab-pane key="sales" :tab="$t('Sales')">
        <DataTable :crud="crud" :columns="columns" :row-key="(r, i) => `${r.sale_id}-${i}`">
          <template #toolbar>
            <a-button size="small" @click="print">
              <template #icon><PrinterOutlined /></template>
              {{ $t('print') }}
            </a-button>
            <a-button size="small" @click="pdf">
              <template #icon><FilePdfOutlined /></template>
              {{ $t('PDF') }}
            </a-button>
            <a-button size="small" @click="excel">
              <template #icon><FileExcelOutlined /></template>
              {{ $t('EXCEL') }}
            </a-button>
          </template>

          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'Ref'">
              <router-link :to="`/sales/${record.sale_id}`">{{ record.Ref }}</router-link>
            </template>
            <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
          </template>

          <template #summary>
            <SummaryRow>
              <SummaryCell v-for="(col, i) in columns" :key="col.key" :index="i">
                <strong v-if="col.key === 'total'">{{ money(pageTotal) }}</strong>
              </SummaryCell>
            </SummaryRow>
          </template>
        </DataTable>
      </a-tab-pane>
    </a-tabs>
  </div>
</template>

<script setup>
/**
 * Product drill-down report — legacy detail_product_report.vue.
 * GET get_product_detail/{id} for the variant table; GET
 * report/sale_products_details returns the rows AND the filter option lists
 * (customers/warehouses/users) in one payload, so the selects populate from
 * the first fetch.
 *
 * Filter params are legacy's verbatim: Ref, client_id, warehouse_id, user_id,
 * from, to, plus the usual page/limit/search/id. The default range is
 * 2000-01-01 → today, which is what legacy's misleadingly-named `today_mode`
 * bootstrap actually produced (all time, not today).
 *
 * Legacy's Reset cleared a property that did not exist (`search` rather than
 * `search_sales`), so the table's search term survived a reset; here Reset
 * clears the search too. Legacy's hardcoded "Choose Vendeur" is now the
 * 'Choose_Seller' i18n key.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { PrinterOutlined, FilePdfOutlined, FileExcelOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { Table } from 'ant-design-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf, printRows } from '../../lib/exporters';
import http from '../../lib/http';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

// Imported rather than relying on the global registration for these two
// nested sub-components.
const SummaryRow = Table.Summary.Row;
const SummaryCell = Table.Summary.Cell;

const { t } = useI18n();
const { money } = useFormat();
const route = useRoute();
const id = route.params.id;

const product = ref({});
const customers = ref([]);
const warehouses = ref([]);
const users = ref([]);

// Legacy's all-time default window.
const range = ref(['2000-01-01', new Date().toISOString().slice(0, 10)]);
const filters = reactive({ Ref: '', client_id: undefined, warehouse_id: undefined, user_id: undefined });

const crud = useCrudTable('report/sale_products_details', {
  rowsKey: 'sales',
  params: () => ({
    id,
    Ref: filters.Ref || '',
    client_id: filters.client_id || '',
    warehouse_id: filters.warehouse_id || '',
    user_id: filters.user_id || '',
    from: range.value?.[0] || '',
    to: range.value?.[1] || '',
  }),
});

// The option lists ride along with the rows.
const stashOptions = () => {
  const p = crud.payload.value || {};
  if (Array.isArray(p.customers)) customers.value = p.customers;
  if (Array.isArray(p.warehouses)) warehouses.value = p.warehouses;
  if (Array.isArray(p.users)) users.value = p.users;
};

async function reload() {
  crud.page.value = 1;
  await crud.fetchRows();
  stashOptions();
}

function resetFilters() {
  filters.Ref = '';
  filters.client_id = undefined;
  filters.warehouse_id = undefined;
  filters.user_id = undefined;
  crud.search.value = '';
  reload();
}

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Created_by'), dataIndex: 'created_by', key: 'created_by' },
  { title: t('product_name'), dataIndex: 'product_name', key: 'product_name' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Quantity'), dataIndex: 'quantity', key: 'quantity', align: 'right' },
  { title: t('Total'), dataIndex: 'total', key: 'total', align: 'right', exportValue: r => money(r.total) },
]);

const variantColumns = computed(() => [
  { title: t('Variant_code'), dataIndex: 'code', key: 'code' },
  { title: t('Variant_Name'), dataIndex: 'name', key: 'name' },
  { title: t('Variant_price'), dataIndex: 'price', key: 'price', align: 'right' },
]);

const pageTotal = computed(() =>
  crud.rows.value.reduce((sum, r) => sum + (Number(r.total) || 0), 0));

const title = computed(() => `${t('Reports')} / ${t('product_report')}`);

function print() { printRows(title.value, columns.value, crud.rows.value); }
function pdf() { exportPdf(title.value, columns.value, crud.rows.value); }
function excel() { exportExcel('product_report', columns.value, crud.rows.value); }

onMounted(async () => {
  await reload();
  try {
    product.value = await http.get(`get_product_detail/${id}`) || {};
  } catch (e) { /* variant card just stays hidden */ }
});
</script>
