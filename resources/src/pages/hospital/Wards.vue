<template>
  <div class="page">
    <PageHeader title="Wards & Beds" subtitle="Live bed board." :breadcrumb="['Hospital', 'Wards']">
      <template #actions>
        <a-button @click="openWard(null)">
          <template #icon><PlusOutlined /></template>
          New ward
        </a-button>
        <a-button type="primary" :disabled="!wards.length" @click="openBed(null)">
          <template #icon><PlusOutlined /></template>
          Add beds
        </a-button>
      </template>
    </PageHeader>

    <div class="summary">
      <div class="sum">
        <span class="sum-label">Beds</span>
        <span class="sum-value">{{ totals.total }}</span>
      </div>
      <div class="sum">
        <span class="sum-label">Occupied</span>
        <span class="sum-value danger">{{ totals.occupied }}</span>
      </div>
      <div class="sum">
        <span class="sum-label">Available</span>
        <span class="sum-value ok">{{ totals.available }}</span>
      </div>
      <div class="sum">
        <span class="sum-label">Occupancy</span>
        <span class="sum-value">{{ totals.rate }}%</span>
      </div>
    </div>

    <a-spin :spinning="loading">
      <a-card v-for="ward in wards" :key="ward.id" size="small" class="ward-card">
        <template #title>
          <span class="ward-title">{{ ward.name }}</span>
          <a-tag>{{ labelOf(WARD_TYPES, ward.type) }}</a-tag>
          <span v-if="ward.floor" class="ward-floor">{{ ward.floor }}</span>
          <span v-if="ward.department_name" class="ward-dept">· {{ ward.department_name }}</span>
        </template>
        <template #extra>
          <a-space>
            <span class="ward-rate">{{ money(ward.daily_rate) }}/night</span>
            <a-tag :color="wardRate(ward) >= 90 ? 'error' : wardRate(ward) >= 70 ? 'warning' : 'success'">
              {{ occupiedIn(ward) }}/{{ ward.beds.length }}
            </a-tag>
            <a-button type="text" size="small" @click="openWard(ward)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button type="text" size="small" danger @click="removeWard(ward)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>

        <div v-if="ward.beds.length" class="beds">
          <button
            v-for="bed in ward.beds" :key="bed.id" type="button"
            class="bed" :class="bed.status" @click="onBedClick(bed, ward)"
          >
            <span class="bed-no">{{ bed.bed_number }}</span>
            <span v-if="bed.patient_name" class="bed-patient">{{ bed.patient_name }}</span>
            <span v-else class="bed-state">{{ labelOf(BED_STATUSES, bed.status) }}</span>
          </button>
        </div>
        <a-empty v-else :image="simpleEmptyImage" description="No beds in this ward">
          <a-button size="small" @click="openBed(ward)">Add beds</a-button>
        </a-empty>
      </a-card>

      <a-card v-if="!loading && !wards.length">
        <a-empty description="No wards configured yet">
          <a-button type="primary" @click="openWard(null)">Create the first ward</a-button>
        </a-empty>
      </a-card>
    </a-spin>

    <!-- Ward form -->
    <a-modal
      :open="wardOpen" :title="editingWard ? 'Edit ward' : 'New ward'" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitWard" @cancel="wardOpen = false"
    >
      <a-form ref="wardRef" :model="wardForm" :rules="wardRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="14">
            <a-form-item label="Ward name *" name="name">
              <a-input v-model:value="wardForm.name" placeholder="e.g. Male Medical" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="10">
            <a-form-item label="Type *" name="type">
              <a-select v-model:value="wardForm.type" :options="WARD_TYPES" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Floor">
              <a-input v-model:value="wardForm.floor" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item label="Rate / night">
              <a-input-number v-model:value="wardForm.daily_rate" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item label="Department">
              <a-select
                v-model:value="wardForm.department_id" allow-clear show-search
                option-filter-prop="label" :options="departmentOptions" placeholder="None"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')" style="margin-bottom: 0">
              <a-textarea v-model:value="wardForm.notes" :rows="2" allow-clear />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Bed form -->
    <a-modal
      :open="bedOpen" :title="editingBed ? 'Edit bed' : 'Add beds'" :width="520"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitBed" @cancel="bedOpen = false"
    >
      <a-form ref="bedRef" :model="bedForm" :rules="bedRules" layout="vertical">
        <a-form-item label="Ward *" name="ward_id">
          <a-select
            v-model:value="bedForm.ward_id" :options="wardOptions" :disabled="!!editingBed"
            show-search option-filter-prop="label"
          />
        </a-form-item>
        <a-form-item
          label="Bed number *" name="bed_number"
          :extra="editingBed ? null : 'One number, a range like 1-12, or a comma list like A1,A2'"
        >
          <a-input v-model:value="bedForm.bed_number" placeholder="e.g. 1-12" allow-clear />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item label="Status">
              <a-select v-model:value="bedForm.status" :options="assignableBedStatuses" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item label="Rate / night" extra="Leave empty to use the ward rate">
              <a-input-number v-model:value="bedForm.daily_rate" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="bedForm.notes" :rows="2" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Bed board: every ward with its beds, colour-coded by state, and who is in
 * each occupied one.
 *
 * Clicking an occupied bed jumps to that patient; clicking a free one edits it.
 * Beds are never made "occupied" from here — that only happens by admitting a
 * patient, and the backend refuses anything else, so the board cannot lie.
 */
import { ref, reactive, computed, onMounted, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { WARD_TYPES, BED_STATUSES, labelOf } from './hospitalOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const router = useRouter();
const { money } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const wards = ref([]);
const departments = ref([]);
const loading = ref(false);
const saving = ref(false);

const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.name })));
const wardOptions = computed(() => wards.value.map(w => ({ value: w.id, label: w.name })));

// 'occupied' is deliberately not offered — see the component doc.
const assignableBedStatuses = BED_STATUSES.filter(s => s.value !== 'occupied');

const totals = computed(() => {
  let total = 0;
  let occupied = 0;
  wards.value.forEach(w => {
    total += w.beds.length;
    occupied += w.beds.filter(b => b.status === 'occupied').length;
  });
  return {
    total,
    occupied,
    available: wards.value.reduce((sum, w) => sum + w.beds.filter(b => b.status === 'available').length, 0),
    rate: total ? Math.round((occupied / total) * 100) : 0,
  };
});

function occupiedIn(ward) {
  return ward.beds.filter(b => b.status === 'occupied').length;
}
function wardRate(ward) {
  return ward.beds.length ? Math.round((occupiedIn(ward) / ward.beds.length) * 100) : 0;
}

async function load() {
  loading.value = true;
  try {
    const [board, meta] = await Promise.all([
      http.get('hospital/beds'),
      http.get('hospital/meta').catch(() => null),
    ]);
    wards.value = board?.wards || [];
    departments.value = meta?.departments || [];
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the bed board'));
  } finally {
    loading.value = false;
  }
}

// ---------------- ward form ----------------

const wardRef = ref();
const wardOpen = ref(false);
const editingWard = ref(null);
const wardForm = reactive({ name: '', type: 'general', floor: '', daily_rate: null, department_id: undefined, notes: '' });

const wardRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required', 'This field is required') }],
  type: [{ required: true, message: t('Field_is_required', 'This field is required') }],
}));

function openWard(ward) {
  editingWard.value = ward;
  Object.assign(wardForm, ward
    ? {
        name: ward.name, type: ward.type, floor: ward.floor || '',
        daily_rate: ward.daily_rate, department_id: ward.department_id || undefined,
        notes: ward.notes || '',
      }
    : { name: '', type: 'general', floor: '', daily_rate: null, department_id: undefined, notes: '' });
  wardOpen.value = true;
  wardRef.value?.clearValidate?.();
}

async function submitWard() {
  try {
    await wardRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editingWard.value) await http.put(`hospital/wards/${editingWard.value.id}`, { ...wardForm });
    else await http.post('hospital/wards', { ...wardForm });
    message.success(t('Created_in_successfully', 'Saved successfully'));
    wardOpen.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this ward'));
  } finally {
    saving.value = false;
  }
}

function removeWard(ward) {
  Modal.confirm({
    title: `Delete ${ward.name}?`,
    icon: createVNode(ExclamationCircleOutlined),
    content: 'Its beds are deleted with it. Occupied wards cannot be deleted.',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`hospital/wards/${ward.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        load();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData', 'Could not delete this ward'));
      }
    },
  });
}

// ---------------- bed form ----------------

const bedRef = ref();
const bedOpen = ref(false);
const editingBed = ref(null);
const bedForm = reactive({ ward_id: undefined, bed_number: '', status: 'available', daily_rate: null, notes: '' });

const bedRules = computed(() => ({
  ward_id: [{ required: true, message: t('Field_is_required', 'This field is required') }],
  bed_number: [{ required: true, message: t('Field_is_required', 'This field is required') }],
}));

function openBed(ward, bed = null) {
  editingBed.value = bed;
  Object.assign(bedForm, bed
    ? {
        ward_id: ward.id, bed_number: bed.bed_number,
        status: bed.status === 'occupied' ? 'occupied' : bed.status,
        daily_rate: bed.daily_rate, notes: bed.notes || '',
      }
    : { ward_id: ward?.id || wards.value[0]?.id, bed_number: '', status: 'available', daily_rate: null, notes: '' });
  bedOpen.value = true;
  bedRef.value?.clearValidate?.();
}

function onBedClick(bed, ward) {
  // An occupied bed is about the patient in it, not about the bed.
  if (bed.status === 'occupied' && bed.patient_id) {
    router.push(`/hospital/patients/${bed.patient_id}`);
    return;
  }
  openBed(ward, bed);
}

async function submitBed() {
  try {
    await bedRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editingBed.value) await http.put(`hospital/beds/${editingBed.value.id}`, { ...bedForm });
    else await http.post('hospital/beds', { ...bedForm });
    message.success(t('Created_in_successfully', 'Saved successfully'));
    bedOpen.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this bed'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.danger {
  color: #ff4d4f;
}
.ok {
  color: #16a34a;
}
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.sum {
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
}
.sum-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.sum-value {
  font-size: 19px;
  font-weight: 600;
}
.ward-card {
  margin-bottom: 16px;
}
.ward-title {
  font-weight: 600;
  margin-inline-end: 8px;
}
.ward-floor,
.ward-dept {
  font-size: 12px;
  font-weight: 400;
  opacity: 0.55;
  margin-inline-start: 6px;
}
.ward-rate {
  font-size: 12px;
  opacity: 0.6;
}
.beds {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 10px;
}
.bed {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px 12px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 12px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
  transition: transform 0.12s ease, border-color 0.15s ease;
}
.bed:hover {
  transform: translateY(-2px);
}
.bed.available {
  border-color: rgba(22, 163, 74, 0.45);
  background: rgba(22, 163, 74, 0.07);
}
.bed.occupied {
  border-color: rgba(255, 77, 79, 0.5);
  background: rgba(255, 77, 79, 0.08);
}
.bed.reserved {
  border-color: rgba(217, 119, 6, 0.5);
  background: rgba(217, 119, 6, 0.08);
}
.bed.maintenance {
  border-style: dashed;
  opacity: 0.65;
}
.bed-no {
  font-weight: 600;
  font-size: 14px;
}
.bed-patient {
  font-size: 11.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.bed-state {
  font-size: 11.5px;
  opacity: 0.6;
}
</style>
