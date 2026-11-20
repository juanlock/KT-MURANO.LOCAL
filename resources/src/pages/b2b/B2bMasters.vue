<template>
  <div class="page b2b">
    <PageHeader :title="cfg.title" />
    <!-- Hordex's Productos / Proveedores / Clientes (2026-11-20): "Listado de ..." like the earlier system (Proveedores and
         Clientes with "Nuevo ..." under the list, Productos with "Nuevo"), plus the row clicked on the right to edit it. -->
    <a-row :gutter="14">
      <a-col :xs="24" :xl="14">
        <a-card size="small" class="panel">
          <div class="side-head">
            <h3>Listado de {{ cfg.title }}</h3>
            <a-button v-if="type === 'products'" type="primary" size="small" @click="openNew"><PlusOutlined /> Nuevo</a-button>
          </div>
          <DataTable :crud="crud" :columns="columns" :row-click="r => pick(r)" compact-toolbar :table-key="`b2b-${type}`">
            <template #bodyCell="{ column, record, index }">
              <template v-if="column.key === 'n'">{{ String((crud.page.value - 1) * crud.limit.value + index + 1).padStart(3, '0') }}</template>
              <template v-else-if="column.key === 'stock'"><span class="num" :class="{ neg: Number(record.stock) < 0 }">{{ qty(record.stock) }}</span></template>
            </template>
          </DataTable>
          <div v-if="type !== 'products'" class="new-line">
            <h4>Nuevo {{ cfg.one }}</h4>
            <a-row :gutter="8" align="bottom">
              <a-col :span="6"><div class="lbl">RUC</div><a-input v-model:value="quick.tax_number" size="small" :maxlength="11" /></a-col>
              <a-col :span="15"><div class="lbl">Razón Social</div><a-input v-model:value="quick.name" size="small" @press-enter="saveQuick" /></a-col>
              <a-col :span="3"><a-button type="primary" size="small" block :loading="saving" @click="saveQuick"><SaveOutlined /></a-button></a-col>
            </a-row>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :xl="10">
        <a-card size="small" class="panel">
          <a-empty v-if="!sel" :description="`Haga click en un ${cfg.one.toLowerCase()} de la lista`" />
          <template v-else>
            <div class="side-head"><h3>{{ sel.id ? cfg.one : `Nuevo ${cfg.one}` }}</h3></div>
            <a-form layout="vertical" class="tight">
              <template v-if="type === 'products'">
                <a-row :gutter="10">
                  <a-col :span="8"><a-form-item label="Código"><a-input v-model:value="sel.code" /></a-form-item></a-col>
                  <a-col :span="16"><a-form-item label="Producto"><a-input v-model:value="sel.name" /></a-form-item></a-col>
                </a-row>
                <a-row :gutter="10">
                  <a-col :span="8"><a-form-item label="Und."><a-select v-model:value="sel.unit_id" :options="unitOptions" /></a-form-item></a-col>
                  <a-col :span="8"><a-form-item label="Precio"><a-input-number v-model:value="sel.price" :min="0" :precision="2" style="width: 100%" /></a-form-item></a-col>
                  <a-col :span="8"><a-form-item label="Tipo"><a-select v-model:value="sel.type" :options="[{ value: 'is_single', label: 'Producto' }, { value: 'is_service', label: 'Servicio' }]" /></a-form-item></a-col>
                </a-row>
                <a-form-item label="Detalle"><a-textarea v-model:value="sel.note" :rows="2" /></a-form-item>
                <div v-if="sel.id" class="stock">Stock: <b :class="{ neg: Number(sel.stock) < 0 }">{{ qty(sel.stock) }}</b> {{ sel.unit }}</div>
              </template>
              <template v-else>
                <a-row :gutter="10">
                  <a-col :span="8"><a-form-item label="RUC"><a-input v-model:value="sel.tax_number" :maxlength="11" /></a-form-item></a-col>
                  <a-col :span="16"><a-form-item label="Razón Social"><a-input v-model:value="sel.name" /></a-form-item></a-col>
                </a-row>
                <a-form-item v-if="type === 'clients'" label="Nombre Comercial"><a-input v-model:value="sel.short_name" /></a-form-item>
                <a-form-item label="Dirección"><a-input v-model:value="sel.adresse" /></a-form-item>
                <a-row :gutter="10">
                  <a-col :span="12"><a-form-item label="Teléfono"><a-input v-model:value="sel.phone" /></a-form-item></a-col>
                  <a-col :span="12"><a-form-item label="Email"><a-input v-model:value="sel.email" /></a-form-item></a-col>
                </a-row>
                <a-form-item label="Contacto"><a-input v-model:value="sel.contact_name" /></a-form-item>
              </template>
              <div class="actions">
                <a-button size="small" @click="sel = null">Cancelar</a-button>
                <a-button type="primary" size="small" :loading="saving" @click="save(sel)"><SaveOutlined /> Guardar</a-button>
              </div>
            </a-form>
          </template>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/** Distribución B2B -> Productos / Proveedores / Clientes (2026-11-20): Hordex's three lists, one page (prop `type`). */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, SaveOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';

const props = defineProps({ type: { type: String, required: true } });
const CFG = {
  products: { title: 'Productos', one: 'Producto' },
  providers: { title: 'Proveedores', one: 'Proveedor' },
  clients: { title: 'Clientes', one: 'Cliente' },
};
const cfg = computed(() => CFG[props.type]);
const qty = v => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const crud = useCrudTable(`b2b/masters/${props.type}`, { rowsKey: 'rows', sortField: 'name', sortType: 'asc', limit: 33 });
const columns = props.type === 'products'
  ? [
      { title: '#', key: 'n', width: 52 },
      { title: 'Código', key: 'code', dataIndex: 'code', sorter: true, width: 130, ellipsis: true },
      { title: 'Producto', key: 'name', dataIndex: 'name', sorter: true, ellipsis: true },
      { title: 'Und.', key: 'unit', dataIndex: 'unit', width: 58 },
      { title: 'Stock', key: 'stock', dataIndex: 'stock', width: 72, align: 'right' },
    ]
  : [
      { title: '#', key: 'n', width: 52 },
      { title: 'RUC', key: 'tax_number', dataIndex: 'tax_number', sorter: true, width: 130 },
      { title: 'Nombre Comercial', key: 'name', dataIndex: 'name', sorter: true, ellipsis: true },
    ];

const units = ref([]);
const unitOptions = computed(() => sortByLabel(units.value.map(u => ({ value: u.id, label: u.ShortName || u.name }))));
const sel = ref(null);
const saving = ref(false);
const quick = reactive({ tax_number: '', name: '' });

function pick(r) { sel.value = { ...r }; }
function openNew() { sel.value = { code: '', name: '', unit_id: units.value.find(u => /^und$/i.test(u.ShortName || ''))?.id, price: 0, type: 'is_single', note: '' }; }

async function save(row) {
  saving.value = true;
  try {
    const r = row.id ? await http.put(`b2b/masters/${props.type}/${row.id}`, row) : await http.post(`b2b/masters/${props.type}`, row);
    message.success(`${cfg.value.one} guardado`);
    await load();
    const fresh = crud.rows.value.find(x => x.id === r.id);
    sel.value = fresh ? { ...fresh } : null;
    return true;
  } catch (e) {
    message.error(e?.data?.message || 'No se pudo guardar');
    return false;
  } finally { saving.value = false; }
}
async function saveQuick() {
  if (!quick.tax_number || !quick.name) { message.warning('Escriba el RUC y la Razón Social'); return; }
  if (await save({ ...quick })) { quick.tax_number = ''; quick.name = ''; }
}
async function load() {
  await crud.fetchRows();
  if (props.type === 'products') units.value = crud.payload.value?.units || units.value;
}
onMounted(load);
</script>

<style scoped>
.panel { min-height: calc(100vh - 170px); border-radius: 2px; }
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 22px; font-weight: 400; }
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; }
.num { font-variant-numeric: tabular-nums; }
.neg { color: #cf1322; }
.new-line { margin-top: 12px; }
.new-line h4 { margin: 0 0 4px; font-size: 16px; font-weight: 500; }
.lbl { font-size: 12px; opacity: 0.75; margin-bottom: 2px; }
.tight :deep(.ant-form-item) { margin-bottom: 8px; }
.stock { margin: 4px 0 8px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
</style>
