<template>
  <div class="page">
    <PageHeader :title="es ? 'Repuestos' : 'Spare parts'" :breadcrumb="[es ? 'Flota' : 'Fleet', es ? 'Repuestos' : 'Spare parts']">
      <template #actions>
        <a-button type="primary" @click="openForm(null)"><PlusOutlined /> {{ es ? 'Nuevo repuesto' : 'New part' }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: '12px' }">
      <div class="fp-filters">
        <a-input-search v-model:value="search" allow-clear style="width: 240px" :placeholder="es ? 'Nombre o código' : 'Name or code'" @search="load" />
        <a-checkbox v-model:checked="low" @change="load">{{ es ? 'Solo stock bajo' : 'Low stock only' }}</a-checkbox>
        <span class="fp-muted">{{ es ? 'Valor en almacén' : 'Stock value' }}: <b>{{ money(stockValue) }}</b></span>
      </div>
      <a-table :columns="columns" :data-source="rows" :loading="loading" row-key="id" size="middle" :pagination="false" :scroll="{ x: 'max-content' }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><b>{{ record.name }}</b><div class="fp-muted">{{ record.code }}<template v-if="record.provider"> · {{ record.provider }}</template></div></template>
          <template v-else-if="column.key === 'stock'">
            <a-tag :color="Number(record.stock) <= Number(record.min_stock) ? 'error' : 'green'">{{ Number(record.stock) }} {{ record.unit }}</a-tag>
            <div class="fp-muted">{{ es ? 'mínimo' : 'minimum' }} {{ Number(record.min_stock) }}</div>
          </template>
          <template v-else-if="column.key === 'cost'">{{ money(record.unit_cost) }}</template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="4" wrap>
              <a-button size="small" @click="openMove(record, 'in')">{{ es ? 'Comprar' : 'Buy' }}</a-button>
              <a-button size="small" @click="openMove(record, 'adjust')">{{ es ? 'Ajustar' : 'Adjust' }}</a-button>
              <a-button size="small" @click="openMoves(record)">{{ es ? 'Historial' : 'History' }}</a-button>
              <a-button type="text" size="small" @click="openForm(record)"><EditOutlined /></a-button>
              <a-popconfirm :title="es ? '¿Eliminar este repuesto?' : 'Delete this part?'" @confirm="remove(record)">
                <a-button type="text" size="small" danger><DeleteOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="formOpen" :title="form.id ? (es ? 'Editar repuesto' : 'Edit part') : (es ? 'Nuevo repuesto' : 'New part')" :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="save">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="16"><a-form-item :label="es ? 'Nombre *' : 'Name *'"><a-input v-model:value="form.name" :maxlength="150" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="es ? 'Código' : 'Code'"><a-input v-model:value="form.code" :maxlength="40" /></a-form-item></a-col>
          <a-col :xs="8"><a-form-item :label="es ? 'Unidad' : 'Unit'"><a-input v-model:value="form.unit" :maxlength="20" /></a-form-item></a-col>
          <a-col :xs="8"><a-form-item :label="es ? 'Stock mínimo' : 'Minimum stock'"><a-input-number v-model:value="form.min_stock" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="8"><a-form-item :label="es ? 'Costo' : 'Cost'"><a-input-number v-model:value="form.unit_cost" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="24"><a-form-item :label="es ? 'Proveedor' : 'Supplier'" style="margin-bottom: 0"><a-select v-model:value="form.provider_id" :options="providerOptions" allow-clear show-search option-filter-prop="label" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>

    <a-modal v-model:open="moveOpen" :title="move.kind === 'in' ? (es ? 'Comprar' : 'Buy') : (es ? 'Ajustar stock' : 'Adjust stock')" :confirm-loading="saving" :ok-text="es ? 'Guardar' : 'Save'" @ok="saveMove">
      <p v-if="move.part"><b>{{ move.part.name }}</b> · {{ es ? 'stock' : 'stock' }} {{ Number(move.part.stock) }} {{ move.part.unit }}</p>
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="es ? 'Cantidad *' : 'Quantity *'" :extra="move.kind === 'adjust' ? (es ? 'Negativo para descontar.' : 'Negative to take out.') : ''"><a-input-number v-model:value="move.quantity" style="width: 100%" /></a-form-item></a-col>
          <a-col v-if="move.kind === 'in'" :span="12"><a-form-item :label="es ? 'Costo unitario' : 'Unit cost'"><a-input-number v-model:value="move.unit_cost" :min="0" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="es ? 'Nota' : 'Note'" style="margin-bottom: 0"><a-input v-model:value="move.note" :maxlength="190" /></a-form-item>
      </a-form>
    </a-modal>

    <a-modal v-model:open="movesOpen" :title="movesTitle" :footer="null" :width="640">
      <a-table :columns="moveColumns" :data-source="moves" row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content', y: 360 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'what'">{{ record.title ? `${record.vehicle} · ${record.title}` : (record.note || kindLabel(record.kind)) }}</template>
          <template v-else-if="column.key === 'qty'"><span :class="Number(record.quantity) < 0 ? 'neg' : 'pos'">{{ Number(record.quantity) > 0 ? '+' : '' }}{{ Number(record.quantity) }}</span></template>
          <template v-else-if="column.key === 'date'">{{ String(record.created_at).slice(0, 10) }}</template>
        </template>
      </a-table>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Fleet -> Spare parts (2026-11-04), the mechanic's shelf: stock, what was bought, and what each job took out
 * (Fleet -> Maintenance -> Parts used).
 */
import { computed, onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';
import { sortByLabel } from '../../lib/filterOptions';
import { useUiStore } from '../../stores/ui';

const { money } = useFormat();
const es = computed(() => useUiStore().locale === 'es');
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const rows = ref([]);
const providers = ref([]);
const loading = ref(false);
const saving = ref(false);
const search = ref('');
const low = ref(false);
const providerOptions = computed(() => sortByLabel(providers.value.map(p => ({ value: p.id, label: p.name }))));
const stockValue = computed(() => rows.value.reduce((s, p) => s + Number(p.stock) * Number(p.unit_cost), 0));
const columns = computed(() => [
  { title: es.value ? 'Repuesto' : 'Part', key: 'name' },
  { title: 'Stock', key: 'stock' },
  { title: es.value ? 'Costo' : 'Cost', key: 'cost', align: 'right' },
  { title: '', key: 'actions' },
]);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('fleet/parts', { search: search.value, low: low.value ? 1 : 0 });
    rows.value = res.parts;
    providers.value = res.providers;
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}

const formOpen = ref(false);
const form = ref({});
function openForm(r) {
  form.value = r ? { id: r.id, name: r.name, code: r.code, unit: r.unit, min_stock: Number(r.min_stock), unit_cost: Number(r.unit_cost), provider_id: r.provider_id || undefined }
    : { id: null, name: '', code: '', unit: 'und', min_stock: 0, unit_cost: 0, provider_id: undefined };
  formOpen.value = true;
}
async function save() {
  saving.value = true;
  try {
    if (form.value.id) await http.put(`fleet/parts/${form.value.id}`, form.value); else await http.post('fleet/parts', form.value);
    formOpen.value = false;
    load();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}
async function remove(r) {
  try { await http.delete(`fleet/parts/${r.id}`); load(); } catch (e) { message.error(errorOf(e)); }
}

const moveOpen = ref(false);
const move = ref({});
function openMove(part, kind) {
  move.value = { part, kind, quantity: null, unit_cost: Number(part.unit_cost), note: '' };
  moveOpen.value = true;
}
async function saveMove() {
  saving.value = true;
  try {
    await http.post(`fleet/parts/${move.value.part.id}/move`, { kind: move.value.kind, quantity: move.value.quantity, unit_cost: move.value.kind === 'in' ? move.value.unit_cost : undefined, note: move.value.note });
    moveOpen.value = false;
    load();
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}

const movesOpen = ref(false);
const movesTitle = ref('');
const moves = ref([]);
const kindLabel = k => ({ in: es.value ? 'Compra' : 'Purchase', used: es.value ? 'Usado' : 'Used', adjust: es.value ? 'Ajuste' : 'Adjustment' })[k] || k;
const moveColumns = computed(() => [
  { title: es.value ? 'Fecha' : 'Date', key: 'date', width: 110 },
  { title: es.value ? 'Detalle' : 'Detail', key: 'what' },
  { title: es.value ? 'Cantidad' : 'Quantity', key: 'qty', align: 'right', width: 100 },
]);
async function openMoves(part) {
  try {
    moves.value = (await http.get(`fleet/parts/${part.id}/moves`)).moves;
    movesTitle.value = part.name;
    movesOpen.value = true;
  } catch (e) {
    message.error(errorOf(e));
  }
}

onMounted(load);
</script>

<style scoped>
.fp-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 12px; }
.fp-muted { font-size: 12px; color: #64748b; }
.pos { color: #15803d; } .neg { color: #b91c1c; }
</style>
