import { t } from '../i18n';

/**
 * Appends an explicit "All" option LAST to a table filter dropdown's option
 * list, rather than relying only on the less-discoverable allow-clear "x".
 * value: '' matches the existing "empty string means no filter" convention
 * already used by every list page's filterParams().
 */
export function withAllOption(options) {
    return [...options, { value: '', label: t('All') }];
}

/** Alphabetical by label (locale-aware, case-insensitive) -- apply BEFORE
 *  withAllOption() so the "All" sentinel stays pinned at the end. */
export function sortByLabel(options) {
    return [...options].sort((a, b) =>
        String(a.label).localeCompare(String(b.label), undefined, { sensitivity: 'base', numeric: true })
    );
}
