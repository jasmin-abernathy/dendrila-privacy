<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Privacy_Account_Consent {
    const USER_META = '_dendrila_privacy_account_consent';
    const USER_SYNC_META = '_dendrila_privacy_account_sync_enabled';
    private static $instance = null;
    private $plugin;

    public static function instance( $plugin = null ) { if(null===self::$instance){self::$instance=new self($plugin);}elseif($plugin){self::$instance->plugin=$plugin;}return self::$instance; }
    private function __construct( $plugin ) { $this->plugin=$plugin; add_action('rest_api_init',array($this,'register_routes')); }
    private function settings() { return $this->plugin&&method_exists($this->plugin,'public_settings')?$this->plugin->public_settings():array(); }
    private function enabled() { $settings=$this->settings(); return !empty($settings['consent_account_sync']); }
    private function strategy() { $settings=$this->settings();$strategy=isset($settings['consent_account_conflict_policy'])?sanitize_key((string)$settings['consent_account_conflict_policy']):'ask_user';return in_array($strategy,array('ask_user','account_wins','latest_wins'),true)?$strategy:'ask_user'; }
    public function user_sync_enabled( $user_id = 0 ) { $user_id=$user_id?absint($user_id):get_current_user_id();if(!$user_id){return false;}$stored=get_user_meta($user_id,self::USER_SYNC_META,true);if('1'===$stored){return true;}if('0'===$stored){return false;}$legacy=get_user_meta($user_id,self::USER_META,true);return is_array($legacy)&&!empty($legacy['savedAt']); }
    private function server_milliseconds() { return sprintf('%.0f',floor(microtime(true)*1000)); }
    private function expected_fingerprint() { if(!class_exists('Pixel_Trackers_Manager_Consent')){return '';}return (string)Pixel_Trackers_Manager_Consent::instance($this->plugin)->public_fingerprint(); }

    private function normalise_choice( $raw ) {
        $raw=is_array($raw)?$raw:array();$saved_at=isset($raw['savedAt'])?preg_replace('/[^0-9]/','',(string)$raw['savedAt']):'';
        if(!preg_match('/^[0-9]{10,16}$/',$saved_at)){$saved_at=$this->server_milliseconds();}$now=(float)$this->server_milliseconds();if((float)$saved_at>$now+300000){$saved_at=(string)$now;}
        return array('statistics'=>!empty($raw['statistics']),'external'=>!empty($raw['external']),'marketing'=>!empty($raw['marketing']),'savedAt'=>$saved_at,'fingerprint'=>isset($raw['fingerprint'])?substr(sanitize_text_field((string)$raw['fingerprint']),0,128):'');
    }

    public function current_choice( $user_id = 0 ) { $user_id=$user_id?absint($user_id):get_current_user_id();if(!$user_id){return null;}$stored=get_user_meta($user_id,self::USER_META,true);if(!is_array($stored)||empty($stored['savedAt'])){return null;}return $this->normalise_choice($stored); }
    public function frontend_config() { $enabled=$this->enabled();$logged_in=is_user_logged_in();$user_enabled=$enabled&&$logged_in?$this->user_sync_enabled():false;$config=array('enabled'=>$enabled,'loggedIn'=>$logged_in,'userEnabled'=>$user_enabled,'strategy'=>$this->strategy(),'accountChoice'=>$user_enabled?$this->current_choice():null,'endpoint'=>'','toggleEndpoint'=>'','nonce'=>'');if($enabled&&$logged_in){$config['endpoint']=rest_url('dendrila-privacy/v1/account-consent');$config['toggleEndpoint']=rest_url('dendrila-privacy/v1/account-consent-sync');$config['nonce']=wp_create_nonce('wp_rest');}return $config; }

    public function register_routes() { register_rest_route('dendrila-privacy/v1','/account-consent',array(array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'rest_get'),'permission_callback'=>array($this,'rest_permission')),array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'rest_update'),'permission_callback'=>array($this,'rest_permission'))));register_rest_route('dendrila-privacy/v1','/account-consent-sync',array('methods'=>WP_REST_Server::EDITABLE,'callback'=>array($this,'rest_sync_update'),'permission_callback'=>array($this,'rest_permission'))); }
    public function rest_permission() { return is_user_logged_in(); }
    public function rest_get() { if(!$this->enabled()){return new WP_Error('dendrila_privacy_account_sync_disabled','La synchronisation de consentement par compte est désactivée.',array('status'=>409));}$user_enabled=$this->user_sync_enabled();return rest_ensure_response(array('choice'=>$user_enabled?$this->current_choice():null,'strategy'=>$this->strategy(),'userEnabled'=>$user_enabled)); }
    public function rest_sync_update( WP_REST_Request $request ) { if(!$this->enabled()){return new WP_Error('dendrila_privacy_account_sync_disabled','La synchronisation de consentement par compte est désactivée.',array('status'=>409));}$params=$request->get_json_params();if(!is_array($params)){$params=$request->get_params();}if(!is_array($params)||!array_key_exists('enabled',$params)){return new WP_Error('dendrila_privacy_account_sync_missing_state','État de synchronisation manquant.',array('status'=>400));}$enabled=rest_sanitize_boolean($params['enabled']);$user_id=get_current_user_id();update_user_meta($user_id,self::USER_SYNC_META,$enabled?'1':'0');if(!$enabled){delete_user_meta($user_id,self::USER_META);}do_action('dendrila_privacy_account_sync_toggled',$user_id,$enabled);return rest_ensure_response(array('userEnabled'=>$enabled,'choice'=>$enabled?$this->current_choice($user_id):null,'strategy'=>$this->strategy())); }
    private function evidence_decision($before,$after){$before_any=is_array($before)&&(!empty($before['statistics'])||!empty($before['external'])||!empty($before['marketing']));$after_count=(!empty($after['statistics'])?1:0)+(!empty($after['external'])?1:0)+(!empty($after['marketing'])?1:0);if($before_any&&0===$after_count){return 'withdrawn';}if(0===$after_count){return 'denied';}if(3===$after_count){return 'granted';}return 'partial';}
    private function evidence_purposes($choice){$purposes=array();if(!empty($choice['statistics'])){$purposes[]='Mesure d’audience';}if(!empty($choice['external'])){$purposes[]='Contenus externes';}if(!empty($choice['marketing'])){$purposes[]='Marketing et suivi';}return $purposes;}

    public function rest_update( WP_REST_Request $request ) {
        if(!$this->enabled()){return new WP_Error('dendrila_privacy_account_sync_disabled','La synchronisation de consentement par compte est désactivée.',array('status'=>409));}
        if(!$this->user_sync_enabled()){return new WP_Error('dendrila_privacy_account_sync_user_disabled','La synchronisation de consentement est désactivée pour ce compte.',array('status'=>409));}
        $params=$request->get_json_params();if(!is_array($params)){$params=$request->get_params();}$choice=$this->normalise_choice($params);$expected=$this->expected_fingerprint();
        if(''===$choice['fingerprint']||''===$expected||!hash_equals($expected,$choice['fingerprint'])){return new WP_Error('dendrila_privacy_account_sync_fingerprint','La configuration de consentement a changé. Un nouveau choix est nécessaire.',array('status'=>409));}
        $reason=isset($params['syncReason'])?sanitize_key((string)$params['syncReason']):'background';$user_id=get_current_user_id();$before=$this->current_choice($user_id);
        if('latest_wins'===$this->strategy()&&'explicit'!==$reason&&$before&&((float)$choice['savedAt']<=(float)$before['savedAt'])){return rest_ensure_response(array('choice'=>$before,'strategy'=>$this->strategy(),'ignored'=>'older_choice'));}
        $choice['savedAt']=$this->server_milliseconds();update_user_meta($user_id,self::USER_META,$choice);$user=get_userdata($user_id);
        if('explicit'===$reason&&$user&&!empty($user->user_email)&&function_exists('dendrila_privacy_record_consent_evidence')){dendrila_privacy_record_consent_evidence($user->user_email,'site_consent',$this->evidence_decision($before,$choice),$this->evidence_purposes($choice),array('notice_version'=>'consent-copy-1','source'=>'wordpress_account_sync','evidence_ref'=>'wordpress-account','method'=>'native_consent','metadata'=>array('statistics'=>!empty($choice['statistics'])?'1':'0','external'=>!empty($choice['external'])?'1':'0','marketing'=>!empty($choice['marketing'])?'1':'0','conflict_strategy'=>$this->strategy(),'sync_reason'=>$reason)));}
        do_action('dendrila_privacy_account_consent_updated',$user_id,$choice,$before);return rest_ensure_response(array('choice'=>$choice,'strategy'=>$this->strategy()));
    }
}
