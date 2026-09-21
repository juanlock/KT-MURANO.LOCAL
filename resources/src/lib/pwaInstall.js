import { ref } from 'vue';

/**
 * PWA install prompt state, captured at module scope so the listener is armed
 * from app boot (main.js imports this file) — `beforeinstallprompt` can fire
 * before the topbar mounts, and an uncaptured event is gone for good.
 *
 * `canInstall` stays false when the app is already running installed
 * (standalone display mode) or when the browser never offers the prompt
 * (installed already, unsupported browser, PWA criteria not met), so the
 * topbar button simply doesn't render in those cases.
 */
export const canInstall = ref(false);

let deferredPrompt = null;

function isStandalone() {
  return (
    window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true // iOS Safari
  );
}

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  if (!isStandalone()) canInstall.value = true;
});

window.addEventListener('appinstalled', () => {
  deferredPrompt = null;
  canInstall.value = false;
});

export async function promptInstall() {
  if (!deferredPrompt) return;
  const promptEvent = deferredPrompt;
  // A prompt event is single-use: clear it now; if the user dismisses,
  // the browser fires beforeinstallprompt again when it's ready to re-offer.
  deferredPrompt = null;
  canInstall.value = false;
  promptEvent.prompt();
  try { await promptEvent.userChoice; } catch { /* dismissed */ }
}
