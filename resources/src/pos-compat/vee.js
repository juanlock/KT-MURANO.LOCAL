/**
 * vee-validate v3 replacement with REAL validation (user rule: front-end
 * validation must match legacy exactly). Implements the two rules the POS
 * uses — `required` and `regex` — with legacy's exact localized messages
 * (see legacy main.js localize()): required → "This field is required",
 * regex → "This field must be a valid".
 *
 * Behavior parity with vee-validate v3:
 * - provider renders a <span> wrapper (v3's default tag) and exposes
 *   {valid, errors, dirty, validated, touched} to its slot — legacy's
 *   getValidationState({dirty, validated, valid}) works unchanged
 * - valid is null until the field was interacted with or validated, so
 *   pristine forms look identical to legacy
 * - observer.validate() validates every registered provider and resolves
 *   a boolean; after the first validate, fields re-validate live on input
 * - values are read from the rendered DOM (input/select/textarea, checkbox
 *   checked, or vue-select's .vs__selected chip), which covers every field
 *   the POS wraps
 */
import { h, defineComponent, ref, reactive, computed, provide, inject, onMounted, onBeforeUnmount } from 'vue';

const MESSAGES = {
    required: 'This field is required',
    regex: 'This field must be a valid',
};

const OBSERVER_KEY = Symbol('pos-vee-observer');

function readDomValue(rootEl) {
    if (!rootEl) return '';
    // vue-select: a selected chip means "has value".
    const vs = rootEl.querySelector('.v-select, .vs__dropdown-toggle');
    if (vs) {
        return rootEl.querySelector('.vs__selected') ? 'selected' : '';
    }
    const field = rootEl.querySelector('input, select, textarea');
    if (!field) return '';
    if (field.type === 'checkbox') return field.checked ? 'on' : '';
    return field.value ?? '';
}

function normalizeRules(rules) {
    if (!rules) return {};
    if (typeof rules === 'string') {
        // "required|regex:..." string form — POS only uses the object form,
        // but keep the string form working for safety.
        const out = {};
        rules.split('|').forEach(part => {
            const [name, arg] = part.split(':');
            if (name) out[name] = arg ?? true;
        });
        return out;
    }
    return rules;
}

export const ValidationProvider = defineComponent({
    name: 'ValidationProvider',
    props: {
        rules: { type: [Object, String], default: null },
        name: { type: String, default: '' },
        vid: { type: String, default: '' },
        mode: { type: String, default: 'aggressive' },
    },
    setup(props, { slots, expose }) {
        const rootEl = ref(null);
        const state = reactive({
            errors: [],
            dirty: false,
            touched: false,
            validated: false,
        });

        function runRules() {
            const rules = normalizeRules(props.rules);
            const value = readDomValue(rootEl.value);
            const errors = [];
            if (rules.required) {
                const empty = value === '' || value === null || value === undefined;
                if (empty) errors.push(MESSAGES.required);
            }
            if (rules.regex && value !== '' && value !== null && value !== undefined) {
                const re = rules.regex instanceof RegExp ? rules.regex : new RegExp(rules.regex);
                if (!re.test(String(value))) errors.push(MESSAGES.regex);
            }
            state.errors = errors;
            return errors.length === 0;
        }

        function validate() {
            state.validated = true;
            return Promise.resolve({ valid: runRules() });
        }

        function reset() {
            state.errors = [];
            state.dirty = false;
            state.touched = false;
            state.validated = false;
        }

        const observer = inject(OBSERVER_KEY, null);
        const api = { validate, reset };
        onMounted(() => observer?.register(api));
        onBeforeUnmount(() => observer?.unregister(api));
        expose(api);

        const ctx = computed(() => ({
            valid: state.validated || state.dirty ? state.errors.length === 0 : null,
            errors: state.errors,
            dirty: state.dirty,
            touched: state.touched,
            validated: state.validated,
            failedRules: {},
        }));

        // vee's default `aggressive` mode validates on every input from the
        // first keystroke — reproduce that so red/green states appear at the
        // same moments they did in legacy.
        function onInput() {
            state.dirty = true;
            runRules();
        }
        function onFocusOut() {
            state.touched = true;
            if (state.dirty || state.validated) runRules();
        }

        return () => h('span', {
            ref: rootEl,
            onInput,
            onChange: onInput,
            onFocusout: onFocusOut,
        }, slots.default?.(ctx.value));
    },
});

export const ValidationObserver = defineComponent({
    name: 'ValidationObserver',
    setup(_, { slots, expose }) {
        const providers = new Set();
        provide(OBSERVER_KEY, {
            register: p => providers.add(p),
            unregister: p => providers.delete(p),
        });
        expose({
            async validate() {
                const results = await Promise.all([...providers].map(p => p.validate()));
                return results.every(r => r.valid);
            },
            reset() {
                providers.forEach(p => p.reset());
            },
        });
        return () => h('span', slots.default?.({}));
    },
});
