<template>
  <div class="page">
    <PageHeader :title="$t('module_settings')" :breadcrumb="[$t('Settings'), $t('module_settings')]" />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Upload module -->
      <a-card :title="'Upload Module'" style="margin-bottom: 16px">
        <div style="max-width: 640px; margin: 0 auto">
          <a-upload-dragger
            :file-list="moduleZip ? [{ uid: '-1', name: moduleZip.name + ' (' + formatFileSize(moduleZip.size) + ')', status: 'done' }] : []"
            :before-upload="onZipPicked"
            accept=".zip"
            :max-count="1"
            @remove="removeFile"
          >
            <p class="ant-upload-drag-icon"><InboxOutlined /></p>
            <p class="ant-upload-text">{{ $t('Choose_files_or_drop_them_here') }}</p>
            <p class="ant-upload-hint">.zip</p>
          </a-upload-dragger>
          <div style="display: flex; justify-content: center; margin-top: 16px">
            <a-button type="primary" :loading="submitProcessing" :disabled="!moduleZip" @click="uploadModule">
              <UploadOutlined /> {{ $t('submit') }}
            </a-button>
          </div>
        </div>
      </a-card>

      <!-- Installed modules -->
      <template v-if="modulesInfo.length > 0">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 8px">
          <h4 style="margin: 0">Installed Modules</h4>
          <a-segmented
            v-model:value="filter"
            :options="[
              { value: 'all', label: 'All (' + modulesInfo.length + ')' },
              { value: 'active', label: 'Active (' + activeCount + ')' },
              { value: 'inactive', label: 'Inactive (' + inactiveCount + ')' },
            ]"
          />
        </div>

        <a-row :gutter="[16, 16]">
          <a-col v-for="m in filteredModules" :key="m.module_name" :xs="24" :md="12" :lg="8">
            <a-card size="small" style="height: 100%">
              <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px">
                <a-avatar shape="square" :size="44" :style="{ background: m.status ? 'rgba(109, 40, 217, 0.12)' : 'rgba(0,0,0,0.06)', color: m.status ? '#6d28d9' : '#999' }">
                  <template #icon><AppstoreOutlined /></template>
                </a-avatar>
                <a-tag :color="m.status ? 'success' : 'default'">{{ m.status ? $t('Active') : $t('Inactive') }}</a-tag>
              </div>
              <div style="font-weight: 600; margin-bottom: 4px">{{ formatModuleName(m.module_name) }}</div>
              <div style="font-size: 12px; color: #8c8c8c; min-height: 40px">{{ getModuleDescription(m.module_name) }}</div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 12px">
                <a-tag><TagOutlined /> v{{ m.current_version }}</a-tag>
                <span>
                  <a-switch
                    :checked="!!m.status"
                    :loading="togglingModule === m.module_name"
                    size="small"
                    @change="v => toggleModule(m, v)"
                  />
                  <span style="margin-left: 8px; font-size: 12px" :style="{ color: m.status ? '#52c41a' : '#8c8c8c' }">
                    {{ m.status ? 'Enabled' : 'Disabled' }}
                  </span>
                </span>
              </div>
            </a-card>
          </a-col>
        </a-row>
      </template>

      <a-empty v-else description="No Modules Installed">
        <div style="color: #8c8c8c; font-size: 12px">
          Upload a module zip file above to get started.
        </div>
      </a-empty>
    </template>
  </div>
</template>

<script setup>
/**
 * Module settings — GET get_modules_info → [{module_name, status,
 * current_version}]; POST update_status_module {status, name} then a FULL
 * PAGE RELOAD after 1s (legacy: modules change routes/menus, a reload is
 * required); POST upload_module (FormData module_zip, .zip only) then
 * reload. Icons/descriptions are the legacy hardcoded map.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  InboxOutlined, UploadOutlined, AppstoreOutlined, TagOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();

const isLoading = ref(true);
const submitProcessing = ref(false);
const modulesInfo = ref([]);
const moduleZip = ref(null);
const filter = ref('all');
const togglingModule = ref(null);

const activeCount = computed(() => modulesInfo.value.filter(m => m.status).length);
const inactiveCount = computed(() => modulesInfo.value.filter(m => !m.status).length);
const filteredModules = computed(() => {
  if (filter.value === 'active') return modulesInfo.value.filter(m => m.status);
  if (filter.value === 'inactive') return modulesInfo.value.filter(m => !m.status);
  return modulesInfo.value;
});

function formatFileSize(bytes) {
  if (!bytes) return '0 B';
  const k = 1024; const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}
function formatModuleName(name) {
  return name.replace(/([A-Z])/g, ' $1').replace(/^[\s]/, '').trim();
}
function getModuleDescription(name) {
  const descriptions = {
    ApiDocs: 'Interactive API documentation with code examples and endpoint reference.',
    WooCommerceSync: 'Sync products and stock with your WooCommerce store.',
    Ecommerce: 'Full-featured online store with product catalog and checkout.',
    HRM: 'Human resource management with employees, attendance, and payroll.',
    Commission: 'Sales agent commission programs, rules, and tracking.',
    Contracts: 'Contract management with templates, tasks, and attachments.',
    Bookings: 'Appointment booking and service job management.',
    Reports: 'Advanced reporting and business analytics.',
    Recruit: 'Recruitment management with jobs, candidates, applications, interviews and reports.',
  };
  return descriptions[name] || 'Extends your Stocky application with additional functionality.';
}

function onZipPicked(file) {
  if (!file.name.endsWith('.zip')) {
    message.error('Please upload a .zip file');
    return false;
  }
  moduleZip.value = file;
  return false;
}
function removeFile() {
  moduleZip.value = null;
  return false;
}

async function toggleModule(m, newStatus) {
  togglingModule.value = m.module_name;
  m.status = newStatus;
  try {
    await http.post('update_status_module', { status: m.status, name: m.module_name });
    if (m.status) message.success(t('Module_enabled_success') || 'Module enabled successfully');
    else message.warning(t('Module_Disabled_success') || 'Module disabled successfully');
    togglingModule.value = null;
    // Modules add/remove routes, menus and permissions — a full reload is required.
    setTimeout(() => { window.location.reload(); }, 1000);
  } catch (e) {
    m.status = !m.status;
    togglingModule.value = null;
    message.error(t('Delete_Therewassomethingwronge'));
  }
}

async function uploadModule() {
  if (!moduleZip.value) {
    message.error(t('Please_Upload_the_Correct_Module') || 'Please upload a valid module');
    return;
  }
  submitProcessing.value = true;
  try {
    const fd = new FormData();
    fd.append('module_zip', moduleZip.value);
    const resp = await uploadForm('upload_module', fd);
    if (resp.status >= 400) throw new Error('HTTP ' + resp.status);
    moduleZip.value = null;
    message.success(t('Uploaded_Success') || 'Module installed successfully');
    setTimeout(() => { window.location.reload(); }, 1000);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    submitProcessing.value = false;
  }
}

onMounted(async () => {
  try {
    modulesInfo.value = await http.get('get_modules_info') || [];
  } catch (e) { /* empty grid */ }
  isLoading.value = false;
});
</script>
