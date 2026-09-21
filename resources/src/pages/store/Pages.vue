<template>
  <div class="page">
    <PageHeader :title="$t('Pages')" :breadcrumb="[$t('Store'), $t('Pages')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Create') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 240px" @search="fetch" @change="fetch" />
      </div>
      <a-table
        :columns="columns" :data-source="pages" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :locale="{ emptyText: $t('No_Pages') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'title'"><strong>{{ record.title }}</strong></template>
          <template v-else-if="column.key === 'slug'">
            <a-tag style="font-family: ui-monospace, Menlo, monospace">{{ record.slug }}</a-tag>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-switch :checked="!!record.published" @change="togglePublished(record)" />
            <span :style="{ marginLeft: '8px', color: record.published ? '#3f8600' : '#999' }">
              {{ record.published ? $t('Published') : $t('Draft') }}
            </span>
          </template>
          <template v-else-if="column.key === 'created_at'">
            {{ (record.created_at || '').substring(0, 10) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" :href="`/online_store/pages/${record.slug}`" target="_blank" rel="noopener">
                <template #icon><LinkOutlined /></template>
              </a-button>
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
      v-model:open="modalOpen" :title="form.id ? $t('Edit') : $t('Create')"
      :confirm-loading="saving" width="960px" @ok="submit"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="16">
            <a-form-item :label="$t('Name') + ' *'">
              <a-input v-model:value="form.title" @input="onTitleInput" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Slug')">
              <a-input v-model:value="form.slug" :placeholder="$t('Auto_from_name')" @input="slugTouched = true" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Content')">
          <RichTextEditor v-model="form.content" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('SEO_Title')">
              <a-input v-model:value="form.seo_title" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('SEO_Description')">
              <a-input v-model:value="form.seo_description" />
            </a-form-item>
          </a-col>
        </a-row>
        <template v-if="localeList.length">
          <a-divider orientation="left">{{ $t('Translations') }}</a-divider>
          <div class="i18n-hint">{{ $t('Translations_Fallback_Hint') }}</div>
          <a-tabs v-model:activeKey="localeTab" size="small">
            <a-tab-pane v-for="l in localeList" :key="l.code" :tab="l.label">
              <a-form-item :label="$t('Name')">
                <a-input v-model:value="form.title_translations[l.code]" :placeholder="form.title" />
              </a-form-item>
              <a-form-item :label="$t('Content')">
                <RichTextEditor v-model="form.content_translations[l.code]" />
              </a-form-item>
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('SEO_Title')">
                    <a-input v-model:value="form.seo_title_translations[l.code]" :placeholder="form.seo_title" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('SEO_Description')">
                    <a-input v-model:value="form.seo_description_translations[l.code]" :placeholder="form.seo_description" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-tab-pane>
          </a-tabs>
        </template>

        <a-switch v-model:checked="form.published" />
        <span style="margin-left: 8px">{{ form.published ? $t('Published') : $t('Draft') }}</span>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Store pages — GET store/pages?q&per_page=100 → {data}; save POST/PUT
 * store/pages[/{id}] {title, slug, content, seo_title, seo_description,
 * published 1|0}. Slug auto-derives from title until manually edited
 * (legacy slugTouched). Content = Quill rich text.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, LinkOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const search = ref('');
const pages = ref([]);
const modalOpen = ref(false);
const slugTouched = ref(false);

const emptyForm = () => ({
  id: null, title: '', slug: '', content: '', seo_title: '', seo_description: '', published: true,
  title_translations: {}, content_translations: {},
  seo_title_translations: {}, seo_description_translations: {},
});
const form = ref(emptyForm());

// Storefront locales, from the API: [{code: 'fr', label: 'Français'}, …].
const localeList = ref([]);
const localeTab = ref('');
function setLocales(map) {
  if (!map) return;
  localeList.value = Object.keys(map).map(code => ({ code, label: map[code] }));
  if (!localeTab.value && localeList.value.length) localeTab.value = localeList.value[0].code;
}

const columns = computed(() => [
  { title: t('Name'), key: 'title' },
  { title: 'Slug', key: 'slug' },
  { title: t('Status'), key: 'status', width: 160 },
  { title: t('Created_at'), key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 140 },
]);

function slugify(s) {
  return (s || '').toString().toLowerCase().trim()
    .replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-');
}
function onTitleInput() {
  if (!form.value.id && !slugTouched.value) form.value.slug = slugify(form.value.title);
}

async function fetch() {
  try {
    const r = await http.get('store/pages', { q: search.value, per_page: 100 });
    pages.value = (r && r.data) || [];
    setLocales(r && r.locales);
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function openCreate() {
  form.value = emptyForm();
  slugTouched.value = false;
  modalOpen.value = true;
}
function openEdit(p) {
  form.value = {
    ...emptyForm(),
    id: p.id, title: p.title, slug: p.slug, content: p.content || '',
    seo_title: p.seo_title || '', seo_description: p.seo_description || '', published: !!p.published,
    title_translations: { ...(p.title_translations || {}) },
    content_translations: { ...(p.content_translations || {}) },
    seo_title_translations: { ...(p.seo_title_translations || {}) },
    seo_description_translations: { ...(p.seo_description_translations || {}) },
  };
  slugTouched.value = true;
  modalOpen.value = true;
}
async function submit() {
  if (!form.value.title) {
    message.warning(`${t('Name')} *`);
    return;
  }
  saving.value = true;
  const payload = {
    title: form.value.title,
    slug: form.value.slug || '',
    content: form.value.content || '',
    seo_title: form.value.seo_title || '',
    seo_description: form.value.seo_description || '',
    published: form.value.published ? 1 : 0,
  };
  // Every locale key is sent, blanks included, so clearing a translation here
  // actually clears it. togglePublished() sends none of these, which is how it
  // leaves the stored copy untouched.
  ['title', 'content', 'seo_title', 'seo_description'].forEach(field => {
    const key = `${field}_translations`;
    payload[key] = {};
    localeList.value.forEach(l => {
      payload[key][l.code] = (form.value[key] || {})[l.code] || '';
    });
  });
  try {
    if (form.value.id) await http.put(`store/pages/${form.value.id}`, payload);
    else await http.post('store/pages', payload);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetch();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
async function togglePublished(p) {
  try {
    await http.put(`store/pages/${p.id}`, {
      title: p.title, slug: p.slug, content: p.content || '',
      seo_title: p.seo_title || '', seo_description: p.seo_description || '',
      published: p.published ? 0 : 1,
    });
    p.published = !p.published;
  } catch (e) {
    message.error(t('Failed'));
  }
}
function confirmDelete(p) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: p.title,
    okText: t('Delete'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      try {
        await http.delete(`store/pages/${p.id}`);
        message.success(t('Successfully_Deleted'));
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
.toolbar {
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
</style>
