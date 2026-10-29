<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-native-template-preview.php';
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

trait GitPostedTelegramNativeTemplateActorUi
{
    private function telegramNativeTemplateListLabel(array$template):string
    {
        try{$header=PostedTelegramNativeTemplate::load($this->pdo,$template);$r=PostedTelegramNativeTemplatePreview::load($this->pdo,(int)$template['id'],(int)$template['owner_id']);
            return(!$header['saved']||($r!==null&&$r['phase']!=='shown')?'⏳ ':'').PostedTelegramNativeTemplate::label($template);
        }catch(Throwable){return'⚠️ Шаблон требует проверки';}
    }
    private function showTelegramNativeTemplatePendingStatus(int$actor,array$template):bool
    {
        $id=(int)$template['id'];try{$header=PostedTelegramNativeTemplate::load($this->pdo,$template);$r=PostedTelegramNativeTemplatePreview::load($this->pdo,$id,$actor);if($r===null||$r['phase']==='shown')return false;
            $text='⏳ '.PostedTelegramNativeTemplate::label($template).".\n\n".'Предпросмотр шаблона не подтверждён. Принятое содержимое и настройки сохранены. Повторная отправка не выполнялась.';
            $rows=[[['text'=>'Удалить','callback_data'=>'tmedia:delete:'.$id.':'.$header['revision']]],[['text'=>'Назад','callback_data'=>'tpl_scope:'.(int)($template['channel_id']??0)]]];
        }catch(Throwable){$text='Данные шаблона требуют проверки. Повторная отправка не выполнялась.';$rows=[[['text'=>'Назад','callback_data'=>'tpl_scope:'.(int)($template['channel_id']??0)]]];}
        $this->telegram->sendMessage($actor,$text,['reply_markup'=>['inline_keyboard'=>$rows]]);return true;
    }
    private function telegramNativeTemplateCurrentClaim(int$actor,int$id,string$nonce,string$fingerprint):bool
    {
        return$this->withTelegramTemplateLock($actor,$id,function(array$template)use($actor,$id,$nonce,$fingerprint):bool{
            $current=PostedTelegramNativeTemplate::fingerprint($template,PostedTelegramPostOptions::loadTemplate($this->pdo,$template));if(!hash_equals($fingerprint,$current))throw new RuntimeException('native_template_preview_content_changed');
            PostedTelegramNativeTemplatePreview::currentLocked($this->pdo,$template,$actor,$nonce,$fingerprint);return true;
        })===true;
    }
    /** Caller holds the existing actor-input and template-preview advisory locks. */
    private function renderTelegramNativeTemplateUnlocked(int$actor,int$id,string$mode):bool
    {
        if($this->pdo->inTransaction())return false;$receipt=null;
        try{
            $claim=$this->withTelegramTemplateLock($actor,$id,function(array$template)use($actor,$mode):array{
                if(!PostedTelegramNativeTemplate::isNative($template))throw new RuntimeException('native_template_kind_changed');
                PostedTelegramTemplateMedia::assertReady($this->pdo,$template);$post=$this->telegramTemplatePost($template,$actor);$plan=$this->telegramPreviewPlan($post);$fp=PostedTelegramNativeTemplate::fingerprint($template,PostedTelegramPostOptions::loadTemplate($this->pdo,$template));
                $prepared=PostedTelegramNativeTemplatePreview::prepareLocked($this->pdo,$template,$actor,$plan,$mode,$fp);
                return array_merge($prepared,['template'=>$template,'post'=>$post,'plan'=>$plan,'fingerprint'=>$fp]);
            });if(!is_array($claim))return false;if($claim['reuse'])return true;
            $receipt=$claim['receipt'];$nonce=$receipt['nonce'];$fp=$claim['fingerprint'];$plan=$claim['plan'];$descriptor=PostedTelegramNativeContent::normalize($plan['native_descriptor']);$request=PostedTelegramNativeContent::request($descriptor);
            if(($plan['mode']??null)!=='native'||($plan['native_request']??null)!==$request||($plan['media']??null)!==[]||($plan['text_parts']??null)!==[])throw new RuntimeException('native_template_plan_changed');
            $notice=$plan['native_notice']??'';if(!is_string($notice))throw new RuntimeException('native_template_notice');$requests=[];
            if($notice!=='')$requests[]=['notice'=>$notice,'send'=>fn():array=>$this->telegram->sendMessage($actor,$notice)];
            $requests[]=['native'=>$descriptor,'send'=>fn():array=>$this->telegram->call($request['method'],array_merge($request['params'],['chat_id'=>(string)$actor]))];
            $ids=[];foreach($requests as$step){
                if(!$this->telegramNativeTemplateCurrentClaim($actor,$id,$nonce,$fp))throw new RuntimeException('native_template_preview_authority_changed');
                $result=($step['send'])();$projection=$this->telegramTemplateResponseProjection($result,$actor);$ids=array_values(array_unique(array_merge($ids,$projection['ids'])));
                PostedTelegramNativeTemplatePreview::record($this->pdo,$id,$actor,$nonce,function(array$r)use($ids):array{$r['media_ids']=$ids;$r['phase']='media_accepted';return$r;});
                if(!$projection['valid'])throw new RuntimeException('Native template response is malformed.');PostedTelegramPreviewProof::requireKnownIds($projection['ids'],1);
                if(isset($step['native'])){if(!PostedTelegramNativeContent::acknowledgement($step['native'],$result,(string)$actor)['known'])throw new RuntimeException('Native template content is not confirmed.');}
                elseif(($result['result']['text']??null)!==$step['notice'])throw new RuntimeException('Native template notice is not confirmed.');
            }
            PostedTelegramPreviewProof::requireKnownIds($ids,$receipt['expected']);
            if(!$this->telegramNativeTemplateCurrentClaim($actor,$id,$nonce,$fp))throw new RuntimeException('native_template_preview_authority_changed');
            $text=$mode==='view'?'Выберите, что сделать с шаблоном.':'Настройте пост перед тем, как добавить его в шаблоны.';
            $keyboard=$this->telegramTemplateKeyboard($claim['template'],$mode);$result=$this->telegram->sendMessage($actor,$text,['reply_markup'=>['inline_keyboard'=>$keyboard]]);$projection=$this->telegramTemplateResponseProjection($result,$actor);$controls=$projection['ids'];
            if(count($controls)===1){$control=$controls[0];PostedTelegramNativeTemplatePreview::record($this->pdo,$id,$actor,$nonce,function(array$r)use($control):array{$r['control_id']=$control;return$r;});}
            elseif($controls!==[])PostedTelegramNativeTemplatePreview::record($this->pdo,$id,$actor,$nonce,function(array$r)use($controls):array{$r['media_ids']=array_values(array_unique(array_merge($r['media_ids'],$controls)));return$r;});
            if(!$projection['valid']||($result['result']['text']??null)!==$text)throw new RuntimeException('Native template control is not confirmed.');PostedTelegramPreviewProof::requireKnownIds($controls,1);$control=$controls[0];if(in_array($control,$ids,true))throw new RuntimeException('Native template control reused content ID.');
            $applied=$this->withTelegramTemplateLock($actor,$id,function(array$template)use($actor,$nonce,$fp):bool{
                $current=PostedTelegramNativeTemplate::fingerprint($template,PostedTelegramPostOptions::loadTemplate($this->pdo,$template));if(!hash_equals($fp,$current))throw new RuntimeException('native_template_preview_content_changed');
                PostedTelegramNativeTemplatePreview::shownLocked($this->pdo,$template,$actor,$nonce,$fp);return true;
            });if($applied!==true)throw new RuntimeException('native_template_preview_authority_changed');
            $canRetire=$this->withTelegramTemplateLock($actor,$id,function(array$template)use($actor,$nonce,$fp):bool{$r=PostedTelegramNativeTemplatePreview::load($this->pdo,(int)$template['id'],$actor,true);return$r!==null&&$r['phase']==='shown'&&hash_equals($nonce,$r['nonce'])&&hash_equals($fp,PostedTelegramNativeTemplate::fingerprint($template,PostedTelegramPostOptions::loadTemplate($this->pdo,$template)));});
            $old=$claim['old'];if($canRetire===true&&is_array($old)&&$old['actor_id']===$actor){$obsolete=array_diff(array_merge($old['media_ids'],[$old['control_id']]),array_merge($ids,[$control]));foreach(array_unique($obsolete)as$mid)if(is_int($mid)&&$mid>0){try{$this->telegram->call('deleteMessage',['chat_id'=>(string)$actor,'message_id'=>$mid]);}catch(Throwable){}}}
            return true;
        }catch(Throwable$error){
            if(is_array($receipt))try{PostedTelegramNativeTemplatePreview::record($this->pdo,$id,$actor,$receipt['nonce'],function(array$r):array{$r['phase']='unknown';return$r;});}catch(Throwable){}
            error_log('GitPosted native template preview held: '.get_class($error));
            // A revoked former owner can record its outcome, but cannot cause another private effect.
            $current=$this->ownedTemplate($actor,$id);$user=$this->user($actor);if(is_array($current)&&is_array($user)&&(int)($user['is_active']??0)===1&&PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo))$this->telegram->sendMessage($actor,'Предпросмотр шаблона не подтверждён. Принятое содержимое не изменено. Повторная отправка не выполнялась.');
            return false;
        }
    }
}
