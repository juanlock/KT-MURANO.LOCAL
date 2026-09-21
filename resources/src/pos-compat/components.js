/**
 * Bootstrap-Vue component shims for the POS 1:1 port. Each renders the SAME
 * markup and classes Bootstrap-Vue rendered, so the POS SCSS (copied
 * verbatim) and the scoped Bootstrap subset style them identically. Only the
 * component API surface the POS template actually uses is implemented.
 * Render functions keep this dependency-light (no SFC per shim).
 */
import { h, defineComponent, onMounted, onBeforeUnmount, ref } from 'vue';
import { registerModal, unregisterModal } from './bv';
import VSelectCompat from './vselect';
import LucideIcon from './LucideIcon.vue';
import QrcodeScanner from './QrcodeScanner';
import SerialNumbersField from './SerialNumbersField.vue';
import { ValidationObserver, ValidationProvider } from './vee';

export const BRow = defineComponent({
    name: 'BRow',
    setup(_, { slots, attrs }) {
        return () => h('div', { ...attrs, class: ['row', attrs.class] }, slots.default?.());
    },
});

const COL_PROPS = ['cols', 'sm', 'md', 'lg', 'xl'];
export const BCol = defineComponent({
    name: 'BCol',
    props: Object.fromEntries(COL_PROPS.map(p => [p, { type: [String, Number], default: null }])),
    setup(props, { slots, attrs }) {
        return () => {
            const classes = ['col'];
            if (props.cols) { classes.shift(); classes.push(`col-${props.cols}`); }
            for (const bp of ['sm', 'md', 'lg', 'xl']) {
                if (props[bp]) {
                    if (classes[0] === 'col') classes.shift();
                    classes.push(`col-${bp}-${props[bp]}`);
                }
            }
            if (!classes.length) classes.push('col');
            return h('div', { ...attrs, class: [...classes, attrs.class] }, slots.default?.());
        };
    },
});

export const BButton = defineComponent({
    name: 'BButton',
    props: {
        variant: { type: String, default: 'secondary' },
        size: { type: String, default: null },
        block: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        type: { type: String, default: 'button' },
    },
    setup(props, { slots, attrs }) {
        return () => h('button', {
            ...attrs,
            type: props.type,
            disabled: props.disabled,
            class: [
                'btn',
                `btn-${props.variant}`,
                props.size ? `btn-${props.size}` : null,
                props.block ? 'btn-block' : null,
                attrs.class,
            ],
        }, slots.default?.());
    },
});

export const BForm = defineComponent({
    name: 'BForm',
    setup(_, { slots, attrs }) {
        return () => h('form', attrs, slots.default?.());
    },
});

export const BFormGroup = defineComponent({
    name: 'BFormGroup',
    props: { label: { type: String, default: null } },
    setup(props, { slots, attrs }) {
        return () => h('div', { ...attrs, class: ['form-group', attrs.class] }, [
            slots.label ? h('label', slots.label()) : (props.label ? h('label', props.label) : null),
            slots.default?.(),
        ]);
    },
});

export const BFormInput = defineComponent({
    name: 'BFormInput',
    props: {
        modelValue: { type: [String, Number], default: '' },
        type: { type: String, default: 'text' },
        state: { type: [Boolean, null], default: null },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue', 'input', 'keyup', 'change', 'blur', 'focus'],
    setup(props, { emit, attrs, expose }) {
        const el = ref(null);
        expose({ focus: () => el.value?.focus(), $el: el });
        return () => h('input', {
            ...attrs,
            ref: el,
            type: props.type,
            value: props.modelValue,
            disabled: props.disabled,
            class: [
                'form-control',
                props.state === false ? 'is-invalid' : props.state === true ? 'is-valid' : null,
                attrs.class,
            ],
            onInput: e => { emit('update:modelValue', e.target.value); emit('input', e.target.value); },
            onKeyup: e => emit('keyup', e),
            onChange: e => emit('change', e.target.value),
            onBlur: e => emit('blur', e),
            onFocus: e => emit('focus', e),
        });
    },
});

export const BFormSelect = defineComponent({
    name: 'BFormSelect',
    props: {
        modelValue: { default: null },
        options: { type: Array, default: () => [] },
    },
    emits: ['update:modelValue', 'change'],
    setup(props, { emit, attrs, slots }) {
        return () => h('select', {
            ...attrs,
            class: ['custom-select', attrs.class],
            value: props.modelValue,
            onChange: e => {
                const raw = e.target.value;
                const match = props.options.find(o =>
                    String(typeof o === 'object' ? o.value : o) === raw
                );
                const val = match !== undefined ? (typeof match === 'object' ? match.value : match) : raw;
                emit('update:modelValue', val);
                emit('change', val);
            },
        }, [
            ...(slots.first ? slots.first() : []),
            ...props.options.map(o => {
                const isObj = typeof o === 'object' && o !== null;
                return h('option', { value: isObj ? o.value : o }, isObj ? (o.text ?? o.label) : o);
            }),
            ...(slots.default ? slots.default() : []),
        ]);
    },
});

export const BFormInvalidFeedback = defineComponent({
    name: 'BFormInvalidFeedback',
    setup(_, { slots, attrs }) {
        return () => h('div', { ...attrs, class: ['invalid-feedback d-block', attrs.class] }, slots.default?.());
    },
});

export const BAlert = defineComponent({
    name: 'BAlert',
    props: {
        show: { type: [Boolean, Number], default: false },
        variant: { type: String, default: 'info' },
    },
    setup(props, { slots, attrs }) {
        return () => (props.show
            ? h('div', { ...attrs, class: [`alert alert-${props.variant}`, attrs.class], role: 'alert' }, slots.default?.())
            : null);
    },
});

export const BInputGroup = defineComponent({
    name: 'BInputGroup',
    setup(_, { slots, attrs }) {
        return () => h('div', { ...attrs, class: ['input-group', attrs.class] }, [
            slots.prepend ? h('div', { class: 'input-group-prepend' }, slots.prepend()) : null,
            slots.default?.(),
            slots.append ? h('div', { class: 'input-group-append' }, slots.append()) : null,
        ]);
    },
});

export const BDropdown = defineComponent({
    name: 'BDropdown',
    props: {
        right: { type: Boolean, default: false },
        // Undeclared props fall through to `attrs` and get spread onto the
        // root as inert HTML attributes, so `dropup` and `menu-class` MUST be
        // declared to have any effect. Without `dropup` the mobile tab bar's
        // "More" menu dropped downward off the bottom of the screen.
        dropup: { type: Boolean, default: false },
        noCaret: { type: Boolean, default: false },
        variant: { type: String, default: 'secondary' },
        toggleClass: { type: [String, Array], default: '' },
        menuClass: { type: [String, Array], default: '' },
    },
    setup(props, { slots, attrs }) {
        const open = ref(false);
        const rootEl = ref(null);
        const onDocClick = e => {
            if (rootEl.value && !rootEl.value.contains(e.target)) open.value = false;
        };
        onMounted(() => document.addEventListener('click', onDocClick));
        onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
        return () => h('div', {
            ...attrs,
            ref: rootEl,
            class: ['dropdown', props.dropup ? 'dropup' : null, attrs.class],
        }, [
            h('button', {
                type: 'button',
                class: [
                    'btn', `btn-${props.variant}`,
                    props.noCaret ? null : 'dropdown-toggle',
                    props.toggleClass,
                ],
                onClick: () => { open.value = !open.value; },
            }, slots['button-content']?.()),
            h('div', {
                class: [
                    'dropdown-menu',
                    props.menuClass,
                    { show: open.value, 'dropdown-menu-right': props.right },
                ],
                onClick: () => { open.value = false; },
            }, slots.default?.()),
        ]);
    },
});

export const BPagination = defineComponent({
    name: 'BPagination',
    props: {
        modelValue: { type: Number, default: 1 },
        totalRows: { type: Number, default: 0 },
        perPage: { type: Number, default: 10 },
    },
    emits: ['update:modelValue', 'change'],
    setup(props, { emit, attrs }) {
        const go = p => {
            const pages = Math.max(1, Math.ceil(props.totalRows / props.perPage));
            const next = Math.min(Math.max(1, p), pages);
            if (next !== props.modelValue) { emit('update:modelValue', next); emit('change', next); }
        };
        return () => {
            const pages = Math.max(1, Math.ceil(props.totalRows / props.perPage));
            const current = props.modelValue;
            const windowed = [];
            for (let p = Math.max(1, current - 2); p <= Math.min(pages, current + 2); p++) windowed.push(p);
            const item = (label, page, { disabled = false, active = false } = {}) =>
                h('li', { class: ['page-item', { disabled, active }] },
                    h('button', { type: 'button', class: 'page-link', disabled, onClick: () => go(page) }, label));
            return h('ul', { ...attrs, class: ['pagination', attrs.class] }, [
                item('«', 1, { disabled: current === 1 }),
                item('‹', current - 1, { disabled: current === 1 }),
                ...windowed.map(p => item(String(p), p, { active: p === current })),
                item('›', current + 1, { disabled: current === pages }),
                item('»', pages, { disabled: current === pages }),
            ]);
        };
    },
});

/**
 * Body scroll lock, counted so nested modals (payment → sub-modal) don't
 * unlock early. The `modal-open` class is what Bootstrap's own CSS keys on:
 * `.modal-open .modal { overflow-y: auto }` is what lets a modal TALLER than
 * the viewport scroll — critical on phones, where every modal is taller.
 */
let openModalCount = 0;
function lockBodyScroll() {
    openModalCount++;
    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';
}
function unlockBodyScroll() {
    openModalCount = Math.max(0, openModalCount - 1);
    if (openModalCount === 0) {
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
    }
}

export const BModal = defineComponent({
    name: 'BModal',
    // Renders IN PLACE like Bootstrap-Vue (no teleport): keeps the modal
    // inside .pos-scope, keeps ancestor selectors working, and gives the
    // parent's scoped-style attribute a chance to land on slot content.
    inheritAttrs: false,
    props: {
        id: { type: String, default: null },
        title: { type: String, default: '' },
        size: { type: String, default: null },     // sm | lg | xl
        hideFooter: { type: Boolean, default: false },
        hideHeader: { type: Boolean, default: false },
        scrollable: { type: Boolean, default: false },
        centered: { type: Boolean, default: false },
        noCloseOnBackdrop: { type: Boolean, default: false },
        bodyClass: { type: [String, Array], default: null },
        modalClass: { type: [String, Array], default: null },
        dialogClass: { type: [String, Array], default: null },
    },
    emits: ['show', 'shown', 'hide', 'hidden'],
    setup(props, { slots, emit, expose, attrs }) {
        const visible = ref(false);
        const open = () => {
            emit('show');
            visible.value = true;
            lockBodyScroll();
            // 'shown' fires after the DOM is on screen, like Bootstrap-Vue.
            requestAnimationFrame(() => requestAnimationFrame(() => emit('shown')));
        };
        const close = () => {
            emit('hide');
            visible.value = false;
            unlockBodyScroll();
            emit('hidden');
        };
        onMounted(() => registerModal(props.id, { open, close }));
        onBeforeUnmount(() => {
            unregisterModal(props.id);
            if (visible.value) unlockBodyScroll();
        });
        // Bootstrap-Vue instance API — POS sub-modals call $refs.x.show()/.hide().
        expose({ show: open, hide: close });
        return () => (visible.value
            ? [
                h('div', { class: 'modal-backdrop fade show' }),
                h('div', {
                    // Bootstrap-Vue put the id AND any static classes on the
                    // .modal element — global stylesheets key off them
                    // (#modern_payment_modal, .payment-modal-wrapper, …).
                    ...attrs,
                    id: props.id || undefined,
                    class: ['modal fade show d-block', props.modalClass, attrs.class],
                    tabindex: '-1',
                    role: 'dialog',
                    onClick: e => {
                        if (!props.noCloseOnBackdrop && e.target === e.currentTarget) close();
                    },
                }, h('div', {
                    class: [
                        'modal-dialog',
                        props.size ? `modal-${props.size}` : null,
                        props.scrollable ? 'modal-dialog-scrollable' : null,
                        props.centered ? 'modal-dialog-centered' : null,
                        props.dialogClass,
                    ],
                    role: 'document',
                }, h('div', { class: 'modal-content' }, [
                    props.hideHeader ? null : h('header', { class: 'modal-header' }, [
                        h('h5', { class: 'modal-title' }, props.title),
                        h('button', { type: 'button', class: 'close', 'aria-label': 'Close', onClick: close },
                            h('span', { 'aria-hidden': 'true' }, '×')),
                    ]),
                    h('div', { class: ['modal-body', props.bodyClass] }, slots.default?.()),
                    props.hideFooter ? null : h('footer', { class: 'modal-footer' }, slots['modal-footer']?.()),
                ]))),
            ]
            : null);
    },
});

let checkboxSeq = 0;
export const BFormCheckbox = defineComponent({
    name: 'BFormCheckbox',
    props: {
        modelValue: { default: false },
        switch: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue', 'change'],
    setup(props, { emit, slots, attrs }) {
        const id = `pos-cb-${++checkboxSeq}`;
        return () => h('div', {
            ...attrs,
            class: ['custom-control', props.switch ? 'custom-switch' : 'custom-checkbox', attrs.class],
        }, [
            h('input', {
                type: 'checkbox',
                class: 'custom-control-input',
                id,
                checked: !!props.modelValue,
                disabled: props.disabled,
                onChange: e => { emit('update:modelValue', e.target.checked); emit('change', e.target.checked); },
            }),
            h('label', { class: 'custom-control-label', for: id }, slots.default?.()),
        ]);
    },
});

export const BFormRadioGroup = defineComponent({
    name: 'BFormRadioGroup',
    props: {
        modelValue: { default: null },
        options: { type: Array, default: () => [] },
        buttons: { type: Boolean, default: false },
        buttonVariant: { type: String, default: 'secondary' },
    },
    emits: ['update:modelValue', 'change'],
    setup(props, { emit, attrs }) {
        return () => h('div', {
            ...attrs,
            class: [props.buttons ? 'btn-group btn-group-toggle' : 'custom-controls', attrs.class],
            role: 'radiogroup',
        }, props.options.map(o => {
            const isObj = typeof o === 'object' && o !== null;
            const value = isObj ? o.value : o;
            const text = isObj ? (o.text ?? o.label) : o;
            const active = props.modelValue === value;
            return props.buttons
                ? h('label', {
                    class: ['btn', `btn-${props.buttonVariant}`, { active }],
                    onClick: () => { emit('update:modelValue', value); emit('change', value); },
                }, text)
                : h('label', { class: 'custom-control custom-radio' }, [
                    h('input', {
                        type: 'radio',
                        class: 'custom-control-input',
                        checked: active,
                        onChange: () => { emit('update:modelValue', value); emit('change', value); },
                    }),
                    h('span', { class: 'custom-control-label' }, text),
                ]);
        }));
    },
});

export const BFormTextarea = defineComponent({
    name: 'BFormTextarea',
    props: {
        modelValue: { type: [String, Number], default: '' },
        rows: { type: [String, Number], default: 3 },
        state: { type: [Boolean, null], default: null },
    },
    emits: ['update:modelValue', 'input'],
    setup(props, { emit, attrs }) {
        return () => h('textarea', {
            ...attrs,
            rows: props.rows,
            value: props.modelValue,
            class: [
                'form-control',
                props.state === false ? 'is-invalid' : props.state === true ? 'is-valid' : null,
                attrs.class,
            ],
            onInput: e => { emit('update:modelValue', e.target.value); emit('input', e.target.value); },
        });
    },
});

// Native date input styled as .form-control — visually equivalent for the
// single CustomFieldsForm usage; b-form-datepicker's popup calendar is not
// reproduced.
export const BFormDatepicker = defineComponent({
    name: 'BFormDatepicker',
    props: { modelValue: { type: String, default: '' } },
    emits: ['update:modelValue', 'input'],
    setup(props, { emit, attrs }) {
        return () => h('input', {
            ...attrs,
            type: 'date',
            value: props.modelValue,
            class: ['form-control', attrs.class],
            onInput: e => { emit('update:modelValue', e.target.value); emit('input', e.target.value); },
        });
    },
});

/** Component map for `components:` in the POS page — template tags resolve
 *  (<b-modal> → BModal, etc.). */
export const posCompatComponents = {
    BRow, BCol, BButton, BForm, BFormGroup, BFormInput, BFormSelect,
    BFormInvalidFeedback, BAlert, BInputGroup, BDropdown, BPagination, BModal,
    BFormCheckbox, BFormRadioGroup, BFormTextarea, BFormDatepicker,
    // Legacy main.js registered these GLOBALLY (Vue.component) — the POS
    // SFCs therefore use them without local registration. Mirror that here
    // so every compat consumer resolves them.
    'v-select': VSelectCompat,
    'lucide-icon': LucideIcon,
    'qrcode-scanner': QrcodeScanner,
    'serial-numbers-field': SerialNumbersField,
    ValidationObserver,
    ValidationProvider,
};
