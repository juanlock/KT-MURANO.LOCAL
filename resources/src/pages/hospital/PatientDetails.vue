<template>
  <div class="page">
    <PageHeader
      :title="patient ? patient.name : 'Patient'"
      :subtitle="patient ? subtitle : ''"
      :breadcrumb="['Hospital', 'Patients', patient ? patient.mrn : '']"
    >
      <template #actions>
        <a-button @click="$router.push('/hospital/patients')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button v-if="canEdit" type="primary" @click="$router.push(`/hospital/patients/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <template v-else-if="patient">
      <!-- Clinical alerts come first: they change what a clinician does next. -->
      <a-alert
        v-if="patient.allergies" type="error" show-icon class="alert"
        :message="`Allergies: ${patient.allergies}`"
      />
      <a-alert
        v-if="patient.chronic_conditions" type="warning" show-icon class="alert"
        :message="`Chronic: ${patient.chronic_conditions}`"
      />
      <a-alert
        v-if="patient.current_admission" type="info" show-icon class="alert"
        :message="`Currently admitted — ${patient.current_admission.ward_name || 'ward'} / ${patient.current_admission.bed_number || '—'}, ${patient.current_admission.nights} night(s)`"
      />

      <a-row :gutter="16">
        <a-col :xs="24" :lg="8">
          <a-card size="small" style="margin-bottom: 16px">
            <div class="id-head">
              <a-avatar :size="64" :src="patient.image_url" class="id-avatar">
                {{ initials(patient.name) }}
              </a-avatar>
              <div class="id-text">
                <div class="id-mrn">{{ patient.mrn }}</div>
                <a-tag :color="optionOf(PATIENT_STATUSES, patient.status).color">
                  {{ labelOf(PATIENT_STATUSES, patient.status) }}
                </a-tag>
                <a-tag v-if="patient.blood_group" color="red">{{ patient.blood_group }}</a-tag>
              </div>
            </div>

            <a-descriptions :column="1" size="small" class="id-desc">
              <a-descriptions-item label="Age">{{ patient.age !== null ? patient.age : '—' }}</a-descriptions-item>
              <a-descriptions-item label="Gender">{{ labelOf(GENDERS, patient.gender) }}</a-descriptions-item>
              <a-descriptions-item label="Born">{{ patient.date_of_birth ? date(patient.date_of_birth) : '—' }}</a-descriptions-item>
              <a-descriptions-item label="Phone">{{ patient.phone || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Email">{{ patient.email || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Address">{{ [patient.address, patient.city].filter(Boolean).join(', ') || '—' }}</a-descriptions-item>
              <a-descriptions-item label="National ID">{{ patient.national_id || '—' }}</a-descriptions-item>
            </a-descriptions>
          </a-card>

          <a-card size="small" title="Emergency contact" style="margin-bottom: 16px">
            <a-descriptions v-if="patient.emergency_contact_name" :column="1" size="small">
              <a-descriptions-item label="Name">{{ patient.emergency_contact_name }}</a-descriptions-item>
              <a-descriptions-item label="Phone">{{ patient.emergency_contact_phone || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Relation">{{ patient.emergency_contact_relation || '—' }}</a-descriptions-item>
            </a-descriptions>
            <a-empty v-else :image="simpleEmptyImage" description="None recorded" />
          </a-card>

          <a-card size="small" title="Insurance">
            <a-descriptions v-if="patient.insurance_provider || patient.insurance_number" :column="1" size="small">
              <a-descriptions-item label="Provider">{{ patient.insurance_provider || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Policy">{{ patient.insurance_number || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Expires">
                <span :class="{ danger: insuranceExpired }">
                  {{ patient.insurance_expiry ? date(patient.insurance_expiry) : '—' }}
                </span>
              </a-descriptions-item>
            </a-descriptions>
            <a-empty v-else :image="simpleEmptyImage" description="No policy on file" />
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="16">
          <div class="stats">
            <div class="stat">
              <span class="stat-label">Consultations</span>
              <span class="stat-value">{{ stats.visits || 0 }}</span>
            </div>
            <div class="stat">
              <span class="stat-label">Admissions</span>
              <span class="stat-value">{{ stats.admissions || 0 }}</span>
            </div>
            <div class="stat">
              <span class="stat-label">Billed</span>
              <span class="stat-value">{{ money(stats.billed || 0) }}</span>
            </div>
            <div class="stat" :class="{ 'stat--danger': (stats.due || 0) > 0 }">
              <span class="stat-label">Outstanding</span>
              <span class="stat-value">{{ money(stats.due || 0) }}</span>
            </div>
          </div>

          <a-card size="small">
            <a-tabs v-model:activeKey="tab">
              <a-tab-pane key="visits">
                <template #tab>Consultations <a-badge :count="stats.visits || 0" :number-style="badge" /></template>
                <a-table
                  :columns="visitColumns" :data-source="timeline.visits || []" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'visit_date'">{{ dateTime(record.visit_date) }}</template>
                    <template v-else-if="column.key === 'type'">
                      <a-tag :color="optionOf(VISIT_TYPES, record.type).color">{{ labelOf(VISIT_TYPES, record.type) }}</a-tag>
                    </template>
                    <template v-else-if="column.key === 'fee'">{{ money(record.fee) }}</template>
                    <template v-else-if="column.key === 'open'">
                      <a-button type="link" size="small" @click="$router.push(`/hospital/visits/${record.id}/edit`)">
                        Open
                      </a-button>
                    </template>
                  </template>
                  <template #emptyText><a-empty :image="simpleEmptyImage" description="No consultations yet" /></template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="appointments">
                <template #tab>Appointments <a-badge :count="stats.appointments || 0" :number-style="badge" /></template>
                <a-table
                  :columns="appointmentColumns" :data-source="timeline.appointments || []" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'scheduled_at'">{{ dateTime(record.scheduled_at) }}</template>
                    <template v-else-if="column.key === 'status'">
                      <a-tag :color="optionOf(APPOINTMENT_STATUSES, record.status).color">
                        {{ labelOf(APPOINTMENT_STATUSES, record.status) }}
                      </a-tag>
                    </template>
                    <template v-else-if="column.key === 'type'">{{ labelOf(APPOINTMENT_TYPES, record.type) }}</template>
                  </template>
                  <template #emptyText><a-empty :image="simpleEmptyImage" description="No appointments" /></template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="admissions">
                <template #tab>Admissions <a-badge :count="stats.admissions || 0" :number-style="badge" /></template>
                <a-table
                  :columns="admissionColumns" :data-source="timeline.admissions || []" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'admitted_at'">{{ dateTime(record.admitted_at) }}</template>
                    <template v-else-if="column.key === 'discharged_at'">
                      {{ record.discharged_at ? dateTime(record.discharged_at) : '—' }}
                    </template>
                    <template v-else-if="column.key === 'status'">
                      <a-tag :color="optionOf(ADMISSION_STATUSES, record.status).color">
                        {{ labelOf(ADMISSION_STATUSES, record.status) }}
                      </a-tag>
                    </template>
                  </template>
                  <template #emptyText><a-empty :image="simpleEmptyImage" description="Never admitted" /></template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="prescriptions">
                <template #tab>Prescriptions</template>
                <div v-if="(timeline.prescriptions || []).length" class="rx-list">
                  <a-card v-for="rx in timeline.prescriptions" :key="rx.id" size="small" class="rx">
                    <template #title>
                      <span class="rx-title">{{ rx.reference }}</span>
                      <span class="rx-meta">{{ date(rx.prescribed_on) }}{{ rx.doctor_name ? ` · ${rx.doctor_name}` : '' }}</span>
                    </template>
                    <ul class="rx-items">
                      <li v-for="(item, i) in rx.items" :key="i">
                        <b>{{ item.medicine }}</b>
                        <span class="rx-dose">
                          {{ [item.dosage, item.frequency, item.duration].filter(Boolean).join(' · ') }}
                        </span>
                        <span v-if="item.instructions" class="rx-note">{{ item.instructions }}</span>
                      </li>
                    </ul>
                  </a-card>
                </div>
                <a-empty v-else :image="simpleEmptyImage" description="Nothing prescribed yet" style="padding: 24px 0" />
              </a-tab-pane>

              <a-tab-pane key="lab">
                <template #tab>Lab <a-badge :count="stats.lab_orders || 0" :number-style="badge" /></template>
                <a-table
                  :columns="labColumns" :data-source="timeline.lab_orders || []" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'ordered_at'">{{ dateTime(record.ordered_at) }}</template>
                    <template v-else-if="column.key === 'tests'">
                      <a-tag v-for="test in record.tests.slice(0, 3)" :key="test">{{ test }}</a-tag>
                      <a-tag v-if="record.tests.length > 3">+{{ record.tests.length - 3 }}</a-tag>
                    </template>
                    <template v-else-if="column.key === 'status'">
                      <a-tag :color="optionOf(LAB_STATUSES, record.status).color">
                        {{ labelOf(LAB_STATUSES, record.status) }}
                      </a-tag>
                    </template>
                    <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
                  </template>
                  <template #emptyText><a-empty :image="simpleEmptyImage" description="No lab orders" /></template>
                </a-table>
              </a-tab-pane>

              <a-tab-pane key="billing">
                <template #tab>Billing</template>
                <a-table
                  :columns="invoiceColumns" :data-source="timeline.invoices || []" :loading="tabLoading"
                  :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'invoice_date'">{{ date(record.invoice_date) }}</template>
                    <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
                    <template v-else-if="column.key === 'paid'">{{ money(record.paid) }}</template>
                    <template v-else-if="column.key === 'due'">
                      <span :class="{ danger: record.due > 0 }">{{ money(record.due) }}</span>
                    </template>
                    <template v-else-if="column.key === 'status'">
                      <a-tag :color="optionOf(INVOICE_STATUSES, record.status).color">
                        {{ labelOf(INVOICE_STATUSES, record.status) }}
                      </a-tag>
                    </template>
                  </template>
                  <template #emptyText><a-empty :image="simpleEmptyImage" description="Nothing billed" /></template>
                </a-table>
              </a-tab-pane>
            </a-tabs>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <a-empty v-else description="Patient not found" style="padding: 64px 0" />
  </div>
</template>

<script setup>
/**
 * One patient's complete record: identity, alerts and every episode of care.
 *
 * The timeline arrives in ONE call (hospital/patients/{id}/timeline) rather than
 * per tab — the tabs are different views of the same history, and a clinician
 * switching between them should never wait.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import {
  GENDERS, PATIENT_STATUSES, VISIT_TYPES, APPOINTMENT_TYPES, APPOINTMENT_STATUSES,
  ADMISSION_STATUSES, LAB_STATUSES, INVOICE_STATUSES, labelOf, optionOf,
} from './hospitalOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const auth = useAuthStore();
const { money, date, dateTime } = useFormat();

const id = computed(() => route.params.id);
const canEdit = computed(() => auth.can('hms_patients_edit'));
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badge = { backgroundColor: '#6d28d9' };

const patient = ref(null);
const timeline = ref({});
const loading = ref(false);
const tabLoading = ref(false);
const tab = ref('visits');

const stats = computed(() => patient.value?.stats || {});

const subtitle = computed(() => {
  const p = patient.value;
  if (!p) return '';
  return [
    p.age !== null ? `${p.age} yrs` : null,
    labelOf(GENDERS, p.gender),
    p.blood_group,
    p.phone,
  ].filter(Boolean).join(' · ');
});

const insuranceExpired = computed(() => {
  const expiry = patient.value?.insurance_expiry;
  return expiry ? new Date(expiry) < new Date(new Date().toDateString()) : false;
});

const visitColumns = [
  { title: 'Date', key: 'visit_date', dataIndex: 'visit_date', width: 160 },
  { title: 'Ref', dataIndex: 'reference', key: 'reference', width: 150 },
  { title: 'Type', key: 'type', dataIndex: 'type', width: 110 },
  { title: 'Doctor', dataIndex: 'doctor_name', key: 'doctor_name', width: 150 },
  { title: 'Diagnosis', dataIndex: 'diagnosis', key: 'diagnosis' },
  { title: 'BP', dataIndex: 'blood_pressure', key: 'bp', width: 90 },
  { title: 'Fee', key: 'fee', dataIndex: 'fee', width: 110 },
  { title: '', key: 'open', width: 80 },
];
const appointmentColumns = [
  { title: 'When', key: 'scheduled_at', dataIndex: 'scheduled_at', width: 170 },
  { title: 'Ref', dataIndex: 'reference', key: 'reference', width: 150 },
  { title: 'Doctor', dataIndex: 'doctor_name', key: 'doctor_name' },
  { title: 'Type', key: 'type', dataIndex: 'type', width: 130 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 120 },
];
const admissionColumns = [
  { title: 'Admitted', key: 'admitted_at', dataIndex: 'admitted_at', width: 170 },
  { title: 'Discharged', key: 'discharged_at', dataIndex: 'discharged_at', width: 170 },
  { title: 'Ward', dataIndex: 'ward_name', key: 'ward_name' },
  { title: 'Bed', dataIndex: 'bed_number', key: 'bed_number', width: 90 },
  { title: 'Nights', dataIndex: 'nights', key: 'nights', width: 80 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 130 },
];
const labColumns = [
  { title: 'Ordered', key: 'ordered_at', dataIndex: 'ordered_at', width: 170 },
  { title: 'Ref', dataIndex: 'reference', key: 'reference', width: 150 },
  { title: 'Tests', key: 'tests', dataIndex: 'tests' },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 150 },
  { title: 'Total', key: 'total', dataIndex: 'total', width: 110 },
];
const invoiceColumns = [
  { title: 'Date', key: 'invoice_date', dataIndex: 'invoice_date', width: 130 },
  { title: 'Ref', dataIndex: 'reference', key: 'reference', width: 150 },
  { title: 'Total', key: 'total', dataIndex: 'total', width: 120 },
  { title: 'Paid', key: 'paid', dataIndex: 'paid', width: 120 },
  { title: 'Due', key: 'due', dataIndex: 'due', width: 120 },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 130 },
];

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2)
    .map(part => part[0].toUpperCase()).join('');
}

async function load() {
  loading.value = true;
  tabLoading.value = true;
  try {
    const [record, history] = await Promise.all([
      http.get(`hospital/patients/${id.value}`),
      http.get(`hospital/patients/${id.value}/timeline`).catch(() => ({})),
    ]);
    patient.value = record?.patient || null;
    timeline.value = history || {};
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this patient'));
  } finally {
    loading.value = false;
    tabLoading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.danger {
  color: #ff4d4f;
}
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.alert {
  margin-bottom: 12px;
}
.id-head {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 14px;
}
.id-avatar {
  flex: none;
  background: rgba(109, 40, 217, 0.15);
  color: #6d28d9;
  font-size: 20px;
}
.id-mrn {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
  margin-bottom: 6px;
}
.id-desc :deep(.ant-descriptions-item) {
  padding-bottom: 6px !important;
}
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.stat--danger {
  border-color: rgba(255, 77, 79, 0.4);
  background: rgba(255, 77, 79, 0.05);
}
.stat-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.stat-value {
  font-size: 18px;
  font-weight: 600;
}
.rx-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.rx-title {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
}
.rx-meta {
  margin-inline-start: 10px;
  font-size: 12px;
  font-weight: 400;
  opacity: 0.6;
}
.rx-items {
  margin: 0;
  padding-inline-start: 18px;
}
.rx-items li {
  margin-bottom: 6px;
}
.rx-dose {
  margin-inline-start: 8px;
  font-size: 12.5px;
  opacity: 0.7;
}
.rx-note {
  display: block;
  font-size: 12px;
  font-style: italic;
  opacity: 0.6;
}
</style>
