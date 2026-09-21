<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('EditSaleReturn') : $t('CreateSaleReturn')"
      :breadcrumb="[$t('ListReturns'), isEdit ? $t('EditSaleReturn') : $t('CreateSaleReturn')]"
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
            <a-form-item :label="$t('Sale')">
              <a-input :value="form.sale_ref" disabled />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Status')" required>
              <a-select
                v-model:value="form.statut"
                :placeholder="$t('Choose_Status')"
                :options="[
                  { value: 'received', label: $t('Received') },
                  { value: 'pending', label: $t('Pending') },
                ]"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" style="margin-bottom: 16px">
        <template #title>{{ $t('list_product_returns') }} *</template>
        <!-- Create only: legacy warns that the listed lines are already refunded. -->
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
              <a-tag v-if="!isEdit && record.pack_name && Number(record.pack_multiplier) > 1" color="blue">
                {{ $t('Pack') }}: {{ record.pack_name }} (×{{ record.pack_multiplier }})
              </a-tag>
              <a-tooltip v-if="record.is_batch_tracked" :title="tf('Auto_Batch_Mirror_Hint', 'Original sale batches will be auto-credited proportionally when this return is received.')">
                <a-tag color="purple">{{ tf('Batches', 'Batches') }} · Auto</a-tag>
              </a-tooltip>
            </template>
            <template v-else-if="column.key === 'Net_price'">{{ money(record.Net_price) }}</template>
            <template v-else-if="column.key === 'sale_quantity'">
              <a-tag color="warning">{{ record.sale_quantity }} {{ record.unitSale }}</a-tag>
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
            <!-- Create only, and only for serialized lines: pick from the serials
                 sold on THIS sale, not from general stock. -->
            <SerialPicker
              :line="record"
              fetch-url="serial_numbers/for_sale"
              :fetch-params="{
                sale_id: form.sale_id,
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
          <a-button block style="margin-top: 8px" @click="$router.push('/sale-returns')">{{ $t('Cancel') }}</a-button>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Sale return create/edit — legacy create_sale_return.vue + edit_sale_return.vue,
 * which were byte-identical apart from the differences handled below.
 *
 * Lines are FIXED by the source sale: there is no product picker, and legacy's
 * delete-line handler was commented out of the UI, so only the return quantity
 * (and serials on create) can change.
 *
 * - create: GET returns/sale/create_sell_return/{saleId} → POST returns/sale
 * - edit:   GET returns/sale/edit_sell_return/{id}/{saleId} → PUT returns/sale/{id}
 *
 * Create-only, exactly as legacy: the date is forced to today, the pack badge
 * shows, serials are pickable (from serial_numbers/for_sale — the serials sold
 * on this sale, not free stock) and their count must equal the quantity when
 * the status is 'received'. Edit-only: rows whose product no longer exists in
 * the warehouse are locked.
 *
 * Totals follow legacy Calcul_Total exactly, including that the per-line
 * discount is DISPLAY ONLY — it is already baked into Net_price server-side,
 * so subtracting it again here would double-count.
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
const saleId = route.params.saleId;

const loading = ref(true);
const submitting = ref(false);
const details = ref([]);
const grandTotal = ref(0);

const form = ref({
  date: new Date().toISOString().slice(0, 10),
  client_id: '', warehouse_id: '', sale_id: '', sale_ref: '',
  statut: 'received', tax_rate: 0, TaxNet: 0, discount: 0, shipping: 0, notes: '',
});

const columns = computed(() => [
  { title: '#', dataIndex: 'detail_id', key: 'detail_id', width: 50 },
  { title: t('ProductName'), key: 'product' },
  { title: t('Net_Unit_Price'), key: 'Net_price', align: 'right' },
  { title: t('Quantity_sold'), key: 'sale_quantity', align: 'center' },
  { title: t('Qty_return'), key: 'quantity', align: 'center' },
  { title: t('Discount'), key: 'discount', align: 'right' },
  { title: t('Tax'), key: 'tax', align: 'right' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
]);

/**
 * Serial rows: only serialized lines get one, only on create (legacy edit has
 * no serial UI at all), and they start open because legacy rendered the panel
 * inline rather than behind a toggle.
 */
// Ant Design Vue has no `expandable` object prop (that is the React API), so
// these must be passed as flat props or they are silently ignored.
const serialRowExpandable = r => !isEdit && !!r.is_imei;
const showSerialColumn = computed(() => !isEdit && details.value.some(d => d.is_imei));

/**
 * Legacy locked a row when the product is gone from the warehouse (`del`) or
 * has no sale unit — services excepted, since they carry no unit by design.
 * Create never locked anything.
 */
function isRowLocked(row) {
  if (!isEdit) return false;
  return row.del === 1 || (row.no_unit === 0 && row.product_type !== 'is_service');
}

/** Legacy Verified_Qty: clamp to what was sold, warn, and recalculate. */
function setQty(line, value) {
  let qty = Number(value);
  if (Number.isNaN(qty)) qty = 1;
  if (qty > Number(line.sale_quantity)) {
    message.warning(t('qty_return_is_greater_than_qty_sold'));
    qty = Number(line.sale_quantity);
  }
  line.quantity = qty;
  recalc();
}

/** Legacy Calcul_Total, formula for formula. */
function recalc() {
  let total = 0;
  for (const d of details.value) {
    const tax = (Number(d.taxe) || 0) * (Number(d.quantity) || 0);
    d.subtotal = parseFloat((Number(d.quantity) || 0) * (Number(d.Net_price) || 0) + tax);
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
  // Create only, and only once received — legacy skips this while pending.
  if (!isEdit && form.value.statut === 'received') {
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
  // At least one line must actually be returned (legacy counted non-zero rows).
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
    client_id: form.value.client_id,
    sale_id: form.value.sale_id,
    warehouse_id: form.value.warehouse_id,
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
      await http.put(`returns/sale/${returnId}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('returns/sale', body);
      message.success(t('Successfully_Created'));
    }
    router.push('/sale-returns');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    const url = isEdit
      ? `returns/sale/edit_sell_return/${returnId}/${saleId}`
      : `returns/sale/create_sell_return/${saleId}`;
    const data = await http.get(url);
    if (data?.sale_return) form.value = { ...form.value, ...data.sale_return };
    details.value = (data?.details || []).map(d => ({
      ...d,
      // Create's payload carries the pack snapshot and serials; the create
      // endpoint omits them, so they are defaulted here as legacy did.
      serial_numbers: Array.isArray(d.serial_numbers) ? d.serial_numbers : [],
      product_pack_id: d.product_pack_id ?? null,
      pack_multiplier: d.pack_multiplier ?? 1,
      pack_name: d.pack_name ?? null,
    }));
    // Create stamps today; edit keeps the stored date.
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
