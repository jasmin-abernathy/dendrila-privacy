# Pixel Trackers Manager — User Guide

## What it is

Pixel Trackers Manager is a WordPress privacy-audit and documentation assistant. It helps you see which third-party services are actually active, document the uses of personal data that apply to your site, keep legal pages in sync, and optionally manage visitor consent.

It is not a legal certification tool. A green dashboard means that Pixel Trackers Manager can account for the applicable items it knows how to check; it does not mean that every legal obligation in every context has been certified.

## First opening

Activating Pixel Trackers Manager does not launch a scan or change public pages. The guided setup appears only when an administrator opens Pixel Trackers Manager for the first time.

The setup first checks the three reference pages — Legal Notice, Privacy Policy, and Cookies / Consent. It can suggest an existing page, let you select a different one, or create a draft with the appropriate public shortcode when no likely page exists. Builder content is checked locally where supported so a page is not considered missing merely because a public crawl failed.

The setup can be paused, skipped, or relaunched later from Settings.

## A simple workflow

1. **Confirm the legal-page destinations.** Use the first-open setup or Settings; create a draft only when a page is genuinely missing.
2. **Run a site analysis.** Start with the standard analysis. Use the full analysis only when you need broader coverage.
3. **Review what was found.** “Active” means Pixel Trackers Manager found stronger technical evidence than a plugin merely being installed.
4. **Complete only what the site cannot tell us.** Optional unknown information is not treated as an error. If it is not needed to publish a coherent document, the assistant does not keep bringing you back to it.
5. **Review your legal pages.** The coverage score is deliberately simple: documented applicable items divided by all applicable items.
6. **Update the pages.** You can update supported pages individually or apply all ready updates together.
7. **Optionally enable consent.** The native banner is off by default. If you enable it, known optional services are blocked first and activated only after the visitor's choice.

## Consent: the important rule

Pixel Trackers Manager does not try to maximise “accept” clicks. **Accept all** and **Reject all** use the same visual weight. Optional categories start off. Closing the banner is not consent.

The banner is rendered as a global component independent from the page builder. On the front end, PTM mounts it directly below `<body>` so it cannot remain trapped inside a Divi section, Elementor container, or another stacking context that could place it behind the page content.

Visitors can reopen their preferences through **Manage my choices**. Add `[pixel_trackers_manager_consent_settings]` wherever you want that control to appear.

For a custom Divi, Elementor, Gutenberg, or theme control, you can use:

```html
<button type="button" class="ptm-consent-open">Manage my choices</button>
```

or add this behaviour-only attribute to an existing control:

```html
data-ptm-consent-open="preferences"
```

The data attribute is preferable when you want to keep the builder's native button styling untouched.

Advanced integrations can call:

```js
window.PixelTrackersManagerConsentAPI.openPreferences();
```

See `docs/CONSENT-INTEGRATION.md` for the full technical integration notes.

## Page builders

### WordPress editor / Gutenberg

Use the public shortcodes in a Shortcode block or let Pixel Trackers Manager update supported pages directly.

### Elementor

Pixel Trackers Manager reads relevant widget text locally when possible. Legal documents can be inserted after an explicit administrator action. A small Elementor widget is also available for **Manage my choices**.

The consent banner itself is not an Elementor widget. It stays global so its visibility and behaviour do not depend on Elementor's DOM or stacking contexts.

### Divi 4

Relevant module text can be read locally. Pixel Trackers Manager can add a text module containing the requested shortcode after an explicit action.

### Divi 5

The public banner and shortcodes work normally. Pixel Trackers Manager deliberately avoids rewriting a Divi 5 internal page structure when that operation cannot be guaranteed safely. In that case, open Divi and insert the shortcode into a text/code-compatible module.

The consent dialog itself is mounted directly below `<body>`, not inside a Divi module. “Manage my choices” controls are listened to in the capture phase so a later Divi bubbling handler cannot prevent PTM from opening the preferences dialog.

This is intentional: builder compatibility improves the editing experience, but the privacy engine never depends on a builder.

### Other page builders

Pixel Trackers Manager also recognises common page-level signatures from Bricks, Beaver Builder, WPBakery, Oxygen, Breakdance, Brizy, SiteOrigin and Avada. When there is no dedicated safe writer, it deliberately switches to a manual-shortcode workflow instead of rewriting builder data. The public consent engine still runs at WordPress level.

## Public shortcodes

- `[pixel_trackers_manager_legal_notice]`
- `[pixel_trackers_manager_privacy_policy]`
- `[pixel_trackers_manager_cookies]`
- `[pixel_trackers_manager_services]`
- `[pixel_trackers_manager_rights]`
- `[pixel_trackers_manager_documents]`
- `[pixel_trackers_manager_consent_settings]`

Legacy `ptm_...` markup from development builds is rewritten before shortcode rendering on ordinary WordPress content; only the long public shortcode namespace is registered.

## Consent and caches

Consent choices are stored in the visitor's browser. The page HTML does not need a different server-side cache variant for each choice. Optional known resources are delivered in an inert form and activated in the browser only when the matching category is allowed. A small same-origin early guard is also inserted at the start of the page head when the native banner is enabled, so common inline loaders printed directly by themes or builders cannot start a recognised optional request before the normal consent script runs.

This makes the approach friendlier to page caches and CDNs while preserving deny-by-default behaviour.

## Scan errors

A failed page does not stop the rest of the analysis. Pixel Trackers Manager records the failure, continues, and reports how many pages were successfully analysed. When failures remain, the Overview offers a **Retry only these pages** action so a successful part of the scan does not need to be repeated.

## External service

The optional French company lookup contacts the public Recherche d'entreprises API only after an administrator explicitly starts a lookup. Site-audit results are not sent to the plugin author.
