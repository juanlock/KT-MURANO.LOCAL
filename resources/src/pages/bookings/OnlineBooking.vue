<template>
  <div class="page">
    <PageHeader
      :title="tf('Online_Booking', 'Online Booking')"
      :breadcrumb="[$t('Booking Management'), tf('Online_Booking', 'Online Booking')]"
    >
      <template v-if="publicBooking" #extra>
        <a-button type="primary" :href="publicBooking.url" target="_blank">{{ tf('Open_Public_Page', 'Open public page') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="16">
      <a-col :xs="24" :xl="10">
        <OnlineBookingCard :public-booking="publicBooking" />

        <a-card v-if="publicBooking" size="small" :title="tf('Booking_Page_Look', 'Look of the booking page')" style="margin-top: 16px">
          <a-form layout="vertical">
            <a-form-item :label="tf('Booking_Show_Logo', 'Show logo')" :help="tf('Booking_Show_Logo_Help', 'Off = no logo on the booking page or the embedded form.')">
              <a-switch v-model:checked="look.show_logo" />
            </a-form-item>
            <a-form-item :label="tf('Background_Color', 'Background color')" :help="tf('Booking_Bg_Help', 'Empty = default (light gray; transparent when embedded, so the website\'s own background shows).')">
              <div class="pb-color">
                <input :value="swatch" type="color" class="pb-swatch" @input="e => (look.bg_color = e.target.value)" />
                <a-input v-model:value="look.bg_color" placeholder="#ffffff" style="width: 120px" allow-clear />
                <a-button v-if="look.bg_color" size="small" @click="look.bg_color = ''">{{ tf('Default', 'Default') }}</a-button>
              </div>
            </a-form-item>
            <a-button type="primary" :loading="savingLook" :disabled="!lookChanged" @click="saveLook">{{ $t('Save') }}</a-button>
          </a-form>
        </a-card>

        <!-- Websites that use the form + their ready-made codes ("Exit" / "Close" go back there). -->
        <a-card v-if="publicBooking" size="small" :title="tf('Booking_Websites', 'Websites & codes')" style="margin-top: 16px">
          <a-form layout="vertical">
            <a-form-item :help="tf('Booking_Websites_Help', 'The websites where you put the booking form, one per line (e.g. https://www.mysalon.com). &quot;Exit&quot; and &quot;Close&quot; take the customer back to the website the code is on.')">
              <a-textarea v-model:value="sitesText" :auto-size="{ minRows: 2, maxRows: 6 }" placeholder="https://www.mysalon.com" />
            </a-form-item>
            <a-button type="primary" :loading="savingSites" :disabled="!sitesChanged" @click="saveSites">{{ $t('Save') }}</a-button>
          </a-form>

          <a-divider style="margin: 14px 0" />
          <a-alert type="info" show-icon style="margin-bottom: 12px">
            <template #message>
              Codes use the address <b>{{ publicBooking.base_url }}</b>
              ({{ publicBooking.base_is_company ? 'this company\'s public address' : 'the server address' }}).
            </template>
            <template #description>
              To change it: System Settings → General → Server address (SuperAdmin, whole install), or
              Defaults → General → Public address (this company only). Codes already on websites keep the address they were made with.
            </template>
          </a-alert>
          <a-form layout="vertical">
            <a-form-item :label="tf('Code_For_Website', 'Codes for')">
              <a-select v-model:value="codeSite" :options="siteOptions" style="width: 100%" />
            </a-form-item>
            <a-form-item v-for="c in codes" :key="c.key" :label="c.label" :help="c.help">
              <a-textarea :value="c.code" readonly :auto-size="{ minRows: c.rows, maxRows: 8 }" class="pb-code" />
              <a-space style="margin-top: 6px">
                <a-button size="small" type="primary" @click="copy(c.code)">{{ tf('Copy_Code', 'Copy code') }}</a-button>
                <a-button v-if="c.key === 'link'" size="small" :href="c.code" target="_blank">{{ tf('Open', 'Open') }}</a-button>
              </a-space>
            </a-form-item>
          </a-form>
        </a-card>
      </a-col>
      <a-col v-if="publicBooking" :xs="24" :xl="14">
        <a-card size="small" :title="tf('Preview', 'Preview')">
          <template #extra>
            <a-button size="small" @click="previewKey++">{{ tf('Reload', 'Reload') }}</a-button>
          </template>
          <!-- Exactly what the embed code shows on an external website. -->
          <iframe
            :key="previewKey" :src="publicBooking.embed_url" title="Online booking preview"
            class="pb-preview"
          />
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Booking Management -> Online Booking: the acting company's public "Book an
 * appointment" page (/book?company=ID) -- link, iframe embed code for external
 * websites, and a live preview of the embedded form. Bookings made there arrive
 * in the Booking List as Pending.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import OnlineBookingCard from '../../components/OnlineBookingCard.vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';

const loading = ref(true);
const publicBooking = ref(null);
const previewKey = ref(0);

// Look of the public page (logo on/off, background color); saved per company.
const look = reactive({ show_logo: true, bg_color: '' });
const savingLook = ref(false);
const HEX = /^#[0-9a-fA-F]{6}$/;
const swatch = computed(() => (HEX.test(look.bg_color || '') ? look.bg_color : '#ffffff'));
const lookChanged = computed(() => {
  const l = publicBooking.value?.look || {};
  return look.show_logo === !!l.hide_logo || (look.bg_color || '') !== (l.bg_color || '');
});
function applyLook(pb) {
  publicBooking.value = pb;
  look.show_logo = !pb?.look?.hide_logo;
  look.bg_color = pb?.look?.bg_color || '';
  sitesText.value = (pb?.websites || []).join('\n');
  if (!(pb?.websites || []).includes(codeSite.value)) codeSite.value = pb?.websites?.[0] || '';
}

// ---- websites + codes (iframe / link / button), each with ?return= that website ----
const sitesText = ref('');
const savingSites = ref(false);
const codeSite = ref('');
const sitesList = () => sitesText.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
const sitesChanged = computed(() => sitesList().join('\n') !== (publicBooking.value?.websites || []).join('\n'));
const siteOptions = computed(() => [
  ...sortByLabel((publicBooking.value?.websites || []).map(u => ({ value: u, label: u }))),
  { value: '', label: tf('No_Website', '(no website — Exit goes to the company website)') },
]);
async function saveSites() {
  savingSites.value = true;
  try {
    const data = await http.put('bookings_public_websites', { websites: sitesList() });
    applyLook(data.public_booking);
    message.success(tf('Saved', 'Saved'));
  } catch (e) {
    const errs = e?.data?.errors ? Object.values(e.data.errors).flat().join(' ') : '';
    message.error(errs || e?.data?.message || tf('InvalidData', 'Invalid data'));
  } finally {
    savingSites.value = false;
  }
}
const withReturn = url => (codeSite.value ? `${url}${url.includes('?') ? '&' : '?'}return=${encodeURIComponent(codeSite.value)}` : url);
const codes = computed(() => {
  const pb = publicBooking.value;
  if (!pb) return [];
  const id = `murano-booking-${pb.company_id}`;
  const embed = withReturn(pb.embed_url);
  const link = withReturn(pb.url);
  return [
    {
      key: 'iframe', rows: 4, label: tf('Iframe_Code', 'Embedded form (iframe)'),
      help: tf('Iframe_Code_Help', 'The form inside your page; it grows with its content.'),
      code: `<iframe id="${id}" src="${embed}" title="Book an appointment" style="width:100%;min-height:720px;border:0;" loading="lazy"></iframe>\n`
        + `<script>window.addEventListener('message',function(e){if(e.data&&e.data.type==='murano-booking-height'){var f=document.getElementById('${id}');`
        + `if(f&&e.source===f.contentWindow)f.style.height=(e.data.height+20)+'px';}});<\/script>`,
    },
    {
      key: 'link', rows: 1, label: tf('Link_Url', 'Link (URL)'),
      help: tf('Link_Url_Help', 'For a menu item, a social profile or a message: opens the booking page.'),
      code: link,
    },
    {
      key: 'button', rows: 3, label: tf('Button_Code', 'Button'),
      help: tf('Button_Code_Help', 'A "Book an appointment" button that opens the booking page.'),
      code: `<a href="${link}" target="_blank" rel="noopener" style="display:inline-block;padding:12px 26px;background:#b8860b;color:#fff;`
        + `border-radius:8px;font:600 16px/1.2 system-ui,Arial,sans-serif;text-decoration:none;">Book an appointment</a>`,
    },
  ];
});
async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    message.success(tf('Copied', 'Copied'));
  } catch (e) {
    message.error(tf('Copy_Failed', 'Could not copy — select the text and copy it manually.'));
  }
}
async function saveLook() {
  const bg = (look.bg_color || '').trim();
  if (bg && !HEX.test(bg)) {
    message.error(tf('Color_Format', 'Use a color like #ffffff.'));
    return;
  }
  savingLook.value = true;
  try {
    const data = await http.put('bookings_public_look', { hide_logo: !look.show_logo, bg_color: bg || null });
    applyLook(data.public_booking);
    previewKey.value++;
    message.success(tf('Saved', 'Saved'));
  } catch (e) {
    message.error(e?.data?.message || tf('InvalidData', 'Invalid data'));
  } finally {
    savingLook.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('bookings_public_link');
    applyLook(data?.public_booking || null);
  } catch (e) {
    message.error(e?.data?.message || tf('InvalidData', 'Invalid data'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.pb-color { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pb-swatch { width: 40px; height: 32px; padding: 2px; border: 1px solid var(--ant-color-border, #d9d9d9); border-radius: 6px; background: none; cursor: pointer; }
.pb-code :deep(textarea), .pb-code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; }
.pb-preview {
  width: 100%;
  height: 900px;
  border: 1px solid var(--ant-color-border, #e5e7eb);
  border-radius: 8px;
  background: #fff;
}
</style>
