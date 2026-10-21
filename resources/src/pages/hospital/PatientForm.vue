<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? s('Edit patient') : s('Register patient')"
      :breadcrumb="[s('Hospital'), s('Patients'), isEdit ? s('Edit') : s('Add')]"
    >
      <template #actions>
        <a-button @click="back">{{ s('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">{{ s('Save') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-card size="small" :title="s('Identity')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Full name *')" name="name">
                  <a-input v-model:value="form.name" :placeholder="s(`Patient's full name`)" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item
                  :label="s('Medical record number')"
                  :extra="isEdit ? null : s('Leave empty and one is issued automatically')"
                >
                  <a-input v-model:value="form.mrn" placeholder="MRN-000001" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="s('Gender *')" name="gender">
                  <a-select v-model:value="form.gender" :options="genderOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="s('Date of birth')" name="date_of_birth">
                  <a-date-picker v-model:value="form.date_of_birth" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="s('Blood group')">
                  <a-select v-model:value="form.blood_group" :options="BLOOD_GROUPS" allow-clear :placeholder="s('Unknown')" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="s('Status *')" name="status">
                  <a-select v-model:value="form.status" :options="statusOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('Phone')">
                  <a-input v-model:value="form.phone" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('Email')" name="email">
                  <a-input v-model:value="form.email" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('National ID')">
                  <a-input v-model:value="form.national_id" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="16">
                <a-form-item :label="s('Address')">
                  <a-input v-model:value="form.address" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('City')">
                  <a-input v-model:value="form.city" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" :title="s('Clinical alerts')" style="margin-bottom: 16px">
            <a-alert
              type="warning" show-icon banner
              :message="s('Allergies entered here are shown on every consultation and prescription screen.')"
              style="margin-bottom: 16px"
            />
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Allergies')">
                  <a-textarea v-model:value="form.allergies" :rows="3" :placeholder="s('e.g. Penicillin, sulfa drugs')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Chronic conditions')">
                  <a-textarea v-model:value="form.chronic_conditions" :rows="3" :placeholder="s('e.g. Type 2 diabetes, hypertension')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" :title="s('Emergency contact & insurance')">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('Contact name')">
                  <a-input v-model:value="form.emergency_contact_name" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Contact phone')">
                  <a-input v-model:value="form.emergency_contact_phone" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Relationship')">
                  <a-input v-model:value="form.emergency_contact_relation" :placeholder="s('e.g. Spouse')" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('Insurance provider')">
                  <a-input v-model:value="form.insurance_provider" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Policy number')">
                  <a-input v-model:value="form.insurance_number" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Policy expiry')" name="insurance_expiry">
                  <a-date-picker v-model:value="form.insurance_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="s('Photo')" style="margin-bottom: 16px">
            <div class="photo">
              <img v-if="previewUrl" :src="previewUrl" alt="Patient" />
              <span v-else class="photo-empty"><UserOutlined /></span>
            </div>
            <a-space>
              <a-upload :show-upload-list="false" accept="image/*" :before-upload="pickImage">
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ previewUrl ? s('Replace') : s('Upload') }}
                </a-button>
              </a-upload>
              <a-button v-if="previewUrl" danger @click="clearImage">{{ s('Delete') }}</a-button>
            </a-space>
          </a-card>

          <a-card size="small" :title="s('Links')" style="margin-bottom: 16px">
            <a-form-item
              :label="s('Customer')"
              :extra="s('Link to a CRM customer if this patient also buys from the shop')"
              style="margin-bottom: 0"
            >
              <a-select
                v-model:value="form.client_id" allow-clear show-search
                option-filter-prop="label" :options="clientOptions"
                :placeholder="s('Not linked')"
              />
            </a-form-item>
          </a-card>

          <a-card size="small" :title="s('Note')">
            <a-textarea v-model:value="form.notes" :rows="5" />
          </a-card>
        </a-col>
      </a-row>

      <div class="form-foot">
        <a-button @click="back">{{ s('Cancel') }}</a-button>
        <a-button type="primary" size="large" :loading="saving" @click="submit">{{ s('Save') }}</a-button>
      </div>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Register / edit a patient.
 *
 * Always posts multipart (the photo rides along), so the edit path uses
 * POST hospital/patients/{id}/update rather than PUT — PHP cannot parse a
 * multipart body off a PUT request.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { UploadOutlined, UserOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { GENDERS, BLOOD_GROUPS, PATIENT_STATUSES } from './hospitalOptions';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/PatientForm.es.js';

const { s } = useHospitalText(ES);
const genderOptions = computed(() => GENDERS.map(o => ({ ...o, label: s(o.label) })));
const statusOptions = computed(() => PATIENT_STATUSES.map(o => ({ ...o, label: s(o.label) })));

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const formRef = ref();
const loading = ref(false);
const saving = ref(false);

const form = ref({
  name: '', mrn: '', gender: 'male', date_of_birth: null, blood_group: undefined,
  status: 'active', phone: '', email: '', national_id: '', address: '', city: '',
  allergies: '', chronic_conditions: '',
  emergency_contact_name: '', emergency_contact_phone: '', emergency_contact_relation: '',
  insurance_provider: '', insurance_number: '', insurance_expiry: null,
  client_id: undefined, notes: '',
});

const imageFile = ref(null);
const previewUrl = ref('');
const removeImage = ref(false);

const required = () => [{ required: true, message: s('This field is required') }];
const rules = computed(() => ({
  name: required(),
  gender: required(),
  status: required(),
  email: [{ type: 'email', message: s('Enter a valid email address') }],
}));

const clients = ref([]);
const clientOptions = computed(() => clients.value.map(c => ({ value: c.id, label: c.name })));

function pickImage(file) {
  imageFile.value = file;
  removeImage.value = false;
  previewUrl.value = window.URL.createObjectURL(file);
  return false;
}

function clearImage() {
  imageFile.value = null;
  previewUrl.value = '';
  removeImage.value = true;
}

function back() {
  router.push(isEdit.value ? `/hospital/patients/${route.params.id}` : '/hospital/patients');
}

async function load() {
  loading.value = true;
  try {
    const [record, clientList] = await Promise.all([
      isEdit.value ? http.get(`hospital/patients/${route.params.id}/edit`) : Promise.resolve(null),
      // Same list endpoint the CRM pages use; -1 is the "all rows" convention.
      http.get('clients', { page: 1, SortField: 'id', SortType: 'asc', search: '', limit: -1 })
        .catch(() => null),
    ]);

    clients.value = clientList?.clients || [];

    if (record?.patient) {
      const p = record.patient;
      form.value = {
        ...form.value,
        ...p,
        blood_group: p.blood_group || undefined,
        client_id: p.client_id || undefined,
      };
      previewUrl.value = p.image_url || '';
    }
  } catch (e) {
    message.error(s('Could not load this patient'));
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
    if (['id', 'image', 'image_url', 'age', 'created_at', 'updated_at', 'deleted_at'].includes(key)) return;
    fd.append(key, value);
  });
  if (imageFile.value) fd.append('image', imageFile.value);
  if (removeImage.value) fd.append('remove_image', '1');

  saving.value = true;
  try {
    const url = isEdit.value ? `hospital/patients/${route.params.id}/update` : 'hospital/patients';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(isEdit.value
        ? s('Updated successfully')
        : s('Patient registered — {mrn}', { mrn: res.data?.mrn || '' }));
      router.push(isEdit.value ? `/hospital/patients/${route.params.id}` : `/hospital/patients/${res.data.id}`);
      return;
    }
    // uploadForm resolves on 422 instead of throwing.
    message.error(firstError(res.data) || s('Could not save this patient'));
  } catch (e) {
    message.error(s('Could not save this patient'));
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
.form-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}
</style>
