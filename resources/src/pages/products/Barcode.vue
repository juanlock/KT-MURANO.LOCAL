<template>
  <div class="page">
    <PageHeader :title="$t('Printbarcode')" :breadcrumb="[$t('Products'), $t('Printbarcode')]" />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-modal v-model:open="scanOpen" :title="'Barcode Scanner'" :footer="null" destroy-on-close>
        <QrcodeScanner :qrbox="250" :fps="10" style="width: 100%" @result="onScan" />
      </a-modal>

      <a-row :gutter="16">
      <a-col :xs="24" :lg="15">

      <!-- Configuration -->
      <a-card :title="$t('Configuration') || 'Configuration'" size="small" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item
              :label="$t('warehouse') + ' *'" layout="vertical"
              :validate-status="warehouseTouched && !barcode.warehouse_id ? 'error' : undefined"
              :help="warehouseTouched && !barcode.warehouse_id ? $t('Field_is_required') : undefined"
            >
              <a-select
                v-model:value="barcode.warehouse_id"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                show-search option-filter-prop="label"
                @change="Selected_Warehouse"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Paper_size')" layout="vertical">
              <a-select
                v-model:value="paper_size"
                :placeholder="$t('Paper_size')"
                :options="getPaperSizeOptions().map(o => ({ label: o.label, value: o.value }))"
                @change="Selected_Paper_size"
              />
            </a-form-item>
          </a-col>

          <a-col :span="24" v-if="paper_size === 'customstyle' || (paper_size && paper_size.startsWith('sticker_'))">
            <a-form-item :label="paper_size === 'customstyle' ? 'Custom Sticker Dimensions' : 'Sticker Dimensions'" layout="vertical">
              <a-row :gutter="12">
                <a-col :span="12">
                  <a-input-number
                    v-model:value="custom_sticker_width" :min="1" style="width: 100%"
                    placeholder="Width" :disabled="paper_size !== 'customstyle'"
                    @change="updateCustomStickerLabel"
                  />
                  <small style="color: #8c8c8c">Width (mm)</small>
                </a-col>
                <a-col :span="12">
                  <a-input-number
                    v-model:value="custom_sticker_height" :min="1" style="width: 100%"
                    placeholder="Height" :disabled="paper_size !== 'customstyle'"
                    @change="updateCustomStickerLabel"
                  />
                  <small style="color: #8c8c8c">Height (mm)</small>
                </a-col>
              </a-row>
              <small v-if="paper_size !== 'customstyle'" style="color: #8c8c8c; display: block; margin-top: 6px">
                Dimensions are preset. Select "Stickers - Custom" to enter custom dimensions.
              </small>
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12">
            <a-checkbox v-model:checked="auto_print">{{ $t('Auto_Print') || 'Auto Print' }}</a-checkbox>
          </a-col>
        </a-row>

      </a-card>

      <!-- Label design -->
      <a-card size="small" style="margin-bottom: 16px">
        <template #title>{{ $t('Label_Design') }}</template>
        <template #extra>
          <a-button type="primary" ghost size="small" :loading="savingLabelSettings" @click="saveLabelSettings">
            {{ $t('Save_Label_Settings') }}
          </a-button>
        </template>

        <!-- Template picker: each card is a schematic of what the template prints -->
        <div class="tpl-grid">
          <div
            v-for="opt in templateCards" :key="opt.value"
            class="tpl-card" :class="{ active: label_settings.template === opt.value }"
            role="button" tabindex="0"
            @click="selectTemplate(opt.value)"
            @keydown.enter.prevent="selectTemplate(opt.value)"
            @keydown.space.prevent="selectTemplate(opt.value)"
          >
            <div class="tpl-thumb" :class="{ compact: opt.parts.compact }">
              <SettingOutlined v-if="opt.parts.custom" class="tpl-custom-icon" />
              <template v-else>
                <span v-if="opt.parts.name" class="tpl-line tpl-name" :class="{ big: opt.parts.big }"></span>
                <span v-if="opt.parts.name" class="tpl-line tpl-name short" :class="{ big: opt.parts.big }"></span>
                <span v-if="opt.parts.barcode" class="tpl-bars" :class="{ tall: opt.parts.tall }"></span>
                <span v-if="opt.parts.number" class="tpl-line tpl-number"></span>
                <span v-if="opt.parts.price" class="tpl-price"></span>
              </template>
            </div>
            <div class="tpl-label">{{ opt.label }}</div>
          </div>
        </div>

        <a-row :gutter="[16, 8]" style="margin-top: 16px">
          <a-col :xs="12" :md="6">
            <a-checkbox v-model:checked="label_settings.show_name" @change="onLabelSettingChanged">
              {{ $t('Display_Product_Name') }}
            </a-checkbox>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-checkbox v-model:checked="label_settings.show_barcode" @change="onLabelSettingChanged">
              {{ $t('Display_Barcode') }}
            </a-checkbox>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-checkbox v-model:checked="label_settings.show_barcode_number" @change="onLabelSettingChanged">
              {{ $t('Display_Barcode_Number') }}
            </a-checkbox>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-checkbox v-model:checked="label_settings.show_price" @change="onLabelSettingChanged">
              {{ $t('Display_Price') || 'Display Price' }}
            </a-checkbox>
          </a-col>
        </a-row>

        <a-row :gutter="16" style="margin-top: 12px">
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Barcode_Height')" layout="vertical" style="margin-bottom: 0">
              <a-input-number
                v-model:value="label_settings.barcode_height" :min="10" :max="100" style="width: 100%"
                @change="onLabelSettingChanged"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Font_Size')" layout="vertical" style="margin-bottom: 0">
              <a-input-number
                v-model:value="label_settings.font_size" :min="6" :max="30" style="width: 100%"
                @change="onLabelSettingChanged"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Barcode_Number_Size')" layout="vertical" style="margin-bottom: 0">
              <a-input-number
                v-model:value="label_settings.number_font_size" :min="6" :max="30" style="width: 100%"
                @change="onLabelSettingChanged"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Font_Weight')" layout="vertical" style="margin-bottom: 0">
              <a-select
                v-model:value="label_settings.bold_font"
                :options="[{ value: true, label: $t('Bold') }, { value: false, label: $t('Regular') }]"
                @change="onLabelSettingChanged"
              />
            </a-form-item>
          </a-col>
        </a-row>

        <a-row :gutter="16" style="margin-top: 12px">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Font_Family')" layout="vertical" style="margin-bottom: 0">
              <a-select
                v-model:value="label_settings.font_family"
                option-label-prop="label"
                @change="onLabelSettingChanged"
              >
                <a-select-option v-for="f in FONT_FAMILIES" :key="f.value" :value="f.value" :label="f.label">
                  <span :style="{ fontFamily: f.value }">{{ f.label }}</span>
                </a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <!-- Product search -->
      <a-card :title="$t('ProductName')" size="small" style="margin-bottom: 16px">
        <div style="display: flex; gap: 8px">
          <a-button @click="scanOpen = true" :title="$t('Scan_Barcode') || 'Scan Barcode'">
            <QrcodeOutlined />
          </a-button>
          <a-auto-complete
            v-model:value="search_input"
            style="flex: 1"
            :options="autocompleteOptions"
            :filter-option="false"
            :placeholder="$t('Scan_Search_Product_by_Code_Name')"
            @search="search()"
            @select="onAutocompleteSelect"
          />
        </div>
      </a-card>

      <!-- Selected products -->
      <a-card size="small" style="margin-bottom: 16px">
        <template #title>
          {{ $t('Selected_Products') || $t('ProductName') }}
          <a-tag v-if="products_added.length" color="blue" style="margin-left: 8px">{{ products_added.length }}</a-tag>
        </template>
        <template #extra>
          <a-space>
            <a-tag v-if="labelPrinterEnabled" color="green" style="margin: 0">
              {{ $t('Label_Printer_Direct') }}
            </a-tag>
            <a-button danger size="small" @click="reset()">{{ $t('Reset') }}</a-button>
            <a-button
              v-if="products_added.length > 0" type="primary" size="small"
              :loading="printingDirect" @click="print_all_Barcode()"
            >
              {{ $t('print') }}
            </a-button>
          </a-space>
        </template>
        <a-table
          :columns="selectedColumns" :data-source="products_added"
          size="small" :pagination="false" row-key="code"
          :locale="{ emptyText: $t('NodataAvailable') }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'qte'">
              <a-input-number
                v-model:value="record.qte" :min="1" style="width: 90px"
                @change="autoGenerateBarcodes()"
              />
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-button size="small" danger :title="$t('Delete')" @click="delete_Product(record.code)">×</a-button>
            </template>
          </template>
        </a-table>
      </a-card>

      </a-col>

      <a-col :xs="24" :lg="9">
        <div class="preview-sticky">
          <a-card size="small">
            <template #title>{{ $t('Live_Preview') }}</template>
            <template #extra>
              <a-segmented v-model:value="previewZoom" :options="zoomOptions" size="small" />
            </template>
            <!-- Example sticker at true size on a cutting-mat stage. Kept
                 outside #print_barcode_label so it is never printed. -->
            <div class="label-stage">
              <div class="label-sample" :style="{ zoom: previewZoom }">
                <div :class="class_type_page || 'barcode_custom'">
                  <div class="barcode-item" :class="class_sheet || 'customstyle'">
                    <div class="bc-name" v-if="label_settings.show_name">{{ sampleLabel.name }}</div>
                    <BarcodeSvg
                      v-if="label_settings.show_barcode"
                      class="barcode"
                      :format="sampleLabel.Type_barcode"
                      :value="sampleLabel.barcode"
                      textmargin="0"
                      :fontoptions="label_settings.bold_font ? 'bold' : ''"
                      :font="label_settings.font_family"
                      :fontSize="Math.max(6, Number(label_settings.number_font_size) || 10)"
                      :displayValue="label_settings.show_barcode_number"
                      :height="label_settings.barcode_height || 28"
                      width="1"
                    />
                    <div class="bc-footer" v-if="label_settings.show_price">
                      <span class="bc-price">{{ auth.currency }} {{ sampleLabel.Net_price }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="stage-caption">
              <a-tag style="margin: 0">{{ sampleSizeLabel }}</a-tag>
              <span v-if="!products_added.length" class="stage-note">{{ $t('Label_Preview_Note') }}</span>
            </div>
          </a-card>
        </div>
      </a-col>
      </a-row>

      <!-- Preview -->
      <a-card v-if="ShowCard" size="small">
        <template #title>
          {{ $t('Barcode_Preview') || 'Barcode Preview' }}
          <a-tag v-if="pages.length" color="cyan" style="margin-left: 8px">{{ pages.length }} {{ $t('Pages') || 'Pages' }}</a-tag>
        </template>
        <template #extra>
          <a-button type="primary" @click="print_all_Barcode()">{{ $t('print') }}</a-button>
        </template>
        <!-- The innerHTML of #print_barcode_label is copied into the print
             popup, styled by /assets_setup/css/print_label.css plus the two
             injected style tags (#custom-sticker-dimensions and
             #barcode-label-settings-style). Selectors in those styles must
             not rely on the #print_barcode_label ancestor: it does not exist
             inside the popup document. -->
        <div class="barcode-row" id="print_barcode_label">
          <div v-for="(page, pageIndex) in pages" :key="pageIndex">
            <div :class="class_type_page">
              <div class="barcode-item" :class="class_sheet" v-for="(bc, index) in page" :key="index">
                <div class="bc-name" v-if="label_settings.show_name">{{ bc.name }}</div>
                <BarcodeSvg
                  v-if="label_settings.show_barcode"
                  class="barcode"
                  :format="bc.Type_barcode"
                  :value="bc.barcode"
                  textmargin="0"
                  :fontoptions="label_settings.bold_font ? 'bold' : ''"
                  :font="label_settings.font_family"
                  :fontSize="Math.max(6, Number(label_settings.number_font_size) || 10)"
                  :displayValue="label_settings.show_barcode_number"
                  :height="label_settings.barcode_height || 28"
                  width="1"
                />
                <div class="bc-footer" v-if="label_settings.show_price">
                  <span class="bc-price">{{ auth.currency }} {{ bc.Net_price }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * Print Barcode page — logic ported VERBATIM from legacy barcode.vue:
 * barcode_create_page for warehouses, get_Products_by_warehouse/{id}
 * ?stock=0&product_service=1&product_combo=1, purchases/{id}/barcodes deep
 * link (?purchase_id=), local token search with 800ms debounce and
 * exact-code auto-add, paper-size map (style40..style10 + sticker presets +
 * customstyle via injected #custom-sticker-dimensions style), auto-print
 * 1.5s after generation, print popup fed print_label.css + the preview's
 * innerHTML. The same print_label.css is linked on-screen so the preview
 * matches legacy's globals-based rendering.
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { notification } from 'ant-design-vue';
import { QrcodeOutlined, SettingOutlined } from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import BarcodeSvg from '../../components/BarcodeSvg.vue';
import QrcodeScanner from '../../pos-compat/QrcodeScanner';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { qzPrintRaw, isQzConnectError } from '../../lib/qzPrint';
import { renderLabelRaster } from '../../lib/labelRaster';
import { start as startProgress, done as doneProgress } from '../../lib/progress';

const { t } = useI18n();
const route = useRoute();
const auth = useAuthStore();

const isLoading = ref(true);
const ShowCard = ref(false);
const scanOpen = ref(false);
const search_input = ref('');
const product_filter = ref([]);
const warehouses = ref([]);
const products = ref([]);
const products_added = ref([]);
const pages = ref([]);
const barcode = ref({ product_id: '', warehouse_id: undefined, qte: 10 });
const paper_size = ref(undefined);
const sheets = ref('');
const class_sheet = ref('');
const class_type_page = ref('');
const auto_print = ref(true);
const custom_sticker_width = ref(50);
const custom_sticker_height = ref(25);
const warehouseTouched = ref(false);

// Label design settings — loaded from / saved to settings.barcode_label_settings.
const labelPrinterEnabled = ref(false);
const labelRenderMode = ref('native');
// Physical sticker size from the printer settings — direct printing uses
// this, NOT the on-screen sticker size: the preview default (50x25) has no
// reason to match the roll, and a mismatched SIZE makes the printer hunt for
// the gap in the wrong place so every following label drifts.
const labelPrinterSize = ref({ width: null, height: null });
const labelPrinterDpi = ref(203);
const labelPrinterOffsets = ref({ x: 0, y: 0 });
const currencyCode = ref('');
const printingDirect = ref(false);
const label_settings = ref({
  template: 'template1',
  show_name: true,
  show_price: true,
  show_barcode: true,
  show_barcode_number: true,
  barcode_height: 28,
  font_size: 10,
  number_font_size: 10,
  bold_font: true,
  font_family: 'Arial, Helvetica, sans-serif',
});
const savingLabelSettings = ref(false);

// Web-safe stacks only: the print popup and the label preview load no
// webfonts, so every choice must resolve from fonts installed with the OS.
const FONT_FAMILIES = [
  { label: 'Arial', value: 'Arial, Helvetica, sans-serif' },
  { label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
  { label: 'Tahoma', value: 'Tahoma, Geneva, sans-serif' },
  { label: 'Trebuchet MS', value: "'Trebuchet MS', Helvetica, sans-serif" },
  { label: 'Times New Roman', value: "'Times New Roman', Times, serif" },
  { label: 'Georgia', value: 'Georgia, serif' },
  { label: 'Garamond', value: 'Garamond, serif' },
  { label: 'Courier New', value: "'Courier New', Courier, monospace" },
  { label: 'Lucida Console', value: "'Lucida Console', Monaco, monospace" },
  { label: 'Impact', value: 'Impact, Charcoal, sans-serif' },
];

// Built-in label templates. Selecting one applies its preset to the custom
// controls; changing any control afterwards flips the selector to "custom".
const LABEL_TEMPLATES = {
  // Product name + barcode + barcode number + price
  template1: { show_name: true, show_barcode: true, show_barcode_number: true, show_price: true, barcode_height: 28, font_size: 10, number_font_size: 10, bold_font: true },
  // Product name + price only
  template2: { show_name: true, show_barcode: false, show_barcode_number: false, show_price: true, barcode_height: 28, font_size: 14, number_font_size: 10, bold_font: true },
  // Barcode only
  template3: { show_name: false, show_barcode: true, show_barcode_number: true, show_price: false, barcode_height: 42, font_size: 10, number_font_size: 10, bold_font: true },
  // Product name + barcode + price, larger fonts
  template4: { show_name: true, show_barcode: true, show_barcode_number: false, show_price: true, barcode_height: 24, font_size: 13, number_font_size: 12, bold_font: true },
  // Compact label for small accessories
  template5: { show_name: true, show_barcode: true, show_barcode_number: true, show_price: true, barcode_height: 16, font_size: 7, number_font_size: 7, bold_font: false },
};

// Template picker cards — `parts` drives the schematic thumbnail so each card
// shows what that template actually prints.
const templateCards = computed(() => [
  { value: 'template1', label: t('Label_Template_1'), parts: { name: true, barcode: true, number: true, price: true } },
  { value: 'template2', label: t('Label_Template_2'), parts: { name: true, price: true } },
  { value: 'template3', label: t('Label_Template_3'), parts: { barcode: true, number: true, tall: true } },
  { value: 'template4', label: t('Label_Template_4'), parts: { name: true, big: true, barcode: true, price: true } },
  { value: 'template5', label: t('Label_Template_5'), parts: { name: true, barcode: true, number: true, price: true, compact: true } },
  { value: 'custom', label: t('Label_Template_Custom'), parts: { custom: true } },
]);

function selectTemplate(value) {
  label_settings.value.template = value;
  applyTemplate(value);
}

// ---- Live preview (example sticker) ----
const previewZoom = ref(1);
const zoomOptions = [
  { label: '100%', value: 1 },
  { label: '150%', value: 1.5 },
  { label: '200%', value: 2 },
];

// First selected product if any, else example data (name long enough to
// demonstrate the 2-line wrap).
const sampleLabel = computed(() => {
  const p = products_added.value[0];
  if (p) {
    return { name: p.name, barcode: p.barcode, Type_barcode: p.Type_barcode, Net_price: p.Net_price };
  }
  return { name: t('Label_Sample_Product'), barcode: '628112345678', Type_barcode: 'CODE128', Net_price: '49.99' };
});

const SHEET_ITEM_SIZES = {
  style40: '1.8 × 1.0 in',
  style30: '2.63 × 1 in',
  style24: '2.48 × 1.33 in',
  style20: '4 × 1 in',
  style18: '2.5 × 1.84 in',
  style14: '4 × 1.33 in',
  style12: '2.5 × 2.83 in',
  style10: '4 × 2 in',
};

const sampleSizeLabel = computed(() => {
  if (!class_type_page.value || class_type_page.value === 'barcode_custom') {
    return `${custom_sticker_width.value || 50} × ${custom_sticker_height.value || 25} mm`;
  }
  return SHEET_ITEM_SIZES[class_sheet.value] || '';
});

const autocompleteOptions = computed(() => product_filter.value.map((p) => ({
  value: p.code,
  label: getResultValue(p),
})));

const selectedColumns = computed(() => [
  { title: t('ProductName'), dataIndex: 'name', key: 'name' },
  { title: t('CodeProduct'), dataIndex: 'code', key: 'code' },
  { title: t('Quantity'), key: 'qte', width: 130, align: 'center' },
  { title: t('Actions') || 'Actions', key: 'actions', width: 80, align: 'center' },
]);

function onAutocompleteSelect(code) {
  const p = product_filter.value.find((x) => x.code === code)
    || products.value.find((x) => x.code === code);
  if (p) SearchProduct(p);
}

let timer = null;
let printTimeout = null;
let isGenerating = false;

const canGenerateBarcodes = computed(() => {
  const hasPaperSize = paper_size.value
    && (sheets.value > 0
      || paper_size.value === 'customstyle'
      || (paper_size.value && paper_size.value.startsWith('sticker_')));
  return products_added.value.length > 0 && hasPaperSize && barcode.value.warehouse_id;
});

function makeToast(variant, msg, title) {
  const fn = variant === 'danger' ? 'error' : (notification[variant] ? variant : 'info');
  notification[fn]({ message: title, description: msg });
}

function getPaperSizeOptions() {
  const baseOptions = [
    { label: '40 per sheet (a4) (1.799 * 1.003)', value: 'style40' },
    { label: '30 per sheet (2.625 * 1)', value: 'style30' },
    { label: '24 per sheet (a4) (2.48 * 1.334)', value: 'style24' },
    { label: '20 per sheet (4 * 1)', value: 'style20' },
    { label: '18 per sheet (a4) (2.5 * 1.835)', value: 'style18' },
    { label: '14 per sheet (4 * 1.33)', value: 'style14' },
    { label: '12 per sheet (a4) (2.5 * 2.834)', value: 'style12' },
    { label: '10 per sheet (4 * 2)', value: 'style10' },
  ];
  const stickerOptions = [
    { label: 'Stickers - 50mm x 25mm', value: 'sticker_50x25', width: 50, height: 25 },
    { label: 'Stickers - 50mm x 30mm', value: 'sticker_50x30', width: 50, height: 30 },
    { label: 'Stickers - 53mm x 32mm (Avery 22806)', value: 'sticker_53x32', width: 53, height: 32 },
    { label: 'Stickers - 57mm x 32mm', value: 'sticker_57x32', width: 57, height: 32 },
    { label: 'Stickers - 63mm x 29mm', value: 'sticker_63x29', width: 63, height: 29 },
    { label: 'Stickers - 63mm x 38mm', value: 'sticker_63x38', width: 63, height: 38 },
    { label: 'Stickers - 70mm x 36mm', value: 'sticker_70x36', width: 70, height: 36 },
    { label: 'Stickers - 70mm x 37mm', value: 'sticker_70x37', width: 70, height: 37 },
    { label: 'Stickers - 74mm x 52mm', value: 'sticker_74x52', width: 74, height: 52 },
    { label: 'Stickers - 80mm x 50mm', value: 'sticker_80x50', width: 80, height: 50 },
    { label: 'Stickers - 100mm x 50mm', value: 'sticker_100x50', width: 100, height: 50 },
    { label: 'Stickers - 100mm x 70mm', value: 'sticker_100x70', width: 100, height: 70 },
    { label: 'Stickers - 105mm x 37mm', value: 'sticker_105x37', width: 105, height: 37 },
    { label: 'Stickers - 105mm x 48mm', value: 'sticker_105x48', width: 105, height: 48 },
    { label: 'Stickers - 105mm x 74mm', value: 'sticker_105x74', width: 105, height: 74 },
    { label: 'Stickers - 148mm x 105mm (A5)', value: 'sticker_148x105', width: 148, height: 105 },
  ];
  const out = baseOptions.concat(stickerOptions.map((o) => ({ ...o })));
  out.push({ label: 'Stickers - Custom Value', value: 'customstyle' });
  return out;
}

function applyCustomStickerDimensions() {
  nextTick(() => {
    const styleId = 'custom-sticker-dimensions';
    let styleElement = document.getElementById(styleId);
    if (!styleElement) {
      styleElement = document.createElement('style');
      styleElement.id = styleId;
      document.head.appendChild(styleElement);
    }
    const widthMM = custom_sticker_width.value || 50;
    const heightMM = custom_sticker_height.value || 25;
    styleElement.textContent = `
      .barcode_custom {
        width: ${widthMM}mm !important;
        height: ${heightMM}mm !important;
      }
    `;
  });
}

// Dynamic bits of the label design (font size / weight). Injected as a style
// tag so it can be copied into the print popup alongside the sticker
// dimensions. Selectors are id-free on purpose (see the preview comment).
const LABEL_STYLE_ID = 'barcode-label-settings-style';
function applyLabelSettingsStyle() {
  let styleElement = document.getElementById(LABEL_STYLE_ID);
  if (!styleElement) {
    styleElement = document.createElement('style');
    styleElement.id = LABEL_STYLE_ID;
    document.head.appendChild(styleElement);
  }
  const fontSize = Number(label_settings.value.font_size) || 10;
  const weight = label_settings.value.bold_font ? 'bold' : 'normal';
  const family = label_settings.value.font_family || 'Arial, Helvetica, sans-serif';
  styleElement.textContent = `
    .barcode-item .bc-name {
      font-size: ${fontSize}px;
      line-height: ${Math.round(fontSize * 1.25)}px;
      max-height: ${Math.round(fontSize * 1.25) * 2}px;
      font-weight: ${weight};
      font-family: ${family};
    }
    .barcode-item .bc-price {
      font-size: ${fontSize}px;
      font-weight: ${weight};
      font-family: ${family};
    }
  `;
}

function applyTemplate(value) {
  const preset = LABEL_TEMPLATES[value];
  if (!preset) return; // "custom" keeps the current values
  Object.assign(label_settings.value, preset);
}

function onLabelSettingChanged() {
  label_settings.value.template = 'custom';
}

function applySavedLabelSettings(saved) {
  if (!saved || typeof saved !== 'object') return;
  ['template', 'show_name', 'show_price', 'show_barcode', 'show_barcode_number', 'barcode_height', 'font_size', 'number_font_size', 'bold_font', 'font_family']
    .forEach((key) => {
      if (saved[key] !== undefined && saved[key] !== null) label_settings.value[key] = saved[key];
    });
  if (saved.auto_print !== undefined && saved.auto_print !== null) auto_print.value = !!saved.auto_print;
  if (saved.custom_sticker_width) custom_sticker_width.value = saved.custom_sticker_width;
  if (saved.custom_sticker_height) custom_sticker_height.value = saved.custom_sticker_height;
  if (saved.paper_size && getPaperSizeOptions().some((o) => o.value === saved.paper_size)) {
    paper_size.value = saved.paper_size;
    Selected_Paper_size(saved.paper_size);
    if (saved.paper_size === 'customstyle') {
      // Selected_Paper_size only re-applies preset dimensions for sticker_*;
      // for customstyle keep the saved ones.
      if (saved.custom_sticker_width) custom_sticker_width.value = saved.custom_sticker_width;
      if (saved.custom_sticker_height) custom_sticker_height.value = saved.custom_sticker_height;
      applyCustomStickerDimensions();
    }
  }
}

function saveLabelSettings() {
  savingLabelSettings.value = true;
  const payload = {
    ...label_settings.value,
    auto_print: auto_print.value,
    paper_size: paper_size.value || null,
    custom_sticker_width: custom_sticker_width.value,
    custom_sticker_height: custom_sticker_height.value,
  };
  http.put('barcode_label_settings', { settings: payload })
    .then(() => {
      makeToast('success', t('Label_Settings_Saved'), t('Success'));
    })
    .catch(() => {
      makeToast('danger', t('Error'), t('Error'));
    })
    .finally(() => {
      savingLabelSettings.value = false;
    });
}

function updateCustomStickerLabel() {
  if (paper_size.value === 'customstyle' || (paper_size.value && paper_size.value.startsWith('sticker_'))) {
    applyCustomStickerDimensions();
    if (canGenerateBarcodes.value) autoGenerateBarcodes(true);
  }
}

function Selected_Paper_size(value) {
  if (value === 'style40') {
    sheets.value = 40; class_sheet.value = 'style40'; class_type_page.value = 'barcodea4';
  } else if (value === 'style30') {
    sheets.value = 30; class_type_page.value = 'barcode_non_a4'; class_sheet.value = 'style30';
  } else if (value === 'style24') {
    sheets.value = 24; class_sheet.value = 'style24'; class_type_page.value = 'barcodea4';
  } else if (value === 'style20') {
    sheets.value = 20; class_sheet.value = 'style20'; class_type_page.value = 'barcode_non_a4';
  } else if (value === 'style18') {
    sheets.value = 18; class_sheet.value = 'style18'; class_type_page.value = 'barcodea4';
  } else if (value === 'style14') {
    sheets.value = 14; class_sheet.value = 'style14'; class_type_page.value = 'barcode_non_a4';
  } else if (value === 'style12') {
    sheets.value = 12; class_sheet.value = 'style12'; class_type_page.value = 'barcodea4';
  } else if (value === 'style10') {
    sheets.value = 10; class_sheet.value = 'style10'; class_type_page.value = 'barcode_non_a4';
  } else if (value === 'customstyle') {
    sheets.value = 1; class_sheet.value = 'customstyle'; class_type_page.value = 'barcode_custom';
    applyCustomStickerDimensions();
  } else if (value && value.startsWith('sticker_')) {
    sheets.value = 1; class_sheet.value = 'customstyle'; class_type_page.value = 'barcode_custom';
    const option = getPaperSizeOptions().find((opt) => opt.value === value);
    if (option && option.width && option.height) {
      custom_sticker_width.value = option.width;
      custom_sticker_height.value = option.height;
      applyCustomStickerDimensions();
    }
  }

  nextTick(() => {
    if (canGenerateBarcodes.value) {
      autoGenerateBarcodes(true); // skip auto-print on paper size change
    } else if (products_added.value.length > 0 && barcode.value.warehouse_id) {
      ShowCard.value = false;
    }
  });
}

function generatePages() {
  const allBarcodes = [];
  products_added.value.forEach((product) => {
    for (let i = 0; i < product.qte; i++) {
      allBarcodes.push({
        name: product.name,
        barcode: product.barcode,
        Type_barcode: product.Type_barcode,
        Net_price: product.Net_price,
      });
    }
  });
  pages.value = [];
  while (allBarcodes.length > 0) {
    pages.value.push(allBarcodes.splice(0, sheets.value));
  }
}

function autoGenerateBarcodes(skipAutoPrint = false) {
  if (isGenerating) return;
  isGenerating = true;
  if (printTimeout) { clearTimeout(printTimeout); printTimeout = null; }
  nextTick(() => {
    if (canGenerateBarcodes.value) {
      generatePages();
      ShowCard.value = true;
      // Never auto-fire the direct printer: physical labels would print on
      // every quantity tweak. Direct printing is click-to-print only.
      if (auto_print.value && pages.value.length > 0 && !skipAutoPrint && !labelPrinterEnabled.value) {
        printTimeout = setTimeout(() => { print_all_Barcode(); }, 1500);
      }
    } else {
      ShowCard.value = false;
    }
    isGenerating = false;
  });
}

function delete_Product(code) {
  for (let i = 0; i < products_added.value.length; i++) {
    if (code === products_added.value[i].code) {
      products_added.value.splice(i, 1);
      if (canGenerateBarcodes.value) autoGenerateBarcodes();
      else ShowCard.value = false;
      break;
    }
  }
}

function search() {
  if (timer) { clearTimeout(timer); timer = null; }
  if (search_input.value.length < 2) {
    product_filter.value = [];
    return;
  }
  if (barcode.value.warehouse_id !== '' && barcode.value.warehouse_id != null) {
    timer = setTimeout(() => {
      const exact = products.value.filter(
        (product) => product.code === search_input.value || product.barcode.includes(search_input.value),
      );
      if (exact.length === 1) {
        SearchProduct(exact[0]);
      } else {
        const tokens = search_input.value.toLowerCase().split(' ');
        product_filter.value = products.value.filter((product) => tokens.every(
          (token) => product.name.toLowerCase().includes(token)
            || product.code.toLowerCase().includes(token)
            || product.barcode.toLowerCase().includes(token)
            || (product.note && product.note.toLowerCase().includes(token)),
        ));
        if (product_filter.value.length <= 0) {
          makeToast('warning', 'Product Not Found', 'Warning');
        }
      }
    }, 800);
  } else {
    makeToast('warning', t('SelectWarehouse'), t('Warning'));
  }
}

function getResultValue(result) {
  return result.code + ' (' + result.name + ')';
}

function SearchProduct(result) {
  const existingProduct = products_added.value.find((product) => product.code === result.code);
  if (existingProduct) {
    makeToast('warning', t('AlreadyAdd'), t('Warning'));
  } else {
    products_added.value.push({
      code: result.code,
      barcode: result.barcode,
      name: result.name,
      Type_barcode: result.Type_barcode,
      Net_price: result.Net_price,
      qte: 1,
    });
  }
  search_input.value = '';
  product_filter.value = [];
}

function Get_Products_By_Warehouse(id) {
  startProgress();
  http.get('get_Products_by_warehouse/' + id + '?stock=0&product_service=1&product_combo=1')
    .then((data) => {
      products.value = data;
      doneProgress();
    })
    .catch(() => { doneProgress(); });
}

function Selected_Warehouse(value) {
  search_input.value = '';
  product_filter.value = [];
  Get_Products_By_Warehouse(value);
}

function onScan(decodedText) {
  search_input.value = decodedText;
  search();
  scanOpen.value = false;
}

// Sends raw TSPL to the configured label printer — no browser print dialog.
// The printer renders the barcode natively, so the paper-size/pages state of
// the on-screen preview is irrelevant here.
function directPrintLabels() {
  if (printingDirect.value) return;
  if (products_added.value.length === 0) {
    makeToast('warning', t('NodataAvailable'), t('Warning'));
    return;
  }
  printingDirect.value = true;
  const labels = products_added.value.map((p) => ({
    name: p.name,
    barcode: p.barcode,
    Type_barcode: p.Type_barcode,
    Net_price: p.Net_price,
    qte: p.qte,
  }));
  const design = {
    ...label_settings.value,
    width_mm: labelPrinterSize.value.width || custom_sticker_width.value || 50,
    height_mm: labelPrinterSize.value.height || custom_sticker_height.value || 25,
    printer_dpi: labelPrinterDpi.value,
    offset_x_mm: labelPrinterOffsets.value.x,
    offset_y_mm: labelPrinterOffsets.value.y,
    // Symbol for display; ASCII code as fallback where the symbol can't be
    // drawn (printer bitmap fonts) or isn't configured.
    currency: auth.currency || currencyCode.value,
    currency_ascii: currencyCode.value,
  };
  // In raster mode the browser draws each label at 203 dpi and the printer
  // just stamps the image, so the print matches the preview exactly.
  const payload = labelRenderMode.value === 'raster'
    ? { rasters: labels.map((label) => renderLabelRaster(label, design)), design }
    : { labels, design };

  http.post('print_labels_direct', payload)
    .then(async (res) => {
      // QZ Tray connection: the server returned the raw TSPL payload and the
      // browser hands it to the printer on THIS computer.
      if (res && res.qz) {
        await qzPrintRaw(res.printer, res.payload_base64);
      }
      makeToast('success', (res && res.message) || t('Sent_to_label_printer'), t('Success'));
    })
    .catch((e) => {
      const msg = isQzConnectError(e) ? t('QZ_Not_Running')
        : (e?.data?.message || e?.message || t('Label_print_failed'));
      makeToast('danger', msg, t('Error'));
    })
    .finally(() => {
      printingDirect.value = false;
    });
}

function print_all_Barcode() {
  if (labelPrinterEnabled.value) {
    directPrintLabels();
    return;
  }
  // Legacy behavior: generate on demand if nothing is rendered yet.
  if (!ShowCard.value || pages.value.length === 0) {
    if (canGenerateBarcodes.value) {
      generatePages();
      ShowCard.value = true;
      nextTick(() => print_all_Barcode());
    } else {
      makeToast('warning', t('Paper_size') || 'Please select a paper size', t('Warning'));
    }
    return;
  }

  const divContents = document.getElementById('print_barcode_label').innerHTML;
  // The injected style tags (custom sticker dimensions + label design) live in
  // this document's head — copy them into the popup or it prints defaults.
  const dynamicStyles = ['custom-sticker-dimensions', LABEL_STYLE_ID]
    .map((id) => document.getElementById(id)?.outerHTML || '')
    .join('');
  const a = window.open('', '', 'height=500, width=500');
  if (!a) {
    // Popup blocked (likely: auto-print fires outside a user gesture).
    makeToast('warning', t('Popup_blocked_allow_printing'), t('Warning'));
    return;
  }
  a.document.write('<html><head><link rel="stylesheet" href="/assets_setup/css/print_label.css">' + dynamicStyles + '</head>');
  a.document.write('<body >');
  a.document.write(divContents);
  a.document.write('</body></html>');
  a.document.close();
  setTimeout(() => { a.print(); }, 1000);
}

function loadPurchaseBarcodes(purchaseId) {
  startProgress();
  http.get('purchases/' + purchaseId + '/barcodes')
    .then((data) => {
      data = data || {};
      if (data.warehouse_id) barcode.value.warehouse_id = data.warehouse_id;
      const items = data.products || [];
      products_added.value = items.map((p) => ({
        code: p.code,
        barcode: p.barcode,
        name: p.name,
        Type_barcode: p.Type_barcode,
        Net_price: p.Net_price,
        qte: p.qte,
      }));
      if (!paper_size.value) {
        paper_size.value = 'style40';
        Selected_Paper_size('style40');
      } else if (paper_size.value === 'customstyle' || paper_size.value.startsWith('sticker_')) {
        if (paper_size.value.startsWith('sticker_')) {
          const option = getPaperSizeOptions().find((opt) => opt.value === paper_size.value);
          if (option && option.width && option.height) {
            custom_sticker_width.value = option.width;
            custom_sticker_height.value = option.height;
          }
        }
        applyCustomStickerDimensions();
      }
      if (canGenerateBarcodes.value) autoGenerateBarcodes();
      setTimeout(() => doneProgress(), 500);
    })
    .catch(() => { setTimeout(() => doneProgress(), 500); });
}

function reset() {
  ShowCard.value = false;
  products.value = [];
  products_added.value = [];
  barcode.value.qte = 10;
  barcode.value.warehouse_id = undefined;
  paper_size.value = undefined;
  sheets.value = '';
  search_input.value = '';
  product_filter.value = [];
  pages.value = [];
  custom_sticker_width.value = 50;
  custom_sticker_height.value = 25;
  warehouseTouched.value = false;
  if (printTimeout) { clearTimeout(printTimeout); printTimeout = null; }
  const styleElement = document.getElementById('custom-sticker-dimensions');
  if (styleElement) styleElement.remove();
}

// Legacy watchers.
watch(products_added, () => {
  if (canGenerateBarcodes.value) autoGenerateBarcodes();
}, { deep: true });
watch(paper_size, (newVal, oldVal) => {
  if (newVal !== oldVal && newVal) {
    nextTick(() => {
      if (canGenerateBarcodes.value) autoGenerateBarcodes(true);
      else if (products_added.value.length > 0 && barcode.value.warehouse_id) ShowCard.value = false;
    });
  }
});
// Label design changes only restyle the already-rendered labels — the
// preview updates reactively, no regeneration (or auto-print) needed.
watch(label_settings, applyLabelSettingsStyle, { deep: true });

const PREVIEW_CSS_ID = 'barcode-print-label-css';
onMounted(async () => {
  // Preview uses the same stylesheet as the print popup (legacy got these
  // rules from its global app skin).
  if (!document.getElementById(PREVIEW_CSS_ID)) {
    const link = document.createElement('link');
    link.id = PREVIEW_CSS_ID;
    link.rel = 'stylesheet';
    link.href = '/assets_setup/css/print_label.css';
    document.head.appendChild(link);
  }

  applyLabelSettingsStyle();

  try {
    const data = await http.get('barcode_create_page');
    warehouses.value = data.warehouses || [];
    labelPrinterEnabled.value = !!data.label_printer_enabled;
    labelRenderMode.value = data.label_printer_render_mode === 'raster' ? 'raster' : 'native';
    labelPrinterSize.value = {
      width: Number(data.label_printer_width_mm) || null,
      height: Number(data.label_printer_height_mm) || null,
    };
    labelPrinterDpi.value = Number(data.label_printer_dpi) === 300 ? 300 : 203;
    labelPrinterOffsets.value = {
      x: Number(data.label_printer_offset_x_mm) || 0,
      y: Number(data.label_printer_offset_y_mm) || 0,
    };
    currencyCode.value = data.currency_code || '';
    applySavedLabelSettings(data.barcode_label_settings);
    // Direct label printing uses the printer's physical sticker size from
    // Settings → Direct Network Printing, never the paper choice on this
    // page — force the preview to that size so what is shown matches what
    // prints. (The paper presets remain relevant only for browser printing,
    // i.e. when the direct label printer is disabled.)
    if (labelPrinterEnabled.value && labelPrinterSize.value.width && labelPrinterSize.value.height) {
      paper_size.value = 'customstyle';
      Selected_Paper_size('customstyle');
      custom_sticker_width.value = labelPrinterSize.value.width;
      custom_sticker_height.value = labelPrinterSize.value.height;
      applyCustomStickerDimensions();
    }
    isLoading.value = false;
  } catch (e) {
    setTimeout(() => { isLoading.value = false; }, 500);
  }

  const purchaseId = route.query ? route.query.purchase_id : null;
  if (purchaseId) loadPurchaseBarcodes(purchaseId);
});

onBeforeUnmount(() => {
  if (printTimeout) clearTimeout(printTimeout);
  if (timer) clearTimeout(timer);
  document.getElementById(PREVIEW_CSS_ID)?.remove();
  document.getElementById('custom-sticker-dimensions')?.remove();
  document.getElementById(LABEL_STYLE_ID)?.remove();
});
</script>

<style scoped>
.barcode-row { background: #ffffff; padding: 1.5rem; border-radius: 6px; border: 1px solid #e5e7eb; overflow-x: auto; }

.preview-sticky { position: sticky; top: 16px; }

/* ---- Template picker: schematic cards ---- */
.tpl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
  gap: 10px;
}
.tpl-card {
  border: 1px solid rgba(128, 128, 128, 0.3);
  border-radius: 8px;
  padding: 8px;
  cursor: pointer;
  text-align: center;
  transition: border-color 0.2s, box-shadow 0.2s;
  outline: none;
}
.tpl-card:hover,
.tpl-card:focus-visible { border-color: #6d28d9; }
.tpl-card.active {
  border-color: #6d28d9;
  box-shadow: 0 0 0 2px rgba(109, 40, 217, 0.18);
}
/* The thumb is a miniature of the printed sticker, so it stays paper-white
   in both themes. */
.tpl-thumb {
  position: relative;
  height: 64px;
  background: #fff;
  border: 1px solid rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 6px 8px;
  margin-bottom: 6px;
}
.tpl-thumb.compact { gap: 2px; }
.tpl-line { display: block; border-radius: 2px; }
.tpl-name { width: 72%; height: 5px; background: #475569; }
.tpl-name.short { width: 46%; }
.tpl-name.big { height: 7px; }
.tpl-thumb.compact .tpl-name { height: 3px; }
.tpl-bars {
  display: block;
  width: 76%;
  height: 16px;
  background: repeating-linear-gradient(90deg, #111 0 2px, transparent 2px 4px);
}
.tpl-bars.tall { height: 32px; }
.tpl-thumb.compact .tpl-bars { height: 9px; }
.tpl-number { width: 40%; height: 3px; background: #94a3b8; }
.tpl-price {
  position: absolute;
  right: 6px;
  bottom: 5px;
  width: 22px;
  height: 6px;
  border-radius: 2px;
  background: #6d28d9;
  opacity: 0.85;
}
.tpl-custom-icon { font-size: 22px; color: #94a3b8; }
.tpl-label {
  font-size: 11px;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* ---- Live preview: sticker on a cutting-mat stage ---- */
.label-stage {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 220px;
  padding: 24px 16px;
  border-radius: 8px;
  overflow: auto;
  background-color: rgba(128, 128, 128, 0.06);
  background-image: radial-gradient(rgba(128, 128, 128, 0.3) 1px, transparent 1px);
  background-size: 14px 14px;
}
/* Neutralize the print "page" chrome so only the label itself shows. For
   sheet layouts the page is A4-sized — collapse it and let the fixed-size
   item stand alone; for sticker layouts the page IS the sticker. */
.label-sample .barcodea4,
.label-sample .barcode_non_a4 {
  width: auto;
  height: auto;
  margin: 0;
  padding: 0;
  border: none;
}
.label-sample .barcode_custom {
  margin: 0;
  border: none;
}
.label-sample .barcode-item {
  float: none !important;
  margin: 0 !important;
  background: #fff;
  color: #000;
  border: none !important;
  border-radius: 2px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2), 0 8px 20px rgba(0, 0, 0, 0.14);
}
.stage-caption {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 12px;
}
.stage-note { font-size: 12px; opacity: 0.65; }
</style>
