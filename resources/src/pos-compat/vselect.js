/**
 * vue-select compat wrapper for the POS 1:1 port.
 *
 * The legacy POS templates use the Vue 2 contract in two forms:
 *   - v-model="..."            (compiles to modelValue in Vue 3 — fine)
 *   - :value="..." + @input    (vue-select 4 renamed these to
 *                               modelValue / update:modelValue; a raw @input
 *                               would fall through as a NATIVE listener on
 *                               the root div and fire with an InputEvent
 *                               while typing in the search box)
 *
 * This wrapper accepts both forms and re-emits `input` with the selected
 * value, so the ported templates stay byte-identical to legacy.
 */
import { h, defineComponent } from 'vue';
import VueSelect from 'vue-select';

export default defineComponent({
    name: 'VSelect',
    inheritAttrs: false,
    props: {
        value: { type: null, default: undefined },
        modelValue: { type: null, default: undefined },
    },
    emits: ['input', 'update:modelValue'],
    setup(props, { attrs, slots, emit }) {
        return () => h(VueSelect, {
            ...attrs,
            modelValue: props.value !== undefined ? props.value : props.modelValue,
            'onUpdate:modelValue': (v) => {
                emit('update:modelValue', v);
                emit('input', v);
            },
        }, slots);
    },
});
