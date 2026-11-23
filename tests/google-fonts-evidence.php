<?php
/**
 * Theme-agnostic regressions for static font references versus declared resources.
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
    array( '<div style="background-image:url(https://fonts.gstatic.com/s/test.woff2)"></div>', true, true, 'remote CSS URL in a style attribute' ),
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
    check( 'potential' === $finding['tracking_state'], $label . ': a declaration is not network proof' );
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

/**
 * Parent/child inheritance: a same-name module is NOT necessarily overridden.
 */
function get_stylesheet_directory() { return $GLOBALS['dendrila_test_child']; }
function get_template_directory() { return $GLOBALS['dendrila_test_parent']; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
$root = sys_get_temp_dir() . '/dendrila-theme-' . uniqid( '', true );
$parent = $root . '/studio-framework';
$child = $root . '/studio-framework-child';
foreach ( array( $parent . '/inc/components', $child . '/inc/components', $parent . '/template-parts', $child . '/template-parts' ) as $dir ) {
    check( is_dir( $dir ) || mkdir( $dir, 0700, true ), 'create theme fixtures' );
}
$GLOBALS['dendrila_test_parent'] = $parent;
$GLOBALS['dendrila_test_child'] = $child;
file_put_contents( $parent . '/inc/components/styles.php', "<?php\n// fonts.googleapis.com referenced conditionally.\n" );
file_put_contents( $child . '/inc/components/styles.php', "<?php\n// Same path does not override a module.\n" );
file_put_contents( $parent . '/template-parts/header.php', "<?php\n// fonts.gstatic.com conditional.\n" );
file_put_contents( $child . '/template-parts/header.php', "<?php\n// Child template.\n" );
file_put_contents( $parent . '/functions.php', "<?php\n// fonts.googleapis.com conditional.\n" );
file_put_contents( $child . '/functions.php', "<?php\n// Loaded alongside parent functions.php.\n" );
file_put_contents( $parent . '/custom-loader.php', "<?php\n// fonts.googleapis.com appears in a helper.\n" );
file_put_contents( $child . '/custom-loader.php', "<?php\n// Same-named include is not a guaranteed override.\n" );
file_put_contents( $parent . '/single.php', "<?php\n// fonts.googleapis.com template reference.\n" );
file_put_contents( $child . '/single.php', "<?php\n// Child template with normal hierarchy priority.\n" );
file_put_contents( $child . '/style.css', "/* fonts.googleapis.com mentioned locally */\n" );
$method = $ref->getMethod( 'scan_theme_references' );
$method->setAccessible( true );
$refs = $method->invoke( $plugin );
check( 6 === count( $refs['google-fonts'] ), 'theme names do not affect static code reference detection' );
$by_path = array();
foreach ( $refs['google-fonts'] as $entry ) { $by_path[ $entry['path'] ] = $entry; }
check( 'parent' === $by_path['studio-framework/inc/components/styles.php']['role'], 'parent role recorded' );
check( 2 === $by_path['studio-framework/inc/components/styles.php']['line'], 'source line recorded' );
check( false !== strpos( $by_path['studio-framework/inc/components/styles.php']['relationship'], 'peut toujours être exécuté' ), 'homonymous PHP module is not a proven override' );
check( false !== strpos( $by_path['studio-framework/template-parts/header.php']['relationship'], 'priorité habituelle' ), 'template-part priority remains qualified' );
check( false !== strpos( $by_path['studio-framework/custom-loader.php']['relationship'], 'peut toujours être exécuté' ), 'arbitrary same-name PHP files are not assumed to be templates' );
check( false !== strpos( $by_path['studio-framework/single.php']['relationship'], 'priorité habituelle' ), 'known template hierarchy is identified' );
check( false !== strpos( $by_path['studio-framework/functions.php']['relationship'], 'peut toujours être exécuté' ), 'both functions.php files can run' );
check( 'child' === $by_path['studio-framework-child/style.css']['role'], 'child stylesheet origin' );
check( null === font_result( $scan, $plugin, '<link rel="stylesheet" href="/wp-content/themes/studio-framework-child/style.css">' ), 'local stylesheet does not imply remote font load' );
$status_method = $ref->getMethod( 'finding_evidence_status' );
$status_method->setAccessible( true );
$theme_status = $status_method->invoke( $plugin, array( 'id'=>'fullstory', 'tracking_state'=>'potential', 'code_reference'=>true, 'theme_references'=>array( $by_path['studio-framework/functions.php'] ) ) );
check( 'neutral' === $theme_status['tone'] && false !== strpos( $theme_status['label'], 'code du thème' ), 'theme-only signature is labelled as a non-confirmed theme mention' );
$finalize = $ref->getMethod( 'finalize_findings' );
$finalize->setAccessible( true );
$theme_findings = $finalize->invoke( $plugin, array(), array(), $refs );
check( ! empty( $theme_findings['google-fonts']['theme_references'] ), 'theme file paths remain attached to the finding' );
check( empty( $theme_findings['google-fonts']['active_tracking'] ) && 'potential' === $theme_findings['google-fonts']['tracking_state'], 'theme-only references do not create active tracking' );
check( empty( array_filter( $theme_findings, static function ( $finding ) { return ! empty( $finding['active_tracking'] ); } ) ), 'static references in any theme produce no active count' );
foreach ( array( $child . '/inc/components/styles.php', $child . '/template-parts/header.php', $child . '/functions.php', $child . '/custom-loader.php', $child . '/single.php', $child . '/style.css', $parent . '/inc/components/styles.php', $parent . '/template-parts/header.php', $parent . '/functions.php', $parent . '/custom-loader.php', $parent . '/single.php' ) as $path ) { unlink( $path ); }
foreach ( array( $child . '/inc/components', $child . '/inc', $child . '/template-parts', $parent . '/inc/components', $parent . '/inc', $parent . '/template-parts', $child, $parent, $root ) as $dir ) { rmdir( $dir ); }
$plain = sys_get_temp_dir() . '/dendrila-plain-theme-' . uniqid( '', true );
check( mkdir( $plain, 0700, true ), 'create theme without a child fixture' );
file_put_contents( $plain . '/style.css', "/* fonts.googleapis.com appears in a theme stylesheet. */\n" );
$GLOBALS['dendrila_test_parent'] = $plain;
$GLOBALS['dendrila_test_child'] = $plain;
$plain_refs = $method->invoke( $plugin );
check( 1 === count( $plain_refs['google-fonts'] ), 'theme without child is scanned without a theme-name special case' );
check( 'active' === $plain_refs['google-fonts'][0]['role'], 'theme without child is identified as the active theme' );
unlink( $plain . '/style.css' );
rmdir( $plain );
echo "Theme parent/child references: PASS\n";
