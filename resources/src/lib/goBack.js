/**
 * "Back" on a details page: return to the page the user came from (e.g. Sales
 * -> customer -> Back = Sales, with its filters), not always the module's
 * list. Opened directly (new tab, bookmark, reload with no history) -> the
 * fallback list. Templates use the global $goBack('/customers').
 */
export function goBack(router, fallback) {
  const cameFrom = window.history.state && window.history.state.back;
  if (cameFrom) router.back();
  else router.push(fallback || '/');
}
