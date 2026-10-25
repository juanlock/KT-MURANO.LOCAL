<template>
  <!--
    Floor map (2026-10-25): a grid of map_cols x map_rows cells. Rooms (rooms,
    offices, ER, nursing...) are rectangles; beds are small squares colored by
    status. editable: drag a room to move it, its corner to resize it, drag a
    bed (or click a cell while placing one) to put it in a room. Read only:
    beds can be picked (pickable(bed) decides which ones).
  -->
  <div ref="wrap" class="fm-wrap">
    <div class="fm-grid" :style="gridStyle" @click="onGridClick">
      <div
        v-for="r in floor.rooms" :key="'r' + r.id" class="fm-room" :class="['k-' + r.kind, { sel: selectedRoomId === r.id, edit: editable }]"
        :style="rectStyle(r)" @pointerdown="editable && startDrag($event, 'room', r)" @click.stop="$emit('select-room', r)"
      >
        <span class="fm-rname">{{ r.name }}</span>
        <span v-if="r.ward_name && cell > 22" class="fm-rward">{{ r.ward_name }}</span>
        <span v-if="editable" class="fm-resize" @pointerdown.stop="startDrag($event, 'resize', r)" />
      </div>
      <a-tooltip v-for="b in placedBeds" :key="'b' + b.id" :title="bedTitle(b)">
        <div
          class="fm-bed" :class="['s-' + b.status, { pick: canPick(b), dim: pickMode && !canPick(b), sel: selectedBedId === b.id, late: b.status === 'cleaning' && b.status_minutes > 30, edit: editable }]"
          :style="bedStyle(b)" @pointerdown="editable && startDrag($event, 'bed', b)" @click.stop="onBedClick(b)"
        >
          <span>{{ b.bed_number }}</span>
        </div>
      </a-tooltip>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  floor: { type: Object, required: true },        // { map_cols, map_rows, rooms: [], beds: [] }
  editable: { type: Boolean, default: false },
  pickable: { type: Function, default: null },    // (bed) => bool -- read-only bed picking
  selectedBedId: { type: [Number, null], default: null },
  selectedRoomId: { type: [Number, null], default: null },
  placingBed: { type: Object, default: null },    // an unplaced bed waiting for a click on the map
  statusLabel: { type: Function, default: s => s },
});
const emit = defineEmits(['change', 'select-room', 'pick-bed', 'place-bed']);

const wrap = ref(null);
const width = ref(800);
let ro = null;
onMounted(() => {
  ro = new ResizeObserver(e => { width.value = e[0].contentRect.width || 800; });
  if (wrap.value) ro.observe(wrap.value);
});
onBeforeUnmount(() => ro && ro.disconnect());

const cols = computed(() => props.floor.map_cols || 24);
// Read only: the map ends one row below its last room (no empty grid to scroll past).
const rows = computed(() => {
  const all = props.floor.map_rows || 14;
  if (props.editable) return all;
  const bottom = Math.max(0, ...(props.floor.rooms || []).map(r => (r.y ?? 0) + (r.h ?? 0)));
  return Math.min(all, Math.max(4, bottom + 1));
});
const cell = computed(() => Math.max(14, Math.floor(width.value / cols.value)));
const gridStyle = computed(() => ({
  width: cell.value * cols.value + 'px', height: cell.value * rows.value + 'px',
  backgroundSize: `${cell.value}px ${cell.value}px`,
}));
const placedBeds = computed(() => (props.floor.beds || []).filter(b => b.x != null && b.y != null));
const pickMode = computed(() => !!props.pickable);
const canPick = b => !!props.pickable && props.pickable(b);

const rectStyle = r => ({
  left: (r.x ?? 0) * cell.value + 'px', top: (r.y ?? 0) * cell.value + 'px',
  width: (r.w ?? 4) * cell.value + 'px', height: (r.h ?? 3) * cell.value + 'px',
  ...(r.color ? { background: r.color } : {}),
});
const bedStyle = b => {
  const pad = Math.max(2, Math.round(cell.value * 0.12));
  return { left: b.x * cell.value + pad + 'px', top: b.y * cell.value + pad + 'px', width: cell.value - pad * 2 + 'px', height: cell.value - pad * 2 + 'px',
    fontSize: Math.max(9, Math.round(cell.value * 0.34)) + 'px' };
};
function bedTitle(b) {
  const parts = [`${b.ward_name ? b.ward_name + ' · ' : ''}${b.bed_number}`, props.statusLabel(b.status)];
  if (b.patient_name) parts.push(b.patient_name);
  if (b.status === 'cleaning' && b.status_minutes != null) parts.push(`${b.status_minutes} min`);
  return parts.join(' — ');
}
function onBedClick(b) {
  if (props.editable) return;
  if (canPick(b)) emit('pick-bed', b);
}

// The room that contains a cell (beds dropped there join it).
function roomAt(x, y) {
  return (props.floor.rooms || []).find(r => x >= r.x && x < r.x + r.w && y >= r.y && y < r.y + r.h) || null;
}
function cellFromEvent(e) {
  const box = e.currentTarget.getBoundingClientRect();
  return [Math.floor((e.clientX - box.left) / cell.value), Math.floor((e.clientY - box.top) / cell.value)];
}
// Placing an unplaced bed: click a cell inside a room.
function onGridClick(e) {
  if (!props.editable || !props.placingBed) return;
  const [x, y] = cellFromEvent(e);
  const room = roomAt(x, y);
  if (room) emit('place-bed', { bed: props.placingBed, x, y, room_id: room.id });
}

// ---- drag (move a room / resize it / move a bed), snapped to the grid
let drag = null;
function startDrag(e, kind, item) {
  e.preventDefault();
  drag = { kind, item, sx: e.clientX, sy: e.clientY, ox: item.x ?? 0, oy: item.y ?? 0, ow: item.w ?? 4, oh: item.h ?? 3,
    beds: kind === 'room' ? placedBeds.value.filter(b => b.room_id === item.id).map(b => ({ b, ox: b.x, oy: b.y })) : [] };
  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', endDrag, { once: true });
}
function onMove(e) {
  if (!drag) return;
  const dx = Math.round((e.clientX - drag.sx) / cell.value);
  const dy = Math.round((e.clientY - drag.sy) / cell.value);
  const it = drag.item;
  if (drag.kind === 'resize') {
    it.w = Math.max(1, Math.min(cols.value - it.x, drag.ow + dx));
    it.h = Math.max(1, Math.min(rows.value - it.y, drag.oh + dy));
  } else if (drag.kind === 'room') {
    const nx = Math.max(0, Math.min(cols.value - it.w, drag.ox + dx));
    const ny = Math.max(0, Math.min(rows.value - it.h, drag.oy + dy));
    // The room's beds travel with it.
    drag.beds.forEach(({ b, ox, oy }) => { b.x = ox + (nx - drag.ox); b.y = oy + (ny - drag.oy); });
    it.x = nx; it.y = ny;
  } else {
    it.x = Math.max(0, Math.min(cols.value - 1, drag.ox + dx));
    it.y = Math.max(0, Math.min(rows.value - 1, drag.oy + dy));
  }
}
function endDrag() {
  window.removeEventListener('pointermove', onMove);
  if (!drag) return;
  if (drag.kind === 'bed') {
    const room = roomAt(drag.item.x, drag.item.y);
    drag.item.room_id = room ? room.id : null;
  }
  const moved = drag.kind === 'resize' ? (drag.item.w !== drag.ow || drag.item.h !== drag.oh) : (drag.item.x !== drag.ox || drag.item.y !== drag.oy);
  drag = null;
  if (moved) emit('change');
}
</script>

<style scoped>
.fm-wrap { width: 100%; overflow: auto; }
.fm-grid {
  position: relative; border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 6px;
  background-color: rgba(128, 128, 128, 0.03);
  background-image: linear-gradient(rgba(128, 128, 128, 0.12) 1px, transparent 1px), linear-gradient(90deg, rgba(128, 128, 128, 0.12) 1px, transparent 1px);
}
.fm-room {
  position: absolute; border: 1.5px solid rgba(15, 23, 42, 0.25); border-radius: 6px; padding: 3px 5px;
  display: flex; flex-direction: column; overflow: hidden; color: #0f172a; user-select: none;
}
.fm-room.edit { cursor: move; }
.fm-room.sel { outline: 2px solid #1677ff; outline-offset: 1px; z-index: 2; }
.fm-rname { font-weight: 700; font-size: 12px; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fm-rward { font-size: 10.5px; opacity: 0.7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fm-resize { position: absolute; right: 0; bottom: 0; width: 12px; height: 12px; cursor: nwse-resize; background: linear-gradient(135deg, transparent 50%, rgba(15, 23, 42, 0.45) 50%); }
.k-room { background: #e0f2fe; } .k-office { background: #f1f5f9; } .k-er { background: #fee2e2; } .k-nursing { background: #dcfce7; }
.k-or { background: #ede9fe; } .k-lab { background: #fef9c3; } .k-imaging { background: #fae8ff; } .k-pharmacy { background: #ccfbf1; }
.k-reception { background: #ffedd5; } .k-waiting { background: #f5f5f4; } .k-bath { background: #e2e8f0; } .k-stairs { background: #d4d4d8; } .k-other { background: #f8fafc; }
.fm-bed {
  position: absolute; z-index: 3; border-radius: 4px; color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25); user-select: none; line-height: 1; overflow: hidden;
}
.fm-bed.edit { cursor: grab; }
.s-available { background: #16a34a; } .s-occupied { background: #ef4444; } .s-cleaning { background: #7c3aed; }
.s-reserved { background: #d97706; } .s-maintenance { background: #94a3b8; }
.fm-bed.late { box-shadow: 0 0 0 2px #dc2626, 0 0 8px rgba(220, 38, 38, 0.6); }
.fm-bed.pick { cursor: pointer; transform-origin: center; transition: transform 0.12s; }
.fm-bed.pick:hover { transform: scale(1.15); }
.fm-bed.dim { opacity: 0.35; }
.fm-bed.sel { outline: 3px solid #1677ff; outline-offset: 1px; }
</style>
