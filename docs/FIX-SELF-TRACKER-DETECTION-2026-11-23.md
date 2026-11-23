# Critical: Dendrila Privacy scanner reports its own consent vendor catalogue as active tracking

## Root cause

When the consent module is enabled, it localizes two JavaScript variables into the HTML:

- DendrilaPrivacyConsent
- PixelTrackersManagerConsent

Their domains configuration lists *possible* third-party hosts for the blocker. The previous technical_surface() method treated these inline JavaScript strings as live tracker evidence. Therefore it marked Google Analytics, GTM, Brevo, Hotjar, Fullstory, Smartlook, Vimeo, Google Maps, TikTok, Meta Pixel and others as "active" despite no external requests.

## Fix

strip_own_scan_configuration() removes only valid, complete JSON assignments to Dendrila's own known localized variables, from the in-memory text analyzed by technical_surface(). It does not change the live HTML response, consent functionality, storage, blocking rules, or third-party scripts.

The regression fixture simulates the consent scripts from a nearly empty WordPress installation and verifies that no trackers are detected. It also verifies that a real external script is still detected and that unrelated inline JavaScript is preserved.

## Important follow-up

An existing scan stored in WordPress will retain old false positives until the administrator performs a FULL new scan. "Retry failed pages" is insufficient.

This patch is intentionally narrow: it does not establish that other ambiguous third-party code snippets always represent actual HTTP traffic. For full "confirmed" status, add network-based proof or use labels distinguishing declarations from requests, then test over multiple frontend pages with consent on and off.
