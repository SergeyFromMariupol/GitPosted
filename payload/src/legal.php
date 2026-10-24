<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function gitpostedLegalPage(string $documentKey): never
{
    $config = PostedConfig::get();
    $legalPath = static fn(string $path): string => htmlspecialchars(gitpostedAppPath($config, $path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $supportUsername = ltrim((string) ($config['service_admin_username'] ?? ''), '@');
    $supportHandle = preg_match('/^[A-Za-z0-9_]{5,32}$/D', $supportUsername) === 1
        ? '@' . $supportUsername : 'контакт владельца пока не указан';
    $telegramUsername = ltrim((string) ($config['bot_username'] ?? ''), '@');
    $maxUsername = ltrim((string) ($config['max_bot_username'] ?? ''), '@');
    $platforms = [];
    $botUrl = null;
    if (($config['telegram_bot_enabled'] ?? false) === true && preg_match('/^[A-Za-z0-9_]{5,32}$/D', $telegramUsername) === 1) {
        $platforms[] = 'Telegram-боту @' . $telegramUsername;
        $botUrl = 'https://t.me/' . rawurlencode($telegramUsername);
    }
    if (($config['max_bot_enabled'] ?? false) === true && preg_match('/^[A-Za-z0-9_]{3,128}$/D', $maxUsername) === 1) {
        $platforms[] = 'боту МАКС @' . $maxUsername;
        $botUrl ??= 'https://max.ru/' . rawurlencode($maxUsername);
    }
    $siteHost = (string) (
        parse_url((string) ($config['base_url'] ?? 'https://example.com'), PHP_URL_HOST)
        ?: 'example.com'
    );
    $documents = [
        'agreement' => [
            'title' => 'Пользовательское соглашение',
            'description' => 'Правила использования ботов GitPosted в Telegram и МАКС и связанных функций сервиса.',
            'sections' => [
                ['title' => '1. Принятие соглашения', 'paragraphs' => [
                    'Запуская GitPosted или продолжая пользоваться ботом, пользователь подтверждает, что ознакомился с настоящим соглашением и принимает его условия.',
                    'Если пользователь действует от имени владельца канала или организации, он подтверждает наличие необходимых полномочий.',
                ]],
                ['title' => '2. Назначение сервиса', 'paragraphs' => [
                    'GitPosted помогает создавать, сохранять, планировать, публиковать, редактировать, повторять и удалять публикации в каналах Telegram и МАКС, к которым пользователь предоставил соответствующему боту доступ.',
                    'Конкретный набор возможностей зависит от функций, поддерживаемых Telegram или МАКС. Одинаково названные функции могут работать по-разному из-за технических ограничений конкретной платформы.',
                ]],
                ['title' => '3. Подключение каналов и аккаунтов', 'items' => [
                    'Пользователь самостоятельно добавляет соответствующего бота GitPosted в канал Telegram или МАКС и выдаёт только те административные права, которые нужны для выбранных действий.',
                    'Для совместного использования настроек пользователь может по своей инициативе связать аккаунт МАКС с аккаунтом Telegram посредством одноразового кода. Такая привязка сопоставляет платформенные идентификаторы одного пользователя и не передаёт GitPosted пароль от аккаунта.',
                    'Владелец подключённого канала может назначать редакторов внутри GitPosted. Редактор действует только в пределах выданных прав и может самостоятельно отказаться от редактирования.',
                    'Пользователь обязан удалить бота, отозвать его права или запросить удаление привязки аккаунтов, если доступ больше не требуется.',
                    'GitPosted не предоставляет пользователю права на чужой канал и не проверяет имущественные отношения между администраторами.',
                ]],
                ['title' => '4. Контент пользователя', 'paragraphs' => [
                    'Права на текст, медиа, ссылки и иные материалы остаются у пользователя или их законного правообладателя. Пользователь разрешает техническую обработку материалов только для выполнения выбранных операций.',
                    'Пользователь самостоятельно отвечает за законность материалов, достоверность рекламы, соблюдение авторских прав, маркировку и другие требования к публикации.',
                ]],
                ['title' => '5. Запрещённое использование', 'items' => [
                    'Публикация материалов, нарушающих закон, права третьих лиц или правила Telegram либо МАКС.',
                    'Распространение вредоносного кода, фишинга, спама либо попытки получить несанкционированный доступ.',
                    'Вмешательство в работу сервиса, обход технических ограничений, автоматизированная перегрузка или передача доступа посторонним лицам.',
                ]],
                ['title' => '6. Доступность и изменения', 'paragraphs' => [
                    'Сервис может временно ограничиваться для технических работ, защиты пользователей или исполнения обязательных требований. Существенные изменения условий публикуются на сайте.',
                    'Функции, зависящие от Telegram или МАКС, могут меняться вслед за API, правилами и клиентами соответствующей платформы.',
                ]],
                ['title' => '7. Приостановка доступа', 'paragraphs' => [
                    'Доступ может быть ограничен при нарушении соглашения, попытке навредить сервису, жалобах на незаконный контент или обязательном требовании уполномоченного органа.',
                    'Пользователь может прекратить использование, остановив или заблокировав бота на используемой платформе, отключив его от каналов и обратившись с запросом на удаление связанных данных и привязки аккаунтов.',
                ]],
                ['title' => '8. Конфиденциальность', 'paragraphs' => [
                    'Обработка данных описана в политике конфиденциальности GitPosted.',
                ]],
                ['title' => '9. Связь', 'paragraphs' => [
                    'Владелец сервиса: владелец GitPosted. Контакт по вопросам работы сервиса и настоящего соглашения: ' . $supportHandle . '.',
                ]],
            ],
        ],
        'privacy' => [
            'title' => 'Политика конфиденциальности',
            'description' => 'Какие данные обрабатывает GitPosted, зачем они нужны и как пользователь может управлять ими.',
            'sections' => [
                ['title' => '1. Кто обрабатывает данные', 'paragraphs' => [
                    'Оператором данных является владелец сервиса GitPosted. Контакт для обращений по вопросам конфиденциальности: ' . $supportHandle . '.',
                    'Политика относится к ' . ($platforms !== [] ? implode(', ', $platforms) . ' и ' : '') . 'сайту ' . $siteHost . '.',
                ]],
                ['title' => '2. Какие данные могут обрабатываться', 'items' => [
                    'Telegram ID и МАКС ID, username, имя и иные технические сведения, переданные соответствующей платформой при взаимодействии с ботом.',
                    'Сведения о добровольной привязке аккаунтов, включая сопоставление Telegram ID и МАКС ID, временный одноразовый код и статус его использования.',
                    'Идентификаторы, названия и настройки подключённых каналов и чатов Telegram и МАКС, а также сведения о выданных боту правах.',
                    'Команды, нажатия кнопок и сообщения, направленные пользователем боту, когда они нужны для выполнения выбранного сценария.',
                    'Тексты публикаций, параметры расписания, платформенные идентификаторы медиа и вложений, кнопки, реакции, шаблоны и другие настройки постов.',
                    'Идентификаторы и статусы обработки событий webhook от Telegram и МАКС, технические журналы ошибок и данные безопасности, необходимые для защиты сервиса и административного входа.',
                ]],
                ['title' => '3. Цели обработки', 'items' => [
                    'Регистрация пользователя, связывание аккаунтов по его запросу и выполнение команд бота.',
                    'Публикация и управление материалами в выбранных каналах Telegram и МАКС.',
                    'Планирование задач и выполнение выбранных пользователем сценариев.',
                    'Приём и безопасная обработка событий webhook, ответы на обращения, предотвращение повторной обработки и злоупотреблений, диагностика ошибок.',
                ]],
                ['title' => '4. Основания обработки', 'paragraphs' => [
                    'Данные обрабатываются для исполнения пользовательского соглашения, по запросу пользователя, а также для обеспечения безопасности и выполнения обязательных требований.',
                    'Пользователь не должен передавать через бот лишние персональные данные третьих лиц.',
                ]],
                ['title' => '5. Передача и внешние сервисы', 'paragraphs' => [
                    'Для работы GitPosted данные технически передаются Telegram или МАКС в зависимости от выбранной пользователем платформы. Эти компании обрабатывают данные по собственным правилам.',
                    'GitPosted не продаёт пользовательские данные. Доступ может предоставляться подрядчику только в объёме, необходимом для эксплуатации и защиты сервиса, либо по обязательному законному требованию.',
                ]],
                ['title' => '6. Хранение и защита', 'paragraphs' => [
                    'Данные хранятся столько, сколько необходимо для работы активных функций, разрешения обращений и исполнения обязательных требований. Временные технические отметки удаляются по мере утраты необходимости.',
                    'Неиспользованный код привязки аккаунтов и служебная отметка обработки webhook хранятся ограниченное время. Связь между Telegram ID и МАКС ID сохраняется до прекращения использования связанной функции или обоснованного запроса пользователя, если более длительное хранение не требуется по закону.',
                    'Применяются разграничение доступа, защищённое соединение, хранение секретов вне публичной части сайта и другие разумные организационные и технические меры.',
                ]],
                ['title' => '7. Cookies и сайт', 'paragraphs' => [
                    'Публичная главная страница не использует рекламные и аналитические cookies. В закрытом разделе ' . gitpostedAppPath($config, '/control/') . ' применяется защищённая сессионная cookie для административной авторизации.',
                ]],
                ['title' => '8. Права пользователя', 'items' => [
                    'Запросить сведения о связанных с ним данных, их исправление или удаление в допустимом объёме.',
                    'Отключить бота от канала, прекратить использование сервиса, отозвать ранее выданные права Telegram или МАКС и запросить удаление привязки аккаунтов.',
                    'Обратиться по контакту ' . $supportHandle . '; для защиты аккаунта может потребоваться подтверждение Telegram ID, МАКС ID или владения соответствующим аккаунтом.',
                ]],
                ['title' => '9. Изменения политики', 'paragraphs' => [
                    'Новая редакция публикуется на этой странице с обновлённой датой. Продолжение использования после публикации означает ознакомление с актуальной редакцией.',
                ]],
            ],
        ],
    ];

    if (!isset($documents[$documentKey])) {
        http_response_code(404);
        exit;
    }
    $document = $documents[$documentKey];
    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'none'; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, follow');
    header('Cache-Control: public, max-age=300');
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= $escape($document['description']) ?>">
    <meta name="theme-color" content="#07111f">
    <link rel="stylesheet" href="<?= $legalPath('/assets/site.css') ?>">
    <title><?= $escape($document['title']) ?> — GitPosted</title>
</head>
<body class="legal-page">
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="<?= $legalPath('/') ?>" aria-label="GitPosted — главная"><span class="brand-mark" aria-hidden="true">✓</span><span>GitPosted</span></a>
        <?php if ($botUrl !== null): ?><a class="button button-small" href="<?= htmlspecialchars($botUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Открыть бота</a><?php endif; ?>
    </div>
</header>
<main class="legal-main">
    <div class="wrap">
        <a class="legal-back" href="<?= $legalPath('/') ?>">← На главную</a>
        <div class="legal-heading">
            <p class="eyebrow dark"><span></span> Правовая информация</p>
            <h1><?= $escape($document['title']) ?></h1>
            <p><?= $escape($document['description']) ?></p>
            <span class="legal-date">Редакция от 1 августа 2026 года</span>
        </div>
        <article class="legal-card">
            <?php foreach ($document['sections'] as $section): ?>
                <section class="legal-section">
                    <h2><?= $escape($section['title']) ?></h2>
                    <?php foreach (($section['paragraphs'] ?? []) as $paragraph): ?>
                        <p><?= $escape($paragraph) ?></p>
                    <?php endforeach; ?>
                    <?php if (!empty($section['items'])): ?>
                        <ul>
                            <?php foreach ($section['items'] as $item): ?><li><?= $escape($item) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </article>
    </div>
</main>
<footer class="site-footer legal-footer">
    <div class="wrap footer-grid">
        <div><span class="brand"><span class="brand-mark" aria-hidden="true">✓</span><span>GitPosted</span></span><p>Боты для создания и планирования публикаций в Telegram и МАКС.</p></div>
        <nav aria-label="Правовая информация"><a href="<?= $legalPath('/user-agreement/') ?>">Пользовательское соглашение</a><a href="<?= $legalPath('/privacy/') ?>">Конфиденциальность</a></nav>
        <p class="footer-meta">Связь: <?= htmlspecialchars($supportHandle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>© 2026 GitPosted</p>
    </div>
</footer>
</body>
</html>
    <?php
    exit;
}
