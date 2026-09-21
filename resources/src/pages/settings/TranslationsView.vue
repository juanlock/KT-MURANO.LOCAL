<template>
  <div class="page">
    <PageHeader
      :title="`${$t('Translations for')} ${language?.name || locale}`"
      :breadcrumb="[$t('Settings'), $t('Languages'), locale]"
    >
      <template #actions>
        <a-space wrap>
          <a-button @click="$router.push('/settings/languages')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back') }}
          </a-button>
          <a-button type="primary" @click="addOpen = true">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add New') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-alert
      type="info" show-icon style="margin-bottom: 16px"
      :message="$t('Please reload the page after saving translations to apply the changes.')"
    />

    <a-card size="small" :body-style="{ padding: 0 }">
      <div style="padding: 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06)">
        <a-input-search
          v-model:value="search"
          :placeholder="$t('Search_this_table')"
          allow-clear
          style="max-width: 320px"
          @search="load(1)"
        />
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="loading"
        :pagination="{
          current: page, pageSize: perPage, total, showSizeChanger: false,
        }"
        :row-key="r => r.key" size="middle"
        @change="p => load(p.current)"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'value'">
            <a-input
              v-model:value="record.value"
              @blur="saveIfChanged(record)"
            />
          </template>
          <template v-else-if="column.key === 'actions'">
            <!-- English is the fallback locale, so legacy withheld delete
                 there: removing an `en` key leaves every other locale with no
                 base string to fall back to. The row's own locale wins, with
                 the route's locale as the fallback check. -->
            <a-tooltip v-if="(record.locale || locale) !== 'en'" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </template>
        </template>
      </a-table>
      <div class="showing">
        {{ $t('Showing') }} {{ rows.length }} of {{ total }} {{ $t('Translations') }}
      </div>
    </a-card>

    <a-modal
      v-model:open="addOpen"
      :title="$t('Add New Translation')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="addTranslation"
    >
      <a-form layout="vertical">
        <a-form-item label="Key">
          <a-input v-model:value="newEntry.key" />
        </a-form-item>
        <a-form-item label="Value">
          <a-input v-model:value="newEntry.value" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET translations_setting/{locale}?page&per_page&search → paginator
 * {data: [{key, value}], total, current_page, language}. Upsert one entry =
 * PUT translations_setting/{locale} {key, value} (fires on input blur when
 * changed, like legacy's per-row save); DELETE
 * translations_setting/{locale}/{key}. Changes apply after a page reload —
 * translations are served from the DB at boot.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, PlusOutlined, DeleteOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const locale = route.params.locale;

const loading = ref(true);
const rows = ref([]);
const original = ref({});
const total = ref(0);
const page = ref(1);
const perPage = 50;
const search = ref('');
const language = ref(null);

const columns = computed(() => [
  { title: 'Key', dataIndex: 'key', key: 'key', width: '40%' },
  { title: 'Value', key: 'value' },
  { title: t('Action'), key: 'actions', width: 70, align: 'center' },
]);

async function load(p = 1) {
  loading.value = true;
  try {
    const data = await http.get(`translations_setting/${locale}`, {
      page: p,
      per_page: perPage,
      search: search.value,
    });
    rows.value = data.data || [];
    original.value = Object.fromEntries(rows.value.map(r => [r.key, r.value]));
    total.value = data.total || 0;
    page.value = data.current_page || p;
    language.value = data.language || null;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function saveIfChanged(record) {
  if (original.value[record.key] === record.value) return;
  try {
    await http.put(`translations_setting/${locale}`, { key: record.key, value: record.value });
    original.value[record.key] = record.value;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

const addOpen = ref(false);
const saving = ref(false);
const newEntry = ref({ key: '', value: '' });

async function addTranslation() {
  if (!newEntry.value.key) return;
  saving.value = true;
  try {
    await http.put(`translations_setting/${locale}`, { ...newEntry.value });
    message.success(t('Successfully_Created'));
    addOpen.value = false;
    newEntry.value = { key: '', value: '' };
    load(page.value);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function remove(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: record.key,
    okType: 'danger',
    okText: t('Delete_confirmButtonText'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`translations_setting/${locale}/${encodeURIComponent(record.key)}`);
        message.success(t('Deleted_in_successfully'));
        load(page.value);
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

onMounted(() => load(1));
</script>

<style scoped>
.showing {
  padding: 12px 16px;
  text-align: right;
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
