<template>
  <!-- Company profile (App\Support\CompanyProfile): used inside the Add and Edit
       company forms -- the parent <a-form> owns the model and the rules
       (profileRules below), these are only its fields. -->
  <div>
    <!-- section: 'all' (default) or one of company / helpdesk / defaults (tabbed forms). -->
    <template v-if="show('company')">
    <div v-if="section === 'all'" class="cp-title">Company</div>
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item label="Company name" name="name"><a-input v-model:value="form.name" /></a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item label="Business type" name="business_type" extra="Sets the company's accounts, expense categories and demo data.">
          <a-select v-model:value="form.business_type" :options="businessTypeOptions" placeholder="Choose…" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item label="Owner name" name="owner_name"><a-input v-model:value="form.owner_name" /></a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item label="Company email" name="email" extra="On invoices and emails sent to customers.">
          <a-input v-model:value="form.email" type="email" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item label="Phone" name="phone"><a-input v-model:value="form.phone" /></a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item label="Country" name="country"><a-input v-model:value="form.country" /></a-form-item>
      </a-col>
      <a-col :span="24">
        <a-form-item label="Address" name="address" extra="Street, city, state, ZIP — printed on invoices and quotes.">
          <a-input v-model:value="form.address" />
        </a-form-item>
      </a-col>
    </a-row>
    </template>

    <template v-if="show('helpdesk')">
    <div v-if="section === 'all'" class="cp-title">Helpdesk</div>
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item label="Helpdesk email" name="helpdesk_email" extra="The support mailbox tickets are sent from, e.g. support@company.com.">
          <a-input v-model:value="form.helpdesk_email" type="email" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item label="Helpdesk sender name" name="helpdesk_name"><a-input v-model:value="form.helpdesk_name" placeholder="Helpdesk" /></a-form-item>
      </a-col>
    </a-row>
    </template>

    <template v-if="show('defaults')">
    <div v-if="section === 'all'" class="cp-title">Defaults</div>
    <a-row :gutter="16">
      <a-col :xs="24" :md="8">
        <a-form-item label="Currency" name="currency_id">
          <a-select v-model:value="form.currency_id" :options="currencyOptions" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-form-item label="Language" name="default_language">
          <a-select v-model:value="form.default_language" :options="languageOptions" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-form-item label="Sale prefix" name="sale_prefix" extra="Invoice numbers, e.g. INV → INV_0001.">
          <a-input v-model:value="form.sale_prefix" :maxlength="20" />
        </a-form-item>
      </a-col>
      <a-col :span="24">
        <a-form-item label="Public address" name="public_base_url" extra="Optional — the address customers use (booking links, codes). Empty = the server address.">
          <a-input v-model:value="form.public_base_url" placeholder="https://company.com" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item
          label="Default employee password" name="default_password_employee_new"
          :extra="form.default_password_employee_set ? 'Set — leave blank to keep it.' : 'Not set: new employees\' logins stay disabled until one is given.'"
        >
          <a-input-password v-model:value="form.default_password_employee_new" autocomplete="new-password" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="12">
        <a-form-item
          label="Default customer password" name="default_password_customer_new"
          :extra="form.default_password_customer_set ? 'Set — leave blank to keep it.' : 'Not set.'"
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
