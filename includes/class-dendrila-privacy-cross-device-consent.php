<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Privacy_Cross_Device_Consent {
    const OPTION = 'dendrila_privacy_cross_device_settings';
    const USER_META = '_dendrila_privacy_cross_device_consent';

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
        add_action( 'dendrila_privacy_after_consent_settings', array( $this, 'render_admin_settings' ) );
        add_action( 'admin_post_dendrila_privacy_save_cross_device', array( $this, 'handle_admin_save' ) );
        add_action( 'wp_ajax_dendrila_privacy_cross_device_save', array( $this, 'ajax_save' ) );
        add_filter( 'dendrila_privacy_consent_banner_copy', array( $this, 'filter_banner_copy' ) );
        add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ), 35 );
    }

    private function settings() {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $settings = wp_parse_args( $stored, array(
            'enabled' => 0,
            'strategy' => 'account_wins',
        ) );
        $settings['enabled'] = empty( $settings['enabled'] ) ? 0 : 1;
        $settings['strategy'] = 'device_wins' === $settings['strategy'] ? 'device_wins' : 'account_wins';
        return $settings;
    }

    private function account_choice( $user_id, $fingerprint, $retention_days ) {
        $choice = Dendrila_Privacy_Consent_Core::normalize_choice( get_user_meta( $user_id, self::USER_META, true ) );
        if ( ! $choice || ! $choice['savedAt'] ) {
            return null;
        }
        if ( $fingerprint && $choice['fingerprint'] !== $fingerprint ) {
            return null;
        }
        if ( (int) floor( microtime( true ) * 1000 ) - (int) $choice['savedAt'] > max( 30, (int) $retention_days ) * 86400000 ) {
            return null;
        }
        return $choice;
    }

    private function account_version( $choice ) {
        return $choice ? substr( hash( 'sha256', wp_json_encode( $choice ) ), 0, 20 ) : '';
    }

    public function configured_enabled() {
        return ! empty( $this->settings()['enabled'] );
    }

    public function active() {
        $native = $this->plugin->public_settings();
        return $this->configured_enabled() && ! empty( $native['consent_enabled'] );
    }

    public static function public_config( $plugin, $fingerprint ) {
        $instance = self::instance( $plugin );
        if ( ! $instance ) {
            return array( 'enabled' => false, 'active' => false );
        }
        $settings = $instance->settings();
        $native = $plugin->public_settings();
        $logged_in = is_user_logged_in();
        $retention_days = isset( $native['consent_retention_days'] ) ? (int) $native['consent_retention_days'] : 180;
        $active = ! empty( $settings['enabled'] ) && ! empty( $native['consent_enabled'] ) && $logged_in;
        $choice = $active ? $instance->account_choice( get_current_user_id(), $fingerprint, $retention_days ) : null;
        return array(
            'enabled' => ! empty( $settings['enabled'] ),
            'active' => $active,
            'loggedIn' => $logged_in,
            'strategy' => $settings['strategy'],
            'accountChoice' => $choice,
            'accountVersion' => $instance->account_version( $choice ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => $active ? wp_create_nonce( 'dendrila_privacy_cross_device_save' ) : '',
        );
    }

    public function filter_banner_copy( $copy ) {
        if ( ! $this->configured_enabled() ) {
            return $copy;
        }
        return rtrim( $copy ) . ' Si vous vous connectez à votre compte, vos choix seront appliqués aux autres appareils sur lesquels ce compte est connecté.';
    }

    public function render_admin_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = $this->settings();
        $native = $this->plugin->public_settings();
        echo '<section class="ptm-card ptm-cross-device-settings"><div class="ptm-card-head"><div><p class="ptm-eyebrow">Multi-appareils</p><h2>Synchroniser les choix avec le compte WordPress</h2><p>Fonction locale et facultative : aucun identifiant de consentement externe ni choix de consentement n’est envoyé à un service tiers. Les visiteurs non connectés restent en stockage local classique.</p></div></div>';
        if ( empty( $native['consent_enabled'] ) ) {
            echo '<div class="ptm-callout neutral"><strong>Préparé mais inactif.</strong> Cette synchronisation ne s’applique que lorsque la gestion du consentement Dendrila Privacy est elle-même activée.</div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dendrila_privacy_save_cross_device">';
        wp_nonce_field( 'dendrila_privacy_save_cross_device' );
        echo '<label class="ptm-consent-toggle-card"><input type="checkbox" name="cross_device_enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . '><span><strong>Activer pour les comptes connectés</strong><small>Accepter, refuser et retirer auront la même portée sur les appareils reliés au même compte.</small></span></label>';
        echo '<h3>En cas de contradiction au moment de la connexion</h3><div class="ptm-cross-device-strategies">';
        echo '<label class="ptm-cross-device-strategy"><input type="radio" name="cross_device_strategy" value="account_wins" ' . checked( $settings['strategy'], 'account_wins', false ) . '><strong>Le compte prévaut</strong><p>Les choix enregistrés sur le compte remplacent ceux de ce navigateur. Une information est affichée immédiatement après la connexion.</p></label>';
        echo '<label class="ptm-cross-device-strategy"><input type="radio" name="cross_device_strategy" value="device_wins" ' . checked( $settings['strategy'], 'device_wins', false ) . '><strong>Ce terminal prévaut</strong><p>Les choix faits ici remplacent ceux du compte et deviennent les choix appliqués sur les autres appareils connectés.</p></label>';
        echo '</div>';
        submit_button( 'Enregistrer la synchronisation multi-appareils' );
        echo '</form></section>';
    }

    public function handle_admin_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'dendrila-privacy' ) );
        }
        check_admin_referer( 'dendrila_privacy_save_cross_device' );
        $strategy = isset( $_POST['cross_device_strategy'] ) ? sanitize_key( wp_unslash( $_POST['cross_device_strategy'] ) ) : 'account_wins';
        update_option(
            self::OPTION,
            array(
                'enabled' => empty( $_POST['cross_device_enabled'] ) ? 0 : 1,
                'strategy' => 'device_wins' === $strategy ? 'device_wins' : 'account_wins',
            ),
            false
        );
        wp_safe_redirect( add_query_arg(
            array( 'page' => 'pixel-trackers-manager-consent', 'dendrila_cross_device_saved' => '1' ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function ajax_save() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Authentification requise.' ), 401 );
        }
        if ( ! check_ajax_referer( 'dendrila_privacy_cross_device_save', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Session expirée.' ), 403 );
        }
        if ( ! $this->active() ) {
            wp_send_json_error( array( 'message' => 'Synchronisation multi-appareils inactive.' ), 409 );
        }

        $choice = Dendrila_Privacy_Consent_Core::normalize_choice( array(
            'statistics' => ! empty( $_POST['statistics'] ),
            'external' => ! empty( $_POST['external'] ),
            'marketing' => ! empty( $_POST['marketing'] ),
            'savedAt' => (int) floor( microtime( true ) * 1000 ),
            'fingerprint' => isset( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '',
        ) );
        update_user_meta( get_current_user_id(), self::USER_META, $choice );

        $reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : 'user_choice';
        if ( ! in_array( $reason, array( 'user_choice', 'account_created', 'device_wins' ), true ) ) {
            $reason = 'user_choice';
        }
        $ledger = Dendrila_Privacy_Consent_Ledger::instance();
        if ( $ledger ) {
            $ledger->record_account_choice( get_current_user_id(), $choice, $reason );
        }
        wp_send_json_success( array(
            'choice' => $choice,
            'accountVersion' => $this->account_version( $choice ),
        ) );
    }

    public function register_privacy_policy_content() {
        if ( ! $this->configured_enabled() || ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        $content = '<p>Lorsque la synchronisation multi-appareils de Dendrila Privacy est activée, les choix relatifs aux traceurs peuvent être associés au compte WordPress d’un utilisateur authentifié afin d’être appliqués sur ses autres appareils connectés. Les choix restent dans la base WordPress du site et ne sont pas envoyés à l’éditeur de Dendrila Privacy.</p>';
        $content .= '<p>Lorsqu’un choix local et un choix du compte se contredisent, le site applique la stratégie configurée par son administrateur et informe l’utilisateur après authentification. Les choix peuvent ensuite être modifiés depuis le même module de gestion du consentement.</p>';
        wp_add_privacy_policy_content( 'Dendrila Privacy — consentement multi-appareils', wp_kses_post( $content ) );
    }
}
