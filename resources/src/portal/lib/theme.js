/**
 * Theme, accent, radius and sidebar state — the portal theme customizer.
 *
 * Shared localStorage keys (rst-theme, rst-accent,
 * rst-radius, rst-sidenav) so the pre-paint boot script in portal.blade.php
 * and this module agree. Values are applied to <html> as follows:
 * data-bs-theme + color-scheme, inline --accent / --accent-rgb, inline
 * --rst-radius / --rst-radius-sm, and the rst-sidenav-collapsed class.
 */
import { reactive } from 'vue';

export const THEME_KEY = 'rst-theme';
export const ACCENT_KEY = 'rst-accent';
export const RADIUS_KEY = 'rst-radius';
export const SIDENAV_KEY = 'rst-sidenav';

export const DEFAULT_THEME = 'dark';
export const DEFAULT_ACCENT = '#f97316';

export const RADII = {
  sharp: ['0.25rem', '0.125rem'],
  regular: ['0.75rem', '0.375rem'],
  round: ['1.25rem', '0.75rem'],
};

export const SWATCHES = [
  ['#f97316', 'Orange'], ['#ef4444', 'Red'], ['#ec4899', 'Pink'],
  ['#8b5cf6', 'Purple'], ['#6366f1', 'Indigo'], ['#3b82f6', 'Blue'],
  ['#06b6d4', 'Cyan'], ['#14b8a6', 'Teal'], ['#22c55e', 'Green'],
  ['#84cc16', 'Lime'], ['#f59e0b', 'Amber'], ['#64748b', 'Slate'],
];

const root = () => document.documentElement;

function read(key) {
  try { return window.localStorage.getItem(key); } catch (e) { return null; }
}
function write(key, value) {
  try {
    if (value === null) window.localStorage.removeItem(key); else window.localStorage.setItem(key, value);
  } catch (e) { /* storage unavailable */ }
}

export function hexToRgb(hex) {
  const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
  if (!m) return null;
  const n = parseInt(m[1], 16);
  return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`;
}

/** Reactive view of the persisted choices (what the customizer edits). */
export const themeState = reactive({
  choice: DEFAULT_THEME,   // 'light' | 'dark' | 'system'
  resolved: DEFAULT_THEME, // 'light' | 'dark'
  accent: DEFAULT_ACCENT,
  radius: 'regular',
  collapsed: false,        // desktop sidebar off-canvas
});

function resolve(choice) {
  if (choice === 'light' || choice === 'dark') return choice;
  if (choice === 'system') return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  return DEFAULT_THEME;
}

/** Apply a resolved theme ('light' or 'dark') — never 'system'. */
function paint(theme) {
  root().setAttribute('data-bs-theme', theme);
  root().style.colorScheme = theme;
  themeState.resolved = theme;
}

export function setThemeChoice(choice, persist = true) {
  themeState.choice = choice;
  if (persist) write(THEME_KEY, choice);
  paint(resolve(choice));
}

export function toggleTheme() {
  const next = themeState.resolved === 'dark' ? 'light' : 'dark';
  setThemeChoice(next);
}

export function setAccent(hex, persist = true) {
  const rgb = hexToRgb(hex);
  if (!rgb) return;
  root().style.setProperty('--accent', hex);
  root().style.setProperty('--accent-rgb', rgb);
  themeState.accent = hex;
  if (persist) write(ACCENT_KEY, hex);
}

export function setRadius(choice, persist = true) {
  const steps = RADII[choice] || RADII.regular;
  root().style.setProperty('--rst-radius', steps[0]);
  root().style.setProperty('--rst-radius-sm', steps[1]);
  themeState.radius = RADII[choice] ? choice : 'regular';
  if (persist) write(RADIUS_KEY, themeState.radius);
}

export function setSidenavCollapsed(collapsed) {
  themeState.collapsed = !!collapsed;
  root().classList.toggle('rst-sidenav-collapsed', themeState.collapsed);
  write(SIDENAV_KEY, themeState.collapsed ? 'collapsed' : 'expanded');
}

export function resetTheme() {
  write(ACCENT_KEY, null);
  write(RADIUS_KEY, null);
  write(THEME_KEY, null);
  ['--accent', '--accent-rgb', '--rst-radius', '--rst-radius-sm'].forEach((p) => root().style.removeProperty(p));
  themeState.accent = DEFAULT_ACCENT;
  themeState.radius = 'regular';
  setThemeChoice(DEFAULT_THEME, false);
}

/** Read the persisted state (already painted pre-paint by the Blade boot script). */
export function initTheme() {
  const stored = read(THEME_KEY);
  themeState.choice = stored === 'light' || stored === 'dark' || stored === 'system' ? stored : DEFAULT_THEME;
  themeState.resolved = root().getAttribute('data-bs-theme') || resolve(themeState.choice);

  const accent = read(ACCENT_KEY);
  themeState.accent = accent && hexToRgb(accent) ? accent : DEFAULT_ACCENT;

  const radius = read(RADIUS_KEY);
  themeState.radius = RADII[radius] ? radius : 'regular';

  themeState.collapsed = read(SIDENAV_KEY) === 'collapsed';
  root().classList.toggle('rst-sidenav-collapsed', themeState.collapsed);

  // Track the OS while 'system' is the active choice.
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
    if (themeState.choice === 'system') paint(event.matches ? 'dark' : 'light');
  });
}
