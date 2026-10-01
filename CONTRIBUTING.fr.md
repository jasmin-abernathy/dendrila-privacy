# Contribuer à Dendrila Privacy

[English version](CONTRIBUTING.md)

Merci de contribuer à Dendrila Privacy.

## Avant de proposer un changement

- Vérifiez qu’une issue similaire n’existe pas déjà.
- Gardez les modifications ciblées : idéalement un correctif ou une fonctionnalité cohérente par branche/pull request.
- Préférez un fonctionnement local et respectueux de la vie privée lorsqu’un service distant n’est pas nécessaire.
- Ne commitez jamais de mots de passe, clés API, données personnelles, exports clients, dumps de base ou identifiants de production.
- Ne publiez pas silencieusement une page juridique et n’activez pas un comportement de suivi/consentement sans action explicite.

## Vérifications minimales

Avant un push ou une pull request qui modifie le code :

- exécuter `php -l` sur les fichiers PHP modifiés ;
- exécuter `node --check` sur les fichiers JavaScript modifiés ;
- tester l’activation du plugin sans erreur fatale ;
- si le scanner change, vérifier qu’une URL en erreur n’interrompt pas la suite ;
- si le consentement change, vérifier le blocage par défaut et l’égalité visuelle Accepter/Refuser ;
- si les pages juridiques changent, vérifier la conservation des brouillons et des validations explicites.

La checklist détaillée de la version courante se trouve dans `docs/TESTS-0.0.2-test4.md`.

## Pull requests

Indiquez :

1. le problème traité ;
2. ce qui a changé ;
3. comment cela a été testé ;
4. si l’interface ou le rendu public a changé.

Les petites pull requests faciles à relire sont préférées aux gros lots de changements sans rapport entre eux.
