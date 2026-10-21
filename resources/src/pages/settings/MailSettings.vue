<template>
  <div class="page">
    <!-- No PageHeader actions: the header is hidden when this page is
         embedded in System Settings — the Test button sits next to submit. -->
    <PageHeader :title="$t('mail_settings')" :breadcrumb="[$t('Settings'), $t('mail_settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form layout="vertical">
        <!-- Field labels literal like legacy's .env-style naming. -->
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item label="MAIL_MAILER *">
              <a-input v-model:value="server.mail_mailer" placeholder="smtp" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="MAIL_HOST *">
              <a-input v-model:value="server.host" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="MAIL_PORT *">
              <a-input v-model:value="server.port" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="MAIL_USERNAME *">
              <a-input v-model:value="server.username" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="MAIL_PASSWORD *">
              <a-input-password v-model:value="server.password" :placeholder="$t('LeaveBlank')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="MAIL_ENCRYPTION *">
              <a-select
                v-model:value="server.encryption"
                :options="['tls', 'ssl', 'none'].map(v => ({ value: v, label: v }))"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Sender Name *">
              <a-input v-model:value="server.sender_name" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Sender Email *">
              <a-input v-model:value="server.sender_email" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-space>
          <a-button type="primary" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
          <a-button :loading="testing" @click="openTestModal">
            <template #icon><SendOutlined /></template>
            Test
          </a-button>
          <a-popconfirm
            v-if="isTemplate"
            :title="t('mail_deploy_confirm')"
            :ok-text="t('Yes')" :cancel-text="t('No')"
            @confirm="deployAll"
          >
            <a-button :loading="deploying">
              <template #icon><CloudUploadOutlined /></template>
              {{ t('mail_deploy_all') }}
            </a-button>
          </a-popconfirm>
        </a-space>
        <div v-if="isTemplate" class="notice-help" style="margin-top: 8px">{{ t('mail_deploy_help') }}</div>
      </a-form>
    </a-card>

    <!-- Who gets this company's automatic notices (saved with Submit above). -->
    <a-card v-if="!loading" size="small" title="Admin notices" style="margin-top: 16px">
      <div class="notice-help">
        Emails sent automatically to this company's admins: online booking, booking created from the panel,
        booking confirmed, booking completed (with invoice), POS register opened and closed.
        Every admin user of the company receives them, through the mail server above.
      </div>
      <div class="notice-list">
        <a-tag v-for="r in recipients" :key="r.email" color="blue" style="margin-bottom: 4px">
          {{ r.name ? `${r.name} — ` : '' }}{{ r.email }}
        </a-tag>
        <span v-if="!recipients.length" style="color: #cf1322">No admin user with an email in this company.</span>
      </div>
      <div v-if="isSuperAdmin" class="notice-row">
        <div>
          <div style="font-weight: 600">Send a copy to the SuperAdmin</div>
          <div class="notice-help">You (SuperAdmin) also receive this company's notices, as a hidden copy. Off: only the company's admins get them.</div>
        </div>
        <a-switch v-model:checked="superadminCopy" />
      </div>
    </a-card>

    <!-- Hidden copy (BCC) of the emails sent to customers / suppliers (saved with Submit above). -->
    <a-card v-if="!loading" size="small" title="Send a copy to other emails" style="margin-top: 16px">
      <div class="notice-help">
        These addresses get a hidden copy of the emails this company sends to customers and suppliers —
        the customer never sees them. Type an email and press Enter to add it.
      </div>
      <a-select
        v-model:value="copyEmails" mode="tags" style="width: 100%; margin: 8px 0"
        :token-separators="[',', ';', ' ']" :open="false"
        placeholder="e.g. accounting@company.com, owner@company.com"
      />
      <div v-if="badCopyEmails.length" style="color: #cf1322; margin-bottom: 6px">Not a valid email: {{ badCopyEmails.join(', ') }}</div>
      <div style="font-weight: 600; margin-bottom: 4px">Copy these emails</div>
      <a-checkbox-group v-model:value="copyOn" :options="copyKindOptions" />
    </a-card>

    <a-modal
      v-model:open="testModalVisible"
      title="Send test email"
      :confirm-loading="testing"
      @ok="sendTest"
    >
      <a-form layout="vertical">
        <a-form-item label="Destination email">
          <a-input v-model:value="testEmail" type="email" placeholder="you@company.com" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET get_config_mail → {server}; PUT update_config_mail/{id} with the SMTP
 * fields; POST test_config_mail sends a test message and answers
 * {message|msg} — shown verbatim either way.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SendOutlined, CloudUploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const server = ref({});
const testModalVisible = ref(false);
const testEmail = ref('');
const defaultTestEmail = ref('');
// Admin notices: recipients (the company's admins) + SuperAdmin copy switch.
const auth = useAuthStore();
const isSuperAdmin = computed(() => auth.user?.type === 'SuperAdmin');
const recipients = ref([]);
const superadminCopy = ref(false);
// "Send a copy to other emails": addresses + which emails (CompanyMail::COPY_KINDS).
const copyEmails = ref([]);
const copyOn = ref(['sales', 'reminders', 'purchases']);
const copyKindOptions = [
  { value: 'sales', label: 'Sales (invoices and payment receipts)' },
  { value: 'reminders', label: 'Payment reminders' },
  { value: 'purchases', label: 'Purchases (orders and payments)' },
];
const badCopyEmails = computed(() => copyEmails.value.filter(e => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(e).trim())));

// AdminSystem (SuperAdmin): its mail server is the template for every company.
const isTemplate = ref(false);
const deploying = ref(false);

// Saves AdminSystem's form first, then copies it to every company (sender "Admin {company}").
async function deployAll() {
  deploying.value = true;
  try {
    if (!(await save())) return;
    const data = await http.post('deploy_config_mail');
    message.success(data?.message || t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    deploying.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    await http.put(`update_config_mail/${server.value.id}`, {
      mail_mailer: server.value.mail_mailer,
      host: server.value.host,
      port: server.value.port,
      sender_name: server.value.sender_name,
      sender_email: server.value.sender_email,
      username: server.value.username,
      password: server.value.password,
      encryption: server.value.encryption,
      ...(isSuperAdmin.value ? { notify_superadmin_copy: superadminCopy.value ? 1 : 0 } : {}),
      mail_copy_emails: copyEmails.value,
      mail_copy_on: copyOn.value,
    });
    message.success(t('Successfully_Updated'));
    return true;
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
    return false;
  } finally {
    saving.value = false;
  }
}

function openTestModal() {
  // Pre-fill with whatever destination the backend would actually use by
  // default (current user's email, falling back to the company's settings
  // email) -- keeps this in sync with test_config_mail()'s own fallback
  // instead of duplicating that logic client-side.
  testEmail.value = defaultTestEmail.value;
  testModalVisible.value = true;
}

async function sendTest() {
  testing.value = true;
  try {
    const data = await http.post('test_config_mail', { email: testEmail.value });
    message.success(data?.message || data?.msg || t('Success'));
    testModalVisible.value = false;
  } catch (e) {
    // The backend's `errors` field carries the actual SMTP failure reason
    // (e.g. "535 Incorrect authentication data") -- show it, not just the
    // generic "Failed to send test email." wrapper message, so a bad
    // password/host/port is self-diagnosable from the toast alone.
    const detail = typeof e?.data?.errors === 'string' ? e.data.errors : (e?.data?.errors && JSON.stringify(e.data.errors));
    const summary = e?.data?.message || t('SMTPIncorrect');
    message.error(detail ? `${summary} ${detail}` : summary, 10);
  } finally {
    testing.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_config_mail');
    server.value = data.server || {};
    defaultTestEmail.value = data.default_test_email || '';
    recipients.value = data.notice_recipients || [];
    superadminCopy.value = data.notify_superadmin_copy === true;
    copyEmails.value = data.mail_copy?.emails || [];
    copyOn.value = data.mail_copy?.on || ['sales', 'reminders', 'purchases'];
    isTemplate.value = data.is_template === true;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.notice-help { font-size: 12px; opacity: 0.65; }
.notice-list { margin: 10px 0; }
.notice-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding-top: 10px;
  border-top: 1px solid rgba(128, 128, 128, 0.15);
}
</style>
