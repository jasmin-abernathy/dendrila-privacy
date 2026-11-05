# Dendrila Privacy 0.0.6 — correction de l’administration

## Cause confirmée

Le ZIP téléchargé de WordPress.org le 5 novembre 2026 contient la version 0.0.5 et le défaut. Le commit de renommage `5faedfe449e5c41794988d1e8c14d33a22967db5` a remplacé les contrôles de route par `dendrila-privacy`, alors que les huit menus restent enregistrés avec `pixel-trackers-manager`. CSS et JavaScript ne sont donc pas chargés sur les pages du plugin : logo non contraint, mise en page absente, boutons d’analyse sans gestionnaire JavaScript. Le même remplacement empêche la redirection initiale vers l’assistant.

Les versions internes 0.1 à 0.9 précèdent la numérotation publique 0.0.x (voir changelog.txt). Ce correctif conserve les interfaces et données existantes ; il ne prétend pas comparer pixel par pixel un ancien ZIP 0.9 qui n’a pas été retrouvé.

## Correction

- Reconnaissance explicite des huit routes enregistrées, partagée entre chargement des assets et première ouverture.
- Route par défaut de la vue d’ensemble corrigée.
- Dimensions HTML des trois logos, même sans CSS.
- Test de régression exécuté sur PHP 7.4, 8.3 et 8.4 en CI : huit menus, CSS/JS présents, nonce et URL AJAX, fichiers effectivement présents et aucune injection sur les pages étrangères.
- Versions du header, de la constante et du Stable tag alignées sur 0.0.6.

Revue de la Description publique : les fonctionnalités restaurées et la détection locale Age Assurance figurent déjà dans la liste principale. Aucun nouveau service externe introduit.

## Publier sur WordPress.org

Le dépôt GitHub ne met pas à jour WordPress.org. La publication se fait dans le SVN du plugin, avec l’utilisateur `juliane16` et son mot de passe SVN spécifique, créé sur https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password . Ne pas transmettre ce mot de passe dans une conversation.

Avec un client SVN (TortoiseSVN sous Windows ou ligne de commande) :

1. Faire un checkout de `https://plugins.svn.wordpress.org/dendrila-privacy/` dans un dossier neuf.
2. Décompresser `dendrila-privacy-0.0.6.zip` ailleurs. Remplacer le contenu de `trunk/` par le **contenu** du dossier `dendrila-privacy/` : le fichier principal doit être `trunk/dendrila-privacy.php`. Conserver les métadonnées `.svn` du checkout.
3. Ajouter les nouveaux fichiers au suivi SVN, marquer les fichiers disparus comme supprimés, puis vérifier `svn status` et `svn diff`. Le dossier `assets/` du ZIP va dans `trunk/assets/`, pas dans le dossier `assets/` à la racine SVN (réservé à la fiche publique).
4. Créer `tags/0.0.6` à partir du trunk local (`svn copy trunk tags/0.0.6`). Vérifier que `Version`, la constante `VERSION` et `Stable tag` valent tous 0.0.6. Si ce tag existe déjà, arrêter : ne jamais écraser une version publiée.
5. Faire un seul commit SVN du trunk et du tag : `svn commit --username juliane16 -m "Release 0.0.6: fix admin assets and scans"`. Saisir le mot de passe au prompt. Si la confirmation de publication est activée dans WordPress.org, la valider également.
6. Contrôler la fiche publique puis télécharger `https://downloads.wordpress.org/plugin/dendrila-privacy.0.0.6.zip` et comparer les fichiers à l’archive validée. Les sites déjà équipés reçoivent ensuite la proposition de mise à jour selon leur prochain contrôle WordPress.

Pour dépanner immédiatement un site : Extensions → Ajouter une extension → Téléverser une extension → sélectionner le ZIP → Remplacer la version actuelle. Ne pas supprimer l’extension au préalable. Les clés de données n’ont pas changé.

Référence officielle : https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/

## Vérifications du 5 novembre 2026

- CI GitHub 42042748536 : métadonnées, syntaxe PHP 7.4/8.3/8.4, test admin-assets et test Age Assurance sur chaque version PHP, JavaScript et Plugin Check officiel réussis.
- Test WordPress Playground sur WordPress 6.5.14 / PHP 7.4 puis WordPress « latest » / PHP 8.4 : activation, redirection initiale vers setup, pages HTML contenant les assets, clics des boutons standard et complet via jsdom avec le vrai admin.js, appels AJAX WordPress, résultat sauvegardé. Une fixture HTTP contrôlée contenant Google Tag Manager est détectée, les trois pages sont analysées sans erreur. Un nonce incorrect est rejeté avec HTTP 403.
- Un essai sans fixture a aussi confirmé la fin de l’analyse et l’enregistrement des erreurs de lecture lorsque l’environnement ne permet pas l’accès HTTP aux pages locales. Ne pas confondre un scan terminé avec un scan sans erreurs.
- Le navigateur Chromium ne peut pas démarrer dans cet environnement (création de socket interdite). Le rendu visuel pixel par pixel et les interactions propres à Divi/Elementor ne sont donc pas validés par cette session. Les contrôles DOM/JavaScript ci-dessus ne constituent pas un test visuel Chromium.
- Archive : 15 fichiers, 154864 octets ; SHA-256 `046e309645c514d602ff9504bab0acdd7ea6e60f7d255b52609826d875c7d6b5`. Chaque fichier distribué correspond à la source testée. Les tests et documents ne sont pas inclus.
- Correctif fusionné : PR #18, commit de fusion `ad9c19798c38583e8fa5a5a4f75382b4f73f4a9f`. Publication SVN non effectuée : pas d’accès SVN authentifié dans cette session.

## Prévention

Les tests de syntaxe et Plugin Check ne détectent pas l’absence de CSS/JS due à un mauvais slug. Conserver les routes internes lors d’un renommage commercial et tester les menus réellement enregistrés, leur chargement d’assets et un parcours AJAX. Ne pas remplacer globalement les identifiants internes par la nouvelle marque.

