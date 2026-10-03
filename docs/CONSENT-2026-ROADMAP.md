# Consentement 2026 — feuille de route Dendrila Privacy

## But

Construire des fonctions de consentement et de preuve qui restent locales au WordPress, transparentes pour la personne concernée et vérifiables par l’administrateur, sans dépendre d’un identifiant de consentement géré par Dendrila.

Cette feuille de route vise notamment les points où des suites comme iubenda et Usercentrics proposent déjà des fonctions fortes : preuve du consentement, gestion multi-appareils et pilotage centralisé. L’objectif n’est pas de revendiquer une conformité automatique ou une supériorité juridique, mais de proposer une approche plus locale, explicable et contrôlable.

## Lot 1 — socle

État : implémenté dans la PR de travail dédiée.

- registre local de preuves, avec identifiant e-mail HMAC au lieu de l’adresse en clair ;
- chaîne d’intégrité HMAC par personne et par portée ;
- export JSON et export via l’outil de confidentialité WordPress ;
- effacement via l’outil de confidentialité WordPress ;
- fonctions et hooks publics pour permettre aux intégrations de déposer une preuve ;
- synchronisation facultative du consentement pour les utilisateurs WordPress connectés ;
- résolution des conflits entre terminal et compte avant le chargement des services facultatifs ;
- stratégie « choix du compte » ou « choix le plus récent » ;
- information visible quand un choix synchronisé est appliqué ;
- stockage par défaut toujours local au navigateur pour les personnes non connectées ;
- fonctions désactivées par défaut quand elles modifient le comportement public.

## Lot 2 — préférences e-mail sans compte

État : implémenté sur la branche empilée dédiée, avant validation finale.

Le centre de préférences dédié au suivi e-mail comprend :

- lien signé et temporaire ;
- aucune adresse e-mail en clair dans l’URL ;
- consultation du dernier choix connu ;
- retrait ou modification avec la même simplicité que l’accord ;
- événement enregistré dans le registre local ;
- aucune redirection de clic utilisée comme mécanisme de preuve ;
- texte d’information versionné, avec copie et empreinte conservées dans la preuve ;
- API locale permettant aux adaptateurs de vérifier une finalité avant d’activer un suivi individualisé ;
- aperçu administrateur non enregistrant, afin qu’un test ne puisse pas fabriquer une preuve ;
- effacement WordPress qui supprime aussi les ancrages HMAC associés à la personne.

Le jeton est chiffré et authentifié, expire automatiquement, n’affiche pas l’adresse dans l’URL et n’est pas utilisé comme identifiant de mesure de clic.

## Lot 3 — adaptateurs e-mail

État : couche de vérification commencée. Dendrila Privacy vérifie désormais l’état réel de l’outil d’origine avant d’annoncer qu’un refus a été appliqué. Les contrôles publics actuellement vérifiés pour MailPoet et FluentCRM sont globaux ; ils ne sont donc pas présentés comme une synchronisation par destinataire lorsque le suivi reste actif.

Ordre prévu :

1. MailPoet ;
2. FluentCRM ;
3. Newsletter ;
4. Mail Mint ;
5. Brevo ;
6. Mailchimp / MC4WP lorsque l’API publique disponible permet une vérification fiable.

Pour chaque adaptateur :

- détecter séparément suivi d’ouverture et suivi individualisé des clics ;
- distinguer mesure marketing et usage strictement nécessaire à la délivrabilité ;
- ne modifier automatiquement qu’un réglage exposé par une API publique et vérifiable ;
- relire l’état après modification ;
- ne jamais afficher « corrigé » quand le résultat ne peut pas être vérifié ;
- relier le choix de la personne au registre de preuve ;
- documenter clairement les limites quand le réglage vit chez un prestataire externe ;
- ne pas détourner un filtre global en pseudo-contrôle par destinataire en s’appuyant sur un ordre d’exécution fragile ou un état global temporaire.

## Lot 4 — preuve plus robuste

État : presque complet sur la branche dédiée.

- durée de conservation configurable avec purge qui préserve une ancre cryptographique pour les événements encore conservés ;
- export CSV en plus du JSON, avec protection contre l’interprétation de formules par un tableur ;
- contrôle d’intégrité global et par personne, par lots pour éviter une limite artificielle à 500 événements ;
- compteur, date et résultat du dernier contrôle ;
- copie + empreinte du texte d’information présenté ;
- preuve d’origine via source, méthode et référence ;
- journal séparé des opérations administratives sensibles, sans recopier d’adresse e-mail.

Reste volontairement à traiter séparément : rotation de la clé d’identification HMAC. La clé actuelle sert à retrouver une personne sans conserver son adresse en clair ; la remplacer sans disposer du clair empêcherait de relier les anciennes preuves. Une migration sûre doit donc séparer à l’avenir la clé d’identification stable de la clé de signature rotative, plutôt que de simuler une rotation destructrice.

## Lot 5 — multi-appareils avancé

État : implémenté sur la branche dédiée.

- mode recommandé qui demande explicitement quelle version garder lorsqu’un choix local et un choix de compte diffèrent ;
- blocage strict de toutes les catégories facultatives tant que ce conflit n’est pas résolu ;
- synthèse « ce navigateur / compte WordPress » avec dates, sans identifiant d’appareil, empreinte matérielle ni fingerprinting ;
- opt-in/out par compte depuis « Gérer mes choix » : un nouveau compte n’est pas synchronisé tant que la personne ne l’active pas ; une ancienne copie déjà créée reste reconnue pour préserver la migration ; la désactivation efface la copie liée au compte sans toucher au choix local ;
- refus et retrait explicites propagés au compte par le même chemin que l’acceptation ;
- copie de fond et réconciliation d’un conflit distinguées d’un nouveau consentement dans le registre de preuves ;
- test Node sans dépendance ajouté au contrôle JavaScript afin de vérifier qu’un conflit en mode recommandé garde toutes les catégories facultatives bloquées avant sa résolution ;
- les choix en conflit restent privés dans la fermeture JavaScript et l’API publique de consentement ne peut pas contourner la résolution explicite.

## Principes non négociables

- pas de cloud Dendrila nécessaire pour stocker les preuves ;
- pas de Controller-ID externe ;
- pas d’IP ni de user-agent dans le registre par défaut ;
- pas de fingerprinting d’appareil ;
- pas de promesse « conforme RGPD » générée automatiquement ;
- refus et retrait ne doivent jamais être plus difficiles que l’acceptation ;
- une intégration non vérifiable donne une instruction, pas un faux succès ;
- toute nouvelle fonction visible doit être ajoutée à la description principale WordPress.org avant la release ;
- limiter les GitHub Actions : regrouper les changements, contrôler localement autant que possible et éviter les pushs de correction unitaires.
