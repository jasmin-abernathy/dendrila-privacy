# Audit d’intégration WordPress

## Problèmes corrigés

La 0.0.8 distingue les références Google Fonts des ressources déclarées, mais l’audit statique ne mesure pas le trafic du navigateur. La PR #29 réunit les PR #27 et #28. Elle corrige aussi le décompte Abilities des groupes ACF qui ne sont pas configurés pour REST. Le réglage des cartes du calendrier et son option locale de télémétrie sont présentés comme des réglages. L’état effectif de StellarWP reste distinct.

Les documents Complianz peuvent avoir un titre personnalisé et n’afficher leur type que dans leur code court ou leur bloc. Dendrila reconnaît désormais leur type sans les exécuter. L’assistant peut sélectionner les pages correspondantes et refuse la création d’un doublon. Le test couvre aussi un bloc imbriqué, une région différente et un code court échappé.

## Tests réalisés

- Activation simultanée dans WordPress 7.1.5 et PHP 8.4 de Dendrila Privacy, ACF 6.8.12, The Events Calendar 6.18.4.1 et Complianz 7.5.7.
- Test WP-CLI avec les extensions actives. Création d’un événement, accès aux réglages ACF et calendrier, rendu du premier écran d’administration et refus de doublons pour les trois documents de test.
- Contrôle REST avec un champ ACF fictif. Le contenu publié est lisible anonymement quand le champ est exposé. Le même champ sur un contenu privé est refusé en anonyme et accessible à l’administrateur. Les tests isolés couvrent les sélecteurs Abilities et la télémétrie locale.
- Neuf suites PHP autonomes. Le test Chromium du blocage par catégorie inclut désormais une iframe Google Maps de test avant le choix, après autorisation des statistiques seules et après autorisation des contenus externes. Les requêtes sont interceptées par le banc de test.

## Limites

Le navigateur Chromium local n’a pas pu démarrer en raison d’une restriction sur les sockets. Le banc Chromium utilise des réponses interceptées. Il ne constitue donc pas une capture sans cache vers Google Fonts ou Google Maps sur le site de la testeuse, ni une validation du rendu réel d’une carte avec une clé Google. La soumission de formulaires dans le navigateur, le rendu mobile, l’accessibilité et Divi restent à valider. Les autorisations des clients Abilities et le statut effectif du stockage StellarWP n’ont pas été mesurés. Le site de la testeuse n’a pas été modifié.
