<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('CreateTransfer')"
      :breadcrumb="[$t('ListTransfers'), isEdit ? $t('Edit') : $t('CreateTransfer')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="transfer.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('FromWarehouse')" required>
              <a-select
                v-model:value="transfer.from_warehouse"
                show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouseOptions"
                :disabled="isEdit"
                @change="onFromWarehouseChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('ToWarehouse')" required>
              <a-select
                v-model:value="transfer.to_warehouse"
                show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')"
                :options="toWarehouseOptions"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; gap: 8px; margin-bottom: 16px">
          <a-select
            v-model:value="searchValue"
            show-search
            :filter-option="filterProduct"
            :placeholder="$t('Scan_Search_Product_by_Code_Name')"
            style="flex: 1"
            :options="productOptions"
            :disabled="!transfer.from_warehouse"
            @select="onProductPicked"
          />
          <ProductScanModal :disabled="!transfer.from_warehouse" @scan="onScan" />
        </div>

        <a-table
          :columns="lineColumns"
          :data-source="lines"
          :pagination="false"
          size="middle"
          :row-key="(r, i) => r.detail_id"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record, index }">
            <template v-if="column.key === 'product'">
              <div style="font-weight: 500">{{ record.name }}</div>
              <div class="muted">{{ record.code }}</div>
              <a-tag v-if="record.is_batch_tracked" color="warning" style="margin-top: 2px">Batch</a-tag>
            </template>
            <template v-else-if="column.key === 'net_cost'">{{ money(record.Net_cost) }}</template>
            <template v-else-if="column.key === 'stock'">{{ record.stock }} {{ record.unitPurchase }}</template>
            <template v-else-if="column.key === 'quantity'">
              <a-input-number
                :value="record.quantity"
                :min="0"
                style="width: 110px"
                @update:value="v => setQty(record, v)"
              />
            </template>
            <template v-else-if="column.key === 'subtotal'">
              <strong>{{ money(record.subtotal) }}</strong>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button type="text" size="small" danger @click="lines.splice(index, 1)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </template>
          </template>
          <template #emptyText>
            <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
          </template>
        </a-table>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="14">
          <a-card size="small" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('OrderTax')">
                  <a-input-number v-model:value="transfer.tax_rate" style="width: 100%" :min="0" :max="100" addon-after="%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Discount')">
                  <a-input-number v-model:value="transfer.discount" style="width: 100%" :min="0" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Shipping')">
                  <a-input-number v-model:value="transfer.shipping" style="width: 100%" :min="0" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Status')">
                  <a-select
                    v-model:value="transfer.statut"
                    :options="[
                      { value: 'completed', label: $t('complete') },
                      { value: 'sent', label: $t('Sent') },
                      { value: 'pending', label: $t('Pending') },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Note')">
                  <a-textarea v-model:value="transfer.notes" :rows="2" :placeholder="$t('Afewwords')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="10">
          <a-card size="small">
            <div class="sum-row"><span>{{ $t('Total') }}</span><span>{{ money(totals.total) }}</span></div>
            <div class="sum-row"><span>{{ $t('OrderTax') }}</span><span>{{ money(totals.TaxNet) }} ({{ Number(transfer.tax_rate) || 0 }}%)</span></div>
            <div class="sum-row">
              <span>{{ $t('Discount') }}</span>
              <span style="color: #ff4d4f">- {{ money(transfer.discount) }}</span>
            </div>
            <div class="sum-row"><span>{{ $t('Shipping') }}</span><span>{{ money(transfer.shipping) }}</span></div>
            <div class="sum-row grand"><span>{{ $t('Total') }}</span><span>{{ money(totals.GrandTotal) }}</span></div>
          </a-card>

          <a-button
            type="primary" block size="large" style="margin-top: 16px"
            :loading="submitting"
            @click="submit"
          >
            {{ $t('submit') }}
          </a-button>
          <a-button block style="margin-top: 8px" @click="$router.push('/transfers')">{{ $t('Cancel') }}</a-button>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Transfer create/edit. Contracts:
 * - bootstrap GET transfers/create → {warehouses, to_warehouses}; edit
 *   GET transfers/{id}/edit → +transfer/details
 * - products from the FROM warehouse: GET get_Products_by_warehouse/{wid}
 *   ?stock=1&product_service=0&product_combo=1; line stock = row qte
 * - lines are COST-based (show_product_data → Unit_cost/Net_cost);
 *   totals = computeSimpleTotals over recomputeCostLine
 * - validation = legacy: qty ALWAYS clamped at stock (LowStock + reset, no
 *   overselling switch); submit = AddProductToList → WarehouseIdentical →
 *   AddQuantity → batch gate (Select_Batch_Required_For, no picker in v1)
 * - payload NESTED under `transfer` key: POST/PUT transfers
 *   {transfer: {...header incl items}, details, GrandTotal}
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import ProductScanModal from '../../components/ProductScanModal.vue';
import { useFormat } from '../../composables/useFormat';
import { recomputeCostLine, computeSimpleTotals } from '../../lib/lineCalc';
import { resolveScan } from '../../lib/scanMatch';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const { money } = useFormat();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);

const warehouses = ref([]);
const toWarehouses = ref([]);
const products = ref([]);
const lines = ref([]);
// `null`, never `undefined`: antd's Select falls back to its own internal
// selection whenever the bound value is undefined, so clearing it that way
// leaves the last picked product on screen instead of the placeholder.
const searchValue = ref(null);
let nextDetailId = 1;

const transfer = ref({
  date: dayjs().format('YYYY-MM-DD'),
  from_warehouse: undefined,
  to_warehouse: undefined,
  statut: 'completed',
  notes: '',
  tax_rate: 0,
  discount: 0,
  shipping: 0,
});

const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const toWarehouseOptions = computed(() =>
  (toWarehouses.value.length ? toWarehouses.value : warehouses.value).map(w => ({ value: w.id, label: w.name }))
);
// Transfers move existing stock, so zero-stock products are hidden — unless
// the global Allow Overselling switch is on, which lifts stock limits in every
// transaction. Filtered here (not at fetch time) so it reacts to the async
// settings load; `value` keeps the ORIGINAL index into `products`.
const productOptions = computed(() =>
  products.value
    .map((p, i) => ({ p, i }))
    .filter(({ p }) => oversellingAllowed.value || Number(p.qte) > 0)
    .map(({ p, i }) => ({ value: i, label: `${p.code} — ${p.name} (${p.qte ?? ''})` }))
);

// A variant's label carries the VARIANT code, so also match the parent
// product's code — otherwise typing the main code finds none of its variants.
function filterProduct(input, option) {
  const p = products.value[option.value];
  return `${option.label} ${p?.product_code ?? ''}`
    .toLowerCase()
    .includes(String(input).toLowerCase());
}

const totals = computed(() =>
  computeSimpleTotals(lines.value, {
    discount: transfer.value.discount,
    taxRate: transfer.value.tax_rate,
    shipping: transfer.value.shipping,
  }, recomputeCostLine)
);

const lineColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Cost'), key: 'net_cost', align: 'right' },
  { title: t('Stock'), key: 'stock', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

async function loadProducts() {
  if (!transfer.value.from_warehouse) return;
  try {
    // Full list kept here; productOptions/onScan hide zero-stock rows unless
    // overselling is allowed (the endpoint's stock=1 clause passes them through).
    products.value = await http.get(
      `get_Products_by_warehouse/${transfer.value.from_warehouse}`,
      { stock: 1, product_service: 0, product_combo: 1 }
    ) || [];
  } catch (e) {
    products.value = [];
    message.error(t('InvalidData'));
  }
}

function onFromWarehouseChange() {
  lines.value = [];
  loadProducts();
}

// Camera / handheld scan: legacy transfer search() shares the sale form's
// weighing-scale handling, so match the same way and apply any embedded weight.
async function onScan(code) {
  const hit = resolveScan(products.value, code, { weighing: true });
  if (!hit) {
    message.warning(t('Product_Not_Found'));
    return;
  }
  const p = products.value[hit.idx];
  // Same rule as the search dropdown: no zero-stock scans unless overselling is on.
  if (p && !oversellingAllowed.value && !(Number(p.qte) > 0)) {
    message.warning(t('LowStock'));
    return;
  }
  await onProductPicked(hit.idx);
  if (hit.weight != null && p) {
    const line = lines.value.find(l => l.product_id === p.id);
    if (line) setQty(line, hit.weight);
  }
}

async function onProductPicked(idx) {
  searchValue.value = null;
  const p = products.value[idx];
  if (!p) return;
  const variantId = p.product_variant_id ?? 0;

  const existing = lines.value.find(
    l => l.product_id === p.id && (l.product_variant_id ?? 0) === (variantId || 0)
  );
  if (existing) {
    setQty(existing, Number(existing.quantity) + 1);
    return;
  }

  try {
    const d = await http.get(`show_product_data/${p.id}/${variantId}/${transfer.value.from_warehouse}`);
    const stock = Number(p.qte) || 0;
    const line = {
      detail_id: nextDetailId++,
      product_id: d.id,
      product_variant_id: p.product_variant_id ?? null,
      code: d.code || p.code,
      name: d.name,
      stock,
      quantity: stock < 1 ? stock : 1,
      Unit_cost: d.Unit_cost,
      Net_cost: d.Net_cost,
      discount: d.discount,
      discount_Method: d.discount_method,
      DiscountNet: d.DiscountNet,
      taxe: d.tax_cost,
      tax_percent: d.tax_percent,
      tax_method: d.tax_method,
      unitPurchase: d.unitPurchase,
      purchase_unit_id: d.purchase_unit_id,
      is_batch_tracked: !!d.is_batch_tracked,
      batches: [],
      subtotal: 0,
    };
    recomputeCostLine(line);
    lines.value.push(line);
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

// Global Allow Overselling (Settings > Features): when ON, stock clamps on
// every transaction form are bypassed.
const oversellingAllowed = ref(false);
http.get('get_pos_Settings')
  .then(d => { oversellingAllowed.value = !!d?.pos_settings?.allow_overselling; })
  .catch(() => {});

// Legacy Verified_Qty: NaN → stock; qty > stock warns LowStock and RESETS to
// stock — unless the global overselling switch is on.
function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = Number(line.stock) || 0;
  if (!oversellingAllowed.value && qty > Number(line.stock)) {
    message.warning(t('LowStock'));
    qty = Number(line.stock) || 0;
  }
  line.quantity = qty;
  recomputeCostLine(line);
}

// Same checks, same order as legacy verifiedForm.
function validateForm() {
  if (!transfer.value.from_warehouse || !transfer.value.to_warehouse) {
    message.warning(t('Please_fill_the_form_correctly'));
    return false;
  }
  if (!lines.value.length) {
    message.warning(t('AddProductToList'));
    return false;
  }
  if (transfer.value.from_warehouse === transfer.value.to_warehouse) {
    message.warning(t('WarehouseIdentical'));
    return false;
  }
  if (lines.value.some(l => l.quantity === '' || Number(l.quantity) === 0)) {
    message.warning(t('AddQuantity'));
    return false;
  }
  const missingBatch = lines.value.find(l => l.is_batch_tracked && !(l.batches || []).length);
  if (missingBatch) {
    message.warning(`${t('Select_Batch_Required_For')} ${missingBatch.name}`);
    return false;
  }
  return true;
}

function detailsPayload() {
  return lines.value.map(l => ({
    // Existing rows carry their detail id: the controller uses it to tell an
    // update from an insert, and to spot lines removed from the document.
    // Newly added lines have none, and null keeps them out of the old-id set.
    id: l.id ?? null,
    product_id: l.product_id,
    product_variant_id: l.product_variant_id ?? null,
    code: l.code,
    name: l.name,
    quantity: l.quantity,
    purchase_unit_id: l.purchase_unit_id,
    Unit_cost: l.Unit_cost,
    Net_cost: l.Net_cost,
    discount: l.discount,
    discount_Method: String(l.discount_Method || '2'),
    DiscountNet: l.DiscountNet,
    taxe: l.taxe,
    tax_percent: l.tax_percent,
    tax_method: String(l.tax_method || '1'),
    subtotal: l.subtotal,
    batches: Array.isArray(l.batches) && l.batches.length ? l.batches : [],
  }));
}

async function submit() {
  if (!validateForm()) return;
  submitting.value = true;
  const body = {
    // Legacy nests the header under `transfer` (unlike sales/purchases).
    transfer: {
      date: transfer.value.date,
      from_warehouse: transfer.value.from_warehouse,
      to_warehouse: transfer.value.to_warehouse,
      statut: transfer.value.statut,
      notes: transfer.value.notes,
      items: lines.value.length,
      tax_rate: Number(transfer.value.tax_rate) || 0,
      TaxNet: totals.value.TaxNet,
      discount: Number(transfer.value.discount) || 0,
      shipping: Number(transfer.value.shipping) || 0,
    },
    details: detailsPayload(),
    GrandTotal: totals.value.GrandTotal,
  };
  try {
    if (isEdit.value) {
      await http.put(`transfers/${id.value}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('transfers', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/transfers');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    if (isEdit.value) {
      const data = await http.get(`transfers/${id.value}/edit`);
      warehouses.value = data.warehouses || [];
      toWarehouses.value = data.to_warehouses || [];
      const tr = data.transfer || {};
      transfer.value = {
        date: tr.date,
        from_warehouse: tr.from_warehouse,
        to_warehouse: tr.to_warehouse,
        statut: tr.statut,
        notes: tr.notes || '',
        tax_rate: Number(tr.tax_rate) || 0,
        discount: Number(tr.discount) || 0,
        shipping: Number(tr.shipping) || 0,
      };
      lines.value = (data.details || []).map(d => {
        const line = {
          ...d,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: Array.isArray(d.batches) ? d.batches : [],
        };
        recomputeCostLine(line);
        return line;
      });
      await loadProducts();
    } else {
      const data = await http.get('transfers/create');
      warehouses.value = data.warehouses || [];
      toWarehouses.value = data.to_warehouses || [];
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/transfers');
    return;
  } finally {
    loadingRecord.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.sum-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
  font-size: 14px;
}
.sum-row.grand {
  border-bottom: none;
  border-top: 2px solid rgba(5, 5, 5, 0.15);
  font-weight: 700;
  font-size: 16px;
}
</style>
