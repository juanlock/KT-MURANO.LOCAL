<template>
  <div class="page">
    <PageHeader
      :title="editMode ? $t('Edit_Property') : $t('Add_Property')"
      :breadcrumb="[$t('Real_Estate'), editMode ? $t('Edit_Property') : $t('Add_Property')]"
    />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="[16, 16]">
      <!-- LEFT COLUMN -->
      <a-col :xs="24" :lg="16">
        <a-card size="small" :title="$t('Property_Information')" style="margin-bottom: 16px">
          <a-form layout="vertical">
            <a-form-item
              :label="$t('Property_Title') + ' *'"
              :validate-status="submitted && !form.title ? 'error' : ''"
            >
              <a-input v-model:value="form.title" />
            </a-form-item>
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Property_Type')">
                  <a-select
                    v-model:value="form.property_category_id" :placeholder="$t('Select')"
                    :options="categories.map(c => ({ label: c.name, value: c.id }))"
                    show-search option-filter-prop="label" allow-clear
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Purpose')">
                  <a-select v-model:value="form.purpose">
                    <a-select-option value="sale">{{ $t('For_Sale') }}</a-select-option>
                    <a-select-option value="rent">{{ $t('For_Rent') }}</a-select-option>
                  </a-select>
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Status')">
                  <a-select v-model:value="form.status">
                    <a-select-option value="available">{{ $t('Available') }}</a-select-option>
                    <a-select-option value="sold">{{ $t('Sold') }}</a-select-option>
                    <a-select-option value="rented">{{ $t('Rented') }}</a-select-option>
                  </a-select>
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="form.description" :rows="5" />
            </a-form-item>
          </a-form>
        </a-card>

        <a-card size="small" :title="$t('Specifications')" style="margin-bottom: 16px">
          <a-row :gutter="16">
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Price')">
                <a-input-number v-model:value="form.price" :min="0" :step="0.01" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Area')">
                <a-input-number v-model:value="form.area" :min="0" :step="0.01" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Area_Unit')">
                <a-input v-model:value="form.area_unit" placeholder="m²" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Bedrooms')">
                <a-input-number v-model:value="form.bedrooms" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Bathrooms')">
                <a-input-number v-model:value="form.bathrooms" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Garage')">
                <a-input-number v-model:value="form.garage" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <a-card size="small" :title="$t('Location')" style="margin-bottom: 16px">
          <a-form-item :label="$t('Address')">
            <a-input v-model:value="form.address" />
          </a-form-item>
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('City')">
                <a-input v-model:value="form.city" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Region')">
                <a-input v-model:value="form.region" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="$t('Latitude')">
                <a-input v-model:value="form.latitude" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="$t('Longitude')">
                <a-input v-model:value="form.longitude" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <a-card size="small" :title="$t('Features_Amenities')" style="margin-bottom: 16px">
          <div style="margin-bottom: 12px">
            <a-tag
              v-for="(a, i) in form.amenities" :key="i"
              closable color="blue" style="margin-bottom: 6px"
              @close="form.amenities.splice(i, 1)"
            >
              {{ a }}
            </a-tag>
          </div>
          <a-input-group compact style="display: flex">
            <a-input v-model:value="amenityInput" :placeholder="$t('Add_Amenity')" @press-enter="addAmenity" />
            <a-button type="primary" @click="addAmenity">{{ $t('Add') }}</a-button>
          </a-input-group>
        </a-card>

        <a-card size="small" title="SEO" style="margin-bottom: 16px">
          <a-form-item :label="$t('SEO_Title')">
            <a-input v-model:value="form.seo_title" />
          </a-form-item>
          <a-form-item :label="$t('SEO_Description')">
            <a-textarea v-model:value="form.seo_description" :rows="2" />
          </a-form-item>
          <a-form-item :label="$t('SEO_Keywords')" style="margin-bottom: 0">
            <a-input v-model:value="form.seo_keywords" :placeholder="$t('Comma_separated')" />
          </a-form-item>
        </a-card>
      </a-col>

      <!-- RIGHT COLUMN -->
      <a-col :xs="24" :lg="8">
        <a-card size="small" :title="$t('Featured_Image')" style="margin-bottom: 16px">
          <img v-if="featuredPreview" :src="featuredPreview" style="max-height: 180px; max-width: 100%; border-radius: 8px; margin-bottom: 10px" />
          <a-upload
            :file-list="[]" :before-upload="() => false" :max-count="1"
            accept="image/*" @change="onFeaturedChange"
          >
            <a-button>
              <template #icon><UploadOutlined /></template>
              {{ $t('Choose_a_file') }}
            </a-button>
          </a-upload>
        </a-card>

        <a-card size="small" :title="$t('Gallery')" style="margin-bottom: 16px">
          <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px">
            <div v-for="(g, i) in existingGallery" :key="'e' + i" class="gallery-tile">
              <img :src="'/' + g" />
              <a-button size="small" danger shape="circle" class="gallery-x" @click="existingGallery.splice(i, 1)">×</a-button>
            </div>
            <div v-for="(g, i) in galleryPreviews" :key="'n' + i" class="gallery-tile">
              <img :src="g" />
              <a-button size="small" danger shape="circle" class="gallery-x" @click="removeNew(i)">×</a-button>
            </div>
          </div>
          <a-upload
            :file-list="[]" :before-upload="() => false" multiple
            accept="image/*" @change="onGalleryChange"
          >
            <a-button>
              <template #icon><UploadOutlined /></template>
              {{ $t('Choose_files') }}
            </a-button>
          </a-upload>
        </a-card>

        <a-card size="small" style="margin-bottom: 16px">
          <a-switch v-model:checked="form.featured" />
          <span style="margin-left: 8px">{{ $t('Mark_as_Featured') }}</span>
        </a-card>

        <a-card size="small" :title="$t('Agent_Information')" style="margin-bottom: 16px">
          <a-form layout="vertical">
            <a-form-item :label="$t('Agent_Name')">
              <a-input v-model:value="form.agent_name" />
            </a-form-item>
            <a-form-item :label="$t('Phone')">
              <a-input v-model:value="form.agent_phone" />
            </a-form-item>
            <a-form-item :label="$t('Email')">
              <a-input v-model:value="form.agent_email" />
            </a-form-item>
            <a-form-item label="WhatsApp" style="margin-bottom: 0">
              <a-input v-model:value="form.agent_whatsapp" />
            </a-form-item>
          </a-form>
        </a-card>

        <a-card size="small">
          <a-space direction="vertical" style="width: 100%">
            <a-button type="primary" block :loading="saving" @click="submit">
              {{ editMode ? $t('Update') : $t('Save') }}
            </a-button>
            <a-button block @click="$router.push('/realestate/properties')">{{ $t('Cancel') }}</a-button>
          </a-space>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Property create/edit — bootstrap realestate/categories_all (+
 * realestate/properties/{id} on edit → {property}). Save is MULTIPART POST
 * to realestate/properties[/{id}] (edit is POST too, no _method — legacy):
 * scalars skipped when empty, featured 1|0, amenities + gallery_existing as
 * JSON strings, featured_image_file, gallery_files[]. Only title required.
 */
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = route.params.id || null;
const editMode = !!id;

const isLoading = ref(true);
const saving = ref(false);
const submitted = ref(false);
const categories = ref([]);
const amenityInput = ref('');
const featuredFile = ref(null);
const featuredPreview = ref(null);
const newGalleryFiles = ref([]);
const galleryPreviews = ref([]);
const existingGallery = ref([]);

const form = ref({
  title: '', property_category_id: null, description: '',
  purpose: 'sale', status: 'available', featured: false,
  price: null, area: null, area_unit: 'm²', bedrooms: null, bathrooms: null, garage: null,
  address: '', city: '', region: '', latitude: '', longitude: '',
  amenities: [],
  agent_name: '', agent_phone: '', agent_email: '', agent_whatsapp: '',
  seo_title: '', seo_description: '', seo_keywords: '',
});

function addAmenity() {
  const v = (amenityInput.value || '').trim();
  if (v && !form.value.amenities.includes(v)) form.value.amenities.push(v);
  amenityInput.value = '';
}
function onFeaturedChange({ file }) {
  const f = file.originFileObj || file;
  if (!f) return;
  featuredFile.value = f;
  featuredPreview.value = URL.createObjectURL(f);
}
function onGalleryChange({ file }) {
  const f = file.originFileObj || file;
  if (!f) return;
  newGalleryFiles.value.push(f);
  galleryPreviews.value.push(URL.createObjectURL(f));
}
function removeNew(i) {
  newGalleryFiles.value.splice(i, 1);
  galleryPreviews.value.splice(i, 1);
}

function buildFormData() {
  const fd = new FormData();
  const f = form.value;
  const scalar = ['title', 'property_category_id', 'description', 'purpose', 'status',
    'price', 'area', 'area_unit', 'bedrooms', 'bathrooms', 'garage',
    'address', 'city', 'region', 'latitude', 'longitude',
    'agent_name', 'agent_phone', 'agent_email', 'agent_whatsapp',
    'seo_title', 'seo_description', 'seo_keywords'];
  scalar.forEach(k => {
    if (f[k] !== null && f[k] !== undefined && f[k] !== '') fd.append(k, f[k]);
  });
  fd.append('featured', f.featured ? 1 : 0);
  fd.append('amenities', JSON.stringify(f.amenities || []));
  fd.append('gallery_existing', JSON.stringify(existingGallery.value || []));
  if (featuredFile.value) fd.append('featured_image_file', featuredFile.value);
  newGalleryFiles.value.forEach(file => fd.append('gallery_files[]', file));
  return fd;
}

async function submit() {
  submitted.value = true;
  if (!form.value.title) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  saving.value = true;
  try {
    const url = editMode ? `realestate/properties/${id}` : 'realestate/properties';
    const { status, data } = await uploadForm(url, buildFormData());
    if (status >= 200 && status < 300) {
      message.success(t('Created_in_successfully'));
      router.push('/realestate/properties');
    } else {
      message.error(data?.message || t('InvalidData'));
      saving.value = false;
    }
  } catch (e) {
    message.error(t('InvalidData'));
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const catData = await http.get('realestate/categories_all');
    categories.value = catData.categories || [];
    if (editMode) {
      const data = await http.get(`realestate/properties/${id}`);
      const p = data.property;
      form.value = {
        ...form.value,
        title: p.title,
        property_category_id: p.property_category_id,
        description: p.description || '',
        purpose: p.purpose || 'sale',
        status: p.status || 'available',
        featured: !!p.featured,
        price: p.price, area: p.area, area_unit: p.area_unit || 'm²',
        bedrooms: p.bedrooms, bathrooms: p.bathrooms, garage: p.garage,
        address: p.address || '', city: p.city || '', region: p.region || '',
        latitude: p.latitude, longitude: p.longitude,
        amenities: Array.isArray(p.amenities) ? p.amenities : [],
        agent_name: p.agent_name || '', agent_phone: p.agent_phone || '',
        agent_email: p.agent_email || '', agent_whatsapp: p.agent_whatsapp || '',
        seo_title: p.seo_title || '', seo_description: p.seo_description || '',
        seo_keywords: p.seo_keywords || '',
      };
      existingGallery.value = Array.isArray(p.gallery) ? p.gallery.slice() : [];
      if (p.featured_image) featuredPreview.value = `/${p.featured_image}`;
    }
  } catch (e) {
    message.error(t('InvalidData'));
  }
  isLoading.value = false;
});
</script>

<style scoped>
.gallery-tile {
  position: relative;
}
.gallery-tile img {
  width: 80px;
  height: 62px;
  object-fit: cover;
  border-radius: 6px;
  display: block;
}
.gallery-x {
  position: absolute;
  top: -8px;
  right: -8px;
  min-width: 22px;
  width: 22px;
  height: 22px;
  padding: 0;
  line-height: 1;
}
</style>
