<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Add')"
      :breadcrumb="[$t('People'), $t('Suppliers'), isEdit ? $t('Edit') : $t('Add')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <!-- New supplier from a received email (Email Office, ?email=ID): filled
         from the sender; values found in the email are one click away. -->
    <a-card v-if="!loadingRecord && fromEmail" size="small" class="sf-from">
      <div class="sf-from__title">
        <MailOutlined /> From the email “{{ fromEmail.subject }}” — {{ fromEmail.from_name || fromEmail.from_email }}
      </div>
      <div class="sf-muted">
        Name and email are taken from the sender<span v-if="senderAddresses > 1">. This sender writes from {{ senderAddresses }} different addresses, so the email is left empty — its emails are matched by the name</span>.
        When you save, its emails are linked to the new supplier.
      </div>
      <div v-if="phonesFound.length" class="sf-pick">
        <span class="sf-muted">Phones in the email:</span>
        <a-tag v-for="p in phonesFound" :key="p" class="sf-chip" :color="form.phone === p ? 'purple' : undefined" @click="form.phone = p">{{ p }}</a-tag>
      </div>
      <div v-if="sitesFound.length" class="sf-pick">
        <span class="sf-muted">Addresses in the email:</span>
        <a-tag v-for="a in sitesFound" :key="a" class="sf-chip" :color="form.email === a ? 'purple' : undefined" @click="form.email = a">{{ a }}</a-tag>
      </div>
    </a-card>

    <a-card v-if="!loadingRecord">
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
          <!-- Extra purchase-email recipients -- also filled from the Purchases
               list Send Email modal when a new address is typed. -->
          <a-col :xs="24" :md="12">
            <a-form-item label="Additional Emails" name="additional_emails">
              <a-select
                v-model:value="form.additional_emails"
                mode="tags" :open="false" :token-separators="[',', ' ', ';']"
                placeholder="Type an email and press Enter"
              />
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
          <a-col :xs="12" :md="6">
            <a-form-item label="State" name="state">
              <a-input v-model:value="form.state" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item label="ZIP" name="zip">
              <a-input v-model:value="form.zip" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Adress')" name="adresse">
              <a-textarea v-model:value="form.adresse" :rows="2" :placeholder="$t('Enter_Address')" />
            </a-form-item>
          </a-col>
          <!-- Contact person: who to talk to at this supplier (account manager…). -->
          <a-col :span="24"><div class="sf-section">Contact person</div></a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Name" name="contact_name">
              <a-input v-model:value="form.contact_name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Email" name="contact_email">
              <a-input v-model:value="form.contact_email" type="email" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Phone" name="contact_phone">
              <a-input v-model:value="form.contact_phone" />
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
          <a-button @click="fromEmail ? $router.push('/email-office/received') : $goBack('/suppliers')">{{ $t('Cancel') }}</a-button>
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
import { message, Modal } from 'ant-design-vue';
import { MailOutlined } from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { goBack } from '../../lib/goBack';

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
  name: '', email: '', additional_emails: [], phone: '', tax_number: '', country: '', city: '',
  state: '', zip: '', adresse: '', opening_balance: 0, credit_limit: 0,
  contact_name: '', contact_email: '', contact_phone: '',
});
const form = ref(emptyForm());
// The providers update endpoint ignores opening_balance — it is changed only
// via providers/{id}/adjust-opening-balance. Remember the loaded value so an
// edit can push a "set" adjustment when (and only when) it changes.
const loadedOpeningBalance = ref(0);

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  email: [{ type: 'email', message: t('InvalidData') }],
  contact_email: [{ type: 'email', message: t('InvalidData') }],
  additional_emails: [{
    validator: (_r, list) => ((list || []).every(e => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e))
      ? Promise.resolve() : Promise.reject(t('InvalidData'))),
  }],
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
      const res = await http.post('providers', body());
      message.success(t('Successfully_Created'));
      if (fromEmail.value && res?.id) {
        await linkEmails(res.id);
        return;
      }
    }
    // Back where the user came from (the filtered list, or the supplier's page).
    goBack(router, '/suppliers');
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


/* ---------------- from a received email (Email Office, ?email=ID) ---------------- */
const fromEmail = ref(null);
const senderAddresses = ref(1);
const senderName = e => String(e.from_name || '').trim().replace(/^"+|"+$/g, '').trim();
// Phone numbers in the email text (US / international), most likely first.
const phonesFound = computed(() => {
  const text = String(fromEmail.value?.body_text || '');
  const hits = text.match(/(?:\+?1[\s.-]?)?\(?\b\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}\b/g) || [];
  return [...new Set(hits.map(p => p.trim()))].slice(0, 6);
});
// Other email addresses written in the email (e.g. a billing contact), not the sender's.
const sitesFound = computed(() => {
  const e = fromEmail.value;
  if (!e) return [];
  const hits = String(e.body_text || '').toLowerCase().match(/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/g) || [];
  return [...new Set(hits)].filter(a => a !== String(e.from_email || '').toLowerCase()).slice(0, 6);
});
async function prefillFromEmail(emailId) {
  const e = (await http.get(`email-office/emails/${emailId}`)).email;
  fromEmail.value = e;
  form.value.name = senderName(e) || e.from_email;
  try {
    // How many addresses this sender name writes from (AT&T: a new one per bill).
    const s = ((await http.get('email-office/addresses', { direction: 'in', search: senderName(e) || e.from_email })).senders || [])
      .find(x => x.sender.toLowerCase() === (senderName(e) || e.from_email).toLowerCase());
    senderAddresses.value = s?.addresses || 1;
  } catch (err) { senderAddresses.value = 1; }
  form.value.email = senderAddresses.value > 1 ? '' : (e.from_email || '');
}
// After saving: link this email -- and, if the user agrees, every email under
// the same sender name -- to the new supplier, then back to Email Office.
async function linkEmails(providerId) {
  const e = fromEmail.value;
  let count = 0;
  try {
    count = (await http.post('email-office/assign-sender', { email_id: e.id, count_only: 1 })).count || 0;
  } catch (err) { /* fall back to this one email */ }
  const all = count > 1 && await new Promise(resolve => Modal.confirm({
    title: `I found ${count} emails from ${senderName(e) || e.from_email}`,
    content: 'Link all of them to the new supplier? Its next emails will match it by themselves.',
    okText: `Yes, link all ${count}`,
    cancelText: 'Only this one',
    onOk: () => resolve(true),
    onCancel: () => resolve(false),
  }));
  try {
    if (all) {
      const r = await http.post('email-office/assign-sender', { email_id: e.id, party_type: 'provider', party_id: providerId });
      message.success(`${r.updated} emails linked to the supplier.`);
    } else {
      await http.put(`email-office/emails/${e.id}`, { party_type: 'provider', party_id: providerId });
    }
  } catch (err) {
    message.warning('Supplier saved, but the email could not be linked — link it from Email Office.');
  }
  router.push('/email-office/received');
}

onMounted(async () => {
  if (isEdit.value) {
    loadRecord();
  } else if (route.query.email) {
    loadingRecord.value = true;
    try {
      await prefillFromEmail(route.query.email);
    } catch (e) { /* email gone or no access: plain form */ } finally {
      loadingRecord.value = false;
    }
  }
});
</script>

<style scoped>
.sf-section {
  font-weight: 600;
  margin: 4px 0 8px;
  padding-top: 8px;
  border-top: 1px solid #f0f0f0;
}
.sf-from {
  margin-bottom: 12px;
}
.sf-from__title {
  font-weight: 600;
  margin-bottom: 4px;
}
.sf-muted {
  color: #888;
  font-size: 12px;
}
.sf-pick {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
}
.sf-chip {
  cursor: pointer;
}
</style>
