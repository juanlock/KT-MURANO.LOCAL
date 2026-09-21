<template>
  <div class="page">
    <PageHeader
      :title="$t('Add')"
      :breadcrumb="[$t('Subscriptions'), $t('Add')]"
    />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('date') + ' *'" name="date">
              <a-input v-model:value="form.date" type="date" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Customer') + ' *'" name="client_id">
              <a-select
                v-model:value="form.client_id" :placeholder="$t('Choose_Customer')"
                :options="clients.map(c => ({ label: c.name, value: c.id }))"
                show-search option-filter-prop="label"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('warehouse') + ' *'" name="warehouse_id">
              <a-select
                v-model:value="form.warehouse_id" :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                show-search option-filter-prop="label"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('ProductName') + ' *'" name="product_id">
              <a-select
                v-model:value="form.product_id" :placeholder="$t('PleaseSelect')"
                :options="products.map(p => ({ label: p.name, value: p.id }))"
                show-search option-filter-prop="label"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('total_cycles') + ' *'" name="total_cycles">
              <a-input-number v-model:value="form.total_cycles" :min="1" style="width: 100%">
                <template #addonAfter>
                  <a-select v-model:value="form.cycle_type" style="width: 100px">
                    <a-select-option value="monthly">Months</a-select-option>
                    <a-select-option value="weekly">Weeks</a-select-option>
                    <a-select-option value="yearly">Years</a-select-option>
                  </a-select>
                </template>
              </a-input-number>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item name="billing_cycle">
              <template #label>
                {{ $t('Billing_Cycle') }} *
                <a-tooltip title="How often the user pays (e.g., monthly, weekly, yearly)">
                  <QuestionCircleOutlined style="margin-left: 4px; color: #1677ff" />
                </a-tooltip>
              </template>
              <a-select
                v-model:value="form.billing_cycle"
                :options="[
                  { label: 'Monthly', value: 'monthly' },
                  { label: 'Weekly', value: 'weekly' },
                  { label: 'Yearly', value: 'yearly' },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Price_Per_Cycle') + ' *'" name="price_per_cycle">
              <a-input-number v-model:value="form.price_per_cycle" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Quantity') + ' *'" name="quantity">
              <a-input-number v-model:value="form.quantity" :min="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Price_Per_Unit') + ' *'" name="price_per_unit">
              <a-input-number v-model:value="form.price_per_unit" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('next_billing_date') + ' *'" name="next_billing_date">
              <a-input v-model:value="form.next_billing_date" type="date" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Status') + ' *'" name="status">
              <a-select
                v-model:value="form.status"
                :options="[
                  { label: $t('Active'), value: 'active' },
                  { label: $t('Canceled'), value: 'canceled' },
                  { label: $t('Completed'), value: 'completed' },
                ]"
              />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/subscriptions')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Subscription create — bootstrap GET subscriptions/create → {clients,
 * products, warehouses}; save POST subscriptions with the whole form nested
 * under {subscription} (remaining_cycles mirrors total_cycles, legacy
 * default). All fields required; total_cycles min 1.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { QuestionCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const router = useRouter();

const isLoading = ref(true);
const submitting = ref(false);
const formRef = ref();
const clients = ref([]);
const products = ref([]);
const warehouses = ref([]);

const form = ref({
  date: new Date().toISOString().slice(0, 10),
  user_id: null,
  client_id: null,
  product_id: null,
  warehouse_id: null,
  total_cycles: 12,
  cycle_type: 'monthly',
  billing_cycle: 'monthly',
  remaining_cycles: 12,
  price_per_cycle: 0,
  price_per_unit: 0,
  quantity: 1,
  next_billing_date: new Date().toISOString().slice(0, 10),
  status: 'active',
});

// Legacy: remaining cycles start equal to total cycles.
watch(() => form.value.total_cycles, v => { form.value.remaining_cycles = v; });

const req = () => [{ required: true, message: t('Field_is_required') }];
const rules = computed(() => ({
  date: req(),
  client_id: req(),
  warehouse_id: req(),
  product_id: req(),
  total_cycles: req(),
  billing_cycle: req(),
  price_per_cycle: req(),
  quantity: req(),
  price_per_unit: req(),
  next_billing_date: req(),
  status: req(),
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
    await http.post('subscriptions', { subscription: form.value });
    message.success(t('Successfully_Created'));
    router.push('/subscriptions');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('subscriptions/create');
    clients.value = data.clients || [];
    products.value = data.products || [];
    warehouses.value = data.warehouses || [];
  } catch (e) { /* selects stay empty */ }
  isLoading.value = false;
});
</script>
