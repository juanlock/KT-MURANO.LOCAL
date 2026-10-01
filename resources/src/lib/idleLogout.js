/**
 * System Settings -> Defaults -> Users -> "Sign out after inactivity":
 * signs the user out after `minutes` without mouse / keyboard / touch /
 * scroll activity in ANY tab of the app (activity is shared between tabs via
 * localStorage). A minute before, a warning shows; any activity cancels it.
 * Skipped while the user has a POS cash register open -- the shift stays
 * signed in until the register is closed.
 */
import { notification } from 'ant-design-vue';
import http from './http';

const ACTIVITY_KEY = 'idle_last_activity';
const EVENTS = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'wheel'];
const WARN_KEY = 'idle-logout-warning';

export function startIdleLogout({ minutes, userId, onLogout }) {
  const limitMs = Number(minutes) * 60 * 1000;
  if (!limitMs || limitMs <= 0) return () => {};

  let last = Date.now();
  let warned = false;
  let lastWrite = 0;

  const touch = () => {
    last = Date.now();
    if (warned) { warned = false; notification.close(WARN_KEY); }
    // Shared with the other tabs, throttled to one write every 5 s.
    if (last - lastWrite > 5000) {
      lastWrite = last;
      try { window.localStorage.setItem(ACTIVITY_KEY, String(last)); } catch (e) { /* storage unavailable */ }
    }
  };
  const sharedLast = () => {
    let v = 0;
    try { v = Number(window.localStorage.getItem(ACTIVITY_KEY)) || 0; } catch (e) { v = 0; }
    return Math.max(last, v);
  };
  const registerOpen = async () => {
    try {
      const data = await http.get(`cash-registers/current/${userId}`);
      return !!(data && data.register && data.register.status === 'open');
    } catch (e) {
      return false; // no POS access / offline: nothing to protect
    }
  };

  let checking = false;
  const tick = async () => {
    if (checking) return;
    const idle = Date.now() - sharedLast();
    if (idle >= limitMs) {
      checking = true;
      const open = await registerOpen();
      checking = false;
      if (open) { touch(); return; } // POS register open: stay signed in
      clearInterval(timer);
      notification.close(WARN_KEY);
      onLogout();
    } else if (idle >= limitMs - 60000 && !warned) {
      warned = true;
      notification.warning({
        key: WARN_KEY,
        message: 'Still there?',
        description: 'You will be signed out in 1 minute for inactivity. Move the mouse or press a key to stay signed in.',
        duration: 0,
      });
    }
  };

  EVENTS.forEach(ev => window.addEventListener(ev, touch, { passive: true }));
  touch();
  const timer = setInterval(tick, 15000);

  return () => {
    clearInterval(timer);
    EVENTS.forEach(ev => window.removeEventListener(ev, touch));
    notification.close(WARN_KEY);
  };
}
