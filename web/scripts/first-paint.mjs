#!/usr/bin/env node
/**
 * First-paint JS, measured ONE way, so the number stops drifting.
 *
 * Sums the entry script and every `modulepreload` in dist/index.html — the
 * bytes a browser fetches before the shell can render — gzipped at level 9.
 * The docs once said "~246 KB"; this method read 249.7 KB on the same commit,
 * and nobody could say how the 246 had been produced. That is why this exists.
 *
 *   npm run size                      build, then measure
 *   FIRST_PAINT_BUDGET_KB=255 npm run size
 *
 * Exits 1 over budget, so CI (or a person) finds out when it happens rather
 * than several phases later.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const dist = process.argv[2] ?? 'dist';
const budgetKb = Number(process.env.FIRST_PAINT_BUDGET_KB ?? 250);

const html = readFileSync(join(dist, 'index.html'), 'utf8');
const eager = [
  ...html.matchAll(/<script[^>]+src="\/?([^"]+\.js)"/g),
  ...html.matchAll(/<link[^>]+rel="modulepreload"[^>]+href="\/?([^"]+\.js)"/g),
].map((match) => match[1]);

let total = 0;
for (const file of new Set(eager)) {
  const bytes = gzipSync(readFileSync(join(dist, file)), { level: 9 }).length;
  total += bytes;
  console.log(`${(bytes / 1024).toFixed(2).padStart(8)} KB  ${file}`);
}

const totalKb = total / 1024;
const verdict = totalKb <= budgetKb ? 'within' : 'OVER';
console.log(`${totalKb.toFixed(2).padStart(8)} KB  first-paint JS, gzip-9 — ${verdict} the ${budgetKb} KB budget`);

process.exit(totalKb <= budgetKb ? 0 : 1);
