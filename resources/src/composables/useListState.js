import { onBeforeRouteLeave, useRoute } from 'vue-router';

/**
 * Lists keep their state when the user comes BACK to them (2026-10-13):
 * Sales filtered by a customer -> open an invoice -> Back = the same filters,
 * page, page size, search and sort, not the defaults.
 *
 *   const crud = useCrudTable(...);
 *   const listState = useListState('sales', { filters, datePreset }, crud);
 *   if (listState.restored) crud.fetchRows(); else applyDefaults();
 *
 * - Saved (sessionStorage, per tab) every time the user leaves the list.
 * - Restored only when the list is reached with Back / Forward (the page's
 *   Back button = router.back(), or the browser's) -- opening the list from
 *   the menu or a link starts fresh, as before.
 * - `refs`: the page's own filter refs (plain JSON values: strings, numbers,
 *   arrays, objects -- dates as 'YYYY-MM-DD' strings). `crud` (optional):
 *   page, limit, search and sort of useCrudTable come back too.
 * Call it during setup, BEFORE the page's first fetch.
 */

// ---------------- Back / Forward detection (one listener per router) ----------------
let pendingPop = false;
let lastWasPop = false;
let installed = false;

/** Called once from router/index.js. */
export function installNavKind(router) {
  if (installed) return;
  installed = true;
  // History listeners fire only for popstate (Back / Forward / router.back()),
  // and before the router resolves that navigation.
  router.options.history.listen(() => { pendingPop = true; });
  router.beforeEach(() => {
    lastWasPop = pendingPop;
    pendingPop = false;
  });
}

/** True when the navigation being rendered came from Back / Forward. */
export const navigatedBack = () => lastWasPop;

const CRUD_KEYS = ['page', 'limit', 'search', 'sortField', 'sortType'];

export function useListState(key, refs = {}, crud = null) {
  const route = useRoute();
  const storeKey = `murano:list:${route.path}:${key}`;

  function snapshot() {
    const s = { refs: {}, crud: {} };
    for (const [k, r] of Object.entries(refs)) s.refs[k] = r.value;
    if (crud) for (const k of CRUD_KEYS) if (crud[k]) s.crud[k] = crud[k].value;
    return s;
  }

  onBeforeRouteLeave(() => {
    try { sessionStorage.setItem(storeKey, JSON.stringify(snapshot())); } catch (e) { /* storage blocked: lists just start fresh */ }
  });

  let restored = false;
  if (navigatedBack()) {
    try {
      const s = JSON.parse(sessionStorage.getItem(storeKey) || 'null');
      if (s) {
        for (const [k, r] of Object.entries(refs)) if (s.refs && k in s.refs) r.value = s.refs[k];
        if (crud && s.crud) for (const k of CRUD_KEYS) if (k in s.crud && crud[k]) crud[k].value = s.crud[k];
        restored = true;
      }
    } catch (e) { /* unreadable snapshot: defaults */ }
  }

  return { restored };
}
