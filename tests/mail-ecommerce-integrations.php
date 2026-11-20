<?php
// Offline regression fixtures grounded in the user-provided MC4WP 4.15.0 and
// Brevo 3.3.6 ZIPs. No mail is sent and no checkout is processed.
define( 'ABSPATH', __DIR__ );
function register_activation_hook() {}
function register_deactivation_hook() {}
function get_option( $name, $default = array() ) {
    return isset( $GLOBALS['test_options'][ $name ] ) ? $GLOBALS['test_options'][ $name ] : $default;
}
function mc4wp_get_options() {
    $defaults = array( 'tracking_pixel_enabled' => false,
        'tracking_pixel_site_id' => '', 'tracking_pixel_script_url' => '' );
    return array_merge( $defaults, get_option( 'mc4wp', array() ) );
}
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function current_user_can() { return false; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function check( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$main = file_get_contents( dirname( __DIR__ ) . '/dendrila-privacy.php' );
$main = str_replace( "require_once __DIR__ . '/includes/class-dendrila-privacy-age-assurance.php';", '', $main );
$main = str_replace( 'Pixel_Trackers_Manager_Plugin::instance();', '', $main, $count );
check( 1 === $count, 'main plugin loader located exactly once' );
eval( substr( $main, 5 ) );
$plugin = ( new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' ) )->newInstanceWithoutConstructor();
$r = new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' );
$mc4wp = $r->getMethod( 'mc4wp_site_tracking_state' );
$brevo = $r->getMethod( 'brevo_site_tracking_state' );
$mc4wp->setAccessible( true );
$brevo->setAccessible( true );

// MC4WP 4.15.0: stored WordPress option and public settings accessor.
$GLOBALS['test_options'] = array();
check( 'disabled' === $mc4wp->invoke( $plugin )['state'], 'MC4WP defaults off' );
$GLOBALS['test_options']['mc4wp'] = array(
    'tracking_pixel_enabled' => true, 'tracking_pixel_site_id' => '',
    'tracking_pixel_script_url' => '',
);
check( 'unknown' === $mc4wp->invoke( $plugin )['state'], 'MC4WP enabled but not connected' );
$GLOBALS['test_options']['mc4wp'] = array(
    'tracking_pixel_enabled' => true, 'tracking_pixel_site_id' => 'site-1',
    'tracking_pixel_script_url' => 'https://chimpstatic.com/mcjs-connected/js/users/test.js',
);
check( 'active' === $mc4wp->invoke( $plugin )['state'], 'MC4WP pixel enabled and connected' );
$GLOBALS['test_options']['mc4wp']['tracking_pixel_enabled'] = false;
check( 'disabled' === $mc4wp->invoke( $plugin )['state'], 'MC4WP site tracking disabled separately from forms' );

// Brevo 3.3.6: SIB_Manager::is_ma_active() uses ma_key and activate_ma=no.
$GLOBALS['test_options'] = array();
check( 'disabled' === $brevo->invoke( $plugin )['state'], 'Brevo no key: no automation' );
$GLOBALS['test_options']['sib_main_option'] = array( 'ma_key' => 'test-placeholder-not-secret' );
check( 'active' === $brevo->invoke( $plugin )['state'], 'Brevo default automation enabled with MA key' );
$GLOBALS['test_options']['sib_home_option'] = array( 'activate_ma' => 'no', 'activate_email' => 'yes' );
check( 'disabled' === $brevo->invoke( $plugin )['state'],
    'Brevo transactional email continues while marketing automation is off' );
$GLOBALS['test_options']['sib_home_option']['activate_ma'] = 'yes';
check( 'active' === $brevo->invoke( $plugin )['state'], 'Brevo marketing automation explicitly on' );

require_once dirname( __DIR__ ) . '/includes/class-pixel-trackers-manager-consent.php';
class PluginForConsent {
    public function public_settings() { return array( 'consent_enabled' => true ); }
}
$c = new ReflectionClass( 'Pixel_Trackers_Manager_Consent' );
$manager = $c->newInstanceWithoutConstructor();
$prop = $c->getProperty( 'plugin' );
$prop->setAccessible( true );
$prop->setValue( $manager, new PluginForConsent() );
$classify = $c->getMethod( 'classify_url' );
$classify->setAccessible( true );
foreach ( array(
    'https://cdn.brevo.com/js/sdk-loader.js',
    'https://cdn.brevo.com/js/sdk-staging-loader.js',
    'https://chimpstatic.com/mcjs-connected/js/users/example.js'
) as $url ) {
    check( 'marketing' === $classify->invoke( $manager, $url ), 'tracker classified as marketing: ' . $url );
}
foreach ( array(
    'https://js.stripe.com/v3/',
    'https://www.paypal.com/sdk/js',
    'https://shop.test/wp-json/wc/store/cart',
    'https://shop.test/?wc-ajax=checkout'
) as $url ) {
    check( '' === $classify->invoke( $manager, $url ), 'checkout unblocked: ' . $url );
}
$tag = '<script src="https://example.invalid/vendor-pixel.js" defer></script>';
$blocked = $manager->filter_script_tag( $tag,
    'mc4wp-mailchimp-site-tracking-pixel', 'https://example.invalid/vendor-pixel.js' );
check( false !== strpos( $blocked, 'data-ptm-category="marketing"' ),
    'MC4WP handle blocked even when the site-specific URL is unknown' );
check( false !== strpos( $blocked, 'type="text/plain"' ),
    'MC4WP script becomes inert until consent' );
$untouched = $manager->filter_script_tag( $tag,
    'woocommerce-stripe', 'https://js.stripe.com/v3/' );
check( $tag === $untouched, 'payment script untouched' );
echo "Mail/commerce PHP integration checks PASS\n";
