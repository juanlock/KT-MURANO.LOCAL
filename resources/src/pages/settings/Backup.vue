<template>
  <div class="page">
    <!-- No PageHeader actions: the header is hidden when this page is
         embedded in System Settings — Generate lives on the card instead. -->
    <PageHeader :title="$t('Backup')" :breadcrumb="[$t('Settings'), $t('Backup')]" />

    <a-card size="small" :title="$t('Backup')" :body-style="{ padding: 0 }" style="margin-bottom: 16px">
      <template #extra>
        <a-button type="primary" :loading="generating" @click="generate">
          <template #icon><CloudDownloadOutlined /></template>
          {{ $t('GenerateBackup') || 'Generate Backup' }}
        </a-button>
      </template>
      <a-table
        :columns="columns" :data-source="backups" :loading="loading"
        :pagination="false" :row-key="r => r.date" size="middle"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'actions'">
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
        </template>
      </a-table>
    </a-card>

    <!-- Cloud storage destination -->
    <a-card size="small" :title="'Cloud Backup'" v-if="setting.id">
      <a-form layout="vertical">
        <a-row :gutter="16">
          <a-col :span="24">
            <a-checkbox v-model:checked="setting.backup_cloud_enabled" style="margin-bottom: 12px">
              Enable cloud backup
            </a-checkbox>
          </a-col>
          <template v-if="setting.backup_cloud_enabled">
            <a-col :xs="24" :md="8">
              <a-form-item label="Provider">
                <a-select
                  v-model:value="setting.backup_cloud_provider"
                  :options="[
                    { value: 's3', label: 'Amazon S3' },
                    { value: 'gdrive', label: 'Google Drive' },
                  ]"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item label="Path">
                <a-input v-model:value="setting.backup_cloud_path" />
              </a-form-item>
            </a-col>
            <template v-if="setting.backup_cloud_provider === 's3'">
              <a-col :xs="24" :md="8">
                <a-form-item label="S3 Bucket"><a-input v-model:value="setting.backup_s3_bucket" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="S3 Region"><a-input v-model:value="setting.backup_s3_region" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Access Key"><a-input v-model:value="setting.backup_s3_access_key" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Secret Key">
                  <a-input-password v-model:value="setting.backup_s3_secret_key" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Endpoint"><a-input v-model:value="setting.backup_s3_endpoint" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label=" ">
                  <a-checkbox v-model:checked="setting.backup_s3_path_style">Path-style endpoint</a-checkbox>
                </a-form-item>
              </a-col>
            </template>
            <template v-else-if="setting.backup_cloud_provider === 'gdrive'">
              <a-col :xs="24" :md="8">
                <a-form-item label="Folder ID"><a-input v-model:value="setting.backup_gdrive_folder_id" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Client ID"><a-input v-model:value="setting.backup_gdrive_client_id" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label="Client Secret">
                  <a-input-password v-model:value="setting.backup_gdrive_client_secret" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Access Token">
                  <a-input-password v-model:value="setting.backup_gdrive_access_token" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item label="Refresh Token">
                  <a-input-password v-model:value="setting.backup_gdrive_refresh_token" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
            </template>
          </template>
        </a-row>
        <a-button type="primary" :loading="saving" @click="saveSettings">{{ $t('submit') }}</a-button>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * List GET get_backup → {backups (rows keyed by date), totalRows}; generate
 * GET generate_new_backup (answers {success:false, error|message} on failure
 * — surfaced verbatim); delete DELETE delete_backup/{date}. Cloud destination
 * lives in the main settings row: GET get_Settings_data?include_secrets=1,
 * saved via POST settings/{id} as FormData with backup_* fields (booleans
 * as 1/0). Field labels literal English like legacy.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { CloudDownloadOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const generating = ref(false);
const saving = ref(false);
const backups = ref([]);
const setting = ref({});

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Action'), key: 'actions', width: 90, align: 'center' },
]);

async function loadBackups() {
  loading.value = true;
  try {
    const data = await http.get('get_backup');
    backups.value = data.backups || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function loadSettings() {
  try {
    const data = await http.get('get_Settings_data', { include_secrets: 1 });
    const s = data.settings || {};
    setting.value = {
      ...s,
      backup_cloud_enabled: !!Number(s.backup_cloud_enabled),
      backup_s3_path_style: !!Number(s.backup_s3_path_style),
    };
  } catch (e) { /* cloud card stays hidden */ }
}

async function generate() {
  generating.value = true;
  try {
    const data = await http.get('generate_new_backup');
    if (data && data.success === false) {
      message.error(data.error || data.message || t('Failed'));
    } else {
      message.success(t('Success'));
    }
    loadBackups();
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
  } finally {
    generating.value = false;
  }
}

function remove(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: record.date,
    okType: 'danger',
    okText: t('Delete_confirmButtonText'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`delete_backup/${encodeURIComponent(record.date)}`);
        message.success(t('Deleted_in_successfully'));
        loadBackups();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

async function saveSettings() {
  saving.value = true;
  const s = setting.value;
  const fd = new FormData();
  fd.append('backup_cloud_enabled', s.backup_cloud_enabled ? 1 : 0);
  fd.append('backup_cloud_provider', s.backup_cloud_provider || '');
  fd.append('backup_cloud_path', s.backup_cloud_path || '');
  fd.append('backup_s3_bucket', s.backup_s3_bucket || '');
  fd.append('backup_s3_region', s.backup_s3_region || '');
  fd.append('backup_s3_access_key', s.backup_s3_access_key || '');
  fd.append('backup_s3_secret_key', s.backup_s3_secret_key || '');
  fd.append('backup_s3_endpoint', s.backup_s3_endpoint || '');
  fd.append('backup_s3_path_style', s.backup_s3_path_style ? 1 : 0);
  fd.append('backup_gdrive_folder_id', s.backup_gdrive_folder_id || '');
  fd.append('backup_gdrive_access_token', s.backup_gdrive_access_token || '');
  fd.append('backup_gdrive_refresh_token', s.backup_gdrive_refresh_token || '');
  fd.append('backup_gdrive_client_id', s.backup_gdrive_client_id || '');
  fd.append('backup_gdrive_client_secret', s.backup_gdrive_client_secret || '');
  try {
    await http.postForm(`settings/${s.id}`, fd);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || e?.data?.error || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadBackups();
  loadSettings();
});
</script>
