<?php
// Standalone schema double: production still calls WordPress dbDelta.
function dbDelta( $sql ) {
    $GLOBALS['schema_calls']++;
    if ( false === strpos( $sql, "signing_key_id varchar(32) NOT NULL DEFAULT ''" ) ) { throw new RuntimeException( 'Missing v3 column' ); }
    $GLOBALS['wpdb']->schema_ready = ! $GLOBALS['schema_failure'];
}
