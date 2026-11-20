<?php
/** Source-based regression tests: The Events Calendar 6.18.4.1, with no WP database. */
define( 'ABSPATH', __DIR__ . '/' );
function register_activation_hook() {}
function register_deactivation_hook() {}
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $v ) ); }
function sanitize_text_field( $v ) { return strip_tags( (string) $v ); }
function wp_unslash( $v ) { return stripslashes( $v ); }
function get_option( $k, $d = null ) { return $d; }
function tribe_get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['tec_options'] ) ? $GLOBALS['tec_options'][$k] : $d; }
function current_user_can() { return false; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function expect( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: '.$message.PHP_EOL ); exit( 1 ); }
}
$source = file_get_contents( dirname(__DIR__).'/dendrila-privacy.php' );
$source = str_replace( "require_once __DIR__ . '/includes/class-dendrila-privacy-age-assurance.php';", '', $source );
$source = str_replace( 'Pixel_Trackers_Manager_Plugin::instance();', '', $source, $count );
expect( $count === 1, 'main bootstrap found' );
eval( substr( $source, 5 ) );
$p = ( new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' ) )->newInstanceWithoutConstructor();
$r = new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' );
$state = $r->getMethod( 'events_calendar_privacy_configuration' ); $state->setAccessible( true );
$templates = $r->getMethod( 'treatment_template_recommendations' ); $templates->setAccessible( true );
$GLOBALS['tec_options'] = array( 'embedGoogleMaps' => true );
$scan = array( 'plugins'=>array( array( 'slug'=>'the-events-calendar','active'=>false ) ) );
expect( ! $state->invoke( $p, $scan )['active'], 'inactive TEC ignored' );
$scan['plugins'][0]['active'] = true;
$report = $state->invoke( $p, $scan );
expect( $report['active'] && $report['settings_readable'], 'active TEC recognized' );
expect( 'enabled' === $report['maps_setting'], 'global maps setting read' );
expect( 'unknown' === $report['telemetry_setting'], 'telemetry unknown without opt-in option' );
expect( in_array( 'events', array_column( $templates->invoke( $p, $scan ), 'id' ), true ), 'events purpose template present' );
$GLOBALS['tec_options']['opt_in_status'] = true;
expect( 'enabled' === $state->invoke( $p, $scan )['telemetry_setting'], 'local opt-in true' );
$GLOBALS['tec_options']['opt_in_status'] = false;
expect( 'disabled' === $state->invoke( $p, $scan )['telemetry_setting'], 'local opt-in false' );
$GLOBALS['tec_options']['embedGoogleMaps'] = false;
expect( 'disabled' === $state->invoke( $p, $scan )['maps_setting'], 'maps setting off' );
$scan['plugins'][0]['slug'] = 'event-tickets';
expect( ! $state->invoke( $p, $scan )['active'], 'do not infer TEC from ticket plugin' );
$admin = file_get_contents( dirname(__DIR__).'/assets/admin.js' );
expect( false !== strpos( $admin, 'events: {' ), 'event documentation example included' );

require_once dirname(__DIR__).'/includes/class-pixel-trackers-manager-consent.php';
class EventConsentConfig { public function public_settings() { return array('consent_enabled'=>true); } }
$c = new ReflectionClass( 'Pixel_Trackers_Manager_Consent' );
$m = $c->newInstanceWithoutConstructor();
$prop = $c->getProperty('plugin');$prop->setAccessible(true);$prop->setValue($m,new EventConsentConfig());
$cls = $c->getMethod('classify_url');$cls->setAccessible(true);
foreach( array('https://maps.google.com/maps/api/js','https://maps.googleapis.com/maps/api/js?key=masked','https://www.google.com/maps/embed/v1/place') as $url ){
    expect( 'external' === $cls->invoke($m,$url), 'external map API: '.$url );
}
foreach( array('https://maps.google.com/maps?f=q&q=Paris','https://js.stripe.com/v3/','https://www.paypal.com/sdk/js','https://shop.example/wp-json/tribe/events/v1/events') as $url ){
    expect( '' === $cls->invoke($m,$url), 'nonblocking functional/navigation URL: '.$url );
}
$script='<script src="https://custom.example/maps/proxy.js"></script>';
$blocked=$m->filter_script_tag($script,'tribe-events-google-maps','https://custom.example/maps/proxy.js');
expect( false!==strpos($blocked,'type="text/plain"') && false!==strpos($blocked,'data-ptm-category="external"'), 'known TEC map handle blocked even at a custom URL' );
expect( $script===$m->filter_script_tag($script,'tribe-events-dynamic','https://custom.example/maps/proxy.js'), 'ordinary event calendar scripts untouched' );
echo "The Events Calendar privacy and map blocking: PASS\n";
