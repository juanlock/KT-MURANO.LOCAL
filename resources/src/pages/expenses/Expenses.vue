<template>
  <div class="page">
    <PageHeader :title="$t('ListExpenses')" :breadcrumb="[$t('Accounting'), $t('ListExpenses')]">
      <template #actions>
        <a-space>
          <!-- Recurring expenses: bills with the same amount every period,
               created automatically on their dates (set from Create Expense). -->
          <a-button @click="openRecurring">
            <template #icon><SyncOutlined /></template>
            Recurring<a-badge v-if="recurringCount" :count="recurringCount" :number-style="{ backgroundColor: '#6d28d9' }" style="margin-inline-start: 6px" />
          </a-button>
          <a-button :loading="exporting" @click="pdf">PDF</a-button>
          <a-button v-if="auth.can('expense_add')" type="primary" @click="$router.push('/expenses/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('Create_Expense') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="filters-reset-wrap">
      <a-row :gutter="[16, 8]" class="filters-reset-wrap__row">
        <!-- The system date filter: presets + editable Start date / to. -->
        <a-col :span="24">
          <div class="filter-label">{{ $t('date') }}</div>
          <PresetRangeFilter v-model:value="range" @change="crud.reload()" />
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
      <ResetFiltersButton :dirty="filtersDirty" @reset="resetFilters" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'amount'">
          {{ money(record.amount) }}
          <a-tooltip v-if="record.recurring_expense_id" title="Created automatically by a recurring expense">
            <SyncOutlined style="color: #6d28d9; margin-inline-start: 4px" />
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'category_name'">{{ localName(record, 'category_name') }}</template>
        <template v-else-if="column.key === 'category_group'">{{ localName(record, 'category_group') || '—' }}</template>
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
            <!-- Payroll expenses follow their payroll: change them in HRM -> Payroll. -->
            <a-tooltip v-if="record.payroll_id" :title="$t('Edit or delete the payroll instead')">
              <a-tag color="purple" style="cursor: pointer" @click="$router.push('/hrm/payrolls')">{{ $t('Payroll') }}</a-tag>
            </a-tooltip>
            <a-tooltip v-if="!record.payroll_id && auth.can('expense_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/expenses/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="!record.payroll_id && auth.can('expense_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.Ref })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Recurring expenses: same amount every period, created automatically. -->
    <a-modal v-model:open="recurringOpen" title="Recurring expenses" :footer="null" width="980px">
      <a-alert
        type="info" show-icon style="margin-bottom: 12px"
        message="Bills with the same amount every week / month / 3 months / year."
        description="Each one is created automatically on its date (and posted to Accounting). Set one up from Create Expense → How often? → Same amount every…  Pausing stops the next ones; the expenses already created stay."
      />
      <a-table
        :columns="recurringColumns" :data-source="recurringRows" :loading="recurringLoading"
        row-key="id" size="small" :pagination="false" :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: 'No recurring expenses yet.' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'category_name'">
            {{ localName({ name: record.category_name }) }}
            <div class="rec-muted">{{ record.details }}</div>
          </template>
          <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
          <template v-else-if="column.key === 'frequency'">{{ FREQ_LABEL[record.frequency] || record.frequency }}</template>
          <template v-else-if="column.key === 'next_date'">{{ record.is_active ? date(record.next_date) : '—' }}</template>
          <template v-else-if="column.key === 'end_date'">{{ record.end_date ? date(record.end_date) : 'No end' }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag v-if="record.is_active" color="green">Active</a-tag>
            <a-tag v-else-if="record.end_date && record.next_date > record.end_date" color="default">Finished</a-tag>
            <a-tag v-else color="orange">Paused</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button v-if="auth.can('expense_edit')" size="small" @click="toggleRecurring(record)">
                {{ record.is_active ? 'Pause' : 'Resume' }}
              </a-button>
              <a-popconfirm
                v-if="auth.can('expense_delete')" title="Stop this recurring expense for good? Expenses already created stay."
                :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="deleteRecurring(record)"
              >
                <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-modal>

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
  PlusOutlined, EditOutlined, DeleteOutlined, FileOutlined, UploadOutlined, DownloadOutlined, SyncOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import { rangeModel } from '../../lib/datePresets';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFilterReset } from '../../composables/useFilterReset';
import ResetFiltersButton from '../../components/ResetFiltersButton.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { sortByLabel } from '../../lib/filterOptions';
import { localName, isSpanish } from '../../lib/localName';

const { t } = useI18n();
const { money, date } = useFormat();
const auth = useAuthStore();

const filters = ref({
  date_from: '', date_to: '', Ref: '', payment_method_id: undefined,
  account_id: undefined, warehouse_id: undefined, expense_category_id: undefined,
});
const { isDirty: filtersDirty, resetFilters } = useFilterReset(filters, () => crud.reload());
// Date range (presets + editable Start date / to); none = All.
const range = rangeModel(() => [filters.value.date_from, filters.value.date_to], (a, b) => { filters.value.date_from = a; filters.value.date_to = b; });

const crud = useCrudTable('expenses', {
  rowsKey: 'expenses',
  params: () => ({
    Ref: filters.value.Ref || '',
    account_id: filters.value.account_id || '',
    payment_method_id: filters.value.payment_method_id || '',
    warehouse_id: filters.value.warehouse_id || '',
    date: '',
    date_from: filters.value.date_from || '',
    date_to: filters.value.date_to || '',
    expense_category_id: filters.value.expense_category_id || '',
  }),
});
crud.fetchRows();

/* ---------------- recurring expenses ---------------- */
const FREQ_LABEL = { weekly: 'Every week', monthly: 'Every month', quarterly: 'Every 3 months', yearly: 'Every year' };
const recurringCount = computed(() => crud.payload.value?.recurring_count || 0);
const recurringOpen = ref(false);
const recurringLoading = ref(false);
const recurringRows = ref([]);
const recurringColumns = [
  { title: 'Category / details', key: 'category_name' },
  { title: 'Amount', key: 'amount', align: 'right' },
  { title: 'How often', key: 'frequency' },
  { title: 'Next one', key: 'next_date' },
  { title: 'Until', key: 'end_date' },
  { title: 'Created', dataIndex: 'generated_count', key: 'generated_count', align: 'center' },
  { title: 'Status', key: 'status' },
  { title: '', key: 'actions' },
];
async function loadRecurring() {
  recurringLoading.value = true;
  try {
    recurringRows.value = (await http.get('expenses/recurring')).recurring || [];
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    recurringLoading.value = false;
  }
}
function openRecurring() {
  recurringOpen.value = true;
  loadRecurring();
}
async function toggleRecurring(row) {
  try {
    await http.put(`expenses/recurring/${row.id}`, { is_active: !row.is_active });
    message.success(row.is_active ? 'Paused — no more will be created until you resume it.' : 'Resumed.');
    loadRecurring();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}
async function deleteRecurring(row) {
  try {
    await http.delete(`expenses/recurring/${row.id}`);
    message.success('Stopped.');
    loadRecurring();
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

// Per-company categories, in the user's language (name / name_es).
const categoryOptions = computed(() =>
  sortByLabel((crud.payload.value?.Expenses_category || []).map(c => ({ value: c.id, label: localName(c) }))));
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
  { title: t('Categorie'), dataIndex: 'category_name', key: 'category_name', sorter: true, exportValue: r => localName(r, 'category_name') },
  // Expense group of the category (expense-list column).
  { title: isSpanish() ? 'Grupo de gasto' : 'Expense group', dataIndex: 'category_group', key: 'category_group', exportValue: r => localName(r, 'category_group') },
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
.rec-muted {
  font-size: 12px;
  opacity: 0.6;
}
.filter-label {
  margin-bottom: 4px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
</style>
