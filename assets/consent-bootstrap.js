(function () {
    'use strict';

    var config = window.DendrilaPrivacyConsentEarlyConfig || window.PixelTrackersManagerConsentEarlyConfig || {};
    var marker = document.currentScript;
    var retentionDays = Number(config.retentionDays || (marker ? marker.getAttribute('data-retention-days') : 180) || 180);
    var storageKey = 'pixel_trackers_manager_consent_v2';
    var fingerprint = String(config.fingerprint || (marker ? marker.getAttribute('data-consent-fingerprint') : '') || '');
    var testMode = String(config.testMode || (marker ? marker.getAttribute('data-test-mode') : '') || '');
    var crossDevice = config.crossDevice || {};
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
    var current = testChoice() || resolveCrossDeviceChoice();
    var nativeSetAttribute = Element.prototype.setAttribute;

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

    function normaliseChoice(choice) {
        if (!choice || !choice.savedAt) { return null; }
        return {
            statistics: choice.statistics === true,
            external: choice.external === true,
            marketing: choice.marketing === true,
            savedAt: Number(choice.savedAt || 0),
            fingerprint: String(choice.fingerprint || '')
        };
    }

    function validAccountChoice(choice) {
        choice = normaliseChoice(choice);
        if (!choice) { return null; }
        if (fingerprint && choice.fingerprint !== fingerprint) { return null; }
        if (Date.now() - choice.savedAt > retentionDays * 86400000) { return null; }
        return choice;
    }

    function sameChoice(a, b) {
        return !!(a && b &&
            a.statistics === b.statistics &&
            a.external === b.external &&
            a.marketing === b.marketing);
    }

    function writeLocalChoice(choice) {
        try { localStorage.setItem(storageKey, JSON.stringify(choice)); } catch (e) {}
    }

    function resolveCrossDeviceChoice() {
        var local = readChoice();
        if (!crossDevice || crossDevice.active !== true) { return local; }

        var account = validAccountChoice(crossDevice.accountChoice);
        var strategy = crossDevice.strategy === 'device_wins' ? 'device_wins' : 'account_wins';
        var resolution = {active:true, needsSync:false, reason:'', accountVersion:String(crossDevice.accountVersion || '')};

        if (!local && !account) {
            window.DendrilaPrivacyCrossDeviceResolution = resolution;
            return null;
        }
        if (!local && account) {
            writeLocalChoice(account);
            resolution.reason = 'account_restored';
            window.DendrilaPrivacyCrossDeviceResolution = resolution;
            return account;
        }
        if (local && !account) {
            resolution.needsSync = true;
            resolution.reason = 'account_created';
            window.DendrilaPrivacyCrossDeviceResolution = resolution;
            return local;
        }
        if (sameChoice(local, account)) {
            window.DendrilaPrivacyCrossDeviceResolution = resolution;
            return local;
        }
        if (strategy === 'device_wins') {
            resolution.needsSync = true;
            resolution.reason = 'device_wins';
            window.DendrilaPrivacyCrossDeviceResolution = resolution;
            return local;
        }

        writeLocalChoice(account);
        resolution.reason = 'account_wins';
        window.DendrilaPrivacyCrossDeviceResolution = resolution;
        return account;
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

    Element.prototype.setAttribute = function (name, value) {
        var tag = (this.tagName || '').toLowerCase();
        var attribute = String(name || '').toLowerCase();
        if (attribute === 'src' && (tag === 'script' || tag === 'iframe' || tag === 'img')) {
            var category = classify(value);
            if (category && !allowed(category)) {
                rememberBlocked(this, value, category);
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
