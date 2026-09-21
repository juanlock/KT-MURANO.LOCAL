<template>
  <div class="page">
    <PageHeader
      :title="`${isEdit ? $t('Edit') : $t('New')} ${$t('Article')}`"
      :breadcrumb="[$t('Knowledge_Base'), isEdit ? $t('Edit') : $t('New')]"
    >
      <template #actions>
        <a-button @click="$router.push('/kb')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Knowledge_Base') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="[16, 16]">
        <!-- Writing surface -->
        <a-col :xs="24" :lg="16">
          <a-card size="small" class="kb-form-card">
            <a-form-item :label="$t('Title')" name="title">
              <a-input
                v-model:value="form.title"
                size="large"
                :placeholder="tf('Article_Title_Placeholder', 'e.g. How to process a refund')"
                @input="syncSlug"
              />
            </a-form-item>

            <a-form-item :label="$t('Slug')" name="slug">
              <a-input v-model:value="form.slug" @input="slugTouched = true">
                <template #prefix><LinkOutlined style="opacity: 0.5" /></template>
              </a-input>
            </a-form-item>

            <a-form-item :label="$t('Content')" style="margin-bottom: 0">
              <RichTextEditor v-model="form.content" />
            </a-form-item>
          </a-card>
        </a-col>

        <!-- Publish settings -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" class="kb-form-card kb-form-side" :title="tf('Publishing', 'Publishing')">
            <a-form-item :label="$t('Group')" name="knowledge_base_article_group_id">
              <a-select
                v-model:value="form.knowledge_base_article_group_id"
                show-search option-filter-prop="label"
                :options="groups.map(g => ({ value: g.id, label: g.name }))"
              />
              <template #extra>
                <router-link to="/kb/groups">{{ $t('Article_Groups') }}</router-link>
              </template>
            </a-form-item>

            <a-form-item
              :label="tf('Publication_date', 'Publication date')"
              :help="tf('Leave_empty_for_now', 'Optional — leave empty for an unpublished draft.')"
            >
              <a-date-picker v-model:value="form.published_at" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>

            <a-form-item :label="tf('Sort_order', 'Sort order')">
              <a-input-number v-model:value="form.sort_order" :min="0" style="width: 100%" />
            </a-form-item>

            <div class="kb-internal-row">
              <div>
                <a-typography-text strong style="display: block">
                  {{ tf('Internal_Article', 'Internal article') }}
                </a-typography-text>
                <a-typography-text type="secondary" style="font-size: 12px">
                  {{ tf('Internal_Article_Hint', 'Visible only to users with Knowledge Base permission.') }}
                </a-typography-text>
              </div>
              <a-switch v-model:checked="form.is_internal" />
            </div>

            <a-divider style="margin: 16px 0" />

            <a-space direction="vertical" style="width: 100%" :size="8">
              <a-button type="primary" block :loading="saving" @click="save">
                <template #icon><SaveOutlined /></template>
                {{ $t('Save') }}
              </a-button>
              <a-button v-if="isEdit" block @click="$router.push(`/kb/articles/${id}`)">
                <template #icon><EyeOutlined /></template>
                {{ $t('View') }}
              </a-button>
              <a-button block @click="$router.push('/kb')">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Knowledge-base article create/edit — legacy KnowledgeBaseArticleForm.vue,
 * which the v3 list and view pages were still handing off to because the
 * rich-text editor had not been ported. RichTextEditor (Quill 2) exists now,
 * so the whole flow stays in-app.
 *
 * GET knowledge-base/groups → array | {data} for the group select (legacy
 * preselects the first group on create). Edit loads
 * GET knowledge-base/articles/{id} and trims published_at to a date.
 * Save: POST knowledge-base/articles | PUT knowledge-base/articles/{id};
 * an empty published_at is sent as NULL, which is what marks a draft.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, LinkOutlined, SaveOutlined, EyeOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = route.params.id;
const isEdit = !!id;

const loading = ref(true);
const saving = ref(false);
const formRef = ref();
const groups = ref([]);

const form = ref({
  knowledge_base_article_group_id: null,
  title: '',
  slug: '',
  content: '',
  is_internal: false,
  sort_order: 0,
  published_at: '',
});

const rules = computed(() => ({
  knowledge_base_article_group_id: [{ required: true, message: t('Field_is_required') }],
  title: [{ required: true, message: t('Field_is_required') }],
  slug: [{ required: true, message: t('Field_is_required') }],
}));

// Convenience only: keep the slug in step with the title on create until the
// user edits the slug themselves. Validation is untouched — slug stays required.
const slugTouched = ref(isEdit);
function syncSlug() {
  if (slugTouched.value) return;
  form.value.slug = form.value.title
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  saving.value = true;
  // An empty date must go as null, not "" — the backend reads null as "draft".
  const payload = { ...form.value, published_at: form.value.published_at || null };
  try {
    if (isEdit) {
      await http.put(`knowledge-base/articles/${id}`, payload);
      message.success(tf('Updated', 'Updated'));
    } else {
      await http.post('knowledge-base/articles', payload);
      message.success(tf('Saved', 'Saved'));
    }
    router.push('/kb');
  } catch (e) {
    const errors = e?.data?.errors;
    message.error(e?.data?.message || (errors && Object.values(errors).flat()[0]) || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('knowledge-base/groups');
    groups.value = Array.isArray(data) ? data : (data?.data || []);
  } catch (e) {
    groups.value = [];
  }

  if (isEdit) {
    try {
      const a = await http.get(`knowledge-base/articles/${id}`);
      const article = a?.data || a || {};
      form.value = {
        knowledge_base_article_group_id: article.knowledge_base_article_group_id,
        title: article.title,
        slug: article.slug,
        content: article.content || '',
        is_internal: !!article.is_internal,
        sort_order: article.sort_order ?? 0,
        published_at: article.published_at ? String(article.published_at).slice(0, 10) : '',
      };
    } catch (e) {
      message.error(tf('Failed_to_load', 'Failed to load'));
    }
  } else if (groups.value.length) {
    form.value.knowledge_base_article_group_id = groups.value[0].id;
  }
  loading.value = false;
});
</script>

<style scoped>
.kb-form-card { border-radius: 12px; }
@media (min-width: 992px) {
  .kb-form-side { position: sticky; top: 16px; }
}
.kb-internal-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 10px;
}
</style>
