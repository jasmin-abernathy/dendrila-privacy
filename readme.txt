=== Dendrila Privacy ===
Contributors: juliane16
Tags: privacy, gdpr, cookies, consent, trackers
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local privacy audits, legal documentation assistance, and optional consent management for WordPress.

== Description ==

Dendrila Privacy (Dendrila Privacy) helps WordPress administrators understand what their site actually does with trackers and third-party services, keep privacy-related documentation up to date, and optionally manage visitor consent.

Dendrila Privacy is designed local-first: audit results, settings, and assistant answers stay in WordPress by default. The plugin does not certify GDPR compliance and does not replace legal advice adapted to an organisation's actual activities.

Main features:

* standard or full analysis of public site content;
* detection of known trackers, third-party services, external content, and consent tools;
* distinction between observed technical evidence, an available integration, and something that still needs human verification;
* progressive scans where one failing page does not stop the remaining analysis;
* a guided privacy assistant that reuses information WordPress can already provide before asking questions;
* documentation coverage based only on requirements that actually apply;
* management of Legal Notice, Privacy Policy, and Cookies / Consent reference pages;
* explicit draft creation and updates, without silently publishing legal content;
* conservative compatibility with Gutenberg, Elementor, Divi, and several other page builders;
* an optional native consent interface that is disabled after installation;
* protective blocking of recognised optional services before the visitor's choice when Dendrila Privacy consent is enabled;
* Accept all and Reject all actions with equal visual weight;
* a persistent Manage my choices control, Elementor widget, and universal shortcode;
* initial checks for practices outside WordPress, including email, messaging, booking tools, external forms, payments, and files/lists.

= Consent =

The Dendrila Privacy consent interface is independent from the page builder. It is mounted globally on the page to avoid positioning and stacking conflicts caused by themes, Divi, or Elementor.

When Dendrila Privacy manages consent:

* no optional category is preselected;
* closing the interface is not treated as consent;
* visitors can change their choice through Manage my choices;
* recognised optional services stay blocked before a choice;
* the real blocking engine is disabled inside visual page-builder editors.

Public shortcodes: `[dendrila_privacy_legal_notice]`, `[dendrila_privacy_privacy_policy]`, `[dendrila_privacy_cookies]`, `[dendrila_privacy_services]`, `[dendrila_privacy_rights]`, `[dendrila_privacy_documents]`, and `[dendrila_privacy_consent_settings]`.

= Page builders =

WordPress / Gutenberg: Dendrila Privacy uses WordPress APIs and public shortcodes for supported content.

Elementor: Dendrila Privacy can inspect known local widget content, explicitly create supported legal pages, and provides a Manage my choices widget.

Divi: Dendrila Privacy reads recognised content conservatively. It prefers a native structure only when that structure is clearly understood and falls back to a shortcode when a safe rewrite cannot be guaranteed.

Other builders: Dendrila Privacy recognises several common page-level signatures conservatively and prefers a manual fallback over modifying unknown builder storage.

= Data and privacy =

Dendrila Privacy does not send site-audit results to the plugin author and does not include advertising telemetry.

Visitor consent preferences are stored locally in the visitor's browser. Administration and audit data stay in the site's WordPress database unless an administrator explicitly starts the documented external lookup below.

== External services ==

= French company search API (optional) =

Dendrila Privacy can offer an optional French company lookup to prefill public organisation information. This lookup is not required for the plugin to work and runs only after an administrator explicitly starts it.

Data sent: only the search term entered by the administrator (company name, SIREN, or SIRET).
When it is sent: only after the administrator clicks the company-search control.
Recipient: the public Recherche d'entreprises API operated by the French Interministerial Digital Directorate (DINUM).
Data not sent: Dendrila Privacy site-audit results, privacy-assistant answers, visitor consent choices, or page contents are not included in this request.

Service page: https://www.data.gouv.fr/dataservices/api-recherche-dentreprises
Access conditions and API information: https://annuaire-entreprises.data.gouv.fr/donnees/api-entreprises
API documentation: https://recherche-entreprises.api.gouv.fr/docs/
Terms of use: https://www.data.gouv.fr/pages/legal/cgu
Privacy information: https://www.data.gouv.fr/en/suivi/

= Detection signatures are not external connections =

Dendrila Privacy contains literal domain and path signatures for services such as Google Analytics, Google Tag Manager, Meta/Facebook Pixel, YouTube, Vimeo, and Google Maps. Those strings are used locally to recognise third-party services in the site's own markup and to classify or block them when the optional Dendrila Privacy consent feature is enabled.

Their presence in Dendrila Privacy's source code does not mean that Dendrila Privacy loads those services or sends data to them. Dendrila Privacy itself does not add analytics or advertising trackers. A request to one of those providers can only originate from the site, theme, or plugin integration that Dendrila Privacy is inspecting, subject to that integration and the site's consent configuration.

== Installation ==

1. Upload the Dendrila Privacy ZIP through Plugins > Add Plugin > Upload Plugin.
2. Activate the plugin.
3. Open Dendrila Privacy. The guided setup starts on first access, not during activation.
4. Review the proposed Legal Notice, Privacy Policy, and Cookies / Consent reference pages.
5. Start an analysis when you choose. Installation does not automatically launch a full-site scan.
6. Enable the native consent interface only if you want Dendrila Privacy to manage visitor choices and blocking as well.

== Frequently Asked Questions ==

= Does Dendrila Privacy automatically make my site GDPR compliant? =

No. Dendrila Privacy provides technical observations, helps document relevant practices, and can manage a consent mechanism. Compliance still depends on the site's actual context and applicable obligations.

= Are audit results sent to the plugin author? =

No. Audit results, settings, and assistant answers stay in your WordPress installation. Only the optional company lookup contacts the documented public API after an administrator starts that lookup.

= Is the consent interface enabled automatically? =

No. It is disabled by default. If you enable it, recognised optional services are then blocked until the visitor makes a choice.

= Does Dendrila Privacy replace a security plugin? =

No. Dendrila Privacy focuses on privacy-related technical checks and documentation. It does not replace a firewall, malware scanner, vulnerability scanner, or general WordPress hardening tool.

== Changelog ==

= 0.0.4 =
* Renamed the public plugin identity to Dendrila Privacy with the requested WordPress.org slug dendrila-privacy.
* Text domain and public shortcodes now use the Dendrila Privacy namespace.
* Pre-publication shortcode markup and consent JavaScript globals remain compatible through non-breaking aliases.
* Internal stored-data keys remain unchanged to avoid losing existing settings on test sites.

= 0.0.3 =
* WordPress.org review hardening: admin menu CSS now uses the WordPress enqueue API.
* Public shortcodes now use the unique pixel_trackers_manager_ namespace; pre-publication ptm_ markup is rewritten at render time for compatibility.
* Full-page consent fallback uses WordPress 6.9+'s standardized template enhancement output buffer instead of opening a plugin-owned buffer.
* External-service documentation now distinguishes the optional DINUM company lookup from local detection signatures for third-party services.

= 0.0.2 =
* Dedicated GDPR assistant tab with AJAX saves that do not trigger hidden public-page audits.
* Updated navigation and responsive overview.
* Non-public WordPress pages separated from genuine public HTTP errors.
* Guided legal-page creation with Elementor and Divi compatibility.
* Better separation of legal roles and contact details in generated documentation.
* Expanded coverage of external tools, retention settings, and backups.
* Builder-independent consent interface and more reliable Manage my choices control.
* WordPress 7.1-targeted test matrix.

Detailed development-build history is kept in `changelog.txt`.
