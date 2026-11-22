<template>
  <div class="page b2b">
    <!-- The title is in the top bar (useBarTitle, 2026-11-21: "el título arriba para ganar espacio visual"). -->
    <!-- Hordex's "Facturas" (user, 2026-11-20: "igual que Guías"): "Listado de Facturas" on the left, "Registrar Nueva Factura"
         on the right; a click on an invoice shows it there. -->
    <a-row :gutter="14">
      <a-col :xs="24" :xl="11">
        <a-card size="small" class="side panel">
          <div class="side-head"><h3>Listado de Facturas</h3>
            <a-button v-if="isPhone" type="primary" size="small" @click="newInvoice(); phoneOpen = true"><PlusOutlined /> Nueva</a-button></div>
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

      <!-- Phone: this side opens as a modal (DetailPanel, 2026-11-21). -->
      <DetailPanel :open="phoneOpen" :xs="24" :xl="13" @close="phoneOpen = false">
        <a-card size="small" class="side panel">
          <!-- New invoice -->
          <template v-if="mode === 'new' || mode === 'edit'">
            <div class="side-head">
              <h3>{{ mode === 'edit' ? `Editar Factura ${editRef}` : 'Registrar Nueva Factura' }}</h3>
              <a-space :size="6">
                <a-popconfirm v-if="form.items.length" title="¿Limpiar la factura? Se quitan todos los productos y guías (no se guarda hasta pulsar Guardar)." ok-text="Limpiar" cancel-text="No" @confirm="clearInvoice">
                  <a-button size="small" danger><ClearOutlined /> Limpiar factura</a-button>
                </a-popconfirm>
                <a-button type="primary" size="small" @click="addLine"><PlusOutlined /> Agregar producto</a-button>
                <a-button v-if="mode === 'edit'" size="small" @click="openInvoice(editId)">Cancelar</a-button>
              </a-space>
            </div>
            <a-form layout="vertical" class="tight">
              <a-row :gutter="8">
                <a-col :span="8">
                  <a-form-item label="Nro. Factura">
                    <a-input-group compact>
                      <a-input v-model:value="form.series" :disabled="mode === 'edit'" style="width: 40%" />
                      <a-input-number v-model:value="form.number" :disabled="mode === 'edit'" :min="1" style="width: 60%" />
                    </a-input-group>
                  </a-form-item>
                </a-col>
                <a-col :span="6"><a-form-item label="Fecha Emisión"><a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
                <a-col :span="10"><a-form-item label="Forma de Pago"><a-select v-model:value="form.payment_term_id" allow-clear :options="termOptions" /></a-form-item></a-col>
              </a-row>
              <a-row :gutter="8">
                <a-col :span="16"><a-form-item label="Cliente"><a-select v-model:value="form.client_id" :disabled="mode === 'edit'" show-search option-filter-prop="label" :options="clientOptions" @change="onClient" /></a-form-item></a-col>
                <a-col :span="8"><a-form-item label="Orden de compra"><a-input v-model:value="form.purchase_order" /></a-form-item></a-col>
              </a-row>
              <!-- The client's guides to invoice (2026-11-22): ticking one adds its products; products can be added too. -->
              <div v-if="form.client_id" class="gl">
                <div class="gl-h">Guías del cliente por facturar <span class="muted">({{ cGuides.length }})</span></div>
                <div v-if="!cGuides.length" class="muted small">No tiene guías despachadas por facturar.</div>
                <div class="gl-list">
                  <label v-for="g in cGuides" :key="g.id" class="gl-row" :class="{ on: form.guide_ids.includes(g.id) }">
                    <a-checkbox :checked="form.guide_ids.includes(g.id)" @change="e => toggleGuide(g, e.target.checked)" />
                    <span class="mono">{{ gref(g.series, g.number) }}</span>
                    <span class="muted">{{ date(g.issue_date) }}</span>
                    <span v-if="g.purchase_order" class="muted">OC {{ g.purchase_order }}</span>
                    <span class="grow" />
                    <span class="muted">{{ g.items.length }} prod. · {{ number(g.items.reduce((s, i) => s + Number(i.quantity), 0), 0) }} unid.</span>
                  </label>
                </div>
              </div>
              <table class="lines edit-lines">
                <colgroup><col style="width: 24px" /><col /><col style="width: 100px" /><col style="width: 85px" /><col style="width: 82px" /><col style="width: 96px" /><col style="width: 34px" /></colgroup>
                <thead><tr><th>#</th><th class="pc">Producto</th><th class="n">V.U.</th><th class="n">Cant.</th><th>Unid.</th><th class="n">Subtotal</th><th /></tr></thead>
                <tbody>
                  <tr v-for="(it, i) in form.items" :key="i">
                    <td class="muted">{{ i + 1 }}</td>
                    <td>
                      <a-select v-model:value="it.product_id" size="small" show-search option-filter-prop="label" :options="productOptions" :disabled="!!it.guide_id" style="width: 100%" @change="onProduct(it)" />
                      <div v-if="it.guide_id" class="from-g">de la guía {{ guideRef(it.guide_id) }}</div>
                    </td>
                    <td class="n"><a-input-number v-model:value="it.price" size="small" :min="0" :precision="4" style="width: 100%" /></td>
                    <td class="n"><a-input-number v-model:value="it.quantity" size="small" :min="0" :disabled="!!it.guide_id" style="width: 100%" /></td>
                    <td><a-select v-model:value="it.unit_id" size="small" style="width: 100%" :options="unitOptions" /></td>
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
              <a-button type="primary" block class="save" :loading="saving" @click="saveInvoice">{{ mode === 'edit' ? 'Guardar cambios' : 'Guardar Factura' }}</a-button>
            </a-form>
          </template>

          <!-- One invoice -->
          <template v-else-if="inv">
            <!-- Buttons where Hordex had "ANULAR FACTURA", plus Imprimir and PDF (client's request, 2026-11-20). -->
            <div class="doc-actions">
              <a-popconfirm v-if="inv.state !== 'ANULADA' && inv.state !== 'CANCELADA'" :title="`¿Anular la factura ${inv.Ref}? Sus guías quedarán libres para facturar.`"
                ok-text="Anular" cancel-text="No" @confirm="cancelInvoice">
                <a-button size="small" danger>ANULAR FACTURA</a-button>
              </a-popconfirm>
              <a-popconfirm v-if="inv.state === 'ANULADA'" :title="`¿Revertir la anulación de ${inv.Ref}? La factura vuelve a estar vigente con sus guías.`"
                ok-text="Revertir" cancel-text="No" @confirm="revertCancel">
                <a-button size="small"><UndoOutlined /> Revertir anulación</a-button>
              </a-popconfirm>
              <a-button size="small" :loading="printing" @click="printInvoice"><PrinterOutlined /> Imprimir</a-button>
              <a-button size="small" @click="pdfInvoice"><FilePdfOutlined /> PDF</a-button>
              <a-tooltip :title="inv.paid_amount > 0 ? 'Tiene pagos: quítelos primero para editarla' : 'Limpiar la factura y rearmarla con guías o productos'">
                <a-button v-if="inv.state !== 'ANULADA'" size="small" :disabled="inv.paid_amount > 0" @click="editInvoice"><EditOutlined /> Editar</a-button>
              </a-tooltip>
              <a-button size="small" @click="newInvoice"><PlusOutlined /> Nueva factura</a-button>
            </div>
            <!-- Hordex's invoice document (parciales/detalleFactura): number box on the right, state under it. -->
            <div class="doc">
              <div class="doc-box">
                <div class="doc-box-t">{{ inv.Ref?.startsWith('E') ? 'FACTURA ELECTRONICA' : 'FACTURA' }}</div>
                <div class="doc-box-n">Nº {{ inv.Ref }}</div>
              </div>
              <div class="doc-state" :class="inv.state">{{ inv.state }}</div>
              <div class="doc-lines">
                <div><span>Fecha de Emisión</span>{{ date(inv.date) }}</div>
                <div><span>Fecha de Vencimiento</span>{{ inv.due_date ? date(inv.due_date) : '' }}</div>
                <div><span>Señor(es)</span>{{ inv.client_name }}</div>
                <div><span>RUC</span>{{ inv.tax_number }}</div>
                <div><span>Tipo de Moneda</span>SOLES</div>
                <div><span>Forma de Pago</span>{{ inv.term }}</div>
              </div>
              <div class="doc-grid doc-grid-h"><div>Cantidad</div><div>Unidad Medida</div><div>Descripción</div><div>Valor Unitario</div><div>Sub Total</div></div>
              <div v-for="(l, i) in inv.lines" :key="i" class="doc-grid">
                <div class="r">{{ number(l.quantity, 2) }}</div><div class="c">{{ (l.unit_name || l.unit || '').toUpperCase() }}</div>
                <div>{{ l.name }}</div><div class="r">{{ number(l.price, 2) }}</div><div class="r">{{ number(l.total, 2) }}</div>
              </div>
              <div class="doc-tot"><div /><div>Neto</div><div>{{ number(inv.GrandTotal - inv.TaxNet, 2) }}</div></div>
              <div class="doc-tot"><div><b>SON: {{ inv.words }}</b></div><div>IGV</div><div>{{ number(inv.TaxNet, 2) }}</div></div>
              <div class="doc-tot"><div /><div>Total</div><div>{{ number(inv.GrandTotal, 2) }}</div></div>
              <template v-if="inv.retentions?.length">
                <div class="doc-tot ret-h"><div /><div>Retenciones realizadas por el Cliente</div><div /></div>
                <div v-for="r in inv.retentions" :key="r.name" class="doc-tot"><div /><div>{{ r.name }}</div><div>{{ number(r.amount, 2) }}</div></div>
                <div class="doc-tot"><div /><div>Total a Percibir</div><div>{{ number(inv.to_receive, 2) }}</div></div>
              </template>
            </div>
            <div class="doc-extra muted">
              <span v-if="inv.purchase_order">OC {{ inv.purchase_order }} · </span>
              <span v-if="inv.guides?.length">Guías {{ inv.guides.map(g => gref(g.series, g.number)).join(', ') }} · </span>
              Abonado {{ money(inv.paid_amount) }} · Saldo {{ money(inv.GrandTotal - inv.paid_amount) }}
            </div>
            <template v-if="inv.payments?.length">
              <h4 class="sub">Pagos</h4>
              <table class="lines">
                <thead><tr><th>Fecha</th><th>Concepto</th><th>Documento</th><th class="n">Monto</th></tr></thead>
                <tbody><tr v-for="p in inv.payments" :key="p.id"><td>{{ date(p.date) }}</td><td>{{ p.concept || p.method }}</td><td>{{ p.document }}</td><td class="n">{{ money(p.amount) }}</td></tr></tbody>
              </table>
            </template>
          </template>
        </a-card>
      </DetailPanel>
    </a-row>
  </div>
</template>

<script setup>
/** Distribución B2B -> Facturas (2026-11-20): Hordex's invoices -- list, the invoice clicked, a new invoice typed directly. */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, DeleteOutlined, CheckOutlined, ClockCircleOutlined, CloseCircleOutlined, PrinterOutlined, FilePdfOutlined, EditOutlined, ClearOutlined, UndoOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useBarTitle } from '../../composables/useBarTitle';
import DetailPanel from '../../components/DetailPanel.vue';
import { useIsPhone } from '../../lib/useIsPhone';

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
  { title: 'Factura', key: 'Ref', dataIndex: 'Ref', sorter: true, width: 108 },
  { title: 'Emisión', key: 'date', dataIndex: 'date', sorter: true, width: 92 },
  { title: 'Razón Social', key: 'client_name', dataIndex: 'client_name', sorter: true, ellipsis: true, width: 190 },
  { title: 'Total', key: 'GrandTotal', dataIndex: 'GrandTotal', sorter: true, width: 105, align: 'right' },
  { title: 'Estado', key: 'estado', width: 58, align: 'center' },
];
const estado = r => (r.statut === 'canceled' ? 'ANULADA' : r.payment_statut === 'paid' ? 'CANCELADA' : 'PENDIENTE');

const clientOptions = computed(() => sortByLabel((opts.value.clients || []).map(c => ({ value: c.id, label: c.name }))));
const productOptions = computed(() => sortByLabel((opts.value.products || []).map(p => ({ value: p.id, label: `${p.code} - ${p.name}` }))));
const termOptions = computed(() => (opts.value.terms || []).map(t => ({ value: t.id, label: t.name })));
const unitOptions = computed(() => (opts.value.units || []).map(u => ({ value: u.id, label: u.ShortName || u.name })));
const igvRate = computed(() => Number(opts.value.settings?.igv || 18));

const mode = ref('new');
// Phone (2026-11-21): the right side is a modal, opened by a row or "Nueva".
const isPhone = useIsPhone();
const phoneOpen = ref(false);
const saving = ref(false);
// Forma de pago by default: "FACTURA 30 DIAS" (user, 2026-11-20).
const defaultTerm = () => (opts.value.terms || []).find(t => /^factura\s+30\s+d[ií]as$/i.test(String(t.name).trim()))?.id;
const blankLine = () => ({ product_id: undefined, price: 0, quantity: 1, unit_id: undefined });
const blank = () => ({ series: opts.value.settings?.invoice_series || 'E001', number: opts.value.next_invoice, date: dayjs().format('YYYY-MM-DD'),
  payment_term_id: defaultTerm(), client_id: undefined, purchase_order: '', notes: '', items: [blankLine()], guide_ids: [] });
const form = reactive({ items: [], guide_ids: [] });
// The client's guides that can go on this invoice (2026-11-22).
const cGuides = ref([]);
const editId = ref(null);
const editRef = ref('');
async function loadGuides() {
  cGuides.value = form.client_id ? ((await http.get('b2b/invoices/guides', { client_id: form.client_id, sale_id: editId.value || undefined })).guides || []) : [];
}
function onClient() { form.guide_ids = []; form.items = form.items.filter(i => !i.guide_id); loadGuides(); }
const guideRef = id => { const g = cGuides.value.find(x => x.id === id); return g ? gref(g.series, g.number) : ''; };
function toggleGuide(g, on) {
  form.items = form.items.filter(i => i.guide_id !== g.id && (i.product_id || i.guide_id));
  if (on) {
    if (!form.guide_ids.includes(g.id)) form.guide_ids.push(g.id);
    g.items.forEach(it => form.items.push({ product_id: it.product_id, quantity: Number(it.quantity), price: Number(it.price) || 0, unit_id: it.unit_sale_id, guide_id: g.id }));
    if (!form.purchase_order && g.purchase_order) form.purchase_order = g.purchase_order;
  } else {
    form.guide_ids = form.guide_ids.filter(x => x !== g.id);
  }
  if (!form.items.length) form.items.push(blankLine());
}
function clearInvoice() { form.items = [blankLine()]; form.guide_ids = []; }
// Editar: the invoice's data in the form; its lines as they are (Limpiar empties them to rebuild it from a guide).
function editInvoice() {
  const x = inv.value;
  editId.value = x.id;
  editRef.value = x.Ref;
  const [series, num] = String(x.Ref || '').split('-');
  Object.assign(form, { series, number: Number(num) || undefined, date: x.date, payment_term_id: x.payment_term_id || undefined,
    client_id: x.client_id, purchase_order: x.purchase_order || '', notes: x.notes || '',
    items: (x.lines || []).map(l => ({ product_id: l.product_id, quantity: Number(l.quantity), price: Number(l.price), unit_id: l.unit_id })),
    guide_ids: (x.guides || []).map(g => g.id) });
  mode.value = 'edit';
  loadGuides();
}
const net = computed(() => form.items.reduce((s, i) => s + (i.price || 0) * (i.quantity || 0), 0));
const igv = computed(() => Math.round(net.value * igvRate.value) / 100);
function newInvoice() { Object.assign(form, blank()); editId.value = null; cGuides.value = []; mode.value = 'new'; }
function addLine() { if (mode.value !== 'new' && mode.value !== 'edit') newInvoice(); else form.items.push(blankLine()); }
function onProduct(it) {
  const p = (opts.value.products || []).find(x => x.id === it.product_id);
  if (p) { it.price = Number(p.price) || 0; it.unit_id = p.unit_sale_id || it.unit_id; }
}
async function saveInvoice() {
  const items = form.items.filter(i => i.product_id && i.quantity > 0);
  if (!form.client_id || !items.length) { message.warning('Elija el cliente y al menos un producto'); return; }
  saving.value = true;
  try {
    const body = { ...form, items: items.map(({ guide_id, ...l }) => l) };
    const r = mode.value === 'edit' ? await http.put(`b2b/invoices/${editId.value}`, body) : await http.post('b2b/invoices', body);
    message.success(mode.value === 'edit' ? `Factura ${r.ref} actualizada` : `Factura ${r.ref} registrada`);
    opts.value = await http.get('b2b/options');
    await crud.fetchRows();
    openInvoice(r.sale_id);
  } catch (e) {
    message.error(e?.data?.message || 'No se pudo guardar la factura');
  } finally { saving.value = false; }
}

const inv = ref(null);
async function openInvoice(id) { inv.value = await http.get(`b2b/invoices/${id}`); mode.value = 'view'; phoneOpen.value = true; }
async function cancelInvoice() {
  try {
    await http.post(`b2b/invoices/${inv.value.id}/cancel`);
    message.warning(`La Factura ${inv.value.Ref} ha sido anulada.`);
    await openInvoice(inv.value.id);
    crud.fetchRows();
  } catch (e) { message.error(e?.data?.message || 'No se pudo anular la factura.'); }
}
async function revertCancel() {
  try {
    const r = await http.post(`b2b/invoices/${inv.value.id}/revert-cancel`);
    let msg = `La Factura ${inv.value.Ref} vuelve a estar vigente.`;
    if (r.guides?.length) msg += ` Guías: ${r.guides.join(', ')}.`;
    message.success(msg);
    if (r.not_returned?.length) message.warning(`Ya facturadas en otra factura, no se devolvieron: ${r.not_returned.join(', ')}.`, 8);
    await openInvoice(inv.value.id);
    crud.fetchRows();
  } catch (e) { message.error(e?.data?.message || 'No se pudo revertir la anulación.'); }
}
const printing = ref(false);
async function printInvoice() {
  printing.value = true;
  try {
    const blob = await http.blob(`b2b/invoices/${inv.value.id}/pdf`);
    const url = URL.createObjectURL(blob);
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    frame.src = url;
    frame.onload = () => { setTimeout(() => { frame.contentWindow.focus(); frame.contentWindow.print(); }, 300); setTimeout(() => { frame.remove(); URL.revokeObjectURL(url); }, 60000); };
    document.body.appendChild(frame);
  } catch (e) { message.error('No se pudo preparar la impresión'); } finally { printing.value = false; }
}
function pdfInvoice() { http.download(`b2b/invoices/${inv.value.id}/pdf`, `${inv.value.Ref}.pdf`); }

onMounted(async () => { opts.value = await http.get('b2b/options'); newInvoice(); crud.fetchRows(); });

useBarTitle('Facturas');
</script>

<style scoped>
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; }
.side { position: sticky; top: 12px; }
.panel { min-height: calc(100vh - 170px); border-radius: 2px; }
.panel :deep(.datatable-toolbar) { padding-left: 0; padding-right: 0; }
.gl { border: 1px solid rgba(128, 128, 128, 0.22); border-radius: 6px; padding: 6px 8px; margin-bottom: 8px; }
.gl-h { font-weight: 600; font-size: 12.5px; margin-bottom: 4px; }
.gl-list { max-height: 170px; overflow: auto; }
.gl-row { display: flex; align-items: center; gap: 8px; padding: 2px 4px; font-size: 12px; border-radius: 4px; cursor: pointer; }
.gl-row:hover { background: rgba(128, 128, 128, 0.07); }
.gl-row.on { background: rgba(37, 99, 235, 0.08); }
.gl-row .grow { flex: 1; }
.from-g { font-size: 10.5px; color: #2563eb; }
.small { font-size: 12px; }
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
/* The form's table never grows past the panel: a long product name is cut inside its select. */
.edit-lines { table-layout: fixed; }
.edit-lines td.n { white-space: nowrap; }
.edit-lines :deep(.ant-select-selection-item) { overflow: hidden; text-overflow: ellipsis; }
.save { margin-top: 10px; }
.sub { margin: 10px 0 2px; font-size: 15px; font-weight: 500; }
.mono { font-family: ui-monospace, Consolas, monospace; }
.num { font-variant-numeric: tabular-nums; }
.muted { opacity: 0.6; }
.facts { display: grid; grid-template-columns: 130px 1fr; gap: 3px 10px; margin: 0 0 8px; font-size: 13px; }
.facts dt { opacity: 0.6; }
.facts dd { margin: 0; }
.ico { font-size: 14px; }
.doc-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.doc { position: relative; font-size: 12.5px; padding-top: 4px; }
.doc-box { position: absolute; right: 0; top: 0; min-width: 190px; border: 2px solid currentColor; padding: 10px 12px; text-align: center; }
.doc-box-t { font-size: 12.5px; font-weight: 700; }
.doc-box-n { font-weight: 700; margin-top: 10px; }
.doc-state { position: absolute; right: 0; top: 92px; min-width: 194px; text-align: center; font-weight: 700; font-size: 14px; }
.doc-state.ANULADA { color: #6b7280; }
.doc-state.PENDIENTE { color: #2563eb; }
.doc-state.VENCIDA { color: #dc2626; }
.doc-state.CANCELADA { color: #16a34a; }
.doc-lines { min-height: 128px; padding-right: 205px; line-height: 1.6; }
.doc-lines > div { display: flex; }
.doc-lines span { flex: 0 0 150px; position: relative; }
.doc-lines span::after { content: ':'; position: absolute; right: 10px; }
.doc-grid { display: grid; grid-template-columns: 70px 90px 1fr 90px 90px; line-height: 1.6; }
.doc-grid > div { padding: 0 6px; }
.doc-grid-h { background: #000; color: #fff; margin-top: 8px; }
.doc-grid .r { text-align: right; font-variant-numeric: tabular-nums; }
.doc-grid .c { text-align: center; }
.doc-tot { display: grid; grid-template-columns: 1fr 190px 90px; line-height: 1.6; }
.doc-tot > div { padding: 0 6px; }
.doc-tot > div:nth-child(2) { text-align: right; font-style: italic; }
.doc-tot > div:nth-child(3) { text-align: right; font-variant-numeric: tabular-nums; }
.doc-tot.ret-h > div:nth-child(2) { text-align: left; font-style: normal; color: #ea580c; font-weight: 700; text-transform: uppercase; grid-column: 2 / 4; }
.doc-extra { margin-top: 10px; font-size: 12px; }
.pc { width: 46%; }
.green { color: #16a34a; }
.blue { color: #2563eb; }
.red { color: #dc2626; }
</style>
