<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

trait GitPostedTelegramTemplateMediaUi
{
    private ?array $telegramTemplateIngress = null;
    private array $telegramTemplateDeferred = [];

    private function telegramTemplateUsesVectorUi(array $template):bool
    { return PostedTelegramNativeTemplate::isNative($template)||PostedTelegramCaptionPlacement::load($this->pdo,$template,'t')!==null||PostedTelegramTemplateMedia::load($this->pdo,$template)['album']; }

    private function telegramTemplateListLabel(array $template):string
    {
        if(PostedTelegramNativeTemplate::isNative($template))return$this->telegramNativeTemplateListLabel($template);
        try{$value=PostedTelegramTemplateMedia::load($this->pdo,$template);$t=PostedTelegramTemplateMedia::hydrate($this->pdo,$template);$prefix=$value['saved']?'':'⏳ ';
            $c=PostedTelegramTemplateMedia::read($this->pdo,PostedTelegramTemplateMedia::collectionKey((int)$template['id']));$p=PostedTelegramTemplateMedia::read($this->pdo,PostedTelegramTemplateMedia::previewKey((int)$template['id']));
            if(($c!==null&&!in_array(PostedTelegramTemplateMedia::collection($c)['phase'],['shown','closed'],true))||($p!==null&&PostedTelegramTemplateMedia::preview($p,(int)$template['id'])['phase']!=='shown'))$prefix='⏳ ';
            return$prefix.PostedTelegramTemplateMedia::label($t);
        }catch(Throwable){return'⚠️ Шаблон требует проверки';}
    }
    /** SQL-owned data remains discoverable after an interrupted input or cleared private history. */
    private function showTelegramTemplatePendingStatus(int $actor,int $id):bool
    {
        if(!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo))return true;
        $q=$this->pdo->prepare('SELECT t.* FROM posted_templates t JOIN posted_users u ON u.telegram_id=t.owner_id AND u.is_active=1 WHERE t.id=? AND t.owner_id=? LIMIT 1');$q->execute([$id,$actor]);$t=$q->fetch();if(!is_array($t))return false;$v=PostedTelegramTemplateMedia::class;
        if(PostedTelegramNativeTemplate::isNative($t))return$this->showTelegramNativeTemplatePendingStatus($actor,$t);
        try{
            $value=$v::load($this->pdo,$t);$c=$v::read($this->pdo,$v::collectionKey($id));$p=$v::read($this->pdo,$v::previewKey($id));$phase=null;
            if($c!==null){$c=$v::collection($c);if(!in_array($c['phase'],['shown','closed'],true))$phase=$c['phase'];}
            if($p!==null){$p=$v::preview($p,$id);if($p['phase']!=='shown')$phase=$p['phase'];}
            if($phase===null)return false;
            $text=PostedTelegramNativeTemplate::isNative($t)?'⏳ '.PostedTelegramNativeTemplate::label($t).".\n\n":'⏳ Файлы шаблона: '.count($value['items']).".\n\n";
            $text.=$phase==='collecting'?'Альбом ещё загружается. Принятые файлы, подпись и настройки сохранены. Предпросмотр пока не отправлен.':'Предпросмотр шаблона не подтверждён. Принятые файлы, подпись и настройки сохранены. Повторная отправка не выполнялась.';
            if(PostedTelegramNativeTemplate::isNative($t))$text='⏳ '.PostedTelegramNativeTemplate::label($t).".\n\n".'Предпросмотр шаблона не подтверждён. Принятое содержимое и настройки сохранены. Повторная отправка не выполнялась.';
            $rows=[[['text'=>'Удалить','callback_data'=>'tmedia:delete:'.$id.':'.$value['revision']]],[['text'=>'Назад','callback_data'=>'tpl_scope:'.(int)($t['channel_id']??0)]]];
        }catch(Throwable){$text='Данные шаблона требуют проверки. Повторная отправка не выполнялась.';$rows=[[['text'=>'Назад','callback_data'=>'tpl_scope:'.(int)($t['channel_id']??0)]]];}
        $this->telegram->sendMessage($actor,$text,['reply_markup'=>['inline_keyboard'=>$rows]]);return true;
    }

    private function updateTelegramTemplateMediaDraft(int $actor,int $id,string $state,array $message):void
    {
        $expected=$this->user($actor);$data=$this->stateData($expected);
        if(($expected['state']??null)!==$state||(int)($data['template_id']??0)!==$id)return;
        if($this->updateTelegramNativeTemplateButtons($actor,$id,$state,$message,$expected,$data))return;
        $v=PostedTelegramTemplateMedia::class;
        try{
            $content=in_array($state,['await_template_edit_text','await_template_edit_buttons'],true)?$this->telegramTextInputContent($message):$this->contentFromMessage($message);
            $grouped=false;$placementRefresh=false;$ok=$this->withTelegramTemplateLock($actor,$id,function(array$template)use($actor,$id,$state,$message,$expected,$data,$content,$v,&$grouped,&$placementRefresh):bool{
                if(!$this->telegramTemplateInputMatches($actor,$expected))return false;$v::assertReady($this->pdo,$template);$stored=$v::load($this->pdo,$template,true);
                if(isset($data['template_revision'])&&$data['template_revision']!==$stored['revision'])return false;
                $items=$v::legacy($template);
                if($state==='await_template_edit_buttons'){
                    if(count($items)>1)throw new RuntimeException('Кнопки недоступны для альбомов.');
                    $buttons=$this->parseButtonsInput($message);
                    if($buttons===null)throw new RuntimeException('Не удалось распознать кнопки. Сохранённые кнопки не изменены.');
                    $this->pdo->prepare('UPDATE posted_templates SET buttons_json=? WHERE id=? AND owner_id=?')->execute([$buttons!==[]?$this->encodeJson($buttons):null,$id,$actor]);$v::saveLocked($this->pdo,$template,$items);
                }elseif($state==='await_template_edit_text'){
                    if($content['text']==='')return false;
                    $this->pdo->prepare('UPDATE posted_templates SET text=?,text_entities_json=?,title=? WHERE id=? AND owner_id=?')->execute([$content['text'],$content['text_entities']!==[]?$this->encodeJson($content['text_entities']):null,$this->templateTitle($content['text'],(string)$template['media_type']),$id,$actor]);$v::saveLocked($this->pdo,$template,$items);
                }else{
                    if($content['media_file_id']===null)return false;$captionAbove=PostedTelegramCaptionPlacement::input($message,$content);$placementRefresh=PostedTelegramCaptionPlacement::load($this->pdo,$template,'t')!==null;PostedTelegramCaptionPlacement::captureLocked($this->pdo,$template,false,'t');$items=$v::vector($content['media']);$options=PostedTelegramPostOptions::loadTemplate($this->pdo,$template);
                    if(($options['media_spoiler']&&array_diff(array_column($items,'type'),['photo','video','animation'])!==[])||($options['paid_media_stars']!==null&&(array_diff(array_column($items,'type'),['photo','video'])!==[]||$content['media_spoiler'])))throw new RuntimeException('Новые медиа несовместимы с сохранёнными настройками.');
                    PostedTelegramSourceSpoiler::detachLocked($this->pdo,$template,'template');$v::saveLocked($this->pdo,$template,$items);
                    if($content['text']!=='')$this->pdo->prepare('UPDATE posted_templates SET text=?,text_entities_json=?,title=? WHERE id=? AND owner_id=?')->execute([$content['text'],$content['text_entities']!==[]?$this->encodeJson($content['text_entities']):null,$this->templateTitle($content['text'],$items[0]['type']),$id,$actor]);
                    $placementQuery=$this->pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1 FOR UPDATE');$placementQuery->execute([$id]);$placementRow=$placementQuery->fetch();if(!is_array($placementRow))throw new RuntimeException('caption_placement_template_missing');$placementTemplate=PostedTelegramTemplateMedia::hydrate($this->pdo,$placementRow);$placementTemplate['_telegram_post_options']=$options;PostedTelegramCaptionPlacement::captureLocked($this->pdo,$placementTemplate,$captionAbove,'t');
                    if($content['media_spoiler'])PostedTelegramPostOptions::saveTemplateLocked($this->pdo,array_replace($template,['media_type'=>$items[0]['type'],'media_file_id'=>$items[0]['file_id']]),['media_spoiler'=>true]);
                    $grouped=$this->registerTelegramTemplateAlbumLocked($actor,$template,$message,$expected);
                }
                if(!$grouped)$this->clearTelegramTemplateInput($actor,$id);return true;
            });if($ok===true&&!$grouped)if($placementRefresh)$this->renderTelegramTemplateMedia($actor,$id);else$this->showTemplateEditor($actor,$id);
        }catch(Throwable$error){error_log('GitPosted template edit: '.$error::class);$this->telegram->sendMessage($actor,'Шаблон не изменён. Проверьте введённые данные или откройте его заново.');}
    }

    private function finishTelegramTemplateMedia(int $actor,int $id,?int $revision=null):void
    {
        $v=PostedTelegramTemplateMedia::class;$scope=$this->withTelegramTemplateLock($actor,$id,function(array$t)use($actor,$id,$revision,$v):?int{
            $v::assertReady($this->pdo,$t);if(PostedTelegramNativeTemplate::isNative($t))PostedTelegramNativeTemplatePreview::assertActorReady($this->pdo,$t,$actor,true);$value=$v::load($this->pdo,$t,true);if($revision!==null&&$revision!==$value['revision'])return null;
            if(!$value['saved']){if(PostedTelegramNativeTemplate::isNative($t))PostedTelegramNativeTemplate::saveLocked($this->pdo,$t,true);else $v::saveLocked($this->pdo,$t,$value['items'],true);}$this->clearTelegramTemplateInput($actor,$id);return(int)($t['channel_id']??0);
        });if(is_int($scope))$this->showTemplateList($actor,$scope);else$this->telegram->sendMessage($actor,'Меню устарело. Шаблон не изменён.');
    }
    private function deleteTelegramTemplateMedia(int $actor,int $id,bool $cancel=false,?int $revision=null):void
    {
        $v=PostedTelegramTemplateMedia::class;$scope=$this->withTelegramTemplateLock($actor,$id,function(array$t)use($actor,$id,$cancel,$revision,$v):?int{
            $value=$v::load($this->pdo,$t,true);if($revision!==null&&$value['revision']!==$revision)return null;
            if(!$cancel||!$value['saved']){$v::deleteLocked($this->pdo,$t);$this->pdo->prepare('DELETE FROM posted_templates WHERE id=? AND owner_id=?')->execute([$id,$actor]);}
            $this->clearTelegramTemplateInput($actor,$id);return(int)($t['channel_id']??0);
        });if(is_int($scope))$this->showTemplateList($actor,$scope);else$this->telegram->sendMessage($actor,'Меню устарело. Шаблон не изменён.');
    }

    private function withTelegramTemplateLock(int $actor,int $id,callable $callback):mixed
    {
        if($this->pdo->inTransaction())throw new LogicException('Template operation starts outside SQL.');
        if(!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo))return null;
        $hint=$this->ownedTemplate($actor,$id);if(!$hint)return null;$scope=(int)($hint['channel_id']??0);
        PostedTelegramRootedAccess::begin($this->pdo,$this->config);try{
            PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
            if($scope>0)PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$actor,$scope);
            if($scope>0){$s=$this->pdo->prepare('SELECT id FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$scope]);if($s->fetchColumn()===false){PostedTelegramRootedAccess::rollback($this->pdo);return null;}}
            if(!$this->lockActiveTelegramActor($actor)){PostedTelegramRootedAccess::rollback($this->pdo);return null;}
            $s=$this->pdo->prepare('SELECT * FROM posted_templates WHERE id=? AND owner_id=? LIMIT 1 FOR UPDATE');$s->execute([$id,$actor]);$template=$s->fetch();
            if(!is_array($template)||(int)($template['channel_id']??0)!==$scope){PostedTelegramRootedAccess::rollback($this->pdo);return null;}
            $result=$callback(PostedTelegramTemplateMedia::hydrate($this->pdo,$template));PostedTelegramRootedAccess::commit($this->pdo);return$result;
        }catch(Throwable$error){if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);throw$error;}
    }

    private function telegramTemplateInputMatches(int $actor,array $expected):bool
    {
        $s=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE');$s->execute([$actor]);$u=$s->fetch();
        return is_array($u)&&($u['state']??null)===($expected['state']??null)
            &&PostedTelegramTemplateMedia::json($this->decodeJson((string)($u['state_data']??'')))===PostedTelegramTemplateMedia::json($this->decodeJson((string)($expected['state_data']??'')));
    }
    private function clearTelegramTemplateInput(int $actor,int $id):void
    {
        $u=$this->user($actor);$data=$this->stateData($u);if((int)($data['template_id']??0)!==$id||!in_array($u['state']??null,['collecting_template_media','await_template_edit_text','await_template_edit_media','await_template_edit_buttons','editing_template'],true))return;
        $this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND state<=>? AND state_data<=>CAST(? AS JSON)')->execute([$actor,$u['state'],$u['state_data']]);
    }
    private function registerTelegramTemplateAlbumLocked(int $actor,array $template,array $message,array $expected):bool
    {
        $v=PostedTelegramTemplateMedia::class;$group=PostedTelegramAlbumCollector::group($message,$actor);
        if($group===null||$this->telegramTemplateIngress===null)return false;
        if(!$this->pdo->inTransaction()||!$this->telegramTemplateInputMatches($actor,$expected))throw new RuntimeException('Ввод шаблона устарел.');
        $inputKey=$v::inputKey($actor,$group);if($v::read($this->pdo,$inputKey,true)!==null)throw new RuntimeException('Альбом уже принят.');
        $id=(int)$template['id'];$item=PostedTelegramAlbumCollector::item($message,$this->contentFromMessage($message));
        $input=['state'=>'collecting_template_media','state_data'=>$this->encodeJson(['template_id'=>$id,'group_id'=>$group,'epoch'=>bin2hex(random_bytes(16))])];
        $data=$this->decodeJson($input['state_data']);$record=$v::collection(['version'=>1,'template_id'=>$id,'actor_id'=>$actor,'group_id'=>$group,'epoch'=>$data['epoch'],'items'=>[$item],'revision'=>1,'last_ms'=>$v::now(),'phase'=>'collecting','input'=>$input]);
        $v::write($this->pdo,$v::collectionKey($id),$record);$v::write($this->pdo,$inputKey,['version'=>1,'template_id'=>$id,'epoch'=>$record['epoch']]);
        $this->pdo->prepare('UPDATE posted_users SET state=?,state_data=? WHERE telegram_id=? AND state<=>? AND state_data<=>CAST(? AS JSON)')->execute([$input['state'],$input['state_data'],$actor,$expected['state'],$expected['state_data']]);
        $this->telegramTemplateDeferred[$id]=true;return true;
    }

    /** Template album ingress is disjoint from post album ingress and is checked before it. */
    private function handleTelegramTemplateMediaIngress(array $message):bool
    {
        if($this->telegramTemplateIngress!==null)return false;$actor=$message['from']['id']??null;if(!is_int($actor)||$actor<=0)return false;
        $group=PostedTelegramAlbumCollector::group($message,$actor);if($group===null)return false;
        if(!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo))return false;$u=$this->user($actor);if(!$this->isKnownActiveUser($u))return false;
        $v=PostedTelegramTemplateMedia::class;$known=$v::read($this->pdo,$v::inputKey($actor,$group));$state=$u['state']??null;
        if($known===null&&!in_array($state,['await_template_content','await_template_edit_media','collecting_template_media'],true))return false;
        $lock='tg_template_input_'.substr(hash('sha256',(string)$actor),0,32);$s=$this->pdo->prepare('SELECT GET_LOCK(?,10)');$s->execute([$lock]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Ввод шаблона занят.');
        try{
            $u=$this->user($actor);$state=$u['state']??null;$data=$this->stateData($u);
            if($known!==null){
                if(($known['version']??null)!==1||!is_int($known['template_id']??null)||!is_string($known['epoch']??null))throw new RuntimeException('Ввод шаблона повреждён.');
                if($state!=='collecting_template_media'||($data['group_id']??null)!==$group||(int)($data['template_id']??0)!==$known['template_id']||($data['epoch']??null)!==$known['epoch'])return true;
                $this->appendTelegramTemplateAlbum($actor,$known['template_id'],$message,$u);return true;
            }
            if(!in_array($state,['await_template_content','await_template_edit_media'],true))return false;
            $this->telegramTemplateIngress=$u;
            if($state==='await_template_content')$this->createTemplateFromMessage($actor,(int)($data['scope_id']??0),$message,$data);
            else $this->updateTemplateDraft($actor,(int)($data['template_id']??0),$state,$message);
            return true;
        }finally{$this->telegramTemplateIngress=null;$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    }

    private function appendTelegramTemplateAlbum(int $actor,int $id,array $message,array $expected):void
    {
        $v=PostedTelegramTemplateMedia::class;$item=PostedTelegramAlbumCollector::item($message,$this->contentFromMessage($message));
        $ok=$this->withTelegramTemplateLock($actor,$id,function(array$template)use($actor,$id,$message,$expected,$item,$v):bool{
            if(!$this->telegramTemplateInputMatches($actor,$expected))return false;$r=$v::collection($v::read($this->pdo,$v::collectionKey($id),true)??[]);
            if($r['actor_id']!==$actor||$r['group_id']!==$message['media_group_id']||$r['input']['state']!==($expected['state']??null)
                ||$v::json($this->decodeJson((string)$r['input']['state_data']))!==$v::json($this->decodeJson((string)($expected['state_data']??''))))return false;
            $next=$v::append($r,$item,$v::now());if($next===$r)return true;$a=$v::assembled($next,$template);$options=PostedTelegramPostOptions::loadTemplate($this->pdo,$template);
            if(($options['paid_media_stars']!==null&&(array_diff(array_column($a['media'],'type'),['photo','video'])!==[]||$a['spoiler']))||($options['media_spoiler']&&array_diff(array_column($a['media'],'type'),['photo','video','animation'])!==[]))throw new RuntimeException('Медиа несовместимы с сохранёнными настройками.');
            PostedTelegramSourceSpoiler::detachLocked($this->pdo,$template,'template');$v::saveLocked($this->pdo,$template,$a['media']);
            $this->pdo->prepare('UPDATE posted_templates SET text=?,text_entities_json=?,title=? WHERE id=? AND owner_id=?')->execute([$a['text']!==''?$a['text']:null,$a['entities']!==[]&&$a['entities']!==null?$this->encodeJson($a['entities']):null,$this->templateTitle($a['text'],$a['media'][0]['type']),$id,$actor]);
            PostedTelegramCaptionVector::captureLocked($this->pdo,array_replace($template,['media_json'=>$a['media']]),$next['items'],'t');
            if($a['spoiler'])PostedTelegramPostOptions::saveTemplateLocked($this->pdo,$template,['media_spoiler'=>true]);$v::write($this->pdo,$v::collectionKey($id),$next);return true;
        });if($ok===true)$this->telegramTemplateDeferred[$id]=true;
    }

    public function hasTelegramTemplatePreviewWork():bool{return$this->telegramTemplateDeferred!==[];}
    public function flushTelegramTemplatePreviews(bool $tail=false,int $limit=5):array
    {
        if($this->pdo->inTransaction())throw new LogicException('Template preview flush starts outside SQL.');$v=PostedTelegramTemplateMedia::class;$limit=max(1,min(10,$limit));$ids=array_keys($this->telegramTemplateDeferred);$this->telegramTemplateDeferred=[];
        if(!$tail){$s=$this->pdo->query("SELECT t.id FROM posted_templates t JOIN posted_users u ON u.telegram_id=t.owner_id AND u.is_active=1 JOIN posted_app_settings s ON s.setting_key=CONCAT('tg_template_collect_',t.id) WHERE JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.phase'))='sending' OR (JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.phase'))='collecting' AND JSON_LENGTH(JSON_EXTRACT(s.setting_value,'$.items'))>=2 AND u.state='collecting_template_media' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(u.state_data,'$.template_id')) AS UNSIGNED)=t.id) ORDER BY s.updated_at,t.id LIMIT ".$limit);$ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));}
        $shown=0;$unknown=0;foreach(array_slice(array_unique($ids),0,$limit)as$id){$lock='tg_template_flush_'.$id;$s=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);if((int)$s->fetchColumn()!==1)continue;
            try{$deadline=$v::now()+($tail?$v::TAIL_MS:0);do{$raw=$v::read($this->pdo,$v::collectionKey($id));if($raw===null)break;$r=$v::collection($raw);
                if($r['phase']==='sending'){$previewLock='tg_template_preview_'.$id;$p=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$p->execute([$previewLock]);if((int)$p->fetchColumn()===1){try{$this->markTelegramTemplateUnknown($id);$unknown++;}finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$previewLock]);}}break;}
                if($v::due($r,$v::now())){$ok=$this->renderTelegramTemplateMedia($r['actor_id'],$id,'editor',true);$ok?$shown++:$unknown++;break;}
                if(!$tail||$r['phase']!=='collecting'||count($r['items'])<2||$v::now()>=$deadline)break;usleep(100000);
            }while(true);}catch(Throwable$error){error_log('GitPosted template preview: '.$error::class);}finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}}
        return['shown'=>$shown,'unconfirmed'=>$unknown];
    }
    private function markTelegramTemplateUnknown(int $id):void
    {
        $v=PostedTelegramTemplateMedia::class;$this->pdo->beginTransaction();try{$r=$v::read($this->pdo,$v::previewKey($id),true);if($r!==null){$r=$v::preview($r,$id);if(in_array($r['phase'],['sending','media_accepted'],true)){$r['phase']='unknown';$v::write($this->pdo,$v::previewKey($id),$r);}}$c=$v::read($this->pdo,$v::collectionKey($id),true);if($c!==null){$c=$v::collection($c);if($c['phase']==='sending'){$c['phase']='unknown';$v::write($this->pdo,$v::collectionKey($id),$c);}}$this->pdo->commit();}catch(Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function telegramTemplateKeyboard(array $template,string $mode):array
    {
        if(PostedTelegramNativeTemplate::isNative($template))return $this->telegramNativeTemplateKeyboard($template,$mode);
        $id=(int)$template['id'];$v=PostedTelegramTemplateMedia::load($this->pdo,$template);$revision=$v['revision'];$cb=static fn(string$action):string=>'tmedia:'.$action.':'.$id.':'.$revision;
        if($mode==='view')return[[['text'=>'Опубликовать','callback_data'=>$cb('use')],['text'=>'Изменить','callback_data'=>$cb('editor')]],[['text'=>'Удалить','callback_data'=>$cb('delete')]],[['text'=>'← Назад','callback_data'=>'tpl_scope:'.(int)($template['channel_id']??0)]]];
        $items=PostedTelegramTemplateMedia::legacy($template);$rows=[[['text'=>$items!==[]?'Изменить описание':'Изменить текст','callback_data'=>$cb('text')],['text'=>$items!==[]?'Удалить медиа':'Добавить медиа','callback_data'=>$cb($items!==[]?'remove':'media')]]];
        $options=PostedTelegramPostOptions::loadTemplate($this->pdo,$template);if($items!==[]&&array_diff(array_column($items,'type'),['photo','video','animation'])===[]&&$options['paid_media_stars']===null)$rows[]=[['text'=>($options['media_spoiler']?'✅️ ':'☑️ ').'Спойлер','callback_data'=>$cb('spoiler')]];
        if(count($items)<2)$rows[]=[['text'=>'URL-кнопки','callback_data'=>$cb('buttons')]];
        $rows[]=[['text'=>'← Отменить','callback_data'=>$cb('cancel')],['text'=>'Готово →','callback_data'=>$cb('done')]];return$rows;
    }
    private function telegramTemplatePost(array $template,int $actor):array
    {
        if(PostedTelegramNativeTemplate::isNative($template)){
            $native=PostedTelegramNativeStore::load($this->pdo,$template,'t');$options=PostedTelegramPostOptions::loadTemplate($this->pdo,$template);unset($options['template_id']);$options['post_id']=(int)$template['id'];$options['channel_id']=(int)($template['channel_id']??0)?:1;
            $post=array_replace($template,['channel_id'=>$options['channel_id'],'author_id'=>$actor,'chat_id'=>(string)$actor,'status'=>'draft','repost_chat_id'=>null,'repost_message_id'=>null,'autosignature_text'=>null,'autosignature_entities_json'=>null,'_telegram_post_options'=>$options,'_telegram_caption_vector'=>null,'_telegram_native_content'=>$native['descriptor'],'_telegram_native_legacy_recorded'=>false]);
            return PostedTelegramCaptionPlacement::templatePost($this->pdo,$template,$post);
        }
        $options=PostedTelegramPostOptions::loadTemplate($this->pdo,$template);unset($options['template_id']);$options['post_id']=(int)$template['id'];$options['channel_id']=(int)($template['channel_id']??0)?:1;
        $post=array_replace($template,['channel_id'=>$options['channel_id'],'author_id'=>$actor,'chat_id'=>(string)$actor,'status'=>'draft','repost_chat_id'=>null,'repost_message_id'=>null,'autosignature_text'=>null,'autosignature_entities_json'=>null,'_telegram_post_options'=>$options,'_telegram_caption_vector'=>PostedTelegramCaptionVector::rebind($template['_telegram_template_captions']??PostedTelegramCaptionVector::load($this->pdo,$template,'t'),array_replace($template,['channel_id'=>$options['channel_id']]))]);
        return PostedTelegramCaptionPlacement::templatePost($this->pdo,$template,$post);
    }
    private function renderTelegramTemplateMedia(int $actor,int $id,string $mode='editor',bool $collection=false):bool
    {
        $input='tg_template_input_'.substr(hash('sha256',(string)$actor),0,32);$s=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$input]);if((int)$s->fetchColumn()!==1)return false;
        try{$lock='tg_template_preview_'.$id;$s=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);if((int)$s->fetchColumn()!==1)return false;
            try{return$this->renderTelegramTemplateMediaUnlocked($actor,$id,$mode,$collection);}finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
        }finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$input]);}
    }
    private function renderTelegramTemplateMediaUnlocked(int $actor,int $id,string $mode='editor',bool $collection=false):bool
    {
        $nativeTemplate=$this->ownedTemplate($actor,$id);if(is_array($nativeTemplate)&&PostedTelegramNativeTemplate::isNative($nativeTemplate))return$this->renderTelegramNativeTemplateUnlocked($actor,$id,$mode);
        $v=PostedTelegramTemplateMedia::class;$claim=$this->withTelegramTemplateLock($actor,$id,function(array$template)use($actor,$id,$mode,$collection,$v):?array{
            $r=$v::read($this->pdo,$v::collectionKey($id),true);if($r!==null){$r=$v::collection($r);if($collection){if(!$v::due($r,$v::now())||$r['actor_id']!==$actor||!$this->telegramTemplateInputMatches($actor,$r['input']))return null;}else $v::assertReady($this->pdo,$template);}
            $post=$this->telegramTemplatePost($template,$actor);$plan=$this->telegramPreviewPlan($post);$fp=$v::fingerprint($template,PostedTelegramPostOptions::loadTemplate($this->pdo,$template));$old=$v::read($this->pdo,$v::previewKey($id),true);
            if($old!==null){$old=$this->normalizeTelegramTemplatePreview($old,$id);if(in_array($old['phase'],['sending','media_accepted','unknown'],true))throw new RuntimeException('Результат предпросмотра шаблона не подтверждён. Повторная отправка не выполнялась.');
                if($old['phase']==='shown'&&$old['actor_id']===$actor&&$old['fingerprint']===$fp&&$old['mode']===$mode)return['reuse'=>true,'template'=>$template,'old'=>$old];}
            $previous=$old===null?null:array_intersect_key($old,array_flip(['actor_id','fingerprint','mode','media_ids','control_id']));
            $receipt=['version'=>1,'template_id'=>$id,'actor_id'=>$actor,'fingerprint'=>$fp,'mode'=>$mode,'nonce'=>bin2hex(random_bytes(16)),'phase'=>'sending','expected'=>PostedTelegramPreviewProof::expectedCount($plan,false),'media_ids'=>[],'control_id'=>null,'previous'=>$previous];
            $v::write($this->pdo,$v::previewKey($id),$receipt);if($r!==null){$r['phase']='sending';$v::write($this->pdo,$v::collectionKey($id),$r);}
            return['reuse'=>false,'template'=>$template,'post'=>$post,'plan'=>$plan,'receipt'=>$receipt,'old'=>$old];
        });if(!is_array($claim))return false;
        $keyboard=$this->telegramTemplateKeyboard($claim['template'],$mode);
        if($claim['reuse']){$this->telegram->call('editMessageReplyMarkup',['chat_id'=>(string)$actor,'message_id'=>$claim['old']['control_id'],'reply_markup'=>['inline_keyboard'=>$keyboard]]);return true;}
        $receipt=$claim['receipt'];$ids=[];try{
            $ids=$this->dispatchTelegramTemplatePreview($actor,$id,$receipt['nonce'],$claim['post'],$claim['plan']);
            PostedTelegramPreviewProof::requireKnownIds($ids,$receipt['expected']);
            $text=$mode==='view'?'Выберите, что сделать с шаблоном.':'Настройте пост перед тем, как добавить его в шаблоны.';
            $result=$this->telegram->sendMessage($actor,$text,['reply_markup'=>['inline_keyboard'=>$keyboard]]);$controlProjection=$this->telegramTemplateResponseProjection($result,$actor);$controlIds=$controlProjection['ids'];
            if(count($controlIds)===1){$control=$controlIds[0];$this->updateTelegramTemplatePreviewReceipt($id,$receipt['nonce'],function(array$r)use($control):array{$r['control_id']=$control;return$r;});}
            elseif($controlIds!==[])$this->updateTelegramTemplatePreviewReceipt($id,$receipt['nonce'],function(array$r)use($controlIds):array{$r['media_ids']=array_values(array_unique(array_merge($r['media_ids'],$controlIds)));return$r;});
            if(!$controlProjection['valid'])throw new RuntimeException('Template control response is malformed.');PostedTelegramPreviewProof::requireKnownIds($controlIds,1);$control=$controlIds[0];
            if(in_array($control,$ids,true))throw new RuntimeException('Template control reused a media ID.');
            $this->updateTelegramTemplatePreviewReceipt($id,$receipt['nonce'],function(array$r)use($control):array{$r['control_id']=$control;$r['phase']='shown';return$r;},true);
            $old=$claim['old'];if(is_array($old)&&$old['actor_id']===$actor){$obsolete=array_values(array_diff(array_merge($old['media_ids'],[$old['control_id']]),array_merge($ids,[$control])));foreach($obsolete as$mid)if(is_int($mid)&&$mid>0){try{$this->telegram->call('deleteMessage',['chat_id'=>(string)$actor,'message_id'=>$mid]);}catch(Throwable){}}}
            return true;
        }catch(Throwable$error){$this->markTelegramTemplateUnknown($id);$this->telegram->sendMessage($actor,PostedTelegramNativeTemplate::isNative($claim['template'])?'Предпросмотр шаблона не подтверждён. Принятое содержимое не изменено. Повторная отправка не выполнялась.':'Предпросмотр шаблона не подтверждён. Сохранённые файлы не изменены. Повторная отправка не выполнялась.');return false;}
    }
    private function normalizeTelegramTemplatePreview(array$r,int$id):array
    {
        return PostedTelegramTemplateMedia::preview($r,$id);
    }
    private function telegramTemplateResponseProjection(array$result,int$actor):array
    {
        if(($result['ok']??null)!==true||!is_array($result['result']??null))return['ids'=>[],'valid'=>false];$raw=$result['result'];$messages=array_is_list($raw)?$raw:[$raw];$valid=count($messages)<=110;$ids=[];$seen=[];
        foreach(array_slice($messages,0,110)as$message){if(!is_array($message)||!is_int($message['message_id']??null)||$message['message_id']<1||(string)($message['chat']['id']??'')!==(string)$actor){$valid=false;continue;}$mid=$message['message_id'];if(isset($seen[$mid])){$valid=false;continue;}$seen[$mid]=true;$ids[]=$mid;}return['ids'=>$ids,'valid'=>$valid];
    }
    private function dispatchTelegramTemplatePreview(int$actor,int$id,string$nonce,array$post,array$plan):array
    {
        $requests=[];$mode=$plan['mode'];$media=$plan['media'];$parts=$plan['text_parts'];
        if($mode==='native'){
            $descriptor=PostedTelegramNativeContent::normalize($plan['native_descriptor']);$request=PostedTelegramNativeContent::request($descriptor);if($request!==$plan['native_request'])throw new RuntimeException('native_template_plan_changed');
            $notice=$plan['native_notice']??'';if(!is_string($notice))throw new RuntimeException('native_template_notice');if($notice!=='')$requests[]=['count'=>1,'send'=>fn():array=>$this->telegram->sendMessage($actor,$notice)];
            $requests[]=['count'=>1,'native_descriptor'=>$descriptor,'send'=>fn():array=>$this->telegram->call($request['method'],array_merge($request['params'],['chat_id'=>(string)$actor]))];
        }elseif($mode==='group')$requests[]=['count'=>count($media),'send'=>fn():array=>$this->telegram->sendMediaGroup($actor,$media)];
        elseif($mode==='paid'){
            $caption=$parts===[]?(string)($media[0]['caption']??''):'';$entities=$parts===[]?(array)($media[0]['caption_entities']??[]):[];$paid=$this->telegramPaidMediaPayload($post,$actor,$caption,$entities);unset($paid['protect_content']);$requests[]=['count'=>1,'send'=>fn():array=>$this->telegram->call('sendPaidMedia',$paid)];
        }elseif($mode==='single'||$mode==='sequence')foreach($media as$item){$extra=[];if(($item['show_caption_above_media']??null)===true)$extra['show_caption_above_media']=true;if(!empty($item['has_spoiler']))$extra['has_spoiler']=true;if(!empty($item['caption_entities']))$extra['caption_entities']=$item['caption_entities'];$requests[]=['count'=>1,'send'=>fn():array=>$this->sendMedia($actor,$item['type'],$item['media'],(string)($item['caption']??''),$extra)];}
        foreach($parts as$part){$extra=$this->linkPreviewExtra($post);if(!empty($part['entities']))$extra['entities']=$part['entities'];$requests[]=['count'=>1,'send'=>fn():array=>$this->telegram->sendMessage($actor,(string)$part['text'],$extra)];}
        $ids=[];foreach($requests as$request){$result=($request['send'])();$projection=$this->telegramTemplateResponseProjection($result,$actor);$received=$projection['ids'];$ids=array_values(array_unique(array_merge($ids,$received)));
            // Each accepted part is durable before another API call or any later derivation.
            $this->updateTelegramTemplatePreviewReceipt($id,$nonce,function(array$r)use($ids):array{$r['media_ids']=$ids;$r['phase']='media_accepted';return$r;});if(isset($request['native_descriptor'])){$ack=PostedTelegramNativeContent::acknowledgement($request['native_descriptor'],$result,(string)$actor);if(!$ack['known'])throw new RuntimeException('Native template media response is not confirmed.');}if(!$projection['valid'])throw new RuntimeException('Template media response is malformed.');PostedTelegramPreviewProof::requireKnownIds($received,$request['count']);
        }return$ids;
    }
    private function updateTelegramTemplatePreviewReceipt(int$id,string$nonce,callable$change,bool$shown=false):void
    {
        $v=PostedTelegramTemplateMedia::class;$this->pdo->beginTransaction();try{$r=$this->normalizeTelegramTemplatePreview($v::read($this->pdo,$v::previewKey($id),true)??[],$id);if($r['nonce']!==$nonce||!in_array($r['phase'],['sending','media_accepted'],true))throw new RuntimeException('Template preview claim changed.');$r=$this->normalizeTelegramTemplatePreview($change($r),$id);$v::write($this->pdo,$v::previewKey($id),$r);
            if($shown){$c=$v::read($this->pdo,$v::collectionKey($id),true);if($c!==null){$c=$v::collection($c);if($c['phase']!=='sending')throw new RuntimeException('Template collection claim changed.');if(array_intersect(array_merge($r['media_ids'],[$r['control_id']]),array_column($c['items'],'message_id'))!==[])throw new RuntimeException('Template preview reused incoming IDs.');$c['phase']='shown';$v::write($this->pdo,$v::collectionKey($id),$c);}}$this->pdo->commit();
        }catch(Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function handleTelegramTemplateMediaCallback(array$callback):bool
    {
        $raw=$callback['data']??null;if(!is_string($raw))return false;
        $legacy=preg_match('/\Atpl_(?:edit_text|edit_media|edit_buttons|done|cancel|editor):([1-9][0-9]{0,18})\z/D',$raw,$legacyMatch)===1;
        if(!str_starts_with($raw,'tmedia:')&&!$legacy)return false;$actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if(!is_int($actor)||$actor<1||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor)return true;
        if($legacy){$legacyTemplate=$this->ownedTemplate($actor,(int)$legacyMatch[1]);if(!$legacyTemplate||!$this->telegramTemplateUsesVectorUi($legacyTemplate))return false;$this->answerCallback($callback,'Меню устарело. Откройте шаблон заново.');return true;}
        if(preg_match('/\Atmedia:(view|editor|use|delete|text|media|remove|spoiler|buttons|done|cancel):([1-9][0-9]{0,18}):([0-9]{1,10})\z/D',$raw,$m)!==1||(string)(int)$m[2]!==$m[2]||(string)(int)$m[3]!==$m[3]||(int)$m[3]>2147483647||!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo)){$this->answerCallback($callback,'Меню устарело.');return true;}
        $id=(int)$m[2];$revision=(int)$m[3];$action=$m[1];$v=PostedTelegramTemplateMedia::class;$template=$this->ownedTemplate($actor,$id);if(!$template){$this->answerCallback($callback,'Шаблон не найден.');return true;}
        try{
            $valid=$this->withTelegramTemplateLock($actor,$id,function(array$t)use($revision,$action,$v,$actor):bool{if(!in_array($action,['delete','cancel'],true)){$v::assertReady($this->pdo,$t);if(PostedTelegramNativeTemplate::isNative($t)&&!in_array($action,['view','editor'],true))PostedTelegramNativeTemplatePreview::assertActorReady($this->pdo,$t,$actor,true);}return$v::load($this->pdo,$t,true)['revision']===$revision;});if($valid!==true){$this->answerCallback($callback,'Меню устарело.');return true;}
            if(PostedTelegramNativeTemplate::isNative($template)&&in_array($action,['text','media','spoiler'],true)){$this->answerCallback($callback,'Этот вид содержимого не поддерживает выбранное изменение.');return true;}
            $this->answerCallback($callback);
            if($action==='view'||$action==='editor'){$this->renderTelegramTemplateMedia($actor,$id,$action);return true;}
            if($action==='use'){$this->useTemplate($actor,$id);return true;}
            if($action==='delete'){$this->deleteTelegramTemplateMedia($actor,$id,false,$revision);return true;}
            if($action==='done'){$this->finishTelegramTemplateMedia($actor,$id,$revision);return true;}
            if($action==='cancel'){$this->deleteTelegramTemplateMedia($actor,$id,true,$revision);return true;}
            if($action==='text'||$action==='media'||$action==='buttons'){
                if($action==='buttons'&&count($v::legacy($template))>=2){$this->telegram->sendMessage($actor,'Кнопки недоступны для альбомов.');return true;}
                $state='await_template_edit_'.($action==='text'?'text':($action==='media'?'media':'buttons'));$prompt=match($action){'text'=>'Отправьте боту новое описание.','media'=>'Отправьте боту новое медиа с подписью или без неё.',default=>$this->buttonsHelpText()};
                $this->setState($actor,$state,['template_id'=>$id,'input_token'=>bin2hex(random_bytes(12)),'template_revision'=>$revision]);$this->telegram->sendMessage($actor,$prompt,['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'tmedia:editor:'.$id.':'.$revision]]]]]);return true;
            }
            $ok=$this->withTelegramTemplateLock($actor,$id,function(array$t)use($actor,$id,$action,$revision,$v):bool{
                $v::assertReady($this->pdo,$t);if($v::load($this->pdo,$t,true)['revision']!==$revision)return false;
                if($action==='remove'){PostedTelegramCaptionPlacement::captureLocked($this->pdo,$t,false,'t');PostedTelegramSourceSpoiler::detachLocked($this->pdo,$t,'template');$v::saveLocked($this->pdo,$t,[]);PostedTelegramPostOptions::saveTemplateLocked($this->pdo,array_replace($t,['media_type'=>'none','media_file_id'=>null]),['media_spoiler'=>false,'paid_media_stars'=>null]);}
                else{$options=PostedTelegramPostOptions::loadTemplate($this->pdo,$t);$items=$v::legacy($t);if($items===[]||array_diff(array_column($items,'type'),['photo','video','animation'])!==[]||$options['paid_media_stars']!==null)return false;PostedTelegramPostOptions::saveTemplateLocked($this->pdo,$t,['media_spoiler'=>!$options['media_spoiler']]);$v::saveLocked($this->pdo,$t,$items);}
                return true;
            });if($ok===true)$this->renderTelegramTemplateMedia($actor,$id);else$this->telegram->sendMessage($actor,'Меню устарело.');
        }catch(Throwable$error){error_log('GitPosted template callback: '.$error::class);$this->telegram->sendMessage($actor,'Шаблон не изменён. Альбом ещё загружается или требует проверки. Повторная отправка не выполнялась.');}return true;
    }
}
