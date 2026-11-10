/**
 * Is a logo light (white / near white, made for a dark background)? (2026-11-10: Turismo Cautivo's
 * logo is white and vanished on the white top bar.) Reads the opaque pixels of a small copy;
 * light = average luminance over 0.8. Remembered per URL. Unreadable images (another origin
 * without CORS) count as not light.
 */
const cache = new Map();

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
