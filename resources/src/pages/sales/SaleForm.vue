<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('EditSale') : $t('AddSale')"
      :breadcrumb="[$t('Sales'), isEdit ? $t('EditSale') : $t('AddSale')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date')" required>
              <a-date-picker v-model:value="sale.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Customer')" required>
              <div style="display: flex; gap: 8px">
                <a-select
                  v-model:value="sale.client_id"
                  show-search option-filter-prop="label"
                  :placeholder="$t('Choose_Customer')"
                  :options="clientOptions"
                  style="flex: 1"
                  @change="onClientChange"
                />
                <a-tooltip :title="$t('Quick_Add_Customer')">
                  <a-button @click="quickAddClientOpen = true">
                    <template #icon><PlusOutlined /></template>
                  </a-button>
                </a-tooltip>
              </div>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('warehouse')" required>
              <a-select
                v-model:value="sale.warehouse_id"
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

      <!-- Product search + lines -->
      <a-card size="small" style="margin-bottom: 16px">
        <div style="display: flex; gap: 8px; margin-bottom: 16px">
          <a-select
            v-model:value="searchValue"
            show-search
            :filter-option="filterProduct"
            :placeholder="$t('Scan_Search_Product_by_Code_Name')"
            style="flex: 1"
            :options="productOptions"
            :disabled="!sale.warehouse_id"
            @select="onProductPicked"
          />
          <ProductScanModal :disabled="!sale.warehouse_id" @scan="onScan" />
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
              <a-select
                :value="record.price_type"
                size="small"
                style="width: 100%; min-width: 150px; margin-top: 4px"
                :options="[
                  { value: 'retail', label: $t('Retail Price') },
                  { value: 'wholesale', label: $t('Wholesale Price') },
                ]"
                @change="v => onChangePriceType(record, v)"
              />
              <a-select
                v-if="record.packs && record.packs.length"
                :value="record.product_pack_id"
                size="small"
                style="width: 100%; min-width: 150px; margin-top: 4px"
                :placeholder="$t('Pack')"
                :options="packOptions(record)"
                @change="v => onChangePack(record, v)"
              />
              <div
                v-if="(Number(record.min_price) || 0) > 0 && Number(record.Net_price) < Number(record.min_price)"
                class="form-warn"
              >
                {{ $t('Price_below_min_not_allowed') }}
              </div>
            </template>
            <template v-else-if="column.key === 'net_price'">{{ docMoney(record.Net_price) }}</template>
            <template v-else-if="column.key === 'stock'">
              {{ record.stock }} {{ record.unitSale }}
            </template>
            <template v-else-if="column.key === 'quantity'">
              <!-- min 0 like legacy: a zero qty is caught at submit (AddQuantity) -->
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
                  <a-button type="text" size="small" danger @click="removeLine(index)">
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
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('OrderTax')">
                  <a-input-number
                    v-model:value="sale.tax_rate" style="width: 100%" :min="0" :max="100"
                    addon-after="%"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Discount')">
                  <a-input-number v-model:value="discountInput" style="width: 100%" :min="0" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Discount_Method') || 'Discount Method'">
                  <a-select
                    v-model:value="sale.discount_Method"
                    :options="[
                      { value: '2', label: $t('Fixed') },
                      { value: '1', label: $t('Percentage') },
                    ]"
                  />
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
                    v-model:value="sale.statut"
                    :options="[
                      { value: 'completed', label: $t('complete') },
                      { value: 'pending', label: $t('Pending') },
                      { value: 'ordered', label: $t('Ordered') },
                    ]"
                  />
                </a-form-item>
              </a-col>
              <!-- Sales agents belong to the Commissions module; hide the field
                   when the module is toggled off (Settings → Modules). -->
              <a-col v-if="auth.moduleEnabled('commissions')" :xs="24" :md="8">
                <a-form-item :label="$t('Sales_Agent') || 'Sales Agent'">
                  <a-select
                    v-model:value="sale.sales_agent_id" allow-clear show-search
                    option-filter-prop="label" :options="agentOptions"
                  />
                </a-form-item>
              </a-col>
              <!-- Change Salesperson (same opt-in feature as the POS picker) -->
              <a-col v-if="salesSwitchEnabled" :xs="24" :md="8">
                <a-form-item :label="$t('Seller')">
                  <a-select
                    v-model:value="sale.seller_id" allow-clear show-search
                    option-filter-prop="label" :options="salespersonOptions"
                    :placeholder="$t('Select_Salesperson')"
                  />
                </a-form-item>
              </a-col>
              <!-- Loyalty points -> discount. Legacy gates this on the customer
                   being royalty-eligible AND the tax/discount edit permission. -->
              <a-col v-if="clientIsEligible && auth.can('edit_tax_discount_shipping_sale')" :span="24">
                <a-form-item label="Points to convert">
                  <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                    <a-input-number
                      :value="points_to_convert"
                      :min="0"
                      :max="selectedClientPoints"
                      :step="1"
                      :disabled="selectedClientPoints === 0 || pointsConverted"
                      placeholder="e.g., 200"
                      style="width: 160px"
                      @update:value="onPointsToConvertInput"
                    />
                    <a-button
                      :type="pointsConverted ? 'default' : 'primary'"
                      :disabled="!pointsConverted && (selectedClientPoints === 0 || !pointsInputValid)"
                      @click="convertPointsToDiscount"
                    >
                      {{ pointsConverted ? 'Unconverted' : 'Convert' }}
                    </a-button>
                    <span class="muted">Total available: <strong>{{ selectedClientPoints }}</strong> pts</span>
                  </div>
                  <div v-if="!pointsConverted && points_to_convert && !pointsInputValid" class="form-warn">
                    Enter a value from 1 to your available points.
                  </div>
                  <div v-if="pointsState.discount_from_points > 0" style="margin-top: 4px; color: #52c41a">
                    Discount of <strong>{{ docMoney(pointsState.discount_from_points) }}</strong> will be applied
                  </div>
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Note')">
                  <a-textarea v-model:value="sale.notes" :rows="2" :placeholder="$t('Afewwords')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Payment (create + completed only, like legacy) -->
          <a-card v-if="!isEdit && sale.statut === 'completed'" size="small" :title="$t('Payment')" style="margin-bottom: 16px">
            <a-row :gutter="12">
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('PaymentStatus')">
                  <a-select
                    v-model:value="payment.status"
                    :options="[
                      { value: 'paid', label: $t('Paid') },
                      { value: 'partial', label: $t('partial') },
                      { value: 'pending', label: $t('Pending') },
                    ]"
                    @change="onPaymentStatusChange"
                  />
                </a-form-item>
              </a-col>
              <!-- One sale may be split across several methods (legacy
                   payment_lines); the first line is prefilled with the total. -->
              <a-col v-if="payment.status !== 'pending'" :span="24">
                <a-form-item :label="$t('Paymentchoice')">
                  <div v-for="(line, idx) in payment_lines" :key="'pay-' + idx" class="pay-line">
                    <a-select
                      v-model:value="line.payment_method_id"
                      :placeholder="$t('PleaseSelect')"
                      :options="paymentMethodOptions"
                      style="flex: 1 1 160px"
                    />
                    <a-input-number
                      :value="toDocAmt(line.amount)"
                      :min="0"
                      :placeholder="$t('Paying_Amount')"
                      style="flex: 1 1 140px"
                      @update:value="v => onPaymentLineInput(line, v)"
                    />
                    <a-select
                      v-model:value="line.account_id"
                      allow-clear
                      :placeholder="$t('Choose_Account')"
                      :options="accountOptions"
                      style="flex: 1 1 160px"
                    />
                    <a-button
                      v-if="payment_lines.length > 1"
                      danger type="text" :title="$t('Delete')"
                      @click="removePaymentLine(idx)"
                    >
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>

                  <a-button size="small" style="margin-top: 4px" @click="addPaymentLine">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Add_Payment_Method') }}
                  </a-button>

                  <div class="pay-summary">
                    <span><strong>{{ $t('Paying') }}:</strong> {{ docMoney(totalPaidLines) }}</span>
                    <span><strong>{{ $t('Balance') }}:</strong> {{ docMoney(paymentDue) }}</span>
                    <span><strong>{{ $t('Change') }}:</strong> {{ docMoney(paymentChange) }}</span>
                  </div>
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="10">
          <a-card size="small">
            <div class="sum-row"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.total) }}</span></div>
            <div class="sum-row"><span>{{ $t('OrderTax') }}</span><span>{{ docMoney(totals.TaxNet) }} ({{ Number(sale.tax_rate) || 0 }}%)</span></div>
            <div class="sum-row">
              <span>{{ $t('Discount') }}</span>
              <span style="color: #ff4d4f">- {{ docMoney(totals.discountAmount) }}</span>
            </div>
            <div class="sum-row"><span>{{ $t('Shipping') }}</span><span>{{ docMoney(sale.shipping) }}</span></div>
            <div class="sum-row grand"><span>{{ $t('Total') }}</span><span>{{ docMoney(totals.GrandTotal) }}</span></div>
            <!-- When a foreign currency is active, keep the base total visible -->
            <div v-if="!dcIsBase" class="sum-row" style="border-bottom: none; font-size: 12px; color: rgba(128,128,128,.85)">
              <span>{{ $t('Base_Currency_Equivalent') }}</span><span>{{ moneyBase(totals.GrandTotal) }}</span>
            </div>
          </a-card>

          <!-- Disabled conditions mirror each legacy form: create also gates
               on min price; edit only on batch errors. -->
          <a-button
            type="primary" block size="large" style="margin-top: 16px"
            :loading="submitting"
            :disabled="(!isEdit && hasMinPriceViolation) || (sale.statut === 'completed' && hasBatchValidationErrors)"
            @click="submit"
          >
            {{ $t('submit') }}
          </a-button>
          <div v-if="!isEdit && hasMinPriceViolation" class="form-warn">{{ $t('Price_below_min_not_allowed') }}</div>
          <div v-else-if="sale.statut === 'completed' && hasBatchValidationErrors" class="form-warn">
            {{ firstBatchSelectError(lines, $t) }}
          </div>
          <a-button block style="margin-top: 8px" @click="$router.push('/sales')">{{ $t('Cancel') }}</a-button>
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
            <a-form-item :label="$t('IMEI_SN')">
              <SerialPicker :line="editingLine" :warehouse-id="sale.warehouse_id" />
            </a-form-item>
          </a-col>
          <a-col v-if="editingLine.is_batch_tracked" :span="24">
            <a-form-item :label="$t('Batches')">
              <BatchAllocator :line="editingLine" :warehouse-id="sale.warehouse_id" endpoint="batches_for_sale" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <QuickAddParty v-model:open="quickAddClientOpen" type="client" @created="onClientCreated" />
  </div>
</template>

<script setup>
/**
 * Sale create/edit — v1 of the multi-line document form.
 * Contracts (all verified against SalesController + legacy form):
 * - bootstrap create: GET sales/create → clients/warehouses/sales_agents/
 *   accounts/payment_methods; edit: GET sales/{id}/edit → + sale/details
 * - products for a warehouse: GET get_Products_by_warehouse/{id}
 *   ?stock=1&is_sale=1&product_service=1&product_combo=1 (client-side filter)
 * - line data: GET show_product_data/{pid}/{vid}/{wid} — line stock is
 *   qte_sale, '---' for services; unit list GET get_units?id={product_id}
 * - math in lib/lineCalc.js, copied from legacy to the formula
 * - POST sales / PUT sales/{id} — details carry legacy field names verbatim;
 *   payment only on create when statut=completed (one payments[] line)
 *
 * v2: batch allocation (BatchAllocator ← batches_for_sale) and the serial
 * picker (SerialPicker ← serial_numbers/available) live in the line-edit
 * modal; validation matches legacy's hasBatchValidationErrors /
 * serialCountMismatch exactly (completed submits only).
 *
 * v3 closes the remaining parity gaps, all copied from legacy:
 * - retail/wholesale toggle + multi-pack selling per line (a non-default pack
 *   pins its own price and multiplies the stock a unit consumes)
 * - loyalty points → discount (GET get_points_client/{id}, rate from
 *   sales/create), kept separate from the manual discount
 * - credit-limit check at submit (GET clients/{id}/brief)
 * - split payment across several methods (payments[]), with the sale's amount
 *   summed from the lines and the surplus reported as change
 * - Quick Add Customer without leaving the page (QuickAddParty)
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import QuickAddParty from '../../components/QuickAddParty.vue';
import SerialPicker from '../../components/SerialPicker.vue';
import BatchAllocator from '../../components/BatchAllocator.vue';
import ProductScanModal from '../../components/ProductScanModal.vue';
import { useFormat } from '../../composables/useFormat';
import { useDocCurrency } from '../../composables/useDocCurrency';
import { useAuthStore } from '../../stores/auth';
import { recomputeLine, computeTotals } from '../../lib/lineCalc';
import { hasBatchSelectErrors, firstBatchSelectError } from '../../lib/batchValidation';
import { resolveScan } from '../../lib/scanMatch';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
// moneyBase: forms always show base-currency values as base, ignoring the
// report pages' display-currency selector.
const { moneyBase } = useFormat();
const auth = useAuthStore();

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
/**
 * Three modes. `convert` turns a quotation into a NEW sale, so it carries a
 * route :id like edit does but must submit down the create path — reading the
 * mode from route meta rather than inferring it from the presence of :id is
 * what keeps those two apart.
 */
const mode = computed(() => route.meta?.mode || (route.params.id ? 'edit' : 'create'));
const isEdit = computed(() => mode.value === 'edit');
const isConvert = computed(() => mode.value === 'convert');

const loadingRecord = ref(true);
const submitting = ref(false);

const clients = ref([]);
const warehouses = ref([]);
const agents = ref([]);
// Change Salesperson (same opt-in feature as the POS picker): attribution only,
// the sale's owner stays the logged-in user.
const salespeople = ref([]);
const salesSwitchEnabled = ref(false);

// New sales start credited to the authenticated user (matches the POS
// default); edit mode keeps whatever the sale already stores.
function defaultSellerToSelf() {
  if (!salesSwitchEnabled.value || sale.value.seller_id) return;
  const uid = Number(auth.user?.id);
  if (uid && salespeople.value.some(s => Number(s.id) === uid)) {
    sale.value.seller_id = uid;
  }
}
const accounts = ref([]);
const paymentMethods = ref([]);
const products = ref([]);
const lines = ref([]);
// `null`, never `undefined`: antd's Select falls back to its own internal
// selection whenever the bound value is undefined, so clearing it that way
// leaves the last picked product on screen instead of the placeholder.
const searchValue = ref(null);
let nextDetailId = 1;

// Overselling Control (get_pos_Settings.allow_overselling): when ON, every
// stock check on this page is bypassed — same switch legacy honours.
const posSettings = ref({});
const isOversellingAllowed = computed(() => !!posSettings.value?.allow_overselling);

const serialsOf = line =>
  Array.isArray(line.serial_numbers) && line.serial_numbers.length
    ? line.serial_numbers
    : String(line.imei_number || '').split(/[\r\n,;\t]+/).map(s => s.trim()).filter(Boolean);

const serialCountMismatch = line =>
  !!line.is_imei && serialsOf(line).length !== Math.round(Number(line.quantity) || 0);

// Legacy disables submit on these two, in addition to blocking in verifiedForm.
const hasMinPriceViolation = computed(() =>
  lines.value.some(l => (Number(l.min_price) || 0) > 0 && Number(l.Net_price) < Number(l.min_price))
);
// Full legacy rule set: allocation present, every row picked, qty > 0 and
// within availability, no duplicate batches, totals matching the line qty.
const hasBatchValidationErrors = computed(() => hasBatchSelectErrors(lines.value));

const sale = ref({
  date: dayjs().format('YYYY-MM-DD'),
  client_id: undefined,
  warehouse_id: undefined,
  sales_agent_id: undefined,
  seller_id: undefined,
  statut: 'completed',
  notes: '',
  tax_rate: 0,
  discount: 0,
  discount_Method: '2',
  shipping: 0,
});

// Monetary inputs are typed in the document currency but stored in base.
// Percentage discounts are unit-less and pass through untouched.
const discountInput = computed({
  get: () => (String(sale.value.discount_Method) === '2'
    ? toDocAmt(sale.value.discount)
    : Number(sale.value.discount) || 0),
  set: (v) => {
    sale.value.discount = String(sale.value.discount_Method) === '2'
      ? toBaseAmt(v)
      : (Number(v) || 0);
  },
});
const shippingInput = computed({
  get: () => toDocAmt(sale.value.shipping),
  set: (v) => { sale.value.shipping = toBaseAmt(v); },
});

// Preserved verbatim on edit — zeroing them would strip an existing
// loyalty-points discount from the sale.
const pointsState = ref({ discount_from_points: 0, used_points: 0 });

// Loyalty points: `point_to_amount_rate` comes from sales/create and converts
// points to currency. Available points drop as soon as a conversion is applied
// (display only — the backend deducts them on save), and `initialClientPoints`
// is what an "Unconverted" click restores.
const point_to_amount_rate = ref(0);
const selectedClientPoints = ref(0);
const initialClientPoints = ref(0);
const points_to_convert = ref(0);
const pointsConverted = ref(false);
const clientIsEligible = ref(false);

// Credit limit of the selected customer (0 = unlimited) and what they already
// owe — checked at submit when the sale is not paid in full.
const selectedClientCreditLimit = ref(0);
const selectedClientNetBalance = ref(0);

// Split payment: one row per method. Empty while the status is pending.
const payment_lines = ref([]);

const payment = ref({
  // Unpaid until the cashier explicitly records money — a new sale must not
  // default to fully paid.
  status: 'pending',
  payment_method_id: 2,
  amount: 0,
  received_amount: 0,
  account_id: undefined,
});

const pointsInputValid = computed(() => {
  const val = Number(points_to_convert.value);
  return Number.isInteger(val) && val >= 1 && val <= (Number(selectedClientPoints.value) || 0);
});

const totalPaidLines = computed(() =>
  payment_lines.value.reduce((sum, l) => sum + (Number(l.amount) || 0), 0));
const paymentDue = computed(() => {
  const due = Number(totals.value.GrandTotal || 0) - totalPaidLines.value;
  return due > 0 ? due : 0;
});
const paymentChange = computed(() => {
  const over = totalPaidLines.value - Number(totals.value.GrandTotal || 0);
  return over > 0 ? over : 0;
});

const clientOptions = computed(() => clients.value.map(c => ({ value: c.id, label: c.name })));

// Quick Add Customer — legacy appends the new client, selects it, then runs
// the same selection side effects as picking an existing one.
const quickAddClientOpen = ref(false);
function onClientCreated(client) {
  clients.value.push(client);
  sale.value.client_id = client.id;
  onClientChange(client.id);
}

/**
 * Legacy Selected_customer: every customer change resets any points already
 * converted AND the manual discount (legacy zeroes sale.discount and resets
 * the method to fixed), then reloads the new customer's points and credit.
 */
async function onClientChange(clientId) {
  pointsState.value = { discount_from_points: 0, used_points: 0 };
  selectedClientPoints.value = 0;
  initialClientPoints.value = 0;
  points_to_convert.value = 0;
  pointsConverted.value = false;
  clientIsEligible.value = false;
  sale.value.discount = 0;
  sale.value.discount_Method = '2';
  selectedClientCreditLimit.value = 0;
  selectedClientNetBalance.value = 0;
  if (!clientId) return;

  try {
    const data = await http.get(`get_points_client/${clientId}`);
    if (data?.is_royalty_eligible) {
      selectedClientPoints.value = Number(data.points) || 0;
      initialClientPoints.value = Number(data.points) || 0;
      clientIsEligible.value = true;
    }
  } catch (e) { /* points stay at zero */ }

  try {
    const brief = await http.get(`clients/${clientId}/brief`);
    selectedClientCreditLimit.value = parseFloat(brief?.credit_limit || 0);
    selectedClientNetBalance.value = parseFloat(brief?.netBalance || 0);
  } catch (e) { /* treated as no limit */ }
}

/** Legacy clamps the input to whole points within the available balance. */
function onPointsToConvertInput(value) {
  const max = Number(selectedClientPoints.value) || 0;
  let val = Number(value);
  if (!Number.isFinite(val) || val < 0) val = 0;
  val = Math.floor(val);
  if (val > max) {
    message.warning(t('Entered_points_exceed_available'));
    val = max;
  }
  points_to_convert.value = val;
}

/**
 * Toggle the points discount. Converting keeps the discount SEPARATE from
 * sale.discount (the manual field stays what the user typed) — computeTotals
 * applies both. Un-converting restores the displayed balance.
 */
function convertPointsToDiscount() {
  if (pointsConverted.value) {
    pointsState.value = { discount_from_points: 0, used_points: 0 };
    points_to_convert.value = 0;
    pointsConverted.value = false;
    if (Number.isFinite(initialClientPoints.value)) {
      selectedClientPoints.value = Number(initialClientPoints.value) || 0;
    }
    return;
  }

  const maxPoints = Number(selectedClientPoints.value) || 0;
  let pts = Number(points_to_convert.value);
  if (!Number.isFinite(pts) || pts <= 0) {
    message.warning(t('Please_enter_points_to_convert'));
    return;
  }
  if (pts > maxPoints) {
    message.warning(t('Entered_points_exceed_available'));
    pts = maxPoints;
  }
  points_to_convert.value = pts;
  pointsState.value = {
    discount_from_points: parseFloat((pts * point_to_amount_rate.value).toFixed(2)),
    used_points: pts,
  };
  pointsConverted.value = true;
  const baseAvail = Number(initialClientPoints.value || selectedClientPoints.value) || 0;
  selectedClientPoints.value = Math.max(0, baseAvail - pts);
}
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const agentOptions = computed(() => agents.value.map(a => ({ value: a.id, label: a.name })));
const salespersonOptions = computed(() =>
  salespeople.value.map(s => ({ value: s.id, label: s.name || s.username }))
);
const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: a.account_name })));
const paymentMethodOptions = computed(() => paymentMethods.value.map(m => ({ value: m.id, label: m.name })));

const productOptions = computed(() =>
  products.value.map((p, i) => ({
    value: i,
    label: `${p.code} — ${p.name}${p.qte !== undefined ? ` (${p.qte})` : ''}`,
  }))
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
  computeTotals(lines.value, {
    discount: sale.value.discount,
    discountMethod: sale.value.discount_Method,
    taxRate: sale.value.tax_rate,
    shipping: sale.value.shipping,
    discountFromPoints: pointsState.value.discount_from_points,
  })
);

// Keep "paid in full" aligned with the grand total, like legacy's GrandTotal
// watcher: a single line tracks the total, a manual split is left alone. A
// negative total forces the sale to pending, exactly as legacy does.
watch([totals, () => payment.value.status], () => {
  if (Number(totals.value.GrandTotal) < 0) {
    payment.value.status = 'pending';
    return;
  }
  if (payment.value.status === 'paid') {
    payment.value.amount = totals.value.GrandTotal;
    payment.value.received_amount = totals.value.GrandTotal;
    if (payment_lines.value.length === 1) {
      const gt = Number(totals.value.GrandTotal);
      payment_lines.value[0].amount = gt > 0 ? Number(gt.toFixed(2)) : 0;
    }
  }
});

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

// ---------------- products / lines ----------------

async function loadProducts() {
  if (!sale.value.warehouse_id) return;
  try {
    products.value = await http.get(
      `get_Products_by_warehouse/${sale.value.warehouse_id}`,
      { stock: 1, is_sale: 1, product_service: 1, product_combo: 1 }
    ) || [];
  } catch (e) {
    products.value = [];
    message.error(t('InvalidData'));
  }
}

function onWarehouseChange() {
  // Prices/stock are per-warehouse; existing lines would be stale.
  lines.value = [];
  loadProducts();
}

// Camera / handheld scan: match the code exactly as legacy search() did
// (weighing-scale 13-digit codes included) and add the product, applying the
// embedded weight as the quantity when the scale barcode carries one.
async function onScan(code) {
  const hit = resolveScan(products.value, code, { weighing: true });
  if (!hit) {
    message.warning(t('Product_Not_Found'));
    return;
  }
  const p = products.value[hit.idx];
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
    const d = await http.get(`show_product_data/${p.id}/${variantId}/${sale.value.warehouse_id}`);
    const line = {
      detail_id: nextDetailId++,
      product_id: d.id,
      product_variant_id: p.product_variant_id ?? null,
      product_type: d.product_type,
      code: d.code || p.code,
      name: d.name,
      stock: d.product_type !== 'is_service' ? d.qte_sale : '---',
      // Overselling: never seed the line with the (zero/negative) stock —
      // always start at 1 so the product can actually be sold.
      quantity: !isOversellingAllowed.value && d.qte_sale !== undefined && d.qte_sale < 1 ? d.qte_sale : 1,
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
      min_price: Number(d.min_price) || 0,
      is_imei: d.is_imei,
      imei_number: '',
      serial_numbers: [],
      is_batch_tracked: !!d.is_batch_tracked,
      batches: [],
      etat: 'new',
      qte_copy: 0,
      price_type: 'retail',
      // Immutable baselines the retail/wholesale switch reprices from.
      retail_unit_price: d.Unit_price,
      wholesale_unit_price: d.Unit_price_wholesale,
      packs: d.packs || [],
      product_pack_id: null,
      pack_multiplier: 1,
      pack_name: null,
      subtotal: 0,
    };
    // Multi-pack selling: preselect the default pack, like legacy.
    const defPack = (line.packs || []).find(pk => pk.is_default) || null;
    line.product_pack_id = defPack ? defPack.id : null;
    line.pack_name = defPack ? defPack.name : null;

    recomputeLine(line);
    // A wholesale-priced product that breaches its own minimum falls back to
    // retail before the line is ever shown.
    if (Number(line.Net_price) < Number(line.min_price || 0)) {
      line.price_type = 'retail';
      applyPriceType(line);
    }
    lines.value.push(line);
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

// Mirrors legacy Verified_Qty exactly — the two forms differ:
// - create: cap qty×pack_multiplier at stock (clamp floor(stock/mult)),
//   bypassed entirely when overselling is allowed
// - edit: STRICT (legacy edit ignores the overselling switch); lines already
//   on the sale (etat 'current') may go up to stock + qte_copy since their
//   sold quantity was deducted from stock — violation resets to qte_copy;
//   lines added during the edit behave like create ones (cap = stock)
// Services (stock '---') are never stock-checked.
function setQty(line, value) {
  let qty = Number(value);
  const stocked = line.stock !== '---' && line.product_type !== 'is_service';
  if (isEdit.value && line.etat === 'current') {
    if (Number.isNaN(qty)) qty = Number(line.qte_copy) || 0;
    if (stocked && qty > Number(line.stock) + (Number(line.qte_copy) || 0)) {
      message.warning(t('LowStock'));
      qty = Number(line.qte_copy) || 0;
    }
  } else {
    if (Number.isNaN(qty)) qty = Number(line.stock) || 0;
    const mult = Number(line.pack_multiplier) || 1;
    const bypass = !isEdit.value && isOversellingAllowed.value;
    if (!bypass && stocked && qty * mult > Number(line.stock)) {
      message.warning(t('LowStock'));
      qty = mult > 1 ? Math.floor(Number(line.stock) / mult) : Number(line.stock);
    }
  }
  line.quantity = qty;
  recomputeLine(line);
}

function removeLine(index) {
  lines.value.splice(index, 1);
}

// ---------------- price type + multi-pack selling ----------------

function getSelectedPack(line) {
  if (!line?.packs || !line.product_pack_id) return null;
  return line.packs.find(p => String(p.id) === String(line.product_pack_id)) || null;
}

/** A pack is unsellable when one pack alone would exceed the stock on hand. */
function packOutOfStock(line, pack) {
  return !isOversellingAllowed.value
    && line.product_type !== 'is_service'
    && Number(pack.multiplier) > 1
    && Number(pack.multiplier) > Number(line.stock);
}

function packOptions(line) {
  return (line.packs || []).map(pack => ({
    value: pack.id,
    label: `${pack.name} (x${pack.multiplier})${packOutOfStock(line, pack) ? ` — ${t('Out_of_Stock')}` : ''}`,
    disabled: packOutOfStock(line, pack),
  }));
}

/** Reprice from the retail/wholesale baseline captured at add time. */
function applyPriceType(line) {
  const wantsWholesale = line.price_type === 'wholesale';
  const wholesale = line.wholesale_unit_price;
  const retail = line.retail_unit_price;
  const hasWholesale = wholesale !== undefined && wholesale !== null && wholesale !== '';
  const hasRetail = retail !== undefined && retail !== null && retail !== '';

  if (wantsWholesale && hasWholesale) line.Unit_price = parseFloat(wholesale);
  else if (hasRetail) line.Unit_price = parseFloat(retail);

  recomputeLine(line);
}

/**
 * A non-default pack pins the price to the pack's own price and ignores the
 * retail/wholesale baseline; the default pack falls back to that baseline.
 * pack_multiplier / pack_name always stay in sync — the backend uses them for
 * the stock math.
 */
function applyPackPrice(line) {
  const pack = getSelectedPack(line);
  if (!pack || pack.is_default) {
    line.product_pack_id = pack ? pack.id : null;
    line.pack_multiplier = 1;
    line.pack_name = pack ? pack.name : null;
    applyPriceType(line);
    return;
  }
  line.pack_multiplier = pack.multiplier;
  line.pack_name = pack.name;
  line.Unit_price = parseFloat(pack.price) || 0;
  recomputeLine(line);
}

function onChangePriceType(line, newType) {
  if (newType) line.price_type = newType;

  const pack = getSelectedPack(line);
  if (pack && !pack.is_default) {
    // A fixed pack price wins over the retail/wholesale choice.
    applyPackPrice(line);
    return;
  }
  applyPriceType(line);
  if (Number(line.Net_price) < Number(line.min_price || 0)) {
    message.warning(t('Price_below_min_not_allowed'));
    line.price_type = 'retail';
    applyPriceType(line);
  }
}

function onChangePack(line, packId) {
  line.product_pack_id = packId;
  applyPackPrice(line);
  if (!isOversellingAllowed.value && line.product_type !== 'is_service') {
    const mult = Number(line.pack_multiplier) || 1;
    const avail = Number(line.stock) || 0;
    if (mult > 1 && mult > avail) {
      // Not even one pack fits in stock — fall back to the default pack.
      message.warning(t('Pack_Stock_Insufficient'));
      const def = (line.packs || []).find(p => p.is_default) || null;
      line.product_pack_id = def ? def.id : null;
      applyPackPrice(line);
    } else if (mult > 1 && Number(line.quantity) * mult > avail) {
      message.warning(t('LowStock'));
      line.quantity = Math.floor(avail / mult);
      recomputeLine(line);
    }
  }
}

// ---------------- split payment lines ----------------

/** Legacy defaults a new line to the cash method when one exists. */
function defaultPaymentLine(amount) {
  let methodId = 2;
  if (paymentMethods.value.length) {
    const cash = paymentMethods.value.find(m => m.name && m.name.toLowerCase().includes('cash'));
    methodId = cash ? cash.id : paymentMethods.value[0].id;
  }
  return { payment_method_id: methodId, amount: Number(amount) || 0, account_id: undefined };
}

function initPaymentLines(amount) {
  payment_lines.value = [defaultPaymentLine(amount)];
}

function addPaymentLine() {
  // Prefilled with what is still due, to speed up a split.
  payment_lines.value.push(defaultPaymentLine(paymentDue.value > 0 ? paymentDue.value : 0));
}

function removePaymentLine(idx) {
  if (payment_lines.value.length <= 1) return;
  payment_lines.value.splice(idx, 1);
}

function onPaymentLineInput(line, value) {
  // Typed in the document currency; stored in base like every other amount.
  const v = Number(value);
  line.amount = !Number.isFinite(v) || v < 0 ? 0 : toBaseAmt(v);
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
    // serial_numbers / imei_number / batches are managed by SerialPicker
    // and BatchAllocator, which mutate the line directly.
  });
  recomputeLine(line);
  lineEditOpen.value = false;
}

// ---------------- payment ----------------

function onPaymentStatusChange() {
  if (payment.value.status === 'paid') {
    payment.value.amount = totals.value.GrandTotal;
    // Prefill one line with the full total.
    initPaymentLines(Number(totals.value.GrandTotal) > 0 ? Number(totals.value.GrandTotal) : 0);
  } else if (payment.value.status === 'partial') {
    payment.value.amount = 0;
    // Start empty so the cashier types the part payment.
    initPaymentLines(0);
  } else {
    payment_lines.value = [];
  }
}

// ---------------- submit ----------------

// Same checks, same order, same messages as legacy. Create and edit differ:
// legacy edit's verifiedForm only checks empty quantities (no overstock or
// min-price re-check at submit — Verified_Qty already clamped input).
function validateForm() {
  // Name the missing field instead of the generic "fill the form" message.
  if (!sale.value.client_id) {
    message.warning(`${t('Customer')}: ${t('Field_is_required')}`);
    return false;
  }
  if (!sale.value.warehouse_id) {
    message.warning(`${t('warehouse')}: ${t('Field_is_required')}`);
    return false;
  }
  if (!lines.value.length) {
    message.warning(t('AddProductToList'));
    return false;
  }
  let emptyQty = 0;
  for (const l of lines.value) {
    if (l.quantity === '' || Number(l.quantity) === 0) {
      emptyQty += 1;
      continue;
    }
    if (isEdit.value) continue;
    const stocked = l.stock !== '---' && l.product_type !== 'is_service';
    if (!isOversellingAllowed.value && stocked && Number(l.quantity) > Number(l.stock)) {
      message.warning(t('LowStock'));
      return false;
    }
    if ((Number(l.min_price) || 0) > 0 && Number(l.Net_price) < Number(l.min_price)) {
      message.warning(t('Price_below_min_not_allowed'));
      return false;
    }
  }
  if (emptyQty > 0) {
    message.warning(t('AddQuantity'));
    return false;
  }
  if (Number(totals.value.GrandTotal) < 0) {
    message.warning(`${t('pos.Total_Payable')} ${t('cannot_be_negative')}`);
    return false;
  }
  // Batch allocation must be complete and consistent when completed —
  // messages come from legacy's firstBatchErrorMessage, key for key.
  if (sale.value.statut === 'completed' && hasBatchValidationErrors.value) {
    message.warning(firstBatchSelectError(lines.value, t));
    return false;
  }
  // Serial count must match quantity when completed (is_imei lines).
  if (sale.value.statut === 'completed') {
    const bad = lines.value.find(serialCountMismatch);
    if (bad) {
      message.warning(`${t('Serials_Count_Mismatch')} — ${bad.name || bad.code || ''}`);
      return false;
    }
  }
  // Payment lines are only validated when money is actually being recorded.
  if (!isEdit.value && payment.value.status !== 'pending' && sale.value.statut === 'completed') {
    if (!payment_lines.value.length || payment_lines.value.some(l => !l.payment_method_id)) {
      message.warning(t('PleaseSelect'));
      return false;
    }
    const totalPaid = totalPaidLines.value;
    if (totalPaid <= 0) {
      message.warning(`${t('Paying_Amount')}: ${t('Field_is_required')}`);
      return false;
    }
    // A split must not exceed the total; a SINGLE line may overpay, and the
    // surplus comes back as change.
    if (payment_lines.value.length > 1 && totalPaid > Number(totals.value.GrandTotal)) {
      message.warning(t('Paying_amount_is_greater_than_Grand_Total'));
      return false;
    }
    // Credit limit (0 = no limit) only bites when this sale adds new credit.
    if (sale.value.client_id && selectedClientCreditLimit.value > 0) {
      const saleTotal = parseFloat(totals.value.GrandTotal || 0);
      if (totalPaid < saleTotal) {
        const newTotalDue = parseFloat(selectedClientNetBalance.value || 0) + (saleTotal - totalPaid);
        if (newTotalDue > selectedClientCreditLimit.value) {
          const exceeded = newTotalDue - selectedClientCreditLimit.value;
          message.error(
            `${t('Credit_Limit_Exceeded')}: ${moneyBase(exceeded)} ${t('exceeds_credit_limit_of')} ${moneyBase(selectedClientCreditLimit.value)}`
          );
          return false;
        }
      }
    }
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
    product_type: l.product_type,
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
    serial_numbers: serialsOf(l),
    price_type: l.price_type || 'retail',
    product_pack_id: l.product_pack_id || null,
    pack_multiplier: Number(l.pack_multiplier) || 1,
    pack_name: l.pack_name || null,
    // Legacy strips helper fields and keeps only valid allocations,
    // stamping each with the line's unit price.
    batches: l.is_batch_tracked && Array.isArray(l.batches)
      ? l.batches
          .filter(b => b && b.product_batch_id && Number(b.qty) > 0)
          .map(b => ({
            product_batch_id: Number(b.product_batch_id),
            qty: Number(b.qty),
            unit_price: l.Unit_price,
          }))
      : [],
  }));
}

async function submit() {
  if (!validateForm()) return;
  submitting.value = true;

  const base = {
    date: sale.value.date,
    client_id: sale.value.client_id,
    warehouse_id: sale.value.warehouse_id,
    sales_agent_id: sale.value.sales_agent_id || null,
    // Always present (null clears the attribution back to the creator).
    seller_id: sale.value.seller_id || null,
    statut: sale.value.statut,
    notes: sale.value.notes,
    tax_rate: Number(sale.value.tax_rate) || 0,
    TaxNet: totals.value.TaxNet,
    discount: Number(sale.value.discount) || 0,
    discount_Method: String(sale.value.discount_Method || '2'),
    shipping: Number(sale.value.shipping) || 0,
    GrandTotal: totals.value.GrandTotal,
    details: detailsPayload(),
    discount_from_points: pointsState.value.discount_from_points,
    used_points: pointsState.value.used_points,
    // Multi-Currency snapshot ({} when the module is off)
    ...currencyPayload(),
  };

  try {
    if (isEdit.value) {
      await http.put(`sales/${id.value}`, base);
      message.success(t('Successfully_Updated'));
    } else {
      const effective = sale.value.statut === 'completed' ? payment.value.status : 'pending';
      // Legacy sends the summed payment lines as the sale's amount, not the
      // single `payment.amount` field.
      const paidLines = effective !== 'pending'
        ? payment_lines.value
            .filter(l => Number(l.amount) > 0)
            .map(l => ({
              amount: Number(l.amount) || 0,
              payment_method_id: l.payment_method_id,
              account_id: l.account_id || null,
            }))
        : [];
      const amount = effective === 'pending' ? 0 : totalPaidLines.value;
      await http.post('sales', {
        ...base,
        // Conversion: link the new sale back to its source quotation.
        ...(isConvert.value ? { quotation_id: id.value } : {}),
        // `payment` keeps the legacy single-payment shape (the first line
        // stands in for it); `payments` carries the full split.
        payment: {
          status: effective,
          payment_method_id: paidLines[0]?.payment_method_id ?? payment.value.payment_method_id,
          amount,
          received_amount: amount,
          account_id: paidLines[0]?.account_id ?? null,
        },
        payments: paidLines,
        amount: amount.toFixed(2),
        received_amount: amount.toFixed(2),
        change: paymentChange.value.toFixed(2),
      });
      message.success(t('Successfully_Created'));
    }
    router.push('/sales');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

// ---------------- bootstrap ----------------

async function loadPosSettings() {
  try {
    const data = await http.get('get_pos_Settings');
    posSettings.value = data?.pos_settings || {};
  } catch (e) { /* strict stock checks stay on */ }
}

onMounted(async () => {
  loadPosSettings();
  loadCurrencies();
  try {
    if (isConvert.value) {
      // The quotation payload carries the document + lines but NOT the
      // accounts / payment methods / points rate, which is why the legacy
      // converter's payment section threw the moment you touched it.
      const [conv, create] = await Promise.all([
        http.get(`convert_to_sale_data/${id.value}`),
        http.get('sales/create'),
      ]);
      clients.value = conv.clients || create.clients || [];
      warehouses.value = conv.warehouses || create.warehouses || [];
      agents.value = conv.sales_agents || create.sales_agents || [];
      salespeople.value = create.salespeople || [];
      salesSwitchEnabled.value = create.enable_pos_salesperson_switch === true;
      accounts.value = create.accounts || [];
      paymentMethods.value = create.payment_methods || [];
      point_to_amount_rate.value = Number(create.point_to_amount_rate) || 0;

      const q = conv.sale || {};
      sale.value = {
        // The quotation's own date and status carry over, as in legacy.
        date: q.date,
        client_id: q.client_id || undefined,
        warehouse_id: q.warehouse_id || undefined,
        sales_agent_id: q.sales_agent_id || undefined,
        seller_id: undefined,
        statut: q.statut || 'completed',
        notes: q.notes || '',
        tax_rate: Number(q.tax_rate) || 0,
        discount: Number(q.discount) || 0,
        discount_Method: '2',
        shipping: Number(q.shipping) || 0,
      };
      // The converted sale keeps the quotation's currency snapshot.
      setDocCurrency(q.currency_id, q.exchange_rate);
      defaultSellerToSelf();
      lines.value = (conv.details || []).map(d => {
        const line = {
          ...d,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: [],
          serial_numbers: [],
          price_type: d.price_type || 'retail',
          retail_unit_price: d.Unit_price,
          wholesale_unit_price: d.Unit_price_wholesale,
          packs: d.packs || [],
          product_pack_id: d.product_pack_id ?? null,
          pack_multiplier: d.pack_multiplier ?? 1,
          pack_name: d.pack_name ?? null,
        };
        recomputeLine(line);
        return line;
      });
      await loadProducts();
      // Status defaults to pending (unpaid): no payment line until the user
      // switches to paid/partial, which prefills via onPaymentStatusChange.
    } else if (isEdit.value) {
      const data = await http.get(`sales/${id.value}/edit`);
      clients.value = data.clients || [];
      warehouses.value = data.warehouses || [];
      agents.value = data.sales_agents || [];
      salespeople.value = data.salespeople || [];
      salesSwitchEnabled.value = data.enable_pos_salesperson_switch === true;
      const s = data.sale || {};
      const dm = String(s.discount_Method ?? '2').toLowerCase().trim();
      sale.value = {
        date: s.date,
        client_id: s.client_id,
        warehouse_id: s.warehouse_id,
        sales_agent_id: s.sales_agent_id || undefined,
        seller_id: s.seller_id || undefined,
        statut: s.statut,
        notes: s.notes || '',
        tax_rate: Number(s.tax_rate) || 0,
        discount: Number(s.discount) || 0,
        discount_Method: dm === '1' || dm === 'percent' || dm === 'percentage' ? '1' : '2',
        shipping: Number(s.shipping) || 0,
      };
      pointsState.value = {
        discount_from_points: Number(s.discount_from_points) || 0,
        used_points: Number(s.used_points) || 0,
      };
      // Reopen the sale in its stored currency at its stored rate.
      setDocCurrency(s.currency_id, s.exchange_rate);
      lines.value = (data.details || []).map(d => {
        const line = {
          ...d,
          detail_id: nextDetailId++,
          discount_Method: String(d.discount_Method ?? d.discount_method ?? '2'),
          tax_method: String(d.tax_method ?? '1'),
          is_batch_tracked: !!d.is_batch_tracked,
          batches: [],
          // Legacy edit prefills the picker from the stored IMEI text.
          serial_numbers: Array.isArray(d.serial_numbers)
            ? d.serial_numbers
            : (d.is_imei && d.imei_number
                ? String(d.imei_number).split(/[\r\n,;\t]+/).map(s => s.trim()).filter(Boolean)
                : []),
          price_type: d.price_type || 'retail',
          retail_unit_price: d.retail_unit_price ?? d.Unit_price,
          wholesale_unit_price: d.wholesale_unit_price ?? d.Unit_price_wholesale,
          packs: d.packs || [],
          product_pack_id: d.product_pack_id ?? null,
          pack_multiplier: d.pack_multiplier ?? 1,
          pack_name: d.pack_name ?? null,
        };
        recomputeLine(line);
        return line;
      });
      await loadProducts();
    } else {
      const data = await http.get('sales/create');
      clients.value = data.clients || [];
      warehouses.value = data.warehouses || [];
      agents.value = data.sales_agents || [];
      salespeople.value = data.salespeople || [];
      salesSwitchEnabled.value = data.enable_pos_salesperson_switch === true;
      defaultSellerToSelf();
      accounts.value = data.accounts || [];
      paymentMethods.value = data.payment_methods || [];
      point_to_amount_rate.value = Number(data.point_to_amount_rate) || 0;
      // Status defaults to pending (unpaid): no payment line until the user
      // switches to paid/partial, which prefills via onPaymentStatusChange.
    }
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/sales');
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
.form-warn {
  margin-top: 8px;
  color: #faad14;
  font-size: 13px;
  text-align: center;
}
.pay-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 8px;
  margin-bottom: 8px;
  border: 1px solid rgba(5, 5, 5, 0.1);
  border-radius: 6px;
}
.pay-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 24px;
  margin-top: 12px;
  font-size: 13px;
}
</style>
