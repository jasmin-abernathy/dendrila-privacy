<?php
/**
 * Regressions for the Google Fonts false positives reported by a Divi tester:
 * remote font links, preconnect hints, HTML comments, inline JS and local fonts.
 */
define( 'ABSPATH', __DIR__ . '/' );
function register_activation_hook() {}
function register_deactivation_hook() {}
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function check( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}

$main = file_get_contents( dirname( __DIR__ ) . '/dendrila-privacy.php' );
$main = str_replace( "require_once __DIR__ . '/includes/class-dendrila-privacy-age-assurance.php';", '', $main );
$main = str_replace( 'Pixel_Trackers_Manager_Plugin::instance();', '', $main, $count );
check( 1 === $count, 'main plugin bootstrap present once' );
eval( substr( $main, 5 ) );
$ref = new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' );
$plugin = $ref->newInstanceWithoutConstructor();
$scan = $ref->getMethod( 'scan_html_into_findings' );
$scan->setAccessible( true );
$helper = $ref->getMethod( 'google_fonts_html_evidence' );
$helper->setAccessible( true );
$control = $ref->getMethod( 'tracking_control_for_finding' );
$control->setAccessible( true );

function font_result( $scan, $plugin, $html ) {
    $results = $scan->invoke( $plugin, $html, 'https://example.test/page/', array() );
    return isset( $results['google-fonts'] ) ? $results['google-fonts'] : null;
}

$cases = array(
    // The screenshots show fonts served from /wp-content/fonts/, not Google.
    array( '<link rel="stylesheet" href="/wp-content/fonts/local.css"><style>@font-face{src:url(/wp-content/fonts/local.woff2)}</style>', false, false, 'local fonts only' ),
    array( '<!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans"> -->', false, false, 'comment is not an external load' ),
    array( '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="/wp-content/fonts/my-font.css" rel="stylesheet">', true, false, 'preconnect not a load' ),
    array( '<script>var config={"google_font_api":"https://fonts.googleapis.com/css2"};</script>', true, false, 'inline script string is not a load' ),
    array( '<link href="https://fonts.googleapis.com/css2?family=Roboto" rel="stylesheet">', true, true, 'stylesheet Google Fonts link' ),
    array( '<link rel="preload" as="font" href="https://fonts.gstatic.com/s/roboto.woff2">', true, true, 'direct remote font preload' ),
    array( '<style>@import url("https://fonts.googleapis.com/css2?family=Roboto");</style>', true, true, 'remote CSS import' ),
    array( '<style>/* @import url("https://fonts.googleapis.com/css2") */ .x{font-family:Roboto}</style>', false, false, 'commented CSS import' ),
    array( '<style>@font-face{font-family:Test;src:url(https://fonts.gstatic.com/s/test.woff2)}</style>', true, true, 'remote font face' ),
    array( '<link href="//fonts.googleapis.com/css2?family=Roboto" rel="stylesheet">', true, true, 'protocol relative stylesheet' ),
    array( '<link href=https://fonts.googleapis.com/css2?family=Roboto rel=stylesheet>', true, true, 'unquoted stylesheet link' ),
    array( '<script>const html = \'<link rel="stylesheet" href="//fonts.googleapis.com/css2">\';</script>', true, false, 'markup inside script string' ),
    array( '<template><template><link rel="stylesheet" href="//fonts.googleapis.com/css2"></template></template>', true, false, 'nested inactive template' ),
    array( '<div data-preview=\'<link rel="stylesheet" href="//fonts.googleapis.com/css2">\'></div>', true, false, 'markup inside quoted attribute' ),
    array( '<link rel="stylesheet" data-href="//fonts.googleapis.com/css2" href="/local.css">', true, false, 'inert data-href' ),
    array( '<link data-rel="stylesheet" rel="preconnect" href="//fonts.googleapis.com">', true, false, 'inert data-rel' ),
    array( '<link rel="prefetch" href="//fonts.googleapis.com/css2">', true, false, 'speculative prefetch' ),
    array( '<link rel="preload" as="image" href="//fonts.gstatic.com/file">', true, false, 'non-font preload' ),
    array( '<style>@import "/local.css?origin=fonts.googleapis.com";</style>', true, false, 'local CSS with Google in query' ),
    array( '<style>.x{content:\'url(https://fonts.gstatic.com/x)\'}</style>', true, false, 'CSS string is not a resource' ),
    array( '<style>@font-face{src:url(https://fonts.gstatic.com.example.test/a.woff2)}</style>', true, false, 'lookalike font host' ),
    array( '<link rel="preload" as="style" href="//fonts.googleapis.com/css2">', true, true, 'stylesheet preload' ),
);
foreach ( $cases as $case ) {
    list( $html, $present, $loadable, $label ) = $case;
    $finding = font_result( $scan, $plugin, $html );
    check( $present === ( null !== $finding ), $label . ': finding presence' );
    if ( null === $finding ) { continue; }
    check( $loadable === ! empty( $finding['observed_html'] ), $label . ': declared remote load' );
    check( false === ! empty( $finding['active_tracking'] ), $label . ': fonts must not be treated as a tracker' );
    check( ( $loadable ? 'active' : 'potential' ) === $finding['tracking_state'], $label . ': correct state' );
    $status = $control->invoke( $plugin, $finding );
    check( $status['state'] === $finding['tracking_state'], $label . ': action must not force active state' );
    check( false === strpos( $status['instructions'], 'est chargée directement' ), $label . ': no unverified loading claim' );
}
$mixed = $scan->invoke( $plugin, '<link rel="preconnect" href="//fonts.gstatic.com">', 'https://example.test/hint/', array() );
$mixed = $scan->invoke( $plugin, '<link rel="stylesheet" href="//fonts.googleapis.com/css2">', 'https://example.test/load/', $mixed );
check( array( 'https://example.test/load/' ) === $mixed['google-fonts']['urls'], 'a hint page must not be attributed to a declaration' );
check( array( 'https://example.test/hint/' ) === $mixed['google-fonts']['font_hint_urls'], 'hint page is retained separately' );
$comment = $scan->invoke( $plugin, '<!-- <script src="https://connect.facebook.net/en_US/fbevents.js"></script> -->', 'https://example.test/comment/', array() );
check( empty( $comment['meta-pixel'] ), 'commented Meta Pixel must not be active' );
$live = $scan->invoke( $plugin, '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>', 'https://example.test/live/', array() );
check( ! empty( $live['meta-pixel']['observed_html'] ), 'live Meta Pixel script remains detectable' );
echo "Google Fonts reference versus loading declaration checks: PASS\n";
