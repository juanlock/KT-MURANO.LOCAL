<template>
  <div class="page b2b">
    <!-- The title goes in the top bar, next to the company (ui.barTitle, 2026-11-21: "usemos ese espacio"). -->
    <!-- Hordex's Productos / Proveedores / Clientes (2026-11-20): "Listado de ..." like the earlier system (Proveedores and
         Clientes with "Nuevo ..." under the list, Productos with "Nuevo"), plus the row clicked on the right to edit it. -->
    <a-row :gutter="14">
      <!-- 40 % list / 60 % record (user, 2026-11-21). -->
      <a-col :span="24" class="col-list">
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
              <!-- RUC lookup (2026-11-21): 11 digits typed (or the magnifier) fills the Razón Social from SUNAT's padrón. -->
              <a-col :span="7"><div class="lbl">RUC</div>
                <a-input v-model:value="quick.tax_number" size="small" :maxlength="11" @update:value="v => /^\d{11}$/.test(v) && lookupQuick()">
                  <template #suffix><a-spin v-if="looking" size="small" /><SearchOutlined v-else class="ruc-go" @click="lookupQuick" /></template>
                </a-input></a-col>
              <a-col :span="14"><div class="lbl">Razón Social <span v-if="quickFound" class="ruc-st" :class="{ bad: quickFound.state !== 'ACTIVO' || quickFound.condition !== 'HABIDO' }">{{ quickFound.state }} · {{ quickFound.condition }}</span></div>
                <a-input v-model:value="quick.name" size="small" @press-enter="saveQuick" /></a-col>
              <a-col :span="3"><a-button type="primary" size="small" block :loading="saving" @click="saveQuick"><SaveOutlined /></a-button></a-col>
            </a-row>
          </div>
        </a-card>
      </a-col>
      <!-- Phone: the record opens in a modal (DetailPanel, 2026-11-21). -->
      <DetailPanel :open="!!sel" :span="24" class="col-form" @close="sel = null">
        <a-card size="small" class="panel">
          <a-empty v-if="!sel" :description="`Haga click en un ${cfg.one.toLowerCase()} de la lista`" />
          <template v-else>
            <!-- The record picked, by name (2026-11-21), not the word "Cliente". -->
            <!-- A product as in Hordex's earlier system (2026-11-21): its name in orange with "Ajustar" / "Editar", Ref. and
                 Existencias, the recipe with each input's stock, and the last movements of its kardex. -->
            <template v-if="type === 'products'">
              <div class="pc-head">
                <!-- Editing: the name is typed right in the heading, same look as the card (2026-11-21: "el mismo formato visual"). -->
                <input v-if="editing || !sel.id" v-model="sel.name" class="cx-title pc-title-in" placeholder="Nombre del producto" />
                <span v-else class="pc-title">{{ sel.name }}</span>
                <a-space v-if="sel.id && !editing">
                  <a-button size="small" @click="openAdjust">Ajustar</a-button>
                  <a-button size="small" @click="editing = true">Editar</a-button>
                </a-space>
              </div>
              <template v-if="sel.id && !editing">
                <!-- Label (grey, right-aligned) : value (dark, bold) -- the same reading as the clients' card (2026-11-21:
                     "no se sabe cuál es data y cuál es leyenda"). -->
                <div class="pc-data">
                  <div class="pc-kv"><span class="cx-lbl">Ref.</span><b>{{ sel.code }}</b></div>
                  <div class="pc-kv"><span class="cx-lbl">Existencias</span><b :class="{ neg: Number(card?.stock ?? sel.stock) < 0 }">{{ qty(card?.stock ?? sel.stock) }}</b>&nbsp;<span class="pc-unit">{{ sel.unit }}</span></div>
                  <div class="pc-kv"><span class="cx-lbl">Tipo</span><b>{{ card?.kind || '—' }}</b></div>
                  <div class="pc-kv"><span class="cx-lbl">Precio</span><b>{{ Number(sel.price || 0).toFixed(2) }}</b></div>
                </div>
                <template v-if="card?.inputs?.length">
                  <h4 class="sub">Insumos necesarios</h4>
                  <!-- Half the width, like the earlier system. -->
                  <table class="addr half">
                    <thead><tr><th>Insumo</th><th class="n">Cant. x und.</th><th class="n">Saldo</th></tr></thead>
                    <tbody>
                      <tr v-for="i in card.inputs" :key="i.input_id" :class="{ out: Number(i.stock) <= 0 }">
                        <td>{{ i.name }}</td><td class="n">{{ qty(i.quantity) }}</td><td class="n">{{ qty(i.stock) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </template>
                <h4 class="sub">Últimos Movimientos</h4>
                <div class="moves">
                  <table class="addr">
                    <thead><tr><th class="nr">#</th><th class="dir"><ArrowUpOutlined /><ArrowDownOutlined /></th><th>Fecha</th><th>Motivo</th><th class="n">Cant.</th><th class="n">Saldo</th></tr></thead>
                    <tbody>
                      <tr v-for="(m, i) in card?.moves || []" :key="m.id">
                        <td class="nr">{{ String(i).padStart(2, '0') }}</td>
                        <td class="dir"><ArrowUpOutlined v-if="m.direction === 'salida'" class="up" /><ArrowDownOutlined v-else class="down" /></td>
                        <td>{{ date(m.moved_at) }}</td>
                        <td :title="m.reference || ''">{{ m.reason || '' }}<span v-if="m.reference" class="muted"> · {{ m.reference }}</span></td>
                        <td class="n">{{ qty(m.quantity) }}</td><td class="n">{{ qty(m.balance) }}</td>
                      </tr>
                      <tr v-if="card && !card.moves.length"><td colspan="6" class="muted">Sin movimientos</td></tr>
                    </tbody>
                  </table>
                </div>
              </template>
            </template>
            <a-modal v-model:open="adjust.open" title="Ajustar stock" ok-text="Guardar" cancel-text="Cancelar" width="340px" :confirm-loading="adjust.saving" @ok="saveAdjust">
              <p class="muted">{{ sel?.name }} · Existencias actuales: <b>{{ qty(card?.stock ?? sel?.stock) }}</b></p>
              <div class="lbl">Nuevo Stock</div>
              <a-input-number v-model:value="adjust.quantity" :min="0" style="width: 100%" @press-enter="saveAdjust" />
            </a-modal>
            <!-- A client's / supplier's card as in Hordex's earlier system (2026-11-21: "más limpio de trabajar y más compacto"):
                 the razón social as the heading (Hordex's orange), then one "Label : value" line per field, underlined inputs,
                 the 3 phones (and a supplier's 3 contacts) on one line. -->
            <div v-if="type !== 'products'" class="card-x">
              <input v-model="sel.name" class="cx-title" :placeholder="sel.id ? 'Razón Social' : `Nuevo ${cfg.one} - Razón Social`" />
              <div class="cx-row"><span class="cx-lbl">Nombre Comercial</span><input v-model="sel.short_name" class="cx-in" /></div>
              <div class="cx-row cx-two">
                <div class="cx-row"><span class="cx-lbl">RUC</span><input v-model="sel.tax_number" class="cx-in" maxlength="11" />
                  <a-tooltip title="Consultar en SUNAT"><a-button size="small" type="text" :loading="looking" @click="lookupCard"><SearchOutlined /></a-button></a-tooltip></div>
                <div v-if="type === 'clients'" class="cx-row"><span class="cx-lbl">Agente de Retención</span><a-checkbox v-model:checked="sel.retention_agent" /></div>
                <div v-else class="cx-row"><span class="cx-lbl">Email</span><input v-model="sel.email" class="cx-in" /></div>
              </div>
              <!-- What SUNAT said about this RUC (the magnifier): state / condition; a fiscal address not in the list can be added. -->
              <div v-if="cardFound" class="cx-sunat">
                <span class="ruc-st" :class="{ bad: cardFound.state !== 'ACTIVO' || cardFound.condition !== 'HABIDO' }">SUNAT: {{ cardFound.state }} · {{ cardFound.condition }}</span>
                <span v-if="cardFound.name && cardFound.name !== sel.name" class="muted"> · {{ cardFound.name }} <a @click="sel.name = cardFound.name">usar</a></span>
                <span v-if="type === 'clients' && cardFound.address && !hasFiscal"> · <a @click="useFiscal">Agregar dirección fiscal</a></span>
                <span v-if="type === 'providers' && cardFound.address && norm(cardFound.address) !== norm(sel.adresse)"> · <a @click="useFiscal">Usar dirección fiscal</a></span>
              </div>
              <template v-if="type === 'clients'">
                <div class="cx-row"><span class="cx-lbl">Condición de Pago</span>
                  <a-select v-model:value="sel.payment_term_id" class="cx-in" size="small" :bordered="false" allow-clear show-search option-filter-prop="label" :options="termOptions" /></div>
                <div class="cx-row"><span class="cx-lbl">Email</span><input v-model="sel.email" class="cx-in" /></div>
              </template>
              <!-- A supplier keeps its address on the card (no address table) and its location (ubigeo). -->
              <template v-else>
                <div class="cx-row"><span class="cx-lbl">Dirección</span><input v-model="sel.adresse" class="cx-in" /></div>
                <div class="cx-row"><span class="cx-lbl">Ubicación</span><input v-model="sel.city" class="cx-in" placeholder="Distrito - Provincia - Departamento" /></div>
                <div class="cx-phones">
                  <div v-for="n in 3" :key="'c' + n" class="cx-row"><span class="cx-lbl sm">Cont. {{ n }}</span><input v-model="contacts[n - 1]" class="cx-in" /></div>
                </div>
              </template>
              <div class="cx-phones">
                <div v-for="n in 3" :key="n" class="cx-row"><span class="cx-lbl sm">Telf. {{ n }}</span><input v-model="phones[n - 1]" class="cx-in" /></div>
              </div>
              <!-- A client's contacts are a table below (several people, 2026-11-21); a new client starts with one. -->
              <template v-if="type === 'clients' && !sel.id">
                <div class="cx-row"><span class="cx-lbl">Contacto</span><input v-model="sel.contact_name" class="cx-in" /></div>
                <div class="cx-row"><span class="cx-lbl">Dirección</span><input v-model="sel.adresse" class="cx-in" /></div>
              </template>
            </div>
            <a-form layout="vertical" class="tight">
              <!-- Hordex's "Editar Producto" (Código / Ref. / SKU, Tipo, Empaque, Precio, Receta), in the card's own look:
                   grey label : value on underlined fields, two columns, the recipe as a half-width table. -->
              <template v-if="type === 'products' && (editing || !sel.id)">
                <div class="pc-data">
                  <div class="pc-kv"><span class="cx-lbl">Código / Ref.</span><input v-model="sel.code" class="cx-in" /></div>
                  <div class="pc-kv"><span class="cx-lbl">Tipo</span>
                    <a-select v-model:value="sel.category_id" class="cx-in" size="small" :bordered="false" :options="categoryOptions" /></div>
                  <div class="pc-kv"><span class="cx-lbl">Und.</span>
                    <a-select v-model:value="sel.unit_id" class="cx-in" size="small" :bordered="false" show-search option-filter-prop="label" :options="unitOptions" /></div>
                  <div class="pc-kv"><span class="cx-lbl">Precio</span><input v-model="sel.price" class="cx-in num" type="number" min="0" step="0.01" /></div>
                  <div v-if="isFinal" class="pc-kv"><span class="cx-lbl">Empaque</span><input v-model="sel.pack_size" class="cx-in num" type="number" min="1" step="1" placeholder="und. por caja" /></div>
                </div>
                <div class="pc-kv full"><span class="cx-lbl">Detalle</span><input v-model="sel.note" class="cx-in" /></div>
                <template v-if="isFinal">
                  <h4 class="sub">Receta</h4>
                  <table class="addr half">
                    <thead><tr><th>Insumo</th><th class="n">Cant. x und.</th><th /></tr></thead>
                    <tbody>
                      <tr v-for="(r, i) in recipe" :key="i">
                        <td><a-select v-model:value="r.input_id" size="small" :bordered="false" show-search option-filter-prop="label" :options="inputOptions"
                          style="width: 100%" @change="onRecipeInput(r)" /></td>
                        <td class="n"><input v-model="r.quantity" class="cx-in num qty" type="number" min="0" step="any" /></td>
                        <td class="del"><a @click="recipe.splice(i, 1)"><DeleteOutlined /></a></td>
                      </tr>
                      <tr v-if="!recipe.length"><td colspan="3" class="muted">Sin insumos</td></tr>
                    </tbody>
                  </table>
                  <a-button size="small" type="dashed" class="add-in" @click="recipe.push({ input_id: undefined, quantity: 1 })"><PlusOutlined /> Agregar otro insumo</a-button>
                </template>
              </template>
              <div v-if="type !== 'products' || editing || !sel.id" class="actions">
                <a-button size="small" @click="type === 'products' && sel.id ? (editing = false) : (sel = null)">Cancelar</a-button>
                <a-button type="primary" size="small" :loading="saving" @click="save(sel)"><SaveOutlined /> Guardar</a-button>
              </div>

              <!-- A client's addresses as a table, like Hordex's client detail (2026-11-21: ADYGA EIRL has 2, the form
                   showed one field). They are the delivery points the guides pick. -->
              <template v-if="type === 'clients' && sel.id">
                <h4 class="sub">Direcciones</h4>
                <table class="addr">
                  <thead><tr><th class="nr">#</th><th>Dirección</th><th>Ubigeo</th><th /></tr></thead>
                  <tbody>
                    <tr v-for="(a, i) in addresses" :key="a.id">
                      <td class="nr">{{ i + 1 }}.</td>
                      <td>{{ a.address }}</td>
                      <td>{{ [a.district, a.province, a.department].filter(Boolean).join(' - ') }}</td>
                      <td class="del">
                        <a-popconfirm title="¿Quitar esta dirección?" ok-text="Sí" cancel-text="No" @confirm="removeAddress(a)">
                          <a><DeleteOutlined /></a>
                        </a-popconfirm>
                      </td>
                    </tr>
                    <tr v-if="!addresses.length"><td colspan="4" class="muted">Sin direcciones</td></tr>
                  </tbody>
                </table>
                <a-row :gutter="6" align="bottom" class="addr-new">
                  <a-col :span="24"><div class="lbl">Nueva dirección</div><a-input v-model:value="addr.address" size="small" @press-enter="addAddress" /></a-col>
                  <a-col :span="7"><div class="lbl">Distrito</div><a-input v-model:value="addr.district" size="small" /></a-col>
                  <a-col :span="7"><div class="lbl">Provincia</div><a-input v-model:value="addr.province" size="small" /></a-col>
                  <a-col :span="7"><div class="lbl">Departamento</div><a-input v-model:value="addr.department" size="small" /></a-col>
                  <a-col :span="3"><a-button type="primary" size="small" block :loading="savingAddr" @click="addAddress"><PlusOutlined /></a-button></a-col>
                </a-row>

                <!-- Contactos: several people per client, as the earlier system (2026-11-21). -->
                <h4 class="sub">Contactos</h4>
                <table class="addr">
                  <thead><tr><th class="nr">#</th><th>Nombre</th><th>Área</th><th>Teléfono</th><th>Email</th><th /></tr></thead>
                  <tbody>
                    <tr v-for="(k, i) in clientContacts" :key="k.id">
                      <td class="nr">{{ i + 1 }}.</td><td>{{ k.name }}</td><td>{{ k.area }}</td><td>{{ k.phone }}</td><td>{{ k.email }}</td>
                      <td class="del">
                        <a-popconfirm title="¿Quitar este contacto?" ok-text="Sí" cancel-text="No" @confirm="removeContact(k)"><a><DeleteOutlined /></a></a-popconfirm>
                      </td>
                    </tr>
                    <tr v-if="!clientContacts.length"><td colspan="6" class="muted">Sin contactos</td></tr>
                  </tbody>
                </table>
                <a-row :gutter="6" align="bottom" class="addr-new">
                  <a-col :span="7"><div class="lbl">Nombre</div><a-input v-model:value="kon.name" size="small" @press-enter="addContact" /></a-col>
                  <a-col :span="4"><div class="lbl">Área</div><a-input v-model:value="kon.area" size="small" /></a-col>
                  <a-col :span="5"><div class="lbl">Teléfono</div><a-input v-model:value="kon.phone" size="small" /></a-col>
                  <a-col :span="5"><div class="lbl">Email</div><a-input v-model:value="kon.email" size="small" /></a-col>
                  <a-col :span="3"><a-button type="primary" size="small" block :loading="savingKon" @click="addContact"><PlusOutlined /></a-button></a-col>
                </a-row>
              </template>
            </a-form>
          </template>
        </a-card>
      </DetailPanel>
    </a-row>
  </div>
</template>

<script setup>
/** Distribución B2B -> Productos / Proveedores / Clientes (2026-11-20): Hordex's three lists, one page (prop `type`). */
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, SaveOutlined, DeleteOutlined, SearchOutlined, ArrowUpOutlined, ArrowDownOutlined } from '@ant-design/icons-vue';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import DetailPanel from '../../components/DetailPanel.vue';
import { useBarTitle } from '../../composables/useBarTitle';
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
      // Compact so every column fits the 40 % list (2026-11-21); the name takes the rest and is cut with "…".
      { title: '#', key: 'n', width: 40 },
      { title: 'Código', key: 'code', dataIndex: 'code', sorter: true, width: 78, ellipsis: true },
      { title: 'Producto', key: 'name', dataIndex: 'name', sorter: true, ellipsis: { showTitle: true } },
      { title: 'Und.', key: 'unit', dataIndex: 'unit', width: 46 },
      { title: 'Stock', key: 'stock', dataIndex: 'stock', width: 58, align: 'right' },
    ]
  : [
      { title: '#', key: 'n', width: 40 },
      { title: 'RUC', key: 'tax_number', dataIndex: 'tax_number', sorter: true, width: 104 },
      { title: 'Nombre Comercial', key: 'name', dataIndex: 'name', sorter: true, ellipsis: true },
    ];

const units = ref([]);
const unitOptions = computed(() => sortByLabel(units.value.map(u => ({ value: u.id, label: u.ShortName || u.name }))));
const sel = ref(null);
const saving = ref(false);
const quick = reactive({ tax_number: '', name: '' });

// The client's three phones (stored as "a / b / c" in clients.phone, like the import wrote them).
const phones = ref(['', '', '']);
// A supplier's three contacts, stored the same way in providers.contact_name (2026-11-21).
const contacts = ref(['', '', '']);
const split3 = v => { const p = String(v || '').split('/').map(s => s.trim()); return [p[0] || '', p[1] || '', p.slice(2).join(' / ') || '']; };
const join3 = a => a.map(s => String(s || '').trim()).filter(Boolean).join(' / ') || null;
const terms = ref([]);
const termOptions = computed(() => sortByLabel(terms.value.map(t => ({ value: t.id, label: t.name }))));
function pick(r) {
  sel.value = { ...r, retention_agent: !!Number(r.retention_agent) };
  phones.value = split3(r.phone);
  contacts.value = split3(r.contact_name);
}

// The client's addresses (its own endpoint; the guides read the same table).
const addresses = ref([]);
const addr = reactive({ address: '', district: '', province: '', department: '' });
const savingAddr = ref(false);
// The client's contacts (several people; B2bController::clientContacts).
const clientContacts = ref([]);
const kon = reactive({ name: '', area: '', phone: '', email: '' });
const savingKon = ref(false);
async function loadContacts() {
  clientContacts.value = props.type === 'clients' && sel.value?.id ? await http.get(`b2b/clients/${sel.value.id}/contacts`) : [];
}
async function addContact() {
  if (!kon.name.trim()) { message.warning('Escriba el nombre del contacto'); return; }
  savingKon.value = true;
  try {
    await http.post(`b2b/clients/${sel.value.id}/contacts`, { ...kon });
    Object.assign(kon, { name: '', area: '', phone: '', email: '' });
    await loadContacts();
  } catch (e) { message.error(e?.data?.message || 'No se pudo guardar'); } finally { savingKon.value = false; }
}
async function removeContact(k) {
  try { await http.delete(`b2b/clients/${sel.value.id}/contacts/${k.id}`); await loadContacts(); } catch (e) { message.error(e?.data?.message || 'No se pudo quitar'); }
}
async function loadAddresses() {
  addresses.value = props.type === 'clients' && sel.value?.id ? await http.get(`b2b/clients/${sel.value.id}/addresses`) : [];
}
watch(() => sel.value?.id, () => {
  Object.assign(addr, { address: '', district: '', province: '', department: '' }); cardFound.value = null; editing.value = false;
  Object.assign(kon, { name: '', area: '', phone: '', email: '' });
  loadAddresses(); loadContacts(); loadCard();
});

// A product's card (B2bController::productCard): stock, recipe, last kardex movements; "Ajustar" sets the counted stock.
const { date } = useFormat();
const editing = ref(false);
// Hordex's edit form: Tipo = the company's categories, the recipe with its inputs / packing materials.
const categories = ref([]);
const inputs = ref([]);
const categoryOptions = computed(() => categories.value.map(c => ({ value: c.id, label: c.code === 'FIN' ? 'Producto final' : c.code === 'INS' ? 'Insumo' : c.code === 'SER' ? 'Servicio' : c.name })));
const inputOptions = computed(() => inputs.value.filter(i => i.id !== sel.value?.id).map(i => ({ value: i.id, label: i.name })));
const isFinal = computed(() => categories.value.find(c => c.id === sel.value?.category_id)?.code === 'FIN');
const recipe = ref([]);
watch(editing, on => { if (on) recipe.value = (card.value?.inputs || []).map(i => ({ input_id: i.input_id, quantity: Number(i.quantity) })); });
// A packing material goes in as 1 / Empaque per unit (the earlier system divided it by the pack size).
function onRecipeInput(r) {
  const kind = inputs.value.find(i => i.id === r.input_id)?.kind;
  if (kind === 'EMB' && Number(sel.value?.pack_size) > 0) r.quantity = +(1 / Number(sel.value.pack_size)).toFixed(6);
}
const card = ref(null);
async function loadCard() {
  card.value = null;
  if (props.type === 'products' && sel.value?.id) card.value = await http.get(`b2b/products/${sel.value.id}/card`);
}
const adjust = reactive({ open: false, quantity: 0, saving: false });
function openAdjust() { adjust.quantity = Number(card.value?.stock ?? sel.value?.stock ?? 0); adjust.open = true; }
async function saveAdjust() {
  adjust.saving = true;
  try {
    const r = await http.post(`b2b/products/${sel.value.id}/adjust`, { quantity: adjust.quantity });
    message.success(`Stock ajustado a ${qty(r.stock)}`);
    adjust.open = false;
    sel.value.stock = r.stock;
    await loadCard();
    crud.fetchRows();
  } catch (e) { message.error(e?.data?.message || 'No se pudo ajustar'); } finally { adjust.saving = false; }
}
async function addAddress() {
  if (!addr.address.trim()) { message.warning('Escriba la dirección'); return; }
  savingAddr.value = true;
  try {
    await http.post(`b2b/clients/${sel.value.id}/addresses`, { ...addr });
    Object.assign(addr, { address: '', district: '', province: '', department: '' });
    await loadAddresses();
  } catch (e) { message.error(e?.data?.message || 'No se pudo guardar'); } finally { savingAddr.value = false; }
}
async function removeAddress(a) {
  try { await http.delete(`b2b/clients/${sel.value.id}/addresses/${a.id}`); await loadAddresses(); } catch (e) { message.error(e?.data?.message || 'No se pudo quitar'); }
}

const ui = useUiStore();
useBarTitle(() => cfg.value.title);
function openNew() {
  sel.value = { code: '', name: '', unit_id: units.value.find(u => /^und$/i.test(u.ShortName || ''))?.id, price: 0, type: 'is_single', note: '',
    category_id: categories.value.find(c => c.code === 'FIN')?.id, pack_size: null };
  recipe.value = [];
}

async function save(row) {
  saving.value = true;
  try {
    if (props.type === 'products' && row === sel.value) {
      // The recipe only for a finished product (Hordex's "Receta"), empty rows dropped.
      row = { ...row, pack_size: Number(row.pack_size) > 0 ? Number(row.pack_size) : null,
        inputs: isFinal.value ? recipe.value.filter(r => r.input_id && Number(r.quantity) > 0).map(r => ({ input_id: r.input_id, quantity: Number(r.quantity) })) : [] };
    }
    if (props.type !== 'products' && row === sel.value) {
      row = { ...row, phone: join3(phones.value) };
      if (props.type === 'providers') row.contact_name = join3(contacts.value);
      // An existing client's contacts live in its Contactos table: the card does not send the old single field.
      if (props.type === 'clients' && row.id) delete row.contact_name;
    }
    const r = row.id ? await http.put(`b2b/masters/${props.type}/${row.id}`, row) : await http.post(`b2b/masters/${props.type}`, row);
    message.success(`${cfg.value.one} guardado`);
    await load();
    const fresh = crud.rows.value.find(x => x.id === r.id);
    // Not on the page shown (another page of the list): open what was saved.
    pick(fresh || { ...row, id: r.id });
    editing.value = false;
    return true;
  } catch (e) {
    message.error(e?.data?.message || 'No se pudo guardar');
    return false;
  } finally { saving.value = false; }
}
async function saveQuick() {
  if (!quick.tax_number || !quick.name) { message.warning('Escriba el RUC y la Razón Social'); return; }
  const found = quickFound.value?.ruc === quick.tax_number ? quickFound.value : null;
  // A supplier keeps SUNAT's fiscal address and location on its own card.
  const extra = props.type === 'providers' && found ? { adresse: found.address, city: [found.district, found.province, found.department].filter(Boolean).join(' - ') || null, zip: found.ubigeo } : {};
  if (await save({ ...quick, ...extra })) {
    // The new client also gets SUNAT's fiscal address as its first address (the guides' arrival point).
    if (props.type === 'clients' && found?.address && sel.value?.id) {
      try { await http.post(`b2b/clients/${sel.value.id}/addresses`, { address: found.address, ubigeo: found.ubigeo, district: found.district, province: found.province, department: found.department }); await loadAddresses(); } catch (e) { /* the client is saved; the address can be added by hand */ }
    }
    quick.tax_number = ''; quick.name = ''; quickFound.value = null;
  }
}

// RUC lookup (B2bController::rucLookup -> apis.net.pe, cached 30 days).
const looking = ref(false);
const quickFound = ref(null);
const cardFound = ref(null);
async function askRuc(ruc) {
  if (!/^\d{11}$/.test(String(ruc || ''))) { message.warning('El RUC tiene 11 dígitos'); return null; }
  looking.value = true;
  try { return await http.get(`b2b/ruc/${ruc}`); } catch (e) { message.warning(e?.data?.message || 'No se pudo consultar el RUC'); return null; } finally { looking.value = false; }
}
async function lookupQuick() {
  if (looking.value) return;
  const r = await askRuc(quick.tax_number);
  quickFound.value = r;
  if (r?.name) quick.name = r.name;
}
async function lookupCard() {
  const r = await askRuc(sel.value?.tax_number);
  cardFound.value = r;
  if (r?.name && !sel.value.name) sel.value.name = r.name;
}
const norm = s => String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
const hasFiscal = computed(() => !!cardFound.value?.address && addresses.value.some(a => norm(a.address) === norm(cardFound.value.address)));
function useFiscal() {
  const f = cardFound.value;
  if (props.type === 'providers') {
    // A supplier: its card's address and location (no address table).
    Object.assign(sel.value, { adresse: f.address || sel.value.adresse, city: [f.district, f.province, f.department].filter(Boolean).join(' - ') || sel.value.city, zip: f.ubigeo || sel.value.zip });
    return;
  }
  Object.assign(addr, { address: f.address || '', district: f.district || '', province: f.province || '', department: f.department || '' });
  addAddress();
}
async function load() {
  await crud.fetchRows();
  if (props.type === 'products') {
    units.value = crud.payload.value?.units || units.value;
    categories.value = crud.payload.value?.categories || categories.value;
    inputs.value = crud.payload.value?.inputs || inputs.value;
  }
  if (props.type === 'clients') terms.value = crud.payload.value?.terms || terms.value;
}
onMounted(load);
</script>

<style scoped>
.panel { min-height: calc(100vh - 170px); border-radius: 2px; }
@media (min-width: 1200px) {
  .col-list { flex: 0 0 40%; max-width: 40%; }
  .col-form { flex: 0 0 60%; max-width: 60%; }
}
.side-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.side-head h3 { margin: 0; font-size: 22px; font-weight: 400; }
.b2b :deep(.dt-managed .ant-table-cell) { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; padding-left: 6px !important; padding-right: 6px !important; }
/* The list never runs wider than its panel: fixed layout, the columns keep their widths, the name gets the rest. */
.b2b :deep(.col-list .dt-managed table) { table-layout: fixed !important; width: 100% !important; min-width: 0 !important; }
.b2b :deep(.col-list .ant-table-content) { overflow-x: hidden !important; }
.num { font-variant-numeric: tabular-nums; }
.neg { color: #cf1322; }
.new-line { margin-top: 12px; }
.new-line h4 { margin: 0 0 4px; font-size: 16px; font-weight: 500; }
.lbl { font-size: 12px; opacity: 0.75; margin-bottom: 2px; }
.tight :deep(.ant-form-item) { margin-bottom: 8px; }
.stock { margin: 4px 0 8px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
.sel-name { font-weight: 600 !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sub { margin: 12px 0 4px; font-size: 15px; font-weight: 600; }
/* Hordex's client card: "Label : value" lines, labels right-aligned, inputs underlined only (2026-11-21). */
.card-x { margin-bottom: 6px; }
/* Hordex's orange (--naranja-asg, #ff3f00) on the company name, like its earlier system (2026-11-21). */
.cx-title { width: 100%; font-size: 21px; font-weight: 600; color: #ff3f00 !important; border: 0; border-bottom: 1px solid rgba(128, 128, 128, 0.35); padding: 2px 0 4px;
  margin-bottom: 8px; background: transparent; color: inherit; outline: none; }
.cx-row { display: flex; align-items: center; min-width: 0; }
.card-x > .cx-row, .card-x > .cx-two, .card-x > .cx-phones { margin-top: 6px; }
.cx-lbl { flex: 0 0 9.5rem; text-align: right; font-size: 13px; opacity: 0.8; white-space: nowrap; }
.cx-lbl::after { content: ':'; padding: 0 0.5rem 0 0.25rem; }
.cx-lbl.sm { flex-basis: 4rem; }
.cx-in { flex: 1 1 auto; min-width: 0; border: 0; border-bottom: 1px solid rgba(128, 128, 128, 0.35); padding: 2px 4px; font-size: 13.5px;
  background: transparent; color: inherit; outline: none; }
input.cx-in:focus, .cx-title:focus { border-bottom-color: rgb(var(--app-primary-rgb, 0, 120, 212)); }
.cx-in :deep(.ant-select-selector) { padding: 0 4px !important; }
.cx-two { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.cx-phones { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.addr .nr { width: 26px; }
/* Product card (Hordex, 2026-11-21). */
.pc-head { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
.pc-title { font-size: 21px; font-weight: 600; color: #ff3f00; flex: 1 1 auto; min-width: 0; }
.pc-data { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; font-size: 13.5px; margin: 4px 0 6px; }
.pc-kv { display: flex; align-items: baseline; padding: 2px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.18); }
.pc-kv .cx-lbl { flex-basis: 7rem; }
.pc-kv b { font-size: 14.5px; }
.pc-unit { font-size: 12px; opacity: 0.65; }
.addr.half { width: 50%; }
.pc-kv .cx-in { font-size: 14px; }
.pc-kv.full { margin-bottom: 4px; }
.pc-title-in { flex: 1 1 auto; margin-bottom: 0 !important; }
.cx-in.num { text-align: right; }
.cx-in.qty { width: 90px; }
.add-in { margin-top: 6px; }
.pc-kv :deep(.ant-select) { flex: 1 1 auto; }
@media (max-width: 767px) { .pc-data { grid-template-columns: 1fr; } .addr.half { width: 100%; } }
.addr th.n, .addr td.n { text-align: right; font-variant-numeric: tabular-nums; }
.addr tr.out td { color: #cf1322; font-weight: 600; }
.addr .dir { width: 34px; text-align: center; }
.addr .up { color: #cf1322; }
.addr .down { color: #15803d; }
.moves { max-height: calc(100vh - 380px); overflow-y: auto; }
.moves thead th { position: sticky; top: 0; z-index: 1; }
.ruc-go { cursor: pointer; opacity: 0.7; }
.ruc-go:hover { opacity: 1; color: rgb(var(--app-primary-rgb, 0, 120, 212)); }
.ruc-st { font-size: 11.5px; font-weight: 600; color: #15803d; }
.ruc-st.bad { color: #cf1322; }
.cx-sunat { margin: 4px 0 0 9.5rem; font-size: 12px; }
@media (max-width: 767px) {
  .cx-lbl { flex-basis: 7.5rem; }
  .cx-two, .cx-phones { grid-template-columns: 1fr; }
  .cx-phones .cx-lbl.sm { flex-basis: 7.5rem; }
}
/* Hordex's compact table: dense rows, header band, zebra, hover. */
.addr { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.addr th { text-align: left; font-weight: 600; padding: 4px 6px; background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.1); }
.addr td { padding: 4px 6px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); }
.addr tbody tr:nth-child(even) td { background: rgba(128, 128, 128, 0.045); }
.addr tbody tr:hover td { background: rgba(var(--app-primary-rgb, 0, 120, 212), 0.07); }
.addr .del { width: 26px; text-align: center; }
.addr-new { margin-top: 6px; row-gap: 4px; }
.muted { opacity: 0.6; }
</style>
