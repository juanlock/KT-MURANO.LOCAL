<template>
  <div class="page">
    <PageHeader :title="$t('Marketing_Settings')" :breadcrumb="[$t('Marketing'), $t('Marketing_Settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="12">
          <a-card :title="$t('SMS_Provider_Configuration')" size="small">
            <a-form-item>
              <a-checkbox v-model:checked="settings.sms_enabled">{{ $t('Enable_SMS') }}</a-checkbox>
            </a-form-item>
            <a-form-item :label="$t('Provider_Name')">
              <a-input v-model:value="settings.sms_provider" />
            </a-form-item>
            <a-form-item :label="$t('API_URL')">
              <a-input v-model:value="settings.sms_api_url" placeholder="https://" />
            </a-form-item>
            <a-form-item :label="$t('API_Key')">
              <a-input v-model:value="settings.sms_api_key" />
            </a-form-item>
            <a-form-item :label="$t('Sender_ID')">
              <a-input v-model:value="settings.sms_sender_id" />
            </a-form-item>
            <a-row :gutter="12">
              <a-col :span="8">
                <a-form-item :label="$t('HTTP_Method')">
                  <a-select v-model:value="settings.sms_http_method" :options="httpMethods" />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item :label="$t('Recipient_Field_Name')">
                  <a-input v-model:value="settings.sms_to_field" />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item :label="$t('Message_Field_Name')">
                  <a-input v-model:value="settings.sms_message_field" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card :title="$t('WhatsApp_API_Configuration')" size="small">
            <a-form-item>
              <a-checkbox v-model:checked="settings.whatsapp_enabled">{{ $t('Enable_WhatsApp') }}</a-checkbox>
            </a-form-item>
            <a-form-item :label="$t('Provider_Name')">
              <a-input v-model:value="settings.whatsapp_provider" />
            </a-form-item>
            <a-form-item :label="$t('API_URL')">
              <a-input v-model:value="settings.whatsapp_api_url" placeholder="https://" />
            </a-form-item>
            <a-form-item :label="$t('API_Key')">
              <a-input v-model:value="settings.whatsapp_api_key" />
            </a-form-item>
            <a-form-item :label="$t('Phone_Number_ID')">
              <a-input v-model:value="settings.whatsapp_phone_id" />
            </a-form-item>
            <a-row :gutter="12">
              <a-col :span="8">
                <a-form-item :label="$t('HTTP_Method')">
                  <a-select v-model:value="settings.whatsapp_http_method" :options="httpMethods" />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item :label="$t('Recipient_Field_Name')">
                  <a-input v-model:value="settings.whatsapp_to_field" />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item :label="$t('Message_Field_Name')">
                  <a-input v-model:value="settings.whatsapp_message_field" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card :title="$t('Email_SMTP_Configuration')" size="small">
            <a-form-item>
              <a-checkbox v-model:checked="settings.email_enabled">{{ $t('Enable_Email') }}</a-checkbox>
            </a-form-item>
            <!-- Same note as legacy: SMTP itself comes from system Mail Settings. -->
            <p style="color: rgba(0, 0, 0, 0.45); font-size: 13px">
              SMTP is taken from the system Mail Settings (Servers).
            </p>
            <a-form-item :label="$t('From_Name')">
              <a-input v-model:value="settings.email_from_name" />
            </a-form-item>
            <a-form-item :label="$t('From_Email')">
              <a-input v-model:value="settings.email_from_address" type="email" />
            </a-form-item>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card :title="$t('Default_Sender_Information')" size="small" style="margin-bottom: 16px">
            <a-form-item :label="$t('Default_Sender_Name')">
              <a-input v-model:value="settings.default_sender_name" />
            </a-form-item>
          </a-card>
          <a-card :title="$t('Campaign_Scheduling_Settings')" size="small">
            <a-form-item>
              <a-checkbox v-model:checked="settings.scheduling_enabled">{{ $t('Enable_Scheduling') }}</a-checkbox>
            </a-form-item>
            <a-form-item :label="$t('Batch_Size')">
              <a-input-number v-model:value="settings.batch_size" :min="1" style="width: 100%" />
            </a-form-item>
          </a-card>
        </a-col>
      </a-row>

      <a-button type="primary" style="margin-top: 16px" :loading="saving" @click="save">
        {{ $t('Save_Settings') }}
      </a-button>
    </a-form>
  </div>
</template>

<script setup>
/**
 * GET/POST marketing/settings — a single settings row (MarketingSetting::
 * current()). Booleans go over the wire as 1/0, like legacy sends them.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const BOOL_KEYS = ['sms_enabled', 'whatsapp_enabled', 'email_enabled', 'scheduling_enabled'];
const httpMethods = [{ value: 'POST', label: 'POST' }, { value: 'GET', label: 'GET' }];

const loading = ref(true);
const saving = ref(false);
const settings = ref({});

async function load() {
  try {
    const data = await http.get('marketing/settings');
    settings.value = data.settings || {};
    BOOL_KEYS.forEach(k => { settings.value[k] = !!settings.value[k]; });
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  const payload = { ...settings.value };
  BOOL_KEYS.forEach(k => { payload[k] = settings.value[k] ? 1 : 0; });
  try {
    await http.post('marketing/settings', payload);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
