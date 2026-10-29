<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

require_once __DIR__.'/telegram-native-template-actor-ui.php';

trait GitPostedTelegramNativeTemplateUi
{
    use GitPostedTelegramNativeTemplateActorUi;
    /** Called only from the authenticated private template-content route after channel preflight. */
    private function createTelegramNativeTemplateFromMessage(int $actor,int $scope,array $message,array $expected):bool
    {
        try{$descriptor=PostedTelegramNativeContent::fromMessage($message);}catch(InvalidArgumentException){$this->telegram->sendMessage($actor,'Этот вид содержимого не поддерживает описание или выбранные дополнительные поля. Шаблон не изменён.');return true;}
        if($descriptor===null)return false;
        if($this->pdo->inTransaction())return true;
        if(($message['chat']['type']??null)!=='private'||(string)($message['chat']['id']??'')!==(string)$actor||(string)($message['from']['id']??'')!==(string)$actor||!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo))return true;
        $id=0;PostedTelegramRootedAccess::begin($this->pdo,$this->config);try{
            PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
            if($scope>0)PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$actor,$scope);
            if($scope>0){$q=$this->pdo->prepare('SELECT id FROM posted_channels WHERE id=? AND is_active=1 AND added_by IS NOT NULL AND added_by>0 LIMIT 1 FOR UPDATE');$q->execute([$scope]);if($q->fetchColumn()===false){PostedTelegramRootedAccess::rollback($this->pdo);return true;}}
            if(!$this->lockActiveTelegramActor($actor)){PostedTelegramRootedAccess::rollback($this->pdo);return true;}
            $q=$this->pdo->prepare('SELECT id FROM posted_templates WHERE owner_id=? ORDER BY id FOR UPDATE');$q->execute([$actor]);$q->fetchAll(PDO::FETCH_COLUMN);
            $q=$this->pdo->prepare("SELECT telegram_id FROM posted_users WHERE telegram_id=? AND is_active=1 AND state='await_template_content' AND state_data <=> CAST(? AS JSON) LIMIT 1 FOR UPDATE");$q->execute([$actor,$this->encodeJson($expected)]);if($q->fetchColumn()===false){PostedTelegramRootedAccess::rollback($this->pdo);return true;}
            if((int)($expected['scope_id']??0)!==$scope)throw new RuntimeException('native_template_scope_changed');
            if($scope>0){$q=$this->pdo->prepare('SELECT can_post FROM posted_permissions WHERE user_id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$actor,$scope]);if((int)$q->fetchColumn()!==1){PostedTelegramRootedAccess::rollback($this->pdo);return true;}}
            PostedTelegramNativeSchema::requireEnabled($this->pdo);
            $title=PostedTelegramNativeTemplate::label(['media_type'=>$descriptor['kind']]);$q=$this->pdo->prepare('INSERT INTO posted_templates(owner_id,channel_id,title,text,text_entities_json,media_type,media_file_id) VALUES(?,?,?,NULL,NULL,?,?)');$q->execute([$actor,$scope>0?$scope:null,$title,$descriptor['kind'],$descriptor['payload']['file_id']??null]);$id=(int)$this->pdo->lastInsertId();
            $q=$this->pdo->prepare('SELECT * FROM posted_templates WHERE id=? AND owner_id=? LIMIT 1 FOR UPDATE');$q->execute([$id,$actor]);$template=$q->fetch();if(!is_array($template))throw new RuntimeException('native_template_insert_missing');
            PostedTelegramNativeStore::captureLocked($this->pdo,$template,$descriptor,'t');$token=$expected['input_token']??null;if(!is_string($token)||preg_match('/\A[a-f0-9]{24}\z/D',$token)!==1)$token=bin2hex(random_bytes(12));PostedTelegramNativeTemplate::saveLocked($this->pdo,$template,false,$token,true);
            $q=$this->pdo->prepare("UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND is_active=1 AND state='await_template_content' AND state_data <=> CAST(? AS JSON)");$q->execute([$actor,$this->encodeJson($expected)]);if($q->rowCount()!==1)throw new RuntimeException('native_template_input_changed');PostedTelegramRootedAccess::commit($this->pdo);
        }catch(Throwable$e){if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);error_log('GitPosted native template input: '.get_class($e));$this->telegram->sendMessage($actor,'Не удалось сохранить этот вид содержимого. Исходный ввод и остальные шаблоны не изменены.');return true;}
        $this->showTemplateEditor($actor,$id);return true;
    }
    private function telegramNativeTemplateKeyboard(array $template,string $mode):array
    {
        $v=PostedTelegramNativeTemplate::load($this->pdo,$template);$id=(int)$template['id'];$revision=$v['revision'];$cb=static fn(string$a):string=>'tmedia:'.$a.':'.$id.':'.$revision;
        if($mode==='view')return[[['text'=>'Опубликовать','callback_data'=>$cb('use')],['text'=>'Изменить','callback_data'=>$cb('editor')]],[['text'=>'Удалить','callback_data'=>$cb('delete')]],[['text'=>'← Назад','callback_data'=>'tpl_scope:'.(int)($template['channel_id']??0)]]];
        return[[['text'=>'Удалить медиа','callback_data'=>$cb('remove')]],[['text'=>'URL-кнопки','callback_data'=>$cb('buttons')]],[['text'=>'← Отменить','callback_data'=>$cb('cancel')],['text'=>'Готово →','callback_data'=>$cb('done')]]];
    }
    private function updateTelegramNativeTemplateButtons(int $actor,int $id,string $state,array $message,array $expected,array $data):bool
    {
        $template=$this->ownedTemplate($actor,$id);if(!$template||!PostedTelegramNativeTemplate::isNative($template))return false;
        if($state!=='await_template_edit_buttons'){$this->telegram->sendMessage($actor,'Этот вид содержимого не поддерживает описание или замену медиа. Сохранённый шаблон не изменён.');return true;}
        $buttons=$this->parseButtonsInput($message);if($buttons===null){$this->telegram->sendMessage($actor,'Не удалось распознать кнопки. Сохранённые кнопки не изменены.');return true;}
        try{$ok=$this->withTelegramTemplateLock($actor,$id,function(array$t)use($actor,$id,$expected,$data,$buttons):bool{
            if(!$this->telegramTemplateInputMatches($actor,$expected)||!PostedTelegramNativeTemplate::isNative($t))return false;PostedTelegramTemplateMedia::assertReady($this->pdo,$t);PostedTelegramNativeTemplatePreview::assertActorReady($this->pdo,$t,$actor,true);$v=PostedTelegramNativeTemplate::load($this->pdo,$t,true);if(isset($data['template_revision'])&&$data['template_revision']!==$v['revision'])return false;
            $this->pdo->prepare('UPDATE posted_templates SET buttons_json=? WHERE id=? AND owner_id=?')->execute([$buttons!==[]?$this->encodeJson($buttons):null,$id,$actor]);PostedTelegramNativeTemplate::saveLocked($this->pdo,$t);$this->clearTelegramTemplateInput($actor,$id);return true;
        });if($ok===true)$this->showTemplateEditor($actor,$id);
        }catch(Throwable$e){error_log('GitPosted native template buttons: '.get_class($e));$this->telegram->sendMessage($actor,'Шаблон не изменён. Проверьте введённые данные или откройте его заново.');}return true;
    }
}
