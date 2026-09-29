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
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import OnlineBookingCard from '../../components/OnlineBookingCard.vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const loading = ref(true);
const publicBooking = ref(null);
const previewKey = ref(0);

onMounted(async () => {
  try {
    const data = await http.get('bookings_public_link');
    publicBooking.value = data?.public_booking || null;
  } catch (e) {
    message.error(e?.data?.message || tf('InvalidData', 'Invalid data'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.pb-preview {
  width: 100%;
  height: 900px;
  border: 1px solid var(--ant-color-border, #e5e7eb);
  border-radius: 8px;
  background: #fff;
}
</style>
