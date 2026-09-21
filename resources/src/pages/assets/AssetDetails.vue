<template>
  <div class="page">
    <PageHeader
      :title="asset.name || 'Asset'"
      :subtitle="asset.tag ? `${asset.tag}${asset.serial_number ? ' · S/N ' + asset.serial_number : ''}` : ''"
      :breadcrumb="['Asset Management', 'Assets', asset.tag || '']"
    >
      <template #actions>
        <a-button @click="$router.push('/assets')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button @click="$router.push(`/assets/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
        <a-button v-if="!asset.disposal_date" danger @click="disposeOpen = true">
          <template #icon><ExportOutlined /></template>
          Dispose
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert
        v-if="asset.disposal_date" type="warning" show-icon banner style="margin-bottom: 16px"
        :message="`Disposed on ${asset.disposal_date} for ${money(asset.disposal_amount || 0)}`"
        :description="disposalSummary"
      />

      <!-- headline figures -->
      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">Status</span>
          <span class="kpi-value">
            <a-tag :color="optionOf(ASSET_STATUSES, asset.status).color">
              {{ labelOf(ASSET_STATUSES, asset.status) }}
            </a-tag>
          </span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Purchase cost</span>
          <span class="kpi-value">{{ money(asset.purchase_cost || 0) }}</span>
          <span class="kpi-sub">{{ asset.purchase_date ? date(asset.purchase_date) : 'No date' }}</span>
        </div>
        <div class="kpi kpi--accent">
          <span class="kpi-label">Book value today</span>
          <span class="kpi-value">{{ money(asset.book_value || 0) }}</span>
          <span class="kpi-sub">−{{ money(asset.accumulated_depreciation || 0) }} depreciated</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Cost of ownership</span>
          <span class="kpi-value">{{ money(asset.total_cost_of_ownership || 0) }}</span>
          <span class="kpi-sub">incl. {{ money(asset.maintenance_cost || 0) }} upkeep</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Held by</span>
          <span class="kpi-value kpi-value--sm">{{ asset.holder_name || 'On the shelf' }}</span>
          <span class="kpi-sub">{{ asset.warehouse_name || 'No warehouse' }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Next validation</span>
          <span class="kpi-value kpi-value--sm">
            <a-tag v-if="asset.next_validation" :color="dueColor(asset.days_to_validation)">
              {{ dueLabel(asset.days_to_validation) }}
            </a-tag>
            <span v-else class="muted">Not set</span>
          </span>
          <span class="kpi-sub">{{ asset.next_validation ? date(asset.next_validation) : '—' }}</span>
        </div>
      </div>

      <a-card size="small">
        <a-tabs v-model:activeKey="tab">
          <!-- ---------------------------------------------- overview -->
          <a-tab-pane key="overview" tab="Overview">
            <a-descriptions bordered size="small" :column="{ xs: 1, sm: 2, xl: 3 }">
              <a-descriptions-item label="Tag">{{ asset.tag || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Serial number">{{ asset.serial_number || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Category')">{{ asset.category_name || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Warehouse')">{{ asset.warehouse_name || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Supplier">{{ asset.supplier || '—' }}</a-descriptions-item>
              <a-descriptions-item label="Warranty">
                <a-tag v-if="asset.warranty_expiry" :color="asset.under_warranty ? 'success' : 'default'">
                  {{ asset.under_warranty ? 'Until' : 'Expired' }} {{ date(asset.warranty_expiry) }}
                </a-tag>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
              <a-descriptions-item label="Last verified">
                {{ asset.last_verification ? date(asset.last_verification) : '—' }}
              </a-descriptions-item>
              <a-descriptions-item label="Depreciation">
                {{ labelOf(DEPRECIATION_METHODS, asset.depreciation_method || 'none') }}
              </a-descriptions-item>
              <a-descriptions-item label="Useful life">
                {{ asset.useful_life_months ? `${asset.useful_life_months} months` : '—' }}
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Description')" :span="3">
                {{ asset.description || '—' }}
              </a-descriptions-item>
            </a-descriptions>
          </a-tab-pane>

          <!-- ---------------------------------------------- custody -->
          <a-tab-pane key="assignments">
            <template #tab>
              <span>Custody <a-badge :count="assignments.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="$router.push(`/assets/assignments?asset_id=${id}`)">
                Manage assignments
              </a-button>
            </div>
            <a-table
              size="small" :columns="assignmentColumns" :data-source="assignments"
              row-key="id" :pagination="false" :scroll="{ x: 800 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'status'">
                  <a-tag :color="record.is_overdue ? 'error' : optionOf(ASSIGNMENT_STATUSES, record.status).color">
                    {{ record.is_overdue ? 'Overdue' : labelOf(ASSIGNMENT_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'days_held'">{{ record.days_held }}d</template>
                <template v-else-if="column.dataIndex && column.dataIndex.endsWith('_on')">
                  {{ record[column.dataIndex] ? date(record[column.dataIndex]) : '—' }}
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ---------------------------------------------- maintenance -->
          <a-tab-pane key="maintenance">
            <template #tab>
              <span>Maintenance <a-badge :count="maintenance.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="$router.push(`/assets/maintenance?asset_id=${id}`)">
                Manage jobs
              </a-button>
              <span class="tab-total">Spent to date: <strong>{{ money(asset.maintenance_cost || 0) }}</strong></span>
            </div>
            <a-table
              size="small" :columns="maintenanceColumns" :data-source="maintenance"
              row-key="id" :pagination="false" :scroll="{ x: 900 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'type'">
                  <a-tag :color="optionOf(MAINTENANCE_TYPES, record.type).color">
                    {{ labelOf(MAINTENANCE_TYPES, record.type) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'status'">
                  <a-tag :color="optionOf(MAINTENANCE_STATUSES, record.status).color">
                    {{ labelOf(MAINTENANCE_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
                <template v-else-if="column.key === 'scheduled_date'">
                  <a-tag :color="record.is_overdue ? 'error' : 'default'">{{ date(record.scheduled_date) }}</a-tag>
                </template>
                <template v-else-if="column.dataIndex && column.dataIndex.endsWith('_date')">
                  {{ record[column.dataIndex] ? date(record[column.dataIndex]) : '—' }}
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ---------------------------------------------- movement -->
          <a-tab-pane key="transfers">
            <template #tab>
              <span>Movement <a-badge :count="transfers.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="$router.push(`/assets/transfers?asset_id=${id}`)">
                Manage transfers
              </a-button>
            </div>
            <a-empty v-if="!transfers.length" :image="simpleImage" description="This asset has not moved" />
            <a-timeline v-else class="timeline">
              <a-timeline-item v-for="t in transfers" :key="t.id" color="purple">
                <div class="tl-head">
                  {{ t.from_warehouse_name || 'Unassigned' }}
                  <ArrowRightOutlined class="arrow" />
                  <strong>{{ t.to_warehouse_name }}</strong>
                </div>
                <div class="tl-sub">
                  {{ date(t.transfer_date) }}<span v-if="t.reason"> · {{ t.reason }}</span>
                </div>
                <div v-if="t.notes" class="tl-note">{{ t.notes }}</div>
              </a-timeline-item>
            </a-timeline>
          </a-tab-pane>

          <!-- ---------------------------------------------- depreciation -->
          <a-tab-pane key="depreciation" tab="Depreciation">
            <a-empty
              v-if="!schedule.length" :image="simpleImage"
              description="No depreciation method set — edit the asset to add a useful life"
            />
            <template v-else>
              <a-table
                size="small" :columns="scheduleColumns" :data-source="schedule"
                row-key="year" :pagination="false"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="['opening', 'depreciation', 'accumulated', 'closing'].includes(column.key)">
                    <span :class="column.key === 'depreciation' ? 'down' : ''">
                      {{ column.key === 'depreciation' ? '−' : '' }}{{ money(record[column.key]) }}
                    </span>
                  </template>
                </template>
              </a-table>
              <ReportChart
                :data="schedule"
                :fields="[{ key: 'closing', label: 'Book value', money: true }]"
                title="Book value over the asset's life"
                type="area"
                x-key="year"
                :height="240"
                :format="money"
                style="margin-top: 16px"
              />
            </template>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <!-- dispose -->
    <a-modal
      :open="disposeOpen" title="Dispose of this asset" :width="500"
      :confirm-loading="disposing" ok-text="Dispose" :cancel-text="$t('Cancel')"
      ok-type="danger" @ok="submitDispose" @cancel="disposeOpen = false"
    >
      <a-alert
        type="warning" show-icon banner style="margin-bottom: 14px"
        message="Disposal is permanent: depreciation stops on this date and the asset is retired."
      />
      <a-form layout="vertical">
        <a-form-item label="Disposal date *">
          <a-date-picker v-model:value="disposeForm.disposal_date" style="width: 100%" value-format="YYYY-MM-DD" />
        </a-form-item>
        <a-form-item label="Amount received" extra="Leave at 0 if it was scrapped">
          <a-input-number v-model:value="disposeForm.disposal_amount" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-input v-model:value="disposeForm.disposal_note" placeholder="e.g. Sold to staff member" allow-clear />
        </a-form-item>
      </a-form>
      <p class="dispose-hint">
        Book value today is <strong>{{ money(asset.book_value || 0) }}</strong> —
        anything above that is booked as a gain.
      </p>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Everything about one asset on one page: what it is, what it is worth, who has
 * had it, what has been done to it and where it has been.
 *
 * The tabs each link out to the module page filtered to this asset rather than
 * duplicating the editing UI — one place to change a thing, several places to
 * see it.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, ExportOutlined, ArrowRightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import {
  ASSET_STATUSES, ASSIGNMENT_STATUSES, MAINTENANCE_TYPES, MAINTENANCE_STATUSES,
  DEPRECIATION_METHODS, labelOf, optionOf, dueColor, dueLabel,
} from './assetOptions';
import http from '../../lib/http';

const route = useRoute();
const { money, date } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,0.25)', color: 'inherit', boxShadow: 'none' };

const id = route.params.id;
const loading = ref(false);
const tab = ref('overview');

const asset = ref({});
const schedule = ref([]);
const maintenance = ref([]);
const assignments = ref([]);
const transfers = ref([]);

const disposalSummary = computed(() => {
  const gain = asset.value.disposal_gain;
  if (gain === null || gain === undefined) return '';
  return gain >= 0
    ? `Sold ${money(gain)} above its book value.`
    : `Sold ${money(Math.abs(gain))} below its book value.`;
});

const assignmentColumns = [
  { title: 'Holder', dataIndex: 'user_name', key: 'user_name' },
  { title: 'Assigned', dataIndex: 'assigned_on', key: 'assigned_on', width: 120 },
  { title: 'Due back', dataIndex: 'due_back_on', key: 'due_back_on', width: 120 },
  { title: 'Returned', dataIndex: 'returned_on', key: 'returned_on', width: 120 },
  { title: 'Held', key: 'days_held', dataIndex: 'days_held', width: 80, align: 'right' },
  { title: 'Out', dataIndex: 'condition_out', key: 'condition_out' },
  { title: 'In', dataIndex: 'condition_in', key: 'condition_in' },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 110 },
];

const maintenanceColumns = [
  { title: 'Scheduled', key: 'scheduled_date', dataIndex: 'scheduled_date', width: 125 },
  { title: 'Work', dataIndex: 'title', key: 'title' },
  { title: 'Type', key: 'type', dataIndex: 'type', width: 115 },
  { title: 'Vendor', dataIndex: 'vendor', key: 'vendor', width: 130 },
  { title: 'Completed', key: 'completed_date', dataIndex: 'completed_date', width: 115 },
  { title: 'Next due', key: 'next_due_date', dataIndex: 'next_due_date', width: 115 },
  { title: 'Cost', key: 'cost', dataIndex: 'cost', width: 110, align: 'right' },
  { title: 'Status', key: 'status', dataIndex: 'status', width: 120 },
];

const scheduleColumns = [
  { title: 'Year', dataIndex: 'year', key: 'year', width: 70 },
  { title: 'Period', dataIndex: 'period', key: 'period' },
  { title: 'Opening', key: 'opening', dataIndex: 'opening', width: 130, align: 'right' },
  { title: 'Charge', key: 'depreciation', dataIndex: 'depreciation', width: 130, align: 'right' },
  { title: 'Accumulated', key: 'accumulated', dataIndex: 'accumulated', width: 140, align: 'right' },
  { title: 'Closing', key: 'closing', dataIndex: 'closing', width: 130, align: 'right' },
];

// ---------------- dispose ----------------

const disposeOpen = ref(false);
const disposing = ref(false);
const disposeForm = ref({
  disposal_date: new Date().toISOString().slice(0, 10),
  disposal_amount: 0,
  disposal_note: '',
});

async function submitDispose() {
  if (!disposeForm.value.disposal_date) {
    message.error('Pick a disposal date');
    return;
  }
  disposing.value = true;
  try {
    const res = await http.post(`assets/workspace/dispose/${id}`, disposeForm.value);
    message.success(
      res?.gain >= 0
        ? `Disposed — ${money(res.gain)} gain`
        : `Disposed — ${money(Math.abs(res?.gain || 0))} loss`,
    );
    disposeOpen.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || 'Could not dispose of this asset');
  } finally {
    disposing.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`assets/workspace/details/${id}`);
    asset.value = data?.asset || {};
    schedule.value = data?.schedule || [];
    maintenance.value = data?.maintenance || [];
    assignments.value = data?.assignments || [];
    transfers.value = data?.transfers || [];
  } catch (e) {
    message.error('Could not load this asset');
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.down {
  color: #dc2626;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 13px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.kpi--accent {
  border-color: rgba(109, 40, 217, 0.45);
  background: rgba(109, 40, 217, 0.05);
}
.kpi-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.kpi-value {
  font-size: 19px;
  font-weight: 600;
  line-height: 1.35;
}
.kpi-value--sm {
  font-size: 15px;
}
.kpi-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.tab-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
.tab-total {
  margin-inline-start: auto;
  font-size: 12.5px;
  opacity: 0.75;
}
.timeline {
  padding-top: 6px;
}
.tl-head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.arrow {
  font-size: 11px;
  opacity: 0.45;
}
.tl-sub {
  font-size: 12px;
  opacity: 0.6;
}
.tl-note {
  font-size: 12px;
  opacity: 0.75;
  margin-top: 2px;
}
.dispose-hint {
  margin: 14px 0 0;
  font-size: 12.5px;
  opacity: 0.7;
}
</style>
