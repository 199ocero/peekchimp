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
    var sessionActivityKey = 'peekchimp_session_activity';
    var sessionId = null;
    var reportedSignals = {};
    var reportLcp = function () {};
    var captureEnabled = false;

    try {
        sessionId = window.sessionStorage.getItem(sessionKey);
        if (!sessionId) {
            sessionId = randomId();
            window.sessionStorage.setItem(sessionKey, sessionId);
        }
    } catch (_) {
        sessionId = randomId();
    }

    function ensureSession() {
        var now = Date.now();
        var lastActivity = 0;

        try {
            lastActivity = parseInt(window.sessionStorage.getItem(sessionActivityKey) || '0', 10);
        } catch (_) {
            lastActivity = 0;
        }

        if (!lastActivity || now - lastActivity > 30 * 60 * 1000) {
            sessionId = randomId();
            try {
                window.sessionStorage.setItem(sessionKey, sessionId);
            } catch (_) {}
        }

        try {
            window.sessionStorage.setItem(sessionActivityKey, String(now));
        } catch (_) {}
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

    function parseUrl(value) {
        try {
            return new URL(value, window.location.href);
        } catch (_) {
            return null;
        }
    }

    function cleanPath(value) {
        var url = parseUrl(value);
        return url ? url.pathname : '/';
    }

    function cleanReferrer() {
        var referrer = parseUrl(document.referrer);
        return referrer ? referrer.origin : undefined;
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

    function event(name, properties, path) {
        ensureSession();
        var query = new URLSearchParams(window.location.search);
        var data = cleanProperties(properties);
        var item = {
            event_id: randomId(),
            event_name: name,
            platform: 'web',
            session_id: sessionId,
            occurred_at: new Date().toISOString(),
            path: path || window.location.pathname,
            referrer: cleanReferrer(),
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

        window.fetch(endpoint, {
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

    function fingerprint(value) {
        var hash = 5381;
        for (var index = 0; index < value.length; index += 1) {
            hash = (hash * 33) ^ value.charCodeAt(index);
        }
        return (hash >>> 0).toString(16);
    }

    function reportOnce(name, properties, signature) {
        var key = name + ':' + signature;
        if (reportedSignals[key]) {
            return;
        }
        reportedSignals[key] = true;
        event(name, properties);
    }

    function semanticName(element) {
        return element.getAttribute('data-peekchimp-name') || element.getAttribute('name') || undefined;
    }

    function elementKey(element) {
        return element.getAttribute('data-peekchimp-name') || element.getAttribute('id') || element.getAttribute('name') || undefined;
    }

    function elementText(element) {
        var text = element.getAttribute('aria-label') || element.textContent || '';
        text = text.replace(/\s+/g, ' ').trim();

        return text ? text.slice(0, 120) : undefined;
    }

    function interactiveElement(target) {
        return target && typeof target.closest === 'function'
            ? target.closest('a,button,input[type="button"],input[type="submit"],[role="button"]')
            : null;
    }

    function downloadExtension(link, url) {
        if (!link || !url) {
            return undefined;
        }
        var match = url.pathname.toLowerCase().match(/\.([a-z0-9]{1,8})$/);
        var extension = match && match[1];
        var downloads = ['csv', 'doc', 'docx', 'pdf', 'ppt', 'pptx', 'txt', 'xls', 'xlsx', 'zip'];
        return link.hasAttribute('download') || downloads.indexOf(extension) !== -1 ? extension || 'unknown' : undefined;
    }

    function captureClick(clickEvent) {
        var element = interactiveElement(clickEvent.target);
        if (!element) {
            return;
        }
        var tag = element.tagName.toLowerCase();
        var type = (element.getAttribute('type') || '').toLowerCase() || undefined;
        var link = tag === 'a' ? element : null;
        var destination = link ? parseUrl(link.href) : null;
        var extension = downloadExtension(link, destination);
        var kind = type === 'submit'
            ? 'submit'
            : (extension ? 'download' : (destination && destination.origin !== window.location.origin ? 'external' : 'click'));

        event('autocapture.click', {
            kind: kind,
            tag: tag,
            role: element.getAttribute('role') || undefined,
            type: type,
            target: semanticName(element),
            element_key: elementKey(element),
            text: elementText(element),
            href: destination && destination.origin === window.location.origin ? destination.pathname : undefined,
            id: element.getAttribute('id') || undefined,
            name: element.getAttribute('name') || undefined,
            destination_host: destination && kind === 'external' ? destination.hostname : undefined,
            file_extension: extension
        });
    }

    function captureSubmit(submitEvent) {
        var form = submitEvent.target;
        if (!form || form.tagName.toLowerCase() !== 'form') {
            return;
        }
        event('autocapture.submit', {
            target: semanticName(form),
            method: (form.getAttribute('method') || 'get').toUpperCase(),
            action_path: cleanPath(form.getAttribute('action') || window.location.href)
        });
    }

    function nowMilliseconds() {
        return window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
    }

    function requestDetails(value, options) {
        var url = parseUrl(typeof value === 'string' ? value : value && value.url);
        var method = (options && options.method) || (value && value.method) || 'GET';
        var collector = parseUrl(endpoint);
        return {
            url: url,
            method: String(method).toUpperCase(),
            trackable: Boolean(url && url.origin === window.location.origin && (!collector || url.origin !== collector.origin || url.pathname !== collector.pathname))
        };
    }

    function reportRequestFailure(details, status, startedAt) {
        if (!details.trackable) {
            return;
        }
        var path = details.url.pathname;
        var signature = details.method + '|' + path + '|' + status;
        reportOnce('request_failure', {
            method: details.method,
            request_path: path,
            status: status,
            duration_ms: Math.max(0, Math.round(nowMilliseconds() - startedAt)),
            fingerprint: fingerprint(signature)
        }, signature);
    }

    function captureRequests() {
        var originalFetch = window.fetch;
        if (typeof originalFetch === 'function') {
            window.fetch = function (value, options) {
                var details = requestDetails(value, options);
                var startedAt = nowMilliseconds();
                return originalFetch.apply(this, arguments).then(function (response) {
                    if (!response.ok) {
                        reportRequestFailure(details, response.status, startedAt);
                    }
                    return response;
                }).catch(function (error) {
                    reportRequestFailure(details, 0, startedAt);
                    throw error;
                });
            };
        }

        if (!window.XMLHttpRequest) {
            return;
        }
        var originalOpen = window.XMLHttpRequest.prototype.open;
        var originalSend = window.XMLHttpRequest.prototype.send;
        window.XMLHttpRequest.prototype.open = function (method, url) {
            this.__peekchimpRequest = requestDetails(url, { method: method });
            return originalOpen.apply(this, arguments);
        };
        window.XMLHttpRequest.prototype.send = function () {
            var request = this;
            var startedAt = nowMilliseconds();
            request.addEventListener('loadend', function () {
                if (request.status === 0 || request.status >= 400) {
                    reportRequestFailure(request.__peekchimpRequest || { trackable: false }, request.status, startedAt);
                }
            }, { once: true });
            return originalSend.apply(this, arguments);
        };
    }

    function captureBrowserSignals() {
        window.addEventListener('error', function (errorEvent) {
            var errorType = errorEvent.error && errorEvent.error.name ? errorEvent.error.name : 'Error';
            var scriptPath = errorEvent.filename ? cleanPath(errorEvent.filename) : undefined;
            var signature = errorType + '|' + (scriptPath || '') + '|' + (errorEvent.lineno || 0) + '|' + (errorEvent.colno || 0);
            reportOnce('browser_error', {
                error_type: errorType,
                script_path: scriptPath,
                line: errorEvent.lineno || 0,
                column: errorEvent.colno || 0,
                fingerprint: fingerprint(signature)
            }, signature);
        });
        window.addEventListener('unhandledrejection', function (rejectionEvent) {
            var reason = rejectionEvent.reason;
            var errorType = reason && reason.name ? reason.name : 'UnhandledRejection';
            reportOnce('browser_error', {
                error_type: errorType,
                fingerprint: fingerprint(errorType)
            }, 'rejection|' + errorType);
        });

        if (!window.PerformanceObserver) {
            return;
        }
        var largestContentfulPaint = 0;
        var initialPath = window.location.pathname;
        try {
            var observer = new window.PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    largestContentfulPaint = Math.max(largestContentfulPaint, entry.startTime);
                });
            });
            observer.observe({ type: 'largest-contentful-paint', buffered: true });
            reportLcp = function () {
                if (largestContentfulPaint <= 0) {
                    return;
                }
                event('web_vital.lcp', { value_ms: Math.round(largestContentfulPaint) }, initialPath);
                largestContentfulPaint = 0;
                observer.disconnect();
            };
        } catch (_) {}
    }

    function loadAutocaptureConfig() {
        var configUrl = parseUrl(endpoint);
        if (!configUrl || typeof window.fetch !== 'function') {
            return;
        }
        configUrl.searchParams.set('site', site);
        window.fetch(configUrl.toString(), { method: 'GET', credentials: 'omit', cache: 'no-store' })
            .then(function (response) {
                return response.ok && typeof response.json === 'function' ? response.json() : null;
            })
            .then(function (config) {
                if (!config || config.autocapture !== true || captureEnabled) {
                    return;
                }
                captureEnabled = true;
                document.addEventListener('click', captureClick, true);
                document.addEventListener('submit', captureSubmit, true);
                captureBrowserSignals();
                captureRequests();
            })
            .catch(function () {});
    }

    function pageView() {
        var path = window.location.pathname;
        if (path === lastPath) {
            return;
        }
        lastPath = path;
        reportedSignals = {};
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
            reportLcp();
            flush(true);
        }
    });
    window.addEventListener('pagehide', function () {
        reportLcp();
        flush(true);
    });

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
    loadAutocaptureConfig();
})(window, document);
