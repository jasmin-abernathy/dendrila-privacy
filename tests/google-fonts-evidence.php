<?php
/**
 * Regressions for the Google Fonts false positives reported by a Divi tester:
 * remote font links, preconnect hints, HTML comments, inline JS and local fonts.
 */
define( 'ABSPATH', __DIR__ . '/' );
function register_activation_hook() {}
function register_deactivation_hook() {}
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
echo "Google Fonts reference versus loading declaration checks: PASS\n";
