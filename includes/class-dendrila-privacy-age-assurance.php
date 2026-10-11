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
        echo '<div class="ptm-card-head"><div><h2><span class="dashicons dashicons-lock"></span> Vérification d’âge</h2><p>Contrôle local des mécanismes qui peuvent restreindre l’accès à des contenus selon l’âge.</p></div>';

        if ( 'dendrila' === $status['kind'] ) {
            if ( 'good' === $status['state'] ) {
                echo '<span class="ptm-badge good">Protection active</span>';
            } elseif ( 'bad' === $status['state'] ) {
                echo '<span class="ptm-badge bad">Configuration incomplète</span>';
            } else {
                echo '<span class="ptm-badge warn">Désactivée</span>';
            }
        } elseif ( 'other' === $status['kind'] ) {
            echo '<span class="ptm-badge warn">À vérifier</span>';
        } else {
            echo '<span class="ptm-badge">Non détectée</span>';
        }
        echo '</div>';

        if ( 'dendrila' === $status['kind'] ) {
            $mode_labels = array(
                'off'      => 'désactivée',
                'selected' => 'contenus sélectionnés',
                'sitewide' => 'site entier',
            );
            $mode_label = isset( $mode_labels[ $status['mode'] ] ) ? $mode_labels[ $status['mode'] ] : 'inconnue';

            echo '<div class="ptm-age-assurance-summary">';
            echo '<div><strong>Dendrila Age Assurance</strong><span>Mode : ' . esc_html( $mode_label ) . '</span></div>';
            echo '<div><strong>' . esc_html( $status['age_threshold'] ? $status['age_threshold'] . '+' : 'Seuil inconnu' ) . '</strong><span>Âge minimal configuré</span></div>';
            echo '<div><strong>' . esc_html( $status['required_assurance'] ? $status['required_assurance'] : '—' ) . '</strong><span>Niveau d’assurance minimal</span></div>';
            echo '</div>';

            if ( ! $status['provider_configured'] ) {
                echo '<p><strong>Le fournisseur de vérification n’est pas encore configuré.</strong> Tant que ce point n’est pas réglé, Dendrila Privacy ne considère pas le contrôle comme opérationnel.</p>';
            } elseif ( ! $status['enabled'] ) {
                echo '<p>Le fournisseur est configuré, mais la protection est désactivée. Ce n’est pas un problème si le site ne contient pas de contenu soumis à une restriction d’âge.</p>';
            } else {
                echo '<p>Le plugin compagnon annonce une preuve locale courte et un contrôle serveur. Vérifiez aussi les couches qui échappent à WordPress, notamment les médias directement publics et un éventuel cache/CDN.</p>';
            }

            echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=dendrila-age-assurance' ) ) . '">Ouvrir Age Assurance</a></p>';
        } elseif ( 'other' === $status['kind'] ) {
            $names = array();
            foreach ( (array) $status['plugins'] as $plugin ) {
                if ( ! empty( $plugin['name'] ) ) {
                    $names[] = (string) $plugin['name'];
                }
            }

            echo '<p><strong>Mécanisme lié à l’âge détecté : ' . esc_html( implode( ', ', array_slice( $names, 0, 3 ) ) ) . '.</strong></p>';
            echo '<p>Dendrila Privacy ne dispose pas d’un contrat technique lui permettant de confirmer s’il s’agit d’une preuve d’âge, d’une estimation ou d’une simple déclaration « J’ai 18 ans ». Vérifiez le fonctionnement réel et les exigences applicables au site.</p>';
            echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Examiner les extensions</a></p>';
        } else {
            echo '<p>Aucun mécanisme de vérification d’âge reconnu n’est actif. <strong>Ce constat n’est pas une anomalie en soi</strong> : ce contrôle devient pertinent uniquement si le site propose des contenus, produits ou services soumis à une restriction d’âge.</p>';
        }

        echo '<p class="ptm-age-assurance-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> Ce module observe uniquement l’état local de WordPress et n’effectue aucune requête vers un service de vérification d’âge.</p>';
        echo '</section>';
    }
}
