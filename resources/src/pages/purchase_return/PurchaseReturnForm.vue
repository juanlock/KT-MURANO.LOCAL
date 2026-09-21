<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('EditPurchaseReturn') : $t('CreatePurchaseReturn')"
      :breadcrumb="[$t('ListReturns'), isEdit ? $t('EditPurchaseReturn') : $t('CreatePurchaseReturn')]"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Purchase')">
              <a-input :value="form.purchase_ref" disabled />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Status')" required>
              <a-select
                v-model:value="form.statut"
                :placeholder="$t('Choose_Status')"
                :options="[
                  { value: 'completed', label: $t('complete') },
                  { value: 'pending', label: $t('Pending') },
                ]"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" style="margin-bottom: 16px">
        <template #title>{{ $t('list_product_returns') }} *</template>
        <a-alert v-if="!isEdit" type="error" show-icon :message="$t('products_refunded_alert')" style="margin-bottom: 12px" />

        <a-table
          :columns="columns" :data-source="details" :pagination="false"
          size="middle" :row-key="r => r.detail_id" :scroll="{ x: 'max-content' }"
          :row-class-name="r => (isRowLocked(r) ? 'row-locked' : '')"
          :row-expandable="serialRowExpandable"
          :default-expand-all-rows="true"
          :show-expand-column="showSerialColumn"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'product'">
              <div>{{ record.code }}</div>
              <a-tag color="success">{{ record.name }}</a-tag>
              <a-tooltip v-if="record.is_batch_tracked" :title="tf('Auto_FEFO_Hint', 'Oldest-expiring batches will be auto-allocated (FEFO) when this return is completed.')">
                <a-tag color="purple">{{ tf('Batches', 'Batches') }} · FEFO</a-tag>
              </a-tooltip>
            </template>
            <template v-else-if="column.key === 'Net_cost'">{{ money(record.Net_cost) }}</template>
            <template v-else-if="column.key === 'purchase_quantity'">
              <a-tag color="warning">{{ record.purchase_quantity }} {{ record.unitPurchase }}</a-tag>
            </template>
            <template v-else-if="column.key === 'stock'">
              <a-tag color="warning">{{ record.stock }} {{ record.unitPurchase }}</a-tag>
            </template>
            <template v-else-if="column.key === 'quantity'">
              <a-input-number
                :value="record.quantity" :min="0" style="width: 120px"
                :disabled="isRowLocked(record)"
                @update:value="v => setQty(record, v)"
              />
            </template>
            <template v-else-if="column.key === 'discount'">{{ money(record.DiscountNet * record.quantity) }}</template>
            <template v-else-if="column.key === 'tax'">{{ money(record.taxe * record.quantity) }}</template>
            <template v-else-if="column.key === 'subtotal'"><strong>{{ money(record.subtotal) }}</strong></template>
          </template>

          <template #expandedRowRender="{ record }">
            <!-- Create only: pick from the serials received on THIS purchase. -->
            <SerialPicker
              :line="record"
              fetch-url="serial_numbers/for_purchase"
              :fetch-params="{
                purchase_id: form.purchase_id,
                product_id: record.product_id,
                product_variant_id: record.product_variant_id || null,
              }"
            />
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
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('OrderTax')">
                  <a-input-number v-model:value="form.tax_rate" style="width: 100%" :min="0" addon-after="%" @change="recalc" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Discount')">
                  <a-input-number v-model:value="form.discount" style="width: 100%" :min="0" @change="recalc" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Shipping')">
                  <a-input-number v-model:value="form.shipping" style="width: 100%" :min="0" @change="recalc" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Please_provide_any_details')">
                  <a-textarea v-model:value="form.notes" :rows="4" :placeholder="$t('Afewwords')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="10">
          <a-card size="small">
            <div class="sum-row">
              <span>{{ $t('OrderTax') }}</span>
              <span>{{ money(form.TaxNet) }} ({{ Number(form.tax_rate) || 0 }} %)</span>
            </div>
            <div class="sum-row"><span>{{ $t('Discount') }}</span><span>{{ money(form.discount) }}</span></div>
            <div class="sum-row"><span>{{ $t('Shipping') }}</span><span>{{ money(form.shipping) }}</span></div>
            <div class="sum-row grand"><span>{{ $t('Total') }}</span><span>{{ money(grandTotal) }}</span></div>
          </a-card>

          <a-button type="primary" block size="large" style="margin-top: 16px" :loading="submitting" @click="submit">
            {{ $t('submit') }}
          </a-button>
          <a-button block style="margin-top: 8px" @click="$router.push('/purchase-returns')">{{ $t('Cancel') }}</a-button>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Purchase return create/edit — legacy create_purchase_return.vue +
 * edit_purchase_return.vue, which were identical apart from the branches below.
 *
 * Lines are FIXED by the source purchase (no product picker, no delete), so
 * only the return quantity — and serials on create — can change.
 *
 * - create: GET returns/purchase/create_purchase_return/{purchaseId}
 *           → POST returns/purchase
 * - edit:   GET returns/purchase/edit_purchase_return/{id}/{purchaseId}
 *           → PUT returns/purchase/{id}
 *
 * The quantity has TWO caps, checked in legacy's order: what was purchased,
 * then what is still in stock. On create a violation resets the line to 0; on
 * edit it reverts to `quantity_copy`, the quantity this return was saved with —
 * on edit the API's `stock` is already "stock as if this return were rolled
 * back", which is what makes that comparison meaningful.
 *
 * Totals follow legacy Calcul_Total exactly, including the per-line discount
 * being DISPLAY ONLY (it is already inside Net_cost).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import SerialPicker from '../../components/SerialPicker.vue';
import { useFormat } from '../../composables/useFormat';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const { t } = useI18n();
const { money, decimals } = useFormat();
const route = useRoute();
const router = useRouter();

const isEdit = !!route.params.id;
const returnId = route.params.id;
const purchaseId = route.params.purchaseId;

const loading = ref(true);
const submitting = ref(false);
const details = ref([]);
const grandTotal = ref(0);

const form = ref({
  date: new Date().toISOString().slice(0, 10),
  supplier_id: '', warehouse_id: '', purchase_id: '', purchase_ref: '',
  statut: 'completed', tax_rate: 0, TaxNet: 0, discount: 0, shipping: 0, notes: '',
});

const columns = computed(() => [
  { title: '#', dataIndex: 'detail_id', key: 'detail_id', width: 50 },
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Cost'), key: 'Net_cost', align: 'right' },
  { title: t('qty_purchased'), key: 'purchase_quantity', align: 'center' },
  { title: t('Current_stock'), key: 'stock', align: 'center' },
  { title: t('Qty_return'), key: 'quantity', align: 'center' },
  { title: t('Discount'), key: 'discount', align: 'right' },
  { title: t('Tax'), key: 'tax', align: 'right' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
]);

// Ant Design Vue has no `expandable` object prop (that is the React API), so
// these must be passed as flat props or they are silently ignored.
const serialRowExpandable = r => !isEdit && !!r.is_imei;
const showSerialColumn = computed(() => !isEdit && details.value.some(d => d.is_imei));

/** Edit-only: the product is gone from the warehouse, or has no purchase unit. */
function isRowLocked(row) {
  if (!isEdit) return false;
  return row.del === 1 || row.no_unit === 0;
}

/** Legacy Verified_Qty — purchased cap first, then remaining stock. */
function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = 1;
  // Create resets an invalid entry to zero; edit puts back the saved quantity.
  const fallback = isEdit ? (Number(line.quantity_copy) || 0) : 0;

  if (qty > Number(line.purchase_quantity)) {
    message.warning(t('qty_return_is_greater_than_qty_purchased'));
    qty = fallback;
  } else if (qty > Number(line.stock)) {
    message.warning(t('qty_return_is_greater_than_Quantity_Remaining'));
    qty = fallback;
  }
  line.quantity = qty;
  recalc();
}

/** Legacy Calcul_Total, formula for formula. */
function recalc() {
  let total = 0;
  for (const d of details.value) {
    const tax = (Number(d.taxe) || 0) * (Number(d.quantity) || 0);
    d.subtotal = parseFloat((Number(d.quantity) || 0) * (Number(d.Net_cost) || 0) + tax);
    total = parseFloat(total + d.subtotal);
  }
  const afterDiscount = parseFloat(total - (Number(form.value.discount) || 0));
  form.value.TaxNet = parseFloat((afterDiscount * (Number(form.value.tax_rate) || 0)) / 100);
  grandTotal.value = parseFloat(
    (afterDiscount + form.value.TaxNet + (Number(form.value.shipping) || 0)).toFixed(decimals.value)
  );
}

function serialCountMismatch(d) {
  if (!d?.is_imei) return false;
  const count = Array.isArray(d.serial_numbers) ? d.serial_numbers.length : 0;
  return count !== Math.round(Number(d.quantity) || 0);
}

function validate() {
  // Create only, and only once completed — legacy skips this while pending.
  if (!isEdit && form.value.statut === 'completed') {
    const bad = details.value.find(serialCountMismatch);
    if (bad) {
      message.error(`${t('Serials_Count_Mismatch')} (${bad.name})`);
      return false;
    }
  }
  if (!form.value.date || !form.value.statut) {
    message.error(t('Please_fill_the_form_correctly'));
    return false;
  }
  if (!details.value.length) {
    message.warning(t('AddProductToList'));
    return false;
  }
  if (!details.value.some(d => Number(d.quantity) !== 0)) {
    message.warning(t('Please_add_return_quantity'));
    return false;
  }
  return true;
}

async function submit() {
  if (!validate()) return;
  submitting.value = true;
  const body = {
    date: form.value.date,
    supplier_id: form.value.supplier_id,
    warehouse_id: form.value.warehouse_id,
    purchase_id: form.value.purchase_id,
    statut: form.value.statut,
    notes: form.value.notes,
    tax_rate: form.value.tax_rate || 0,
    TaxNet: form.value.TaxNet || 0,
    discount: form.value.discount || 0,
    shipping: form.value.shipping || 0,
    GrandTotal: grandTotal.value,
    details: details.value,
  };
  try {
    if (isEdit) {
      await http.put(`returns/purchase/${returnId}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('returns/purchase', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/purchase-returns');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    const url = isEdit
      ? `returns/purchase/edit_purchase_return/${returnId}/${purchaseId}`
      : `returns/purchase/create_purchase_return/${purchaseId}`;
    const data = await http.get(url);
    if (data?.purchase_return) form.value = { ...form.value, ...data.purchase_return };
    details.value = (data?.details || []).map(d => ({
      ...d,
      serial_numbers: Array.isArray(d.serial_numbers) ? d.serial_numbers : [],
    }));
    if (!isEdit) form.value.date = new Date().toISOString().slice(0, 10);
    recalc();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
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
:deep(.row-locked) {
  opacity: 0.55;
  text-decoration: line-through;
}
</style>
