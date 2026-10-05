<template>
  <div class="page">
    <PageHeader :title="$t('Journal_Entries_Title')" :breadcrumb="[$t('Accounting'), $t('Journal_Entries_Title')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Filters: the shared date range (presets, double-click = default) + source,
         status, account, amount. -->
    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]" align="bottom">
        <!-- Full width: the preset buttons and the calendar stay on one row. -->
        <a-col :span="24">
          <div class="filter-label">{{ $t('date') }}</div>
          <PresetRangeFilter v-model:value="filters.range" :apply-default="!linkedRange" @change="reload" />
        </a-col>
        <a-col :xs="12" :md="6" :lg="5">
          <div class="filter-label">{{ $t('Source') }}</div>
          <a-select v-model:value="filters.reference_type" allow-clear style="width: 100%" placeholder="All" :options="sourceTypeOptions" @change="reload" />
        </a-col>
        <a-col :xs="12" :md="6" :lg="3">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="filters.status" allow-clear style="width: 100%" placeholder="All" @change="reload"
            :options="[{ value: 'posted', label: t('Journal_Status_Posted') }, { value: 'draft', label: t('Journal_Status_Draft') }]"
          />
        </a-col>
        <a-col :xs="24" :md="12" :lg="6">
          <div class="filter-label">{{ $t('Account') }}</div>
          <a-select
            v-model:value="filters.coa_id" allow-clear show-search option-filter-prop="label" style="width: 100%"
            :placeholder="$t('Select_Account')" :options="accountOptions" @change="reload"
          />
        </a-col>
        <a-col :xs="12" :md="6" :lg="3">
          <div class="filter-label">Amount from</div>
          <a-input-number v-model:value="filters.amount_min" :min="0" style="width: 100%" @change="reloadSoon" />
        </a-col>
        <a-col :xs="12" :md="6" :lg="3">
          <div class="filter-label">Amount to</div>
          <a-input-number v-model:value="filters.amount_max" :min="0" style="width: 100%" @change="reloadSoon" />
        </a-col>
        <a-col flex="none">
          <ResetFiltersButton :dirty="hasFilters" @reset="resetFilters" />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'id'">#{{ record.id }}</template>
        <!-- Description + the source document: click to see it. -->
        <template v-else-if="column.key === 'description'">
          <div>{{ record.description || '—' }}</div>
          <a v-if="record.source_label" class="je-source" @click="openSource(record)">
            <LinkOutlined /> {{ record.source_label }}
          </a>
        </template>
        <template v-else-if="column.key === 'reference_type'">
          <a-tag>{{ record.source_type_label || record.reference_type }}</a-tag>
        </template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="record.status === 'posted' ? 'success' : 'warning'">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('View')">
              <a-button size="small" @click="view(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="record.status !== 'posted'" :title="$t('Post')">
              <a-button size="small" :loading="postingId === record.id" @click="post(record)">
                <template #icon><CheckOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-button v-if="record.status !== 'posted'" size="small" @click="tryEdit(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button v-if="record.status !== 'posted'" size="small" danger @click="tryDelete(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Create / edit -->
    <a-modal
      v-model:open="modalOpen" :title="editing ? $t('Edit_Entry') : $t('New_Entry')"
      :confirm-loading="saving" width="920px"
      :ok-button-props="{ disabled: !entry.date || !linesValid }"
      @ok="save"
    >
      <a-row :gutter="16" style="margin-top: 12px">
        <a-col :xs="24" :md="8">
          <a-form-item :label="$t('date')">
            <a-input v-model:value="entry.date" type="date" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="16">
          <a-form-item :label="$t('Description')">
            <a-input v-model:value="entry.description" :placeholder="$t('Description_Placeholder')" />
          </a-form-item>
        </a-col>
      </a-row>

      <a-table
        :columns="lineColumns" :data-source="entry.lines"
        :pagination="false" size="small" :row-key="(_r, i) => i"
        :scroll="{ x: 'max-content' }"
      >
        <template #bodyCell="{ column, record, index }">
          <template v-if="column.key === 'coa_id'">
            <a-select
              v-model:value="record.coa_id" :placeholder="$t('Select_Account')"
              show-search option-filter-prop="label" style="width: 100%"
              :status="showErrors && !record.coa_id ? 'error' : ''"
              :options="accounts.filter(a => !(a.company_id != null && String(a.code).includes('-'))).map(a => ({ label: `${a.code} — ${localName(a)}`, value: a.id }))"
            />
          </template>
          <template v-else-if="column.key === 'debit'">
            <a-input-number
              v-model:value="record.debit" :min="0" :step="0.01" style="width: 100%"
              :status="showErrors && !validRow(record) ? 'error' : ''"
              @change="onAmountChange(index, 'debit')"
            />
          </template>
          <template v-else-if="column.key === 'credit'">
            <a-input-number
              v-model:value="record.credit" :min="0" :step="0.01" style="width: 100%"
              :status="showErrors && !validRow(record) ? 'error' : ''"
              @change="onAmountChange(index, 'credit')"
            />
          </template>
          <template v-else-if="column.key === 'memo'">
            <a-input v-model:value="record.memo" />
          </template>
          <template v-else-if="column.key === 'action'">
            <a-button type="text" size="small" danger :disabled="entry.lines.length <= 1" @click="removeLine(index)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </template>
        </template>
      </a-table>

      <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; flex-wrap: wrap; gap: 8px">
        <a-button size="small" @click="addLine">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add_Line') }}
        </a-button>
        <a-space wrap>
          <span>{{ $t('Total_Debit') }}: <strong>{{ number(totals.debit) }}</strong></span>
          <span>{{ $t('Total_Credit') }}: <strong>{{ number(totals.credit) }}</strong></span>
          <a-tag :color="balanced ? 'success' : 'warning'">{{ balanced ? $t('Balanced') : $t('Not_Balanced') }}</a-tag>
        </a-space>
      </div>
    </a-modal>

    <!-- Source document of an entry (sale, payment, expense...). -->
    <a-modal v-model:open="sourceOpen" :title="source?.title || $t('Source')" :footer="null" width="760px">
      <a-spin :spinning="sourceLoading">
        <template v-if="source">
          <a-descriptions :column="2" size="small" bordered style="margin-top: 8px">
            <a-descriptions-item :label="$t('Type')">{{ source.type_label }}</a-descriptions-item>
            <a-descriptions-item label="Ref">{{ source.ref || '—' }}</a-descriptions-item>
            <a-descriptions-item v-for="(v, k) in source.fields" :key="k" :label="k">
              {{ ['Total', 'Amount', 'Opening cash'].includes(k) ? money(v) : v }}
            </a-descriptions-item>
          </a-descriptions>
          <a-table
            v-if="source.lines && source.lines.length" style="margin-top: 12px" size="small" :pagination="false" row-key="name"
            :data-source="source.lines" :columns="sourceLineColumns"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'price' || column.key === 'total'">{{ money(record[column.key]) }}</template>
            </template>
          </a-table>
          <div style="margin-top: 12px; text-align: right">
            <a-button v-if="source.link" type="primary" @click="goSource">
              <template #icon><LinkOutlined /></template> Open {{ source.type_label.toLowerCase() }}
            </a-button>
          </div>
        </template>
        <a-empty v-else-if="!sourceLoading" description="This entry has no source document (manual entry)." />
      </a-spin>
    </a-modal>

    <!-- View -->
    <a-modal v-model:open="viewOpen" :title="$t('Journal_Number', { number: current && current.id })" :footer="null" width="860px">
      <template v-if="current">
        <div style="margin: 12px 0 4px"><strong>{{ $t('date') }}:</strong> {{ current.date }}</div>
        <div style="margin-bottom: 12px"><strong>{{ $t('Description') }}:</strong> {{ current.description || '-' }}</div>
        <div v-if="current.source_label" style="margin-bottom: 12px">
          <strong>{{ $t('Source') }}:</strong> <a @click="openSource(current)"><LinkOutlined /> {{ current.source_label }}</a>
        </div>
        <a-table
          :columns="viewColumns" :data-source="current.lines || []"
          :pagination="false" size="small" :row-key="(_r, i) => i"
          :scroll="{ x: 'max-content' }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'account'">{{ accountName(record.coa_id) }}</template>
            <template v-else-if="column.key === 'debit'">{{ number(record.debit) }}</template>
            <template v-else-if="column.key === 'credit'">{{ number(record.credit) }}</template>
          </template>
        </a-table>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Journal entries (v2) — GET accounting/v2/journal-entries (paginator shape:
 * data/total OR rows/totalRows). Accounts for the line selector from
 * accounting/v2/coa?limit=-1&active=1. Legacy line validation verbatim:
 * each line needs coa_id + EXACTLY ONE of debit/credit > 0; typing a debit
 * zeroes the credit and vice versa; the Balanced badge is display-only (save
 * only requires date + valid lines). Posted entries are read-only; draft can
 * be posted via POST .../{id}/post.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, CheckOutlined, LinkOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import DataTable from '../../../components/DataTable.vue';
import PresetRangeFilter from '../../../components/PresetRangeFilter.vue';
import ResetFiltersButton from '../../../components/ResetFiltersButton.vue';
import { useCrudTable } from '../../../composables/useCrudTable';
import { useFormat } from '../../../composables/useFormat';
import { sortByLabel } from '../../../lib/filterOptions';
import http from '../../../lib/http';
import { localName } from '../../../lib/localName';

const { t } = useI18n();
const { number, money } = useFormat();
const router = useRouter();

// ---------------- filters ----------------
const emptyFilters = () => ({ range: null, reference_type: undefined, status: undefined, coa_id: undefined, amount_min: null, amount_max: null });
const filters = ref(emptyFilters());
// ?from=&to= (e.g. the Accounting dashboard's Journal Entries card): open
// on that range instead of the user's default preset.
const route = useRoute();
const linkedRange = /^\d{4}-\d{2}-\d{2}$/.test(String(route.query.from || '')) && /^\d{4}-\d{2}-\d{2}$/.test(String(route.query.to || ''))
  ? [String(route.query.from), String(route.query.to)]
  : null;
if (linkedRange) filters.value.range = linkedRange;
const hasFilters = computed(() => {
  const f = filters.value;
  return !!(f.reference_type || f.status || f.coa_id || f.amount_min != null || f.amount_max != null);
});

const crud = useCrudTable('accounting/v2/journal-entries', {
  sortField: 'date',
  sortType: 'desc',
  params: () => {
    const f = filters.value;
    return {
      from: f.range?.[0] || '', to: f.range?.[1] || '',
      reference_type: f.reference_type || '', status: f.status || '', coa_id: f.coa_id || '',
      amount_min: f.amount_min ?? '', amount_max: f.amount_max ?? '',
    };
  },
  select: p => {
    const rows = (p && (p.data || p.rows)) || [];
    return { rows, total: (p && (p.total ?? p.totalRows ?? rows.length)) || 0 };
  },
});
const sourceTypeOptions = computed(() => sortByLabel(crud.payload.value?.source_types || []));
const allCompanies = computed(() => !!crud.payload.value?.all_companies);
const accountOptions = computed(() => sortByLabel(accounts.value.map(a => ({ label: `${a.code} — ${localName(a)}`, value: a.id }))));

function reload() { crud.reload ? crud.reload() : crud.fetchRows(); }
let amountTimer = null;
function reloadSoon() { clearTimeout(amountTimer); amountTimer = setTimeout(reload, 400); }
function resetFilters() {
  const range = filters.value.range;
  filters.value = { ...emptyFilters(), range };
  reload();
}

// ---------------- source modal ----------------
const sourceOpen = ref(false);
const sourceLoading = ref(false);
const source = ref(null);
const sourceLineColumns = [
  { title: 'Product / service', dataIndex: 'name', key: 'name' },
  { title: 'Qty', dataIndex: 'quantity', key: 'quantity', align: 'right' },
  { title: 'Price', key: 'price', align: 'right' },
  { title: 'Total', key: 'total', align: 'right' },
];
async function openSource(entryRow) {
  source.value = null;
  sourceOpen.value = true;
  sourceLoading.value = true;
  try {
    const data = await http.get(`accounting/v2/journal-entries/${entryRow.id}/source`);
    source.value = data?.source || null;
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    sourceLoading.value = false;
  }
}
function goSource() {
  if (!source.value?.link) return;
  sourceOpen.value = false;
  viewOpen.value = false;
  router.push(source.value.link);
}

const accounts = ref([]);
const modalOpen = ref(false);
const editing = ref(false);
const saving = ref(false);
const showErrors = ref(false);
const viewOpen = ref(false);
const current = ref(null);
const postingId = ref(null);

const emptyLine = () => ({ coa_id: null, debit: 0, credit: 0, memo: '' });
const entry = ref({ id: null, date: '', description: '', lines: [] });

const columns = computed(() => [
  { title: '#', dataIndex: 'id', key: 'id', width: 70 },
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  // AdminSystem (all companies): whose entry it is.
  ...(allCompanies.value ? [{ title: t('Company'), dataIndex: 'company_name', key: 'company_name' }] : []),
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  { title: t('Source'), dataIndex: 'reference_type', key: 'reference_type' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', align: 'right', sum: 'money' },
  { title: t('Status'), key: 'status', width: 100, align: 'center' },
  { title: t('Action'), key: 'actions', width: 160 },
]);
const lineColumns = computed(() => [
  { title: t('Account'), key: 'coa_id', width: 280 },
  { title: t('Debit'), key: 'debit', width: 140 },
  { title: t('Credit'), key: 'credit', width: 140 },
  { title: t('Memo'), key: 'memo', width: 160 },
  { title: '', key: 'action', width: 50 },
]);
const viewColumns = computed(() => [
  { title: t('Account'), key: 'account' },
  { title: t('Debit'), key: 'debit', align: 'right' },
  { title: t('Credit'), key: 'credit', align: 'right' },
  { title: t('Memo'), dataIndex: 'memo', key: 'memo' },
]);

const totals = computed(() =>
  entry.value.lines.reduce(
    (acc, l) => {
      acc.debit += Number(l.debit || 0);
      acc.credit += Number(l.credit || 0);
      return acc;
    },
    { debit: 0, credit: 0 },
  ));
const balanced = computed(() => Math.abs(totals.value.debit - totals.value.credit) < 0.0001);
const linesValid = computed(() => entry.value.lines.every(l => validRow(l)));

function statusLabel(status) {
  if (!status) return '';
  if (status === 'posted') return t('Journal_Status_Posted');
  if (status === 'draft') return t('Journal_Status_Draft');
  return t(status);
}
function accountName(id) {
  const a = accounts.value.find(x => x.id === id);
  return a ? `${a.code} — ${localName(a)}` : id;
}
function validRow(l) {
  const debit = Number(l.debit || 0);
  const credit = Number(l.credit || 0);
  if (!l.coa_id) return false;
  const hasOne = (debit > 0) !== (credit > 0);
  const nonNegative = debit >= 0 && credit >= 0;
  return hasOne && nonNegative;
}
function onAmountChange(idx, field) {
  const l = entry.value.lines[idx];
  const val = Number(l[field] || 0);
  if (val < 0 || Number.isNaN(val)) l[field] = 0;
  if (field === 'debit' && val > 0) l.credit = 0;
  if (field === 'credit' && val > 0) l.debit = 0;
}
function addLine() {
  entry.value.lines.push(emptyLine());
}
function removeLine(idx) {
  if (entry.value.lines.length <= 1) {
    message.warning(t('At_Least_One_Line'));
    return;
  }
  entry.value.lines.splice(idx, 1);
}

async function fetchAccounts() {
  try {
    const data = await http.get('accounting/v2/coa', {
      limit: -1, SortField: 'code', SortType: 'asc', active: 1,
    });
    accounts.value = (data && data.data) || [];
  } catch (e) {
    accounts.value = [];
  }
}

function openCreate() {
  editing.value = false;
  entry.value = {
    id: null,
    date: new Date().toISOString().slice(0, 10),
    description: '',
    lines: [emptyLine(), emptyLine()],
  };
  showErrors.value = false;
  modalOpen.value = true;
}
function view(j) {
  current.value = j;
  viewOpen.value = true;
}
function tryEdit(j) {
  if (j.status === 'posted') return;
  editing.value = true;
  entry.value = {
    id: j.id,
    date: j.date,
    description: j.description || '',
    lines: (j.lines || []).map(l => ({ coa_id: l.coa_id, debit: l.debit, credit: l.credit, memo: l.memo })),
  };
  showErrors.value = false;
  modalOpen.value = true;
}
async function save() {
  showErrors.value = true;
  if (!linesValid.value) {
    message.error(t('Complete_Lines_Message'));
    return;
  }
  saving.value = true;
  const payload = { date: entry.value.date, description: entry.value.description, lines: entry.value.lines };
  try {
    if (editing.value && entry.value.id) {
      await http.put(`accounting/v2/journal-entries/${entry.value.id}`, payload);
      message.success(t('Entry_Updated'));
    } else {
      await http.post('accounting/v2/journal-entries', payload);
      message.success(t('Entry_Created_Draft'));
    }
    modalOpen.value = false;
    await crud.fetchRows();
  } catch (e) {
    message.error(t('Operation_Failed'));
  } finally {
    saving.value = false;
  }
}
function tryDelete(j) {
  if (j.status === 'posted') return;
  Modal.confirm({
    title: t('Delete'),
    content: t('Delete_Draft_Entry_Question'),
    okText: t('Delete'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      try {
        await http.delete(`accounting/v2/journal-entries/${j.id}`);
        message.success(t('Deleted_Successfully'));
        await crud.fetchRows();
      } catch (e) {
        message.error(t('Delete_Failed'));
      }
    },
  });
}
async function post(j) {
  postingId.value = j.id;
  try {
    await http.post(`accounting/v2/journal-entries/${j.id}/post`);
    message.success(t('Posted_Successfully'));
    await crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || e?.data?.error || t('Post_Failed'));
  } finally {
    postingId.value = null;
  }
}

onMounted(() => {
  crud.fetchRows();
  fetchAccounts();
});
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  opacity: 0.6;
  margin-bottom: 4px;
}
.je-source {
  font-size: 12px;
}
</style>
