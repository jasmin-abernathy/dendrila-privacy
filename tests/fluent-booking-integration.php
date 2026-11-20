<?php
// Regression for the Fluent Booking 2.6.0 privacy integration, no WordPress needed.
define( 'ABSPATH', __DIR__ . '/' );
function register_activation_hook() {}
function register_deactivation_hook() {}
function get_option( $name, $default = array() ) {
    return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default;
}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function check( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$source = file_get_contents( dirname( __DIR__ ) . '/dendrila-privacy.php' );
$source = str_replace( "require_once __DIR__ . '/includes/class-dendrila-privacy-age-assurance.php';", '', $source );
$source = str_replace( 'Pixel_Trackers_Manager_Plugin::instance();', '', $source, $count );
check( 1 === $count, 'plugin main loader found' );
eval( substr( $source, 5 ) );
$plugin = ( new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' ) )->newInstanceWithoutConstructor();
$r = new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' );
$state = $r->getMethod( 'fluent_booking_privacy_state' );
$state->setAccessible( true );
$templates = $r->getMethod( 'treatment_template_recommendations' );
$templates->setAccessible( true );
$GLOBALS['test_options'] = array();

$scan = array( 'plugins' => array( array( 'slug'=>'fluent-booking', 'active'=>false ) ) );
check( false === $state->invoke( $plugin, $scan )['active'], 'inactive booking plugin ignored' );
$scan['plugins'][0]['active'] = true;
$s = $state->invoke( $plugin, $scan );
check( $s['active'] === true && $s['mcp_enabled'] === false, 'booking active but MCP defaults off' );
$ids = array_column( $templates->invoke( $plugin, $scan ), 'id' );
check( in_array( 'bookings', $ids, true ), 'booking treatment offered' );
$GLOBALS['test_options']['_fluent_booking_mcp_settings'] = array( 'enabled'=>'yes' );
check( $state->invoke( $plugin, $scan )['mcp_enabled'] === true, 'MCP activated from actual plugin option' );
$GLOBALS['test_options']['_fluent_booking_mcp_settings'] = array( 'enabled'=>'no' );
check( $state->invoke( $plugin, $scan )['mcp_enabled'] === false, 'MCP disabled again' );
$scan['plugins'] = array( array( 'slug'=>'fluent-crm', 'active'=>true ) );
check( $state->invoke( $plugin, $scan )['active'] === false, 'FluentCRM is not Fluent Booking' );

$js = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
check( false !== strpos( $js, "bookings: {" ), 'booking example exists in admin.js' );
check( false !== strpos( $js, 'adresse IP et paramètres UTM' ), 'booking treatment describes saved IP/UTM data' );
echo "Fluent Booking 2.6.0 privacy treatment checks: PASS\n";
