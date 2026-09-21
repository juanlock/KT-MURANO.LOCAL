<template>
  <div class="page">
    <PageHeader
      :title="$t('Service_Job_Details')"
      :breadcrumb="[$t('Service_Maintenance'), $t('Service_Job_Details')]"
    >
      <template #extra>
        <a-space>
          <a-button @click="$router.push('/service/jobs')">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t('Back_to_Service_Jobs') }}
          </a-button>
          <a-button :loading="isPdfLoading" @click="downloadPdf">
            <template #icon><FilePdfOutlined /></template>
            {{ $t('PDF') }}
          </a-button>
          <a-button @click="printJob">
            <template #icon><PrinterOutlined /></template>
            {{ $t('print') }}
          </a-button>
          <a-button v-if="job" type="primary" @click="$router.push(`/service/jobs/edit/${job.id}`)">
            <template #icon><EditOutlined /></template>
            {{ $t('Edit') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else-if="job">
      <!-- Header card: who/what + status journey -->
      <a-card style="margin-bottom: 16px">
        <div class="job-head">
          <a-avatar :size="48" style="background: #1677ff">
            <template #icon><ToolOutlined /></template>
          </a-avatar>
          <div>
            <div class="job-head-title">{{ job.client_name || $t('Service_Job_Details') }}</div>
            <a-space wrap size="small" style="margin-top: 4px">
              <a-tag>{{ job.Ref }}</a-tag>
              <a-tag :color="statusColor(job.status)">{{ statusLabel(job.status) }}</a-tag>
              <span v-if="job.service_item" class="job-head-meta">{{ job.service_item }}</span>
              <span v-if="job.technician_name" class="job-head-meta">· {{ job.technician_name }}</span>
              <span v-if="job.scheduled_date" class="job-head-meta">· {{ formatDateTime(job.scheduled_date) }}</span>
            </a-space>
          </div>
        </div>

        <a-divider style="margin: 16px 0" />

        <a-alert
          v-if="isCancelled" type="error" show-icon
          :message="statusLabel(job.status)"
        />
        <a-steps
          v-else
          :current="currentStageIndex - 1"
          size="small" label-placement="vertical"
          :items="statusJourney"
          class="journey-steps"
        />
      </a-card>

      <!-- Finance stats -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :md="6">
          <a-card size="small">
            <a-statistic :title="$t('Total')" :value="formatNumber(job.total_amount)" :prefix="currencySymbol" />
          </a-card>
        </a-col>
        <a-col :xs="24" :md="6">
          <a-card size="small">
            <a-statistic :title="$t('Paid')" :value="formatNumber(job.paid_amount)" :prefix="currencySymbol" :value-style="{ color: '#3f8600' }" />
            <a-progress :percent="paidPercent" size="small" />
          </a-card>
        </a-col>
        <a-col :xs="24" :md="6">
          <a-card size="small">
            <a-statistic
              :title="$t('Balance_Due')" :value="formatNumber(job.balance_due)" :prefix="currencySymbol"
              :value-style="{ color: job.balance_due > 0 ? '#cf1322' : '#3f8600' }"
            />
            <a-tag :color="paymentColor(job.payment_status)" style="margin-top: 6px">{{ $t(job.payment_status || 'unpaid') }}</a-tag>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="6">
          <a-card size="small">
            <template v-if="job.warranty_expires_at">
              <a-statistic :title="$t('Warranty')" :value="formatDate(job.warranty_expires_at)" :value-style="{ fontSize: '16px' }" />
              <a-tag :color="isWarrantyActive(job.warranty_expires_at) ? 'success' : 'default'" style="margin-top: 6px">
                {{ isWarrantyActive(job.warranty_expires_at) ? $t('Active') : $t('Expired') }}
              </a-tag>
            </template>
            <template v-else-if="job.delivered_at">
              <a-statistic :title="$t('Delivered_On')" :value="formatDateTime(job.delivered_at)" :value-style="{ fontSize: '16px' }" />
            </template>
            <template v-else>
              <a-statistic :title="$t('Job_Type')" :value="job.job_type || '-'" :value-style="{ fontSize: '16px' }" />
            </template>
          </a-card>
        </a-col>
      </a-row>

      <!-- Job info -->
      <a-card size="small" :title="$t('Service_Job_Details')" style="margin-bottom: 16px">
        <a-descriptions :column="{ xs: 1, md: 3 }" size="small" bordered>
          <a-descriptions-item :label="$t('Reference')">{{ job.Ref }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Customer')">{{ job.client_name || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Technician')">{{ job.technician_name || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Service_Item')">{{ job.service_item || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Job_Type')">{{ job.job_type || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Status')">
            <a-tag :color="statusColor(job.status)">{{ statusLabel(job.status) }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item v-if="job.scheduled_date" :label="$t('Scheduled_Date')">{{ formatDateTime(job.scheduled_date) }}</a-descriptions-item>
          <a-descriptions-item v-if="job.started_at" :label="$t('Started_At')">{{ formatDateTime(job.started_at) }}</a-descriptions-item>
          <a-descriptions-item v-if="job.completed_at" :label="$t('Completed_At')">{{ formatDateTime(job.completed_at) }}</a-descriptions-item>
        </a-descriptions>
        <a-alert
          v-if="job.notes" type="info" style="margin-top: 12px"
          :message="$t('Notes')"
        >
          <template #description><span style="white-space: pre-line">{{ job.notes }}</span></template>
        </a-alert>
      </a-card>

      <!-- Device information -->
      <a-card
        v-if="job.device_brand || job.device_model || job.device_serial || job.device_imei"
        size="small" :title="$t('Device_Information')" style="margin-bottom: 16px"
      >
        <a-descriptions :column="{ xs: 1, md: 3 }" size="small" bordered>
          <a-descriptions-item v-if="job.device_brand" :label="$t('Brand')">{{ job.device_brand }}</a-descriptions-item>
          <a-descriptions-item v-if="job.device_model" :label="$t('Model')">{{ job.device_model }}</a-descriptions-item>
          <a-descriptions-item v-if="job.device_color" :label="$t('Color')">{{ job.device_color }}</a-descriptions-item>
          <a-descriptions-item v-if="job.device_serial" :label="$t('Serial_Number')">{{ job.device_serial }}</a-descriptions-item>
          <a-descriptions-item v-if="job.device_imei" :label="$t('IMEI')">{{ job.device_imei }}</a-descriptions-item>
          <a-descriptions-item v-if="job.device_password" :label="$t('Unlock_Code')">{{ job.device_password }}</a-descriptions-item>
          <a-descriptions-item v-if="job.accessories && job.accessories.length" :label="$t('Accessories_Received')" :span="3">
            <a-tag v-for="a in job.accessories" :key="a">{{ a }}</a-tag>
          </a-descriptions-item>
        </a-descriptions>
      </a-card>

      <!-- Intake / diagnostic -->
      <a-card
        v-if="job.condition_on_arrival || job.reported_issue || job.diagnosis"
        size="small" :title="$t('Intake_Diagnostic')" style="margin-bottom: 16px"
      >
        <a-row :gutter="[16, 16]">
          <a-col v-if="job.condition_on_arrival" :xs="24" :md="8">
            <div class="intake-block">
              <div class="intake-label">{{ $t('Condition_On_Arrival') }}</div>
              <p>{{ job.condition_on_arrival }}</p>
            </div>
          </a-col>
          <a-col v-if="job.reported_issue" :xs="24" :md="8">
            <div class="intake-block">
              <div class="intake-label">{{ $t('Reported_Issue') }}</div>
              <p>{{ job.reported_issue }}</p>
            </div>
          </a-col>
          <a-col v-if="job.diagnosis" :xs="24" :md="8">
            <div class="intake-block">
              <div class="intake-label">{{ $t('Diagnosis') }}</div>
              <p>{{ job.diagnosis }}</p>
            </div>
          </a-col>
        </a-row>
      </a-card>

      <!-- Quote -->
      <a-card
        v-if="job.quote_amount > 0 || job.quote_approved_at || job.quote_valid_until"
        size="small" :title="$t('Quote')" style="margin-bottom: 16px"
      >
        <a-descriptions :column="{ xs: 1, md: 3 }" size="small" bordered>
          <a-descriptions-item v-if="job.quote_amount > 0" :label="$t('Quote_Amount')">
            {{ currencySymbol }}{{ formatNumber(job.quote_amount) }}
          </a-descriptions-item>
          <a-descriptions-item v-if="job.quote_valid_until" :label="$t('Valid_Until')">
            {{ formatDate(job.quote_valid_until) }}
          </a-descriptions-item>
          <a-descriptions-item v-if="job.quote_approved_at" :label="$t('Approved')">
            <span style="color: #3f8600">
              <CheckOutlined /> {{ formatDateTime(job.quote_approved_at) }}
              <span v-if="job.quote_approved_by"> · {{ job.quote_approved_by }}</span>
            </span>
          </a-descriptions-item>
        </a-descriptions>
      </a-card>

      <!-- Checklist -->
      <a-card v-if="checklist.length" size="small" :title="`${$t('Checklist')} (${checklist.length})`" style="margin-bottom: 16px">
        <a-row :gutter="[12, 12]">
          <a-col v-for="item in checklist" :key="item.id" :xs="24" :md="8">
            <div class="check-item" :class="{ 'check-item--done': item.is_completed }">
              <CheckCircleFilled v-if="item.is_completed" style="color: #52c41a" />
              <MinusCircleOutlined v-else style="color: #bbb" />
              <div>
                <div style="font-weight: 500">{{ item.item_name }}</div>
                <small v-if="item.category_name" style="color: #999">{{ item.category_name }}</small>
              </div>
            </div>
          </a-col>
        </a-row>
      </a-card>

      <!-- Line items -->
      <a-card v-if="items.length" size="small" :title="`${$t('Line_Items')} (${items.length})`" style="margin-bottom: 16px">
        <a-table :columns="itemColumns" :data-source="items" :pagination="false" size="small" row-key="id" :scroll="{ x: 'max-content' }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'type'">
              <a-tag :color="record.type === 'part' ? 'cyan' : record.type === 'labor' ? 'blue' : 'default'">
                {{ record.type }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'unit_price'">
              {{ currencySymbol }}{{ formatNumber(record.unit_price) }}
            </template>
            <template v-else-if="column.key === 'total'">
              <strong>{{ currencySymbol }}{{ formatNumber(record.total) }}</strong>
            </template>
          </template>
          <template #footer>
            <div class="items-totals">
              <div v-if="job.diagnostic_fee > 0">
                <span>{{ $t('Diagnostic_Fee') }}</span>
                <span>{{ currencySymbol }}{{ formatNumber(job.diagnostic_fee) }}</span>
              </div>
              <div>
                <span>{{ $t('Grand_Total') }}</span>
                <strong>{{ currencySymbol }}{{ formatNumber(job.total_amount) }}</strong>
              </div>
            </div>
          </template>
        </a-table>
      </a-card>

      <!-- Photos -->
      <a-card v-if="photos.length" size="small" :title="`${$t('Photos')} (${photos.length})`" style="margin-bottom: 16px">
        <div class="photo-grid">
          <div v-for="ph in photos" :key="ph.id" class="photo-tile" @click="previewPhoto = ph">
            <img :src="ph.url" :alt="ph.original_name" />
            <a-tag color="cyan" class="photo-stage">{{ ph.stage }}</a-tag>
            <small v-if="ph.caption" style="color: #999; display: block; margin-top: 4px">{{ ph.caption }}</small>
          </div>
        </div>
        <a-modal :open="!!previewPhoto" :footer="null" width="800px" @cancel="previewPhoto = null">
          <img v-if="previewPhoto" :src="previewPhoto.url" style="max-width: 100%; margin-top: 16px" />
        </a-modal>
      </a-card>

      <!-- Payments -->
      <a-card v-if="payments.length" size="small" :title="`${$t('Payments')} (${payments.length})`" style="margin-bottom: 16px">
        <a-table :columns="paymentColumns" :data-source="payments" :pagination="false" size="small" row-key="id" :scroll="{ x: 'max-content' }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'payment_kind'">
              <a-tag :color="kindColor(record.payment_kind)">{{ record.payment_kind || 'payment' }}</a-tag>
            </template>
            <template v-else-if="column.key === 'payment_method'">
              {{ record.payment_method || '—' }}
            </template>
            <template v-else-if="column.key === 'montant'">
              <span :style="{ color: record.payment_kind === 'refund' ? '#cf1322' : undefined, fontWeight: 600 }">
                {{ record.payment_kind === 'refund' ? '-' : '' }}{{ currencySymbol }}{{ formatNumber(record.montant) }}
              </span>
            </template>
            <template v-else-if="column.key === 'notes'">
              {{ record.notes || '—' }}
            </template>
          </template>
        </a-table>
      </a-card>
    </template>

    <a-result v-else status="404" :title="$t('Job_not_found')">
      <template #extra>
        <a-button type="primary" @click="$router.push('/service/jobs')">{{ $t('Back_to_Service_Jobs') }}</a-button>
      </template>
    </a-result>
  </div>
</template>

<script setup>
/**
 * Service job details — GET service_jobs/{id} → {job, checklist, items,
 * payments, photos}. PDF via service_job_pdf/{id} blob; print opens a popup
 * with the same minimal job sheet legacy printed via $htmlToPaper.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, FilePdfOutlined, PrinterOutlined, EditOutlined,
  ToolOutlined, CheckOutlined, CheckCircleFilled, MinusCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const route = useRoute();

const isLoading = ref(true);
const isPdfLoading = ref(false);
const job = ref(null);
const checklist = ref([]);
const items = ref([]);
const payments = ref([]);
const photos = ref([]);
const previewPhoto = ref(null);
const currencySymbol = computed(() => auth.currency);

const jobId = computed(() => (route.params.id ? Number(route.params.id) : null));

const paidPercent = computed(() => {
  if (!job.value) return 0;
  const total = Number(job.value.total_amount || 0);
  const paid = Number(job.value.paid_amount || 0);
  if (total <= 0) return paid > 0 ? 100 : 0;
  return Math.min(100, Math.max(0, Math.round((paid / total) * 100)));
});
const isCancelled = computed(() =>
  job.value && (job.value.status === 'cancelled' || job.value.status === 'declined'));

const statusJourney = computed(() => [
  { title: 'Intake' },
  { title: 'Diagnostic' },
  { title: t('Quote') },
  { title: t('Approved') },
  { title: t('In_Progress') },
  { title: 'Ready' },
  { title: 'Delivered' },
]);
const currentStageIndex = computed(() => {
  if (!job.value) return 0;
  const map = {
    pending: 1, intake: 1, diagnostic: 2, quoted: 3, approved: 4,
    in_progress: 5, ready: 6, delivered: 7, completed: 7,
  };
  return map[job.value.status] || 1;
});

const itemColumns = computed(() => [
  { title: t('Type'), key: 'type', width: 90 },
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  { title: t('Qty'), dataIndex: 'quantity', key: 'quantity', align: 'right' },
  { title: t('UnitPrice'), key: 'unit_price', align: 'right' },
  { title: t('Total'), key: 'total', align: 'right' },
]);
const paymentColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Kind'), key: 'payment_kind', width: 110 },
  { title: t('Payment_Method'), key: 'payment_method' },
  { title: t('Amount'), key: 'montant', align: 'right' },
  { title: t('Notes'), key: 'notes' },
]);

function statusLabel(status) {
  const map = {
    pending: t('Pending'), intake: 'Intake', diagnostic: 'Diagnostic',
    quoted: 'Quoted', approved: t('Approved'), in_progress: t('In_Progress'),
    ready: 'Ready for Pickup', delivered: 'Delivered', declined: 'Declined',
    completed: t('complete'), cancelled: t('Cancelled'),
  };
  return map[status] || status;
}
function statusColor(s) {
  const map = {
    delivered: 'success', ready: 'success', completed: 'success',
    approved: 'processing', in_progress: 'processing',
    quoted: 'cyan', diagnostic: 'cyan', intake: 'cyan',
    pending: 'warning', declined: 'error', cancelled: 'error',
  };
  return map[s] || 'default';
}
function paymentColor(s) {
  if (s === 'paid') return 'success';
  if (s === 'partial') return 'warning';
  return 'error';
}
function kindColor(kind) {
  if (kind === 'deposit') return 'warning';
  if (kind === 'refund') return 'error';
  return 'success';
}
function formatNumber(n) { return (Number(n) || 0).toFixed(2); }
function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString();
}
function formatDateTime(d) {
  if (!d) return '-';
  return new Date(d).toLocaleString();
}
function isWarrantyActive(d) {
  if (!d) return false;
  try { return new Date(d) >= new Date(); } catch (e) { return false; }
}

async function downloadPdf() {
  isPdfLoading.value = true;
  try {
    await http.download(`service_job_pdf/${jobId.value}`, `Service_Job_${jobId.value}.pdf`);
    message.success(t('PDF_downloaded_successfully'));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isPdfLoading.value = false;
  }
}

function printJob() {
  if (!job.value) return;
  const j = job.value;
  const esc = s => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const checklistRows = checklist.value.map(item => `
    <tr>
      <td style="padding:8px;">${item.is_completed ? '&#10003; Completed' : '&#9675; Pending'}</td>
      <td style="padding:8px;">${esc(item.category_name || '-')}</td>
      <td style="padding:8px;">${esc(item.item_name)}</td>
    </tr>`).join('');
  const html = `
    <div style="padding:20px;font-family:Arial,sans-serif;">
      <div style="text-align:center;margin-bottom:20px;border-bottom:2px solid #1a56db;padding-bottom:15px;">
        <h1 style="color:#1a56db;margin:0 0 10px 0;">SERVICE JOB</h1>
        <h2 style="color:#4b5563;margin:0;">${esc(j.Ref)}</h2>
      </div>
      <table style="width:100%;margin-bottom:20px;" cellpadding="5" cellspacing="0">
        <tr>
          <td style="width:50%;vertical-align:top;">
            <h3 style="color:#1a56db;margin:0 0 10px 0;font-size:14px;">CUSTOMER</h3>
            <p style="margin:5px 0;"><strong>Name:</strong> ${esc(j.client_name || '-')}</p>
            ${j.client_phone ? `<p style="margin:5px 0;"><strong>Phone:</strong> ${esc(j.client_phone)}</p>` : ''}
            ${j.client_email ? `<p style="margin:5px 0;"><strong>Email:</strong> ${esc(j.client_email)}</p>` : ''}
          </td>
          <td style="width:50%;vertical-align:top;">
            <h3 style="color:#1a56db;margin:0 0 10px 0;font-size:14px;">JOB INFORMATION</h3>
            <p style="margin:5px 0;"><strong>Service Item:</strong> ${esc(j.service_item || '-')}</p>
            ${j.job_type ? `<p style="margin:5px 0;"><strong>Job Type:</strong> ${esc(j.job_type)}</p>` : ''}
            <p style="margin:5px 0;"><strong>Technician:</strong> ${esc(j.technician_name || '-')}</p>
            ${j.scheduled_date ? `<p style="margin:5px 0;"><strong>Scheduled Date:</strong> ${esc(formatDateTime(j.scheduled_date))}</p>` : ''}
            <p style="margin:5px 0;"><strong>Status:</strong> ${esc(statusLabel(j.status))}</p>
          </td>
        </tr>
      </table>
      ${j.notes ? `
      <div style="margin-bottom:20px;padding:10px;background:#f9fafb;border-left:3px solid #1a56db;">
        <h3 style="color:#1a56db;margin:0 0 10px 0;font-size:14px;">NOTES</h3>
        <p style="margin:0;white-space:pre-line;">${esc(j.notes)}</p>
      </div>` : ''}
      ${checklist.value.length ? `
      <div style="margin-bottom:20px;">
        <h3 style="color:#1a56db;margin:0 0 10px 0;font-size:14px;">CHECKLIST</h3>
        <table style="width:100%;border-collapse:collapse;" cellpadding="5" cellspacing="0" border="1">
          <thead>
            <tr style="background:#1a56db;color:white;">
              <th style="padding:8px;text-align:left;">Status</th>
              <th style="padding:8px;text-align:left;">Category</th>
              <th style="padding:8px;text-align:left;">Item</th>
            </tr>
          </thead>
          <tbody>${checklistRows}</tbody>
        </table>
      </div>` : ''}
      <div style="margin-top:30px;text-align:center;padding-top:15px;border-top:2px solid #e5e7eb;">
        <p style="color:#1a56db;font-weight:bold;margin:0;">Thank you for your business!</p>
      </div>
    </div>`;
  const win = window.open('', '_blank', 'width=900,height=700');
  if (!win) return;
  win.document.write(`<!DOCTYPE html><html><head><title>${esc(j.Ref)}</title></head><body>${html}</body></html>`);
  win.document.close();
  win.focus();
  setTimeout(() => { win.print(); win.close(); }, 300);
}

onMounted(async () => {
  if (!jobId.value) { isLoading.value = false; return; }
  try {
    const data = await http.get(`service_jobs/${jobId.value}`);
    job.value = data.job || null;
    checklist.value = data.checklist || [];
    items.value = data.items || [];
    payments.value = data.payments || [];
    photos.value = data.photos || [];
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
});
</script>

<style scoped>
.job-head {
  display: flex;
  align-items: center;
  gap: 16px;
}
.job-head-title {
  font-size: 18px;
  font-weight: 600;
}
.job-head-meta {
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
.journey-steps {
  overflow-x: auto;
  padding-top: 4px;
}
.intake-block {
  background: rgba(0, 0, 0, 0.02);
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
  padding: 12px 14px;
  height: 100%;
}
.intake-label {
  font-size: 12px;
  text-transform: uppercase;
  color: rgba(0, 0, 0, 0.45);
  margin-bottom: 6px;
  letter-spacing: 0.04em;
}
.intake-block p {
  margin: 0;
  white-space: pre-line;
}
.check-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid rgba(5, 5, 5, 0.06);
  border-radius: 8px;
}
.check-item--done {
  background: #f6ffed;
  border-color: #b7eb8f;
}
.items-totals {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}
.items-totals > div {
  display: flex;
  gap: 24px;
}
.photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}
.photo-tile {
  border: 1px solid rgba(5, 5, 5, 0.1);
  border-radius: 8px;
  padding: 6px;
  cursor: zoom-in;
  position: relative;
}
.photo-tile img {
  width: 100%;
  height: 140px;
  object-fit: cover;
  border-radius: 6px;
  display: block;
}
.photo-stage {
  position: absolute;
  top: 12px;
  left: 12px;
}
</style>
