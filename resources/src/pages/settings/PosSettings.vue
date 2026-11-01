<template>
  <div class="page">
    <PageHeader :title="$t('Pos_Settings')" :breadcrumb="[$t('Settings'), $t('Pos_Settings')]" />

    <!-- Everything POS in one place: General (this page's settings + the
         company-level POS rules), Receipt (POS Receipt editor) and Direct
         Network Printing. ?tab=general|receipt|printing opens a tab. -->
    <a-tabs v-model:activeKey="tab" class="pos-settings-tabs" @change="k => $router.replace({ query: { ...$route.query, tab: k } })">
      <a-tab-pane key="general" :tab="$t('General') || 'General'" />
      <a-tab-pane key="receipt" :tab="$t('POS_Receipt') || 'Receipt'" />
      <a-tab-pane key="printing" tab="Network printing" />
    </a-tabs>

    <PosReceipt v-if="tab === 'receipt'" embedded />
    <DirectNetworkPrinting v-else-if="tab === 'printing'" embedded />

    <template v-else>
    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <!-- Company-level POS rules (settings row, saved with this page). -->
      <a-card size="small" title="Company rules" style="margin-bottom: 16px">
        <!-- Price decimals moved to System Settings -> Defaults -> General (company-wide). -->
        <div class="pos-rule">
          <div>
            <div class="pos-rule__label">Require an open register</div>
            <div class="pos-rule__help">The POS stays locked until the cashier opens a cash register (opening cash + note), and sales can't be submitted without one. Sales rung up offline are still accepted when they sync.</div>
          </div>
          <a-switch v-model:checked="requireOpenRegister" />
        </div>
        <!-- 2026-11-01: who may open / close the register (besides roles with the
             "Open / close register" permission); the POS message names them. -->
        <div class="pos-rule pos-rule--block">
          <div class="pos-rule__label">Who can open / close the register</div>
          <div class="pos-rule__help">Roles and users allowed to open and close the cash register. Roles that already have the "Open / close register" permission (Roles) can too. Everyone else sells with their PIN on the open register.</div>
          <a-row :gutter="12" style="margin-top: 8px">
            <a-col :xs="24" :md="12">
              <a-select v-model:value="openerRoles" mode="multiple" :options="roleOptions" placeholder="Roles" option-filter-prop="label" style="width: 100%" />
            </a-col>
            <a-col :xs="24" :md="12">
              <a-select v-model:value="openerUsers" mode="multiple" :options="userOptions" placeholder="Users" option-filter-prop="label" style="width: 100%" />
            </a-col>
          </a-row>
        </div>
        <div class="pos-rule">
          <div>
            <div class="pos-rule__label">Open register with PIN</div>
            <div class="pos-rule__help">Opening a register shows a PIN keypad: the cashier types their personal PIN and the POS switches to that user (whoever was signed in), so the register and its sales are recorded under the person at the till. Closing is confirmed with the PIN too. PINs are unique per company and set on each user (Users → Edit → Cashier PIN).</div>
          </div>
          <a-switch v-model:checked="registerPinRequired" />
        </div>
        <div class="pos-rule">
          <div>
            <div class="pos-rule__label">Confirm each sale with the cashier's PIN</div>
            <div class="pos-rule__help">At payment, the person making the sale types their PIN and the sale is recorded under their user. The POS stays signed in as whoever opened the register, so another employee can ring up a sale without signing in or closing anything. The register's close-out (screen and email) includes every user's sales, with a breakdown by user. Works with or without "Open register with PIN".</div>
          </div>
          <a-switch v-model:checked="salePinRequired" />
        </div>
        <div class="pos-rule">
          <div>
            <div class="pos-rule__label">Email the admins when a register is opened or closed</div>
            <div class="pos-rule__help">Every admin user of the company gets an email when a cashier opens a register (who, where, when, opening cash) and when it is closed (time opened / closed, sales, payments per method — Cash, Zelle…, services vs products, expected vs counted cash). The SuperAdmin gets a copy only if enabled in Mail Settings.</div>
          </div>
          <a-switch v-model:checked="closeRegisterEmail" />
        </div>
        <div class="pos-rule">
          <div>
            <div class="pos-rule__label">Integrate POS with Booking</div>
            <div class="pos-rule__help">Every POS sale also records a completed booking (customer, date and time, services, staff, location and total), linked to the sale's invoice, so it shows in the Bookings list and calendar. The company admins get the booking report by email. The sale is never invoiced twice.</div>
          </div>
          <a-switch v-model:checked="bookingIntegration" />
        </div>
      </a-card>

      <!-- POS top toolbar: which filters/selectors the cashier sees. -->
      <a-card size="small" title="Toolbar filters" style="margin-bottom: 16px">
        <div v-for="f in TOOLBAR_FLAGS" :key="f.key" class="pos-rule">
          <div>
            <div class="pos-rule__label">{{ f.label }}</div>
            <div class="pos-rule__help">{{ f.help }}</div>
          </div>
          <a-switch v-model:checked="s[f.key]" />
        </div>
      </a-card>

      <a-card size="small" :title="$t('Receipt') || 'Receipt'" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item label="Receipt paper size">
              <a-select
                v-model:value="s.receipt_paper_size"
                :options="['80mm', '58mm', 'A4'].map(v => ({ value: v, label: v }))"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Receipt layout"
              extra="Preview each layout live in the Receipt tab."
            >
              <a-select
                v-model:value="s.receipt_layout"
                :options="[
                  { value: 1, label: $t('Layout_1_Standard') },
                  { value: 2, label: $t('Layout_2_Compact') },
                  { value: 3, label: $t('Layout_3_Detailed') },
                  { value: 4, label: $t('Layout_4_Bilingual') },
                  { value: 5, label: $t('Layout_5_Minimal') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Logo size (px)">
              <a-input-number v-model:value="s.logo_size" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-alert
              type="info" show-icon
              message="The receipt content — note and show/hide toggles (logo, reference, taxes, payments…) — is configured in the Receipt tab, with a live preview."
            />
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" title="POS" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item label="Products & services per page">
              <a-input-number v-model:value="s.products_per_page" style="width: 100%" :min="1" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Invoice format"
              extra="Format of the invoice printed after a sale — thermal receipt or full A4 page. Colors and layout of the A4 PDF are configured in System Settings → Invoice PDF."
            >
              <a-select
                v-model:value="invoiceFormat"
                :options="[
                  { value: 'thermal', label: 'Thermal receipt' },
                  { value: 'a4', label: 'A4 page' },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-row :gutter="[8, 8]">
              <a-col v-for="f in visiblePosFlags" :key="f.key" :xs="12" :md="8">
                <a-checkbox v-model:checked="s[f.key]">{{ f.label }}</a-checkbox>
              </a-col>
            </a-row>
          </a-col>
        </a-row>
      </a-card>

      <!-- Cash drawer auto-open (QZ Tray + ESC/POS) — same section as legacy pos_settings.vue -->
      <a-card size="small" :title="$t('Cash_Drawer_Settings')" style="margin-bottom: 16px">
        <a-alert type="info" show-icon :message="$t('Cash_Drawer_Auto_Open_Help')" style="margin-bottom: 16px" />
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Cash_Drawer_Auto_Open')">
              <a-switch v-model:checked="s.cash_drawer_auto_open" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Cash_Drawer_Printer_Name')" :extra="$t('Cash_Drawer_Printer_Name_Help')">
              <a-input
                v-model:value="s.cash_drawer_printer_name"
                :placeholder="$t('Leave_blank_for_default_receipt_printer')"
                :maxlength="192"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <!-- Keyboard shortcuts: per-device (localStorage), saved on toggle — not
           part of the pos_settings payload, so no Save click is needed. -->
      <a-card size="small" :title="$t('POS_Keyboard_Shortcuts')" style="margin-bottom: 16px">
        <a-space direction="vertical" size="small">
          <a-space>
            <a-switch v-model:checked="keyboardShortcuts" @change="onToggleKeyboardShortcuts" />
            <span>{{ $t('Enable_Keyboard_Shortcuts') }}</span>
          </a-space>
          <a-typography-text type="secondary">
            {{ $t('Enable_Keyboard_Shortcuts_Help') }}
            <a @click.prevent="shortcutsGuideOpen = true">{{ $t('View_Shortcuts') }}</a>
          </a-typography-text>
        </a-space>
      </a-card>

      <a-button type="primary" size="large" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
    </a-form>
    </template>

    <!-- POS Keyboard Shortcuts Guide (read-only reference) -->
    <a-modal
      v-model:open="shortcutsGuideOpen"
      :title="$t('POS_Keyboard_Shortcuts')"
      :footer="null"
      :width="560"
    >
      <a-typography-paragraph type="secondary">{{ $t('Shortcuts_Guide_Intro') }}</a-typography-paragraph>
      <a-table
        :columns="shortcutColumns"
        :data-source="shortcutRows"
        row-key="id"
        size="small"
        :pagination="false"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'keys'">
            <a-tag>{{ record.keys }}</a-tag>
          </template>
        </template>
      </a-table>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET get_pos_Settings_api → {pos_settings}; PUT pos_settings/{id} with the
 * POS flags only (cash_drawer_auto_open goes as 1/0, the rest as booleans).
 * invoice_format is sent as a separate top-level field like legacy.
 * Receipt content (note + show/hide toggles) is owned by the POS Receipt page
 * and deliberately NOT sent from here, so the two pages can't overwrite each
 * other with stale values. allow_overselling lives in System Settings >
 * Features now.
 */
import { ref, computed, onMounted, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';

// Receipt / Network printing tabs: the existing pages, embedded (loaded on demand).
const PosReceipt = defineAsyncComponent(() => import('./PosReceipt.vue'));
const DirectNetworkPrinting = defineAsyncComponent(() => import('./DirectNetworkPrinting.vue'));
const TABS = ['general', 'receipt', 'printing'];
const route = useRoute();
const tab = ref(TABS.includes(String(route.query.tab)) ? String(route.query.tab) : 'general');

// Company-level POS rules (settings row), loaded/saved with this page.
const requireOpenRegister = ref(true);
const registerPinRequired = ref(false);
const closeRegisterEmail = ref(true);
const bookingIntegration = ref(false);
// Each POS sale confirmed with the PIN of who makes it (recorded under that user).
const salePinRequired = ref(false);
const openerRoles = ref([]);
const openerUsers = ref([]);
const roleOptions = ref([]);
const userOptions = ref([]);
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import {
  POS_SHORTCUTS,
  posShortcutsEnabled,
  setPosShortcutsEnabled,
} from '../../pos-compat/posKeyboardShortcuts';

const { t } = useI18n();
const auth = useAuthStore();

const POS_FLAGS = [
  // The blue POS button in the admin top header (off: reach the POS from the sidebar / account menu).
  { key: 'show_pos_button', label: 'Show POS button in header' },
  { key: 'barcode_scanning_sound', label: 'Barcode scanning sound' },
  { key: 'show_product_images', label: 'Show product images' },
  { key: 'show_stock_quantity', label: 'Show stock quantity' },
  { key: 'enable_hold_sales', label: 'Enable hold sales' },
  { key: 'enable_customer_points', label: 'Enable customer points' },
  { key: 'show_customer_history', label: 'Show customer purchase history' },
  // allow_overselling moved to System Settings > Features (global switch).
  // cash_drawer_auto_open has its own card below (legacy "Cash Drawer" section).
];

// POS top toolbar (own card): hide what the cashier doesn't use.
const TOOLBAR_FLAGS = [
  { key: 'show_categories', label: 'Category filter ("All Categories")', help: 'Filter the products & services grid by category.' },
  { key: 'show_brands', label: 'Brand filter ("All Brands")', help: 'Filter the products & services grid by brand.' },
  { key: 'show_customer_filter', label: 'Customer selector', help: 'Pick the customer for the sale. Hidden: every sale goes to the default customer (e.g. Walk-in Customer).' },
  { key: 'quick_add_customer', label: 'Quick add customer', help: 'The + button next to the customer selector to create a new customer on the spot.' },
];

const ALL_FLAGS = [...POS_FLAGS.map(f => f.key), ...TOOLBAR_FLAGS.map(f => f.key), 'cash_drawer_auto_open'];

// Loyalty off (System Settings → Defaults → Customers) hides the customer
// points checkbox; its stored value still round-trips via ALL_FLAGS.
const visiblePosFlags = computed(() => POS_FLAGS.filter(f =>
  f.key !== 'enable_customer_points' || auth.customerLoyaltyEnabled));

const loading = ref(true);
const saving = ref(false);
const s = ref({});
const invoiceFormat = ref('');

// Per-device keyboard-shortcuts preference (localStorage, no backend field).
const keyboardShortcuts = ref(posShortcutsEnabled());
const shortcutsGuideOpen = ref(false);

const shortcutColumns = computed(() => [
  { title: t('Shortcut'), key: 'keys', dataIndex: 'keys', width: '45%' },
  { title: t('Action'), dataIndex: 'description' },
]);

// vue-i18n returns the key itself when a translation is missing, so fall back
// to the English label shipped with the shortcut definition.
const shortcutRows = computed(() =>
  POS_SHORTCUTS.map(sc => ({
    id: sc.id,
    keys: sc.keys,
    description: t(sc.descriptionKey) === sc.descriptionKey
      ? sc.descriptionFallback
      : t(sc.descriptionKey),
  })),
);

function onToggleKeyboardShortcuts(checked) {
  setPosShortcutsEnabled(checked);
  message.success(t('Successfully_Updated'));
}

async function save() {
  saving.value = true;
  const v = s.value;
  const body = {
    logo_size: v.logo_size,
    receipt_paper_size: v.receipt_paper_size,
    receipt_layout: v.receipt_layout,
    products_per_page: v.products_per_page,
    cash_drawer_printer_name: v.cash_drawer_printer_name || null,
    invoice_format: invoiceFormat.value,
    pos_require_open_register: requireOpenRegister.value ? 1 : 0,
    pos_register_pin_required: registerPinRequired.value ? 1 : 0,
    pos_close_register_email: closeRegisterEmail.value ? 1 : 0,
    pos_booking_integration: bookingIntegration.value ? 1 : 0,
    pos_sale_pin_required: salePinRequired.value ? 1 : 0,
    pos_register_openers: { roles: openerRoles.value, users: openerUsers.value },
  };
  ALL_FLAGS.forEach(k => {
    body[k] = k === 'cash_drawer_auto_open' ? (v[k] ? 1 : 0) : v[k];
  });
  try {
    await http.put(`pos_settings/${v.id}`, body);
    // The topbar's POS button follows this setting right away (no reload needed).
    if (auth.user) auth.user.show_pos_button = !!v.show_pos_button;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_pos_Settings_api');
    const ps = data.pos_settings || {};
    ALL_FLAGS.forEach(k => { ps[k] = !!Number(ps[k] ?? 0) || ps[k] === true; });
    s.value = ps;
    // Global invoice format comes as a top-level field on the response
    // (it lives on the `settings` row, not `pos_settings`).
    invoiceFormat.value = data.invoice_format || ps.invoice_format || 'thermal';
    requireOpenRegister.value = data.pos_require_open_register !== false;
    registerPinRequired.value = data.pos_register_pin_required === true;
    closeRegisterEmail.value = data.pos_close_register_email !== false;
    bookingIntegration.value = data.pos_booking_integration === true;
    salePinRequired.value = data.pos_sale_pin_required === true;
    openerRoles.value = data.pos_register_openers?.roles || [];
    openerUsers.value = data.pos_register_openers?.users || [];
    roleOptions.value = data.register_role_options || [];
    userOptions.value = data.register_user_options || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.pos-settings-tabs {
  margin-bottom: 8px;
}
.pos-rule {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
}
.pos-rule--block { display: block; }
.pos-rule:last-child {
  border-bottom: 0;
}
.pos-rule__label {
  font-weight: 600;
}
.pos-rule__help {
  font-size: 12px;
  opacity: 0.65;
  max-width: 640px;
}
</style>
