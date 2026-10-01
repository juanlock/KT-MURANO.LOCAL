/**
 * Per-user UI preferences kept on the SERVER (users.ui_preferences), so a
 * choice follows the user to every PC/browser and survives logout or cleared
 * browser data -- unlike plain localStorage, which stays in one browser.
 * Loaded with get_user_auth (auth.user.ui_preferences); written with
 * PUT user_preferences {key, value} (value null = forget), debounced per key.
 */
import http from './http';
import { useAuthStore } from '../stores/auth';

const timers = {};

/** Saved value for `key`, or undefined when this user never saved one. */
export function getUserPref(key) {
  const prefs = useAuthStore().user?.ui_preferences;
  return prefs && Object.prototype.hasOwnProperty.call(prefs, key) ? prefs[key] : undefined;
}

/** Saves `value` for `key` (null removes it). Local state updates at once. */
export function setUserPref(key, value) {
  const auth = useAuthStore();
  if (!auth.user) return;
  const prefs = { ...(auth.user.ui_preferences || {}) };
  if (value === null || value === undefined) delete prefs[key];
  else prefs[key] = value;
  auth.user.ui_preferences = prefs;

  clearTimeout(timers[key]);
  timers[key] = setTimeout(() => {
    http.put('user_preferences', { key, value: value ?? null }).catch(() => { /* kept locally; retried on next change */ });
  }, 600);
}
