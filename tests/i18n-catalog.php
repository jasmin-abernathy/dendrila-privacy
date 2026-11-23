<?php
/**
 * Test the bundled MO catalog with no WordPress installation required.
 */
$root = dirname( __DIR__ );
$po = file_get_contents( $root . '/languages/dendrila-privacy-fr_FR.po' );
$mo = file_get_contents( $root . '/languages/dendrila-privacy-fr_FR.mo' );
function assert_i18n( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: $reason\n" ); exit( 1 ); }
}
assert_i18n( is_string( $po ) && false !== strpos( $po, 'msgid "Reject all"' ), 'PO catalog exists' );
assert_i18n( is_string( $mo ) && strlen( $mo ) > 32, 'MO catalog exists' );
assert_i18n( 0x950412de === unpack( 'V', substr( $mo, 0, 4 ) )[1], 'little-endian gettext header' );
$count = unpack( 'V', substr( $mo, 8, 4 ) )[1];
$offset_original = unpack( 'V', substr( $mo, 12, 4 ) )[1];
$offset_translated = unpack( 'V', substr( $mo, 16, 4 ) )[1];
assert_i18n( $count > 110, 'sufficient catalog entries' );
$catalog = array();
for ( $i = 0; $i < $count; ++$i ) {
    $o = unpack( 'Vlen/Vstart', substr( $mo, $offset_original + $i * 8, 8 ) );
    $t = unpack( 'Vlen/Vstart', substr( $mo, $offset_translated + $i * 8, 8 ) );
    assert_i18n( $o['start'] + $o['len'] < strlen( $mo ) && $t['start'] + $t['len'] < strlen( $mo ), 'bounds valid' );
    $catalog[ substr( $mo, $o['start'], $o['len'] ) ] = substr( $mo, $t['start'], $t['len'] );
}
assert_i18n( 'Tout refuser' === $catalog['Reject all'], 'French visitor consent' );
assert_i18n( 'Pages légales' === $catalog['Legal pages'], 'French legal navigation' );
assert_i18n( 'Enregistrer les réglages' === $catalog['Save settings'], 'French settings action' );
assert_i18n( 'Réglages' === $catalog['Settings'], 'French admin navigation' );
assert_i18n( 'Gérer mes choix' === $catalog['Manage my choices'], 'French preference link' );
assert_i18n( 'Vue d’ensemble' === $catalog['Overview'], 'French dashboard navigation' );
assert_i18n( 'Désactivée' === $catalog['Disabled'], 'French age status' );
assert_i18n( false !== strpos( file_get_contents( $root . '/dendrila-privacy.php' ), 'load_plugin_textdomain(' ), 'translation loader registered' );
// Every gettext literal in production PHP should have a matching bundled translation.
foreach ( array( $root . '/dendrila-privacy.php', $root . '/includes/class-pixel-trackers-manager-consent.php', $root . '/includes/class-dendrila-privacy-age-assurance.php', $root . '/includes/class-pixel-trackers-manager-elementor-widget.php' ) as $filename ) {
    $contents = file_get_contents( $filename );
    preg_match_all( "/\\b(?:__|esc_html__|esc_attr__)\\(\\s*'((?:\\\\'|[^'])*)'\\s*,\\s*'dendrila-privacy'/", $contents, $matches );
    foreach ( $matches[1] as $original ) {
        $original = str_replace( "\\\\'", "'", $original );
        assert_i18n( isset( $catalog[ $original ] ), 'missing French translation for ' . $original );
    }
}
echo "I18n catalog regression: PASS\n";
