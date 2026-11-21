<template>
  <div class="page b2b">
    <!-- The title is in the top bar (useBarTitle, 2026-11-21: "el título arriba para ganar espacio visual"). -->
    <a-spin :spinning="loading">
      <a-tabs v-model:activeKey="tab" class="tabs-strong">
        <a-tab-pane key="settings"><template #tab><SettingOutlined />{{ tr('Series', 'Series') }}</template>
          <a-card size="small" style="max-width: 560px">
            <a-form layout="vertical">
              <a-row :gutter="10">
                <a-col :span="8"><a-form-item :label="tr('Invoice series', 'Serie de facturas')"><a-input v-model:value="settings.invoice_series" /></a-form-item></a-col>
                <a-col :span="8"><a-form-item :label="tr('Guide series', 'Serie de guías')"><a-input v-model:value="settings.guide_series" /></a-form-item></a-col>
                <a-col :span="8"><a-form-item label="IGV %"><a-input-number v-model:value="settings.igv" :min="0" :max="50" style="width: 100%" /></a-form-item></a-col>
              </a-row>
              <a-form-item :label="tr('Warehouse the guides dispatch from', 'Almacén de despacho de las guías')">
                <a-select v-model:value="settings.warehouse_id" allow-clear :options="warehouseOptions" />
              </a-form-item>
              <p class="muted">{{ tr('Next numbers', 'Siguientes números') }}: {{ nextText }}</p>
              <a-button type="primary" @click="saveSettings">{{ tr('Save', 'Guardar') }}</a-button>
            </a-form>
          </a-card>
        </a-tab-pane>
        <a-tab-pane v-for="t in TYPES" :key="t.key"><template #tab><component :is="t.icon" />{{ tr(...t.title) }}</template>
          <div class="bar">
            <a-button type="primary" size="small" @click="edit(t)"><PlusOutlined /> {{ tr('Add', 'Agregar') }}</a-button>
            <span class="muted">{{ tr(...t.help) }}</span>
          </div>
          <table class="lines">
            <thead><tr><th v-for="f in t.fields" :key="f.k">{{ tr(...f.l) }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="row in data[t.key] || []" :key="row.id" :class="{ off: row.active === 0 || row.active === false }">
                <td v-for="f in t.fields" :key="f.k">{{ show(f, row) }}</td>
                <td class="act">
                  <a-button v-if="t.key !== 'reasons' || row.company_id" type="text" size="small" @click="edit(t, row)"><EditOutlined /></a-button>
                  <a-popconfirm v-if="t.key !== 'reasons' || row.company_id" :title="tr('Remove / turn off?', '¿Quitar / desactivar?')" @confirm="remove(t, row)">
                    <a-button type="text" size="small" danger><DeleteOutlined /></a-button>
                  </a-popconfirm>
                </td>
              </tr>
            </tbody>
          </table>
        </a-tab-pane>
      </a-tabs>
    </a-spin>

    <a-modal v-model:open="modal.open" :title="modal.row?.id ? tr('Edit', 'Editar') : tr('Add', 'Agregar')" :ok-text="tr('Save', 'Guardar')" @ok="saveRow">
      <a-form layout="vertical">
        <a-form-item v-for="f in modal.type?.fields || []" :key="f.k" :label="tr(...f.l)">
          <a-select v-if="f.t === 'client'" v-model:value="modal.row[f.k]" allow-clear show-search option-filter-prop="label" :options="clientOptions" />
          <a-select v-else-if="f.t === 'select'" v-model:value="modal.row[f.k]" :options="f.o.map(o => ({ value: o[0], label: tr(o[1], o[2]) }))" />
          <a-input-number v-else-if="f.t === 'num'" v-model:value="modal.row[f.k]" style="width: 100%" />
          <a-switch v-else-if="f.t === 'bool'" v-model:checked="modal.row[f.k]" />
          <a-input v-else v-model:value="modal.row[f.k]" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** Distribución B2B -> Configuración (2026-11-20): series, carriers, the clients' delivery points, payment terms, collection concepts, reasons of transfer. */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, SettingOutlined, CarOutlined, EnvironmentOutlined, CalendarOutlined, PercentageOutlined, SwapOutlined } from '@ant-design/icons-vue';
import { sortByLabel } from '../../lib/filterOptions';
import { useTr } from '../../lib/useTr';
import http from '../../lib/http';
import { useBarTitle } from '../../composables/useBarTitle';

const tr = useTr();
const KINDS = [['deposit', 'Bank deposit', 'Abono bancario'], ['detraction', 'Detracción (SUNAT)', 'Detracción (SUNAT)'], ['rebate', 'Rebate', 'Rebate'], ['deduction', 'Commercial deduction', 'Deducción comercial']];
const TYPES = [
  { key: 'carriers', icon: CarOutlined, title: ['Carriers', 'Transportistas'], help: ['Who carries the goods: the plate and license fill the guide.', 'Quién lleva la mercadería: su placa y licencia llenan la guía.'],
    fields: [{ k: 'name', l: ['Name', 'Nombre'] }, { k: 'ruc', l: ['RUC', 'RUC'] }, { k: 'driver_name', l: ['Driver', 'Conductor'] }, { k: 'license', l: ['License', 'Licencia'] },
      { k: 'plate', l: ['Plate', 'Placa'] }, { k: 'brand', l: ['Brand', 'Marca'] }, { k: 'active', l: ['Active', 'Activo'], t: 'bool' }] },
  { key: 'addresses', icon: EnvironmentOutlined, title: ['Delivery points', 'Puntos de llegada'], help: ['Each chain\'s stores / warehouses where the guides deliver.', 'Las tiendas / almacenes de cada cadena donde entregan las guías.'],
    fields: [{ k: 'client_id', l: ['Client', 'Cliente'], t: 'client' }, { k: 'alias', l: ['Alias', 'Alias'] }, { k: 'address', l: ['Address', 'Dirección'] },
      { k: 'district', l: ['District', 'Distrito'] }, { k: 'ubigeo', l: ['Ubigeo', 'Ubigeo'] }, { k: 'sunat_annex', l: ['SUNAT annex', 'Anexo SUNAT'] }] },
  { key: 'terms', icon: CalendarOutlined, title: ['Payment terms', 'Condiciones de pago'], help: ['The invoice\'s due date = its date + the days.', 'El vencimiento de la factura = su fecha + los días.'],
    fields: [{ k: 'name', l: ['Name', 'Nombre'] }, { k: 'code', l: ['Code', 'Código'] }, { k: 'days', l: ['Days', 'Días'], t: 'num' }, { k: 'active', l: ['Active', 'Activo'], t: 'bool' }] },
  { key: 'concepts', icon: PercentageOutlined, title: ['Collection concepts', 'Conceptos de cobranza'], help: ['What settles an invoice besides the deposit: detracción and each chain\'s deductions (% of the invoice).', 'Lo que cancela una factura además del abono: la detracción y las deducciones de cada cadena (% de la factura).'],
    fields: [{ k: 'name', l: ['Concept', 'Concepto'] }, { k: 'client_id', l: ['Chain (empty = all)', 'Cadena (vacío = todas)'], t: 'client' },
      { k: 'kind', l: ['Kind', 'Tipo'], t: 'select', o: KINDS }, { k: 'percent', l: ['%', '%'], t: 'num' }, { k: 'active', l: ['Active', 'Activo'], t: 'bool' }] },
  { key: 'reasons', icon: SwapOutlined, title: ['Reasons of transfer', 'Motivos de traslado'], help: ['SUNAT catalogue 20 (fixed) + your own.', 'Catálogo 20 de SUNAT (fijo) + los propios.'],
    fields: [{ k: 'sunat_code', l: ['SUNAT code', 'Código SUNAT'] }, { k: 'name', l: ['Reason', 'Motivo'] },
      { k: 'direction', l: ['Direction', 'Sentido'], t: 'select', o: [['out', 'Dispatch', 'Despacho'], ['in', 'Reception', 'Recepción']] }, { k: 'active', l: ['Active', 'Activo'], t: 'bool' }] },
];

const tab = ref('settings');
const loading = ref(false);
const data = ref({});
const opts = ref({});
const settings = reactive({});
const clientOptions = computed(() => sortByLabel((opts.value.clients || []).map(c => ({ value: c.id, label: c.name }))));
const warehouseOptions = computed(() => sortByLabel((opts.value.warehouses || []).map(w => ({ value: w.id, label: w.name }))));
const nextText = computed(() => Object.entries(settings.next || {}).map(([s, n]) => `${s}-${String(n).padStart(6, '0')}`).join(' · ') || '—');
function show(f, row) {
  const v = row[f.k];
  if (f.t === 'bool') return v ? '✓' : '';
  if (f.t === 'client') return v ? (opts.value.clients || []).find(c => c.id === v)?.name || `#${v}` : '';
  if (f.t === 'select') { const o = f.o.find(x => x[0] === v); return o ? tr(o[1], o[2]) : v; }
  return v ?? '';
}
async function load() {
  loading.value = true;
  try {
    [data.value, opts.value] = await Promise.all([http.get('b2b/setup'), http.get('b2b/options')]);
    Object.assign(settings, data.value.settings || {});
  } finally { loading.value = false; }
}
async function saveSettings() {
  try { await http.put('b2b/settings', settings); message.success(tr('Saved', 'Guardado')); load(); } catch (e) { message.error(e?.data?.message || 'Error'); }
}
const modal = reactive({ open: false, type: null, row: {} });
function edit(type, row) {
  const empty = Object.fromEntries(type.fields.map(f => [f.k, f.t === 'bool' ? true : undefined]));
  modal.type = type;
  modal.row = row ? { ...empty, ...row, active: row.active === undefined ? undefined : !!row.active } : empty;
  modal.open = true;
}
async function saveRow() {
  const body = Object.fromEntries(modal.type.fields.map(f => [f.k, modal.row[f.k]]));
  try {
    if (modal.row.id) await http.put(`b2b/setup/${modal.type.key}/${modal.row.id}`, body);
    else await http.post(`b2b/setup/${modal.type.key}`, body);
    modal.open = false;
    message.success(tr('Saved', 'Guardado'));
    load();
  } catch (e) { message.error(e?.data?.message || 'Error'); }
}
async function remove(type, row) {
  try { await http.delete(`b2b/setup/${type.key}/${row.id}`); load(); } catch (e) { message.error(e?.data?.message || 'Error'); }
}
onMounted(load);

useBarTitle(() => tr('B2B setup', 'Configuración B2B'));
</script>

<style scoped>
.bar { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.muted { opacity: 0.6; font-size: 12.5px; }
.lines { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.lines th { background: #2b2f36; color: #fff; font-weight: 600; padding: 4px 8px; text-align: left; }
.lines td { padding: 3px 8px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); }
.lines tbody tr:nth-child(even) td { background: rgba(128, 128, 128, 0.05); }
.lines tbody tr:hover td { background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.08); }
.lines tr.off td { opacity: 0.45; }
.act { white-space: nowrap; width: 80px; }
</style>
