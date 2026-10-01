# Dendrila Privacy

[Read in English](README.md)

Dendrila Privacy est un assistant WordPress **local-first** d’audit de confidentialité, de documentation et de consentement, développé par **Le Potager du Web**.

Il aide l’administrateur à comprendre ce que fait réellement son site : repérer les traceurs et services tiers, contrôler les pages légales, documenter les pratiques liées aux données et, s’il le souhaite, gérer le consentement des visiteurs.

> **État actuel :** version candidate WordPress.org `0.0.4` sous le nom public **Dendrila Privacy**. Dendrila Privacy n’est pas un outil de certification juridique et ne remplace pas une analyse adaptée à l’activité réelle de l’organisation.

## Principes du projet

- **Détecter d’abord, demander ensuite.** Dendrila Privacy réutilise les informations déjà présentes dans WordPress avant de les redemander.
- **Local par défaut.** Les résultats d’audit, réglages et réponses restent dans WordPress, sauf action explicite de l’administrateur vers un service externe documenté.
- **Aucune publication juridique silencieuse.** Dendrila Privacy peut préparer des brouillons et proposer des mises à jour, mais les changements publics importants demandent une validation explicite.
- **Pas de dark patterns.** Si l’interface de consentement native est activée, Accepter et Refuser gardent le même poids visuel.
- **Moteur indépendant des builders.** Le consentement fonctionne au niveau WordPress ; Elementor, Divi et les autres constructeurs sont des couches de compatibilité, pas des dépendances.
- **Écriture prudente.** Si Dendrila Privacy ne comprend pas assez sûrement la structure interne d’un constructeur, il utilise un shortcode ou un parcours manuel plutôt que de réécrire des données inconnues.

## Ce que Dendrila Privacy couvre actuellement

### Analyse du site

- analyse progressive du site complet ;
- détection des traceurs et services tiers ;
- distinction entre activité réellement observée, intégration disponible et élément restant à vérifier ;
- une URL en erreur n’interrompt pas le reste du scan ;
- les brouillons/pages privées/en attente/programmées peuvent être distingués d’une vraie erreur 404 publique.

### Documentation légale

- pages de référence Mentions légales, Politique de confidentialité et Cookies / Consentement ;
- Assistant RGPD dans un onglet dédié ;
- éléments manquants de la couverture documentaire cliquables vers la bonne section de l’assistant ;
- séparation entre éditeur du site, responsable du traitement, contact public, contact pour l’exercice des droits et DPO réellement désigné ;
- shortcodes publics et création de brouillons ;
- mise à jour explicite plutôt qu’une publication silencieuse.

### Consentement

- barre native ou encart centré facultatif ;
- interface montée globalement sous `<body>`, indépendante de Divi/Elementor ;
- blocage par défaut des services facultatifs reconnus une fois la fonction activée ;
- même poids visuel pour Tout accepter et Tout refuser ;
- contrôle permanent **Gérer mes choix** ;
- API publique légère pour les intégrations de thème/builder ;
- mode de test administrateur et compatibilité prudente avec les modules dynamiques.

### Compréhension de l’écosystème WordPress

Dendrila Privacy peut exploiter ou inspecter prudemment des informations provenant de WordPress/Gutenberg, Elementor, Divi, certains plugins de formulaires, de sauvegarde, de consentement ou d’analytics.

Le projet prévoit aussi de couvrir les pratiques qui ont lieu **en dehors de WordPress** lorsque le site ne peut pas les révéler : envois groupés depuis Gmail/Outlook, contacts WhatsApp, outils de réservation comme Calendly/Koalendar, formulaires externes, HelloAsso, paiements, tableurs ou stockage cloud.

## ZIP installable automatique

Le dépôt génère désormais automatiquement une archive propre à chaque push sur `master`.

Dans GitHub :

1. ouvrir **Actions** ;
2. ouvrir la dernière exécution **Dendrila Privacy quality and distribution** ;
3. télécharger l’artifact `dendrila-privacy-VERSION`.

Le ZIP est construit avec la commande officielle WP-CLI `wp dist-archive` et `.distignore`. Il contient uniquement les fichiers nécessaires à l’installation WordPress : pas de workflows GitHub, documentation de développement, fichiers de contribution ou fichiers temporaires.

Le workflow vérifie également :

- cohérence entre le header `Version`, la constante runtime et `Stable tag` ;
- syntaxe PHP sous PHP 7.4, 8.3 et 8.4 ;
- syntaxe JavaScript ;
- taille et métadonnées du `readme.txt` ;
- maximum de cinq tags WordPress.org ;
- **Plugin Check officiel WordPress** ;
- intégrité et SHA-256 du ZIP.

## Installation de test

1. Téléchargez le ZIP produit par GitHub Actions ou placez le dépôt dans `wp-content/plugins/dendrila-privacy/`.
2. Activez **Dendrila Privacy** dans WordPress.
3. Ouvrez Dendrila Privacy une première fois pour lancer l’assistant de configuration.

Prérequis actuels :

- WordPress 6.5+
- PHP 7.4+

Les métadonnées ciblent actuellement WordPress 7.1. Avant toute publication d’une build sur WordPress.org, la matrice WordPress 7.1 doit être réellement validée sur cette build.

## Versionnement et WordPress.org

`0.0.4` est la **version candidate actuellement soumise à la revue WordPress.org**, avec le même numéro dans le header PHP, la constante runtime et le `Stable tag`.

Le dépôt SVN WordPress.org servira uniquement aux publications et ne sera pas utilisé comme miroir de chaque commit GitHub.

Voir [`docs/WORDPRESS-ORG-RELEASE.md`](docs/WORDPRESS-ORG-RELEASE.md) pour la procédure complète.

## Documentation

- [`README.md`](README.md) — présentation du projet en anglais.
- [`docs/WORDPRESS-ORG-RELEASE.md`](docs/WORDPRESS-ORG-RELEASE.md) — préparation et publication WordPress.org.
- [`docs/CONSENT-INTEGRATION.md`](docs/CONSENT-INTEGRATION.md) — intégration de la bannière avec les builders.
- [`docs/STATUS-2026-08-30.md`](docs/STATUS-2026-08-30.md) — dernier point de consolidation produit.
- [`docs/USER-GUIDE.fr.md`](docs/USER-GUIDE.fr.md) — guide utilisateur français.
- [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md) — guide utilisateur anglais.
- `docs/TESTS-0.0.2-test4.md` — checklist de validation actuelle.
- `docs/CAHIER-DES-CHARGES.md` — cahier des charges et feuille de route interne.
- `changelog.txt` — historique détaillé des builds de développement.
- [`CONTRIBUTING.fr.md`](CONTRIBUTING.fr.md) — guide de contribution en français.
- [`SECURITY.fr.md`](SECURITY.fr.md) — politique de signalement des vulnérabilités.

## Développement

Le dépôt sert au développement pré-publication. La branche de référence doit rester installable ; les changements non terminés devraient, lorsque possible, passer par une branche de fonctionnalité.

Avant de retenir une version candidate à la publication, Dendrila Privacy doit au minimum passer :

- les contrôles automatiques GitHub ;
- les tests d’activation/désactivation WordPress ;
- la checklist manuelle de la version courante ;
- la validation réelle WordPress 7.1 ;
- les tests Gutenberg, Elementor et Divi ;
- l’installation du **ZIP exact** produit par GitHub Actions sur un WordPress vierge.

## Signaler un problème

Utilisez les modèles d’issues GitHub pour les bugs reproductibles et propositions d’amélioration. Ne publiez jamais de mots de passe, clés API, données personnelles ou exports de sites clients dans une issue publique.

Pour une vulnérabilité pouvant exposer des données ou compromettre un site, suivez [`SECURITY.fr.md`](SECURITY.fr.md) plutôt que de publier les détails d’exploitation dans une issue.

## Licence

Voir [`LICENSE`](LICENSE). Dendrila Privacy est distribué sous GPL v2 ou ultérieure.
