import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';

// The transport dashboard's charts (2026-11-05). Colors checked for color blindness (dataviz validator); yellow is weak
// on white, so its bars carry their figure, and every chart has its table (TransportChartBlock).
export const BLUE = '#2a78d6';
export const ORANGE = '#eb6834';
export const RED = '#e34948';
export const YELLOW = '#eda100';
export const GRAY = '#898781';

/** The height left on the screen under `el`, so a board fits with no scroll. null on a phone, where the blocks stack. */
export function useFitHeight(el, bottom = 62) {
  const fit = ref(null);
  function measure() {
    if (!el.value || window.innerWidth < 900) { fit.value = null; return; }
    const top = el.value.getBoundingClientRect().top + window.scrollY;
    fit.value = Math.max(400, Math.floor(window.innerHeight - top - bottom));
  }
  onMounted(() => { nextTick(measure); window.addEventListener('resize', measure); });
  onBeforeUnmount(() => window.removeEventListener('resize', measure));
  return { fit, measure };
}

export function useTransportCharts() {
  const ui = useUiStore();
  const { money } = useFormat();
  const es = computed(() => ui.locale === 'es');
  const tr = (en, sp) => (es.value ? sp : en);
  // Same number style as money() in the app, whatever the language.
  const short = v => new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(v);
  const small = { fontSize: '10px' };

  // What every chart shares: compact, quiet grid and axes, the app's font, the theme's tooltip.
  function base(type) {
    return {
      chart: { type, background: 'transparent', foreColor: ui.dark ? '#c3c2b7' : '#52514e', fontFamily: 'inherit', toolbar: { show: false }, animations: { enabled: false }, parentHeightOffset: 0 },
      grid: { borderColor: ui.dark ? '#2c2c2a' : '#e1e0d9', strokeDashArray: 0, padding: { top: -10, bottom: -4, left: 4, right: 8 } },
      tooltip: { theme: ui.dark ? 'dark' : 'light', y: { formatter: v => money(v) } },
      legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px', offsetY: -2, height: 18, markers: { size: 5 }, itemMargin: { horizontal: 6, vertical: 0 } },
      dataLabels: { enabled: false },
    };
  }

  // Horizontal bars with the figure past the end of each one.
  function hbar(names, series, { colors, stacked = false, labels = short, tooltip = money, ranges, offset = 26, labelWidth = 130, height } = {}) {
    const o = base('bar');
    const groups = stacked ? 1 : series.length;
    const values = stacked ? names.map((_, i) => series.reduce((sum, s) => sum + s.data[i], 0)) : series.flatMap(s => s.data);
    const top = Math.max(0, ...values);
    const low = Math.min(0, ...values);
    const room = (top - low) * 0.22; // keeps the longest bar's figure outside it
    // A thin bar whatever the space: with few rows, a percentage alone would make blocks of them.
    const slot = height ? Math.max(1, (height - 46) / Math.max(1, names.length)) : 40;
    const thickness = Math.min(groups > 1 ? 78 : 66, Math.round(((groups > 1 ? 22 : 16) / slot) * 100));
    return {
      ...o,
      chart: { ...o.chart, stacked },
      colors,
      plotOptions: { bar: { horizontal: true, barHeight: `${thickness}%`, borderRadius: 3, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last',
        dataLabels: { position: stacked ? 'center' : 'top' }, ...(ranges ? { colors: { ranges } } : {}) } },
      stroke: { show: true, width: 1, colors: ['transparent'] },
      dataLabels: { enabled: true, formatter: v => (v ? labels(v) : ''), offsetX: stacked ? 0 : offset, style: { fontSize: '10px', fontWeight: 500, colors: [stacked ? '#fff' : (ui.dark ? '#f0efec' : '#0b0b0b')] } },
      xaxis: { categories: names, labels: { style: small, formatter: v => (typeof v === 'number' ? short(v) : v) }, axisBorder: { show: false }, axisTicks: { show: false } },
      yaxis: { labels: { maxWidth: labelWidth, style: small }, ...(stacked ? {} : { max: top ? top + room : undefined, min: low ? low - room : undefined }) },
      grid: { ...o.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } }, padding: { ...o.grid.padding, right: 22 } },
      tooltip: { ...o.tooltip, y: { formatter: v => tooltip(v) } },
      legend: { ...o.legend, show: series.length > 1 },
    };
  }

  return { ui, money, es, tr, short, small, base, hbar };
}
