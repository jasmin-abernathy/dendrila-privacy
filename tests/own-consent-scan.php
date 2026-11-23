<?php
/**
 * A new WordPress site with Dendrila's consent blocker enabled must NOT be
 * reported as using every vendor listed in DendrilaPrivacyConsent.domains.
 */
define( 'ABSPATH', __DIR__ . '/' );
function register_activation_hook() {}
function register_deactivation_hook() {}
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function expect_own_config( $valid, $label ) {
    if ( ! $valid ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
$main = file_get_contents( dirname( __DIR__ ) . '/dendrila-privacy.php' );
$main = str_replace( "require_once __DIR__ . '/includes/class-dendrila-privacy-age-assurance.php';", '', $main );
$main = str_replace( 'Pixel_Trackers_Manager_Plugin::instance();', '', $main, $count );
expect_own_config( 1 === $count, 'bootstrap available' );
eval( substr( $main, 5 ) );
$ref = new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' );
$plugin = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod( 'scan_html_into_findings' );
$method->setAccessible( true );
$scan = function ( $html ) use ( $method, $plugin ) {
    return $method->invoke( $plugin, $html, 'https://example.test/', array() );
};
$domains = array(
    'statistics' => array(
        'google-analytics.com',
        'googletagmanager.com/gtag/js',
        'static.hotjar.com',
        'edge.fullstory.com/s/fs.js',
        'web-sdk.smartlook.com/recorder.js',
    ),
    'marketing' => array(
        'googletagmanager.com/gtm.js',
        'connect.facebook.net',
        'analytics.tiktok.com',
        'snap.licdn.com',
        'cdn.brevo.com/js/sdk-loader.js',
    ),
    'external' => array(
        'youtube.com/embed',
        'player.vimeo.com',
        'maps.googleapis.com',
    ),
);
$configuration = array(
    'categories' => array( 'statistics', 'marketing', 'external' ),
    'domains' => $domains,
    'nested' => array( 'text' => 'quoted "end" and braces { } ; inside a JSON string' ),
);
$encoded = json_encode( $configuration, JSON_UNESCAPED_SLASHES );
$consent = '<script id="pixel-trackers-manager-consent-js-extra">var DendrilaPrivacyConsent = ' . $encoded . ';' .
    "\nvar PixelTrackersManagerConsent = " . $encoded . ';</script>';
$early = '<script id="pixel-trackers-manager-consent-bootstrap-js-extra">var DendrilaPrivacyConsentEarlyConfig = ' . $encoded . ';' .
    "\nvar PixelTrackersManagerConsentEarlyConfig = " . $encoded . ';</script>';
$minimal = '<html><head>' . $early . '</head><body><h1>WordPress test</h1>' . $consent . '</body></html>';
$findings = $scan( $minimal );
expect_own_config( empty( $findings ), 'own consent configuration alone cannot create tracker findings: ' . implode( ', ', array_keys( $findings ) ) );
$actual = $scan( $minimal . '<script src="https://edge.fullstory.com/s/fs.js"></script>' );
expect_own_config( ! empty( $actual['fullstory']['observed_html'] ), 'external script declaration remains detectable' );
expect_own_config( empty( $actual['fullstory']['active_tracking'] ), 'HTML declaration alone is not counted as active tracking' );
expect_own_config( 'potential' === $actual['fullstory']['tracking_state'], 'HTML declaration remains to be verified on the network' );
$finalize = $ref->getMethod( 'finalize_findings' );
$finalize->setAccessible( true );
$finalized = $finalize->invoke( $plugin, $actual, array(), array() );
$finalized_active = array_filter( $finalized, static function ( $finding ) { return ! empty( $finding['active_tracking'] ); } );
expect_own_config( empty( $finalized_active ), 'HTML-only findings do not enter the finalized active count' );
$css_declaration = $scan( '<style>.hero{background-image:url("https://edge.fullstory.com/s/fs.js")}</style>' );
expect_own_config( ! empty( $css_declaration['fullstory']['observed_html'] ), 'CSS url() declaration remains detectable' );
expect_own_config( empty( $css_declaration['fullstory']['active_tracking'] ), 'CSS declaration alone is not counted as active tracking' );
$css_comment = $scan( '<style>/* background:url(https://edge.fullstory.com/s/fs.js) */ .hero{color:red}</style>' );
expect_own_config( empty( $css_comment['fullstory'] ), 'commented CSS URL is not a resource declaration' );
$css_string = $scan( '<style>.hero{content:"url(https://edge.fullstory.com/s/fs.js)"}</style>' );
expect_own_config( empty( $css_string['fullstory'] ), 'URL text in CSS content is not a resource declaration' );
$connection_hint = $scan( '<link rel="preconnect" href="https://edge.fullstory.com"><link rel="dns-prefetch" href="//edge.fullstory.com">' );
expect_own_config( empty( $connection_hint['fullstory'] ), 'connection hints alone do not imply a tracker resource' );
$fake_attribute = $scan( '<script data-note="src=https://edge.fullstory.com/s/fs.js"></script>' );
expect_own_config( empty( $fake_attribute['fullstory'] ), 'attribute text that resembles src is not parsed as an actual src' );
foreach ( array( 'google-analytics', 'google-tag-manager', 'hotjar', 'smartlook', 'meta-pixel', 'tiktok-pixel', 'linkedin-insight', 'brevo', 'google-maps', 'youtube', 'vimeo' ) as $id ) {
    expect_own_config( empty( $actual[ $id ] ), 'no unrelated false positive: ' . $id );
}
$mixed = $scan( '<script>' . "var DendrilaPrivacyConsent = " . $encoded . ';' .
    "var real = document.createElement('script'); real.src='https://static.hotjar.com/c/hotjar-123.js';" .
    '</script>' );
expect_own_config( empty( $mixed['hotjar'] ), 'an inline script string does not prove a resource declaration' );
$unrelated = $scan( '<script>var SomeOtherPlugin = ' . $encoded . ';</script>' );
expect_own_config( empty( $unrelated ), 'vendor URLs in an inline configuration do not create findings' );
$inert = $scan( '<script>var DendrilaPrivacyConsent = "https://edge.fullstory.com/s/fs.js";</script>' );
expect_own_config( empty( $inert ), 'vendor URLs in inline JavaScript strings do not create findings' );
$metadata = $scan( '<script type="application/ld+json">{"@type":"WebSite","doc":"https://edge.fullstory.com/s/fs.js"}</script>' );
expect_own_config( empty( $metadata ), 'JSON-LD URLs are metadata, not activated trackers' );
$import_map = $scan( '<script type="importmap">{"imports":{"tracking":"https://static.hotjar.com/c/hotjar.js"}}</script>' );
expect_own_config( empty( $import_map ), 'import-map references do not prove tracking' );
$inert_payload = $scan( '<script type="text/plain">https://edge.fullstory.com/s/fs.js</script>' );
expect_own_config( empty( $inert_payload ), 'plain-text scripts cannot be active' );
$blocked = $scan( '<script type="text/plain" data-ptm-blocked="1" data-ptm-src="https://edge.fullstory.com/s/fs.js"></script>' );
expect_own_config( ! empty( $blocked['fullstory']['blocked_by_consent'] ), 'consent-blocked scripts remain detected as blocked' );
$blocked_active_mix = $scan( '<script type="text/plain" data-ptm-blocked="1" data-ptm-src="https://edge.fullstory.com/s/fs.js"></script><script src="https://edge.fullstory.com/s/fs.js"></script>' );
expect_own_config( empty( $blocked_active_mix['fullstory']['blocked_by_consent'] ), 'an unblocked declaration on another page prevents an all-blocked conclusion' );
$inert_template = $scan( '<template><script src="https://edge.fullstory.com/s/fs.js"></script></template>' );
expect_own_config( empty( $inert_template['fullstory'] ), 'template content is not treated as a declared live resource' );
$evidence = $ref->getMethod( 'finding_evidence_status' );
$evidence->setAccessible( true );
$declared = $scan( '<script src="https://edge.fullstory.com/s/fs.js"></script>' );
$status = $evidence->invoke( $plugin, $declared['fullstory'] );
expect_own_config( 'warn' === $status['tone'] && false !== strpos( $status['label'], 'réseau' ), 'HTML cannot confirm network requests' );
echo "No self-detection from Dendrila consent configuration: PASS\n";
