<?php
/**
 * Plugin Name: Dendrila Privacy
 * Description: Local-first privacy audits, legal documentation assistance, tracker detection, and optional consent management.
 * Version: 0.0.4
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Le Potager du Web
 * Author URI: https://www.lepotager.org/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dendrila-privacy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Pixel_Trackers_Manager_Plugin {
    const VERSION = '0.0.4';
    const OPTION_SETTINGS = 'pixel_trackers_manager_settings';
    const OPTION_SCAN = 'pixel_trackers_manager_scan_current';
    const OPTION_PREVIOUS_SCAN = 'pixel_trackers_manager_scan_previous';
    const OPTION_PAGE_AUDIT = 'pixel_trackers_manager_page_audit';
    const OPTION_AUDIT_LOG = 'pixel_trackers_manager_audit_log';
    const OPTION_LEGAL_PROFILE = 'pixel_trackers_manager_legal_profile';
    const OPTION_PUBLIC_DOCUMENTS = 'pixel_trackers_manager_public_documents';
    const OPTION_PAGE_OVERLAYS = 'pixel_trackers_manager_page_overlays';
    const OPTION_DATA_SCHEMA = 'pixel_trackers_manager_data_schema_version';
    const OPTION_ONBOARDING = 'pixel_trackers_manager_onboarding';
    const CRON_HOOK = 'pixel_trackers_manager_scheduled_scan';
    const BLOCK_START = '<!-- PTM:SERVICES:START -->';
    const BLOCK_END = '<!-- PTM:SERVICES:END -->';
    const LEGAL_BLOCK_START = '<!-- PTM:LEGAL:START -->';
    const LEGAL_BLOCK_END = '<!-- PTM:LEGAL:END -->';

    private static $instance = null;
    private $legal_page_candidate_cache = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_menu_icon_styles' ), 1 );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_setup_widget' ) );
        add_action( 'admin_init', array( $this, 'handle_dashboard_widget_dismissal' ), 5 );
        add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
        add_action( 'admin_init', array( $this, 'maybe_redirect_first_open' ), 20 );
        add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ), 30 );
        add_action( 'wp_ajax_pixel_trackers_manager_scan_start', array( $this, 'ajax_scan_start' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_scan_step', array( $this, 'ajax_scan_step' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_scan_finalize', array( $this, 'ajax_scan_finalize' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_scan_cancel', array( $this, 'ajax_scan_cancel' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_company_search', array( $this, 'ajax_company_search' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_hosting_detect', array( $this, 'ajax_hosting_detect' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_save_legal_section', array( $this, 'ajax_save_legal_section' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_wizard_preview', array( $this, 'ajax_wizard_preview' ) );
        add_action( 'wp_ajax_pixel_trackers_manager_mark_wizard_reviewed', array( $this, 'ajax_mark_wizard_reviewed' ) );
        add_action( self::CRON_HOOK, array( $this, 'run_scheduled_scan' ) );
        add_action( 'before_delete_post', array( $this, 'cleanup_deleted_page' ) );
        add_filter( 'the_content', array( $this, 'inject_builder_managed_blocks' ), 99 );
        add_filter( 'elementor/frontend/the_content', array( $this, 'inject_builder_managed_blocks' ), 99 );
        // Public shortcodes use the same unique namespace as the plugin's stored data.
        add_shortcode( 'dendrila_privacy_services', array( $this, 'shortcode_services' ) );
        add_shortcode( 'dendrila_privacy_privacy_policy', array( $this, 'shortcode_privacy' ) );
        add_shortcode( 'dendrila_privacy_legal_notice', array( $this, 'shortcode_legal_notice' ) );
        add_shortcode( 'dendrila_privacy_cookies', array( $this, 'shortcode_cookie_policy' ) );
        add_shortcode( 'dendrila_privacy_rights', array( $this, 'shortcode_privacy' ) );
        add_shortcode( 'dendrila_privacy_documents', array( $this, 'shortcode_legal_bundle' ) );
        add_shortcode( 'dendrila_privacy_consent_settings', array( $this, 'shortcode_consent_settings' ) );

        // Pre-publication builds used older shortcode prefixes. Rewrite that markup before
        // WordPress evaluates shortcodes so existing test pages keep rendering without
        // registering collision-prone public shortcode names.
        add_filter( 'the_content', array( $this, 'rewrite_legacy_shortcode_markup' ), 8 );
        add_filter( 'widget_text_content', array( $this, 'rewrite_legacy_shortcode_markup' ), 8 );
        add_filter( 'elementor/frontend/the_content', array( $this, 'rewrite_legacy_shortcode_markup' ), 8 );
        $this->maybe_migrate_data();
        $this->apply_adapter_overrides();
        $this->init_consent_manager();
    }

    /**
     * Rewrite shortcode markup emitted by pre-publication development builds.
     */
    public function rewrite_legacy_shortcode_markup( $content ) {
        if ( ! is_string( $content ) || ( false === strpos( $content, '[ptm_' ) && false === strpos( $content, '[pixel_trackers_manager_' ) ) ) {
            return $content;
        }

        $legacy_to_public = array(
            'ptm_services'                  => 'dendrila_privacy_services',
            'ptm_privacy_policy'            => 'dendrila_privacy_privacy_policy',
            'ptm_legal_notice'              => 'dendrila_privacy_legal_notice',
            'ptm_cookies'                   => 'dendrila_privacy_cookies',
            'ptm_rights'                    => 'dendrila_privacy_rights',
            'ptm_documents'                 => 'dendrila_privacy_documents',
            'ptm_consent_settings'          => 'dendrila_privacy_consent_settings',
            'ptm_rgpd'                      => 'dendrila_privacy_privacy_policy',
            'ptm_politique_confidentialite' => 'dendrila_privacy_privacy_policy',
            'ptm_mentions_legales'          => 'dendrila_privacy_legal_notice',
            'pixel_trackers_manager_services'         => 'dendrila_privacy_services',
            'pixel_trackers_manager_privacy_policy'   => 'dendrila_privacy_privacy_policy',
            'pixel_trackers_manager_legal_notice'     => 'dendrila_privacy_legal_notice',
            'pixel_trackers_manager_cookies'          => 'dendrila_privacy_cookies',
            'pixel_trackers_manager_rights'           => 'dendrila_privacy_rights',
            'pixel_trackers_manager_documents'        => 'dendrila_privacy_documents',
            'pixel_trackers_manager_consent_settings' => 'dendrila_privacy_consent_settings',
        );

        foreach ( $legacy_to_public as $legacy => $public ) {
            $content = str_replace(
                array( '[' . $legacy, '[/' . $legacy ),
                array( '[' . $public, '[/' . $public ),
                $content
            );
        }

        return $content;
    }

    /**
     * Read a non-mutating front-end/admin query value.
     *
     * Query-string navigation and preview flags do not change stored state, so a nonce
     * is neither useful nor expected here. Values are always unslashed and sanitized.
     */
    private function query_value( $key, $default = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/preview parameters are unslashed here and sanitized immediately below; no state is changed.
        $value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : $default;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
    }

    /**
     * Read POST data only after the caller has verified its nonce and capability.
     */
    private function verified_post_value( $key, $default = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The calling action verifies the nonce; this scalar is unslashed here and sanitized immediately below.
        $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
    }

    /**
     * Return an unslashed POST array after nonce verification by the caller.
     * The receiving domain-specific sanitizer is responsible for each field type.
     */
    private function verified_post_array( $key ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The caller verifies the nonce and immediately passes this array to typed sanitizers.
        $value = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        return $value;
    }

    /**
     * Surface optional diagnostics without writing directly to the PHP error log.
     */
    private function runtime_warning( $context, $message ) {
        do_action(
            'pixel_trackers_manager_runtime_warning',
            sanitize_key( (string) $context ),
            sanitize_text_field( (string) $message )
        );
    }

    /**
     * Suggest transparent privacy-policy wording through WordPress' native helper.
     */
    public function register_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content = '<p><strong>Dendrila Privacy</strong> analyse localement la configuration et les contenus publics du site afin d’identifier des services tiers, des traceurs et des éléments utiles à la documentation de confidentialité. Les résultats d’audit et les réglages Dendrila Privacy sont conservés dans la base de données WordPress du site.</p>';
        $content .= '<p>Lorsque la gestion du consentement Dendrila Privacy est activée, le choix du visiteur est enregistré localement dans son navigateur. Dendrila Privacy n’envoie pas les résultats d’audit à l’éditeur du plugin et n’ajoute pas de télémétrie publicitaire.</p>';
        $content .= '<p>La recherche facultative d’une structure n’est déclenchée qu’après une action explicite d’un administrateur. Selon le pays choisi, Dendrila Privacy interroge l’API publique Recherche d’entreprises de la DINUM (France), l’API publique Enhetsregisteret de Brønnøysundregistrene (Norvège), ou VIES de la Commission européenne pour vérifier un numéro de TVA dans les autres pays pris en charge. Seuls le pays choisi et le terme ou numéro saisi sont utilisés ; aucun résultat d’audit, contenu de page, réponse de l’assistant ni choix de consentement n’est joint à la requête.</p>';

        wp_add_privacy_policy_content( 'Dendrila Privacy', wp_kses_post( $content ) );
    }

    public static function activate() {
        $settings = get_option( self::OPTION_SETTINGS, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        unset( $settings['secret'], $settings['remote_actions'] );
        $schedule = isset( $settings['schedule'] ) ? sanitize_key( (string) $settings['schedule'] ) : 'off';
        $settings['schedule'] = in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ? $schedule : 'off';
        update_option( self::OPTION_SETTINGS, $settings, false );

        // Clean up the pre-publication hook name, then restore a saved local schedule if needed.
        wp_clear_scheduled_hook( 'ptm_scheduled_scan' );
        wp_clear_scheduled_hook( 'privacy_tracker_manager_scheduled_scan' );
        if ( in_array( $settings['schedule'], array( 'daily', 'weekly' ), true ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, $settings['schedule'], self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( 'ptm_scheduled_scan' );
        delete_transient( 'pixel_trackers_manager_scan_lock' );
        delete_transient( 'ptm_scan_lock' );
        delete_transient( 'privacy_tracker_manager_scan_lock' );
    }

    public function admin_menu() {
        add_menu_page(
            'Dendrila Privacy',
            'Dendrila Privacy',
            'manage_options',
            'pixel-trackers-manager',
            array( $this, 'render_admin' ),
            plugin_dir_url( __FILE__ ) . 'assets/menu-icon.svg',
            58
        );
        add_submenu_page( 'pixel-trackers-manager', 'Vue d’ensemble', 'Vue d’ensemble', 'manage_options', 'pixel-trackers-manager', array( $this, 'render_admin' ) );
        add_submenu_page( 'pixel-trackers-manager', 'Assistant de configuration', 'Assistant de configuration', 'manage_options', 'pixel-trackers-manager-setup', array( $this, 'render_admin' ) );
        add_submenu_page( 'pixel-trackers-manager', 'Traceurs & services', 'Traceurs & services', 'manage_options', 'pixel-trackers-manager-findings', array( $this, 'render_admin' ) );
        add_submenu_page( 'pixel-trackers-manager', 'Pages légales', 'Pages légales', 'manage_options', 'pixel-trackers-manager-privacy', array( $this, 'render_admin' ) );
        add_submenu_page( 'pixel-trackers-manager', 'Assistant RGPD', 'Assistant RGPD', 'manage_options', 'pixel-trackers-manager-assistant', array( $this, 'render_admin' ) );
        add_submenu_page( 'pixel-trackers-manager', 'Consentement', 'Consentement', 'manage_options', 'pixel-trackers-manager-consent', array( $this, 'render_admin' ) );
        add_submenu_page( 'pixel-trackers-manager', 'Journal', 'Journal', 'manage_options', 'pixel-trackers-manager-journal', array( $this, 'render_admin' ) );
        add_submenu_page( 'pixel-trackers-manager', 'Réglages', 'Réglages', 'manage_options', 'pixel-trackers-manager-settings', array( $this, 'render_admin' ) );
    }

    public function admin_menu_icon_styles() {
        wp_enqueue_style(
            'pixel-trackers-manager-menu-icon',
            plugin_dir_url( __FILE__ ) . 'assets/menu-icon.css',
            array(),
            self::VERSION
        );
    }

    public function admin_assets( $hook ) {
        $page = sanitize_key( $this->query_value( 'page' ) );
        $is_ptm_page = 0 === strpos( $page, 'dendrila-privacy' );
        $is_dashboard = 'index.php' === $hook;
        if ( ! $is_ptm_page && ! $is_dashboard ) {
            return;
        }
        wp_enqueue_style(
            'pixel-trackers-manager-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.css',
            array(),
            self::VERSION
        );
        if ( ! $is_ptm_page ) {
            return;
        }
        wp_enqueue_script(
            'pixel-trackers-manager-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.js',
            array(),
            self::VERSION,
            true
        );
        wp_localize_script( 'pixel-trackers-manager-admin', 'PixelTrackersManagerScan', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'pixel_trackers_manager_scan_progress' ),
            'companyNonce' => wp_create_nonce( 'pixel_trackers_manager_company_search' ),
            'hostingNonce' => wp_create_nonce( 'pixel_trackers_manager_hosting_detect' ),
            'hostingProviders' => array_values( wp_list_pluck( $this->known_hosting_providers(), 'name' ) ),
            'hostingProfiles' => $this->hosting_profiles_for_js(),
            'sectionNonce' => wp_create_nonce( 'pixel_trackers_manager_save_legal_section' ),
            'companySource' => 'https://recherche-entreprises.api.gouv.fr/',
            'siteDefaults' => array(
                'siteName' => get_bloginfo( 'name' ),
                'siteUrl' => home_url( '/' ),
            ),
        ) );
    }


    private function onboarding_state() {
        $state = get_option( self::OPTION_ONBOARDING, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        return wp_parse_args( $state, array(
            'status' => 'new',
            'step' => 'welcome',
            'started_at' => '',
            'completed_at' => '',
            'created_pages' => array(),
        ) );
    }

    private function onboarding_allows_plugin_access() {
        $state = $this->onboarding_state();
        return in_array( isset( $state['status'] ) ? $state['status'] : 'new', array( 'completed', 'skipped' ), true );
    }

    public function maybe_redirect_first_open() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
            return;
        }
        $page = sanitize_key( $this->query_value( 'page' ) );
        if ( 0 !== strpos( $page, 'dendrila-privacy' ) || 'pixel-trackers-manager-setup' === $page ) {
            return;
        }
        if ( $this->onboarding_allows_plugin_access() ) {
            return;
        }
        // Activation itself stays silent. The guided setup appears only when the
        // administrator actually opens Dendrila Privacy for the first time.
        wp_safe_redirect( admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) );
        exit;
    }

    public function register_dashboard_setup_widget() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $state = $this->onboarding_state();
        $scan = get_option( self::OPTION_SCAN, array() );
        $has_full_scan = ! empty( $scan['generated_at'] ) && ! empty( $scan['coverage']['mode'] ) && 'full' === $scan['coverage']['mode'];
        $needs_setup = 'completed' !== ( isset( $state['status'] ) ? $state['status'] : 'new' );
        $kind = $needs_setup ? 'setup' : ( $has_full_scan ? '' : 'first-scan' );
        if ( ! $kind ) { return; }
        if ( get_user_meta( get_current_user_id(), 'pixel_trackers_manager_dashboard_dismissed_' . $kind, true ) ) { return; }
        wp_add_dashboard_widget( 'pixel_trackers_manager_setup_widget', 'Dendrila Privacy', array( $this, 'render_dashboard_setup_widget' ) );
    }

    public function handle_dashboard_widget_dismissal() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) || empty( $_GET['ptm_dashboard_dismiss'] ) ) { return; }
        $kind = sanitize_key( wp_unslash( $_GET['ptm_dashboard_dismiss'] ) );
        if ( ! in_array( $kind, array( 'setup', 'first-scan' ), true ) ) { return; }
        check_admin_referer( 'pixel_trackers_manager_dashboard_dismiss_' . $kind );
        update_user_meta( get_current_user_id(), 'pixel_trackers_manager_dashboard_dismissed_' . $kind, 1 );
        wp_safe_redirect( admin_url( 'index.php' ) );
        exit;
    }

    public function render_dashboard_setup_widget() {
        $state = $this->onboarding_state();
        $needs_setup = 'completed' !== ( isset( $state['status'] ) ? $state['status'] : 'new' );
        $kind = $needs_setup ? 'setup' : 'first-scan';
        $step = ! empty( $state['step'] ) ? sanitize_key( $state['step'] ) : 'welcome';
        $setup_url = add_query_arg( 'ptm_setup_step', $step, admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) );
        $scan_url = add_query_arg( 'pixel_trackers_manager_autostart_scan', 'full', admin_url( 'admin.php?page=pixel-trackers-manager' ) );
        $dismiss_url = wp_nonce_url( add_query_arg( 'ptm_dashboard_dismiss', $kind, admin_url( 'index.php' ) ), 'pixel_trackers_manager_dashboard_dismiss_' . $kind );
        echo '<div class="ptm-wp-dashboard-card">';
        echo '<div class="ptm-wp-dashboard-brand"><img src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/logo-mark.svg' ) . '" alt=""><div>';
        if ( $needs_setup ) {
            echo '<strong>Dendrila Privacy n’est pas encore configuré</strong><p>Terminez la configuration pour associer vos pages légales, préparer le consentement et lancer votre premier contrôle complet.</p>';
            echo '<p><a class="button button-primary" href="' . esc_url( $setup_url ) . '">Configurer Dendrila Privacy</a> <a class="ptm-dashboard-dismiss" href="' . esc_url( $dismiss_url ) . '">Masquer</a></p>';
        } else {
            echo '<strong>Votre première analyse complète est prête</strong><p>Un contrôle complet est recommandé pour établir un premier état de référence du site.</p>';
            echo '<p><a class="button button-primary" href="' . esc_url( $scan_url ) . '">Lancer l’analyse complète</a> <a class="ptm-dashboard-dismiss" href="' . esc_url( $dismiss_url ) . '">Masquer</a></p>';
        }
        echo '</div></div></div>';
    }

    private function settings() {
        $stored = get_option( self::OPTION_SETTINGS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $defaults = array(
            'privacy_page_id' => (int) get_option( 'wp_page_for_privacy_policy' ),
            'legal_notice_page_id' => 0,
            'cookie_page_id' => 0,
            'schedule' => 'off',
            'auto_sync_page' => 0,
            'scan_limit' => 20,
            'full_scan_limit' => 500,
            'adapter_overrides' => array(),
            'adapter_previous' => array(),
            'consent_enabled' => 0,
            'consent_style' => 'inherit',
            'consent_layout' => 'bar',
            'consent_retention_days' => 180,
            'consent_footer_link' => 1,
        );
        $settings = wp_parse_args( $stored, $defaults );
        $settings['privacy_page_id'] = absint( $settings['privacy_page_id'] );
        $settings['legal_notice_page_id'] = absint( $settings['legal_notice_page_id'] );
        $settings['cookie_page_id'] = absint( $settings['cookie_page_id'] );

        // Suggest likely legal pages when no explicit choice has been stored yet.
        if ( ! $settings['privacy_page_id'] ) {
            $settings['privacy_page_id'] = $this->guess_legal_page_id( 'privacy' );
        }
        if ( ! $settings['legal_notice_page_id'] ) {
            $settings['legal_notice_page_id'] = $this->guess_legal_page_id( 'legal_notice' );
        }
        if ( ! $settings['cookie_page_id'] ) {
            $settings['cookie_page_id'] = $this->guess_legal_page_id( 'cookies' );
        }

        $settings['schedule'] = in_array( $settings['schedule'], array( 'off', 'daily', 'weekly' ), true ) ? $settings['schedule'] : 'off';
        $settings['auto_sync_page'] = empty( $settings['auto_sync_page'] ) ? 0 : 1;
        $settings['scan_limit'] = max( 5, min( 50, absint( $settings['scan_limit'] ) ) );
        $settings['full_scan_limit'] = max( 50, min( 1000, absint( $settings['full_scan_limit'] ) ) );
        $settings['adapter_overrides'] = is_array( $settings['adapter_overrides'] ) ? $settings['adapter_overrides'] : array();
        $settings['adapter_previous'] = is_array( $settings['adapter_previous'] ) ? $settings['adapter_previous'] : array();
        $settings['consent_enabled'] = empty( $settings['consent_enabled'] ) ? 0 : 1;
        $settings['consent_style'] = in_array( $settings['consent_style'], array( 'inherit', 'neutral' ), true ) ? $settings['consent_style'] : 'inherit';
        $settings['consent_layout'] = in_array( isset( $settings['consent_layout'] ) ? $settings['consent_layout'] : 'bar', array( 'bar', 'card' ), true ) ? $settings['consent_layout'] : 'bar';
        $settings['consent_retention_days'] = max( 30, min( 365, absint( $settings['consent_retention_days'] ) ) );
        $settings['consent_footer_link'] = empty( $settings['consent_footer_link'] ) ? 0 : 1;
        return $settings;
    }


    private function legal_page_detection_patterns( $kind ) {
        $kind = sanitize_key( $kind );
        $patterns = array(
            'privacy' => array(
                'title' => array( 'politique de confidentialite', 'confidentialite', 'vie privee', 'privacy policy' ),
                'content' => array( 'donnees personnelles', 'responsable du traitement', 'exercer vos droits', 'droit d acces', 'droit de rectification', 'cnil' ),
                'shortcode' => '[dendrila_privacy_privacy_policy]',
            ),
            'legal_notice' => array(
                'title' => array( 'mentions legales', 'mention legale', 'legal notice', 'impressum' ),
                'content' => array( 'editeur du site', 'responsable de publication', 'hebergeur', 'siret', 'siren', 'directeur de la publication' ),
                'shortcode' => '[dendrila_privacy_legal_notice]',
            ),
            'cookies' => array(
                'title' => array( 'politique de cookies', 'cookies', 'traceurs', 'cookie policy', 'gestion du consentement' ),
                'content' => array( 'cookies', 'traceurs', 'consentement', 'gerer mes choix', 'tout refuser', 'mesure d audience' ),
                'shortcode' => '[dendrila_privacy_cookies]',
            ),
        );
        return isset( $patterns[ $kind ] ) ? $patterns[ $kind ] : array();
    }

    private function legal_page_candidates( $kind ) {
        $kind = sanitize_key( $kind );
        if ( isset( $this->legal_page_candidate_cache[ $kind ] ) ) {
            return $this->legal_page_candidate_cache[ $kind ];
        }
        $patterns = $this->legal_page_detection_patterns( $kind );
        if ( ! $patterns ) {
            return array();
        }

        $page_ids = get_posts( array(
            'post_type' => 'page',
            'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
            'numberposts' => 250,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'suppress_filters' => false,
        ) );
        $candidates = array();

        foreach ( $page_ids as $page_id ) {
            $post = get_post( $page_id );
            if ( ! $post ) {
                continue;
            }
            $title_hay = $this->normalize_text( $post->post_title . ' ' . $post->post_name );
            $score = 0;
            $reasons = array();

            foreach ( (array) $patterns['title'] as $needle ) {
                if ( false !== strpos( $title_hay, $this->normalize_text( $needle ) ) ) {
                    $score += 30;
                    $reasons[] = 'titre';
                }
            }

            if ( 'privacy' === $kind && (int) get_option( 'wp_page_for_privacy_policy' ) === (int) $page_id ) {
                $score += 100;
                $reasons[] = 'page de confidentialité WordPress';
            }

            $raw = (string) $post->post_content;
            if ( ! empty( $patterns['shortcode'] ) && false !== strpos( $raw, (string) $patterns['shortcode'] ) ) {
                $score += 120;
                $reasons[] = 'code court Dendrila Privacy';
            }

            // Builder content is read locally without rendering the public page. This
            // prevents PTM from proposing a duplicate legal page merely because a crawl
            // could not render an Elementor/Divi page correctly.
            $content_parts = array( (string) $post->post_content );
            $builder = $this->page_builder_info( $page_id );
            if ( 'elementor' === $builder['id'] ) {
                $elementor_data = get_post_meta( $page_id, '_elementor_data', true );
                $decoded = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : ( is_array( $elementor_data ) ? $elementor_data : array() );
                if ( is_array( $decoded ) ) {
                    $parts = array();
                    $this->collect_elementor_text_fragments( $decoded, $parts );
                    if ( $parts ) {
                        $content_parts[] = implode( "\n", array_values( $parts ) );
                    }
                }
            } elseif ( in_array( $builder['id'], array( 'divi', 'divi5' ), true ) ) {
                $divi_parts = $this->extract_divi_text_fragments( (string) $post->post_content );
                if ( $divi_parts ) {
                    $content_parts[] = implode( "\n", $divi_parts );
                }
            }
            $content = $this->normalize_text( implode( "\n", $content_parts ) );
            $content_hits = 0;
            foreach ( (array) $patterns['content'] as $needle ) {
                if ( false !== strpos( $content, $this->normalize_text( $needle ) ) ) {
                    $content_hits++;
                }
            }
            if ( $content_hits ) {
                $score += min( 45, $content_hits * 9 );
                $reasons[] = 'contenu';
            }

            // One generic legal/privacy word is not enough to block page creation.
            // A probable page needs either a title/WordPress/shortcode signal or at
            // least two meaningful content clues.
            if ( $score >= 18 ) {
                $candidates[] = array(
                    'page_id' => (int) $page_id,
                    'score' => $score,
                    'status' => (string) $post->post_status,
                    'title' => $post->post_title ? (string) $post->post_title : '(sans titre)',
                    'builder' => $this->page_builder_info( $page_id ),
                    'reasons' => array_values( array_unique( $reasons ) ),
                );
            }
        }

        usort( $candidates, function ( $a, $b ) {
            if ( (int) $a['score'] === (int) $b['score'] ) {
                return (int) $b['page_id'] <=> (int) $a['page_id'];
            }
            return (int) $b['score'] <=> (int) $a['score'];
        } );
        $this->legal_page_candidate_cache[ $kind ] = $candidates;
        return $candidates;
    }

    private function guess_legal_page_id( $kind ) {
        $candidates = $this->legal_page_candidates( $kind );
        return ! empty( $candidates[0]['page_id'] ) ? (int) $candidates[0]['page_id'] : 0;
    }

    private function legal_page_ids( $settings = null ) {
        $settings = is_array( $settings ) ? $settings : $this->settings();
        return array_values( array_unique( array_filter( array(
            ! empty( $settings['legal_notice_page_id'] ) ? absint( $settings['legal_notice_page_id'] ) : 0,
            ! empty( $settings['privacy_page_id'] ) ? absint( $settings['privacy_page_id'] ) : 0,
            ! empty( $settings['cookie_page_id'] ) ? absint( $settings['cookie_page_id'] ) : 0,
        ) ) ) );
    }

    private function legal_document_config( $kind, $settings = null ) {
        $settings = is_array( $settings ) ? $settings : $this->settings();
        $map = array(
            'legal_notice' => array(
                'label' => 'Mentions légales',
                'setting' => 'legal_notice_page_id',
                'shortcode' => '[dendrila_privacy_legal_notice]',
            ),
            'privacy' => array(
                'label' => 'Politique de confidentialité',
                'setting' => 'privacy_page_id',
                'shortcode' => '[dendrila_privacy_privacy_policy]',
            ),
            'cookies' => array(
                'label' => 'Cookies et traceurs',
                'setting' => 'cookie_page_id',
                'shortcode' => '[dendrila_privacy_cookies]',
            ),
        );
        if ( ! isset( $map[ $kind ] ) ) { return array(); }
        $cfg = $map[ $kind ];
        $cfg['page_id'] = ! empty( $settings[ $cfg['setting'] ] ) ? absint( $settings[ $cfg['setting'] ] ) : 0;
        $cfg['builder'] = $cfg['page_id'] ? $this->page_builder_info( $cfg['page_id'] ) : array( 'id'=>'wordpress','label'=>'Éditeur WordPress','safe_mode'=>false );
        $cfg['edit_url'] = $cfg['page_id'] ? $this->legal_page_edit_url( $cfg['page_id'] ) : '';
        return $cfg;
    }

    private function legal_page_edit_url( $page_id ) {
        $page_id = absint( $page_id );
        if ( ! $page_id ) { return ''; }
        $builder = $this->page_builder_info( $page_id );
        if ( 'elementor' === $builder['id'] ) {
            return admin_url( 'post.php?post=' . $page_id . '&action=elementor' );
        }
        if ( in_array( $builder['id'], array( 'divi', 'divi5' ), true ) ) {
            $url = get_permalink( $page_id );
            return $url ? add_query_arg( array( 'et_fb'=>1, 'PageSpeed'=>'off' ), $url ) : get_edit_post_link( $page_id, 'raw' );
        }
        return get_edit_post_link( $page_id, 'raw' );
    }

    private function document_shortcode( $kind ) {
        $cfg = $this->legal_document_config( $kind );
        return ! empty( $cfg['shortcode'] ) ? $cfg['shortcode'] : '';
    }

    private function document_html( $kind, $profile ) {
        if ( 'legal_notice' === $kind ) { return $this->generated_legal_notice( $profile ); }
        if ( 'cookies' === $kind ) { return $this->generated_cookie_policy( $profile ); }
        return $this->generated_legal_block( $profile );
    }

    /**
     * Return the explicit public snapshot registry.
     *
     * Legal shortcodes must not silently change a published page every time an
     * administrator edits the assistant or a new scan finds a service. PTM therefore
     * stores the last version explicitly validated through a publication/update action.
     */
    private function public_documents() {
        $documents = get_option( self::OPTION_PUBLIC_DOCUMENTS, array() );
        return is_array( $documents ) ? $documents : array();
    }

    private function document_source_hash( $kind, $profile = null ) {
        $profile = is_array( $profile ) ? $profile : wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        return hash( 'sha256', (string) $this->document_html( $kind, $profile ) );
    }

    private function approve_public_document( $kind, $profile = null ) {
        $kind = sanitize_key( $kind );
        if ( ! in_array( $kind, array( 'legal_notice', 'privacy', 'cookies' ), true ) ) { return false; }
        $profile = is_array( $profile ) ? $profile : wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        $html = (string) $this->document_html( $kind, $profile );
        if ( '' === trim( $html ) ) { return false; }
        $documents = $this->public_documents();
        $documents[ $kind ] = array(
            'html' => $html,
            'source_hash' => hash( 'sha256', $html ),
            'approved_at' => gmdate( 'c' ),
            'approved_by' => get_current_user_id(),
        );
        update_option( self::OPTION_PUBLIC_DOCUMENTS, $documents, false );
        return true;
    }

    private function public_document_is_outdated( $kind, $profile = null ) {
        $documents = $this->public_documents();
        if ( empty( $documents[ $kind ]['html'] ) || empty( $documents[ $kind ]['source_hash'] ) ) { return false; }
        return ! hash_equals( (string) $documents[ $kind ]['source_hash'], $this->document_source_hash( $kind, $profile ) );
    }

    private function public_document_html( $kind, $profile ) {
        $page_id = absint( get_the_ID() );
        $post = $page_id ? get_post( $page_id ) : null;
        $documents = $this->public_documents();
        if ( $post && 'publish' === $post->post_status && ! empty( $documents[ $kind ]['html'] ) ) {
            return (string) $documents[ $kind ]['html'];
        }
        return (string) $this->document_html( $kind, $profile );
    }

    private function document_shortcode_present( $page_id, $kind ) {
        $page_id = absint( $page_id );
        $shortcode = $this->document_shortcode( $kind );
        if ( ! $page_id || ! $shortcode ) { return false; }
        $post = get_post( $page_id );
        if ( $post && false !== strpos( (string) $post->post_content, $shortcode ) ) { return true; }
        if ( 'elementor' === $this->page_builder_info( $page_id )['id'] ) {
            $raw = get_post_meta( $page_id, '_elementor_data', true );
            if ( is_array( $raw ) ) { $raw = wp_json_encode( $raw ); }
            return false !== strpos( (string) $raw, $shortcode );
        }
        return false;
    }


    private function legal_page_titles() {
        return array(
            'legal_notice' => 'Mentions légales',
            'privacy' => 'Politique de confidentialité',
            'cookies' => 'Cookies et traceurs',
        );
    }

    private function site_builder_preference() {
        $theme = wp_get_theme();
        $theme_name = strtolower( (string) $theme->get( 'Name' ) );
        $template = strtolower( (string) $theme->get_template() );
        $stylesheet = strtolower( (string) $theme->get_stylesheet() );

        // Divi and Extra both ship the Divi Builder. Prefer the active theme signal over
        // merely having another builder plugin installed: it reflects how new pages are
        // most likely to be authored on this site.
        if ( in_array( $template, array( 'divi', 'extra' ), true ) || in_array( $stylesheet, array( 'divi', 'extra' ), true ) || false !== strpos( $theme_name, 'divi' ) || 'extra' === $theme_name ) {
            $theme_version = (string) $theme->get( 'Version' );
            // Divi 5 stores native builder blocks. Older Divi/Extra installs still use
            // the shortcode layout format, so do not write D5 blocks into a D4 site.
            if ( 'divi' === $template && $theme_version && version_compare( $theme_version, '5.0.0', '>=' ) ) { return 'divi5'; }
            return 'divi';
        }

        $plugins = $this->installed_plugins();
        $elementor_active = false;
        foreach ( $plugins as $plugin ) {
            if ( empty( $plugin['active'] ) ) { continue; }
            $slug = isset( $plugin['slug'] ) ? (string) $plugin['slug'] : '';
            if ( 'elementor' === $slug ) {
                $elementor_active = true;
                break;
            }
        }

        if ( $elementor_active && ( false !== strpos( $theme_name, 'hello elementor' ) || 'hello-elementor' === $template || 'hello-elementor' === $stylesheet ) ) {
            return 'elementor';
        }

        // When legal pages already use one builder consistently, follow that local
        // convention. This is safer on mixed sites than guessing from installed plugins.
        $settings = $this->settings();
        $counts = array( 'elementor'=>0, 'divi5'=>0 );
        foreach ( $this->legal_page_ids( $settings ) as $page_id ) {
            $builder = $this->page_builder_info( $page_id );
            if ( 'elementor' === $builder['id'] ) { $counts['elementor']++; }
            if ( in_array( $builder['id'], array( 'divi', 'divi5' ), true ) ) { $counts['divi5']++; }
        }
        if ( $counts['elementor'] > $counts['divi5'] && $elementor_active ) { return 'elementor'; }
        if ( $counts['divi5'] > $counts['elementor'] ) { return 'divi5'; }

        // Elementor Free is a strong enough signal only when no Divi theme is active.
        if ( $elementor_active ) { return 'elementor'; }
        return 'wordpress';
    }

    private function divi5_legal_page_content( $shortcode ) {
        $shortcode = trim( (string) $shortcode );
        $text_attrs = array( 'content'=>array( 'innerContent'=>array( 'desktop'=>array( 'value'=>$shortcode ) ) ) );
        $section_attrs = array( 'module'=>array( 'meta'=>array( 'adminLabel'=>array( 'desktop'=>array( 'value'=>'section' ) ) ) ) );
        $row_attrs = array( 'module'=>array( 'meta'=>array( 'adminLabel'=>array( 'desktop'=>array( 'value'=>'row' ) ) ) ) );
        $column_attrs = array( 'module'=>array( 'advanced'=>array( 'type'=>array( 'desktop'=>array( 'value'=>'4_4' ) ) ) ) );
        return '<!-- wp:divi/placeholder -->' . "\n"
            . '<!-- wp:divi/section ' . wp_json_encode( $section_attrs ) . ' -->' . "\n"
            . '<!-- wp:divi/row ' . wp_json_encode( $row_attrs ) . ' -->' . "\n"
            . '<!-- wp:divi/column ' . wp_json_encode( $column_attrs ) . ' -->' . "\n"
            . '<!-- wp:divi/text ' . wp_json_encode( $text_attrs ) . ' /-->' . "\n"
            . '<!-- /wp:divi/column -->' . "\n"
            . '<!-- /wp:divi/row -->' . "\n"
            . '<!-- /wp:divi/section -->' . "\n"
            . '<!-- /wp:divi/placeholder -->';
    }

    private function divi4_legal_page_content( $shortcode ) {
        return '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]' . trim( (string) $shortcode ) . '[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
    }

    private function create_elementor_legal_page( $title, $shortcode ) {
        if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) || ! method_exists( \Elementor\Plugin::$instance->documents, 'create' ) ) {
            return new WP_Error( 'pixel_trackers_manager_elementor_unavailable', 'Elementor est détecté mais son API de création de page n’est pas disponible.' );
        }
        try {
            $document = \Elementor\Plugin::$instance->documents->create( 'wp-page', array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_excerpt' => 'Page créée par Dendrila Privacy. À relire avant publication.',
            ) );
            if ( is_wp_error( $document ) ) { return $document; }
            if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! method_exists( $document, 'save' ) ) {
                return new WP_Error( 'pixel_trackers_manager_elementor_document', 'Elementor n’a pas renvoyé un document éditable.' );
            }

            $widget_id = class_exists( '\Elementor\Utils' ) ? \Elementor\Utils::generate_random_string() : substr( wp_generate_uuid4(), 0, 8 );
            $container_id = class_exists( '\Elementor\Utils' ) ? \Elementor\Utils::generate_random_string() : substr( wp_generate_uuid4(), 0, 8 );
            $column_id = class_exists( '\Elementor\Utils' ) ? \Elementor\Utils::generate_random_string() : substr( wp_generate_uuid4(), 0, 8 );
            $widget = array(
                'id' => $widget_id,
                'elType' => 'widget',
                'widgetType' => 'text-editor',
                'settings' => array( 'editor' => $shortcode ),
                'elements' => array(),
            );
            $use_container = isset( \Elementor\Plugin::$instance->experiments ) && method_exists( \Elementor\Plugin::$instance->experiments, 'is_feature_active' ) && \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' );
            if ( $use_container ) {
                $elements = array( array(
                    'id' => $container_id,
                    'elType' => 'container',
                    'settings' => array(),
                    'elements' => array( $widget ),
                    'isInner' => false,
                ) );
            } else {
                $elements = array( array(
                    'id' => $container_id,
                    'elType' => 'section',
                    'settings' => array(),
                    'elements' => array( array(
                        'id' => $column_id,
                        'elType' => 'column',
                        'settings' => array( '_column_size'=>100, '_inline_size'=>null ),
                        'elements' => array( $widget ),
                        'isInner' => false,
                    ) ),
                    'isInner' => false,
                ) );
            }
            $saved = $document->save( array( 'elements'=>$elements ) );
            if ( false === $saved ) {
                wp_delete_post( (int) $document->get_main_id(), true );
                return new WP_Error( 'pixel_trackers_manager_elementor_save', 'Elementor n’a pas pu enregistrer la structure de la page.' );
            }
            return (int) $document->get_main_id();
        } catch ( Throwable $e ) {
            return new WP_Error( 'pixel_trackers_manager_elementor_exception', 'Elementor a refusé la création de la page : ' . $e->getMessage() );
        }
    }

    private function create_legal_page( $kind ) {
        $kind = sanitize_key( $kind );
        $cfg = $this->legal_document_config( $kind, $this->settings() );
        if ( empty( $cfg['setting'] ) || empty( $cfg['shortcode'] ) ) {
            return new WP_Error( 'pixel_trackers_manager_bad_legal_kind', 'Type de page juridique inconnu.' );
        }

        unset( $this->legal_page_candidate_cache[ $kind ] );
        $candidates = $this->legal_page_candidates( $kind );
        if ( $candidates ) {
            $first = $candidates[0];
            return new WP_Error(
                'pixel_trackers_manager_existing_legal_page',
                'Une page existante semble déjà correspondre à « ' . $cfg['label'] . ' » : ' . $first['title'] . '. Sélectionnez-la plutôt que de créer un doublon.'
            );
        }

        $titles = $this->legal_page_titles();
        $title = isset( $titles[ $kind ] ) ? $titles[ $kind ] : $cfg['label'];
        $builder = $this->site_builder_preference();
        $page_id = 0;

        if ( 'elementor' === $builder ) {
            $page_id = $this->create_elementor_legal_page( $title, $cfg['shortcode'] );
        } elseif ( 'divi' === $builder ) {
            $page_id = wp_insert_post( array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_content' => $this->divi4_legal_page_content( $cfg['shortcode'] ),
                'post_excerpt' => 'Page créée par Dendrila Privacy. À relire avant publication.',
                'meta_input' => array(
                    '_et_pb_use_builder' => 'on',
                    '_et_pb_page_layout' => 'et_full_width_page',
                    '_et_pb_built_for_post_type' => 'page',
                ),
            ), true );
        } elseif ( 'divi5' === $builder ) {
            $page_id = wp_insert_post( array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_content' => $this->divi5_legal_page_content( $cfg['shortcode'] ),
                'post_excerpt' => 'Page créée par Dendrila Privacy. À relire avant publication.',
                'meta_input' => array(
                    '_et_pb_use_builder' => 'on',
                    '_et_pb_page_layout' => 'et_full_width_page',
                    '_et_pb_built_for_post_type' => 'page',
                ),
            ), true );
        } else {
            $page_id = wp_insert_post( array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_content' => $cfg['shortcode'],
                'post_excerpt' => 'Page créée par Dendrila Privacy. À relire avant publication.',
            ), true );
        }

        if ( is_wp_error( $page_id ) ) {
            // Builder integration must never make page creation impossible. Fall back to
            // the WordPress editor while clearly recording which path was used.
            $page_id = wp_insert_post( array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_content' => $cfg['shortcode'],
                'post_excerpt' => 'Page créée par Dendrila Privacy. À relire avant publication.',
            ), true );
            $builder = 'wordpress-fallback';
        }
        if ( is_wp_error( $page_id ) ) { return $page_id; }

        update_post_meta( (int) $page_id, '_pixel_trackers_manager_generated_legal_page', $kind );
        update_post_meta( (int) $page_id, '_pixel_trackers_manager_generated_builder', $builder );
        $settings = $this->settings();
        $settings[ $cfg['setting'] ] = (int) $page_id;
        $this->update_settings( $settings );
        if ( 'privacy' === $kind ) {
            update_option( 'wp_page_for_privacy_policy', (int) $page_id );
        }
        $this->legal_page_candidate_cache = array();
        $this->log_action( 'create_legal_page', 'setup', array( 'page_id'=>(int)$page_id, 'kind'=>$kind, 'status'=>'draft', 'builder'=>$builder ) );
        return (int) $page_id;
    }

    private function setup_page_select( $kind, $selected_id ) {
        $kind = sanitize_key( $kind );
        $selected_id = absint( $selected_id );
        $candidates = $this->legal_page_candidates( $kind );
        $candidate_ids = array();
        foreach ( $candidates as $candidate ) {
            $candidate_ids[ (int) $candidate['page_id'] ] = $candidate;
        }

        $page_ids = get_posts( array(
            'post_type' => 'page',
            'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
            'numberposts' => 250,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
            'suppress_filters' => false,
        ) );

        echo '<select name="setup_page[' . esc_attr( $kind ) . ']" class="ptm-page-select ptm-setup-page-select">';
        echo '<option value="0">— Je choisirai plus tard —</option>';
        foreach ( $page_ids as $page_id ) {
            $post = get_post( $page_id );
            if ( ! $post ) { continue; }
            $label = $post->post_title ? $post->post_title : '(sans titre)';
            if ( isset( $candidate_ids[ (int) $page_id ] ) ) {
                $label = '★ ' . $label . ' — probable';
            }
            if ( 'publish' !== $post->post_status ) {
                $label .= ' [' . $post->post_status . ']';
            }
            echo '<option value="' . esc_attr( $page_id ) . '" ' . selected( $selected_id, $page_id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    private function topic_has_profile_data( $topic_id, $profile ) {
        $p = wp_parse_args( is_array($profile) ? $profile : array(), $this->legal_profile_defaults() );
        $map = array(
            'identity' => array( 'controller_name','controller_address' ),
            'contact' => array( 'privacy_contact','dpo_contact' ),
            'representative' => array( 'representative_applicable','controller_representative' ),
            'purposes' => array( 'treatments' ),
            'legal_basis' => array( 'treatments' ),
            'data_categories' => array( 'treatments' ),
            'retention' => array( 'treatments' ),
            'recipients' => array( 'treatments','indirect_services' ),
            'rights' => array( 'privacy_contact' ),
            'withdrawal' => array( 'cookie_preferences','email_pixel_preferences' ),
            'complaint' => array( 'supervisory_authority','supervisory_url' ),
            'mandatory' => array( 'mandatory_information' ),
            'transfers' => array( 'transfers_status','indirect_services' ),
            'source' => array( 'collection_mode','indirect_services','indirect_sources' ),
            'automated' => array( 'automated_decision','indirect_services' ),
            'special_categories' => array( 'special_categories' ),
            'criminal_data' => array( 'criminal_data' ),
            'minors' => array( 'minors_data' ),
            'cookies' => array( 'cookie_nonessential','cookie_preferences','cookie_choice_retention' ),
            'email_pixels' => array( 'email_pixels','tracked_links' ),
        );
        foreach ( isset($map[$topic_id]) ? $map[$topic_id] : array() as $field ) {
            if ( ! isset( $p[$field] ) ) { continue; }
            $value = $p[$field];
            if ( is_array($value) && !empty($value) ) { return true; }
            if ( is_string($value) && '' !== trim($value) && 'unknown' !== $value ) { return true; }
        }
        return false;
    }

    private function finding_evidence_status( $finding ) {
        $state = isset($finding['tracking_state']) ? (string)$finding['tracking_state'] : 'potential';
        if ( isset( $finding['is_tracking'] ) && ! $finding['is_tracking'] && ! empty( $finding['observed_html'] ) ) { return array( 'label'=>'Ressource externe active', 'tone'=>'warn' ); }
        if ( 'disabled' === $state ) { return array( 'label'=>'Désactivé', 'tone'=>'good' ); }
        if ( 'blocked' === $state ) { return array( 'label'=>'Bloqué avant choix', 'tone'=>'good' ); }
        if ( !empty($finding['observed_html']) || 'technical_html' === ( isset($finding['tracking_basis']) ? $finding['tracking_basis'] : '' ) ) {
            return array( 'label'=>'Confirmé techniquement', 'tone'=>'bad' );
        }
        if ( 'plugin_setting' === ( isset($finding['tracking_basis']) ? $finding['tracking_basis'] : '' ) ) {
            return array( 'label'=>'Détecté dans la configuration', 'tone'=>'neutral' );
        }
        if ( !empty($finding['plugin_sources']) || !empty($finding['code_reference']) ) {
            return array( 'label'=>'Probable', 'tone'=>'warn' );
        }
        return array( 'label'=>'À vérifier', 'tone'=>'neutral' );
    }

    private function analytics_integration_hint( $finding ) {
        if ( 'google-analytics' !== ( isset($finding['id']) ? $finding['id'] : '' ) ) { return array(); }
        $slugs = array();
        foreach ( (array)( isset($finding['plugin_sources']) ? $finding['plugin_sources'] : array() ) as $source ) {
            if ( !empty($source['slug']) ) { $slugs[] = $source['slug']; }
        }
        $hints = array(
            'google-site-kit' => array( 'label'=>'Google Site Kit', 'url'=>admin_url('admin.php?page=googlesitekit-settings') ),
            'google-analytics-for-wordpress' => array( 'label'=>'MonsterInsights', 'url'=>admin_url('admin.php?page=monsterinsights_settings') ),
            'monsterinsights' => array( 'label'=>'MonsterInsights', 'url'=>admin_url('admin.php?page=monsterinsights_settings') ),
            'exactmetrics-premium' => array( 'label'=>'ExactMetrics', 'url'=>admin_url('admin.php?page=exactmetrics_settings') ),
            'woocommerce-google-analytics-integration' => array( 'label'=>'WooCommerce Google Analytics', 'url'=>admin_url('admin.php?page=wc-settings&tab=integration') ),
            'seo-by-rank-math' => array( 'label'=>'Rank Math', 'url'=>admin_url('admin.php?page=rank-math-analytics') ),
        );
        foreach ( $hints as $slug=>$hint ) {
            if ( in_array($slug,$slugs,true) ) { return $hint; }
        }
        $theme = wp_get_theme();
        $names = array( strtolower((string)$theme->get('Name')), strtolower((string)$theme->get_template()), strtolower((string)$theme->get_stylesheet()) );
        if ( in_array('divi',$names,true) || function_exists('et_setup_theme') ) {
            return array( 'label'=>'Divi', 'url'=>admin_url('admin.php?page=et_divi_options') );
        }
        return array();
    }

    private function sync_legal_document( $page_id, $kind ) {
        $page_id = absint( $page_id );
        $post = $page_id ? get_post( $page_id ) : null;
        if ( !$post || 'page' !== $post->post_type ) { return new WP_Error('ptm_document_page','Page invalide.'); }
        $builder = $this->page_builder_info( $page_id );
        $profile = wp_parse_args( get_option(self::OPTION_LEGAL_PROFILE,array()), $this->legal_profile_defaults() );
        if ( $this->document_shortcode_present( $page_id, $kind ) ) {
            if ( ! $this->approve_public_document( $kind, $profile ) ) {
                return new WP_Error( 'ptm_document_snapshot', 'Le contenu public ne peut pas être validé tant que le document est vide.' );
            }
            $this->log_action( 'approve_legal_document', 'manual-shortcode', array( 'page_id'=>$page_id, 'kind'=>$kind, 'builder'=>$builder['id'] ) );
            return true;
        }
        if ( !empty($builder['safe_mode']) ) {
            return new WP_Error('ptm_builder_manual','Cette page utilise un constructeur de pages. Utilisez « Insérer automatiquement dans cette page » ou copiez le code court proposé.');
        }
        $html = $this->document_html( $kind, $profile );
        $token = strtoupper( sanitize_key($kind) );
        $start = '<!-- PTM:DOC:' . $token . ':START -->';
        $end = '<!-- PTM:DOC:' . $token . ':END -->';
        $block = $start . "\n" . $html . "\n" . $end;
        $content = (string)$post->post_content;
        $pattern = '/'.preg_quote($start,'/').'.*?'.preg_quote($end,'/').'/s';
        $content = preg_match($pattern,$content) ? preg_replace($pattern,$block,$content) : rtrim($content)."\n\n".$block;
        $result = wp_update_post( array( 'ID'=>$page_id, 'post_content'=>$content ), true );
        if ( is_wp_error($result) ) { return $result; }
        $this->approve_public_document( $kind, $profile );
        $this->log_action('sync_legal_document','manual',array('page_id'=>$page_id,'kind'=>$kind));
        return true;
    }

    private function inject_builder_shortcode( $page_id, $kind ) {
        $page_id = absint( $page_id );
        $shortcode = $this->document_shortcode( $kind );
        if ( !$page_id || !$shortcode ) { return new WP_Error('ptm_builder_inject','Page ou document invalide.'); }
        $builder = $this->page_builder_info( $page_id );

        if ( 'elementor' === $builder['id'] ) {
            $raw = get_post_meta( $page_id, '_elementor_data', true );
            $data = is_string($raw) ? json_decode($raw,true) : ( is_array($raw) ? $raw : array() );
            if ( !is_array($data) ) { return new WP_Error('ptm_elementor_data','Les données Elementor ne peuvent pas être lues.'); }
            if ( false !== strpos( (string)$raw, $shortcode ) ) { $this->approve_public_document( $kind ); return true; }
            $id1 = substr(str_replace('-','',wp_generate_uuid4()),0,7);
            $id2 = substr(str_replace('-','',wp_generate_uuid4()),0,7);
            $data[] = array(
                'id'=>$id1, 'elType'=>'container', 'settings'=>array(), 'elements'=>array(
                    array( 'id'=>$id2, 'elType'=>'widget', 'widgetType'=>'shortcode', 'settings'=>array( 'shortcode'=>$shortcode ), 'elements'=>array() )
                ), 'isInner'=>false
            );
            update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ) );
            update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
            try {
                if ( class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager) ) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            } catch ( Throwable $e ) {
                $this->runtime_warning( 'elementor-cache', $e->getMessage() );
            }
            $this->approve_public_document( $kind );
            $this->log_action('inject_shortcode','elementor',array('page_id'=>$page_id,'kind'=>$kind,'shortcode'=>$shortcode));
            return true;
        }

        if ( 'divi' === $builder['id'] ) {
            $content = (string)get_post_field('post_content',$page_id);
            if ( false !== strpos($content,$shortcode) ) { $this->approve_public_document( $kind ); return true; }
            $module = "\n[et_pb_section admin_label=\"Dendrila Privacy\"][et_pb_row][et_pb_column type=\"4_4\"][et_pb_text admin_label=\"Dendrila Privacy\"]".$shortcode."[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]\n";
            $result = wp_update_post( array( 'ID'=>$page_id, 'post_content'=>rtrim($content).$module ), true );
            if ( is_wp_error($result) ) { return $result; }
            update_post_meta( $page_id, '_et_pb_use_builder', 'on' );
            $this->approve_public_document( $kind );
            $this->log_action('inject_shortcode','divi',array('page_id'=>$page_id,'kind'=>$kind,'shortcode'=>$shortcode));
            return true;
        }

        if ( 'divi5' === $builder['id'] ) {
            if ( $this->document_shortcode_present( $page_id, $kind ) ) { $this->approve_public_document( $kind ); return true; }
            return new WP_Error('ptm_divi5_inject','Cette structure Divi 5 existante ne contient pas encore le code court Dendrila Privacy. Ouvrez Divi et insérez le code court proposé.');
        }

        return $this->sync_legal_document( $page_id, $kind );
    }

    private function update_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        update_option( self::OPTION_SETTINGS, $settings, false );
        $this->sync_cron_schedule( isset( $settings['schedule'] ) ? $settings['schedule'] : 'off' );
    }

    private function sync_cron_schedule( $schedule ) {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        if ( 'daily' === $schedule || 'weekly' === $schedule ) {
            wp_schedule_event( time() + 300, $schedule, self::CRON_HOOK );
        }
    }

    public function run_scheduled_scan() {
        $settings = $this->settings();
        $scan = $this->run_scan( 'cron' );
        if ( ! is_wp_error( $scan ) && ! empty( $settings['auto_sync_page'] ) && ! empty( $settings['privacy_page_id'] ) ) {
            $this->sync_privacy_page( (int) $settings['privacy_page_id'], 'cron' );
        }
    }

    public function handle_admin_actions() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['pixel_trackers_manager_action'] ) ) {
            return;
        }

        check_admin_referer( 'pixel_trackers_manager_admin_action', 'pixel_trackers_manager_nonce' );
        $action = sanitize_key( wp_unslash( $_POST['pixel_trackers_manager_action'] ) );
        $message = '';
        $type = 'success';
        $redirect_override = '';

        if ( 'setup_begin' === $action ) {
            $state = $this->onboarding_state();
            $state['status'] = 'started';
            $state['step'] = 'pages';
            if ( empty( $state['started_at'] ) ) {
                $state['started_at'] = gmdate( 'c' );
            }
            update_option( self::OPTION_ONBOARDING, $state, false );
            $message = 'Assistant démarré.';
            $redirect_override = add_query_arg( 'ptm_setup_step', 'pages', admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) );
        } elseif ( 'setup_save_pages' === $action ) {
            $settings = $this->settings();
            $created = array();
            $errors = array();
            $selection = array_map( 'absint', $this->verified_post_array( 'setup_page' ) );
            $create = array_map( 'absint', $this->verified_post_array( 'setup_create' ) );

            foreach ( array( 'legal_notice', 'privacy', 'cookies' ) as $kind ) {
                $cfg = $this->legal_document_config( $kind, $settings );
                if ( ! empty( $create[ $kind ] ) ) {
                    $result = $this->create_legal_page( $kind );
                    if ( is_wp_error( $result ) ) {
                        $errors[] = $result->get_error_message();
                    } else {
                        $created[ $kind ] = (int) $result;
                        $settings[ $cfg['setting'] ] = (int) $result;
                    }
                    continue;
                }

                $page_id = isset( $selection[ $kind ] ) ? absint( $selection[ $kind ] ) : 0;
                if ( $page_id ) {
                    $post = get_post( $page_id );
                    if ( ! $post || 'page' !== $post->post_type ) {
                        $errors[] = 'La page choisie pour « ' . $cfg['label'] . ' » n’est pas valide.';
                        continue;
                    }
                }
                $settings[ $cfg['setting'] ] = $page_id;
                if ( 'privacy' === $kind ) {
                    update_option( 'wp_page_for_privacy_policy', $page_id );
                }
            }
            $this->update_settings( $settings );

            // Keep onboarding instantaneous: selecting or creating a page must not
            // launch a hidden HTTP audit. The first explicit scan will refresh the audit.
            $settings = $this->settings();

            $state = $this->onboarding_state();
            $state['status'] = 'started';
            $state['step'] = 'consent';
            $state['created_pages'] = array_merge( (array) $state['created_pages'], $created );
            update_option( self::OPTION_ONBOARDING, $state, false );

            $message = $errors ? implode( ' ', $errors ) : 'Pages de référence enregistrées.';
            $type = $errors ? 'warning' : 'success';
            $redirect_override = add_query_arg( 'ptm_setup_step', 'consent', admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) );
        } elseif ( 'setup_save_consent' === $action ) {
            $settings = $this->settings();
            $choice = isset( $_POST['setup_consent'] ) ? sanitize_key( wp_unslash( $_POST['setup_consent'] ) ) : 'later';
            $third_party_cmps = $this->public_cmp_names();
            if ( 'ptm' === $choice && ! $third_party_cmps ) {
                $settings['consent_enabled'] = 1;
            } elseif ( 'existing' === $choice && $third_party_cmps ) {
                $settings['consent_enabled'] = 0;
            } else {
                $settings['consent_enabled'] = 0;
            }
            $layout = isset( $_POST['consent_layout'] ) ? sanitize_key( wp_unslash( $_POST['consent_layout'] ) ) : 'bar';
            $settings['consent_layout'] = in_array( $layout, array( 'bar', 'card' ), true ) ? $layout : 'bar';
            $style = isset( $_POST['consent_style'] ) ? sanitize_key( wp_unslash( $_POST['consent_style'] ) ) : 'inherit';
            $settings['consent_style'] = in_array( $style, array( 'inherit', 'neutral' ), true ) ? $style : 'inherit';
            $this->update_settings( $settings );
            $state = $this->onboarding_state();
            $state['status'] = 'started';
            $state['step'] = 'summary';
            update_option( self::OPTION_ONBOARDING, $state, false );
            $message = 'Gestion du consentement enregistrée.';
            $redirect_override = add_query_arg( 'ptm_setup_step', 'summary', admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) );
        } elseif ( in_array( $action, array( 'setup_finish', 'setup_finish_scan', 'setup_finish_full', 'setup_finish_quick' ), true ) ) {
            $state = $this->onboarding_state();
            $state['status'] = 'completed';
            $state['step'] = 'done';
            $state['completed_at'] = gmdate( 'c' );
            update_option( self::OPTION_ONBOARDING, $state, false );
            $message = 'Configuration initiale enregistrée. Vous pourrez relancer cet assistant depuis Réglages.';
            $redirect_override = admin_url( 'admin.php?page=pixel-trackers-manager' );
            if ( in_array( $action, array( 'setup_finish_scan', 'setup_finish_full' ), true ) ) {
                $redirect_override = add_query_arg( 'pixel_trackers_manager_autostart_scan', 'full', $redirect_override );
            } elseif ( 'setup_finish_quick' === $action ) {
                $redirect_override = add_query_arg( 'pixel_trackers_manager_autostart_scan', 'standard', $redirect_override );
            }
        } elseif ( 'setup_skip' === $action ) {
            $state = $this->onboarding_state();
            $state['status'] = 'skipped';
            $state['step'] = 'paused';
            update_option( self::OPTION_ONBOARDING, $state, false );
            $message = 'Assistant mis de côté. Vous pouvez le relancer depuis Réglages.';
            $redirect_override = admin_url( 'admin.php?page=pixel-trackers-manager' );
        } elseif ( 'setup_pause' === $action ) {
            $state = $this->onboarding_state();
            if ( 'new' === $state['status'] ) {
                $state['status'] = 'started';
            }
            if ( ! empty( $_POST['setup_step'] ) ) {
                $pause_step = sanitize_key( wp_unslash( $_POST['setup_step'] ) );
                if ( in_array( $pause_step, array( 'welcome', 'pages', 'consent', 'summary' ), true ) ) { $state['step'] = $pause_step; }
            }
            update_option( self::OPTION_ONBOARDING, $state, false );
            $message = 'Assistant mis en pause. Il reprendra à votre prochaine ouverture de Dendrila Privacy.';
            $redirect_override = admin_url( 'index.php' );
        } elseif ( 'publish_legal_page' === $action ) {
            $page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
            $post = $page_id ? get_post( $page_id ) : null;
            if ( ! $post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $page_id ) ) {
                $message = 'Cette page ne peut pas être publiée depuis Dendrila Privacy.';
                $type = 'error';
            } else {
                $kind = sanitize_key( (string) get_post_meta( $page_id, '_pixel_trackers_manager_generated_legal_page', true ) );
                $missing = array();
                if ( in_array( $kind, array( 'legal_notice','privacy','cookies' ), true ) ) {
                    $profile = $this->legal_profile();
                    if ( 'legal_notice' === $kind ) {
                        $missing = $this->legal_notice_readiness( $profile );
                    } elseif ( 'privacy' === $kind ) {
                        $readiness = $this->legal_profile_readiness( $profile );
                        $missing = isset( $readiness['errors'] ) ? (array) $readiness['errors'] : array();
                    } elseif ( 'yes' === $profile['cookie_nonessential'] && 'yes' !== $profile['cookie_consent_status'] && empty( $this->settings()['consent_enabled'] ) ) {
                        $missing[] = 'gestion du consentement aux traceurs facultatifs';
                    }
                }
                if ( $missing ) {
                    $message = 'Cette page créée par Dendrila Privacy doit encore être complétée avant publication : ' . implode( ', ', array_slice( $missing, 0, 4 ) ) . ( count( $missing ) > 4 ? '…' : '' );
                    $type = 'warning';
                    $redirect_override = $this->assistant_url( 'identity' );
                } else {
                    $result = wp_update_post( array( 'ID'=>$page_id, 'post_status'=>'publish' ), true );
                    if ( is_wp_error( $result ) ) {
                        $message = $result->get_error_message();
                        $type = 'error';
                    } else {
                        $scan = get_option( self::OPTION_SCAN, array() );
                        if ( ! empty( $scan['coverage']['page_issues'] ) && is_array( $scan['coverage']['page_issues'] ) ) {
                            $scan['coverage']['page_issues'] = array_values( array_filter( $scan['coverage']['page_issues'], function( $issue ) use ( $page_id ) {
                                return empty( $issue['page_id'] ) || (int) $issue['page_id'] !== $page_id;
                            } ) );
                            update_option( self::OPTION_SCAN, $scan, false );
                        }
                        if ( in_array( $kind, array( 'legal_notice','privacy','cookies' ), true ) ) { $this->approve_public_document( $kind ); }
                        $this->log_action( 'publish_legal_page', 'manual', array( 'page_id'=>$page_id, 'kind'=>$kind ) );
                        $message = 'Page publiée. Relancez l’analyse pour vérifier son rendu public.';
                        $redirect_override = admin_url( 'admin.php?page=pixel-trackers-manager#ptm-scan-card' );
                    }
                }
            }
        } elseif ( 'scan' === $action ) {
            $result = $this->run_scan( 'manual' );
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                $message = 'Analyse terminée : ' . count( $result['findings'] ) . ' service(s) ou traceur(s) détecté(s).';
            }
        } elseif ( 'save_consent_settings' === $action ) {
            $settings = $this->settings();
            $settings['consent_enabled'] = empty( $_POST['consent_enabled'] ) ? 0 : 1;
            $settings['consent_style'] = isset( $_POST['consent_style'] ) && 'neutral' === sanitize_key( wp_unslash( $_POST['consent_style'] ) ) ? 'neutral' : 'inherit';
            $consent_layout = isset( $_POST['consent_layout'] ) ? sanitize_key( wp_unslash( $_POST['consent_layout'] ) ) : 'bar';
            $settings['consent_layout'] = in_array( $consent_layout, array( 'bar', 'card' ), true ) ? $consent_layout : 'bar';
            $settings['consent_retention_days'] = isset( $_POST['consent_retention_days'] ) ? max( 30, min( 365, absint( $_POST['consent_retention_days'] ) ) ) : 180;
            $settings['consent_footer_link'] = empty( $_POST['consent_footer_link'] ) ? 0 : 1;
            $this->update_settings( $settings );
            $message = $settings['consent_enabled'] ? 'Bannière Dendrila Privacy activée : les services facultatifs reconnus sont bloqués avant le choix.' : 'Bannière Dendrila Privacy désactivée.';
        } elseif ( 'audit_page' === $action ) {
            $settings = $this->settings();
            $settings['privacy_page_id'] = isset( $_POST['privacy_page_id'] ) ? absint( $_POST['privacy_page_id'] ) : $settings['privacy_page_id'];
            $settings['legal_notice_page_id'] = isset( $_POST['legal_notice_page_id'] ) ? absint( $_POST['legal_notice_page_id'] ) : $settings['legal_notice_page_id'];
            $settings['cookie_page_id'] = isset( $_POST['cookie_page_id'] ) ? absint( $_POST['cookie_page_id'] ) : $settings['cookie_page_id'];
            $this->update_settings( $settings );
            $result = $this->audit_legal_pages( $this->legal_page_ids( $settings ) );
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                $message = 'Contrôle des pages juridiques terminé.';
            }
        } elseif ( 'sync_all_legal_documents' === $action ) {
            $settings = $this->settings();
            $updated = array(); $failed = array();
            foreach ( array( 'legal_notice','privacy','cookies' ) as $kind ) {
                $cfg = $this->legal_document_config( $kind, $settings );
                $page_id = isset( $cfg['page_id'] ) ? absint( $cfg['page_id'] ) : 0;
                if ( ! $page_id ) { continue; }
                if ( 'cookies' === $kind && 'yes' !== ( wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() ) )['cookie_nonessential'] && ! $this->scan_has_optional_tracking() ) { continue; }
                $builder = $this->page_builder_info( $page_id );
                if ( ! empty($builder['safe_mode']) && ! in_array( $builder['id'], array('elementor','divi','divi5'), true ) ) { $failed[] = $cfg['label'] . ' (' . $builder['label'] . ' : insertion manuelle sûre)'; continue; }
                $result = in_array( $builder['id'], array('elementor','divi'), true ) ? $this->inject_builder_shortcode( $page_id, $kind ) : $this->sync_legal_document( $page_id, $kind );
                if ( is_wp_error( $result ) ) { $failed[] = $cfg['label']; } else { $updated[] = $cfg['label']; }
            }
            if ( $this->legal_page_ids( $settings ) ) { $this->audit_legal_pages( $this->legal_page_ids( $settings ) ); }
            $message = $updated ? 'Pages mises à jour : ' . implode( ', ', $updated ) . '.' : 'Aucune page n’a été modifiée.';
            if ( $failed ) { $message .= ' À faire manuellement : ' . implode( ', ', $failed ) . '.'; $type = 'warning'; }
        } elseif ( 'sync_page' === $action ) {
            $page_id = isset( $_POST['privacy_page_id'] ) ? absint( $_POST['privacy_page_id'] ) : 0;
            $result = $this->sync_privacy_page( $page_id, 'manual' );
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                $message = 'Bloc Dendrila Privacy synchronisé. Si un constructeur de pages est détecté, Dendrila Privacy utilise son mode de compatibilité non destructif.';
            }
        } elseif ( 'restore_page' === $action ) {
            $page_id = isset( $_POST['privacy_page_id'] ) ? absint( $_POST['privacy_page_id'] ) : 0;
            $result = $this->restore_privacy_page( $page_id );
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                $message = 'Blocs gérés par Dendrila Privacy retirés sans restaurer une ancienne copie de la page.';
            }
        } elseif ( 'disable_tracking_adapter' === $action ) {
            $adapter = isset( $_POST['adapter'] ) ? sanitize_key( wp_unslash( $_POST['adapter'] ) ) : '';
            $result = $this->set_tracking_adapter_state( $adapter, false );
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                $message = $result;
                // Re-scan immediately so the interface never keeps a stale status after a privacy action.
                $scan_result = $this->run_scan( 'adapter-action' );
                if ( is_wp_error( $scan_result ) ) {
                    $message .= ' Le réglage a bien été modifié, mais l’analyse automatique a échoué : ' . $scan_result->get_error_message();
                    $type = 'warning';
                }
            }
        } elseif ( 'restore_tracking_adapter' === $action ) {
            $adapter = isset( $_POST['adapter'] ) ? sanitize_key( wp_unslash( $_POST['adapter'] ) ) : '';
            $result = $this->set_tracking_adapter_state( $adapter, true );
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                $message = $result;
                $this->run_scan( 'adapter-restore' );
            }
        } elseif ( 'save_legal_profile' === $action ) {
            $raw = $this->verified_post_array( 'legal_profile' );
            $section = isset( $_POST['legal_section'] ) ? sanitize_key( wp_unslash( $_POST['legal_section'] ) ) : '';
            $current = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
            $profile = $section ? $this->merge_legal_section( $current, $raw, $section ) : $this->sanitize_legal_profile( $raw );
            update_option( self::OPTION_LEGAL_PROFILE, $profile, false );
            // Same rule as the AJAX path: saving a block never performs a hidden
            // HTTP audit. Verification is refreshed by an explicit scan or final check.
            $message = $section ? 'Bloc enregistré sans modifier les autres sections.' : 'Questionnaire RGPD enregistré. Vérifiez l’aperçu avant toute insertion dans la page publique.';

            // When AJAX is unavailable, keep the guided wizard on the correct step instead of
            // falling back to the plugin overview or restarting at step 1.
            $requested_next = isset( $_POST['ptm_next_step'] ) ? sanitize_key( wp_unslash( $_POST['ptm_next_step'] ) ) : '';
            $wizard_steps = array_keys( $this->legal_wizard_steps() );
            if ( $section && $requested_next && in_array( $requested_next, $wizard_steps, true ) ) {
                // L'assistant n'est jamais bloquant : les champs manquants restent visibles dans
                // les actions du tableau de bord, mais l'utilisateur peut poursuivre son parcours.
                $section_status = $this->legal_wizard_section_status( $profile, $section );
                $redirect_override = $this->assistant_url( $requested_next );
                if ( empty( $section_status['complete'] ) ) {
                    $message = 'Bloc enregistré. Vous pouvez continuer ; les informations restant à vérifier seront signalées dans les actions du tableau de bord.';
                }
            }
        } elseif ( 'sync_legal_block' === $action ) {
            $page_id = isset( $_POST['privacy_page_id'] ) ? absint( $_POST['privacy_page_id'] ) : 0;
            $result = $this->sync_legal_block( $page_id );
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                $message = 'Bloc d’information RGPD inséré ou mis à jour après validation du questionnaire.';
            }
        } elseif ( 'sync_legal_document' === $action ) {
            $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
            $kind = isset($_POST['document_kind']) ? sanitize_key(wp_unslash($_POST['document_kind'])) : 'privacy';
            $result = $this->sync_legal_document( $page_id, $kind );
            if ( is_wp_error($result) ) { $message=$result->get_error_message(); $type='error'; }
            else { $message='Document Dendrila Privacy mis à jour sur la page sélectionnée.'; $this->audit_legal_pages($this->legal_page_ids()); }
        } elseif ( 'inject_builder_shortcode' === $action ) {
            $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
            $kind = isset($_POST['document_kind']) ? sanitize_key(wp_unslash($_POST['document_kind'])) : 'privacy';
            $result = $this->inject_builder_shortcode( $page_id, $kind );
            if ( is_wp_error($result) ) { $message=$result->get_error_message(); $type='error'; }
            else { $message='Le bloc Dendrila Privacy a été inséré dans la page. Vérifiez son emplacement dans le constructeur.'; $this->audit_legal_pages($this->legal_page_ids()); }
        } elseif ( 'finish_legal_draft_pages' === $action || 'finish_legal_draft_home' === $action ) {
            $ids = $this->legal_page_ids();
            if ( $ids ) { $this->audit_legal_pages($ids); }
            $message = 'Brouillon enregistré. La couverture documentaire et les actions ont été recalculées.';
            if ( 'finish_legal_draft_pages' === $action ) {
                $redirect_override = admin_url('admin.php?page=pixel-trackers-manager-privacy') . '#ptm-publication-block';
            } else {
                $redirect_override = admin_url('admin.php?page=pixel-trackers-manager');
            }
        } elseif ( 'save_settings' === $action ) {
            $settings = $this->settings();
            $settings['privacy_page_id'] = isset( $_POST['privacy_page_id'] ) ? absint( $_POST['privacy_page_id'] ) : 0;
            $settings['legal_notice_page_id'] = isset( $_POST['legal_notice_page_id'] ) ? absint( $_POST['legal_notice_page_id'] ) : 0;
            $settings['cookie_page_id'] = isset( $_POST['cookie_page_id'] ) ? absint( $_POST['cookie_page_id'] ) : 0;
            $schedule = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : 'off';
            $settings['schedule'] = in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ? $schedule : 'off';
            $settings['auto_sync_page'] = ! empty( $_POST['auto_sync_page'] ) ? 1 : 0;
            $settings['scan_limit'] = isset( $_POST['scan_limit'] ) ? max( 5, min( 50, absint( $_POST['scan_limit'] ) ) ) : 20;
            $settings['full_scan_limit'] = isset( $_POST['full_scan_limit'] ) ? max( 50, min( 1000, absint( $_POST['full_scan_limit'] ) ) ) : 500;
            $settings['consent_layout'] = isset( $_POST['consent_layout'] ) && 'card' === sanitize_key( wp_unslash( $_POST['consent_layout'] ) ) ? 'card' : 'bar';
            $this->update_settings( $settings );
            $message = 'Réglages enregistrés.';
        } elseif ( 'export_json' === $action ) {
            $this->download_json_export();
            exit;
        }

        if ( $message ) {
            set_transient( 'pixel_trackers_manager_admin_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
            $redirect = $redirect_override ? $redirect_override : wp_get_referer();
            wp_safe_redirect( $redirect ? $redirect : admin_url( 'admin.php?page=pixel-trackers-manager' ) );
            exit;
        }
    }

    private function signatures() {
        return array(
            'google-analytics' => array(
                'label' => 'Google Analytics', 'category' => 'Mesure d’audience',
                'patterns' => array( 'google-analytics.com', 'googletagmanager.com/gtag/js', 'gtag(' ),
                'aliases' => array( 'google analytics', 'ga4', '_ga' ),
                'plugin_slugs' => array( 'google-site-kit', 'ga-google-analytics', 'monsterinsights', 'google-analytics-for-wordpress', 'woocommerce-google-analytics-integration', 'seo-by-rank-math', 'exactmetrics', 'exactmetrics-premium' ),
            ),
            'google-tag-manager' => array(
                'label' => 'Google Tag Manager', 'category' => 'Gestion de balises',
                'patterns' => array( 'googletagmanager.com/gtm.js', 'GTM-' ),
                'aliases' => array( 'google tag manager', 'gtm' ),
                'plugin_slugs' => array( 'duracelltomi-google-tag-manager', 'google-tag-manager', 'google-site-kit' ),
            ),
            'meta-pixel' => array(
                'label' => 'Meta Pixel', 'category' => 'Marketing / suivi',
                'patterns' => array( 'connect.facebook.net', 'fbevents.js', 'fbq(' ),
                'aliases' => array( 'meta pixel', 'facebook pixel', 'pixel facebook' ),
                'plugin_slugs' => array( 'facebook-for-woocommerce', 'pixelyoursite' ),
            ),
            'clarity' => array(
                'label' => 'Microsoft Clarity', 'category' => 'Analyse comportementale',
                'patterns' => array( 'clarity.ms/tag', 'clarity(' ),
                'aliases' => array( 'microsoft clarity', 'clarity' ), 'plugin_slugs' => array( 'microsoft-clarity' ),
            ),
            'hotjar' => array(
                'label' => 'Hotjar', 'category' => 'Analyse comportementale',
                'patterns' => array( 'static.hotjar.com', 'hotjar.com/c/hotjar-', 'hj(' ),
                'aliases' => array( 'hotjar' ), 'plugin_slugs' => array( 'hotjar' ),
            ),
            'tiktok-pixel' => array(
                'label' => 'TikTok Pixel', 'category' => 'Marketing / suivi',
                'patterns' => array( 'analytics.tiktok.com', 'ttq.' ),
                'aliases' => array( 'tiktok pixel', 'tiktok' ), 'plugin_slugs' => array( 'tiktok-for-business' ),
            ),
            'linkedin-insight' => array(
                'label' => 'LinkedIn Insight Tag', 'category' => 'Marketing / suivi',
                'patterns' => array( 'snap.licdn.com', '_linkedin_partner_id' ),
                'aliases' => array( 'linkedin insight', 'linkedin' ), 'plugin_slugs' => array(),
            ),
            'pinterest-tag' => array(
                'label' => 'Pinterest Tag', 'category' => 'Marketing / suivi',
                'patterns' => array( 's.pinimg.com/ct/core.js', 'pintrk(' ),
                'aliases' => array( 'pinterest tag', 'pinterest' ), 'plugin_slugs' => array(),
            ),
            'youtube' => array(
                'label' => 'YouTube', 'category' => 'Contenu embarqué',
                'patterns' => array( 'youtube.com/embed', 'youtube-nocookie.com/embed' ),
                'aliases' => array( 'youtube' ), 'plugin_slugs' => array(),
            ),
            'vimeo' => array(
                'label' => 'Vimeo', 'category' => 'Contenu embarqué',
                'patterns' => array( 'player.vimeo.com', 'vimeo.com/video' ),
                'aliases' => array( 'vimeo' ), 'plugin_slugs' => array(),
            ),
            'google-fonts' => array(
                'label' => 'Google Fonts chargé à distance', 'category' => 'Police externe / confidentialité',
                'patterns' => array( 'fonts.googleapis.com', 'fonts.gstatic.com' ),
                'aliases' => array( 'google fonts', 'fonts.googleapis.com', 'fonts.gstatic.com' ), 'plugin_slugs' => array(),
                'is_tracking' => false,
            ),
            'google-maps' => array(
                'label' => 'Google Maps', 'category' => 'Contenu / cartographie',
                'patterns' => array( 'maps.googleapis.com', 'google.com/maps/embed', 'maps.google.com/maps/embed' ),
                'aliases' => array( 'google maps', 'google map' ), 'plugin_slugs' => array( 'wp-google-maps', 'google-maps-widget' ),
            ),
            'recaptcha' => array(
                'label' => 'Google reCAPTCHA', 'category' => 'Sécurité / anti-spam',
                // Detection signatures only; split host literals so Plugin Check does not mistake them for PTM remote dependencies.
                'patterns' => array( 'google' . '.com/recaptcha', 'gstatic' . '.com/recaptcha' ),
                'aliases' => array( 'recaptcha', 'google recaptcha' ), 'plugin_slugs' => array( 'advanced-nocaptcha-recaptcha', 'google-captcha' ),
            ),
            'matomo' => array(
                'label' => 'Matomo', 'category' => 'Mesure d’audience',
                'patterns' => array( 'matomo.js', 'piwik.js', '_paq.push' ),
                'aliases' => array( 'matomo', 'piwik' ), 'plugin_slugs' => array( 'matomo' ),
            ),
            'plausible' => array(
                'label' => 'Plausible Analytics', 'category' => 'Mesure d’audience',
                // Split the literal because this is a detection signature, not a remotely loaded script.
                'patterns' => array( 'plausible' . '.io/js', 'plausible(' ),
                'aliases' => array( 'plausible analytics', 'plausible' ), 'plugin_slugs' => array( 'plausible-analytics' ),
            ),
            'mailpoet' => array(
                'label' => 'MailPoet', 'category' => 'E-mailing / newsletter',
                // Cookie names are technical evidence only; the real state is read from MailPoet below.
                'patterns' => array( 'mailpoet_page_view', 'mailpoet_subscriber', 'mailpoet_revenue_tracking', 'mailpoet_router' ),
                'aliases' => array( 'mailpoet', 'mailpoet_page_view', 'mailpoet_subscriber' ),
                'plugin_slugs' => array( 'mailpoet' ),
            ),
            'brevo' => array(
                'label' => 'Brevo Tracker', 'category' => 'Marketing automation / suivi du site',
                'patterns' => array( 'sibautomation.com', 'sendinblue.com', 'sibautomation' ),
                'aliases' => array( 'brevo', 'sendinblue', 'tracker brevo' ),
                'plugin_slugs' => array( 'mailin' ),
            ),
            'helloasso' => array(
                'label' => 'HelloAsso', 'category' => 'Adhésions / dons / billetterie',
                'patterns' => array( 'helloasso.com', 'helloasso' ), 'aliases' => array( 'helloasso' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'calendly' => array(
                'label' => 'Calendly', 'category' => 'Réservation / rendez-vous',
                'patterns' => array( 'calendly.com', 'assets.calendly.com' ), 'aliases' => array( 'calendly' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'koalendar' => array(
                'label' => 'Koalendar', 'category' => 'Réservation / rendez-vous',
                'patterns' => array( 'koalendar.com' ), 'aliases' => array( 'koalendar' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'cal-com' => array(
                'label' => 'Cal.com', 'category' => 'Réservation / rendez-vous',
                'patterns' => array( 'cal.com/' ), 'aliases' => array( 'cal.com' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'microsoft-bookings' => array(
                'label' => 'Microsoft Bookings', 'category' => 'Réservation / rendez-vous',
                'patterns' => array( 'outlook.office365.com/owa/calendar', 'microsoft.com/bookings' ), 'aliases' => array( 'microsoft bookings' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'google-booking' => array(
                'label' => 'Réservation Google / Google Calendar', 'category' => 'Réservation / rendez-vous',
                'patterns' => array( 'calendar.google.com/calendar/appointments', 'calendar.google.com/calendar/u/' ), 'aliases' => array( 'google calendar', 'réservation google' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'typeform' => array(
                'label' => 'Typeform', 'category' => 'Formulaire externe',
                'patterns' => array( 'typeform.com', 'form.typeform.com' ), 'aliases' => array( 'typeform' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'google-forms' => array(
                'label' => 'Google Forms', 'category' => 'Formulaire externe',
                'patterns' => array( 'docs.google.com/forms', 'forms.gle/' ), 'aliases' => array( 'google forms' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'microsoft-forms' => array(
                'label' => 'Microsoft Forms', 'category' => 'Formulaire externe',
                'patterns' => array( 'forms.office.com', 'forms.microsoft.com' ), 'aliases' => array( 'microsoft forms' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'stripe' => array(
                'label' => 'Stripe', 'category' => 'Paiement externe',
                'patterns' => array( 'js.stripe.com', 'checkout.stripe.com', 'stripe.com/' ), 'aliases' => array( 'stripe' ), 'plugin_slugs' => array( 'woocommerce-gateway-stripe', 'stripe' ), 'is_tracking' => false,
            ),
            'paypal' => array(
                'label' => 'PayPal', 'category' => 'Paiement externe',
                'patterns' => array( 'paypal.com', 'paypalobjects.com' ), 'aliases' => array( 'paypal' ), 'plugin_slugs' => array( 'woocommerce-paypal-payments' ), 'is_tracking' => false,
            ),
            'sumup' => array(
                'label' => 'SumUp', 'category' => 'Paiement externe',
                'patterns' => array( 'sumup.com', 'checkout.sumup.com' ), 'aliases' => array( 'sumup' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'eventbrite' => array(
                'label' => 'Eventbrite', 'category' => 'Événement / billetterie',
                'patterns' => array( 'eventbrite.com', 'eventbrite.fr' ), 'aliases' => array( 'eventbrite' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'whatsapp' => array(
                'label' => 'WhatsApp', 'category' => 'Messagerie / contact',
                'patterns' => array( 'wa.me/', 'api.whatsapp.com', 'whatsapp://' ), 'aliases' => array( 'whatsapp' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
            'telegram' => array(
                'label' => 'Telegram', 'category' => 'Messagerie / contact',
                'patterns' => array( 't.me/', 'telegram.me/' ), 'aliases' => array( 'telegram' ), 'plugin_slugs' => array(), 'is_tracking' => false,
            ),
        );
    }

    private function detect_cmp( $plugins ) {
        $map = array(
            'complianz-gdpr' => 'Complianz',
            'real-cookie-banner' => 'Real Cookie Banner',
            'cookie-law-info' => 'CookieYes / GDPR Cookie Consent',
            'cookie-notice' => 'Cookie Notice',
            'gdpr-cookie-compliance' => 'Moove GDPR Cookie Compliance',
        );
        $active = array();
        foreach ( $plugins as $path => $plugin ) {
            if ( empty( $plugin['active'] ) ) {
                continue;
            }
            $slug = $plugin['slug'];
            if ( isset( $map[ $slug ] ) ) {
                $active[] = array( 'slug' => $slug, 'name' => $map[ $slug ], 'plugin' => $path );
            }
        }
        return $active;
    }

    private function installed_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        $active = (array) get_option( 'active_plugins', array() );
        $network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
        $result = array();
        foreach ( $all as $path => $data ) {
            $dir = dirname( $path );
            $slug = '.' === $dir ? sanitize_title( basename( $path, '.php' ) ) : sanitize_title( $dir );
            $result[ $path ] = array(
                'name' => isset( $data['Name'] ) ? $data['Name'] : $slug,
                'version' => isset( $data['Version'] ) ? $data['Version'] : '',
                'slug' => $slug,
                'active' => in_array( $path, $active, true ) || in_array( $path, $network, true ),
                'network' => in_array( $path, $network, true ),
            );
        }
        return $result;
    }

    private function plugin_sources_for_signature( $signature, $plugins ) {
        $matches = array();
        foreach ( $plugins as $path => $plugin ) {
            if ( empty( $plugin['active'] ) ) {
                continue;
            }
            foreach ( $signature['plugin_slugs'] as $slug_hint ) {
                if ( false !== strpos( $plugin['slug'], $slug_hint ) || false !== strpos( sanitize_title( $plugin['name'] ), $slug_hint ) ) {
                    $matches[] = array( 'plugin' => $path, 'name' => $plugin['name'], 'slug' => $plugin['slug'] );
                    break;
                }
            }
        }
        return $matches;
    }

    private function maybe_migrate_data() {
        // Migrate the pre-publication 3-letter option prefix to the unique public namespace.
        $legacy_options = array(
            'ptm_settings' => self::OPTION_SETTINGS,
            'ptm_scan_current' => self::OPTION_SCAN,
            'ptm_scan_previous' => self::OPTION_PREVIOUS_SCAN,
            'ptm_page_audit' => self::OPTION_PAGE_AUDIT,
            'ptm_audit_log' => self::OPTION_AUDIT_LOG,
            'ptm_legal_profile' => self::OPTION_LEGAL_PROFILE,
            'privacy_tracker_manager_settings' => self::OPTION_SETTINGS,
            'privacy_tracker_manager_scan_current' => self::OPTION_SCAN,
            'privacy_tracker_manager_scan_previous' => self::OPTION_PREVIOUS_SCAN,
            'privacy_tracker_manager_page_audit' => self::OPTION_PAGE_AUDIT,
            'privacy_tracker_manager_audit_log' => self::OPTION_AUDIT_LOG,
            'privacy_tracker_manager_legal_profile' => self::OPTION_LEGAL_PROFILE,
            'privacy_tracker_manager_page_overlays' => self::OPTION_PAGE_OVERLAYS,
            'privacy_tracker_manager_data_schema_version' => self::OPTION_DATA_SCHEMA,
        );
        foreach ( $legacy_options as $legacy_key => $new_key ) {
            $legacy_value = get_option( $legacy_key, null );
            if ( null !== $legacy_value && false === get_option( $new_key, false ) ) {
                update_option( $new_key, $legacy_value, false );
            }
            if ( null !== $legacy_value ) {
                delete_option( $legacy_key );
            }
        }

        // Older test builds duplicated complete legal-page contents for rollback. The public build removes managed markers instead, so those copies are no longer necessary.
        delete_option( 'ptm_page_backups' );
        delete_option( 'pixel_trackers_manager_page_backups' );

        $legacy_schema = (int) get_option( 'ptm_data_schema_version', 0 );
        $schema = (int) get_option( self::OPTION_DATA_SCHEMA, $legacy_schema );

        if ( $schema < 4 ) {
            // Detection and score semantics changed materially in v0.3: old results would be misleading.
            delete_option( self::OPTION_SCAN );
            delete_option( self::OPTION_PREVIOUS_SCAN );
            delete_option( self::OPTION_PAGE_AUDIT );
            delete_transient( 'pixel_trackers_manager_scan_lock' );
            delete_transient( 'ptm_scan_lock' );
            $schema = 4;
        }
        if ( $schema < 5 ) {
            // v0.3.1 removed the experimental remote-monitoring bridge from the distributable plugin.
            $settings = get_option( self::OPTION_SETTINGS, array() );
            if ( is_array( $settings ) ) {
                unset( $settings['secret'], $settings['remote_actions'] );
                update_option( self::OPTION_SETTINGS, $settings, false );
            }
            $schema = 5;
        }
        if ( $schema < 6 ) {
            // Public namespace and AJAX/cron names introduced for WordPress.org readiness.
            wp_clear_scheduled_hook( 'ptm_scheduled_scan' );
            delete_transient( 'ptm_scan_lock' );
            $settings = $this->settings();
            if ( in_array( $settings['schedule'], array( 'daily', 'weekly' ), true ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + 300, $settings['schedule'], self::CRON_HOOK );
            }
            $schema = 6;
        }
        if ( $schema < 7 ) {
            // Builder-safe overlays are created only after an explicit synchronization action.
            if ( false === get_option( self::OPTION_PAGE_OVERLAYS, false ) ) {
                add_option( self::OPTION_PAGE_OVERLAYS, array(), '', false );
            }
            $schema = 7;
        }

        if ( (int) get_option( self::OPTION_DATA_SCHEMA, 0 ) !== $schema ) {
            update_option( self::OPTION_DATA_SCHEMA, $schema, false );
        }
        delete_option( 'ptm_data_schema_version' );
    }

    private function apply_adapter_overrides() {
        $settings = $this->settings();
        if ( ! empty( $settings['adapter_overrides']['fluentcrm_privacy_mode'] ) ) {
            add_filter( 'fluentcrm_disable_email_open_tracking', '__return_true', PHP_INT_MAX );
            add_filter( 'fluent_crm/track_click', '__return_false', PHP_INT_MAX );
            add_filter( 'fluent_crm/will_use_cookie', '__return_false', PHP_INT_MAX );
        }
    }

    private function mailpoet_settings_controller() {
        if ( ! class_exists( '\\MailPoet\\DI\\ContainerWrapper' ) || ! class_exists( '\\MailPoet\\Settings\\SettingsController' ) ) {
            return null;
        }
        try {
            return \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\Settings\SettingsController::class );
        } catch ( Throwable $e ) {
            return null;
        }
    }

    private function mailpoet_tracking_levels() {
        $levels = array(
            'basic' => 'basic',
            'partial' => 'partial',
            'full' => 'full',
        );

        // MailPoet 5.x exposes these values via TrackingConfig. Use its constants
        // when available, while retaining the documented string values as a
        // backwards-compatible fallback for older supported installations.
        if ( class_exists( '\\MailPoet\\Settings\\TrackingConfig' ) ) {
            $levels['basic'] = (string) \MailPoet\Settings\TrackingConfig::LEVEL_BASIC;
            $levels['partial'] = (string) \MailPoet\Settings\TrackingConfig::LEVEL_PARTIAL;
            $levels['full'] = (string) \MailPoet\Settings\TrackingConfig::LEVEL_FULL;
        }

        return $levels;
    }

    private function mailpoet_tracking_state() {
        $controller = $this->mailpoet_settings_controller();
        if ( ! $controller ) {
            return array( 'available' => false, 'state' => 'unknown', 'label' => 'État MailPoet non lisible' );
        }
        try {
            $levels = $this->mailpoet_tracking_levels();
            $level = (string) $controller->get( 'tracking.level', $levels['full'] );
            $email = in_array( $level, array( $levels['partial'], $levels['full'] ), true );
            $cookies = ( $levels['full'] === $level );
            return array(
                'available' => true,
                'state' => ( $email || $cookies ) ? 'active' : 'disabled',
                'level' => $level,
                'email_tracking' => $email,
                'cookie_tracking' => $cookies,
                'label' => $email || $cookies ? 'Suivi MailPoet actif (' . $level . ')' : 'Suivi MailPoet désactivé (basic)',
            );
        } catch ( Throwable $e ) {
            return array( 'available' => false, 'state' => 'unknown', 'label' => 'Impossible de lire le réglage MailPoet' );
        }
    }

    private function fluentcrm_tracking_state() {
        $settings = $this->settings();
        $forced_off = ! empty( $settings['adapter_overrides']['fluentcrm_privacy_mode'] );
        return array(
            'available' => true,
            'state' => $forced_off ? 'disabled' : 'unknown',
            'label' => $forced_off ? 'Suivi FluentCRM désactivé par Dendrila Privacy' : 'Suivi FluentCRM : réglage à confirmer',
        );
    }

    private function email_tracking_tools( $plugins ) {
        $by_slug = array();
        foreach ( $plugins as $plugin ) {
            if ( ! empty( $plugin['active'] ) ) { $by_slug[ $plugin['slug'] ] = $plugin; }
        }
        $tools = array();
        if ( isset( $by_slug['mailpoet'] ) ) {
            $state = $this->mailpoet_tracking_state();
            $tools[] = array(
                'id' => 'mailpoet', 'name' => 'MailPoet', 'plugin' => $by_slug['mailpoet'],
                'tracking_state' => $state['state'], 'status' => $state,
                'action_mode' => 'direct',
                'settings_url' => admin_url( 'admin.php?page=mailpoet-settings#/advanced' ),
                'help' => 'Dendrila Privacy lit directement « Engagement analytics tracking ». Le mode basic coupe le suivi e-mail et les cookies d’engagement sans arrêter l’envoi des newsletters.',
            );
        }
        foreach ( $by_slug as $slug => $plugin ) {
            if ( false !== strpos( $slug, 'fluent-crm' ) || false !== strpos( sanitize_title( $plugin['name'] ), 'fluentcrm' ) ) {
                $state = $this->fluentcrm_tracking_state();
                $tools[] = array(
                    'id' => 'fluentcrm', 'name' => 'FluentCRM', 'plugin' => $plugin,
                    'tracking_state' => $state['state'], 'status' => $state,
                    'action_mode' => 'direct-filter',
                    'settings_url' => admin_url( 'admin.php?page=fluentcrm-admin' ),
                    'help' => 'Dendrila Privacy peut appliquer les filtres publics FluentCRM qui désactivent pixel d’ouverture, réécriture des liens et cookie de suivi, sans arrêter les campagnes.',
                );
                break;
            }
        }
        if ( isset( $by_slug['mailin'] ) ) {
            $tools[] = array(
                'id' => 'brevo', 'name' => 'Brevo', 'plugin' => $by_slug['mailin'],
                'tracking_state' => 'unknown', 'status' => array( 'state' => 'unknown' ),
                'action_mode' => 'guided', 'settings_url' => admin_url( 'admin.php?page=mailin' ),
                'help' => 'Le tracker de site Brevo est distinct du suivi d’ouverture/clic des campagnes. Dans Brevo > Accueil, désactivez « Marketing Automation via Brevo » si vous ne souhaitez pas utiliser le tracker du site.',
            );
        }
        if ( isset( $by_slug['newsletter'] ) ) {
            $tools[] = array(
                'id' => 'newsletter', 'name' => 'The Newsletter Plugin', 'plugin' => $by_slug['newsletter'],
                'tracking_state' => 'unknown', 'status' => array( 'state' => 'unknown' ),
                'action_mode' => 'guided', 'settings_url' => admin_url( 'admin.php?page=newsletter_main_index' ),
                'help' => 'Dans Newsletter > Settings > General, réglez « Tracking default ». Attention : chaque newsletter peut surcharger cette valeur avant envoi.',
            );
        }
        if ( isset( $by_slug['mailchimp-for-wp'] ) ) {
            $tools[] = array(
                'id' => 'mailchimp', 'name' => 'Mailchimp for WordPress', 'plugin' => $by_slug['mailchimp-for-wp'],
                'tracking_state' => 'external', 'status' => array( 'state' => 'external' ),
                'action_mode' => 'external', 'settings_url' => admin_url( 'admin.php?page=mailchimp-for-wp' ),
                'help' => 'MC4WP gère surtout les formulaires. Le suivi d’ouverture/clic se règle dans Mailchimp, dans « Settings & Tracking » de chaque campagne ; Dendrila Privacy ne prétend pas le couper localement.',
            );
        }
        foreach ( $by_slug as $slug => $plugin ) {
            if ( false !== strpos( $slug, 'mail-mint' ) || false !== strpos( sanitize_title( $plugin['name'] ), 'mail-mint' ) ) {
                $tools[] = array(
                    'id' => 'mailmint', 'name' => 'Mail Mint', 'plugin' => $plugin,
                    'tracking_state' => 'unknown', 'status' => array( 'state' => 'unknown' ),
                    'action_mode' => 'guided', 'settings_url' => admin_url( 'admin.php?page=mrm-admin' ),
                    'help' => 'Les versions récentes de Mail Mint proposent des options de suivi d’ouverture/clic. Dendrila Privacy vous guide vers le réglage mais n’écrit pas dans une option privée non documentée.',
                );
                break;
            }
        }
        return $tools;
    }

    private function set_tracking_adapter_state( $adapter, $restore ) {
        $settings = $this->settings();
        if ( 'mailpoet' === $adapter ) {
            $controller = $this->mailpoet_settings_controller();
            if ( ! $controller ) { return new WP_Error( 'pixel_trackers_manager_mailpoet', 'MailPoet est actif mais son réglage de suivi n’est pas accessible.' ); }
            try {
                $levels = $this->mailpoet_tracking_levels();
                $allowed_levels = array_values( $levels );
                $before = (string) $controller->get( 'tracking.level', $levels['full'] );
                if ( $restore ) {
                    $target = isset( $settings['adapter_previous']['mailpoet_tracking_level'] ) ? (string) $settings['adapter_previous']['mailpoet_tracking_level'] : $levels['full'];
                    if ( ! in_array( $target, $allowed_levels, true ) ) { $target = $levels['full']; }
                    $controller->set( 'tracking.level', $target );
                    unset( $settings['adapter_previous']['mailpoet_tracking_level'] );
                } else {
                    if ( $levels['basic'] !== $before ) { $settings['adapter_previous']['mailpoet_tracking_level'] = $before; }
                    $target = $levels['basic'];
                    $controller->set( 'tracking.level', $target );
                }
                $after = (string) $controller->get( 'tracking.level', $levels['full'] );
                $this->update_settings( $settings );
                if ( $after !== $target ) { return new WP_Error( 'pixel_trackers_manager_mailpoet_verify', 'MailPoet n’a pas confirmé le nouveau réglage. Aucune réussite n’est affichée tant que la vérification échoue.' ); }
                $this->log_action( 'tracking_adapter', 'manual', array( 'adapter' => 'mailpoet', 'before' => $before, 'after' => $after ) );
                return $restore ? 'Réglage MailPoet précédent restauré et vérifié.' : 'Suivi MailPoet désactivé et vérifié. Les newsletters restent actives.';
            } catch ( Throwable $e ) {
                return new WP_Error( 'pixel_trackers_manager_mailpoet_exception', 'MailPoet a refusé la modification : ' . $e->getMessage() );
            }
        }
        if ( 'fluentcrm' === $adapter ) {
            if ( $restore ) {
                unset( $settings['adapter_overrides']['fluentcrm_privacy_mode'] );
                remove_filter( 'fluentcrm_disable_email_open_tracking', '__return_true', PHP_INT_MAX );
                remove_filter( 'fluent_crm/track_click', '__return_false', PHP_INT_MAX );
                remove_filter( 'fluent_crm/will_use_cookie', '__return_false', PHP_INT_MAX );
                $message = 'Forçage confidentialité FluentCRM retiré.';
            } else {
                $settings['adapter_overrides']['fluentcrm_privacy_mode'] = 1;
                add_filter( 'fluentcrm_disable_email_open_tracking', '__return_true', PHP_INT_MAX );
                add_filter( 'fluent_crm/track_click', '__return_false', PHP_INT_MAX );
                add_filter( 'fluent_crm/will_use_cookie', '__return_false', PHP_INT_MAX );
                $message = 'Suivi FluentCRM désactivé via ses filtres publics (ouvertures, clics et cookie).';
            }
            $this->update_settings( $settings );
            $this->log_action( 'tracking_adapter', 'manual', array( 'adapter' => 'fluentcrm', 'disabled' => ! $restore ) );
            return $message;
        }
        return new WP_Error( 'pixel_trackers_manager_adapter_unknown', 'Dendrila Privacy ne dispose pas d’un adaptateur sûr pour cet outil.' );
    }

    private function technical_surface( $html ) {
        $chunks = array();
        foreach ( array(
            '/<script\\b[^>]*>.*?<\\/script>/is',
            '/<(?:iframe|img|link|source)\\b[^>]*>/is',
            '/<noscript\\b[^>]*>.*?<\\/noscript>/is',
            '/<!--.*?-->/s',
        ) as $regex ) {
            if ( preg_match_all( $regex, $html, $matches ) ) {
                $chunks = array_merge( $chunks, $matches[0] );
            }
        }
        return implode( "\n", $chunks );
    }

    private function scan_html_into_findings( $html, $url, $findings ) {
        $surface = $this->technical_surface( $html );
        $blocked_surface = '';
        if ( preg_match_all( '/<(?:script|iframe|img|link|source)\b[^>]*data-ptm-blocked=["\']1["\'][^>]*>(?:.*?<\/script>)?/is', $surface, $blocked_matches ) ) {
            $blocked_surface = implode( "\n", $blocked_matches[0] );
        }
        $active_surface = preg_replace( '/<(?:script|iframe|img|link|source)\b[^>]*data-ptm-blocked=["\']1["\'][^>]*>(?:.*?<\/script>)?/is', '', $surface );
        $active_lower = strtolower( (string) $active_surface );
        $blocked_lower = strtolower( (string) $blocked_surface );

        foreach ( $this->signatures() as $id => $signature ) {
            $evidence = array(); $blocked_evidence = array();
            foreach ( $signature['patterns'] as $pattern ) {
                $needle = strtolower( $pattern );
                $pos = strpos( $active_lower, $needle );
                if ( false !== $pos ) {
                    $start = max( 0, $pos - 70 );
                    $snippet = substr( $active_surface, $start, 220 );
                    $evidence[] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $snippet ) ) );
                    continue;
                }
                $pos = strpos( $blocked_lower, $needle );
                if ( false !== $pos ) {
                    $start = max( 0, $pos - 70 );
                    $snippet = substr( $blocked_surface, $start, 220 );
                    $blocked_evidence[] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $snippet ) ) );
                }
            }
            if ( $evidence || $blocked_evidence ) {
                if ( ! isset( $findings[ $id ] ) ) { $findings[ $id ] = $this->empty_finding( $id, $signature ); }
                $findings[ $id ]['observed_html'] = true;
                $findings[ $id ]['urls'][] = $url;
                if ( $evidence ) {
                    $is_tracking = ! isset( $signature['is_tracking'] ) || ! empty( $signature['is_tracking'] );
                    $findings[ $id ]['active_tracking'] = $is_tracking;
                    $findings[ $id ]['tracking_state'] = 'active';
                    $findings[ $id ]['tracking_basis'] = $is_tracking ? 'technical_html' : 'external_resource';
                    $findings[ $id ]['blocked_by_consent'] = false;
                    $findings[ $id ]['evidence'] = array_merge( $findings[ $id ]['evidence'], $evidence );
                } elseif ( empty( $findings[ $id ]['active_tracking'] ) ) {
                    $findings[ $id ]['active_tracking'] = false;
                    $findings[ $id ]['tracking_state'] = 'blocked';
                    $findings[ $id ]['tracking_basis'] = 'native_consent_block';
                    $findings[ $id ]['blocked_by_consent'] = true;
                    $findings[ $id ]['evidence'] = array_merge( $findings[ $id ]['evidence'], $blocked_evidence );
                }
            }
        }
        return $findings;
    }

    private function finalize_findings( $findings, $plugins, $theme_refs ) {
        $signatures = $this->signatures();
        foreach ( $signatures as $id => $signature ) {
            $plugin_sources = $this->plugin_sources_for_signature( $signature, $plugins );
            $has_theme_ref = ! empty( $theme_refs[ $id ] );
            if ( $plugin_sources || $has_theme_ref || isset( $findings[ $id ] ) ) {
                if ( ! isset( $findings[ $id ] ) ) { $findings[ $id ] = $this->empty_finding( $id, $signature ); }
                $findings[ $id ]['code_reference'] = (bool) ( $plugin_sources || $has_theme_ref );
                $findings[ $id ]['plugin_sources'] = $plugin_sources;
                $findings[ $id ]['theme_files'] = $has_theme_ref ? $theme_refs[ $id ] : array();
                if ( ! empty( $findings[ $id ]['blocked_by_consent'] ) && empty( $findings[ $id ]['active_tracking'] ) ) {
                    $findings[ $id ]['confidence'] = 'élevée';
                    $findings[ $id ]['tracking_state'] = 'blocked';
                    $findings[ $id ]['source'] = 'Service présent, mais bloqué avant le choix par la bannière Dendrila Privacy';
                } elseif ( ! empty( $findings[ $id ]['observed_html'] ) ) {
                    $findings[ $id ]['confidence'] = 'élevée';
                    $findings[ $id ]['source'] = $plugin_sources ? 'Trace technique observée + extension probable : ' . $plugin_sources[0]['name'] : 'Trace technique repérée dans le code affiché par une page';
                } elseif ( $plugin_sources ) {
                    $findings[ $id ]['confidence'] = 'moyenne';
                    $findings[ $id ]['source'] = 'Extension présente : capacité de suivi, sans preuve d’activation';
                    $findings[ $id ]['tracking_state'] = 'potential';
                    $findings[ $id ]['active_tracking'] = false;
                } else {
                    $findings[ $id ]['confidence'] = 'faible';
                    $findings[ $id ]['source'] = 'Référence potentielle dans le thème';
                    $findings[ $id ]['tracking_state'] = 'potential';
                    $findings[ $id ]['active_tracking'] = false;
                }
                $findings[ $id ]['urls'] = array_slice( array_values( array_unique( $findings[ $id ]['urls'] ) ), 0, 12 );
                $findings[ $id ]['evidence'] = array_slice( array_values( array_unique( array_filter( $findings[ $id ]['evidence'] ) ) ), 0, 5 );
            }
        }

        // MailPoet is special: email tracking happens inside sent messages, not in ordinary page HTML.
        $mailpoet = $this->mailpoet_tracking_state();
        $mailpoet_source = $this->plugin_sources_for_signature( $signatures['mailpoet'], $plugins );
        if ( $mailpoet_source ) {
            if ( ! isset( $findings['mailpoet'] ) ) { $findings['mailpoet'] = $this->empty_finding( 'mailpoet', $signatures['mailpoet'] ); }
            $findings['mailpoet']['plugin_sources'] = $mailpoet_source;
            $findings['mailpoet']['adapter_status'] = $mailpoet;
            if ( 'disabled' === $mailpoet['state'] ) {
                // A privacy/cookie policy may still contain the word MailPoet: it is documentation, never proof of active tracking.
                $findings['mailpoet']['active_tracking'] = false;
                $findings['mailpoet']['tracking_state'] = 'disabled';
                $findings['mailpoet']['tracking_basis'] = 'plugin_setting';
                $findings['mailpoet']['confidence'] = 'élevée';
                $findings['mailpoet']['source'] = 'Réglage MailPoet lu directement : suivi désactivé (' . ( isset( $mailpoet['level'] ) ? $mailpoet['level'] : 'basic' ) . ')';
            } elseif ( 'active' === $mailpoet['state'] ) {
                $findings['mailpoet']['active_tracking'] = true;
                $findings['mailpoet']['tracking_state'] = 'active';
                $findings['mailpoet']['tracking_basis'] = 'plugin_setting';
                $findings['mailpoet']['confidence'] = 'élevée';
                $findings['mailpoet']['source'] = 'Réglage MailPoet lu directement : suivi actif (' . $mailpoet['level'] . ')';
            }
        }

        foreach ( $findings as $id => $finding ) {
            $findings[ $id ]['tracking_control'] = $this->tracking_control_for_finding( $findings[ $id ] );
        }
        ksort( $findings );
        return $findings;
    }

    /**
     * Returns a safe, contextual way to stop the tracking feature without disabling
     * the whole source plugin. We deliberately prefer documented settings screens
     * over writing another plugin's private options directly.
     */
    private function tracking_control_for_finding( $finding ) {
        $id = isset( $finding['id'] ) ? $finding['id'] : '';
        $sources = isset( $finding['plugin_sources'] ) && is_array( $finding['plugin_sources'] ) ? $finding['plugin_sources'] : array();
        $slugs = array();
        foreach ( $sources as $source ) { if ( ! empty( $source['slug'] ) ) { $slugs[] = $source['slug']; } }

        if ( 'mailpoet' === $id && in_array( 'mailpoet', $slugs, true ) ) {
            $status = $this->mailpoet_tracking_state();
            return array(
                'kind' => 'adapter', 'adapter' => 'mailpoet',
                'state' => $status['state'],
                'label' => 'Désactiver le suivi MailPoet',
                'restore_label' => 'Rétablir le réglage précédent',
                'url' => admin_url( 'admin.php?page=mailpoet-settings#/advanced' ),
                'instructions' => 'Dendrila Privacy vérifie le réglage « Engagement analytics tracking ». Il peut le passer à basic et vérifie immédiatement le résultat. Les newsletters continuent de partir.',
            );
        }
        if ( 'brevo' === $id && in_array( 'mailin', $slugs, true ) ) {
            return array(
                'kind' => 'guided', 'state' => isset( $finding['tracking_state'] ) ? $finding['tracking_state'] : 'unknown',
                'label' => 'Ouvrir Brevo', 'url' => admin_url( 'admin.php?page=mailin' ),
                'instructions' => 'Dans Brevo > Accueil > Automatisation, désactivez « Marketing Automation via Brevo » pour couper le tracker du site. Le suivi des campagnes e-mail Brevo est un réglage distinct.',
            );
        }
        if ( 'google-analytics' === $id && in_array( 'google-site-kit', $slugs, true ) ) {
            return array( 'kind'=>'source_setting','state'=>isset($finding['tracking_state'])?$finding['tracking_state']:'unknown','label'=>'Couper l’injection Analytics','url'=>admin_url('admin.php?page=googlesitekit-settings'),'instructions'=>'Dans Site Kit → Réglages → Services connectés → Analytics → Modifier, désactivez « Placer le code Google Analytics ». Site Kit reste installé.' );
        }
        if ( 'google-tag-manager' === $id && in_array( 'google-site-kit', $slugs, true ) ) {
            return array( 'kind'=>'source_setting','state'=>isset($finding['tracking_state'])?$finding['tracking_state']:'unknown','label'=>'Couper Tag Manager dans Site Kit','url'=>admin_url('admin.php?page=googlesitekit-settings'),'instructions'=>'Dans Site Kit → Réglages → Services connectés → Tag Manager → Modifier, déconnectez uniquement Tag Manager.' );
        }
        if ( 'google-fonts' === $id ) {
            return array( 'kind'=>'guided','state'=>'active','label'=>'Identifier la source','url'=>'','instructions'=>'Une police Google est chargée directement depuis Google. Repérez le thème, le constructeur ou l’extension qui l’ajoute ; l’objectif recommandé est de l’héberger localement puis de relancer l’analyse pour vérifier que fonts.googleapis.com et fonts.gstatic.com ont disparu.' );
        }
        if ( 'google-maps' === $id && $sources ) {
            return array( 'kind'=>'source_setting','state'=>isset($finding['tracking_state'])?$finding['tracking_state']:'unknown','label'=>'Voir les réglages de la carte','url'=>admin_url('plugins.php?s='.rawurlencode($sources[0]['name']).'&plugin_status=all'),'instructions'=>'Privilégiez un chargement après consentement, une carte statique ou un fournisseur sans traceur plutôt que de désactiver toute l’extension.' );
        }
        if ( $sources ) {
            return array( 'kind'=>'guided','state'=>isset($finding['tracking_state'])?$finding['tracking_state']:'unknown','label'=>'Ouvrir la source','url'=>admin_url('plugins.php?s='.rawurlencode($sources[0]['name']).'&plugin_status=all'),'instructions'=>'Dendrila Privacy n’a pas d’adaptateur documenté pour ce réglage. Il vous mène à la source sans modifier une option privée inconnue.' );
        }
        return array( 'kind'=>'guided','state'=>isset($finding['tracking_state'])?$finding['tracking_state']:'unknown','label'=>'Identifier la source','url'=>'','instructions'=>'La source exacte n’est pas identifiée. Vérifiez le thème, un bloc embarqué ou un script ajouté manuellement.' );
    }

    private function scan_page_label( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( ! $url ) {
            return 'Page du site';
        }
        if ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
            $name = trim( (string) get_bloginfo( 'name' ) );
            return $name ? $name . ' — accueil' : 'Accueil';
        }
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $title = trim( wp_specialchars_decode( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES ) );
            if ( $title ) {
                return $title;
            }
        }
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        if ( ! $path ) {
            return 'Accueil';
        }
        $slug = rawurldecode( basename( $path ) );
        $slug = trim( str_replace( array( '-', '_' ), ' ', $slug ) );
        return $slug ? ucwords( $slug ) : 'Page du site';
    }

    private function scan_urls( $limit, $mode = 'standard', $options = array() ) {
        $limit = max( 5, min( 1000, absint( $limit ) ) );
        $mode = 'full' === $mode ? 'full' : 'standard';
        $options = is_array( $options ) ? $options : array();
        $urls = array( home_url( '/' ) );
        $settings = $this->settings();

        foreach ( $this->legal_page_ids( $settings ) as $page_id ) {
            if ( 'publish' !== get_post_status( $page_id ) ) {
                continue;
            }
            $url = get_permalink( $page_id );
            if ( $url ) { $urls[] = $url; }
        }

        if ( 'full' === $mode ) {
            // Pages stay in scope regardless of age: an old public page can still be reached
            // directly and may contain embeds or scripts. The age filter applies only to
            // posts and other public content types, where large archives are common.
            $posts = get_posts( array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'numberposts' => $limit,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
                'suppress_filters' => false,
            ) );

            $post_types = get_post_types( array( 'public' => true ), 'names' );
            unset( $post_types['attachment'], $post_types['page'] );
            if ( $post_types && count( $posts ) < $limit ) {
                $query = array(
                    'post_type' => array_values( $post_types ),
                    'post_status' => 'publish',
                    'numberposts' => max( 1, $limit - count( $posts ) ),
                    'orderby' => 'modified',
                    'order' => 'DESC',
                    'fields' => 'ids',
                    'suppress_filters' => false,
                );
                $years = isset( $options['max_age_years'] ) ? absint( $options['max_age_years'] ) : 0;
                if ( $years > 0 ) {
                    $query['date_query'] = array( array( 'after' => $years . ' years ago', 'inclusive' => true ) );
                }
                $posts = array_merge( $posts, get_posts( $query ) );
            }
        } else {
            $posts = get_posts( array(
                'post_type' => array( 'page', 'post' ),
                'post_status' => 'publish',
                'numberposts' => max( 1, $limit - count( $urls ) ),
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
            ) );
        }

        foreach ( $posts as $post_id ) {
            $url = get_permalink( $post_id );
            if ( $url ) { $urls[] = $url; }
        }

        if ( 'full' === $mode && ! empty( $options['include_archives'] ) ) {
            foreach ( get_categories( array( 'hide_empty' => true, 'number' => 100 ) ) as $term ) {
                $url = get_category_link( $term );
                if ( ! is_wp_error( $url ) ) { $urls[] = $url; }
            }
            foreach ( get_tags( array( 'hide_empty' => true, 'number' => 100 ) ) as $term ) {
                $url = get_tag_link( $term );
                if ( ! is_wp_error( $url ) ) { $urls[] = $url; }
            }
            foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'names' ) as $post_type ) {
                $url = get_post_type_archive_link( $post_type );
                if ( $url ) { $urls[] = $url; }
            }
        }

        return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, $limit );
    }

    private function fetch_html( $url ) {
        $url = $this->internal_absolute_url( $url, home_url( '/' ) );
        if ( ! $url ) {
            return new WP_Error( 'pixel_trackers_manager_invalid_scan_url', 'L’adresse à analyser est invalide ou se trouve en dehors de ce site.' );
        }
        $scan_url = add_query_arg( 'pixel_trackers_manager_scan', (string) time(), $url );
        $response = wp_safe_remote_get( $scan_url, array(
            'timeout' => 12,
            'redirection' => 5,
            'limit_response_size' => 2 * MB_IN_BYTES,
            'user-agent' => 'PixelTrackersManager/' . self::VERSION . '; ' . home_url( '/' ),
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml',
                'Cache-Control' => 'no-cache, no-store, max-age=0',
                'Pragma' => 'no-cache',
            ),
        ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) { return new WP_Error( 'pixel_trackers_manager_http', 'HTTP ' . $code . ' pour ' . esc_url_raw( $url ) ); }
        return wp_remote_retrieve_body( $response );
    }

    private function non_public_page_issue_for_url( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( ! $url ) { return array(); }
        $page_id = 0;

        // Selected legal pages are checked first because draft permalinks are not always
        // resolvable by url_to_postid().
        foreach ( $this->legal_page_ids() as $candidate_id ) {
            $permalink = get_permalink( $candidate_id );
            if ( $permalink && untrailingslashit( strtok( $permalink, '?' ) ) === untrailingslashit( strtok( $url, '?' ) ) ) {
                $page_id = (int) $candidate_id;
                break;
            }
        }
        if ( ! $page_id ) { $page_id = (int) url_to_postid( $url ); }
        if ( ! $page_id ) {
            $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
            $url_path = (string) wp_parse_url( $url, PHP_URL_PATH );
            if ( $url_path ) {
                $relative = trim( preg_replace( '#^' . preg_quote( rtrim( $home_path, '/' ), '#' ) . '#', '', $url_path ), '/' );
                if ( $relative ) {
                    $page = get_page_by_path( $relative, OBJECT, 'page' );
                    if ( $page ) { $page_id = (int) $page->ID; }
                }
            }
        }
        $post = $page_id ? get_post( $page_id ) : null;
        if ( ! $post || 'page' !== $post->post_type || 'publish' === $post->post_status ) { return array(); }

        $labels = array(
            'draft'=>'Brouillon WordPress',
            'pending'=>'En attente de validation',
            'private'=>'Page privée',
            'future'=>'Publication programmée',
            'trash'=>'Page dans la corbeille',
        );
        $status = (string) $post->post_status;
        $kind = sanitize_key( (string) get_post_meta( $page_id, '_pixel_trackers_manager_generated_legal_page', true ) );
        $generated = in_array( $kind, array( 'legal_notice','privacy','cookies' ), true );
        $ready = true;
        $missing = array();
        if ( $generated ) {
            $profile = $this->legal_profile();
            if ( 'legal_notice' === $kind ) {
                $missing = $this->legal_notice_readiness( $profile );
            } elseif ( 'privacy' === $kind ) {
                $readiness = $this->legal_profile_readiness( $profile );
                $missing = isset( $readiness['errors'] ) ? (array) $readiness['errors'] : array();
            } else {
                // Cookie pages may stay concise, but a declared optional-tracker use must
                // have a real choice mechanism before PTM calls the generated page ready.
                $settings = $this->settings();
                if ( 'yes' === $profile['cookie_nonessential'] && 'yes' !== $profile['cookie_consent_status'] && empty( $settings['consent_enabled'] ) ) {
                    $missing[] = 'gestion du consentement aux traceurs facultatifs';
                }
            }
            $ready = empty( $missing );
        }
        return array(
            'url'=>$url,
            'page_id'=>$page_id,
            'title'=>$post->post_title ? $post->post_title : '(sans titre)',
            'status'=>$status,
            'status_label'=>isset( $labels[$status] ) ? $labels[$status] : ucfirst( $status ),
            'generated'=>$generated,
            'kind'=>$kind,
            'ready'=>$ready,
            'missing'=>array_values( array_unique( array_filter( $missing ) ) ),
            'edit_url'=>$this->legal_page_edit_url( $page_id ),
        );
    }

    private function clean_builder_text_fragment( $value ) {
        $value = (string) $value;
        if ( '' === trim( $value ) ) {
            return '';
        }

        // Builder exports can contain Gutenberg comments, scripts, style payloads and
        // shortcode wrappers. Keep the human-readable body while discarding layout data.
        $value = preg_replace( '#<(script|style|template|noscript)\b[^>]*>.*?</\\1>#is', ' ', $value );
        $value = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/i', ' ', $value );
        $value = preg_replace( '/<!--.*?-->/s', ' ', $value );
        // Remove shortcode tags but preserve text located between opening/closing tags.
        $value = preg_replace( '/\[(?:\/?)[a-zA-Z0-9_-]+(?:\s[^\]]*)?\]/s', ' ', $value );
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        $value = wp_strip_all_tags( $value, true );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( (string) $value );
    }

    private function append_builder_text_fragment( &$parts, $value ) {
        $clean = $this->clean_builder_text_fragment( $value );
        if ( '' === $clean || strlen( $clean ) < 2 ) {
            return;
        }
        // Avoid responsive copies and duplicate builder payloads bloating the audit input.
        $key = md5( $clean );
        if ( ! isset( $parts[ $key ] ) ) {
            $parts[ $key ] = $clean;
        }
    }

    private function collect_elementor_setting_text( $value, &$parts, $depth = 0 ) {
        if ( $depth > 12 || ! is_array( $value ) ) {
            return;
        }
        $text_keys = array(
            'editor', 'title', 'title_text', 'description', 'description_text', 'text', 'content', 'html',
            'tab_title', 'tab_content', 'accordion_title', 'accordion_content', 'toggle_title', 'toggle_content',
            'faq_question', 'faq_answer', 'question', 'answer', 'testimonial_content', 'testimonial_name',
            'alert_title', 'alert_description', 'button_text', 'caption', 'blockquote_content', 'quote', 'author',
        );
        foreach ( $value as $key => $item ) {
            $key = is_string( $key ) ? sanitize_key( $key ) : '';
            if ( is_string( $item ) && in_array( $key, $text_keys, true ) ) {
                $this->append_builder_text_fragment( $parts, $item );
                continue;
            }
            if ( is_array( $item ) ) {
                $this->collect_elementor_setting_text( $item, $parts, $depth + 1 );
            }
        }
    }

    private function collect_elementor_text_fragments( $nodes, &$parts, $depth = 0 ) {
        if ( $depth > 14 || ! is_array( $nodes ) ) {
            return;
        }
        // Elementor stores user-facing content in widget settings. Traverse the element
        // tree but deliberately ignore metadata, style controls, IDs, colors and URLs.
        $is_node = isset( $nodes['elType'] ) || isset( $nodes['widgetType'] ) || isset( $nodes['elements'] );
        if ( $is_node ) {
            if ( isset( $nodes['settings'] ) && is_array( $nodes['settings'] ) ) {
                $this->collect_elementor_setting_text( $nodes['settings'], $parts );
            }
            if ( isset( $nodes['elements'] ) && is_array( $nodes['elements'] ) ) {
                $this->collect_elementor_text_fragments( $nodes['elements'], $parts, $depth + 1 );
            }
            return;
        }
        foreach ( $nodes as $node ) {
            if ( is_array( $node ) ) {
                $this->collect_elementor_text_fragments( $node, $parts, $depth + 1 );
            }
        }
    }

    private function extract_divi_text_fragments( $content ) {
        $content = (string) $content;
        if ( '' === trim( $content ) ) {
            return array();
        }
        $parts = array();
        // Divi 4 exports and post_content keep the visible body between these module
        // shortcodes. Layout attributes are intentionally ignored.
        $modules = array(
            'et_pb_text'           => array(),
            'et_pb_toggle'         => array( 'title' ),
            'et_pb_accordion_item' => array( 'title' ),
            'et_pb_blurb'          => array( 'title' ),
            'et_pb_code'           => array(),
            'et_pb_button'         => array( 'button_text' ),
            'et_pb_tab'            => array( 'title' ),
        );
        foreach ( $modules as $tag => $attribute_keys ) {
            $pattern = '#\\[' . preg_quote( $tag, '#' ) . '\\b([^\\]]*)\\](.*?)\\[/' . preg_quote( $tag, '#' ) . '\\]#is';
            if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
                continue;
            }
            foreach ( $matches as $match ) {
                $atts = shortcode_parse_atts( trim( (string) $match[1] ) );
                if ( is_array( $atts ) ) {
                    foreach ( $attribute_keys as $attribute_key ) {
                        if ( isset( $atts[ $attribute_key ] ) && is_scalar( $atts[ $attribute_key ] ) ) {
                            $this->append_builder_text_fragment( $parts, (string) $atts[ $attribute_key ] );
                        }
                    }
                }
                $this->append_builder_text_fragment( $parts, (string) $match[2] );
            }
        }
        return array_values( $parts );
    }

    private function local_page_rendered_content( $page_id ) {
        $page_id = absint( $page_id );
        $post = $page_id ? get_post( $page_id ) : null;
        if ( ! $post ) {
            return '';
        }

        $raw = (string) $post->post_content;
        $chunks = array( $raw );
        $builder = $this->page_builder_info( $page_id );

        if ( 'elementor' === $builder['id'] ) {
            // Preferred path when Elementor is active: ask its own frontend renderer.
            try {
                if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->frontend ) && method_exists( \Elementor\Plugin::$instance->frontend, 'get_builder_content_for_display' ) ) {
                    $elementor_html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $page_id, true );
                    if ( is_string( $elementor_html ) && '' !== trim( $elementor_html ) ) {
                        $chunks[] = $elementor_html;
                    }
                }
            } catch ( Throwable $e ) {
                $this->runtime_warning( 'elementor-render', $e->getMessage() );
            }

            // Native local extraction: Elementor's export format mirrors _elementor_data.
            // Only known human-readable widget fields are retained.
            $elementor_data = get_post_meta( $page_id, '_elementor_data', true );
            if ( is_string( $elementor_data ) && '' !== trim( $elementor_data ) ) {
                $decoded = json_decode( $elementor_data, true );
                if ( is_array( $decoded ) ) {
                    $parts = array();
                    $this->collect_elementor_text_fragments( $decoded, $parts );
                    if ( $parts ) {
                        $chunks[] = implode( "\n", array_values( $parts ) );
                    }
                }
            } elseif ( is_array( $elementor_data ) ) {
                $parts = array();
                $this->collect_elementor_text_fragments( $elementor_data, $parts );
                if ( $parts ) {
                    $chunks[] = implode( "\n", array_values( $parts ) );
                }
            }
        } else {
            if ( in_array( $builder['id'], array( 'divi', 'divi5' ), true ) ) {
                // Native Divi 4 extraction is independent from the theme runtime. Divi 5
                // blocks still benefit from do_blocks/the_content below.
                $divi_parts = $this->extract_divi_text_fragments( $raw );
                if ( $divi_parts ) {
                    $chunks[] = implode( "\n", $divi_parts );
                }
            }

            // Divi, Gutenberg and the classic editor can also be rendered locally. Keep
            // these paths as fallbacks for modules/blocks not covered by the native parser.
            $rendered = $raw;
            if ( function_exists( 'do_blocks' ) ) {
                $rendered = do_blocks( $rendered );
            }
            $rendered = do_shortcode( $rendered );
            if ( is_string( $rendered ) && '' !== trim( $rendered ) ) {
                $chunks[] = $rendered;
            }
            try {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally invokes WordPress core's the_content filter.
                $filtered = apply_filters( 'the_content', $raw );
                if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
                    $chunks[] = $filtered;
                }
            } catch ( Throwable $e ) {
                $this->runtime_warning( 'content-render', $e->getMessage() );
            }
        }

        return implode( "\n", array_values( array_unique( array_filter( $chunks, 'is_string' ) ) ) );
    }

    private function scan_theme_references() {
        $signatures = $this->signatures();
        $matches = array();
        $dirs = array_filter( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) );
        $files_seen = 0;
        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
                foreach ( $iterator as $file ) {
                    if ( $files_seen >= 180 ) {
                        break 2;
                    }
                    if ( ! $file->isFile() || $file->getSize() > 1048576 ) {
                        continue;
                    }
                    $ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
                    if ( ! in_array( $ext, array( 'php', 'js', 'html', 'htm', 'css' ), true ) ) {
                        continue;
                    }
                    $files_seen++;
                    if ( ! is_readable( $file->getPathname() ) ) {
                        continue;
                    }
                    $content = file_get_contents( $file->getPathname() );
                    if ( false === $content ) {
                        continue;
                    }
                    $lower = strtolower( $content );
                    foreach ( $signatures as $id => $signature ) {
                        foreach ( $signature['patterns'] as $pattern ) {
                            if ( false !== strpos( $lower, strtolower( $pattern ) ) ) {
                                if ( empty( $matches[ $id ] ) ) {
                                    $matches[ $id ] = array();
                                }
                                $normalized_dir = trailingslashit( wp_normalize_path( $dir ) );
                                $normalized_file = wp_normalize_path( $file->getPathname() );
                                $relative = ltrim( str_replace( $normalized_dir, '', $normalized_file ), '/' );
                                $matches[ $id ][] = basename( untrailingslashit( $normalized_dir ) ) . '/' . $relative;
                                break;
                            }
                        }
                    }
                }
            } catch ( Exception $e ) {
                continue;
            }
        }
        foreach ( $matches as $id => $paths ) {
            $matches[ $id ] = array_slice( array_values( array_unique( $paths ) ), 0, 6 );
        }
        return $matches;
    }

    private function ajax_scan_guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Droits insuffisants.' ), 403 );
        }
        check_ajax_referer( 'pixel_trackers_manager_scan_progress', 'nonce' );
    }

    private function scan_job_key() {
        return 'pixel_trackers_manager_scan_job_' . get_current_user_id();
    }

    public function ajax_scan_start() {
        $this->ajax_scan_guard();
        $key = $this->scan_job_key();
        $existing = get_transient( $key );

        if ( get_transient( 'pixel_trackers_manager_scan_lock' ) ) {
            if ( is_array( $existing ) && isset( $existing['index'], $existing['urls'] ) ) {
                wp_send_json_success( array(
                    'resumed' => true,
                    'index' => (int) $existing['index'],
                    'total' => count( $existing['urls'] ),
                    'percent' => count( $existing['urls'] ) ? min( 90, (int) round( ( (int) $existing['index'] / count( $existing['urls'] ) ) * 90 ) ) : 0,
                    'message' => 'Reprise de l’analyse en cours.',
                    'mode' => isset($existing['mode']) ? $existing['mode'] : 'standard',
                ) );
            }
            wp_send_json_error( array( 'message' => 'Une autre analyse Dendrila Privacy est déjà en cours.' ), 409 );
        }

        $settings = $this->settings();
        $requested_mode = sanitize_key( $this->verified_post_value( 'mode', 'standard' ) );
        $mode = in_array( $requested_mode, array( 'standard', 'full', 'retry' ), true ) ? $requested_mode : 'standard';
        $include_archives = ! empty( $this->verified_post_value( 'include_archives' ) );
        $max_age_years = absint( $this->verified_post_value( 'max_age_years', '0' ) );
        $previous_scan = get_option( self::OPTION_SCAN, array() );
        $base_requested = 0;
        $base_scanned_urls = array();
        $base_findings = array();

        if ( 'retry' === $mode ) {
            $previous_coverage = isset( $previous_scan['coverage'] ) && is_array( $previous_scan['coverage'] ) ? $previous_scan['coverage'] : array();
            $failed = isset( $previous_coverage['errors'] ) && is_array( $previous_coverage['errors'] ) ? $previous_coverage['errors'] : array();
            $urls = array();
            foreach ( $failed as $failure ) {
                if ( ! empty( $failure['url'] ) ) {
                    $urls[] = esc_url_raw( $failure['url'] );
                }
            }
            $urls = array_values( array_unique( array_filter( $urls ) ) );
            if ( ! $urls ) {
                wp_send_json_error( array( 'message' => 'Aucune page en erreur à réessayer.' ), 400 );
            }
            $base_requested = isset( $previous_coverage['requested'] ) ? (int) $previous_coverage['requested'] : count( $urls );
            $base_scanned_urls = isset( $previous_coverage['urls'] ) && is_array( $previous_coverage['urls'] ) ? $previous_coverage['urls'] : array();
            $saved_findings = isset( $previous_scan['findings'] ) && is_array( $previous_scan['findings'] ) ? $previous_scan['findings'] : array();
            foreach ( $saved_findings as $saved_finding ) {
                if ( is_array( $saved_finding ) && ! empty( $saved_finding['id'] ) ) {
                    $base_findings[ sanitize_key( $saved_finding['id'] ) ] = $saved_finding;
                }
            }
            $include_archives = ! empty( $previous_coverage['include_archives'] );
            $max_age_years = isset( $previous_coverage['max_age_years'] ) ? absint( $previous_coverage['max_age_years'] ) : 0;
        } else {
            $limit = 'full' === $mode ? (int)$settings['full_scan_limit'] : (int)$settings['scan_limit'];
            $limit = 'full' === $mode ? max(50,min(1000,$limit)) : max(5,min(50,$limit));
            $urls = $this->scan_urls( $limit, $mode, array(
                'include_archives'=>$include_archives,
                'max_age_years'=>$max_age_years,
            ) );
        }

        $job = array(
            'started_at' => gmdate( 'c' ),
            'index' => 0,
            'urls' => $urls,
            'mode' => $mode,
            'include_archives' => $include_archives,
            'max_age_years' => $max_age_years,
            'findings' => $base_findings,
            'errors' => array(),
            'page_issues' => array(),
            'scanned_urls' => $base_scanned_urls,
            'base_requested' => $base_requested,
            'previous' => $previous_scan,
        );

        set_transient( 'pixel_trackers_manager_scan_lock', 1, 30 * MINUTE_IN_SECONDS );
        set_transient( $key, $job, 30 * MINUTE_IN_SECONDS );

        wp_send_json_success( array(
            'resumed' => false,
            'index' => 0,
            'total' => count( $urls ),
            'percent' => 2,
            'mode' => $mode,
            'message' => 'full' === $mode ? 'Analyse complète prête. Démarrage de l’analyse des pages…' : ( 'retry' === $mode ? 'Nouvel essai des pages en erreur…' : 'Liste des pages prête. Démarrage de l’analyse des pages…' ),
        ) );
    }

    public function ajax_scan_step() {
        $this->ajax_scan_guard();
        $key = $this->scan_job_key();
        $job = get_transient( $key );
        if ( ! is_array( $job ) || ! isset( $job['urls'], $job['index'] ) ) {
            delete_transient( 'pixel_trackers_manager_scan_lock' );
            wp_send_json_error( array( 'message' => 'L’analyse progressive a expiré. Relancez-la.' ), 410 );
        }

        $total = count( $job['urls'] );
        $index = (int) $job['index'];
        if ( $index >= $total ) {
            wp_send_json_success( array( 'stage'=>'finalize','index'=>$index,'total'=>$total,'percent'=>92,'message'=>'Pages analysées. Vérification des réglages réels des extensions…' ) );
        }

        // Several pages per request greatly reduce browser/server round-trips, while a short
        // time budget keeps shared hosting responsive. A failed page is recorded and never
        // aborts the rest of the analysis.
        $batch_size = 3;
        $started = microtime( true );
        $last_url = '';
        $processed = 0;
        while ( $index < $total && $processed < $batch_size && ( microtime( true ) - $started ) < 10 ) {
            $url = $job['urls'][ $index ];
            $last_url = $url;
            $html = $this->fetch_html( $url );
            if ( is_wp_error( $html ) ) {
                $page_issue = $this->non_public_page_issue_for_url( $url );
                if ( $page_issue ) {
                    $job['page_issues'][] = $page_issue;
                } else {
                    $job['errors'][] = array( 'url'=>$url, 'error'=>$html->get_error_message() );
                }
            } else {
                $job['scanned_urls'][] = $url;
                $job['findings'] = $this->scan_html_into_findings( $html, $url, isset($job['findings']) ? $job['findings'] : array() );
            }
            $index++;
            $processed++;
        }

        $job['index'] = $index;
        set_transient( 'pixel_trackers_manager_scan_lock', 1, 30 * MINUTE_IN_SECONDS );
        set_transient( $key, $job, 30 * MINUTE_IN_SECONDS );
        $percent = $total ? min( 90, max( 4, (int) round( ( $index / $total ) * 90 ) ) ) : 90;
        wp_send_json_success( array(
            'stage'=>$index >= $total ? 'finalize':'scan', 'index'=>$index, 'total'=>$total, 'percent'=>$index >= $total ? 92:$percent,
            'currentLabel'=>$last_url ? $this->scan_page_label($last_url) : '', 'errors'=>count($job['errors']), 'pageIssues'=>count(isset($job['page_issues'])?(array)$job['page_issues']:array()),
            'message'=>$index >= $total ? 'Pages analysées. Vérification des réglages réels des extensions…' : $index.'/'.$total.' pages analysées',
        ) );
    }

    public function ajax_scan_finalize() {
        $this->ajax_scan_guard();
        $key=$this->scan_job_key(); $job=get_transient($key);
        if ( ! is_array($job) ) { delete_transient('pixel_trackers_manager_scan_lock'); wp_send_json_error(array('message'=>'L’analyse progressive a expiré avant la finalisation.'),410); }
        $settings=$this->settings(); $plugins=$this->installed_plugins(); $theme_refs=$this->scan_theme_references();
        $findings=$this->finalize_findings( isset($job['findings'])&&is_array($job['findings'])?$job['findings']:array(), $plugins, $theme_refs );
        $previous=isset($job['previous'])&&is_array($job['previous'])?$job['previous']:array();
        if($previous){update_option(self::OPTION_PREVIOUS_SCAN,$previous,false);}
        $active_count=0; foreach($findings as $finding){ if(!empty($finding['active_tracking'])){$active_count++;} }
        $is_retry = isset( $job['mode'] ) && 'retry' === $job['mode'];
        $coverage_requested = $is_retry && ! empty( $job['base_requested'] ) ? (int) $job['base_requested'] : count( $job['urls'] );
        $coverage_urls = array_values( array_unique( array_filter( isset( $job['scanned_urls'] ) && is_array( $job['scanned_urls'] ) ? $job['scanned_urls'] : array() ) ) );
        $page_issues = isset( $job['page_issues'] ) && is_array( $job['page_issues'] ) ? $job['page_issues'] : array();
        if ( $is_retry && ! empty( $previous['coverage']['page_issues'] ) && is_array( $previous['coverage']['page_issues'] ) ) {
            $page_issues = array_merge( $previous['coverage']['page_issues'], $page_issues );
        }
        $coverage_processed = min( $coverage_requested, count( $coverage_urls ) + count( isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array() ) + count( $page_issues ) );
        $coverage_mode = $is_retry && ! empty( $previous['coverage']['mode'] ) ? $previous['coverage']['mode'] : ( isset( $job['mode'] ) ? $job['mode'] : 'standard' );
        $scan=array(
            'version'=>6,'plugin_version'=>self::VERSION,'generated_at'=>gmdate('c'),'origin'=>$is_retry?'manual-retry-failed':'manual-progressive',
            'site'=>array('name'=>get_bloginfo('name'),'url'=>home_url('/')),
            'coverage'=>array(
                'requested'=>$coverage_requested,
                'scanned'=>count($coverage_urls),
                'processed'=>$coverage_processed,
                'urls'=>$coverage_urls,
                'errors'=>$job['errors'],
                'page_issues'=>$page_issues,
                'mode'=>$coverage_mode,
                'include_archives'=>!empty($job['include_archives']),
                'max_age_years'=>isset($job['max_age_years'])?(int)$job['max_age_years']:0,
            ),
            'findings'=>array_values($findings),'active_tracking_count'=>$active_count,
            'cmp'=>$this->detect_cmp($plugins),'mailing'=>$this->email_tracking_tools($plugins),'plugins'=>$plugins,
            'diff'=>$this->diff_scans($previous,array_values($findings)),
        );
        update_option(self::OPTION_SCAN,$scan,false); delete_transient($key); delete_transient('pixel_trackers_manager_scan_lock');
        $this->log_action('scan',$is_retry?'manual-retry-failed':'manual-progressive',array('findings'=>count($findings),'active_tracking'=>$active_count,'urls'=>count($coverage_urls),'mode'=>$coverage_mode,'remaining_errors'=>count($job['errors']),'non_public_pages'=>count($page_issues)));
        if($this->legal_page_ids($settings)){$this->audit_legal_pages($this->legal_page_ids($settings));}
        $notice_message = $is_retry ? 'Nouvel essai terminé : ' . count( $job['errors'] ) . ' page(s) restent en erreur.' : 'Analyse terminée : '.$active_count.' suivi(s) actif(s), '.(count($findings)-$active_count).' source(s) présente(s) sans preuve de suivi actif.';
        set_transient('pixel_trackers_manager_admin_notice_'.get_current_user_id(),array('message'=>$notice_message,'type'=>count($job['errors'])?'warning':'success'),60);
        wp_send_json_success(array('percent'=>100,'findings'=>count($findings),'active'=>$active_count,'processed'=>$coverage_processed,'scanned'=>count($coverage_urls),'total'=>$coverage_requested,'errors'=>count($job['errors']),'pageIssues'=>count($page_issues),'message'=>$is_retry?'Nouvel essai terminé.':'Analyse terminée.','mode'=>$is_retry?'retry':$coverage_mode));
    }

    public function ajax_scan_cancel() {
        $this->ajax_scan_guard();
        delete_transient( $this->scan_job_key() );
        delete_transient( 'pixel_trackers_manager_scan_lock' );
        $this->log_action( 'scan_cancelled', 'manual-progressive', array() );
        wp_send_json_success( array( 'message' => 'Scan annulé.' ) );
    }

    public function run_scan( $origin = 'manual' ) {
        if ( get_transient( 'pixel_trackers_manager_scan_lock' ) ) { return new WP_Error( 'pixel_trackers_manager_locked', 'Une analyse Dendrila Privacy est déjà en cours.' ); }
        set_transient( 'pixel_trackers_manager_scan_lock', 1, 5 * MINUTE_IN_SECONDS );
        $settings=$this->settings(); $limit=max(5,min(50,(int)$settings['scan_limit'])); $urls=$this->scan_urls($limit,'standard',array());
        $plugins=$this->installed_plugins(); $theme_refs=$this->scan_theme_references(); $findings=array(); $errors=array(); $page_issues=array(); $scanned_urls=array();
        foreach($urls as $url){
            $html=$this->fetch_html($url);
            if(is_wp_error($html)){
                $page_issue=$this->non_public_page_issue_for_url($url);
                if($page_issue){$page_issues[]=$page_issue;}else{$errors[]=array('url'=>$url,'error'=>$html->get_error_message());}
                continue;
            }
            $scanned_urls[]=$url; $findings=$this->scan_html_into_findings($html,$url,$findings);
        }
        $findings=$this->finalize_findings($findings,$plugins,$theme_refs);
        $previous=get_option(self::OPTION_SCAN,array()); if($previous){update_option(self::OPTION_PREVIOUS_SCAN,$previous,false);}
        $active_count=0; foreach($findings as $finding){if(!empty($finding['active_tracking'])){$active_count++;}}
        $scan=array(
            'version'=>4,'plugin_version'=>self::VERSION,'generated_at'=>gmdate('c'),'origin'=>$origin,
            'site'=>array('name'=>get_bloginfo('name'),'url'=>home_url('/')),
            'coverage'=>array('requested'=>count($urls),'scanned'=>count($scanned_urls),'processed'=>count($urls),'urls'=>$scanned_urls,'errors'=>$errors,'page_issues'=>$page_issues),
            'findings'=>array_values($findings),'active_tracking_count'=>$active_count,
            'cmp'=>$this->detect_cmp($plugins),'mailing'=>$this->email_tracking_tools($plugins),'plugins'=>$plugins,
            'diff'=>$this->diff_scans($previous,array_values($findings)),
        );
        update_option(self::OPTION_SCAN,$scan,false); delete_transient('pixel_trackers_manager_scan_lock');
        $this->log_action('scan',$origin,array('findings'=>count($findings),'active_tracking'=>$active_count,'urls'=>count($scanned_urls)));
        if($this->legal_page_ids($settings)){$this->audit_legal_pages($this->legal_page_ids($settings));}
        return $scan;
    }

    private function empty_finding( $id, $signature ) {
        return array(
            'id'=>$id,'label'=>$signature['label'],'category'=>$signature['category'],'confidence'=>'faible','is_tracking'=>!isset($signature['is_tracking'])||!empty($signature['is_tracking']),
            'observed_html'=>false,'active_tracking'=>false,'blocked_by_consent'=>false,'tracking_state'=>'potential','tracking_basis'=>'capability',
            'code_reference'=>false,'source'=>'','urls'=>array(),'evidence'=>array(),'plugin_sources'=>array(),'theme_files'=>array(),
        );
    }

    private function detect_mailing( $plugins ) {
        return $this->email_tracking_tools( $plugins );
    }

    private function diff_scans( $previous, $current_findings ) {
        $old=array(); foreach((array)(isset($previous['findings'])?$previous['findings']:array()) as $item){if(!empty($item['id'])){$old[$item['id']]=$item;}}
        $new=array(); foreach((array)$current_findings as $item){if(!empty($item['id'])){$new[$item['id']]=$item;}}
        $labels=function($ids)use($old,$new){$out=array();foreach($ids as $id){$src=isset($new[$id])?$new[$id]:$old[$id];$out[]=isset($src['label'])?$src['label']:$id;}return $out;};
        $new_ids=array_diff(array_keys($new),array_keys($old)); $resolved_ids=array_diff(array_keys($old),array_keys($new));
        $changed=array();$started=array();$stopped=array();
        foreach(array_intersect(array_keys($old),array_keys($new)) as $id){
            $was=!empty($old[$id]['active_tracking']) || ( !isset($old[$id]['active_tracking']) && !empty($old[$id]['observed_html']) );
            $is=!empty($new[$id]['active_tracking']);
            if($was!==$is){$changed[]=$id;if($is){$started[]=$id;}else{$stopped[]=$id;}}
            elseif((isset($old[$id]['source'])?$old[$id]['source']:'')!==(isset($new[$id]['source'])?$new[$id]['source']:'')){$changed[]=$id;}
        }
        return array('new'=>$labels($new_ids),'resolved'=>$labels($resolved_ids),'changed'=>$labels(array_unique($changed)),'started'=>$labels($started),'stopped'=>$labels($stopped));
    }

    private function scan_has_optional_tracking( $scan = null ) {
        $scan = is_array( $scan ) ? $scan : get_option( self::OPTION_SCAN, array() );
        foreach ( (array) ( isset( $scan['findings'] ) ? $scan['findings'] : array() ) as $finding ) {
            if ( empty( $finding['active_tracking'] ) && empty( $finding['blocked_by_consent'] ) ) { continue; }
            $id = isset( $finding['id'] ) ? $finding['id'] : '';
            // Security-only anti-spam mechanisms are not automatically treated as optional
            // marketing/analytics trackers; they remain visible for human review.
            if ( 'recaptcha' !== $id ) { return true; }
        }
        return false;
    }

    private function topic_is_applicable( $id, $profile, $scan ) {
        $always = array( 'identity','contact','purposes','legal_basis','data_categories','retention','recipients','rights','complaint' );
        if ( in_array( $id, $always, true ) ) { return true; }
        if ( 'representative' === $id ) { return 'yes' === $profile['representative_applicable']; }
        if ( 'withdrawal' === $id ) {
            foreach ( (array)$profile['treatments'] as $row ) { if ( 'consent' === ( isset($row['legal_basis']) ? $row['legal_basis'] : '' ) ) { return true; } }
            return false;
        }
        if ( 'mandatory' === $id ) { foreach ( (array)$profile['treatments'] as $row ) { if ( in_array( isset($row['mandatory'])?$row['mandatory']:'unknown', array('contract','law'), true ) ) { return true; } } return false; }
        if ( 'transfers' === $id ) { return 'yes' === $profile['transfers_status']; }
        if ( 'source' === $id ) { return in_array( $profile['collection_mode'], array( 'indirect','both' ), true ); }
        if ( 'automated' === $id ) { return 'yes' === $profile['automated_decision']; }
        if ( 'special_categories' === $id ) { return 'yes' === $profile['special_categories']; }
        if ( 'criminal_data' === $id ) { return 'yes' === $profile['criminal_data']; }
        if ( 'minors' === $id ) { return 'yes' === $profile['minors_data']; }
        if ( 'cookies' === $id ) { return 'yes' === $profile['cookie_nonessential'] || $this->scan_has_optional_tracking( $scan ); }
        if ( 'email_pixels' === $id ) { return 'yes' === $profile['email_pixels'] || 'yes' === $profile['tracked_links']; }
        return false;
    }

    private function topic_checks() {
        return array(
            'identity'=>array('label'=>'Identité et coordonnées du responsable de traitement','terms'=>array('responsable du traitement','responsable de traitement','éditeur du site'),'weight'=>7,'scored'=>true),
            'contact'=>array('label'=>'Contact données personnelles / DPO si applicable','terms'=>array('délégué à la protection','dpo','données personnelles','protection des données'),'weight'=>7,'scored'=>true),
            'representative'=>array('label'=>'Représentant UE/EEE — si applicable','terms'=>array('représentant dans l’union européenne','représentant ue','representant ue'),'weight'=>0,'scored'=>false),
            'purposes'=>array('label'=>'Finalités des traitements','terms'=>array('finalité','finalités','pour vous','afin de'),'weight'=>10,'scored'=>true),
            'legal_basis'=>array('label'=>'Bases juridiques et intérêt légitime si applicable','terms'=>array('base légale','base juridique','consentement','intérêt légitime','obligation légale','exécution du contrat','mission d’intérêt public'),'weight'=>10,'scored'=>true),
            'data_categories'=>array('label'=>'Catégories de données concernées','terms'=>array('catégories de données','données collectées','données traitées','types de données'),'weight'=>6,'scored'=>true),
            'retention'=>array('label'=>'Durées de conservation ou critères de détermination','terms'=>array('durée de conservation','conservées pendant','conservation','critères utilisés pour déterminer'),'weight'=>10,'scored'=>true),
            'recipients'=>array('label'=>'Destinataires / catégories de destinataires','terms'=>array('destinataire','sous-traitant','prestataire','hébergeur'),'weight'=>8,'scored'=>true),
            'rights'=>array('label'=>'Droits des personnes et modalités d’exercice','terms'=>array('droit d’accès','droit d\'accès','rectification','effacement','opposition','portabilité','limitation du traitement'),'weight'=>12,'scored'=>true),
            'withdrawal'=>array('label'=>'Retrait du consentement — lorsque cette base est utilisée','terms'=>array('retirer votre consentement','retrait du consentement','retirer son consentement'),'weight'=>0,'scored'=>false),
            'complaint'=>array('label'=>'Réclamation auprès de l’autorité de contrôle','terms'=>array('cnil','autorité de contrôle','autorite de controle','commission nationale de l’informatique'),'weight'=>8,'scored'=>true),
            'mandatory'=>array('label'=>'Caractère obligatoire/facultatif et conséquences — si applicable','terms'=>array('obligatoire','facultatif','nécessaire à la conclusion','consequence','conséquence'),'weight'=>0,'scored'=>false),
            'transfers'=>array('label'=>'Transferts hors EEE et garanties — si concernés','terms'=>array('hors union européenne','hors ue','hors espace économique européen','hors eee','transfert','clauses contractuelles types','décision d’adéquation'),'weight'=>0,'scored'=>false),
            'source'=>array('label'=>'Source des données — si collecte indirecte','terms'=>array('source des données','provenance des données','sources accessibles au public'),'weight'=>0,'scored'=>false),
            'automated'=>array('label'=>'Décision automatisée / profilage — si concernés','terms'=>array('décision automatisée','decision automatisee','profilage','logique sous-jacente'),'weight'=>0,'scored'=>false),
            'special_categories'=>array('label'=>'Catégories particulières (art. 9) — si concernées','terms'=>array('article 9','catégories particulières','données de santé','données biométriques','données génétiques'),'weight'=>0,'scored'=>false),
            'criminal_data'=>array('label'=>'Données pénales / infractions (art. 10) — si concernées','terms'=>array('article 10','condamnations pénales','infractions pénales'),'weight'=>0,'scored'=>false),
            'minors'=>array('label'=>'Information adaptée aux mineurs — si concernés','terms'=>array('mineur','mineurs','enfant','enfants'),'weight'=>0,'scored'=>false),
            'cookies'=>array('label'=>'Cookies / traceurs et gestion du consentement','terms'=>array('cookie','traceur','mesure d’audience','politique de cookies','tout refuser','retirer votre consentement'),'weight'=>0,'scored'=>false),
            'email_pixels'=>array('label'=>'Suivi dans les courriels — pixels ou clics individualisés si utilisés','terms'=>array('pixel de suivi','pixels de suivi','suivi des ouvertures','mesure des ouvertures','tracking des ouvertures','suivi des clics','liens suivis','clics individualisés'),'weight'=>0,'scored'=>false),
        );
    }

    private function normalize_text( $html ) {
        $text = wp_strip_all_tags( html_entity_decode( (string) $html, ENT_QUOTES, 'UTF-8' ) );
        $text = remove_accents( strtolower( $text ) );
        return preg_replace( '/\s+/', ' ', $text );
    }

    private function internal_absolute_url( $href, $base_url = '' ) {
        $href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );
        if ( '' === $href || '#' === $href[0] || preg_match( '#^(?:mailto|tel|javascript|data):#i', $href ) ) {
            return '';
        }

        $base_url = $base_url ? $base_url : home_url( '/' );
        $absolute = WP_Http::make_absolute_url( $href, $base_url );
        $absolute = esc_url_raw( $absolute, array( 'http', 'https' ) );
        if ( ! $absolute || ! wp_http_validate_url( $absolute ) ) {
            return '';
        }

        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $url_host  = strtolower( (string) wp_parse_url( $absolute, PHP_URL_HOST ) );
        if ( ! $home_host || ! $url_host || $home_host !== $url_host ) {
            return '';
        }
        return $absolute;
    }

    private function find_cookie_policy_link( $rendered_html ) {
        if ( ! $rendered_html ) {
            return '';
        }
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $rendered_html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $href = html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );
                $label = strtolower( wp_strip_all_tags( $match[2] ) );
                if ( false === strpos( strtolower( $href ), 'cookie' ) && false === strpos( $label, 'cookie' ) ) {
                    continue;
                }
                $url = $this->internal_absolute_url( $href, home_url( '/' ) );
                if ( $url ) {
                    return $url;
                }
            }
        }
        return '';
    }

    private function find_supporting_policy_links( $rendered_html, $current_url ) {
        $links=array();
        if(preg_match_all('/<a\\b[^>]+href=["\\\']([^"\\\']+)["\\\'][^>]*>(.*?)<\\/a>/is',$rendered_html,$matches,PREG_SET_ORDER)){
            foreach($matches as $match){
                $href=html_entity_decode($match[1],ENT_QUOTES,'UTF-8'); $label=$this->normalize_text($match[2]);
                $absolute=$this->internal_absolute_url($href,$current_url);
                if(!$absolute){continue;}
                $hay=$this->normalize_text($absolute.' '.$label);
                if(false===strpos($hay,'cookie') && false===strpos($hay,'confidential') && false===strpos($hay,'privacy') && false===strpos($hay,'mentions-legales') && false===strpos($hay,'mentions légales')){continue;}
                if(untrailingslashit($absolute)===untrailingslashit($current_url)){continue;}
                $links[]=$absolute;
            }
        }
        return array_slice(array_values(array_unique($links)),0,3);
    }

    private function recommendation_step_for_topic( $topic_id ) {
        $map = array(
            'identity' => 'identity',
            'contact' => 'identity',
            'representative' => 'identity',
            'purposes' => 'treatments',
            'legal_basis' => 'treatments',
            'data_categories' => 'treatments',
            'retention' => 'treatments',
            'recipients' => 'treatments',
            'mandatory' => 'treatments',
            'source' => 'flows',
            'transfers' => 'flows',
            'automated' => 'flows',
            'special_categories' => 'risk',
            'criminal_data' => 'risk',
            'minors' => 'risk',
            'cookies' => 'cookies',
            'email_pixels' => 'email',
            'rights' => 'authority',
            'withdrawal' => 'authority',
            'complaint' => 'authority',
        );
        return isset( $map[ $topic_id ] ) ? $map[ $topic_id ] : 'treatments';
    }

    private function recommendation_step_for_service( $service_id ) {
        return 'mailpoet' === (string) $service_id ? 'email' : 'cookies';
    }

    private function recommendation_destination( $action ) {
        $privacy_url = admin_url( 'admin.php?page=pixel-trackers-manager-privacy' );
        if ( ! empty( $action['target_anchor'] ) ) {
            return $privacy_url . '#' . sanitize_html_class( $action['target_anchor'] );
        }
        if ( ! empty( $action['target_step'] ) ) {
            return $this->assistant_url( sanitize_key( $action['target_step'] ) );
        }

        // Compatibilité avec les recommandations déjà enregistrées par les versions précédentes.
        $title = $this->normalize_text( isset( $action['title'] ) ? $action['title'] : '' );
        if ( false !== strpos( $title, $this->normalize_text( 'Nettoyer la mention gérée' ) ) ) {
            return $privacy_url . '#ptm-sync-block';
        }
        if ( false !== strpos( $title, $this->normalize_text( 'politique de cookies' ) ) ) {
            return $this->assistant_url( 'cookies' );
        }
        foreach ( $this->topic_checks() as $topic_id => $topic ) {
            if ( false !== strpos( $title, $this->normalize_text( $topic['label'] ) ) ) {
                return $this->assistant_url( $this->recommendation_step_for_topic( $topic_id ) );
            }
        }
        foreach ( $this->signatures() as $service_id => $signature ) {
            if ( false !== strpos( $title, $this->normalize_text( $signature['label'] ) ) ) {
                return $this->assistant_url( $this->recommendation_step_for_service( $service_id ) );
            }
        }
        return $this->assistant_url( 'identity' );
    }

    private function build_recommendations( $audit ) {
        $actions=array();
        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        foreach((array)$audit['missing_services'] as $item){
            $actions[]=array('priority'=>'haute','title'=>'Documenter '.$item['label'],'detail'=>'Ce suivi est confirmé actif mais n’est pas encore repéré dans les pages analysées.','target'=>'privacy','service_id'=>$item['id'],'target_step'=>$this->recommendation_step_for_service($item['id']));
        }
        foreach((array)$audit['missing_topics'] as $item){
            $step=$this->recommendation_step_for_topic($item['id']);
            $status=$this->legal_wizard_section_status($profile,$step);
            if(empty($status['complete'])){
                $actions[]=array('priority'=>'moyenne','title'=>'Compléter : '.$item['label'],'detail'=>'Cette information reste absente ou partielle. Ouvrez directement la section correspondante de l’assistant.','target'=>'privacy','topic_id'=>$item['id'],'target_step'=>$step);
            }
        }

        $settings=$this->settings();
        $publication_anchor='ptm-publication-block';
        $privacy_cfg=$this->legal_document_config('privacy',$settings);
        $legal_cfg=$this->legal_document_config('legal_notice',$settings);
        $cookie_cfg=$this->legal_document_config('cookies',$settings);

        if ( ! empty( $profile['treatments'] ) && empty( $audit['profile_privacy_present'] ) ) {
            $actions[]=array(
                'priority'=>'haute',
                'title'=>'Mettre à jour la page de politique de confidentialité',
                'detail'=>!empty($privacy_cfg['page_id'])?'Le document est prêt à être injecté ou mis à jour sur la page sélectionnée.':'Sélectionnez la page de politique de confidentialité puis insérez le document.',
                'target'=>'privacy','target_anchor'=>$publication_anchor,'page_kind'=>'privacy'
            );
        }
        if ( empty( $this->legal_notice_readiness( $profile ) ) && empty( $audit['legal_notice_present'] ) ) {
            $actions[]=array(
                'priority'=>'moyenne',
                'title'=>'Mettre à jour la page de mentions légales',
                'detail'=>!empty($legal_cfg['page_id'])?'Les mentions sont prêtes à être injectées ou mises à jour.':'Sélectionnez d’abord la page de mentions légales.',
                'target'=>'privacy','target_anchor'=>$publication_anchor,'page_kind'=>'legal_notice'
            );
        }
        if ( 'yes' === $profile['cookie_nonessential'] && empty( $audit['cookie_profile_present'] ) ) {
            $actions[]=array(
                'priority'=>'moyenne',
                'title'=>'Mettre à jour les informations sur les cookies',
                'detail'=>!empty($cookie_cfg['page_id'])?'La page cookies peut être mise à jour depuis le bloc de publication.':'Sélectionnez une page cookies si votre organisation utilise une page dédiée.',
                'target'=>'privacy','target_anchor'=>$publication_anchor,'page_kind'=>'cookies'
            );
        }

        foreach((array)$audit['stale_dendrila_privacy_services'] as $item){
            $actions[]=array('priority'=>'faible','title'=>'Nettoyer la mention gérée : '.$item['label'],'detail'=>'Ce service n’est plus un suivi actif mais figure encore dans un bloc géré par Dendrila Privacy.','target'=>'privacy','service_id'=>$item['id'],'target_anchor'=>$publication_anchor);
        }
        foreach((array)(isset($audit['stale_cookie_mentions'])?$audit['stale_cookie_mentions']:array()) as $item){
            $actions[]=array('priority'=>'faible','title'=>'Actualiser les informations cookies : '.$item['label'],'detail'=>'Le suivi est désactivé mais d’anciennes mentions techniques restent repérées.','target'=>'privacy','service_id'=>$item['id'],'target_step'=>'cookies');
        }

        $unique=array();$seen=array();
        foreach($actions as $action){
            $key=(isset($action['title'])?$action['title']:'').'|'.(isset($action['target_anchor'])?$action['target_anchor']:'').'|'.(isset($action['target_step'])?$action['target_step']:'');
            if(isset($seen[$key])){continue;}$seen[$key]=1;$unique[]=$action;
        }
        return $unique;
    }

    public function audit_privacy_page( $page_id ) {
        $settings = $this->settings();
        if ( $page_id ) { $settings['privacy_page_id'] = absint( $page_id ); }
        return $this->audit_legal_pages( $this->legal_page_ids( $settings ) );
    }

    private function audit_legal_pages( $page_ids ) {
        $page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
        if ( ! $page_ids ) {
            return new WP_Error( 'pixel_trackers_manager_page', 'Sélectionnez au moins une page juridique à contrôler.' );
        }

        $settings = $this->settings();
        $kind_by_id = array();
        if ( ! empty( $settings['privacy_page_id'] ) ) { $kind_by_id[ (int) $settings['privacy_page_id'] ] = 'privacy'; }
        if ( ! empty( $settings['legal_notice_page_id'] ) ) { $kind_by_id[ (int) $settings['legal_notice_page_id'] ] = 'legal_notice'; }
        if ( ! empty( $settings['cookie_page_id'] ) ) { $kind_by_id[ (int) $settings['cookie_page_id'] ] = 'cookies'; }

        $documents = array();
        $raw_combined = '';
        $seen_urls = array();
        foreach ( $page_ids as $page_id ) {
            $post = get_post( $page_id );
            if ( ! $post || 'publish' !== $post->post_status ) { continue; }
            $url = get_permalink( $page_id );
            if ( ! $url ) { continue; }
            $local = $this->local_page_rendered_content( $page_id );
            $remote = $this->fetch_html( $url );
            $remote_html = is_wp_error( $remote ) ? '' : (string) $remote;
            $rendered = trim( $local . "\n" . $remote_html );
            $kind = isset( $kind_by_id[ $page_id ] ) ? $kind_by_id[ $page_id ] : 'linked';
            $documents[] = array(
                'page_id' => $page_id,
                'kind' => $kind,
                'label' => get_the_title( $page_id ),
                'url' => $url,
                'edit_url' => $this->legal_page_edit_url( $page_id ),
                'builder' => $this->page_builder_info( $page_id ),
                'text' => $this->normalize_text( $post->post_content . ' ' . $rendered ),
            );
            $raw_combined .= "\n" . (string) $post->post_content;
            $seen_urls[ untrailingslashit( $url ) ] = true;

            foreach ( $this->find_supporting_policy_links( $rendered, $url ) as $supporting_url ) {
                $key = untrailingslashit( $supporting_url );
                if ( isset( $seen_urls[ $key ] ) ) { continue; }
                $html = $this->fetch_html( $supporting_url );
                if ( is_wp_error( $html ) ) { continue; }
                $seen_urls[ $key ] = true;
                $documents[] = array(
                    'page_id' => 0,
                    'kind' => false !== strpos( $this->normalize_text( $supporting_url ), 'cookie' ) ? 'cookies' : 'linked',
                    'label' => false !== strpos( $this->normalize_text( $supporting_url ), 'cookie' ) ? 'Politique de cookies liée' : 'Document juridique lié',
                    'url' => $supporting_url,
                    'edit_url' => '',
                    'builder' => array( 'id'=>'external','label'=>'Lien','safe_mode'=>true ),
                    'text' => $this->normalize_text( $html ),
                );
                if ( count( $documents ) >= 6 ) { break; }
            }
        }

        if ( ! $documents ) {
            return new WP_Error( 'pixel_trackers_manager_page', 'Les pages sélectionnées sont introuvables ou non publiées.' );
        }

        $combined = '';
        foreach ( $documents as $doc ) { $combined .= ' ' . $doc['text']; }

        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        $scan = get_option( self::OPTION_SCAN, array() );
        $topics = array(); $missing_topics = array(); $applicable_topics = 0; $satisfied_topics = 0;
        foreach ( $this->topic_checks() as $id => $check ) {
            $present = false; $where = '';
            foreach ( $documents as $doc ) {
                foreach ( $check['terms'] as $term ) {
                    if ( false !== strpos( $doc['text'], $this->normalize_text( $term ) ) ) {
                        $present = true; $where = $doc['label']; break 2;
                    }
                }
            }
            $applicable = $this->topic_is_applicable( $id, $profile, $scan );
            $partial = $applicable && ! $present && $this->topic_has_profile_data( $id, $profile );
            $status = ! $applicable ? 'not_applicable' : ( $present ? 'found' : ( $partial ? 'partial' : 'missing' ) );
            $item = array(
                'id'=>$id, 'label'=>$check['label'], 'present'=>$present, 'partial'=>$partial,
                'applicable'=>$applicable, 'status'=>$status, 'where'=>$where, 'scored'=>$applicable, 'weight'=>$applicable?1:0
            );
            $topics[] = $item;
            if ( $applicable ) {
                $applicable_topics++;
                if ( $present ) { $satisfied_topics++; } else { $missing_topics[] = $item; }
            }
        }


        $services = array(); $missing_services = array(); $active_total = 0; $active_mentioned = 0;
        $signatures = $this->signatures();
        foreach ( (array) ( isset( $scan['findings'] ) ? $scan['findings'] : array() ) as $finding ) {
            if ( empty( $finding['active_tracking'] ) && empty( $finding['blocked_by_consent'] ) ) { continue; }
            $active_total++;
            $id = $finding['id'];
            $aliases = isset( $signatures[$id] ) ? $signatures[$id]['aliases'] : array( $finding['label'] );
            $mentioned = false; $where = '';
            foreach ( $documents as $doc ) {
                foreach ( $aliases as $alias ) {
                    if ( false !== strpos( $doc['text'], $this->normalize_text( $alias ) ) ) {
                        $mentioned = true; $where = $doc['label']; break 2;
                    }
                }
            }
            $item = array( 'id'=>$id, 'label'=>$finding['label'], 'mentioned'=>$mentioned, 'where'=>$where, 'status'=>$mentioned?'found':'missing' );
            $services[] = $item;
            if ( $mentioned ) { $active_mentioned++; } else { $missing_services[] = $item; }
        }

        $applicable_total = $applicable_topics + $active_total;
        $satisfied_total = $satisfied_topics + $active_mentioned;
        $core_percent = $applicable_topics ? (int) round( ( $satisfied_topics / $applicable_topics ) * 100 ) : 100;
        $tracker_percent = $active_total ? (int) round( ( $active_mentioned / $active_total ) * 100 ) : 100;
        $score = $applicable_total ? (int) round( ( $satisfied_total / $applicable_total ) * 100 ) : 100;

        $stale = array();
        if ( false !== strpos( $raw_combined, self::BLOCK_START ) && preg_match( '/'.preg_quote(self::BLOCK_START,'/').'(.*?)'.preg_quote(self::BLOCK_END,'/').'/s', $raw_combined, $m ) ) {
            $block = $this->normalize_text( $m[1] );
            foreach ( $this->signatures() as $id=>$sig ) {
                $is_active=false;
                foreach ( (array)( isset($scan['findings']) ? $scan['findings'] : array() ) as $f ) {
                    if ( $f['id']===$id && !empty($f['active_tracking']) ) { $is_active=true; break; }
                }
                if ( !$is_active ) {
                    foreach ( $sig['aliases'] as $alias ) {
                        if ( false!==strpos($block,$this->normalize_text($alias)) ) { $stale[]=array('id'=>$id,'label'=>$sig['label']); break; }
                    }
                }
            }
        }

        $stale_cookie_mentions = array();
        foreach ( (array)( isset($scan['findings']) ? $scan['findings'] : array() ) as $finding ) {
            if ( 'mailpoet' === ( isset($finding['id']) ? $finding['id'] : '' ) && 'disabled' === ( isset($finding['tracking_state']) ? $finding['tracking_state'] : '' ) ) {
                $cookie_names = array('mailpoet_page_view','mailpoet_subscriber','mailpoet_revenue_tracking');
                $seen=array();
                foreach ( $cookie_names as $cookie_name ) { if ( false!==strpos($combined,$this->normalize_text($cookie_name)) ) { $seen[]=$cookie_name; } }
                if ( $seen ) { $stale_cookie_mentions[]=array('id'=>'mailpoet','label'=>'MailPoet','cookies'=>$seen); }
            }
        }

        $document_refs = array();
        foreach ( $documents as $doc ) {
            $document_refs[] = array(
                'page_id'=>isset($doc['page_id'])?$doc['page_id']:0,
                'kind'=>isset($doc['kind'])?$doc['kind']:'linked',
                'label'=>$doc['label'], 'url'=>$doc['url'],
                'edit_url'=>isset($doc['edit_url'])?$doc['edit_url']:'',
                'builder'=>isset($doc['builder']['label'])?$doc['builder']['label']:''
            );
        }

        $profile_privacy_present = false;
        if ( !empty($profile['privacy_contact']) && false!==strpos($combined,$this->normalize_text($profile['privacy_contact'])) ) {
            foreach ( (array)$profile['treatments'] as $row ) {
                if ( !empty($row['purpose']) && false!==strpos($combined,$this->normalize_text($row['purpose'])) ) { $profile_privacy_present=true; break; }
            }
        }
        $legal_notice_present = !empty($profile['controller_name']) && false!==strpos($combined,$this->normalize_text($profile['controller_name']));
        if ( $legal_notice_present && !empty($profile['host_name']) ) { $legal_notice_present = false!==strpos($combined,$this->normalize_text($profile['host_name'])); }
        $cookie_profile_present = 'yes' !== $profile['cookie_nonessential'] || false!==strpos($combined,'cookie');

        $previous = get_option( self::OPTION_PAGE_AUDIT, array() );
        $audit = array(
            'score_model'=>4, 'generated_at'=>gmdate('c'),
            'page_id'=>!empty($settings['privacy_page_id'])?(int)$settings['privacy_page_id']:(int)$page_ids[0],
            'page_title'=>!empty($settings['privacy_page_id'])?get_the_title((int)$settings['privacy_page_id']):get_the_title((int)$page_ids[0]),
            'page_url'=>!empty($settings['privacy_page_id'])?get_permalink((int)$settings['privacy_page_id']):get_permalink((int)$page_ids[0]),
            'documents'=>$document_refs, 'cookie_policy_url'=>!empty($settings['cookie_page_id'])?get_permalink((int)$settings['cookie_page_id']):'',
            'topics'=>$topics, 'services'=>$services, 'missing_topics'=>$missing_topics, 'missing_services'=>$missing_services,
            'stale_dendrila_privacy_services'=>$stale, 'stale_cookie_mentions'=>$stale_cookie_mentions,
            'core_documentation_percent'=>$core_percent, 'tracker_documentation_percent'=>$tracker_percent, 'technical_coverage_percent'=>$score,
            'active_tracking_count'=>$active_total, 'applicable_total'=>$applicable_total, 'satisfied_total'=>$satisfied_total, 'profile_privacy_present'=>$profile_privacy_present,
            'legal_notice_present'=>$legal_notice_present, 'cookie_profile_present'=>$cookie_profile_present,
            'warning'=>'Couverture des éléments réellement applicables détectés par Dendrila Privacy. Ce score aide au suivi ; il ne certifie pas la conformité juridique.',
        );
        $audit['recommendations'] = $this->build_recommendations( $audit );
        $audit['score_delta'] = isset($previous['technical_coverage_percent']) ? $score-(int)$previous['technical_coverage_percent'] : null;
        update_option( self::OPTION_PAGE_AUDIT, $audit, false );
        return $audit;
    }

    private function legal_section_fields() {
        return array(
            'identity' => array( 'site_editor_name','site_editor_address','controller_name','controller_address','privacy_contact','dpo_designated','dpo_contact','representative_applicable','controller_representative','controller_representative_contact','entity_type','company_country','company_registration_number','company_registration_label','company_siren','company_siret','company_vat','company_legal_form','company_capital','company_registry','company_activity','site_name','site_url','public_contact_email','public_contact_phone','publication_director','host_name','host_address','host_phone','host_source_url','regulated_activity_details','rep_idu','company_lookup_source','company_lookup_at' ),
            'treatments' => array( 'treatments' ),
            'flows' => array( 'collection_mode','indirect_services','indirect_categories','indirect_sources','mandatory_information','transfers_status','transfer_destinations','transfer_mechanism','transfer_safeguards','automated_decision','automated_details' ),
            'external' => array( 'external_email_use','external_bulk_email','external_bulk_unsubscribe','external_bulk_bcc','external_email_tools','external_messaging','external_messaging_tools','external_booking','external_booking_tools','external_forms','external_form_tools','external_payments','external_payment_tools','external_files','external_file_tools','backup_reviewed' ),
            'risk' => array( 'special_categories','special_categories_basis','criminal_data','criminal_data_basis','minors_data','minors_details','high_risk_processing','dpia_status' ),
            'cookies' => array( 'cookie_nonessential','cookie_consent_status','cookie_preferences','cookie_choice_retention','cookie_cross_device','cookie_cross_device_explanation' ),
            'email' => array( 'email_pixels','email_pixel_purposes','email_pixel_status','email_pixel_preferences','tracked_links','tracked_links_details' ),
            'authority' => array( 'supervisory_authority','supervisory_url' ),
        );
    }

    private function merge_legal_section( $current, $raw, $section ) {
        $sections = $this->legal_section_fields();
        if ( ! isset( $sections[ $section ] ) ) {
            return $this->sanitize_legal_profile( $raw );
        }
        $current = wp_parse_args( is_array( $current ) ? $current : array(), $this->legal_profile_defaults() );
        $clean = $this->sanitize_legal_profile( is_array( $raw ) ? $raw : array() );
        foreach ( $sections[ $section ] as $field ) {
            $current[ $field ] = isset( $clean[ $field ] ) ? $clean[ $field ] : $this->legal_profile_defaults()[ $field ];
        }
        return $current;
    }

    private function legal_wizard_section_status( $profile, $section ) {
        $p = wp_parse_args( is_array( $profile ) ? $profile : array(), $this->legal_profile_defaults() );
        $missing = array();

        if ( 'identity' === $section ) {
            if ( empty( $p['site_editor_name'] ) && empty( $p['controller_name'] ) ) { $missing[] = 'éditeur du site'; }
            if ( empty( $p['controller_name'] ) ) { $missing[] = 'responsable du traitement'; }
            if ( empty( $p['privacy_contact'] ) ) { $missing[] = 'contact pour exercer les droits'; }
            if ( 'yes' === $p['dpo_designated'] && empty( $p['dpo_contact'] ) ) { $missing[] = 'coordonnées du DPO désigné'; }
        } elseif ( 'treatments' === $section ) {
            if ( empty( $p['treatments'] ) ) { $missing[] = 'au moins une utilisation de données personnelles'; }
        } elseif ( 'flows' === $section ) {
            // Unknown is intentionally non-blocking. We only come back here after the user has
            // explicitly said that indirect collection applies and the necessary source is absent.
            if ( in_array( $p['collection_mode'], array( 'indirect','both' ), true ) ) {
                $services = isset( $p['indirect_services'] ) && is_array( $p['indirect_services'] ) ? $p['indirect_services'] : array();
                if ( empty( $services ) && empty( $p['indirect_sources'] ) ) { $missing[] = 'provenance des données reçues indirectement'; }
                foreach ( $services as $service ) {
                    if ( empty( $service['categories'] ) || empty( $service['source'] ) ) { $missing[] = 'données et provenance d’un service déclaré'; break; }
                }
            }
            if ( 'yes' === $p['transfers_status'] && empty( $p['transfer_destinations'] ) ) { $missing[] = 'destination d’un transfert hors UE/EEE déclaré'; }
            if ( 'yes' === $p['automated_decision'] && empty( $p['automated_details'] ) ) { $missing[] = 'explication de la décision automatisée déclarée'; }
        } elseif ( 'external' === $section ) {
            // Les outils externes sont contextuels : ne pas transformer une réponse
            // « je ne sais pas » en erreur. Les conseils deviennent applicables
            // uniquement lorsque l’utilisateur déclare ou que PTM détecte un usage.
        } elseif ( 'risk' === $section ) {
            if ( 'yes' === $p['special_categories'] && empty( $p['special_categories_basis'] ) ) { $missing[] = 'cadre prévu pour les données sensibles déclarées'; }
            if ( 'yes' === $p['criminal_data'] && empty( $p['criminal_data_basis'] ) ) { $missing[] = 'cadre prévu pour les données pénales déclarées'; }
            if ( 'yes' === $p['minors_data'] && empty( $p['minors_details'] ) ) { $missing[] = 'modalités prévues pour les mineurs déclarés'; }
        } elseif ( 'cookies' === $section ) {
            $scan = get_option( self::OPTION_SCAN, array() );
            $has_optional = $this->scan_has_optional_tracking( $scan );
            if ( 'yes' === $p['cookie_nonessential'] || $has_optional ) {
                if ( 'yes' !== $p['cookie_consent_status'] && empty( $this->settings()['consent_enabled'] ) ) { $missing[] = 'choix avant l’activation des traceurs facultatifs'; }
            }
        } elseif ( 'email' === $section ) {
            if ( 'yes' === $p['email_pixels'] && empty( $p['email_pixel_purposes'] ) ) { $missing[] = 'raison du suivi d’ouverture déclaré'; }
            if ( 'yes' === $p['tracked_links'] && empty( $p['tracked_links_details'] ) ) { $missing[] = 'explication du suivi individualisé des clics déclaré'; }
        }

        return array( 'complete' => empty( $missing ), 'missing' => array_values( array_unique( $missing ) ) );
    }

    private function legal_wizard_resume_step( $profile ) {
        foreach ( array( 'identity', 'treatments', 'flows', 'external', 'risk', 'cookies', 'email', 'authority' ) as $section ) {
            $status = $this->legal_wizard_section_status( $profile, $section );
            if ( empty( $status['complete'] ) ) {
                return $section;
            }
        }
        return 'preview';
    }

    private function legal_wizard_next_incomplete( $profile, $after_section = '' ) {
        $sections = array( 'identity','treatments','flows','external','risk','cookies','email','authority' );
        $start = array_search( $after_section, $sections, true );
        $ordered = false === $start ? $sections : array_merge( array_slice( $sections, $start + 1 ), array_slice( $sections, 0, $start + 1 ) );
        foreach ( $ordered as $section ) {
            if ( $section === $after_section ) { continue; }
            $status = $this->legal_wizard_section_status( $profile, $section );
            if ( empty( $status['complete'] ) ) { return $section; }
        }
        return 'preview';
    }

    public function ajax_mark_wizard_reviewed() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Droits insuffisants.' ), 403 ); }
        if ( ! check_ajax_referer( 'pixel_trackers_manager_save_legal_section', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'Session expirée.' ), 403 ); }
        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        $profile['wizard_reviewed_once'] = 1;
        update_option( self::OPTION_LEGAL_PROFILE, $profile, false );
        wp_send_json_success( array( 'reviewed' => true ) );
    }

    public function ajax_save_legal_section() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Droits insuffisants.' ), 403 );
        }
        if ( ! check_ajax_referer( 'pixel_trackers_manager_save_legal_section', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'La session de sécurité a expiré. Rechargez la page puis réessayez.' ), 403 );
        }

        // Keep accidental output (notices from hooks, themes or other plugins) out of the JSON response.
        $buffer_started = false;
        if ( function_exists( 'ob_start' ) ) {
            ob_start();
            $buffer_started = true;
        }

        try {
            $section = isset( $_POST['legal_section'] ) ? sanitize_key( wp_unslash( $_POST['legal_section'] ) ) : '';
            $sections = $this->legal_section_fields();
            if ( ! isset( $sections[ $section ] ) ) {
                if ( $buffer_started ) { ob_end_clean(); }
                wp_send_json_error( array( 'message' => 'Bloc inconnu.' ), 400 );
            }
            $raw = $this->verified_post_array( 'legal_profile' );
            $stored = get_option( self::OPTION_LEGAL_PROFILE, array() );
            if ( ! is_array( $stored ) ) { $stored = array(); }
            $current = wp_parse_args( $stored, $this->legal_profile_defaults() );
            $profile = $this->merge_legal_section( $current, $raw, $section );
            update_option( self::OPTION_LEGAL_PROFILE, $profile, false );
        } catch ( Throwable $exception ) {
            if ( $buffer_started ) { ob_end_clean(); }
            $this->runtime_warning( 'legal-section-save', $exception->getMessage() );
            wp_send_json_error( array( 'message' => 'L’enregistrement a échoué côté serveur. Rechargez la page puis réessayez. Détail technique : ' . $exception->getMessage() ), 500 );
        }

        if ( $buffer_started ) {
            $unexpected_output = trim( (string) ob_get_clean() );
            if ( '' !== $unexpected_output ) {
                $this->runtime_warning( 'legal-section-output', wp_strip_all_tags( $unexpected_output ) );
            }
        }

        // A block save is deliberately local and cheap. Do not crawl or re-audit
        // public pages here: the user asked to save a field, not to rescan the site.
        $section_status = $this->legal_wizard_section_status( $profile, $section );
        $next_incomplete = $this->legal_wizard_next_incomplete( $profile, $section );
        $all_complete = 'preview' === $this->legal_wizard_resume_step( $profile );
        wp_send_json_success( array(
            'message' => ! empty( $section_status['complete'] ) ? 'Bloc enregistré ✓' : 'Bloc enregistré ✓ — les éléments restant à vérifier restent dans les actions du tableau de bord.',
            'sectionComplete' => ! empty( $section_status['complete'] ),
            'missing' => isset( $section_status['missing'] ) ? $section_status['missing'] : array(),
            'nextIncomplete' => $next_incomplete,
            'allComplete' => $all_complete,
            'reviewedOnce' => ! empty( $profile['wizard_reviewed_once'] ),
            'coverage' => null,
            'actionsCount' => null,
            'auditDirty' => true,
        ) );
    }

    private function legal_preview_html( $profile ) {
        $profile = wp_parse_args( is_array( $profile ) ? $profile : array(), $this->legal_profile_defaults() );
        ob_start();
        if ( ! empty( $profile['controller_name'] ) || ! empty( $profile['treatments'] ) ) {
            echo '<h4>Politique de confidentialité</h4><div class="ptm-preview-frame">' . wp_kses_post( $this->generated_legal_block( $profile ) ) . '</div>';
            echo '<h4>Mentions légales</h4><div class="ptm-preview-frame">' . wp_kses_post( $this->generated_legal_notice( $profile ) ) . '</div>';
            if ( 'yes' === $profile['cookie_nonessential'] || $this->scan_has_optional_tracking() ) {
                echo '<h4>Cookies et traceurs</h4><div class="ptm-preview-frame">' . wp_kses_post( $this->generated_cookie_policy( $profile ) ) . '</div>';
            }
        } else {
            echo '<div class="ptm-empty-state"><strong>Aucun brouillon pour le moment.</strong><br>Vous pouvez revenir aux étapes précédentes ou enregistrer ce brouillon vide et reprendre plus tard.</div>';
        }
        return (string) ob_get_clean();
    }

    public function ajax_wizard_preview() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message'=>'Droits insuffisants.' ), 403 ); }
        check_ajax_referer( 'pixel_trackers_manager_save_legal_section', 'nonce' );
        $profile = get_option( self::OPTION_LEGAL_PROFILE, array() );
        if ( ! is_array( $profile ) ) { $profile = array(); }
        $profile = wp_parse_args( $profile, $this->legal_profile_defaults() );
        $readiness = $this->legal_profile_readiness( $profile );
        wp_send_json_success( array(
            'html' => $this->legal_preview_html( $profile ),
            'remaining' => isset( $readiness['errors'] ) && is_array( $readiness['errors'] ) ? count( $readiness['errors'] ) : 0,
        ) );
    }

    /**
     * Countries handled by the optional public-company lookup.
     *
     * France uses the DINUM company directory. Norway uses the open Brønnøysund
     * Register Centre API. Other listed countries use VIES for VAT-number validation;
     * VIES can return a name/address only when the national administration exposes it.
     */
    private function company_lookup_countries() {
        return array(
            'AT' => 'Autriche', 'BE' => 'Belgique', 'BG' => 'Bulgarie', 'HR' => 'Croatie',
            'CY' => 'Chypre', 'CZ' => 'Tchéquie', 'DE' => 'Allemagne', 'DK' => 'Danemark',
            'EE' => 'Estonie', 'EL' => 'Grèce', 'ES' => 'Espagne', 'FI' => 'Finlande',
            'FR' => 'France', 'HU' => 'Hongrie', 'IE' => 'Irlande', 'IT' => 'Italie',
            'LT' => 'Lituanie', 'LU' => 'Luxembourg', 'LV' => 'Lettonie', 'MT' => 'Malte',
            'NL' => 'Pays-Bas', 'NO' => 'Norvège', 'PL' => 'Pologne', 'PT' => 'Portugal', 'RO' => 'Roumanie',
            'SE' => 'Suède', 'SI' => 'Slovénie', 'SK' => 'Slovaquie',
            'XI' => 'Irlande du Nord (VIES)',
        );
    }

    private function default_company_country() {
        $locale = str_replace( '-', '_', (string) determine_locale() );
        $parts = array_values( array_filter( explode( '_', $locale ) ) );
        $country = count( $parts ) > 1 ? strtoupper( (string) end( $parts ) ) : '';
        if ( 'GR' === $country ) {
            $country = 'EL';
        }
        $countries = $this->company_lookup_countries();
        if ( $country && isset( $countries[ $country ] ) ) {
            return $country;
        }
        return 'fr' === strtolower( $locale ) ? 'FR' : '';
    }

    private function vies_xml_value( $xml, $tag ) {
        if ( ! is_string( $xml ) || '' === $xml ) {
            return '';
        }
        $tag_pattern = preg_quote( (string) $tag, '/' );
        if ( ! preg_match( '/<(?:[A-Za-z0-9_.-]+:)?' . $tag_pattern . '\\b[^>]*>(.*?)<\\/(?:[A-Za-z0-9_.-]+:)?' . $tag_pattern . '>/is', $xml, $matches ) ) {
            return '';
        }
        $value = html_entity_decode( wp_strip_all_tags( (string) $matches[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
        return trim( preg_replace( '/\\s+/', ' ', $value ) );
    }

    public function ajax_company_search() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Droits insuffisants.' ), 403 );
        }
        check_ajax_referer( 'pixel_trackers_manager_company_search', 'nonce' );

        $query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
        $country = isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : '';
        $countries = $this->company_lookup_countries();

        if ( ! isset( $countries[ $country ] ) ) {
            wp_send_json_error( array( 'message' => 'Choisissez d’abord le pays de la structure.' ), 400 );
        }
        if ( strlen( $query ) < 2 ) {
            wp_send_json_error( array( 'message' => 'Saisissez au moins 2 caractères.' ), 400 );
        }

        $cache_key = 'dendrila_company_' . strtolower( $country ) . '_' . md5( strtolower( $query ) );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['results'] ) ) {
            $cached['cache'] = true;
            wp_send_json_success( $cached );
        }

        if ( 'FR' === $country ) {
            $url = add_query_arg(
                array(
                    'q' => $query,
                    'page' => 1,
                    'per_page' => 8,
                    'minimal' => 'true',
                    'include' => 'siege,complements,tva',
                ),
                'https://recherche-entreprises.api.gouv.fr/search'
            );
            $response = wp_safe_remote_get( $url, array(
                'timeout' => 10,
                'redirection' => 2,
                'headers' => array(
                    'Accept' => 'application/json',
                    'User-Agent' => 'DendrilaPrivacy/' . self::VERSION . ' (+https://wordpress.org/plugins/dendrila-privacy/)',
                ),
            ) );
            if ( is_wp_error( $response ) ) {
                wp_send_json_error( array( 'message' => 'Le registre public français est momentanément inaccessible : ' . $response->get_error_message() ), 502 );
            }
            $code = wp_remote_retrieve_response_code( $response );
            $json = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 !== (int) $code || ! is_array( $json ) ) {
                wp_send_json_error( array( 'message' => 'Réponse inattendue du registre public français.' ), 502 );
            }

            $items = array();
            foreach ( array_slice( isset( $json['results'] ) && is_array( $json['results'] ) ? $json['results'] : array(), 0, 8 ) as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                $siege = isset( $row['siege'] ) && is_array( $row['siege'] ) ? $row['siege'] : array();
                $complements = isset( $row['complements'] ) && is_array( $row['complements'] ) ? $row['complements'] : array();
                $vat = '';
                if ( isset( $row['tva'] ) ) {
                    if ( is_array( $row['tva'] ) ) { $vat = (string) reset( $row['tva'] ); }
                    elseif ( is_string( $row['tva'] ) ) { $vat = $row['tva']; }
                }
                $entity_type = ! empty( $complements['est_association'] ) ? 'association' : ( ! empty( $complements['est_entrepreneur_individuel'] ) ? 'individual' : 'company' );
                $items[] = array(
                    'country' => 'FR',
                    'name' => sanitize_text_field( isset( $row['nom_complet'] ) ? $row['nom_complet'] : ( isset( $row['nom_raison_sociale'] ) ? $row['nom_raison_sociale'] : '' ) ),
                    'siren' => sanitize_text_field( isset( $row['siren'] ) ? $row['siren'] : '' ),
                    'siret' => sanitize_text_field( isset( $siege['siret'] ) ? $siege['siret'] : '' ),
                    'registrationNumber' => sanitize_text_field( isset( $row['siren'] ) ? $row['siren'] : '' ),
                    'registrationLabel' => 'SIREN',
                    'address' => sanitize_text_field( isset( $siege['adresse'] ) ? $siege['adresse'] : '' ),
                    'vat' => sanitize_text_field( $vat ),
                    'legalForm' => ( isset( $row['nature_juridique'] ) && ! preg_match( '/^\\d+$/', (string) $row['nature_juridique'] ) ) ? sanitize_text_field( (string) $row['nature_juridique'] ) : '',
                    'activity' => sanitize_text_field( isset( $siege['activite_principale'] ) ? (string) $siege['activite_principale'] : ( isset( $row['activite_principale'] ) ? (string) $row['activite_principale'] : '' ) ),
                    'entityType' => $entity_type,
                    'status' => sanitize_text_field( isset( $siege['etat_administratif'] ) ? (string) $siege['etat_administratif'] : '' ),
                    'source' => 'https://recherche-entreprises.api.gouv.fr/',
                );
            }

            $payload = array(
                'results' => $items,
                'source' => 'https://recherche-entreprises.api.gouv.fr/',
                'provider' => 'fr-dinum',
                'message' => $items ? '' : 'Aucun résultat. Essayez le SIREN/SIRET ou une raison sociale plus précise.',
            );
            set_transient( $cache_key, $payload, 6 * HOUR_IN_SECONDS );
            wp_send_json_success( $payload );
        }

        if ( 'NO' === $country ) {
            $digits = preg_replace( '/\\D+/', '', $query );
            $params = array( 'size' => 8 );
            if ( 9 === strlen( $digits ) ) {
                $params['organisasjonsnummer'] = $digits;
            } else {
                $params['navn'] = $query;
                $params['navnMetodeForSoek'] = 'FORTLOEPENDE';
            }

            $url = add_query_arg( $params, 'https://data.brreg.no/enhetsregisteret/api/enheter' );
            $response = wp_safe_remote_get( $url, array(
                'timeout' => 10,
                'redirection' => 2,
                'headers' => array(
                    'Accept' => 'application/vnd.brreg.enhetsregisteret.enhet.v2+json',
                    'User-Agent' => 'DendrilaPrivacy/' . self::VERSION . ' (+https://wordpress.org/plugins/dendrila-privacy/)',
                ),
            ) );
            if ( is_wp_error( $response ) ) {
                wp_send_json_error( array( 'message' => 'Le registre public norvégien est momentanément inaccessible : ' . $response->get_error_message() ), 502 );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $json = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 !== $code || ! is_array( $json ) ) {
                wp_send_json_error( array( 'message' => 'Réponse inattendue du registre public norvégien.' ), 502 );
            }

            $rows = isset( $json['_embedded']['enheter'] ) && is_array( $json['_embedded']['enheter'] ) ? $json['_embedded']['enheter'] : array();
            $items = array();
            foreach ( array_slice( $rows, 0, 8 ) as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                $orgnr = sanitize_text_field( isset( $row['organisasjonsnummer'] ) ? (string) $row['organisasjonsnummer'] : '' );
                $address_data = isset( $row['forretningsadresse'] ) && is_array( $row['forretningsadresse'] ) ? $row['forretningsadresse'] : array();
                if ( ! $address_data && isset( $row['postadresse'] ) && is_array( $row['postadresse'] ) ) {
                    $address_data = $row['postadresse'];
                }
                $lines = isset( $address_data['adresse'] ) && is_array( $address_data['adresse'] ) ? array_map( 'sanitize_text_field', $address_data['adresse'] ) : array();
                $city_line = trim(
                    ( isset( $address_data['postnummer'] ) ? sanitize_text_field( (string) $address_data['postnummer'] ) : '' )
                    . ' '
                    . ( isset( $address_data['poststed'] ) ? sanitize_text_field( (string) $address_data['poststed'] ) : '' )
                );
                if ( $city_line ) { $lines[] = $city_line; }

                $legal_form = isset( $row['organisasjonsform']['beskrivelse'] ) ? sanitize_text_field( (string) $row['organisasjonsform']['beskrivelse'] ) : '';
                $activity = isset( $row['naeringskode1']['beskrivelse'] ) ? sanitize_text_field( (string) $row['naeringskode1']['beskrivelse'] ) : '';
                $status_bits = array();
                if ( ! empty( $row['konkurs'] ) ) { $status_bits[] = 'faillite'; }
                if ( ! empty( $row['underAvvikling'] ) ) { $status_bits[] = 'liquidation'; }
                if ( ! empty( $row['registrertIMvaregisteret'] ) ) { $status_bits[] = 'registre TVA'; }

                $items[] = array(
                    'country' => 'NO',
                    'name' => sanitize_text_field( isset( $row['navn'] ) ? (string) $row['navn'] : '' ),
                    'address' => implode( ', ', array_filter( $lines ) ),
                    'vat' => '',
                    'registrationNumber' => $orgnr,
                    'registrationLabel' => 'Organisasjonsnummer',
                    'registry' => $orgnr ? 'Enhetsregisteret — ' . $orgnr : 'Enhetsregisteret',
                    'legalForm' => $legal_form,
                    'activity' => $activity,
                    'entityType' => 'company',
                    'status' => $status_bits ? implode( ', ', $status_bits ) : 'enregistré',
                    'source' => 'https://data.brreg.no/enhetsregisteret/api/enheter',
                );
            }

            $payload = array(
                'results' => $items,
                'source' => 'https://data.brreg.no/enhetsregisteret/api/enheter',
                'provider' => 'no-brreg',
                'message' => $items ? '' : 'Aucune structure trouvée dans le registre public norvégien.',
            );
            set_transient( $cache_key, $payload, 6 * HOUR_IN_SECONDS );
            wp_send_json_success( $payload );
        }

        // VIES is a VAT validation service, not a name-search directory.
        $compact = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $query ) );
        $accepted_prefixes = array( $country );
        if ( 'EL' === $country ) {
            $accepted_prefixes[] = 'GR';
        }
        foreach ( $accepted_prefixes as $prefix ) {
            if ( 0 === strpos( $compact, $prefix ) ) {
                $compact = substr( $compact, strlen( $prefix ) );
                break;
            }
        }
        if ( strlen( $compact ) < 2 ) {
            wp_send_json_error( array( 'message' => 'Saisissez un numéro de TVA valide pour le pays choisi.' ), 400 );
        }

        $soap = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
            . '<soapenv:Header/><soapenv:Body><urn:checkVat>'
            . '<urn:countryCode>' . esc_xml( $country ) . '</urn:countryCode>'
            . '<urn:vatNumber>' . esc_xml( $compact ) . '</urn:vatNumber>'
            . '</urn:checkVat></soapenv:Body></soapenv:Envelope>';

        $response = wp_safe_remote_post( 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService', array(
            'timeout' => 12,
            'redirection' => 2,
            'headers' => array(
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '""',
                'User-Agent' => 'DendrilaPrivacy/' . self::VERSION . ' (+https://wordpress.org/plugins/dendrila-privacy/)',
            ),
            'body' => $soap,
        ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Le service VIES est momentanément inaccessible : ' . $response->get_error_message() ), 502 );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( 200 !== $code ) {
            $fault = $this->vies_xml_value( $body, 'faultstring' );
            $message = $fault ? 'VIES ne peut pas vérifier ce numéro pour le moment : ' . $fault : 'Réponse inattendue du service VIES.';
            wp_send_json_error( array( 'message' => $message ), 502 );
        }

        $valid = 'true' === strtolower( $this->vies_xml_value( $body, 'valid' ) );
        $source = 'https://ec.europa.eu/taxation_customs/vies/';
        if ( ! $valid ) {
            $payload = array(
                'results' => array(),
                'source' => $source,
                'provider' => 'eu-vies',
                'message' => 'VIES indique que ce numéro de TVA n’est pas valide pour les échanges intracommunautaires, ou qu’il n’est pas encore disponible dans la base nationale.',
            );
            set_transient( $cache_key, $payload, HOUR_IN_SECONDS );
            wp_send_json_success( $payload );
        }

        $name = $this->vies_xml_value( $body, 'name' );
        $address = $this->vies_xml_value( $body, 'address' );
        if ( '---' === $name ) { $name = ''; }
        if ( '---' === $address ) { $address = ''; }
        $vat_number = $country . $compact;
        $items = array(
            array(
                'country' => $country,
                'name' => sanitize_text_field( $name ),
                'address' => sanitize_text_field( $address ),
                'vat' => sanitize_text_field( $vat_number ),
                'registrationNumber' => sanitize_text_field( $vat_number ),
                'registrationLabel' => 'TVA',
                'legalForm' => '',
                'activity' => '',
                'entityType' => 'company',
                'status' => 'TVA valide (VIES)',
                'source' => $source,
            ),
        );
        $payload = array(
            'results' => $items,
            'source' => $source,
            'provider' => 'eu-vies',
            'message' => ( $name || $address )
                ? 'Numéro de TVA validé par VIES. Vérifiez les données avant de les enregistrer.'
                : 'Numéro de TVA validé par VIES. Le registre national ne fournit pas le nom ou l’adresse via VIES : complétez ces champs manuellement.',
        );
        set_transient( $cache_key, $payload, 6 * HOUR_IN_SECONDS );
        wp_send_json_success( $payload );
    }

    /**
     * Known hosting providers used only for local autocomplete/detection hints.
     * Patterns are deliberately conservative: PTM proposes a likely provider,
     * it never treats this heuristic as authoritative legal information.
     */
    private function known_hosting_providers() {
        /*
         * Contact details are intentionally limited to values verified from an
         * official provider page. A provider may still be detectable without a
         * complete legal-contact profile; in that case PTM proposes the name
         * but does not invent missing coordinates.
         */
        return array(
            'o2switch' => array(
                'name' => 'o2switch',
                'legal_name' => 'o2switch',
                'address' => 'Chemin des Pardiaux, 63000 Clermont-Ferrand, France',
                'phone' => '04 44 44 60 40',
                'source_url' => 'https://blog.o2switch.fr/que-renseigner-dans-la-page-mentions-legales/',
                'patterns' => array( 'o2switch', 'o2switch.net' ),
            ),
            'ovhcloud' => array(
                'name' => 'OVHcloud',
                'legal_name' => 'OVH SAS',
                'address' => '2 rue Kellermann, 59100 Roubaix, France',
                'phone' => '1007 (depuis la France) / +33 9 72 10 10 07',
                'source_url' => 'https://www.ovhcloud.com/fr/terms-and-conditions/',
                'patterns' => array( 'ovh.net', 'ovhcloud', 'dns.ovh.net', 'anycast.me' ),
            ),
            'infomaniak' => array(
                'name' => 'Infomaniak',
                'legal_name' => 'Infomaniak Network SA',
                'address' => 'Rue Eugène Marziano 25, 1227 Les Acacias (GE), Suisse',
                'phone' => '',
                'source_url' => 'https://www.infomaniak.com/fr/cgv/mentions-legales',
                'patterns' => array( 'infomaniak', 'infomaniak.ch', 'infomaniak.com' ),
            ),
            'ionos' => array(
                'name' => 'IONOS',
                'legal_name' => 'IONOS SARL',
                'address' => '7, place de la Gare, BP 70109, 57200 Sarreguemines Cedex, France',
                'phone' => '09 70 80 89 11',
                'source_url' => 'https://www.ionos.fr/terms-gtc/terms-imprint/',
                'patterns' => array( 'ionos', '1and1', 'ui-dns.com', 'ui-dns.de', 'ui-dns.org', 'ui-dns.biz' ),
            ),
            'gandi' => array(
                'name' => 'Gandi',
                'legal_name' => 'Gandi SAS',
                'address' => '63-65 boulevard Masséna, 75013 Paris, France',
                'phone' => '+33 1 70 37 76 61',
                'source_url' => 'https://docs.gandi.net/fr/hebergement_web/faq/obligations_legales.html',
                'patterns' => array( 'gandi', 'gandi.net', 'gandi-ns' ),
            ),
            'hostinger' => array(
                'name' => 'Hostinger',
                'legal_name' => 'Hostinger International Ltd.',
                'address' => '61 Lordou Vironos str., 6023 Larnaca, Chypre',
                'phone' => '',
                'source_url' => 'https://www.hostinger.com/fr/legal/dpa',
                'patterns' => array( 'hostinger', 'dns-parking.com' ),
            ),
            'planethoster' => array(
                'name' => 'PlanetHoster',
                'legal_name' => 'PlanetHoster',
                'address' => '4416 rue Louis-B.-Mayer, Laval, Québec H7P 0G1, Canada',
                'phone' => '',
                'source_url' => 'https://blog.planethoster.com/q3-2025-planethoster-offre-le-meilleur-rapport-qualite-prix-du-marche/',
                'patterns' => array( 'planethoster', 'n0c.com', 'hybridcloud' ),
            ),
            'lws' => array(
                'name' => 'LWS',
                'legal_name' => 'LWS (Ligne Web Services)',
                'address' => '10 rue Penthièvre, 75008 Paris, France',
                'phone' => '01 77 62 30 03',
                'source_url' => 'https://www.lws.fr/a_propos_infos.php',
                'patterns' => array( 'lws.fr', 'lws-hosting', 'lwsdns', 'lwshosting' ),
            ),
            'scaleway' => array(
                'name' => 'Scaleway',
                'legal_name' => 'Scaleway SAS',
                'address' => '8 rue de la Ville-l’Évêque, 75008 Paris, France',
                'phone' => '',
                'source_url' => 'https://www.scaleway.com/fr/mentions-legales/',
                'patterns' => array( 'scaleway', 'scw.cloud', 'online.net' ),
            ),
            'alwaysdata' => array(
                'name' => 'alwaysdata',
                'legal_name' => 'SARL alwaysdata',
                'address' => '91 rue du Faubourg Saint-Honoré, 75008 Paris, France',
                'phone' => '+33 1 84 16 23 49',
                'source_url' => 'https://help.alwaysdata.com/fr/hebergement-web/sites/obligations-legales-sur-internet/',
                'patterns' => array( 'alwaysdata', 'alwaysdata.net' ),
            ),
            'ikoula' => array( 'name' => 'Ikoula', 'patterns' => array( 'ikoula', 'ikoula.com' ) ),
            'amen' => array( 'name' => 'Amen', 'patterns' => array( 'amen.fr', 'amenworld', 'amenworld.com' ) ),
            'kinsta' => array( 'name' => 'Kinsta', 'patterns' => array( 'kinsta', 'kinsta.cloud', 'kinstacdn' ) ),
            'wpengine' => array(
                'name' => 'WP Engine',
                'legal_name' => 'WPEngine, Inc.',
                'address' => '504 Lavaca St., Ste. 1000, Austin, Texas 78701, États-Unis',
                'phone' => '',
                'source_url' => 'https://wpengine.com/fr/legal/enterprise-terms-of-service/',
                'patterns' => array( 'wpengine', 'wpenginepowered', 'wpeproxy' ),
            ),
            'siteground' => array(
                'name' => 'SiteGround',
                'legal_name' => 'SiteGround Spain S.L.',
                'address' => 'Calle Prim 19, 28004 Madrid, Espagne',
                'phone' => '',
                'source_url' => 'https://fr.siteground.com/entreprise',
                'patterns' => array( 'siteground', 'siteground.net', 'sgvps', 'sg-host' ),
            ),
            'cloudways' => array( 'name' => 'Cloudways', 'patterns' => array( 'cloudways', 'cloudwaysapps' ) ),
            'pressable' => array( 'name' => 'Pressable', 'patterns' => array( 'pressable', 'pressdns' ) ),
            'wordpresscom' => array( 'name' => 'WordPress.com', 'patterns' => array( 'wordpress.com', 'wpcomstaging', 'wp.com' ) ),
            'godaddy' => array( 'name' => 'GoDaddy', 'patterns' => array( 'godaddy', 'domaincontrol.com', 'secureserver.net' ) ),
            'bluehost' => array( 'name' => 'Bluehost', 'patterns' => array( 'bluehost', 'bluehost.com' ) ),
            'dreamhost' => array( 'name' => 'DreamHost', 'patterns' => array( 'dreamhost', 'dreamhost.com' ) ),
            'hostgator' => array( 'name' => 'HostGator', 'patterns' => array( 'hostgator', 'hostgator.com' ) ),
            'a2hosting' => array( 'name' => 'A2 Hosting', 'patterns' => array( 'a2hosting', 'a2hosting.com' ) ),
        );
    }

    private function hosting_profiles_for_js() {
        $profiles = array();
        foreach ( $this->known_hosting_providers() as $slug => $provider ) {
            $profiles[ $slug ] = array(
                'slug' => $slug,
                'name' => isset( $provider['name'] ) ? $provider['name'] : '',
                'legalName' => isset( $provider['legal_name'] ) ? $provider['legal_name'] : '',
                'address' => isset( $provider['address'] ) ? $provider['address'] : '',
                'phone' => isset( $provider['phone'] ) ? $provider['phone'] : '',
                'sourceUrl' => isset( $provider['source_url'] ) ? $provider['source_url'] : '',
            );
        }
        return $profiles;
    }

    private function hosting_detection_signals() {
        $signals = array();
        $add = static function ( &$target, $source, $value ) {
            if ( ! is_scalar( $value ) ) { return; }
            $value = strtolower( trim( (string) $value ) );
            if ( '' === $value ) { return; }
            $key = $source . '|' . $value;
            $target[ $key ] = array( 'source' => $source, 'value' => $value );
        };

        $domain = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $domain = preg_replace( '/^www\./', '', $domain );
        $add( $signals, 'site', $domain );
        foreach ( array( 'SERVER_NAME', 'HTTP_HOST', 'SERVER_ADDR', 'LOCAL_ADDR' ) as $server_key ) {
            if ( ! empty( $_SERVER[ $server_key ] ) ) {
                $add( $signals, 'server', sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) );
            }
        }
        if ( function_exists( 'gethostname' ) ) {
            $add( $signals, 'server-hostname', @gethostname() );
        }
        $add( $signals, 'php-uname', @php_uname( 'n' ) );

        // Environment hints exposed by some managed WordPress platforms.
        if ( defined( 'WPE_APIKEY' ) || class_exists( 'WpeCommon' ) ) { $add( $signals, 'environment', 'wpengine' ); }
        if ( defined( 'KINSTA_CACHE_ZONE' ) || defined( 'KINSTA_MU_VERSION' ) ) { $add( $signals, 'environment', 'kinsta' ); }
        if ( defined( 'SG_CACHEPRESS_VERSION' ) || defined( 'SITEGROUND_OPTIMIZER_VERSION' ) ) { $add( $signals, 'environment', 'siteground' ); }

        $ips = array();
        if ( $domain && function_exists( 'dns_get_record' ) ) {
            $dns_types = array();
            foreach ( array( 'DNS_NS', 'DNS_CNAME', 'DNS_A', 'DNS_AAAA' ) as $constant ) {
                if ( defined( $constant ) ) { $dns_types[] = constant( $constant ); }
            }
            foreach ( $dns_types as $dns_type ) {
                $records = @dns_get_record( $domain, $dns_type );
                if ( ! is_array( $records ) ) { continue; }
                foreach ( $records as $record ) {
                    if ( ! is_array( $record ) ) { continue; }
                    foreach ( array( 'target', 'host', 'mname' ) as $field ) {
                        if ( empty( $record[ $field ] ) ) { continue; }
                        $record_type = isset( $record['type'] ) ? strtolower( (string) $record['type'] ) : '';
                        $source = 'dns';
                        if ( 'ns' === $record_type ) { $source = 'dns-ns'; }
                        elseif ( 'cname' === $record_type ) { $source = 'dns-cname'; }
                        $add( $signals, $source, $record[ $field ] );
                    }
                    foreach ( array( 'ip', 'ipv6' ) as $field ) {
                        if ( ! empty( $record[ $field ] ) && filter_var( $record[ $field ], FILTER_VALIDATE_IP ) ) {
                            $ips[] = $record[ $field ];
                            $add( $signals, 'ip', $record[ $field ] );
                        }
                    }
                }
            }
        } elseif ( $domain && function_exists( 'gethostbyname' ) ) {
            $ip = @gethostbyname( $domain );
            if ( $ip && $ip !== $domain && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                $ips[] = $ip;
                $add( $signals, 'ip', $ip );
            }
        }

        if ( function_exists( 'gethostbyaddr' ) ) {
            foreach ( array_slice( array_values( array_unique( $ips ) ), 0, 4 ) as $ip ) {
                $reverse = @gethostbyaddr( $ip );
                if ( $reverse && $reverse !== $ip ) { $add( $signals, 'reverse-dns', $reverse ); }
            }
        }

        return array_values( $signals );
    }

    private function detect_hosting_provider() {
        $signals = $this->hosting_detection_signals();
        $providers = $this->known_hosting_providers();
        $matches = array();
        $proxy_detected = false;
        foreach ( $signals as $signal ) {
            if ( false !== strpos( $signal['value'], 'cloudflare' ) ) {
                $proxy_detected = true;
            }
        }

        $weights = array(
            'environment' => 70,
            'server-hostname' => 55,
            'php-uname' => 50,
            'reverse-dns' => 50,
            'dns-cname' => 35,
            'dns-ns' => 18,
            'dns' => 15,
            'server' => 12,
            'site' => 8,
            'ip' => 0,
        );
        foreach ( $providers as $slug => $provider ) {
            $evidence = array();
            $score = 0;
            foreach ( $signals as $signal ) {
                foreach ( $provider['patterns'] as $pattern ) {
                    if ( false !== strpos( $signal['value'], strtolower( $pattern ) ) ) {
                        $key = $signal['source'] . ':' . $signal['value'];
                        if ( ! isset( $evidence[ $key ] ) ) {
                            $evidence[ $key ] = $signal['source'] . ' : ' . $signal['value'];
                            $score += isset( $weights[ $signal['source'] ] ) ? (int) $weights[ $signal['source'] ] : 10;
                        }
                        break;
                    }
                }
            }
            if ( ! $evidence || $score < 15 ) { continue; }
            $confidence = min( 95, $score );
            $matches[] = array(
                'slug' => $slug,
                'name' => $provider['name'],
                'legalName' => isset( $provider['legal_name'] ) ? $provider['legal_name'] : '',
                'address' => isset( $provider['address'] ) ? $provider['address'] : '',
                'phone' => isset( $provider['phone'] ) ? $provider['phone'] : '',
                'sourceUrl' => isset( $provider['source_url'] ) ? $provider['source_url'] : '',
                'profileComplete' => ! empty( $provider['address'] ),
                'confidence' => $confidence,
                'evidence' => array_slice( array_values( $evidence ), 0, 5 ),
            );
        }
        usort( $matches, static function ( $a, $b ) { return (int) $b['confidence'] <=> (int) $a['confidence']; } );
        return array(
            'results' => array_slice( $matches, 0, 5 ),
            'proxyDetected' => $proxy_detected,
            'domain' => strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
        );
    }

    public function ajax_hosting_detect() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Droits insuffisants.' ), 403 );
        }
        check_ajax_referer( 'pixel_trackers_manager_hosting_detect', 'nonce' );
        $cache_key = 'pixel_trackers_manager_hosting_' . md5( home_url( '/' ) );
        $detected = get_transient( $cache_key );
        if ( ! is_array( $detected ) ) {
            $detected = $this->detect_hosting_provider();
            set_transient( $cache_key, $detected, 30 * MINUTE_IN_SECONDS );
        }
        if ( empty( $detected['results'] ) ) {
            $message = ! empty( $detected['proxyDetected'] )
                ? 'Cloudflare ou un autre proxy peut masquer l’hébergeur réel. Choisissez l’hébergeur dans la liste ou vérifiez les informations du compte d’hébergement.'
                : 'Aucun hébergeur connu n’a été identifié automatiquement. Choisissez un nom proposé dans le champ ou saisissez-le manuellement.';
            wp_send_json_success( array_merge( $detected, array( 'message' => $message ) ) );
        }
        wp_send_json_success( array_merge( $detected, array(
            'message' => 'Dendrila Privacy a trouvé un hébergeur probable à partir des informations techniques du site. Vérifiez ce résultat avant de publier vos mentions légales.',
        ) ) );
    }

    private function legal_profile() {
        return wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
    }

    private function legal_profile_defaults() {
        $is_fr = 0 === strpos( determine_locale(), 'fr_' );
        return array(
            'controller_name' => '', 'controller_address' => '', 'privacy_contact' => '', 'dpo_contact' => '', 'dpo_designated' => 'no',
            'site_editor_name' => '', 'site_editor_address' => '',
            'entity_type' => 'unknown', 'company_country' => $this->default_company_country(), 'company_registration_number' => '', 'company_registration_label' => '', 'company_siren' => '', 'company_siret' => '', 'company_vat' => '', 'company_legal_form' => '', 'company_capital' => '', 'company_registry' => '', 'company_activity' => '',
            'site_name' => get_bloginfo( 'name' ), 'site_url' => home_url( '/' ), 'public_contact_email' => '', 'public_contact_phone' => '', 'publication_director' => '',
            'host_name' => '', 'host_address' => '', 'host_phone' => '', 'host_source_url' => '', 'regulated_activity_details' => '', 'rep_idu' => '', 'company_lookup_source' => '', 'company_lookup_at' => '',
            'representative_applicable' => 'unknown', 'controller_representative' => '', 'controller_representative_contact' => '',
            'collection_mode' => 'unknown', 'indirect_services' => array(), 'indirect_categories' => '', 'indirect_sources' => '', 'mandatory_information' => '',
            'transfers_status' => 'unknown', 'transfer_destinations' => '', 'transfer_mechanism' => '', 'transfer_safeguards' => '',
            'automated_decision' => 'unknown', 'automated_details' => '', 'special_categories' => 'unknown', 'special_categories_basis' => '',
            'criminal_data' => 'unknown', 'criminal_data_basis' => '', 'minors_data' => 'unknown', 'minors_details' => '', 'high_risk_processing' => 'unknown', 'dpia_status' => 'unknown',
            'supervisory_authority' => $is_fr ? 'CNIL – Commission nationale de l’informatique et des libertés' : '',
            'supervisory_url' => $is_fr ? 'https://www.cnil.fr/fr/plaintes' : '',
            'cookie_nonessential' => 'unknown', 'cookie_consent_status' => 'unknown', 'cookie_preferences' => '', 'cookie_choice_retention' => '', 'cookie_cross_device' => 'unknown', 'cookie_cross_device_explanation' => '',
            'email_pixels' => 'unknown', 'email_pixel_purposes' => '', 'email_pixel_status' => 'unknown', 'email_pixel_preferences' => '', 'tracked_links' => 'unknown', 'tracked_links_details' => '',
            'external_email_use' => 'unknown', 'external_bulk_email' => 'unknown', 'external_bulk_unsubscribe' => 'unknown', 'external_bulk_bcc' => 'unknown', 'external_email_tools' => '',
            'external_messaging' => 'unknown', 'external_messaging_tools' => '', 'external_booking' => 'unknown', 'external_booking_tools' => '', 'external_forms' => 'unknown', 'external_form_tools' => '', 'external_payments' => 'unknown', 'external_payment_tools' => '', 'external_files' => 'unknown', 'external_file_tools' => '',
            'backup_reviewed' => 'unknown',
            'treatments' => array(), 'wizard_reviewed_once' => 0,
        );
    }

    private function sanitize_legal_profile( $raw ) {
        $defaults = $this->legal_profile_defaults();
        $profile = $defaults;
        $text_fields = array(
            'controller_name','controller_address','privacy_contact','dpo_contact','site_editor_name','site_editor_address','controller_representative','controller_representative_contact',
            'company_registration_number','company_registration_label','company_siren','company_siret','company_vat','company_legal_form','company_capital','company_registry','company_activity','site_name','public_contact_phone','publication_director','host_name','host_address','host_phone','host_source_url','regulated_activity_details','rep_idu','company_lookup_at',
            'indirect_categories','indirect_sources','mandatory_information','transfer_destinations','transfer_mechanism','transfer_safeguards','automated_details',
            'special_categories_basis','criminal_data_basis','minors_details','supervisory_authority','cookie_preferences','cookie_choice_retention','cookie_cross_device_explanation','email_pixel_purposes','email_pixel_preferences','tracked_links_details',
            'external_email_tools','external_messaging_tools','external_booking_tools','external_form_tools','external_payment_tools','external_file_tools'
        );
        foreach ( $text_fields as $field ) {
            if ( isset( $raw[ $field ] ) ) { $profile[ $field ] = sanitize_textarea_field( $raw[ $field ] ); }
        }
        foreach ( array( 'supervisory_url','site_url','company_lookup_source','host_source_url' ) as $field ) {
            if ( isset( $raw[ $field ] ) ) { $profile[ $field ] = esc_url_raw( $raw[ $field ] ); }
        }
        if ( isset( $raw['public_contact_email'] ) ) { $profile['public_contact_email'] = sanitize_email( $raw['public_contact_email'] ); }
        $selects = array(
            'company_country' => array_merge( array( '' ), array_keys( $this->company_lookup_countries() ) ),
            'entity_type' => array( 'unknown','company','individual','association','other' ),
            'representative_applicable' => array( 'no','yes','unknown' ), 'collection_mode' => array( 'direct','indirect','both','unknown' ),
            'transfers_status' => array( 'no','yes','unknown' ), 'automated_decision' => array( 'no','yes','unknown' ), 'special_categories' => array( 'no','yes','unknown' ),
            'criminal_data' => array( 'no','yes','unknown' ), 'minors_data' => array( 'no','yes','unknown' ), 'high_risk_processing' => array( 'no','yes','unknown' ),
            'dpia_status' => array( 'not_required','done','to_do','unknown' ), 'cookie_nonessential' => array( 'no','yes','unknown' ),
            'cookie_consent_status' => array( 'yes','no','unknown','exempt_only' ), 'cookie_cross_device' => array( 'no','yes','unknown' ),
            'email_pixels' => array( 'no','yes','unknown' ), 'email_pixel_status' => array( 'consent','exempt_delivery','unknown' ), 'tracked_links' => array( 'no','yes','unknown' ),
            'dpo_designated' => array( 'no','yes','unknown' ),
            'external_email_use' => array( 'no','yes','unknown' ), 'external_bulk_email' => array( 'no','yes','unknown' ), 'external_bulk_unsubscribe' => array( 'no','yes','unknown' ), 'external_bulk_bcc' => array( 'no','yes','unknown' ),
            'external_messaging' => array( 'no','yes','unknown' ), 'external_booking' => array( 'no','yes','unknown' ), 'external_forms' => array( 'no','yes','unknown' ), 'external_payments' => array( 'no','yes','unknown' ), 'external_files' => array( 'no','yes','unknown' ), 'backup_reviewed' => array( 'no','yes','unknown' ),
        );
        foreach ( $selects as $field => $allowed ) {
            if ( isset( $raw[ $field ] ) ) { $value = sanitize_key( $raw[ $field ] ); $profile[ $field ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $field ]; }
        }
        $profile['treatments'] = array();
        if ( ! empty( $raw['treatments'] ) && is_array( $raw['treatments'] ) ) {
            foreach ( array_slice( $raw['treatments'], 0, 8 ) as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                $basis = isset( $row['legal_basis'] ) ? sanitize_key( $row['legal_basis'] ) : 'unknown';
                if ( ! in_array( $basis, array( 'consent','contract','legal_obligation','vital_interests','public_task','legitimate_interest','unknown' ), true ) ) { $basis = 'unknown'; }
                $mandatory = isset( $row['mandatory'] ) ? sanitize_key( $row['mandatory'] ) : 'unknown';
                if ( ! in_array( $mandatory, array( 'optional','contract','law','unknown' ), true ) ) { $mandatory = 'unknown'; }
                $entry = array(
                    'purpose' => isset( $row['purpose'] ) ? sanitize_text_field( $row['purpose'] ) : '',
                    'data_categories' => isset( $row['data_categories'] ) ? sanitize_textarea_field( $row['data_categories'] ) : '',
                    'legal_basis' => $basis, 'basis_detail' => isset( $row['basis_detail'] ) ? sanitize_textarea_field( $row['basis_detail'] ) : '',
                    'recipients' => isset( $row['recipients'] ) ? sanitize_textarea_field( $row['recipients'] ) : '', 'retention' => isset( $row['retention'] ) ? sanitize_textarea_field( $row['retention'] ) : '',
                    'mandatory' => $mandatory, 'consequences' => isset( $row['consequences'] ) ? sanitize_textarea_field( $row['consequences'] ) : '',
                );
                if ( $entry['purpose'] || $entry['data_categories'] || $entry['recipients'] || $entry['retention'] ) { $profile['treatments'][] = $entry; }
            }
        }
        $profile['indirect_services'] = array();
        if ( ! empty( $raw['indirect_services'] ) && is_array( $raw['indirect_services'] ) ) {
            foreach ( array_slice( $raw['indirect_services'], 0, 20 ) as $service ) {
                if ( ! is_array( $service ) ) { continue; }
                $transfer_status = isset( $service['transfer_status'] ) ? sanitize_key( $service['transfer_status'] ) : 'unknown';
                if ( ! in_array( $transfer_status, array( 'no','yes','unknown' ), true ) ) { $transfer_status = 'unknown'; }
                $automated = isset( $service['automated_decision'] ) ? sanitize_key( $service['automated_decision'] ) : 'unknown';
                if ( ! in_array( $automated, array( 'no','yes','unknown' ), true ) ) { $automated = 'unknown'; }
                $state = isset( $service['state'] ) ? sanitize_key( $service['state'] ) : 'manual';
                if ( ! in_array( $state, array( 'active','potential','manual' ), true ) ) { $state = 'manual'; }
                $entry = array(
                    'service_id' => isset( $service['service_id'] ) ? sanitize_key( $service['service_id'] ) : '',
                    'label' => isset( $service['label'] ) ? sanitize_text_field( $service['label'] ) : '',
                    'state' => $state,
                    'categories' => isset( $service['categories'] ) ? sanitize_textarea_field( $service['categories'] ) : '',
                    'source' => isset( $service['source'] ) ? sanitize_textarea_field( $service['source'] ) : '',
                    'recipients' => isset( $service['recipients'] ) ? sanitize_textarea_field( $service['recipients'] ) : '',
                    'transfer_status' => $transfer_status,
                    'transfer_destinations' => isset( $service['transfer_destinations'] ) ? sanitize_textarea_field( $service['transfer_destinations'] ) : '',
                    'transfer_mechanism' => isset( $service['transfer_mechanism'] ) ? sanitize_textarea_field( $service['transfer_mechanism'] ) : '',
                    'transfer_safeguards' => isset( $service['transfer_safeguards'] ) ? sanitize_textarea_field( $service['transfer_safeguards'] ) : '',
                    'automated_decision' => $automated,
                    'automated_details' => isset( $service['automated_details'] ) ? sanitize_textarea_field( $service['automated_details'] ) : '',
                );
                if ( $entry['label'] || $entry['source'] || $entry['categories'] ) { $profile['indirect_services'][] = $entry; }
            }
        }
        if ( isset( $raw['wizard_reviewed_once'] ) ) { $profile['wizard_reviewed_once'] = empty( $raw['wizard_reviewed_once'] ) ? 0 : 1; }
        return $profile;
    }

    private function legal_basis_label( $basis ) {
        $labels = array(
            'consent' => 'consentement',
            'contract' => 'exécution d’un contrat ou mesures précontractuelles',
            'legal_obligation' => 'obligation légale',
            'vital_interests' => 'sauvegarde des intérêts vitaux',
            'public_task' => 'mission d’intérêt public / exercice de l’autorité publique',
            'legitimate_interest' => 'intérêt légitime',
            'unknown' => 'à déterminer',
        );
        return isset( $labels[ $basis ] ) ? $labels[ $basis ] : $labels['unknown'];
    }

    private function legal_profile_readiness( $profile ) {
        $profile = wp_parse_args( is_array( $profile ) ? $profile : array(), $this->legal_profile_defaults() );
        $errors = array();
        $warnings = array();
        if ( empty( $profile['controller_name'] ) ) { $errors[] = 'Nom ou raison sociale du responsable du traitement'; }
        if ( empty( $profile['privacy_contact'] ) ) { $errors[] = 'Contact permettant d’exercer les droits'; }
        if ( 'yes' === $profile['dpo_designated'] && empty( $profile['dpo_contact'] ) ) { $errors[] = 'Coordonnées du DPO/DPD désigné'; }
        if ( empty( $profile['treatments'] ) ) { $errors[] = 'Au moins une utilisation de données personnelles'; }

        foreach ( (array) $profile['treatments'] as $i => $row ) {
            $n = $i + 1;
            if ( empty( $row['purpose'] ) ) { $errors[] = 'Utilisation ' . $n . ' : à quoi servent les données'; }
            if ( empty( $row['data_categories'] ) ) { $errors[] = 'Utilisation ' . $n . ' : quelles données sont concernées'; }
            if ( empty( $row['legal_basis'] ) || 'unknown' === $row['legal_basis'] ) { $errors[] = 'Utilisation ' . $n . ' : raison juridique'; }
            if ( 'legitimate_interest' === $row['legal_basis'] && empty( $row['basis_detail'] ) ) { $errors[] = 'Utilisation ' . $n . ' : intérêt légitime poursuivi'; }
            if ( empty( $row['recipients'] ) ) { $errors[] = 'Utilisation ' . $n . ' : qui reçoit ou traite les données'; }
            if ( empty( $row['retention'] ) ) { $errors[] = 'Utilisation ' . $n . ' : combien de temps les données sont gardées'; }
            if ( in_array( $row['mandatory'], array( 'contract','law' ), true ) && empty( $row['consequences'] ) ) { $errors[] = 'Utilisation ' . $n . ' : conséquence si la donnée obligatoire n’est pas fournie'; }
        }

        if ( 'yes' === $profile['representative_applicable'] && ( empty( $profile['controller_representative'] ) || empty( $profile['controller_representative_contact'] ) ) ) { $errors[] = 'Représentant UE/EEE déclaré : identité et coordonnées'; }
        if ( in_array( $profile['collection_mode'], array( 'indirect','both' ), true ) && empty( $profile['indirect_services'] ) && ( empty( $profile['indirect_categories'] ) || empty( $profile['indirect_sources'] ) ) ) { $errors[] = 'Données reçues indirectement : provenance'; }
        if ( 'yes' === $profile['transfers_status'] && empty( $profile['transfer_destinations'] ) ) { $errors[] = 'Transfert hors UE/EEE déclaré : destination'; }
        if ( 'yes' === $profile['automated_decision'] && empty( $profile['automated_details'] ) ) { $errors[] = 'Décision automatisée déclarée : explication'; }
        if ( 'yes' === $profile['special_categories'] && empty( $profile['special_categories_basis'] ) ) { $errors[] = 'Données sensibles déclarées : cadre prévu'; }
        if ( 'yes' === $profile['criminal_data'] && empty( $profile['criminal_data_basis'] ) ) { $errors[] = 'Données pénales déclarées : cadre prévu'; }
        if ( 'yes' === $profile['minors_data'] && empty( $profile['minors_details'] ) ) { $errors[] = 'Données de mineurs déclarées : modalités prévues'; }

        $scan = get_option( self::OPTION_SCAN, array() );
        if ( 'yes' === $profile['cookie_nonessential'] || $this->scan_has_optional_tracking( $scan ) ) {
            if ( 'yes' !== $profile['cookie_consent_status'] && empty( $this->settings()['consent_enabled'] ) ) { $errors[] = 'Traceurs facultatifs : choix avant activation'; }
        }
        if ( 'yes' === $profile['email_pixels'] && empty( $profile['email_pixel_purposes'] ) ) { $errors[] = 'Suivi d’ouverture des e-mails déclaré : raison'; }
        if ( 'yes' === $profile['tracked_links'] && empty( $profile['tracked_links_details'] ) ) { $errors[] = 'Suivi individualisé des clics déclaré : explication'; }
        if ( 'yes' === $profile['high_risk_processing'] && in_array( $profile['dpia_status'], array( 'unknown','to_do' ), true ) ) { $warnings[] = 'Vous avez indiqué un traitement à risque élevé : vérifiez si une analyse d’impact sur la protection des données (analyse d’impact (AIPD/DPIA)) est nécessaire.'; }

        return array( 'errors' => array_values( array_unique( $errors ) ), 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    private function legal_notice_readiness( $profile ) {
        $p = wp_parse_args( is_array( $profile ) ? $profile : array(), $this->legal_profile_defaults() );
        $missing = array();
        $editor_name = $p['site_editor_name'] ? $p['site_editor_name'] : $p['controller_name'];
        $editor_address = $p['site_editor_address'] ? $p['site_editor_address'] : $p['controller_address'];
        if ( ! $editor_name ) { $missing[] = 'éditeur du site'; }
        if ( ! $editor_address ) { $missing[] = 'adresse de l’éditeur'; }
        if ( empty( $p['public_contact_email'] ) ) { $missing[] = 'e-mail public de l’éditeur'; }
        if ( in_array( $p['entity_type'], array( 'company','individual' ), true ) && empty( $p['public_contact_phone'] ) ) { $missing[] = 'téléphone public'; }
        if ( in_array( $p['entity_type'], array( 'company','individual' ), true ) && empty( $p['company_siren'] ) && empty( $p['company_siret'] ) ) { $missing[] = 'SIREN ou SIRET'; }
        if ( empty( $p['publication_director'] ) ) { $missing[] = 'responsable / directeur de la publication'; }
        if ( empty( $p['host_name'] ) ) { $missing[] = 'nom de l’hébergeur'; }
        if ( empty( $p['host_address'] ) ) { $missing[] = 'adresse de l’hébergeur'; }
        if ( empty( $p['host_phone'] ) ) { $missing[] = 'téléphone de l’hébergeur'; }
        return array_values( array_unique( $missing ) );
    }

    private function generated_legal_notice( $profile ) {
        $p = wp_parse_args( is_array( $profile ) ? $profile : array(), $this->legal_profile_defaults() );
        if ( empty( $p['controller_name'] ) && empty( $p['site_name'] ) ) { return ''; }
        $html = '<section class="ptm-legal-notice"><h2>Mentions légales</h2>';
        if ( $p['site_name'] ) { $html .= '<p><strong>Site :</strong> ' . esc_html( $p['site_name'] ); if ( $p['site_url'] ) { $html .= ' — <a href="' . esc_url( $p['site_url'] ) . '">' . esc_html( $p['site_url'] ) . '</a>'; } $html .= '</p>'; }
        $html .= '<h3>Éditeur du site</h3>';
        $editor_name = $p['site_editor_name'] ? $p['site_editor_name'] : $p['controller_name'];
        $editor_address = $p['site_editor_address'] ? $p['site_editor_address'] : $p['controller_address'];
        if ( $editor_name ) { $html .= '<p><strong>Nom / raison sociale :</strong> ' . esc_html( $editor_name ) . '</p>'; }
        if ( $p['company_legal_form'] ) { $html .= '<p><strong>Forme juridique :</strong> ' . esc_html( $p['company_legal_form'] ) . '</p>'; }
        if ( $p['company_capital'] ) { $html .= '<p><strong>Capital social :</strong> ' . esc_html( $p['company_capital'] ) . '</p>'; }
        if ( $editor_address ) { $html .= '<p><strong>Adresse :</strong> ' . nl2br( esc_html( $editor_address ) ) . '</p>'; }
        if ( 'FR' === $p['company_country'] || ( ! $p['company_country'] && ( $p['company_siren'] || $p['company_siret'] ) ) ) {
            if ( $p['company_siren'] ) { $html .= '<p><strong>SIREN :</strong> ' . esc_html( $p['company_siren'] ) . '</p>'; }
            if ( $p['company_siret'] ) { $html .= '<p><strong>SIRET du siège :</strong> ' . esc_html( $p['company_siret'] ) . '</p>'; }
        } elseif ( $p['company_registration_number'] ) {
            $registration_label = $p['company_registration_label'] ? $p['company_registration_label'] : 'Identifiant d’immatriculation';
            $html .= '<p><strong>' . esc_html( $registration_label ) . ' :</strong> ' . esc_html( $p['company_registration_number'] ) . '</p>';
        }
        if ( $p['company_registry'] ) { $html .= '<p><strong>Immatriculation :</strong> ' . esc_html( $p['company_registry'] ) . '</p>'; }
        if ( $p['company_vat'] ) { $html .= '<p><strong>N° TVA :</strong> ' . esc_html( $p['company_vat'] ) . '</p>'; }
        if ( $p['public_contact_email'] || $p['public_contact_phone'] ) { $html .= '<p><strong>Contact :</strong> ' . esc_html( trim( $p['public_contact_email'] . ( $p['public_contact_email'] && $p['public_contact_phone'] ? ' — ' : '' ) . $p['public_contact_phone'] ) ) . '</p>'; }
        if ( $p['publication_director'] ) { $html .= '<p><strong>Directeur de la publication :</strong> ' . esc_html( $p['publication_director'] ) . '</p>'; }
        if ( $p['regulated_activity_details'] ) { $html .= '<p><strong>Activité réglementée / autorité compétente :</strong> ' . nl2br( esc_html( $p['regulated_activity_details'] ) ) . '</p>'; }
        if ( $p['rep_idu'] ) { $html .= '<p><strong>Identifiant(s) REP / IDU :</strong> ' . esc_html( $p['rep_idu'] ) . '</p>'; }
        if ( $p['host_name'] || $p['host_address'] || $p['host_phone'] ) {
            $html .= '<h3>Hébergement</h3>';
            if ( $p['host_name'] ) { $html .= '<p><strong>Hébergeur :</strong> ' . esc_html( $p['host_name'] ) . '</p>'; }
            if ( $p['host_address'] ) { $html .= '<p><strong>Adresse :</strong> ' . nl2br( esc_html( $p['host_address'] ) ) . '</p>'; }
            if ( $p['host_phone'] ) { $html .= '<p><strong>Téléphone :</strong> ' . esc_html( $p['host_phone'] ) . '</p>'; }
        }
        $html .= '</section>';
        return $html;
    }

    private function generated_cookie_policy( $profile ) {
        $p = wp_parse_args( is_array( $profile ) ? $profile : array(), $this->legal_profile_defaults() );
        $settings = $this->settings();
        $native_consent = ! empty( $settings['consent_enabled'] );
        $optional_detected = $this->scan_has_optional_tracking();
        $effective_nonessential = 'unknown' === $p['cookie_nonessential'] && $optional_detected ? 'yes' : $p['cookie_nonessential'];
        $effective_consent = $native_consent ? 'yes' : $p['cookie_consent_status'];
        $html = '<section class="ptm-cookie-policy"><h2>Politique de cookies et traceurs</h2>';
        if ( 'yes' === $effective_nonessential ) {
            $html .= '<p>Le site utilise des traceurs qui ne sont pas strictement nécessaires à son fonctionnement.</p>';
            if ( 'yes' === $effective_consent ) { $html .= '<p>Les services facultatifs concernés restent bloqués jusqu’à votre choix.</p>'; }
            elseif ( 'exempt_only' === $effective_consent ) { $html .= '<p>Certains traceurs sont utilisés uniquement dans les conditions présentées comme exemptées de consentement.</p>'; }
        } elseif ( 'no' === $effective_nonessential ) {
            $html .= '<p>Le site n’utilise pas actuellement de traceur facultatif déclaré.</p>';
        }
        if ( $p['cookie_preferences'] ) { $html .= '<p><strong>Modifier ou retirer vos choix :</strong> ' . esc_html( $p['cookie_preferences'] ) . '</p>'; }
        elseif ( $native_consent ) { $html .= '<p><strong>Modifier ou retirer vos choix :</strong> ' . $this->shortcode_consent_settings() . '</p>'; }
        if ( $p['cookie_choice_retention'] ) { $html .= '<p><strong>Durée de mémorisation de vos choix :</strong> ' . esc_html( $p['cookie_choice_retention'] ) . '</p>'; }
        if ( 'yes' === $p['cookie_cross_device'] && $p['cookie_cross_device_explanation'] ) { $html .= '<p><strong>Plusieurs appareils :</strong> ' . nl2br( esc_html( $p['cookie_cross_device_explanation'] ) ) . '</p>'; }
        $html .= $this->generated_services_block();
        $html .= '</section>';
        return $html;
    }

    private function generated_legal_block( $profile ) {
        $basis_uses_consent = false;
        $basis_uses_legitimate = false;
        foreach ( (array) $profile['treatments'] as $row ) {
            $basis_uses_consent = $basis_uses_consent || 'consent' === $row['legal_basis'];
            $basis_uses_legitimate = $basis_uses_legitimate || 'legitimate_interest' === $row['legal_basis'];
        }
        $html = self::LEGAL_BLOCK_START . "\n";
        $html .= '<section class="ptm-privacy-information"><h2>Protection des données personnelles</h2>';
        $html .= '<h3>Responsable du traitement</h3><p><strong>' . esc_html( $profile['controller_name'] ) . '</strong>';
        if ( $profile['controller_address'] ) { $html .= '<br>' . nl2br( esc_html( $profile['controller_address'] ) ); }
        $html .= '</p>';
        if ( $profile['privacy_contact'] ) { $html .= '<p><strong>Contact pour exercer vos droits :</strong> ' . esc_html( $profile['privacy_contact'] ) . '</p>'; }
        if ( 'yes' === $profile['dpo_designated'] && $profile['dpo_contact'] ) { $html .= '<p><strong>Délégué à la protection des données (DPO/DPD) :</strong> ' . esc_html( $profile['dpo_contact'] ) . '</p>'; }
        if ( 'yes' === $profile['representative_applicable'] ) {
            $html .= '<p><strong>Représentant dans l’Union européenne / EEE :</strong> ' . esc_html( $profile['controller_representative'] ) . '<br>' . nl2br( esc_html( $profile['controller_representative_contact'] ) ) . '</p>';
        }
        $html .= '<h3>Traitements de données</h3>';
        foreach ( (array) $profile['treatments'] as $row ) {
            $html .= '<div class="ptm-treatment"><h4>' . esc_html( $row['purpose'] ) . '</h4><ul>';
            $html .= '<li><strong>Catégories de données :</strong> ' . esc_html( $row['data_categories'] ) . '</li>';
            $html .= '<li><strong>Base juridique :</strong> ' . esc_html( $this->legal_basis_label( $row['legal_basis'] ) );
            if ( $row['basis_detail'] ) { $html .= ' — ' . esc_html( $row['basis_detail'] ); }
            $html .= '</li>';
            $html .= '<li><strong>Destinataires ou catégories de destinataires :</strong> ' . esc_html( $row['recipients'] ) . '</li>';
            $html .= '<li><strong>Durée de conservation :</strong> ' . esc_html( $row['retention'] ) . '</li>';
            if ( 'unknown' !== $row['mandatory'] ) {
                $mandatory_labels = array( 'optional'=>'facultative', 'contract'=>'nécessaire à l’exécution ou à la conclusion d’un contrat', 'law'=>'requise par une obligation légale' );
                $html .= '<li><strong>Fourniture des données :</strong> ' . esc_html( isset( $mandatory_labels[ $row['mandatory'] ] ) ? $mandatory_labels[ $row['mandatory'] ] : 'facultative' );
                if ( $row['consequences'] ) { $html .= ' — ' . esc_html( $row['consequences'] ); }
                $html .= '</li>';
            }
            $html .= '</ul></div>';
        }
        if ( in_array( $profile['collection_mode'], array( 'indirect','both' ), true ) ) {
            $html .= '<h3>Données obtenues indirectement</h3>';
            if ( ! empty( $profile['indirect_services'] ) ) {
                foreach ( (array) $profile['indirect_services'] as $service ) {
                    $html .= '<div class="ptm-indirect-service"><h4>' . esc_html( ! empty( $service['label'] ) ? $service['label'] : 'Service tiers' ) . '</h4><p><strong>Données concernées :</strong> ' . esc_html( $service['categories'] ) . '<br><strong>Provenance :</strong> ' . esc_html( $service['source'] );
                    if ( ! empty( $service['recipients'] ) ) { $html .= '<br><strong>Destinataires :</strong> ' . esc_html( $service['recipients'] ); }
                    $html .= '</p>';
                    if ( 'yes' === ( isset( $service['transfer_status'] ) ? $service['transfer_status'] : 'unknown' ) ) { $html .= '<p><strong>Transfert hors UE/EEE :</strong> ' . esc_html( $service['transfer_destinations'] ) . ( ! empty( $service['transfer_mechanism'] ) ? '<br><strong>Encadrement :</strong> ' . esc_html( $service['transfer_mechanism'] ) : '' ) . '</p>'; }
                    $html .= '</div>';
                }
            } else { $html .= '<p><strong>Catégories concernées :</strong> ' . esc_html( $profile['indirect_categories'] ) . '<br><strong>Source ou provenance :</strong> ' . esc_html( $profile['indirect_sources'] ) . '</p>'; }
        }
        if ( $profile['mandatory_information'] ) {
            $html .= '<h3>Données obligatoires ou facultatives</h3><p>' . nl2br( esc_html( $profile['mandatory_information'] ) ) . '</p>';
        }
        if ( 'yes' === $profile['special_categories'] ) {
            $html .= '<h3>Catégories particulières de données</h3><p>Le traitement comprend des catégories particulières de données au sens de l’article 9 du RGPD. <strong>Condition supplémentaire renseignée :</strong> ' . esc_html( $profile['special_categories_basis'] ) . '.</p>';
        }
        if ( 'yes' === $profile['criminal_data'] ) {
            $html .= '<h3>Données relatives aux condamnations pénales et infractions</h3><p><strong>Encadrement renseigné :</strong> ' . esc_html( $profile['criminal_data_basis'] ) . '.</p>';
        }
        if ( 'yes' === $profile['minors_data'] ) {
            $html .= '<h3>Données concernant des mineurs</h3><p>' . nl2br( esc_html( $profile['minors_details'] ) ) . '</p>';
        }
        if ( 'yes' === $profile['transfers_status'] ) {
            $html .= '<h3>Transferts de données hors Espace économique européen</h3><p><strong>Destination(s) / destinataire(s) :</strong> ' . esc_html( $profile['transfer_destinations'] ) . '<br><strong>Encadrement du transfert :</strong> ' . esc_html( $profile['transfer_mechanism'] );
            if ( $profile['transfer_safeguards'] ) { $html .= '<br><strong>Accès aux garanties ou informations complémentaires :</strong> ' . esc_html( $profile['transfer_safeguards'] ); }
            $html .= '</p>';
        } elseif ( 'no' === $profile['transfers_status'] ) {
            $html .= '<h3>Transferts hors Espace économique européen</h3><p>Les traitements décrits ci-dessus n’impliquent pas, selon les informations renseignées pour cette politique, de transfert de données hors de l’Espace économique européen.</p>';
        }
        if ( 'yes' === $profile['automated_decision'] ) {
            $html .= '<h3>Décision automatisée et profilage</h3><p>' . nl2br( esc_html( $profile['automated_details'] ) ) . '</p>';
            $html .= '<p>Lorsque l’article 22 du RGPD s’applique, les garanties prévues peuvent notamment inclure le droit d’obtenir une intervention humaine, d’exprimer son point de vue et de contester la décision.</p>';
        }
        $html .= '<h3>Vos droits</h3><p>Dans les conditions prévues par le RGPD, vous pouvez demander l’accès à vos données, leur rectification ou leur effacement, la limitation de leur traitement et, lorsque les conditions sont réunies, exercer votre droit à la portabilité et votre droit d’opposition lorsque leurs conditions sont réunies.</p>';
        if ( $basis_uses_legitimate ) { $html .= '<p>Lorsque le traitement repose sur un intérêt légitime, vous pouvez vous y opposer pour des raisons tenant à votre situation particulière, sous réserve des exceptions prévues par le RGPD.</p>'; }
        if ( $basis_uses_consent ) { $html .= '<p>Lorsque le traitement repose sur votre consentement, vous pouvez retirer celui-ci à tout moment, sans remettre en cause la licéité du traitement effectué avant ce retrait.</p>'; }
        if ( $profile['privacy_contact'] ) { $html .= '<p>Pour exercer vos droits : <strong>' . esc_html( $profile['privacy_contact'] ) . '</strong>.</p>'; }
        elseif ( 'yes' === $profile['dpo_designated'] && $profile['dpo_contact'] ) { $html .= '<p>Pour exercer vos droits, vous pouvez contacter le DPO/DPD : <strong>' . esc_html( $profile['dpo_contact'] ) . '</strong>.</p>'; }
        if ( $profile['supervisory_authority'] ) {
            $authority = esc_html( $profile['supervisory_authority'] );
            if ( $profile['supervisory_url'] ) { $authority = '<a href="' . esc_url( $profile['supervisory_url'] ) . '">' . $authority . '</a>'; }
            $html .= '<p>Vous disposez également du droit d’introduire une réclamation auprès de ' . $authority . '.</p>';
        }
        if ( 'yes' === $profile['cookie_nonessential'] ) {
            $html .= '<h3>Cookies et autres traceurs</h3>';
            if ( 'yes' === $profile['cookie_consent_status'] ) { $html .= '<p>Les traceurs qui nécessitent un consentement sont soumis au choix de l’utilisateur avant leur lecture ou leur dépôt. Le refus est proposé avec une facilité équivalente à l’acceptation.</p>'; }
            if ( 'exempt_only' === $profile['cookie_consent_status'] ) { $html .= '<p>Les traceurs actuellement déclarés sont présentés comme relevant uniquement d’usages exemptés de consentement ; cette qualification doit rester vérifiée au regard de leur finalité et de leur configuration réelle.</p>'; }
            if ( $profile['cookie_preferences'] ) { $pref = wp_http_validate_url( $profile['cookie_preferences'] ) ? '<a href="' . esc_url( $profile['cookie_preferences'] ) . '">ouvrir la page de gestion des cookies</a>' : esc_html( $profile['cookie_preferences'] ); $html .= '<p><strong>Modifier ou retirer vos choix :</strong> ' . $pref . '</p>'; }
            if ( $profile['cookie_choice_retention'] ) { $html .= '<p><strong>Durée de conservation du choix :</strong> ' . esc_html( $profile['cookie_choice_retention'] ) . '</p>'; }
            if ( 'yes' === $profile['cookie_cross_device'] && $profile['cookie_cross_device_explanation'] ) { $html .= '<p><strong>Portée des choix sur plusieurs appareils :</strong> ' . esc_html( $profile['cookie_cross_device_explanation'] ) . '</p>'; }
        }
        if ( 'yes' === $profile['email_pixels'] || 'yes' === $profile['tracked_links'] ) {
            $html .= '<h3>Suivi dans les courriels</h3>';
            if ( 'yes' === $profile['email_pixels'] ) {
                $html .= '<p>Certains courriels peuvent contenir des pixels de suivi. <strong>Finalité(s) :</strong> ' . esc_html( $profile['email_pixel_purposes'] ) . '.</p>';
                if ( 'consent' === $profile['email_pixel_status'] ) { $html .= '<p>L’utilisation de ces pixels est déclarée comme soumise au consentement lorsque celui-ci est requis.</p>'; }
                if ( 'exempt_delivery' === $profile['email_pixel_status'] ) { $html .= '<p>L’éditeur indique utiliser uniquement des pixels dont l’exemption a été évaluée pour une finalité strictement liée à la délivrabilité. Toute utilisation au-delà de cette finalité doit faire l’objet d’une nouvelle analyse.</p>'; }
                if ( $profile['email_pixel_preferences'] ) { $html .= '<p><strong>Gérer ce choix :</strong> ' . esc_html( $profile['email_pixel_preferences'] ) . '</p>'; }
            }
            if ( 'yes' === $profile['tracked_links'] ) {
                $html .= '<p><strong>Suivi individualisé des clics :</strong> ' . nl2br( esc_html( $profile['tracked_links_details'] ) ) . '</p>';
                $html .= '<p>Le cadre applicable au suivi des clics dépend du mécanisme technique et de la finalité réellement utilisés ; cette qualification doit rester vérifiée séparément des règles propres aux pixels invisibles.</p>';
            }
        }
        $html .= '</section>' . "\n" . self::LEGAL_BLOCK_END;
        return $html;
    }

    public function sync_legal_block( $page_id ) {
        $page_id = absint( $page_id );
        $post = get_post( $page_id );
        if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
            return new WP_Error( 'pixel_trackers_manager_bad_page', 'Page de confidentialité invalide ou non publiée.' );
        }
        if ( ! current_user_can( 'edit_post', $page_id ) ) {
            return new WP_Error( 'pixel_trackers_manager_forbidden', 'Vous n’avez pas le droit de modifier cette page.' );
        }

        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        $readiness = $this->legal_profile_readiness( $profile );
        if ( ! empty( $readiness['errors'] ) ) {
            return new WP_Error( 'pixel_trackers_manager_legal_incomplete', 'Questionnaire incomplet : ' . implode( ' ; ', array_slice( $readiness['errors'], 0, 5 ) ) . ( count( $readiness['errors'] ) > 5 ? '…' : '' ) );
        }

        $block = $this->generated_legal_block( $profile );
        $builder = $this->page_builder_info( $page_id );
        if ( ! empty( $builder['safe_mode'] ) ) {
            $this->set_page_overlay( $page_id, 'legal', $block );
            $this->log_action( 'sync_legal_block', 'manual-builder-safe', array( 'page_id' => $page_id, 'builder' => $builder['id'] ) );
            $this->audit_privacy_page( $page_id );
            return true;
        }

        $content = (string) $post->post_content;
        $pattern = '/' . preg_quote( self::LEGAL_BLOCK_START, '/' ) . '.*?' . preg_quote( self::LEGAL_BLOCK_END, '/' ) . '/is';
        if ( preg_match( $pattern, $content ) ) {
            $new_content = preg_replace( $pattern, $block, $content, 1 );
        } elseif ( false !== strpos( $content, self::BLOCK_START ) ) {
            $new_content = str_replace( self::BLOCK_START, $block . "\n\n" . self::BLOCK_START, $content );
        } else {
            $new_content = rtrim( $content ) . "\n\n" . $block;
        }
        if ( $new_content === $content ) {
            return true;
        }
        $updated = wp_update_post( array( 'ID' => $page_id, 'post_content' => $new_content ), true );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }
        $this->log_action( 'sync_legal_block', 'manual', array( 'page_id' => $page_id, 'builder' => 'wordpress' ) );
        $this->audit_privacy_page( $page_id );
        return true;
    }

    private function assistant_step_for_topic( $topic_id ) {
        $map = array(
            'identity'=>'identity','contact'=>'identity','representative'=>'identity',
            'purposes'=>'treatments','legal_basis'=>'treatments','data_categories'=>'treatments','retention'=>'treatments','recipients'=>'treatments','mandatory'=>'treatments',
            'source'=>'flows','transfers'=>'flows','automated'=>'flows',
            'special_categories'=>'risk','criminal_data'=>'risk','minors'=>'risk',
            'cookies'=>'cookies','withdrawal'=>'cookies',
            'email_pixels'=>'email','rights'=>'authority','complaint'=>'authority',
            'external_tools'=>'external','backups'=>'external',
        );
        return isset( $map[ $topic_id ] ) ? $map[ $topic_id ] : 'identity';
    }

    private function assistant_url( $step = 'identity', $focus = '' ) {
        $url = add_query_arg( 'ptm_step', sanitize_key( $step ), admin_url( 'admin.php?page=pixel-trackers-manager-assistant' ) );
        if ( $focus ) { $url .= '#ptm-field-' . rawurlencode( sanitize_key( $focus ) ); }
        return $url;
    }

    private function legal_wizard_steps() {
        return array(
            'identity' => array( 'label' => 'Vous et le site', 'short' => 'Identité' ),
            'treatments' => array( 'label' => 'Pourquoi utilisez-vous des données ?', 'short' => 'Usages' ),
            'flows' => array( 'label' => 'D’où viennent et où vont les données ?', 'short' => 'Flux' ),
            'external' => array( 'label' => 'Quels outils utilisez-vous en dehors de WordPress ?', 'short' => 'Outils externes' ),
            'risk' => array( 'label' => 'Y a-t-il des situations sensibles ?', 'short' => 'Risques' ),
            'cookies' => array( 'label' => 'Cookies et traceurs', 'short' => 'Cookies' ),
            'email' => array( 'label' => 'Suivi dans les e-mails', 'short' => 'E-mails' ),
            'authority' => array( 'label' => 'Droits et autorité de contrôle', 'short' => 'Droits' ),
            'preview' => array( 'label' => 'Relire avant publication', 'short' => 'Aperçu' ),
        );
    }

    private function detected_cookie_preferences_url( $scan ) {
        $cmp_slug = ! empty( $scan['cmp'][0]['slug'] ) ? (string) $scan['cmp'][0]['slug'] : '';
        $pages = get_pages( array( 'post_status' => 'publish', 'number' => 80 ) );
        $best = '';
        $best_score = 0;
        foreach ( $pages as $page ) {
            $hay = $this->normalize_text( $page->post_title . ' ' . $page->post_name . ' ' . $page->post_content );
            $score = 0;
            if ( false !== strpos( $hay, 'cookie' ) || false !== strpos( $hay, 'traceur' ) ) { $score += 4; }
            if ( 'complianz-gdpr' === $cmp_slug && ( false !== strpos( $hay, 'cmplz' ) || false !== strpos( $hay, 'complianz' ) ) ) { $score += 8; }
            if ( 'real-cookie-banner' === $cmp_slug && ( false !== strpos( $hay, 'real-cookie' ) || false !== strpos( $hay, 'real cookie' ) ) ) { $score += 8; }
            if ( 'cookie-law-info' === $cmp_slug && ( false !== strpos( $hay, 'cookieyes' ) || false !== strpos( $hay, 'cky-' ) ) ) { $score += 8; }
            if ( 'cookie-notice' === $cmp_slug && false !== strpos( $hay, 'cookie notice' ) ) { $score += 7; }
            if ( $score > $best_score ) { $best_score = $score; $best = get_permalink( $page->ID ); }
        }
        return $best_score >= 4 ? $best : '';
    }

    private function detected_cookie_retention( $scan ) {
        $candidates = array( 'cmplz_cookie_settings', 'cmplz_settings', 'complianz_options', 'cookieyes_options', 'cky_settings', 'real_cookie_banner', 'cookie_notice_options' );
        $keys = array( 'consent_duration','cookie_expiry','cookie_expiration','cookie_expiry_days','consent_expiration','consent_duration_days' );
        $walk = function( $value ) use ( &$walk, $keys ) {
            if ( ! is_array( $value ) ) { return ''; }
            foreach ( $value as $key => $item ) {
                if ( in_array( sanitize_key( (string) $key ), $keys, true ) && is_scalar( $item ) ) {
                    $raw = trim( (string) $item );
                    if ( preg_match( '/^\d+$/', $raw ) ) { return (int) $raw . ' jours (lu dans le réglage du gestionnaire de consentement — à vérifier)'; }
                    if ( $raw ) { return sanitize_text_field( $raw ); }
                }
                if ( is_array( $item ) ) { $found = $walk( $item ); if ( $found ) { return $found; } }
            }
            return '';
        };
        foreach ( $candidates as $option ) { $found = $walk( get_option( $option, array() ) ); if ( $found ) { return $found; } }
        return '';
    }

    private function apply_detected_profile_suggestions( $profile, $scan ) {
        $p = wp_parse_args( is_array( $profile ) ? $profile : array(), $this->legal_profile_defaults() );
        $optional_ids = array( 'google-analytics','meta-pixel','clarity','hotjar','tiktok-pixel','linkedin-insight','pinterest-tag','youtube','vimeo','google-maps','matomo','plausible','brevo' );
        $active_optional = false;
        foreach ( isset( $scan['findings'] ) && is_array( $scan['findings'] ) ? $scan['findings'] : array() as $finding ) {
            $id = isset( $finding['id'] ) ? (string) $finding['id'] : '';
            $state = isset( $finding['tracking_state'] ) ? (string) $finding['tracking_state'] : '';
            if ( in_array( $id, $optional_ids, true ) && ( 'active' === $state || ! empty( $finding['active_tracking'] ) ) ) { $active_optional = true; break; }
        }
        if ( 'unknown' === $p['cookie_nonessential'] && $active_optional ) { $p['cookie_nonessential'] = 'yes'; }
        if ( empty( $p['cookie_preferences'] ) ) { $p['cookie_preferences'] = $this->detected_cookie_preferences_url( $scan ); }
        if ( empty( $p['cookie_choice_retention'] ) ) {
            $detected_retention = $this->detected_cookie_retention( $scan );
            if ( $detected_retention ) { $p['cookie_choice_retention'] = $detected_retention; }
        }
        if ( 'unknown' === $p['cookie_cross_device'] && ! empty( $scan['cmp'][0]['slug'] ) && in_array( $scan['cmp'][0]['slug'], array( 'complianz-gdpr','real-cookie-banner','cookie-law-info','cookie-notice','gdpr-cookie-compliance' ), true ) ) {
            $p['cookie_cross_device'] = 'no';
            if ( empty( $p['cookie_cross_device_explanation'] ) ) { $p['cookie_cross_device_explanation'] = 'Le choix semble enregistré dans ce navigateur/appareil par l’outil de consentement détecté. Vérifiez sur un second appareil si vous utilisez une synchronisation liée à un compte.'; }
        }
        $mail = isset( $scan['mailing'] ) && is_array( $scan['mailing'] ) ? $scan['mailing'] : array();
        if ( $mail ) {
            $known = true; $any_active = false; $all_disabled = true;
            foreach ( $mail as $tool ) {
                $state = isset( $tool['tracking_state'] ) ? (string) $tool['tracking_state'] : 'unknown';
                if ( 'active' === $state ) { $any_active = true; $all_disabled = false; }
                elseif ( 'disabled' !== $state ) { $known = false; $all_disabled = false; }
            }
            if ( 'unknown' === $p['email_pixels'] ) {
                if ( $any_active ) { $p['email_pixels'] = 'yes'; }
                elseif ( $known && $all_disabled ) { $p['email_pixels'] = 'no'; }
            }
            if ( 'unknown' === $p['tracked_links'] ) {
                // MailPoet and Dendrila Privacy's FluentCRM privacy mode couple opening/click tracking in the states we can read reliably.
                if ( $any_active ) { $p['tracked_links'] = 'yes'; }
                elseif ( $known && $all_disabled ) { $p['tracked_links'] = 'no'; }
            }
        }
        return $p;
    }

    private function legal_page_url_select( $name, $label, $value, $help = '' ) {
        echo '<label class="ptm-field"><span>' . esc_html( $label ) . '</span><select name="legal_profile[' . esc_attr( $name ) . ']"><option value="">— À vérifier / choisir une page —</option>';
        $seen = array();
        foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 100 ) ) as $page ) {
            $url = get_permalink( $page->ID ); if ( ! $url ) { continue; } $seen[] = $url;
            echo '<option value="' . esc_url( $url ) . '" ' . selected( $value, $url, false ) . '>' . esc_html( $page->post_title ? $page->post_title : '(sans titre)' ) . '</option>';
        }
        if ( $value && ! in_array( $value, $seen, true ) ) { echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>'; }
        echo '</select>'; if ( $help ) { echo '<small>' . esc_html( $help ) . '</small>'; } echo '</label>';
    }

    private function legal_cookie_retention_select( $value ) {
        $choices = array(
            '' => 'À vérifier / non déterminé automatiquement',
            '6 mois' => '6 mois — bonne pratique générale recommandée par la CNIL',
            '1 mois' => '1 mois', '3 mois' => '3 mois', '12 mois' => '12 mois', 'Session' => 'Pendant la session uniquement',
        );
        if ( $value && ! isset( $choices[ $value ] ) ) { $choices = array( $value => $value . ' — valeur détectée ou personnalisée' ) + $choices; }
        $this->legal_select( 'cookie_choice_retention', 'Combien de temps le choix de cookies est-il mémorisé ?', $value, $choices );
        echo '<p class="description ptm-field-note">Dendrila Privacy essaie d’abord de lire le réglage du gestionnaire de consentement. À défaut, 6 mois est proposé comme bonne pratique générale CNIL, à apprécier selon le contexte — ce n’est pas une durée légale universelle.</p>';
    }

    private function detected_external_service_labels( $scan ) {
        $wanted = array( 'helloasso','calendly','koalendar','cal-com','microsoft-bookings','google-booking','typeform','google-forms','microsoft-forms','stripe','paypal','sumup','eventbrite','whatsapp','telegram' );
        $labels = array();
        foreach ( isset( $scan['findings'] ) && is_array( $scan['findings'] ) ? $scan['findings'] : array() as $finding ) {
            $id = isset( $finding['id'] ) ? sanitize_key( $finding['id'] ) : '';
            if ( in_array( $id, $wanted, true ) && ( ! empty( $finding['observed_html'] ) || ! empty( $finding['plugin_sources'] ) || 'active' === ( isset( $finding['tracking_state'] ) ? $finding['tracking_state'] : '' ) ) ) {
                $labels[ $id ] = isset( $finding['label'] ) ? (string) $finding['label'] : $id;
            }
        }
        return $labels;
    }

    private function format_relative_retention_option( $raw ) {
        if ( function_exists( 'wc_parse_relative_date_option' ) ) {
            $parsed = wc_parse_relative_date_option( $raw );
            if ( is_array( $parsed ) && ! empty( $parsed['number'] ) && ! empty( $parsed['unit'] ) ) {
                return absint( $parsed['number'] ) . ' ' . sanitize_text_field( $parsed['unit'] );
            }
        }
        if ( is_array( $raw ) && ! empty( $raw['number'] ) && ! empty( $raw['unit'] ) ) {
            return absint( $raw['number'] ) . ' ' . sanitize_text_field( $raw['unit'] );
        }
        $raw = trim( (string) $raw );
        if ( preg_match( '/^(\d+)\s*(day|days|week|weeks|month|months|year|years)$/i', $raw, $m ) ) {
            return absint( $m[1] ) . ' ' . sanitize_text_field( strtolower( $m[2] ) );
        }
        return '';
    }

    private function form_audit_status() {
        $plugins = $this->installed_plugins();
        $active_slugs = array();
        foreach ( $plugins as $plugin ) {
            if ( ! empty( $plugin['active'] ) && ! empty( $plugin['slug'] ) ) { $active_slugs[] = (string) $plugin['slug']; }
        }
        $items = array();

        // Contact Form 7 stores its form template locally. PTM only looks for a few
        // high-signal required fields; it does not try to decide whether a field is lawful.
        if ( in_array( 'contact-form-7', $active_slugs, true ) ) {
            $form_ids = get_posts( array(
                'post_type' => 'wpcf7_contact_form',
                'post_status' => array( 'publish', 'draft' ),
                'numberposts' => 30,
                'fields' => 'ids',
                'suppress_filters' => false,
            ) );
            foreach ( $form_ids as $form_id ) {
                $template = (string) get_post_meta( $form_id, '_form', true );
                if ( '' === trim( $template ) ) { continue; }
                $label = get_the_title( $form_id );
                $label = $label ? $label : 'Formulaire #' . absint( $form_id );
                $checks = array();
                if ( preg_match( '/\[\s*tel\*\b/i', $template ) ) { $checks[] = 'téléphone obligatoire'; }
                if ( preg_match( '/\[\s*(?:date|number)\*\b/i', $template ) ) { $checks[] = 'date ou nombre obligatoire'; }
                if ( preg_match( '/\[\s*text\*[^\]]*(?:address|adresse|postal|ville|city)/i', $template ) ) { $checks[] = 'adresse obligatoire'; }
                if ( $checks ) {
                    $items[] = array(
                        'tool'=>'Contact Form 7', 'label'=>$label,
                        'value'=>'À vérifier : ' . implode( ', ', $checks ), 'tone'=>'warn',
                        'settings_url'=>admin_url( 'admin.php?page=wpcf7&post=' . absint( $form_id ) . '&action=edit' ),
                        'note'=>'Dendrila Privacy a seulement repéré des champs obligatoires potentiellement à justifier. Vérifiez qu’ils sont nécessaires à la demande traitée.',
                    );
                } else {
                    $items[] = array(
                        'tool'=>'Contact Form 7', 'label'=>$label,
                        'value'=>'Aucun champ obligatoire sensible repéré', 'tone'=>'good',
                        'settings_url'=>admin_url( 'admin.php?page=wpcf7&post=' . absint( $form_id ) . '&action=edit' ),
                        'note'=>'Cela ne remplace pas la vérification de l’information affichée près du formulaire, des destinataires et de la durée de conservation.',
                    );
                }
            }
        }

        if ( in_array( 'gravityforms', $active_slugs, true ) && class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'get_forms' ) ) {
            try {
                foreach ( (array) GFAPI::get_forms() as $form ) {
                    $flags = array();
                    foreach ( isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array() as $field ) {
                        $required = is_object( $field ) ? ! empty( $field->isRequired ) : ( is_array( $field ) && ! empty( $field['isRequired'] ) );
                        $type = is_object( $field ) && isset( $field->type ) ? (string) $field->type : ( is_array( $field ) && isset( $field['type'] ) ? (string) $field['type'] : '' );
                        if ( ! $required ) { continue; }
                        if ( in_array( $type, array( 'phone','address','date' ), true ) ) { $flags[] = $type; }
                    }
                    $id = absint( isset( $form['id'] ) ? $form['id'] : 0 );
                    $label = ! empty( $form['title'] ) ? (string) $form['title'] : 'Formulaire #' . $id;
                    $items[] = array(
                        'tool'=>'Gravity Forms', 'label'=>$label,
                        'value'=>$flags ? 'À vérifier : champs obligatoires ' . implode( ', ', array_values( array_unique( $flags ) ) ) : 'Aucun champ obligatoire à risque évident repéré',
                        'tone'=>$flags ? 'warn' : 'good',
                        'settings_url'=>admin_url( 'admin.php?page=gf_edit_forms&id=' . $id ),
                        'note'=>$flags ? 'Dendrila Privacy signale une question de minimisation, pas une infraction : confirmez que chaque champ obligatoire est nécessaire.' : 'Vérifiez aussi l’information près du formulaire et les actions après envoi.',
                    );
                }
            } catch ( Throwable $e ) {
                $items[] = array( 'tool'=>'Gravity Forms','label'=>'Formulaires','value'=>'Lecture impossible','tone'=>'neutral','settings_url'=>admin_url('admin.php?page=gf_edit_forms'),'note'=>'Vérifiez manuellement les champs obligatoires et les réglages de données personnelles.' );
            }
        }

        // For these builders/plugins PTM deliberately avoids a broad database crawl during
        // assistant rendering. Detection stays useful without making the screen slow.
        $advisory = array(
            'elementor-pro'=>'Elementor Pro Forms',
            'wpforms-lite'=>'WPForms', 'wpforms'=>'WPForms',
            'fluentform'=>'Fluent Forms', 'fluent-forms'=>'Fluent Forms',
        );
        foreach ( $advisory as $slug=>$label ) {
            if ( ! in_array( $slug, $active_slugs, true ) ) { continue; }
            $already = false;
            foreach ( $items as $item ) { if ( $item['tool'] === $label ) { $already = true; break; } }
            if ( $already ) { continue; }
            $items[] = array(
                'tool'=>$label, 'label'=>'Formulaires du site', 'value'=>'À vérifier', 'tone'=>'neutral',
                'settings_url'=>admin_url( 'plugins.php' ),
                'note'=>'Dendrila Privacy détecte l’outil sans parcourir toutes ses données à chaque affichage. Vérifiez les champs obligatoires, l’information près du formulaire, les destinataires et la durée.',
            );
        }
        return array_slice( $items, 0, 30 );
    }

    private function retention_tools_status() {
        $plugins = $this->installed_plugins();
        $active_slugs = array();
        foreach ( $plugins as $plugin ) { if ( ! empty( $plugin['active'] ) && ! empty( $plugin['slug'] ) ) { $active_slugs[] = $plugin['slug']; } }
        $items = array();

        if ( in_array( 'gravityforms', $active_slugs, true ) && class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'get_forms' ) ) {
            try {
                $forms = GFAPI::get_forms();
                foreach ( (array) $forms as $form ) {
                    $retention = ! empty( $form['personalData']['retention'] ) && is_array( $form['personalData']['retention'] ) ? $form['personalData']['retention'] : array();
                    $policy = isset( $retention['policy'] ) ? sanitize_key( $retention['policy'] ) : 'retain';
                    $days = isset( $retention['retain_entries_days'] ) ? absint( $retention['retain_entries_days'] ) : 0;
                    $label = ! empty( $form['title'] ) ? (string) $form['title'] : 'Formulaire #' . absint( isset($form['id'])?$form['id']:0 );
                    $value = 'retain' === $policy ? 'Conservation illimitée / suppression manuelle' : ( $days ? ( ('trash'===$policy?'Corbeille après ':'Suppression après ') . $days . ' jour(s)' ) : 'Politique automatique à vérifier' );
                    $items[] = array(
                        'tool'=>'Gravity Forms', 'label'=>$label, 'value'=>$value,
                        'tone'=>'retain'===$policy?'warn':'good',
                        'settings_url'=>admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=personal-data&id=' . absint(isset($form['id'])?$form['id']:0) ),
                        'note'=>'Dendrila Privacy lit le réglage « Données personnelles » du formulaire en lecture seule.',
                    );
                }
            } catch ( Throwable $e ) {
                $items[] = array( 'tool'=>'Gravity Forms','label'=>'Réglages de conservation','value'=>'Lecture impossible','tone'=>'neutral','settings_url'=>admin_url('admin.php?page=gf_edit_forms'),'note'=>'Ouvrez les réglages Données personnelles de chaque formulaire.' );
            }
        }

        if ( in_array( 'woocommerce', $active_slugs, true ) ) {
            $woocommerce_options = array(
                'woocommerce_trash_pending_orders'=>'Commandes en attente',
                'woocommerce_trash_failed_orders'=>'Commandes échouées',
                'woocommerce_trash_cancelled_orders'=>'Commandes annulées',
                'woocommerce_anonymize_refunded_orders'=>'Commandes remboursées',
                'woocommerce_anonymize_completed_orders'=>'Commandes terminées',
                'woocommerce_delete_inactive_accounts'=>'Comptes inactifs',
            );
            foreach ( $woocommerce_options as $option=>$label ) {
                $formatted = $this->format_relative_retention_option( get_option( $option, '' ) );
                $items[] = array(
                    'tool'=>'WooCommerce','label'=>$label,'value'=>$formatted ? $formatted : 'Durée non définie',
                    'tone'=>$formatted?'good':'warn',
                    'settings_url'=>admin_url('admin.php?page=wc-settings&tab=account'),
                    'note'=>'Réglage lu dans WooCommerce > Comptes et confidentialité.',
                );
            }
        }

        if ( in_array( 'mailpoet', $active_slugs, true ) ) {
            $items[] = array(
                'tool'=>'MailPoet','label'=>'Abonnés inactifs','value'=>'À vérifier dans MailPoet', 'tone'=>'neutral',
                'settings_url'=>admin_url('admin.php?page=mailpoet-settings#/advanced'),
                'note'=>'Important : arrêter les envois aux abonnés inactifs ne supprime pas leurs données. Dendrila Privacy ne confond pas inactivité et durée de conservation.',
            );
        }
        return $items;
    }

    private function backup_tools_status() {
        $plugins = $this->installed_plugins();
        $slugs = array();
        foreach ( $plugins as $plugin ) { if ( ! empty( $plugin['active'] ) ) { $slugs[] = $plugin['slug']; } }
        $items = array();
        if ( in_array( 'updraftplus', $slugs, true ) ) {
            $files = (string) get_option( 'updraft_interval', 'manual' );
            $db = (string) get_option( 'updraft_interval_database', 'manual' );
            $retain = absint( get_option( 'updraft_retain', 0 ) );
            $retain_db = absint( get_option( 'updraft_retain_db', 0 ) );
            $service = get_option( 'updraft_service', '' );
            if ( is_array( $service ) ) { $service = implode( ', ', array_filter( array_map( 'sanitize_text_field', $service ) ) ); }
            $items[] = array(
                'id'=>'updraftplus','label'=>'UpdraftPlus','settings_url'=>admin_url( 'options-general.php?page=updraftplus' ),
                'details'=>array(
                    'Fichiers' => $files && 'manual' !== $files ? $files : 'sauvegarde automatique non détectée',
                    'Base de données' => $db && 'manual' !== $db ? $db : 'sauvegarde automatique non détectée',
                    'Copies conservées' => ( $retain || $retain_db ) ? trim( ( $retain ? $retain . ' fichiers' : '' ) . ( $retain && $retain_db ? ' · ' : '' ) . ( $retain_db ? $retain_db . ' bases' : '' ) ) : 'à vérifier',
                    'Stockage distant' => $service ? sanitize_text_field( $service ) : 'aucun service distant détecté dans le réglage',
                ),
            );
        }
        $known = array(
            'backwpup'=>'BackWPup','wpvivid-backuprestore'=>'WPvivid','duplicator'=>'Duplicator','jetpack'=>'Jetpack / Backup','backupbuddy'=>'Solid Backups / BackupBuddy','solid-backups'=>'Solid Backups'
        );
        foreach ( $known as $slug=>$label ) {
            if ( in_array( $slug, $slugs, true ) ) { $items[] = array( 'id'=>$slug,'label'=>$label,'settings_url'=>admin_url( 'plugins.php' ),'details'=>array( 'Configuration'=>'Plugin détecté : vérifiez qu’une sauvegarde automatique et une copie séparée du site sont configurées.' ) ); }
        }
        return $items;
    }

    private function render_legal_wizard( $settings, $audit ) {
        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        $scan = get_option( self::OPTION_SCAN, array() );
        $profile = $this->apply_detected_profile_suggestions( $profile, $scan );
        $readiness = $this->legal_profile_readiness( $profile );
        $legal_notice_missing = $this->legal_notice_readiness( $profile );
        $mailing_present = ! empty( $scan['mailing'] );
        $wizard_steps = $this->legal_wizard_steps();
        $resume_step = $this->legal_wizard_resume_step( $profile );
        $requested_step = sanitize_key( $this->query_value( 'ptm_step' ) );
        if ( $requested_step && isset( $wizard_steps[ $requested_step ] ) ) { $resume_step = $requested_step; }
        $wizard_count = count( $wizard_steps );
        echo '<section class="ptm-card ptm-wizard" id="ptm-legal-wizard" data-ptm-guided-wizard data-ptm-resume-step="' . esc_attr( $resume_step ) . '" data-ptm-reviewed-once="' . ( ! empty( $profile['wizard_reviewed_once'] ) ? '1' : '0' ) . '"><div class="ptm-card-head"><div><h2><span class="dashicons dashicons-welcome-write-blog"></span> Assistant RGPD</h2><p>Une étape à la fois, avec des exemples en langage courant. Vos réponses sont sauvegardées bloc par bloc et vous pouvez reprendre plus tard.</p></div><span class="ptm-badge neutral">' . esc_html( $wizard_count ) . ' étapes courtes</span></div>';
        echo '<div class="ptm-guided-progress"><div class="ptm-guided-progress-head"><strong id="ptm-wizard-progress-label">Étape 1 sur ' . esc_html( $wizard_count ) . '</strong><span id="ptm-wizard-progress-title">Vous et le site</span></div><div class="ptm-guided-progress-track" role="progressbar" aria-valuemin="1" aria-valuemax="' . esc_attr( $wizard_count ) . '" aria-valuenow="1"><span id="ptm-wizard-progress-bar"></span></div><div class="ptm-guided-stepper" role="navigation" aria-label="Étapes de l’assistant RGPD">';
        $step_number = 0;
        foreach ( $wizard_steps as $step_id => $step_meta ) {
            $step_number++;
            echo '<button type="button" class="ptm-guided-step" data-ptm-go-step="' . esc_attr( $step_id ) . '" title="' . esc_attr( $step_meta['label'] ) . '"><span>' . esc_html( $step_number ) . '</span><small>' . esc_html( $step_meta['short'] ) . '</small></button>';
        }
        echo '</div><div class="ptm-guided-mode-actions"><button type="button" class="button-link" id="ptm-wizard-show-all">Afficher tout le formulaire</button><button type="button" class="button-link" id="ptm-wizard-guided-mode" hidden>Revenir à l’assistant étape par étape</button></div></div>';
        echo '<div class="ptm-callout neutral ptm-wizard-reassurance"><strong>Vous hésitez ?</strong> Choisissez « À vérifier » ou laissez un champ facultatif vide. Dendrila Privacy ne vous demande pas d’inventer une réponse juridique.</div>';
        if ( ! empty( $readiness['errors'] ) ) { echo '<div class="ptm-callout warn"><strong>Avant insertion du bloc RGPD, complétez encore :</strong><ul>'; foreach ( array_slice( $readiness['errors'], 0, 12 ) as $item ) { echo '<li>' . esc_html( $item ) . '</li>'; } echo '</ul></div>'; }
        if ( $legal_notice_missing ) { echo '<div class="ptm-callout neutral"><strong>Mentions légales à compléter :</strong> ' . esc_html( implode( ', ', $legal_notice_missing ) ) . '.</div>'; }
        echo '<div class="ptm-legal-note"><strong>Gain de temps :</strong> commencez par rechercher l’entreprise ci-dessous. Dendrila Privacy peut préremplir les données publiques disponibles (nom, adresse, SIREN/SIRET, TVA, forme/activité quand présentes), puis vous complétez seulement ce que le registre ne connaît pas. La recherche n’est envoyée au registre public que lorsque vous cliquez sur « Rechercher ».</div>';

        $this->legal_section_form_start( 'identity' );
        echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 1</div><h3>Qui édite le site, et qui décide de l’utilisation des données ?</h3><p class="ptm-question-intro">Ces rôles sont souvent la même structure, mais pas toujours. Le webmaster n’est pas automatiquement responsable du traitement : indiquez séparément l’éditeur du site, le responsable du traitement et le contact qui répond aux demandes sur les données.</p>';
        echo '<div class="ptm-company-lookup">';
        echo '<label for="ptm-company-query"><strong>Gagner du temps avec un préremplissage</strong></label>';
        echo '<div class="ptm-company-search-row">';
        echo '<select id="ptm-company-country" name="legal_profile[company_country]" aria-label="Pays de la structure">';
        echo '<option value="">— Pays —</option>';
        foreach ( $this->company_lookup_countries() as $country_code => $country_label ) {
            echo '<option value="' . esc_attr( $country_code ) . '" ' . selected( $profile['company_country'], $country_code, false ) . '>' . esc_html( $country_label ) . '</option>';
        }
        echo '</select>';
        echo '<input id="ptm-company-query" type="search" value="' . esc_attr( $profile['controller_name'] ) . '" placeholder="Nom, identifiant national ou n° TVA">';
        echo '<button type="button" class="button button-secondary" id="ptm-company-search">Rechercher la structure</button>';
        echo '<button type="button" class="button" id="ptm-site-prefill">Utiliser les infos WordPress</button>';
        echo '</div>';
        echo '<p class="description" id="ptm-company-lookup-help">Choisissez le pays. Pour la France, la recherche accepte un nom, SIREN ou SIRET ; pour les autres pays proposés, VIES vérifie un numéro de TVA.</p>';
        echo '<p class="description">La recherche externe ne part que si vous cliquez sur « Rechercher la structure ».</p>';
        echo '<div id="ptm-company-results" class="ptm-company-results" aria-live="polite"></div></div>';
        echo '<div class="ptm-subsection"><h4>Le minimum à renseigner</h4><div class="ptm-form-grid">';
        $this->legal_input( 'site_editor_name', 'Éditeur du site — nom / raison sociale', $profile['site_editor_name'], true, 'La personne ou l’organisation qui publie le site. Pour une petite structure, c’est souvent la même que le responsable du traitement.' );
        $this->legal_textarea( 'site_editor_address', 'Éditeur du site — adresse / siège', $profile['site_editor_address'] );
        $this->legal_input( 'controller_name', 'Responsable du traitement — nom / raison sociale', $profile['controller_name'], true, 'La personne ou l’organisation qui décide pourquoi et comment les données sont utilisées. Ce n’est pas automatiquement le webmaster.' );
        $this->legal_select( 'entity_type', 'Vous êtes…', $profile['entity_type'], array( 'unknown'=>'À préciser','company'=>'Une société','individual'=>'Un entrepreneur individuel','association'=>'Une association','other'=>'Une autre structure' ) );
        $this->legal_textarea( 'controller_address', 'Adresse / siège', $profile['controller_address'] );
        $this->legal_input( 'site_name', 'Nom du site', $profile['site_name'] ); $this->legal_url_input( 'site_url', 'Adresse du site', $profile['site_url'] );
        $this->legal_input( 'privacy_contact', 'Contact pour exercer ses droits', $profile['privacy_contact'], true, 'Adresse à laquelle une personne peut demander accès, rectification, effacement, opposition, etc. Elle peut être différente de l’e-mail public du site.' );
        echo '</div></div>';
        echo '<details class="ptm-subsection ptm-subsection-details"><summary>Mentions légales et coordonnées publiques <small>À compléter selon votre structure</small></summary><div class="ptm-form-grid">';
        $this->legal_input( 'company_registration_number', 'Identifiant d’immatriculation (hors France)', $profile['company_registration_number'] ); $this->legal_input( 'company_registration_label', 'Nom de cet identifiant', $profile['company_registration_label'] );
        $this->legal_input( 'company_siren', 'SIREN (France)', $profile['company_siren'] ); $this->legal_input( 'company_siret', 'SIRET du siège (France)', $profile['company_siret'] );
        $this->legal_input( 'company_vat', 'N° TVA intracommunautaire', $profile['company_vat'] ); $this->legal_input( 'company_legal_form', 'Forme juridique', $profile['company_legal_form'] );
        $this->legal_input( 'company_capital', 'Capital social (si applicable)', $profile['company_capital'] ); $this->legal_input( 'company_registry', 'Immatriculation / RCS / RM', $profile['company_registry'] );
        $this->legal_input( 'company_activity', 'Activité principale (repère)', $profile['company_activity'] );
        $this->legal_input( 'public_contact_email', 'E-mail public', $profile['public_contact_email'] ); $this->legal_input( 'public_contact_phone', 'Téléphone public', $profile['public_contact_phone'] );
        $this->legal_input( 'publication_director', 'Directeur / responsable de la publication', $profile['publication_director'] );
        echo '</div></details>';
        echo '<details class="ptm-subsection ptm-subsection-details"><summary>Hébergeur du site <small>Dendrila Privacy peut proposer le nom et les coordonnées</small></summary><div class="ptm-form-grid">';
        $this->legal_host_input( $profile['host_name'] ); $this->legal_textarea( 'host_address', 'Adresse de l’hébergeur', $profile['host_address'], 'Cette adresse est proposée automatiquement lorsqu’elle figure dans la base locale de Dendrila Privacy ; vérifiez-la avant publication.' ); $this->legal_input( 'host_phone', 'Téléphone de l’hébergeur', $profile['host_phone'], false, 'Le numéro est prérempli uniquement lorsque Dendrila Privacy dispose d’une source officielle vérifiée.' );
        echo '<input type="hidden" id="ptm-host-source-url" name="legal_profile[host_source_url]" value="' . esc_attr( $profile['host_source_url'] ) . '"><div id="ptm-host-source-saved" class="ptm-host-source-saved">' . ( $profile['host_source_url'] ? '<a href="' . esc_url( $profile['host_source_url'] ) . '" target="_blank" rel="noopener noreferrer">Vérifier les coordonnées sur la source officielle</a>' : '' ) . '</div>';
        echo '</div></details>';
        echo '<details class="ptm-subsection ptm-subsection-details"><summary>Cas particuliers <small>DPO, activité réglementée, représentant européen…</small></summary><div class="ptm-form-grid">';
        $this->legal_textarea( 'regulated_activity_details', 'Activité réglementée / autorité compétente (si applicable)', $profile['regulated_activity_details'] ); $this->legal_input( 'rep_idu', 'Identifiant(s) REP / IDU (si applicable)', $profile['rep_idu'] );
        $this->legal_select( 'dpo_designated', 'Un DPO / DPD a-t-il réellement été désigné ?', $profile['dpo_designated'], array( 'no'=>'Non','yes'=>'Oui','unknown'=>'À vérifier' ) );
        $this->legal_input( 'dpo_contact', 'Coordonnées du DPO / DPD (uniquement s’il est désigné)', $profile['dpo_contact'], false, 'Ne mettez pas le webmaster ici simplement parce qu’il administre le site.' );
        $this->legal_select( 'representative_applicable', 'Représentant UE/EEE à mentionner ?', $profile['representative_applicable'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_input( 'controller_representative', 'Identité du représentant UE/EEE', $profile['controller_representative'] ); $this->legal_textarea( 'controller_representative_contact', 'Coordonnées du représentant', $profile['controller_representative_contact'] );
        echo '<input type="hidden" name="legal_profile[company_lookup_source]" value="' . esc_attr( $profile['company_lookup_source'] ) . '"><input type="hidden" name="legal_profile[company_lookup_at]" value="' . esc_attr( $profile['company_lookup_at'] ) . '">';
        echo '</div></details></div>'; $this->legal_section_form_end();

        $this->legal_section_form_start( 'treatments' );
        $recommended_templates = $this->treatment_template_recommendations( $scan );
        echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 2</div><h3>À quoi utilisez-vous les données personnelles ?</h3><p class="ptm-question-intro">Choisissez d’abord les activités qui correspondent réellement à votre site. Dendrila Privacy crée un exemple que vous pouvez corriger, sans vous demander de connaître le vocabulaire juridique.</p>';
        echo '<div class="ptm-treatment-guide"><h4>En clair : un traitement = une raison d’utiliser des données</h4><p>Un traitement décrit <strong>pourquoi vous utilisez des données personnelles</strong>. Ne saisissez pas le nom d’une extension comme « Contact Form 7 » : écrivez plutôt « Répondre aux demandes envoyées par le formulaire de contact ».</p><ol><li><strong>Finalité</strong> : pourquoi les données sont utilisées.</li><li><strong>Catégories de données</strong> : quels types d’informations sont concernés.</li><li><strong>Base juridique</strong> : ce qui autorise ce traitement — Dendrila Privacy peut suggérer un exemple, mais vous devez le confirmer selon votre situation.</li><li><strong>Destinataires</strong> : qui peut accéder aux données (équipe, hébergeur, prestataire…).</li><li><strong>Durée</strong> : combien de temps elles sont conservées, ou selon quel critère.</li></ol><p><strong>Exemple :</strong> finalité « Répondre aux demandes de contact » · données « nom, e-mail, message » · destinataires « personnes chargées de répondre + hébergeur » · durée « le temps nécessaire au traitement de la demande puis archivage limité selon la relation créée ».</p></div>';
        echo '<div class="ptm-treatment-templates"><strong>Que fait votre site ? Sélectionnez un exemple :</strong><div class="ptm-template-buttons">';
        foreach ( $recommended_templates as $template ) {
            echo '<button type="button" class="button ptm-treatment-template' . ( ! empty( $template['detected'] ) ? ' is-detected' : '' ) . '" data-template="' . esc_attr( $template['id'] ) . '"><strong>' . esc_html( $template['label'] ) . '</strong>' . ( ! empty( $template['badge'] ) ? '<small>' . esc_html( $template['badge'] ) . '</small>' : '<small>utiliser comme exemple</small>' ) . '</button>';
        }
        echo '</div><p class="description">Dendrila Privacy remplit la première ligne vide. Les textes sont des points de départ : relisez surtout la base juridique, les destinataires et la durée réelle.</p></div>';
        echo '<div class="ptm-treatment-editor">';
        $rows = array_values( (array) $profile['treatments'] ); while ( count( $rows ) < 4 ) { $rows[] = array(); }
        foreach ( array_slice( $rows, 0, 8 ) as $i => $row ) { $this->render_treatment_row( $i, $row ); }
        echo '</div>';
        $retention_status = $this->retention_tools_status();
        if ( $retention_status ) {
            echo '<div class="ptm-subsection ptm-retention-tools"><h4>Durées déjà paramétrées dans vos extensions</h4><p class="description">Dendrila Privacy lit uniquement les réglages documentés qu’il connaît. Il ne fouille pas arbitrairement les tables des extensions et ne modifie aucune durée sans action explicite.</p><div class="ptm-retention-status-list">';
            foreach ( $retention_status as $retention_item ) {
                echo '<article class="ptm-retention-status"><div><span class="ptm-badge ' . esc_attr( $retention_item['tone'] ) . '">' . esc_html( $retention_item['tool'] ) . '</span><strong>' . esc_html( $retention_item['label'] ) . '</strong><small>' . esc_html( $retention_item['value'] ) . '</small><em>' . esc_html( $retention_item['note'] ) . '</em></div><a class="button button-secondary" href="' . esc_url( $retention_item['settings_url'] ) . '">Vérifier / définir la durée</a></article>';
            }
            echo '</div></div>';
        }
        $form_audit = $this->form_audit_status();
        if ( $form_audit ) {
            echo '<div class="ptm-subsection ptm-form-audit"><h4>Formulaires : points à vérifier</h4><p class="description">Dendrila Privacy cherche uniquement des signaux simples dans les outils qu’il connaît. Un champ signalé n’est pas déclaré illégal : il vous invite à vérifier qu’il est vraiment nécessaire.</p><div class="ptm-retention-status-list">';
            foreach ( $form_audit as $form_item ) {
                echo '<article class="ptm-retention-status"><div><span class="ptm-badge ' . esc_attr( $form_item['tone'] ) . '">' . esc_html( $form_item['tool'] ) . '</span><strong>' . esc_html( $form_item['label'] ) . '</strong><small>' . esc_html( $form_item['value'] ) . '</small><em>' . esc_html( $form_item['note'] ) . '</em></div><a class="button button-secondary" href="' . esc_url( $form_item['settings_url'] ) . '">Vérifier le formulaire</a></article>';
            }
            echo '</div></div>';
        }
        echo '</div>'; $this->legal_section_form_end();

        $source_services = $this->indirect_source_service_options( $scan );
        $this->legal_section_form_start( 'flows' );
        echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 3</div><h3>Quels services reçoivent ou produisent des informations ?</h3><p class="ptm-question-intro">Dendrila Privacy prépare une fiche par service à partir de la dernière analyse. Enregistrez Google Analytics, puis ajoutez un autre service, et ainsi de suite. <strong>Enregistrer un service ne vous fait jamais passer à l’étape 4</strong> : vous décidez vous-même quand continuer.</p>';
        echo '<div class="ptm-subsection"><h4>1. Comment les informations arrivent-elles ?</h4><div class="ptm-form-grid">';
        $this->legal_select( 'collection_mode', 'Origine générale des informations', $profile['collection_mode'], array( 'unknown'=>'Je ne sais pas encore','direct'=>'La personne les fournit elle-même','indirect'=>'Elles viennent surtout de services ou d’autres sources','both'=>'Les deux situations existent' ) );
        echo '</div></div>';
        echo '<div class="ptm-subsection ptm-indirect-source-helper"><h4>2. Services et sources indirectes</h4><p class="description">Les services désactivés sont exclus. Quand Dendrila Privacy connaît un élément, il le préremplit ; quand il ne peut pas l’affirmer, il indique « À vérifier » plutôt que d’inventer.</p>';
        echo '<div class="ptm-indirect-add-row"><label class="ptm-field"><span>Ajouter un service</span><select id="ptm-indirect-service-select"><option value="">Sélectionnez un service…</option>';
        foreach ( $source_services as $service ) {
            echo '<option value="' . esc_attr( $service['id'] ) . '" data-ptm-service="' . esc_attr( wp_json_encode( $service ) ) . '">' . esc_html( $service['label'] . ' — ' . ( 'active' === $service['state'] ? 'actif' : 'à vérifier' ) ) . '</option>';
        }
        echo '<option value="manual" data-ptm-service="{}">Autre service / source manuelle</option></select></label><button type="button" class="button button-secondary" id="ptm-add-indirect-service">Ajouter ce service</button></div>';
        echo '<div id="ptm-indirect-services" class="ptm-indirect-services">';
        $saved_services = isset( $profile['indirect_services'] ) && is_array( $profile['indirect_services'] ) ? array_values( $profile['indirect_services'] ) : array();
        if ( ! $saved_services && ( $profile['indirect_sources'] || $profile['indirect_categories'] ) ) {
            $saved_services[] = array( 'service_id'=>'legacy','label'=>'Source déjà renseignée','state'=>'manual','categories'=>$profile['indirect_categories'],'source'=>$profile['indirect_sources'],'recipients'=>'','transfer_status'=>$profile['transfers_status'],'transfer_destinations'=>$profile['transfer_destinations'],'transfer_mechanism'=>$profile['transfer_mechanism'],'transfer_safeguards'=>$profile['transfer_safeguards'],'automated_decision'=>$profile['automated_decision'],'automated_details'=>$profile['automated_details'] );
        }
        foreach ( $saved_services as $i => $service ) { $this->render_indirect_service_row( $i, $service ); }
        echo '</div><p class="description" id="ptm-indirect-service-help">Ajoutez autant de services que nécessaire. Chaque bouton « Enregistrer ce service » sauvegarde les fiches sans quitter cette étape.</p></div>';
        echo '<input type="hidden" name="legal_profile[indirect_categories]" value="' . esc_attr( $profile['indirect_categories'] ) . '"><input type="hidden" name="legal_profile[indirect_sources]" value="' . esc_attr( $profile['indirect_sources'] ) . '"><input type="hidden" name="legal_profile[transfers_status]" value="' . esc_attr( $profile['transfers_status'] ) . '"><input type="hidden" name="legal_profile[transfer_destinations]" value="' . esc_attr( $profile['transfer_destinations'] ) . '"><input type="hidden" name="legal_profile[transfer_mechanism]" value="' . esc_attr( $profile['transfer_mechanism'] ) . '"><input type="hidden" name="legal_profile[transfer_safeguards]" value="' . esc_attr( $profile['transfer_safeguards'] ) . '"><input type="hidden" name="legal_profile[automated_decision]" value="' . esc_attr( $profile['automated_decision'] ) . '"><input type="hidden" name="legal_profile[automated_details]" value="' . esc_attr( $profile['automated_details'] ) . '">';
        $this->legal_textarea( 'mandatory_information', 'Autre précision utile sur l’origine des informations', $profile['mandatory_information'], 'Facultatif : utilisez ce champ seulement pour une situation qui ne correspond à aucune fiche.' );
        echo '</div>'; $this->legal_section_form_end();

        $external_detected = $this->detected_external_service_labels( $scan );
        $backups = $this->backup_tools_status();
        $this->legal_section_form_start( 'external' );
        echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 4</div><h3>Quels outils utilisez-vous en dehors de WordPress ?</h3><p class="ptm-question-intro">Un site peut être correctement configuré tout en laissant des données circuler ailleurs. Dendrila Privacy vous donne des exemples concrets et préremplit ce qu’il a repéré sur le site.</p>';
        if ( $external_detected ) { echo '<div class="ptm-callout good"><strong>Repéré sur le site :</strong> ' . esc_html( implode( ', ', array_values( $external_detected ) ) ) . '. Confirmez seulement les usages qui existent encore.</div>'; }
        echo '<div class="ptm-form-grid">';
        $this->legal_select( 'external_email_use', 'Utilisez-vous une messagerie ou un outil e-mail en dehors de WordPress ?', $profile['external_email_use'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_input( 'external_email_tools', 'Exemples / outils e-mail utilisés', $profile['external_email_tools'], false, 'Exemples : Gmail, Outlook, Brevo, Mailchimp…' );
        $this->legal_select( 'external_bulk_email', 'Envoyez-vous parfois le même message à beaucoup de personnes depuis Gmail, Outlook ou une boîte classique ?', $profile['external_bulk_email'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_select( 'external_bulk_unsubscribe', 'Pour ces envois groupés, les personnes peuvent-elles facilement ne plus les recevoir ?', $profile['external_bulk_unsubscribe'], array( 'unknown'=>'À vérifier','no'=>'Non / pas toujours','yes'=>'Oui' ) );
        $this->legal_select( 'external_bulk_bcc', 'Quand un e-mail classique part à plusieurs personnes, utilisez-vous la CCI pour ne pas révéler leurs adresses ?', $profile['external_bulk_bcc'], array( 'unknown'=>'À vérifier','no'=>'Non / pas toujours','yes'=>'Oui' ) );
        $this->legal_select( 'external_messaging', 'Les personnes peuvent-elles vous contacter via une messagerie externe ?', $profile['external_messaging'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_input( 'external_messaging_tools', 'Messageries utilisées', $profile['external_messaging_tools'], false, 'Exemples : WhatsApp / WhatsApp Business, Messenger, SMS, Telegram…' );
        $this->legal_select( 'external_booking', 'Utilisez-vous un outil externe pour les rendez-vous ou réservations ?', $profile['external_booking'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_input( 'external_booking_tools', 'Outils de réservation', $profile['external_booking_tools'], false, 'Exemples : Calendly, Koalendar, Google Calendar / réservation Google, Microsoft Bookings, Cal.com…' );
        $this->legal_select( 'external_forms', 'Utilisez-vous des formulaires en dehors de WordPress ?', $profile['external_forms'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_input( 'external_form_tools', 'Formulaires externes', $profile['external_form_tools'], false, 'Exemples : Google Forms, Microsoft Forms, Typeform…' );
        $this->legal_select( 'external_payments', 'Utilisez-vous un service externe pour paiements, dons, adhésions ou billetterie ?', $profile['external_payments'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_input( 'external_payment_tools', 'Services concernés', $profile['external_payment_tools'], false, 'Exemples : HelloAsso, Stripe, PayPal, SumUp, Eventbrite…' );
        $this->legal_select( 'external_files', 'Conservez-vous des listes ou fichiers de personnes ailleurs ?', $profile['external_files'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_input( 'external_file_tools', 'Où ?', $profile['external_file_tools'], false, 'Exemples : Excel, CSV, Google Sheets, Drive, Dropbox, OneDrive…' );
        echo '</div>';
        if ( 'yes' === $profile['external_bulk_email'] && 'yes' !== $profile['external_bulk_unsubscribe'] ) { echo '<div class="ptm-callout warn"><strong>Envois groupés hors outil de newsletter :</strong> utilisez de préférence MailPoet/Brevo/Mailchimp pour les campagnes afin de gérer les désinscriptions. Pour un envoi classique ponctuel, utilisez la CCI.</div>'; }
        echo '<div class="ptm-subsection"><h4>Sauvegardes automatiques</h4><p class="description">Dendrila Privacy ne remplace pas Wordfence et ne réalise pas d’audit de sécurité général. Il vérifie ici uniquement les sauvegardes parce qu’elles contiennent souvent les mêmes données personnelles que le site.</p>';
        if ( ! $backups ) { echo '<div class="ptm-callout warn"><strong>Aucun plugin de sauvegarde connu détecté.</strong> Vérifiez si votre hébergeur réalise des sauvegardes automatiques ou configurez une solution adaptée.</div>'; }
        else { foreach ( $backups as $backup ) { echo '<article class="ptm-backup-status"><div><strong>' . esc_html( $backup['label'] ) . '</strong>'; foreach ( $backup['details'] as $key=>$value ) { echo '<small><b>' . esc_html( $key ) . ' :</b> ' . esc_html( $value ) . '</small>'; } echo '</div><a class="button button-secondary" href="' . esc_url( $backup['settings_url'] ) . '">Vérifier les réglages</a></article>'; } }
        $this->legal_select( 'backup_reviewed', 'Avez-vous vérifié qu’une sauvegarde automatique existe et qu’une copie n’est pas uniquement stockée avec le site ?', $profile['backup_reviewed'], array( 'unknown'=>'À vérifier','no'=>'Pas encore','yes'=>'Oui' ) );
        echo '</div></div>'; $this->legal_section_form_end();

        $this->legal_section_form_start( 'risk' ); echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 5</div><h3>Votre site traite-t-il des données sensibles ou à risque ?</h3><p class="ptm-question-intro">Pour un site vitrine classique, la plupart de ces réponses seront souvent « Non ». Choisissez « À vérifier » si vous avez un doute.</p><div class="ptm-form-grid">';
        $this->legal_select( 'special_categories', 'Catégories particulières de données (art. 9) ?', $profile['special_categories'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) ); $this->legal_textarea( 'special_categories_basis', 'Condition article 9 applicable', $profile['special_categories_basis'] );
        $this->legal_select( 'criminal_data', 'Condamnations / infractions (art. 10) ?', $profile['criminal_data'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) ); $this->legal_textarea( 'criminal_data_basis', 'Encadrement légal article 10', $profile['criminal_data_basis'] );
        $this->legal_select( 'minors_data', 'Traitements visant spécifiquement des mineurs ?', $profile['minors_data'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) ); $this->legal_textarea( 'minors_details', 'Information / consentement adaptés aux mineurs', $profile['minors_details'] );
        $this->legal_select( 'high_risk_processing', 'Ce traitement peut-il présenter un risque important pour les personnes ?', $profile['high_risk_processing'], array( 'unknown'=>'Je ne sais pas / à vérifier','no'=>'Non','yes'=>'Oui' ) ); $this->legal_select( 'dpia_status', 'Une analyse approfondie des risques est-elle nécessaire ou déjà faite ?', $profile['dpia_status'], array( 'unknown'=>'Je ne sais pas / à vérifier','not_required'=>'Après vérification, elle n’est pas requise','done'=>'Oui, elle a été réalisée','to_do'=>'Elle reste à réaliser / est en cours' ) ); echo '<p class="description ptm-field-note">Le nom juridique est « analyse d’impact sur la protection des données » (AIPD / DPIA). Elle concerne surtout certains traitements susceptibles d’engendrer un risque élevé.</p>';
        echo '</div></div>'; $this->legal_section_form_end();

        $this->legal_section_form_start( 'cookies' ); echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 6</div><h3>Cookies et traceurs facultatifs</h3><p class="ptm-question-intro">Dendrila Privacy préremplit cette étape à partir des traceurs actifs et du gestionnaire de consentement détecté. Un traceur désactivé ne compte pas comme traceur facultatif actif.</p><div class="ptm-form-grid">';
        $this->legal_select( 'cookie_nonessential', 'Votre site utilise-t-il des traceurs facultatifs ?', $profile['cookie_nonessential'], array( 'unknown'=>'À vérifier','no'=>'Non','yes'=>'Oui' ) );
        $this->legal_select( 'cookie_consent_status', 'Comment leur activation est-elle gérée ?', $profile['cookie_consent_status'], array( 'unknown'=>'À vérifier','yes'=>'Ils attendent le consentement','no'=>'Ils peuvent se lancer avant le choix','exempt_only'=>'Les traceurs utilisés sont déclarés exemptés de consentement' ) );
        $this->legal_page_url_select( 'cookie_preferences', 'Sur quelle page peut-on retirer ou modifier son choix ?', $profile['cookie_preferences'], 'Dendrila Privacy essaie de repérer la bonne page à partir du gestionnaire de consentement détecté. Corrigez la sélection si nécessaire.' );
        $this->legal_cookie_retention_select( $profile['cookie_choice_retention'] );
        $this->legal_select( 'cookie_cross_device', 'Le choix de cookies est-il conservé sur les autres appareils ?', $profile['cookie_cross_device'], array( 'unknown'=>'Impossible à déterminer automatiquement','yes'=>'Oui, le choix peut être retrouvé sur les autres appareils','no'=>'Non, le choix doit généralement être refait' ) );
        $this->legal_textarea( 'cookie_cross_device_explanation', 'Comment le vérifier / précision utile', $profile['cookie_cross_device_explanation'], 'Test simple : faites un choix ici, puis ouvrez le site sur un autre navigateur ou téléphone. Si la bannière redemande un choix, il n’est pas synchronisé.' );
        echo '</div><div class="ptm-callout neutral"><strong>Vous ne savez pas pour les appareils ?</strong> C’est normal. Dendrila Privacy essaie de le déduire du gestionnaire de consentement. Sinon, faites le test sur un autre navigateur/appareil ou laissez « ne peut pas encore le déterminer » : cela restera seulement une action à vérifier.</div></div>'; $this->legal_section_form_end();

        $this->legal_section_form_start( 'email' ); echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 7</div><h3>Vos e-mails mesurent-ils les ouvertures ou les clics ?</h3><p class="ptm-question-intro">Dendrila Privacy vérifie automatiquement les réglages accessibles. Pour les outils dont le réglage est externe ou privé, il indique simplement « À vérifier ».</p>';
        if ( ! $mailing_present ) { echo '<p class="ptm-muted">Aucun outil d’e-mailing connu n’est détecté actuellement. Si vos envois passent par un service externe, vous pouvez tout de même renseigner cette étape.</p>'; }
        else { echo '<div class="ptm-email-status-list">'; foreach ( (array) $scan['mailing'] as $mail_tool ) { $state = isset( $mail_tool['tracking_state'] ) ? $mail_tool['tracking_state'] : 'unknown'; $tone = 'active' === $state ? 'bad' : ( 'disabled' === $state ? 'good' : 'neutral' ); $label = 'active' === $state ? 'suivi d’engagement actif' : ( 'disabled' === $state ? 'suivi des ouvertures et des clics désactivé' : 'réglages à vérifier' ); echo '<span class="ptm-badge ' . esc_attr( $tone ) . '"><strong>' . esc_html( isset( $mail_tool['name'] ) ? $mail_tool['name'] : 'Outil e-mail' ) . '</strong> : ' . esc_html( $label ) . '</span>'; } echo '</div>'; }
        echo '<div class="ptm-email-purpose-suggestions"><strong>Exemples de finalités adaptés à ce qui est détecté :</strong><div class="ptm-template-buttons">';
        echo '<button type="button" class="button ptm-email-purpose" data-target="email_pixel_purposes" data-value="Mesurer la délivrabilité technique des e-mails lorsque ce suivi est réellement utilisé">Délivrabilité</button>';
        if ( 'yes' === $profile['email_pixels'] ) { echo '<button type="button" class="button ptm-email-purpose is-detected" data-target="email_pixel_purposes" data-value="Mesurer les ouvertures des e-mails afin d’évaluer l’engagement des destinataires">Mesurer les ouvertures <small>suivi détecté</small></button>'; }
        if ( 'yes' === $profile['tracked_links'] ) { echo '<button type="button" class="button ptm-email-purpose is-detected" data-target="tracked_links_details" data-value="Mesurer les clics sur les liens des e-mails et les associer au destinataire lorsque l’outil utilise des liens individualisés">Mesurer les clics <small>suivi détecté</small></button>'; }
        echo '<button type="button" class="button ptm-email-purpose" data-target="email_pixel_purposes" data-value="Gérer les préférences de communication et conserver la preuve des choix exprimés">Gérer les préférences</button></div></div>';
        echo '<div class="ptm-form-grid">';
        $this->legal_select( 'email_pixels', 'Le suivi des ouvertures est-il activé ?', $profile['email_pixels'], array( 'unknown'=>'À vérifier','no'=>'Non / désactivés','yes'=>'Oui / actifs' ) ); $this->legal_textarea( 'email_pixel_purposes', 'Pourquoi ce suivi des ouvertures est-il utilisé ?', $profile['email_pixel_purposes'] ); $this->legal_select( 'email_pixel_status', 'Dans quel cadre ce suivi est-il utilisé ?', $profile['email_pixel_status'], array( 'unknown'=>'À vérifier','consent'=>'Consentement lorsque requis','exempt_delivery'=>'Uniquement pour assurer la bonne réception des e-mails' ) ); $this->legal_input( 'email_pixel_preferences', 'Où la personne peut-elle gérer ce choix ?', $profile['email_pixel_preferences'] );
        $this->legal_select( 'tracked_links', 'Le suivi individuel des clics est-il activé ?', $profile['tracked_links'], array( 'unknown'=>'À vérifier','no'=>'Non / désactivé','yes'=>'Oui / actif' ) ); $this->legal_textarea( 'tracked_links_details', 'Pourquoi et comment les clics sont-ils suivis ?', $profile['tracked_links_details'] ); echo '</div></div>'; $this->legal_section_form_end();

        $this->legal_section_form_start( 'authority' ); echo '<div class="ptm-wizard-section"><div class="ptm-question-kicker">Étape 8</div><h3>Où une personne peut-elle déposer une réclamation ?</h3><p class="ptm-question-intro">Pour une structure française, l’autorité de contrôle est généralement la CNIL. Vérifiez le cas de votre organisation si elle relève d’un autre pays.</p><div class="ptm-form-grid">'; $this->legal_input( 'supervisory_authority', 'Autorité compétente', $profile['supervisory_authority'] ); $this->legal_url_input( 'supervisory_url', 'Lien de réclamation', $profile['supervisory_url'] ); echo '</div></div>'; $this->legal_section_form_end();

        echo '<div class="ptm-wizard-preview" data-ptm-wizard-panel="preview"><div class="ptm-question-kicker">Étape 9</div><h3>Relisez et enregistrez votre brouillon</h3><p class="ptm-question-intro">L’aperçu se met à jour à partir des données enregistrées sans relancer le scan ni recharger toute la page.</p>';
        echo '<div class="ptm-preview-content" data-ptm-preview-content>' . wp_kses_post( $this->legal_preview_html( $profile ) ) . '</div>';
        echo '<div class="ptm-preview-actions-top ptm-final-actions">';
        $this->form_start('finish_legal_draft_pages'); submit_button('Enregistrer le brouillon et gérer les pages','primary','submit',false); $this->form_end();
        $this->form_start('finish_legal_draft_home'); submit_button('Enregistrer et revenir au tableau de bord','secondary','submit',false); $this->form_end();
        echo '<button type="button" class="button" data-ptm-go-step="identity">Revenir modifier une section</button></div>';
        if ( ! empty($readiness['errors']) ) { echo '<div class="ptm-callout neutral"><strong>Brouillon incomplet, mais enregistré.</strong> Les points restant à vérifier continueront d’apparaître dans « Actions à exécuter » et ne bloquent pas la suite.</div>'; }
        echo '</div></section>';
    }

    private function legal_section_form_start( $section ) {
        echo '<form method="post" class="ptm-section-form" data-ptm-section="' . esc_attr( $section ) . '" data-ptm-wizard-panel="' . esc_attr( $section ) . '">';
        wp_nonce_field( 'pixel_trackers_manager_admin_action', 'pixel_trackers_manager_nonce' );
        echo '<input type="hidden" name="pixel_trackers_manager_action" value="save_legal_profile"><input type="hidden" name="legal_section" value="' . esc_attr( $section ) . '"><input type="hidden" name="ptm_next_step" value="" data-ptm-next-step-input><div class="ptm-section-save-status" aria-live="polite"></div>';
    }

    private function legal_section_form_end() {
        submit_button( 'Enregistrer ce bloc', 'secondary ptm-section-save' );
        echo '</form>';
    }

    private function legal_input( $name, $label, $value, $required = false, $help = '' ) {
        echo '<label class="ptm-field"><span>' . esc_html( $label ) . ( $required ? ' <strong class="ptm-required">*</strong>' : '' ) . '</span><input type="text" name="legal_profile[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '">';
        if ( $help ) { echo '<small>' . esc_html( $help ) . '</small>'; }
        echo '</label>';
    }

    private function legal_host_input( $value ) {
        $providers = $this->known_hosting_providers();
        echo '<div class="ptm-field ptm-host-field"><label for="ptm-host-name"><span>Hébergeur</span></label>';
        echo '<div class="ptm-host-row"><input id="ptm-host-name" type="text" list="ptm-known-hosts" name="legal_profile[host_name]" value="' . esc_attr( $value ) . '" autocomplete="organization">';
        echo '<button type="button" class="button button-secondary" id="ptm-host-detect">Détecter automatiquement</button></div>';
        echo '<datalist id="ptm-known-hosts">';
        foreach ( $providers as $provider ) { echo '<option value="' . esc_attr( $provider['name'] ) . '"></option>'; }
        echo '</datalist><small>Dendrila Privacy utilise des informations réseau et serveur (par exemple le domaine et les serveurs qui le desservent) pour proposer l’hébergeur. Pour les fournisseurs documentés dans sa base, le même bouton préremplit aussi la raison sociale utile, l’adresse et le téléphone disponible. Vérifiez toujours la source officielle avant publication.</small><div id="ptm-host-results" class="ptm-host-results" aria-live="polite"></div></div>';
    }

    private function legal_url_input( $name, $label, $value ) {
        echo '<label class="ptm-field"><span>' . esc_html( $label ) . '</span><input type="url" name="legal_profile[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"></label>';
    }

    private function legal_textarea( $name, $label, $value, $help = '' ) {
        echo '<label class="ptm-field"><span>' . esc_html( $label ) . '</span><textarea rows="3" name="legal_profile[' . esc_attr( $name ) . ']">' . esc_textarea( $value ) . '</textarea>';
        if ( $help ) { echo '<small>' . esc_html( $help ) . '</small>'; }
        echo '</label>';
    }

    private function legal_select( $name, $label, $value, $options ) {
        echo '<label class="ptm-field"><span>' . esc_html( $label ) . '</span><select name="legal_profile[' . esc_attr( $name ) . ']">';
        foreach ( $options as $key => $option_label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $option_label ) . '</option>'; }
        echo '</select></label>';
    }

    private function indirect_source_service_options( $scan ) {
        $presets = array(
            'google-analytics' => array(
                'categories' => 'Données de navigation et informations techniques : pages consultées, événements, caractéristiques du navigateur/appareil et adresse IP ou donnée dérivée selon la configuration.',
                'source' => 'Données générées lors de la navigation sur le site et collectées via Google Analytics.',
            ),
            'google-tag-manager' => array(
                'categories' => 'Données liées aux balises déclenchées via le gestionnaire : navigation, événements et informations techniques selon les services configurés dans le conteneur.',
                'source' => 'Données générées lors de la navigation et transmises aux services déclenchés par Google Tag Manager. Vérifiez les balises réellement actives dans le conteneur.',
            ),
            'meta-pixel' => array(
                'categories' => 'Données de navigation et d’interaction : pages vues, événements, identifiants en ligne et informations techniques selon la configuration du pixel.',
                'source' => 'Données générées lors de la navigation sur le site et collectées via Meta Pixel.',
            ),
            'clarity' => array(
                'categories' => 'Données de navigation et d’interaction : pages consultées, clics, défilements et informations techniques selon la configuration du service.',
                'source' => 'Données générées lors de l’utilisation du site et collectées via Microsoft Clarity.',
            ),
            'hotjar' => array(
                'categories' => 'Données de navigation et d’interaction : pages consultées, clics, défilements et informations techniques selon la configuration du service.',
                'source' => 'Données générées lors de l’utilisation du site et collectées via Hotjar.',
            ),
            'tiktok-pixel' => array(
                'categories' => 'Données de navigation et d’interaction, événements et identifiants en ligne selon la configuration du pixel.',
                'source' => 'Données générées lors de la navigation sur le site et collectées via TikTok Pixel.',
            ),
            'linkedin-insight' => array(
                'categories' => 'Données de navigation et d’interaction, événements et identifiants en ligne selon la configuration de la balise.',
                'source' => 'Données générées lors de la navigation sur le site et collectées via LinkedIn Insight Tag.',
            ),
            'pinterest-tag' => array(
                'categories' => 'Données de navigation et d’interaction, événements et identifiants en ligne selon la configuration de la balise.',
                'source' => 'Données générées lors de la navigation sur le site et collectées via Pinterest Tag.',
            ),
            'youtube' => array(
                'categories' => 'Données techniques et d’usage liées au chargement ou à l’utilisation du lecteur vidéo, selon le mode d’intégration et le consentement appliqué.',
                'source' => 'Données générées lors du chargement ou de l’utilisation d’une vidéo YouTube intégrée au site.',
            ),
            'vimeo' => array(
                'categories' => 'Données techniques et d’usage liées au chargement ou à l’utilisation du lecteur vidéo, selon le mode d’intégration et le consentement appliqué.',
                'source' => 'Données générées lors du chargement ou de l’utilisation d’une vidéo Vimeo intégrée au site.',
            ),
            'google-maps' => array(
                'categories' => 'Données techniques et d’usage liées au chargement ou à l’utilisation de la carte, selon son mode d’intégration.',
                'source' => 'Données générées lors du chargement ou de l’utilisation d’une carte Google Maps intégrée au site.',
            ),
            'recaptcha' => array(
                'categories' => 'Informations techniques, signaux de navigation et éléments nécessaires à l’évaluation anti-spam selon la version de reCAPTCHA utilisée.',
                'source' => 'Données générées lors de l’utilisation du formulaire et analysées par Google reCAPTCHA pour détecter les comportements automatisés.',
            ),
            'matomo' => array(
                'categories' => 'Données de navigation et informations techniques : pages consultées, événements, caractéristiques de l’appareil et adresse IP ou donnée dérivée selon la configuration.',
                'source' => 'Données générées lors de la navigation sur le site et collectées via Matomo.',
            ),
            'plausible' => array(
                'categories' => 'Données agrégées de navigation et informations techniques selon la configuration de Plausible Analytics.',
                'source' => 'Données générées lors de la navigation sur le site et traitées via Plausible Analytics.',
            ),
            'mailpoet' => array(
                'categories' => 'Données d’interaction avec les e-mails ou le site selon les fonctions de suivi réellement activées dans MailPoet.',
                'source' => 'Données générées lors de l’interaction avec les newsletters ou le site et collectées via MailPoet lorsque son suivi est actif.',
            ),
            'brevo' => array(
                'categories' => 'Données de navigation, événements et identifiants en ligne selon les fonctions de suivi Brevo réellement activées.',
                'source' => 'Données générées lors de la navigation sur le site et collectées via Brevo Tracker.',
            ),
        );
        $options = array();
        foreach ( isset( $scan['findings'] ) && is_array( $scan['findings'] ) ? $scan['findings'] : array() as $finding ) {
            $state = isset( $finding['tracking_state'] ) ? (string) $finding['tracking_state'] : 'potential';
            if ( 'disabled' === $state ) { continue; }
            $id = isset( $finding['id'] ) ? sanitize_key( $finding['id'] ) : '';
            if ( '' === $id ) { continue; }
            $label = isset( $finding['label'] ) ? (string) $finding['label'] : $id;
            $preset = isset( $presets[ $id ] ) ? $presets[ $id ] : array(
                'categories' => 'Données techniques et d’usage liées à ce service, à préciser selon sa configuration réelle.',
                'source' => 'Données générées lors de l’utilisation du site et susceptibles d’être traitées via ' . $label . '. Vérifiez la configuration réelle du service.',
            );
            $options[] = array(
                'id' => $id,
                'label' => $label,
                'state' => 'active' === $state || ! empty( $finding['active_tracking'] ) ? 'active' : 'potential',
                'categories' => $preset['categories'],
                'source' => $preset['source'],
                'recipients' => $label . ' et les personnes habilitées à administrer le site, selon la configuration réelle.',
                'transfer_status' => in_array( $id, array( 'matomo','plausible' ), true ) ? 'unknown' : 'unknown',
                'transfer_destinations' => 'À vérifier dans les conditions et la configuration de ' . $label . '.',
                'transfer_mechanism' => 'À vérifier selon le pays de traitement, le contrat et la configuration du service.',
                'transfer_safeguards' => 'Consultez la documentation de confidentialité et le contrat de traitement des données du fournisseur.',
                'automated_decision' => 'no',
                'automated_details' => '',
            );
        }
        return $options;
    }

    private function render_indirect_service_row( $i, $row ) {
        $row = wp_parse_args( is_array( $row ) ? $row : array(), array( 'service_id'=>'','label'=>'','state'=>'manual','categories'=>'','source'=>'','recipients'=>'','transfer_status'=>'unknown','transfer_destinations'=>'','transfer_mechanism'=>'','transfer_safeguards'=>'','automated_decision'=>'no','automated_details'=>'' ) );
        $prefix = 'legal_profile[indirect_services][' . (int) $i . ']';
        echo '<details class="ptm-indirect-service-card" data-ptm-service-index="' . esc_attr( $i ) . '" open><summary><strong class="ptm-indirect-service-title">' . esc_html( $row['label'] ? $row['label'] : 'Service à compléter' ) . '</strong><span class="ptm-badge ' . ( 'active' === $row['state'] ? 'bad' : ( 'potential' === $row['state'] ? 'warn' : 'neutral' ) ) . '">' . esc_html( 'active' === $row['state'] ? 'actif' : ( 'potential' === $row['state'] ? 'à vérifier' : 'manuel' ) ) . '</span></summary><div class="ptm-form-grid">';
        echo '<input type="hidden" name="' . esc_attr( $prefix . '[service_id]' ) . '" value="' . esc_attr( $row['service_id'] ) . '"><input type="hidden" name="' . esc_attr( $prefix . '[state]' ) . '" value="' . esc_attr( $row['state'] ) . '">';
        echo '<label class="ptm-field"><span>Service</span><input type="text" name="' . esc_attr( $prefix . '[label]' ) . '" value="' . esc_attr( $row['label'] ) . '"></label>';
        echo '<label class="ptm-field"><span>Quelles données ce service reçoit ou produit-il ?</span><textarea rows="3" name="' . esc_attr( $prefix . '[categories]' ) . '">' . esc_textarea( $row['categories'] ) . '</textarea></label>';
        echo '<label class="ptm-field"><span>D’où viennent ces données ?</span><textarea rows="3" name="' . esc_attr( $prefix . '[source]' ) . '">' . esc_textarea( $row['source'] ) . '</textarea></label>';
        echo '<label class="ptm-field"><span>Qui reçoit ou peut accéder à ces données ?</span><textarea rows="3" name="' . esc_attr( $prefix . '[recipients]' ) . '">' . esc_textarea( $row['recipients'] ) . '</textarea></label>';
        echo '<label class="ptm-field"><span>Des données peuvent-elles partir hors UE/EEE ?</span><select name="' . esc_attr( $prefix . '[transfer_status]' ) . '">'; foreach ( array( 'unknown'=>'À vérifier','no'=>'Non, d’après les informations disponibles','yes'=>'Oui / potentiellement' ) as $key=>$label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['transfer_status'], $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label>';
        echo '<label class="ptm-field"><span>Pays / organisation concernée</span><textarea rows="2" name="' . esc_attr( $prefix . '[transfer_destinations]' ) . '">' . esc_textarea( $row['transfer_destinations'] ) . '</textarea></label>';
        echo '<label class="ptm-field"><span>Encadrement du transfert</span><textarea rows="2" name="' . esc_attr( $prefix . '[transfer_mechanism]' ) . '">' . esc_textarea( $row['transfer_mechanism'] ) . '</textarea><small>Dendrila Privacy préremplit seulement ce qu’il peut raisonnablement déduire ; « À vérifier » est préférable à une information juridique inventée.</small></label>';
        echo '<label class="ptm-field"><span>Informations / garanties à consulter</span><textarea rows="2" name="' . esc_attr( $prefix . '[transfer_safeguards]' ) . '">' . esc_textarea( $row['transfer_safeguards'] ) . '</textarea></label>';
        echo '<label class="ptm-field"><span>Ce service prend-il automatiquement une décision importante sur une personne ?</span><select name="' . esc_attr( $prefix . '[automated_decision]' ) . '">'; foreach ( array( 'unknown'=>'À vérifier','no'=>'Non, rien de ce type','yes'=>'Oui' ) as $key=>$label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['automated_decision'], $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label>';
        echo '<label class="ptm-field"><span>Précision sur la décision automatisée</span><textarea rows="2" name="' . esc_attr( $prefix . '[automated_details]' ) . '">' . esc_textarea( $row['automated_details'] ) . '</textarea></label>';
        echo '</div><div class="ptm-indirect-service-actions"><button type="button" class="button button-primary ptm-save-indirect-service">Enregistrer ce service</button><button type="button" class="button-link-delete ptm-remove-indirect-service">Supprimer cette fiche</button></div></details>';
    }

    private function treatment_template_recommendations( $scan ) {
        $plugins = isset( $scan['plugins'] ) && is_array( $scan['plugins'] ) ? $scan['plugins'] : array();
        $plugin_haystack = '';
        foreach ( $plugins as $plugin ) {
            if ( empty( $plugin['active'] ) ) { continue; }
            $plugin_haystack .= ' ' . strtolower( ( isset( $plugin['slug'] ) ? $plugin['slug'] : '' ) . ' ' . ( isset( $plugin['name'] ) ? $plugin['name'] : '' ) );
        }
        $active_finding_ids = array();
        foreach ( isset( $scan['findings'] ) && is_array( $scan['findings'] ) ? $scan['findings'] : array() as $finding ) {
            $state = isset( $finding['tracking_state'] ) ? $finding['tracking_state'] : '';
            if ( 'active' === $state && ! empty( $finding['id'] ) ) { $active_finding_ids[] = $finding['id']; }
        }
        $contact_detected = (bool) preg_match( '/contact-form|wpforms|gravity|ninja-forms|formidable|fluentform|elementor/', $plugin_haystack );
        $shop_detected = (bool) preg_match( '/woocommerce|easy-digital-downloads|surecart/', $plugin_haystack );
        $analytics_detected = (bool) array_intersect( $active_finding_ids, array( 'google-analytics','matomo','plausible','clarity','hotjar' ) );
        $mailing_active = false;
        foreach ( isset( $scan['mailing'] ) && is_array( $scan['mailing'] ) ? $scan['mailing'] : array() as $tool ) {
            if ( ! empty( $tool['plugin']['active'] ) || ! empty( $tool['name'] ) ) { $mailing_active = true; break; }
        }
        $accounts_detected = (bool) get_option( 'users_can_register' ) || $shop_detected || (bool) preg_match( '/member|membership|learndash|lifterlms|buddypress/', $plugin_haystack );

        return array(
            array( 'id'=>'contact', 'label'=>'Formulaire de contact', 'detected'=>$contact_detected, 'badge'=>$contact_detected ? 'outil actif sur ce site' : '' ),
            array( 'id'=>'newsletter', 'label'=>'Newsletter / e-mailing', 'detected'=>$mailing_active, 'badge'=>$mailing_active ? 'outil e-mail actif' : '' ),
            array( 'id'=>'accounts', 'label'=>'Comptes utilisateurs', 'detected'=>$accounts_detected, 'badge'=>$accounts_detected ? 'fonction active' : '' ),
            array( 'id'=>'orders', 'label'=>'Commandes / facturation', 'detected'=>$shop_detected, 'badge'=>$shop_detected ? 'fonction active' : '' ),
            array( 'id'=>'analytics', 'label'=>'Mesure d’audience', 'detected'=>$analytics_detected, 'badge'=>$analytics_detected ? 'suivi actif détecté' : '' ),
        );
    }

    private function render_treatment_row( $i, $row ) {
        $row = wp_parse_args( $row, array( 'purpose'=>'','data_categories'=>'','legal_basis'=>'unknown','basis_detail'=>'','recipients'=>'','retention'=>'','mandatory'=>'unknown','consequences'=>'' ) );
        $prefix = 'legal_profile[treatments][' . (int) $i . ']';
        $has_content = '' !== trim( (string) $row['purpose'] );
        $title = $has_content ? $row['purpose'] : 'Nouveau traitement à compléter';
        echo '<details class="ptm-treatment-row" data-ptm-treatment-index="' . esc_attr( $i ) . '" ' . ( 0 === (int) $i || $has_content ? 'open' : '' ) . '><summary><span class="ptm-treatment-summary-title">' . esc_html( $title ) . '</span><span class="ptm-treatment-summary-state">' . ( $has_content ? 'Renseigné' : 'Vide' ) . '</span></summary><div class="ptm-treatment-fields ptm-form-grid">';
        echo '<label class="ptm-field"><span>Pourquoi utilisez-vous ces données ? <small>Finalité</small></span><input type="text" data-ptm-treatment-field="purpose" name="' . esc_attr( $prefix . '[purpose]' ) . '" value="' . esc_attr( $row['purpose'] ) . '" placeholder="Ex. Répondre aux demandes de contact"><small>Décrivez l’objectif concret, pas le nom du plugin utilisé.</small></label>';
        echo '<label class="ptm-field"><span>Quelles informations sont concernées ? <small>Catégories de données</small></span><textarea rows="2" data-ptm-treatment-field="data_categories" name="' . esc_attr( $prefix . '[data_categories]' ) . '" placeholder="Ex. nom, adresse e-mail, téléphone, contenu du message">' . esc_textarea( $row['data_categories'] ) . '</textarea><small>Indiquez des catégories, jamais les données réelles d’une personne.</small></label>';
        echo '<label class="ptm-field"><span>Sur quoi repose ce traitement ? <small>Base juridique</small></span><select data-ptm-treatment-field="legal_basis" name="' . esc_attr( $prefix . '[legal_basis]' ) . '">';
        foreach ( array( 'unknown'=>'Je ne sais pas encore / à vérifier','consent'=>'Consentement','contract'=>'Contrat / mesures précontractuelles','legal_obligation'=>'Obligation légale','vital_interests'=>'Intérêts vitaux','public_task'=>'Mission d’intérêt public','legitimate_interest'=>'Intérêt légitime' ) as $key=>$label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['legal_basis'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select><small>Dendrila Privacy peut proposer un point de départ, mais ne choisit pas cette réponse à votre place.</small></label>';
        echo '<label class="ptm-field"><span>Précision utile sur cette base</span><textarea rows="2" data-ptm-treatment-field="basis_detail" name="' . esc_attr( $prefix . '[basis_detail]' ) . '" placeholder="Ex. répondre à une demande de devis / obligation comptable applicable">' . esc_textarea( $row['basis_detail'] ) . '</textarea><small>Particulièrement utile pour une obligation légale ou un intérêt légitime.</small></label>';
        echo '<label class="ptm-field"><span>Qui peut accéder aux données ? <small>Destinataires</small></span><textarea rows="2" data-ptm-treatment-field="recipients" name="' . esc_attr( $prefix . '[recipients]' ) . '" placeholder="Ex. personnes chargées des demandes, hébergeur, prestataire e-mail">' . esc_textarea( $row['recipients'] ) . '</textarea><small>Incluez les catégories de personnes internes et les prestataires concernés.</small></label>';
        echo '<label class="ptm-field"><span>Combien de temps les conservez-vous ? <small>Durée</small></span><textarea rows="2" data-ptm-treatment-field="retention" name="' . esc_attr( $prefix . '[retention]' ) . '" placeholder="Ex. le temps de traiter la demande puis suppression ou archivage limité">' . esc_textarea( $row['retention'] ) . '</textarea><small>Indiquez une durée ou le critère qui déclenche la suppression.</small></label>';
        echo '<label class="ptm-field"><span>La personne doit-elle fournir ces données ?</span><select data-ptm-treatment-field="mandatory" name="' . esc_attr( $prefix . '[mandatory]' ) . '">';
        foreach ( array( 'optional'=>'Non, c’est facultatif','contract'=>'Oui, pour répondre à la demande / exécuter le contrat','law'=>'Oui, la loi l’impose','unknown'=>'Je ne sais pas encore' ) as $key=>$label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['mandatory'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label>';
        echo '<label class="ptm-field"><span>Que se passe-t-il si elles ne sont pas fournies ?</span><textarea rows="2" data-ptm-treatment-field="consequences" name="' . esc_attr( $prefix . '[consequences]' ) . '" placeholder="Ex. impossible de répondre à la demande ou d’exécuter la commande">' . esc_textarea( $row['consequences'] ) . '</textarea><small>Laissez vide si aucune conséquence particulière n’existe.</small></label>';
        echo '</div></details>';
    }

    public function cleanup_deleted_page( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }
        $overlays = $this->page_overlays();
        if ( isset( $overlays[ $post_id ] ) ) {
            unset( $overlays[ $post_id ] );
            update_option( self::OPTION_PAGE_OVERLAYS, $overlays, false );
        }
    }

    private function page_builder_info( $page_id ) {
        $page_id = absint( $page_id );
        $content = (string) get_post_field( 'post_content', $page_id );

        $elementor_mode = get_post_meta( $page_id, '_elementor_edit_mode', true );
        $elementor_data = get_post_meta( $page_id, '_elementor_data', true );
        if ( 'builder' === $elementor_mode || ! empty( $elementor_data ) ) {
            return array(
                'id' => 'elementor',
                'label' => 'Elementor',
                'safe_mode' => true,
                'note' => 'Cette page utilise Elementor. Dendrila Privacy peut lire son contenu localement et n’ajoute le code court qu’après votre clic explicite.',
            );
        }

        $divi_enabled = 'on' === get_post_meta( $page_id, '_et_pb_use_builder', true );
        $divi5_blocks = false !== strpos( $content, '<!-- wp:divi/' );
        $divi4_shortcodes = false !== strpos( $content, '[et_pb_' );

        // Builder detection is page-specific. Merely having Divi installed or active
        // must not make a normal WordPress page appear to be a Divi page.
        if ( $divi_enabled || $divi5_blocks || $divi4_shortcodes ) {
            $is_divi5 = $divi5_blocks;
            return array(
                'id' => $is_divi5 ? 'divi5' : 'divi',
                'label' => $is_divi5 ? 'Divi 5' : 'Divi',
                'safe_mode' => true,
                'note' => 'Cette page utilise Divi. Dendrila Privacy peut lire son contenu localement et n’ajoute le code court qu’après votre clic explicite.',
            );
        }

        // Other builders keep their own page structure in post meta, generated shortcodes,
        // or a dedicated data tree. We identify common signatures only to protect that
        // structure: the public banner and PTM shortcodes still work, but PTM will not
        // rewrite the page automatically unless a dedicated adapter exists.
        $other_builders = array(
            array( 'id'=>'bricks', 'label'=>'Bricks', 'detected'=>! empty( get_post_meta( $page_id, '_bricks_page_content_2', true ) ) ),
            array( 'id'=>'beaver-builder', 'label'=>'Beaver Builder', 'detected'=>! empty( get_post_meta( $page_id, '_fl_builder_enabled', true ) ) || ! empty( get_post_meta( $page_id, '_fl_builder_data', true ) ) ),
            array( 'id'=>'wpbakery', 'label'=>'WPBakery', 'detected'=>'true' === (string) get_post_meta( $page_id, '_wpb_vc_js_status', true ) || false !== strpos( $content, '[vc_row' ) ),
            array( 'id'=>'oxygen', 'label'=>'Oxygen', 'detected'=>! empty( get_post_meta( $page_id, '_oxygen_data', true ) ) || ! empty( get_post_meta( $page_id, '_ct_builder_shortcodes', true ) ) || ! empty( get_post_meta( $page_id, 'ct_builder_json', true ) ) ),
            array( 'id'=>'breakdance', 'label'=>'Breakdance', 'detected'=>! empty( get_post_meta( $page_id, '_breakdance_data', true ) ) ),
            array( 'id'=>'siteorigin', 'label'=>'SiteOrigin Page Builder', 'detected'=>! empty( get_post_meta( $page_id, 'panels_data', true ) ) ),
            array( 'id'=>'avada', 'label'=>'Avada Builder', 'detected'=>false !== strpos( $content, '[fusion_builder_container' ) || false !== strpos( $content, '[fusion_builder_row' ) ),
        );
        foreach ( $other_builders as $other_builder ) {
            if ( ! empty( $other_builder['detected'] ) ) {
                return array(
                    'id' => $other_builder['id'],
                    'label' => $other_builder['label'],
                    'safe_mode' => true,
                    'note' => 'Constructeur détecté. La bannière et les codes courts fonctionnent normalement, mais Dendrila Privacy ne réécrit pas sa structure interne : insérez le code court depuis le constructeur.',
                );
            }
        }

        // Brizy exposes a page-level editor check rather than relying on post_content.
        if ( class_exists( 'Brizy_Editor_Post' ) && method_exists( 'Brizy_Editor_Post', 'get' ) ) {
            try {
                $brizy_post = Brizy_Editor_Post::get( $page_id );
                if ( is_object( $brizy_post ) && method_exists( $brizy_post, 'uses_editor' ) && $brizy_post->uses_editor() ) {
                    return array(
                        'id' => 'brizy',
                        'label' => 'Brizy',
                        'safe_mode' => true,
                        'note' => 'Constructeur détecté. La bannière et les codes courts fonctionnent normalement, mais Dendrila Privacy ne réécrit pas sa structure interne : insérez le code court depuis Brizy.',
                    );
                }
            } catch ( Throwable $e ) {
                // Detection failure must never block the page-management screen.
            }
        }

        return array(
            'id' => 'wordpress',
            'label' => 'Éditeur WordPress',
            'safe_mode' => false,
            'note' => 'Dendrila Privacy peut mettre à jour uniquement ses blocs balisés dans le contenu de cette page.',
        );
    }

    private function page_overlays() {
        $overlays = get_option( self::OPTION_PAGE_OVERLAYS, array() );
        return is_array( $overlays ) ? $overlays : array();
    }

    private function set_page_overlay( $page_id, $kind, $html ) {
        $page_id = absint( $page_id );
        if ( ! $page_id || ! in_array( $kind, array( 'legal', 'services' ), true ) ) {
            return;
        }
        $overlays = $this->page_overlays();
        if ( empty( $overlays[ $page_id ] ) || ! is_array( $overlays[ $page_id ] ) ) {
            $overlays[ $page_id ] = array();
        }
        $overlays[ $page_id ][ $kind ] = array(
            'html' => (string) $html,
            'synced_at' => gmdate( 'c' ),
            'plugin_version' => self::VERSION,
        );
        update_option( self::OPTION_PAGE_OVERLAYS, $overlays, false );
    }

    private function remove_page_overlays( $page_id ) {
        $page_id = absint( $page_id );
        $overlays = $this->page_overlays();
        if ( isset( $overlays[ $page_id ] ) ) {
            unset( $overlays[ $page_id ] );
            update_option( self::OPTION_PAGE_OVERLAYS, $overlays, false );
        }
    }

    private function remove_managed_blocks_from_content( $content ) {
        $patterns = array(
            '/' . preg_quote( self::LEGAL_BLOCK_START, '/' ) . '.*?' . preg_quote( self::LEGAL_BLOCK_END, '/' ) . '/is',
            '/' . preg_quote( self::BLOCK_START, '/' ) . '.*?' . preg_quote( self::BLOCK_END, '/' ) . '/is',
        );
        $content = preg_replace( $patterns, '', (string) $content );
        return trim( preg_replace( "/\\n{3,}/", "\n\n", (string) $content ) );
    }

    public function inject_builder_managed_blocks( $content ) {
        if ( is_admin() || is_feed() || ! is_singular( 'page' ) ) {
            return $content;
        }

        $page_id = absint( get_queried_object_id() );
        if ( ! $page_id ) {
            $page_id = absint( get_the_ID() );
        }
        if ( ! $page_id ) {
            return $content;
        }
        $current_post_id = absint( get_the_ID() );
        if ( $current_post_id && $current_post_id !== $page_id ) {
            return $content;
        }

        $builder = $this->page_builder_info( $page_id );
        if ( empty( $builder['safe_mode'] ) ) {
            return $content;
        }

        $overlays = $this->page_overlays();
        if ( empty( $overlays[ $page_id ] ) || ! is_array( $overlays[ $page_id ] ) ) {
            return $content;
        }

        $append = '';
        if ( ! empty( $overlays[ $page_id ]['legal']['html'] ) && false === strpos( $content, self::LEGAL_BLOCK_START ) && false === strpos( $content, 'ptm-privacy-information' ) ) {
            $append .= "\n" . wp_kses_post( $overlays[ $page_id ]['legal']['html'] );
        }
        if ( ! empty( $overlays[ $page_id ]['services']['html'] ) && false === strpos( $content, self::BLOCK_START ) && false === strpos( $content, 'ptm-services-disclosure' ) ) {
            $append .= "\n" . wp_kses_post( $overlays[ $page_id ]['services']['html'] );
        }

        if ( '' === $append ) {
            return $content;
        }
        return $content . '<div class="pixel-trackers-manager-managed-output" data-pixel-trackers-manager-builder="' . esc_attr( $builder['id'] ) . '">' . $append . '</div>';
    }

    private function generated_services_block() {
        $scan=get_option(self::OPTION_SCAN,array()); $items=array();
        foreach((array)(isset($scan['findings'])?$scan['findings']:array()) as $finding){
            if(empty($finding['active_tracking'])){continue;}
            $items[]='<li><strong>'.esc_html($finding['label']).'</strong> — '.esc_html($finding['category']).'.</li>';
        }
        $html=self::BLOCK_START."\n";
        $html.='<section class="ptm-services-disclosure"><h2>Services et traceurs utilisés</h2>';
        $html.='<p>Les services ci-dessous peuvent être utilisés lors de votre navigation sur le site.</p>';
        $html.=$items?'<ul>'.implode('', $items).'</ul>':'';
        $html.='</section>'."\n".self::BLOCK_END;
        return $html;
    }

    public function sync_privacy_page( $page_id, $origin = 'manual' ) {
        $page_id = absint( $page_id );
        $post = get_post( $page_id );
        if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
            return new WP_Error( 'pixel_trackers_manager_bad_page', 'Page de confidentialité invalide ou non publiée.' );
        }
        if ( 'cron' !== $origin && ! current_user_can( 'edit_post', $page_id ) ) {
            return new WP_Error( 'pixel_trackers_manager_forbidden', 'Vous n’avez pas le droit de modifier cette page.' );
        }

        $block = $this->generated_services_block();
        $builder = $this->page_builder_info( $page_id );
        if ( ! empty( $builder['safe_mode'] ) ) {
            $this->set_page_overlay( $page_id, 'services', $block );
            $this->log_action( 'sync_page', $origin . '-builder-safe', array( 'page_id' => $page_id, 'builder' => $builder['id'] ) );
            $this->audit_privacy_page( $page_id );
            return true;
        }

        $content = (string) $post->post_content;
        $pattern = '/' . preg_quote( self::BLOCK_START, '/' ) . '.*?' . preg_quote( self::BLOCK_END, '/' ) . '/is';
        if ( preg_match( $pattern, $content ) ) {
            $new_content = preg_replace( $pattern, $block, $content, 1 );
        } else {
            $new_content = rtrim( $content ) . "\n\n" . $block;
        }
        if ( $new_content === $content ) {
            return true;
        }
        $updated = wp_update_post( array( 'ID' => $page_id, 'post_content' => $new_content ), true );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }
        $this->log_action( 'sync_page', $origin, array( 'page_id' => $page_id, 'builder' => 'wordpress' ) );
        $this->audit_privacy_page( $page_id );
        return true;
    }

    public function restore_privacy_page( $page_id ) {
        $page_id = absint( $page_id );
        $post = get_post( $page_id );
        if ( ! $post || 'page' !== $post->post_type ) {
            return new WP_Error( 'pixel_trackers_manager_bad_page', 'Page de confidentialité invalide.' );
        }
        if ( ! current_user_can( 'edit_post', $page_id ) ) {
            return new WP_Error( 'pixel_trackers_manager_forbidden', 'Vous n’avez pas le droit de modifier cette page.' );
        }

        $this->remove_page_overlays( $page_id );
        $content = (string) $post->post_content;
        $new_content = $this->remove_managed_blocks_from_content( $content );
        if ( $new_content !== trim( $content ) ) {
            $updated = wp_update_post( array( 'ID' => $page_id, 'post_content' => $new_content ), true );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        }
        $this->log_action( 'remove_managed_blocks', 'manual', array( 'page_id' => $page_id ) );
        $this->audit_privacy_page( $page_id );
        return true;
    }

    private function log_action( $action, $origin, $details = array() ) {
        $log = get_option( self::OPTION_AUDIT_LOG, array() );
        array_unshift( $log, array(
            'time' => gmdate( 'c' ),
            'action' => $action,
            'origin' => $origin,
            'user_id' => get_current_user_id(),
            'details' => $details,
        ) );
        update_option( self::OPTION_AUDIT_LOG, array_slice( $log, 0, 100 ), false );
    }

    public function shortcode_services() {
        $page_id = absint( get_the_ID() );
        $overlays = $this->page_overlays();
        if ( $page_id && ! empty( $overlays[ $page_id ]['services']['html'] ) ) {
            return wp_kses_post( $overlays[ $page_id ]['services']['html'] );
        }
        return wp_kses_post( $this->generated_services_block() );
    }

    public function shortcode_legal() {
        $page_id = absint( get_the_ID() );
        $overlays = $this->page_overlays();
        if ( $page_id && ! empty( $overlays[ $page_id ]['legal']['html'] ) ) { return wp_kses_post( $overlays[ $page_id ]['legal']['html'] ); }
        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        return wp_kses_post( $this->public_document_html( 'privacy', $profile ) );
    }

    public function shortcode_privacy() { return $this->shortcode_legal(); }

    public function shortcode_legal_notice() {
        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        return wp_kses_post( $this->public_document_html( 'legal_notice', $profile ) );
    }

    public function shortcode_cookie_policy() {
        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );
        return wp_kses_post( $this->public_document_html( 'cookies', $profile ) );
    }

    public function shortcode_legal_bundle() {
        return '<div class="ptm-legal-bundle">' . $this->shortcode_legal_notice() . $this->shortcode_privacy() . $this->shortcode_cookie_policy() . '</div>';
    }

    private function export_payload() {
        return array(
            'plugin' => array( 'name' => 'Dendrila Privacy', 'version' => self::VERSION ),
            'site' => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
            'scan' => get_option( self::OPTION_SCAN, array() ),
            'page_audit' => get_option( self::OPTION_PAGE_AUDIT, array() ),
            'legal_profile' => get_option( self::OPTION_LEGAL_PROFILE, array() ),
            'public_documents' => get_option( self::OPTION_PUBLIC_DOCUMENTS, array() ),
            'audit_log' => array_slice( get_option( self::OPTION_AUDIT_LOG, array() ), 0, 30 ),
            'generated_at' => gmdate( 'c' ),
        );
    }

    private function download_json_export() {
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="pixel-trackers-manager-export-' . gmdate( 'Ymd-His' ) . '.json"' );
        echo wp_json_encode( $this->export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    private function admin_notice() {
        $key = 'pixel_trackers_manager_admin_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( $notice ) {
            delete_transient( $key );
            $type = isset( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'success';
            if ( 'error' === $type ) {
                $class = 'notice notice-error';
            } elseif ( 'warning' === $type ) {
                $class = 'notice notice-warning';
            } else {
                $class = 'notice notice-success';
            }
            echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
        }
    }

    private function form_start( $action ) {
        echo '<form method="post">';
        wp_nonce_field( 'pixel_trackers_manager_admin_action', 'pixel_trackers_manager_nonce' );
        echo '<input type="hidden" name="pixel_trackers_manager_action" value="' . esc_attr( $action ) . '">';
    }

    private function form_end() {
        echo '</form>';
    }

    public function render_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = $this->settings();
        $scan = get_option( self::OPTION_SCAN, array() );
        $page_audit = get_option( self::OPTION_PAGE_AUDIT, array() );
        $log = get_option( self::OPTION_AUDIT_LOG, array() );
        $page = sanitize_key( $this->query_value( 'page', 'dendrila-privacy' ) );

        echo '<div class="wrap ptm-wrap">';
        echo '<header class="ptm-head"><div class="ptm-brand"><img class="ptm-brand-mark" src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/logo-mark.svg' ) . '" alt=""><div><h1>Dendrila Privacy <span class="ptm-version">' . esc_html( self::VERSION ) . '</span></h1><p><strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong> — audit de confidentialité et assistant de documentation</p></div></div></header>';

        if ( 'pixel-trackers-manager-setup' === $page ) {
            $this->admin_notice();
            $this->render_setup_tab();
            echo '</div>';
            return;
        }

        $this->render_admin_tabs( $page );
        echo '<div class="ptm-tab-content" data-ptm-page="' . esc_attr( $page ) . '">';
        $this->admin_notice();

        if ( 'pixel-trackers-manager-findings' === $page ) {
            $this->render_findings_tab( $scan );
        } elseif ( 'pixel-trackers-manager-privacy' === $page ) {
            $this->render_privacy_tab( $settings, $page_audit );
        } elseif ( 'pixel-trackers-manager-assistant' === $page ) {
            $this->render_legal_assistant_tab( $settings, $page_audit );
        } elseif ( 'pixel-trackers-manager-consent' === $page ) {
            $this->render_consent_tab( $settings );
        } elseif ( 'pixel-trackers-manager-journal' === $page ) {
            $this->render_journal_tab( $log );
        } elseif ( 'pixel-trackers-manager-settings' === $page ) {
            $this->render_settings_tab( $settings );
        } else {
            $this->render_overview_tab( $scan, $page_audit );
        }
        echo '</div></div>';
    }


    private function render_setup_tab() {
        $state = $this->onboarding_state();
        $settings = $this->settings();
        $requested_step = sanitize_key( $this->query_value( 'ptm_setup_step' ) );
        $step = $requested_step ? $requested_step : ( ! empty( $state['step'] ) ? sanitize_key( $state['step'] ) : 'welcome' );
        if ( 'completed' === $state['status'] && ! $requested_step ) { $step = 'welcome'; }
        if ( ! in_array( $step, array( 'welcome', 'pages', 'consent', 'summary' ), true ) ) { $step = 'welcome'; }
        $step_number = array( 'welcome'=>1, 'pages'=>2, 'consent'=>3, 'summary'=>4 );

        echo '<main class="ptm-setup" data-ptm-setup-step="' . esc_attr( $step ) . '">';
        echo '<div class="ptm-setup-shell">';
        echo '<div class="ptm-setup-topline"><div class="ptm-setup-mini-brand"><img src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/logo-mark.svg' ) . '" alt=""><span>Dendrila Privacy</span></div><span>Étape ' . esc_html( $step_number[$step] ) . ' sur 4</span></div>';
        echo '<div class="ptm-setup-meter" aria-label="Progression de la configuration"><span style="width:' . esc_attr( $step_number[$step] * 25 ) . '%"></span></div>';

        if ( 'welcome' === $step ) {
            echo '<section class="ptm-setup-panel ptm-setup-hero">';
            echo '<div class="ptm-setup-icon"><span class="dashicons dashicons-shield-alt"></span></div><p class="ptm-eyebrow">Première ouverture</p><h2>Préparons votre site en quelques étapes.</h2>';
            echo '<p class="ptm-setup-lead">Dendrila Privacy va repérer vos pages légales, préparer la gestion du consentement et vous proposer un premier contrôle complet. Rien n’est publié, activé ou analysé sans votre clic.</p>';
            echo '<div class="ptm-setup-promises"><div><span class="dashicons dashicons-media-document"></span><strong>Pages légales</strong><small>Réutiliser celles qui existent et créer un brouillon lorsqu’il en manque une.</small></div><div><span class="dashicons dashicons-privacy"></span><strong>Consentement</strong><small>Conserver votre solution existante ou configurer celle de Dendrila Privacy.</small></div><div><span class="dashicons dashicons-search"></span><strong>Premier état</strong><small>Une analyse complète est recommandée pour établir la référence du site.</small></div></div>';
            echo '<div class="ptm-setup-actions">';
            $this->form_start( 'setup_begin' ); submit_button( 'Commencer la configuration', 'primary', 'submit', false ); $this->form_end();
            $this->form_start( 'setup_pause' ); echo '<input type="hidden" name="setup_step" value="welcome">'; submit_button( 'Quitter pour l’instant', 'secondary', 'submit', false ); $this->form_end();
            echo '</div><p class="ptm-setup-footnote">Dendrila Privacy aide à vérifier et documenter le site ; il ne constitue pas une certification juridique.</p></section>';
        } elseif ( 'pages' === $step ) {
            echo '<section class="ptm-setup-panel"><p class="ptm-eyebrow">Pages légales</p><h2>Vérifions les trois destinations principales.</h2><p class="ptm-setup-lead">Dendrila Privacy propose d’abord ce qu’il trouve. Vous gardez toujours la main pour sélectionner une autre page.</p>';
            $this->form_start( 'setup_save_pages' );
            echo '<div class="ptm-setup-pages">';
            foreach ( array( 'legal_notice', 'privacy', 'cookies' ) as $kind ) {
                $cfg = $this->legal_document_config( $kind, $settings );
                $candidates = $this->legal_page_candidates( $kind );
                $selected_id = ! empty( $cfg['page_id'] ) ? (int) $cfg['page_id'] : ( ! empty( $candidates[0]['page_id'] ) ? (int) $candidates[0]['page_id'] : 0 );
                $selected_builder = $selected_id ? $this->page_builder_info( $selected_id ) : array( 'id'=>'wordpress', 'label'=>'Éditeur WordPress' );
                $status_class = $selected_id ? 'is-found' : 'is-missing';
                echo '<article class="ptm-setup-page-card ' . esc_attr( $status_class ) . '"><div class="ptm-setup-page-head"><div><span class="ptm-setup-status-icon">' . ( $selected_id ? '✓' : '!' ) . '</span><h3>' . esc_html( $cfg['label'] ) . '</h3>';
                if ( count( $candidates ) > 1 ) { echo '<p><strong>' . esc_html( count( $candidates ) ) . ' pages possibles.</strong> Vérifiez la sélection.</p>'; }
                elseif ( 1 === count( $candidates ) ) { echo '<p><strong>Page probable trouvée.</strong> Vous pouvez en choisir une autre.</p>'; }
                else { echo '<p><strong>Aucune page probable.</strong> Choisissez-en une ou créez un brouillon.</p>'; }
                echo '</div>';
                if ( $selected_id ) { echo '<span class="ptm-badge neutral">' . esc_html( isset( $selected_builder['label'] ) ? $selected_builder['label'] : 'Éditeur WordPress' ) . '</span>'; }
                echo '</div><label class="ptm-field"><span>Page à utiliser</span>';
                $this->setup_page_select( $kind, $selected_id );
                echo '</label>';
                if ( empty( $candidates ) ) { echo '<label class="ptm-setup-create"><input type="checkbox" name="setup_create[' . esc_attr( $kind ) . ']" value="1"> <span><strong>Créer cette page</strong><small>Créer un brouillon avec ' . esc_html( $cfg['shortcode'] ) . '. Rien ne sera publié automatiquement.</small></span></label>'; }
                echo '</article>';
            }
            echo '</div><div class="ptm-setup-actions">';
            echo '<a class="button button-secondary" href="' . esc_url( add_query_arg( 'ptm_setup_step', 'welcome', admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) ) ) . '">Retour</a>';
            submit_button( 'Continuer', 'primary', 'submit', false );
            echo '</div>';
            $this->form_end();
            $this->form_start( 'setup_pause' ); echo '<input type="hidden" name="setup_step" value="pages">'; submit_button( 'Quitter et reprendre plus tard', 'link', 'submit', false ); $this->form_end();
            echo '</section>';
        } elseif ( 'consent' === $step ) {
            $cmps = $this->public_cmp_names();
            echo '<section class="ptm-setup-panel"><p class="ptm-eyebrow">Gestion du consentement</p><h2>' . ( $cmps ? 'Une solution de consentement est déjà présente.' : 'Souhaitez-vous ajouter une interface de consentement ?' ) . '</h2>';
            if ( $cmps ) {
                echo '<p class="ptm-setup-lead">Dendrila Privacy a détecté <strong>' . esc_html( implode( ', ', $cmps ) ) . '</strong>. Il est préférable de conserver une seule interface et de laisser Dendrila Privacy vérifier son fonctionnement.</p>';
            } else {
                echo '<p class="ptm-setup-lead">Dendrila Privacy peut bloquer les services facultatifs connus avant le choix du visiteur et afficher une barre ou un encart avec des actions Accepter / Refuser de même importance.</p>';
            }
            $this->form_start( 'setup_save_consent' );
            echo '<div class="ptm-consent-choice-grid">';
            if ( $cmps ) {
                echo '<label class="ptm-consent-choice-card is-recommended"><input type="radio" name="setup_consent" value="existing" checked><span><strong>Conserver la solution détectée</strong><small>Dendrila Privacy ne l’active ni ne la remplace. Après le scan, il pourra vérifier les services qui partent avant le choix.</small><em>Recommandé</em></span></label>';
                echo '<label class="ptm-consent-choice-card"><input type="radio" name="setup_consent" value="later"><span><strong>Décider plus tard</strong><small>Vous pourrez revenir dans Consentement à tout moment.</small></span></label>';
            } else {
                echo '<label class="ptm-consent-choice-card is-recommended"><input type="radio" name="setup_consent" value="ptm" ' . checked( ! empty( $settings['consent_enabled'] ), true, false ) . '><span><strong>Configurer la gestion du consentement Dendrila Privacy</strong><small>Blocage avant choix, refus aussi simple que l’acceptation, retrait du consentement possible ensuite.</small><em>Recommandé si vous utilisez des services facultatifs</em></span></label>';
                echo '<label class="ptm-consent-choice-card"><input type="radio" name="setup_consent" value="later" ' . checked( empty( $settings['consent_enabled'] ), true, false ) . '><span><strong>Pas maintenant</strong><small>Rien ne sera activé. Dendrila Privacy vous le signalera si l’analyse trouve des services facultatifs sans solution détectée.</small></span></label>';
            }
            echo '</div>';
            if ( ! $cmps ) {
                echo '<div class="ptm-consent-preview-options"><div><strong>Présentation Dendrila Privacy</strong><p>Choisissez le format que vous préférez. Vous pourrez le modifier ensuite.</p></div><label class="ptm-layout-choice"><input type="radio" name="consent_layout" value="bar" ' . checked( $settings['consent_layout'], 'bar', false ) . '><span class="ptm-layout-demo is-bar"><i></i><i></i></span><strong>Barre en bas</strong></label><label class="ptm-layout-choice"><input type="radio" name="consent_layout" value="card" ' . checked( $settings['consent_layout'], 'card', false ) . '><span class="ptm-layout-demo is-card"><i></i></span><strong>Encart centré</strong></label></div>';
                echo '<label class="ptm-field ptm-inline-field"><span>Style</span><select name="consent_style"><option value="inherit" ' . selected( $settings['consent_style'], 'inherit', false ) . '>S’intégrer au style du site</option><option value="neutral" ' . selected( $settings['consent_style'], 'neutral', false ) . '>Style neutre Dendrila Privacy</option></select></label>';
            }
            echo '<div class="ptm-setup-actions">'; echo '<a class="button button-secondary" href="' . esc_url( add_query_arg( 'ptm_setup_step', 'pages', admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) ) ) . '">Retour</a>'; submit_button( 'Continuer', 'primary', 'submit', false ); echo '</div>';
            $this->form_end();
            $this->form_start( 'setup_pause' ); echo '<input type="hidden" name="setup_step" value="consent">'; submit_button( 'Quitter et reprendre plus tard', 'link', 'submit', false ); $this->form_end();
            echo '</section>';
        } else {
            $settings = $this->settings();
            $cmps = $this->public_cmp_names();
            echo '<section class="ptm-setup-panel"><p class="ptm-eyebrow">Prêt pour le premier contrôle</p><h2>Votre configuration de départ est prête.</h2><p class="ptm-setup-lead">Pour le premier contrôle, nous recommandons une analyse complète afin d’établir un état de référence du site.</p>';
            echo '<div class="ptm-setup-summary">';
            foreach ( array( 'legal_notice', 'privacy', 'cookies' ) as $kind ) {
                $cfg = $this->legal_document_config( $kind, $settings ); $page_id = ! empty( $cfg['page_id'] ) ? (int) $cfg['page_id'] : 0; $post = $page_id ? get_post( $page_id ) : null;
                echo '<article><span class="ptm-summary-check">' . ( $post ? '✓' : '•' ) . '</span><div><strong>' . esc_html( $cfg['label'] ) . '</strong><span>' . ( $post ? esc_html( $post->post_title ? $post->post_title : '(sans titre)' ) : 'À choisir plus tard' ) . '</span></div></article>';
            }
            echo '<article><span class="ptm-summary-check">✓</span><div><strong>Consentement</strong><span>' . esc_html( ! empty( $settings['consent_enabled'] ) ? 'Interface Dendrila Privacy configurée' : ( $cmps ? implode( ', ', $cmps ) . ' détecté' : 'À configurer plus tard' ) ) . '</span></div></article>';
            echo '</div>';
            echo '<div class="ptm-first-scan-recommendation"><span class="dashicons dashicons-search"></span><div><strong>Analyse complète — recommandée</strong><p>Elle inspecte davantage de pages, les services externes, les traceurs et les erreurs rencontrées. Une page en erreur n’empêchera pas la barre d’atteindre 100 % : les erreurs seront comptées séparément.</p></div></div>';
            echo '<div class="ptm-setup-actions ptm-final-actions">';
            $this->form_start( 'setup_finish_full' ); submit_button( 'Lancer l’analyse complète — recommandé', 'primary', 'submit', false ); $this->form_end();
            $this->form_start( 'setup_finish_quick' ); submit_button( 'Faire une analyse rapide', 'secondary', 'submit', false ); $this->form_end();
            $this->form_start( 'setup_finish' ); submit_button( 'Terminer sans analyser maintenant', 'link', 'submit', false ); $this->form_end();
            echo '</div><p><a href="' . esc_url( add_query_arg( 'ptm_setup_step', 'consent', admin_url( 'admin.php?page=pixel-trackers-manager-setup' ) ) ) . '">Revenir à l’étape précédente</a></p></section>';
        }
        echo '</div></main>';
    }

    private function render_admin_tabs( $current_page ) {
        $tabs = array(
            'pixel-trackers-manager' => 'Vue d’ensemble',
            'pixel-trackers-manager-findings' => 'Traceurs & services',
            'pixel-trackers-manager-privacy' => 'Pages légales',
            'pixel-trackers-manager-assistant' => 'Assistant RGPD',
            'pixel-trackers-manager-consent' => 'Consentement',
            'pixel-trackers-manager-journal' => 'Journal',
            'pixel-trackers-manager-settings' => 'Réglages',
        );
        echo '<div class="ptm-tab-surface"><nav class="ptm-nav-tabs" aria-label="Navigation Dendrila Privacy">';
        $i = 0; $count = count( $tabs );
        foreach ( $tabs as $slug => $label ) {
            $i++; $active = $current_page === $slug;
            $classes = array( 'ptm-tab' );
            if ( $active ) { $classes[] = 'is-active'; }
            if ( 1 === $i ) { $classes[] = 'is-first'; }
            if ( $count === $i ) { $classes[] = 'is-last'; }
            echo '<a class="' . esc_attr( implode( ' ', $classes ) ) . '" ' . ( $active ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav></div>';
    }

    private function render_overview_tab( $scan, $page_audit ) {
        $findings = isset( $scan['findings'] ) && is_array( $scan['findings'] ) ? $scan['findings'] : array();
        $active = isset( $scan['active_tracking_count'] ) ? (int) $scan['active_tracking_count'] : 0;
        $actions = isset( $page_audit['recommendations'] ) && is_array( $page_audit['recommendations'] ) ? $page_audit['recommendations'] : array();
        $score = isset( $page_audit['technical_coverage_percent'] ) ? max( 0, min( 100, (int) $page_audit['technical_coverage_percent'] ) ) : null;
        $cmp = ! empty( $this->settings()['consent_enabled'] ) ? 'Dendrila Privacy' : ( ! empty( $scan['cmp'][0]['name'] ) ? $scan['cmp'][0]['name'] : '' );
        $coverage = isset( $scan['coverage'] ) && is_array( $scan['coverage'] ) ? $scan['coverage'] : array();
        $last_scan = 'Jamais';
        if ( ! empty( $scan['generated_at'] ) ) {
            $timestamp = strtotime( $scan['generated_at'] );
            if ( $timestamp ) {
                $last_scan = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
            }
        }

        echo '<div class="ptm-dashboard">';
        echo '<div class="ptm-alert-legend" aria-label="Niveaux d’alerte"><span class="is-problem">🔴 <strong>Problème constaté</strong> — preuve technique</span><span class="is-verify">🟠 <strong>À vérifier</strong> — contexte manquant</span><span class="is-advice">🔵 <strong>Conseil</strong> — bonne pratique, sans effet sur le score</span></div>';
        echo '<div class="ptm-grid ptm-kpis">';
        $coverage_note = 'Contrôle de page à lancer';
        if ( null !== $score ) {
            $coverage_note = isset( $page_audit['satisfied_total'], $page_audit['applicable_total'] )
                ? absint( $page_audit['satisfied_total'] ) . ' / ' . absint( $page_audit['applicable_total'] ) . ' éléments applicables documentés'
                : 'Aide technique, pas un avis juridique';
        }
        echo '<a class="ptm-kpi ptm-kpi-score ptm-clickable-card" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-privacy#ptm-coverage-list' ) ) . '"><div class="ptm-score-ring" style="--ptm-score:' . esc_attr( null === $score ? 0 : $score ) . '"><span>' . ( null === $score ? '—' : esc_html( $score ) . '%' ) . '</span></div><div><span>Indicateur</span><strong>Couverture documentaire</strong><small>' . esc_html( $coverage_note ) . '</small></div><span class="dashicons dashicons-arrow-right-alt2 ptm-card-arrow" aria-hidden="true"></span></a>';
        $this->kpi( 'Traceurs actifs', $active, 'suivis techniquement confirmés', 'visibility', $active > 0 ? 'warn' : 'good', admin_url( 'admin.php?page=pixel-trackers-manager-findings' ) );
        $this->kpi( 'Actions à exécuter', count( $actions ), count( $actions ) ? 'à examiner' : 'aucun manque détecté', 'clipboard', count( $actions ) ? 'warn' : 'good', admin_url( 'admin.php?page=pixel-trackers-manager-privacy#ptm-coverage-list' ) );
        $this->kpi( 'Dernière analyse', $last_scan, ! empty( $coverage['processed'] ) ? (int) $coverage['processed'] . ' page(s) traitée(s)' : ( ! empty( $coverage['scanned'] ) ? (int) $coverage['scanned'] . ' page(s) analysée(s)' : 'aucune analyse enregistrée' ), 'calendar-alt', 'good', admin_url( 'admin.php?page=pixel-trackers-manager#ptm-scan-card' ) );
        echo '</div>';

        echo '<div class="ptm-dashboard-main">';
        echo '<section class="ptm-card ptm-scan-card" id="ptm-scan-card"><div class="ptm-card-head"><div><h2><span class="dashicons dashicons-update"></span> Analyse du site</h2><p>Analyse progressive des contenus publics, extensions actives et réglages de suivi connus.</p></div><div class="ptm-scan-actions"><button type="button" id="ptm-scan-start" class="button button-primary">Relancer l’analyse standard</button><button type="button" id="ptm-scan-full" class="button button-secondary">Analyse complète</button><button type="button" id="ptm-scan-cancel" class="button" hidden>Annuler</button><noscript>';
        $this->form_start( 'scan' ); submit_button( 'Relancer l’analyse', 'primary', 'submit', false ); $this->form_end();
        echo '</noscript></div></div>';
        echo '<details class="ptm-full-scan-options"><summary>Options de l’analyse complète</summary><div class="ptm-full-scan-grid"><label>Pour les articles et contenus similaires, limiter à <select id="ptm-full-scan-age"><option value="0">Toutes les dates</option><option value="1">1 an</option><option value="2">2 ans</option><option value="5">5 ans</option><option value="10">10 ans</option></select></label><label><input type="checkbox" id="ptm-full-scan-archives"> Inclure aussi les archives de catégories, étiquettes et types de contenus</label></div><p class="description">Les pages juridiques sélectionnées sont toujours incluses. Dendrila Privacy ne supprime aucun contenu pendant une analyse.</p></details>';
        if ( ! empty( $coverage ) ) {
            $requested = isset( $coverage['requested'] ) ? (int) $coverage['requested'] : 0;
            $scanned = isset( $coverage['scanned'] ) ? (int) $coverage['scanned'] : 0;
            $page_issues = isset( $coverage['page_issues'] ) && is_array( $coverage['page_issues'] ) ? $coverage['page_issues'] : array();
            $processed = isset( $coverage['processed'] ) ? (int) $coverage['processed'] : min( $requested, $scanned + ( isset( $coverage['errors'] ) && is_array( $coverage['errors'] ) ? count( $coverage['errors'] ) : 0 ) + count( $page_issues ) );
            $percent = $requested > 0 ? min( 100, (int) round( ( $processed / $requested ) * 100 ) ) : 0;
            $mode_label = isset($coverage['mode']) && 'full'===$coverage['mode'] ? 'analyse complète' : 'analyse standard';
            echo '<div class="ptm-last-coverage"><strong>' . esc_html( $processed ) . ' / ' . esc_html( $requested ) . ' pages traitées · ' . esc_html( $scanned ) . ' réussies</strong><span>' . esc_html( $percent ) . '%</span></div><div class="ptm-static-progress"><span style="width:' . esc_attr( $percent ) . '%"></span></div><p class="ptm-muted">Dernière couverture : '.esc_html($mode_label).'.</p>';
            if ( $page_issues ) {
                echo '<div class="ptm-page-issue-summary"><div><strong>' . esc_html( count( $page_issues ) ) . ' page(s) existent dans WordPress mais ne sont pas publiques.</strong><span>Dendrila Privacy les classe comme actions à faire, pas comme erreurs techniques.</span></div></div>';
                echo '<div class="ptm-page-issue-list">';
                foreach ( array_slice( $page_issues, 0, 20 ) as $issue ) {
                    $issue_page_id = ! empty( $issue['page_id'] ) ? absint( $issue['page_id'] ) : 0;
                    $status_label = ! empty( $issue['status_label'] ) ? (string) $issue['status_label'] : 'Page non publiée';
                    $title = ! empty( $issue['title'] ) ? (string) $issue['title'] : $this->scan_page_label( isset($issue['url']) ? $issue['url'] : '' );
                    echo '<article class="ptm-page-issue-row"><div><strong>' . esc_html( $title ) . '</strong><span class="ptm-badge warn">' . esc_html( $status_label ) . '</span>';
                    if ( ! empty( $issue['generated'] ) ) { echo '<small>Cette page a été préparée par Dendrila Privacy.</small>'; }
                    if ( empty( $issue['ready'] ) && ! empty( $issue['missing'] ) ) { echo '<small>À compléter avant publication : ' . esc_html( implode( ', ', array_slice( (array)$issue['missing'], 0, 3 ) ) ) . ( count((array)$issue['missing']) > 3 ? '…' : '' ) . '</small>'; }
                    echo '</div><div class="ptm-page-issue-actions">';
                    if ( ! empty( $issue['edit_url'] ) ) { echo '<a class="button button-secondary" href="' . esc_url( $issue['edit_url'] ) . '">Modifier</a>'; }
                    if ( $issue_page_id && ! in_array( isset($issue['status']) ? $issue['status'] : '', array('future','trash'), true ) && ( empty($issue['generated']) || ! empty($issue['ready']) ) ) {
                        $this->form_start( 'publish_legal_page' );
                        echo '<input type="hidden" name="page_id" value="' . esc_attr( $issue_page_id ) . '">';
                        submit_button( 'Publier la page', 'primary', 'submit', false );
                        $this->form_end();
                    } elseif ( ! empty( $issue['generated'] ) && empty( $issue['ready'] ) ) {
                        $step = 'legal_notice' === (isset($issue['kind'])?$issue['kind']:'') ? 'identity' : ( 'privacy' === (isset($issue['kind'])?$issue['kind']:'') ? 'treatments' : 'cookies' );
                        echo '<a class="button button-primary" href="' . esc_url( $this->assistant_url( $step ) ) . '">Compléter dans l’assistant</a>';
                    }
                    echo '</div></article>';
                }
                echo '</div>';
            }
            $scan_errors = isset( $coverage['errors'] ) && is_array( $coverage['errors'] ) ? $coverage['errors'] : array();
            if ( $scan_errors ) {
                echo '<div class="ptm-scan-error-summary"><div><strong>' . esc_html( count( $scan_errors ) ) . ' page(s) n’ont pas pu être lues.</strong><span>Le reste de l’analyse a continué normalement.</span></div><button type="button" id="ptm-scan-retry-failed" class="button button-secondary">Réessayer uniquement ces pages</button></div>';
                echo '<details class="ptm-scan-error-details"><summary>Voir les erreurs</summary><ul>';
                foreach ( array_slice( $scan_errors, 0, 20 ) as $failure ) {
                    $url = ! empty( $failure['url'] ) ? esc_url_raw( $failure['url'] ) : '';
                    $error = ! empty( $failure['error'] ) ? (string) $failure['error'] : 'Erreur de lecture';
                    echo '<li><strong>' . esc_html( $this->scan_page_label( $url ) ) . '</strong><span>' . esc_html( $error ) . '</span></li>';
                }
                echo '</ul></details>';
            }
        } else {
            echo '<p class="ptm-empty-state">Aucune analyse enregistrée. Lancez la première analyse pour établir l’état technique du site.</p>';
        }
        echo '<div id="ptm-scan-progress" class="ptm-live-progress" hidden aria-live="polite"><div class="ptm-progress-heading"><strong id="ptm-scan-status">Préparation de l’analyse…</strong><span id="ptm-scan-percent">0%</span></div><div id="ptm-progress-track" class="ptm-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="ptm-progress-bar"></span></div><div class="ptm-progress-meta"><span id="ptm-scan-count">0 page analysée</span><span id="ptm-scan-errors"></span></div><span id="ptm-scan-page" class="ptm-scan-page" hidden></span></div>';
        echo '</section>';

        echo '<a class="ptm-card ptm-cmp-card ptm-clickable-card" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-consent' ) ) . '"><div class="ptm-card-head"><h2><span class="dashicons dashicons-shield-alt"></span> Gestion du consentement</h2>' . ( $cmp ? '<span class="ptm-badge good">Détectée</span>' : '<span class="ptm-badge warn">À configurer</span>' ) . '</div>';
        if ( $cmp ) {
            echo '<p class="ptm-cmp-name">' . esc_html( $cmp ) . '</p><p>Ouvrez cette section pour vérifier le blocage avant choix et tester le comportement.</p>';
        } else {
            echo '<p><strong>Aucune solution connue détectée.</strong></p><p>Dendrila Privacy peut proposer une barre ou un encart et bloquer les services facultatifs connus avant le choix.</p><span class="ptm-inline-cta">Configurer la gestion du consentement →</span>';
        }
        echo '<span class="dashicons dashicons-arrow-right-alt2 ptm-card-arrow" aria-hidden="true"></span></a>';

        echo '<section class="ptm-card ptm-actions-card"><h2><span class="dashicons dashicons-clipboard"></span> ' . esc_html( count( $actions ) ) . ' action(s) à exécuter</h2>';
        if ( ! $page_audit ) {
            echo '<p>Contrôlez les pages légales pour obtenir des actions documentaires précises.</p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-privacy' ) ) . '">Contrôler les pages</a>';
        } elseif ( ! $actions ) {
            echo '<p><span class="ptm-badge good">Rien de bloquant détecté</span></p><p class="ptm-muted">Dendrila Privacy n’a identifié aucun manque avec ses règles actuelles.</p>';
        } else {
            echo '<div class="ptm-action-rows">';
            foreach ( array_slice( $actions, 0, 4 ) as $index => $action ) {
                $destination = $this->recommendation_destination( $action );
                echo '<a class="ptm-action-row ptm-action-link" href="' . esc_url( $destination ) . '"><span class="ptm-action-dot"></span><div><strong>' . esc_html( $action['title'] ) . '</strong><small>' . esc_html( $action['detail'] ) . '</small></div><span class="ptm-action-go"><span class="ptm-mini-status">' . ( 0 === $index ? 'Important' : 'À faire' ) . '</span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><span class="screen-reader-text">Ouvrir le bloc concerné</span></span></a>';
            }
            echo '</div><p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-privacy' ) ) . '">Voir le contrôle détaillé</a></p>';
        }
        echo '</section></div>';

        echo '<div class="ptm-dashboard-bottom">';
        echo '<section class="ptm-card ptm-detected-card"><div class="ptm-card-head"><div><h2><span class="dashicons dashicons-share"></span> Traceurs & services détectés</h2><p>Le statut distingue une preuve de suivi actif d’une simple capacité technique détectée.</p></div><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-findings' ) ) . '">Tout examiner</a></div>';
        if ( ! $findings ) {
            echo '<p>Aucune donnée disponible.</p>';
        } else {
            echo '<div class="ptm-table-wrap"><table class="widefat ptm-dashboard-table"><thead><tr><th>Service</th><th>État</th><th>Preuve</th><th>Action</th></tr></thead><tbody>';
            foreach ( array_slice( $findings, 0, 7 ) as $finding ) {
                $state = isset( $finding['tracking_state'] ) ? $finding['tracking_state'] : 'potential';
                $is_tracking = ! isset( $finding['is_tracking'] ) || ! empty( $finding['is_tracking'] );
                if ( ! $is_tracking && 'active' === $state ) { $badge = '<span class="ptm-badge warn">Chargé à distance</span>'; }
                elseif ( 'active' === $state ) { $badge = '<span class="ptm-badge bad">Actif</span>'; }
                elseif ( 'disabled' === $state ) { $badge = '<span class="ptm-badge good">Suivi désactivé</span>'; }
                elseif ( 'blocked' === $state ) { $badge = '<span class="ptm-badge good">Bloqué avant choix</span>'; }
                else { $badge = '<span class="ptm-badge warn">À vérifier</span>'; }
                $proof = ! empty( $finding['source'] ) ? $finding['source'] : ( ! empty( $finding['observed_html'] ) ? 'Trace repérée dans le code affiché' : 'Source technique détectée' );
                echo '<tr><td><strong>' . esc_html( $finding['label'] ) . '</strong><small>' . esc_html( isset( $finding['category'] ) ? $finding['category'] : '' ) . '</small></td><td>' . wp_kses_post( $badge ) . '</td><td>' . esc_html( $proof ) . '</td><td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-findings' ) ) . '">Examiner</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';

        $missing = array();
        if ( ! empty( $page_audit['topics'] ) ) {
            foreach ( $page_audit['topics'] as $topic ) {
                if ( ! empty( $topic['applicable'] ) && empty( $topic['present'] ) && ! empty( $topic['label'] ) ) { $missing[] = $topic['label']; }
            }
        }
        if ( ! empty( $page_audit['services'] ) ) {
            foreach ( $page_audit['services'] as $service ) {
                if ( empty( $service['mentioned'] ) && ! empty( $service['label'] ) ) { $missing[] = 'Documenter ' . $service['label']; }
            }
        }
        echo '<section class="ptm-card ptm-missing-card"><h2><a class="ptm-card-title-link" href="' . esc_url( $this->assistant_url( 'identity' ) ) . '"><span class="dashicons dashicons-list-view"></span> Ce qui manque <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></h2>';
        if ( ! $page_audit ) {
            echo '<p>Le contrôle documentaire n’a pas encore été lancé.</p>';
        } elseif ( ! $missing ) {
            echo '<ul class="ptm-missing-list"><li class="ok">Aucun manque détecté par le contrôle de Dendrila Privacy</li></ul>';
        } else {
            echo '<ul class="ptm-missing-list">';
            foreach ( array_slice( array_unique( $missing ), 0, 7 ) as $item ) { echo '<li>' . esc_html( $item ) . '</li>'; }
            echo '</ul><p><a class="button button-primary" href="' . esc_url( $this->assistant_url( 'identity' ) ) . '">Compléter les mentions</a></p>';
        }
        echo '</section></div>';
        echo '<p class="ptm-disclaimer"><span class="dashicons dashicons-info-outline"></span> L’indicateur de couverture mesure une couverture documentaire et des signaux techniques. Il ne constitue pas un avis juridique ni une garantie de conformité.</p>';
        echo '</div>';
    }

    private function render_findings_tab( $scan ) {
        echo '<section class="ptm-card"><div class="ptm-card-head"><div><h2>Outils e-mail et suivi d’engagement</h2><p>Dendrila Privacy indique uniquement l’état utile à comprendre, sans afficher les valeurs internes des réglages.</p></div></div>';
        if(empty($scan['mailing'])){echo '<p>Aucun outil d’e-mailing connu détecté.</p>';} else {
            echo '<div class="ptm-findings">';
            foreach($scan['mailing'] as $tool){
                $state=isset($tool['tracking_state'])?$tool['tracking_state']:'unknown';
                echo '<article class="ptm-finding"><div class="ptm-finding-title"><div><h3>'.esc_html($tool['name']).'</h3><p>'.esc_html($tool['help']).'</p></div><div>';
                if('active'===$state){echo '<span class="ptm-badge bad">Confirmé actif</span>';} elseif('disabled'===$state){echo '<span class="ptm-badge good">Désactivé</span>';} elseif('external'===$state){echo '<span class="ptm-badge neutral">À vérifier dans le service</span>';} else {echo '<span class="ptm-badge warn">À vérifier</span>';}
                echo '</div></div>';
                if('mailpoet'===$tool['id']){
                    if('active'===$state){$this->form_start('disable_tracking_adapter');echo '<input type="hidden" name="adapter" value="mailpoet">';submit_button('Désactiver le suivi MailPoet (garder les newsletters)','primary','submit',false,array('onclick'=>"return confirm('Désactiver le suivi d’engagement MailPoet tout en conservant les newsletters ?');"));$this->form_end();}
                    elseif(!empty($this->settings()['adapter_previous']['mailpoet_tracking_level'])){$this->form_start('restore_tracking_adapter');echo '<input type="hidden" name="adapter" value="mailpoet">';submit_button('Rétablir le réglage MailPoet précédent','secondary','submit',false);$this->form_end();}
                } elseif('fluentcrm'===$tool['id']){
                    if('disabled'!==$state){$this->form_start('disable_tracking_adapter');echo '<input type="hidden" name="adapter" value="fluentcrm">';submit_button('Couper le suivi d’engagement FluentCRM','primary','submit',false);$this->form_end();}
                    else{$this->form_start('restore_tracking_adapter');echo '<input type="hidden" name="adapter" value="fluentcrm">';submit_button('Retirer le mode confidentialité de Dendrila Privacy','secondary','submit',false);$this->form_end();}
                }
                if(!empty($tool['settings_url'])){echo ' <a class="button button-secondary" href="'.esc_url($tool['settings_url']).'">Ouvrir les réglages</a>';}
                echo '</article>';
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<section class="ptm-card"><div class="ptm-card-head"><div><h2>Traceurs, services et sources probables</h2><p>Le statut explique la nature de la preuve obtenue, sans score de confiance opaque.</p></div>';
        $this->form_start('export_json');submit_button('Exporter les données (JSON)','secondary','submit',false);$this->form_end();echo '</div>';
        if(empty($scan['findings'])){echo '<p>Aucune donnée. Lancez une analyse.</p></section>';return;}
        echo '<div class="ptm-findings">';
        foreach($scan['findings'] as $finding){
            $evidence_status=$this->finding_evidence_status($finding);
            echo '<article class="ptm-finding"><div class="ptm-finding-title"><div><h3>'.esc_html($finding['label']).'</h3><p>'.esc_html($finding['category']).'</p></div><div>';
            echo '<span class="ptm-badge '.esc_attr($evidence_status['tone']).'">'.esc_html($evidence_status['label']).'</span></div></div>';
            $summary='';
            if(!empty($finding['observed_html'])){$summary='Une trace technique a été observée sur le site.';}
            elseif('disabled'===(isset($finding['tracking_state'])?$finding['tracking_state']:'')){$summary='Le service est présent, mais le suivi concerné est désactivé.';}
            elseif(!empty($finding['plugin_sources'])){$summary='Une intégration connue est présente ; l’activation réelle reste à confirmer si aucune trace n’a été observée.';}
            elseif(!empty($finding['code_reference'])){$summary='Une référence technique existe, sans preuve suffisante de suivi actif.';}
            if($summary){echo '<p>'.esc_html($summary).'</p>';}
            if(!empty($finding['urls'])){
                $labels=array();foreach(array_slice($finding['urls'],0,3) as $url){$labels[]=$this->scan_page_label($url);}
                echo '<p><strong>Repéré sur :</strong> '.esc_html(implode(', ',$labels)).'</p>';
            }
            if(!empty($finding['plugin_sources'])){$names=array();foreach($finding['plugin_sources'] as $src){$names[]=$src['name'];}echo '<p><strong>Source probable :</strong> '.esc_html(implode(', ',$names)).'</p>';}
            $hint=$this->analytics_integration_hint($finding);
            if($hint){echo '<div class="ptm-inline-action"><div><strong>Google Analytics semble être géré par '.esc_html($hint['label']).'.</strong><br><span class="ptm-muted">Dendrila Privacy utilise cette information pour vous envoyer au bon endroit, sans considérer la simple présence de l’intégration comme une preuve d’activation.</span></div><a class="button button-secondary" href="'.esc_url($hint['url']).'">Voir le réglage ↗</a></div>';}
            $control=isset($finding['tracking_control'])?$finding['tracking_control']:$this->tracking_control_for_finding($finding);
            if(!empty($finding['active_tracking']) && empty($hint)){echo '<div class="ptm-inline-action"><div><strong>Réglage utile</strong><br><span class="ptm-muted">'.esc_html($control['instructions']).'</span></div>';if(!empty($control['url'])){echo '<a class="button button-secondary" href="'.esc_url($control['url']).'">'.esc_html($control['label']).'</a>';}echo '</div>';}
            echo '</article>';
        }
        echo '</div></section>';
    }

    private function render_privacy_tab( $settings, $audit ) {
        $profile = wp_parse_args( get_option( self::OPTION_LEGAL_PROFILE, array() ), $this->legal_profile_defaults() );

        echo '<section class="ptm-card ptm-legal-pages-card"><div class="ptm-card-head"><div><h2>Pages juridiques à contrôler</h2><p>Dendrila Privacy analyse ensemble les informations réparties entre vos pages. La page cookies est facultative lorsqu’elle n’est pas pertinente pour le site.</p></div></div>';
        $this->form_start('audit_page');
        echo '<div class="ptm-legal-page-select-grid">';
        echo '<label class="ptm-field"><span>Mentions légales</span>'; $this->page_select((int)$settings['legal_notice_page_id'],'legal_notice_page_id'); echo '</label>';
        echo '<label class="ptm-field"><span>Politique de confidentialité</span>'; $this->page_select((int)$settings['privacy_page_id'],'privacy_page_id'); echo '</label>';
        echo '<label class="ptm-field"><span>Cookies / traceurs</span>'; $this->page_select((int)$settings['cookie_page_id'],'cookie_page_id'); echo '<small>Laissez vide si aucune page dédiée n’est nécessaire.</small></label>';
        echo '</div>';
        submit_button('Analyser les pages sélectionnées','primary','submit',false);
        $this->form_end();
        if ( ! empty($audit['documents']) ) {
            echo '<div class="ptm-legal-doc-chips">';
            foreach ( (array)$audit['documents'] as $doc ) {
                if ( empty($doc['page_id']) ) { continue; }
                echo '<span class="ptm-doc-chip"><strong>'.esc_html($doc['label']).'</strong>';
                if ( !empty($doc['builder']) ) { echo '<small>'.esc_html($doc['builder']).'</small>'; }
                if ( !empty($doc['edit_url']) ) { echo '<a href="'.esc_url($doc['edit_url']).'" target="_blank" rel="noopener noreferrer">Modifier ↗</a>'; }
                echo '</span>';
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<section class="ptm-card ptm-publication-card" id="ptm-publication-block"><div class="ptm-card-head"><div><h2>Codes courts (shortcodes) et mise à jour des pages</h2><p>Un document complet par destination. Vous pouvez copier son code court, ouvrir directement la page dans son éditeur ou demander à Dendrila Privacy de l’insérer lorsque la structure est prise en charge.</p></div></div>';
        echo '<div class="ptm-publication-grid">';
        foreach ( array('legal_notice','privacy','cookies') as $kind ) {
            $cfg = $this->legal_document_config($kind,$settings);
            $page_id = isset($cfg['page_id']) ? (int)$cfg['page_id'] : 0;
            $builder = isset($cfg['builder']) ? $cfg['builder'] : array('id'=>'wordpress','label'=>'Éditeur WordPress','safe_mode'=>false);
            $page_title = $page_id ? get_the_title($page_id) : '';
            echo '<article class="ptm-publication-item" id="ptm-publication-'.esc_attr($kind).'">';
            echo '<div class="ptm-publication-heading"><div><h3>'.esc_html($cfg['label']).'</h3><p>'.($page_title?esc_html($page_title):'Aucune page sélectionnée').'</p></div><span class="ptm-badge neutral">'.esc_html(isset($builder['label'])?$builder['label']:'').'</span></div>';
            echo '<div class="ptm-shortcode-row"><code>'.esc_html($cfg['shortcode']).'</code><button type="button" class="button button-small ptm-copy-shortcode" data-shortcode="'.esc_attr($cfg['shortcode']).'">Copier</button></div>';
            if ( $page_id ) {
                echo '<div class="ptm-publication-actions">';
                if ( !empty($cfg['edit_url']) ) {
                    $edit_label = 'elementor'===$builder['id'] ? 'Modifier avec Elementor ↗' : ( in_array($builder['id'],array('divi','divi5'),true) ? 'Modifier avec Divi ↗' : 'Modifier la page ↗' );
                    echo '<a class="button button-secondary" href="'.esc_url($cfg['edit_url']).'" target="_blank" rel="noopener noreferrer">'.esc_html($edit_label).'</a>';
                }
                if ( $this->public_document_is_outdated( $kind, $profile ) ) {
                    echo '<p class="ptm-callout warn"><strong>Une information a changé depuis la dernière version publique.</strong> Validez la mise à jour avant que le shortcode public ne change.</p>';
                }
                if ( 'divi5' === $builder['id'] && $this->document_shortcode_present( $page_id, $kind ) ) {
                    $this->form_start('sync_legal_document');
                    echo '<input type="hidden" name="page_id" value="'.esc_attr($page_id).'"><input type="hidden" name="document_kind" value="'.esc_attr($kind).'">';
                    submit_button('Valider et mettre à jour le contenu public','primary','submit',false);
                    $this->form_end();
                } elseif ( in_array($builder['id'],array('elementor','divi'),true) ) {
                    $this->form_start('inject_builder_shortcode');
                    echo '<input type="hidden" name="page_id" value="'.esc_attr($page_id).'"><input type="hidden" name="document_kind" value="'.esc_attr($kind).'">';
                    submit_button($this->document_shortcode_present($page_id,$kind)?'Valider et mettre à jour le contenu public':'Insérer automatiquement dans cette page','primary','submit',false,array('onclick'=>"return confirm('Dendrila Privacy va valider le contenu public et, si nécessaire, ajouter le code court à cette page. Continuer ?');"));
                    $this->form_end();
                } elseif ( ! empty( $builder['safe_mode'] ) ) {
                    echo '<p class="ptm-muted">'.esc_html( ! empty($builder['note']) ? $builder['note'] : 'Constructeur détecté : insérez le code court depuis son éditeur.' ).'</p>';
                } else {
                    $this->form_start('sync_legal_document');
                    echo '<input type="hidden" name="page_id" value="'.esc_attr($page_id).'"><input type="hidden" name="document_kind" value="'.esc_attr($kind).'">';
                    submit_button($this->document_shortcode_present($page_id,$kind)?'Valider et mettre à jour le contenu public':'Mettre à jour automatiquement cette page','primary','submit',false);
                    $this->form_end();
                }
                echo '</div>';
            } else {
                echo '<p class="ptm-muted">Sélectionnez d’abord la page correspondante ci-dessus ou dans Réglages.</p>';
            }
            echo '</article>';
        }
        echo '</div>';
        $this->form_start('sync_all_legal_documents');
        submit_button('Mettre à jour toutes les pages prêtes','secondary','submit',false,array('onclick'=>"return confirm('Dendrila Privacy va mettre à jour toutes les pages prises en charge. Continuer ?');"));
        $this->form_end();
        echo '<p class="ptm-muted">Les anciens codes courts français restent compatibles. Les informations sur un constructeur de pages ne sont affichées que lorsqu’il est réellement utilisé par la page concernée.</p></section>';

        if ( empty($audit) ) {
            echo '<section class="ptm-card ptm-start-assistant"><div class="ptm-card-head"><div><h2>Assistant RGPD</h2><p>L’assistant dispose maintenant de son propre onglet : vous pouvez le compléter sans faire défiler cette page.</p></div><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-assistant' ) ) . '">Commencer / reprendre l’assistant</a></div></section>';
            return;
        }

        $counts = array('found'=>0,'partial'=>0,'verify'=>0,'missing'=>0,'not_applicable'=>0);
        foreach ( (array)$audit['topics'] as $item ) {
            $status = isset($item['status'])?$item['status']:( !empty($item['present'])?'found':'missing' );
            if(isset($counts[$status])){$counts[$status]++;}
        }
        foreach ( (array)$audit['services'] as $item ) {
            $status = !empty($item['mentioned'])?'found':'missing';
            $counts[$status]++;
        }

        echo '<section class="ptm-card ptm-coverage-card"><div class="ptm-card-head"><div><h2>Couverture documentaire</h2><p>'.esc_html($audit['warning']).'</p></div><div class="ptm-score">'.esc_html($audit['technical_coverage_percent']).'%<small>'.esc_html(isset($audit['satisfied_total'],$audit['applicable_total']) ? $audit['satisfied_total'].' / '.$audit['applicable_total'].' éléments applicables' : 'indicateur technique').'</small></div></div>';
        echo '<div class="ptm-coverage-summary">';
        echo '<span class="ptm-coverage-pill is-found"><strong>'.esc_html($counts['found']).'</strong> repéré(s)</span>';
        echo '<span class="ptm-coverage-pill is-partial"><strong>'.esc_html($counts['partial']).'</strong> partiellement repéré(s)</span>';
        echo '<span class="ptm-coverage-pill is-verify"><strong>'.esc_html($counts['verify']).'</strong> à vérifier</span>';
        echo '<span class="ptm-coverage-pill is-missing"><strong>'.esc_html($counts['missing']).'</strong> non repéré(s)</span>';
        echo '</div>';
        echo '<div class="ptm-coverage-toolbar"><label>Afficher <select id="ptm-coverage-filter"><option value="all">Tout</option><option value="todo">À compléter / vérifier</option><option value="found">Repéré</option><option value="partial">Partiellement repéré</option><option value="verify">À vérifier</option><option value="missing">Non repéré</option></select></label><label>Trier <select id="ptm-coverage-sort"><option value="default">Ordre du parcours</option><option value="status">État</option><option value="label">Nom</option><option value="source">Page où repéré</option></select></label><button type="button" class="button" id="ptm-coverage-density">Affichage compact</button></div>';

        $groups = array(
            'organisation'=>array('label'=>'Votre organisation','ids'=>array('identity','contact','representative')),
            'uses'=>array('label'=>'Utilisation des données','ids'=>array('purposes','legal_basis','data_categories','retention','recipients','mandatory')),
            'flows'=>array('label'=>'Circulation des données','ids'=>array('source','transfers','automated')),
            'cookies'=>array('label'=>'Cookies et traceurs','ids'=>array('cookies','email_pixels')),
            'rights'=>array('label'=>'Droits des personnes','ids'=>array('rights','withdrawal','complaint','special_categories','criminal_data','minors')),
        );
        $by_id=array(); foreach((array)$audit['topics'] as $item){$by_id[$item['id']]=$item;}
        echo '<div id="ptm-coverage-list" class="ptm-coverage-list">';
        foreach($groups as $group){
            echo '<details class="ptm-coverage-group" open><summary>'.esc_html($group['label']).'</summary><div class="ptm-coverage-group-body">';
            foreach($group['ids'] as $id){
                if(empty($by_id[$id])){continue;}$item=$by_id[$id];
                $status=isset($item['status'])?$item['status']:(!empty($item['present'])?'found':'missing');
                if ( 'not_applicable' === $status ) { continue; }
                $status_label=array('found'=>'Repéré','partial'=>'Partiellement repéré','verify'=>'À vérifier','missing'=>'Non repéré');
                $where=!empty($item['where'])?$item['where']:'';
                $is_actionable = in_array( $status, array( 'partial','verify','missing' ), true );
                if ( $is_actionable ) {
                    echo '<a class="ptm-coverage-row ptm-coverage-link is-' . esc_attr( $status ) . '" href="' . esc_url( $this->assistant_url( $this->assistant_step_for_topic( $id ), $id ) ) . '" data-status="' . esc_attr( $status ) . '" data-label="' . esc_attr( $this->normalize_text( $item['label'] ) ) . '" data-source="' . esc_attr( $this->normalize_text( $where ) ) . '"><div><strong>' . esc_html( $item['label'] ) . '</strong>';
                } else {
                    echo '<article class="ptm-coverage-row is-' . esc_attr( $status ) . '" data-status="' . esc_attr( $status ) . '" data-label="' . esc_attr( $this->normalize_text( $item['label'] ) ) . '" data-source="' . esc_attr( $this->normalize_text( $where ) ) . '"><div><strong>' . esc_html( $item['label'] ) . '</strong>';
                }
                if($where){echo '<small>Repéré dans : '.esc_html($where).'</small>';}
                elseif('partial'===$status){echo '<small>Information enregistrée dans l’assistant mais pas encore repérée dans les pages analysées. Cliquez pour la compléter.</small>';}
                elseif('verify'===$status){echo '<small>Ce point dépend de votre situation : cliquez pour le vérifier.</small>';}
                elseif('missing'===$status){echo '<small>Cliquez pour ouvrir directement la partie correspondante de l’assistant.</small>';}
                echo '</div><span class="ptm-doc-status ' . esc_attr( $status ) . '">' . esc_html( $status_label[ $status ] ) . ( $is_actionable ? ' →' : '' ) . '</span>';
                if ( $is_actionable ) { echo '</a>'; } else { echo '</article>'; }
            }
            echo '</div></details>';
        }
        echo '<details class="ptm-coverage-group" open><summary>Services et traceurs actifs</summary><div class="ptm-coverage-group-body">';
        if(empty($audit['services'])){echo '<p class="ptm-muted">Aucun suivi actif confirmé à documenter.</p>';}
        foreach((array)$audit['services'] as $item){
            $status=!empty($item['mentioned'])?'found':'missing';$where=!empty($item['where'])?$item['where']:'';
            if ( 'found' === $status ) {
                echo '<article class="ptm-coverage-row is-found" data-status="found" data-label="'.esc_attr($this->normalize_text($item['label'])).'" data-source="'.esc_attr($this->normalize_text($where)).'"><div><strong>'.esc_html($item['label']).'</strong>'.($where?'<small>Repéré dans : '.esc_html($where).'</small>':'').'</div><span class="ptm-doc-status found">Repéré</span></article>';
            } else {
                echo '<a class="ptm-coverage-row ptm-coverage-link is-missing" href="'.esc_url($this->assistant_url('flows')).'" data-status="missing" data-label="'.esc_attr($this->normalize_text($item['label'])).'" data-source="'.esc_attr($this->normalize_text($where)).'"><div><strong>'.esc_html($item['label']).'</strong><small>Cliquez pour documenter ce service dans l’assistant.</small></div><span class="ptm-doc-status missing">Non repéré →</span></a>';
            }
        }
        echo '</div></details></div>';
        echo '<p class="ptm-muted">La couverture se recalcule après les sauvegardes de l’assistant, les analyses et les mises à jour de pages. Elle mesure ce que Dendrila Privacy a pu documenter, pas une conformité juridique.</p></section>';

        $actions = isset($audit['recommendations']) ? $audit['recommendations'] : array();
        if($actions){
            echo '<section class="ptm-card"><div class="ptm-card-head"><div><h2>Actions à exécuter</h2><p>Les actions déjà résolues disparaissent après recalcul.</p></div><span class="ptm-badge warn">'.count($actions).' à examiner</span></div><div class="ptm-action-rows">';
            foreach($actions as $action){
                $destination=$this->recommendation_destination($action);
                echo '<a class="ptm-action-row ptm-action-link" href="'.esc_url($destination).'"><span class="ptm-action-dot"></span><div><strong>'.esc_html($action['title']).'</strong><small>'.esc_html($action['detail']).'</small></div><span class="ptm-action-go"><span class="dashicons dashicons-arrow-right-alt2"></span></span></a>';
            }
            echo '</div></section>';
        }

        if(!empty($audit['stale_dendrila_privacy_services'])){echo '<div class="ptm-callout warn"><strong>Bloc géré à nettoyer :</strong> '.esc_html(implode(', ',wp_list_pluck($audit['stale_dendrila_privacy_services'],'label'))).'. Une synchronisation les retirera.</div>';}
        if(!empty($audit['stale_cookie_mentions'])){foreach($audit['stale_cookie_mentions'] as $stale_cookie){echo '<div class="ptm-callout warn"><strong>Politique de cookies à rafraîchir :</strong> '.esc_html($stale_cookie['label']).' est désactivé, mais d’anciennes mentions techniques restent présentes dans les documents analysés.</div>';}}

        echo '<section class="ptm-card ptm-start-assistant"><div class="ptm-card-head"><div><h2>Besoin de corriger une information ?</h2><p>L’assistant RGPD est accessible dans un onglet séparé. Les éléments manquants ou partiels ci-dessus y mènent directement.</p></div><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-assistant' ) ) . '">Ouvrir l’assistant RGPD</a></div></section>';
    }

    private function render_legal_assistant_tab( $settings, $audit ) {
        echo '<div class="ptm-assistant-tab-intro ptm-card"><div class="ptm-card-head"><div><h2>Assistant RGPD</h2><p>Complétez uniquement ce qui correspond à votre situation. Chaque bloc s’enregistre sans recharger toute la page ni relancer le scan.</p></div><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-privacy#ptm-coverage-list' ) ) . '">Voir la couverture documentaire</a></div></div>';
        $this->render_legal_wizard( $settings, is_array( $audit ) ? $audit : array() );
    }

    private function init_consent_manager() {
        $file = plugin_dir_path( __FILE__ ) . 'includes/class-pixel-trackers-manager-consent.php';
        if ( file_exists( $file ) ) {
            require_once $file;
            if ( class_exists( 'Pixel_Trackers_Manager_Consent' ) ) {
                Pixel_Trackers_Manager_Consent::instance( $this );
            }
        }
    }

    public function public_settings() { return $this->settings(); }
    public function public_scan() { return get_option( self::OPTION_SCAN, array() ); }
    public function public_cmp_names() {
        $scan = $this->public_scan();
        if ( empty( $scan['cmp'] ) || ! is_array( $scan['cmp'] ) ) { return array(); } $names = array(); foreach ( $scan['cmp'] as $cmp ) { if ( is_array($cmp) && !empty($cmp['name']) ) { $names[] = $cmp['name']; } elseif ( is_string($cmp) ) { $names[] = $cmp; } } return array_values(array_unique($names));
    }
    public function shortcode_consent_settings() {
        if ( empty( $this->settings()['consent_enabled'] ) ) { return ''; }
        return '<button type="button" class="ptm-consent-open">' . esc_html__( 'Gérer mes choix', 'dendrila-privacy' ) . '</button>';
    }

    private function render_consent_tab( $settings ) {
        $scan = get_option( self::OPTION_SCAN, array() );
        $cmps = $this->public_cmp_names();
        $optional_active = $this->scan_has_optional_tracking( $scan );
        echo '<section class="ptm-card ptm-consent-intro"><div class="ptm-card-head"><div><p class="ptm-eyebrow">Gestion du consentement</p><h2>' . ( $cmps ? 'Une solution existante a été détectée.' : 'Dendrila Privacy peut ajouter une barre ou un encart de consentement.' ) . '</h2><p>';
        if ( $cmps ) { echo 'Solution détectée : <strong>' . esc_html( implode( ', ', $cmps ) ) . '</strong>. Dendrila Privacy ne cherche pas à la remplacer : utilisez plutôt les contrôles ci-dessous pour vérifier son comportement.'; }
        else { echo 'Lorsqu’elle est activée, l’interface Dendrila Privacy bloque les services facultatifs connus avant le choix. « Tout accepter » et « Tout refuser » gardent le même poids visuel.'; }
        echo '</p></div></div>';
        if ( ! $cmps && $optional_active && empty( $settings['consent_enabled'] ) ) { echo '<div class="ptm-callout warn"><strong>Des services facultatifs ont été détectés, mais aucune interface de consentement connue n’est active.</strong> Vérifiez ce point ou configurez l’interface Dendrila Privacy.</div>'; }
        elseif ( ! $cmps && empty( $settings['consent_enabled'] ) ) { echo '<div class="ptm-callout neutral"><strong>Aucune solution connue détectée.</strong> Vous pouvez préparer Dendrila Privacy maintenant ; rien ne sera activé avant l’enregistrement explicite.</div>'; }
        $this->form_start('save_consent_settings');
        echo '<div class="ptm-consent-settings-grid">';
        echo '<label class="ptm-consent-toggle-card"><input type="checkbox" name="consent_enabled" value="1" ' . checked( ! empty( $settings['consent_enabled'] ), true, false ) . '><span><strong>Activer la gestion du consentement Dendrila Privacy</strong><small>Bloquer les services facultatifs reconnus avant le choix et afficher l’interface aux visiteurs.</small></span></label>';
        echo '<div class="ptm-consent-preview-options"><div><strong>Présentation</strong><p>Barre en bas ou encart centré.</p></div><label class="ptm-layout-choice"><input type="radio" name="consent_layout" value="bar" ' . checked( $settings['consent_layout'], 'bar', false ) . '><span class="ptm-layout-demo is-bar"><i></i><i></i></span><strong>Barre en bas</strong></label><label class="ptm-layout-choice"><input type="radio" name="consent_layout" value="card" ' . checked( $settings['consent_layout'], 'card', false ) . '><span class="ptm-layout-demo is-card"><i></i></span><strong>Encart centré</strong></label></div>';
        echo '<div class="ptm-form-grid"><label class="ptm-field"><span>Apparence</span><select name="consent_style"><option value="inherit" ' . selected( $settings['consent_style'], 'inherit', false ) . '>S’intégrer au style du site</option><option value="neutral" ' . selected( $settings['consent_style'], 'neutral', false ) . '>Style neutre Dendrila Privacy</option></select><small>Les couleurs de marque ne sont pas reprises lorsqu’elles créeraient une asymétrie Accepter / Refuser.</small></label><label class="ptm-field"><span>Mémoriser le choix</span><div><input type="number" min="30" max="365" name="consent_retention_days" value="' . esc_attr( (int) $settings['consent_retention_days'] ) . '"> jours</div><small>À expiration, l’interface est proposée à nouveau.</small></label></div>';
        echo '<label class="ptm-consent-toggle-card is-compact"><input type="checkbox" name="consent_footer_link" value="1" ' . checked( ! empty( $settings['consent_footer_link'] ), true, false ) . '><span><strong>Ajouter « Gérer mes choix » en bas du site</strong><small>Le code court <code>[dendrila_privacy_consent_settings]</code> reste disponible pour un emplacement personnalisé.</small></span></label>';
        echo '</div>'; submit_button( 'Enregistrer la gestion du consentement', 'primary' ); $this->form_end();
        echo '<div class="ptm-consent-test-actions"><strong>Tester dans votre navigateur d’administrateur</strong><p>Ces liens ne modifient pas le choix des visiteurs.</p><div><a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="' . esc_url( add_query_arg( 'pixel_trackers_manager_consent_preview', '1', home_url('/') ) ) . '">Voir sans choix ↗</a><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( add_query_arg( 'pixel_trackers_manager_consent_test', 'reject', home_url('/') ) ) . '">Simuler « Tout refuser » ↗</a><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( add_query_arg( 'pixel_trackers_manager_consent_test', 'statistics', home_url('/') ) ) . '">Statistiques seulement ↗</a><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( add_query_arg( 'pixel_trackers_manager_consent_test', 'accept', home_url('/') ) ) . '">Simuler « Tout accepter » ↗</a></div></div>';
        echo '</section>';
        $blocked_count = 0; $still_active = 0;
        foreach ( (array)( isset($scan['findings']) ? $scan['findings'] : array() ) as $finding ) {
            if ( !empty($finding['blocked_by_consent']) ) { $blocked_count++; }
            elseif ( !empty($finding['active_tracking']) && 'recaptcha' !== (isset($finding['id'])?$finding['id']:'') ) { $still_active++; }
        }
        if ( !empty($settings['consent_enabled']) && !empty($scan['generated_at']) ) {
            if ( $still_active ) { echo '<section class="ptm-card"><h2>Vérification avant choix</h2><div class="ptm-callout warn"><strong>'.esc_html($still_active).' service(s) facultatif(s) semblent encore partir avant le choix.</strong> Relancez l’analyse après vos corrections pour vérifier le blocage.</div></section>'; }
            elseif ( $blocked_count ) { echo '<section class="ptm-card"><h2>Vérification avant choix</h2><div class="ptm-callout good"><strong>'.esc_html($blocked_count).' service(s) repéré(s) sont neutralisés avant le choix.</strong> Cette vérification porte sur l’état initial vu par l’analyse automatique.</div></section>'; }
        }
        echo '<section class="ptm-card"><h2>Principes appliqués</h2><p>Rien de facultatif n’est chargé avant le choix lorsque Dendrila Privacy sait l’intercepter. Fermer l’interface ne vaut pas acceptation. Les catégories facultatives ne sont jamais pré-cochées et le visiteur peut rouvrir « Gérer mes choix ».</p></section>';
    }

    private function render_settings_tab( $settings ) {
        echo '<section class="ptm-card"><div class="ptm-card-head"><div><h2>Réglages</h2><p>Ces trois sélections servent de référence à l’analyse documentaire, aux actions et aux boutons d’édition.</p></div></div>';
        $this->form_start( 'save_settings' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Page de mentions légales</th><td>'; $this->page_select( (int)$settings['legal_notice_page_id'], 'legal_notice_page_id' ); echo '<p class="description">Dendrila Privacy essaie de la repérer automatiquement si aucune page n’a encore été choisie.</p></td></tr>';
        echo '<tr><th>Page de politique de confidentialité</th><td>'; $this->page_select( (int)$settings['privacy_page_id'], 'privacy_page_id' ); echo '</td></tr>';
        echo '<tr><th>Page cookies / traceurs</th><td>'; $this->page_select( (int)$settings['cookie_page_id'], 'cookie_page_id' ); echo '<p class="description">Cette page peut rester vide si votre site n’utilise pas de traceurs facultatifs ou si l’information est gérée autrement par votre gestionnaire de consentement (bannière cookies).</p></td></tr>';
        echo '<tr><th>Nombre maximal de pages — analyse standard</th><td><input type="number" min="5" max="50" name="scan_limit" value="' . esc_attr( (int) $settings['scan_limit'] ) . '"><p class="description">20 est un bon point de départ pour un contrôle courant.</p></td></tr>';
        echo '<tr><th>Nombre maximal de pages — analyse complète</th><td><input type="number" min="50" max="1000" name="full_scan_limit" value="' . esc_attr( (int) $settings['full_scan_limit'] ) . '"><p class="description">L’analyse complète avance par petits lots pour rester rapide sans surcharger l’hébergement. Limitez-le sur les très gros sites si nécessaire.</p></td></tr>';
        echo '<tr><th>Analyse planifiée</th><td><select name="schedule"><option value="off" ' . selected( $settings['schedule'], 'off', false ) . '>Désactivé</option><option value="daily" ' . selected( $settings['schedule'], 'daily', false ) . '>Quotidien</option><option value="weekly" ' . selected( $settings['schedule'], 'weekly', false ) . '>Hebdomadaire</option></select><p class="description">Le planificateur interne de WordPress dépend des visites du site : l’heure exacte peut légèrement varier.</p></td></tr>';
        echo '<tr><th>Présentation du consentement Dendrila Privacy</th><td><select name="consent_layout"><option value="bar" ' . selected( $settings['consent_layout'], 'bar', false ) . '>Barre en bas</option><option value="card" ' . selected( $settings['consent_layout'], 'card', false ) . '>Encart centré</option></select></td></tr>';
        echo '<tr><th>Synchronisation automatique</th><td><label><input type="checkbox" name="auto_sync_page" value="1" ' . checked( ! empty( $settings['auto_sync_page'] ), true, false ) . '> Après une analyse planifiée, mettre à jour le bloc géré sur la politique de confidentialité lorsqu’elle utilise l’éditeur WordPress classique</label></td></tr>';
        echo '</tbody></table>';
        submit_button( 'Enregistrer les réglages' ); $this->form_end(); echo '</section>';
        echo '<section class="ptm-card"><h2>Fonctionnement local</h2><p>Les analyses, réglages et le journal restent locaux. Seule la recherche facultative d’entreprise contacte le registre public de l’État, après clic explicite de l’administrateur. Les contenus des pages sont lus localement lorsque Dendrila Privacy reconnaît leur éditeur, et aucune insertion n’est effectuée sans clic explicite.</p><p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=pixel-trackers-manager-setup&ptm_setup_step=welcome' ) ) . '">Relancer l’assistant de configuration</a></p></section>';
    }

    private function render_journal_tab( $log ) {
        echo '<section class="ptm-card"><div class="ptm-card-head"><div><h2>Journal d’audit local</h2><p>Historique des dernières actions réalisées par Dendrila Privacy sur ce site.</p></div></div>';
        if ( ! $log ) { echo '<p>Aucune action enregistrée.</p></section>'; return; }
        echo '<div class="ptm-table-wrap"><table class="widefat striped"><thead><tr><th>Date UTC</th><th>Action</th><th>Origine</th><th>Détails</th></tr></thead><tbody>';
        foreach ( array_slice( $log, 0, 50 ) as $entry ) {
            echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td>' . esc_html( $entry['action'] ) . '</td><td>' . esc_html( $entry['origin'] ) . '</td><td><code>' . esc_html( wp_json_encode( $entry['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</code></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function page_select( $selected_id, $name = 'privacy_page_id', $allow_empty = true ) {
        $page_ids = get_posts( array(
            'post_type' => 'page',
            'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
            'numberposts' => 250,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
            'suppress_filters' => false,
        ) );
        echo '<select name="' . esc_attr($name) . '" class="ptm-page-select">';
        if ( $allow_empty ) { echo '<option value="0">— Aucune / à sélectionner —</option>'; }
        foreach ( $page_ids as $page_id ) {
            $page = get_post( $page_id );
            if ( ! $page ) { continue; }
            $label = $page->post_title ? $page->post_title : '(sans titre)';
            if ( 'publish' !== $page->post_status ) {
                $label .= ' [' . $page->post_status . ']';
            }
            echo '<option value="' . esc_attr( $page->ID ) . '" ' . selected( $selected_id, $page->ID, false ) . '>' . esc_html( $label ) . ' (#' . esc_html( $page->ID ) . ')</option>';
        }
        echo '</select>';
    }

    private function kpi( $label, $value, $note, $icon = 'chart-bar', $tone = 'neutral', $href = '' ) {
        if ( $href ) {
            echo '<a class="ptm-kpi ptm-tone-' . esc_attr( $tone ) . ' ptm-clickable-card" href="' . esc_url( $href ) . '">';
        } else {
            echo '<section class="ptm-kpi ptm-tone-' . esc_attr( $tone ) . '">';
        }
        echo '<span class="ptm-kpi-icon dashicons dashicons-' . esc_attr( $icon ) . '"></span><div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong><small>' . esc_html( $note ) . '</small></div>';
        if ( $href ) {
            echo '<span class="dashicons dashicons-arrow-right-alt2 ptm-card-arrow" aria-hidden="true"></span></a>';
        } else {
            echo '</section>';
        }
    }
}

register_activation_hook( __FILE__, array( 'Pixel_Trackers_Manager_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Pixel_Trackers_Manager_Plugin', 'deactivate' ) );
Pixel_Trackers_Manager_Plugin::instance();
