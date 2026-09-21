<template>
  <div class="page">
    <PageHeader
      title="Import Customers"
      :breadcrumb="[$t('People'), $t('Customers'), 'Import']"
    >
      <template #extra>
        <a-button @click="$router.push('/customers')">{{ $t('Back') }}</a-button>
      </template>
    </PageHeader>

    <!-- Upload -->
    <a-card style="margin-bottom: 16px">
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
            <a-button href="/import/exemples/customers.xlsx" target="_blank" rel="noopener">
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
          One row per customer ·
          <a-tag color="green" style="margin: 0">green *</a-tag> = required
        </span>
      </template>
      <a-space wrap style="margin-bottom: 12px">
        <a-tag v-for="c in columnsGuide" :key="c.key" :color="c.required ? 'green' : 'default'">
          {{ c.label }}{{ c.required ? ' *' : '' }}
        </a-tag>
      </a-space>
      <a-table
        :columns="exampleColumns" :data-source="exampleRows"
        size="small" :pagination="false" :scroll="{ x: 'max-content' }" row-key="code"
      />
      <ul style="padding-left: 18px; margin: 12px 0 0 0; font-size: 12px">
        <li><strong>code</strong> must be an integer and unique (the database column is INT).</li>
        <li><strong>Username</strong> is required (column name in file: <strong>name</strong>).</li>
        <li><strong>firstname</strong> and <strong>lastname</strong> are optional.</li>
        <li><strong>Address</strong> is the address field name expected by the backend.</li>
        <li><strong>opening_balance</strong> is optional; if present it must be numeric and represents previous dues for this customer.</li>
        <li><strong>email</strong> should be valid if provided; include the country code in <strong>phone</strong> when possible.</li>
      </ul>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Import Customers wizard — POST customers/import (multipart, key `customers`).
 * All file checks, error-collection rules and messages are copied VERBATIM
 * from legacy ImportCustomers.vue (validation-parity rule): 20MB clamp,
 * xlsx/xls whitelist, 422/status:false handling that prefers errors[] and
 * never surfaces a bare "Validation failed".
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

const endpoint = 'customers/import';
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls';

const columnsGuide = [
  { key: 'name', label: 'Username', required: true },
  { key: 'firstname', label: 'firstname', required: false },
  { key: 'lastname', label: 'lastname', required: false },
  { key: 'code', label: 'code (integer)', required: true },
  { key: 'email', label: 'email', required: false },
  { key: 'phone', label: 'phone', required: false },
  { key: 'tax_number', label: 'tax_number', required: false },
  { key: 'country', label: 'country', required: false },
  { key: 'city', label: 'city', required: false },
  { key: 'adresse', label: 'adresse', required: false },
  { key: 'opening_balance', label: 'opening_balance', required: false },
];

const canSubmit = computed(() => !!file.value && errorMessages.value.length === 0);

const exampleColumns = [
  { title: 'Username *', dataIndex: 'name' }, { title: 'firstname', dataIndex: 'firstname' },
  { title: 'lastname', dataIndex: 'lastname' }, { title: 'code (integer) *', dataIndex: 'code' },
  { title: 'email', dataIndex: 'email' }, { title: 'phone', dataIndex: 'phone' },
  { title: 'tax_number', dataIndex: 'tax_number' }, { title: 'country', dataIndex: 'country' },
  { title: 'city', dataIndex: 'city' }, { title: 'Address', dataIndex: 'adresse' },
  { title: 'opening_balance', dataIndex: 'opening_balance' },
];
const exampleRows = [
  { name: 'Acme Trading', firstname: 'Acme', lastname: 'Trading', code: '10001', email: 'info@acme.com', phone: '+1 555 0123', tax_number: 'TAX-9988', country: 'USA', city: 'New York', adresse: '5th Ave, Suite 2', opening_balance: '150.50' },
  { name: 'Jane Smith', firstname: 'Jane', lastname: 'Smith', code: '10002', email: 'jane@example.com', phone: '+44 20 7946 0958', tax_number: '', country: 'UK', city: 'London', adresse: '221B Baker Street', opening_balance: '0' },
];

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

function onlyErrorsArray(data) {
  if (!data || !data.errors) return [];
  const e = data.errors;
  const out = [];
  if (Array.isArray(e)) {
    for (let i = 0; i < e.length; i++) if (e[i]) out.push(String(e[i]));
  } else if (typeof e === 'object') {
    Object.keys(e).forEach((k) => {
      const v = e[k];
      if (Array.isArray(v)) v.forEach((m) => { if (m) out.push(String(m)); });
      else if (v) out.push(String(v));
    });
  } else if (typeof e === 'string') {
    out.push(e);
  }
  const seen = {};
  return out.map((s) => String(s).trim()).filter((s) => s && !seen[s] && (seen[s] = 1));
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
    fd.append('customers', file.value);

    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp && resp.data && typeof resp.data === 'object' ? resp.data : {};
    const http = resp ? resp.status : 0;

    if (http === 422 || data.status === false) {
      const errs = onlyErrorsArray(data);
      errorMessages.value = errs.length
        ? errs
        : (data.message && data.message.trim().toLowerCase() !== 'validation failed'
          ? [data.message]
          : ['Please fix the highlighted errors in your file and try again.']);
      toast('Check the error list and fix your file.', 'Import failed', 'danger');
      return;
    }

    if (Array.isArray(data.warnings) && data.warnings.length) {
      warningMessages.value = data.warnings;
    }
    const count = data.imported || 0;
    toast(count + ' customers imported successfully.', 'Success', 'success');
    router.push('/customers');
  } catch (e) {
    const msg = e && e.message ? String(e.message) : 'Network error. Please try again.';
    errorMessages.value = [msg];
    toast('Upload failed due to a network error.', 'Error', 'danger');
  } finally {
    doneProgress();
    uploading.value = false;
    progress.value = 0;
  }
}
</script>
