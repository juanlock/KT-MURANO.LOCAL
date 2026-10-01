<template>
  <div class="page">
    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Document type + save -->
      <div class="pdfc-top">
        <a-segmented v-model:value="docType" :options="typeOptions" @change="load" />
        <a-space>
          <a-button v-if="tab === 'design'" @click="resetDefaults">{{ $t('Reset_to_Default') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-space>
      </div>

      <a-row :gutter="16">
        <!-- ============================ Controls ============================ -->
        <a-col :xs="24" :lg="10" :xl="9">
          <a-tabs v-model:activeKey="tab" class="pdfc-tabs">
            <!-- Default: this company's invoice options (settings row). -->
            <a-tab-pane key="default" tab="Default">
              <a-alert
                v-if="docType !== 'sale'" type="info" show-icon style="margin-bottom: 12px"
                message="These options apply to Sales invoices."
              />
              <div class="pdfc-card">
                <div v-for="f in COMPANY_FLAGS" :key="f.key" class="pdfc-row pdfc-row--top">
                  <div>
                    <div class="pdfc-strong">{{ f.label }}</div>
                    <div class="pdfc-help">{{ f.help }}</div>
                  </div>
                  <a-switch v-model:checked="company[f.key]" />
                </div>
                <div class="pdfc-field" :class="{ 'pdfc-row--off': !company.sale_pdf_show_company_note }">
                  <div class="pdfc-label">Company Note content</div>
                  <a-textarea v-model:value="company.sale_pdf_company_note" :rows="3" placeholder="e.g. We accept credit card for payment with 3.5% extra fee..." />
                </div>
                <div class="pdfc-field" :class="{ 'pdfc-row--off': !company.sale_pdf_show_terms_conditions }">
                  <div class="pdfc-label">Terms &amp; Conditions content</div>
                  <a-textarea v-model:value="company.sale_pdf_terms_conditions" :rows="3" placeholder="e.g. Make all checks payable to: {{COMPANY NAME}}..." />
                  <div class="pdfc-help" v-text="PLACEHOLDER_HELP"></div>
                </div>
              </div>
            </a-tab-pane>

            <!-- Design: colors, fonts, layout, sections, labels (all companies). -->
            <a-tab-pane key="design" tab="Design">
              <a-collapse v-model:activeKey="openPanels" :bordered="false" class="pdfc-panels">
                <a-collapse-panel key="colors" header="Colors">
                  <div v-for="c in colorFields" :key="c.key" class="pdfc-row">
                    <span>{{ c.label }}</span>
                    <input type="color" class="pdfc-color" :value="form[c.key]" @input="e => (form[c.key] = e.target.value)" />
                  </div>
                </a-collapse-panel>

                <a-collapse-panel key="type" header="Typography">
                  <div class="pdfc-row">
                    <span>Font family</span>
                    <a-select v-model:value="form.font_family" style="width: 190px" :options="fontOptions" />
                  </div>
                  <div class="pdfc-row">
                    <span>Font size (pt)</span>
                    <a-input-number v-model:value="form.font_size" :min="7" :max="14" />
                  </div>
                </a-collapse-panel>

                <a-collapse-panel key="layout" header="Layout & logo">
                  <div class="pdfc-row">
                    <span>Top/bottom margin (mm)</span>
                    <a-input-number v-model:value="form.margin_v" :min="0" :max="40" />
                  </div>
                  <div class="pdfc-row">
                    <span>Left/right margin (mm)</span>
                    <a-input-number v-model:value="form.margin_h" :min="0" :max="40" />
                  </div>
                  <div class="pdfc-row">
                    <span>Show logo</span>
                    <a-switch v-model:checked="form.logo_show" />
                  </div>
                  <div class="pdfc-row" :class="{ 'pdfc-row--off': !form.logo_show }">
                    <span>Logo width (px)</span>
                    <a-slider v-model:value="form.logo_width" :min="40" :max="400" style="width: 180px" />
                  </div>
                  <div class="pdfc-row" :class="{ 'pdfc-row--off': !form.logo_show }">
                    <span>Logo height (px)</span>
                    <a-slider v-model:value="form.logo_height" :min="20" :max="300" style="width: 180px" />
                  </div>
                </a-collapse-panel>

                <a-collapse-panel key="table" header="Items table">
                  <div class="pdfc-row">
                    <span>Outer border</span>
                    <a-switch v-model:checked="form.table_borders" />
                  </div>
                  <div class="pdfc-row">
                    <span>Striped rows</span>
                    <a-switch v-model:checked="form.table_striped" />
                  </div>
                </a-collapse-panel>

                <a-collapse-panel key="sections" header="Sections">
                  <div v-for="sct in sectionFields" :key="sct.key" class="pdfc-row">
                    <span>{{ sct.label }}</span>
                    <a-switch v-model:checked="form[sct.key]" />
                  </div>
                </a-collapse-panel>

                <a-collapse-panel key="text" header="Text & labels">
                  <div class="pdfc-field">
                    <div class="pdfc-label">Document title (blank = default)</div>
                    <a-input v-model:value="form.labels.title" :placeholder="defaultTitle" />
                  </div>
                  <div class="pdfc-field">
                    <div class="pdfc-label">Thank-you line (blank = default)</div>
                    <a-input v-model:value="form.labels.thank_you" placeholder="Thank you for your business!" />
                  </div>
                  <div class="pdfc-field">
                    <div class="pdfc-label">Footer text (blank = System Settings invoice footer)</div>
                    <a-textarea v-model:value="form.footer_text" :rows="3" />
                  </div>
                </a-collapse-panel>
              </a-collapse>
            </a-tab-pane>
          </a-tabs>
        </a-col>

        <!-- ============================ Live preview ============================ -->
        <!-- The REAL PDF: rendered by the server with the same blade/builder as
             the download button, from the unsaved settings above, on the
             company's latest document -- so it is exactly what will be made. -->
        <a-col :xs="24" :lg="14" :xl="15">
          <div class="pdfc-preview-wrap">
            <div class="pdfc-preview-bar">
              <EyeOutlined /> Live preview — the real PDF<span v-if="previewRef">&nbsp;({{ previewRef }})</span>
              <a-spin v-if="previewLoading" size="small" style="margin-left: 8px" />
              <a-button size="small" type="link" style="margin-left: auto" @click="refreshPreview">Refresh</a-button>
            </div>
            <div class="pdfc-page">
              <iframe v-if="previewUrl" :src="previewUrl + '#toolbar=0&navpanes=0&view=FitH'" class="pdfc-frame" title="Invoice preview" />
              <a-empty v-else-if="!previewLoading" :description="previewError || 'No preview yet'" style="padding: 96px 0" />
            </div>
          </div>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Settings -> Invoice PDF.
 *  - Default tab: company options (hide time, columns, rows, note, terms) --
 *    GET/PUT pdf_company_settings (acting company's settings row).
 *  - Design tab: GET/POST pdf_templates/{type} for sale | quotation | purchase.
 *  - Preview: POST pdf_templates/{type}/preview with the unsaved design (and,
 *    for sales, the unsaved Default options) -> the real PDF, shown in an
 *    iframe, refreshed shortly after each change.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SaveOutlined, EyeOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { getActingCompanyId } from '../../lib/actingCompany';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const tab = ref('default');
const docType = ref('sale');
const openPanels = ref(['colors', 'type', 'layout', 'table', 'sections', 'text']);
const defaults = ref(null);
const form = ref(null);
const company = ref({});

const typeOptions = [
  { value: 'sale', label: 'Sales Invoice' },
  { value: 'quotation', label: 'Quotation' },
  { value: 'purchase', label: 'Purchase' },
];

const PLACEHOLDER_HELP = '{{COMPANY NAME}} and {{ADDRESS COMPANY}} are replaced with this company\'s own name / address.';

const COMPANY_FLAGS = [
  { key: 'sale_pdf_hide_time', label: 'Hide the time on the invoice date', help: 'Show only the date (2025-12-30), not the time the sale was made (16:39:00).' },
  { key: 'sale_pdf_show_disc_column', label: 'Discount column', help: 'Show the DISC column in the line-items table.' },
  { key: 'sale_pdf_show_tax_column', label: 'Tax column', help: 'Show the TAX column (only when at least one item has its own tax).' },
  { key: 'sale_pdf_show_shipping_row', label: 'Shipping row', help: 'Show Shipping in the totals (only when the invoice has shipping).' },
  { key: 'sale_pdf_show_discount_row', label: 'Discount row', help: 'Show Discount in the totals (only when the invoice has a discount).' },
  { key: 'sale_pdf_show_previous_dues', label: 'Previous Dues / Net Balance', help: 'For a customer with an outstanding balance.' },
  { key: 'sale_pdf_show_company_note', label: 'Company Note', help: 'A note block below the totals.' },
  { key: 'sale_pdf_show_terms_conditions', label: 'Terms & Conditions', help: 'A terms block below the totals.' },
];

const defaultTitle = computed(() => ({
  sale: 'SALES INVOICE', quotation: 'QUOTATION', purchase: 'PURCHASE ORDER',
}[docType.value]));

const colorFields = [
  { key: 'primary_color', label: 'Primary color' },
  { key: 'secondary_color', label: 'Secondary color' },
  { key: 'text_color', label: 'Text color' },
  { key: 'background_color', label: 'Background color' },
];
const fontOptions = ['DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono', 'Helvetica', 'Times', 'Courier']
  .map(f => ({ value: f, label: f }));
const sectionFields = computed(() => [
  { key: 'show_status', label: 'Status / Payment badges' },
  { key: 'show_customer', label: docType.value === 'purchase' ? 'Supplier details' : 'Customer details' },
  { key: 'show_company', label: 'Company details' },
  { key: 'show_notes', label: 'Notes' },
  { key: 'show_footer_text', label: 'Footer text' },
  { key: 'show_thank_you', label: 'Thank-you line' },
]);

// ---------------- real-PDF preview ----------------
const previewUrl = ref('');
const previewRef = ref('');
const previewLoading = ref(false);
const previewError = ref('');
let previewTimer = null;
let previewSeq = 0;

function readCookie(name) {
  const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : '';
}

async function refreshPreview() {
  if (!form.value) return;
  const seq = ++previewSeq;
  previewLoading.value = true;
  previewError.value = '';
  try {
    const qs = getActingCompanyId() ? `?company_id=${getActingCompanyId()}` : '';
    const res = await fetch(`/api/pdf_templates/${docType.value}/preview${qs}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/pdf, application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
      },
      body: JSON.stringify({ ...form.value, company: company.value }),
    });
    if (seq !== previewSeq) return; // a newer preview is on its way
    if (!res.ok) {
      let msg = `Preview failed (HTTP ${res.status})`;
      try { msg = (await res.json()).message || msg; } catch (e) { /* not JSON */ }
      throw new Error(msg);
    }
    const blob = await res.blob();
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = URL.createObjectURL(blob);
    previewRef.value = res.headers.get('X-Preview-Ref') || '';
  } catch (e) {
    if (seq === previewSeq) {
      previewError.value = e.message;
      if (previewUrl.value) { URL.revokeObjectURL(previewUrl.value); previewUrl.value = ''; }
    }
  } finally {
    if (seq === previewSeq) previewLoading.value = false;
  }
}
function schedulePreview() {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(refreshPreview, 700);
}
watch([form, company], schedulePreview, { deep: true });

// ---------------- load / save ----------------
async function load() {
  loading.value = true;
  try {
    const [tpl, co] = await Promise.all([
      http.get(`pdf_templates/${docType.value}`),
      http.get('pdf_company_settings'),
    ]);
    form.value = tpl.settings;
    defaults.value = tpl.defaults;
    company.value = co.settings || {};
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

function resetDefaults() {
  if (defaults.value) form.value = JSON.parse(JSON.stringify(defaults.value));
}

// Saves both tabs: the design (all companies) and this company's Default options.
async function save() {
  saving.value = true;
  try {
    await Promise.all([
      http.post(`pdf_templates/${docType.value}`, form.value),
      http.put('pdf_company_settings', company.value),
    ]);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
onBeforeUnmount(() => {
  clearTimeout(previewTimer);
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<style scoped>
.pdfc-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.pdfc-tabs :deep(.ant-tabs-nav) { margin-bottom: 10px; }
.pdfc-card {
  background: #fff;
  border: 1px solid #ececee;
  border-radius: 10px;
  padding: 6px 14px 12px;
}
.pdfc-panels {
  background: transparent;
}
.pdfc-panels :deep(.ant-collapse-item) {
  background: #fff;
  border: 1px solid #ececee !important;
  border-radius: 10px !important;
  margin-bottom: 10px;
  overflow: hidden;
}
.pdfc-panels :deep(.ant-collapse-header) {
  font-weight: 600;
}
.pdfc-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 7px 0;
  font-size: 13px;
}
.pdfc-row--top {
  align-items: flex-start;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.pdfc-row--off {
  opacity: 0.45;
  pointer-events: none;
}
.pdfc-strong { font-weight: 600; }
.pdfc-help { font-size: 12px; opacity: 0.6; }
.pdfc-color {
  width: 44px;
  height: 30px;
  padding: 2px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
}
.pdfc-field {
  margin: 10px 0 4px;
}
.pdfc-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}

/* ---------------- Preview ---------------- */
.pdfc-preview-wrap {
  position: sticky;
  top: 12px;
}
.pdfc-preview-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
  margin-bottom: 8px;
}
.pdfc-page {
  border: 1px solid #ececee;
  border-radius: 8px;
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
  height: calc(100vh - 170px);
  min-height: 560px;
  overflow: hidden;
  background: #f5f5f7;
}
.pdfc-frame {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}
</style>
