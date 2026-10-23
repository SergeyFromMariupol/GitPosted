<?php

declare(strict_types=1);

/** Fixed command menus shared by initial installation and later attachment. */
function gitpostedTelegramCommandSets(): array
{
    return [
        'default' => [
            ['command' => 'start', 'description' => 'Перезапустить бота'],
            ['command' => 'create', 'description' => 'Создать пост'],
            ['command' => 'help', 'description' => 'Помощь'],
            ['command' => 'lang', 'description' => 'Сменить язык'],
            ['command' => 'edit', 'description' => 'Изменить пост'],
            ['command' => 'content', 'description' => 'Контент-план'],
            ['command' => 'templates', 'description' => 'Шаблоны'],
            ['command' => 'settings', 'description' => 'Настройки'],
            ['command' => 'catalog', 'description' => 'Каталог эмодзи'],
            ['command' => 'addemoji', 'description' => 'Добавить набор эмодзи'],
            ['command' => 'support', 'description' => 'Поддержка'],
            ['command' => 'privacy', 'description' => 'Политика конфиденциальности'],
            ['command' => 'maxlink', 'description' => 'Связать аккаунт МАКС'],
        ],
        'ru' => [
            ['command' => 'start', 'description' => 'Перезапустить бота'],
            ['command' => 'create', 'description' => 'Создать пост'],
            ['command' => 'help', 'description' => 'Помощь'],
            ['command' => 'lang', 'description' => 'Сменить язык'],
            ['command' => 'edit', 'description' => 'Изменить пост'],
            ['command' => 'content', 'description' => 'Контент-план'],
            ['command' => 'templates', 'description' => 'Шаблоны'],
            ['command' => 'settings', 'description' => 'Настройки'],
            ['command' => 'catalog', 'description' => 'Каталог эмодзи'],
            ['command' => 'addemoji', 'description' => 'Добавить набор эмодзи'],
            ['command' => 'support', 'description' => 'Поддержка'],
            ['command' => 'privacy', 'description' => 'Политика конфиденциальности'],
            ['command' => 'maxlink', 'description' => 'Связать аккаунт МАКС'],
        ],
        'en' => [
            ['command' => 'start', 'description' => 'Restart bot'],
            ['command' => 'create', 'description' => 'Create post'],
            ['command' => 'help', 'description' => 'Help'],
            ['command' => 'lang', 'description' => 'Change language'],
            ['command' => 'edit', 'description' => 'Edit post'],
            ['command' => 'content', 'description' => 'Content plan'],
            ['command' => 'templates', 'description' => 'Templates'],
            ['command' => 'settings', 'description' => 'Settings'],
            ['command' => 'catalog', 'description' => 'Emoji catalog'],
            ['command' => 'addemoji', 'description' => 'Add emoji set'],
            ['command' => 'support', 'description' => 'Support'],
            ['command' => 'privacy', 'description' => 'Privacy policy'],
            ['command' => 'maxlink', 'description' => 'Link МАКС account'],
        ],
        'uk' => [
            ['command' => 'start', 'description' => 'Перезапустити бота'],
            ['command' => 'create', 'description' => 'Створити допис'],
            ['command' => 'help', 'description' => 'Допомога'],
            ['command' => 'lang', 'description' => 'Змінити мову'],
            ['command' => 'edit', 'description' => 'Змінити допис'],
            ['command' => 'content', 'description' => 'Контент-план'],
            ['command' => 'templates', 'description' => 'Шаблони'],
            ['command' => 'settings', 'description' => 'Налаштування'],
            ['command' => 'catalog', 'description' => 'Каталог емодзі'],
            ['command' => 'addemoji', 'description' => 'Додати набір емодзі'],
            ['command' => 'support', 'description' => 'Підтримка'],
            ['command' => 'privacy', 'description' => 'Політика конфіденційності'],
            ['command' => 'maxlink', 'description' => 'Пов’язати акаунт МАКС'],
        ],
    ];
}

function gitpostedMaxCommands(): array
{
    return [
        ['name' => 'start', 'description' => 'Запустить GitPosted'],
        ['name' => 'menu', 'description' => 'Открыть главное меню'],
        ['name' => 'help', 'description' => 'Открыть справку'],
        ['name' => 'cancel', 'description' => 'Отменить текущее действие'],
    ];
}
