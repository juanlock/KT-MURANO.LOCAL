/**
 * Email Office email types (office_emails.category; App\Models\OfficeEmail::CATEGORIES)
 * plus the company's own folders ("f{id}", email_office_folders -- created from
 * the rules' "Move to"). One list for the Type menu / filter, the email panel,
 * the rules and the Email conversation tabs (Customer / Supplier details).
 *
 * The exports stay plain-looking objects / arrays but are reactive: the
 * company's folders are added to them by loadEmailFolders(), and every screen
 * using them follows.
 */
import { reactive } from 'vue';
import { sortByLabel } from './filterOptions';
import http from './http';

const BUILT_IN = {
  archive: 'Archive',
  bill: 'Bill',
  claim: 'Claim',
  general: 'General',
  payment: 'Payment',
  question: 'Question',
  reminder: 'Reminder',
  request: 'Request',
  social: 'Social',
  spam: 'Spam',
  supplier: 'Supplier',
  work_order: 'Work order',
};

export const EMAIL_CATEGORY_LABEL = reactive({ ...BUILT_IN });

export const EMAIL_CATEGORY_COLOR = reactive({
  archive: 'default',
  social: 'magenta',
  spam: 'volcano',
  bill: 'orange',
  claim: 'red',
  general: 'default',
  payment: 'lime',
  question: 'geekblue',
  reminder: 'blue',
  request: 'gold',
  supplier: 'cyan',
  work_order: 'green',
});

export const EMAIL_CATEGORY_OPTIONS = reactive([]);

/** The company's own folders: [{key: 'f12', name, color}]. */
export const EMAIL_FOLDERS = reactive([]);

function rebuildOptions() {
  EMAIL_CATEGORY_OPTIONS.splice(0, EMAIL_CATEGORY_OPTIONS.length,
    ...sortByLabel(Object.entries(EMAIL_CATEGORY_LABEL).map(([value, label]) => ({ value, label }))));
}
rebuildOptions();

function addFolder(f) {
  EMAIL_CATEGORY_LABEL[f.key] = f.name;
  EMAIL_CATEGORY_COLOR[f.key] = f.color || 'purple';
  if (!EMAIL_FOLDERS.some(x => x.key === f.key)) EMAIL_FOLDERS.push(f);
}

let loaded = null;
/** Loads the acting company's folders into the lists (once; force = again, e.g. company switch). */
export function loadEmailFolders(force = false) {
  if (loaded && !force) return loaded;
  loaded = http.get('email-office/folders').then(d => {
    // Forget folders of another company (SuperAdmin switching company).
    EMAIL_FOLDERS.splice(0).forEach(f => { delete EMAIL_CATEGORY_LABEL[f.key]; delete EMAIL_CATEGORY_COLOR[f.key]; });
    (d.folders || []).forEach(addFolder);
    rebuildOptions();
  }).catch(() => { loaded = null; });
  return loaded;
}

/** Creates a folder (Email Office manage) and adds it to the lists; returns its key. */
export async function createEmailFolder(name) {
  const r = await http.post('email-office/folders', { name });
  addFolder({ key: r.key, name: r.name });
  rebuildOptions();
  return r.key;
}

/**
 * Email Office summary cards (EmailOfficeList), for Defaults -> Email Office
 * (show / hide per company). The company's own folders are cards too
 * (EMAIL_FOLDERS, key "f12" under Received).
 */
export const EMAIL_OFFICE_CARDS = {
  in: [
    { key: 'all', label: 'Received (all emails)' }, { key: 'unlinked', label: 'Bills to record' }, { key: 'bill', label: 'Bills' },
    { key: 'claim', label: 'Claims' }, { key: 'supplier', label: 'Supplier' }, { key: 'general', label: 'General' },
    { key: 'unread', label: 'Unread' }, { key: 'social', label: 'Social' }, { key: 'archive', label: 'Archive' }, { key: 'spam', label: 'Spam' },
  ],
  out: [
    { key: 'all', label: 'Sent (all emails)' }, { key: 'reminder', label: 'Reminders sent' }, { key: 'bill', label: 'Bills' },
    { key: 'claim', label: 'Claims' }, { key: 'general', label: 'General' }, { key: 'archive', label: 'Archive' },
  ],
};

/** Folders (out of the normal list): Social / Archive / Spam and the company's own. */
export const isEmailFolder = c => ['social', 'archive', 'spam'].includes(c) || /^f\d+$/.test(String(c || ''));

export const emailCategoryLabel = c => EMAIL_CATEGORY_LABEL[c] || c;
