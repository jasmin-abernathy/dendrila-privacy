<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Privacy_Consent_Ledger {
    const SCHEMA_OPTION = 'dendrila_privacy_consent_ledger_schema';
    const SECRET_OPTION = 'dendrila_privacy_consent_ledger_secret';
    const SCHEMA_VERSION = '1';

    private static $instance = null;
    private $plugin;

    public static function instance( $plugin = null ) {
        if ( null === self::$instance && $plugin ) {
            self::$instance = new self( $plugin );
        }
        return self::$instance;
    }

    private function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_init', array( $this, 'maybe_install' ), 5 );
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
        add_action( 'admin_post_dendrila_privacy_record_email_pixel_event', array( $this, 'handle_manual_record' ) );
        add_action( 'admin_post_dendrila_privacy_export_consent_proof', array( $this, 'handle_export' ) );
        add_action( 'dendrila_privacy_record_email_pixel_event', array( $this, 'action_record_email_event' ), 10, 8 );
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
    }

    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dendrila_privacy_consent_events';
    }

    private function secret() {
        $secret = get_option( self::SECRET_OPTION, '' );
        if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
            return $secret;
        }
        $secret = wp_generate_password( 64, true, true );
        if ( false === get_option( self::SECRET_OPTION, false ) ) {
            add_option( self::SECRET_OPTION, $secret, '', 'no' );
        } else {
            update_option( self::SECRET_OPTION, $secret, false );
        }
        return $secret;
    }

    public function maybe_install() {
        if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_OPTION, '' ) ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subject_hash char(64) NOT NULL,
            subject_type varchar(32) NOT NULL,
            channel varchar(32) NOT NULL,
            purpose varchar(64) NOT NULL,
            decision varchar(32) NOT NULL,
            legal_basis varchar(32) NOT NULL,
            notice_version varchar(64) NOT NULL,
            notice_hash char(64) NOT NULL,
            notice_text longtext NOT NULL,
            source varchar(64) NOT NULL,
            context_json longtext NOT NULL,
            occurred_at datetime NOT NULL,
            previous_hash char(64) NOT NULL,
            event_hash char(64) NOT NULL,
            PRIMARY KEY  (id),
            KEY subject_channel (subject_hash, channel),
            KEY occurred_at (occurred_at)
        ) {$charset};";
        dbDelta( $sql );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
        $this->secret();
    }

    private function ensure_schema() {
        if ( self::SCHEMA_VERSION !== get_option( self::SCHEMA_OPTION, '' ) ) {
            $this->maybe_install();
        }
    }

    private function safe_context( $context ) {
        $context = is_array( $context ) ? $context : array();
        $out = array();
        foreach ( array( 'provider', 'campaign_id', 'form_id', 'collection_date', 'legacy_notice_date', 'legacy_extension_note', 'legacy_conditions_unchanged', 'exemption_note', 'service_requested', 'last_open_date_only', 'inactive_list_cleanup', 'choice', 'reason' ) as $key ) {
            if ( ! isset( $context[ $key ] ) ) {
                continue;
            }
            if ( 'choice' === $key && is_array( $context[ $key ] ) ) {
                $out[ $key ] = Dendrila_Privacy_Consent_Core::normalize_choice( $context[ $key ] );
                continue;
            }
            $out[ $key ] = sanitize_textarea_field( (string) $context[ $key ] );
        }
        return $out;
    }

    private function validate_email_event( $event ) {
        $purpose = isset( $event['purpose'] ) ? sanitize_key( $event['purpose'] ) : '';
        $decision = isset( $event['decision'] ) ? sanitize_key( $event['decision'] ) : '';
        $basis = isset( $event['legal_basis'] ) ? sanitize_key( $event['legal_basis'] ) : '';
        $notice = isset( $event['notice_text'] ) ? trim( (string) $event['notice_text'] ) : '';
        $context = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array();

        return Dendrila_Privacy_Consent_Core::validate_email_evidence(
            $purpose,
            $decision,
            $basis,
            $notice,
            $context
        );
    }

    private function insert_event( $subject_hash, $subject_type, $channel, $event ) {
        $this->ensure_schema();
        global $wpdb;
        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $previous_hash = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_hash FROM {$table} WHERE subject_hash = %s AND channel = %s ORDER BY id DESC LIMIT 1",
                $subject_hash,
                $channel
            )
        );
        $row = array(
            'subject_hash' => $subject_hash,
            'subject_type' => sanitize_key( $subject_type ),
            'channel' => sanitize_key( $channel ),
            'purpose' => sanitize_key( $event['purpose'] ),
            'decision' => sanitize_key( $event['decision'] ),
            'legal_basis' => sanitize_key( $event['legal_basis'] ),
            'notice_version' => sanitize_text_field( isset( $event['notice_version'] ) ? $event['notice_version'] : 'unversioned' ),
            'notice_hash' => hash( 'sha256', (string) $event['notice_text'] ),
            'notice_text' => sanitize_textarea_field( (string) $event['notice_text'] ),
            'source' => sanitize_key( isset( $event['source'] ) ? $event['source'] : 'manual' ),
            'context_json' => wp_json_encode( $this->safe_context( isset( $event['context'] ) ? $event['context'] : array() ) ),
            'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
            'previous_hash' => $previous_hash,
        );
        $row['event_hash'] = Dendrila_Privacy_Consent_Core::event_hash( $row, $this->secret() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $ok = $wpdb->insert(
            $table,
            $row,
            array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
        );
        return false === $ok
            ? new WP_Error( 'dendrila_privacy_ledger_insert', 'Impossible d’enregistrer la preuve locale.' )
            : (int) $wpdb->insert_id;
    }

    public function record_email_event( $email, $purpose, $decision, $legal_basis, $notice_text, $notice_version = '', $source = 'manual', $context = array() ) {
        $email = sanitize_email( $email );
        if ( ! $email || ! is_email( $email ) ) {
            return new WP_Error( 'dendrila_privacy_email', 'Adresse e-mail invalide.' );
        }
        $event = array(
            'purpose' => $purpose,
            'decision' => $decision,
            'legal_basis' => $legal_basis,
            'notice_text' => $notice_text,
            'notice_version' => $notice_version,
            'source' => $source,
            'context' => $context,
        );
        $errors = $this->validate_email_event( $event );
        if ( $errors ) {
            return new WP_Error( 'dendrila_privacy_email_event', implode( ' ', $errors ) );
        }
        return $this->insert_event(
            Dendrila_Privacy_Consent_Core::subject_hash( $email, $this->secret() ),
            'email',
            'email_pixel',
            $event
        );
    }

    public function action_record_email_event( $email, $purpose, $decision, $legal_basis, $notice_text, $notice_version = '', $source = 'integration', $context = array() ) {
        $this->record_email_event( $email, $purpose, $decision, $legal_basis, $notice_text, $notice_version, $source, $context );
    }

    public function record_account_choice( $user_id, $choice, $source = 'browser' ) {
        $choice = Dendrila_Privacy_Consent_Core::normalize_choice( $choice );
        if ( ! $choice || ! $user_id ) {
            return new WP_Error( 'dendrila_privacy_account_choice', 'Choix de compte invalide.' );
        }
        $copy = apply_filters(
            'dendrila_privacy_consent_banner_copy',
            'Ce site utilise ce qui est nécessaire à son fonctionnement. Les services facultatifs restent bloqués tant que vous ne les avez pas acceptés.'
        );
        return $this->insert_event(
            Dendrila_Privacy_Consent_Core::subject_hash( 'wp-user:' . absint( $user_id ), $this->secret() ),
            'wordpress_account',
            'cookie_consent',
            array(
                'purpose' => 'cookie_cross_device',
                'decision' => 'updated',
                'legal_basis' => 'consent',
                'notice_text' => $copy,
                'notice_version' => Pixel_Trackers_Manager_Plugin::VERSION,
                'source' => $source,
                'context' => array( 'choice' => $choice, 'reason' => $source ),
            )
        );
    }

    private function rows_for_hash( $subject_hash, $limit = 500 ) {
        $this->ensure_schema();
        global $wpdb;
        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE subject_hash = %s ORDER BY id ASC LIMIT %d",
                $subject_hash,
                max( 1, min( 1000, absint( $limit ) ) )
            ),
            ARRAY_A
        );
    }

    private function verify_rows( $rows ) {
        $previous = '';
        $secret = $this->secret();
        foreach ( (array) $rows as $row ) {
            if ( ! hash_equals( (string) $previous, (string) $row['previous_hash'] ) ) {
                return false;
            }
            $expected = Dendrila_Privacy_Consent_Core::event_hash( $row, $secret );
            if ( ! $expected || ! hash_equals( $expected, (string) $row['event_hash'] ) ) {
                return false;
            }
            $previous = $row['event_hash'];
        }
        return true;
    }

    public function admin_menu() {
        add_submenu_page(
            'pixel-trackers-manager',
            'Preuves de consentement',
            'Preuves de consentement',
            'manage_options',
            'dendrila-privacy-consent-proof',
            array( $this, 'render_admin_page' )
        );
    }

    private function query_email() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only lookup; it does not change state.
        $email = isset( $_GET['dendrila_subject_email'] ) ? wp_unslash( $_GET['dendrila_subject_email'] ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return sanitize_email( $email );
    }

    private function query_notice() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect feedback.
        $recorded = isset( $_GET['dendrila_recorded'] ) ? sanitize_key( wp_unslash( $_GET['dendrila_recorded'] ) ) : '';
        $error = isset( $_GET['dendrila_error'] ) ? sanitize_text_field( wp_unslash( $_GET['dendrila_error'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( '1' === $recorded ) {
            return array( 'success', 'Preuve locale enregistrée.' );
        }
        if ( '0' === $recorded && $error ) {
            return array( 'error', $error );
        }
        return array( '', '' );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'dendrila-privacy' ) );
        }
        list( $notice_type, $notice_text ) = $this->query_notice();
        $email = $this->query_email();
        $rows = array();
        $chain_ok = null;
        if ( $email && is_email( $email ) ) {
            $rows = $this->rows_for_hash( Dendrila_Privacy_Consent_Core::subject_hash( $email, $this->secret() ) );
            $chain_ok = $this->verify_rows( $rows );
        }

        $logo_url = plugin_dir_url( dirname( __DIR__ ) . '/dendrila-privacy.php' ) . 'assets/logo-mark.svg';
        echo '<div class="wrap ptm-wrap">';
        echo '<header class="ptm-head"><div class="ptm-brand"><img class="ptm-brand-mark" src="' . esc_url( $logo_url ) . '" alt=""><div><h1>Dendrila Privacy <span class="ptm-version">' . esc_html( Pixel_Trackers_Manager_Plugin::VERSION ) . '</span></h1><p><strong>Preuves de consentement</strong> — historique local et vérifiable</p></div></div></header>';
        echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-consent' ) ) . '">← Revenir au consentement</a></p>';

        if ( $notice_text ) {
            echo '<div class="notice notice-' . esc_attr( $notice_type ) . ' is-dismissible"><p>' . esc_html( $notice_text ) . '</p></div>';
        }

        echo '<section class="ptm-card">';
        echo '<div class="ptm-card-head"><div><p class="ptm-eyebrow">Registre local</p><h2>Retrouver une preuve</h2><p>L’adresse e-mail n’est pas conservée dans le registre : elle sert à recalculer localement un identifiant HMAC pour retrouver les événements correspondants.</p></div></div>';
        echo '<div class="ptm-callout neutral"><strong>Ce registre documente des faits.</strong> Il aide à conserver les choix et le texte présenté, sans transformer automatiquement une pratique en pratique conforme.</div>';
        echo '<form method="get" class="ptm-proof-search"><input type="hidden" name="page" value="dendrila-privacy-consent-proof">';
        echo '<label class="ptm-field" for="dendrila-subject-email"><span>Adresse e-mail</span><input id="dendrila-subject-email" type="email" name="dendrila_subject_email" value="' . esc_attr( $email ) . '" placeholder="personne@example.org"><small>Utilisée uniquement pour cette recherche locale.</small></label>';
        submit_button( 'Rechercher', 'secondary', '', false );
        echo '</form>';
        echo '</section>';

        if ( $email ) {
            echo '<section class="ptm-card">';
            echo '<div class="ptm-card-head"><div><p class="ptm-eyebrow">Historique</p><h2>' . esc_html( $email ) . '</h2><p>Chaque entrée conserve la finalité, la décision, le cadre déclaré, la source et la version de l’information présentée.</p></div></div>';
            if ( ! $rows ) {
                echo '<div class="ptm-callout neutral"><strong>Aucun événement trouvé.</strong> Ce registre ne contient encore aucune preuve locale correspondant à cette adresse.</div>';
            } else {
                echo '<div class="ptm-proof-status"><span class="ptm-badge ' . ( $chain_ok ? 'good' : 'bad' ) . '">' . ( $chain_ok ? 'Chaîne vérifiée' : 'Intégrité à contrôler' ) . '</span><span class="ptm-muted">' . esc_html( count( $rows ) ) . ' événement(s)</span></div>';
                $export = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action' => 'dendrila_privacy_export_consent_proof',
                            'email' => $email,
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                    'dendrila_privacy_export_consent_proof'
                );
                echo '<p><a class="button button-secondary" href="' . esc_url( $export ) . '">Exporter la preuve JSON</a></p>';
                echo '<div class="ptm-table-wrap"><table class="widefat striped ptm-dashboard-table ptm-proof-table"><thead><tr><th>Date UTC</th><th>Canal</th><th>Finalité</th><th>Décision</th><th>Cadre déclaré</th><th>Source</th><th>Information</th></tr></thead><tbody>';
                foreach ( $rows as $row ) {
                    echo '<tr><td>' . esc_html( $row['occurred_at'] ) . '</td><td>' . esc_html( $row['channel'] ) . '</td><td>' . esc_html( $row['purpose'] ) . '</td><td>' . esc_html( $row['decision'] ) . '</td><td>' . esc_html( $row['legal_basis'] ) . '</td><td>' . esc_html( $row['source'] ) . '</td><td><details><summary>' . esc_html( $row['notice_version'] ) . '</summary><p>' . nl2br( esc_html( $row['notice_text'] ) ) . '</p></details></td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';
        }

        echo '<section class="ptm-card">';
        echo '<div class="ptm-card-head"><div><p class="ptm-eyebrow">Pixels e-mail</p><h2>Ajouter une preuve manuellement</h2><p>Les futurs adaptateurs MailPoet, FluentCRM et autres utiliseront le même registre. Ce formulaire permet déjà de documenter proprement un événement vérifié par l’administrateur.</p></div></div>';
        echo '<form class="ptm-proof-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dendrila_privacy_record_email_pixel_event">';
        wp_nonce_field( 'dendrila_privacy_record_email_pixel_event' );
        echo '<div class="ptm-form-grid">';
        echo '<label class="ptm-field"><span>Adresse e-mail</span><input name="email" type="email" required placeholder="personne@example.org"></label>';
        echo '<label class="ptm-field"><span>Finalité</span><select name="purpose"><option value="email_open_measurement">Mesure des ouvertures / performance</option><option value="email_delivery">Délivrabilité strictement nécessaire</option></select></label>';
        echo '<label class="ptm-field"><span>Cadre déclaré</span><select name="legal_basis"><option value="consent">Consentement</option><option value="legacy_no_objection">Adresse collectée avant le 14 avril 2026, information + absence d’opposition</option><option value="exempt_delivery">Exemption délivrabilité évaluée</option></select></label>';
        echo '<label class="ptm-field"><span>Événement</span><select name="decision"><option value="granted">Consentement accordé</option><option value="denied">Consentement refusé</option><option value="withdrawn">Consentement retiré</option><option value="legacy_no_objection">Information transitoire sans opposition</option><option value="recorded_exemption">Exemption documentée</option></select></label>';
        echo '<label class="ptm-field"><span>Date de collecte de l’adresse</span><input name="collection_date" type="date"><small>Régime transitoire : elle doit être antérieure au 14 avril 2026.</small></label>';
        echo '<label class="ptm-field"><span>Date de l’information transitoire</span><input name="legacy_notice_date" type="date"><small>En principe avant ou le 14 juillet 2026 ; au-delà, documentez la prolongation.</small></label>';
        echo '<label class="ptm-field ptm-proof-wide"><span>Justification d’une information après le 14 juillet 2026</span><textarea name="legacy_extension_note" rows="3"></textarea><small>Par exemple : volume de la base ou risque de délivrabilité, avec éléments objectifs conservés par l’organisme.</small></label>';
        echo '<label class="ptm-consent-toggle-card is-compact ptm-proof-wide"><input type="checkbox" name="legacy_conditions_unchanged" value="1"><span><strong>Les conditions d’envoi sont restées inchangées</strong><small>Aucun nouveau consentement à l’envoi des courriels n’a dû être recueilli depuis cette information.</small></span></label>';
        echo '<label class="ptm-field"><span>Version de l’information</span><input name="notice_version" type="text" value="email-tracking-2026-1"></label>';
        echo '<label class="ptm-field ptm-proof-wide"><span>Texte présenté</span><textarea name="notice_text" rows="5"></textarea><small>Conservez le texte réellement affiché ou envoyé à la personne.</small></label>';
        echo '<div class="ptm-proof-wide ptm-callout neutral"><strong>Si vous déclarez une exemption de délivrabilité</strong><p>Les trois critères ci-dessous doivent être vérifiés, sinon le registre refusera l’événement comme « exempté ».</p></div>';
        echo '<label class="ptm-consent-toggle-card is-compact"><input type="checkbox" name="service_requested" value="1"><span><strong>Service explicitement demandé</strong><small>Le courriel se rattache à un service demandé par le destinataire.</small></span></label>';
        echo '<label class="ptm-consent-toggle-card is-compact"><input type="checkbox" name="last_open_date_only" value="1"><span><strong>Données strictement minimisées</strong><small>En principe, seule la date de dernière ouverture est nécessaire ; pas d’heure, IP ou user-agent sans justification distincte.</small></span></label>';
        echo '<label class="ptm-consent-toggle-card is-compact"><input type="checkbox" name="inactive_list_cleanup" value="1"><span><strong>Gestion réelle des inactifs</strong><small>La mesure sert à adapter la fréquence ou arrêter les envois aux destinataires inactifs.</small></span></label>';
        echo '<label class="ptm-field ptm-proof-wide"><span>Justification délivrabilité</span><textarea name="exemption_note" rows="4"></textarea><small>Décrivez concrètement comment ces critères sont respectés.</small></label>';
        echo '<label class="ptm-field"><span>Source</span><input name="source" type="text" value="manual"><small>Ex. manual, mailpoet, fluentcrm.</small></label>';
        echo '</div>';
        echo '<div class="ptm-proof-form-actions">';
        submit_button( 'Enregistrer la preuve locale', 'primary', '', false );
        echo '</div></form>';
        echo '</section></div>';
    }

    public function handle_manual_record() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'dendrila-privacy' ) );
        }
        check_admin_referer( 'dendrila_privacy_record_email_pixel_event' );
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $purpose = isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : '';
        $decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        $basis = isset( $_POST['legal_basis'] ) ? sanitize_key( wp_unslash( $_POST['legal_basis'] ) ) : '';
        $notice_text = isset( $_POST['notice_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notice_text'] ) ) : '';
        $notice_version = isset( $_POST['notice_version'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_version'] ) ) : '';
        $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'manual';
        $context = array(
            'collection_date' => isset( $_POST['collection_date'] ) ? sanitize_text_field( wp_unslash( $_POST['collection_date'] ) ) : '',
            'legacy_notice_date' => isset( $_POST['legacy_notice_date'] ) ? sanitize_text_field( wp_unslash( $_POST['legacy_notice_date'] ) ) : '',
            'legacy_extension_note' => isset( $_POST['legacy_extension_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['legacy_extension_note'] ) ) : '',
            'legacy_conditions_unchanged' => empty( $_POST['legacy_conditions_unchanged'] ) ? '' : 'yes',
            'service_requested' => empty( $_POST['service_requested'] ) ? '' : 'yes',
            'last_open_date_only' => empty( $_POST['last_open_date_only'] ) ? '' : 'yes',
            'inactive_list_cleanup' => empty( $_POST['inactive_list_cleanup'] ) ? '' : 'yes',
            'exemption_note' => isset( $_POST['exemption_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exemption_note'] ) ) : '',
        );
        $result = $this->record_email_event( $email, $purpose, $decision, $basis, $notice_text, $notice_version, $source, $context );
        $args = array(
            'page' => 'dendrila-privacy-consent-proof',
            'dendrila_subject_email' => $email,
            'dendrila_recorded' => is_wp_error( $result ) ? '0' : '1',
        );
        if ( is_wp_error( $result ) ) {
            $args['dendrila_error'] = $result->get_error_message();
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'dendrila-privacy' ) );
        }
        check_admin_referer( 'dendrila_privacy_export_consent_proof' );
        $email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
        if ( ! $email || ! is_email( $email ) ) {
            wp_die( esc_html__( 'Adresse e-mail invalide.', 'dendrila-privacy' ) );
        }
        $hash = Dendrila_Privacy_Consent_Core::subject_hash( $email, $this->secret() );
        $rows = $this->rows_for_hash( $hash );
        $payload = array(
            'format' => 'dendrila-privacy-consent-proof-1',
            'exported_at' => gmdate( 'c' ),
            'subject_email' => $email,
            'subject_hash' => $hash,
            'chain_valid' => $this->verify_rows( $rows ),
            'events' => $rows,
        );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dendrila-consent-proof-' . gmdate( 'Ymd-His' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    public function register_privacy_exporter( $exporters ) {
        $exporters['dendrila-privacy-consent'] = array(
            'exporter_friendly_name' => __( 'Dendrila Privacy — preuves de consentement', 'dendrila-privacy' ),
            'callback' => array( $this, 'privacy_exporter' ),
        );
        return $exporters;
    }

    public function register_privacy_eraser( $erasers ) {
        $erasers['dendrila-privacy-consent'] = array(
            'eraser_friendly_name' => __( 'Dendrila Privacy — preuves de consentement', 'dendrila-privacy' ),
            'callback' => array( $this, 'privacy_eraser' ),
        );
        return $erasers;
    }

    private function subject_hashes_for_email( $email_address ) {
        $hashes = array();
        $email = sanitize_email( $email_address );
        if ( $email && is_email( $email ) ) {
            $hashes[] = Dendrila_Privacy_Consent_Core::subject_hash( $email, $this->secret() );
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $hashes[] = Dendrila_Privacy_Consent_Core::subject_hash( 'wp-user:' . absint( $user->ID ), $this->secret() );
            }
        }
        return array_unique( $hashes );
    }

    public function privacy_exporter( $email_address, $page = 1 ) {
        if ( (int) $page > 1 ) {
            return array( 'data' => array(), 'done' => true );
        }
        $items = array();
        foreach ( $this->subject_hashes_for_email( $email_address ) as $hash ) {
            foreach ( $this->rows_for_hash( $hash ) as $row ) {
                $items[] = array(
                    'group_id' => 'dendrila-privacy-consent',
                    'group_label' => __( 'Dendrila Privacy — consentements', 'dendrila-privacy' ),
                    'item_id' => 'dendrila-consent-' . (int) $row['id'],
                    'data' => array(
                        array( 'name' => __( 'Date UTC', 'dendrila-privacy' ), 'value' => $row['occurred_at'] ),
                        array( 'name' => __( 'Finalité', 'dendrila-privacy' ), 'value' => $row['purpose'] ),
                        array( 'name' => __( 'Décision', 'dendrila-privacy' ), 'value' => $row['decision'] ),
                        array( 'name' => __( 'Base déclarée', 'dendrila-privacy' ), 'value' => $row['legal_basis'] ),
                        array( 'name' => __( 'Information présentée', 'dendrila-privacy' ), 'value' => $row['notice_text'] ),
                    ),
                );
            }
        }
        return array( 'data' => $items, 'done' => true );
    }

    public function privacy_eraser( $email_address, $page = 1 ) {
        if ( (int) $page > 1 ) {
            return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
        }
        $this->ensure_schema();
        global $wpdb;
        $removed = false;
        $table = $this->table_name();
        foreach ( $this->subject_hashes_for_email( $email_address ) as $hash ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $deleted = $wpdb->delete( $table, array( 'subject_hash' => $hash ), array( '%s' ) );
            if ( false !== $deleted && $deleted > 0 ) {
                $removed = true;
            }
        }
        $user = get_user_by( 'email', sanitize_email( $email_address ) );
        if ( $user && delete_user_meta( $user->ID, '_dendrila_privacy_cross_device_consent' ) ) {
            $removed = true;
        }
        return array(
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => array(),
            'done' => true,
        );
    }
}
