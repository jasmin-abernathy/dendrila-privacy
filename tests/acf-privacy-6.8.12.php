<?php
/**
 * ACF 6.8.12 settings and metadata checks without loading WordPress or touching
 * field values. The mocks reflect ACF's actual documented public APIs.
 */
define( 'ABSPATH', __DIR__ . '/' );
function register_activation_hook() {}
function register_deactivation_hook() {}
function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
}
function acf_get_setting( $name ) {
    return isset( $GLOBALS['acf_settings'][ $name ] ) ? $GLOBALS['acf_settings'][ $name ] : false;
}
function wp_register_ability() {} // Simulate WordPress 6.9+ Abilities API availability.
function acf_get_field_groups() {
    ++$GLOBALS['acf_group_reads'];
    return $GLOBALS['acf_groups'];
}
function acf_get_fields( $group ) {
    return isset( $GLOBALS['acf_fields'][ $group['key'] ] ) ? $GLOBALS['acf_fields'][ $group['key'] ] : array();
}
function check( $pass, $label ) {
    if ( ! $pass ) {
        fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL );
        exit( 1 );
    }
}
$source = file_get_contents( dirname( __DIR__ ) . '/dendrila-privacy.php' );
$source = str_replace( "require_once __DIR__ . '/includes/class-dendrila-privacy-age-assurance.php';", '', $source );
$source = str_replace( 'Pixel_Trackers_Manager_Plugin::instance();', '', $source, $count );
check( 1 === $count, 'main plugin loader found exactly once' );
eval( substr( $source, 5 ) );
$ref = new ReflectionClass( 'Pixel_Trackers_Manager_Plugin' );
$plugin = $ref->newInstanceWithoutConstructor();
$review = $ref->getMethod( 'acf_privacy_configuration' );
$review->setAccessible( true );

$GLOBALS['acf_settings'] = array( 'rest_api_enabled' => true, 'enable_abilities' => true );
$GLOBALS['acf_group_reads'] = 0;
$GLOBALS['acf_groups'] = array(
    array( 'key' => 'group_public', 'active' => true, 'show_in_rest' => true, 'enable_acf_abilities' => true ),
    array( 'key' => 'group_local', 'active' => true, 'show_in_rest' => false, 'enable_acf_abilities' => true ),
    array( 'key' => 'group_inactive', 'active' => false, 'show_in_rest' => true, 'enable_acf_abilities' => true ),
);
$GLOBALS['acf_fields'] = array(
    'group_public' => array(
        array( 'type' => 'email', 'default_value' => 'never-export-this@example.invalid' ),
        array( 'type' => 'google_map', 'key' => 'map_field' ),
    ),
    'group_local' => array( array( 'type' => 'text', 'value' => 'PRIVATE_VALUE_DO_NOT_COPY' ) ),
);
$acf = array( 'plugins' => array( array( 'slug' => 'advanced-custom-fields', 'active' => true ) ) );
$inactive = array( 'plugins' => array( array( 'slug' => 'advanced-custom-fields', 'active' => false ) ) );
check( false === $review->invoke( $plugin, $inactive )['active'], 'inactive plugin ignored' );
check( 0 === $GLOBALS['acf_group_reads'], 'do not enumerate fields when ACF is inactive' );
$out = $review->invoke( $plugin, $acf );
check( true === $out['active'], 'ACF identified' );
check( true === $out['readable'], 'ACF native settings readable' );
check( 2 === $out['groups_checked'], 'inactive groups ignored' );
check( 1 === $out['rest_groups'], 'REST-enabled active field group counted' );
check( 2 === $out['abilities_groups'], 'Abilities group setting read without assuming remote AI connection' );
check( 1 === $out['google_map_fields'], 'map field configuration counted' );
check( 1 === $out['data_input_fields'], 'potential personal data field type counted' );
check( false === strpos( json_encode( $out ), 'PRIVATE_VALUE_DO_NOT_COPY' ), 'no field values exposed' );
check( false === strpos( json_encode( $out ), 'never-export-this@example.invalid' ), 'no field defaults exported' );
$acf['plugins'][0]['slug'] = 'advanced-custom-fields-pro';
check( true === $review->invoke( $plugin, $acf )['active'], 'ACF Pro also supported' );

$GLOBALS['acf_settings']['rest_api_enabled'] = false;
check( 0 === $review->invoke( $plugin, $acf )['rest_groups'], 'global REST setting respected' );
$GLOBALS['acf_settings']['enable_abilities'] = false;
check( 0 === $review->invoke( $plugin, $acf )['abilities_groups'], 'disabled Abilities not shown as active' );

$GLOBALS['acf_settings']['rest_api_enabled'] = true;
$GLOBALS['acf_groups'] = array( array( 'key' => 'only_local', 'show_in_rest' => false, 'active' => true ) );
$GLOBALS['acf_fields'] = array( 'only_local' => array( array( 'type' => 'text' ) ) );
check( 0 === $review->invoke( $plugin, $acf )['google_map_fields'], 'no invented map activity' );
check( 0 === $review->invoke( $plugin, $acf )['rest_groups'], 'REST not inferred from plugin install' );
echo "ACF 6.8.12 privacy settings: PASS\n";
