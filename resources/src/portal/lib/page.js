/**
 * Page chrome shared between the layout and the current view.
 *
 * The portal shell draws the page header (pretitle, title, breadcrumb trail)
 * above the page body; here the layout owns that markup and each view
 * declares what to show through `setPage()`. Page actions are teleported
 * into the header by the view (see the `#portal-page-actions` target).
 */
import { reactive } from 'vue';

export const page = reactive({
  title: '',
  pretitle: '',
  crumbs: [], // [{ label, to }]
});

export function setPage({ title = '', pretitle = '', crumbs = [] } = {}) {
  page.title = title;
  page.pretitle = pretitle;
  page.crumbs = crumbs;
}
