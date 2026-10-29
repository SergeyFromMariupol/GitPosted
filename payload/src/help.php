<?php
declare(strict_types=1);

require_once __DIR__ . '/telegram-ui.php';

$helpMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($helpMethod, ['GET', 'HEAD'], true)) {
    if (!headers_sent()) header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
}
if ($helpMethod === 'HEAD') return;
$helpTopics = PostedTelegramUi::helpTopics();
$helpEscape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Помощь · GitPosted</title>
    <meta name="description" content="Как подключить канал, создать и запланировать пост, настроить удаление и автоповтор в GitPosted.">
    <style>
        :root{color-scheme:dark light;font:16px/1.65 system-ui,-apple-system,"Segoe UI",sans-serif;scroll-behavior:smooth}
        *{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#1c2638}a{color:#2455c7;text-underline-offset:.2em}a:hover{color:#123a9a}
        header{padding:2rem max(1.2rem,calc((100vw - 1160px)/2));background:#172132;color:#f7f9ff}header a{color:#b9d0ff}h1{margin:.5rem 0;font-size:2rem}header p{max-width:48rem;margin:.5rem 0}
        main{max-width:1200px;margin:auto;padding:1.5rem;display:grid;grid-template-columns:minmax(220px,290px) minmax(0,1fr);gap:1.5rem;align-items:start}
        nav,.articles{background:white;border:1px solid #dce2ec;border-radius:16px;padding:1.5rem}nav{position:sticky;top:1rem}nav h2{font-size:1rem;margin:0 0 .75rem}nav ol{margin:0;padding-left:1.3rem}nav li{margin:.5rem 0}
        section{scroll-margin-top:1.5rem;padding:0 0 1.4rem;margin:0 0 1.4rem;border-bottom:1px solid #e3e7ef}section:last-child{border:0;margin:0;padding:0}h2{font-size:1.25rem;line-height:1.4;margin:0 0 .8rem}p{margin:.6rem 0}.top{font-size:.9rem;display:inline-block;margin-top:.25rem}
        @media(max-width:820px){main{grid-template-columns:1fr;padding:1rem}nav{position:static}nav,.articles{padding:1.2rem}header{padding:1.5rem 1.2rem}h1{font-size:1.7rem}}
        @media(prefers-color-scheme:dark){body{background:#0f1723;color:#e6edf7}nav,.articles{background:#172132;border-color:#2b394e}a{color:#a9c5ff}a:hover{color:#d0e0ff}section{border-color:#2b394e}}
        @media(prefers-reduced-motion:reduce){:root{scroll-behavior:auto}}
    </style>
</head>
<body>
<header id="top">
    <a href="./">GitPosted</a>
    <h1>Помощь</h1>
    <p>Короткие инструкции по работе с Telegram-ботом GitPosted. Выберите раздел или откройте его по ссылке из команды /help.</p>
</header>
<main>
    <nav aria-label="Разделы помощи">
        <h2>В этой инструкции</h2>
        <ol>
            <?php foreach ($helpTopics as $anchor=>$topic): ?>
                <li><a href="#<?= $helpEscape($anchor) ?>"><?= $helpEscape($topic['title']) ?></a></li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <article class="articles" aria-label="Инструкции GitPosted">
        <?php foreach ($helpTopics as $anchor=>$topic): ?>
            <section id="<?= $helpEscape($anchor) ?>">
                <h2><?= $helpEscape($topic['title']) ?></h2>
                <?php foreach ($topic['paragraphs'] as $paragraph): ?>
                    <p><?= $helpEscape($paragraph) ?></p>
                <?php endforeach; ?>
                <a class="top" href="#top">К разделам ↑</a>
            </section>
        <?php endforeach; ?>
    </article>
</main>
</body>
</html>
