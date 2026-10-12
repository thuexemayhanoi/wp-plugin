<?php
/** Local no-WordPress smoke checks: mocks only, no live API or DB access. */
namespace {
 define('ABSPATH','/tmp/');
 function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
 function sanitize_text_field($v){return strip_tags((string)$v);} function get_option($name,$default=''){return $GLOBALS['opts'][$name]??$default;}
 function update_option($name,$value,$autoload=false){$GLOBALS['opts'][$name]=$value;return true;}
 function is_wp_error($v){return false;} function get_term($id,$tax){return $id===3?(object)['term_id'=>3]:false;}
 function get_current_user_id(){return 1;} function wp_date($fmt,$time){return date($fmt,$time);} function wp_json_encode($v){return json_encode($v);}
 function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);} function esc_attr($v){return esc_html($v);} function esc_url($v){return esc_html($v);}
 function admin_url($v){return '/wp-admin/'.$v;} function wp_dropdown_categories($opts){echo '<select name="category"><option value="3">A</option></select>';}
 function wp_nonce_field($x){echo '<input type="hidden" name="nonce" value="test">';}
 function get_transient($v){return [];} function checked($a,$b,$c){return $a===$b?'checked':'';} function selected($a,$b,$echo){return (string)$a===(string)$b?'selected':'';}
 function esc_textarea($v){return esc_html($v);}
 class DummyDB { public $insert_id=0; public $last=[]; public function insert($table,$row){$this->last=$row; $this->insert_id++;return 1;} }
 $wpdb=new DummyDB(); $GLOBALS['opts']=[];
}
namespace MASF {
 class Settings {
  public static function key($provider){return $GLOBALS['opts']['masf_key_'.$provider]??'';}
  public static function model($v){if($v && !preg_match('/^[a-zA-Z0-9._:\/-]{1,150}$/D',$v)) throw new \InvalidArgumentException('model');return trim($v);}
  public static function provider($provider){return ['model'=>'','output_tokens'=>16000,'timeout'=>45];}
  public static function encrypt($v){return 'enc('.$v.')';}
  public static function campaign_config($v){return $v;}
 }
 class Store { public static function table($v){return 'wp_masf_'.$v;} public static function now(){return date('Y-m-d H:i:s');} }
 class Importer {public static function upload($v){return [];} public static function add($id,$rows){$GLOBALS['rows']=$rows;return ['added'=>count($rows),'skipped'=>[]];}}
 class Queue {public static function control($id,$cmd){$GLOBALS['control']=[$id,$cmd];}}
 require __DIR__ . '/../includes/class-admin.php';
 require __DIR__ . '/../includes/class-api-error.php';
 require __DIR__ . '/../includes/class-adapter.php';
 $pass=0; function check($ok,$name){global $pass;if(!$ok){throw new \Exception('FAILED '.$name);}++$pass;}
 $method=new \ReflectionMethod(Admin::class,'create_quick');$method->setAccessible(true);
 $base=['provider'=>'claude','api_key'=>'sk-test-not-real','model'=>'claude-sonnet-4-5','count'=>'5','words'=>'1500','category'=>'3','language'=>'vi','output_tokens'=>'16000','confirm_cost'=>'1','titles'=>implode("\n",array_map(fn($n)=>'Bài chuyên sâu số '.$n,range(1,5)))];
 $r=$method->invoke(null,$base);
 check($r['id']===1,'new campaign');check(count($GLOBALS['rows'])===5,'five titles');check($GLOBALS['control']===[1,'start'],'starts queue');
 check($GLOBALS['wpdb']->last['status']==='PAUSED','created paused before start');
 $cfg=json_decode($GLOBALS['wpdb']->last['config'],true);check($cfg['mode']==='draft','only drafts');check($cfg['output_tokens']===16000,'tokens per campaign');
 check($cfg['min_words']===1350 && $cfg['max_words']===1650,'word tolerance');check(Settings::key('claude')==='enc(sk-test-not-real)','stores encrypted key');
 $b=$base;$b['count']='100';$b['words']='5000';$b['output_tokens']='64000';$b['titles']=implode("\n",array_map(fn($n)=>'Hướng dẫn xe máy số '.$n,range(1,100)));$b['api_key']='';
 $r=$method->invoke(null,$b);check($r['id']===2,'hundred campaign');check(count($GLOBALS['rows'])===100,'hundred rows');$cfg=json_decode($GLOBALS['wpdb']->last['config'],true);check($cfg['min_words']===4500 && $cfg['max_words']===5000,'5000-word target');
 $b['count']='101';try{$method->invoke(null,$b);throw new \Exception('missing 101 validation');}catch(\InvalidArgumentException $e){check(true,'reject 101');}
 $b=$base;$b['count']='20';try{$method->invoke(null,$b);throw new \Exception('missing title count validation');}catch(\InvalidArgumentException $e){check(true,'reject insufficient titles');}
 $b=$base;$b['api_key']='bad key with spaces';try{$method->invoke(null,$b);throw new \Exception('missing invalid key');}catch(\InvalidArgumentException $e){check(true,'reject bad key');}
 $err=Adapter::classify_error(429,json_encode(['error'=>['code'=>'insufficient_quota','message'=>'You exceeded quota']]),0);check($err->requires_action && !$err->transient,'429 insufficient quota needs action');
 $err=Adapter::classify_error(429,json_encode(['error'=>['type'=>'rate_limit_error','message'=>'Rate limit exceeded']]),30);check(!$err->requires_action && $err->transient && $err->retry_after===30,'429 rate limit retry');
 $err=Adapter::classify_error(402,json_encode(['error'=>['status'=>'RESOURCE_EXHAUSTED','message'=>'prepayment credits depleted']]),0);check($err->requires_action,'Gemini 402 billing');
 $err=Adapter::classify_error(400,json_encode(['error'=>['type'=>'invalid_request_error','message'=>'Reached your workspace spend limit']]),0);check($err->requires_action && str_contains($err->getMessage(),'credit'),'Anthropic 400 spend');
 $err=Adapter::classify_error(400,json_encode(['error'=>['type'=>'invalid_request_error','message'=>'Model does not support json']]),0);check($err->requires_action && str_contains($err->getMessage(),'JSON'),'bad model response');
 $err=Adapter::classify_error(401,json_encode(['error'=>['message'=>'sk-secret-stolen']]),0);check(!str_contains($err->getMessage(),'sk-secret-stolen'),'raw secrets masked');
 $quick = new \ReflectionMethod(Admin::class,'quick_form'); $quick->setAccessible(true);
 ob_start(); $quick->invoke(null); $html=ob_get_clean();
 check(str_contains($html,'name="count"') && str_contains($html,'name="matrix_file"'),'quick form controls');
 check(str_contains($html,'type="password"') && !str_contains($html,'enc(sk-test-not-real)'),'quick form no decrypted secret');
 check(str_contains($html,'Tạo chiến dịch và chạy'),'quick submit');
 echo "PASS $pass/23 checks; no live WP/API calls\n";
}
