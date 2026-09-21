<template>
  <div class="page">
    <PageHeader
      :title="$t('Customer_Display_Configuration')"
      :breadcrumb="[$t('Sales'), $t('Customer_Screen')]"
    />
    <p style="color: #8c8c8c; margin: -12px 0 20px">{{ $t('Customer_Display_Configuration_Subtitle') }}</p>

    <!-- Token generation -->
    <a-card style="margin-bottom: 16px">
      <template #title>{{ $t('Generate_Access_Token') }}</template>
      <template #extra>
        <a-button type="primary" :loading="loading" @click="generate">
          {{ loading ? $t('Generating') + '…' : $t('Generate_New_Token') }}
        </a-button>
      </template>
      <p style="color: #8c8c8c">{{ $t('Create_secure_token_info') }}</p>

      <a-alert v-if="error" type="error" show-icon :message="error" style="margin-bottom: 16px" />

      <template v-if="url">
        <!-- Main URL -->
        <a-form-item :label="$t('Display_URL')" layout="vertical">
          <a-input-group compact style="display: flex">
            <a-input :value="url" readonly style="flex: 1" @focus="e => e.target.select()" />
            <a-button @click="copy(url)"><CopyOutlined /> {{ $t('Copy') }}</a-button>
          </a-input-group>
          <div style="font-size: 12px; color: #8c8c8c; margin-top: 4px">
            {{ $t('Click_input_to_select_then_use_copy_button') }}
          </div>
        </a-form-item>

        <!-- Per-screen URLs -->
        <a-form-item :label="$t('Multiple_Displays')" layout="vertical" style="margin-bottom: 0">
          <div style="font-size: 12px; color: #8c8c8c; margin-bottom: 8px">
            {{ $t('Use_one_URL_per_screen_In_POS_select_matching_screen') }}
          </div>
          <div v-for="n in 5" :key="n" style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px">
            <a-tag style="min-width: 84px; text-align: center">{{ $t('Screen') }} {{ n }}</a-tag>
            <a-input :value="screenUrl(n)" readonly size="small" style="flex: 1" @focus="e => e.target.select()" />
            <a-button size="small" @click="copy(screenUrl(n))"><CopyOutlined /></a-button>
            <a-button size="small" :title="$t('Show_QR_Code')" @click="openScreenQrModal(n)"><QrcodeOutlined /></a-button>
          </div>
        </a-form-item>

        <a-divider />

        <!-- Main QR -->
        <div style="text-align: center">
          <div style="font-weight: 600; margin-bottom: 4px">{{ $t('QR_Code') }}</div>
          <div style="font-size: 12px; color: #8c8c8c; margin-bottom: 12px">{{ $t('Scan_QR_to_open_display') }}</div>
          <img v-if="qr" :src="qr" alt="QR" style="width: 200px; height: 200px" />
          <div v-else ref="qrcanvas" style="display: inline-block"></div>
        </div>
      </template>

      <a-empty v-else :description="$t('No_Token_Generated_Yet')">
        <template #image><QrcodeOutlined style="font-size: 48px; color: #bfbfbf" /></template>
        <div style="color: #8c8c8c; font-size: 12px">{{ $t('Click_Generate_New_Token_to_create') }}</div>
      </a-empty>
    </a-card>

    <!-- Help cards -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :md="8">
        <a-card size="small" :title="$t('How_to_Use')" style="height: 100%">
          <ul class="help-list">
            <li>{{ $t('Open_URL_on_display_device') }}</li>
            <li>{{ $t('Items_appear_realtime') }}</li>
            <li>{{ $t('Display_updates_automatically') }}</li>
            <li>{{ $t('Perfect_for_showing_purchases') }}</li>
          </ul>
        </a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small" :title="$t('Security')" style="height: 100%">
          <ul class="help-list">
            <li>{{ $t('Tokens_secure_time_limited') }}</li>
            <li>{{ $t('Token_expires_24h') }}</li>
            <li>{{ $t('Generate_new_tokens_anytime') }}</li>
            <li>{{ $t('Old_tokens_invalid_immediately') }}</li>
          </ul>
        </a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small" :title="$t('Features')" style="height: 100%">
          <ul class="help-list">
            <li>{{ $t('Real_time_item_updates') }}</li>
            <li>{{ $t('Automatic_cart_sync') }}</li>
            <li>{{ $t('Theme_support_dark_light') }}</li>
            <li>{{ $t('Professional_responsive_design') }}</li>
          </ul>
        </a-card>
      </a-col>
    </a-row>

    <!-- Troubleshooting -->
    <a-card size="small" :title="$t('Troubleshooting')">
      <a-collapse ghost>
        <a-collapse-panel key="1" :header="$t('Display_not_connecting_Q')">
          <p>{{ $t('Display_not_connecting_A') }}</p>
        </a-collapse-panel>
        <a-collapse-panel key="2" :header="$t('Items_not_updating_Q')">
          <p>{{ $t('Items_not_updating_A') }}</p>
        </a-collapse-panel>
        <a-collapse-panel key="3" :header="$t('Token_expired_Q')">
          <p>{{ $t('Token_expired_A') }}</p>
        </a-collapse-panel>
      </a-collapse>
    </a-card>

    <!-- Per-screen QR modal -->
    <a-modal
      :open="qrModalScreen !== null"
      :title="$t('Screen') + ' ' + qrModalScreen + ' – ' + $t('QR_Code')"
      :footer="null"
      @cancel="closeScreenQrModal"
    >
      <div style="text-align: center">
        <div ref="qrModalContainer" style="display: inline-block; min-height: 200px"></div>
        <p style="font-size: 12px; color: #8c8c8c">{{ $t('Scan_QR_to_open_display') }}</p>
      </div>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Customer Display setup — POST /customer-display/generate → {token, url,
 * qr?}. Token+url cached 24h in localStorage `cd_token_data` (same key as
 * legacy so tokens survive the migration). Per-screen URLs append
 * ?screen=1..5. QR codes: server-provided data-URI when present, otherwise
 * qrcodejs loaded on demand from the same CDN as legacy, with the same
 * canvas text fallback.
 */
import { ref, nextTick, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { CopyOutlined, QrcodeOutlined } from '@ant-design/icons-vue';
import PageHeader from '../components/PageHeader.vue';
import http from '../lib/http';

const { t } = useI18n();

const loading = ref(false);
const token = ref('');
const url = ref('');
const qr = ref(null);
const error = ref('');
const qrModalScreen = ref(null);
const qrcanvas = ref();
const qrModalContainer = ref();

function loadExistingToken() {
  try {
    const stored = localStorage.getItem('cd_token_data');
    if (!stored) return;
    const data = JSON.parse(stored);
    if (data.expiry && Date.now() < data.expiry) {
      token.value = data.token;
      url.value = data.url;
      qr.value = data.qr || null;
      nextTick(() => {
        if (!qr.value && url.value) generateQrCode(url.value);
      });
    } else {
      localStorage.removeItem('cd_token_data');
    }
  } catch (e) { /* corrupted cache — ignore */ }
}

function saveTokenData() {
  try {
    localStorage.setItem('cd_token_data', JSON.stringify({
      token: token.value,
      url: url.value,
      qr: qr.value,
      expiry: Date.now() + 24 * 60 * 60 * 1000,
    }));
  } catch (e) { /* storage full — ignore */ }
}

async function generate() {
  loading.value = true;
  error.value = '';
  try {
    const data = await http.post('customer-display/generate');
    token.value = data.token;
    url.value = data.url;
    qr.value = data.qr || null;
    nextTick(() => {
      if (!qr.value && url.value) generateQrCode(url.value);
    });
    saveTokenData();
  } catch (e) {
    error.value = e?.data?.message || t('Failed_to_generate_token');
  } finally {
    loading.value = false;
  }
}

/* --------------------------------------------------------------- QR codes */
function withQrLib(onReady, onFail) {
  if (window.QRCode) { onReady(); return; }
  const script = document.createElement('script');
  script.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
  script.onload = onReady;
  script.onerror = onFail;
  document.head.appendChild(script);
}

function drawQrInto(container, text) {
  if (!container || !text) return;
  container.innerHTML = '';
  try {
    if (window.QRCode) {
      new window.QRCode(container, {
        text,
        width: 200,
        height: 200,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: window.QRCode.CorrectLevel.H,
      });
      return;
    }
  } catch (e) { /* fall through to canvas fallback */ }
  drawFallbackInto(container, text);
}

function drawFallbackInto(container, text) {
  if (!container) return;
  container.innerHTML = '';
  try {
    const canvas = document.createElement('canvas');
    container.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    canvas.width = 200;
    canvas.height = 200;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, 200, 200);
    ctx.strokeStyle = '#ccc';
    ctx.lineWidth = 2;
    ctx.strokeRect(0, 0, 200, 200);
    ctx.fillStyle = '#333';
    ctx.font = '14px Arial';
    ctx.textAlign = 'center';
    ctx.fillText(t('QR_Code'), 100, 90);
    ctx.fillText(t('Not_Available'), 100, 110);
    ctx.font = '11px Arial';
    ctx.fillStyle = '#666';
    const short = text.length > 35 ? text.substring(0, 35) + '...' : text;
    ctx.fillText(short, 100, 160);
  } catch (e) { /* canvas unsupported */ }
}

function generateQrCode(text) {
  withQrLib(
    () => drawQrInto(qrcanvas.value, text),
    () => drawFallbackInto(qrcanvas.value, text),
  );
}

/* ------------------------------------------------------------ copy + urls */
async function copy(text) {
  if (!text) return;
  try {
    await navigator.clipboard.writeText(text);
    message.success(t('Copied_to_clipboard'));
  } catch (e) {
    // Legacy textarea fallback for non-secure contexts.
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      if (ok) message.success(t('Copied_to_clipboard'));
      else message.error(t('Failed_to_copy_to_clipboard'));
    } catch (e2) {
      message.error(t('Failed_to_copy_to_clipboard'));
    }
  }
}

function screenUrl(n) {
  if (!url.value) return '';
  const sep = url.value.indexOf('?') >= 0 ? '&' : '?';
  return url.value + sep + 'screen=' + n;
}

function openScreenQrModal(n) {
  qrModalScreen.value = n;
  nextTick(() => {
    withQrLib(
      () => drawQrInto(qrModalContainer.value, screenUrl(n)),
      () => drawFallbackInto(qrModalContainer.value, screenUrl(n)),
    );
  });
}

function closeScreenQrModal() {
  qrModalScreen.value = null;
  if (qrModalContainer.value) qrModalContainer.value.innerHTML = '';
}

onMounted(loadExistingToken);
</script>

<style scoped>
.help-list {
  padding-left: 18px;
  margin: 0;
}
.help-list li {
  margin-bottom: 8px;
  font-size: 13px;
}
</style>
