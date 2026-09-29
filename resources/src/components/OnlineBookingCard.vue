<template>
  <a-card size="small" :title="tf('Online_Booking_Page', 'Online booking page')">
    <template v-if="publicBooking">
      <a-alert
        v-if="publicBooking.enabled === false" type="warning" show-icon style="margin-bottom: 12px"
        :message="tf('Online_Booking_Module_Off', 'The Bookings module is turned off for this company, so the public page is not available.')"
      />
      <p class="pb-hint">
        {{ tf('Online_Booking_Page_Hint', 'Customers can book an appointment themselves on this page. Every request arrives here as a Pending booking.') }}
      </p>
      <a-form layout="vertical">
        <a-form-item :label="tf('Public_Link', 'Public link')">
          <a-input-group compact style="display: flex">
            <a-input :value="publicBooking.url" readonly style="flex: 1" />
            <a-button @click="copy(publicBooking.url)">{{ tf('Copy', 'Copy') }}</a-button>
            <a-button type="primary" :href="publicBooking.url" target="_blank">{{ tf('Open', 'Open') }}</a-button>
          </a-input-group>
        </a-form-item>
        <a-form-item
          :label="tf('Embed_Code', 'Embed code (iframe)')"
          :help="tf('Embed_Code_Hint', 'Paste this HTML into any external website where customers should be able to book. The frame resizes itself to its content.')"
        >
          <a-textarea :value="embedCode" readonly :auto-size="{ minRows: 4, maxRows: 10 }" class="pb-code" />
          <div style="margin-top: 8px">
            <a-button type="primary" @click="copy(embedCode)">{{ tf('Copy_Code', 'Copy code') }}</a-button>
          </div>
        </a-form-item>
      </a-form>
    </template>
    <a-alert
      v-else type="info" show-icon
      :message="tf('Online_Booking_Pick_Company', 'Select a company to see its public booking link and embed code.')"
    />
  </a-card>
</template>

<script setup>
/**
 * Public "Book an appointment" link + iframe snippet of the acting company.
 * `publicBooking` = the API's public_booking object ({company_id, url, embed_url, enabled})
 * or null (SuperAdmin without a picked company). Used on Create Booking and on
 * Booking Management -> Online Booking.
 */
import { computed } from 'vue';
import { message } from 'ant-design-vue';
import { t as tf } from '../i18n';

const props = defineProps({ publicBooking: { type: Object, default: null } });

/** iframe snippet for external sites; the small script lets the frame follow the page height. */
const embedCode = computed(() => {
  const pb = props.publicBooking;
  if (!pb) return '';
  const id = `murano-booking-${pb.company_id}`;
  return `<iframe id="${id}" src="${pb.embed_url}" title="Book an appointment" `
    + `style="width:100%;min-height:720px;border:0;" loading="lazy"></iframe>\n`
    + `<script>window.addEventListener('message',function(e){`
    + `if(e.data&&e.data.type==='murano-booking-height'){var f=document.getElementById('${id}');`
    + `if(f&&e.source===f.contentWindow)f.style.height=(e.data.height+20)+'px';}});<\/script>`;
});

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    message.success(tf('Copied', 'Copied'));
  } catch (e) {
    message.error(tf('Copy_Failed', 'Could not copy — select the text and copy it manually.'));
  }
}
</script>

<style scoped>
.pb-hint {
  margin: 0 0 12px;
  color: var(--ant-color-text-secondary, rgba(0, 0, 0, 0.55));
}
.pb-code :deep(textarea),
.pb-code {
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
}
</style>
