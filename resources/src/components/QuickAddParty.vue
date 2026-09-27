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
        <!-- Customer: Name/Lastname -> `name` is composed automatically,
             never shown as its own field. Phone/Email, then Country/City
             (City becomes a searchable US-city picker once Country is the
             United States; a plain field otherwise). -->
        <template v-if="isClient">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Firstname') + ' *'" name="firstname">
              <a-input v-model:value="form.firstname" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('lastname') + ' *'" name="lastname">
              <a-input v-model:value="form.lastname" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Phone')">
              <a-input v-model:value="form.phone" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Email')">
              <a-input v-model:value="form.email" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Country')">
              <a-select
                v-model:value="form.country_iso2"
                show-search allow-clear
                option-filter-prop="label"
                :options="countryOptions"
                @focus="ensureCountriesLoaded"
                @change="onCountryChange"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('City')">
              <UsCityPicker v-if="isUSA" v-model="form.city_id" @select="onCitySelected" />
              <a-input v-else v-model:value="form.city" />
            </a-form-item>
          </a-col>
        </template>
        <template v-else>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('SupplierName') + ' *'" name="name">
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
        </template>
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
 * Customer: Name/Lastname are the only name inputs -- `name` is composed
 * from them right before submit, never shown/edited directly. Country is a
 * dropdown (see useCountries -- 249 rows, loaded once and shared); City
 * becomes a searchable US-city picker once Country is the United States
 * (UsCityPicker, backed by the 31k-row uscities table) and auto-fills State
 * from the picked city, otherwise City stays a plain text field like before.
 * Supplier keeps its original single Name field and plain Country/City
 * inputs unchanged -- providers has no firstname/lastname/state columns.
 *
 * Payload: POST clients (name, firstname, lastname, email, phone,
 * tax_number, country, city, state, adresse, is_royalty_eligible) or POST
 * providers (name, email, phone, tax_number, country, city, adresse) --
 * suppliers have no loyalty/firstname/lastname/state. Providers answer with
 * {provider} or the record itself.
 *
 * The backend (ClientController::store()) already provisions a linked
 * `users` row with a random password for every new customer via
 * LinkedUserService::provision() -- nothing extra needed here for that part.
 *
 * Emits `created` with the new row so the parent can append it to its list and
 * select it.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../lib/http';
import { useCountries } from '../composables/useCountries';
import { sortByLabel } from '../lib/filterOptions';
import UsCityPicker from './UsCityPicker.vue';

const { t } = useI18n();

const props = defineProps({
  open: { type: Boolean, default: false },
  type: { type: String, default: 'client' }, // 'client' | 'supplier'
});
const emit = defineEmits(['update:open', 'created']);

const isClient = computed(() => props.type === 'client');
const formRef = ref();
const submitting = ref(false);

const { countries, ensureLoaded: ensureCountriesLoaded } = useCountries();
const countryOptions = computed(() => sortByLabel(countries.value.map(c => ({ value: c.iso2, label: c.name }))));
const isUSA = computed(() => form.value.country_iso2 === 'US');

const blank = () => ({
  name: '',
  firstname: '',
  lastname: '',
  email: '',
  phone: '',
  tax_number: '',
  country: '',
  // Default to the United States for a new customer -- most add-customer
  // flows are for US-based customers, so this saves a click and puts City
  // straight into the searchable US-city picker. Supplier is unaffected
  // (its Country stays the plain `country` text field, untouched).
  country_iso2: isClient.value ? 'US' : undefined,
  city: '',
  city_id: undefined,
  state: '',
  adresse: '',
  is_royalty_eligible: false,
});
const form = ref(blank());

const rules = computed(() => (isClient.value
  ? {
    firstname: [{ required: true, message: t('Field_is_required') }],
    lastname: [{ required: true, message: t('Field_is_required') }],
  }
  : {
    name: [{ required: true, message: t('Field_is_required') }],
  }));

// Legacy resets the form each time the modal is opened, not on close.
watch(() => props.open, isOpen => {
  if (isOpen) {
    form.value = blank();
    formRef.value?.clearValidate?.();
    ensureCountriesLoaded();
  }
});

function onCountryChange() {
  // Switching country invalidates whatever city/state was picked under the
  // previous one (especially US <-> non-US, which swaps the City field's
  // whole input type).
  form.value.city = '';
  form.value.city_id = undefined;
  form.value.state = '';
}

function onCitySelected(city) {
  form.value.city = city?.city || '';
  form.value.state = city?.state_name || '';
}

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

  const name = isClient.value
    ? `${form.value.firstname} ${form.value.lastname}`.trim()
    : form.value.name;
  const country = form.value.country_iso2
    ? (countries.value.find(c => c.iso2 === form.value.country_iso2)?.name || '')
    : (form.value.country || '');

  const body = {
    name,
    email: form.value.email || '',
    phone: form.value.phone || '',
    tax_number: form.value.tax_number || '',
    country,
    city: form.value.city || '',
    adresse: form.value.adresse || '',
  };
  if (isClient.value) {
    body.firstname = form.value.firstname;
    body.lastname = form.value.lastname;
    body.state = form.value.state || '';
    body.is_royalty_eligible = form.value.is_royalty_eligible || false;
  }

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
