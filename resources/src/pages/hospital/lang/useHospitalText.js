import { useLocalText } from '../../../lib/useLocalText';
import COMMON from './common.es.js';

/**
 * Hospital Management texts in the active language (2026-10-21, same as the
 * School module's useSchoolText): s('English') -> Spanish when the app is in
 * Spanish. Each page passes its own map (lang/<Page>.es.js) on top of the
 * module's common words. Placeholders: s('{n} beds', { n: 3 }).
 */
export function useHospitalText(pageEs = {}) {
    return useLocalText({ ...COMMON, ...pageEs });
}
