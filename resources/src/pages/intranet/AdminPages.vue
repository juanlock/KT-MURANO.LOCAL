<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_Pages')" :breadcrumb="[$t('Intranet_Management'), $t('Intranet_Pages')]">
      <template #extra>
        <a-button type="primary" @click="openForm()"><template #icon><PlusOutlined /></template>{{ $t('Intranet_Add_Page') }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <a-table :data-source="rows" :columns="columns" :loading="loading" row-key="id" size="middle" :pagination="false" :scroll="{ x: 700 }">
        <template #bodyCell="{ column, record, index }">
          <template v-if="column.key === 'order'">
            <a-space :size="2">
              <a-button size="small" type="text" :disabled="index === 0" :title="$t('Intranet_Move_Up')" @click="move(index, -1)">↑</a-button>
              <a-button size="small" type="text" :disabled="index === rows.length - 1" :title="$t('Intranet_Move_Down')" @click="move(index, 1)">↓</a-button>
            </a-space>
          </template>
          <template v-else-if="column.key === 'title'"><a @click="openForm(record)">{{ record.title }}</a></template>
          <template v-else-if="column.key === 'slug'"><code>{{ record.slug }}</code></template>
          <template v-else-if="column.key === 'published'">
            <a-tag :color="record.published ? 'green' : 'default'">{{ record.published ? $t('Intranet_Published') : $t('Intranet_Draft') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'action'">
            <a-space>
              <router-link v-if="record.published" :to="{ name: 'intranet-page', params: { slug: record.slug } }">
                <a-button size="small" :title="$t('Intranet_View_Page')"><template #icon><EyeOutlined /></template></a-button>
              </router-link>
              <a-button size="small" @click="openForm(record)"><template #icon><EditOutlined /></template></a-button>
              <a-popconfirm :title="$t('Delete_Title')" :ok-text="$t('Delete_confirmButtonText')" :cancel-text="$t('Delete_cancelButtonText')" @confirm="remove(record)">
                <a-button size="small" danger><template #icon><DeleteOutlined /></template></a-button>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-drawer :open="formOpen" :title="form.id ? $t('Intranet_Edit_Page') : $t('Intranet_Add_Page')" width="min(920px, 100vw)" destroy-on-close @close="formOpen = false">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="12"><a-form-item :label="$t('Intranet_Title')" required><a-input v-model:value="form.title" :maxlength="190" /></a-form-item></a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Intranet_Slug')" :extra="$t('Intranet_Slug_Hint')"><a-input v-model:value="form.slug" :maxlength="190" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="4"><a-form-item :label="$t('Intranet_Published')"><a-switch v-model:checked="form.published" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="$t('Intranet_Body')"><RichTextEditor v-model="form.content" /></a-form-item>
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
 * Intranet Management -> Pages (intranet_manage): static pages (policies,
 * handbook, contacts...) listed in the intranet navigation in this order.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import http from '../../lib/http';
import { apiError } from './intranetShared';

const { t } = useI18n();
const loading = ref(true);
const rows = ref([]);
const columns = computed(() => [
  { title: '', key: 'order', width: 80 },
  { title: t('Intranet_Title'), key: 'title' },
  { title: t('Intranet_Slug'), key: 'slug', width: 240 },
  { title: t('Status'), key: 'published', width: 120 },
  { title: t('Intranet_Actions'), key: 'action', width: 150, align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    rows.value = (await http.get('intranet-admin/pages')).data || [];
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

const formOpen = ref(false);
const saving = ref(false);
const form = ref({});
async function openForm(row) {
  if (!row) {
    form.value = { id: null, title: '', slug: '', content: '', published: true };
    formOpen.value = true;
    return;
  }
  try {
    const p = await http.get(`intranet-admin/pages/${row.id}`);
    form.value = { id: p.id, title: p.title, slug: p.slug, content: p.content || '', published: !!p.published };
    formOpen.value = true;
  } catch (e) {
    message.error(apiError(e));
  }
}

async function save() {
  const f = form.value;
  if (!f.title.trim()) { message.warning(`${t('Intranet_Title')}: ${t('Required')}`); return; }
  saving.value = true;
  try {
    const body = { title: f.title.trim(), slug: f.slug || null, content: f.content === '<p><br></p>' ? null : f.content, published: f.published };
    if (f.id) await http.put(`intranet-admin/pages/${f.id}`, body);
    else await http.post('intranet-admin/pages', body);
    message.success(t('Saved_successfully'));
    formOpen.value = false;
    load();
  } catch (e) {
    message.error(apiError(e));
  } finally {
    saving.value = false;
  }
}

async function move(i, dir) {
  const list = rows.value;
  [list[i], list[i + dir]] = [list[i + dir], list[i]];
  rows.value = [...list];
  try { await http.post('intranet-admin/pages/reorder', { ids: list.map(r => r.id) }); } catch (e) { load(); }
}

async function remove(row) {
  try {
    await http.delete(`intranet-admin/pages/${row.id}`);
    message.success(t('Deleted_in_successfully'));
    load();
  } catch (e) {
    message.error(apiError(e, t('Delete_Therewassomethingwronge')));
  }
}

onMounted(load);
</script>
