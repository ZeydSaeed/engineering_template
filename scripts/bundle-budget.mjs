// Bundle budget per page (ADR-0024): everything a first visit to a page
// downloads (the app entry, the page and every chunk they import), gzipped,
// must stay within the budget below. Run after `npm run build`:
//
//   npm run budget
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

// Kilobytes, gzipped. Raise only with a reason in the commit message.
// Measured 2026-11-12: 177-201 kB JS, most of it react-dom, Inertia,
// tailwind-merge and the Radix menus every layout shares (ADR-0024).
const BUDGET = { js: 210, css: 20 };

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const gzipped = (file) =>
    gzipSync(readFileSync(`public/build/${file}`)).length / 1024;

function closure(key, seen = new Set()) {
    if (seen.has(key) || !manifest[key]) {
        return seen;
    }

    seen.add(key);

    for (const imported of manifest[key].imports ?? []) {
        closure(imported, seen);
    }

    return seen;
}

const entry = 'resources/js/app.tsx';
const pages = Object.keys(manifest).filter((key) =>
    /^resources\/js\/pages\/.+\.tsx$/.test(key),
);
let failed = false;

console.log('page'.padEnd(40), 'JS kB', 'CSS kB');

for (const page of pages.sort()) {
    const chunks = new Set([...closure(entry), ...closure(page)]);
    let js = 0;
    let css = 0;

    for (const key of chunks) {
        js += gzipped(manifest[key].file);

        for (const sheet of manifest[key].css ?? []) {
            css += gzipped(sheet);
        }
    }

    css += gzipped(manifest['resources/css/app.css'].file);

    const over = js > BUDGET.js || css > BUDGET.css;
    failed ||= over;
    console.log(
        page.replace('resources/js/pages/', '').padEnd(40),
        js.toFixed(1).padStart(5),
        css.toFixed(1).padStart(6),
        over ? '  OVER BUDGET' : '',
    );
}

console.log(`budget: JS ${BUDGET.js} kB, CSS ${BUDGET.css} kB (gzipped)`);
process.exit(failed ? 1 : 0);
