<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Privacy_Evidence_Ledger {
    const DB_VERSION = '2';
    const OPTION_DB_VERSION = 'dendrila_privacy_evidence_db_version';
    const OPTION_CHAIN_KEY = 'dendrila_privacy_evidence_chain_key';
    const OPTION_HEAD_PREFIX = 'dendrila_privacy_evidence_head_';
    const OPTION_RETENTION_PREFIX = 'dendrila_privacy_evidence_retention_anchor_';
    const OPTION_RETENTION_DAYS = 'dendrila_privacy_evidence_retention_days';
    const OPTION_LAST_CLEANUP = 'dendrila_privacy_evidence_last_cleanup';
    const OPTION_LAST_INTEGRITY = 'dendrila_privacy_evidence_last_integrity';
    const OPTION_ADMIN_LOG = 'dendrila_privacy_evidence_admin_log';
    private static $instance = null;

    public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'maybe_install' ), 5 );
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
        add_action( 'admin_post_dendrila_privacy_export_evidence', array( $this, 'export_evidence' ) );
        add_action( 'admin_post_dendrila_privacy_export_evidence_csv', array( $this, 'export_evidence_csv' ) );
        add_action( 'admin_init', array( $this, 'maybe_retention_cleanup' ), 25 );
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
        add_action( 'dendrila_privacy_record_email_tracking_consent', array( $this, 'action_record_email_tracking_consent' ), 10, 4 );
        add_filter( 'dendrila_privacy_email_tracking_consent_status', array( $this, 'filter_email_tracking_status' ), 10, 2 );
    }

    private function table_name() { global $wpdb; return $wpdb->prefix . 'dendrila_privacy_evidence'; }

    private function chain_key() {
        $key = get_option( self::OPTION_CHAIN_KEY, '' );
        if ( is_string( $key ) && strlen( $key ) >= 32 ) { return $key; }
        $generated = wp_generate_password( 64, true, true );
        add_option( self::OPTION_CHAIN_KEY, $generated, '', 'no' );
        $stored = get_option( self::OPTION_CHAIN_KEY, $generated );
        return is_string( $stored ) && '' !== $stored ? $stored : $generated;
    }

    public function maybe_install() {
        if ( self::DB_VERSION === (string) get_option( self::OPTION_DB_VERSION, '' ) ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subject_hash char(64) NOT NULL,
            scope varchar(64) NOT NULL,
            decision varchar(20) NOT NULL,
            purposes longtext NOT NULL,
            notice_version varchar(191) NOT NULL DEFAULT '',
            source varchar(100) NOT NULL DEFAULT '',
            evidence_ref varchar(191) NOT NULL DEFAULT '',
            occurred_at datetime NOT NULL,
            recorded_at datetime NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_version tinyint(3) unsigned NOT NULL DEFAULT 1,
            notice_hash char(64) NOT NULL DEFAULT '',
            notice_snapshot longtext NULL,
            previous_hash char(64) NOT NULL DEFAULT '',
            event_hash char(64) NOT NULL,
            context_json longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY subject_scope (subject_hash, scope),
            KEY occurred_at (occurred_at)
        ) {$charset};";
        dbDelta( $sql );
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
        $this->chain_key();
    }

    private function normalise_email( $email ) { $email = sanitize_email( strtolower( trim( (string) $email ) ) ); return is_email( $email ) ? $email : ''; }
    private function subject_hash( $email ) { $email = $this->normalise_email( $email ); return '' === $email ? '' : hash_hmac( 'sha256', $email, $this->chain_key() ); }
    private function head_option_name( $subject_hash, $scope ) { return self::OPTION_HEAD_PREFIX . hash( 'sha256', (string) $subject_hash . '|' . sanitize_key( (string) $scope ) ); }
    private function retention_anchor_option_name( $subject_hash, $scope ) { return self::OPTION_RETENTION_PREFIX . hash( 'sha256', (string) $subject_hash . '|' . sanitize_key( (string) $scope ) ); }

    private function retention_days() {
        $days = absint( get_option( self::OPTION_RETENTION_DAYS, 730 ) );
        return max( 30, min( 3650, $days ? $days : 730 ) );
    }

    private function log_admin_action( $action, $details = array() ) {
        if ( ! is_user_logged_in() ) { return; }
        $clean = array();
        foreach ( (array) $details as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( '' !== $key && is_scalar( $value ) ) { $clean[ $key ] = sanitize_text_field( (string) $value ); }
        }
        $entries = get_option( self::OPTION_ADMIN_LOG, array() );
        $entries = is_array( $entries ) ? $entries : array();
        array_unshift( $entries, array( 'occurred_at'=>gmdate('c'), 'actor_user_id'=>get_current_user_id(), 'action'=>sanitize_key((string)$action), 'details'=>$clean ) );
        update_option( self::OPTION_ADMIN_LOG, array_slice( $entries, 0, 100 ), false );
    }

    private function admin_log_entries() {
        $entries = get_option( self::OPTION_ADMIN_LOG, array() );
        return is_array( $entries ) ? array_slice( $entries, 0, 12 ) : array();
    }

    public function maybe_retention_cleanup() {
        if ( ! is_admin() ) { return; }
        $last = absint( get_option( self::OPTION_LAST_CLEANUP, 0 ) );
        if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) { return; }
        $this->cleanup_retention( false );
    }

    private function cleanup_retention( $force = false ) {
        $this->maybe_install();
        global $wpdb; $table = $this->table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $this->retention_days() * DAY_IN_SECONDS ) );
        $limit = $force ? 1000 : 250;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention maintenance must inspect plugin-owned evidence rows directly.
        $groups = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT subject_hash, scope FROM %i WHERE occurred_at < %s GROUP BY subject_hash, scope ORDER BY MIN(id) ASC LIMIT %d', $table, $cutoff, $limit ), ARRAY_A );
        $removed = 0; $skipped = 0;
        foreach ( $groups as $group ) {
            $subject_hash = isset($group['subject_hash']) ? (string)$group['subject_hash'] : '';
            $scope = isset($group['scope']) ? sanitize_key((string)$group['scope']) : '';
            if ( '' === $subject_hash || '' === $scope ) { continue; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The retention anchor preserves the hash-chain boundary before old rows are removed.
            $last_old = $wpdb->get_row( $wpdb->prepare( 'SELECT id, event_hash FROM %i WHERE subject_hash = %s AND scope = %s AND occurred_at < %s ORDER BY id DESC LIMIT 1', $table, $subject_hash, $scope, $cutoff ), ARRAY_A );
            if ( ! is_array( $last_old ) || empty( $last_old['event_hash'] ) ) { continue; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above: immediate retained boundary is required before deletion.
            $first_kept = $wpdb->get_row( $wpdb->prepare( 'SELECT id, previous_hash FROM %i WHERE subject_hash = %s AND scope = %s AND occurred_at >= %s ORDER BY id ASC LIMIT 1', $table, $subject_hash, $scope, $cutoff ), ARRAY_A );
            if ( is_array( $first_kept ) && ! hash_equals( (string)$last_old['event_hash'], (string)$first_kept['previous_hash'] ) ) { $skipped++; continue; }
            if ( is_array( $first_kept ) ) { update_option( $this->retention_anchor_option_name( $subject_hash, $scope ), (string)$last_old['event_hash'], false ); }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Retention deletes only expired rows from this plugin-owned table.
            $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subject_hash = %s AND scope = %s AND occurred_at < %s', $table, $subject_hash, $scope, $cutoff ) );
            if ( false !== $deleted ) { $removed += (int)$deleted; }
            if ( ! is_array( $first_kept ) ) {
                delete_option( $this->head_option_name( $subject_hash, $scope ) );
                delete_option( $this->retention_anchor_option_name( $subject_hash, $scope ) );
            }
        }
        update_option( self::OPTION_LAST_CLEANUP, time(), false );
        return array( 'removed'=>$removed, 'skipped'=>$skipped, 'groups'=>count($groups), 'cutoff'=>$cutoff );
    }

    private function normalise_purposes( $purposes ) {
        if ( is_string( $purposes ) ) { $purposes = preg_split( '/[,\r\n]+/', $purposes ); }
        $clean = array();
        foreach ( (array) $purposes as $purpose ) { $purpose = sanitize_text_field( (string) $purpose ); if ( '' !== $purpose ) { $clean[] = $purpose; } }
        $clean = array_values( array_unique( $clean ) ); sort( $clean, SORT_STRING ); return $clean;
    }

    private function sanitise_context( $context ) {
        $context = is_array( $context ) ? $context : array(); $clean = array();
        foreach ( array( 'method', 'form_id', 'campaign_id', 'plugin', 'legal_basis_note', 'legacy_collection' ) as $key ) {
            if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) { $clean[ $key ] = sanitize_text_field( (string) $context[ $key ] ); }
        }
        if ( isset( $context['metadata'] ) && is_array( $context['metadata'] ) ) {
            $metadata = array();
            foreach ( $context['metadata'] as $key => $value ) { $key = sanitize_key( (string) $key ); if ( '' !== $key && is_scalar( $value ) ) { $metadata[ $key ] = sanitize_text_field( (string) $value ); } }
            ksort( $metadata ); $clean['metadata'] = $metadata;
        }
        ksort( $clean ); return $clean;
    }

    private function canonical_payload( $row ) {
        $payload = array(
            'subject_hash'=>(string)$row['subject_hash'], 'scope'=>(string)$row['scope'], 'decision'=>(string)$row['decision'], 'purposes'=>array_values((array)$row['purposes']),
            'notice_version'=>(string)$row['notice_version'], 'source'=>(string)$row['source'], 'evidence_ref'=>(string)$row['evidence_ref'], 'occurred_at'=>(string)$row['occurred_at'],
            'recorded_at'=>(string)$row['recorded_at'], 'actor_user_id'=>(int)$row['actor_user_id'], 'previous_hash'=>(string)$row['previous_hash'], 'context'=>is_array($row['context'])?$row['context']:array(),
        );
        if ( isset( $row['event_version'] ) && (int) $row['event_version'] >= 2 ) { $payload['event_version']=(int)$row['event_version']; $payload['notice_hash']=isset($row['notice_hash'])?(string)$row['notice_hash']:''; }
        return wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    public function record_event( $email, $scope, $decision, $purposes, $context = array() ) {
        $this->maybe_install(); $subject_hash = $this->subject_hash( $email );
        if ( '' === $subject_hash ) { return new WP_Error( 'dendrila_privacy_evidence_email', 'Adresse e-mail invalide.' ); }
        $scope = sanitize_key( (string) $scope ); if ( '' === $scope ) { $scope = 'email_tracking'; }
        $decision = sanitize_key( (string) $decision );
        if ( ! in_array( $decision, array( 'granted', 'partial', 'denied', 'withdrawn' ), true ) ) { return new WP_Error( 'dendrila_privacy_evidence_decision', 'Décision de consentement invalide.' ); }
        $purposes = $this->normalise_purposes( $purposes ); $context = is_array( $context ) ? $context : array();
        $notice_version = isset( $context['notice_version'] ) ? sanitize_text_field( (string) $context['notice_version'] ) : '';
        $notice_snapshot = isset( $context['notice_text'] ) ? sanitize_textarea_field( (string) $context['notice_text'] ) : '';
        $notice_hash = '' !== $notice_snapshot ? hash( 'sha256', $notice_snapshot ) : '';
        $source = isset( $context['source'] ) ? sanitize_key( (string) $context['source'] ) : '';
        $evidence_ref = isset( $context['evidence_ref'] ) ? sanitize_text_field( (string) $context['evidence_ref'] ) : '';
        $occurred_at = current_time( 'mysql', true );
        if ( ! empty( $context['occurred_at'] ) ) { $timestamp = strtotime( (string) $context['occurred_at'] ); if ( false !== $timestamp ) { $occurred_at = gmdate( 'Y-m-d H:i:s', $timestamp ); } }
        $clean_context = $this->sanitise_context( $context );
        global $wpdb; $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin-owned append-only ledger must read the immediately previous hash before writing the next event.
        $previous_hash = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT event_hash FROM %i WHERE subject_hash = %s AND scope = %s ORDER BY id DESC LIMIT 1', $table, $subject_hash, $scope ) );
        $recorded_at = current_time( 'mysql', true );
        $row = array( 'subject_hash'=>$subject_hash, 'scope'=>$scope, 'decision'=>$decision, 'purposes'=>$purposes, 'notice_version'=>$notice_version, 'source'=>$source, 'evidence_ref'=>$evidence_ref, 'occurred_at'=>$occurred_at, 'recorded_at'=>$recorded_at, 'actor_user_id'=>get_current_user_id(), 'event_version'=>2, 'notice_hash'=>$notice_hash, 'previous_hash'=>$previous_hash, 'context'=>$clean_context );
        $event_hash = hash_hmac( 'sha256', $this->canonical_payload( $row ), $this->chain_key() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Dedicated plugin-owned evidence table; there is no WordPress CRUD API for this ledger.
        $inserted = $wpdb->insert( $table, array( 'subject_hash'=>$subject_hash, 'scope'=>$scope, 'decision'=>$decision, 'purposes'=>wp_json_encode($purposes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), 'notice_version'=>$notice_version, 'source'=>$source, 'evidence_ref'=>$evidence_ref, 'occurred_at'=>$occurred_at, 'recorded_at'=>$recorded_at, 'actor_user_id'=>get_current_user_id(), 'event_version'=>2, 'notice_hash'=>$notice_hash, 'notice_snapshot'=>$notice_snapshot, 'previous_hash'=>$previous_hash, 'event_hash'=>$event_hash, 'context_json'=>wp_json_encode($clean_context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ), array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s' ) );
        if ( false === $inserted ) { return new WP_Error( 'dendrila_privacy_evidence_insert', 'Impossible d’enregistrer la preuve locale.' ); }
        update_option( $this->head_option_name( $subject_hash, $scope ), $event_hash, false );
        return (int) $wpdb->insert_id;
    }

    public function record_email_tracking_consent( $email, $decision, $purposes, $context = array() ) { return $this->record_event( $email, 'email_tracking', $decision, $purposes, $context ); }
    public function action_record_email_tracking_consent( $email, $decision, $purposes, $context = array() ) { $this->record_email_tracking_consent( $email, $decision, $purposes, $context ); }

    private function rows_for_email( $email, $limit = 500, $offset = 0 ) {
        $this->maybe_install(); $subject_hash = $this->subject_hash( $email ); if ( '' === $subject_hash ) { return array(); }
        global $wpdb; $table = $this->table_name(); $limit=max(1,min(500,absint($limit))); $offset=max(0,absint($offset));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Evidence lookups must reflect the current append-only ledger immediately.
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE subject_hash = %s ORDER BY id ASC LIMIT %d OFFSET %d', $table, $subject_hash, $limit, $offset ), ARRAY_A );
    }

    private function decoded_row( $row ) { $row['purposes']=json_decode((string)$row['purposes'],true);$row['purposes']=is_array($row['purposes'])?$row['purposes']:array();$row['context']=json_decode((string)$row['context_json'],true);$row['context']=is_array($row['context'])?$row['context']:array();$row['event_version']=isset($row['event_version'])?(int)$row['event_version']:1;$row['notice_hash']=isset($row['notice_hash'])?(string)$row['notice_hash']:'';$row['notice_snapshot']=isset($row['notice_snapshot'])?(string)$row['notice_snapshot']:'';return $row; }

    public function latest_for_email( $email, $scope = 'email_tracking' ) {
        $this->maybe_install(); $subject_hash=$this->subject_hash($email); if(''===$subject_hash){return null;} global $wpdb; $table=$this->table_name(); $scope=sanitize_key((string)$scope);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Consent status must reflect the latest ledger event without stale cache state.
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE subject_hash = %s AND scope = %s ORDER BY id DESC LIMIT 1',$table,$subject_hash,$scope),ARRAY_A);
        return is_array($row)?$this->decoded_row($row):null;
    }

    private function verify_subject_hash( $subject_hash ) {
        $subject_hash = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $subject_hash ) );
        if ( 64 !== strlen( $subject_hash ) ) { return false; }
        global $wpdb; $table = $this->table_name(); $offset = 0; $limit = 500; $previous_by_scope = array(); $seen = false;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integrity checks must read the complete current ledger in deterministic order.
            $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE subject_hash = %s ORDER BY id ASC LIMIT %d OFFSET %d', $table, $subject_hash, $limit, $offset ), ARRAY_A );
            foreach ( $rows as $stored ) {
                $seen = true; $row = $this->decoded_row( $stored ); $scope = (string)$row['scope'];
                if ( ! array_key_exists( $scope, $previous_by_scope ) ) { $previous_by_scope[$scope]=(string)get_option($this->retention_anchor_option_name($subject_hash,$scope),''); }
                if ( ! hash_equals( (string)$previous_by_scope[$scope], (string)$row['previous_hash'] ) ) { return false; }
                if ( (int)$row['event_version'] >= 2 && '' !== $row['notice_hash'] && ! hash_equals( $row['notice_hash'], hash( 'sha256', $row['notice_snapshot'] ) ) ) { return false; }
                $payload=array('subject_hash'=>(string)$row['subject_hash'],'scope'=>$scope,'decision'=>(string)$row['decision'],'purposes'=>$row['purposes'],'notice_version'=>(string)$row['notice_version'],'source'=>(string)$row['source'],'evidence_ref'=>(string)$row['evidence_ref'],'occurred_at'=>(string)$row['occurred_at'],'recorded_at'=>(string)$row['recorded_at'],'actor_user_id'=>(int)$row['actor_user_id'],'event_version'=>(int)$row['event_version'],'notice_hash'=>(string)$row['notice_hash'],'previous_hash'=>(string)$row['previous_hash'],'context'=>$row['context']);
                $expected=hash_hmac('sha256',$this->canonical_payload($payload),$this->chain_key());
                if(!hash_equals($expected,(string)$row['event_hash'])){return false;}
                $previous_by_scope[$scope]=(string)$row['event_hash'];
            }
            $offset += count( $rows );
        } while ( count( $rows ) === $limit );
        if ( ! $seen ) { return true; }
        foreach($previous_by_scope as $scope=>$last_hash){$head=(string)get_option($this->head_option_name($subject_hash,$scope),'');if(''===$head||!hash_equals($head,$last_hash)){return false;}}
        return true;
    }

    public function verify_chain_for_email( $email ) {
        $subject_hash=$this->subject_hash($email);if(''===$subject_hash){return false;}return $this->verify_subject_hash($subject_hash);
    }

    private function verify_all_chains() {
        $this->maybe_install(); global $wpdb; $table=$this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Manual integrity sweep reads pseudonymous subject hashes from the plugin-owned ledger.
        $subjects=(array)$wpdb->get_col($wpdb->prepare('SELECT DISTINCT subject_hash FROM %i ORDER BY subject_hash ASC LIMIT %d',$table,5001));
        $truncated=count($subjects)>5000;if($truncated){$subjects=array_slice($subjects,0,5000);}
        $failed=0;foreach($subjects as $subject_hash){if(!$this->verify_subject_hash($subject_hash)){$failed++;}}
        $result=array('checked_at'=>gmdate('c'),'subjects'=>count($subjects),'failed'=>$failed,'verified'=>0===$failed,'truncated'=>$truncated);
        update_option(self::OPTION_LAST_INTEGRITY,$result,false);return $result;
    }

    private function decision_label( $decision ) { $labels=array('granted'=>'Consentement donné','partial'=>'Consentement partiel','denied'=>'Refus','withdrawn'=>'Retrait');return isset($labels[$decision])?$labels[$decision]:(string)$decision; }
    private function scope_label( $scope ) { $labels=array('email_tracking'=>'Suivi des e-mails','site_consent'=>'Consentement du site');return isset($labels[$scope])?$labels[$scope]:(string)$scope; }

    public function filter_email_tracking_status( $status, $email ) { $latest=$this->latest_for_email($email,'email_tracking'); return $latest?$latest:$status; }
    public function admin_menu() { add_submenu_page('pixel-trackers-manager','Preuves de consentement','Preuves','manage_options','dendrila-privacy-evidence',array($this,'render_admin_page')); }

    public function render_admin_page() {
        if(!current_user_can('manage_options')){wp_die(esc_html__('Accès refusé.','dendrila-privacy'));}
        $message='';$type='success';$lookup_email='';
        if(isset($_POST['dendrila_privacy_evidence_action'])){
            check_admin_referer('dendrila_privacy_evidence_admin','dendrila_privacy_evidence_nonce');$action=sanitize_key(wp_unslash($_POST['dendrila_privacy_evidence_action']));$lookup_email=isset($_POST['evidence_email'])?sanitize_email(wp_unslash($_POST['evidence_email'])):'';
            if('record'===$action){$decision=isset($_POST['evidence_decision'])?sanitize_key(wp_unslash($_POST['evidence_decision'])):'granted';$purposes=isset($_POST['evidence_purposes'])?sanitize_textarea_field(wp_unslash($_POST['evidence_purposes'])):'';$notice=isset($_POST['evidence_notice_version'])?sanitize_text_field(wp_unslash($_POST['evidence_notice_version'])):'';$notice_text=isset($_POST['evidence_notice_text'])?sanitize_textarea_field(wp_unslash($_POST['evidence_notice_text'])):'';$source=isset($_POST['evidence_source'])?sanitize_key(wp_unslash($_POST['evidence_source'])):'manual';$result=$this->record_email_tracking_consent($lookup_email,$decision,$purposes,array('notice_version'=>$notice,'notice_text'=>$notice_text,'source'=>$source,'method'=>'manual_admin'));if(is_wp_error($result)){$message=$result->get_error_message();$type='error';}else{$message='Preuve locale ajoutée au registre.';$this->log_admin_action('manual_evidence_recorded',array('scope'=>'email_tracking'));}}
            elseif('save_retention'===$action){$days=isset($_POST['evidence_retention_days'])?max(30,min(3650,absint($_POST['evidence_retention_days']))):730;update_option(self::OPTION_RETENTION_DAYS,$days,false);$this->log_admin_action('retention_changed',array('days'=>$days));$message='Durée de conservation enregistrée : '.$days.' jours.';}
            elseif('verify_all'===$action){$result=$this->verify_all_chains();$this->log_admin_action('integrity_check',array('subjects'=>$result['subjects'],'failed'=>$result['failed'],'truncated'=>$result['truncated']?'1':'0'));$message=$result['verified']?'Contrôle d’intégrité terminé : aucune rupture détectée.':'Contrôle terminé : '.$result['failed'].' chaîne(s) demandent une vérification.';$type=$result['verified']?'success':'warning';}
            elseif('cleanup_now'===$action){$result=$this->cleanup_retention(true);$this->log_admin_action('retention_cleanup',array('removed'=>$result['removed'],'skipped'=>$result['skipped']));$message=$result['removed'].' événement(s) expiré(s) supprimé(s).'.($result['skipped']?' '.$result['skipped'].' groupe(s) conservé(s) car la continuité de chaîne n’était pas vérifiable.':'');$type=$result['skipped']?'warning':'success';}
        }
        $rows=$lookup_email?$this->rows_for_email($lookup_email,500,0):array();$verified=$lookup_email&&$rows?$this->verify_chain_for_email($lookup_email):null;
        $last_integrity=get_option(self::OPTION_LAST_INTEGRITY,array());$last_integrity=is_array($last_integrity)?$last_integrity:array();$admin_log=$this->admin_log_entries();
        echo '<div class="wrap ptm-wrap"><header class="ptm-head"><div><p class="ptm-eyebrow">Consentement vérifiable</p><h1>Preuves de consentement</h1><p>Un historique local, lisible et exportable — sans stocker l’adresse e-mail en clair dans le registre.</p></div></header>';
        if($message){echo '<div class="notice notice-'.esc_attr($type).' inline"><p>'.esc_html($message).'</p></div>';}
        echo '<section class="ptm-card ptm-evidence-governance"><div class="ptm-card-head"><div><h2><span class="dashicons dashicons-shield" aria-hidden="true"></span>Intégrité et conservation</h2><p>Le registre peut supprimer les événements expirés sans casser la preuve des événements conservés : la frontière cryptographique de la chaîne est gardée localement.</p></div></div><div class="ptm-evidence-governance-grid"><div><strong>'.esc_html($this->retention_days()).' jours</strong><span>conservation actuelle</span></div><div><strong>'.esc_html(isset($last_integrity['subjects'])?$last_integrity['subjects']:'—').'</strong><span>sujet(s) contrôlé(s)</span></div><div><strong>'.(!empty($last_integrity)?(empty($last_integrity['failed'])?'Vérifiée':'À contrôler'):'Jamais').'</strong><span>dernier contrôle global</span></div></div>';
        echo '<form method="post" class="ptm-evidence-governance-form">';wp_nonce_field('dendrila_privacy_evidence_admin','dendrila_privacy_evidence_nonce');echo '<label class="ptm-field"><span>Durée de conservation du registre</span><div class="ptm-inline-number"><input type="number" min="30" max="3650" step="1" name="evidence_retention_days" value="'.esc_attr($this->retention_days()).'"><span>jours</span></div><small>Entre 30 jours et 10 ans. Le nettoyage automatique est vérifié au plus une fois par jour dans l’administration.</small></label><div class="ptm-evidence-governance-actions"><button class="button button-primary" type="submit" name="dendrila_privacy_evidence_action" value="save_retention">Enregistrer</button><button class="button" type="submit" name="dendrila_privacy_evidence_action" value="verify_all">Contrôler l’intégrité</button><button class="button" type="submit" name="dendrila_privacy_evidence_action" value="cleanup_now">Appliquer la conservation maintenant</button></div></form>';
        if(!empty($last_integrity['checked_at'])){echo '<p class="ptm-muted">Dernier contrôle : '.esc_html($last_integrity['checked_at']).' UTC'.(!empty($last_integrity['truncated'])?' — contrôle limité aux 5 000 premiers sujets.':'').'.</p>';}
        echo '</section>';
        echo '<section class="ptm-card"><div class="ptm-card-head"><div><h2><span class="dashicons dashicons-search" aria-hidden="true"></span>Retrouver une preuve</h2><p>La recherche utilise une empreinte HMAC propre à ce WordPress. L’adresse saisie sert à retrouver l’historique mais n’est pas enregistrée dans le registre.</p></div></div>';
        echo '<form method="post" class="ptm-evidence-search">';wp_nonce_field('dendrila_privacy_evidence_admin','dendrila_privacy_evidence_nonce');echo '<input type="hidden" name="dendrila_privacy_evidence_action" value="lookup"><label class="ptm-field" for="dendrila-evidence-email"><span>Adresse e-mail</span><input id="dendrila-evidence-email" type="email" name="evidence_email" value="'.esc_attr($lookup_email).'" required><small>Aucune adresse n’est ajoutée au registre lors d’une simple recherche.</small></label>';submit_button('Rechercher','secondary','submit',false);echo '</form>';
        if($lookup_email){$latest=$rows?$this->decoded_row(end($rows)):null;echo '<div class="ptm-evidence-summary"><div><strong>'.esc_html(count($rows)).'</strong><span>événement(s) retrouvé(s)</span></div><div><strong>'.($rows?($verified?'Vérifiée':'À contrôler'):'—').'</strong><span>intégrité de la chaîne</span></div><div><strong>'.esc_html($latest?$this->decision_label($latest['decision']):'—').'</strong><span>dernier état enregistré</span></div></div>';
            if($rows){echo '<div class="ptm-table-wrap"><table class="widefat striped ptm-dashboard-table ptm-evidence-table"><thead><tr><th>Date UTC</th><th>Portée</th><th>Décision</th><th>Finalités</th><th>Source</th><th>Information</th></tr></thead><tbody>';foreach(array_reverse($rows) as $stored){$row=$this->decoded_row($stored);echo '<tr><td>'.esc_html($row['occurred_at']).'</td><td>'.esc_html($this->scope_label($row['scope'])).'</td><td>'.esc_html($this->decision_label($row['decision'])).'</td><td>'.esc_html(implode(', ',$row['purposes'])).'</td><td>'.esc_html($row['source']).'</td><td>'.esc_html($row['notice_version']).( '' !== $row['notice_snapshot'] ? '<details><summary>Texte conservé</summary><p>'.nl2br(esc_html($row['notice_snapshot'])).'</p></details>' : '' ).'</td></tr>';}echo '</tbody></table></div>';
                echo '<div class="ptm-evidence-export-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dendrila_privacy_export_evidence"><input type="hidden" name="evidence_email" value="'.esc_attr($lookup_email).'">';wp_nonce_field('dendrila_privacy_evidence_export');submit_button('Exporter JSON','secondary','submit',false);echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dendrila_privacy_export_evidence_csv"><input type="hidden" name="evidence_email" value="'.esc_attr($lookup_email).'">';wp_nonce_field('dendrila_privacy_evidence_export');submit_button('Exporter CSV','secondary','submit',false);echo '</form></div>';
            }else{echo '<div class="ptm-callout neutral"><strong>Aucune preuve trouvée.</strong> Vérifiez l’adresse ou ajoutez une preuve seulement si l’outil concerné ne peut pas encore la transmettre automatiquement.</div>';}
            do_action( 'dendrila_privacy_evidence_after_lookup', $lookup_email, $latest );
        }
        echo '</section>';
        echo '<section class="ptm-card ptm-evidence-manual"><div class="ptm-card-head"><div><h2><span class="dashicons dashicons-edit" aria-hidden="true"></span>Ajout manuel</h2><p>Solution de secours uniquement. Les futurs adaptateurs MailPoet, FluentCRM et autres utiliseront directement le registre.</p></div></div><div class="ptm-callout neutral"><strong>À retenir :</strong> n’inscrivez jamais l’adresse e-mail dans les champs de finalité, source ou contexte.</div>';
        echo '<form method="post">';wp_nonce_field('dendrila_privacy_evidence_admin','dendrila_privacy_evidence_nonce');echo '<input type="hidden" name="dendrila_privacy_evidence_action" value="record"><div class="ptm-form-grid"><label class="ptm-field"><span>Adresse e-mail</span><input type="email" name="evidence_email" value="'.esc_attr($lookup_email).'" required></label><label class="ptm-field"><span>Décision</span><select name="evidence_decision"><option value="granted">Consentement donné</option><option value="partial">Consentement partiel</option><option value="denied">Refus</option><option value="withdrawn">Retrait</option></select></label><label class="ptm-field"><span>Finalités</span><textarea rows="3" name="evidence_purposes" placeholder="Mesure des ouvertures, personnalisation…"></textarea></label><label class="ptm-field"><span>Version de l’information</span><input type="text" name="evidence_notice_version" placeholder="email-tracking-2026-04"><small>Identifie le texte présenté au moment du choix.</small></label><label class="ptm-field"><span>Texte présenté</span><textarea rows="4" name="evidence_notice_text" placeholder="Copiez ici l’information réellement présentée à la personne."></textarea><small>Le registre conserve le texte et son empreinte afin de détecter une modification ultérieure.</small></label><label class="ptm-field"><span>Source</span><input type="text" name="evidence_source" value="manual"><small>Par exemple : mailpoet, fluentcrm ou manual.</small></label></div>';submit_button('Ajouter au registre');echo '</form></section>';
        if($admin_log){echo '<section class="ptm-card"><div class="ptm-card-head"><div><h2><span class="dashicons dashicons-list-view" aria-hidden="true"></span>Journal administratif</h2><p>Les opérations sensibles sur le registre sont historisées séparément, sans y recopier d’adresse e-mail.</p></div></div><div class="ptm-table-wrap"><table class="widefat striped ptm-dashboard-table"><thead><tr><th>Date UTC</th><th>Action</th><th>Compte</th><th>Détail</th></tr></thead><tbody>';foreach($admin_log as $entry){$details=array();foreach((array)(isset($entry['details'])?$entry['details']:array()) as $key=>$value){$details[]=sanitize_key((string)$key).'='.sanitize_text_field((string)$value);}echo '<tr><td>'.esc_html(isset($entry['occurred_at'])?$entry['occurred_at']:'').'</td><td>'.esc_html(isset($entry['action'])?$entry['action']:'').'</td><td>#'.esc_html(isset($entry['actor_user_id'])?absint($entry['actor_user_id']):0).'</td><td>'.esc_html(implode(' · ',$details)).'</td></tr>';}echo '</tbody></table></div></section>';}
        echo '</div>';
    }

    public function export_evidence() {
        if(!current_user_can('manage_options')){wp_die(esc_html__('Accès refusé.','dendrila-privacy'));}check_admin_referer('dendrila_privacy_evidence_export');$email=isset($_POST['evidence_email'])?sanitize_email(wp_unslash($_POST['evidence_email'])):'';$rows=$this->rows_for_email($email,500,0);$events=array();foreach($rows as $stored){$row=$this->decoded_row($stored);unset($row['context_json']);$events[]=$row;}
        $this->log_admin_action('export_json',array('events'=>count($events)));
        $payload=array('schema'=>2,'subject_hash'=>$this->subject_hash($email),'chain_verified'=>$rows?$this->verify_chain_for_email($email):true,'retention_days'=>$this->retention_days(),'exported_at'=>gmdate('c'),'events'=>$events);nocache_headers();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dendrila-privacy-consent-evidence.json"');echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }

    private function csv_value( $value ) {
        if ( is_array( $value ) ) { $value = implode( ' | ', $value ); }
        $value = (string)$value;
        if ( '' !== $value && in_array( substr($value,0,1), array('=','+','-','@'), true ) ) { $value="'".$value; }
        return '"'.str_replace('"','""',$value).'"';
    }

    public function export_evidence_csv() {
        if(!current_user_can('manage_options')){wp_die(esc_html__('Accès refusé.','dendrila-privacy'));}check_admin_referer('dendrila_privacy_evidence_export');$email=isset($_POST['evidence_email'])?sanitize_email(wp_unslash($_POST['evidence_email'])):'';$rows=$this->rows_for_email($email,500,0);
        $this->log_admin_action('export_csv',array('events'=>count($rows)));nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="dendrila-privacy-consent-evidence.csv"');echo "\xEF\xBB\xBF";echo implode(';',array_map(array($this,'csv_value'),array('date_utc','scope','decision','purposes','source','notice_version','notice_hash','evidence_ref','event_hash')))."\r\n";
        foreach($rows as $stored){$row=$this->decoded_row($stored);$line=array($row['occurred_at'],$row['scope'],$row['decision'],$row['purposes'],$row['source'],$row['notice_version'],$row['notice_hash'],$row['evidence_ref'],$row['event_hash']);echo implode(';',array_map(array($this,'csv_value'),$line))."\r\n";}exit;
    }

    public function register_privacy_exporter( $exporters ) { $exporters['dendrila-privacy-consent-evidence']=array('exporter_friendly_name'=>'Dendrila Privacy — preuves de consentement','callback'=>array($this,'privacy_exporter'));return $exporters; }
    public function register_privacy_eraser( $erasers ) { $erasers['dendrila-privacy-consent-evidence']=array('eraser_friendly_name'=>'Dendrila Privacy — preuves de consentement','callback'=>array($this,'privacy_eraser'));return $erasers; }
    public function privacy_exporter( $email_address, $page = 1 ) {
        $page=max(1,absint($page));$limit=100;$rows=$this->rows_for_email($email_address,$limit,($page-1)*$limit);$data=array();foreach($rows as $stored){$row=$this->decoded_row($stored);$data[]=array('group_id'=>'dendrila-privacy-consent-evidence','group_label'=>'Dendrila Privacy — preuves de consentement','item_id'=>'evidence-'.absint($row['id']),'data'=>array(array('name'=>'Portée','value'=>$row['scope']),array('name'=>'Décision','value'=>$row['decision']),array('name'=>'Finalités','value'=>implode(', ',$row['purposes'])),array('name'=>'Date UTC','value'=>$row['occurred_at']),array('name'=>'Source','value'=>$row['source']),array('name'=>'Version information','value'=>$row['notice_version']),array('name'=>'Texte information','value'=>$row['notice_snapshot']),array('name'=>'Empreinte information','value'=>$row['notice_hash']),array('name'=>'Empreinte de l’événement','value'=>$row['event_hash'])));}return array('data'=>$data,'done'=>count($rows)<$limit);
    }

    public function privacy_eraser( $email_address, $page = 1 ) {
        if ( (int) $page > 1 ) { return array( 'items_removed'=>false, 'items_retained'=>false, 'messages'=>array(), 'done'=>true ); }
        $this->maybe_install();
        $subject_hash=$this->subject_hash($email_address);
        if(''===$subject_hash){return array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true);}
        $rows_before=$this->rows_for_email($email_address,500,0);$scopes=array('email_tracking','site_consent');foreach($rows_before as $row){if(!empty($row['scope'])){$scopes[]=sanitize_key((string)$row['scope']);}}$scopes=array_values(array_unique(array_filter($scopes)));
        global $wpdb;$table=$this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress privacy eraser explicitly removes this subject's local ledger rows.
        $deleted=$wpdb->delete($table,array('subject_hash'=>$subject_hash),array('%s'));
        foreach($scopes as $scope){delete_option($this->head_option_name($subject_hash,$scope));delete_option($this->retention_anchor_option_name($subject_hash,$scope));}
        $user=get_user_by('email',sanitize_email($email_address));
        if($user){delete_user_meta($user->ID,'_dendrila_privacy_account_consent');}
        return array('items_removed'=>false!==$deleted&&$deleted>0,'items_retained'=>false,'messages'=>array(),'done'=>true);
    }
}

if(!function_exists('dendrila_privacy_record_consent_evidence')){function dendrila_privacy_record_consent_evidence($email,$scope,$decision,$purposes,$context=array()){return Dendrila_Privacy_Evidence_Ledger::instance()->record_event($email,$scope,$decision,$purposes,$context);}}
if(!function_exists('dendrila_privacy_record_email_tracking_consent')){function dendrila_privacy_record_email_tracking_consent($email,$decision,$purposes,$context=array()){return Dendrila_Privacy_Evidence_Ledger::instance()->record_email_tracking_consent($email,$decision,$purposes,$context);}}
if(!function_exists('dendrila_privacy_get_email_tracking_consent')){function dendrila_privacy_get_email_tracking_consent($email){return Dendrila_Privacy_Evidence_Ledger::instance()->latest_for_email($email,'email_tracking');}}
