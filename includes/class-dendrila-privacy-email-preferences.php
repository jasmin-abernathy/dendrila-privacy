<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Privacy_Email_Preferences {
    const QUERY_KEY = 'dendrila_privacy_email_preferences';
    const NOTICE_VERSION = 'email-preferences-2026-1';
    const DEFAULT_TTL = 1209600;
    const MAX_TTL = 2592000;
    private static $instance = null;

    public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }
    private function __construct() { add_action( 'template_redirect', array( $this, 'maybe_render_public_page' ), 0 ); add_action( 'dendrila_privacy_evidence_after_lookup', array( $this, 'render_admin_test_link' ), 10, 2 ); }

    private function purposes_catalogue() {
        return array(
            'email_open_measurement'=>array('label'=>'Mesure des ouvertures','description'=>'Autorise un pixel individuel à indiquer si ce message a été ouvert pour mesurer les performances de l’envoi.'),
            'email_click_tracking'=>array('label'=>'Suivi individualisé des clics','description'=>'Autorise l’association des clics sur les liens de ce message à votre destinataire. Ce réglage est distinct du pixel d’ouverture.'),
        );
    }

    private function normalise_purposes( $purposes ) {
        $catalogue=$this->purposes_catalogue();$clean=array();
        foreach((array)$purposes as $purpose){$purpose=sanitize_key((string)$purpose);if(isset($catalogue[$purpose])){$clean[]=$purpose;}}
        return array_values(array_unique($clean));
    }

    private function crypto_key(){return hash_hmac('sha256','dendrila-privacy-email-preferences-v1',wp_salt('auth'),true);}
    private function b64url_encode($value){return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private function b64url_decode($value){if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]+$/',$value)){return false;}$padding=strlen($value)%4;if($padding){$value.=str_repeat('=',4-$padding);}return base64_decode(strtr($value,'-_','+/'),true);}

    private function encrypt_payload($payload){
        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($json)||''===$json){return new WP_Error('dendrila_privacy_preference_token','Impossible de préparer le lien de préférences.');}$key=$this->crypto_key();
        if(function_exists('sodium_crypto_secretbox')&&defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')){
            try{$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$cipher=sodium_crypto_secretbox($json,$nonce,$key);return 's1.'.$this->b64url_encode($nonce.$cipher);}catch(Exception $e){}
        }
        if(function_exists('openssl_encrypt')){
            try{$iv=random_bytes(12);}catch(Exception $e){return new WP_Error('dendrila_privacy_preference_random','Impossible de générer un lien de préférences sûr.');}
            $tag='';$cipher=openssl_encrypt($json,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'dendrila-privacy-email-preferences-v1',16);
            if(false!==$cipher&&16===strlen($tag)){return 'o1.'.$this->b64url_encode($iv.$tag.$cipher);}
        }
        return new WP_Error('dendrila_privacy_preference_crypto','Le serveur ne fournit pas le chiffrement nécessaire pour créer ce lien.');
    }

    private function decrypt_payload($token){
        if(!is_string($token)||strlen($token)>4096||false===strpos($token,'.')){return new WP_Error('dendrila_privacy_preference_token','Lien de préférences invalide.');}
        list($mode,$encoded)=array_pad(explode('.',$token,2),2,'');$raw=$this->b64url_decode($encoded);if(false===$raw){return new WP_Error('dendrila_privacy_preference_token','Lien de préférences invalide.');}$key=$this->crypto_key();$json=false;
        if('s1'===$mode&&function_exists('sodium_crypto_secretbox_open')&&defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')){$n=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;if(strlen($raw)>$n){$json=sodium_crypto_secretbox_open(substr($raw,$n),substr($raw,0,$n),$key);}}
        elseif('o1'===$mode&&function_exists('openssl_decrypt')&&strlen($raw)>28){$json=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'dendrila-privacy-email-preferences-v1');}
        if(!is_string($json)||''===$json){return new WP_Error('dendrila_privacy_preference_token','Lien invalide ou devenu illisible après une rotation des clés du site.');}
        $payload=json_decode($json,true);if(!is_array($payload)||empty($payload['email'])||empty($payload['exp'])){return new WP_Error('dendrila_privacy_preference_token','Lien de préférences incomplet.');}
        $email=sanitize_email(strtolower(trim((string)$payload['email'])));if(!$email||!is_email($email)){return new WP_Error('dendrila_privacy_preference_token','Lien de préférences invalide.');}
        $expires=absint($payload['exp']);if($expires<time()){return new WP_Error('dendrila_privacy_preference_expired','Ce lien de préférences a expiré. Demandez un nouveau lien dans un e-mail récent.');}
        if($expires>time()+self::MAX_TTL+HOUR_IN_SECONDS){return new WP_Error('dendrila_privacy_preference_expiry','La durée de validité de ce lien est incohérente.');}
        $payload['email']=$email;$payload['purposes']=$this->normalise_purposes(isset($payload['purposes'])?$payload['purposes']:array());if(!$payload['purposes']){return new WP_Error('dendrila_privacy_preference_purposes','Ce lien ne contient aucune préférence modifiable.');}
        return $payload;
    }

    public function preference_url($email,$purposes=array('email_open_measurement','email_click_tracking'),$ttl=self::DEFAULT_TTL,$context=array()){
        $email=sanitize_email(strtolower(trim((string)$email)));if(!$email||!is_email($email)){return new WP_Error('dendrila_privacy_preference_email','Adresse e-mail invalide.');}
        $purposes=$this->normalise_purposes($purposes);if(!$purposes){return new WP_Error('dendrila_privacy_preference_purposes','Aucune préférence e-mail reconnue.');}
        $ttl=max(HOUR_IN_SECONDS,min(self::MAX_TTL,absint($ttl)));$context=is_array($context)?$context:array();
        $payload=array('v'=>1,'email'=>$email,'iat'=>time(),'exp'=>time()+$ttl,'purposes'=>$purposes,'source'=>isset($context['source'])?sanitize_key((string)$context['source']):'email','ref'=>isset($context['evidence_ref'])?substr(sanitize_text_field((string)$context['evidence_ref']),0,191):'','notice'=>isset($context['notice_version'])?substr(sanitize_text_field((string)$context['notice_version']),0,191):self::NOTICE_VERSION,'preview'=>!empty($context['preview']));
        $token=$this->encrypt_payload($payload);if(is_wp_error($token)){return $token;}return add_query_arg(self::QUERY_KEY,$token,home_url('/'));
    }

    private function request_token(){
        $token='';
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Anonymous bearer-link workflow: encrypted token + token-bound HMAC are used instead of a WordPress session nonce.
        if(isset($_POST[self::QUERY_KEY])&&is_scalar($_POST[self::QUERY_KEY])){$token=wp_unslash($_POST[self::QUERY_KEY]);}
        elseif(isset($_GET[self::QUERY_KEY])&&is_scalar($_GET[self::QUERY_KEY])){$token=wp_unslash($_GET[self::QUERY_KEY]);}
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $token=is_string($token)?trim($token):'';return preg_match('/^[A-Za-z0-9._-]+$/',$token)?$token:'';
    }

    private function form_key($token){return hash_hmac('sha256','form|'.$token,$this->crypto_key());}
    private function is_post(){$method=isset($_SERVER['REQUEST_METHOD'])?sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])):'GET';return 'post'===strtolower($method);}

    private function current_selection($email,$allowed){
        if(!class_exists('Dendrila_Privacy_Evidence_Ledger')){return array();}$latest=Dendrila_Privacy_Evidence_Ledger::instance()->latest_for_email($email,'email_tracking');
        if(!is_array($latest)||!in_array(isset($latest['decision'])?$latest['decision']:'',array('granted','partial'),true)){return array();}
        return array_values(array_intersect($allowed,isset($latest['purposes'])&&is_array($latest['purposes'])?$latest['purposes']:array()));
    }

    private function notice_text(){return 'Vous pouvez choisir séparément la mesure individualisée des ouvertures et le suivi individualisé des clics. Ces réglages concernent le suivi de vos interactions avec les e-mails ; ils ne modifient pas votre abonnement ni votre possibilité de vous désinscrire. Vous pouvez refuser ou retirer ces choix avec la même interface.';}

    private function save_preferences($token,$payload){
        if ( ! empty( $payload['preview'] ) ) { return new WP_Error( 'dendrila_privacy_preference_preview', 'Cet aperçu administrateur ne peut enregistrer aucun choix.' ); }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Token-bound HMAC is the CSRF proof for this anonymous bearer-link workflow.
        $form_key=isset($_POST['dendrila_privacy_preference_form_key'])?sanitize_text_field(wp_unslash($_POST['dendrila_privacy_preference_form_key'])):'';
        $action=isset($_POST['dendrila_privacy_preference_action'])?sanitize_key(wp_unslash($_POST['dendrila_privacy_preference_action'])):'save';
        $posted=isset($_POST['dendrila_privacy_email_purposes'])&&is_array($_POST['dendrila_privacy_email_purposes'])?wp_unslash($_POST['dendrila_privacy_email_purposes']):array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if(!$form_key||!hash_equals($this->form_key($token),$form_key)){return new WP_Error('dendrila_privacy_preference_form','Le formulaire n’est plus valide. Rouvrez le lien reçu par e-mail.');}
        $allowed=$payload['purposes'];$previous=$this->current_selection($payload['email'],$allowed);$selected='reject_all'===$action?array():array_values(array_intersect($allowed,$this->normalise_purposes($posted)));
        if(!$selected){$decision=$previous?'withdrawn':'denied';}elseif(count($selected)===count($allowed)){$decision='granted';}else{$decision='partial';}
        if(!class_exists('Dendrila_Privacy_Evidence_Ledger')){return new WP_Error('dendrila_privacy_preference_ledger','Le registre local de preuves n’est pas disponible.');}
        $context=array('notice_version'=>isset($payload['notice'])?$payload['notice']:self::NOTICE_VERSION,'notice_text'=>$this->notice_text(),'source'=>'email_preference_center','evidence_ref'=>isset($payload['ref'])?$payload['ref']:'','method'=>'signed_email_link','metadata'=>array('link_source'=>isset($payload['source'])?$payload['source']:'email','token_ref'=>substr(hash('sha256',$token),0,16)));
        $recorded=Dendrila_Privacy_Evidence_Ledger::instance()->record_email_tracking_consent($payload['email'],$decision,$selected,$context);if(is_wp_error($recorded)){return $recorded;}
        $integration=apply_filters('dendrila_privacy_apply_email_tracking_preferences',null,$payload['email'],$selected,$decision,$payload);do_action('dendrila_privacy_email_tracking_preferences_updated',$payload['email'],$selected,$decision,$payload);
        return array('selected'=>$selected,'decision'=>$decision,'integration'=>$integration);
    }

    public function maybe_render_public_page(){
        $token=$this->request_token();if(''===$token){return;}$payload=$this->decrypt_payload($token);$message='';$tone='neutral';$selected=array();
        if(!is_wp_error($payload)){$selected=$this->current_selection($payload['email'],$payload['purposes']);if($this->is_post()){$saved=$this->save_preferences($token,$payload);if(is_wp_error($saved)){$message=$saved->get_error_message();$tone='error';}else{$selected=$saved['selected'];if(is_wp_error($saved['integration'])){$message='Votre choix est enregistré dans Dendrila Privacy, mais l’outil d’envoi n’a pas confirmé sa synchronisation : '.$saved['integration']->get_error_message();$tone='warning';}elseif(true===$saved['integration']||(is_array($saved['integration'])&&!empty($saved['integration']['verified']))){$message='Vos choix ont été enregistrés et l’outil d’envoi a confirmé leur prise en compte.';$tone='success';}else{$message='Vos choix ont été enregistrés localement. L’outil d’envoi doit maintenant consulter cette préférence avant d’activer un suivi individualisé.';$tone='success';}}}}
        $this->render_document($token,$payload,$selected,$message,$tone);exit;
    }

    private function render_document($token,$payload,$selected,$message,$tone){
        nocache_headers();header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");header('Content-Type: text/html; charset='.get_option('blog_charset','UTF-8'));
        wp_enqueue_style('dendrila-privacy-email-preferences',plugin_dir_url(dirname(__DIR__).'/dendrila-privacy.php').'assets/email-preferences.css',array(),Pixel_Trackers_Manager_Plugin::VERSION);
        echo '<!doctype html><html lang="'.esc_attr(get_bloginfo('language')).'"><head><meta charset="'.esc_attr(get_option('blog_charset','UTF-8')).'"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.esc_html('Préférences de suivi e-mail — '.get_bloginfo('name')).'</title>';wp_print_styles(array('dendrila-privacy-email-preferences'));echo '</head><body><main class="dendrila-email-page"><section class="dendrila-email-card"><p class="dendrila-email-eyebrow">Vie privée</p><h1>Préférences de suivi e-mail</h1><p class="dendrila-email-lead">Choisissez si vos interactions avec les e-mails peuvent être mesurées individuellement. Le refus n’affecte pas la réception des messages auxquels vous êtes abonné.</p>';
        if(is_wp_error($payload)){echo '<div class="dendrila-email-message is-error" role="alert"><strong>Lien inutilisable</strong><span>'.esc_html($payload->get_error_message()).'</span></div><p class="dendrila-email-footnote">Aucune préférence n’a été modifiée.</p></section></main></body></html>';return;}
        if($message){echo '<div class="dendrila-email-message is-'.esc_attr($tone).'" role="status" aria-live="polite"><strong>'.('error'===$tone?'À vérifier':'Choix enregistré').'</strong><span>'.esc_html($message).'</span></div>';}
        if ( ! empty( $payload['preview'] ) ) { echo '<div class="dendrila-email-message is-warning" role="status"><strong>Aperçu administrateur</strong><span>Cette page permet de vérifier le rendu uniquement. Aucun choix affiché ici ne peut être enregistré comme preuve.</span></div>'; }
        echo '<div class="dendrila-email-notice"><p>'.esc_html($this->notice_text()).'</p></div><form method="post" action="'.esc_url(home_url('/')).'"><input type="hidden" name="'.esc_attr(self::QUERY_KEY).'" value="'.esc_attr($token).'"><input type="hidden" name="dendrila_privacy_preference_form_key" value="'.esc_attr($this->form_key($token)).'"><div class="dendrila-email-options">';
        $catalogue=$this->purposes_catalogue();foreach($payload['purposes'] as $purpose){if(!isset($catalogue[$purpose])){continue;}$id='dendrila-email-'.$purpose;echo '<label class="dendrila-email-option" for="'.esc_attr($id).'"><span class="dendrila-email-option-copy"><strong>'.esc_html($catalogue[$purpose]['label']).'</strong><small>'.esc_html($catalogue[$purpose]['description']).'</small></span><input id="'.esc_attr($id).'" type="checkbox" name="dendrila_privacy_email_purposes[]" value="'.esc_attr($purpose).'" '.checked(in_array($purpose,$selected,true),true,false).( ! empty( $payload['preview'] ) ? ' disabled' : '' ).'></label>';}
        echo '</div><div class="dendrila-email-actions"><button type="submit" name="dendrila_privacy_preference_action" value="reject_all"'.( ! empty( $payload['preview'] ) ? ' disabled' : '' ).'>Tout refuser</button><button type="submit" name="dendrila_privacy_preference_action" value="save"'.( ! empty( $payload['preview'] ) ? ' disabled' : '' ).'>Enregistrer mes choix</button></div></form><p class="dendrila-email-footnote">Ce lien expire le '.esc_html(wp_date(get_option('date_format').' '.get_option('time_format'),(int)$payload['exp'])).'. Il ne contient pas votre adresse e-mail en clair et n’est pas utilisé comme lien de mesure de clic.</p></section></main></body></html>';
    }

    public function render_admin_test_link($email,$latest){
        if(!current_user_can('manage_options')||!is_email($email)){return;}$url=$this->preference_url($email,array('email_open_measurement','email_click_tracking'),self::DEFAULT_TTL,array('source'=>'admin_preview','preview'=>true));if(is_wp_error($url)){echo '<div class="ptm-callout warn ptm-evidence-preference-card"><strong>Centre de préférences indisponible :</strong> '.esc_html($url->get_error_message()).'</div>';return;}
        echo '<div class="ptm-evidence-preference-card"><div><p class="ptm-eyebrow">Lien sans compte</p><h3>Aperçu du centre de préférences e-mail</h3><p>Le lien est chiffré, valable 14 jours et ne montre pas l’adresse e-mail. Ce mode d’aperçu est volontairement non enregistrant : il ne peut pas fabriquer une preuve au nom de la personne.</p></div><a class="button button-secondary" href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">Ouvrir le centre de préférences</a></div>';
    }

    public function tracking_allowed($email,$purpose){
        $purpose=sanitize_key((string)$purpose);$catalogue=$this->purposes_catalogue();if(!isset($catalogue[$purpose])||!class_exists('Dendrila_Privacy_Evidence_Ledger')){return false;}$latest=Dendrila_Privacy_Evidence_Ledger::instance()->latest_for_email($email,'email_tracking');
        $allowed=is_array($latest)&&in_array(isset($latest['decision'])?$latest['decision']:'',array('granted','partial'),true)&&in_array($purpose,isset($latest['purposes'])&&is_array($latest['purposes'])?$latest['purposes']:array(),true);return (bool)apply_filters('dendrila_privacy_email_tracking_allowed',$allowed,$email,$purpose,$latest);
    }
}

if(!function_exists('dendrila_privacy_email_preferences_url')){function dendrila_privacy_email_preferences_url($email,$purposes=array('email_open_measurement','email_click_tracking'),$ttl=Dendrila_Privacy_Email_Preferences::DEFAULT_TTL,$context=array()){return Dendrila_Privacy_Email_Preferences::instance()->preference_url($email,$purposes,$ttl,$context);}}
if(!function_exists('dendrila_privacy_email_tracking_allowed')){function dendrila_privacy_email_tracking_allowed($email,$purpose){return Dendrila_Privacy_Email_Preferences::instance()->tracking_allowed($email,$purpose);}}
