// Bundle budget (ADR-0024): what a first visit to a page downloads,
// gzipped, in two parts with their own limits:
//   - the base every page loads (the app entry and its imports);
//   - each page's own chunks on top of the base.
// Growth in either fails, and the report says which part grew.
// Run after `npm run build`:
//
//   npm run budget
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

// Kilobytes, gzipped. Raise only with a reason in the commit message.
// Measured 2026-11-12 (Phase 7 batch 2): base 178.6 kB (react-dom,
// Inertia, tailwind-merge, the Radix menus and toasts every layout uses);
// largest page addition 31.3 kB (admin/users); CSS 13.8 kB.
const BUDGET = { base: 185, page: 35, css: 20 };

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

const size = (keys) => {
    let js = 0;
    let css = 0;

    for (const key of keys) {
        js += gzipped(manifest[key].file);

        for (const sheet of manifest[key].css ?? []) {
            css += gzipped(sheet);
        }
    }

    return { js, css };
};

const base = closure('resources/js/app.tsx');
const baseSize = size(base);
const appCss = gzipped(manifest['resources/css/app.css'].file);
let failed = baseSize.js > BUDGET.base;

console.log(
    'base (every page)'.padEnd(40),
    baseSize.js.toFixed(1).padStart(6),
    baseSize.js > BUDGET.base ? '  OVER BUDGET' : '',
);
console.log('page'.padEnd(40), ' +JS kB', 'total', 'CSS kB');

// Application pages and module pages (ADR-0033).
const pages = Object.keys(manifest).filter((key) =>
    /^resources\/js\/(pages|modules\/[^/]+\/pages)\/.+\.tsx$/.test(key),
);

for (const page of pages.sort()) {
    const own = [...closure(page)].filter((key) => !base.has(key));
    const pageSize = size(own);
    const css = baseSize.css + pageSize.css + appCss;
    const over = pageSize.js > BUDGET.page || css > BUDGET.css;
    failed ||= over;

    console.log(
        page.replace(/^resources\/js\/(pages\/)?/, '').padEnd(40),
        pageSize.js.toFixed(1).padStart(6),
        (baseSize.js + pageSize.js).toFixed(1).padStart(5),
        css.toFixed(1).padStart(6),
        over ? '  OVER BUDGET' : '',
    );
}

console.log(
    `budget: base ${BUDGET.base} kB, page +${BUDGET.page} kB, CSS ${BUDGET.css} kB (gzipped)`,
);
process.exit(failed ? 1 : 0);
