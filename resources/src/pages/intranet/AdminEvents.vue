<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_Events')" :breadcrumb="[$t('Intranet_Management'), $t('Intranet_Events')]">
      <template #extra>
        <a-segmented v-model:value="scope" :options="scopeOptions" @change="load" />
        <a-input-search v-model:value="search" :placeholder="$t('Intranet_Search')" allow-clear style="width: 200px" @search="load" />
        <a-button type="primary" @click="openForm()"><template #icon><PlusOutlined /></template>{{ $t('Intranet_Add_Event') }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <a-table :data-source="rows" :columns="columns" :loading="loading" row-key="id" size="middle" :pagination="{ pageSize: 25, hideOnSinglePage: true }" :scroll="{ x: 800 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'title'">
            <span class="swatch" :style="{ background: record.color || '#1677ff' }"></span>
            <a @click="openForm(record)">{{ record.title }}</a>
            <div v-if="record.description" class="muted desc">{{ record.description }}</div>
          </template>
          <template v-else-if="column.key === 'when'">
            {{ eventWhen(record) }}
            <a-tag v-if="record.all_day" style="margin-left: 6px">{{ $t('Intranet_All_Day') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'location'">{{ record.location }}</template>
          <template v-else-if="column.key === 'action'">
            <a-space>
              <a-button size="small" @click="openForm(record)"><template #icon><EditOutlined /></template></a-button>
              <a-popconfirm :title="$t('Delete_Title')" :ok-text="$t('Delete_confirmButtonText')" :cancel-text="$t('Delete_cancelButtonText')" @confirm="remove(record)">
                <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal :open="formOpen" :title="form.id ? $t('Intranet_Edit_Event') : $t('Intranet_Add_Event')" :confirm-loading="saving" :ok-text="$t('Intranet_Save')" :cancel-text="$t('Intranet_Cancel')" width="640px" @ok="save" @cancel="formOpen = false">
      <a-form layout="vertical">
        <a-form-item :label="$t('Intranet_Title')" required><a-input v-model:value="form.title" :maxlength="190" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="16"><a-form-item :label="$t('Intranet_Location')"><a-input v-model:value="form.location" :maxlength="190" /></a-form-item></a-col>
          <a-col :span="4"><a-form-item :label="$t('Intranet_All_Day')"><a-switch v-model:checked="form.all_day" /></a-form-item></a-col>
          <a-col :span="4"><a-form-item :label="$t('Intranet_Color')"><input v-model="form.color" type="color" class="color" /></a-form-item></a-col>
        </a-row>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Intranet_Starts')" required>
              <a-date-picker v-model:value="form.starts_at" :show-time="form.all_day ? false : { format: 'HH:mm' }" :format="form.all_day ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH:mm'" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Intranet_Ends')">
              <a-date-picker v-model:value="form.ends_at" :show-time="form.all_day ? false : { format: 'HH:mm' }" :format="form.all_day ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH:mm'" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Intranet_Description')"><a-textarea v-model:value="form.description" :maxlength="5000" :auto-size="{ minRows: 3, maxRows: 8 }" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** Intranet Management -> Events (intranet_manage): shown on the intranet calendar and home page. */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { eventWhen, apiError } from './intranetShared';

const { t } = useI18n();
const loading = ref(true);
const rows = ref([]);
const scope = ref('upcoming');
const search = ref('');
// Fixed chronological order (not alphabetical): upcoming / past / all.
const scopeOptions = computed(() => [
  { value: 'upcoming', label: t('Intranet_Upcoming_Events') },
  { value: 'past', label: t('Intranet_Past_Events') },
  { value: 'all', label: t('All') },
]);
const columns = computed(() => [
  { title: t('Intranet_Title'), key: 'title' },
  { title: t('Intranet_Date'), key: 'when', width: 300 },
  { title: t('Intranet_Location'), key: 'location', width: 200 },
  { title: t('Intranet_Actions'), key: 'action', width: 110, align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    rows.value = (await http.get('intranet-admin/events', { scope: scope.value, search: search.value || undefined })).data || [];
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

const formOpen = ref(false);
const saving = ref(false);
const form = ref({});
function openForm(row) {
  form.value = row
    ? { id: row.id, title: row.title, description: row.description || '', location: row.location || '', starts_at: row.starts_at, ends_at: row.ends_at, all_day: !!row.all_day, color: row.color || '#1677ff' }
    : { id: null, title: '', description: '', location: '', starts_at: dayjs().add(1, 'day').hour(9).minute(0).second(0).format('YYYY-MM-DD HH:mm:ss'), ends_at: null, all_day: false, color: '#1677ff' };
  formOpen.value = true;
}

async function save() {
  const f = form.value;
  if (!f.title.trim() || !f.starts_at) { message.warning(`${t('Intranet_Title')} / ${t('Intranet_Starts')}: ${t('Required')}`); return; }
  saving.value = true;
  try {
    const body = {
      title: f.title.trim(), description: f.description || null, location: f.location || null,
      starts_at: f.starts_at, ends_at: f.ends_at || null, all_day: f.all_day, color: f.color || null,
    };
    if (f.id) await http.put(`intranet-admin/events/${f.id}`, body);
    else await http.post('intranet-admin/events', body);
    message.success(t('Saved_successfully'));
    formOpen.value = false;
    load();
  } catch (e) {
    message.error(apiError(e));
  } finally {
    saving.value = false;
  }
}

async function remove(row) {
  try {
    await http.delete(`intranet-admin/events/${row.id}`);
    message.success(t('Deleted_in_successfully'));
    load();
  } catch (e) {
    message.error(apiError(e, t('Delete_Therewassomethingwronge')));
  }
}

onMounted(load);
</script>

<style scoped>
.swatch { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
.desc { max-width: 460px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.color { width: 44px; height: 32px; padding: 0; border: 1px solid #d9d9d9; border-radius: 6px; background: none; cursor: pointer; }
</style>
