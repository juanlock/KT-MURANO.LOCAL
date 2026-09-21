<template>
  <div class="page">
    <PageHeader :title="$t('sms_templates')" :breadcrumb="[$t('Settings'), $t('sms_templates')]">
      <template #actions>
        <a-select
          v-model:value="locale"
          style="min-width: 160px"
          :options="languages.map(l => ({ value: l.locale, label: l.name }))"
          @change="load"
        />
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="16" class="tpl-grid">
      <!-- Template rail -->
      <a-col :xs="24" :lg="8" :xl="7">
        <div class="tpl-rail">
          <div class="tpl-rail__heading">{{ $t('sms_templates') }}</div>
          <button
            v-for="tpl in TEMPLATE_TYPES" :key="tpl.type"
            type="button"
            class="tpl-item"
            :class="{ 'tpl-item--active': selected === tpl.type }"
            @click="selected = tpl.type"
          >
            <a-avatar :size="38" :style="{ background: tpl.color, flex: '0 0 auto' }">
              <template #icon><component :is="tpl.icon" /></template>
            </a-avatar>
            <div class="tpl-item__text">
              <div class="tpl-item__name">{{ tpl.titleKey ? $t(tpl.titleKey) : tpl.title }}</div>
              <div class="tpl-item__desc">{{ preview(tpl.type) }}</div>
            </div>
            <a-badge :status="hasBody(tpl.type) ? 'success' : 'default'" />
          </button>
        </div>
      </a-col>

      <!-- Editor panel -->
      <a-col :xs="24" :lg="16" :xl="17">
        <a-card :bordered="false" class="editor-card">
          <div class="editor-head">
            <div class="editor-head__title">
              <a-avatar :size="38" :style="{ background: activeTpl.color, flex: '0 0 auto' }">
                <template #icon><component :is="activeTpl.icon" /></template>
              </a-avatar>
              <div>
                <div class="editor-head__name">
                  {{ activeTpl.titleKey ? $t(activeTpl.titleKey) : activeTpl.title }}
                </div>
                <div class="editor-head__desc">{{ $t('sms_body') }}</div>
              </div>
            </div>
            <a-tag v-if="hasBody(selected)" color="success">
              <template #icon><CheckCircleFilled /></template>
              {{ $t('Active') }}
            </a-tag>
          </div>

          <a-divider style="margin: 16px 0" />

          <!-- Merge variables -->
          <div class="vars">
            <div class="vars__label">{{ $t('Variable') }}</div>
            <div class="vars__chips">
              <a-tag
                v-for="v in activeTpl.vars" :key="v"
                class="vars__chip"
                @click="insertVar(v)"
              >
                {{ '{' + v + '}' }}
              </a-tag>
            </div>
          </div>

          <a-textarea
            ref="editorRef"
            v-model:value="bodies[selected]"
            :rows="8"
            :placeholder="$t('sms_body')"
            :maxlength="640"
            show-count
            @focus="trackCursor"
            @click="trackCursor"
            @keyup="trackCursor"
          />

          <div class="editor-foot">
            <a-button
              type="primary"
              :loading="saving === selected"
              @click="save(selected)"
            >
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
          </div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * GET get_sms_template?locale → one body per type; save one at a time with
 * PUT update_sms_body {sms_body, sms_body_type, locale}. Locales from
 * GET languages_setting (array, is_default marks the initial pick).
 *
 * UI is a master/detail: a rail of template types on the left, an editor on the
 * right. The merge-variable chips (per legacy's per-template variable lists)
 * insert their {token} at the caret. Variable sets are copied verbatim from the
 * legacy page so sent messages interpolate identically.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  SaveOutlined, CheckCircleFilled, ShoppingOutlined, FileTextOutlined, DollarOutlined,
  ShoppingCartOutlined, SendOutlined, ReloadOutlined, SafetyCertificateOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

// Legacy card titles: Quote (not Quotation), PaiementsReceived/Sent, and a
// literal "Subscription Reminder" with no key. `vars` mirror the legacy page's
// per-template placeholder lists.
const TEMPLATE_TYPES = [
  { type: 'sale', titleKey: 'Sale', color: '#1677ff', icon: ShoppingOutlined, vars: ['contact_name', 'business_name', 'invoice_number', 'invoice_url', 'total_amount', 'paid_amount', 'due_amount'] },
  { type: 'quotation', titleKey: 'Quote', color: '#13c2c2', icon: FileTextOutlined, vars: ['contact_name', 'business_name', 'quotation_number', 'quotation_url', 'total_amount'] },
  { type: 'payment_received', titleKey: 'PaiementsReceived', color: '#52c41a', icon: DollarOutlined, vars: ['contact_name', 'business_name', 'payment_number', 'paid_amount'] },
  { type: 'purchase', titleKey: 'Purchase', color: '#722ed1', icon: ShoppingCartOutlined, vars: ['contact_name', 'business_name', 'invoice_number', 'invoice_url', 'total_amount', 'paid_amount', 'due_amount'] },
  { type: 'payment_sent', titleKey: 'PaiementsSent', color: '#fa8c16', icon: SendOutlined, vars: ['contact_name', 'business_name', 'payment_number', 'paid_amount'] },
  { type: 'subscription_reminder', title: 'Subscription Reminder', color: '#eb2f96', icon: ReloadOutlined, vars: ['client_name', 'business_name', 'next_billing_date'] },
  { type: 'asset_validation_due', titleKey: 'Asset_Validation_Due', color: '#f5222d', icon: SafetyCertificateOutlined, vars: ['asset_name', 'asset_tag', 'next_validation', 'business_name'] },
];

const loading = ref(true);
const saving = ref(null);
const languages = ref([]);
const locale = ref(undefined);
const bodies = ref({});
const selected = ref(TEMPLATE_TYPES[0].type);
const editorRef = ref(null);
const cursorPos = ref(null);

const activeTpl = computed(() => TEMPLATE_TYPES.find(x => x.type === selected.value) || TEMPLATE_TYPES[0]);
const hasBody = type => !!(bodies.value[type] || '').trim();
const preview = type => {
  const body = (bodies.value[type] || '').trim().replace(/\s+/g, ' ');
  return body ? (body.length > 44 ? body.slice(0, 44) + '…' : body) : '—';
};

function trackCursor(e) {
  cursorPos.value = e?.target?.selectionStart ?? null;
}

function insertVar(v) {
  const token = `{${v}}`;
  const body = bodies.value[selected.value] || '';
  const pos = cursorPos.value == null ? body.length : cursorPos.value;
  bodies.value[selected.value] = body.slice(0, pos) + token + body.slice(pos);
  cursorPos.value = pos + token.length;
  // Restore focus so the caret lands after the inserted token.
  const el = editorRef.value?.resizableTextArea?.textArea;
  if (el) {
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(cursorPos.value, cursorPos.value);
    });
  }
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('get_sms_template', { locale: locale.value || '' });
    bodies.value = {
      sale: data.sms_body_sale || '',
      quotation: data.sms_body_quotation || '',
      payment_received: data.sms_body_payment_received || '',
      purchase: data.sms_body_purchase || '',
      payment_sent: data.sms_body_payment_sent || '',
      subscription_reminder: data.sms_body_subscription_reminder || '',
      asset_validation_due: data.sms_body_asset_validation_due || '',
    };
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function save(type) {
  saving.value = type;
  try {
    await http.put('update_sms_body', {
      sms_body: bodies.value[type],
      sms_body_type: type,
      locale: locale.value,
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = null;
  }
}

onMounted(async () => {
  try {
    const langs = await http.get('languages_setting');
    languages.value = Array.isArray(langs) ? langs : [];
    const def = languages.value.find(l => l.is_default) || languages.value[0];
    if (def) locale.value = def.locale;
  } catch (e) {
    languages.value = [];
  }
  await load();
});
</script>

<style scoped>
.tpl-grid { margin-top: 4px; }

/* Template rail --------------------------------------------------------- */
.tpl-rail {
  display: flex;
  flex-direction: column;
  gap: 8px;
  position: sticky;
  top: 12px;
}
.tpl-rail__heading {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgba(0, 0, 0, 0.45);
  padding: 4px 6px;
}
.tpl-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 11px 14px;
  border: 1px solid #f0f0f0;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.18s, box-shadow 0.18s, transform 0.05s;
}
.tpl-item:hover {
  border-color: #91caff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}
.tpl-item:active { transform: translateY(1px); }
.tpl-item--active {
  border-color: #1677ff;
  box-shadow: 0 0 0 2px rgba(22, 119, 255, 0.12);
}
.tpl-item__text { flex: 1 1 auto; min-width: 0; }
.tpl-item__name { font-weight: 600; }
.tpl-item__desc {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Editor panel ---------------------------------------------------------- */
.editor-card {
  border: 1px solid #f0f0f0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.editor-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.editor-head__title {
  display: flex;
  align-items: center;
  gap: 12px;
}
.editor-head__name {
  font-size: 17px;
  font-weight: 600;
  line-height: 1.2;
}
.editor-head__desc {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}

/* Variables ------------------------------------------------------------- */
.vars { margin-bottom: 12px; }
.vars__label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 8px;
}
.vars__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.vars__chip {
  cursor: pointer;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  transition: color 0.15s, border-color 0.15s, background 0.15s;
}
.vars__chip:hover {
  color: #1677ff;
  border-color: #91caff;
  background: rgba(22, 119, 255, 0.06);
}

.editor-foot {
  margin-top: 16px;
}

@media (max-width: 991px) {
  .tpl-rail { position: static; margin-bottom: 16px; }
}
</style>
