<template>
  <div>
    <a-alert
      :type="connectionOk === true ? 'success' : connectionOk === false ? 'error' : 'info'"
      show-icon style="margin-bottom: 16px"
      :message="connectionOk === true ? $t('Connected') : connectionOk === false ? $t('Disconnected') : $t('Unknown')"
      :description="lastSyncText || undefined"
    />

    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="max-width: 720px">
      <a-form-item :label="$t('Store_URL') || 'Store URL'" name="store_url">
        <a-input v-model:value="form.store_url" placeholder="https://shop.example.com" />
      </a-form-item>
      <a-row :gutter="16">
        <a-col :xs="24" :md="12">
          <a-form-item :label="'Consumer key'" name="consumer_key">
            <a-input v-model:value="form.consumer_key" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="'Consumer secret'" name="consumer_secret">
            <a-input-password v-model:value="form.consumer_secret" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="'WordPress username'">
            <a-input v-model:value="form.wp_username" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="'WordPress application password'">
            <a-input-password v-model:value="form.wp_app_password" />
          </a-form-item>
        </a-col>
      </a-row>
      <a-space>
        <a-button type="primary" @click="onSubmit">{{ $t('Save') }}</a-button>
        <a-button :loading="connecting" @click="testConnection">{{ $t('Connection') }}</a-button>
      </a-space>
    </a-form>

    <a-card class="guide-card" :bordered="true" style="margin-top: 24px">
      <template #title>
        <span class="guide-card-title">
          <ControlOutlined :style="{ color: token.colorInfo }" />
          Sync tuning
        </span>
      </template>
      <template #extra>
        <a-button size="small" :disabled="!tuningFields.length" @click="resetTuning">{{ $t('Reset') }}</a-button>
      </template>

      <p class="guide-intro">
        The defaults suit most stores. Change these only if sync is slow or times out on your hosting.
        Every value is capped to a safe range, and clearing a field restores its default.
      </p>

      <a-collapse v-model:activeKey="tuningOpen" ghost>
        <a-collapse-panel v-for="g in tuningGroups" :key="g.key" :header="g.title">
          <a-row :gutter="16">
            <a-col v-for="f in fieldsIn(g.key)" :key="f.key" :xs="24" :md="12">
              <a-form-item :label="f.label" :help="f.hint">
                <a-input-number
                  v-if="f.type === 'integer'"
                  v-model:value="syncOptions[f.key]"
                  :min="f.min" :max="f.max" :placeholder="String(f.default)"
                  style="width: 100%"
                />
                <a-switch v-else-if="f.type === 'boolean'" v-model:checked="syncOptions[f.key]" />
                <a-input v-else v-model:value="syncOptions[f.key]" allow-clear placeholder="—" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-collapse-panel>
      </a-collapse>

      <a-button type="primary" style="margin-top: 8px" @click="onSubmit">{{ $t('Save') }}</a-button>
    </a-card>

    <a-card class="guide-card" :bordered="true" style="margin-top: 24px">
      <template #title>
        <span class="guide-card-title">
          <BookOutlined :style="{ color: token.colorInfo }" />
          WooCommerce Sync Guide
        </span>
      </template>

      <div class="guide-section">
        <div class="guide-title">
          <KeyOutlined :style="{ color: token.colorInfo }" />
          Getting API keys
        </div>
        <ul class="guide-list">
          <li><SelectOutlined :style="{ color: token.colorPrimary }" /><span>In WooCommerce: WooCommerce → Settings → Advanced → REST API.</span></li>
          <li><PlusOutlined :style="{ color: token.colorPrimary }" /><span>Add key, choose Read/Write, then copy Consumer key and Consumer secret.</span></li>
          <li><GlobalOutlined :style="{ color: token.colorPrimary }" /><span>Store URL: your site URL with no trailing slash (e.g. <a-typography-text code>https://yoursite.com</a-typography-text>).</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <UserOutlined :style="{ color: token.colorInfo }" />
          WP Username and Application Password (optional)
        </div>
        <p class="guide-intro">These fields are used only for product images. The WooCommerce API (Store URL + Consumer key/secret) handles sync for products, stock, categories, brands, customers, and orders; the WordPress REST API handles the Media Library (search and upload images).</p>
        <ul class="guide-list">
          <li><PictureOutlined :style="{ color: token.colorPrimary }" /><span>When syncing products or stock, Stocky can attach product images: it first searches the WordPress Media Library for an existing image by filename; if not found, it uploads the image via the WordPress API.</span></li>
          <li><KeyOutlined :style="{ color: token.colorPrimary }" /><span>Use a WordPress user that can manage media (e.g. Administrator). Create an Application Password in WordPress: Users → Profile (or your user) → Application Passwords — add a new one and paste it here.</span></li>
          <li><InfoCircleOutlined :style="{ color: token.colorPrimary }" /><span>If you leave these blank, sync still works for all data (products, stock, categories, brands, customers, orders); only product image attachment (search/upload) is skipped.</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <SettingOutlined :style="{ color: token.colorInfo }" />
          How to enable
        </div>
        <ul class="guide-list">
          <li><CheckCircleOutlined :style="{ color: token.colorSuccess }" /><span>Enter Store URL, Consumer key, and Consumer secret above, then click Save.</span></li>
          <li><CloudSyncOutlined :style="{ color: token.colorSuccess }" /><span>Use Test Connection to verify credentials.</span></li>
          <li><ClockCircleOutlined :style="{ color: token.colorSuccess }" /><span>Use manual sync from any tab when you need to sync.</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <SyncOutlined :style="{ color: token.colorPrimary }" />
          Manual sync (on demand)
        </div>
        <ul class="guide-list">
          <li><SwapOutlined :style="{ color: token.colorPrimary }" /><span>Sync works in both directions: Stocky → WooCommerce and WooCommerce → Stocky.</span></li>
          <li><MenuOutlined :style="{ color: token.colorPrimary }" /><span>Manual sync is available in all WooCommerce tabs (Products, Stock, etc.); use the sync actions in each tab to run sync on demand.</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <FieldTimeOutlined :style="{ color: token.colorInfo }" />
          Scheduled sync (cron)
        </div>
        <ul class="guide-list">
          <li><ClockCircleOutlined :style="{ color: token.colorPrimary }" /><span>Stocky → WooCommerce runs on its own: new (not-yet-linked) products every night at 02:00 (<a-typography-text code>--scope=products --only-unsynced</a-typography-text>) and stock every hour (<a-typography-text code>--scope=stock</a-typography-text>).</span></li>
          <li><CloudSyncOutlined :style="{ color: token.colorPrimary }" /><span>WooCommerce → Stocky runs too: orders every 15 minutes (<a-typography-text code>--scope=orders</a-typography-text>, only orders changed since the last run), products at 03:00 (<a-typography-text code>--scope=pull-products</a-typography-text>) and customers at 03:30 (<a-typography-text code>--scope=customers</a-typography-text>).</span></li>
          <li><SettingOutlined :style="{ color: token.colorPrimary }" /><span>For this to work, your server needs one cron entry that runs the Laravel scheduler every minute (on cPanel: Cron Jobs section; the same cron also processes queued sync batches):</span></li>
        </ul>
        <pre class="guide-code" :style="{ background: token.colorFillTertiary, borderColor: token.colorBorderSecondary }">* * * * * cd /path/to/your/app &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
        <ul class="guide-list">
          <li><CodeOutlined :style="{ color: token.colorPrimary }" /><span>You can also run it on demand from the terminal: <a-typography-text code>php artisan woocommerce:sync --scope=products|stock|pull-products|orders|customers</a-typography-text> — add <a-typography-text code>--only-unsynced</a-typography-text> to push only products not yet linked to WooCommerce.</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <InfoCircleOutlined :style="{ color: token.colorInfo }" />
          Notes
        </div>
        <ul class="guide-list">
          <li><ExclamationCircleOutlined :style="{ color: token.colorWarning }" /><span>Changing Store URL or API keys resets mappings (products, categories, brands, customers); items will sync again to the (new) store.</span></li>
          <li><ExclamationCircleOutlined :style="{ color: token.colorWarning }" /><span>Keep SKUs consistent between Stocky and WooCommerce to avoid duplicate products and to relink safely.</span></li>
        </ul>
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * WooCommerce connection settings — GET/POST woocommerce/settings
 * {store_url, consumer_key, consumer_secret, wp_username, wp_app_password};
 * POST woocommerce/test-connection → {ok}. Legacy validation: store_url
 * required + must start with http(s)://; keys required.
 *
 * The same endpoint also carries `sync_options` (batch sizes, timeouts, retries,
 * lookup caps — formerly WOO_* env vars) plus `sync_options_meta`, which
 * describes every field so the tuning form below renders itself. Sending a key
 * as null/blank clears it, and the server falls back to env then to its default.
 */
import { ref, computed, onMounted } from 'vue';
import { message, theme } from 'ant-design-vue';
import {
  BookOutlined, KeyOutlined, UserOutlined, SettingOutlined, SyncOutlined,
  InfoCircleOutlined, GlobalOutlined, PlusOutlined, SelectOutlined,
  PictureOutlined, CheckCircleOutlined, CloudSyncOutlined, ClockCircleOutlined,
  SwapOutlined, MenuOutlined, ExclamationCircleOutlined, FieldTimeOutlined,
  CodeOutlined, ControlOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import http from '../../../lib/http';

dayjs.extend(relativeTime);

const { t } = useI18n();
const { token } = theme.useToken();
const emit = defineEmits(['ready', 'connection', 'updated']);

const connecting = ref(false);
const connectionOk = ref(null);
const lastSyncAt = ref(null);
const formRef = ref();
const form = ref({
  store_url: '',
  consumer_key: '',
  consumer_secret: '',
  wp_username: '',
  wp_app_password: '',
});

const syncOptions = ref({});
const tuningMeta = ref({ groups: [], fields: [] });
const tuningOpen = ref([]);

const tuningGroups = computed(() => tuningMeta.value.groups || []);
const tuningFields = computed(() => tuningMeta.value.fields || []);
function fieldsIn(group) {
  return tuningFields.value.filter(f => f.group === group);
}
function resetTuning() {
  const next = {};
  for (const f of tuningFields.value) {
    next[f.key] = f.default ?? (f.type === 'boolean' ? false : null);
  }
  syncOptions.value = next;
}

const rules = computed(() => ({
  store_url: [
    { required: true, message: t('Field_is_required') },
    { pattern: /^https?:\/\//, message: 'https://…' },
  ],
  consumer_key: [{ required: true, message: t('Field_is_required') }],
  consumer_secret: [{ required: true, message: t('Field_is_required') }],
}));

const lastSyncText = computed(() => (lastSyncAt.value ? dayjs(lastSyncAt.value).fromNow() : null));

async function loadSettings() {
  try {
    const data = await http.get('woocommerce/settings');
    if (data.settings) {
      // sync_options is edited through its own form, not the credentials one.
      const { sync_options: _stored, ...credentials } = data.settings;
      form.value = { ...form.value, ...credentials };
      lastSyncAt.value = data.settings.last_sync_at;
    }
    if (data.sync_options_meta) tuningMeta.value = data.sync_options_meta;
    if (data.sync_options) syncOptions.value = { ...data.sync_options };
  } catch (e) { /* first-run: nothing saved yet */ }
}

async function onSubmit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  try {
    await http.post('woocommerce/settings', { ...form.value, sync_options: syncOptions.value });
    message.success(t('Successfully_Updated'));
    emit('updated');
    testConnection();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function testConnection() {
  connecting.value = true;
  try {
    const data = await http.post('woocommerce/test-connection');
    connectionOk.value = !!data.ok;
    emit('connection', connectionOk.value);
    if (data.ok) message.success(t('Connection_successful'));
    else message.error(t('Connection_failed'));
  } catch (e) {
    connectionOk.value = false;
    emit('connection', false);
    message.error(t('Connection_failed'));
  } finally {
    connecting.value = false;
  }
}

onMounted(async () => {
  await loadSettings();
  // Silent probe on load (no toast) — mirror legacy's created() order but
  // avoid the noisy toast: probe directly.
  try {
    const data = await http.post('woocommerce/test-connection');
    connectionOk.value = !!data.ok;
    emit('connection', connectionOk.value);
  } catch (e) {
    connectionOk.value = false;
    emit('connection', false);
  }
  emit('ready');
});
</script>

<style scoped>
.guide-card {
  max-width: 920px;
}

.guide-card-title,
.guide-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.guide-title {
  display: flex;
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 10px;
}

.guide-intro {
  font-size: 13px;
  line-height: 1.6;
  margin: 0 0 8px;
  opacity: 0.85;
}

.guide-divider {
  margin: 14px 0;
}

.guide-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.guide-list li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 5px 0;
  line-height: 1.6;
  font-size: 13px;
}

.guide-list li .anticon {
  margin-top: 4px;
  flex-shrink: 0;
}

.guide-code {
  border: 1px solid;
  border-radius: 8px;
  padding: 10px 14px;
  margin: 8px 0;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
  overflow-x: auto;
}
</style>
