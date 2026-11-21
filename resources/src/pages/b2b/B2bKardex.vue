<template>
  <div class="page b2b">
    <!-- The title is in the top bar (useBarTitle, 2026-11-21: "el título arriba para ganar espacio visual"). -->
    <h2 class="ptl">{{ tr('Kardex movements', 'Movimientos del Kardex') }}</h2>
    <DataTable :crud="crud" :columns="columns" table-key="b2b-kardex">
      <template #filters>
        <a-select v-model:value="productId" size="small" allow-clear show-search option-filter-prop="label" style="width: 260px"
          :placeholder="tr('Product', 'Producto')" :options="productOptions" @change="reload" />
        <PresetRangeFilter v-model:value="range" allow-all @change="reload" />
      </template>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'moved_at'">{{ date(record.moved_at) }}</template>
        <template v-else-if="column.key === 'direction'">
          <!-- Hordex's arrows: green in (↙), red out (↗). -->
          <a-tooltip :title="record.direction === 'salida' ? tr('Out', 'Salida') : tr('In', 'Entrada')">
            <span :class="record.direction === 'salida' ? 'out' : 'in'" class="arrow">{{ record.direction === 'salida' ? '↗' : '↙' }}</span>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'product'">{{ record.code }} - {{ record.product }}</template>
        <template v-else-if="['quantity', 'balance'].includes(column.key)"><span class="num">{{ number(record[column.key] || 0, 0) }}</span></template>
        <template v-else-if="column.key === 'price'"><span class="num">{{ record.price !== null ? money(record.price) : '' }}</span></template>
      </template>
    </DataTable>
    <p class="muted note">{{ tr('History brought from the earlier system. New movements are in Products -> stock and the guides.', 'Historial traído del sistema anterior. Los movimientos nuevos están en el stock de Productos y en las guías.') }}</p>
  </div>
</template>

<script setup>
/** Distribución B2B -> Kardex (2026-11-20): the stock card history of the earlier system (b2b_kardex, read only). */
import { ref, computed, onMounted } from 'vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import { useTr } from '../../lib/useTr';
import http from '../../lib/http';
import { useBarTitle } from '../../composables/useBarTitle';

const tr = useTr();
const { date, number, money } = useFormat();
const productId = ref();
const range = ref([]);
const products = ref([]);
const crud = useCrudTable('b2b/kardex', { rowsKey: 'rows', sortField: 'moved_at', limit: 50,
  params: () => ({ product_id: productId.value, from: range.value?.[0], to: range.value?.[1] }) });
const reload = () => crud.reload();
const productOptions = computed(() => sortByLabel(products.value.map(p => ({ value: p.id, label: `${p.code} · ${p.name}` }))));
const columns = computed(() => [
  { title: '', key: 'direction', dataIndex: 'direction', width: 36 },
  { title: tr('Date', 'Fecha'), key: 'moved_at', dataIndex: 'moved_at', sorter: true, width: 100 },
  { title: tr('Product', 'Producto'), key: 'product', dataIndex: 'product', sorter: true, ellipsis: true },
  { title: tr('Qty.', 'Cant.'), key: 'quantity', dataIndex: 'quantity', width: 90, align: 'right' },
  { title: tr('Price', 'Precio'), key: 'price', dataIndex: 'price', width: 100, align: 'right' },
  { title: tr('Balance', 'Saldo'), key: 'balance', dataIndex: 'balance', width: 90, align: 'right' },
  { title: tr('Reference', 'Referencia'), key: 'reference', dataIndex: 'reference', width: 180 },
]);
onMounted(async () => { products.value = (await http.get('b2b/options')).products || []; crud.fetchRows(); });

useBarTitle('Kardex');
</script>

<style scoped>
/* Compact rows (Hordex's way, user 2026-11-20): one line per row, long names cut with an ellipsis. */
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; word-break: normal !important; overflow-wrap: normal !important; }
.b2b :deep(.dt-managed td.ant-table-cell-ellipsis) { max-width: 260px; }
.num { font-variant-numeric: tabular-nums; }
.in { color: #15803d; }
.out { color: #dc2626; }
.arrow { font-weight: 700; font-size: 15px; }
.ptl { font-size: 22px; font-weight: 400; margin: 0 0 6px; }
.muted { opacity: 0.6; }
.note { font-size: 12px; margin-top: 6px; }
</style>
