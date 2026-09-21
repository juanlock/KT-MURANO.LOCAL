<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Create_Expense')"
      :breadcrumb="[$t('Accounting'), isEdit ? $t('Edit') : $t('Create_Expense')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('date') + ' *'" name="date">
              <a-input v-model:value="form.date" type="date" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('warehouse') + ' *'" name="warehouse_id">
              <a-select
                v-model:value="form.warehouse_id" :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                show-search option-filter-prop="label"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Paymentchoice') + ' *'" name="payment_method_id">
              <a-select
                v-model:value="form.payment_method_id" :placeholder="$t('PleaseSelect')"
                :options="paymentMethods.map(p => ({ label: p.name, value: p.id }))"
                show-search option-filter-prop="label"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Account')" name="account_id">
              <a-select
                v-model:value="form.account_id" :placeholder="$t('Choose_Account')"
                :options="accounts.map(a => ({ label: a.account_name, value: a.id }))"
                show-search option-filter-prop="label" allow-clear
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Expense_Category') + ' *'" name="category_id">
              <a-select
                v-model:value="form.category_id" :placeholder="$t('Choose_Category')"
                :options="categories.map(c => ({ label: c.name, value: c.id }))"
                show-search option-filter-prop="label"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Amount') + ' *'" name="amount">
              <a-input v-model:value="form.amount" :placeholder="$t('Amount')" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Details') + ' *'" name="details">
              <a-textarea v-model:value="form.details" :rows="4" :placeholder="$t('Afewwords')" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/expenses')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Expense create/edit — bootstrap GET expenses/create → {Expenses_category,
 * warehouses, accounts, payment_methods}; edit GET expenses/{id}/edit →
 * {expense, expense_Category (lowercase e!), warehouses, accounts,
 * payment_methods}. Save POST/PUT expenses with the whole object nested
 * under {expense}. Legacy validation: date/warehouse/payment
 * method/category/details required; amount required + ^\d*\.?\d*$;
 * account optional.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();

const warehouses = ref([]);
const categories = ref([]);
const paymentMethods = ref([]);
const accounts = ref([]);

const form = ref({
  date: new Date().toISOString().slice(0, 10),
  warehouse_id: undefined,
  account_id: undefined,
  category_id: undefined,
  payment_method_id: undefined,
  details: '',
  amount: '',
});

const NUM_RE = /^\d*\.?\d*$/;
const rules = computed(() => ({
  date: [{ required: true, message: t('Field_is_required') }],
  warehouse_id: [{ required: true, message: t('Field_is_required') }],
  payment_method_id: [{ required: true, message: t('Field_is_required') }],
  category_id: [{ required: true, message: t('Field_is_required') }],
  details: [{ required: true, message: t('Field_is_required') }],
  amount: [
    { required: true, message: t('Field_is_required') },
    { validator: (_r, v) => (NUM_RE.test(String(v ?? '')) ? Promise.resolve() : Promise.reject(t('InvalidData'))) },
  ],
}));

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  submitting.value = true;
  try {
    const expense = {
      ...form.value,
      account_id: form.value.account_id || '',
    };
    if (isEdit.value) {
      await http.put(`expenses/${id.value}`, { expense });
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('expenses', { expense });
      message.success(t('Successfully_Created'));
    }
    router.push('/expenses');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    if (isEdit.value) {
      const data = await http.get(`expenses/${id.value}/edit`);
      form.value = { ...form.value, ...data.expense };
      categories.value = data.expense_Category || [];
      warehouses.value = data.warehouses || [];
      accounts.value = data.accounts || [];
      paymentMethods.value = data.payment_methods || [];
    } else {
      const data = await http.get('expenses/create');
      categories.value = data.Expenses_category || [];
      warehouses.value = data.warehouses || [];
      accounts.value = data.accounts || [];
      paymentMethods.value = data.payment_methods || [];
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loadingRecord.value = false;
  }
});
</script>
