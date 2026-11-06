<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('tx_Edit_vehicle_d4932') : $t('Add_Fitment_Row')"
      :breadcrumb="['Fleet Management', 'Vehicles', isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #actions>
        <a-button @click="$router.push('/fleet/vehicles')">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <!-- Identity -->
        <a-col :xs="24" :lg="16">
          <a-card size="small" :title="$t('Vehicle')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('tx_name_4871e')" name="name">
                  <a-input v-model:value="form.name" :placeholder="$t('tx_eg_vehicle_name')" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('tx_Plate_number_f8018')" name="plate_number">
                  <a-input v-model:value="form.plate_number" :placeholder="$t('tx_eg_plate')" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('tx_Type_fa404')" name="type">
                  <a-select v-model:value="form.type" :options="VEHICLE_TYPES" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('tx_Status_f553a')" name="status">
                  <a-select v-model:value="form.status" :options="VEHICLE_STATUSES" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('tx_Fuel_type_5c606')" name="fuel_type">
                  <a-select v-model:value="form.fuel_type" :options="FUEL_TYPES" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Make')">
                  <a-input v-model:value="form.make" :placeholder="$t('tx_eg_make')" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Model')">
                  <a-input v-model:value="form.model" :placeholder="$t('tx_eg_model')" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item :label="$t('Year')" name="year">
                  <a-input-number v-model:value="form.year" :min="1900" :max="2200" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item :label="$t('tx_Colour_dfe60')">
                  <a-input v-model:value="form.color" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('tx_VIN_chassis_no_970f5')">
                  <a-input v-model:value="form.vin" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('tx_Odometer_5c059')" name="odometer">
                  <a-input-number v-model:value="form.odometer" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('tx_Tank_capacity_302fd')" name="tank_capacity">
                  <a-input-number v-model:value="form.tank_capacity" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
              <!-- Passengers and cargo (2026-11-04): tickets and parcels stop at these limits. -->
              <a-col :xs="12" :md="6">
                <a-form-item :label="t('Vehicle_Seats', 'Seats')" name="seats">
                  <a-input-number v-model:value="form.seats" :min="1" :max="200" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="t('Vehicle_Seats_Lower', 'Seats on the first floor')" name="seats_lower">
                  <a-input-number v-model:value="form.seats_lower" :min="0" :max="200" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="t('Vehicle_Vip_Seats', 'VIP seats (the first ones)')" name="vip_seats">
                  <a-input-number v-model:value="form.vip_seats" :min="0" :max="200" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="t('Vehicle_Cargo_Volume', 'Cargo hold (m³)')" name="cargo_volume_m3">
                  <a-input-number v-model:value="form.cargo_volume_m3" :min="0" :step="0.5" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="t('Vehicle_Cargo_Weight', 'Cargo limit (kg)')" name="cargo_weight_kg">
                  <a-input-number v-model:value="form.cargo_weight_kg" :min="0" :step="50" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" :title="$t('tx_Assignment_purchase_860dd')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Warehouse')">
                  <a-select
                    v-model:value="form.warehouse_id" allow-clear show-search
                    option-filter-prop="label" :options="warehouseOptions"
                    :placeholder="$t('tx_Not_assigned_to_a_branch_44d95')"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('tx_Default_driver_4d164')">
                  <a-select
                    v-model:value="form.employee_id" allow-clear show-search
                    option-filter-prop="label" :options="employeeOptions"
                    :placeholder="$t('tx_No_driver_assigned_5c944')"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Purchase_Date')" name="purchase_date">
                  <a-date-picker v-model:value="form.purchase_date" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('tx_Purchase_price_e32a2')" name="purchase_price">
                  <a-input-number v-model:value="form.purchase_price" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
              <!-- Depreciation per km = (price - residual value) / useful life. -->
              <a-col :xs="24" :md="12">
                <a-form-item :label="t('Vehicle_Useful_Life', 'Useful life (km)')" name="useful_life_km">
                  <a-input-number v-model:value="form.useful_life_km" :min="1" :precision="0" :step="50000" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="t('Vehicle_Residual_Value', 'Residual value')" name="residual_value">
                  <a-input-number v-model:value="form.residual_value" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" :title="$t('tx_Compliance_9c040')" style="margin-bottom: 16px">
            <a-alert
              type="info" show-icon banner
              :message="$t('tx_Dates_set_here_drive_the_dashboard_s_needs_attention_alerts__fba8f')"
              style="margin-bottom: 16px"
            />
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('tx_Insurance_provider_4e358')">
                  <a-input v-model:value="form.insurance_provider" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('tx_Policy_number_7a170')">
                  <a-input v-model:value="form.insurance_policy" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('tx_Insurance_expiry_d8436')" name="insurance_expiry">
                  <a-date-picker v-model:value="form.insurance_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('tx_Registration_expiry_eb139')" name="registration_expiry">
                  <a-date-picker v-model:value="form.registration_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('tx_Inspection_due_985a4')" name="inspection_expiry">
                  <a-date-picker v-model:value="form.inspection_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <!-- Photo + notes -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Photo')" style="margin-bottom: 16px">
            <div class="photo">
              <img v-if="previewUrl" :src="previewUrl" alt="Vehicle" />
              <!-- Tracks the Type select, so the placeholder confirms the choice. -->
              <span v-else class="photo-empty">
                <component :is="typeIcon(form.type)" :size="48" :stroke-width="1.4" />
              </span>
            </div>
            <a-space>
              <a-upload :show-upload-list="false" accept="image/*" :before-upload="pickImage">
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ previewUrl ? $t('tx_Replace_0ebe6') : $t('Upload') }}
                </a-button>
              </a-upload>
              <a-button v-if="previewUrl" danger @click="clearImage">{{ $t('Delete') }}</a-button>
            </a-space>
            <p class="photo-hint">{{ $t('tx_JPG_or_PNG_up_to_5_MB_ea7f0') }}</p>
          </a-card>

          <a-card size="small" :title="$t('Note')">
            <a-textarea v-model:value="form.notes" :rows="6" :placeholder="$t('tx_Anything_worth_knowing_about_this_vehicle_3de3b')" />
          </a-card>
        </a-col>
      </a-row>

      <div class="form-foot">
        <a-button @click="$router.push('/fleet/vehicles')">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" size="large" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </div>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Create / edit a vehicle.
 *
 * Always posts multipart (the photo rides along), so the edit path uses
 * POST fleet/vehicles/{id}/update rather than PUT — PHP cannot parse a
 * multipart body off a PUT request.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { VEHICLE_TYPES, VEHICLE_STATUSES, FUEL_TYPES, typeIcon } from './fleetOptions';
import { t } from '../../i18n';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const formRef = ref();
const loading = ref(false);
const saving = ref(false);

const form = ref({
  name: '', plate_number: '', vin: '', make: '', model: '', year: null, color: '',
  type: 'car', status: 'active', fuel_type: 'petrol',
  warehouse_id: undefined, employee_id: undefined,
  tank_capacity: null, odometer: 0, seats: null, seats_lower: null, vip_seats: null, cargo_volume_m3: null, cargo_weight_kg: null,
  purchase_date: null, purchase_price: null, useful_life_km: null, residual_value: null,
  insurance_provider: '', insurance_policy: '',
  insurance_expiry: null, registration_expiry: null, inspection_expiry: null,
  notes: '',
});

const imageFile = ref(null);
const previewUrl = ref('');
const removeImage = ref(false);

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  name: required(),
  plate_number: required(),
  type: required(),
  status: required(),
  fuel_type: required(),
}));

const warehouses = ref([]);
const employees = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.name })));

function pickImage(file) {
  imageFile.value = file;
  removeImage.value = false;
  previewUrl.value = window.URL.createObjectURL(file);
  return false; // keep antd from uploading it itself
}

function clearImage() {
  imageFile.value = null;
  previewUrl.value = '';
  removeImage.value = true;
}

async function load() {
  loading.value = true;
  try {
    const [meta, record] = await Promise.all([
      http.get('fleet/meta'),
      isEdit.value ? http.get(`fleet/vehicles/${route.params.id}/edit`) : Promise.resolve(null),
    ]);
    warehouses.value = meta?.warehouses || [];
    employees.value = meta?.employees || [];

    if (record?.vehicle) {
      const v = record.vehicle;
      form.value = {
        ...form.value,
        ...v,
        year: v.year ?? null,
        warehouse_id: v.warehouse_id || undefined,
        employee_id: v.employee_id || undefined,
        odometer: Number(v.odometer || 0),
        tank_capacity: v.tank_capacity === null ? null : Number(v.tank_capacity),
        seats: v.seats ?? null, seats_lower: v.seats_lower ?? null, vip_seats: v.vip_seats ?? null, cargo_volume_m3: v.cargo_volume_m3 ?? null, cargo_weight_kg: v.cargo_weight_kg ?? null,
        useful_life_km: v.useful_life_km ?? null, residual_value: v.residual_value ?? null,
        purchase_price: v.purchase_price === null ? null : Number(v.purchase_price),
      };
      previewUrl.value = v.image_url || '';
    }
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this vehicle'));
  } finally {
    loading.value = false;
  }
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  const fd = new FormData();
  Object.entries(form.value).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;
    // The API reads these from the route/photo, not the body.
    if (['id', 'image', 'image_url', 'created_at', 'updated_at', 'deleted_at'].includes(key)) return;
    fd.append(key, value);
  });
  if (imageFile.value) fd.append('image', imageFile.value);
  if (removeImage.value) fd.append('remove_image', '1');

  saving.value = true;
  try {
    const url = isEdit.value ? `fleet/vehicles/${route.params.id}/update` : 'fleet/vehicles';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(isEdit.value
        ? t('Updated_in_successfully', 'Updated successfully')
        : t('Created_in_successfully', 'Created successfully'));
      router.push('/fleet/vehicles');
      return;
    }
    // uploadForm resolves on 422 instead of throwing.
    message.error(firstError(res.data) || t('InvalidData', 'Could not save this vehicle'));
  } catch (e) {
    message.error(t('InvalidData', 'Could not save this vehicle'));
  } finally {
    saving.value = false;
  }
}

function firstError(data) {
  const errors = data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return data?.message || '';
}

onMounted(load);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.photo {
  height: 170px;
  margin-bottom: 12px;
  border-radius: 12px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}
.photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.photo-empty {
  font-size: 44px;
  opacity: 0.25;
}
.photo-hint {
  margin: 10px 0 0;
  font-size: 12px;
  opacity: 0.55;
}
.form-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}
</style>
