// Downscale + re-encode an image File to a compact JPEG data URL before upload.
// High-res source images (e.g. AI-generated PNGs) base64-encode into multi-MB
// payloads that exceed the API/nginx body limit; compressing first keeps uploads
// well under the limit while staying visually sharp.

type Options = { maxDim?: number; quality?: number };

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = src;
  });
}

function readAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

/**
 * Convert an image File to a compressed JPEG data URL.
 * Scales the longest edge down to `maxDim` (default 1600px) and encodes at
 * `quality` (default 0.82). Falls back to the raw data URL if canvas/decoding
 * is unavailable (e.g. SVG, or an unexpected decode error).
 */
export async function fileToCompressedDataUrl(file: File, opts: Options = {}): Promise<string> {
  const { maxDim = 1600, quality = 0.82 } = opts;
  const raw = await readAsDataUrl(file);

  // SVGs can't be rasterised meaningfully here; ship as-is (they're already tiny).
  if (file.type === 'image/svg+xml') return raw;

  try {
    const img = await loadImage(raw);
    const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
    const w = Math.round(img.width * scale);
    const h = Math.round(img.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) return raw;

    // White backfill so transparent PNGs (logos) don't turn black under JPEG.
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);

    const out = canvas.toDataURL('image/jpeg', quality);
    // Guard against pathological cases where re-encoding grew the payload.
    return out.length < raw.length ? out : raw;
  } catch {
    return raw;
  }
}
