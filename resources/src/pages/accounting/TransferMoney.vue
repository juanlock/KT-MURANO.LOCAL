<template>
  <div class="page">
    <PageHeader :title="$t('Transfers_Money')" :breadcrumb="[$t('Accounting'), $t('Transfers_Money')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
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
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('date') + ' *'" name="date">
              <a-input v-model:value="form.date" type="date" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Amount') + ' *'" name="amount">
              <a-input v-model:value="form.amount" :placeholder="$t('Amount')" />
            </a-form-item>
          </a-col>
          <!-- Legacy hides the account selects in edit mode (accounts can't change). -->
          <template v-if="!editMode">
            <a-col :span="12">
              <a-form-item :label="$t('From_Account') + ' *'" name="from_account_id">
                <a-select
                  v-model:value="form.from_account_id"
                  :placeholder="$t('Choose_Account')"
                  :options="accountOptions"
                  show-search option-filter-prop="label"
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('To_Account') + ' *'" name="to_account_id">
                <a-select
                  v-model:value="form.to_account_id"
                  :placeholder="$t('Choose_Account')"
                  :options="accountOptions"
                  show-search option-filter-prop="label"
                />
              </a-form-item>
            </a-col>
          </template>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transfer Money — GET transfer_money → {transfers, accounts, totalRows};
 * POST/PUT transfer_money with {from_account_id, to_account_id, amount, date};
 * DELETE transfer_money/{id}. Legacy validation: date/amount/from/to required,
 * amount ^\d*\.?\d*$; from === to rejected client-side before POST with the
 * Accounts_cannot_be_the_same toast; edit mode hides account selects.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money, date } = useFormat();

const crud = useCrudTable('transfer_money', { rowsKey: 'transfers' });
crud.fetchRows();

const accountOptions = computed(() =>
  (crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name }))
);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('From_Account'), dataIndex: 'from_account', key: 'from_account' },
  { title: t('To_Account'), dataIndex: 'to_account', key: 'to_account' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, align: 'right', exportValue: r => money(r.amount) },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();

const emptyForm = () => ({
  id: null,
  from_account_id: undefined,
  to_account_id: undefined,
  amount: '',
  date: new Date().toISOString().slice(0, 10),
});
const form = ref(emptyForm());

const NUM_RE = /^\d*\.?\d*$/;
const rules = computed(() => ({
  date: [{ required: true, message: t('Field_is_required') }],
  amount: [
    { required: true, message: t('Field_is_required') },
    { validator: (_r, v) => (NUM_RE.test(String(v ?? '')) ? Promise.resolve() : Promise.reject(t('InvalidData'))) },
  ],
  from_account_id: editMode.value ? [] : [{ required: true, message: t('Field_is_required') }],
  to_account_id: editMode.value ? [] : [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    id: record.id,
    from_account_id: record.from_account_id,
    to_account_id: record.to_account_id,
    amount: record.amount,
    date: record.date,
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
  // Legacy blocks same-account transfers client-side before POST.
  if (!editMode.value && form.value.from_account_id === form.value.to_account_id) {
    message.error(t('Accounts_cannot_be_the_same'));
    return;
  }
  submitting.value = true;
  const body = {
    from_account_id: form.value.from_account_id,
    to_account_id: form.value.to_account_id,
    amount: form.value.amount,
    date: form.value.date,
  };
  try {
    if (editMode.value) {
      await http.put(`transfer_money/${form.value.id}`, body);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('transfer_money', body);
      message.success(t('Created_in_successfully'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}
</script>
