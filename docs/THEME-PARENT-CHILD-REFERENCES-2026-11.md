# Analyse contextuelle des thèmes parent et enfant

## Modification

Dendrila Privacy sépare désormais le thème actif/enfant du thème parent lors de
la recherche de signatures. Chaque référence présente un fichier, une ligne,
le rôle du thème et l'éventuelle présence d'un fichier homonyme dans l'enfant.

Attention : les fichiers des deux thèmes ne s'annulent pas systématiquement.
Les fichiers functions.php sont chargés ensemble, les fichiers PHP inc/
peuvent être inclus par le thème parent, tandis que les gabarits courants
racine et template-parts/ donnent habituellement priorité à l'enfant.
Cette priorité reste indicative : les appels PHP dynamiques ne sont pas tracés.

Une référence Google Fonts dans le code du thème ne signifie jamais que Google
Fonts a été chargé depuis un serveur tiers. Le badge précise « Référence de code,
non confirmée » tant qu'aucune déclaration externe n'est trouvée dans le HTML.
Une déclaration HTML n'est pas davantage une preuve réseau ; elle nécessite
une vérification dans l'onglet Réseau du navigateur.

Le rapport donne jusqu'à 6 fichiers par signature et par thème, avec un plafond
de 600 fichiers et 16 Mio lus par thème. Les références sont des indices
contextualisés ; il ne s'agit pas d'un moteur d'exécution PHP ou JavaScript.

## Régression

php tests/google-fonts-evidence.php

Les fixtures couvrent un module PHP de même nom, deux functions.php, un
gabarit enfant, un fichier CSS enfant, et l'absence de déclaration distante
dans une page qui ne charge qu'une feuille CSS locale.

## Suivi

Un test WordPress réel doit contrôler le cas Kadence parent + enfant avec
cache, et distinguer les observations HTML des requêtes réseau effectivement
émises. La réanalyse doit être complète : « réessayer les pages en erreur »
réutilise des observations de l'analyse précédente.
