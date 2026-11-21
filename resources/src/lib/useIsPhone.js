import { ref, onUnmounted, getCurrentInstance } from 'vue';

/** true on a phone (width under 768 px, the same cut as DataTable), kept current when the window changes. */
export function useIsPhone() {
    const mq = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 767px)') : null;
    const isPhone = ref(!!mq?.matches);
    const onChange = e => { isPhone.value = e.matches; };
    mq?.addEventListener?.('change', onChange);
    if (getCurrentInstance()) onUnmounted(() => mq?.removeEventListener?.('change', onChange));
    return isPhone;
}
