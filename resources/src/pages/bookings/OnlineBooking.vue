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
.pb-preview {
  width: 100%;
  height: 900px;
  border: 1px solid var(--ant-color-border, #e5e7eb);
  border-radius: 8px;
  background: #fff;
}
</style>
