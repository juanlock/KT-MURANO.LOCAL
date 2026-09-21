<template>
  <div class="page">
    <PageHeader :title="$t('Payroll')" :breadcrumb="[$t('hrm'), $t('Payroll')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- No bulk delete: the API has no payroll/delete/by_selection route. -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'payment_status'">
          <a-tag v-if="record.payment_status === 'paid'" color="green">{{ $t('Paid') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.Ref })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editing ? $t('Edit') : $t('Add')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-form-item :label="$t('date')" name="date">
          <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Employee')" name="employee_id">
          <a-select
            v-model:value="form.employee_id"
            :placeholder="$t('Choose_Employee')"
            show-search
            option-filter-prop="label"
            :options="employeeOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('Account')" name="account_id">
          <a-select
            v-model:value="form.account_id"
            :placeholder="$t('Choose_Account')"
            allow-clear
            show-search
            option-filter-prop="label"
            :options="accountOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('Amount')" name="amount">
          <a-input-number v-model:value="form.amount" :min="0" style="width: 100%" :placeholder="$t('Paying_Amount')" />
        </a-form-item>
        <a-form-item :label="$t('Paymentchoice')" name="payment_method_id">
          <a-select
            v-model:value="form.payment_method_id"
            :placeholder="$t('PleaseSelect')"
            :options="paymentMethodOptions"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET payroll → {payrolls, totalRows, accounts, employees, payment_methods}
 * — the form's selects ship in the list payload, no bootstrap endpoint.
 * POST/PUT payroll with {date, employee_id, account_id, amount,
 * payment_method_id}. No bulk delete: legacy's button called a method that
 * doesn't exist and the API has no by_selection route.
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

const crud = useCrudTable('payroll', { rowsKey: 'payrolls' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Employee'), dataIndex: 'employee_name', key: 'employee_name' },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', align: 'right', sorter: true, exportValue: r => money(r.amount) },
  { title: t('ModePaiement'), dataIndex: 'payment_method', key: 'payment_method' },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const formRef = ref();

const form = ref(emptyForm());

function emptyForm() {
  return {
    date: null, employee_id: undefined, account_id: undefined,
    amount: 0, payment_method_id: undefined,
  };
}

const employeeOptions = computed(() =>
  (crud.payload.value?.employees || []).map(e => ({ value: e.id, label: e.username }))
);
const accountOptions = computed(() =>
  (crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name }))
);
const paymentMethodOptions = computed(() =>
  (crud.payload.value?.payment_methods || []).map(m => ({ value: m.id, label: m.name }))
);

const formRules = computed(() => ({
  date: [{ required: true, message: t('Field_is_required') }],
  employee_id: [{ required: true, message: t('Field_is_required') }],
  amount: [{ required: true, message: t('Field_is_required') }],
  payment_method_id: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  editing.value = null;
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = {
    date: record.date || null,
    employee_id: record.employee_id,
    account_id: record.account_id || undefined,
    amount: Number(record.amount) || 0,
    payment_method_id: record.payment_method_id || undefined,
  };
  modalOpen.value = true;
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    if (editing.value) {
      await http.put(`payroll/${editing.value.id}`, form.value);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('payroll', form.value);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
