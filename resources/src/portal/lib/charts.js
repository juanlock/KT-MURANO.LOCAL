/*
 * Chart.js bootstrap for the portal.
 *
 * A component renders `<canvas data-rst-chart="{...}">` and `renderChart()`
 * builds the chart from that spec. Colours resolve from the live CSS custom
 * properties, so charts follow the customizer's accent and repaint when the
 * light/dark theme is toggled.
 */
import {
  ArcElement,
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  DoughnutController,
  Filler,
  Legend,
  LineController,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
} from 'chart.js';

Chart.register(
  ArcElement, BarController, BarElement, CategoryScale, DoughnutController,
  Filler, Legend, LineController, LineElement, LinearScale, PointElement, Tooltip,
);

const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const isDark = () => document.documentElement.getAttribute('data-bs-theme') === 'dark';

/** Accept #rgb / #rrggbb / rgb(...) and return "r, g, b". */
function toRgb(color) {
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(color);
  if (hex) {
    const full = hex[1].length === 3 ? hex[1].replace(/./g, (c) => c + c) : hex[1];
    const n = parseInt(full, 16);
    return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`;
  }
  const rgb = /rgba?\(([^)]+)\)/i.exec(color);
  if (rgb) {
    return rgb[1].split(',').slice(0, 3).map((p) => Math.round(parseFloat(p))).join(', ');
  }
  return '249, 115, 22';
}

const alpha = (color, a) => `rgba(${toRgb(color)}, ${a})`;

function palette() {
  const dark = isDark();
  return {
    accent: cssVar('--accent') || '#f97316',
    amber: dark ? '#fbbf24' : '#f59e0b',
    peach: dark ? '#fdba74' : '#fb923c',
    ember: dark ? '#ea580c' : '#c2410c',
    sand: dark ? '#fde68a' : '#fcd34d',
    slate: dark ? '#64748b' : '#94a3b8',
    success: dark ? '#34d399' : '#16a34a',
    danger: dark ? '#f87171' : '#dc2626',
    info: dark ? '#60a5fa' : '#2a78d6',
    s1: cssVar('--rst-s1') || (dark ? '#3987e5' : '#2a78d6'),
    s2: cssVar('--rst-s2') || (dark ? '#d95926' : '#eb6834'),
    s3: cssVar('--rst-s3') || (dark ? '#199e70' : '#1baf7a'),
    s4: cssVar('--rst-s4') || (dark ? '#c98500' : '#eda100'),
    s5: cssVar('--rst-s5') || (dark ? '#d55181' : '#e87ba4'),
    s6: cssVar('--rst-s6') || '#008300',
  };
}

const resolveColor = (name, fallback) => palette()[name] ?? (name || fallback);

function chrome() {
  const dark = isDark();
  return {
    grid: dark ? 'rgba(148, 163, 184, .16)' : 'rgba(15, 23, 42, .07)',
    tick: dark ? '#94a3b8' : '#64748b',
    surface: cssVar('--tblr-bg-surface') || (dark ? '#1a2234' : '#ffffff'),
    body: cssVar('--tblr-body-color') || (dark ? '#e2e8f0' : '#1e293b'),
    border: cssVar('--tblr-border-color') || (dark ? '#2c3a52' : '#e2e8f0'),
  };
}

function formatter(spec) {
  const symbol = spec.currency || '';
  const digits = spec.decimals ?? 0;
  return (value, compact = false) => {
    const n = Number(value) || 0;
    if (spec.format === 'percent') return `${Math.round(n * 10) / 10}%`;
    if (spec.format === 'currency') {
      if (compact && Math.abs(n) >= 1000) {
        const k = Math.round((n / 1000) * 10) / 10;
        return `${symbol}${String(k).replace(/\.0$/, '')}K`;
      }
      return symbol + n.toLocaleString(undefined, {
        minimumFractionDigits: compact ? 0 : digits,
        maximumFractionDigits: compact ? 0 : digits,
      });
    }
    return n.toLocaleString();
  };
}

function areaFill(context, color, strength) {
  const { ctx, chartArea } = context.chart;
  if (!chartArea) return alpha(color, strength * 0.5);
  const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
  gradient.addColorStop(0, alpha(color, strength));
  gradient.addColorStop(1, alpha(color, 0));
  return gradient;
}

function buildDatasets(spec, ui) {
  return (spec.series || []).map((series) => {
    const color = resolveColor(series.color, palette().accent);
    const common = { label: series.label ?? '', data: series.data ?? [] };

    if (spec.type === 'doughnut') {
      const empty = common.data.reduce((sum, v) => sum + (Number(v) || 0), 0) === 0;
      return {
        ...common,
        data: empty ? common.data.map((_, i) => (i === 0 ? 1 : 0)) : common.data,
        backgroundColor: empty
          ? common.data.map(() => alpha(ui.tick, 0.18))
          : (series.colors || []).map((c) => resolveColor(c, palette().accent)),
        borderColor: ui.surface,
        borderWidth: 3,
        hoverOffset: empty ? 0 : 8,
        hoverBorderColor: ui.surface,
        _empty: empty,
      };
    }

    if (spec.type === 'bar') {
      return {
        ...common,
        backgroundColor: series.soft ? alpha(color, 0.4) : color,
        hoverBackgroundColor: color,
        borderColor: 'transparent',
        borderRadius: 6,
        borderSkipped: false,
        maxBarThickness: spec.horizontal ? 18 : 34,
      };
    }

    return {
      ...common,
      borderColor: color,
      backgroundColor: series.fill === false
        ? 'transparent'
        : (context) => areaFill(context, color, series.fillStrength ?? 0.22),
      fill: series.fill === false ? false : 'origin',
      borderWidth: series.width ?? 2.5,
      borderDash: series.dashed ? [5, 4] : undefined,
      tension: series.tension ?? 0.38,
      pointRadius: (context) => {
        const points = context.dataset.data;
        const i = context.dataIndex;
        if (points[i] === null || points[i] === undefined) return 0;
        const linked = (i > 0 && points[i - 1] !== null && points[i - 1] !== undefined)
          || (i < points.length - 1 && points[i + 1] !== null && points[i + 1] !== undefined);
        return linked ? 0 : 3.5;
      },
      pointHoverRadius: 5,
      pointBackgroundColor: color,
      pointBorderColor: ui.surface,
      pointBorderWidth: 2,
      pointHitRadius: 18,
      spanGaps: true,
    };
  });
}

function buildOptions(spec, ui) {
  const fmt = formatter(spec);
  const rtl = document.documentElement.getAttribute('dir') === 'rtl';

  const tooltip = {
    rtl,
    backgroundColor: ui.surface,
    titleColor: ui.body,
    bodyColor: ui.body,
    borderColor: ui.border,
    borderWidth: 1,
    padding: 10,
    cornerRadius: 8,
    boxWidth: 8,
    boxHeight: 8,
    boxPadding: 4,
    usePointStyle: true,
    filter: (context) => !context.dataset._empty,
    callbacks: {
      label(context) {
        const label = context.dataset.label ? `${context.dataset.label}: ` : '';
        const value = spec.type === 'doughnut' ? context.parsed : context.parsed.y ?? context.parsed.x;
        const share = spec.type === 'doughnut' && spec.total > 0 ? ` (${Math.round((value / spec.total) * 100)}%)` : '';
        return `${label}${fmt(value)}${share}`;
      },
    },
  };

  const legend = {
    display: spec.legend !== false && ((spec.series || []).length > 1 || spec.type === 'doughnut'),
    position: spec.legendPosition || 'bottom',
    rtl,
    labels: { color: ui.tick, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16, font: { size: 12 } },
  };

  const base = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: spec.type === 'doughnut' ? { mode: 'nearest', intersect: true } : { mode: 'index', intersect: false },
    animation: { duration: 550, easing: 'easeOutQuart' },
    plugins: { legend, tooltip },
  };

  if (spec.type === 'doughnut') return { ...base, cutout: spec.cutout || '72%' };

  const valueAxis = {
    beginAtZero: true,
    suggestedMax: spec.max,
    border: { display: false },
    grid: { color: ui.grid, drawTicks: false },
    ticks: { color: ui.tick, padding: 8, maxTicksLimit: spec.ticks ?? 5, font: { size: 11 }, callback: (value) => fmt(value, true) },
  };
  const categoryAxis = {
    border: { display: false },
    grid: { display: false },
    ticks: { color: ui.tick, padding: 6, font: { size: 11 }, autoSkip: true, maxRotation: 0 },
  };
  const stacked = !!spec.stacked;

  return {
    ...base,
    indexAxis: spec.horizontal ? 'y' : 'x',
    scales: spec.horizontal
      ? { x: { ...valueAxis, stacked }, y: { ...categoryAxis, stacked } }
      : { y: { ...valueAxis, stacked }, x: { ...categoryAxis, stacked } },
  };
}

const charts = new Map();

/** Draw (or redraw) the chart for one canvas carrying data-rst-chart. */
export function renderChart(canvas) {
  let spec;
  try { spec = JSON.parse(canvas.dataset.rstChart); } catch { return; }
  const ui = chrome();
  const existing = charts.get(canvas);
  const type = spec.type || 'line';
  if (existing && existing.config.type === type) {
    // Same chart, new data or theme: update in place so Chart.js keeps the
    // canvas it already sized (destroy + recreate leaves a 0×0 canvas behind
    // after a theme toggle).
    existing.data.labels = spec.labels || [];
    existing.data.datasets = buildDatasets(spec, ui);
    existing.options = buildOptions(spec, ui);
    existing.update();
    return;
  }
  existing?.destroy();
  charts.set(canvas, new Chart(canvas, {
    type,
    data: { labels: spec.labels || [], datasets: buildDatasets(spec, ui) },
    options: buildOptions(spec, ui),
  }));
}

export function destroyChart(canvas) {
  charts.get(canvas)?.destroy();
  charts.delete(canvas);
}

export function renderAllCharts(root = document) {
  root.querySelectorAll('canvas[data-rst-chart]').forEach(renderChart);
}

/*
 * Repaint on theme or accent change — the customizer writes `data-bs-theme`
 * and an inline `--accent` on <html>, neither of which Chart.js observes.
 */
let repaint;
new MutationObserver(() => {
  clearTimeout(repaint);
  repaint = setTimeout(() => {
    charts.forEach((_, canvas) => { if (canvas.isConnected) renderChart(canvas); else charts.delete(canvas); });
  }, 60);
}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme', 'style'] });
