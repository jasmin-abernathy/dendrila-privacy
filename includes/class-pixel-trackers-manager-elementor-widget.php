<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( '\Elementor\Widget_Base' ) ) { return; }

class Pixel_Trackers_Manager_Elementor_Consent_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'pixel-trackers-manager-consent-settings'; }
    public function get_title() { return 'Pixel Trackers Manager — Gérer mes choix'; }
    public function get_icon() { return 'eicon-shield'; }
    public function get_categories() { return array( 'general' ); }
    public function get_keywords() { return array( 'cookies','consentement','confidentialité','privacy' ); }
    protected function render() { echo do_shortcode( '[pixel_trackers_manager_consent_settings]' ); }
}
