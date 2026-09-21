<template>
  <div><svg ref="svgEl"></svg></div>
</template>

<script setup>
/**
 * Vue 3 replacement for the legacy `vue-barcode` component (a JsBarcode
 * wrapper). Renders the same DOM shape (wrapper div + svg) so the barcode
 * print stylesheet (.barcode { text-align: center }) applies unchanged.
 * Prop names match the legacy call sites (textmargin, fontoptions).
 */
import { ref, watch, onMounted } from 'vue';
import JsBarcode from 'jsbarcode';

const props = defineProps({
  value: { type: [String, Number], default: '' },
  format: { type: String, default: 'CODE128' },
  width: { type: [String, Number], default: 2 },
  height: { type: [String, Number], default: 100 },
  fontSize: { type: [String, Number], default: 20 },
  textmargin: { type: [String, Number], default: 2 },
  fontoptions: { type: String, default: '' },
  font: { type: String, default: '' },
  displayValue: { type: Boolean, default: true },
});

const svgEl = ref();

function render() {
  if (!svgEl.value) return;
  const opts = (format) => ({
    format,
    width: Number(props.width),
    height: Number(props.height),
    fontSize: Number(props.fontSize),
    textMargin: Number(props.textmargin),
    fontOptions: props.fontoptions,
    font: props.font || 'monospace',
    displayValue: props.displayValue,
  });
  try {
    JsBarcode(svgEl.value, String(props.value), opts(props.format || 'CODE128'));
  } catch (e) {
    // Value can't be encoded in the requested symbology (e.g. an 8-digit
    // code with EAN13 selected). Fall back to CODE128 — which accepts any
    // ASCII value — so the label still prints a scannable barcode instead
    // of coming out blank.
    try {
      JsBarcode(svgEl.value, String(props.value), opts('CODE128'));
    } catch (e2) {
      while (svgEl.value.firstChild) svgEl.value.removeChild(svgEl.value.firstChild);
    }
  }
}

onMounted(render);
watch(() => [
  props.value, props.format, props.width, props.height,
  props.fontSize, props.textmargin, props.fontoptions, props.font, props.displayValue,
], render);
</script>
