<template>
  <div class="page b2b">
    <!-- Hordex's "Compras" = goods received into the warehouse (2026-11-21), laid out as its earlier screen: the list on
         the left; on the right the "Detalle de Compra" of the row clicked and, below, "Registrar Nueva Compra". -->
    <a-row :gutter="14">
      <a-col :span="24" class="col-half">
        <a-card size="small" class="panel">
          <div class="side-head">
            <h3>Listado de Compras</h3>
            <!-- Above the table, blue, always (user, 2026-11-23). -->
            <a-button type="primary" size="small" @click="newReceipt"><PlusOutlined /> Agregar Compra</a-button>
          </div>
          <DataTable :crud="crud" :columns="columns" :row-click="r => pick(r.id)" :selected-key="mode === 'view' ? sel?.id ?? null : null" compact-toolbar table-key="b2b-receipts">
            <template #bodyCell="{ column, record, index }">
              <template v-if="column.key === 'n'">{{ String((crud.page.value - 1) * crud.limit.value + index + 1).padStart(3, '0') }}</template>
              <template v-else-if="column.key === 'date'">{{ date(record.date) }}</template>
            </template>
          </DataTable>
        </a-card>
      </a-col>

      <!-- Phone: the right side opens as a modal (DetailPanel). -->
      <DetailPanel :open="phoneOpen" :span="24" class="col-half" @close="phoneOpen = false">
        <!-- One at a time, like Facturas (2026-11-23): the record selected (the latest when the page opens), or the new form. -->
        <a-card v-if="mode === 'view'" size="small" class="panel detail">
          <template v-if="sel">
            <div class="side-head">
              <h3 class="dt-title">Detalle de Compra {{ sel.provider }} <span class="doc">{{ sel.invoice ? 'Fact.' : sel.guide ? 'G.R.' : '' }} {{ sel.invoice || sel.guide }}</span></h3>
              <a-space :size="6">
                <a-popconfirm title="¿Eliminar esta compra? Si sumó stock, se descuenta." ok-text="Eliminar" cancel-text="No" @confirm="remove">
                  <a-button size="small" danger>Eliminar</a-button>
                </a-popconfirm>
              </a-space>
            </div>
            <div class="facts">
              <span><span class="lbl2">Fecha :</span> <b>{{ date(sel.date) }}</b></span>
              <span><span class="lbl2">Motivo :</span> <b>{{ sel.motive || '—' }}</b></span>
              <span v-if="sel.notes"><span class="lbl2">Obs. :</span> {{ sel.notes }}</span>
              <span v-if="sel.moves_stock" class="ok">Sumó stock</span>
            </div>
            <table class="grid">
              <thead><tr><th class="nr">#</th><th>Producto</th><th class="n">V.U.</th><th class="n">Cant.</th><th class="n">Subtotal</th></tr></thead>
              <tbody>
                <tr v-for="(it, i) in sel.items" :key="it.id">
                  <td class="nr">{{ String(i + 1).padStart(3, '0') }}</td>
                  <td>{{ it.name }}<br><span class="ref">Ref. {{ it.code }}</span></td>
                  <td class="n">{{ it.currency === 'USD' ? '$' : 'S/' }} {{ fmt(it.price) }}</td>
                  <td class="n">{{ fmt(it.quantity) }}</td>
                  <td class="n">{{ fmt((Number(it.price) || 0) * Number(it.quantity)) }}</td>
                </tr>
                <tr v-if="!sel.items.length"><td colspan="5" class="muted">Sin detalle</td></tr>
              </tbody>
              <tfoot>
                <tr><td colspan="4" class="n">Sub Total</td><td class="n">{{ fmt(subtotal) }}</td></tr>
                <tr><td colspan="4" class="n">IGV</td><td class="n">{{ fmt(subtotal * 0.18) }}</td></tr>
                <tr class="tot"><td colspan="4" class="n">Total</td><td class="n">{{ fmt(subtotal * 1.18) }}</td></tr>
              </tfoot>
            </table>
          </template>
          <div v-else-if="!isPhone" class="hint"><DoubleLeftOutlined /> Haga click en un registro para poder ver su información detallada</div>
        </a-card>

        <a-card v-else size="small" class="panel form">
          <div class="side-head"><h3>Registrar Nueva Compra</h3></div>
          <div class="f-row r1">
            <label>Fecha de ingreso<a-date-picker v-model:value="f.date" value-format="YYYY-MM-DD" size="small" :disabled-date="d => d && d.isAfter(today)" /></label>
            <label>Motivo<a-select v-model:value="f.motive" size="small" :options="motiveOptions" /></label>
            <label>Proveedor<a-select v-model:value="f.provider_id" size="small" show-search option-filter-prop="label" :options="providerOptions" @change="fillGuide" /></label>
            <label>Guía<a-input v-model:value="f.guide" size="small" placeholder="000-000000" /></label>
            <label>Factura<a-input v-model:value="f.invoice" size="small" placeholder="000-000000" :disabled="f.kind === 'compra' || !f.kind" /></label>
          </div>
          <div class="f-row r2">
            <label>Tipo<a-select v-model:value="f.kind" size="small" :options="kindOptions" @change="k => k === 'compra' && (f.invoice = '')" /></label>
            <label>Observaciones<a-input v-model:value="f.notes" size="small" /></label>
          </div>
          <table class="grid lines">
            <thead><tr><th class="nr">#</th><th>Producto</th><th>Moneda</th><th class="n">V.U.</th><th class="n">Cant.</th><th /></tr></thead>
            <tbody>
              <tr v-for="(l, i) in f.items" :key="i">
                <td class="nr">{{ i + 1 }}</td>
                <!-- Producto takes the room; Moneda is a one-click S/ / $ switch (2026-11-21: it wasted space). -->
                <td class="prod"><a-select v-model:value="l.product_id" size="small" show-search option-filter-prop="label" :options="productOptions" style="width: 100%" /></td>
                <td class="cur"><a class="cur-sw" :title="l.currency === 'USD' ? 'Dólares' : 'Soles'" @click="l.currency = l.currency === 'USD' ? 'PEN' : 'USD'">{{ l.currency === 'USD' ? '$' : 'S/' }}</a></td>
                <td class="n"><a-input-number v-model:value="l.price" size="small" :min="0" :step="0.01" placeholder="0.00" style="width: 84px" /></td>
                <td class="n"><a-input-number v-model:value="l.quantity" size="small" :min="0" style="width: 70px" /></td>
                <!-- Always there; the last line is emptied instead of removed. -->
                <td class="del"><a title="Quitar" @click="f.items.length > 1 ? f.items.splice(i, 1) : f.items.splice(0, 1, blankLine())"><DeleteOutlined /></a></td>
              </tr>
            </tbody>
          </table>
          <div class="f-actions">
            <a-button size="small" type="dashed" @click="f.items.push(blankLine())"><PlusOutlined /> Agregar producto</a-button>
            <a-button type="primary" size="small" :loading="saving" @click="save"><SaveOutlined /> Guardar</a-button>
          </div>
        </a-card>
      </DetailPanel>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Distribución B2B -> Compras (2026-11-21): goods received, as Hordex's earlier "Compras" (IngresoController): only the
 * kind "Compra" adds stock (kardex COMPRA LOCAL); "Adelanto" / "Servicio" take the invoice and need every price.
 * Eliminar takes the stock back out (B2bController::deleteReceipt). Same names, fields and order as there.
 */
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, SaveOutlined, DeleteOutlined, DoubleLeftOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import DetailPanel from '../../components/DetailPanel.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useIsPhone } from '../../lib/useIsPhone';
import { useBarTitle } from '../../composables/useBarTitle';
import http from '../../lib/http';

const { date } = useFormat();
const isPhone = useIsPhone();
const phoneOpen = ref(false);
const today = dayjs();
const fmt = (v, d = 2) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: d === 4 ? 2 : d, maximumFractionDigits: d });

const crud = useCrudTable('b2b/receipts', { rowsKey: 'rows', sortField: 'date', sortType: 'desc', limit: 33 });
const columns = [
  { title: '#', key: 'n', width: 38 },
  { title: 'Fecha', key: 'date', dataIndex: 'date', sorter: true, width: 84 },
  // Proveedor narrower and Observación small (mostly empty; full text on hover) -- 2026-11-21. Proveedor takes what is
  // left (~110 px at 1440).
  { title: 'Proveedor', key: 'provider', dataIndex: 'provider', sorter: true, ellipsis: { showTitle: true } },
  { title: 'Motivo', key: 'motive', dataIndex: 'motive', sorter: true, width: 116, ellipsis: { showTitle: true } },
  { title: 'Guía', key: 'guide', dataIndex: 'guide', width: 94, ellipsis: { showTitle: true } },
  { title: 'Factura', key: 'invoice', dataIndex: 'invoice', width: 104, ellipsis: { showTitle: true } },
  { title: 'Obs.', key: 'notes', dataIndex: 'notes', width: 70, ellipsis: { showTitle: true } },
];

const sel = ref(null);
const mode = ref('view');
async function pick(id, show = true) { sel.value = await http.get(`b2b/receipts/${id}`); mode.value = 'view'; if (show) phoneOpen.value = true; }
const subtotal = computed(() => (sel.value?.items || []).reduce((s, it) => s + (Number(it.price) || 0) * Number(it.quantity), 0));
async function remove() {
  try {
    await http.delete(`b2b/receipts/${sel.value.id}`);
    message.success('Compra eliminada');
    sel.value = null; phoneOpen.value = false;
    crud.fetchRows();
  } catch (e) { message.error(e?.data?.message || 'No se pudo eliminar'); }
}

// Options (they come with the list).
const motives = ref([]);
const providers = ref([]);
const products = ref([]);
const motiveOptions = computed(() => motives.value.map(m => ({ value: m, label: m })));
const providerOptions = computed(() => providers.value.map(p => ({ value: p.id, label: p.name })));
const productOptions = computed(() => products.value.map(p => ({ value: p.id, label: `${p.code} - ${p.name}` })));
const kindOptions = [{ value: 'compra', label: 'Compra' }, { value: 'adelanto', label: 'Adelanto' }, { value: 'servicio', label: 'Servicio' }];

const blankLine = () => ({ product_id: undefined, currency: 'PEN', price: null, quantity: 1 });
const blank = () => ({ date: dayjs().format('YYYY-MM-DD'), motive: 'COMPRA LOCAL', provider_id: undefined, guide: '', invoice: '', kind: undefined, notes: '', items: [blankLine()] });
const f = reactive(blank());
const saving = ref(false);
// The supplier's next guide, filled in (2026-11-21: "ayúdalo rellenando el nro de guía acorde al último"); editable.
const guideFilled = ref('');
async function fillGuide() {
  if (!f.provider_id) return;
  try {
    const r = await http.get('b2b/receipts/next-guide', { provider_id: f.provider_id });
    if (r.next && (!f.guide || f.guide === guideFilled.value)) { f.guide = r.next; guideFilled.value = r.next; }
  } catch (e) { /* only a help */ }
}
function newReceipt() { Object.assign(f, blank()); mode.value = 'new'; phoneOpen.value = true; }

// The earlier screen's checks, in its words.
async function save() {
  if (!f.provider_id) return message.error('Debe especificar el "Proveedor"');
  if (!f.kind) return message.error('Debe especificar el "Tipo"');
  if (!f.guide && !f.invoice) return message.error('Debe especificar la "Guía" o la "Factura"');
  for (const [i, l] of f.items.entries()) {
    if (!l.product_id) return message.error(`Debe especificar el "Nombre del Producto" de la linea ${i + 1}`);
    if (f.kind !== 'compra' && !(Number(l.price) > 0)) return message.error(`Debe especificar el "Precio de Compra" del producto de la linea ${i + 1}`);
    if (!(Number(l.quantity) > 0)) return message.error(`Debe especificar la "Cantidad" del producto de la linea ${i + 1}`);
  }
  saving.value = true;
  try {
    const r = await http.post('b2b/receipts', { ...f, invoice: f.kind === 'compra' ? null : f.invoice });
    message.success('Compra registrada');
    Object.assign(f, blank());
    await load();
    pick(r.id);
  } catch (e) { message.error(e?.data?.message || 'No se pudo registrar la compra'); } finally { saving.value = false; }
}

async function load() {
  await crud.fetchRows();
  const p = crud.payload.value || {};
  motives.value = p.motives || motives.value;
  providers.value = p.providers || providers.value;
  products.value = p.products || products.value;
}
useBarTitle('Compras');
onMounted(async () => {
  await load();
  // The latest one shown with its buttons (user, 2026-11-23); none yet = the new form.
  if (crud.rows.value?.length) await pick(crud.rows.value[0].id, false);
  else mode.value = 'new';
});
</script>

<style scoped>
@media (min-width: 1200px) { .col-half { flex: 0 0 50%; max-width: 50%; } }
.panel { border-radius: 2px; }
.panel + .panel { margin-top: 12px; }
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 20px; font-weight: 400; }
.dt-title { font-size: 17px !important; color: #ff3f00; font-weight: 600 !important; }
.dt-title .doc { color: inherit; opacity: 0.85; }
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; padding-left: 6px !important; padding-right: 6px !important; }
.b2b :deep(.col-half .dt-managed table) { table-layout: fixed !important; width: 100% !important; min-width: 0 !important; }
.hint { opacity: 0.65; padding: 18px 4px; }
.facts { display: flex; flex-wrap: wrap; gap: 4px 18px; font-size: 13px; margin-bottom: 6px; }
.lbl2 { opacity: 0.65; }
.ok { color: #15803d; font-weight: 600; }
.grid { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.grid th { text-align: left; font-weight: 600; padding: 4px 6px; background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.1); }
.grid td { padding: 3px 6px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); vertical-align: middle; }
.grid tbody tr:nth-child(even) td { background: rgba(128, 128, 128, 0.045); }
.grid .n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.grid .nr { width: 40px; }
.grid .del { width: 26px; text-align: center; }
.grid .del a { color: #cf1322; }
.grid td.prod { width: 64%; }
.grid td.cur { width: 34px; text-align: center; }
.cur-sw { display: inline-block; min-width: 26px; padding: 1px 4px; border: 1px solid rgba(128, 128, 128, 0.35); border-radius: 4px; font-weight: 600; }
.grid tfoot td { border: 0; padding: 2px 6px; }
.grid tfoot .tot td { font-weight: 700; font-size: 13.5px; }
.ref { font-size: 11px; opacity: 0.6; }
.f-row { display: grid; gap: 8px; margin-bottom: 8px; }
.f-row.r1 { grid-template-columns: 7.5rem 1.6fr 1.6fr 0.9fr 0.9fr; }
.f-row.r2 { grid-template-columns: 7.5rem 1fr; }
.f-row label { display: flex; flex-direction: column; font-size: 11px; opacity: 0.9; gap: 2px; min-width: 0; }
.f-row label :deep(.ant-picker), .f-row label :deep(.ant-select) { width: 100%; }
.f-actions { display: flex; justify-content: space-between; margin-top: 8px; }
.muted { opacity: 0.6; }
@media (max-width: 767px) { .f-row.r1, .f-row.r2 { grid-template-columns: 1fr 1fr; } }
</style>
