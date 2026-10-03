=== Dendrila Privacy ===
Contributors: juliane16
Tags: privacy, gdpr, cookies, consent, trackers
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local privacy audits, legal documentation assistance, and optional consent management for WordPress.

== Description ==

Dendrila Privacy helps WordPress administrators understand what their site actually does with trackers and third-party services, keep privacy-related documentation up to date, and optionally manage visitor consent.

Local-first by design, audit results, settings, and assistant answers stay in WordPress by default. The plugin does not certify GDPR compliance or replace context-specific legal advice.

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
* optional account-linked consent sync for logged-in users, with explicit conflict handling and a notice when a choice is applied on another device;
* a local tamper-evident consent evidence ledger that hashes email identifiers instead of storing them in clear text;
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

Dendrila Privacy uses WordPress APIs and public shortcodes where possible. Gutenberg, Elementor and Divi receive dedicated conservative handling; for other recognised builders, it prefers a manual fallback over rewriting unknown storage.

= Data and privacy =

Dendrila Privacy does not send site-audit results to the plugin author and does not include advertising telemetry.

Visitor choices stay in the browser by default. If account sync is enabled, logged-in users can also store the same categories in WordPress user metadata. The optional evidence ledger stays in the site's database and hashes email identifiers with a site-local key. Other administration and audit data remain local unless an administrator starts a documented external lookup below.

== External services ==

= Public organisation lookups (optional) =

Dendrila Privacy can optionally query a public register to prefill organisation information. Nothing is sent automatically: an administrator chooses a country, enters the required identifier or search term, and clicks Search.

These lookups never include site-audit results, assistant answers, visitor consent choices, page contents, or unrelated WordPress settings.

= France — Recherche d'entreprises API =

For France, Dendrila Privacy sends only the administrator's search term (company name, SIREN, or SIRET) to the public Recherche d'entreprises API operated by the French Interministerial Digital Directorate (DINUM).

Service page: https://www.data.gouv.fr/dataservices/api-recherche-dentreprises
API documentation: https://recherche-entreprises.api.gouv.fr/docs/
Terms of use: https://www.data.gouv.fr/pages/legal/cgu
Privacy information: https://www.data.gouv.fr/en/suivi/

= Norway — Brønnøysund Register Centre =

For Norway, only the organisation name or number entered by the administrator is sent to the official open Enhetsregisteret API. Returned public identity, address, legal form and activity data may be used for optional prefill.

API: https://data.brreg.no/enhetsregisteret/api/dokumentasjon/en/index.html
Privacy: https://www.brreg.no/en/about-us/privacy-policy/

= EU / Northern Ireland — VIES VAT validation =

For supported EU countries and Northern Ireland (XI), Dendrila Privacy sends only the selected country code and VAT number to the European Commission VIES service. VIES validates VAT registration for intra-EU trade. Depending on the national database, the response may not contain the organisation name or address.

VIES service: https://ec.europa.eu/taxation_customs/vies/
Information about VIES: https://europa.eu/youreurope/business/finance-and-tax/vat/check-vat-number-vies/
European Commission privacy information: https://taxation-customs.ec.europa.eu/privacy-statement_en

= Detection signatures are not external connections =

Dendrila Privacy contains literal domain and path signatures for services such as Google Analytics, Google Tag Manager, Meta/Facebook Pixel, YouTube, Vimeo, and Google Maps. Those strings are used locally to recognise third-party services in the site's own markup and to classify or block them when the optional Dendrila Privacy consent feature is enabled.

Their presence in Dendrila Privacy's source code does not mean that Dendrila Privacy loads those services or sends data to them. Dendrila Privacy itself does not add analytics or advertising trackers. A request to one of those providers can only originate from the site, theme, or plugin integration that Dendrila Privacy is inspecting, subject to that integration and the site's consent configuration.

== Installation ==

1. Upload and activate Dendrila Privacy.
2. Open Dendrila Privacy and review the proposed legal reference pages.
3. Start an analysis when you choose; installation does not automatically launch a full-site scan.
4. Enable consent management and account sync only when needed.

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

= 0.0.5 =
* Added country-aware public organisation lookup.
* France keeps DINUM Recherche d'entreprises lookup by name, SIREN or SIRET.
* Norway adds official Brønnøysund Enhetsregisteret lookup by organisation name or number.
* Supported EU countries and Northern Ireland can validate VAT numbers through VIES.
* Added generic registration metadata for non-French organisations and cleared incompatible stale fields when switching results.
* All external lookups remain explicit administrator actions and do not include audit results, page contents, assistant answers, or visitor consent choices.

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
