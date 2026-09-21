<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="[$t('People'), $t('Suppliers'), isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('SupplierName')" name="name">
              <a-input v-model:value="form.name" :placeholder="$t('Enter_Supplier_Name')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Phone')" name="phone" :validate-status="phoneDup ? 'warning' : undefined"
              :help="phoneDup ? $t('Phone_Already_Registered') : undefined">
              <a-input v-model:value="form.phone" :placeholder="$t('Enter_Phone')" @blur="checkPhone" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Email')" name="email">
              <a-input v-model:value="form.email" type="email" :placeholder="$t('Enter_Email')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Tax_Number')" name="tax_number">
              <a-input v-model:value="form.tax_number" :placeholder="$t('Enter_Tax_Number')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Country')" name="country">
              <a-input v-model:value="form.country" :placeholder="$t('Enter_Country')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('City')" name="city">
              <a-input v-model:value="form.city" :placeholder="$t('Enter_City')" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Adress')" name="adresse">
              <a-textarea v-model:value="form.adresse" :rows="2" :placeholder="$t('Enter_Address')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Opening_Balance')" name="opening_balance">
              <a-input-number v-model:value="form.opening_balance" style="width: 100%" :min="0" placeholder="0.00" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Credit_Limit')" name="credit_limit">
              <a-input-number v-model:value="form.credit_limit" style="width: 100%" :min="0" placeholder="0.00" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/suppliers')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Mirror of CustomerForm: POST providers | GET|PUT providers/{id};
 * phone probe uses type=provider.
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

const loadingRecord = ref(false);
const submitting = ref(false);
const phoneDup = ref(false);
const formRef = ref();

const emptyForm = () => ({
  name: '', email: '', phone: '', tax_number: '', country: '', city: '',
  adresse: '', opening_balance: 0, credit_limit: 0,
});
const form = ref(emptyForm());
// The providers update endpoint ignores opening_balance — it is changed only
// via providers/{id}/adjust-opening-balance. Remember the loaded value so an
// edit can push a "set" adjustment when (and only when) it changes.
const loadedOpeningBalance = ref(0);

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  email: [{ type: 'email', message: t('InvalidData') }],
}));

async function checkPhone() {
  phoneDup.value = false;
  const phone = (form.value.phone || '').trim();
  if (!phone) return;
  try {
    const data = await http.get('check_phone_duplicate', { phone, type: 'provider' });
    phoneDup.value = !!data.exists && !isEdit.value;
  } catch (e) { /* advisory only */ }
}

const body = () => ({
  ...form.value,
  opening_balance: parseFloat(form.value.opening_balance) || 0,
  credit_limit: parseFloat(form.value.credit_limit) || 0,
});

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    if (isEdit.value) {
      await http.put(`providers/${id.value}`, body());
      // opening_balance is not part of the update payload; sync it separately
      // when the admin changed the field.
      const newOpening = parseFloat(form.value.opening_balance) || 0;
      if (newOpening !== loadedOpeningBalance.value) {
        await http.post(`providers/${id.value}/adjust-opening-balance`, { mode: 'set', amount: newOpening });
        loadedOpeningBalance.value = newOpening;
      }
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('providers', body());
      message.success(t('Successfully_Created'));
    }
    router.push('/suppliers');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function loadRecord() {
  loadingRecord.value = true;
  try {
    const data = await http.get(`providers/${id.value}`);
    const p = data.provider || data;
    form.value = Object.fromEntries(Object.keys(emptyForm()).map(k => [k, p[k] ?? emptyForm()[k]]));
    loadedOpeningBalance.value = parseFloat(p.opening_balance) || 0;
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/suppliers');
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(() => {
  if (isEdit.value) loadRecord();
});
</script>
