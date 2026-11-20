<?php
// Dendrila PHP consent contracts based on WP Consent API 2.1.0.
define( 'ABSPATH', __DIR__ );
define( 'REST_REQUEST', true );
define( 'WP_CONSENT_API_VERSION', '2.1.0' );
function wp_get_consent_type() { return 'optin'; }
function wp_unslash( $x ) { return stripslashes( $x ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function sanitize_key( $x ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $x ) ); }
function wp_has_consent( $category, $by = null ) {
    return $GLOBALS['cmp']->filter_wp_has_consent( ! empty( $GLOBALS['grants'][ $category ] ), $category, $by );
}
class WP_Consent_API { public static $cookie_info; }
class CookieInfo {
    public function get_service_category( $service ) {
        return 'google-analytics' === $service ? 'statistics' : 'marketing';
    }
}
class DendrilaMock {
    public $settings = array( 'consent_enabled' => true, 'consent_retention_days' => 180 );
    public function public_settings() { return $this->settings; }
    public function public_scan() { return array(); }
}
function check( $valid, $message ) {
    if ( ! $valid ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
require_once __DIR__ . '/../includes/class-pixel-trackers-manager-consent.php';
$r = new ReflectionClass( 'Pixel_Trackers_Manager_Consent' );
$cmp = $r->newInstanceWithoutConstructor();
$p = $r->getProperty( 'plugin' ); $p->setAccessible( true );
$plugin = new DendrilaMock(); $p->setValue( $cmp, $plugin );
$GLOBALS['cmp'] = $cmp;
WP_Consent_API::$cookie_info = new CookieInfo();
$GLOBALS['grants'] = array( 'statistics'=>true, 'statistics-anonymous'=>true, 'marketing'=>true, 'functional'=>true, 'preferences'=>true );
check( 'optin' === $cmp->filter_wp_consent_type( 'optout' ), 'REST and AJAX optin' );
check( $cmp->register_wp_consent_manager( false ), 'registered' );
check( 180 === $cmp->wp_consent_cookie_expiration( 30 ), 'expiration' );
check( ! wp_has_consent( 'statistics' ), 'missing marker denies stale category' );
check( ! $cmp->filter_wp_has_service_consent( true, 'facebook-pixel' ), 'old service override denied' );
check( wp_has_consent( 'functional' ), 'essential functions allowed' );
check( ! wp_has_consent( 'preferences' ), 'external not preferences' );
$method = $r->getMethod( 'consent_fingerprint' ); $method->setAccessible( true );
$fp = $method->invoke( $cmp );
$now = (int) ( microtime( true ) * 1000 );
$_COOKIE['dendrila_privacy_consent_v1'] = '1.' . $now . '.100.' . $fp;
check( wp_has_consent( 'statistics' ), 'statistics valid choice' );
check( ! wp_has_consent( 'marketing' ), 'marketing local refusal' );
check( ! $cmp->filter_wp_has_service_consent( true, 'facebook-pixel' ), 'old marketing override still denied' );
check( $cmp->filter_wp_has_service_consent( true, 'google-analytics' ), 'stats service allowed' );
check( ! $cmp->filter_wp_has_service_consent( false, 'google-analytics' ), 'specific refusal preserved' );
$_COOKIE['dendrila_privacy_consent_v1'] = '1.' . $now . '.111.' . str_repeat( '0', 64 );
check( ! wp_has_consent( 'statistics' ), 'fingerprint mismatch' );
$_COOKIE['dendrila_privacy_consent_v1'] = '1.' . ( $now - 181 * 86400000 ) . '.111.' . $fp;
check( ! wp_has_consent( 'statistics' ), 'expired marker' );
$plugin->settings['consent_enabled'] = false;
check( 'optout' === $cmp->filter_wp_consent_type( 'optout' ), 'other manager untouched' );
check( ! $cmp->register_wp_consent_manager( false ), 'disabled not registered' );
check( 30 === $cmp->wp_consent_cookie_expiration( 30 ), 'disabled expiration untouched' );
check( $cmp->filter_wp_has_consent( true, 'marketing' ), 'disabled choice untouched' );
echo "WP Consent API 2.1 PHP checks passed\n";
