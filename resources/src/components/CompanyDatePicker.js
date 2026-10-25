import { h } from 'vue';
import { DatePicker } from 'ant-design-vue';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';
import { pickerFormat, datePlaceholder, hasTime, isDayPicker } from '../lib/dateInput';

/**
 * Registered as <a-date-picker> / <a-range-picker> for the whole app
 * (main.js, after Ant Design): the same Ant pickers, but the date shows in the
 * company's format and can be typed (lib/dateInput.js). Every prop, event and
 * slot passes through; value-format (what is stored) is untouched.
 */
function wrap(Picker, name, range) {
  return {
    name,
    inheritAttrs: false,
    setup(_, { attrs, slots }) {
      const auth = useAuthStore();
      const ui = useUiStore();

      return () => {
        if (!isDayPicker(attrs)) return h(Picker, attrs, slots);
        const fmt = auth.user?.date_format;
        const props = { ...attrs, format: pickerFormat(fmt, attrs) };
        if (props.placeholder === undefined) {
          const ph = datePlaceholder(fmt, hasTime(attrs), String(ui.locale || '').startsWith('es'));
          props.placeholder = range ? [ph, ph] : ph;
        }

        return h(Picker, props, slots);
      };
    },
  };
}

export const CompanyDatePicker = wrap(DatePicker, 'CompanyDatePicker', false);
export const CompanyRangePicker = wrap(DatePicker.RangePicker, 'CompanyRangePicker', true);
