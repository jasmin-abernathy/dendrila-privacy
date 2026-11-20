(function () {
    'use strict';

    var config = window.DendrilaPrivacyConsentEarlyConfig || window.PixelTrackersManagerConsentEarlyConfig || {};
    var marker = document.currentScript;
    var retentionDays = Number(config.retentionDays || (marker ? marker.getAttribute('data-retention-days') : 180) || 180);
    var storageKey = 'pixel_trackers_manager_consent_v2';
    var fingerprint = String(config.fingerprint || (marker ? marker.getAttribute('data-consent-fingerprint') : '') || '');
    var testMode = String(config.testMode || (marker ? marker.getAttribute('data-test-mode') : '') || '');
    var domains = {
        statistics: [
            'google-analytics.com', 'googletagmanager.com/gtag/js', 'analytics.google.com',
            'clarity.ms', 'hotjar.com', 'static.hotjar.com', 'plausible.io', 'matomo.js', 'piwik.js'
        ],
        marketing: [
            'googletagmanager.com/gtm.js', 'connect.facebook.net', 'facebook.com/tr',
            'analytics.tiktok.com', 'snap.licdn.com', 'linkedin.com/insight',
            's.pinimg.com/ct', 'bat.bing.com', 'ads-twitter.com'
        ],
        external: [
            'youtube.com/embed', 'youtube-nocookie.com/embed', 'player.vimeo.com',
            'google.com/maps/embed', 'maps.google.com/maps/embed', 'maps.googleapis.com'
        ]
    };
    var current = testChoice() || readChoice();
    var nativeSetAttribute = Element.prototype.setAttribute;
    var nativeRemoveAttribute = Element.prototype.removeAttribute;
    if (config.wpConsentApi) {
        // Define opt-in before the API's footer script can evaluate tracking plugins.
        window.wp_consent_type = 'optin';
    }

    function testChoice() {
        if (!testMode) { return null; }
        if (testMode === 'accept') { return {statistics:true, external:true, marketing:true, savedAt:Date.now(), fingerprint:fingerprint}; }
        if (testMode === 'statistics') { return {statistics:true, external:false, marketing:false, savedAt:Date.now(), fingerprint:fingerprint}; }
        return {statistics:false, external:false, marketing:false, savedAt:Date.now(), fingerprint:fingerprint};
    }

    function readChoice() {
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (!saved || !saved.savedAt) {
                return null;
            }
            if (fingerprint && saved.fingerprint !== fingerprint) {
                return null;
            }
            if (Date.now() - Number(saved.savedAt) > retentionDays * 86400000) {
                return null;
            }
            return saved;
        } catch (e) {
            return null;
        }
    }

    function allowed(category) {
        return !!(current && current[category] === true);
    }

    function classify(url) {
        var value = String(url || '').toLowerCase();
        var category;
        var i;
        for (category in domains) {
            if (!Object.prototype.hasOwnProperty.call(domains, category)) {
                continue;
            }
            for (i = 0; i < domains[category].length; i += 1) {
                if (value.indexOf(domains[category][i]) !== -1) {
                    return category;
                }
            }
        }
        return '';
    }

    function rememberBlocked(element, value, category) {
        nativeSetAttribute.call(element, 'data-ptm-src', value);
        nativeSetAttribute.call(element, 'data-ptm-category', category);
        nativeSetAttribute.call(element, 'data-ptm-blocked', '1');
        if ((element.tagName || '').toLowerCase() === 'script') {
            var type = element.getAttribute('type');
            if (type && type !== 'text/plain') {
                nativeSetAttribute.call(element, 'data-ptm-type', type);
            }
            nativeSetAttribute.call(element, 'type', 'text/plain');
        }
    }

    // Neutralise nested resources before they enter the live DOM. A MutationObserver
    // alone is too late: the browser may start an iframe/image request on insertion.
    function blockResource(element) {
        if (!element || element.nodeType !== 1) { return; }
        var tag = (element.tagName || '').toLowerCase();
        var attribute = tag === 'link' ? 'href' : 'src';
        if (tag !== 'script' && tag !== 'iframe' && tag !== 'img' && tag !== 'link') { return; }
        if (tag === 'link' && !/\\b(?:preload|modulepreload|prefetch|preconnect|dns-prefetch)\\b/i.test(element.getAttribute('rel') || '')) { return; }
        var src = element.getAttribute(attribute) || '';
        var category = classify(src);
        if (!src || !category || allowed(category)) { return; }
        rememberBlocked(element, src, category);
        if (tag === 'link') {
            nativeSetAttribute.call(element, 'data-ptm-href', src);
            nativeRemoveAttribute.call(element, 'href');
        } else if (tag === 'iframe') {
            nativeSetAttribute.call(element, 'src', 'about:blank');
        } else {
            nativeRemoveAttribute.call(element, 'src');
        }
    }

    function blockSubtree(node) {
        if (!node || (node.nodeType !== 1 && node.nodeType !== 11)) { return node; }
        blockResource(node);
        if (typeof node.querySelectorAll === 'function') {
            node.querySelectorAll('script[src],iframe[src],img[src],link[href]').forEach(blockResource);
        }
        return node;
    }

    // Intercept common DOM APIs synchronously, including DocumentFragment descendants.
    ['appendChild', 'insertBefore', 'replaceChild', 'append', 'prepend', 'replaceChildren', 'insertAdjacentElement'].forEach(function (method) {
        if (typeof Element.prototype[method] !== 'function') { return; }
        var nativeMethod = Element.prototype[method];
        Element.prototype[method] = function () {
            var args = Array.prototype.slice.call(arguments);
            if (method === 'append' || method === 'prepend' || method === 'replaceChildren') {
                args.forEach(blockSubtree);
            } else if (method === 'insertAdjacentElement') {
                blockSubtree(args[1]);
            } else {
                blockSubtree(args[0]);
            }
            return nativeMethod.apply(this, args);
        };
    });

    // Parse potentially tracking markup in an inert template before assigning it
    // to innerHTML / insertAdjacentHTML. Unrelated builder markup uses native APIs.
    var htmlDescriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
    function sanitiseMarkup(markup) {
        var html = String(markup);
        if (!classify(html) || !/<(?:script|iframe|img|link)\\b/i.test(html)) { return html; }
        var template = document.createElement('template');
        htmlDescriptor.set.call(template, html);
        blockSubtree(template.content);
        return htmlDescriptor.get.call(template);
    }
    if (htmlDescriptor && htmlDescriptor.get && htmlDescriptor.set && htmlDescriptor.configurable) {
        Object.defineProperty(Element.prototype, 'innerHTML', {
            configurable: true, enumerable: htmlDescriptor.enumerable,
            get: htmlDescriptor.get,
            set: function (html) { return htmlDescriptor.set.call(this, sanitiseMarkup(html)); }
        });
    }
    if (typeof Element.prototype.insertAdjacentHTML === 'function' && htmlDescriptor && htmlDescriptor.set) {
        var nativeInsertAdjacentHTML = Element.prototype.insertAdjacentHTML;
        Element.prototype.insertAdjacentHTML = function (position, html) {
            return nativeInsertAdjacentHTML.call(this, position, sanitiseMarkup(html));
        };
    }

    Element.prototype.setAttribute = function (name, value) {
        var tag = (this.tagName || '').toLowerCase();
        var attribute = String(name || '').toLowerCase();
        if ((attribute === 'src' && (tag === 'script' || tag === 'iframe' || tag === 'img')) || (attribute === 'href' && tag === 'link' && /\b(?:preload|modulepreload|prefetch|preconnect|dns-prefetch)\b/i.test(this.getAttribute('rel') || ''))) {
            var category = classify(value);
            if (category && !allowed(category)) {
                rememberBlocked(this, value, category);
                if (tag === 'link') { nativeSetAttribute.call(this, 'data-ptm-href', value); return; }
                if (tag === 'iframe') {
                    return nativeSetAttribute.call(this, 'src', 'about:blank');
                }
                return;
            }
        }
        return nativeSetAttribute.call(this, name, value);
    };

    function guardSrc(prototype) {
        try {
            var descriptor = Object.getOwnPropertyDescriptor(prototype, 'src');
            if (!descriptor || !descriptor.get || !descriptor.set || descriptor.configurable === false) {
                return;
            }
            Object.defineProperty(prototype, 'src', {
                configurable: true,
                enumerable: descriptor.enumerable,
                get: descriptor.get,
                set: function (value) {
                    var category = classify(value);
                    var tag = (this.tagName || '').toLowerCase();
                    if (category && !allowed(category)) {
                        rememberBlocked(this, value, category);
                        if (tag === 'iframe') {
                            descriptor.set.call(this, 'about:blank');
                        }
                        return;
                    }
                    return descriptor.set.call(this, value);
                }
            });
        } catch (e) {
            // A browser that refuses this guard still benefits from server-side neutralisation.
        }
    }

    if (window.HTMLScriptElement) {
        guardSrc(window.HTMLScriptElement.prototype);
    }
    if (window.HTMLIFrameElement) {
        guardSrc(window.HTMLIFrameElement.prototype);
    }
    if (window.HTMLImageElement) {
        guardSrc(window.HTMLImageElement.prototype);
    }

    // Cover common network APIs used by inline tracking loaders. Known optional
    // destinations behave as unavailable until the corresponding category is allowed.
    if (typeof window.fetch === 'function') {
        var nativeFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
            var category = classify(url);
            if (category && !allowed(category)) {
                return Promise.reject(new TypeError('Request blocked until consent'));
            }
            return nativeFetch.call(this, input, init);
        };
    }

    if (window.XMLHttpRequest && window.XMLHttpRequest.prototype) {
        var nativeOpen = window.XMLHttpRequest.prototype.open;
        var nativeSend = window.XMLHttpRequest.prototype.send;
        window.XMLHttpRequest.prototype.open = function (method, url) {
            var category = classify(url);
            this.__pixelTrackersManagerBlocked = !!(category && !allowed(category));
            return nativeOpen.apply(this, arguments);
        };
        window.XMLHttpRequest.prototype.send = function () {
            if (this.__pixelTrackersManagerBlocked) {
                try { this.abort(); } catch (e) {}
                return;
            }
            return nativeSend.apply(this, arguments);
        };
    }

    if (window.navigator && typeof window.navigator.sendBeacon === 'function') {
        var nativeBeacon = window.navigator.sendBeacon.bind(window.navigator);
        window.navigator.sendBeacon = function (url, data) {
            var category = classify(url);
            if (category && !allowed(category)) {
                return false;
            }
            return nativeBeacon(url, data);
        };
    }

    var earlyApi = {
        classify: classify,
        setCurrent: function (choice) {
            current = choice || null;
        }
    };
    window.DendrilaPrivacyConsentEarly = earlyApi;
    // Pre-publication alias retained for compatibility.
    window.PixelTrackersManagerConsentEarly = earlyApi;
}());
