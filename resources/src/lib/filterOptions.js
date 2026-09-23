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
