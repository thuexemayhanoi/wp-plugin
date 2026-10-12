<?php
/** Mock-only tests for simple UI, validation, secret handling and Markdown. */
namespace {
 define('ABSPATH','/tmp/');
 function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
 function sanitize_text_field($v){return strip_tags((string)$v);} function get_option($name,$default=''){return $GLOBALS['opts'][$name]??$default;}
 function update_option($name,$value,$autoload=false){$GLOBALS['opts'][$name]=$value;return true;}
 function wp_parse_args($arg,$defaults){return array_merge($defaults,(array)$arg);} function wp_salt($name){return 'test-only-salt-'.$name;} function sanitize_textarea_field($v){return strip_tags((string)$v);} 
 function is_wp_error($v){return false;} function get_term($id,$tax){return $id===3?(object)['term_id'=>3]:false;}
 function current_user_can($cap){return true;} function get_current_user_id(){return 1;}
 function wp_date($fmt,$time=null){return date($fmt,$time??time());} function wp_json_encode($v){return json_encode($v);}
 function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);} function esc_attr($v){return esc_html($v);} function esc_url($v,$protocols=[]){return esc_html($v);}
 function admin_url($v){return '/wp-admin/'.$v;} function wp_dropdown_categories($opts){echo '<select name="category"><option value="3">A</option></select>';}
 function wp_nonce_field($x){echo '<input type="hidden" name="nonce" value="test">';}
 function get_transient($v){return [];} function checked($a,$b,$c){return $a===$b?'checked':'';} function selected($a,$b,$echo){return (string)$a===(string)$b?'selected':'';}
 function esc_textarea($v){return esc_html($v);} function wp_kses($v,$allowed){return strip_tags($v,'<p><h2><h3><h4><h5><h6><strong><em><ul><li><ol><a><code><pre>');}
 function wp_strip_all_tags($v){return strip_tags($v);} if (!function_exists('mb_substr')) { function mb_substr($v,$start,$max){return substr($v,$start,$max);} }
 class DummyDB { public $insert_id=0; public $last=[]; public function insert($table,$row){$this->last=$row;$this->insert_id++;return 1;} }
 $wpdb=new DummyDB();$GLOBALS['opts']=['masf_provider_claude'=>['model'=>'claude-test-model','output_tokens'=>16000,'timeout'=>45]];
}
namespace MASF {
 require __DIR__.'/../includes/class-settings.php';
 class Store {public static function table($n){return 'masf_'.$n;}public static function now(){return date('Y-m-d H:i:s');}}
 class Importer {public static function upload($v){return [];}public static function add($id,$rows){$GLOBALS['rows']=$rows;return ['added'=>count($rows),'skipped'=>[]];}}
 class Queue {public static function control($id,$op){$GLOBALS['control']=[$id,$op];}}
 class QA {public static function allowed_html(){return [];}}
 require __DIR__.'/../includes/class-admin.php';
 require __DIR__.'/../includes/class-markdown.php';
 require __DIR__.'/../includes/class-api-error.php';
 require __DIR__.'/../includes/class-adapter.php';
 $GLOBALS['opts']['masf_key_claude'] = Settings::encrypt('fake-test-key');
 $passed=0; function ok($condition,$label){global $passed;if(!$condition)throw new \Exception('FAILED: '.$label);++$passed;}
 $make=new \ReflectionMethod(Admin::class,'create_quick');$make->setAccessible(true);
 $base=['provider'=>'claude','count'=>'1','words'=>'1500','category'=>'3','language'=>'vi','mode'=>'draft','h2'=>'5','h3'=>'6','h4'=>'0','h5'=>'0','h6'=>'0','confirm_cost'=>'1','titles'=>'Bài thử 1'];
 $r=$make->invoke(null,$base); ok($r['id']===1,'creates 1 job');ok(count($GLOBALS['rows'])===1,'imports one');
 $cfg=json_decode($GLOBALS['wpdb']->last['config'],true);ok($cfg['simple_mode']===true,'simple mode');ok($cfg['mode']==='draft','draft default');ok($cfg['simple_headings']['h2']==5,'H2 count');ok($cfg['simple_headings']['h3']==6,'H3 count');ok($cfg['target_words']===1500,'word target');ok($cfg['output_tokens']>=12000,'automatic tokens');
 $b=$base;$b['words']='10000';$b['count']='100';$b['titles']=implode("\n",array_map(fn($n)=>'Bài thứ '.$n,range(1,100)));$b['h6']='2';
 $make->invoke(null,$b);$cfg=json_decode($GLOBALS['wpdb']->last['config'],true);ok(count($GLOBALS['rows'])===100,'100 titles');ok($cfg['max_words']===10000,'10k cap');ok($cfg['simple_headings']['h6']===2,'H6 count');
 $b['count']=101;try{$make->invoke(null,$b);throw new \Exception('should reject 101');}catch(\InvalidArgumentException $e){ok(true,'count limit');}
 $b=$base;$b['words']=1499;try{$make->invoke(null,$b);throw new \Exception('should reject short');}catch(\InvalidArgumentException $e){ok(true,'word lower bound');}
 $b=$base;$b['h2']=0;try{$make->invoke(null,$b);throw new \Exception('should reject h2=0');}catch(\InvalidArgumentException $e){ok(true,'h2 required');}
 $b=$base;$b['count']=3;try{$make->invoke(null,$b);throw new \Exception('should reject missing titles');}catch(\InvalidArgumentException $e){ok(true,'title count');}
 $b=$base;$b['mode']='publish';$make->invoke(null,$b);$cfg=json_decode($GLOBALS['wpdb']->last['config'],true);ok($cfg['mode']==='publish' && $cfg['auto_publish']===true,'explicit publication');
 $b=$base;$b['provider']='gemini';try{$make->invoke(null,$b);throw new \Exception('should reject no key');}catch(\InvalidArgumentException $e){ok(true,'API requires setup');}
 $quick=new \ReflectionMethod(Admin::class,'quick_form');$quick->setAccessible(true);ob_start();$quick->invoke(null);$html=ob_get_clean();
 ok(str_contains($html,'name="h6"'),'H6 visible');ok(str_contains($html,'name="mode"'),'status visible');ok(str_contains($html,'10000'),'long article visible');ok(!str_contains($html,'type="password"'),'API only in settings');ok(!str_contains($html,'fake-test-key'),'secret not exposed');
 $md=Markdown::render("## H2\n\n### H3\n\n##### H5\n\n###### H6\n\n**bold** text\n\n<script>alert(1)</script>");
 ok(str_contains($md,'<h6>H6</h6>'),'H6 rendered');ok(str_contains($md,'<strong>bold</strong>'),'bold rendered');ok(!str_contains($md,'<script>'),'script escaped');
 $err=Adapter::classify_error(429,json_encode(['error'=>['code'=>'insufficient_quota']]),0);ok($err->requires_action,'quota error');
 $err=Adapter::classify_error(429,json_encode(['error'=>['type'=>'rate_limit_error']]),30);ok($err->transient,'rate limit retry');
 echo "PASS $passed smoke checks (mock-only; no real API/WP).\n";
}
