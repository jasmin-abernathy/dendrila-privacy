# Analyse des ZIP e-mail, avis et WooCommerce — 20 novembre 2026

Cette revue repose sur les archives de versions transmises par l'utilisateur. Les réglages propres à chaque site ne sont **pas** inclus dans les ZIP. Aucune extension tierce n'est modifiée ni redistribuée.

## Correctifs mis en code

### Mailchimp for WordPress 4.15.0
- `includes/class-tracking-pixel.php` utilise le handle WordPress `mc4wp-mailchimp-site-tracking-pixel`, la source `tracking_pixel_script_url`, l'identifiant `tracking_pixel_site_id` et le booléen `tracking_pixel_enabled`. Ces valeurs proviennent de l'option `mc4wp` via `mc4wp_get_options()`.
- Dendrila lit **sans modifier** l'état du pixel **de site**, renseigne un contrôle guidé et bloque l'enqueue reconnu sous la catégorie marketing si son gestionnaire de consentement est activé.
- Les formulaires MC4WP, l'inscription à la newsletter et les pixels **des campagnes Mailchimp** sont distincts : Dendrila ne déclare pas que ces derniers sont actifs à partir de l'option du site.
- Le script d'identification après souscription dans MC4WP est une autre voie de suivi à tester en environnement réel.

### Brevo (mailin) 3.3.6
- Le plugin définit `SIB_Manager::is_ma_active()` : option `sib_main_option.ma_key` non vide et `sib_home_option.activate_ma` différent de `no`.
- Les scripts `cdn.brevo.com/js/sdk-loader.js` et `sdk-staging-loader.js` sont des SDK facultatifs de marketing automation. Dendrila les reconnaît pour l'audit et le blocage des ressources détectées.
- **Attention** : Brevo émet directement un bloc `<script>` à `wp_head` plutôt que de passer par `wp_enqueue_script`. Sur WordPress sans mécanisme de réécriture HTML complète, le filtre `script_loader_tag` seul ne peut **pas** garantir la neutralisation des balises déjà émises par le parseur. Le propriétaire doit utiliser le contrôle guidé pour désactiver « Marketing Automation via Brevo » jusqu'à validation réseau sur un site de test.
- Le paramètre `activate_email` et le transport SMTP **ne sont jamais désactivés** par Dendrila.

### Google Listings & Ads 3.10.1
- Le plugin contient `src/Google/ConsentGatedScriptTrait.php` et son bundle WP Consent API. Il emploie `wp_has_consent('marketing')` et `wp_listen_for_consent_change` pour une partie de ses balises.
- Sans API disponible, certains chemins sont conçus pour « fail open » : **ne pas considérer l'installation du plugin seule comme une preuve de conformité**. L'intégration WP Consent API de Dendrila est dans la PR #19, séparée et non encore fusionnée.
- Vérifier les requêtes Ads/Conversions/Analytics avant consentement en navigateur réel.

## Autres extensions remises
- MailPoet 5.45.2 : fonctions de mesure d'engagement et options de suivi existantes ; vérification de l'adaptateur natif à poursuivre.
- FluentCRM 3.3.1 : les filtres `fluentcrm_disable_email_open_tracking`, `fluent_crm/track_click` et `fluent_crm/will_use_cookie` existent dans le code de l'archive : l'intégration existante de Dendrila utilise bien les filtres exposés.
- The Newsletter Plugin 9.5.7 : plusieurs mécanismes de mesure (ouverture/clic) ; un état global ne suffit pas pour inférer les réglages de chaque campagne.
- WooCommerce 11.3.1, Stripe Gateway 11.2.0, WooCommerce Payments 11.3.0, PayPal Payments 4.2.2 et WooCommerce Services 3.7.7 : ne pas confondre scripts indispensables à la commande, authentification de paiement et traceurs commerciaux facultatifs.
- Customer Reviews for WooCommerce 5.129.0 : gestion du consentement d'envoi des demandes d'avis et mécanisme facultatif de suivi des recommandations ; ne pas déduire un accord à un pixel marketing d'un consentement à recevoir une demande d'avis.
- Google Reviews / Trustindex 15.0 : widgets d'avis et contenus externes ; ne pas conclure à un suivi actif sur la seule présence de l'extension.

## Tests
`php tests/mail-ecommerce-integrations.php` valide les options MC4WP/Brevo, la classification des SDK facultatifs et la non-interférence avec des URL courantes de paiement/panier. Il ne constitue **pas** un test complet du réseau d'un véritable WordPress.

Avant fusion : vérifier les environnements Divi + WooCommerce, les hooks `wp_head` utilisés par Brevo et les scripts d'identification MC4WP, plus les chemins de paiement, refus puis acceptation, retour en arrière et retrait du consentement. Fusionner avec #19 et #21 seulement après résolution des éventuels conflits et tests croisés.
