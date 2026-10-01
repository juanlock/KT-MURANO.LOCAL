<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit_Booking') : $t('Create_Booking')"
      :breadcrumb="[$t('Bookings'), isEdit ? $t('Edit_Booking') : $t('Create_Booking')]"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <a-form ref="formRef" :model="booking" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item name="customer_id">
              <template #label>
                <div class="label-row">
                  <span>{{ $t('Customer') }} *</span>
                  <span>
                    <!-- Edit the selected customer's details in a modal,
                         without leaving the booking. -->
                    <a-button
                      v-if="booking.customer_id && auth.can('Customers_edit')"
                      type="link" size="small" style="padding: 0; margin-right: 10px"
                      @click="openEditCustomer"
                    >
                      <EditOutlined /> {{ $t('Edit') }}
                    </a-button>
                    <a-button type="link" size="small" style="padding: 0" @click="openQuickAdd">
                      {{ $t('New_Customer') }}
                    </a-button>
                  </span>
                </div>
              </template>
              <a-select
                v-model:value="booking.customer_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Customer')" allow-clear
                :options="customers.map(c => ({ value: c.id, label: c.name }))"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <!-- Staff: locked to the signed-in user unless admin / role has
                 "Booking to other staff" (the server enforces it too). -->
            <a-form-item label="Staff" :extra="canOtherStaff ? '' : 'Your own booking (booking for other staff needs the “Booking to other staff” permission).'">
              <a-select
                v-model:value="booking.sales_person_id" show-search option-filter-prop="label"
                placeholder="Choose staff" :allow-clear="canOtherStaff" :disabled="!canOtherStaff"
                :options="salesPersons.map(p => ({ value: p.id, label: p.name, avatar: p.avatar }))"
                popup-class-name="bk-rich-pop"
              >
                <template #option="{ label, avatar }">
                  <span class="opt-staff"><a-avatar :size="22" :src="avatar || undefined" class="opt-staff__avatar">{{ (label || '?').split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase() }}</a-avatar>{{ label }}</span>
                </template>
              </a-select>
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Tray')">
              <a-select
                v-model:value="booking.tray_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Tray')" allow-clear
                :options="trays.map(t2 => ({ value: t2.id, label: t2.name }))"
              />
            </a-form-item>
          </a-col>

          <!-- Location of the appointment (and of its invoice). -->
          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('warehouse')">
              <a-select
                v-model:value="booking.warehouse_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')" allow-clear
                :options="warehouseOptions"
              />
            </a-form-item>
          </a-col>

          <!-- Product/Service: optional Group filter, then the dropdown (like
               the Sales / Purchase forms) with "New" to create one on the spot. -->
          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Group_Service')">
              <a-select
                v-model:value="serviceGroup" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Group_Service')" allow-clear
                :options="groupOptions"
                @change="onGroupChange"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item name="product_id">
              <template #label>
                <div class="label-row">
                  <span>{{ $t('Product_Name') }}</span>
                  <a-button v-if="auth.can('products_add')" type="link" size="small" style="padding: 0" @click="quickProductOpen = true">
                    <PlusOutlined /> {{ $t('New') || 'New' }}
                  </a-button>
                </div>
              </template>
              <a-select
                v-model:value="booking.product_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Service')" allow-clear
                :options="serviceOptions"
                @change="onProductChange"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="4">
            <a-form-item :label="$t('Price')" name="price">
              <a-input-number
                v-model:value="booking.price" :min="0" :step="moneyStep" :precision="moneyPrecision"
                style="width: 100%" :placeholder="tf('Enter_Price', 'Enter price')"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="4">
            <a-form-item :label="$t('Amount')" name="amount">
              <a-input-number
                v-model:value="booking.amount" :min="0" :step="moneyStep" :precision="moneyPrecision"
                style="width: 100%" :placeholder="$t('Amount')"
              />
            </a-form-item>
          </a-col>

          <a-col :span="24">
            <a-form-item :label="$t('Product_Description')">
              <a-textarea
                v-model:value="booking.product_description" :rows="2"
                :placeholder="$t('Product_Description')"
              />
            </a-form-item>
          </a-col>

          <!-- Date / start / end: the same control set as the calendar modal. -->
          <a-col :span="24">
            <BookingWhenFields
              v-model:date="booking.booking_date" v-model:start="booking.booking_time" v-model:end="booking.booking_end_time"
              :duration="selectedDuration" :exclude-id="isEdit ? id : null" :customer-id="booking.customer_id"
            />
          </a-col>

          <a-col v-if="fields.delivery_date" :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Delivery_Date')">
              <a-date-picker v-model:value="booking.delivery_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>

          <a-col v-if="fields.delivery_time" :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Delivery_Time')">
              <a-time-picker
                v-model:value="booking.delivery_time" value-format="HH:mm" format="HH:mm" style="width: 100%"
              />
            </a-form-item>
          </a-col>

          <a-col v-if="fields.status" :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Status') + ' *'" name="status">
              <a-select
                v-model:value="booking.status" :placeholder="$t('Choose_Status')" :options="statusOptions"
              />
            </a-form-item>
          </a-col>

          <a-col v-if="fields.delivery_address" :xs="24" :lg="12">
            <a-form-item :label="$t('Delivery_Address')">
              <a-textarea
                v-model:value="booking.delivery_address" :rows="2"
                :placeholder="$t('Delivery_Address')"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :lg="12">
            <a-form-item :label="$t('Details')">
              <a-textarea v-model:value="booking.notes" :rows="4" :placeholder="$t('Afewwords')" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space>
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/bookings')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>

    <!-- New product/service without leaving the booking (same modal as Sales). -->
    <QuickAddProduct v-model:open="quickProductOpen" context="sale" @created="onProductCreated" />

    <!-- Quick add customer -->
    <a-modal
      v-model:open="quickAddOpen" :title="$t('New_Customer')"
      :confirm-loading="clientSaving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitQuickAdd"
    >
      <a-form ref="clientRef" :model="client" :rules="clientRules" layout="vertical">
        <a-form-item :label="$t('CustomerName') + ' *'" name="name">
          <a-input v-model:value="client.name" :placeholder="$t('CustomerName')" />
        </a-form-item>
        <a-form-item :label="$t('Email')">
          <a-input v-model:value="client.email" />
        </a-form-item>
        <a-form-item :label="$t('Phone')">
          <a-input v-model:value="client.phone" />
        </a-form-item>
        <a-form-item :label="$t('Address')">
          <a-textarea v-model:value="client.adresse" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Edit the selected customer -->
    <a-modal
      v-model:open="editCustomerOpen" :title="`${$t('Edit')} — ${editCustomer.name || $t('Customer')}`"
      :confirm-loading="editCustomerSaving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      :ok-button-props="{ disabled: editCustomerLoading }"
      @ok="submitEditCustomer"
    >
      <a-spin :spinning="editCustomerLoading">
        <a-form ref="editCustomerRef" :model="editCustomer" :rules="editCustomerRules" layout="vertical">
          <a-form-item :label="$t('CustomerName') + ' *'" name="name">
            <a-input v-model:value="editCustomer.name" />
          </a-form-item>
          <a-row :gutter="12">
            <a-col :span="12">
              <a-form-item :label="$t('Email')" name="email">
                <a-input v-model:value="editCustomer.email" type="email" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('Phone')" name="phone">
                <a-input v-model:value="editCustomer.phone" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item :label="$t('Address')" name="adresse">
            <a-textarea v-model:value="editCustomer.adresse" :rows="2" />
          </a-form-item>
          <a-row :gutter="12">
            <a-col :span="10">
              <a-form-item :label="$t('City')" name="city">
                <a-input v-model:value="editCustomer.city" />
              </a-form-item>
            </a-col>
            <a-col :span="7">
              <a-form-item :label="$t('State')" name="state">
                <a-input v-model:value="editCustomer.state" />
              </a-form-item>
            </a-col>
            <a-col :span="7">
              <a-form-item :label="$t('ZipCode')" name="zip">
                <a-input v-model:value="editCustomer.zip" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-form>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Booking create/edit — legacy store_booking.vue + Edit_booking.vue, which
 * were byte-identical apart from the five branches handled below.
 *
 * - create: GET bookings/create → {customers, products, trays, sales_persons}
 *           → POST bookings
 * - edit:   GET bookings/{id}/edit → the same four lists + booking
 *           → PUT bookings/{id} (same body, id only in the URL)
 *
 * The product list is services only — that is the server's filter, not ours.
 *
 * Legacy quirks kept: `price` and `amount` are independent free-entry numbers
 * with no arithmetic between them, and the product-price prefill differs by
 * mode (create overwrites whatever is typed and clears on deselect; edit only
 * fills an empty price and ignores deselect).
 *
 * Fixed: the default booking date used toISOString(), i.e. UTC, so anyone east
 * of UTC got yesterday's date late in the evening — it is local now. Server
 * validation errors were swallowed behind a generic toast; field messages are
 * surfaced. Times still round-trip as HH:mm, truncating the HH:mm:ss the API
 * returns, or the pickers render blank and a save would wipe existing times.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import BookingWhenFields from '../../components/BookingWhenFields.vue';
import QuickAddProduct from '../../components/QuickAddProduct.vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { EditOutlined, PlusOutlined } from '@ant-design/icons-vue';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = route.params.id;
const isEdit = !!id;

const loading = ref(true);
const submitting = ref(false);
const formRef = ref();

const customers = ref([]);
const products = ref([]);
const trays = ref([]);
const salesPersons = ref([]);
// Admins / roles with "Booking to other staff" may pick any staff; others are locked to themselves.
const canOtherStaff = ref(true);
const warehouses = ref([]);
const warehouseOptions = computed(() => sortByLabel(warehouses.value.map(w => ({ value: w.id, label: w.name }))));
const quickProductOpen = ref(false);
// System Settings -> Defaults -> Booking: optional fields (all shown until the API says otherwise).
// A hidden field keeps its value: a new booking saves the defaults (status Pending,
// no delivery), an edit sends back what the booking already has.
const fields = ref({ delivery_date: true, delivery_time: true, status: true, delivery_address: true, decimals: true });
// Defaults -> Booking -> Use decimals: off = whole numbers in Price / Amount.
const moneyPrecision = computed(() => (fields.value.decimals ? 2 : 0));
const moneyStep = computed(() => (fields.value.decimals ? 0.01 : 1));

/** Local date, not UTC — see the note above. */
function todayLocal() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// New booking opened from the calendar: ?date=YYYY-MM-DD&time=HH:mm prefill
// the day/time that was clicked.
const qDate = /^\d{4}-\d{2}-\d{2}$/.test(String(route.query.date || '')) ? String(route.query.date) : null;
const qTime = /^\d{2}:\d{2}$/.test(String(route.query.time || '')) ? String(route.query.time) : '';

const booking = ref({
  customer_id: null, sales_person_id: null, tray_id: null, warehouse_id: null, product_id: null,
  product_name: '', product_description: '', price: null, amount: null,
  booking_date: isEdit ? '' : (qDate || todayLocal()),
  booking_time: isEdit ? '' : qTime, booking_end_time: '',
  delivery_date: '', delivery_time: '', delivery_address: '',
  status: 'pending', notes: '',
});

const statusOptions = computed(() => [
  { value: 'pending', label: t('Pending') },
  // `Confirmed` is referenced in legacy but missing from the translation
  // seeder, so it needs an explicit fallback or it renders as the raw key.
  { value: 'confirmed', label: tf('Confirmed', 'Confirmed') },
  { value: 'cancelled', label: t('Cancelled') },
  { value: 'completed', label: t('complete') },
]);

const rules = computed(() => ({
  customer_id: [{ required: true, message: t('Field_is_required') }],
  booking_date: [{ required: true, message: t('Field_is_required') }],
  booking_time: [{ required: true, message: t('Field_is_required') }],
  status: [{ required: true, message: t('Field_is_required') }],
  product_name: [{ max: 255, message: t('Please_fill_the_form_correctly') }],
  price: [{ type: 'number', min: 0, message: t('Please_fill_the_form_correctly') }],
  amount: [{ type: 'number', min: 0, message: t('Please_fill_the_form_correctly') }],
}));

/** The API stores HH:mm:ss; the pickers only accept HH:mm. */
function normalizeTime(value) {
  if (!value) return '';
  return String(value).length > 5 ? String(value).substring(0, 5) : String(value);
}

/* ---------------- Group Service -> Service ----------------
 * Groups come from the services themselves (product_group_id / group_name);
 * services without a group sit under "Other". NO_GROUP keeps that choice
 * distinct from "no group selected" (null = every service is offered). */
const NO_GROUP = 0;
const serviceGroup = ref(null);
const groupOf = p => p.product_group_id || NO_GROUP;

const groupOptions = computed(() => {
  const seen = new Map();
  products.value.forEach(p => {
    const g = groupOf(p);
    if (!seen.has(g)) seen.set(g, g === NO_GROUP ? tf('Other', 'Other') : p.group_name);
  });
  return sortByLabel([...seen].map(([value, label]) => ({ value, label })));
});

const serviceOptions = computed(() => sortByLabel(
  products.value
    .filter(p => serviceGroup.value === null || serviceGroup.value === undefined || groupOf(p) === serviceGroup.value)
    // Duration shown with the name, e.g. "Haircut · 1 h" (Product form -> Service
    // duration); physical products are marked so they stand apart from services.
    .map(p => ({
      value: p.id,
      label: (p.service_duration ? `${p.name} · ${fmtDuration(p.service_duration)}` : p.name)
        + (p.type && p.type !== 'is_service' ? ` (${tf('Product_Only', 'Product')})` : ''),
    }))
));
const selectedDuration = computed(() => Number(products.value.find(p => p.id === booking.value.product_id)?.service_duration) || 0);
function fmtDuration(m) {
  const h = Math.floor(m / 60);
  const r = m % 60;
  return [h ? `${h} h` : '', r ? `${r} min` : ''].filter(Boolean).join(' ');
}

function onGroupChange(group) {
  const current = products.value.find(p => p.id === booking.value.product_id);
  // A service outside the newly chosen group no longer fits -- clear it.
  if (current && group != null && groupOf(current) !== group) {
    booking.value.product_id = null;
    onProductChange(null);
  }
}

// End Time follows the service's duration inside BookingWhenFields.

/**
 * Picking a product/service (a user action, create or edit) fills its name
 * and its price -- the price is the default and can still be typed over.
 * Clearing it clears both.
 */
function onProductChange(productId) {
  const product = products.value.find(p => p.id === productId);
  // Picking one straight away (no group chosen) fills its group in.
  if (product) serviceGroup.value = groupOf(product);
  booking.value.product_name = product ? product.name : '';
  booking.value.price = product && product.price != null ? Number(product.price) : null;
}

// Quick Add Product/Service: reload the list, then pick the new one by its code.
async function onProductCreated({ code }) {
  try {
    const data = await http.get('bookings/create');
    products.value = data?.products || products.value;
    const created = products.value.find(p => p.code === code);
    if (created) {
      booking.value.product_id = created.id;
      onProductChange(created.id);
    }
  } catch (e) { /* the list just isn't refreshed */ }
}

function payload() {
  const b = booking.value;
  return {
    customer_id: b.customer_id,
    sales_person_id: b.sales_person_id,
    tray_id: b.tray_id,
    warehouse_id: b.warehouse_id || null,
    product_id: b.product_id,
    product_name: b.product_name,
    product_description: b.product_description,
    price: b.price ? parseFloat(b.price) : null,
    amount: b.amount ? parseFloat(b.amount) : null,
    booking_date: b.booking_date,
    booking_time: b.booking_time,
    // These three must be null rather than "" — the server validates them
    // with date_format:H:i and an empty string fails.
    booking_end_time: b.booking_end_time || null,
    delivery_date: b.delivery_date || null,
    delivery_time: b.delivery_time || null,
    delivery_address: b.delivery_address,
    status: b.status,
    notes: b.notes,
  };
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  submitting.value = true;
  try {
    let res;
    if (isEdit) {
      res = await http.put(`bookings/${id}`, payload());
      message.success(t('Successfully_Updated'));
    } else {
      res = await http.post('bookings', payload());
      message.success(t('Successfully_Created'));
    }
    // A completed booking is invoiced on save (sale paid in cash).
    if (res?.sale_warning) message.warning(res.sale_warning, 6);
    else if (booking.value.status === 'completed' && res?.sale_ref) message.info(`${t('Invoice')} ${res.sale_ref}`);
    router.push('/bookings');
  } catch (e) {
    const errors = e?.data?.errors;
    message.error(e?.data?.message || (errors && Object.values(errors).flat()[0]) || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

// ---------------- quick add customer ----------------

const quickAddOpen = ref(false);
const clientSaving = ref(false);
const clientRef = ref();
const client = ref({ name: '', email: '', phone: '', adresse: '' });
const clientRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

function openQuickAdd() {
  client.value = { name: '', email: '', phone: '', adresse: '' };
  clientRef.value?.clearValidate?.();
  quickAddOpen.value = true;
}

async function submitQuickAdd() {
  try {
    await clientRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  clientSaving.value = true;
  try {
    const data = await http.post('clients', {
      name: client.value.name,
      email: client.value.email || '',
      phone: client.value.phone || '',
      adresse: client.value.adresse || '',
    });
    const created = data?.provider || data;
    if (created?.id) {
      customers.value.push({ id: created.id, name: created.name });
      booking.value.customer_id = created.id;
    }
    message.success(t('Successfully_Created'));
    quickAddOpen.value = false;
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    clientSaving.value = false;
  }
}

// ---------------- edit the selected customer (modal) ----------------
// GET clients/{id} -> the full customer; the modal edits a few fields and
// PUT clients/{id} sends the FULL record back -- that endpoint overwrites
// every field it receives, so sending only the edited ones would blank the
// rest (tax number, country, credit limit...).

const editCustomerOpen = ref(false);
const editCustomerLoading = ref(false);
const editCustomerSaving = ref(false);
const editCustomerRef = ref();
const editCustomer = ref({});
let editCustomerFull = {};
const editCustomerRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
  email: [{ type: 'email', message: t('InvalidData') }],
}));

async function openEditCustomer() {
  const id = booking.value.customer_id;
  if (!id) return;
  editCustomer.value = { name: customers.value.find(c => c.id === id)?.name || '' };
  editCustomerRef.value?.clearValidate?.();
  editCustomerOpen.value = true;
  editCustomerLoading.value = true;
  try {
    const data = await http.get(`clients/${id}`);
    editCustomerFull = data?.client || data || {};
    const c = editCustomerFull;
    editCustomer.value = {
      name: c.name || '', email: c.email || '', phone: c.phone || '',
      adresse: c.adresse || '', city: c.city || '', state: c.state || '', zip: c.zip || '',
    };
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
    editCustomerOpen.value = false;
  } finally {
    editCustomerLoading.value = false;
  }
}

async function submitEditCustomer() {
  try {
    await editCustomerRef.value.validate();
  } catch (e) {
    return;
  }
  const id = booking.value.customer_id;
  const c = editCustomerFull;
  editCustomerSaving.value = true;
  try {
    await http.put(`clients/${id}`, {
      // untouched fields, exactly as loaded
      firstname: c.firstname ?? '', lastname: c.lastname ?? '', country: c.country ?? '',
      tax_number: c.tax_number ?? '', is_royalty_eligible: !!c.is_royalty_eligible,
      credit_limit: Number(c.credit_limit) || 0,
      // edited here
      ...editCustomer.value,
    });
    const row = customers.value.find(x => x.id === id);
    if (row) row.name = editCustomer.value.name;
    message.success(t('Successfully_Updated'));
    editCustomerOpen.value = false;
  } catch (e) {
    const errs = e?.data?.errors;
    const first = errs && Object.values(errs)[0];
    message.error((Array.isArray(first) ? first[0] : first) || e?.data?.message || t('InvalidData'));
  } finally {
    editCustomerSaving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get(isEdit ? `bookings/${id}/edit` : 'bookings/create');
    customers.value = data?.customers || [];
    products.value = data?.products || [];
    trays.value = data?.trays || [];
    salesPersons.value = data?.sales_persons || [];
    warehouses.value = data?.warehouses || [];
    canOtherStaff.value = data?.can_book_other_staff !== false;
    // Staff locked to the user: a new booking is theirs.
    if (!isEdit && !canOtherStaff.value && data?.me) booking.value.sales_person_id = data.me;
    if (data?.booking_fields) fields.value = { ...fields.value, ...data.booking_fields };

    // New booking: System Settings -> Defaults -> Booking (location, Walk-in
    // customer, station, signed-in user as sales person).
    if (!isEdit && data?.defaults) {
      const d = data.defaults;
      const has = (list, v) => v && list.some(x => x.id === v);
      if (has(warehouses.value, d.warehouse_id)) booking.value.warehouse_id = d.warehouse_id;
      if (has(customers.value, d.customer_id)) booking.value.customer_id = d.customer_id;
      if (has(trays.value, d.tray_id)) booking.value.tray_id = d.tray_id;
      if (has(salesPersons.value, d.sales_person_id)) booking.value.sales_person_id = d.sales_person_id;
    }

    if (isEdit) {
      const b = data?.booking || {};
      booking.value = {
        customer_id: b.customer_id,
        sales_person_id: b.sales_person_id,
        tray_id: b.tray_id,
        warehouse_id: b.warehouse_id || null,
        product_id: b.product_id,
        product_name: b.product_name || '',
        product_description: b.product_description || '',
        price: b.price,
        amount: b.amount,
        // Defensive slice: the pickers need a bare date even if the API ever
        // starts returning a full timestamp.
        booking_date: String(b.booking_date || '').slice(0, 10),
        booking_time: normalizeTime(b.booking_time),
        booking_end_time: normalizeTime(b.booking_end_time),
        delivery_date: String(b.delivery_date || '').slice(0, 10),
        delivery_time: normalizeTime(b.delivery_time),
        delivery_address: b.delivery_address || '',
        status: b.status,
        notes: b.notes || '',
      };
      const current = products.value.find(p => p.id === b.product_id);
      if (current) serviceGroup.value = groupOf(current);
    }
  } catch (e) {
    // Legacy failed silently here and left an empty form with empty selects.
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
}
</style>

<style>
/* Staff dropdown rows (popup renders outside the component). */
.bk-rich-pop .opt-staff,
.ant-select-selection-item .opt-staff {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.bk-rich-pop .opt-staff__avatar {
  background: #ede9fe;
  color: #6d28d9;
  font-size: 10px;
  font-weight: 600;
}
</style>
