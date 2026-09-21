<template>
  <div class="page">
    <PageHeader title="Telegram" :breadcrumb="['Integrations', 'Telegram']">
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
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(34,158,217,0.14)', color: '#229ed9' }">
          <template #icon><SendOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">Telegram notifications</div>
          <div class="muted">
            Send sales, purchases, payments and stock alerts to a Telegram chat or group via your bot.
          </div>
        </div>
      </div>

      <a-form layout="vertical" style="max-width: 640px">
        <a-form-item label="Bot token">
          <a-input-password
            v-model:value="form.bot_token"
            :placeholder="state.configured ? `Saved (${state.bot_token_hint}) — paste a new token to replace it` : '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ'"
            autocomplete="off"
          />
          <div class="muted" style="margin-top: 4px">
            Create a bot with <strong>@BotFather</strong> in Telegram and paste its token here.
            It is stored write-only and never shown again in full.
          </div>
        </a-form-item>

        <a-form-item label="Chat ID">
          <a-input v-model:value="form.chat_id" placeholder="e.g. -1001234567890 for a group, or a user id" />
          <div class="muted" style="margin-top: 4px">
            Add the bot to your group or channel, then get the chat id — for example by
            forwarding a message to <strong>@userinfobot</strong>, or from the bot's
            <code>getUpdates</code> API response.
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
            placeholder="Pick the events to send"
            :options="state.available_events.map(ev => ({ value: ev, label: ev }))"
          />
        </a-form-item>

        <a-form-item>
          <a-space align="center">
            <a-switch v-model:checked="form.enabled" />
            <span>Enable Telegram notifications</span>
          </a-space>
        </a-form-item>

        <a-space wrap>
          <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
          <a-button :loading="testing" :disabled="!state.configured && !(form.bot_token && form.chat_id)" @click="sendTest">
            Send test message
          </a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Telegram integration settings: write-only bot token, destination chat id,
 * event subscription (empty list = all events, mirrored server-side), enable
 * switch, and a synchronous test send.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SendOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const state = ref({ enabled: false, configured: false, bot_token_hint: null, chat_id: '', available_events: [] });
const form = ref({ enabled: false, bot_token: '', chat_id: '', events: [] });
const eventMode = ref('all');

async function load() {
  isLoading.value = true;
  try {
    state.value = await http.get('telegram/settings');
    form.value.enabled = !!state.value.enabled;
    form.value.chat_id = state.value.chat_id || '';
    form.value.events = state.value.events || [];
    form.value.bot_token = '';
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
      chat_id: (form.value.chat_id || '').trim() || null,
      events: eventMode.value === 'all' ? [] : form.value.events,
    };
    // The token is write-only: send it only when the admin pasted a new one.
    if (form.value.bot_token) payload.bot_token = form.value.bot_token.trim();
    await http.put('telegram/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function sendTest() {
  testing.value = true;
  try {
    // Persist freshly entered credentials first so the test uses them.
    if (form.value.bot_token || form.value.chat_id !== (state.value.chat_id || '')) await save();
    await http.post('telegram/settings/test');
    message.success('Test message sent — check your Telegram chat.');
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
