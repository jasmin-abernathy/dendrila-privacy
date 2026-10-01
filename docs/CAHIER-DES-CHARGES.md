# Pixel Trackers Manager — Cahier des charges consolidé

**Date de consolidation : 27 août 2026**  
**Version de travail associée : 0.0.2-test4**

## 1. Positionnement produit

Pixel Trackers Manager (PTM) est un assistant WordPress d’audit technique de confidentialité, de documentation et de correction guidée.

PTM doit répondre à quatre questions simples :

1. **Que fait réellement ce site avec des services tiers et des traceurs ?**
2. **Qu’est-ce qui mérite une vérification ou une correction ?**
3. **Les pages légales et la gestion du consentement reflètent-elles la situation observée ?**
4. **Comment corriger et documenter cela sans imposer à l’administrateur un jargon technique ou juridique inutile ?**

PTM **n’est pas un gestionnaire marketing de tags**. Il ne doit pas chercher à reproduire WP Full Picture, Google Tag Manager ou un catalogue d’intégrations publicitaires servant à installer GA, Meta Pixel, TikTok, Google Ads, CAPI, etc. Il doit en revanche pouvoir **détecter, analyser, expliquer et vérifier** ces intégrations lorsqu’elles existent déjà.

PTM ne certifie pas juridiquement une conformité RGPD et ne remplace pas un conseil juridique contextualisé.

---

## 2. Principes non négociables

### 2.1 Confidentialité et sobriété
- Les données d’audit, réponses de l’assistant et préférences d’administration restent dans WordPress par défaut.
- Aucun résultat de scan n’est envoyé à l’éditeur de PTM.
- Les services externes facultatifs ne sont appelés qu’après action explicite de l’administrateur.
- Le plugin doit éviter les recalculs, crawls et requêtes réseau inutiles.

### 2.2 UX non punitive
- Pas de vocabulaire culpabilisant.
- Pas de faux sentiment de certification.
- Les recommandations ne doivent pas être présentées comme des infractions certaines.
- Les champs facultatifs non renseignés restent silencieux tant qu’ils n’empêchent pas une génération cohérente.
- Les sujets « si concerné » ne deviennent des manques qu’après confirmation que le cas s’applique ou lorsqu’un signal technique suffisamment clair existe.

### 2.3 Consentement sans dark patterns
- « Tout accepter » et « Tout refuser » ont le même niveau d’accès et le même poids visuel.
- Aucune catégorie facultative n’est précochée.
- Fermer l’interface sans répondre ne vaut pas consentement.
- Le choix peut être retiré/modifié facilement.
- Les traceurs facultatifs reconnus restent bloqués avant le choix lorsque la CMP native PTM est activée.

### 2.4 Compatibilité par dégradation sûre
- Le moteur de consentement ne dépend d’aucun constructeur de page.
- PTM reconnaît et utilise les structures qu’il sait traiter de façon fiable.
- S’il ne maîtrise pas un format, il ne réécrit pas sa structure : il propose un shortcode ou une action manuelle sûre.

---

# 3. Priorité P0 — première version publiable

## 3.1 Onboarding / première ouverture

### Déclenchement
- L’activation de l’extension reste silencieuse.
- L’assistant de configuration se lance **à la première ouverture de Pixel Trackers Manager**.
- Si l’utilisateur quitte le parcours, PTM mémorise l’étape et reprend au même endroit lors du prochain accès.
- L’assistant reste relançable depuis Réglages.

### Direction visuelle
Le setup doit s’inspirer des qualités du parcours Google Site Kit sans copier son identité :
- grande surface claire et aérée ;
- identité PTM visible mais discrète ;
- une décision principale par écran ;
- peu de texte à la fois ;
- cartes simples ;
- CTA principal évident ;
- progression courte (« Étape 2 sur 4 ») ;
- possibilité de quitter/reprendre.

### Parcours cible en 4 étapes
1. **Bienvenue** — expliquer en quelques lignes ce que PTM va préparer.
2. **Pages légales** — détecter/sélectionner/créer les trois destinations.
3. **Consentement** — conserver une CMP existante ou présenter clairement la solution PTM.
4. **Premier contrôle** — recommander fortement une analyse complète.

### Premier scan
- Le CTA principal final doit être **« Lancer l’analyse complète — recommandé »**.
- Le scan rapide reste disponible comme alternative secondaire.
- Aucun scan n’est lancé automatiquement sans clic explicite.
- L’analyse complète du premier lancement pourra plus tard devenir le premier snapshot de référence.

---

## 3.2 Rappel sur la page d’accueil de wp-admin

Tant que l’onboarding n’est pas terminé :
- afficher un encart sur **Tableau de bord > Accueil** ;
- texte court : PTM n’est pas encore configuré ;
- CTA : **Configurer Pixel Trackers Manager** ;
- possibilité de masquer l’encart ;
- le dismiss est enregistré **par utilisateur**.

Une fois l’onboarding terminé mais avant la première analyse complète :
- remplacer par une invitation plus légère à lancer le premier scan complet ;
- cette invitation est elle aussi masquable.

Après un premier scan complet :
- plus de rappel.

---

## 3.3 Pages légales

### Trio de référence
- Mentions légales
- Politique de confidentialité
- Cookies / gestion du consentement

### Détection et sélection
Pour chaque destination :
- chercher les pages probables ;
- proposer la meilleure candidate ;
- si plusieurs candidates existent, demander confirmation ;
- toujours permettre de choisir une autre page existante ;
- ne pas créer de doublon si une page crédible existe déjà.

### Création d’une page absente
- Action explicite **Créer cette page**.
- Créer un **brouillon**, jamais une publication silencieuse.
- Réutiliser les informations déjà connues par PTM.
- Ne jamais bloquer la création pour des informations facultatives.
- Shortcodes publics de base :
  - `[pixel_trackers_manager_legal_notice]`
  - `[pixel_trackers_manager_privacy_policy]`
  - `[pixel_trackers_manager_cookies]`

### Constructeurs
Détection **page par page**, jamais par simple présence d’un thème/plugin :
- WordPress/Gutenberg : éditeur WordPress ;
- Elementor : lire/mettre à jour la structure connue (`text-editor`, `settings.editor`) en préservant les métadonnées ;
- Divi : lire/mettre à jour les modules connus lorsque le format a été validé ;
- autres builders : fallback prudent.

Dans l’interface normale, ne jamais afficher « Divi » ou « Elementor » si la page concernée n’utilise pas ce builder.

### Publication / mise à jour
- Aucune modification silencieuse.
- Afficher clairement la page concernée et le type d’éditeur.
- Actions : **Mettre à jour cette page** et **Mettre à jour toutes les pages**.
- L’action groupée doit afficher une progression page par page.

---

## 3.4 Assistant RGPD — vitesse et fonctionnement

### Règle de performance
Une sauvegarde de champ ou de bloc doit faire :

> validation locale → sauvegarde → réponse AJAX

Elle ne doit **pas** déclencher :
- crawl ;
- requête HTTP sur les pages publiques ;
- analyse complète des pages légales ;
- téléchargement d’un document distant ;
- recalcul global inutile.

### Cache
Pour les pages WordPress, utiliser une clé de fraîcheur basée au minimum sur :
- `page_id` ;
- `post_modified` ;
- hash du contenu utile / structure builder utile.

Si rien n’a changé, ne pas réanalyser.

Pour les ressources externes, exploiter lorsque disponible :
- ETag ;
- Last-Modified ;
- cache temporel raisonnable.

### Navigation
- Pas de `window.location.reload()` pour passer à l’aperçu final de l’assistant.
- Aperçu généré depuis les données locales enregistrées et rafraîchi en AJAX.
- L’audit public est une action distincte.
- Les huit étapes du questionnaire restent franchissables ; aucune étape ne doit piéger l’utilisateur.

### Principe de questions
> Ne pas demander à l’utilisateur ce que PTM peut déjà déduire du site.

---

## 3.5 Scanner

### Deux modes
- Analyse standard : rapide, adaptée aux contrôles courants.
- Analyse complète : plus large, recommandée au premier lancement.

### Progression
La barre représente **l’avancement du traitement**, jamais le taux de réussite.

Formule :

> URL ayant reçu un résultat final / URL prévues

Une URL compte comme traitée lorsqu’elle aboutit à :
- succès ;
- redirection traitée ;
- 403 ;
- 404 ;
- 500 ;
- timeout ;
- erreur réseau finale.

Ainsi : **toutes les URL traitées = 100 %**, même avec des erreurs.

### Erreurs
À la fin :
- nombre total de pages traitées ;
- nombre de réussites ;
- nombre d’erreurs ;
- liste des erreurs ;
- action **Réessayer uniquement ces pages**.

Le retry ciblé :
- conserve les résultats réussis ;
- ne rescane que les échecs ;
- ne repart pas de zéro.

Une erreur ne doit jamais arrêter le traitement des autres pages.

### Lisibilité
- Afficher les titres de pages plutôt que des URL longues quand cela améliore la compréhension.
- Décoder correctement accents, apostrophes et caractères spéciaux.
- Ne pas confondre « URL découverte » et « page WordPress publiée ».

---

## 3.6 Score / couverture / actions

PTM doit séparer clairement :

### Couverture documentaire
Nombre d’exigences **réellement applicables** documentées / nombre total d’exigences applicables.

Exemple : `17 / 20 = 85 %`.

### Actions techniques
Les modifications restant à effectuer sur le site sont une liste distincte.

Règles :
- une exigence applicable compte une fois ;
- un sujet conditionnel ne compte qu’une fois déclaré applicable ;
- une recommandation ne baisse jamais le score ;
- une action d’implémentation ne doit pas être confondue avec une donnée documentaire manquante ;
- 100 % signifie que toutes les exigences évaluables et applicables sont satisfaites, pas que PTM certifie juridiquement le site.

États lisibles :
- En ordre
- À vérifier
- À corriger
- Non applicable

---

## 3.7 Consentement natif PTM

### Présentation beaucoup plus claire
Si aucune CMP n’est détectée, l’interface ne doit plus présenter la CMP native comme une option technique obscure.

Carte dédiée :
- expliquer que PTM peut afficher une **barre** ou un **encart** ;
- expliquer qu’il peut bloquer les services facultatifs connus avant le choix ;
- CTA clair : **Configurer la gestion du consentement** ;
- montrer un aperçu visuel.

Formats :
- barre en bas ;
- encart centré.

Styles :
- s’intégrer au site ;
- style neutre PTM.

### Si une CMP tierce existe
- Ne pas pousser au remplacement.
- Afficher la solution détectée.
- CTA : **Vérifier son fonctionnement**.

### Si traceurs facultatifs + aucune CMP
Message renforcé mais factuel :
> Des services susceptibles de nécessiter un consentement ont été détectés, mais aucune interface de consentement n’a été trouvée.

### Moteur
- CMP PTM désactivée par défaut à l’installation.
- Blocage avant choix lorsqu’activée.
- fermeture ≠ acceptation ;
- catégories pertinentes uniquement ;
- aucun pré-cochage ;
- accès permanent « Gérer mes choix » ;
- moteur désactivé dans les éditeurs de builders.

---

## 3.8 Design global PTM

### Direction
L’interface générale doit être :
- moins austère ;
- moins « réglages WordPress gris » ;
- plus douce, cohérente et lisible ;
- sérieuse sans devenir froide ;
- accessible en contraste et navigation clavier.

### Onglets
- barre visuellement raccordée au contenu ;
- onglet actif intégré au panneau ;
- couleurs pâles ;
- premier onglet : angle supérieur gauche arrondi ;
- dernier onglet : angle supérieur droit arrondi ;
- onglets intermédiaires raccordés ;
- hover/focus clair mais discret.

### Boutons
- Éviter les boutons gris durs comme style secondaire par défaut.
- Palette douce : sauge, beige, bleu très pâle, tons neutres.
- Rouge réservé aux vrais états destructifs/erreurs.
- Hiérarchie primaire/secondaire toujours claire.

### Variables communes
Utiliser un petit design system commun, par exemple :
- `--ptm-surface`
- `--ptm-surface-soft`
- `--ptm-border`
- `--ptm-text`
- `--ptm-muted`
- `--ptm-primary`
- `--ptm-primary-soft`
- `--ptm-success-soft`
- `--ptm-warning-soft`
- `--ptm-danger-soft`
- `--ptm-radius`

Le wizard, la Vue d’ensemble, le scanner, les pages légales et la CMP doivent parler le même langage visuel.

---

## 3.9 Vue d’ensemble / dashboard PTM

La page d’accueil PTM est un **récapitulatif navigable**, pas une page statique.

### Règle centrale
> Si une information résume une section PTM, cliquer dessus doit mener à la section correspondante, idéalement déjà filtrée ou positionnée sur l’élément concerné.

À rendre cliquable autant que possible :
- carte Analyse → scanner ;
- nombre d’erreurs → liste des erreurs ;
- date du dernier scan → rapport / zone analyse ;
- couverture documentaire → détail du calcul ;
- carte Pages légales → gestion des pages ;
- « 2/3 » → page manquante mise en évidence ;
- carte Consentement → configuration / vérification ;
- carte Actions → liste filtrée ;
- Traceurs & services → section correspondante ;
- badges « À vérifier » → élément concerné ;
- éléments « Ce qui manque » → assistant / bloc correspondant.

Quand possible, rendre la carte entière cliquable plutôt qu’un petit lien isolé.

### Résumé attendu
La vue doit répondre rapidement à :
1. Qu’a trouvé PTM ?
2. Qu’est-ce qui demande mon attention ?
3. Quelle action puis-je faire maintenant ?

---

## 3.10 Vocabulaire / microcopy

Éviter dans le parcours normal :
- structure interne ;
- adaptateur d’écriture ;
- injection builder ;
- endpoint ;
- crawler ;
- jargon technique non expliqué.

Préférer :
- Page trouvée
- Modifier la page
- Vérifier
- Réessayer
- À vérifier
- Aucun outil de consentement détecté

Nom public unique : **Pixel Trackers Manager**.

---

## 3.11 Tests builders avant publication

Validation réelle minimale :
- WordPress / Gutenberg / thème standard récent ;
- Elementor ;
- Divi.

Autres builders à reconnaître prudemment :
- Bricks
- Beaver Builder
- WPBakery
- Oxygen
- Breakdance
- Brizy
- SiteOrigin
- Avada

PTM ne réécrit pas un stockage inconnu.

---

# 4. Priorité P1 — immédiatement après stabilisation

## 4.1 Setup Helper / contrôle de configuration
Inspiré de la bonne idée de WP Full Picture, sans transformer PTM en tag manager.

Après configuration, proposer un contrôle lisible :
> 8 points vérifiés · 2 à examiner

Exemples :
- politique de confidentialité accessible ;
- page cookies accessible ;
- CMP détectée ;
- traceurs facultatifs bloqués avant choix ;
- lien de gestion des choix présent ;
- page légale configurée mais 404 ;
- service déclaré désactivé mais encore observé.

---

## 4.2 Reconsentement intelligent lors d’un changement matériel

PTM doit versionner la configuration à laquelle un visiteur a consenti.

L’empreinte peut prendre en compte :
- services actifs ;
- catégories ;
- finalités ;
- version des textes de consentement ;
- version significative de la politique applicable.

Si un changement matériel survient :
- prévenir l’administrateur ;
- invalider l’ancien choix lorsque nécessaire ;
- demander un nouveau choix au visiteur.

Une correction de typo ne doit pas provoquer automatiquement un reconsentement. La notion de « changement matériel » doit être testable et explicable.

**État test2 : prototype partiel** — fingerprint des services/catégories et invalidation côté navigateur. La détection sémantique des changements de politique reste à faire.

---

## 4.3 Journal / preuve versionnée du consentement

Fonction optionnelle et locale par défaut.

Enregistrer au minimum :
- identifiant aléatoire de consentement ;
- date ;
- catégories acceptées/refusées ;
- version du bandeau ;
- fingerprint des services ;
- hash/version de la politique applicable.

Principes :
- ne pas stocker l’adresse IP par défaut ;
- durée de conservation configurable ;
- export possible ;
- envisager un journal chaîné par hash afin que des modifications ultérieures deviennent détectables.

---

## 4.4 Mode test du consentement pour administrateur

Permettre à un administrateur de simuler dans son seul navigateur :
- aucun choix ;
- tout refuser ;
- statistiques seulement ;
- tout accepter.

PTM doit ensuite montrer le résultat réel :
- GA bloqué ✓
- YouTube bloqué ✓
- Meta Pixel bloqué ✓
- erreur JavaScript après blocage ⚠

Aucun effet sur les visiteurs.

**État test2 : premier prototype disponible.**

---

## 4.5 Polices externes

Détecter les polices chargées depuis des services externes, notamment Google Fonts.

Présentation :
> Google Fonts chargé à distance

Actions futures :
- Voir d’où vient cette police ;
- proposer **Charger cette police localement** lorsqu’une automatisation fiable est disponible ;
- rescanner et vérifier que `fonts.googleapis.com` / `fonts.gstatic.com` ont disparu.

PTM doit préférer l’auto-hébergement au remplacement par un autre CDN.

**État test2 : détection et signalement, sans relocalisation automatique.**

---

## 4.6 Sites multilingues

Détecter WPML / Polylang ou équivalent lorsque possible.

Contrôler :
- correspondance des pages légales FR/EN/etc. ;
- traduction des textes de consentement ;
- lien du bandeau vers la politique dans la bonne langue ;
- catégories de consentement traduites ;
- cohérence des versions juridiques.

PTM n’a pas à traduire le site à la place de l’utilisateur.

---

## 4.7 Export / import sécurisé de configuration

Exporter séparément :

### Réglages réutilisables
- options d’analyse ;
- règles de consentement ;
- préférences d’affichage ;
- exceptions génériques.

### Données propres au site
- IDs de pages ;
- URL ;
- résultats de scans ;
- identité de l’organisation ;
- preuves / historique.

À l’import :
- ne jamais recopier aveuglément les données propres au site ;
- proposer un mapping ;
- afficher ce qui sera remplacé avant confirmation.

---

## 4.8 Consent Mode / signaux de plateformes

PTM ne devient pas un gestionnaire publicitaire.

Il doit pouvoir **détecter et vérifier** les signaux existants, par exemple :
- Google Consent Mode v2 ;
- Microsoft UET Consent Mode.

Exemple de restitution :
- `analytics_storage` avant choix : denied ✓
- après consentement statistiques : granted ✓
- `ad_storage` : denied ✓

Si la CMP native PTM est activée, elle pourra émettre les signaux nécessaires lorsque cela est pertinent, tout en conservant une approche protectrice par défaut.

---

# 5. Priorité P2 — historique et comparaison

## 5.1 Snapshots de scans

Permettre d’enregistrer un scan comme point de référence structuré :
- URL ;
- statut HTTP ;
- titre ;
- hash du contenu utile ;
- formulaires ;
- services détectés ;
- traceurs ;
- CMP ;
- domaines externes ;
- embeds ;
- éléments légaux observés.

Éviter de stocker inutilement des copies intégrales de tout le HTML.

## 5.2 Comparaison dans le temps

Comparer un nouveau scan avec un snapshot :
- nouveau tracker ;
- service disparu ;
- iframe ajoutée ;
- page légale modifiée ;
- URL devenue 404 ;
- nouveau domaine externe ;
- blocage du consentement devenu incorrect.

L’historique doit rester orienté **conformité technique et changement du site**, pas marketing ou conversion.

---

# 6. Architecture modulaire à maintenir

Même sans formaliser dès maintenant un framework complexe, séparer clairement les responsabilités :
- Scanner
- Traceurs & services
- Pages légales
- Assistant
- Consentement
- E-mail tracking
- Corrections guidées
- Vérifications / Setup Helper
- Journal / historique
- Export/import

Objectif : empêcher qu’une petite action d’interface relance « toute la machine ».

---

# 7. Critères d’acceptation avant première publication

## Onboarding
- [ ] Activation silencieuse.
- [ ] Première ouverture PTM → assistant.
- [ ] Quitter/reprendre fonctionne.
- [ ] Trois pages légales détectées/sélectionnables/créables sans doublons.
- [ ] CMP existante respectée.
- [ ] CMP PTM clairement proposée si absente.
- [ ] Analyse complète mise en avant en fin de setup.
- [ ] Aucun scan sans clic explicite.

## Performance
- [ ] Sauvegarder un bloc n’appelle aucun audit HTTP.
- [ ] Navigation entre étapes sans reload complet.
- [ ] Aperçu final local/AJAX.
- [ ] Cache évite les analyses inchangées.

## Scanner
- [ ] 403/404/500/timeout n’arrêtent pas le scan.
- [ ] Toutes les URL traitées → barre à 100 %.
- [ ] Nombre d’erreurs visible séparément.
- [ ] Retry uniquement des échecs.
- [ ] Titres spéciaux correctement affichés.

## UX/design
- [ ] Onglets intégrés au panneau, arrondis aux extrémités.
- [ ] Boutons secondaires pâles.
- [ ] Dashboard presque entièrement navigable/clicable.
- [ ] Consentement compris sans jargon.
- [ ] Aucun texte Divi/Elementor hors contexte.

## Consentement
- [ ] Rien de facultatif avant choix lorsque PTM gère le blocage.
- [ ] Accepter/refuser équivalents visuellement.
- [ ] Fermer ne vaut pas acceptation.
- [ ] Modifier son choix fonctionne.
- [ ] Aucune double bannière si CMP tierce détectée.

## Builders
- [ ] WordPress/Gutenberg testé.
- [ ] Elementor testé.
- [ ] Divi testé.
- [ ] Builder inconnu → aucun dommage / fallback sûr.

## Publication
- [ ] Plugin Check.
- [ ] Text-domain / traductions contrôlés.
- [ ] Sécurité des actions AJAX / nonces / capacités.
- [ ] Installation propre.
- [ ] Mise à jour depuis la version testée.
- [ ] Désinstallation sans comportement destructif inattendu.

---

# 8. État de la 0.0.2-test3

## Implémenté ou prototypé dans ce test
- onboarding 4 écrans ;
- lancement au premier accès ;
- rappel wp-admin masquable ;
- sélection/création des pages légales ;
- consentement présenté plus clairement ;
- formats barre / encart ;
- forte incitation au scan complet ;
- sauvegardes d’assistant sans audit HTTP caché ;
- aperçu final AJAX ;
- scan à 100 % même avec erreurs ;
- retry ciblé ;
- design général adouci ;
- onglets reliés/arrondis ;
- dashboard plus cliquable ;
- prototype de simulation du consentement ;
- prototype de fingerprint/reconsentement ;
- détection des Google Fonts distantes.

## Spécifié mais pas encore livré complètement dans test2
- preuve/journal persistant de consentement ;
- détection sémantique d’un changement matériel de politique ;
- relocalisation automatique des polices ;
- cohérence multilingue ;
- export/import de configuration nouvelle génération ;
- vérification détaillée Google/Microsoft Consent Mode ;
- historique de snapshots et diff ;
- Setup Helper complet de vérification post-configuration.



---

# 9. Décisions intégrées ou préparées pour 0.0.2-test4

## Navigation et performance
- Assistant RGPD dans un onglet principal dédié.
- Sauvegarde des blocs en AJAX, sans crawl ni audit HTTP implicite.
- Sommaire latéral des sections sur les écrans riches ; navigation de l’assistant sans rechargement complet.
- Onglets pastel continus et reliés au panneau de contenu ; pas de tuile blanche isolée pour l’onglet actif.
- Vue d’ensemble responsive sans défilement horizontal de la page sur petit écran PC.
- Les éléments manquants/partiels de la couverture documentaire mènent directement à la bonne étape de l’assistant.

## Pages juridiques et rôles
- Distinguer éditeur du site, responsable du traitement, contact public, contact d’exercice des droits et DPO réellement désigné.
- Ne jamais assimiler automatiquement l’e-mail d’administration WordPress au contact public ou au contact données personnelles.
- Renforcer les critères de complétude des mentions légales selon le profil renseigné.
- Ne jamais publier de consigne interne PTM ou de texte « à vérifier dans le questionnaire » dans les shortcodes publics.
- Une page générée en brouillon et trouvée en 404 est classée comme page non publique ; proposer publication seulement si les données requises sont présentes.

## Builders
- Elementor : création native via l’API Documents ; respecter Containers/Flexbox ou la structure historique selon le site.
- Divi 5 : création native Section → Ligne → Colonne → Texte à partir du format de blocs D5 ; ancien Divi conserve le format shortcode.
- Extra/Divi et builders inconnus : repli conservateur, jamais de réécriture arbitraire.

## Pratiques autour du site
- Étape « outils utilisés en dehors de WordPress » avec exemples concrets : Gmail/Outlook, WhatsApp, Calendly, Koalendar, réservation Google/Microsoft, HelloAsso, Stripe/PayPal/SumUp, formulaires externes, fichiers Excel/Sheets/Drive, etc.
- Conseils contextualisés pour les envois de masse hors outil de newsletter : désinscription, CCI, listes d’opposition et distinction envoi ponctuel/campagne.
- Premiers contrôles prudents de formulaires connus : champs obligatoires potentiellement à justifier, minimisation et renvoi vers les réglages.
- Adaptateurs de conservation en lecture seule pour plugins connus ; ne pas parcourir arbitrairement leurs tables.
- Détection des extensions de sauvegarde ; UpdraftPlus en priorité pour fréquence, rétention et stockage distant.
- PTM ne remplace pas Wordfence : pas de firewall, malware scan, brute-force, CVE ou durcissement général.

## Consentement / Divi
- CMP PTM indépendante de Divi, Elementor ou de tout builder : barre fixe ou encart centré natif.
- Désactiver le vrai moteur de blocage dans les éditeurs visuels.
- Après consentement, réinitialiser prudemment les modules Divi qui en ont besoin, en commençant par Maps et reCAPTCHA.
- Aucun plugin popup Divi requis pour afficher le consentement PTM.

## Lecture des alertes
- 🔴 Problème constaté : preuve technique suffisante.
- 🟠 À vérifier : signal présent mais contexte humain insuffisant.
- 🔵 Conseil : bonne pratique, sans effet négatif sur le score.

## Premium Pro — après publication du plugin gratuit
- Stagings, clones, copies de bases, inventaire d’exports, contrôles migration/agence et comparaisons avancées restent hors du cœur gratuit pour l’instant.
