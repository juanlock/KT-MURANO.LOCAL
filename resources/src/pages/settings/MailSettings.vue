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
          <a-button :loading="testing" @click="testMail">
            <template #icon><SendOutlined /></template>
            Test
          </a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * GET get_config_mail → {server}; PUT update_config_mail/{id} with the SMTP
 * fields; POST test_config_mail sends a test message and answers
 * {message|msg} — shown verbatim either way.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SendOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const server = ref({});

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
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function testMail() {
  testing.value = true;
  try {
    const data = await http.post('test_config_mail');
    message.success(data?.message || data?.msg || t('Success'));
  } catch (e) {
    const msg = e?.data?.message || (e?.data?.errors && JSON.stringify(e.data.errors));
    message.error(msg || t('SMTPIncorrect'));
  } finally {
    testing.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_config_mail');
    server.value = data.server || {};
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>
