<?php
/** Run only on a disposable WordPress test installation via wp eval-file. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
    return;
}
function dp_check( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}
dp_check( function_exists( 'acf_add_local_field_group' ) && function_exists( 'tribe_get_option' ) && shortcode_exists( 'cmplz-document' ), 'Required plugins missing' );
$plugin = Pixel_Trackers_Manager_Plugin::instance();
$reflection = new ReflectionClass( $plugin );
$invoke = static function ( $name, ...$args ) use ( $reflection, $plugin ) {
    $method = $reflection->getMethod( $name );
    $method->setAccessible( true );
    return $method->invokeArgs( $plugin, $args );
};
$ids = array();
try {
    foreach ( array(
        'privacy' => '[cmplz-document type="privacy-statement" region="eu"]',
        'cookies' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:complianz/document {"selectedDocument":"cookie-statement-uk"} /--></div><!-- /wp:group -->',
        'legal_notice' => '[cmplz-document type="impressum" region="eu"]',
    ) as $kind => $content ) {
        $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'DP fixture ' . wp_generate_uuid4(), 'post_content' => $content ), true );
        dp_check( ! is_wp_error( $id ) && $id > 0, 'fixture insert' );
        $ids[] = $id;
        $result = $invoke( 'create_legal_page', $kind );
        dp_check( is_wp_error( $result ) && 'pixel_trackers_manager_existing_legal_page' === $result->get_error_code(), 'Duplicate prevention for ' . $kind );
        $matches = array_column( $invoke( 'legal_page_candidates', $kind ), 'page_id' );
        dp_check( in_array( $id, $matches, true ), 'Complianz document is selectable for ' . $kind );
    }
    dp_check( ! $invoke( 'has_complianz_document', '[[cmplz-document type="cookie-statement"]]', 'cookies' ), 'Escaped shortcode is inert' );
    dp_check( ! $invoke( 'has_complianz_document', '[cmplz-document type="privacy-statement"]', 'cookies' ), 'Different document type stays separate' );
    $scan = array( 'plugins' => array(
        array( 'slug' => 'advanced-custom-fields', 'active' => true ),
        array( 'slug' => 'the-events-calendar', 'active' => true ),
    ) );
    $acf = $invoke( 'acf_privacy_configuration', $scan );
    dp_check( $acf['readable'] && function_exists( 'acf_form' ), 'ACF settings and forms API' );
    $events = $invoke( 'events_calendar_privacy_configuration', $scan );
    dp_check( $events['settings_readable'] && post_type_exists( 'tribe_events' ), 'Events settings and post type' );
    $event_id = wp_insert_post( array( 'post_type' => 'tribe_events', 'post_status' => 'publish', 'post_title' => 'DP event fixture' ), true );
    dp_check( ! is_wp_error( $event_id ) && $event_id > 0, 'Event fixture insert' );
    $ids[] = $event_id;
    dp_check( 'tribe_events' === get_post_type( $event_id ), 'Event persisted' );
    register_post_type( 'dp_audit_fixture', array( 'public' => true, 'show_in_rest' => true ) );
    acf_add_local_field_group( array(
        'key' => 'group_dp_audit_fixture', 'title' => 'DP audit fields', 'show_in_rest' => true,
        'fields' => array( array( 'key' => 'field_dp_audit_note', 'name' => 'dp_audit_note', 'label' => 'Note', 'type' => 'text' ) ),
        'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'dp_audit_fixture' ) ) ),
    ) );
    foreach ( array( 'publish', 'private' ) as $status ) {
        $post_id = wp_insert_post( array( 'post_type' => 'dp_audit_fixture', 'post_status' => $status, 'post_title' => 'DP REST fixture' ), true );
        dp_check( ! is_wp_error( $post_id ) && $post_id > 0, 'REST fixture insert' );
        $ids[] = $post_id;
        update_field( 'field_dp_audit_note', 'audit-fixture', $post_id );
        wp_set_current_user( 0 );
        $path = '/wp/v2/dp_audit_fixture/' . $post_id;
        $response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
        if ( 'publish' === $status ) {
            dp_check( 200 === $response->get_status() && 'audit-fixture' === ( $response->get_data()['acf']['dp_audit_note'] ?? '' ), 'Public ACF field is exposed as configured' );
        } else {
            dp_check( in_array( $response->get_status(), array( 401, 403, 404 ), true ), 'Anonymous request cannot read private ACF content' );
            wp_set_current_user( 1 );
            $response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
            dp_check( 200 === $response->get_status() && 'audit-fixture' === ( $response->get_data()['acf']['dp_audit_note'] ?? '' ), 'Administrator can read private ACF content' );
        }
    }
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    $_GET = array( 'page' => 'pixel-trackers-manager-setup' );
    ob_start();
    $plugin->render_admin();
    $admin = ob_get_clean();
    dp_check( false !== strpos( $admin, 'data-ptm-setup-step="welcome"' ), 'Administrator can render the setup screen' );
    echo "PASS WordPress, ACF, Events Calendar and Complianz coexist; existing legal pages are reused; event and REST permission smoke checks.\n";
} finally {
    foreach ( $ids as $id ) {
        wp_delete_post( $id, true );
    }
}
