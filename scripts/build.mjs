// Збирає dist/sq-wholesale-analytics.zip для завантаження через «Плагіни → Додати новий».
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import archiver from 'archiver';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const slug = 'sq-wholesale-analytics';
const src = path.join(root, 'plugin', slug);
const dist = path.join(root, 'dist');
const zipPath = path.join(dist, `${slug}.zip`);

const header = fs.readFileSync(path.join(src, `${slug}.php`), 'utf8');
const version = header.match(/^\s*\*\s*Version:\s*(\S+)/m)?.[1] ?? '?';

fs.mkdirSync(dist, { recursive: true });

const output = fs.createWriteStream(zipPath);
const archive = archiver('zip', { zlib: { level: 9 } });

output.on('close', () => {
  console.log(`✔ ${path.relative(root, zipPath)} — v${version}, ${(archive.pointer() / 1024).toFixed(1)} KB`);
});
archive.on('warning', (err) => console.warn(err));
archive.on('error', (err) => {
  throw err;
});

archive.pipe(output);
// Папка плагіна в корені архіву, шляхи з прямими слешами (важливо для Linux-хостингу).
archive.directory(src, slug);
await archive.finalize();
