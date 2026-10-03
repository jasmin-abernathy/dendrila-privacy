<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'DENDRILA_PRIVACY_STANDALONE_TEST' ) ) { exit; }

final class Dendrila_Privacy_Consent_Core {
    public static function normalize_choice( $choice ) {
        if ( ! is_array( $choice ) ) {
            return null;
        }
        return array(
            'statistics' => ! empty( $choice['statistics'] ),
            'external' => ! empty( $choice['external'] ),
            'marketing' => ! empty( $choice['marketing'] ),
            'savedAt' => isset( $choice['savedAt'] ) ? max( 0, (int) $choice['savedAt'] ) : 0,
            'fingerprint' => isset( $choice['fingerprint'] ) ? (string) $choice['fingerprint'] : '',
        );
    }

    public static function choices_equal( $a, $b ) {
        $a = self::normalize_choice( $a );
        $b = self::normalize_choice( $b );
        if ( ! $a || ! $b ) {
            return false;
        }
        return $a['statistics'] === $b['statistics']
            && $a['external'] === $b['external']
            && $a['marketing'] === $b['marketing'];
    }

    public static function resolve_cross_device( $local, $account, $strategy ) {
        $local = self::normalize_choice( $local );
        $account = self::normalize_choice( $account );
        $strategy = 'device_wins' === $strategy ? 'device_wins' : 'account_wins';

        $result = array(
            'choice' => null,
            'write_local' => false,
            'sync_account' => false,
            'reason' => '',
        );

        if ( ! $local && ! $account ) {
            return $result;
        }
        if ( ! $local && $account ) {
            $result['choice'] = $account;
            $result['write_local'] = true;
            $result['reason'] = 'account_restored';
            return $result;
        }
        if ( $local && ! $account ) {
            $result['choice'] = $local;
            $result['sync_account'] = true;
            $result['reason'] = 'account_created';
            return $result;
        }
        if ( self::choices_equal( $local, $account ) ) {
            $result['choice'] = $local;
            return $result;
        }
        if ( 'device_wins' === $strategy ) {
            $result['choice'] = $local;
            $result['sync_account'] = true;
            $result['reason'] = 'device_wins';
            return $result;
        }
        $result['choice'] = $account;
        $result['write_local'] = true;
        $result['reason'] = 'account_wins';
        return $result;
    }

    public static function validate_email_evidence( $purpose, $decision, $basis, $notice, $context = array() ) {
        $purpose = (string) $purpose;
        $decision = (string) $decision;
        $basis = (string) $basis;
        $notice = trim( (string) $notice );
        $context = is_array( $context ) ? $context : array();
        $errors = array();

        if ( ! in_array( $purpose, array( 'email_open_measurement', 'email_delivery' ), true ) ) {
            $errors[] = 'Finalité inconnue.';
        }

        if ( 'consent' === $basis ) {
            if ( 'email_open_measurement' !== $purpose ) {
                $errors[] = 'Le consentement enregistré ici concerne la mesure des ouvertures.';
            }
            if ( ! in_array( $decision, array( 'granted', 'denied', 'withdrawn' ), true ) ) {
                $errors[] = 'Décision de consentement invalide.';
            }
            if ( '' === $notice ) {
                $errors[] = 'Conservez le texte présenté à la personne.';
            }
            return $errors;
        }

        if ( 'exempt_delivery' === $basis ) {
            if ( 'email_delivery' !== $purpose || 'recorded_exemption' !== $decision ) {
                $errors[] = 'L’exemption de délivrabilité doit rester limitée à cette finalité.';
            }
            if ( empty( $context['service_requested'] ) ) {
                $errors[] = 'Confirmez que le courriel se rattache à un service explicitement demandé par la personne.';
            }
            if ( empty( $context['last_open_date_only'] ) ) {
                $errors[] = 'Confirmez que la collecte est limitée, en principe, à la date de dernière ouverture et n’enregistre pas inutilement heure, IP ou user-agent.';
            }
            if ( empty( $context['inactive_list_cleanup'] ) ) {
                $errors[] = 'Confirmez que la mesure sert réellement à adapter la fréquence ou arrêter les envois aux destinataires inactifs.';
            }
            if ( empty( $context['exemption_note'] ) ) {
                $errors[] = 'Documentez les éléments techniques qui justifient l’exemption de délivrabilité.';
            }
            return $errors;
        }

        if ( 'legacy_no_objection' === $basis ) {
            $collection_date = isset( $context['collection_date'] ) ? (string) $context['collection_date'] : '';
            $notice_date = isset( $context['legacy_notice_date'] ) ? (string) $context['legacy_notice_date'] : '';
            if ( 'email_open_measurement' !== $purpose || 'legacy_no_objection' !== $decision ) {
                $errors[] = 'Le régime transitoire ne s’applique qu’au suivi d’ouverture concerné.';
            }
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $collection_date ) || $collection_date >= '2026-04-14' ) {
                $errors[] = 'Le régime transitoire exige une adresse collectée avant le 14 avril 2026.';
            }
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $notice_date ) ) {
                $errors[] = 'Indiquez la date à laquelle l’information permettant une opposition simple a été envoyée.';
            } elseif ( $notice_date > '2026-07-14' && empty( $context['legacy_extension_note'] ) ) {
                $errors[] = 'Une information envoyée après le 14 juillet 2026 exige de documenter les difficultés objectives justifiant une prolongation raisonnable.';
            }
            if ( empty( $context['legacy_conditions_unchanged'] ) ) {
                $errors[] = 'Confirmez que les conditions d’envoi sont restées inchangées et qu’aucun nouveau consentement à l’envoi n’a dû être recueilli.';
            }
            if ( '' === $notice ) {
                $errors[] = 'Conservez le texte d’information et d’opposition envoyé.';
            }
            return $errors;
        }

        $errors[] = 'Base déclarée inconnue.';
        return $errors;
    }

    public static function normalize_email( $email ) {
        return strtolower( trim( (string) $email ) );
    }

    public static function subject_hash( $subject, $secret ) {
        return hash_hmac( 'sha256', self::normalize_email( $subject ), (string) $secret );
    }

    private static function canonicalize( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( empty( $value ) ) {
            return array();
        }
        $keys = array_keys( $value );
        $is_list = $keys === range( 0, count( $value ) - 1 );
        if ( $is_list ) {
            $out = array();
            foreach ( $value as $item ) {
                $out[] = self::canonicalize( $item );
            }
            return $out;
        }
        ksort( $value, SORT_STRING );
        foreach ( $value as $key => $item ) {
            $value[ $key ] = self::canonicalize( $item );
        }
        return $value;
    }

    public static function event_hash( $event, $secret ) {
        if ( ! is_array( $event ) ) {
            return '';
        }
        unset( $event['event_hash'], $event['id'] );
        $json = json_encode( self::canonicalize( $event ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return false === $json ? '' : hash_hmac( 'sha256', $json, (string) $secret );
    }
}
