<?php

declare(strict_types=1);

function adminStatus(bool $enabled, string $enabledText = 'Включено', string $disabledText = 'Выключено'): string
{
    return '<span class="status ' . ($enabled ? 'status--ok' : 'status--off') . '">'
        . '<span class="status__dot"></span>'
        . e($enabled ? $enabledText : $disabledText)
        . '</span>';
}

function adminPlatformIcon(string $surface): string
{
    global $config;
    return match ($surface) {
        'telegram' => '<img src="' . e(gitpostedAppPath($config, '/assets/admin/platforms/telegram.webp')) . '" alt="">',
        'max' => '<img src="' . e(gitpostedAppPath($config, '/assets/admin/platforms/max.webp')) . '" alt="">',
        default => '<i class="fa-solid fa-globe" aria-hidden="true"></i>',
    };
}

function adminMaxChannelBotSummary(array $channel): array
{
    $labels=['Публикует', 'Редактирует', 'Закрепляет', 'Удаляет'];
    $emptyCapabilities=array_fill_keys($labels, null);
    $botStatus=trim((string)($channel['bot_status'] ?? ''));
    if ($botStatus === 'removed') {
        return ['is_admin'=>false, 'capabilities'=>array_fill_keys($labels, false)];
    }

    $rights=json_decode((string)($channel['rights_json'] ?? ''), true);
    if (!is_array($rights) || trim((string)($channel['rights_checked_at'] ?? '')) === '') {
        return ['is_admin'=>null, 'capabilities'=>$emptyCapabilities];
    }

    $permissions=array_values(array_filter(
        (array)($rights['permissions'] ?? []),
        static fn(mixed $permission): bool => is_string($permission) && $permission !== ''
    ));
    $isAdmin=!empty($rights['is_admin']) || !empty($rights['is_owner']);
    $implicitFullAccess=$isAdmin && $permissions === [];
    $canEdit=$implicitFullAccess;
    foreach (['edit', 'edit_message', 'post_edit_delete_message'] as $editPermission) {
        if (in_array($editPermission, $permissions, true)) {
            $canEdit=true;
            break;
        }
    }
    if (($channel['chat_type'] ?? '') !== 'channel' && in_array('write', $permissions, true)) {
        $canEdit=true;
    }

    return [
        'is_admin'=>$isAdmin,
        'capabilities'=>[
            'Публикует'=>!empty($channel['can_post']),
            'Редактирует'=>$canEdit,
            'Закрепляет'=>!empty($channel['can_pin']),
            'Удаляет'=>!empty($channel['can_delete']),
        ],
    ];
}

function adminMessengerIdentity(
    string $platform,
    string $id,
    string $displayName = '',
    string $username = ''
): array {
    $id=trim($id);
    $displayName=trim($displayName);
    $username=ltrim(trim($username), '@');
    $title=$displayName !== '' ? $displayName : ($username !== '' ? '@' . $username : 'Без имени');
    $details=[];
    if ($username !== '' && $title !== '@' . $username) $details[]='@' . $username;
    $details[]=$platform . ' ID ' . ($id !== '' ? $id : '—');
    return [$title, implode(' · ', $details)];
}

$pageTitles = [
    'overview' => ['Обзор', 'Состояние GitPosted на всех подключённых платформах', 'fa-solid fa-gauge-high'],
    'telegram' => ['Telegram', 'Настройки бота', '/assets/admin/platforms/telegram.webp'],
    'max' => ['МАКС', 'Настройки бота', '/assets/admin/platforms/max.webp'],
    'website' => ['Сайт', 'Публичная витрина ' . $siteHostname, 'fa-solid fa-globe'],
    'telegram-data' => ['Данные Telegram', 'Пользователи и каналы Telegram', 'fa-solid fa-paper-plane'],
    'max-data' => ['Данные МАКС', 'Пользователи и каналы МАКС', '/assets/admin/platforms/max.webp'],
    'subscriptions' => ['Подписки и тарифы', 'Доступ к каналам Telegram и МАКС', 'fa-solid fa-credit-card'],
    'system' => ['Система', 'Интеграции и состояние приложения', 'fa-solid fa-gear'],
];
$navigation = [
    'Главное' => [
        ['overview', 'fa-solid fa-gauge-high', 'Обзор'],
        ['subscriptions', 'fa-solid fa-credit-card', 'Подписки и тарифы'],
    ],
    'Боты' => [
        ['telegram', '/assets/admin/platforms/telegram.webp', 'Telegram'],
        ['max', '/assets/admin/platforms/max.webp', 'МАКС'],
    ],
    'Данные' => [
        ['telegram-data', 'fa-solid fa-paper-plane', 'Telegram'],
        ['max-data', '/assets/admin/platforms/max.webp', 'МАКС'],
        ['system', 'fa-solid fa-gear', 'Система'],
    ],
];
$pageTitle = $pageTitles[$page] ?? $pageTitles['overview'];
if(in_array($page,['telegram','max','subscriptions','system'],true))$pageTitle=['Настройки','Telegram, МАКС, подписки и общие параметры','fa-solid fa-gear'];
$csrf = (string) ($_SESSION['csrf'] ?? '');
$telegramBotEnabled = ($config['telegram_bot_enabled'] ?? false) === true;
$maxBotEnabled = ($config['max_bot_enabled'] ?? false) === true;
$telegramPolicyFormError='';
try{$telegramPolicyForm=PostedTelegramPolicy::fromConfig($config);}
catch(InvalidArgumentException){$telegramPolicyForm=PostedTelegramPolicy::defaults();$telegramPolicyFormError='Настройки доступа повреждены. Бот закрыт до исправления. Укажите оба режима и сохраните настройки.';}

$maxPolicyFormError='';try{$maxPolicyForm=GitPostedMaxPolicy::fromConfig($config);}catch(Throwable){$maxPolicyForm=['version'=>1,'pricing_mode'=>'free','access_mode'=>'open','allowed_max_ids'=>[]];$maxPolicyFormError='Настройки доступа МАКС повреждены. Бот закрыт до исправления. Укажите оба режима и сохраните настройки.';}
$maxTopPolicyError='';$maxTopQuotaError='';$maxTopQuotaSelected=null;
try{$maxTopPolicy=PostedTelegramTopHold::fromConfig($config);}catch(Throwable){$maxTopPolicy=PostedTelegramTopHold::defaults();$maxTopPolicyError='Настройки ТОП повреждены. Укажите длительность и сохраните её заново.';}
if($page==='max'&&(array_key_exists('max_top_channel_id',$_GET)||array_key_exists('max_top_user_id',$_GET))){try{$topChannel=(int)PostedTelegramPolicy::positiveId($_GET['max_top_channel_id']??null);$topUser=$_GET['max_top_user_id']??null;if(!is_string($topUser)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$topUser)!==1)throw new InvalidArgumentException('Некорректный ID МАКС.');$maxTopQuotaSelected=adminMaxTopQuotaView($pdo,$topChannel,$topUser);}catch(Throwable){$maxTopQuotaError='Не удалось открыть лимит. Укажите внутренний ID канала и ID МАКС пользователя с действующими правами.';}}
$maxLicenseSelected=null;$maxLicenseError='';if($page==='max'&&array_key_exists('max_license_channel_id',$_GET)){try{$maxLicenseSelected=adminMaxLicenseView($pdo,(int)PostedTelegramPolicy::positiveId($_GET['max_license_channel_id']));}catch(Throwable){$maxLicenseError='Не удалось прочитать подписку. Проверьте ID канала и сохранённые данные.';}}
$telegramTopPolicyError='';$telegramTopQuotaError='';$telegramTopQuotaSelected=null;
try{$telegramTopPolicy=PostedTelegramTopHold::fromConfig($config);}
catch(Throwable){$telegramTopPolicy=PostedTelegramTopHold::defaults();$telegramTopPolicyError='Настройки ТОП повреждены. Укажите длительность и сохраните её заново.';}
if($authorized&&$page==='telegram'&&(isset($_GET['top_channel_id'])||isset($_GET['top_user_id']))){
    try{$topChannel=(int)PostedTelegramPolicy::positiveId($_GET['top_channel_id']??null);$topUser=(int)PostedTelegramPolicy::positiveId($_GET['top_user_id']??null);
        $telegramTopQuotaSelected=adminTelegramTopQuotaView($pdo,$topChannel,$topUser);}
    catch(Throwable){$telegramTopQuotaError='Не удалось открыть лимит. Укажите внутренний ID канала и Telegram ID пользователя с действующими правами.';}
}
$billingError='';$billingSelected=null;$billingSearch=null;$billingReview=null;$billingPrices=null;$billingConfigHash=null;
$billingPlatform='telegram';$billingQuery='';$billingPage='0';$billingZone=PostedAdminChannelLicenses::timezone($config);
if($authorized&&$page==='subscriptions'){
    if(!isset($adminBillingView)||!is_array($adminBillingView))throw new RuntimeException('Billing view unavailable.');
    foreach(['error','selected','search','review','prices','config_hash','platform','query','page','zone']as$billingViewKey)if(!array_key_exists($billingViewKey,$adminBillingView))throw new RuntimeException('Billing view incomplete.');
    if(!is_string($adminBillingView['error'])||!is_string($adminBillingView['platform'])||!is_string($adminBillingView['query'])||!is_string($adminBillingView['page'])||!($adminBillingView['zone']instanceof DateTimeZone))throw new RuntimeException('Billing view malformed.');
    foreach(['selected','search','review','prices']as$billingViewKey)if($adminBillingView[$billingViewKey]!==null&&!is_array($adminBillingView[$billingViewKey]))throw new RuntimeException('Billing view malformed.');
    $billingError=$adminBillingView['error'];
    $billingSelected=$adminBillingView['selected'];
    $billingSearch=$adminBillingView['search'];
    $billingReview=$adminBillingView['review'];
    $billingPrices=$adminBillingView['prices'];
    $billingConfigHash=$adminBillingView['config_hash'];
    $billingPlatform=$adminBillingView['platform'];
    $billingQuery=$adminBillingView['query'];
    $billingPage=$adminBillingView['page'];
    $billingZone=$adminBillingView['zone'];
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($pageTitle[0]) ?> — GitPosted · Панель управления</title>
    <link rel="stylesheet" href="<?= e(gitpostedAppPath($config, '/assets/admin/fontawesome/css/fontawesome.min.css')) ?>?v=6.5.2">
    <link rel="stylesheet" href="<?= e(gitpostedAppPath($config, '/assets/admin/fontawesome/css/solid.min.css')) ?>?v=6.5.2">
    <link rel="stylesheet" href="<?= e(gitpostedAppPath($config, '/control/admin.css')) ?>?v=<?= (int) filemtime(__DIR__ . '/admin.css') ?>">
    <?php if (!$authorized && $hCaptchaRequired && $hCaptchaReady): ?>
        <script src="https://js.hcaptcha.com/1/api.js?hl=ru" async defer></script>
    <?php endif; ?>
</head>
<body class="<?= $authorized ? 'admin-body' : 'login-body' ?>" data-app-base-path="<?= e(gitpostedUrlMount((string) $config['base_url'])) ?>">
<?php if (!$authorized): ?>
<main class="login-page">
    <section class="login-card">
        <div class="login-card__visual">
            <div class="brand-mark brand-mark--large"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
            <div>
                <div class="eyebrow">ЗАЩИЩЁННЫЙ ВХОД</div>
                <h1>GitPosted</h1>
                <p>Единое управление Telegram, МАКС и сайтом.</p>
            </div>
        </div>
        <div class="login-card__form">
            <div class="eyebrow">ВХОД В ПАНЕЛЬ</div>
            <h2>С возвращением</h2>
            <p class="muted">Используйте логин и пароль администратора.</p>
            <?php if ($error !== ''): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" autocomplete="on">
                <input type="hidden" name="login_csrf" value="<?= e($_SESSION['login_csrf']) ?>">
                <label class="field">
                    <span>Логин</span>
                    <input name="login" autocomplete="username" required autofocus>
                </label>
                <label class="field">
                    <span>Пароль</span>
                    <input name="password" type="password" autocomplete="current-password" required>
                </label>
                <?php if ($hCaptchaRequired): ?><div class="captcha-shell">
                    <?php if ($hCaptchaReady): ?>
                        <div class="h-captcha" data-sitekey="<?= e($hCaptchaSiteKey) ?>" data-theme="dark"></div>
                    <?php else: ?>
                        <div class="captcha-unavailable">Проверка безопасности временно недоступна.</div>
                    <?php endif; ?>
                </div><?php endif; ?>
                <button class="button button--primary button--wide" <?= !$hCaptchaRequired || $hCaptchaReady ? '' : 'disabled' ?>><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span>Войти в панель</span></button>
            </form>
            <div class="passkey-login" id="passkeyLogin" hidden>
                <span>или</span>
                <button class="button button--quiet button--wide" type="button" id="passkeyLoginButton"><i class="fa-solid fa-key" aria-hidden="true"></i><span>Войти по ключу доступа</span></button>
            </div>
        </div>
    </section>
</main>
<?php else: ?>
<div class="admin-layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar__brand">
            <div class="brand-mark"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
            <div>
                <strong>GitPosted</strong>
                <span>Панель управления</span>
            </div>
            <button class="icon-button sidebar__close" type="button" data-menu-close aria-label="Закрыть меню"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <nav class="sidebar__nav" aria-label="Главная навигация">
            <?php foreach ($navigation as $group => $items): ?>
                <div class="nav-group">
                    <div class="nav-group__title"><?= e($group) ?></div>
                    <?php foreach ($items as [$target, $icon, $label]): ?>
                        <a class="nav-item <?= $page === $target ? 'nav-item--active' : '' ?>" href="<?= e(gitpostedAppPath($config, '/control/')) ?>?page=<?= e($target) ?>"<?php $settingsLink=['telegram'=>'telegram','max'=>'max','subscriptions'=>'payments','system'=>'common'][$target]??null;if($settingsLink!==null): ?> data-settings-nav="<?= e($settingsLink) ?>" data-settings-link="<?= e($settingsLink) ?>"<?php endif; ?>>
                            <span class="nav-item__icon">
                                <?php if (str_starts_with($icon, '/')): ?>
                                    <img src="<?= e(gitpostedAppPath($config, $icon)) ?>" alt="">
                                <?php else: ?>
                                    <i class="<?= e($icon) ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                            <span><?= e($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar__footer">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="button button--quiet button--wide sidebar-logout" type="submit"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Выйти</span></button>
            </form>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-menu-close></div>

    <main class="workspace">
        <header class="topbar">
            <button class="icon-button topbar__menu" type="button" data-menu-open aria-label="Открыть меню"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
            <div class="topbar__identity">
                <span class="topbar__page-icon">
                    <?php if (str_starts_with((string) $pageTitle[2], '/')): ?>
                        <img src="<?= e(gitpostedAppPath($config, (string) $pageTitle[2])) ?>" alt="">
                    <?php else: ?>
                        <i class="<?= e($pageTitle[2]) ?>" aria-hidden="true"></i>
                    <?php endif; ?>
                </span>
                <div>
                    <h1><?= e($pageTitle[0]) ?></h1>
                    <p><?= e($pageTitle[1]) ?></p>
                </div>
            </div>
            <a class="button button--quiet topbar__site-link" href="<?= e(gitpostedAppPath($config, '/')) ?>" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span>Открыть сайт</span></a>
        </header>

        <?php if ($flash !== ''): ?>
            <div class="alert alert--success" data-alert><span><?= e($flash) ?></span><button type="button" data-alert-close>×</button></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert--error" data-alert><span><?= e($error) ?></span><button type="button" data-alert-close>×</button></div>
        <?php endif; ?>
        <?php if ($maxDataError !== ''): ?>
            <div class="alert alert--warning" data-alert><span><?= e($maxDataError) ?></span><button type="button" data-alert-close>×</button></div>
        <?php endif; ?>

        <div class="page-content">
        <?php if ($authorized && in_array($page,['telegram','max','subscriptions','system'],true)):
            require_once __DIR__.'/settings-view.php';
            if(isset($adminSettingsView)&&is_array($adminSettingsView))echo PostedAdminSettingsView::render($adminSettingsView,$csrf,gitpostedAppPath($config,'/control/'));
        endif; ?>

        <?php if ($page === 'overview'): ?>
            <section class="metric-grid">
                <article class="metric-card">
                    <span class="metric-card__label">Пользователи</span>
                    <strong><?= e($stats['users'] ?? 0) ?></strong>
                    <small>Telegram <?= e($stats['tg_users'] ?? 0) ?> · МАКС <?= e($stats['max_users_total'] ?? 0) ?></small>
                </article>
                <article class="metric-card">
                    <span class="metric-card__label">Активные каналы</span>
                    <strong><?= e($stats['channels'] ?? 0) ?></strong>
                    <small>Telegram <?= e($stats['tg_channels'] ?? 0) ?> · МАКС <?= e($stats['max_channels'] ?? 0) ?></small>
                </article>
                <article class="metric-card">
                    <span class="metric-card__label">Публикации</span>
                    <strong><?= e($stats['posts'] ?? 0) ?></strong>
                    <small>Telegram <?= e($stats['tg_posts'] ?? 0) ?> · МАКС <?= e($stats['max_posts'] ?? 0) ?></small>
                </article>
            </section>

        <?php elseif ($page === 'subscriptions'): ?>
            <section id="channel-subscriptions" data-settings-utility="payments" class="panel bot-panel">
                <div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h2>Подписки каналов</h2></div>
                    <p>Ручная выдача и отзыв доступа к одному каналу. Платежи не создаются. Посты, расписание, права и лимиты сохраняются.</p>
                    <p><small>Даты показаны в <?= e($billingZone->getName()) ?> с UTC-смещением. В базе срок хранится в UTC. Периоды — точное количество суток.</small></p>
                    <?php if($billingError!==''): ?><p role="alert"><?= e($billingError) ?></p><?php endif; ?>
                    <form method="get" class="config-form bot-panel__config-form">
                        <input type="hidden" name="page" value="subscriptions">
                        <div class="bot-panel__field-grid">
                            <label class="field"><span>Платформа</span><select name="platform"><option value="telegram" <?= $billingPlatform==='telegram'?'selected':'' ?>>Telegram</option><option value="max" <?= $billingPlatform==='max'?'selected':'' ?>>МАКС</option></select></label>
                            <label class="field"><span>Канал или владелец</span><input name="q" maxlength="100" value="<?= e($billingQuery) ?>" placeholder="Название, ID, имя или username"></label>
                        </div><button class="button" type="submit">Найти каналы</button>
                    </form>
                    <?php if($billingSearch!==null): ?>
                        <div class="table-wrap"><table><thead><tr><th>Канал</th><th>Владелец</th><th>Подписка</th><th></th></tr></thead><tbody>
                        <?php foreach($billingSearch['rows']as$billingRow): $billingSub=$billingRow['subscription']; ?>
                            <tr><td><?= e($billingRow['title']) ?><br><small>ID <?= e($billingRow['id']) ?> · <?= e($billingRow['chat_id']) ?> · <?= (int)$billingRow['is_active']===1?'активен':'отключён' ?></small></td>
                                <td><?= e($billingRow['owner_name']??'—') ?><br><small><?= e($billingRow['owner_username']??'') ?> · ID <?= e($billingRow['added_by']??'—') ?></small></td>
                                <td><?= e($billingSub===null?$billingRow['error']:match($billingSub['state']){'active'=>'Активна','expired'=>'Истекла','revoked'=>'Отозвана',default=>'Не выдана'}) ?><br><small><?= e(PostedAdminChannelLicenses::format($billingSub['license']['expires_at']??null,$billingZone)) ?></small></td>
                                <td><a class="button button--quiet" href="<?= e(gitpostedAppPath($config, '/control/')) ?>?<?= e(http_build_query(['page'=>'subscriptions','platform'=>$billingPlatform,'q'=>$billingQuery,'list_page'=>$billingPage,'channel_id'=>$billingRow['id']])) ?>">Открыть</a></td></tr>
                        <?php endforeach; ?>
                        <?php if($billingSearch['rows']===[]): ?><tr><td colspan="4">Каналы не найдены.</td></tr><?php endif; ?>
                        </tbody></table></div>
                        <p><?php if($billingSearch['page']>0): ?><a class="button button--quiet" href="<?= e(gitpostedAppPath($config, '/control/')) ?>?<?= e(http_build_query(['page'=>'subscriptions','platform'=>$billingPlatform,'q'=>$billingQuery,'list_page'=>$billingSearch['page']-1])) ?>">← Назад</a><?php endif; ?>
                        <?php if($billingSearch['has_more']&&$billingSearch['page']<999): ?><a class="button button--quiet" href="<?= e(gitpostedAppPath($config, '/control/')) ?>?<?= e(http_build_query(['page'=>'subscriptions','platform'=>$billingPlatform,'q'=>$billingQuery,'list_page'=>$billingSearch['page']+1])) ?>">Дальше →</a><?php endif; ?></p>
                    <?php endif; ?>
                </div>
                <?php if($billingSelected!==null): $billingChannel=$billingSelected['channel']; ?>
                <div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h3><?= e($billingChannel['title']) ?> · <?= $billingPlatform==='telegram'?'Telegram':'МАКС' ?></h3></div>
                    <p>ID <?= e($billingChannel['id']) ?> · <?= e($billingChannel['chat_id']) ?> · владелец <?= e($billingChannel['added_by']??'—') ?></p>
                    <p>Подписка: <?= e(match($billingSelected['state']){'active'=>'активна','expired'=>'истекла','revoked'=>'отозвана',default=>'не выдана'}) ?> · до <?= e(PostedAdminChannelLicenses::format($billingSelected['license']['expires_at']??null,$billingZone)) ?></p>
                    <form method="post" class="config-form bot-panel__config-form">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="admin_channel_license_review"><input type="hidden" name="return_page" value="subscriptions">
                        <input type="hidden" name="platform" value="<?= e($billingPlatform) ?>"><input type="hidden" name="channel_id" value="<?= e($billingChannel['id']) ?>"><input type="hidden" name="license_hash" value="<?= e($billingSelected['license_hash']) ?>">
                        <div class="bot-panel__field-grid">
                            <label class="field"><span>Выдать или продлить</span><select name="period"><?php foreach(PostedAdminChannelLicenses::DAYS as$billingDays): ?><option value="<?= e($billingDays) ?>"><?= e($billingDays) ?> дней</option><?php endforeach; ?><option value="custom">До своей даты</option></select><small>Активная подписка продлевается от текущего срока. Истёкшая или отозванная начинается сейчас.</small></label>
                            <label class="field"><span>Своя дата, <?= e($billingZone->getName()) ?></span><input name="custom_expires" type="datetime-local" step="60"><small>Используется только при выборе «До своей даты».</small></label>
                        </div><button class="button" name="license_action" value="grant" type="submit">Проверить выдачу / продление</button>
                        <?php if($billingSelected['license']!==null): ?><button class="button button--quiet" name="license_action" value="revoke" type="submit">Проверить отзыв</button><?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>
                <?php if($billingReview!==null): ?>
                <div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h3>Проверьте изменение перед сохранением</h3></div>
                    <?php if($billingReview['kind']==='license'): ?>
                        <p><?= e($billingReview['channel']['title']) ?> · <?= $billingReview['platform']==='telegram'?'Telegram':'МАКС' ?> · ID <?= e($billingReview['channel']['id']) ?> · <?= e($billingReview['channel']['chat_id']) ?></p>
                        <p>Владелец: <?= e($billingReview['channel']['added_by']??'—') ?>.</p>
                        <p>Было: <?= e($billingReview['before']['status']??'не выдана') ?> · <?= e(PostedAdminChannelLicenses::format($billingReview['before']['expires_at']??null,$billingZone)) ?>.<br>
                        Станет: <?= $billingReview['after']['status']==='active'?'активна':'отозвана' ?> · <?= e(PostedAdminChannelLicenses::format($billingReview['after']['expires_at'],$billingZone)) ?>.</p>
                        <p><small>Отзыв не удаляет канал и публикации. Доступ через бесплатный пробный режим определяется настройками бота.</small></p>
                    <?php else: ?>
                        <p>Общие тарифы за один канал / Telegram и MAX. Валюта — RUB.</p><ul><?php foreach(PostedAdminChannelLicenses::DAYS as$billingDays): ?><li><?= e($billingDays) ?> дней: <?= e($billingReview['before'][$billingDays]) ?> → <?= e($billingReview['after'][$billingDays]) ?> ₽</li><?php endforeach; ?></ul>
                        <p>Новая цена применяется к новым оформленным платежам. Начатые платежи сохраняют сумму; неподтверждённая старая цена проверяется заново.</p>
                    <?php endif; ?>
                    <form method="post" class="config-form bot-panel__config-form">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="<?= $billingReview['kind']==='license'?'admin_channel_license_confirm':'admin_channel_tariffs_confirm' ?>"><input type="hidden" name="return_page" value="subscriptions"><input type="hidden" name="review_nonce" value="<?= e($billingReview['nonce']) ?>">
                        <button class="button" type="submit">Подтвердить это изменение</button><a class="button button--quiet" href="<?= e(gitpostedAppPath($config, '/control/')) ?>?page=subscriptions">Отмена</a>
                    </form>
                </div>
                <?php endif; ?>

            </section>
        <?php elseif ($page === 'telegram'): ?>
            <details id="utilities-telegram" data-settings-utility="telegram" class="panel settings-utilities"><summary>Действия с Telegram</summary><div class="bot-panel"><div class="bot-panel__section"><h3>Меню команд</h3><form method="post" class="config-form bot-panel__config-form">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="telegram_commands_refresh">
                        <input type="hidden" name="return_page" value="telegram">
                        <p><small>Обновляет только меню команд включённого Telegram-бота. При частичной ошибке можно повторить без изменения настроек и публикаций.</small></p>
                        <button class="button" type="submit">Повторить обновление меню команд</button>
                    </form></div><div class="bot-panel__section"><div class="bot-panel__section-head"><h3>Лимит топов пользователя</h3></div>
                    <p>Общий остаток пользователя в одном канале, включая обычных редакторов и рекламных менеджеров. Рекламные компании не разделяют этот лимит. Для владельца количество топов не ограничено.</p>
                    <form method="get" class="config-form bot-panel__config-form">
                        <input type="hidden" name="page" value="telegram">
                        <div class="bot-panel__field-grid">
                            <label class="field"><span>Внутренний ID канала</span><input name="top_channel_id" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="19" value="<?= e($telegramTopQuotaSelected['channel_id']??'') ?>" required></label>
                            <label class="field"><span>Telegram ID пользователя</span><input name="top_user_id" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="19" value="<?= e($telegramTopQuotaSelected['user_id']??'') ?>" required></label>
                        </div>
                        <button class="button" type="submit">Открыть текущий лимит</button>
                    </form>
                    <?php if($telegramTopQuotaError!==''): ?><p role="alert"><?= e($telegramTopQuotaError) ?></p><?php endif; ?>
                    <?php if($telegramTopQuotaSelected!==null): ?>
                        <p><?= e($telegramTopQuotaSelected['title']) ?> · Telegram ID <?= e($telegramTopQuotaSelected['user_id']) ?></p>
                        <?php if($telegramTopQuotaSelected['unlimited']): ?><p>Владелец канала: количество топов не ограничено.</p>
                        <?php else: ?>
                            <form method="post" class="config-form bot-panel__config-form">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="telegram_top_quota"><input type="hidden" name="return_page" value="telegram">
                                <input type="hidden" name="top_channel_id" value="<?= e($telegramTopQuotaSelected['channel_id']) ?>"><input type="hidden" name="top_user_id" value="<?= e($telegramTopQuotaSelected['user_id']) ?>"><input type="hidden" name="top_revision" value="<?= e($telegramTopQuotaSelected['quota']['revision']) ?>">
                                <label class="field"><span>Осталось топов</span><input type="number" name="top_remaining" min="0" max="1000000" step="1" value="<?= e($telegramTopQuotaSelected['quota']['remaining']) ?>" required><small>Сохраняется точный остаток. Если лимит изменился или завершается TOP-отправка, откройте его заново. Права на канал не выдаются и не меняются.</small></label>
                                <button class="button" type="submit">Сохранить лимит топов</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div><div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h3>Подписка на отдельный канал</h3></div>
                    <p>Ручная выдача подписки администратором. Подписка привязана к самому каналу и сохраняется при переносе аккаунта.</p>
                    <form method="post" class="config-form bot-panel__config-form">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="telegram_channel_license"><input type="hidden" name="return_page" value="telegram">
                        <div class="bot-panel__field-grid">
                            <label class="field"><span>Канал</span><input name="license_channel_id" inputmode="numeric" pattern="[1-9][0-9]*" list="telegram-license-channels" placeholder="ID канала в GitPosted" required><datalist id="telegram-license-channels"><?php foreach($channels as$licenseChannel): ?><option value="<?= e($licenseChannel['id']) ?>"><?= e($licenseChannel['title'].' · '.$licenseChannel['chat_id']) ?></option><?php endforeach; ?></datalist><small>Выберите из списка или введите внутренний ID канала. Это не Telegram ID пользователя.</small></label>
                            <label class="field"><span>Действует до, UTC</span><input type="datetime-local" name="license_expires_utc"><small>Срок обязателен для выдачи. Для отзыва оставьте поле пустым.</small></label>
                        </div>
                        <button class="button" type="submit" name="license_action" value="grant">Выдать вручную</button>
                        <button class="button" type="submit" name="license_action" value="revoke">Отозвать</button>
                    </form>
                    <?php $telegramLicenseRows=[];foreach($channels as$licenseChannel){try{$license=PostedTelegramPolicy::readLicense($pdo,$licenseChannel);if($license!==null)$telegramLicenseRows[]=['channel'=>$licenseChannel,'license'=>$license];}catch(InvalidArgumentException){$telegramLicenseRows[]=['channel'=>$licenseChannel,'license'=>null];}} ?>
                    <?php if($telegramLicenseRows!==[]): ?><div class="table-wrap"><table><thead><tr><th>Канал</th><th>Действует до, UTC</th><th>Статус</th></tr></thead><tbody><?php foreach($telegramLicenseRows as$licenseRow): ?><tr><td><?= e($licenseRow['channel']['title'].' · ID '.$licenseRow['channel']['id']) ?></td><td><?= e($licenseRow['license']['expires_at']??'—') ?></td><td><?= e($licenseRow['license']===null?'Повреждена':($licenseRow['license']['status']==='revoked'?'Отозвана':((string)$licenseRow['license']['expires_at']>gmdate('Y-m-d\TH:i:s\Z')?'Активна':'Истекла'))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                </div><div class="bot-panel__section bot-panel__section--metrics">
                    <div class="bot-panel__section-head"><h3>Статистика</h3></div>
                    <div class="bot-panel__metrics" role="group" aria-label="Статистика Telegram">
                        <div class="bot-panel__metric"><span>Пользователи</span><strong><?= e($stats['tg_users'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Каналы</span><strong><?= e($stats['tg_channels'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Публикации</span><strong><?= e($stats['tg_posts'] ?? 0) ?></strong></div>
                    </div>
                </div></div></details>

        <?php elseif ($page === 'max'): ?>
            <details id="utilities-max" data-settings-utility="max" class="panel settings-utilities"><summary>Действия с МАКС</summary><div class="bot-panel"><div class="bot-panel__section"><div class="bot-panel__section-head"><h3>Лимит топов пользователя</h3></div>
                    <p>Общий остаток пользователя в одном канале, включая обычных редакторов и рекламных менеджеров. Рекламные компании не разделяют этот лимит. Для владельца количество топов не ограничено.</p>
                    <form method="get" class="config-form bot-panel__config-form">
                        <input type="hidden" name="page" value="max">
                        <div class="bot-panel__field-grid">
                            <label class="field"><span>Внутренний ID канала</span><input name="max_top_channel_id" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="19" value="<?= e($maxTopQuotaSelected['channel_id']??'') ?>" required></label>
                            <label class="field"><span>ID МАКС пользователя</span><input name="max_top_user_id" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="19" value="<?= e($maxTopQuotaSelected['user_id']??'') ?>" required></label>
                        </div>
                        <button class="button" type="submit">Открыть текущий лимит</button>
                    </form>
                    <?php if($maxTopQuotaError!==''): ?><p role="alert"><?= e($maxTopQuotaError) ?></p><?php endif; ?>
                    <?php if($maxTopQuotaSelected!==null): ?>
                        <p><?= e($maxTopQuotaSelected['title']) ?> · ID МАКС <?= e($maxTopQuotaSelected['user_id']) ?></p>
                        <?php if($maxTopQuotaSelected['unlimited']): ?><p>Владелец канала: количество топов не ограничено.</p>
                        <?php else: ?>
                            <form method="post" class="config-form bot-panel__config-form">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="max_top_quota"><input type="hidden" name="return_page" value="max">
                                <input type="hidden" name="max_top_channel_id" value="<?= e($maxTopQuotaSelected['channel_id']) ?>"><input type="hidden" name="max_top_user_id" value="<?= e($maxTopQuotaSelected['user_id']) ?>"><input type="hidden" name="max_top_revision" value="<?= e($maxTopQuotaSelected['quota']['revision']) ?>">
                                <label class="field"><span>Осталось топов</span><input type="number" name="max_top_remaining" min="0" max="1000000" step="1" value="<?= e($maxTopQuotaSelected['quota']['remaining']) ?>" required><small>Сохраняется точный остаток. Если лимит изменился или завершается TOP-отправка, откройте его заново. Права на канал не выдаются и не меняются.</small></label>
                                <button class="button" type="submit">Сохранить лимит топов</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div><div class="bot-panel__section" id="max-license">
                    <div class="bot-panel__section-head"><h3>Подписки каналов МАКС</h3></div>
                    <p>Найдите канал или владельца, проверьте текущий срок и подтвердите выдачу, продление или отзыв.</p>
                    <a class="button" href="<?= e(gitpostedAppPath($config, '/control/')) ?>?page=subscriptions&amp;platform=max">Открыть подписки и общие тарифы</a>
                </div><div class="bot-panel__section bot-panel__section--metrics">
                    <div class="bot-panel__section-head"><h3>Статистика</h3></div>
                    <div class="bot-panel__metrics" role="group" aria-label="Статистика МАКС">
                        <div class="bot-panel__metric"><span>Пользователи</span><strong><?= e($stats['max_users_total'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Связано с Telegram</span><strong><?= e($stats['max_users_linked'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Каналы</span><strong><?= e($stats['max_channels'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Публикации</span><strong><?= e($stats['max_posts'] ?? 0) ?></strong></div>
                    </div>
                </div></div></details>

        <?php elseif ($page === 'website'): ?>
            <section class="hero-panel hero-panel--website">
                <div class="platform-logo platform-logo--website platform-logo--large"><?= adminPlatformIcon('website') ?></div>
                <div>
                    <div class="eyebrow">PUBLIC WEBSITE</div>
                    <h2><?= e($siteHostname) ?></h2>
                    <p>Главная бесплатная витрина и вход в GitPosted.</p>
                </div>
                <?= adminStatus(true, 'Сайт подключён', 'Сайт не готов') ?>
            </section>
            <section class="panel-grid panel-grid--two">
                <article class="panel">
                    <div class="panel__head"><div><div class="eyebrow">МАРШРУТЫ</div><h2>Конфигурация сайта</h2></div></div>
                    <dl class="config-list">
                        <div><dt>Главная</dt><dd><code><?= e($baseUrl) ?>/</code></dd></div>
                        <div><dt>Админка</dt><dd><code><?= e($baseUrl) ?>/control/</code></dd></div>
                    </dl>
                    <a class="button button--quiet" href="<?= e($baseUrl) ?>/" target="_blank" rel="noopener noreferrer">Открыть сайт ↗</a>
                </article>
            </section>

        <?php elseif ($page === 'telegram-data'): ?>
        <?php if(isset($rootedAccessView)&&is_array($rootedAccessView)){require_once __DIR__.'/rooted-access-view.php';echo PostedTelegramRootedAccessView::render($rootedAccessView,$csrf,gitpostedAppPath($config,'/control/'));} ?>
            <div class="messenger-data-grid" data-messenger-data="telegram">
            <section class="panel selection-panel" data-user-table="telegram" data-return-page="telegram-data">
                <div class="panel__head"><div><div class="eyebrow">УЧЁТНЫЕ ЗАПИСИ</div><h2>Пользователи Telegram</h2></div><div class="selection-toolbar" aria-live="polite"><span class="selection-toolbar__hint">Выберите пользователя</span></div></div>
                <div class="table-wrap"><table><thead><tr><th>Telegram ID</th><th>Имя Telegram</th><th>Username Telegram</th><th>Связанный МАКС</th><th>Доступ</th><th>Создан</th><th class="actions-column">Действия</th></tr></thead><tbody>
                <?php if (!$users): ?><tr><td colspan="7" class="empty">Пользователей Telegram пока нет.</td></tr><?php endif; ?>
                <?php foreach ($users as $user): ?><tr class="selectable-row" tabindex="0" data-telegram-id="<?= e($user['telegram_id']) ?>" data-linked-max-id="<?= e($user['linked_max_user_id'] ?? '') ?>">
                    <td><?= e($user['telegram_id']) ?></td>
                    <td><strong><?= e($user['first_name'] ?: 'Без имени') ?></strong></td>
                    <td><?= e($user['username'] ? '@' . $user['username'] : 'без username') ?></td>
                    <td><?= e($user['linked_max_user_id'] ?: 'не связан') ?></td>
                    <td><?= adminStatus((bool) $user['is_active'], 'Активен', 'Заблокирован') ?></td>
                    <td><?= e($user['created_at']) ?></td>
                    <td class="actions-column"><form method="post" class="row-actions" <?= $user['is_active'] ? 'data-confirm="Заблокировать пользователя?"' : '' ?>><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="user_status"><input type="hidden" name="return_page" value="telegram-data"><input type="hidden" name="user_id" value="<?= e($user['telegram_id']) ?>"><button class="action-button <?= $user['is_active'] ? 'action-button--danger' : 'action-button--success' ?>" title="<?= $user['is_active'] ? 'Заблокировать' : 'Разблокировать' ?>"><i class="fa-solid <?= $user['is_active'] ? 'fa-lock' : 'fa-lock-open' ?>" aria-hidden="true"></i><span><?= $user['is_active'] ? 'Блок' : 'Разблокировать' ?></span></button></form></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            </section>
            <section class="panel selection-panel" data-channel-table="telegram">
                <div class="panel__head"><div><div class="eyebrow">TELEGRAM</div><h2>Каналы Telegram</h2><p>Владелец управляет каналом, редакторы помогают с публикациями.</p></div><div class="selection-toolbar" aria-live="polite"><span class="selection-toolbar__hint">Выберите канал</span></div></div>
                <div class="table-wrap"><table><thead><tr><th>ID</th><th>Канал</th><th>Chat ID</th><th>Владелец</th><th>Редакторы</th><th>Права бота</th><th>Статус</th><th>Обновлён</th><th class="actions-column">Действия</th></tr></thead><tbody>
                <?php if (!$channels): ?><tr><td colspan="9" class="empty">Каналов Telegram пока нет.</td></tr><?php endif; ?>
                <?php foreach ($channels as $channel):
                    $telegramOwnerIdentity=!empty($channel['added_by'])
                        ? adminMessengerIdentity(
                            'Telegram',
                            (string)$channel['added_by'],
                            (string)($channel['owner_name'] ?? ''),
                            (string)($channel['owner_username'] ?? '')
                        )
                        : null;
                    $telegramEditors=($telegramEditorsByChannel ?? [])[(int)$channel['id']] ?? [];
                ?>
                    <tr class="selectable-row" tabindex="0">
                        <td><?= e($channel['id']) ?></td>
                        <td><strong><?= e($channel['title']) ?></strong><small><?= e($channel['username'] ? '@' . $channel['username'] : '') ?></small></td>
                        <td><?= e($channel['chat_id']) ?></td>
                        <td><?php if ($telegramOwnerIdentity !== null): ?><div class="role-list"><div class="role-person"><strong><?= e($telegramOwnerIdentity[0]) ?></strong><small><?= e($telegramOwnerIdentity[1]) ?></small></div></div><?php else: ?><span class="muted">Не назначен</span><?php endif; ?></td>
                        <td><?php if ($telegramEditors !== []): ?><div class="role-list"><?php foreach ($telegramEditors as $editor): $editorIdentity=adminMessengerIdentity('Telegram', (string)$editor['user_id'], (string)($editor['display_name'] ?? ''), (string)($editor['username'] ?? '')); ?><div class="role-person"><strong><?= e($editorIdentity[0]) ?></strong><small><?= e($editorIdentity[1]) ?></small></div><?php endforeach; ?></div><?php else: ?><span class="muted">Нет редакторов</span><?php endif; ?></td>
                        <td><?= adminStatus((bool) $channel['bot_can_post'], 'Публикует', 'Нет прав') ?></td>
                        <td><?= adminStatus(empty($channel['admin_blocked']) && !empty($channel['is_active']), 'Активен', !empty($channel['admin_blocked']) ? 'Заблокирован' : 'Отключён') ?></td>
                        <td><?= e($channel['updated_at']) ?></td>
                        <td class="actions-column"><div class="row-actions">
                            <?php if (!empty($channel['added_by'])): ?>
                            <form method="post" <?= empty($channel['admin_blocked']) ? 'data-confirm="Заблокировать канал?"' : '' ?>><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="channel_status"><input type="hidden" name="return_page" value="telegram-data"><input type="hidden" name="channel_id" value="<?= e($channel['id']) ?>"><button class="icon-button <?= empty($channel['admin_blocked']) ? 'icon-button--danger' : 'icon-button--success' ?>" title="<?= empty($channel['admin_blocked']) ? 'Заблокировать канал' : 'Разблокировать канал' ?>" aria-label="<?= empty($channel['admin_blocked']) ? 'Заблокировать канал' : 'Разблокировать канал' ?>"><i class="fa-solid <?= empty($channel['admin_blocked']) ? 'fa-lock' : 'fa-lock-open' ?>" aria-hidden="true"></i></button></form>
                            <form method="post" data-confirm="Отключить канал и отвязать его от владельца? Публикации и история сохранятся."><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="channel_detach"><input type="hidden" name="return_page" value="telegram-data"><input type="hidden" name="channel_id" value="<?= e($channel['id']) ?>"><button class="icon-button icon-button--danger" title="Отключить и отвязать владельца" aria-label="Отключить и отвязать владельца"><i class="fa-solid fa-link-slash" aria-hidden="true"></i></button></form>
                            <?php else: ?><span class="tag">Нет владельца</span><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </section>
            </div><!-- /messenger-data-grid -->

        <?php elseif ($page === 'max-data'): ?>
            <div class="messenger-data-grid" data-messenger-data="max">
            <section class="panel selection-panel" data-user-table="max" data-return-page="max-data">
                <div class="panel__head"><div><div class="eyebrow">УЧЁТНЫЕ ЗАПИСИ</div><h2>Пользователи МАКС</h2></div><div class="selection-toolbar" aria-live="polite"><span class="selection-toolbar__hint">Выберите пользователя</span></div></div>
                <div class="table-wrap"><table><thead><tr><th>МАКС ID</th><th>Пользователь</th><th>Связанный Telegram</th><th>Доступ</th><th>Права</th><th>Обновлён</th><th class="actions-column">Действия</th></tr></thead><tbody>
                <?php if (!$maxSchemaInstalled): ?><tr><td colspan="7" class="empty">Схема МАКС ещё не установлена.</td></tr><?php elseif (!$maxUsers): ?><tr><td colspan="7" class="empty">Пользователей МАКС пока нет.</td></tr><?php endif; ?>
                <?php foreach ($maxUsers as $user): ?><tr class="selectable-row" tabindex="0" data-max-id="<?= e($user['max_user_id']) ?>" data-linked-telegram-id="<?= e($user['linked_telegram_id'] ?? '') ?>">
                    <td><?= e($user['max_user_id']) ?></td>
                    <td><strong><?= e($user['display_name'] ?: 'Без имени') ?></strong><small><?= e(($user['username'] ? '@' . $user['username'] : 'без username') . ' · chat: ' . ($user['private_chat_id'] ?: '—')) ?></small></td>
                    <td><?= e($user['linked_telegram_id'] ?: 'не связан') ?></td>
                    <td><?= adminStatus((bool) $user['is_active'], 'Активен', 'Заблокирован') ?></td>
                    <td><?= e('каналов ' . $user['channel_count'] . ' · пост ' . $user['can_post_count'] . ' · план ' . $user['can_schedule_count']) ?></td>
                    <td><?= e($user['updated_at']) ?></td>
                    <td class="actions-column"><form method="post" class="row-actions" <?= $user['is_active'] ? 'data-confirm="Заблокировать пользователя?"' : '' ?>><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="max_user_status"><input type="hidden" name="return_page" value="max-data"><input type="hidden" name="max_user_id" value="<?= e($user['max_user_id']) ?>"><button class="action-button <?= $user['is_active'] ? 'action-button--danger' : 'action-button--success' ?>" title="<?= $user['is_active'] ? 'Заблокировать' : 'Разблокировать' ?>"><i class="fa-solid <?= $user['is_active'] ? 'fa-lock' : 'fa-lock-open' ?>" aria-hidden="true"></i><span><?= $user['is_active'] ? 'Блок' : 'Разблокировать' ?></span></button></form></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            </section>
            <section class="panel selection-panel" data-channel-table="max">
                <div class="panel__head"><div><div class="eyebrow">МАКС</div><h2>Каналы МАКС</h2><p>Владелец управляет каналом, редакторы помогают с публикациями.</p></div><div class="selection-toolbar" aria-live="polite"><span class="selection-toolbar__hint">Выберите канал</span></div></div>
                <div class="table-wrap"><table><thead><tr><th>Канал</th><th>Владелец</th><th>Редакторы</th><th>Возможности бота</th><th>Доступ</th><th>Проверено</th><th class="actions-column">Действия</th></tr></thead><tbody>
                <?php if (!$maxSchemaInstalled): ?><tr><td colspan="7" class="empty">Схема МАКС ещё не установлена.</td></tr><?php elseif (!$maxChannels): ?><tr><td colspan="7" class="empty">Каналов МАКС пока нет.</td></tr><?php endif; ?>
                <?php foreach ($maxChannels as $channel):
                    $maxOwnerIdentity=!empty($channel['added_by'])
                        ? adminMessengerIdentity(
                            'МАКС',
                            (string)$channel['added_by'],
                            (string)($channel['owner_name'] ?? ''),
                            (string)($channel['owner_username'] ?? '')
                        )
                        : null;
                    $maxEditors=($maxEditorsByChannel ?? [])[(int)$channel['id']] ?? [];
                    $maxBotSummary=adminMaxChannelBotSummary($channel);
                ?>
                    <tr class="selectable-row" tabindex="0">
                        <td><strong><?= e($channel['title']) ?></strong><small><?= e('Chat ID ' . $channel['chat_id']) ?></small><small><?= e('Запись #' . $channel['id']) ?></small></td>
                        <td><?php if ($maxOwnerIdentity !== null): ?><div class="role-list"><div class="role-person"><strong><?= e($maxOwnerIdentity[0]) ?></strong><small><?= e($maxOwnerIdentity[1]) ?></small></div></div><?php else: ?><span class="muted">Не назначен</span><?php endif; ?></td>
                        <td><?php if ($maxEditors !== []): ?><div class="role-list"><?php foreach ($maxEditors as $editor): $editorIdentity=adminMessengerIdentity('МАКС', (string)$editor['user_id'], (string)($editor['display_name'] ?? ''), (string)($editor['username'] ?? '')); ?><div class="role-person"><strong><?= e($editorIdentity[0]) ?></strong><small><?= e($editorIdentity[1]) ?></small></div><?php endforeach; ?></div><?php else: ?><span class="muted">Нет редакторов</span><?php endif; ?></td>
                        <td>
                            <?php if ($maxBotSummary['is_admin'] === null): ?><span class="tag">Права бота не проверены</span><?php else: ?><?= adminStatus((bool)$maxBotSummary['is_admin'], 'Бот — администратор', 'Бот не администратор') ?><?php endif; ?>
                            <div class="bot-capabilities" role="list" aria-label="Возможности бота">
                            <?php foreach ($maxBotSummary['capabilities'] as $capabilityLabel => $capabilityState):
                                $capabilityClass=$capabilityState === null ? 'unknown' : ($capabilityState ? 'ok' : 'off');
                                $capabilityIcon=$capabilityState === null ? 'fa-question' : ($capabilityState ? 'fa-check' : 'fa-xmark');
                                $capabilityText=$capabilityState === null ? 'не проверено' : ($capabilityState ? 'да' : 'нет');
                            ?><span class="bot-capability bot-capability--<?= e($capabilityClass) ?>" role="listitem"><i class="fa-solid <?= e($capabilityIcon) ?>" aria-hidden="true"></i><span><?= e($capabilityLabel) ?></span><small><?= e($capabilityText) ?></small></span><?php endforeach; ?>
                            </div>
                        </td>
                        <td><?= adminStatus(empty($channel['admin_blocked']) && !empty($channel['is_active']), 'Активен', !empty($channel['admin_blocked']) ? 'Заблокирован' : 'Отключён') ?></td>
                        <td><?= e($channel['rights_checked_at'] ?: 'не проверено') ?></td>
                        <td class="actions-column"><div class="row-actions">
                            <?php if (!empty($channel['added_by'])): ?>
                            <form method="post" <?= empty($channel['admin_blocked']) ? 'data-confirm="Заблокировать канал?"' : '' ?>><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="max_channel_status"><input type="hidden" name="return_page" value="max-data"><input type="hidden" name="channel_id" value="<?= e($channel['id']) ?>"><button class="icon-button <?= empty($channel['admin_blocked']) ? 'icon-button--danger' : 'icon-button--success' ?>" title="<?= empty($channel['admin_blocked']) ? 'Заблокировать канал' : 'Разблокировать канал' ?>" aria-label="<?= empty($channel['admin_blocked']) ? 'Заблокировать канал' : 'Разблокировать канал' ?>"><i class="fa-solid <?= empty($channel['admin_blocked']) ? 'fa-lock' : 'fa-lock-open' ?>" aria-hidden="true"></i></button></form>
                            <form method="post" data-confirm="Отключить канал и отвязать его от владельца? Публикации и история сохранятся."><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="max_channel_detach"><input type="hidden" name="return_page" value="max-data"><input type="hidden" name="channel_id" value="<?= e($channel['id']) ?>"><button class="icon-button icon-button--danger" title="Отключить и отвязать владельца" aria-label="Отключить и отвязать владельца"><i class="fa-solid fa-link-slash" aria-hidden="true"></i></button></form>
                            <?php else: ?><span class="tag">Нет владельца</span><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </section>
            </div><!-- /messenger-data-grid -->

        <?php else: ?>
            <div id="system-tools" data-settings-utility="common">
            <section class="panel passkey-panel">
                <div class="panel__head">
                    <div><div class="eyebrow">БЕЗОПАСНОСТЬ</div><h2>Ключи доступа</h2></div>
                    <button class="icon-button" type="button" id="passkeyAddButton" title="Добавить ключ" aria-label="Добавить ключ"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                </div>
                <p class="muted">Вход без пароля по ключу устройства, телефону или аппаратному ключу. Можно добавить до трёх ключей.</p>
                <div class="passkey-list" id="passkeyList"><div class="empty">Загрузка ключей…</div></div>
                <div class="alert alert--error" id="passkeyError" hidden></div>
            </section>
            <section class="metric-grid metric-grid--compact">
                <article class="metric-card"><span class="metric-card__label">Telegram</span><strong><?= $telegramConfigured ? 'Готово' : '—' ?></strong><small>Бот подключён</small></article>
                <article class="metric-card"><span class="metric-card__label">МАКС</span><strong><?= $maxConfigured && $maxSchemaInstalled ? 'Готово' : '—' ?></strong><small>Бот и данные подключены</small></article>
            </section>
            <section class="panel-grid panel-grid--two">
                <article class="panel">
                    <div class="panel__head"><div><div class="eyebrow">ИНТЕГРАЦИИ</div><h2>Точки входа</h2></div></div>
                    <dl class="config-list">
                        <div><dt>Telegram webhook</dt><dd><code><?= e(gitpostedAppPath($config, '/webhook.php')) ?></code></dd></div>
                        <div><dt>Webhook МАКС</dt><dd><code>/max-webhook.php</code></dd></div>
                        <div><dt>Планировщик</dt><dd><code>cron.php</code></dd></div>
                    </dl>
                </article>
                <article class="panel">
                    <div class="panel__head"><div><div class="eyebrow">ЗАЩИТА</div><h2>Состояние панели</h2></div></div>
                    <dl class="config-list">
                        <div><dt>Авторизация</dt><dd><?= adminStatus(true, 'Включена', 'Выключена') ?></dd></div>
                        <div><dt>Защита форм</dt><dd><?= adminStatus($csrf !== '', 'Активна', 'Неактивна') ?></dd></div>
                        <div><dt>Защита входа</dt><dd><?= adminStatus(true, 'Активна', 'Выключена') ?></dd></div>
                        <div><dt>Секреты</dt><dd><?= adminStatus(true, 'Вне webroot', 'В webroot') ?></dd></div>
                        <div><dt>Кэш страницы</dt><dd><?= adminStatus(false, 'Включён', 'Отключён') ?></dd></div>
                    </dl>
                </article>
            </section>
            </div>
        <?php endif; ?>
        </div>
    </main>
</div>
<script src="<?= e(gitpostedAppPath($config, '/control/admin.js')) ?>?v=<?= (int) filemtime(__DIR__ . '/admin.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
