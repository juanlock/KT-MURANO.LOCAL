<template>
  <div class="page b2b">
    <PageHeader title="Facturas" />
    <!-- Hordex's "Facturas" (user, 2026-11-20: "igual que Guías"): "Listado de Facturas" on the left, "Registrar Nueva Factura"
         on the right; a click on an invoice shows it there. -->
    <a-row :gutter="14">
      <a-col :xs="24" :xl="14">
        <a-card size="small" class="side panel">
          <div class="side-head"><h3>Listado de Facturas</h3></div>
          <DataTable :crud="crud" :columns="columns" :row-click="r => openInvoice(r.id)" compact-toolbar table-key="b2b-invoices">
            <template #filters>
              <a-select v-model:value="filters.status" size="small" style="width: 140px" :options="statusOptions" @change="reload" />
              <a-select v-model:value="filters.client_id" size="small" allow-clear show-search option-filter-prop="label" style="width: 220px"
                placeholder="Cliente" :options="clientOptions" @change="reload" />
            </template>
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'Ref'"><span class="mono">{{ record.Ref }}</span></template>
              <template v-else-if="column.key === 'date'">{{ date(record.date) }}</template>
              <template v-else-if="column.key === 'GrandTotal'"><span class="num">{{ money(record.GrandTotal) }}</span></template>
              <template v-else-if="column.key === 'estado'">
                <CloseCircleOutlined v-if="record.statut === 'canceled'" class="ico red" title="Anulada" />
                <CheckOutlined v-else-if="record.payment_statut === 'paid'" class="ico green" title="Cancelada" />
                <ClockCircleOutlined v-else class="ico" :class="record.overdue ? 'red' : 'blue'" :title="record.overdue ? 'Vencida' : 'Pendiente'" />
              </template>
            </template>
          </DataTable>
        </a-card>
      </a-col>

      <a-col :xs="24" :xl="10">
        <a-card size="small" class="side panel">
          <!-- New invoice -->
          <template v-if="mode === 'new'">
            <div class="side-head">
              <h3>Registrar Nueva Factura</h3>
              <a-button type="primary" size="small" @click="addLine"><PlusOutlined /> Agregar producto</a-button>
            </div>
            <a-form layout="vertical" class="tight">
              <a-row :gutter="8">
                <a-col :span="8">
                  <a-form-item label="Nro. Factura">
                    <a-input-group compact>
                      <a-input v-model:value="form.series" style="width: 40%" />
                      <a-input-number v-model:value="form.number" :min="1" style="width: 60%" />
                    </a-input-group>
                  </a-form-item>
                </a-col>
                <a-col :span="6"><a-form-item label="Fecha Emisión"><a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
                <a-col :span="10"><a-form-item label="Forma de Pago"><a-select v-model:value="form.payment_term_id" allow-clear :options="termOptions" /></a-form-item></a-col>
              </a-row>
              <a-row :gutter="8">
                <a-col :span="16"><a-form-item label="Cliente"><a-select v-model:value="form.client_id" show-search option-filter-prop="label" :options="clientOptions" /></a-form-item></a-col>
                <a-col :span="8"><a-form-item label="Orden de compra"><a-input v-model:value="form.purchase_order" /></a-form-item></a-col>
              </a-row>
              <table class="lines">
                <thead><tr><th>#</th><th class="pc">Producto</th><th class="n">V.U.</th><th class="n">Cant.</th><th>Unid.</th><th class="n">Subtotal</th><th /></tr></thead>
                <tbody>
                  <tr v-for="(it, i) in form.items" :key="i">
                    <td class="muted">{{ i + 1 }}</td>
                    <td><a-select v-model:value="it.product_id" size="small" show-search option-filter-prop="label" :options="productOptions" style="width: 100%" @change="onProduct(it)" /></td>
                    <td class="n"><a-input-number v-model:value="it.price" size="small" :min="0" :precision="4" style="width: 90px" /></td>
                    <td class="n"><a-input-number v-model:value="it.quantity" size="small" :min="0" style="width: 75px" /></td>
                    <td><a-select v-model:value="it.unit_id" size="small" style="width: 72px" :options="unitOptions" /></td>
                    <td class="n">{{ money((it.price || 0) * (it.quantity || 0)) }}</td>
                    <td><a-button type="text" size="small" danger @click="form.items.splice(i, 1)"><DeleteOutlined /></a-button></td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr><td colspan="5" class="n">Sub Total</td><td class="n">{{ money(net) }}</td><td /></tr>
                  <tr><td colspan="5" class="n">I.G.V. {{ igvRate }}%</td><td class="n">{{ money(igv) }}</td><td /></tr>
                  <tr class="tot"><td colspan="5" class="n">Total</td><td class="n">{{ money(net + igv) }}</td><td /></tr>
                </tfoot>
              </table>
              <a-button type="primary" block class="save" :loading="saving" @click="saveInvoice">Guardar Factura</a-button>
            </a-form>
          </template>

          <!-- One invoice -->
          <template v-else-if="inv">
            <div class="side-head">
              <h3 class="mono">{{ inv.Ref }}</h3>
              <a-button size="small" @click="newInvoice"><PlusOutlined /> Nueva factura</a-button>
            </div>
            <dl class="facts">
              <dt>Estado</dt><dd>{{ estado(inv) }}</dd>
              <dt>Cliente</dt><dd>{{ inv.client_name }}</dd>
              <dt>Emisión / vence</dt><dd>{{ date(inv.date) }} · {{ inv.due_date ? date(inv.due_date) : '—' }}</dd>
              <dt>Forma de pago</dt><dd>{{ inv.term || '—' }}</dd>
              <dt>Orden de compra</dt><dd>{{ inv.purchase_order || '—' }}</dd>
              <dt>Guías</dt><dd class="mono">{{ inv.guides?.length ? inv.guides.map(g => gref(g.series, g.number)).join(', ') : '—' }}</dd>
            </dl>
            <table class="lines">
              <thead><tr><th>#</th><th>Producto</th><th class="n">V.U.</th><th class="n">Cant.</th><th>Unid.</th><th class="n">Subtotal</th></tr></thead>
              <tbody>
                <tr v-for="(l, i) in inv.lines" :key="i"><td class="muted">{{ i + 1 }}</td><td>{{ l.name }}</td><td class="n">{{ money(l.price) }}</td>
                  <td class="n">{{ number(l.quantity, 0) }}</td><td>{{ l.unit }}</td><td class="n">{{ money(l.total) }}</td></tr>
              </tbody>
              <tfoot>
                <tr><td colspan="5" class="n">Sub Total</td><td class="n">{{ money(inv.GrandTotal - inv.TaxNet) }}</td></tr>
                <tr><td colspan="5" class="n">I.G.V.</td><td class="n">{{ money(inv.TaxNet) }}</td></tr>
                <tr class="tot"><td colspan="5" class="n">Total</td><td class="n">{{ money(inv.GrandTotal) }}</td></tr>
                <tr><td colspan="5" class="n">Abonado</td><td class="n">{{ money(inv.paid_amount) }}</td></tr>
                <tr class="tot"><td colspan="5" class="n">Saldo</td><td class="n">{{ money(inv.GrandTotal - inv.paid_amount) }}</td></tr>
              </tfoot>
            </table>
            <template v-if="inv.payments?.length">
              <h4 class="sub">Pagos</h4>
              <table class="lines">
                <thead><tr><th>Fecha</th><th>Concepto</th><th>Documento</th><th class="n">Monto</th></tr></thead>
                <tbody><tr v-for="p in inv.payments" :key="p.id"><td>{{ date(p.date) }}</td><td>{{ p.concept || p.method }}</td><td>{{ p.document }}</td><td class="n">{{ money(p.amount) }}</td></tr></tbody>
              </table>
            </template>
          </template>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/** Distribución B2B -> Facturas (2026-11-20): Hordex's invoices -- list, the invoice clicked, a new invoice typed directly. */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, DeleteOutlined, CheckOutlined, ClockCircleOutlined, CloseCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const { date, number, money } = useFormat();
const gref = (s, n) => `${s}-${String(n || 0).padStart(6, '0')}`;
const opts = ref({});
const filters = reactive({ status: 'all', client_id: undefined });
const range = ref([]);
const crud = useCrudTable('b2b/invoices', {
  rowsKey: 'invoices', sortField: 'date', limit: 50,
  params: () => ({ ...filters, status: filters.status === 'all' ? undefined : filters.status, from: range.value?.[0], to: range.value?.[1] }),
});
const reload = () => crud.reload();
const statusOptions = [{ value: 'all', label: 'Todas' }, { value: 'pending', label: 'Pendientes' }, { value: 'overdue', label: 'Vencidas' }, { value: 'paid', label: 'Canceladas' }];
const columns = [
  { title: 'Factura', key: 'Ref', dataIndex: 'Ref', sorter: true, width: 115 },
  { title: 'Emisión', key: 'date', dataIndex: 'date', sorter: true, width: 95 },
  { title: 'Razón Social', key: 'client_name', dataIndex: 'client_name', sorter: true, ellipsis: true },
  { title: 'Total', key: 'GrandTotal', dataIndex: 'GrandTotal', sorter: true, width: 115, align: 'right' },
  { title: 'Estado', key: 'estado', width: 64, align: 'center' },
];
const estado = r => (r.statut === 'canceled' ? 'ANULADA' : r.payment_statut === 'paid' ? 'CANCELADA' : 'PENDIENTE');

const clientOptions = computed(() => sortByLabel((opts.value.clients || []).map(c => ({ value: c.id, label: c.name }))));
const productOptions = computed(() => sortByLabel((opts.value.products || []).map(p => ({ value: p.id, label: `${p.code} - ${p.name}` }))));
const termOptions = computed(() => (opts.value.terms || []).map(t => ({ value: t.id, label: t.name })));
const unitOptions = computed(() => (opts.value.units || []).map(u => ({ value: u.id, label: u.ShortName || u.name })));
const igvRate = computed(() => Number(opts.value.settings?.igv || 18));

const mode = ref('new');
const saving = ref(false);
const blankLine = () => ({ product_id: undefined, price: 0, quantity: 1, unit_id: undefined });
const blank = () => ({ series: opts.value.settings?.invoice_series || 'E001', number: opts.value.next_invoice, date: dayjs().format('YYYY-MM-DD'),
  payment_term_id: undefined, client_id: undefined, purchase_order: '', items: [blankLine()] });
const form = reactive({ items: [] });
const net = computed(() => form.items.reduce((s, i) => s + (i.price || 0) * (i.quantity || 0), 0));
const igv = computed(() => Math.round(net.value * igvRate.value) / 100);
function newInvoice() { Object.assign(form, blank()); mode.value = 'new'; }
function addLine() { if (mode.value !== 'new') newInvoice(); else form.items.push(blankLine()); }
function onProduct(it) {
  const p = (opts.value.products || []).find(x => x.id === it.product_id);
  if (p) { it.price = Number(p.price) || 0; it.unit_id = p.unit_sale_id || it.unit_id; }
}
async function saveInvoice() {
  const items = form.items.filter(i => i.product_id && i.quantity > 0);
  if (!form.client_id || !items.length) { message.warning('Elija el cliente y al menos un producto'); return; }
  saving.value = true;
  try {
    const r = await http.post('b2b/invoices', { ...form, items });
    message.success(`Factura ${r.ref} registrada`);
    opts.value = await http.get('b2b/options');
    await crud.fetchRows();
    openInvoice(r.sale_id);
  } catch (e) {
    message.error(e?.data?.message || 'No se pudo guardar la factura');
  } finally { saving.value = false; }
}

const inv = ref(null);
async function openInvoice(id) { inv.value = await http.get(`b2b/invoices/${id}`); mode.value = 'view'; }

onMounted(async () => { opts.value = await http.get('b2b/options'); newInvoice(); crud.fetchRows(); });
</script>

<style scoped>
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; }
.side { position: sticky; top: 12px; }
.panel { min-height: calc(100vh - 170px); border-radius: 2px; }
.panel :deep(.datatable-toolbar) { padding-left: 0; padding-right: 0; }
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 22px; font-weight: 400; }
.tight :deep(.ant-form-item) { margin-bottom: 8px; }
.tight :deep(.ant-form-item-label) { padding-bottom: 2px; }
.tight :deep(.ant-form-item-label > label) { font-size: 12px; opacity: 0.75; }
.lines { width: 100%; border-collapse: collapse; font-size: 12.5px; margin: 6px 0; }
.lines th { background: #2b2f36; color: #fff; font-weight: 600; padding: 4px 6px; text-align: left; }
.lines td { padding: 3px 6px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); }
.lines tbody tr:nth-child(even) td { background: rgba(128, 128, 128, 0.05); }
.lines .n { text-align: right; font-variant-numeric: tabular-nums; }
.lines tfoot td { border: 0; padding: 2px 6px; }
.lines tfoot .tot td { font-weight: 700; }
.save { margin-top: 10px; }
.sub { margin: 10px 0 2px; font-size: 15px; font-weight: 500; }
.mono { font-family: ui-monospace, Consolas, monospace; }
.num { font-variant-numeric: tabular-nums; }
.muted { opacity: 0.6; }
.facts { display: grid; grid-template-columns: 130px 1fr; gap: 3px 10px; margin: 0 0 8px; font-size: 13px; }
.facts dt { opacity: 0.6; }
.facts dd { margin: 0; }
.ico { font-size: 14px; }
.pc { width: 46%; }
.green { color: #16a34a; }
.blue { color: #2563eb; }
.red { color: #dc2626; }
</style>
