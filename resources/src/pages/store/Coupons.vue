<template>
  <div class="page">
    <PageHeader :title="$t('Coupons')" :breadcrumb="[$t('Store'), $t('Coupons')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 240px" @search="fetch" @change="fetch" />
      </div>
      <a-table
        :columns="columns" :data-source="coupons" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_Coupons') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'code'">
            <strong style="font-family: ui-monospace, Menlo, monospace">{{ record.code }}</strong>
            <div v-if="record.description" style="font-size: 12px; color: #999">{{ record.description }}</div>
          </template>
          <template v-else-if="column.key === 'discount'">
            <span v-if="record.type === 'percentage'">
              {{ record.value }}%<span v-if="record.max_discount"> ({{ $t('Max') }} {{ record.max_discount }})</span>
            </span>
            <span v-else>{{ record.value }}</span>
          </template>
          <template v-else-if="column.key === 'min_order'">
            {{ record.min_order_amount != null ? record.min_order_amount : '—' }}
          </template>
          <template v-else-if="column.key === 'usage'">
            {{ record.used_count }}<span v-if="record.usage_limit"> / {{ record.usage_limit }}</span>
            <div v-if="record.per_customer_limit" style="font-size: 12px; color: #999">
              {{ $t('Per_Customer') }}: {{ record.per_customer_limit }}
            </div>
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
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Code') + ' *'">
              <a-input v-model:value="form.code" @input="form.code = (form.code || '').toUpperCase()" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Description')">
              <a-input v-model:value="form.description" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Type')">
              <a-select v-model:value="form.type">
                <a-select-option value="percentage">{{ $t('Percentage') }}</a-select-option>
                <a-select-option value="fixed">{{ $t('Fixed_Amount') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="(form.type === 'percentage' ? $t('Percent_Value') : $t('Amount')) + ' *'">
              <a-input-number v-model:value="form.value" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Min_Order')">
              <a-input-number v-model:value="form.min_order_amount" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col v-if="form.type === 'percentage'" :xs="24" :md="12">
            <a-form-item :label="$t('Max_Discount')">
              <a-input-number v-model:value="form.max_discount" :min="0" :step="0.01" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Usage_Limit')">
              <a-input-number v-model:value="form.usage_limit" :min="1" :placeholder="$t('Unlimited')" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Per_Customer_Limit')">
              <a-input-number v-model:value="form.per_customer_limit" :min="1" :placeholder="$t('Unlimited')" style="width: 100%" />
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
          <a-col :span="24">
            <a-switch v-model:checked="form.enabled" />
            <span style="margin-left: 8px">{{ form.enabled ? $t('Enabled') : $t('Disabled') }}</span>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Coupons — GET store/coupons?search → {coupons}; save POST/PUT
 * store/coupons[/{id}] with legacy payload (empty → null, enabled 1|0, code
 * uppercased). Toggle re-PUTs the full row payload with enabled flipped.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const search = ref('');
const coupons = ref([]);
const modalOpen = ref(false);

const emptyForm = () => ({
  id: null, code: '', description: '', type: 'percentage', value: 0,
  min_order_amount: null, max_discount: null, usage_limit: null, per_customer_limit: null,
  starts_at: '', ends_at: '', enabled: true,
});
const form = ref(emptyForm());

const columns = computed(() => [
  { title: t('Code'), key: 'code' },
  { title: t('Discount'), key: 'discount' },
  { title: t('Min_Order'), key: 'min_order', align: 'right' },
  { title: t('Usage'), key: 'usage' },
  { title: t('Status'), key: 'status', width: 160 },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

const clean = v => (v === '' || v == null ? null : v);

async function fetch() {
  try {
    const r = await http.get('store/coupons', { search: search.value });
    coupons.value = r.coupons || [];
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function openCreate() {
  form.value = emptyForm();
  modalOpen.value = true;
}
function openEdit(c) {
  form.value = { ...emptyForm(), ...c, enabled: !!c.enabled };
  modalOpen.value = true;
}
function buildPayload(f) {
  return {
    code: f.code, description: f.description, type: f.type, value: f.value,
    min_order_amount: clean(f.min_order_amount), max_discount: clean(f.max_discount),
    usage_limit: clean(f.usage_limit), per_customer_limit: clean(f.per_customer_limit),
    starts_at: clean(f.starts_at), ends_at: clean(f.ends_at), enabled: f.enabled ? 1 : 0,
  };
}
async function submit() {
  saving.value = true;
  try {
    if (form.value.id) await http.put(`store/coupons/${form.value.id}`, buildPayload(form.value));
    else await http.post('store/coupons', buildPayload(form.value));
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetch();
  } catch (e) {
    const msg = e?.data?.errors ? Object.values(e.data.errors)[0][0] : t('InvalidData');
    message.error(msg);
  } finally {
    saving.value = false;
  }
}
async function toggle(c) {
  try {
    await http.put(`store/coupons/${c.id}`, { ...buildPayload(c), enabled: c.enabled ? 0 : 1 });
    fetch();
  } catch (e) {
    message.error(t('Failed'));
    fetch();
  }
}
function confirmDelete(c) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: c.code,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/coupons/${c.id}`);
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
.toolbar {
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
</style>
