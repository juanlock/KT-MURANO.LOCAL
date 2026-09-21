<template>
  <div class="page">
    <PageHeader
      title="Import Products (Update Only)"
      :breadcrumb="[$t('Products'), 'Import (Update Only)']"
    >
      <template #extra>
        <a-button @click="$router.push('/products')">{{ $t('Back') }}</a-button>
      </template>
    </PageHeader>

    <!-- Upload -->
    <a-card style="margin-bottom: 16px">
      <div style="max-width: 760px; margin: 0 auto">
          <a-alert
            type="info" show-icon style="margin-bottom: 16px"
            message="Update only"
            description="This import updates cost and retail price of EXISTING products by code. It never creates products."
          />

          <ImportDropzone
            ref="dz"
            :file="file"
            :accept="accept"
            title="Click or drop your file here"
            hint="Allowed formats: CSV, XLSX, XLS · Max size: 20MB"
            :disabled="uploading"
            @file="loadFile"
            @clear="clearFile()"
          />

          <a-alert v-if="successMessage" type="success" style="margin-top: 16px" show-icon>
            <template #message>{{ successMessage }}</template>
            <template #description>
              <div v-if="importResults" style="font-size: 12px">
                <div>Updated: {{ importResults.updated }} product(s)</div>
                <div v-if="importResults.not_found > 0" style="color: #d48806">
                  Not found: {{ importResults.not_found }} code(s)
                </div>
                <div v-if="importResults.errors > 0" style="color: #cf1322">
                  Errors: {{ importResults.errors }}
                </div>
              </div>
            </template>
          </a-alert>

          <a-alert v-if="errorMessages.length" type="error" style="margin-top: 16px" show-icon>
            <template #message>Import failed. Fix the issues below:</template>
            <template #description>
              <ul style="margin: 0; padding-left: 18px">
                <li v-for="(err, idx) in errorMessages" :key="'err-' + idx">{{ err }}</li>
              </ul>
            </template>
          </a-alert>

          <a-progress v-if="uploading" :percent="progress" size="small" style="margin-top: 16px" />

          <div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap">
            <a-button type="primary" :disabled="!canSubmit || uploading" :loading="uploading" @click="submit">
              Import now
            </a-button>
            <a-button href="/import/exemples/update_products.csv" target="_blank" rel="noopener">
              <DownloadOutlined /> Download example
            </a-button>
            <a-button :disabled="!file || uploading" @click="clearFile()">Reset</a-button>
          </div>
      </div>
    </a-card>

    <!-- Expected file format -->
    <a-card size="small" :title="'Expected file format'">
      <template #extra>
        <span style="font-size: 12px; color: #8c8c8c">One row per product · all columns required</span>
      </template>
      <a-table
        :columns="exampleColumns" :data-source="exampleRows"
        size="small" :pagination="false" row-key="code"
      />
      <ul style="padding-left: 18px; margin: 12px 0 0 0; font-size: 12px">
        <li>Only products with matching codes will be updated. Other fields are ignored.</li>
      </ul>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Import Products (Update Only) — POST products/import/update-only
 * (multipart, key `products`). Accepts csv/xlsx/xls (unlike the other
 * wizards). Success shows an updated/not_found/errors panel then redirects
 * after 2s — all copied from legacy Import_products_update.vue.
 */
import { ref, computed, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { notification } from 'ant-design-vue';
import { DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ImportDropzone from '../../components/ImportDropzone.vue';
import { uploadForm } from '../../lib/upload';
import { start as startProgress, done as doneProgress } from '../../lib/progress';

const router = useRouter();

const endpoint = 'products/import/update-only';
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const successMessage = ref('');
const importResults = ref(null);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();
let redirectTimer = null;

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = '.csv,.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv';

const canSubmit = computed(() => !!file.value && errorMessages.value.length === 0);

const exampleColumns = [
  { title: 'code *', dataIndex: 'code' },
  { title: 'cost *', dataIndex: 'cost' },
  { title: 'retail_price *', dataIndex: 'retail_price' },
];
const exampleRows = [
  { code: 'PROD-001', cost: '10.50', retail_price: '19.99' },
  { code: 'PROD-002', cost: '5.25', retail_price: '12.50' },
  { code: 'PROD-003', cost: '8.00', retail_price: '15.00' },
];

onBeforeUnmount(() => { if (redirectTimer) clearTimeout(redirectTimer); });

function toast(msg, title, type) {
  notification[type === 'danger' ? 'error' : type]({ message: title, description: msg });
}

function clearErrors() {
  errorMessages.value = [];
  warningMessages.value = [];
}

function loadFile(f) {
  clearErrors();
  successMessage.value = '';
  importResults.value = null;
  const msgs = [];
  if (f.size > maxSize) msgs.push('File is too large. Please upload a file under the 20MB limit.');
  const name = f.name || '';
  const ext = name.split('.').pop().toLowerCase();
  if (['xlsx', 'xls', 'csv'].indexOf(ext) === -1) msgs.push('Unsupported file type. Please upload a .csv, .xlsx or .xls file.');
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
  successMessage.value = '';
  importResults.value = null;
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
  successMessage.value = '';
  importResults.value = null;
  uploading.value = true;
  progress.value = 0;
  startProgress();

  try {
    const fd = new FormData();
    fd.append('products', file.value);

    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp ? resp.data : null;
    const ok = data && typeof data === 'object' && (data.status === true || data.success === true);

    if (!ok) {
      const msgs = collectErrorsFromResponse(data);
      errorMessages.value = msgs.length ? msgs : ['Import failed. Please review your file and try again.'];
      toast('Check the error list and fix your file.', 'Import failed', 'danger');
      return;
    }

    importResults.value = {
      updated: data.updated || 0,
      not_found: data.not_found || 0,
      errors: data.errors || 0,
    };
    successMessage.value = data.message || 'Products updated successfully!';
    toast(successMessage.value, 'Success', 'success');
    redirectTimer = setTimeout(() => { router.push('/products'); }, 2000);
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
