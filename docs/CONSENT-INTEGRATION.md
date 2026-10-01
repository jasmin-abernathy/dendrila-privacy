# PTM — intégration de la bannière de consentement

## Architecture retenue

La bannière de Pixel Trackers Manager est un composant global indépendant de Gutenberg, Elementor, Divi et des autres constructeurs.

Le PHP peut produire le conteneur via `wp_body_open` ou, si le thème ne l’expose pas correctement, via le fallback `wp_footer`. Au chargement du front, le moteur JavaScript récupère ensuite `#pixel-trackers-manager-consent` et le monte directement sous `<body>`.

Objectif : ne jamais laisser le dialogue enfermé dans un conteneur de builder susceptible de créer un contexte d’empilement (`transform`, `z-index`, `overflow`, sticky, popup, etc.).

Le conteneur reçoit `data-ptm-mounted="body"` une fois monté.

## Ouverture des préférences depuis n’importe quel builder

PTM écoute les contrôles d’ouverture au niveau `document` **en phase capture**. Le clic est donc vu par PTM avant les handlers de bubbling d’un thème ou d’un builder.

Deux formes sont reconnues :

```html
<button type="button" class="ptm-consent-open">Gérer mes choix</button>
```

ou :

```html
<a href="#" data-ptm-consent-open="preferences">Gérer mes choix</a>
```

Cela permet d’utiliser le même mécanisme dans Gutenberg, Elementor, Divi, un menu, un footer, une popup ou un module HTML/code.

Le shortcode `[pixel_trackers_manager_consent_settings]` et le widget Elementor restent les moyens simples à privilégier lorsqu’ils conviennent.

## API JavaScript publique

PTM expose également :

```js
window.PixelTrackersManagerConsentAPI.open();
window.PixelTrackersManagerConsentAPI.openPreferences();
window.PixelTrackersManagerConsentAPI.close();
window.PixelTrackersManagerConsentAPI.getChoice();
window.PixelTrackersManagerConsentAPI.saveChoice({
    statistics: false,
    external: true,
    marketing: false
});
```

`openPreferences()` est le point d’entrée recommandé pour un bouton personnalisé « Gérer mes choix ».

`getChoice()` est en lecture seule et renvoie `null` lorsqu’aucun choix valide n’est enregistré.

`saveChoice()` doit être réservé aux interfaces contrôlées par PTM ou à une intégration volontaire : il enregistre réellement un nouveau choix.

Pour compatibilité, les mêmes méthodes sont aussi ajoutées à `window.PixelTrackersManagerConsent`.

## Événements publics

PTM émet :

- `pixel-trackers-manager:consent-opened` ;
- `pixel-trackers-manager:consent-closed` ;
- `pixel-trackers-manager:consent-updated`.

L’événement `consent-updated` contient l’état des catégories `statistics`, `external` et `marketing`.

## Comportement de « Gérer mes choix »

Lorsqu’un visiteur a déjà enregistré un choix :

1. le bouton rouvre le dialogue ;
2. le panneau de préférences est ouvert directement ;
3. les cases reflètent le choix actuel ;
4. l’utilisateur peut modifier puis enregistrer ;
5. le focus revient au contrôle d’origine après fermeture lorsque celui-ci existe encore.

## CSS et builders

Seules les propriétés structurelles indispensables sont renforcées avec `!important` :

- `position: fixed` ;
- `inset: 0` ;
- `z-index` ;
- dimensions viewport ;
- absence de transformation héritée sur le conteneur/dialogue.

Les couleurs, typographies et autres choix visuels restent dans le système de style PTM et peuvent continuer à s’intégrer au site.

## Divi / Elementor

La bannière elle-même n’a pas d’adaptateur Divi ou Elementor : c’est volontaire.

Les adaptations spécifiques aux builders restent limitées aux **contenus qui ont été bloqués avant consentement** et qui peuvent nécessiter une réinitialisation après autorisation, par exemple certaines Maps Divi ou intégrations dynamiques.

Le moteur reste désactivé dans les éditeurs visuels afin de ne pas casser l’expérience d’édition.

## Cache

Pendant le cycle `0.0.2-test4`, les assets de consentement utilisent un suffixe de cache dédié (`consent-portal1`) afin que les sites de test chargent bien le nouveau JS/CSS même si le numéro général du plugin n’a pas encore changé.

## Tests minimum

Avant de considérer ce correctif comme stabilisé :

- tester un site Gutenberg ;
- tester un site Elementor ;
- tester un site Divi ;
- vérifier que la bannière est directement sous `<body>` ;
- vérifier qu’elle reste au-dessus des éléments sticky/popups ;
- enregistrer un choix puis rouvrir **Gérer mes choix** ;
- tester un bouton personnalisé avec `.ptm-consent-open` ;
- tester un contrôle personnalisé avec `data-ptm-consent-open="preferences"` ;
- tester `PixelTrackersManagerConsentAPI.openPreferences()` depuis la console ;
- vérifier le clavier, Escape et le retour du focus.

La checklist détaillée se trouve dans `docs/TESTS-0.0.2-test4.md`.
