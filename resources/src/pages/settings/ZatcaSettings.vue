<template>
  <div class="page">
    <PageHeader :title="$t('Zatca_E_Invoicing')" :breadcrumb="[$t('Settings'), $t('Zatca_E_Invoicing')]" />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else>
      <a-tabs v-model:activeKey="activeTab">
        <!-- ============================== Phase 1 (QR code) ============================== -->
        <a-tab-pane key="phase1" :tab="$t('Zatca_Phase_1')">
          <a-alert
            type="info" show-icon style="max-width: 720px; margin-bottom: 16px"
            :message="$t('Zatca_Phase_1')" :description="$t('Zatca_Phase_1_Help')"
          />
          <a-alert
            v-if="!phase2Available" type="warning" show-icon style="max-width: 720px; margin-bottom: 16px"
            :message="$t('Zatca_Phase_2')" :description="$t('Zatca_Phase_2_Unavailable')"
          />
          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Enable_ZATCA_QR_on_Sales_Receipts') }}</div>
              <div class="setting-help">{{ $t('Zatca_Phase_1_Help') }}</div>
            </div>
            <a-switch v-model:checked="company.zatca_enabled" />
          </div>
          <a-divider orientation="left">{{ $t('Zatca_Company_Details') }}</a-divider>
          <a-typography-paragraph type="secondary" style="max-width: 720px; font-size: 12px">
            {{ $t('Zatca_Company_Details_Help') }}
          </a-typography-paragraph>
          <a-form layout="vertical" style="max-width: 720px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('VAT_Number')">
                  <a-input v-model:value="company.vat_number" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('CompanyNameArabic')">
                  <a-input v-model:value="company.name_ar" dir="rtl" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-button type="primary" :loading="busy" @click="save">{{ $t('Save') }}</a-button>
          </a-form>
        </a-tab-pane>

        <!-- ============================== Onboarding ============================== -->
        <a-tab-pane v-if="phase2Available" key="onboarding" :tab="$t('Zatca_Phase_2') + ' · ' + $t('Zatca_Onboarding')">
          <a-alert
            type="info" show-icon style="max-width: 720px; margin-bottom: 16px"
            :message="$t('Zatca_Phase_2')" :description="$t('Zatca_Phase_2_Help')"
          />
          <a-descriptions :column="1" bordered size="small" style="max-width: 720px; margin-bottom: 16px">
            <a-descriptions-item :label="$t('Environment')">
              <a-tag :color="form.environment === 'production' ? 'error' : 'processing'">{{ form.environment }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Zatca_Onboarding')">
              <a-tag :color="statusColor">{{ statusLabel }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Zatca_Company_VAT_Number')">
              {{ company.vat_number || $t('Zatca_Set_VAT_In_System_Settings') }}
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Zatca_Device_Serial')">{{ form.device_serial || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Zatca_Invoice_Counter')">{{ form.icv }}</a-descriptions-item>
          </a-descriptions>

          <template v-if="form.onboarding_status !== 'ready'">
            <a-alert
              v-if="!company.vat_number" type="warning" show-icon style="margin-bottom: 16px; max-width: 720px"
              :message="$t('Zatca_VAT_Required')"
              :description="$t('Zatca_VAT_Required_Help')"
            >
              <template #action>
                <a-button size="small" @click="activeTab = 'phase1'">{{ $t('Zatca_Go_To_Phase_1') }}</a-button>
              </template>
            </a-alert>
            <a-space wrap>
              <a-input
                v-model:value="otp" :placeholder="$t('Zatca_OTP_Placeholder')" style="width: 180px"
                @pressEnter="onboard"
              />
              <a-button type="primary" :loading="busy" :disabled="!company.vat_number || !otp" @click="onboard">
                {{ $t('Zatca_Start_Onboarding') }}
              </a-button>
              <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="regenerateCsr">
                <a-button :loading="busy">{{ $t('Zatca_Regenerate_CSR') }}</a-button>
              </a-popconfirm>
            </a-space>
            <a-typography-paragraph type="secondary" style="margin-top: 12px; max-width: 720px; font-size: 12px">
              {{ $t('Zatca_OTP_Help') }}
            </a-typography-paragraph>
          </template>

          <template v-else>
            <a-alert
              type="success" show-icon style="margin-bottom: 16px; max-width: 720px"
              :message="$t('Zatca_Onboarding_Complete')"
              :description="form.auto_submit ? $t('Zatca_Auto_Submit_On_Help') : $t('Zatca_Auto_Submit_Off_Help')"
            />
            <a-space wrap>
              <a-input v-model:value="otp" :placeholder="$t('Zatca_OTP_Placeholder')" style="width: 160px" />
              <a-popconfirm :title="$t('AreYouSure')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="onboard">
                <a-button danger :loading="busy" :disabled="!otp">{{ $t('Zatca_Reonboard') }}</a-button>
              </a-popconfirm>
            </a-space>
          </template>

          <template v-if="complianceChecks && complianceChecks.length">
            <a-typography-title :level="5" style="margin-top: 24px">{{ $t('Zatca_Compliance_Results') }}</a-typography-title>
            <a-table
              :columns="complianceColumns" :data-source="complianceChecks"
              size="small" :pagination="false" row-key="type" :scroll="{ x: 'max-content' }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'result'">
                  <a-tag :color="record.passed ? 'success' : 'error'">
                    {{ record.passed ? (record.status || 'PASSED') : 'FAILED (HTTP ' + record.http_status + ')' }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'messages'">
                  <div v-for="(m, j) in record.messages || []" :key="j" style="font-size: 12px">
                    <a-tag :color="m.level === 'error' ? 'error' : m.level === 'warning' ? 'warning' : 'default'">{{ m.level }}</a-tag>
                    {{ m.code }} — {{ m.message }}
                  </div>
                </template>
              </template>
            </a-table>
          </template>
        </a-tab-pane>

        <!-- ============================== Configuration ============================== -->
        <a-tab-pane v-if="phase2Available" key="configuration" :tab="$t('Zatca_Phase_2') + ' · ' + $t('Zatca_Configuration')">
          <a-form layout="vertical" style="max-width: 900px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Enable')">
                  <a-switch v-model:checked="form.enabled" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Auto_Submit')" :help="$t('Zatca_Auto_Submit_Help')">
                  <a-switch v-model:checked="form.auto_submit" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Environment')" :help="$t('Zatca_Environment_Help')">
                  <a-select v-model:value="form.environment" :options="environmentOptions" />
                </a-form-item>
              </a-col>

              <a-col :span="24"><a-divider orientation="left">{{ $t('Zatca_EGS_Details') }}</a-divider></a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Common_Name')">
                  <a-input v-model:value="form.common_name" :placeholder="$t('Zatca_Auto_Generated')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Organization_Name')">
                  <a-input v-model:value="form.organization_name" :placeholder="company.name || ''" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Organization_Unit')">
                  <a-input v-model:value="form.organization_unit" placeholder="Main Branch" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_CRN')">
                  <a-input v-model:value="form.crn" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Business_Category')">
                  <a-input v-model:value="form.business_category" placeholder="Retail" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Invoice_Types')">
                  <a-select v-model:value="form.invoice_types" :options="invoiceTypeOptions" />
                </a-form-item>
              </a-col>

              <a-col :span="24"><a-divider orientation="left">{{ $t('Zatca_National_Address') }}</a-divider></a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Street_Name')">
                  <a-input v-model:value="form.street_name" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item :label="$t('Zatca_Building_Number')">
                  <a-input v-model:value="form.building_number" :maxlength="4" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item :label="$t('Zatca_Plot_Id')">
                  <a-input v-model:value="form.plot_identification" :maxlength="10" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_District')">
                  <a-input v-model:value="form.sub_division" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('City')">
                  <a-input v-model:value="form.city" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item :label="$t('Zatca_Postal_Code')">
                  <a-input v-model:value="form.postal_zone" :maxlength="5" />
                </a-form-item>
              </a-col>

              <a-col :span="24"><a-divider orientation="left">{{ $t('Zatca_Zero_Rated') }}</a-divider></a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zatca_Exemption_Code')">
                  <a-input v-model:value="form.zero_tax_reason_code" placeholder="VATEX-SA-32" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="16">
                <a-form-item :label="$t('Zatca_Exemption_Reason')">
                  <a-input v-model:value="form.zero_tax_reason" placeholder="Export of goods" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-button type="primary" :loading="busy" @click="save">{{ $t('Save') }}</a-button>
          </a-form>
        </a-tab-pane>

        <!-- ============================== Documents ============================== -->
        <a-tab-pane v-if="phase2Available" key="documents" :tab="$t('Zatca_Phase_2') + ' · ' + $t('Zatca_Documents')">
          <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap">
            <a-select
              v-model:value="docStatus" style="width: 180px" :options="docStatusOptions"
              @change="loadDocuments(1)"
            />
            <a-button @click="loadDocuments(docPage)">{{ $t('Refresh') }}</a-button>
            <span style="margin-left: auto; align-self: center; font-size: 12px; color: #8c8c8c">
              {{ $t('Total') }}: {{ documents.total }}
            </span>
          </div>
          <a-table
            :columns="documentColumns" :data-source="documents.data"
            size="small" :loading="docsBusy" row-key="id" :scroll="{ x: 'max-content' }"
            :pagination="{
              current: docPage, total: documents.total, pageSize: 20, showSizeChanger: false,
              onChange: p => loadDocuments(p),
            }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'type'">{{ typeLabel(record) }}</template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="docColor(record.status)">{{ record.status }}</a-tag>
              </template>
              <template v-else-if="column.key === 'issues'">
                <span v-if="record.errors && record.errors.length" style="color: #ff4d4f; font-size: 12px">
                  {{ firstMessage(record.errors) }}
                </span>
                <span v-else-if="record.warnings && record.warnings.length" style="color: #faad14; font-size: 12px">
                  {{ firstMessage(record.warnings) }}
                </span>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space>
                  <a-button size="small" @click="downloadXml(record)">XML</a-button>
                  <a-button
                    v-if="record.status === 'failed'" size="small" danger :loading="retryingId === record.id"
                    @click="retry(record)"
                  >
                    {{ $t('Zatca_Retry') }}
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * ZATCA Phase 2 e-invoicing administration. Endpoints (all under /api, gated
 * by the zatca_settings permission):
 * GET/POST zatca/settings; POST zatca/onboard {otp}; POST zatca/csr/regenerate;
 * GET zatca/documents?page&status; GET zatca/documents/{id}/xml (download);
 * POST zatca/{sales|sale_returns}/{id}/submit (retry).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

// Emitted after the phase-1 (general settings) fields are saved, so the
// System Settings hub — which embeds this page — refreshes its own copy.
const emit = defineEmits(['phase1-saved']);

const isLoading = ref(true);
const busy = ref(false);
const otp = ref('');
const activeTab = ref('phase1');
// False when the phase 2 tables are not migrated — phase 1 still works.
const phase2Available = ref(true);

// Phase 1 fields (QR on receipts) live on the general settings row; they are
// edited here so both ZATCA phases sit on the same page.
const company = ref({ name: '', name_ar: '', vat_number: '', zatca_enabled: false });
const form = ref({
    enabled: false,
    auto_submit: true,
    environment: 'sandbox',
    common_name: '',
    organization_name: '',
    organization_unit: '',
    solution_name: 'Rasheed',
    solution_version: '1.0',
    device_serial: '',
    business_category: '',
    invoice_types: '1100',
    crn: '',
    street_name: '',
    building_number: '',
    plot_identification: '',
    sub_division: '',
    city: '',
    postal_zone: '',
    zero_tax_reason_code: 'VATEX-SA-32',
    zero_tax_reason: 'Export of goods',
    onboarding_status: 'not_started',
    compliance_results: null,
    icv: 0,
});
const environmentOptions = ref([]);
const complianceChecks = ref(null);

const documents = ref({ data: [], total: 0 });
const docPage = ref(1);
const docStatus = ref('');
const docsBusy = ref(false);
const retryingId = ref(null);

const invoiceTypeOptions = computed(() => [
    { value: '1100', label: t('Zatca_Types_Both') },
    { value: '1000', label: t('Zatca_Types_Standard') },
    { value: '0100', label: t('Zatca_Types_Simplified') },
]);
const docStatusOptions = computed(() => [
    { value: '', label: t('All') },
    { value: 'reported', label: 'Reported' },
    { value: 'cleared', label: 'Cleared' },
    { value: 'failed', label: 'Failed' },
    { value: 'pending', label: 'Pending' },
]);
const complianceColumns = computed(() => [
    { title: t('Zatca_Document_Type'), dataIndex: 'type', key: 'type' },
    { title: t('Zatca_Result'), key: 'result', width: 160 },
    { title: t('Zatca_Messages'), key: 'messages' },
]);
const documentColumns = computed(() => [
    { title: '#', dataIndex: 'id', key: 'id', width: 70 },
    { title: t('Invoice'), dataIndex: 'invoice_number', key: 'invoice_number' },
    { title: t('Zatca_Source'), dataIndex: 'source', key: 'source', width: 130 },
    { title: t('Zatca_Document_Type'), key: 'type', width: 170 },
    { title: 'ICV', dataIndex: 'icv', key: 'icv', width: 70 },
    { title: t('Status'), key: 'status', width: 100 },
    { title: t('Zatca_Submitted_At'), dataIndex: 'submitted_at', key: 'submitted_at', width: 160 },
    { title: t('Zatca_Issues'), key: 'issues', ellipsis: true },
    { title: t('Actions'), key: 'actions', width: 140, align: 'center' },
]);

const statusLabel = computed(() => ({
    not_started: t('Zatca_Status_Not_Started'),
    csid_issued: t('Zatca_Status_Csid_Issued'),
    compliance_checked: t('Zatca_Status_Compliance_Checked'),
    ready: t('Zatca_Status_Ready'),
}[form.value.onboarding_status] || form.value.onboarding_status));
const statusColor = computed(() => ({
    not_started: 'default',
    csid_issued: 'warning',
    compliance_checked: 'processing',
    ready: 'success',
}[form.value.onboarding_status] || 'default'));

function errMsg(e, fallback) {
    return (e && e.data && e.data.message) || fallback;
}

async function load() {
    try {
        const data = await http.get('zatca/settings');
        phase2Available.value = data.phase2_available !== false;
        form.value = { ...form.value, ...(data.settings || {}) };
        company.value = { ...company.value, ...(data.company || {}) };
        environmentOptions.value = (data.environments || []).map(e => ({ value: e.value, label: e.label }));
        complianceChecks.value = form.value.compliance_results;
    } catch (e) {
        message.error(errMsg(e, t('Zatca_Load_Failed')));
    }
}

async function save() {
    busy.value = true;
    try {
        const data = await http.post('zatca/settings', {
            ...form.value,
            company: {
                zatca_enabled: company.value.zatca_enabled,
                vat_number: company.value.vat_number || '',
                name_ar: company.value.name_ar || '',
            },
        });
        phase2Available.value = data.phase2_available !== false;
        form.value = { ...form.value, ...(data.settings || {}) };
        company.value = { ...company.value, ...(data.company || {}) };
        emit('phase1-saved', company.value);
        message.success(t('Successfully_Updated'));
    } catch (e) {
        message.error(errMsg(e, t('Zatca_Save_Failed')));
    } finally {
        busy.value = false;
    }
}

async function onboard() {
    if (!otp.value) return;
    busy.value = true;
    try {
        const data = await http.post('zatca/onboard', { otp: otp.value });
        form.value = { ...form.value, ...(data.settings || {}) };
        complianceChecks.value = data.compliance_checks || [];
        if (data.success) message.success(t('Zatca_Onboarding_Success'));
        else message.warning(t('Zatca_Onboarding_Incomplete') + ': ' + (data.status || ''));
    } catch (e) {
        message.error(errMsg(e, t('Zatca_Onboarding_Failed')));
    } finally {
        busy.value = false;
        otp.value = '';
    }
}

async function regenerateCsr() {
    busy.value = true;
    try {
        const data = await http.post('zatca/csr/regenerate');
        form.value = { ...form.value, ...(data.settings || {}) };
        message.success(t('Zatca_CSR_Generated'));
    } catch (e) {
        message.error(errMsg(e, t('Zatca_CSR_Failed')));
    } finally {
        busy.value = false;
    }
}

async function loadDocuments(p = 1) {
    if (!phase2Available.value) return;
    docPage.value = Math.max(1, p);
    docsBusy.value = true;
    try {
        documents.value = await http.get('zatca/documents', {
            page: docPage.value,
            status: docStatus.value || undefined,
        });
    } catch (e) {
        message.error(t('Zatca_Load_Failed'));
    } finally {
        docsBusy.value = false;
    }
}

async function retry(doc) {
    retryingId.value = doc.id;
    try {
        const data = await http.post(`zatca/${doc.source_kind}/${doc.source_id}/submit`);
        (data.success ? message.success : message.warning)(t('Status') + ': ' + data.status);
        await loadDocuments(docPage.value);
    } catch (e) {
        message.error(errMsg(e, t('Zatca_Retry_Failed')));
    } finally {
        retryingId.value = null;
    }
}

function downloadXml(doc) {
    http.download(`zatca/documents/${doc.id}/xml`, `${doc.invoice_number || 'invoice'}-${doc.uuid}.xml`)
        .catch(() => message.error(t('Zatca_Load_Failed')));
}

function typeLabel(doc) {
    const names = { 388: t('Invoice'), 381: t('Zatca_Credit_Note'), 383: t('Zatca_Debit_Note') };
    return `${names[doc.type] || doc.type} (${doc.subtype})`;
}

function docColor(status) {
    return { reported: 'success', cleared: 'success', failed: 'error', pending: 'warning' }[status] || 'default';
}

function firstMessage(list) {
    const m = (list || [])[0] || {};
    return m.message || m.code || JSON.stringify(m);
}

onMounted(async () => {
    try {
        await load();
        await loadDocuments(1);
    } finally {
        isLoading.value = false;
    }
});
</script>

<style scoped>
.setting-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 14px 0;
}
.setting-label {
  font-weight: 500;
}
.setting-help {
  font-size: 12px;
  color: #8c8c8c;
  max-width: 520px;
}
</style>
