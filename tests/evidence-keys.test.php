<?php
/** Run with php tests/evidence-keys.test.php (PHP 7.4+, no dependencies). */
define( 'ABSPATH', __DIR__ . '/fixtures/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {
    public $code; private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action() {}
function add_filter() {}
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
function add_option( $k, $v, $unused = '', $autoload = 'yes' ) {
    if ( isset( $GLOBALS['options'][ $k ] ) || $GLOBALS['fail_options'] ) { return false; }
    $GLOBALS['autoload'][ $k ] = $autoload; $GLOBALS['options'][ $k ] = $v; return true;
}
function update_option( $k, $v, $autoload = null ) { if ( $GLOBALS['fail_options'] ) { return false; } $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_cache_delete() {}
function sanitize_email( $s ) { return filter_var( $s, FILTER_SANITIZE_EMAIL ); }
function is_email( $s ) { return filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_textarea_field( $s ) { return sanitize_text_field( $s ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function current_time() { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 7; }
function is_user_logged_in() { return true; }
function is_admin() { return true; }
function absint( $n ) { return abs( (int) $n ); }
function get_user_by() { return false; }
function current_user_can() { return $GLOBALS['capability']; }
function check_admin_referer() { if ( ! $GLOBALS['nonce_valid'] ) { throw new RuntimeException( 'Bad nonce' ); } }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function esc_html__( $s, $domain ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_attr( $s ); }
function wp_unslash( $s ) { return $s; }
function wp_nonce_field() { echo '<input type="hidden" name="nonce" value="test">'; }
function admin_url( $s ) { return $s; }
function submit_button( $s ) { echo '<button>' . esc_html( $s ) . '</button>'; }
function do_action() {}
class EvidenceDB {
    public $prefix = 'wp_test_'; public $rows = array(); public $insert_id = 0;
    public $schema_ready = false; public $busy = false; public $held = false; public $delete_failure = false;
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        list( $sql, $a ) = $q;
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) { if ( $this->busy || $this->held ) { return 0; } $this->held = true; return 1; }
        if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) { $this->held = false; return 1; }
        if ( false !== strpos( $sql, 'SHOW COLUMNS' ) ) { return $this->schema_ready ? 'signing_key_id' : null; }
        if ( false !== strpos( $sql, 'SELECT event_hash' ) ) { $r = $this->get_row( $q ); return $r ? $r['event_hash'] : null; }
        throw new RuntimeException( 'Unhandled SQL: ' . $sql );
    }
    public function get_results( $q, $format = null ) {
        list( $sql, $a ) = $q; $rows = array_values( $this->rows );
        if ( false !== strpos( $sql, 'WHERE subject_hash = %s' ) ) { $rows = array_values( array_filter( $rows, function ( $r ) use ( $a ) { return $r['subject_hash'] === $a[1]; } ) ); }
        if ( false !== strpos( $sql, 'AND scope = %s' ) ) { $rows = array_values( array_filter( $rows, function ( $r ) use ( $a ) { return $r['scope'] === $a[2]; } ) ); }
        if ( false !== strpos( $sql, 'occurred_at < %s' ) || false !== strpos( $sql, 'occurred_at >= %s' ) ) {
            $cutoff = $a[ count( $a ) - ( false !== strpos( $sql, 'GROUP BY' ) ? 2 : 1 ) ]; $old = false !== strpos( $sql, 'occurred_at < %s' );
            $rows = array_values( array_filter( $rows, function ( $r ) use ( $cutoff, $old ) { return $old ? $r['occurred_at'] < $cutoff : $r['occurred_at'] >= $cutoff; } ) );
        }
        if ( false !== strpos( $sql, 'GROUP BY subject_hash, scope' ) ) {
            $groups = array(); foreach ( $rows as $r ) { $groups[ $r['subject_hash'] . $r['scope'] ] = array( 'subject_hash'=>$r['subject_hash'], 'scope'=>$r['scope'] ); } return array_values( $groups );
        }
        usort( $rows, function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
        if ( false !== strpos( $sql, 'ORDER BY id DESC' ) ) { $rows = array_reverse( $rows ); }
        if ( false !== strpos( $sql, 'LIMIT %d OFFSET %d' ) ) { $rows = array_slice( $rows, $a[3], $a[2] ); }
        if ( false !== strpos( $sql, 'LIMIT 1' ) ) { $rows = array_slice( $rows, 0, 1 ); }
        return $rows;
    }
    public function get_row( $q, $format = null ) { $rows = $this->get_results( $q ); return $rows ? $rows[0] : null; }
    public function get_col( $q ) {
        list( $sql, $a ) = $q; $field = false !== strpos( $sql, 'DISTINCT scope' ) ? 'scope' : 'subject_hash';
        return array_values( array_unique( array_column( $this->get_results( $q ), $field ) ) );
    }
    public function insert( $table, $row, $formats ) { check( count( $row ) === count( $formats ), 'insert format count' ); $row['id'] = ++$this->insert_id; $this->rows[ $row['id'] ] = $row; return 1; }
    public function delete( $table, $where, $formats ) {
        if ( $this->delete_failure ) { return false; }
        $count = 0; foreach ( $this->rows as $id => $r ) { if ( $r['subject_hash'] === $where['subject_hash'] ) { unset( $this->rows[$id] ); $count++; } } return $count;
    }
    public function query( $q ) { $rows = $this->get_results( $q ); foreach ( $rows as $r ) { unset( $this->rows[$r['id']] ); } return count( $rows ); }
}
$checks = 0;
function check( $ok, $message ) { global $checks; $checks++; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function reset_fixture() {
    $GLOBALS['options'] = array(); $GLOBALS['autoload'] = array(); $GLOBALS['fail_options'] = false;
    $GLOBALS['schema_calls'] = 0; $GLOBALS['schema_failure'] = false; $GLOBALS['capability'] = true; $GLOBALS['nonce_valid'] = true;
    $GLOBALS['wpdb'] = new EvidenceDB(); $_POST = array();
}
reset_fixture();
require dirname( __DIR__ ) . '/includes/class-dendrila-privacy-evidence-ledger.php';
$ledger = Dendrila_Privacy_Evidence_Ledger::instance();
function invoke_private( $method, ...$args ) { global $ledger; $r = new ReflectionMethod( $ledger, $method ); $r->setAccessible( true ); return $r->invokeArgs( $ledger, $args ); }
function ring() { return get_option( Dendrila_Privacy_Evidence_Ledger::OPTION_KEYRING ); }
function rotate() { return invoke_private( 'rotate_signing_key', ring()['active'] ); }
function record( $email = 'alice@example.test', $context = array() ) { global $ledger; $id = $ledger->record_event( $email, 'email_tracking', 'granted', array( 'opens' ), $context ); check( is_int( $id ), 'record succeeded' ); return $id; }
function historical( $version ) {
    global $wpdb;
    $legacy = str_repeat( 'L', 64 );
    $GLOBALS['options'][ Dendrila_Privacy_Evidence_Ledger::OPTION_CHAIN_KEY ] = $legacy;
    $GLOBALS['options'][ Dendrila_Privacy_Evidence_Ledger::OPTION_DB_VERSION ] = '2';
    // Independent pre-migration payload in the original v1/v2 field order.
    $row = array( 'subject_hash'=>hash_hmac('sha256','alice@example.test',$legacy), 'scope'=>'email_tracking', 'decision'=>'granted', 'purposes'=>array('opens'), 'notice_version'=>'old', 'source'=>'manual', 'evidence_ref'=>'fixture', 'occurred_at'=>'2020-01-01 00:00:00', 'recorded_at'=>'2020-01-01 00:00:00', 'actor_user_id'=>7, 'previous_hash'=>'', 'context'=>array() );
    if ( 2 === $version ) { $row['event_version']=2; $row['notice_hash']=hash('sha256','Historical notice'); }
    $hash = hash_hmac('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$legacy);
    $row['event_version']=$version; $row['notice_hash']=2===$version?hash('sha256','Historical notice'):''; $row['notice_snapshot']=2===$version?'Historical notice':'';
    $row['purposes']=json_encode($row['purposes']); $row['context_json']=json_encode($row['context']); unset($row['context']); $row['event_hash']=$hash; $row['id']=++$wpdb->insert_id; $wpdb->rows[$row['id']]=$row;
    $GLOBALS['options'][ invoke_private('head_option_name',$row['subject_hash'],$row['scope']) ]=$hash;
    return $row;
}
foreach ( array( 1, 2 ) as $version ) {
    reset_fixture(); $old = historical( $version );
    check( ! is_wp_error( $ledger->maybe_install() ), 'migration succeeds' );
    $first = ring(); check( $first['identity_key'] === str_repeat('L',64) && $first['legacy_key'] === str_repeat('L',64), 'historical identity and verifier preserved' );
    check( invoke_private('subject_hash',' ALICE@example.test ') === $old['subject_hash'], 'subject hash unchanged' );
    check( $wpdb->rows[1] === $old, 'migration did not rewrite historical row' );
    check( $ledger->verify_chain_for_email('alice@example.test'), 'legacy signature verified' );
    $ledger->maybe_install(); check( ring() === $first && 1 === $schema_calls, 'migration idempotent' );
    $a=record(); $key1=ring()['active']; check( 3===$wpdb->rows[$a]['event_version'] && $key1===$wpdb->rows[$a]['signing_key_id'], 'v3 references active key' );
    $before=$wpdb->rows; $key2=rotate(); check( is_string($key2) && $key2!==$key1, 'rotation changes active key' );
    check( $wpdb->rows===$before && ring()['keys'][$key1]['status']==='retired', 'rotation preserves events and retires K1' );
    $b=record(); check( $wpdb->rows[$b]['signing_key_id']===$key2, 'retired K1 cannot sign new event' );
    check( $ledger->verify_chain_for_email('alice@example.test'), 'legacy + K1 + K2 chain verifies' );
    check( invoke_private('subject_hash','alice@example.test')===$old['subject_hash'], 'rotation preserves identity' );
    $saved=$wpdb->rows[$b]; $wpdb->rows[$b]['signing_key_id']=$key1; check(!$ledger->verify_chain_for_email('alice@example.test'),'key id substitution detected');
    $wpdb->rows[$b]=$saved; $wpdb->rows[$b]['signing_key_id']='unknown'; check(!$ledger->verify_chain_for_email('alice@example.test'),'unknown key rejected');
    $wpdb->rows[$b]=$saved; $wpdb->rows[$b]['event_version']=4; check(!$ledger->verify_chain_for_email('alice@example.test'),'unsupported version rejected'); $wpdb->rows[$b]=$saved;
    $clean=invoke_private('cleanup_retention',true); check(1===$clean['removed'],'retention removes legacy prefix');
    $anchor=invoke_private('retention_anchor_option_name',$old['subject_hash'],$old['scope']); check(get_option($anchor)===$old['event_hash'],'legacy retention anchor preserved');
    rotate(); record(); check($ledger->verify_chain_for_email('alice@example.test'),'pre-rotation retention anchor still verifies');
    $other=record('bob@example.test'); $keys=ring(); $erased=$ledger->privacy_eraser('alice@example.test');
    check($erased['items_removed'] && !get_option($anchor) && !get_option(invoke_private('head_option_name',$old['subject_hash'],$old['scope'])),'erasure removes rows heads and anchors');
    check(ring()===$keys && isset($wpdb->rows[$other]) && $ledger->verify_chain_for_email('bob@example.test'),'erasure preserves global keys and other subject');
    check(false===strpos(json_encode(array($options,$wpdb->rows)),'alice@example.test'),'no clear email persisted');
}
reset_fixture(); record(); $fresh=ring();
check(''===$fresh['legacy_key'] && $fresh['identity_key']!==$fresh['keys'][$fresh['active']]['material'],'fresh install has distinct keys');
check('no'===$autoload[Dendrila_Privacy_Evidence_Ledger::OPTION_KEYRING],'secrets not autoloaded');
$wpdb->busy=true; $before=ring(); check(is_wp_error(rotate()) && ring()===$before,'busy rotation fails without mutation');
check(is_wp_error($ledger->record_event('alice@example.test','email_tracking','granted',array())),'busy append fails closed'); $wpdb->busy=false;
$fail_options=true; check(is_wp_error(rotate()) && ring()===$before,'failed rotation leaves keyring intact'); $fail_options=false;
$old_id=ring()['active']; rotate(); $after=ring(); check(is_wp_error(invoke_private('rotate_signing_key',$old_id)) && ring()===$after,'stale form and repeat submit do not rotate twice');
$missing=ring(); unset($missing['keys'][$old_id]); $options[Dendrila_Privacy_Evidence_Ledger::OPTION_KEYRING]=$missing;
check(!$ledger->verify_chain_for_email('alice@example.test'),'missing retired verification key fails closed');
$options[Dendrila_Privacy_Evidence_Ledger::OPTION_KEYRING]=$after;
$bad=$after; $bad['keys'][$bad['active']]['status']='retired'; $options[Dendrila_Privacy_Evidence_Ledger::OPTION_KEYRING]=$bad;
check(is_wp_error($ledger->record_event('alice@example.test','email_tracking','granted',array())),'retired active pointer cannot sign');
unset($options[Dendrila_Privacy_Evidence_Ledger::OPTION_KEYRING]); check(is_wp_error($ledger->maybe_install()),'missing v3 keyring never regenerated');
reset_fixture(); historical(2); unset($options[Dendrila_Privacy_Evidence_Ledger::OPTION_CHAIN_KEY]); check(is_wp_error($ledger->maybe_install()),'missing legacy key never regenerated');
reset_fixture(); historical(2); $schema_failure=true; check(is_wp_error($ledger->maybe_install()),'failed schema detected'); $partial=ring();
check('2'===get_option(Dendrila_Privacy_Evidence_Ledger::OPTION_DB_VERSION),'schema failure leaves version unchanged');
$schema_failure=false; $ledger->maybe_install(); check(ring()===$partial && '3'===get_option(Dendrila_Privacy_Evidence_Ledger::OPTION_DB_VERSION),'interrupted migration reuses same keys');
reset_fixture(); record(); $keys=ring(); $wpdb->delete_failure=true; $result=$ledger->privacy_eraser('alice@example.test');
check($result['items_retained'] && !$result['done'] && $ledger->verify_chain_for_email('alice@example.test'),'failed erase retains anchors for retry');
$wpdb->delete_failure=false;
$result=$ledger->privacy_eraser('alice@example.test',2); check($result['items_removed'] && $result['done'], 'erasure retry works on next WordPress page');
record();
// Scope after first page: no orphan heads/anchors from a >500-row erasure.
for($i=0;$i<501;$i++){ $row=reset($wpdb->rows);$row['id']=++$wpdb->insert_id;$wpdb->rows[$row['id']]=$row; }
$subject=reset($wpdb->rows)['subject_hash'];$id=$ledger->record_event('alice@example.test','late_scope','denied',array());
$late_head=invoke_private('head_option_name',$subject,'late_scope');$late_anchor=invoke_private('retention_anchor_option_name',$subject,'late_scope');$options[$late_anchor]='anchor';
$result=$ledger->privacy_eraser('alice@example.test'); check($result['items_removed'] && !get_option($late_head) && !get_option($late_anchor) && ring()===$keys,'all scopes erased beyond first 500 rows');
// Exercise real administrator POST routing, capability, nonce and server-side confirmation.
reset_fixture(); record(); $before=ring();
$_POST=array('dendrila_privacy_evidence_action'=>'rotate_signing_key','evidence_active_key'=>$before['active']);
ob_start();$ledger->render_admin_page();$html=ob_get_clean(); check(ring()===$before && false!==strpos($html,'Confirmez la rotation'),'confirmation required server-side');
$_POST['evidence_rotate_confirm']='1';$capability=false;
try{$ledger->render_admin_page();throw new RuntimeException('capability bypass');}catch(RuntimeException $e){check('Accès refusé.'===$e->getMessage(),'capability required');}
$capability=true;$nonce_valid=false;
try{$ledger->render_admin_page();throw new RuntimeException('nonce bypass');}catch(RuntimeException $e){check('Bad nonce'===$e->getMessage(),'nonce required');}
$nonce_valid=true;ob_start();$ledger->render_admin_page();$html=ob_get_clean();check(ring()['active']!==$before['active'],'authorized confirmed POST rotates');
check(false!==strpos($html,'role="status"') && false!==strpos($html,'evidence_rotate_confirm'),'accessible feedback and explicit label');
foreach(ring()['keys'] as $k){check(false===strpos($html,$k['material']),'secret never rendered');}
check(false===strpos($html,ring()['identity_key']),'identity secret never rendered');
$log=get_option(Dendrila_Privacy_Evidence_Ledger::OPTION_ADMIN_LOG);check('signing_key_rotated'===$log[0]['action'],'rotation audited');
check(!$wpdb->held,'lock released');
$wpdb->busy=true; $busy_cleanup=invoke_private('cleanup_retention',true);check(isset($busy_cleanup['error']),'busy cleanup reports retryable error'); $wpdb->busy=false;
$render_html=$html;
unset($options[Dendrila_Privacy_Evidence_Ledger::OPTION_KEYRING]);
$integrity=invoke_private('verify_all_chains');check(!$integrity['verified'] && isset($integrity['error']),'missing keys never reported as verified empty ledger');
foreach(array('export_evidence','export_evidence_csv') as $method){
    try{$ledger->$method();throw new RuntimeException('Exported unavailable ledger');}catch(RuntimeException $error){check(false!==strpos($error->getMessage(),'Clés du registre indisponibles'),'unavailable export rejected');}
}
$html=$render_html;
if(getenv('DENDRILA_TEST_RENDER')){file_put_contents(getenv('DENDRILA_TEST_RENDER'),$html);}
echo "Evidence key regression: {$checks} checks passed.\n";
