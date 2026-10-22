<template>
  <div class="page">
    <PageHeader :title="s('Treatments')" :breadcrumb="[s('Hospital'), s('Treatments')]">
      <template #actions>
        <a-button v-if="canEdit" type="primary" @click="openNew"><template #icon><PlusOutlined /></template>{{ s('New treatment') }}</a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 12px">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="s('Search patient, MRN, reference or diagnosis…')" allow-clear class="tb-search" @search="load" />
        <a-radio-group v-model:value="status" button-style="solid" @change="load">
          <a-radio-button value="active">{{ s('Active') }}</a-radio-button>
          <a-radio-button value="completed">{{ s('Completed') }}</a-radio-button>
          <a-radio-button value="cancelled">{{ s('Cancelled') }}</a-radio-button>
        </a-radio-group>
      </div>
    </a-card>

    <a-table :data-source="rows" :columns="columns" row-key="id" size="small" :loading="loading" :pagination="{ pageSize: 20 }" :scroll="{ x: 900 }"
      :custom-row="r => ({ onClick: () => openDetail(r.id), style: 'cursor: pointer' })">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'patient'">
          <b>{{ record.patient_name }}</b>
          <div class="muted">{{ record.patient_mrn }} · {{ record.reference }}</div>
        </template>
        <template v-else-if="column.key === 'where'">{{ s(AREA_LABEL[record.area] || '—') }}<template v-if="record.where"> · {{ record.where }}</template></template>
        <template v-else-if="column.key === 'doses'">
          <a-tag v-if="record.doses_late" color="red">{{ record.doses_late }} {{ s('late') }}</a-tag>
          <a-tag v-if="record.doses_due - record.doses_late > 0" color="orange">{{ record.doses_due - record.doses_late }} {{ s('due') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'started_at'">{{ dt(record.started_at) }}</template>
      </template>
    </a-table>

    <!-- New treatment -->
    <a-modal v-model:open="newOpen" :title="s('New treatment')" :ok-text="s('Save')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="createTreatment">
      <a-form layout="vertical">
        <a-form-item :label="s('Patient')" required>
          <a-select v-model:value="nf.patient_id" show-search option-filter-prop="label" :options="censusOptions" :placeholder="s('Patients in the hospital')" />
        </a-form-item>
        <a-form-item :label="s('Diagnosis')"><a-textarea v-model:value="nf.diagnosis" :rows="2" /></a-form-item>
        <a-form-item :label="s('Plan')"><a-textarea v-model:value="nf.plan" :rows="3" /></a-form-item>
        <a-form-item :label="s('Lead doctor')"><a-select v-model:value="nf.lead_doctor_id" show-search option-filter-prop="label" :options="doctorOptions" /></a-form-item>
        <a-form-item :label="s('Other doctors')"><a-select v-model:value="nf.doctor_ids" mode="multiple" option-filter-prop="label" :options="doctorOptions" /></a-form-item>
      </a-form>
    </a-modal>

    <!-- Detail -->
    <a-drawer v-model:open="detailOpen" :width="drawerWidth" :title="t ? `${t.reference} · ${t.patient_name}` : ''" destroy-on-close>
      <a-spin :spinning="detailLoading">
        <template v-if="t">
          <div class="head">
            <a-tag :color="t.status === 'active' ? 'green' : 'default'">{{ s(STATUS_LABEL[t.status]) }}</a-tag>
            <span>{{ t.patient_mrn }}</span>
            <span v-if="t.age != null">· {{ t.age }} {{ s('y') }}</span>
            <span v-if="t.blood_group">· {{ t.blood_group }}</span>
            <span>· {{ s(AREA_LABEL[t.area] || '—') }}<template v-if="t.where"> {{ t.where }}</template></span>
            <span v-if="t.surgery_id">· <a-tag color="purple">{{ s('Post-op') }}</a-tag></span>
          </div>
          <div v-if="t.allergies" class="allergy"><WarningOutlined /> {{ s('Allergies') }}: {{ t.allergies }}</div>

          <a-tabs v-model:activeKey="dtab">
            <a-tab-pane key="sheet" :tab="s('Medication sheet')">
              <div class="sheet-nav">
                <a-button size="small" @click="moveSheet(-12)"><LeftOutlined /></a-button>
                <span>{{ dt(sheetFrom) }} → {{ dt(dayjs(sheetFrom).add(48, 'hour')) }}</span>
                <a-button size="small" @click="moveSheet(12)"><RightOutlined /></a-button>
                <a-button size="small" @click="sheetNow">{{ s('Now') }}</a-button>
              </div>
              <a-empty v-if="!t.doses.length" :description="s('No doses in this period')" />
              <div v-for="d in t.doses" :key="d.id" class="dose" :class="[d.status, { late: d.late }]">
                <span class="d-time">{{ dt(d.scheduled_at) }}</span>
                <span class="d-med">{{ orderOf(d.order_id).medicine }} · {{ orderOf(d.order_id).dose }} · {{ s(ROUTE_LABEL[orderOf(d.order_id).route] || '') }}</span>
                <a-tag v-if="!d.dispensed && d.status === 'pending'" color="orange">{{ s('Not dispensed') }}</a-tag>
                <template v-if="d.status === 'pending'">
                  <template v-if="canGive">
                    <a-button size="small" type="primary" @click="record(d, 'given')">{{ s('Given') }}</a-button>
                    <a-button size="small" @click="record(d, 'omitted')">{{ s('Omitted') }}</a-button>
                    <a-button size="small" @click="record(d, 'refused')">{{ s('Refused') }}</a-button>
                  </template>
                  <span v-else class="muted">{{ s('Pending') }}</span>
                </template>
                <template v-else>
                  <a-tag :color="d.status === 'given' ? 'green' : 'red'">{{ s(DOSE_LABEL[d.status]) }}</a-tag>
                  <span class="muted">{{ dt(d.given_at) }}<template v-if="d.given_by"> · {{ d.given_by }}</template></span>
                  <a-button v-if="canGive" size="small" type="text" @click="record(d, 'pending')">{{ s('Undo') }}</a-button>
                </template>
              </div>
            </a-tab-pane>

            <a-tab-pane key="orders" :tab="s('Medication orders')">
              <a-table :data-source="t.orders" :columns="orderColumns" row-key="id" size="small" :pagination="false" :scroll="{ x: 700 }">
                <template #bodyCell="{ column, record: o }">
                  <template v-if="column.key === 'med'"><b>{{ o.medicine }}</b><div class="muted">{{ o.instructions }}</div></template>
                  <template v-else-if="column.key === 'how'">{{ o.dose }} · {{ s(ROUTE_LABEL[o.route] || o.route) }} · {{ s('every') }} {{ o.every_hours }} h · {{ o.days }} {{ s('days') }}</template>
                  <template v-else-if="column.key === 'progress'">{{ o.given }} / {{ o.given + o.pending + o.omitted }}</template>
                  <template v-else-if="column.key === 'status'">
                    <a-tag :color="o.status === 'active' ? 'green' : 'default'">{{ s(ORDER_LABEL[o.status] || o.status) }}</a-tag>
                    <a-popconfirm v-if="canEdit && o.status === 'active'" :title="s('Stop this order? Its future doses are removed.')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="stopOrder(o)">
                      <a-button size="small" type="link" danger>{{ s('Stop') }}</a-button>
                    </a-popconfirm>
                  </template>
                </template>
              </a-table>
              <a-card v-if="canEdit && t.status === 'active'" size="small" :title="s('New medication order')" style="margin-top: 12px">
                <a-row :gutter="8">
                  <a-col :xs="24" :md="12"><a-form-item :label="s('Medicine (pharmacy)')"><a-select v-model:value="of.product_id" allow-clear show-search option-filter-prop="label" :options="medicineOptions" /></a-form-item></a-col>
                  <a-col :xs="24" :md="12"><a-form-item :label="s('Or type it')"><a-input v-model:value="of.medicine" :disabled="!!of.product_id" /></a-form-item></a-col>
                  <a-col :xs="12" :md="6"><a-form-item :label="s('Dose')"><a-input v-model:value="of.dose" :placeholder="s('e.g. 500 mg')" /></a-form-item></a-col>
                  <a-col :xs="12" :md="6"><a-form-item :label="s('Route')"><a-select v-model:value="of.route" :options="routeOptions" /></a-form-item></a-col>
                  <a-col :xs="12" :md="6"><a-form-item :label="s('Every (hours)')"><a-select v-model:value="of.every_hours" :options="[1,2,3,4,6,8,12,24].map(h => ({ value: h, label: `${h} h` }))" /></a-form-item></a-col>
                  <a-col :xs="12" :md="6"><a-form-item :label="s('Days')"><a-input-number v-model:value="of.days" :min="1" :max="60" style="width: 100%" /></a-form-item></a-col>
                  <a-col :xs="12" :md="8"><a-form-item :label="s('First dose')"><a-date-picker v-model:value="of.starts_at" show-time format="DD/MM/YYYY HH:mm" value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" /></a-form-item></a-col>
                  <a-col :xs="12" :md="6"><a-form-item :label="s('Units per dose')"><a-input-number v-model:value="of.units_per_dose" :min="0" :max="100" :step="0.5" style="width: 100%" /></a-form-item></a-col>
                  <a-col :xs="24" :md="10"><a-form-item :label="s('Instructions')"><a-input v-model:value="of.instructions" /></a-form-item></a-col>
                </a-row>
                <a-button type="primary" :loading="saving" @click="addOrder">{{ s('Add order') }}</a-button>
              </a-card>
            </a-tab-pane>

            <a-tab-pane key="team" :tab="s('Care team')">
              <div class="d-title">{{ s('Doctors') }}</div>
              <div v-if="!t.doctors.length" class="muted">—</div>
              <div v-for="d in t.doctors" :key="d.id">{{ d.name }} <span class="muted">· {{ d.specialty }} · {{ s(ROLE_LABEL[d.role] || d.role) }}</span></div>
              <div class="d-title" style="margin-top: 12px">{{ s('By shift (today)') }}</div>
              <div v-if="!t.team.length" class="muted">{{ s('Nobody has this patient in a shift yet (Staff shifts).') }}</div>
              <div v-for="(m, i) in t.team" :key="i">{{ m.date }} · {{ s(SHIFT_LABEL[m.shift]) }} · {{ s(POS_LABEL[m.position]) }}: <b>{{ m.name }}</b></div>
            </a-tab-pane>

            <a-tab-pane key="info" :tab="s('Diagnosis and plan')">
              <a-form layout="vertical">
                <a-form-item :label="s('Diagnosis')"><a-textarea v-model:value="ef.diagnosis" :rows="3" :disabled="!canEdit" /></a-form-item>
                <a-form-item :label="s('Plan')"><a-textarea v-model:value="ef.plan" :rows="5" :disabled="!canEdit" /></a-form-item>
                <a-form-item :label="s('Lead doctor')"><a-select v-model:value="ef.lead_doctor_id" show-search option-filter-prop="label" :options="doctorOptions" :disabled="!canEdit" /></a-form-item>
                <a-form-item :label="s('Other doctors')"><a-select v-model:value="ef.doctor_ids" mode="multiple" option-filter-prop="label" :options="doctorOptions" :disabled="!canEdit" /></a-form-item>
                <a-space v-if="canEdit">
                  <a-button type="primary" :loading="saving" @click="saveInfo()">{{ s('Save') }}</a-button>
                  <a-popconfirm v-if="t.status === 'active'" :title="s('Finish this treatment? Pending future doses are removed.')" :ok-text="s('Yes')" :cancel-text="s('No')" @confirm="saveInfo('completed')">
                    <a-button>{{ s('Finish treatment') }}</a-button>
                  </a-popconfirm>
                  <a-button v-else @click="saveInfo('active')">{{ s('Reopen') }}</a-button>
                </a-space>
              </a-form>
            </a-tab-pane>
          </a-tabs>
        </template>
      </a-spin>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Treatments (2026-10-21): list of treatments and, per treatment, the
 * medication sheet (doses every N hours, given / omitted / refused by the
 * nurse), the medication orders, the care team (doctors + who has the patient
 * each shift) and the diagnosis / plan. Opens ?id= directly (My patients).
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { PlusOutlined, WarningOutlined, LeftOutlined, RightOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import { sortByLabel } from '../../lib/filterOptions';
import http from '../../lib/http';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/Treatments.es.js';

const { s } = useHospitalText(ES);
const auth = useAuthStore();
const route = useRoute();
const canEdit = computed(() => auth.can('hms_treatments'));
const canGive = computed(() => auth.can('hms_treatments_administer') || auth.can('hms_treatments'));

const AREA_LABEL = { ward: 'Ward', er_bed: 'ER bed', waiting_room: 'Waiting room' };
const STATUS_LABEL = { active: 'Active', completed: 'Completed', cancelled: 'Cancelled' };
const ORDER_LABEL = { active: 'Active', stopped: 'Stopped', completed: 'Completed' };
const DOSE_LABEL = { given: 'Given', omitted: 'Omitted', refused: 'Refused', pending: 'Pending' };
const ROUTE_LABEL = { oral: 'Oral', iv: 'IV', im: 'IM', sc: 'SC', topical: 'Topical', inhaled: 'Inhaled', sublingual: 'Sublingual', rectal: 'Rectal', other: 'Other' };
const ROLE_LABEL = { lead: 'Lead', consultant: 'Consultant', surgeon: 'Surgeon' };
const SHIFT_LABEL = { er24: 'Emergency guard (24 h)', morning: 'Morning', afternoon: 'Afternoon', night: 'Night' };
const POS_LABEL = { er_doctor: 'Emergency doctor', specialist: 'Specialist', nurse_supervisor: 'Nurse supervisor', nurse: 'Nurse' };

const loading = ref(false);
const saving = ref(false);
const rows = ref([]);
const search = ref('');
const status = ref('active');
const meta = ref({ doctors: [], census: [], medicines: [], routes: [] });
const dt = iso => (iso ? dayjs(iso).format('DD/MM HH:mm') : '—');
const drawerWidth = computed(() => (window.innerWidth < 900 ? '100%' : 860));

const doctorOptions = computed(() => sortByLabel(meta.value.doctors.map(d => ({ value: d.id, label: d.specialty ? `${d.name} (${d.specialty})` : d.name }))));
const censusOptions = computed(() => sortByLabel(meta.value.census.map(c => ({ value: c.patient_id, label: `${c.name} · ${c.mrn}${c.where ? ' · ' + c.where : ''}` }))));
const medicineOptions = computed(() => sortByLabel(meta.value.medicines.map(m => ({ value: m.id, label: [m.name, m.generic_name, m.strength].filter(Boolean).join(' · ') }))));
const routeOptions = computed(() => meta.value.routes.map(r => ({ value: r, label: s(ROUTE_LABEL[r] || r) })));
const columns = computed(() => [
  { title: s('Patient'), key: 'patient' }, { title: s('Where'), key: 'where' }, { title: s('Diagnosis'), dataIndex: 'diagnosis', ellipsis: true },
  { title: s('Lead doctor'), dataIndex: 'lead_doctor' }, { title: s('Doses'), key: 'doses', width: 150 }, { title: s('Started'), key: 'started_at', width: 120 },
]);
const orderColumns = computed(() => [
  { title: s('Medicine'), key: 'med' }, { title: s('How'), key: 'how' }, { title: s('Given'), key: 'progress', width: 90 }, { title: s('Status'), key: 'status', width: 150 },
]);

async function load() {
  loading.value = true;
  try {
    rows.value = (await http.get('hospital/treatments', { status: status.value, search: search.value || '' }))?.treatments || [];
  } catch (e) {
    message.error(e?.data?.message || s('Could not load'));
  } finally {
    loading.value = false;
  }
}

const newOpen = ref(false);
const nf = reactive({ patient_id: undefined, diagnosis: '', plan: '', lead_doctor_id: undefined, doctor_ids: [] });
function openNew() {
  Object.assign(nf, { patient_id: undefined, diagnosis: '', plan: '', lead_doctor_id: undefined, doctor_ids: [] });
  newOpen.value = true;
}
async function createTreatment() {
  if (!nf.patient_id) return message.warning(s('Pick the patient.'));
  saving.value = true;
  try {
    const d = await http.post('hospital/treatments', { ...nf });
    newOpen.value = false;
    load();
    openDetail(d.id);
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

const detailOpen = ref(false);
const detailLoading = ref(false);
const dtab = ref('sheet');
const t = ref(null);
const sheetFrom = ref(dayjs().subtract(12, 'hour'));
const ef = reactive({ diagnosis: '', plan: '', lead_doctor_id: undefined, doctor_ids: [] });
const orderOf = id => (t.value?.orders || []).find(o => o.id === id) || {};
function emptyOrder() {
  return { product_id: undefined, medicine: '', dose: '', route: 'oral', every_hours: 8, days: 3, starts_at: dayjs().minute(0).add(1, 'hour').format('YYYY-MM-DD HH:mm:ss'), units_per_dose: 1, instructions: '' };
}
const of = reactive(emptyOrder());

async function openDetail(id, keepTab = false) {
  detailOpen.value = true;
  detailLoading.value = true;
  if (!keepTab) dtab.value = 'sheet';
  try {
    const d = await http.get(`hospital/treatments/${id}`, { from: dayjs(sheetFrom.value).format('YYYY-MM-DD HH:mm:ss') });
    t.value = d.treatment;
    Object.assign(ef, { diagnosis: t.value.diagnosis || '', plan: t.value.plan || '', lead_doctor_id: t.value.lead_doctor_id || undefined,
      doctor_ids: t.value.doctors.filter(x => x.role !== 'lead').map(x => x.id) });
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not load'));
  } finally {
    detailLoading.value = false;
  }
}
function moveSheet(h) { sheetFrom.value = dayjs(sheetFrom.value).add(h, 'hour'); openDetail(t.value.id, true); }
function sheetNow() { sheetFrom.value = dayjs().subtract(12, 'hour'); openDetail(t.value.id, true); }

async function record(d, st) {
  try {
    await http.post(`hospital/doses/${d.id}`, { status: st });
    openDetail(t.value.id, true);
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  }
}
async function addOrder() {
  if (!of.product_id && !of.medicine.trim()) return message.warning(s('Pick or type the medicine.'));
  if (!of.dose.trim()) return message.warning(s('Type the dose.'));
  saving.value = true;
  try {
    await http.post(`hospital/treatments/${t.value.id}/orders`, { ...of });
    Object.assign(of, emptyOrder());
    message.success(s('Order added: its doses are on the medication sheet.'));
    openDetail(t.value.id, true);
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}
async function stopOrder(o) {
  try {
    await http.post(`hospital/medication-orders/${o.id}/stop`, {});
    openDetail(t.value.id, true);
  } catch (e) {
    message.error(s('Could not save'));
  }
}
async function saveInfo(newStatus) {
  saving.value = true;
  try {
    await http.put(`hospital/treatments/${t.value.id}`, { ...ef, status: newStatus || undefined });
    message.success(s('Saved'));
    openDetail(t.value.id, true);
    load();
  } catch (e) {
    message.error(s(e?.data?.message || 'Could not save'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  load();
  try { meta.value = await http.get('hospital/treatments/meta'); } catch (e) { /* selects stay empty */ }
  if (route.query.id) openDetail(Number(route.query.id));
});
</script>

<style scoped>
.toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.tb-search { flex: 1 1 260px; min-width: 180px; }
.muted { opacity: 0.6; font-size: 12px; }
.head { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
.allergy { color: #dc2626; margin-bottom: 8px; }
.sheet-nav { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
.dose { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 6px 8px; border-bottom: 1px dashed rgba(128, 128, 128, 0.2); }
.dose.late .d-time { color: #dc2626; font-weight: 700; }
.dose.given { opacity: 0.75; }
.d-time { width: 90px; font-family: ui-monospace, Menlo, Consolas, monospace; }
.d-med { flex: 1; min-width: 180px; }
.d-title { font-weight: 600; margin-bottom: 4px; }
</style>
