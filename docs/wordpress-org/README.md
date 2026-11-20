# Dendrila Privacy 0.0.7 sur WordPress.org

Les deux catalogues français sont destinés à GlotPress. Ils ne sont pas inclus dans le ZIP de l’extension. Le catalogue de la fiche traduit le contenu de `readme.txt` pour la version 0.0.7.

1. Après validation de la PR de préparation, créer le tag Git `v0.0.7` sur le commit fusionné.
2. Attendre les deux workflows du tag. « Dendrila Privacy quality and distribution » vérifie notamment la syntaxe, WordPress Plugin Check et le ZIP. « Dendrila consent browser network test » vérifie le blocage et le consentement dans Chromium.
3. Lancer manuellement « Publier la 0.0.7 sur WordPress.org » depuis `master`. Il vérifie la réussite des deux workflows sur le même commit et réutilise le secret SVN du dépôt. Confirmer ensuite la version dans WordPress.org si la confirmation de publication est activée.
4. Une fois les originaux de la 0.0.7 importés dans GlotPress, utiliser `readme-fr_FR.po` pour les nouvelles chaînes de [Stable Readme](https://translate.wordpress.org/projects/wp-plugins/dendrila-privacy/stable-readme/fr/default/). Le fichier `plugin-fr_FR.po` correspond au projet [Stable](https://translate.wordpress.org/projects/wp-plugins/dendrila-privacy/stable/fr/default/). L’équipe française ou un PTE doit valider les traductions proposées. Vérifier ensuite que le paquet `fr_FR` est disponible et que la description française s’affiche dans l’annuaire.

Le 20 novembre 2026, 73 entrées de la fiche stable 0.0.6 et les 5 chaînes du code stable ont été soumises et sont en attente de validation.
