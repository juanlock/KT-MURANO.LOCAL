<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? 'Edit bill of materials' : 'New bill of materials'"
      :breadcrumb="['Manufacturing', 'Bills of Materials', isEdit ? form.code : 'New']"
    >
      <template #actions>
        <a-button @click="$router.push('/mrp/boms')">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">
          <template #icon><SaveOutlined /></template>
          {{ $t('submit') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loadingRecord" class="loading"><a-spin size="large" /></div>

    <template v-else>
      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <a-card size="small" :title="$t('Details')" style="margin-bottom: 16px">
            <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
              <a-row :gutter="16">
                <a-col :xs="24" :md="8">
                  <a-form-item label="Code *" name="code">
                    <a-input v-model:value="form.code" placeholder="e.g. BOM-TABLE" allow-clear />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="16">
                  <a-form-item label="Name *" name="name">
                    <a-input v-model:value="form.name" placeholder="e.g. Oak table — standard build" allow-clear />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item label="Product built *" name="product_id">
                    <a-select
                      v-model:value="form.product_id" show-search :filter-option="false"
                      :options="productOptions" :not-found-content="searching ? undefined : null"
                      placeholder="Search a product" @search="searchProducts"
                    >
                      <template v-if="searching" #notFoundContent><a-spin size="small" /></template>
                    </a-select>
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item
                    label="Output per run *" name="output_qty"
                    extra="Component quantities below are per this figure"
                  >
                    <a-input-number v-model:value="form.output_qty" :min="0.0001" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item label="Version" name="version">
                    <a-input-number v-model:value="form.version" :min="1" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Warehouse')" name="warehouse_id">
                    <a-select
                      v-model:value="form.warehouse_id" show-search option-filter-prop="label"
                      :options="warehouseOptions" allow-clear placeholder="Default build location"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="8">
                  <a-form-item
                    label="Output scrap %" name="scrap_pct"
                    extra="Expected loss — raises what must be started"
                  >
                    <a-input-number v-model:value="form.scrap_pct" :min="0" :max="99" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="8">
                  <a-form-item label="Fixed overhead per run" name="overhead_cost">
                    <a-input-number v-model:value="form.overhead_cost" :min="0" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Status')" name="status">
                    <a-select v-model:value="form.status" :options="BOM_STATUSES" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item label=" " :colon="false">
                    <a-checkbox v-model:checked="form.is_default">
                      Default recipe for this product
                    </a-checkbox>
                    <div class="hint">Used when an order does not name a BOM. Only one per product.</div>
                  </a-form-item>
                </a-col>
                <a-col :span="24">
                  <a-form-item :label="$t('Note')" style="margin-bottom: 0">
                    <a-textarea v-model:value="form.notes" :rows="2" allow-clear />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>
          </a-card>

          <!-- components -->
          <a-card size="small" title="Components" style="margin-bottom: 16px">
            <template #extra>
              <a-button size="small" type="primary" @click="addLine">
                <template #icon><PlusOutlined /></template>
                Add component
              </a-button>
            </template>

            <a-empty v-if="!form.lines.length" :image="simpleImage" description="No components yet" />
            <a-table
              v-else size="small" :columns="lineColumns" :data-source="form.lines"
              row-key="key" :pagination="false" :scroll="{ x: 760 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'product_id'">
                  <a-select
                    v-model:value="record.product_id" show-search :filter-option="false" size="small"
                    style="width: 100%" :options="productOptions" placeholder="Search a product"
                    @search="searchProducts" @change="() => onComponentChange(record)"
                  />
                </template>
                <template v-else-if="column.key === 'qty'">
                  <a-input-number v-model:value="record.qty" :min="0" size="small" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'scrap_pct'">
                  <a-input-number v-model:value="record.scrap_pct" :min="0" :max="99" size="small" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'effective'">
                  {{ number(effectiveQty(record), 4) }}
                </template>
                <template v-else-if="column.key === 'cost'">
                  {{ money(lineCost(record)) }}
                </template>
                <template v-else-if="column.key === 'is_optional'">
                  <a-checkbox v-model:checked="record.is_optional" />
                </template>
                <template v-else-if="column.key === 'actions'">
                  <a-button type="text" size="small" danger @click="removeLine(record)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </template>
              </template>
            </a-table>
          </a-card>

          <!-- routing -->
          <a-card size="small" title="Routing">
            <template #extra>
              <a-button size="small" type="primary" @click="addOperation">
                <template #icon><PlusOutlined /></template>
                Add operation
              </a-button>
            </template>

            <a-empty
              v-if="!form.operations.length" :image="simpleImage"
              description="No routing — the BOM will cost materials only"
            />
            <a-table
              v-else size="small" :columns="operationColumns" :data-source="form.operations"
              row-key="key" :pagination="false" :scroll="{ x: 880 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'sequence'">
                  <a-input-number v-model:value="record.sequence" :min="1" size="small" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'name'">
                  <a-input v-model:value="record.name" size="small" placeholder="e.g. Cut to size" />
                </template>
                <template v-else-if="column.key === 'work_center_id'">
                  <a-select
                    v-model:value="record.work_center_id" size="small" style="width: 100%"
                    :options="workCenterOptions" allow-clear placeholder="Work centre"
                  />
                </template>
                <template v-else-if="column.key === 'setup_minutes'">
                  <a-input-number v-model:value="record.setup_minutes" :min="0" size="small" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'run_minutes_per_unit'">
                  <a-input-number v-model:value="record.run_minutes_per_unit" :min="0" size="small" style="width: 100%" />
                </template>
                <template v-else-if="column.key === 'total_minutes'">
                  {{ number(operationMinutes(record), 1) }}
                </template>
                <template v-else-if="column.key === 'requires_qc'">
                  <a-checkbox v-model:checked="record.requires_qc" />
                </template>
                <template v-else-if="column.key === 'actions'">
                  <a-button type="text" size="small" danger @click="removeOperation(record)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </template>
              </template>
            </a-table>
          </a-card>
        </a-col>

        <!-- live costing -->
        <a-col :xs="24" :xl="8">
          <a-card size="small" title="Cost per run" class="sticky">
            <ul class="costing">
              <li><span>Material</span><span>{{ money(costing.material) }}</span></li>
              <li><span>Labour</span><span>{{ money(costing.labour) }}</span></li>
              <li><span>Overhead</span><span>{{ money(costing.overhead) }}</span></li>
              <li class="total"><span>Total</span><span>{{ money(costing.total) }}</span></li>
              <li class="unit">
                <span>Per finished unit</span>
                <span>{{ money(costing.perUnit) }}</span>
              </li>
            </ul>

            <a-alert
              v-if="form.scrap_pct > 0" type="info" show-icon banner style="margin-top: 12px"
              :message="`With ${form.scrap_pct}% output scrap you must start ${number(grossOutput, 3)} to finish ${number(form.output_qty || 0, 3)}.`"
            />

            <a-divider style="margin: 14px 0 10px" />
            <div class="tally">
              <span>{{ form.lines.length }} component{{ form.lines.length === 1 ? '' : 's' }}</span>
              <span>{{ form.operations.length }} operation{{ form.operations.length === 1 ? '' : 's' }}</span>
              <span>{{ number(totalMinutes, 0) }} min per run</span>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * The BOM editor.
 *
 * Costing updates as you type, using the same arithmetic the server uses, so
 * the figure on screen is the figure an order will be planned against. The two
 * scrap fields are labelled separately because they mean different things:
 * output scrap raises what you must START, component scrap raises what you must
 * DRAW.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { SaveOutlined, PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { BOM_STATUSES } from './mrpOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const router = useRouter();
const { money, number } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const saving = ref(false);
const formRef = ref();

let rowKey = 0;
const form = reactive({
  code: '', name: '', product_id: undefined, output_qty: 1, version: 1,
  warehouse_id: undefined, status: 'draft', is_default: false,
  scrap_pct: 0, overhead_cost: 0, notes: '',
  lines: [], operations: [],
});

const products = ref([]);
const searching = ref(false);
const warehouses = ref([]);
const workCenters = ref([]);

const productOptions = computed(() => products.value.map(p => ({ value: p.id, label: p.label })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const workCenterOptions = computed(() => workCenters.value.map(c => ({ value: c.id, label: c.label })));

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  code: required(), name: required(), product_id: required(), output_qty: required(),
}));

// ---------------- lines ----------------

function addLine() {
  rowKey += 1;
  form.lines.push({ key: rowKey, product_id: undefined, qty: 1, scrap_pct: 0, is_optional: false, unit_cost: 0 });
}

function removeLine(record) {
  const i = form.lines.findIndex(l => l.key === record.key);
  if (i >= 0) form.lines.splice(i, 1);
}

/** Cache the cost so the sidebar does not need a lookup on every keystroke. */
function onComponentChange(record) {
  const product = products.value.find(p => p.id === record.product_id);
  record.unit_cost = product ? product.cost : 0;
}

function effectiveQty(record) {
  const qty = Number(record.qty) || 0;
  const scrap = Number(record.scrap_pct) || 0;
  return qty * (1 + scrap / 100);
}

function lineCost(record) {
  return effectiveQty(record) * (Number(record.unit_cost) || 0);
}

// ---------------- operations ----------------

function addOperation() {
  rowKey += 1;
  form.operations.push({
    key: rowKey, sequence: form.operations.length + 1, name: '',
    work_center_id: undefined, setup_minutes: 0, run_minutes_per_unit: 0, requires_qc: false,
  });
}

function removeOperation(record) {
  const i = form.operations.findIndex(o => o.key === record.key);
  if (i >= 0) form.operations.splice(i, 1);
}

/** Setup once per run plus run time per unit, adjusted for centre efficiency. */
function operationMinutes(record) {
  const setup = Number(record.setup_minutes) || 0;
  const per = Number(record.run_minutes_per_unit) || 0;
  const nominal = setup + per * (Number(form.output_qty) || 0);
  const centre = workCenters.value.find(c => c.id === record.work_center_id);
  const efficiency = centre?.efficiency_pct || 100;
  return (nominal * 100) / Math.max(1, efficiency);
}

// ---------------- costing ----------------

const grossOutput = computed(() => {
  const qty = Number(form.output_qty) || 0;
  const scrap = Math.min(99.9, Number(form.scrap_pct) || 0);
  return scrap > 0 ? qty / (1 - scrap / 100) : qty;
});

const totalMinutes = computed(
  () => form.operations.reduce((sum, op) => sum + operationMinutes(op), 0),
);

const costing = computed(() => {
  // Output scrap means starting more, so material scales by the gross figure.
  const factor = (Number(form.output_qty) || 0) > 0 ? grossOutput.value / (Number(form.output_qty) || 1) : 1;
  const material = form.lines.reduce((sum, l) => sum + lineCost(l), 0) * factor;

  let labour = 0;
  let overhead = 0;
  form.operations.forEach((op) => {
    const centre = workCenters.value.find(c => c.id === op.work_center_id);
    if (!centre) return;
    const hours = operationMinutes(op) / 60;
    labour += hours * (Number(centre.hourly_cost) || 0);
    overhead += hours * (Number(centre.overhead_rate) || 0);
  });

  overhead += Number(form.overhead_cost) || 0;
  const total = material + labour + overhead;
  const output = Number(form.output_qty) || 0;

  return {
    material, labour, overhead, total,
    perUnit: output > 0 ? total / output : 0,
  };
});

// ---------------- data ----------------

let searchTimer = null;
function searchProducts(term) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    searching.value = true;
    try {
      const res = await http.get('mrp/products', { search: term || '' });
      // Keep anything already chosen in the list, or the select shows a blank.
      const chosen = products.value.filter(p => p.id === form.product_id
        || form.lines.some(l => l.product_id === p.id));
      const fresh = res?.products || [];
      const seen = new Set(fresh.map(p => p.id));
      products.value = [...fresh, ...chosen.filter(p => !seen.has(p.id))];
    } catch (e) { /* leave the list as it was */ } finally {
      searching.value = false;
    }
  }, 250);
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error('Fill in the highlighted fields');
    return;
  }
  if (!form.lines.length) {
    message.error('A bill of materials needs at least one component');
    return;
  }
  if (form.lines.some(l => !l.product_id)) {
    message.error('Every component row needs a product');
    return;
  }

  saving.value = true;
  try {
    const payload = {
      ...form,
      lines: form.lines.map(l => ({
        product_id: l.product_id, qty: l.qty, scrap_pct: l.scrap_pct,
        is_optional: l.is_optional, notes: l.notes,
      })),
      operations: form.operations.filter(o => o.name).map(o => ({
        sequence: o.sequence, name: o.name, work_center_id: o.work_center_id,
        setup_minutes: o.setup_minutes, run_minutes_per_unit: o.run_minutes_per_unit,
        requires_qc: o.requires_qc, instructions: o.instructions,
      })),
    };

    if (isEdit.value) await http.put(`mrp/boms/${id.value}`, payload);
    else await http.post('mrp/boms', payload);

    message.success(t('Created_in_successfully', 'Saved successfully'));
    router.push('/mrp/boms');
  } catch (e) {
    message.error(firstError(e) || 'Could not save that bill of materials');
  } finally {
    saving.value = false;
  }
}

function firstError(e) {
  const errors = e?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e?.data?.message || '';
}

const lineColumns = [
  { title: 'Component', key: 'product_id', dataIndex: 'product_id', width: 260 },
  { title: 'Qty per run', key: 'qty', dataIndex: 'qty', width: 120 },
  { title: 'Scrap %', key: 'scrap_pct', dataIndex: 'scrap_pct', width: 100 },
  { title: 'To draw', key: 'effective', width: 110, align: 'right' },
  { title: 'Cost', key: 'cost', width: 110, align: 'right' },
  { title: 'Optional', key: 'is_optional', dataIndex: 'is_optional', width: 90, align: 'center' },
  { title: '', key: 'actions', width: 50 },
];

const operationColumns = [
  { title: '#', key: 'sequence', dataIndex: 'sequence', width: 70 },
  { title: 'Operation', key: 'name', dataIndex: 'name' },
  { title: 'Work centre', key: 'work_center_id', dataIndex: 'work_center_id', width: 200 },
  { title: 'Setup (min)', key: 'setup_minutes', dataIndex: 'setup_minutes', width: 110 },
  { title: 'Per unit (min)', key: 'run_minutes_per_unit', dataIndex: 'run_minutes_per_unit', width: 130 },
  { title: 'Per run', key: 'total_minutes', width: 90, align: 'right' },
  { title: 'QC', key: 'requires_qc', dataIndex: 'requires_qc', width: 60, align: 'center' },
  { title: '', key: 'actions', width: 50 },
];

async function bootstrap() {
  loadingRecord.value = true;
  try {
    const meta = await http.get('mrp/meta');
    warehouses.value = meta?.warehouses || [];
    workCenters.value = meta?.work_centers || [];

    await searchProductsNow('');

    if (isEdit.value) {
      const res = await http.get(`mrp/boms/${id.value}`);
      Object.assign(form, {
        code: res.bom.code, name: res.bom.name, product_id: res.bom.product_id,
        output_qty: res.bom.output_qty, version: res.bom.version,
        warehouse_id: res.bom.warehouse_id, status: res.bom.status,
        is_default: res.bom.is_default, scrap_pct: res.bom.scrap_pct,
        overhead_cost: res.bom.overhead_cost, notes: res.bom.notes,
      });

      form.lines = (res.lines || []).map((l) => {
        rowKey += 1;
        return {
          key: rowKey, product_id: l.product_id, qty: l.qty, scrap_pct: l.scrap_pct,
          is_optional: l.is_optional, notes: l.notes, unit_cost: l.unit_cost,
        };
      });
      form.operations = (res.operations || []).map((o) => {
        rowKey += 1;
        return {
          key: rowKey, sequence: o.sequence, name: o.name, work_center_id: o.work_center_id,
          setup_minutes: o.setup_minutes, run_minutes_per_unit: o.run_minutes_per_unit,
          requires_qc: o.requires_qc, instructions: o.instructions,
        };
      });

      // The saved product and components may not be in the first 50 search
      // results, so add them explicitly or the selects render empty.
      const known = new Set(products.value.map(p => p.id));
      const missing = [res.bom.product_id, ...(res.lines || []).map(l => l.product_id)]
        .filter(pid => pid && !known.has(pid));
      if (missing.length) {
        products.value = [
          ...products.value,
          ...(res.lines || [])
            .filter(l => missing.includes(l.product_id))
            .map(l => ({ id: l.product_id, label: `${l.product_code} — ${l.product_name}`, cost: l.unit_cost })),
        ];
        if (missing.includes(res.bom.product_id)) {
          products.value.push({
            id: res.bom.product_id,
            label: `${res.bom.product_code} — ${res.bom.product_name}`,
            cost: 0,
          });
        }
      }
    } else {
      addLine();
    }
  } catch (e) {
    message.error('Could not load that bill of materials');
    router.push('/mrp/boms');
  } finally {
    loadingRecord.value = false;
  }
}

async function searchProductsNow(term) {
  try {
    const res = await http.get('mrp/products', { search: term });
    products.value = res?.products || [];
  } catch (e) { /* leave empty */ }
}

onMounted(bootstrap);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.hint {
  font-size: 11.5px;
  opacity: 0.55;
  margin-top: 2px;
}
.sticky {
  position: sticky;
  top: 16px;
}
.costing {
  list-style: none;
  margin: 0;
  padding: 0;
}
.costing li {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 13px;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.18);
}
.costing li.total {
  font-weight: 600;
  font-size: 15px;
  border-bottom: none;
  padding-top: 10px;
}
.costing li.unit {
  border: none;
  color: #6d28d9;
  font-weight: 600;
}
.tally {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 12px;
  opacity: 0.65;
}
</style>
