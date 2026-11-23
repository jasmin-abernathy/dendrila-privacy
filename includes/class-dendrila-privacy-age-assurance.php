<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Local companion check for age-assurance controls.
 *
 * This module never performs age verification itself. It only inspects local plugin
 * state and, when Dendrila Age Assurance is active, reads its deliberately minimal
 * public status contract.
 */
final class Dendrila_Privacy_Age_Assurance {

    public static function boot() {
        add_action( 'dendrila_privacy_overview_age_assurance', array( __CLASS__, 'render_overview_card' ) );
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    public static function normalize_companion_status( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();

        $mode = isset( $raw['mode'] ) ? strtolower( (string) $raw['mode'] ) : 'off';
        if ( ! in_array( $mode, array( 'off', 'selected', 'sitewide' ), true ) ) {
            $mode = 'off';
        }

        $assurance = isset( $raw['required_assurance'] ) ? strtoupper( (string) $raw['required_assurance'] ) : '';
        if ( ! in_array( $assurance, array( 'STANDARD', 'ENHANCED', 'STRICT' ), true ) ) {
            $assurance = '';
        }

        $threshold = isset( $raw['age_threshold'] ) ? (int) $raw['age_threshold'] : 0;
        $ttl       = isset( $raw['session_ttl_minutes'] ) ? (int) $raw['session_ttl_minutes'] : 0;
        $provider  = isset( $raw['provider'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $raw['provider'] ) ) : '';

        $enabled    = ! empty( $raw['enabled'] ) && 'off' !== $mode;
        $configured = ! empty( $raw['provider_configured'] );

        if ( $enabled && $configured ) {
            $state = 'good';
        } elseif ( $enabled && ! $configured ) {
            $state = 'bad';
        } else {
            $state = 'warn';
        }

        return array(
            'kind'                 => 'dendrila',
            'state'                => $state,
            'enabled'              => $enabled,
            'provider_configured'  => $configured,
            'mode'                 => $mode,
            'provider'             => $provider,
            'age_threshold'        => max( 0, $threshold ),
            'required_assurance'   => $assurance,
            'session_ttl_minutes'  => max( 0, $ttl ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function status() {
        if ( function_exists( 'dendrila_age_assurance_get_status' ) ) {
            $status = self::normalize_companion_status( dendrila_age_assurance_get_status() );
        } else {
            $other = self::detect_other_age_plugins();

            if ( $other ) {
                $status = array(
                    'kind'    => 'other',
                    'state'   => 'warn',
                    'plugins' => $other,
                );
            } else {
                $status = array(
                    'kind'  => 'none',
                    'state' => 'neutral',
                );
            }
        }

        /**
         * Filter the local age-assurance companion status shown by Dendrila Privacy.
         *
         * No external request is performed by this module.
         *
         * @param array<string,mixed> $status
         */
        $filtered = apply_filters( 'dendrila_privacy_age_assurance_status', $status );

        return is_array( $filtered ) ? $filtered : $status;
    }

    /**
     * @return array<int,array{name:string,slug:string}>
     */
    private static function detect_other_age_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all     = get_plugins();
        $active  = (array) get_option( 'active_plugins', array() );
        $network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
        $found   = array();

        foreach ( $all as $path => $data ) {
            if ( ! in_array( $path, $active, true ) && ! in_array( $path, $network, true ) ) {
                continue;
            }

            $name = isset( $data['Name'] ) ? (string) $data['Name'] : '';
            $dir  = dirname( $path );
            $slug = '.' === $dir ? basename( $path, '.php' ) : $dir;
            $hay  = strtolower( $name . ' ' . $slug );

            if (
                false === strpos( $hay, 'age gate' )
                && false === strpos( $hay, 'age-gate' )
                && false === strpos( $hay, 'age verification' )
                && false === strpos( $hay, 'age-verification' )
                && false === strpos( $hay, 'age assurance' )
                && false === strpos( $hay, 'age-assurance' )
            ) {
                continue;
            }

            $found[] = array(
                'name' => '' !== $name ? $name : $slug,
                'slug' => sanitize_title( $slug ),
            );
        }

        return $found;
    }

    public static function render_overview_card() {
        $status = self::status();

        echo '<section class="ptm-card ptm-age-assurance-card">';
        echo '<div class="ptm-card-head"><div><h2><span class="dashicons dashicons-lock"></span> ' . esc_html__( 'Age verification', 'dendrila-privacy' ) . '</h2><p>' . esc_html__( 'Local review of mechanisms that may restrict access to content based on age.', 'dendrila-privacy' ) . '</p></div>';

        if ( 'dendrila' === $status['kind'] ) {
            if ( 'good' === $status['state'] ) {
                echo '<span class="ptm-badge good">' . esc_html__( 'Protection active', 'dendrila-privacy' ) . '</span>';
            } elseif ( 'bad' === $status['state'] ) {
                echo '<span class="ptm-badge bad">' . esc_html__( 'Incomplete configuration', 'dendrila-privacy' ) . '</span>';
            } else {
                echo '<span class="ptm-badge warn">' . esc_html__( 'Disabled', 'dendrila-privacy' ) . '</span>';
            }
        } elseif ( 'other' === $status['kind'] ) {
            echo '<span class="ptm-badge warn">' . esc_html__( 'Needs review', 'dendrila-privacy' ) . '</span>';
        } else {
            echo '<span class="ptm-badge">' . esc_html__( 'Not detected', 'dendrila-privacy' ) . '</span>';
        }
        echo '</div>';

        if ( 'dendrila' === $status['kind'] ) {
            $mode_labels = array(
                'off'      => esc_html__( 'disabled', 'dendrila-privacy' ),
                'selected' => esc_html__( 'selected content', 'dendrila-privacy' ),
                'sitewide' => esc_html__( 'entire site', 'dendrila-privacy' ),
            );
            $mode_label = isset( $mode_labels[ $status['mode'] ] ) ? $mode_labels[ $status['mode'] ] : esc_html__( 'unknown', 'dendrila-privacy' );

            echo '<div class="ptm-age-assurance-summary">';
            echo '<div><strong>Dendrila Age Assurance</strong><span>' . esc_html__( 'Mode:', 'dendrila-privacy' ) . ' ' . esc_html( $mode_label ) . '</span></div>';
            echo '<div><strong>' . esc_html( $status['age_threshold'] ? $status['age_threshold'] . '+' : esc_html__( 'Unknown threshold', 'dendrila-privacy' ) ) . '</strong><span>' . esc_html__( 'Configured minimum age', 'dendrila-privacy' ) . '</span></div>';
            echo '<div><strong>' . esc_html( $status['required_assurance'] ? $status['required_assurance'] : '—' ) . '</strong><span>' . esc_html__( 'Minimum assurance level', 'dendrila-privacy' ) . '</span></div>';
            echo '</div>';

            if ( ! $status['provider_configured'] ) {
                echo '<p><strong>' . esc_html__( 'The verification provider is not configured yet.', 'dendrila-privacy' ) . '</strong> ' . esc_html__( 'Until this is fixed, Dendrila Privacy does not consider the control operational.', 'dendrila-privacy' ) . '</p>';
            } elseif ( ! $status['enabled'] ) {
                echo '<p>' . esc_html__( 'The provider is configured, but protection is disabled. This is not a problem if the site has no age-restricted content.', 'dendrila-privacy' ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'The companion plugin reports short-lived local proof and server-side checks. Review layers outside WordPress too, including directly accessible media and any cache or CDN.', 'dendrila-privacy' ) . '</p>';
            }

            echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=dendrila-age-assurance' ) ) . '">' . esc_html__( 'Open Age Assurance', 'dendrila-privacy' ) . '</a></p>';
        } elseif ( 'other' === $status['kind'] ) {
            $names = array();
            foreach ( (array) $status['plugins'] as $plugin ) {
                if ( ! empty( $plugin['name'] ) ) {
                    $names[] = (string) $plugin['name'];
                }
            }

            echo '<p><strong>' . esc_html__( 'Age-related mechanism detected:', 'dendrila-privacy' ) . ' ' . esc_html( implode( ', ', array_slice( $names, 0, 3 ) ) ) . '.</strong></p>';
            echo '<p>' . esc_html__( 'Dendrila Privacy cannot confirm whether this is proof of age, an estimation, or simply an age declaration. Check how it works and which requirements apply to this site.', 'dendrila-privacy' ) . '</p>';
            echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Review plugins', 'dendrila-privacy' ) . '</a></p>';
        } else {
            echo '<p>' . esc_html__( 'No recognized age verification mechanism is active.', 'dendrila-privacy' ) . ' <strong>' . esc_html__( 'This is not necessarily an issue', 'dendrila-privacy' ) . '</strong> : ' . esc_html__( 'this review only matters if the site offers age-restricted content, products or services.', 'dendrila-privacy' ) . '</p>';
        }

        echo '<p class="ptm-age-assurance-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> ' . esc_html__( 'This module only inspects local WordPress state and does not contact an age verification service.', 'dendrila-privacy' ) . '</p>';
        echo '</section>';
    }
}
