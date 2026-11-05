<?php
/** Isolated regression: exercise the real registered menus and enqueue callback. */
define( 'ABSPATH', __DIR__ . '/' );
function register_activation_hook() {}
function register_deactivation_hook() {}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function plugin_dir_url() { return 'https://example.test/wp-content/plugins/dendrila-privacy/'; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function home_url( $path ) { return 'https://example.test' . $path; }
function get_bloginfo() { return 'Test site'; }
function wp_create_nonce( $action ) { return 'test-' . $action; }
function wp_list_pluck( $items, $field ) { return array_column( $items, $field ); }
function add_menu_page( $title, $label, $cap, $slug ) { $GLOBALS['pages'][] = $slug; }
function add_submenu_page( $parent, $title, $label, $cap, $slug ) { $GLOBALS['pages'][] = $slug; }
function wp_enqueue_style( $handle, $url ) { $GLOBALS['styles'][$handle] = $url; }
function wp_enqueue_script( $handle, $url ) { $GLOBALS['scripts'][$handle] = $url; }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][$name] = $data; }

// Load the class without booting unrelated WordPress hooks or touching a database.
$source = file_get_contents( dirname( __DIR__ ) . '/dendrila-privacy.php' );
$source = str_replace( "require_once __DIR__ . '/includes/class-dendrila-privacy-age-assurance.php';", '', $source );
$source = str_replace( 'Pixel_Trackers_Manager_Plugin::instance();', '', $source, $count );
if ( 1 !== $count ) { throw new RuntimeException( 'Bootstrap changed: update the test loader.' ); }
eval( substr( $source, 5 ) );
$plugin = ( new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' ) )->newInstanceWithoutConstructor();
$plugin->admin_menu();
function expect( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
}
foreach ( array_unique( $GLOBALS['pages'] ) as $page ) {
    $_GET = array( 'page' => $page );
    $GLOBALS['styles'] = $GLOBALS['scripts'] = $GLOBALS['localized'] = array();
    $plugin->admin_assets( 'toplevel_page_' . $page );
    expect( isset( $GLOBALS['styles']['pixel-trackers-manager-admin'] ), $page . ': CSS missing' );
    expect( isset( $GLOBALS['scripts']['pixel-trackers-manager-admin'] ), $page . ': JS missing' );
    $config = $GLOBALS['localized']['PixelTrackersManagerScan'];
    expect( ! empty( $config['nonce'] ) && ! empty( $config['ajaxUrl'] ), $page . ': scan configuration missing' );
    foreach ( array_merge( $GLOBALS['styles'], $GLOBALS['scripts'] ) as $url ) {
        expect( is_file( dirname( __DIR__ ) . '/assets/' . basename( $url ) ), 'Missing packaged asset: ' . $url );
    }
}
foreach ( array( '', 'unrelated-plugin', 'pixel-trackers-manager-unrelated', 'dendrila-privacy' ) as $page ) {
    $_GET = array( 'page' => $page );
    $GLOBALS['styles'] = $GLOBALS['scripts'] = array();
    $plugin->admin_assets( 'options-general.php' );
    expect( ! $GLOBALS['styles'] && ! $GLOBALS['scripts'], 'Assets leaked onto unrelated page' );
}
$_GET = array();
$GLOBALS['styles'] = $GLOBALS['scripts'] = array();
$plugin->admin_assets( 'index.php' );
expect( ! empty( $GLOBALS['styles'] ) && ! $GLOBALS['scripts'], 'Dashboard must load styles only' );
echo "PASS: all registered admin pages load CSS, JS and scan configuration; unrelated pages remain untouched.\n";
