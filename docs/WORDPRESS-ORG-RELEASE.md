# Dendrila Privacy — procédure WordPress.org

Ce document sépare volontairement le développement GitHub de la distribution WordPress.org.

## 1. Principe

- **GitHub** = dépôt de développement, documentation, issues, tests et CI.
- **ZIP GitHub Actions** = archive installable produite automatiquement à partir des seuls fichiers de distribution.
- **SVN WordPress.org** = dépôt de publication, à alimenter uniquement avec des versions prêtes à être distribuées.

WordPress.org ne doit pas servir de dépôt de développement quotidien.

## 2. Versionnement

Pendant le développement GitHub, une version de test telle que `0.0.2-test4` peut rester utilisée.

Avant une vraie publication WordPress.org :

1. choisir une version numérique, par exemple `0.0.2` ou `0.1.0` ;
2. mettre exactement la même version dans :
   - le header `Version:` de `dendrila-privacy.php` ;
   - `Pixel_Trackers_Manager_Plugin::VERSION` ;
   - `Stable tag:` de `readme.txt` ;
3. utiliser cette version numérique pour le tag SVN WordPress.org.

Les tags SVN WordPress.org doivent être des numéros de version composés de chiffres et de points. Ne pas publier un tag SVN `0.0.2-test4`.

Le workflow GitHub refuse également un tag GitHub `v...` de publication s'il n'est pas numérique ou s'il ne correspond pas à la version déclarée dans PTM.

## 3. Tested up to

`Tested up to` doit indiquer une version de WordPress réellement testée. Ne pas annoncer une version supérieure à la version stable courante, sauf release candidate officiellement disponible.

Pour PTM, WordPress 7.1 est la version stable courante au moment de cette procédure. La checklist dédiée doit être réellement exécutée avant de considérer `Tested up to: 7.1` comme validé pour une publication.

## 4. Contrôles automatiques GitHub

Le workflow `.github/workflows/quality-and-build.yml` exécute automatiquement :

- cohérence `Version` / constante runtime / `Stable tag` ;
- limite de cinq tags WordPress.org ;
- taille du `readme.txt` ;
- lint PHP sur PHP 7.4, 8.3 et 8.4 ;
- contrôle de syntaxe JavaScript ;
- Plugin Check officiel WordPress ;
- génération de l'archive installable avec `wp dist-archive` et `.distignore` ;
- test de l'intégrité du ZIP ;
- contrôle qu'aucun fichier réservé au développement ne fuit dans le ZIP ;
- calcul SHA-256 de l'archive.

Le ZIP est généré automatiquement à chaque push sur `master` et lors d'un lancement manuel du workflow. Il est disponible dans les **Artifacts** de la dernière exécution GitHub Actions.

Les erreurs Plugin Check ne doivent pas être masquées pour obtenir artificiellement un workflow vert. Les erreurs réellement applicables au code deviennent des critères bloquants de publication.

## 5. Contenu de l'archive

Le ZIP distribué doit contenir une seule racine :

`dendrila-privacy/`

Il contient notamment :

- `dendrila-privacy.php` ;
- `readme.txt` ;
- `LICENSE` ;
- `changelog.txt` ;
- `includes/` ;
- `assets/` utilisés par le plugin à l'exécution.

Il ne contient pas :

- `.github/` ;
- `docs/` ;
- README GitHub ;
- fichiers de contribution / sécurité / code de conduite ;
- `.git*`, `.editorconfig`, `.distignore` ;
- archives ou fichiers temporaires.

`.distignore` est la source de vérité pour cette séparation.

## 6. Readme WordPress.org

Depuis 2025, WordPress.org exige que les informations de base du `readme.txt` soumis au répertoire soient fournies en **anglais standard**. Les traductions sont gérées ensuite par l'infrastructure de traduction WordPress.org.

Le `readme.txt` doit donc :

- être rédigé en anglais ;
- rester concis ;
- utiliser au maximum cinq tags ;
- conserver un `Stable tag` identique à la version publiée ;
- documenter clairement les services externes ;
- ne pas promettre ou garantir une conformité juridique ;
- conserver uniquement le changelog de la version courante, l'historique détaillé restant dans `changelog.txt`.

Les README GitHub `README.md` et `README.fr.md` restent bilingues et ne sont pas distribués dans le ZIP WordPress.org.

## 7. Services externes facultatifs

Les recherches de registre sont facultatives et uniquement déclenchées par une action explicite d’un administrateur.

Le `readme.txt` doit continuer à documenter chaque fournisseur réellement appelé :

- France : Recherche d’entreprises / DINUM ;
- Norvège : Enhetsregisteret / Brønnøysundregistrene ;
- UE prise en charge + Irlande du Nord : VIES / Commission européenne.

Pour chaque service : données envoyées, moment du déclenchement, destinataire, lien de service et information de confidentialité. Aucun audit, contenu de page, réponse de l’assistant ou choix visiteur ne doit être joint à la requête.

## 8. Blocages Plugin Check à traiter avant première soumission

Le premier passage réel de Plugin Check 2.1.0 a permis de séparer les faux positifs liés au dépôt de développement des problèmes qui concernent vraiment le plugin distribué.

Les principaux sujets à fermer avant la première soumission sont suivis dans `docs/PLUGIN-CHECK-2026-08-31.md` et comprennent notamment :

- échappement de plusieurs sorties HTML dynamiques ;
- revue des nonces et de la sanitisation de quelques entrées `POST` / paramètres de preview ;
- suppression ou remplacement des `error_log()` de développement ;
- adaptation du garde-fou de consentement précoce afin de respecter les règles WordPress d'enqueue des scripts ;
- analyse du signalement d'offloading de contenu distant ;
- stratégie d'internationalisation et dossier `languages/` cohérent.

## 9. Avant soumission initiale

À faire manuellement en plus de la CI :

- [ ] aucune **ERROR** Plugin Check sur l'archive distribuable ;
- [ ] chaque WARNING restant a été compris et explicitement accepté ;
- [ ] exécuter la checklist P0 complète ;
- [ ] tester réellement WordPress 7.1 ;
- [ ] tester Gutenberg ;
- [ ] tester Elementor ;
- [ ] tester Divi ;
- [ ] tester la bannière et Gérer mes choix sur mobile ;
- [ ] tester installation propre, mise à jour et désactivation ;
- [ ] tester avec `WP_DEBUG` actif ;
- [ ] passer le `readme.txt` dans le validateur officiel WordPress.org ;
- [ ] installer le ZIP exact généré par GitHub Actions sur un WordPress de test vierge.

## 10. Après approbation WordPress.org

WordPress.org fournit un dépôt SVN avec notamment :

- `/trunk` pour le code courant ;
- `/tags/VERSION` pour les versions publiées ;
- `/assets` pour les icônes, bannières et captures de la fiche WordPress.org.

Attention : le dossier runtime `assets/` du plugin n'est pas le même concept que le `/assets` situé à la racine du dépôt SVN WordPress.org.

Pour publier :

1. copier le contenu du ZIP propre dans `trunk/` sans dossier intermédiaire supplémentaire ;
2. vérifier `trunk/readme.txt` ;
3. committer le trunk prêt ;
4. créer le tag avec `svn cp trunk tags/VERSION` ;
5. committer le tag ;
6. vérifier la fiche et le téléchargement sur WordPress.org.

Ne pas envoyer tous les petits commits GitHub vers le SVN : le SVN WordPress.org est un dépôt de publication.
