<?php
// CNIL session replay consent checks, without a WordPress installation.
define( 'ABSPATH', __DIR__ );
function current_user_can() { return false; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_unslash( $v ) { return stripslashes( $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $v ) ); }
function sanitize_text_field( $v ) { return strip_tags( (string) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_attr( $v ) { return htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $message, $domain = 'default' ) { return esc_attr( $message ); }
function esc_html__( $message, $domain = 'default' ) { return htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ); }
function expect( $good, $message ) {
    if ( ! $good ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
class ReplayMockPlugin {
    public $findings = array();
    public function public_settings() {
        return array( 'consent_enabled' => 1, 'consent_style' => 'default', 'consent_layout' => 'bar' );
    }
    public function public_scan() { return array( 'findings' => $this->findings ); }
}
require_once __DIR__ . '/../includes/class-pixel-trackers-manager-consent.php';
$ref = new ReflectionClass( 'Pixel_Trackers_Manager_Consent' );
$cmp = $ref->newInstanceWithoutConstructor();
$prop = $ref->getProperty( 'plugin' );
$prop->setAccessible( true );
$plugin = new ReplayMockPlugin();
$prop->setValue( $cmp, $plugin );
$classification = $ref->getMethod( 'classify_url' );
$classification->setAccessible( true );
foreach ( array(
    'https://edge.fullstory.com/s/fs.js',
    'https://edge.eu1.fullstory.com/s/fs.js',
    'https://cdn.mouseflow.com/projects/example.js',
    'https://web-sdk.smartlook.com/recorder.js',
    'https://cdn.logr-in.com/LogRocket.min.js',
) as $url ) {
    expect( 'statistics' === $classification->invoke( $cmp, $url ), "replay SDK classified: $url" );
}
expect( '' === $classification->invoke( $cmp, 'https://store.example.org/checkout' ), 'checkout URL remains allowed' );
expect( '' === $classification->invoke( $cmp, 'https://js.stripe.com/v3/' ), 'payment SDK remains allowed' );

$fingerprint = $ref->getMethod( 'consent_fingerprint' );
$fingerprint->setAccessible( true );
$baseline = $fingerprint->invoke( $cmp );
ob_start();
$cmp->render_banner();
$plainBanner = ob_get_clean();
expect( false === strpos( $plainBanner, 'reconstruct interactions' ), 'no unjustified replay claim' );

$plugin->findings = array( array( 'id' => 'fullstory', 'active_tracking' => 1 ) );
expect( $baseline !== $fingerprint->invoke( $cmp ), 'replay-enabled scan invalidates previous choice' );
$newCmp = $ref->newInstanceWithoutConstructor();
$prop->setValue( $newCmp, $plugin );
ob_start();
$newCmp->render_banner();
$replayBanner = ob_get_clean();
expect( false !== strpos( $replayBanner, 'reconstruct interactions' ), 'first-level replay information' );
expect( false !== strpos( $replayBanner, 'replaying a browsing session' ), 'category detail on replay' );
echo "CNIL 2026 replay consent tests: PASS\n";
