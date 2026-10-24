<?php

declare(strict_types=1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';

if ($method === 'POST') {
    header('Location: /control/', true, 307);
    exit;
}
if ($requestPath === '/index.php') {
    header('Location: /control/', true, 308);
    exit;
}
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');

require __DIR__ . '/public-site.php';

$siteConfig = PostedConfig::get();
$siteBaseUrl = rtrim((string) ($siteConfig['base_url'] ?? 'https://example.com'), '/');
$publicBotLinks = gitpostedPublicBotLinks();
$telegramBotUrl = is_string($publicBotLinks['telegram'] ?? null)
    ? $publicBotLinks['telegram']
    : null;
$maxBotUrl = is_string($publicBotLinks['max'] ?? null)
    ? $publicBotLinks['max']
    : null;
$ctaPresentation = [
    'label' => 'Открыть бота',
    'note' => 'Выберите Telegram или МАКС.',
];
$projectRepository = 'https://github.com/SergeyFromMariupol/GitPosted';
$seoTitle = 'GitPosted — бесплатный бот для постинга в Telegram и МАКС';
$seoDescription = 'Бесплатная альтернатива Posted (@posted): отложенный постинг в Telegram и МАКС, серии, дубликаты, закреп и удаление. Открытый код на GitHub, установка на свой сервер без платы за каждый канал.';
$faqItems = [
    [
        'question' => 'GitPosted — бесплатная альтернатива Posted (@posted)?',
        'answer' => 'Да, если вам нужен бот для создания и отложенного постинга на своём сервере. GitPosted — самостоятельный проект с открытым кодом, не связанный с сервисом Posted. Доступны публикации в Telegram и МАКС, медиа, расписание, серии и репосты.',
    ],
    [
        'question' => 'За что нужно платить?',
        'answer' => 'Код GitPosted доступен бесплатно по лицензии MIT. Подписки на программу и лицензионной платы за каждый канал нет. При самостоятельной установке вы оплачиваете выбранный сервер и, при необходимости, домен. Стоимость зависит от вашего хостинга.',
    ],
    [
        'question' => 'Есть ли ограничения на каналы и публикации?',
        'answer' => 'В собственной установке нет лицензионной тарификации по числу каналов: вы сами управляете доступом и настройками. Производительность зависит от сервера; действуют технические ограничения проекта, Telegram и МАКС, включая размеры файлов, частоту запросов и права ботов.',
    ],
    [
        'question' => 'Можно пользоваться самому и делиться с друзьями?',
        'answer' => 'Да. Можно развернуть GitPosted для своих каналов, команды или друзей, изучать и изменять код с соблюдением лицензии MIT. В репозитории GitHub есть установщик, описание возможностей и инструкции по настройке собственных ботов.',
    ],
    [
        'question' => 'Как автоматически пересылать посты между Telegram и МАКС?',
        'answer' => 'Для автоматической пересылки новых публикаций из канала в канал есть отдельный бесплатный проект GitRePosted. GitPosted помогает подготовить и запланировать пост; GitRePosted передаёт новые публикации по настроенным маршрутам между Telegram и МАКС.',
    ],
];
$structuredData = json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            '@id' => $siteBaseUrl . '/#website',
            'url' => $siteBaseUrl . '/',
            'name' => 'GitPosted',
            'inLanguage' => 'ru-RU',
            'description' => $seoDescription,
        ],
        [
            '@type' => ['SoftwareApplication', 'SoftwareSourceCode'],
            '@id' => $siteBaseUrl . '/#software',
            'name' => 'GitPosted',
            'url' => $siteBaseUrl . '/',
            'description' => $seoDescription,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Ubuntu Server 24.04',
            'isAccessibleForFree' => true,
            'license' => $projectRepository . '/blob/main/LICENSE',
            'downloadUrl' => $projectRepository,
            'codeRepository' => $projectRepository,
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'RUB'],
            'featureList' => ['Отложенный постинг в Telegram и МАКС', 'Серии и дубликаты публикаций', 'Закреп и автоматическое удаление', 'Копии и репосты', 'Установка на свой сервер'],
        ],
        [
            '@type' => 'FAQPage',
            '@id' => $siteBaseUrl . '/#faq',
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $faqItems),
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$structuredDataHash = base64_encode(hash('sha256', $structuredData, true));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-" . $structuredDataHash . "'; style-src 'self' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'none'; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");

$publicStats = gitpostedPublicStats();
$statText = static fn (string $key): string => $publicStats['live'] && is_int($publicStats[$key])
    ? number_format($publicStats[$key], 0, '.', ' ')
    : '—';
$renderPlatformCta = static function (string $modifier = '') use (
    $ctaPresentation,
    $telegramBotUrl,
    $maxBotUrl
): void {
    $label = (string) $ctaPresentation['label'];
    $note = (string) $ctaPresentation['note'];
    ?>
    <div class="platform-cta <?= htmlspecialchars($modifier, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="platform-cta__copy">
            <strong class="platform-cta__label"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            <span class="platform-cta__note"><?= htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <div class="platform-cta__buttons" role="group" aria-label="<?= htmlspecialchars($label . ': выбор мессенджера', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if ($telegramBotUrl !== null): ?>
                <a class="platform-cta__button platform-cta__button_primary" href="<?= htmlspecialchars($telegramBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= htmlspecialchars($label . ' в Telegram', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Telegram</a>
            <?php else: ?>
                <span class="platform-cta__button platform-cta__button_disabled" aria-disabled="true">Telegram недоступен</span>
            <?php endif; ?>
            <?php if ($maxBotUrl !== null): ?>
                <a class="platform-cta__button platform-cta__button_secondary" href="<?= htmlspecialchars($maxBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= htmlspecialchars($label . ' в МАКС', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">МАКС</a>
            <?php else: ?>
                <span class="platform-cta__button platform-cta__button_disabled" aria-disabled="true">МАКС недоступен</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="theme-color" content="#1c1c1e">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($siteBaseUrl . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($siteBaseUrl . '/assets/promo/og.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta property="og:image:width" content="1536">
    <meta property="og:image:height" content="1024">
    <meta property="og:image:alt" content="GitPosted — бесплатный постинг в Telegram и МАКС с открытым кодом">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($siteBaseUrl . '/assets/promo/og.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <link rel="canonical" href="<?= htmlspecialchars($siteBaseUrl . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <link rel="stylesheet" href="/assets/promo/styles.css?v=20261024-open-source">
    <title><?= htmlspecialchars($seoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <script type="application/ld+json"><?= $structuredData ?></script>
</head>
<body>

<div class="burger" aria-hidden="true">
    <div class="burger__back"></div>
    <div class="burger-menu" role="dialog" aria-label="Навигация">
        <button class="burger-menu__cross" type="button" aria-label="Закрыть меню">
            <img src="/assets/promo/cross.svg" alt="">
        </button>
        <div class="burger-menu__links">
            <a href="#about-bot" class="burger-menu__link">О боте</a>
            <a href="#triggers" class="burger-menu__link">Наши преимущества</a>
            <a href="#free" class="burger-menu__link">Бесплатно</a>
            <a href="#opinions" class="burger-menu__link">Возможности</a>
            <a href="#faq" class="burger-menu__link">Вопросы и ответы</a>
            <a href="<?= htmlspecialchars($projectRepository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="burger-menu__link" target="_blank" rel="noopener noreferrer">GitHub и установка</a>
        </div>
    </div>
</div>

<header class="header">
    <img src="/assets/promo/image.svg" alt="" class="header__image">
    <div class="container container_header">
        <nav class="nav" aria-label="Основная навигация">
            <a class="nav__brand" href="/" aria-label="GitPosted — главная">
                <span class="nav__brand-mark" aria-hidden="true">✓</span>
                <span>GitPosted</span>
            </a>
            <div class="nav__menu footer__menu">
                <a href="#about-bot" class="footer__anchor">О боте</a>
                <a href="#triggers" class="footer__anchor">Наши преимущества</a>
                <a href="#free" class="footer__anchor">Бесплатно</a>
                <a href="#opinions" class="footer__anchor">Возможности</a>
            </div>
            <div class="footer__links">
                <?php if ($telegramBotUrl !== null): ?>
                    <a href="<?= htmlspecialchars($telegramBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="footer__link nav__link">Мы в Telegram</a>
                    <a href="<?= htmlspecialchars($telegramBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="footer__link" aria-label="Открыть GitPosted в Telegram">
                        <img src="/assets/promo/02.svg" alt="">
                    </a>
                <?php endif; ?>
                <?php if ($maxBotUrl !== null): ?>
                    <a href="<?= htmlspecialchars($maxBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="footer__link nav__link">Мы в МАКС</a>
                <?php endif; ?>
            </div>
            <button class="nav__burger" type="button" aria-label="Открыть меню" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </nav>

        <div class="header__content">
            <p class="project-eyebrow">Открытый код · Свой сервер · Без платы за каждый канал</p>
            <h1 class="header__title">Бесплатный бот для постинга в Telegram и МАКС</h1>
            <p class="header__text">
                <?php if ($telegramBotUrl !== null): ?>
                    <a href="<?= htmlspecialchars($telegramBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">GitPosted</a>
                <?php else: ?>
                    <span>GitPosted</span>
                <?php endif; ?>
                —
                создавайте посты, планируйте время выхода, серии, закреп и удаление.
                Установите проект на свой сервер для себя и друзей. Код бесплатный;
                сервер и необязательный домен вы оплачиваете самостоятельно.
            </p>
            <div class="project-actions">
                <a class="project-button" href="<?= htmlspecialchars($projectRepository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Скачать и установить с GitHub <span aria-hidden="true">↗</span></a>
                <a class="project-text-link" href="#free">Как это работает</a>
            </div>
            <?php $renderPlatformCta('platform-cta_header'); ?>
        </div>
        <span id="about-bot" class="anchor-target"></span>
    </div>
</header>

<main>
<section class="about-us">
    <div class="container">
        <h2 class="about-us__title">О боте</h2>
        <div class="about-us__blocks">
            <div class="about-us-block">
                <img src="/assets/promo/01.svg" alt="" class="about-us-block__image">
                <p class="about-us-block__text">Упрощает ведение канала</p>
            </div>
            <div class="about-us-block">
                <img src="/assets/promo/02(1).svg" alt="" class="about-us-block__image">
                <p class="about-us-block__text">Автоматически выкладывает и удаляет публикации в нужное время</p>
            </div>
            <div class="about-us-block">
                <img src="/assets/promo/03.svg" alt="" class="about-us-block__image">
                <p class="about-us-block__text">Позволяет создавать уникальный контент</p>
            </div>
            <div class="about-us-block">
                <img src="/assets/promo/04.svg" alt="" class="about-us-block__image">
                <p class="about-us-block__text">Дает возможность назначить редакторов для канала</p>
            </div>
        </div>
    </div>
    <span id="triggers" class="anchor-target"></span>
</section>

<section class="triggers">
    <div class="container">
        <h2 class="triggers__title">Преимущества бота</h2>
        <div class="triggers__blocks">
            <img class="triggers__line" src="/assets/promo/line.svg" alt="">
            <div class="triggers__content">
                <div class="trigger trigger_lower">
                    <img src="/assets/promo/01(1).svg" alt="" class="trigger__image">
                    <h3 class="trigger__title">Простое подключение</h3>
                    <p class="trigger__text">Добавьте бота администратором канала и продолжайте настройку прямо в Telegram или МАКС.</p>
                </div>
                <div class="trigger">
                    <img src="/assets/promo/02(2).svg" alt="" class="trigger__image">
                    <h3 class="trigger__title">Поддержка анимированных эмодзи</h3>
                    <p class="trigger__text">В Telegram используйте анимированные эмодзи в тексте и кнопках; в МАКС — нативные цветные кнопки.</p>
                </div>
                <div class="trigger">
                    <img src="/assets/promo/03(1).svg" alt="" class="trigger__image">
                    <h3 class="trigger__title">Поддержка лонгридов</h3>
                    <p class="trigger__text">Создавайте длинные публикации, добавляйте фото, видео, кнопки, реакции и скрытое продолжение.</p>
                </div>
                <div class="trigger trigger_lower">
                    <img src="/assets/promo/04(1).svg" alt="" class="trigger__image">
                    <h3 class="trigger__title">Публикация по расписанию</h3>
                    <p class="trigger__text">Настройте время выхода, автоматическое удаление и повтор публикации заранее.</p>
                </div>
            </div>
        </div>
        <?php $renderPlatformCta('platform-cta_center platform-cta_section'); ?>
    </div>
</section>

<span id="free" class="anchor-target"></span>
<section class="free-access">
    <div class="container free-access__container">
        <div class="free-access__copy">
            <div class="free-access__kicker">Бесплатный проект по лицензии MIT</div>
            <h2 class="free-access__title">Один сервер.<br>Ваши <span>каналы.</span></h2>
            <p class="free-access__text">GitPosted не требует подписки на программу или лицензионной оплаты за каждый канал. Размещайте ботов на своём сервере и управляйте публикациями для себя, команды и друзей.</p>
            <p class="free-access__text">На GitHub опубликованы исходный код, установщик для чистого Ubuntu Server, описание возможностей и инструкции для собственных Telegram- и МАКС-ботов. Оплачивается выбранный вами хостинг; ресурсы сервера и ограничения платформ учитываются при работе.</p>
            <a class="project-text-link" href="<?= htmlspecialchars($projectRepository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Исходники и инструкция на GitHub ↗</a>
        </div>
        <?php $renderPlatformCta('platform-cta_free'); ?>
    </div>
</section>

<section class="stats">
    <div class="container">
        <h2 class="stats__title">С нами работают</h2>
        <div class="stats__blocks">
            <div class="stats__block stats__block_top">
                <p><strong><?= htmlspecialchars($statText('users'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> пользователей GitPosted</p>
            </div>
            <div class="stats__block stats__block_top">
                <p><strong><?= htmlspecialchars($statText('channels'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> подключенных Telegram- и МАКС-каналов</p>
            </div>
            <div class="stats__block">
                <p><strong><?= htmlspecialchars($statText('posts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> опубликованных и запланированных постов</p>
            </div>
        </div>
    </div>
    <span id="opinions" class="anchor-target"></span>
</section>

<section class="opinions">
    <div class="container container_opinions">
        <div class="opinions__inner">
            <h2 class="opinions__title">Возможности GitPosted</h2>
            <article class="opinion">
                <div class="opinion-author">
                    <img src="/assets/promo/01.svg" alt="" class="opinion-author__image opinion-author__icon">
                    <div class="opinion-author__text">
                        <h3 class="opinion-author__nick">Контент-план</h3>
                        <span class="opinion-author__link">Публикации по расписанию</span>
                    </div>
                </div>
                <p class="opinion__text">Подготовьте публикации на неделю или месяц вперёд. Задайте период серии, шаг или отдельные даты; создайте дубликаты с собственным временем, закрепом и удалением.</p>
            </article>
            <article class="opinion">
                <div class="opinion-author">
                    <img src="/assets/promo/02(2).svg" alt="" class="opinion-author__image opinion-author__icon">
                    <div class="opinion-author__text">
                        <h3 class="opinion-author__nick">Анимированные эмодзи</h3>
                        <span class="opinion-author__link">Оформление текста и кнопок</span>
                    </div>
                </div>
                <p class="opinion__text">Добавляйте анимированные эмодзи в Telegram-публикации и подписи кнопок, сохраняйте оформление постов.</p>
            </article>
        </div>
        <div class="opinions__inner">
            <article class="opinion">
                <div class="opinion-author">
                    <img src="/assets/promo/02(1).svg" alt="" class="opinion-author__image opinion-author__icon">
                    <div class="opinion-author__text">
                        <h3 class="opinion-author__nick">Автоматическое удаление</h3>
                        <span class="opinion-author__link">Жизненный цикл поста</span>
                    </div>
                </div>
                <p class="opinion__text">Заранее укажите, когда публикацию нужно убрать из канала, чтобы не ставить отдельные напоминания.</p>
            </article>
            <article class="opinion">
                <div class="opinion-author">
                    <img src="/assets/promo/03.svg" alt="" class="opinion-author__image opinion-author__icon">
                    <div class="opinion-author__text">
                        <h3 class="opinion-author__nick">Медиа, реакции и URL-кнопки</h3>
                        <span class="opinion-author__link">Полное оформление поста</span>
                    </div>
                </div>
                <p class="opinion__text">Соберите текст, фото или видео, добавьте нужные ссылки, реакции и скрытое продолжение в одном сценарии.</p>
            </article>
            <article class="opinion">
                <div class="opinion-author">
                    <img src="/assets/promo/04.svg" alt="" class="opinion-author__image opinion-author__icon">
                    <div class="opinion-author__text">
                        <h3 class="opinion-author__nick">Каналы и редакторы</h3>
                        <span class="opinion-author__link">Совместная работа</span>
                    </div>
                </div>
                <p class="opinion__text">Подключайте несколько каналов, назначайте редакторов и используйте шаблоны для регулярного контента.</p>
            </article>
        </div>
    </div>
</section>

<section class="project-family" aria-labelledby="family-title">
    <div class="container">
        <p class="project-eyebrow">Два бесплатных проекта</p>
        <h2 id="family-title" class="project-section-title">Планируйте здесь.<br>Пересылайте с GitRePosted.</h2>
        <div class="project-cards">
            <article class="project-card">
                <span class="project-card__tag">Подготовка и расписание</span>
                <h3>GitPosted</h3>
                <p>Создавайте и оформляйте посты для Telegram и МАКС. Планируйте разовые публикации, серии и дубликаты, настраивайте закреп, удаление и режим копии или репоста.</p>
                <a class="project-text-link" href="<?= htmlspecialchars($projectRepository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">GitPosted на GitHub ↗</a>
            </article>
            <article class="project-card">
                <span class="project-card__tag">Автоматическая пересылка</span>
                <h3>GitRePosted</h3>
                <p>Передавайте новые публикации из канала в канал по своим маршрутам между Telegram и МАКС. Подключайте собственных ботов и выбирайте доступные вам источники и получателей.</p>
                <div class="project-card__links"><a class="project-text-link" href="https://gitreposted.ru/">Подробнее о GitRePosted ↗</a><a class="project-text-link" href="https://github.com/SergeyFromMariupol/GitRePosted" target="_blank" rel="noopener noreferrer">GitHub ↗</a></div>
            </article>
        </div>
    </div>
</section>

<section class="project-faq" id="faq" aria-labelledby="faq-title">
    <div class="container">
        <p class="project-eyebrow">О бесплатном постинге</p>
        <h2 id="faq-title" class="project-section-title">Вопросы перед установкой</h2>
        <div class="project-faq__items">
            <?php foreach ($faqItems as $item): ?>
                <details class="project-faq__item">
                    <summary><?= htmlspecialchars($item['question'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></summary>
                    <p><?= htmlspecialchars($item['answer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="start">
    <div class="container">
        <h2 class="start__title">Ваши боты.<br>Ваш сервер. Ваши публикации.</h2>
        <div class="project-actions project-actions_center"><a class="project-button" href="<?= htmlspecialchars($projectRepository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Установить GitPosted с GitHub ↗</a></div>
        <?php $renderPlatformCta('platform-cta_center platform-cta_final'); ?>
    </div>
</section>
</main>

<footer class="footer">
    <div class="container container_footer">
        <div class="footer__menu">
            <a href="#about-bot" class="footer__anchor">О боте</a>
            <a href="#triggers" class="footer__anchor">Наши преимущества</a>
            <a href="#free" class="footer__anchor">Бесплатно</a>
            <a href="#opinions" class="footer__anchor">Возможности</a>
        </div>
        <div class="footer__links footer__links_legal">
            <a href="<?= htmlspecialchars($projectRepository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="footer__link">GitHub</a>
            <a href="#faq" class="footer__link">Вопросы и ответы</a>
            <a href="/user-agreement/" class="footer__link">Соглашение</a>
            <a href="/privacy/" class="footer__link">Конфиденциальность</a>
            <?php if ($maxBotUrl !== null): ?>
                <a href="<?= htmlspecialchars($maxBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="footer__link">GitPosted в МАКС</a>
            <?php endif; ?>
            <?php if ($telegramBotUrl !== null): ?>
                <a href="<?= htmlspecialchars($telegramBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="footer__link">Поддержка</a>
            <?php endif; ?>
        </div>
    </div>
</footer>

<script src="/assets/promo/app.js" defer></script>
</body>
</html>
