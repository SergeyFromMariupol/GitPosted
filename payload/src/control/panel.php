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
    'system' => ['Система', 'Интеграции и состояние приложения', 'fa-solid fa-gear'],
];
$navigation = [
    'Главное' => [
        ['overview', 'fa-solid fa-gauge-high', 'Обзор'],
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
$csrf = (string) ($_SESSION['csrf'] ?? '');
$telegramBotEnabled = ($config['telegram_bot_enabled'] ?? false) === true;
$maxBotEnabled = ($config['max_bot_enabled'] ?? false) === true;
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
                        <a class="nav-item <?= $page === $target ? 'nav-item--active' : '' ?>" href="<?= e(gitpostedAppPath($config, '/control/')) ?>?page=<?= e($target) ?>">
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

        <?php elseif ($page === 'telegram'): ?>
            <section class="panel bot-panel bot-panel--telegram" data-bot-panel="telegram" aria-labelledby="telegram-bot-title">
                <header class="bot-panel__header">
                    <div class="bot-panel__identity">
                        <div class="platform-logo platform-logo--telegram platform-logo--large"><?= adminPlatformIcon('telegram') ?></div>
                        <div class="bot-panel__identity-copy">
                            <div class="bot-panel__title-row">
                                <h2 id="telegram-bot-title">@<?= e(ltrim((string) ($config['bot_username'] ?? ''), '@')) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="bot-panel__header-actions">
                        <form method="post" class="bot-panel__switch-form" aria-label="Состояние Telegram-бота">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="bot_toggle">
                            <input type="hidden" name="return_page" value="telegram">
                            <input type="hidden" name="platform" value="telegram">
                            <button class="bot-switch <?= $telegramBotEnabled ? 'bot-switch--on' : 'bot-switch--off' ?>" type="submit" name="enabled" value="<?= $telegramBotEnabled ? '0' : '1' ?>" role="switch" aria-checked="<?= $telegramBotEnabled ? 'true' : 'false' ?>" aria-label="Telegram-бот" title="<?= $telegramBotEnabled ? 'Отключить Telegram-бота' : 'Включить Telegram-бота' ?>">
                                <span class="bot-switch__copy">
                                    <strong><?= $telegramBotEnabled ? 'Бот включён' : 'Бот отключён' ?></strong>
                                </span>
                                <span class="bot-switch__track" aria-hidden="true"><span class="bot-switch__thumb"></span></span>
                            </button>
                        </form>
                        <button class="icon-button bot-panel__save-button" type="submit" form="telegram-config-form" title="Сохранить" aria-label="Сохранить настройки Telegram"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button>
                    </div>
                </header>

                <div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h3>Подключение</h3></div>
                    <dl class="bot-panel__connection-grid">
                        <div><dt>Конфигурация</dt><dd><?= adminStatus($telegramConfigured, 'Готова', 'Не готова') ?></dd></div>
                        <div><dt>Токен</dt><dd><?= adminStatus(trim((string) ($config['bot_token'] ?? '')) !== '', 'Задан', 'Не задан') ?></dd></div>
                        <div><dt>Webhook</dt><dd><code><?= e($baseUrl) ?>/webhook.php</code></dd></div>
                        <div><dt>Секрет webhook</dt><dd><?= adminStatus(trim((string) ($config['webhook_secret'] ?? '')) !== '', 'Задан', 'Не задан') ?></dd></div>
                    </dl>
                </div>

                <div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h3>Настройки</h3></div>
                    <form id="telegram-config-form" method="post" class="config-form bot-panel__config-form" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="telegram_config">
                        <input type="hidden" name="return_page" value="telegram">
                        <div class="bot-panel__field-grid">
                            <label class="field"><span>Username бота</span><input name="bot_username" maxlength="32" value="<?= e(ltrim((string) ($config['bot_username'] ?? ''), '@')) ?>" required></label>
                            <label class="field"><span>Часовой пояс хранения</span><input name="timezone" value="<?= e($config['timezone'] ?? 'Europe/Moscow') ?>" readonly required><small>Часовой пояс хранения фиксирован. Часовой пояс отображения меняется в настройках бота.</small></label>
                            <label class="field"><span>Username поддержки (необязательно)</span><input name="service_admin_username" value="<?= e(ltrim((string) ($config['service_admin_username'] ?? ''), '@')) ?>"></label>
                            <label class="field"><span>Новый токен</span><input name="bot_token" type="password" autocomplete="new-password" placeholder="Пусто — оставить действующий"></label>
                            <label class="field"><span>Новый секрет webhook</span><input name="webhook_secret" type="password" autocomplete="new-password" placeholder="Пусто — оставить действующий"></label>
                            <label class="field field--checkbox"><span><input name="takeover_webhook" type="checkbox" value="1"> Перенести бота из другого сервиса</span><small>Разрешаю заменить чужой webhook. Предыдущий сервис перестанет получать события этого бота.</small></label>
                        </div>
                    </form>
                </div>

                <div class="bot-panel__section bot-panel__section--metrics">
                    <div class="bot-panel__section-head"><h3>Статистика</h3></div>
                    <div class="bot-panel__metrics" role="group" aria-label="Статистика Telegram">
                        <div class="bot-panel__metric"><span>Пользователи</span><strong><?= e($stats['tg_users'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Каналы</span><strong><?= e($stats['tg_channels'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Публикации</span><strong><?= e($stats['tg_posts'] ?? 0) ?></strong></div>
                    </div>
                </div>
            </section>

        <?php elseif ($page === 'max'): ?>
            <section class="panel bot-panel bot-panel--max" data-bot-panel="max" aria-labelledby="max-bot-title">
                <header class="bot-panel__header">
                    <div class="bot-panel__identity">
                        <div class="platform-logo platform-logo--max platform-logo--large"><?= adminPlatformIcon('max') ?></div>
                        <div class="bot-panel__identity-copy">
                            <div class="bot-panel__title-row">
                                <h2 id="max-bot-title">@<?= e(ltrim((string) ($config['max_bot_username'] ?? ''), '@')) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="bot-panel__header-actions">
                        <form method="post" class="bot-panel__switch-form" aria-label="Состояние МАКС-бота">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="bot_toggle">
                            <input type="hidden" name="return_page" value="max">
                            <input type="hidden" name="platform" value="max">
                            <button class="bot-switch <?= $maxBotEnabled ? 'bot-switch--on' : 'bot-switch--off' ?>" type="submit" name="enabled" value="<?= $maxBotEnabled ? '0' : '1' ?>" role="switch" aria-checked="<?= $maxBotEnabled ? 'true' : 'false' ?>" aria-label="МАКС-бот" title="<?= $maxBotEnabled ? 'Отключить МАКС-бота' : 'Включить МАКС-бота' ?>">
                                <span class="bot-switch__copy">
                                    <strong><?= $maxBotEnabled ? 'Бот включён' : 'Бот отключён' ?></strong>
                                </span>
                                <span class="bot-switch__track" aria-hidden="true"><span class="bot-switch__thumb"></span></span>
                            </button>
                        </form>
                        <button class="icon-button bot-panel__save-button" type="submit" form="max-config-form" title="Сохранить" aria-label="Сохранить настройки МАКС"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button>
                    </div>
                </header>

                <div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h3>Подключение</h3></div>
                    <dl class="bot-panel__connection-grid">
                        <div><dt>Конфигурация</dt><dd><?= adminStatus($maxConfigured && $maxSchemaInstalled, 'Готова', 'Не готова') ?></dd></div>
                        <div><dt>Токен</dt><dd><?= adminStatus(trim((string) ($config['max_bot_token'] ?? '')) !== '', 'Задан', 'Не задан') ?></dd></div>
                        <div><dt>Webhook</dt><dd><code><?= e($baseUrl) ?>/max-webhook.php</code></dd></div>
                        <div><dt>Схема данных</dt><dd><?= adminStatus($maxSchemaInstalled, 'Установлена', 'Не установлена') ?></dd></div>
                    </dl>
                </div>

                <div class="bot-panel__section">
                    <div class="bot-panel__section-head"><h3>Настройки</h3></div>
                    <form id="max-config-form" method="post" class="config-form bot-panel__config-form" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="max_config">
                        <input type="hidden" name="return_page" value="max">
                        <div class="bot-panel__field-grid">
                            <label class="field"><span>Username бота</span><input name="max_bot_username" maxlength="60" value="<?= e(ltrim((string) ($config['max_bot_username'] ?? ''), '@')) ?>" required></label>
                            <label class="field"><span>API URL МАКС</span><input name="max_api_base_url" type="url" value="<?= e($config['max_api_base_url'] ?? 'https://platform-api2.max.ru') ?>" readonly required></label>
                            <label class="field"><span>Новый токен</span><input name="max_bot_token" type="password" autocomplete="new-password" placeholder="Пусто — оставить действующий"></label>
                            <label class="field"><span>Новый секрет webhook</span><input name="max_webhook_secret" type="password" autocomplete="new-password" placeholder="Пусто — оставить действующий"></label>
                            <label class="field field--checkbox"><span><input name="takeover_webhook" type="checkbox" value="1"> Перенести бота из другого сервиса</span><small>Разрешаю удалить прежние подписки после подтверждения нового webhook GitPosted.</small></label>
                        </div>
                    </form>
                </div>

                <div class="bot-panel__section bot-panel__section--metrics">
                    <div class="bot-panel__section-head"><h3>Статистика</h3></div>
                    <div class="bot-panel__metrics" role="group" aria-label="Статистика МАКС">
                        <div class="bot-panel__metric"><span>Пользователи</span><strong><?= e($stats['max_users_total'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Связано с Telegram</span><strong><?= e($stats['max_users_linked'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Каналы</span><strong><?= e($stats['max_channels'] ?? 0) ?></strong></div>
                        <div class="bot-panel__metric"><span>Публикации</span><strong><?= e($stats['max_posts'] ?? 0) ?></strong></div>
                    </div>
                </div>
            </section>

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
        <?php endif; ?>
        </div>
    </main>
</div>
<script src="<?= e(gitpostedAppPath($config, '/control/admin.js')) ?>?v=<?= (int) filemtime(__DIR__ . '/admin.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
