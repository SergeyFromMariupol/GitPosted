<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Escaped presentation of the backend's non-secret settings DTO; no reads or writes. */
final class PostedAdminSettingsView
{
    private const CATEGORIES=['telegram'=>'Telegram','max'=>'МАКС','payments'=>'Подписки и тарифы','common'=>'Общие'];
    private const SECRETS=['bot_token','webhook_secret','max_bot_token','max_webhook_secret','yookassa_secret_key'];
    private static function e(string$v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function field(array$v,string$n):string
    {if(!array_key_exists($n,$v['fields'])||!is_string($v['fields'][$n]))throw new InvalidArgumentException('settings_view_field');return$v['fields'][$n];}
    private static function error(array$v,string$n):string
    {$e=$v['errors'][$n]??'';if(!is_string($e))throw new InvalidArgumentException('settings_view_error');return$e;}
    private static function input(array$v,string$n,string$label,string$type='text',string$hint='',array$attrs=[]):string
    {
        $secret=in_array($n,self::SECRETS,true);$value=$secret?'':self::field($v,$n);$id='setting-'.$n;$error=self::error($v,$n);$extra='';
        foreach($attrs as$k=>$valueAttr){if(!in_array($k,['maxlength','min','max','step','inputmode','pattern','readonly','required'],true)||!is_string($valueAttr))throw new InvalidArgumentException('settings_view_attribute');$extra.=' '.self::e($k).'="'.self::e($valueAttr).'"';}
        if($secret){$configured=$v['secret_configured'][$n]??null;if(!is_bool($configured))throw new InvalidArgumentException('settings_view_secret');$hint=$n==='yookassa_secret_key'?($configured?'Ключ сохранён. Пустое поле — оставить действующий.':'Ключ не задан.'):($configured?'Задан. Пустое поле — оставить действующий.':'Пока не задан.');$extra.=' autocomplete="new-password"';}
        $out='<label class="settings-field" for="'.$id.'"><span>'.self::e($label).'</span><input data-settings-value id="'.$id.'" name="'.self::e($n).'" type="'.self::e($type).'" value="'.self::e($value).'"'.$extra.($error!==''?' aria-invalid="true"':'').(($hint!==''||$error!=='')?' aria-describedby="'.$id.'-hint"':'').'>';
        if($hint!==''||$error!=='')$out.='<small id="'.$id.'-hint"'.($error!==''?' class="settings-field__error"':'').'>'.self::e($error!==''?$error:$hint).'</small>';
        return$out.'</label>';
    }
    private static function select(array$v,string$n,string$label,array$options,string$hint=''):string
    {
        $value=self::field($v,$n);$error=self::error($v,$n);$id='setting-'.$n;$out='<label class="settings-field" for="'.$id.'"><span>'.self::e($label).'</span><select data-settings-value id="'.$id.'" name="'.self::e($n).'"'.($error!==''?' aria-invalid="true"':'').(($hint!==''||$error!=='')?' aria-describedby="'.$id.'-hint"':'').'>';
        foreach($options as$k=>$labelOption)$out.='<option value="'.self::e((string)$k).'"'.((string)$k===$value?' selected':'').'>'.self::e($labelOption).'</option>';
        $out.='</select>';if($hint!==''||$error!=='')$out.='<small id="'.$id.'-hint"'.($error!==''?' class="settings-field__error"':'').'>'.self::e($error!==''?$error:$hint).'</small>';return$out.'</label>';
    }
    private static function ids(array$v,string$n,string$label,string$hint='По одному ID в строке. Используется только для закрытого доступа.'):string
    {$id='setting-'.$n;$error=self::error($v,$n);return'<label class="settings-field settings-field--wide" for="'.$id.'"><span>'.self::e($label).'</span><textarea data-settings-value id="'.$id.'" name="'.self::e($n).'" rows="3" maxlength="24000"'.($error!==''?' aria-invalid="true"':'').'>'.self::e(self::field($v,$n)).'</textarea>'.($error!==''?'<small class="settings-field__error">'.self::e($error).'</small>':'<small>'.self::e($hint).'</small>').'</label>';}
    private static function card(string$title,string$content,string$description=''):string
    {return'<section class="settings-card"><header class="settings-card__head"><h3>'.self::e($title).'</h3>'.($description!==''?'<p>'.self::e($description).'</p>':'').'</header><div class="settings-grid">'.$content.'</div></section>';}
    public static function render(array$v,string$csrf,string$controlUrl='/control/'):string
    {
        if(($v['version']??null)!==1||!is_array($v['fields']??null)||!is_array($v['secret_configured']??null)||!is_array($v['errors']??null)||!is_array($v['notices']??null)||!is_string($v['category']??null)||!isset(self::CATEGORIES[$v['category']])||!is_string($v['config_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$v['config_hash'])!==1||!is_string($v['nonce']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$v['nonce'])!==1||$csrf==='')throw new InvalidArgumentException('settings_view_dto');
        if(preg_match('~\A/(?:[A-Za-z0-9_-]+/)*control/\z~D',$controlUrl)!==1)throw new InvalidArgumentException('settings_view_control_path');
        foreach(self::SECRETS as$n)if(array_key_exists($n,$v['fields']))throw new InvalidArgumentException('settings_view_secret_in_fields');
        if(array_key_exists('pending_sync',$v)&&!is_bool($v['pending_sync']))throw new InvalidArgumentException('settings_view_sync');$pendingSync=$v['pending_sync']??false;
        $pending=$v['errors']!==[];$out='<section class="settings-workspace" data-admin-settings data-settings-pending="'.($pending?'1':'0').'" data-settings-sync-pending="'.($pendingSync?'1':'0').'"><header class="settings-workspace__head"><div><h2>Настройки</h2><p>Все изменения применяются одной кнопкой.</p></div></header>';
        foreach($v['notices']as$notice){if(!is_string($notice))throw new InvalidArgumentException('settings_view_notice');$out.='<p class="settings-notice" role="status">'.self::e($notice).'</p>';}
        $out.='<form id="admin-settings-form" method="post" autocomplete="off"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><input type="hidden" name="action" value="admin_settings_save"><input type="hidden" name="settings_version" value="1"><input type="hidden" name="config_hash" value="'.$v['config_hash'].'"><input type="hidden" name="settings_nonce" value="'.$v['nonce'].'"><input type="hidden" name="settings_category" value="'.$v['category'].'">';
        foreach(['telegram','max']as$platform){
            $tg=$platform==='telegram';$name=$tg?'Telegram':'МАКС';$prefix=$tg?'':'max_';$out.='<div id="settings-panel-'.$platform.'" class="settings-category" data-settings-panel="'.$platform.'" aria-label="'.self::e($name).'">';
            $advanced='<details class="settings-advanced settings-field--wide"><summary>Дополнительные настройки подключения</summary><div class="settings-grid">'.self::input($v,$prefix.'webhook_secret','Новый секрет webhook','password').self::select($v,$platform.'_takeover_webhook','Сторонний webhook',['0'=>'Не заменять','1'=>'Разрешить замену']).'</div></details>';
            $out.=self::card($name,self::select($v,$platform.'_bot_enabled','Состояние бота',['0'=>'Выключен','1'=>'Включён']).self::input($v,$prefix.'bot_username','Имя бота','text','',['maxlength'=>'32']).self::input($v,$prefix.'bot_token','Новый токен','password').$advanced);
            $out.=self::card('Доступ',self::select($v,$platform.'_pricing_mode','Режим работы',['free'=>'Бесплатно','paid'=>'Подписка за каждый канал']).self::select($v,$platform.'_access_mode','Кто может пользоваться',$tg?['open'=>'Все пользователи','allowlist'=>'Только из списка','rooted'=>'По каналам доверенных аккаунтов']:['open'=>'Все пользователи','allowlist'=>'Только из списка']).self::ids($v,$platform.'_allowed_ids',$tg&&self::field($v,'telegram_access_mode')==='rooted'?'Корневые Telegram ID':($tg?'Разрешённые Telegram ID':'Разрешённые ID МАКС'),$tg&&self::field($v,'telegram_access_mode')==='rooted'?'От этих аккаунтов строится доступ сотрудников. По одному ID в строке.':'По одному ID в строке. Используется только для закрытого доступа.'));
            if($tg)$out.=self::card('Настройки поста',self::select($v,'telegram_web_window_enabled','Веб-окно',['0'=>'Выключено','1'=>'Доступно пользователям']));
            else $out.='<input data-settings-value type="hidden" name="max_api_base_url" value="'.self::e(self::field($v,'max_api_base_url')).'">';
            $out.='<p class="settings-actions-link"><a class="button button--quiet" data-settings-tools="'.$platform.'" href="'.self::e($controlUrl.'?page='.$platform).'#utilities-'.$platform.'">Лимиты и действия '.self::e($name).'</a></p></div>';
        }
        $out.='<div id="settings-panel-payments" class="settings-category" data-settings-panel="payments" aria-label="Подписки и тарифы">';$prices='';foreach([30,90,180,365]as$days)$prices.=self::input($v,'price_'.$days,$days.' дней, ₽','number','',['min'=>'1','max'=>'1000000','step'=>'1','required'=>'required']);$out.=self::card('Тарифы',$prices,'Цена за один канал. Новые тарифы применяются к новым платежам.');
        $out.=self::card('ЮKassa',self::select($v,'yookassa_enabled','Приём платежей',['0'=>'Выключен','1'=>'Включён']).self::input($v,'yookassa_shop_id','ID магазина (shopId)','text','',['inputmode'=>'numeric','pattern'=>'[1-9][0-9]{0,18}','maxlength'=>'19']).self::input($v,'yookassa_secret_key','Секретный ключ ЮKassa','password'));
        if(!is_string($v['notification_url']??null)||!is_array($v['notification_events']??null))throw new InvalidArgumentException('settings_view_notifications');$events='';foreach($v['notification_events']as$event){if(!is_string($event))throw new InvalidArgumentException('settings_view_event');$events.='<li><code>'.self::e($event).'</code></li>';}
        $out.='<section class="settings-card"><header class="settings-card__head"><h3>Уведомления ЮKassa</h3><p>В кабинете ЮKassa: Интеграция → HTTP-уведомления.</p></header><p class="settings-endpoint"><code>'.self::e($v['notification_url']).'</code></p><ul class="settings-events">'.$events.'</ul></section><p class="settings-actions-link"><a class="button button--quiet" data-settings-tools="payments" href="'.self::e($controlUrl.'?page=subscriptions').'#channel-subscriptions">Управлять подписками каналов</a></p></div>';
        $out.='<div id="settings-panel-common" class="settings-category" data-settings-panel="common" aria-label="Общие">';$out.=self::card('Общие',self::input($v,'service_admin_username','Поддержка','text','',['maxlength'=>'33']).self::input($v,'service_news_username','Канал новостей','text','',['maxlength'=>'33']).self::input($v,'telegram_top_hold_minutes','Длительность ТОП, минут','number','Применяется к новым ТОП-публикациям.',['min'=>'1','max'=>'525600','step'=>'1','required'=>'required']).self::input($v,'timezone','Часовой пояс хранения','text','Время показа меняется в настройках бота.',['readonly'=>'readonly']));$out.='<p class="settings-actions-link"><a class="button button--quiet" data-settings-tools="common" href="'.self::e($controlUrl.'?page=system').'#system-tools">Ключи доступа и система</a></p></div>';
        $out.='<footer class="settings-savebar"><p data-settings-status role="status" aria-live="polite">'.($pending?'Изменения не сохранены':($pendingSync?'Подключение ожидает синхронизации':'Нет несохранённых изменений')).'</p><button id="admin-settings-save" class="button" type="submit">Сохранить изменения</button></footer></form></section>';return$out;
    }
}
