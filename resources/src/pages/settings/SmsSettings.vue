<template>
  <div class="page">
    <PageHeader :title="$t('sms_settings')" :breadcrumb="[$t('Settings'), $t('sms_settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Active gateway banner ------------------------------------------->
      <a-card :bordered="false" class="active-gateway">
        <div class="active-gateway__body">
          <div class="active-gateway__info">
            <a-avatar :size="44" :style="{ background: activeMeta.color, flex: '0 0 auto' }">
              <template #icon><component :is="activeMeta.icon" /></template>
            </a-avatar>
            <div>
              <div class="active-gateway__label">{{ $t('Default_SMS_Gateway') }}</div>
              <div class="active-gateway__name">
                {{ activeGatewayTitle || $t('Choose_SMS_Gateway') }}
              </div>
            </div>
          </div>
          <div class="active-gateway__controls">
            <a-select
              v-model:value="defaultGateway"
              class="active-gateway__select"
              show-search
              option-filter-prop="label"
              :placeholder="$t('Choose_SMS_Gateway')"
              :options="gateways.map(g => ({ value: g.id, label: g.title }))"
            />
            <a-button type="primary" :loading="saving === 'default'" @click="saveDefault">
              <template #icon><CheckOutlined /></template>
              {{ $t('submit') }}
            </a-button>
          </div>
        </div>
      </a-card>

      <!-- Master / detail --------------------------------------------------->
      <a-row :gutter="16" class="sms-grid">
        <!-- Provider rail -->
        <a-col :xs="24" :lg="8" :xl="7">
          <div class="provider-rail">
            <div class="provider-rail__heading">{{ $t('sms_settings') }}</div>
            <button
              v-for="p in providers"
              :key="p.key"
              type="button"
              class="provider-item"
              :class="{ 'provider-item--active': selected === p.key }"
              @click="selected = p.key"
            >
              <a-avatar :size="40" :style="{ background: p.color, flex: '0 0 auto' }">
                <template #icon><component :is="p.icon" /></template>
              </a-avatar>
              <div class="provider-item__text">
                <div class="provider-item__name">
                  {{ p.name }}
                  <StarFilled v-if="isDefault(p.key)" class="provider-item__star" />
                </div>
                <div class="provider-item__desc">{{ p.desc }}</div>
              </div>
              <a-badge
                :status="isConfigured(p.key) ? 'success' : 'default'"
                :title="isConfigured(p.key) ? $t('Active') : $t('Inactive')"
              />
            </button>
          </div>
        </a-col>

        <!-- Detail panel -->
        <a-col :xs="24" :lg="16" :xl="17">
          <a-card :bordered="false" class="detail-card">
            <div class="detail-head">
              <div class="detail-head__title">
                <a-avatar :size="38" :style="{ background: activeProvider.color, flex: '0 0 auto' }">
                  <template #icon><component :is="activeProvider.icon" /></template>
                </a-avatar>
                <div>
                  <div class="detail-head__name">{{ activeProvider.name }}</div>
                  <div class="detail-head__desc">{{ activeProvider.desc }}</div>
                </div>
              </div>
              <div class="detail-head__actions">
                <a-tag v-if="isConfigured(selected)" color="success">
                  <template #icon><CheckCircleFilled /></template>
                  {{ $t('Active') }}
                </a-tag>
                <a-tag v-if="isDefault(selected)" color="blue">
                  <template #icon><StarFilled /></template>
                  {{ $t('Default_SMS_Gateway') }}
                </a-tag>
                <a-button
                  v-else-if="gatewayIdFor(selected)"
                  size="small"
                  @click="makeDefault(selected)"
                >
                  <template #icon><StarOutlined /></template>
                  {{ $t('Default_SMS_Gateway') }}
                </a-button>
              </div>
            </div>

            <a-divider style="margin: 16px 0 20px" />

            <!-- Twilio / Termii / Infobip share a simple field grid -->
            <a-form v-if="selected !== 'custom'" layout="vertical">
              <a-row :gutter="16">
                <a-col v-for="f in activeFields" :key="f.model" :xs="24" :md="12">
                  <a-form-item :label="f.label">
                    <a-input-password
                      v-if="f.secret"
                      v-model:value="activeModel[f.model]"
                      :placeholder="$t('LeaveBlank')"
                      autocomplete="new-password"
                    />
                    <a-input v-else v-model:value="activeModel[f.model]" :placeholder="f.placeholder" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-button type="primary" :loading="saving === selected" @click="saveProvider(selected)">
                <template #icon><SaveOutlined /></template>
                {{ $t('submit') }}
              </a-button>
            </a-form>

            <!-- Custom gateway -->
            <a-form v-else layout="vertical">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Custom_SMS_Api_Url')">
                    <a-input v-model:value="custom.api_url" placeholder="https://" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Custom_SMS_Method')">
                    <a-select v-model:value="custom.method" :options="['POST', 'GET'].map(v => ({ value: v, label: v }))" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Custom_SMS_Content_Type')">
                    <a-input v-model:value="custom.content_type" placeholder="application/json" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Custom_SMS_Sender')"><a-input v-model:value="custom.sender" /></a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Custom_SMS_Success_Keyword')">
                    <a-input v-model:value="custom.success_keyword" />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-form-item :label="$t('Custom_SMS_Headers')">
                <div class="kv-editor">
                  <div v-for="(row, i) in headerRows" :key="'h' + i" class="kv-row">
                    <a-input v-model:value="row.key" placeholder="Key" />
                    <a-input v-model:value="row.value" placeholder="Value" />
                    <a-button type="text" danger @click="headerRows.splice(i, 1)">
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>
                  <a-button type="dashed" block @click="headerRows.push({ key: '', value: '' })">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Custom_SMS_Add_Header') }}
                  </a-button>
                </div>
              </a-form-item>

              <a-form-item :label="$t('Custom_SMS_Payload')" :extra="$t('Custom_SMS_Payload_Hint')">
                <div class="kv-editor">
                  <div v-for="(row, i) in payloadRows" :key="'p' + i" class="kv-row">
                    <a-input v-model:value="row.key" placeholder="Key" />
                    <a-input v-model:value="row.value" placeholder="Value" />
                    <a-button type="text" danger @click="payloadRows.splice(i, 1)">
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>
                  <a-button type="dashed" block @click="payloadRows.push({ key: '', value: '' })">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Custom_SMS_Add_Field') }}
                  </a-button>
                </div>
              </a-form-item>

              <a-button type="primary" :loading="saving === 'custom'" @click="saveProvider('custom')">
                <template #icon><SaveOutlined /></template>
                {{ $t('submit') }}
              </a-button>
            </a-form>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * GET get_sms_config → {twilio, termi, infobip, custom{headers, payload},
 * sms_gateway (list of {id, title}), default_sms_gateway}. Saves:
 * PUT update_Default_SMS {default_sms_gateway}; POST update_twilio_config /
 * update_termi_config / update_infobip_config / update_custom_config —
 * custom's headers/payload are objects rebuilt from key/value rows.
 *
 * UI is a master/detail: a provider rail on the left selects which gateway to
 * configure on the right. The "default gateway" banner drives the same
 * update_Default_SMS call as before; a provider is flagged Default when its
 * title matches an entry in sms_gateway whose id equals default_sms_gateway.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  DeleteOutlined, PlusOutlined, SaveOutlined, CheckOutlined, CheckCircleFilled,
  StarFilled, StarOutlined, MessageOutlined, ThunderboltOutlined, GlobalOutlined, ApiOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(null);
const gateways = ref([]);
const defaultGateway = ref(undefined);
const twilio = ref({});
const termi = ref({});
const infobip = ref({});
const custom = ref({});
const headerRows = ref([]);
const payloadRows = ref([]);
const selected = ref('twilio');

// Provider metadata. `match` is used to link a card to its sms_gateway entry by
// title; `configuredBy` is a non-secret field that proves it has been saved
// (secret fields come back blank from the API, so they can't signal config).
const providers = [
  { key: 'twilio', name: 'Twilio', desc: 'Global voice & SMS API', color: '#F22F46', icon: MessageOutlined, match: 'twilio', configuredBy: () => twilio.value.TWILIO_SID },
  { key: 'termi', name: 'Termii', desc: 'Messaging for Africa', color: '#00B383', icon: ThunderboltOutlined, match: 'termi', configuredBy: () => termi.value.TERMI_SENDER },
  { key: 'infobip', name: 'Infobip', desc: 'Omnichannel messaging cloud', color: '#EB5A1E', icon: GlobalOutlined, match: 'infobip', configuredBy: () => infobip.value.sender_from || infobip.value.base_url },
  { key: 'custom', name: t('Custom_SMS_Gateway'), desc: t('Custom_SMS_Api_Url'), color: '#6E56CF', icon: ApiOutlined, match: 'custom', configuredBy: () => custom.value.api_url },
];

const fields = {
  twilio: [
    { model: 'TWILIO_SID', label: 'Account SID (TWILIO_SID)' },
    { model: 'TWILIO_TOKEN', label: 'Auth Token (TWILIO_TOKEN)', secret: true },
    { model: 'TWILIO_FROM', label: 'From Number (TWILIO_FROM)' },
  ],
  termi: [
    { model: 'TERMI_KEY', label: 'API Key (TERMI_KEY)', secret: true },
    { model: 'TERMI_SECRET', label: 'API Secret (TERMI_SECRET)', secret: true },
    { model: 'TERMI_SENDER', label: 'Sender ID (TERMI_SENDER)' },
  ],
  infobip: [
    { model: 'api_key', label: 'API Key', secret: true },
    { model: 'sender_from', label: 'Sender / From' },
    { model: 'base_url', label: 'Base URL', placeholder: 'https://' },
  ],
};

const models = { twilio, termi, infobip };
const activeProvider = computed(() => providers.find(p => p.key === selected.value) || providers[0]);
const activeModel = computed(() => models[selected.value]?.value || {});
const activeFields = computed(() => fields[selected.value] || []);

const isConfigured = key => !!providers.find(p => p.key === key)?.configuredBy();

const gatewayIdFor = key => {
  const p = providers.find(x => x.key === key);
  const g = gateways.value.find(gw => (gw.title || '').toLowerCase().includes(p.match));
  return g ? g.id : undefined;
};
const isDefault = key => {
  const id = gatewayIdFor(key);
  return id !== undefined && id === defaultGateway.value;
};

const activeGatewayTitle = computed(() =>
  gateways.value.find(g => g.id === defaultGateway.value)?.title || ''
);
// Meta shown in the banner: the provider matching the active default gateway.
const activeMeta = computed(() => {
  const match = providers.find(p => isDefault(p.key));
  return match || { color: '#8c8c8c', icon: MessageOutlined };
});

const toRows = obj => Object.entries(obj || {}).map(([key, value]) => ({ key, value: String(value ?? '') }));
const toObject = rows => Object.fromEntries(rows.filter(r => r.key).map(r => [r.key, r.value]));

async function makeDefault(key) {
  const id = gatewayIdFor(key);
  if (id === undefined) return;
  defaultGateway.value = id;
  await saveDefault();
}

async function saveDefault() {
  saving.value = 'default';
  try {
    await http.put('update_Default_SMS', { default_sms_gateway: defaultGateway.value });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = null;
  }
}

async function saveProvider(which) {
  saving.value = which;
  const bodies = {
    twilio: ['update_twilio_config', {
      TWILIO_SID: twilio.value.TWILIO_SID,
      TWILIO_TOKEN: twilio.value.TWILIO_TOKEN,
      TWILIO_FROM: twilio.value.TWILIO_FROM,
    }],
    termi: ['update_termi_config', {
      TERMI_KEY: termi.value.TERMI_KEY,
      TERMI_SECRET: termi.value.TERMI_SECRET,
      TERMI_SENDER: termi.value.TERMI_SENDER,
    }],
    infobip: ['update_infobip_config', {
      api_key: infobip.value.api_key,
      sender_from: infobip.value.sender_from,
      base_url: infobip.value.base_url,
    }],
    custom: ['update_custom_config', {
      api_url: custom.value.api_url,
      method: custom.value.method,
      content_type: custom.value.content_type,
      sender: custom.value.sender,
      success_keyword: custom.value.success_keyword,
      headers: toObject(headerRows.value),
      payload: toObject(payloadRows.value),
    }],
  };
  const [url, body] = bodies[which];
  try {
    await http.post(url, body);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = null;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_sms_config');
    twilio.value = data.twilio || {};
    termi.value = data.termi || {};
    infobip.value = data.infobip || {};
    gateways.value = data.sms_gateway || [];
    defaultGateway.value = data.default_sms_gateway || undefined;
    const c = data.custom || {};
    custom.value = c;
    headerRows.value = toRows(c.headers);
    payloadRows.value = toRows(c.payload);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
/* Active gateway banner ------------------------------------------------- */
.active-gateway {
  margin-bottom: 16px;
  background: linear-gradient(120deg, rgba(24, 144, 255, 0.08), rgba(110, 86, 207, 0.06));
  border: 1px solid rgba(24, 144, 255, 0.18);
}
.active-gateway__body {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.active-gateway__info {
  display: flex;
  align-items: center;
  gap: 14px;
}
.active-gateway__label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgba(0, 0, 0, 0.45);
}
.active-gateway__name {
  font-size: 18px;
  font-weight: 600;
  line-height: 1.3;
}
.active-gateway__controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
.active-gateway__select {
  min-width: 240px;
}

/* Provider rail --------------------------------------------------------- */
.sms-grid { margin-top: 4px; }
.provider-rail {
  display: flex;
  flex-direction: column;
  gap: 8px;
  position: sticky;
  top: 12px;
}
.provider-rail__heading {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgba(0, 0, 0, 0.45);
  padding: 4px 6px;
}
.provider-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 12px 14px;
  border: 1px solid #f0f0f0;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.18s, box-shadow 0.18s, transform 0.05s;
}
.provider-item:hover {
  border-color: #91caff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}
.provider-item:active { transform: translateY(1px); }
.provider-item--active {
  border-color: #1677ff;
  box-shadow: 0 0 0 2px rgba(22, 119, 255, 0.12);
}
.provider-item__text { flex: 1 1 auto; min-width: 0; }
.provider-item__name {
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 6px;
}
.provider-item__star { color: #faad14; font-size: 12px; }
.provider-item__desc {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Detail panel ---------------------------------------------------------- */
.detail-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.detail-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.detail-head__title {
  display: flex;
  align-items: center;
  gap: 12px;
}
.detail-head__name {
  font-size: 17px;
  font-weight: 600;
  line-height: 1.2;
}
.detail-head__desc {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}
.detail-head__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Key/value editor ------------------------------------------------------ */
.kv-editor {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.kv-row {
  display: grid;
  grid-template-columns: 1fr 1fr 40px;
  gap: 8px;
  align-items: center;
}

@media (max-width: 991px) {
  .provider-rail { position: static; margin-bottom: 16px; }
}
</style>
