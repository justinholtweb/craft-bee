/**
 * The front-end runtime, run for real in Node against a stub DOM.
 *
 *     ddev exec node /var/www/craft-bee/tests/integration/runtime.mjs
 *
 * The PHP suites can only read bee.js as text. This loads it the three ways a site can — the
 * injected classic script, a CommonJS bundle, and an ES-module bundle via bee.mjs — and watches
 * what it actually sends.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import vm from 'node:vm';

const dist = join(dirname(fileURLToPath(import.meta.url)), '../../src/web/assets/runtime/dist');
const source = readFileSync(join(dist, 'bee.js'), 'utf8');

let passed = 0;
let failed = 0;

async function check(label, test) {
    try {
        const result = await test();

        if (result === true) {
            passed++;
            console.log(`  ✓ ${label}`);
            return;
        }

        failed++;
        console.log(`  ✗ ${label}\n    ${typeof result === 'string' ? result : 'returned ' + JSON.stringify(result)}`);
    } catch (e) {
        failed++;
        console.log(`  ✗ ${label}\n    ${e.stack}`);
    }
}

/** A page: which attributes are on it, and everything the runtime tries to send. */
function page({ config = null, configOn = 'body', forged = null, item = null, href = '' } = {}) {
    const sent = [];
    const timers = [];
    const node = (name, value) => ({ nodeType: 1, getAttribute: (a) => (a === name ? value : null) });

    // `forged` is a data-bee-config planted by user content somewhere before the real one, so
    // it is what a document-wide querySelector would find first.
    const real = config !== null ? node('data-bee-config', config) : null;
    const planted = forged !== null ? node('data-bee-config', forged) : null;
    const empty = node('-', null);

    const document = {
        hidden: false,
        body: configOn === 'body' && real ? real : empty,
        documentElement: configOn === 'html' && real ? real : empty,
        querySelector(selector) {
            if (selector === '[data-bee-config]') {
                return planted || real;
            }
            if (selector === '[data-bee-page-item]' && item !== null) {
                return node('data-bee-page-item', item);
            }
            return null;
        },
        addEventListener() {},
    };

    const store = {};
    const window = {
        document,
        location: { href },
        // A real page URL brings the URL parser with it; without one the runtime has to fail
        // closed on anything but a path-relative URL.
        ...(href ? { URL } : {}),
        addEventListener() {},
        setTimeout(fn, ms) { timers.push({ fn, ms }); return timers.length; },
        clearTimeout() {},
        sessionStorage: { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = v; } },
        fetch(url, init) {
            sent.push({ url, body: JSON.parse(init.body) });
            return Promise.resolve({});
        },
    };

    /** Fire every pending timer — the detail-view delay, without waiting for it. */
    const flush = () => timers.splice(0).forEach((t) => t.fn());

    return { window, document, sent, timers, flush };
}

/** Run bee.js as a classic script — what Bee's own injection does. */
function inject(p, module = undefined) {
    const context = { window: p.window, document: p.document, navigator: {}, JSON, Date, Math };
    if (module) {
        context.module = module;
    }
    vm.runInNewContext(source, context);
    return p.window.Bee;
}

console.log('\nRuntime');

await check('Bee.start() with no arguments takes the endpoint and delay from data-bee-config', () => {
    const p = page({ config: JSON.stringify({ endpoint: 'https://shop.test/bee/track', delay: 7 }), item: 'e42', href: 'https://shop.test/products/e42' });
    inject(p).start();

    const timer = p.timers[0];
    p.flush();

    return timer?.ms === 7000
        && p.sent.length === 1
        && p.sent[0].url === 'https://shop.test/bee/track'
        && p.sent[0].body.kind === 'detailview'
        && p.sent[0].body.itemId === 'e42'
        || `timer=${JSON.stringify(timer)} sent=${JSON.stringify(p.sent)}`;
});

await check('options passed to Bee.start() win over the page', () => {
    const p = page({ config: JSON.stringify({ endpoint: '/from-page', delay: 7 }), item: 'e42' });
    inject(p).start({ endpoint: '/from-options' });
    p.flush();

    return p.sent[0]?.url === '/from-options' && p.timers.length === 0 || JSON.stringify(p.sent);
});

await check('the injected call still works on a page with no data-bee-config', () => {
    const p = page({ item: 'e42' });
    inject(p).start({ endpoint: '/bee/track', delay: 3 });
    p.flush();

    return p.sent[0]?.url === '/bee/track' || JSON.stringify(p.sent);
});

await check('with no config anywhere, nothing is sent (tracking off)', () => {
    const p = page({ item: 'e42' });
    const Bee = inject(p);
    Bee.start();
    p.flush();

    return p.sent.length === 0 && Bee.track('bookmark', 'e42') === false || JSON.stringify(p.sent);
});

await check('malformed data-bee-config is ignored rather than thrown', () => {
    const p = page({ config: '{not json', item: 'e42' });
    inject(p).start();
    p.flush();

    return p.sent.length === 0;
});

await check('a CommonJS bundler gets the same object as window.Bee', () => {
    const p = page();
    const module = { exports: {} };
    const Bee = inject(p, module);

    return typeof Bee.start === 'function' && module.exports === Bee;
});

await check('loaded twice, the first copy wins and a bundler gets the live instance', () => {
    const p = page({ config: JSON.stringify({ endpoint: '/bee/track', delay: 0 }), item: 'e42' });
    const first = inject(p);
    first.start();

    const module = { exports: {} };
    const second = inject(p, module);
    second.start();
    p.flush();

    return second === first && module.exports === first && p.sent.length === 1
        || `same=${second === first} sent=${p.sent.length}`;
});

await check('bee.mjs default-exports the runtime and still sets window.Bee', async () => {
    const p = page({ config: JSON.stringify({ endpoint: '/bee/track', delay: 0 }), item: 'e9' });
    globalThis.window = p.window;
    globalThis.document = p.document;
    globalThis.navigator ??= {};

    try {
        const { default: Bee } = await import(pathToFileURL(join(dist, 'bee.mjs')).href);
        Bee.start();
        p.flush();

        return Bee === p.window.Bee && p.sent[0]?.body.itemId === 'e9' || JSON.stringify(p.sent);
    } finally {
        delete globalThis.window;
        delete globalThis.document;
    }
});

console.log('\nA forged config');

await check('a data-bee-config planted in the content is ignored; the one on <body> is used', () => {
    const p = page({
        forged: JSON.stringify({ endpoint: '/planted', delay: 0 }),
        config: JSON.stringify({ endpoint: '/bee/track', delay: 0 }),
        item: 'e42',
    });
    inject(p).start();
    p.flush();

    return p.sent.length === 1 && p.sent[0].url === '/bee/track' || JSON.stringify(p.sent);
});

await check('<html> works too, and a config on any other element starts nothing', () => {
    const onHtml = page({ config: JSON.stringify({ endpoint: '/bee/track', delay: 0 }), configOn: 'html', item: 'e42' });
    inject(onHtml).start();
    onHtml.flush();

    const elsewhere = page({ config: JSON.stringify({ endpoint: '/bee/track', delay: 0 }), configOn: 'div', item: 'e42' });
    inject(elsewhere).start();
    elsewhere.flush();

    return onHtml.sent.length === 1 && elsewhere.sent.length === 0
        || `html=${onHtml.sent.length} elsewhere=${elsewhere.sent.length}`;
});

await check('an endpoint on another host is dropped, so nothing is sent', () => {
    const p = page({
        config: JSON.stringify({ endpoint: 'https://evil.test/collect', delay: 0 }),
        item: 'e42',
        href: 'https://shop.test/products/e42',
    });
    inject(p).start();
    p.flush();

    return p.sent.length === 0 || JSON.stringify(p.sent);
});

await check('an absolute endpoint on this site, as siteUrl() writes it, still works', () => {
    const p = page({
        config: JSON.stringify({ endpoint: 'https://shop.test/bee/track', delay: 0 }),
        item: 'e42',
        href: 'https://shop.test/products/e42',
    });
    inject(p).start();
    p.flush();

    return p.sent[0]?.url === 'https://shop.test/bee/track' || JSON.stringify(p.sent);
});

await check('without a URL parser, protocol-relative and backslash endpoints are refused', () => {
    for (const endpoint of ['//evil.test/collect', '/\\evil.test/collect', '\t//evil.test/collect', 'https://evil.test/x']) {
        const p = page({ config: JSON.stringify({ endpoint, delay: 0 }), item: 'e42' });
        inject(p).start();
        p.flush();

        if (p.sent.length !== 0) {
            return `${JSON.stringify(endpoint)} was used`;
        }
    }

    return true;
});

await check('an endpoint passed to Bee.start() is site code and is trusted as given', () => {
    const p = page({ item: 'e42', href: 'https://shop.test/products/e42' });
    inject(p).start({ endpoint: 'https://bee.shop-cdn.test/bee/track', delay: 0 });
    p.flush();

    return p.sent[0]?.url === 'https://bee.shop-cdn.test/bee/track' || JSON.stringify(p.sent);
});

await check('bee.js still parses as an ES5 classic script (no module syntax)', () => {
    // `new vm.Script` compiles as a classic script; an `export` or `import` would be a SyntaxError.
    new vm.Script(source);
    return !/\b(const|let|class)\s|=>/.test(source.replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, '')) || 'ES2015+ syntax in bee.js';
});


console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);
