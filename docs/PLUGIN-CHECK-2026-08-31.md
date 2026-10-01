# PTM — état Plugin Check au 31 août 2026

Ce fichier est une liste de travail issue du premier passage réel de **WordPress Plugin Check 2.1.0** sur le dépôt PTM.

L'objectif n'est pas de masquer les alertes mais de distinguer :

- les alertes dues aux fichiers de développement GitHub, qui ne doivent pas être présents dans le ZIP ;
- les problèmes de code réellement bloquants pour une soumission WordPress.org ;
- les avertissements à analyser et documenter.

## Déjà corrigé côté dépôt / distribution

- [x] `readme.txt` réécrit en anglais standard.
- [x] cinq tags WordPress.org maximum.
- [x] `readme.txt` ramené sous 10 KiB.
- [x] `.distignore` ajouté.
- [x] README GitHub, documentation, workflows et fichiers de contribution exclus de l'archive installable.
- [x] workflow PHP 7.4 / 8.3 / 8.4 et syntaxe JavaScript.
- [x] Plugin Check officiel ajouté à la CI.
- [x] génération automatique du ZIP et SHA-256 ajoutée à la CI.

## Erreurs de code à fermer avant soumission

### Sorties HTML non échappées

Plugin Check signale plusieurs sorties dynamiques dans `dendrila-privacy.php`, notamment autour des lignes relevées lors du scan :

- ~4580 : sortie dynamique impliquant `$this` ;
- ~5321 : compteurs `$page_audit['satisfied_total']` / `$page_audit['applicable_total']` ;
- ~5420 : `$badge` ;
- ~5621 / ~5626 : balise / URL de ligne dynamiques ;
- ~5769 : balise dynamique.

Actions :

- [ ] inspecter chaque construction HTML ;
- [ ] remplacer les balises dynamiques par des branches explicites lorsque possible ;
- [ ] utiliser `esc_html()`, `esc_attr()`, `esc_url()` ou `wp_kses()` au dernier moment selon le contexte ;
- [ ] relancer Plugin Check et conserver zéro ERROR d'échappement.

### Scripts injectés directement

`includes/class-pixel-trackers-manager-consent.php` déclenche `WordPress.WP.EnqueuedResources.NonEnqueuedScript` sur le garde-fou de consentement précoce et sur une reconstruction de script.

Le garde-fou actuel a une justification fonctionnelle : empêcher qu'un builder/thème ne déclenche un service optionnel avant que le moteur normal de consentement ne démarre. Cependant, une version WordPress.org doit respecter autant que possible l'API d'enqueue.

Actions :

- [ ] concevoir une version du bootstrap précoce enregistrée/enqueued par WordPress ;
- [ ] vérifier que le blocage reste effectif sur Divi et Elementor ;
- [ ] éviter toute régression de la règle « rien d'optionnel avant le choix » ;
- [ ] relancer Plugin Check.

### Offloading de contenu distant

Plugin Check a signalé une utilisation potentielle de contenu distant dans `dendrila-privacy.php` autour de la ligne ~1420.

Actions :

- [ ] identifier précisément la ressource concernée ;
- [ ] vérifier si elle est seulement référencée/documentée ou réellement chargée/exécutée ;
- [ ] si PTM charge une ressource JS/CSS/image distante pour son propre fonctionnement, la rendre locale ;
- [ ] si le signalement concerne un service tiers audité, restructurer le code pour que Plugin Check ne l'interprète pas comme une dépendance PTM distante.

## Avertissements sécurité à revoir

Plugin Check signale plusieurs lectures de `$_GET` / `$_POST` et quelques actions pouvant nécessiter une meilleure vérification de nonce ou une sanitisation plus évidente.

Actions :

- [ ] revoir `setup_page` et `setup_create` ;
- [ ] revoir les lectures de `legal_profile` ;
- [ ] revoir les paramètres de preview/test de consentement ;
- [ ] confirmer que les paramètres purement en lecture sont sanitizés même lorsqu'un nonce n'est pas nécessaire ;
- [ ] utiliser `check_admin_referer()` / `check_ajax_referer()` pour toute action qui modifie l'état ;
- [ ] documenter les faux positifs qui resteraient après revue.

## Logs de développement

Plusieurs appels `error_log()` sont encore présents.

Actions :

- [ ] supprimer les logs de développement non nécessaires ;
- [ ] si un log exceptionnel reste utile, le conditionner à `WP_DEBUG` et éviter toute donnée personnelle/sensible.

## Internationalisation

Le header déclare `Domain Path: /languages`, mais le dossier n'existe pas actuellement dans la distribution.

Actions :

- [ ] décider de la stratégie i18n avant publication ;
- [ ] utiliser l'anglais comme langue source des chaînes destinées à WordPress.org/translate.wordpress.org ;
- [ ] envelopper les chaînes publiques dans les fonctions gettext WordPress ;
- [ ] générer un POT cohérent si un dossier `languages/` est conservé ;
- [ ] sinon supprimer temporairement un `Domain Path` trompeur jusqu'à ce que la structure existe.

## Alertes liées uniquement au dépôt de développement

Lors du premier scan, Plugin Check a également signalé `.gitattributes`, `.editorconfig`, `.gitignore`, `.distignore` et plusieurs fichiers Markdown racine.

Ces fichiers sont légitimes dans GitHub mais doivent rester absents de l'archive WordPress.org. `.distignore` et la CI sont désormais responsables de cette séparation.

La cible finale est donc de lancer Plugin Check sur **l'archive de distribution extraite**, et non sur tout le dépôt de développement.

## Définition de « prêt WordPress.org »

PTM n'est pas considéré prêt à soumettre tant que :

- [ ] Plugin Check ne retourne aucune ERROR sur le contenu exact du ZIP ;
- [ ] les warnings restants sont compris ;
- [ ] le ZIP produit automatiquement s'installe sur un WordPress vierge ;
- [ ] la matrice Gutenberg / Elementor / Divi / WordPress 7.1 est validée ;
- [ ] la version passe d'un identifiant de développement (`0.0.2-test4`) à un numéro numérique de publication.
