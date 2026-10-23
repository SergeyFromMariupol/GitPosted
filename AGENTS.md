# GitPosted — инструкции для работы с дистрибутивом

Это публичный пакет чистой установки. Он не содержит рабочую БД, private config,
медиа, bot/user sessions или настройки конкретного сервера. Не смешивайте его с
состоянием существующей установки.

## Карта

- `bootstrap.sh` — вход полного release, проверка manifest и передача provisioner.
- `lib/provision.py` — аудит чистого Ubuntu 24.04, системные зависимости, nginx,
  PHP-FPM, TLS, пользователи и службы.
- `lib/broker.py` — временный root broker с фиксированными действиями и peer UID.
- `lib/Wizard.php`, `web/` — браузерный `/install/`; только ограниченные обращения
  к broker, без произвольных команд и записи privileged config из PHP.
- `bin/application.php` — bounded JSON CLI для схемы и API-проверок приложения.
- `payload/src/` — поставляемый PHP runtime и workers, а не config/data directory.
- `payload/resources/` — только public assets и CA.
- `scripts/build.py` — deterministic archive, allowlist и manifest verification.
- `scripts/release-loader.sh` — шаблон публичного `install.sh` с release URL/SHA.
- `tests/` — offline fixtures и проверки; `docs/USAGE.md` — инструкция оператора.

## Границы изменений

1. Обычные задачи по коду не разрешают подключаться к серверам, менять DNS,
   регистрировать webhooks или отправлять сообщения. Для реальной публикации
   нужно явное разрешение. Read-only API check отделять от register/send.
2. Поддерживать четыре конфигурации: без ботов, только Telegram, только МАКС, обе
   платформы. Пропущенная платформа не требует credentials и не вызывает API.
3. Схему обеих платформ и общие accounts устанавливать локально без сетевых
   вызовов. Оба `*_bot_enabled` остаются `false` до подтверждения регистрации.
4. Не требовать личного Telegram login, телефона или 2FA ради bot worker.
   Настройка API ID/hash не означает разрешение создать session или отправить пост.
5. hCaptcha выключается только явным `enabled=false`; password hashing, CSRF,
   throttling, проверка HTTPS/Origin и peer UID при этом сохраняются.
6. Секреты передавать через stdin/private config, не через argv, URL query,
   публичные JSON, raw stderr, traceback или Git. Личная ссылка использует
   fragment, автоматически удаляемый браузером; не вводить ручное поле кода.
7. Не ослаблять source/target identity, private membership, protection, целостность
   альбомов и SHA-256 файлов ради успешной проверки. Не заменять выбранный native
   repost скрытой копией. Неопределённую доставку не отправлять повторно наугад.
8. Изменение уже существующего чужого webhook требует подтверждённого takeover.
   Сначала подтвердить новый MAX endpoint, затем убирать разрешённые старые URL.
9. До удаления/переноса разрешить точный абсолютный путь и проверить границы.
   Не использовать `reset --hard`, force-push, очистку всего home/root или широкое
   рекурсивное удаление для исправления installation state.
10. Installer должен сохранять checkpoint и корректно продолжать прерванное
    задание. Терминальная запись установки должна восстанавливать публичный
    результат после рестарта; cleanup службы не отменяет завершённую установку.

## Проверки и release

Выполнять соответствующие offline tests, PHP lint и проверки manifest. Для
изменений provisioner нужен отдельный разрешённый чистый VPS; запуск на рабочем
сервере не является заменой такой проверке. Факт успешного smoke описывать только
после выполнения, не обещать его заранее.

Сборка: `python3 -B scripts/build.py --output /absolute/path/outside/repository`.
Проверка release: `python3 -B scripts/build.py --verify-manifest /path/to/bundle`.
Не создавать cache/pyc внутри пакета. Build должен воспроизводить одинаковые байты
при одинаковых исходниках и исключать Git metadata и standalone `install.sh`.

GitHub commit/push, новый repository и release выполнять только по прямому
запросу пользователя. Перед публикацией проверить точный diff, allowlist,
публичные URL и отсутствие секретов/production metadata. Не включать release
архивы, private документы другого проекта или историю с чувствительными данными.
