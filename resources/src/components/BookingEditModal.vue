<template>
  <!-- Appointment quick edit (status, time, service, staff, station, location,
       appointment mode, price, notes) -- shared by Bookings -> Calendar and the Bookings list.
       Loads/saves the full booking through the same endpoints as the Edit
       page (GET bookings/{id}/edit, PUT bookings/{id}), so nothing else is
       overwritten; status buttons use PUT bookings/{id}/status. -->
  <a-modal
    :open="open"
    :title="`${booking?.Ref || ''} — ${booking?.customer_name || ''}`"
    width="720px"
    @update:open="v => emit('update:open', v)"
  >
    <a-spin :spinning="editLoading">
      <template v-if="edit">
        <!-- Status + one-click moves -->
        <div class="appt-status">
          <span>{{ $t('Status') }}:</span>
          <a-tag :style="tagStyle(edit.status)">{{ statusLabel(edit.status) }}</a-tag>
          <a-space wrap>
            <a-button v-if="edit.status === 'pending'" type="primary" size="small" :loading="statusSaving === 'confirmed'" @click="quickStatus('confirmed')">
              <template #icon><CheckCircleOutlined /></template>{{ $t('Confirm') }}
            </a-button>
            <a-button v-if="edit.status === 'confirmed'" size="small" style="color: #15803d; border-color: #22c55e" :loading="statusSaving === 'completed'" @click="quickStatus('completed')">
              <template #icon><CheckSquareOutlined /></template>{{ $t('complete') }}
            </a-button>
            <a-button v-if="edit.status === 'pending' || edit.status === 'confirmed'" danger size="small" :loading="statusSaving === 'cancelled'" @click="quickStatus('cancelled')">
              <template #icon><StopOutlined /></template>{{ $t('Cancel') }}
            </a-button>
          </a-space>
        </div>

        <a-form layout="vertical">
          <!-- Same date / start / end controls as Create/Edit Booking. -->
          <BookingWhenFields
            v-model:date="edit.booking_date" v-model:start="edit.booking_time" v-model:end="edit.booking_end_time"
            :duration="Number(selectedService?.service_duration) || 0"
            :exclude-id="edit.id" :customer-id="edit.customer_id"
          />
          <a-row :gutter="12">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Product_Name')">
                <!-- Service: name, its group, duration and price at a glance. -->
                <a-select
                  v-model:value="edit.product_id" show-search option-filter-prop="label" allow-clear
                  :options="serviceOptions" :placeholder="$t('PleaseSelect')" option-label-prop="label"
                  popup-class-name="bk-rich-pop" @change="onServiceChange"
                >
                  <template #option="{ name, group, duration, price }">
                    <div class="opt-svc">
                      <span class="opt-svc__icon"><ScissorOutlined /></span>
                      <div class="opt-svc__main">
                        <div class="opt-svc__name">{{ name }}</div>
                        <div v-if="group" class="opt-svc__group">{{ group }}</div>
                      </div>
                      <div class="opt-svc__meta">
                        <a-tag v-if="duration" color="purple" style="margin: 0">{{ duration }}</a-tag>
                        <span v-if="price != null" class="opt-svc__price">{{ money(price) }}</span>
                      </div>
                    </div>
                  </template>
                </a-select>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item label="Staff">
                <!-- Staff: photo + name. Locked unless admin / "Booking to other staff". -->
                <a-select
                  v-model:value="edit.sales_person_id" show-search option-filter-prop="label" :allow-clear="canOtherStaff"
                  :options="staffOptions" :placeholder="$t('PleaseSelect')" option-label-prop="label"
                  popup-class-name="bk-rich-pop" :disabled="!canOtherStaff"
                >
                  <template #option="{ label, avatar }">
                    <div class="opt-staff">
                      <a-avatar :size="26" :src="avatar || undefined" class="opt-staff__avatar">{{ initials(label) }}</a-avatar>
                      <span>{{ label }}</span>
                    </div>
                  </template>
                </a-select>
              </a-form-item>
            </a-col>
            <a-col v-if="trayOptions.length" :xs="24" :md="12">
              <a-form-item :label="$t('Tray')">
                <a-select v-model:value="edit.tray_id" allow-clear :options="trayOptions" :placeholder="$t('PleaseSelect')">
                  <template #option="{ label }">
                    <span><AppstoreOutlined style="opacity: 0.55; margin-right: 6px" />{{ label }}</span>
                  </template>
                </a-select>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('warehouse')">
                <a-select v-model:value="edit.warehouse_id" allow-clear show-search option-filter-prop="label" :options="warehouseOptions" :placeholder="$t('PleaseSelect')">
                  <template #option="{ label }">
                    <span><EnvironmentOutlined style="opacity: 0.55; margin-right: 6px" />{{ label }}</span>
                  </template>
                </a-select>
              </a-form-item>
            </a-col>
            <!-- Appointment mode (Settings -> Booking): office / remote + link / on-site + address. -->
            <template v-if="modeOn">
              <a-col :xs="24" :md="12">
                <a-form-item :label="tf('Appointment_Mode', 'Appointment mode')">
                  <a-select v-model:value="edit.mode" :options="modeOptions" @change="m => { if (m === 'onsite' && !edit.site_address) edit.site_address = customerAddress }" />
                </a-form-item>
              </a-col>
              <a-col v-if="edit.mode === 'remote'" :xs="24" :md="12">
                <a-form-item :label="tf('Meeting_Link', 'Meeting link')">
                  <a-input v-model:value="edit.meeting_link" placeholder="https://" allow-clear>
                    <template #suffix>
                      <a v-if="/^https?:\/\/\S+$/i.test(edit.meeting_link || '')" :href="edit.meeting_link" target="_blank" rel="noopener">{{ tf('Join_Meeting', 'Join meeting') }}</a>
                    </template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col v-if="edit.mode === 'onsite'" :xs="24" :md="12">
                <a-form-item :label="tf('Site_Address', 'Site address')">
                  <a-textarea v-model:value="edit.site_address" :rows="2" />
                </a-form-item>
              </a-col>
            </template>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Price')">
                <a-input-number v-model:value="edit.price" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :span="24">
              <a-form-item :label="$t('Note')">
                <a-textarea v-model:value="edit.notes" :rows="3" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-form>
      </template>
    </a-spin>
    <template #footer>
      <div class="appt-footer">
        <a-button type="link" style="padding: 0" :disabled="!booking" @click="router.push(`/bookings/${booking.id}/edit`)">
          {{ $t('Edit') }} — full form
        </a-button>
        <a-space>
          <a-button @click="emit('update:open', false)">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="saving" :disabled="!edit || editLoading" @click="saveEdit">{{ $t('submit') }}</a-button>
        </a-space>
      </div>
    </template>
  </a-modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import {
  CheckCircleOutlined, CheckSquareOutlined, StopOutlined, ScissorOutlined, AppstoreOutlined, EnvironmentOutlined,
} from '@ant-design/icons-vue';
import BookingWhenFields from './BookingWhenFields.vue';
import http from '../lib/http';
import { t as tf } from '../i18n';
import { sortByLabel } from '../lib/filterOptions';
import { useFormat } from '../composables/useFormat';

const props = defineProps({
  open: { type: Boolean, default: false },
  // The list / calendar row: { id, Ref, customer_name, ... }
  booking: { type: Object, default: null },
});
const emit = defineEmits(['update:open', 'saved']);

const { t } = useI18n();
const router = useRouter();
const { money } = useFormat();

const STATUS_COLORS = {
  pending: { bg: '#fed7aa', border: '#f97316', text: '#9a3412' },
  confirmed: { bg: '#dbeafe', border: '#3b82f6', text: '#1d4ed8' },
  cancelled: { bg: '#fee2e2', border: '#ef4444', text: '#b91c1c' },
  completed: { bg: '#bbf7d0', border: '#22c55e', text: '#15803d' },
};
function statusLabel(status) {
  return { pending: t('Pending'), confirmed: t('Confirmed'), cancelled: t('Cancelled'), completed: t('complete') }[status] || status;
}
function tagStyle(status) {
  const c = STATUS_COLORS[status] || STATUS_COLORS.pending;
  return { background: c.bg, borderColor: c.border, color: c.text };
}
function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
}
function fmtDuration(m) {
  const h = Math.floor(m / 60);
  const r = m % 60;
  return [h ? `${h} h` : '', r ? `${r} min` : ''].filter(Boolean).join(' ');
}

const editLoading = ref(false);
const saving = ref(false);
const statusSaving = ref(null);
const edit = ref(null);
const lists = ref({ products: [], sales_persons: [], trays: [], warehouses: [] });
// Admins / roles with "Booking to other staff" may change the staff (server enforces it too).
const canOtherStaff = ref(true);

const serviceOptions = computed(() => sortByLabel((lists.value.products || []).map(p => ({
  value: p.id,
  label: [p.group_name, p.name].filter(Boolean).join(' › '),
  name: p.name,
  group: p.group_name || '',
  duration: p.service_duration ? fmtDuration(p.service_duration) : '',
  price: p.price != null ? Number(p.price) : null,
}))));
const staffOptions = computed(() => sortByLabel((lists.value.sales_persons || []).map(s => ({ value: s.id, label: s.name, avatar: s.avatar || null }))));
const trayOptions = computed(() => sortByLabel((lists.value.trays || []).map(s => ({ value: s.id, label: s.name }))));
const warehouseOptions = computed(() => sortByLabel((lists.value.warehouses || []).map(w => ({ value: w.id, label: w.name }))));
const selectedService = computed(() => (lists.value.products || []).find(p => p.id === edit.value?.product_id));
// Appointment mode on for the booking's company (booking_fields.mode).
const modeOn = ref(false);
const modeOptions = computed(() => [
  { value: 'office', label: tf('Mode_Office', 'At our office') },
  { value: 'remote', label: tf('Mode_Remote', 'Remote (online)') },
  { value: 'onsite', label: tf('Mode_Onsite', "At the customer's site") },
]);
const customerAddress = computed(() => (lists.value.customers || []).find(c => c.id === edit.value?.customer_id)?.address || '');

// Same rule as the booking form: picking a service sets its name and its
// price (the default, still editable); End Time follows its duration.
function onServiceChange() {
  const p = selectedService.value;
  edit.value.product_name = p ? p.name : null;
  edit.value.price = p && p.price != null ? Number(p.price) : null;
}

const hhmm = v => (v ? String(v).slice(0, 5) : null);

async function loadEdit(id) {
  editLoading.value = true;
  edit.value = null;
  try {
    const data = await http.get(`bookings/${id}/edit`);
    const b = data.booking || {};
    lists.value = { products: data.products || [], sales_persons: data.sales_persons || [], trays: data.trays || [], warehouses: data.warehouses || [], customers: data.customers || [] };
    canOtherStaff.value = data.can_book_other_staff !== false;
    modeOn.value = !!data.booking_fields?.mode;
    edit.value = {
      ...b,
      mode: b.mode || 'office',
      booking_date: b.booking_date ? String(b.booking_date).slice(0, 10) : null,
      booking_time: hhmm(b.booking_time),
      booking_end_time: hhmm(b.booking_end_time),
      delivery_date: b.delivery_date ? String(b.delivery_date).slice(0, 10) : null,
      delivery_time: hhmm(b.delivery_time),
    };
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
    emit('update:open', false);
  } finally {
    editLoading.value = false;
  }
}
watch(() => [props.open, props.booking?.id], ([open, id]) => { if (open && id) loadEdit(id); }, { immediate: true });

function payload() {
  const b = edit.value;
  return {
    customer_id: b.customer_id,
    sales_person_id: b.sales_person_id || null,
    tray_id: b.tray_id || null,
    warehouse_id: b.warehouse_id || null,
    product_id: b.product_id || null,
    product_name: b.product_name,
    product_description: b.product_description,
    price: b.price ? parseFloat(b.price) : null,
    amount: b.amount ? parseFloat(b.amount) : null,
    booking_date: b.booking_date,
    booking_time: b.booking_time,
    booking_end_time: b.booking_end_time || null,
    delivery_date: b.delivery_date || null,
    delivery_time: b.delivery_time || null,
    delivery_address: b.delivery_address,
    ...(modeOn.value ? { mode: b.mode || 'office', meeting_link: b.meeting_link || null, site_address: b.site_address || null } : {}),
    status: b.status,
    notes: b.notes,
  };
}

function afterSave(data) {
  if (data?.customer_account_created) message.info('Customer account created');
  if (data?.sale_warning) message.warning(data.sale_warning, 6);
  emit('saved', data);
}

async function saveEdit() {
  if (!edit.value) return;
  if (edit.value.booking_end_time && edit.value.booking_end_time <= edit.value.booking_time) {
    message.warning('End time must be after the start time');
    return;
  }
  saving.value = true;
  try {
    const data = await http.put(`bookings/${edit.value.id}`, payload());
    message.success(t('Successfully_Updated'));
    emit('update:open', false);
    afterSave(data);
  } catch (e) {
    const errs = e?.data?.errors;
    const first = errs && Object.values(errs)[0];
    message.error((Array.isArray(first) ? first[0] : first) || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function quickStatus(status) {
  statusSaving.value = status;
  try {
    const data = await http.put(`bookings/${edit.value.id}/status`, { status });
    edit.value.status = status;
    message.success(`${edit.value.Ref}: ${statusLabel(status)}`);
    afterSave(data);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    statusSaving.value = null;
  }
}
</script>

<style scoped>
.appt-status {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  padding: 8px 12px;
  border-radius: 8px;
  background: rgba(128, 128, 128, 0.08);
}
.appt-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
</style>

<style>
/* Dropdown rows (popups render outside the component, so not scoped). */
.bk-rich-pop .opt-svc {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 2px 0;
}
.bk-rich-pop .opt-svc__icon {
  width: 28px;
  height: 28px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: rgba(109, 40, 217, 0.1);
  color: #6d28d9;
}
.bk-rich-pop .opt-svc__main {
  flex: 1;
  min-width: 0;
}
.bk-rich-pop .opt-svc__name {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bk-rich-pop .opt-svc__group {
  font-size: 11px;
  opacity: 0.6;
}
.bk-rich-pop .opt-svc__meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.bk-rich-pop .opt-svc__price {
  font-weight: 600;
  font-size: 12px;
}
.bk-rich-pop .opt-staff {
  display: flex;
  align-items: center;
  gap: 8px;
}
.bk-rich-pop .opt-staff__avatar {
  background: #ede9fe;
  color: #6d28d9;
  font-size: 11px;
  font-weight: 600;
}
</style>
