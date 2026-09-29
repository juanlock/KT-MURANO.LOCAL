<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_News')" :breadcrumb="[$t('Intranet_Management'), $t('Intranet_News')]">
      <template #extra>
        <a-input-search v-model:value="search" :placeholder="$t('Intranet_Search')" allow-clear style="width: 200px" @search="load(1)" />
        <a-select v-model:value="category" :options="filterCategoryOptions" style="width: 160px" @change="load(1)" />
        <a-button type="primary" @click="openForm()"><template #icon><PlusOutlined /></template>{{ $t('Intranet_Add_Post') }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <a-table
        :data-source="rows" :columns="columns" :loading="loading" row-key="id" size="middle" :scroll="{ x: 900 }"
        :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: false, hideOnSinglePage: true }"
        @change="p => load(p.current)"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'title'">
            <div class="title-cell">
              <img v-if="record.cover_url" :src="record.cover_url" alt="" class="thumb" />
              <div>
                <a @click="openForm(record)">{{ record.title }}</a>
                <div v-if="record.excerpt" class="muted">{{ record.excerpt }}</div>
              </div>
            </div>
          </template>
          <template v-else-if="column.key === 'category'"><a-tag :color="categoryColor(record.category)">{{ categoryLabel(record.category) }}</a-tag></template>
          <template v-else-if="column.key === 'pinned'"><PushpinFilled v-if="record.pinned" style="color: #f5222d" /></template>
          <template v-else-if="column.key === 'published'">
            <a-tag v-if="!record.published">{{ $t('Intranet_Draft') }}</a-tag>
            <a-tag v-else-if="record.published_at && dayjs(record.published_at).isAfter(dayjs())" color="gold">{{ fmtDateTime(record.published_at) }}</a-tag>
            <a-tag v-else color="green">{{ $t('Intranet_Published') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'date'">{{ fmtDate(record.published_at || record.created_at) }}</template>
          <template v-else-if="column.key === 'author'">{{ personName(record.author) }}</template>
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

    <a-drawer :open="formOpen" :title="form.id ? $t('Intranet_Edit_Post') : $t('Intranet_Add_Post')" width="min(920px, 100vw)" destroy-on-close @close="formOpen = false">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="16">
            <a-form-item :label="$t('Intranet_Title')" required>
              <a-input v-model:value="form.title" :maxlength="190" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Intranet_Category')" required>
              <a-select v-model:value="form.category" :options="categoryOptions" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Intranet_Excerpt')">
          <a-textarea v-model:value="form.excerpt" :maxlength="500" :auto-size="{ minRows: 2, maxRows: 4 }" show-count />
        </a-form-item>
        <a-form-item :label="$t('Intranet_Body')">
          <RichTextEditor v-model="form.body" />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Intranet_Cover')">
              <div class="cover-box">
                <img v-if="coverPreview" :src="coverPreview" alt="" />
                <a-space>
                  <a-upload :show-upload-list="false" accept="image/jpeg,image/png,image/webp,image/gif" :before-upload="pickCover">
                    <a-button size="small"><template #icon><UploadOutlined /></template>{{ $t('Intranet_Cover') }}</a-button>
                  </a-upload>
                  <a-button v-if="coverPreview" size="small" danger @click="clearCover">{{ $t('Intranet_Remove_Cover') }}</a-button>
                </a-space>
              </div>
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="5">
            <a-form-item :label="$t('Intranet_Published')"><a-switch v-model:checked="form.published" /></a-form-item>
          </a-col>
          <a-col :xs="12" :md="4">
            <a-form-item :label="$t('Intranet_Pinned')"><a-switch v-model:checked="form.pinned" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="7">
            <a-form-item :label="$t('Intranet_Published_At')">
              <a-date-picker v-model:value="form.published_at" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
      <template #footer>
        <a-space style="float: right">
          <a-button @click="formOpen = false">{{ $t('Intranet_Cancel') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save">{{ $t('Intranet_Save') }}</a-button>
        </a-space>
      </template>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Intranet Management -> News (intranet_manage): posts shown on the
 * employees' intranet feed (pinned first). Body is Quill rich text, purified
 * server-side; the cover image is uploaded after the post is saved.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined, PushpinFilled } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { sortByLabel, withAllOption } from '../../lib/filterOptions';
import { POST_CATEGORIES, categoryLabel, categoryColor, fmtDate, fmtDateTime, personName, apiError } from './intranetShared';

const { t } = useI18n();
const loading = ref(true);
const rows = ref([]);
const page = ref(1);
const perPage = ref(20);
const total = ref(0);
const search = ref('');
const category = ref('');

const categoryOptions = computed(() => sortByLabel(POST_CATEGORIES.map(c => ({ value: c, label: categoryLabel(c) }))));
const filterCategoryOptions = computed(() => withAllOption(categoryOptions.value));
const columns = computed(() => [
  { title: t('Intranet_Title'), key: 'title' },
  { title: t('Intranet_Category'), key: 'category', width: 140 },
  { title: t('Intranet_Pinned'), key: 'pinned', width: 80, align: 'center' },
  { title: t('Status'), key: 'published', width: 150 },
  { title: t('Intranet_Date'), key: 'date', width: 120 },
  { title: t('Intranet_Author'), key: 'author', width: 150 },
  { title: t('Intranet_Actions'), key: 'action', width: 110, align: 'right' },
]);

async function load(p = page.value) {
  loading.value = true;
  try {
    const r = await http.get('intranet-admin/posts', { page: p, search: search.value || undefined, category: category.value || undefined });
    rows.value = r.data || [];
    page.value = r.current_page || 1;
    perPage.value = r.per_page || 20;
    total.value = r.total || 0;
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

/* form */
const formOpen = ref(false);
const saving = ref(false);
const blank = () => ({ id: null, title: '', category: 'news', excerpt: '', body: '', pinned: false, published: true, published_at: null, cover_url: null });
const form = ref(blank());
const coverFile = ref(null);
const coverPreview = ref(null);
const coverRemoved = ref(false);

async function openForm(row) {
  coverFile.value = null;
  coverRemoved.value = false;
  if (!row) {
    form.value = blank();
    coverPreview.value = null;
    formOpen.value = true;
    return;
  }
  try {
    const p = await http.get(`intranet-admin/posts/${row.id}`);
    form.value = {
      id: p.id, title: p.title, category: p.category, excerpt: p.excerpt || '', body: p.body || '',
      pinned: !!p.pinned, published: !!p.published,
      published_at: p.published_at ? dayjs(p.published_at).format('YYYY-MM-DD HH:mm:ss') : null,
      cover_url: p.cover_url,
    };
    coverPreview.value = p.cover_url;
    formOpen.value = true;
  } catch (e) {
    message.error(apiError(e));
  }
}

function pickCover(file) {
  coverFile.value = file;
  coverRemoved.value = false;
  coverPreview.value = URL.createObjectURL(file);
  return false;
}
function clearCover() {
  coverFile.value = null;
  coverPreview.value = null;
  coverRemoved.value = true;
}

async function save() {
  if (!form.value.title.trim()) { message.warning(`${t('Intranet_Title')}: ${t('Required')}`); return; }
  saving.value = true;
  try {
    const body = {
      title: form.value.title.trim(), category: form.value.category, excerpt: form.value.excerpt || null,
      body: form.value.body === '<p><br></p>' ? null : form.value.body, pinned: form.value.pinned,
      published: form.value.published, published_at: form.value.published_at || null,
    };
    const saved = form.value.id
      ? await http.put(`intranet-admin/posts/${form.value.id}`, body)
      : await http.post('intranet-admin/posts', body);
    if (coverFile.value) {
      const fd = new FormData();
      fd.append('cover', coverFile.value);
      const { status, data } = await uploadForm(`intranet-admin/posts/${saved.id}/cover`, fd);
      if (status < 200 || status >= 300) message.error(apiError({ data }));
    } else if (coverRemoved.value && form.value.cover_url) {
      await http.delete(`intranet-admin/posts/${saved.id}/cover`);
    }
    message.success(t('Saved_successfully'));
    formOpen.value = false;
    load(form.value.id ? page.value : 1);
  } catch (e) {
    message.error(apiError(e));
  } finally {
    saving.value = false;
  }
}

async function remove(row) {
  try {
    await http.delete(`intranet-admin/posts/${row.id}`);
    message.success(t('Deleted_in_successfully'));
    load();
  } catch (e) {
    message.error(apiError(e, t('Delete_Therewassomethingwronge')));
  }
}

onMounted(() => load(1));
</script>

<style scoped>
.title-cell { display: flex; align-items: center; gap: 10px; }
.thumb { width: 56px; height: 40px; object-fit: cover; border-radius: 4px; flex: 0 0 auto; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; max-width: 420px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cover-box { display: flex; flex-direction: column; gap: 6px; }
.cover-box img { width: 100%; max-width: 260px; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 6px; }
</style>
