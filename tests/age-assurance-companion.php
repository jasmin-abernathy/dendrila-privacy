<?php

define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function apply_filters( $hook, $value ) { return $value; }

require dirname( __DIR__ ) . '/includes/class-dendrila-privacy-age-assurance.php';

$failures = 0;

function assert_same( $expected, $actual, $label ) {
    global $failures;
    if ( $expected !== $actual ) {
        $failures++;
        fwrite( STDERR, 'FAIL: ' . $label . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) . PHP_EOL );
    }
}

$good = Dendrila_Privacy_Age_Assurance::normalize_companion_status( array(
    'enabled' => true,
    'mode' => 'selected',
    'provider' => 'ageverif',
    'provider_configured' => true,
    'age_threshold' => 18,
    'required_assurance' => 'STRICT',
    'session_ttl_minutes' => 60,
) );

assert_same( 'good', $good['state'], 'enabled + configured should be good' );
assert_same( 'selected', $good['mode'], 'selected mode should be preserved' );
assert_same( 18, $good['age_threshold'], 'threshold should be preserved' );
assert_same( 'STRICT', $good['required_assurance'], 'assurance should be preserved' );

$incomplete = Dendrila_Privacy_Age_Assurance::normalize_companion_status( array(
    'enabled' => true,
    'mode' => 'sitewide',
    'provider_configured' => false,
) );
assert_same( 'bad', $incomplete['state'], 'enabled without provider should be incomplete' );

$off = Dendrila_Privacy_Age_Assurance::normalize_companion_status( array(
    'enabled' => false,
    'mode' => 'off',
    'provider_configured' => true,
) );
assert_same( 'warn', $off['state'], 'configured but disabled should not be reported active' );

$unknown = Dendrila_Privacy_Age_Assurance::normalize_companion_status( array(
    'enabled' => true,
    'mode' => 'mystery',
    'required_assurance' => 'UNKNOWN',
) );
assert_same( 'off', $unknown['mode'], 'unknown mode should fail to off' );
assert_same( '', $unknown['required_assurance'], 'unknown assurance should not be trusted' );

if ( $failures ) {
    exit( 1 );
}

echo 'Age assurance companion checks passed.' . PHP_EOL;
