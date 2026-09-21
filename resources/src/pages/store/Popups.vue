<template>
  <div class="page">
    <PageHeader :title="$t('Popup_Messages')" :breadcrumb="[$t('Store'), $t('Popup_Messages')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert type="info" show-icon :message="$t('Popup_Messages_Help')" style="margin-bottom: 16px" />

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="popups" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_Popups') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'title'"><strong>{{ record.title || '—' }}</strong></template>
          <template v-else-if="column.key === 'type'">
            <a-tag color="cyan">{{ typeLabel(record.type) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'trigger'">
            {{ record.trigger }}<span v-if="record.trigger === 'delay'"> ({{ record.delay_seconds }}s)</span>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-switch :checked="!!record.enabled" @change="toggle(record)" />
            <span :style="{ marginLeft: '8px', color: record.enabled ? '#3f8600' : '#999' }">
              {{ record.enabled ? $t('Enabled') : $t('Disabled') }}
            </span>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="openEdit(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="confirmDelete(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="modalOpen" :title="form.id ? $t('Edit') : $t('Add')"
      :confirm-loading="saving" width="760px" @ok="submit"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="16">
            <a-form-item :label="$t('Title')">
              <a-input v-model:value="form.title" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Type')">
              <a-select v-model:value="form.type">
                <a-select-option value="announcement">{{ $t('Announcement') }}</a-select-option>
                <a-select-option value="subscription">{{ $t('Subscription') }}</a-select-option>
                <a-select-option value="sale">{{ $t('Sale') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Message')">
          <a-textarea v-model:value="form.message" :rows="3" />
        </a-form-item>
        <a-row v-if="form.type !== 'subscription'" :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Button_Label')">
              <a-input v-model:value="form.cta_label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Button_URL')">
              <a-input v-model:value="form.cta_url" placeholder="/online_store/flash-sales" />
            </a-form-item>
          </a-col>
        </a-row>
        <template v-if="localeList.length">
          <a-divider orientation="left">{{ $t('Translations') }}</a-divider>
          <div class="i18n-hint">{{ $t('Translations_Fallback_Hint') }}</div>
          <a-tabs v-model:activeKey="localeTab" size="small">
            <a-tab-pane v-for="l in localeList" :key="l.code" :tab="l.label">
              <a-form-item :label="$t('Title')">
                <a-input v-model:value="form.title_translations[l.code]" :placeholder="form.title" />
              </a-form-item>
              <a-form-item :label="$t('Message')">
                <a-textarea v-model:value="form.message_translations[l.code]" :rows="3" :placeholder="form.message" />
              </a-form-item>
              <a-form-item v-if="form.type !== 'subscription'" :label="$t('Button_Label')">
                <a-input v-model:value="form.cta_label_translations[l.code]" :placeholder="form.cta_label" />
              </a-form-item>
            </a-tab-pane>
          </a-tabs>
        </template>

        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Trigger')">
              <a-select v-model:value="form.trigger">
                <a-select-option value="delay">{{ $t('After_delay') }}</a-select-option>
                <a-select-option value="immediate">{{ $t('Immediately') }}</a-select-option>
                <a-select-option value="exit">{{ $t('Exit_intent') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col v-if="form.trigger === 'delay'" :xs="12" :md="8">
            <a-form-item :label="$t('Delay_seconds')">
              <a-input-number v-model:value="form.delay_seconds" :min="0" :max="120" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Frequency')">
              <a-select v-model:value="form.frequency">
                <a-select-option value="session">{{ $t('Once_per_session') }}</a-select-option>
                <a-select-option value="once">{{ $t('Once_ever') }}</a-select-option>
                <a-select-option value="always">{{ $t('Every_visit') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Starts')">
              <a-input v-model:value="form.starts_at" type="datetime-local" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Ends')">
              <a-input v-model:value="form.ends_at" type="datetime-local" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="16">
            <a-form-item :label="$t('Image')">
              <a-upload :file-list="imageList" :before-upload="() => false" :max-count="1" accept="image/*" @change="({ fileList }) => (imageList = fileList)">
                <a-button>
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Choose_a_file') }}
                </a-button>
              </a-upload>
              <img v-if="form.image_url" :src="form.image_url" style="max-height: 70px; margin-top: 8px; border-radius: 6px" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Sort_Order')">
              <a-input-number v-model:value="form.sort_order" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-switch v-model:checked="form.enabled" />
        <span style="margin-left: 8px">{{ form.enabled ? $t('Enabled') : $t('Disabled') }}</span>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Popup messages — GET store/popups → {popups}; save MULTIPART POST
 * store/popups[/{id}] (edit is POST too — legacy) with all fields, enabled
 * 1|0, optional image. Toggle re-POSTs the row's fields with enabled
 * flipped (legacy) — it sends no *_translations keys, which is exactly how
 * the server knows to leave the existing per-locale copy alone.
 * Storefront locales come back from the API as {locale: label}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const popups = ref([]);
const modalOpen = ref(false);
const imageList = ref([]);

// Storefront locales, from the API: [{code: 'fr', label: 'Français'}, …].
const localeList = ref([]);
const localeTab = ref('');
function setLocales(map) {
  if (!map) return;
  localeList.value = Object.keys(map).map(code => ({ code, label: map[code] }));
  if (!localeTab.value && localeList.value.length) localeTab.value = localeList.value[0].code;
}

const emptyForm = () => ({
  id: null, title: '', message: '', type: 'announcement', cta_label: '', cta_url: '',
  title_translations: {}, message_translations: {}, cta_label_translations: {},
  enabled: true, trigger: 'delay', delay_seconds: 3, frequency: 'session',
  starts_at: '', ends_at: '', sort_order: 0, image_url: null,
});
const form = ref(emptyForm());

const columns = computed(() => [
  { title: t('Title'), key: 'title' },
  { title: t('Type'), key: 'type', width: 130 },
  { title: t('Trigger'), key: 'trigger' },
  { title: t('Frequency'), dataIndex: 'frequency', key: 'frequency' },
  { title: t('Status'), key: 'status', width: 160 },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

function typeLabel(x) {
  return { announcement: t('Announcement'), subscription: t('Subscription'), sale: t('Sale') }[x] || x;
}

async function fetch() {
  try {
    const r = await http.get('store/popups');
    popups.value = r.popups || [];
    setLocales(r.locales);
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function openCreate() {
  form.value = emptyForm();
  imageList.value = [];
  modalOpen.value = true;
}
function openEdit(p) {
  form.value = {
    ...emptyForm(),
    ...p,
    enabled: !!p.enabled,
    // The API sends these as objects ({} when empty) — copy so edits stay local.
    title_translations: { ...(p.title_translations || {}) },
    message_translations: { ...(p.message_translations || {}) },
    cta_label_translations: { ...(p.cta_label_translations || {}) },
  };
  imageList.value = [];
  modalOpen.value = true;
}
function buildFormData() {
  const fd = new FormData();
  const f = form.value;
  const fields = ['title', 'message', 'type', 'cta_label', 'cta_url', 'trigger', 'delay_seconds', 'frequency', 'starts_at', 'ends_at', 'sort_order'];
  fields.forEach(k => {
    if (f[k] !== null && f[k] !== undefined) fd.append(k, f[k]);
  });
  fd.append('enabled', f.enabled ? 1 : 0);

  // Every locale key is sent, blanks included, so clearing a translation in the
  // form actually clears it server-side. The list's enable/disable toggle sends
  // none of these, which is how it leaves existing copy untouched.
  ['title', 'message', 'cta_label'].forEach(field => {
    localeList.value.forEach(l => {
      fd.append(`${field}_translations[${l.code}]`, (f[`${field}_translations`] || {})[l.code] || '');
    });
  });

  const img = imageList.value[0];
  if (img) fd.append('image', img.originFileObj || img);
  return fd;
}
async function submit() {
  saving.value = true;
  try {
    const url = form.value.id ? `store/popups/${form.value.id}` : 'store/popups';
    const { status } = await uploadForm(url, buildFormData());
    if (status >= 200 && status < 300) {
      message.success(t('Successfully_Updated'));
      modalOpen.value = false;
      fetch();
    } else {
      message.error(t('InvalidData'));
    }
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
async function toggle(p) {
  const fd = new FormData();
  ['title', 'message', 'type', 'cta_label', 'cta_url', 'trigger', 'delay_seconds', 'frequency', 'sort_order'].forEach(k => {
    if (p[k] != null) fd.append(k, p[k]);
  });
  fd.append('enabled', p.enabled ? 0 : 1);
  try {
    await uploadForm(`store/popups/${p.id}`, fd);
    fetch();
  } catch (e) {
    message.error(t('Failed'));
    fetch();
  }
}
function confirmDelete(p) {
  Modal.confirm({
    title: t('AreYouSure'),
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/popups/${p.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.i18n-hint {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 4px;
}
</style>
