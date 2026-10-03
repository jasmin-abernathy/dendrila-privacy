<?php
define( 'DENDRILA_PRIVACY_STANDALONE_TEST', true );
require dirname( __DIR__ ) . '/includes/class-dendrila-privacy-consent-core.php';

function dp_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

$secret = 'test-secret';
dp_assert(
    Dendrila_Privacy_Consent_Core::subject_hash( ' User@Example.COM ', $secret )
        === Dendrila_Privacy_Consent_Core::subject_hash( 'user@example.com', $secret ),
    'email subject hashes must be normalized'
);
dp_assert(
    Dendrila_Privacy_Consent_Core::subject_hash( 'user@example.com', $secret )
        !== Dendrila_Privacy_Consent_Core::subject_hash( 'user@example.com', 'other-secret' ),
    'subject hashes must depend on the site secret'
);

$local = array( 'statistics'=>true, 'external'=>false, 'marketing'=>false, 'savedAt'=>1000, 'fingerprint'=>'x' );
$account = array( 'statistics'=>false, 'external'=>false, 'marketing'=>false, 'savedAt'=>900, 'fingerprint'=>'x' );

$r = Dendrila_Privacy_Consent_Core::resolve_cross_device( $local, $account, 'account_wins' );
dp_assert( false === $r['choice']['statistics'] && true === $r['write_local'] && 'account_wins' === $r['reason'], 'account_wins conflict mode' );

$r = Dendrila_Privacy_Consent_Core::resolve_cross_device( $local, $account, 'device_wins' );
dp_assert( true === $r['choice']['statistics'] && true === $r['sync_account'] && 'device_wins' === $r['reason'], 'device_wins conflict mode' );

$r = Dendrila_Privacy_Consent_Core::resolve_cross_device( $local, null, 'account_wins' );
dp_assert( true === $r['sync_account'] && 'account_created' === $r['reason'], 'local choice must seed an empty account' );

$r = Dendrila_Privacy_Consent_Core::resolve_cross_device( null, $account, 'device_wins' );
dp_assert( true === $r['write_local'] && 'account_restored' === $r['reason'], 'account choice must restore an empty device' );

$event_a = array( 'purpose'=>'cookie_cross_device', 'context'=>array('b'=>2,'a'=>1), 'previous_hash'=>'' );
$event_b = array( 'previous_hash'=>'', 'context'=>array('a'=>1,'b'=>2), 'purpose'=>'cookie_cross_device' );
dp_assert(
    Dendrila_Privacy_Consent_Core::event_hash( $event_a, $secret )
        === Dendrila_Privacy_Consent_Core::event_hash( $event_b, $secret ),
    'event hash must be stable across associative key order'
);

$errors = Dendrila_Privacy_Consent_Core::validate_email_evidence(
    'email_delivery',
    'recorded_exemption',
    'exempt_delivery',
    '',
    array()
);
dp_assert( count( $errors ) >= 4, 'deliverability exemption must reject incomplete evidence' );

$errors = Dendrila_Privacy_Consent_Core::validate_email_evidence(
    'email_delivery',
    'recorded_exemption',
    'exempt_delivery',
    '',
    array(
        'service_requested' => 'yes',
        'last_open_date_only' => 'yes',
        'inactive_list_cleanup' => 'yes',
        'exemption_note' => 'Technical review documented.',
    )
);
dp_assert( 0 === count( $errors ), 'complete deliverability exemption evidence should pass' );

$legacy = array(
    'collection_date' => '2026-01-10',
    'legacy_notice_date' => '2026-07-20',
    'legacy_conditions_unchanged' => 'yes',
);
$errors = Dendrila_Privacy_Consent_Core::validate_email_evidence(
    'email_open_measurement',
    'legacy_no_objection',
    'legacy_no_objection',
    'Clear notice with an easy objection mechanism.',
    $legacy
);
dp_assert( count( $errors ) === 1, 'late legacy notice must require an extension justification' );

$legacy['legacy_extension_note'] = 'Documented volume and deliverability constraints.';
$errors = Dendrila_Privacy_Consent_Core::validate_email_evidence(
    'email_open_measurement',
    'legacy_no_objection',
    'legacy_no_objection',
    'Clear notice with an easy objection mechanism.',
    $legacy
);
dp_assert( 0 === count( $errors ), 'documented reasonable legacy extension should pass the evidence gate' );

echo 'Dendrila consent core tests: OK' . PHP_EOL;
