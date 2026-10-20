<template>
  <!-- Logo picker with crop / resize (Companies -> Add / Edit). v-model = the
       final image as a data URL (PNG keeps transparency), or '' for none.
       `current` = the logo already saved (URL), shown until a new one is chosen. -->
  <div class="lc">
    <div class="lc-preview" :class="{ 'lc-preview--empty': !shown }">
      <img v-if="shown" :src="shown" alt="Logo" />
      <span v-else class="lc-muted">No logo</span>
    </div>
    <div class="lc-side">
      <a-space wrap>
        <a-upload :show-upload-list="false" accept="image/png,image/jpeg,image/webp,image/gif" :before-upload="pick">
          <a-button><template #icon><UploadOutlined /></template>{{ shown ? 'Change logo' : 'Upload logo' }}</a-button>
        </a-upload>
        <a-button v-if="source" @click="open = true"><template #icon><ScissorOutlined /></template>Crop / resize</a-button>
        <a-button v-if="modelValue" type="link" danger @click="clear">Remove new logo</a-button>
      </a-space>
      <div class="lc-muted lc-hint">
        PNG, JPG, WebP or GIF. After choosing it you can cut (crop) it and set its size.
        <template v-if="outInfo"> Ready: {{ outInfo }}.</template>
      </div>
    </div>

    <a-modal v-model:open="open" title="Crop / resize the logo" :width="660" ok-text="Use this logo" @ok="apply">
      <div class="lc-tools">
        <div>
          <span class="lc-label">Shape</span>
          <a-radio-group v-model:value="aspect" size="small" button-style="solid" @change="resetCrop">
            <a-radio-button value="free">Free</a-radio-button>
            <a-radio-button :value="1">Square 1:1</a-radio-button>
            <a-radio-button :value="3">Wide 3:1</a-radio-button>
          </a-radio-group>
        </div>
        <a-button size="small" @click="wholeImage">Whole image (no crop)</a-button>
      </div>

      <div class="lc-stage" :style="{ width: dispW + 'px', height: dispH + 'px' }">
        <img v-if="source" :src="source" class="lc-img" :style="{ width: dispW + 'px', height: dispH + 'px' }" draggable="false" alt="" />
        <div class="lc-shade" />
        <div
          class="lc-box"
          :style="{ left: crop.x * scale + 'px', top: crop.y * scale + 'px', width: crop.w * scale + 'px', height: crop.h * scale + 'px',
                    backgroundImage: `url(${source})`, backgroundSize: `${dispW}px ${dispH}px`,
                    backgroundPosition: `-${crop.x * scale}px -${crop.y * scale}px` }"
          @pointerdown.prevent="start($event, 'move')"
        >
          <span v-for="c in ['nw', 'ne', 'sw', 'se']" :key="c" :class="['lc-h', 'lc-h--' + c]" @pointerdown.prevent.stop="start($event, c)" />
        </div>
      </div>

      <div class="lc-tools" style="margin-top: 12px">
        <div>
          <span class="lc-label">Size (longest side)</span>
          <a-select v-model:value="maxSide" size="small" style="width: 150px" :options="sizeOptions" />
        </div>
        <span class="lc-muted">Result: {{ outW }} × {{ outH }} px (crop {{ Math.round(crop.w) }} × {{ Math.round(crop.h) }} of {{ natW }} × {{ natH }})</span>
      </div>
    </a-modal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { UploadOutlined, ScissorOutlined } from '@ant-design/icons-vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  current: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const shown = computed(() => props.modelValue || props.current || '');
const source = ref('');      // the original picked image (data URL)
const open = ref(false);
const natW = ref(0);
const natH = ref(0);
const crop = reactive({ x: 0, y: 0, w: 0, h: 0 }); // in the image's own pixels
const aspect = ref('free');
const maxSide = ref(600);
const outInfo = ref('');

// Fixed choices of the output size, smallest first (not alphabetical: a size scale).
const sizeOptions = [200, 300, 400, 600, 800, 1200].map(v => ({ value: v, label: `${v} px` }));

// The editor shows the image at most 600 x 380.
const scale = computed(() => (natW.value && natH.value ? Math.min(600 / natW.value, 380 / natH.value, 1) : 1));
const dispW = computed(() => Math.round(natW.value * scale.value));
const dispH = computed(() => Math.round(natH.value * scale.value));
const outScale = computed(() => Math.min(1, maxSide.value / Math.max(crop.w || 1, crop.h || 1)));
const outW = computed(() => Math.max(1, Math.round(crop.w * outScale.value)));
const outH = computed(() => Math.max(1, Math.round(crop.h * outScale.value)));

function pick(file) {
  if (!/^image\/(png|jpe?g|webp|gif)$/.test(file.type)) {
    message.error('Choose a PNG, JPG, WebP or GIF image.');
    return false;
  }
  if (file.size > 15 * 1024 * 1024) {
    message.error('That image is over 15 MB.');
    return false;
  }
  const reader = new FileReader();
  reader.onload = () => {
    const img = new Image();
    img.onload = () => {
      source.value = reader.result;
      natW.value = img.naturalWidth;
      natH.value = img.naturalHeight;
      aspect.value = 'free';
      wholeImage();
      open.value = true;
    };
    img.onerror = () => message.error('That file could not be read as an image.');
    img.src = reader.result;
  };
  reader.readAsDataURL(file);
  return false; // never auto-upload: the form sends the result
}

function wholeImage() {
  aspect.value = 'free';
  Object.assign(crop, { x: 0, y: 0, w: natW.value, h: natH.value });
}

// A shape chosen: the largest centered box of that shape.
function resetCrop() {
  if (aspect.value === 'free') return wholeImage();
  const a = aspect.value;
  let w = natW.value;
  let h = w / a;
  if (h > natH.value) { h = natH.value; w = h * a; }
  Object.assign(crop, { x: (natW.value - w) / 2, y: (natH.value - h) / 2, w, h });
}

/* ---- drag (move) and the four corner handles (resize) ---- */
let drag = null;
function start(e, mode) {
  drag = { mode, px: e.clientX, py: e.clientY, r: { ...crop } };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', stop);
}
function stop() {
  drag = null;
  window.removeEventListener('pointermove', move);
  window.removeEventListener('pointerup', stop);
}
onBeforeUnmount(stop);

const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
function move(e) {
  if (!drag) return;
  const dx = (e.clientX - drag.px) / scale.value;
  const dy = (e.clientY - drag.py) / scale.value;
  const r = drag.r;
  const W = natW.value;
  const H = natH.value;
  if (drag.mode === 'move') {
    crop.x = clamp(r.x + dx, 0, W - r.w);
    crop.y = clamp(r.y + dy, 0, H - r.h);
    return;
  }
  const min = Math.max(8, 16 / scale.value);
  const n = drag.mode.includes('n');
  const wSide = drag.mode.includes('w');
  let x1 = r.x; let y1 = r.y; let x2 = r.x + r.w; let y2 = r.y + r.h;
  if (wSide) x1 = clamp(r.x + dx, 0, x2 - min); else x2 = clamp(x2 + dx, x1 + min, W);
  if (n) y1 = clamp(r.y + dy, 0, y2 - min); else y2 = clamp(y2 + dy, y1 + min, H);
  if (aspect.value !== 'free') {
    // Keep the shape: height follows the width, anchored on the opposite corner.
    const a = aspect.value;
    let w = x2 - x1;
    let h = w / a;
    const room = n ? y2 : H - y1;
    if (h > room) { h = room; w = h * a; }
    if (wSide) x1 = x2 - w; else x2 = x1 + w;
    if (n) y1 = y2 - h; else y2 = y1 + h;
  }
  Object.assign(crop, { x: x1, y: y1, w: x2 - x1, h: y2 - y1 });
}

/* ---- result ---- */
function apply() {
  const img = new Image();
  img.onload = () => {
    const c = document.createElement('canvas');
    c.width = outW.value;
    c.height = outH.value;
    const ctx = c.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, crop.x, crop.y, crop.w, crop.h, 0, 0, c.width, c.height);
    let url = c.toDataURL('image/png');
    if (url.length > 5.2 * 1024 * 1024) url = c.toDataURL('image/webp', 0.9); // keep it under the 4 MB server limit
    emit('update:modelValue', url);
    outInfo.value = `${c.width} × ${c.height} px`;
    open.value = false;
  };
  img.src = source.value;
}

function clear() {
  emit('update:modelValue', '');
  source.value = '';
  outInfo.value = '';
}
</script>

<style scoped>
.lc { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.lc-preview {
  width: 160px; height: 80px; border: 1px dashed rgba(128, 128, 128, 0.4); border-radius: 8px;
  display: flex; align-items: center; justify-content: center; padding: 6px;
  background: repeating-conic-gradient(rgba(128, 128, 128, 0.12) 0% 25%, transparent 0% 50%) 50% / 16px 16px;
}
.lc-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.lc-side { flex: 1; min-width: 220px; }
.lc-hint { margin-top: 6px; font-size: 12px; }
.lc-muted { opacity: 0.6; }
.lc-tools { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.lc-label { margin-inline-end: 8px; font-size: 12.5px; opacity: 0.75; }
.lc-stage { position: relative; margin: 0 auto; max-width: 100%; user-select: none; touch-action: none; }
.lc-img { display: block; }
.lc-shade { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.5); }
.lc-box { position: absolute; cursor: move; outline: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.6); background-repeat: no-repeat; }
.lc-h { position: absolute; width: 14px; height: 14px; background: #fff; border: 1px solid #555; border-radius: 2px; }
.lc-h--nw { left: -7px; top: -7px; cursor: nwse-resize; }
.lc-h--ne { right: -7px; top: -7px; cursor: nesw-resize; }
.lc-h--sw { left: -7px; bottom: -7px; cursor: nesw-resize; }
.lc-h--se { right: -7px; bottom: -7px; cursor: nwse-resize; }
</style>
