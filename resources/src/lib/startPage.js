/**
 * Start page after login (System Settings -> Defaults -> Users):
 *  - company `start_page_mode` 'dashboard': the dashboard (minus the sections
 *    the company switched off);
 *  - 'custom': each user picks "Last used" (their 6 most used menu pages, as
 *    square shortcuts on the dashboard) or a form that opens right after login.
 * The user's choice and visit counts live on users.start_preferences
 * (UserController::updateStartPage / recordStartPageVisit).
 */
import { resolveTarget } from '../config/menu';
import { MENU_ITEM_ICONS } from '../config/menuItemIcons';
import { MENU_ICONS } from '../config/menuIcons';
import { isMenuEntryAllowed } from './menuVisibility';
import { useMenuStore } from '../stores/menu';
import { t } from '../i18n';
import http from './http';

// SPA path -> { path, entry, group, icon } for every menu item that opens a
// page here -- built from the acting company's OWN menu (stores/menu.js:
// Manage Menu's names, groups and hidden items), so "POS Launcher" under
// "POS Management" shows as such, not as the stock "Sales › POS".
// Rebuilt only when that menu changes.
let indexedMenu = null;
let PAGES_CACHE = new Map();
function pagesIndex() {
  const menu = useMenuStore().menu;
  if (menu === indexedMenu) return PAGES_CACHE;
  const map = new Map();
  (function index(nodes, groupIcon, group) {
    nodes.forEach(n => {
      if (n.hidden) return; // hidden in Manage Menu
      const icon = n.icon || groupIcon;
      if (Array.isArray(n.children)) return index(n.children, icon, group || n);
      const target = n.to && resolveTarget(n.to);
      if (!target || target.type !== 'next' || ['/dashboard', '/home-buttons'].includes(target.path) || map.has(target.path)) return;
      map.set(target.path, { path: target.path, entry: n, group, icon: MENU_ITEM_ICONS[n.to] || MENU_ICONS[n.icon] || MENU_ICONS[icon] || null });
    });
  })(menu || [], null, null);
  indexedMenu = menu;
  PAGES_CACHE = map;
  return map;
}
const PAGES = {
  has: p => pagesIndex().has(p),
  get: p => pagesIndex().get(p),
  values: () => pagesIndex().values(),
};

const entryLabel = e => (e.raw ? e.label : (e.labelKeys ? e.labelKeys.map(k => t(k)).join(' ') : t(e.label)));
export function pageLabel(page) {
  return entryLabel(page.entry);
}
/** "Products › Add" -- for pickers, where a bare "Add" would be ambiguous. */
export function pageFullLabel(page) {
  return page.group ? `${entryLabel(page.group)} › ${entryLabel(page.entry)}` : entryLabel(page.entry);
}

function allowedFor(auth) {
  return page => isMenuEntryAllowed(page.entry, {
    can: p => auth.can(p),
    actingCompanyId: null,
    isSuperAdmin: auth.user?.type === 'SuperAdmin',
  });
}

export function isMenuPage(path) {
  return PAGES.has(path);
}

/** Every menu page the user may open, for the "open after login" picker. */
export function pageOptions(auth) {
  return [...PAGES.values()].filter(allowedFor(auth));
}

/** Every menu page (for the admin picking the company's shortcut buttons). */
export function allPages() {
  return [...PAGES.values()];
}

/**
 * The 8 shortcut buttons: the admin's picks (Defaults -> Users -> Shortcut
 * buttons) in their slots; an empty slot -- or a pick this user's role can't
 * open -- shows the user's next most used form. Slots with neither are left out.
 */
export function shortcuts(auth, size = 8) {
  const ok = allowedFor(auth);
  const slots = effectiveSlots(auth, size);
  const picked = slots.map(p => (p && p !== '*' && PAGES.has(p) && ok(PAGES.get(p)) ? PAGES.get(p) : null));
  const taken = new Set(picked.filter(Boolean).map(p => p.path));
  const fill = lastUsed(auth, 50).filter(p => !taken.has(p.path));
  return picked.map(p => p || fill.shift() || null).filter(Boolean);
}

/**
 * What each of the 8 buttons is set to: the user's own choice (Home Buttons
 * page: '/path' = that form, '*' = my most used form) or, when they left it
 * on "company default" (null), the admin's pick (null there = most used).
 */
export function effectiveSlots(auth, size = 8) {
  const admin = auth.user?.start_page_shortcuts || [];
  const mine = auth.user?.start_preferences?.slots || [];
  return Array.from({ length: size }, (_, i) => (mine[i] != null ? mine[i] : (admin[i] || null)));
}

/** The user's most used menu pages (max `limit`), most used first. */
export function lastUsed(auth, limit = 6) {
  const recent = auth.user?.start_preferences?.recent || {};
  const ok = allowedFor(auth);
  return Object.entries(recent)
    .sort((a, b) => (b[1].c - a[1].c) || (b[1].t - a[1].t))
    .map(([path]) => PAGES.get(path))
    .filter(p => p && ok(p))
    .slice(0, limit);
}

/** Counts a visit (fire-and-forget; repeated hits on the same page are one). */
let lastSent = '';
export function recordVisit(auth, path) {
  if (!auth.user || auth.user.start_page_mode !== 'custom' || !PAGES.has(path) || path === lastSent) return;
  lastSent = path;
  const recent = (auth.user.start_preferences ||= { mode: 'recent', form: null, recent: {} }).recent ||= {};
  recent[path] = { c: ((recent[path] && recent[path].c) || 0) + 1, t: Math.floor(Date.now() / 1000) };
  http.post('user_start_page/visit', { path }).catch(() => {});
}

/** The form to open right after login, or null. */
export function autoOpenPath(auth) {
  const p = auth.user?.start_preferences;
  if (auth.user?.start_page_mode !== 'custom' || !p || p.mode !== 'form' || !p.form) return null;
  const page = PAGES.get(p.form);
  return page && allowedFor(auth)(page) ? page.path : null;
}

// One auto-open per sign-in: set once it happened, cleared on logout.
const OPENED_KEY = 'start_page_opened';
export function startPageOpened() {
  try { return window.sessionStorage.getItem(OPENED_KEY) === '1'; } catch (e) { return true; }
}
export function markStartPageOpened(on = true) {
  try {
    if (on) window.sessionStorage.setItem(OPENED_KEY, '1');
    else window.sessionStorage.removeItem(OPENED_KEY);
  } catch (e) { /* storage unavailable */ }
}
