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
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'none'; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
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
    'label' => 'Начать бесплатно',
    'note' => 'Все возможности доступны бесплатно.',
];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="description" content="GitPosted — бот для создания, оформления и отложенной публикации постов в Telegram и МАКС с медиа, кнопками и автоматизацией.">
    <meta name="theme-color" content="#1c1c1e">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:title" content="GitPosted | Бот для постинга">
    <meta property="og:description" content="Создавайте публикации и выпускайте их в Telegram и МАКС точно по расписанию.">
    <meta property="og:url" content="<?= htmlspecialchars($siteBaseUrl . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($siteBaseUrl . '/assets/promo/og.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta property="og:image:width" content="1536">
    <meta property="og:image:height" content="1024">
    <meta property="og:image:alt" content="GitPosted — бот для отложенного постинга в Telegram и МАКС">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="GitPosted | Бот для постинга">
    <meta name="twitter:description" content="Создавайте публикации и выпускайте их в Telegram и МАКС точно по расписанию.">
    <meta name="twitter:image" content="<?= htmlspecialchars($siteBaseUrl . '/assets/promo/og.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <link rel="canonical" href="<?= htmlspecialchars($siteBaseUrl . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <link rel="stylesheet" href="/assets/promo/styles.css?v=20260801-1">
    <title>GitPosted | Бот для постинга</title>
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
            <h1 class="header__title">Бот для отложенного постинга в Telegram и МАКС</h1>
            <p class="header__text">
                <?php if ($telegramBotUrl !== null): ?>
                    <a href="<?= htmlspecialchars($telegramBotUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">GitPosted</a>
                <?php else: ?>
                    <span>GitPosted</span>
                <?php endif; ?>
                —
                удобный бот для двух платформ. Создавайте уникальный контент,
                публикуйте его точно в нужное время, а в Telegram используйте анимированные эмодзи.
            </p>
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
            <div class="free-access__kicker">Бесплатно для всех</div>
            <h2 class="free-access__title">Весь <span>GitPosted</span> без оплаты</h2>
            <p class="free-access__text">Все возможности Telegram- и МАКС-ботов доступны бесплатно: публикации, расписание, медиа, редакторы, повтор и автоматическое удаление.</p>
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
                <p class="opinion__text">Подготовьте публикации на неделю или месяц вперед. GitPosted выпустит каждую запись в выбранный день и время.</p>
            </article>
            <article class="opinion">
                <div class="opinion-author">
                    <img src="/assets/promo/02(2).svg" alt="" class="opinion-author__image opinion-author__icon">
                    <div class="opinion-author__text">
                        <h3 class="opinion-author__nick">Анимированные эмодзи</h3>
                        <span class="opinion-author__link">Оформление текста и кнопок</span>
                    </div>
                </div>
                <p class="opinion__text">Добавляйте анимированные эмодзи в публикации и в подпи кнопок, выбирайте их цвет и сохраняйте оформление Telegram.</p>
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

<section class="start">
    <div class="container">
        <h2 class="start__title">Начните работу с нами прямо сейчас</h2>
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
