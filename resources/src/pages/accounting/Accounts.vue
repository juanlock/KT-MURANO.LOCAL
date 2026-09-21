<template>
  <div class="page">
    <PageHeader :title="$t('List_accounts')" :breadcrumb="[$t('Accounting'), $t('List_accounts')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'balance'">{{ money(record.balance) }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.account_name })">
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
        <a-form-item :label="$t('account_num') + ' *'" name="account_num">
          <a-input v-model:value="form.account_num" :placeholder="$t('Enter_account_num')" />
        </a-form-item>
        <a-form-item :label="$t('account_name') + ' *'" name="account_name">
          <a-input v-model:value="form.account_name" :placeholder="$t('Enter_account_name')" />
        </a-form-item>
        <!-- Legacy only takes the opening balance at creation. -->
        <a-form-item v-if="!editMode" :label="$t('initial_balance') + ' *'" name="initial_balance">
          <a-input v-model:value="form.initial_balance" :placeholder="$t('Enter_initial_balance')" />
        </a-form-item>
        <a-form-item :label="$t('Details')">
          <a-textarea v-model:value="form.note" :rows="4" :placeholder="$t('Afewwords')" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Accounts — GET accounts → {accounts, totalRows}; POST accounts
 * {account_num, account_name, initial_balance, note}; PUT accounts/{id}
 * WITHOUT initial_balance (legacy edit omits it); DELETE accounts/{id}.
 * Legacy validation: account_num + account_name required; initial_balance
 * required + ^\d*\.?\d*$ on create only.
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
const { money } = useFormat();

const crud = useCrudTable('accounts', { rowsKey: 'accounts' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('account_num'), dataIndex: 'account_num', key: 'account_num', sorter: true },
  { title: t('account_name'), dataIndex: 'account_name', key: 'account_name', sorter: true },
  { title: t('balance'), dataIndex: 'balance', key: 'balance', sorter: true, align: 'right', exportValue: r => money(r.balance) },
  { title: t('notes'), dataIndex: 'note', key: 'note', ellipsis: true },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const form = ref({ id: null, account_num: '', account_name: '', initial_balance: 0, note: '' });

const NUM_RE = /^\d*\.?\d*$/;
const rules = computed(() => ({
  account_num: [{ required: true, message: t('Field_is_required') }],
  account_name: [{ required: true, message: t('Field_is_required') }],
  initial_balance: editMode.value ? [] : [
    { required: true, message: t('Field_is_required') },
    { validator: (_r, v) => (NUM_RE.test(String(v ?? '')) ? Promise.resolve() : Promise.reject(t('InvalidData'))) },
  ],
}));

function openCreate() {
  editMode.value = false;
  form.value = { id: null, account_num: '', account_name: '', initial_balance: 0, note: '' };
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  form.value = {
    id: record.id,
    account_num: record.account_num,
    account_name: record.account_name,
    initial_balance: 0,
    note: record.note || '',
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
    if (editMode.value) {
      await http.put(`accounts/${form.value.id}`, {
        account_num: form.value.account_num,
        account_name: form.value.account_name,
        note: form.value.note,
      });
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('accounts', {
        account_num: form.value.account_num,
        account_name: form.value.account_name,
        initial_balance: form.value.initial_balance,
        note: form.value.note,
      });
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
