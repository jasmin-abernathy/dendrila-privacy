# Dendrila Privacy — WP Consent API et blocage de ressources (proposition)

## Portée

L’intégration n’est active que si la gestion native du consentement Dendrila est activée et si le plugin WP Consent API est disponible. Elle ne remplace pas WP Consent API et ne déclenche aucune installation automatique.

- Le type WP Consent API est « optin » côté PHP et au plus tôt dans le navigateur. Sans choix valide, les catégories optionnelles sont refusées.
- Une synchronisation a lieu à l’initialisation, après le chargement de l’API, à chaque choix enregistré et à l’événement load en cas de scripts différés.
- Correspondances : « statistics » et « statistics-anonymous » suivent « statistics » Dendrila ; « marketing » suit « marketing » ; « functional » est autorisée car strictement nécessaire ; « preferences » reste refusée, car « external » (vidéos/cartes) n’autorise pas les préférences génériques.
- Les modes aperçu et test administrateur ne modifient pas les cookies de l’API.
- Si l’API n’est pas installée, Dendrila continue de fonctionner normalement avec son moteur autonome.

## Prévention réseau

La neutralisation PHP existante et le bootstrap chargé tôt sont conservés. Le bootstrap vérifie les ressources connues, y compris dans les descendants d’un élément ajouté dynamiquement, avant l’insertion effective dans le DOM. Les chemins pris en compte incluent appendChild, insertBefore, replaceChild, append, prepend, replaceChildren, insertAdjacentElement, innerHTML et insertAdjacentHTML (pour les navigateurs où ces API sont configurables). Les préchargements reconnus via link[href] sont neutralisés puis réactivés après accord.

**Limites** : l’interception JavaScript ne peut pas empêcher une requête lancée avant le bootstrap ; certaines méthodes natives ou scripts dans des shadow roots peuvent contourner ces wrappers. Les scripts déjà exécutés ne sont pas « dés-exécutés » après révocation : recharger la page reste recommandé. Les blocs inconnus/non répertoriés nécessitent un contrôle manuel. Aucun test simulé ne démontre à lui seul l’absence de requête réseau réelle.

## Vérification sur navigateur réel AVANT fusion

1. Installer le ZIP de la PR sur un WordPress de test, avec puis sans WP Consent API. Tester Gutenberg, Divi et Elementor, hors éditeur visuel.
2. Effacer cookies et stockage local. Ouvrir l’onglet Réseau (conserver le journal), recharger : aucune requête vers youtube.com/embed, youtube-nocookie.com/embed, player.vimeo.com, google.com/maps/embed, google-analytics.com, connect.facebook.net pour les exemples couverts.
3. Insérer par JavaScript : script.src, iframe.src, img.src, sous-arbres appendChild, insertAdjacentHTML, innerHTML, preloads link. Vérifier dans Réseau zéro départ avant consentement, puis le chargement attendu lorsque la catégorie est acceptée.
4. Essayer « tout refuser », « statistiques seulement », « tout accepter », puis une modification de choix et un rechargement. Contrôler que seuls les services autorisés partent.
5. Avec WP Consent API : vérifier wp_get_consent_type() = optin et les catégories retournées par wp_has_consent() avant/après consentement et après navigation. Confirmer les événements wp_listen_for_consent_change.
6. Tester cache, optimisation JavaScript différée, URLs de tiers partagées entre catégories, iframe srcdoc, CSS background, embeds via shadow DOM, gestion du retrait du consentement et contenu inséré par builders.
7. Vérifier les pages légales, la bannière, le clavier, les menus et les modules de formulaire après neutralisation.

Les tests Node (sans dépendances externes) couvrent les propriétés élémentaires du blocage et la synchronisation des catégories ; ils ne remplacent pas ce protocole réseau.
