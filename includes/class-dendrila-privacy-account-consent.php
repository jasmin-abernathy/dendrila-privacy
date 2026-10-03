<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Privacy_Account_Consent {
    const USER_META = '_dendrila_privacy_account_consent';
    private static $instance = null;
    private $plugin;

    public static function instance( $plugin = null ) { if(null===self::$instance){self::$instance=new self($plugin);}elseif($plugin){self::$instance->plugin=$plugin;}return self::$instance; }
    private function __construct( $plugin ) { $this->plugin=$plugin; add_action('rest_api_init',array($this,'register_routes')); }
    private function settings() { return $this->plugin&&method_exists($this->plugin,'public_settings')?$this->plugin->public_settings():array(); }
    private function enabled() { $settings=$this->settings(); return !empty($settings['consent_account_sync']); }
    private function strategy() { $settings=$this->settings();$strategy=isset($settings['consent_account_conflict_policy'])?sanitize_key((string)$settings['consent_account_conflict_policy']):'account_wins';return in_array($strategy,array('account_wins','latest_wins'),true)?$strategy:'account_wins'; }
    private function server_milliseconds() { return sprintf('%.0f',floor(microtime(true)*1000)); }

    private function normalise_choice( $raw ) {
        $raw=is_array($raw)?$raw:array();$saved_at=isset($raw['savedAt'])?preg_replace('/[^0-9]/','',(string)$raw['savedAt']):'';
        if(!preg_match('/^[0-9]{10,16}$/',$saved_at)){$saved_at=$this->server_milliseconds();}$now=(float)$this->server_milliseconds();if((float)$saved_at>$now+300000){$saved_at=(string)$now;}
        return array('statistics'=>!empty($raw['statistics']),'external'=>!empty($raw['external']),'marketing'=>!empty($raw['marketing']),'savedAt'=>$saved_at,'fingerprint'=>isset($raw['fingerprint'])?substr(sanitize_text_field((string)$raw['fingerprint']),0,128):'');
    }

    public function current_choice( $user_id = 0 ) { $user_id=$user_id?absint($user_id):get_current_user_id();if(!$user_id){return null;}$stored=get_user_meta($user_id,self::USER_META,true);if(!is_array($stored)||empty($stored['savedAt'])){return null;}return $this->normalise_choice($stored); }
    public function frontend_config() { $enabled=$this->enabled();$logged_in=is_user_logged_in();$config=array('enabled'=>$enabled,'loggedIn'=>$logged_in,'strategy'=>$this->strategy(),'accountChoice'=>$enabled&&$logged_in?$this->current_choice():null,'endpoint'=>'','nonce'=>'');if($enabled&&$logged_in){$config['endpoint']=rest_url('dendrila-privacy/v1/account-consent');$config['nonce']=wp_create_nonce('wp_rest');}return $config; }

    public function register_routes() { register_rest_route('dendrila-privacy/v1','/account-consent',array(array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'rest_get'),'permission_callback'=>array($this,'rest_permission')),array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'rest_update'),'permission_callback'=>array($this,'rest_permission')))); }
    public function rest_permission() { return is_user_logged_in(); }
    public function rest_get() { if(!$this->enabled()){return new WP_Error('dendrila_privacy_account_sync_disabled','La synchronisation de consentement par compte est désactivée.',array('status'=>409));}return rest_ensure_response(array('choice'=>$this->current_choice(),'strategy'=>$this->strategy())); }
    private function evidence_decision($before,$after){$before_any=is_array($before)&&(!empty($before['statistics'])||!empty($before['external'])||!empty($before['marketing']));$after_count=(!empty($after['statistics'])?1:0)+(!empty($after['external'])?1:0)+(!empty($after['marketing'])?1:0);if($before_any&&0===$after_count){return 'withdrawn';}if(0===$after_count){return 'denied';}if(3===$after_count){return 'granted';}return 'partial';}
    private function evidence_purposes($choice){$purposes=array();if(!empty($choice['statistics'])){$purposes[]='Mesure d’audience';}if(!empty($choice['external'])){$purposes[]='Contenus externes';}if(!empty($choice['marketing'])){$purposes[]='Marketing et suivi';}return $purposes;}

    public function rest_update( WP_REST_Request $request ) {
        if(!$this->enabled()){return new WP_Error('dendrila_privacy_account_sync_disabled','La synchronisation de consentement par compte est désactivée.',array('status'=>409));}
        $params=$request->get_json_params();if(!is_array($params)){$params=$request->get_params();}$choice=$this->normalise_choice($params);if(''===$choice['fingerprint']){return new WP_Error('dendrila_privacy_account_sync_fingerprint','Empreinte de configuration manquante.',array('status'=>400));}
        $user_id=get_current_user_id();$before=$this->current_choice($user_id);update_user_meta($user_id,self::USER_META,$choice);$user=get_userdata($user_id);
        if($user&&!empty($user->user_email)&&function_exists('dendrila_privacy_record_consent_evidence')){dendrila_privacy_record_consent_evidence($user->user_email,'site_consent',$this->evidence_decision($before,$choice),$this->evidence_purposes($choice),array('notice_version'=>'consent-copy-1','source'=>'wordpress_account_sync','evidence_ref'=>'wordpress-account','method'=>'native_consent','metadata'=>array('statistics'=>!empty($choice['statistics'])?'1':'0','external'=>!empty($choice['external'])?'1':'0','marketing'=>!empty($choice['marketing'])?'1':'0','conflict_strategy'=>$this->strategy())));}
        do_action('dendrila_privacy_account_consent_updated',$user_id,$choice,$before);return rest_ensure_response(array('choice'=>$choice,'strategy'=>$this->strategy()));
    }
}
