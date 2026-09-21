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

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'status'">
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
              :options="accounts.map(a => ({ label: `${a.code} — ${a.name}`, value: a.id }))"
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

    <!-- View -->
    <a-modal v-model:open="viewOpen" :title="$t('Journal_Number', { number: current && current.id })" :footer="null" width="860px">
      <template v-if="current">
        <div style="margin: 12px 0 4px"><strong>{{ $t('date') }}:</strong> {{ current.date }}</div>
        <div style="margin-bottom: 12px"><strong>{{ $t('Description') }}:</strong> {{ current.description || '-' }}</div>
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
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, CheckOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import DataTable from '../../../components/DataTable.vue';
import { useCrudTable } from '../../../composables/useCrudTable';
import { useFormat } from '../../../composables/useFormat';
import http from '../../../lib/http';

const { t } = useI18n();
const { number } = useFormat();

const crud = useCrudTable('accounting/v2/journal-entries', {
  sortField: 'date',
  sortType: 'desc',
  select: p => {
    const rows = (p && (p.data || p.rows)) || [];
    return { rows, total: (p && (p.total ?? p.totalRows ?? rows.length)) || 0 };
  },
});

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
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  { title: t('Source'), dataIndex: 'reference_type', key: 'reference_type' },
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
  return a ? `${a.code} — ${a.name}` : id;
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
