<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

/** Installer-only protocol. No runtime hook, bot construction, migration helper or DDL. */
final class InstallerNativeCleanFailure extends RuntimeException {}

final class InstallerNativeClean
{
    public const TABLES=['posted_account_identities','posted_accounts','posted_admin_passkeys','posted_admin_tokens','posted_app_settings','posted_channels','posted_edit_sessions','posted_emoji_catalog','posted_emoji_sets','posted_emoji_submissions','posted_folder_channels','posted_folders','posted_manager_categories','posted_max_channels','posted_max_deliveries','posted_max_link_attempts','posted_max_link_codes','posted_max_manager_categories','posted_max_permissions','posted_max_posts','posted_max_publish_attempts','posted_max_reactions','posted_max_schema_meta','posted_max_templates','posted_max_update_effects','posted_max_updates','posted_max_users','posted_permissions','posted_posts','posted_reactions','posted_telegram_publish_attempts','posted_templates','posted_users'];
    private const LOCKS=['trevoga_posted_cron','gitposted_max_cron'];
    private const PHASES=['before_schema','schema_started','schema_seeded','enable_started','enabled'];

    /** Hooks are constructor-only captured-test dependencies, never JSON request options. */
    public function __construct(private readonly mixed $connectHook=null,private readonly mixed $environmentHook=null) {}

    private static function need(bool $ok,string $code):void
    {if(!$ok)throw new InstallerNativeCleanFailure($code);}
    private static function hash(mixed $value):string
    {return PostedTelegramNativeContent::hash($value);}
    private static function sha(mixed $value):bool
    {return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;}
    private static function fields(array $value,array $expected):void
    {$keys=array_keys($value);sort($keys);sort($expected);self::need($keys===$expected,'fields');}
    private static function identity(array $config):array
    {
        $db=$config['database']??null;self::need(is_array($db),'database');
        self::need(($db['host']??null)==='localhost'&&($db['charset']??null)==='utf8mb4'&&is_string($db['name']??null)&&preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D',$db['name'])===1&&is_string($db['user']??null)&&preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,31}\z/D',$db['user'])===1&&is_string($db['password']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$db['password'])===1,'database');
        return array_intersect_key($db,array_flip(['host','name','user','charset']));
    }
    private static function rootFile(string $path):void
    {
        self::need($path!==''&&$path[0]==='/'&&realpath($path)===$path&&!is_link($path)&&is_file($path),'reader_path');
        $cursor=$path;
        while(true){$s=lstat($cursor);self::need(is_array($s)&&$s['uid']===0&&($s['mode']&0022)===0&&!is_link($cursor),'reader_permissions');if($cursor==='/')break;$cursor=dirname($cursor);}
    }
    private function environment(array $sources):array
    {
        if($this->environmentHook!==null){$value=($this->environmentHook)($sources);self::need(is_array($value),'test_environment');return$value;}
        $installer=dirname(__DIR__);self::rootFile($installer.'/VERSION');self::rootFile(__FILE__);
        $version=trim((string)file_get_contents($installer.'/VERSION'));self::need(preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D',$version)===1,'release');
        $current='/opt/gitposted/current';$link=lstat($current);$root=realpath($current.'/src');
        self::need(is_link($current)&&is_array($link)&&$link['uid']===0&&$root==='/opt/gitposted/releases/'.$version.'/src','release');
        $readers=$sources['readers'];$actual=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as$item){self::need(!$item->isLink(),'reader_symlink');if(!$item->isFile())continue;$path=$item->getPathname();self::rootFile($path);$name=substr($path,strlen($root)+1);$actual[$name]=hash_file('sha256',$path);}
        ksort($actual);self::need($actual===$readers,'installed_readers_changed');
        $payload=$installer.'/payload/src/telegram-native-content.php';self::rootFile($payload);self::need(hash_file('sha256',$payload)===($readers['telegram-native-content.php']??null),'native_reader_changed');
        if(!class_exists('PostedTelegramNativeContent',false))require_once$payload;
        $reflection=new ReflectionClass(PostedTelegramNativeContent::class);self::need(hash_file('sha256',(string)$reflection->getFileName())===$readers['telegram-native-content.php'],'loaded_reader_changed');
        $configPath=trim((string)(getenv('POSTED_CONFIG_FILE')?:'/etc/gitposted/config.php'));
        self::need($configPath==='/etc/gitposted/config.php'&&is_file($configPath)&&!is_link($configPath)&&realpath($configPath)===$configPath,'config_path');
        $s=lstat($configPath);self::need(is_array($s)&&($s['mode']&0777)===0600,'config_permissions');$config=require$configPath;self::need(is_array($config),'config');
        $site='/etc/nginx/sites-available/gitposted-installer';self::rootFile($site);
        self::need(hash_file('sha256',$site)===$sources['nginx_sha256']&&is_link('/etc/nginx/sites-enabled/gitposted-installer')&&readlink('/etc/nginx/sites-enabled/gitposted-installer')===$site,'pending_web_gate_changed');
        self::need(hash_file('sha256',__FILE__)===$sources['helper_sha256'],'helper_changed');
        return['config'=>$config,'config_sha256'=>hash_file('sha256',$configPath),'readers_verified'=>true,'web_pending_verified'=>true];
    }
    private function connect(array $config):PDO
    {
        if($this->connectHook!==null){$pdo=($this->connectHook)($config);self::need($pdo instanceof PDO,'test_connection');return$pdo;}
        $db=$config['database'];
        $pdo=new PDO('mysql:host=localhost;dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>true,PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4']);
        $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $tz=new DateTimeZone((string)($config['timezone']??'Europe/Moscow'));$offset=(new DateTimeImmutable('now',$tz))->format('P');$pdo->exec('SET time_zone='.$pdo->quote($offset));
        return$pdo;
    }
    /** Same three-field capability protocol as the runtime, without the production migration code. */
    public static function certificate(array $types):array
    {
        self::need(array_keys($types)===PostedTelegramNativeSchema::COLUMNS,'certificate_columns');$expected=array_merge(PostedTelegramNativeSchema::OLD,PostedTelegramNativeContent::KINDS);
        foreach($types as$type)self::need(is_string($type)&&PostedTelegramNativeSchema::values($type)===$expected,'requires_additive_migration');
        return['version'=>1,'columns_sha256'=>self::hash($types),'kinds'=>PostedTelegramNativeContent::KINDS];
    }
    private static function request(array $request):array
    {
        self::fields($request,['action','creation_receipt','sources','phase','plan','plan_sha256','allow_insert','activation_intent']);
        self::need(in_array($request['action'],['inspect','enable_clean','inspect_activation'],true)&&in_array($request['phase'],self::PHASES,true)&&is_bool($request['allow_insert']),'request');
        self::need($request['action']==='enable_clean'||$request['allow_insert']===false,'inspect_write_forbidden');
        self::need($request['action']==='inspect_activation'||$request['activation_intent']===null,'unexpected_activation_intent');
        $r=$request['creation_receipt'];self::need(is_array($r),'receipt');self::fields($r,['version','nonce','job_id','identity','phase','requested_at','created_at']);
        self::need($r['version']===1&&is_string($r['nonce'])&&preg_match('/\A[a-f0-9]{32}\z/D',$r['nonce'])===1&&is_string($r['job_id'])&&preg_match('/\A[a-f0-9]{24}\z/D',$r['job_id'])===1&&$r['phase']==='database_ready'&&is_int($r['requested_at'])&&$r['requested_at']>0&&is_int($r['created_at'])&&$r['created_at']>=$r['requested_at']&&is_array($r['identity']),'receipt');
        $s=$request['sources'];self::need(is_array($s),'sources');self::fields($s,['readers','reader_manifest_sha256','helper_sha256','nginx_sha256','installation_pending','bots_not_registered','registration_phase','registration_proof']);
        self::need($s['installation_pending']===true&&is_bool($s['bots_not_registered'])&&is_array($s['readers'])&&count($s['readers'])>=100&&count($s['readers'])<=500,'sources');
        foreach($s['readers']as$name=>$sha)self::need(is_string($name)&&preg_match('~\A[A-Za-z0-9_().-]+(?:/[A-Za-z0-9_().-]+)*\z~D',$name)===1&&!in_array('..',explode('/',$name),true)&&self::sha($sha),'reader_map');
        foreach(['reader_manifest_sha256','helper_sha256','nginx_sha256']as$key)self::need(self::sha($s[$key]),'sources');
        if($s['registration_phase']==='before_registration'){
            self::need($s['bots_not_registered']===true&&$s['registration_proof']===null,'pre_registration_authority');
        }else{
            self::need($s['registration_phase']==='verified_registration'&&$s['bots_not_registered']===false&&in_array($request['action'],['inspect','inspect_activation'],true)&&$request['phase']==='enabled'&&$request['allow_insert']===false,'registered_readonly_only');
            $proof=$s['registration_proof'];self::need(is_array($proof),'registration_proof');self::fields($proof,['version','job_id','schema_status','verify_status','platforms']);
            self::need($proof['version']===1&&$proof['job_id']===$r['job_id']&&$proof['schema_status']==='done'&&$proof['verify_status']==='done'&&is_array($proof['platforms']),'registration_proof');
            self::fields($proof['platforms'],['telegram','max']);
            foreach($proof['platforms']as$platform=>$item){
                self::need(is_array($item),'registration_proof');self::fields($item,['selected','status','binding']);self::need(is_bool($item['selected'])&&is_array($item['binding']),'registration_proof');self::fields($item['binding'],['id','started']);self::need(is_bool($item['binding']['started'])&&is_string($item['binding']['id'])&&($item['binding']['id']===''||preg_match('/\A[1-9][0-9]{0,63}\z/D',$item['binding']['id'])===1),'registration_proof');
                if($item['selected'])self::need($item['status']==='done'&&$item['binding']['started']===true&&is_string($item['binding']['id'])&&preg_match('/\A[1-9][0-9]{0,63}\z/D',$item['binding']['id'])===1,'registration_unknown');
                else self::need($item['status']==='skipped'&&$item['binding']['started']===false,'registration_unknown');
            }
        }
        return$request;
    }
    /** Ignore only the numeric AUTO_INCREMENT table option, never column/default/comment text. */
    public static function structuralCreate(string $sql):string
    {
        $quote=null;$depth=0;$opened=false;$offset=null;$n=strlen($sql);
        for($i=0;$i<$n;$i++){
            $c=$sql[$i];
            if($quote!==null){if($c==='\\'&&$quote!=='`'){$i++;continue;}if($c===$quote){if($i+1<$n&&$sql[$i+1]===$quote){$i++;continue;}$quote=null;}continue;}
            if($c==="'"||$c==='"'||$c==='`'){$quote=$c;continue;}
            if($c==='('){$depth++;$opened=true;}elseif($c===')'){self::need($depth>0,'create_parentheses');$depth--;if($opened&&$depth===0){$offset=$i+1;break;}}
        }
        self::need($offset!==null,'create_options');$head=substr($sql,0,$offset);$tail=substr($sql,$offset);$out='';$quote=null;$n=strlen($tail);
        for($i=0;$i<$n;$i++){
            $c=$tail[$i];
            if($quote!==null){$out.=$c;if($c==='\\'&&$quote!=='`'&&$i+1<$n){$out.=$tail[++$i];continue;}if($c===$quote){if($i+1<$n&&$tail[$i+1]===$quote){$out.=$tail[++$i];continue;}$quote=null;}continue;}
            if($c==="'"||$c==='"'||$c==='`'){$quote=$c;$out.=$c;continue;}
            if(($i===0||ctype_space($tail[$i-1]))&&preg_match('/\AAUTO_INCREMENT=[0-9]+(?:\s+|\z)/D',substr($tail,$i),$m)===1){$i+=strlen($m[0])-1;continue;}
            $out.=$c;
        }
        self::need($quote===null,'create_quotes');return$head.rtrim($out);
    }
    private static function scalar(PDO $pdo,string $sql,array $args=[]):mixed
    {$q=$pdo->prepare($sql);$q->execute($args);return$q->fetchColumn();}
    private static function inspection(PDO $pdo,array $request,array $identity,string $disabledConfigSha,bool $activated=false):array
    {
        self::need((bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===true,'dedicated_direct_connection_required');
        self::need(self::scalar($pdo,'SELECT DATABASE()')===$identity['name'],'wrong_database');
        $mode=self::scalar($pdo,'SELECT @@SESSION.sql_mode');self::need(is_string($mode)&&preg_match('/(?:\A|,)STRICT_(?:ALL|TRANS)_TABLES(?:,|\z)/D',$mode)===1,'strict_mode_required');
        $all=$pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);$names=[];
        foreach($all as$row){$name=$row['TABLE_NAME']??null;self::need(is_string($name)&&in_array($name,self::TABLES,true)&&($row['TABLE_TYPE']??null)==='BASE TABLE'&&($row['ENGINE']??null)==='InnoDB','permanent_inventory');$names[]=$name;}
        self::need(count($names)===count(array_unique($names)),'permanent_inventory');$complete=$names===self::TABLES;$allowPartial=in_array($request['phase'],['before_schema','schema_started'],true);
        self::need($complete||$allowPartial,'incomplete_schema');
        $cap=null;$seed=[];$permanentDefinitions=[];$permanentStructure=[];
        foreach($names as$name){
            $row=$pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM);self::need(is_array($row)&&isset($row[1])&&str_starts_with($row[1],'CREATE TABLE ')&&!str_contains($row[1],'CREATE TEMPORARY TABLE'),'temporary_shadow_forbidden');$permanentDefinitions[$name]=hash('sha256',$row[1]);$permanentStructure[$name]=hash('sha256',self::structuralCreate($row[1]));
            if($activated&&$name!=='posted_max_schema_meta'){if($name==='posted_app_settings'){$cap=self::scalar($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=?',[PostedTelegramNativeSchema::KEY]);self::need(is_string($cap)&&strlen($cap)<=4096,'activation_certificate_missing');}continue;}
            $count=self::scalar($pdo,'SELECT COUNT(*) FROM `'.$name.'`');
            if($name==='posted_max_schema_meta'){
                $seed=$pdo->query('SELECT id,schema_version FROM posted_max_schema_meta ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
                self::need($seed===[]||count($seed)===1&&(int)$seed[0]['id']===1&&(int)$seed[0]['schema_version']===5,'fresh_metadata');
                if(count($seed)!==1){self::need($allowPartial,'fresh_metadata');$complete=false;}continue;
            }
            if($name==='posted_app_settings'&&(string)$count==='1'&&in_array($request['phase'],['enable_started','enabled'],true)){
                $cap=self::scalar($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=?',[PostedTelegramNativeSchema::KEY]);self::need(is_string($cap)&&strlen($cap)<=4096,'unexpected_setting');continue;
            }
            self::need((string)$count==='0','ordinary_data_present');
        }
        $definitions=[];$types=[];
        foreach(PostedTelegramNativeSchema::COLUMNS as$key){[$table,$column]=explode('.',$key);if(!in_array($table,$names,true))continue;
            $q=$pdo->prepare('SHOW FULL COLUMNS FROM `'.$table.'` WHERE Field=?');$q->execute([$column]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)){self::need($allowPartial,'native_column_missing');$complete=false;continue;}
            $d=[];foreach(['Field','Type','Collation','Null','Default','Extra','Comment']as$f){self::need(array_key_exists($f,$row),'native_column_metadata');$d[$f]=$row[$f];}
            self::need($d['Field']===$column&&is_string($d['Type'])&&PostedTelegramNativeSchema::values(strtolower($d['Type']))===array_merge(PostedTelegramNativeSchema::OLD,PostedTelegramNativeContent::KINDS),'requires_additive_migration');
            self::need(is_string($d['Collation'])&&preg_match('/\Autf8mb4_[a-z0-9_]+\z/D',$d['Collation'])===1&&in_array($d['Null'],['YES','NO'],true)&&($d['Default']===null||is_string($d['Default'])&&in_array($d['Default'],array_merge(PostedTelegramNativeSchema::OLD,PostedTelegramNativeContent::KINDS),true))&&$d['Extra']===''&&is_string($d['Comment'])&&mb_check_encoding($d['Comment'],'UTF-8')&&!str_contains($d['Comment'],"\0"),'native_column_definition');
            $definitions[$key]=$d;$types[$key]=strtolower($d['Type']);
        }
        if(!$complete)return['complete'=>false,'capability_state'=>'absent','partial_tables'=>$names,'data_writes'=>0,'ddl_statements'=>0];
        $certificate=self::certificate($types);$capState=$cap===null?'absent':'different';
        if($cap!==null){$v=json_decode($cap,true,16,JSON_THROW_ON_ERROR);self::need(is_array($v)&&self::hash($v)===self::hash($certificate),'foreign_or_corrupt_certificate');$capState='same';}
        $server=self::scalar($pdo,'SELECT @@server_uuid');self::need(is_string($server)&&$server!=='','server_identity');
        $plan=['version'=>1,'scope'=>'native_clean_zero_ddl','database_identity'=>$identity,'server_sha256'=>hash('sha256',$server),'creation_receipt_sha256'=>self::hash($request['creation_receipt']),'reader_manifest_sha256'=>$request['sources']['reader_manifest_sha256'],'helper_sha256'=>$request['sources']['helper_sha256'],'definitions'=>$definitions,'permanent_definitions_sha256'=>$permanentDefinitions,'permanent_structure_sha256'=>$permanentStructure,'disabled_config_sha256'=>$disabledConfigSha,'tables'=>self::TABLES,'max_seed'=>['id'=>1,'schema_version'=>5],'certificate'=>$certificate,'steps'=>array_map(static fn(array$d):array=>['before'=>$d,'after'=>$d,'ddl'=>null],$definitions)];
        return['complete'=>true,'capability_state'=>$capState,'plan'=>$plan,'plan_sha256'=>self::hash($plan),'certificate_sha256'=>self::hash($certificate),'data_writes'=>0,'ddl_statements'=>0];
    }
    private function context(array $request):array
    {
        $environment=$this->environment($request['sources']);$config=$environment['config'];$identity=self::identity($config);
        self::need($environment['readers_verified']===true&&$environment['web_pending_verified']===true,'readers_required');
        if($request['action']==='inspect_activation'){
            $a=$request['activation_intent'];self::need(is_array($a),'activation_intent');self::fields($a,['version','phase','job_id','creation_receipt_sha256','plan_sha256','certificate_sha256','reader_manifest_sha256','registration_proof_sha256','disabled_config_sha256','target_config_sha256','target_flags']);
            self::need($request['allow_insert']===false&&$request['phase']==='enabled'&&is_array($request['plan'])&&self::sha($request['plan_sha256'])&&self::hash($request['plan'])===$request['plan_sha256'],'activation_readonly_plan');
            self::need($a['version']===1&&in_array($a['phase'],['config_write_started','config_written_known','config_written_reconciled'],true)&&$a['job_id']===$request['creation_receipt']['job_id']&&$a['creation_receipt_sha256']===self::hash($request['creation_receipt'])&&$a['plan_sha256']===$request['plan_sha256']&&$a['certificate_sha256']===self::hash($request['plan']['certificate'])&&$a['reader_manifest_sha256']===$request['sources']['reader_manifest_sha256']&&$a['registration_proof_sha256']===self::hash($request['sources']['registration_proof']),'activation_intent_changed');
            self::need(is_array($a['target_flags']),'activation_flags');self::fields($a['target_flags'],['telegram','max']);$any=false;
            foreach(['telegram','max']as$platform){$flag=$a['target_flags'][$platform];self::need(is_bool($flag)&&$flag===$request['sources']['registration_proof']['platforms'][$platform]['selected']&&($config[$platform==='telegram'?'telegram_bot_enabled':'max_bot_enabled']??null)===$flag,'unexpected_actual_flags');$any=$any||$flag;}
            self::need($any&&self::hash($config)===$a['target_config_sha256'],'activation_config_changed');
        }else self::need(($config['telegram_bot_enabled']??null)===false&&($config['max_bot_enabled']??null)===false,'disabled_readers_required');
        $disabled=$config;$disabled['telegram_bot_enabled']=false;$disabled['max_bot_enabled']=false;$environment['disabled_config_sha256']=self::hash($disabled);
        if($request['action']==='inspect_activation')self::need($environment['disabled_config_sha256']===$request['activation_intent']['disabled_config_sha256']&&$environment['disabled_config_sha256']===$request['plan']['disabled_config_sha256'],'disabled_config_changed');
        self::need(self::hash($identity)===self::hash($request['creation_receipt']['identity']),'receipt_database_changed');
        self::need(self::hash($request['sources']['readers'])===$request['sources']['reader_manifest_sha256'],'reader_manifest_changed');
        return[$environment,$config,$identity];
    }
    public function run(array $request):array
    {
        $pdo=null;$locks=[];$write='not_started';
        try{
            $action=$request['action']??'inspect';
            if($action==='inspect'&&!array_key_exists('creation_receipt',$request)){
                self::fields($request,$request===[]?[]:['action']);return['ok'=>true,'result'=>['eligible'=>false,'capability_state'=>'not_inspected','reason'=>'positive_creation_receipt_required','data_writes'=>0,'ddl_statements'=>0]];
            }
            $request=self::request($request);[$environment,$config,$identity]=$this->context($request);$pdo=$this->connect($config);
            if($request['action']==='inspect')return['ok'=>true,'result'=>self::inspection($pdo,$request,$identity,$environment['disabled_config_sha256'])];
            if($request['action']==='inspect_activation'){
                $seen=self::inspection($pdo,$request,$identity,$environment['disabled_config_sha256'],true);self::need($seen['complete']===true&&$seen['capability_state']==='same','activation_schema_or_certificate');
                $current=$seen['plan'];$current['permanent_definitions_sha256']=$request['plan']['permanent_definitions_sha256'];self::need(self::hash($current)===$request['plan_sha256'],'activation_structural_plan_changed');
                [$fresh]=$this->context($request);self::need($fresh['config_sha256']===$environment['config_sha256'],'config_changed');PostedTelegramNativeSchema::requireEnabled($pdo);
                return['ok'=>true,'result'=>['activation_reconciled'=>true,'certificate_sha256'=>$seen['certificate_sha256'],'plan_sha256'=>$request['plan_sha256'],'data_writes'=>0,'ddl_statements'=>0,'ordinary_rows_preserved'=>true]];
            }
            self::need(in_array($request['phase'],['enable_started','enabled'],true)&&is_array($request['plan'])&&self::sha($request['plan_sha256'])&&self::hash($request['plan'])===$request['plan_sha256'],'explicit_enable_plan_required');
            $connection=(int)self::scalar($pdo,'SELECT CONNECTION_ID()');self::need($connection>0,'connection');
            foreach(self::LOCKS as$name){self::need((int)self::scalar($pdo,'SELECT GET_LOCK(?,5)',[$name])===1,'cron_busy');$locks[]=$name;}
            foreach(self::LOCKS as$name)self::need((int)self::scalar($pdo,'SELECT IS_USED_LOCK(?)',[$name])===$connection,'cron_lock_not_owned');
            [$fresh]=$this->context($request);self::need($fresh['config_sha256']===$environment['config_sha256'],'config_changed');$ready=self::inspection($pdo,$request,$identity,$environment['disabled_config_sha256']);
            self::need($ready['complete']===true&&$ready['plan_sha256']===$request['plan_sha256'],'plan_stale');
            if($ready['capability_state']==='same'){
                PostedTelegramNativeSchema::requireEnabled($pdo);
                return['ok'=>true,'result'=>['enabled'=>true,'recognized_existing_exact_certificate'=>true,'certificate_sha256'=>$ready['certificate_sha256'],'data_writes'=>0,'ddl_statements'=>0]];
            }
            self::need($request['phase']==='enable_started'&&$request['allow_insert']===true,'unknown_absent_certificate_no_automatic_retry');
            $pdo->beginTransaction();$q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1 FOR UPDATE');$q->execute([PostedTelegramNativeSchema::KEY]);self::need($q->fetchColumn()===false,'certificate_already_exists');
            $ready=self::inspection($pdo,$request,$identity,$environment['disabled_config_sha256']);self::need($ready['plan_sha256']===$request['plan_sha256']&&$ready['capability_state']==='absent','plan_stale');
            [$fresh]=$this->context($request);self::need($fresh['config_sha256']===$environment['config_sha256'],'config_changed');
            $q=$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value)VALUES(?,?)');$q->execute([PostedTelegramNativeSchema::KEY,PostedTelegramNativeContent::json($ready['plan']['certificate'])]);self::need($q->rowCount()===1&&$pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_ASSOC)===[],'certificate_insert_warning');
            $write='commit_outcome_unknown';$pdo->commit();$write='committed';PostedTelegramNativeSchema::requireEnabled($pdo);
            return['ok'=>true,'result'=>['enabled'=>true,'recognized_existing_exact_certificate'=>false,'certificate_sha256'=>$ready['certificate_sha256'],'data_writes'=>1,'ddl_statements'=>0]];
        }catch(Throwable$e){try{if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();}catch(Throwable){}return['ok'=>false,'error'=>['code'=>'NATIVE_CLEAN_FAILED','message'=>'Активация Native не подтверждена. Очереди остаются выключенными.'],'capability_write'=>$write,'safe_reason'=>$e instanceof InstallerNativeCleanFailure?$e->getMessage():'internal_failure'];}
        finally{if($pdo instanceof PDO)foreach(array_reverse($locks)as$name)try{self::scalar($pdo,'SELECT RELEASE_LOCK(?)',[$name]);}catch(Throwable){}}
    }
}

if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');
    set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException('Native clean installer error',0,$severity,$file,$line);});
    try{$raw=stream_get_contents(STDIN,65537);if(!is_string($raw)||strlen($raw)>65536)throw new RuntimeException('input');$request=$raw===''?[]:json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($request)||array_is_list($request)&&$request!==[])throw new RuntimeException('input');$result=(new InstallerNativeClean())->run($request);}catch(Throwable){$result=['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>'Некорректное задание установщика.']] ;}
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($result['ok']?0:1);
}
