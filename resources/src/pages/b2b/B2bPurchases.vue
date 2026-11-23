<template>
  <div class="page b2b">
    <!-- Hordex's "Facturas de Proveedores", like Facturas (user, 2026-11-23: "similar a b2b/invoices con la opción de nuevo a
         mano o xml"): the list on the left; on the right a new invoice (typed or from the supplier's XML), or the invoice
         clicked with Editar and its state buttons. The title is in the top bar (useBarTitle). -->
    <a-row :gutter="14">
      <a-col :xs="24" :xl="11">
        <a-card size="small" class="side panel">
          <div class="side-head">
            <h3>Listado de Facturas de Proveedores</h3>
            <!-- Above the table, blue, always (user, 2026-11-23). -->
            <a-button type="primary" size="small" @click="newPurchase(); phoneOpen = true"><PlusOutlined /> Agregar Nuevo</a-button>
          </div>
          <DataTable :crud="crud" :columns="columns" :row-click="r => openPurchase(r.id)" :selected-key="mode === 'view' ? sel?.id ?? null : null" compact-toolbar table-key="b2b-purchases">
            <template #filters>
              <a-select v-model:value="filters.status" size="small" style="width: 140px" :options="statusOptions" @change="crud.reload" />
            </template>
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'date'">{{ d(record.date) }}</template>
              <template v-else-if="column.key === 'Ref'"><span class="mono">{{ record.Ref }}</span></template>
              <template v-else-if="column.key === 'GrandTotal'"><span class="money"><span>{{ record.currency }}</span><span class="num">{{ fmt(record.GrandTotal) }}</span></span></template>
              <template v-else-if="column.key === 'estado'"><span class="st" :class="record.estado.toLowerCase()">{{ record.estado }}</span></template>
            </template>
          </DataTable>
        </a-card>
      </a-col>

      <DetailPanel :open="phoneOpen" :xs="24" :xl="13" @close="phoneOpen = false">
        <a-card size="small" class="side panel form">
          <!-- New / edit -->
          <template v-if="mode === 'new' || mode === 'edit'">
            <div class="side-head">
              <h3>{{ mode === 'edit' ? `Editar Factura ${editRef}` : 'Registrar Nueva Factura de Proveedor' }}</h3>
              <a-space :size="6">
                <!-- The supplier's SUNAT electronic invoice (XML, or the zip it comes in) fills the form (2026-11-23). -->
                <a-button v-if="mode === 'new'" size="small" :loading="readingXml" @click="xmlInput.click()"><CloudUploadOutlined /> XML</a-button>
                <a-button v-if="mode === 'new'" size="small" @click="newPurchase">Limpiar</a-button>
                <a-button v-if="mode === 'edit'" size="small" @click="openPurchase(editId)">Cancelar</a-button>
              </a-space>
              <input ref="xmlInput" type="file" accept=".xml,.zip,text/xml,application/xml,application/zip" style="display: none" @change="importXml" />
            </div>
            <a-alert v-if="xmlWarnings.length" type="warning" show-icon class="xml-warn" :message="xmlWarnings.join(' · ')" />
            <div class="f-row r1">
              <label>Proveedor<a-select v-model:value="f.provider_id" size="small" show-search option-filter-prop="label" :options="providerOptions" @change="onProvider" /></label>
              <label>Tipo<a-select v-model:value="f.kind" size="small" :options="KINDS" @change="onKind" /></label>
              <label>Moneda<a-select v-model:value="f.currency" size="small" :options="[{ value: 'PEN', label: 'PEN S/' }, { value: 'USD', label: 'USD $' }]" /></label>
              <label v-if="f.currency === 'USD'">T.C.<a-input-number v-model:value="f.tc" size="small" :min="0" :step="0.01" :precision="3" style="width: 100%" /></label>
            </div>
            <div class="f-row r2">
              <label>Factura
                <span class="doc-in">
                  <a-input v-model:value="f.series" size="small" :maxlength="5" :placeholder="next.series || 'F001'" class="ser" @change="loadNext" />
                  <span>-</span>
                  <a-input-number v-model:value="f.number" size="small" :min="1" :placeholder="next.next ? String(next.next) : '0000000'" class="cor" />
                </span>
                <span v-if="next.last && mode === 'new'" class="hint">Última: {{ next.series }}-{{ next.last }} · <a @click="f.number = next.next; f.series ||= next.series">usar {{ next.next }}</a></span>
              </label>
              <label>Subtotal<span class="ro num">{{ fmt(net) }}</span></label>
              <label>IGV<span class="ro num">{{ fmt(net * 0.18) }}</span></label>
              <label>Total<span class="ro num tot">{{ fmt(net * 1.18) }}</span></label>
            </div>
            <div class="f-row r3">
              <label>F. Emisión<a-date-picker v-model:value="f.f_emision" value-format="YYYY-MM-DD" size="small" :disabled-date="x => x && x.isAfter(today)" @change="onEmision" /></label>
              <label>F. Venc.<a-date-picker v-model:value="f.f_venc" value-format="YYYY-MM-DD" size="small" /></label>
              <!-- A new supplier invoice is PENDIENTE, due in 30 days (user, 2026-11-23); paid later with its state button. -->
              <label v-if="mode === 'edit' && editState === 'CANCELADA'">F. Pago<a-date-picker v-model:value="f.f_pago" value-format="YYYY-MM-DD" size="small" /></label>
              <label v-else>Estado<span class="ro st" :class="(mode === 'edit' ? editState : 'PENDIENTE').toLowerCase()">{{ mode === 'edit' ? editState : 'PENDIENTE' }}</span></label>
            </div>
            <div class="f-btns">
              <a-button size="small" :disabled="!f.provider_id || !f.kind" @click="addItem"><PlusOutlined /> Agregar Item</a-button>
              <a-button size="small" :disabled="!f.provider_id || f.kind !== 'bienes'" @click="openLink"><LinkOutlined /> Vincular</a-button>
              <span v-if="f.receipt_ids.length" class="muted">Guías vinculadas: {{ linkedGuides }}</span>
            </div>
            <div class="items">
              <table class="grid">
                <thead><tr><th class="nr">#</th><th>Descripción</th><th class="n">Cant</th><th class="n">P.Unit.</th><th class="n">Subtotal</th><th /></tr></thead>
                <tbody>
                  <tr v-for="(it, i) in f.items" :key="i">
                    <td class="nr">{{ i + 1 }}</td>
                    <td class="desc">
                      <span v-if="it.receipt_id">{{ it.name }} <span class="ref">G.R. {{ it.guide }}</span></span>
                      <template v-else>
                        <a-select v-model:value="it.product_id" size="small" show-search option-filter-prop="label" :options="itemOptions" style="width: 100%"
                          :status="it.xml && !it.product_id ? 'error' : undefined"
                          :dropdown-match-select-width="false" :dropdown-style="{ minWidth: '420px', maxWidth: '90vw' }" />
                        <div v-if="it.xml" class="ref">XML: {{ it.xml }}</div>
                      </template>
                    </td>
                    <td class="n"><span v-if="it.receipt_id">{{ fmt(it.quantity) }}</span><a-input-number v-else v-model:value="it.quantity" size="small" :min="0" :precision="2" style="width: 76px" /></td>
                    <td class="n"><a-input-number v-model:value="it.price" size="small" :min="0" :step="0.01" :precision="2" style="width: 92px" /></td>
                    <td class="n">{{ fmt((it.quantity || 0) * (it.price || 0)) }}</td>
                    <td class="del"><a @click="removeItem(i)"><DeleteOutlined /></a></td>
                  </tr>
                  <tr v-if="!f.items.length"><td colspan="6" class="muted">Elija proveedor y tipo, y use "Agregar Item" o "Vincular" -- o suba el XML de la factura.</td></tr>
                </tbody>
              </table>
            </div>
            <div class="f-actions">
              <span class="muted small" v-if="xmlTotal">Total del XML: {{ f.currency === 'USD' ? '$' : 'S/' }} {{ fmt(xmlTotal) }}
                <b v-if="Math.abs(xmlTotal - net * 1.18) > 0.05" class="red"> · no cuadra con {{ fmt(net * 1.18) }}</b></span>
              <span v-else />
              <a-button type="primary" size="small" :loading="saving" @click="save"><SaveOutlined /> {{ mode === 'edit' ? 'Guardar cambios' : 'Guardar' }}</a-button>
            </div>
          </template>

          <!-- One invoice, as the earlier system shows it -->
          <template v-else-if="sel">
            <div class="doc-actions">
              <!-- The state by hand (2026-11-23): PENDIENTE <-> CANCELADA (pagada), ANULADA and back. -->
              <a-button v-if="sel.estado === 'PENDIENTE'" size="small" type="primary" ghost @click="openPay"><CheckOutlined /> MARCAR CANCELADA</a-button>
              <a-popconfirm v-if="sel.estado === 'CANCELADA'" :title="`¿Volver ${sel.Ref} a PENDIENTE? Se quita la fecha de pago.`" ok-text="Sí" cancel-text="No" @confirm="setStatus('PENDIENTE')">
                <a-button size="small"><ClockCircleOutlined /> VOLVER A PENDIENTE</a-button>
              </a-popconfirm>
              <a-popconfirm v-if="sel.estado !== 'ANULADA'" :title="`¿Anular la factura ${sel.Ref}?`" ok-text="Anular" cancel-text="No" :ok-button-props="{ danger: true }" @confirm="setStatus('ANULADA')">
                <a-button size="small" danger>ANULAR</a-button>
              </a-popconfirm>
              <a-popconfirm v-if="sel.estado === 'ANULADA'" :title="`¿Revertir la anulación de ${sel.Ref}? Vuelve a PENDIENTE.`" ok-text="Revertir" cancel-text="No" @confirm="setStatus('PENDIENTE')">
                <a-button size="small"><UndoOutlined /> Revertir anulación</a-button>
              </a-popconfirm>
              <a-button v-if="sel.estado !== 'ANULADA'" size="small" @click="editPurchase"><EditOutlined /> Editar</a-button>
              <a-popconfirm title="¿Borrar esta factura? Sus guías vuelven a quedar por facturar." ok-text="Borrar" cancel-text="No" :ok-button-props="{ danger: true }" @confirm="removePurchase">
                <a-button size="small"><DeleteOutlined /> Borrar</a-button>
              </a-popconfirm>
              <a-button size="small" @click="newPurchase"><PlusOutlined /> Nueva factura</a-button>
            </div>
            <div class="fx">
              <div class="fx-box"><div class="fx-t">{{ /^E/i.test(sel.Ref) ? 'FACTURA ELECTRONICA' : 'FACTURA' }}</div><div class="fx-n">Nº {{ sel.Ref }}</div></div>
              <div class="fx-st" :class="sel.estado.toLowerCase()">{{ sel.estado }}</div>
              <dl class="fx-l">
                <dt>Fecha de Emisión</dt><dd>{{ d(sel.date) }}</dd>
                <dt>Fecha de Vencimiento</dt><dd>{{ d(sel.due_date) }}</dd>
                <dt>Señor(es)</dt><dd>{{ sel.provider }}</dd>
                <dt>RUC</dt><dd>{{ sel.tax_number }}</dd>
                <dt>Tipo</dt><dd>{{ (sel.kind || '').toUpperCase() }}</dd>
                <dt>Tipo de Moneda</dt><dd>{{ sel.currency === 'USD' ? 'DOLARES' : 'SOLES' }}<span v-if="sel.currency === 'USD' && sel.exchange_rate"> · T.C. {{ sel.exchange_rate }}</span></dd>
                <dt>Guías Relacionadas</dt><dd>{{ sel.guides.length ? sel.guides.join(', ') : 'NO APLICA' }}</dd>
                <dt v-if="sel.pay_date">Fecha de Pago</dt><dd v-if="sel.pay_date">{{ d(sel.pay_date) }}</dd>
              </dl>
              <table class="grid">
                <thead><tr><th class="n">Cantidad</th><th>Descripción</th><th class="n">Valor Unitario</th><th class="n">Sub Total</th></tr></thead>
                <tbody><tr v-for="(l, i) in sel.lines" :key="i"><td class="n">{{ fmt(l.quantity) }}</td><td>{{ l.name }}</td><td class="n">{{ fmt(l.cost) }}</td><td class="n">{{ fmt(l.total) }}</td></tr></tbody>
                <tfoot>
                  <tr><td colspan="2"><em v-if="sel.kind === 'adelanto'" class="adv">OBS: Esta Factura es un adelanto</em></td><td class="n">Neto</td><td class="n">{{ fmt(sel.GrandTotal - sel.TaxNet) }}</td></tr>
                  <tr><td colspan="2" /><td class="n">IGV</td><td class="n">{{ fmt(sel.TaxNet) }}</td></tr>
                  <tr class="tot"><td colspan="2" /><td class="n">Total {{ sel.currency === 'USD' ? '$' : 'S/' }}</td><td class="n">{{ fmt(sel.GrandTotal) }}</td></tr>
                </tfoot>
              </table>
            </div>
          </template>
        </a-card>
      </DetailPanel>
    </a-row>

    <!-- MARCAR CANCELADA: the payment date. -->
    <a-modal v-model:open="pay.open" title="Marcar cancelada (pagada)" ok-text="Guardar" cancel-text="Cerrar" width="340px" :confirm-loading="pay.saving" @ok="savePay">
      <p class="muted">{{ sel?.Ref }} · {{ sel?.provider }} · {{ sel?.currency === 'USD' ? '$' : 'S/' }} {{ fmt(sel?.GrandTotal) }}</p>
      <div class="lbl">Fecha de pago</div>
      <a-date-picker v-model:value="pay.date" value-format="YYYY-MM-DD" style="width: 100%" />
    </a-modal>

    <!-- VINCULAR: the supplier's receipts with a guide not invoiced yet. -->
    <a-modal v-model:open="link.open" title="Guías pendientes por facturar" ok-text="Facturar Productos" cancel-text="Cancelar" width="min(760px, 96vw)" @ok="applyLink">
      <a-spin :spinning="link.loading">
        <p v-if="!link.rows.length && !link.loading" class="muted">No hay guías pendientes por facturar.</p>
        <div v-for="r in link.rows" :key="r.id" class="lk">
          <a-checkbox v-model:checked="r.checked"><b>G.R. {{ r.guide }}</b> · {{ d(r.date) }} · {{ r.motive }}</a-checkbox>
          <div class="lk-items">{{ r.items.map(i => `${fmt(i.quantity)} ${i.name}`).join(' · ') || 'Sin detalle' }}</div>
        </div>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Distribución B2B -> Facturas de Proveedores (2026-11-21, reworked 2026-11-23 like Facturas): the list on the left; on the
 * right a new invoice -- typed ("Agregar Item", "Vincular" receipts) or from the supplier's SUNAT XML -- or the invoice
 * clicked, with Editar and its state buttons (Marcar cancelada / Volver a pendiente / Anular / Revertir anulación).
 * A new one is PENDIENTE, due in 30 days.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, DeleteOutlined, SaveOutlined, LinkOutlined, CheckOutlined, ClockCircleOutlined, UndoOutlined, EditOutlined, CloudUploadOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import DetailPanel from '../../components/DetailPanel.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useBarTitle } from '../../composables/useBarTitle';
import { useIsPhone } from '../../lib/useIsPhone';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const isPhone = useIsPhone();
const phoneOpen = ref(false);
const today = dayjs();
// Two decimals everywhere (user, 2026-11-23).
const fmt = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const d = v => (v ? dayjs(String(v).slice(0, 10)).format('DD/MM/YYYY') : '');
const KINDS = [{ value: 'bienes', label: 'BIENES' }, { value: 'servicios', label: 'SERVICIOS' }, { value: 'adelanto', label: 'ADELANTO' }];
const DUE_DAYS = 30;

const filters = reactive({ status: 'all' });
const statusOptions = [{ value: 'all', label: 'Todas' }, { value: 'PENDIENTE', label: 'Pendientes' }, { value: 'CANCELADA', label: 'Canceladas' }, { value: 'ANULADA', label: 'Anuladas' }];
const crud = useCrudTable('b2b/purchases', { rowsKey: 'purchases', sortField: 'date', limit: 33,
  params: () => ({ status: filters.status === 'all' ? undefined : filters.status }) });
const columns = [
  { title: 'Fecha', key: 'date', dataIndex: 'date', sorter: true, width: 86 },
  { title: 'Proveedor', key: 'provider', dataIndex: 'provider', sorter: true, ellipsis: { showTitle: true } },
  { title: 'Factura', key: 'Ref', dataIndex: 'Ref', sorter: true, width: 118, ellipsis: { showTitle: true } },
  { title: 'Total', key: 'GrandTotal', dataIndex: 'GrandTotal', sorter: true, width: 104, align: 'right' },
  { title: 'Estado', key: 'estado', dataIndex: 'estado', width: 88 },
];

const opts = ref({});
const providerOptions = computed(() => sortByLabel((opts.value.providers || []).map(p => ({ value: p.id, label: p.name }))));
// "Agregar Item": services for a SERVICIOS invoice, goods (inputs, packing...) otherwise -- as there.
const itemOptions = computed(() => sortByLabel((opts.value.products || [])
  .filter(p => (f.kind === 'servicios') === (p.type === 'is_service'))
  .map(p => ({ value: p.id, label: `${p.code} - ${p.name}` }))));

// ---- one invoice
const mode = ref('new');
const sel = ref(null);
async function openPurchase(id, show = true) { sel.value = await http.get(`b2b/purchases/${id}`); mode.value = 'view'; if (show) phoneOpen.value = true; }
const pay = reactive({ open: false, date: dayjs().format('YYYY-MM-DD'), saving: false });
function openPay() { Object.assign(pay, { open: true, date: dayjs().format('YYYY-MM-DD') }); }
async function savePay() {
  if (!pay.date) return message.warning('Indique la fecha de pago');
  pay.saving = true;
  try { await setStatus('CANCELADA', pay.date); pay.open = false; } finally { pay.saving = false; }
}
async function setStatus(status, fPago = null) {
  try {
    await http.post(`b2b/purchases/${sel.value.id}/status`, { status, f_pago: fPago });
    message.success({ PENDIENTE: 'Factura PENDIENTE', CANCELADA: 'Factura CANCELADA (pagada)', ANULADA: 'Factura ANULADA' }[status]);
    await openPurchase(sel.value.id);
    crud.fetchRows();
  } catch (e) { message.error(e?.data?.message || 'No se pudo cambiar el estado'); }
}
async function removePurchase() {
  try {
    await http.delete(`b2b/purchases/${sel.value.id}`);
    message.success('Factura eliminada');
    newPurchase();
    crud.fetchRows();
  } catch (e) { message.error(e?.data?.message || 'No se pudo borrar'); }
}

// ---- the form
const blank = () => ({ provider_id: undefined, kind: undefined, currency: 'PEN', tc: null, series: '', number: null,
  f_emision: dayjs().format('YYYY-MM-DD'), f_venc: dayjs().add(DUE_DAYS, 'day').format('YYYY-MM-DD'), f_pago: null, items: [], receipt_ids: [] });
const f = reactive(blank());
const net = computed(() => f.items.reduce((s, i) => s + (Number(i.quantity) || 0) * (Number(i.price) || 0), 0));
const saving = ref(false);
const next = reactive({ series: null, last: null, next: null });
const linkedGuides = ref('');
const editId = ref(null);
const editRef = ref('');
const editState = ref('PENDIENTE');
const xmlWarnings = ref([]);
const xmlTotal = ref(0);
function newPurchase() {
  Object.assign(f, blank()); Object.assign(next, { series: null, last: null, next: null });
  linkedGuides.value = ''; xmlWarnings.value = []; xmlTotal.value = 0; editId.value = null; mode.value = 'new';
}
// Due in 30 days from the issue date while it is a new invoice (the supplier "lo programa a 30 días").
function onEmision(v) { if (mode.value === 'new' && v) f.f_venc = dayjs(v).add(DUE_DAYS, 'day').format('YYYY-MM-DD'); }
function editPurchase() {
  const x = sel.value;
  const [series, num] = String(x.Ref || '').split('-');
  const inReceipt = new Set((x.receipt_items || []).map(i => i.product_id));
  Object.assign(f, {
    provider_id: x.provider_id, kind: x.kind || 'bienes', currency: x.currency || 'PEN', tc: x.exchange_rate ? Number(x.exchange_rate) : null,
    series, number: Number(num) || null, f_emision: String(x.date).slice(0, 10), f_venc: x.due_date ? String(x.due_date).slice(0, 10) : null,
    f_pago: x.pay_date ? String(x.pay_date).slice(0, 10) : null, receipt_ids: [...(x.receipt_ids || [])],
    items: (x.lines || []).map(l => ({ product_id: l.product_id, name: l.name, quantity: Number(l.quantity), price: Number(l.cost),
      ...(inReceipt.has(l.product_id) && x.receipt_ids?.length ? { receipt_id: x.receipt_ids[0], guide: (x.guides || []).join(', ') } : {}) })),
  });
  linkedGuides.value = (x.guides || []).join(', ');
  editId.value = x.id; editRef.value = x.Ref; editState.value = x.estado; xmlWarnings.value = []; xmlTotal.value = 0;
  mode.value = 'edit';
}
// The number suggested: the next after this supplier's last invoice (in the series typed, or its last one).
async function loadNext() {
  if (!f.provider_id || mode.value !== 'new') return;
  try {
    Object.assign(next, await http.get('b2b/purchases/next-number', { provider_id: f.provider_id, series: f.series || undefined }));
    if (next.next && !f.number) { f.series = f.series || next.series; f.number = next.next; }
  } catch (e) { /* only a help */ }
}
function onProvider() { f.items = f.items.filter(i => !i.receipt_id); f.receipt_ids = []; linkedGuides.value = ''; if (mode.value === 'new') { f.series = ''; f.number = null; } loadNext(); }
function onKind() { f.items = []; f.receipt_ids = []; linkedGuides.value = ''; }
function addItem() { f.items.push({ product_id: undefined, quantity: 1, price: null }); }
function removeItem(i) {
  const it = f.items[i];
  f.items.splice(i, 1);
  if (it.receipt_id && !f.items.some(x => x.receipt_id === it.receipt_id)) f.receipt_ids = f.receipt_ids.filter(id => id !== it.receipt_id);
}

// ---- XML (2026-11-23)
const xmlInput = ref(null);
const readingXml = ref(false);
async function importXml(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  readingXml.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const x = await http.postForm('b2b/purchases/xml', fd);
    newPurchase();
    Object.assign(f, { provider_id: x.provider_id || undefined, kind: x.kind, currency: x.currency, series: x.series || '', number: x.number,
      f_emision: x.f_emision || f.f_emision, f_venc: x.f_venc || dayjs(x.f_emision || undefined).add(DUE_DAYS, 'day').format('YYYY-MM-DD'),
      receipt_ids: x.receipt_ids || [],
      items: (x.items || []).map(i => ({ product_id: i.product_id || undefined, quantity: Number(i.quantity), price: Number(i.price), xml: i.desc })) });
    linkedGuides.value = x.receipt_ids?.length ? (x.guides || []).join(', ') : '';
    xmlWarnings.value = x.warnings || [];
    xmlTotal.value = Number(x.total) || 0;
    if (x.currency === 'USD') message.info('Factura en dólares: escriba el tipo de cambio (T.C.)');
    message.success(`XML leído: ${x.series}-${x.number} · ${x.provider_name || ''}`);
  } catch (err) {
    message.error(err?.data?.message || 'No se pudo leer el XML');
  } finally { readingXml.value = false; }
}

const link = reactive({ open: false, loading: false, rows: [] });
async function openLink() {
  Object.assign(link, { open: true, loading: true, rows: [] });
  try { link.rows = (await http.get('b2b/purchases/pending-guides', { provider_id: f.provider_id })).map(r => ({ ...r, checked: f.receipt_ids.includes(r.id) })); }
  finally { link.loading = false; }
}
function applyLink() {
  const chosen = link.rows.filter(r => r.checked);
  f.items = f.items.filter(i => !i.receipt_id);
  chosen.forEach(r => r.items.forEach(i => f.items.push({ receipt_id: r.id, guide: r.guide, product_id: i.product_id, name: i.name, quantity: Number(i.quantity), price: i.price ? Number(i.price) : null })));
  f.receipt_ids = chosen.map(r => r.id);
  linkedGuides.value = chosen.map(r => r.guide).join(', ');
  link.open = false;
}

// The earlier screen's checks.
async function save() {
  if (!f.provider_id) return message.error('Debe especificar el "Proveedor"');
  if (!f.kind) return message.error('Debe especificar el "Tipo"');
  if (f.currency === 'USD' && !(Number(f.tc) > 0)) return message.error('Debe especificar el "T.C."');
  if (!f.series || !f.number) return message.error('Debe especificar la "Factura" (serie y número)');
  if (!f.f_emision || !f.f_venc) return message.error('Debe especificar la "F. Emisión" y la "F. Venc."');
  if (f.items.some(i => i.xml && !i.product_id)) return message.error('Elija el producto de las líneas del XML marcadas en rojo');
  const items = f.items.filter(i => i.product_id);
  if (!items.length) return message.error('Agregue al menos un item');
  for (const [i, it] of items.entries()) {
    if (!(Number(it.quantity) > 0)) return message.error(`Debe especificar la "Cant" de la linea ${i + 1}`);
    if (it.price === null || it.price === undefined || it.price === '') return message.error(`Debe especificar el "P.Unit." de la linea ${i + 1}`);
  }
  saving.value = true;
  try {
    const body = { ...f, f_pago: mode.value === 'edit' ? f.f_pago : null, items: items.map(i => ({ product_id: i.product_id, quantity: i.quantity, price: i.price })) };
    const r = mode.value === 'edit' ? await http.put(`b2b/purchases/${editId.value}`, body) : await http.post('b2b/purchases', body);
    message.success(`Factura ${r.ref} guardada`);
    await crud.fetchRows();
    openPurchase(r.id);
  } catch (e) { message.error(e?.data?.message || 'No se pudo guardar la factura'); } finally { saving.value = false; }
}

onMounted(async () => {
  opts.value = await http.get('b2b/options');
  newPurchase();
  await crud.fetchRows();
  // The latest invoice shown with its buttons (user, 2026-11-23); on a phone it opens when a row is tapped.
  if (crud.rows.value?.length) await openPurchase(crud.rows.value[0].id, false);
});
useBarTitle('Facturas de Proveedores');
</script>

<style scoped>
.side { position: sticky; top: 12px; }
.panel { border-radius: 2px; min-height: calc(100vh - 170px); }
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 20px; font-weight: 400; }
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; padding-left: 6px !important; padding-right: 6px !important; }
.num { font-variant-numeric: tabular-nums; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
.money { display: flex; justify-content: space-between; gap: 6px; }
.st { font-weight: 600; font-size: 12px; }
.st.cancelada { color: #15803d; }
.st.pendiente { color: #d46b08; }
.st.anulada { color: #cf1322; text-decoration: line-through; }
.xml-warn { margin-bottom: 8px; }
.f-row { display: grid; gap: 8px; margin-bottom: 8px; }
.f-row.r1 { grid-template-columns: 2fr 1fr 1fr 0.8fr; }
.f-row.r2 { grid-template-columns: 1.7fr 1fr 1fr 1fr; }
.f-row.r3 { grid-template-columns: repeat(3, 1fr); }
.f-row label { display: flex; flex-direction: column; font-size: 11px; gap: 2px; min-width: 0; }
.f-row label :deep(.ant-picker), .f-row label :deep(.ant-select) { width: 100%; }
.doc-in { display: flex; align-items: center; gap: 4px; }
.doc-in .ser { width: 64px; text-align: right; }
.doc-in .cor { flex: 1 1 auto; }
.hint { font-size: 11px; opacity: 0.75; }
.ro { display: block; border-bottom: 1px solid rgba(128, 128, 128, 0.3); padding: 2px 4px; font-size: 13.5px; text-align: right; min-height: 24px; }
.ro.tot { font-weight: 700; }
.ro.st { text-align: left; }
.f-btns { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.items { max-height: calc(100vh - 480px); min-height: 120px; overflow-y: auto; }
.grid { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.grid th { text-align: left; font-weight: 600; padding: 4px 6px; background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.1); position: sticky; top: 0; z-index: 1; }
.grid td { padding: 3px 6px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); vertical-align: middle; }
.grid tbody tr:nth-child(even) td { background: rgba(128, 128, 128, 0.045); }
.grid .n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.grid .nr { width: 30px; }
.grid .del { width: 26px; text-align: center; }
.grid td.desc { width: 55%; }
.grid tfoot td { border: 0; padding: 2px 6px; }
.grid tfoot .tot td { font-weight: 700; }
.ref { font-size: 11px; opacity: 0.6; }
.f-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; gap: 8px; }
.muted { opacity: 0.6; }
.small { font-size: 12px; }
.red { color: #cf1322; }
.lbl { font-size: 12px; opacity: 0.75; margin-bottom: 2px; }
.lk { padding: 6px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.15); }
.lk-items { font-size: 12px; opacity: 0.75; margin-left: 24px; }
.doc-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
/* The invoice as the earlier system shows it */
.fx { position: relative; }
.fx-box { float: right; border: 2px solid #222; padding: 8px 18px; text-align: center; font-weight: 700; }
.fx-st { clear: right; float: right; margin-top: 6px; font-weight: 700; }
.fx-st.cancelada { color: #15803d; }
.fx-st.pendiente { color: #d46b08; }
.fx-st.anulada { color: #cf1322; }
.fx-l { display: grid; grid-template-columns: 150px 1fr; gap: 3px 10px; font-size: 13px; margin: 0 0 12px; }
.fx-l dt::after { content: ' :'; }
.fx-l dd { margin: 0; }
.adv { color: #cf1322; }
@media (max-width: 767px) { .f-row.r1, .f-row.r2, .f-row.r3 { grid-template-columns: 1fr 1fr; } }
</style>
