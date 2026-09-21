/**
 * Bootstrap-Vue runtime shims for the POS port: the $bvModal show/hide
 * registry, a $bvToast.toast() that renders Bootstrap toast markup, and the
 * legacy global `Fire` event bus, plus a SweetAlert2-style $swal over antd Modal.
 */

import { Modal } from 'ant-design-vue';

// ---------------- modal registry ($bvModal) ----------------

const modals = new Map();

export function registerModal(id, api) {
    if (id) modals.set(id, api);
}

export function unregisterModal(id) {
    modals.delete(id);
}

export const bvModal = {
    show(id) { modals.get(id)?.open(); },
    hide(id) { modals.get(id)?.close(); },
};

// ---------------- toasts ($bvToast) ----------------

let toastHost = null;

function ensureToastHost() {
    if (toastHost && document.body.contains(toastHost)) return toastHost;
    toastHost = document.createElement('div');
    toastHost.className = 'pos-scope';
    Object.assign(toastHost.style, {
        position: 'fixed',
        top: '16px',
        right: '16px',
        zIndex: '11000',
        display: 'flex',
        flexDirection: 'column',
        gap: '8px',
        maxWidth: '340px',
    });
    document.body.appendChild(toastHost);
    return toastHost;
}

export const bvToast = {
    toast(message, { title = '', variant = 'default' } = {}) {
        const host = ensureToastHost();
        const el = document.createElement('div');
        el.className = 'toast show';
        el.setAttribute('role', 'alert');
        const border = { success: '#28a745', danger: '#dc3545', warning: '#ffc107', info: '#17a2b8' }[variant];
        if (border) el.style.borderLeft = `4px solid ${border}`;
        el.innerHTML = `
            ${title ? `<div class="toast-header"><strong class="mr-auto">${title}</strong></div>` : ''}
            <div class="toast-body"></div>`;
        el.querySelector('.toast-body').textContent = String(message ?? '');
        host.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    },
};

// ---------------- legacy Fire bus ----------------

const listeners = new Map();

export const Fire = {
    $on(event, fn) {
        if (!listeners.has(event)) listeners.set(event, new Set());
        listeners.get(event).add(fn);
    },
    $off(event, fn) {
        if (!listeners.has(event)) return;
        if (fn) listeners.get(event).delete(fn);
        else listeners.delete(event);
    },
    $emit(event, ...args) {
        listeners.get(event)?.forEach(fn => fn(...args));
    },
};

// ---------------- SweetAlert2 ($swal) ----------------

/**
 * Minimal SweetAlert2 facade over Ant Design's static Modal. Covers the two
 * shapes the ported POS script uses:
 *   $swal({ title, text, type, showCancelButton, confirmButtonText, ... })
 *     -> resolves { value: true } on confirm, { value: false, dismiss } on cancel
 *   $swal(title, text, type)
 *     -> plain informational alert, resolves { value: true } once closed
 */
const SWAL_MODAL = { success: 'success', error: 'error', warning: 'warning', info: 'info', question: 'confirm' };

export function swal(arg, text, type) {
    const opts = (arg && typeof arg === 'object') ? arg : { title: arg, text, type };
    const icon = SWAL_MODAL[opts.icon || opts.type] || 'info';
    const content = opts.text ?? opts.html ?? '';
    const base = {
        title: opts.title || '',
        content,
        centered: true,
        okText: opts.confirmButtonText || 'OK',
        zIndex: 12000,
    };
    return new Promise((resolve) => {
        if (opts.showCancelButton) {
            Modal.confirm({
                ...base,
                okType: icon === 'warning' || icon === 'error' ? 'danger' : 'primary',
                cancelText: opts.cancelButtonText || 'Cancel',
                onOk: () => resolve({ value: true, isConfirmed: true }),
                onCancel: () => resolve({ value: false, isConfirmed: false, dismiss: 'cancel' }),
            });
            return;
        }
        const open = Modal[icon === 'confirm' ? 'info' : icon] || Modal.info;
        open({ ...base, onOk: () => resolve({ value: true, isConfirmed: true }) });
    });
}
