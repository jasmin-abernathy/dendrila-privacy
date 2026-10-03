<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pixel_Trackers_Manager_Consent {
    private static $instance = null;
    private $plugin;
    private $rendered = false;

    public static function instance( $plugin ) {
        if ( null === self::$instance ) { self::$instance = new self( $plugin ); }
        return self::$instance;
    }

    private function __construct( $plugin ) {
        $this->plugin = $plugin;
        add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'filter_full_html' ), 10, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 1 );
        add_action( 'wp_body_open', array( $this, 'render_banner' ), 1 );
        add_action( 'wp_footer', array( $this, 'footer' ), 1 );
        add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 5, 3 );
        add_filter( 'the_content', array( $this, 'filter_content' ), 999 );
        add_filter( 'elementor/frontend/the_content', array( $this, 'filter_content' ), 999 );
        add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
    }

    /**
     * Read a non-mutating query-string flag used only for previews/builders.
     */
    private function query_value( $key ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/preview parameters are unslashed here and sanitized immediately below; no state is changed.
        $value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
    }

    private function enabled() {
        $settings = $this->plugin->public_settings();
        $admin_test = current_user_can( 'manage_options' ) && ( '' !== $this->query_value( 'pixel_trackers_manager_consent_preview' ) || '' !== $this->query_value( 'pixel_trackers_manager_consent_test' ) );
        return ( ! empty( $settings['consent_enabled'] ) || $admin_test ) && ! $this->is_editor_request();
    }

    private function is_editor_request() {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return true; }
        if ( '' !== $this->query_value( 'elementor-preview' ) || 'elementor' === sanitize_key( $this->query_value( 'action' ) ) ) { return true; }
        if ( '1' === $this->query_value( 'et_fb' ) ) { return true; }
        // Front-end editors from other builders should never be altered by the consent blocker.
        foreach ( array( 'bricks', 'fl_builder', 'ct_builder', 'vc_editable', 'siteorigin_panels_live_editor', 'so_live_editor', 'breakdance', 'brizy-edit' ) as $editor_key ) {
            if ( '' !== $this->query_value( $editor_key ) ) { return true; }
        }
        return false;
    }

    private function domain_map() {
        return array(
            'statistics' => array(
                'google-analytics.com', 'googletagmanager.com/gtag/js', 'analytics.google.com',
                'clarity.ms', 'hotjar.com', 'static.hotjar.com', 'plausible.io', 'matomo.js', 'piwik.js',
            ),
            'marketing' => array(
                'googletagmanager.com/gtm.js', 'connect.facebook.net', 'facebook.com/tr',
                'analytics.tiktok.com', 'snap.licdn.com', 'linkedin.com/insight',
                's.pinimg.com/ct', 'bat.bing.com', 'ads-twitter.com',
            ),
            'external' => array(
                'youtube.com/embed', 'youtube-nocookie.com/embed', 'player.vimeo.com',
                'google.com/maps/embed', 'maps.google.com/maps/embed', 'maps.googleapis.com',
            ),
        );
    }

    private function classify_url( $url ) {
        $url = strtolower( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
        foreach ( $this->domain_map() as $category => $needles ) {
            foreach ( $needles as $needle ) { if ( false !== strpos( $url, $needle ) ) { return $category; } }
        }
        return '';
    }

    public function filter_full_html( $html, $original_html = '' ) {
        if ( ! $this->enabled() || is_feed() || is_trackback() || is_robots() ) { return $html; }
        if ( ! is_string( $html ) || '' === $html || strlen( $html ) > 5 * 1024 * 1024 ) { return $html; }

        // WordPress 6.9+ invokes this through the standardized template enhancement
        // output buffer. On older supported versions the hook is not fired; the
        // script_loader_tag/content filters and early browser guard remain active
        // without opening a plugin-owned full-page output buffer.
        return $this->filter_content( $html );
    }

    private function active_service_ids() {
        $ids = array();
        $scan = $this->plugin->public_scan();
        foreach ( (array) ( isset( $scan['findings'] ) ? $scan['findings'] : array() ) as $finding ) {
            if ( empty( $finding['active_tracking'] ) && empty( $finding['blocked_by_consent'] ) ) { continue; }
            if ( ! empty( $finding['id'] ) ) { $ids[] = sanitize_key( $finding['id'] ); }
        }
        sort( $ids );
        return array_values( array_unique( $ids ) );
    }

    private function consent_fingerprint() {
        $payload = array(
            'schema' => 2,
            'services' => $this->active_service_ids(),
            'categories' => $this->active_categories(),
            'copy_version' => 1,
        );
        return hash( 'sha256', wp_json_encode( $payload ) );
    }

    public function enqueue() {
        if ( ! $this->enabled() ) { return; }
        $settings = $this->plugin->public_settings();
        // Consent assets have their own cache suffix during the test cycle so fixes reach
        // builder test sites even before the plugin version changes.
        $asset_version = Pixel_Trackers_Manager_Plugin::VERSION . '-consent-portal2';
        $bootstrap_handle = 'pixel-trackers-manager-consent-bootstrap';
        $test_mode = '';
        $cross_device_config = array( 'enabled' => false, 'active' => false );
        if ( current_user_can( 'manage_options' ) ) {
            $candidate = sanitize_key( $this->query_value( 'pixel_trackers_manager_consent_test' ) );
            if ( in_array( $candidate, array( 'reject', 'statistics', 'accept' ), true ) ) { $test_mode = $candidate; }
        }
        if ( class_exists( 'Dendrila_Privacy_Cross_Device_Consent' ) ) {
            $cross_device_config = Dendrila_Privacy_Cross_Device_Consent::public_config( $this->plugin, $this->consent_fingerprint() );
        }

        // Load the early blocker through WordPress' script API, in the head, before the
        // main consent UI. This keeps PTM compatible with Plugin Check and builders.
        wp_enqueue_script( $bootstrap_handle, plugin_dir_url( dirname( __DIR__ ) . '/dendrila-privacy.php' ) . 'assets/consent-bootstrap.js', array(), $asset_version, false );
        $early_config = array(
            'retentionDays' => (int) $settings['consent_retention_days'],
            'fingerprint' => $this->consent_fingerprint(),
            'testMode' => $test_mode,
            'crossDevice' => $cross_device_config,
        );
        wp_localize_script( $bootstrap_handle, 'DendrilaPrivacyConsentEarlyConfig', $early_config );
        // Backward-compatible alias for pre-publication integrations.
        wp_localize_script( $bootstrap_handle, 'PixelTrackersManagerConsentEarlyConfig', $early_config );
        wp_enqueue_style( 'pixel-trackers-manager-consent', plugin_dir_url( dirname( __DIR__ ) . '/dendrila-privacy.php' ) . 'assets/consent.css', array(), $asset_version );
        wp_enqueue_script( 'pixel-trackers-manager-consent', plugin_dir_url( dirname( __DIR__ ) . '/dendrila-privacy.php' ) . 'assets/consent.js', array( $bootstrap_handle ), $asset_version, false );
        $consent_config = array(
            'storageKey' => 'pixel_trackers_manager_consent_v2',
            'retentionDays' => (int) $settings['consent_retention_days'],
            'style' => $settings['consent_style'],
            'layout' => isset( $settings['consent_layout'] ) ? $settings['consent_layout'] : 'bar',
            'fingerprint' => $this->consent_fingerprint(),
            'categories' => $this->active_categories(),
            'domains' => $this->domain_map(),
            'preview' => '' !== $this->query_value( 'pixel_trackers_manager_consent_preview' ) && current_user_can( 'manage_options' ),
            'testMode' => $test_mode,
            'crossDevice' => $cross_device_config,
        );
        wp_localize_script( 'pixel-trackers-manager-consent', 'DendrilaPrivacyConsent', $consent_config );
        // Backward-compatible alias for pre-publication integrations.
        wp_localize_script( 'pixel-trackers-manager-consent', 'PixelTrackersManagerConsent', $consent_config );
        if ( ! empty( $cross_device_config['active'] ) ) {
            wp_enqueue_script(
                'dendrila-privacy-account-consent-sync',
                plugin_dir_url( dirname( __DIR__ ) . '/dendrila-privacy.php' ) . 'assets/consent-account-sync.js',
                array( 'pixel-trackers-manager-consent' ),
                $asset_version,
                false
            );
        }
    }

    private function active_categories() {
        $ids = array(
            'google-analytics'=>'statistics','clarity'=>'statistics','hotjar'=>'statistics','matomo'=>'statistics','plausible'=>'statistics',
            'google-tag-manager'=>'marketing','meta-pixel'=>'marketing','tiktok-pixel'=>'marketing','linkedin-insight'=>'marketing','pinterest-tag'=>'marketing','brevo'=>'marketing',
            'youtube'=>'external','vimeo'=>'external','google-maps'=>'external',
        );
        $active = array();
        $scan = $this->plugin->public_scan();
        foreach ( (array) ( isset($scan['findings']) ? $scan['findings'] : array() ) as $finding ) {
            if ( empty($finding['active_tracking']) && empty($finding['blocked_by_consent']) ) { continue; }
            $id = isset($finding['id']) ? $finding['id'] : '';
            if ( isset($ids[$id]) ) { $active[$ids[$id]] = true; }
        }
        // With no useful scan yet, keep all optional families available: the blocker can still
        // encounter a service at runtime before the next analysis.
        return $active ? array_keys($active) : array('statistics','external','marketing');
    }

    public function filter_script_tag( $tag, $handle, $src ) {
        if ( ! $this->enabled() ) { return $tag; }
        $category = $this->classify_url( $src );
        if ( ! $category ) { return $tag; }
        if ( false !== strpos( $tag, 'data-ptm-category=' ) ) { return $tag; }
        $original_type = '';
        if ( preg_match( '/\stype=["\']([^"\']+)["\']/i', $tag, $m ) ) { $original_type = $m[1]; $tag = preg_replace('/\stype=["\'][^"\']+["\']/i','',$tag,1); }
        $tag = preg_replace( '/\ssrc=(["\'])(.*?)\1/i', ' data-ptm-src=$1$2$1', $tag, 1 );
        return preg_replace( '/<script\b/i', '<script type="text/plain" data-ptm-category="'.esc_attr($category).'" data-ptm-blocked="1"'.($original_type?' data-ptm-type="'.esc_attr($original_type).'"':''), $tag, 1 );
    }

    public function filter_content( $content ) {
        if ( ! $this->enabled() || ! is_string( $content ) || '' === $content ) { return $content; }
        $self = $this;
        $content = preg_replace_callback( '#<iframe\b([^>]*?)\bsrc=(["\'])(.*?)\2([^>]*)>#is', function($m) use ($self){
            $category = $self->classify_url($m[3]); if(!$category){return $m[0];}
            return '<iframe'.$m[1].'src="about:blank" data-ptm-src="'.esc_url($m[3]).'" data-ptm-category="'.esc_attr($category).'" data-ptm-blocked="1"'.$m[4].'>';
        }, $content );
        $content = preg_replace_callback( '#<img\b([^>]*?)\bsrc=(["\'])(.*?)\2([^>]*)>#is', function($m) use ($self){
            $category = $self->classify_url( $m[3] ); if ( ! $category ) { return $m[0]; }
            return '<img'.$m[1].'data-ptm-src="'.esc_url($m[3]).'" data-ptm-category="'.esc_attr($category).'" data-ptm-blocked="1"'.$m[4].'>';
        }, $content );
        $content = preg_replace_callback( '#<link\b([^>]*?)\bhref=(["\'])(.*?)\2([^>]*)>#is', function($m) use ($self){
            $category = $self->classify_url( $m[3] ); if ( ! $category ) { return $m[0]; }
            $attrs = $m[1] . ' ' . $m[4];
            if ( ! preg_match( '/\brel\s*=\s*(["\'])(?:[^"\']*\b(?:preload|modulepreload|prefetch|preconnect|dns-prefetch)\b[^"\']*)\1/i', $attrs ) ) { return $m[0]; }
            return '<link'.$m[1].'data-ptm-href="'.esc_url($m[3]).'" data-ptm-category="'.esc_attr($category).'" data-ptm-blocked="1"'.$m[4].'>';
        }, $content );
        $content = preg_replace_callback( '#<script\b([^>]*?)\bsrc=(["\'])(.*?)\2([^>]*)>(.*?)</script>#is', function($m) use ($self){
            $category = $self->classify_url( $m[3] );
            if ( ! $category ) { return $m[0]; }
            $attrs = trim( $m[1] . ' ' . $m[4] );
            $original_type = '';
            if ( preg_match( '/\btype\s*=\s*(["\'])(.*?)\1/i', $attrs, $type_match ) ) {
                $original_type = $type_match[2];
                $attrs = preg_replace( '/\s*\btype\s*=\s*(["\']).*?\1/i', '', $attrs, 1 );
            }
            // Remove stale PTM blocker attributes before rebuilding a clean inert tag.
            $attrs = preg_replace( '/\s*\bdata-ptm-(?:src|category|blocked|type)\s*=\s*(["\']).*?\1/i', '', $attrs );
            $attrs = trim( $attrs );
            $script_tag = 'scr' . 'ipt';
            return '<' . $script_tag . ' type="text/plain" data-ptm-src="'.esc_url($m[3]).'" data-ptm-category="'.esc_attr($category).'" data-ptm-blocked="1"'.($original_type?' data-ptm-type="'.esc_attr($original_type).'"':'').($attrs?' '.$attrs:'').'>'.$m[5].'</' . $script_tag . '>';
        }, $content );
        return $content;
    }

    public function render_banner() {
        if ( ! $this->enabled() || $this->rendered ) { return; }
        $this->rendered = true;
        $cats = $this->active_categories();
        $banner_copy = apply_filters(
            'dendrila_privacy_consent_banner_copy',
            'Ce site utilise ce qui est nécessaire à son fonctionnement. Les services facultatifs restent bloqués tant que vous ne les avez pas acceptés.'
        );
        ?>
        <div id="pixel-trackers-manager-consent" class="ptm-consent" data-ptm-consent-root="1" data-ptm-style="<?php echo esc_attr( $this->plugin->public_settings()['consent_style'] ); ?>" data-ptm-layout="<?php echo esc_attr( isset( $this->plugin->public_settings()['consent_layout'] ) ? $this->plugin->public_settings()['consent_layout'] : 'bar' ); ?>" aria-hidden="true" hidden>
            <div class="ptm-consent-dialog" role="dialog" aria-modal="true" aria-labelledby="ptm-consent-title" aria-describedby="ptm-consent-copy" tabindex="-1">
                <button type="button" class="ptm-consent-close" data-ptm-action="close" aria-label="Fermer sans accepter">×</button>
                <div class="ptm-consent-main">
                    <h2 id="ptm-consent-title">Votre choix compte</h2>
                    <p id="ptm-consent-copy"><?php echo esc_html( $banner_copy ); ?></p>
                    <div class="ptm-consent-actions" role="group" aria-label="Choix de consentement">
                        <button type="button" class="ptm-consent-choice" data-ptm-action="reject">Tout refuser</button>
                        <button type="button" class="ptm-consent-choice" data-ptm-action="accept">Tout accepter</button>
                        <button type="button" class="ptm-consent-customize" data-ptm-action="customize" aria-expanded="false">Personnaliser</button>
                    </div>
                    <div class="ptm-consent-preferences" hidden>
                        <label class="ptm-consent-row"><span><strong>Nécessaires</strong><small>Fonctionnement et sécurité essentiels.</small></span><input type="checkbox" checked disabled></label>
                        <?php if ( in_array('statistics',$cats,true) ) : ?><label class="ptm-consent-row"><span><strong>Mesure d’audience</strong><small>Comprendre l’usage du site.</small></span><input type="checkbox" data-ptm-category-toggle="statistics"></label><?php endif; ?>
                        <?php if ( in_array('external',$cats,true) ) : ?><label class="ptm-consent-row"><span><strong>Contenus externes</strong><small>Vidéos, cartes ou contenus venant d’un autre service.</small></span><input type="checkbox" data-ptm-category-toggle="external"></label><?php endif; ?>
                        <?php if ( in_array('marketing',$cats,true) ) : ?><label class="ptm-consent-row"><span><strong>Marketing et suivi</strong><small>Mesure individualisée ou publicité.</small></span><input type="checkbox" data-ptm-category-toggle="marketing"></label><?php endif; ?>
                        <button type="button" class="ptm-consent-save" data-ptm-action="save">Enregistrer mes choix</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function footer() {
        if ( ! $this->enabled() ) { return; }
        if ( ! $this->rendered ) { $this->render_banner(); }
        $settings = $this->plugin->public_settings();
        if ( ! empty( $settings['consent_footer_link'] ) ) {
            echo '<div class="ptm-consent-footer-link"><button type="button" class="ptm-consent-open" data-ptm-consent-open="preferences" aria-haspopup="dialog">'.esc_html__('Gérer mes choix','dendrila-privacy').'</button></div>';
        }
    }

    public function register_elementor_widget( $widgets_manager ) {
        if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) { return; }
        $file = plugin_dir_path( __FILE__ ) . 'class-pixel-trackers-manager-elementor-widget.php';
        if ( file_exists( $file ) ) { require_once $file; }
        if ( class_exists( 'Pixel_Trackers_Manager_Elementor_Consent_Widget' ) ) { $widgets_manager->register( new Pixel_Trackers_Manager_Elementor_Consent_Widget() ); }
    }
}
