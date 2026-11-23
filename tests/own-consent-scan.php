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
expect_own_config( ! empty( $actual['fullstory']['active_tracking'] ), 'real external script remains detectable' );
foreach ( array( 'google-analytics', 'google-tag-manager', 'hotjar', 'smartlook', 'meta-pixel', 'tiktok-pixel', 'linkedin-insight', 'brevo', 'google-maps', 'youtube', 'vimeo' ) as $id ) {
    expect_own_config( empty( $actual[ $id ] ), 'no unrelated false positive: ' . $id );
}
$mixed = $scan( '<script>' . "var DendrilaPrivacyConsent = " . $encoded . ';' .
    "var real = document.createElement('script'); real.src='https://static.hotjar.com/c/hotjar-123.js';" .
    '</script>' );
expect_own_config( ! empty( $mixed['hotjar']['active_tracking'] ), 'real code in same script is not removed' );
$unrelated = $scan( '<script>var SomeOtherPlugin = ' . $encoded . ';</script>' );
expect_own_config( ! empty( $unrelated['fullstory']['active_tracking'] ), 'unrelated script is still inspected' );
$inert = $scan( '<script>var DendrilaPrivacyConsent = "https://edge.fullstory.com/s/fs.js";</script>' );
expect_own_config( ! empty( $inert['fullstory']['active_tracking'] ), 'non-JSON assignment is not silently ignored' );
$metadata = $scan( '<script type="application/ld+json">{"@type":"WebSite","doc":"https://edge.fullstory.com/s/fs.js"}</script>' );
expect_own_config( empty( $metadata ), 'JSON-LD URLs are metadata, not activated trackers' );
$import_map = $scan( '<script type="importmap">{"imports":{"tracking":"https://static.hotjar.com/c/hotjar.js"}}</script>' );
expect_own_config( empty( $import_map ), 'import-map references do not prove tracking' );
$inert_payload = $scan( '<script type="text/plain">https://edge.fullstory.com/s/fs.js</script>' );
expect_own_config( empty( $inert_payload ), 'plain-text scripts cannot be active' );
$blocked = $scan( '<script type="text/plain" data-ptm-blocked="1" data-ptm-src="https://edge.fullstory.com/s/fs.js"></script>' );
expect_own_config( ! empty( $blocked['fullstory']['blocked_by_consent'] ), 'consent-blocked scripts remain detected as blocked' );
$evidence = $ref->getMethod( 'finding_evidence_status' );
$evidence->setAccessible( true );
$declared = $scan( '<script src="https://edge.fullstory.com/s/fs.js"></script>' );
$status = $evidence->invoke( $plugin, $declared['fullstory'] );
expect_own_config( 'warn' === $status['tone'] && false !== strpos( $status['label'], 'réseau' ), 'HTML cannot confirm network requests' );
echo "No self-detection from Dendrila consent configuration: PASS\n";
