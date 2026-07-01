// Generates PNG app icons + favicon from public/favicon.svg.
// Run with: node scripts/gen-icons.mjs
import sharp from 'sharp';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const svg = readFileSync(resolve(root, 'public/favicon.svg'));

mkdirSync(resolve(root, 'public/icons'), { recursive: true });

const targets = [
  { file: 'public/favicon-32.png', size: 32 },
  { file: 'public/favicon-16.png', size: 16 },
  { file: 'public/apple-touch-icon.png', size: 180 },
  { file: 'public/icons/icon-192.png', size: 192 },
  { file: 'public/icons/icon-512.png', size: 512 },
];

for (const { file, size } of targets) {
  await sharp(svg, { density: 384 })
    .resize(size, size, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png()
    .toFile(resolve(root, file));
  console.log(`wrote ${file} (${size}x${size})`);
}
