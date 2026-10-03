# Clés du registre de preuves

## Migration et signatures

Le schéma 3 sépare l’identité pseudonyme stable des signatures rotatives. Le trousseau est stocké dans `dendrila_privacy_evidence_keyring`, une option non autoloadée. Son initialisation est atomique : elle contient ensemble la clé d’identité, la clé de vérification historique éventuelle, les clés de signature et l’identifiant actif. Aucun e-mail n’y est ajouté.

Sur un registre v1/v2, la valeur de `dendrila_privacy_evidence_chain_key` est réutilisée sans modification comme clé d’identité et de vérification historique. L’option d’origine reste intacte. Les événements anciens ne sont ni réécrits ni resignés. Sur une installation neuve, l’identité et la signature reçoivent chacune 32 octets aléatoires indépendants. Les identifiants de clés utilisent 16 octets aléatoires. L’absence d’aléa fort provoque un échec, sans repli prévisible.

Chaque événement v3 inclut `signing_key_id` dans le payload HMAC-SHA256. La vérification des événements v1/v2 conserve exactement leur encodage canonique. Une version inconnue, une clé introuvable ou une signature incorrecte font échouer la vérification. Les liens `previous_hash`, les heads et les ancres de rétention restent des empreintes opaques indépendantes de la clé active.

La rotation manuelle retire l’ancienne clé de la signature et la conserve pour la vérification. Il n’existe aucune suppression automatique de clés dans ce lot. Le nombre affiché comprend la clé active, les clés retirées et l’éventuelle clé historique. La clé d’identité ne tourne pas : les anciennes adresses n’étant pas stockées, une rotation de cette identité demanderait un autre protocole de migration.

## Concurrence et échecs

Migration, ajouts, rotation, purge et effacement utilisent un même verrou de connexion MySQL/MariaDB (`GET_LOCK`, puis `RELEASE_LOCK` dans `finally`). Un verrou occupé ou une fonction indisponible refuse l’opération immédiatement. L’API d’enregistrement renvoie alors un `WP_Error` : les intégrations doivent prévoir une nouvelle tentative et ne pas annoncer une preuve enregistrée. Les entrées sensibles du cache d’options sont invalidées après acquisition du verrou pour relire la clé active.

Le formulaire de rotation envoie l’identifiant actif qu’il a affiché. Un deuxième envoi ou un formulaire périmé ne déclenche pas une deuxième rotation. La capacité `manage_options`, le nonce WordPress et la case de confirmation sont vérifiés côté serveur. Le journal ne contient que les identifiants des clés, jamais leurs secrets.

La migration crée d’abord le trousseau, puis utilise `dbDelta` et vérifie la présence de la nouvelle colonne avant de marquer le schéma 3. En cas d’interruption, le même trousseau est réutilisé. Un trousseau absent sur un schéma 3, corrompu, ou une ancienne clé absente sur un schéma historique ne sont jamais remplacés automatiquement.

Pendant le déploiement, arrêter les processus exécutant encore l’ancienne version avant de permettre des ajouts : ceux-ci ne connaissent pas ce verrou. Revenir au code v2 après l’écriture d’événements v3 n’est pas une procédure de rollback compatible.

## Sauvegardes et portée

Sauvegarder et restaurer ensemble la table des preuves, le trousseau, l’ancienne option de clé, les heads et les ancres de rétention. Ne jamais exporter les secrets dans un export de preuves, les afficher dans l’interface ou les placer dans un ticket de support. La perte des clés peut rendre les preuves invérifiables ou empêcher de retrouver une personne. Une restauration partielle ne répare pas ce problème.

Ce mécanisme détecte les altérations selon les clés et les ancrages conservés localement. Il ne fournit ni signature publique, ni horodatage externe, ni protection contre un administrateur capable de modifier à la fois les clés et les données. Une rotation ne répare pas rétroactivement une clé compromise et ne change pas l’identité pseudonyme.

Les liens temporaires du centre de préférences utilisent séparément les salts WordPress. Leur rotation peut invalider les anciens liens ; elle ne modifie pas les clés du registre durable.

## Validation

Exécuter `php tests/evidence-keys.test.php`. Le test appelle le code du registre avec des doubles WordPress/SQL, des fixtures v1/v2 indépendantes du payload v3, et vérifie migration, chaînes mixtes, signatures, rotation, rétention, effacement, panne d’écriture, clés manquantes et contrôles administrateur. Il ne remplace pas une intégration WordPress/MySQL.

Validation réelle effectuée le 3 octobre 2026 sur une installation WordPress éphémère avec MariaDB 10.11.14 et cache objet Redis 7.0.15 : migration d’un registre v2 sans réécriture de l’historique, deux rotations successives, chaîne mixte legacy/K1/K2/K3, purge avec ancre de rétention, exports JSON/CSV, effacement isolé, concurrence sur deux connexions MariaDB et rafraîchissement d’un cache persistant après rotation. Le parcours du formulaire avait déjà été contrôlé au clavier et à 360/1280 px. La matrice PHP 7.4/8.3/8.4 reste à exécuter une dernière fois sur le SHA final avant fusion vers `master`.

La description principale de `readme.txt` mentionne les clés séparées et rotatives. Ce lot ne modifie pas la version stable et ne publie rien sur WordPress.org.

Validation locale du lot : 97 contrôles PHP réussis sous PHP 8.3.6, syntaxe PHP/JS du dépôt vérifiée, formulaire rendu et contrôlé au clavier à 360 et 1280 px avec le HTML produit par le test et le CSS du plugin (hors WordPress complet). Le test de conflit JavaScript hérité utilisait des dates de 1970 : ses fixtures ont été rendues non expirées pour atteindre réellement le conflit, sans modifier le code de production. Le test passe désormais.
