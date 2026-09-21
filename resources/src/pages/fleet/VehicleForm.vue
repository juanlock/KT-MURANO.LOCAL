<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? 'Edit vehicle' : 'Add vehicle'"
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
          <a-card size="small" title="Vehicle" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item label="Name *" name="name">
                  <a-input v-model:value="form.name" placeholder="e.g. Delivery van 1" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Plate number *" name="plate_number">
                  <a-input v-model:value="form.plate_number" placeholder="e.g. 1234-AB-56" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Type *" name="type">
                  <a-select v-model:value="form.type" :options="VEHICLE_TYPES" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Status *" name="status">
                  <a-select v-model:value="form.status" :options="VEHICLE_STATUSES" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Fuel type *" name="fuel_type">
                  <a-select v-model:value="form.fuel_type" :options="FUEL_TYPES" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item label="Make">
                  <a-input v-model:value="form.make" placeholder="e.g. Toyota" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item label="Model">
                  <a-input v-model:value="form.model" placeholder="e.g. Hiace" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item label="Year" name="year">
                  <a-input-number v-model:value="form.year" :min="1900" :max="2200" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item label="Colour">
                  <a-input v-model:value="form.color" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="VIN / chassis no.">
                  <a-input v-model:value="form.vin" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item label="Odometer" name="odometer">
                  <a-input-number v-model:value="form.odometer" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item label="Tank capacity" name="tank_capacity">
                  <a-input-number v-model:value="form.tank_capacity" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" title="Assignment & purchase" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Warehouse')">
                  <a-select
                    v-model:value="form.warehouse_id" allow-clear show-search
                    option-filter-prop="label" :options="warehouseOptions"
                    placeholder="Not assigned to a branch"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Default driver">
                  <a-select
                    v-model:value="form.employee_id" allow-clear show-search
                    option-filter-prop="label" :options="employeeOptions"
                    placeholder="No driver assigned"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Purchase date" name="purchase_date">
                  <a-date-picker v-model:value="form.purchase_date" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Purchase price" name="purchase_price">
                  <a-input-number v-model:value="form.purchase_price" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" title="Compliance" style="margin-bottom: 16px">
            <a-alert
              type="info" show-icon banner
              message="Dates set here drive the dashboard's “needs attention” alerts, 30 days ahead."
              style="margin-bottom: 16px"
            />
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item label="Insurance provider">
                  <a-input v-model:value="form.insurance_provider" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Policy number">
                  <a-input v-model:value="form.insurance_policy" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Insurance expiry" name="insurance_expiry">
                  <a-date-picker v-model:value="form.insurance_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Registration expiry" name="registration_expiry">
                  <a-date-picker v-model:value="form.registration_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Inspection due" name="inspection_expiry">
                  <a-date-picker v-model:value="form.inspection_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <!-- Photo + notes -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" title="Photo" style="margin-bottom: 16px">
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
                  {{ previewUrl ? 'Replace' : 'Upload' }}
                </a-button>
              </a-upload>
              <a-button v-if="previewUrl" danger @click="clearImage">{{ $t('Delete') }}</a-button>
            </a-space>
            <p class="photo-hint">JPG or PNG, up to 5 MB.</p>
          </a-card>

          <a-card size="small" :title="$t('Note')">
            <a-textarea v-model:value="form.notes" :rows="6" placeholder="Anything worth knowing about this vehicle…" />
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
  tank_capacity: null, odometer: 0,
  purchase_date: null, purchase_price: null,
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
