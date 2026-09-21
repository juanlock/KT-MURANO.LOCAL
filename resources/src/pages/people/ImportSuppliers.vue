<template>
  <div class="page">
    <PageHeader
      title="Import Suppliers"
      :breadcrumb="[$t('People'), $t('Suppliers'), 'Import']"
    >
      <template #extra>
        <a-button @click="$router.push('/suppliers')">{{ $t('Back') }}</a-button>
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
            <a-button href="/import/exemples/suppliers.xlsx" target="_blank" rel="noopener">
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
          One row per supplier ·
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
        <li><strong>code</strong> must be an integer and unique (DB column is INT).</li>
        <li><strong>name</strong> is required.</li>
        <li><strong>Address</strong> is the address field expected by the backend.</li>
      </ul>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Import Suppliers wizard — POST suppliers/import (multipart, key `suppliers`).
 * File checks / error rules copied VERBATIM from legacy ImportSuppliers.vue.
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

const endpoint = 'suppliers/import';
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls';

const columnsGuide = [
  { key: 'name', label: 'name', required: true },
  { key: 'code', label: 'code (integer)', required: true },
  { key: 'email', label: 'email', required: false },
  { key: 'phone', label: 'phone', required: false },
  { key: 'tax_number', label: 'tax_number', required: false },
  { key: 'country', label: 'country', required: false },
  { key: 'city', label: 'city', required: false },
  { key: 'adresse', label: 'adresse', required: false },
];

const canSubmit = computed(() => !!file.value && errorMessages.value.length === 0);

const exampleColumns = [
  { title: 'name *', dataIndex: 'name' }, { title: 'code (integer) *', dataIndex: 'code' },
  { title: 'email', dataIndex: 'email' }, { title: 'phone', dataIndex: 'phone' },
  { title: 'tax_number', dataIndex: 'tax_number' }, { title: 'country', dataIndex: 'country' },
  { title: 'city', dataIndex: 'city' }, { title: 'Address', dataIndex: 'adresse' },
];
const exampleRows = [
  { name: 'ACME Supplies', code: '2001', email: 'contact@acmesupplies.com', phone: '+1 555 0100', tax_number: 'VAT-4455', country: 'USA', city: 'New York', adresse: '5th Ave' },
  { name: 'Global Vendor', code: '2002', email: '', phone: '+44 20 7946 0000', tax_number: '', country: 'UK', city: 'London', adresse: '221B Baker Street' },
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
    fd.append('suppliers', file.value);

    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp && resp.data && typeof resp.data === 'object' ? resp.data : {};
    const http = resp ? resp.status : 0;

    if (http === 422 || data.status === false) {
      let errs = onlyErrorsArray(data);
      if (!errs.length && data && typeof data.message === 'string'
        && data.message.trim().toLowerCase() !== 'validation failed') {
        errs = [data.message];
      }
      if (!errs.length) {
        errs = ['Please fix the highlighted errors in your file and try again.'];
      }
      errorMessages.value = errs;
      toast('Check the error list and fix your file.', 'Import failed', 'danger');
      return;
    }

    if (Array.isArray(data.warnings) && data.warnings.length) {
      warningMessages.value = data.warnings;
    }
    const count = data.imported || 0;
    toast(count + ' suppliers imported successfully.', 'Success', 'success');
    router.push('/suppliers');
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
