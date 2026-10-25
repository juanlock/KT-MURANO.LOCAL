<template>
  <div class="page">
    <PageHeader :title="s('Hospital configuration')" :subtitle="s('Set up once: shifts and their hours, floors, rooms, beds and the map of each floor.')" :breadcrumb="[s('Hospital'), s('Configuration')]">
      <template #actions>
        <a-space wrap>
          <PermLink to="/hospital/departments" permission="hms_departments"><a-button>{{ s('Departments') }}</a-button></PermLink>
          <PermLink to="/hospital/wards" permission="hms_wards"><a-button>{{ s('Wards & beds') }}</a-button></PermLink>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-card :body-style="{ padding: '8px 16px 16px' }">
        <a-tabs v-model:activeKey="tab">
          <!-- ===================== Shifts ===================== -->
          <a-tab-pane key="shifts" :tab="s('Shifts and hours')">
            <p class="hint">{{ s('The shifts the staff works. A shift belongs to the day it starts (a night shift that ends the next morning is of the day it began). A 24-hour shift is the emergency guard.') }}</p>
            <div class="shifts">
              <div v-for="(sh, i) in shifts" :key="sh.key" class="shift-row" :class="{ off: !sh.active }">
                <input v-model="sh.color" type="color" class="color" :title="s('Color')" @input="dirtyShifts = true">
                <a-input v-model:value="sh.name" :placeholder="s('Name')" class="sh-name" :maxlength="80" @change="dirtyShifts = true" />
                <span class="lbl">{{ s('Starts') }}</span>
                <a-time-picker v-model:value="sh.start" format="HH:mm" value-format="HH:mm" :minute-step="15" :allow-clear="false" class="sh-time" @change="dirtyShifts = true" />
                <span class="lbl">{{ s('Hours') }}</span>
                <a-input-number v-model:value="sh.hours" :min="1" :max="24" class="sh-hours" @change="dirtyShifts = true" />
                <span class="ends">{{ s('ends {t}', { t: endOf(sh) }) }}</span>
                <a-switch v-model:checked="sh.active" size="small" @change="dirtyShifts = true" />
                <span class="act">
                  <a-button type="text" size="small" :disabled="i === 0" @click="moveShift(i, -1)"><ArrowUpOutlined /></a-button>
                  <a-button type="text" size="small" :disabled="i === shifts.length - 1" @click="moveShift(i, 1)"><ArrowDownOutlined /></a-button>
                  <a-button type="text" size="small" danger @click="removeShift(i)"><DeleteOutlined /></a-button>
                </span>
              </div>
            </div>
            <a-space style="margin-top: 12px">
              <a-button @click="addShift"><PlusOutlined /> {{ s('Add a shift') }}</a-button>
              <a-button type="primary" :disabled="!dirtyShifts" :loading="saving" @click="saveShifts"><SaveOutlined /> {{ s('Save shifts') }}</a-button>
            </a-space>
          </a-tab-pane>

          <!-- ===================== Floors & rooms ===================== -->
          <a-tab-pane key="floors" :tab="s('Floors and rooms')">
            <div class="two">
              <div>
                <div class="h">{{ s('Floors') }}</div>
                <div v-for="f in floors" :key="f.id" class="floor" :class="{ on: f.id === floorId }" @click="floorId = f.id">
                  <span class="f-name">{{ f.name }}</span>
                  <span class="f-meta">{{ s('{n} rooms', { n: f.rooms.length }) }} · {{ s('{n} beds', { n: f.beds.length }) }}</span>
                  <a-button type="text" size="small" @click.stop="openFloor(f)"><EditOutlined /></a-button>
                </div>
                <a-button block style="margin-top: 8px" @click="openFloor(null)"><PlusOutlined /> {{ s('Add a floor') }}</a-button>
              </div>

              <div v-if="floor">
                <div class="h">{{ s('Rooms and spaces on {f}', { f: floor.name }) }}</div>
                <a-table :data-source="floor.rooms" :columns="roomColumns" row-key="id" size="small" :pagination="false">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'kind'"><a-tag :class="'k-' + record.kind">{{ s(KIND_LABEL[record.kind] || record.kind) }}</a-tag></template>
                    <template v-else-if="column.key === 'beds'">
                      <template v-if="record.kind === 'room' || record.kind === 'er'">
                        <span class="beds-in">{{ bedsOf(record).map(b => b.bed_number).join(', ') || '—' }}</span>
                      </template>
                      <span v-else class="muted">—</span>
                    </template>
                    <template v-else-if="column.key === 'act'">
                      <a-space :size="2">
                        <a-button v-if="record.kind === 'room' || record.kind === 'er'" size="small" @click="openBeds(record)"><PlusOutlined /> {{ s('Beds') }}</a-button>
                        <a-button type="text" size="small" @click="openRoom(record)"><EditOutlined /></a-button>
                        <a-button type="text" size="small" danger @click="removeRoom(record)"><DeleteOutlined /></a-button>
                      </a-space>
                    </template>
                  </template>
                </a-table>
                <a-button type="primary" ghost style="margin-top: 10px" @click="openRoom(null)"><PlusOutlined /> {{ s('Add a room or space') }}</a-button>
                <p class="hint" style="margin-top: 10px">{{ s('Patient rooms belong to a ward (its rate per night) and hold beds. Offices, ER, nursing station, operating rooms... are spaces on the map to guide admissions and visitors.') }}</p>
              </div>
              <a-empty v-else :description="s('Add the first floor of the building.')" />
            </div>
          </a-tab-pane>

          <!-- ===================== Map ===================== -->
          <a-tab-pane key="map" :tab="s('Floor map')">
            <div class="map-bar">
              <a-segmented v-model:value="floorId" :options="floorOptions" />
              <span class="lbl">{{ s('Grid') }}</span>
              <a-input-number v-if="floor" v-model:value="floor.map_cols" :min="6" :max="80" size="small" style="width: 70px" @change="dirtyMap = true" />
              <span class="lbl">×</span>
              <a-input-number v-if="floor" v-model:value="floor.map_rows" :min="4" :max="60" size="small" style="width: 70px" @change="dirtyMap = true" />
              <a-button type="primary" :disabled="!dirtyMap" :loading="saving" @click="saveMap"><SaveOutlined /> {{ s('Save map') }}</a-button>
            </div>
            <p class="hint">{{ s('Drag a room to move it and its corner to resize it. Drag a bed to another room. To place a bed that is not on the map yet, click it on the right and then click inside a room.') }}</p>
            <div v-if="floor" class="map-two">
              <FloorMap
                :floor="floor" editable :placing-bed="placing" :selected-room-id="selRoom" :status-label="st => s(statusLabel(st))"
                @change="dirtyMap = true" @select-room="r => selRoom = r.id" @place-bed="placeBed"
              />
              <div class="side">
                <div class="h">{{ s('Beds not on the map') }}</div>
                <div v-if="!unplaced.length" class="muted">{{ s('Every bed is on a map.') }}</div>
                <div class="unplaced">
                  <button v-for="b in unplaced" :key="b.id" type="button" class="ub" :class="{ on: placing && placing.id === b.id }" @click="placing = placing && placing.id === b.id ? null : b">
                    <b>{{ b.bed_number }}</b> <small>{{ b.ward_name }}</small>
                  </button>
                </div>
                <div class="h" style="margin-top: 14px">{{ s('Legend') }}</div>
                <div class="legend">
                  <span v-for="st in ['available', 'occupied', 'cleaning', 'reserved', 'maintenance']" :key="st"><i :class="'s-' + st" /> {{ s(statusLabel(st)) }}</span>
                </div>
              </div>
            </div>
            <a-empty v-else :description="s('Add the first floor of the building.')" />
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <!-- Floor -->
    <a-modal :open="floorOpen" :title="floorForm.id ? s('Edit floor') : s('Add a floor')" :ok-text="s('Save')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="saveFloor" @cancel="floorOpen = false">
      <a-form layout="vertical">
        <a-form-item :label="s('Name') + ' *'"><a-input v-model:value="floorForm.name" :placeholder="s('e.g. Ground floor, 1st floor')" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="8"><a-form-item :label="s('Level')"><a-input-number v-model:value="floorForm.level" :min="-10" :max="200" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="s('Map width (cells)')"><a-input-number v-model:value="floorForm.map_cols" :min="6" :max="80" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="s('Map height (cells)')"><a-input-number v-model:value="floorForm.map_rows" :min="4" :max="60" style="width: 100%" /></a-form-item></a-col>
        </a-row>
      </a-form>
      <template v-if="floorForm.id" #footer>
        <a-button danger style="float: left" @click="removeFloor">{{ s('Delete floor') }}</a-button>
        <a-button @click="floorOpen = false">{{ s('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="saveFloor">{{ s('Save') }}</a-button>
      </template>
    </a-modal>

    <!-- Room -->
    <a-modal :open="roomOpen" :title="roomForm.id ? s('Edit room') : s('Add a room or space')" :ok-text="s('Save')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="saveRoom" @cancel="roomOpen = false">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="14"><a-form-item :label="s('Name') + ' *'"><a-input v-model:value="roomForm.name" :placeholder="s('e.g. Room 101, Nursing station')" /></a-form-item></a-col>
          <a-col :span="10"><a-form-item :label="s('Type') + ' *'"><a-select v-model:value="roomForm.kind" :options="kindOptions" /></a-form-item></a-col>
        </a-row>
        <a-form-item v-if="roomForm.kind === 'room' || roomForm.kind === 'er'" :label="s('Ward (rate per night)')">
          <a-select v-model:value="roomForm.ward_id" :options="wardOptions" allow-clear :placeholder="s('Pick the ward')" />
        </a-form-item>
        <a-row v-if="!roomForm.id" :gutter="12">
          <a-col :span="12"><a-form-item :label="s('Width on the map (cells)')"><a-input-number v-model:value="roomForm.map_w" :min="1" :max="40" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="s('Height on the map (cells)')"><a-input-number v-model:value="roomForm.map_h" :min="1" :max="40" style="width: 100%" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Beds in a room -->
    <a-modal :open="bedsOpen" :title="s('Beds in {r}', { r: bedsRoom?.name || '' })" :ok-text="s('Add')" :cancel-text="s('Cancel')" :confirm-loading="saving" @ok="saveBeds" @cancel="bedsOpen = false">
      <a-form layout="vertical">
        <a-form-item :label="s('Bed numbers')" :extra="s('One number, a range like 1-4, or a list like A,B')">
          <a-input v-model:value="bedNumbers" :placeholder="s('e.g. 101-104')" @press-enter="saveBeds" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, SaveOutlined, ArrowUpOutlined, ArrowDownOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { BED_STATUSES, labelOf } from './hospitalOptions';
import FloorMap from './FloorMap.vue';
import { useHospitalText } from './lang/useHospitalText';
import ES from './lang/HospitalSetup.es.js';

const { s } = useHospitalText(ES);
const loading = ref(false);
const saving = ref(false);
const tab = ref('shifts');

const KIND_LABEL = {
  room: 'Patient room', office: 'Office', er: 'Emergency', nursing: 'Nursing station', or: 'Operating room', lab: 'Laboratory',
  imaging: 'Imaging', pharmacy: 'Pharmacy', reception: 'Reception', waiting: 'Waiting room', bath: 'Bathroom', stairs: 'Stairs / elevator', other: 'Other',
};
const statusLabel = st => labelOf(BED_STATUSES, st);
const err = e => message.error(e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || e?.message || s('Could not save'));

// ---------------- data
const floors = ref([]);
const wards = ref([]);
const unplaced = ref([]);
const kinds = ref([]);
const floorId = ref(null);
const floor = computed(() => floors.value.find(f => f.id === floorId.value) || null);
const floorOptions = computed(() => floors.value.map(f => ({ value: f.id, label: f.name })));
const wardOptions = computed(() => sortByLabel(wards.value.map(w => ({ value: w.id, label: w.name }))));
const kindOptions = computed(() => kinds.value.map(k => ({ value: k, label: s(KIND_LABEL[k] || k) })));
const bedsOf = room => (floor.value?.beds || []).filter(b => b.room_id === room.id);

function takeFloors(list) {
  floors.value = list;
  if (!list.find(f => f.id === floorId.value)) floorId.value = list[0]?.id ?? null;
}
async function load() {
  loading.value = true;
  try {
    const d = await http.get('hospital/setup');
    shifts.value = d.shifts.map(toShift);
    takeFloors(d.floors);
    wards.value = d.wards;
    unplaced.value = d.unplaced_beds;
    kinds.value = d.kinds;
    dirtyShifts.value = false;
    dirtyMap.value = false;
  } catch (e) { err(e); } finally { loading.value = false; }
}
async function refreshUnplaced() {
  try { unplaced.value = (await http.get('hospital/setup')).unplaced_beds; } catch (e) { /* the list stays */ }
}

// ---------------- shifts
const shifts = ref([]);
const dirtyShifts = ref(false);
let k = 0;
const toShift = t => ({ key: ++k, code: t.code, name: t.name, start: t.start, hours: t.hours, color: t.color || '#64748b', active: t.active });
function endOf(sh) {
  const [h, m] = String(sh.start || '00:00').split(':').map(Number);
  const end = (h * 60 + m + (sh.hours || 0) * 60) % (24 * 60);
  const next = h * 60 + m + (sh.hours || 0) * 60 >= 24 * 60;
  return `${String(Math.floor(end / 60)).padStart(2, '0')}:${String(end % 60).padStart(2, '0')}${next ? ' (' + s('next day') + ')' : ''}`;
}
function addShift() { shifts.value.push(toShift({ name: '', start: '07:00', hours: 8, color: '#64748b', active: true })); dirtyShifts.value = true; }
function moveShift(i, d) { const a = shifts.value; [a[i], a[i + d]] = [a[i + d], a[i]]; dirtyShifts.value = true; }
function removeShift(i) { shifts.value.splice(i, 1); dirtyShifts.value = true; }
async function saveShifts() {
  if (shifts.value.some(x => !x.name.trim())) return message.warning(s('Every shift needs a name.'));
  saving.value = true;
  try {
    const d = await http.put('hospital/setup/shifts', { shifts: shifts.value.map(x => ({ code: x.code, name: x.name.trim(), start: x.start, hours: x.hours, color: x.color, active: x.active })) });
    shifts.value = d.shifts.map(toShift);
    dirtyShifts.value = false;
    message.success(s('Saved'));
  } catch (e) { err(e); } finally { saving.value = false; }
}

// ---------------- floors
const floorOpen = ref(false);
const floorForm = reactive({ id: null, name: '', level: 1, map_cols: 24, map_rows: 14 });
function openFloor(f) {
  Object.assign(floorForm, f ? { id: f.id, name: f.name, level: f.level, map_cols: f.map_cols, map_rows: f.map_rows }
    : { id: null, name: '', level: floors.value.length, map_cols: 24, map_rows: 14 });
  floorOpen.value = true;
}
async function saveFloor() {
  if (!floorForm.name.trim()) return message.warning(s('Type the name.'));
  saving.value = true;
  try {
    const body = { name: floorForm.name.trim(), level: floorForm.level, map_cols: floorForm.map_cols, map_rows: floorForm.map_rows };
    const d = floorForm.id ? await http.put(`hospital/setup/floors/${floorForm.id}`, body) : await http.post('hospital/setup/floors', body);
    takeFloors(d.floors);
    if (d.id) floorId.value = d.id;
    floorOpen.value = false;
  } catch (e) { err(e); } finally { saving.value = false; }
}
function removeFloor() {
  Modal.confirm({
    title: s('Delete the floor {f}?', { f: floorForm.name }), icon: createVNode(ExclamationCircleOutlined),
    content: s('Its rooms are removed and their beds go back to "not on the map". Wards and beds are kept.'),
    okType: 'danger', okText: s('Delete'), cancelText: s('Cancel'),
    async onOk() {
      try { takeFloors((await http.delete(`hospital/setup/floors/${floorForm.id}`)).floors); floorOpen.value = false; refreshUnplaced(); } catch (e) { err(e); }
    },
  });
}

// ---------------- rooms
const roomColumns = computed(() => [
  { title: s('Name'), dataIndex: 'name', key: 'name' },
  { title: s('Type'), key: 'kind', width: 150 },
  { title: s('Ward'), dataIndex: 'ward_name', key: 'ward', width: 170 },
  { title: s('Beds'), key: 'beds' },
  { title: '', key: 'act', width: 170, align: 'right' },
]);
const roomOpen = ref(false);
const roomForm = reactive({ id: null, name: '', kind: 'room', ward_id: null, map_w: 4, map_h: 3 });
function openRoom(r) {
  Object.assign(roomForm, r ? { id: r.id, name: r.name, kind: r.kind, ward_id: r.ward_id, map_w: r.w, map_h: r.h }
    : { id: null, name: '', kind: 'room', ward_id: null, map_w: 4, map_h: 3 });
  roomOpen.value = true;
}
async function saveRoom() {
  if (!roomForm.name.trim()) return message.warning(s('Type the name.'));
  saving.value = true;
  try {
    const body = { floor_id: floorId.value, name: roomForm.name.trim(), kind: roomForm.kind, ward_id: roomForm.ward_id || null, map_w: roomForm.map_w, map_h: roomForm.map_h };
    const d = roomForm.id ? await http.put(`hospital/setup/rooms/${roomForm.id}`, body) : await http.post('hospital/setup/rooms', body);
    takeFloors(d.floors);
    roomOpen.value = false;
  } catch (e) { err(e); } finally { saving.value = false; }
}
function removeRoom(r) {
  Modal.confirm({
    title: s('Delete {r}?', { r: r.name }), icon: createVNode(ExclamationCircleOutlined),
    content: s('Its beds go back to "not on the map" (the beds themselves are kept).'),
    okType: 'danger', okText: s('Delete'), cancelText: s('Cancel'),
    async onOk() { try { takeFloors((await http.delete(`hospital/setup/rooms/${r.id}`)).floors); refreshUnplaced(); } catch (e) { err(e); } },
  });
}
const bedsOpen = ref(false);
const bedsRoom = ref(null);
const bedNumbers = ref('');
function openBeds(r) {
  if (!r.ward_id) return message.warning(s('Pick the ward of this room first (edit the room).'));
  bedsRoom.value = r; bedNumbers.value = ''; bedsOpen.value = true;
}
async function saveBeds() {
  if (!bedNumbers.value.trim()) return;
  saving.value = true;
  try {
    const d = await http.post(`hospital/setup/rooms/${bedsRoom.value.id}/beds`, { bed_number: bedNumbers.value.trim() });
    takeFloors(d.floors);
    message.success(s('{n} beds added', { n: d.count }));
    bedsOpen.value = false;
  } catch (e) { err(e); } finally { saving.value = false; }
}

// ---------------- map
const dirtyMap = ref(false);
const placing = ref(null);
const selRoom = ref(null);
function placeBed({ bed, x, y, room_id }) {
  floor.value.beds.push({ ...bed, x, y, room_id });
  unplaced.value = unplaced.value.filter(b => b.id !== bed.id);
  placing.value = null;
  dirtyMap.value = true;
}
async function saveMap() {
  saving.value = true;
  try {
    const f = floor.value;
    const d = await http.put(`hospital/setup/floors/${f.id}/map`, {
      map_cols: f.map_cols, map_rows: f.map_rows,
      rooms: f.rooms.map(r => ({ id: r.id, x: r.x, y: r.y, w: r.w, h: r.h })),
      beds: f.beds.map(b => ({ id: b.id, x: b.room_id ? b.x : null, y: b.room_id ? b.y : null, room_id: b.room_id })),
    });
    takeFloors(d.floors);
    dirtyMap.value = false;
    refreshUnplaced();
    message.success(s('Map saved'));
  } catch (e) { err(e); } finally { saving.value = false; }
}

onMounted(load);
</script>

<style scoped>
.hint { color: rgba(128, 128, 128, 0.95); font-size: 13px; margin: 4px 0 12px; }
.muted { opacity: 0.6; }
.h { font-weight: 600; margin-bottom: 8px; }
.lbl { font-size: 12px; opacity: 0.7; }
.shifts { display: flex; flex-direction: column; gap: 8px; }
.shift-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 8px; padding: 8px 10px; }
.shift-row.off { opacity: 0.55; }
.color { width: 30px; height: 30px; border: 0; padding: 0; background: none; cursor: pointer; }
.sh-name { width: 220px; }
.sh-time { width: 100px; }
.sh-hours { width: 70px; }
.ends { font-size: 12.5px; opacity: 0.75; min-width: 130px; }
.act { margin-inline-start: auto; display: inline-flex; }
.two { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 20px; align-items: start; }
@media (max-width: 900px) { .two { grid-template-columns: 1fr; } }
.floor { display: flex; align-items: center; gap: 6px; padding: 8px 10px; border-radius: 8px; border: 1px solid transparent; cursor: pointer; }
.floor:hover { background: rgba(128, 128, 128, 0.07); }
.floor.on { border-color: #1677ff; background: rgba(22, 119, 255, 0.06); }
.f-name { font-weight: 600; flex: 1; }
.f-meta { font-size: 12px; opacity: 0.65; }
.beds-in { font-size: 12.5px; }
.map-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
.map-two { display: grid; grid-template-columns: minmax(0, 1fr) 220px; gap: 16px; align-items: start; }
@media (max-width: 1000px) { .map-two { grid-template-columns: 1fr; } }
.unplaced { display: flex; flex-wrap: wrap; gap: 6px; max-height: 300px; overflow: auto; }
.ub { border: 1px solid rgba(128, 128, 128, 0.35); background: transparent; border-radius: 6px; padding: 3px 8px; cursor: pointer; color: inherit; font: inherit; text-align: left; }
.ub small { opacity: 0.65; }
.ub.on { border-color: #1677ff; background: rgba(22, 119, 255, 0.1); }
.legend { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; vertical-align: -1px; margin-inline-end: 4px; }
.s-available { background: #16a34a; } .s-occupied { background: #ef4444; } .s-cleaning { background: #7c3aed; } .s-reserved { background: #d97706; } .s-maintenance { background: #94a3b8; }
.k-room { background: #e0f2fe; } .k-office { background: #f1f5f9; } .k-er { background: #fee2e2; } .k-nursing { background: #dcfce7; } .k-or { background: #ede9fe; }
.k-lab { background: #fef9c3; } .k-imaging { background: #fae8ff; } .k-pharmacy { background: #ccfbf1; } .k-reception { background: #ffedd5; }
.k-waiting { background: #f5f5f4; } .k-bath { background: #e2e8f0; } .k-stairs { background: #d4d4d8; } .k-other { background: #f8fafc; }
</style>
