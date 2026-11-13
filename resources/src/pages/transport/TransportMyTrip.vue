<template>
  <div class="page mt">
    <PageHeader :title="T('My trip', 'Mi viaje')" :breadcrumb="[T('Transport', 'Transporte'), T('My trip', 'Mi viaje')]" />

    <!-- A pilot never reports with the bus moving: the whole screen is covered while it moves (user's rule, 2026-11-06). -->
    <div v-if="blocked" class="mt-block">
      <CarOutlined class="mt-block-icon" />
      <div class="mt-block-title">{{ T('Vehicle moving', 'Vehículo en movimiento') }}</div>
      <div class="mt-block-text">{{ T('Stop the vehicle to report.', 'Detén el vehículo para reportar.') }}</div>
      <div v-if="speed !== null && speed <= MAX_SHOWN_KMH" class="mt-block-speed">{{ Math.round(speed) }} km/h</div>
      <div v-if="nextPoint" class="mt-block-next">{{ T('Next', 'Siguiente') }}: {{ nextPoint.name }}</div>
    </div>

    <!-- A developer's PC has no HTTPS, so no real location: this stands in for the phone's GPS. Never on a public address. -->
    <div v-if="simulated && trip && running" class="mt-sim">
      <div class="mt-sim-title">{{ T('GPS simulator (this PC only)', 'Simulador de GPS (solo en este PC)') }}<template v-if="speed !== null"> · {{ Math.round(speed) }} km/h</template></div>
      <a-space wrap :size="6">
        <a-button size="small" :type="simMoving ? 'default' : 'primary'" @click="simMoving = false">{{ T('Stopped', 'Detenido') }}</a-button>
        <a-button size="small" :type="simMoving ? 'primary' : 'default'" @click="simMoving = true">{{ T('Moving', 'En marcha') }}</a-button>
        <a-button v-if="nextPoint && nextPoint.lat !== null" size="small" @click="simGoToNext">{{ T('Go to', 'Ir a') }} {{ nextPoint.name }}</a-button>
      </a-space>
    </div>

    <a-spin :spinning="loading">
      <a-empty v-if="!trip && !loading" :description="T('You have no trips coming up.', 'No tienes viajes próximos.')" />

      <template v-if="trip">
        <a-alert v-if="gpsMessage" class="mt-gap" :type="gpsMessage.type" show-icon :message="gpsMessage.text" />

        <a-card size="small" class="mt-gap" :body-style="{ padding: '12px' }">
          <div class="mt-route">{{ trip.route }}</div>
          <div class="mt-muted">{{ trip.vehicle }}</div>
          <div class="mt-muted">{{ dayLabel(trip.departure_at) }} · {{ time(trip.departure_at) }}<template v-if="trip.arrival_at"> → {{ time(trip.arrival_at) }}</template></div>
          <div class="mt-muted">{{ trip.crew.map(c => c.name).join(', ') }}</div>
          <div class="mt-tags">
            <a-tag :color="STATUS[trip.status]?.color">{{ STATUS[trip.status] ? T(STATUS[trip.status].en, STATUS[trip.status].es) : trip.status }}</a-tag>
            <a-tag v-if="trip.tracking && fix" color="blue"><AimOutlined /> {{ T('Sharing the trip\'s location', 'Compartiendo la ubicación del viaje') }}</a-tag>
          </div>
        </a-card>

        <a-card v-if="pendingPhoto" size="small" class="mt-gap mt-warn" :body-style="{ padding: '12px' }">
          <div class="mt-action-title">{{ T('Arrival without a photo', 'Llegada sin foto') }}: {{ pendingPhoto.name }}</div>
          <a-button block size="large" type="primary" :disabled="!canReport" @click="openArrival(pendingPhoto, true)"><CameraOutlined /> {{ T('Take the photo', 'Tomar la foto') }}</a-button>
        </a-card>

        <a-card v-if="nextPoint && running" size="small" class="mt-gap" :body-style="{ padding: '12px' }">
          <div class="mt-action-title">{{ nextPoint.name }}<span v-if="nextPoint.label" class="mt-muted"> ({{ nextPoint.label }})</span></div>
          <div class="mt-muted">{{ T('Planned', 'Previsto') }}: {{ time(trip.next.action === 'arrive' ? nextPoint.plan_arrive : nextPoint.plan_depart) }}</div>
          <div v-if="waitReason" class="mt-wait">{{ waitReason }}</div>
          <template v-if="!trip.tracking && trip.status === 'scheduled'">
            <div class="mt-wait">{{ T('It opens 30 minutes before leaving.', 'Se habilita 30 minutos antes de la salida.') }}</div>
          </template>
          <template v-else>
            <a-button v-if="trip.next.action === 'arrive'" block size="large" type="primary" class="mt-big" :disabled="!canReport" @click="openArrival(nextPoint, false)"><CameraOutlined /> {{ T('I arrived', 'Llegué') }}</a-button>
            <a-button v-else block size="large" type="primary" class="mt-big" :disabled="!canReport" :loading="saving" @click="depart"><SendOutlined /> {{ T('I left', 'Salí') }}</a-button>
            <a-button v-if="trip.next.action === 'arrive' && !nextPoint.last" block type="link" :disabled="!canReport" @click="skip">{{ T('The bus does not call here', 'El bus no para aquí') }}</a-button>
          </template>
        </a-card>

        <!-- Pilot swap on a long trip (2026-11-13): when and to whom; "Swap done" with the bus stopped. -->
        <a-card v-if="trip.swap" size="small" class="mt-gap" :class="{ 'mt-warn': ['due', 'late'].includes(trip.swap.state) }" :body-style="{ padding: '12px' }">
          <div class="mt-action-title">{{ T('Pilot swap', 'Cambio de piloto') }} · {{ time(trip.swap.due_at) }}</div>
          <div class="mt-muted">{{ trip.swap.from }} → {{ trip.swap.to }}</div>
          <div v-if="trip.swap.state === 'done'" class="mt-ok">✓ {{ T('Swap done at', 'Cambio hecho a las') }} {{ time(trip.swap.done_at) }}</div>
          <div v-else-if="trip.swap.state === 'late'" class="mt-wait">{{ T('The swap is late: stop and hand over the wheel.', 'El cambio está atrasado: detente y entrega el volante.') }}</div>
          <div v-else-if="trip.swap.state === 'missed'" class="mt-wait">{{ T('The trip ended without the swap.', 'El viaje terminó sin el cambio.') }}</div>
          <template v-if="running && ['due', 'late'].includes(trip.swap.state)">
            <div v-if="waitReason" class="mt-wait">{{ waitReason }}</div>
            <a-button block size="large" type="primary" class="mt-big" :disabled="!canReport" :loading="saving" @click="swapDone"><SwapOutlined /> {{ T('Swap done', 'Cambio hecho') }}</a-button>
          </template>
        </a-card>

        <a-button v-if="running" block size="large" danger class="mt-gap" :disabled="!canReport" @click="openIncident"><WarningOutlined /> {{ T('Report an incident', 'Reportar una incidencia') }}</a-button>

        <a-card size="small" class="mt-gap" :title="T('Control points', 'Puntos de control')" :body-style="{ padding: '12px' }">
          <a-timeline class="mt-line">
            <a-timeline-item v-for="p in trip.points" :key="p.location_id" :color="pointColor(p)">
              <div class="mt-point"><b>{{ p.name }}</b><a-tag v-if="p.skipped" class="mt-small">{{ T('Did not call', 'No paró') }}</a-tag></div>
              <div v-if="p.plan_arrive" class="mt-muted">{{ T('Arrival', 'Llegada') }}: {{ time(p.plan_arrive) }}<template v-if="p.arrived_at"> → <b>{{ time(p.arrived_at) }}</b> {{ delayText(p.arrive_delay_min) }}<CameraOutlined v-if="p.has_photo" class="mt-cam" /></template></div>
              <div v-if="p.plan_depart" class="mt-muted">{{ T('Departure', 'Salida') }}: {{ time(p.plan_depart) }}<template v-if="p.departed_at"> → <b>{{ time(p.departed_at) }}</b> {{ delayText(p.depart_delay_min) }}</template></div>
            </a-timeline-item>
          </a-timeline>
        </a-card>

        <a-card v-if="otherTrips.length" size="small" class="mt-gap" :title="T('Next trips', 'Próximos viajes')" :body-style="{ padding: '12px' }">
          <div v-for="o in otherTrips" :key="o.id" class="mt-other">
            <b>{{ time(o.departure_at) }}</b> · {{ dayLabel(o.departure_at) }}<div class="mt-muted">{{ o.route }}<template v-if="o.vehicle"> · {{ o.vehicle }}</template></div>
          </div>
        </a-card>
      </template>
    </a-spin>

    <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="mt-hidden" @change="onCamera">

    <a-modal v-model:open="arrivalOpen" :title="`${T('Arrival', 'Llegada')}: ${arrival.point?.name || ''}`" :footer="null" destroy-on-close>
      <a-button block size="large" class="mt-big" @click="cameraInput.click()"><CameraOutlined /> {{ photo.url ? T('Take it again', 'Tomarla de nuevo') : T('Take the photo', 'Tomar la foto') }}</a-button>
      <img v-if="photo.url" :src="photo.url" class="mt-preview" alt="">
      <a-textarea v-if="!arrival.photoOnly" v-model:value="arrival.note" class="mt-gap" :rows="2" :maxlength="500" :placeholder="T('Note (optional)', 'Nota (opcional)')" />
      <a-button block size="large" type="primary" class="mt-big mt-gap" :disabled="!photo.file" :loading="saving" @click="sendArrival">{{ T('Send the arrival report', 'Enviar el reporte de llegada') }}</a-button>
    </a-modal>

    <a-modal v-model:open="incidentOpen" :title="T('Report an incident', 'Reportar una incidencia')" :footer="null" destroy-on-close>
      <a-form layout="vertical">
        <a-form-item :label="T('What happened', 'Qué pasó')" required><a-select v-model:value="incident.kind" :options="kindOptions" /></a-form-item>
        <a-form-item :label="T('Details', 'Detalle')"><a-textarea v-model:value="incident.note" :rows="3" :maxlength="1000" /></a-form-item>
      </a-form>
      <a-button block @click="cameraInput.click()"><CameraOutlined /> {{ T('Add a photo (optional)', 'Agregar una foto (opcional)') }}</a-button>
      <img v-if="photo.url" :src="photo.url" class="mt-preview" alt="">
      <a-button block size="large" type="primary" class="mt-big mt-gap" :disabled="!incident.kind" :loading="saving" @click="sendIncident">{{ T('Send', 'Enviar') }}</a-button>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Transport -> My trip (2026-11-06), made for the crew's phone: the trip's control points, "I arrived" with its photo (required),
 * "I left", incidents. While it's open it follows the bus with the phone's GPS and keeps the screen on; with the screen
 * locked or in another app the browser stops sending. A pilot's screen is covered while the bus moves, and the server checks
 * again (TransportControl::drivingBlock). Needs HTTPS: the browser gives no location on http://.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { AimOutlined, CameraOutlined, CarOutlined, SendOutlined, SwapOutlined, WarningOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';
import { sortByLabel } from '../../lib/filterOptions';

const ui = useUiStore();
const route = useRoute();
const es = computed(() => ui.locale === 'es');
const T = (en, sp) => (es.value ? sp : en);
const STATUS = {
  scheduled: { color: 'gold', en: 'Scheduled', es: 'Programado' },
  in_progress: { color: 'orange', en: 'On the road', es: 'En ruta' },
  completed: { color: 'blue', en: 'Completed', es: 'Completado' },
  cancelled: { color: 'default', en: 'Cancelled', es: 'Cancelado' },
};
// What the server answers, in the screen's language.
const SERVER_TEXTS = {
  'Stop the vehicle to report.': 'Detén el vehículo para reportar.',
  'Turn on the location to report.': 'Activa la ubicación para reportar.',
  'Wait a few seconds: checking that the vehicle is stopped.': 'Espera unos segundos: comprobando que el vehículo está detenido.',
  'That is not the next control point: refresh the page.': 'Ese no es el siguiente punto de control: actualiza la página.',
  'That control point can not be skipped: refresh the page.': 'Ese punto de control no se puede omitir: actualiza la página.',
  'This trip is not running.': 'Este viaje no está en curso.',
  'The arrival needs its photo.': 'La llegada necesita su foto.',
  'That file is not a picture.': 'Ese archivo no es una imagen.',
  'That arrival already has its photo.': 'Esa llegada ya tiene su foto.',
  'Take the photo.': 'Toma la foto.',
  'This trip is not yours.': 'Este viaje no es tuyo.',
};
const say = text => (es.value && SERVER_TEXTS[text]) || text;
const errorOf = e => { const errs = e?.data?.errors; return say((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message); };

const time = d => (d ? dayjs(d).format('HH:mm') : '');
const dayLabel = d => (d ? new Date(String(d).replace(' ', 'T')).toLocaleDateString(es.value ? 'es-PE' : 'en-US', { weekday: 'short', day: 'numeric', month: 'short' }) : '');
const delayText = m => (m === null || m === undefined ? '' : m > 0 ? `(+${m} min)` : m < 0 ? `(${m} min)` : '');

const trip = ref(null);
const otherTrips = ref([]);
const loading = ref(false);
const saving = ref(false);

const running = computed(() => trip.value && ['scheduled', 'in_progress'].includes(trip.value.status));
const nextPoint = computed(() => (trip.value?.next ? trip.value.points[trip.value.next.index] : null));
const pendingPhoto = computed(() => trip.value?.points.find(p => p.arrived_at && !p.has_photo) || null);
const pointColor = p => (p.skipped ? 'gray' : p.departed_at || (p.last && p.arrived_at) ? 'green' : p.arrived_at ? 'blue' : 'gray');

async function load(silent = false) {
  if (!silent) loading.value = true;
  try {
    const res = await http.get('transport/control/mine', route.query.trip ? { trip: route.query.trip } : {});
    trip.value = res.trip;
    otherTrips.value = res.trips;
  } catch (e) {
    if (!silent) message.error(errorOf(e));
  } finally {
    loading.value = false;
  }
}

// ------------------------------------------------------------------ the phone's position
const STOPPED_SECONDS = 30;
const MAX_ACCURACY_M = 200;
const SEND_EVERY_MS = 45000;
// A jump of the GPS can read as an impossible speed: it still counts as moving, but isn't shown or stored as it is.
const MAX_SHOWN_KMH = 200;

const fix = ref(null);
const speed = ref(null);
const gpsError = ref('');
const stoppedSince = ref(null);
const tick = ref(Date.now());
let watchId = null;
let lastSample = null;
let sendTimer = null;
let tickTimer = null;
let refreshTimer = null;
let wakeLock = null;

const metres = (a, b) => {
  const rad = Math.PI / 180;
  const h = Math.sin(((b.lat - a.lat) * rad) / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(((b.lng - a.lng) * rad) / 2) ** 2;
  return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
};

function onPosition(pos) {
  const c = pos.coords;
  const sample = { lat: c.latitude, lng: c.longitude, at: pos.timestamp };
  gpsError.value = '';
  // Some phones give no speed: then it comes from the distance between two positions, past what GPS wanders by itself.
  let kmh = c.speed !== null && c.speed !== undefined && c.speed >= 0 ? c.speed * 3.6 : null;
  if (kmh === null) {
    if (lastSample && sample.at - lastSample.at >= 5000) {
      kmh = (Math.max(0, metres(lastSample, sample) - Math.min(100, c.accuracy || 30)) / ((sample.at - lastSample.at) / 1000)) * 3.6;
      lastSample = sample;
    } else if (!lastSample) {
      lastSample = sample;
    }
  } else {
    lastSample = sample;
  }
  if (kmh !== null) speed.value = kmh;
  const first = !fix.value;
  fix.value = { lat: c.latitude, lng: c.longitude, accuracy: c.accuracy, speed: speed.value };
  const stop = trip.value?.stop_kmh ?? 5;
  if (speed.value === null || speed.value > stop || c.accuracy > MAX_ACCURACY_M) stoppedSince.value = null;
  else if (!stoppedSince.value) stoppedSince.value = Date.now();
  if (first) sendPosition();
}

function onGpsError(e) {
  gpsError.value = e.code === 1 ? 'denied' : 'unavailable';
  fix.value = null;
  stoppedSince.value = null;
}

// ------------------------------------------------------------------ a developer's PC: simulated GPS
const simulated = /(\.local|\.test)$|^127\.|^localhost$/.test(window.location.hostname);
const simMoving = ref(false);
const simAt = ref(null);
let simTimer = null;
function simFeed() {
  if (!simAt.value) {
    const first = trip.value?.points.find(p => p.lat !== null);
    simAt.value = first ? { lat: first.lat, lng: first.lng } : { lat: -12.0464, lng: -77.0428 };
  }
  if (simMoving.value) simAt.value = { lat: simAt.value.lat + 0.0022, lng: simAt.value.lng - 0.0004 };
  onPosition({ coords: { latitude: simAt.value.lat, longitude: simAt.value.lng, accuracy: 10, speed: simMoving.value ? 80 / 3.6 : 0 }, timestamp: Date.now() });
}
function simGoToNext() {
  simAt.value = { lat: nextPoint.value.lat, lng: nextPoint.value.lng };
  simMoving.value = false;
  simFeed();
}

function startGps() {
  if (simulated) {
    if (!simTimer) {
      simFeed();
      simTimer = setInterval(simFeed, 3000);
    }
    return;
  }
  if (watchId !== null) return;
  if (!window.isSecureContext || !navigator.geolocation) {
    gpsError.value = 'unsupported';
    return;
  }
  watchId = navigator.geolocation.watchPosition(onPosition, onGpsError, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
  keepScreenOn();
}

function stopGps() {
  clearInterval(simTimer);
  simTimer = null;
  if (watchId !== null) navigator.geolocation.clearWatch(watchId);
  watchId = null;
  releaseScreen();
}

async function keepScreenOn() {
  try {
    if ('wakeLock' in navigator && document.visibilityState === 'visible' && !wakeLock) {
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => { wakeLock = null; });
    }
  } catch (e) { /* low battery or not allowed: the page still works */ }
}
function releaseScreen() {
  if (wakeLock) wakeLock.release().catch(() => {});
  wakeLock = null;
}
function onVisible() {
  if (document.visibilityState === 'visible') {
    if (watchId !== null) keepScreenOn();
    load(true);
    sendPosition();
  }
}

async function sendPosition() {
  if (!trip.value?.tracking || !fix.value || fix.value.accuracy > MAX_ACCURACY_M) return;
  try {
    const res = await http.post(`transport/control/trips/${trip.value.id}/position`, fixBody());
    if (res.tracking === false) trip.value.tracking = false;
    if (res.auto && res.trip) {
      trip.value = res.trip;
      message.info(res.auto === 'arrive' ? T('Arrival detected by GPS: take the photo.', 'Llegada detectada por GPS: toma la foto.') : T('Departure detected by GPS.', 'Salida detectada por GPS.'), 6);
    }
  } catch (e) { /* no signal right now: the next one goes in 45 s */ }
}

const fixBody = () => (fix.value ? { lat: fix.value.lat, lng: fix.value.lng, accuracy: fix.value.accuracy, speed: fix.value.speed === null ? null : Math.min(fix.value.speed, MAX_SHOWN_KMH) } : {});

// ------------------------------------------------------------------ may this person report now?
const mustStop = computed(() => !!trip.value?.must_stop && trip.value.status !== 'completed');
const moving = computed(() => speed.value !== null && speed.value > (trip.value?.stop_kmh ?? 5));
const stoppedFor = computed(() => (stoppedSince.value ? Math.floor((tick.value - stoppedSince.value) / 1000) : 0));
const blocked = computed(() => mustStop.value && !!running.value && moving.value);
const canReport = computed(() => !mustStop.value || (!!fix.value && !moving.value && stoppedFor.value >= STOPPED_SECONDS));
// The server compares with the bus's earlier position: it needs one from after it stopped, not only the regular one of 45 s.
watch(stoppedFor, s => { if (mustStop.value && s > 0 && s <= STOPPED_SECONDS && s % 10 === 0) sendPosition(); });
const waitReason = computed(() => {
  if (!mustStop.value || canReport.value) return '';
  if (!fix.value) return T('Waiting for the phone\'s location...', 'Esperando la ubicación del celular...');
  return T(`Checking that the vehicle is stopped (${Math.max(0, STOPPED_SECONDS - stoppedFor.value)} s)`, `Comprobando que el vehículo está detenido (${Math.max(0, STOPPED_SECONDS - stoppedFor.value)} s)`);
});
const gpsMessage = computed(() => {
  if (!trip.value || !running.value) return null;
  if (gpsError.value === 'denied') return { type: 'error', text: T('Location is blocked: allow it for this site in the browser, then reopen this page.', 'La ubicación está bloqueada: permítela para este sitio en el navegador y vuelve a abrir esta página.') };
  if (gpsError.value === 'unsupported') return { type: 'warning', text: T('This browser gives no location on this address.', 'Este navegador no da la ubicación en esta dirección.') };
  if (gpsError.value === 'unavailable') return { type: 'warning', text: T('No GPS signal right now.', 'Sin señal GPS en este momento.') };
  return fix.value && trip.value.tracking ? { type: 'info', text: T('Keep this page open: with the screen locked the location stops.', 'Mantén esta página abierta: con la pantalla bloqueada la ubicación se detiene.') } : null;
});

// ------------------------------------------------------------------ photos
const cameraInput = ref(null);
const photo = ref({ file: null, url: '' });
function clearPhoto() {
  if (photo.value.url) URL.revokeObjectURL(photo.value.url);
  photo.value = { file: null, url: '' };
}
/** A camera photo can weigh 10 MB and the road's signal is weak: it goes as a JPEG of at most 1600 px. */
function shrink(file) {
  return new Promise(resolve => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.naturalWidth * k);
      canvas.height = Math.round(img.naturalHeight * k);
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      URL.revokeObjectURL(url);
      canvas.toBlob(b => resolve(b ? new File([b], 'photo.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.85);
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
    img.src = url;
  });
}
async function onCamera(ev) {
  const file = ev.target.files?.[0];
  ev.target.value = '';
  if (!file) return;
  const small = await shrink(file);
  clearPhoto();
  photo.value = { file: small, url: URL.createObjectURL(small) };
}
function formOf(extra) {
  const fd = new FormData();
  Object.entries({ ...fixBody(), ...extra }).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') fd.append(k, v); });
  if (photo.value.file) fd.append('photo', photo.value.file, 'photo.jpg');
  return fd;
}

// ------------------------------------------------------------------ reports
const arrivalOpen = ref(false);
const arrival = ref({ point: null, photoOnly: false, note: '' });
function openArrival(point, photoOnly) {
  clearPhoto();
  arrival.value = { point, photoOnly, note: '' };
  arrivalOpen.value = true;
}
async function sendArrival() {
  const a = arrival.value;
  saving.value = true;
  try {
    const res = await http.postForm(`transport/control/trips/${trip.value.id}/${a.photoOnly ? 'photo' : 'arrive'}`, formOf({ location_id: a.point.location_id, note: a.note }));
    trip.value = res.trip;
    arrivalOpen.value = false;
    clearPhoto();
    message.success(T('Arrival reported.', 'Llegada reportada.'));
  } catch (e) {
    message.error(errorOf(e)); // the photo stays, to try again
  } finally {
    saving.value = false;
  }
}
async function act(action, ok) {
  saving.value = true;
  try {
    const res = await http.post(`transport/control/trips/${trip.value.id}/${action}`, { location_id: nextPoint.value.location_id, ...fixBody() });
    trip.value = res.trip;
    message.success(ok);
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}
const depart = () => act('depart', T('Departure reported.', 'Salida reportada.'));
async function swapDone() {
  saving.value = true;
  try {
    const res = await http.post(`transport/control/trips/${trip.value.id}/swap`, fixBody());
    trip.value = res.trip;
    message.success(T('Swap reported.', 'Cambio reportado.'));
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}
const skip = () => act('skip', T('Saved.', 'Guardado.'));

const incidentOpen = ref(false);
const incident = ref({ kind: undefined, note: '' });
const kindOptions = computed(() => sortByLabel([
  { value: 'traffic', label: T('Traffic', 'Tráfico') },
  { value: 'breakdown', label: T('Breakdown', 'Avería') },
  { value: 'accident', label: T('Accident', 'Accidente') },
  { value: 'road', label: T('Road closed or blocked', 'Vía cerrada o bloqueada') },
  { value: 'passenger', label: T('Passenger', 'Pasajero') },
  { value: 'other', label: T('Other', 'Otro') },
]));
function openIncident() {
  clearPhoto();
  incident.value = { kind: undefined, note: '' };
  incidentOpen.value = true;
}
async function sendIncident() {
  saving.value = true;
  try {
    const res = await http.postForm(`transport/control/trips/${trip.value.id}/report`, formOf({ kind: incident.value.kind, note: incident.value.note }));
    trip.value = res.trip;
    incidentOpen.value = false;
    clearPhoto();
    message.success(T('Incident reported.', 'Incidencia reportada.'));
  } catch (e) {
    message.error(errorOf(e));
  } finally {
    saving.value = false;
  }
}

// The location is used only while a trip is running or about to.
watch(() => !!trip.value && !!running.value, on => { if (on) startGps(); else stopGps(); });

onMounted(async () => {
  await load();
  if (trip.value && running.value) startGps();
  tickTimer = setInterval(() => { tick.value = Date.now(); }, 1000);
  sendTimer = setInterval(sendPosition, SEND_EVERY_MS);
  refreshTimer = setInterval(() => { if (!arrivalOpen.value && !incidentOpen.value) load(true); }, 120000);
  document.addEventListener('visibilitychange', onVisible);
});
onBeforeUnmount(() => {
  stopGps();
  clearInterval(tickTimer);
  clearInterval(sendTimer);
  clearInterval(refreshTimer);
  document.removeEventListener('visibilitychange', onVisible);
  clearPhoto();
});
</script>

<style scoped>
.mt { max-width: 620px; margin: 0 auto; }
.mt-gap { margin-top: 10px; }
.mt-route { font-size: 17px; font-weight: 700; }
.mt-muted { font-size: 13px; color: #64748b; }
.mt-tags { margin-top: 6px; }
.mt-warn { border-color: #f59e0b; background: #fffbeb; }
.mt-action-title { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.mt-wait { margin: 8px 0; font-size: 13px; color: #b45309; }
.mt-ok { margin-top: 6px; font-size: 13px; color: #15803d; font-weight: 600; }
.mt-big { height: 54px; font-size: 17px; font-weight: 600; margin-top: 8px; }
.mt-line { margin-top: 6px; }
.mt-line :deep(.ant-timeline-item:last-child) { padding-bottom: 0; }
.mt-small { margin-left: 6px; }
.mt-cam { margin-left: 6px; color: #2563eb; }
.mt-other { padding: 6px 0; border-top: 1px solid #f1f5f9; }
.mt-other:first-child { border-top: 0; }
.mt-hidden { display: none; }
.mt-preview { display: block; width: 100%; max-height: 300px; object-fit: contain; margin-top: 10px; border-radius: 8px; background: #f1f5f9; }
.mt-sim { position: fixed; left: 8px; right: 8px; bottom: 8px; z-index: 2100; max-width: 460px; margin: 0 auto; padding: 8px 10px; border: 1px dashed #7c3aed; border-radius: 8px; background: #f5f3ff; }
.mt-sim-title { font-size: 12px; font-weight: 600; color: #5b21b6; margin-bottom: 6px; }
.mt-block { position: fixed; inset: 0; z-index: 2000; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; padding: 24px; text-align: center; background: #0f172a; color: #fff; }
.mt-block-icon { font-size: 64px; color: #f59e0b; }
.mt-block-title { font-size: 26px; font-weight: 700; }
.mt-block-text { font-size: 18px; }
.mt-block-speed { font-size: 44px; font-weight: 700; }
.mt-block-next { font-size: 15px; color: #cbd5e1; }
</style>
