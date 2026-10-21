<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? s('Edit student') : s('Admit student')"
      :breadcrumb="[s('School'), s('Students'), isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #actions>
        <a-button @click="back">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-card size="small" :title="s('Student')" style="margin-bottom: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Full name *')" name="name">
                  <a-input v-model:value="form.name" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item
                  :label="s('Admission number')"
                  :extra="isEdit ? null : s('Leave empty and one is issued automatically')"
                >
                  <a-input v-model:value="form.admission_number" placeholder="ADM-000001" allow-clear />
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
                <a-form-item :label="s('Admitted on')" name="admission_date">
                  <a-date-picker v-model:value="form.admission_date" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="s('Status *')" name="status">
                  <a-select v-model:value="form.status" :options="statusOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="s('Blood group')">
                  <a-input v-model:value="form.blood_group" :placeholder="s('e.g. O+')" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="s('Phone')">
                  <a-input v-model:value="form.phone" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Email')" name="email">
                  <a-input v-model:value="form.email" allow-clear />
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

          <a-card size="small" :title="s('Placement')" style="margin-bottom: 16px">
            <a-alert
              type="info" show-icon banner
              :message="currentYearName
                ? s('Placing this student creates their enrolment for {year}.', { year: currentYearName })
                : s('No academic year is marked current — set one up before placing students.')"
              style="margin-bottom: 16px"
            />
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('Class')">
                  <a-select
                    v-model:value="form.class_id" allow-clear show-search option-filter-prop="label"
                    :options="classOptions" :placeholder="s('Not placed yet')" @change="onClassChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Section')">
                  <a-select
                    v-model:value="form.section_id" allow-clear show-search option-filter-prop="label"
                    :options="sectionOptions" :disabled="!form.class_id" :placeholder="s('Any')"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Roll number')">
                  <a-input v-model:value="form.roll_number" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-card size="small" :title="s('Guardian')">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="s('Name')">
                  <a-input v-model:value="form.guardian_name" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Relationship')">
                  <a-input v-model:value="form.guardian_relation" :placeholder="s('e.g. Mother')" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="s('Phone')">
                  <a-input v-model:value="form.guardian_phone" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Email')" name="guardian_email">
                  <a-input v-model:value="form.guardian_email" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="s('Occupation')" style="margin-bottom: 0">
                  <a-input v-model:value="form.guardian_occupation" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="s('Photo')" style="margin-bottom: 16px">
            <div class="photo">
              <img v-if="previewUrl" :src="previewUrl" :alt="s('Student')" />
              <span v-else class="photo-empty"><UserOutlined /></span>
            </div>
            <a-space>
              <a-upload :show-upload-list="false" accept="image/*" :before-upload="pickImage">
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ previewUrl ? s('Replace') : s('Upload') }}
                </a-button>
              </a-upload>
              <a-button v-if="previewUrl" danger @click="clearImage">{{ $t('Delete') }}</a-button>
            </a-space>
          </a-card>

          <a-card size="small" :title="s('Medical')" style="margin-bottom: 16px">
            <a-form-item style="margin-bottom: 0">
              <a-textarea
                v-model:value="form.medical_notes" :rows="3"
                :placeholder="s('Allergies, conditions, medication…')"
              />
            </a-form-item>
          </a-card>

          <a-card size="small" :title="s('Links')" style="margin-bottom: 16px">
            <a-form-item
              :label="$t('Customer')"
              :extra="s('Link to the CRM customer who pays the fees')"
              style="margin-bottom: 0"
            >
              <a-select
                v-model:value="form.client_id" allow-clear show-search
                option-filter-prop="label" :options="clientOptions" :placeholder="s('Not linked')"
              />
            </a-form-item>
          </a-card>

          <a-card size="small" :title="$t('Note')">
            <a-textarea v-model:value="form.notes" :rows="4" />
          </a-card>
        </a-col>
      </a-row>

      <div class="form-foot">
        <a-button @click="back">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" size="large" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </div>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Admit / edit a student.
 *
 * Always posts multipart (the photo rides along), so the edit path uses
 * POST school/students/{id}/update rather than PUT — PHP cannot parse a
 * multipart body off a PUT request.
 *
 * Placement is part of this form because admitting a student and putting them
 * in a class is one act at a desk; the backend turns it into the enrolment row.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { UploadOutlined, UserOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { GENDERS, STUDENT_STATUSES } from './schoolOptions';
import { t } from '../../i18n';
import { useSchoolText } from './lang/useSchoolText';
import ES from './lang/StudentForm.es.js';

const { s } = useSchoolText(ES);

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const formRef = ref();
const loading = ref(false);
const saving = ref(false);

const form = ref({
  name: '', admission_number: '', gender: 'male', date_of_birth: null,
  admission_date: new Date().toISOString().slice(0, 10), status: 'active',
  blood_group: '', phone: '', email: '', address: '', city: '', national_id: '',
  medical_notes: '',
  guardian_name: '', guardian_relation: '', guardian_phone: '',
  guardian_email: '', guardian_occupation: '',
  class_id: undefined, section_id: undefined, roll_number: '',
  client_id: undefined, notes: '',
});

const imageFile = ref(null);
const previewUrl = ref('');
const removeImage = ref(false);

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  name: required(),
  gender: required(),
  status: required(),
  email: [{ type: 'email', message: t('InvalidData', 'Enter a valid email address') }],
  guardian_email: [{ type: 'email', message: t('InvalidData', 'Enter a valid email address') }],
}));

const meta = ref({});
const clients = ref([]);
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const sectionOptions = computed(() => (meta.value.sections || [])
  .filter(sec => sec.class_id === form.value.class_id)
  .map(sec => ({ value: sec.id, label: sec.name })));
const genderOptions = computed(() => GENDERS.map(o => ({ ...o, label: s(o.label) })));
const statusOptions = computed(() => STUDENT_STATUSES.map(o => ({ ...o, label: s(o.label) })));
const clientOptions = computed(() => clients.value.map(c => ({ value: c.id, label: c.name })));
const currentYearName = computed(() => {
  const years = meta.value.academic_years || [];
  const current = years.find(y => y.id === meta.value.current_year_id);
  return current ? current.name : null;
});

function onClassChange() {
  form.value.section_id = undefined;
}

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
  router.push(isEdit.value ? `/school/students/${route.params.id}` : '/school/students');
}

async function load() {
  loading.value = true;
  try {
    const [metaData, record, clientList] = await Promise.all([
      http.get('school/meta').catch(() => ({})),
      isEdit.value ? http.get(`school/students/${route.params.id}/edit`) : Promise.resolve(null),
      http.get('clients', { page: 1, SortField: 'id', SortType: 'asc', search: '', limit: -1 }).catch(() => null),
    ]);

    meta.value = metaData || {};
    clients.value = clientList?.clients || [];

    if (record?.student) {
      const st = record.student;
      form.value = {
        ...form.value,
        ...st,
        class_id: st.class_id || undefined,
        section_id: st.section_id || undefined,
        client_id: st.client_id || undefined,
      };
      previewUrl.value = st.image_url || '';
    }
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this student'));
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
    const url = isEdit.value ? `school/students/${route.params.id}/update` : 'school/students';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(isEdit.value
        ? t('Updated_in_successfully', 'Updated successfully')
        : s('Student admitted — {adm}', { adm: res.data?.admission_number || '' }));
      router.push(isEdit.value ? `/school/students/${route.params.id}` : `/school/students/${res.data.id}`);
      return;
    }
    // uploadForm resolves on 422 instead of throwing.
    message.error(firstError(res.data) || t('InvalidData', 'Could not save this student'));
  } catch (e) {
    message.error(t('InvalidData', 'Could not save this student'));
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
