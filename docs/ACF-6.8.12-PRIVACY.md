# Advanced Custom Fields 6.8.12 : intégration d'audit RGPD

Date : 20 novembre 2026. Code analysé : archive `advanced-custom-fields.6.8.12.zip` fournie par l'utilisateur. L'intégration se limite à la **lecture des réglages**, ne modifie jamais ACF et ne récupère aucune valeur de champ.

## Vérifications pertinentes

### 1. WordPress REST API

Les groupes ACF possèdent un réglage `show_in_rest` (désactivé par défaut). Le réglage ACF global `rest_api_enabled` est activé par défaut. Dendrila compte uniquement les groupes **actifs** dont la visibilité REST est effectivement activée et dont le réglage global permet REST. **La visibilité REST n'est pas une preuve d'accès anonyme à des données privées** : les permissions dépendent des objets WordPress, de leur statut et des contrôles d'accès.

Sources : `includes/post-types/class-acf-field-group.php` et `includes/rest-api/class-acf-rest-api.php`, ainsi que https://www.advancedcustomfields.com/resources/wp-rest-api-integration/

### 2. WordPress Abilities API / assistants

Depuis ACF 6.8.12, le réglage global `enable_abilities` est **activé par défaut**, de même que `enable_acf_abilities` sur les groupes, CPT et taxonomies. Le site doit cependant disposer de WordPress avec `wp_register_ability`, et la connexion à un assistant externe ou serveur MCP requiert une installation et des permissions supplémentaires. Dendrila signale **la configuration et la disponibilité**, et dénombre les groupes concernés, sans déclarer une transmission automatique de données.

Sources : `acf.php`, `src/AI/AI.php`, `src/Site_Health/AI_Usage.php`, `includes/post-types/class-acf-field-group.php`; annonce officielle https://www.advancedcustomfields.com/blog/acf-6-8-12-released/

### 3. Champs personnels et formulaires

ACF peut stocker des valeurs personnelles par ses champs et via `acf_form()`. Dendrila énumère **uniquement les définitions** des champs et compte les types `email`, `password`, `user`. Aucune valeur, valeur par défaut, adresse de visiteur ou nom de groupe n'est affiché. Les champs de type `text` ne sont pas supposés personnels par défaut ; un contrôle éditorial reste nécessaire.

La présence d'ACF n'implique pas qu'un formulaire frontal existe : un thème peut utiliser `acf_form()`, mais il faut vérifier son comportement réel.

### 4. Google Maps contre Google Fonts

ACF utilise `maps.googleapis.com/maps/api/js` pour le **champ Google Map**, à l'édition/admin ou selon son utilisation réelle. Le fait qu'un groupe comporte ce type de champ ne prouve **pas** que les visiteurs d'une page chargent Google Maps. Dendrila n'ajoute pas ce champ à ses détecteurs de requêtes de page. Les polices de l'interface ACF (Inter et JetBrains Mono) sont intégrées en WOFF2 locaux. Donc **ACF ne constitue pas, par sa seule présence, une preuve d'appel à Google Fonts**.

Sources : `includes/fields/class-acf-field-google-map.php`, `assets/build/fonts/`; https://www.advancedcustomfields.com/resources/google-map/

### 5. Inscription à la newsletter ACF

L'interface admin contient un opt-in à la newsletter d'ACF envoyé, après action explicite, à `api.hsforms.com`. Ce traitement concerne les coordonnées fournies volontairement par l'administrateur, **pas** une télémétrie automatique des visiteurs du site.

Source : `includes/admin/admin-email-opt-in-banner.php`.

## Affichage et sécurité

- L'encart est affiché uniquement si ACF ou ACF Pro est actif dans l'inventaire du scan.
- Il affiche un résumé des réglages REST, Abilities, et nombre de champs cartographiques ou potentiellement personnels, sans noms/valeurs ni clés API.
- Les requêtes et capacités ACF restent intactes ; aucune désactivation forcée, aucun blocage du back-office ou des formulaires.
- L'inspection est plafonnée à 200 groupes et 1 500 champs pour ne pas ralentir les gros sites ; l'interface indique si le résultat est partiel.
- **Important** : ces indicateurs sont des pistes de vérification, pas des alertes de fuite prouvée, ni un certificat de conformité.

## Tests

`php tests/acf-privacy-6.8.12.php` : plugin absent/inactif, ACF et ACF Pro, groupe REST actif ou non, Abilities activées/désactivées, carte configurée, absence de lecture des valeurs de champ. Exécuter sur PHP 7.4, 8.3 et 8.4, puis tester réellement ACF dans WordPress avant fusion.

## À contrôler en mode Work / sur WordPress

- Endpoints REST réellement consultables **sans authentification** et permissions par type de contenu (ne jamais interroger ni exporter de données clients réelles).
- Rendu d'un champ carte dans le back-office et sur un éventuel front : vérifier les requêtes réseau externes.
- Formulaires ACF réellement présents dans le thème et traitements associés.
- Activer/désactiver Abilities dans ACF et vérifier la correspondance de l'encart.
- Vérifier l'affichage mobile, l'assistant et le parcours de consentement sans régression Divi/WooCommerce.

### Types de contenu et taxonomies ACF

L’analyse compte également les types de contenu et taxonomies configurés par ACF (fonctions `acf_get_acf_post_types()` et `acf_get_acf_taxonomies()`) : `show_in_rest`, `enable_acf_abilities` et état actif. Ces éléments sont distincts des groupes de champs et REST peut être activé par défaut sur eux. Leurs compteurs indiquent une **configuration**, pas un accès anonyme ni la présence d'un client d'IA.
