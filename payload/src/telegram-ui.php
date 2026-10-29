<?php

declare(strict_types=1);

/** Explicit service UI strings; publication content never passes through this catalog. */
final class PostedTelegramUi
{
    private const EN_HTML = [
        'main.body' => "<tg-emoji emoji-id=\"5431446665070415240\">✅</tg-emoji> <b>{brand}</b> is a simple bot for posting that supports working with <tg-emoji emoji-id=\"5258165702707125574\">⭐️</tg-emoji> <b>custom emoji</b>.\n\n<b>The bot gives you ability to: </b>\n\n<tg-emoji emoji-id=\"5258419835922030550\">🕔</tg-emoji> Schedule posts in your channels\n<tg-emoji emoji-id=\"5258130763148172425\">🗑</tg-emoji> Automatically delete them on a timer\n<tg-emoji emoji-id=\"5258215635996908355\">👩‍🎨</tg-emoji> Create and customize posts of any format\n<tg-emoji emoji-id=\"5258420634785947640\">🔄</tg-emoji> Loop posts, add buttons and watermarks\n<tg-emoji emoji-id=\"5260341314095947411\">👀</tg-emoji> And more",
        'main.news' => "<b>Service News -</b> {news}",
        'main.support' => "<b>Support -</b> {support}",
        'help.body' => "<tg-emoji emoji-id=\"5258503720928288433\">ℹ️</tg-emoji> <b>HELP</b>\n    \nIf you have any questions about the service or you find an error, write to us in support {support}.",
        'help.news' => "{news} - the news channel of the service, in which we talk about all the new products of the service and post useful materials.",
        'language.choose' => "<tg-emoji emoji-id=\"5260512129240276089\">📚</tg-emoji> Choose your language.",
        'settings.body' => "<tg-emoji emoji-id=\"5258096772776991776\">⚙️</tg-emoji> <b>SETTINGS</b>\n\nIn this section, you can set up work with the bot, with a separate channel, as well as add a new channel to {brand}.",
        'settings.timezone' => "Timezone: {timezone} ({time})",
        'create.body' => "<tg-emoji emoji-id=\"5260652420052032852\">⬆️</tg-emoji> <b>CREATING A POST</b>\n\nSelect the channel you want to post in.",
        'edit.body' => "<tg-emoji emoji-id=\"5258331647358540449\">✍️</tg-emoji> <b>EDIT POST</b>\n\nForward post you want to edit from your channel.",
        'content.body' => "<tg-emoji emoji-id=\"5258105663359294787\">🗓</tg-emoji> <b>CONTENT PLAN</b>\n\nIn this section, you can view and edit scheduled posts. Select the channel in which you want to see the content plan.",
        'templates.body' => "<tg-emoji emoji-id=\"5296348778012361146\">🏷</tg-emoji> <b>TEMPLATES</b>\n\nSave templates or promotional creatives. For templates, you can replace links with one click and then forward.\n\nSelect which channel you want to view templates for, or click <b>«Common»</b>.",
        'catalog.title' => "<tg-emoji emoji-id=\"5258328383183396223\">📖</tg-emoji> <b>EMOJI CATALOG</b>",
        'catalog.open' => "Open",
        'button.add_channel' => "➕ Add new channel",
        'button.advanced' => "Advanced settings",
        'button.cancel' => "← Cancel",
        'button.common' => "Common",
        'catalog.more' => "↓ More filters",
        'catalog.quality' => "High quality",
        'catalog.fonts' => "Fonts",
        'catalog.icons' => "Icons",
        'catalog.brands' => "Brands",
        'catalog.statuses' => "Statuses",
        'catalog.memes' => "Memes",
    ];

    private const MAIN_BUTTONS = [
        ['Создать пост', 'Create post'], ['Изменить пост', 'Edit post'],
        ['Контент-план', 'Content plan'], ['Настройки', 'Settings'],
        ['Шаблоны', 'Templates'], ['Каталог эмодзи', 'Emoji catalog'],
    ];

    public static function helpTopics(): array
    {
        return [
            'connect-channel'=>['title'=>'Как подключить бота к каналу?', 'paragraphs'=>[
                'Добавьте своего бота администратором канала и разрешите публикацию, редактирование и удаление сообщений.',
                'В боте откройте «Настройки → Добавить новый канал» и отправьте @username, публичную ссылку, числовой ID канала или пересланное из него сообщение. Для закрытого канала используйте ID или пересылку.',
            ]],
            'create-post'=>['title'=>'Как создать и опубликовать пост?', 'paragraphs'=>[
                'Нажмите «Создать пост», выберите канал и отправьте текст, фото, видео, документ или альбом. Можно также переслать сообщение или импортировать пост по ссылке.',
                'Проверьте предпросмотр, настройте публикацию и нажмите «Далее». Выберите публикацию сейчас или отложите пост на нужное время.',
            ]],
            'content-plan'=>['title'=>'Работа с контент-планом', 'paragraphs'=>[
                'Откройте «Контент-план», выберите канал и дату. Нажмите на публикацию, чтобы увидеть её состояние и доступные действия.',
                'В карточке можно изменить дату, настроить удаление и повтор, сделать дубликат или отменить публикацию. Дубликаты и экземпляры серии имеют отдельные настройки.',
            ]],
            'replace-links'=>['title'=>'Замена ссылок', 'paragraphs'=>[
                'При создании поста со ссылками нажмите «Замена ссылок» и отправьте новую ссылку, начинающуюся с https:// или http://.',
                'Бот применит выбранную ссылку к ссылкам публикации. Проверьте текст и кнопки в предпросмотре перед отправкой. Для изменения нативного репоста сначала явно выберите режим копии.',
            ]],
            'edit-post'=>['title'=>'Изменение поста', 'paragraphs'=>[
                'Нажмите «Изменить пост» и перешлите из своего канала публикацию, которую нужно изменить. Выберите нужное действие в открывшемся редакторе.',
                'После правок проверьте результат и сохраните изменения. Редактирование доступно в пределах ваших прав и возможностей Telegram; нативный репост нельзя незаметно превратить в копию.',
            ]],
            'emoji-catalog'=>['title'=>'Каталог эмодзи', 'paragraphs'=>[
                'Откройте «Каталог эмодзи», выберите категорию и листайте страницы. Ссылка «Открыть» ведёт к соответствующему набору в Telegram.',
                'Добавленные наборы можно использовать при подготовке текста поста. Команда /addemoji позволяет предложить свой набор в общий каталог.',
            ]],
            'channel-settings'=>['title'=>'Настройки канала', 'paragraphs'=>[
                'Откройте «Настройки» и нажмите на канал. В его карточке доступны расписание, параметры обычных и рекламных постов, редакторы и рекламные менеджеры.',
                'Настройки и доступы меняет владелец канала в GitPosted. Права самого бота на публикацию, редактирование и удаление проверяйте отдельно в Telegram.',
            ]],
            'schedule'=>['title'=>'Расписание', 'paragraphs'=>[
                'В настройках канала откройте «Расписание» и отправьте список ежедневных времён, например 09:00 и 18:30, каждое с новой строки.',
                'При отложенной публикации эти времена появятся как готовые варианты. Выберите дату и подходящее время; само расписание не создаёт посты без вашего выбора.',
            ]],
            'timezone'=>['title'=>'Часовой пояс', 'paragraphs'=>[
                'Откройте «Настройки → Часовой пояс» и выберите свой регион.',
                'Бот показывает даты публикаций и принимает новое время в выбранном часовом поясе. Смена отображения не переносит уже запланированные посты на другой момент.',
            ]],
            'post-types'=>['title'=>'Обычные посты и рекламные', 'paragraphs'=>[
                'У канала есть отдельные настройки обычных и рекламных публикаций: подпись, уведомление, закреп и доступные сроки удаления.',
                'Чтобы применить рекламные настройки, отметьте «Это рекламный пост», когда эта кнопка доступна в редакторе. Рекламные менеджеры работают в пределах выданных владельцем прав и квот.',
            ]],
            'publication-settings'=>['title'=>'Как настроить пост перед публикацией?', 'paragraphs'=>[
                'В редакторе проверьте текст или описание, медиа, уведомление, кнопки и закреп. Для постов со ссылками доступны настройки превью и замена ссылок.',
                'Кнопка «Ответный пост» позволяет выбрать сообщение того же канала для ответа. После изменения параметров снова проверьте предпросмотр и нажмите «Далее».',
            ]],
            'schedule-post'=>['title'=>'Как отложить пост к нужному времени?', 'paragraphs'=>[
                'Подготовьте пост, нажмите «Далее» и выберите отложенную отправку. Укажите дату и время в своём часовом поясе или используйте календарь и времена расписания.',
                'Проверьте выбранный момент и подтвердите планирование, если бот запрашивает подтверждение. Готовый пост можно найти и перенести в контент-плане.',
            ]],
            'delete-repeat'=>['title'=>'Как настроить таймер удаления и автоповтор поста?', 'paragraphs'=>[
                'В настройках отправки или карточке поста откройте «Таймер удаления» либо «Автоповтор» и выберите нужный срок или интервал.',
                'Автоудаление Telegram ограничено сроком раньше 48 часов. «≈48 ч» означает 47 ч 55 мин; большие сроки помечены «вручную» и служат напоминанием. Автоповтор и удаление настраиваются отдельно.',
            ]],
            'channel-folders'=>['title'=>'Папки каналов', 'paragraphs'=>[
                'Откройте «Настройки → Продвинутые настройки → Папки». Создайте папку, задайте название и добавьте доступные вам каналы.',
                'Папки помогают выбирать каналы при создании поста. Добавление канала в папку не меняет его владельца, права участников или запланированные публикации.',
            ]],
        ];
    }

    public static function helpLinks(string $baseUrl): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $baseUrl)
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Configured help URL is invalid.');
        }
        $links=[];
        foreach (self::helpTopics() as $anchor=>$topic) {
            $links[]=['title'=>$topic['title'], 'url'=>$baseUrl.'/?help=1#'.$anchor];
        }
        return $links;
    }

    public static function language(PDO $pdo, int $userId): string
    {
        $statement = $pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1');
        $statement->execute(['key'=>self::languageKey($userId)]);
        $stored = json_decode((string) ($statement->fetchColumn() ?: ''), true);
        return is_array($stored) && ($stored['language'] ?? null) === 'en' ? 'en' : 'ru';
    }

    public static function saveLanguage(PDO $pdo, int $userId, string $language): void
    {
        if (!in_array($language, ['ru', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported Telegram UI language.');
        }
        $value = json_encode(['version'=>1, 'language'=>$language], JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute(['key'=>self::languageKey($userId), 'value'=>$value]);
    }

    private static function languageKey(int $userId): string
    {
        if ($userId <= 0) throw new InvalidArgumentException('Invalid Telegram UI user.');
        return 'telegram_ui_language_' . $userId;
    }

    public static function englishHtml(string $key, array $parameters = []): string
    {
        if (!isset(self::EN_HTML[$key])) throw new InvalidArgumentException('Unknown Telegram UI text.');
        $template = self::EN_HTML[$key];
        preg_match_all('/\{([a-z_]+)\}/', $template, $matches);
        $values = [];
        foreach (array_unique($matches[1]) as $parameter) {
            if (!array_key_exists($parameter, $parameters) || !is_string($parameters[$parameter])) {
                throw new InvalidArgumentException('Missing Telegram UI parameter.');
            }
            $values['{' . $parameter . '}'] = htmlspecialchars($parameters[$parameter], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return strtr($template, $values);
    }

    public static function mainKeyboard(array $keyboard, string $language, bool $includeIcons = true): array
    {
        if(!$includeIcons)foreach($keyboard as&$row){foreach($row as&$button)unset($button['icon_custom_emoji_id']);unset($button);}unset($row);
        if ($language !== 'en') return $keyboard;
        foreach (self::MAIN_BUTTONS as $index => [$russian, $english]) {
            $row = intdiv($index, 2); $column = $index % 2;
            if (($keyboard[$row][$column]['text'] ?? null) === $russian) {
                $keyboard[$row][$column]['text'] = $english;
            }
        }
        return $keyboard;
    }

    public static function mainButtonAction(string $text): string
    {
        foreach (self::MAIN_BUTTONS as [$russian, $english]) {
            if ($text === $english) return $russian;
        }
        return $text;
    }
}
