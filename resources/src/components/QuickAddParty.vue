<template>
  <a-modal
    :open="open"
    :title="isClient ? $t('Quick_Add_Customer') : $t('Quick_Add_Supplier')"
    :confirm-loading="submitting"
    :ok-text="$t('submit')"
    :cancel-text="$t('Cancel')"
    width="720px"
    @ok="submit"
    @cancel="close"
  >
    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :md="12">
          <a-form-item :label="(isClient ? $t('CustomerName') : $t('SupplierName')) + ' *'" name="name">
            <a-input v-model:value="form.name" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Email')">
            <a-input v-model:value="form.email" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Phone')">
            <a-input v-model:value="form.phone" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Country')">
            <a-input v-model:value="form.country" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('City')">
            <a-input v-model:value="form.city" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Tax_Number')">
            <a-input v-model:value="form.tax_number" />
          </a-form-item>
        </a-col>
        <a-col :span="24">
          <a-form-item :label="$t('Adress')">
            <a-textarea v-model:value="form.adresse" :rows="3" />
          </a-form-item>
        </a-col>
        <a-col v-if="isClient" :span="24">
          <a-checkbox v-model:checked="form.is_royalty_eligible">{{ $t('Is_Royalty_Eligible') }}</a-checkbox>
        </a-col>
      </a-row>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Quick Add Customer / Supplier — the modal legacy create_sale and
 * create_purchase open from beside their party selector, so a sale can be
 * written up for a walk-in without leaving the page.
 *
 * Same fields and same payload as legacy: POST clients (name, email, phone,
 * tax_number, country, city, adresse, is_royalty_eligible) or POST providers
 * (the same minus the loyalty flag — suppliers have no loyalty). Only `name`
 * is required, and every other value is sent as "" rather than omitted, which
 * is what legacy does. Providers answer with {provider} or the record itself.
 *
 * Emits `created` with the new row so the parent can append it to its list and
 * select it.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../lib/http';

const { t } = useI18n();

const props = defineProps({
  open: { type: Boolean, default: false },
  type: { type: String, default: 'client' }, // 'client' | 'supplier'
});
const emit = defineEmits(['update:open', 'created']);

const isClient = computed(() => props.type === 'client');
const formRef = ref();
const submitting = ref(false);

const blank = () => ({
  name: '',
  email: '',
  phone: '',
  tax_number: '',
  country: '',
  city: '',
  adresse: '',
  is_royalty_eligible: false,
});
const form = ref(blank());

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

// Legacy resets the form each time the modal is opened, not on close.
watch(() => props.open, isOpen => {
  if (isOpen) {
    form.value = blank();
    formRef.value?.clearValidate?.();
  }
});

function close() {
  emit('update:open', false);
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  const body = {
    name: form.value.name,
    email: form.value.email || '',
    phone: form.value.phone || '',
    tax_number: form.value.tax_number || '',
    country: form.value.country || '',
    city: form.value.city || '',
    adresse: form.value.adresse || '',
  };
  if (isClient.value) body.is_royalty_eligible = form.value.is_royalty_eligible || false;

  submitting.value = true;
  try {
    const data = await http.post(isClient.value ? 'clients' : 'providers', body);
    const record = data?.provider || data;
    if (record?.id) {
      emit('created', { id: record.id, name: record.name, phone: record.phone || '' });
    }
    message.success(t('Successfully_Created'));
    close();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}
</script>
