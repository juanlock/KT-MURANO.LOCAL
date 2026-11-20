<template>
  <div class="page b2b">
    <PageHeader :title="tr('Guides', 'Guías')" />

    <!-- Compact way of working (user, 2026-11-20, from Hordex): the list on the left, the form on the right. -->
    <a-row :gutter="14">
      <a-col :xs="24" :xl="14">
        <!-- Two panels side by side, same top and height, like Hordex (user: "las 2 tablas deben empezar en la misma línea"). -->
        <a-card size="small" class="side panel">
        <div class="side-head"><h3>{{ tr('Guide list', 'Listado de guías') }}</h3></div>
        <DataTable :crud="crud" :columns="columns" :row-click="r => openGuide(r.id)" bulk-select compact-toolbar table-key="b2b-guides">
          <template #filters>
            <a-select v-model:value="filters.status" size="small" style="width: 150px" :options="statusOptions" @change="reload" />
            <a-select v-model:value="filters.client_id" size="small" allow-clear show-search option-filter-prop="label" style="width: 190px"
              :placeholder="tr('Client', 'Cliente')" :options="clientOptions" @change="reload" />
            <PresetRangeFilter v-model:value="range" allow-all compact @change="reload" />
          </template>
          <template #bulk="{ ids }">
            <a-button v-if="can('b2b_invoice')" type="primary" size="small" @click="startInvoice(ids)">
              <template #icon><FileDoneOutlined /></template>{{ tr('Invoice', 'Facturar') }} ({{ ids.length }})
            </a-button>
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'number'">
              <a class="mono" @click="openGuide(record.id)">{{ ref(record.series, record.number) }}</a>
            </template>
            <template v-else-if="column.key === 'issue_date'">{{ date(record.issue_date) }}</template>
            <template v-else-if="column.key === 'units'"><span class="num">{{ number(record.units || 0, 0) }}</span></template>
            <template v-else-if="column.key === 'status'">
              <span class="st" :class="record.status"><i />{{ statusLabel(record.status) }}</span>
              <span v-if="record.sale_ref" class="muted mono"> · {{ record.sale_ref }}</span>
            </template>
          </template>
        </DataTable>
        </a-card>
      </a-col>

      <a-col :xs="24" :xl="10">
        <a-card size="small" class="side panel">
          <!-- New guide -->
          <template v-if="mode === 'new'">
            <div class="side-head">
              <h3>{{ tr('Register new guide', 'Registrar Nueva Guía') }} <span class="mono muted">{{ ref(form.series, opts.next_guide) }}</span></h3>
            </div>
            <a-form layout="vertical" class="tight">
              <a-row :gutter="8">
                <a-col :span="8"><a-form-item :label="tr('Issue date', 'Fecha de emisión')"><a-date-picker v-model:value="form.issue_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
                <a-col :span="8"><a-form-item :label="tr('Transfer date', 'Fecha de traslado')"><a-date-picker v-model:value="form.transfer_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
                <a-col :span="8"><a-form-item :label="tr('Purchase order', 'Orden de compra')"><a-input v-model:value="form.purchase_order" /></a-form-item></a-col>
              </a-row>
              <a-form-item :label="tr('Client', 'Cliente')">
                <a-select v-model:value="form.client_id" show-search option-filter-prop="label" :options="clientOptions" @change="form.address_id = undefined" />
              </a-form-item>
              <a-row :gutter="8">
                <a-col :span="14">
                  <a-form-item :label="tr('Delivery point', 'Punto de llegada')">
                    <a-select v-model:value="form.address_id" allow-clear show-search option-filter-prop="label" :options="addressOptions" :placeholder="tr('Client address', 'Dirección del cliente')" />
                  </a-form-item>
                </a-col>
                <a-col :span="10">
                  <a-form-item :label="tr('Reason of transfer', 'Motivo de traslado')">
                    <a-select v-model:value="form.reason_id" :options="reasonOptions" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-row :gutter="8">
                <a-col :span="10"><a-form-item :label="tr('Carrier', 'Transportista')"><a-select v-model:value="form.carrier_id" allow-clear :options="carrierOptions" @change="onCarrier" /></a-form-item></a-col>
                <a-col :span="7"><a-form-item :label="tr('Plate', 'Placa')"><a-input v-model:value="form.plate" /></a-form-item></a-col>
                <a-col :span="7"><a-form-item :label="tr('License', 'Licencia')"><a-input v-model:value="form.driver_license" /></a-form-item></a-col>
              </a-row>
              <table class="lines">
                <thead><tr><th>#</th><th>{{ tr('Product', 'Producto') }}</th><th class="n">{{ tr('Qty.', 'Cant.') }}</th><th>{{ tr('Unit', 'Unid.') }}</th><th /></tr></thead>
                <tbody>
                  <tr v-for="(it, i) in form.items" :key="i">
                    <td class="muted">{{ i + 1 }}</td>
                    <td><a-select v-model:value="it.product_id" size="small" show-search option-filter-prop="label" :options="productOptions" style="width: 100%" /></td>
                    <td class="n"><a-input-number v-model:value="it.quantity" size="small" :min="0" style="width: 90px" /></td>
                    <td><a-select v-model:value="it.unit" size="small" style="width: 72px" :options="unitOptions" /></td>
                    <td><a-button type="text" size="small" danger @click="form.items.splice(i, 1)"><DeleteOutlined /></a-button></td>
                  </tr>
                </tbody>
              </table>
              <a-button size="small" type="dashed" block @click="addLine"><PlusOutlined /> {{ tr('Add product', 'Agregar producto') }}</a-button>
              <a-button type="primary" block class="save" :loading="saving" @click="saveGuide">{{ tr('Save guide', 'Guardar guía') }}</a-button>
            </a-form>
          </template>

          <!-- One guide -->
          <template v-else-if="mode === 'view' && guide">
            <div class="side-head">
              <h3 class="mono">{{ ref(guide.series, guide.number) }}</h3>
              <a-space>
                <a-button size="small" @click="newGuide"><PlusOutlined /> {{ tr('New guide', 'Nueva guía') }}</a-button>
                <a-popconfirm v-if="can('b2b_guides') && guide.status === 'dispatched'" :title="tr('Cancel this guide? Its goods go back to the warehouse.', '¿Anular esta guía? La mercadería vuelve al almacén.')" @confirm="cancelGuide">
                  <a-button size="small" danger>{{ tr('Cancel guide', 'Anular') }}</a-button>
                </a-popconfirm>
              </a-space>
            </div>
            <dl class="facts">
              <dt>{{ tr('Status', 'Estado') }}</dt><dd><span class="st" :class="guide.status"><i />{{ statusLabel(guide.status) }}</span> <span v-if="guide.sale_ref" class="mono">{{ guide.sale_ref }}</span></dd>
              <dt>{{ tr('Client', 'Cliente') }}</dt><dd>{{ guide.client_name }}</dd>
              <dt>{{ tr('Issue / transfer', 'Emisión / traslado') }}</dt><dd>{{ date(guide.issue_date) }} · {{ guide.transfer_date ? date(guide.transfer_date) : '—' }}</dd>
              <dt>{{ tr('Delivery point', 'Punto de llegada') }}</dt><dd>{{ guide.delivery_address || '—' }}</dd>
              <dt>{{ tr('Reason', 'Motivo') }}</dt><dd>{{ guide.reason_name || '—' }}</dd>
              <dt>{{ tr('Carrier', 'Transportista') }}</dt><dd>{{ guide.carrier_name || '—' }} <span v-if="guide.plate" class="muted">· {{ guide.plate }}</span></dd>
              <dt>{{ tr('Purchase order', 'Orden de compra') }}</dt><dd>{{ guide.purchase_order || '—' }}</dd>
            </dl>
            <table class="lines">
              <thead><tr><th>{{ tr('Product', 'Producto') }}</th><th class="n">{{ tr('Qty.', 'Cant.') }}</th><th>{{ tr('Unit', 'Unid.') }}</th></tr></thead>
              <tbody><tr v-for="it in guide.items" :key="it.id"><td>{{ it.product_name || it.description }}</td><td class="n">{{ number(it.quantity, 0) }}</td><td>{{ it.unit }}</td></tr></tbody>
            </table>
          </template>

          <!-- Invoice the selected guides -->
          <template v-else-if="mode === 'invoice'">
            <div class="side-head">
              <h3>{{ tr('Invoice guides', 'Facturar guías') }} <span class="mono muted">{{ ref(inv.series, opts.next_invoice) }}</span></h3>
              <a-button size="small" @click="newGuide">{{ tr('Close', 'Cerrar') }}</a-button>
            </div>
            <p class="muted">{{ inv.guides.map(g => ref(g.series, g.number)).join(', ') }} · {{ inv.client }}</p>
            <a-row :gutter="8">
              <a-col :span="8"><a-form-item :label="tr('Date', 'Fecha')"><a-date-picker v-model:value="inv.date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
              <a-col :span="16"><a-form-item :label="tr('Payment term', 'Condición de pago')"><a-select v-model:value="inv.payment_term_id" allow-clear :options="termOptions" /></a-form-item></a-col>
            </a-row>
            <table class="lines">
              <thead><tr><th>{{ tr('Product', 'Producto') }}</th><th class="n">{{ tr('Qty.', 'Cant.') }}</th><th class="n">{{ tr('Unit price', 'V. unit.') }}</th><th class="n">{{ tr('Subtotal', 'Subtotal') }}</th></tr></thead>
              <tbody>
                <tr v-for="l in inv.lines" :key="l.product_id">
                  <td>{{ l.name }}</td><td class="n">{{ number(l.quantity, 0) }}</td>
                  <td class="n"><a-input-number v-model:value="l.price" size="small" :min="0" :precision="4" style="width: 100px" /></td>
                  <td class="n">{{ money(l.price * l.quantity) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr><td colspan="3" class="n">{{ tr('Net', 'Sub total') }}</td><td class="n">{{ money(invNet) }}</td></tr>
                <tr><td colspan="3" class="n">IGV {{ opts.settings?.igv || 18 }}%</td><td class="n">{{ money(invIgv) }}</td></tr>
                <tr class="tot"><td colspan="3" class="n">{{ tr('Total', 'Total') }}</td><td class="n">{{ money(invNet + invIgv) }}</td></tr>
              </tfoot>
            </table>
            <a-button type="primary" block class="save" :loading="saving" @click="emitInvoice">{{ tr('Issue invoice', 'Emitir factura') }}</a-button>
          </template>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Distribución B2B -> Guías (2026-11-20): Hordex's compact way of working -- the guides on the left (dense rows, hover,
 * status as a dot), the form on the right: a new guide, the guide clicked, or the invoice of the guides ticked (same client,
 * dispatched). B2bController guides / storeGuide / cancelGuide / invoice.
 */
import { ref as vref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, DeleteOutlined, FileDoneOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import { useTr } from '../../lib/useTr';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const tr = useTr();
const auth = useAuthStore();
const can = p => auth.can(p);
const { date, number, money } = useFormat();
const ref = (s, n) => `${s}-${String(n || 0).padStart(6, '0')}`;

const opts = vref({});
const filters = reactive({ status: 'all', client_id: undefined });
const range = vref([]);
const crud = useCrudTable('b2b/guides', {
  rowsKey: 'guides', sortField: 'issue_date', limit: 50,
  params: () => ({ ...filters, status: filters.status === 'all' ? undefined : filters.status, from: range.value?.[0], to: range.value?.[1] }),
});
const reload = () => crud.reload();

const STATUS = { dispatched: ['To invoice', 'Por facturar'], invoiced: ['Invoiced', 'Facturada'], closed: ['Closed (history)', 'Cerrada (histórico)'], cancelled: ['Cancelled', 'Anulada'] };
const statusLabel = s => (STATUS[s] ? tr(...STATUS[s]) : s);
const statusOptions = computed(() => [{ value: 'dispatched', label: tr('To invoice', 'Por facturar') }, { value: 'invoiced', label: tr('Invoiced', 'Facturadas') },
  { value: 'closed', label: tr('Closed (history)', 'Cerradas (histórico)') }, { value: 'cancelled', label: tr('Cancelled', 'Anuladas') }, { value: 'all', label: tr('All', 'Todas') }]);
const columns = computed(() => [
  { title: tr('Guide', 'Guía'), key: 'number', dataIndex: 'number', sorter: true, width: 120 },
  { title: tr('Date', 'Fecha'), key: 'issue_date', dataIndex: 'issue_date', sorter: true, width: 100 },
  { title: tr('Client', 'Cliente'), key: 'client_name', dataIndex: 'client_name', sorter: true, ellipsis: true },
  { title: tr('PO', 'OC'), key: 'purchase_order', dataIndex: 'purchase_order', width: 110, ellipsis: true },
  { title: tr('Units', 'Unid.'), key: 'units', dataIndex: 'units', width: 80, align: 'right' },
  { title: tr('Status', 'Estado'), key: 'status', dataIndex: 'status', sorter: true, width: 190 },
]);

const clientOptions = computed(() => sortByLabel((opts.value.clients || []).map(c => ({ value: c.id, label: c.name }))));
const productOptions = computed(() => sortByLabel((opts.value.products || []).filter(p => p.type !== 'is_service').map(p => ({ value: p.id, label: `${p.code} · ${p.name}` }))));
const carrierOptions = computed(() => sortByLabel((opts.value.carriers || []).map(c => ({ value: c.id, label: c.name }))));
const reasonOptions = computed(() => (opts.value.reasons || []).filter(r => r.direction === 'out').map(r => ({ value: r.id, label: `${r.sunat_code} · ${r.name}` })));
const termOptions = computed(() => (opts.value.terms || []).map(t => ({ value: t.id, label: t.name })));
const addressOptions = computed(() => sortByLabel((opts.value.addresses || []).filter(a => a.client_id === form.client_id)
  .map(a => ({ value: a.id, label: [a.alias, a.address, a.district].filter(Boolean).join(' · ') }))));
const unitOptions = ['UND', 'CTO', 'MLL', 'DOC', 'KG'].map(v => ({ value: v, label: v }));

// ---- new guide
const mode = vref('new');
const saving = vref(false);
const blank = () => ({ series: opts.value.settings?.guide_series || 'EG07', issue_date: dayjs().format('YYYY-MM-DD'), transfer_date: dayjs().format('YYYY-MM-DD'),
  client_id: undefined, address_id: undefined, reason_id: (opts.value.reasons || []).find(r => r.sunat_code === '01')?.id, carrier_id: undefined,
  plate: '', driver_license: '', purchase_order: '', items: [{ product_id: undefined, quantity: 1, unit: 'UND' }] });
const form = reactive({ items: [] });
function newGuide() { Object.assign(form, blank()); mode.value = 'new'; }
function addLine() { form.items.push({ product_id: undefined, quantity: 1, unit: 'UND' }); }
function onCarrier(id) {
  const c = (opts.value.carriers || []).find(x => x.id === id);
  if (c) { form.plate = c.plate || form.plate; form.driver_license = c.license || form.driver_license; }
}
async function saveGuide() {
  saving.value = true;
  try {
    const r = await http.post('b2b/guides', { ...form, items: form.items.filter(i => i.product_id && i.quantity > 0) });
    message.success(tr(`Guide ${r.ref} saved`, `Guía ${r.ref} guardada`));
    await loadOptions();
    newGuide();
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || tr('Could not save the guide.', 'No se pudo guardar la guía.'));
  } finally { saving.value = false; }
}

// ---- one guide
const guide = vref(null);
async function openGuide(id) { guide.value = await http.get(`b2b/guides/${id}`); mode.value = 'view'; }
async function cancelGuide() {
  try { await http.post(`b2b/guides/${guide.value.id}/cancel`); message.success(tr('Guide cancelled', 'Guía anulada')); await openGuide(guide.value.id); crud.fetchRows(); } catch (e) { message.error(e?.data?.message || 'Error'); }
}

// ---- invoice
const inv = reactive({ guides: [], client: '', lines: [], date: dayjs().format('YYYY-MM-DD'), payment_term_id: undefined, series: 'E001' });
const invNet = computed(() => inv.lines.reduce((s, l) => s + (Number(l.price) || 0) * l.quantity, 0));
const invIgv = computed(() => Math.round(invNet.value * (opts.value.settings?.igv || 18)) / 100);
async function startInvoice(ids) {
  const rows = crud.rows.value.filter(r => ids.includes(r.id));
  if (rows.some(r => r.status !== 'dispatched')) return message.warning(tr('Only guides still to invoice.', 'Solo guías por facturar.'));
  if (new Set(rows.map(r => r.client_id)).size !== 1) return message.warning(tr('The guides must be of the same client.', 'Las guías deben ser del mismo cliente.'));
  const full = await Promise.all(rows.map(r => http.get(`b2b/guides/${r.id}`)));
  const lines = {};
  full.forEach(g => g.items.forEach(it => {
    lines[it.product_id] ??= { product_id: it.product_id, name: it.product_name, quantity: 0, price: Number(it.price) || 0 };
    lines[it.product_id].quantity += Number(it.quantity);
  }));
  Object.assign(inv, { guides: full, client: full[0].client_name, lines: Object.values(lines), series: opts.value.settings?.invoice_series || 'E001',
    date: dayjs().format('YYYY-MM-DD'), payment_term_id: undefined });
  mode.value = 'invoice';
}
async function emitInvoice() {
  saving.value = true;
  try {
    const r = await http.post('b2b/invoice', { guide_ids: inv.guides.map(g => g.id), date: inv.date, payment_term_id: inv.payment_term_id,
      prices: Object.fromEntries(inv.lines.map(l => [l.product_id, l.price])) });
    message.success(tr(`Invoice ${r.ref} issued`, `Factura ${r.ref} emitida`));
    crud.selectedIds.value = [];
    await loadOptions();
    newGuide();
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || tr('Could not issue the invoice.', 'No se pudo emitir la factura.'));
  } finally { saving.value = false; }
}

async function loadOptions() { opts.value = await http.get('b2b/options'); }
onMounted(async () => { await loadOptions(); newGuide(); crud.fetchRows(); });
</script>

<style scoped>
/* Compact rows (Hordex's way, user 2026-11-20): one line per row, long names cut with an ellipsis. */
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; word-break: normal !important; overflow-wrap: normal !important; overflow: hidden; text-overflow: ellipsis; }
.b2b :deep(.dt-managed td.ant-table-cell-ellipsis) { max-width: 260px; }
.side { position: sticky; top: 12px; }
.panel { min-height: calc(100vh - 170px); border-radius: 2px; }
.panel :deep(.ant-card) { box-shadow: none; border: 0; }
.panel :deep(.datatable-toolbar) { padding-left: 0; padding-right: 0; }
.ptl { font-size: 22px; font-weight: 400; margin: 0 0 6px; }
.side-head h3 { font-size: 22px !important; font-weight: 400; }
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 16px; }
.tight :deep(.ant-form-item) { margin-bottom: 8px; }
.tight :deep(.ant-form-item-label) { padding-bottom: 2px; }
.tight :deep(.ant-form-item-label > label) { font-size: 12px; opacity: 0.75; }
.lines { width: 100%; border-collapse: collapse; font-size: 12.5px; margin: 6px 0; }
.lines th { background: #2b2f36; color: #fff; font-weight: 600; padding: 4px 6px; text-align: left; }
.lines td { padding: 3px 6px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); }
.lines tbody tr:nth-child(even) td { background: rgba(128, 128, 128, 0.05); }
.lines tbody tr:hover td { background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.08); }
.lines .n { text-align: right; font-variant-numeric: tabular-nums; }
.lines tfoot td { border: 0; padding: 2px 6px; }
.lines tfoot .tot td { font-weight: 700; font-size: 14px; }
.save { margin-top: 10px; }
.mono { font-family: ui-monospace, Consolas, monospace; }
.num { font-variant-numeric: tabular-nums; }
.muted { opacity: 0.6; }
.facts { display: grid; grid-template-columns: 130px 1fr; gap: 3px 10px; margin: 0 0 8px; font-size: 13px; }
.facts dt { opacity: 0.6; }
.facts dd { margin: 0; }
.st { white-space: nowrap; }
.st i { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-inline-end: 6px; background: #94a3b8; }
.st.dispatched i { background: #f59e0b; }
.st.invoiced i { background: #16a34a; }
.st.cancelled i { background: #dc2626; }
.st.closed i { background: #94a3b8; }
</style>
