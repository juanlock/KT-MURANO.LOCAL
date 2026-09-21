<template>
  <div class="page">
    <PageHeader
      :title="$t('Import_Purchases')"
      :breadcrumb="[$t('Purchases'), $t('Import_Purchases')]"
    >
      <template #extra>
        <a-button href="/import/exemples/import_purchases.csv" download>
          {{ $t('Download_exemple') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="16">
      <!-- Left: purchase header form -->
      <a-col :xs="24" :lg="10" style="margin-bottom: 16px">
        <a-card :title="$t('PurchaseDetails')" size="small">
          <a-form layout="vertical">
            <a-form-item :label="$t('date') + ' *'" v-bind="fieldProps('date')">
              <a-input v-model:value="purchase.date" type="date" />
            </a-form-item>

            <a-form-item :label="$t('Supplier') + ' *'" v-bind="fieldProps('supplier_id')">
              <a-select
                v-model:value="purchase.supplier_id"
                :placeholder="$t('Choose_Supplier')"
                :options="suppliers.map(s => ({ label: s.name, value: s.id }))"
                show-search
                option-filter-prop="label"
              />
            </a-form-item>

            <a-form-item :label="$t('warehouse') + ' *'" v-bind="fieldProps('warehouse_id')">
              <a-select
                v-model:value="purchase.warehouse_id"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                show-search
                option-filter-prop="label"
              />
            </a-form-item>

            <a-form-item :label="$t('Status') + ' *'" v-bind="fieldProps('statut')">
              <a-select
                v-model:value="purchase.statut"
                :placeholder="$t('Choose_Status')"
                :options="[
                  { label: 'received', value: 'received' },
                  { label: 'pending', value: 'pending' },
                  { label: 'ordered', value: 'ordered' },
                ]"
              />
            </a-form-item>

            <a-row :gutter="12" v-if="auth.can('edit_tax_discount_shipping_purchase')">
              <a-col :span="12">
                <a-form-item :label="$t('OrderTax')" v-bind="numFieldProps('tax_rate')">
                  <a-input v-model:value="purchase.tax_rate" addon-after="%" @keyup="keyupNum('tax_rate')" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item :label="$t('Discount')" v-bind="numFieldProps('discount')">
                  <a-input v-model:value="purchase.discount" :addon-after="auth.currency" @keyup="keyupNum('discount')" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Shipping')" v-bind="numFieldProps('shipping')">
                  <a-input v-model:value="purchase.shipping" :addon-after="auth.currency" @keyup="keyupNum('shipping')" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="purchase.notes" :rows="3" :placeholder="$t('Afewwords')" />
            </a-form-item>
          </a-form>
        </a-card>
      </a-col>

      <!-- Right: CSV upload + preview -->
      <a-col :xs="24" :lg="14">
        <a-card :title="$t('CSV_Import') || 'CSV Import'" size="small">
          <ImportDropzone
            ref="dz"
            :file="importFile"
            accept=".csv,text/csv"
            :title="$t('Click_Or_Drop_CSV') || 'Click to browse or drop your CSV file here'"
            :hint="$t('Accepted_Format_CSV') || 'Only .csv files are supported · semicolon (;) separator'"
            :disabled="SubmitProcessing"
            @file="handleFile"
            @clear="clearFile"
          />

          <div v-if="previewLoading" style="margin-top: 16px">
            <a-spin size="small" style="margin-right: 8px" />
            {{ $t('Parsing_CSV') || 'Parsing and validating CSV...' }}
          </div>

          <a-alert v-if="previewError" type="error" style="margin-top: 16px" show-icon :message="previewError" />

          <div v-if="previewRows.length" style="margin-top: 20px">
            <a-table
              :columns="previewColumns"
              :data-source="previewRows"
              size="small"
              :pagination="false"
              :scroll="{ x: true }"
              row-key="__k"
              :expanded-row-keys="expandedKeys"
              :expand-icon="() => null"
            >
              <template #bodyCell="{ column, record, index }">
                <template v-if="column.key === 'idx'">{{ index + 1 }}</template>
                <template v-else-if="column.key === 'code'">
                  <a-typography-text code>{{ record.code }}</a-typography-text>
                  <a-tag v-if="record.is_batch_tracked" color="purple" style="margin-left: 6px">{{ $t('Batches') || 'Batches' }}</a-tag>
                </template>
                <template v-else-if="column.key === 'qty'">
                  {{ number(record.qty, 2) }} <span style="color: #9ca3af; font-size: 11px">{{ record.unit }}</span>
                </template>
                <template v-else-if="column.key === 'cost'">{{ number(record.cost) }}</template>
                <template v-else-if="column.key === 'total'">
                  <strong>{{ number(record.total) }}</strong>
                </template>
              </template>

              <!-- Batch editor for batch-tracked rows (always expanded, like legacy) -->
              <template #expandedRowRender="{ record }">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px">
                  <span style="font-weight: 600">
                    {{ $t('Batches') || 'Batches' }}
                    <a-tag style="margin-left: 6px">{{ (record.batches || []).length }}</a-tag>
                  </span>
                  <a-button size="small" @click="addBatch(record)">+ {{ $t('Add') || 'Add' }}</a-button>
                </div>
                <a-empty v-if="!record.batches || record.batches.length === 0"
                  :description="$t('Click_Add_To_Start') || 'Click Add to create a batch'"
                  :image-style="{ height: '40px' }" />
                <a-table v-else
                  :columns="batchColumns" :data-source="record.batches"
                  size="small" :pagination="false" :row-key="(_b, i) => i"
                >
                  <template #bodyCell="{ column, record: b, index: bIdx }">
                    <template v-if="column.key === 'batch_no'">
                      <a-input size="small" v-model:value="b.batch_no" :placeholder="$t('Batch_No')" />
                    </template>
                    <template v-else-if="column.key === 'mfg_date'">
                      <a-input size="small" v-model:value="b.mfg_date" type="date" />
                    </template>
                    <template v-else-if="column.key === 'expiry_date'">
                      <a-input size="small" v-model:value="b.expiry_date" type="date" />
                    </template>
                    <template v-else-if="column.key === 'qty'">
                      <a-input size="small" style="text-align: right" inputmode="decimal" placeholder="0"
                        :value="b.qty" @input="e => onBatchNumberInput(b, 'qty', e.target.value)" />
                    </template>
                    <template v-else-if="column.key === 'unit_cost'">
                      <a-input size="small" style="text-align: right" inputmode="decimal" :placeholder="String(record.cost || '')"
                        :value="b.unit_cost" @input="e => onBatchNumberInput(b, 'unit_cost', e.target.value)" />
                    </template>
                    <template v-else-if="column.key === 'del'">
                      <a-button size="small" danger @click="removeBatch(record, bIdx)">×</a-button>
                    </template>
                  </template>
                </a-table>
                <a-alert v-if="batchQtyMismatch(record)" type="warning" show-icon style="margin-top: 8px"
                  :message="($t('Total_batch_qty_mismatch') || 'Total batch quantity does not match the line quantity')
                    + ' (' + number(batchTotalQty(record), 2) + ' / ' + number(record.qty, 2) + ')'" />
              </template>

              <template #footer>
                <div style="display: flex; justify-content: flex-end; gap: 12px; font-weight: 600">
                  <span>{{ $t('Subtotal') || 'Subtotal' }}</span>
                  <span>{{ number(previewSubtotal) }}</span>
                </div>
              </template>
            </a-table>

            <a-alert v-if="hasBatchValidationErrors" type="warning" style="margin-top: 12px" show-icon :message="firstBatchErrorDetail" />
          </div>

          <div class="submit-bar">
            <div style="flex: 1; font-size: 13px; color: #6b7280">
              <template v-if="previewRows.length">
                <strong style="color: #1f2937">{{ previewRows.length }}</strong>
                {{ $t('items_ready') || 'items ready to import' }} ·
                <strong style="color: #4f46e5">{{ number(previewSubtotal) }}</strong>
              </template>
              <template v-else>
                {{ $t('Upload_CSV_To_Preview') || 'Upload a CSV file to preview items before submitting' }}
              </template>
            </div>
            <a-button
              type="primary"
              :loading="SubmitProcessing"
              :disabled="SubmitProcessing || !previewRows.length || hasBatchValidationErrors"
              @click="submitPurchase"
            >
              {{ $t('submit') }}
            </a-button>
          </div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Import Purchases — header form + CSV preview + per-row batch entry.
 * Endpoints and ALL validation copied from legacy import_purchases.vue:
 * GET get_import_purchases, POST preview_import_purchases (key `products`),
 * POST store_import_purchases (FormData + batches_by_code JSON).
 * Legacy rules preserved verbatim: csv-only file check; required
 * date/supplier/warehouse/statut; ^\d*\.?\d*$ on tax/discount/shipping with
 * NaN→0 keyup resets; batch rules (no empty batch_no, qty>0, total vs line
 * qty tolerance 0.0001) gate the submit button exactly like legacy.
 */
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { notification } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import ImportDropzone from '../../components/ImportDropzone.vue';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { start as startProgress, done as doneProgress } from '../../lib/progress';

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const { number } = useFormat();

const isLoading = ref(true);
const SubmitProcessing = ref(false);
const warehouses = ref([]);
const suppliers = ref([]);
const importFile = ref(null);
const previewRows = ref([]);
const previewSubtotal = ref(0);
const previewLoading = ref(false);
const previewError = ref('');
const validated = ref(false);
const dz = ref();

const purchase = ref({
  date: new Date().toISOString().slice(0, 10),
  statut: 'received',
  notes: '',
  supplier_id: undefined,
  warehouse_id: undefined,
  tax_rate: 0,
  shipping: 0,
  discount: 0,
});

const NUM_RE = /^\d*\.?\d*$/;

// ---- header field validation (legacy vee rules: required + regex) ----
function fieldError(key) {
  if (!validated.value) return null;
  const v = purchase.value[key];
  if (v === '' || v === null || v === undefined) return t('Field_is_required');
  return null;
}
function numFieldError(key) {
  if (!validated.value) return null;
  const v = purchase.value[key];
  if (v === '' || v === null || v === undefined) return null;
  return NUM_RE.test(String(v)) ? null : t('InvalidData');
}
function fieldProps(key) {
  const err = fieldError(key);
  return err ? { validateStatus: 'error', help: err } : {};
}
function numFieldProps(key) {
  const err = numFieldError(key);
  return err ? { validateStatus: 'error', help: err } : {};
}
function headerValid() {
  return ['date', 'supplier_id', 'warehouse_id', 'statut'].every((k) => {
    const v = purchase.value[k];
    return !(v === '' || v === null || v === undefined);
  }) && ['tax_rate', 'discount', 'shipping'].every((k) => {
    const v = purchase.value[k];
    return v === '' || v === null || v === undefined || NUM_RE.test(String(v));
  });
}

function keyupNum(key) {
  if (isNaN(purchase.value[key]) || purchase.value[key] === '') {
    purchase.value[key] = 0;
  }
}

function toast(variant, msg, title) {
  notification[variant === 'danger' ? 'error' : 'success']({ message: title, description: msg });
}

// ---- file + preview ----
function handleFile(file) {
  const name = file.name || '';
  const ext = name.split('.').pop().toLowerCase();
  if (ext !== 'csv') {
    previewError.value = t('field_must_be_in_csv_format');
    importFile.value = null;
    previewRows.value = [];
    previewSubtotal.value = 0;
    return;
  }
  importFile.value = file;
  previewError.value = '';
  fetchPreview();
}

function clearFile() {
  importFile.value = null;
  previewRows.value = [];
  previewSubtotal.value = 0;
  previewError.value = '';
  if (dz.value) dz.value.resetInput();
}

async function fetchPreview() {
  if (!importFile.value) return;
  previewLoading.value = true;
  previewRows.value = [];
  previewSubtotal.value = 0;
  previewError.value = '';

  const formData = new FormData();
  formData.append('products', importFile.value);

  try {
    const resp = await uploadForm('preview_import_purchases', formData);
    previewLoading.value = false;
    const d = resp && resp.data && typeof resp.data === 'object' ? resp.data : {};
    if (resp.status >= 400 || d.status === false) {
      previewError.value = d.msg || t('CSV_Parse_Failed') || 'Failed to parse CSV';
      return;
    }
    const rows = Array.isArray(d.rows) ? d.rows : [];
    previewRows.value = rows.map((r, i) => Object.assign({}, r, { batches: [], __k: i }));
    previewSubtotal.value = Number(d.subtotal) || 0;
    if (!previewRows.value.length) {
      previewError.value = t('CSV_No_Valid_Rows') || 'No valid rows were found in the CSV file';
    }
  } catch (e) {
    previewLoading.value = false;
    previewError.value = t('CSV_Parse_Failed') || 'Failed to parse CSV';
  }
}

// ---- preview table (Ant) ----
const previewColumns = computed(() => [
  { title: '#', key: 'idx', width: 50 },
  { title: t('Code') || 'Code', key: 'code' },
  { title: t('product_name') || 'Product', dataIndex: 'name', key: 'name' },
  { title: t('Quantity'), key: 'qty', align: 'right' },
  { title: t('Cost'), key: 'cost', align: 'right' },
  { title: t('Subtotal') || 'Subtotal', key: 'total', align: 'right' },
]);
const batchColumns = computed(() => [
  { title: t('Batch_No') + ' *', key: 'batch_no' },
  { title: t('Mfg_Date'), key: 'mfg_date', width: 140 },
  { title: t('Expiry_Date'), key: 'expiry_date', width: 140 },
  { title: t('Quantity') + ' *', key: 'qty', width: 100, align: 'right' },
  { title: t('Cost'), key: 'unit_cost', width: 100, align: 'right' },
  { title: '', key: 'del', width: 50, align: 'center' },
]);
// Batch panels are always visible for batch-tracked rows, like legacy.
const expandedKeys = computed(() => previewRows.value.filter(r => r.is_batch_tracked).map(r => r.__k));

// ---- batches (legacy rules verbatim) ----
function addBatch(row) {
  if (!Array.isArray(row.batches)) row.batches = [];
  row.batches.push({ batch_no: '', expiry_date: null, mfg_date: null, qty: '', unit_cost: '' });
}
function removeBatch(row, idx) {
  if (Array.isArray(row.batches)) row.batches.splice(idx, 1);
}
function batchTotalQty(row) {
  if (!Array.isArray(row.batches)) return 0;
  return row.batches.reduce((sum, b) => {
    const n = Number(b.qty);
    return sum + (Number.isFinite(n) ? n : 0);
  }, 0);
}
function batchQtyMismatch(row) {
  const rowQty = Number(row.qty) || 0;
  const total = batchTotalQty(row);
  return Math.abs(total - rowQty) > 0.0001;
}
function onBatchNumberInput(batchRow, field, raw) {
  let s = raw == null ? '' : String(raw);
  s = s.replace(',', '.');
  s = s.replace(/[^0-9.]/g, '');
  const firstDot = s.indexOf('.');
  if (firstDot !== -1) {
    s = s.slice(0, firstDot + 1) + s.slice(firstDot + 1).replace(/\./g, '');
  }
  batchRow[field] = s;
}

const hasBatchValidationErrors = computed(() => {
  if (!Array.isArray(previewRows.value)) return false;
  for (const row of previewRows.value) {
    if (!row.is_batch_tracked) continue;
    const batches = Array.isArray(row.batches) ? row.batches : [];
    if (batches.length === 0) return true;
    if (batchQtyMismatch(row)) return true;
    for (const b of batches) {
      if (!b.batch_no || String(b.batch_no).trim() === '') return true;
      const q = Number(b.qty);
      if (!(q > 0)) return true;
    }
  }
  return false;
});

const firstBatchErrorDetail = computed(() => {
  if (!Array.isArray(previewRows.value)) return '';
  for (const row of previewRows.value) {
    if (!row.is_batch_tracked) continue;
    const batches = Array.isArray(row.batches) ? row.batches : [];
    if (batches.length === 0) {
      return (t('Batch_Required_For_Item') || 'Add at least one batch for') + ' ' + row.name;
    }
    for (const b of batches) {
      if (!b.batch_no || String(b.batch_no).trim() === '') {
        return (t('Batch_No_Required_For') || 'Batch No is required for') + ' ' + row.name;
      }
      const q = Number(b.qty);
      if (!(q > 0)) {
        return (t('Batch_Qty_Required_For') || 'Batch quantity must be greater than 0 for') + ' ' + row.name;
      }
    }
    if (batchQtyMismatch(row)) {
      return (t('Total_batch_qty_mismatch') || 'Total batch quantity does not match the line quantity') + ' — ' + row.name;
    }
  }
  return '';
});

// ---- submit (legacy order: form → file → rows) ----
function submitPurchase() {
  validated.value = true;
  if (!headerValid()) {
    toast('danger', t('Please_fill_the_form_correctly'), t('Failed'));
    return;
  }
  if (!importFile.value) {
    toast('danger', t('field_must_be_in_csv_format'), t('Failed'));
    return;
  }
  if (!previewRows.value.length) {
    toast('danger', t('CSV_No_Valid_Rows') || 'No valid rows were found in the CSV file', t('Failed'));
    return;
  }
  createPurchase();
}

async function createPurchase() {
  SubmitProcessing.value = true;
  startProgress();

  const data = new FormData();
  data.append('date', purchase.value.date);
  data.append('supplier_id', purchase.value.supplier_id);
  data.append('warehouse_id', purchase.value.warehouse_id);
  data.append('statut', purchase.value.statut);
  data.append('notes', purchase.value.notes);
  data.append('tax_rate', purchase.value.tax_rate);
  data.append('discount', purchase.value.discount);
  data.append('shipping', purchase.value.shipping);
  data.append('products', importFile.value);

  const batchesByCode = {};
  for (const row of previewRows.value) {
    if (!row.is_batch_tracked) continue;
    const cleaned = (row.batches || [])
      .filter((b) => b && b.batch_no && String(b.batch_no).trim() !== '' && Number(b.qty) > 0)
      .map((b) => ({
        batch_no: String(b.batch_no).trim(),
        expiry_date: b.expiry_date || null,
        mfg_date: b.mfg_date || null,
        qty: Number(b.qty),
        unit_cost: b.unit_cost === '' || b.unit_cost == null ? null : Number(b.unit_cost),
      }));
    if (cleaned.length) batchesByCode[row.code] = cleaned;
  }
  data.append('batches_by_code', JSON.stringify(batchesByCode));

  try {
    const resp = await uploadForm('store_import_purchases', data);
    if (resp.status >= 400) throw new Error('HTTP ' + resp.status);
    doneProgress();
    toast('success', t('Successfully_Imported'), t('Success'));
    SubmitProcessing.value = false;
    router.push('/purchases');
  } catch (e) {
    doneProgress();
    toast('danger', 'An error occurred while processing the CSV file.', t('Failed'));
    SubmitProcessing.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_import_purchases');
    suppliers.value = data.suppliers || [];
    warehouses.value = data.warehouses || [];
    isLoading.value = false;
  } catch (e) {
    setTimeout(() => { isLoading.value = false; }, 500);
  }
});
</script>

<style scoped>
.submit-bar { display: flex; align-items: center; gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
</style>
