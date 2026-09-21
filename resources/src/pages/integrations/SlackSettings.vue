<template>
  <div class="page">
    <PageHeader title="Slack" :breadcrumb="['Integrations', 'Slack']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.enabled && state.configured ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.enabled && state.configured ? $t('Enabled') : state.configured ? $t('Disabled') : 'Not configured' }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(97,31,105,0.14)', color: '#611f69' }">
          <template #icon><SlackOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">Slack notifications</div>
          <div class="muted">
            Post sales, purchases, payments and stock alerts into a Slack channel as they happen.
          </div>
        </div>
      </div>

      <a-form layout="vertical" style="max-width: 640px">
        <a-form-item label="Incoming webhook URL">
          <a-input-password
            v-model:value="form.webhook_url"
            :placeholder="state.configured ? `Saved (${state.webhook_hint}) — paste a new URL to replace it` : 'https://hooks.slack.com/services/…'"
            autocomplete="off"
          />
          <div class="muted" style="margin-top: 4px">
            Create one in Slack under
            <a href="https://api.slack.com/messaging/webhooks" target="_blank" rel="noopener">Apps → Incoming Webhooks</a>,
            pick the channel, then paste the URL here. It is stored write-only and never shown again in full.
          </div>
        </a-form-item>

        <a-form-item :label="$t('Events')">
          <a-radio-group v-model:value="eventMode">
            <a-radio-button value="all">All events</a-radio-button>
            <a-radio-button value="selected">Selected events</a-radio-button>
          </a-radio-group>
          <a-select
            v-if="eventMode === 'selected'"
            v-model:value="form.events"
            mode="multiple"
            style="width: 100%; margin-top: 8px"
            placeholder="Pick the events to post"
            :options="state.available_events.map(ev => ({ value: ev, label: ev }))"
          />
        </a-form-item>

        <a-form-item>
          <a-space align="center">
            <a-switch v-model:checked="form.enabled" />
            <span>Enable Slack notifications</span>
          </a-space>
        </a-form-item>

        <a-space wrap>
          <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
          <a-button :loading="testing" :disabled="!state.configured && !form.webhook_url" @click="sendTest">
            Send test message
          </a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Slack integration settings: write-only incoming-webhook URL, event
 * subscription (empty list = all events, mirrored server-side), enable
 * switch, and a synchronous test send.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SlackOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const state = ref({ enabled: false, configured: false, webhook_hint: null, available_events: [] });
const form = ref({ enabled: false, webhook_url: '', events: [] });
const eventMode = ref('all');

async function load() {
  isLoading.value = true;
  try {
    state.value = await http.get('slack/settings');
    form.value.enabled = !!state.value.enabled;
    form.value.events = state.value.events || [];
    form.value.webhook_url = '';
    eventMode.value = form.value.events.length ? 'selected' : 'all';
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      events: eventMode.value === 'all' ? [] : form.value.events,
    };
    // The URL is write-only: send it only when the admin pasted a new one.
    if (form.value.webhook_url) payload.webhook_url = form.value.webhook_url.trim();
    await http.put('slack/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.errors?.webhook_url?.[0] || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function sendTest() {
  testing.value = true;
  try {
    // Persist any freshly pasted URL first so the test uses it.
    if (form.value.webhook_url) await save();
    await http.post('slack/settings/test');
    message.success('Test message sent — check your Slack channel.');
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || 'Test failed');
  } finally {
    testing.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.hero {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
}
.hero-title {
  font-weight: 600;
  font-size: 16px;
}
.muted {
  opacity: 0.65;
  font-size: 13px;
}
</style>
