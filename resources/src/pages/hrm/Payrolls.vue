<template>
  <div class="page">
    <PageHeader :title="$t('Payroll')" :breadcrumb="[$t('hrm'), $t('Payroll')]">
      <template #actions>
        <a-space wrap>
          <a-button type="primary" @click="openWeekly">
            <template #icon><CalendarOutlined /></template>
            {{ tr('Generate payroll', 'Generar planilla') }}
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
          <span v-if="record.period_start">{{ date(record.period_start) }} – {{ date(record.period_end) }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'pay_type'">
          <a-tag v-if="record.pay_type === 'hourly'" color="blue">{{ $t('Hours') }}</a-tag>
          <a-tag v-else-if="record.pay_type === 'commission'" color="purple">{{ $t('Commission') }}</a-tag>
          <a-tag v-else-if="record.pay_type === 'per_trip'" color="cyan">{{ $t('tx_Per_trip_3f532') }}</a-tag>
          <a-tag v-else-if="record.pay_type === 'monthly'" color="green">{{ $t('tx_Salary_b083a') }}</a-tag>
          <span v-else class="muted">Manual</span>
        </template>
        <template v-else-if="column.key === 'detail'">
          <span v-if="record.pay_type === 'hourly'">{{ Number(record.hours) }} h × {{ money(record.rate) }}</span>
          <span v-else-if="record.pay_type === 'per_trip' || record.pay_type === 'monthly'">{{ record.notes }}</span>
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

    <!-- Payroll run: weekly or monthly (the company's period, settings.payroll_period; switchable here), review, create
         (paid -> posted to accounting). Monthly, 2026-11-28: a monthly salary = the whole month, 30-day basis. -->
    <a-modal v-model:open="weekly.open" :title="weekly.period === 'monthly' ? tr('Generate monthly payroll', 'Generar planilla mensual') : $t('tx_Generate_weekly_payroll_d166d')"
      width="900px" :confirm-loading="weekly.saving"
      :ok-text="$t('tx_Create_payrolls_ef2d5')" :ok-button-props="{ disabled: !weeklyNew.length }" @ok="createWeekly">
      <a-row :gutter="12" align="bottom">
        <a-col :xs="24" :md="4">
          <a-form-item :label="tr('Period', 'Periodo')" style="margin-bottom: 8px">
            <a-radio-group v-model:value="weekly.period" button-style="solid" @change="loadWeekly">
              <a-radio-button value="weekly">{{ tr('Week', 'Semana') }}</a-radio-button>
              <a-radio-button value="monthly">{{ tr('Month', 'Mes') }}</a-radio-button>
            </a-radio-group>
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="6">
          <a-form-item v-if="weekly.period === 'monthly'" :label="tr('Month', 'Mes')" style="margin-bottom: 8px">
            <a-date-picker v-model:value="weekly.date" picker="month" value-format="YYYY-MM-DD" style="width: 100%" @change="loadWeekly" />
          </a-form-item>
          <a-form-item v-else :label="$t('tx_Week_Monday_Sunday_860d9')" style="margin-bottom: 8px">
            <a-date-picker v-model:value="weekly.date" picker="week" value-format="YYYY-MM-DD" style="width: 100%" @change="loadWeekly" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="7">
          <a-form-item :label="$t('Paymentchoice') + ' *'" style="margin-bottom: 8px">
            <a-select v-model:value="weekly.payment_method_id" :options="paymentMethodOptions" :placeholder="$t('PleaseSelect')" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="7">
          <a-form-item :label="$t('Account')" style="margin-bottom: 8px">
            <a-select v-model:value="weekly.account_id" allow-clear :options="accountOptions" :placeholder="$t('tx_Cash_069b3')" />
          </a-form-item>
        </a-col>
      </a-row>
      <div class="muted" style="margin-bottom: 8px">
        {{ date(weekly.period_start) }} – {{ date(weekly.period_end) }}.
        <template v-if="weekly.period === 'monthly'">{{ tr('Monthly salary = the whole month; whoever joined or left during the month is paid by days (salary ÷ 30 × days). Trips, hours and commission of the month are added. Created as paid and posted to accounting (Salaries and wages / Commissions).',
          'Sueldo mensual = el mes completo; quien entró o salió en el mes cobra por días (sueldo ÷ 30 × días). Se suman los viajes, horas y comisiones del mes. Se crea como pagado y pasa a contabilidad (Sueldos y salarios / Comisiones).') }}</template>
        <template v-else>{{ tr('Hours = hours on the service jobs each person completed that week; commission = their sales that week; a monthly salary pays a week\'s share. Created as paid and posted to accounting (Salaries and wages / Commissions).',
          'Horas = horas de los trabajos que cada persona completó esa semana; comisión = sus ventas de la semana; un sueldo mensual paga la parte de una semana. Se crea como pagado y pasa a contabilidad (Sueldos y salarios / Comisiones).') }}</template>
      </div>
      <a-table :data-source="weekly.rows" :columns="weeklyColumns" :pagination="false" size="small" row-key="employee_id" :loading="weekly.loading"
        :locale="{ emptyText: tr('Nobody has a way of pay (Employee -> Company tab -> Paid by).', 'Nadie tiene forma de pago (Empleado -> pestaña Compañía -> Pagado por).') }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'pay_type'">{{ record.pay_type === 'hourly' ? $t('Hours') : record.pay_type === 'per_trip' ? $t('tx_Per_trip_3f532') : record.pay_type === 'monthly' ? $t('tx_Salary_b083a') : $t('Commission') }}</template>
          <template v-else-if="column.key === 'basis'">
            <span v-if="record.pay_type === 'hourly'">{{ record.hours }} h · {{ record.jobs }} {{ tr('job(s)', 'trabajo(s)') }} × {{ money(record.rate) }}</span>
            <span v-else-if="record.pay_type === 'per_trip'">{{ record.trips }} {{ $t('tx_trip_s_completed_90c3b') }}</span>
            <span v-else-if="record.pay_type === 'monthly' && weekly.period === 'monthly'">{{ money(record.rate) }} · {{ record.days }} {{ tr('of 30 days', 'de 30 días') }}<template v-if="record.trips"> + {{ record.trips }} {{ $t('tx_trip_s_5a27e') }}</template></span>
            <span v-else-if="record.pay_type === 'monthly'">{{ money(record.rate) }} / {{ record.trips }} {{ $t('tx_trip_s_5a27e') }}</span>
            <span v-else>{{ record.commission_rate }}% of {{ money(record.commission_base) }} ({{ record.sales }} {{ $t('tx_sale_s_c1dd1') }}</span>
          </template>
          <template v-else-if="column.key === 'amount'"><strong>{{ money(record.amount) }}</strong></template>
          <template v-else-if="column.key === 'absences'">
            <a-tooltip v-if="record.absences" :title="tr('Unjustified absences in the daily attendance (not deducted)', 'Faltas sin permiso en la Asistencia del día (no se descuentan)')">
              <a-tag color="red">{{ record.absences }}</a-tag>
            </a-tooltip>
            <span v-else class="muted">0</span>
          </template>
          <template v-else-if="column.key === 'state'">
            <a-tag v-if="record.existing" color="default">{{ $t('tx_Already_paid_1533c') }}{{ record.existing }})</a-tag>
            <a-tag v-else-if="record.amount <= 0" color="default">{{ $t('tx_Nothing_to_pay_329db') }}</a-tag>
            <a-tag v-else color="green">{{ $t('tx_Will_be_created_ee5be') }}</a-tag>
          </template>
        </template>
      </a-table>
      <div style="text-align: right; margin-top: 8px">{{ $t('tx_Total_to_create_28967') }} <strong>{{ money(weeklyNew.reduce((s, r) => s + r.amount, 0)) }}</strong></div>
    </a-modal>
  </div>
</template>

<script setup>
import { t as tEs } from '../../i18n/index.js';
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
import { useSystemContext } from '../../lib/systemContext';
import { useTr } from '../../lib/useTr';

const { t } = useI18n();
const { money, date } = useFormat();
const tr = useTr();
const { isSystem } = useSystemContext();

const crud = useCrudTable('payroll', { rowsKey: 'payrolls' });
crud.fetchRows();

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Employee'), dataIndex: 'employee_name', key: 'employee_name' },
  // superadmin/AdminSystem: every company (2026-10-21)
  ...(isSystem.value ? [{ title: t('Company'), key: 'company_name', dataIndex: 'company_name', exportValue: r => r.company_name || '' }] : []),
  { title: tr('Period', 'Periodo'), key: 'period', exportValue: r => (r.period_start ? `${r.period_start} - ${r.period_end}` : '') },
  { title: tEs('type', 'Type'), key: 'pay_type', exportValue: r => r.pay_type || 'manual' },
  { title: tEs('tx_Detail_a254c', 'Detail'), key: 'detail', exportValue: r => r.notes || '' },
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
// period: '' = the company's (settings.payroll_period), answered by the first preview.
const weekly = ref({ open: false, period: '', date: dayjs().subtract(7, 'day').format('YYYY-MM-DD'), rows: [], loading: false, saving: false, payment_method_id: undefined, account_id: undefined, period_start: '', period_end: '' });
const weeklyNew = computed(() => weekly.value.rows.filter(r => !r.existing && r.amount > 0));
const weeklyColumns = computed(() => [
  { title: t('Employee'), dataIndex: 'name', key: 'name' },
  { title: tEs('ModePaiement', 'Paid by'), key: 'pay_type' },
  { title: tEs('tx_How_13257', 'How'), key: 'basis' },
  { title: tr('Absences', 'Faltas'), key: 'absences', align: 'center', width: 70 },
  { title: t('Amount'), key: 'amount', align: 'right' },
  { title: '', key: 'state' },
]);
async function loadWeekly() {
  weekly.value.loading = true;
  try {
    const data = await http.get('payroll_weekly/preview', { date: weekly.value.date, period: weekly.value.period || undefined });
    weekly.value.period = data.period || 'weekly';
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
      date: weekly.value.date, period: weekly.value.period, payment_method_id: weekly.value.payment_method_id, account_id: weekly.value.account_id || null,
    });
    message.success(tr(`${res.created} payroll(s) created: ${money(res.total)}.`, `${res.created} pago(s) creado(s): ${money(res.total)}.`));
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
