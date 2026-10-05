/**
 * A record's name in the user's language. Two sources:
 *  - a Spanish column next to it (`name_es`, `expense_group_es`...) -- the
 *    chart of accounts, expense groups / periods;
 *  - the translations table, the name itself being the key (Settings ->
 *    Translations) -- expense category names (no name_es column any more).
 * Falls back to the stored (English) value.
 */
import { useUiStore } from '../stores/ui';
import { dataTranslation } from '../i18n';

export function isSpanish() {
  try {
    return String(useUiStore().locale || '').toLowerCase().startsWith('es');
  } catch (e) {
    return false;
  }
}

/** row[field + '_es'] for Spanish users when set, else the translation of row[field], else row[field]. */
export function localName(row, field = 'name') {
  if (!row) return '';
  const value = row[field] ?? '';
  const es = row[`${field}_es`];
  if (isSpanish() && es) return es;
  return dataTranslation(value) || value;
}
