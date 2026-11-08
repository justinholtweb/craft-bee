/**
 * Bee front-end runtime.
 *
 * Sends interactions to Bee's own endpoint, never to Recombee directly — the private token stays on
 * the server, and the visitor's identity is resolved server-side from their cookie. Nothing here can
 * name a user, which is what makes the public endpoint safe.
 *
 * No dependencies and no build step. This ships into other people's sites.
 */
(function (window, document) {
    'use strict';

    // Loaded twice — injected by Bee *and* bundled by the site — the first copy wins, so there is
    // only ever one click listener and one detail view. A bundler still gets the live instance.
    if (window.Bee) {
        if (typeof module === 'object' && module && module.exports) {
            module.exports = window.Bee;
        }

        return;
    }

    var config = {
        endpoint: null,
        delay: 3
    };

    var state = {
        started: false,
        item: null,
        enteredAt: null,
        visibleMs: 0,
        viewSent: false,
        viewTimer: null
    };

    var ATTRIBUTION_KEY = 'bee:attribution';
    var ATTRIBUTION_TTL = 30 * 60 * 1000; // Recombee keeps a recommId alive for thirty minutes.

    // ── transport ────────────────────────────────────────────────────────────────────────────

    function send(payload, beacon) {
        if (!config.endpoint || !payload.itemId) {
            return false;
        }

        var body = JSON.stringify(payload);

        // A page being unloaded cannot wait for fetch. sendBeacon is the only transport the browser
        // promises to finish, and it is why the dwell-time update ever arrives at all.
        if (beacon && navigator.sendBeacon) {
            try {
                return navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'application/json' }));
            } catch (e) {
                // Some privacy extensions throw rather than return false. Fall through to fetch.
            }
        }

        if (window.fetch) {
            window.fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: body,
                credentials: 'same-origin',
                keepalive: true
            })['catch'](function () {
                // Telemetry. A blocked request is not the visitor's problem.
            });

            return true;
        }

        return false;
    }

    // ── attribution ──────────────────────────────────────────────────────────────────────────

    /**
     * Remember which recommendation a click came from, so the *next* page's detail view can carry
     * the recommId. The click and the view happen on different pages, which is why this has to
     * survive navigation.
     */
    function rememberAttribution(itemId, recommId) {
        try {
            var store = readAttribution();
            store[itemId] = { r: recommId, t: Date.now() };
            window.sessionStorage.setItem(ATTRIBUTION_KEY, JSON.stringify(store));
        } catch (e) {
            // Private browsing, storage disabled, quota. Attribution is a nice-to-have.
        }
    }

    function readAttribution() {
        try {
            var raw = window.sessionStorage.getItem(ATTRIBUTION_KEY);
            var store = raw ? JSON.parse(raw) : {};
            var now = Date.now();

            for (var key in store) {
                if (store.hasOwnProperty(key) && (now - store[key].t) > ATTRIBUTION_TTL) {
                    delete store[key];
                }
            }

            return store;
        } catch (e) {
            return {};
        }
    }

    function attributionFor(itemId) {
        var entry = readAttribution()[itemId];

        return entry ? entry.r : null;
    }

    function onClick(event) {
        var node = event.target;

        while (node && node !== document.body) {
            if (node.nodeType === 1 && node.getAttribute('data-bee-recomm-id')) {
                var itemId = node.getAttribute('data-bee-item-id');

                if (itemId) {
                    rememberAttribution(itemId, node.getAttribute('data-bee-recomm-id'));
                }

                return;
            }

            node = node.parentNode;
        }
    }

    // ── dwell time ───────────────────────────────────────────────────────────────────────────

    function accrue() {
        if (state.enteredAt !== null) {
            state.visibleMs += Date.now() - state.enteredAt;
            state.enteredAt = null;
        }
    }

    function resume() {
        if (state.enteredAt === null) {
            state.enteredAt = Date.now();
        }
    }

    function seconds() {
        var total = state.visibleMs + (state.enteredAt !== null ? Date.now() - state.enteredAt : 0);

        return Math.round(total / 1000);
    }

    /**
     * The detail view is sent once, after the visitor has actually stayed. A view fired on load
     * counts every bounce and every prefetch as interest, which is exactly the noise that makes a
     * recommender worse rather than better.
     */
    function sendView() {
        if (state.viewSent || !state.item) {
            return;
        }

        state.viewSent = true;

        send({
            kind: 'detailview',
            itemId: state.item,
            duration: seconds(),
            recommId: attributionFor(state.item)
        }, false);
    }

    function sendFinalDuration() {
        if (!state.item || !state.viewSent) {
            return;
        }

        var duration = seconds();

        // Recombee keys an interaction on (user, item, timestamp) and the first view already
        // landed, so this is only worth sending when the visitor genuinely stayed longer.
        if (duration < config.delay + 5) {
            return;
        }

        send({ kind: 'detailview', itemId: state.item, duration: duration, update: true }, true);
    }

    // ── configuration ────────────────────────────────────────────────────────────────────────

    /**
     * Whether a URL points at the page's own origin. Without a URL parser only a path-relative
     * URL can be proven local, so the answer fails closed.
     */
    function sameOrigin(url) {
        if (typeof url !== 'string' || url === '') {
            return false;
        }

        // Browsers ignore leading control characters, strip tabs and newlines anywhere, and treat
        // a backslash like a slash, so `/\evil.test` is protocol-relative by the time it is used.
        var cleaned = url.replace(/^[\u0000- ]+/, '').replace(/[\t\n\r]/g, '');
        var base = window.location && window.location.href;

        if (typeof window.URL === 'function' && base) {
            try {
                var target = new window.URL(cleaned, base);
                var page = new window.URL(base);

                return target.protocol === page.protocol && target.host === page.host;
            } catch (e) {
                return false;
            }
        }

        return /^\/(?![\/\\])/.test(cleaned);
    }

    /**
     * The configuration `{{ craft.bee.runtimeConfig() }}` writes into the page, for a site that
     * bundles this file itself. Page-specific, never visitor-specific, so the page stays cacheable.
     *
     * Read from `<body>` or `<html>` and nowhere else. Pages render user content through
     * sanitisers that keep `data-*` attributes, and a forged `data-bee-config` earlier in the
     * document would otherwise win. The endpoint must also be on this site, so even a forged
     * config cannot send the visitor's browsing somewhere else.
     */
    function pageConfig() {
        var nodes = [document.body, document.documentElement];

        for (var i = 0; i < nodes.length; i++) {
            var raw = nodes[i] && nodes[i].getAttribute ? nodes[i].getAttribute('data-bee-config') : null;

            if (!raw) {
                continue;
            }

            try {
                var parsed = JSON.parse(raw);

                if (!parsed || typeof parsed !== 'object') {
                    return {};
                }

                if (parsed.hasOwnProperty('endpoint') && !sameOrigin(parsed.endpoint)) {
                    delete parsed.endpoint;
                }

                return parsed;
            } catch (e) {
                return {};
            }
        }

        return {};
    }

    function configure(options) {
        for (var key in options || {}) {
            if (options.hasOwnProperty(key)) {
                config[key] = options[key];
            }
        }
    }

    // ── public API ───────────────────────────────────────────────────────────────────────────

    var Bee = {
        /**
         * Start tracking. Bee calls this itself when it injects the runtime; a site that bundles
         * the file calls it with no arguments and the page's `data-bee-config` supplies the rest.
         * Anything passed here wins over the page.
         */
        start: function (options) {
            if (state.started) {
                return;
            }

            state.started = true;

            configure(pageConfig());
            configure(options);

            document.addEventListener('click', onClick, true);

            state.item = Bee.pageItem();

            if (!state.item) {
                return;
            }

            resume();

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    accrue();
                } else {
                    resume();
                }
            });

            window.addEventListener('pagehide', function () {
                accrue();
                sendFinalDuration();
            });

            state.viewTimer = window.setTimeout(sendView, Math.max(0, config.delay) * 1000);
        },

        /**
         * The item this page is about.
         *
         * Explicit `data-bee-page-item` wins; otherwise the first recommendable thing on the page
         * is not guessed at — guessing wrong writes a false signal that cannot be taken back.
         */
        pageItem: function () {
            var node = document.querySelector('[data-bee-page-item]');

            return node ? node.getAttribute('data-bee-page-item') : null;
        },

        track: function (kind, itemId, extra) {
            var payload = { kind: kind, itemId: itemId };

            for (var key in extra || {}) {
                if (extra.hasOwnProperty(key)) {
                    payload[key] = extra[key];
                }
            }

            if (!payload.recommId) {
                payload.recommId = attributionFor(itemId);
            }

            return send(payload, false);
        },

        view: function (itemId, duration) {
            return Bee.track('detailview', itemId, { duration: duration || 0 });
        },

        cartAdd: function (itemId, amount, price) {
            return Bee.track('cartaddition', itemId, { amount: amount || 1, price: price });
        },

        bookmark: function (itemId) {
            return Bee.track('bookmark', itemId, {});
        },

        rate: function (itemId, rating) {
            return Bee.track('rating', itemId, { rating: rating });
        },

        portion: function (itemId, portion) {
            return Bee.track('viewportion', itemId, { portion: portion });
        },

        /** Stop tracking for this page — for a consent banner that is declined after load. */
        stop: function () {
            if (state.viewTimer) {
                window.clearTimeout(state.viewTimer);
            }

            state.item = null;
            state.viewSent = true;
        }
    };

    window.Bee = Bee;

    // For a site that bundles the runtime: a CommonJS bundler gets the same object as the global.
    // ES-module bundlers use bee.mjs instead, which wraps this file rather than duplicating it.
    if (typeof module === 'object' && module && module.exports) {
        module.exports = Bee;
    }
})(window, document);
