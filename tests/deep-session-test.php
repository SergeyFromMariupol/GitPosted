<?php
declare(strict_types=1);
/** Real PHP files-session GC, isolated temporary directory; no app config/data. */
$directory=rtrim(sys_get_temp_dir(),'/\\').'/gitposted-session-check-'.bin2hex(random_bytes(10));
if(!mkdir($directory,0700)||realpath($directory)!==$directory)throw new RuntimeException('Fixture directory failed');
$child= <<<'PHP'
$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
ini_set('session.save_path',$input['directory']);
ini_set('session.gc_maxlifetime','3600');
ini_set('session.gc_probability',$input['collect']?'1':'0');
ini_set('session.gc_divisor','1');
ini_set('session.lazy_write','1');
session_id($input['id']);
if(!session_start())exit(2);
if(!session_write_close())exit(3);
PHP;
function sessionFixtureChild(string $code,array $input):void {
    $pipes=[];$process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('PHP fixture did not start');
    fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($process)!==0||$out!==''||$err!=='')throw new RuntimeException('PHP session fixture failed');
}
function sessionFixtureAssert(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
try {
    $old=$directory.'/sess_expiredFixture123';$fresh=$directory.'/sess_activeFixture456';
    file_put_contents($old,'marker|s:7:"fixture";');chmod($old,0600);touch($old,time()-7200);
    file_put_contents($fresh,'marker|s:7:"fixture";');chmod($fresh,0600);touch($fresh,time()-1800);
    $started=time();
    sessionFixtureChild($child,['directory'=>$directory,'collect'=>false,'id'=>'activeFixture456']);
    clearstatcache(true,$fresh);
    sessionFixtureAssert(filemtime($fresh)>=$started,'Active session timestamp was not refreshed by PHP lazy-write');
    echo "PASS native PHP refreshes the active session timestamp without changing its data\n";
    sessionFixtureChild($child,['directory'=>$directory,'collect'=>true,'id'=>'gcTriggerFixture789']);
    clearstatcache();
    sessionFixtureAssert(!file_exists($old),'Native GC did not remove the expired session in the custom directory');
    sessionFixtureAssert(file_exists($fresh)&&file_get_contents($fresh)==='marker|s:7:"fixture";','Native GC removed or changed the fresh session');
    echo "PASS native PHP GC expires old custom-path sessions and preserves fresh authenticated data\n";
}finally {
    if(realpath($directory)!==$directory||dirname($directory)!==rtrim(sys_get_temp_dir(),'/\\'))throw new RuntimeException('Unsafe fixture cleanup');
    foreach(scandir($directory)as$name){
        if($name==='.'||$name==='..')continue;
        $path=$directory.'/'.$name;
        if(!preg_match('/\Asess_[A-Za-z0-9,-]+\z/D',$name)||is_link($path)||!is_file($path))throw new RuntimeException('Unexpected session fixture member');
        unlink($path);
    }
    rmdir($directory);
}
