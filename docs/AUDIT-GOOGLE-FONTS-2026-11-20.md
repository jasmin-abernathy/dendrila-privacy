# Audit de Dendrila Privacy et Google Fonts

## Faits confirmés le 20 novembre 2026

L’audit compare le ZIP officiel 0.0.6, la tête de la PR #25 (`4f25ac2`) et la branche de correction. Il porte sur la qualification des preuves de chargement. Les essais historiques et actuels appellent le scanner PHP sur le même HTML.

| Entrée | 0.0.6 | PR #25 | Correctif |
|---|---|---|---|
| Préconnexion vers fonts.gstatic.com | Ressource observée | Indice | Indice |
| Feuille Google dans un commentaire HTML | Ressource observée | Absente | Absente |
| Vraie feuille Google déclarée | Ressource observée | Déclaration | Déclaration à vérifier en réseau |
| HTML actuel de l’accueil | Aucun résultat Google Fonts | Aucun résultat | Aucun résultat |

En 0.0.6, `technical_surface()` conservait les commentaires, les scripts et les balises contenant des URL. Une correspondance textuelle pouvait alimenter `observed_html` et le badge « Ressource externe active ». Le conseil sur Google Fonts affirmait aussi un chargement direct. Le scan des fichiers du thème parent et enfant cherche les mêmes signatures, mais cette voie fournit des références de code et ne suffit pas à attribuer un chargement à une page.

### Site public

L’échantillon accessible couvre /, /aide-pour-gerer-activite-en-ligne/, /sites-internet-au-juste-prix/, /site-internet-tarifs/, /contact/, /mentions-legales/ et /protection-donnees/. /sites-internet-a-petit-prix/ a répondu HTTP 403 au relevé automatisé.

- Aucun des sept HTML reçus ne contient `fonts.googleapis.com` ou `fonts.gstatic.com`, même dans les commentaires ou scripts inline. Aucune balise de préconnexion ou de résolution DNS anticipée n’y a été relevée.
- Les 34 URL CSS/JS locales référencées et distinctes ont été téléchargées et inspectées, y compris les feuilles du thème enfant `kadencevu`, du parent Kadence et les scripts différés déclarés par WP Rocket. Aucune occurrence des deux domaines de polices n’y a été trouvée. Le formulaire référence séparément le script Cloudflare Turnstile.
- Le DOM rendu de l’accueil contient neuf préchargements de polices locales. Les URL emploient `/wp-content/uploads/fonts/`, `/wp-content/fonts/` et `/wp-content/themes/kadencevu/polices/`. La feuille minifiée sous `/wp-content/cache/min/1/wp-content/fonts/` contient 38 occurrences de WOFF2 et aucune référence aux deux domaines Google. Les noms de fichiers Poppins et Sue Ellen Francisco permettent d’identifier les familles, tandis que l’hôte des URL détermine la provenance HTTP.
- Les réponses HTML portent `Cache-Control: max-age=0` et des dates `Last-Modified` distinctes. Le HTML comporte des attributs WP Rocket et des chemins minifiés cache. Ces faits établissent une couche d’optimisation visible, sans établir ce qu’un ancien cache contenait ni prouver que le scanner avait reçu cette même variante.

[Site audité](https://creationsite.veronique-utecht.fr/) · [Archive officielle 0.0.6](https://downloads.wordpress.org/plugin/dendrila-privacy.0.0.6.zip) · [PR #25](https://github.com/jasmin-abernathy/dendrila-privacy/pull/25)

## Corrections vérifiées

La PR #25 distingue déjà les indices des ressources déclarées et retire les commentaires CSS. Les expressions originales admettaient encore les fausses balises dans un script, une configuration ou un gabarit inactif, les attributs `data-href` et `data-rel`, les préchargements de type image et les hôtes trompeurs dans les URL CSS. Le correctif sépare ces cas, les préchargements légitimes et les feuilles de style distantes. Le badge demande une vérification réseau. Les pages portant uniquement un indice sont conservées à part, même si une autre page déclare une feuille distante.

La 0.0.6 présente la même confusion pour d’autres signatures. Un pixel Meta entièrement commenté est désormais ignoré par le scanner général. Un vrai script Meta reste détecté. Les chaînes de configuration ordinaires et les blocs conditionnels des autres services nécessiteraient des règles propres à chaque intégration. Ils ne sont pas reclassés globalement dans cette branche.

Les sept scripts de test PHP passent sous PHP 8.4. Le test Google Fonts couvre également un vrai import CSS, des familles locales, les chaînes dans le CSS et les déclarations avant/après un indice de préconnexion.

## Hypothèses et limites

Les URL des trois pages de l’analyse d’origine, son HTML brut, son journal et son état de cache ne sont pas disponibles. Le code explique comment un faux positif a pu être produit. L’occurrence exacte qui a déclenché l’analyse historique reste inconnue. Un ancien cache, une chaîne de configuration injectée sur certaines pages ou un scan conservé en base restent des explications plausibles. Une relance limitée aux erreurs peut réutiliser des résultats précédents. Une nouvelle analyse complète recrée les preuves.

La lecture du DOM rendu ne remplace pas un HAR. Chromium en ligne de commande n’a pas démarré dans cet environnement et la lecture de Resource Timing n’était pas exposée par le navigateur distant. Il n’est donc pas possible d’attester ici la liste des requêtes réellement émises après interaction, des tentatives interrompues, des connexions spéculatives ou des variantes selon cookies, mobile et cache. Aucune requête Google Fonts n’a été effectuée par le collecteur public, qui a limité ses téléchargements au site. Le scanner reste statique et n’exécute pas le JavaScript ni ne suit les imports des feuilles externes.
