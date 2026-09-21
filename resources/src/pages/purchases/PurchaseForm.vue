<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('EditPurchase') : $t('AddPurchase')"
      :breadcrumb="[$t('Purchases'), isEdit ? $t('EditPurchase') : $t('AddPurchase')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="purchase.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Supplier')" required>
              <div style="display: flex; gap: 8px">
                <a-select
                  v-model:value="purchase.supplier_id"
                  show-search option-filter-prop="label"
                  :placeholder="$t('Choose_Supplier')"
                  :options="supplierOptions"
                  style="flex: 1"
                />
                <a-tooltip :title="$t('Quick_Add_Supplier')">
                  <a-button @click="quickAddSupplierOpen = true">
                    <template #icon><PlusOutlined /></template>
                  </a-button>
                </a-tooltip>
              </div>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('warehouse')" required>
              <a-select
                v-model:value="purchase.warehouse_id"
                show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouseOptions"
                :disabled="isEdit"
                @change="onWarehouseChange"
              />
            </a-form-item>
          </a-col>
          <!-- Multi-Currency: entered/displayed amounts are in this currency;
               the model (and the payload) stays in the base currency. -->
          <a-col v-if="mcEnabled" :xs="24" :md="8">
            <a-form-item :label="$t('Currency')">
              <a-select
                v-model:value="docCurrencyId"
                show-search option-filter-prop="label"
                :options="currencyOptions"
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
            :disabled="!purchase.warehouse_id"
            @select="onProductPicked"
          />
          <ProductScanModal :disabled="!purchase.warehouse_id" @scan="onScan" />
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
            <template v-else-if="column.key === 'net_cost'">{{ docMoney(record.Net_cost) }}</template>
            <template v-else-if="column.key === 'stock'">
              {{ record.stock ?? '—' }} {{ record.unitPurchase }}
            </template>
            <template v-else-if="column.key === 'quantity'">
              <!-- No stock cap on purchases (buying adds stock) — legacy only
                   guards empty/zero at submit. -->
              <a-input-number
                :value="record.quantity"
                :min="0"
                style="width: 110px"
                @update:value="v => setQty(record, v)"
              />
            </template>
            <template v-else-if="column.key === 'discount'">{{ docMoney(record.DiscountNet * record.quantity) }}</template>
            <template v-else-if="column.key === 'tax'">{{ docMoney(record.taxe * record.quantity) }}</template>
            <template v-else-if="column.key === 'subtotal'">
              <strong>{{ docMoney(record.subtotal) }}</strong>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space>
                <a-tooltip :title="$t('Edit')">
                  <a-button type="text" size="small" @click="openLineEdit(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                </a-tooltip>
                <a-tooltip :title="$t('Del')">
                  <a-button type="text" size="small" danger @click="lines.splice(index, 1)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-tooltip>
              </a-space>
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
                  <a-input-number v-model:value="purchase.tax_rate" style="width: 100%" :min="0" :max="100" addon-after="%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Discount')">
                  <a-input-number v-model:value="discountInput" style="width: 100%" :min="0" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Shipping')">
                  <a-input-number v-model:value="shippingInput" style="width: 100%" :min="0" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Status')">
                  <a-select
                    v-model:value="purchase.statut"
                    :options="[
                      { value: 'received', label: $t('Received') },
                      { value: 'pending', label: $t('Pending') },
                      { value: 'ordered', label: $t('Ordered') },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Note')">
                  <a-textarea v-model:value="purchase.notes" :rows="2" :placeholder="$t('Afewwords')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="10">
          <a-card size="small">
            <div class="sum-row"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.total) }}</span></div>
            <div class="sum-row"><span>{{ $t('OrderTax') }}</span><span>{{ docMoney(totals.TaxNet) }} ({{ Number(purchase.tax_rate) || 0 }}%)</span></div>
            <div class="sum-row">
              <span>{{ $t('Discount') }}</span>
              <span style="color: #ff4d4f">- {{ docMoney(purchase.discount) }}</span>
            </div>
            <div class="sum-row"><span>{{ $t('Shipping') }}</span><span>{{ docMoney(purchase.shipping) }}</span></div>
            <div class="sum-row grand"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.GrandTotal) }}</span></div>
            <!-- When a foreign currency is active, keep the base total visible -->
            <div v-if="!dcIsBase" class="sum-row" style="border-bottom: none; font-size: 12px; color: rgba(128,128,128,.85)">
              <span>{{ $t('Base_Currency_Equivalent') }}</span><span>{{ moneyBase(totals.GrandTotal) }}</span>
            </div>
          </a-card>

          <!-- Legacy disables submit whenever a batch-tracked line lacks its
               batch rows, whatever the status. v1 has no batch entry UI, so
               those purchases stay on the legacy form. -->
          <a-button
            type="primary" block size="large" style="margin-top: 16px"
            :loading="submitting"
            :disabled="hasBatchValidationErrors"
            @click="submit"
          >
            {{ $t('submit') }}
          </a-button>
          <div v-if="hasBatchValidationErrors" class="form-warn">
            {{ $t('Select_Batch_Required_For') }}
            {{ lines.find(x => x.is_batch_tracked && (!(x.batches || []).length || Math.abs(batchTotalQty(x) - (Number(x.quantity) || 0)) > 0.0001))?.name }}
          </div>
          <a-button block style="margin-top: 8px" @click="$router.push('/purchases')">{{ $t('Cancel') }}</a-button>
        </a-col>
      </a-row>
    </a-form>

    <!-- Line edit modal -->
    <a-modal
      v-model:open="lineEditOpen"
      :title="editingLine?.name"
      :ok-text="$t('submit')"
      :ok-button-props="{ disabled: lineEditLoading }"
      @ok="applyLineEdit"
    >
      <!-- The unit list is fetched as the modal opens, so show a spinner until
           it lands rather than letting the unit field pop in afterwards. -->
      <div v-if="lineEditLoading" class="modal-loading"><a-spin /></div>
      <a-form v-else-if="editingLine" layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('ProductCost')">
              <a-input-number v-model:value="lineDraft.Unit_cost" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('TaxMethod')">
              <a-select
                v-model:value="lineDraft.tax_method"
                :options="[
                  { value: '1', label: 'Exclusive' },
                  { value: '2', label: 'Inclusive' },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Tax')">
              <a-input-number v-model:value="lineDraft.tax_percent" style="width: 100%" :min="0" :max="100" addon-after="%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Discount_Method') || 'Discount Method'">
              <a-select
                v-model:value="lineDraft.discount_Method"
                :options="[
                  { value: '2', label: $t('Fixed') },
                  { value: '1', label: $t('Percentage') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Discount')">
              <a-input-number v-model:value="lineDraft.discount" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col v-if="units.length" :span="12">
            <a-form-item :label="$t('UnitPurchase')">
              <a-select v-model:value="lineDraft.purchase_unit_id" :options="units.map(u => ({ value: u.id, label: u.name }))" />
            </a-form-item>
          </a-col>
          <a-col v-if="editingLine.is_imei" :span="24">
            <!-- Purchases CREATE serials — entry, not selection from stock. -->
            <a-form-item :label="$t('IMEI_SN')">
              <SerialEntry :line="editingLine" />
            </a-form-item>
          </a-col>
          <a-col v-if="editingLine.is_batch_tracked" :span="24">
            <!-- Purchases CREATE batches: batch no / dates / qty / cost rows. -->
            <a-form-item :label="$t('Batches')">
              <div v-for="(b, i) in editingLine.batches" :key="i" class="batch-entry-row">
                <a-input v-model:value="b.batch_no" :placeholder="$t('Batch_No')" style="width: 160px" />
                <a-date-picker v-model:value="b.mfg_date" value-format="YYYY-MM-DD" :placeholder="$t('Mfg_Date')" style="width: 140px" />
                <a-date-picker v-model:value="b.expiry_date" value-format="YYYY-MM-DD" :placeholder="$t('Expiry_Date')" style="width: 140px" />
                <a-input-number v-model:value="b.qty" :min="0" :placeholder="$t('Quantity')" style="width: 100px" />
                <a-input-number
                  :value="toDocAmt(b.unit_cost)"
                  :min="0" :placeholder="$t('UnitCost')" style="width: 110px"
                  @update:value="v => (b.unit_cost = toBaseAmt(v))"
                />
                <a-button type="text" size="small" danger @click="editingLine.batches.splice(i, 1)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </div>
              <div style="display: flex; align-items: center; gap: 12px">
                <a-button size="small" @click="addBatchRow(editingLine)">{{ $t('Add_Batch') }}</a-button>
                <span class="muted">
                  {{ $t('Total') }}: <strong>{{ batchTotalQty(editingLine) }}</strong> / {{ Number(editingLine.quantity) || 0 }}
                </span>
              </div>
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <QuickAddParty v-model:open="quickAddSupplierOpen" type="supplier" @created="onSupplierCreated" />
  </div>
</template>

<script setup>
/**
 * Purchase create/edit — stamped from SaleForm with the purchase contract:
 * - bootstrap GET purchases/create → {suppliers, warehouses}; edit
 *   GET purchases/{id}/edit → +purchase/details
 * - products GET get_Products_by_warehouse/{wid}?stock=0&product_service=0
 * - lines are COST-based (Unit_cost/Net_cost, taxe = tax_cost,
 *   unitPurchase/purchase_unit_id) — recomputeCostLine in lib/lineCalc.js
 * - totals: legacy Calcul_Total — fixed discount subtracted uncapped, no
 *   discount method, no points (computeSimpleTotals)
 * - validation = legacy exactly: NO stock checks anywhere (buying adds
 *   stock); submit checks AddProductToList + AddQuantity only; submit button
 *   disabled while a batch-tracked line has no batch rows (any status)
 * - POST purchases / PUT purchases/{id}; no payment section (payments are
 *   added from the list like legacy)
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import ProductScanModal from '../../components/ProductScanModal.vue';
import { resolveScan } from '../../lib/scanMatch';
import SerialEntry from '../../components/SerialEntry.vue';
import QuickAddParty from '../../components/QuickAddParty.vue';
import { useFormat } from '../../composables/useFormat';
import { useDocCurrency } from '../../composables/useDocCurrency';
import { recomputeCostLine, computeSimpleTotals } from '../../lib/lineCalc';
import { hasBatchEntryErrors, batchTotalQty } from '../../lib/batchValidation';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
// moneyBase: forms always show base-currency values as base, ignoring the
// report pages' display-currency selector.
const { moneyBase } = useFormat();

// Multi-Currency: display/input conversion only — the model stays base.
const {
  enabled: mcEnabled,
  currencyId: docCurrencyId,
  options: currencyOptions,
  isBase: dcIsBase,
  toDoc: toDocAmt,
  toBase: toBaseAmt,
  docMoney,
  loadCurrencies,
  setFromDocument: setDocCurrency,
  payloadFields: currencyPayload,
} = useDocCurrency();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);

const suppliers = ref([]);
const warehouses = ref([]);
const products = ref([]);
const lines = ref([]);
// `null`, never `undefined`: antd's Select falls back to its own internal
// selection whenever the bound value is undefined, so clearing it that way
// leaves the last picked product on screen instead of the placeholder.
const searchValue = ref(null);
let nextDetailId = 1;

const purchase = ref({
  date: dayjs().format('YYYY-MM-DD'),
  supplier_id: undefined,
  warehouse_id: undefined,
  statut: 'received',
  notes: '',
  tax_rate: 0,
  discount: 0,
  shipping: 0,
});

// Monetary inputs are typed in the document currency but stored in base.
// (The purchase-level discount is always a fixed amount.)
const discountInput = computed({
  get: () => toDocAmt(purchase.value.discount),
  set: (v) => { purchase.value.discount = toBaseAmt(v); },
});
const shippingInput = computed({
  get: () => toDocAmt(purchase.value.shipping),
  set: (v) => { purchase.value.shipping = toBaseAmt(v); },
});

const supplierOptions = computed(() => suppliers.value.map(s => ({ value: s.id, label: s.name })));

// Quick Add Supplier — legacy appends the new supplier and selects it inline.
const quickAddSupplierOpen = ref(false);
function onSupplierCreated(supplier) {
  suppliers.value.push(supplier);
  purchase.value.supplier_id = supplier.id;
}
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const productOptions = computed(() =>
  products.value.map((p, i) => ({ value: i, label: `${p.code} — ${p.name}` }))
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
    discount: purchase.value.discount,
    taxRate: purchase.value.tax_rate,
    shipping: purchase.value.shipping,
  }, recomputeCostLine)
);

// Legacy purchase rule: batches present AND total qty matching the line.
const hasBatchValidationErrors = computed(() => hasBatchEntryErrors(lines.value));

// First row is seeded with the line quantity, like legacy add_batch().
function addBatchRow(line) {
  if (!Array.isArray(line.batches)) line.batches = [];
  line.batches.push({
    batch_no: '',
    expiry_date: null,
    mfg_date: null,
    qty: line.batches.length === 0 ? (Number(line.quantity) || 0) : 0,
    unit_cost: Number(line.Unit_cost) || 0,
  });
}

const lineColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Cost'), key: 'net_cost', align: 'right' },
  { title: t('Stock'), key: 'stock', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('Discount'), key: 'discount', align: 'right' },
  { title: t('Tax'), key: 'tax', align: 'right' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
  { title: t('Action'), key: 'actions', width: 100, align: 'center' },
]);

async function loadProducts() {
  if (!purchase.value.warehouse_id) return;
  try {
    products.value = await http.get(
      `get_Products_by_warehouse/${purchase.value.warehouse_id}`,
      { stock: 0, product_service: 0 }
    ) || [];
  } catch (e) {
    products.value = [];
    message.error(t('InvalidData'));
  }
}

function onWarehouseChange() {
  lines.value = [];
  loadProducts();
}

// Camera / handheld scan: match the code exactly (code or barcode) and add the
// single matching product, as legacy's purchase search() did.
function onScan(code) {
  const hit = resolveScan(products.value, code);
  if (!hit) {
    message.warning(t('Product_Not_Found'));
    return;
  }
  onProductPicked(hit.idx);
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
    const d = await http.get(`show_product_data/${p.id}/${variantId}/${purchase.value.warehouse_id}`);
    const line = {
      detail_id: nextDetailId++,
      product_id: d.id,
      product_variant_id: p.product_variant_id ?? null,
      code: d.code || p.code,
      name: d.name,
      stock: d.qte ?? p.qte,
      quantity: 1,
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
      is_imei: d.is_imei,
      imei_number: '',
      serial_numbers: [],
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

function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = 0;
  line.quantity = qty;
  recomputeCostLine(line);
}

// ---------------- line edit modal ----------------

const lineEditOpen = ref(false);
const lineEditLoading = ref(false);
const editingLine = ref(null);
const lineDraft = ref({});
const units = ref([]);

async function openLineEdit(line) {
  editingLine.value = line;
  lineDraft.value = {
    // The modal is typed in the document currency (fixed amounts only —
    // percentages pass through); applyLineEdit converts back to base.
    Unit_cost: toDocAmt(line.Unit_cost),
    tax_method: String(line.tax_method || '1'),
    tax_percent: line.tax_percent,
    discount_Method: String(line.discount_Method || '2'),
    discount: String(line.discount_Method || '2') === '2' ? toDocAmt(line.discount) : line.discount,
    purchase_unit_id: line.purchase_unit_id,
  };
  units.value = [];
  lineEditLoading.value = true;
  lineEditOpen.value = true;
  try {
    const data = await http.get('get_units', { id: line.product_id });
    units.value = Array.isArray(data) ? data : data?.units || [];
  } catch (e) {
    /* unit select stays hidden */
  } finally {
    lineEditLoading.value = false;
  }
}

function applyLineEdit() {
  const line = editingLine.value;
  Object.assign(line, {
    Unit_cost: toBaseAmt(lineDraft.value.Unit_cost),
    tax_method: lineDraft.value.tax_method,
    tax_percent: Number(lineDraft.value.tax_percent) || 0,
    discount_Method: lineDraft.value.discount_Method,
    discount: String(lineDraft.value.discount_Method) === '2'
      ? toBaseAmt(lineDraft.value.discount)
      : (Number(lineDraft.value.discount) || 0),
    purchase_unit_id: lineDraft.value.purchase_unit_id,
    // imei_number is NOT copied from the draft — SerialEntry writes it (and
    // serial_numbers) straight onto the line, so a stale draft copy here would
    // wipe serials entered in this same modal.
  });
  recomputeCostLine(line);
  lineEditOpen.value = false;
}

// ---------------- submit ----------------

// Legacy verifiedForm: product list non-empty + no empty/zero quantities.
function validateForm() {
  if (!purchase.value.supplier_id || !purchase.value.warehouse_id) {
    message.warning(t('Please_fill_the_form_correctly'));
    return false;
  }
  if (!lines.value.length) {
    message.warning(t('AddProductToList'));
    return false;
  }
  if (lines.value.some(l => l.quantity === '' || Number(l.quantity) === 0)) {
    message.warning(t('AddQuantity'));
    return false;
  }
  // Legacy: serial count must equal quantity, but only once received.
  if (purchase.value.statut === 'received') {
    const bad = lines.value.find(serialCountMismatch);
    if (bad) {
      message.error(`${t('Serials_Count_Mismatch')} (${bad.name})`);
      return false;
    }
  }
  return true;
}

function serialCountMismatch(line) {
  if (!line?.is_imei) return false;
  const count = Array.isArray(line.serial_numbers) ? line.serial_numbers.length : 0;
  return count !== Math.round(Number(line.quantity) || 0);
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
    imei_number: l.imei_number || null,
    serial_numbers: l.is_imei && Array.isArray(l.serial_numbers) ? l.serial_numbers : [],
    // New batches created on receive — rows as entered.
    batches: l.is_batch_tracked && Array.isArray(l.batches) ? l.batches : [],
  }));
}

async function submit() {
  if (!validateForm()) return;
  submitting.value = true;
  const body = {
    date: purchase.value.date,
    supplier_id: purchase.value.supplier_id,
    warehouse_id: purchase.value.warehouse_id,
    statut: purchase.value.statut,
    notes: purchase.value.notes,
    tax_rate: Number(purchase.value.tax_rate) || 0,
    TaxNet: totals.value.TaxNet,
    discount: Number(purchase.value.discount) || 0,
    shipping: Number(purchase.value.shipping) || 0,
    GrandTotal: totals.value.GrandTotal,
    details: detailsPayload(),
    // Multi-Currency snapshot ({} when the module is off)
    ...currencyPayload(),
  };
  try {
    if (isEdit.value) {
      await http.put(`purchases/${id.value}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('purchases', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/purchases');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

// ---------------- bootstrap ----------------

onMounted(async () => {
  loadCurrencies();
  try {
    if (isEdit.value) {
      const data = await http.get(`purchases/${id.value}/edit`);
      suppliers.value = data.suppliers || [];
      warehouses.value = data.warehouses || [];
      const p = data.purchase || {};
      purchase.value = {
        date: p.date,
        supplier_id: p.supplier_id,
        warehouse_id: p.warehouse_id,
        statut: p.statut,
        notes: p.notes || '',
        tax_rate: Number(p.tax_rate) || 0,
        discount: Number(p.discount) || 0,
        shipping: Number(p.shipping) || 0,
      };
      // Reopen the purchase in its stored currency at its stored rate.
      setDocCurrency(p.currency_id, p.exchange_rate);
      lines.value = (data.details || []).map(d => {
        const line = {
          ...d,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: Array.isArray(d.batches) ? d.batches : [],
          // Serials may come back as a list or only as the comma-joined string.
          serial_numbers: Array.isArray(d.serial_numbers)
            ? d.serial_numbers
            : String(d.imei_number || '').split(',').map(s => s.trim()).filter(Boolean),
        };
        recomputeCostLine(line);
        return line;
      });
      await loadProducts();
    } else {
      const data = await http.get('purchases/create');
      suppliers.value = data.suppliers || [];
      warehouses.value = data.warehouses || [];
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/purchases');
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
/* Holds the modal body's height steady while its data loads, so swapping the
   spinner for the form doesn't resize the dialog. */
.modal-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 220px;
}
.batch-entry-row {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.form-warn {
  margin-top: 8px;
  color: #faad14;
  font-size: 13px;
  text-align: center;
}
</style>
