# PTM 0.0.2-test4 — checklist de test

Cette version est un build de test. Tester de préférence sur une copie ou un WordPress de test avant un site de production.

## 1. Installation / mise à jour
- [ ] Le ZIP s’installe depuis Extensions → Ajouter une extension → Téléverser.
- [ ] Activation sans erreur PHP ni écran blanc.
- [ ] Mise à jour depuis 0.0.2-test3 sans perte des réglages existants.
- [ ] Première ouverture de PTM reprend correctement l’onboarding si nécessaire.

## 1.1 Validation WordPress 7.1

WordPress 7.1 est sorti le 19 août 2026. Le `readme.txt` indique `Tested up to: 7.1` : cette mention doit correspondre à un test réel réussi avant toute publication.

- [ ] Installation propre et activation de PTM sur WordPress 7.1 stable.
- [ ] Mise à jour d’un site de test vers WordPress 7.1 avec PTM déjà actif, sans erreur fatale ni perte de réglages.
- [ ] Onboarding, onglets d’administration, sauvegardes AJAX et lancement du scanner fonctionnent sous 7.1.
- [ ] Création puis réouverture d’une page juridique Gutenberg sans erreur dans l’éditeur de blocs 7.1 exécuté dans son iframe.
- [ ] Les styles/scripts d’administration PTM ne contaminent pas le canevas de l’éditeur de blocs et le rendu public reste inchangé.
- [ ] Les shortcodes PTM restent utilisables dans Gutenberg sous 7.1.
- [ ] Avec `WP_DEBUG` actif sur le site de test, aucune nouvelle erreur PHP bloquante liée à WordPress 7.1 n’apparaît pendant le parcours P0.
- [ ] La mention `Tested up to: 7.1` n’est conservée dans la build candidate WordPress.org qu’après validation de cette section.

## 2. Navigation / responsive
- [ ] Onglets pastel continus, raccordés au panneau de contenu.
- [ ] Aucun onglet actif blanc/carré isolé.
- [ ] Assistant RGPD visible comme onglet principal.
- [ ] Sommaire de sections présent sur les écrans riches et utilisable au clavier.
- [ ] Sur petit écran PC, Vue d’ensemble ne provoque aucun scroll horizontal de la page.
- [ ] La carte Actions à exécuter se replie correctement.

## 3. Assistant RGPD / performance
- [ ] Les boutons Retour / Continuer sont en bas à droite.
- [ ] Navigation entre sections sans rechargement complet de la page.
- [ ] Sauvegarder un bloc ne lance ni crawl ni audit HTTP.
- [ ] Un élément manquant/partiel de Couverture documentaire ouvre directement la bonne section de l’assistant.
- [ ] Le retour vers Pages légales conserve un parcours compréhensible.

## 4. Pages légales / rôles
- [ ] Éditeur, responsable du traitement, contact public, contact droits et DPO sont distincts.
- [ ] L’e-mail admin WordPress n’est pas recopié automatiquement comme contact public/RGPD.
- [ ] Les pages publiques ne contiennent aucune consigne interne du type « à vérifier dans PTM ».
- [ ] Une modification de l’assistant ne modifie pas silencieusement une version juridique publique déjà validée.
- [ ] La couverture documentaire reflète les éléments applicables sans afficher 100 % si des actions applicables restent à corriger.

## 5. Création de pages
### WordPress natif
- [ ] Création en brouillon avec le shortcode correspondant.

### Elementor
- [ ] Si Elementor est le builder attendu, la page est créée comme vraie page Elementor.
- [ ] Réouverture dans Elementor sans conversion ni erreur.
- [ ] Le contenu PTM se trouve dans un widget texte et reste modifiable.

### Divi 5 / Extra
- [ ] Avec Divi 5, création Section → Row → Column → Text en blocs Divi natifs.
- [ ] Réouverture dans le Visual Builder sans reconstruction manuelle.
- [ ] Avec ancien Divi/Extra, repli shortcode compatible.

## 6. Scan complet / erreurs
- [ ] Un scan terminé reste terminé et ne se relance pas automatiquement.
- [ ] 100 % = toutes les URLs ont reçu un résultat, même avec erreurs.
- [ ] Les vraies 404 restent des erreurs HTTP.
- [ ] Une URL 404 correspondant à une page WordPress brouillon/private/pending/future est classée comme page non publique.
- [ ] Si PTM a généré cette page et qu’elle est prête, une action Publier est proposée.
- [ ] Si elle n’est pas prête, PTM dirige vers la bonne section à compléter.
- [ ] Réessayer uniquement les erreurs ne relance pas les pages non publiques tant que leur statut n’a pas changé.

## 7. Outils externes / pratiques
- [ ] Section outils externes compréhensible par un utilisateur non technique.
- [ ] Exemples : Gmail/Outlook, WhatsApp, Calendly, Koalendar, réservations Google/Microsoft, HelloAsso, paiements, formulaires externes, fichiers/listes.
- [ ] Les services détectables sur le site sont pré-signalés quand possible.
- [ ] Les conseils d’envoi groupé distinguent campagne et envoi ponctuel, et parlent désinscription/CCI/opposition sans jargon inutile.

## 8. Conservation / sauvegardes
- [ ] Gravity Forms : réglages lisibles quand le plugin les expose, sinon état « à vérifier ».
- [ ] WooCommerce : durées de conservation lues sans écriture automatique.
- [ ] MailPoet : ne pas confondre « inactif » et « supprimé ».
- [ ] UpdraftPlus et extensions de sauvegarde connues détectées prudemment.
- [ ] Les recommandations ne prétendent pas remplacer un plugin de sécurité type Wordfence.

## 9. Consentement / builders
- [ ] Barre fixe et encart centré PTM indépendants du builder.
- [ ] Sur le front, `#pixel-trackers-manager-consent` est monté directement sous `<body>` avec `data-ptm-mounted="body"`.
- [ ] Sur Divi, la bannière reste visuellement au-dessus de `#page-container`, des sections/modules et des éléments positionnés/sticky.
- [ ] Sur Elementor, la bannière reste visuellement au-dessus des containers, popups et éléments positionnés/sticky.
- [ ] Le moteur réel est désactivé dans Elementor/Divi Visual Builder.
- [ ] Accepter et Refuser gardent un poids visuel équivalent.
- [ ] Fermer sans choisir ne vaut pas acceptation.
- [ ] Après un choix enregistré, le bouton de footer **Gérer mes choix** rouvre directement le panneau des préférences avec les choix actuels préremplis.
- [ ] Un bouton/lien Divi portant la classe `ptm-consent-open` rouvre les préférences.
- [ ] Un bouton/lien Elementor portant l’attribut `data-ptm-consent-open="preferences"` rouvre les préférences.
- [ ] Même si un handler Divi/Elementor exécute `stopPropagation()` en phase de bubbling, le contrôle PTM continue à fonctionner grâce au listener capture.
- [ ] `window.DendrilaPrivacyConsentAPI.openPreferences()` ouvre le panneau des préférences.
- [ ] `window.DendrilaPrivacyConsentAPI.getChoice()` renvoie l’état enregistré sans modifier le consentement.
- [ ] Escape ferme le dialogue et le focus revient au contrôle qui l’avait ouvert lorsque celui-ci existe encore.
- [ ] Divi Maps se réinitialise après consentement si nécessaire.
- [ ] reCAPTCHA n’est pas automatiquement qualifié de traceur marketing ; son traitement reste contextuel.

## 10. Contrôles techniques avant partage
- [ ] Tous les PHP passent `php -l`.
- [ ] Tous les JS passent `node --check`.
- [ ] Aucun appel de méthode interne manquant détecté par le contrôle statique simple.
- [ ] Pas de fichier de travail `.pre-final` dans le ZIP.
- [ ] Une seule racine `pixel-trackers-manager/` dans l’archive.
- [ ] `unzip -t` ne signale aucune erreur.
- [ ] SHA-256 calculé pour le ZIP final.
