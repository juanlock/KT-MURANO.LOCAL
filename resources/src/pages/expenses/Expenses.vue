<template>
  <div class="page">
    <PageHeader :title="$t('ListExpenses')" :breadcrumb="[$t('Accounting'), $t('ListExpenses')]">
      <template #actions>
        <a-space>
          <a-button :loading="exporting" @click="pdf">PDF</a-button>
          <a-button v-if="auth.can('expense_add')" type="primary" @click="$router.push('/expenses/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('Create_Expense') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="4">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="4">
          <div class="filter-label">{{ $t('Reference') }}</div>
          <a-input v-model:value="filters.Ref" :placeholder="$t('Reference')" allow-clear @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="4">
          <div class="filter-label">{{ $t('Paymentchoice') }}</div>
          <a-select
            v-model:value="filters.payment_method_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('PleaseSelect')" :options="paymentMethodOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="4">
          <div class="filter-label">{{ $t('Account') }}</div>
          <a-select
            v-model:value="filters.account_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Account')" :options="accountOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="4">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="4">
          <div class="filter-label">{{ $t('Expense_Category') }}</div>
          <a-select
            v-model:value="filters.expense_category_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Category')" :options="categoryOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'documents'">
          <a-tag v-if="record.documents_count > 0" color="blue">
            <FileOutlined /> {{ record.documents_count }}
          </a-tag>
          <span v-else style="color: #bfbfbf">-</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Attach_Documents')">
              <a-button type="text" size="small" @click="openDocuments(record)">
                <template #icon><FileOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('expense_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/expenses/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('expense_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.Ref })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Attach documents -->
    <a-modal v-model:open="docsOpen" :title="$t('Attach_Documents')" :footer="null" width="720px">
      <a-upload
        :file-list="selectedFiles"
        :before-upload="queueFile"
        multiple
        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif"
        @remove="f => (selectedFiles = selectedFiles.filter(x => x !== f))"
      >
        <a-button><UploadOutlined /> {{ $t('Choose_files_or_drop_them_here') }}</a-button>
      </a-upload>
      <a-button
        type="primary" size="small" style="margin-top: 8px"
        :disabled="!selectedFiles.length" :loading="uploadProcessing"
        @click="uploadDocuments"
      >
        <UploadOutlined /> {{ $t('Upload') }}
      </a-button>

      <a-divider style="margin: 16px 0" />
      <div style="font-weight: 600; margin-bottom: 8px">{{ $t('Attached_Documents') }}</div>
      <a-table
        :data-source="documents" :columns="docColumns" :pagination="false"
        size="small" row-key="id" :locale="{ emptyText: $t('NodataAvailable') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><FileOutlined /> {{ record.name }}</template>
          <template v-else-if="column.key === 'size'">{{ formatFileSize(record.size) }}</template>
          <template v-else-if="column.key === 'created_at'">{{ date(record.created_at) }}</template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="downloadDocument(record)"><DownloadOutlined /></a-button>
              <a-popconfirm :title="$t('Delete_Text')" :ok-text="$t('Delete_confirmButtonText')"
                :cancel-text="$t('Delete_cancelButtonText')" @confirm="removeDocument(record.id)">
                <a-button size="small" danger><DeleteOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Expenses list — GET expenses with filters Ref/account_id/
 * payment_method_id/warehouse_id/date/expense_category_id (always sent,
 * empty when unset — legacy contract) → {expenses, Expenses_category,
 * warehouses, accounts, payment_methods, totalRows}. Row actions: attach
 * documents (GET expenses/{id}/documents, POST .../documents with
 * documents[] + expense_id, GET expenses/documents/{docId}/download blob,
 * DELETE expenses/documents/{docId}), edit, delete. PDF exports the current
 * page like legacy's jsPDF table. NOTE: legacy's bulk-delete button called
 * an UNDEFINED method (dead code) — not carried over.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, FileOutlined, UploadOutlined, DownloadOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();
const { money, date } = useFormat();
const auth = useAuthStore();

const filters = ref({
  date: null, Ref: '', payment_method_id: undefined,
  account_id: undefined, warehouse_id: undefined, expense_category_id: undefined,
});

const crud = useCrudTable('expenses', {
  rowsKey: 'expenses',
  params: () => ({
    Ref: filters.value.Ref || '',
    account_id: filters.value.account_id || '',
    payment_method_id: filters.value.payment_method_id || '',
    warehouse_id: filters.value.warehouse_id || '',
    date: filters.value.date || '',
    expense_category_id: filters.value.expense_category_id || '',
  }),
});
crud.fetchRows();

const categoryOptions = computed(() =>
  (crud.payload.value?.Expenses_category || []).map(c => ({ value: c.id, label: c.name })));
const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name })));
const accountOptions = computed(() =>
  (crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name })));
const paymentMethodOptions = computed(() =>
  (crud.payload.value?.payment_methods || []).map(p => ({ value: p.id, label: p.name })));

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('ModePaiement'), dataIndex: 'payment_method', key: 'payment_method' },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name', sorter: true },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, align: 'right', exportValue: r => money(r.amount) },
  { title: t('Categorie'), dataIndex: 'category_name', key: 'category_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('Details'), dataIndex: 'details', key: 'details', ellipsis: true },
  { title: t('Documents'), key: 'documents', width: 100, align: 'center' },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

/* -------------------------------------------------------------- PDF export */
const exporting = ref(false);
async function pdf() {
  exporting.value = true;
  try {
    const cols = columns.value.filter(c => !['documents', 'actions'].includes(c.key));
    await exportPdf(t('ListExpenses'), cols, crud.rows.value);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = false;
  }
}

/* --------------------------------------------------------------- documents */
const docsOpen = ref(false);
const documents = ref([]);
const selectedFiles = ref([]);
const uploadProcessing = ref(false);
let currentExpenseId = null;

const docColumns = computed(() => [
  { title: t('File_Name'), key: 'name' },
  { title: t('Size'), key: 'size', width: 100 },
  { title: t('Uploaded_Date'), key: 'created_at', width: 130 },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

function formatFileSize(bytes) {
  if (!bytes || bytes <= 0) return '0 B';
  const k = 1024; const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i];
}

function queueFile(file) {
  selectedFiles.value = [...selectedFiles.value, file];
  return false; // upload happens on the Upload button, like legacy
}

async function openDocuments(record) {
  currentExpenseId = record.id;
  selectedFiles.value = [];
  try {
    const data = await http.get(`expenses/${record.id}/documents`);
    documents.value = data.documents || [];
    docsOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function uploadDocuments() {
  if (!selectedFiles.value.length) return;
  uploadProcessing.value = true;
  try {
    const fd = new FormData();
    for (const f of selectedFiles.value) fd.append('documents[]', f.originFileObj || f);
    fd.append('expense_id', currentExpenseId);
    const resp = await uploadForm(`expenses/${currentExpenseId}/documents`, fd);
    if (resp.status >= 400) throw new Error('HTTP ' + resp.status);
    message.success(t('Successfully_Created'));
    selectedFiles.value = [];
    const data = await http.get(`expenses/${currentExpenseId}/documents`);
    documents.value = data.documents || [];
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    uploadProcessing.value = false;
  }
}

async function downloadDocument(doc) {
  try {
    await http.download(`expenses/documents/${doc.id}/download`, doc.name);
  } catch (e) {
    message.error(t('Failed_to_download_document'));
  }
}

async function removeDocument(documentId) {
  try {
    await http.delete(`expenses/documents/${documentId}`);
    message.success(t('Deleted_in_successfully'));
    const data = await http.get(`expenses/${currentExpenseId}/documents`);
    documents.value = data.documents || [];
    crud.fetchRows();
  } catch (e) {
    message.error(t('Delete_Therewassomethingwronge'));
  }
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
