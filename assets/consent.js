(function () {
    'use strict';

    var cfg = window.DendrilaPrivacyConsent || window.PixelTrackersManagerConsent || {};
    var storageKey = cfg.storageKey || 'pixel_trackers_manager_consent_v1';
    var current = null;
    var lastFocusedElement = null;
    var mountedRoot = null;
    var accountSync = cfg.accountSync || {};

    function readChoice() {
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (!saved || !saved.savedAt) {
                return null;
            }
            if (cfg.fingerprint && saved.fingerprint !== cfg.fingerprint) {
                return null;
            }
            var age = Date.now() - Number(saved.savedAt);
            if (age > Number(cfg.retentionDays || 180) * 86400000) {
                return null;
            }
            return saved;
        } catch (e) {
            return null;
        }
    }

    function validAccountChoice(choice){if(!choice||!choice.savedAt){return null;}if(cfg.fingerprint&&String(choice.fingerprint||'')!==String(cfg.fingerprint||'')){return null;}return{statistics:choice.statistics===true,external:choice.external===true,marketing:choice.marketing===true,savedAt:choice.savedAt,fingerprint:String(choice.fingerprint||'')};}
    function sameChoice(a,b){return!!a&&!!b&&(a.statistics===true)===(b.statistics===true)&&(a.external===true)===(b.external===true)&&(a.marketing===true)===(b.marketing===true);}
    function writeLocalChoice(choice){if(!choice||cfg.preview||cfg.testMode){return;}try{localStorage.setItem(storageKey,JSON.stringify(choice));}catch(e){}}
    function resolveAccountChoice(localChoice){var result={choice:localChoice,storeLocal:false,pushAccount:false,message:''};if(cfg.preview||cfg.testMode||!accountSync.enabled||!accountSync.loggedIn){return result;}var accountChoice=validAccountChoice(accountSync.accountChoice);if(!accountChoice){if(localChoice){result.pushAccount=true;result.message='Votre choix actuel est synchronisé avec votre compte pour être retrouvé sur vos autres appareils.';}return result;}if(!localChoice){result.choice=accountChoice;result.storeLocal=true;result.message='Vos choix enregistrés dans votre compte ont été appliqués sur cet appareil.';return result;}if(sameChoice(localChoice,accountChoice)){if(Number(accountChoice.savedAt||0)>Number(localChoice.savedAt||0)){result.choice=accountChoice;result.storeLocal=true;}else if(Number(localChoice.savedAt||0)>Number(accountChoice.savedAt||0)){result.pushAccount=true;}return result;}if(String(accountSync.strategy||'')==='latest_wins'&&Number(localChoice.savedAt||0)>Number(accountChoice.savedAt||0)){result.pushAccount=true;result.message='Le choix plus récent de cet appareil a été synchronisé avec votre compte.';return result;}result.choice=accountChoice;result.storeLocal=true;result.message='Le choix enregistré dans votre compte a été appliqué sur cet appareil. Vous pouvez le modifier à tout moment.';return result;}
    function ensureSyncNotice(){var notice=document.getElementById('dendrila-privacy-consent-sync-notice');if(notice||!document.body){return notice;}notice=document.createElement('div');notice.id='dendrila-privacy-consent-sync-notice';notice.className='dendrila-privacy-consent-sync-notice';notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');notice.hidden=true;var text=document.createElement('span');text.setAttribute('data-dendrila-sync-message','1');notice.appendChild(text);var button=document.createElement('button');button.type='button';button.className='ptm-consent-open';button.textContent='Gérer mes choix';button.addEventListener('click',function(){openPreferences();notice.hidden=true;});notice.appendChild(button);document.body.appendChild(notice);return notice;}
    function showSyncNotice(message,tone){if(!message){return;}var notice=ensureSyncNotice();if(!notice){return;}var text=notice.querySelector('[data-dendrila-sync-message]');if(text){text.textContent=message;}notice.setAttribute('data-tone',tone||'info');notice.hidden=false;}
    function persistChoiceToAccount(choice,showSuccess,reason){if(!choice||cfg.preview||cfg.testMode||!accountSync.enabled||!accountSync.loggedIn||!accountSync.endpoint||!accountSync.nonce||typeof window.fetch!=='function'){return;}var payload={statistics:choice.statistics===true,external:choice.external===true,marketing:choice.marketing===true,savedAt:choice.savedAt,fingerprint:choice.fingerprint||'',syncReason:reason||'background'};window.fetch(accountSync.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':accountSync.nonce},body:JSON.stringify(payload)}).then(function(response){if(!response.ok){throw new Error('sync-failed');}return response.json();}).then(function(saved){if(saved&&saved.choice){accountSync.accountChoice=saved.choice;current=saved.choice;writeLocalChoice(saved.choice);syncEarlyGuard(saved.choice);}if(showSuccess){showSyncNotice('Vos choix sont enregistrés sur cet appareil et dans votre compte.','success');}}).catch(function(){showSyncNotice('Votre choix est enregistré sur cet appareil, mais la synchronisation avec votre compte a échoué.','error');});}

    function allowed(category) {
        return !!(current && current[category] === true);
    }

    function classify(url) {
        var value = String(url || '').toLowerCase();
        var domains = cfg.domains || {};
        var category;
        var i;

        for (category in domains) {
            if (!Object.prototype.hasOwnProperty.call(domains, category)) {
                continue;
            }
            for (i = 0; i < domains[category].length; i += 1) {
                if (value.indexOf(String(domains[category][i]).toLowerCase()) !== -1) {
                    return category;
                }
            }
        }
        return '';
    }

    function blockNode(node) {
        if (!node || node.nodeType !== 1) {
            return node;
        }

        var tag = (node.tagName || '').toLowerCase();
        var src = '';
        try {
            src = node.src || node.getAttribute('src') || '';
        } catch (e) {
            src = '';
        }

        var category = classify(src);
        if (!category || allowed(category)) {
            return node;
        }

        if (tag === 'script') {
            if (src) {
                node.setAttribute('data-ptm-src', src);
                node.removeAttribute('src');
            }
            var type = node.getAttribute('type');
            if (type && type !== 'text/plain') {
                node.setAttribute('data-ptm-type', type);
            }
            node.setAttribute('type', 'text/plain');
        } else if (tag === 'iframe') {
            if (src) {
                node.setAttribute('data-ptm-src', src);
                node.setAttribute('src', 'about:blank');
            }
        } else if (tag === 'img') {
            if (src) {
                node.setAttribute('data-ptm-src', src);
                node.removeAttribute('src');
            }
        }

        node.setAttribute('data-ptm-category', category);
        node.setAttribute('data-ptm-blocked', '1');
        return node;
    }

    var nativeAppendChild = Element.prototype.appendChild;
    var nativeInsertBefore = Element.prototype.insertBefore;
    var previousSetAttribute = Element.prototype.setAttribute;

    Element.prototype.setAttribute = function (name, value) {
        var tag = (this.tagName || '').toLowerCase();
        if (String(name).toLowerCase() === 'src' && (tag === 'script' || tag === 'iframe' || tag === 'img')) {
            var category = classify(value);
            if (category && !allowed(category)) {
                previousSetAttribute.call(this, 'data-ptm-src', value);
                previousSetAttribute.call(this, 'data-ptm-category', category);
                previousSetAttribute.call(this, 'data-ptm-blocked', '1');
                if (tag === 'script') {
                    previousSetAttribute.call(this, 'type', 'text/plain');
                    return;
                }
                if (tag === 'iframe') {
                    return previousSetAttribute.call(this, 'src', 'about:blank');
                }
                if (tag === 'img') {
                    return;
                }
            }
        }
        return previousSetAttribute.call(this, name, value);
    };

    Element.prototype.appendChild = function (node) {
        return nativeAppendChild.call(this, blockNode(node));
    };

    Element.prototype.insertBefore = function (node, referenceNode) {
        return nativeInsertBefore.call(this, blockNode(node), referenceNode);
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
                        previousSetAttribute.call(this, 'data-ptm-src', value);
                        previousSetAttribute.call(this, 'data-ptm-category', category);
                        previousSetAttribute.call(this, 'data-ptm-blocked', '1');
                        if (tag === 'script') {
                            previousSetAttribute.call(this, 'type', 'text/plain');
                        } else if (tag === 'iframe') {
                            descriptor.set.call(this, 'about:blank');
                        }
                        return;
                    }
                    return descriptor.set.call(this, value);
                }
            });
        } catch (e) {
            // Server-side neutralisation remains available if a browser refuses this guard.
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

    function testChoice() {
        if (!cfg.testMode) { return null; }
        if (cfg.testMode === 'accept') { return {statistics:true, external:true, marketing:true, savedAt:Date.now(), fingerprint:cfg.fingerprint || ''}; }
        if (cfg.testMode === 'statistics') { return {statistics:true, external:false, marketing:false, savedAt:Date.now(), fingerprint:cfg.fingerprint || ''}; }
        return {statistics:false, external:false, marketing:false, savedAt:Date.now(), fingerprint:cfg.fingerprint || ''};
    }

    current = cfg.preview ? null : (testChoice() || readChoice());

    function activateAllowedResources() {
        document.querySelectorAll('[data-ptm-blocked="1"][data-ptm-category]').forEach(function (element) {
            var category = element.getAttribute('data-ptm-category');
            if (!allowed(category)) {
                return;
            }

            var tag = element.tagName.toLowerCase();
            var src = element.getAttribute('data-ptm-src');

            if (tag === 'script') {
                var replacement = document.createElement('script');
                Array.prototype.slice.call(element.attributes).forEach(function (attribute) {
                    if (['type', 'data-ptm-src', 'data-ptm-category', 'data-ptm-blocked', 'data-ptm-type'].indexOf(attribute.name) === -1) {
                        replacement.setAttribute(attribute.name, attribute.value);
                    }
                });
                var originalType = element.getAttribute('data-ptm-type');
                if (originalType) {
                    replacement.type = originalType;
                }
                if (src) {
                    replacement.src = src;
                }
                if (element.textContent) {
                    replacement.textContent = element.textContent;
                }
                if (element.parentNode) {
                    element.parentNode.replaceChild(replacement, element);
                }
                return;
            }

            if (src) {
                element.setAttribute('src', src);
            }
            element.removeAttribute('data-ptm-blocked');
        });
    }

    var diviMapsInitialised = false;
    var diviRecaptchaInitialised = false;

    function reinitialiseDiviIntegrations() {
        // Divi modules may have attempted to initialise while an optional third-party
        // resource was still blocked. Once the visitor allows it, give Divi a chance
        // to initialise the affected module again. This adapter is deliberately small
        // and only calls public/global runtime hooks when Divi itself exposes them.
        if (allowed('external') && document.querySelector('.et_pb_map_container') && !diviMapsInitialised) {
            var mapAttempts = 0;
            var initMaps = function () {
                mapAttempts += 1;
                if (window.jQuery && typeof window.et_pb_map_init === 'function') {
                    window.jQuery('.et_pb_map_container').each(function () {
                        try { window.et_pb_map_init(window.jQuery(this)); } catch (e) {}
                    });
                    diviMapsInitialised = true;
                    return;
                }
                if (mapAttempts < 8) { window.setTimeout(initMaps, 500); }
            };
            window.setTimeout(initMaps, 180);
        }

        // reCAPTCHA is not force-classified by PTM because its legal treatment depends
        // on context. If another rule has delayed it and Divi exposes its own runtime
        // reinitializer after consent, use that hook without making reCAPTCHA a tracker
        // category by default.
        if (!diviRecaptchaInitialised && document.querySelector('.et_pb_contact_form_container')) {
            var recaptchaAttempts = 0;
            var initRecaptcha = function () {
                recaptchaAttempts += 1;
                var api = window.etCore && window.etCore.api && window.etCore.api.spam && window.etCore.api.spam.recaptcha;
                if (api && typeof api.init === 'function' && (window.grecaptcha || document.querySelector('script[src*="recaptcha"]'))) {
                    try { api.init(); diviRecaptchaInitialised = true; } catch (e) {}
                    return;
                }
                if (recaptchaAttempts < 6) { window.setTimeout(initRecaptcha, 500); }
            };
            window.setTimeout(initRecaptcha, 220);
        }
    }

    function announceConsentUpdate() {
        try {
            document.dispatchEvent(new CustomEvent('pixel-trackers-manager:consent-updated', {
                detail: {
                    statistics: allowed('statistics'),
                    external: allowed('external'),
                    marketing: allowed('marketing')
                }
            }));
        } catch (e) {}
    }

    function announceDialogEvent(name) {
        try {
            document.dispatchEvent(new CustomEvent('pixel-trackers-manager:consent-' + name));
        } catch (e) {}
    }

    function syncEarlyGuard(choice) {
        var earlyGuard = window.DendrilaPrivacyConsentEarly || window.PixelTrackersManagerConsentEarly;
        if (earlyGuard && typeof earlyGuard.setCurrent === 'function') {
            earlyGuard.setCurrent(choice);
        }
    }

    function normaliseChoice(choice) {
        choice = choice || {};
        return {
            statistics: choice.statistics === true,
            external: choice.external === true,
            marketing: choice.marketing === true
        };
    }

    function saveChoice(choice) {
        choice = normaliseChoice(choice);
        choice.savedAt = Date.now();
        choice.fingerprint = cfg.fingerprint || '';
        writeLocalChoice(choice);
        current = choice;
        syncEarlyGuard(choice);
        activateAllowedResources();
        reinitialiseDiviIntegrations();
        announceConsentUpdate();
        persistChoiceToAccount(choice, true, 'explicit');
        hideBanner(true);
    }

    function saveAll(value) {
        saveChoice({
            statistics: value,
            external: value,
            marketing: value
        });
    }

    function bannerRoot() {
        if (mountedRoot) {
            return mountedRoot;
        }
        mountedRoot = document.getElementById('pixel-trackers-manager-consent');
        return mountedRoot;
    }

    function mountConsentRoot() {
        var root = bannerRoot();
        if (!root || !document.body) {
            return root;
        }
        if (root.parentNode !== document.body) {
            nativeAppendChild.call(document.body, root);
        }
        root.setAttribute('data-ptm-mounted', 'body');
        return root;
    }

    function syncPreferenceToggles(root) {
        if (!root) {
            return;
        }
        root.querySelectorAll('[data-ptm-category-toggle]').forEach(function (toggle) {
            var category = toggle.getAttribute('data-ptm-category-toggle');
            toggle.checked = !!(current && current[category] === true);
        });
    }

    function setPreferencesVisible(root, visible) {
        if (!root) {
            return;
        }
        var preferences = root.querySelector('.ptm-consent-preferences');
        var customize = root.querySelector('[data-ptm-action="customize"]');
        if (!preferences) {
            return;
        }
        if (visible) {
            syncPreferenceToggles(root);
        }
        preferences.hidden = !visible;
        if (customize) {
            customize.setAttribute('aria-expanded', visible ? 'true' : 'false');
        }
    }

    function hideBanner(restoreFocus) {
        var root = bannerRoot();
        if (root) {
            root.hidden = true;
            root.setAttribute('aria-hidden', 'true');
        }
        announceDialogEvent('closed');
        if (restoreFocus && lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            try {
                lastFocusedElement.focus();
            } catch (e) {
                // No action needed if the previous element disappeared.
            }
        }
    }

    function showBanner(force, openPreferences) {
        var root = mountConsentRoot();
        if (!root) {
            return;
        }
        if (!force && current && !cfg.preview) {
            root.hidden = true;
            root.setAttribute('aria-hidden', 'true');
            return;
        }
        lastFocusedElement = document.activeElement;
        setPreferencesVisible(root, !!openPreferences);
        root.hidden = false;
        root.setAttribute('aria-hidden', 'false');
        announceDialogEvent('opened');
        var dialog = root.querySelector('.ptm-consent-dialog');
        if (dialog) {
            window.setTimeout(function () {
                dialog.focus();
            }, 0);
        }
    }

    function openPreferences() {
        showBanner(true, true);
    }

    function closeWithoutChoice() {
        try {
            sessionStorage.setItem('pixel_trackers_manager_consent_closed', '1');
        } catch (e) {
            // Session storage is only a convenience to avoid reopening after a close.
        }
        hideBanner(true);
    }

    function trapKeyboard(event, root) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeWithoutChoice();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        var focusable = Array.prototype.slice.call(root.querySelectorAll(
            'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
        )).filter(function (element) {
            return !element.hidden && element.offsetParent !== null;
        });

        if (!focusable.length) {
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function inheritSiteStyle() {
        if (cfg.style !== 'inherit') {
            return;
        }

        var root = bannerRoot();
        if (!root || !document.body) {
            return;
        }

        var bodyStyle = window.getComputedStyle(document.body);
        var representativeButton = document.querySelector(
            '.elementor-button, .et_pb_button, .bricks-button, .fl-button, .vc_btn3, .wp-element-button, button[type="submit"], input[type="submit"]'
        );
        var buttonStyle = representativeButton ? window.getComputedStyle(representativeButton) : null;

        root.style.setProperty('--pixel-trackers-manager-font', bodyStyle.fontFamily || 'inherit');
        if (buttonStyle && buttonStyle.borderRadius) {
            root.style.setProperty('--pixel-trackers-manager-radius', buttonStyle.borderRadius);
        }

        // Brand colours are deliberately not copied. Accept and reject must remain equivalent.
    }

    function eventClosest(event, selector) {
        var target = event.target;
        if (!target) {
            return null;
        }
        if (target.nodeType === 3) {
            target = target.parentElement;
        }
        return target && typeof target.closest === 'function' ? target.closest(selector) : null;
    }

    function handleDocumentClick(event) {
        var openControl = eventClosest(event, '[data-ptm-consent-open], .ptm-consent-open');
        if (openControl) {
            event.preventDefault();
            openPreferences();
            return;
        }

        var actionControl = eventClosest(event, '#pixel-trackers-manager-consent [data-ptm-action], #pixel-trackers-manager-consent .ptm-consent-close');
        if (!actionControl) {
            return;
        }

        var root = bannerRoot();
        if (!root || !root.contains(actionControl)) {
            return;
        }

        event.preventDefault();
        var action = actionControl.getAttribute('data-ptm-action');
        if (!action && actionControl.classList.contains('ptm-consent-close')) {
            action = 'close';
        }

        if (action === 'reject') {
            saveAll(false);
        } else if (action === 'accept') {
            saveAll(true);
        } else if (action === 'customize') {
            var preferences = root.querySelector('.ptm-consent-preferences');
            setPreferencesVisible(root, !!(preferences && preferences.hidden));
        } else if (action === 'save') {
            var choice = { statistics: false, external: false, marketing: false };
            root.querySelectorAll('[data-ptm-category-toggle]').forEach(function (toggle) {
                choice[toggle.getAttribute('data-ptm-category-toggle')] = !!toggle.checked;
            });
            saveChoice(choice);
        } else if (action === 'close') {
            closeWithoutChoice();
        }
    }

    function exposePublicApi() {
        var api = {
            open: function (mode) {
                showBanner(true, mode === true || mode === 'preferences' || !!(mode && mode.preferences));
            },
            openPreferences: openPreferences,
            close: function () {
                hideBanner(true);
            },
            getChoice: function () {
                if (!current) {
                    return null;
                }
                return {
                    statistics: current.statistics === true,
                    external: current.external === true,
                    marketing: current.marketing === true,
                    savedAt: current.savedAt || null,
                    fingerprint: current.fingerprint || ''
                };
            },
            saveChoice: function (choice) {
                saveChoice(choice);
            }
        };

        window.DendrilaPrivacyConsentAPI = api;
        // Pre-publication API alias retained so test sites and integrations do not break.
        window.PixelTrackersManagerConsentAPI = api;
        cfg.open = api.open;
        cfg.openPreferences = api.openPreferences;
        cfg.close = api.close;
        cfg.getChoice = api.getChoice;
        cfg.saveChoice = api.saveChoice;
        window.DendrilaPrivacyConsent = cfg;
        window.PixelTrackersManagerConsent = cfg;
    }

    function initialise() {
        var localChoice = cfg.preview ? null : (testChoice() || readChoice());
        var accountResolution = resolveAccountChoice(localChoice);
        current = accountResolution.choice;
        if (accountResolution.storeLocal && current) { writeLocalChoice(current); }
        syncEarlyGuard(current);
        mountConsentRoot();
        activateAllowedResources();
        reinitialiseDiviIntegrations();
        inheritSiteStyle();
        if (accountResolution.pushAccount && current) { persistChoiceToAccount(current, false, 'background'); }
        if (accountResolution.message) { showSyncNotice(accountResolution.message, 'info'); }

        var root = bannerRoot();
        if (root) {
            root.addEventListener('keydown', function (event) {
                trapKeyboard(event, root);
            });
        }

        var closedThisSession = false;
        try {
            closedThisSession = sessionStorage.getItem('pixel_trackers_manager_consent_closed') === '1';
        } catch (e) {
            closedThisSession = false;
        }

        if (!current && !closedThisSession) {
            showBanner(false, false);
        }
    }

    // Register this as soon as the head script executes. Builders can attach their own
    // bubbling handlers later; PTM sees consent controls first in the capture phase.
    document.addEventListener('click', handleDocumentClick, true);
    exposePublicApi();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise);
    } else {
        initialise();
    }
}());
