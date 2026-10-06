<template>
  <div class="page">
    <PageHeader :title="$t('Payroll')" :breadcrumb="[$t('hrm'), $t('Payroll')]">
      <template #actions>
        <a-space wrap>
          <a-button type="primary" @click="openWeekly">
            <template #icon><CalendarOutlined /></template>
            Generate weekly payroll
          </a-button>
          <a-button @click="openCreate">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- No bulk delete: the API has no payroll/delete/by_selection route. -->
    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'period'">
          <span v-if="record.period_start">{{ record.period_start }} – {{ record.period_end }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'pay_type'">
          <a-tag v-if="record.pay_type === 'hourly'" color="blue">Hours</a-tag>
          <a-tag v-else-if="record.pay_type === 'commission'" color="purple">Commission</a-tag>
          <span v-else class="muted">Manual</span>
        </template>
        <template v-else-if="column.key === 'detail'">
          <span v-if="record.pay_type === 'hourly'">{{ Number(record.hours) }} h × {{ money(record.rate) }}</span>
          <span v-else-if="record.pay_type === 'commission'">{{ Number(record.commission_rate) }}% of {{ money(record.commission_base) }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'payment_status'">
          <a-tag v-if="record.payment_status === 'paid'" color="green">{{ $t('Paid') }}</a-tag>
          <a-tag v-else color="orange">{{ record.payment_status }}</a-tag>
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

    <!-- Weekly run: pick a week, review, create (paid -> posted to accounting). -->
    <a-modal v-model:open="weekly.open" title="Generate weekly payroll" width="860px" :confirm-loading="weekly.saving"
      ok-text="Create payrolls" :ok-button-props="{ disabled: !weeklyNew.length }" @ok="createWeekly">
      <a-row :gutter="12" align="bottom">
        <a-col :xs="24" :md="8">
          <a-form-item label="Week (Monday – Sunday)" style="margin-bottom: 8px">
            <a-date-picker v-model:value="weekly.date" picker="week" value-format="YYYY-MM-DD" style="width: 100%" @change="loadWeekly" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-form-item :label="$t('Paymentchoice') + ' *'" style="margin-bottom: 8px">
            <a-select v-model:value="weekly.payment_method_id" :options="paymentMethodOptions" :placeholder="$t('PleaseSelect')" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-form-item :label="$t('Account')" style="margin-bottom: 8px">
            <a-select v-model:value="weekly.account_id" allow-clear :options="accountOptions" placeholder="Cash" />
          </a-form-item>
        </a-col>
      </a-row>
      <div class="muted" style="margin-bottom: 8px">
        {{ weekly.period_start }} – {{ weekly.period_end }}. Hours = hours on the service jobs each person completed that week;
        commission = their sales that week. Created as paid and posted to accounting (Salaries &amp; Wages / Commissions).
      </div>
      <a-table :data-source="weekly.rows" :columns="weeklyColumns" :pagination="false" size="small" row-key="employee_id" :loading="weekly.loading"
        :locale="{ emptyText: 'Nobody is set to be paid by hours or commission (Employee -> Company tab -> Paid by).' }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'pay_type'">{{ record.pay_type === 'hourly' ? 'Hours' : 'Commission' }}</template>
          <template v-else-if="column.key === 'basis'">
            <span v-if="record.pay_type === 'hourly'">{{ record.hours }} h on {{ record.jobs }} job(s) × {{ money(record.rate) }}</span>
            <span v-else>{{ record.commission_rate }}% of {{ money(record.commission_base) }} ({{ record.sales }} sale(s))</span>
          </template>
          <template v-else-if="column.key === 'amount'"><strong>{{ money(record.amount) }}</strong></template>
          <template v-else-if="column.key === 'state'">
            <a-tag v-if="record.existing" color="default">Already paid ({{ record.existing }})</a-tag>
            <a-tag v-else-if="record.amount <= 0" color="default">Nothing to pay</a-tag>
            <a-tag v-else color="green">Will be created</a-tag>
          </template>
        </template>
      </a-table>
      <div style="text-align: right; margin-top: 8px">Total to create: <strong>{{ money(weeklyNew.reduce((s, r) => s + r.amount, 0)) }}</strong></div>
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
import { PlusOutlined, EditOutlined, DeleteOutlined, CalendarOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';

const { t } = useI18n();
const { money } = useFormat();

const crud = useCrudTable('payroll', { rowsKey: 'payrolls' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Employee'), dataIndex: 'employee_name', key: 'employee_name' },
  { title: 'Week', key: 'period', exportValue: r => (r.period_start ? `${r.period_start} - ${r.period_end}` : '') },
  { title: 'Type', key: 'pay_type', exportValue: r => r.pay_type || 'manual' },
  { title: 'Detail', key: 'detail', exportValue: r => r.notes || '' },
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
  sortByLabel((crud.payload.value?.employees || []).map(e => ({ value: e.id, label: `${e.firstname || ''} ${e.lastname || ''}`.trim() || e.username })))
);
const accountOptions = computed(() =>
  sortByLabel((crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name })))
);
const paymentMethodOptions = computed(() =>
  sortByLabel((crud.payload.value?.payment_methods || []).map(m => ({ value: m.id, label: m.name })))
);

// ---- Weekly payroll run ----
const weekly = ref({ open: false, date: dayjs().subtract(7, 'day').format('YYYY-MM-DD'), rows: [], loading: false, saving: false, payment_method_id: undefined, account_id: undefined, period_start: '', period_end: '' });
const weeklyNew = computed(() => weekly.value.rows.filter(r => !r.existing && r.amount > 0));
const weeklyColumns = [
  { title: t('Employee'), dataIndex: 'name', key: 'name' },
  { title: 'Paid by', key: 'pay_type' },
  { title: 'How', key: 'basis' },
  { title: t('Amount'), key: 'amount', align: 'right' },
  { title: '', key: 'state' },
];
async function loadWeekly() {
  weekly.value.loading = true;
  try {
    const data = await http.get('payroll_weekly/preview', { date: weekly.value.date });
    weekly.value.rows = data.rows || [];
    weekly.value.period_start = data.period_start;
    weekly.value.period_end = data.period_end;
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    weekly.value.loading = false;
  }
}
function openWeekly() {
  weekly.value.open = true;
  if (!weekly.value.payment_method_id) weekly.value.payment_method_id = paymentMethodOptions.value[0]?.value;
  loadWeekly();
}
async function createWeekly() {
  if (!weekly.value.payment_method_id) { message.warning(`${t('Paymentchoice')}: ${t('Field_is_required')}`); return; }
  weekly.value.saving = true;
  try {
    const res = await http.post('payroll_weekly/create', {
      date: weekly.value.date, payment_method_id: weekly.value.payment_method_id, account_id: weekly.value.account_id || null,
    });
    message.success(`${res.created} payroll(s) created: ${money(res.total)}.`);
    weekly.value.open = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    weekly.value.saving = false;
  }
}

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
