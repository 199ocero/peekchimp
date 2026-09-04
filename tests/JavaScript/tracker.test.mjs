import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/a.js', import.meta.url), 'utf8');

function browser({ autocapture = false } = {}) {
    const bodies = [];
    const documentListeners = {};
    const windowListeners = {};
    let performanceObserverCallback;
    const script = {
        src: 'https://peekchimp.test/a.js',
        getAttribute(name) {
            return name === 'data-site' ? 'site-key' : name === 'data-endpoint' ? 'https://peekchimp.test/api/v1/events' : null;
        },
        hasAttribute() {
            return false;
        },
    };
    const document = {
        currentScript: script,
        referrer: 'https://referrer.test/page?email=private@example.test',
        visibilityState: 'visible',
        querySelector: () => script,
        addEventListener(name, callback) {
            documentListeners[name] = callback;
        },
        dispatch(name, event) {
            documentListeners[name]?.(event);
        },
    };
    const window = {
        location: new URL('https://example.test/pricing?utm_source=newsletter&email=private@example.test'),
        document,
        history: {
            pushState() {},
            replaceState() {},
        },
        sessionStorage: {
            values: new Map(),
            getItem(key) {
                return this.values.get(key) ?? null;
            },
            setItem(key, value) {
                this.values.set(key, value);
            },
        },
        crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
        performance: { now: () => 12 },
        PerformanceObserver: class {
            constructor(callback) {
                performanceObserverCallback = callback;
            }

            observe() {}

            disconnect() {}
        },
        addEventListener(name, callback) {
            windowListeners[name] = callback;
        },
        setTimeout(callback) {
            callback();

            return null;
        },
        fetch: async (url, options) => {
            if (options?.body) {
                bodies.push(JSON.parse(options.body));

                return { ok: true, status: 200 };
            }

            if (String(url).includes('/api/v1/events?site=site-key')) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ autocapture }),
                };
            }

            return { ok: false, status: 500 };
        },
    };
    const sandbox = {
        window,
        document,
        navigator: {},
        URL,
        URLSearchParams,
        Uint8Array,
        Blob,
        Date,
        Math,
        JSON,
        setTimeout: window.setTimeout,
        console,
    };

    vm.runInNewContext(source, sandbox);

    return { bodies, document, window, windowListeners, documentListeners, performanceObserverCallback: () => performanceObserverCallback };
}

test('autocapture stays disabled when the server setting is off', () => {
    const browserInstance = browser();
    browserInstance.document.dispatch('click', { target: { closest: () => ({}) } });

    const events = browserInstance.bodies.flatMap((body) => body.events);
    assert.deepEqual(events.map((event) => event.event_name), ['page_view']);
    assert.equal(events[0].path, '/pricing');
    assert.equal(events[0].referrer, 'https://referrer.test');
});

test('autocapture emits semantic metadata without values or query strings', async () => {
    const browserInstance = browser({ autocapture: true });
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(typeof browserInstance.documentListeners.click, 'function');
    const link = {
        tagName: 'A',
        href: 'https://outside.test/pricing?token=private',
        getAttribute(name) {
            return name === 'data-peekchimp-name' ? 'download-guide' : null;
        },
        hasAttribute: () => false,
        closest() {
            return this;
        },
    };
    const download = { ...link, href: 'https://example.test/downloads/guide.pdf?token=private', hasAttribute: (name) => name === 'download' };
    const callToAction = {
        tagName: 'A',
        href: 'https://example.test/register?token=private#form',
        textContent: '  Start   free  ',
        getAttribute(name) {
            return {
                'data-peekchimp-name': 'pricing-pro-get-started',
                'aria-label': 'Get Started',
                id: 'pro-cta',
                name: 'register',
            }[name] ?? null;
        },
        hasAttribute: () => false,
        closest() {
            return this;
        },
    };
    const form = {
        tagName: 'FORM',
        getAttribute(name) {
            return name === 'data-peekchimp-name' ? 'checkout-form' : name === 'method' ? 'post' : name === 'action' ? '/checkout?token=private' : null;
        },
        hasAttribute: () => false,
    };

    browserInstance.document.dispatch('click', { target: link });
    browserInstance.document.dispatch('click', { target: download });
    browserInstance.document.dispatch('click', { target: callToAction });
    browserInstance.document.dispatch('submit', { target: form });

    const events = browserInstance.bodies.flatMap((body) => body.events);
    assert.deepEqual(events.map((event) => event.event_name), ['page_view', 'autocapture.click', 'autocapture.click', 'autocapture.click', 'autocapture.submit']);
    assert.equal(events[1].properties.destination_host, 'outside.test');
    assert.equal(events[1].properties.href, undefined);
    assert.equal(events[1].properties.kind, 'external');
    assert.equal(events[2].properties.file_extension, 'pdf');
    assert.deepEqual(events[3].properties, {
        kind: 'click',
        tag: 'a',
        target: 'pricing-pro-get-started',
        element_key: 'pricing-pro-get-started',
        text: 'Get Started',
        href: '/register',
        id: 'pro-cta',
        name: 'register',
    });
    assert.equal(events[4].properties.action_path, '/checkout');
    assert.equal(events[4].path, '/pricing');
    assert.equal(events[4].referrer, 'https://referrer.test');
    assert.equal(JSON.stringify(events).includes('private'), false);
});

test('runtime signals are metadata-only and deduplicated', async () => {
    const browserInstance = browser({ autocapture: true });
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await new Promise((resolve) => setImmediate(resolve));
    const error = {
        error: { name: 'TypeError' },
        filename: 'https://example.test/assets/app.js?secret=private',
        lineno: 42,
        colno: 8,
    };

    browserInstance.windowListeners.error(error);
    browserInstance.windowListeners.error(error);
    await browserInstance.window.fetch('/api/checkout?token=private', { method: 'POST' });

    const events = browserInstance.bodies.flatMap((body) => body.events);
    assert.deepEqual(events.map((event) => event.event_name), ['page_view', 'browser_error', 'request_failure']);
    assert.equal(events[1].properties.script_path, '/assets/app.js');
    assert.equal(events[1].properties.message, undefined);
    assert.equal(events[2].properties.request_path, '/api/checkout');
    assert.equal(events[2].properties.status, 500);
    assert.equal(JSON.stringify(events).includes('private'), false);
});

test('LCP is recorded once for the initial page', async () => {
    const browserInstance = browser({ autocapture: true });
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await new Promise((resolve) => setImmediate(resolve));
    browserInstance.performanceObserverCallback()?.({ getEntries: () => [{ startTime: 1800 }, { startTime: 2800 }] });
    browserInstance.windowListeners.pagehide();
    browserInstance.windowListeners.pagehide();

    const events = browserInstance.bodies.flatMap((body) => body.events);
    assert.deepEqual(events.map((event) => event.event_name), ['page_view', 'web_vital.lcp']);
    assert.equal(events[1].properties.value_ms, 2800);
});
