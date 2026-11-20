# The Events Calendar 6.18.4.1 — contrôle de confidentialité

Revue du 20 novembre 2026 à partir de l'archive `the-events-calendar.6.18.4.1.zip` transmise par l'utilisateur. Les données des événements et des organisateurs du site réel ne sont pas consultées.

## Google Maps

Dans `src/Tribe/Assets.php`, l'extension enregistre le script `tribe-events-google-maps` avec une URL sur `maps.google.com/maps/api/js` ou `maps.googleapis.com/maps/api/js`. Dans `src/functions/template-tags/google-map.php`, elle utilise également `www.google.com/maps/embed/v1/place` pour les cartes iframe. `src/Tribe/Embedded_Maps.php` montre qu'une carte est générée en fonction de l'événement ou du lieu et de sa configuration. Le réglage `embedGoogleMaps` permet les cartes, sans prouver que chaque visiteur en charge une. Les simples liens de navigation vers Google Maps ne sont pas des iframes.

Dendrila :
- reconnaît désormais aussi `maps.google.com/maps/api/js` dans le scan et dans le bloqueur facultatif ;
- traite le script WordPress portant exactement le handle `tribe-events-google-maps` comme du « contenu externe », même lorsqu'un filtre modifie l'URL du script ;
- laisse intacts les URL de navigation et les scripts internes du calendrier ;
- indique aux administrateurs de vérifier les requêtes réseau réelles sur leurs pages. Si Complianz gère le consentement, ne pas activer un deuxième CMP par réflexe.

## Données, imports et politique de confidentialité

`src/Tribe/Organizer.php` définit notamment les champs nom, téléphone, e-mail et site web ; `src/Tribe/Venue.php` gère les coordonnées des lieux. Le fichier `src/admin-views/privacy.php` offre déjà un modèle de politique de confidentialité, avec lieux, organisateurs, imports CSV/ICS, et usages additionnels possibles. L'installation de TEC ne prouve **ni la collecte de participants, ni la billetterie, ni un formulaire de réservation**.

Dendrila ajoute l'exemple de traitement « Événements / organisateurs / lieux » et un encart dans l'assistant « Outils externes » sans recopier des données personnelles. L'administrateur doit vérifier quelles coordonnées sont effectivement publiques et si des imports ou services tiers sont utilisés.

## Télémétrie de l'éditeur

`src/Events/Telemetry/Telemetry.php` contient une option `opt_in_status` de partage facultatif. `common/src/Common/Telemetry/Telemetry.php` initialise le fournisseur `telemetry.stellarwp.com/api/v1` et la bibliothèque StellarWP de gestion d'opt-in.

Dendrila lit l'option locale lorsqu'elle existe ; **celle-ci n'est pas une preuve du statut effectif** puisque TEC réconcilie aussi un état StellarWP. Une option absente reste « à vérifier ». Cette télémétrie ne prouve pas un traceur de visiteurs.

## Vérifications indispensables en WordPress de test

- Tester le consentement avec une vraie carte iframe et une carte via JavaScript avant choix/refus/acceptation/retrait ;
- contrôler le rendu accessible d'une carte bloquée, en particulier mobile et Divi ;
- vérifier la coexistence avec Complianz sans double bandeau ;
- vérifier le statut de télémétrie effectif dans les réglages The Events Calendar ;
- contrôler les coordonnées publiques d'organisateurs et la publication de leurs informations ;
- vérifier l'assistant et les tests PHP/JS/Plugin Check sur la PR.
