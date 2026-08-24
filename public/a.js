/* Peekchimp tracker — dependency free, cookie free, and intentionally small. */
(function (window, document) {
    'use strict';

    if (window.peekchimp && window.peekchimp.__initialized) {
        return;
    }

    var script = document.currentScript || document.querySelector('script[data-site]');
    var site = script && script.getAttribute('data-site');

    if (!site) {
        return;
    }

    var endpoint = (script.getAttribute('data-endpoint') || new URL(script.src, window.location.href).origin + '/api/v1/events');
    var queue = [];
    var flushTimer = null;
    var lastPath = null;
    var sessionKey = 'peekchimp_session';
    var sessionId = null;

    try {
        sessionId = window.sessionStorage.getItem(sessionKey);
        if (!sessionId) {
            sessionId = randomId();
            window.sessionStorage.setItem(sessionKey, sessionId);
        }
    } catch (_) {
        sessionId = randomId();
    }

    function randomId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        var bytes = new Uint8Array(16);
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var index = 0; index < bytes.length; index += 1) {
                bytes[index] = Math.floor(Math.random() * 256);
            }
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(bytes, function (byte) { return ('0' + byte.toString(16)).slice(-2); }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function cleanProperties(properties) {
        var clean = {};
        var keys = Object.keys(properties || {}).slice(0, 20);

        keys.forEach(function (key) {
            var value = properties[key];
            if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
                clean[key.slice(0, 64)] = typeof value === 'string' ? value.slice(0, 256) : value;
            }
        });

        return clean;
    }

    function event(name, properties) {
        var query = new URLSearchParams(window.location.search);
        var data = cleanProperties(properties);
        var item = {
            event_id: randomId(),
            event_name: name,
            platform: 'web',
            session_id: sessionId,
            path: window.location.pathname + window.location.search,
            referrer: document.referrer || undefined,
            utm_source: query.get('utm_source') || undefined,
            utm_medium: query.get('utm_medium') || undefined,
            utm_campaign: query.get('utm_campaign') || undefined,
            properties: data
        };

        queue.push(item);
        scheduleFlush();
    }

    function scheduleFlush() {
        if (flushTimer !== null) {
            return;
        }

        flushTimer = window.setTimeout(function () {
            flushTimer = null;
            flush(false);
        }, 250);
    }

    function flush(beacon) {
        if (!queue.length) {
            return;
        }

        var batch = queue.splice(0, 10);
        var body = JSON.stringify({ site: site, events: batch });

        if (beacon && navigator.sendBeacon) {
            var sent = navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
            if (!sent) {
                queue = batch.concat(queue);
            }
            return;
        }

        batch.forEach(function (item) { item._peekchimp_attempt = (item._peekchimp_attempt || 0) + 1; });
        body = JSON.stringify({ site: site, events: batch });

        fetch(endpoint, {
            method: 'POST',
            body: body,
            keepalive: true,
            credentials: 'omit',
            mode: 'cors',
            headers: { 'Content-Type': 'application/json' }
        }).catch(function () {
            if (batch[0] && batch[0]._peekchimp_attempt < 2) {
                window.setTimeout(function () {
                    queue = batch.concat(queue);
                    flush(false);
                }, 1000);
            }
        });

        if (queue.length) {
            scheduleFlush();
        }
    }

    function pageView() {
        var path = window.location.pathname + window.location.search;
        if (path === lastPath) {
            return;
        }
        lastPath = path;
        event('page_view', {});
    }

    function patchHistory(method) {
        var original = window.history[method];
        window.history[method] = function () {
            var result = original.apply(this, arguments);
            window.setTimeout(pageView, 0);
            return result;
        };
    }

    patchHistory('pushState');
    patchHistory('replaceState');
    window.addEventListener('popstate', pageView);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush(true);
        }
    });
    window.addEventListener('pagehide', function () { flush(true); });

    window.peekchimp = {
        __initialized: true,
        track: function (name, properties) {
            if (typeof name !== 'string' || !/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/.test(name)) {
                return;
            }
            event(name, properties || {});
        }
    };

    pageView();
})(window, document);
