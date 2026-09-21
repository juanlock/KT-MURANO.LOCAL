<template>
  <div class="page">
    <PageHeader :title="$t('Invite_Codes')" :breadcrumb="[$t('Store'), $t('Invite_Codes')]">
      <template #extra>
        <a-space>
          <a-button @click="openBatch">
            {{ $t('Generate_Batch') }}
          </a-button>
          <a-button type="primary" @click="openCreate">
            <template #icon><PlusOutlined /></template>
            {{ $t('Create_Code') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search
          v-model:value="search" :placeholder="$t('Search_by_code')"
          allow-clear style="max-width: 220px" @search="() => fetchCodes(1)" @change="debounceFetch"
        />
        <a-select v-model:value="filterStatus" style="width: 150px" @change="() => fetchCodes(1)">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option value="active">{{ $t('Active') }}</a-select-option>
          <a-select-option value="inactive">{{ $t('Inactive') }}</a-select-option>
        </a-select>
      </div>
      <a-table
        :columns="columns" :data-source="codes" :loading="isLoading"
        :pagination="pagination" size="middle" row-key="id"
        :scroll="{ x: 'max-content' }"
        :locale="{ emptyText: $t('No_invite_codes_yet') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'code'">
            <strong style="font-family: ui-monospace, Menlo, monospace">{{ record.code }}</strong>
            <a-button type="text" size="small" @click="copyCode(record.code)">
              <template #icon><CopyOutlined /></template>
            </a-button>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="codeStatusColor(record)">{{ codeStatusLabel(record) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'max_uses'">
            {{ record.max_uses != null ? record.max_uses : '∞' }}
          </template>
          <template v-else-if="column.key === 'expires_at'">
            {{ record.expires_at ? formatDate(record.expires_at) : '—' }}
          </template>
          <template v-else-if="column.key === 'created_at'">
            {{ formatDate(record.created_at) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="openEdit(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="deleteCode(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <!-- Create/edit -->
    <a-modal
      v-model:open="modalOpen" :title="editingCode ? $t('Edit_Invite_Code') : $t('Create_Invite_Code')"
      :confirm-loading="saving" @ok="saveCode"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item v-if="!editingCode" :label="$t('Code')" :extra="$t('Leave_blank_to_auto_generate')">
          <a-input v-model:value="codeForm.code" placeholder="e.g. WELCOME2026" :maxlength="64" />
        </a-form-item>
        <a-form-item :label="$t('Max_Uses')" :extra="$t('Leave_blank_for_unlimited')">
          <a-input-number v-model:value="codeForm.max_uses" :min="1" placeholder="∞" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Expires_At')">
          <a-input v-model:value="codeForm.expires_at" type="datetime-local" />
        </a-form-item>
        <a-form-item :label="$t('Active')" style="margin-bottom: 0">
          <a-switch v-model:checked="codeForm.is_active" />
          <span style="margin-left: 8px">{{ codeForm.is_active ? $t('Yes') : $t('No') }}</span>
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Batch generate -->
    <a-modal
      v-model:open="batchOpen" :title="$t('Generate_Invite_Codes')"
      :confirm-loading="saving" :ok-text="$t('Generate')" @ok="generateBatch"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Number_of_codes')">
          <a-input-number v-model:value="batchForm.count" :min="1" :max="50" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Max_Uses_Per_Code')" :extra="$t('Leave_blank_for_unlimited')">
          <a-input-number v-model:value="batchForm.max_uses" :min="1" placeholder="∞" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Expires_At')" style="margin-bottom: 0">
          <a-input v-model:value="batchForm.expires_at" type="datetime-local" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Invite codes — GET store/invite-codes?per_page=15&page&search&status
 * (paginator root current_page/last_page). Save: create POST (code|null =
 * auto-generate), edit PUT (max_uses/expires_at/is_active only). Batch POST
 * store/invite-codes/batch {count ≤ 50, max_uses|null, expires_at|null} →
 * {generated}. Status derives client-side: disabled/expired/exhausted/active.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, CopyOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const search = ref('');
const filterStatus = ref('');
const codes = ref([]);
const currentPage = ref(1);
const lastPage = ref(1);
const modalOpen = ref(false);
const batchOpen = ref(false);
const editingCode = ref(null);
const codeForm = ref({ code: '', max_uses: null, expires_at: '', is_active: true });
const batchForm = ref({ count: 5, max_uses: null, expires_at: '' });
let debounceTimer = null;

const columns = computed(() => [
  { title: t('Code'), key: 'code' },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('Uses'), dataIndex: 'times_used', key: 'times_used', align: 'center' },
  { title: t('Max_Uses'), key: 'max_uses', align: 'center' },
  { title: t('Expires'), key: 'expires_at' },
  { title: t('Created'), key: 'created_at' },
  { title: t('Actions'), key: 'actions', width: 100 },
]);
const pagination = computed(() => ({
  current: currentPage.value,
  pageSize: 15,
  total: lastPage.value * 15,
  showSizeChanger: false,
}));

function formatDate(d) {
  if (!d) return '';
  const dt = new Date(d);
  return `${dt.toLocaleDateString()} ${dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}
function codeStatusColor(c) {
  if (!c.is_active) return 'default';
  if (c.expires_at && new Date(c.expires_at) < new Date()) return 'error';
  if (c.max_uses != null && c.times_used >= c.max_uses) return 'warning';
  return 'success';
}
function codeStatusLabel(c) {
  if (!c.is_active) return t('Disabled');
  if (c.expires_at && new Date(c.expires_at) < new Date()) return t('Expired');
  if (c.max_uses != null && c.times_used >= c.max_uses) return t('Exhausted');
  return t('Active');
}
function copyCode(code) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(code);
    message.success(t('Copied'));
  }
}
function debounceFetch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => fetchCodes(1), 400);
}

async function fetchCodes(page) {
  try {
    isLoading.value = codes.value.length === 0;
    const params = { per_page: 15, page: page || 1 };
    if (search.value) params.search = search.value;
    if (filterStatus.value) params.status = filterStatus.value;
    const resp = await http.get('store/invite-codes', params);
    codes.value = resp.data || [];
    currentPage.value = resp.current_page || 1;
    lastPage.value = resp.last_page || 1;
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function onTableChange(pag) {
  fetchCodes(pag.current);
}
function openCreate() {
  editingCode.value = null;
  codeForm.value = { code: '', max_uses: null, expires_at: '', is_active: true };
  modalOpen.value = true;
}
function openEdit(c) {
  editingCode.value = c;
  codeForm.value = {
    code: c.code,
    max_uses: c.max_uses,
    expires_at: c.expires_at ? c.expires_at.replace(' ', 'T').substring(0, 16) : '',
    is_active: !!c.is_active,
  };
  modalOpen.value = true;
}
function openBatch() {
  batchForm.value = { count: 5, max_uses: null, expires_at: '' };
  batchOpen.value = true;
}
async function saveCode() {
  saving.value = true;
  try {
    const payload = {
      max_uses: codeForm.value.max_uses || null,
      expires_at: codeForm.value.expires_at || null,
      is_active: codeForm.value.is_active,
    };
    if (editingCode.value) {
      await http.put(`store/invite-codes/${editingCode.value.id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      payload.code = codeForm.value.code || null;
      await http.post('store/invite-codes', payload);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    await fetchCodes(currentPage.value);
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
  } finally {
    saving.value = false;
  }
}
function deleteCode(c) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: c.code,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/invite-codes/${c.id}`);
        message.success(t('Successfully_Deleted'));
        await fetchCodes(currentPage.value);
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
}
async function generateBatch() {
  saving.value = true;
  try {
    const resp = await http.post('store/invite-codes/batch', {
      count: batchForm.value.count || 5,
      max_uses: batchForm.value.max_uses || null,
      expires_at: batchForm.value.expires_at || null,
    });
    const n = (resp && resp.generated) || 0;
    message.success(`${n} ${t('codes_generated')}`);
    batchOpen.value = false;
    await fetchCodes(1);
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
  } finally {
    saving.value = false;
  }
}

onMounted(() => fetchCodes(1));
</script>

<style scoped>
.toolbar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
</style>
