<template>
  <div class="page">
    <PageHeader :title="$t('Expense_Category')" :breadcrumb="[$t('Accounting'), $t('Expense_Category')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 12px">
      <a-space wrap>
        <a-select
          v-model:value="groupFilter" allow-clear show-search option-filter-prop="label"
          :placeholder="L.group" :options="groupFilterOptions" style="min-width: 260px"
          @change="crud.reload()"
        />
      </a-space>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span>{{ localName(record) }}</span>
          <a-tooltip v-if="record.is_common" :title="L.commonHelp"><StarFilled style="color: #faad14; margin-left: 6px" /></a-tooltip>
          <div v-if="record.reference" class="muted">{{ record.reference }}</div>
        </template>
        <template v-else-if="column.key === 'expense_group'">{{ localName(record, 'expense_group') || '—' }}</template>
        <template v-else-if="column.key === 'account_type'">
          <a-tag v-if="record.account_type" :color="TYPE_COLORS[record.account_type]">{{ typeLabel(record.account_type) }}</a-tag>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'coa_code'">
          <template v-if="record.coa_code">{{ accountLabel(record.coa_code) }}</template>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'period'">{{ localName(record, 'period') || '—' }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: localName(record) })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      :width="720"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-row :gutter="12">
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.name + ' *'" name="name" :extra="L.nameHelp">
              <a-input v-model:value="form.name" />
            </a-form-item>
          </a-col>
          <!-- Reference: a short explanation of what the category is for. -->
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.reference" :extra="L.referenceHelp">
              <a-input v-model:value="form.reference" :maxlength="255" show-count />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.groupEn">
              <a-auto-complete v-model:value="form.expense_group" :options="groupOptionsEn" :filter-option="filterText" @select="onGroupPicked" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.groupEs">
              <a-input v-model:value="form.expense_group_es" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.type">
              <a-select v-model:value="form.account_type" allow-clear :options="typeOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.account" :extra="L.accountHelp">
              <a-select
                v-model:value="form.coa_code" allow-clear show-search option-filter-prop="label"
                :options="accountOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.periodEn">
              <a-input v-model:value="form.period" placeholder="Monthly" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.periodEs">
              <a-input v-model:value="form.period_es" placeholder="Mensual" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.amount">
              <a-input v-model:value="form.approximate_amount" placeholder="USD 200–USD 5,000 per month" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="L.typical">
              <a-input v-model:value="form.typical_account" placeholder="Utilities Expense" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item>
              <a-checkbox v-model:checked="form.is_common">{{ L.common }}</a-checkbox>
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="form.description" :rows="3" :placeholder="$t('Afewwords')" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Expense categories, per company -- GET expenses_category → {Expenses_category
 * (capital E!), totalRows, accounts (the company's expense/asset accounts),
 * groups}; POST/PUT expenses_category; DELETE expenses_category/{id}.
 * The name is translated in Settings -> Translations (key = the name; shown
 * in the user's language by lib/localName); reference = a short explanation;
 * the expense-list columns: group, account type, linked account (expenses of
 * the category post there), period, approximate amount, typical account,
 * most common in its group. Every company starts with the default set
 * (App\Support\DefaultAccounting).
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, StarFilled } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { sortByLabel } from '../../lib/filterOptions';
import { useSystemContext } from '../../lib/systemContext';
import { localName, isSpanish } from '../../lib/localName';
import http from '../../lib/http';

const { t } = useI18n();
const { isSystem } = useSystemContext();
// Follows the user's language live (lib/localName).
const es = computed(() => isSpanish());

// Labels for the new columns, in the user's language.
const L = computed(() => (es.value ? {
  group: 'Grupo de gasto', type: 'Tipo de cuenta', account: 'Cuenta contable', accountHelp: 'Los gastos de esta categoría se registran en esta cuenta.',
  period: 'Periodo', amount: 'Monto aproximado (USD)', typical: 'Cuenta típica', common: 'La más usada de su grupo',
  commonHelp: 'La más usada de su grupo', name: 'Nombre', nameHelp: 'Su traducción se edita en Configuración → Traducciones.', reference: 'Referencia', referenceHelp: 'Breve explicación de la categoría.', groupEn: 'Grupo (inglés)', groupEs: 'Grupo (español)',
  periodEn: 'Periodo (inglés)', periodEs: 'Periodo (español)',
} : {
  group: 'Expense group', type: 'Account type', account: 'Account', accountHelp: 'Expenses of this category post to this account.',
  period: 'Period', amount: 'Approximate amount (USD)', typical: 'Typical account', common: 'Most commonly used in its group',
  commonHelp: 'Most commonly used in its group', name: 'Name', nameHelp: 'Translated in Settings → Translations.', reference: 'Reference', referenceHelp: 'A short explanation of the category.', groupEn: 'Group (English)', groupEs: 'Group (Spanish)',
  periodEn: 'Period (English)', periodEs: 'Period (Spanish)',
}));
const TYPES = {
  cogs: ['Cost of Goods Sold', 'Costo de bienes vendidos'],
  operating_expense: ['Operating Expense', 'Gasto operativo'],
  other_expense: ['Other Expense', 'Otro gasto'],
  asset: ['Asset Account', 'Cuenta de activo'],
};
const TYPE_COLORS = { cogs: 'purple', operating_expense: 'blue', other_expense: 'orange', asset: 'green' };
const typeLabel = k => (TYPES[k] ? TYPES[k][es.value ? 1 : 0] : k);
const typeOptions = computed(() => sortByLabel(Object.keys(TYPES).map(k => ({ value: k, label: typeLabel(k) }))));

const groupFilter = ref(undefined);
const crud = useCrudTable('expenses_category', {
  rowsKey: 'Expenses_category',
  sortField: 'sort_order',
  sortType: 'asc',
  limit: 50,
  params: () => (groupFilter.value ? { expense_group: groupFilter.value } : {}),
});
crud.fetchRows();

const accounts = computed(() => crud.payload.value?.accounts || []);
const groups = computed(() => crud.payload.value?.groups || []);
const accountLabel = code => {
  const a = accounts.value.find(x => x.code === code);
  return a ? `${a.code} — ${localName(a)}` : code;
};
const accountOptions = computed(() => accounts.value.map(a => ({ value: a.code, label: `${a.code} — ${localName(a)}` })));
const groupFilterOptions = computed(() => sortByLabel(groups.value.map(g => ({ value: g.expense_group, label: localName(g, 'expense_group') }))));
const groupOptionsEn = computed(() => sortByLabel(groups.value.map(g => ({ value: g.expense_group, label: g.expense_group }))));
const filterText = (input, option) => String(option.value || '').toLowerCase().includes(String(input || '').toLowerCase());
// Picking an existing group also fills its Spanish name.
function onGroupPicked(value) {
  const g = groups.value.find(x => x.expense_group === value);
  if (g && !form.value.expense_group_es) form.value.expense_group_es = g.expense_group_es || '';
}

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true, exportValue: r => localName(r) },
  // superadmin/AdminSystem: every company (2026-10-21)
  ...(isSystem.value ? [{ title: t('Company'), key: 'company_name', dataIndex: 'company_name', exportValue: r => r.company_name || '' }] : []),
  { title: L.value.reference, dataIndex: 'reference', key: 'reference', ellipsis: true, defaultHidden: true },
  { title: L.value.group, dataIndex: 'expense_group', key: 'expense_group', sorter: true, exportValue: r => localName(r, 'expense_group') },
  { title: L.value.type, dataIndex: 'account_type', key: 'account_type', sorter: true, exportValue: r => typeLabel(r.account_type) },
  { title: L.value.account, dataIndex: 'coa_code', key: 'coa_code', sorter: true, exportValue: r => (r.coa_code ? accountLabel(r.coa_code) : '') },
  { title: L.value.period, dataIndex: 'period', key: 'period', exportValue: r => localName(r, 'period') },
  { title: L.value.amount, dataIndex: 'approximate_amount', key: 'approximate_amount', ellipsis: true },
  { title: L.value.typical, dataIndex: 'typical_account', key: 'typical_account', ellipsis: true, defaultHidden: true },
  { title: t('Description'), dataIndex: 'description', key: 'description', ellipsis: true, defaultHidden: true },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const blank = () => ({
  id: null, name: '', reference: '', description: '', account_type: undefined, expense_group: '', expense_group_es: '',
  typical_account: '', period: '', period_es: '', approximate_amount: '', is_common: false, coa_code: undefined,
});
const form = ref(blank());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = blank();
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    ...blank(),
    ...Object.fromEntries(Object.keys(blank()).map(k => [k, record[k] ?? blank()[k]])),
    is_common: !!record.is_common,
    coa_code: record.coa_code || undefined,
    account_type: record.account_type || undefined,
  };
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  submitting.value = true;
  try {
    const { id, ...body } = form.value;
    body.coa_code = body.coa_code || null;
    body.account_type = body.account_type || null;
    if (editMode.value) {
      await http.put(`expenses_category/${id}`, body);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('expenses_category', body);
      message.success(t('Created_in_successfully'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.muted {
  font-size: 12px;
  opacity: 0.55;
}
</style>
