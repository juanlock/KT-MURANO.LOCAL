<template>
  <div class="page">
    <PageHeader :title="$t('email_templates')" :breadcrumb="[$t('Settings'), $t('email_templates')]">
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
          <div class="tpl-rail__heading">{{ $t('email_templates') }}</div>
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
            <a-badge :status="hasContent(tpl.type) ? 'success' : 'default'" />
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
                <div class="editor-head__desc">{{ $t('email_templates') }}</div>
              </div>
            </div>
            <a-tag v-if="hasContent(selected)" color="success">
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

          <a-form layout="vertical">
            <a-form-item :label="$t('Subject')">
              <a-input
                ref="subjectRef"
                v-model:value="templates[selected].subject"
                @focus="e => { activeField = 'subject'; trackCursor(e); }"
                @click="trackCursor"
                @keyup="trackCursor"
              />
            </a-form-item>
            <a-form-item :label="$t('Message')">
              <!-- Rich HTML editor — email bodies are HTML; merge tokens like
                   {customer_name} are preserved as plain text. -->
              <div @focusin="activeField = 'body'">
                <RichTextEditor ref="bodyRef" v-model="templates[selected].body" />
              </div>
            </a-form-item>
          </a-form>

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
 * GET get_emails_template?locale → {sale, quotation, payment_received,
 * purchase, payment_sent, booking, asset_validation_due} each {subject, body};
 * save per type with PUT update_custom_email {custom_email_body,
 * custom_email_subject, email_type, locale}.
 *
 * UI is a master/detail: a rail of template types on the left, a subject +
 * rich-body editor on the right. Merge-variable chips (copied verbatim from the
 * legacy page's per-template lists) insert their {token} into whichever field
 * was last focused — the HTML body or the subject.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  SaveOutlined, CheckCircleFilled, ShoppingOutlined, FileTextOutlined, DollarOutlined,
  ShoppingCartOutlined, SendOutlined, CalendarOutlined, SafetyCertificateOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import http from '../../lib/http';

const { t } = useI18n();

// `vars` mirror the legacy page's per-template placeholder lists so rendered
// emails interpolate identically.
const TEMPLATE_TYPES = [
  { type: 'sale', titleKey: 'Sale', color: '#1677ff', icon: ShoppingOutlined, vars: ['contact_name', 'business_name', 'invoice_number', 'invoice_url', 'total_amount', 'paid_amount', 'due_amount'] },
  { type: 'quotation', titleKey: 'Quote', color: '#13c2c2', icon: FileTextOutlined, vars: ['contact_name', 'business_name', 'quotation_number', 'quotation_url', 'total_amount'] },
  { type: 'payment_received', titleKey: 'PaiementsReceived', color: '#52c41a', icon: DollarOutlined, vars: ['contact_name', 'business_name', 'payment_number', 'paid_amount'] },
  { type: 'purchase', titleKey: 'Purchase', color: '#722ed1', icon: ShoppingCartOutlined, vars: ['contact_name', 'business_name', 'invoice_number', 'invoice_url', 'total_amount', 'paid_amount', 'due_amount'] },
  { type: 'payment_sent', titleKey: 'PaiementsSent', color: '#fa8c16', icon: SendOutlined, vars: ['contact_name', 'business_name', 'payment_number', 'paid_amount'] },
  { type: 'booking', titleKey: 'Custom_Template_Booking', color: '#eb2f96', icon: CalendarOutlined, vars: ['contact_name', 'business_name', 'booking_number', 'booking_date', 'start_time', 'end_time', 'service_name'] },
  { type: 'asset_validation_due', titleKey: 'Asset_Validation_Due', color: '#f5222d', icon: SafetyCertificateOutlined, vars: ['asset_name', 'asset_tag', 'next_validation', 'asset_edit_url', 'business_name'] },
];

const empty = () => ({ subject: '', body: '' });

const loading = ref(true);
const saving = ref(null);
const languages = ref([]);
const locale = ref(undefined);
const templates = ref(Object.fromEntries(TEMPLATE_TYPES.map(x => [x.type, empty()])));
const selected = ref(TEMPLATE_TYPES[0].type);
const subjectRef = ref(null);
const bodyRef = ref(null);
const activeField = ref('body');
const cursorPos = ref(null);

const activeTpl = computed(() => TEMPLATE_TYPES.find(x => x.type === selected.value) || TEMPLATE_TYPES[0]);
const stripHtml = html => (html || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
const hasContent = type => {
  const tpl = templates.value[type] || {};
  return !!(tpl.subject || '').trim() || !!stripHtml(tpl.body);
};
const preview = type => {
  const tpl = templates.value[type] || {};
  const text = (tpl.subject || '').trim() || stripHtml(tpl.body);
  return text ? (text.length > 44 ? text.slice(0, 44) + '…' : text) : '—';
};

function trackCursor(e) {
  cursorPos.value = e?.target?.selectionStart ?? null;
}

function insertVar(v) {
  const token = `{${v}}`;
  if (activeField.value === 'subject') {
    const s = templates.value[selected.value].subject || '';
    const pos = cursorPos.value == null ? s.length : cursorPos.value;
    templates.value[selected.value].subject = s.slice(0, pos) + token + s.slice(pos);
    cursorPos.value = pos + token.length;
    const el = subjectRef.value?.input;
    if (el) {
      requestAnimationFrame(() => {
        el.focus();
        el.setSelectionRange(cursorPos.value, cursorPos.value);
      });
    }
  } else {
    bodyRef.value?.insertText(token);
  }
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('get_emails_template', { locale: locale.value || '' });
    TEMPLATE_TYPES.forEach(({ type }) => {
      templates.value[type] = { ...empty(), ...(data[type] || {}) };
    });
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function save(type) {
  saving.value = type;
  try {
    await http.put('update_custom_email', {
      custom_email_body: templates.value[type].body,
      custom_email_subject: templates.value[type].subject,
      email_type: type,
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
.vars { margin-bottom: 16px; }
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

.editor-foot { margin-top: 16px; }

@media (max-width: 991px) {
  .tpl-rail { position: static; margin-bottom: 16px; }
}
</style>
