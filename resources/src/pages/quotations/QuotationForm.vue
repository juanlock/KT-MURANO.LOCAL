<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('EditQuote') : $t('AddQuote')"
      :breadcrumb="[$t('Quotations'), isEdit ? $t('EditQuote') : $t('AddQuote')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="quote.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Customer')" required>
              <a-select
                v-model:value="quote.client_id"
                show-search option-filter-prop="label"
                :placeholder="$t('Choose_Customer')"
                :options="clientOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('warehouse')" required>
              <a-select
                v-model:value="quote.warehouse_id"
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
            :disabled="!quote.warehouse_id"
            @select="onProductPicked"
          />
          <ProductScanModal :disabled="!quote.warehouse_id" @scan="onScan" />
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
            <template v-else-if="column.key === 'net_price'">{{ docMoney(record.Net_price) }}</template>
            <template v-else-if="column.key === 'stock'">
              {{ record.stock ?? '—' }} {{ record.unitSale }}
            </template>
            <template v-else-if="column.key === 'quantity'">
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
                  <a-input-number v-model:value="quote.tax_rate" style="width: 100%" :min="0" :max="100" addon-after="%" />
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
                    v-model:value="quote.statut"
                    :options="[
                      { value: 'pending', label: $t('Pending') },
                      { value: 'sent', label: $t('Sent') },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Note')">
                  <a-textarea v-model:value="quote.notes" :rows="2" :placeholder="$t('Afewwords')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="10">
          <a-card size="small">
            <div class="sum-row"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.total) }}</span></div>
            <div class="sum-row"><span>{{ $t('OrderTax') }}</span><span>{{ docMoney(totals.TaxNet) }} ({{ Number(quote.tax_rate) || 0 }}%)</span></div>
            <div class="sum-row">
              <span>{{ $t('Discount') }}</span>
              <span style="color: #ff4d4f">- {{ docMoney(quote.discount) }}</span>
            </div>
            <div class="sum-row"><span>{{ $t('Shipping') }}</span><span>{{ docMoney(quote.shipping) }}</span></div>
            <div class="sum-row grand"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.GrandTotal) }}</span></div>
            <!-- When a foreign currency is active, keep the base total visible -->
            <div v-if="!dcIsBase" class="sum-row" style="border-bottom: none; font-size: 12px; color: rgba(128,128,128,.85)">
              <span>{{ $t('Base_Currency_Equivalent') }}</span><span>{{ moneyBase(totals.GrandTotal) }}</span>
            </div>
          </a-card>

          <a-button
            type="primary" block size="large" style="margin-top: 16px"
            :loading="submitting"
            @click="submit"
          >
            {{ $t('submit') }}
          </a-button>
          <a-button block style="margin-top: 8px" @click="$router.push('/quotations')">{{ $t('Cancel') }}</a-button>
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
            <a-form-item :label="$t('ProductPrice')">
              <a-input-number v-model:value="lineDraft.Unit_price" style="width: 100%" :min="0" />
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
            <a-form-item :label="$t('UnitSale')">
              <a-select v-model:value="lineDraft.sale_unit_id" :options="units.map(u => ({ value: u.id, label: u.name }))" />
            </a-form-item>
          </a-col>
          <a-col v-if="editingLine.is_imei" :span="24">
            <!-- Quotations don't reserve stock, so legacy takes serials as a
                 single free-text field rather than a picker or entry list. -->
            <a-form-item :label="$t('Add_product_IMEI_Serial_number')">
              <a-input
                v-model:value="lineDraft.imei_number"
                :placeholder="$t('Add_product_IMEI_Serial_number')"
              />
            </a-form-item>
          </a-col>
          <a-col v-if="editingLine.is_batch_tracked" :span="24">
            <a-form-item :label="$t('Batches')">
              <BatchAllocator :line="editingLine" :warehouse-id="quote.warehouse_id" endpoint="batches_for_quotation" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Quotation create/edit. Contracts:
 * - bootstrap GET quotations/create → {clients, warehouses,
 *   quotation_with_stock}; the setting gates BOTH the products query's stock
 *   param and the quantity clamps. Edit GET quotations/{id}/edit →
 *   +quote/details, products always loaded with stock=1, and the setting is
 *   never loaded — legacy edit has no stock clamps, so neither do we.
 * - line data: GET show_product_data/{pid}/{vid} — NO warehouse segment
 * - totals: computeSimpleTotals (uncapped fixed discount, like purchases)
 * - payload quirk kept verbatim: create POST sends `Discount` (capital D),
 *   edit PUT sends `discount`
 * - validation: AddProductToList + AddQuantity (+ legacy also gates on batch
 *   selection — v1 has no picker; quotation batches are optional metadata so
 *   batch-tracked lines submit with batches:[] exactly like a legacy user who
 *   never opened the picker could not — hence we keep legacy's block)
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import ProductScanModal from '../../components/ProductScanModal.vue';
import { resolveScan } from '../../lib/scanMatch';
import BatchAllocator from '../../components/BatchAllocator.vue';
import { useFormat } from '../../composables/useFormat';
import { useDocCurrency } from '../../composables/useDocCurrency';
import { recomputeLine, computeSimpleTotals } from '../../lib/lineCalc';
import { hasBatchSelectErrors, firstBatchSelectError } from '../../lib/batchValidation';
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

const clients = ref([]);
const warehouses = ref([]);
const products = ref([]);
const lines = ref([]);
// `null`, never `undefined`: antd's Select falls back to its own internal
// selection whenever the bound value is undefined, so clearing it that way
// leaves the last picked product on screen instead of the placeholder.
const searchValue = ref(null);
const quotationWithStock = ref('');
let nextDetailId = 1;

const quote = ref({
  date: dayjs().format('YYYY-MM-DD'),
  client_id: undefined,
  warehouse_id: undefined,
  statut: 'pending',
  notes: '',
  tax_rate: 0,
  discount: 0,
  shipping: 0,
});

// Monetary inputs are typed in the document currency but stored in base.
// (The quotation-level discount is always a fixed amount.)
const discountInput = computed({
  get: () => toDocAmt(quote.value.discount),
  set: (v) => { quote.value.discount = toBaseAmt(v); },
});
const shippingInput = computed({
  get: () => toDocAmt(quote.value.shipping),
  set: (v) => { quote.value.shipping = toBaseAmt(v); },
});

const clientOptions = computed(() => clients.value.map(c => ({ value: c.id, label: c.name })));
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
    discount: quote.value.discount,
    taxRate: quote.value.tax_rate,
    shipping: quote.value.shipping,
  }, recomputeLine)
);

const lineColumns = computed(() => [
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Price'), key: 'net_price', align: 'right' },
  { title: t('Stock'), key: 'stock', align: 'right' },
  { title: t('Quantity'), key: 'quantity', align: 'center' },
  { title: t('Discount'), key: 'discount', align: 'right' },
  { title: t('Tax'), key: 'tax', align: 'right' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
  { title: t('Action'), key: 'actions', width: 100, align: 'center' },
]);

async function loadProducts() {
  if (!quote.value.warehouse_id) return;
  try {
    // Legacy: create uses the quotation_with_stock setting; edit always stock=1.
    const stockParam = isEdit.value ? 1 : (quotationWithStock.value || 0);
    products.value = await http.get(
      `get_Products_by_warehouse/${quote.value.warehouse_id}`,
      { stock: stockParam, product_service: 1, product_combo: 1 }
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
// single matching product, as legacy's quotation search() did.
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
    // Quotation lines are priced without a warehouse segment, like legacy.
    const d = await http.get(`show_product_data/${p.id}/${variantId}`);
    const line = {
      detail_id: nextDetailId++,
      product_id: d.id,
      product_variant_id: p.product_variant_id ?? null,
      product_type: d.product_type,
      code: d.code || p.code,
      name: d.name,
      stock: p.qte ?? d.qte_sale,
      quantity: 1,
      Unit_price: d.Unit_price,
      Net_price: d.Net_price,
      discount: d.discount,
      discount_Method: d.discount_method,
      DiscountNet: d.DiscountNet,
      taxe: d.tax_price,
      tax_percent: d.tax_percent,
      tax_method: d.tax_method,
      unitSale: d.unitSale,
      sale_unit_id: d.sale_unit_id,
      is_imei: d.is_imei,
      imei_number: '',
      is_batch_tracked: !!d.is_batch_tracked,
      batches: [],
      subtotal: 0,
    };
    recomputeLine(line);
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

// Legacy Verified_Qty: clamps at stock ONLY when the quotation_with_stock
// setting is on (create; edit never loads the setting so it never clamps).
function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = Number(line.stock) || 0;
  if (!oversellingAllowed.value && !isEdit.value && quotationWithStock.value && qty > Number(line.stock)) {
    message.warning(t('LowStock'));
    qty = Number(line.stock) || 0;
  }
  line.quantity = qty;
  recomputeLine(line);
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
    Unit_price: toDocAmt(line.Unit_price),
    tax_method: String(line.tax_method || '1'),
    tax_percent: line.tax_percent,
    discount_Method: String(line.discount_Method || '2'),
    discount: String(line.discount_Method || '2') === '2' ? toDocAmt(line.discount) : line.discount,
    sale_unit_id: line.sale_unit_id,
    imei_number: line.imei_number,
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
    Unit_price: toBaseAmt(lineDraft.value.Unit_price),
    tax_method: lineDraft.value.tax_method,
    tax_percent: Number(lineDraft.value.tax_percent) || 0,
    discount_Method: lineDraft.value.discount_Method,
    discount: String(lineDraft.value.discount_Method) === '2'
      ? toBaseAmt(lineDraft.value.discount)
      : (Number(lineDraft.value.discount) || 0),
    sale_unit_id: lineDraft.value.sale_unit_id,
    imei_number: lineDraft.value.imei_number,
  });
  recomputeLine(line);
  lineEditOpen.value = false;
}

// ---------------- submit ----------------

function validateForm() {
  if (!quote.value.client_id || !quote.value.warehouse_id) {
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
  // Legacy verifiedForm also refuses batch-tracked lines without a selection.
  // Full legacy rule set (same as sales): allocation present + consistent.
  if (hasBatchSelectErrors(lines.value)) {
    message.warning(firstBatchSelectError(lines.value, t));
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
    sale_unit_id: l.sale_unit_id,
    Unit_price: l.Unit_price,
    Net_price: l.Net_price,
    discount: l.discount,
    discount_Method: String(l.discount_Method || '2'),
    DiscountNet: l.DiscountNet,
    taxe: l.taxe,
    tax_percent: l.tax_percent,
    tax_method: String(l.tax_method || '1'),
    subtotal: l.subtotal,
    imei_number: l.imei_number || null,
    batches: Array.isArray(l.batches) ? l.batches : [],
  }));
}

async function submit() {
  if (!validateForm()) return;
  submitting.value = true;
  const shared = {
    date: quote.value.date,
    client_id: quote.value.client_id,
    warehouse_id: quote.value.warehouse_id,
    statut: quote.value.statut,
    notes: quote.value.notes,
    tax_rate: Number(quote.value.tax_rate) || 0,
    TaxNet: totals.value.TaxNet,
    shipping: Number(quote.value.shipping) || 0,
    GrandTotal: totals.value.GrandTotal,
    details: detailsPayload(),
    // Multi-Currency snapshot ({} when the module is off)
    ...currencyPayload(),
  };
  try {
    if (isEdit.value) {
      // Legacy edit sends lowercase `discount`.
      await http.put(`quotations/${id.value}`, { ...shared, discount: Number(quote.value.discount) || 0 });
      message.success(t('Successfully_Updated'));
    } else {
      // Legacy create sends capital-D `Discount`.
      await http.post('quotations', { ...shared, Discount: Number(quote.value.discount) || 0 });
      message.success(t('Successfully_Created'));
    }
    router.push('/quotations');
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
      const data = await http.get(`quotations/${id.value}/edit`);
      clients.value = data.clients || [];
      warehouses.value = data.warehouses || [];
      const q = data.quote || {};
      quote.value = {
        date: q.date,
        client_id: q.client_id,
        warehouse_id: q.warehouse_id,
        statut: q.statut,
        notes: q.notes || '',
        tax_rate: Number(q.tax_rate) || 0,
        discount: Number(q.discount) || 0,
        shipping: Number(q.shipping) || 0,
      };
      // Reopen the quotation in its stored currency at its stored rate.
      setDocCurrency(q.currency_id, q.exchange_rate);
      lines.value = (data.details || []).map(d => {
        const line = {
          ...d,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: Array.isArray(d.batches) ? d.batches : [],
        };
        recomputeLine(line);
        return line;
      });
      await loadProducts();
    } else {
      const data = await http.get('quotations/create');
      clients.value = data.clients || [];
      warehouses.value = data.warehouses || [];
      quotationWithStock.value = data.quotation_with_stock || '';
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/quotations');
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
/* Holds the modal body's height steady while its data loads, so swapping the
   spinner for the form doesn't resize the dialog. */
.modal-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 220px;
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
