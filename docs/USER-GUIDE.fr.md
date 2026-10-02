# Dendrila Privacy — Guide utilisateur

## À quoi sert PTM ?

Dendrila Privacy est un assistant WordPress d’audit de confidentialité et de documentation. Il aide à voir quels services tiers sont réellement actifs, à documenter les usages de données personnelles qui concernent le site, à maintenir les pages légales et, si vous le souhaitez, à gérer le consentement des visiteurs.

Il ne s’agit pas d’un outil de certification juridique. Un tableau de bord au vert signifie que PTM peut rendre compte des éléments applicables qu’il sait contrôler ; cela ne signifie pas que toutes les obligations juridiques possibles ont été certifiées.

## Première ouverture

L’activation de PTM ne lance pas de scan et ne modifie pas les pages publiques. L’assistant de configuration apparaît uniquement lorsqu’un administrateur ouvre Dendrila Privacy pour la première fois.

L’assistant vérifie d’abord les trois pages de référence : Mentions légales, Politique de confidentialité et Cookies / Consentement. Il peut proposer une page existante, permettre d’en choisir une autre ou créer un brouillon adapté lorsqu’aucune page probable n’existe.

L’assistant peut être quitté puis repris, ou relancé plus tard depuis Réglages.

## Parcours simple conseillé

1. **Confirmer les pages juridiques de référence.** Utilisez l’assistant de première ouverture ou les Réglages.
2. **Lancer une analyse du site.** Une analyse complète permet d’établir un premier état de référence.
3. **Examiner ce qui a été détecté.** « Actif » correspond à une preuve technique plus forte qu’un plugin simplement installé.
4. **Compléter uniquement ce que le site ne peut pas dire.** Une information facultative inconnue n’est pas transformée automatiquement en erreur.
5. **Vérifier la couverture documentaire.** Les éléments manquants ou partiels doivent mener directement à la section correspondante de l’Assistant RGPD.
6. **Mettre à jour les pages.** Les modifications importantes doivent rester explicites et contrôlables.
7. **Activer le consentement uniquement si vous le souhaitez.** La bannière native est désactivée par défaut.

## Consentement : règle essentielle

PTM n’essaie pas de maximiser les clics sur « Accepter ». **Tout accepter** et **Tout refuser** doivent avoir le même poids visuel. Les catégories facultatives commencent désactivées. Fermer l’interface ne vaut pas consentement.

La bannière est rendue comme un composant global indépendant du constructeur de pages. Au chargement, PTM la place directement sous `<body>` afin qu’elle ne reste pas enfermée dans une section Divi, un container Elementor ou un autre contexte d’empilement pouvant la faire passer derrière le contenu.

Les visiteurs peuvent rouvrir directement leurs préférences avec **Gérer mes choix**. Le shortcode universel est `[dendrila_privacy_consent_settings]`.

Pour un bouton personnalisé créé dans Divi, Elementor, Gutenberg ou le thème, vous pouvez aussi utiliser :

```html
<button type="button" class="ptm-consent-open">Gérer mes choix</button>
```

ou ajouter l’attribut suivant à un contrôle existant :

```html
data-ptm-consent-open="preferences"
```

L’attribut est préférable si vous souhaitez conserver entièrement le style natif du bouton fourni par votre constructeur.

Les intégrations avancées peuvent appeler :

```js
window.DendrilaPrivacyConsentAPI.openPreferences();
```

La documentation technique complète se trouve dans `docs/CONSENT-INTEGRATION.md`.

## Constructeurs de pages

### Éditeur WordPress / Gutenberg

Utilisez les shortcodes publics dans un bloc Shortcode ou laissez PTM mettre à jour les pages prises en charge après une action explicite.

### Elementor

PTM peut lire localement les contenus utiles et créer de nouvelles pages juridiques avec une structure Elementor lorsqu’il est clairement détecté. Le plugin doit respecter la structure réellement utilisée par le site et éviter de réécrire des données qu’il ne comprend pas assez sûrement.

Le widget **Gérer mes choix** reste disponible. La bannière elle-même n’est pas un widget Elementor : elle reste globale afin d’éviter les problèmes de positionnement et de dépendance au builder.

### Divi

PTM sait reconnaître les principaux contenus utiles de Divi et travaille à la création native des nouvelles pages juridiques Divi 5. Le moteur de consentement reste indépendant de Divi.

La bannière n’est pas rendue à l’intérieur d’une section ou d’un module Divi : PTM la remonte directement sous `<body>`. Les contrôles « Gérer mes choix » sont écoutés en phase capture afin qu’un script Divi qui intercepte ensuite le clic ne puisse pas empêcher PTM d’ouvrir les préférences.

Quand un module dynamique a été bloqué avant consentement, un adaptateur peut être nécessaire pour le réinitialiser après autorisation, par exemple pour certaines cartes ou protections anti-spam.

### Autres constructeurs

PTM reconnaît de manière prudente plusieurs constructeurs courants. Lorsqu’aucun adaptateur d’écriture fiable n’existe, il privilégie le shortcode et l’édition manuelle plutôt qu’une modification hasardeuse de la structure interne.

Le moteur public de consentement reste indépendant de ces constructeurs.

## Shortcodes publics

- `[dendrila_privacy_legal_notice]`
- `[dendrila_privacy_privacy_policy]`
- `[dendrila_privacy_cookies]`
- `[dendrila_privacy_services]`
- `[dendrila_privacy_rights]`
- `[dendrila_privacy_documents]`
- `[dendrila_privacy_consent_settings]`

## Erreurs de scan

Une page en erreur n’arrête pas le reste de l’analyse. Une fois toutes les URL prévues traitées, la progression atteint 100 %, même si certaines ont échoué.

Une 404 qui correspond en réalité à un brouillon WordPress, une page privée, en attente ou programmée doit être présentée comme une action WordPress à traiter plutôt que comme une panne réseau classique.

## Outils et pratiques externes

Certaines pratiques ne peuvent pas être détectées depuis WordPress : envois groupés depuis Gmail/Outlook, contacts WhatsApp, réservations via Calendly/Koalendar, formulaires externes, tableurs, services de paiement, etc.

PTM peut donc demander confirmation lorsque le contexte humain est nécessaire, tout en essayant d’abord de détecter les services visibles depuis le site.

## Service externe facultatif

La recherche facultative d’organisation ne part qu’après une action explicite de l’administrateur. La France utilise l’API publique Recherche d’entreprises, la Norvège l’API publique Enhetsregisteret de Brønnøysundregistrene, et les pays UE pris en charge / l’Irlande du Nord utilisent VIES pour la validation TVA. Aucun résultat d’audit, contenu de page, réponse de l’assistant ou choix de consentement visiteur n’est joint à ces requêtes.
