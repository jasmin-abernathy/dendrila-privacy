# Google Fonts — correction des indices abusifs (2026-11-20)

## Déclencheur

Une testeuse de Dendrila Privacy 0.0.6 a signalé « Google font APIs » sur trois pages d'un WordPress dont les polices semblent locales. Sa capture réseau montre des fichiers principalement sous `/wp-content/fonts/` et `/wp-content/uploads/`, sans requête Google Fonts visible. La capture ne prouve pas qu'aucun chargement distant ne se produit sur les autres pages ou dans d'autres conditions (cache, consentement, appareil).

## Origine technique vérifiée dans le code

- Avant cette correction, `technical_surface()` incluait les commentaires HTML et le contenu des scripts. `scan_html_into_findings()` classait une simple occurrence textuelle de `fonts.googleapis.com` ou `fonts.gstatic.com` comme une ressource externe active.
- `scan_theme_references()` cherche également les signatures dans les fichiers CSS/PHP/JS du thème. Ces références doivent rester des **capacités / indices**, sans preuve de requête.
- `tracking_control_for_finding()` renvoyait toujours `state=active` pour Google Fonts, même avec un résultat potentiel : information trompeuse.

## Correction

- Google Fonts est un cas spécial de l'analyse : déclaration de chargement uniquement pour un vrai `<link rel="stylesheet|preload|prefetch" href="https://fonts.googleapis.com/...">` ou `fonts.gstatic.com`, un import `@import` ou un `url()` distant dans un bloc CSS `<style>`.
- Les préconnexions (`preconnect` / `dns-prefetch`), commentaires et chaînes dans les scripts ne sont plus considérés comme des **chargements confirmés** ; ils deviennent des indices potentiels, ou sont ignorés si entièrement commentés.
- La présence d'un vrai lien HTML donne **une déclaration de chargement**, pas la preuve d'une requête réseau réellement effectuée (CSP, cache, bloqueur, etc.). Dendrila l'indique et demande une vérification dans l'onglet Réseau du navigateur.
- Le contrôle d'action reprend l'état constaté, sans imposer artificiellement `active`.
- Les ressources locales `/wp-content/fonts/` ne sont pas classées comme Google Fonts distant.

## Limites

Le scan du HTML brut ne voit pas forcément les imports Google Fonts contenus dans un CSS distant local (`/wp-content/cache/...`), ni les appels déclenchés après rendu par JavaScript, ou les variations suivant les cookies et le navigateur. La capture réseau réelle est donc prioritaire. Ne pas affirmer une absence totale de requêtes sur la base de la seule analyse statique.

Les tests isolés `php tests/google-fonts-evidence.php` couvrent les liens, les indices, les commentaires, `<style>`, le chargement local et le statut de l'action ; un essai WordPress/Divi réel reste nécessaire avant fusion.
