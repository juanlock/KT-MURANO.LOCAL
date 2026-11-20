/**
 * Is a logo light (white / near white, made for a dark background)? (2026-11-10: Turismo Cautivo's
 * logo is white and vanished on the white top bar.) Reads the opaque pixels of a small copy;
 * light = average luminance over 0.8. Remembered per URL. Unreadable images (another origin
 * without CORS) count as not light.
 */
const cache = new Map();
const colorCache = new Map();

/**
 * The main colours of a logo, read from the image FILE (user, 2026-11-19: the eyedropper of "Custom" picks a screen
 * pixel of the small, smoothed logo and gives a different colour). Opaque pixels of a 160 px copy, grouped by colour
 * (24 levels per channel); each group's exact average; near-white / near-black / grey left out unless nothing else;
 * groups closer than a small distance merged; the most used first. Resolves to ['#rrggbb', ...] (empty if unreadable).
 */
export function logoColors(url, max = 6) {
  if (!url) return Promise.resolve([]);
  if (colorCache.has(url)) return colorCache.get(url);
  const p = new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const w = 160;
        const h = Math.max(1, Math.round((img.naturalHeight / Math.max(1, img.naturalWidth)) * w));
        const c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        const ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, w, h);
        const d = ctx.getImageData(0, 0, w, h).data;
        const groups = new Map();
        for (let i = 0; i < d.length; i += 4) {
          if (d[i + 3] < 200) continue; // transparent or anti-aliased edge
          const r = d[i], g = d[i + 1], b = d[i + 2];
          const k = `${Math.floor(r / 24)}-${Math.floor(g / 24)}-${Math.floor(b / 24)}`;
          const e = groups.get(k) || { n: 0, r: 0, g: 0, b: 0 };
          e.n++; e.r += r; e.g += g; e.b += b;
          groups.set(k, e);
        }
        const all = [...groups.values()].map(e => ({ n: e.n, r: e.r / e.n, g: e.g / e.n, b: e.b / e.n }));
        const chroma = e => Math.max(e.r, e.g, e.b) - Math.min(e.r, e.g, e.b);
        const lum = e => 0.2126 * e.r + 0.7152 * e.g + 0.0722 * e.b;
        const colorful = all.filter(e => chroma(e) > 40 && lum(e) > 25 && lum(e) < 235);
        const pool = (colorful.length ? colorful : all).sort((a, b) => b.n - a.n);
        const picked = [];
        for (const e of pool) {
          if (picked.every(q => Math.hypot(q.r - e.r, q.g - e.g, q.b - e.b) > 48)) picked.push(e);
          if (picked.length >= max) break;
        }
        const hex = v => Math.round(v).toString(16).padStart(2, '0');
        resolve(picked.map(e => `#${hex(e.r)}${hex(e.g)}${hex(e.b)}`));
      } catch (e) {
        resolve([]);
      }
    };
    img.onerror = () => resolve([]);
    img.src = url;
  });
  colorCache.set(url, p);
  return p;
}

export function isLightLogo(url) {
  if (!url) return Promise.resolve(false);
  if (cache.has(url)) return cache.get(url);
  const p = new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const w = 48;
        const h = Math.max(1, Math.round((img.naturalHeight / Math.max(1, img.naturalWidth)) * w));
        const c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        const ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, w, h);
        const d = ctx.getImageData(0, 0, w, h).data;
        let sum = 0;
        let n = 0;
        for (let i = 0; i < d.length; i += 4) {
          if (d[i + 3] < 128) continue; // transparent
          sum += (0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]) / 255;
          n++;
        }
        resolve(n > 0 && sum / n > 0.8);
      } catch (e) {
        resolve(false);
      }
    };
    img.onerror = () => resolve(false);
    img.src = url;
  });
  cache.set(url, p);
  return p;
}
