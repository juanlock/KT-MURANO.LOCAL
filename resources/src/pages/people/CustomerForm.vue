<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="[$t('People'), $t('Customers'), isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Firstname')" name="firstname">
              <a-input v-model:value="form.firstname" :placeholder="$t('Enter_Firstname')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('lastname')" name="lastname">
              <a-input v-model:value="form.lastname" :placeholder="$t('Enter_Lastname')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('CustomerName')" name="name">
              <a-input v-model:value="form.name" :placeholder="$t('Enter_Customer_Name')" />
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
              <a-select
                v-model:value="countryIso2"
                show-search allow-clear
                option-filter-prop="label"
                :options="countryOptions"
                :placeholder="$t('Country')"
                @focus="ensureCountriesLoaded"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('City')" name="city">
              <UsCityPicker
                v-if="isUSA"
                v-model="cityPickerValue"
                :initial-option="cityInitialOption"
                @select="onCitySelected"
              />
              <a-input v-else v-model:value="form.city" :placeholder="$t('Enter_City')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('State')" name="state">
              <a-input v-model:value="form.state" :placeholder="$t('Enter_State')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('ZipCode')" name="zip">
              <a-input v-model:value="form.zip" :placeholder="$t('Enter_Zip_Code')" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Adress')" name="adresse">
              <a-textarea v-model:value="form.adresse" :rows="2" :placeholder="$t('Enter_Address')" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Opening_Balance')" name="opening_balance">
              <a-input-number v-model:value="form.opening_balance" style="width: 100%" :min="0" placeholder="0.00" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Credit_Limit')" name="credit_limit">
              <a-input-number v-model:value="form.credit_limit" style="width: 100%" :min="0" placeholder="0.00" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Loyalty_Eligible')">
              <a-switch v-model:checked="form.is_royalty_eligible" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/customers')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * WAVE C REFERENCE FORM — full-page create/edit (route decides via :id).
 * POST clients | GET clients/{id} + PUT clients/{id}. Phone duplicates are a
 * WARNING (non-blocking), same as legacy's check_phone_duplicate probe.
 * Custom fields stay in legacy for now.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import UsCityPicker from '../../components/UsCityPicker.vue';
import { useCountries } from '../../composables/useCountries';
import { sortByLabel } from '../../lib/filterOptions';
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
  firstname: '', lastname: '', name: '', email: '', phone: '', tax_number: '',
  // New customers default to the United States (most add-customer flows are
  // for US-based customers) -- City then starts straight in the searchable
  // US-city picker below.
  country: isEdit.value ? '' : 'United States', city: '', state: '', zip: '', adresse: '',
  opening_balance: 0, credit_limit: 0, is_royalty_eligible: false,
});
const form = ref(emptyForm());

// Country/City: `form.country`/`form.city`/`form.state` (plain text) stay the
// actual submitted fields -- countryIso2/cityPickerValue are just a UI bridge
// so the pickers have something to bind to. Country is only ~250 rows
// (loaded once, shared -- see useCountries); City is a remote search over
// 31k+ US cities (UsCityPicker), never loaded as a full dropdown, and only
// swapped in once Country is the United States.
const { countries, ensureLoaded: ensureCountriesLoaded } = useCountries();
const countryOptions = computed(() => sortByLabel(countries.value.map(c => ({ value: c.iso2, label: c.name }))));
const countryIso2 = computed({
  get: () => countries.value.find(c => c.name === form.value.country)?.iso2,
  set(iso2) {
    form.value.country = countries.value.find(c => c.iso2 === iso2)?.name || '';
    form.value.city = '';
    form.value.state = '';
    cityPickerValue.value = undefined;
  },
});
const isUSA = computed(() => form.value.country === 'United States');
// UsCityPicker has no persisted id to select by -- seed its display from the
// currently-stored city/state text (edit) instead of a real uscities.id.
const cityPickerValue = ref(undefined);
const cityInitialOption = computed(() => (form.value.city
  ? { id: -1, city: form.value.city, state_id: '', state_name: form.value.state }
  : null));
// The picker's displayed selection is driven by modelValue matching one of
// its options' `value` -- seed it to the initial option's sentinel id so an
// edited record's saved city actually shows, not just the placeholder.
watch(cityInitialOption, opt => {
  if (opt && cityPickerValue.value === undefined) cityPickerValue.value = opt.id;
}, { immediate: true });
function onCitySelected(city) {
  form.value.city = city?.city || '';
  form.value.state = city?.state_name || '';
}
// The clients update endpoint deliberately ignores opening_balance — it is
// changed only via customers/{id}/adjust-opening-balance. Remember the loaded
// value so an edit can push a "set" adjustment when (and only when) it changes.
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
    const data = await http.get('check_phone_duplicate', { phone, type: 'client' });
    // On edit, the customer's own number is not a duplicate.
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
      await http.put(`clients/${id.value}`, body());
      // opening_balance is not part of the update payload; sync it separately
      // when the admin changed the field.
      const newOpening = parseFloat(form.value.opening_balance) || 0;
      if (newOpening !== loadedOpeningBalance.value) {
        await http.post(`customers/${id.value}/adjust-opening-balance`, { mode: 'set', amount: newOpening });
        loadedOpeningBalance.value = newOpening;
      }
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('clients', body());
      message.success(t('Successfully_Created'));
    }
    router.push('/customers');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function loadRecord() {
  loadingRecord.value = true;
  try {
    const data = await http.get(`clients/${id.value}`);
    const c = data.client || data; // tolerate either envelope
    form.value = {
      ...emptyForm(),
      ...Object.fromEntries(Object.keys(emptyForm()).map(k => [k, c[k] ?? emptyForm()[k]])),
      is_royalty_eligible: !!c.is_royalty_eligible,
    };
    loadedOpeningBalance.value = parseFloat(c.opening_balance) || 0;
  } catch (e) {
    message.error(t('InvalidData'));
    router.push('/customers');
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(() => {
  ensureCountriesLoaded();
  if (isEdit.value) loadRecord();
});
</script>
