<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit') : $t('Create_deposit')"
      :breadcrumb="[$t('Deposits'), isEdit ? $t('Edit') : $t('Create_deposit')]"
    />

    <div v-if="loadingRecord" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('date')" name="date">
              <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Amount')" name="amount">
              <a-input-number v-model:value="form.amount" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Account')" name="account_id">
              <a-select
                v-model:value="form.account_id"
                show-search
                option-filter-prop="label"
                :placeholder="$t('Choose_Account')"
                :options="accountOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Deposit_Category')" name="category_id">
              <a-select
                v-model:value="form.category_id"
                show-search
                option-filter-prop="label"
                :placeholder="$t('Choose_Category')"
                :options="categoryOptions"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Details')" name="description">
              <a-textarea v-model:value="form.description" :rows="3" :placeholder="$t('Afewwords')" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space style="margin-top: 8px">
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/deposits')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Create: GET deposits/create → {deposits_category, accounts};
 * POST deposits {deposit: {...}} — the whole form is nested under `deposit`.
 * Edit: GET deposits/{id}/edit → {deposit, deposit_category, accounts}
 * (bootstrap keys differ between the two endpoints!); PUT deposits/{id}
 * with the same nested body.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = computed(() => route.params.id);
const isEdit = computed(() => !!id.value);

const loadingRecord = ref(true);
const submitting = ref(false);
const formRef = ref();

const categories = ref([]);
const accounts = ref([]);

const form = ref({
  date: dayjs().format('YYYY-MM-DD'),
  account_id: undefined,
  category_id: undefined,
  description: '',
  amount: null,
});

const categoryOptions = computed(() => categories.value.map(c => ({ value: c.id, label: c.title })));
const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: a.account_name })));

const rules = computed(() => ({
  date: [{ required: true, message: t('Field_is_required') }],
  amount: [{ required: true, message: t('Field_is_required') }],
  account_id: [{ required: true, message: t('Field_is_required') }],
  category_id: [{ required: true, message: t('Field_is_required') }],
}));

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    if (isEdit.value) {
      await http.put(`deposits/${id.value}`, { deposit: form.value });
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('deposits', { deposit: form.value });
      message.success(t('Successfully_Created'));
    }
    router.push('/deposits');
  } catch (e) {
    const errors = e?.data?.errors;
    if (errors) Object.values(errors).flat().forEach(msg => message.error(String(msg)));
    else message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

async function bootstrap() {
  loadingRecord.value = true;
  try {
    if (isEdit.value) {
      const data = await http.get(`deposits/${id.value}/edit`);
      categories.value = data.deposit_category || [];
      accounts.value = data.accounts || [];
      const d = data.deposit || {};
      form.value = {
        date: d.date || form.value.date,
        account_id: d.account_id || undefined,
        category_id: d.category_id || undefined,
        description: d.description || '',
        amount: Number(d.amount) || null,
      };
    } else {
      const data = await http.get('deposits/create');
      categories.value = data.deposits_category || [];
      accounts.value = data.accounts || [];
    }
  } catch (e) {
    if (isEdit.value) {
      message.error(t('InvalidData'));
      router.push('/deposits');
      return;
    }
    message.warning(t('InvalidData'));
  } finally {
    loadingRecord.value = false;
  }
}

onMounted(bootstrap);
</script>
