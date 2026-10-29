<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
/** Private admin-only DTO renderer. No database or policy mutation. */
final class PostedTelegramRootedAccessView
{
    public static function render(array $view,string $csrf,string $controlUrl='/control/'):string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $out='<section class="card"><h2>Собственные каналы сотрудников</h2><p>В режиме доступа по доверенным аккаунтам сотрудник работает только с назначенными каналами. Подключение своих каналов разрешается отдельно; их сотрудники получают доступ по той же цепочке.</p>';
        $out.='<form method="get" action="'.$e($controlUrl).'"><input type="hidden" name="page" value="telegram-data"><label>Telegram ID сотрудника <input name="rooted_user_id" inputmode="numeric" pattern="[1-9][0-9]*" required></label><button type="submit">Открыть доступ</button></form>';
        if(($view['error']??'')!=='')$out.='<p role="alert">'.$e($view['error']).'</p>';
        if(is_array($view['selected']??null)){
            $selected=$view['selected'];$user=$selected['user'];$control=$selected['control'];
            $out.='<form method="post" action="'.$e($controlUrl).'"><input type="hidden" name="csrf" value="'.$e($csrf).'"><input type="hidden" name="action" value="telegram_rooted_access"><input type="hidden" name="rooted_user_id" value="'.$e($user['telegram_id']).'"><input type="hidden" name="rooted_revision" value="'.$e($control['revision']).'"><input type="hidden" name="rooted_can_add" value="0">';
            $out.='<p>'.$e($user['first_name']??'').' · '.$e($user['telegram_id']).'</p><label><input type="checkbox" name="rooted_can_add" value="1"'.($control['can_add_channels']?' checked':'').'> Разрешить подключать собственные каналы</label><p>Разрешение не открывает чужие каналы и не заменяет канальные права. После отзыва цепочка через собственные каналы этого сотрудника перестаёт давать доступ.</p><button type="submit">Сохранить доступ</button></form>';
        }
        return$out.'</section>';
    }
}
