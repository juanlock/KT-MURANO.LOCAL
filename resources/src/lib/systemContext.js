import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useCompanyStore } from '../stores/company';

/**
 * "superadmin/AdminSystem" (user's rule, 2026-10-21): the SuperAdmin with
 * AdminSystem (999) picked in the company switcher. There, lists show every
 * company's records (the server's listCompanyId() is null), so pages add a
 * "Company" column; with another company picked only that company's rows come.
 */
export function useSystemContext() {
    const auth = useAuthStore();
    const companies = useCompanyStore();
    const isSystem = computed(() => auth.user?.type === 'SuperAdmin' && Number(companies.actingCompanyId) === 999);

    return { isSystem };
}
