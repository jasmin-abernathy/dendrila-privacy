(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof PixelTrackersManagerScan === 'undefined') { return; }

        var startButton = document.getElementById('ptm-scan-start');
        var fullButton = document.getElementById('ptm-scan-full');
        var retryButton = document.getElementById('ptm-scan-retry-failed');
        var cancelButton = document.getElementById('ptm-scan-cancel');
        var wrapper = document.getElementById('ptm-scan-progress');
        var bar = document.getElementById('ptm-progress-bar');
        var track = document.getElementById('ptm-progress-track');
        var status = document.getElementById('ptm-scan-status');
        var percent = document.getElementById('ptm-scan-percent');
        var count = document.getElementById('ptm-scan-count');
        var errors = document.getElementById('ptm-scan-errors');
        var currentPage = document.getElementById('ptm-scan-page');

        if (!startButton || !wrapper || !bar || !track) { return; }

        var running = false;
        var cancelled = false;
        var currentMode = 'standard';

        function post(action, extra) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('nonce', PixelTrackersManagerScan.nonce);
            Object.keys(extra || {}).forEach(function (key) {
                body.set(key, extra[key]);
            });

            return fetch(PixelTrackersManagerScan.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload;
                    try { payload = JSON.parse(text); }
                    catch (e) {
                        throw new Error('Le serveur n’a pas renvoyé une réponse exploitable pendant l’analyse.');
                    }
                    if (!response.ok || !payload || payload.success !== true) {
                        var message = payload && payload.data && payload.data.message ? payload.data.message : 'L’analyse a rencontré une erreur.';
                        throw new Error(message);
                    }
                    return payload.data || {};
                });
            });
        }

        function setProgress(value, message, index, total, pageLabel, errorCount) {
            value = Math.max(0, Math.min(100, parseInt(value || 0, 10)));
            wrapper.hidden = false;
            bar.style.width = value + '%';
            track.setAttribute('aria-valuenow', String(value));
            percent.textContent = value + '%';
            if (message) { status.textContent = message; }
            if (typeof index !== 'undefined' && typeof total !== 'undefined') {
                count.textContent = index + '/' + total + ' page' + (total > 1 ? 's' : '') + ' traitée' + (index > 1 ? 's' : '');
            }
            if (currentPage) {
                var visibleLabel = (pageLabel || '').trim();
                currentPage.textContent = visibleLabel ? 'Page en cours : ' + visibleLabel : '';
                currentPage.title = visibleLabel;
                currentPage.hidden = !visibleLabel;
            }
            errors.textContent = errorCount ? errorCount + ' erreur' + (errorCount > 1 ? 's' : '') + ' de lecture' : '';
        }

        function controls(active) {
            running = active;
            startButton.disabled = active;
            if (fullButton) { fullButton.disabled = active; }
            if (retryButton) { retryButton.disabled = active; }
            startButton.textContent = active && currentMode === 'standard' ? 'Analyse en cours…' : 'Relancer l’analyse standard';
            if (fullButton) { fullButton.textContent = active && currentMode === 'full' ? 'Analyse complète en cours…' : 'Analyse complète'; }
            if (retryButton) { retryButton.textContent = active && currentMode === 'retry' ? 'Nouvel essai en cours…' : 'Réessayer uniquement ces pages'; }
            if (cancelButton) {
                cancelButton.hidden = !active;
                cancelButton.disabled = false;
            }
        }

        function fail(err) {
            running = false;
            startButton.disabled = false;
            if (fullButton) { fullButton.disabled = false; }
            if (retryButton) { retryButton.disabled = false; }
            startButton.textContent = 'Relancer l’analyse standard';
            if (fullButton) { fullButton.textContent = 'Analyse complète'; }
            if (retryButton) { retryButton.textContent = 'Réessayer uniquement ces pages'; }
            if (cancelButton) { cancelButton.hidden = false; }
            wrapper.classList.add('is-error');
            status.textContent = err && err.message ? err.message : 'Erreur pendant l’analyse.';
        }

        function cleanScanUrl() {
            try {
                var clean = new URL(window.location.href);
                clean.searchParams.delete('pixel_trackers_manager_autostart_scan');
                return clean;
            } catch (e) {
                return null;
            }
        }

        function consumeAutostartParameter() {
            var clean = cleanScanUrl();
            if (!clean || !window.history || typeof window.history.replaceState !== 'function') { return; }
            var relative = clean.pathname + (clean.search || '') + (clean.hash || '');
            window.history.replaceState(window.history.state, document.title, relative);
        }

        function finalize() {
            if (cancelled) { return Promise.resolve(); }
            setProgress(94, 'Analyse des extensions et du thème…', undefined, undefined, '', 0);
            return post('pixel_trackers_manager_scan_finalize').then(function (data) {
                if (cancelled) { return; }
                wrapper.classList.remove('is-error');
                wrapper.classList.add('is-complete');
                setProgress(100, 'Analyse terminée — ' + (data.findings || 0) + ' détection' + ((data.findings || 0) > 1 ? 's' : '') + '.', data.processed || data.total || 0, data.total || 0, '', data.errors || 0);
                controls(false);
                if (cancelButton) { cancelButton.hidden = true; }
                window.setTimeout(function () {
                    // The autostart query flag is a one-shot command. Never keep it
                    // across the post-scan refresh, otherwise the completed scan
                    // starts again immediately after reloading the dashboard.
                    var clean = cleanScanUrl();
                    if (clean) {
                        window.location.replace(clean.toString());
                    } else {
                        window.location.reload();
                    }
                }, 900);
            });
        }

        function nextStep() {
            if (cancelled) { return Promise.resolve(); }
            return post('pixel_trackers_manager_scan_step').then(function (data) {
                if (cancelled) { return; }
                setProgress(data.percent || 0, data.message || 'Analyse en cours…', data.index || 0, data.total || 0, data.currentLabel || '', data.errors || 0);
                if (data.stage === 'finalize') { return finalize(); }
                return nextStep();
            });
        }

        function startScan(mode) {
            if (running) { return; }
            currentMode = mode === 'full' ? 'full' : (mode === 'retry' ? 'retry' : 'standard');
            cancelled = false;
            wrapper.classList.remove('is-error', 'is-complete');
            controls(true);
            setProgress(1, currentMode === 'full' ? 'Préparation de l’analyse complète…' : (currentMode === 'retry' ? 'Préparation du nouvel essai…' : 'Préparation de l’analyse…'), 0, 0, '', 0);
            var extra = {mode: currentMode};
            if (currentMode === 'full') {
                var archives = document.getElementById('ptm-full-scan-archives');
                var age = document.getElementById('ptm-full-scan-age');
                extra.include_archives = archives && archives.checked ? '1' : '';
                extra.max_age_years = age ? age.value : '0';
            }
            post('pixel_trackers_manager_scan_start', extra).then(function (data) {
                setProgress(data.percent || 2, data.message || 'Préparation des pages à vérifier…', data.index || 0, data.total || 0, '', 0);
                return nextStep();
            }).catch(fail);
        }

        startButton.addEventListener('click', function () { startScan('standard'); });
        if (fullButton) { fullButton.addEventListener('click', function () { startScan('full'); }); }
        if (retryButton) { retryButton.addEventListener('click', function () { startScan('retry'); }); }

        var params = new URLSearchParams(window.location.search || '');
        var autoMode = params.get('pixel_trackers_manager_autostart_scan');
        if (autoMode === 'full' || autoMode === 'standard') {
            // Consume the command before starting. A later refresh must never
            // be able to launch the same scan for a second time.
            consumeAutostartParameter();
            window.setTimeout(function () { startScan(autoMode); }, 250);
        }

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                cancelled = true;
                cancelButton.disabled = true;
                status.textContent = 'Annulation…';
                post('pixel_trackers_manager_scan_cancel').then(function () {
                    wrapper.classList.remove('is-error');
                    setProgress(0, 'Analyse annulée.', 0, 0, '', 0);
                    controls(false);
                    cancelButton.hidden = true;
                }).catch(fail);
            });
        }
    });
}());

(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof PixelTrackersManagerScan === 'undefined') { return; }
        var cfg = PixelTrackersManagerScan;

        function field(name) { return document.querySelector('[name="legal_profile[' + name + ']"]'); }
        function setField(name, value, onlyEmpty) { var el = field(name); if (!el || (onlyEmpty && el.value)) { return; } el.value = value || ''; el.dispatchEvent(new Event('change', {bubbles:true})); }
        function setHostSource(url) {
            var hidden = document.getElementById('ptm-host-source-url');
            var saved = document.getElementById('ptm-host-source-saved');
            if (hidden) { hidden.value = url || ''; }
            if (saved) {
                saved.textContent = '';
                if (url) {
                    var a = document.createElement('a');
                    a.href = url;
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                    a.textContent = 'Vérifier les coordonnées sur la source officielle';
                    saved.appendChild(a);
                }
            }
        }
        function applyHostingProfile(item, overwrite) {
            if (!item) { return false; }
            var displayName = item.legalName && item.legalName !== item.name ? item.legalName + ' (' + item.name + ')' : (item.legalName || item.name || '');
            setField('host_name', displayName, !overwrite);
            setField('host_address', item.address || '', !overwrite);
            setField('host_phone', item.phone || '', !overwrite);
            setHostSource(item.sourceUrl || '');
            return Boolean(item.address || item.phone);
        }
        function findHostingProfileByName(value) {
            value = (value || '').trim().toLowerCase();
            if (!value || !cfg.hostingProfiles) { return null; }
            var found = null;
            Object.keys(cfg.hostingProfiles).some(function (key) {
                var item = cfg.hostingProfiles[key] || {};
                var names = [item.name || '', item.legalName || '', (item.legalName && item.name ? item.legalName + ' (' + item.name + ')' : '')];
                if (names.some(function (n) { return n.toLowerCase() === value; })) { found = item; return true; }
                return false;
            });
            return found;
        }

        var treatmentTemplates = {
            contact: {
                purpose: 'Répondre aux demandes envoyées via le formulaire de contact',
                data_categories: 'Nom et prénom si demandés, adresse e-mail, téléphone si demandé, objet et contenu du message, informations techniques nécessaires à la sécurité du formulaire',
                legal_basis: 'unknown',
                basis_detail: 'À confirmer selon votre situation : mesures précontractuelles si la demande porte sur un devis ou un contrat ; intérêt légitime possible pour une demande générale.',
                recipients: 'Personnes chargées de répondre aux demandes ; hébergeur du site ; prestataire de messagerie le cas échéant',
                retention: 'Jusqu’à la clôture de la demande, puis pendant la durée réellement nécessaire selon la relation créée et les obligations applicables',
                mandatory: 'contract',
                consequences: 'Sans les informations nécessaires pour vous recontacter et comprendre la demande, il peut être impossible d’y répondre.'
            },
            newsletter: {
                purpose: 'Envoyer la newsletter et gérer les abonnements, désabonnements et préférences',
                data_categories: 'Adresse e-mail, préférences d’abonnement, date et preuve d’inscription/consentement ; données d’engagement uniquement si le suivi est activé',
                legal_basis: 'consent',
                basis_detail: 'À vérifier selon votre mode de prospection et votre public ; ce modèle suppose une inscription volontaire à la newsletter.',
                recipients: 'Personnes chargées de la communication ; prestataire d’envoi de newsletters ; hébergeur ou prestataire technique le cas échéant',
                retention: 'Jusqu’au désabonnement ou au retrait du consentement, avec conservation limitée des preuves nécessaires à la gestion des choix et oppositions',
                mandatory: 'optional',
                consequences: 'Sans adresse e-mail, la newsletter ne peut pas être envoyée.'
            },
            accounts: {
                purpose: 'Créer, sécuriser et administrer les comptes utilisateurs du site',
                data_categories: 'Identifiant, nom si demandé, adresse e-mail, mot de passe stocké sous forme protégée, rôles et droits, journaux techniques de connexion si utilisés',
                legal_basis: 'unknown',
                basis_detail: 'À confirmer selon la fonction du compte : exécution d’un service/contrat, intérêt légitime de sécurité ou autre base pertinente.',
                recipients: 'Administrateurs habilités ; hébergeur ; prestataires techniques nécessaires au fonctionnement du compte',
                retention: 'Pendant la durée d’utilisation du compte puis selon la politique de suppression/archivage réellement appliquée',
                mandatory: 'contract',
                consequences: 'Les données nécessaires au compte doivent être fournies pour créer ou utiliser l’espace concerné.'
            },
            orders: {
                purpose: 'Traiter les commandes, paiements, livraisons et obligations de facturation',
                data_categories: 'Identité et coordonnées, adresse de facturation/livraison, contenu de la commande, montant, statut du paiement et références de transaction ; les données bancaires complètes restent chez le prestataire de paiement',
                legal_basis: 'contract',
                basis_detail: 'Exécution de la commande ; certaines données et pièces peuvent ensuite être conservées au titre d’obligations légales, notamment comptables.',
                recipients: 'Personnes chargées des commandes ; hébergeur ; prestataire de paiement ; transporteur si applicable ; prestataires comptables autorisés',
                retention: 'Pendant l’exécution de la commande puis archivage selon les obligations comptables, fiscales et de preuve réellement applicables',
                mandatory: 'contract',
                consequences: 'Sans les informations nécessaires à la commande, au paiement ou à la livraison, celle-ci ne peut pas être exécutée.'
            },
            analytics: {
                purpose: 'Mesurer l’audience et comprendre l’utilisation du site afin d’en améliorer le fonctionnement',
                data_categories: 'Identifiants en ligne et informations de navigation selon la configuration de l’outil : pages consultées, événements, caractéristiques techniques, adresse IP ou donnée dérivée le cas échéant',
                legal_basis: 'unknown',
                basis_detail: 'À déterminer selon l’outil et sa configuration : certains dispositifs nécessitent un consentement préalable, certaines mesures d’audience peuvent relever d’un régime différent sous conditions strictes.',
                recipients: 'Personnes chargées du site ; fournisseur de l’outil de mesure s’il reçoit les données ; hébergeur selon l’architecture',
                retention: 'Selon la durée réellement configurée dans l’outil de mesure et la politique de conservation appliquée',
                mandatory: 'optional',
                consequences: 'Aucune conséquence sur l’accès au service principal lorsque la mesure d’audience est facultative.'
            }
        };
        function postForm(data) {
            return fetch(cfg.ajaxUrl, {method:'POST', credentials:'same-origin', body:data}).then(function (r) {
                return r.text().then(function (text) {
                    var cleaned = (text || '').replace(/^\uFEFF/, '').trim();
                    var payload;
                    try {
                        payload = JSON.parse(cleaned);
                    } catch (parseError) {
                        var err = new Error('Le serveur n’a pas renvoyé une réponse exploitable (code HTTP ' + r.status + '). Dendrila Privacy va utiliser l’enregistrement WordPress standard.');
                        err.ptmNonJson = true;
                        err.httpStatus = r.status;
                        err.responsePreview = cleaned.slice(0, 500);
                        throw err;
                    }
                    if (!payload || payload.success !== true) {
                        var serverError = new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Erreur serveur.');
                        serverError.httpStatus = r.status;
                        throw serverError;
                    }
                    return payload.data || {};
                });
            });
        }

        document.querySelectorAll('.ptm-section-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.fetch || !cfg.sectionNonce) { return; }
                event.preventDefault();
                var navigationPending = form.dataset.ptmNavPending === '1';
                var stayOnStep = form.dataset.ptmStayOnStep === '1';
                var button = form.querySelector('[type="submit"]');
                var status = form.querySelector('.ptm-section-save-status');
                var data = new FormData(form);
                data.set('action', 'pixel_trackers_manager_save_legal_section');
                data.set('nonce', cfg.sectionNonce);
                if (button) { button.disabled = true; button.dataset.oldText = button.value || button.textContent; if ('value' in button) { button.value = 'Enregistrement…'; } else { button.textContent = 'Enregistrement…'; } }
                if (status) { status.textContent = 'Enregistrement…'; status.className = 'ptm-section-save-status is-saving'; }
                postForm(data).then(function (out) {
                    if (status) {
                        status.textContent = out.message || 'Bloc enregistré ✓';
                        status.className = 'ptm-section-save-status is-saved';
                    }
                    form.dataset.dirty = '0';
                    out.navigationPending = navigationPending;
                    out.stayOnStep = stayOnStep;
                    delete form.dataset.ptmNextStep;
                    delete form.dataset.ptmStayOnStep;
                    delete form.dataset.ptmNavPending;
                    form.dispatchEvent(new CustomEvent('ptm:section-saved', {detail: out}));
                }).catch(function (err) {
                    if (err && err.ptmNonJson) {
                        if (status) { status.textContent = 'L’enregistrement rapide est indisponible sur ce site. Dendrila Privacy utilise l’enregistrement WordPress standard…'; status.className = 'ptm-section-save-status is-saving'; }
                        var fallbackStep = form.dataset.ptmNextStep || '';
                        if (fallbackStep) {
                            var nextInput = form.querySelector('[data-ptm-next-step-input]');
                            if (nextInput) { nextInput.value = fallbackStep; }
                            try { window.sessionStorage.setItem('ptm_rgpd_wizard_step_' + (window.location.host || 'site'), fallbackStep); } catch (storageError) {}
                        }
                        // Native submit bypasses this AJAX listener and uses the already implemented, nonce-protected WordPress form handler.
                        window.setTimeout(function () { HTMLFormElement.prototype.submit.call(form); }, 30);
                        return;
                    }
                    if (status) { status.textContent = err.message || 'Échec de l’enregistrement.'; status.className = 'ptm-section-save-status is-error'; }
                    form.dispatchEvent(new CustomEvent('ptm:section-save-error', {detail: {message: err.message || 'Échec de l’enregistrement.'}}));
                }).finally(function () {
                    if (button) { button.disabled = false; if ('value' in button) { button.value = button.dataset.oldText || 'Enregistrer ce bloc'; } else { button.textContent = button.dataset.oldText || 'Enregistrer ce bloc'; } }
                });
            });
        });

        document.querySelectorAll('.ptm-section-form').forEach(function (form) {
            form.addEventListener('input', function () { form.dataset.dirty = '1'; });
            form.addEventListener('change', function () { form.dataset.dirty = '1'; });
        });

        var lookupButton = document.getElementById('ptm-company-search');
        var queryInput = document.getElementById('ptm-company-query');
        var results = document.getElementById('ptm-company-results');
        if (lookupButton && queryInput && results) {
            lookupButton.addEventListener('click', function () {
                var q = (queryInput.value || '').trim();
                if (q.length < 2) { results.textContent = 'Saisissez au moins 2 caractères.'; return; }
                results.textContent = 'Recherche dans le registre public…'; lookupButton.disabled = true;
                var data = new FormData(); data.set('action','pixel_trackers_manager_company_search'); data.set('nonce',cfg.companyNonce); data.set('q',q);
                postForm(data).then(function (out) {
                    results.textContent = '';
                    if (!out.results || !out.results.length) { results.textContent = 'Aucun résultat. Essayez le SIREN/SIRET ou une raison sociale plus précise.'; return; }
                    out.results.forEach(function (item) {
                        var card = document.createElement('button'); card.type='button'; card.className='ptm-company-result';
                        var title=document.createElement('strong'); title.textContent=item.name || 'Entreprise'; card.appendChild(title);
                        var meta=document.createElement('span'); meta.textContent=[item.siren ? 'SIREN '+item.siren : '', item.address || '', item.status ? 'Statut '+item.status : ''].filter(Boolean).join(' · '); card.appendChild(meta);
                        card.addEventListener('click', function () {
                            setField('controller_name', item.name); setField('controller_address', item.address); setField('company_siren', item.siren); setField('company_siret', item.siret); setField('company_vat', item.vat); setField('company_legal_form', item.legalForm); setField('company_activity', item.activity); setField('entity_type', item.entityType || 'company');
                            setField('company_lookup_source', cfg.companySource || 'https://recherche-entreprises.api.gouv.fr/'); setField('company_lookup_at', new Date().toISOString());
                            results.textContent = 'Données préremplies. Vérifiez-les puis enregistrez ce bloc.';
                        });
                        results.appendChild(card);
                    });
                }).catch(function (err) { results.textContent = err.message || 'Recherche impossible.'; }).finally(function () { lookupButton.disabled=false; });
            });
        }

        var prefill = document.getElementById('ptm-site-prefill');
        if (prefill) { prefill.addEventListener('click', function () {
            var d=cfg.siteDefaults || {}; setField('site_name',d.siteName,true); setField('site_url',d.siteUrl,true);
            var status=prefill.closest('form').querySelector('.ptm-section-save-status'); if(status){status.textContent='Nom et adresse du site copiés. L’e-mail d’administration WordPress n’est pas utilisé automatiquement : il peut appartenir au webmaster.'; status.className='ptm-section-save-status';}
        }); }

        var hostDetect = document.getElementById('ptm-host-detect');
        var hostResults = document.getElementById('ptm-host-results');
        if (hostDetect && hostResults && cfg.hostingNonce) {
            hostDetect.addEventListener('click', function () {
                hostResults.textContent = 'Analyse des informations réseau et du serveur…';
                hostResults.className = 'ptm-host-results is-loading';
                hostDetect.disabled = true;
                var data = new FormData();
                data.set('action', 'pixel_trackers_manager_hosting_detect');
                data.set('nonce', cfg.hostingNonce);
                postForm(data).then(function (out) {
                    hostResults.textContent = '';
                    hostResults.className = 'ptm-host-results';
                    var items = Array.isArray(out.results) ? out.results : [];
                    if (!items.length) {
                        hostResults.textContent = out.message || 'Aucun hébergeur connu n’a été identifié automatiquement.';
                        hostResults.classList.add('is-warning');
                        return;
                    }

                    var top = items[0];
                    var hostField = field('host_name');
                    var oldValue = hostField ? (hostField.value || '').trim() : '';
                    var confidentEnough = Number(top.confidence || 0) >= 50;
                    var knownCurrent = findHostingProfileByName(oldValue);
                    var autoFilled = confidentEnough && (!oldValue || oldValue === top.name || (knownCurrent && knownCurrent.slug === top.slug));
                    if (autoFilled) { applyHostingProfile(top, false); }

                    var summary = document.createElement('div');
                    summary.className = 'ptm-host-summary';
                    var strong = document.createElement('strong');
                    strong.textContent = (confidentEnough ? 'Hébergeur probable : ' : 'Correspondance possible : ') + top.name;
                    summary.appendChild(strong);
                    var meta = document.createElement('span');
                    meta.textContent = 'Confiance indicative : ' + String(top.confidence || 0) + ' %';
                    summary.appendChild(meta);
                    hostResults.appendChild(summary);
                    if (top.address || top.phone) {
                        var coords = document.createElement('p');
                        coords.className = 'ptm-host-coordinates';
                        coords.textContent = [top.legalName || '', top.address || '', top.phone ? 'Tél. ' + top.phone : ''].filter(Boolean).join(' · ');
                        hostResults.appendChild(coords);
                        if (autoFilled) {
                            var filled = document.createElement('p');
                            filled.className = 'ptm-host-filled';
                            filled.textContent = 'Nom et coordonnées disponibles préremplis. Vérifiez la source officielle avant d’enregistrer.';
                            hostResults.appendChild(filled);
                        }
                        if (top.sourceUrl) {
                            var source = document.createElement('a');
                            source.href = top.sourceUrl;
                            source.target = '_blank';
                            source.rel = 'noopener noreferrer';
                            source.textContent = 'Source officielle des coordonnées';
                            hostResults.appendChild(source);
                        }
                    } else {
                        var noCoords = document.createElement('p');
                        noCoords.className = 'ptm-host-warning';
                        noCoords.textContent = 'Dendrila Privacy reconnaît ce fournisseur mais ne dispose pas encore de coordonnées officielles vérifiées dans sa base. Complétez-les manuellement.';
                        hostResults.appendChild(noCoords);
                    }

                    if (!autoFilled) {
                        var useButton = document.createElement('button');
                        useButton.type = 'button';
                        useButton.className = 'button button-small';
                        useButton.textContent = 'Utiliser « ' + top.name + ' »';
                        useButton.addEventListener('click', function () {
                            applyHostingProfile(top, true);
                            useButton.remove();
                        });
                        hostResults.appendChild(useButton);
                    }

                    if (out.proxyDetected) {
                        var proxy = document.createElement('p');
                        proxy.className = 'ptm-host-warning';
                        proxy.textContent = 'Un proxy/CDN (par exemple Cloudflare) a aussi été détecté et peut masquer l’hébergement réel.';
                        hostResults.appendChild(proxy);
                    }
                    var note = document.createElement('p');
                    note.className = 'description';
                    note.textContent = out.message || 'Vérifiez ce résultat avant de publier vos mentions légales.';
                    hostResults.appendChild(note);

                    if (items.length > 1) {
                        var alternatives = document.createElement('details');
                        var summaryNode = document.createElement('summary');
                        summaryNode.textContent = 'Autres correspondances détectées';
                        alternatives.appendChild(summaryNode);
                        var list = document.createElement('div');
                        list.className = 'ptm-host-alternatives';
                        items.slice(1).forEach(function (item) {
                            var b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'button button-small';
                            b.textContent = item.name + ' (' + String(item.confidence || 0) + ' %)';
                            b.addEventListener('click', function () { applyHostingProfile(item, true); });
                            list.appendChild(b);
                        });
                        alternatives.appendChild(list);
                        hostResults.appendChild(alternatives);
                    }
                }).catch(function (err) {
                    hostResults.textContent = err.message || 'Détection impossible.';
                    hostResults.className = 'ptm-host-results is-error';
                }).finally(function () {
                    hostDetect.disabled = false;
                });
            });
        }

        var hostNameField = field('host_name');
        if (hostNameField) {
            hostNameField.addEventListener('change', function () {
                var profile = findHostingProfileByName(hostNameField.value);
                if (!profile) { return; }
                applyHostingProfile(profile, false);
                if (hostResults) {
                    hostResults.className = 'ptm-host-results';
                    hostResults.textContent = profile.address || profile.phone
                        ? 'Coordonnées connues préremplies dans les champs vides. Vérifiez-les puis enregistrez ce bloc.'
                        : 'Fournisseur reconnu, mais Dendrila Privacy ne dispose pas encore de coordonnées officielles vérifiées pour ce fournisseur.';
                }
            });
        }

        document.querySelectorAll('.ptm-treatment-template').forEach(function (button) {
            button.addEventListener('click', function () {
                var template = treatmentTemplates[button.getAttribute('data-template') || ''];
                if (!template) { return; }
                var rows = Array.prototype.slice.call(document.querySelectorAll('.ptm-treatment-row'));
                var row = rows.find(function (candidate) {
                    var purpose = candidate.querySelector('[data-ptm-treatment-field="purpose"]');
                    return purpose && !(purpose.value || '').trim();
                });
                if (!row) {
                    window.alert('Les lignes visibles contiennent déjà une finalité. Videz une ligne inutilisée avant d’appliquer un modèle.');
                    return;
                }
                Object.keys(template).forEach(function (key) {
                    var el = row.querySelector('[data-ptm-treatment-field="' + key + '"]');
                    if (!el) { return; }
                    el.value = template[key];
                    el.dispatchEvent(new Event('change', {bubbles:true}));
                });
                row.classList.add('is-prefilled');
                if ('open' in row) { row.open = true; }
                var summaryTitle = row.querySelector('.ptm-treatment-summary-title');
                var summaryState = row.querySelector('.ptm-treatment-summary-state');
                if (summaryTitle) { summaryTitle.textContent = template.purpose || 'Traitement à compléter'; }
                if (summaryState) { summaryState.textContent = 'À adapter'; }
                window.setTimeout(function () { row.classList.remove('is-prefilled'); }, 1800);
                var first = row.querySelector('[data-ptm-treatment-field="purpose"]');
                if (first) { first.focus(); first.scrollIntoView({behavior:'smooth', block:'center'}); }
                var form = row.closest('form');
                var status = form ? form.querySelector('.ptm-section-save-status') : null;
                if (status) {
                    status.textContent = 'Exemple prérempli. Adaptez-le à votre situation puis continuez lorsque vous êtes prêt.';
                    status.className = 'ptm-section-save-status';
                }
            });
        });

        document.querySelectorAll('.ptm-treatment-row [data-ptm-treatment-field="purpose"]').forEach(function (input) {
            input.addEventListener('input', function () {
                var row = input.closest('.ptm-treatment-row');
                if (!row) { return; }
                var title = row.querySelector('.ptm-treatment-summary-title');
                var state = row.querySelector('.ptm-treatment-summary-state');
                var value = (input.value || '').trim();
                if (title) { title.textContent = value || 'Nouveau traitement à compléter'; }
                if (state) { state.textContent = value ? 'Renseigné' : 'Vide'; }
            });
        });

        var wizard = document.querySelector('[data-ptm-guided-wizard]');
        if (wizard) {
            var stepIds = ['identity','treatments','flows','external','risk','cookies','email','authority','preview'];
            var stepTitles = {
                identity: 'Vous et le site',
                treatments: 'Pourquoi utilisez-vous des données ?',
                flows: 'D’où viennent et où vont les données ?',
                external: 'Outils utilisés en dehors de WordPress',
                risk: 'Situations sensibles',
                cookies: 'Cookies et traceurs',
                email: 'Suivi dans les e-mails',
                authority: 'Droits et autorité de contrôle',
                preview: 'Relire avant publication'
            };
            var panels = {};
            stepIds.forEach(function (id) {
                panels[id] = wizard.querySelector('[data-ptm-wizard-panel="' + id + '"]');
            });
            var progressLabel = document.getElementById('ptm-wizard-progress-label');
            var progressTitle = document.getElementById('ptm-wizard-progress-title');
            var progressBar = document.getElementById('ptm-wizard-progress-bar');
            var progressTrack = wizard.querySelector('.ptm-guided-progress-track');
            var stepButtons = Array.prototype.slice.call(wizard.querySelectorAll('[data-ptm-go-step]'));
            var showAll = document.getElementById('ptm-wizard-show-all');
            var guidedMode = document.getElementById('ptm-wizard-guided-mode');
            var storageKey = 'ptm_rgpd_wizard_step_' + (window.location.host || 'site');
            var resumeStep = wizard.getAttribute('data-ptm-resume-step') || 'identity';
            var current = stepIds.indexOf(resumeStep) !== -1 ? resumeStep : 'identity';
            var reviewedOnce = wizard.getAttribute('data-ptm-reviewed-once') === '1';
            var reviewMarkSent = reviewedOnce;
            var requestedStep = '';
            try {
                requestedStep = new URLSearchParams(window.location.search).get('ptm_step') || '';
            } catch (e) {}
            if (stepIds.indexOf(requestedStep) !== -1) {
                current = requestedStep;
            } else {
                try {
                    var stored = window.sessionStorage.getItem(storageKey);
                    // Do not let an old stored step force the wizard back to a section the
                    // server now recognises as complete. The server-computed resume step is
                    // the source of truth when the saved cursor points to step 1.
                    if (stepIds.indexOf(stored) !== -1 && !(stored === 'identity' && resumeStep !== 'identity')) { current = stored; }
                } catch (e) {}
            }

            function focusPanel(panel) {
                if (!panel) { return; }
                var heading = panel.querySelector('h3, h4');
                if (heading) {
                    heading.setAttribute('tabindex', '-1');
                    window.setTimeout(function () { heading.focus({preventScroll:true}); }, 40);
                }
                panel.scrollIntoView({behavior:'smooth', block:'start'});
            }

            function markPreviewReviewed() {
                if (reviewMarkSent || !cfg.sectionNonce) { reviewedOnce = true; return; }
                reviewMarkSent = true;
                reviewedOnce = true;
                wizard.setAttribute('data-ptm-reviewed-once', '1');
                var data = new FormData();
                data.set('action', 'pixel_trackers_manager_mark_wizard_reviewed');
                data.set('nonce', cfg.sectionNonce);
                postForm(data).catch(function () { /* Le mode local reste actif ; le prochain enregistrement le resynchronisera. */ });
            }

            function navigateToCorrectionTarget(detail) {
                var target = detail && detail.nextIncomplete ? detail.nextIncomplete : 'preview';
                if (stepIds.indexOf(target) === -1) { target = 'preview'; }
                if (target === 'preview') { refreshIntoPreview(); }
                else { renderStep(target, true); }
            }

            function renderStep(id, focus) {
                if (stepIds.indexOf(id) === -1) { id = 'identity'; }
                current = id;
                if (id === 'preview') { markPreviewReviewed(); }
                wizard.classList.add('is-guided');
                wizard.classList.remove('is-expanded');
                stepIds.forEach(function (stepId) {
                    if (panels[stepId]) { panels[stepId].hidden = stepId !== id; }
                });
                var index = stepIds.indexOf(id);
                var number = index + 1;
                if (progressLabel) { progressLabel.textContent = 'Étape ' + number + ' sur ' + stepIds.length; }
                if (progressTitle) { progressTitle.textContent = stepTitles[id] || ''; }
                if (progressBar) { progressBar.style.width = String((number / stepIds.length) * 100) + '%'; }
                if (progressTrack) { progressTrack.setAttribute('aria-valuenow', String(number)); }
                stepButtons.forEach(function (button) {
                    var buttonId = button.getAttribute('data-ptm-go-step');
                    var buttonIndex = stepIds.indexOf(buttonId);
                    button.classList.toggle('is-active', buttonId === id);
                    button.classList.toggle('is-past', buttonIndex < index);
                    button.setAttribute('aria-current', buttonId === id ? 'step' : 'false');
                });
                if (showAll) { showAll.hidden = false; }
                if (guidedMode) { guidedMode.hidden = true; }
                try { window.sessionStorage.setItem(storageKey, id); } catch (e) {}
                if (focus) { focusPanel(panels[id]); }
            }

            function renderAll() {
                wizard.classList.remove('is-guided');
                wizard.classList.add('is-expanded');
                stepIds.forEach(function (id) { if (panels[id]) { panels[id].hidden = false; } });
                if (showAll) { showAll.hidden = true; }
                if (guidedMode) { guidedMode.hidden = false; }
            }

            function currentForm() {
                var panel = panels[current];
                return panel && panel.matches('form.ptm-section-form') ? panel : null;
            }

            function refreshIntoPreview() {
                try { window.sessionStorage.setItem(storageKey, 'preview'); } catch (e) {}
                var previewContent = wizard.querySelector('[data-ptm-preview-content]');
                if (!cfg.sectionNonce || !window.fetch) { renderStep('preview', true); return; }
                if (previewContent) { previewContent.setAttribute('aria-busy', 'true'); }
                var data = new FormData();
                data.set('action', 'pixel_trackers_manager_wizard_preview');
                data.set('nonce', cfg.sectionNonce);
                postForm(data).then(function (out) {
                    if (previewContent && typeof out.html === 'string') { previewContent.innerHTML = out.html; }
                    renderStep('preview', true);
                }).catch(function () {
                    renderStep('preview', true);
                }).finally(function () {
                    if (previewContent) { previewContent.removeAttribute('aria-busy'); }
                });
            }

            function goTo(id, saveCurrent) {
                if (id === current) { return; }
                var form = currentForm();
                if (saveCurrent && form && form.dataset.dirty === '1') {
                    var moved = false;
                    var success = function (event) {
                        if (moved) { return; }
                        var detail = event && event.detail ? event.detail : {};
                        moved = true;
                        form.removeEventListener('ptm:section-save-error', failure);
                        if (id === 'preview') { refreshIntoPreview(); }
                        else { renderStep(id, true); }
                    };
                    var failure = function () {
                        form.removeEventListener('ptm:section-saved', success);
                    };
                    form.addEventListener('ptm:section-saved', success, {once:true});
                    form.addEventListener('ptm:section-save-error', failure, {once:true});
                    form.dataset.ptmNextStep = id;
                    form.dataset.ptmNavPending = '1';
                    var nextInput = form.querySelector('[data-ptm-next-step-input]');
                    if (nextInput) { nextInput.value = id; }
                    if (form.requestSubmit) { form.requestSubmit(); }
                    else { form.dispatchEvent(new Event('submit', {bubbles:true, cancelable:true})); }
                    return;
                }
                if (id === 'preview') { refreshIntoPreview(); }
                else { renderStep(id, true); }
            }

            stepButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    goTo(button.getAttribute('data-ptm-go-step') || 'identity', true);
                });
            });
            if (showAll) { showAll.addEventListener('click', renderAll); }
            if (guidedMode) { guidedMode.addEventListener('click', function () { renderStep(current, true); }); }

            stepIds.forEach(function (id, index) {
                var panel = panels[id];
                if (!panel) { return; }
                var nav = document.createElement('div');
                nav.className = 'ptm-wizard-nav';
                if (index > 0) {
                    var back = document.createElement('button');
                    back.type = 'button';
                    back.className = 'button';
                    back.textContent = '← Étape précédente';
                    back.addEventListener('click', function () { goTo(stepIds[index - 1], true); });
                    nav.appendChild(back);
                }
                var spacer = document.createElement('span');
                spacer.className = 'ptm-wizard-nav-spacer';
                nav.appendChild(spacer);
                if (index < stepIds.length - 1) {
                    var next = document.createElement('button');
                    next.type = 'button';
                    next.className = 'button button-primary';
                    next.textContent = panel.matches('form.ptm-section-form') ? 'Enregistrer et continuer →' : 'Continuer →';
                    next.addEventListener('click', function () {
                        var form = panel.matches('form.ptm-section-form') ? panel : null;
                        if (form) {
                            var advanced = false;
                            var onSaved = function (event) {
                                if (advanced) { return; }
                                var detail = event && event.detail ? event.detail : {};
                                advanced = true;
                                form.removeEventListener('ptm:section-save-error', onError);
                                var target = reviewedOnce ? (detail.nextIncomplete || 'preview') : stepIds[index + 1];
                                if (target === 'preview') { refreshIntoPreview(); }
                                else { renderStep(target, true); }
                            };
                            var onError = function () { form.removeEventListener('ptm:section-saved', onSaved); };
                            form.addEventListener('ptm:section-saved', onSaved, {once:true});
                            form.addEventListener('ptm:section-save-error', onError, {once:true});
                            form.dataset.ptmNextStep = stepIds[index + 1];
                            form.dataset.ptmNavPending = '1';
                            var nextInput = form.querySelector('[data-ptm-next-step-input]');
                            if (nextInput) { nextInput.value = stepIds[index + 1]; }
                            if (form.requestSubmit) { form.requestSubmit(); }
                            else { form.dispatchEvent(new Event('submit', {bubbles:true, cancelable:true})); }
                        } else {
                            renderStep(stepIds[index + 1], true);
                        }
                    });
                    nav.appendChild(next);
                }
                panel.appendChild(nav);
            });

            Array.prototype.slice.call(wizard.querySelectorAll('form.ptm-section-form')).forEach(function (form) {
                form.addEventListener('ptm:section-saved', function (event) {
                    var detail = event && event.detail ? event.detail : {};
                    if (!reviewedOnce || detail.navigationPending || detail.stayOnStep) { return; }
                    navigateToCorrectionTarget(detail);
                });
            });

            renderStep(current, false);
            if (requestedStep) {
                window.setTimeout(function () { focusPanel(panels[current]); }, 80);
            }
        }

        var requestedHash = window.location.hash ? window.location.hash.slice(1) : '';
        if (requestedHash && requestedHash !== 'ptm-legal-wizard') {
            var requestedTarget = document.getElementById(requestedHash);
            if (requestedTarget) {
                requestedTarget.classList.add('ptm-target-highlight');
                window.setTimeout(function () {
                    requestedTarget.scrollIntoView({behavior:'smooth', block:'center'});
                }, 80);
                window.setTimeout(function () {
                    requestedTarget.classList.remove('ptm-target-highlight');
                }, 2600);
            }
        }

        var indirectServiceSelect = document.getElementById('ptm-indirect-service-select');
        var indirectServiceButton = document.getElementById('ptm-add-indirect-service');
        var indirectServices = document.getElementById('ptm-indirect-services');
        var flowsForm = indirectServices ? indirectServices.closest('form.ptm-section-form') : null;

        function serviceIndex() {
            var indices = Array.prototype.slice.call(document.querySelectorAll('.ptm-indirect-service-card')).map(function (card) { return parseInt(card.getAttribute('data-ptm-service-index') || '0', 10); });
            return indices.length ? Math.max.apply(Math, indices) + 1 : 0;
        }
        function addServiceField(grid, prefix, label, key, value, kind, options) {
            var wrapper = document.createElement('label'); wrapper.className = 'ptm-field';
            var caption = document.createElement('span'); caption.textContent = label; wrapper.appendChild(caption);
            var el;
            if (kind === 'select') {
                el = document.createElement('select');
                Object.keys(options || {}).forEach(function (optionKey) { var opt=document.createElement('option');opt.value=optionKey;opt.textContent=options[optionKey];if(String(value||'')===optionKey){opt.selected=true;}el.appendChild(opt); });
            } else if (kind === 'textarea') { el=document.createElement('textarea');el.rows=2;el.value=value||''; }
            else { el=document.createElement('input');el.type='text';el.value=value||''; }
            el.name = prefix + '[' + key + ']'; wrapper.appendChild(el); grid.appendChild(wrapper); return el;
        }
        function bindServiceCard(card) {
            if (!card || card.dataset.ptmBound === '1') { return; }
            card.dataset.ptmBound='1';
            var save=card.querySelector('.ptm-save-indirect-service');
            var remove=card.querySelector('.ptm-remove-indirect-service');
            if(save){save.addEventListener('click',function(){if(!flowsForm){return;}var status=flowsForm.querySelector('.ptm-section-save-status');if(status){status.textContent='Enregistrement de cette fiche…';}flowsForm.dataset.ptmNavPending='0';flowsForm.dataset.ptmStayOnStep='1';if(flowsForm.requestSubmit){flowsForm.requestSubmit();}else{flowsForm.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}});}
            if(remove){remove.addEventListener('click',function(){card.remove();if(flowsForm){flowsForm.dataset.dirty='1';flowsForm.dataset.ptmNavPending='0';flowsForm.dataset.ptmStayOnStep='1';if(flowsForm.requestSubmit){flowsForm.requestSubmit();}}});}
        }
        Array.prototype.slice.call(document.querySelectorAll('.ptm-indirect-service-card')).forEach(bindServiceCard);

        function createServiceCard(service) {
            if (!indirectServices) { return null; }
            var existingId=(service.service_id||'').trim();
            if(existingId && existingId!=='manual'){
                var duplicate=Array.prototype.slice.call(indirectServices.querySelectorAll('[name$="[service_id]"]')).find(function(el){return el.value===existingId;});
                if(duplicate){var existingCard=duplicate.closest('.ptm-indirect-service-card');if(existingCard){existingCard.open=true;existingCard.scrollIntoView({behavior:'smooth',block:'center'});}return existingCard;}
            }
            var index=serviceIndex(); var prefix='legal_profile[indirect_services]['+index+']';
            var card=document.createElement('details');card.className='ptm-indirect-service-card';card.open=true;card.setAttribute('data-ptm-service-index',String(index));
            var summary=document.createElement('summary');var title=document.createElement('strong');title.className='ptm-indirect-service-title';title.textContent=service.label||'Nouveau service';summary.appendChild(title);var badge=document.createElement('span');badge.className='ptm-badge '+(service.state==='active'?'bad':service.state==='potential'?'warn':'neutral');badge.textContent=service.state==='active'?'actif':service.state==='potential'?'à vérifier':'manuel';summary.appendChild(badge);card.appendChild(summary);
            [['service_id',service.service_id||'manual'],['state',service.state||'manual']].forEach(function(pair){var h=document.createElement('input');h.type='hidden';h.name=prefix+'['+pair[0]+']';h.value=pair[1];card.appendChild(h);});
            var grid=document.createElement('div');grid.className='ptm-form-grid';card.appendChild(grid);
            var labelInput=addServiceField(grid,prefix,'Service','label',service.label||'', 'text');labelInput.addEventListener('input',function(){title.textContent=labelInput.value||'Nouveau service';});
            addServiceField(grid,prefix,'Quelles données ce service reçoit ou produit-il ?','categories',service.categories||'','textarea');
            addServiceField(grid,prefix,'D’où viennent ces données ?','source',service.source||'','textarea');
            addServiceField(grid,prefix,'Qui reçoit ou peut accéder à ces données ?','recipients',service.recipients||'','textarea');
            addServiceField(grid,prefix,'Des données peuvent-elles partir hors UE/EEE ?','transfer_status',service.transfer_status||'unknown','select',{'unknown':'À vérifier','no':'Non, d’après les informations disponibles','yes':'Oui / potentiellement'});
            addServiceField(grid,prefix,'Pays / organisation concernée','transfer_destinations',service.transfer_destinations||'','textarea');
            addServiceField(grid,prefix,'Encadrement du transfert','transfer_mechanism',service.transfer_mechanism||'','textarea');
            addServiceField(grid,prefix,'Informations / garanties à consulter','transfer_safeguards',service.transfer_safeguards||'','textarea');
            addServiceField(grid,prefix,'Décision importante prise automatiquement ?','automated_decision',service.automated_decision||'no','select',{'unknown':'À vérifier','no':'Non, rien de ce type','yes':'Oui'});
            addServiceField(grid,prefix,'Précision sur la décision automatisée','automated_details',service.automated_details||'','textarea');
            var actions=document.createElement('div');actions.className='ptm-indirect-service-actions';var save=document.createElement('button');save.type='button';save.className='button button-primary ptm-save-indirect-service';save.textContent='Enregistrer ce service';actions.appendChild(save);var remove=document.createElement('button');remove.type='button';remove.className='button-link-delete ptm-remove-indirect-service';remove.textContent='Supprimer cette fiche';actions.appendChild(remove);card.appendChild(actions);
            indirectServices.appendChild(card);bindServiceCard(card);if(flowsForm){flowsForm.dataset.dirty='1';}card.scrollIntoView({behavior:'smooth',block:'center'});return card;
        }
        if (indirectServiceSelect && indirectServiceButton) {
            indirectServiceButton.addEventListener('click', function () {
                var option=indirectServiceSelect.options[indirectServiceSelect.selectedIndex];if(!option||!option.value){return;}
                var service={service_id:'manual',label:'Nouveau service',state:'manual',categories:'',source:'',recipients:'',transfer_status:'unknown',transfer_destinations:'',transfer_mechanism:'',transfer_safeguards:'',automated_decision:'no',automated_details:''};
                if(option.value!=='manual'){try{service=JSON.parse(option.getAttribute('data-ptm-service')||'{}');}catch(e){} }
                createServiceCard(service);indirectServiceSelect.value='';
            });
        }

        document.querySelectorAll('.ptm-email-purpose').forEach(function(button){button.addEventListener('click',function(){var target=field(button.getAttribute('data-target')||'');var value=button.getAttribute('data-value')||'';if(!target||!value){return;}var current=(target.value||'').trim();if(current.indexOf(value)===-1){target.value=current?current+'\n• '+value:'• '+value;target.dispatchEvent(new Event('input',{bubbles:true}));}});});

        function toggle(selectName, values, targets) {
            var source=field(selectName); if(!source){return;} var run=function(){var show=values.indexOf(source.value)!==-1; targets.forEach(function(n){var t=field(n); if(t&&t.closest('.ptm-field')){t.closest('.ptm-field').hidden=!show;}});}; source.addEventListener('change',run); run();
        }
        toggle('representative_applicable',['yes'],['controller_representative','controller_representative_contact']);
        toggle('transfers_status',['yes'],['transfer_destinations','transfer_mechanism','transfer_safeguards']);
        toggle('automated_decision',['yes'],['automated_details']);
        toggle('special_categories',['yes'],['special_categories_basis']); toggle('criminal_data',['yes'],['criminal_data_basis']); toggle('minors_data',['yes'],['minors_details']);
        toggle('cookie_nonessential',['yes'],['cookie_consent_status','cookie_preferences','cookie_choice_retention','cookie_cross_device']); toggle('cookie_cross_device',['yes'],['cookie_cross_device_explanation']);
        toggle('email_pixels',['yes'],['email_pixel_purposes','email_pixel_status','email_pixel_preferences']); toggle('tracked_links',['yes'],['tracked_links_details']);
    });
}());


(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ptm-copy-shortcode').forEach(function (button) {
            button.addEventListener('click', function () {
                var code = button.getAttribute('data-shortcode') || '';
                if (!code) { return; }
                var done = function () {
                    var old = button.textContent;
                    button.textContent = 'Copié ✓';
                    window.setTimeout(function () { button.textContent = old; }, 1400);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code).then(done).catch(function () {});
                } else {
                    var input = document.createElement('textarea');
                    input.value = code; document.body.appendChild(input); input.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    input.remove();
                }
            });
        });

        var list = document.getElementById('ptm-coverage-list');
        var filter = document.getElementById('ptm-coverage-filter');
        var sort = document.getElementById('ptm-coverage-sort');
        var density = document.getElementById('ptm-coverage-density');
        if (!list) { return; }

        function applyCoverageView() {
            var filterValue = filter ? filter.value : 'all';
            list.querySelectorAll('.ptm-coverage-row').forEach(function (row) {
                var status = row.getAttribute('data-status') || '';
                var visible = filterValue === 'all' ||
                    filterValue === status ||
                    (filterValue === 'todo' && status !== 'found');
                row.hidden = !visible;
            });

            if (sort && sort.value !== 'default') {
                list.querySelectorAll('.ptm-coverage-group-body').forEach(function (group) {
                    var rows = Array.prototype.slice.call(group.querySelectorAll('.ptm-coverage-row'));
                    rows.sort(function (a, b) {
                        var key = sort.value === 'status' ? 'status' : (sort.value === 'source' ? 'source' : 'label');
                        return (a.getAttribute('data-' + key) || '').localeCompare(b.getAttribute('data-' + key) || '', 'fr');
                    });
                    rows.forEach(function (row) { group.appendChild(row); });
                });
            }
        }
        if (filter) { filter.addEventListener('change', applyCoverageView); }
        if (sort) { sort.addEventListener('change', applyCoverageView); }
        if (density) {
            density.addEventListener('click', function () {
                list.classList.toggle('is-compact');
                density.textContent = list.classList.contains('is-compact') ? 'Affichage détaillé' : 'Affichage compact';
                try { window.localStorage.setItem('ptm_coverage_compact', list.classList.contains('is-compact') ? '1' : '0'); } catch (e) {}
            });
            try {
                if (window.localStorage.getItem('ptm_coverage_compact') === '1') {
                    list.classList.add('is-compact'); density.textContent = 'Affichage détaillé';
                }
            } catch (e) {}
        }
        applyCoverageView();
    });
}());


(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var content = document.querySelector('.ptm-tab-content');
        if (!content) { return; }
        var page = content.getAttribute('data-ptm-page') || '';
        // The overview is already a dashboard of destinations. Keeping its full width
        // avoids recreating the small-laptop horizontal overflow that this release fixes.
        if (!page || page === 'pixel-trackers-manager') { return; }

        var wizard = page === 'pixel-trackers-manager-assistant' ? content.querySelector('[data-ptm-guided-wizard]') : null;
        var entries = [];
        if (wizard) {
            Array.prototype.slice.call(wizard.querySelectorAll('[data-ptm-wizard-panel]')).forEach(function (node, index) {
                var step = node.getAttribute('data-ptm-wizard-panel') || '';
                var heading = node.querySelector('h3, h2');
                var label = heading ? (heading.textContent || '').trim() : '';
                if (!step || !label) { return; }
                if (!node.id) { node.id = 'ptm-assistant-section-' + step; }
                entries.push({node: node, id: node.id, label: label, step: step});
            });
        } else {
            var candidates = Array.prototype.slice.call(content.children).filter(function (node) {
                return node.matches && (node.matches('section.ptm-card') || node.matches('.ptm-card') || node.matches('.ptm-wizard'));
            });
            candidates.forEach(function (node, index) {
                var heading = node.querySelector('h2, h3');
                if (!heading) { return; }
                var label = (heading.textContent || '').trim();
                if (!label) { return; }
                if (!node.id) { node.id = 'ptm-section-' + page.replace('pixel-trackers-manager-', '').replace(/[^a-z0-9-]/gi, '-') + '-' + (index + 1); }
                entries.push({node: node, id: node.id, label: label, step: ''});
            });
        }
        if (entries.length < 2) { return; }

        var layout = document.createElement('div');
        layout.className = 'ptm-tab-layout';
        var aside = document.createElement('aside');
        aside.className = 'ptm-section-menu';
        aside.setAttribute('aria-label', 'Sections de cet onglet');
        var details = document.createElement('details');
        details.className = 'ptm-section-menu-details';
        details.open = true;
        var summary = document.createElement('summary');
        summary.textContent = 'Sections';
        details.appendChild(summary);
        var nav = document.createElement('nav');

        function markCurrent(id) {
            nav.querySelectorAll('a').forEach(function (item) {
                item.classList.toggle('is-current', item.getAttribute('data-ptm-section-id') === id);
            });
        }

        entries.forEach(function (entry) {
            var link = document.createElement('a');
            link.href = '#' + entry.id;
            link.textContent = entry.label;
            link.setAttribute('data-ptm-section-id', entry.id);
            if (entry.step) { link.setAttribute('data-ptm-assistant-step', entry.step); }
            link.addEventListener('click', function (event) {
                if (entry.step && wizard) {
                    event.preventDefault();
                    var trigger = wizard.querySelector('[data-ptm-go-step="' + entry.step + '"]');
                    if (trigger) { trigger.click(); }
                    markCurrent(entry.id);
                    window.setTimeout(function () {
                        wizard.scrollIntoView({behavior: 'smooth', block: 'start'});
                    }, 20);
                    return;
                }
                markCurrent(entry.id);
            });
            nav.appendChild(link);
        });
        if (nav.firstElementChild) { nav.firstElementChild.classList.add('is-current'); }
        details.appendChild(nav);
        aside.appendChild(details);

        var main = document.createElement('main');
        main.className = 'ptm-section-main';
        var movable = Array.prototype.slice.call(content.children).filter(function (node) {
            return !node.classList.contains('notice');
        });
        movable.forEach(function (node) { main.appendChild(node); });
        layout.appendChild(aside);
        layout.appendChild(main);
        content.appendChild(layout);

        if (wizard) {
            var initialStep = wizard.getAttribute('data-ptm-resume-step') || '';
            var initial = entries.filter(function (entry) { return entry.step === initialStep; })[0];
            if (initial) { markCurrent(initial.id); }
            wizard.querySelectorAll('[data-ptm-go-step]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var step = button.getAttribute('data-ptm-go-step') || '';
                    var target = entries.filter(function (entry) { return entry.step === step; })[0];
                    if (target) { markCurrent(target.id); }
                });
            });
            return;
        }

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (items) {
                var visible = items.filter(function (item) { return item.isIntersecting; }).sort(function (a,b) { return b.intersectionRatio-a.intersectionRatio; })[0];
                if (!visible) { return; }
                markCurrent(visible.target.id);
            }, {rootMargin: '-120px 0px -65% 0px', threshold: [0, .1, .25]});
            entries.forEach(function (entry) { observer.observe(entry.node); });
        }
    });
}());
