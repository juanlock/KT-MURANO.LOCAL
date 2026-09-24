// '#0078d4' -> [0, 120, 212].
export function hexToRgb(hex) {
  const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
  return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [0, 120, 212];
}

// Perceived brightness (ITU-R BT.601), 0 (black) - 255 (white).
export function brightness(hex) {
  const [r, g, b] = hexToRgb(hex);
  return (r * 299 + g * 587 + b * 114) / 1000;
}

// Whether black text reads better on this colour than white does.
export function isLightColor(hex) {
  return brightness(hex) > 130;
}
