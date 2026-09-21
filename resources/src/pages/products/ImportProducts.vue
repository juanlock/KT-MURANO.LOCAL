<template>
  <div class="page">
    <PageHeader
      :title="$t('import_products')"
      :breadcrumb="[$t('Products'), $t('import_products')]"
    >
      <template #extra>
        <a-button @click="$router.push('/products')">{{ $t('Back') }}</a-button>
      </template>
    </PageHeader>

    <!-- Upload -->
    <a-card style="margin-bottom: 16px">
      <div style="display: flex; justify-content: center; margin-bottom: 24px">
        <a-segmented v-model:value="importType" :options="typeOptions" @change="clearErrors" />
      </div>

      <div style="max-width: 760px; margin: 0 auto">
          <ImportDropzone
            ref="dz"
            :file="file"
            :accept="accept"
            title="Click or drop your Excel file here"
            hint="Allowed formats: XLSX, XLS · Max size: 20MB"
            :disabled="uploading"
            @file="loadFile"
            @clear="clearFile()"
          />

          <a-alert v-if="errorMessages.length" type="error" style="margin-top: 16px" show-icon>
            <template #message>Import failed. Fix the issues below:</template>
            <template #description>
              <ul style="margin: 0; padding-left: 18px">
                <li v-for="(err, idx) in errorMessages" :key="'err-' + idx">{{ err }}</li>
              </ul>
            </template>
          </a-alert>

          <a-alert v-if="warningMessages.length" type="warning" style="margin-top: 16px" show-icon>
            <template #message>Warnings</template>
            <template #description>
              <ul style="margin: 0; padding-left: 18px">
                <li v-for="(w, idx) in warningMessages" :key="'warn-' + idx">{{ w }}</li>
              </ul>
            </template>
          </a-alert>

          <a-progress v-if="uploading" :percent="progress" size="small" style="margin-top: 16px" />

          <div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap">
            <a-button type="primary" :disabled="!canSubmit || uploading" :loading="uploading" @click="submit">
              Import now
            </a-button>
            <a-button :href="exampleHref" target="_blank" rel="noopener">
              <DownloadOutlined /> Download example
            </a-button>
            <a-button :disabled="!file || uploading" @click="clearFile()">Reset</a-button>
          </div>
      </div>
    </a-card>

    <!-- Expected file format -->
    <a-card size="small" :title="'Expected file format'">
      <template #extra>
        <span style="font-size: 12px; color: #8c8c8c">
          One row per {{ importType === 'variant' ? 'variant' : 'product' }} ·
          <a-tag color="green" style="margin: 0">green *</a-tag> = required
        </span>
      </template>
      <a-space wrap style="margin-bottom: 12px">
        <a-tag v-for="c in activeGuide" :key="c.key" :color="c.required ? 'green' : 'default'">
          {{ c.label }}{{ c.required ? ' *' : '' }}
        </a-tag>
      </a-space>
      <a-table
        :columns="activeExample.columns" :data-source="activeExample.rows"
        size="small" :pagination="false" :scroll="{ x: 'max-content' }" row-key="__k"
      />
    </a-card>
  </div>
</template>

<script setup>
/**
 * Import Products wizard — single/variant/service tabs. Endpoints and the
 * `type` field mirror legacy Import_products.vue exactly:
 * products/import/{single|variants|service} + type is_single/is_variant/is_service.
 * File checks and error collection copied VERBATIM (validation-parity rule).
 * NOTE: legacy submit does NOT special-case 422 into the resolve path — it
 * throws (no validateStatus), handled by collectErrorsFromAxios; we mirror
 * that by routing non-2xx through the same collectors.
 */
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { notification } from 'ant-design-vue';
import { DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ImportDropzone from '../../components/ImportDropzone.vue';
import { uploadForm } from '../../lib/upload';
import { start as startProgress, done as doneProgress } from '../../lib/progress';

const router = useRouter();

const singleEndpoint = 'products/import/single';
const variantEndpoint = 'products/import/variants';
const serviceEndpoint = 'products/import/service';

const importType = ref('single');
const typeOptions = [
  { label: 'Single Products', value: 'single' },
  { label: 'Variant Products', value: 'variant' },
  { label: 'Service Products', value: 'service' },
];
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls';

const singlesGuide = [
  { key: 'name', label: 'name', required: true },
  { key: 'code', label: 'code', required: true },
  { key: 'Retail price', label: 'Retail price', required: true },
  { key: 'cost', label: 'cost', required: true },
  { key: 'category', label: 'category', required: true },
  { key: 'sub_category', label: 'sub_category', required: false },
  { key: 'unit', label: 'unit', required: true },
  { key: 'Wholesale price', label: 'Wholesale price', required: false },
  { key: 'Min price', label: 'Min price', required: false },
  { key: 'brand', label: 'brand', required: false },
  { key: 'Stock alert', label: 'Stock alert', required: false },
  { key: 'note', label: 'note', required: false },
];
const variantsGuide = [
  { key: 'product_name', label: 'product_name', required: true },
  { key: 'product_code', label: 'product_code', required: true },
  { key: 'category', label: 'category', required: true },
  { key: 'sub_category', label: 'sub_category', required: false },
  { key: 'unit', label: 'unit', required: true },
  { key: 'brand', label: 'brand', required: false },
  { key: 'variant_name', label: 'variant_name', required: true },
  { key: 'variant_code', label: 'variant_code', required: true },
  { key: 'variant_cost', label: 'variant_cost', required: true },
  { key: 'variant_price', label: 'variant_price', required: true },
  { key: 'variant_wholesale', label: 'variant_wholesale', required: false },
  { key: 'variant_min_price', label: 'variant_min_price', required: false },
];
const serviceGuide = [
  { key: 'name', label: 'name', required: true },
  { key: 'code', label: 'code', required: true },
  { key: 'Retail price', label: 'Retail price', required: true },
  { key: 'category', label: 'category', required: true },
  { key: 'sub_category', label: 'sub_category', required: false },
  { key: 'unit', label: 'unit', required: true },
  { key: 'Wholesale price', label: 'Wholesale price', required: false },
  { key: 'Min price', label: 'Min price', required: false },
  { key: 'brand', label: 'brand', required: false },
  { key: 'note', label: 'note', required: false },
];

const activeGuide = computed(() => {
  if (importType.value === 'variant') return variantsGuide;
  if (importType.value === 'service') return serviceGuide;
  return singlesGuide;
});

const EXAMPLES = {
  single: {
    columns: [
      { title: 'name *', dataIndex: 'name' }, { title: 'code *', dataIndex: 'code' },
      { title: 'cost *', dataIndex: 'cost' }, { title: 'category *', dataIndex: 'category' },
      { title: 'sub_category', dataIndex: 'sub_category' }, { title: 'unit *', dataIndex: 'unit' },
      { title: 'Retail price *', dataIndex: 'retail' }, { title: 'Wholesale price', dataIndex: 'wholesale' },
      { title: 'Min price', dataIndex: 'min' }, { title: 'brand', dataIndex: 'brand' },
      { title: 'Stock alert', dataIndex: 'alert' }, { title: 'note', dataIndex: 'note' },
    ],
    rows: [
      { __k: 1, name: 'Blue T-Shirt', code: 'TSHIRT-BLUE', cost: '8.00', category: 'Apparel', sub_category: 'T-Shirts', unit: 'pc', retail: '19.90', wholesale: '17.00', min: '15.00', brand: 'Acme', alert: '5', note: 'Summer collection' },
      { __k: 2, name: 'Coffee Mug', code: 'MUG-COF-01', cost: '2.20', category: 'Home', sub_category: 'Kitchen', unit: 'pc', retail: '6.50', wholesale: '6.00', min: '5.75', brand: '', alert: '0', note: '' },
    ],
  },
  variant: {
    columns: [
      { title: 'product name *', dataIndex: 'pname' }, { title: 'product code *', dataIndex: 'pcode' },
      { title: 'category *', dataIndex: 'category' }, { title: 'sub_category', dataIndex: 'sub_category' },
      { title: 'unit *', dataIndex: 'unit' }, { title: 'brand', dataIndex: 'brand' },
      { title: 'variant name *', dataIndex: 'vname' }, { title: 'variant code *', dataIndex: 'vcode' },
      { title: 'variant cost *', dataIndex: 'vcost' }, { title: 'variant price *', dataIndex: 'vprice' },
      { title: 'variant wholesale', dataIndex: 'vwholesale' }, { title: 'variant min price', dataIndex: 'vmin' },
    ],
    rows: [
      { __k: 1, pname: 'T-Shirt', pcode: 'TSHIRT-100', category: 'Apparel', sub_category: 'T-Shirts', unit: 'pc', brand: 'Acme', vname: 'Small', vcode: 'TSHIRT-100-S', vcost: '7.50', vprice: '14.90', vwholesale: '13.00', vmin: '12.00' },
      { __k: 2, pname: 'T-Shirt', pcode: 'TSHIRT-100', category: 'Apparel', sub_category: 'T-Shirts', unit: 'pc', brand: 'Acme', vname: 'Medium', vcode: 'TSHIRT-100-M', vcost: '7.50', vprice: '14.90', vwholesale: '13.00', vmin: '12.00' },
    ],
  },
  service: {
    columns: [
      { title: 'name *', dataIndex: 'name' }, { title: 'code *', dataIndex: 'code' },
      { title: 'Retail price *', dataIndex: 'retail' }, { title: 'category *', dataIndex: 'category' },
      { title: 'sub_category', dataIndex: 'sub_category' }, { title: 'unit *', dataIndex: 'unit' },
      { title: 'Wholesale price', dataIndex: 'wholesale' }, { title: 'Min price', dataIndex: 'min' },
      { title: 'brand', dataIndex: 'brand' }, { title: 'note', dataIndex: 'note' },
    ],
    rows: [
      { __k: 1, name: 'Delivery Service', code: 'SRV-DELIV', retail: '25.00', category: 'Services', sub_category: '', unit: 'pc', wholesale: '20.00', min: '18.00', brand: '', note: 'Same-day delivery' },
    ],
  },
};
const activeExample = computed(() => EXAMPLES[importType.value] || EXAMPLES.single);

const canSubmit = computed(() => !!file.value && errorMessages.value.length === 0);
const exampleHref = computed(() => {
  if (importType.value === 'single') return '/import/exemples/single_products.xlsx';
  if (importType.value === 'service') return '/import/exemples/service_products.xlsx';
  return '/import/exemples/variant_products.xlsx';
});

function toast(msg, title, type) {
  notification[type === 'danger' ? 'error' : type]({ message: title, description: msg });
}

function clearErrors() {
  errorMessages.value = [];
  warningMessages.value = [];
}

function loadFile(f) {
  clearErrors();
  const msgs = [];
  if (f.size > maxSize) msgs.push('File is too large. Please upload a file under the 20MB limit.');
  const name = f.name || '';
  const ext = name.split('.').pop().toLowerCase();
  if (['xlsx', 'xls'].indexOf(ext) === -1) msgs.push('Unsupported file type. Please upload an .xlsx or .xls file.');
  if (msgs.length) {
    errorMessages.value = msgs;
    clearFile(false);
    return;
  }
  file.value = f;
}

function clearFile(resetInput) {
  if (typeof resetInput === 'undefined') resetInput = true;
  file.value = null;
  if (resetInput && dz.value) dz.value.resetInput();
}

function flattenLaravelErrors(errors) {
  const out = [];
  if (!errors) return out;
  if (Array.isArray(errors)) {
    errors.forEach((v) => { if (v != null && v !== '') out.push(String(v)); });
    return out;
  }
  if (typeof errors === 'object') {
    Object.keys(errors).forEach((k) => {
      const val = errors[k];
      if (Array.isArray(val)) val.forEach((vv) => { if (vv != null && vv !== '') out.push(String(vv)); });
      else if (val != null && val !== '') out.push(String(val));
    });
    return out;
  }
  if (typeof errors === 'string') return [errors];
  return out;
}

function collectErrorsFromResponse(data) {
  const out = [];
  if (!data) return out;
  if (typeof data === 'string') {
    const txt = data.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    if (txt && txt.toLowerCase() !== 'validation failed') out.push(txt);
    return out;
  }
  if (data.errors) {
    const errs = flattenLaravelErrors(data.errors);
    if (errs.length) return errs;
  }
  if (Array.isArray(data.messages)) out.push(...data.messages);
  if (data.details) {
    if (Array.isArray(data.details)) out.push(...data.details);
    else if (typeof data.details === 'string') out.push(data.details);
  }
  if (typeof data.error === 'string') out.push(data.error);
  if (!out.length && typeof data.message === 'string') {
    const msg = data.message.trim();
    if (msg.toLowerCase() !== 'validation failed') out.push(msg);
  }
  const seen = {}; const filtered = [];
  out.forEach((s) => {
    const t = String(s).trim();
    if (t && !seen[t]) { seen[t] = true; filtered.push(t); }
  });
  return filtered;
}

async function submit() {
  if (!file.value) {
    errorMessages.value = ['Please choose a file to import.'];
    return;
  }
  clearErrors();
  uploading.value = true;
  progress.value = 0;
  startProgress();

  try {
    const fd = new FormData();
    fd.append('products', file.value);
    const typeMap = { single: 'is_single', variant: 'is_variant', service: 'is_service' };
    fd.append('type', typeMap[importType.value] || 'is_single');

    let endpoint = singleEndpoint;
    if (importType.value === 'variant') endpoint = variantEndpoint;
    else if (importType.value === 'service') endpoint = serviceEndpoint;

    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp ? resp.data : null;
    const ok = data && typeof data === 'object' && (data.status === true || data.success === true);

    if (!ok) {
      const msgs = collectErrorsFromResponse(data);
      errorMessages.value = msgs.length ? msgs : ['Import failed. Please review your file and try again.'];
      toast('Check the error list and fix your file.', 'Import failed', 'danger');
      return;
    }

    toast('Imported successfully.', 'Success', 'success');
    router.push('/products');
  } catch (err) {
    errorMessages.value = err && err.message ? [String(err.message)] : ['Something went wrong while uploading. Please try again.'];
    toast('Check the error list and fix your file.', 'Import failed', 'danger');
  } finally {
    doneProgress();
    uploading.value = false;
    progress.value = 0;
  }
}
</script>
