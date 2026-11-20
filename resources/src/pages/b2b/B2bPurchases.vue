<template>
  <div class="page b2b">
    <PageHeader title="Compras" />
    <!-- Hordex's "Compras" (supplier invoices), corrected as the user asked (2026-11-20): the list on the left, the invoice
         clicked on the right, "Nueva" opens the registration in a window. -->
    <a-row :gutter="14">
      <a-col :xs="24" :xl="14">
        <a-card size="small" class="panel">
          <div class="side-head">
            <h3>Listado de Facturas de Proveedores</h3>
            <a-button type="primary" size="small" @click="openNew"><PlusOutlined /> Nueva</a-button>
          </div>
          <DataTable :crud="crud" :columns="columns" :row-click="r => pick(r.id)" compact-toolbar table-key="b2b-purchases">
            <template #bodyCell="{ column, record, index }">
              <template v-if="column.key === 'n'">{{ String((crud.page.value - 1) * crud.limit.value + index + 1).padStart(3, '0') }}</template>
              <template v-else-if="column.key === 'date'">{{ d(record.date) }}</template>
              <template v-else-if="column.key === 'GrandTotal'"><span class="num">{{ record.currency }} {{ fmt(record.GrandTotal) }}</span></template>
            </template>
          </DataTable>
        </a-card>
      </a-col>
      <a-col :xs="24" :xl="10">
        <a-card size="small" class="panel">
          <a-empty v-if="!sel" description="Haga click en una factura de la lista" />
          <template v-else>
            <div class="side-head"><h3>Factura {{ sel.Ref }}</h3><span class="st">{{ sel.estado }}</span></div>
            <dl class="facts">
              <dt>Proveedor</dt><dd>{{ sel.provider }} <span v-if="sel.tax_number" class="muted">· RUC {{ sel.tax_number }}</span></dd>
              <dt>F. Emisión</dt><dd>{{ d(sel.date) }}</dd>
              <dt>Moneda</dt><dd>{{ sel.currency === '$' ? 'Dólares' : 'Soles' }}</dd>
              <dt v-if="sel.notes">Notas</dt><dd v-if="sel.notes">{{ sel.notes }}</dd>
            </dl>
            <table class="lines">
              <thead><tr><th>#</th><th>Descripción</th><th class="n">Cant</th><th class="n">P.Unit.</th><th class="n">Subtotal</th></tr></thead>
              <tbody>
                <tr v-for="(l, i) in sel.lines" :key="i"><td>{{ i + 1 }}</td><td>{{ l.code ? l.code + ' - ' : '' }}{{ l.name }}</td>
                  <td class="n">{{ fmt(l.quantity) }}</td><td class="n">{{ fmt(l.cost) }}</td><td class="n">{{ fmt(l.total) }}</td></tr>
              </tbody>
              <tfoot>
                <tr><td colspan="4" class="n">Subtotal</td><td class="n">{{ fmt(sel.GrandTotal - sel.TaxNet) }}</td></tr>
                <tr><td colspan="4" class="n">IGV</td><td class="n">{{ fmt(sel.TaxNet) }}</td></tr>
                <tr class="tot"><td colspan="4" class="n">Total {{ sel.currency }}</td><td class="n">{{ fmt(sel.GrandTotal) }}</td></tr>
              </tfoot>
            </table>
          </template>
        </a-card>
      </a-col>
    </a-row>

    <a-modal v-model:open="modal" title="Registrar Nueva Factura de Proveedor" width="760px" ok-text="Guardar" cancel-text="Cancelar" :confirm-loading="saving" @ok="save">
      <a-form layout="vertical" class="tight">
        <a-row :gutter="10">
          <a-col :span="12"><a-form-item label="Proveedor"><a-select v-model:value="f.provider_id" show-search option-filter-prop="label" :options="providerOptions" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item label="Tipo"><a-select v-model:value="f.tipo" allow-clear :options="[{ value: 'Bienes', label: 'Bienes' }, { value: 'Servicios', label: 'Servicios' }]" /></a-form-item></a-col>
          <a-col :span="6"><a-form-item label="Moneda"><a-select v-model:value="f.moneda" :options="[{ value: 'PEN', label: 'Soles' }, { value: 'USD', label: 'Dólares' }]" /></a-form-item></a-col>
        </a-row>
        <a-row :gutter="10">
          <a-col :span="6"><a-form-item label="Factura"><a-input v-model:value="f.factura" placeholder="000 - 0000000" /></a-form-item></a-col>
          <a-col v-if="f.moneda === 'USD'" :span="4"><a-form-item label="T.C."><a-input-number v-model:value="f.tc" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="5"><a-form-item label="F. Emisión"><a-date-picker v-model:value="f.f_emision" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="5"><a-form-item label="F. Venc."><a-date-picker v-model:value="f.f_venc" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="4"><a-form-item label="F. Pago"><a-date-picker v-model:value="f.f_pago" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <table class="lines">
          <thead><tr><th>#</th><th>Descripción</th><th class="n">Cant</th><th class="n">P.Unit.</th><th class="n">Subtotal</th><th /></tr></thead>
          <tbody>
            <tr v-for="(it, i) in f.items" :key="i">
              <td>{{ i + 1 }}</td>
              <td><a-select v-model:value="it.product_id" size="small" show-search option-filter-prop="label" :options="productOptions" style="width: 100%" /></td>
              <td class="n"><a-input-number v-model:value="it.quantity" size="small" :min="0" style="width: 80px" /></td>
              <td class="n"><a-input-number v-model:value="it.price" size="small" :min="0" :precision="2" style="width: 90px" /></td>
              <td class="n">{{ fmt((it.quantity || 0) * (it.price || 0)) }}</td>
              <td><a-button type="text" size="small" danger @click="f.items.splice(i, 1)"><DeleteOutlined /></a-button></td>
            </tr>
          </tbody>
          <tfoot>
            <tr><td colspan="4" class="n">Subtotal</td><td class="n">{{ fmt(net) }}</td><td /></tr>
            <tr><td colspan="4" class="n">IGV</td><td class="n">{{ fmt(net * 0.18) }}</td><td /></tr>
            <tr class="tot"><td colspan="4" class="n">Total</td><td class="n">{{ fmt(net * 1.18) }}</td><td /></tr>
          </tfoot>
        </table>
        <a-button size="small" type="dashed" @click="f.items.push({ product_id: undefined, quantity: 1, price: 0 })"><PlusOutlined /> Agregar Item(s)</a-button>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** Distribución B2B -> Compras (2026-11-20): Hordex's supplier invoices -- list, detail on click, new one in a window. */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const fmt = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const d = v => (v ? dayjs(String(v).slice(0, 10)).format('DD/MM/YYYY') : '');
const crud = useCrudTable('b2b/purchases', { rowsKey: 'purchases', sortField: 'date', limit: 33 });
const columns = [
  { title: '#', key: 'n', width: 52 },
  { title: 'Fecha', key: 'date', dataIndex: 'date', sorter: true, width: 100 },
  { title: 'Proveedor', key: 'provider', dataIndex: 'provider', sorter: true, ellipsis: true },
  { title: 'Factura', key: 'Ref', dataIndex: 'Ref', sorter: true, width: 140 },
  { title: 'Total', key: 'GrandTotal', dataIndex: 'GrandTotal', sorter: true, width: 120, align: 'right' },
  { title: 'Estado', key: 'estado', dataIndex: 'estado', width: 100 },
];
const opts = ref({});
const providerOptions = computed(() => sortByLabel((opts.value.providers || []).map(p => ({ value: p.id, label: p.name }))));
const productOptions = computed(() => sortByLabel((opts.value.products || []).map(p => ({ value: p.id, label: `${p.code} - ${p.name}` }))));
const sel = ref(null);
async function pick(id) { sel.value = await http.get(`b2b/purchases/${id}`); }

const modal = ref(false);
const saving = ref(false);
const blank = () => ({ provider_id: undefined, tipo: undefined, moneda: 'PEN', tc: undefined, factura: '', f_emision: dayjs().format('YYYY-MM-DD'), f_venc: undefined, f_pago: undefined,
  items: [{ product_id: undefined, quantity: 1, price: 0 }] });
const f = reactive(blank());
const net = computed(() => f.items.reduce((s, i) => s + (i.quantity || 0) * (i.price || 0), 0));
function openNew() { Object.assign(f, blank()); modal.value = true; }
async function save() {
  saving.value = true;
  try {
    const r = await http.post('b2b/purchases', { ...f, items: f.items.filter(i => i.quantity > 0) });
    message.success('Factura de proveedor registrada');
    modal.value = false;
    await crud.fetchRows();
    pick(r.id);
  } catch (e) { message.error(e?.data?.message || 'No se pudo guardar'); } finally { saving.value = false; }
}
onMounted(async () => { opts.value = await http.get('b2b/options'); crud.fetchRows(); });
</script>

<style scoped>
.panel { min-height: calc(100vh - 170px); border-radius: 2px; }
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 22px; font-weight: 400; }
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; }
.num { font-variant-numeric: tabular-nums; }
.muted { opacity: 0.6; }
.st { font-weight: 600; }
.facts { display: grid; grid-template-columns: 110px 1fr; gap: 3px 10px; margin: 0 0 8px; font-size: 13px; }
.facts dt { opacity: 0.6; }
.facts dd { margin: 0; }
.tight :deep(.ant-form-item) { margin-bottom: 8px; }
.lines { width: 100%; border-collapse: collapse; font-size: 12.5px; margin: 6px 0; }
.lines th { background: #2b2f36; color: #fff; font-weight: 600; padding: 4px 6px; text-align: left; }
.lines td { padding: 3px 6px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); }
.lines tbody tr:nth-child(even) td { background: rgba(128, 128, 128, 0.05); }
.lines .n { text-align: right; font-variant-numeric: tabular-nums; }
.lines tfoot td { border: 0; }
.lines tfoot .tot td { font-weight: 700; }
</style>
