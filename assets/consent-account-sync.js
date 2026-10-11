(function () {
    'use strict';

    var cfg = window.DendrilaPrivacyConsent || window.PixelTrackersManagerConsent || {};
    var cross = cfg.crossDevice || {};
    var resolution = window.DendrilaPrivacyCrossDeviceResolution || {};
    var seenKey = 'dendrila_privacy_cross_device_seen_v1';

    if (!cross.active || cfg.preview || cfg.testMode) {
        return;
    }

    function currentChoice() {
        var api = window.DendrilaPrivacyConsentAPI || window.PixelTrackersManagerConsentAPI;
        return api && typeof api.getChoice === 'function' ? api.getChoice() : null;
    }

    function rememberVersion(version) {
        if (!version) { return; }
        try { localStorage.setItem(seenKey, String(version)); } catch (e) {}
    }

    function seenVersion() {
        try { return localStorage.getItem(seenKey) || ''; } catch (e) { return ''; }
    }

    function postChoice(choice, reason) {
        if (!choice || !cross.ajaxUrl || !cross.nonce || typeof window.fetch !== 'function') {
            return;
        }
        var body = new URLSearchParams();
        body.set('action', 'dendrila_privacy_cross_device_save');
        body.set('nonce', cross.nonce);
        body.set('statistics', choice.statistics ? '1' : '');
        body.set('external', choice.external ? '1' : '');
        body.set('marketing', choice.marketing ? '1' : '');
        body.set('fingerprint', choice.fingerprint || '');
        body.set('reason', reason || 'user_choice');

        window.fetch(cross.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (payload && payload.success && payload.data && payload.data.accountVersion) {
                cross.accountVersion = payload.data.accountVersion;
                rememberVersion(cross.accountVersion);
            }
        }).catch(function () {
            // A failed account sync must never alter the local consent choice.
        });
    }

    function noticeText(reason) {
        if (reason === 'account_restored' || reason === 'account_wins') {
            return 'Vos choix enregistrés sur votre compte ont été appliqués sur cet appareil. Vous pouvez les modifier à tout moment via « Gérer mes choix ».';
        }
        if (reason === 'device_wins') {
            return 'Les choix faits sur cet appareil ont remplacé ceux de votre compte et seront appliqués à vos autres appareils connectés.';
        }
        if (reason === 'account_created') {
            return 'Les choix faits sur cet appareil ont été associés à votre compte et seront appliqués à vos autres appareils connectés.';
        }
        return 'Vos choix de traceurs sont associés à votre compte et peuvent être appliqués sur vos appareils connectés.';
    }

    function showNotice(reason) {
        if (!document.body || !reason || document.querySelector('.ptm-cross-device-notice')) {
            return;
        }
        var root = document.createElement('div');
        root.className = 'ptm-cross-device-notice';
        root.setAttribute('role', 'status');
        root.setAttribute('aria-live', 'polite');

        var text = document.createElement('p');
        text.textContent = noticeText(reason);
        root.appendChild(text);

        var close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Fermer cette information');
        close.textContent = '×';
        close.addEventListener('click', function () {
            if (root.parentNode) { root.parentNode.removeChild(root); }
        });
        root.appendChild(close);
        document.body.appendChild(root);
    }

    function initialise() {
        var choice = currentChoice();
        if (resolution.needsSync && choice) {
            postChoice(choice, resolution.reason || 'account_created');
        }

        var reason = resolution.reason || '';
        if (!reason && cross.accountChoice && cross.accountVersion && seenVersion() !== cross.accountVersion) {
            reason = 'account_restored';
        }
        if (reason) {
            showNotice(reason);
        }
        if (cross.accountVersion) {
            rememberVersion(cross.accountVersion);
        }
    }

    document.addEventListener('pixel-trackers-manager:consent-updated', function () {
        var choice = currentChoice();
        if (choice) {
            postChoice(choice, 'user_choice');
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise);
    } else {
        initialise();
    }
}());
