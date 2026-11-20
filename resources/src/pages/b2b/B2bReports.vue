<template>
  <div class="page b2b">
    <!-- Hordex's Reportes, as it was: five big coloured tiles; each opens its filters and downloads the Excel. -->
    <ul class="tiles">
      <li v-for="r in REPORTS" :key="r.key">
        <a class="tile" :style="{ background: r.bg, color: r.fg }" @click="open(r)">
          <span class="name">{{ r.title }}</span>
          <component :is="r.icon" class="ico" />
        </a>
      </li>
    </ul>

    <a-modal v-model:open="modal.open" :title="modal.r ? `Reporte de ${modal.r.title}` : ''" :ok-text="tr('Download Excel', 'Descargar Excel')"
      :cancel-text="tr('Cancel', 'Cancelar')" :confirm-loading="loading" @ok="run">
      <a-form layout="vertical">
        <a-form-item v-if="modal.r?.provider" :label="tr('Supplier', 'Proveedor')">
          <a-select v-model:value="f.proveedor_id" allow-clear show-search option-filter-prop="label" :placeholder="tr('All suppliers', 'Todos los Proveedores')" :options="providerOptions" />
        </a-form-item>
        <a-form-item v-if="modal.r?.product" :label="tr('Product', 'Producto')">
          <a-select v-model:value="f.producto_id" allow-clear show-search option-filter-prop="label" :placeholder="tr('All products', 'Todos los Productos')" :options="productOptions" />
        </a-form-item>
        <a-row v-if="modal.r?.dates" :gutter="10">
          <a-col :span="12"><a-form-item :label="tr('From', 'Desde')"><a-date-picker v-model:value="f.desde" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tr('To', 'Hasta')"><a-date-picker v-model:value="f.hasta" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <p v-if="!modal.r?.dates" class="muted">{{ tr('Current stock of every product.', 'El saldo actual de cada producto.') }}</p>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Distribución B2B -> Reportes (2026-11-20): Hordex's reports screen copied as it was (user: "deben ser iguales o tener
 * parecido") -- Compras, Ventas, Pagos, Cobros, Existencias as big tiles in its colours; each asks its filters in a small
 * window and downloads the Excel with the earlier system's columns (B2bController::report).
 */
import { reactive, ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { ShoppingCartOutlined, FileTextOutlined, BankOutlined, InboxOutlined, AppstoreOutlined } from '@ant-design/icons-vue';
import { sortByLabel } from '../../lib/filterOptions';
import { useTr } from '../../lib/useTr';
import http from '../../lib/http';

const tr = useTr();
// Hordex's Bootstrap colours: warning, primary, info, success, dark.
const REPORTS = [
  { key: 'compras', title: 'Compras', bg: '#ffc107', fg: '#000', icon: ShoppingCartOutlined, provider: true, product: true, dates: true },
  { key: 'ventas', title: 'Ventas', bg: '#0d6efd', fg: '#fff', icon: FileTextOutlined, product: true, dates: true },
  { key: 'pagos', title: 'Pagos', bg: '#0dcaf0', fg: '#000', icon: BankOutlined, dates: true },
  { key: 'cobros', title: 'Cobros', bg: '#198754', fg: '#fff', icon: InboxOutlined, dates: true },
  { key: 'existencias', title: 'Existencias', bg: '#212529', fg: '#fff', icon: AppstoreOutlined, dates: false },
];
const opts = ref({});
const providers = ref([]);
const productOptions = computed(() => sortByLabel((opts.value.products || []).map(p => ({ value: p.id, label: `${p.code} - ${p.name}` }))));
const providerOptions = computed(() => sortByLabel(providers.value.map(p => ({ value: p.id, label: p.name }))));
const modal = reactive({ open: false, r: null });
const f = reactive({ desde: dayjs().startOf('month').format('YYYY-MM-DD'), hasta: dayjs().format('YYYY-MM-DD'), producto_id: undefined, proveedor_id: undefined });
const loading = ref(false);
function open(r) { modal.r = r; modal.open = true; }
async function run() {
  loading.value = true;
  try {
    const q = new URLSearchParams(Object.entries({ desde: f.desde, hasta: f.hasta, producto_id: modal.r.product ? f.producto_id : '', proveedor_id: modal.r.provider ? f.proveedor_id : '' })
      .filter(([, v]) => v)).toString();
    await http.download(`b2b/reports/${modal.r.key}?${q}`, `${modal.r.key}.xlsx`);
    modal.open = false;
  } catch (e) { message.error(tr('Could not build the report.', 'No se pudo generar el reporte.')); } finally { loading.value = false; }
}
onMounted(async () => {
  opts.value = await http.get('b2b/options');
  providers.value = opts.value.providers || [];
});
</script>

<style scoped>
.tiles { list-style: none; margin: 8px 0 0; padding: 0 0 0 46px; display: grid; gap: 1rem; grid-template-columns: repeat(3, 1fr); }
.tile { aspect-ratio: 2 / 1; display: block; position: relative; width: 100%; cursor: pointer; overflow: hidden; }
.tile .name { position: absolute; left: 1rem; top: 1rem; font-size: 3.5rem; font-weight: 600; line-height: 1; opacity: 0.75; }
.tile .ico { position: absolute; right: 0.6rem; bottom: 0.5rem; font-size: 8rem; opacity: 0.25; }
.tile:hover { filter: brightness(1.05); }
.muted { opacity: 0.6; }
@media (max-width: 900px) { .tiles { grid-template-columns: 1fr; padding-left: 0; } }
</style>
