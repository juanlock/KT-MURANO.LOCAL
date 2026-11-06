<template>
  <!-- Company profile (App\Support\CompanyProfile): used inside the Add and Edit
       company forms -- the parent <a-form> owns the model and the rules
       (profileRules below), these are only its fields. -->
  <div>
    <!-- section: 'all' (default) or one of company / helpdesk / defaults (tabbed forms). -->
    <template v-if="show('company')">
    <div v-if="section === 'all'" class="cp-title">{{ $t('Company') }}</div>
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('CompanyName')" name="name"><a-input v-model:value="form.name" /></a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('tx_Business_type_56396')" name="business_type" :extra="$t('tx_Sets_the_company_s_accounts_expense_categories_and_demo_data_41976')">
          <a-select v-model:value="form.business_type" :options="businessTypeOptions" :placeholder="$t('tx_Choose_3f6c9')" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('tx_Owner_name_776ad')" name="owner_name"><a-input v-model:value="form.owner_name" /></a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('tx_Company_email_729a1')" name="email" :extra="$t('tx_On_invoices_and_emails_sent_to_customers_659ee')">
          <a-input v-model:value="form.email" type="email" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('Phone')" name="phone"><a-input v-model:value="form.phone" /></a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('Country')" name="country"><a-input v-model:value="form.country" /></a-form-item>
      </a-col>
      <a-col :span="24">
        <a-form-item :label="$t('Address')" name="address" :extra="$t('tx_Street_city_state_ZIP_printed_on_invoices_and_quotes_e10c1')">
          <a-input v-model:value="form.address" />
        </a-form-item>
      </a-col>
    </a-row>
    </template>

    <template v-if="show('helpdesk')">
    <div v-if="section === 'all'" class="cp-title">{{ $t('Helpdesk') }}</div>
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('tx_Helpdesk_email_777f9')" name="helpdesk_email" extra="The support mailbox tickets are sent from, e.g. support@company.com.">
          <a-input v-model:value="form.helpdesk_email" type="email" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item :label="$t('tx_Helpdesk_sender_name_1f913')" name="helpdesk_name"><a-input v-model:value="form.helpdesk_name" :placeholder="$t('Helpdesk')" /></a-form-item>
      </a-col>
    </a-row>
    </template>

    <template v-if="show('defaults')">
    <div v-if="section === 'all'" class="cp-title">{{ $t('Defaults') }}</div>
    <a-row :gutter="16">
      <a-col :xs="24" :md="8">
        <a-form-item :label="$t('Currencies')" name="currency_id">
          <a-select v-model:value="form.currency_id" :options="currencyOptions" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-form-item :label="$t('Language')" name="default_language">
          <a-select v-model:value="form.default_language" :options="languageOptions" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-form-item :label="$t('tx_Sale_prefix_56bbc')" name="sale_prefix" :extra="$t('tx_Invoice_numbers_e_g_INV_INV_0001_f8656')">
          <a-input v-model:value="form.sale_prefix" :maxlength="20" />
        </a-form-item>
      </a-col>
      <a-col :span="24">
        <a-form-item :label="$t('tx_Public_address_2bfbe')" name="public_base_url" extra="Optional — the address customers use (booking links, codes). Empty = the server address.">
          <a-input v-model:value="form.public_base_url" placeholder="https://company.com" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item
          :label="$t('tx_Default_employee_password_48012')" name="default_password_employee_new"
          :extra="form.default_password_employee_set ? $t('tx_Set_leave_blank_to_keep_it_16625') : 'Not set: new employees\' logins stay disabled until one is given.'"
        >
          <a-input-password v-model:value="form.default_password_employee_new" autocomplete="new-password" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item
          :label="$t('tx_Default_customer_password_691ee')" name="default_password_customer_new"
          :extra="form.default_password_customer_set ? $t('tx_Set_leave_blank_to_keep_it_16625') : $t('tx_Not_set_f4152')"
        >
          <a-input-password v-model:value="form.default_password_customer_new" autocomplete="new-password" />
        </a-form-item>
      </a-col>
    </a-row>
    </template>
  </div>
</template>

<script>
/** The profile's empty values (Add) -- Edit fills them from GET company/{id}. */
export function emptyProfile() {
  return {
    name: '', owner_name: '', business_type: undefined, email: '', phone: '', country: 'United States', address: '',
    helpdesk_email: '', helpdesk_name: 'Helpdesk',
    currency_id: undefined, default_language: 'en', sale_prefix: 'INV', public_base_url: '',
    default_password_employee_new: '', default_password_customer_new: '',
    default_password_employee_set: false, default_password_customer_set: false,
  };
}

/** Rules for the profile fields, merged into the parent form's rules. */
export function profileRules() {
  const req = msg => ({ required: true, message: msg });
  return {
    name: [req('Enter the company name')],
    business_type: [req('Choose the business type')],
    owner_name: [req('Enter the owner\'s name')],
    email: [req('Enter the company email'), { type: 'email', message: 'Not a valid email' }],
    phone: [req('Enter the phone')],
    address: [req('Enter the address')],
    helpdesk_email: [req('Enter the helpdesk email'), { type: 'email', message: 'Not a valid email' }],
    currency_id: [req('Choose the currency')],
    default_language: [req('Choose the language')],
    sale_prefix: [req('Enter the sale prefix'), { pattern: /^[A-Za-z0-9_-]+$/, message: 'Letters, numbers, - and _ only' }],
    public_base_url: [{ type: 'url', message: 'Not a valid address (https://…)' }],
    default_password_employee_new: [{ min: 6, message: 'At least 6 characters' }],
    default_password_customer_new: [{ min: 6, message: 'At least 6 characters' }],
  };
}

/** Which profile section each field is in (tabbed forms: jump to the tab with an error). */
export const PROFILE_SECTIONS = {
  company: ['name', 'business_type', 'owner_name', 'email', 'phone', 'country', 'address'],
  helpdesk: ['helpdesk_email', 'helpdesk_name'],
  defaults: ['currency_id', 'default_language', 'sale_prefix', 'public_base_url', 'default_password_employee_new', 'default_password_customer_new'],
};

/** The fields sent to the server (the *_set flags are display only). */
export function profilePayload(form) {
  const { default_password_employee_set, default_password_customer_set, ...p } = form;
  return p;
}
</script>

<script setup>
import { computed } from 'vue';
import { sortByLabel } from '../lib/filterOptions';

const props = defineProps({
  form: { type: Object, required: true },
  options: { type: Object, default: () => ({}) },
  section: { type: String, default: 'all' },
});
const show = s => props.section === 'all' || props.section === s;

const businessTypeOptions = computed(() => sortByLabel(props.options.business_types || []));
const currencyOptions = computed(() => sortByLabel((props.options.currencies || []).map(c => ({ value: c.id, label: `${c.name} (${c.symbol})` }))));
const languageOptions = computed(() => sortByLabel((props.options.languages || []).map(l => ({ value: l.locale, label: l.name }))));
</script>

<style scoped>
.cp-title { font-weight: 600; margin: 4px 0 10px; }
</style>
