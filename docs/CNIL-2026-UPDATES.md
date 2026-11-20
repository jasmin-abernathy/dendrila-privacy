# Actualisation CNIL 2026 — Dendrila Privacy

Revue du 20 novembre 2026. Ces éléments servent à améliorer les contrôles et les explications de Dendrila, pas à délivrer une certification RGPD.

## 1. Rejeu de session

**Source CNIL :** https://www.cnil.fr/fr/rejeu-de-session-recommandation (recommandation adoptée le 8 octobre 2026, publiée le 5 novembre).

La CNIL rappelle que les outils de rejeu de session relèvent des traceurs soumis au consentement préalable (article 82 de la loi Informatique et Libertés), sans exemption applicable au rejeu. Information explicite dès le **premier niveau** du bandeau, minimisation des enregistrements et masquage des champs, conversations et zones sensibles.

Le plugin détectait déjà les ressources Clarity et Hotjar, mais ne pouvait pas conclure à lui seul que leurs fonctionnalités d'enregistrement étaient activées. Quatre nouveaux SDK sont reconnus et traités comme facultatifs **lorsque le consentement natif est activé** :

- Fullstory : `edge.fullstory.com/s/fs.js`, notamment `edge.eu1.fullstory.com/s/fs.js` ;
- Mouseflow : `cdn.mouseflow.com/projects/` ;
- Smartlook : `web-sdk.smartlook.com/recorder.js` ;
- LogRocket : `cdn.logrocket.io`, `cdn.logr-in.com`.

Les sources des signatures sont les documentations éditeurs :
https://help.fullstory.com/hc/en-us/articles/360041242094-Fullstory-on-a-CDN
https://help.mouseflow.com/en/articles/4272029-cross-domain-support
https://web.developer.smartlook.com/docs/getting-started
https://docs.logrocket.com/docs/troubleshooting-sessions

Lorsqu'un scan observe un script d'un de ces outils (ou Clarity/Hotjar), la bannière mentionne **la possibilité de rejeu dès le premier niveau**. Le choix précédemment enregistré est invalidé par une nouvelle empreinte uniquement pour les sites concernés. Un outil installé sans activité détectée n'est pas présenté comme un rejeu actif. Les scripts CDN sont bloqués avant accord via le moteur existant ; les proxys first-party et le suivi lancé avant le bootstrap restent des limitations techniques.

## 2. Pixels de suivi dans les courriels

**Sources CNIL :**
https://www.cnil.fr/fr/recommandation-pixel-suivi-courriels (adoption le 12 mars, publication le 14 avril 2026) et https://www.cnil.fr/fr/faq-recommandation-pixels-courriers-electroniques (22 juillet 2026).

L'inscription à une newsletter ne constitue pas automatiquement un accord aux pixels de suivi. Il existe certaines exemptions **conditionnelles** de délivrabilité, notamment dans le contexte d'un message explicitement demandé ; une relance de panier promotionnelle n'est pas assimilable à un courriel transactionnel. Les liens servant au retrait du consentement ne doivent pas être confondus avec les liens marketing individualisés.

L'assistant rappelle ces distinctions et évite de prétendre régler automatiquement les options internes des plateformes mail externes. Les presets juridiques ne présentent plus l'option de délivrabilité comme une exemption automatique.

## 3. Consentement entre appareils

**Source CNIL :** https://cnil.fr/fr/cookies-et-autres-traceurs-recommandations-finales-sur-le-consentement-multi-terminaux (16 janvier 2026).

La recommandation vise les utilisateurs connectés et les préférences pouvant s'appliquer sur plusieurs terminaux. L'information doit être présentée avant le choix, dès le premier niveau. Le refus et le retrait doivent être aussi simples et aussi étendus que l'acceptation ; les conflits entre choix local et choix du compte doivent être résolus loyalement.

Dendrila conserve actuellement des choix **locaux au navigateur** et ne synchronise pas les consentements entre appareils ; l'assistant explique les exigences applicables aux gestionnaires qui le font, sans introduire de collecte de comptes.

## 4. Digital Omnibus

**Source Conseil UE :** https://www.consilium.europa.eu/fr/press/press-releases/2026/10/21/digital-rulebook-council-agrees-position-to-simplify-data-protection-privacy-and-cyber-reporting-rules/

La position de négociation du Conseil du 21 octobre 2026 ne constitue pas une loi applicable. Dendrila n'assouplit pas les exemptions cookies sur cette seule base.

## Validation avant fusion

- Tester les cinq domaines ci-dessus en simulation et sur navigateur réel : aucun appel aux SDK avant consentement, réactivation selon la catégorie.
- Tester une installation WooCommerce + Divi : paiement, panier et ressources fonctionnelles doivent fonctionner sans consentement facultatif.
- Tester les sites sans analyse connue et ceux où Clarity ou Hotjar sont simplement installés mais non actifs.
- Vérifier à la main le masquage des formulaires, conversations et zones sensibles sur un site qui enregistre réellement des sessions.
- Tester le parcours de l'assistant et les nouveaux textes juridiques générés avant toute publication WordPress.org.
