import { t as tEs } from '../i18n/index.js';
/**
 * The user's default date preset -- ONE choice for every page with the
 * shared date buttons (lib/datePresets.js): Dashboard, Sales, Purchases, POS
 * Sales, Payments report, Bookings and every PresetRangeFilter page.
 * Double-click a date button anywhere to set it (★), again to clear.
 * Saved on the user's account (users.ui_preferences, via lib/userPrefs), so
 * it follows them to every PC. Pages fall back to their own default (Today,
 * All...) when the user has none.
 */
import { computed } from 'vue';
import { message } from 'ant-design-vue';
import { DATE_PRESETS } from './datePresets';
import { getUserPref, setUserPref } from './userPrefs';
import { t } from '../i18n';

export const DATE_DEFAULT_KEY = 'date_preset_default';
export const DATE_DEFAULT_HINT = 'Double-click a date button to make it your default on every page (again to clear)';

const isPreset = v => DATE_PRESETS.some(p => p.value === v);

/** The saved preset (reactive), or null. */
export const savedDatePreset = computed(() => {
  const v = getUserPref(DATE_DEFAULT_KEY);
  return isPreset(v) ? v : null;
});

/**
 * The preset a page should start on: the user's default when it's one the
 * page offers (`allowed`, e.g. no "all" where empty means something else),
 * else the page's own `fallback`.
 */
export function startPreset(fallback, allowed = null) {
  const v = savedDatePreset.value;
  return v && (!allowed || allowed.includes(v)) ? v : fallback;
}

/** Double-click handler: make `preset` the default, or clear it if it already is. */
export function toggleDatePreset(preset) {
  if (!isPreset(preset)) {
    message.info(tEs('tx_Pick_one_of_the_date_buttons_first_then_double_click_it_7ecf4', 'Pick one of the date buttons first, then double-click it.'));
    return;
  }
  const label = t(DATE_PRESETS.find(p => p.value === preset).labelKey);
  if (savedDatePreset.value === preset) {
    setUserPref(DATE_DEFAULT_KEY, null);
    message.info(`${label}: no longer your default date range`);
  } else {
    setUserPref(DATE_DEFAULT_KEY, preset);
    message.success(`${label} is now your default date range on every page`);
  }
}
