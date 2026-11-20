<template>
  <div class="page b2b hx">
    <!-- Hordex's "Control de Pagos" as it was: the invoices, the payment line, the invoice's payments -- three panels. -->
    <h1 class="ptitle">Control de Pagos</h1>
    <section class="panel">
      <div class="head">
        <h2>{{ showPaid ? 'Facturas canceladas' : 'Facturas pendientes de pago' }}</h2>
        <a-input v-model:value="search" size="small" allow-clear placeholder="Buscar" style="width: 200px" @change="load" />
        <button class="btn" @click="showPaid = !showPaid; load()">{{ showPaid ? 'Ver Pendientes' : 'Ver Canceladas' }}</button>
      </div>
      <div class="scroll">
        <table class="grid">
          <thead><tr><th>Factura</th><th>O.C.</th><th>Emisión</th><th>Razón Social</th><th class="n">Total</th><th class="n">Abonado</th><th class="n">Saldo</th><th>Estado</th></tr></thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id" :class="{ sel: inv?.id === r.id }" @click="pick(r.id)">
              <td>{{ r.Ref }}</td><td>{{ r.purchase_order || '' }}</td><td>{{ d(r.date) }}</td><td>{{ r.client_name }}</td>
              <td class="n">S/ {{ fmt(r.GrandTotal) }}</td><td class="n">S/ {{ fmt(r.paid_amount) }}</td><td class="n">S/ {{ fmt(r.balance) }}</td>
              <td><ClockCircleOutlined v-if="r.payment_statut !== 'paid'" :class="{ late: r.overdue }" /><CheckOutlined v-else class="ok" /></td>
            </tr>
            <tr v-if="!rows.length"><td colspan="8" class="muted">Nada que mostrar</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="head"><h2>Registro de pago</h2></div>
      <div class="line">
        <label>Factura {{ companyWord }}
          <a-select v-model:value="form.sale_id" size="small" show-search option-filter-prop="label" :options="invoiceOptions" style="width: 140px" @change="pick" />
        </label>
        <label>Documento <a-input v-model:value="form.document" size="small" placeholder="E000-000000" style="width: 130px" /></label>
        <label>Fecha del doc. <a-date-picker v-model:value="form.document_date" size="small" value-format="YYYY-MM-DD" style="width: 130px" /></label>
        <label>Concepto / Cta. Bancaria
          <a-select v-model:value="form.concept_id" size="small" :options="conceptOptions" :placeholder="inv ? '' : 'SELECCIONE UNA FACTURA'" :disabled="!inv" style="width: 220px" @change="onConcept" />
        </label>
        <label>Fecha del abono <a-date-picker v-model:value="form.date" size="small" value-format="YYYY-MM-DD" style="width: 130px" /></label>
        <label>% <a-input-number v-model:value="form.percent" size="small" :min="0" :max="100" style="width: 70px" @change="onPercent" /></label>
        <label>Estimado <span class="est">{{ fmt(estimated) }}</span></label>
        <label>Monto <a-input-number v-model:value="form.expected" size="small" :min="0" :precision="2" style="width: 110px" @change="form.amount = form.expected" /></label>
        <label>Abonado <a-input-number v-model:value="form.amount" size="small" :min="0" :precision="2" style="width: 110px" /></label>
        <button class="btn icon" :disabled="!inv || !form.amount || saving" title="Guardar" @click="save"><SaveOutlined /></button>
        <button class="btn icon" title="Limpiar" @click="clear"><DeleteOutlined /></button>
      </div>
    </section>

    <section class="panel">
      <div class="head"><h2>Detalle de pagos</h2></div>
      <p v-if="!inv" class="muted">Haga click en una factura de la lista de arriba</p>
      <table v-else class="grid">
        <thead><tr><th>Fecha abono</th><th>Concepto</th><th>Documento</th><th>Fecha doc.</th><th class="n">%</th><th class="n">Monto</th><th class="n">Abonado</th><th /></tr></thead>
        <tbody>
          <tr v-for="p in inv.payments" :key="p.id">
            <td>{{ d(p.date) }}</td><td>{{ p.concept || p.notes }}</td><td>{{ p.document || '' }}</td><td>{{ d(p.document_date) }}</td>
            <td class="n">{{ p.percent !== null ? fmt(p.percent) : '' }}</td><td class="n">{{ fmt(p.expected ?? p.amount) }}</td><td class="n">{{ fmt(p.amount) }}</td>
            <td><a-popconfirm title="¿Quitar este pago?" @confirm="remove(p.id)"><DeleteOutlined class="del" /></a-popconfirm></td>
          </tr>
          <tr class="tot"><td colspan="6" class="n">Total {{ fmt(inv.GrandTotal) }} · Abonado</td><td class="n">{{ fmt(inv.paid_amount) }}</td><td /></tr>
          <tr class="tot"><td colspan="6" class="n">Saldo</td><td class="n" :class="{ late: balance > 0.009 }">{{ fmt(balance) }}</td><td /></tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script setup>
/**
 * Distribución B2B -> Control de pagos (2026-11-20): Hordex's screen copied as it was, texts included (user: "te comiste el
 * filtro" -- its one-line payment register: Factura, Documento, Fecha del doc., Concepto / Cta. Bancaria, Fecha del abono,
 * %, Estimado, Monto, Abonado). Concepts = SUNAT's detracción, the chains' deductions and the bank accounts (b2b_concepts).
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { ClockCircleOutlined, CheckOutlined, SaveOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const auth = useAuthStore();
const companyWord = computed(() => { const w = String(auth.companyName || '').split(' ')[0].toLowerCase(); return w.charAt(0).toUpperCase() + w.slice(1); });
const fmt = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const d = v => (v ? dayjs(String(v).slice(0, 10)).format('DD/MM/YYYY') : '');
const rows = ref([]);
const showPaid = ref(false);
const search = ref('');
const inv = ref(null);
const saving = ref(false);
const blank = () => ({ sale_id: inv.value?.id, document: '', document_date: undefined, concept_id: undefined, date: dayjs().format('YYYY-MM-DD'), percent: 0, expected: 0, amount: 0 });
const form = reactive(blank());
const balance = computed(() => Math.round(((inv.value?.GrandTotal || 0) - (inv.value?.paid_amount || 0)) * 100) / 100);
const estimated = computed(() => Math.round((inv.value?.GrandTotal || 0) * (Number(form.percent) || 0)) / 100);
const invoiceOptions = computed(() => rows.value.map(r => ({ value: r.id, label: r.Ref })));
const conceptOptions = computed(() => (inv.value?.concepts || []).map(c => ({ value: c.id, label: c.name })));

async function load() {
  const r = await http.get('b2b/invoices', { status: showPaid.value ? 'paid' : 'pending', limit: 500, SortField: 'date', SortType: 'desc', search: search.value });
  rows.value = r.invoices || [];
}
async function pick(id) {
  inv.value = await http.get(`b2b/invoices/${id}`);
  Object.assign(form, blank(), { sale_id: id });
}
function onConcept(id) {
  const c = (inv.value?.concepts || []).find(x => x.id === id);
  form.percent = Number(c?.percent) || 0;
  onPercent();
  if (c?.kind === 'deposit') { form.expected = balance.value; form.amount = balance.value; }
}
function onPercent() { if (form.percent) { form.expected = estimated.value; form.amount = estimated.value; } }
function clear() { Object.assign(form, blank()); }
async function save() {
  saving.value = true;
  try {
    await http.post(`b2b/invoices/${inv.value.id}/payments`, { lines: [{ concept_id: form.concept_id, amount: form.amount, expected: form.expected, date: form.date,
      document: form.document || null, document_date: form.document_date || null, percent: form.percent || null }] });
    message.success('Pago registrado');
    await pick(inv.value.id);
    load();
  } catch (e) { message.error(e?.data?.message || 'Error'); } finally { saving.value = false; }
}
async function remove(id) { try { await http.delete(`b2b/payments/${id}`); await pick(inv.value.id); load(); } catch (e) { message.error(e?.data?.message || 'Error'); } }
onMounted(load);
</script>

<style scoped>
.hx { padding-top: 0; }
.ptitle { font-size: 24px; font-weight: 400; margin: 0 0 10px; }
.panel { background: #fff; border: 1px solid #ddd; padding: 10px 12px 14px; margin-bottom: 16px; }
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.head h2 { flex: 1; margin: 0; font-size: 24px; font-weight: 400; }
.btn { background: #ff4500; color: #fff; border: 0; padding: 3px 10px; font-size: 16px; cursor: pointer; }
.btn.icon { padding: 3px 8px; font-size: 14px; }
.btn:disabled { opacity: 0.5; cursor: default; }
.scroll { max-height: 360px; overflow: auto; }
.grid { width: 100%; border-collapse: collapse; font-family: ui-monospace, Consolas, monospace; font-size: 11.5px; }
.grid th { background: #1f2124; color: #fff; text-align: left; padding: 3px 4px; font-weight: 600; position: sticky; top: 0; }
.grid td { padding: 2px 4px; }
.grid tbody tr:nth-child(odd) td { background: #eef0f2; }
.grid tbody tr:hover td { background: #dbe7ff; cursor: pointer; }
.grid tr.sel td { background: #ffe1d1 !important; }
.grid .n { text-align: right; font-variant-numeric: tabular-nums; }
.grid tr.tot td { background: #fff !important; font-weight: 700; }
.line { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 14px; }
.line label { display: flex; flex-direction: column; gap: 2px; font-size: 11.5px; }
.est { min-width: 70px; padding: 2px 0; text-align: right; opacity: 0.7; }
.late { color: #dc2626; }
.ok { color: #16a34a; }
.del { color: #dc2626; }
.muted { opacity: 0.6; font-size: 13px; }
</style>
