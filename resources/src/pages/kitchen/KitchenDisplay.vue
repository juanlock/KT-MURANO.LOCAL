<template>
  <div ref="pageEl" class="page kitchen" :class="{ 'kitchen-mode': kitchenMode }" :style="kitchenModeStyle">
    <PageHeader :title="$t('KitchenDisplay')" :breadcrumb="[$t('Kitchen'), $t('KitchenDisplay')]">
      <template #actions>
        <a-tag :color="autoRefresh ? 'success' : 'default'">
          <span class="live-dot" :class="{ on: autoRefresh }"></span>
          {{ autoRefresh ? $t('Live') : $t('Paused') }}
        </a-tag>
        <a-button @click="toggleAutoRefresh">
          <template #icon>
            <PauseOutlined v-if="autoRefresh" />
            <CaretRightOutlined v-else />
          </template>
        </a-button>
        <a-button :loading="refreshing" @click="fetchBoard(true)">
          <template #icon><ReloadOutlined /></template>
          {{ $t('Refresh') }}
        </a-button>
        <a-select
          v-if="stations.length"
          v-model:value="selectedStation"
          style="min-width: 170px"
          :options="stationOptions"
          @change="onStationChange"
        />
        <a-tooltip v-if="canManage" :title="$t('KitchenStations')">
          <a-button @click="openStations">
            <template #icon><SettingOutlined /></template>
          </a-button>
        </a-tooltip>
        <a-tooltip :title="$t('OrderReadyScreen')">
          <a-button :loading="readyScreenBusy" @click="openReadyScreen">
            <template #icon><DesktopOutlined /></template>
          </a-button>
        </a-tooltip>
        <a-button @click="toggleKitchenMode">
          <template #icon>
            <FullscreenExitOutlined v-if="kitchenMode" />
            <FullscreenOutlined v-else />
          </template>
          {{ kitchenMode ? $t('ExitKitchenMode') : $t('KitchenMode') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Counts — same stat-card design as the Sales list -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in statTiles" :key="tile.label" :xs="12" :sm="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: tile.tint, color: tile.color }">
              <component :is="tile.icon" />
            </div>
            <div class="stat-meta">
              <div class="stat-label">{{ tile.label }}</div>
              <div class="stat-value">
                <a-spin v-if="loading && !firstLoadDone" size="small" />
                <template v-else>{{ tile.value }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <!-- Filters -->
    <a-card size="small" class="filters-card" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <a-input-search
          v-model:value="search" :placeholder="$t('Search')"
          allow-clear style="width: 260px" @search="reload"
        />
        <a-range-picker v-model:value="range" value-format="YYYY-MM-DD" @change="reload" />
        <a-button @click="clearFilters">
          <template #icon><CloseOutlined /></template>
          {{ $t('Clear') }}
        </a-button>
      </a-space>
    </a-card>

    <!-- Board -->
    <a-row :gutter="[16, 16]">
      <a-col v-for="col in COLUMNS" :key="col.key" :xs="24" :md="12" :xl="6">
        <div class="lane">
          <div class="lane-head" :style="{ background: col.color }">
            <span>{{ $t(col.label) }}</span>
            <span class="lane-count">{{ (displayGrouped[col.key] || []).length }}</span>
          </div>

          <div class="lane-body">
            <a-skeleton v-if="loading && !firstLoadDone" active :paragraph="{ rows: 3 }" />

            <a-empty
              v-else-if="!(displayGrouped[col.key] || []).length"
              :description="$t('NoOrders')" style="padding: 24px 0"
            />

            <a-card
              v-for="order in displayGrouped[col.key] || []" :key="order.id"
              size="small" class="ticket"
              :class="{ 'ticket-late': urgency(order, col.key) === 'late' }"
              :style="{ borderLeftColor: ticketColor(order, col) }"
            >
              <a-spin :spinning="busyId === order.id">
                <div class="ticket-top">
                  <span v-if="order.token_number" class="token">#{{ order.token_number }}</span>
                  <strong class="ticket-ref">{{ order.ref || `#${order.sale_id}` }}</strong>
                  <a-tooltip :title="order.created_at">
                    <span class="muted elapsed" :class="urgency(order, col.key)">
                      <ClockCircleOutlined /> {{ elapsed(order) }}
                    </span>
                  </a-tooltip>
                </div>

                <div class="muted">
                  <UserOutlined /> {{ order.customer_name || $t('Walkin_Customer') }}
                </div>

                <div
                  v-if="order.voided || order.source === 'online' || urgency(order, col.key) === 'late'
                    || (col.key !== 'completed' && doneCount(order) > 0)"
                  class="ticket-badges"
                >
                  <a-tag v-if="order.voided" color="error">{{ $t('Voided') }}</a-tag>
                  <a-tag v-if="order.source === 'online'" color="geekblue">{{ $t('OnlineOrder') }}</a-tag>
                  <a-tag v-if="!order.voided && urgency(order, col.key) === 'late'" color="error">{{ $t('Overdue') }}</a-tag>
                  <a-tag v-if="col.key !== 'completed' && doneCount(order) > 0" color="processing">
                    {{ doneCount(order) }}/{{ visibleItems(order).length }}
                  </a-tag>
                </div>

                <ul class="items">
                  <li v-for="item in visibleItems(order)" :key="item.id" :class="{ done: item.done }">
                    <a-checkbox
                      v-if="canManage && col.key !== 'completed'"
                      :checked="!!item.done"
                      :disabled="busyId === order.id"
                      @change="e => toggleItem(order, item, e.target.checked)"
                    />
                    <span class="qty">{{ formatQty(item.quantity) }}<small v-if="item.unit"> {{ item.unit }}</small></span>
                    <span class="item-name">{{ item.name }}</span>
                  </li>
                </ul>

                <a-alert
                  v-if="order.instructions" type="warning" :message="order.instructions"
                  style="margin-bottom: 8px"
                />

                <a-tag v-if="order.assigned_name" color="purple">
                  {{ order.assigned_name }}
                </a-tag>

                <!-- Dispatch is only offered once the ticket is complete. -->
                <template v-if="col.key === 'completed'">
                  <a-tag v-if="order.dispatched_warehouse_id" color="success">
                    {{ $t('SentToWarehouse') }} {{ order.dispatched_warehouse_name }}
                  </a-tag>
                  <a-select
                    v-if="canManage"
                    :value="order.dispatched_warehouse_id || undefined"
                    :placeholder="order.dispatched_warehouse_id ? $t('ChangeWarehouse') : $t('SendToWarehouse')"
                    :disabled="busyId === order.id"
                    size="small" style="width: 100%; margin-top: 6px"
                    :options="warehouses.map(w => ({ value: w.id, label: w.name }))"
                    @change="v => dispatch(order, v)"
                  />
                </template>

                <div class="ticket-actions">
                  <template v-if="canManage">
                    <a-button
                      v-if="col.key === 'pending' || col.key === 'on_hold'"
                      type="primary" size="small" :disabled="busyId === order.id"
                      @click="setStatus(order, 'preparing')"
                    >{{ $t('StartPreparation') }}</a-button>

                    <a-button
                      v-if="col.key === 'preparing'"
                      type="primary" size="small" :disabled="busyId === order.id"
                      style="background: #22c55e; border-color: #22c55e"
                      @click="setStatus(order, 'completed')"
                    >{{ $t('MarkCompleted') }}</a-button>

                    <a-button
                      v-if="col.key === 'pending' || col.key === 'preparing'"
                      size="small" :disabled="busyId === order.id"
                      @click="setStatus(order, 'on_hold')"
                    >{{ $t('PutOnHold') }}</a-button>

                    <a-button
                      v-if="col.key === 'completed'"
                      size="small" :disabled="busyId === order.id"
                      @click="setStatus(order, 'preparing')"
                    >{{ $t('Reopen') }}</a-button>
                  </template>

                  <a-tooltip :title="$t('print')">
                    <a-button size="small" type="text" style="margin-left: auto" @click="printTicket(order)">
                      <template #icon><PrinterOutlined /></template>
                    </a-button>
                  </a-tooltip>
                  <a-tooltip :title="$t('Details')">
                    <a-button size="small" type="text" @click="openDetails(order)">
                      <template #icon><EyeOutlined /></template>
                    </a-button>
                  </a-tooltip>
                </div>
              </a-spin>
            </a-card>
          </div>
        </div>
      </a-col>
    </a-row>

    <!-- Details -->
    <a-modal v-model:open="detailsOpen" :title="$t('OrderDetails')" :footer="null" width="720px">
      <template v-if="selected">
        <a-descriptions :column="{ xs: 1, md: 2 }" size="small" bordered>
          <a-descriptions-item :label="$t('Order')">
            {{ selected.ref || `#${selected.sale_id}` }}
            <a-tag v-if="selected.token_number" style="margin-left: 6px">#{{ selected.token_number }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Status')">
            <a-tag :color="statusColor(selected.status)">{{ statusLabel(selected.status) }}</a-tag>
            <a-tag v-if="selected.voided" color="error">{{ $t('Voided') }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Source')">
            <a-tag v-if="selected.source === 'online'" color="geekblue">{{ $t('OnlineOrder') }}</a-tag>
            <template v-else>{{ selected.source === 'manual' ? $t('SendLater') : 'POS' }}</template>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Customer')">
            {{ selected.customer_name || $t('Walkin_Customer') }}
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Date')">{{ selected.created_at }}</a-descriptions-item>
          <a-descriptions-item v-if="selected.dispatched_warehouse_id" :label="$t('SentToWarehouse')">
            {{ selected.dispatched_warehouse_name }}
          </a-descriptions-item>
        </a-descriptions>

        <a-table
          :columns="itemColumns" :data-source="selected.items || []"
          :pagination="false" size="small" :row-key="r => r.id" style="margin-top: 16px"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'quantity'">
              {{ formatQty(record.quantity) }}<span v-if="record.unit"> {{ record.unit }}</span>
            </template>
            <template v-else-if="column.key === 'done'">
              <CheckCircleOutlined v-if="record.done" style="color: #22c55e" />
            </template>
          </template>
        </a-table>

        <template v-if="selected.instructions">
          <h4 style="margin-top: 16px">{{ $t('SpecialInstructions') }}</h4>
          <p>{{ selected.instructions }}</p>
        </template>

        <template v-if="canManage">
          <h4 style="margin-top: 16px">{{ $t('AssignedStaff') }}</h4>
          <a-space>
            <a-select
              v-model:value="assignChoice" style="width: 260px"
              :options="staffOptions" allow-clear :placeholder="$t('Unassigned')"
            />
            <a-button type="primary" :loading="assignBusy" @click="saveAssignment">{{ $t('Save') }}</a-button>
          </a-space>
        </template>
      </template>
    </a-modal>

    <!-- Stations editor -->
    <a-modal
      v-model:open="stationsOpen" :title="$t('KitchenStations')"
      :confirm-loading="stationsBusy" width="640px" @ok="saveStations"
    >
      <p class="muted" style="margin-bottom: 12px">{{ $t('StationCategoriesHint') }}</p>
      <div v-for="(st, idx) in stationsDraft" :key="st.id || `new-${idx}`" class="station-row">
        <a-input v-model:value="st.name" :placeholder="$t('StationName')" style="width: 170px" />
        <a-select
          v-model:value="st.category_ids" mode="multiple" style="flex: 1"
          :options="categoryOptions" :placeholder="$t('Categories')"
          option-filter-prop="label" :max-tag-count="3"
        />
        <a-button danger type="text" @click="stationsDraft.splice(idx, 1)">
          <template #icon><DeleteOutlined /></template>
        </a-button>
      </div>
      <a-button type="dashed" block @click="stationsDraft.push({ id: '', name: '', category_ids: [] })">
        <template #icon><PlusOutlined /></template>
        {{ $t('AddStation') }}
      </a-button>
    </a-modal>

    <!-- Order Ready screen link -->
    <a-modal v-model:open="readyScreenOpen" :title="$t('OrderReadyScreen')" :footer="null" width="560px">
      <p class="muted" style="margin-bottom: 12px">{{ $t('OrderReadyScreenHint') }}</p>
      <a-input-group compact style="display: flex">
        <a-input :value="readyScreenUrl" readonly style="flex: 1" />
        <a-button @click="copyReadyUrl">{{ $t('Copy') }}</a-button>
        <a-button type="primary" @click="openReadyUrl">{{ $t('Open') }}</a-button>
      </a-input-group>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Kitchen display board — legacy KitchenDisplay.vue. Four status lanes
 * (pending / preparing / completed / on hold) fed by GET kitchen/orders,
 * with a 4-second poll and a beep when new tickets land.
 *
 * Endpoints: GET kitchen/orders?q&from&to → {grouped, warehouses};
 * GET kitchen/orders/poll → {latest_id}; PATCH kitchen/orders/{id}/status
 * {status}; .../assign {assigned_to}; .../dispatch {dispatched_warehouse_id};
 * GET users_list_for_select; GET kitchen/warehouses.
 *
 * Things legacy got wrong that are fixed here:
 * - polling kept running in a hidden tab; it now pauses on visibilitychange
 *   and refreshes on return, which matters for a board left open all day.
 * - a single AudioContext was constructed per beep and never closed, leaking
 *   one context every time a ticket arrived; one lazily-created context now
 *   serves them all.
 * - the details modal held a reference into the replaced-every-4s board array,
 *   so it froze at open-time state; `selected` is re-resolved by id after each
 *   fetch.
 * Kept as-is: the new-order count is an id delta, so it can overcount when
 * unrelated rows consume ids — matching legacy rather than inventing a count.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount, h } from 'vue';
import { message, notification, Button } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PauseOutlined, CaretRightOutlined, ReloadOutlined, CloseOutlined,
  ClockCircleOutlined, UserOutlined, EyeOutlined, FireOutlined, CheckCircleOutlined,
  FullscreenOutlined, FullscreenExitOutlined, PrinterOutlined,
  SettingOutlined, DeleteOutlined, PlusOutlined, DesktopOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const COLUMNS = [
  { key: 'pending', label: 'PendingOrders', color: '#64748b' },
  { key: 'preparing', label: 'PreparingOrders', color: '#3b82f6' },
  { key: 'completed', label: 'CompletedOrders', color: '#22c55e' },
  { key: 'on_hold', label: 'OnHoldOrders', color: '#f59e0b' },
];

// Summary tiles mirror the Sales list's stat cards: lane color + soft tint + icon.
const TILE_META = {
  pending: { icon: ClockCircleOutlined, tint: 'rgba(100, 116, 139, 0.14)' },
  preparing: { icon: FireOutlined, tint: 'rgba(59, 130, 246, 0.12)' },
  completed: { icon: CheckCircleOutlined, tint: 'rgba(34, 197, 94, 0.12)' },
  on_hold: { icon: PauseOutlined, tint: 'rgba(245, 158, 11, 0.14)' },
};

const statTiles = computed(() => COLUMNS.map(c => ({
  label: t(c.label),
  value: (displayGrouped.value[c.key] || []).length,
  color: c.color,
  icon: TILE_META[c.key].icon,
  tint: TILE_META[c.key].tint,
})));

const loading = ref(true);
const refreshing = ref(false);
const firstLoadDone = ref(false);
const grouped = ref({});
const warehouses = ref([]);
const staff = ref([]);
const busyId = ref(null);

const search = ref('');
const range = ref([]);
const autoRefresh = ref(true);

// Prep-time target (minutes) from settings; null disables the overdue escalation.
const targetMinutes = ref(null);

// ---------------- stations (per-device filter, like POS keyboard shortcuts) ----------------

const STATION_KEY = 'kitchen_display_station';

const stations = ref([]);
const categories = ref([]);
const selectedStation = ref((() => {
  try { return localStorage.getItem(STATION_KEY) || ''; } catch (e) { return ''; }
})());

const stationOptions = computed(() => [
  { value: '', label: t('AllStations') },
  ...stations.value.map(s => ({ value: s.id, label: s.name })),
]);

const activeStation = computed(() =>
  stations.value.find(s => s.id === selectedStation.value) || null);

/** Categories claimed by ANY station: items outside these show on every station
 *  so an unassigned category can never silently drop off all screens. */
const coveredCategoryIds = computed(() => {
  const set = new Set();
  for (const s of stations.value) for (const id of s.category_ids || []) set.add(Number(id));
  return set;
});

const activeCategoryIds = computed(() => {
  const st = activeStation.value;
  return st ? new Set((st.category_ids || []).map(Number)) : null;
});

/** The ticket lines this display should show: everything on the expo (All)
 *  view; on a station, its categories plus any category no station claims. */
function visibleItems(order) {
  const items = order.items || [];
  const mine = activeCategoryIds.value;
  if (!mine) return items;
  return items.filter(i => {
    const cat = i.category_id == null ? null : Number(i.category_id);
    return (cat != null && mine.has(cat)) || cat == null || !coveredCategoryIds.value.has(cat);
  });
}

function onStationChange() {
  try { localStorage.setItem(STATION_KEY, selectedStation.value || ''); } catch (e) { /* per-device only */ }
}

// Stations editor (manage permission)
const stationsOpen = ref(false);
const stationsBusy = ref(false);
const stationsDraft = ref([]);

const categoryOptions = computed(() =>
  categories.value.map(c => ({ value: c.id, label: c.name })));

function openStations() {
  stationsDraft.value = stations.value.map(s => ({
    id: s.id, name: s.name, category_ids: (s.category_ids || []).slice(),
  }));
  stationsOpen.value = true;
}

async function saveStations() {
  const clean = stationsDraft.value
    .map(s => ({ ...s, name: (s.name || '').trim() }))
    .filter(s => s.name);
  stationsBusy.value = true;
  try {
    const data = await http.put('kitchen/stations', { stations: clean });
    stations.value = data?.stations || clean;
    // A station the display was pinned to may have been deleted.
    if (selectedStation.value && !stations.value.some(s => s.id === selectedStation.value)) {
      selectedStation.value = '';
      onStationChange();
    }
    stationsOpen.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    stationsBusy.value = false;
  }
}

// ---------------- kitchen mode (fullscreen board for a wall TV) ----------------

const pageEl = ref(null);
const kitchenMode = ref(false);

// Fullscreen elements get a transparent background over a black backdrop, so the
// board paints the app's layout color itself (matching light/dark theme).
const kitchenModeStyle = computed(() => (kitchenMode.value
  ? { background: ui.dark ? '#141414' : '#fafafa' }
  : null));

function toggleKitchenMode() {
  if (kitchenMode.value) {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    kitchenMode.value = false;
    return;
  }
  kitchenMode.value = true;
  const el = pageEl.value;
  if (el && el.requestFullscreen) el.requestFullscreen().catch(() => { /* class-only mode */ });
}

/** Sync when the user leaves fullscreen via Esc instead of the button. */
function onFullscreenChange() {
  if (!document.fullscreenElement) kitchenMode.value = false;
}

// ---------------- Order Ready screen (public token-guarded TV page) ----------------

const readyScreenOpen = ref(false);
const readyScreenBusy = ref(false);
const readyScreenUrl = ref('');

/** Each click mints a fresh 7-day token (invalidating the previous link, same
 *  as the POS customer display) and shows the URL to open on the TV. */
async function openReadyScreen() {
  readyScreenBusy.value = true;
  try {
    const data = await http.post('kitchen/ready-screen/generate');
    readyScreenUrl.value = data?.url || '';
    readyScreenOpen.value = true;
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    readyScreenBusy.value = false;
  }
}

function copyReadyUrl() {
  try {
    navigator.clipboard.writeText(readyScreenUrl.value);
    message.success(t('Copied'));
  } catch (e) { /* clipboard unavailable — the input is selectable */ }
}

function openReadyUrl() {
  window.open(readyScreenUrl.value, '_blank');
}

const canManage = computed(() => auth.can('kitchen_display_manage'));

const itemColumns = computed(() => [
  { title: t('Product'), dataIndex: 'name', key: 'name' },
  { title: t('Quantity'), key: 'quantity', align: 'right' },
  { title: '', key: 'done', width: 48, align: 'center' },
]);

const staffOptions = computed(() =>
  staff.value.map(u => ({
    value: u.id,
    label: [u.firstname, u.lastname].filter(Boolean).join(' ') || u.username || u.email,
  })));

function statusLabel(s) {
  const col = COLUMNS.find(c => c.key === s);
  return col ? t(col.label) : s;
}
function statusColor(s) {
  return { pending: 'default', preparing: 'processing', completed: 'success', on_hold: 'warning' }[s] || 'default';
}

/** Legacy formatQty: integers bare, otherwise 3dp with trailing zeros trimmed. */
function formatQty(n) {
  const num = Number(n || 0);
  return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
}

const nowTs = ref(Date.now());

/** Minutes since the ticket was sent (or created), or null when unknown. */
function elapsedMins(order) {
  const raw = order.sent_at || order.created_at;
  if (!raw) return null;
  const dt = new Date(String(raw).replace(' ', 'T'));
  if (Number.isNaN(dt.getTime())) return null;
  return Math.max(0, Math.floor((nowTs.value - dt.getTime()) / 60000));
}

/** Minutes since the ticket was sent (or created): "now", "45m", "2h 15m". */
function elapsed(order) {
  const mins = elapsedMins(order);
  if (mins == null) return '';
  if (mins < 1) return t('Now');
  if (mins < 60) return `${mins}m`;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return `${h}h${m ? ` ${m}m` : ''}`;
}

/**
 * Overdue escalation against the admin-set prep target: '' below 50% of the
 * target, 'warn' past 50%, 'late' past 100%. Completed tickets never escalate.
 */
function urgency(order, lane) {
  if (!targetMinutes.value || lane === 'completed') return '';
  const mins = elapsedMins(order);
  if (mins == null) return '';
  if (mins >= targetMinutes.value) return 'late';
  if (mins >= targetMinutes.value / 2) return 'warn';
  return '';
}

/** Lane accent for the ticket's left border, overridden by urgency. */
function ticketColor(order, col) {
  const u = urgency(order, col.key);
  if (u === 'late') return '#ef4444';
  if (u === 'warn') return '#f59e0b';
  return col.color;
}

/** Board lanes: station-filtered, with overdue tickets floated to the top
 *  (oldest first within each tier). */
const displayGrouped = computed(() => {
  const rank = { late: 0, warn: 1, '': 2 };
  const out = {};
  for (const col of COLUMNS) {
    let list = (grouped.value[col.key] || []).slice();
    if (activeStation.value) {
      list = list.filter(o => visibleItems(o).length > 0);
    }
    if (targetMinutes.value && col.key !== 'completed') {
      list.sort((a, b) => {
        const d = rank[urgency(a, col.key)] - rank[urgency(b, col.key)];
        return d !== 0 ? d : String(a.created_at || '').localeCompare(String(b.created_at || ''));
      });
    }
    out[col.key] = list;
  }
  return out;
});

// ---------------- data ----------------

function params() {
  return { q: search.value || '', from: range.value?.[0] || '', to: range.value?.[1] || '' };
}

async function fetchBoard(showSpinner = false) {
  if (showSpinner) refreshing.value = true;
  if (!firstLoadDone.value) loading.value = true;
  try {
    const data = await http.get('kitchen/orders', params());
    if (data?.grouped) grouped.value = data.grouped;
    if (Array.isArray(data?.warehouses) && data.warehouses.length) warehouses.value = data.warehouses;
    targetMinutes.value = data?.target_minutes ? Number(data.target_minutes) : null;
    if (Array.isArray(data?.stations)) stations.value = data.stations;
    // Keep the open modal live rather than frozen at open-time state.
    if (selected.value) {
      const all = Object.values(grouped.value).flat();
      const fresh = all.find(o => o.id === selected.value.id);
      if (fresh) selected.value = fresh;
    }
  } catch (e) { /* keep the last good board */ } finally {
    loading.value = false;
    refreshing.value = false;
    firstLoadDone.value = true;
  }
}

function reload() {
  fetchBoard(true);
}
function clearFilters() {
  search.value = '';
  range.value = [];
  fetchBoard(true);
}

// ---------------- actions ----------------

async function setStatus(order, status) {
  busyId.value = order.id;
  try {
    await http.patch(`kitchen/orders/${order.id}/status`, { status });
    await fetchBoard(false);
    message.success(t('Successfully_Updated'));
    if (status === 'completed') offerUndo(order);
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    busyId.value = null;
  }
}

/** 10-second escape hatch after a bump, so an accidental tap doesn't need hunting
 *  through the completed lane and "Reopen". */
function offerUndo(order) {
  const key = `kitchen-undo-${order.id}`;
  notification.open({
    key,
    message: t('MarkCompleted'),
    description: `${order.ref || `#${order.sale_id}`}${order.token_number ? ` — #${order.token_number}` : ''}`,
    duration: 10,
    btn: () => h(Button, {
      size: 'small',
      type: 'primary',
      onClick: () => {
        notification.close(key);
        setStatus(order, 'preparing');
      },
    }, { default: () => t('Undo') }),
  });
}

function doneCount(order) {
  return visibleItems(order).filter(i => i.done).length;
}

/** Per-item bump. The backend auto-starts the ticket on the first check and
 *  auto-completes it on the last; auto-complete gets the same Undo window. */
async function toggleItem(order, item, done) {
  busyId.value = order.id;
  try {
    const data = await http.patch(`kitchen/orders/${order.id}/item`, { item_id: item.id, done: !!done });
    await fetchBoard(false);
    if (data?.auto_completed) {
      message.success(t('Successfully_Updated'));
      offerUndo(order);
    }
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    busyId.value = null;
  }
}

/** Compact 72mm kitchen chit in a print popup — token big, items, instructions.
 *  Styles are inline in the popup document, so nothing needs copying in. */
function printTicket(order) {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Station displays print only their own lines, expo prints the full ticket.
  const rows = visibleItems(order).map(i =>
    `<tr><td class="q">${esc(formatQty(i.quantity))}${i.unit ? ` ${esc(i.unit)}` : ''}</td>`
    + `<td>${esc(i.name)}</td></tr>`).join('');
  const html = `<!doctype html><html><head><title>${esc(order.ref || `#${order.sale_id}`)}</title><style>
    body{font-family:'Segoe UI',Arial,sans-serif;width:72mm;margin:0;padding:4mm;color:#000}
    .tok{font-size:28px;font-weight:800;text-align:center;margin:0}
    .ref{text-align:center;font-size:12px;margin:2px 0 6px}
    .meta{font-size:11px;margin:1px 0}
    table{width:100%;border-collapse:collapse;margin-top:6px;font-size:13px}
    td{padding:3px 0;border-bottom:1px dashed #999;vertical-align:top}
    td.q{width:52px;font-weight:700}
    .notes{margin-top:6px;font-size:12px;border:1px solid #000;padding:4px}
    hr{border:none;border-top:1px solid #000;margin:6px 0}
  </style></head><body>
    ${order.token_number ? `<p class="tok">#${esc(order.token_number)}</p>` : ''}
    <p class="ref">${esc(order.ref || `#${order.sale_id}`)}</p>
    <p class="meta">${esc(order.created_at || '')}</p>
    <p class="meta">${esc(order.customer_name || t('Walkin_Customer'))}${order.source === 'online' ? ` — ${esc(t('OnlineOrder'))}` : ''}</p>
    ${activeStation.value ? `<p class="meta"><strong>${esc(activeStation.value.name)}</strong></p>` : ''}
    <hr>
    <table>${rows}</table>
    ${order.instructions ? `<div class="notes">${esc(order.instructions)}</div>` : ''}
  </body></html>`;
  const w = window.open('', '_blank', 'width=420,height=600');
  if (!w) return;
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => { w.print(); w.close(); }, 250);
}

async function dispatch(order, warehouseId) {
  if (!warehouseId) return;
  busyId.value = order.id;
  try {
    await http.patch(`kitchen/orders/${order.id}/dispatch`, { dispatched_warehouse_id: warehouseId });
    await fetchBoard(false);
    const wh = warehouses.value.find(w => String(w.id) === String(warehouseId));
    message.success(`${t('SentToWarehouse')} ${wh ? wh.name : ''}`);
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    busyId.value = null;
  }
}

// ---------------- details ----------------

const detailsOpen = ref(false);
const selected = ref(null);
const assignChoice = ref(null);
const assignBusy = ref(false);

function openDetails(order) {
  selected.value = order;
  assignChoice.value = order.assigned_to || null;
  detailsOpen.value = true;
}

async function saveAssignment() {
  assignBusy.value = true;
  try {
    const data = await http.patch(`kitchen/orders/${selected.value.id}/assign`, {
      assigned_to: assignChoice.value ?? null,
    });
    await fetchBoard(false);
    if (data?.order) selected.value = data.order;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Network_error'));
  } finally {
    assignBusy.value = false;
  }
}

// ---------------- polling ----------------

let pollTimer = null;
let clockTimer = null;
let lastLatestId = 0;
let audioCtx = null;

/** 880 Hz, 180 ms. One context reused — legacy leaked one per beep. */
function beep() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    if (!audioCtx) audioCtx = new Ctx();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.frequency.value = 880;
    gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
    osc.start();
    osc.stop(audioCtx.currentTime + 0.18);
  } catch (e) { /* autoplay policy or no WebAudio */ }
}

async function poll() {
  try {
    const data = await http.get('kitchen/orders/poll');
    const latest = Number(data?.latest_id || 0);
    await fetchBoard(false);
    // lastLatestId starts at 0, so the first tick only seeds — no false alarm.
    if (lastLatestId && latest > lastLatestId) {
      const count = latest - lastLatestId;
      notification.info({
        message: t('Kitchen'),
        description: `${t('NewKitchenOrders')}${count > 1 ? ` (${count})` : ''}`,
        duration: 4,
      });
      beep();
    }
    lastLatestId = latest || lastLatestId;
  } catch (e) { /* transient; next tick retries */ }
}

function startPolling() {
  stopPolling();
  if (autoRefresh.value) pollTimer = setInterval(poll, 4000);
}
function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}
function toggleAutoRefresh() {
  autoRefresh.value = !autoRefresh.value;
  startPolling();
}

/** A board left open all day should not poll while nobody is looking. */
function onVisibility() {
  if (document.hidden) {
    stopPolling();
  } else if (autoRefresh.value) {
    fetchBoard(false);
    startPolling();
  }
}

onMounted(async () => {
  fetchBoard(false);
  startPolling();
  clockTimer = setInterval(() => { nowTs.value = Date.now(); }, 30000);
  document.addEventListener('visibilitychange', onVisibility);
  document.addEventListener('fullscreenchange', onFullscreenChange);

  try {
    const data = await http.get('users_list_for_select');
    staff.value = data?.users || [];
  } catch (e) { /* assignment select just stays empty */ }
  try {
    const data = await http.get('kitchen/warehouses');
    warehouses.value = data?.warehouses || [];
  } catch (e) { /* dispatch select stays empty */ }
  try {
    const data = await http.get('kitchen/stations');
    if (Array.isArray(data?.stations)) stations.value = data.stations;
    categories.value = data?.categories || [];
  } catch (e) { /* stations UI just stays hidden */ }
});

onBeforeUnmount(() => {
  stopPolling();
  if (clockTimer) clearInterval(clockTimer);
  document.removeEventListener('visibilitychange', onVisibility);
  document.removeEventListener('fullscreenchange', onFullscreenChange);
  if (audioCtx) audioCtx.close().catch(() => {});
});
</script>

<style scoped>
/* Stat tiles — same design as the Sales list summary cards. */
.stat-card {
  border-radius: 10px;
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .stat-inner {
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}

.live-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
  margin-right: 6px;
  vertical-align: middle;
}
.live-dot.on {
  animation: pulse 1.4s infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}
.lane {
  background: rgba(128, 128, 128, 0.06);
  border-radius: 12px;
  padding: 10px;
  min-height: 220px;
}
.lane-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: #fff;
  border-radius: 8px;
  padding: 8px 12px;
  font-weight: 600;
  margin-bottom: 10px;
}
.lane-count {
  background: rgba(255, 255, 255, 0.28);
  border-radius: 10px;
  padding: 0 8px;
}
.lane-body {
  max-height: calc(100vh - 380px);
  overflow-y: auto;
}
.ticket {
  border-left: 4px solid #cbd5e1;
  margin-bottom: 10px;
}
.ticket-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.items {
  list-style: none;
  padding: 0;
  margin: 8px 0;
}
.items li {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 2px 0;
  font-size: 13px;
}
/* Bumped lines read as done without disappearing. */
.items li.done .qty,
.items li.done .item-name {
  text-decoration: line-through;
  opacity: 0.55;
}
.qty {
  min-width: 46px;
  font-weight: 700;
  color: #6d28d9;
}
.ticket-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin-top: 8px;
}
.muted {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}

/* Stations editor rows: name, categories, remove. */
.station-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

/* Daily call number — the thing a kitchen shouts across the counter. */
.token {
  font-weight: 800;
  font-size: 15px;
  color: #6d28d9;
  margin-right: 6px;
}
.ticket-top .ticket-ref {
  margin-right: auto;
}
.ticket-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin: 4px 0;
}

/* Overdue escalation: elapsed time recolors at 50% / 100% of the prep target,
   and late tickets pulse so they read from across the kitchen. */
.elapsed.warn {
  color: #d97706;
  font-weight: 700;
}
.elapsed.late {
  color: #dc2626;
  font-weight: 700;
}
.ticket-late {
  animation: late-pulse 1.6s infinite;
}
@keyframes late-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.45); }
  50% { box-shadow: 0 0 0 5px rgba(239, 68, 68, 0); }
}

/* Kitchen mode: the board fullscreened for a wall TV — filters drop away and
   type scales up for at-a-distance reading. (Background is set inline so it
   follows the light/dark theme.) */
.kitchen-mode {
  padding: 16px;
  height: 100%;
  overflow-y: auto;
}
.kitchen-mode .filters-card {
  display: none;
}
.kitchen-mode .lane-body {
  max-height: calc(100vh - 300px);
}
.kitchen-mode .token {
  font-size: 20px;
}
.kitchen-mode .ticket-ref {
  font-size: 16px;
}
.kitchen-mode .items li {
  font-size: 16px;
}
.kitchen-mode .qty {
  min-width: 54px;
}
.kitchen-mode .muted {
  font-size: 14px;
}
.kitchen-mode .lane-head {
  font-size: 17px;
}
</style>
