<template>
  <div class="page">
    <PageHeader
      :title="$t('Import_Sales')"
      :breadcrumb="[$t('Sales'), $t('Import_Sales')]"
    >
      <template #extra>
        <a-button href="/import/exemples/import_sales.csv" download>
          {{ $t('Download_exemple') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="16">
      <!-- Left: sale header form -->
      <a-col :xs="24" :lg="10" style="margin-bottom: 16px">
        <a-card :title="$t('SaleDetails') || 'Sale Details'" size="small">
          <a-form layout="vertical">
            <a-form-item :label="$t('date') + ' *'" v-bind="fieldProps('date')">
              <a-input v-model:value="sale.date" type="date" />
            </a-form-item>

            <a-form-item :label="$t('Customer') + ' *'" v-bind="fieldProps('client_id')">
              <a-select
                v-model:value="sale.client_id"
                :placeholder="$t('Choose_Customer')"
                :options="clients.map(c => ({ label: c.name, value: c.id }))"
                show-search
                option-filter-prop="label"
              />
            </a-form-item>

            <a-form-item :label="$t('warehouse') + ' *'" v-bind="fieldProps('warehouse_id')">
              <a-select
                v-model:value="sale.warehouse_id"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                show-search
                option-filter-prop="label"
              />
            </a-form-item>

            <!-- Sales agents belong to the Commissions module; hide the field
                 when the module is toggled off (Settings → Modules). -->
            <a-form-item v-if="auth.moduleEnabled('commissions')" :label="$t('Sales_Agent')">
              <a-select
                v-model:value="sale.sales_agent_id"
                :options="salesAgents.map(ag => ({ label: ag.name, value: ag.id }))"
                allow-clear
                show-search
                option-filter-prop="label"
              />
            </a-form-item>

            <a-form-item :label="$t('Status') + ' *'" v-bind="fieldProps('statut')">
              <a-select
                v-model:value="sale.statut"
                :placeholder="$t('Choose_Status')"
                :options="[
                  { label: 'completed', value: 'completed' },
                  { label: 'Pending', value: 'pending' },
                ]"
              />
            </a-form-item>

            <a-row :gutter="12" v-if="auth.can('edit_tax_discount_shipping_sale')">
              <a-col :span="12">
                <a-form-item :label="$t('OrderTax')" v-bind="numFieldProps('tax_rate')">
                  <a-input v-model:value="sale.tax_rate" addon-after="%" @keyup="keyupNum('tax_rate')" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item :label="$t('Discount')" v-bind="numFieldProps('discount')">
                  <a-input v-model:value="sale.discount" :addon-after="auth.currency" @keyup="keyupNum('discount')" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Shipping')" v-bind="numFieldProps('shipping')">
                  <a-input v-model:value="sale.shipping" :addon-after="auth.currency" @keyup="keyupNum('shipping')" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="sale.notes" :rows="3" :placeholder="$t('Afewwords')" />
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

          <a-alert v-if="errorMessages.length" type="error" style="margin-top: 16px" show-icon>
            <template #message>{{ $t('Import_Failed_Fix_Below') || 'Import failed. Fix the issues below:' }}</template>
            <template #description>
              <ul style="margin: 0; padding-left: 18px">
                <li v-for="(err, idx) in errorMessages" :key="'err-' + idx">{{ err }}</li>
              </ul>
            </template>
          </a-alert>

          <div v-if="previewRows.length" style="margin-top: 20px">
            <a-table
              :columns="previewColumns" :data-source="previewRows"
              size="small" :pagination="false" :scroll="{ x: true }" :row-key="(_r, i) => i"
            >
              <template #bodyCell="{ column, record, index }">
                <template v-if="column.key === 'idx'">{{ index + 1 }}</template>
                <template v-else-if="column.key === 'code'">
                  <a-typography-text code>{{ record.code }}</a-typography-text>
                </template>
                <template v-else-if="column.key === 'qty'">
                  {{ number(record.qty, 2) }} <span style="color: #9ca3af; font-size: 11px">{{ record.unit }}</span>
                </template>
                <template v-else-if="column.key === 'price'">{{ number(record.price) }}</template>
                <template v-else-if="column.key === 'total'"><strong>{{ number(record.total) }}</strong></template>
              </template>
              <template #footer>
                <div style="display: flex; justify-content: flex-end; gap: 12px; font-weight: 600">
                  <span>{{ $t('Subtotal') || 'Subtotal' }}</span>
                  <span>{{ number(previewSubtotal) }}</span>
                </div>
              </template>
            </a-table>
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
              :disabled="SubmitProcessing || !previewRows.length"
              @click="submitSale"
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
 * Import Sales — header form + CSV preview. Endpoints and validation copied
 * from legacy import_sales.vue: GET get_import_sales, POST
 * preview_import_sales (key `products`), POST store_import_sales.
 * Legacy rules preserved: csv-only; required date/client/warehouse/statut;
 * ^\d*\.?\d*$ + NaN→0 keyup on tax/discount/shipping; sales_agent_id only
 * appended when set; error lists collected via the legacy collectors.
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
const clients = ref([]);
const salesAgents = ref([]);
const importFile = ref(null);
const previewRows = ref([]);
const previewSubtotal = ref(0);
const previewLoading = ref(false);
const errorMessages = ref([]);
const validated = ref(false);
const dz = ref();

const sale = ref({
  date: new Date().toISOString().slice(0, 10),
  statut: 'completed',
  notes: '',
  client_id: undefined,
  warehouse_id: undefined,
  sales_agent_id: null,
  tax_rate: 0,
  shipping: 0,
  discount: 0,
});

const NUM_RE = /^\d*\.?\d*$/;

const previewColumns = computed(() => [
  { title: '#', key: 'idx', width: 50 },
  { title: t('Code') || 'Code', key: 'code' },
  { title: t('product_name') || 'Product', dataIndex: 'name', key: 'name' },
  { title: t('Quantity'), key: 'qty', align: 'right' },
  { title: t('Price'), key: 'price', align: 'right' },
  { title: t('Subtotal') || 'Subtotal', key: 'total', align: 'right' },
]);

function fieldError(key) {
  if (!validated.value) return null;
  const v = sale.value[key];
  if (v === '' || v === null || v === undefined) return t('Field_is_required');
  return null;
}
function numFieldError(key) {
  if (!validated.value) return null;
  const v = sale.value[key];
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
  return ['date', 'client_id', 'warehouse_id', 'statut'].every((k) => {
    const v = sale.value[k];
    return !(v === '' || v === null || v === undefined);
  }) && ['tax_rate', 'discount', 'shipping'].every((k) => {
    const v = sale.value[k];
    return v === '' || v === null || v === undefined || NUM_RE.test(String(v));
  });
}

function keyupNum(key) {
  if (isNaN(sale.value[key]) || sale.value[key] === '') {
    sale.value[key] = 0;
  }
}

function toast(variant, msg, title) {
  notification[variant === 'danger' ? 'error' : 'success']({ message: title, description: msg });
}

// ---- error collectors (legacy import_sales.vue verbatim) ----
function flattenLaravelErrors(errorsObj) {
  const out = [];
  if (!errorsObj || typeof errorsObj !== 'object') return out;
  Object.keys(errorsObj).forEach((k) => {
    const v = errorsObj[k];
    if (Array.isArray(v)) v.forEach((m) => { if (m) out.push(String(m)); });
    else if (v) out.push(String(v));
  });
  return out;
}
function collectErrorsFromResponse(data) {
  const out = [];
  if (!data || typeof data !== 'object') return out;
  if (Array.isArray(data.messages)) {
    data.messages.forEach((m) => { if (m) out.push(String(m)); });
  }
  if (data.message) {
    out.push(String(data.message));
  }
  if (data.errors) {
    out.push(...flattenLaravelErrors(data.errors));
  }
  if (data.insufficient && Array.isArray(data.insufficient)) {
    data.insufficient.forEach((it) => {
      out.push(`${it.product_code}: requested ${it.requested}, available ${it.available}`);
    });
  }
  if (data.msg && !(data.insufficient && data.insufficient.length)) {
    out.push(String(data.msg));
  }
  if (data.details) {
    if (Array.isArray(data.details)) {
      data.details.forEach((m) => { if (m) out.push(String(m)); });
    } else if (typeof data.details === 'string') {
      out.push(data.details);
    }
  }
  if (data.error && typeof data.error === 'string') {
    out.push(data.error);
  }
  const seen = {};
  return out.filter((m) => (seen[m] ? false : (seen[m] = true)));
}
function collectErrorsFromAxios(err) {
  let payload = null;
  if (err && err.response && err.response.data !== undefined) {
    payload = err.response.data;
  } else if (err && typeof err === 'object'
    && (err.msg !== undefined || err.details !== undefined || err.errors !== undefined || err.message !== undefined)) {
    payload = err;
  }
  const list = collectErrorsFromResponse(payload);
  if (list.length) return list;
  if (err && typeof err === 'object' && err.message) return [String(err.message)];
  return [t('An_error_occurred_while_processing_the_CSV_file') || 'An error occurred while processing the CSV file.'];
}

// ---- file + preview ----
function handleFile(file) {
  const name = file.name || '';
  const ext = name.split('.').pop().toLowerCase();
  if (ext !== 'csv') {
    errorMessages.value = [t('field_must_be_in_csv_format') || 'File must be in CSV format'];
    importFile.value = null;
    previewRows.value = [];
    previewSubtotal.value = 0;
    return;
  }
  importFile.value = file;
  errorMessages.value = [];
  fetchPreview();
}

function clearFile() {
  importFile.value = null;
  previewRows.value = [];
  previewSubtotal.value = 0;
  errorMessages.value = [];
  if (dz.value) dz.value.resetInput();
}

async function fetchPreview() {
  if (!importFile.value) return;
  previewLoading.value = true;
  previewRows.value = [];
  previewSubtotal.value = 0;
  errorMessages.value = [];

  const formData = new FormData();
  formData.append('products', importFile.value);

  try {
    const resp = await uploadForm('preview_import_sales', formData);
    previewLoading.value = false;
    const d = resp && resp.data && typeof resp.data === 'object' ? resp.data : {};
    if (resp.status >= 400 || d.status === false) {
      errorMessages.value = collectErrorsFromResponse(d);
      if (!errorMessages.value.length) {
        errorMessages.value = [t('CSV_Parse_Failed') || 'Failed to parse CSV'];
      }
      return;
    }
    const rows = Array.isArray(d.rows) ? d.rows : [];
    previewRows.value = rows;
    previewSubtotal.value = Number(d.grand_total) || 0;
    if (!previewRows.value.length) {
      errorMessages.value = [t('CSV_No_Valid_Rows') || 'No valid rows were found in the CSV file'];
    }
  } catch (error) {
    previewLoading.value = false;
    errorMessages.value = collectErrorsFromAxios(error);
  }
}

// ---- submit (legacy order: form → file → rows) ----
function submitSale() {
  errorMessages.value = [];
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
  createSale();
}

async function createSale() {
  SubmitProcessing.value = true;
  startProgress();

  const data = new FormData();
  data.append('date', sale.value.date);
  data.append('client_id', sale.value.client_id);
  data.append('warehouse_id', sale.value.warehouse_id);
  if (sale.value.sales_agent_id != null && sale.value.sales_agent_id !== '') {
    data.append('sales_agent_id', sale.value.sales_agent_id);
  }
  data.append('statut', sale.value.statut);
  data.append('notes', sale.value.notes);
  data.append('tax_rate', sale.value.tax_rate);
  data.append('discount', sale.value.discount);
  data.append('shipping', sale.value.shipping);
  data.append('products', importFile.value);

  try {
    const resp = await uploadForm('store_import_sales', data);
    if (resp.status >= 400) {
      const err = new Error('HTTP ' + resp.status);
      err.response = { data: resp.data };
      throw err;
    }
    doneProgress();
    errorMessages.value = [];
    toast('success', t('Successfully_Imported'), t('Success'));
    SubmitProcessing.value = false;
    router.push('/sales');
  } catch (error) {
    doneProgress();
    errorMessages.value = collectErrorsFromAxios(error);
    toast('danger', t('Check_the_error_list_and_fix_your_file') || 'Check the error list below and fix your file.', t('Failed'));
    SubmitProcessing.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_import_sales');
    clients.value = data.clients || [];
    warehouses.value = data.warehouses || [];
    salesAgents.value = data.sales_agents || [];
    isLoading.value = false;
  } catch (e) {
    setTimeout(() => { isLoading.value = false; }, 500);
  }
});
</script>

<style scoped>
.submit-bar { display: flex; align-items: center; gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
</style>
