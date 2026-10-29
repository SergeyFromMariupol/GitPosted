<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/web-path.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/publication-plan.php';
require_once __DIR__ . '/telegram-native-content.php';
require_once __DIR__ . '/telegram-native-store.php';
require_once __DIR__ . '/telegram-native-runtime.php';
require_once __DIR__ . '/post-link.php';
require_once __DIR__ . '/telegram-native-forward.php';
require_once __DIR__ . '/telegram-post-links.php';
require_once __DIR__ . '/telegram-account-transfer.php';
require_once __DIR__ . '/telegram-account-transfer-ui.php';
require_once __DIR__ . '/telegram-authority-pause.php';
require_once __DIR__ . '/telegram-authority-pause-ui.php';
require_once __DIR__ . '/telegram-top-hold.php';
require_once __DIR__ . '/telegram-preview-proof.php';
require_once __DIR__ . '/telegram-album-collector.php';
require_once __DIR__ . '/telegram-template-media.php';
require_once __DIR__ . '/telegram-template-media-ui.php';
require_once __DIR__ . '/telegram-native-template-ui.php';
require_once __DIR__ . '/telegram-published-album-editor.php';
require_once __DIR__ . '/telegram-published-album-editor-ui.php';
require_once __DIR__ . '/telegram-published-album-panel.php';
require_once __DIR__ . '/telegram-button-ui.php';
require_once __DIR__ . '/telegram-public-button-layout.php';
require_once __DIR__ . '/telegram-public-button-edit.php';
require_once __DIR__ . '/telegram-public-button-integration.php';
require_once __DIR__ . '/telegram-cp-delete-confirm.php';
require_once __DIR__ . '/telegram-cp-detail-receipt.php';
require_once __DIR__ . '/telegram-published-single-ui.php';
require_once __DIR__ . '/telegram-top-ui.php';
require_once __DIR__ . '/telegram-top-quota-ui.php';
require_once __DIR__ . '/telegram-draft-ui.php';
require_once __DIR__ . '/telegram-draft-panel.php';
require_once __DIR__ . '/telegram-post-options.php';
require_once __DIR__ . '/telegram-transport-layout.php';
require_once __DIR__ . '/telegram-media-options.php';
require_once __DIR__ . '/telegram-web-bridge.php';
require_once __DIR__ . '/telegram-policy.php';
require_once __DIR__ . '/telegram-comments.php';
require_once __DIR__ . '/telegram-comments-reader.php';
require_once __DIR__ . '/telegram-comments-integration.php';
require_once __DIR__ . '/telegram-billing-api.php';
require_once __DIR__ . '/bot-commands.php';
require_once __DIR__ . '/telegram-payment-support.php';
require_once __DIR__ . '/telegram-subscription-ui.php';
require_once __DIR__ . '/telegram-preview-media.php';
require_once __DIR__ . '/telegram-multipost-delivery.php';
require_once __DIR__ . '/telegram-ui.php';
require_once __DIR__ . '/telegram-content-plan.php';
require_once __DIR__ . '/telegram-emoji-catalog.php';

final class PostedConfig
{
    private const DEFAULT_CONFIG_PATH = '/etc/gitposted/config.php';

    public static function get(): array
    {
        $configPath = trim((string) (getenv('POSTED_CONFIG_FILE') ?: self::DEFAULT_CONFIG_PATH));
        if ($configPath === '' || !is_file($configPath)) {
            throw new RuntimeException('GitPosted config file is unavailable.');
        }
        $config = require $configPath;
        if (!is_array($config)) {
            throw new RuntimeException('Config is invalid.');
        }
        date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Moscow'));
        return $config;
    }
}

final class PostedDb
{
    private static ?PDO $pdo = null;

    public static function pdo(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $db = (array) ($config['database'] ?? []);
        $charset = (string) ($db['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            (string) ($db['host'] ?? 'localhost'),
            (string) ($db['name'] ?? ''),
            $charset
        );
        self::$pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        ]);
        self::$pdo->exec(
            "SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );
        try{$timezone=new DateTimeZone((string)($config['timezone']??'Europe/Moscow'));}catch(Throwable){$timezone=new DateTimeZone('Europe/Moscow');}
        $offset=(new DateTimeImmutable('now',$timezone))->format('P');
        self::$pdo->exec('SET time_zone='.self::$pdo->quote($offset));
        return self::$pdo;
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_users (
                telegram_id BIGINT NOT NULL PRIMARY KEY,
                username VARCHAR(190) DEFAULT NULL,
                first_name VARCHAR(190) DEFAULT NULL,
                role ENUM('user') NOT NULL DEFAULT 'user',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                user_timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow',
                state VARCHAR(64) DEFAULT NULL,
                state_data JSON DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo, 'posted_users', 'user_timezone', "ALTER TABLE posted_users ADD COLUMN user_timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow' AFTER is_active");
        self::ensureColumn($pdo, 'posted_users', 'setting_folders', "ALTER TABLE posted_users ADD COLUMN setting_folders TINYINT(1) NOT NULL DEFAULT 0 AFTER user_timezone");
        self::ensureColumn($pdo, 'posted_users', 'setting_web_mode', "ALTER TABLE posted_users ADD COLUMN setting_web_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER setting_folders");
        self::ensureColumn($pdo, 'posted_users', 'setting_confirm_schedule', "ALTER TABLE posted_users ADD COLUMN setting_confirm_schedule TINYINT(1) NOT NULL DEFAULT 1 AFTER setting_web_mode");
        self::ensureColumn($pdo, 'posted_users', 'setting_compact_plan', "ALTER TABLE posted_users ADD COLUMN setting_compact_plan TINYINT(1) NOT NULL DEFAULT 0 AFTER setting_confirm_schedule");
        self::ensureColumn($pdo, 'posted_users', 'setting_show_plan_icons', "ALTER TABLE posted_users ADD COLUMN setting_show_plan_icons TINYINT(1) NOT NULL DEFAULT 1 AFTER setting_compact_plan");
        self::ensureColumn($pdo, 'posted_users', 'setting_delay_alerts', "ALTER TABLE posted_users ADD COLUMN setting_delay_alerts TINYINT(1) NOT NULL DEFAULT 1 AFTER setting_show_plan_icons");
        self::ensureColumnDefault($pdo,'posted_users','setting_web_mode','0',"ALTER TABLE posted_users ALTER COLUMN setting_web_mode SET DEFAULT 0");
        self::ensureColumnDefault($pdo,'posted_users','setting_compact_plan','0',"ALTER TABLE posted_users ALTER COLUMN setting_compact_plan SET DEFAULT 0");
        $pdo->exec("UPDATE posted_users SET role='user' WHERE role<>'user'");
        self::ensureColumnType(
            $pdo,
            'posted_users',
            'role',
            "enum('user')",
            "ALTER TABLE posted_users MODIFY role ENUM('user') NOT NULL DEFAULT 'user'"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_channels (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                chat_id VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                username VARCHAR(190) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                admin_blocked TINYINT(1) NOT NULL DEFAULT 0,
                bot_status VARCHAR(64) DEFAULT NULL,
                bot_can_post TINYINT(1) NOT NULL DEFAULT 0,
                bot_can_edit TINYINT(1) NOT NULL DEFAULT 0,
                bot_can_delete TINYINT(1) NOT NULL DEFAULT 0,
                added_by BIGINT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_channels_chat (chat_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo, 'posted_channels', 'admin_blocked', "ALTER TABLE posted_channels ADD COLUMN admin_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        self::ensureColumn($pdo, 'posted_channels', 'is_archived', "ALTER TABLE posted_channels ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        self::ensureColumn($pdo, 'posted_channels', 'schedule_json', "ALTER TABLE posted_channels ADD COLUMN schedule_json JSON DEFAULT NULL AFTER added_by");
        self::ensureColumn($pdo, 'posted_channels', 'schedule_timezone', "ALTER TABLE posted_channels ADD COLUMN schedule_timezone VARCHAR(64) DEFAULT NULL AFTER schedule_json");
        self::ensureColumn($pdo, 'posted_channels', 'post_autosignature', "ALTER TABLE posted_channels ADD COLUMN post_autosignature VARCHAR(255) DEFAULT NULL AFTER schedule_timezone");
        self::ensureColumn($pdo, 'posted_channels', 'post_autosignature_entities_json', "ALTER TABLE posted_channels ADD COLUMN post_autosignature_entities_json JSON DEFAULT NULL AFTER post_autosignature");
        self::ensureColumn($pdo, 'posted_channels', 'post_preview', "ALTER TABLE posted_channels ADD COLUMN post_preview TINYINT(1) DEFAULT NULL AFTER post_autosignature");
        self::ensureColumn($pdo, 'posted_channels', 'post_preview_large', "ALTER TABLE posted_channels ADD COLUMN post_preview_large TINYINT(1) DEFAULT NULL AFTER post_preview");
        self::ensureColumn($pdo, 'posted_channels', 'post_preview_above', "ALTER TABLE posted_channels ADD COLUMN post_preview_above TINYINT(1) DEFAULT NULL AFTER post_preview_large");
        self::ensureColumnNullable($pdo, 'posted_channels', 'post_preview', "ALTER TABLE posted_channels MODIFY post_preview TINYINT(1) DEFAULT NULL");
        self::ensureColumnNullable($pdo, 'posted_channels', 'post_preview_large', "ALTER TABLE posted_channels MODIFY post_preview_large TINYINT(1) DEFAULT NULL");
        self::ensureColumnNullable($pdo, 'posted_channels', 'post_preview_above', "ALTER TABLE posted_channels MODIFY post_preview_above TINYINT(1) DEFAULT NULL");
        self::ensureColumn($pdo, 'posted_channels', 'post_buttons_json', "ALTER TABLE posted_channels ADD COLUMN post_buttons_json JSON DEFAULT NULL AFTER post_preview_above");
        self::ensureColumn($pdo, 'posted_channels', 'post_silent', "ALTER TABLE posted_channels ADD COLUMN post_silent TINYINT(1) NOT NULL DEFAULT 0 AFTER post_buttons_json");
        self::ensureColumn($pdo, 'posted_channels', 'post_pin', "ALTER TABLE posted_channels ADD COLUMN post_pin TINYINT(1) NOT NULL DEFAULT 0 AFTER post_silent");
        self::ensureColumn($pdo, 'posted_channels', 'post_comments', "ALTER TABLE posted_channels ADD COLUMN post_comments TINYINT(1) NOT NULL DEFAULT 1 AFTER post_pin");
        self::ensureColumn($pdo, 'posted_channels', 'post_delete_service', "ALTER TABLE posted_channels ADD COLUMN post_delete_service TINYINT(1) NOT NULL DEFAULT 0 AFTER post_comments");
        self::ensureColumn($pdo, 'posted_channels', 'post_check_rights', "ALTER TABLE posted_channels ADD COLUMN post_check_rights TINYINT(1) NOT NULL DEFAULT 1 AFTER post_delete_service");
        self::ensureColumn($pdo, 'posted_channels', 'ad_delete_minutes', "ALTER TABLE posted_channels ADD COLUMN ad_delete_minutes INT UNSIGNED DEFAULT NULL AFTER post_check_rights");
        self::ensureColumn($pdo, 'posted_channels', 'ad_autosignature', "ALTER TABLE posted_channels ADD COLUMN ad_autosignature VARCHAR(255) DEFAULT NULL AFTER ad_delete_minutes");
        self::ensureColumn($pdo, 'posted_channels', 'ad_autosignature_entities_json', "ALTER TABLE posted_channels ADD COLUMN ad_autosignature_entities_json JSON DEFAULT NULL AFTER ad_autosignature");
        self::ensureColumn($pdo, 'posted_channels', 'ad_silent', "ALTER TABLE posted_channels ADD COLUMN ad_silent TINYINT(1) NOT NULL DEFAULT 1 AFTER ad_autosignature");
        self::ensureColumn($pdo, 'posted_channels', 'ad_pin', "ALTER TABLE posted_channels ADD COLUMN ad_pin TINYINT(1) NOT NULL DEFAULT 0 AFTER ad_silent");
        self::ensureColumn($pdo, 'posted_channels', 'ad_comments', "ALTER TABLE posted_channels ADD COLUMN ad_comments TINYINT(1) NOT NULL DEFAULT 0 AFTER ad_pin");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_permissions (
                user_id BIGINT NOT NULL,
                channel_id BIGINT UNSIGNED NOT NULL,
                can_post TINYINT(1) NOT NULL DEFAULT 1,
                can_schedule TINYINT(1) NOT NULL DEFAULT 1,
                can_manage_posts TINYINT(1) NOT NULL DEFAULT 1,
                can_manage_admins TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, channel_id),
                KEY idx_posted_permissions_channel (channel_id),
                CONSTRAINT fk_posted_permissions_channel FOREIGN KEY (channel_id) REFERENCES posted_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo, 'posted_permissions', 'is_ad_manager', "ALTER TABLE posted_permissions ADD COLUMN is_ad_manager TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_admins");
        self::ensureColumn($pdo, 'posted_permissions', 'manager_description', "ALTER TABLE posted_permissions ADD COLUMN manager_description VARCHAR(250) DEFAULT NULL AFTER is_ad_manager");
        self::ensureColumn($pdo, 'posted_permissions', 'post_quota_remaining', "ALTER TABLE posted_permissions ADD COLUMN post_quota_remaining INT UNSIGNED DEFAULT NULL AFTER manager_description");
        self::ensureColumn($pdo, 'posted_permissions', 'pin_quota_remaining', "ALTER TABLE posted_permissions ADD COLUMN pin_quota_remaining INT UNSIGNED DEFAULT NULL AFTER post_quota_remaining");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_manager_categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                channel_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                post_quota_remaining INT UNSIGNED DEFAULT NULL,
                pin_quota_remaining INT UNSIGNED DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_manager_category_name (user_id,channel_id,name),
                KEY idx_posted_manager_categories_channel (channel_id),
                CONSTRAINT fk_posted_manager_categories_channel FOREIGN KEY (channel_id) REFERENCES posted_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo,'posted_manager_categories','is_active','ALTER TABLE posted_manager_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER pin_quota_remaining');
        $pdo->exec(
            "INSERT INTO posted_permissions
                (user_id,channel_id,can_post,can_schedule,can_manage_posts,can_manage_admins)
             SELECT added_by,id,1,1,1,1
             FROM posted_channels
             WHERE added_by IS NOT NULL AND added_by>0
             ON DUPLICATE KEY UPDATE
                can_post=1,
                can_schedule=1,
                can_manage_posts=1,
                can_manage_admins=1"
        );
        $pdo->exec(
            "UPDATE posted_permissions permission
             INNER JOIN posted_channels channel ON channel.id=permission.channel_id
             SET permission.can_manage_admins=IF(permission.user_id=channel.added_by,1,0)"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_posts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                channel_id BIGINT UNSIGNED NOT NULL,
                author_id BIGINT NOT NULL,
                text MEDIUMTEXT DEFAULT NULL,
                text_entities_json JSON DEFAULT NULL,
                autosignature_text VARCHAR(255) DEFAULT NULL,
                autosignature_entities_json JSON DEFAULT NULL,
                media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none',
                media_file_id VARCHAR(255) DEFAULT NULL,
                media_json JSON DEFAULT NULL,
                telegram_preview_chat_id VARCHAR(64) DEFAULT NULL,
                telegram_preview_message_ids JSON DEFAULT NULL,
                telegram_preview_control_message_id BIGINT DEFAULT NULL,
                telegram_preview_fingerprint CHAR(64) DEFAULT NULL,
                buttons_json JSON DEFAULT NULL,
                status ENUM('draft','scheduled','publishing','telegram_pending','sent','deleted','failed','cancelled') NOT NULL DEFAULT 'draft',
                scheduled_at DATETIME DEFAULT NULL,
                delete_after_minutes INT UNSIGNED DEFAULT NULL,
                delete_at DATETIME DEFAULT NULL,
                telegram_message_id BIGINT DEFAULT NULL,
                telegram_message_ids JSON DEFAULT NULL,
                repeat_interval_minutes INT UNSIGNED DEFAULT NULL,
                repeat_until DATETIME DEFAULT NULL,
                copied_from_id BIGINT UNSIGNED DEFAULT NULL,
                silent TINYINT(1) NOT NULL DEFAULT 0,
                pin_after_publish TINYINT(1) NOT NULL DEFAULT 0,
                comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
                reply_chat_id VARCHAR(64) DEFAULT NULL,
                reply_message_id BIGINT DEFAULT NULL,
                external_chat_id VARCHAR(64) DEFAULT NULL,
                external_message_id BIGINT DEFAULT NULL,
                original_media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none',
                repost_chat_id VARCHAR(64) DEFAULT NULL,
                repost_message_id BIGINT DEFAULT NULL,
                repost_input_chat_id VARCHAR(64) DEFAULT NULL,
                repost_input_message_id BIGINT DEFAULT NULL,
                error_text TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_posted_posts_channel_status (channel_id, status, scheduled_at),
                KEY idx_posted_posts_delete (status, delete_at),
                CONSTRAINT fk_posted_posts_channel FOREIGN KEY (channel_id) REFERENCES posted_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo, 'posted_posts', 'text_entities_json', "ALTER TABLE posted_posts ADD COLUMN text_entities_json JSON DEFAULT NULL AFTER text");
        self::ensureColumnType(
            $pdo,
            'posted_posts',
            'status',
            "enum('draft','scheduled','publishing','telegram_pending','sent','deleted','failed','cancelled')",
            "ALTER TABLE posted_posts MODIFY status ENUM('draft','scheduled','publishing','telegram_pending','sent','deleted','failed','cancelled') NOT NULL DEFAULT 'draft'"
        );
        self::ensureColumn($pdo, 'posted_posts', 'autosignature_text', "ALTER TABLE posted_posts ADD COLUMN autosignature_text VARCHAR(255) DEFAULT NULL AFTER text_entities_json");
        self::ensureColumn($pdo, 'posted_posts', 'autosignature_entities_json', "ALTER TABLE posted_posts ADD COLUMN autosignature_entities_json JSON DEFAULT NULL AFTER autosignature_text");
        self::ensureColumn($pdo, 'posted_posts', 'publish_started_at', "ALTER TABLE posted_posts ADD COLUMN publish_started_at DATETIME DEFAULT NULL AFTER scheduled_at");
        self::ensureColumn($pdo, 'posted_posts', 'published_at', "ALTER TABLE posted_posts ADD COLUMN published_at DATETIME DEFAULT NULL AFTER publish_started_at");
        self::ensureColumn($pdo, 'posted_posts', 'delete_report_enabled', "ALTER TABLE posted_posts ADD COLUMN delete_report_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER delete_at");
        self::ensureColumn($pdo, 'posted_posts', 'manual_delete_required', "ALTER TABLE posted_posts ADD COLUMN manual_delete_required TINYINT(1) NOT NULL DEFAULT 0 AFTER delete_report_enabled");
        self::ensureColumn($pdo, 'posted_posts', 'delete_notice_sent_at', "ALTER TABLE posted_posts ADD COLUMN delete_notice_sent_at DATETIME DEFAULT NULL AFTER manual_delete_required");
        self::ensureColumn($pdo, 'posted_posts', 'telegram_message_ids', "ALTER TABLE posted_posts ADD COLUMN telegram_message_ids JSON DEFAULT NULL AFTER telegram_message_id");
        self::ensureColumn($pdo, 'posted_posts', 'media_json', "ALTER TABLE posted_posts ADD COLUMN media_json JSON DEFAULT NULL AFTER media_file_id");
        self::ensureColumn($pdo, 'posted_posts', 'telegram_preview_chat_id', "ALTER TABLE posted_posts ADD COLUMN telegram_preview_chat_id VARCHAR(64) DEFAULT NULL AFTER media_json");
        self::ensureColumn($pdo, 'posted_posts', 'telegram_preview_message_ids', "ALTER TABLE posted_posts ADD COLUMN telegram_preview_message_ids JSON DEFAULT NULL AFTER telegram_preview_chat_id");
        self::ensureColumn($pdo, 'posted_posts', 'telegram_preview_control_message_id', "ALTER TABLE posted_posts ADD COLUMN telegram_preview_control_message_id BIGINT DEFAULT NULL AFTER telegram_preview_message_ids");
        self::ensureColumn($pdo, 'posted_posts', 'telegram_preview_fingerprint', "ALTER TABLE posted_posts ADD COLUMN telegram_preview_fingerprint CHAR(64) DEFAULT NULL AFTER telegram_preview_control_message_id");
        self::ensureColumn($pdo, 'posted_posts', 'repeat_interval_minutes', "ALTER TABLE posted_posts ADD COLUMN repeat_interval_minutes INT UNSIGNED DEFAULT NULL AFTER telegram_message_ids");
        self::ensureColumn($pdo, 'posted_posts', 'repeat_until', "ALTER TABLE posted_posts ADD COLUMN repeat_until DATETIME DEFAULT NULL AFTER repeat_interval_minutes");
        self::ensureColumn($pdo, 'posted_posts', 'repeat_slot_at', "ALTER TABLE posted_posts ADD COLUMN repeat_slot_at DATETIME DEFAULT NULL AFTER repeat_until");
        self::ensureColumn($pdo, 'posted_posts', 'copied_from_id', "ALTER TABLE posted_posts ADD COLUMN copied_from_id BIGINT UNSIGNED DEFAULT NULL AFTER repeat_slot_at");
        self::ensureColumn($pdo, 'posted_posts', 'silent', "ALTER TABLE posted_posts ADD COLUMN silent TINYINT(1) NOT NULL DEFAULT 0 AFTER copied_from_id");
        self::ensureColumn($pdo, 'posted_posts', 'pin_after_publish', "ALTER TABLE posted_posts ADD COLUMN pin_after_publish TINYINT(1) NOT NULL DEFAULT 0 AFTER silent");
        self::ensureColumn($pdo, 'posted_posts', 'manager_post_quota_charged', "ALTER TABLE posted_posts ADD COLUMN manager_post_quota_charged TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_after_publish");
        self::ensureColumn($pdo, 'posted_posts', 'manager_pin_quota_charged', "ALTER TABLE posted_posts ADD COLUMN manager_pin_quota_charged TINYINT(1) NOT NULL DEFAULT 0 AFTER manager_post_quota_charged");
        self::ensureColumn($pdo, 'posted_posts', 'manager_category_id', "ALTER TABLE posted_posts ADD COLUMN manager_category_id BIGINT UNSIGNED DEFAULT NULL AFTER manager_pin_quota_charged");
        self::ensureColumn($pdo, 'posted_posts', 'comments_enabled', "ALTER TABLE posted_posts ADD COLUMN comments_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER pin_after_publish");
        self::ensureColumn($pdo, 'posted_posts', 'is_ad', "ALTER TABLE posted_posts ADD COLUMN is_ad TINYINT(1) NOT NULL DEFAULT 0 AFTER comments_enabled");
        self::ensureColumn($pdo, 'posted_posts', 'replacement_url', "ALTER TABLE posted_posts ADD COLUMN replacement_url VARCHAR(2048) DEFAULT NULL AFTER is_ad");
        self::ensureColumn($pdo, 'posted_posts', 'link_preview_enabled', "ALTER TABLE posted_posts ADD COLUMN link_preview_enabled TINYINT(1) DEFAULT NULL AFTER replacement_url");
        self::ensureColumn($pdo, 'posted_posts', 'link_preview_large', "ALTER TABLE posted_posts ADD COLUMN link_preview_large TINYINT(1) DEFAULT NULL AFTER link_preview_enabled");
        self::ensureColumn($pdo, 'posted_posts', 'link_preview_above', "ALTER TABLE posted_posts ADD COLUMN link_preview_above TINYINT(1) DEFAULT NULL AFTER link_preview_large");
        self::ensureColumnNullable($pdo, 'posted_posts', 'link_preview_enabled', "ALTER TABLE posted_posts MODIFY link_preview_enabled TINYINT(1) DEFAULT NULL");
        self::ensureColumnNullable($pdo, 'posted_posts', 'link_preview_large', "ALTER TABLE posted_posts MODIFY link_preview_large TINYINT(1) DEFAULT NULL");
        self::ensureColumnNullable($pdo, 'posted_posts', 'link_preview_above', "ALTER TABLE posted_posts MODIFY link_preview_above TINYINT(1) DEFAULT NULL");
        self::ensureColumn($pdo, 'posted_posts', 'reply_chat_id', "ALTER TABLE posted_posts ADD COLUMN reply_chat_id VARCHAR(64) DEFAULT NULL AFTER comments_enabled");
        self::ensureColumn($pdo, 'posted_posts', 'reply_message_id', "ALTER TABLE posted_posts ADD COLUMN reply_message_id BIGINT DEFAULT NULL AFTER reply_chat_id");
        self::ensureColumn($pdo, 'posted_posts', 'external_chat_id', "ALTER TABLE posted_posts ADD COLUMN external_chat_id VARCHAR(64) DEFAULT NULL AFTER reply_message_id");
        self::ensureColumn($pdo, 'posted_posts', 'external_message_id', "ALTER TABLE posted_posts ADD COLUMN external_message_id BIGINT DEFAULT NULL AFTER external_chat_id");
        self::ensureColumn($pdo, 'posted_posts', 'original_media_type', "ALTER TABLE posted_posts ADD COLUMN original_media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none' AFTER external_message_id");
        self::ensureColumnType($pdo, 'posted_posts', 'media_type', "enum('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact')", "ALTER TABLE posted_posts MODIFY media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none'");
        self::ensureColumnType($pdo, 'posted_posts', 'original_media_type', "enum('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact')", "ALTER TABLE posted_posts MODIFY original_media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none'");
        self::ensureColumn($pdo, 'posted_posts', 'repost_chat_id', "ALTER TABLE posted_posts ADD COLUMN repost_chat_id VARCHAR(64) DEFAULT NULL AFTER original_media_type");
        self::ensureColumn($pdo, 'posted_posts', 'repost_message_id', "ALTER TABLE posted_posts ADD COLUMN repost_message_id BIGINT DEFAULT NULL AFTER repost_chat_id");
        self::ensureColumn($pdo, 'posted_posts', 'repost_input_chat_id', "ALTER TABLE posted_posts ADD COLUMN repost_input_chat_id VARCHAR(64) DEFAULT NULL AFTER repost_message_id");
        self::ensureColumn($pdo, 'posted_posts', 'repost_input_message_id', "ALTER TABLE posted_posts ADD COLUMN repost_input_message_id BIGINT DEFAULT NULL AFTER repost_input_chat_id");
        self::ensureColumn($pdo, 'posted_posts', 'import_json', 'ALTER TABLE posted_posts ADD COLUMN import_json JSON DEFAULT NULL');
        self::ensureColumn($pdo, 'posted_posts', 'delete_attempts', "ALTER TABLE posted_posts ADD COLUMN delete_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER delete_notice_sent_at");
        self::ensureIndex($pdo,'posted_posts','uq_posted_repeat_slot','ALTER TABLE posted_posts ADD UNIQUE KEY uq_posted_repeat_slot (copied_from_id,repeat_slot_at)');
        $pdo->exec("UPDATE posted_posts SET original_media_type=media_type WHERE status='sent' AND telegram_message_id IS NOT NULL AND original_media_type='none' AND media_type IN ('photo','video','animation','document','audio','voice')");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_telegram_publish_attempts (
                post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                channel_id BIGINT UNSIGNED NOT NULL,
                chat_id VARCHAR(64) NOT NULL,
                gate_channel_id BIGINT UNSIGNED DEFAULT NULL,
                submission_key CHAR(64) DEFAULT NULL,
                state ENUM('sending','awaiting_telegram','reconciled','finalizing','continuation_sending','cancel_finalizing','force_cancel_finalizing','needs_review','finalization_uncertain','cancel_requested','completed') NOT NULL DEFAULT 'sending',
                message_slots_json JSON DEFAULT NULL,
                pending_parts_json JSON DEFAULT NULL,
                continuation_json JSON DEFAULT NULL,
                accepted_at DATETIME DEFAULT NULL,
                deadline_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                review_notified_at DATETIME DEFAULT NULL,
                review_notification_sent_at DATETIME DEFAULT NULL,
                pin_started_at DATETIME DEFAULT NULL,
                pin_completed_at DATETIME DEFAULT NULL,
                pin_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                pin_delivery_uncertain TINYINT(1) NOT NULL DEFAULT 0,
                finalized_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_telegram_gate_channel (gate_channel_id),
                UNIQUE KEY uq_posted_telegram_submission (submission_key),
                KEY idx_posted_telegram_attempt_state (state,deadline_at),
                KEY idx_posted_telegram_attempt_chat (chat_id,state),
                CONSTRAINT fk_posted_telegram_attempt_post FOREIGN KEY (post_id) REFERENCES posted_posts(id) ON DELETE CASCADE,
                CONSTRAINT fk_posted_telegram_attempt_channel FOREIGN KEY (channel_id) REFERENCES posted_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumnType(
            $pdo,
            'posted_telegram_publish_attempts',
            'state',
            "enum('sending','awaiting_telegram','reconciled','finalizing','continuation_sending','cancel_finalizing','force_cancel_finalizing','needs_review','finalization_uncertain','cancel_requested','completed')",
            "ALTER TABLE posted_telegram_publish_attempts MODIFY state ENUM('sending','awaiting_telegram','reconciled','finalizing','continuation_sending','cancel_finalizing','force_cancel_finalizing','needs_review','finalization_uncertain','cancel_requested','completed') NOT NULL DEFAULT 'sending'"
        );
        self::ensureColumn(
            $pdo,
            'posted_telegram_publish_attempts',
            'review_notified_at',
            'ALTER TABLE posted_telegram_publish_attempts ADD COLUMN review_notified_at DATETIME DEFAULT NULL AFTER completed_at'
        );
        self::ensureColumn(
            $pdo,
            'posted_telegram_publish_attempts',
            'review_notification_sent_at',
            'ALTER TABLE posted_telegram_publish_attempts ADD COLUMN review_notification_sent_at DATETIME DEFAULT NULL AFTER review_notified_at'
        );
        self::ensureColumn(
            $pdo,
            'posted_telegram_publish_attempts',
            'pin_started_at',
            'ALTER TABLE posted_telegram_publish_attempts ADD COLUMN pin_started_at DATETIME DEFAULT NULL AFTER review_notification_sent_at'
        );
        self::ensureColumn(
            $pdo,
            'posted_telegram_publish_attempts',
            'pin_completed_at',
            'ALTER TABLE posted_telegram_publish_attempts ADD COLUMN pin_completed_at DATETIME DEFAULT NULL AFTER pin_started_at'
        );
        self::ensureColumn(
            $pdo,
            'posted_telegram_publish_attempts',
            'pin_attempts',
            'ALTER TABLE posted_telegram_publish_attempts ADD COLUMN pin_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_completed_at'
        );
        self::ensureColumn(
            $pdo,
            'posted_telegram_publish_attempts',
            'pin_delivery_uncertain',
            'ALTER TABLE posted_telegram_publish_attempts ADD COLUMN pin_delivery_uncertain TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_attempts'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner_id BIGINT NOT NULL,
                channel_id BIGINT UNSIGNED DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                text MEDIUMTEXT DEFAULT NULL,
                text_entities_json JSON DEFAULT NULL,
                media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none',
                media_file_id VARCHAR(255) DEFAULT NULL,
                buttons_json JSON DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_posted_templates_owner (owner_id),
                CONSTRAINT fk_posted_templates_channel FOREIGN KEY (channel_id) REFERENCES posted_channels(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo, 'posted_templates', 'text_entities_json', "ALTER TABLE posted_templates ADD COLUMN text_entities_json JSON DEFAULT NULL AFTER text");
        self::ensureColumn($pdo, 'posted_templates', 'media_type', "ALTER TABLE posted_templates ADD COLUMN media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none' AFTER text");
        self::ensureColumnType($pdo, 'posted_templates', 'media_type', "enum('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact')", "ALTER TABLE posted_templates MODIFY media_type ENUM('none','photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact') NOT NULL DEFAULT 'none'");
        self::ensureColumn($pdo, 'posted_templates', 'media_file_id', "ALTER TABLE posted_templates ADD COLUMN media_file_id VARCHAR(255) DEFAULT NULL AFTER media_type");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_edit_sessions (
                user_id BIGINT NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                snapshot_json JSON NOT NULL,
                delete_on_cancel TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id,post_id),
                CONSTRAINT fk_posted_edit_session_post FOREIGN KEY (post_id) REFERENCES posted_posts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_reactions (
                post_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT NOT NULL,
                reaction_key VARCHAR(32) NOT NULL,
                last_callback_id VARCHAR(128) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id, user_id),
                KEY idx_posted_reactions_key (post_id, reaction_key),
                CONSTRAINT fk_posted_reactions_post FOREIGN KEY (post_id) REFERENCES posted_posts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn(
            $pdo,
            'posted_reactions',
            'last_callback_id',
            'ALTER TABLE posted_reactions ADD COLUMN last_callback_id VARCHAR(128) DEFAULT NULL AFTER reaction_key'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_app_settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value JSON NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_admin_tokens (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_posted_admin_tokens_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_admin_passkeys (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                credential_id VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(80) NOT NULL,
                public_key_spki MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                algorithm SMALLINT NOT NULL,
                sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                transports VARCHAR(255) NOT NULL DEFAULT '[]',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                UNIQUE KEY uq_posted_admin_passkey_credential (credential_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_folders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner_id BIGINT NOT NULL,
                title VARCHAR(64) NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_folder_owner_title (owner_id,title),
                KEY idx_posted_folders_owner (owner_id,sort_order,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_folder_channels (
                folder_id BIGINT UNSIGNED NOT NULL,
                channel_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (folder_id,channel_id),
                CONSTRAINT fk_posted_folder_channel_folder FOREIGN KEY (folder_id) REFERENCES posted_folders(id) ON DELETE CASCADE,
                CONSTRAINT fk_posted_folder_channel_channel FOREIGN KEY (channel_id) REFERENCES posted_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_emoji_sets (
                set_name VARCHAR(190) NOT NULL PRIMARY KEY,
                preview_html TEXT DEFAULT NULL,
                sticker_count INT UNSIGNED DEFAULT NULL,
                error_text TEXT DEFAULT NULL,
                fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_emoji_catalog (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(64) NOT NULL,
                set_name VARCHAR(190) NOT NULL,
                preview TEXT DEFAULT NULL,
                url VARCHAR(255) NOT NULL,
                sticker_count INT UNSIGNED NOT NULL DEFAULT 0,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_emoji_catalog_set (category, set_name),
                KEY idx_posted_emoji_catalog_category (category, is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_emoji_submissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                set_name VARCHAR(190) NOT NULL,
                source_text TEXT DEFAULT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_emoji_submission (user_id,set_name),
                KEY idx_posted_emoji_submission_status (status,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureEmojiCatalogKey($pdo);
        PostedAccounts::installSchema($pdo);
    }

    public static function assertSchema(PDO $pdo): void
    {
        $requiredTables = [
            'posted_users',
            'posted_channels',
            'posted_permissions',
            'posted_manager_categories',
            'posted_posts',
            'posted_telegram_publish_attempts',
            'posted_templates',
            'posted_edit_sessions',
            'posted_reactions',
            'posted_app_settings',
            'posted_admin_tokens',
            'posted_accounts',
            'posted_account_identities',
            'posted_folders',
            'posted_folder_channels',
            'posted_emoji_sets',
            'posted_emoji_catalog',
            'posted_emoji_submissions',
        ];
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        foreach ($requiredTables as $table) {
            $stmt->execute(['table_name' => $table]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new RuntimeException('GitPosted schema is not installed; run setup.php from CLI.');
            }
        }

        $requiredColumns = [
            'posted_users' => ['telegram_id', 'is_active', 'user_timezone', 'state', 'state_data', 'setting_confirm_schedule'],
            'posted_channels' => ['id', 'chat_id', 'is_active', 'admin_blocked', 'bot_can_post', 'added_by'],
            'posted_permissions' => ['user_id', 'channel_id', 'can_post', 'can_schedule', 'can_manage_posts', 'can_manage_admins', 'is_ad_manager'],
            'posted_manager_categories' => ['id', 'user_id', 'channel_id', 'post_quota_remaining', 'pin_quota_remaining', 'is_active'],
            'posted_posts' => ['id', 'channel_id', 'author_id', 'status', 'scheduled_at', 'media_json', 'telegram_message_ids', 'manager_category_id', 'import_json'],
            'posted_telegram_publish_attempts' => ['post_id', 'channel_id', 'gate_channel_id', 'submission_key', 'state', 'pin_attempts', 'pin_delivery_uncertain'],
            'posted_reactions' => ['post_id', 'user_id', 'reaction_key', 'last_callback_id'],
        ];
        $availableColumns = [];
        $columnRows = $pdo->query(
            "SELECT TABLE_NAME,COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()"
        )->fetchAll();
        foreach ($columnRows as $row) {
            $availableColumns[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = true;
        }
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (empty($availableColumns[$table][$column])) {
                    throw new RuntimeException(
                        "GitPosted schema is incomplete ({$table}.{$column}); run setup.php from CLI."
                    );
                }
            }
        }

        $requiredIndexes = [
            'posted_channels' => ['PRIMARY', 'uq_posted_channels_chat'],
            'posted_permissions' => ['PRIMARY', 'idx_posted_permissions_channel'],
            'posted_manager_categories' => ['PRIMARY', 'uq_posted_manager_category_name'],
            'posted_posts' => ['PRIMARY', 'idx_posted_posts_channel_status', 'idx_posted_posts_delete', 'uq_posted_repeat_slot'],
            'posted_telegram_publish_attempts' => ['PRIMARY', 'uq_posted_telegram_gate_channel', 'uq_posted_telegram_submission'],
        ];
        $availableIndexes = [];
        $indexRows = $pdo->query(
            "SELECT DISTINCT TABLE_NAME,INDEX_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE()"
        )->fetchAll();
        foreach ($indexRows as $row) {
            $availableIndexes[(string) $row['TABLE_NAME']][(string) $row['INDEX_NAME']] = true;
        }
        foreach ($requiredIndexes as $table => $indexes) {
            foreach ($indexes as $index) {
                if (empty($availableIndexes[$table][$index])) {
                    throw new RuntimeException(
                        "GitPosted schema is incomplete ({$table}.{$index}); run setup.php from CLI."
                    );
                }
            }
        }
    }

    private static function ensureEmojiCatalogKey(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='posted_emoji_catalog' AND INDEX_NAME='uq_posted_emoji_catalog_set'");
        $columns = (string) ($stmt->fetchColumn() ?: '');
        if ($columns === '') {
            $pdo->exec('ALTER TABLE posted_emoji_catalog ADD UNIQUE KEY uq_posted_emoji_catalog_set (category, set_name)');
        } elseif ($columns !== 'category,set_name') {
            $pdo->exec('ALTER TABLE posted_emoji_catalog DROP INDEX uq_posted_emoji_catalog_set, ADD UNIQUE KEY uq_posted_emoji_catalog_set (category, set_name)');
        }
    }
    private static function ensureColumn(PDO $pdo, string $table, string $column, string $alterSql): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name"
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec($alterSql);
        }
    }

    private static function ensureColumnNullable(PDO $pdo, string $table, string $column, string $alterSql): void
    {
        $stmt=$pdo->prepare('SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name LIMIT 1');
        $stmt->execute(['table_name'=>$table,'column_name'=>$column]);
        if((string)($stmt->fetchColumn()?:'')!=='YES')$pdo->exec($alterSql);
    }

    private static function ensureIndex(PDO $pdo, string $table, string $index, string $alterSql): void
    {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND INDEX_NAME=:index_name');
        $stmt->execute(['table_name'=>$table,'index_name'=>$index]);
        if((int)$stmt->fetchColumn()===0)$pdo->exec($alterSql);
    }

    private static function ensureColumnType(PDO $pdo, string $table, string $column, string $expectedType, string $alterSql): void
    {
        if(PostedTelegramNativeSchema::protectedColumn($table,$column)){
            $actual=PostedTelegramNativeSchema::columnType($pdo,$table,$column);
            $legacy="enum('none','photo','video','animation','document','audio','voice')";
            if(!PostedTelegramNativeSchema::supportedSuperset($actual,$legacy))throw new RuntimeException('Telegram media enum requires explicit compatibility review.');
            // Existing supported columns, including future supersets, are NEVER narrowed or automatically widened here.
            // An explicit additive migration and a verified capability certificate enable the new ingress separately.
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name
             LIMIT 1"
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        $type = (string) ($stmt->fetchColumn() ?: '');
        if (strtolower($type) !== strtolower($expectedType)) {
            $pdo->exec($alterSql);
        }
    }

    private static function ensureColumnDefault(PDO $pdo,string $table,string $column,string $expectedDefault,string $alterSql):void
    {
        $stmt=$pdo->prepare(
            'SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS'
            .' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name'
            .' AND COLUMN_NAME=:column_name LIMIT 1'
        );
        $stmt->execute(['table_name'=>$table,'column_name'=>$column]);
        if((string)$stmt->fetchColumn()!==$expectedDefault)$pdo->exec($alterSql);
    }
}

final class PostedTelegramRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $deliveryMayBeUnknown = false,
        private readonly int $httpStatus = 0,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }

    public function deliveryMayBeUnknown(): bool
    {
        return $this->deliveryMayBeUnknown;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

final class PostedTelegramAcceptedButNotPersistedException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            'Telegram принял запрос, но локальное состояние не удалось полностью сохранить: '
            . $previous->getMessage(),
            0,
            $previous
        );
    }
}

final class PostedTerminalPublishException extends RuntimeException
{
}

final class PostedPublishAuthorizationException extends RuntimeException
{
}

final class PostedTelegram
{
    public function __construct(private readonly string $token, private readonly int $requestTimeoutSeconds = 30)
    {
        if ($this->requestTimeoutSeconds < 1 || $this->requestTimeoutSeconds > 30) {
            throw new InvalidArgumentException('Telegram API timeout must be between one and thirty seconds.');
        }
    }

    public function call(string $method, array $payload = []): array
    {
        if (GitPostedPostLinkAssets::containsMarker($payload)) {
            $multipart = GitPostedPostLinkAssets::telegramMultipart($method, $payload, PostedConfig::get());
            if ($multipart !== null) return $this->callMultipart($method, $multipart);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required.');
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode($this->token) . '/' . $method;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Cannot encode Telegram payload.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->requestTimeoutSeconds),
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Accept: application/json'],
            CURLOPT_USERAGENT => 'GitPosted Bot',
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false || trim((string) $response) === '') {
            throw new PostedTelegramRequestException(
                $error !== '' ? $error : 'Empty Telegram response.',
                true
            );
        }
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new PostedTelegramRequestException('Bad Telegram JSON response.', true, $status);
        }
        if ($status >= 400 || empty($data['ok'])) {
            throw new PostedTelegramRequestException(
                (string) ($data['description'] ?? ('Telegram HTTP ' . $status)),
                $status === 408 || $status >= 500,
                $status,
                isset($data['parameters']['retry_after'])
                    ?max(1,(int)$data['parameters']['retry_after'])
                    :null
            );
        }
        return $data;
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        $base=['chat_id'=>(string)$chatId,'text'=>$text];
        if(!isset($extra['link_preview_options'])&&!array_key_exists('disable_web_page_preview',$extra))$base['disable_web_page_preview']=true;
        return $this->call('sendMessage',array_merge($base,$extra));
    }

    public function sendPhoto(int|string $chatId, string $fileId, string $caption = '', array $extra = []): array
    {
        if (is_file($fileId)) {
            $payload = array_merge(['chat_id'=>(string)$chatId,'caption'=>$caption],$extra);
            $payload['photo'] = new CURLFile($fileId, 'image/jpeg', basename($fileId));
            return $this->callMultipart('sendPhoto', $payload);
        }
        return $this->call('sendPhoto', array_merge(['chat_id'=>(string)$chatId,'photo'=>$fileId,'caption'=>$caption],$extra));
    }

    private function callMultipart(string $method, array $payload): array
    {
        $url='https://api.telegram.org/bot'.rawurlencode($this->token).'/'.$method;
        foreach($payload as $key=>$value) if(is_array($value)) $payload[$key]=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>min(10,$this->requestTimeoutSeconds),CURLOPT_TIMEOUT=>$this->requestTimeoutSeconds,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'GitPosted Bot']);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if(!is_string($raw)||$raw===''){
            throw new PostedTelegramRequestException(
                $error!==''?$error:'Empty Telegram response.',
                true,
                $status
            );
        }
        $data=json_decode($raw,true);
        if(!is_array($data)){
            throw new PostedTelegramRequestException('Bad Telegram JSON response.',true,$status);
        }
        if($status>=400||empty($data['ok'])){
            throw new PostedTelegramRequestException(
                (string)($data['description']??('Telegram HTTP '.$status)),
                $status===408||$status>=500,
                $status,
                isset($data['parameters']['retry_after'])
                    ?max(1,(int)$data['parameters']['retry_after'])
                    :null
            );
        }
        return $data;
    }


    public function sendVideo(int|string $chatId, string $fileId, string $caption = '', array $extra = []): array
    {
        return $this->call('sendVideo', array_merge([
            'chat_id' => (string) $chatId,
            'video' => $fileId,
            'caption' => $caption,
        ], $extra));
    }

    public function sendMediaGroup(int|string $chatId, array $media, array $extra = []): array
    {
        if (count($media) < 2 || count($media) > 10) {
            throw new InvalidArgumentException('Telegram media group must contain 2 to 10 items.');
        }
        return $this->call('sendMediaGroup', array_merge([
            'chat_id' => (string) $chatId,
            'media' => array_values($media),
        ], $extra));
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): array
    {
        $base=['chat_id'=>(string)$chatId,'message_id'=>$messageId,'text'=>$text];
        if(!isset($extra['link_preview_options'])&&!array_key_exists('disable_web_page_preview',$extra))$base['disable_web_page_preview']=true;
        return $this->call('editMessageText',array_merge($base,$extra));
    }

    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, array $extra = []): array
    {
        return $this->call('editMessageCaption', array_merge([
            'chat_id' => (string) $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
        ], $extra));
    }

    public function editMessageMedia(int|string $chatId, int $messageId, array $media, array $extra = []): array
    {
        return $this->call('editMessageMedia', array_merge([
            'chat_id' => (string) $chatId,
            'message_id' => $messageId,
            'media' => $media,
        ], $extra));
    }

    public function forwardMessage(int|string $chatId, int|string $fromChatId, int $messageId, array $extra = []): array
    {
        return $this->call('forwardMessage', array_merge([
            'chat_id' => (string) $chatId,
            'from_chat_id' => (string) $fromChatId,
            'message_id' => $messageId,
        ], $extra));
    }

    public function pinChatMessage(int|string $chatId, int $messageId, bool $disableNotification = true): array
    {
        return $this->call('pinChatMessage', [
            'chat_id' => (string) $chatId,
            'message_id' => $messageId,
            'disable_notification' => $disableNotification,
        ]);
    }

    public function unpinChatMessage(int|string $chatId,int $messageId):array
    {
        return $this->call('unpinChatMessage',['chat_id'=>(string)$chatId,'message_id'=>$messageId]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', [
            'chat_id' => (string) $chatId,
            'message_id' => $messageId,
        ]);
    }
}

final class PostedBot
{
    use GitPostedTelegramPostLinks;
    use GitPostedTelegramAccountTransferUi;
    use PostedTelegramAuthorityPauseCore;
    use GitPostedTelegramTopHold;
    use PostedTelegramTopUi;
    use PostedTelegramAlbumUi;
    use GitPostedTelegramTemplateMediaUi;
    use GitPostedTelegramNativeTemplateUi;
    use PostedTelegramPublishedAlbumEditorUi;
    use GitPostedTelegramPublishedAlbumPanel;
    use GitPostedTelegramPublicButtonIntegration;
    use GitPostedTelegramCpDeleteConfirmation;
    use GitPostedTelegramCpDetailReceipt;
    use GitPostedTelegramPublishedSingleUi;
    use PostedTelegramTopQuotaUi;
    use PostedTelegramAuthorityPauseUi;
    use GitPostedTelegramDraftUi;
    use GitPostedTelegramDraftPanel;
    use GitPostedTelegramPostOptions;
    use GitPostedTelegramNativeRuntime;
    use GitPostedTelegramMediaOptions;
    use GitPostedTelegramWebBridge;
    use GitPostedTelegramContentPlanUi;
    use GitPostedTelegramPolicy;
    use PostedTelegramCommentsHooksPrototype05;
    use PostedTelegramCommentsReaderHooksV3;
    use GitPostedTelegramCommentsIntegration;
    use GitPostedTelegramPaymentSupportHandler;
    use PostedTelegramSubscriptionUi;
    use GitPostedTelegramMultipost;
    use GitPostedTelegramMultipostDelivery;
    use PostedTelegramPreviewMediaUi;
    use PostedTelegramEmojiCatalogUi;
    private const TEXT_LIMIT = 4096;
    private const PHOTO_CAPTION_LIMIT = 1024;
    private const HIDDEN_TEXT_LIMIT = 190;
    private const TELEGRAM_MAX_AUTO_DELETE_MINUTES = 2875;
    private const TELEGRAM_MAX_DELETE_TIMER_MINUTES = 525600;
    private const EDITABLE_POST_FIELDS = [
        'text',
        'text_entities_json',
        'media_type',
        'media_file_id',
        'media_json',
        'buttons_json',
        'pin_after_publish',
        'autosignature_text',
        'autosignature_entities_json',
    ];
    /** @var array<string, bool|null> */
    private array $telegramChannelAdminCache = [];
    private ?int $telegramBotIdCache = null;
    private ?int $telegramTransferredBotIdCache = null;
    private array $telegramUiLanguageCache = [];

    private const MAIN_KEYBOARD = [
        [
            ['text' => 'Создать пост', 'icon_custom_emoji_id' => '6028346797368283073'],
            ['text' => 'Изменить пост', 'icon_custom_emoji_id' => '6039779802741739617'],
        ],
        [
            ['text' => 'Контент-план', 'icon_custom_emoji_id' => '5890937706803894250'],
            ['text' => 'Настройки', 'icon_custom_emoji_id' => '6032742198179532882'],
        ],
        [
            ['text' => 'Шаблоны', 'icon_custom_emoji_id' => '5886285355279193209'],
            ['text' => 'Каталог эмодзи', 'icon_custom_emoji_id' => '6028315147754278596'],
        ],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PostedTelegram $telegram,
        private readonly array $config
    ) {
    }

    public function handle(array $update): void
    {
        if($this->observeTelegramDiscussionRoot($update))return;
        if (isset($update['channel_post']) && is_array($update['channel_post'])) {
            $this->handleTelegramChannelPost(
                $update['channel_post'],
                max(0, (int) ($update['update_id'] ?? 0))
            );
            return;
        }
        if (isset($update['edited_channel_post']) && is_array($update['edited_channel_post'])) {
            // Editing an existing message cannot prove a new delivery slot.
            // Original channel_post updates and known API acknowledgements keep
            // their existing reconciliation paths.
            return;
        }
        if (isset($update['my_chat_member']) && is_array($update['my_chat_member'])) {
            $this->handleMyChatMember($update['my_chat_member']);
            return;
        }
        if (isset($update['chat_member']) && is_array($update['chat_member'])) {
            $this->handleChatMember($update['chat_member']);
            return;
        }
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }
        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    public function runCron(): array
    {
        $lockName = 'trevoga_posted_cron';
        $lockStmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $lockStmt->execute(['lock_name' => $lockName]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            return ['sent' => 0, 'pending' => 0, 'deleted' => 0, 'errors' => [], 'skipped' => 'already_running'];
        }

        try {
        $sent = 0;
        $pending = 0;
        $deleted = 0;
        $errors = [];
        $this->reconcileTelegramChannelOwners(5);
        $this->recoverStalePublishingPosts();
        $this->resumeReconciledTelegramAttempts();
        $this->resumeForceCancelledTelegramAttempts();
        $this->resumeCompletedTelegramEffects();
        $this->resumeTelegramMultiposts();
        $this->notifyExpiredTelegramAttempts();
        $stmt = $this->pdo->query(
            "SELECT p.*, c.chat_id
             FROM posted_posts p
             INNER JOIN posted_channels c ON c.id = p.channel_id
             INNER JOIN posted_users owner_user
                     ON owner_user.telegram_id = c.added_by
                    AND owner_user.is_active = 1
             INNER JOIN posted_users author_user
                     ON author_user.telegram_id = p.author_id
                    AND author_user.is_active = 1
             INNER JOIN posted_permissions author_permission
                     ON author_permission.user_id = p.author_id
                    AND author_permission.channel_id = p.channel_id
                    AND author_permission.can_schedule = 1
             LEFT JOIN posted_manager_categories manager_category
                    ON manager_category.id = p.manager_category_id
                   AND manager_category.user_id = p.author_id
                   AND manager_category.channel_id = p.channel_id
                   AND manager_category.is_active = 1
             WHERE p.status = 'scheduled'
               AND p.scheduled_at IS NOT NULL
               AND p.scheduled_at <= NOW()
               AND c.is_active = 1
               AND c.bot_can_post = 1
               AND ".PostedTelegramAuthorityPause::dueSql('c')."
               AND c.added_by IS NOT NULL
               AND c.added_by > 0
               AND (
                    COALESCE(p.manager_category_id, 0) = 0
                    OR (
                        author_permission.is_ad_manager = 1
                        AND manager_category.id IS NOT NULL
                    )
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM posted_telegram_publish_attempts busy_attempt
                    WHERE busy_attempt.gate_channel_id = p.channel_id
               )
              ORDER BY p.scheduled_at, p.id
              LIMIT 30"
        );
        foreach ($stmt->fetchAll() as $candidate) {
            try{$post=$this->claimPostForPublish((int)$candidate['id'],['scheduled'],true);}catch(PostedTelegramAuthorityPauseException){continue;}
            if(!$post)continue;
            try {
                $outcome=$this->publishClaimedPost($post);
                if(in_array((string)($outcome['state']??''),['processing','retry_scheduled'],true))$pending++;
                elseif((string)($outcome['state']??'')!=='blocked')$sent++;
            } catch (Throwable $exception) {
                $errors[] = 'post ' . (int) $post['id'] . ': ' . $exception->getMessage();
                if (
                    $exception instanceof PostedTerminalPublishException
                    || str_starts_with($exception->getMessage(), 'Лимит ')
                ) {
                    try {
                        $this->telegram->sendMessage(
                            (int) $post['author_id'],
                            'Пост #' . (int) $post['id'] . ' не опубликован: ' . $exception->getMessage()
                        );
                    } catch (Throwable) {
                    }
                }
            }
        }

        $stmt = $this->pdo->query(
            "SELECT p.*, c.chat_id
             FROM posted_posts p
             INNER JOIN posted_channels c ON c.id = p.channel_id
              WHERE p.status = 'sent'
                AND p.delete_at IS NOT NULL
                AND p.delete_at <= NOW()
                AND c.is_active = 1
                AND c.added_by IS NOT NULL
                AND c.added_by > 0
                AND (p.telegram_message_id IS NOT NULL OR p.telegram_message_ids IS NOT NULL)
             ORDER BY p.delete_at, p.id
             LIMIT 30"
        );
        foreach ($stmt->fetchAll() as $post) {
            try {
                if ((int) ($post['delete_after_minutes'] ?? 0) > self::TELEGRAM_MAX_AUTO_DELETE_MINUTES) {
                    $manual=$this->pdo->prepare("UPDATE posted_posts SET delete_at=NULL,manual_delete_required=1,error_text=:error
                        WHERE id=:id AND status='sent' AND delete_at=:observed_at
                          AND delete_after_minutes <=> :observed_minutes");
                    $manual->execute(['error'=>'Безопасный срок автоудаления Telegram превышен; требуется ручное удаление.',
                        'id'=>(int)$post['id'],'observed_at'=>$post['delete_at'],'observed_minutes'=>$post['delete_after_minutes']]);
                    if ($manual->rowCount()!==1) continue;
                    if(!empty($post['delete_report_enabled'])){
                        try{$this->telegram->sendMessage((int)$post['author_id'],'Пост #'.(int)$post['id'].' нужно удалить вручную: безопасный срок автоудаления Telegram уже прошёл.');}catch(Throwable){}
                    }
                    continue;
                }
                if (!$this->deleteDueTelegramPost($post)) {
                    continue;
                }
                if(!empty($post['delete_report_enabled'])){
                    try{$this->telegram->sendMessage((int)$post['author_id'],'Пост #'.(int)$post['id'].' удалён по таймеру.');}catch(Throwable){}
                }
                $deleted++;
            } catch (Throwable $exception) {
                $errors[] = 'delete ' . (int) $post['id'] . ': ' . $exception->getMessage();
                $this->recordTelegramDeleteFailure($post,$exception);
            }
        }

        return ['sent' => $sent, 'pending' => $pending, 'deleted' => $deleted, 'errors' => $errors];
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        }
    }

    private function recordTelegramDeleteFailure(array $post,Throwable $exception): bool
    {
        $attempt=(int)($post['delete_attempts']??0)+1;
        $terminal=$attempt>=5;
        $parameters=['attempt'=>$attempt,'error'=>mb_substr($exception->getMessage(),0,4000),
            'id'=>(int)$post['id'],'observed_at'=>$post['delete_at'],
            'observed_minutes'=>$post['delete_after_minutes']??null];
        $sql=$terminal
            ?'UPDATE posted_posts SET delete_at=NULL,manual_delete_required=1,delete_attempts=:attempt,error_text=:error'
            :'UPDATE posted_posts SET delete_at=:retry,delete_attempts=:attempt,error_text=:error';
        if (!$terminal) $parameters['retry']=date('Y-m-d H:i:s',time()+min(60,2**$attempt)*60);
        // A user may change the timer while Telegram is processing the request.
        // A failure belongs only to the deletion intent selected by this cron.
        $update=$this->pdo->prepare($sql." WHERE id=:id AND status='sent'
            AND delete_at=:observed_at AND delete_after_minutes <=> :observed_minutes");
        $update->execute($parameters);
        if ($update->rowCount()!==1) return false;
        if ($terminal) {
            try {$this->telegram->sendMessage((int)$post['author_id'],
                'Не удалось автоматически удалить пост #'.(int)$post['id'].'. Удалите его вручную.');} catch (Throwable) {}
        }
        return true;
    }

    private function deleteDueTelegramPost(array $post): bool
    {
        $override=$this->telegramPublicDeleteOverride($post,'due');if($override!==null)return$override;
        $postId=(int)($post['id']??0);
        $channelId=(int)($post['channel_id']??0);
        if($postId<=0||$channelId<=0)return false;
        $pinLockName='gitposted_pin_'.substr(hash('sha256',(string)$channelId),0,32);
        $pinLock=$this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $pinLock->execute(['lock_name'=>$pinLockName]);
        if((int)($pinLock->fetchColumn()?:0)!==1)return false;
        try{
            $this->pdo->beginTransaction();
            $channelLock=$this->pdo->prepare(
                'SELECT chat_id,is_active,added_by
                 FROM posted_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelLock->execute(['channel'=>$channelId]);
            $channel=$channelLock->fetch();
            $postLock=$this->pdo->prepare(
                "SELECT *
                 FROM posted_posts
                 WHERE id=:post AND channel_id=:channel
                   AND status='sent' AND delete_at IS NOT NULL AND delete_at<=NOW()
                 LIMIT 1
                 FOR UPDATE"
            );
            $postLock->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedPost=$postLock->fetch();
            if(
                !is_array($channel)
                ||empty($channel['is_active'])
                ||(int)($channel['added_by']??0)<=0
                ||!is_array($lockedPost)
            ){
                $this->pdo->commit();
                return false;
            }
            if(!$this->cancelTelegramPinEffect($postId)){
                $this->pdo->commit();
                return false;
            }
            $this->pdo->commit();
            foreach($this->postMessageIds($lockedPost) as $messageId){
                try{
                    $this->telegram->call('deleteMessage',[
                        'chat_id'=>(string)$channel['chat_id'],
                        'message_id'=>$messageId,
                    ]);
                }catch(Throwable $exception){
                    if(str_contains(
                        mb_strtolower($exception->getMessage()),
                        'message to delete not found'
                    )){
                        continue;
                    }
                    throw $exception;
                }
            }
            $updated=$this->pdo->prepare(
                "UPDATE posted_posts
                 SET status='deleted',delete_at=NULL,delete_attempts=0,error_text=NULL
                 WHERE id=:id AND status='sent'"
            );
            $updated->execute(['id'=>$postId]);
            return $updated->rowCount()===1;
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }finally{
            $release=$this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name'=>$pinLockName]);
        }
    }

    private function handleMessage(array $message): void
    {
        if(($message['chat']['type']??null)==='private'&&PostedTelegramRootedAccess::enabled(PostedTelegramRootedAccess::current($this->config))&&!$this->telegramPolicyAllowOperator((int)($message['from']['id']??0)))return;
        if($this->handleTelegramTemplateMediaIngress($message))return;
        if($this->handleTelegramAlbumIngress($message))return;
        $chat = (array) ($message['chat'] ?? []);
        if (($chat['type'] ?? '') !== 'private') {
            return;
        }
        $from = (array) ($message['from'] ?? []);
        $userId = (int) ($from['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        if (!$this->telegramPolicyAllowOperator($userId)) return;
        $subscriptionNewUser=$this->telegramSubscriptionPaid()&&$this->user($userId)===null;
        $this->upsertUser($from);
        PostedAccounts::forTelegram($this->pdo, $userId);
        $user = $this->user($userId);
        if($this->handleTelegramTopQuotaInput($message,$user))return;
        if (!$this->isKnownActiveUser($user)) {
            $this->telegram->sendMessage($userId, 'Доступ не выдан. Напишите владельцу бота ваш Telegram ID: ' . $userId);
            return;
        }
        if($this->handleTelegramPaymentSupportMessage($userId,$message))return;
        if(array_key_exists('paid_media',$message)&&(string)($user['state']??'')!=='await_edit_forward'){
            $this->telegram->sendMessage($userId,'Копирование внешнего платного медиа пока недоступно. Подпись не будет сохранена вместо платного содержимого.');return;
        }
        if(array_key_exists('has_media_spoiler',$message)&&!is_bool($message['has_media_spoiler'])){
            $this->telegram->sendMessage($userId,'Некорректный признак спойлера. Медиа не сохранено.');return;
        }
        $text = trim((string) ($message['text'] ?? ''));
        if (
            $text === 'Меню'
            || preg_match(
                '/^\/start(?:@[A-Za-z0-9_]+)?(?:\s+([A-Za-z0-9_-]{1,64}))?$/u',
                $text,
                $startMatch
            ) === 1
        ) {
            if(isset($startMatch[1]))$this->trackTelegramSubscriptionReferral($userId,(string)$startMatch[1],$subscriptionNewUser);
            $this->clearState($userId);
            $this->showMainMenu($userId);
            return;
        }
        $plainMaxLinkCode = preg_match('/^(\d{6})$/D', $text, $maxLinkMatch) === 1;
        $maxLinkCommand = preg_match('/^\/maxlink(?:@[A-Za-z0-9_]+)?\s+(\d{6})$/D', $text, $maxLinkCommandMatch) === 1;
        if ($maxLinkCommand || ($plainMaxLinkCode && (string) ($user['state'] ?? '') === '')) {
            $code = (string) ($maxLinkCommand ? $maxLinkCommandMatch[1] : $maxLinkMatch[1]);
            try {
                require_once __DIR__ . '/max-bootstrap.php';
                $linkResult = posted_max_consume_link_code($userId, $code);
                if (!empty($linkResult['ok']) && !empty($linkResult['max_user_id'])) {
                    $maxUserId=(string)$linkResult['max_user_id'];
                    PostedAccounts::linkTelegramMax(
                        $this->pdo,
                        $userId,
                        $maxUserId
                    );
                    $this->pdo->prepare(
                        'UPDATE posted_max_users
                         SET linked_telegram_id=:telegram
                         WHERE max_user_id=:max_user
                           AND (linked_telegram_id IS NULL OR linked_telegram_id=:telegram_existing)'
                    )->execute([
                        'telegram'=>$userId,
                        'telegram_existing'=>$userId,
                        'max_user'=>$maxUserId,
                    ]);
                }
                $this->telegram->sendMessage(
                    $userId,
                    (string) ($linkResult['message'] ?? 'Не удалось связать аккаунты.')
                );
            } catch (Throwable $exception) {
                error_log('GitPosted MAX account link error: ' . $exception->getMessage());
                $this->telegram->sendMessage($userId, 'Не удалось связать аккаунты. Создайте новый код в боте GitPosted в МАКС.');
            }
            return;
        }
        $command = strtolower((string) preg_replace('/@[^\s]+$/', '', explode(' ', $text)[0] ?? ''));
        if ($command === '/create') { $this->showChannelPicker($userId, 'post_channel'); return; }
        if ($command === '/edit') { $this->startEditPost($userId); return; }
        if ($command === '/content') { $this->showContentPlanChannels($userId); return; }
        if ($command === '/templates') { $this->showTemplates($userId); return; }
        if ($command === '/settings') { $this->showSettings($userId); return; }
        if ($command === '/pay') { $this->showTelegramSubscription($userId); return; }
        if ($command === '/catalog') { $this->showEmojiCatalogV2($userId); return; }
        if ($command === '/addemoji') {
            $this->setState($userId,'await_emoji_add');
            $text = $this->customEmojiHtml('5258262708838472996', '🙂')
                . " <b>ДОБАВИТЬ ЭМОДЗИ</b>\n\nЧтобы добавить свои наборы эмодзи в <b>общий каталог</b>, отправьте боту сообщение содержащее эмодзи из ваших наборов либо ссылку на набор.";
            $this->telegram->sendMessage($userId, $text, [
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => [[['text' => '🚫 Выход', 'callback_data' => 'emoji_add:exit']]]],
            ]);
            return;
        }
        if ($command === '/help') {
            if ($this->telegramUiLanguage($userId) === 'en') {
                $supportUsername = $this->serviceAdminUsername();
                $text = PostedTelegramUi::englishHtml('help.body', ['support'=>$supportUsername !== '' ? '@'.$supportUsername : 'контакт владельца пока не указан']);
                $newsUsername = $this->serviceNewsUsername();
                if ($newsUsername !== '') $text .= "\n\n" . PostedTelegramUi::englishHtml('help.news', ['news'=>'@'.$newsUsername]);
                $this->telegram->sendMessage($userId, $text, ['parse_mode'=>'HTML']);
                return;
            }
            $info = $this->customEmojiHtml('5258503720928288433', 'ℹ️');
            $check = $this->customEmojiHtml('5260726538302660868', '✅');
            $support = $this->serviceAdminLabel();
            $text = $info . " <b>ПОМОЩЬ</b>\n    \n"
                . "Если у вас возникли вопросы по работе сервиса или вы нашли ошибку, пишите нам в поддержку " . $support . ".";
            $newsUsername = $this->serviceNewsUsername();
            if ($newsUsername !== '') {
                $text .= "\n\n@" . htmlspecialchars($newsUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . " - новостной канал сервиса, в котором мы рассказываем о всех новинках сервиса и выкладываем полезные материалы.";
            }
            $text .= "\n\n<b>Инструкции по разделам сервиса:</b>\n\n";
            $helpRows=[];
            foreach (PostedTelegramUi::helpLinks($this->serviceBaseUrl()) as $helpLink) {
                $helpRows[]=$check . ' <a href="' . htmlspecialchars($helpLink['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                    . htmlspecialchars($helpLink['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            }
            $text .= implode("\n", $helpRows);
            $this->telegram->sendMessage($userId, $text, ['parse_mode' => 'HTML']);
            return;
        }
        if ($command === '/lang') {
            $this->telegram->sendMessage(
                $userId,
                $this->telegramUiText($userId, 'language.choose', $this->customEmojiHtml('5260512129240276089', '📚') . ' Выберите язык.'),
                [
                    'parse_mode' => 'HTML',
                    'reply_markup' => ['inline_keyboard' => [[['text' => '🇷🇺 Русский', 'callback_data' => 'lang:set:ru']], [['text' => '🇬🇧 English', 'callback_data' => 'lang:set:en']]]],
                ]
            );
            return;
        }
        if ($command === '/support') {
            $support = $this->serviceAdminLabel();
            $this->telegram->sendMessage(
                $userId,
                $this->customEmojiHtml('5258503720928288433', 'ℹ️') . ' Если у вас возникли вопросы по работе GitPosted, напишите в поддержку: ' . $support . '.',
                ['parse_mode' => 'HTML']
            );
            return;
        }
        if ($command === '/privacy') {
            $this->telegram->sendMessage(
                $userId,
                $this->customEmojiHtml('5258503720928288433', 'ℹ️')
                    . ' <a href="' . htmlspecialchars(
                        $this->serviceBaseUrl() . '/privacy/',
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) . '">Политика конфиденциальности GitPosted</a>.',
                [
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                ]
            );
            return;
        }
        if ($command === '/maxlink') {
            $this->telegram->sendMessage(
                $userId,
                'Откройте настройки аккаунта в GitPosted для МАКС, создайте одноразовый код и отправьте сюда командой /maxlink 123456.'
            );
            return;
        }
        if ($this->handleTelegramSubscriptionInput($userId,$message)) return;
        if ($this->handleTelegramPostLinkMessage($userId, $message)) return;
        $menuText = PostedTelegramUi::mainButtonAction($text);
        $state = (string) ($user['state'] ?? '');
        if($state==='collecting_media_group'&&in_array($menuText,['Создать пост','Изменить пост','Контент-план','Настройки','Шаблоны','Каталог эмодзи'],true)){
            // A recognized reply-menu label navigates after the album. Preserve
            // its draft/vector/receipts and never clear a newer input snapshot.
            if($this->pdo->inTransaction()||!array_key_exists('state_data',$user))return;
            $navigation=$this->pdo->prepare("UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND is_active=1 AND state='collecting_media_group' AND state_data <=> CAST(? AS JSON)");
            $navigation->execute([$userId,$user['state_data']]);
            if($navigation->rowCount()!==1)return;
            $state='';
        }
        if($state==='await_post_content'&&in_array($menuText,['Создать пост','Изменить пост','Контент-план','Настройки','Шаблоны','Каталог эмодзи'],true)){
            // The reply menu also navigates while awaiting the next post's content.
            // Preserve every existing draft and let a newer input snapshot win.
            if($this->pdo->inTransaction()||!array_key_exists('state_data',$user))return;
            $navigation=$this->pdo->prepare("UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND is_active=1 AND state='await_post_content' AND state_data <=> CAST(? AS JSON)");
            $navigation->execute([$userId,$user['state_data']]);
            if($navigation->rowCount()!==1)return;
            $state='';
        }
        if ($state !== '') {
            $this->handleStateMessage($userId, $state, $this->stateData($user), $message);
            return;
        }
        if ($menuText === (string) self::MAIN_KEYBOARD[0][1]['text']) {
            $this->startEditPost($userId);
            return;
        }
        match ($menuText) {
            'Создать пост' => $this->showChannelPicker($userId, 'post_channel'),
            'Изменить пост' => $this->showContentPlanChannels($userId),
            'Контент-план' => $this->showContentPlanChannels($userId),
            'Шаблоны' => $this->showTemplates($userId),
            'Каталог эмодзи' => $this->showEmojiCatalogV2($userId),
            'Настройки' => $this->showSettings($userId),
            'Мои каналы' => $this->showMyChannels($userId),
            default => $this->showMainMenu($userId),
        };
    }

    private function handleCallback(array $callback): void
    {
        $from = (array) ($callback['from'] ?? []);
        $userId = (int) ($from['id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');
        if ($userId <= 0 || $data === '') {
            return;
        }
        if (str_starts_with($data, 'reaction:') || str_starts_with($data, 'hidden:')) {
            $this->handlePublicInteractiveButton($callback, $data);
            return;
        }
        if (!$this->telegramPolicyAllowOperator($userId, $callback)) return;
        if($this->handleTelegramPublicButtonResume($callback))return;
        if($this->handleTelegramPublicEditStatusCallback($callback))return;
        if($this->handleTelegramCpDeleteConfirmation($callback))return;
        $this->upsertUser($from);
        $user = $this->user($userId);
        if (!$this->isKnownActiveUser($user)) {
            $this->answerCallback($callback, 'Доступ не выдан.');
            return;
        }
        if($this->handleTelegramTemplateMediaCallback($callback))return;
        // Calendar read routes carry a CHANNEL ID; never treat it as an unrelated post ID in the album guard.
        if(preg_match('/\Atop:(?:graph|month|slot|advertiser|custom):/D',$data)===1&&$this->handleTelegramTopCallback($callback))return;
        if($this->handleTelegramAlbumPendingCallback($callback))return;
        if($this->handleTelegramAlbumButtonCallback($callback))return;
        if (str_starts_with($data, 'lang:set:')) {
            $language = substr($data, strlen('lang:set:'));
            if (!in_array($language, ['ru', 'en'], true)) {
                $this->answerCallback($callback);
                return;
            }
            PostedTelegramUi::saveLanguage($this->pdo, $userId, $language);
            $this->telegramUiLanguageCache[$userId] = $language;
            $this->answerCallback($callback);
            $this->showMainMenu($userId,false);
            return;
        }
        if(str_starts_with($data,'sub:')){
            $this->answerCallback($callback);
            $this->handleTelegramSubscriptionCallback($userId,$data,$this->callbackTarget($callback));
            return;
        }
        if(str_starts_with($data,'mopt:')||str_starts_with($data,'mprice:')||str_starts_with($data,'draft:add_media:')){
            if($this->handleTelegramMediaOptionsCallback($userId,$data,$this->callbackTarget($callback))){$this->answerCallback($callback);return;}
        }
        // Transfer buttons must validate their nonce before touching unrelated
        // work, including an album which arrived after an old confirmation.
        if (str_starts_with($data, 'transfer:')) {
            $this->answerCallback($callback);
            $this->handleTelegramAccountTransferCallback($userId, $data, $this->callbackTarget($callback));
            return;
        }
        // Catalogue navigation and filters do not consume an unrelated draft/album input.
        if (str_starts_with($data, 'post_option:')) {
            $this->answerCallback($callback);
            $this->handleTelegramPostOptionsCallback($userId,$data,$this->callbackTarget($callback));
            return;
        }
        if (str_starts_with($data, 'preview_media:')) {
            $this->answerCallback($callback);
            $this->handleTelegramPreviewMediaCallback($userId, $data, $this->callbackTarget($callback));
            return;
        }
        if (str_starts_with($data, 'mp:')) {
            $this->answerCallback($callback);
            $this->handleTelegramMultipostCallback($userId, $data, $this->callbackTarget($callback));
            return;
        }
        if (str_starts_with($data, 'emoji_filter:')) {
            $this->answerCallback($callback);
            $this->handleTelegramEmojiFilterCallback($userId, $data, $this->callbackTarget($callback));
            return;
        }
        if (str_starts_with($data, 'emoji:')) {
            $this->answerCallback($callback);
            $parts = explode(':', $data);
            $this->showEmojiCatalogV2(
                $userId,
                (string) ($parts[1] ?? 'quality'),
                max(1, (int) ($parts[2] ?? 1)),
                $this->callbackTarget($callback),
                (string) ($parts[3] ?? '') === 'more'
            );
            return;
        }
        if($this->handleTelegramAuthorityPauseCallback($callback))return;
        if($this->handleTelegramTopCallback($callback))return;
        if($this->handleTelegramTopQuotaCallback($callback))return;
        if($this->handleTelegramDraftUrlCallback($callback))return;
        if($this->handleTelegramDraftMoreCallback($callback))return;
        if($this->handleTelegramDraftPanelCallback($callback))return;
        if($this->handleTelegramPublishedAlbumCallback($callback))return;
        if($this->handleTelegramPublishedSingleCallback($callback))return;
        if ((string) ($user['state'] ?? '') === 'collecting_media_group') {
            $this->clearState($userId);
        }
        $this->answerCallback($callback);
        $target = $this->callbackTarget($callback);
        if ($this->handleTelegramPostLinkCallback($userId, $data, $target)) return;

        if ($data === 'emoji_add:exit') {
            $this->clearState($userId);
            $message = (array) ($callback['message'] ?? []);
            $chatId = (int) (($message['chat']['id'] ?? 0));
            $messageId = (int) ($message['message_id'] ?? 0);
            if ($chatId && $messageId) { try { $this->telegram->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]); } catch (Throwable) {} }
            return;
        }
        if ($data === 'main') {
            $this->clearState($userId);
            $this->showMainMenu($userId);
            return;
        }
        if ($data === 'tpl_root') {
            $this->clearState($userId);
            $this->showTemplates($userId);
            return;
        }
        if (str_starts_with($data, 'series:')) {
            $this->handlePublicationPlanCallback($userId, $data, $target);
            return;
        }
        if (str_starts_with($data, 'cp:')) {
            $this->handleContentPlanCallback($userId, $data, $target);
            return;
        }
        if ($data === 'post_channels') {
            $this->showChannelPicker($userId, 'post_channel', $target);
            return;
        }
        if (str_starts_with($data, 'post_folder:')) {
            $this->showFolderChannelPicker($userId, (int) substr($data, strlen('post_folder:')), $target);
            return;
        }
        if (str_starts_with($data, 'post_channel:')) {
            $channelId = (int) substr($data, strlen('post_channel:'));
            if (!$this->can($userId, $channelId, 'post')) {
                $this->telegram->sendMessage($userId, 'Нет права публиковать в этом канале.');
                return;
            }
            $this->startPostForChannel($userId, $channelId, $target);
            return;
        }
        if (preg_match('/^post_category:(\d+):(\d+)$/D', $data, $match) === 1) {
            $channelId=(int)$match[1];$categoryId=(int)$match[2];
            if(!$this->can($userId,$channelId,'post')||!$this->managerCategory($userId,$channelId,$categoryId,true)){
                $this->telegram->sendMessage($userId,'Рекламная категория недоступна.');return;
            }
            $this->setState($userId,'await_post_content',['channel_id'=>$channelId,'manager_category_id'=>$categoryId]);
            $category=$this->managerCategory($userId,$channelId,$categoryId,true);
            $this->sendOrEditMessage($userId,'Категория: <b>'.htmlspecialchars((string)$category['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</b>\nОтправьте содержимое рекламного поста.",[
                'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>[[['text'=>'← Выбрать другую категорию','callback_data'=>'post_channel:'.$channelId]]]],
            ],$target);
            return;
        }
        if (str_starts_with($data, 'draft:')) {
            $this->handleDraftCallback($userId, $data, $target);
            return;
        }
        if (str_starts_with($data, 'edit:')) {
            $this->handleEditCallback($userId, $data);
            return;
        }
        if (str_starts_with($data, 'tpl_scope:')) {
            $this->showTemplateList($userId, (int) substr($data, strlen('tpl_scope:')));
            return;
        }
        if (str_starts_with($data, 'tpl_create:')) {
            $scopeId = (int) substr($data, strlen('tpl_create:'));
            $this->startTemplateCreate($userId, $scopeId);
            return;
        }
        if (str_starts_with($data, 'tpl_view:')) {
            $this->showTemplateView($userId, (int) substr($data, strlen('tpl_view:')));
            return;
        }
        if (str_starts_with($data, 'tpl_use:')) {
            $this->useTemplate($userId, (int) substr($data, strlen('tpl_use:')));
            return;
        }
        if (str_starts_with($data, 'tpl_ch:')) {
            $parts = explode(':', $data);
            $this->startTemplateForChannel($userId,(int)($parts[1]??0),(int)($parts[2]??0));
            return;
        }
        if (str_starts_with($data,'tpl_cat:')) {
            $parts=explode(':',$data);$templateId=(int)($parts[1]??0);$channelId=(int)($parts[2]??0);$categoryId=(int)($parts[3]??0);
            if(!$this->managerCategory($userId,$channelId,$categoryId,true)){$this->telegram->sendMessage($userId,'Рекламная компания недоступна.');return;}
            $this->createDraftFromTemplate($userId,$templateId,$channelId,$categoryId);
            return;
        }
        if (str_starts_with($data, 'tpl_del:')) {
            $this->deleteTemplate($userId, (int) substr($data, strlen('tpl_del:')));
            return;
        }
        if(str_starts_with($data,'tpl_edit_text:')){$this->startTemplateEditInput($userId,(int)substr($data,strlen('tpl_edit_text:')),'await_template_edit_text','Отправьте боту новый текст шаблона.');return;}
        if(str_starts_with($data,'tpl_edit_media:')){$this->startTemplateEditInput($userId,(int)substr($data,strlen('tpl_edit_media:')),'await_template_edit_media','Отправьте боту новое медиа с подписью или без неё.');return;}
        if(str_starts_with($data,'tpl_edit_buttons:')){$this->startTemplateEditInput($userId,(int)substr($data,strlen('tpl_edit_buttons:')),'await_template_edit_buttons',$this->buttonsHelpText());return;}
        if(str_starts_with($data,'tpl_done:')){$this->finishTemplateEdit($userId,(int)substr($data,strlen('tpl_done:')));return;}
        if(str_starts_with($data,'tpl_cancel:')){$this->cancelTemplateEdit($userId,(int)substr($data,strlen('tpl_cancel:')));return;}
        if(str_starts_with($data,'tpl_editor:')){$this->clearState($userId);$this->showTemplateEditor($userId,(int)substr($data,strlen('tpl_editor:')));return;}
        if (str_starts_with($data, 'settings_channel:')) {
            $this->clearState($userId);
            $this->showChannelSettings($userId, (int) substr($data, strlen('settings_channel:')), $target);
            return;
        }
        if (str_starts_with($data, 'channel_editors:')) {
            $parts = explode(':', $data);
            $this->showChannelEditors(
                $userId,
                (int) ($parts[1] ?? 0),
                max(0, (int) ($parts[2] ?? 0)),
                $target
            );
            return;
        }
        if (str_starts_with($data, 'channel_managers:')) {
            $parts = explode(':', $data);
            $this->showChannelManagers(
                $userId,
                (int) ($parts[1] ?? 0),
                max(0, (int) ($parts[2] ?? 0)),
                $target
            );
            return;
        }
        if (str_starts_with($data, 'channel_leave:')) {
            $this->confirmLeaveChannel(
                $userId,
                (int) substr($data, strlen('channel_leave:')),
                $target
            );
            return;
        }
        if (str_starts_with($data, 'channel_leave_yes:')) {
            $parts = explode(':', $data);
            $this->leaveChannel(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                $target
            );
            return;
        }
        if ($data === 'noop') { return; }
        if (str_starts_with($data, 'channel_refresh:')) {
            $channelId = (int) substr($data, strlen('channel_refresh:'));
            $this->refreshChannelData($userId, $channelId);
            $this->showChannelSettings($userId, $channelId, $target);
            return;
        }
        if (str_starts_with($data, 'channel_schedule:')) { $this->showChannelSchedule($userId, (int) substr($data, strlen('channel_schedule:')), $target); return; }
        if (str_starts_with($data, 'channel_post_settings:')) {
            $this->clearState($userId);
            $this->showChannelPostSettings($userId, (int) substr($data, strlen('channel_post_settings:')), $target);
            return;
        }
        if (str_starts_with($data, 'channel_ad_settings:')) {
            $this->clearState($userId);
            $this->showChannelAdSettings($userId, (int) substr($data, strlen('channel_ad_settings:')), $target);
            return;
        }
        if (str_starts_with($data, 'chpost:')) { $parts=explode(':',$data); $this->handleChannelPostSetting($userId,(int)($parts[2]??0),(string)($parts[1]??''),$target,$parts[3]??null); return; }
        if (str_starts_with($data, 'chad:')) { $parts=explode(':',$data); $field=(string)($parts[1]??''); $channelId=(int)($parts[2]??0); if($field==='settimer') $this->setChannelAdTimer($userId,$channelId,(int)($parts[3]??0),$target); else $this->handleChannelAdSetting($userId,$channelId,$field,$target,$parts[3]??null); return; }
        if (str_starts_with($data, 'folder:')) { $this->handleFolderCallback($userId,$data,$target); return; }
        if ($data === 'settings:add_channel') {
            $this->startAddChannel($userId, $target);
            return;
        }
        if ($data === 'settings:admins') {
            $this->showAdminChannels($userId, $target);
            return;
        }
        if ($data === 'settings:managers') {
            $this->showManagerChannels($userId, $target);
            return;
        }
        if ($data === 'settings:my_channels') {
            $this->showMyChannels($userId, $target);
            return;
        }
        if (str_starts_with($data, 'channel_archive:')) {
            $this->archiveChannel($userId, (int) substr($data, strlen('channel_archive:')), $target);
            return;
        }
        if (str_starts_with($data, 'channel_disable:')) {
            $this->confirmDisableChannel($userId, (int) substr($data, strlen('channel_disable:')), $target);
            return;
        }
        if (str_starts_with($data, 'channel_disable_yes:')) {
            $this->disableChannel($userId, (int) substr($data, strlen('channel_disable_yes:')), $target);
            return;
        }
        if ($data === 'settings:timezone') {
            $this->showTimezonePicker($userId, $target);
            return;
        }
        if (str_starts_with($data, 'tz:set:')) {
            $this->saveTimezone($userId, substr($data, strlen('tz:set:')), $target);
            return;
        }
        if ($data === 'settings:advanced') {
            $this->clearState($userId);
            $this->showAdvancedSettings($userId, $target);
            return;
        }
        if (str_starts_with($data, 'settings:adv:')) {
            $advancedParts=explode(':',substr($data,strlen('settings:adv:')),2);
            $advancedKey = (string)($advancedParts[0]??'');
            if ($advancedKey === 'folders') { $this->clearState($userId); $this->showFolders($userId, $target); }
            else $this->toggleAdvancedSetting(
                $userId,
                $advancedKey,
                $target,
                in_array((string)($advancedParts[1]??''),['0','1'],true)
                    ?(int)$advancedParts[1]
                    :null
            );
            return;
        }
        if ($data === 'settings:root') {
            $this->clearState($userId);
            $this->showSettings($userId, $target);
            return;
        }
        if (str_starts_with($data, 'admin_edit:')) {
            $parts = explode(':', $data);
            $this->showRightsPicker(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                max(0, (int) ($parts[3] ?? 0)),
                $target
            );
            return;
        }
        if (str_starts_with($data, 'admin_add:')) {
            $channelId = (int) substr($data, strlen('admin_add:'));
            if (!$this->isChannelOwner($userId, $channelId)) {
                $this->telegram->sendMessage($userId, 'Только владелец канала может управлять редакторами.');
                return;
            }
            $this->setState($userId, 'await_admin_id', ['channel_id' => $channelId]);
            $this->sendOrEditMessage(
                $userId,
                "👤 ДОБАВЛЕНИЕ РЕДАКТОРА\n\nОтправьте числовой Telegram ID пользователя или перешлите сообщение от него без скрытого автора.\n\n@username не используется для выдачи прав: Telegram позволяет передать ник другому человеку.",
                ['reply_markup' => ['inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'settings_channel:' . $channelId]]]]],
                $target
            );
            return;
        }
        if (str_starts_with($data, 'manager_add:')) {
            $channelId = (int) substr($data, strlen('manager_add:'));
            if (!$this->isChannelOwner($userId, $channelId)) {
                $this->telegram->sendMessage($userId, 'Только владелец канала может управлять рекламными менеджерами.');
                return;
            }
            $this->setState($userId, 'await_manager_id', ['channel_id' => $channelId]);
            $this->sendOrEditMessage(
                $userId,
                "💼 ДОБАВЛЕНИЕ РЕКЛАМНОГО МЕНЕДЖЕРА\n\nОтправьте числовой Telegram ID пользователя или перешлите сообщение от него без скрытого автора.\n\n@username не используется для выдачи прав: Telegram позволяет передать ник другому человеку.",
                ['reply_markup'=>['inline_keyboard'=>[[
                    ['text'=>'← Назад','callback_data'=>'channel_managers:'.$channelId.':0'],
                ]]]],
                $target
            );
            return;
        }
        if (str_starts_with($data, 'admin_revoke:')) {
            $parts = explode(':', $data);
            $this->confirmRevokeAdminRights(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                max(0, (int) ($parts[3] ?? 0)),
                $target
            );
            return;
        }
        if (str_starts_with($data, 'admin_revoke_yes:')) {
            $parts = explode(':', $data);
            $this->revokeAdminRights(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                max(0, (int) ($parts[3] ?? 0)),
                $target
            );
            return;
        }
        if (str_starts_with($data, 'manager_revoke:')) {
            $parts = explode(':', $data);
            $this->confirmRevokeAdminRights(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                max(0, (int) ($parts[3] ?? 0)),
                $target,
                true
            );
            return;
        }
        if (str_starts_with($data, 'manager_revoke_yes:')) {
            $parts = explode(':', $data);
            $this->revokeAdminRights(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                max(0, (int) ($parts[3] ?? 0)),
                $target,
                true
            );
            return;
        }
        if (str_starts_with($data, 'admin_rights:')) {
            $parts = explode(':', $data);
            $this->saveAdminRights(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                (string) ($parts[3] ?? 'post'),
                max(0, (int) ($parts[4] ?? 0)),
                $target
            );
            return;
        }
        if (str_starts_with($data, 'admin_manager_field:')) {
            $parts = explode(':', $data);
            $this->startManagerFieldInput(
                $userId,
                (int) ($parts[1] ?? 0),
                (int) ($parts[2] ?? 0),
                (string) ($parts[3] ?? ''),
                max(0, (int) ($parts[4] ?? 0)),
                $target
            );
            return;
        }
        if(preg_match('/^manager_categories:(\d+):(\d+):(\d+)$/D',$data,$m)===1){$this->showManagerCategories($userId,(int)$m[1],(int)$m[2],(int)$m[3],$target);return;}
        if(preg_match('/^manager_category_add:(\d+):(\d+):(\d+)$/D',$data,$m)===1){
            if(!$this->isChannelOwner($userId,(int)$m[1]))return;
            $this->setState($userId,'await_manager_category_name',['channel_id'=>(int)$m[1],'manager_id'=>(int)$m[2],'page'=>(int)$m[3]]);
            $this->sendOrEditMessage($userId,'Отправьте название рекламной компании (до 100 символов).',[],$target);return;
        }
        if(preg_match('/^manager_category:(\d+):(\d+):(\d+):(\d+)$/D',$data,$m)===1){$this->showManagerCategory($userId,(int)$m[1],(int)$m[2],(int)$m[3],(int)$m[4],$target);return;}
        if(preg_match('/^manager_category_field:(\d+):(\d+):(\d+):(\d+):(posts|pins)$/D',$data,$m)===1){
            if(!$this->isChannelOwner($userId,(int)$m[1]))return;
            $this->setState($userId,'await_manager_category_field',['channel_id'=>(int)$m[1],'manager_id'=>(int)$m[2],'category_id'=>(int)$m[3],'page'=>(int)$m[4],'field'=>$m[5]]);
            $this->sendOrEditMessage($userId,'Отправьте новый лимит: целое число или «безлимит».',[],$target);return;
        }
        if(preg_match('/^manager_category_delete:(\d+):(\d+):(\d+):(\d+)$/D',$data,$m)===1){
            $this->deactivateManagerCategory(
                $userId,
                (int)$m[1],
                (int)$m[2],
                (int)$m[3]
            );
            $this->showManagerCategories($userId,(int)$m[1],(int)$m[2],(int)$m[4],$target);return;
        }
    }

    private function handleMyChatMember(array $event): void
    {
        $chat = (array) ($event['chat'] ?? []);
        if (($chat['type'] ?? '') !== 'channel') {
            return;
        }
        $new = (array) ($event['new_chat_member'] ?? []);
        $subject = (array) ($new['user'] ?? []);
        $subjectId = (int) ($subject['id'] ?? 0);
        $botId = $this->telegramBotId();
        if (!is_int($subject['id']??null)||($subject['is_bot']??null)!==true||$subjectId <= 0 || $botId <= 0 || $subjectId !== $botId) {
            error_log('GitPosted ignored my_chat_member update with an invalid bot identity.');
            return;
        }
        $status = (string) ($new['status'] ?? '');
        if(!in_array($status,['creator','administrator','member','restricted','left','kicked'],true))return;
        if($status==='administrator'&&!is_bool($new['can_post_messages']??null))return;
        $from = (array) ($event['from'] ?? []);
        $fromId = (int) ($from['id'] ?? 0);
        if ($fromId > 0 && $this->telegramPolicyOperatorAllowed($fromId)) {
            $this->upsertUser($from);
        }
        $previousChannel = $this->channelByChatId((string)($chat['id']??''));
        $canPost = !empty($new['can_post_messages']);
        $channelSql =
            "INSERT INTO posted_channels
                (chat_id, title, username, is_active, bot_status, bot_can_post, bot_can_edit, bot_can_delete, added_by)
             VALUES (:chat_id, :title, :username, :insert_active, :bot_status, :can_post, :can_edit, :can_delete, :added_by)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                username = VALUES(username),
                bot_status = VALUES(bot_status),
                bot_can_post = VALUES(bot_can_post),
                bot_can_edit = VALUES(bot_can_edit),
                bot_can_delete = VALUES(bot_can_delete),
                is_active = IF(
                    admin_blocked=1,
                    0,
                    IF(COALESCE(added_by,0)>0,:observed_active,0)
                )";
        $observedActive = in_array($status, ['administrator', 'member'], true);
        $channelParameters = [
            'chat_id' => (string) ($chat['id'] ?? ''),
            'title' => (string) ($chat['title'] ?? 'Канал'),
            'username' => isset($chat['username']) ? (string) $chat['username'] : null,
            'insert_active' => 0,
            'observed_active' => $observedActive ? 1 : 0,
            'bot_status' => $status,
            'can_post' => $canPost ? 1 : 0,
            'can_edit' => !empty($new['can_edit_messages']) ? 1 : 0,
            'can_delete' => !empty($new['can_delete_messages']) ? 1 : 0,
            'added_by' => null,
        ];
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        try {
            $this->pdo->prepare($channelSql)->execute($channelParameters);
            if (in_array($status, ['left', 'kicked'], true)) {
                $removedChannel = $this->channelByChatId((string) ($chat['id'] ?? ''));
                if (is_array($removedChannel)) $this->clearTelegramChannelTransferReceipt((int) $removedChannel['id']);
            }
            $s=$this->pdo->prepare('SELECT * FROM posted_channels WHERE chat_id=? LIMIT 1 FOR UPDATE');$s->execute([(string)$chat['id']]);$current=$s->fetch();
            if(is_array($current)&&(int)($current['added_by']??0)>0){
                if($status!=='administrator'||($new['can_post_messages']??null)!==true)PostedTelegramAuthorityPause::pauseLocked($this->pdo,$current,in_array($status,['left','kicked'],true)?'bot_removed':'bot_rights_lost');
                elseif(PostedTelegramAuthorityPause::blocked($this->pdo,$current,true))$this->pdo->prepare('UPDATE posted_channels SET is_active=0 WHERE id=?')->execute([(int)$current['id']]);
            }
            if ($ownsTransaction) PostedTelegramRootedAccess::commit($this->pdo);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) PostedTelegramRootedAccess::rollback($this->pdo);
            throw $exception;
        }
        if (
            is_array($previousChannel)
            && !empty($previousChannel['bot_can_post'])
            && !$canPost
            && (int)($previousChannel['added_by']??0)>0
        ) {
            try {
                $this->telegram->sendMessage(
                    (int)$previousChannel['added_by'],
                    'Публикации в канал «'.(string)($previousChannel['title']??'Канал')
                    .'» приостановлены: у бота больше нет права публиковать сообщения. '
                    .'Посты, даты и повторы сохранены. После возврата права требуется отдельная проверка и подтверждение возобновления.'
                );
            } catch (Throwable $exception) {
                error_log('GitPosted could not notify about lost Telegram publish rights: '.$exception->getMessage());
            }
        }
    }

    private function handleChatMember(array $event): void
    {
        $chat = (array) ($event['chat'] ?? []);
        if (($chat['type'] ?? '') !== 'channel') {
            return;
        }
        $chatId = trim((string) ($chat['id'] ?? ''));
        $new = (array) ($event['new_chat_member'] ?? []);
        $member = (array) ($new['user'] ?? []);
        $memberId = (int) ($member['id'] ?? 0);
        if ($chatId === '' || !is_int($member['id']??null) || $memberId <= 0 || ($member['is_bot']??null)!==false) {
            return;
        }
        $channel = $this->channelByChatId($chatId);
        if (!is_array($channel) || (int) ($channel['added_by'] ?? 0) !== $memberId) {
            return;
        }
        $status = (string) ($new['status'] ?? '');
        if(!in_array($status,['creator','administrator','member','restricted','left','kicked'],true))return;
        $isAdministrator = in_array($status, ['creator', 'administrator'], true);
        $this->telegramChannelAdminCache[$this->telegramChannelAdminCacheKey($chatId, $memberId)] = $isAdministrator;
        if ($isAdministrator) {
            return;
        }
        // A confirmed in-app transfer delegates this binding independently of
        // the recipient's human Telegram role. Bot authority is checked at use.
        if ($this->telegramTransferredChannelOwnerReceipt($channel) !== null) return;
        if (!$this->freezeTelegramChannelOwnership((int) $channel['id'], $memberId)) {
            return;
        }
        try {
            $this->telegram->sendMessage(
                $memberId,
                'Доступ к каналу «' . (string) ($channel['title'] ?? $chatId)
                . '» остановлен: вы больше не являетесь его владельцем или администратором в Telegram. '
                . 'Посты, даты и повторы сохранены. После возврата прав откройте проверку сохранённых публикаций и подтвердите возобновление.'
            );
        } catch (Throwable $exception) {
            error_log('GitPosted could not notify a former Telegram channel owner: ' . $exception->getMessage());
        }
    }

    private function handleStateMessage(int $userId, string $state, array $data, array $message): void
    {
        if($this->handleTelegramPublishedSingleInput($userId,$state,$data,$message))return;
        if($this->handleTelegramAlbumButtonInput($userId,$state,$data))return;
        if($state==='await_paid_media_price'){$this->handleTelegramPaidMediaPriceInput($userId,$data,$message);return;}
        if ($state === 'await_draft_preview_media') {
            $this->handleTelegramDraftPreviewMediaInput($userId, $data, $message);
            return;
        }
        if ($this->handleTelegramAccountTransferInput($userId, $state, $data, $message)) return;
        if ($state === 'await_publication_plan') {
            $this->handlePublicationPlanInput($userId, $data, trim((string)($message['text'] ?? '')));
            return;
        }
        if($state==='await_emoji_add'){
            $source=trim((string)($message['text']??$message['caption']??''));
            $setNames=[];
            if(preg_match_all('~(?:https?://)?t\.me/addemoji/([A-Za-z0-9_]{5,190})~iu',$source,$matches)){
                foreach((array)($matches[1]??[]) as $name)$setNames[]=(string)$name;
            }
            $customEmojiIds=[];
            foreach([...(array)($message['entities']??[]),...(array)($message['caption_entities']??[])] as $entity){
                if(is_array($entity)&&($entity['type']??'')==='custom_emoji'&&!empty($entity['custom_emoji_id']))$customEmojiIds[]=(string)$entity['custom_emoji_id'];
            }
            if($customEmojiIds!==[]){
                try{
                    $result=$this->telegram->call('getCustomEmojiStickers',['custom_emoji_ids'=>array_values(array_unique($customEmojiIds))]);
                    foreach((array)($result['result']??[]) as $sticker)if(is_array($sticker)&&!empty($sticker['set_name']))$setNames[]=(string)$sticker['set_name'];
                }catch(Throwable){}
            }
            $setNames=array_values(array_unique(array_filter($setNames,static fn(string $name):bool=>preg_match('/^[A-Za-z0-9_]{5,190}$/',$name)===1)));
            if($setNames===[]){
                $this->telegram->sendMessage($userId,'Не удалось определить набор. Отправьте ссылку вида https://t.me/addemoji/Name или сообщение с пользовательским эмодзи.');
                return;
            }
            $stmt=$this->pdo->prepare("INSERT INTO posted_emoji_submissions(user_id,set_name,source_text,status) VALUES(:user,:set_name,:source,'pending') ON DUPLICATE KEY UPDATE source_text=VALUES(source_text),status=IF(status='approved','approved','pending'),updated_at=NOW()");
            foreach($setNames as $setName)$stmt->execute(['user'=>$userId,'set_name'=>$setName,'source'=>$source!==''?$source:null]);
            $this->telegram->sendMessage($userId,'Набор отправлен на добавление в каталог. Можно прислать ещё один или нажать «Выход».',[
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'🚫 Выход','callback_data'=>'emoji_add:exit']]]],
            ]);
            return;
        }
        if ($state === 'await_channel_autosignature') {
            $channelId = (int) ($data['channel_id'] ?? 0);
            $scope = (string) ($data['scope'] ?? 'post');
            if (!$this->can($userId,$channelId,'manage_admins')) { $this->clearState($userId); return; }
            $content = $this->telegramTextInputContent($message);
            $value = (string) ($content['text'] ?? '');
            if(trim($value)===''){$this->telegram->sendMessage($userId,'Отправьте текст для автоподписи.');return;}
            $control = mb_strtolower(trim($value));
            if ($control === '-' || $control === 'нет') {
                $value = '';
                $content['text_entities'] = [];
            }
            $column = $scope === 'ad' ? 'ad_autosignature' : 'post_autosignature';
            $entitiesColumn = $scope === 'ad'
                ? 'ad_autosignature_entities_json'
                : 'post_autosignature_entities_json';
            $this->withOwnedTelegramChannelLock(
                $userId,
                $channelId,
                function () use ($column, $entitiesColumn, $value, $content, $channelId): void {
                    $this->pdo->prepare(
                        "UPDATE posted_channels
                         SET {$column}=:value,{$entitiesColumn}=:entities
                         WHERE id=:id"
                    )->execute([
                        'value'=>trim($value)!==''?$value:null,
                        'entities'=>$value!=='' && (array) $content['text_entities'] !== []
                            ?$this->encodeJson((array) $content['text_entities'])
                            :null,
                        'id'=>$channelId,
                    ]);
                }
            );
            $this->clearState($userId);
            $scope === 'ad' ? $this->showChannelAdSettings($userId,$channelId) : $this->showChannelPostSettings($userId,$channelId);
            return;
        }
        if ($state === 'await_channel_buttons') {
            $channelId = (int) ($data['channel_id'] ?? 0);
            if (!$this->can($userId,$channelId,'manage_admins')) { $this->clearState($userId); return; }
            $buttons = $this->parseButtonsInput($message);
            if($buttons===null){$this->telegram->sendMessage($userId,$this->buttonsHelpText());return;}
            $this->replaceChannelDefaultButtons($userId, $channelId, 'url', $buttons);
            $this->clearState($userId);
            $this->showChannelPostSettings($userId,$channelId);
            return;
        }
        if ($state === 'await_channel_reactions') {
            $channelId = (int) ($data['channel_id'] ?? 0);
            if (!$this->can($userId,$channelId,'manage_admins')) { $this->clearState($userId); return; }
            $buttons = $this->parseReactionButtonsInput(0,$message);
            if($buttons===null){$this->telegram->sendMessage($userId,'Отправьте боту список реакций, разделяя их символом «/».');return;}
            $this->replaceChannelDefaultButtons($userId, $channelId, 'reaction', $buttons);
            $this->clearState($userId);
            $this->showChannelPostSettings($userId,$channelId);
            return;
        }
        if ($state === 'await_channel_hidden') {
            $channelId = (int) ($data['channel_id'] ?? 0);
            if (!$this->can($userId,$channelId,'manage_admins')) { $this->clearState($userId); return; }
            $parts = preg_split('/\R+/', trim((string) ($message['text'] ?? '')), 2) ?: [];
            $label = trim((string) ($parts[0] ?? ''));
            $hidden = trim((string) ($parts[1] ?? ''));
            if ($label === '' || $hidden === '') {
                $this->telegram->sendMessage($userId, 'Первая строка — название кнопки, со второй строки — скрытый текст.');
                return;
            }
            if(mb_strlen($hidden)>self::HIDDEN_TEXT_LIMIT){
                $this->telegram->sendMessage(
                    $userId,
                    'Скрытый текст должен быть не длиннее '.self::HIDDEN_TEXT_LIMIT.' символов.'
                );
                return;
            }
            $buttons = [[['text'=>$label,'callback_data'=>'hidden:0:h0','hidden_text'=>$hidden]]];
            $this->replaceChannelDefaultButtons($userId, $channelId, 'hidden', $buttons);
            $this->clearState($userId);
            $this->showChannelPostSettings($userId,$channelId);
            return;
        }
        if ($state === 'await_channel_ad_timer') {
            $channelId = (int) ($data['channel_id'] ?? 0);
            if (!$this->can($userId,$channelId,'manage_admins')) { $this->clearState($userId); return; }
            $minutes = $this->parseDeleteTimerMinutes((string) ($message['text'] ?? ''));
            if ($minutes === null) { $this->telegram->sendMessage($userId,'Не понял время. Примеры: 6, 6:30, 6 30 или 10д.'); return; }
            $this->withOwnedTelegramChannelLock(
                $userId,
                $channelId,
                function () use ($channelId, $minutes): void {
                    $this->pdo->prepare(
                        'UPDATE posted_channels SET ad_delete_minutes=:minutes WHERE id=:id'
                    )->execute(['minutes'=>$minutes>0?$minutes:null,'id'=>$channelId]);
                }
            );
            $this->clearState($userId);
            $this->showChannelAdSettings($userId,$channelId);
            return;
        }
        if ($state === 'await_folder_title') {
            $this->saveFolderTitle($userId,(int)($data['folder_id']??0),(string)($message['text']??''),$data);
            return;
        }
        if ($state === 'await_channel_schedule') {
            $channelId = (int) ($data['channel_id'] ?? 0);
            if (!$this->can($userId,$channelId,'manage_admins')) {
                $this->clearState($userId);
                return;
            }
            $scheduleText = trim((string) ($message['text'] ?? ''));
            preg_match_all('/(?:[01]?\d|2[0-3]):[0-5]\d/u', $scheduleText, $matches);
            $times = array_values(array_unique((array) ($matches[0] ?? [])));
            if ($times === [] && !in_array(mb_strtolower($scheduleText), ['-', 'нет'], true)) {
                $this->telegram->sendMessage($userId, 'Не нашёл время. Отправьте список в формате 09:00, 13:30, 18:00 либо «-», чтобы очистить расписание.');
                return;
            }
            sort($times);
            $this->withOwnedTelegramChannelLock(
                $userId,
                $channelId,
                function () use ($channelId, $times, $userId): void {
                    $this->pdo->prepare(
                        'UPDATE posted_channels
                         SET schedule_json=:schedule,schedule_timezone=:timezone
                         WHERE id=:id'
                    )->execute([
                        'schedule'=>json_encode($times,JSON_UNESCAPED_UNICODE),
                        'timezone'=>$this->userTimezone($userId),
                        'id'=>$channelId,
                    ]);
                }
            );
            $this->clearState($userId);
            $this->showChannelSchedule($userId,$channelId);
            return;
        }
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === 'Отмена') {
            $this->clearState($userId);
            $this->showMainMenu($userId);
            return;
        }
        if ($state === 'collecting_media_group') {
            $postId = (int) ($data['post_id'] ?? 0);
            $groupId = (string) ($data['media_group_id'] ?? '');
            if ($postId > 0 && $groupId !== '' && hash_equals($groupId, (string) ($message['media_group_id'] ?? ''))) {
                $collected=$this->hasTelegramAlbumCollection($userId,$postId,$message);
                $updated = $this->appendDraftMediaGroupItem($userId, $postId, $message);
                if($collected)return; // A complete preview is emitted once after the quiet window.
                if (!$updated) $this->clearState($userId);
                $this->showDraftActions($userId, $postId, $updated ? 'Альбом обновлён.' : 'Альбом не изменён. Для редактирования репоста сначала выберите копию.');
                return;
            }
            $this->clearState($userId);
        }
        $draftStates = [
            'await_draft_text',
            'await_draft_media',
            'await_reply_post',
            'await_reactions',
            'await_hidden_label',
            'await_hidden_text',
            'await_replace_links',
            'await_buttons',
            'await_schedule',
            'await_delete_timer',
        ];
        if(in_array($state,$draftStates,true)){
            $postId=(int)($data['post_id']??0);
            $post=$this->mutableDraft($userId,$postId,$state==='await_schedule');
            if(!$post){
                $this->clearState($userId);
                $this->telegram->sendMessage($userId,'Черновик уже недоступен или у вас больше нет нужных прав.');
                return;
            }
            if ($this->isNativeRepost($post) && $this->isTelegramRepostCopyInput($state)) {
                $this->clearState($userId);
                $this->showDraftActions($userId, $postId, 'Выбран режим репоста. Чтобы изменить содержимое, сначала выберите «Сделать копией».');
                return;
            }
        }
        if ($state === 'await_post_content') {
            $this->createDraftFromMessage($userId,(int)($data['channel_id']??0),$message,(int)($data['manager_category_id']??0));
            return;
        }
        if ($state === 'await_channel_ref') {
            $this->registerChannelFromMessage($userId, $message, $data);
            return;
        }
        if ($state === 'await_edit_forward') {
            $this->createEditableFromForward($userId, $message);
            return;
        }
        if ($state === 'await_draft_text') {
            $this->updateDraftText($userId, (int) ($data['post_id'] ?? 0), $message);
            return;
        }
        if ($state === 'await_draft_media') {
            $this->updateDraftMedia($userId, (int) ($data['post_id'] ?? 0), $message);
            return;
        }
        if ($state === 'await_reply_post') {
            $this->setDraftReplyPost($userId, (int) ($data['post_id'] ?? 0), $message, $data);
            return;
        }
        if ($state === 'await_edit_text') {
            $this->updateEditableText($userId, (int) ($data['post_id'] ?? 0), $message);
            return;
        }
        if ($state === 'await_edit_media') {
            $this->updateEditableMedia($userId, (int) ($data['post_id'] ?? 0), $message);
            return;
        }
        if ($state === 'await_edit_buttons') {
            $postId = (int) ($data['post_id'] ?? 0);
            $post = $this->post($postId);
            if (!$post || !$this->can($userId, (int) $post['channel_id'], 'manage_posts') || (string) $post['status'] !== 'sent') {
                $this->clearState($userId);
                $this->telegram->sendMessage($userId, 'Пост уже недоступен для изменения или у вас больше нет прав.');
                return;
            }
            $buttons = $this->parseButtonsInput($message);
            if($buttons===null){$this->telegram->sendMessage($userId,$this->buttonsHelpText());return;}
            if(!$this->stageEditablePostChanges($userId,$postId,['buttons_json'=>$buttons])){
                return;
            }
            $this->clearState($userId);
            $this->showEditPostActions($userId, $postId, 'Кнопки сохранены.');
            return;
        }
        if ($state === 'await_template_content') {
            $this->createTemplateFromMessage($userId, (int) ($data['scope_id'] ?? 0), $message, $data);
            return;
        }
        if(in_array($state,['await_template_edit_text','await_template_edit_media','await_template_edit_buttons'],true)){
            $this->updateTemplateDraft($userId,(int)($data['template_id']??0),$state,$message);
            return;
        }
        if ($state === 'await_reactions') {
            $postId = (int) ($data['post_id'] ?? 0);
            $buttons = $this->parseReactionButtonsInput($postId,$message);
            if($buttons===null){$this->telegram->sendMessage($userId,'Отправьте боту список реакций, разделяя их символом «/».');return;}
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($postId, $buttons, $userId, $state, $data): bool|string {
                    if(!$this->telegramPostInlineButtonsAllowed($lockedPost))return 'album';
                    if ($this->isNativeRepost($lockedPost)) {
                        $this->clearTelegramRepostInputIfCurrent($userId, $state, $data);
                        return 'native';
                    }
                    $buttons=PostedTelegramButtonUi::replaceTypeRows($this->decodeJson((string)($lockedPost['buttons_json']??'[]')),$buttons,'reaction');
                    $this->pdo->prepare(
                        "UPDATE posted_posts SET buttons_json=:buttons
                         WHERE id=:id AND status='draft'"
                    )->execute(['buttons'=>$this->encodeJson($buttons),'id'=>$postId]);
                    return true;
                }
            );
            if($updated==='album'){$this->telegram->sendMessage($userId,'Кнопки недоступны для альбомов.');return;}
            if ($updated !== true) {
                if ($updated !== 'native') $this->clearState($userId);
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->clearState($userId);
            $this->showDraftActions($userId, $postId, '', $this->draftStateMenuTarget($userId,$data));
            return;
        }
        if ($state === 'await_hidden_label') {
            $postId = (int) ($data['post_id'] ?? 0);
            $label = trim($text);
            $style='';
            if(preg_match('/^!([rgb])\s+(.+)$/us',$label,$styleMatch)===1){
                $style=match($styleMatch[1]){'r'=>'danger','g'=>'success','b'=>'primary'};
                $label=trim($styleMatch[2]);
            }
            if ($label === '') {
                $this->telegram->sendMessage($userId, 'Отправьте непустое название кнопки.');
                return;
            }
            $menuTarget=$this->draftStateMenuTarget($userId,$data);
            $menuId=$this->sendOrEditMessage(
                $userId,
                'Отправьте боту текст, который увидят подписчики вашего канала по нажатию на кнопку.',
                ['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'draft:hidden:'.$postId]]]]],
                $menuTarget
            );
            $nextData=['post_id'=>$postId,'hidden_label'=>$label,'hidden_style'=>$style];
            if($menuId>0)$nextData['menu_message_id']=$menuId;
            $this->setState($userId,'await_hidden_text',$nextData);
            return;
        }
        if ($state === 'await_hidden_text') {
            $postId = (int) ($data['post_id'] ?? 0);
            $label = trim((string)($data['hidden_label']??''));
            $hidden = trim($text);
            $style = (string)($data['hidden_style']??'');
            if ($label === '' || $hidden === '') {
                $this->telegram->sendMessage($userId, 'Отправьте непустой скрытый текст.');
                return;
            }
            if(mb_strlen($hidden)>self::HIDDEN_TEXT_LIMIT){
                $this->telegram->sendMessage(
                    $userId,
                    'Скрытый текст должен быть не длиннее '.self::HIDDEN_TEXT_LIMIT.' символов.'
                );
                return;
            }
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $post) use ($postId, $label, $hidden, $style, $userId, $state, $data): bool|string {
                    if(!$this->telegramPostInlineButtonsAllowed($post))return 'album';
                    if ($this->isNativeRepost($post)) {
                        $this->clearTelegramRepostInputIfCurrent($userId, $state, $data);
                        return 'native';
                    }
                    $buttons = $this->decodeJson((string) ($post['buttons_json'] ?? '[]'));
                    $hiddenIndex = 0;
                    foreach ($buttons as $row) {
                        foreach ((array) $row as $button) {
                            if (
                                is_array($button)
                                && preg_match(
                                    '/^hidden:' . $postId . ':h(\d+)$/',
                                    (string) ($button['callback_data'] ?? ''),
                                    $match
                                )
                            ) {
                                $hiddenIndex = max($hiddenIndex, (int) $match[1] + 1);
                            }
                        }
                    }
                    $button=[
                        'text' => $label,
                        'callback_data' => 'hidden:' . $postId . ':h' . $hiddenIndex,
                        'hidden_text' => $hidden,
                    ];
                    if(in_array($style,['danger','success','primary'],true))$button['style']=$style;
                    $buttons[] = [$button];
                    $buttons=PostedTelegramButtonUi::orderRows($buttons);
                    $this->pdo->prepare(
                        "UPDATE posted_posts SET buttons_json=:buttons
                         WHERE id=:id AND status='draft'"
                    )->execute(['buttons'=>$this->encodeJson($buttons),'id'=>$postId]);
                    return true;
                }
            );
            if($updated==='album'){$this->telegram->sendMessage($userId,'Кнопки недоступны для альбомов.');return;}
            if ($updated !== true) {
                if ($updated !== 'native') $this->clearState($userId);
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->clearState($userId);
            $this->showDraftActions($userId, $postId, '', $this->draftStateMenuTarget($userId,$data));
            return;
        }
        if ($state === 'await_replace_links') {
            $postId = (int) ($data['post_id'] ?? 0);
            $url = trim($text);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->telegram->sendMessage($userId, 'Отправьте корректную ссылку, начинающуюся с http:// или https://.');
                return;
            }
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($postId, $url, $userId, $state, $data): bool|string {
                    if ($this->isNativeRepost($lockedPost)) {
                        $this->clearTelegramRepostInputIfCurrent($userId, $state, $data);
                        return 'native';
                    }
                    $this->pdo->prepare(
                        "UPDATE posted_posts SET replacement_url=:url
                         WHERE id=:id AND status='draft'"
                    )->execute(['url'=>$url,'id'=>$postId]);
                    return true;
                }
            );
            if ($updated !== true) {
                if ($updated !== 'native') $this->clearState($userId);
                return;
            }
            $this->clearState($userId);
            $this->showDraftActions($userId, $postId, '', $this->draftStateMenuTarget($userId,$data));
            return;
        }
        if ($state === 'await_buttons') {
            $postId = (int) ($data['post_id'] ?? 0);
            $buttons = $this->parseButtonsInput($message);
            if($buttons===null){$this->showTelegramDraftInvalidUrlInput($userId,$postId);return;}
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($postId, $buttons, $userId, $state, $data): bool|string {
                    if(!$this->telegramPostInlineButtonsAllowed($lockedPost))return 'album';
                    if ($this->isNativeRepost($lockedPost)) {
                        $this->clearTelegramRepostInputIfCurrent($userId, $state, $data);
                        return 'native';
                    }
                    if($buttons!==[])$buttons=PostedTelegramButtonUi::replaceTypeRows($this->decodeJson((string)($lockedPost['buttons_json']??'[]')),$buttons,'url');
                    $this->pdo->prepare(
                        "UPDATE posted_posts SET buttons_json=:buttons
                         WHERE id=:id AND status='draft'"
                    )->execute([
                        'buttons'=>json_encode(
                            $buttons,
                            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                        ),
                        'id'=>$postId,
                    ]);
                    return true;
                }
            );
            if($updated==='album'){$this->telegram->sendMessage($userId,'Кнопки недоступны для альбомов.');return;}
            if ($updated !== true) {
                if ($updated !== 'native') $this->clearState($userId);
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->clearState($userId);
            $this->showDraftActions($userId, $postId, '', $this->draftStateMenuTarget($userId,$data));
            return;
        }
        if ($state === 'await_schedule') {
            $postId = (int) ($data['post_id'] ?? 0);
            try {
                $scheduledAt = $this->parseScheduleForDate($userId,$text,(string)($data['date']??$this->todayForUser($userId)));
            } catch (InvalidArgumentException $exception) {
                $this->telegram->sendMessage($userId, $exception->getMessage());
                return;
            }
            if ($scheduledAt === null) {
                $this->telegram->sendMessage($userId, 'Не понял дату или время. Укажите сначала дату, затем время: '
                    . $this->scheduleInputExamples($userId, (string) ($data['date'] ?? '')) . '.');
                return;
            }
            $this->clearState($userId);
            $this->confirmOrScheduleDraft($userId,$postId,$scheduledAt,$this->draftStateMenuTarget($userId,$data));
            return;
        }
        if ($state === 'await_cp_schedule') {
            $postId = (int) ($data['post_id'] ?? 0);
            try {
                $scheduledAt = $this->parseScheduleForDate($userId,$text,(string)($data['date']??''));
            } catch (InvalidArgumentException $exception) {
                $this->telegram->sendMessage($userId, $exception->getMessage());
                return;
            }
            if ($scheduledAt === null) {
                $this->telegram->sendMessage($userId, 'Не понял дату или время. Укажите сначала дату, затем время: '
                    . $this->scheduleInputExamples($userId, (string) ($data['date'] ?? '')) . '.');
                return;
            }
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft','scheduled','failed'],
                function (array $lockedPost,array $lockedChannel) use ($postId, $scheduledAt): bool {
                    PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$lockedChannel,$lockedPost,$scheduledAt);
                    $this->pdo->prepare(
                        "UPDATE posted_posts
                         SET status='scheduled',scheduled_at=:scheduled_at,error_text=NULL
                         WHERE id=:id AND status IN ('draft','scheduled','failed')"
                    )->execute(['scheduled_at' => $scheduledAt, 'id' => $postId]);
                    return true;
                },
                true,false,false,true
            );
            if ($updated !== true) {
                $this->clearState($userId);
                $this->telegram->sendMessage($userId, 'Пост не найден или нет доступа.');
                return;
            }
            $this->clearState($userId);
            $this->showManagedPost($userId, $postId);
            return;
        }
        if ($state === 'await_delete_timer') {
            $postId = (int) ($data['post_id'] ?? 0);
            $minutes = $this->parseDeleteTimerMinutes($text);
            if($minutes===null){
                $this->telegram->sendMessage($userId,'Не понял время. Примеры: 6, 6:30, 6 30 или 10д. Максимум 365 дней.');
                return;
            }
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function () use ($postId, $minutes): bool {
                    $this->pdo->prepare(
                        "UPDATE posted_posts
                         SET delete_after_minutes=:minutes,delete_at=NULL,
                             manual_delete_required=0,delete_attempts=0
                         WHERE id=:id AND status='draft'"
                    )->execute(['minutes'=>$minutes>0?$minutes:null,'id'=>$postId]);
                    return true;
                }
            );
            if ($updated !== true) {
                $this->clearState($userId);
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->clearState($userId);
            $timerNotice=$minutes<=0?'Автоудаление выключено.':($minutes>self::TELEGRAM_MAX_AUTO_DELETE_MINUTES
                ?'Сохранён срок ручного удаления: '.$this->telegramDeleteTimerLabel($minutes).'. Автоудаление после 48 часов недоступно.'
                :'Автоудаление включено: '.$this->telegramDeleteTimerLabel($minutes).'.');
            if($minutes>self::TELEGRAM_MAX_AUTO_DELETE_MINUTES){
                // Single-message previews keep the publication text intact;
                // their separate acknowledgement cannot live in prefixText.
                $this->telegram->sendMessage($userId,$timerNotice);
                $timerNotice='';
            }
            $this->showDraftActions($userId, $postId, $timerNotice);
            return;
        }
        if ($state === 'await_admin_id') {
            $adminId = $this->adminTargetUserId($message, $text);
            if ($adminId <= 0) {
                $this->telegram->sendMessage($userId, 'Не удалось определить пользователя. Отправьте числовой Telegram ID или перешлите сообщение от пользователя без скрытого автора.');
                return;
            }
            $this->clearState($userId);
            $this->showRightsPicker($userId, (int) ($data['channel_id'] ?? 0), $adminId);
            return;
        }
        if ($state === 'await_manager_id') {
            $managerId = $this->adminTargetUserId($message, $text);
            if ($managerId <= 0) {
                $this->telegram->sendMessage($userId, 'Не удалось определить пользователя. Отправьте числовой Telegram ID или перешлите сообщение без скрытого автора.');
                return;
            }
            $this->clearState($userId);
            $this->saveAdminRights($userId, (int) ($data['channel_id'] ?? 0), $managerId, 'manager');
            return;
        }
        if ($state === 'await_manager_field') {
            $this->saveManagerFieldInput(
                $userId,
                (int) ($data['channel_id'] ?? 0),
                (int) ($data['manager_id'] ?? 0),
                (string) ($data['field'] ?? ''),
                (int) ($data['page'] ?? 0),
                $text
            );
            return;
        }
        if($state==='await_manager_category_name'){
            $name=trim($text);if($name===''||mb_strlen($name)>100){$this->telegram->sendMessage($userId,'Название должно содержать от 1 до 100 символов.');return;}
            $channelId=(int)($data['channel_id']??0);$managerId=(int)($data['manager_id']??0);
            try{
                $categoryId=(int)$this->withOwnedTelegramChannelLock(
                    $userId,
                    $channelId,
                    function()use($managerId,$channelId,$name):int{
                        $permission=$this->pdo->prepare(
                            'SELECT is_ad_manager FROM posted_permissions
                             WHERE user_id=:user AND channel_id=:channel LIMIT 1 FOR UPDATE'
                        );
                        $permission->execute(['user'=>$managerId,'channel'=>$channelId]);
                        if((int)($permission->fetchColumn()?:0)!==1){
                            throw new RuntimeException('Настройки рекламного менеджера уже недоступны.');
                        }
                        return $this->createOrReactivateManagerCategoryLocked($managerId,$channelId,$name);
                    }
                );
            }catch(Throwable $exception){
                $this->telegram->sendMessage($userId,$exception->getMessage());
                return;
            }
            $this->clearState($userId);$this->showManagerCategory($userId,$channelId,$managerId,$categoryId,(int)($data['page']??0));return;
        }
        if($state==='await_manager_category_field'){
            $input=trim($text);$unlimited=in_array(mb_strtolower($input),['безлимит','безлим','unlimited','∞'],true);
            if(!$unlimited&&preg_match('/^\d{1,9}$/D',$input)!==1){$this->telegram->sendMessage($userId,'Отправьте целое число или «безлимит».');return;}
            $channelId=(int)($data['channel_id']??0);$managerId=(int)($data['manager_id']??0);$categoryId=(int)($data['category_id']??0);$field=(string)($data['field']??'');
            if(!$this->isChannelOwner($userId,$channelId)||!in_array($field,['posts','pins'],true)){ $this->clearState($userId);return; }
            $column=$field==='posts'?'post_quota_remaining':'pin_quota_remaining';$stmt=$this->pdo->prepare("UPDATE posted_manager_categories SET {$column}=:value WHERE id=:id AND user_id=:manager AND channel_id=:channel AND is_active=1");$value=$unlimited?null:(int)$input;$stmt->bindValue(':value',$value,$value===null?PDO::PARAM_NULL:PDO::PARAM_INT);$stmt->bindValue(':id',$categoryId,PDO::PARAM_INT);$stmt->bindValue(':manager',$managerId,PDO::PARAM_INT);$stmt->bindValue(':channel',$channelId,PDO::PARAM_INT);$stmt->execute();
            $this->clearState($userId);$this->showManagerCategory($userId,$channelId,$managerId,$categoryId,(int)($data['page']??0));return;
        }
    }

    /** Text-only workflows never decode or adopt an incoming Native payload. */
    private function telegramTextInputContent(array $message): array
    {
        foreach(PostedTelegramNativeContent::KINDS as$kind)if(array_key_exists($kind,$message))return['text'=>'','text_entities'=>[]];
        if(array_key_exists('paid_media',$message))return['text'=>'','text_entities'=>[]];
        $usesText=array_key_exists('text',$message);$text=$usesText?($message['text']??''):($message['caption']??'');
        if(!is_string($text))return['text'=>'','text_entities'=>[]];
        return['text'=>$text,'text_entities'=>$this->normalizeTelegramEntities($text,(array)($usesText?($message['entities']??[]):($message['caption_entities']??[])))];
    }

    private function contentFromMessage(array $message): array
    {
        $native=PostedTelegramNativeContent::fromMessage($message);
        if($native!==null){$p=$native['payload'];return['text'=>'','text_entities'=>[],'media_spoiler'=>false,'media_type'=>$native['kind'],'media_file_id'=>$p['file_id']??null,'media'=>isset($p['file_id'])?[['type'=>$native['kind'],'file_id'=>$p['file_id'],'file_unique_id'=>$p['file_unique_id'],'media_group_id'=>null]]:[],'native_content'=>$native];}
        if(array_key_exists('paid_media',$message))throw new InvalidArgumentException('Копирование внешнего платного медиа пока недоступно. Подпись не заменяет платное содержимое.');
        if(array_key_exists('has_media_spoiler',$message)&&!is_bool($message['has_media_spoiler']))throw new InvalidArgumentException('Некорректный признак спойлера.');
        $usesText = array_key_exists('text', $message);
        $text = (string) ($usesText ? ($message['text'] ?? '') : ($message['caption'] ?? ''));
        $entities = $this->normalizeTelegramEntities(
            $text,
            (array) ($usesText
                ? ($message['entities'] ?? [])
                : ($message['caption_entities'] ?? []))
        );
        $mediaType = 'none';
        $mediaFileId = null;
        $mediaUniqueId = null;
        $mediaGroupId = trim((string) ($message['media_group_id'] ?? ''));

        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']);
            if (is_array($photo) && !empty($photo['file_id'])) {
                $mediaType = 'photo';
                $mediaFileId = (string) $photo['file_id'];
                $mediaUniqueId = trim((string) ($photo['file_unique_id'] ?? '')) ?: null;
            }
        } elseif (!empty($message['video']) && is_array($message['video']) && !empty($message['video']['file_id'])) {
            $mediaType = 'video';
            $mediaFileId = (string) $message['video']['file_id'];
            $mediaUniqueId = trim((string) ($message['video']['file_unique_id'] ?? '')) ?: null;
        } else {
            foreach (['animation', 'document', 'audio', 'voice'] as $candidateType) {
                $candidate = $message[$candidateType] ?? null;
                if (is_array($candidate) && !empty($candidate['file_id'])) {
                    $mediaType = $candidateType;
                    $mediaFileId = (string) $candidate['file_id'];
                    $mediaUniqueId = trim((string) ($candidate['file_unique_id'] ?? '')) ?: null;
                    break;
                }
            }
        }

        return [
            'text' => $text,
            'text_entities' => $entities,
            'media_spoiler'=>($message['has_media_spoiler']??false)===true&&in_array($mediaType,['photo','video','animation'],true),
            'media_type' => $mediaType,
            'media_file_id' => $mediaFileId,
            'media' => $mediaFileId === null ? [] : [[
                'type' => $mediaType,
                'file_id' => $mediaFileId,
                'file_unique_id' => $mediaUniqueId,
                'media_group_id' => $mediaGroupId !== '' ? $mediaGroupId : null,
            ]],
        ];
    }

    private function postMedia(array $post): array
    {
        $stored = $this->decodeJson((string) ($post['media_json'] ?? ''));
        $media = [];
        foreach ($stored as $item) {
            if (!is_array($item)) continue;
            $type = (string) ($item['type'] ?? '');
            $fileId = trim((string) ($item['file_id'] ?? ''));
            if (!in_array($type, ['photo', 'video', 'animation', 'document', 'audio', 'voice', 'video_note', 'sticker'], true) || $fileId === '') continue;
            $media[] = [
                'type' => $type,
                'file_id' => $fileId,
                'file_unique_id' => trim((string) ($item['file_unique_id'] ?? '')) ?: null,
                'media_group_id' => trim((string) ($item['media_group_id'] ?? '')) ?: null,
            ];
            if (count($media) >= 10) break;
        }
        if ($media === []) {
            $type = (string) ($post['media_type'] ?? 'none');
            $fileId = trim((string) ($post['media_file_id'] ?? ''));
            if ($type !== 'none' && $fileId !== '') {
                $media[] = [
                    'type' => $type,
                    'file_id' => $fileId,
                    'file_unique_id' => null,
                    'media_group_id' => null,
                ];
            }
        }
        return $media;
    }

    private function appendDraftMediaGroupItem(int $userId, int $postId, array $message): bool
    {
        if($this->hasTelegramAlbumCollection($userId,$postId,$message))return$this->appendTelegramAlbumItem($userId,$postId,$message);
        $content = $this->contentFromMessage($message);
        if ($content['media'] === []) {
            return false;
        }
        try {
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($content, $postId): bool {
                    if ($this->isNativeRepost($lockedPost)) return false;
                    if(PostedTelegramCaptionVector::load($this->pdo,$lockedPost)!==null)return false; // Only authenticated MID-bound collector ingress may extend this vector.
                    $media = $this->postMedia($lockedPost);
                    $item = $content['media'][0];
                    foreach ($media as $existing) {
                        if (hash_equals((string) $existing['file_id'], (string) $item['file_id'])) {
                            return true;
                        }
                    }
                    if (count($media) >= 10) {
                        return true;
                    }
                    $media[] = $item;
                    if(!$this->telegramMediaOptionsCompatible($lockedPost,$media)||!$this->telegramInboundSpoilerLocked($lockedPost,$content))return false;
                    if(PostedTelegramSourceSpoiler::mediaFingerprint($lockedPost)!==PostedTelegramSourceSpoiler::mediaFingerprint(array_replace($lockedPost,['media_json'=>$this->encodeJson($media)])))PostedTelegramSourceSpoiler::detachLocked($this->pdo,$lockedPost);
                    $text = (string) $content['text'];
                    $sql = "UPDATE posted_posts
                            SET media_json=:media_json,
                                repost_chat_id=NULL,repost_message_id=NULL,
                                repost_input_chat_id=NULL,repost_input_message_id=NULL";
                    $parameters = ['media_json' => $this->encodeJson($media), 'id' => $postId];
                    if ($text !== '') {
                        $sql .= ',text=:text,text_entities_json=:text_entities_json';
                        $parameters['text'] = $text;
                        $parameters['text_entities_json'] = $content['text_entities'] !== []
                            ? $this->encodeJson($content['text_entities'])
                            : null;
                    }
                    $sql .= " WHERE id=:id AND status='draft'";
                    $this->pdo->prepare($sql)->execute($parameters);
                    return true;
                }
            );
            return $updated === true;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    private function startEditPost(int $userId): void
    {
        $this->setState($userId, 'await_edit_forward', []);
        $this->telegram->sendMessage($userId, $this->telegramUiText($userId, 'edit.body', $this->customEmojiHtml('5258331647358540449', '✍️') . ' <b>ИЗМЕНЕНИЕ ПОСТА</b>' . "\n\n" . 'Перешлите пост из вашего канала, который хотите изменить.'), [
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => $this->telegramUiText($userId, 'button.cancel', '← Отменить'), 'callback_data' => 'main']]]],
        ]);
    }

    private function forwardedChannelOrigin(array $message): ?array
    {
        $origin = (array) ($message['forward_origin'] ?? []);
        if (($origin['type'] ?? '') === 'channel' && !empty($origin['chat']) && !empty($origin['message_id'])) {
            $chat = (array) $origin['chat'];
            return [
                'chat_id' => (string) ($chat['id'] ?? ''),
                'message_id' => (int) $origin['message_id'],
            ];
        }

        if (!empty($message['forward_from_chat']) && !empty($message['forward_from_message_id'])) {
            $chat = (array) $message['forward_from_chat'];
            return [
                'chat_id' => (string) ($chat['id'] ?? ''),
                'message_id' => (int) $message['forward_from_message_id'],
            ];
        }

        return null;
    }

    private function repostInput(array $message): ?array
    {
        $chat = (array) ($message['chat'] ?? []);
        $chatId = trim((string) ($chat['id'] ?? ''));
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === '' || $messageId <= 0) {
            return null;
        }

        return [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];
    }

    private function adminTargetUserId(array $message, string $text): int
    {
        $origin = (array) ($message['forward_origin'] ?? []);
        if (($origin['type'] ?? '') === 'user' && !empty($origin['sender_user']) && is_array($origin['sender_user'])) {
            $sender = (array) $origin['sender_user'];
            $id = (int) ($sender['id'] ?? 0);
            if ($id > 0 && $this->telegramPolicyOperatorAllowed($id)) {
                $this->upsertUser($sender);
                return $id;
            }
        }

        if (!empty($message['forward_from']) && is_array($message['forward_from'])) {
            $sender = (array) $message['forward_from'];
            $id = (int) ($sender['id'] ?? 0);
            if ($id > 0 && $this->telegramPolicyOperatorAllowed($id)) {
                $this->upsertUser($sender);
                return $id;
            }
        }

        if (preg_match('/\b\d{5,20}\b/u', $text, $match) === 1) {
            return (int) $match[0];
        }

        return 0;
    }

    private function createEditableFromForward(int $userId, array $message): void
    {
        $origin = $this->forwardedChannelOrigin($message);
        if (!$origin || $origin['chat_id'] === '' || $origin['message_id'] <= 0) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Не вижу источник канала. Перешлите именно пост из канала, где бот админ.');
            return;
        }

        $channel = $this->channelByChatId($origin['chat_id']);
        if (!$channel) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Нет доступа на изменение постов в этом канале.');
            return;
        }
        try {
            [$postId, $deleteOnCancel] = $this->withTelegramChannelCapabilityLock(
                $userId,
                (int) $channel['id'],
                'manage_posts',
                function () use ($channel, $origin, $message, $userId): array {
                    $existingPostId = $this->postIdByChannelMessage(
                        (int) $channel['id'],
                        (int) $origin['message_id']
                    );
                    if ($existingPostId > 0) {
                        return [$existingPostId, false];
                    }
                    $content=$this->contentFromMessage($message);
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO posted_posts
                            (channel_id, author_id, text, text_entities_json, media_type, media_file_id, media_json, status, external_chat_id, external_message_id, original_media_type)
                         VALUES
                            (:channel_id, :author_id, :text, :text_entities_json, :media_type, :media_file_id, :media_json, 'sent', :external_chat_id, :external_message_id, :original_media_type)"
                    );
                    $stmt->execute([
                        'channel_id' => (int) $channel['id'],
                        'author_id' => $userId,
                        'text' => $content['text'] !== '' ? $content['text'] : null,
                        'text_entities_json' => $content['text_entities'] !== []
                            ? $this->encodeJson($content['text_entities'])
                            : null,
                        'media_type' => $content['media_type'],
                        'media_file_id' => $content['media_file_id'],
                        'media_json' => $content['media'] !== []
                            ? $this->encodeJson($content['media'])
                            : null,
                        'external_chat_id' => $origin['chat_id'],
                        'external_message_id' => $origin['message_id'],
                        'original_media_type' => $content['media_type'],
                    ]);
                    $postId = (int) $this->pdo->lastInsertId();
                    if ($postId <= 0) {
                        throw new RuntimeException('Не удалось сохранить публикацию.');
                    }
                    return [$postId, true];
                }
            );
        } catch (Throwable $exception) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, $exception instanceof InvalidArgumentException&&array_key_exists('paid_media',$message)?$exception->getMessage():'Нет доступа на изменение постов в этом канале.');
            return;
        }
        $this->clearState($userId);
        $editablePost=$this->post($postId);
        if(!is_array($editablePost)||(!$this->isSingleMessageEditableTelegramPost($editablePost)&&!$this->telegramPublishedAlbumEditable($editablePost))){
            $this->telegram->sendMessage(
                $userId,
                'Составные публикации и альбомы нельзя безопасно изменить через этот редактор. Изменения не применялись.'
            );
            return;
        }
        if ($postId <= 0 || !$this->startEditSession($userId, $postId, $deleteOnCancel)) {
            $this->telegram->sendMessage($userId, 'Пост принят, но не удалось открыть карточку изменения. Откройте «Изменить пост» ещё раз.');
            return;
        }
        $this->showEditPostActions($userId, $postId, 'Пост принят на изменение.');
    }

    private function startEditSession(int $userId,int $postId,bool $deleteOnCancel):bool
    {
        try {
            return $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['sent'],
                function (array $lockedPost) use ($userId, $postId, $deleteOnCancel): bool {
                    if(!$this->isSingleMessageEditableTelegramPost($lockedPost)&&!$this->telegramPublishedAlbumEditable($lockedPost)){
                        return false;
                    }
                    PostedTelegramPublicButtonLayout::assertNotComponents($lockedPost);
                    PostedTelegramPublicButtonEdit::assertIdleLocked($this->pdo,$postId);
                    $snapshot=$this->editablePostSnapshot($lockedPost);
                    $document=[
                        'version'=>2,
                        'base'=>$snapshot,
                        'draft'=>$snapshot,
                    ];
                    $this->pdo->prepare(
                        'INSERT INTO posted_edit_sessions(user_id,post_id,snapshot_json,delete_on_cancel)
                         VALUES(:user,:post,:snapshot,:delete_on_cancel)
                         ON DUPLICATE KEY UPDATE
                            snapshot_json=snapshot_json,
                            delete_on_cancel=GREATEST(delete_on_cancel,VALUES(delete_on_cancel)),
                            created_at=NOW()'
                    )->execute([
                        'user'=>$userId,
                        'post'=>$postId,
                        'snapshot'=>$this->encodeJson($document),
                        'delete_on_cancel'=>$deleteOnCancel?1:0,
                    ]);
                    $channel=$this->channel((int)$lockedPost['channel_id']);
                    if(!is_array($channel))throw new RuntimeException('album_edit_channel_missing');
                    $this->telegramStartAlbumEditLocked($userId,$lockedPost,$channel);
                    return true;
                },
                false,
                true
            ) === true;
        } catch (Throwable $exception) {
            error_log('GitPosted edit session creation failed: '.$exception->getMessage());
            return false;
        }
    }

    private function rollbackEditSession(int $userId,int $postId):void
    {
        try {
            $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['sent'],
                function (array $post) use ($userId, $postId): bool {
                    PostedTelegramPublicButtonEdit::assertIdleLocked($this->pdo,$postId);
                    $sessionStmt=$this->pdo->prepare(
                        'SELECT * FROM posted_edit_sessions
                         WHERE user_id=:user AND post_id=:post
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $sessionStmt->execute(['user'=>$userId,'post'=>$postId]);
                    $session=$sessionStmt->fetch();
                    if(!is_array($session)){
                        return true;
                    }

                    if($this->telegramAlbumEditCandidate($post)&&$this->telegramAlbumEditBusy($postId,true))throw new RuntimeException('album_edit_operation_pending');
                    $deleteImportedPost=false;
                    if(!empty($session['delete_on_cancel'])){
                        $document=$this->editSessionDocument($session,$post);
                        $otherSessions=$this->pdo->prepare(
                            'SELECT COUNT(*)
                             FROM posted_edit_sessions
                             WHERE post_id=:post AND user_id<>:user'
                        );
                        $otherSessions->execute(['post'=>$postId,'user'=>$userId]);
                        $deleteImportedPost=(int)$otherSessions->fetchColumn()===0
                            &&$this->editablePostSnapshot($post)===$document['base'];
                    }

                    $this->pdo->prepare(
                        'DELETE FROM posted_edit_sessions WHERE user_id=:user AND post_id=:post'
                    )->execute(['user'=>$userId,'post'=>$postId]);
                    PostedTelegramPublishedAlbumEditor::removeStageLocked($this->pdo,$userId,$postId);
                    if($deleteImportedPost){
                        $this->pdo->prepare('DELETE FROM posted_posts WHERE id=:post')->execute(['post'=>$postId]);
                    }
                    return true;
                },
                false,
                true
            );
        } catch (Throwable $exception) {
            error_log('GitPosted edit session rollback failed: '.$exception->getMessage());
        }
    }

    private function editablePostSnapshot(array $source):array
    {
        $buttons=$source['buttons_json']??[];
        if(is_string($buttons))$buttons=$this->decodeJson($buttons);
        if(!is_array($buttons))$buttons=[];
        $mediaType=(string)($source['media_type']??'none');
        if(!in_array($mediaType,['none','photo','video','animation','document','audio','voice'],true)){
            $mediaType='none';
        }
        $text=$source['text']??null;
        $textEntities=$source['text_entities_json']??[];
        if(is_string($textEntities))$textEntities=$this->decodeJson($textEntities);
        if(!is_array($textEntities))$textEntities=[];
        $normalizedText=$text===null?'':(string)$text;
        $textEntities=$this->normalizeTelegramEntities($normalizedText,$textEntities);
        $mediaFileId=$source['media_file_id']??null;
        $mediaSource=$source;
        if(is_array($mediaSource['media_json']??null)){
            $mediaSource['media_json']=$this->encodeJson($mediaSource['media_json']);
        }
        $media=$this->postMedia($mediaSource);
        $signature=$source['autosignature_text']??null;
        $signatureEntities=$source['autosignature_entities_json']??[];
        if(is_string($signatureEntities))$signatureEntities=$this->decodeJson($signatureEntities);
        if(!is_array($signatureEntities))$signatureEntities=[];
        $signatureEntities=$this->normalizeTelegramEntities(
            $signature===null?'':(string)$signature,
            $signatureEntities
        );
        return [
            'text'=>$text===null?null:(string)$text,
            'text_entities_json'=>$textEntities,
            'media_type'=>$mediaType,
            'media_file_id'=>$mediaFileId===null?null:(string)$mediaFileId,
            'media_json'=>$media,
            'buttons_json'=>$buttons,
            'pin_after_publish'=>!empty($source['pin_after_publish'])?1:0,
            'autosignature_text'=>$signature===null?null:(string)$signature,
            'autosignature_entities_json'=>$signatureEntities,
        ];
    }

    private function editSessionDocument(array $session,array $post):array
    {
        $stored=$this->decodeJson((string)($session['snapshot_json']??''));
        if(
            (int)($stored['version']??0)===2
            &&is_array($stored['base']??null)
            &&is_array($stored['draft']??null)
        ){
            $document=[
                'version'=>2,
                'base'=>$this->editablePostSnapshot((array)$stored['base']),
                'draft'=>$this->editablePostSnapshot((array)$stored['draft']),
            ];
            if(array_key_exists('caption_placement',$stored))$document['caption_placement']=$stored['caption_placement'];
            return PostedTelegramCaptionPlacement::editDocument($this->pdo,$post,$document);
        }

        // Lazy migration for sessions created by the legacy editor, which wrote
        // changes directly to posted_posts. Treat the current row as the new base
        // so cancelling this session can never restore stale data over another edit.
        $snapshot=$this->editablePostSnapshot($post);
        return PostedTelegramCaptionPlacement::editDocument($this->pdo,$post,['version'=>2,'base'=>$snapshot,'draft'=>$snapshot]);
    }

    private function editSessionPost(int $userId,int $postId):?array
    {
        $post=$this->post($postId);
        if(!$post)return null;
        $sessionStmt=$this->pdo->prepare(
            'SELECT * FROM posted_edit_sessions WHERE user_id=:user AND post_id=:post LIMIT 1'
        );
        $sessionStmt->execute(['user'=>$userId,'post'=>$postId]);
        $session=$sessionStmt->fetch();
        if(!is_array($session))return null;
        $document=$this->editSessionDocument($session,$post);
        return PostedTelegramCaptionPlacement::editPost($this->postWithEditableSnapshot($post,$document['draft']),$document);
    }

    private function postWithEditableSnapshot(array $post,array $snapshot):array
    {
        $snapshot=$this->editablePostSnapshot($snapshot);
        foreach(self::EDITABLE_POST_FIELDS as $field){
            if(in_array(
                $field,
                ['buttons_json','text_entities_json','media_json','autosignature_entities_json'],
                true
            )){
                $post[$field]=$snapshot[$field]!==[]?$this->encodeJson($snapshot[$field]):null;
                continue;
            }
            $post[$field]=$snapshot[$field];
        }
        return $post;
    }

    private function isSingleMessageEditableTelegramPost(array $post):bool
    {
        if((int)($post['external_message_id']??0)>0){
            return trim((string)($post['external_chat_id']??''))!=='';
        }
        return count($this->postMessageIds($post))===1;
    }

    private function stageEditablePostChanges(int $userId,int $postId,array $changes,?bool $captionAboveReplacement=null):bool
    {
        try {
            $updated=$this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['sent'],
                function (array $lockedPost,array $lockedChannel) use ($userId, $postId, $changes,$captionAboveReplacement): bool|string {
                    PostedTelegramPublicButtonEdit::assertIdleLocked($this->pdo,$postId);
                    if(array_key_exists('buttons_json',$changes)&&!$this->telegramPostInlineButtonsAllowed($lockedPost))return 'album';
                    if($this->telegramStageAlbumEditChangesLocked($userId,$lockedPost,$lockedChannel,$changes))return true;
                    $sourceSpoiler=PostedTelegramSourceSpoiler::freeze($this->pdo,$lockedPost);
                    if($sourceSpoiler!==null&&$sourceSpoiler['mode']==='native'&&($sourceSpoiler['state']==='unknown'||$sourceSpoiler['media_spoiler']===true)&&array_diff(array_keys($changes),['pin_after_publish'])!==[])throw new InvalidArgumentException('Состояние спойлера этого источника требует сохранения. Для изменения создайте дубликат и явно выберите копию.');
                    if($this->telegramPostOptions($lockedPost)['paid_media_stars']!==null
                        &&array_intersect(array_keys($changes),['media_type','media_file_id','media_json'])!==[]){
                        throw new InvalidArgumentException('Медиа опубликованного платного поста нельзя заменять обычным медиа. Создайте отдельный черновик.');
                    }
                    $sessionStmt=$this->pdo->prepare(
                        'SELECT * FROM posted_edit_sessions
                         WHERE user_id=:user AND post_id=:post
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $sessionStmt->execute(['user'=>$userId,'post'=>$postId]);
                    $session=$sessionStmt->fetch();
                    if(!is_array($session)){
                        throw new RuntimeException('Edit session is missing.');
                    }
                    $document=$this->editSessionDocument($session,$lockedPost);
                    $document['draft']=$this->editablePostSnapshot(array_replace($document['draft'],$changes));
                    if($captionAboveReplacement!==null)$document=PostedTelegramCaptionPlacement::replaceEdit($document,$captionAboveReplacement);
                    PostedTelegramCaptionPlacement::editPost($this->postWithEditableSnapshot($lockedPost,$document['draft']),$document);
                    $this->pdo->prepare(
                        'UPDATE posted_edit_sessions SET snapshot_json=:snapshot
                         WHERE user_id=:user AND post_id=:post'
                    )->execute([
                        'snapshot'=>$this->encodeJson($document),
                        'user'=>$userId,
                        'post'=>$postId,
                    ]);
                    return true;
                },
                false,
                true
            );
            if($updated==='album'){$this->telegram->sendMessage($userId,'Кнопки недоступны для альбомов.');return false;}
            if($updated===true)return true;
            $this->clearState($userId);
            $this->telegram->sendMessage($userId,'Доступ к редактированию этого канала отозван.');
            return false;
        } catch (Throwable $exception) {
            $this->clearState($userId);
            error_log('GitPosted edit staging failed: '.$exception->getMessage());
            $this->telegram->sendMessage($userId,$exception instanceof InvalidArgumentException&&str_starts_with($exception->getMessage(),'Состояние спойлера этого источника')?$exception->getMessage():'Сессия редактирования устарела. Откройте пост заново.');
            return false;
        }
    }

    private function updateDraftText(int $userId, int $postId, array $message): void
    {
        $nativePost=$this->post($postId);if(is_array($nativePost)&&PostedTelegramNativeContent::isKind($nativePost['media_type']??null)){$this->telegram->sendMessage($userId,'Этот вид содержимого нельзя заменить описанием или обычным медиа. Исходный пост сохранён.');return;}
        $content = $this->telegramTextInputContent($message);
        if(isset($content['native_content'])){$this->telegram->sendMessage($userId,'Создайте новый пост с этим видом содержимого. Исходный пост сохранён.');return;}
        $text = (string) $content['text'];
        if($text===''){$this->telegram->sendMessage($userId,'Отправьте новый текст поста.');return;}
        try{$updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function (array $lockedPost) use ($postId, $text, $content): bool {
                if(PostedTelegramNativeContent::isKind($lockedPost['media_type']??null))throw new RuntimeException('native_content_mutation_denied');
                if ($this->isNativeRepost($lockedPost)) return false;
                if($this->postMedia($lockedPost)!==[]&&mb_strlen($text,'UTF-8')>self::PHOTO_CAPTION_LIMIT)throw new LengthException('draft_caption_too_long');
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET text=:text,text_entities_json=:text_entities_json,
                         repost_chat_id=NULL,repost_message_id=NULL,
                         repost_input_chat_id=NULL,repost_input_message_id=NULL
                     WHERE id=:id AND status='draft'"
                )->execute([
                    'text' => $text !== '' ? $text : null,
                    'text_entities_json' => $content['text_entities'] !== []
                        ? $this->encodeJson($content['text_entities'])
                        : null,
                    'id' => $postId,
                ]);
                return true;
            }
        );}catch(LengthException $error){if($error->getMessage()!=='draft_caption_too_long')throw$error;$this->telegram->sendMessage($userId,'❌ Нельзя добавить описание длиннее 1024 символов. Чтобы создать длинное описание, отправьте пост заново сразу с готовым описанием.',['entities'=>[['type'=>'custom_emoji','offset'=>0,'length'=>1,'custom_emoji_id'=>'5260342697075416641']]]);return;}
        if ($updated !== true) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Черновик недоступен для редактирования. Для репоста сначала выберите «Сделать копией».');
            return;
        }
        $this->clearState($userId);
        $this->showDraftActions($userId, $postId, 'Текст обновлён.');
    }

    private function updateDraftMedia(int $userId, int $postId, array $message): void
    {
        $nativePost=$this->post($postId);if(is_array($nativePost)&&PostedTelegramNativeContent::isKind($nativePost['media_type']??null)){$this->telegram->sendMessage($userId,'Этот вид содержимого нельзя заменить описанием или обычным медиа. Исходный пост сохранён.');return;}
        try{$content = $this->contentFromMessage($message);}catch(InvalidArgumentException){$this->telegram->sendMessage($userId,'Отправьте поддерживаемое медиа. Исходные данные и текущий ввод сохранены.');return;}
        if(isset($content['native_content'])){$this->telegram->sendMessage($userId,'Создайте новый пост с этим видом содержимого. Исходный пост сохранён.');return;}
        if ($content['media_file_id'] === null) {
            $this->telegram->sendMessage($userId, 'Отправьте фото, видео, анимацию, документ, аудио или голосовое сообщение.');
            return;
        }
        try{$captionAbove=PostedTelegramCaptionPlacement::input($message,$content);}catch(InvalidArgumentException$e){$this->telegram->sendMessage($userId,$e->getMessage());return;}
        $replaceText = array_key_exists('caption',$message) || array_key_exists('text',$message);
        $updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function (array $lockedPost) use ($postId, $content, $replaceText,$userId,$message,$captionAbove): bool {
                if(PostedTelegramNativeContent::isKind($lockedPost['media_type']??null))throw new RuntimeException('native_content_mutation_denied');
                if ($this->isNativeRepost($lockedPost)) return false;
                if(!$this->telegramMediaOptionsCompatible($lockedPost,(array)$content['media']))return false;
                $replacement=array_replace($lockedPost,['media_type'=>$content['media_type'],'media_file_id'=>$content['media_file_id'],'media_json'=>$this->encodeJson($content['media'])]);
                PostedTelegramCaptionPlacement::captureLocked($this->pdo,$lockedPost,false);
                if($captionAbove){$checked=array_replace($replacement,['repost_chat_id'=>null,'repost_message_id'=>null,'repost_input_chat_id'=>null,'repost_input_message_id'=>null]);$checked['_telegram_post_options']=$this->telegramPostOptions($lockedPost);PostedTelegramCaptionPlacement::assertCompatible($checked);}
                if(!$this->telegramInboundSpoilerLocked($lockedPost,$content))return false;
                if(PostedTelegramSourceSpoiler::mediaFingerprint($lockedPost)!==PostedTelegramSourceSpoiler::mediaFingerprint($replacement)){PostedTelegramSourceSpoiler::detachLocked($this->pdo,$lockedPost);PostedTelegramCaptionVector::detachLocked($this->pdo,$lockedPost);}
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET media_type=:media_type,media_file_id=:media_file_id,media_json=:media_json,
                         text=CASE WHEN :replace_text_value=1 THEN :replacement_text ELSE text END,
                         text_entities_json=CASE
                             WHEN :replace_entities_value=1 THEN :replacement_entities
                             ELSE text_entities_json
                         END,
                         repost_chat_id=NULL,repost_message_id=NULL,
                         repost_input_chat_id=NULL,repost_input_message_id=NULL
                     WHERE id=:id AND status='draft'"
                )->execute([
                    'media_type'=>$content['media_type'],
                    'media_file_id'=>$content['media_file_id'],
                    'media_json'=>$this->encodeJson($content['media']),
                    'replace_text_value'=>$replaceText?1:0,
                    'replacement_text'=>$replaceText && $content['text']!==''?$content['text']:null,
                    'replace_entities_value'=>$replaceText?1:0,
                    'replacement_entities'=>$replaceText && $content['text_entities']!==[]
                        ?$this->encodeJson($content['text_entities'])
                        :null,
                    'id'=>$postId,
                ]);
                $placementPost=$this->post($postId);if(!is_array($placementPost))throw new RuntimeException('caption_placement_replacement_missing');PostedTelegramCaptionPlacement::captureLocked($this->pdo,$placementPost,$captionAbove);
                $albumPost=$this->post($postId);if(is_array($albumPost))$this->registerTelegramAlbumLocked($userId,$albumPost,$message);
                return true;
            }
        );
        if ($updated !== true) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Черновик недоступен для редактирования. Для репоста сначала выберите «Сделать копией».');
            return;
        }
        $groupId = trim((string) ($message['media_group_id'] ?? ''));
        if($this->hasTelegramAlbumCollection($userId,$postId,$message))return;
        if ($groupId !== '') {
            $this->setState($userId, 'collecting_media_group', ['post_id' => $postId, 'media_group_id' => $groupId]);
        } else {
            $this->clearState($userId);
        }
        $this->showDraftActions($userId, $postId, 'Медиа обновлено.');
    }

    private function setDraftReplyPost(int $userId, int $postId, array $message, array $inputData): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $origin = $this->forwardedChannelOrigin($message);
        $updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function (array $_post, array $channel) use ($postId, $text, $origin, $userId, $inputData): string {
                if ($this->isNativeRepost($_post)) {
                    $this->clearTelegramRepostInputIfCurrent($userId, 'await_reply_post', $inputData);
                    return 'native';
                }
                if ($text === '-') {
                    $this->pdo->prepare(
                        'UPDATE posted_posts
                         SET reply_chat_id=NULL,reply_message_id=NULL
                         WHERE id=:id AND status=\'draft\''
                    )->execute(['id' => $postId]);
                    return 'removed';
                }
                if (!$origin || (string) $origin['chat_id'] !== (string) $channel['chat_id']) {
                    return 'wrong_channel';
                }
                $this->pdo->prepare(
                    'UPDATE posted_posts
                     SET reply_chat_id=:chat_id,reply_message_id=:message_id
                     WHERE id=:id AND status=\'draft\''
                )->execute([
                    'chat_id' => $origin['chat_id'],
                    'message_id' => $origin['message_id'],
                    'id' => $postId,
                ]);
                return 'updated';
            }
        );
        if ($updated === null) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Пост не найден или нет доступа.');
            return;
        }
        if ($updated === 'wrong_channel') {
            $this->telegram->sendMessage($userId, 'Перешлите пост из того же канала.');
            return;
        }
        if ($updated === 'native') {
            $this->showDraftActions($userId, $postId, 'Выбран режим репоста. Чтобы изменить ответный пост, сначала выберите копию.');
            return;
        }
        $this->clearState($userId);
        $this->showDraftActions(
            $userId,
            $postId,
            $updated === 'removed' ? 'Ответный пост убран.' : 'Ответный пост выбран.'
        );
    }

    private function createDraftFromMessage(int $userId,int $channelId,array $message,int $managerCategoryId=0): void
    {
        if ($channelId <= 0) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Нет доступа к каналу.');
            return;
        }
        $nativeInput=false;foreach(PostedTelegramNativeContent::KINDS as$nativeKind)if(array_key_exists($nativeKind,$message))$nativeInput=true;
        try{$content=$this->contentFromMessage($message);if(isset($content['native_content']))PostedTelegramNativeSchema::requireEnabled($this->pdo);}catch(Throwable$e){$this->telegram->sendMessage($userId,'Содержимое не принято. Исходные данные сохранены: '.$e->getMessage());return;}
        $repost = $this->forwardedChannelOrigin($message);
        $repostInput = $repost === null ? null : $this->repostInput($message);
        try{$captionAbove=PostedTelegramCaptionPlacement::input($message,$content,$repost);}catch(InvalidArgumentException$e){$this->telegram->sendMessage($userId,$e->getMessage());return;}
        if ($content['text'] === '' && $content['media_file_id'] === null && !isset($content['native_content']) && $repost === null) {
            $this->telegram->sendMessage($userId, 'Нужен текст либо поддерживаемое медиа с подписью.');
            return;
        }
        try {
            $postId = $this->withTelegramChannelCapabilityLock(
                $userId,
                $channelId,
                'post',
                function (array $_channel,array $permission) use ($channelId,$userId,$content,$repost,$repostInput,$managerCategoryId,$message,$captionAbove): int {
                    if(isset($content['native_content'])){PostedTelegramNativeSchema::requireEnabled($this->pdo);if(trim((string)($_channel['post_autosignature']??''))!==''||$repost!==null)throw new InvalidArgumentException('Для этого вида содержимого требуется пустая подпись канала и явный режим копии. Данные не изменены.');}
                    $resolvedCategoryId=$this->resolveTelegramManagerCategoryLocked(
                        $userId,
                        $channelId,
                        $managerCategoryId,
                        $permission
                    );
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO posted_posts
                            (channel_id,author_id,text,text_entities_json,autosignature_text,autosignature_entities_json,
                             media_type,media_file_id,media_json,buttons_json,status,
                             silent,pin_after_publish,comments_enabled,is_ad,link_preview_enabled,link_preview_large,link_preview_above,
                             repost_chat_id,repost_message_id,repost_input_chat_id,repost_input_message_id,manager_category_id)
                         SELECT c.id,:author_id,:text,:text_entities_json,c.post_autosignature,
                                c.post_autosignature_entities_json,:media_type,:media_file_id,:media_json,
                                c.post_buttons_json,'draft',
                                c.post_silent,c.post_pin,c.post_comments,0,c.post_preview,c.post_preview_large,c.post_preview_above,
                                :repost_chat_id,:repost_message_id,:repost_input_chat_id,:repost_input_message_id,:manager_category_id
                         FROM posted_channels c
                         WHERE c.id=:channel_id AND c.is_active=1 AND c.added_by IS NOT NULL AND c.added_by>0"
                    );
                    $stmt->execute([
                        'channel_id' => $channelId,
                        'author_id' => $userId,
                        'text' => $content['text'] !== '' ? $content['text'] : null,
                        'text_entities_json' => $content['text_entities'] !== []
                            ? $this->encodeJson($content['text_entities'])
                            : null,
                        'media_type' => $content['media_type'],
                        'media_file_id' => $content['media_file_id'],
                        'media_json' => $this->encodeJson($content['media']),
                        'repost_chat_id' => $repost['chat_id'] ?? null,
                        'repost_message_id' => $repost['message_id'] ?? null,
                        'repost_input_chat_id' => $repostInput['chat_id'] ?? null,
                        'repost_input_message_id' => $repostInput['message_id'] ?? null,
                        'manager_category_id'=>$resolvedCategoryId,
                    ]);
                    $postId = (int) $this->pdo->lastInsertId();
                    if ($stmt->rowCount() !== 1 || $postId <= 0) {
                        throw new RuntimeException('Не удалось создать черновик.');
                    }
                    $post = $this->post($postId);
                    if(is_array($post)&&isset($content['native_content']))PostedTelegramNativeStore::captureLocked($this->pdo,$post,$content['native_content']);
                    if (is_array($post)) {
                        PostedTelegramCaptionPlacement::captureLocked($this->pdo,$post,$captionAbove);
                        if(!isset($content['native_content'])&&!$this->telegramInboundSpoilerLocked($post,$content))throw new InvalidArgumentException('Спойлер нельзя совмещать с платным медиа.');
                        $buttons = $this->rebindInteractiveButtons(
                            $this->decodeJson((string) ($post['buttons_json'] ?? '[]')),
                            $postId
                        );
                        $this->pdo->prepare('UPDATE posted_posts SET buttons_json=:buttons WHERE id=:id')
                            ->execute([
                                'buttons' => $buttons !== [] ? $this->encodeJson($buttons) : null,
                                'id' => $postId,
                            ]);
                    }
                    if(is_array($post)&&!isset($content['native_content']))$this->registerTelegramAlbumLocked($userId,$post,$message);
                    return $postId;
                }
            );
        } catch (Throwable $exception) {
            if($exception instanceof PostedTelegramAlbumStaleInputException)throw $exception;
            if(!$nativeInput){if($this->telegramAlbumIngressActive)$this->clearTelegramAlbumInputIfCurrent($userId);else$this->clearState($userId);}
            $this->telegram->sendMessage($userId, 'Нет доступа к каналу: ' . $exception->getMessage());
            return;
        }
        $groupId = trim((string) ($message['media_group_id'] ?? ''));
        if($this->hasTelegramAlbumCollection($userId,$postId,$message))return;
        if ($groupId !== '') {
            $this->setState($userId, 'collecting_media_group', ['post_id' => $postId, 'media_group_id' => $groupId]);
        } else {
            $this->clearState($userId);
        }
        $this->showDraftActions($userId, $postId);
    }

    private function rebindInteractiveButtons(array $buttons, int $postId): array
    {
        foreach($buttons as $rowIndex=>$row){
            foreach((array)$row as $buttonIndex=>$button){
                if(!is_array($button))continue;
                $callback=(string)($button['callback_data']??'');
                if(preg_match('/^(reaction|hidden):\d+:([A-Za-z0-9_]+)$/',$callback,$match)===1){
                    $buttons[$rowIndex][$buttonIndex]['callback_data']=$match[1].':'.$postId.':'.$match[2];
                }
            }
        }
        return $buttons;
    }

    private function handleDraftCallback(int $userId, string $data, ?array $target = null): void
    {
        $parts = explode(':', $data);
        $target = $this->telegramDraftEditorCallbackTarget($parts, $target);
        $action = (string) ($parts[1] ?? '');
        $action = match ($action) { 'sc' => 'schedule_confirm', 'sn' => 'schedule_noconfirm', default => $action };
        $postId = (int) ($parts[2] ?? 0);
        $desiredFlag=in_array((string)($parts[3]??''),['0','1'],true)
            ?(int)$parts[3]
            :null;
        $post = $this->post($postId);
        if (!$post || !$this->canManagePost($userId, $postId)) {
            $this->telegram->sendMessage($userId, 'Пост не найден или нет доступа.');
            return;
        }
        if ((string) ($post['status'] ?? '') !== 'draft') {
            $this->telegram->sendMessage($userId, 'Черновик уже не активен.');
            return;
        }
        if($target!==null){
            $targetMessageId=max(0,(int)($target['message_id']??0));
            $previewMessageIds=$this->decodeMessageSlots(
                (string)($post['telegram_preview_message_ids']??'')
            );
            $previewControlId=max(0,(int)($post['telegram_preview_control_message_id']??0));
            if(
                $targetMessageId>0
                &&(
                    in_array($targetMessageId,$previewMessageIds,true)
                    ||$targetMessageId===$previewControlId
                )
            ){
                $target['preserve_message']=true;
            }
        }
        if($this->telegramAlbumButtonAction($action)&&!$this->telegramPostInlineButtonsAllowed($post)){
            $this->telegram->sendMessage($userId,'Кнопки недоступны для альбомов.');return;
        }
        if(PostedTelegramNativeContent::isKind($post['media_type']??null)&&in_array($action,['edit_text','add_media','remove_media','preview','media','convert_copy'],true)){$this->showDraftActions($userId,$postId,'Для этого вида содержимого действие недоступно. Данные сохранены.',$target);return;}
        if($action==='main'){
            $this->showDraftActions($userId,$postId,'',$target);
            return;
        }
        if (
            $this->isNativeRepost($post)
            && !$this->isNativeRepostDraftActionSupported($action)
        ) {
            $this->showDraftActions(
                $userId,
                $postId,
                'Этот репост будет опубликован без изменений. Чтобы изменить текст, медиа или добавить кнопки, сначала выберите «Сделать копией для редактирования».',
                $target
            );
            return;
        }
        if($action==='send'&&!empty(($this->user($userId)??[])['setting_confirm_schedule'])){
            $confirmation = $this->telegramDraftConfirmationKey($userId, $post, 'send');
            $this->sendOrEditMessage(
                $userId,
                'Вы уверены, что хотите опубликовать пост?',
                ['reply_markup'=>['inline_keyboard'=>[
                    [['text'=>'Да, опубликовать','callback_data'=>'draft:send_confirm:'.$postId.':'.$confirmation]],
                    [['text'=>'← Назад','callback_data'=>'draft:next:'.$postId]],
                ]]],
                $target
            );
            return;
        }
        if ($action === 'send'||$action==='send_confirm') {
            if(!$this->can($userId,(int)$post['channel_id'],'post')){
                $this->telegram->sendMessage($userId,'Нет права публиковать в этом канале.');
                return;
            }
            try {
                $claimed=$this->withTelegramManagedPostLock($userId,$postId,['draft'],
                    function (array $lockedPost) use ($userId,$postId,$action,$parts): ?array {
                        if ($action === 'send_confirm') {
                            $this->assertTelegramDraftConfirmation($userId,$lockedPost,'send',(string)($parts[3]??''));
                        }
                        $claimed=$this->claimPostForPublish($postId,['draft']);
                        if ($claimed !== null) $this->clearState($userId);
                        return $claimed;
                    },false,false,true
                );
            } catch (InvalidArgumentException $exception) {
                $this->telegram->sendMessage($userId,$exception->getMessage());
                return;
            }
            if(!$claimed){
                $this->telegram->sendMessage($userId,'Пост уже публикуется или был обработан.');
                return;
            }
            try {
                $outcome=$this->publishClaimedPost($claimed, $userId);
            } catch (Throwable $exception) {
                $this->telegram->sendMessage($userId, 'Не удалось отправить: ' . $exception->getMessage());
                return;
            }
            $this->sendTelegramPublicationOutcome($userId,$claimed,$outcome,$target);
            return;
        }
        if ($action === 'convert_copy') {
            $converted = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($postId): bool {
                    if (!$this->isNativeRepost($lockedPost)) {
                        return true;
                    }
                    if (!$this->hasStoredRepostCopy($lockedPost)) {
                        return false;
                    }
                    if(PostedTelegramSourceSpoiler::transitionToCopyLocked($this->pdo,$lockedPost)){
                        if($this->telegramPostOptions($lockedPost)['paid_media_stars']!==null)throw new InvalidArgumentException('Спойлер источника нельзя заменить платным медиа.');
                        PostedTelegramPostOptions::saveLocked($this->pdo,$lockedPost,['media_spoiler'=>true]);
                    }
                    $this->telegramMultipostConvertCopyLocked($lockedPost);
                    $this->pdo->prepare(
                        "UPDATE posted_posts
                         SET repost_chat_id=NULL,repost_message_id=NULL,
                             repost_input_chat_id=NULL,repost_input_message_id=NULL
                         WHERE id=:id AND status='draft'"
                    )->execute(['id' => $postId]);
                    return true;
                }
            );
            if ($converted !== true) {
                $this->telegram->sendMessage(
                    $userId,
                    'Не удалось сделать редактируемую копию: Telegram не передал содержимое этого репоста.'
                );
                return;
            }
            $this->clearState($userId);
            $this->showDraftActions(
                $userId,
                $postId,
                'Репост преобразован в обычную копию. Теперь можно менять текст, медиа и добавлять кнопки.',
                $target
            );
            return;
        }
        if ($action === 'edit_text') {
            $this->setState($userId, 'await_draft_text', ['post_id' => $postId]);
            $this->telegram->sendMessage($userId, 'Отправьте новый текст поста.');
            return;
        }
        if ($action === 'add_media') {
            if ($this->telegramPreviewMediaEligible($post)) {
                if (!$this->beginTelegramDraftPreviewMedia($userId, $postId, $target)) {
                    $this->telegram->sendMessage($userId, 'Меню устарело. Откройте добавление медиа заново.');
                }
                return;
            }
            $this->setState($userId, 'await_draft_media', ['post_id' => $postId]);
            $this->telegram->sendMessage($userId, 'Отправьте новое медиа. Чтобы снять медиа, нажмите кнопку удаления медиа.');
            return;
        }
        if ($action === 'remove_media') {
            if ($this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($postId): bool {
                    if ($this->isNativeRepost($lockedPost)) return false;
                    PostedTelegramSourceSpoiler::detachLocked($this->pdo,$lockedPost);
                    PostedTelegramCaptionVector::detachLocked($this->pdo,$lockedPost);
                    PostedTelegramCaptionPlacement::captureLocked($this->pdo,$lockedPost,false);
                    $this->pdo->prepare(
                        "UPDATE posted_posts
                         SET media_type='none',media_file_id=NULL,media_json=NULL
                         WHERE id=:id AND status='draft'"
                    )->execute(['id' => $postId]);
                    PostedTelegramPostOptions::saveLocked($this->pdo,$lockedPost,['media_spoiler'=>false,'paid_media_stars'=>null]);
                    return true;
                }
            ) !== true) {
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->showDraftActions($userId, $postId, 'Медиа откреплено.', $target);
            return;
        }
        if ($action === 'silent') {
            $updated=$desiredFlag===null
                ?$this->togglePostFlag($userId,$postId,'silent')
                :$this->setPostFlag($userId,$postId,'silent',$desiredFlag);
            if (!$updated) {
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->showDraftActions($userId, $postId, 'Настройка уведомления изменена.', $target);
            return;
        }
        if ($action === 'pin') {
            $updated=$desiredFlag===null
                ?$this->togglePostFlag($userId,$postId,'pin_after_publish')
                :$this->setPostFlag($userId,$postId,'pin_after_publish',$desiredFlag);
            if (!$updated) {
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->showDraftActions($userId, $postId, 'Настройка закрепления изменена.', $target);
            return;
        }
        if ($action === 'comments') {
            $this->setTelegramDraftComments($userId, $postId, $desiredFlag, $target);
            return;
        }
        if ($action === 'reply') {
            $this->setState($userId, 'await_reply_post', ['post_id' => $postId]);
            $this->telegram->sendMessage($userId, 'Перешлите пост из этого же канала, чтобы новый пост вышел ответом на него. Отправьте "-" чтобы убрать ответный пост.');
            return;
        }
        if ($action === 'more') {
            $this->showDraftMoreSettings($userId, $postId, $target);
            return;
        }
        if ($action === 'next') {
            $this->showDraftSendSettings($userId, $postId, $target);
            return;
        }
        if ($action === 'preview') { $this->showDraftPreviewSettings($userId,$postId,$target); return; }
        if ($action === 'preview_toggle') { if($desiredFlag===null?$this->togglePostFlag($userId,$postId,'link_preview_enabled'):$this->setPostFlag($userId,$postId,'link_preview_enabled',$desiredFlag))$this->showDraftPreviewSettings($userId,$postId,$target); return; }
        if ($action === 'preview_large') { if($desiredFlag===null?$this->togglePostFlag($userId,$postId,'link_preview_large'):$this->setPostFlag($userId,$postId,'link_preview_large',$desiredFlag))$this->showDraftPreviewSettings($userId,$postId,$target); return; }
        if ($action === 'preview_position') { if($desiredFlag===null?$this->togglePostFlag($userId,$postId,'link_preview_above'):$this->setPostFlag($userId,$postId,'link_preview_above',$desiredFlag))$this->showDraftPreviewSettings($userId,$postId,$target); return; }
        if ($action === 'replace_links') {
            $this->beginDraftInput($userId,'await_replace_links',['post_id'=>$postId],"🔃 ЗАМЕНА ССЫЛОК

В этом разделе вы можете быстро заменить все ссылки в сообщении.

Отправьте новую ссылку, на которую нужно заменить все остальные.", [
                'reply_markup' => ['inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'draft:cancel_timer:' . $postId]]]],
            ], $target);
            return;
        }
        if ($action === 'ad') {
            $updated = $this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($postId,$desiredFlag): bool {
                    if ($this->isNativeRepost($lockedPost)) return false;
                    $enable=$desiredFlag??(empty($lockedPost['is_ad'])?1:0);
                    if((empty($lockedPost['is_ad'])?0:1)===$enable)return true;
                    if ($enable===1) {
                        $this->pdo->prepare(
                            "UPDATE posted_posts p JOIN posted_channels c ON c.id=p.channel_id
                             SET p.is_ad=1,p.autosignature_text=c.ad_autosignature,
                                 p.autosignature_entities_json=c.ad_autosignature_entities_json,
                                 p.silent=c.ad_silent,
                                 p.pin_after_publish=c.ad_pin,p.comments_enabled=c.ad_comments,
                                 p.delete_after_minutes=c.ad_delete_minutes
                             WHERE p.id=:id AND p.status='draft'"
                        )->execute(['id'=>$postId]);
                    } else {
                        $this->pdo->prepare(
                            "UPDATE posted_posts p JOIN posted_channels c ON c.id=p.channel_id
                             SET p.is_ad=0,p.autosignature_text=c.post_autosignature,
                                 p.autosignature_entities_json=c.post_autosignature_entities_json,
                                 p.silent=c.post_silent,
                                 p.pin_after_publish=c.post_pin,p.comments_enabled=c.post_comments,
                                 p.delete_after_minutes=NULL,p.link_preview_enabled=c.post_preview,
                                 p.link_preview_large=c.post_preview_large,p.link_preview_above=c.post_preview_above
                             WHERE p.id=:id AND p.status='draft'"
                        )->execute(['id'=>$postId]);
                    }
                    return true;
                }
            );
            if ($updated !== true) {
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->showDraftActions($userId, $postId, '', $target);
            return;
        }
        if ($action === 'copy' || $action === 'copycat') {
            $managerCategoryId=$action==='copycat'?(int)($parts[3]??0):null;
            if($managerCategoryId===null){
                $categories=$this->activeTelegramManagerCategories($userId,(int)$post['channel_id']);
                if($categories!==[]){
                    $rows=[];
                    foreach($categories as $category){
                        $rows[]=[[
                            'text'=>mb_substr(
                                (string)$category['name'].' · '
                                    .$this->managerQuotaLabel($category['post_quota_remaining']??null,'постов'),
                                0,
                                60
                            ),
                            'callback_data'=>'draft:copycat:'.$postId.':'.(int)$category['id'],
                        ]];
                    }
                    $rows[]=[['text'=>'← Назад','callback_data'=>'draft:main:'.$postId]];
                    $this->sendOrEditMessage(
                        $userId,
                        'Выберите рекламную компанию для копии поста.',
                        ['reply_markup'=>['inline_keyboard'=>$rows]],
                        $target
                    );
                    return;
                }
            }
            try {
                $newId = $this->copyPostForUser(
                    $userId,
                    $postId,
                    false,
                    $managerCategoryId
                );
            } catch (Throwable $exception) {
                $this->telegram->sendMessage(
                    $userId,
                    'Права на черновик или рекламная компания изменились. Откройте пост заново.'
                );
                return;
            }
            $this->showDraftActions($userId, $newId);
            return;
        }
        if ($action === 'forward') {
            $this->telegram->sendMessage($userId, 'Пересылка в другой канал будет доступна через выбор канала. Сейчас можно использовать «Копировать» и выбрать нужный канал при создании нового поста.');
            return;
        }
        if($action==='buttons_clear'){$this->clearTelegramDraftUrlButtons($userId,$data,$target);return;}
        if ($action === 'buttons') {
            $this->showTelegramDraftUrlButtonMenu($userId,$postId,$target);
            return;
        }
        if ($action === 'reactions') {
            $this->beginDraftInput($userId,'await_reactions',['post_id'=>$postId],"❤️ КНОПКИ: реакции\n\nОтправьте боту список реакций, разделяя их символом «/», в следующем формате:\n\n👍/👎/🤯\n\nЧтобы разместить реакции в несколько строчек, разделите их переносом строки:\n\n👍/👎\n🔥/💀\n\nЧтобы установить цвет кнопке, добавьте «!r», «!g» или «!b» перед кнопкой:\n\n!r 👍 Красный / !b 👎 Синий", [
                'entities' => [['type'=>'custom_emoji','offset'=>0,'length'=>2,'custom_emoji_id'=>'5258179403652801593'],['type'=>'bold','offset'=>3,'length'=>15],['type'=>'blockquote','offset'=>99,'length'=>8],['type'=>'blockquote','offset'=>187,'length'=>11],['type'=>'code','offset'=>240,'length'=>2],['type'=>'code','offset'=>246,'length'=>2],['type'=>'code','offset'=>255,'length'=>2],['type'=>'blockquote','offset'=>275,'length'=>27],['type'=>'bold','offset'=>275,'length'=>27]],
                'reply_markup' => ['inline_keyboard' => $this->buttonTypeKeyboard($postId, 'reactions')],
            ], $target);
            return;
        }
        if ($action === 'hidden') {
            $keyboard = $this->buttonTypeKeyboard($postId, 'hidden');
            $back = array_pop($keyboard);
            $keyboard[] = [['text' => '➕', 'callback_data' => 'draft:hidden_add:' . $postId]];
            $keyboard[] = $back;
            $this->sendOrEditMessage($userId, "👁 КНОПКИ: скрытое продолжение\n\nВ этом разделе вы можете добавить кнопки, под которые вы можете скрыть текст от тех, кто не подписан на ваш канал.", [
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ], $target);
            return;
        }
        if ($action === 'hidden_add') {
            $this->beginDraftInput(
                $userId,
                'await_hidden_label',
                ['post_id'=>$postId],
                "Отправьте боту название кнопки.\n\nВаши подписчики увидят скрытый текст, нажав на кнопку.\n\nЧтобы установить цвет кнопке, добавьте «!r», «!g» или «!b» перед названием.",
                ['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'draft:hidden:'.$postId]]]]],
                $target
            );
            return;
        }
        if ($action === 'paintopen') {
            $this->showDraftButtonColorMenu($userId, $postId, (string) ($parts[3] ?? 'danger'), $target, false);
            return;
        }
        if ($action === 'paint') {
            $this->showDraftButtonColorMenu($userId, $postId, (string) ($parts[3] ?? 'danger'), $target, true);
            return;
        }
        if ($action === 'paintbtn') {
            $this->paintDraftButton($userId, $postId, (string) ($parts[3] ?? 'success'), (int) ($parts[4] ?? -1), (int) ($parts[5] ?? -1), $target);
            return;
        }
        if ($action === 'paintrow') {
            $this->paintDraftButtonRow($userId, $postId, (string) ($parts[3] ?? 'success'), (int) ($parts[4] ?? -1), $target);
            return;
        }
        if ($action === 'paintcol') {
            $this->paintDraftButtonColumn($userId, $postId, (string) ($parts[3] ?? 'success'), (int) ($parts[4] ?? -1), $target);
            return;
        }
        if ($action === 'paintall') {
            $this->paintDraftAllButtons($userId, $postId, (string) ($parts[3] ?? 'success'), $target);
            return;
        }
        if ($action === 'schedule') {
            $this->showDraftScheduleMenu($userId,$postId,$this->todayForUser($userId),$target);
            return;
        }
        if($action==='scheduledate'||$action==='scheduletimes'){
            $date=(string)($parts[3]??'');
            if(preg_match('/^(\d{4})(\d{2})(\d{2})$/D',$date,$dateMatch)!==1
                ||!checkdate((int)$dateMatch[2],(int)$dateMatch[3],(int)$dateMatch[1]))return;
            $date=substr($date,0,4).'-'.substr($date,4,2).'-'.substr($date,6,2);
            $this->showDraftScheduleMenu($userId,$postId,$date,$target,$action==='scheduletimes');
            return;
        }
        if($action==='scheduletime'){
            $date=(string)($parts[3]??'');
            $time=(string)($parts[4]??'');
            if(preg_match('/^(\d{4})(\d{2})(\d{2})$/D',$date,$dateMatch)!==1
                ||!checkdate((int)$dateMatch[2],(int)$dateMatch[3],(int)$dateMatch[1])
                ||preg_match('/^([01]\d|2[0-3])(00|30)$/D',$time,$timeMatch)!==1)return;
            $date=$dateMatch[1].'-'.$dateMatch[2].'-'.$dateMatch[3];
            try {
                $scheduledAt=$this->parseScheduleForDate($userId,$timeMatch[1].':'.$timeMatch[2],$date);
            } catch (InvalidArgumentException $exception) {
                $this->telegram->sendMessage($userId,$exception->getMessage());
                return;
            }
            if($scheduledAt===null){
                $this->telegram->sendMessage($userId,'Не удалось распознать дату и время. Выберите время заново.');
                return;
            }
            $this->clearState($userId);
            $this->confirmOrScheduleDraft($userId,$postId,$scheduledAt,$target);
            return;
        }
        if($action==='schedule_confirm'||$action==='schedule_noconfirm'){
            $stamp=(string)($parts[3]??'');
            $when=preg_match('/^[0-9]{14}$/D',$stamp)===1
                ?DateTimeImmutable::createFromFormat('!YmdHis',$stamp,$this->storageTimezone())
                :false;
            if(!$when||$when->format('YmdHis')!==$stamp||$when<=new DateTimeImmutable('now',$this->storageTimezone())){
                $this->telegram->sendMessage($userId,'Это подтверждение устарело. Выберите дату и время заново.');
                return;
            }
            $this->scheduleDraft($userId,$postId,$when->format('Y-m-d H:i:s'),(string)($parts[4]??''),$action==='schedule_noconfirm');
            return;
        }
        if($action==='scheduleslot'){
            $slot=(string)($parts[3]??'');
            if(preg_match('/^([01]\d|2[0-3])([0-5]\d)$/',$slot,$match)!==1)return;
            $scheduledAt = $this->withTelegramManagedPostLock(
                $userId,$postId,['draft'],
                function (array $_post, array $channel) use ($userId, $match): string {
                    $timezoneName=(string)($channel['schedule_timezone']??$this->userTimezone($userId));
                    try{$timezone=new DateTimeZone($timezoneName);}catch(Throwable){$timezone=$this->userTimezoneObject($userId);}
                    $now=new DateTimeImmutable('now',$timezone);
                    $when=new DateTimeImmutable($now->format('Y-m-d').' '.$match[1].':'.$match[2].':00',$timezone);
                    if($when<=$now)$when=$when->modify('+1 day');
                    return $when->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
                }
            );
            if (!is_string($scheduledAt) || $scheduledAt === '') {
                $this->clearState($userId);
                $this->telegram->sendMessage($userId,'Черновик уже недоступен для планирования.');
                return;
            }
            $this->confirmOrScheduleDraft($userId,$postId,$scheduledAt,$target);
            return;
        }
        if ($action === 'delete_timer') {
            $this->showDraftDeleteTimerMenu($userId, $postId, $target);
            return;
        }
        if($action==='delete_custom'){
            $this->setState($userId,'await_delete_timer',['post_id'=>$postId]);
            $this->showDraftDeleteTimerMenu($userId,$postId,$target);
            return;
        }
        if($action==='delete_report'){
            $updated=$desiredFlag===null
                ?$this->togglePostFlag($userId,$postId,'delete_report_enabled')
                :$this->setPostFlag($userId,$postId,'delete_report_enabled',$desiredFlag);
            if(!$updated){
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->showDraftDeleteTimerMenu($userId,$postId,$target);
            return;
        }
        if ($action === 'setdelete') {
            $this->setDraftDeleteTimer($userId, $postId, (int) ($parts[3] ?? 0), $target);
            return;
        }
        if ($action === 'repeat_timer') {
            $this->showDraftRepeatMenu($userId, $postId, $target);
            return;
        }
        if ($action === 'setrepeat') {
            $this->setDraftRepeat($userId, $postId, (int) ($parts[3] ?? 0), $target);
            return;
        }
        if ($action === 'cancel_timer') {
            $this->clearState($userId);
            $this->showDraftActions($userId, $postId, '', $target);
            return;
        }
        if ($action === 'cancel') {
            $this->clearState($userId);
            if ($this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function () use ($postId): bool {
                    $this->pdo->prepare(
                        "UPDATE posted_posts SET status='cancelled'
                         WHERE id=:id AND status='draft'"
                    )->execute(['id' => $postId]);
                    return true;
                }
            ) !== true) {
                $this->telegram->sendMessage($userId, 'Черновик уже недоступен.');
                return;
            }
            $this->telegram->sendMessage($userId, 'Черновик отменён.');
        }
    }

    private function confirmOrScheduleDraft(int $userId,int $postId,string $scheduledAt,?array $target=null):void
    {
        $post=$this->post($postId);
        if(!$post||!$this->can($userId,(int)$post['channel_id'],'schedule')){
            $this->telegram->sendMessage($userId,'Черновик уже недоступен для планирования.');
            return;
        }
        $user=$this->user($userId)??[];
        if(empty($user['setting_confirm_schedule'])){
            $this->scheduleDraft($userId,$postId,$scheduledAt);
            return;
        }
        $local=$this->storedDateForUser($userId,$scheduledAt);
        if(!$local)return;
        $when=new DateTimeImmutable($scheduledAt,$this->storageTimezone());
        $stamp=$when->format('YmdHis');
        $confirmation=$this->telegramDraftConfirmationKey($userId,$post,'schedule',$when->format('Y-m-d H:i:s'));
        $text='Запланировать пост в канал '.(string)($post['channel_title']??'').' на '.$this->humanDate($local->format('Y-m-d')).' в '.$local->format('H:i').'?';
        $this->sendOrEditMessage($userId,$text,['reply_markup'=>['inline_keyboard'=>[
            [['text'=>'Да, запланировать','callback_data'=>'draft:sc:'.$postId.':'.$stamp.':'.$confirmation]],
            [['text'=>'☑️ Больше не спрашивать','callback_data'=>'draft:sn:'.$postId.':'.$stamp.':'.$confirmation]],
            [['text'=>'← Назад','callback_data'=>'draft:schedule:'.$postId]],
        ]]],$target);
    }

    private function scheduleDraft(int $userId,int $postId,string $scheduledAt,?string $confirmation=null,bool $disableConfirmation=false,bool $calendar=false):void
    {
        try {
        $updated=$this->withTelegramManagedPostLock(
            $userId,$postId,['draft'],
            function(array $lockedPost)use($userId,$postId,$scheduledAt,$confirmation,$disableConfirmation,$calendar):bool{
                if($calendar){$when=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$scheduledAt,$this->storageTimezone());if(!$when||$when->format('Y-m-d H:i:s')!==$scheduledAt||$when->getTimestamp()<=time())throw new InvalidArgumentException('Выбранное время уже прошло. Обновите график.');PostedTelegramAlbumCollector::assertReadyLocked($this->pdo,$lockedPost);}
                if ($confirmation !== null) {
                    $this->assertTelegramDraftConfirmation($userId,$lockedPost,'schedule',$confirmation,$scheduledAt);
                }
                $topChannel=$this->channel((int)$lockedPost['channel_id']);PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$topChannel,$lockedPost,$scheduledAt);
                $stmt=$this->pdo->prepare("UPDATE posted_posts SET status='scheduled',scheduled_at=:scheduled_at WHERE id=:id AND status='draft'");
                $stmt->execute(['scheduled_at'=>$scheduledAt,'id'=>$postId]);
                if ($stmt->rowCount()!==1) return false;
                if ($disableConfirmation) {
                    $this->pdo->prepare('UPDATE posted_users SET setting_confirm_schedule=0 WHERE telegram_id=:user AND is_active=1')
                        ->execute(['user'=>$userId]);
                }
                $this->clearState($userId);
                return true;
            },true
        );
        } catch (InvalidArgumentException $exception) {
            $this->telegram->sendMessage($userId,$exception->getMessage());
            return;
        }
        if($updated!==true){
            $this->telegram->sendMessage($userId,'Черновик уже недоступен для планирования.');
            return;
        }
        $this->telegram->sendMessage($userId,'Пост запланирован на '.$this->formatStoredForUser($userId,$scheduledAt,'d.m.Y H:i').'.');
    }

    private function telegramDraftConfirmationKey(int $userId,array $post,string $purpose,string $scheduledAt=''):string
    {
        if (!isset($post['chat_id'])) $post['chat_id']=(string)($this->channel((int)$post['channel_id'])['chat_id']??'');
        $user=$this->user($userId)??[];
        $options=[];
        foreach (['author_id','silent','pin_after_publish','delete_after_minutes','delete_report_enabled',
            'repeat_interval_minutes','repeat_until','comments_enabled','is_ad','link_preview_enabled',
            'link_preview_large','link_preview_above','manager_category_id','import_json'] as $field) {
            $options[$field]=$post[$field]??null;
        }
        return substr(hash('sha256',$this->encodeJson([
            'version'=>1,'post'=>(int)$post['id'],'actor'=>$userId,'purpose'=>$purpose,
            'timezone'=>$this->userTimezone($userId),'scheduled_at'=>$scheduledAt,
            'state'=>$user['state']??null,'state_data'=>$user['state_data']??null,
            'confirmation_enabled'=>(int)($user['setting_confirm_schedule']??0),
            'submission'=>$this->telegramSubmissionKey($post),'options'=>$options,
            'delivery_options'=>$this->telegramPostOptions($post),
        ])),0,12);
    }

    private function assertTelegramDraftConfirmation(int $userId,array $post,string $purpose,string $confirmation,string $scheduledAt=''):void
    {
        if (preg_match('/^[a-f0-9]{12}$/D',$confirmation)!==1
            || !hash_equals($this->telegramDraftConfirmationKey($userId,$post,$purpose,$scheduledAt),$confirmation)) {
            throw new InvalidArgumentException('Пост или настройки изменились после открытия подтверждения. Откройте карточку поста и подтвердите актуальные настройки заново.');
        }
    }

    private function showDraftScheduleMenu(int $userId,int $postId,string $date,?array $target=null,bool $showTimes=false):void
    {
        $post=$this->post($postId);if(!$post)return;
        $timezone=$this->userTimezoneObject($userId);
        $selected=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$timezone);
        if(!$selected)$selected=new DateTimeImmutable('today',$timezone);
        $date=$selected->format('Y-m-d');
        $channel=$this->channel((int)$post['channel_id']);
        $times=$this->decodeJson((string)($channel['schedule_json']??'[]'));
        $keyboard=[];
        foreach(array_chunk($showTimes?[]:$times,4) as $row){
            $buttons=[];
            foreach($row as $time){
                if(preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)$time)!==1)continue;
                $buttons[]=['text'=>(string)$time,'callback_data'=>'draft:scheduleslot:'.$postId.':'.str_replace(':','',(string)$time)];
            }
            if($buttons!==[])$keyboard[]=$buttons;
        }
        if($showTimes){
            $buttons=[];
            for($minute=0;$minute<1440;$minute+=30){
                $time=sprintf('%02d:%02d',intdiv($minute,60),$minute%60);
                $buttons[]=['text'=>$time,'callback_data'=>'draft:scheduletime:'.$postId.':'.$selected->format('Ymd').':'.str_replace(':','',$time)];
                if(count($buttons)===4){$keyboard[]=$buttons;$buttons=[];}
            }
        }
        $compact=static fn(DateTimeImmutable $value):string=>$value->format('Ymd');
        $dateAction=$showTimes?'scheduletimes':'scheduledate';
        $keyboard[]=[
            ['text'=>'← '.$selected->modify('-1 day')->format('d.m'),'callback_data'=>'draft:'.$dateAction.':'.$postId.':'.$compact($selected->modify('-1 day'))],
            ['text'=>$selected->format('d.m'),'callback_data'=>'draft:scheduletimes:'.$postId.':'.$compact($selected)],
            ['text'=>$selected->modify('+1 day')->format('d.m').' →','callback_data'=>'draft:'.$dateAction.':'.$postId.':'.$compact($selected->modify('+1 day'))],
        ];
        $keyboard[]=[['text'=>'← Назад','callback_data'=>'draft:next:'.$postId]];
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM posted_posts WHERE channel_id=:channel AND status='scheduled' AND scheduled_at>=:start AND scheduled_at<:finish");
        $start=$selected->setTimezone($this->storageTimezone());
        $stmt->execute(['channel'=>(int)$post['channel_id'],'start'=>$start->format('Y-m-d H:i:s'),'finish'=>$start->modify('+1 day')->format('Y-m-d H:i:s')]);
        $count=(int)$stmt->fetchColumn();
        $status=$count===0?'не запланировано ни одного поста':'запланировано постов: '.$count;
        $text="🕔 ОТЛОЖИТЬ ПОСТ\n\nНа ".$this->humanDate($date).' '.$status
            .".\n\nОтправьте дату и время публикации в вашем часовом поясе: сначала дата, затем время.\n\nНапример:\n"
            .$this->scheduleInputExamples($userId,$date,"\n")
            ."\n\nМожно отправить только время для выбранной даты: 18:30, 18 30 или 1830.\n"
            .($showTimes?'Выберите время ниже или введите дату и время вручную.':'Нажмите на дату, чтобы выбрать время кнопкой.');
        $menuId=$this->sendOrEditMessage($userId,$text,[
            'reply_markup'=>['inline_keyboard'=>$keyboard],
        ],$target);
        $state=['post_id'=>$postId,'date'=>$date];
        if($menuId>0)$state['menu_message_id']=$menuId;
        $this->setState($userId,'await_schedule',$state);
    }

    private function handleLegacyEditCallback(int $userId, string $data): void
    {
        $parts = explode(':', $data);
        $action = (string) ($parts[1] ?? '');
        $postId = (int) ($parts[2] ?? 0);
        $desiredFlag=in_array((string)($parts[3]??''),['0','1'],true)
            ?(int)$parts[3]
            :null;
        if($this->handleTelegramPublishedAlbumEditCallback($userId,$postId,$action,$parts))return;
        $post = $this->editSessionPost($userId,$postId);
        if (!$post || !$this->can($userId, (int) $post['channel_id'], 'manage_posts')) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Пост не найден, сессия устарела или нет доступа.');
            return;
        }

        if($action==='editor_back'){$this->showEditPostActions($userId,$postId);return;}
        if($action==='clear_text'){if($this->stageEditablePostChanges($userId,$postId,['text'=>null,'text_entities_json'=>[]])){$this->clearState($userId);$this->showEditPostActions($userId,$postId);}return;}
        if ($action === 'text') {
            $this->setState($userId, 'await_edit_text', ['post_id' => $postId]);
            $isMedia=(string)($post['media_type']??'none')!=='none';
            $this->telegram->sendMessage($userId,$isMedia?'Отправьте новое описание для поста.':'Отправьте новый текст для поста.',['reply_markup'=>['inline_keyboard'=>[
                [['text'=>$isMedia?'Удалить описание':'Удалить текст','callback_data'=>'edit:clear_text:'.$postId]],[['text'=>'← Назад','callback_data'=>'edit:editor_back:'.$postId]],
            ]]]);
            return;
        }
        if ($action === 'media') {
            if ((string) ($post['original_media_type'] ?? $post['media_type'] ?? 'none') === 'voice') {
                $this->telegram->sendMessage($userId, 'Telegram Bot API не разрешает заменить уже опубликованное голосовое сообщение. Текст подписи и кнопки изменить можно.');
                return;
            }
            $this->setState($userId, 'await_edit_media', ['post_id' => $postId]);
            $this->telegram->sendMessage($userId, 'Отправьте новое медиа для замены.');
            return;
        }
        if ($action === 'buttons') {
            $prepared=$this->withTelegramManagedPostLock($userId,$postId,['sent'],function(array $lockedPost)use($userId,$postId):bool|string{
                if(!$this->telegramPostInlineButtonsAllowed($lockedPost))return 'album';
                $query=$this->pdo->prepare('SELECT * FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1 FOR UPDATE');
                $query->execute([$userId,$postId]);$session=$query->fetch();if(!is_array($session))return false;
                $document=$this->editSessionDocument($session,$lockedPost);
                if(!$this->telegramPostInlineButtonsAllowed($this->postWithEditableSnapshot($lockedPost,$document['draft'])))return 'album';
                $this->setState($userId,'await_edit_buttons',['post_id'=>$postId]);return true;
            },false,true);
            if($prepared!==true){$this->telegram->sendMessage($userId,$prepared==='album'?'Кнопки недоступны для альбомов.':'Сессия редактирования устарела. Откройте пост заново.');return;}
            $this->telegram->sendMessage($userId, $this->buttonsHelpText());
            return;
        }
        if ($action === 'pin') {
            if(!$this->stageEditablePostChanges(
                $userId,
                $postId,
                ['pin_after_publish'=>$desiredFlag??(empty($post['pin_after_publish'])?1:0)]
            )){
                return;
            }
            $this->showEditPostActions($userId, $postId, 'Настройка закрепления изменена.');
            return;
        }
        if ($action === 'save') {
            $this->saveEditedPost($userId, $postId);
            return;
        }
        if ($action === 'cancel') {
            $this->clearState($userId);
            $this->rollbackEditSession($userId,$postId);
            $this->telegram->sendMessage($userId, 'Изменение отменено.');
            return;
        }
    }

    private function updateEditableText(int $userId, int $postId, array $message): void
    {
        $post = $this->editSessionPost($userId,$postId);
        if (!$post || !$this->can($userId, (int) $post['channel_id'], 'manage_posts')) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Пост не найден, сессия устарела или нет доступа.');
            return;
        }
        $content = $this->telegramTextInputContent($message);
        $text = (string) $content['text'];
        if($text===''){$this->telegram->sendMessage($userId,'Отправьте новый текст или подпись.');return;}
        if(!$this->stageEditablePostChanges(
            $userId,
            $postId,
            [
                'text'=>$text!==''?$text:null,
                'text_entities_json'=>$content['text_entities'],
            ]
        )){
            return;
        }
        $this->clearState($userId);
        $this->showEditPostActions($userId, $postId, 'Текст обновлён.');
    }

    private function updateEditableMedia(int $userId, int $postId, array $message): void
    {
        $post = $this->editSessionPost($userId,$postId);
        if (!$post || !$this->can($userId, (int) $post['channel_id'], 'manage_posts')) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Пост не найден, сессия устарела или нет доступа.');
            return;
        }
        if ((string) ($post['original_media_type'] ?? $post['media_type'] ?? 'none') === 'none') {
            $this->telegram->sendMessage($userId, 'Telegram не разрешает превратить уже опубликованный текстовый пост в пост с медиа. Можно изменить текст и кнопки, либо создать новый пост с медиа.');
            return;
        }
        try{$content = $this->contentFromMessage($message);}catch(InvalidArgumentException){$this->telegram->sendMessage($userId,'Отправьте поддерживаемое медиа. Исходные данные и текущий ввод сохранены.');return;}
        if(isset($content['native_content'])){$this->telegram->sendMessage($userId,'Создайте новый пост с этим видом содержимого. Исходный пост сохранён.');return;}
        if ($content['media_file_id'] === null) {
            $this->telegram->sendMessage($userId, 'Отправьте фото, видео, анимацию, документ, аудио или голосовое сообщение.');
            return;
        }
        if ((string) ($post['original_media_type'] ?? $post['media_type'] ?? 'none') === 'voice'
            || $content['media_type'] === 'voice') {
            $this->telegram->sendMessage($userId, 'Telegram не разрешает заменить опубликованный файл голосовым сообщением или заменить само голосовое сообщение. Можно изменить подпись и кнопки; для нового файла создайте отдельный пост.');
            return;
        }
        try{$captionAbove=PostedTelegramCaptionPlacement::input($message,$content);}catch(InvalidArgumentException$e){$this->telegram->sendMessage($userId,$e->getMessage());return;}
        $changes=[
            'media_type'=>$content['media_type'],
            'media_file_id'=>$content['media_file_id'],
            'media_json'=>$content['media'],
        ];
        if(array_key_exists('caption',$message)||array_key_exists('text',$message)){
            $changes['text']=$content['text']!==''?$content['text']:null;
            $changes['text_entities_json']=$content['text_entities'];
        }
        if(!$this->stageEditablePostChanges($userId,$postId,$changes,$captionAbove)){
            return;
        }
        $this->clearState($userId);
        $this->showEditPostActions($userId, $postId, 'Медиа обновлено.');
    }

    private function showEditPostActions(int $userId, int $postId, string $prefixText = ''): void
    {
        if($this->showTelegramPublishedSingleEditor($userId,$postId,$prefixText))return;
        if($this->showTelegramPublishedAlbumEditor($userId,$postId))return;
        $post = $this->editSessionPost($userId,$postId);
        if (!$post || !$this->can($userId,(int)$post['channel_id'],'manage_posts')) {
            $this->clearState($userId);
            $this->telegram->sendMessage($userId, 'Пост не найден, сессия устарела или нет доступа.');
            return;
        }
        // An edit session previews its staged payload, even if the existing
        // delivery was forwarded. Keep the durable source and mode untouched.
        $this->sendPostPreview($userId, array_replace($post, [
            'repost_chat_id' => null,
            'repost_message_id' => null,
        ]));
        $pinButton = !empty($post['pin_after_publish']) ? '✅ Закрепить' : '☑️ Закрепить';
        $isMedia=(string)($post['media_type']??'none')!=='none';
        $mediaLabel=$isMedia?'Медиа':'Добавить медиа';
        $textLabel=$isMedia?'Изменить описание':'Изменить текст';
        $storedPost=$this->post($postId);
        $buttonsAllowed=is_array($storedPost)&&$this->telegramPostInlineButtonsAllowed($storedPost)&&$this->telegramPostInlineButtonsAllowed($post);
        $this->telegram->sendMessage($userId, 'Настройте пост перед публикацией.', [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => $mediaLabel, 'callback_data' => 'edit:media:' . $postId], ['text' => $textLabel, 'callback_data' => 'edit:text:' . $postId]],
                    ...($buttonsAllowed?[[['text'=>'Кнопки','callback_data'=>'edit:buttons:'.$postId]]]:[]),
                    [['text' => $pinButton, 'callback_data' => 'edit:pin:' . $postId . ':' . (empty($post['pin_after_publish'])?1:0)]],
                    [['text' => '💾 Сохранить изменения', 'callback_data' => 'edit:save:' . $postId]],
                    [['text' => '← Назад', 'callback_data' => 'edit:cancel:' . $postId]],
                ],
            ],
        ]);
    }

    private function saveEditedPost(int $userId, int $postId): void
    {
        if($this->saveTelegramPublicButtonEdit($userId,$postId))return;
        // Unsupported Placement edits retain this fallback HOLD; supported signed-photo edits were intercepted above.
        $placementHint=$this->post($postId);
        if(is_array($placementHint)&&($placementHint['status']??null)==='sent'&&PostedTelegramPolicy::operatorAllowed($this->config,$userId,$this->pdo)&&$this->isKnownActiveUser($this->user($userId))&&$this->can($userId,(int)$placementHint['channel_id'],'manage_posts')){
            $placementSession=$this->pdo->prepare('SELECT * FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1');$placementSession->execute([$userId,$postId]);$placementSession=$placementSession->fetch();
            if(is_array($placementSession)){
                try{$placementDocument=$this->editSessionDocument($placementSession,$placementHint);$placementHold=array_key_exists('caption_placement',$placementDocument);}catch(Throwable){$placementHold=true;}
                if($placementHold){if(!$this->pdo->inTransaction())$this->telegram->sendMessage($userId,'Изменения сохранены в редакторе. Безопасное сохранение описания над медиа пока недоступно; исходная публикация не изменялась.');return;}
            }
        }
        if($this->saveTelegramPublishedAlbumEdit($userId,$postId))return;
        $post = $this->post($postId);
        if (
            !$post
            ||(string)($post['status']??'')!=='sent'
            ||!$this->can($userId, (int) $post['channel_id'], 'manage_posts')
        ) {
            $this->telegramPublishedSingleSaveClear($userId);
            $this->telegram->sendMessage($userId, 'Пост уже недоступен для изменения или у вас больше нет прав.');
            return;
        }
        $remoteMutationApplied=false;
        $remoteSnapshot=null;
        $pinLockName='gitposted_pin_'.substr(hash('sha256',(string)$post['channel_id']),0,32);
        $pinLockHeld=false;
        try {
            // Use the worker/delete lock before SQL locks. A worker commits its
            // pin intent before calling Telegram, so row locks alone cannot
            // serialize an editor's unpin with that in-flight operation.
            $pinLock=$this->pdo->prepare('SELECT GET_LOCK(:lock_name,2)');
            $pinLock->execute(['lock_name'=>$pinLockName]);
            if ((int)$pinLock->fetchColumn()!==1) {
                $this->telegram->sendMessage($userId,'В канале завершается закрепление или удаление. Повторите сохранение через несколько секунд; ваши изменения сохранены в редакторе.');
                return;
            }
            $pinLockHeld=true;
            $this->pdo->beginTransaction();
            $channelStmt=$this->pdo->prepare(
                'SELECT chat_id,is_active,added_by
                 FROM posted_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelStmt->execute(['channel'=>(int)$post['channel_id']]);
            $lockedChannel=$channelStmt->fetch();
            if(
                !is_array($lockedChannel)
                ||empty($lockedChannel['is_active'])
                ||(int)($lockedChannel['added_by']??0)<=0
            ){
                $this->pdo->rollBack();
                $this->telegramPublishedSingleSaveClear($userId);
                $this->telegram->sendMessage($userId,'Канал отключён или его владелец изменился.');
                return;
            }

            $postStmt=$this->pdo->prepare(
                'SELECT *
                 FROM posted_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postStmt->execute([
                'post'=>$postId,
                'channel'=>(int)$post['channel_id'],
            ]);
            $lockedPost=$postStmt->fetch();
            if(!is_array($lockedPost)||(string)($lockedPost['status']??'')!=='sent'){
                $this->pdo->rollBack();
                $this->telegramPublishedSingleSaveClear($userId);
                $this->telegram->sendMessage($userId,'Пост уже недоступен для изменения.');
                return;
            }
            if (!$this->lockActiveTelegramActor($userId)) {
                $this->pdo->rollBack();
                $this->telegramPublishedSingleSaveClear($userId);
                $this->telegram->sendMessage($userId,'Пользователь заблокирован.');
                return;
            }
            PostedTelegramPublicButtonEdit::assertLegacySaveLocked($this->pdo,$postId);
            $lockedPost['chat_id']=(string)$lockedChannel['chat_id'];

            $permissionStmt=$this->pdo->prepare(
                'SELECT can_manage_posts
                 FROM posted_permissions
                 WHERE user_id=:user AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $permissionStmt->execute([
                'user'=>$userId,
                'channel'=>(int)$post['channel_id'],
            ]);
            if((int)($permissionStmt->fetchColumn()?:0)!==1){
                $this->pdo->rollBack();
                $this->telegramPublishedSingleSaveClear($userId);
                $this->telegram->sendMessage($userId,'Доступ к редактированию этого канала отозван.');
                return;
            }

            $sessionStmt=$this->pdo->prepare(
                'SELECT * FROM posted_edit_sessions
                 WHERE user_id=:user AND post_id=:post
                 LIMIT 1
                 FOR UPDATE'
            );
            $sessionStmt->execute(['user'=>$userId,'post'=>$postId]);
            $session=$sessionStmt->fetch();
            if(!is_array($session)){
                $this->pdo->rollBack();
                $this->telegramPublishedSingleSaveClear($userId);
                $this->telegram->sendMessage($userId,'Сессия редактирования устарела. Откройте пост заново.');
                return;
            }

            $document=$this->editSessionDocument($session,$lockedPost);
            if(array_key_exists('caption_placement',$document)){
                $this->pdo->rollBack();
                $this->telegram->sendMessage($userId,'Изменения сохранены в редакторе. Безопасное сохранение описания над медиа пока недоступно; исходная публикация не изменялась.');
                return;
            }
            // A new signed private panel may save only the staged revision it showed.
            // Legacy callers keep the existing backend path with a NULL expectation.
            if($this->telegramPublishedSingleSaveHash!==null&&(!hash_equals($this->telegramPublishedSingleSaveHash,PostedTelegramPublishedSingleUi::hash($document))||$this->telegramPublishedSingleSaveInput===null||!$this->telegramPublishedSingleInputMatches($userId,$this->telegramPublishedSingleSaveInput['state'],$this->telegramPublishedSingleSaveInput['data'])||(int)$lockedChannel['added_by']!==($this->telegramPublishedSingleSaveInput['owner_id']??null)||!hash_equals((string)($this->telegramPublishedSingleSaveInput['epoch']??''),PostedTelegramAuthorityPause::generation($this->pdo,['id'=>(int)$lockedPost['channel_id']]+$lockedChannel,true)?:'legacy')||PostedTelegramAuthorityPause::blocked($this->pdo,['id'=>(int)$lockedPost['channel_id']]+$lockedChannel,true))){
                $this->pdo->rollBack();$this->telegram->sendMessage($userId,'Сессия редактирования изменилась. Откройте пост заново.');return;
            }
            if(!$this->telegramPostInlineButtonsAllowed($lockedPost)&&$document['base']['buttons_json']!==$document['draft']['buttons_json']){
                $this->pdo->rollBack();
                $this->telegram->sendMessage($userId,'Кнопки недоступны для альбомов. Изменения сохранены в редакторе и не применялись.');
                return;
            }
            if($this->editablePostSnapshot($lockedPost)!==$document['base']){
                $this->pdo->prepare(
                    'DELETE FROM posted_edit_sessions WHERE user_id=:user AND post_id=:post'
                )->execute(['user'=>$userId,'post'=>$postId]);
                $this->pdo->commit();
                $this->telegramPublishedSingleSaveClear($userId);
                $this->telegram->sendMessage(
                    $userId,
                    'Пост уже изменил другой редактор. Ваши изменения не применены — откройте актуальный пост заново.'
                );
                return;
            }


            $editedPost=$this->postWithEditableSnapshot($lockedPost,$document['draft']);
            $remoteSnapshot=$document['base'];
            $chatId=(string)($editedPost['external_chat_id']?:$editedPost['chat_id']);
            $messageId=(int)($editedPost['external_message_id']?:$editedPost['telegram_message_id']);
            if($chatId===''||$messageId<=0){
                throw new RuntimeException('Не найден ID сообщения в канале.');
            }

            $editedPayload=$this->effectivePostPayload($editedPost);
            $buttons=$this->buttonsMarkup((array)($editedPayload['buttons']??[]));
            $extra=$this->telegramPostInlineButtonsAllowed($lockedPost)?['reply_markup'=>['inline_keyboard'=>$buttons]]:[];
            $text=(string)($editedPayload['text']??'');
            $textEntities=(array)($editedPayload['entities']??[]);
            $mediaType=(string)($editedPost['media_type']??'none');
            $contentChanged=false;
            foreach([
                'text','text_entities_json','media_type','media_file_id','media_json','buttons_json',
                'autosignature_text','autosignature_entities_json',
            ] as $field){
                if($document['base'][$field]!==$document['draft'][$field]){
                    $contentChanged=true;
                    break;
                }
            }

            if($contentChanged){
                $sourceSpoiler=PostedTelegramSourceSpoiler::freeze($this->pdo,$lockedPost);
                if($sourceSpoiler!==null&&$sourceSpoiler['mode']==='native'&&($sourceSpoiler['state']==='unknown'||$sourceSpoiler['media_spoiler']===true))throw new InvalidArgumentException('Состояние спойлера репоста нельзя безопасно менять обычным редактором. Создайте дубликат и явно выберите копию.');
                $deliveryOptions=PostedTelegramPostOptions::load($this->pdo,PostedTelegramPostOptions::freeze($this->pdo,$lockedPost));
                $paidContent=$deliveryOptions['paid_media_stars']!==null;
                $mediaChanged=false;
                foreach(['media_type','media_file_id','media_json'] as $field){
                    if($document['base'][$field]!==$document['draft'][$field]){
                        $mediaChanged=true;
                        break;
                    }
                }
                if($paidContent&&$mediaChanged)throw new InvalidArgumentException('Медиа опубликованного платного поста нельзя заменить обычным медиа. Создайте отдельный черновик.');
                if($mediaChanged){
                    if(!$this->isNativeRepost($lockedPost))PostedTelegramSourceSpoiler::detachLocked($this->pdo,$lockedPost);
                }
                if($deliveryOptions['media_spoiler']&&!in_array($mediaType,['photo','video','animation'],true))throw new InvalidArgumentException('Выбранный формат не поддерживает спойлер. Исходное медиа не изменено.');
                // editMessageCaption can succeed without replacing any file.
                // Never persist an unsupported voice replacement as if it did.
                if($mediaChanged&&(
                    (string)($lockedPost['original_media_type']??$document['base']['media_type'])==='voice'
                    ||$document['draft']['media_type']==='voice'
                )){
                    throw new RuntimeException('Telegram не разрешает заменять опубликованный файл голосовым сообщением или заменять само голосовое сообщение. Измените только подпись и кнопки либо создайте новый пост.');
                }
                if(
                    !$paidContent&&in_array($mediaType,['photo','video','animation','document','audio'],true)
                    &&trim((string)($editedPost['media_file_id']??''))!==''
                ){
                    if((string)($lockedPost['original_media_type']??$mediaType)==='none'){
                        throw new RuntimeException(
                            'Telegram не разрешает превратить уже опубликованный текстовый пост в пост с медиа.'
                        );
                    }
                    if((($paidContent||$this->isNativeRepost($lockedPost))?$this->telegramUtf16Length($text):PostedTelegramTransportLayout::characters($text))>self::PHOTO_CAPTION_LIMIT){
                        throw new RuntimeException(
                            'Подпись к медиа при редактировании через Bot API может быть не длиннее 1024 символов.'
                        );
                    }
                    $this->applyIdempotentTelegramEdit(
                        fn()=> $this->telegram->editMessageMedia($chatId,$messageId,array_merge([
                            'type'=>$mediaType,
                            'media'=>(string)$editedPost['media_file_id'],
                            'caption'=>$text,
                            'caption_entities'=>$textEntities,
                        ],$deliveryOptions['media_spoiler']?['has_spoiler'=>true]:[]),$extra)
                    );
                }elseif(in_array($mediaType,['photo','video','animation','document','audio','voice'],true)){
                    if((($paidContent||$this->isNativeRepost($lockedPost))?$this->telegramUtf16Length($text):PostedTelegramTransportLayout::characters($text))>self::PHOTO_CAPTION_LIMIT){
                        throw new RuntimeException(
                            'Подпись к медиа при редактировании через Bot API может быть не длиннее 1024 символов.'
                        );
                    }
                    $captionExtra=$extra;
                    if($textEntities!==[])$captionExtra['caption_entities']=$textEntities;
                    $this->applyIdempotentTelegramEdit(
                        fn()=> $this->telegram->editMessageCaption(
                            $chatId,
                            $messageId,
                            $text,
                            $captionExtra
                        )
                    );
                }else{
                    $textExtra=$extra;
                    if($textEntities!==[])$textExtra['entities']=$textEntities;
                    $this->applyIdempotentTelegramEdit(
                        fn()=> $this->telegram->editMessageText(
                            $chatId,
                            $messageId,
                            $text!==''?$text:' ',
                            $textExtra
                        )
                    );
                }
                foreach([
                    'text','text_entities_json','media_type','media_file_id','media_json',
                    'buttons_json','autosignature_text','autosignature_entities_json',
                ] as $field){
                    $remoteSnapshot[$field]=$document['draft'][$field];
                }
                $remoteMutationApplied=true;
            }

            if($document['base']['pin_after_publish']!==$document['draft']['pin_after_publish']){
                if(!empty($editedPost['pin_after_publish'])){
                    $this->applyIdempotentTelegramEdit(
                        fn()=> $this->telegram->pinChatMessage($chatId,$messageId,true),
                        ['message is not modified','already pinned']
                    );
                }else{
                    $this->applyIdempotentTelegramEdit(
                        fn()=> $this->telegram->unpinChatMessage($chatId,$messageId),
                        ['message is not modified','not pinned']
                    );
                }
                $remoteSnapshot['pin_after_publish']=$document['draft']['pin_after_publish'];
                $remoteMutationApplied=true;
            }

            $draft=$document['draft'];
            $this->persistEditedTelegramRepostMode($lockedPost,$draft);
            $this->pdo->prepare(
                'UPDATE posted_posts
                 SET text=:text,text_entities_json=:text_entities_json,
                     media_type=:media_type,media_file_id=:media_file_id,media_json=:media_json,
                     buttons_json=:buttons,pin_after_publish=:pin,
                     autosignature_text=:signature,
                     autosignature_entities_json=:signature_entities
                 WHERE id=:post AND status=\'sent\''
            )->execute([
                'text'=>$draft['text'],
                'text_entities_json'=>$draft['text_entities_json']!==[]
                    ?$this->encodeJson($draft['text_entities_json'])
                    :null,
                'media_type'=>$draft['media_type'],
                'media_file_id'=>$draft['media_file_id'],
                'media_json'=>$draft['media_json']!==[]?$this->encodeJson($draft['media_json']):null,
                'buttons'=>$draft['buttons_json']!==[]?$this->encodeJson($draft['buttons_json']):null,
                'pin'=>$draft['pin_after_publish'],
                'signature'=>$draft['autosignature_text'],
                'signature_entities'=>$draft['autosignature_entities_json']!==[]
                    ?$this->encodeJson($draft['autosignature_entities_json'])
                    :null,
                'post'=>$postId,
            ]);
            $this->pdo->prepare(
                'DELETE FROM posted_edit_sessions WHERE user_id=:user AND post_id=:post'
            )->execute(['user'=>$userId,'post'=>$postId]);
            $this->pdo->commit();
            $this->telegramPublishedSingleSaveClear($userId,true);
            $this->telegram->sendMessage($userId,'Изменения сохранены.');
        } catch (Throwable $exception) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            error_log('GitPosted post edit failed: '.$exception->getMessage());
            $recovered=$remoteMutationApplied&&is_array($remoteSnapshot)
                ?$this->persistTelegramEditRecovery($userId,$postId,$remoteSnapshot,$exception)
                :false;
            $this->telegram->sendMessage(
                $userId,
                $recovered
                    ?'Telegram применил изменения, а локальная фиксация прервалась. Данные поста синхронизированы; проверьте публикацию и закрепление вручную.'
                    :'Не удалось изменить пост: ' . $exception->getMessage()
            );
        } finally {
            if ($pinLockHeld) {
                $releasePin=$this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releasePin->execute(['lock_name'=>$pinLockName]);
            }
        }
    }

    private function applyIdempotentTelegramEdit(
        callable $operation,
        array $alreadyAppliedMessages=['message is not modified']
    ):void
    {
        try{
            $operation();
            return;
        }catch(PostedTelegramRequestException $exception){
            if($this->telegramMutationAlreadyApplied($exception,$alreadyAppliedMessages)){
                return;
            }
            if(!$exception->deliveryMayBeUnknown())throw $exception;
        }
        try{
            $operation();
        }catch(PostedTelegramRequestException $retryException){
            if($this->telegramMutationAlreadyApplied($retryException,$alreadyAppliedMessages)){
                return;
            }
            throw $retryException;
        }
    }

    private function telegramMutationAlreadyApplied(
        Throwable $exception,
        array $acceptedMessages
    ):bool {
        $message=mb_strtolower($exception->getMessage());
        foreach($acceptedMessages as $acceptedMessage){
            $acceptedMessage=mb_strtolower(trim((string)$acceptedMessage));
            if($acceptedMessage!==''&&str_contains($message,$acceptedMessage))return true;
        }
        return false;
    }

    private function persistTelegramEditRecovery(
        int $userId,
        int $postId,
        array $snapshot,
        Throwable $reason
    ): bool {
        $snapshot=$this->editablePostSnapshot($snapshot);
        try{
            $this->pdo->beginTransaction();
            $post=$this->pdo->prepare(
                "SELECT * FROM posted_posts WHERE id=:post LIMIT 1 FOR UPDATE"
            );
            $post->execute(['post'=>$postId]);
            $current = $post->fetch();
            if(!is_array($current)||(string)($current['status']??'')!=='sent'){
                $this->pdo->rollBack();
                return false;
            }
            $this->persistEditedTelegramRepostMode($current,$snapshot);
            if(!$this->isNativeRepost($current)&&PostedTelegramSourceSpoiler::mediaFingerprint($current)!==PostedTelegramSourceSpoiler::mediaFingerprint($snapshot))PostedTelegramSourceSpoiler::detachLocked($this->pdo,$current);
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET text=:text,text_entities_json=:text_entities_json,
                     media_type=:media_type,media_file_id=:media_file_id,media_json=:media_json,
                     buttons_json=:buttons,pin_after_publish=:pin,
                     autosignature_text=:signature,
                     autosignature_entities_json=:signature_entities,
                     error_text=:error
                 WHERE id=:post AND status='sent'"
            )->execute([
                'text'=>$snapshot['text'],
                'text_entities_json'=>$snapshot['text_entities_json']!==[]
                    ?$this->encodeJson($snapshot['text_entities_json'])
                    :null,
                'media_type'=>$snapshot['media_type'],
                'media_file_id'=>$snapshot['media_file_id'],
                'media_json'=>$snapshot['media_json']!==[]?$this->encodeJson($snapshot['media_json']):null,
                'buttons'=>$snapshot['buttons_json']!==[]?$this->encodeJson($snapshot['buttons_json']):null,
                'pin'=>$snapshot['pin_after_publish'],
                'signature'=>$snapshot['autosignature_text'],
                'signature_entities'=>$snapshot['autosignature_entities_json']!==[]
                    ?$this->encodeJson($snapshot['autosignature_entities_json'])
                    :null,
                'error'=>'Telegram применил изменение, но обычное завершение операции прервалось: '
                    .mb_substr($reason->getMessage(),0,3500),
                'post'=>$postId,
            ]);
            $this->pdo->prepare(
                'DELETE FROM posted_edit_sessions WHERE user_id=:user AND post_id=:post'
            )->execute(['user'=>$userId,'post'=>$postId]);
            $this->pdo->commit();
            $this->telegramPublishedSingleSaveClear($userId,true);
            return true;
        }catch(Throwable $recoveryError){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            error_log(
                'GitPosted could not persist Telegram edit recovery for post '
                .$postId.': '.$recoveryError->getMessage()
            );
            return false;
        }
    }

    private function persistEditedTelegramRepostMode(array $post, array $snapshot): void
    {
        if (!$this->isNativeRepost($post)) return;
        $before = $this->editablePostSnapshot($post);
        $after = $this->editablePostSnapshot($snapshot);
        $changed = false;
        foreach (['text', 'text_entities_json', 'media_type', 'media_file_id', 'media_json',
            'buttons_json', 'autosignature_text', 'autosignature_entities_json'] as $field) {
            if ($before[$field] !== $after[$field]) { $changed = true; break; }
        }
        if (!$changed) return;
        $import = $this->decodeJson((string) ($post['import_json'] ?? ''));
        $importJson = $post['import_json'] ?? null;
        if (($import['version'] ?? null) === 1) {
            $import['mode'] = 'copy';
            $importJson = $this->encodeJson($import);
        }
        // Telegram has applied the edited payload. Future duplicates/repeats
        // must reuse that payload instead of forwarding the unedited source.
        // Keep the immutable import source for an explicit later mode change.
        PostedTelegramSourceSpoiler::transitionToCopyLocked($this->pdo,$post);
        $this->pdo->prepare("UPDATE posted_posts SET repost_chat_id=NULL,repost_message_id=NULL,
            repost_input_chat_id=NULL,repost_input_message_id=NULL,import_json=:import
            WHERE id=:post AND status='sent'")
            ->execute(['import' => $importJson, 'post' => (int) $post['id']]);
        if(PostedTelegramSourceSpoiler::mediaFingerprint($post)!==PostedTelegramSourceSpoiler::mediaFingerprint($snapshot))PostedTelegramSourceSpoiler::detachLocked($this->pdo,array_replace($post,['repost_chat_id'=>null,'repost_message_id'=>null]));
    }

    private function claimPostForPublish(int $postId,array $expectedStatuses,bool $due=false): ?array
    {
        $expectedStatuses=array_values(array_intersect($expectedStatuses,['draft','scheduled','failed']));if($expectedStatuses===[])return null;
        $before=$this->post($postId);if(!$before||!in_array($before['status'],$expectedStatuses,true))return null;
        $own=!$this->pdo->inTransaction();
        // Existing managed callers already performed network preflight before their transaction.
        if($own&&!$this->ensureTelegramChannelTenure((int)$before['channel_id']))return null;
        if($own)$this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([(int)$before['channel_id']]);$channel=$s->fetch();
            $s=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$postId]);$current=$s->fetch();
            if(!$channel||!$current||(int)$current['channel_id']!==(int)$channel['id']||empty($channel['is_active'])||(int)$channel['added_by']<=0||!in_array($current['status'],$expectedStatuses,true)){
                if($own)$this->pdo->commit();return null;}
            PostedTelegramAuthorityPause::assertDeliveryLocked($this->pdo,$channel);
            $prior=$current['status'];$marks=implode(',',array_fill(0,count($expectedStatuses),'?'));
            $sql="UPDATE posted_posts SET status='publishing',publish_started_at=NOW(),error_text=NULL WHERE id=? AND status IN ({$marks})";
            if($due)$sql.=' AND scheduled_at IS NOT NULL AND scheduled_at<=NOW()';
            $s=$this->pdo->prepare($sql);$s->execute([$postId,...$expectedStatuses]);if($s->rowCount()!==1){if($own)$this->pdo->commit();return null;}
            $this->pdo->prepare('UPDATE posted_posts SET delete_after_minutes=? WHERE id=? AND delete_after_minutes=2880')->execute([self::TELEGRAM_MAX_AUTO_DELETE_MINUTES,$postId]);
            $claimed=$this->post($postId);if(!$claimed)throw new RuntimeException('Claimed post disappeared.');
            $claim=PostedTelegramAuthorityPause::createClaimLocked($this->pdo,$claimed,$channel,$prior);
            $claimed['_authority_claim_nonce']=$claim['nonce'];$claimed['_authority_generation']=$claim['generation'];$claimed['_claimed_from_status']=$prior;
            if($own)$this->pdo->commit();return$claimed;
        }catch(PostedTelegramAuthorityPauseException $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();if($own)return null;throw$e;
        }catch(Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }


    private function telegramSubmissionKey(array $post): string
    {
        $post=PostedTelegramNativeStore::freeze($this->pdo,$post);
        $post=PostedTelegramCaptionVector::freeze($this->pdo,$post);
        $post=PostedTelegramTransportLayout::freeze($this->pdo,$post);
        $post=PostedTelegramCaptionPlacement::freeze($this->pdo,$post);
        $post=PostedTelegramPublicButtonLayout::freeze($this->pdo,$post,$this->effectivePostPayload($post));
        $payload=$this->effectivePostPayload($post);
        $media=array_map(
            static fn(array $item):array=>[
                'type'=>(string)($item['type']??''),
                'file_id'=>(string)($item['file_id']??''),
            ],
            $this->postMedia($post)
        );
        $document=[
            'version'=>1,
            'channel_id'=>(int)($post['channel_id']??0),
            'chat_id'=>(string)($post['chat_id']??''),
            'text'=>(string)($payload['text']??''),
            'entities'=>(array)($payload['entities']??[]),
            'media'=>$media,
            'buttons'=>$payload['buttons']??[],
            'repost_chat_id'=>(string)($post['repost_chat_id']??''),
            'repost_message_id'=>(int)($post['repost_message_id']??0),
            'repost_input_chat_id'=>(string)($post['repost_input_chat_id']??''),
            'repost_input_message_id'=>(int)($post['repost_input_message_id']??0),
            'reply_chat_id'=>(string)($post['reply_chat_id']??''),
            'reply_message_id'=>(int)($post['reply_message_id']??0),
        ];
        $options=$this->telegramPostOptions($post);
        // Keep the established legacy payload identity and cross-post duplicate
        // gate when no delivery behavior is selected. Bound row IDs are metadata.
        $behavior=PostedTelegramPostOptions::behavior($options);
        if($behavior!==[])$document['delivery_options']=$behavior;
        $captions=PostedTelegramCaptionVector::behavior($post);if($captions!==[])$document['caption_vector']=$captions;
        $layout=$this->telegramTransportLayoutBehavior($post);if($layout!==[])$document['transport_layout']=$layout;
        $placement=PostedTelegramCaptionPlacement::behavior($post);if($placement!==[])$document['caption_placement']=$placement;
        $public=PostedTelegramPublicButtonLayout::behavior($post);if($public!==[])$document['public_button_layout']=$public;
        $multipost=$this->telegramMultipostSubmissionSnapshot($post);
        if($multipost!==null)$document['multipost']=$multipost;
        $authority=PostedTelegramAuthorityPause::submissionContext($this->pdo,$post);
        if($authority!==null)$document['authority']=$authority;
        $top=PostedTelegramTopHold::submission($this->pdo,$post);if($top!==null)$document['top']=$top;
        $album=PostedTelegramAlbumCollector::submission($this->pdo,$post);if($album!==null)$document['album']=$album;
        $native=PostedTelegramNativeStore::behavior($post);if($native!==[])$document['native_content']=$native;
        return hash('sha256',$this->encodeJson($document));
    }

    private function reserveTelegramPublishAttempt(array $post, ?int $actorId = null): bool
    {
        $post=PostedTelegramCaptionPlacement::freeze($this->pdo,$post);
        $multipostHint=$this->telegramMultipostReserveHint($post);
        PostedTelegramRootedAccess::begin($this->pdo,$this->config);
        try{
            $lockedChannels=$this->telegramMultipostLockChannels($multipostHint);
            $lockedChannel=$lockedChannels[(int)$post['channel_id']]??null;
            if(
                !is_array($lockedChannel)
                ||empty($lockedChannel['is_active'])
                ||(int)($lockedChannel['added_by']??0)<=0
                ||empty($lockedChannel['bot_can_post'])
            ){
                PostedTelegramRootedAccess::rollback($this->pdo);
                return false;
            }
            $lockedPost=$this->pdo->prepare(
                "SELECT status,channel_id,author_id,pin_after_publish,manager_category_id,
                        id,manager_post_quota_charged,manager_pin_quota_charged,repeat_interval_minutes,publish_started_at
                 FROM posted_posts
                 WHERE id=:post
                 LIMIT 1
                 FOR UPDATE"
            );
            $lockedPost->execute(['post'=>(int)$post['id']]);
            $currentPost=$lockedPost->fetch();
            if(
                !is_array($currentPost)
                ||(string)($currentPost['status']??'')!=='publishing'
                ||(int)($currentPost['channel_id']??0)!==(int)$post['channel_id']
            ){
                PostedTelegramRootedAccess::rollback($this->pdo);
                return false;
            }
            foreach($lockedChannels as$authorityChannel)PostedTelegramAuthorityPause::assertDeliveryLocked($this->pdo,$authorityChannel);
            PostedTelegramAuthorityPause::ownedClaimLocked($this->pdo,$post,$currentPost,$lockedChannel,true);
            PostedTelegramAlbumCollector::assertReadyLocked($this->pdo,$currentPost);
            PostedTelegramTopHold::assertAvailableLocked($this->pdo,$lockedChannel,(int)$post['id']);
            $ownerId=(int)($lockedChannel['added_by']??0);
            $authorId=(int)($currentPost['author_id']??0);
            $requiredActors=[$ownerId,$authorId];
            foreach($lockedChannels as$involvedChannel)$requiredActors[]=(int)($involvedChannel['added_by']??0);
            if($actorId!==null)$requiredActors[]=$actorId;
            if(
                $ownerId<=0
                ||$authorId<=0
                ||!$this->lockActiveTelegramActors($requiredActors)
            ){
                if(count($multipostHint['channel_ids'])>1)throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: автор или владелец одного из каналов отключён. Пост и дата сохранены.');
                PostedTelegramRootedAccess::rollback($this->pdo);
                return false;
            }
            $this->telegramPolicyReserveLocked($post,$lockedChannel,$authorId,$actorId);
            if((int)($post['repeat_interval_minutes']??0)>0||(int)($currentPost['repeat_interval_minutes']??0)>0)
                PostedTelegramPolicy::requireChannel($this->pdo,$this->config,$lockedChannel,'premium');
            $this->telegramMultipostValidateTargetsLocked($post,$lockedChannels,$multipostHint,$authorId);
            if ($actorId !== null) {
                $permission = $this->pdo->prepare(
                    'SELECT can_post,can_manage_posts
                     FROM posted_permissions
                     WHERE user_id=:user AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $permission->execute([
                    'user' => $actorId,
                    'channel' => (int) $post['channel_id'],
                ]);
                $currentPermission = $permission->fetch();
                if (
                    !is_array($currentPermission)
                    || (int) ($currentPermission['can_post'] ?? 0) !== 1
                    || (
                        (int) ($currentPost['author_id'] ?? 0) !== $actorId
                        && (int) ($currentPermission['can_manage_posts'] ?? 0) !== 1
                    )
                ) {
                    PostedTelegramRootedAccess::rollback($this->pdo);
                    return false;
                }
            }
            $authorPermission = $this->pdo->prepare(
                'SELECT can_post,can_schedule,is_ad_manager,post_quota_remaining,pin_quota_remaining
                 FROM posted_permissions
                 WHERE user_id=:user AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $authorPermission->execute([
                'user'=>$authorId,
                'channel'=>(int) $post['channel_id'],
            ]);
            $managerPermission = $authorPermission->fetch();
            $requiredAbility = (string)($post['_claimed_from_status']??'draft') === 'scheduled'
                ? 'can_schedule'
                : 'can_post';
            if (
                !is_array($managerPermission)
                || (int)($managerPermission[$requiredAbility]??0) !== 1
            ) {
                throw new PostedPublishAuthorizationException(
                    'Публикация отменена: у автора больше нет необходимых прав на канал.'
                );
            }
            if ($authorId !== $ownerId) {
                $categoryId=(int)($currentPost['manager_category_id']??0);
                if($categoryId===0&&!empty($managerPermission['is_ad_manager'])){
                    $activeCategory=$this->pdo->prepare(
                        'SELECT id
                         FROM posted_manager_categories
                         WHERE user_id=:user AND channel_id=:channel AND is_active=1
                         ORDER BY id
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $activeCategory->execute([
                        'user'=>$authorId,
                        'channel'=>(int)$post['channel_id'],
                    ]);
                    if($activeCategory->fetchColumn()!==false){
                        throw new PostedPublishAuthorizationException(
                            'Публикация отменена: выберите рекламную компанию и создайте пост заново.'
                        );
                    }
                }
                if($categoryId>0){
                    if (empty($managerPermission['is_ad_manager'])) {
                        throw new PostedPublishAuthorizationException(
                            'Публикация отменена: автор больше не является рекламным менеджером.'
                        );
                    }
                    $categoryStmt=$this->pdo->prepare('SELECT post_quota_remaining,pin_quota_remaining FROM posted_manager_categories WHERE id=:id AND user_id=:user AND channel_id=:channel AND is_active=1 LIMIT 1 FOR UPDATE');
                    $categoryStmt->execute(['id'=>$categoryId,'user'=>$authorId,'channel'=>(int)$post['channel_id']]);
                    $category=$categoryStmt->fetch();
                    if(!is_array($category)){
                        throw new PostedPublishAuthorizationException(
                            'Публикация отменена: рекламная категория удалена или отключена.'
                        );
                    }
                    $managerPermission=$category;
                }
                if ($categoryId>0 || (is_array($managerPermission) && !empty($managerPermission['is_ad_manager']))) {
                    $chargePost = empty($currentPost['manager_post_quota_charged']);
                    $chargePin = !empty($currentPost['pin_after_publish'])
                        && empty($currentPost['manager_pin_quota_charged']);
                    if ($chargePost && $managerPermission['post_quota_remaining'] !== null
                        && (int) $managerPermission['post_quota_remaining'] <= 0) {
                        throw new RuntimeException($this->managerLimitExhaustedMessage(
                            'публикаций',
                            $ownerId,
                            (string) ($lockedChannel['title'] ?? '')
                        ));
                    }
                    if ($chargePin && $managerPermission['pin_quota_remaining'] !== null
                        && (int) $managerPermission['pin_quota_remaining'] <= 0) {
                        throw new RuntimeException($this->managerLimitExhaustedMessage(
                            'закреплений',
                            $ownerId,
                            (string) ($lockedChannel['title'] ?? '')
                        ));
                    }
                    if ($chargePost || $chargePin) {
                        $quotaTable=$categoryId>0?'posted_manager_categories':'posted_permissions';
                        $quotaWhere=$categoryId>0?'id=:category AND user_id=:user AND channel_id=:channel':'user_id=:user AND channel_id=:channel AND is_ad_manager=1';
                        $quota=$this->pdo->prepare(
                            'UPDATE '.$quotaTable.'
                             SET post_quota_remaining=IF(:charge_post=1 AND post_quota_remaining IS NOT NULL,post_quota_remaining-1,post_quota_remaining),
                                 pin_quota_remaining=IF(:charge_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining-1,pin_quota_remaining)
                             WHERE '.$quotaWhere
                        );$quotaParams=[
                            'charge_post'=>$chargePost ? 1 : 0,
                            'charge_pin'=>$chargePin ? 1 : 0,
                            'user'=>$authorId,
                            'channel'=>(int) $post['channel_id'],
                        ];if($categoryId>0)$quotaParams['category']=$categoryId;$quota->execute($quotaParams);
                        $this->pdo->prepare(
                            'UPDATE posted_posts
                             SET manager_post_quota_charged=IF(:charge_post=1,1,manager_post_quota_charged),
                                 manager_pin_quota_charged=IF(:charge_pin=1,1,manager_pin_quota_charged)
                             WHERE id=:post'
                        )->execute([
                            'charge_post'=>$chargePost ? 1 : 0,
                            'charge_pin'=>$chargePin ? 1 : 0,
                            'post'=>(int) $post['id'],
                        ]);
                    }
                }
            }
            $post=PostedTelegramNativeStore::freeze($this->pdo,$post);
            if(PostedTelegramNativeContent::isKind($post['media_type']??null))$this->telegramNativeAssertOrdinaryPayload($post,$this->effectivePostPayload($post));
            $post=PostedTelegramCaptionVector::freeze($this->pdo,$post);
            $post=PostedTelegramTransportLayout::freeze($this->pdo,$post);
        $post=PostedTelegramPublicButtonLayout::freeze($this->pdo,$post,$this->effectivePostPayload($post));
            PostedTelegramTopHold::reserveLocked($this->pdo,$this->config,$post,$lockedChannel);
            $pendingParts=$this->expectedTelegramPendingParts($post);
            $messageSlots=array_fill(0,count($pendingParts),0);
            $continuation=$this->telegramContinuationAfterPendingPrimary($post);
            $this->telegramMultipostReserveLocked($post,$lockedChannel,$pendingParts,$continuation);
            $stmt=$this->pdo->prepare(
                "INSERT INTO posted_telegram_publish_attempts
                    (post_id,channel_id,chat_id,gate_channel_id,submission_key,state,
                     message_slots_json,pending_parts_json,continuation_json)
                 VALUES
                    (:post_id,:channel_id,:chat_id,:gate_channel_id,:submission_key,'sending',
                     :message_slots,:pending_parts,:continuation)"
            );
            $stmt->execute([
                'post_id'=>(int)$post['id'],
                'channel_id'=>(int)$post['channel_id'],
                'chat_id'=>(string)$post['chat_id'],
                'gate_channel_id'=>(int)$post['channel_id'],
                'submission_key'=>$this->telegramSubmissionKey($post),
                'message_slots'=>$this->encodeMessageSlots($messageSlots),
                'pending_parts'=>$this->encodeJson($pendingParts),
                'continuation'=>$this->encodeJson($continuation),
            ]);
            PostedTelegramPostOptions::saveAttemptSnapshotLocked($this->pdo,$post,$this->telegramSubmissionKey($post));
            PostedTelegramNativeStore::snapshotLocked($this->pdo,$post,$this->telegramSubmissionKey($post));
            PostedTelegramTransportLayout::snapshotLocked($this->pdo,$post,$this->telegramSubmissionKey($post));
            PostedTelegramCaptionPlacement::snapshotLocked($this->pdo,$post,$this->telegramSubmissionKey($post));
            if(PostedTelegramPublicButtonLayout::mode($post)===1&&$stmt->rowCount()!==1)throw new RuntimeException('public_button_fresh_insert_not_confirmed');
            PostedTelegramPublicButtonLayout::snapshotLocked($this->pdo,$post,$this->telegramSubmissionKey($post),true);
            PostedTelegramRootedAccess::commit($this->pdo);
            return true;
        }catch(PDOException $exception){
            if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);
            if((string)$exception->getCode()!=='23000')throw $exception;
            return false;
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);
            throw $exception;
        }
    }

    private function hasRecordedTelegramPublishAttempt(int $postId): bool
    {
        $query=$this->pdo->prepare('SELECT post_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');
        $query->execute([$postId]);return $query->fetchColumn()!==false;
    }

    private function restoreBlockedPublishClaim(array $post,?string $error=null): bool
    {return$this->telegramAuthorityRestoreClaim($post,$error);}


    private function cancelUnauthorizedTelegramPublishClaim(array $post,string $error): bool
    {
        // A revoked editor does not freeze other authors or erase this one's dates.
        return$this->telegramAuthorityRestoreClaim($post,$error);
    }


    private function discardTelegramPublishAttempt(int $postId): void
    {
        $this->pdo->prepare('DELETE FROM posted_telegram_publish_attempts WHERE post_id=:post')
            ->execute(['post'=>$postId]);
    }

    private function recordPublishingSlots(int $postId,array $slots):void
    {
        $positive=array_values(array_filter(array_map('intval',$slots),static fn(int $id):bool=>$id>0));
        $stmt=$this->pdo->prepare(
            "UPDATE posted_posts
             SET telegram_message_id=COALESCE(telegram_message_id,:first),
                 telegram_message_ids=:slots
             WHERE id=:id AND status IN ('publishing','telegram_pending')"
        );
        $stmt->execute([
            'first'=>$positive[0]??null,
            'slots'=>$this->encodeMessageSlots($slots),
            'id'=>$postId,
        ]);
        if($stmt->rowCount()!==1){
            throw new RuntimeException('Не удалось зафиксировать результат отправки Telegram.');
        }
        $this->pdo->prepare(
            'UPDATE posted_telegram_publish_attempts
             SET message_slots_json=:slots
             WHERE post_id=:post'
        )->execute(['slots'=>$this->encodeMessageSlots($slots),'post'=>$postId]);
    }

    private function recoverStalePublishingPosts(): void
    {
        $typed=$this->pdo->query("SELECT p.* FROM posted_posts p JOIN posted_app_settings s ON s.setting_key=CONCAT('telegram_publish_claim_',p.id) WHERE p.status='publishing' AND p.publish_started_at<NOW()-INTERVAL 15 MINUTE AND NOT EXISTS(SELECT 1 FROM posted_telegram_publish_attempts a WHERE a.post_id=p.id) ORDER BY p.id LIMIT 20");
        foreach($typed->fetchAll()as$stale){try{$request=PostedTelegramAuthorityPause::recoveryRequest($this->pdo,$stale);if($request!==null)$this->restoreBlockedPublishClaim($request,'Операция остановлена до отправки. Пост и дата сохранены.');}catch(PostedTelegramAuthorityPauseException){/* malformed claim stays held for review */}}
        $this->pdo->exec(
            "DELETE attempt
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             WHERE post.status IN ('failed','cancelled','deleted')
               AND attempt.state<>'completed'"
        );
        $this->pdo->exec(
            "UPDATE posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             SET attempt.state='completed',attempt.gate_channel_id=NULL,
                 attempt.submission_key=NULL,
                 attempt.completed_at=COALESCE(attempt.completed_at,NOW()),
                 attempt.finalized_at=COALESCE(attempt.finalized_at,NOW())
             WHERE post.status='sent'
               AND attempt.state<>'completed'"
        );
        $this->pdo->exec(
            "UPDATE posted_posts post
             INNER JOIN posted_telegram_publish_attempts attempt ON attempt.post_id=post.id
             SET post.status='telegram_pending',
                 post.error_text='Ответ Telegram не был окончательно зафиксирован. Автоматический повтор отключён во избежание дубля.',
                 attempt.state=IF(attempt.state='sending','needs_review',attempt.state),
                 attempt.deadline_at=COALESCE(attempt.deadline_at,NOW()+INTERVAL 24 HOUR)
             WHERE post.status='publishing'
               AND post.publish_started_at<NOW()-INTERVAL 15 MINUTE
               AND attempt.state IN ('sending','awaiting_telegram','needs_review','reconciled','cancel_requested')"
        );
        $this->pdo->exec(
            "UPDATE posted_posts post
             INNER JOIN posted_telegram_publish_attempts attempt ON attempt.post_id=post.id
                 SET post.error_text=CASE
                     WHEN attempt.state='cancel_finalizing'
                     THEN 'Удаление прервалось и будет безопасно продолжено без повторной публикации.'
                     ELSE 'Завершение публикации прервалось и будет безопасно продолжено без повторной отправки.'
                 END,
                 attempt.state=CASE
                     WHEN attempt.state='cancel_finalizing' THEN 'cancel_requested'
                     ELSE 'reconciled'
                 END
             WHERE post.status='telegram_pending'
               AND attempt.state IN ('finalizing','cancel_finalizing')
               AND attempt.updated_at<NOW()-INTERVAL 15 MINUTE"
        );
        $stmt=$this->pdo->query(
            "SELECT attempt.post_id,attempt.pending_parts_json
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             WHERE post.status='telegram_pending'
               AND attempt.state='continuation_sending'
               AND attempt.updated_at<NOW()-INTERVAL 15 MINUTE
             LIMIT 20"
        );
        foreach($stmt->fetchAll() as $attempt){
            $parts=$this->decodeJson((string)($attempt['pending_parts_json']??''));
            $complete=$parts!==[];
            foreach($parts as $part){
                if(!is_array($part)||(int)($part['message_id']??0)<=0){
                    $complete=false;
                    break;
                }
            }
            $this->pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET state=:state,
                     completed_at=IF(:complete=1,COALESCE(completed_at,NOW()),completed_at)
                 WHERE post_id=:post AND state='continuation_sending'"
            )->execute([
                'state'=>$complete?'reconciled':'finalization_uncertain',
                'complete'=>$complete?1:0,
                'post'=>(int)$attempt['post_id'],
            ]);
        }
        $this->pdo->exec(
            "UPDATE posted_posts
             SET status='sent',
                 published_at=COALESCE(published_at,publish_started_at,NOW()),
                 delete_at=CASE
                     WHEN delete_after_minutes IS NOT NULL AND delete_after_minutes>0
                     THEN DATE_ADD(
                         COALESCE(published_at,publish_started_at,NOW()),
                         INTERVAL IF(delete_after_minutes=2880,2875,delete_after_minutes) MINUTE
                     )
                     ELSE NULL
                 END,
                 publish_started_at=NULL,
                 error_text='Публикация была отправлена, но завершение операции прервалось; проверьте пост вручную.'
             WHERE status='publishing'
               AND publish_started_at<NOW()-INTERVAL 15 MINUTE
               AND telegram_message_id IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM posted_telegram_publish_attempts attempt
                    WHERE attempt.post_id=posted_posts.id
               )"
        );
        $this->pdo->exec(
            "UPDATE posted_posts
             SET status='failed',publish_started_at=NULL,
                 error_text='Операция публикации прервалась до получения message_id; повторите отправку вручную.'
             WHERE status='publishing'
               AND publish_started_at<NOW()-INTERVAL 15 MINUTE
               AND telegram_message_id IS NULL
               AND NOT EXISTS(SELECT 1 FROM posted_app_settings s WHERE s.setting_key=CONCAT('telegram_publish_claim_',posted_posts.id))
               AND NOT EXISTS (
                    SELECT 1 FROM posted_telegram_publish_attempts attempt
                    WHERE attempt.post_id=posted_posts.id
               )"
        );
    }

    private function resumeReconciledTelegramAttempts():void
    {
        $rotate=$this->pdo->prepare(
            "UPDATE posted_telegram_publish_attempts
             SET updated_at=NOW()
             WHERE post_id=:post AND state=:state"
        );
        $stmt=$this->pdo->query(
            "SELECT attempt.post_id,attempt.state,attempt.pending_parts_json
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             WHERE attempt.state IN ('sending','awaiting_telegram','needs_review','finalization_uncertain','reconciled','cancel_requested')
               AND post.status='telegram_pending'
             ORDER BY attempt.updated_at,attempt.post_id
             LIMIT 20"
        );
        foreach($stmt->fetchAll() as $attempt){
            $state=(string)($attempt['state']??'');
            $parts=$this->decodeJson((string)($attempt['pending_parts_json']??''));
            $complete=$parts!==[];
            foreach($parts as $part){
                if(!is_array($part)||(int)($part['message_id']??0)<=0){
                    $complete=false;
                    break;
                }
            }
            if(!$complete){
                $rotate->execute([
                    'post'=>(int)$attempt['post_id'],
                    'state'=>$state,
                ]);
                continue;
            }
            if($state!=='reconciled'&&$state!=='cancel_requested'){
                $changed=$this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET state='reconciled',completed_at=COALESCE(completed_at,NOW())
                     WHERE post_id=:post
                       AND state IN ('sending','awaiting_telegram','needs_review','finalization_uncertain')"
                );
                $changed->execute(['post'=>(int)$attempt['post_id']]);
                if($changed->rowCount()!==1)continue;
            }
            $this->finalizeTelegramPublishAttempt((int)$attempt['post_id']);
        }
    }

    private function resumeForceCancelledTelegramAttempts():void
    {
        $stmt=$this->pdo->query(
            "SELECT attempt.post_id
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             WHERE attempt.state='force_cancel_finalizing'
               AND post.status='telegram_pending'
             ORDER BY attempt.updated_at,attempt.post_id
             LIMIT 20"
        );
        foreach($stmt->fetchAll() as $attempt){
            $postId=(int)($attempt['post_id']??0);
            if($postId<=0)continue;
            try{
                $this->completeForceCancelledTelegramAttempt($postId);
            }catch(Throwable $exception){
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET error_text=:error
                     WHERE id=:post AND status='telegram_pending'"
                )->execute([
                    'error'=>'Явная отмена будет повторена автоматически: '.mb_substr($exception->getMessage(),0,3400),
                    'post'=>$postId,
                ]);
                $this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET updated_at=NOW()
                     WHERE post_id=:post
                       AND state='force_cancel_finalizing'"
                )->execute(['post'=>$postId]);
                error_log(
                    'GitPosted force cancellation retry failed for post '
                    .$postId.': '.$exception->getMessage()
                );
            }
        }
    }

    private function resumeCompletedTelegramEffects():void
    {
        $stmt=$this->pdo->query(
            "SELECT post.*,channel.chat_id
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             INNER JOIN posted_channels channel ON channel.id=post.channel_id
             WHERE attempt.state='completed'
               AND attempt.pin_completed_at IS NULL
               AND post.status='sent'
             ORDER BY attempt.updated_at,attempt.post_id
             LIMIT 20"
        );
        foreach($stmt->fetchAll() as $post){
            $this->completeTelegramPinEffect(
                (int)$post['id'],
                $post,
                $this->postMessageIds($post)
            );
        }
    }

    private function notifyExpiredTelegramAttempts():void
    {
        $stmt=$this->pdo->query(
            "SELECT attempt.post_id,post.author_id
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             WHERE post.status='telegram_pending'
               AND attempt.state IN (
                    'awaiting_telegram','needs_review','finalization_uncertain',
                    'cancel_requested'
               )
               AND attempt.deadline_at IS NOT NULL
               AND attempt.deadline_at<=NOW()
               AND attempt.review_notification_sent_at IS NULL
             ORDER BY COALESCE(attempt.review_notified_at,attempt.deadline_at),
                      attempt.post_id
             LIMIT 20"
        );
        foreach($stmt->fetchAll() as $candidate){
            $postId=(int)($candidate['post_id']??0);
            $userId=(int)($candidate['author_id']??0);
            if($postId<=0)continue;
            $mark=$this->pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET review_notified_at=NOW()
                 WHERE post_id=:post
                   AND review_notification_sent_at IS NULL
                   AND deadline_at IS NOT NULL
                   AND deadline_at<=NOW()
                   AND state IN (
                        'awaiting_telegram','needs_review','finalization_uncertain',
                        'cancel_requested'
                   )"
            );
            $mark->execute(['post'=>$postId]);
            if($mark->rowCount()!==1)continue;
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET error_text='Telegram не прислал окончательное подтверждение. Автоматический повтор заблокирован; выберите продолжение ожидания либо явную отмену.'
                 WHERE id=:post AND status='telegram_pending'"
            )->execute(['post'=>$postId]);
            if($userId<=0)continue;
            try{
                $this->telegram->sendMessage(
                    $userId,
                    "Telegram больше суток не подтверждает публикацию #{$postId}.\n\n"
                    ."GitPosted не повторяет отправку, поэтому дубля не будет. "
                    ."Если публикация ещё обрабатывается, продолжите ожидание. "
                    ."Если её точно нет в канале, можно явно отменить ожидание и освободить канал. "
                    ."При очень позднем появлении такого поста бот уже не сможет удалить его автоматически.",
                    ['reply_markup'=>['inline_keyboard'=>[
                        [[
                            'text'=>'Продолжить ожидание',
                            'callback_data'=>'cp:pendingwait:'.$postId,
                        ]],
                        [[
                            'text'=>'Отменить ожидание и освободить канал',
                            'callback_data'=>'cp:forcecancel:'.$postId,
                        ]],
                    ]]]
                );
                $this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET review_notification_sent_at=NOW()
                     WHERE post_id=:post
                       AND review_notified_at IS NOT NULL
                       AND review_notification_sent_at IS NULL
                       AND deadline_at IS NOT NULL
                       AND deadline_at<=NOW()
                       AND state IN (
                            'awaiting_telegram','needs_review','finalization_uncertain',
                            'cancel_requested'
                       )"
                )->execute(['post'=>$postId]);
            }catch(Throwable $notifyError){
                error_log(
                    'GitPosted pending Telegram review notification failed for post '
                    .$postId.': '.$notifyError->getMessage()
                );
            }
        }
    }

    private function resolveInitialTelegramFailure(int $postId,Throwable $exception):string
    {
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare(
                "SELECT post.status,post.channel_id,post.author_id,
                        post.manager_post_quota_charged,post.manager_pin_quota_charged,
                        attempt.state AS attempt_state
                 FROM posted_posts post
                 LEFT JOIN posted_telegram_publish_attempts attempt
                    ON attempt.post_id=post.id
                 WHERE post.id=:post
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['post'=>$postId]);
            $row=$stmt->fetch();
            if(!is_array($row)){
                $this->pdo->commit();
                return 'missing';
            }
            $status=(string)($row['status']??'');
            $attemptState=(string)($row['attempt_state']??'');
            if(
                $attemptState==='cancel_requested'
                &&in_array($status,['publishing','telegram_pending'],true)
            ){
                $updated=$this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status='deleted',publish_started_at=NULL,delete_at=NULL,
                         manual_delete_required=0,error_text=NULL
                     WHERE id=:post
                       AND status IN ('publishing','telegram_pending')"
                );
                $updated->execute(['post'=>$postId]);
                if($updated->rowCount()!==1){
                    throw new RuntimeException('Отменяемая публикация уже изменила состояние.');
                }
                $completed=$this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET state='completed',gate_channel_id=NULL,submission_key=NULL,
                         finalized_at=NOW(),completed_at=COALESCE(completed_at,NOW()),
                         pin_started_at=NULL,pin_completed_at=NOW()
                     WHERE post_id=:post AND state='cancel_requested'"
                );
                $completed->execute(['post'=>$postId]);
                if($completed->rowCount()!==1){
                    throw new RuntimeException('Не удалось завершить отмену публикации.');
                }
                PostedTelegramTopHold::knownRejectLocked($this->pdo,$postId);
                $this->refundManagerQuotaForPost($postId);
                $this->pdo->commit();
                return 'cancelled';
            }
            if($attemptState==='sending'&&$status==='publishing'){
                PostedTelegramNativeStore::retireKnownRejectedAttemptLocked($this->pdo,$postId);
                PostedTelegramCaptionVector::retireKnownRejectedAttemptLocked($this->pdo,$postId);
                PostedTelegramCaptionPlacement::retireKnownRejectedAttemptLocked($this->pdo,$postId);
            PostedTelegramTransportLayout::retireKnownRejectedAttemptLocked($this->pdo,$postId);
            PostedTelegramPublicButtonLayout::retireKnownRejectedAttemptLocked($this->pdo,$postId);
                $updated=$this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status='failed',publish_started_at=NULL,error_text=:error
                     WHERE id=:post AND status='publishing'"
                );
                $updated->execute([
                    'error'=>mb_substr($exception->getMessage(),0,4000),
                    'post'=>$postId,
                ]);
                if($updated->rowCount()!==1){
                    throw new RuntimeException('Публикация уже изменила состояние.');
                }
                $discard=$this->pdo->prepare(
                    "DELETE FROM posted_telegram_publish_attempts
                     WHERE post_id=:post AND state='sending'"
                );
                $discard->execute(['post'=>$postId]);
                if($discard->rowCount()!==1){
                    throw new RuntimeException('Не удалось снять резерв отклонённой публикации.');
                }
                PostedTelegramTopHold::knownRejectLocked($this->pdo,$postId);
                $this->refundManagerQuotaForPost($postId);
                $this->pdo->commit();
                return 'failed';
            }
            if($attemptState===''&&$status==='publishing'){
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status='failed',publish_started_at=NULL,error_text=:error
                     WHERE id=:post AND status='publishing'"
                )->execute([
                    'error'=>mb_substr($exception->getMessage(),0,4000),
                    'post'=>$postId,
                ]);
                PostedTelegramTopHold::knownRejectLocked($this->pdo,$postId);
                $this->refundManagerQuotaForPost($postId);
                $this->pdo->commit();
                return 'failed';
            }
            $this->pdo->commit();
            return $status;
        }catch(Throwable $transitionError){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $transitionError;
        }
    }

    private function rescheduleTelegramRateLimitedPost(
        array $post,
        PostedTelegramRequestException $exception
    ): ?string {
        if ((string)($post['_claimed_from_status']??'') !== 'scheduled') {
            return null;
        }
        $retrySeconds = min(3600, max(30, (int)($exception->retryAfter()??60)));
        $retryAt = date('Y-m-d H:i:s', time() + $retrySeconds);
        $postId = (int)($post['id']??0);
        if ($postId <= 0) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $current = $this->pdo->prepare(
                "SELECT post.status,attempt.state AS attempt_state
                 FROM posted_posts post
                 LEFT JOIN posted_telegram_publish_attempts attempt ON attempt.post_id=post.id
                 WHERE post.id=:post
                 LIMIT 1 FOR UPDATE"
            );
            $current->execute(['post'=>$postId]);
            $row = $current->fetch();
            if (
                !is_array($row)
                || (string)($row['status']??'') !== 'publishing'
                || (string)($row['attempt_state']??'') !== 'sending'
            ) {
                $this->pdo->commit();
                return null;
            }
            PostedTelegramNativeStore::retireKnownRejectedAttemptLocked($this->pdo,$postId);
            PostedTelegramCaptionVector::retireKnownRejectedAttemptLocked($this->pdo,$postId);
                PostedTelegramCaptionPlacement::retireKnownRejectedAttemptLocked($this->pdo,$postId);
            PostedTelegramTransportLayout::retireKnownRejectedAttemptLocked($this->pdo,$postId);
            PostedTelegramPublicButtonLayout::retireKnownRejectedAttemptLocked($this->pdo,$postId);
            $updated = $this->pdo->prepare(
                "UPDATE posted_posts
                 SET status='scheduled',scheduled_at=:retry_at,publish_started_at=NULL,
                     error_text=:error
                 WHERE id=:post AND status='publishing'"
            );
            $updated->execute([
                'retry_at'=>$retryAt,
                'error'=>'Telegram временно ограничил частоту запросов. Повтор запланирован автоматически.',
                'post'=>$postId,
            ]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('Не удалось перенести публикацию после ограничения Telegram.');
            }
            PostedTelegramTopHold::knownRejectLocked($this->pdo,$postId);
            $discard = $this->pdo->prepare(
                "DELETE FROM posted_telegram_publish_attempts
                 WHERE post_id=:post AND state='sending'"
            );
            $discard->execute(['post'=>$postId]);
            if ($discard->rowCount() !== 1) {
                throw new RuntimeException('Не удалось освободить попытку после ограничения Telegram.');
            }
            $this->pdo->commit();
            return $retryAt;
        } catch (Throwable $transitionError) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $transitionError;
        }
    }

    private function refundManagerQuotaForPost(
        int $postId,
        bool $refundPostQuota = true,
        bool $refundPinQuota = true
    ): void
    {
        $postStmt = $this->pdo->prepare(
            'SELECT channel_id,author_id,manager_category_id,manager_post_quota_charged,manager_pin_quota_charged
             FROM posted_posts WHERE id=:post LIMIT 1 FOR UPDATE'
        );
        $postStmt->execute(['post'=>$postId]);
        $post = $postStmt->fetch();
        if (!is_array($post)) return;
        $refundPost = $refundPostQuota && !empty($post['manager_post_quota_charged']);
        $refundPin = $refundPinQuota && !empty($post['manager_pin_quota_charged']);
        if (!$refundPost && !$refundPin) return;
        $categoryId=(int)($post['manager_category_id']??0);
        $quotaTable=$categoryId>0?'posted_manager_categories':'posted_permissions';
        $quotaWhere=$categoryId>0?'id=:category AND user_id=:user AND channel_id=:channel':'user_id=:user AND channel_id=:channel AND is_ad_manager=1';
        $quota=$this->pdo->prepare(
            'UPDATE '.$quotaTable.'
             SET post_quota_remaining=IF(:refund_post=1 AND post_quota_remaining IS NOT NULL,post_quota_remaining+1,post_quota_remaining),
                 pin_quota_remaining=IF(:refund_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining+1,pin_quota_remaining)
             WHERE '.$quotaWhere
        );$quotaParams=[
            'refund_post'=>$refundPost ? 1 : 0,
            'refund_pin'=>$refundPin ? 1 : 0,
            'user'=>(int) $post['author_id'],
            'channel'=>(int) $post['channel_id'],
        ];if($categoryId>0)$quotaParams['category']=$categoryId;$quota->execute($quotaParams);
        $this->pdo->prepare(
            'UPDATE posted_posts
             SET manager_post_quota_charged=IF(:refund_post=1,0,manager_post_quota_charged),
                 manager_pin_quota_charged=IF(:refund_pin=1,0,manager_pin_quota_charged)
             WHERE id=:post'
        )->execute([
            'refund_post'=>$refundPost ? 1 : 0,
            'refund_pin'=>$refundPin ? 1 : 0,
            'post'=>$postId,
        ]);
    }

    private function resolvePartialTelegramFailure(
        int $postId,
        array $persistedIds,
        Throwable $exception
    ):string {
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare(
                "SELECT post.*,attempt.state AS attempt_state
                 FROM posted_posts post
                 INNER JOIN posted_telegram_publish_attempts attempt
                    ON attempt.post_id=post.id
                 WHERE post.id=:post
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['post'=>$postId]);
            $row=$stmt->fetch();
            if(!is_array($row)){
                $this->pdo->commit();
                return 'processing';
            }
            $state=(string)($row['attempt_state']??'');
            $status=(string)($row['status']??'');
            if($state==='cancel_requested'){
                if($status==='publishing'){
                    $this->pdo->prepare(
                        "UPDATE posted_posts
                         SET status='telegram_pending',publish_started_at=NULL
                         WHERE id=:post AND status='publishing'"
                    )->execute(['post'=>$postId]);
                }
                $this->pdo->commit();
                $this->finalizeTelegramPublishAttempt($postId);
                $fresh=$this->post($postId);
                return is_array($fresh)&&(string)($fresh['status']??'')==='deleted'
                    ?'cancelled'
                    :'processing';
            }
            if($state==='sending'&&$status==='publishing'){
                $published=date('Y-m-d H:i:s');
                $minutes=$this->normalizeTelegramDeleteTimerMinutes((int)($row['delete_after_minutes']??0));
                $deleteAt=$minutes>0
                    ?date('Y-m-d H:i:s',strtotime($published)+$minutes*60)
                    :null;
                $updated=$this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status='sent',publish_started_at=NULL,
                         published_at=COALESCE(published_at,:published),
                         delete_at=:delete_at,manual_delete_required=0,
                         telegram_message_id=COALESCE(telegram_message_id,:first),
                         telegram_message_ids=:ids,
                         error_text=:error
                     WHERE id=:post AND status='publishing'"
                );
                $updated->execute([
                    'published'=>$published,
                    'delete_at'=>$deleteAt,
                    'first'=>$persistedIds[0]??null,
                    'ids'=>$this->encodeMessageSlots($persistedIds),
                    'error'=>'Публикация отправлена полностью или частично; требуется ручная проверка: '
                        .mb_substr($exception->getMessage(),0,3500),
                    'post'=>$postId,
                ]);
                if($updated->rowCount()!==1){
                    throw new RuntimeException('Частичная публикация уже изменила состояние.');
                }
                $this->completeTelegramAttemptRow($postId,true);
                $this->pdo->commit();
                return 'sent_partial';
            }
            $this->pdo->commit();
            return $status==='deleted'?'cancelled':'processing';
        }catch(Throwable $transitionError){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $transitionError;
        }
    }

    private function publishClaimedPost(array $post, ?int $actorId = null): array
    {
        if($this->hasRecordedTelegramPublishAttempt((int)$post['id']))return ['state'=>'processing','message_ids'=>[],'message_slots'=>[]];
        try {
            if((int)($post['repeat_interval_minutes']??0)>0){
                PostedTelegramPolicy::requireOperator($this->config,(int)($post['author_id']??0),$this->pdo);
                if($actorId!==null)PostedTelegramPolicy::requireOperator($this->config,$actorId,$this->pdo);
                PostedTelegramPolicy::requireChannel($this->pdo,$this->config,['id'=>(int)$post['channel_id'],'chat_id'=>(string)$post['chat_id']],'premium');
            }
            $post=$this->telegramPolicyPublishPreflight($post,$actorId);
            $post=PostedTelegramPostOptions::freeze($this->pdo,$post);
        $post=PostedTelegramTransportLayout::freeze($this->pdo,$post);
        $post=PostedTelegramPublicButtonLayout::freeze($this->pdo,$post,$this->effectivePostPayload($post));
            $post=PostedTelegramTopHold::freeze($this->pdo,$post);
            $reserved = $this->reserveTelegramPublishAttempt($post, $actorId);
        } catch (Throwable $exception) {
            if($exception instanceof PostedTelegramAlbumPendingException){
                $notice=$exception->getMessage();$restored=$this->restoreBlockedPublishClaim($post,$notice);return['state'=>$restored?'blocked':'processing','notice'=>$notice,'message_ids'=>[],'message_slots'=>[]];
            }
            if($exception instanceof PostedTelegramTopHoldException){
                $notice=$exception->getMessage();$blocking=$exception->blocking;
                if(is_array($blocking)){if(!empty($blocking['post_link']))$notice.="\nПост: ".$blocking['post_link'];if(is_int($blocking['unlock_at']??null))$notice.="\nМожно публиковать после ".$this->formatStoredForUser($actorId??(int)$post['author_id'],date('Y-m-d H:i:s',$blocking['unlock_at']),'d.m.Y H:i:s').'.';}
                $restored=$this->restoreBlockedPublishClaim($post,$notice);return ['state'=>$restored?'blocked':'processing','top_blocked'=>true,'notice'=>$notice,'message_ids'=>[],'message_slots'=>[]];
            }
            if($exception instanceof PostedTelegramAuthorityPauseException){
                $restored=$this->restoreBlockedPublishClaim($post,$exception->getMessage());
                return ['state'=>$restored?'blocked':'processing','message_ids'=>[],'message_slots'=>[]];
            }
            if($exception instanceof PostedTelegramMultipostPreflightException){
                return $this->telegramMultipostHoldUnreservedClaim($post,$exception);
            }
            if($exception instanceof PostedTelegramPolicyException){
                return $this->telegramPolicyHoldUnreservedClaim($post,$exception,$actorId);
            }
            if($exception instanceof PostedPublishAuthorizationException){
                if(!$this->cancelUnauthorizedTelegramPublishClaim($post,$exception->getMessage()))return ['state'=>'processing','message_ids'=>[],'message_slots'=>[]];
                return ['state'=>'blocked','message_ids'=>[],'message_slots'=>[]];
            }
            if(!$this->restoreBlockedPublishClaim($post, $exception->getMessage())&&$this->hasRecordedTelegramPublishAttempt((int)$post['id']))return ['state'=>'processing','message_ids'=>[],'message_slots'=>[]];
            throw $exception;
        }
        if(!$reserved){
            $restored=$this->restoreBlockedPublishClaim($post);
            return ['state'=>$restored?'blocked':'processing','message_ids'=>[],'message_slots'=>[]];
        }
        try{
            $this->validatePostForPublish($post);
            $outcome=$this->publishPost($post);
            $ids=array_values(array_map('intval',(array)($outcome['message_ids']??[])));
            $slots=array_values(array_map('intval',(array)($outcome['message_slots']??[])));
            $pendingParts=array_values((array)($outcome['pending_parts']??[]));
            $continuation=array_values((array)($outcome['continuation']??[]));
            if($pendingParts!==[]){
                $pending=$this->storeTelegramPendingOutcome(
                    (int)$post['id'],
                    $slots,
                    $pendingParts,
                    $continuation,
                    'awaiting_telegram',
                    'Telegram принял публикацию и обрабатывает видео. Повторно отправлять пост не нужно.'
                );
                if(!empty($pending['complete'])){
                    $this->finalizeTelegramPublishAttempt((int)$post['id']);
                }
                $fresh=$this->post((int)$post['id']);
                return [
                    'state'=>(string)($fresh['status']??'')==='sent'?'sent':'processing',
                    'message_ids'=>$fresh?$this->postMessageIds($fresh):$ids,
                    'message_slots'=>(array)($pending['slots']??$slots),
                ];
            }
            throw new PostedTelegramAcceptedButNotPersistedException(
                new RuntimeException('Telegram не вернул данные для фиксации публикации.')
            );
        }catch(Throwable $exception){
            $fresh=$this->post((int)$post['id']);
            $persistedIds=$fresh ? $this->postMessageIds($fresh) : [];
            if (
                $persistedIds===[]
                &&$exception instanceof PostedTelegramRequestException
                &&(
                    $exception->httpStatus()===429
                    ||$exception->retryAfter()!==null
                )
            ) {
                $retryAt=$this->rescheduleTelegramRateLimitedPost($post,$exception);
                if($retryAt!==null){
                    return [
                        'state'=>'retry_scheduled',
                        'retry_at'=>$retryAt,
                        'message_ids'=>[],
                        'message_slots'=>[],
                    ];
                }
            }
            $knownRejected=(
                $exception instanceof PostedTelegramRequestException
                &&!$exception->deliveryMayBeUnknown()
            )||$exception instanceof InvalidArgumentException;
            $knownRejected=$knownRejected&&$persistedIds===[];
            if($knownRejected){
                $resolved=$this->resolveInitialTelegramFailure(
                    (int)$post['id'],
                    $exception
                );
                if($resolved==='cancelled'){
                    return ['state'=>'cancelled','message_ids'=>[],'message_slots'=>[]];
                }
                throw new PostedTerminalPublishException(
                    $exception->getMessage(),
                    0,
                    $exception
                );
            }
            $deliveryMayBeUnknown=$exception instanceof PostedTelegramRequestException
                ? $exception->deliveryMayBeUnknown()
                : $exception instanceof PostedTelegramAcceptedButNotPersistedException;
            if($deliveryMayBeUnknown){
                $parts=$this->expectedTelegramPendingParts($post);
                if($parts===[])$deliveryMayBeUnknown=false;
            }
            if($deliveryMayBeUnknown){
                $slots=$fresh
                    ?$this->decodeMessageSlots((string)($fresh['telegram_message_ids']??''))
                    :[];
                if($slots===[])$slots=array_fill(0,count($parts),0);
                $continuation=$this->telegramContinuationAfterPendingPrimary($post);
                $pending=$this->storeTelegramPendingOutcome(
                    (int)$post['id'],
                    $slots,
                    $parts,
                    $continuation,
                    'needs_review',
                    in_array($post['media_type']??null,['poll','location','contact'],true)?'Telegram не подтвердил результат. Публикация могла быть принята. Автоматический повтор отключён; требуется ручная проверка.':'Telegram не вернул подтверждение. Повтор отключён: публикация могла быть принята и будет сопоставлена после выхода в канале.'
                );
                if(!empty($pending['complete'])){
                    $this->finalizeTelegramPublishAttempt((int)$post['id']);
                }
                return array_merge(in_array($post['media_type']??null,['poll','location','contact'],true)?[
                    'native_manual_review'=>true,
                    'notice'=>'Telegram не подтвердил результат. Автоматический повтор отключён; требуется ручная проверка.',
                ]:[],[
                    'state'=>'processing',
                    'message_ids'=>$persistedIds,
                    'message_slots'=>(array)($pending['slots']??$slots),
                ]);
            }
            if($persistedIds!==[]){
                $resolved=$this->resolvePartialTelegramFailure(
                    (int)$post['id'],
                    $persistedIds,
                    $exception
                );
                return [
                    'state'=>$resolved,
                    'message_ids'=>$persistedIds,
                    'message_slots'=>$persistedIds,
                ];
            }else{
                $resolved=$this->resolveInitialTelegramFailure(
                    (int)$post['id'],
                    $exception
                );
                if($resolved==='cancelled'){
                    return ['state'=>'cancelled','message_ids'=>[],'message_slots'=>[]];
                }
            }
            if($persistedIds===[]){
                throw new PostedTerminalPublishException(
                    $exception->getMessage(),
                    0,
                    $exception
                );
            }
            throw $exception;
        }
    }

    private function storeTelegramPendingOutcome(
        int $postId,
        array $slots,
        array $parts,
        array $continuation,
        string $waitingState,
        string $errorText
    ):array {
        if(!in_array($waitingState,['awaiting_telegram','needs_review'],true)){
            throw new InvalidArgumentException('Некорректное состояние ожидания Telegram.');
        }
        if($parts===[]){
            throw new RuntimeException('Не удалось сформировать данные для сопоставления публикации Telegram.');
        }

        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare(
                'SELECT state,message_slots_json,pending_parts_json
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['post'=>$postId]);
            $attempt=$stmt->fetch();
            if(!is_array($attempt)){
                throw new RuntimeException('Попытка публикации Telegram не найдена.');
            }
            $currentState=(string)$attempt['state'];
            if(!in_array($currentState,['sending','cancel_requested'],true)){
                $storedSlots=$this->decodeMessageSlots(
                    (string)($attempt['message_slots_json']??'')
                );
                $storedParts=$this->decodeJson(
                    (string)($attempt['pending_parts_json']??'')
                );
                $storedComplete=$storedParts!==[];
                foreach($storedParts as $storedPart){
                    if(!is_array($storedPart)||(int)($storedPart['message_id']??0)<=0){
                        $storedComplete=false;
                        break;
                    }
                }
                $this->pdo->commit();
                return [
                    'state'=>$currentState,
                    'complete'=>$storedComplete,
                    'slots'=>$storedSlots,
                ];
            }

            $storedSlots=$this->decodeMessageSlots((string)($attempt['message_slots_json']??''));
            foreach($storedSlots as $index=>$storedId){
                while(count($slots)<=$index)$slots[]=0;
                if($storedId>0)$slots[$index]=$storedId;
            }

            $storedParts=$this->decodeJson((string)($attempt['pending_parts_json']??''));
            foreach($parts as $index=>&$part){
                if(!is_array($part))continue;
                $storedMatch=null;
                foreach($storedParts as $storedPart){
                    if(!is_array($storedPart))continue;
                    if((int)($storedPart['slot']??-1)!==(int)($part['slot']??$index))continue;
                    $storedMatch=$storedPart;
                    break;
                }
                if(is_array($storedMatch)){
                    $part=(int)($storedMatch['message_id']??0)>0
                        ?$this->mergeTelegramPendingPart($part,$storedMatch)
                        :$this->mergeTelegramPendingPart($storedMatch,$part);
                }
            }
            unset($part);

            $complete=$parts!==[];
            foreach($parts as $part){
                if(!is_array($part)||(int)($part['message_id']??0)<=0){
                    $complete=false;
                    break;
                }
            }
            $nextState=$currentState==='cancel_requested'
                ? 'cancel_requested'
                : ($complete?'reconciled':$waitingState);

            $this->pdo->prepare(
                'UPDATE posted_telegram_publish_attempts
                 SET state=:state,message_slots_json=:slots,pending_parts_json=:parts,
                     continuation_json=:continuation,
                     accepted_at=COALESCE(accepted_at,NOW()),
                     deadline_at=COALESCE(deadline_at,NOW()+INTERVAL 24 HOUR),
                     completed_at=IF(:complete=1,COALESCE(completed_at,NOW()),completed_at)
                 WHERE post_id=:post'
            )->execute([
                'state'=>$nextState,
                'slots'=>$this->encodeMessageSlots($slots),
                'parts'=>$this->encodeJson($parts),
                'continuation'=>$this->encodeJson($continuation),
                'complete'=>$complete?1:0,
                'post'=>$postId,
            ]);
            $positive=array_values(array_filter(
                array_map('intval',$slots),
                static fn(int $id):bool=>$id>0
            ));
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET status=IF(status='publishing','telegram_pending',status),
                     telegram_message_id=COALESCE(telegram_message_id,:first),
                     telegram_message_ids=:slots,error_text=:error
                 WHERE id=:post AND status IN ('publishing','telegram_pending')"
            )->execute([
                'first'=>$positive[0]??null,
                'slots'=>$this->encodeMessageSlots($slots),
                'error'=>$errorText,
                'post'=>$postId,
            ]);
            $this->pdo->commit();
            return ['state'=>$nextState,'complete'=>$complete,'slots'=>$slots];
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($exception instanceof PostedTelegramAcceptedButNotPersistedException)throw $exception;
            throw new PostedTelegramAcceptedButNotPersistedException($exception);
        }
    }

    private function handleTelegramChannelPost(array $message,int $updateId):void
    {
        $chatId=trim((string)(($message['chat']??[])['id']??''));
        $messageId=(int)($message['message_id']??0);
        if($chatId===''||$messageId<=0)return;
        $incoming=$this->telegramPendingPartFromMessage($message,0,$chatId);
        $incomingMatchKeys=array_values(array_filter(
            array_map('strval',(array)($incoming['match_keys']??[$incoming['match_key']??'']))
        ));
        $incomingFallbackKeys=array_values(array_filter(
            array_map('strval',(array)($incoming['fallback_match_keys']??[]))
        ));
        $incomingLegacyMatchKeys=array_values(array_filter(
            array_map('strval',(array)($incoming['legacy_match_keys']??[]))
        ));
        $incomingLegacyFallbackKeys=array_values(array_filter(
            array_map('strval',(array)($incoming['legacy_fallback_match_keys']??[]))
        ));
        $publishedTimestamp=(int)($message['date']??0);
        if($publishedTimestamp<=0)$publishedTimestamp=time();

        try{
            $this->pdo->beginTransaction();
            $stmt=$this->pdo->prepare(
                "SELECT attempt.*,post.status AS post_status
                 FROM posted_telegram_publish_attempts attempt
                 INNER JOIN posted_posts post ON post.id=attempt.post_id
                 WHERE attempt.chat_id=:chat
                   AND attempt.state IN ('sending','continuation_sending','awaiting_telegram','needs_review','finalization_uncertain','cancel_requested')
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['chat'=>$chatId]);
            $attempt=$stmt->fetch();
            if(!is_array($attempt)){
                $this->pdo->commit();
                return;
            }
            $createdAt=strtotime((string)($attempt['created_at']??''));
            if(
                $createdAt!==false
                &&(
                    $publishedTimestamp<$createdAt-60
                    ||$publishedTimestamp>$createdAt+7*86400
                )
            ){
                $this->pdo->commit();
                return;
            }
            $slots=$this->decodeMessageSlots((string)($attempt['message_slots_json']??''));
            if(in_array($messageId,$slots,true)){
                $this->pdo->commit();
                return;
            }
            $parts=$this->decodeJson((string)($attempt['pending_parts_json']??''));
            $matches=[];
            foreach($parts as $index=>$part){
                if(!is_array($part)||!empty($part['message_id']))continue;
                if(!empty($part['native_no_reconcile'])||in_array($part['media_type']??null,['poll','location','contact'],true))continue;
                if(!PostedTelegramCaptionPlacement::matches($part,$incoming))continue;
                $partMatchKeys=array_values(array_filter(
                    array_map('strval',(array)($part['match_keys']??[$part['match_key']??'']))
                ));
                $strictMatch=array_intersect($partMatchKeys,$incomingMatchKeys)!==[];
                $legacyAttempt=empty($part['entity_aware_match']);
                if(!$strictMatch&&$legacyAttempt){
                    $strictMatch=array_intersect(
                        $partMatchKeys,
                        $incomingLegacyMatchKeys
                    )!==[];
                }
                $fallbackMatch=false;
                if(!$strictMatch&&!empty($part['allow_fallback_match'])){
                    $partFallbackKeys=array_values(array_filter(
                        array_map('strval',(array)($part['fallback_match_keys']??[]))
                    ));
                    $fallbackMatch=array_intersect($partFallbackKeys,$incomingFallbackKeys)!==[];
                    if(!$fallbackMatch&&$legacyAttempt){
                        $fallbackMatch=array_intersect(
                            $partFallbackKeys,
                            $incomingLegacyFallbackKeys
                        )!==[];
                    }
                }
                if($strictMatch||$fallbackMatch)$matches[]=(int)$index;
            }
            if($matches===[]){
                $this->pdo->commit();
                return;
            }
            if(str_starts_with((string)($incoming['media_type']??''),'paid_media')&&count($matches)!==1){
                $this->pdo->commit();
                return;
            }
            sort($matches,SORT_NUMERIC);
            $matchIndex=$matches[0];
            $slot=max(0,(int)($parts[$matchIndex]['slot']??$matchIndex));
            while(count($slots)<=$slot)$slots[]=0;
            $slots[$slot]=$messageId;
            $parts[$matchIndex]['message_id']=$messageId;
            $parts[$matchIndex]['update_id']=max(0,$updateId);
            $parts[$matchIndex]['published_at']=$publishedTimestamp;
            $parts[$matchIndex]['matched_at']=date('Y-m-d H:i:s');
            $parts[$matchIndex]['channel_confirmed']=true;
            $unmatched=array_filter(
                $parts,
                static fn(mixed $part):bool=>is_array($part)&&(int)($part['message_id']??0)<=0
            );
            $complete=$unmatched===[]&&$parts!==[];
            $currentState=(string)$attempt['state'];
            if($complete){
                $nextState=match($currentState){
                    'sending'=>'sending',
                    'continuation_sending'=>'continuation_sending',
                    'cancel_requested'=>'cancel_requested',
                    default=>'reconciled',
                };
            }else{
                $nextState=$currentState==='needs_review'?'awaiting_telegram':$currentState;
            }
            $this->pdo->prepare(
                'UPDATE posted_telegram_publish_attempts
                 SET state=:state,message_slots_json=:slots,pending_parts_json=:parts,
                     completed_at=IF(:complete=1,NOW(),completed_at)
                 WHERE post_id=:post'
            )->execute([
                'state'=>$nextState,
                'slots'=>$this->encodeMessageSlots($slots),
                'parts'=>$this->encodeJson($parts),
                'complete'=>$unmatched===[]&&$parts!==[]?1:0,
                'post'=>(int)$attempt['post_id'],
            ]);
            $positive=array_values(array_filter($slots,static fn(int $id):bool=>$id>0));
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET telegram_message_id=COALESCE(telegram_message_id,:first),
                     telegram_message_ids=:slots
                 WHERE id=:post AND status IN ('publishing','telegram_pending')"
            )->execute([
                'first'=>$positive[0]??null,
                'slots'=>$this->encodeMessageSlots($slots),
                'post'=>(int)$attempt['post_id'],
            ]);
            $this->pdo->commit();
            if($nextState==='cancel_requested'){
                try{
                    $this->telegram->call('deleteMessage',[
                        'chat_id'=>$chatId,
                        'message_id'=>$messageId,
                    ]);
                }catch(Throwable $deleteError){
                    if(!str_contains(mb_strtolower($deleteError->getMessage()),'message to delete not found')){
                        $this->pdo->prepare(
                            "UPDATE posted_posts
                             SET error_text=:error
                             WHERE id=:post AND status='telegram_pending'"
                        )->execute([
                            'error'=>'Отмена принята, но Telegram пока не удалил появившуюся часть: '.mb_substr($deleteError->getMessage(),0,3300),
                            'post'=>(int)$attempt['post_id'],
                        ]);
                        error_log('GitPosted delayed Telegram cancellation delete failed: '.$deleteError->getMessage());
                    }
                }
            }
            if($complete&&!in_array($nextState,['sending','continuation_sending'],true)){
                $this->finalizeTelegramPublishAttempt((int)$attempt['post_id']);
            }
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            error_log('GitPosted delayed Telegram reconciliation failed: '.$exception->getMessage());
            throw $exception;
        }
    }

    private function finalizeTelegramPublishAttempt(int $postId):void
    {
        $post=null;
        $attempt=null;
        $normalRetryState='needs_review';
        try{
            $channelLookup=$this->pdo->prepare(
                'SELECT channel_id FROM posted_posts WHERE id=:post LIMIT 1'
            );
            $channelLookup->execute(['post'=>$postId]);
            $channelId=(int)($channelLookup->fetchColumn()?:0);
            if($channelId<=0)return;
            $this->pdo->beginTransaction();
            $channelStmt=$this->pdo->prepare(
                'SELECT chat_id,title
                 FROM posted_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelStmt->execute(['channel'=>$channelId]);
            $lockedChannel=$channelStmt->fetch();
            $postStmt=$this->pdo->prepare(
                'SELECT *
                 FROM posted_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postStmt->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedPost=$postStmt->fetch();
            $attemptStmt=$this->pdo->prepare(
                'SELECT *
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $attemptStmt->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedAttempt=$attemptStmt->fetch();
            $row=is_array($lockedChannel)&&is_array($lockedPost)&&is_array($lockedAttempt)
                ?array_merge(
                    $lockedAttempt,
                    $lockedPost,
                    [
                        'chat_id'=>(string)$lockedChannel['chat_id'],
                        'channel_title'=>(string)$lockedChannel['title'],
                    ]
                )
                :null;
            if(!is_array($row)||!in_array((string)$row['state'],['reconciled','cancel_requested'],true)){
                $this->pdo->commit();
                return;
            }
            $attempt=$row;
            $post=$row;
            $finalizingState=(string)$row['state']==='cancel_requested'
                ? 'cancel_finalizing'
                : 'finalizing';
            $this->pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET state=:state
                 WHERE post_id=:post AND state IN ('reconciled','cancel_requested')"
            )->execute(['state'=>$finalizingState,'post'=>$postId]);
            $this->pdo->commit();

            $slots=$this->decodeMessageSlots((string)($attempt['message_slots_json']??''));
            $parts=$this->decodeJson((string)($attempt['pending_parts_json']??''));
            $delayedTelegramResult=$this->shouldNotifyDelayedTelegramCompletion($parts);
            $publishedTimestamps=[];
            foreach($parts as $part){
                $timestamp=(int)(is_array($part)?($part['published_at']??0):0);
                if($timestamp>0)$publishedTimestamps[]=$timestamp;
            }
            $publishedTimestamp=$publishedTimestamps!==[]?min($publishedTimestamps):time();

            if((string)$attempt['state']==='cancel_requested'){
                $manualDeleteErrors=[];
                foreach(array_values(array_unique(array_filter($slots))) as $messageId){
                    try{
                        $this->telegram->call('deleteMessage',[
                            'chat_id'=>(string)$post['chat_id'],
                            'message_id'=>(int)$messageId,
                        ]);
                    }catch(Throwable $deleteError){
                        if($this->telegramDeleteNotFound($deleteError))continue;
                        if($this->telegramDeleteNeedsManualAction($deleteError)){
                            $manualDeleteErrors[]=$deleteError->getMessage();
                            continue;
                        }
                        throw $deleteError;
                    }
                }
                $manualDeleteRequired=$manualDeleteErrors!==[];
                $manualDeleteError=$manualDeleteRequired
                    ?$this->telegramManualDeleteError($manualDeleteErrors)
                    :null;
                $this->pdo->beginTransaction();
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status='deleted',publish_started_at=NULL,published_at=:published,
                         delete_at=NULL,manual_delete_required=:manual_delete_required,
                         error_text=:error
                     WHERE id=:post AND status='telegram_pending'"
                )->execute([
                    'published'=>date('Y-m-d H:i:s',$publishedTimestamp),
                    'manual_delete_required'=>$manualDeleteRequired?1:0,
                    'error'=>$manualDeleteError,
                    'post'=>$postId,
                ]);
                $this->completeTelegramAttemptRow($postId,true);
                $this->pdo->commit();
                $this->notifyDelayedTelegramResult(
                    (int)$post['author_id'],
                    $manualDeleteRequired
                        ?'Ожидание отменено и канал освобождён, но Telegram не разрешил удалить опубликованное сообщение. Удалите его в канале вручную.'
                        :'Отложенная Telegram-публикация появилась и была сразу удалена по вашей команде.'
                );
                return;
            }

            $continuation=$this->decodeJson((string)($attempt['continuation_json']??''));
            foreach($continuation as $index=>$item){
                if(!is_array($item))continue;
                $remainingContinuation=array_values(array_slice($continuation,(int)$index+1));
                try {
                    $staged=$this->stageTelegramContinuation(
                        $postId,
                        $post,
                        $slots,
                        $parts,
                        $remainingContinuation,
                        $item
                    );
                } catch (PostedPublishAuthorizationException $authorizationError) {
                    $this->finishTelegramContinuationAsPartial(
                        $postId,$post,$slots,$publishedTimestamp,$authorizationError
                    );
                    return;
                }
                $slots=(array)$staged['slots'];
                $parts=(array)$staged['parts'];
                $slot=(int)$staged['slot'];
                $normalRetryState='finalization_uncertain';
                try{
                    $result=$this->telegram->sendMessage(
                        (string)$post['chat_id'],
                        (string)($item['text']??''),
                        (array)($item['extra']??[])
                    );
                }catch(PostedTelegramRequestException $sendError){
                    if(!$sendError->deliveryMayBeUnknown()){
                        $this->finishTelegramContinuationAsPartial(
                            $postId,
                            $post,
                            $slots,
                            $publishedTimestamp,
                            $sendError
                        );
                        return;
                    }
                    $settled=$this->settleTelegramContinuation(
                        $postId,
                        $post,
                        $slot,
                        [
                            'message_id'=>0,
                            'chat'=>['id'=>(string)$post['chat_id']],
                            'text'=>(string)($item['text']??''),
                        ]
                    );
                    $slots=(array)$settled['slots'];
                    $parts=(array)$settled['parts'];
                    if((string)$settled['state']==='finalizing'){
                        $normalRetryState='needs_review';
                        continue;
                    }
                    return;
                }
                $payload=$result['result']??null;
                $message=is_array($payload)?$payload:[
                    'message_id'=>0,
                    'chat'=>['id'=>(string)$post['chat_id']],
                    'text'=>(string)($item['text']??''),
                ];
                $settled=$this->settleTelegramContinuation(
                    $postId,
                    $post,
                    $slot,
                    $message
                );
                $slots=(array)$settled['slots'];
                $parts=(array)$settled['parts'];
                if((string)$settled['state']!=='finalizing'){
                    return;
                }
                $normalRetryState='needs_review';
            }
            $normalRetryState='needs_review';
            $ids=array_values(array_unique(array_filter(
                array_map('intval',$slots),
                static fn(int $id):bool=>$id>0
            )));
            if($ids===[])throw new RuntimeException('Не получены реальные ID отложенной публикации.');
            $published=date('Y-m-d H:i:s',$publishedTimestamp);
            $minutes=$this->normalizeTelegramDeleteTimerMinutes((int)($post['delete_after_minutes']??0));
            $deleteAt=$minutes>0
                ?date('Y-m-d H:i:s',$publishedTimestamp+$minutes*60)
                :null;

            $topFinalHint=PostedTelegramTopHold::planningSnapshot($this->pdo,$channelId);
            $this->pdo->beginTransaction();
            foreach($topFinalHint['channel_ids']as$topFinalId){$finalChannel=$this->pdo->prepare('SELECT id FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$finalChannel->execute([$topFinalId]);if($finalChannel->fetchColumn()===false)throw new RuntimeException('Канал завершённой публикации не найден.');}
            PostedTelegramTopHold::verifyPlanningLocked($this->pdo,$topFinalHint);
            $stmt=$this->pdo->prepare(
                "UPDATE posted_posts
                 SET status='sent',telegram_message_id=:first,telegram_message_ids=:ids,
                     publish_started_at=NULL,published_at=:published,delete_at=:delete_at,
                     manual_delete_required=0,delete_notice_sent_at=NULL,delete_attempts=0,
                     original_media_type=media_type,error_text=NULL
                 WHERE id=:post AND status='telegram_pending'"
            );
            $stmt->execute([
                'first'=>$ids[0],
                'ids'=>$this->encodeMessageSlots($ids),
                'published'=>$published,
                'delete_at'=>$deleteAt,
                'post'=>$postId,
            ]);
            if($stmt->rowCount()!==1)throw new RuntimeException('Отложенная публикация уже изменила состояние.');
            $fresh=$this->post($postId)??array_replace($post,[
                'status'=>'sent',
                'published_at'=>$published,
                'telegram_message_id'=>$ids[0],
                'telegram_message_ids'=>$this->encodeMessageSlots($ids),
            ]);
            PostedTelegramTopHold::finalizeKnownLocked($this->pdo,$postId);
            $this->createRepeatedPost($fresh);
            $this->telegramMultipostKnownFullLocked($fresh,['id'=>$channelId,'chat_id'=>(string)$lockedChannel['chat_id']],$slots,true,true);
            $this->completeTelegramAttemptRow($postId);
            $this->pdo->commit();
            $this->telegramMultipostAfterCommit($postId);
            $this->completeTelegramPinEffect($postId,$fresh,$ids);
            if($delayedTelegramResult){
                $this->notifyDelayedTelegramResult(
                    (int)$post['author_id'],
                    'Telegram подтвердил публикацию. Пост больше не находится в ожидании.'
                );
            }
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            $retryState=is_array($attempt)&&(string)($attempt['state']??'')==='cancel_requested'
                ? 'cancel_requested'
                : $normalRetryState;
            $retrySql=$retryState==='cancel_requested'
                ? "UPDATE posted_telegram_publish_attempts
                   SET state='cancel_requested'
                   WHERE post_id=:post AND state='cancel_finalizing'"
                : "UPDATE posted_telegram_publish_attempts
                   SET state=:state
                   WHERE post_id=:post
                     AND state IN ('finalizing','continuation_sending')";
            $retryParams=['post'=>$postId];
            if($retryState!=='cancel_requested')$retryParams['state']=$retryState;
            $this->pdo->prepare($retrySql)->execute($retryParams);
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET error_text=:error
                 WHERE id=:post AND status='telegram_pending'"
            )->execute([
                'error'=>'Telegram опубликовал медиа, но завершение требует проверки: '.mb_substr($exception->getMessage(),0,3400),
                'post'=>$postId,
            ]);
            error_log('GitPosted delayed Telegram finalization failed: '.$exception->getMessage());
        }
    }

    private function telegramPostAuthorityFailureLocked(array $post,array $channel): ?string
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Проверка прав требует транзакцию.');
        if(!PostedTelegramRootedAccess::channel($this->pdo,$this->config,(int)$post['author_id'],(int)$channel['id']))return 'Доступ автора к этому каналу отозван. Пост сохранён.';
        if(PostedTelegramAuthorityPause::blocked($this->pdo,$channel,true))return 'Публикации приостановлены; новые части не отправлены. Сохранённые данные требуют проверки.';
        $ownerId=(int)($channel['added_by']??0);
        $authorId=(int)($post['author_id']??0);
        if (empty($channel['is_active']) || $ownerId<=0 || empty($channel['bot_can_post'])) {
            return 'Канал отключён или у бота отозвано право публикации.';
        }
        if ($authorId<=0 || !$this->lockActiveTelegramActors([$ownerId,$authorId])) {
            return 'Владелец или автор публикации заблокирован.';
        }
        $permissionStmt=$this->pdo->prepare(
            'SELECT can_post,can_schedule,is_ad_manager FROM posted_permissions
             WHERE user_id=:user AND channel_id=:channel LIMIT 1 FOR UPDATE'
        );
        $permissionStmt->execute(['user'=>$authorId,'channel'=>$post['channel_id']]);
        $permission=$permissionStmt->fetch();
        $ability=!empty($post['scheduled_at'])?'can_schedule':'can_post';
        if (!is_array($permission) || empty($permission[$ability])) {
            return 'Права автора на публикацию отозваны или владелец канала изменился.';
        }
        $categoryId=(int)($post['manager_category_id']??0);
        if ($authorId!==$ownerId && $categoryId>0) {
            if (empty($permission['is_ad_manager'])) return 'Автор больше не является рекламным менеджером.';
            $category=$this->pdo->prepare(
                'SELECT id FROM posted_manager_categories WHERE id=:category AND user_id=:user
                 AND channel_id=:channel AND is_active=1 LIMIT 1 FOR UPDATE'
            );
            $category->execute(['category'=>$categoryId,'user'=>$authorId,'channel'=>$post['channel_id']]);
            if ($category->fetchColumn()===false) return 'Рекламная компания публикации отключена.';
        } elseif ($authorId!==$ownerId && !empty($permission['is_ad_manager'])) {
            $category=$this->pdo->prepare(
                'SELECT id FROM posted_manager_categories WHERE user_id=:user
                 AND channel_id=:channel AND is_active=1 LIMIT 1 FOR UPDATE'
            );
            $category->execute(['user'=>$authorId,'channel'=>$post['channel_id']]);
            if ($category->fetchColumn()!==false) return 'Рекламная компания публикации больше не определена.';
        }
        return null;
    }

    private function stageTelegramContinuation(
        int $postId,
        array $post,
        array $slots,
        array $parts,
        array $remainingContinuation,
        array $item
    ):array {
        $slot=count($slots);
        $slots[]=0;
        $parts[]=$this->telegramPendingPartFromMessage([
            'message_id'=>0,
            'chat'=>['id'=>(string)$post['chat_id']],
            'text'=>(string)($item['text']??''),
            'entities'=>(array)(($item['extra']??[])['entities']??[]),
        ],$slot,(string)$post['chat_id']);
        $encodedSlots=$this->encodeMessageSlots($slots);

        PostedTelegramRootedAccess::begin($this->pdo,$this->config);
        try{
            $channelStmt=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=:channel LIMIT 1 FOR UPDATE');
            $channelStmt->execute(['channel'=>$post['channel_id']]);
            $channel=$channelStmt->fetch();
            $postStmt=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=:post AND channel_id=:channel LIMIT 1 FOR UPDATE');
            $postStmt->execute(['post'=>$postId,'channel'=>$post['channel_id']]);
            $currentPost=$postStmt->fetch();
            $authorityFailure=is_array($channel)&&is_array($currentPost)
                ?$this->telegramPostAuthorityFailureLocked($currentPost,$channel)
                :'Канал или публикация больше недоступны.';
            if ($authorityFailure!==null) throw new PostedPublishAuthorizationException($authorityFailure);
            $stmt=$this->pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET state='continuation_sending',message_slots_json=:slots,
                     pending_parts_json=:parts,continuation_json=:continuation,
                     completed_at=NULL
                 WHERE post_id=:post AND state='finalizing'"
            );
            $stmt->execute([
                'slots'=>$encodedSlots,
                'parts'=>$this->encodeJson($parts),
                'continuation'=>$this->encodeJson($remainingContinuation),
                'post'=>$postId,
            ]);
            if($stmt->rowCount()!==1){
                throw new RuntimeException('Состояние продолжения Telegram уже изменилось.');
            }
            $postStmt=$this->pdo->prepare(
                "UPDATE posted_posts
                 SET telegram_message_ids=:slots
                 WHERE id=:post AND status='telegram_pending'"
            );
            $postStmt->execute(['slots'=>$encodedSlots,'post'=>$postId]);
            if($postStmt->rowCount()!==1){
                throw new RuntimeException('Ожидающая публикация Telegram уже изменила состояние.');
            }
            PostedTelegramRootedAccess::commit($this->pdo);
            return ['slot'=>$slot,'slots'=>$slots,'parts'=>$parts];
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);
            throw $exception;
        }
    }

    private function settleTelegramContinuation(
        int $postId,
        array $post,
        int $slot,
        array $message
    ):array {
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare(
                'SELECT state,message_slots_json,pending_parts_json
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['post'=>$postId]);
            $attempt=$stmt->fetch();
            if(!is_array($attempt)){
                throw new RuntimeException('Попытка продолжения Telegram не найдена.');
            }
            $currentState=(string)$attempt['state'];
            if(in_array(
                $currentState,
                ['cancel_finalizing','force_cancel_finalizing','completed'],
                true
            )){
                $slots=$this->decodeMessageSlots((string)($attempt['message_slots_json']??''));
                $parts=$this->decodeJson((string)($attempt['pending_parts_json']??''));
                $this->pdo->commit();
                return ['state'=>$currentState,'slots'=>$slots,'parts'=>$parts];
            }
            if(!in_array($currentState,['continuation_sending','cancel_requested'],true)){
                throw new RuntimeException('Состояние продолжения Telegram уже изменилось.');
            }
            $slots=$this->decodeMessageSlots((string)($attempt['message_slots_json']??''));
            $parts=$this->decodeJson((string)($attempt['pending_parts_json']??''));
            while(count($slots)<=$slot)$slots[]=0;

            $responseId=(int)($message['message_id']??0);
            $storedPart=is_array($parts[$slot]??null)?$parts[$slot]:[];
            if($responseId>0){
                $resolved=$this->telegramPendingPartFromMessage(
                    $message,
                    $slot,
                    (string)$post['chat_id']
                );
                $resolved['message_id']=$responseId;
                $resolved['published_at']=max(1,(int)($message['date']??time()));
                $resolved['matched_at']=date('Y-m-d H:i:s');
                $resolved['api_confirmed']=true;
                $parts[$slot]=$resolved;
                $slots[$slot]=$responseId;
            }elseif((int)($storedPart['message_id']??0)<=0){
                $message['message_id']=0;
                $parts[$slot]=$this->mergeTelegramPendingPart(
                    $storedPart,
                    $this->telegramPendingPartFromMessage(
                        $message,
                        $slot,
                        (string)$post['chat_id']
                    )
                );
                $slots[$slot]=0;
            }

            $complete=$parts!==[];
            foreach($parts as $part){
                if(!is_array($part)||(int)($part['message_id']??0)<=0){
                    $complete=false;
                    break;
                }
            }
            $nextState=$currentState==='cancel_requested'
                ? 'cancel_requested'
                : ($complete?'finalizing':'awaiting_telegram');
            $updateAttempt=$this->pdo->prepare(
                'UPDATE posted_telegram_publish_attempts
                 SET state=:state,message_slots_json=:slots,pending_parts_json=:parts,
                      completed_at=IF(:complete_state=1,COALESCE(completed_at,NOW()),NULL),
                      deadline_at=IF(:complete_deadline=1,deadline_at,NOW()+INTERVAL 24 HOUR)
                  WHERE post_id=:post
                    AND state IN (\'continuation_sending\',\'cancel_requested\')'
            );
            $updateAttempt->execute([
                'state'=>$nextState,
                'slots'=>$this->encodeMessageSlots($slots),
                'parts'=>$this->encodeJson($parts),
                'complete_state'=>$complete?1:0,
                'complete_deadline'=>$complete?1:0,
                'post'=>$postId,
            ]);
            if($updateAttempt->rowCount()!==1){
                throw new RuntimeException('Состояние продолжения Telegram уже изменилось.');
            }
            $positive=array_values(array_filter(
                array_map('intval',$slots),
                static fn(int $id):bool=>$id>0
            ));
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET telegram_message_id=COALESCE(telegram_message_id,:first),
                     telegram_message_ids=:slots,
                     error_text=IF(:complete=1,NULL,'Telegram обрабатывает продолжение публикации. Повтор отключён.')
                 WHERE id=:post AND status='telegram_pending'"
            )->execute([
                'first'=>$positive[0]??null,
                'slots'=>$this->encodeMessageSlots($slots),
                'complete'=>$complete?1:0,
                'post'=>$postId,
            ]);
            $this->pdo->commit();

            if($nextState==='cancel_requested'&&$complete){
                $this->finalizeTelegramPublishAttempt($postId);
            }
            return ['state'=>$nextState,'slots'=>$slots,'parts'=>$parts];
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
    }

    private function finishTelegramContinuationAsPartial(
        int $postId,
        array $post,
        array $slots,
        int $publishedTimestamp,
        Throwable $reason
    ):void {
        $ids=array_values(array_unique(array_filter(
            array_map('intval',$slots),
            static fn(int $id):bool=>$id>0
        )));
        if($ids===[])throw $reason;
        $published=date('Y-m-d H:i:s',$publishedTimestamp);
        $minutes=$this->normalizeTelegramDeleteTimerMinutes((int)($post['delete_after_minutes']??0));
        $deleteAt=$minutes>0
            ?date('Y-m-d H:i:s',$publishedTimestamp+$minutes*60)
            :null;

        $this->pdo->beginTransaction();
        try{
            $attemptStmt=$this->pdo->prepare(
                'SELECT state,pending_parts_json
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post
                 LIMIT 1
                 FOR UPDATE'
            );
            $attemptStmt->execute(['post'=>$postId]);
            $attempt=$attemptStmt->fetch();
            if(!is_array($attempt)){
                throw new RuntimeException('Попытка продолжения Telegram не найдена.');
            }
            if((string)$attempt['state']==='cancel_requested'){
                $resolvedParts=array_values(array_filter(
                    $this->decodeJson((string)($attempt['pending_parts_json']??'')),
                    static fn(mixed $part):bool=>is_array($part)&&(int)($part['message_id']??0)>0
                ));
                $this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET message_slots_json=:slots,pending_parts_json=:parts,
                         continuation_json='[]',completed_at=NOW()
                     WHERE post_id=:post AND state='cancel_requested'"
                )->execute([
                    'slots'=>$this->encodeMessageSlots($ids),
                    'parts'=>$this->encodeJson($resolvedParts),
                    'post'=>$postId,
                ]);
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET telegram_message_id=:first,telegram_message_ids=:ids
                     WHERE id=:post AND status='telegram_pending'"
                )->execute([
                    'first'=>$ids[0],
                    'ids'=>$this->encodeMessageSlots($ids),
                    'post'=>$postId,
                ]);
                $this->pdo->commit();
                $this->finalizeTelegramPublishAttempt($postId);
                return;
            }
            if((string)$attempt['state']!=='continuation_sending'
                && !($reason instanceof PostedPublishAuthorizationException && (string)$attempt['state']==='finalizing')){
                throw new RuntimeException('Состояние продолжения Telegram уже изменилось.');
            }
            $stmt=$this->pdo->prepare(
                "UPDATE posted_posts
                 SET status='sent',telegram_message_id=:first,telegram_message_ids=:ids,
                     publish_started_at=NULL,published_at=:published,delete_at=:delete_at,
                     manual_delete_required=0,error_text=:error
                 WHERE id=:post AND status='telegram_pending'"
            );
            $stmt->execute([
                'first'=>$ids[0],
                'ids'=>$this->encodeMessageSlots($ids),
                'published'=>$published,
                'delete_at'=>$deleteAt,
                'error'=>'Основная публикация вышла, но продолжение не отправлено: '.mb_substr($reason->getMessage(),0,3400),
                'post'=>$postId,
            ]);
            if($stmt->rowCount()!==1){
                throw new RuntimeException('Не удалось зафиксировать частичную публикацию.');
            }
            $this->completeTelegramAttemptRow($postId,true);
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
        $this->notifyDelayedTelegramResult(
            (int)($post['author_id']??0),
            'Основная публикация вышла, но дополнительный текст или кнопки Telegram отклонил. Повтор не выполнялся.'
        );
    }

    private function completeTelegramAttemptRow(int $postId,bool $skipPin=false):void
    {
        PostedTelegramTopHold::finalizeKnownLocked($this->pdo,$postId);
        if($skipPin){
            $this->refundManagerQuotaForPost($postId,false,true);
        }
        $this->pdo->prepare(
             "UPDATE posted_telegram_publish_attempts
              SET state='completed',gate_channel_id=NULL,submission_key=NULL,
                  finalized_at=NOW(),completed_at=COALESCE(completed_at,NOW()),
                  pin_started_at=NULL,
                  pin_completed_at=IF(:skip_pin=1,NOW(),pin_completed_at)
              WHERE post_id=:post"
        )->execute(['skip_pin'=>$skipPin?1:0,'post'=>$postId]);
    }

    private function completeTelegramPinEffect(int $postId,array $post,array $messageIds):void
    {
        if(empty($post['pin_after_publish'])||$messageIds===[]){
            $this->cancelTelegramPinEffect($postId);
            return;
        }
        $channelId=(int)($post['channel_id']??0);
        if($channelId<=0){
            $this->cancelTelegramPinEffect($postId);
            return;
        }
        $lockName='gitposted_pin_'.substr(hash('sha256',(string)$channelId),0,32);
        $lock=$this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $lock->execute(['lock_name'=>$lockName]);
        if((int)($lock->fetchColumn()?:0)!==1){
            return;
        }
        $remotePinAttempted=false;
        try{
            PostedTelegramRootedAccess::begin($this->pdo,$this->config);
            $channelLock=$this->pdo->prepare(
                'SELECT *
                 FROM posted_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelLock->execute(['channel'=>$channelId]);
            $lockedChannel=$channelLock->fetch();
            $postLock=$this->pdo->prepare(
                'SELECT *
                 FROM posted_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postLock->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedPost=$postLock->fetch();
            $attemptLock=$this->pdo->prepare(
                'SELECT state,pin_completed_at,pin_started_at,
                        pin_started_at>NOW()-INTERVAL 15 MINUTE AS pin_is_fresh
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $attemptLock->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedAttempt=$attemptLock->fetch();
            if(
                !is_array($lockedAttempt)
                ||(string)($lockedAttempt['state']??'')!=='completed'
                ||!empty($lockedAttempt['pin_completed_at'])
            ){
                PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            if(!empty($lockedAttempt['pin_started_at'])){
                if(!empty($lockedAttempt['pin_is_fresh'])){
                    PostedTelegramRootedAccess::commit($this->pdo);
                    return;
                }
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET error_text=CASE
                        WHEN status='sent' THEN 'Результат закрепления неизвестен после прерванной операции; лимит закрепления сохранён как использованный.'
                        ELSE error_text
                     END
                     WHERE id=:post"
                )->execute(['post'=>$postId]);
                $this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET pin_started_at=NULL,pin_delivery_uncertain=1,
                         pin_completed_at=COALESCE(pin_completed_at,NOW())
                     WHERE post_id=:post AND state='completed'
                       AND pin_completed_at IS NULL
                       AND pin_started_at IS NOT NULL"
                )->execute(['post'=>$postId]);
                PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            if(is_array($lockedChannel)&&PostedTelegramAuthorityPause::blocked($this->pdo,$lockedChannel,true)){
                PostedTelegramRootedAccess::commit($this->pdo);return;
            }
            if(
                !is_array($lockedChannel)
                ||empty($lockedChannel['is_active'])
                ||(int)($lockedChannel['added_by']??0)<=0
                ||!is_array($lockedPost)
                ||(string)($lockedPost['status']??'')!=='sent'
            ){
                $this->cancelTelegramPinEffect($postId);
                PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            $authorityFailure=$this->telegramPostAuthorityFailureLocked($lockedPost,$lockedChannel);
            if ($authorityFailure!==null || empty($lockedPost['pin_after_publish'])) {
                if ($authorityFailure!==null) {
                    $this->pdo->prepare("UPDATE posted_posts SET error_text=:error WHERE id=:post AND status='sent'")
                        ->execute(['error'=>'Пост опубликован, но закреп отменён: '.$authorityFailure,'post'=>$postId]);
                }
                $this->cancelTelegramPinEffect($postId);
                PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            $post=array_replace($post,$lockedPost,['chat_id'=>(string)$lockedChannel['chat_id']]);
            $this->pdo->prepare(
                'UPDATE posted_telegram_publish_attempts
                 SET pin_started_at=NOW()
                 WHERE post_id=:post AND state=\'completed\'
                   AND pin_completed_at IS NULL'
            )->execute(['post'=>$postId]);
            PostedTelegramRootedAccess::commit($this->pdo);
            $newer=$this->pdo->prepare(
                "SELECT 1
                 FROM posted_telegram_publish_attempts attempt
                 INNER JOIN posted_posts newer ON newer.id=attempt.post_id
                 WHERE attempt.state='completed'
                   AND newer.status='sent'
                   AND newer.pin_after_publish=1
                   AND newer.channel_id=:channel
                   AND (
                        COALESCE(newer.published_at,newer.created_at)
                            > COALESCE(:published,:created)
                        OR (
                            COALESCE(newer.published_at,newer.created_at)
                                = COALESCE(:published_same,:created_same)
                            AND newer.id>:post
                        )
                   )
                 LIMIT 1"
            );
            $newer->execute([
                'channel'=>$channelId,
                'published'=>$post['published_at']??null,
                'created'=>$post['created_at']??null,
                'published_same'=>$post['published_at']??null,
                'created_same'=>$post['created_at']??null,
                'post'=>$postId,
            ]);
            if($newer->fetchColumn()!==false){
                $this->deferTelegramPinEffect($postId);
                $this->cancelTelegramPinEffect($postId);
                return;
            }
            try{
                $remotePinAttempted=true;
                $this->pinPostIfNeeded($post,$messageIds);
            }catch(Throwable $pinError){
                $this->recordTelegramPinFailure($postId,$pinError);
                return;
            }
            $this->markTelegramPinEffectCompleted($postId);
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);
            if(!$remotePinAttempted)$this->deferTelegramPinEffect($postId);
            throw $exception;
        }finally{
            $release=$this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name'=>$lockName]);
        }
    }

    private function markTelegramPinEffectCompleted(int $postId):void
    {
        $this->pdo->prepare(
            "UPDATE posted_telegram_publish_attempts
             SET pin_started_at=NULL,
                 pin_completed_at=COALESCE(pin_completed_at,NOW())
             WHERE post_id=:post AND state='completed'"
        )->execute(['post'=>$postId]);
    }

    private function cancelTelegramPinEffect(int $postId):bool
    {
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $attempt=$this->pdo->prepare(
                "SELECT state,pin_completed_at,pin_started_at,pin_delivery_uncertain,
                        pin_started_at>NOW()-INTERVAL 15 MINUTE AS pin_is_fresh
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post
                 LIMIT 1
                 FOR UPDATE"
            );
            $attempt->execute(['post'=>$postId]);
            $lockedAttempt=$attempt->fetch();
            if(
                is_array($lockedAttempt)
                &&(string)($lockedAttempt['state']??'')==='completed'
                &&empty($lockedAttempt['pin_completed_at'])
            ){
                if(!empty($lockedAttempt['pin_started_at'])){
                    if(!empty($lockedAttempt['pin_is_fresh'])){
                        if($ownsTransaction)$this->pdo->commit();
                        return false;
                    }
                    $this->pdo->prepare(
                        "UPDATE posted_posts
                         SET error_text=CASE
                            WHEN status='sent' THEN 'Результат закрепления неизвестен после прерванной операции; лимит закрепления сохранён как использованный.'
                            ELSE error_text
                         END
                         WHERE id=:post"
                    )->execute(['post'=>$postId]);
                    $this->pdo->prepare(
                        "UPDATE posted_telegram_publish_attempts
                         SET pin_started_at=NULL,pin_delivery_uncertain=1,
                             pin_completed_at=NOW()
                         WHERE post_id=:post AND state='completed'
                           AND pin_completed_at IS NULL"
                    )->execute(['post'=>$postId]);
                    if($ownsTransaction)$this->pdo->commit();
                    return true;
                }
                if (empty($lockedAttempt['pin_delivery_uncertain'])) {
                    $this->refundManagerQuotaForPost($postId,false,true);
                }
                $this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET pin_started_at=NULL,pin_completed_at=NOW()
                     WHERE post_id=:post AND state='completed'
                       AND pin_completed_at IS NULL"
                )->execute(['post'=>$postId]);
            }
            if($ownsTransaction)$this->pdo->commit();
            return true;
        }catch(Throwable $exception){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
    }

    private function recordTelegramPinFailure(int $postId,Throwable $reason):void
    {
        $authorId=0;
        $terminal=false;
        $refunded=false;
        $deliveryUnknown=!($reason instanceof PostedTelegramRequestException)
            ||$reason->deliveryMayBeUnknown();
        $this->pdo->beginTransaction();
        try{
            $attempt=$this->pdo->prepare(
                "SELECT pin_attempts,pin_delivery_uncertain,pin_completed_at
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post AND state='completed'
                 LIMIT 1
                 FOR UPDATE"
            );
            $attempt->execute(['post'=>$postId]);
            $lockedAttempt=$attempt->fetch();
            if(!is_array($lockedAttempt)||!empty($lockedAttempt['pin_completed_at'])){
                $this->pdo->commit();
                return;
            }
            $attempts=min(65535,(int)($lockedAttempt['pin_attempts']??0)+1);
            $deliveryUncertain=$deliveryUnknown
                ||!empty($lockedAttempt['pin_delivery_uncertain']);
            $terminal=$attempts>=3;
            if($terminal){
                $post=$this->pdo->prepare(
                    'SELECT author_id FROM posted_posts WHERE id=:post LIMIT 1 FOR UPDATE'
                );
                $post->execute(['post'=>$postId]);
                $authorId=(int)($post->fetchColumn()?:0);
                if(!$deliveryUncertain){
                    $this->refundManagerQuotaForPost($postId,false,true);
                    $refunded=true;
                }
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET error_text=:error
                     WHERE id=:post AND status='sent'"
                )->execute([
                    'error'=>($deliveryUncertain
                        ?'Пост опубликован, но результат закрепления после 3 попыток неизвестен; лимит сохранён как использованный: '
                        :'Пост опубликован, но Telegram не закрепил его после 3 подтверждённых отказов: ')
                        .mb_substr($reason->getMessage(),0,3300),
                    'post'=>$postId,
                ]);
            }
            $this->pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET pin_attempts=:attempts,pin_started_at=NULL,
                     pin_delivery_uncertain=:delivery_uncertain,
                     pin_completed_at=IF(:terminal=1,NOW(),pin_completed_at),
                     updated_at=NOW()
                 WHERE post_id=:post AND state='completed'
                   AND pin_completed_at IS NULL"
            )->execute([
                'attempts'=>$attempts,
                'delivery_uncertain'=>$deliveryUncertain?1:0,
                'terminal'=>$terminal?1:0,
                'post'=>$postId,
            ]);
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
        error_log(
            'GitPosted pin attempt failed for post '.$postId.': '.$reason->getMessage()
        );
        if($terminal){
            $this->notifyDelayedTelegramResult(
                $authorId,
                $refunded
                    ?'Пост #'.$postId.' опубликован, но Telegram не смог закрепить его после 3 подтверждённых отказов. Лимит закрепления возвращён.'
                    :'Пост #'.$postId.' опубликован, но Telegram не подтвердил результат закрепления после 3 попыток. Проверьте канал вручную; лимит не возвращён, чтобы исключить двойное использование.'
            );
        }
    }

    private function deferTelegramPinEffect(int $postId):void
    {
        $this->pdo->prepare(
            "UPDATE posted_telegram_publish_attempts
             SET pin_started_at=NULL,updated_at=NOW()
             WHERE post_id=:post
               AND state='completed'
               AND pin_completed_at IS NULL"
        )->execute(['post'=>$postId]);
    }

    private function notifyDelayedTelegramResult(int $userId,string $text):void
    {
        if($userId<=0)return;
        $stmt=$this->pdo->prepare(
            'SELECT setting_delay_alerts FROM posted_users WHERE telegram_id=:user LIMIT 1'
        );
        $stmt->execute(['user'=>$userId]);
        if((int)($stmt->fetchColumn()?:0)!==1)return;
        try{$this->telegram->sendMessage($userId,$text);}catch(Throwable){}
    }

    private function shouldNotifyDelayedTelegramCompletion(array $parts): bool
    {
        foreach($parts as $part){
            if(
                is_array($part)
                &&empty($part['api_confirmed'])
                &&!empty($part['channel_confirmed'])
            ){
                return true;
            }
        }
        return false;
    }

    private function telegramUtf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function telegramPrefixCharactersForUtf16Limit(string $text,int $limit):int
    {
        $characterCount=mb_strlen($text);
        if($characterCount===0||$limit<1)return 0;
        $low=0;
        $high=min($characterCount,$limit);
        while($low<$high){
            $middle=intdiv($low+$high+1,2);
            $prefix=mb_substr($text,0,$middle);
            if($this->telegramUtf16Length($prefix)<=$limit){
                $low=$middle;
            }else{
                $high=$middle-1;
            }
        }
        return $low;
    }

    private function normalizeTelegramEntities(string $text, array $entities): array
    {
        $textLength = $this->telegramUtf16Length($text);
        $allowedTypes = [
            'mention','hashtag','cashtag','bot_command','url','email','phone_number',
            'bold','italic','underline','strikethrough','spoiler','blockquote',
            'expandable_blockquote','code','pre','text_link','text_mention','custom_emoji',
        ];
        $normalized = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type = trim((string) ($entity['type'] ?? ''));
            $offset = (int) ($entity['offset'] ?? -1);
            $length = (int) ($entity['length'] ?? 0);
            if (
                !in_array($type, $allowedTypes, true)
                || $offset < 0
                || $length < 1
                || $offset + $length > $textLength
            ) {
                continue;
            }
            $safe = ['type'=>$type,'offset'=>$offset,'length'=>$length];
            if ($type === 'text_link') {
                $url = trim((string) ($entity['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $safe['url'] = $url;
            } elseif ($type === 'text_mention') {
                $user = $entity['user'] ?? null;
                if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
                    continue;
                }
                $safe['user'] = $user;
            } elseif ($type === 'pre' && isset($entity['language'])) {
                $safe['language'] = (string) $entity['language'];
            } elseif ($type === 'custom_emoji') {
                $customEmojiId = trim((string) ($entity['custom_emoji_id'] ?? ''));
                if ($customEmojiId === '') {
                    continue;
                }
                $safe['custom_emoji_id'] = $customEmojiId;
            }
            $normalized[] = $safe;
        }
        usort(
            $normalized,
            static fn(array $left,array $right):int =>
                [$left['offset'],-$left['length'],$left['type']]
                <=> [$right['offset'],-$right['length'],$right['type']]
        );
        return $normalized;
    }

    private function shiftTelegramEntities(array $entities, int $utf16Offset): array
    {
        if ($utf16Offset === 0) {
            return $entities;
        }
        foreach ($entities as &$entity) {
            if (is_array($entity)) {
                $entity['offset'] = max(0, (int) ($entity['offset'] ?? 0) + $utf16Offset);
            }
        }
        unset($entity);
        return $entities;
    }

    private function telegramEntitiesForRange(
        array $entities,
        int $rangeOffset,
        int $rangeLength
    ): array {
        $rangeEnd = $rangeOffset + max(0, $rangeLength);
        $result = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $entityStart = (int) ($entity['offset'] ?? 0);
            $entityEnd = $entityStart + (int) ($entity['length'] ?? 0);
            $start = max($rangeOffset, $entityStart);
            $end = min($rangeEnd, $entityEnd);
            if ($end <= $start) {
                continue;
            }
            $part = $entity;
            $part['offset'] = $start - $rangeOffset;
            $part['length'] = $end - $start;
            $result[] = $part;
        }
        return $result;
    }

    private function replaceTelegramUrls(
        string $text,
        array $entities,
        string $replacementUrl
    ): array {
        foreach ($entities as &$entity) {
            if (is_array($entity) && (string) ($entity['type'] ?? '') === 'text_link') {
                $entity['url'] = $replacementUrl;
            }
        }
        unset($entity);

        $matches = [];
        preg_match_all('~https?://[^\s<]+~u', $text, $matches, PREG_OFFSET_CAPTURE);
        $captured = (array) ($matches[0] ?? []);
        for ($index = count($captured) - 1; $index >= 0; $index--) {
            $match = $captured[$index] ?? null;
            if (!is_array($match)) {
                continue;
            }
            $visibleUrl = (string) ($match[0] ?? '');
            $byteOffset = (int) ($match[1] ?? -1);
            if ($visibleUrl === '' || $byteOffset < 0) {
                continue;
            }
            $oldStart = $this->telegramUtf16Length(substr($text, 0, $byteOffset));
            $oldLength = $this->telegramUtf16Length($visibleUrl);
            $oldEnd = $oldStart + $oldLength;
            $newLength = $this->telegramUtf16Length($replacementUrl);
            $delta = $newLength - $oldLength;
            foreach ($entities as $entityIndex=>$entity) {
                if (!is_array($entity)) {
                    unset($entities[$entityIndex]);
                    continue;
                }
                $entityStart = (int) ($entity['offset'] ?? 0);
                $entityEnd = $entityStart + (int) ($entity['length'] ?? 0);
                if ($entityEnd <= $oldStart) {
                    continue;
                }
                if ($entityStart >= $oldEnd) {
                    $entities[$entityIndex]['offset'] = $entityStart + $delta;
                    continue;
                }
                if ($entityStart <= $oldStart && $entityEnd >= $oldEnd) {
                    $entities[$entityIndex]['length'] =
                        (int) $entity['length'] + $delta;
                    continue;
                }
                if ($entityStart >= $oldStart && $entityEnd <= $oldEnd) {
                    $entities[$entityIndex]['offset'] = $oldStart;
                    $entities[$entityIndex]['length'] = $newLength;
                    continue;
                }
                unset($entities[$entityIndex]);
            }
            $text = substr_replace($text, $replacementUrl, $byteOffset, strlen($visibleUrl));
            $entities = array_values($entities);
        }
        return [$text,$this->normalizeTelegramEntities($text,$entities)];
    }

    private function telegramPostInlineButtonsAllowed(array $post): bool
    {
        // Native forwarding and paid-media delivery retain their existing paths.
        // Ordinary sendMediaGroup has no reply_markup parameter.
        return $this->isNativeRepost($post)
            || count($this->postMedia($post)) < 2
            || $this->telegramPostOptions($post)['paid_media_stars'] !== null;
    }

    private function telegramAlbumButtonAction(string $action): bool
    {
        return in_array($action, [
            'buttons','buttons_clear','reactions','hidden','hidden_add',
            'paintopen','paint','paintbtn','paintrow','paintcol','paintall',
        ], true);
    }

    private function handleTelegramAlbumButtonCallback(array $callback): bool
    {
        $raw=$callback['data']??null;
        if(!is_string($raw)||preg_match('/\A(draft|edit):([a-z_]+):([1-9][0-9]{0,18})(?::|\z)/D',$raw,$parts)!==1
            ||($parts[1]==='draft'?!$this->telegramAlbumButtonAction($parts[2]):$parts[2]!=='buttons'))return false;
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if(!is_int($actor)||$actor<=0||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor)return true;
        $post=$this->post((int)$parts[3]);
        if(!is_array($post)||!$this->canManagePost($actor,(int)$post['id']))return false;
        if($this->telegramPostInlineButtonsAllowed($post))return false;
        // This handles only obsolete/forged controls; the current editor never offers them.
        $this->answerCallback($callback,'Кнопки недоступны для альбомов.');
        return true;
    }

    private function handleTelegramAlbumButtonInput(int $actor,string $state,array $data): bool
    {
        if(!in_array($state,['await_buttons','await_reactions','await_hidden_label','await_hidden_text','await_edit_buttons'],true))return false;
        $post=$this->post((int)($data['post_id']??0));
        if(!is_array($post)||!$this->canManagePost($actor,(int)$post['id'])||$this->telegramPostInlineButtonsAllowed($post))return false;
        $extra=$state==='await_edit_buttons'?[]:[
            'reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'draft:main:'.(int)$post['id']]]]],
        ];
        $this->telegram->sendMessage($actor,'Кнопки недоступны для альбомов.',$extra);
        return true;
    }

    private function effectivePostPayload(array $post): array
    {
        $text = (string) ($post['text'] ?? '');
        $entities = $this->normalizeTelegramEntities(
            $text,
            $this->decodeJson((string) ($post['text_entities_json'] ?? ''))
        );
        $signature=(string)($post['autosignature_text']??'');
        if(trim($signature)!==''){
            $signatureEntities=$this->normalizeTelegramEntities(
                $signature,
                $this->decodeJson((string)($post['autosignature_entities_json']??''))
            );
            $separator=$text!==''?"\n\n":'';
            $signatureOffset=$this->telegramUtf16Length($text.$separator);
            $text.=$separator.$signature;
            $entities=array_merge(
                $entities,
                $this->shiftTelegramEntities($signatureEntities,$signatureOffset)
            );
            $entities=$this->normalizeTelegramEntities($text,$entities);
        }
        $rawButtons = $this->decodeJson((string) ($post['buttons_json'] ?? ''));
        $replacementUrl = trim((string) ($post['replacement_url'] ?? ''));
        if (filter_var($replacementUrl, FILTER_VALIDATE_URL)) {
            [$text,$entities]=$this->replaceTelegramUrls($text,$entities,$replacementUrl);
            foreach ($rawButtons as $ri=>$row) foreach ((array)$row as $bi=>$button) if(is_array($button)&&isset($button['url'])) $rawButtons[$ri][$bi]['url']=$replacementUrl;
        }
        return ['text'=>$text,'entities'=>$entities,'buttons'=>$rawButtons];
    }

    private function linkPreviewExtra(array $post): array
    {
        if(($post['link_preview_enabled']??null)!==null&&(int)$post['link_preview_enabled']===0){
            return ['link_preview_options'=>['is_disabled'=>true]];
        }
        $options=['is_disabled'=>false];
        if(($post['link_preview_large']??null)!==null){
            $options[(int)$post['link_preview_large']===1?'prefer_large_media':'prefer_small_media']=true;
        }
        if(($post['link_preview_above']??null)!==null)$options['show_above_text']=(int)$post['link_preview_above']===1;
        $custom = $this->telegramCustomPreviewExtra($post);
        if (isset($custom['link_preview_options']['url'])) {
            $options['url'] = $custom['link_preview_options']['url'];
        }
        return ['link_preview_options'=>$options];
    }

    private function validatePostForPublish(array $post):void
    {
        if(!empty($post['repost_chat_id'])&&!empty($post['repost_message_id']))return;
        if(PostedTelegramNativeContent::isKind($post['media_type']??null)){$post=PostedTelegramNativeStore::freeze($this->pdo,$post);if(PostedTelegramNativeStore::behavior($post)===[])throw new InvalidArgumentException('native_content_unavailable');$this->telegramNativeAssertOrdinaryPayload($post,$this->effectivePostPayload($post));return;}
        $payload=$this->effectivePostPayload($post);
        $media=$this->postMedia($post);
        if(trim((string)($payload['text']??''))===''&&$media===[]){
            throw new InvalidArgumentException('Нельзя опубликовать пустой пост.');
        }
        if(count($media)>1&&!$this->isSupportedTelegramMediaGroup($media)){
            throw new InvalidArgumentException(
                'Альбом Telegram может содержать фото и видео вместе либо только документы, либо только аудио.'
            );
        }
    }

    private function isSupportedTelegramMediaGroup(array $media): bool
    {
        if(count($media)<2)return true;
        $types=array_values(array_unique(array_map(
            static fn(array $item):string=>(string)($item['type']??''),
            $media
        )));
        if(array_diff($types,['photo','video'])===[])return true;
        return count($types)===1&&in_array($types[0],['document','audio'],true);
    }

    private function repostForwardSource(array $post): ?array
    {
        if (empty($post['repost_chat_id']) || empty($post['repost_message_id'])) {
            return null;
        }

        $chatId = trim((string) ($post['repost_input_chat_id'] ?? ''));
        $messageId = (int) ($post['repost_input_message_id'] ?? 0);
        if ($chatId === '' || $messageId <= 0) {
            $chatId = trim((string) $post['repost_chat_id']);
            $messageId = (int) $post['repost_message_id'];
        }

        return $chatId !== '' && $messageId > 0
            ? ['chat_id' => $chatId, 'message_id' => $messageId]
            : null;
    }

    private function isNativeRepost(array $post): bool
    {
        return $this->repostForwardSource($post) !== null;
    }

    private function isNativeRepostDraftActionSupported(string $action): bool
    {
        return in_array($action, [
            'convert_copy',
            'more', // Presentation-only expansion; repost mutation restrictions are unchanged.
            'send',
            'send_confirm',
            'silent',
            'pin',
            'next',
            'schedule',
            'scheduledate',
            'scheduletimes',
            'scheduletime',
            'schedule_confirm',
            'schedule_noconfirm',
            'scheduleslot',
            'delete_timer',
            'delete_custom',
            'delete_report',
            'setdelete',
            'repeat_timer',
            'setrepeat',
            'cancel_timer',
            'cancel',
        ], true);
    }

    private function hasStoredRepostCopy(array $post): bool
    {
        return trim((string) ($post['text'] ?? '')) !== '' || $this->postMedia($post) !== [];
    }

    private function publishPost(array $post): array
    {
        $post=PostedTelegramPostOptions::freeze($this->pdo,$post);
        $post=PostedTelegramTransportLayout::freeze($this->pdo,$post);
        $post=PostedTelegramCaptionPlacement::freeze($this->pdo,$post);
        $post=PostedTelegramPublicButtonLayout::freeze($this->pdo,$post,$this->effectivePostPayload($post));
        $channelId = (int) ($post['channel_id'] ?? 0);
        $channelStatement = $this->pdo->prepare(
            'SELECT chat_id,added_by,is_active,bot_can_post
             FROM posted_channels
             WHERE id=:channel
             LIMIT 1'
        );
        $channelStatement->execute(['channel' => $channelId]);
        $channel = $channelStatement->fetch();
        if (
            !is_array($channel)
            || empty($channel['is_active'])
            || (int) ($channel['added_by'] ?? 0) <= 0
            || empty($channel['bot_can_post'])
        ) {
            throw new RuntimeException(
                'Канал отключён, не имеет владельца или у бота нет права публикации.'
            );
        }
        $payload=$this->effectivePostPayload($post);
        $text=(string)$payload['text'];
        PostedTelegramCaptionPlacement::assertCaption($post,$text);
        $entities=(array)($payload['entities']??[]);
        $buttons = $this->telegramPublicButtonsMarkup($post,(array)$payload['buttons']);
        $chatId = (string) $channel['chat_id'];
        $messageSlots = [];
        $pendingParts = [];
        $continuation = [];
        $buttonExtra = $buttons !== [] ? ['reply_markup'=>['inline_keyboard'=>$buttons]] : [];
        $sendExtra = $this->telegramDeliveryExtra($post);
        $multipostForward=$this->telegramMultipostPublishForward($post);
        if($multipostForward!==null)return $multipostForward;
        $this->telegramValidateMediaOptions($post);
        $replyExtra=[];
        if (!empty($post['reply_message_id']) && (string) ($post['reply_chat_id'] ?? '') === $chatId) {
            $replyExtra['reply_parameters']=['message_id'=>(int)$post['reply_message_id']];
        }
        $repostSource = $this->repostForwardSource($post);
        if ($repostSource !== null) {
            $result = $this->forwardTelegramRepost(
                $post,
                $chatId,
                (int) ($post['author_id'] ?? 0),
                'publish',
                $sendExtra
            );
            $this->appendTelegramSendResult($post,$result,$messageSlots,$pendingParts);
            return $this->telegramPublishOutcome($messageSlots,$pendingParts,$continuation);
        }
        $native=$this->telegramNativePublish($post,array_merge($sendExtra,$replyExtra,$buttonExtra));if($native!==null)return$native;
        $media = $this->postMedia($post);
        if($text===''&&$media===[]){
            throw new RuntimeException('Нельзя опубликовать пустой пост.');
        }

        $paid=$this->telegramPaidMediaPayload($post,$chatId,$text,$entities,array_merge($sendExtra,$replyExtra,
            $this->telegramUtf16Length($text)<=self::PHOTO_CAPTION_LIMIT?$buttonExtra:[]));
        if($paid!==null){
            $result=$this->telegram->call('sendPaidMedia',$paid);
            $this->appendTelegramSendResult($post,$result,$messageSlots,$pendingParts);
            if($this->telegramUtf16Length($text)>self::PHOTO_CAPTION_LIMIT)$continuation=$this->telegramTextContinuation(
                $this->splitTelegramFormattedText($text,$entities),array_merge($sendExtra,$this->linkPreviewExtra($post)),$buttonExtra);
            return $this->telegramPublishOutcome($messageSlots,$pendingParts,$continuation);
        }
        $spoiler=$this->telegramPostOptions($post)['media_spoiler'];

        if (count($media) > 1) {
            $buttons=[];$buttonExtra=[]; // Ordinary Telegram albums cannot carry inline keyboards.
            if(!$this->isSupportedTelegramMediaGroup($media)){
                throw new RuntimeException(
                    'Альбом Telegram может содержать фото и видео вместе либо только документы, либо только аудио.'
                );
            }
            $caption = PostedTelegramTransportLayout::length($post,$text) <= self::PHOTO_CAPTION_LIMIT ? $text : '';
            $group = [];
            foreach ($media as $index => $item) {
                $entry = ['type' => (string) $item['type'], 'media' => (string) $item['file_id']];
                if($spoiler&&in_array($item['type'],['photo','video'],true))$entry['has_spoiler']=true;
                if ($index === 0 && $caption !== '') {
                    $entry['caption'] = $caption;
                    if($entities!==[])$entry['caption_entities']=$entities;
                }
                $group[] = PostedTelegramCaptionVector::project($post,$index,$entry);
            }
            $result = $this->telegram->sendMediaGroup($chatId, $group, array_merge($sendExtra, $replyExtra));
            $this->appendTelegramSendResult($post,$result,$messageSlots,$pendingParts);
            if ($caption === '' && $text !== '') {
                $continuation=$this->telegramTextContinuation(
                    $this->splitTelegramFormattedTextForLayout($post,$text,$entities),
                    array_merge($sendExtra,$this->linkPreviewExtra($post)),
                    $buttonExtra
                );
            }
            if($pendingParts===[]){
                $continuation=$this->sendTelegramTextContinuation(
                    $post,$continuation,$messageSlots,$pendingParts
                );
            }
            return $this->telegramPublishOutcome($messageSlots,$pendingParts,$continuation);
        }

        if ($media !== []) {
            $mediaType = (string) $media[0]['type'];
            $fileId = (string) $media[0]['file_id'];
            if ($text === '' || PostedTelegramTransportLayout::length($post,$text) <= self::PHOTO_CAPTION_LIMIT) {
                $mediaExtra=PostedTelegramCaptionPlacement::project($post,array_merge($sendExtra,$replyExtra,$buttonExtra));
                if($spoiler&&in_array($mediaType,['photo','video','animation'],true))$mediaExtra['has_spoiler']=true;
                if($entities!==[])$mediaExtra['caption_entities']=$entities;
                $result = $this->sendMedia($chatId,$mediaType,$fileId,$text,$mediaExtra);
                $this->appendTelegramSendResult($post,$result,$messageSlots,$pendingParts);
                return $this->telegramPublishOutcome($messageSlots,$pendingParts,$continuation);
            }

            $mediaOnlyExtra=array_merge($sendExtra,$replyExtra);
            if($spoiler&&in_array($mediaType,['photo','video','animation'],true))$mediaOnlyExtra['has_spoiler']=true;
            $mediaResult = $this->sendMedia($chatId,$mediaType,$fileId,'',$mediaOnlyExtra);
            $this->appendTelegramSendResult($post,$mediaResult,$messageSlots,$pendingParts);
            $continuation=$this->telegramTextContinuation(
                $this->splitTelegramFormattedTextForLayout($post,$text,$entities),
                array_merge($sendExtra,$this->linkPreviewExtra($post)),
                $buttonExtra
            );
            if($pendingParts===[]){
                $continuation=$this->sendTelegramTextContinuation(
                    $post,$continuation,$messageSlots,$pendingParts
                );
            }
            return $this->telegramPublishOutcome($messageSlots,$pendingParts,$continuation);
        }

        $continuation=$this->telegramTextContinuation(
            $this->splitTelegramFormattedTextForLayout($post,$text,$entities),
            array_merge($sendExtra,$this->linkPreviewExtra($post)),
            $buttonExtra,
            $replyExtra
        );
        $continuation=$this->sendTelegramTextContinuation(
            $post,$continuation,$messageSlots,$pendingParts
        );
        return $this->telegramPublishOutcome($messageSlots,$pendingParts,$continuation);
    }

    private function appendTelegramSendResult(
        array $post,
        array $result,
        array &$messageSlots,
        array &$pendingParts
    ):void {
        $payload=$result['result']??null;
        $messages=is_array($payload)&&array_is_list($payload)?$payload:[$payload];
        $appended=false;
        foreach($messages as $message){
            if(!is_array($message)||!array_key_exists('message_id',$message))continue;
            $messageId=(int)$message['message_id'];
            if($messageId<0)throw new RuntimeException('Telegram вернул некорректный message_id.');
            $slot=count($messageSlots);
            $messageSlots[]=$messageId;
            $appended=true;
            $part=$this->telegramPendingPartFromMessage(
                $message,
                $slot,
                (string)$post['chat_id']
            );
            if($messageId>0){
                $part['message_id']=$messageId;
                $part['published_at']=max(1,(int)($message['date']??time()));
                $part['matched_at']=date('Y-m-d H:i:s');
                $part['api_confirmed']=true;
            }
            if(PostedTelegramCaptionPlacement::enabled($post)){
                $expected=$this->expectedTelegramPendingParts($post)[$slot]??[];
                if(!PostedTelegramCaptionPlacement::matches($expected,$part)){
                    $part['observed_message_id']=$messageId>0?$messageId:null;$part['caption_above']=true;
                    $part['message_id']=null;$part['published_at']=null;$part['api_confirmed']=false;unset($part['matched_at']);
                    $messageSlots[$slot]=0;
                }
            }
            $pendingParts[]=$part;
        }
        if(!$appended){
            throw new PostedTelegramRequestException(
                'Telegram подтвердил запрос без данных опубликованного сообщения.',
                true
            );
        }
        if($pendingParts===[]){
            try{
                $this->recordPublishingSlots((int)$post['id'],$messageSlots);
            }catch(Throwable $exception){
                throw new PostedTelegramAcceptedButNotPersistedException($exception);
            }
            return;
        }
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare(
                'SELECT state,message_slots_json,pending_parts_json
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['post'=>(int)$post['id']]);
            $stored=$stmt->fetch();
            if(!is_array($stored)){
                throw new RuntimeException('Попытка публикации Telegram не найдена.');
            }
            if(!in_array((string)$stored['state'],['sending','cancel_requested'],true)){
                throw new RuntimeException('Состояние публикации Telegram уже изменилось.');
            }
            foreach($this->decodeMessageSlots((string)($stored['message_slots_json']??'')) as $index=>$storedId){
                while(count($messageSlots)<=$index)$messageSlots[]=0;
                if($storedId>0)$messageSlots[$index]=$storedId;
            }
            foreach($this->decodeJson((string)($stored['pending_parts_json']??'')) as $storedPart){
                if(!is_array($storedPart))continue;
                $storedSlot=(int)($storedPart['slot']??-1);
                $matched=false;
                foreach($pendingParts as &$pendingPart){
                    if(!is_array($pendingPart))continue;
                    if((int)($pendingPart['slot']??-1)===$storedSlot){
                        $pendingPart=(int)($storedPart['message_id']??0)>0
                            ?$this->mergeTelegramPendingPart($pendingPart,$storedPart)
                            :$this->mergeTelegramPendingPart($storedPart,$pendingPart);
                        $matched=true;
                        break;
                    }
                }
                unset($pendingPart);
                if(!$matched)$pendingParts[]=$storedPart;
            }
            usort(
                $pendingParts,
                static fn(mixed $left,mixed $right):int=>
                    (int)(is_array($left)?($left['slot']??0):0)
                    <=>(int)(is_array($right)?($right['slot']??0):0)
            );
            $encodedSlots=$this->encodeMessageSlots($messageSlots);
            $positive=array_values(array_filter(
                array_map('intval',$messageSlots),
                static fn(int $id):bool=>$id>0
            ));
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET telegram_message_id=COALESCE(telegram_message_id,:first),
                     telegram_message_ids=:slots
                 WHERE id=:post
                   AND status IN ('publishing','telegram_pending')"
            )->execute([
                'first'=>$positive[0]??null,
                'slots'=>$encodedSlots,
                'post'=>(int)$post['id'],
            ]);
            $saveAttempt=$this->pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET message_slots_json=:slots,pending_parts_json=:parts,
                      accepted_at=COALESCE(accepted_at,NOW()),
                      deadline_at=COALESCE(deadline_at,NOW()+INTERVAL 24 HOUR)
                 WHERE post_id=:post
                   AND state IN ('sending','cancel_requested')"
            );
            $saveAttempt->execute([
                'slots'=>$encodedSlots,
                'parts'=>$this->encodeJson($pendingParts),
                'post'=>(int)$post['id'],
            ]);
            if($saveAttempt->rowCount()!==1){
                throw new RuntimeException('Не удалось зафиксировать ответ Telegram.');
            }
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($exception instanceof PostedTelegramAcceptedButNotPersistedException)throw $exception;
            throw new PostedTelegramAcceptedButNotPersistedException($exception);
        }
    }

    private function telegramPublishOutcome(
        array $messageSlots,
        array $pendingParts,
        array $continuation
    ):array {
        return [
            'message_ids'=>array_values(array_filter(
                array_map('intval',$messageSlots),
                static fn(int $id):bool=>$id>0
            )),
            'message_slots'=>array_values(array_map('intval',$messageSlots)),
            'pending_parts'=>array_values($pendingParts),
            'continuation'=>array_values($continuation),
        ];
    }

    private function telegramTextContinuation(
        array $parts,
        array $baseExtra,
        array $lastExtra=[],
        array $firstExtra=[]
    ):array {
        $items=[];
        $count=count($parts);
        foreach($parts as $index=>$part){
            $extra=$baseExtra;
            if($index===0)$extra=array_merge($extra,$firstExtra);
            if($index===$count-1)$extra=array_merge($extra,$lastExtra);
            $partText=is_array($part)?(string)($part['text']??''):(string)$part;
            $partEntities=is_array($part)?(array)($part['entities']??[]):[];
            if($partEntities!==[])$extra['entities']=$partEntities;
            $items[]=['text'=>$partText,'extra'=>$extra];
        }
        return $items;
    }

    private function sendTelegramTextContinuation(
        array $post,
        array $items,
        array &$messageSlots,
        array &$pendingParts
    ):array {
        foreach($items as $index=>$item){
            try{
                $result=$this->telegram->sendMessage(
                    (string)$post['chat_id'],
                    (string)($item['text']??''),
                    (array)($item['extra']??[])
                );
            }catch(PostedTelegramRequestException $exception){
                if(!$exception->deliveryMayBeUnknown())throw $exception;
                $result=['result'=>[
                    'message_id'=>0,
                    'chat'=>['id'=>(string)$post['chat_id']],
                    'text'=>(string)($item['text']??''),
                ]];
            }
            $before=count($pendingParts);
            try{
                $this->appendTelegramSendResult($post,$result,$messageSlots,$pendingParts);
            }catch(PostedTelegramRequestException $exception){
                if(!$exception->deliveryMayBeUnknown())throw $exception;
                $this->appendTelegramSendResult($post,['result'=>[
                    'message_id'=>0,
                    'chat'=>['id'=>(string)$post['chat_id']],
                    'text'=>(string)($item['text']??''),
                    'entities'=>(array)(($item['extra']??[])['entities']??[]),
                ]],$messageSlots,$pendingParts);
            }
            if(count($pendingParts)>$before){
                return array_values(array_slice($items,$index+1));
            }
        }
        return [];
    }

    private function telegramPendingPartFromMessage(
        array $message,
        int $slot,
        string $fallbackChatId=''
    ):array {
        [$mediaType,$mediaIdentity,$identityIsStable,$mediaAliases]
            =$this->telegramMessageMediaIdentity($message);
        $chatId=trim((string)(($message['chat']??[])['id']??$fallbackChatId));
        $hasCaption=array_key_exists('caption',$message);
        $text=str_replace(
            "\r\n",
            "\n",
            (string)($hasCaption?($message['caption']??''):($message['text']??''))
        );
        $matchText=$mediaType==='forward'?'':$text;
        $matchEntities=$mediaType==='forward'
            ?[]
            :$this->normalizeTelegramEntities(
                $matchText,
                (array)($hasCaption
                    ?($message['caption_entities']??[])
                    :($message['entities']??[]))
            );
        $aliases=array_values(array_unique(array_filter(array_map('strval',$mediaAliases))));
        if($aliases===[])$aliases=[$mediaIdentity];
        $matchKeys=[];
        $legacyMatchKeys=[];
        foreach($aliases as $alias){
            $matchKeys[]=$this->telegramPendingMatchKey(
                $chatId,
                $mediaType,
                (string)$alias,
                $matchText,
                $matchEntities
            );
            $matchKeys[]=$this->telegramExactPendingMatchKey(
                $chatId,
                $mediaType,
                (string)$alias,
                $matchText,
                $matchEntities
            );
            $legacyMatchKeys[]=$this->telegramLegacyPendingMatchKey(
                $chatId,
                $mediaType,
                (string)$alias,
                $matchText
            );
        }
        $matchKeys=array_values(array_unique($matchKeys));
        $legacyMatchKeys=array_values(array_unique($legacyMatchKeys));
        $fallbackMatchKey=$this->telegramPendingMatchKey(
            $chatId,
            $mediaType,
            '',
            $matchText,
            $matchEntities
        );
        $fallbackMatchKeys=[
            $fallbackMatchKey,
            $this->telegramExactPendingMatchKey(
                $chatId,
                $mediaType,
                '',
                $matchText,
                $matchEntities
            ),
        ];
        $fallbackMatchKeys=array_values(array_unique($fallbackMatchKeys));
        $legacyFallbackMatchKey=$this->telegramLegacyPendingMatchKey(
            $chatId,
            $mediaType,
            '',
            $matchText
        );
        $part=[
            'slot'=>max(0,$slot),
            'match_key'=>(string)($matchKeys[0]??$fallbackMatchKey),
            'match_keys'=>$matchKeys,
            'fallback_match_keys'=>$fallbackMatchKeys,
            'legacy_match_keys'=>$legacyMatchKeys,
            'legacy_fallback_match_keys'=>[$legacyFallbackMatchKey],
            'entity_aware_match'=>true,
            'media_type'=>$mediaType,
            'media_identity'=>$mediaIdentity,
            'media_aliases'=>$aliases,
            'identity_is_stable'=>$identityIsStable,
            'allow_fallback_match'=>!$identityIsStable,
            'media_group_id'=>trim((string)($message['media_group_id']??''))?:null,
            'message_id'=>null,
            'update_id'=>null,
            'published_at'=>null,
        ];
        return PostedTelegramCaptionPlacement::observedPart($message,$part);
    }

    private function telegramPendingMatchKey(
        string $chatId,
        string $mediaType,
        string $mediaIdentity,
        string $text,
        array $entities
    ):string {
        return hash('sha256',$this->encodeJson([
            'version'=>3,
            'chat_id'=>$chatId,
            'media_type'=>$mediaType,
            'media_identity'=>$mediaIdentity,
            'text'=>$text,
            'entities'=>$this->telegramPendingEntitiesSignature($text,$entities),
        ]));
    }

    private function telegramExactPendingMatchKey(
        string $chatId,
        string $mediaType,
        string $mediaIdentity,
        string $text,
        array $entities
    ):string {
        return hash('sha256',$this->encodeJson([
            'version'=>3,
            'chat_id'=>$chatId,
            'media_type'=>$mediaType,
            'media_identity'=>$mediaIdentity,
            'text'=>$text,
            'entities'=>$this->telegramPendingEntitiesSignature($text,$entities,true),
        ]));
    }

    private function telegramLegacyPendingMatchKey(
        string $chatId,
        string $mediaType,
        string $mediaIdentity,
        string $text
    ):string {
        return hash('sha256',$this->encodeJson([
            'version'=>2,
            'chat_id'=>$chatId,
            'media_type'=>$mediaType,
            'media_identity'=>$mediaIdentity,
            'text'=>$text,
        ]));
    }

    private function telegramPendingEntitiesSignature(
        string $text,
        array $entities,
        bool $includeServerGenerated=false
    ):array
    {
        $serverGeneratedTypes=[
            'mention','hashtag','cashtag','bot_command','url','email','phone_number',
        ];
        $result=[];
        foreach($this->normalizeTelegramEntities($text,$entities) as $entity){
            if(
                !$includeServerGenerated
                &&in_array((string)$entity['type'],$serverGeneratedTypes,true)
            ){
                continue;
            }
            $signature=[
                'type'=>(string)$entity['type'],
                'offset'=>(int)$entity['offset'],
                'length'=>(int)$entity['length'],
            ];
            if(isset($entity['url'])){
                $signature['url']=(string)$entity['url'];
            }
            if(isset($entity['user'])&&is_array($entity['user'])){
                $signature['user_id']=(int)($entity['user']['id']??0);
            }
            if(isset($entity['language'])){
                $signature['language']=(string)$entity['language'];
            }
            if(isset($entity['custom_emoji_id'])){
                $signature['custom_emoji_id']=(string)$entity['custom_emoji_id'];
            }
            $result[]=$signature;
        }
        return $result;
    }

    private function mergeTelegramPendingPart(array $expected,array $observed):array
    {
        $merged=array_replace($expected,$observed);
        $merged['match_keys']=array_values(array_unique(array_filter(array_merge(
            array_map('strval',(array)($expected['match_keys']??[$expected['match_key']??''])),
            array_map('strval',(array)($observed['match_keys']??[$observed['match_key']??'']))
        ))));
        $merged['fallback_match_keys']=array_values(array_unique(array_filter(array_merge(
            array_map('strval',(array)($expected['fallback_match_keys']??[])),
            array_map('strval',(array)($observed['fallback_match_keys']??[]))
        ))));
        $merged['legacy_match_keys']=array_values(array_unique(array_filter(array_merge(
            array_map('strval',(array)($expected['legacy_match_keys']??[])),
            array_map('strval',(array)($observed['legacy_match_keys']??[]))
        ))));
        $merged['legacy_fallback_match_keys']=array_values(array_unique(array_filter(array_merge(
            array_map('strval',(array)($expected['legacy_fallback_match_keys']??[])),
            array_map('strval',(array)($observed['legacy_fallback_match_keys']??[]))
        ))));
        $merged['entity_aware_match']=!empty($expected['entity_aware_match'])
            ||!empty($observed['entity_aware_match']);
        $merged['media_aliases']=array_values(array_unique(array_filter(array_merge(
            array_map('strval',(array)($expected['media_aliases']??[])),
            array_map('strval',(array)($observed['media_aliases']??[]))
        ))));
        $merged['identity_is_stable']=!empty($expected['identity_is_stable'])
            ||!empty($observed['identity_is_stable']);
        $merged['allow_fallback_match']=!$merged['identity_is_stable']
            &&(
                !empty($expected['allow_fallback_match'])
                ||!empty($observed['allow_fallback_match'])
            );
        if((string)($merged['match_key']??'')===''){
            $merged['match_key']=(string)($merged['match_keys'][0]??'');
        }
        return $merged;
    }

    private function telegramMessageMediaIdentity(array $message):array
    {
        $paid=$this->telegramPaidMediaIdentity($message);if($paid!==null)return $paid;
        $origin=$message['forward_origin']??null;
        if(is_array($origin)&&$origin!==[]){
            $originType=trim((string)($origin['type']??'unknown'));
            $originChatId=trim((string)(($origin['chat']??[])['id']??''));
            $originMessageId=(int)($origin['message_id']??0);
            $identity='forward:'.$originType.':'.$originChatId.':'.$originMessageId;
            return ['forward',$identity,true,[$identity]];
        }
        $native=PostedTelegramNativeContent::identity($message);if($native!==null)return$native;
        foreach(['video','animation','document','audio','voice'] as $type){
            $media=$message[$type]??null;
            if(is_array($media)){
                $uniqueId=trim((string)($media['file_unique_id']??''));
                $fileId=trim((string)($media['file_id']??''));
                $identity=$uniqueId!==''?$uniqueId:$fileId;
                return [
                    $type,
                    $identity,
                    $uniqueId!=='',
                    array_values(array_filter([$uniqueId,$fileId])),
                ];
            }
        }
        $photos=$message['photo']??null;
        if(is_array($photos)&&$photos!==[]){
            $photo=end($photos);
            if(is_array($photo)){
                $uniqueId=trim((string)($photo['file_unique_id']??''));
                $fileId=trim((string)($photo['file_id']??''));
                $identity=$uniqueId!==''?$uniqueId:$fileId;
                return [
                    'photo',
                    $identity,
                    $uniqueId!=='',
                    array_values(array_filter([$uniqueId,$fileId])),
                ];
            }
        }
        return ['none','',true,[]];
    }

    private function expectedTelegramPendingParts(array $post):array
    {
        $post=PostedTelegramPostOptions::freeze($this->pdo,$post);
        $post=PostedTelegramTransportLayout::freeze($this->pdo,$post);
        $post=PostedTelegramCaptionPlacement::freeze($this->pdo,$post);
        $post=PostedTelegramPublicButtonLayout::freeze($this->pdo,$post,$this->effectivePostPayload($post));
        $multipostParts=$this->telegramMultipostForwardParts($post);
        if($multipostParts!==null)return $multipostParts;
        $this->telegramValidateMediaOptions($post);
        $paid=$this->telegramExpectedPaidMediaMessage($post);
        if($paid!==null)return [$this->telegramPendingPartFromMessage($paid,0,(string)$post['chat_id'])];
        $payload=$this->effectivePostPayload($post);
        $text=(string)$payload['text'];
        PostedTelegramCaptionPlacement::assertCaption($post,$text);
        $entities=(array)($payload['entities']??[]);
        $chatId=(string)$post['chat_id'];
        $media=$this->postMedia($post);
        $parts=[];
        if(!empty($post['repost_chat_id'])&&!empty($post['repost_message_id'])){
            $forwardedText=trim((string)($post['text']??''));
            $origin = $this->telegramStoredNativeForwardOrigin($post);
            return [$this->telegramPendingPartFromMessage([
                'chat'=>['id'=>$chatId],
                'forward_origin'=>$origin,
                'text'=>$forwardedText,
            ],0,$chatId)];
        }
        $native=$this->telegramNativeExpectedParts($post);if($native!==null)return$native;
        if(count($media)>1){
            $caption=PostedTelegramTransportLayout::length($post,$text)<=self::PHOTO_CAPTION_LIMIT?$text:'';
            foreach($media as $index=>$item){
                $message=[
                    'chat'=>['id'=>$chatId],
                    (string)$item['type']=>[
                        'file_id'=>(string)$item['file_id'],
                        'file_unique_id'=>(string)($item['file_unique_id']??''),
                    ],
                ];
                if ($item['type'] === 'photo') $message['photo'] = [$message['photo']];
                if($index===0&&$caption!==''){
                    $message['caption']=$caption;
                    $message['caption_entities']=$entities;
                }
                $message=PostedTelegramCaptionVector::project($post,$index,$message);
                $parts[]=$this->telegramPendingPartFromMessage($message,$index,$chatId);
            }
            return $parts;
        }
        if($media!==[]){
            $item=$media[0];
            $caption=PostedTelegramTransportLayout::length($post,$text)<=self::PHOTO_CAPTION_LIMIT?$text:'';
            $message=[
                'chat'=>['id'=>$chatId],
                (string)$item['type']=>[
                    'file_id'=>(string)$item['file_id'],
                    'file_unique_id'=>(string)($item['file_unique_id']??''),
                ],
                'caption'=>$caption,
            ];
            if ($item['type'] === 'photo') $message['photo'] = [$message['photo']];
            if($caption!=='')$message['caption_entities']=$entities;
            $message=PostedTelegramCaptionPlacement::project($post,$message);
            return [$this->telegramPendingPartFromMessage($message,0,$chatId)];
        }
        if($text!==''){
            $firstPart=(array)($this->splitTelegramFormattedTextForLayout($post,$text,$entities)[0]??[]);
            $first=(string)($firstPart['text']??$text);
            return [$this->telegramPendingPartFromMessage([
                'chat'=>['id'=>$chatId],
                'text'=>$first,
                'entities'=>(array)($firstPart['entities']??[]),
            ],0,$chatId)];
        }
        return [];
    }

    private function telegramContinuationAfterPendingPrimary(array $post):array
    {
        $post=PostedTelegramPostOptions::freeze($this->pdo,$post);
        $post=PostedTelegramTransportLayout::freeze($this->pdo,$post);
        $post=PostedTelegramPublicButtonLayout::freeze($this->pdo,$post,$this->effectivePostPayload($post));
        if(!empty($post['repost_chat_id'])&&!empty($post['repost_message_id']))return [];
        if(PostedTelegramNativeContent::isKind($post['media_type']??null))return [];
        if($this->telegramPostOptions($post)['paid_media_stars']!==null){
            $payload=$this->effectivePostPayload($post);$text=(string)$payload['text'];
            if($this->telegramUtf16Length($text)<=self::PHOTO_CAPTION_LIMIT)return [];
            $buttons=$this->telegramPublicButtonsMarkup($post,(array)$payload['buttons']);
            return $this->telegramTextContinuation($this->splitTelegramFormattedText($text,(array)$payload['entities']),
                array_merge($this->telegramDeliveryExtra($post),$this->linkPreviewExtra($post)),
                $buttons!==[]?['reply_markup'=>['inline_keyboard'=>$buttons]]:[]);
        }
        $payload=$this->effectivePostPayload($post);
        $text=(string)$payload['text'];
        $entities=(array)($payload['entities']??[]);
        $buttons=$this->telegramPublicButtonsMarkup($post,(array)$payload['buttons']);
        $buttonExtra=$buttons!==[]?['reply_markup'=>['inline_keyboard'=>$buttons]]:[];
        $sendExtra=$this->telegramDeliveryExtra($post);
        $media=$this->postMedia($post);
        if(count($media)>1){
            $buttonExtra=[];
            if(PostedTelegramTransportLayout::length($post,$text)>self::PHOTO_CAPTION_LIMIT){
                return $this->telegramTextContinuation(
                    $this->splitTelegramFormattedTextForLayout($post,$text,$entities),
                    array_merge($sendExtra,$this->linkPreviewExtra($post)),
                    $buttonExtra
                );
            }
            return [];
        }
        if($media!==[]&&PostedTelegramTransportLayout::length($post,$text)>self::PHOTO_CAPTION_LIMIT){
            return $this->telegramTextContinuation(
                $this->splitTelegramFormattedTextForLayout($post,$text,$entities),
                array_merge($sendExtra,$this->linkPreviewExtra($post)),
                $buttonExtra
            );
        }
        if($media===[]&&$text!==''){
            $replyExtra=[];
            if(
                !empty($post['reply_message_id'])
                &&(string)($post['reply_chat_id']??'')===(string)$post['chat_id']
            ){
                $replyExtra['reply_parameters']=['message_id'=>(int)$post['reply_message_id']];
            }
            $items=$this->telegramTextContinuation(
                $this->splitTelegramFormattedTextForLayout($post,$text,$entities),
                array_merge($sendExtra,$this->linkPreviewExtra($post)),
                $buttonExtra,
                $replyExtra
            );
            return array_values(array_slice($items,1));
        }
        return [];
    }

    private function sendMedia(int|string $chatId, string $mediaType, string $fileId, string $caption = '', array $extra = []): array
    {
        if ($mediaType === 'photo') {
            return $this->telegram->sendPhoto($chatId, $fileId, $caption, $extra);
        }
        if ($mediaType === 'video') {
            return $this->telegram->sendVideo($chatId, $fileId, $caption, $extra);
        }
        $field = match ($mediaType) {
            'animation' => 'animation',
            'document' => 'document',
            'audio' => 'audio',
            'voice' => 'voice',
            default => throw new InvalidArgumentException('Неподдерживаемый тип медиа.'),
        };
        $method = 'send' . ucfirst($field);
        return $this->telegram->call($method, array_merge([
            'chat_id' => (string) $chatId,
            $field => $fileId,
            'caption' => $caption,
        ], $extra));
    }

    private function duplicatePost(
        int $userId,
        int $postId,
        ?int $managerCategoryId = null
    ): void
    {
        $post = $this->post($postId);
        if (!$post || !$this->canManagePost($userId,$postId)) {
            $this->telegram->sendMessage($userId, 'Пост не найден или нет доступа.');
            return;
        }
        if($managerCategoryId===null){
            $categories=$this->activeTelegramManagerCategories($userId,(int)$post['channel_id']);
            if($categories!==[]){
                $rows=[];
                foreach($categories as $category){
                    $rows[]=[[
                        'text'=>mb_substr(
                            (string)$category['name'].' · '
                                .$this->managerQuotaLabel($category['post_quota_remaining']??null,'постов'),
                            0,
                            60
                        ),
                        'callback_data'=>'cp:dupcat:'.$postId.':'.(int)$category['id'],
                    ]];
                }
                $rows[]=[['text'=>'← К посту','callback_data'=>'cp:post:'.$postId]];
                $this->telegram->sendMessage(
                    $userId,
                    'Выберите рекламную компанию для копии поста.',
                    ['reply_markup'=>['inline_keyboard'=>$rows]]
                );
                return;
            }
        }
        try {
            $newId = $this->copyPostForUser(
                $userId,
                $postId,
                true,
                $managerCategoryId
            );
        } catch (Throwable $exception) {
            $this->telegram->sendMessage(
                $userId,
                'Права на пост или рекламная компания изменились. Откройте пост заново.'
            );
            return;
        }
        $this->showDraftActions($userId, $newId, 'Пост продублирован в черновик.');
    }

    private function publishManagedPostNow(int $userId, int $postId): void
    {
        $post = $this->post($postId);
        if (!$post || !$this->canManagePost($userId,$postId) || !$this->can($userId,(int)$post['channel_id'],'post')) {
            $this->telegram->sendMessage($userId, 'Пост не найден или нет доступа.');
            return;
        }
        if(!in_array((string)$post['status'],['draft','scheduled','failed'],true)){
            $this->telegram->sendMessage($userId,'Пост уже обработан.');
            return;
        }
        $claimed=$this->claimPostForPublish($postId,['draft','scheduled','failed']);
        if(!$claimed){$this->telegram->sendMessage($userId,'Пост уже публикуется или был обработан.');return;}
        try {
            $outcome=$this->publishClaimedPost($claimed, $userId);
        } catch (Throwable $exception) {
            $this->telegram->sendMessage($userId, 'Не удалось отправить: ' . $exception->getMessage());
            return;
        }
        $this->sendTelegramPublicationOutcome($userId,$claimed,$outcome);
    }

    private function sendTelegramPublicationOutcome(int $userId,array $post,array $outcome,?array $target=null):void
    {
        // Publication is already committed. A private UI failure must never imply a resend.
        try {
            if((string)($outcome['state']??'')==='sent'){
                $channel=$this->channel((int)$post['channel_id'])??['title'=>(string)($post['channel_title']??'')];
                $this->sendOrEditMessage(
                    $userId,
                    $this->customEmojiHtml('5260341314095947411','👀').' Пост опубликован в канале '.$this->channelTitleHtml($channel).'.',
                    ['parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>[]]],
                    $target
                );
                return;
            }
            $this->telegram->sendMessage($userId,$this->publicationOutcomeMessage($outcome));
        } catch(Throwable $exception) {
            error_log('GitPosted could not display a committed publication outcome: '.$exception->getMessage());
        }
    }

    private function publicationOutcomeMessage(array $outcome):string
    {
        if(!empty($outcome['native_manual_review'])&&is_string($outcome['notice']??null))return$outcome['notice'];
        if(!empty($outcome['policy_blocked'])||!empty($outcome['multipost_blocked'])||!empty($outcome['top_blocked']))return (string)$outcome['notice'];
        return match((string)($outcome['state']??'')){
            'processing'=>'Telegram принял публикацию и обрабатывает медиа. Повторять отправку не нужно: GitPosted сам получит итоговый пост.',
            'retry_scheduled'=>'Telegram временно ограничил частоту запросов. Бот автоматически повторит публикацию позже.',
            'blocked'=>'В этом канале уже обрабатывается другая публикация. Новый пост не отправлялся.',
            'sent_partial'=>'Публикация отправлена частично. Повтор отключён, чтобы не создать дубль; проверьте пост в контент-плане.',
            'cancelled'=>'Публикация отменена, Telegram её не принял. Канал освобождён.',
            default=>'Пост опубликован.',
        };
    }

    private function deletedPostStatusMessage(array $post,string $default):string
    {
        if(!empty($post['manual_delete_required'])){
            return !empty($post['error_text'])
                ?(string)$post['error_text']
                :'Канал освобождён, но сообщение осталось в Telegram. Удалите его в канале вручную.';
        }
        return $default;
    }

    private function deleteManagedPost(int $userId, int $postId, ?array $target = null): void
    {
        if (!$this->canManagePost($userId, $postId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $post = $this->post($postId);
        if (!$post) {
            $this->telegram->sendMessage($userId, 'Пост не найден.');
            return;
        }

        if ((string) ($post['status'] ?? '') === 'sent') {
            $this->deleteSentManagedPost($userId,$post,$target);
            return;
        }

        if(in_array((string)($post['status']??''),['publishing','telegram_pending'],true)){
            $complete=false;
            $lockedPost=null;
            try{
                $this->pdo->beginTransaction();
                $channelId=(int)($post['channel_id']??0);
                $channelStmt=$this->pdo->prepare(
                    'SELECT chat_id,is_active,added_by
                     FROM posted_channels
                     WHERE id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $channelStmt->execute(['channel'=>$channelId]);
                $lockedChannel=$channelStmt->fetch();
                $postStmt=$this->pdo->prepare(
                    'SELECT *
                     FROM posted_posts
                     WHERE id=:post AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $postStmt->execute(['post'=>$postId,'channel'=>$channelId]);
                $lockedPost=$postStmt->fetch();
                if (!$this->lockActiveTelegramActor($userId)) {
                    throw new RuntimeException('Пользователь заблокирован.');
                }
                $permissionStmt=$this->pdo->prepare(
                    'SELECT can_post,can_manage_posts
                     FROM posted_permissions
                     WHERE user_id=:user AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $permissionStmt->execute(['user'=>$userId,'channel'=>$channelId]);
                $lockedPermission=$permissionStmt->fetch();
                $attemptStmt=$this->pdo->prepare(
                    'SELECT state,pending_parts_json
                     FROM posted_telegram_publish_attempts
                     WHERE post_id=:post AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $attemptStmt->execute(['post'=>$postId,'channel'=>$channelId]);
                $lockedAttempt=$attemptStmt->fetch();
                if(
                    !is_array($lockedChannel)
                    ||empty($lockedChannel['is_active'])
                    ||(int)($lockedChannel['added_by']??0)<=0
                    ||!is_array($lockedPost)
                    ||!is_array($lockedPermission)
                    ||!is_array($lockedAttempt)
                ){
                    throw new RuntimeException('Данные ожидающей публикации не найдены.');
                }
                $allowed=!empty($lockedPermission['can_manage_posts'])||(
                    (int)($lockedPost['author_id']??0)===$userId
                    &&!empty($lockedPermission['can_post'])
                );
                if(!$allowed){
                    throw new RuntimeException('Права на отмену публикации изменились.');
                }
                $lockedPost['chat_id']=(string)$lockedChannel['chat_id'];
                $lockedPost['attempt_state']=(string)$lockedAttempt['state'];
                $lockedPost['pending_parts_json']=$lockedAttempt['pending_parts_json'];
                $lockedStatus=(string)($lockedPost['status']??'');
                $attemptState=(string)($lockedPost['attempt_state']??'');
                if($lockedStatus==='sent'||$attemptState==='completed'){
                    $this->pdo->commit();
                    $fresh=$this->post($postId);
                    if(is_array($fresh)&&(string)($fresh['status']??'')==='sent'){
                        $this->deleteSentManagedPost($userId,$fresh,$target);
                    }elseif(is_array($fresh)&&(string)($fresh['status']??'')==='deleted'){
                        $this->telegram->sendMessage(
                            $userId,
                            $this->deletedPostStatusMessage($fresh,'Пост уже удалён.')
                        );
                    }else{
                        $this->telegram->sendMessage($userId,'Состояние публикации уже изменилось. Обновите контент-план.');
                    }
                    return;
                }
                if(in_array(
                    $attemptState,
                    ['finalizing','cancel_finalizing','force_cancel_finalizing'],
                    true
                )){
                    $this->pdo->commit();
                    $this->telegram->sendMessage(
                        $userId,
                        in_array(
                            $attemptState,
                            ['cancel_finalizing','force_cancel_finalizing'],
                            true
                        )
                            ? 'Публикация уже удаляется из канала.'
                            : 'Telegram-публикация уже завершается. Обновите пост через несколько секунд и удалите его обычной кнопкой.'
                    );
                    return;
                }
                if(!in_array(
                    $attemptState,
                    ['sending','continuation_sending','awaiting_telegram','needs_review','finalization_uncertain','reconciled','cancel_requested'],
                    true
                )){
                    throw new RuntimeException('Публикация находится в неизвестном состоянии.');
                }
                $parts=$this->decodeJson((string)($lockedPost['pending_parts_json']??''));
                $complete=$parts!==[];
                foreach($parts as $part){
                    if(!is_array($part)||(int)($part['message_id']??0)<=0){
                        $complete=false;
                        break;
                    }
                }
                $cancelAttempt=$this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET state='cancel_requested',
                         deadline_at=COALESCE(deadline_at,NOW()+INTERVAL 24 HOUR)
                     WHERE post_id=:post
                        AND state IN ('sending','continuation_sending','awaiting_telegram','needs_review','finalization_uncertain','reconciled','cancel_requested')"
                );
                $cancelAttempt->execute(['post'=>$postId]);
                if($cancelAttempt->rowCount()!==1&&$attemptState!=='cancel_requested'){
                    throw new RuntimeException('Состояние публикации уже изменилось.');
                }
                $cancelPost=$this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status=IF(status='publishing','telegram_pending',status),
                         error_text='Отмена принята. Когда Telegram завершит обработку, публикация будет сразу удалена.'
                     WHERE id=:post AND status IN ('publishing','telegram_pending')"
                );
                $cancelPost->execute(['post'=>$postId]);
                if($cancelPost->rowCount()!==1&&$lockedStatus!=='telegram_pending'){
                    throw new RuntimeException('Состояние публикации уже изменилось.');
                }
                $this->pdo->commit();
                if($complete)$this->finalizeTelegramPublishAttempt($postId);
            }catch(Throwable $exception){
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                $this->telegram->sendMessage($userId,'Не удалось отменить публикацию: '.$exception->getMessage());
                return;
            }
            $fresh=$this->post($postId);
            $this->telegram->sendMessage(
                $userId,
                is_array($fresh)&&(string)($fresh['status']??'')==='deleted'
                    ?$this->deletedPostStatusMessage(
                        $fresh,
                        'Публикация отменена и удалена из канала.'
                    )
                    : ($complete
                        ? 'Отмена принята. Удаление публикации будет безопасно завершено.'
                        : 'Отмена принята. Как только Telegram закончит обработку медиа, GitPosted сразу удалит публикацию.')
            );
            return;
        }

        $cancelledPost=$this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft','scheduled','failed'],
            function (array $lockedPost) use ($postId): array {
                $this->refundManagerQuotaForPost($postId);
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status='cancelled',error_text=NULL
                     WHERE id=:id AND status IN ('draft','scheduled','failed')"
                )->execute(['id'=>$postId]);
                return $lockedPost;
            }
        );
        if(!is_array($cancelledPost)){
            $fresh=$this->post($postId);
            if(is_array($fresh)&&in_array((string)($fresh['status']??''),['publishing','telegram_pending'],true)){
                $this->deleteManagedPost($userId,$postId,$target);
                return;
            }
            if(is_array($fresh)&&(string)($fresh['status']??'')==='sent'){
                $this->deleteSentManagedPost($userId,$fresh,$target);
                return;
            }
            $this->telegram->sendMessage(
                $userId,
                is_array($fresh)&&in_array((string)($fresh['status']??''),['cancelled','deleted'],true)
                    ?$this->deletedPostStatusMessage($fresh,'Пост уже удалён.')
                    : 'Состояние поста уже изменилось. Обновите контент-план.'
            );
            return;
        }
        $date=$cancelledPost['scheduled_at']?$this->formatStoredForUser($userId,(string)$cancelledPost['scheduled_at'],'Y-m-d'):$this->todayForUser($userId);
        $this->showContentPlanForChannel($userId, (int) $cancelledPost['channel_id'], $date, false, $target);
        $this->telegram->sendMessage($userId, 'Пост удалён из контент-плана.');
    }

    private function deleteSentManagedPost(int $userId,array $post,?array $target=null,?array $confirmation=null):void
    {
        $override=$this->telegramPublicDeleteOverride($post,'manual',$userId,$confirmation);if($override!==null){$this->telegram->sendMessage($userId,$override?'Пост удалён.':'Удаление пока не подтверждено. Журналы сохранены; автоматического повторения нет.');return;}
        $postId=(int)($post['id']??0);
        $channelId=(int)($post['channel_id']??0);
        $pinLockName='gitposted_pin_'.substr(hash('sha256',(string)$channelId),0,32);
        $pinLock=$this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $pinLock->execute(['lock_name'=>$pinLockName]);
        if((int)($pinLock->fetchColumn()?:0)!==1){
            $this->telegram->sendMessage($userId,'Канал занят другой операцией. Повторите удаление.');
            return;
        }
        $pinEffectCancelled=false;
        try{
            $this->pdo->beginTransaction();
            $channelLock=$this->pdo->prepare(
                'SELECT chat_id,added_by,is_active
                 FROM posted_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelLock->execute(['channel'=>$channelId]);
            $channel=$channelLock->fetch();
            $postLock=$this->pdo->prepare(
                'SELECT * FROM posted_posts WHERE id=:post AND channel_id=:channel
                 LIMIT 1 FOR UPDATE'
            );
            $postLock->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedPost=$postLock->fetch();
            if(
                !is_array($channel)
                ||empty($channel['is_active'])
                ||(int)($channel['added_by']??0)<=0
                ||!is_array($lockedPost)
                ||(string)($lockedPost['status']??'')!=='sent'
            ){
                throw new RuntimeException('Канал отключён или состояние поста изменилось.');
            }
            if (!$this->lockActiveTelegramActor($userId)) {
                throw new RuntimeException('Пользователь заблокирован.');
            }
            $permission=$this->pdo->prepare(
                'SELECT can_post,can_manage_posts
                 FROM posted_permissions
                 WHERE user_id=:user AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $permission->execute(['user'=>$userId,'channel'=>$channelId]);
            $rights=$permission->fetch();
            $allowed=is_array($rights)&&(
                !empty($rights['can_manage_posts'])
                ||(
                    (int)($lockedPost['author_id']??0)===$userId
                    &&!empty($rights['can_post'])
                )
            );
            if(!$allowed){
                throw new RuntimeException('Права на удаление поста изменились.');
            }
            if($confirmation!==null&&!$this->telegramCpDeleteGuardLocked($userId,$lockedPost,$confirmation)){$this->pdo->commit();return;}
            if(!$this->cancelTelegramPinEffect($postId)){
                throw new RuntimeException(
                    'Закрепление поста ещё завершается. Повторите удаление позже.'
                );
            }
            $pinEffectCancelled=true;
            $post=array_replace($lockedPost,[
                'chat_id'=>(string)$channel['chat_id'],
            ]);
            foreach($this->postMessageIds($post) as $messageId){
                try{
                    $this->telegram->call('deleteMessage',[
                        'chat_id'=>(string)$post['chat_id'],
                        'message_id'=>$messageId,
                    ]);
                }catch(Throwable $deleteError){
                    if(!str_contains(
                        mb_strtolower($deleteError->getMessage()),
                        'message to delete not found'
                    )){
                        throw $deleteError;
                    }
                }
            }
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET status='deleted',delete_at=NULL,error_text=NULL
                 WHERE id=:id AND status='sent'"
            )->execute(['id'=>$postId]);
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if($pinEffectCancelled){
                try{$this->cancelTelegramPinEffect($postId);}catch(Throwable){}
            }
            try {
                $this->withTelegramManagedPostLock(
                    $userId,
                    $postId,
                    ['sent'],
                    function () use ($postId, $exception): bool {
                        $this->pdo->prepare(
                            "UPDATE posted_posts SET error_text=:error
                             WHERE id=:id AND status='sent'"
                        )->execute(['error'=>$exception->getMessage(),'id'=>$postId]);
                        return true;
                    }
                );
            } catch (Throwable) {
            }
            $this->telegram->sendMessage(
                $userId,
                'Не удалось удалить пост в канале: '.$exception->getMessage()
            );
            return;
        }finally{
            $releasePinLock=$this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releasePinLock->execute(['lock_name'=>$pinLockName]);
        }
        $date=$post['scheduled_at']
            ?$this->formatStoredForUser($userId,(string)$post['scheduled_at'],'Y-m-d')
            :$this->todayForUser($userId);
        $this->showContentPlanForChannel(
            $userId,
            (int)$post['channel_id'],
            $date,
            false,
            $target
        );
    }

    private function continueTelegramPendingWait(int $userId,int $postId):void
    {
        $updated=$this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['telegram_pending'],
            function () use ($postId): bool {
                $attempt=$this->pdo->prepare(
                    'SELECT state,review_notified_at,created_at
                     FROM posted_telegram_publish_attempts
                     WHERE post_id=:post
                     LIMIT 1
                     FOR UPDATE'
                );
                $attempt->execute(['post'=>$postId]);
                $lockedAttempt=$attempt->fetch();
                if(
                    !is_array($lockedAttempt)
                    ||empty($lockedAttempt['review_notified_at'])
                    ||strtotime((string)$lockedAttempt['created_at'])<time()-7*86400
                    ||!in_array(
                        (string)$lockedAttempt['state'],
                        ['awaiting_telegram','needs_review','finalization_uncertain','cancel_requested'],
                        true
                    )
                ){
                    return false;
                }
                $this->pdo->prepare(
                    "UPDATE posted_telegram_publish_attempts
                     SET deadline_at=NOW()+INTERVAL 24 HOUR,
                         review_notified_at=NULL,
                         review_notification_sent_at=NULL
                     WHERE post_id=:post"
                )->execute(['post'=>$postId]);
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET error_text='Ожидание подтверждения Telegram продлено. Автоматический повтор по-прежнему заблокирован.'
                     WHERE id=:post AND status='telegram_pending'"
                )->execute(['post'=>$postId]);
                return true;
            }
        );
        if($updated===null){
            $this->telegram->sendMessage($userId,'Нет доступа к посту.');
            return;
        }
        $this->telegram->sendMessage(
            $userId,
            $updated===true
                ? 'Ожидание продлено ещё на 24 часа. Повторная отправка отключена.'
                : 'Состояние публикации уже изменилось либо истёк максимальный срок ожидания. Обновите контент-план и при необходимости явно освободите канал.'
        );
    }

    private function telegramDeleteNotFound(Throwable $exception):bool
    {
        return str_contains(
            mb_strtolower($exception->getMessage()),
            'message to delete not found'
        );
    }

    private function telegramDeleteNeedsManualAction(Throwable $exception):bool
    {
        if(
            !$exception instanceof PostedTelegramRequestException
            ||$exception->deliveryMayBeUnknown()
        ){
            return false;
        }
        $status=$exception->httpStatus();
        return $status>=400
            &&$status<500
            &&!in_array($status,[408,409,425,429],true);
    }

    private function telegramManualDeleteError(array $errors):string
    {
        $details=mb_substr(implode('; ',array_unique(array_map('strval',$errors))),0,3200);
        return 'Канал освобождён, но Telegram не разрешил удалить опубликованное сообщение. '
            .'Удалите его вручную.'
            .($details!==''?' Ошибка Telegram: '.$details:'');
    }

    private function completeForceCancelledTelegramAttempt(int $postId):array
    {
        $stmt=$this->pdo->prepare(
            "SELECT attempt.state,attempt.message_slots_json,post.status,
                    post.manual_delete_required,post.error_text,channel.chat_id
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             INNER JOIN posted_channels channel ON channel.id=post.channel_id
             WHERE attempt.post_id=:post
             LIMIT 1"
        );
        $stmt->execute(['post'=>$postId]);
        $attempt=$stmt->fetch();
        if(!is_array($attempt)){
            throw new RuntimeException('Попытка явной отмены не найдена.');
        }
        if((string)$attempt['state']==='completed'){
            return [
                'known'=>count(array_filter(
                    $this->decodeMessageSlots((string)($attempt['message_slots_json']??'')),
                    static fn(int $id):bool=>$id>0
                )),
                'manual'=>!empty($attempt['manual_delete_required']),
                'error'=>$attempt['error_text']??null,
            ];
        }
        if(
            (string)$attempt['state']!=='force_cancel_finalizing'
            ||(string)$attempt['status']!=='telegram_pending'
        ){
            throw new RuntimeException('Состояние явной отмены уже изменилось.');
        }
        $messageIds=array_values(array_unique(array_filter(
            $this->decodeMessageSlots((string)($attempt['message_slots_json']??'')),
            static fn(int $id):bool=>$id>0
        )));
        $manualDeleteErrors=[];
        foreach($messageIds as $messageId){
            try{
                $this->telegram->call('deleteMessage',[
                    'chat_id'=>(string)$attempt['chat_id'],
                    'message_id'=>$messageId,
                ]);
            }catch(Throwable $deleteError){
                if($this->telegramDeleteNotFound($deleteError))continue;
                if($this->telegramDeleteNeedsManualAction($deleteError)){
                    $manualDeleteErrors[]=$deleteError->getMessage();
                    continue;
                }
                throw $deleteError;
            }
        }
        $manualDeleteRequired=$manualDeleteErrors!==[];
        $manualDeleteError=$manualDeleteRequired
            ?$this->telegramManualDeleteError($manualDeleteErrors)
            :null;

        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare(
                "SELECT attempt.state,post.manual_delete_required,post.error_text
                 FROM posted_telegram_publish_attempts attempt
                 INNER JOIN posted_posts post ON post.id=attempt.post_id
                 WHERE attempt.post_id=:post
                 LIMIT 1
                 FOR UPDATE"
            );
            $lock->execute(['post'=>$postId]);
            $locked=$lock->fetch();
            $state=(string)(is_array($locked)?($locked['state']??''):'');
            if($state==='completed'){
                $this->pdo->commit();
                return [
                    'known'=>count($messageIds),
                    'manual'=>!empty($locked['manual_delete_required']),
                    'error'=>$locked['error_text']??null,
                ];
            }
            if($state!=='force_cancel_finalizing'){
                throw new RuntimeException('Состояние явной отмены уже изменилось.');
            }
            $updated=$this->pdo->prepare(
                "UPDATE posted_posts
                 SET status='deleted',publish_started_at=NULL,
                      published_at=COALESCE(published_at,NOW()),
                      delete_at=NULL,manual_delete_required=:manual_delete_required,
                      error_text=:error
                  WHERE id=:post AND status='telegram_pending'"
            );
            $updated->execute([
                'manual_delete_required'=>$manualDeleteRequired?1:0,
                'error'=>$manualDeleteError,
                'post'=>$postId,
            ]);
            if($updated->rowCount()!==1){
                throw new RuntimeException('Ожидающая публикация уже изменила состояние.');
            }
            $this->completeTelegramAttemptRow($postId,true);
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
        return [
            'known'=>count($messageIds),
            'manual'=>$manualDeleteRequired,
            'error'=>$manualDeleteError,
        ];
    }

    private function forceCancelTelegramPending(int $userId,int $postId):void
    {
        $initialPost=$this->post($postId);
        if(!is_array($initialPost)||!$this->canManagePost($userId,$postId)){
            $this->telegram->sendMessage($userId,'Нет доступа к посту.');
            return;
        }
        $post=null;
        $cancelResult=['known'=>0,'manual'=>false,'error'=>null];
        try{
            $this->pdo->beginTransaction();
            $channelId=(int)($initialPost['channel_id']??0);
            $channelStmt=$this->pdo->prepare(
                'SELECT chat_id,is_active,added_by
                 FROM posted_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelStmt->execute(['channel'=>$channelId]);
            $lockedChannel=$channelStmt->fetch();
            $postStmt=$this->pdo->prepare(
                'SELECT *
                 FROM posted_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postStmt->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedPost=$postStmt->fetch();
            if (!$this->lockActiveTelegramActor($userId)) {
                throw new RuntimeException('Пользователь заблокирован.');
            }
            $permissionStmt=$this->pdo->prepare(
                'SELECT can_post,can_manage_posts
                 FROM posted_permissions
                 WHERE user_id=:user AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $permissionStmt->execute(['user'=>$userId,'channel'=>$channelId]);
            $lockedPermission=$permissionStmt->fetch();
            $attemptStmt=$this->pdo->prepare(
                'SELECT state,review_notified_at,message_slots_json
                 FROM posted_telegram_publish_attempts
                 WHERE post_id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $attemptStmt->execute(['post'=>$postId,'channel'=>$channelId]);
            $lockedAttempt=$attemptStmt->fetch();
            if(
                !is_array($lockedChannel)
                ||empty($lockedChannel['is_active'])
                ||(int)($lockedChannel['added_by']??0)<=0
                ||!is_array($lockedPost)
                ||(string)($lockedPost['status']??'')!=='telegram_pending'
                ||!is_array($lockedPermission)
                ||!is_array($lockedAttempt)
            ){
                throw new RuntimeException('Ожидающая публикация уже изменила состояние.');
            }
            $allowed=!empty($lockedPermission['can_manage_posts'])||(
                (int)($lockedPost['author_id']??0)===$userId
                &&!empty($lockedPermission['can_post'])
            );
            if(!$allowed){
                throw new RuntimeException('Права на отмену публикации изменились.');
            }
            $post=array_merge(
                $lockedPost,
                [
                    'chat_id'=>(string)$lockedChannel['chat_id'],
                    'attempt_state'=>(string)$lockedAttempt['state'],
                    'review_notified_at'=>$lockedAttempt['review_notified_at'],
                    'message_slots_json'=>$lockedAttempt['message_slots_json'],
                ]
            );
            $state=(string)($post['attempt_state']??'');
            if(
                empty($post['review_notified_at'])
                ||!in_array(
                    $state,
                    ['awaiting_telegram','needs_review','finalization_uncertain','cancel_requested'],
                    true
                )
            ){
                throw new RuntimeException('Явная отмена доступна только после уведомления об истечении ожидания.');
            }
            $claim=$this->pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET state='force_cancel_finalizing'
                 WHERE post_id=:post
                   AND state=:state
                   AND review_notified_at IS NOT NULL"
            );
            $claim->execute(['post'=>$postId,'state'=>$state]);
            if($claim->rowCount()!==1){
                throw new RuntimeException('Состояние публикации уже изменилось.');
            }
            $this->pdo->commit();
            $cancelResult=$this->completeForceCancelledTelegramAttempt($postId);
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            try {
                $this->withTelegramManagedPostLock(
                    $userId,
                    $postId,
                    ['telegram_pending'],
                    function () use ($postId, $exception): bool {
                        $this->pdo->prepare(
                            "UPDATE posted_posts
                             SET error_text=:error
                             WHERE id=:post AND status='telegram_pending'"
                        )->execute([
                            'error'=>'Не удалось завершить явную отмену: '.mb_substr($exception->getMessage(),0,3400),
                            'post'=>$postId,
                        ]);
                        return true;
                    }
                );
            } catch (Throwable) {
            }
            $this->telegram->sendMessage(
                $userId,
                'Не удалось освободить канал: '.$exception->getMessage()
            );
            return;
        }
        $this->telegram->sendMessage(
            $userId,
            !empty($cancelResult['manual'])
                ? 'Ожидание отменено и канал освобождён, но Telegram не разрешил удалить опубликованное сообщение. Удалите его в канале вручную.'
                : ((int)$cancelResult['known']>0
                    ? 'Ожидание отменено, известные сообщения удалены, канал освобождён.'
                    : 'Ожидание отменено, канал освобождён. Автоматической повторной отправки не было.')
        );
    }

    private function setDeleteTimer(int $userId, int $postId, int $minutes): void
    {
        $minutes = $this->normalizeTelegramDeleteTimerMinutes($minutes);
        $updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft','scheduled','failed','sent'],
            function (array $post) use ($postId, $minutes): bool {
                $deleteAt=null;
                if($minutes>0&&(string)$post['status']==='sent'){
                    $base=!empty($post['published_at'])?strtotime((string)$post['published_at']):time();
                    $deleteAt=date('Y-m-d H:i:s',((int)$base)+$minutes*60);
                }
                $this->pdo->prepare(
                    'UPDATE posted_posts
                     SET delete_after_minutes=:minutes,delete_at=:delete_at,
                         manual_delete_required=0,delete_notice_sent_at=NULL,
                         delete_attempts=0,error_text=NULL
                     WHERE id=:id'
                )->execute([
                    'minutes'=>$minutes>0?$minutes:null,
                    'delete_at'=>$deleteAt,
                    'id'=>$postId,
                ]);
                return true;
            }
        );
        if ($updated !== true) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        if ($minutes > self::TELEGRAM_MAX_AUTO_DELETE_MINUTES) {
            $this->telegram->sendMessage(
                $userId,
                'Сохранён срок ручного удаления: '.$this->telegramDeleteTimerLabel($minutes).".\n\n".$this->telegramDeleteTimerHint()
            );
        }
        $this->showManagedPost($userId, $postId);
    }

    private function setRepeat(int $userId, int $postId, int $minutes): void
    {
        try {
        $updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft','scheduled','failed','sent'],
            function (array $post,array $channel) use ($postId, $minutes): bool {
                if($minutes>0)PostedTelegramPolicy::requireChannel($this->pdo,$this->config,$channel,'premium');
                $topOption=PostedTelegramTopHold::load($this->pdo,$post,true);if($minutes>0&&$topOption['enabled']&&$minutes<$topOption['duration_minutes'])throw new PostedTelegramTopHoldException('schedule_conflict');
                $this->replaceTelegramRepeatIntervalLocked($post,$minutes);
                return true;
            }
        );
        } catch (PostedTelegramTopHoldException $exception) {$this->telegram->sendMessage($userId,$exception->getMessage());return;
        } catch (PostedTelegramPolicyException $exception) {
            $message=in_array($exception->reason,['invalid_policy','invalid_license','policy_check_unavailable'],true)
                ?'Настройки доступа или подписки канала требуют проверки администратором.'
                :"У канала нет премиум-подписки\n\nЧтобы продолжить, приобретите подписку для канала";
            $this->telegram->sendMessage($userId,$message);
            return;
        } catch (PostedPublishAuthorizationException $exception) {
            $this->telegram->sendMessage($userId,$exception->getMessage());
            return;
        }
        if ($updated !== true) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $this->showManagedPost($userId, $postId);
    }

    private function replaceTelegramRepeatIntervalLocked(array $post,int $minutes): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Изменение повтора требует транзакцию.');
        $topOption=PostedTelegramTopHold::load($this->pdo,$post,true);if($minutes>0&&$topOption['enabled']&&$minutes<$topOption['duration_minutes'])throw new PostedTelegramTopHoldException('schedule_conflict');
        $postId=(int)$post['id'];
        $rootId=!empty($post['repeat_slot_at'])&&(int)($post['copied_from_id']??0)>0
            ?(int)$post['copied_from_id']:$postId;
        $rootStmt=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=:post AND channel_id=:channel LIMIT 1 FOR UPDATE');
        $rootStmt->execute(['post'=>$rootId,'channel'=>$post['channel_id']]);
        $root=$rootStmt->fetch();
        if (!is_array($root)) {$rootId=$postId;$root=$post;}
        $rows=[$rootId=>$root];
        $frontier=[$rootId];
        while ($frontier!==[]) {
            $marks=implode(',',array_fill(0,count($frontier),'?'));
            $children=$this->pdo->prepare("SELECT * FROM posted_posts WHERE channel_id=?
                AND copied_from_id IN ({$marks}) AND repeat_slot_at IS NOT NULL ORDER BY id FOR UPDATE");
            $children->execute([(int)$post['channel_id'],...$frontier]);
            $frontier=[];
            foreach ($children->fetchAll() as $child) {
                $id=(int)$child['id'];
                if (isset($rows[$id])) continue;
                $rows[$id]=$child;
                $frontier[]=$id;
            }
        }
        foreach ($rows as $row) {
            if (in_array((string)$row['status'],['publishing','telegram_pending'],true)
                || (in_array((string)$row['status'],['draft','scheduled','failed','cancelled'],true)
                    && $this->postMessageIds($row)!==[])) {
                throw new PostedPublishAuthorizationException('В серии завершается отправка или результат публикации требует проверки. Измените повтор после её завершения.');
            }
        }
        foreach (array_chunk(array_keys($rows),200) as $ids) {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $attempt=$this->pdo->prepare("SELECT post_id FROM posted_telegram_publish_attempts
                WHERE post_id IN ({$marks}) AND (state<>'completed' OR gate_channel_id IS NOT NULL)
                LIMIT 1 FOR UPDATE");
            $attempt->execute($ids);
            if ($attempt->fetchColumn()!==false) {
                throw new PostedPublishAuthorizationException('В серии есть неподтверждённая отправка. Сначала завершите её проверку; повтор пока не изменён.');
            }
        }
        $queued=array_values(array_filter($rows,static fn(array $row): bool => (string)$row['status']==='scheduled'));
        if ($minutes>0 && (int)($post['repeat_interval_minutes']??0)===$minutes
            && ((string)$post['status']!=='sent'
                || (count($queued)===1 && (int)($queued[0]['repeat_interval_minutes']??0)===$minutes))) return;

        $this->stopTelegramRepeatDescendants($postId);
        if ($minutes<=0) return;
        // A cancelled, never-sent future slot must not retain the unique key:
        // otherwise explicit resume and later successors would hit a dead row.
        $release=$this->pdo->prepare("UPDATE posted_posts SET repeat_slot_at=NULL
            WHERE id=:post AND status='cancelled' AND repeat_slot_at>:now");
        $now=date('Y-m-d H:i:s');
        foreach ($rows as $id=>$row) {
            if ($id!==$postId && in_array((string)$row['status'],['draft','scheduled','failed','cancelled'],true)) {
                $release->execute(['post'=>$id,'now'=>$now]);
            }
        }
        $this->pdo->prepare('UPDATE posted_posts SET repeat_interval_minutes=:minutes,repeat_until=:until WHERE id=:post')
            ->execute(['minutes'=>$minutes,'until'=>$root['repeat_until']??null,'post'=>$rootId]);
        $this->pdo->prepare('UPDATE posted_posts SET status=:status,scheduled_at=:scheduled,
            repeat_interval_minutes=:minutes,repeat_until=:until WHERE id=:post')
            ->execute(['status'=>$post['status'],'scheduled'=>$post['scheduled_at']??null,
                'minutes'=>$minutes,'until'=>$post['repeat_until']??null,'post'=>$postId]);
        $post['repeat_interval_minutes']=$minutes;
        if ((string)$post['status']==='sent' || !empty($post['scheduled_at'])) {
            $nextAt=$this->nextTelegramRepeatAt($post);
            if ($nextAt!==null) {
                $occupied=$this->pdo->prepare('SELECT id FROM posted_posts WHERE copied_from_id=:root AND repeat_slot_at=:slot LIMIT 1 FOR UPDATE');
                $occupied->execute(['root'=>$rootId,'slot'=>$nextAt]);
                if ($occupied->fetchColumn()!==false) {
                    throw new PostedPublishAuthorizationException('Следующий слот уже связан с подтверждённой публикацией. Укажите другой интервал; история публикаций сохранена.');
                }
                if ((string)$post['status']==='sent') $this->createRepeatedPost($post);
            }
        }
    }

    private function stopTelegramRepeatDescendants(int $postId): void
    {
        if($postId<=0||!$this->pdo->inTransaction()){
            throw new RuntimeException('Остановка повторов требует активной транзакции.');
        }
        $currentStmt=$this->pdo->prepare(
            'SELECT id,copied_from_id,repeat_slot_at
             FROM posted_posts
             WHERE id=:post
             LIMIT 1
             FOR UPDATE'
        );
        $currentStmt->execute(['post'=>$postId]);
        $current=$currentStmt->fetch();
        if(!is_array($current))return;
        $rootId=!empty($current['repeat_slot_at'])&&(int)($current['copied_from_id']??0)>0
            ?(int)$current['copied_from_id']
            :$postId;
        if($rootId!==$postId){
            $rootLock=$this->pdo->prepare(
                'SELECT id FROM posted_posts WHERE id=:post LIMIT 1 FOR UPDATE'
            );
            $rootLock->execute(['post'=>$rootId]);
            if($rootLock->fetchColumn()===false)$rootId=$postId;
        }
        $this->pdo->prepare(
            'UPDATE posted_posts
             SET repeat_interval_minutes=NULL,repeat_until=NULL
             WHERE id=:post'
        )->execute(['post'=>$rootId]);
        $frontier=[$rootId];
        $seen=[$rootId=>true];
        $descendants=[];
        while($frontier!==[]){
            $placeholders=implode(',',array_fill(0,count($frontier),'?'));
            $stmt=$this->pdo->prepare(
                "SELECT id,status FROM posted_posts
                 WHERE copied_from_id IN ({$placeholders})
                   AND repeat_slot_at IS NOT NULL
                 ORDER BY id
                 FOR UPDATE"
            );
            $stmt->execute($frontier);
            $next=[];
            foreach($stmt->fetchAll() as $candidate){
                $candidateId=(int)($candidate['id']??0);
                if($candidateId<=0||isset($seen[$candidateId]))continue;
                $seen[$candidateId]=true;
                $descendants[$candidateId]=(string)($candidate['status']??'');
                $next[]=$candidateId;
            }
            $frontier=$next;
        }
        foreach($descendants as $descendantId=>$status){
            if(in_array($status,['draft','scheduled','failed','cancelled'],true)){
                $this->refundManagerQuotaForPost((int)$descendantId);
            }
        }
        foreach(array_chunk(array_keys($descendants),200) as $chunk){
            $placeholders=implode(',',array_fill(0,count($chunk),'?'));
            $this->pdo->prepare(
                "UPDATE posted_posts
                 SET scheduled_at=IF(status IN ('draft','scheduled','failed'),NULL,scheduled_at),
                     publish_started_at=IF(status IN ('draft','scheduled','failed'),NULL,publish_started_at),
                     status=IF(status IN ('draft','scheduled','failed'),'cancelled',status),
                     repeat_interval_minutes=NULL,repeat_until=NULL
                 WHERE id IN ({$placeholders})"
            )->execute($chunk);
        }
    }

    private function createRepeatedPost(array $post): void
    {
        $nextAt=$this->nextTelegramRepeatAt($post);
        if ($nextAt===null) return;
        try{$this->copyPost($post,(int)$post['channel_id'],(int)$post['author_id'],$nextAt,'scheduled',$nextAt);}
        catch(PostedTelegramTopHoldException$conflict){
            if(!in_array($conflict->reason,['schedule_conflict','hold_active','delivery_unknown'],true))throw$conflict;
            $child=$this->copyPost($post,(int)$post['channel_id'],(int)$post['author_id'],$nextAt,'failed',$nextAt);
            $this->pdo->prepare("UPDATE posted_posts SET error_text=? WHERE id=? AND status='failed'")->execute(['Повтор требует другой даты: конфликт удержания в топе. Отправка не начиналась.',$child]);
        }
    }

    private function nextTelegramRepeatAt(array $post): ?string
    {
        $minutes = (int) ($post['repeat_interval_minutes'] ?? 0);
        if($minutes<=0)return null;
        $step=$minutes*60;
        $baseRaw=(string)($post['scheduled_at']?:($post['published_at']?:date('Y-m-d H:i:s')));
        $base=strtotime($baseRaw);
        if($base===false)$base=time();
        $now=time();
        $next=$base+$step;
        if($next<=$now)$next=$base+(intdiv($now-$base,$step)+1)*$step;
        $nextAt=date('Y-m-d H:i:s',$next);
        if(!empty($post['repeat_until'])&&$next>strtotime((string)$post['repeat_until']))return null;
        return $nextAt;
    }

    private function copyPostForUser(
        int $userId,
        int $postId,
        bool $preserveSchedule,
        ?int $managerCategoryId = null
    ): int
    {
        $copied = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft','scheduled','failed','sent','deleted','cancelled'],
            function (array $lockedPost,array $_channel,array $permission) use (
                $userId,
                $preserveSchedule,
                $managerCategoryId
            ): int {
                $resolvedCategoryId=$this->resolveTelegramManagerCategoryLocked(
                    $userId,
                    (int)$lockedPost['channel_id'],
                    max(0,(int)($managerCategoryId??0)),
                    $permission
                );
                $copySource=$lockedPost;
                $copySource['author_id']=$userId;
                $copySource['manager_category_id']=$resolvedCategoryId;
                return $this->copyPost(
                    $copySource,
                    (int) $lockedPost['channel_id'],
                    $userId,
                    $preserveSchedule && !empty($lockedPost['scheduled_at'])
                        ? (string) $lockedPost['scheduled_at']
                        : null,
                    'draft'
                );
            },
            false,
            false,
            true
        );
        if (!is_int($copied) || $copied <= 0) {
            throw new RuntimeException('Права на пост изменились.');
        }
        return $copied;
    }

    private function copyPost(array $post, int $channelId, int $authorId, ?string $scheduledAt, string $status, ?string $repeatSlotAt = null): int
    {
        $ownsOptionsTransaction=!$this->pdo->inTransaction();
        $topPlanningHint=$ownsOptionsTransaction?PostedTelegramTopHold::planningSnapshot($this->pdo,$channelId):null;
        if($ownsOptionsTransaction)$this->pdo->beginTransaction();
        try {
        if(!$ownsOptionsTransaction)$this->pdo->exec('SAVEPOINT tgh_clone_write');
        if($topPlanningHint!==null){foreach($topPlanningHint['channel_ids']as$topChannelId){$topLock=$this->pdo->prepare('SELECT id FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$topLock->execute([$topChannelId]);if($topLock->fetchColumn()===false)throw new PostedTelegramTopHoldException('stale_revision');}PostedTelegramTopHold::verifyPlanningLocked($this->pdo,$topPlanningHint);}
        if((int)($post['id']??0)>0)PostedTelegramAlbumCollector::assertReadyLocked($this->pdo,$post);
        if(PostedTelegramNativeContent::isKind($post['media_type']??null))PostedTelegramNativeSchema::requireStorageSupported($this->pdo);
        $copiedFromId=(int)($post['id']??0)?:null;
        if($repeatSlotAt!==null&&!empty($post['repeat_slot_at'])){
            $copiedFromId=(int)($post['copied_from_id']??0)?:$copiedFromId;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO posted_posts
                (channel_id,author_id,text,text_entities_json,autosignature_text,autosignature_entities_json,
                 media_type,media_file_id,media_json,buttons_json,status,scheduled_at,
                 delete_after_minutes,delete_report_enabled,repeat_interval_minutes,repeat_until,repeat_slot_at,copied_from_id,
                 silent,pin_after_publish,comments_enabled,is_ad,replacement_url,link_preview_enabled,link_preview_large,
                 link_preview_above,reply_chat_id,reply_message_id,repost_chat_id,repost_message_id,
                 repost_input_chat_id,repost_input_message_id,manager_category_id,import_json)
             VALUES
                (:channel_id,:author_id,:text,:text_entities_json,:autosignature_text,:autosignature_entities_json,
                 :media_type,:media_file_id,:media_json,:buttons_json,:status,:scheduled_at,
                 :delete_after_minutes,:delete_report_enabled,:repeat_interval_minutes,:repeat_until,:repeat_slot_at,:copied_from_id,
                 :silent,:pin_after_publish,:comments_enabled,:is_ad,:replacement_url,:link_preview_enabled,:link_preview_large,
                 :link_preview_above,:reply_chat_id,:reply_message_id,:repost_chat_id,:repost_message_id,
                 :repost_input_chat_id,:repost_input_message_id,:manager_category_id,:import_json)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $stmt->execute([
            'channel_id' => $channelId,
            'author_id' => $authorId,
            'text' => $post['text'] ?? null,
            'text_entities_json' => $post['text_entities_json'] ?? null,
            'autosignature_text'=>$post['autosignature_text']??null,
            'autosignature_entities_json'=>$post['autosignature_entities_json']??null,
            'media_type' => (string) ($post['media_type'] ?? 'none'),
            'media_file_id' => $post['media_file_id'] ?? null,
            'media_json' => $post['media_json'] ?? null,
            'buttons_json' => $post['buttons_json'] ?? null,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'delete_after_minutes' => $post['delete_after_minutes'] ?? null,
            'delete_report_enabled'=>(int)($post['delete_report_enabled']??0),
            'repeat_interval_minutes' => $post['repeat_interval_minutes'] ?? null,
            'repeat_until' => $post['repeat_until'] ?? null,
            'repeat_slot_at'=>$repeatSlotAt,
            'copied_from_id' => $copiedFromId,
            'silent' => (int) ($post['silent'] ?? 0),
            'pin_after_publish' => (int) ($post['pin_after_publish'] ?? 0),
            'comments_enabled' => (int) ($post['comments_enabled'] ?? 1),
            'is_ad'=>(int)($post['is_ad']??0),
            'replacement_url'=>$post['replacement_url']??null,
            'link_preview_enabled'=>$post['link_preview_enabled']??null,
            'link_preview_large'=>$post['link_preview_large']??null,
            'link_preview_above'=>$post['link_preview_above']??null,
            'reply_chat_id' => $post['reply_chat_id'] ?? null,
            'reply_message_id' => $post['reply_message_id'] ?? null,
            'repost_chat_id' => $post['repost_chat_id'] ?? null,
            'repost_message_id' => $post['repost_message_id'] ?? null,
            'repost_input_chat_id' => $post['repost_input_chat_id'] ?? null,
            'repost_input_message_id' => $post['repost_input_message_id'] ?? null,
            'manager_category_id'=>(int)($post['author_id']??0)===$authorId?($post['manager_category_id']??null):null,
            'import_json'=>$post['import_json']??null,
        ]);
        $postId=(int)$this->pdo->lastInsertId();
        // PostedDb uses affected-row counts (MYSQL_ATTR_FOUND_ROWS is off).
        // A replayed repeat slot is an existing row: never rebind its buttons
        // from an older source snapshot after the user edited that instance.
        if($postId>0&&$stmt->rowCount()===1){
            $buttons=$this->rebindInteractiveButtons($this->decodeJson((string)($post['buttons_json']??'[]')),$postId);
            $this->pdo->prepare('UPDATE posted_posts SET buttons_json=:buttons WHERE id=:id')
                ->execute(['buttons'=>$buttons!==[]?$this->encodeJson($buttons):null,'id'=>$postId]);
            PostedTelegramPostOptions::clonePostLocked($this->pdo,$post,['id'=>$postId,'channel_id'=>$channelId]);
            PostedTelegramTopHold::cloneLocked($this->pdo,$post,['id'=>$postId,'channel_id'=>$channelId]);
            if($status==='scheduled'&&$scheduledAt!==null){$topChannel=$this->channel($channelId);PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$topChannel,$this->post($postId),$scheduledAt);}
            $this->telegramMultipostCloneSelectionLocked($post,['id'=>$postId,'channel_id'=>$channelId,'author_id'=>$authorId]);
        }
        if($ownsOptionsTransaction)$this->pdo->commit();else$this->pdo->exec('RELEASE SAVEPOINT tgh_clone_write');
        return $postId;
        }catch(Throwable$error){if($ownsOptionsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();elseif($this->pdo->inTransaction()){$this->pdo->exec('ROLLBACK TO SAVEPOINT tgh_clone_write');$this->pdo->exec('RELEASE SAVEPOINT tgh_clone_write');}throw$error;}
    }

    private function showMainMenu(int $userId,bool $includeIcons=true): void
    {
        $support = $this->serviceAdminLabel();
        $newsUsername = $this->serviceNewsUsername();
        $text = $this->customEmojiHtml('5431446665070415240', '✅')
            . " <b>GitPosted</b> - это простой и удобный бот для отложенного постинга, поддерживающий работу с "
            . $this->customEmojiHtml('5258165702707125574', '⭐️')
            . " <b>анимированными эмодзи</b>.\n\n"
            . "<b>Бот позволяет: </b>\n\n"
            . $this->customEmojiHtml('5258419835922030550', '🕔') . " Планировать выход публикаций в ваших каналах\n"
            . $this->customEmojiHtml('5258130763148172425', '🗑') . " Автоматически удалять их по таймеру\n"
            . $this->customEmojiHtml('5258215635996908355', '👩‍🎨') . " Создавать и настраивать посты любого формата\n"
            . $this->customEmojiHtml('5258420634785947640', '🔄') . " Зацикливать публикации, добавлять кнопки и водяные знаки\n"
            . $this->customEmojiHtml('5260341314095947411', '👀') . " И многое другое\n\n"
            . ($newsUsername !== '' ? "<b>Новости сервиса -</b> @" . htmlspecialchars($newsUsername, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" : '')
            . "<b>Поддержка -</b> " . $support;
        if ($this->telegramUiLanguage($userId) === 'en') {
            $supportUsername = $this->serviceAdminUsername();
            $text = PostedTelegramUi::englishHtml('main.body', ['brand'=>'GitPosted']) . "\n\n";
            if ($newsUsername !== '') $text .= PostedTelegramUi::englishHtml('main.news', ['news'=>'@'.$newsUsername]) . "\n";
            $text .= PostedTelegramUi::englishHtml('main.support', ['support'=>$supportUsername !== '' ? '@'.$supportUsername : 'контакт владельца пока не указан']);
        }
        $this->telegram->sendMessage($userId, $text, [
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'keyboard' => PostedTelegramUi::mainKeyboard(self::MAIN_KEYBOARD, $this->telegramUiLanguage($userId),$includeIcons),
                'resize_keyboard' => true,
            ],
        ]);
    }

    private function showChannelPicker(int $userId, string $prefix, ?array $target = null): void
    {
        $channels = $this->allowedChannels($userId, 'post');
        if ($channels === []) {
            $this->telegram->sendMessage($userId, 'Нет доступных каналов. Добавьте бота администратором в канал.');
            return;
        }
        if ($prefix === 'post_channel' && count($channels) === 1) {
            $channel = $channels[0];
            $channelId = (int) $channel['id'];
            $this->startPostForChannel($userId,$channelId,$target);
            return;
        }

        $keyboard = [];
        if($prefix==='post_channel'){
            $folders=$this->pdo->prepare('SELECT f.* FROM posted_folders f WHERE f.owner_id=:owner AND EXISTS(SELECT 1 FROM posted_folder_channels fc WHERE fc.folder_id=f.id) ORDER BY f.sort_order,f.id');
            $folders->execute(['owner'=>$userId]);
            foreach($folders->fetchAll() as $folder){
                $keyboard[]=[['text'=>'📂 '.(string)$folder['title'],'callback_data'=>'post_folder:'.(int)$folder['id']]];
            }
        }
        $channelButtons=[];
        foreach ($channels as $channel) {
            $channelButtons[] = ['text' => (string) $channel['title'], 'callback_data' => $prefix . ':' . (int) $channel['id']];
        }
        foreach (array_chunk($channelButtons, 2) as $row) $keyboard[]=$row;
        $keyboard[] = [['text' => $this->telegramUiText($userId, 'button.add_channel', '➕ Добавить новый канал'), 'callback_data' => 'settings:add_channel']];
        $this->sendOrEditMessage($userId, $this->telegramUiText($userId, 'create.body', $this->customEmojiHtml('5260652420052032852', '⬆️') . " <b>СОЗДАНИЕ ПОСТА</b>\n\nВыберите канал, в котором хотите создать публикацию."), [
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ], $target);
    }

    private function managerCategory(int $userId,int $channelId,int $categoryId,bool $activeOnly=false): ?array
    {
        if($categoryId<=0)return null;
        $stmt=$this->pdo->prepare('SELECT * FROM posted_manager_categories WHERE id=:id AND user_id=:user AND channel_id=:channel'.($activeOnly?' AND is_active=1':'').' LIMIT 1');
        $stmt->execute(['id'=>$categoryId,'user'=>$userId,'channel'=>$channelId]);$row=$stmt->fetch();
        return is_array($row)?$row:null;
    }

    private function resolveTelegramManagerCategoryLocked(
        int $userId,
        int $channelId,
        int $requestedCategoryId,
        array $permission
    ): ?int {
        if(!$this->pdo->inTransaction()){
            throw new RuntimeException('Выбор рекламной компании требует активной транзакции.');
        }
        $isManager=!empty($permission['is_ad_manager']);
        $isOwner=$this->channelOwnerId($channelId)===$userId;
        if($requestedCategoryId>0){
            if(!$isManager&&!$isOwner){
                throw new RuntimeException('Рекламная компания больше недоступна.');
            }
            $category=$this->pdo->prepare(
                'SELECT id FROM posted_manager_categories
                 WHERE id=:category AND user_id=:user AND channel_id=:channel
                   AND is_active=1
                 LIMIT 1 FOR UPDATE'
            );
            $category->execute([
                'category'=>$requestedCategoryId,
                'user'=>$userId,
                'channel'=>$channelId,
            ]);
            if($category->fetchColumn()===false){
                throw new RuntimeException('Рекламная компания больше недоступна.');
            }
            return $requestedCategoryId;
        }
        // A transferred owner can retain historical advertising bookkeeping
        // while creating ordinary owner posts without selecting a quota bucket.
        if($isOwner)return null;
        if($isManager){
            $category=$this->pdo->prepare(
                'SELECT id FROM posted_manager_categories
                 WHERE user_id=:user AND channel_id=:channel AND is_active=1
                 ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $category->execute(['user'=>$userId,'channel'=>$channelId]);
            if($category->fetchColumn()!==false){
                throw new RuntimeException('Выберите рекламную компанию перед созданием поста.');
            }
        }
        return null;
    }

    private function activeTelegramManagerCategories(int $userId,int $channelId):array
    {
        if($this->channelOwnerId($channelId)===$userId)return [];
        $stmt=$this->pdo->prepare(
            'SELECT category.* FROM posted_manager_categories category
             INNER JOIN posted_permissions permission
               ON permission.user_id=category.user_id
              AND permission.channel_id=category.channel_id
              AND permission.is_ad_manager=1
             WHERE category.user_id=:user AND category.channel_id=:channel
               AND category.is_active=1
             ORDER BY category.id'
        );
        $stmt->execute(['user'=>$userId,'channel'=>$channelId]);
        return $stmt->fetchAll();
    }

    private function createOrReactivateManagerCategoryLocked(
        int $managerId,
        int $channelId,
        string $name
    ): int {
        if($managerId<=0||$channelId<=0||$name===''||!$this->pdo->inTransaction()){
            throw new RuntimeException('Создание рекламной компании требует активной транзакции.');
        }
        $existing=$this->pdo->prepare(
            'SELECT id,is_active FROM posted_manager_categories
             WHERE user_id=:user AND channel_id=:channel AND name=:name
             LIMIT 1 FOR UPDATE'
        );
        $existing->execute(['user'=>$managerId,'channel'=>$channelId,'name'=>$name]);
        $category=$existing->fetch();
        if(is_array($category)){
            $categoryId=(int)$category['id'];
            if(!empty($category['is_active']))return $categoryId;
            $busy=$this->pdo->prepare(
                "SELECT 1
                 FROM posted_telegram_publish_attempts attempt
                 INNER JOIN posted_posts post ON post.id=attempt.post_id
                 WHERE post.author_id=:user AND post.channel_id=:channel
                   AND post.manager_category_id=:category
                   AND (
                     attempt.state<>'completed'
                     OR attempt.gate_channel_id IS NOT NULL
                     OR attempt.pin_completed_at IS NULL
                   )
                 LIMIT 1 FOR UPDATE"
            );
            $busy->execute(['user'=>$managerId,'channel'=>$channelId,'category'=>$categoryId]);
            if($busy->fetchColumn()!==false){
                throw new RuntimeException(
                    'По этой компании ещё завершается публикация или закрепление. Повторите создание позже.'
                );
            }
            $this->pdo->prepare(
                'UPDATE posted_manager_categories
                 SET post_quota_remaining=0,pin_quota_remaining=0,is_active=1
                 WHERE id=:id AND user_id=:user AND channel_id=:channel AND is_active=0'
            )->execute(['id'=>$categoryId,'user'=>$managerId,'channel'=>$channelId]);
            return $categoryId;
        }
        $insert=$this->pdo->prepare(
            'INSERT INTO posted_manager_categories(
                user_id,channel_id,name,post_quota_remaining,pin_quota_remaining,is_active
             ) VALUES(:user,:channel,:name,0,0,1)'
        );
        $insert->execute(['user'=>$managerId,'channel'=>$channelId,'name'=>$name]);
        $categoryId=(int)$this->pdo->lastInsertId();
        if($categoryId<=0)throw new RuntimeException('Не удалось создать рекламную компанию.');
        return $categoryId;
    }

    private function startPostForChannel(int $userId,int $channelId,?array $target=null): void
    {
        $categories=$this->activeTelegramManagerCategories($userId,$channelId);
        if($categories!==[]){
            $rows=[];foreach($categories as $category){
                $label=(string)$category['name'].' · '.$this->managerQuotaLabel($category['post_quota_remaining']??null,'постов').' / '.$this->managerQuotaLabel($category['pin_quota_remaining']??null,'закрепов');
                $rows[]=[['text'=>mb_substr($label,0,60),'callback_data'=>'post_category:'.$channelId.':'.(int)$category['id']]];
            }
            $rows[]=[['text'=>'← Выбрать другой канал','callback_data'=>'post_channels']];
            $this->sendOrEditMessage($userId,"Выберите рекламную компанию. Лимиты будут списаны именно с неё.",['reply_markup'=>['inline_keyboard'=>$rows]],$target);return;
        }
        $this->setState($userId,'await_post_content',['channel_id'=>$channelId]);
        $channel=$this->channel($channelId) ?? ['title'=>$this->channelTitle($channelId)];
        $this->sendOrEditMessage($userId,'Отправьте боту то, что хотите опубликовать в канале '.$this->channelTitleHtml($channel).'.',['parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>[[['text'=>'← Выбрать другой канал','callback_data'=>'post_channels']]]]],$target);
    }

    private function showDraftActions(int $userId, int $postId, string $prefixText = '', ?array $target = null): void
    {
        $post = $this->post($postId);
        if (!$post) {
            $this->telegram->sendMessage($userId, 'Черновик не найден.');
            return;
        }
        $this->sendDraftPreview($userId, $post, $this->draftMainKeyboard($post, !empty($target['draft_ui_expanded'])), $prefixText, $target);
        try{$this->telegramUnknownSourceSpoilerNotice($userId,$post);}catch(Throwable$error){error_log('GitPosted spoiler hint unavailable: '.get_class($error));}
    }

    private function draftMainKeyboard(array $post, bool $expanded = false): array
    {
        $postId = (int) $post['id'];
        $silentButton = !empty($post['silent']) ? '🔕' : '🔔';
        if ($this->isNativeRepost($post)) {
            $pinButton = !empty($post['pin_after_publish']) ? '✅ Закрепить' : '☑️ Закрепить';
            $keyboard = [];
            $batchImport=$this->decodeJson((string)($post['import_json']??''));
            if((array_key_exists('gitposted_multipost_forward',$batchImport)||array_key_exists('gitposted_multipost_forward_fork',$batchImport))&&$this->hasStoredRepostCopy($post)){
                $keyboard[]=[['text'=>'📝 Сделать копией для редактирования','callback_data'=>'draft:convert_copy:'.$postId]];
            } elseif (!empty($post['import_json'])) {
                array_push($keyboard, ...$this->telegramPostLinkDraftRows($post));
            } elseif ($this->hasStoredRepostCopy($post)) {
                $keyboard[] = [[
                    'text' => '📝 Сделать копией для редактирования',
                    'callback_data' => 'draft:convert_copy:' . $postId,
                ]];
            }
            $keyboard[] = [
                ['text' => $silentButton, 'callback_data' => 'draft:silent:' . $postId . ':' . (empty($post['silent'])?1:0)],
                ['text' => $pinButton, 'callback_data' => 'draft:pin:' . $postId . ':' . (empty($post['pin_after_publish'])?1:0)],
            ];
            $keyboard[]=$expanded?[$this->telegramTopHoldPostButton($post,'d')]:[['text'=>'↓ Больше настроек','callback_data'=>'draft:more:'.$postId]];
            $keyboard[] = [
                ['text' => '← Отменить', 'callback_data' => 'draft:cancel:' . $postId],
                ['text' => 'Далее →', 'callback_data' => 'draft:next:' . $postId],
            ];
            return $keyboard;
        }
        $nativeKeyboard=$this->telegramNativeDraftKeyboard($post,$expanded);if($nativeKeyboard!==null)return$nativeKeyboard;
        $buttonsAllowed=$this->telegramPostInlineButtonsAllowed($post);
        $buttons = $buttonsAllowed?$this->decodeJson((string) ($post['buttons_json'] ?? '[]')):[];
        $isMediaPost = (string) ($post['media_type'] ?? 'none') !== 'none';
        $textButton = $isMediaPost ? (trim((string)($post['text']??''))===''?'Добавить описание':'Изменить описание') : 'Изменить текст';
        $mediaButton = $isMediaPost ? 'Медиа' : 'Добавить медиа';
        $previewDetach = $this->telegramPreviewMediaEligible($post) ? $this->telegramPreviewMediaDetachCallback($post) : null;
        if ($previewDetach !== null) $mediaButton = 'Открепить медиа';
        $mediaCallback = $previewDetach ?? ('draft:add_media:' . $postId);
        $hasTextUrl =
            preg_match('~https?://\S+~u', (string) ($post['text'] ?? '')) === 1
            ||preg_match(
                '~https?://\S+~u',
                (string)($post['autosignature_text']??'')
            )===1;
        if(!$hasTextUrl){
            foreach(['text_entities_json','autosignature_entities_json'] as $entitiesColumn){
                foreach($this->decodeJson((string)($post[$entitiesColumn]??'')) as $entity){
                    if(
                        is_array($entity)
                        &&in_array((string)($entity['type']??''),['url','text_link'],true)
                    ){
                        $hasTextUrl=true;
                        break 2;
                    }
                }
            }
        }

        $keyboard = [];
        if ($hasTextUrl && !$isMediaPost) {
            $keyboard[] = [['text'=>$textButton,'callback_data'=>'draft:edit_text:'.$postId],['text'=>'Превью','callback_data'=>'draft:preview:'.$postId]];
            $keyboard[] = [['text'=>$mediaButton,'callback_data'=>$mediaCallback]];
        } else {
            $keyboard[] = $isMediaPost
                ?[['text'=>$mediaButton,'callback_data'=>$mediaCallback],['text'=>$textButton,'callback_data'=>'draft:edit_text:'.$postId]]
                :[['text'=>$textButton,'callback_data'=>'draft:edit_text:'.$postId],['text'=>$mediaButton,'callback_data'=>$mediaCallback]];
        }
        $silentRow=[['text'=>$silentButton,'callback_data'=>'draft:silent:'.$postId.':'.(empty($post['silent'])?1:0)]];
        if($buttonsAllowed)$silentRow[]=['text'=>'Кнопки','callback_data'=>'draft:buttons:'.$postId];
        $keyboard[]=$silentRow;
        if ($this->hasInlineButtons($buttons)) {
            $keyboard[] = [['text' => '🎨 Раскрасить кнопки', 'callback_data' => 'draft:paintopen:' . $postId . ':danger']];
        }
        $hasUrlButtons = false;
        foreach ($buttons as $row) foreach ((array) $row as $button) if (is_array($button) && !empty($button['url'])) $hasUrlButtons = true;
        $hasAnyUrl=$hasTextUrl||$hasUrlButtons;
        if ($hasAnyUrl) {
            $keyboard[] = [['text' => 'Замена ссылок', 'callback_data' => 'draft:replace_links:' . $postId]];
            $keyboard[] = [['text' => (!empty($post['is_ad']) ? '✅️' : '☑️') . ' Это рекламный пост', 'callback_data' => 'draft:ad:' . $postId . ':' . (empty($post['is_ad'])?1:0)]];
        }
        $pinButton = !empty($post['pin_after_publish']) ? '✅ Закрепить' : '☑️ Закрепить';
        $replyButton = !empty($post['reply_message_id']) ? 'Ответный пост: да' : 'Ответный пост: нет';
        $keyboard[] = [['text'=>$pinButton,'callback_data'=>'draft:pin:'.$postId.':'.(empty($post['pin_after_publish'])?1:0)]];
        $keyboard[] = [['text'=>$replyButton,'callback_data'=>'draft:reply:'.$postId]];
        if ($isMediaPost) {
            $keyboard[] = [['text'=>'Открепить медиа','callback_data'=>'draft:remove_media:'.$postId]];
        }
        array_push($keyboard, ...$this->telegramPostLinkDraftRows($post));
        $keyboard[]=[$this->telegramTopHoldPostButton($post,'d')];
        $keyboard[] = [['text' => '← Отменить', 'callback_data' => 'draft:cancel:' . $postId], ['text' => 'Далее →', 'callback_data' => 'draft:next:' . $postId]];
        return $this->telegramDraftEditorKeyboard($post, $keyboard, $expanded);
    }

    private function showDraftMoreSettings(int $userId, int $postId, ?array $target = null): void
    {
        $this->showTelegramDraftMoreSettings($userId, $postId, $target);
    }

    private function publicationPlanSource(int $userId, int $postId): ?array
    {
        $post = $this->post($postId);
        if (!$post || !in_array((string)$post['status'], ['draft','scheduled','failed','sent'], true)
            || !$this->canManagePost($userId, $postId)
            || !$this->can($userId, (int)$post['channel_id'], 'post')
            || !$this->can($userId, (int)$post['channel_id'], 'schedule')) return null;
        return $post;
    }

    private function savePublicationPlan(int $userId, array $plan): array
    {
        $plan = $this->syncPublicationPlanCalendar($userId, $plan);
        $previousToken = (string)($plan['token'] ?? '');
        $plan['token'] = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            "UPDATE posted_users SET state_data=:data WHERE telegram_id=:user
             AND state='await_publication_plan'
             AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.token'))=:token"
        );
        $stmt->execute(['data'=>$this->encodeJson($plan),'user'=>$userId,'token'=>$previousToken]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Меню устарело. Откройте серию публикаций заново.');
        return $plan;
    }

    private function publicationPlanSlots(array $dates, array $plan): array
    {
        $previous = [];
        foreach ((array)($plan['slots'] ?? []) as $slot) {
            $previous[(string)($slot['at'] ?? '')] = $slot;
        }
        return array_map(static fn(string $date): array => $previous[$date] ?? [
            'at'=>$date, 'pin'=>(int)($plan['default_pin'] ?? 0),
            'delete'=>(int)($plan['default_delete'] ?? 0),
        ], $dates);
    }

    private function syncPublicationPlanCalendar(int $userId, array $plan): array
    {
        $days = [];
        foreach ((array)($plan['slots'] ?? []) as $slot) {
            $local = $this->storedDateForUser($userId, (string)($slot['at'] ?? ''));
            if ($local !== null) $days[$local->format('Y-m-d')] = true;
        }
        $plan['calendar_days'] = array_keys($days);
        sort($plan['calendar_days'], SORT_STRING);
        return $plan;
    }

    private function handlePublicationPlanCallback(int $userId, string $callback, ?array $target): void
    {
        $parts = explode(':', $callback);
        $action = (string)($parts[1] ?? '');
        $postId = (int)($parts[2] ?? 0);
        $source = $this->publicationPlanSource($userId, $postId);
        if (!$source) {
            $this->telegram->sendMessage($userId, 'Пост недоступен для создания серии или нет права планирования.');
            return;
        }
        if ($action === 'open') {
            if ((string)$source['status']==='draft' && (int)$source['author_id']!==$userId) {
                $this->telegram->sendMessage($userId,'Для серии нужен ваш черновик. Сначала создайте свою копию кнопкой «Дублировать».',[
                    'reply_markup'=>['inline_keyboard'=>[[['text'=>'Дублировать','callback_data'=>'cp:dup:'.$postId]]]],
                ]);
                return;
            }
            $user = $this->user($userId);
            $existing = $this->stateData($user);
            if (($user['state'] ?? '') === 'await_publication_plan'
                && (int)($existing['post_id'] ?? 0) === $postId
                && (string)($existing['source_status'] ?? '') === (string)$source['status']
                && preg_match('/^[a-f0-9]{12}$/D', (string)($existing['token'] ?? '')) === 1) {
                $this->showPublicationPlan($userId, $existing, $target);
                return;
            }
            $plan = [
                'post_id'=>$postId, 'source_status'=>(string)$source['status'],
                'token'=>bin2hex(random_bytes(6)), 'mode'=>'home', 'slots'=>[],
                'default_pin'=>(int)($source['pin_after_publish'] ?? 0),
                'default_delete'=>$this->normalizeTelegramDeleteTimerMinutes((int)($source['delete_after_minutes'] ?? 0)),
                'calendar_days'=>[], 'calendar_time'=>'18:30',
                'calendar_month'=>(new DateTimeImmutable('now',$this->userTimezoneObject($userId)))->format('Ym'),
            ];
            $this->setState($userId, 'await_publication_plan', $plan);
            $this->showPublicationPlan($userId, $plan, $target);
            return;
        }
        $user = $this->user($userId);
        $plan = $this->stateData($user);
        if (($user['state'] ?? '') !== 'await_publication_plan' || (int)($plan['post_id'] ?? 0) !== $postId
            || !hash_equals((string)($plan['token'] ?? ''), (string)($parts[3] ?? ''))) {
            if (($user['state'] ?? '') === 'await_publication_plan'
                && (int)($plan['post_id'] ?? 0) === $postId
                && (string)($plan['source_status'] ?? '') === (string)$source['status']
                && preg_match('/^[a-f0-9]{12}$/D', (string)($plan['token'] ?? '')) === 1) {
                // Recover the current keyboard when a previous response was lost.
                // Never apply an action carried by an old token, including confirm.
                $this->showPublicationPlan($userId, $plan, $target);
                return;
            }
            $this->telegram->sendMessage($userId, 'Эта кнопка устарела. Используйте последнее меню серии.');
            return;
        }
        try {
            if ($action === 'confirm') {
                $this->confirmPublicationPlan($userId, $plan, $target);
                return;
            }
            if ($action === 'cancel') {
                $stmt = $this->pdo->prepare(
                    "UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user
                     AND state='await_publication_plan'
                     AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.token'))=:token"
                );
                $stmt->execute(['user'=>$userId,'token'=>$plan['token']]);
                if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Меню уже изменилось.');
                if ((string)$source['status'] === 'draft') $this->showDraftSendSettings($userId,$postId,$target);
                else $this->showManagedPost($userId,$postId);
                return;
            }
            if (in_array($action, ['home','period','list','calendar','calendar_time','preview'], true)) {
                $plan['mode']=$action;
            } elseif ($action === 'month') {
                $month=(string)($parts[4] ?? '');
                $date=DateTimeImmutable::createFromFormat('!Ym',$month,$this->userTimezoneObject($userId));
                if (!$date || $date->format('Ym') !== $month) return;
                $plan['calendar_month']=$month;
                $plan['mode']='calendar';
            } elseif ($action === 'day') {
                $raw=(string)($parts[4] ?? '');
                $date=DateTimeImmutable::createFromFormat('!Ymd',$raw,$this->userTimezoneObject($userId));
                if (!$date || $date->format('Ymd') !== $raw) return;
                $day=$date->format('Y-m-d');
                $plan=$this->syncPublicationPlanCalendar($userId,$plan);
                if (in_array($day,$plan['calendar_days'],true)) {
                    $plan['slots']=array_values(array_filter((array)$plan['slots'],
                        fn(array $slot): bool => $this->formatStoredForUser($userId,$slot['at'],'Y-m-d')!==$day));
                } else {
                    $at=PostedPublicationPlan::one($day.' '.$plan['calendar_time'],$this->userTimezoneObject($userId),$this->storageTimezone());
                    if (count((array)$plan['slots']) >= PostedPublicationPlan::MAX_SLOTS) throw new InvalidArgumentException('В серии допускается не больше 366 публикаций.');
                    $plan['slots'][]=$this->publicationPlanSlots([$at],$plan)[0];
                    usort($plan['slots'],static fn(array $a,array $b): int => strcmp($a['at'],$b['at']));
                }
                $plan['mode']='calendar';
            } elseif ($action === 'calendar_preview') {
                if ((array)($plan['slots'] ?? []) === []) throw new InvalidArgumentException('Сначала отметьте даты в календаре.');
                $plan['mode']='preview';
                $plan['page']=0;
            } elseif ($action === 'page') {
                $plan['page']=max(0,(int)($parts[4] ?? 0));
                $plan['mode']='preview';
            } elseif (in_array($action, ['slot','pin','timer','slot_time','slot_delete','remove'], true)) {
                $index=(int)($parts[4] ?? -1);
                if (!isset($plan['slots'][$index])) return;
                $plan['selected']=$index;
                $plan['mode']='slot';
                if ($action === 'pin') $plan['slots'][$index]['pin']=empty($plan['slots'][$index]['pin'])?1:0;
                if ($action === 'timer') {
                    $value=(string)($parts[5] ?? '');
                    if (!in_array($value,['0','60','360','720','1440','2875','4320','10080'],true)) return;
                    $plan['slots'][$index]['delete']=(int)$value;
                }
                if (in_array($action,['slot_time','slot_delete'],true)) $plan['mode']=$action;
                if ($action === 'remove') {
                    array_splice($plan['slots'],$index,1);
                    $plan['mode']='preview';
                }
            } else return;
            $plan=$this->savePublicationPlan($userId,$plan);
            $this->showPublicationPlan($userId,$plan,$target);
        } catch (InvalidArgumentException $exception) {
            $this->telegram->sendMessage($userId,$exception->getMessage());
        }
    }

    private function handlePublicationPlanInput(int $userId, array $plan, string $text): void
    {
        if ($text === 'Отмена') {
            $this->handlePublicationPlanCallback($userId,'series:cancel:'.(int)($plan['post_id'] ?? 0).':'.(string)($plan['token'] ?? ''),null);
            return;
        }
        if (!$this->publicationPlanSource($userId,(int)($plan['post_id'] ?? 0))) {
            $this->telegram->sendMessage($userId,'Пост или право планирования уже недоступны.');
            return;
        }
        try {
            $timezone=$this->userTimezoneObject($userId);
            $mode=(string)($plan['mode'] ?? '');
            if ($mode === 'period') {
                $lines=preg_split('/\s*[;\n|]\s*/u',trim($text),-1,PREG_SPLIT_NO_EMPTY);
                if (count($lines)!==3 || preg_match('/^\d{1,3}$/D',$lines[2])!==1) {
                    throw new InvalidArgumentException("Пришлите три строки: начало с временем, последняя дата, шаг в днях. Например:\n25.10.2026 18:30\n15.12.2026\n2");
                }
                $dates=PostedPublicationPlan::period($lines[0],$lines[1],(int)$lines[2],$timezone,$this->storageTimezone());
                $plan['slots']=$this->publicationPlanSlots($dates,$plan);
                $plan['mode']='preview';
                $plan['page']=0;
            } elseif ($mode === 'list') {
                $dates=PostedPublicationPlan::dates($text,$timezone,$this->storageTimezone());
                $plan['slots']=$this->publicationPlanSlots($dates,$plan);
                $plan['mode']='preview';
                $plan['page']=0;
            } elseif ($mode === 'calendar_time') {
                if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D',$text)!==1) throw new InvalidArgumentException('Отправьте время в формате ЧЧ:ММ, например 18:30.');
                $slots=[];
                $seen=[];
                foreach ((array)($plan['slots'] ?? []) as $slot) {
                    $day=$this->formatStoredForUser($userId,$slot['at'],'Y-m-d');
                    $at=PostedPublicationPlan::one($day.' '.$text,$timezone,$this->storageTimezone());
                    if (isset($seen[$at])) throw new InvalidArgumentException('На один день выбрано несколько экземпляров. Общее время сделает их одинаковыми; измените время каждого экземпляра отдельно.');
                    $seen[$at]=true;
                    $slot['at']=$at;
                    $slots[]=$slot;
                }
                usort($slots,static fn(array $a,array $b): int => strcmp($a['at'],$b['at']));
                $plan['slots']=$slots;
                $plan['calendar_time']=$text;
                $plan['mode']='calendar';
            } elseif ($mode === 'slot_time') {
                $index=(int)($plan['selected'] ?? -1);
                if (!isset($plan['slots'][$index])) throw new InvalidArgumentException('Выберите экземпляр заново.');
                $date=PostedPublicationPlan::one($text,$timezone,$this->storageTimezone());
                foreach ($plan['slots'] as $other=>$slot) {
                    if ($other!==$index && $slot['at']===$date) throw new InvalidArgumentException('На эту дату и время уже есть экземпляр в серии.');
                }
                $plan['slots'][$index]['at']=$date;
                $plan['mode']='slot';
            } elseif ($mode === 'slot_delete') {
                $index=(int)($plan['selected'] ?? -1);
                if (!isset($plan['slots'][$index])) throw new InvalidArgumentException('Выберите экземпляр заново.');
                if (mb_strlen($text)>40 || preg_match('/\d{7}/',$text)===1) throw new InvalidArgumentException('Срок удаления не должен превышать 365 дней.');
                $minutes=$this->parseDeleteTimerMinutes($text);
                if ($minutes===null || $minutes<0 || $minutes>525600) throw new InvalidArgumentException('Укажите срок: 6, 6:30, 2д или «нет». Максимум 365 дней.');
                $plan['slots'][$index]['delete']=$minutes;
                $plan['mode']='slot';
            } else {
                $this->telegram->sendMessage($userId,'Выберите способ ввода или экземпляр кнопкой в меню серии.');
                return;
            }
            $plan=$this->savePublicationPlan($userId,$plan);
            $this->showPublicationPlan($userId,$plan);
        } catch (InvalidArgumentException $exception) {
            $this->telegram->sendMessage($userId,$exception->getMessage());
        }
    }

    private function showPublicationPlan(int $userId, array $plan, ?array $target=null): void
    {
        $plan=$this->syncPublicationPlanCalendar($userId,$plan);
        $postId=(int)$plan['post_id'];
        $prefix='series:';
        $button=static fn(string $label,string $action,string $extra=''): array => [
            'text'=>$label,'callback_data'=>$prefix.$action.':'.$postId.':'.$plan['token'].($extra!==''?':'.$extra:''),
        ];
        $mode=(string)($plan['mode'] ?? 'home');
        $slots=(array)($plan['slots'] ?? []);
        $keyboard=[];
        $text="📅 СЕРИЯ ПУБЛИКАЦИЙ\n\n";
        if ($mode === 'home') {
            $text.="Выберите период с шагом в днях, введите отдельные даты или отметьте дни в календаре. Для каждого выхода можно отдельно задать закреп и удаление.\n\n"
                .((string)$plan['source_status']==='draft'
                    ?'Черновик станет первым экземпляром; остальные будут отдельными публикациями.'
                    :'Будут созданы дополнительные публикации. Исходный пост и его автоповтор сохраняются.');
            $keyboard=[[$button('↔️ Период и шаг','period')],[$button('📝 Список дат и времени','list')],[$button('🗓 Календарь','calendar')]];
            if ($slots!==[]) $keyboard[]=[$button('Экземпляры: '.count($slots),'preview')];
        } elseif ($mode === 'period') {
            $example=(new DateTimeImmutable('tomorrow',$this->userTimezoneObject($userId)))->format('d.m.Y');
            $text.="Отправьте три строки:\n1. Дата и время первого выхода\n2. Последняя дата периода включительно\n3. Шаг в календарных днях\n\nНапример:\n".$example." 18:30\n".(new DateTimeImmutable('+2 months',$this->userTimezoneObject($userId)))->format('d.m.Y')."\n2\n\nМаксимум 366 выходов; прошедшее время не допускается.";
        } elseif ($mode === 'list') {
            $example=(new DateTimeImmutable('tomorrow',$this->userTimezoneObject($userId)))->format('d.m.Y');
            $text.="Отправьте даты с временем, каждую с новой строки:\n".$example." 10:00\n".$example." 18:30\n\nМаксимум 366 выходов. Все даты и время — в вашем часовом поясе.";
        } elseif ($mode === 'calendar_time') {
            $text.='Отправьте общее время: ЧЧ:ММ, например 18:30. Это изменит время всех выбранных экземпляров и задаст время для новых дат. Закреп и удаление сохранятся. Позже время каждого экземпляра можно изменить отдельно.';
            $keyboard[]=[$button('← К календарю','calendar')];
        } elseif ($mode === 'calendar') {
            $month=DateTimeImmutable::createFromFormat('!Ym',(string)$plan['calendar_month'],$this->userTimezoneObject($userId));
            if (!$month) return;
            $selected=(array)($plan['calendar_days'] ?? []);
            $text.='Нажмите на дни, чтобы добавить публикации; повторное нажатие убирает все экземпляры этого дня. Время для новых дат: '.$plan['calendar_time'].".\nВыбрано дней: ".count($selected).'. Экземпляров: '.count($slots).'.';
            $keyboard[]=[
                $button('←','month',$month->modify('-1 month')->format('Ym')),
                ['text'=>$month->format('m.Y'),'callback_data'=>'noop'],
                $button('→','month',$month->modify('+1 month')->format('Ym')),
            ];
            $keyboard[]=array_map(static fn(string $day): array=>['text'=>$day,'callback_data'=>'noop'],['Пн','Вт','Ср','Чт','Пт','Сб','Вс']);
            $row=array_fill(0,(int)$month->format('N')-1,['text'=>'·','callback_data'=>'noop']);
            for ($day=1;$day<=(int)$month->format('t');$day++) {
                $date=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),$day);
                $row[]=$button((in_array($date->format('Y-m-d'),$selected,true)?'✓':'').$day,'day',$date->format('Ymd'));
                if (count($row)===7) {$keyboard[]=$row;$row=[];}
            }
            if ($row!==[]) $keyboard[]=$row;
            $keyboard[]=[$button('🕒 Общее время: '.$plan['calendar_time'],'calendar_time')];
            $keyboard[]=[$button('Посмотреть экземпляры','calendar_preview')];
        } elseif (in_array($mode,['slot','slot_time','slot_delete'],true)) {
            $index=(int)($plan['selected'] ?? -1);
            if (!isset($slots[$index])) return;
            $slot=$slots[$index];
            $text.='Экземпляр '.($index+1).' · '.$this->formatStoredForUser($userId,$slot['at'],'d.m.Y H:i')
                ."\nЗакреп: ".(!empty($slot['pin'])?'да':'нет')."\nУдаление: ".$this->telegramDeleteTimerLabel((int)$slot['delete']);
            $text.="\n\n".$this->telegramDeleteTimerHint();
            if ($mode==='slot_time') $text.="\n\nОтправьте новую дату и время: ДД.ММ.ГГГГ ЧЧ:ММ.";
            if ($mode==='slot_delete') $text.="\n\nОтправьте срок: 6 — часов, 6:30, 2д или «нет».";
            $keyboard[]=[$button((empty($slot['pin'])?'☑️':'✅').' Закрепить','pin',(string)$index)];
            $keyboard[]=[$button('Изменить дату и время','slot_time',(string)$index)];
            foreach ([[0,60,360],[720,1440,2875]] as $values) {
                $row=[];
                foreach ($values as $value) {
                    $timerButton=$button($this->telegramDeleteTimerLabel($value),'timer',$index.':'.$value);
                    if($this->normalizeTelegramDeleteTimerMinutes((int)$slot['delete'])===$value)$timerButton['style']='primary';
                    $row[]=$timerButton;
                }
                $keyboard[]=$row;
            }
            $keyboard[]=[$button('Другой срок удаления','slot_delete',(string)$index)];
            $keyboard[]=[$button('Убрать этот экземпляр','remove',(string)$index)];
            $keyboard[]=[$button('← Все экземпляры','preview')];
        } elseif ($mode === 'preview') {
            $count=count($slots);
            $page=min(max(0,(int)($plan['page'] ?? 0)),max(0,(int)ceil($count/6)-1));
            $text.='Выходов: '.$count.". Выберите экземпляр, чтобы изменить время, закреп и удаление.\n\n"
                .((string)$plan['source_status']==='draft'
                    ?'Первый экземпляр использует исходный черновик. Автоповтор у всех экземпляров будет выключен.'
                    :'Создаются только дополнительные экземпляры; исходный пост и его повтор сохраняются.');
            foreach (array_slice($slots,$page*6,6,true) as $index=>$slot) {
                $label=($index+1).'. '.$this->formatStoredForUser($userId,$slot['at'],'d.m.Y H:i')
                    .' · '.(!empty($slot['pin'])?'📌':'без закрепа').' · 🗑 '.$this->telegramDeleteTimerLabel((int)$slot['delete']);
                $keyboard[]=[$button($label,'slot',(string)$index)];
            }
            $navigation=[];
            if ($page>0) $navigation[]=$button('←','page',(string)($page-1));
            if (($page+1)*6<$count) $navigation[]=$button('→','page',(string)($page+1));
            if ($navigation!==[]) $keyboard[]=$navigation;
            if ($count>0) $keyboard[]=[$button('✅ Запланировать '.$count.' публикаций','confirm')];
        }
        if ($mode!=='home') $keyboard[]=[$button('← Способы планирования','home')];
        $keyboard[]=[$button('Отменить серию','cancel')];
        $text.="\n\nЧасовой пояс: ".$this->userTimezone($userId).'. До подтверждения публикации не создаются.';
        $this->sendOrEditMessage($userId,$text,['reply_markup'=>['inline_keyboard'=>$keyboard]],$target);
    }

    private function confirmPublicationPlan(int $userId, array $plan, ?array $target): void
    {
        $postId=(int)$plan['post_id'];
        try {
            $ids=$this->withTelegramManagedPostLock(
                $userId,$postId,[(string)$plan['source_status']],
                function (array $source,array $channel,array $permission) use ($userId,$plan,$postId): array {
                    if ((string)$source['status']==='draft' && (int)$source['author_id']!==$userId) {
                        throw new InvalidArgumentException('Сначала создайте свою копию кнопкой «Дублировать», затем настройте серию.');
                    }
                    $userStmt=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=:user FOR UPDATE');
                    $userStmt->execute(['user'=>$userId]);
                    $user=$userStmt->fetch();
                    $current=$this->stateData(is_array($user)?$user:null);
                    if (($user['state'] ?? '')!=='await_publication_plan'
                        || !hash_equals((string)($current['token'] ?? ''),(string)$plan['token'])
                        || (int)($current['post_id'] ?? 0)!==$postId || ($current['mode'] ?? '')!=='preview') {
                        throw new InvalidArgumentException('Серия уже создана или меню устарело.');
                    }
                    $slots=(array)($current['slots'] ?? []);
                    if ($slots===[] || count($slots)>PostedPublicationPlan::MAX_SLOTS) throw new InvalidArgumentException('Выберите от 1 до 366 выходов.');
                    $seen=[];
                    foreach ($slots as $slot) {
                        $at=(string)($slot['at'] ?? '');
                        $local=$this->storedDateForUser($userId,$at);
                        if (!$local || PostedPublicationPlan::one($local->format('Y-m-d H:i'),$this->userTimezoneObject($userId),$this->storageTimezone())!==$at) {
                            throw new InvalidArgumentException('Проверьте даты: время одного из выходов уже прошло или недопустимо.');
                        }
                        if (isset($seen[$at])) throw new InvalidArgumentException('В серии повторяется дата и время.');
                        if ((string)$source['status']==='scheduled' && (string)$source['scheduled_at']===$at) throw new InvalidArgumentException('Исходный пост уже запланирован на одну из этих дат. Уберите этот экземпляр, чтобы не создать двойную публикацию.');
                        $seen[$at]=true;
                        if (!in_array((int)($slot['pin'] ?? -1),[0,1],true) || (int)($slot['delete'] ?? -1)<0 || (int)$slot['delete']>525600) throw new InvalidArgumentException('Проверьте закреп и срок удаления экземпляров.');
                    }
                    usort($slots,static fn(array $a,array $b): int=>strcmp($a['at'],$b['at']));
                    $sourceIsDraft=(string)$source['status']==='draft';
                    $categoryId=$this->resolveTelegramManagerCategoryLocked(
                        $userId,(int)$source['channel_id'],
                        (int)$source['author_id']===$userId?(int)($source['manager_category_id'] ?? 0):0,$permission
                    );
                    // Publication workers charge the counters. Account for already
                    // queued, uncharged work so this batch cannot use its allowance.
                    if ($userId!==(int)$channel['added_by'] && (!empty($permission['is_ad_manager']) || $categoryId!==null)) {
                        if ($categoryId!==null) {
                            $quota=$this->pdo->prepare('SELECT post_quota_remaining,pin_quota_remaining FROM posted_manager_categories WHERE id=:id AND is_active=1 FOR UPDATE');
                            $quota->execute(['id'=>$categoryId]);
                        } else {
                            $quota=$this->pdo->prepare('SELECT post_quota_remaining,pin_quota_remaining FROM posted_permissions WHERE user_id=:user AND channel_id=:channel FOR UPDATE');
                            $quota->execute(['user'=>$userId,'channel'=>$source['channel_id']]);
                        }
                        $limits=$quota->fetch();
                        if (!is_array($limits)) throw new InvalidArgumentException('Рекламная компания уже недоступна.');
                        $queued=$this->pdo->prepare(
                            "SELECT manager_post_quota_charged,manager_pin_quota_charged,pin_after_publish
                             FROM posted_posts WHERE channel_id=:channel AND author_id=:author
                               AND status IN ('scheduled','publishing','telegram_pending')
                               AND COALESCE(manager_category_id,0)=:category
                             FOR UPDATE"
                        );
                        $queued->execute(['channel'=>$source['channel_id'],'author'=>$userId,'category'=>$categoryId??0]);
                        $queuedPosts=0;
                        $queuedPins=0;
                        foreach ($queued->fetchAll() as $pending) {
                            if (empty($pending['manager_post_quota_charged'])) $queuedPosts++;
                            if (!empty($pending['pin_after_publish']) && empty($pending['manager_pin_quota_charged'])) $queuedPins++;
                        }
                        $posts=$queuedPosts+count($slots)-($sourceIsDraft&&!empty($source['manager_post_quota_charged'])?1:0);
                        $pins=$queuedPins+array_sum(array_column($slots,'pin'))-($sourceIsDraft&&!empty($source['manager_pin_quota_charged'])&&!empty($slots[0]['pin'])?1:0);
                        if (($limits['post_quota_remaining']!==null && $posts>(int)$limits['post_quota_remaining'])
                            || ($limits['pin_quota_remaining']!==null && $pins>(int)$limits['pin_quota_remaining'])) {
                            throw new InvalidArgumentException('Для серии недостаточно лимита публикаций или закрепов. Уменьшите серию либо измените лимит рекламной компании.');
                        }
                    }
                    $copy=$source;
                    $copy['author_id']=$userId;
                    $copy['manager_category_id']=$categoryId;
                    $copy['repeat_interval_minutes']=null;
                    $copy['repeat_until']=null;
                    $ids=[];
                    foreach ($slots as $index=>$slot) {
                        $copy['pin_after_publish']=(int)$slot['pin'];
                        $copy['delete_after_minutes']=(int)$slot['delete']>0?(int)$slot['delete']:null;
                        if ($sourceIsDraft && $index===0) {
                            PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$this->channel((int)$source['channel_id']),$source,$slot['at']);
                            $stmt=$this->pdo->prepare(
                                "UPDATE posted_posts SET status='scheduled',scheduled_at=:at,
                                 pin_after_publish=:pin,delete_after_minutes=:minutes,
                                 repeat_interval_minutes=NULL,repeat_until=NULL,repeat_slot_at=NULL,
                                 error_text=NULL WHERE id=:post AND status='draft'"
                            );
                            $stmt->execute(['at'=>$slot['at'],'pin'=>$copy['pin_after_publish'],'minutes'=>$copy['delete_after_minutes'],'post'=>$postId]);
                            if ($stmt->rowCount()!==1) throw new InvalidArgumentException('Черновик уже изменился.');
                            $ids[]=$postId;
                        } else {
                            $ids[]=$this->copyPost($copy,(int)$source['channel_id'],$userId,$slot['at'],'scheduled');
                        }
                    }
                    $this->clearState($userId);
                    return $ids;
                },true,false,true
            );
            if (!is_array($ids) || $ids===[]) throw new InvalidArgumentException('Пост или права изменились. Откройте серию заново.');
        } catch (Throwable $exception) {
            $this->telegram->sendMessage($userId,$exception instanceof InvalidArgumentException
                ?$exception->getMessage():'Не удалось создать серию. Публикации не добавлены; проверьте права и рекламную компанию, затем повторите.');
            if (!($exception instanceof InvalidArgumentException)) error_log('GitPosted publication series: '.$exception->getMessage());
            return;
        }
        $this->sendOrEditMessage($userId,'Запланировано отдельных публикаций: '.count($ids).'. Каждый экземпляр доступен в контент-плане; его дату, закреп и удаление можно изменить отдельно.',[
            'reply_markup'=>['inline_keyboard'=>[[['text'=>'Открыть первый экземпляр','callback_data'=>'cp:post:'.$ids[0]]]]],
        ],$target);
    }

    private function showDraftSendSettings(int $userId, int $postId, ?array $target = null): void
    {
        $post = $this->post($postId);
        if (!$post) {
            $this->telegram->sendMessage($userId, 'Черновик не найден.');
            return;
        }

        $sendChannel=$this->channel((int)$post['channel_id'])??['title'=>(string)($post['channel_title']??'')];
        $view=$this->telegramDraftSettingsPanelView($post,$sendChannel);
        if(PostedTelegramNativeContent::isKind($post['media_type']??null)){$controls=(array)($view['extra']['reply_markup']['inline_keyboard']??[]);$panel=$view;$panel['extra']=$view['extra'];unset($panel['extra']['reply_markup']);$this->sendTelegramNativePreview($userId,$post,$controls,'',$panel);return;}
        $bound=$this->showTelegramDraftBoundSettings($userId,$postId,$target,$view['text'],$view['extra']);
        if($bound!==null)return;
        $this->sendOrEditMessage($userId,$view['text'],$view['extra'],$target);
    }

    private function showDraftPreviewSettings(int $userId,int $postId,?array $target=null):void
    {
        $post=$this->post($postId); if(!$post)return;
        $keyboard=[
            [['text'=>(!empty($post['link_preview_enabled'])?'☑️':'') . ' Превью','callback_data'=>'draft:preview_toggle:'.$postId.':'.(empty($post['link_preview_enabled'])?1:0)]],
            [['text'=>(!empty($post['link_preview_large'])?'☑️':'') . ' Увеличить фото','callback_data'=>'draft:preview_large:'.$postId.':'.(empty($post['link_preview_large'])?1:0)]],
            [['text'=>'Расположение: '.(!empty($post['link_preview_above'])?'сверху':'снизу'),'callback_data'=>'draft:preview_position:'.$postId.':'.(empty($post['link_preview_above'])?1:0)]],
            [['text'=>'← Назад','callback_data'=>'draft:cancel_timer:'.$postId]],
        ];
        $payload=$this->effectivePostPayload($post);
        $extra=array_merge(
            $this->linkPreviewExtra($post),
            ['reply_markup'=>['inline_keyboard'=>$keyboard]]
        );
        if((array)($payload['entities']??[])!==[])$extra['entities']=$payload['entities'];
        $this->sendOrEditMessage($userId,(string)$payload['text'],$extra,$target);
    }

    private function showDraftButtonColorMenu(int $userId, int $postId, string $style = 'danger', ?array $target = null, bool $showColumns = true): void
    {
        $post = $this->post($postId);
        if (!$post) {
            $this->telegram->sendMessage($userId, 'Черновик не найден.');
            return;
        }

        $style = $this->normalizeButtonStyle($style);
        $buttons = $this->decodeJson((string) ($post['buttons_json'] ?? '[]'));
        if (!$this->hasInlineButtons($buttons)) {
            $this->telegram->sendMessage($userId, 'Сначала добавьте URL-кнопки.');
            return;
        }

        $keyboard = [];
        if ($showColumns) {
            $maxColumns = 0;
            foreach ($buttons as $row) $maxColumns = max($maxColumns, count((array) $row));
            if ($maxColumns > 0) {
                $columnRow = [['text'=>"\u{200B}",'callback_data'=>'draft:paint:'.$postId.':'.$style]];
                for ($columnIndex=0;$columnIndex<$maxColumns;$columnIndex++) $columnRow[]=['text'=>'🎨','callback_data'=>'draft:paintcol:'.$postId.':'.$style.':'.$columnIndex];
                $keyboard[]=$columnRow;
            }
        }
        foreach ($buttons as $rowIndex => $row) {
            $safeRow = [];
            $safeRow[] = [
                'text' => '🎨',
                'callback_data' => 'draft:paintrow:' . $postId . ':' . $style . ':' . (int) $rowIndex,
            ];
            foreach ((array) $row as $buttonIndex => $button) {
                if (!is_array($button)) {
                    continue;
                }
                $text = trim((string) ($button['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $safeRow[] = [
                    'text' => $text,
                    'callback_data' => 'draft:paintbtn:' . $postId . ':' . $style . ':' . (int) $rowIndex . ':' . (int) $buttonIndex,
                    ...$this->buttonStylePayload((string) ($button['style'] ?? '')),
                ];
            }
            if (count($safeRow) > 1) {
                $keyboard[] = $safeRow;
            }
        }

        $keyboard[] = [[
            'text' => '🎨 Закрасить все',
            'callback_data' => 'draft:paintall:' . $postId . ':' . $style,
        ]];
        $keyboard[] = [
            ['text' => '🔴', 'callback_data' => 'draft:paint:' . $postId . ':danger', ...($style === 'danger' ? ['style'=>'success'] : [])],
            ['text' => '🟢', 'callback_data' => 'draft:paint:' . $postId . ':success', ...($style === 'success' ? ['style'=>'success'] : [])],
            ['text' => '🔵', 'callback_data' => 'draft:paint:' . $postId . ':primary', ...($style === 'primary' ? ['style'=>'success'] : [])],
            ['text' => 'Без цвета', 'callback_data' => 'draft:paint:' . $postId . ':none', ...($style === 'none' ? ['style'=>'success'] : [])],
        ];
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'draft:cancel_timer:' . $postId]];

        $this->sendOrEditMessage(
            $userId,
            "👩‍🎨 РАСКРАСКА КНОПОК\n\n"
            . "В этом разделе вы можете выбрать цвет для кнопок.\n\n"
            . "• Чтобы выбрать цвет, нажмите на 1 из 4 кнопок внизу\n"
            . "• Чтобы покрасить кнопку, нажмите на неё\n"
            . "• Чтобы закрасить ряд или столбец, нажмите «🎨»",
            ['reply_markup' => ['inline_keyboard' => $keyboard]],
            $target
        );
    }

    private function paintDraftButton(int $userId, int $postId, string $style, int $rowIndex, int $buttonIndex, ?array $target = null): void
    {
        $this->updateDraftButtonsStyle($userId, $postId, $style, static function (array &$buttons) use ($rowIndex, $buttonIndex): void {
            if (isset($buttons[$rowIndex][$buttonIndex]) && is_array($buttons[$rowIndex][$buttonIndex])) {
                $buttons[$rowIndex][$buttonIndex]['__paint'] = true;
            }
        }, $target);
    }

    private function paintDraftButtonRow(int $userId, int $postId, string $style, int $rowIndex, ?array $target = null): void
    {
        $this->updateDraftButtonsStyle($userId, $postId, $style, static function (array &$buttons) use ($rowIndex): void {
            foreach ((array) ($buttons[$rowIndex] ?? []) as $buttonIndex => $button) {
                if (is_array($button)) {
                    $buttons[$rowIndex][$buttonIndex]['__paint'] = true;
                }
            }
        }, $target);
    }

    private function paintDraftButtonColumn(int $userId, int $postId, string $style, int $columnIndex, ?array $target = null): void
    {
        $this->updateDraftButtonsStyle($userId, $postId, $style, static function (array &$buttons) use ($columnIndex): void {
            foreach ($buttons as $rowIndex => $row) {
                if (isset($buttons[$rowIndex][$columnIndex]) && is_array($buttons[$rowIndex][$columnIndex])) {
                    $buttons[$rowIndex][$columnIndex]['__paint'] = true;
                }
            }
        }, $target);
    }

    private function paintDraftAllButtons(int $userId, int $postId, string $style, ?array $target = null): void
    {
        $this->updateDraftButtonsStyle($userId, $postId, $style, static function (array &$buttons): void {
            foreach ($buttons as $rowIndex => $row) {
                foreach ((array) $row as $buttonIndex => $button) {
                    if (is_array($button)) {
                        $buttons[$rowIndex][$buttonIndex]['__paint'] = true;
                    }
                }
            }
        }, $target);
    }

    private function updateDraftButtonsStyle(int $userId, int $postId, string $style, callable $marker, ?array $target = null): void
    {
        $style = $this->normalizeButtonStyle($style);
        $updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function (array $post) use ($postId, $style, $marker): bool {
                if(!$this->telegramPostInlineButtonsAllowed($post))return false;
                $buttons = $this->decodeJson((string) ($post['buttons_json'] ?? '[]'));
                $marker($buttons);
                foreach ($buttons as $rowIndex => $row) {
                    foreach ((array) $row as $buttonIndex => $button) {
                        if (!is_array($button) || empty($button['__paint'])) {
                            continue;
                        }
                        unset($buttons[$rowIndex][$buttonIndex]['__paint'], $buttons[$rowIndex][$buttonIndex]['color']);
                        if ($style === 'none') {
                            unset($buttons[$rowIndex][$buttonIndex]['style']);
                        } else {
                            $buttons[$rowIndex][$buttonIndex]['style'] = $style;
                        }
                    }
                }
                $this->pdo->prepare(
                    "UPDATE posted_posts SET buttons_json=:buttons
                     WHERE id=:id AND status='draft'"
                )->execute(['buttons' => $this->encodeJson($buttons), 'id' => $postId]);
                return true;
            }
        );
        if ($updated !== true) {
            $this->telegram->sendMessage($userId, 'Пост не найден или нет доступа.');
            return;
        }
        $this->showDraftButtonColorMenu($userId, $postId, $style, $target);
    }

    private function showDraftDeleteTimerMenu(int $userId, int $postId, ?array $target = null): void
    {
        if (!$this->canManagePost($userId, $postId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $post=$this->post($postId);
        if(!$post||(string)$post['status']!=='draft')return;
        $this->setState($userId,'await_delete_timer',['post_id'=>$postId]);
        $p = 'draft:setdelete:' . $postId . ':';
        $selected=$this->normalizeTelegramDeleteTimerMinutes((int)($post['delete_after_minutes']??0));
        $button=function(int $minutes,string $callback)use($selected):array{
            $result=['text'=>$this->telegramDeleteTimerLabel($minutes),'callback_data'=>$callback];
            if($selected===$minutes)$result['style']='primary';
            return $result;
        };
        $this->sendOrEditMessage($userId, "🗑 ТАЙМЕР УДАЛЕНИЯ\n\nВыберите срок после публикации или отправьте его сообщением.\n\n".$this->telegramDeleteTimerHint()."\n\n6 — через 6 ч\n6:30 или 6 30 — через 6 ч 30 мин\n2д — автоудаление ≈48 ч\n10д — напоминание удалить вручную через 10 дней", [
            'reply_markup' => ['inline_keyboard' => [
                [$button(0,$p.'0'),$button(5,$p.'5'),$button(15,$p.'15'),$button(30,$p.'30')],
                [$button(60,$p.'60'),$button(120,$p.'120'),$button(240,$p.'240'),$button(360,$p.'360')],
                [$button(720,$p.'720'),$button(1080,$p.'1080'),$button(1440,$p.'1440'),$button(self::TELEGRAM_MAX_AUTO_DELETE_MINUTES,$p.(string)self::TELEGRAM_MAX_AUTO_DELETE_MINUTES)],
                [$button(4320,$p.'4320'),$button(7200,$p.'7200'),$button(10080,$p.'10080'),['text'=>'👁️','callback_data'=>'draft:delete_custom:'.$postId]],
                [['text'=>(!empty($post['delete_report_enabled'])?'✅️':'☑️').' Прислать отчёт об удалении','callback_data'=>'draft:delete_report:'.$postId.':'.(empty($post['delete_report_enabled'])?1:0)]],
                [['text' => '← Назад', 'callback_data' => 'draft:cancel_timer:' . $postId]],
            ]],
        ], $target);
    }
    private function setDraftDeleteTimer(int $userId, int $postId, int $minutes, ?array $target = null): void
    {
        $minutes = $this->normalizeTelegramDeleteTimerMinutes($minutes);
        $updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function () use ($postId, $minutes): bool {
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET delete_after_minutes=:minutes,delete_at=NULL,
                         manual_delete_required=0,delete_notice_sent_at=NULL,
                         delete_attempts=0,error_text=NULL
                     WHERE id=:id AND status='draft'"
                )->execute([
                    'minutes' => $minutes > 0 ? $minutes : null,
                    'id' => $postId,
                ]);
                return true;
            }
        );
        if ($updated !== true) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $this->clearState($userId);
        $this->showDraftSendSettings($userId, $postId, $target);
    }

    private function showDraftRepeatMenu(int $userId, int $postId, ?array $target = null): void
    {
        if (!$this->canManagePost($userId, $postId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $this->sendOrEditMessage($userId, "Автоповтор\n\nВыберите интервал, с которым пост будет повторяться.", [
            'reply_markup' => ['inline_keyboard' => $this->minutesKeyboard('draft:setrepeat:' . $postId . ':', [0, 120, 240, 360, 720, 1080, 1440, 2880, 4320, 7200, 10080, 14400], 'draft:next:' . $postId)],
        ], $target);
    }

    private function setDraftRepeat(int $userId, int $postId, int $minutes, ?array $target = null): void
    {
        try {
        $updated = $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function (array $post,array $channel) use ($postId, $minutes): bool {
                if($minutes>0)PostedTelegramPolicy::requireChannel($this->pdo,$this->config,$channel,'premium');
                $topOption=PostedTelegramTopHold::load($this->pdo,$post,true);if($minutes>0&&$topOption['enabled']&&$minutes<$topOption['duration_minutes'])throw new PostedTelegramTopHoldException('schedule_conflict');
                $this->pdo->prepare(
                    "UPDATE posted_posts SET repeat_interval_minutes=:minutes
                     WHERE id=:id AND status='draft'"
                )->execute([
                    'minutes' => $minutes > 0 ? $minutes : null,
                    'id' => $postId,
                ]);
                return true;
            }
        );
        } catch (PostedTelegramTopHoldException $exception) {$this->telegram->sendMessage($userId,$exception->getMessage());return;
        } catch (PostedTelegramPolicyException $exception) {
            $message=in_array($exception->reason,['invalid_policy','invalid_license','policy_check_unavailable'],true)
                ?'Настройки доступа или подписки канала требуют проверки администратором.'
                :"У канала нет премиум-подписки\n\nЧтобы продолжить, приобретите подписку для канала";
            $this->telegram->sendMessage($userId,$message);
            return;
        }
        if ($updated !== true) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $this->showDraftSendSettings($userId, $postId, $target);
    }

    private function showContentPlanChannels(int $userId, ?array $target = null): void
    {
        $channels = $this->allowedChannels($userId, 'post');
        $pausedEntry=$this->telegramAuthorityPauseEntryButton($userId,'cp');
        if ($channels === [] && $pausedEntry===null) {
            $this->telegram->sendMessage($userId, 'Нет доступных каналов.');
            return;
        }

        if (count($channels) === 1 && $pausedEntry===null) {
            $this->showContentPlanForChannel($userId,(int)$channels[0]['id'],$this->todayForUser($userId),false,$target,false);
            return;
        }

        $keyboard = [];
        $channelButtons=[];
        foreach ($channels as $channel) {
            $channelButtons[] = [
                'text' => (string) $channel['title'],
                'callback_data'=>'cp:channel:'.(int)$channel['id'].':'.$this->todayForUser($userId),
            ];
        }
        foreach (array_chunk($channelButtons, 2) as $row) $keyboard[]=$row;

        if($pausedEntry!==null)$keyboard[]=[$pausedEntry];
        $this->sendOrEditMessage(
            $userId,
            $this->telegramUiText($userId, 'content.body', $this->customEmojiHtml('5258105663359294787', '🗓')
                . " <b>КОНТЕНТ-ПЛАН</b>\n\nВ этом разделе вы можете просматривать и изменять запланированные публикации. Выберите канал, в котором хотите увидеть контент-план."),
            [
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ],
            $target
        );
    }

    private function showContentPlanForChannel(int $userId, int $channelId, ?string $date = null, bool $calendar = false, ?array $target = null, bool $includeBack = true): void
    {
        if (!$this->can($userId, $channelId, 'post')) {
            $this->telegram->sendMessage($userId, 'Нет доступа к каналу.');
            return;
        }

        $date=$this->normalizeDate($date)??$this->todayForUser($userId);
        $posts=$this->postsForDate($userId,[$channelId],$date);
        $channel = $this->channel($channelId) ?? ['title' => $this->channelTitle($channelId)];
        $channelTitle = $this->channelTitleHtml($channel);
        $humanDate = htmlspecialchars($this->humanDate($date), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [$this->customEmojiHtml('5258105663359294787', '🗓') . ' <b>КОНТЕНТ-ПЛАН</b>', ''];
        $lines[] = count($posts) === 0
            ? 'На <b>' . $humanDate . '</b> в канале ' . $channelTitle . ' не запланировано ни одного поста.'
            : 'На <b>' . $humanDate . '</b> в канале ' . $channelTitle . ' ' . PostedTelegramContentPlanUi::scheduledPhrase(count($posts)) . '.';

        $postButtons = [];
        foreach ($posts as $post) {
            $postButtons[] = [
                'text'=>$this->postPlanButtonTitle($userId,$post),
                'callback_data' => 'cp:post:' . (int) $post['id'],
            ];
        }
        $keyboard=PostedTelegramContentPlanUi::postRows($postButtons,PostedTelegramContentPlanUi::compact($this->user($userId)??[]));

        if ($calendar) {
            $keyboard=array_merge($keyboard,$this->calendarKeyboardForChannel($userId,$channelId,$date));
        } else {
            $prev = date('Y-m-d', strtotime($date . ' -1 day'));
            $next = date('Y-m-d', strtotime($date . ' +1 day'));
            $keyboard[] = [
                ['text' => '← ' . $this->shortDate($prev), 'callback_data' => 'cp:cdate:' . $channelId . ':' . $prev],
                ['text' => $this->shortDate($date), 'callback_data' => 'cp:cdate:' . $channelId . ':' . $date],
                ['text' => $this->shortDate($next) . ' →', 'callback_data' => 'cp:cdate:' . $channelId . ':' . $next],
            ];
            $keyboard[] = [['text' => '↓ Развернуть календарь', 'callback_data' => 'cp:ccal:' . $channelId . ':' . $date]];
        }
        $keyboard[]=[['text'=>'⚙️ Управление контент-планом','callback_data'=>'cp:cmanage:'.$channelId.':'.$date]];
        if ($includeBack) {
            $keyboard[] = [['text' => '← Назад', 'callback_data' => 'main']];
        }

        $this->sendOrEditMessage($userId, implode("\n", $lines), [
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ], $target);
    }

    private function showContentPlan(int $userId, ?string $date = null, bool $calendar = false, ?array $target = null): void
    {
        $date=$this->normalizeDate($date)??$this->todayForUser($userId);
        $channelIds = $this->allowedChannelIds($userId, 'post');
        if ($channelIds === []) {
            $this->telegram->sendMessage($userId, 'Нет доступных каналов.');
            return;
        }

        $posts=$this->postsForDate($userId,$channelIds,$date);
        $lines = [$this->customEmojiHtml('5258105663359294787', '🗓') . ' <b>КОНТЕНТ-ПЛАН</b>', ''];
        $lines[] = 'На <b>' . htmlspecialchars($this->humanDate($date), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b> запланировано ' . $this->pluralRu(count($posts), 'пост', 'поста', 'постов') . '.';
        foreach ($posts as $post) {
            $time=$this->contentPlanRowTime($userId,$post);
            $lines[] = htmlspecialchars($time . ' ' . $this->postShortTitle($post), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $keyboard = [];
        foreach ($posts as $post) {
            $time=$this->contentPlanRowTime($userId,$post);
            $keyboard[] = [[
                'text' => $time . ' ' . $this->postShortTitle($post),
                'callback_data' => 'cp:post:' . (int) $post['id'],
            ]];
        }

        if ($calendar) {
            $keyboard = array_merge($keyboard, $this->calendarKeyboard($userId, $date));
        } else {
            $prev = date('Y-m-d', strtotime($date . ' -1 day'));
            $next = date('Y-m-d', strtotime($date . ' +1 day'));
            $keyboard[] = [
                ['text' => '← ' . $this->shortDate($prev), 'callback_data' => 'cp:date:' . $prev],
                ['text' => $this->shortDate($date), 'callback_data' => 'cp:date:' . $date],
                ['text' => $this->shortDate($next) . ' →', 'callback_data' => 'cp:date:' . $next],
            ];
            $keyboard[] = [['text' => '↓ Развернуть календарь', 'callback_data' => 'cp:cal:' . substr($date, 0, 7)]];
        }
        $keyboard[] = [['text' => '⚙️ Управление контент-планом', 'callback_data' => 'cp:manage:' . $date]];
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'main']];

        $this->sendOrEditMessage($userId, implode("\n", $lines), [
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ], $target);
    }

    private function showEmojiCatalogV2(int $userId, string $category = 'quality', int $page = 1, ?array $target = null, bool $showFilters = false): void
    {
        $this->renderTelegramEmojiCatalog($userId, $category, $page, $target, $showFilters);
    }

    private function showEmojiCatalog(int $userId, string $category = 'quality', int $page = 1): void
    {
        $categories = $this->emojiCategories();
        if (!isset($categories[$category])) {
            $category = 'quality';
        }

        $items = $this->emojiCatalog()[$category] ?? [];
        $perPage = 10;
        $totalPages = max(1, (int) ceil(count($items) / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        $lines = ['📖 КАТАЛОГ ЭМОДЗИ'];
        if ($pageItems === []) {
            $lines[] = '';
            $lines[] = 'В этой категории пока нет наборов.';
        } else {
            foreach ($pageItems as $item) {
                $lines[] = '';
                $lines[] = $item['preview'] . '  Открыть [' . (int) $item['count'] . ']';
            }
        }

        $keyboard = [];
        foreach ($pageItems as $item) {
            $keyboard[] = [[
                'text' => 'Открыть [' . (int) $item['count'] . ']',
                'url' => $item['url'],
            ]];
        }

        $categoryButtons = [];
        foreach ($categories as $key => $title) {
            $prefix = $key === $category ? '🔸 ' : '';
            $categoryButtons[] = [
                'text' => $prefix . $title,
                'callback_data' => 'emoji:' . $key . ':1',
            ];
        }
        foreach (array_chunk($categoryButtons, 2) as $row) {
            $keyboard[] = $row;
        }

        $prevPage = $page > 1 ? $page - 1 : $totalPages;
        $nextPage = $page < $totalPages ? $page + 1 : 1;
        $keyboard[] = [[
            'text' => '<',
            'callback_data' => 'emoji:' . $category . ':' . $prevPage,
        ], [
            'text' => $page . ' / ' . $totalPages,
            'callback_data' => 'emoji:' . $category . ':' . $page,
        ], [
            'text' => '>',
            'callback_data' => 'emoji:' . $category . ':' . $nextPage,
        ]];

        $this->telegram->sendMessage($userId, implode("\n", $lines), [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    private function handleContentPlanCallback(int $userId, string $data, ?array $target = null): void
    {
        $parts = explode(':', $data);
        $action = (string) ($parts[1] ?? '');
        if($action==='edit'){
            if(preg_match('/\Acp:edit:([1-9][0-9]{0,18})\z/D',$data,$editMatch)!==1||(string)(int)$editMatch[1]!==$editMatch[1])return;
            $this->openContentPlanPublishedEditor($userId,(int)$editMatch[1]);return;
        }
        if ($action === 'channel') {
            $this->showContentPlanForChannel($userId, (int) ($parts[2] ?? 0), (string) ($parts[3] ?? ''), false, $target);
            return;
        }
        if ($action === 'cdate') {
            $this->showContentPlanForChannel($userId, (int) ($parts[2] ?? 0), (string) ($parts[3] ?? ''), false, $target);
            return;
        }
        if ($action === 'ccal') {
            $date=(string)($parts[3]??date('Y-m-d'));
            if(preg_match('/^\d{4}-\d{2}$/',$date)===1)$date.='-01';
            $this->showContentPlanForChannel($userId,(int)($parts[2]??0),$date,true,$target,count($this->allowedChannelIds($userId,'post'))!==1);
            return;
        }
        if ($action === 'date') {
            $this->showContentPlan($userId, (string) ($parts[2] ?? ''), false, $target);
            return;
        }
        if ($action === 'cal') {
            $date = (string) ($parts[2] ?? date('Y-m')) . '-01';
            $this->showContentPlan($userId, $date, true, $target);
            return;
        }
        if ($action === 'all') {
            $channelIds = $this->allowedChannelIds($userId, 'post');
            if($channelIds===[]){$this->showContentPlanChannels($userId,$target);return;}
            $marks=implode(',',array_fill(0,count($channelIds),'?'));
            $stmt=$this->pdo->prepare(
                "SELECT p.*,c.chat_id,c.title AS channel_title
                 FROM posted_posts p JOIN posted_channels c ON c.id=p.channel_id
                 WHERE p.channel_id IN ({$marks}) AND p.status='scheduled' AND p.scheduled_at>=NOW()
                 ORDER BY p.scheduled_at,p.id LIMIT 100"
            );
            $stmt->execute($channelIds);
            $posts=$stmt->fetchAll();
            if($posts===[]){
                $title=count($channelIds)===1?'В канале '.$this->channelTitle((int)$channelIds[0]).' ':'';
                $this->sendOrEditMessage($userId,$title.'не запланировано ни одного поста.',[
                    'reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'cp:manage:'.(string)($parts[2]??$this->todayForUser($userId))]]]],
                ],$target);
                return;
            }
            $keyboard=[];
            foreach($posts as $post)$keyboard[]=[['text'=>$this->postPlanButtonTitle($userId,$post),'callback_data'=>'cp:post:'.(int)$post['id']]];
            $keyboard[]=[['text'=>'← Назад','callback_data'=>'cp:manage:'.(string)($parts[2]??$this->todayForUser($userId))]];
            $this->sendOrEditMessage($userId,"🔎 ВСЕ ОТЛОЖЕННЫЕ ПОСТЫ\n\nВыберите публикацию.",[
                'reply_markup'=>['inline_keyboard'=>$keyboard],
            ],$target);
            return;
        }
        if($action==='cmanage'){
            $channelId=(int)($parts[2]??0);$date=(string)($parts[3]??$this->todayForUser($userId));
            if(!$this->can($userId,$channelId,'post'))return;
            $this->sendOrEditMessage($userId,"🕘 УПРАВЛЕНИЕ КОНТЕНТ-ПЛАНОМ\n\nВ этом разделе вы использовать продвинутые функции для работы с контент-планом.",[
                'reply_markup'=>['inline_keyboard'=>[
                    [['text'=>'🔎 Все отложенные посты','callback_data'=>'cp:call:'.$channelId.':'.$date]],
                    [['text'=>'← Назад','callback_data'=>'cp:cdate:'.$channelId.':'.$date]],
                ]],
            ],$target);
            return;
        }
        if($action==='call'){
            $channelId=(int)($parts[2]??0);$date=(string)($parts[3]??$this->todayForUser($userId));
            if(!$this->can($userId,$channelId,'post'))return;
            $stmt=$this->pdo->prepare("SELECT p.*,c.chat_id,c.title AS channel_title FROM posted_posts p JOIN posted_channels c ON c.id=p.channel_id WHERE p.channel_id=:channel AND p.status='scheduled' AND p.scheduled_at>=NOW() ORDER BY p.scheduled_at,p.id LIMIT 100");
            $stmt->execute(['channel'=>$channelId]);$posts=$stmt->fetchAll();
            if($posts===[]){
                $this->sendOrEditMessage($userId,'В канале '.$this->channelTitle($channelId).' не запланировано ни одного поста.',[
                    'reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'cp:cmanage:'.$channelId.':'.$date]]]],
                ],$target);return;
            }
            $keyboard=[];foreach($posts as $post)$keyboard[]=[['text'=>$this->postPlanButtonTitle($userId,$post),'callback_data'=>'cp:post:'.(int)$post['id']]];
            $keyboard[]=[['text'=>'← Назад','callback_data'=>'cp:cmanage:'.$channelId.':'.$date]];
            $this->sendOrEditMessage($userId,"🔎 ВСЕ ОТЛОЖЕННЫЕ ПОСТЫ\n\nВыберите публикацию.",['reply_markup'=>['inline_keyboard'=>$keyboard]],$target);
            return;
        }
        if ($action === 'post') {
            $this->showManagedPost($userId, (int) ($parts[2] ?? 0));
            return;
        }
        if ($action === 'dup') {
            $this->duplicatePost($userId, (int) ($parts[2] ?? 0));
            return;
        }
        if($action==='dupcat'){
            $this->duplicatePost(
                $userId,
                (int)($parts[2]??0),
                (int)($parts[3]??0)
            );
            return;
        }
        if ($action === 'del') {
            $this->deleteManagedPost($userId, (int) ($parts[2] ?? 0), $target);
            return;
        }
        if($action==='pendingwait'){
            $this->continueTelegramPendingWait($userId,(int)($parts[2]??0));
            return;
        }
        if($action==='forcecancel'){
            $this->forceCancelTelegramPending($userId,(int)($parts[2]??0));
            return;
        }
        if ($action === 'pub') {
            $this->publishManagedPostNow($userId, (int) ($parts[2] ?? 0));
            return;
        }
        if ($action === 'dateedit') {
            $postId = (int) ($parts[2] ?? 0);
            $date = (string) ($parts[3] ?? date('Y-m-d'));
            $this->setState($userId, 'await_cp_schedule', ['post_id' => $postId, 'date' => $date]);
            $this->telegram->sendMessage($userId, "Отложить пост\n\nОтправьте новую дату и время: сначала дата, затем время.\n\nПримеры:\n"
                . $this->scheduleInputExamples($userId,$date,"\n") . "\n\nМожно отправить только время для выбранной даты: 18:30.");
            return;
        }
        if ($action === 'timer') {
            $this->showDeleteTimerMenu($userId, (int) ($parts[2] ?? 0));
            return;
        }
        if ($action === 'settimer') {
            $this->setDeleteTimer($userId, (int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0));
            return;
        }
        if ($action === 'repeat') {
            $this->showRepeatMenu($userId, (int) ($parts[2] ?? 0));
            return;
        }
        if ($action === 'setrepeat') {
            $this->setRepeat($userId, (int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0));
            return;
        }
        if ($action === 'pinplan') {
            $postId = (int)($parts[2] ?? 0);
            $desired = (string)($parts[3] ?? '');
            if (!in_array($desired, ['0','1'], true)) return;
            $updated = $this->withTelegramManagedPostLock(
                $userId, $postId, ['draft','scheduled','failed'],
                function () use ($postId, $desired): bool {
                    $this->pdo->prepare('UPDATE posted_posts SET pin_after_publish=:pin WHERE id=:post')
                        ->execute(['pin'=>(int)$desired,'post'=>$postId]);
                    return true;
                }
            );
            if ($updated !== true) {
                $this->telegram->sendMessage($userId, 'Настройка недоступна: состояние поста или права изменились.');
                return;
            }
            $this->showManagedPost($userId, $postId);
            return;
        }
        if ($action === 'manage') {
            $this->sendOrEditMessage($userId, "🕘 УПРАВЛЕНИЕ КОНТЕНТ-ПЛАНОМ\n\nВ этом разделе вы использовать продвинутые функции для работы с контент-планом.", ['reply_markup' => ['inline_keyboard' => [[["text" => "🔎 Все отложенные посты", "callback_data" => "cp:all:" . (string) ($parts[2] ?? date('Y-m-d'))]], [["text" => "← Назад", "callback_data" => "cp:date:" . (string) ($parts[2] ?? date('Y-m-d'))]]]]], $target);
        }
    }

    private function showManagedPost(int $userId, int $postId): void
    {
        $post = $this->post($postId);
        if (!$post || !$this->can($userId, (int) $post['channel_id'], 'post')) {
            $this->telegram->sendMessage($userId, 'Пост не найден или нет доступа.');
            return;
        }

        $albumDetail=$this->telegramAlbumPanelDetailEligible($userId,$post);
        $cpDetail=null;
        if(!$albumDetail){
            $cpDetail=$this->telegramCpDetailBegin($userId,$post);if($cpDetail===false)return;
            try{if($cpDetail===null)$this->sendPostPreview($userId,$post);else$this->telegramCpDetailDispatch($cpDetail);}
            catch(Throwable$e){if(is_array($cpDetail)){try{$this->telegramCpDetailStoreOutcome($cpDetail['record'],null,false,true);}catch(Throwable){}}throw$e;}
        }
        $date=$post['scheduled_at']?$this->formatStoredForUser($userId,(string)$post['scheduled_at'],'Y-m-d'):$this->todayForUser($userId);
        if((string)$post['status']==='sent')$date=PostedTelegramContentPlanUi::date($post,$this->storageTimezone(),$this->userTimezoneObject($userId),true)?->format('Y-m-d')??$date;
        $timerLabel=(string)$post['status']==='sent'&&(int)($post['delete_after_minutes']??0)<=0?'нет':$this->telegramDeleteTimerLabel((int)($post['delete_after_minutes']??0));
        $keyboard = [
            [['text' => 'Дублировать', 'callback_data' => 'cp:dup:' . $postId]],
            [['text' => 'Таймер удаления: ' . $timerLabel, 'callback_data' => 'cp:timer:' . $postId]],
            [['text' => 'Автоповтор: ' . $this->minutesLabel((int) ($post['repeat_interval_minutes'] ?? 0)), 'callback_data' => 'cp:repeat:' . $postId]],
        ];
        if((string)$post['status']==='sent'&&$this->can($userId,(int)$post['channel_id'],'manage_posts')&&($this->isSingleMessageEditableTelegramPost($post)||$this->telegramPublishedAlbumEditable($post)))$keyboard[0][]=['text'=>'Изменить','callback_data'=>'cp:edit:'.$postId];
        if (in_array((string)$post['status'], ['draft','scheduled','failed','sent'], true)) {
            $keyboard[] = [['text'=>'📅 Серия публикаций','callback_data'=>'series:open:'.$postId]];
        }
        if (in_array((string)$post['status'], ['draft','scheduled','failed'], true)) {
            $keyboard[] = [['text'=>(!empty($post['pin_after_publish'])?'✅':'☑️').' Закрепить при публикации',
                'callback_data'=>'cp:pinplan:'.$postId.':'.(empty($post['pin_after_publish'])?1:0)]];
        }
        if ((string) ($post['status'] ?? '') === 'sent') {
            $keyboard[] = [['text' => 'Удалить', 'callback_data' => 'cp:del:' . $postId]];
        } elseif((string)($post['status']??'')==='telegram_pending'){
            $keyboard[]=[['text'=>'Отменить публикацию','callback_data'=>'cp:del:'.$postId]];
        } else {
            $keyboard[0][] = ['text' => 'Изменить дату', 'callback_data' => 'cp:dateedit:' . $postId . ':' . $date];
            $keyboard[] = [['text' => 'Опубликовать', 'callback_data' => 'cp:pub:' . $postId], ['text' => 'Удалить', 'callback_data' => 'cp:del:' . $postId]];
        }
        $keyboard[]=[['text'=>'↓ Больше настроек','callback_data'=>'top:more:'.$postId.':m']];
        $keyboard[]=[['text'=>'← Назад','callback_data'=>'cp:cdate:'.(int)$post['channel_id'].':'.$date]];
        $control=(string)$post['status']==='sent'?$this->contentPlanPublishedDetail($userId,$post):['text'=>$this->managedPostControlText($userId,$post)];
        $controlExtra=['reply_markup'=>['inline_keyboard'=>$keyboard]];
        if(isset($control['entities']))$controlExtra['entities']=$control['entities'];
        if($albumDetail){$this->renderTelegramPublishedAlbumDetail($userId,$postId,$control,$controlExtra);return;}
        if(is_array($cpDetail))$this->telegramCpDetailBeforeControl($cpDetail['record']);
        try{$cpControl=$this->telegram->sendMessage($userId,$control['text'],$controlExtra);if(is_array($cpDetail))$this->telegramCpDetailControlObserved($cpDetail['record'],$cpControl,$control,$controlExtra);}
        catch(Throwable$e){if(is_array($cpDetail)){try{$this->telegramCpDetailStoreOutcome($cpDetail['record'],null,false,true);}catch(Throwable){}}throw$e;}
    }

    private function showDeleteTimerMenu(int $userId, int $postId): void
    {
        if (!$this->canManagePost($userId, $postId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $post=$this->post($postId);
        $this->telegram->sendMessage($userId, "Таймер удаления\n\nВыберите срок после публикации.\n\n".$this->telegramDeleteTimerHint(), [
            'reply_markup' => ['inline_keyboard' => $this->telegramDeleteTimerKeyboard('cp:settimer:' . $postId . ':', [0, 5, 15, 30, 60, 120, 240, 360, 720, 1080, 1440, self::TELEGRAM_MAX_AUTO_DELETE_MINUTES, 4320, 7200, 10080], 'cp:post:' . $postId, (int)($post['delete_after_minutes']??0))],
        ]);
    }

    private function showRepeatMenu(int $userId, int $postId): void
    {
        if (!$this->canManagePost($userId, $postId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа к посту.');
            return;
        }
        $this->telegram->sendMessage($userId, "Автоповтор\n\nВыберите интервал, с которым пост будет повторяться.", [
            'reply_markup' => ['inline_keyboard' => $this->minutesKeyboard('cp:setrepeat:' . $postId . ':', [0, 120, 240, 360, 720, 1080, 1440, 2880, 4320, 7200, 10080, 14400], 'cp:post:' . $postId)],
        ]);
    }

    private function showTemplates(int $userId): void
    {
        $channels = $this->allowedChannels($userId, 'post');
        $keyboard = [];
        $channelButtons=[];
        foreach ($channels as $channel) {
            $channelButtons[] = [
                'text' => (string) $channel['title'],
                'callback_data' => 'tpl_scope:' . (int) $channel['id'],
            ];
        }
        foreach (array_chunk($channelButtons, 2) as $row) $keyboard[]=$row;
        $keyboard[] = [['text' => $this->telegramUiText($userId, 'button.common', 'Общие'), 'callback_data' => 'tpl_scope:0']];

        $this->telegram->sendMessage(
            $userId,
            $this->telegramUiText($userId, 'templates.body', $this->customEmojiHtml('5296348778012361146', '🏷')
                . " <b>ШАБЛОНЫ</b>\n\nСохраняйте заготовки для постов или рекламных креативов.\n\nВыберите для какого канала вы хотите просмотреть шаблоны либо нажмите <b>«Общие»</b>."),
            [
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ]
        );
    }

    private function showTemplateList(int $userId, int $scopeId): void
    {
        if ($scopeId > 0 && !$this->can($userId, $scopeId, 'post')) {
            $this->telegram->sendMessage($userId, 'Нет доступа к шаблонам этого канала.');
            return;
        }

        $templates = $this->templatesForScope($userId, $scopeId);
        $keyboard = [];
        foreach ($templates as $template) {
            $keyboard[] = [[
                'text' => $this->telegramTemplateListLabel($template),
                'callback_data' => 'tpl_view:' . (int) $template['id'],
            ]];
        }
        $keyboard[] = [['text' => '➕ Создать шаблон', 'callback_data' => 'tpl_create:' . $scopeId]];
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'tpl_root']];

        $scopeTitle = $scopeId > 0 ? $this->channelTitle($scopeId) : 'Общие';
        $scopeTitleHtml = htmlspecialchars($scopeTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($scopeId > 0) {
            $scopeChannel = $this->channel($scopeId);
            if (is_array($scopeChannel)) {
                $scopeChannel['title'] = $scopeTitle;
                $scopeTitleHtml = $this->channelTitleHtml($scopeChannel);
            }
        }
        $text = $this->customEmojiHtml('5296348778012361146', '🏷') . " <b>ШАБЛОНЫ</b>\n\nСохраняйте заготовки для постов или рекламных креативов.\n\n";
        if ($scopeId > 0) {
            $text .= 'Выберите шаблон для канала ' . $scopeTitleHtml . ' или создайте новый.';
        } else {
            $text .= 'Выберите шаблон или создайте новый.';
        }

        $this->telegram->sendMessage($userId, $text, ['parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $keyboard]]);
    }

    private function startTemplateCreate(int $userId, int $scopeId): void
    {
        if ($scopeId > 0 && !$this->can($userId, $scopeId, 'post')) {
            $this->telegram->sendMessage($userId, 'Нет доступа к шаблонам этого канала.');
            return;
        }

        $this->setState($userId, 'await_template_content', ['scope_id' => $scopeId, 'input_token' => bin2hex(random_bytes(12))]);
        $this->telegram->sendMessage(
            $userId,
            'Отправьте боту шаблон, который вы хотите сохранить.',
            ['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'tpl_scope:'.$scopeId]]]]]
        );
    }

    private function createTemplateFromMessage(int $userId, int $scopeId, array $message, array $expectedStateData): void
    {
        if ((int) ($expectedStateData['scope_id'] ?? 0) !== $scopeId) return;
        $current = $this->pdo->prepare(
            "SELECT telegram_id FROM posted_users
             WHERE telegram_id=:user AND is_active=1 AND state='await_template_content'
               AND state_data <=> CAST(:data AS JSON) LIMIT 1"
        );
        $current->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
        if ($current->fetchColumn() === false) return;
        if ($scopeId > 0 && !$this->ensureTelegramChannelTenure($scopeId)) {
            $this->telegram->sendMessage($userId, 'Владение каналом в Telegram больше не подтверждено.');
            return;
        }
        if(array_key_exists('show_caption_above_media',$message)&&$message['show_caption_above_media']!==false){try{$placementInput=$this->contentFromMessage($message);PostedTelegramCaptionPlacement::input($message,$placementInput);}catch(Throwable$placementError){$this->telegram->sendMessage($userId,$placementError->getMessage());return;}}
        if($this->createTelegramNativeTemplateFromMessage($userId,$scopeId,$message,$expectedStateData))return;
        $content = $this->contentFromMessage($message);
        try{$captionAbove=PostedTelegramCaptionPlacement::input($message,$content);}catch(InvalidArgumentException$e){$this->telegram->sendMessage($userId,$e->getMessage());return;}
        if ($content['text'] === '' && $content['media_file_id'] === null) {
            $this->telegram->sendMessage($userId, 'Нужен текст либо поддерживаемое медиа с подписью.');
            return;
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) PostedTelegramRootedAccess::begin($this->pdo,$this->config);
        $templateId = 0;
        $templateCollecting = false;
        try {
            PostedTelegramPolicy::requireOperator($this->config,$userId,$this->pdo);
            if($scopeId>0)PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$userId,$scopeId);
            if ($scopeId > 0) {
                $channel = $this->pdo->prepare(
                    'SELECT id FROM posted_channels WHERE id=:channel AND is_active=1
                       AND added_by IS NOT NULL AND added_by>0 LIMIT 1 FOR UPDATE'
                );
                $channel->execute(['channel'=>$scopeId]);
                if ($channel->fetchColumn() === false) {
                    if ($ownsTransaction) PostedTelegramRootedAccess::commit($this->pdo);
                    return;
                }
            }
            // Match transfer's actor -> owner-range order, including an empty
            // range whose gap lock cannot serialize concurrent inserts.
            if (!$this->lockActiveTelegramActor($userId)) {
                if ($ownsTransaction) PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            $range = $this->pdo->prepare('SELECT id FROM posted_templates WHERE owner_id=:owner ORDER BY id FOR UPDATE');
            $range->execute(['owner'=>$userId]);
            $range->fetchAll(PDO::FETCH_COLUMN);
            $current = $this->pdo->prepare(
                "SELECT telegram_id FROM posted_users
                 WHERE telegram_id=:user AND is_active=1 AND state='await_template_content'
                   AND state_data <=> CAST(:data AS JSON) LIMIT 1 FOR UPDATE"
            );
            $current->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
            if ($current->fetchColumn() === false) {
                if ($ownsTransaction) PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            if ($scopeId > 0) {
                $permission=$this->pdo->prepare('SELECT can_post FROM posted_permissions WHERE user_id=:user AND channel_id=:channel LIMIT 1 FOR UPDATE');
                $permission->execute(['user'=>$userId,'channel'=>$scopeId]);
                if ((int) ($permission->fetchColumn() ?: 0) !== 1) {
                    if ($ownsTransaction) PostedTelegramRootedAccess::commit($this->pdo);
                    return;
                }
            }
            $stmt = $this->pdo->prepare(
                "INSERT INTO posted_templates
                    (owner_id,channel_id,title,text,text_entities_json,media_type,media_file_id)
                 VALUES
                    (:owner_id,:channel_id,:title,:text,:text_entities_json,:media_type,:media_file_id)"
            );
            $stmt->execute([
                'owner_id'=>$userId,'channel_id'=>$scopeId>0?$scopeId:null,
                'title'=>$this->templateTitle($content['text'],$content['media_type']),
                'text'=>$content['text']!==''?$content['text']:null,
                'text_entities_json'=>$content['text_entities']!==[]?$this->encodeJson($content['text_entities']):null,
                'media_type'=>$content['media_type'],'media_file_id'=>$content['media_file_id'],
            ]);
            $templateId=(int)$this->pdo->lastInsertId();
            $createdQuery=$this->pdo->prepare('SELECT * FROM posted_templates WHERE id=? AND owner_id=? LIMIT 1 FOR UPDATE');$createdQuery->execute([$templateId,$userId]);$newTemplate=$createdQuery->fetch();
            if(!is_array($newTemplate))throw new RuntimeException('Новый шаблон не найден.');
            $draftToken=$expectedStateData['input_token']??null;if(!is_string($draftToken)||preg_match('/\A[a-f0-9]{24}\z/D',$draftToken)!==1)$draftToken=bin2hex(random_bytes(12));
            PostedTelegramTemplateMedia::saveLocked($this->pdo,$newTemplate,$content['media'],false,$draftToken);
            $placementTemplate=PostedTelegramTemplateMedia::hydrate($this->pdo,$newTemplate);PostedTelegramCaptionPlacement::captureLocked($this->pdo,$placementTemplate,$captionAbove,'t');
            $templateExpected=$this->telegramTemplateIngress??['state'=>'await_template_content','state_data'=>$this->encodeJson($expectedStateData)];
            $templateCollecting=$this->registerTelegramTemplateAlbumLocked($userId,$newTemplate,$message,$templateExpected);
            if(!empty($content['media_spoiler'])){
                $createdTemplate=$this->pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1');$createdTemplate->execute([$templateId]);$created=$createdTemplate->fetch();
                if(!is_array($created))throw new RuntimeException('Новый шаблон не найден.');
                PostedTelegramPostOptions::saveTemplateLocked($this->pdo,$created,['media_spoiler'=>true]);
            }
            if(!$templateCollecting)$this->pdo->prepare("UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user AND state='await_template_content' AND state_data <=> CAST(:data AS JSON)")
                ->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
            if ($ownsTransaction) PostedTelegramRootedAccess::commit($this->pdo);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) PostedTelegramRootedAccess::rollback($this->pdo);
            throw $exception;
        }
        if ($templateId <= 0) return;
        if(!$templateCollecting)$this->showTemplateEditor($userId,$templateId);
    }

    private function showTemplateEditor(int $userId,int $templateId):void
    {
        if($this->showTelegramTemplatePendingStatus($userId,$templateId))return;
        $template=$this->ownedTemplate($userId,$templateId);
        if(!$template)return;
        if($this->telegramTemplateUsesVectorUi($template)){$this->renderTelegramTemplateMedia($userId,$templateId);return;}
        $text=(string)($template['text']??'');
        if(trim($text)==='')$text='Медиа-шаблон';
        $keyboard=$this->buttonsMarkup($this->decodeJson((string)($template['buttons_json']??'[]')));
        $keyboard=array_merge($keyboard,[
            [['text'=>'Изменить текст','callback_data'=>'tpl_edit_text:'.$templateId]],
            [['text'=>'Добавить медиа','callback_data'=>'tpl_edit_media:'.$templateId]],
            [['text'=>'URL-кнопки','callback_data'=>'tpl_edit_buttons:'.$templateId]],
            [['text'=>'← Отменить','callback_data'=>'tpl_cancel:'.$templateId],['text'=>'Готово →','callback_data'=>'tpl_done:'.$templateId]],
        ]);
        $extra=['reply_markup'=>['inline_keyboard'=>$keyboard]];
        if($this->telegramTemplateMediaPreview($userId,$template,$text,$extra))return;
        $mediaType=(string)($template['media_type']??'none');
        $fileId=(string)($template['media_file_id']??'');
        if($mediaType!=='none'&&$fileId!==''){
            $method=match($mediaType){'photo'=>'sendPhoto','video'=>'sendVideo','animation'=>'sendAnimation','audio'=>'sendAudio','voice'=>'sendVoice',default=>'sendDocument'};
            $field=match($mediaType){'photo'=>'photo','video'=>'video','animation'=>'animation','audio'=>'audio','voice'=>'voice',default=>'document'};
            $entities=$this->normalizeTelegramEntities(
                $text,
                $this->decodeJson((string)($template['text_entities_json']??''))
            );
            if(PostedTelegramTransportLayout::characters($text)<=self::PHOTO_CAPTION_LIMIT){
                $captionExtra=$extra;
                if($entities!==[])$captionExtra['caption_entities']=$entities;
                $this->telegram->call(
                    $method,
                    array_merge(['chat_id'=>(string)$userId,$field=>$fileId,'caption'=>$text],$captionExtra)
                );
                return;
            }
            $this->telegram->call($method,['chat_id'=>(string)$userId,$field=>$fileId]);
            $parts=$this->splitTelegramScalarFormattedText($text,$entities);
            foreach($parts as $index=>$part){
                $partExtra=[];
                if((array)($part['entities']??[])!==[]){
                    $partExtra['entities']=(array)$part['entities'];
                }
                if($index===array_key_last($parts)){
                    $partExtra=array_merge($partExtra,$extra);
                }
                $this->telegram->sendMessage(
                    $userId,
                    (string)($part['text']??''),
                    $partExtra
                );
            }
            return;
        }
        $plainEntities=$this->normalizeTelegramEntities($text,$this->decodeJson((string)($template['text_entities_json']??'')));if($plainEntities!==[])$extra['entities']=$plainEntities;
        $this->telegram->sendMessage($userId,$text,$extra);
    }

    private function ownedTemplate(int $userId,int $templateId):?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM posted_templates WHERE id=:id AND owner_id=:owner LIMIT 1');
        $stmt->execute(['id'=>$templateId,'owner'=>$userId]);
        $row=$stmt->fetch();if(is_array($row)&&(int)($row['channel_id']??0)>0&&!PostedTelegramRootedAccess::channel($this->pdo,$this->config,$userId,(int)$row['channel_id']))return null;return is_array($row)?PostedTelegramTemplateMedia::hydrate($this->pdo,$row):null;
    }

    private function startTemplateEditInput(int $userId,int $templateId,string $state,string $prompt):void
    {
        if(!$this->ownedTemplate($userId,$templateId))return;
        $this->setState($userId,$state,['template_id'=>$templateId]);
        $this->telegram->sendMessage($userId,$prompt,['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'tpl_editor:'.$templateId]]]]]);
    }

    private function updateTemplateDraft(int $userId,int $templateId,string $state,array $message):void
    {
        $this->updateTelegramTemplateMediaDraft($userId,$templateId,$state,$message);
    }

    private function finishTemplateEdit(int $userId,int $templateId):void
    {
        $this->finishTelegramTemplateMedia($userId,$templateId);
    }

    private function cancelTemplateEdit(int $userId,int $templateId):void
    {
        $this->deleteTelegramTemplateMedia($userId,$templateId,true);
    }

    private function showTemplateView(int $userId, int $templateId): void
    {
        if($this->showTelegramTemplatePendingStatus($userId,$templateId))return;
        $template = $this->template($templateId);
        if (!$template || (int) $template['owner_id'] !== $userId) {
            $this->telegram->sendMessage($userId, 'Шаблон не найден.');
            return;
        }

        $scopeId = (int) ($template['channel_id'] ?? 0);
        if ($scopeId > 0 && !$this->can($userId, $scopeId, 'post')) {
            $this->telegram->sendMessage($userId, 'Нет доступа к шаблону этого канала.');
            return;
        }

        if($this->telegramTemplateUsesVectorUi($template)){$saved=PostedTelegramTemplateMedia::load($this->pdo,$template)['saved'];$this->renderTelegramTemplateMedia($userId,$templateId,$saved?'view':'editor');return;}

        $prefix = "Шаблон: " . (string) $template['title'] . "\n";
        $prefix .= 'Раздел: ' . ($scopeId > 0 ? $this->channelTitle($scopeId) : 'Общие') . "\n\n";
        $templateText=(string)($template['text']??'');
        $summary=$prefix.(trim($templateText)!==''?$templateText:'[медиа без текста]');
        $entities=$templateText!==''
            ?$this->shiftTelegramEntities(
                $this->normalizeTelegramEntities(
                    $templateText,
                    $this->decodeJson((string)($template['text_entities_json']??''))
                ),
                $this->telegramUtf16Length($prefix)
            )
            :[];
        foreach ($this->splitTelegramScalarFormattedText($summary,$entities) as $part) {
            $extra=[];
            if((array)($part['entities']??[])!==[]){
                $extra['entities']=(array)$part['entities'];
            }
            $this->telegram->sendMessage($userId,(string)($part['text']??''),$extra);
        }

        $this->telegram->sendMessage($userId, 'Действия с шаблоном:', [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => 'Использовать', 'callback_data' => 'tpl_use:' . $templateId]],
                    [['text' => 'Удалить', 'callback_data' => 'tpl_del:' . $templateId]],
                    [['text' => '← Назад', 'callback_data' => 'tpl_scope:' . $scopeId]],
                ],
            ],
        ]);
    }

    private function useTemplate(int $userId, int $templateId): void
    {
        $template = $this->template($templateId);
        if (!$template || (int) $template['owner_id'] !== $userId) {
            $this->telegram->sendMessage($userId, 'Шаблон не найден.');
            return;
        }

        $scopeId = (int) ($template['channel_id'] ?? 0);
        if ($scopeId > 0) {
            $this->startTemplateForChannel($userId,$templateId,$scopeId);
            return;
        }

        $channels = $this->allowedChannels($userId, 'post');
        if ($channels === []) {
            $this->telegram->sendMessage($userId, 'Нет доступных каналов для публикации.');
            return;
        }
        $keyboard = [];
        foreach ($channels as $channel) {
            $keyboard[] = [[
                'text' => (string) $channel['title'],
                'callback_data' => 'tpl_ch:' . $templateId . ':' . (int) $channel['id'],
            ]];
        }
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'tpl_view:' . $templateId]];
        $this->telegram->sendMessage($userId, 'Выберите канал для общего шаблона:', [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    private function startTemplateForChannel(int $userId,int $templateId,int $channelId): void
    {
        $stmt=$this->pdo->prepare('SELECT c.* FROM posted_manager_categories c INNER JOIN posted_permissions p ON p.user_id=c.user_id AND p.channel_id=c.channel_id AND p.is_ad_manager=1 WHERE c.user_id=:user AND c.channel_id=:channel AND c.is_active=1 ORDER BY c.id');$stmt->execute(['user'=>$userId,'channel'=>$channelId]);$categories=$stmt->fetchAll();
        if($categories!==[]){$rows=[];foreach($categories as $category){$rows[]=[['text'=>mb_substr((string)$category['name'].' · '.$this->managerQuotaLabel($category['post_quota_remaining']??null,'постов'),0,60),'callback_data'=>'tpl_cat:'.$templateId.':'.$channelId.':'.(int)$category['id']]];}$rows[]=[['text'=>'← Назад','callback_data'=>'tpl_view:'.$templateId]];$this->telegram->sendMessage($userId,'Выберите рекламную компанию для поста из шаблона:',['reply_markup'=>['inline_keyboard'=>$rows]]);return;}
        $this->createDraftFromTemplate($userId,$templateId,$channelId);
    }

    private function createDraftFromTemplate(int $userId,int $templateId,int $channelId,int $managerCategoryId=0): void
    {
        $template = $this->template($templateId);
        if (!$template || (int) $template['owner_id'] !== $userId) {
            $this->telegram->sendMessage($userId, 'Шаблон не найден.');
            return;
        }
        try {
            $postId = $this->withTelegramChannelCapabilityLock(
                $userId,
                $channelId,
                'post',
                function (array $_channel,array $permission) use ($channelId,$userId,$template,$managerCategoryId): int {
                    $templateQuery=$this->pdo->prepare('SELECT * FROM posted_templates WHERE id=? AND owner_id=? LIMIT 1 FOR UPDATE');$templateQuery->execute([(int)$template['id'],$userId]);$currentTemplate=$templateQuery->fetch();
                    if(!is_array($currentTemplate))throw new RuntimeException('Template ownership changed.');
                    $template=PostedTelegramTemplateMedia::hydrate($this->pdo,$currentTemplate);PostedTelegramTemplateMedia::assertReady($this->pdo,$template);
                    if(!PostedTelegramTemplateMedia::load($this->pdo,$template,true)['saved'])throw new RuntimeException('Сначала сохраните шаблон.');
                    if(PostedTelegramNativeContent::isKind($template['media_type']??null))PostedTelegramNativeSchema::requireStorageSupported($this->pdo);
                    $resolvedCategoryId=$this->resolveTelegramManagerCategoryLocked(
                        $userId,
                        $channelId,
                        $managerCategoryId,
                        $permission
                    );
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO posted_posts
                            (channel_id,author_id,text,text_entities_json,autosignature_text,autosignature_entities_json,
                             media_type,media_file_id,media_json,buttons_json,status,
                             silent,pin_after_publish,comments_enabled,link_preview_enabled,link_preview_large,link_preview_above,manager_category_id)
                         SELECT c.id,:author_id,:text,:text_entities_json,c.post_autosignature,
                                c.post_autosignature_entities_json,:media_type,:media_file_id,:media_json,:buttons_json,'draft',
                                c.post_silent,c.post_pin,c.post_comments,c.post_preview,c.post_preview_large,c.post_preview_above,:manager_category_id
                         FROM posted_channels c
                         WHERE c.id=:channel_id AND c.is_active=1
                           AND c.added_by IS NOT NULL AND c.added_by>0"
                    );
                    $stmt->execute([
                        'channel_id' => $channelId,
                        'author_id' => $userId,
                        'text' => $template['text'] ?? null,
                        'text_entities_json' => $template['text_entities_json'] ?? null,
                        'media_type' => (string) ($template['media_type'] ?? 'none'),
                        'media_file_id' => $template['media_file_id'] ?? null,
                        'media_json' => $template['media_json'],
                        'buttons_json' => $template['buttons_json'] ?? null,
                        'manager_category_id'=>$resolvedCategoryId,
                    ]);
                    $postId = (int) $this->pdo->lastInsertId();
                    if ($stmt->rowCount() !== 1 || $postId <= 0) {
                        throw new RuntimeException('Не удалось создать черновик из шаблона.');
                    }
                    $post = $this->post($postId);
                    if (is_array($post)) {
                        $currentTemplate=$this->template((int)$template['id']);
                        if(!$currentTemplate||(int)$currentTemplate['owner_id']!==$userId)throw new RuntimeException('Template ownership changed.');
                        PostedTelegramPostOptions::cloneTemplateToPostLocked($this->pdo,$currentTemplate,$post);
                        $buttons = $this->rebindInteractiveButtons(
                            $this->decodeJson((string) ($post['buttons_json'] ?? '[]')),
                            $postId
                        );
                        $this->pdo->prepare('UPDATE posted_posts SET buttons_json=:buttons WHERE id=:id')
                            ->execute([
                                'buttons' => $buttons !== [] ? $this->encodeJson($buttons) : null,
                                'id' => $postId,
                            ]);
                    }
                    return $postId;
                }
            );
        } catch (Throwable $exception) {
            $message=str_contains(mb_strtolower($exception->getMessage()),'компан')
                ?$exception->getMessage()
                :'Нет права публиковать в этом канале.';
            $this->telegram->sendMessage($userId,$message);
            return;
        }
        $this->showDraftActions($userId,$postId,'Черновик создан из шаблона.');
    }

    private function deleteTemplate(int $userId,int $templateId):void
    {
        $this->deleteTelegramTemplateMedia($userId,$templateId);
    }

    private function startAddChannel(int $userId, ?array $target = null): void
    {
        $this->setState($userId, 'await_channel_ref', ['input_token' => bin2hex(random_bytes(12))]);
        $this->sendOrEditMessage(
            $userId,
            "Чтобы подключить канал:\n\n"
            . "1. Сделайте @" . $this->botUsername() . " администратором, дав следующие права:\n\n"
            . "✓ Отправка сообщений\n✓ Удаление сообщений\n✓ Редактирование сообщений\n\n"
            . "2. Отправьте ссылку https://t.me/username, @username или числовой ID канала (например, -1001234567890). Также можно переслать любое сообщение из канала.\n\n"
            . "Для закрытого канала используйте ID или пересланное сообщение: пригласительная ссылка не подходит.\n\n"
            . ($this->telegramSubscriptionPaid()?'':"GitPosted бесплатный — подписка для подключения и работы с каналом не нужна."),
            ['reply_markup' => ['inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'settings:root']]]]],
            $target
        );
    }

    private function telegramChannelRegistrationId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) return null;
        $id=(string)$value;
        if (preg_match('/^-[1-9][0-9]{0,18}$/D',$id)!==1) return null;
        $absolute=substr($id,1);
        if (strlen($absolute)>19 || (strlen($absolute)===19 && strcmp($absolute,'9223372036854775808')>0)) return null;
        return $id;
    }

    private function telegramChannelRegistrationReference(array $message): ?string
    {
        // A genuine forwarded channel origin identifies the channel even when
        // the forwarded post's body contains a link to somewhere else.
        $origin=$this->forwardedChannelOrigin($message);
        if ($origin!==null) return $this->telegramChannelRegistrationId($origin['chat_id']);
        if (!isset($message['text']) || !is_string($message['text'])) return null;
        $text=trim($message['text']);
        if ($text==='' || strlen($text)>256) return null;
        $id=$this->telegramChannelRegistrationId($text);
        if ($id!==null) return $id;
        if (preg_match('/^@([A-Za-z][A-Za-z0-9_]{3,31})$/D',$text,$match)===1) return '@'.$match[1];
        if (preg_match('~^(?:https?://)?t\\.me/([A-Za-z][A-Za-z0-9_]{3,31})/?$~iD',$text,$match)!==1) return null;
        if (in_array(strtolower($match[1]),['joinchat','addstickers','addemoji','share','proxy','socks','iv','login','confirmphone','setlanguage'],true)) return null;
        return '@'.$match[1];
    }

    private function telegramChannelRegistrationUsernameMatches(array $chat,string $requested): bool
    {
        $names=[];
        if (is_string($chat['username']??null)) $names[]=$chat['username'];
        // A channel may resolve through an active additional username, while
        // its primary username is different or absent in ChatFullInfo.
        if (is_array($chat['active_usernames']??null)) $names=[...$names,...$chat['active_usernames']];
        foreach ($names as $name) {
            if (is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/D',$name)===1
                && strcasecmp($requested,$name)===0) return true;
        }
        return false;
    }

    private function registerChannelFromMessage(int $userId, array $message, array $expectedStateData): void
    {
        if(!PostedTelegramRootedAccess::canConnect($this->pdo,$this->config,$userId))throw new RuntimeException('Администратор не разрешил подключать собственные каналы.');
        $current = $this->pdo->prepare(
            "SELECT telegram_id FROM posted_users WHERE telegram_id=:user AND is_active=1
               AND state='await_channel_ref' AND state_data <=> CAST(:data AS JSON) LIMIT 1"
        );
        $current->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
        if ($current->fetchColumn() === false) return;
        $chatRef=$this->telegramChannelRegistrationReference($message);
        if ($chatRef===null) {
            $this->telegram->sendMessage($userId, 'Отправьте ссылку вида https://t.me/username, @username, числовой ID канала со знаком минус или пересланное сообщение из канала. Для закрытого канала нужны ID или пересланное сообщение; пригласительная ссылка не подходит.');
            return;
        }

        try {
            if(!PostedTelegramRootedAccess::canConnect($this->pdo,$this->config,$userId))throw new RuntimeException('Право подключения каналов отозвано.');
            $chatResult = $this->telegram->call('getChat', ['chat_id' => $chatRef]);
            $chat = (array) ($chatResult['result'] ?? []);
        } catch (Throwable $exception) {
            $this->telegram->sendMessage($userId, 'Не удалось найти канал. Проверьте ссылку или ID и убедитесь, что бот уже добавлен администратором.');
            return;
        }
        $chatId=$this->telegramChannelRegistrationId($chat['id']??null);
        if (($chatResult['ok']??null)!==true || ($chat['type']??null)!=='channel' || $chatId===null) {
            $this->telegram->sendMessage($userId, 'Telegram не подтвердил канал. Отправьте ссылку, @username или ID именно канала; личные аккаунты и группы не подходят.');
            return;
        }
        if (($chatRef[0]==='@' && !$this->telegramChannelRegistrationUsernameMatches($chat,ltrim($chatRef,'@')))
            || ($chatRef[0]!=='@' && $chatRef!==$chatId)) {
            $this->telegram->sendMessage($userId, 'Telegram вернул другой канал. Проверьте ссылку или ID и попробуйте подключить канал заново.');
            return;
        }

        $registrationProofStarted=hrtime(true);
        try {
            $me = $this->telegram->call('getMe');
            $botIdentity=$me['result']??null;
            if (($me['ok']??null)!==true || !is_array($botIdentity) || !is_int($botIdentity['id']??null)
                || $botIdentity['id']<=0 || ($botIdentity['is_bot']??null)!==true) {
                $this->telegram->sendMessage($userId,'Telegram не подтвердил личность бота. Попробуйте подключить канал позже.');
                return;
            }
            $botId=$botIdentity['id'];
            $botResult = $this->telegram->call('getChatMember', ['chat_id'=>$chatId,'user_id'=>$botId]);
            $bot = (array) ($botResult['result'] ?? []);
            $status = (string) ($bot['status'] ?? '');
            $canPost = ($bot['can_post_messages']??null)===true ? 1 : 0;
            $canEdit = ($bot['can_edit_messages']??null)===true ? 1 : 0;
            $canDelete = ($bot['can_delete_messages']??null)===true ? 1 : 0;
            if (($botResult['ok']??null)!==true || ($bot['user']['id']??null)!==$botId || ($bot['user']['is_bot']??null)!==true
                || $status !== 'administrator' || !$canPost || !$canEdit || !$canDelete) {
                $this->telegram->sendMessage($userId, 'Боту нужны права администратора на отправку, редактирование и удаление сообщений. Выдайте все три права и попробуйте снова.');
                return;
            }
            $memberResult=$this->telegram->call('getChatMember',['chat_id'=>$chatId,'user_id'=>$userId]);
            $member=(array)($memberResult['result']??[]);
            $memberStatus=(string)($member['status']??'');
        } catch (Throwable $exception) {
            $this->telegram->sendMessage($userId,'Не удалось проверить права в канале. Проверьте права бота и повторите подключение позже.');
            return;
        }
        $existing=$this->channelByChatId($chatId);
        $existingOwner = is_array($existing) ? (int) ($existing['added_by'] ?? 0) : 0;
        $existingPause=is_array($existing)?PostedTelegramAuthorityPause::read($this->pdo,$existing):null;
        $closedReconnect=is_array($existing)&&$existingOwner<=0&&($existingPause['phase']??null)==='closed_explicitly';
        if(is_array($existing)&&!$closedReconnect&&PostedTelegramAuthorityPause::blocked($this->pdo,$existing)){
            $this->telegram->sendMessage($userId,'Канал приостановлен. Посты, даты и повторы сохранены. Откройте сохранённые публикации и проверьте права перед возобновлением.',['reply_markup'=>['inline_keyboard'=>[[['text'=>'Проверить права','callback_data'=>'pause:verify:'.(int)$existing['id'].':settings']]]]]);return;
        }
        $existingOwnerIsAdministrator = null;
        $freezePermissionIds = null;
        $actorIds = [$userId];
        if (($memberResult['ok']??null)!==true || ($member['user']['id']??null)!==$userId || ($member['user']['is_bot']??null)!==false
            || !in_array($memberStatus, ['creator', 'administrator'], true)) {
            $this->telegram->sendMessage(
                $userId,
                'Подключить канал может только его владелец или администратор.'
            );
            return;
        }
        if ($existingOwner > 0 && $existingOwner !== $userId) {
            $existingOwnerIsAdministrator=$this->telegramTransferredChannelOwnerReceipt($existing) !== null
                ? true
                : $this->telegramChannelAdministratorStatus($chatId,$existingOwner);
            if($existingOwnerIsAdministrator===null){
                $this->telegram->sendMessage(
                    $userId,
                    'Не удалось проверить права прежнего владельца GitPosted. Попробуйте подключить канал ещё раз позже.'
                );
                return;
            }
            if($existingOwnerIsAdministrator){
                $this->telegram->sendMessage(
                    $userId,
                    'У этого канала уже назначен другой действующий владелец GitPosted.'
                );
                return;
            }
            // The ownership freeze can clear each editor's channel workflow.
            // Gather that actor set before the transaction, then verify it with
            // a current read after acquiring all of those actors in ID order.
            $permissionUsers=$this->pdo->prepare('SELECT user_id FROM posted_permissions WHERE channel_id=:channel ORDER BY user_id');
            $permissionUsers->execute(['channel'=>(int)$existing['id']]);
            $freezePermissionIds=array_values(array_unique(array_map('intval',$permissionUsers->fetchAll(PDO::FETCH_COLUMN))));
            sort($freezePermissionIds,SORT_NUMERIC);
            $actorIds=array_values(array_unique([$userId,$existingOwner,...$freezePermissionIds]));
            sort($actorIds,SORT_NUMERIC);
        }

        PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        try {
            if(!PostedTelegramRootedAccess::canConnect($this->pdo,$this->config,$userId))throw new RuntimeException('Право подключения каналов отозвано.');
            $lockedExisting = null;
            // Known channel rows precede the actor, as in every channel action.
            // Do not lock a missing chat-id range before the actor: compatible
            // gap locks cannot serialize concurrent registration or transfer.
            if (is_array($existing)) {
                $known = $this->pdo->prepare('SELECT * FROM posted_channels WHERE id=:id LIMIT 1 FOR UPDATE');
                $known->execute(['id'=>(int)$existing['id']]);
                $lockedExisting=$known->fetch();
                if (!is_array($lockedExisting) || (string)$lockedExisting['chat_id'] !== $chatId) {
                    PostedTelegramRootedAccess::commit($this->pdo);
                    return;
                }
            }
            if ($freezePermissionIds !== null) {
                $actor=$this->pdo->prepare('SELECT telegram_id FROM posted_users WHERE telegram_id=:user LIMIT 1 FOR UPDATE');
                foreach ($actorIds as $actorId) {
                    if ($actorId <= 0) continue;
                    $actor->execute(['user'=>$actorId]); $actor->fetchColumn();
                }
            }
            if (!$this->lockActiveTelegramActor($userId)) {
                PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            $current = $this->pdo->prepare(
                "SELECT telegram_id FROM posted_users WHERE telegram_id=:user AND is_active=1
                   AND state='await_channel_ref' AND state_data <=> CAST(:data AS JSON) LIMIT 1 FOR UPDATE"
            );
            $current->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
            if ($current->fetchColumn() === false) {
                PostedTelegramRootedAccess::commit($this->pdo);
                return;
            }
            if(is_array($lockedExisting)){
                $lockedPause=PostedTelegramAuthorityPause::read($this->pdo,$lockedExisting,true);
                $closedReconnect=$closedReconnect&&(int)($lockedExisting['added_by']??0)<=0&&($lockedPause['phase']??null)==='closed_explicitly';
                if(!$closedReconnect&&PostedTelegramAuthorityPause::blocked($this->pdo,$lockedExisting,true))throw new PostedTelegramAuthorityPauseException('paused');
            }
            $lockedOwner = is_array($lockedExisting) ? (int)($lockedExisting['added_by']??0) : 0;
            if ($lockedOwner > 0 && $lockedOwner !== $userId) {
                if ($this->telegramTransferredChannelOwnerReceipt($lockedExisting) !== null) {
                    throw new RuntimeException('У этого канала уже назначен другой действующий владелец GitPosted.');
                }
                if ($lockedOwner !== $existingOwner || $existingOwnerIsAdministrator !== false) {
                    throw new RuntimeException('Владелец канала изменился. Подключите канал заново.');
                }
                $permissionUsers=$this->pdo->prepare('SELECT user_id FROM posted_permissions WHERE channel_id=:channel ORDER BY user_id FOR UPDATE');
                $permissionUsers->execute(['channel'=>(int)$lockedExisting['id']]);
                $currentPermissionIds=array_values(array_unique(array_map('intval',$permissionUsers->fetchAll(PDO::FETCH_COLUMN))));
                sort($currentPermissionIds,SORT_NUMERIC);
                if ($currentPermissionIds !== $freezePermissionIds) {
                    throw new RuntimeException('Состав редакторов канала изменился. Подключите канал заново.');
                }
                // Freeze only after both the original prompt and the channel's
                // current owner are verified. Its writes share this transaction.
                if (!$this->freezeTelegramChannelOwnership((int)$lockedExisting['id'],$lockedOwner)) {
                    throw new RuntimeException('Не удалось освободить канал после отзыва прав прежнего владельца. Попробуйте ещё раз.');
                }
            }
            if(is_array($lockedExisting)&&$lockedOwner>0&&$lockedOwner!==$userId){
                PostedTelegramRootedAccess::commit($this->pdo);$this->telegram->sendMessage($userId,'Канал приостановлен после изменения прав прежнего владельца. Посты и даты сохранены; смена владельца требует отдельной проверки.');return;
            }
            $stmt = $this->pdo->prepare(
                "INSERT INTO posted_channels
                    (chat_id,title,username,is_active,bot_status,bot_can_post,bot_can_edit,bot_can_delete,added_by)
                 VALUES (:chat_id,:title,:username,1,:bot_status,:can_post,:can_edit,:can_delete,:added_by)
                 ON DUPLICATE KEY UPDATE
                    title=VALUES(title),username=VALUES(username),
                    is_active=IF(admin_blocked=1,0,1),is_archived=0,bot_status=VALUES(bot_status),
                    bot_can_post=VALUES(bot_can_post),bot_can_edit=VALUES(bot_can_edit),bot_can_delete=VALUES(bot_can_delete),
                    added_by=COALESCE(added_by,VALUES(added_by))"
            );
            $stmt->execute([
                'chat_id'=>$chatId,'title'=>(string)($chat['title']??$chatId),
                'username'=>isset($chat['username'])?(string)$chat['username']:null,
                'bot_status'=>$status,'can_post'=>$canPost,'can_edit'=>$canEdit,'can_delete'=>$canDelete,'added_by'=>$userId,
            ]);
            $channel=$this->channelByChatId($chatId);
            if(!$channel)throw new RuntimeException('Channel was not saved.');
            if ((int) ($channel['added_by'] ?? 0) !== $userId) {
                throw new RuntimeException('Channel already has another GitPosted owner.');
            }
            if($closedReconnect)PostedTelegramAuthorityPause::reopenExplicitLocked($this->pdo,$channel,$userId,['actor_id'=>$userId,'chat_id'=>$chatId,'actor_admin'=>true,'bot_admin'=>true,'monotonic'=>$registrationProofStarted]);
            // A new human-admin registration starts a fresh authority binding.
            $this->clearTelegramChannelTransferReceipt((int) $channel['id']);
            $this->grant($userId,(int)$channel['id'],true,true,true,true,$userId);
            $consume=$this->pdo->prepare(
                "UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user
                   AND state='await_channel_ref' AND state_data <=> CAST(:data AS JSON)"
            );
            $consume->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
            if ($consume->rowCount() !== 1) throw new RuntimeException('Подтверждение подключения изменилось. Повторите подключение.');
            PostedTelegramRootedAccess::commit($this->pdo);
        } catch(Throwable $exception) {
            if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);
            $this->telegram->sendMessage($userId,'Не удалось сохранить канал: '.$exception->getMessage());
            return;
        }
        $title = (string) ($chat['title'] ?? $chatId);
        $ownerText = 'Вы назначены владельцем канала в GitPosted. Только вы можете назначать редакторов и отключать его.';
        $keyboard = [];
        $keyboard[] = [['text' => '➕ Добавить ещё канал', 'callback_data' => 'settings:add_channel']];
        $this->telegram->sendMessage(
            $userId,
            "✅ Вы успешно подключили канал «{$title}» к GitPosted.\n\n"
            . "Права бота проверены:\n"
            . "✓ Отправка сообщений\n"
            . "✓ Удаление сообщений\n"
            . "✓ Редактирование сообщений\n\n"
            . $ownerText,
            [
                'message_effect_id' => '5046509860389126442',
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ]
        );
        if($this->telegramSubscriptionPaid()){
            $subscriptionState=$this->telegramPolicyPreflightChannel($channel,'basic');
            $this->telegramSubscriptionChannelConnected($userId,$channel,is_int($subscriptionState['member_count']??null)?$subscriptionState['member_count']:null);
        }
    }
    private function userTimezone(int $userId): string
    {
        $user = $this->user($userId);
        $timezone = (string) ($user['user_timezone'] ?? 'Europe/Moscow');
        return isset($this->timezoneOptions()[$timezone]) ? $timezone : 'Europe/Moscow';
    }

    private function timezoneTitle(string $timezone): string
    {
        return $this->timezoneOptions()[$timezone] ?? $timezone;
    }

    private function timezoneTime(string $timezone): string
    {
        try {
            return (new DateTimeImmutable('now',new DateTimeZone($timezone)))->format('H:i');
        } catch (Throwable) {
            return (new DateTimeImmutable('now',new DateTimeZone('Europe/Moscow')))->format('H:i');
        }
    }

    private function timezoneOffset(string $timezone): int
    {
        try {
            $zone=new DateTimeZone($timezone);
            return intdiv($zone->getOffset(new DateTimeImmutable('now',$zone)),3600);
        } catch (Throwable) {
            return 3;
        }
    }

    private function timezoneOptions(): array
    {
        return [
            'Pacific/Kiritimati' => 'Линия смены дат', 'Pacific/Midway' => 'Самоа',
            'Pacific/Honolulu' => 'Гавайи', 'America/Anchorage' => 'Аляска',
            'America/Los_Angeles' => 'Тихоокеанское время', 'America/Phoenix' => 'Аризона',
            'America/Chicago' => 'Центральное время США', 'America/Bogota' => 'Богота',
            'America/New_York' => 'Восточное время США', 'America/Godthab' => 'Гренландия',
            'Etc/GMT+2' => 'Среднеатлантическое время', 'Atlantic/Azores' => 'Азорские острова',
            'Europe/London' => 'Лондон', 'Europe/Berlin' => 'Берлин',
            'Europe/Kaliningrad' => 'Калининград', 'Europe/Moscow' => 'Москва',
            'Europe/Samara' => 'Самара', 'Asia/Yekaterinburg' => 'Екатеринбург',
            'Asia/Omsk' => 'Омск', 'Asia/Krasnoyarsk' => 'Красноярск',
            'Asia/Irkutsk' => 'Иркутск', 'Asia/Yakutsk' => 'Якутск',
            'Asia/Vladivostok' => 'Владивосток', 'Asia/Magadan' => 'Магадан',
            'Asia/Kamchatka' => 'Камчатка', 'Pacific/Tongatapu' => 'Тонга',
        ];
    }

    private function showTimezonePicker(int $userId, ?array $target = null): void
    {
        $current = $this->userTimezone($userId);
        $keyboard = [];
        foreach ($this->timezoneOptions() as $timezone => $title) {
            $button = ['text' => $title . ' (' . $this->timezoneTime($timezone) . ')', 'callback_data' => 'tz:set:' . $timezone];
            if ($timezone === $current) { $button['style'] = 'primary'; }
            $keyboard[] = [$button];
        }
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'settings:root']];
        $offset = $this->timezoneOffset($current);
        $currentTitle = 'GMT' . ($offset >= 0 ? '+' : '') . $offset . ' ' . $this->timezoneTitle($current);
        $this->sendOrEditMessage($userId, "🏘 ЧАСОВОЙ ПОЯС\n\nВыберите часовой пояс. Время выхода постов будет отображаться в вашем часовом поясе.\n\nВаш часовой пояс: " . $currentTitle, ['reply_markup' => ['inline_keyboard' => $keyboard]], $target);
    }

    private function saveTimezone(int $userId, string $timezone, ?array $target = null): void
    {
        if (!isset($this->timezoneOptions()[$timezone])) { $this->showTimezonePicker($userId, $target); return; }
        $this->pdo->prepare('UPDATE posted_users SET user_timezone = :timezone WHERE telegram_id = :id')->execute(['timezone' => $timezone, 'id' => $userId]);
        $this->showTimezonePicker($userId, $target);
    }
    private function showAdvancedSettings(int $userId, ?array $target = null): void
    {
        $user = $this->user($userId) ?? [];
        $keyboard = [];
        foreach ($this->advancedSettingDefinitions() as $key => $definition) {
            $enabled = !empty($user[$definition['column']]);
            $keyboard[] = [[
                'text' => $key === 'folders'
                    ? '📁 ' . $definition['title']
                    : ($enabled ? (string)($definition['enabled_icon']??'✅️ ') : '☑️ ') . $definition['title'],
                'callback_data' => 'settings:adv:' . $key
                    .($key==='folders'?'':':'.($enabled?0:1)),
            ]];
        }
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'settings:root']];

        $header=PostedTelegramContentPlanUi::advancedHeader();
        $this->sendOrEditMessage(
            $userId,
            $header['text'],
            ['entities'=>$header['entities'],'reply_markup' => ['inline_keyboard' => $keyboard]],
            $target
        );
    }

    private function advancedSettingDefinitions(): array
    {
        $definitions = [
            'folders' => ['column' => 'setting_folders', 'title' => 'Папки'],
            'confirm_schedule' => ['column' => 'setting_confirm_schedule', 'title' => 'Подтверждать планирование постов'],
            'compact_plan' => ['column' => 'setting_compact_plan', 'title' => 'Сокращенный контент-план'],
            'show_plan_icons' => ['column' => 'setting_show_plan_icons', 'title' => 'Показывать иконки в отлоге'],
            'delay_alerts' => ['column' => 'setting_delay_alerts', 'title' => 'Уведомлять о задержке постов'],
        ];
        if($this->telegramWebReady())$definitions=[
            'folders'=>$definitions['folders'],
            'web_mode'=>['column'=>'setting_web_mode','title'=>'Веб-режим'],
        ]+$definitions;
        return $definitions;
    }

    private function toggleAdvancedSetting(
        int $userId,
        string $key,
        ?array $target = null,
        ?int $desiredValue = null
    ): void
    {
        $definitions = $this->advancedSettingDefinitions();
        if (!isset($definitions[$key])) {
            $this->showAdvancedSettings($userId, $target);
            return;
        }

        $column = (string) $definitions[$key]['column'];
        if($desiredValue===null){
            $stmt=$this->pdo->prepare(
                "UPDATE posted_users SET {$column}=IF({$column}=1,0,1) WHERE telegram_id=:id"
            );
            $stmt->execute(['id'=>$userId]);
        }else{
            $stmt=$this->pdo->prepare(
                "UPDATE posted_users SET {$column}=:value WHERE telegram_id=:id"
            );
            $stmt->execute(['value'=>$desiredValue===1?1:0,'id'=>$userId]);
        }
        $this->showAdvancedSettings($userId, $target);
    }

    private function showFolders(int $userId, ?array $target = null): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posted_folders WHERE owner_id=:owner ORDER BY sort_order,id');
        $stmt->execute(['owner'=>$userId]);
        $keyboard = [];
        foreach ($stmt->fetchAll() as $folder) {
            $keyboard[] = [['text'=>'📂 '.(string)$folder['title'],'callback_data'=>'folder:view:'.(int)$folder['id']]];
        }
        $keyboard[] = [['text'=>'➕ Cоздать новую папку','callback_data'=>'folder:create']];
        $keyboard[] = [['text'=>'← Назад','callback_data'=>'settings:advanced']];
        $this->sendOrEditMessage(
            $userId,
            "📂 ПАПКИ\n\nГруппируйте каналы, объединяя их в папки.",
            ['reply_markup'=>['inline_keyboard'=>$keyboard]],
            $target
        );
    }

    private function folder(int $userId, int $folderId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM posted_folders WHERE id=:id AND owner_id=:owner LIMIT 1');
        $stmt->execute(['id'=>$folderId,'owner'=>$userId]);
        $row=$stmt->fetch();
        return is_array($row)?$row:null;
    }

    private function folderChannelIds(int $folderId): array
    {
        $stmt=$this->pdo->prepare('SELECT channel_id FROM posted_folder_channels WHERE folder_id=:folder ORDER BY channel_id');
        $stmt->execute(['folder'=>$folderId]);
        return array_values(array_unique(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function showFolder(int $userId, int $folderId, ?array $target = null): void
    {
        $folder=$this->folder($userId,$folderId);
        if(!$folder){$this->showFolders($userId,$target);return;}
        $selected=$this->folderChannelIds($folderId);
        $keyboard=[];
        foreach($this->allowedChannels($userId,'post') as $channel){
            $channelId=(int)$channel['id'];
            $keyboard[]=[[
                'text'=>(in_array($channelId,$selected,true)?'✅ ':'☑️ ').(string)$channel['title'],
                'callback_data'=>'folder:toggle:'.$folderId.':'.$channelId.':'
                    .(in_array($channelId,$selected,true)?0:1),
            ]];
        }
        $keyboard[]=[
            ['text'=>'✏️ Переименовать','callback_data'=>'folder:rename:'.$folderId],
            ['text'=>'🗑️ Удалить','callback_data'=>'folder:delete:'.$folderId],
        ];
        $keyboard[]=[['text'=>'← Назад','callback_data'=>'settings:adv:folders']];
        $this->sendOrEditMessage(
            $userId,
            '📂 '.(string)$folder['title']."\n\nВыберите каналы, которые нужно объединить в эту папку.",
            ['reply_markup'=>['inline_keyboard'=>$keyboard]],
            $target
        );
    }

    private function handleFolderCallback(int $userId, string $data, ?array $target = null): void
    {
        $parts=explode(':',$data);
        $action=(string)($parts[1]??'');
        $folderId=(int)($parts[2]??0);
        if($action==='create'){
            $this->setState($userId,'await_folder_title',['folder_id'=>0,'input_token'=>bin2hex(random_bytes(12))]);
            $this->sendOrEditMessage($userId,"📂 НОВАЯ ПАПКА\n\nОтправьте название папки.",[
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'settings:adv:folders']]]],
            ],$target);
            return;
        }
        $folder=$this->folder($userId,$folderId);
        if(!$folder){$this->showFolders($userId,$target);return;}
        if($action==='view'){$this->showFolder($userId,$folderId,$target);return;}
        if($action==='toggle'){
            $channelId=(int)($parts[3]??0);
            if(!$this->can($userId,$channelId,'post'))return;
            $exists=in_array($channelId,$this->folderChannelIds($folderId),true);
            $desired=in_array((string)($parts[4]??''),['0','1'],true)
                ?(int)$parts[4]
                :($exists?0:1);
            if($desired===0){
                $this->pdo->prepare('DELETE FROM posted_folder_channels WHERE folder_id=:folder AND channel_id=:channel')
                    ->execute(['folder'=>$folderId,'channel'=>$channelId]);
            }else{
                $this->pdo->prepare('INSERT IGNORE INTO posted_folder_channels(folder_id,channel_id) VALUES(:folder,:channel)')
                    ->execute(['folder'=>$folderId,'channel'=>$channelId]);
            }
            $this->showFolder($userId,$folderId,$target);
            return;
        }
        if($action==='rename'){
            $this->setState($userId,'await_folder_title',['folder_id'=>$folderId,'input_token'=>bin2hex(random_bytes(12))]);
            $this->sendOrEditMessage($userId,"✏️ ПЕРЕИМЕНОВАНИЕ ПАПКИ\n\nОтправьте новое название.",[
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'folder:view:'.$folderId]]]],
            ],$target);
            return;
        }
        if($action==='delete'){
            $this->sendOrEditMessage($userId,'Удалить папку «'.(string)$folder['title'].'»? Каналы останутся подключёнными.',[
                'reply_markup'=>['inline_keyboard'=>[
                    [['text'=>'Да, удалить','callback_data'=>'folder:delete_yes:'.$folderId]],
                    [['text'=>'← Назад','callback_data'=>'folder:view:'.$folderId]],
                ]],
            ],$target);
            return;
        }
        if($action==='delete_yes'){
            $this->pdo->prepare('DELETE FROM posted_folders WHERE id=:id AND owner_id=:owner')
                ->execute(['id'=>$folderId,'owner'=>$userId]);
            $this->showFolders($userId,$target);
        }
    }

    private function saveFolderTitle(int $userId, int $folderId, string $title, array $expectedStateData): void
    {
        if ((int) ($expectedStateData['folder_id'] ?? 0) !== $folderId) return;
        $current = $this->pdo->prepare(
            "SELECT telegram_id FROM posted_users WHERE telegram_id=:user AND is_active=1
               AND state='await_folder_title' AND state_data <=> CAST(:data AS JSON) LIMIT 1"
        );
        $current->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
        if ($current->fetchColumn() === false) return;
        $title=trim($title);
        if($title===''||mb_strlen($title)>64){
            $this->telegram->sendMessage($userId,'Название папки должно содержать от 1 до 64 символов.');
            return;
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try{
            // Match transfer's actor -> owner-range order. A delayed old
            // rename must not turn into a new folder after ownership changes.
            if (!$this->lockActiveTelegramActor($userId)) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            $range=$this->pdo->prepare('SELECT id FROM posted_folders WHERE owner_id=:owner ORDER BY id FOR UPDATE');
            $range->execute(['owner'=>$userId]); $range->fetchAll(PDO::FETCH_COLUMN);
            $current = $this->pdo->prepare(
                "SELECT telegram_id FROM posted_users WHERE telegram_id=:user AND is_active=1
                   AND state='await_folder_title' AND state_data <=> CAST(:data AS JSON) LIMIT 1 FOR UPDATE"
            );
            $current->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
            if ($current->fetchColumn() === false) {
                if ($ownsTransaction) $this->pdo->commit();
                return;
            }
            if($folderId>0){
                $folder=$this->pdo->prepare('SELECT id FROM posted_folders WHERE id=:id AND owner_id=:owner LIMIT 1 FOR UPDATE');
                $folder->execute(['id'=>$folderId,'owner'=>$userId]);
                if ($folder->fetchColumn() === false) {
                    if ($ownsTransaction) $this->pdo->commit();
                    return;
                }
                $this->pdo->prepare('UPDATE posted_folders SET title=:title WHERE id=:id AND owner_id=:owner')
                    ->execute(['title'=>$title,'id'=>$folderId,'owner'=>$userId]);
            }else{
                $this->pdo->prepare('INSERT INTO posted_folders(owner_id,title) VALUES(:owner,:title)')
                    ->execute(['owner'=>$userId,'title'=>$title]);
                $folderId=(int)$this->pdo->lastInsertId();
            }
            $this->pdo->prepare("UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user AND state='await_folder_title' AND state_data <=> CAST(:data AS JSON)")
                ->execute(['user'=>$userId,'data'=>$this->encodeJson($expectedStateData)]);
            if ($ownsTransaction) $this->pdo->commit();
        }catch(Throwable $exception){
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($exception instanceof PDOException && (int)($exception->errorInfo[1]??0)===1062) {
                $this->telegram->sendMessage($userId,'Папка с таким названием уже существует.');
                return;
            }
            throw $exception;
        }
        $this->showFolder($userId,$folderId);
    }

    private function showFolderChannelPicker(int $userId, int $folderId, ?array $target = null): void
    {
        $folder=$this->folder($userId,$folderId);
        if(!$folder){$this->showChannelPicker($userId,'post_channel',$target);return;}
        $allowed=[];
        foreach($this->allowedChannels($userId,'post') as $channel)$allowed[(int)$channel['id']]=$channel;
        $keyboard=[];
        foreach($this->folderChannelIds($folderId) as $channelId){
            if(!isset($allowed[$channelId]))continue;
            $keyboard[]=[['text'=>(string)$allowed[$channelId]['title'],'callback_data'=>'post_channel:'.$channelId]];
        }
        $keyboard[]=[['text'=>'← Назад','callback_data'=>'post_channels']];
        $this->sendOrEditMessage(
            $userId,
            '📂 '.(string)$folder['title']."\n\nВыберите канал.",
            ['reply_markup'=>['inline_keyboard'=>$keyboard]],
            $target
        );
    }

    private function showSettings(int $userId, ?array $target = null): void
    {
        $channels = $this->allowedChannels($userId, 'post');
        $keyboard = [];
        if($this->telegramSubscriptionPaid())$keyboard[]=[['text'=>$this->telegramSubscriptionText($userId,'💰 Подписка','💰 Subscription'),'callback_data'=>'sub:open']];
        $channelButtons = [];
        foreach ($channels as $channel) {
            $channelButtons[] = ['text' => (string) $channel['title'], 'callback_data' => 'settings_channel:' . (int) $channel['id']];
        }
        foreach (array_chunk($channelButtons, 2) as $row) {
            $keyboard[] = $row;
        }
        $pausedEntry=$this->telegramAuthorityPauseEntryButton($userId,'settings');if($pausedEntry!==null)$keyboard[]=[$pausedEntry];
        $tz = $this->userTimezone($userId);
        $keyboard[] = [['text' => $this->telegramUiText($userId, 'button.add_channel', '➕ Добавить новый канал'), 'callback_data' => 'settings:add_channel']];
        if ($this->ownedChannels($userId) !== []) {
            $keyboard[] = [['text' => '👥 Редакторы каналов', 'callback_data' => 'settings:admins']];
            $keyboard[] = [['text' => '💼 Рекламные менеджеры', 'callback_data' => 'settings:managers']];
        }
        $timezoneTitle = $this->telegramUiLanguage($userId) === 'en' && $tz === 'Europe/Moscow' ? 'Moscow' : $this->timezoneTitle($tz);
        $keyboard[] = [['text' => $this->telegramUiText($userId, 'settings.timezone', 'Часовой пояс: ' . $this->timezoneTitle($tz) . ' (' . $this->timezoneTime($tz) . ')', ['timezone'=>$timezoneTitle, 'time'=>$this->timezoneTime($tz)]), 'callback_data' => 'settings:timezone']];
        if($this->telegramSubscriptionPaid())$keyboard[]=[['text'=>$this->telegramSubscriptionText($userId,'Реферальная программа','👥 Referral program'),'callback_data'=>'sub:referral']];
        $keyboard[] = [['text' => $this->telegramUiText($userId, 'button.advanced', 'Продвинутые настройки'), 'callback_data' => 'settings:advanced']];
        $keyboard[] = [['text' => '♻️ Перенос данных', 'callback_data' => 'transfer:open']];
        $this->sendOrEditMessage($userId, $this->telegramUiText($userId, 'settings.body', $this->customEmojiHtml('5258096772776991776', '⚙️') . " <b>НАСТРОЙКИ</b>\n\nВ этом разделе вы можете настроить работу с ботом, с отдельным каналом, а также добавить новый канал в GitPosted.", ['brand'=>'GitPosted']), [
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ], $target);
    }

    private function showAdminChannels(int $userId, ?array $target = null): void
    {
        $channels = $this->ownedChannels($userId);
        if ($channels === []) {
            $this->sendOrEditMessage($userId, "👥 РЕДАКТОРЫ\n\nУ вас пока нет собственных каналов, где можно управлять редакторами.", [
                'reply_markup' => ['inline_keyboard' => [[['text' => '← Назад', 'callback_data' => 'settings:root']]]],
            ], $target);
            return;
        }
        $keyboard = [];
        foreach ($channels as $channel) {
            $channelId = (int) $channel['id'];
            $keyboard[] = [[
                'text' => (string) $channel['title'] . ' · ' . $this->channelEditorCount($channelId),
                'callback_data' => 'channel_editors:' . $channelId . ':0',
            ]];
        }
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'settings:root']];
        $this->sendOrEditMessage($userId, "👥 РЕДАКТОРЫ\n\nВыберите канал, где нужно выдать, изменить или отозвать доступ.", [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ], $target);
    }

    private function showManagerChannels(int $userId, ?array $target = null): void
    {
        $channels = $this->ownedChannels($userId);
        if ($channels === []) {
            $this->sendOrEditMessage($userId, "💼 РЕКЛАМНЫЕ МЕНЕДЖЕРЫ\n\nУ вас пока нет собственных каналов.", [
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'settings:root']]]],
            ], $target);
            return;
        }
        $keyboard = [];
        foreach ($channels as $channel) {
            $channelId = (int) $channel['id'];
            $keyboard[] = [[
                'text'=>(string) $channel['title'].' · '.$this->channelManagerCount($channelId),
                'callback_data'=>'channel_managers:'.$channelId.':0',
            ]];
        }
        $keyboard[] = [['text'=>'← Назад','callback_data'=>'settings:root']];
        $this->sendOrEditMessage($userId, "💼 РЕКЛАМНЫЕ МЕНЕДЖЕРЫ\n\nВыберите канал для управления менеджерами.", [
            'reply_markup'=>['inline_keyboard'=>$keyboard],
        ], $target);
    }

    private function showMyChannels(int $userId, ?array $target = null): void
    {
        $channels = $this->allowedChannels($userId, 'post');
        $pausedEntry=$this->telegramAuthorityPauseEntryButton($userId,'channels');
        $keyboard = [];
        $lines = ["📣 МОИ КАНАЛЫ\n"];
        if ($channels === []) {
            $lines[] = $pausedEntry===null?'У вас пока нет подключённых каналов.':'Нет каналов с текущим доступом. Сохранённые посты доступны в разделе ниже.';
        } else {
            $lines[] = 'Выберите канал:';
            foreach ($channels as $channel) {
                $channelId = (int) $channel['id'];
                $rights = [];
                if ($this->can($userId, $channelId, 'post')) {
                    $rights[] = 'постинг';
                }
                if ($this->can($userId, $channelId, 'schedule')) {
                    $rights[] = 'планирование';
                }
                if ($this->can($userId, $channelId, 'manage_posts')) {
                    $rights[] = 'управление постами';
                }
                $role = $this->isChannelOwner($userId, $channelId) ? 'владелец' : 'редактор';
                $lines[] = '- ' . (string) $channel['title'] . ' · ' . $role . ': ' . implode(', ', $rights);
                $keyboard[] = [['text' => (string) $channel['title'], 'callback_data' => 'settings_channel:' . $channelId]];
            }
        }
        if($pausedEntry!==null)$keyboard[]=[$pausedEntry];
        $keyboard[] = [['text' => '➕ Добавить новый канал', 'callback_data' => 'settings:add_channel']];
        $keyboard[] = [['text' => '← Назад', 'callback_data' => 'settings:root']];
        $this->sendOrEditMessage($userId, implode("\n", $lines), [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ], $target);
    }

    private function showChannels(int $userId): void
    {
        $channels = $this->allowedChannels($userId, 'post');
        $lines = ['Мои каналы'];
        foreach ($channels as $channel) {
            $lines[] = '- ' . (string) $channel['title'] . ' (' . (string) $channel['chat_id'] . ')';
        }
        if (count($lines) === 1) {
            $lines[] = 'Нет каналов.';
        }
        $this->telegram->sendMessage($userId, implode("\n", $lines));
    }

    private function showChannelSchedule(int $userId, int $channelId, ?array $target = null): void
    {
        if (!$this->can($userId,$channelId,'manage_admins')) return;
        $this->setState($userId,'await_channel_schedule',['channel_id'=>$channelId]);
        $timezone=$this->userTimezone($userId);
        $offset=$this->timezoneOffset($timezone);
        $timezoneLabel='GMT'.($offset>=0?'+':'').$offset.' '.$this->timezoneTitle($timezone);
        $text = "🔼 РАСПИСАНИЕ\n\nВ этом разделе вы можете установить ежедневное расписание выхода публикаций в канале " . $this->channelTitle($channelId) . ", чтобы планировать посты в один клик.\n\nЧтобы задать расписание, отправьте время выхода постов в вашем часовом поясе (" . $timezoneLabel . ") в виде списка в любом удобном формате.";
        $extra=['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'settings_channel:'.$channelId]]]]];
        $photo=rtrim((string)$this->config['data_dir'],'/\\').'/assets/schedule.jpg';
        if($target!==null && !empty($target['has_media'])){
            $this->telegram->editMessageCaption((string)$target['chat_id'],(int)$target['message_id'],$text,$extra);
        }else{
            if($target!==null){
                try{$this->telegram->deleteMessage((string)$target['chat_id'],(int)$target['message_id']);}catch(Throwable){}
            }
            $this->telegram->sendPhoto($userId,$photo,$text,$extra);
        }
    }

    private function showChannelPostSettings(int $userId, int $channelId, ?array $target = null): void
    {
        $c=$this->channel($channelId); if(!$c||!$this->can($userId,$channelId,'manage_admins'))return;
        $kb=[
            [['text'=>'Автоподпись: '.(!empty($c['post_autosignature'])?'да':'нет'),'callback_data'=>'chpost:autosignature:'.$channelId],['text'=>'Превью','callback_data'=>'chpost:preview:'.$channelId]],
            [['text'=>!empty($c['post_silent'])?'🔕':'🔔','callback_data'=>'chpost:silent:'.$channelId.':'.(empty($c['post_silent'])?1:0)],['text'=>'Кнопки','callback_data'=>'chpost:buttons:'.$channelId]],
            [['text'=>(!empty($c['post_pin'])?'✅️':'☑️').' Закрепить','callback_data'=>'chpost:pin:'.$channelId.':'.(empty($c['post_pin'])?1:0)]],
            [['text'=>'← Назад','callback_data'=>'settings_channel:'.$channelId]],
        ];
        $this->sendOrEditMessage($userId,'Укажите настройки по умолчанию для публикаций в канале '.$this->channelTitle($channelId).'.',['reply_markup'=>['inline_keyboard'=>$kb]],$target);
    }

    private function triStateIcon(mixed $value): string
    {
        return $value === null ? '❔' : ((int)$value === 1 ? '✅️' : '☑️');
    }

    private function nextTriStatePayload(mixed $value):string
    {
        if($value===null)return '1';
        return (int)$value===1?'0':'n';
    }

    private function cycleTriState(
        int $userId,
        int $channelId,
        string $column,
        ?string $desiredState=null
    ):void
    {
        if(!in_array($column,['post_preview','post_preview_large','post_preview_above'],true))return;
        $this->withOwnedTelegramChannelLock(
            $userId,
            $channelId,
            function () use ($channelId, $column, $desiredState): void {
                if(in_array($desiredState,['n','0','1'],true)){
                    $this->pdo->prepare(
                        "UPDATE posted_channels SET {$column}=:value WHERE id=:id"
                    )->execute([
                        'value'=>$desiredState==='n'?null:(int)$desiredState,
                        'id'=>$channelId,
                    ]);
                    return;
                }
                $this->pdo->exec(
                    "UPDATE posted_channels SET {$column}=CASE
                        WHEN {$column} IS NULL THEN 1
                        WHEN {$column}=1 THEN 0
                        ELSE NULL END
                     WHERE id=".(int)$channelId
                );
            }
        );
    }

    private function showChannelPreviewSettings(int $userId,int $channelId,?array $target=null):void
    {
        $c=$this->channel($channelId);if(!$c||!$this->can($userId,$channelId,'manage_admins'))return;
        $position=$c['post_preview_above']===null?'не задано':((int)$c['post_preview_above']===1?'сверху':'снизу');
        $text="↔️ ПРЕВЬЮ ССЫЛОК\n\nВ этом разделе вы можете задать настройки по умолчанию для превью ссылок.\n\n«✅️» - Всегда включено\n«☑️» - Всегда выключено\n«❔» - Не задано";
        $kb=[
            [['text'=>$this->triStateIcon($c['post_preview']).' Превью','callback_data'=>'chpost:preview_state:'.$channelId.':'.$this->nextTriStatePayload($c['post_preview'])]],
            [['text'=>$this->triStateIcon($c['post_preview_large']).' Увеличить фото','callback_data'=>'chpost:preview_large_state:'.$channelId.':'.$this->nextTriStatePayload($c['post_preview_large'])]],
            [['text'=>'Расположение: '.$position,'callback_data'=>'chpost:preview_position_state:'.$channelId.':'.$this->nextTriStatePayload($c['post_preview_above'])]],
            [['text'=>'← Назад','callback_data'=>'channel_post_settings:'.$channelId]],
        ];
        $this->sendOrEditMessage($userId,$text,['link_preview_options'=>['url'=>'https://telegra.ph/file/dc8e4c222ca5b7dac50ad.png','prefer_large_media'=>true],'reply_markup'=>['inline_keyboard'=>$kb]],$target);
    }

    private function channelButtonTypeKeyboard(int $channelId,string $selected='url'):array
    {
        $url=['text'=>'URL-кнопки','callback_data'=>'chpost:buttons:'.$channelId];
        $reactions=['text'=>'Реакции','callback_data'=>'chpost:reactions:'.$channelId];
        $hidden=['text'=>'Скрытое продолжение','callback_data'=>'chpost:hidden:'.$channelId];
        if($selected==='url')$url['style']='primary';
        if($selected==='reactions')$reactions['style']='primary';
        if($selected==='hidden')$hidden['style']='primary';
        return [[$url,$reactions],[ $hidden ],[['text'=>'← Назад','callback_data'=>'channel_post_settings:'.$channelId]]];
    }

    private function replaceChannelDefaultButtons(
        int $userId,
        int $channelId,
        string $type,
        array $replacementRows
    ): void
    {
        if (!in_array($type, ['url', 'reaction', 'hidden'], true)) {
            throw new InvalidArgumentException('Unsupported channel button type.');
        }
        $this->withOwnedTelegramChannelLock(
            $userId,
            $channelId,
            function (array $channel) use ($channelId, $type, $replacementRows): void {
                $preservedRows = [];
                foreach ($this->decodeJson((string) ($channel['post_buttons_json'] ?? '')) as $row) {
                    $preserved = [];
                    foreach ((array) $row as $button) {
                        if (!is_array($button)) {
                            continue;
                        }
                        $callback = (string) ($button['callback_data'] ?? '');
                        $buttonType = isset($button['url'])
                            ? 'url'
                            : (str_starts_with($callback, 'reaction:')
                                ? 'reaction'
                                : (str_starts_with($callback, 'hidden:') ? 'hidden' : 'other'));
                        if ($buttonType !== $type) {
                            $preserved[] = $button;
                        }
                    }
                    if ($preserved !== []) {
                        $preservedRows[] = $preserved;
                    }
                }
                $buttons = array_merge($preservedRows, array_values(array_filter(
                    $replacementRows,
                    static fn (mixed $row): bool => is_array($row) && $row !== []
                )));
                $this->pdo->prepare(
                    'UPDATE posted_channels SET post_buttons_json=:buttons WHERE id=:id'
                )->execute([
                    'buttons'=>$buttons !== [] ? $this->encodeJson($buttons) : null,
                    'id'=>$channelId,
                ]);
            }
        );
    }

    private function showChannelButtonsSettings(int $userId,int $channelId,?array $target=null):void
    {
        $this->setState($userId,'await_channel_buttons',['channel_id'=>$channelId]);
        $text=str_replace('⛓ КНОПКИ: URL-кнопки','⛓ URL-КНОПКИ',$this->buttonsHelpText());
        $this->sendOrEditMessage($userId,$text,['reply_markup'=>['inline_keyboard'=>$this->channelButtonTypeKeyboard($channelId,'url')]],$target);
    }

    private function showChannelReactionsSettings(int $userId,int $channelId,?array $target=null):void
    {
        $this->setState($userId,'await_channel_reactions',['channel_id'=>$channelId]);
        $this->sendOrEditMessage($userId,"❤️ КНОПКИ: реакции\n\nОтправьте боту список реакций, разделяя их символом «/», в следующем формате:\n\n👍/👎/🤯\n\nЧтобы разместить реакции в несколько строчек, разделите их переносом строки:\n\n👍/👎\n🔥/💀\n\nЧтобы установить цвет кнопке, добавьте «!r», «!g» или «!b» перед кнопкой:\n\n!r 👍 Красный / !b 👎 Синий",['reply_markup'=>['inline_keyboard'=>$this->channelButtonTypeKeyboard($channelId,'reactions')]],$target);
    }

    private function showChannelHiddenSettings(int $userId,int $channelId,?array $target=null):void
    {
        $this->setState($userId,'await_channel_hidden',['channel_id'=>$channelId]);
        $this->sendOrEditMessage($userId,"👁 КНОПКИ: скрытое продолжение\n\nВ этом разделе вы можете добавить кнопку, под которой будет скрыт текст от тех, кто не подписан на ваш канал.\n\nПервая строка — название кнопки, со второй строки — скрытый текст.",['reply_markup'=>['inline_keyboard'=>$this->channelButtonTypeKeyboard($channelId,'hidden')]],$target);
    }

    private function handleChannelPostSetting(int $userId,int $channelId,string $field,?array $target=null,?string $desiredState=null):void
    {
        if(!$this->can($userId,$channelId,'manage_admins'))return;
        if($field==='autosignature'){
            $this->setState($userId,'await_channel_autosignature',['channel_id'=>$channelId,'scope'=>'post']);
            $this->sendOrEditMessage($userId,"© АВТОПОДПИСЬ\n\nВы можете настроить подпись, которая будет автоматически добавляться ко всем вашим публикациям.\n\nОтправьте текст для автоподписи.",['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'channel_post_settings:'.$channelId]]]]],$target);return;
        }
        if($field==='preview'){$this->showChannelPreviewSettings($userId,$channelId,$target);return;}
        if($field==='preview_state'){$this->cycleTriState($userId,$channelId,'post_preview',$desiredState);$this->showChannelPreviewSettings($userId,$channelId,$target);return;}
        if($field==='preview_large_state'){$this->cycleTriState($userId,$channelId,'post_preview_large',$desiredState);$this->showChannelPreviewSettings($userId,$channelId,$target);return;}
        if($field==='preview_position_state'){$this->cycleTriState($userId,$channelId,'post_preview_above',$desiredState);$this->showChannelPreviewSettings($userId,$channelId,$target);return;}
        if($field==='buttons'){$this->showChannelButtonsSettings($userId,$channelId,$target);return;}
        if($field==='reactions'){$this->showChannelReactionsSettings($userId,$channelId,$target);return;}
        if($field==='hidden'){$this->showChannelHiddenSettings($userId,$channelId,$target);return;}
        $this->toggleChannelSetting($userId,$channelId,$field,'post',$target,$desiredState);
    }

    private function showChannelAdSettings(int $userId, int $channelId, ?array $target = null): void
    {
        $c=$this->channel($channelId); if(!$c||!$this->can($userId,$channelId,'manage_admins'))return;
        $kb=[
            [['text'=>'Таймер удаления: '.($c['ad_delete_minutes']?$this->telegramDeleteTimerLabel((int)$c['ad_delete_minutes']):'нет'),'callback_data'=>'chad:timer:'.$channelId]],
            [['text'=>'Автоподпись: '.(!empty($c['ad_autosignature'])?'да':'нет'),'callback_data'=>'chad:autosignature:'.$channelId],['text'=>!empty($c['ad_silent'])?'🔕':'🔔','callback_data'=>'chad:silent:'.$channelId.':'.(empty($c['ad_silent'])?1:0)]],
            [['text'=>(!empty($c['ad_pin'])?'✅️':'☑️').' Закрепить','callback_data'=>'chad:pin:'.$channelId.':'.(empty($c['ad_pin'])?1:0)]],
            [['text'=>'🗑️ Удалить','callback_data'=>'chad:delete:'.$channelId]],
            [['text'=>'← Назад','callback_data'=>'settings_channel:'.$channelId]],
        ];
        $text='Укажите настройки по умолчанию для рекламных постов в канале '.$this->channelTitle($channelId).".\n\nПрименить настройки к посту можно, поставив галочку на кнопке «Это рекламный пост» при создании публикации.";
        $this->sendOrEditMessage($userId,$text,['reply_markup'=>['inline_keyboard'=>$kb]],$target);
    }

    private function showChannelAdTimer(int $userId,int $channelId,?array $target=null):void
    {
        $c=$this->channel($channelId);if(!$c||!$this->can($userId,$channelId,'manage_admins'))return;
        $this->setState($userId,'await_channel_ad_timer',['channel_id'=>$channelId]);
        $values=[0,5,15,30,60,120,240,360,720,1080,1440,2875,4320,7200,10080,14400];
        $selected=$this->normalizeTelegramDeleteTimerMinutes((int)($c['ad_delete_minutes']??0));
        $rows=[];
        foreach(array_chunk($values,4) as $chunk){$row=[];foreach($chunk as $value){$button=['text'=>$this->telegramDeleteTimerLabel($value),'callback_data'=>'chad:settimer:'.$channelId.':'.$value];if($selected===$value)$button['style']='primary';$row[]=$button;}$rows[]=$row;}
        $rows[]=[['text'=>'← Назад','callback_data'=>'channel_ad_settings:'.$channelId]];
        $text="🗑 ТАЙМЕР УДАЛЕНИЯ РЕКЛАМЫ\n\nВыберите срок после публикации или отправьте его сообщением.\n\n".$this->telegramDeleteTimerHint()."\n\n6 — через 6 ч\n6:30 или 6 30 — через 6 ч 30 мин\n2д — автоудаление ≈48 ч\n10д — напоминание удалить вручную через 10 дней";
        $this->sendOrEditMessage($userId,$text,['reply_markup'=>['inline_keyboard'=>$rows]],$target);
    }

    private function handleChannelAdSetting(int $userId,int $channelId,string $field,?array $target=null,?string $desiredState=null):void
    {
        if(!$this->can($userId,$channelId,'manage_admins'))return;
        if($field==='timer'){$this->showChannelAdTimer($userId,$channelId,$target);return;}

        if($field==='autosignature'){
            $this->setState($userId,'await_channel_autosignature',['channel_id'=>$channelId,'scope'=>'ad']);
            $this->sendOrEditMessage($userId,"© РЕКЛАМНАЯ АВТОПОДПИСЬ\n\nВы можете настроить подпись, которая будет автоматически добавляться ко всем вашим рекламным постам.\n\nОтправьте текст для автоподписи.",['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Назад','callback_data'=>'channel_ad_settings:'.$channelId]]]]],$target);return;
        }
        if($field==='delete'){
            $this->withOwnedTelegramChannelLock(
                $userId,
                $channelId,
                function () use ($channelId): void {
                    $this->pdo->prepare(
                        'UPDATE posted_channels
                         SET ad_delete_minutes=NULL,ad_autosignature=NULL,
                             ad_silent=1,ad_pin=0,ad_comments=0
                         WHERE id=:id'
                    )->execute(['id'=>$channelId]);
                }
            );
            $this->clearState($userId);$this->showChannelSettings($userId,$channelId,$target);return;
        }
        $this->toggleChannelSetting($userId,$channelId,$field,'ad',$target,$desiredState);
    }

    private function setChannelAdTimer(int $userId,int $channelId,int $minutes,?array $target=null):void
    {
        $minutes=$this->normalizeTelegramDeleteTimerMinutes($minutes);
        if(!$this->can($userId,$channelId,'manage_admins'))return;
        $this->withOwnedTelegramChannelLock(
            $userId,
            $channelId,
            function () use ($channelId, $minutes): void {
                $this->pdo->prepare(
                    'UPDATE posted_channels SET ad_delete_minutes=:minutes WHERE id=:id'
                )->execute(['minutes'=>$minutes>0?$minutes:null,'id'=>$channelId]);
            }
        );
        $this->clearState($userId);$this->showChannelAdTimer($userId,$channelId,$target);
    }

    private function toggleChannelSetting(int $userId,int $channelId,string $field,string $scope,?array $target=null,?string $desiredState=null): void
    {
        $map=['post'=>['silent'=>'post_silent','pin'=>'post_pin'],'ad'=>['silent'=>'ad_silent','pin'=>'ad_pin']];
        $column=$map[$scope][$field]??null;
        if($column && $this->can($userId,$channelId,'manage_admins')){
            $this->withOwnedTelegramChannelLock(
                $userId,
                $channelId,
                function () use ($channelId, $column, $desiredState): void {
                    if(in_array($desiredState,['0','1'],true)){
                        $this->pdo->prepare(
                            'UPDATE posted_channels SET '.$column.'=:value WHERE id=:id'
                        )->execute([
                            'value'=>(int)$desiredState,
                            'id'=>$channelId,
                        ]);
                        return;
                    }
                    $this->pdo->exec(
                        'UPDATE posted_channels
                         SET '.$column.'=IF('.$column.'=1,0,1)
                         WHERE id='.(int)$channelId
                    );
                }
            );
        }
        $scope==='post'?$this->showChannelPostSettings($userId,$channelId,$target):$this->showChannelAdSettings($userId,$channelId,$target);
    }

    private function refreshChannelData(int $userId, int $channelId): void
    {
        $channel=$this->channel($channelId);
        if(!$channel||!$this->isChannelOwner($userId,$channelId))return;
        if(PostedTelegramAuthorityPause::blocked($this->pdo,$channel)){
            $this->telegram->sendMessage($userId,'Канал приостановлен. Используйте отдельную проверку прав для возобновления.');return;
        }
        try{
            $chatId=(string)$channel['chat_id'];
            $chat=(array)(($this->telegram->call('getChat',['chat_id'=>$chatId]))['result']??[]);
            $botId=(int)((($this->telegram->call('getMe'))['result']??[])['id']??0);
            $bot=(array)(($this->telegram->call('getChatMember',['chat_id'=>$chatId,'user_id'=>$botId]))['result']??[]);
            PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
            $locked=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$locked->execute([$channelId]);$current=$locked->fetch();
            if(!$current||(int)$current['added_by']!==$userId||PostedTelegramAuthorityPause::blocked($this->pdo,$current,true))throw new PostedTelegramAuthorityPauseException('paused');
            $this->pdo->prepare(
                'UPDATE posted_channels
                 SET title=:title,username=:username,
                     is_active=IF(admin_blocked=1,0,:active),
                     bot_status=:status,bot_can_post=:post,
                     bot_can_edit=:edit,bot_can_delete=:delete
                 WHERE id=:id'
            )->execute([
                'title'=>(string)($chat['title']??$channel['title']),
                'username'=>isset($chat['username'])?(string)$chat['username']:null,
                'active'=>in_array((string)($bot['status']??''),['administrator','member'],true)?1:0,
                'status'=>(string)($bot['status']??'left'),
                'post'=>!empty($bot['can_post_messages'])?1:0,
                'edit'=>!empty($bot['can_edit_messages'])?1:0,
                'delete'=>!empty($bot['can_delete_messages'])?1:0,
                'id'=>$channelId,
            ]);
            if (in_array((string) ($bot['status'] ?? ''), ['left', 'kicked'], true)) {
                $this->clearTelegramChannelTransferReceipt($channelId);
            }
            $this->grant($userId,$channelId,true,true,true,true,$userId);
            $this->pdo->prepare(
                'UPDATE posted_permissions
                 SET can_manage_admins=0
                 WHERE channel_id=:channel AND user_id<>:owner'
            )->execute(['channel'=>$channelId,'owner'=>$userId]);
            PostedTelegramRootedAccess::commit($this->pdo);
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);
            $this->telegram->sendMessage($userId,'Не удалось обновить данные канала: '.$exception->getMessage());
        }
    }

    private function showChannelSettings(int $userId, int $channelId, ?array $target = null): void
    {
        $channel = $this->channel($channelId);
        $isOwner = $channel !== null && $this->isChannelOwner($userId, $channelId);
        if (!$channel || (!$isOwner && !$this->hasAnyChannelPermission($userId, $channelId))) {
            $this->telegram->sendMessage($userId, 'Нет доступа.');
            return;
        }
        $chatId = (string) $channel['chat_id'];
        $publicId = preg_replace('/^-100/', '', $chatId) ?: $chatId;
        $subscribers = 0;
        try {
            $result = $this->telegram->call('getChatMemberCount', ['chat_id' => $chatId]);
            $subscribers = (int) ($result['result'] ?? 0);
        } catch (Throwable) {
        }
        $lines = [
            (string) $channel['title'],
            'ID: ' . $publicId,
            'Подписчиков: ' . $subscribers,
            '',
        ];
        $keyboard = [];
        if ($isOwner) {
            $editorCount = $this->channelEditorCount($channelId);
            $managerCount = $this->channelManagerCount($channelId);
            $lines[] = 'Роль: владелец канала';
            $lines[] = 'Редакторов: ' . $editorCount;
            $lines[] = 'Рекламных менеджеров: ' . $managerCount;
            $lines[] = '';
            $lines[] = (!empty($channel['bot_can_post']) ? '✅' : '❌') . ' Бот может публиковать';
            $lines[] = (!empty($channel['bot_can_edit']) ? '✅' : '❌') . ' Бот может закреплять';
            $lines[] = (!empty($channel['bot_can_delete']) ? '✅' : '❌') . ' Бот может удалять публикации';
            $lines[] = '';
            $lines[] = 'Только владелец назначает редакторов, архивирует и отключает канал.';
            $keyboard = [
                [['text' => '🔄 Обновить данные', 'callback_data' => 'channel_refresh:' . $channelId], ['text' => '✈️ Создать пост', 'callback_data' => 'post_channel:' . $channelId]],
                [['text' => '🗓️ Расписание', 'callback_data' => 'channel_schedule:' . $channelId], ['text' => '📅 Контент-план', 'callback_data' => 'cp:channel:' . $channelId . ':' . $this->todayForUser($userId)]],
                [['text' => 'Настройки постов', 'callback_data' => 'channel_post_settings:' . $channelId], ['text' => 'Настройки рекламы', 'callback_data' => 'channel_ad_settings:' . $channelId]],
                [['text' => '👥 Редакторы (' . $editorCount . ')', 'callback_data' => 'channel_editors:' . $channelId . ':0']],
                [['text' => '💼 Рекламные менеджеры (' . $managerCount . ')', 'callback_data' => 'channel_managers:' . $channelId . ':0']],
                [['text' => '🗂️ Добавить в архив', 'callback_data' => 'channel_archive:' . $channelId]],
                [['text' => '🗑️ Отключить канал', 'callback_data' => 'channel_disable:' . $channelId]],
                [['text' => '← Назад', 'callback_data' => 'settings:root']],
            ];
        } else {
            $permission = $this->channelPermission($userId, $channelId);
            $isAdManager = $permission !== null && !empty($permission['is_ad_manager']);
            $lines[] = $isAdManager ? 'Роль: рекламный менеджер' : 'Роль: редактор';
            if ($isAdManager) {
                $lines[] = 'Публикации: ' . $this->managerQuotaLabel($permission['post_quota_remaining'] ?? null, 'осталось');
                $lines[] = 'Закрепления: ' . $this->managerQuotaLabel($permission['pin_quota_remaining'] ?? null, 'осталось');
                $description = trim((string) ($permission['manager_description'] ?? ''));
                if ($description !== '') $lines[] = "Описание:\n" . $description;
            } elseif ($permission !== null) {
                $lines[] = 'Уровень: ' . $this->channelPermissionLevelLabel($permission);
            }
            $lines[] = '';
            $lines[] = 'Настройки канала контролирует владелец. Вы можете работать с публикациями в пределах выданных прав.';
            if (!empty($permission['can_post'])) {
                $keyboard[] = [['text' => '✈️ Создать пост', 'callback_data' => 'post_channel:' . $channelId]];
                $keyboard[] = [['text' => '🗓️ Контент-план', 'callback_data' => 'cp:channel:' . $channelId]];
            }
            $keyboard[] = [['text' => 'Покинуть канал', 'callback_data' => 'channel_leave:' . $channelId]];
            $keyboard[] = [['text' => '← Назад', 'callback_data' => 'settings:root']];
        }
        $keyboard[]=[$this->telegramTopPlacementGraphButton($userId,$channelId)];
        $this->sendOrEditMessage($userId, implode("\n", $lines), ['reply_markup' => ['inline_keyboard' => $keyboard]], $target);
    }

    private function showChannelEditors(
        int $ownerId,
        int $channelId,
        int $page = 0,
        ?array $target = null
    ): void {
        $channel = $this->channel($channelId);
        if (!$channel || !$this->isChannelOwner($ownerId, $channelId)) {
            $this->telegram->sendMessage($ownerId, 'Только владелец канала может управлять редакторами.');
            return;
        }
        $perPage = 10;
        $count = $this->channelEditorCount($channelId);
        $pageCount = max(1, (int) ceil($count / $perPage));
        $page = min(max(0, $page), $pageCount - 1);
        $offset = $page * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT p.*,u.username,u.first_name
             FROM posted_permissions p
             LEFT JOIN posted_users u ON u.telegram_id=p.user_id
             WHERE p.channel_id=:channel
               AND p.user_id<>:owner
               AND p.is_ad_manager=0
               AND (p.can_post=1 OR p.can_schedule=1 OR p.can_manage_posts=1)
             ORDER BY u.first_name,u.username,p.user_id
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute(['channel' => $channelId, 'owner' => $ownerId]);
        $editors = $stmt->fetchAll();

        $text = "👥 РЕДАКТОРЫ\n\nКанал: " . (string) $channel['title']
            . "\nВсего редакторов: " . $count
            . "\n\nРедактору не нужны права управления каналом в Telegram. Доступ действует только внутри GitPosted и может быть отозван владельцем в любой момент.";
        $keyboard = [];
        foreach ($editors as $editor) {
            $editorId = (int) $editor['user_id'];
            $name = $this->adminDisplayName($editorId, $editor);
            $label = mb_substr($name, 0, 28) . ' · ' . $this->channelPermissionLevelLabel($editor);
            $keyboard[] = [
                ['text' => $label, 'callback_data' => 'admin_edit:' . $channelId . ':' . $editorId . ':' . $page],
                ['text' => '🗑️', 'callback_data' => 'admin_revoke:' . $channelId . ':' . $editorId . ':' . $page],
            ];
        }
        if ($count === 0) {
            $text .= "\n\nРедакторов пока нет.";
        }
        if ($pageCount > 1) {
            $navigation = [];
            if ($page > 0) {
                $navigation[] = ['text' => '←', 'callback_data' => 'channel_editors:' . $channelId . ':' . ($page - 1)];
            }
            $navigation[] = ['text' => ($page + 1) . '/' . $pageCount, 'callback_data' => 'noop'];
            if ($page + 1 < $pageCount) {
                $navigation[] = ['text' => '→', 'callback_data' => 'channel_editors:' . $channelId . ':' . ($page + 1)];
            }
            $keyboard[] = $navigation;
        }
        $keyboard[] = [['text' => '➕ Добавить редактора', 'callback_data' => 'admin_add:' . $channelId]];
        $keyboard[] = [['text' => '← К каналу', 'callback_data' => 'settings_channel:' . $channelId]];
        $this->sendOrEditMessage(
            $ownerId,
            $text,
            ['reply_markup' => ['inline_keyboard' => $keyboard]],
            $target
        );
    }

    private function showChannelManagers(
        int $ownerId,
        int $channelId,
        int $page = 0,
        ?array $target = null
    ): void {
        $channel = $this->channel($channelId);
        if (!$channel || !$this->isChannelOwner($ownerId, $channelId)) {
            $this->telegram->sendMessage($ownerId, 'Только владелец канала может управлять рекламными менеджерами.');
            return;
        }
        $perPage = 10;
        $count = $this->channelManagerCount($channelId);
        $pageCount = max(1, (int) ceil($count / $perPage));
        $page = min(max(0, $page), $pageCount - 1);
        $offset = $page * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT p.*,u.username,u.first_name
             FROM posted_permissions p
             LEFT JOIN posted_users u ON u.telegram_id=p.user_id
             WHERE p.channel_id=:channel AND p.user_id<>:owner AND p.is_ad_manager=1
             ORDER BY u.first_name,u.username,p.user_id
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute(['channel'=>$channelId,'owner'=>$ownerId]);
        $managers = $stmt->fetchAll();
        $text = "💼 РЕКЛАМНЫЕ МЕНЕДЖЕРЫ\n\nКанал: " . (string) $channel['title']
            . "\nВсего менеджеров: " . $count;
        $keyboard = [];
        foreach ($managers as $manager) {
            $managerId = (int) $manager['user_id'];
            $name = $this->adminDisplayName($managerId, $manager);
            $posts = $this->managerQuotaLabel($manager['post_quota_remaining'] ?? null, 'постов');
            $pins = $this->managerQuotaLabel($manager['pin_quota_remaining'] ?? null, 'закрепов');
            $keyboard[] = [
                ['text'=>mb_substr($name,0,22).' · '.$posts.' / '.$pins,'callback_data'=>'admin_edit:'.$channelId.':'.$managerId.':'.$page],
                ['text'=>'🗑️','callback_data'=>'manager_revoke:'.$channelId.':'.$managerId.':'.$page],
            ];
        }
        if ($count === 0) $text .= "\n\nРекламных менеджеров пока нет.";
        if ($pageCount > 1) {
            $navigation = [];
            if ($page > 0) $navigation[] = ['text'=>'←','callback_data'=>'channel_managers:'.$channelId.':'.($page-1)];
            $navigation[] = ['text'=>($page+1).'/'.$pageCount,'callback_data'=>'noop'];
            if ($page+1 < $pageCount) $navigation[] = ['text'=>'→','callback_data'=>'channel_managers:'.$channelId.':'.($page+1)];
            $keyboard[] = $navigation;
        }
        $keyboard[] = [['text'=>'➕ Добавить рекламного менеджера','callback_data'=>'manager_add:'.$channelId]];
        $keyboard[] = [['text'=>'← К каналу','callback_data'=>'settings_channel:'.$channelId]];
        $this->sendOrEditMessage($ownerId,$text,['reply_markup'=>['inline_keyboard'=>$keyboard]],$target);
    }

    private function confirmRevokeAdminRights(
        int $ownerId,
        int $channelId,
        int $adminId,
        int $page = 0,
        ?array $target = null,
        bool $managerList = false
    ): void
    {
        $channel = $this->channel($channelId);
        if (!$channel || !$this->isChannelOwner($ownerId, $channelId)) {
            $this->telegram->sendMessage($ownerId, 'Нет доступа.');
            return;
        }
        if ($adminId <= 0 || $adminId === $this->channelOwnerId($channelId)) {
            $this->telegram->sendMessage($ownerId, 'Нельзя снять права владельца канала.');
            return;
        }
        if ($this->channelPermission($adminId, $channelId) === null) {
            if ($managerList) $this->showChannelManagers($ownerId, $channelId, $page, $target);
            else $this->showChannelEditors($ownerId, $channelId, $page, $target);
            return;
        }

        $admin = $this->user($adminId);
        $label = $this->adminDisplayName($adminId, $admin);
        $this->sendOrEditMessage(
            $ownerId,
            "Снять доступ?\n\nКанал: " . (string) $channel['title'] . "\nПользователь: " . $label . "\n\nПосле снятия доступа пользователь больше не сможет работать с этим каналом через бота.",
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => 'Да, снять доступ', 'callback_data' => ($managerList ? 'manager_revoke_yes:' : 'admin_revoke_yes:') . $channelId . ':' . $adminId . ':' . $page]],
                [['text' => '← Назад', 'callback_data' => ($managerList ? 'channel_managers:' : 'channel_editors:') . $channelId . ':' . $page]],
            ]]],
            $target
        );
    }

    private function revokeAdminRights(
        int $ownerId,
        int $channelId,
        int $adminId,
        int $page = 0,
        ?array $target = null,
        bool $managerList = false
    ): void
    {
        if ($adminId <= 0 || $adminId === $ownerId) {
            $this->telegram->sendMessage($ownerId, 'Нельзя снять права владельца канала.');
            return;
        }
        $this->withOwnedTelegramChannelLock(
            $ownerId,
            $channelId,
            function () use ($channelId, $adminId): void {
                $this->cancelTelegramActorPendingPosts($adminId, $channelId);
                $this->pdo->prepare('UPDATE posted_manager_categories SET is_active=0 WHERE channel_id=:channel AND user_id=:user')->execute(['channel'=>$channelId,'user'=>$adminId]);
                $stmt = $this->pdo->prepare(
                    'DELETE FROM posted_permissions
                     WHERE channel_id=:channel AND user_id=:user'
                );
                $stmt->execute(['channel' => $channelId, 'user' => $adminId]);
                $this->clearUserChannelWorkflow($adminId, $channelId);
            }
        );
        if ($managerList) $this->showChannelManagers($ownerId, $channelId, $page, $target);
        else $this->showChannelEditors($ownerId, $channelId, $page, $target);
    }

    private function cancelTelegramActorPendingPosts(
        int $userId,
        int $channelId,
        ?int $managerCategoryId = null
    ): int {
        if ($userId <= 0 || $channelId <= 0 || !$this->pdo->inTransaction()) {
            throw new RuntimeException('Отмена публикаций требует активной транзакции.');
        }
        $categorySql = $managerCategoryId !== null ? ' AND manager_category_id=:category' : '';
        $pinCategorySql = $managerCategoryId !== null ? ' AND post.manager_category_id=:category' : '';
        $parameters = ['user'=>$userId, 'channel'=>$channelId];
        if ($managerCategoryId !== null) {
            $parameters['category'] = $managerCategoryId;
        }
        $activeAttempt=$this->pdo->prepare(
            "SELECT attempt.post_id
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             WHERE post.author_id=:user AND post.channel_id=:channel{$pinCategorySql}
               AND (attempt.state<>'completed' OR attempt.gate_channel_id IS NOT NULL)
             LIMIT 1 FOR UPDATE"
        );
        $activeAttempt->execute($parameters);
        if($activeAttempt->fetchColumn()!==false){
            throw new RuntimeException(
                'У пользователя завершается публикация. Повторите изменение доступа позже.'
            );
        }
        $pendingPins=$this->pdo->prepare(
            "SELECT attempt.post_id
             FROM posted_telegram_publish_attempts attempt
             INNER JOIN posted_posts post ON post.id=attempt.post_id
             WHERE post.author_id=:user AND post.channel_id=:channel
               AND attempt.state='completed' AND attempt.pin_completed_at IS NULL{$pinCategorySql}
             ORDER BY attempt.post_id
             FOR UPDATE"
        );
        $pendingPins->execute($parameters);
        foreach($pendingPins->fetchAll(PDO::FETCH_COLUMN) as $pendingPinPostId){
            if(!$this->cancelTelegramPinEffect((int)$pendingPinPostId)){
                throw new RuntimeException(
                    'Закрепление публикации ещё завершается. Повторите изменение доступа позже.'
                );
            }
        }
        $pending = $this->pdo->prepare(
            "SELECT id FROM posted_posts
             WHERE author_id=:user AND channel_id=:channel
               AND status IN ('draft','scheduled','failed'){$categorySql}
             ORDER BY id
             FOR UPDATE"
        );
        $pending->execute($parameters);
        $postIds = array_map('intval', $pending->fetchAll(PDO::FETCH_COLUMN));
        foreach ($postIds as $postId) {
            $this->refundManagerQuotaForPost($postId);
        }
        $cancel = $this->pdo->prepare(
            "UPDATE posted_posts
             SET status='cancelled',scheduled_at=NULL,publish_started_at=NULL,
                 repeat_interval_minutes=NULL,repeat_until=NULL,error_text=NULL
             WHERE author_id=:user AND channel_id=:channel
               AND status IN ('draft','scheduled','failed'){$categorySql}"
        );
        $cancel->execute($parameters);
        $stopRepeats = $this->pdo->prepare(
            "UPDATE posted_posts
             SET repeat_interval_minutes=NULL,repeat_until=NULL
             WHERE author_id=:user AND channel_id=:channel
               AND repeat_interval_minutes IS NOT NULL{$categorySql}"
        );
        $stopRepeats->execute($parameters);
        return $cancel->rowCount();
    }

    private function archiveChannel(int $userId, int $channelId, ?array $target = null): void
    {
        $this->withOwnedTelegramChannelLock(
            $userId,
            $channelId,
            function () use ($channelId): void {
                $this->pdo->prepare(
                    'UPDATE posted_channels SET is_archived=1 WHERE id=:channel'
                )->execute(['channel' => $channelId]);
            }
        );
        $this->clearState($userId);
        $this->sendOrEditMessage($userId, 'Канал добавлен в архив. Повторное подключение канала вернёт его в рабочие списки.', [
            'reply_markup'=>['inline_keyboard'=>[[['text'=>'← К настройкам','callback_data'=>'settings:root']]]],
        ], $target);
    }

    private function confirmDisableChannel(int $userId, int $channelId, ?array $target = null): void
    {
        $channel = $this->channel($channelId);
        if (!$channel || !$this->isChannelOwner($userId, $channelId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа.');
            return;
        }

        $this->sendOrEditMessage(
            $userId,
            "Отключить канал?\n\nКанал: " . (string) $channel['title'] . "\n\nОн пропадёт из списков публикации и управления, но история постов в базе сохранится. Вернуть канал можно повторным добавлением.",
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => 'Да, отключить', 'callback_data' => 'channel_disable_yes:' . $channelId]],
                [['text' => '← Назад', 'callback_data' => 'settings_channel:' . $channelId]],
            ]]],
            $target
        );
    }

    private function disableChannel(int $userId, int $channelId, ?array $target = null): void
    {
        $channel = $this->channel($channelId);
        if (!$channel || !$this->isChannelOwner($userId, $channelId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа.');
            return;
        }

        $cronLockName='trevoga_posted_cron';
        $cronLock=$this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $cronLock->execute(['lock_name'=>$cronLockName]);
        if((int)($cronLock->fetchColumn()?:0)!==1){
            throw new RuntimeException(
                'Завершается фоновая операция с каналами. Повторите отключение.'
            );
        }
        try {
            $pinLockName='gitposted_pin_'.substr(hash('sha256',(string)$channelId),0,32);
            $pinLock=$this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
            $pinLock->execute(['lock_name'=>$pinLockName]);
            if((int)($pinLock->fetchColumn()?:0)!==1){
                throw new RuntimeException(
                    'В канале завершается закрепление публикации. Повторите отключение.'
                );
            }
            try {
                PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
                try {
                    $lockedChannel = $this->pdo->prepare(
                    'SELECT *
                     FROM posted_channels
                     WHERE id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $lockedChannel->execute(['channel' => $channelId]);
                $channel = $lockedChannel->fetch();
                if (!is_array($channel) || (int) ($channel['added_by'] ?? 0) !== $userId) {
                    throw new RuntimeException('Владелец канала изменился во время отключения.');
                }
                if (!$this->lockActiveTelegramActor($userId)) {
                    throw new RuntimeException('Пользователь заблокирован.');
                }
                PostedTelegramAuthorityPause::closeExplicitLocked($this->pdo,$channel,'explicit_disable');
                PostedTelegramTopHold::retireChannelQuotasLocked($this->pdo,$channel);
                $permissionUsers = $this->pdo->prepare(
                    'SELECT user_id FROM posted_permissions WHERE channel_id=:channel'
                );
                $permissionUsers->execute(['channel' => $channelId]);
                $affectedUsers = array_values(array_unique([
                    $userId,
                    ...array_map('intval', $permissionUsers->fetchAll(PDO::FETCH_COLUMN)),
                ]));
                foreach ($affectedUsers as $affectedUserId) {
                    $this->clearUserChannelWorkflow($affectedUserId, $channelId);
                }
                $activeAttempt = $this->pdo->prepare(
                    'SELECT post_id
                     FROM posted_telegram_publish_attempts
                     WHERE gate_channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $activeAttempt->execute(['channel' => $channelId]);
                if ($activeAttempt->fetchColumn() !== false) {
                    throw new RuntimeException(
                        'В канале завершается публикация. Повторите отключение после её завершения.'
                    );
                }
                $pendingPins=$this->pdo->prepare(
                    "SELECT post_id
                     FROM posted_telegram_publish_attempts
                     WHERE channel_id=:channel AND state='completed'
                       AND pin_completed_at IS NULL
                     ORDER BY post_id
                     FOR UPDATE"
                );
                $pendingPins->execute(['channel'=>$channelId]);
                foreach($pendingPins->fetchAll(PDO::FETCH_COLUMN) as $pendingPinPostId){
                    if(!$this->cancelTelegramPinEffect((int)$pendingPinPostId)){
                        throw new RuntimeException(
                            'Закрепление публикации ещё завершается. Повторите отключение канала позже.'
                        );
                    }
                }
                $refundablePosts=$this->pdo->prepare(
                    "SELECT id
                     FROM posted_posts
                     WHERE channel_id=:channel
                       AND status IN ('draft','scheduled','failed')
                       AND (manager_post_quota_charged=1 OR manager_pin_quota_charged=1)
                     ORDER BY id
                     FOR UPDATE"
                );
                $refundablePosts->execute(['channel'=>$channelId]);
                foreach($refundablePosts->fetchAll(PDO::FETCH_COLUMN) as $refundablePostId){
                    $this->refundManagerQuotaForPost((int)$refundablePostId);
                }
                $this->pdo->prepare(
                    "UPDATE posted_posts
                     SET status='cancelled',scheduled_at=NULL,publish_started_at=NULL,
                         repeat_interval_minutes=NULL,repeat_until=NULL,error_text=NULL
                     WHERE channel_id=:channel
                       AND status IN ('draft','scheduled','publishing','telegram_pending','failed')"
                )->execute(['channel' => $channelId]);
                $this->pdo->prepare(
                    'UPDATE posted_posts
                     SET delete_after_minutes=NULL,delete_at=NULL,
                         repeat_interval_minutes=NULL,repeat_until=NULL,
                         manual_delete_required=0,delete_notice_sent_at=NULL,delete_attempts=0
                     WHERE channel_id=:channel
                       AND status IN (\'sent\',\'deleted\')'
                )->execute(['channel' => $channelId]);
                $this->pdo->prepare(
                    'UPDATE posted_manager_categories SET is_active=0 WHERE channel_id=:channel'
                )->execute(['channel'=>$channelId]);
                $this->pdo->prepare(
                    'DELETE FROM posted_permissions WHERE channel_id=:channel'
                )->execute(['channel' => $channelId]);
                $detach = $this->pdo->prepare(
                    'UPDATE posted_channels
                     SET is_active=0,admin_blocked=0,added_by=NULL
                     WHERE id=:channel AND added_by=:owner'
                );
                $detach->execute(['channel' => $channelId, 'owner' => $userId]);
                if ($detach->rowCount() !== 1) {
                    throw new RuntimeException('Владелец канала изменился во время отключения.');
                }
                $this->clearTelegramChannelTransferReceipt($channelId);
                    PostedTelegramRootedAccess::commit($this->pdo);
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        PostedTelegramRootedAccess::rollback($this->pdo);
                    }
                    throw $exception;
                }
            } finally {
                $releasePinLock=$this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releasePinLock->execute(['lock_name'=>$pinLockName]);
            }
        } finally {
            $releaseCronLock=$this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseCronLock->execute(['lock_name'=>$cronLockName]);
        }

        $this->sendOrEditMessage(
            $userId,
            "Канал отключён: " . (string) $channel['title'],
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => '➕ Добавить канал', 'callback_data' => 'settings:add_channel']],
                [['text' => '← Мои каналы', 'callback_data' => 'settings:my_channels']],
            ]]],
            $target
        );
    }

    private function confirmLeaveChannel(int $userId, int $channelId, ?array $target = null): void
    {
        $channel = $this->channel($channelId);
        if (!$channel || !$this->hasAnyChannelPermission($userId, $channelId)) {
            $this->telegram->sendMessage($userId, 'Нет доступа.');
            return;
        }
        if ($this->isChannelOwner($userId, $channelId)) {
            $this->telegram->sendMessage($userId, 'Владелец не может покинуть собственный канал. Канал можно архивировать или отключить.');
            return;
        }
        $expectedOwnerId = (int) ($channel['added_by'] ?? 0);
        if ($expectedOwnerId <= 0) {
            $this->telegram->sendMessage($userId, 'Канал уже отключён от владельца.');
            return;
        }
        $this->sendOrEditMessage(
            $userId,
            "Покинуть канал?\n\nПосле этого вы потеряете доступ к публикациям канала " . (string) $channel['title'] . '. Вернуть доступ сможет только владелец.',
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => 'Да, покинуть', 'callback_data' => 'channel_leave_yes:' . $channelId . ':' . $expectedOwnerId]],
                [['text' => '← Назад', 'callback_data' => 'settings_channel:' . $channelId]],
            ]]],
            $target
        );
    }

    private function leaveChannel(
        int $userId,
        int $channelId,
        int $expectedOwnerId,
        ?array $target = null
    ): void
    {
        if ($userId <= 0 || $channelId <= 0 || $expectedOwnerId <= 0 || $userId === $expectedOwnerId) {
            $this->telegram->sendMessage($userId, 'Владелец не может покинуть собственный канал.');
            return;
        }
        PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        try {
            $channelLock = $this->pdo->prepare(
                'SELECT title
                 FROM posted_channels
                 WHERE id=:channel AND is_active=1 AND added_by=:owner
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelLock->execute(['channel' => $channelId, 'owner' => $expectedOwnerId]);
            $channelTitle = $channelLock->fetchColumn();
            if ($channelTitle === false) {
                throw new RuntimeException('Владелец канала изменился. Откройте настройки заново.');
            }
            if (!$this->lockActiveTelegramActor($userId)) {
                throw new RuntimeException('Пользователь заблокирован.');
            }
            $this->cancelTelegramActorPendingPosts($userId, $channelId);
            $this->pdo->prepare(
                'UPDATE posted_manager_categories
                 SET is_active=0
                 WHERE user_id=:user AND channel_id=:channel'
            )->execute(['user'=>$userId, 'channel'=>$channelId]);
            $removed = $this->pdo->prepare(
                'DELETE FROM posted_permissions WHERE user_id=:user AND channel_id=:channel'
            );
            $removed->execute(['user' => $userId, 'channel' => $channelId]);
            if ($removed->rowCount() !== 1) {
                throw new RuntimeException('Доступ редактора уже отсутствует.');
            }
            $this->clearUserChannelWorkflow($userId,$channelId);
            PostedTelegramRootedAccess::commit($this->pdo);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                PostedTelegramRootedAccess::rollback($this->pdo);
            }
            throw $exception;
        }
        $this->sendOrEditMessage(
            $userId,
            'Вы больше не редактор канала ' . (string) $channelTitle . '.',
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => '← К настройкам', 'callback_data' => 'settings:root']],
            ]]],
            $target
        );
    }

    private function clearUserChannelWorkflow(int $userId,int $channelId):void
    {
        if($userId<=0||$channelId<=0)return;
        $user=$this->user($userId);
        $stateData=$this->stateData($user);
        $clearState=(int)($stateData['channel_id']??0)===$channelId
            ||(int)($stateData['scope_id']??0)===$channelId;
        $statePostId=(int)($stateData['post_id']??0);
        if(!$clearState&&$statePostId>0){
            $postStmt=$this->pdo->prepare(
                'SELECT 1 FROM posted_posts WHERE id=:post AND channel_id=:channel LIMIT 1'
            );
            $postStmt->execute(['post'=>$statePostId,'channel'=>$channelId]);
            $clearState=$postStmt->fetchColumn()!==false;
        }
        if($clearState)$this->clearState($userId);
        $this->pdo->prepare(
            'DELETE session
             FROM posted_edit_sessions session
             INNER JOIN posted_posts post ON post.id=session.post_id
             WHERE session.user_id=:user AND post.channel_id=:channel'
        )->execute(['user'=>$userId,'channel'=>$channelId]);
    }

    private function showRightsPicker(
        int $ownerId,
        int $channelId,
        int $adminId,
        int $page = 0,
        ?array $target = null
    ): void {
        $channel = $this->channel($channelId);
        if (!$channel || !$this->isChannelOwner($ownerId, $channelId)) {
            $this->telegram->sendMessage($ownerId, 'Только владелец канала может назначать редакторов.');
            return;
        }
        if ($adminId <= 0 || $adminId === $ownerId) {
            $this->telegram->sendMessage($ownerId, 'Владелец уже имеет все права на канал.');
            return;
        }
        $current = $this->channelPermission($adminId, $channelId);
        if ($current !== null && !empty($current['is_ad_manager'])) {
            $this->showManagerSettings($ownerId, $channelId, $adminId, $page, $target);
            return;
        }
        $buttons = [
            ['key' => 'post', 'text' => 'Постинг'],
            ['key' => 'schedule', 'text' => 'Постинг + планирование'],
            ['key' => 'full', 'text' => 'Полный доступ к публикациям'],
            ['key' => 'manager', 'text' => '💼 Рекламный менеджер'],
        ];
        $keyboard = [];
        foreach ($buttons as $definition) {
            $button = [
                'text' => (string) $definition['text'],
                'callback_data' => 'admin_rights:' . $channelId . ':' . $adminId . ':' . $definition['key'] . ':' . $page,
            ];
            if (
                $current !== null
                && $this->channelPermissionLevelKey($current) === $definition['key']
            ) {
                $button['style'] = 'primary';
            }
            $keyboard[] = [$button];
        }
        if($current!==null&&($topQuotaButton=$this->telegramTopQuotaButton($ownerId,$channelId,$adminId,$page))!==null)$keyboard[]=[$topQuotaButton];
        $keyboard[] = [[
            'text' => '← Назад',
            'callback_data' => 'channel_editors:' . $channelId . ':' . $page,
        ]];
        $label = $this->adminDisplayName($adminId, $this->user($adminId));
        $this->sendOrEditMessage(
            $ownerId,
            'Выберите уровень доступа для ' . $label . '. Управлять редакторами и самим каналом сможет только владелец.',
            ['reply_markup' => ['inline_keyboard' => $keyboard]],
            $target
        );
    }

    private function saveAdminRights(
        int $ownerId,
        int $channelId,
        int $adminId,
        string $level,
        int $page = 0,
        ?array $target = null
    ): void {
        if(!$this->telegramPolicyAllowOperator($ownerId))return;
        if(!PostedTelegramRootedAccess::enabled(PostedTelegramRootedAccess::current($this->config))&&!$this->telegramPolicyOperatorAllowed($adminId)){
            $this->telegram->sendMessage($ownerId,'Этот Telegram ID не включён в список доступа к боту. Права не изменены.');
            return;
        }
        if (!$this->isChannelOwner($ownerId, $channelId)) {
            $this->telegram->sendMessage($ownerId, 'Нет доступа.');
            return;
        }
        if ($adminId <= 0 || $adminId === $ownerId) {
            $this->telegram->sendMessage($ownerId, 'Права владельца определяются владением каналом и не изменяются.');
            return;
        }
        if (!in_array($level, ['post', 'schedule', 'full', 'manager'], true)) {
            $level = 'post';
        }
        $this->upsertBareUser($adminId);
        $this->withOwnedTelegramChannelLock(
            $ownerId,
            $channelId,
            function()use($ownerId,$channelId,$adminId,$level):void{
                $currentStmt=$this->pdo->prepare(
                    'SELECT * FROM posted_permissions
                     WHERE user_id=:user AND channel_id=:channel LIMIT 1 FOR UPDATE'
                );
                $currentStmt->execute(['user'=>$adminId,'channel'=>$channelId]);
                $current=$currentStmt->fetch();
                $targetIsManager=$level==='manager';
                $currentIsManager=is_array($current)&&!empty($current['is_ad_manager']);
                if(is_array($current)&&$currentIsManager!==$targetIsManager){
                    $this->cancelTelegramActorPendingPosts($adminId,$channelId);
                }elseif(
                    is_array($current)
                    &&!empty($current['can_schedule'])
                    &&$level==='post'
                ){
                    $this->cancelTelegramActorScheduledPosts($adminId,$channelId);
                }
                $this->grant(
                    $adminId,
                    $channelId,
                    true,
                    in_array($level,['schedule','full','manager'],true),
                    $level==='full',
                    false,
                    $ownerId
                );
                $this->pdo->prepare(
                    'UPDATE posted_permissions
                     SET manager_description=IF(:manager_description_flag=1,manager_description,NULL),
                         post_quota_remaining=IF(:manager_post_flag=1,IF(is_ad_manager=1,post_quota_remaining,0),NULL),
                         pin_quota_remaining=IF(:manager_pin_flag=1,IF(is_ad_manager=1,pin_quota_remaining,0),NULL),
                         is_ad_manager=:manager_flag
                     WHERE user_id=:user AND channel_id=:channel'
                )->execute([
                    'manager_description_flag'=>$level==='manager'?1:0,
                    'manager_post_flag'=>$level==='manager'?1:0,
                    'manager_pin_flag'=>$level==='manager'?1:0,
                    'manager_flag'=>$level==='manager'?1:0,
                    'user'=>$adminId,
                    'channel'=>$channelId,
                ]);
                if($level!=='manager'){
                    $this->pdo->prepare(
                        'UPDATE posted_manager_categories SET is_active=0
                         WHERE user_id=:user AND channel_id=:channel'
                    )->execute(['user'=>$adminId,'channel'=>$channelId]);
                }
            }
        );
        if ($level === 'manager') {
            $this->showManagerSettings($ownerId, $channelId, $adminId, $page, $target);
            return;
        }
        $this->showChannelEditors($ownerId, $channelId, $page, $target);
    }

    private function cancelTelegramActorScheduledPosts(int $userId,int $channelId):void
    {
        if(!$this->pdo->inTransaction()){
            throw new RuntimeException('Отмена отложенных публикаций требует транзакцию.');
        }
        $stmt=$this->pdo->prepare(
            "SELECT id FROM posted_posts
             WHERE author_id=:user AND channel_id=:channel AND status='scheduled'
             ORDER BY id FOR UPDATE"
        );
        $stmt->execute(['user'=>$userId,'channel'=>$channelId]);
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $postId){
            $this->refundManagerQuotaForPost((int)$postId);
        }
        $this->pdo->prepare(
            "UPDATE posted_posts
             SET status='cancelled',scheduled_at=NULL,publish_started_at=NULL,
                 repeat_interval_minutes=NULL,repeat_until=NULL,
                 error_text='Право планирования автора отозвано.'
             WHERE author_id=:user AND channel_id=:channel AND status='scheduled'"
        )->execute(['user'=>$userId,'channel'=>$channelId]);
    }

    private function managerQuotaLabel(mixed $remaining, string $single): string
    {
        return $remaining === null ? 'безлимит' : (string) max(0, (int) $remaining) . ' ' . $single;
    }

    private function managerLimitExhaustedMessage(
        string $quotaType,
        int $ownerId,
        string $channelTitle
    ): string {
        $isPinQuota = $quotaType === 'закреплений';
        $owner = $this->user($ownerId);
        $username = ltrim(trim((string) ($owner['username'] ?? '')), '@');
        $ownerContact = $username !== ''
            ? '@' . $username
            : $this->adminDisplayName($ownerId, $owner);
        $channel = trim($channelTitle) !== '' ? ' «' . trim($channelTitle) . '»' : '';
        $action = $isPinQuota ? 'публиковать посты с закреплением' : 'размещать новые посты';

        return 'Лимит ' . $quotaType . ' для канала' . $channel . " исчерпан.\n"
            . 'Чтобы продолжить ' . $action . ', обратитесь к владельцу канала: ' . $ownerContact . '.';
    }

    private function showManagerSettings(
        int $ownerId,
        int $channelId,
        int $managerId,
        int $page = 0,
        ?array $target = null
    ): void {
        if (!$this->isChannelOwner($ownerId, $channelId)) {
            $this->telegram->sendMessage($ownerId, 'Только владелец канала управляет рекламными менеджерами.');
            return;
        }
        $permission = $this->channelPermission($managerId, $channelId);
        $channel = $this->channel($channelId);
        if (!$channel || !$permission || empty($permission['is_ad_manager'])) {
            $this->showRightsPicker($ownerId, $channelId, $managerId, $page, $target);
            return;
        }
        $description = trim((string) ($permission['manager_description'] ?? ''));
        $label = $this->adminDisplayName($managerId, $this->user($managerId));
        $text = "💼 РЕКЛАМНЫЙ МЕНЕДЖЕР\n\nКанал: " . (string) $channel['title']
            . "\nПользователь: " . $label
            . "\nПубликации: " . $this->managerQuotaLabel($permission['post_quota_remaining'] ?? null, 'осталось')
            . "\nЗакрепления: " . $this->managerQuotaLabel($permission['pin_quota_remaining'] ?? null, 'осталось')
            . "\n\nОписание:\n" . ($description !== '' ? $description : 'не указано');
        $this->sendOrEditMessage($ownerId, $text, ['reply_markup'=>['inline_keyboard'=>[
            [['text'=>'📝 Описание','callback_data'=>'admin_manager_field:'.$channelId.':'.$managerId.':description:'.$page]],
            [['text'=>'📨 Лимит публикаций','callback_data'=>'admin_manager_field:'.$channelId.':'.$managerId.':posts:'.$page]],
            [['text'=>'📌 Лимит закреплений','callback_data'=>'admin_manager_field:'.$channelId.':'.$managerId.':pins:'.$page]],
            ...(($topQuotaButton=$this->telegramTopQuotaButton($ownerId,$channelId,$managerId,$page))!==null?[[$topQuotaButton]]:[]),
            [['text'=>'🏷 Рекламные компании','callback_data'=>'manager_categories:'.$channelId.':'.$managerId.':'.$page]],
            [['text'=>'← К списку','callback_data'=>'channel_managers:'.$channelId.':'.$page]],
        ]]], $target);
    }

    private function showManagerCategories(int $ownerId,int $channelId,int $managerId,int $page=0,?array $target=null): void
    {
        $permission=$this->channelPermission($managerId,$channelId);
        if(!$this->isChannelOwner($ownerId,$channelId)||!$permission||empty($permission['is_ad_manager']))return;
        $stmt=$this->pdo->prepare('SELECT * FROM posted_manager_categories WHERE user_id=:user AND channel_id=:channel AND is_active=1 ORDER BY id');$stmt->execute(['user'=>$managerId,'channel'=>$channelId]);$categories=$stmt->fetchAll();$rows=[];
        foreach($categories as $category){$label=(string)$category['name'].' · '.$this->managerQuotaLabel($category['post_quota_remaining']??null,'постов').' / '.$this->managerQuotaLabel($category['pin_quota_remaining']??null,'закрепов');$rows[]=[['text'=>mb_substr($label,0,60),'callback_data'=>'manager_category:'.$channelId.':'.$managerId.':'.(int)$category['id'].':'.$page]];}
        $rows[]=[['text'=>'➕ Добавить компанию','callback_data'=>'manager_category_add:'.$channelId.':'.$managerId.':'.$page]];$rows[]=[['text'=>'← К менеджеру','callback_data'=>'admin_edit:'.$channelId.':'.$managerId.':'.$page]];
        $text="🏷 РЕКЛАМНЫЕ КОМПАНИИ\n\nЕсли список пуст, действует общий лимит менеджера. Если создана хотя бы одна компания, перед новым постом менеджер обязательно выбирает компанию, а лимиты списываются с неё.";
        $this->sendOrEditMessage($ownerId,$text,['reply_markup'=>['inline_keyboard'=>$rows]],$target);
    }

    private function showManagerCategory(int $ownerId,int $channelId,int $managerId,int $categoryId,int $page=0,?array $target=null): void
    {
        if(!$this->isChannelOwner($ownerId,$channelId))return;$category=$this->managerCategory($managerId,$channelId,$categoryId,true);if(!$category){$this->showManagerCategories($ownerId,$channelId,$managerId,$page,$target);return;}
        $text="🏷 ".(string)$category['name']."\n\nПубликации: ".$this->managerQuotaLabel($category['post_quota_remaining']??null,'осталось')."\nЗакрепления: ".$this->managerQuotaLabel($category['pin_quota_remaining']??null,'осталось');
        $rows=[[['text'=>'📨 Лимит публикаций','callback_data'=>'manager_category_field:'.$channelId.':'.$managerId.':'.$categoryId.':'.$page.':posts']],[['text'=>'📌 Лимит закреплений','callback_data'=>'manager_category_field:'.$channelId.':'.$managerId.':'.$categoryId.':'.$page.':pins']],[['text'=>'🗑 Удалить компанию','callback_data'=>'manager_category_delete:'.$channelId.':'.$managerId.':'.$categoryId.':'.$page]],[['text'=>'← К компаниям','callback_data'=>'manager_categories:'.$channelId.':'.$managerId.':'.$page]]];
        $this->sendOrEditMessage($ownerId,$text,['reply_markup'=>['inline_keyboard'=>$rows]],$target);
    }

    private function deactivateManagerCategory(
        int $ownerId,
        int $channelId,
        int $managerId,
        int $categoryId
    ): void {
        if ($categoryId <= 0 || $managerId <= 0) {
            return;
        }
        $this->withOwnedTelegramChannelLock(
            $ownerId,
            $channelId,
            function () use ($channelId, $managerId, $categoryId): void {
                $category = $this->pdo->prepare(
                    'SELECT id FROM posted_manager_categories
                     WHERE id=:id AND user_id=:manager AND channel_id=:channel AND is_active=1
                     LIMIT 1 FOR UPDATE'
                );
                $category->execute([
                    'id'=>$categoryId,
                    'manager'=>$managerId,
                    'channel'=>$channelId,
                ]);
                if ($category->fetchColumn() === false) {
                    return;
                }
                $this->cancelTelegramActorPendingPosts($managerId, $channelId, $categoryId);
                $this->pdo->prepare(
                    'UPDATE posted_manager_categories SET is_active=0
                     WHERE id=:id AND user_id=:manager AND channel_id=:channel'
                )->execute([
                    'id'=>$categoryId,
                    'manager'=>$managerId,
                    'channel'=>$channelId,
                ]);
                $this->clearUserChannelWorkflow($managerId, $channelId);
            }
        );
    }

    private function startManagerFieldInput(
        int $ownerId,
        int $channelId,
        int $managerId,
        string $field,
        int $page = 0,
        ?array $target = null
    ): void {
        $permission = $this->channelPermission($managerId, $channelId);
        if (!$this->isChannelOwner($ownerId, $channelId) || !$permission || empty($permission['is_ad_manager'])) {
            $this->telegram->sendMessage($ownerId, 'Настройки менеджера уже недоступны.');
            return;
        }
        if (!in_array($field, ['description','posts','pins'], true)) return;
        $prompt = $field === 'description'
            ? 'Отправьте описание менеджера длиной до 250 символов. Чтобы очистить описание, отправьте «-».'
            : 'Отправьте количество либо слово «безлимит». Новое число станет текущим остатком.';
        $this->setState($ownerId, 'await_manager_field', [
            'channel_id'=>$channelId,'manager_id'=>$managerId,'field'=>$field,'page'=>$page,
        ]);
        $this->sendOrEditMessage($ownerId, $prompt, ['reply_markup'=>['inline_keyboard'=>[[
            ['text'=>'← Назад','callback_data'=>'admin_edit:'.$channelId.':'.$managerId.':'.$page],
        ]]]], $target);
    }

    private function saveManagerFieldInput(
        int $ownerId,
        int $channelId,
        int $managerId,
        string $field,
        int $page,
        string $input
    ): void {
        if (!$this->isChannelOwner($ownerId, $channelId)) {
            $this->clearState($ownerId);
            return;
        }
        $input = trim($input);
        if ($field === 'description') {
            if (mb_strlen($input) > 250) {
                $this->telegram->sendMessage($ownerId, 'Описание длиннее 250 символов. Сократите текст.');
                return;
            }
            $value = $input === '-' ? null : ($input !== '' ? $input : null);
            $column = 'manager_description';
        } elseif (in_array($field, ['posts','pins'], true)) {
            $unlimited = in_array(mb_strtolower($input), ['безлимит','безлим','unlimited','∞'], true);
            if (!$unlimited && preg_match('/^\d{1,9}$/D', $input) !== 1) {
                $this->telegram->sendMessage($ownerId, 'Отправьте целое число от 0 до 999999999 либо слово «безлимит».');
                return;
            }
            $value = $unlimited ? null : (int) $input;
            $column = $field === 'posts' ? 'post_quota_remaining' : 'pin_quota_remaining';
        } else {
            $this->clearState($ownerId);
            return;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE posted_permissions
             SET {$column}=:value
             WHERE user_id=:user AND channel_id=:channel AND is_ad_manager=1"
        );
        $stmt->bindValue(':value', $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        $stmt->bindValue(':user', $managerId, PDO::PARAM_INT);
        $stmt->bindValue(':channel', $channelId, PDO::PARAM_INT);
        $stmt->execute();
        $this->clearState($ownerId);
        $this->showManagerSettings($ownerId, $channelId, $managerId, max(0,$page));
    }

    private function buttonsHelpText(): string
    {
        return "⛓ КНОПКИ: URL-кнопки\n\n"
            . "Отправьте боту список URL-кнопок в следующем формате:\n\n"
            . "Кнопка 1 - http://t.me\nКнопка 2 - http://t.me\n\n"
            . "Чтобы поставить кнопки в один ряд, используйте разделитель « | »:\n\n"
            . "Кнопка 1 - http://t.me | Кнопка 2 - http://t.me\n\n"
            . "Чтобы установить цвет кнопке, добавьте «!r», «!g» или «!b» перед кнопкой:\n\n"
            . "!r Красный - https://t.me | !g Зелёный - https://t.me";
    }

    private function buttonTypeKeyboard(int $postId, string $selected = 'url'): array
    {
        $url = ['text' => 'URL-кнопки', 'callback_data' => 'draft:buttons:' . $postId];
        $reactions = ['text' => 'Реакции', 'callback_data' => 'draft:reactions:' . $postId];
        $hidden = ['text' => 'Скрытое продолжение', 'callback_data' => 'draft:hidden:' . $postId];
        if ($selected === 'url') { $url['style'] = 'primary'; }
        if ($selected === 'reactions') { $reactions['style'] = 'primary'; }
        if ($selected === 'hidden') { $hidden['style'] = 'primary'; }
        return [
            [$url, $reactions],
            [$hidden],
            [['text' => '← Назад', 'callback_data' => 'draft:cancel_timer:' . $postId]],
        ];
    }
    private function togglePostFlag(int $userId, int $postId, string $column): bool
    {
        if (!in_array($column, ['silent', 'pin_after_publish', 'comments_enabled', 'is_ad', 'link_preview_enabled', 'link_preview_large', 'link_preview_above', 'delete_report_enabled'], true)) {
            return false;
        }
        return $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function () use ($postId, $column): bool {
                $this->pdo->exec(
                    'UPDATE posted_posts
                     SET ' . $column . ' = IF(' . $column . ' = 1, 0, 1)
                     WHERE id = ' . (int) $postId . " AND status='draft'"
                );
                return true;
            }
        ) === true;
    }

    private function setPostFlag(int $userId,int $postId,string $column,int $value):bool
    {
        if (!in_array($column, ['silent', 'pin_after_publish', 'comments_enabled', 'is_ad', 'link_preview_enabled', 'link_preview_large', 'link_preview_above', 'delete_report_enabled'], true)) {
            return false;
        }
        $value=$value===1?1:0;
        return $this->withTelegramManagedPostLock(
            $userId,
            $postId,
            ['draft'],
            function () use ($postId,$column,$value): bool {
                $this->pdo->prepare(
                    'UPDATE posted_posts SET '.$column.'=:value'
                    .' WHERE id=:post AND status=\'draft\''
                )->execute(['value'=>$value,'post'=>$postId]);
                return true;
            }
        )===true;
    }

    private function pinPostIfNeeded(array $post, array $messageIds): void
    {
        if (empty($post['pin_after_publish']) || $messageIds === []) {
            return;
        }
        try{
            $this->telegram->pinChatMessage(
                (string)$post['chat_id'],
                (int)end($messageIds),
                true
            );
        }catch(PostedTelegramRequestException $exception){
            if($this->telegramMutationAlreadyApplied(
                $exception,
                ['message is not modified','already pinned']
            )){
                return;
            }
            throw $exception;
        }
    }

    private function parseReactionButtons(int $postId, string $text): array
    {
        $buttons = [];
        foreach (preg_split('/\R+/', trim($text)) ?: [] as $rowIndex => $line) {
            $row = [];
            foreach (preg_split('/\s*\/\s*/u', trim($line)) ?: [] as $buttonIndex => $label) {
                $style = '';
                if (preg_match('/^!(r|g|b)\s+(.+)$/u', trim($label), $match)) {
                    $style = match ((string) $match[1]) { 'r'=>'danger', 'g'=>'success', 'b'=>'primary', default=>'' };
                    $label = (string) $match[2];
                }
                $label = trim($label);
                if ($label === '') continue;
                $key = 'r' . (int) $rowIndex . '_' . (int) $buttonIndex;
                $button = ['text'=>$label, 'callback_data'=>'reaction:'.$postId.':'.$key, 'reaction_key'=>$key, 'reaction_label'=>$label];
                if ($style !== '') $button['style'] = $style;
                $row[] = $button;
            }
            if ($row !== []) $buttons[] = $row;
        }
        return $buttons;
    }

    private function handleLegacyPublicInteractiveButton(array $callback, string $data): void
    {
        $parts = explode(':', $data, 3);
        $kind = (string) ($parts[0] ?? '');
        $postId = (int) ($parts[1] ?? 0);
        $key = (string) ($parts[2] ?? '');
        $userId = (int) (($callback['from']['id'] ?? 0));
        $post = $this->post($postId);
        if (!$post || $postId <= 0 || $userId <= 0) {
            $this->answerCallback($callback, 'Пост не найден.');
            return;
        }
        $buttons = $this->decodeJson((string) ($post['buttons_json'] ?? '[]'));
        if ($kind === 'hidden') {
            $hiddenText = '';
            foreach ($buttons as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button) && (string) ($button['callback_data'] ?? '') === $data) {
                        $hiddenText = (string) ($button['hidden_text'] ?? '');
                    }
                }
            }
            try {
                $member = $this->telegram->call('getChatMember', ['chat_id' => (string) $post['chat_id'], 'user_id' => $userId]);
                $status = (string) ($member['result']['status'] ?? 'left');
                if (!in_array($status, ['member', 'administrator', 'creator'], true)) {
                    $this->answerCallback($callback, 'Сначала подпишитесь на канал.');
                    return;
                }
            } catch (Throwable) {
                $this->answerCallback($callback, 'Не удалось проверить подписку.');
                return;
            }
            $shownHiddenText=mb_strlen($hiddenText)>self::HIDDEN_TEXT_LIMIT
                ? mb_substr($hiddenText,0,self::HIDDEN_TEXT_LIMIT-1).'…'
                : $hiddenText;
            $this->telegram->call('answerCallbackQuery', ['callback_query_id' => (string) ($callback['id'] ?? ''), 'text' => $shownHiddenText, 'show_alert' => true]);
            return;
        }
        $callbackId=trim((string)($callback['id']??''));
        $this->pdo->beginTransaction();
        try{
            $find=$this->pdo->prepare(
                'SELECT reaction_key,last_callback_id
                 FROM posted_reactions
                 WHERE post_id=:post AND user_id=:user
                 LIMIT 1
                 FOR UPDATE'
            );
            $find->execute(['post'=>$postId,'user'=>$userId]);
            $reaction=$find->fetch();
            $current=is_array($reaction)?(string)($reaction['reaction_key']??''):'';
            $duplicate=$callbackId!==''
                &&is_array($reaction)
                &&hash_equals((string)($reaction['last_callback_id']??''),$callbackId);
            if(!$duplicate){
                $nextReaction=$current===$key?'':$key;
                $this->pdo->prepare(
                    'INSERT INTO posted_reactions
                        (post_id,user_id,reaction_key,last_callback_id)
                     VALUES (:post,:user,:reaction,:callback_id)
                     ON DUPLICATE KEY UPDATE
                        reaction_key=VALUES(reaction_key),
                        last_callback_id=VALUES(last_callback_id)'
                )->execute([
                    'post'=>$postId,
                    'user'=>$userId,
                    'reaction'=>$nextReaction,
                    'callback_id'=>$callbackId!==''?$callbackId:null,
                ]);
            }
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
        $counts = [];
        $q = $this->pdo->prepare("SELECT reaction_key,COUNT(*) AS cnt FROM posted_reactions WHERE post_id=:post AND reaction_key<>'' GROUP BY reaction_key");
        $q->execute(['post' => $postId]);
        foreach ($q->fetchAll() as $row) {
            $counts[(string) $row['reaction_key']] = (int) $row['cnt'];
        }
        foreach ($buttons as $ri => $row) {
            foreach ((array) $row as $bi => $button) {
                if (!is_array($button) || !isset($button['reaction_key'])) {
                    continue;
                }
                $reactionKey = (string) $button['reaction_key'];
                $label = (string) ($button['reaction_label'] ?? $button['text'] ?? '');
                $buttons[$ri][$bi]['text'] = $label . ' ' . (int) ($counts[$reactionKey] ?? 0);
            }
        }
        $message = (array) ($callback['message'] ?? []);
        try {
            $this->telegram->call('editMessageReplyMarkup', [
                'chat_id' => (string) (($message['chat']['id'] ?? $post['chat_id'])),
                'message_id' => (int) ($message['message_id'] ?? 0),
                'reply_markup' => ['inline_keyboard' => $this->buttonsMarkup($buttons)],
            ]);
        } catch (Throwable) {
        }
        $this->answerCallback($callback);
    }
    /** null means invalid input; [] is reserved for the explicit legacy clear command. */
    private function parseButtonsInput(array $message): ?array
    {
        $raw=$message['text']??null;
        if(!is_string($raw)||trim($raw)==='')return null;
        $buttons=$this->parseButtons($raw);
        return trim($raw)==='-'||$buttons!==[]?$buttons:null;
    }

    private function parseReactionButtonsInput(int $postId,array $message): ?array
    {
        $raw=$message['text']??null;
        if(!is_string($raw)||trim($raw)==='')return null;
        $buttons=$this->parseReactionButtons($postId,$raw);
        return $buttons!==[]?$buttons:null;
    }

    private function parseButtons(string $text): array
    {
        if (trim($text) === '-') {
            return [];
        }
        $buttons = [];
        foreach (preg_split('/\R+/', trim($text)) ?: [] as $line) {
            $legacy = array_map('trim', explode('|', $line, 2));
            if (count($legacy) === 2 && $legacy[0] !== '' && filter_var($legacy[1], FILTER_VALIDATE_URL)) {
                $buttons[] = [['text' => $legacy[0], 'url' => $legacy[1]]];
                continue;
            }
            $row = [];
            foreach (preg_split('/\s*\|\s*/', trim($line)) ?: [] as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                $label = '';
                $url = '';
                if (preg_match('/^(.+?)\s+-\s+(https?:\/\/\S+)$/u', $piece, $m)) {
                    $label = trim((string) $m[1]);
                    $url = trim((string) $m[2]);
                } else {
                    $parts = array_map('trim', explode('|', $piece, 2));
                    if (count($parts) === 2) {
                        $label = $parts[0];
                        $url = $parts[1];
                    }
                }
                $color = null;
                if (preg_match('/^!(r|g|b)\s+(.+)$/u', $label, $m)) {
                    $color = (string) $m[1];
                    $label = trim((string) $m[2]);
                }
                if ($label !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $button = ['text' => $label, 'url' => $url];
                    if ($color !== null) {
                        $button['style'] = match ($color) {
                            'r' => 'danger',
                            'g' => 'success',
                            'b' => 'primary',
                            default => null,
                        };
                    }
                    $row[] = $button;
                }
            }
            if ($row !== []) {
                $buttons[] = $row;
            }
        }
        return $buttons;
    }

    private function buttonsMarkup(array $buttons): array
    {
        $safe = [];
        foreach ($buttons as $row) {
            $safeRow = [];
            foreach ((array) $row as $button) {
                if (!is_array($button)) {
                    continue;
                }
                $text = trim((string) ($button['text'] ?? ''));
                $url = trim((string) ($button['url'] ?? ''));
                $callbackData = trim((string) ($button['callback_data'] ?? ''));
                if ($text !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $safeButton = ['text' => $text, 'url' => $url];
                    $style = (string) ($button['style'] ?? '');
                    if ($style === '' && isset($button['color'])) {
                        $style = match ((string) $button['color']) {
                            'r' => 'danger',
                            'g' => 'success',
                            'b' => 'primary',
                            default => '',
                        };
                    }
                    if (in_array($style, ['danger', 'success', 'primary'], true)) {
                        $safeButton['style'] = $style;
                    }
                    $safeRow[] = $safeButton;
                } elseif ($text !== '' && preg_match('/^(reaction|hidden):[0-9]+:[A-Za-z0-9_]+$/', $callbackData)) {
                    $safeButton = ['text' => $text, 'callback_data' => $callbackData];
                    $style = (string) ($button['style'] ?? '');
                    if (in_array($style, ['danger', 'success', 'primary'], true)) { $safeButton['style'] = $style; }
                    $safeRow[] = $safeButton;
                }
            }
            if ($safeRow !== []) {
                $safe[] = $safeRow;
            }
        }
        return $safe;
    }

    private function hasInlineButtons(array $buttons): bool
    {
        foreach ($buttons as $row) {
            foreach ((array) $row as $button) {
                if (!is_array($button)) {
                    continue;
                }
                $text = trim((string) ($button['text'] ?? ''));
                $url = trim((string) ($button['url'] ?? ''));
                $callbackData = trim((string) ($button['callback_data'] ?? ''));
                if ($text !== '' && (filter_var($url, FILTER_VALIDATE_URL) || preg_match('/^(reaction|hidden):[0-9]+:[A-Za-z0-9_]+$/', $callbackData))) {
                    return true;
                }
            }
        }
        return false;
    }

    private function normalizeButtonStyle(string $style): string
    {
        return in_array($style, ['danger', 'success', 'primary', 'none'], true) ? $style : 'success';
    }

    private function buttonStylePayload(string $style): array
    {
        if (!in_array($style, ['danger', 'success', 'primary'], true)) return [];
        return ['style' => $style];
    }

    private function storageTimezone(): DateTimeZone
    {
        try{return new DateTimeZone((string)($this->config['timezone']??'Europe/Moscow'));}
        catch(Throwable){return new DateTimeZone('Europe/Moscow');}
    }

    private function userTimezoneObject(int $userId): DateTimeZone
    {
        try{return new DateTimeZone($this->userTimezone($userId));}
        catch(Throwable){return new DateTimeZone('Europe/Moscow');}
    }

    private function storedDateForUser(int $userId, ?string $value): ?DateTimeImmutable
    {
        if(!$value)return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$this->storageTimezone());
        return $date?$date->setTimezone($this->userTimezoneObject($userId)):null;
    }

    private function formatStoredForUser(int $userId, ?string $value, string $format): string
    {
        return $this->storedDateForUser($userId,$value)?->format($format)??'';
    }

    private function todayForUser(int $userId): string
    {
        return (new DateTimeImmutable('now',$this->userTimezoneObject($userId)))->format('Y-m-d');
    }

    private function parseSchedule(int $userId, string $text): ?string
    {
        $text=trim(mb_strtolower($text));
        $timezone=$this->userTimezoneObject($userId);
        $now=new DateTimeImmutable('now',$timezone);
        $when=null;
        if(preg_match('/^через\s+(\d+)\s*мин(?:ут[уы]?)?$/u',$text,$match)===1){
            $when=$now->modify('+'.(int)$match[1].' minutes');
        }elseif(preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})\s+(\d{1,2}):(\d{2})$/u',$text,$match)===1){
            $day=(int)$match[1];$month=(int)$match[2];$year=(int)$match[3];$hour=(int)$match[4];$minute=(int)$match[5];
            if(!checkdate($month,$day,$year)||$hour>23||$minute>59)return null;
            $when=new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00',$year,$month,$day,$hour,$minute),$timezone);
        }
        if(!$when)return null;
        if($when<=$now)throw new InvalidArgumentException('Это время уже прошло. Укажите будущую дату и время.');
        return $when->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
    }

    private function parseScheduleForDate(int $userId, string $text, string $defaultDate): ?string
    {
        $text=trim((string)preg_replace('/[\s\x{00A0}\x{202F}]+/u',' ',$text));
        $timezone=$this->userTimezoneObject($userId);
        $hasDefaultDate=preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$defaultDate,$dateMatch)===1
            &&checkdate((int)$dateMatch[2],(int)$dateMatch[3],(int)$dateMatch[1]);
        $year=$hasDefaultDate?(int)$dateMatch[1]:0;
        $month=$hasDefaultDate?(int)$dateMatch[2]:0;
        $day=$hasDefaultDate?(int)$dateMatch[3]:0;
        if(preg_match('/^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))? (\d{1,2})[: ](\d{2})$/D',$text,$match)===1){
            $day=(int)$match[1];$month=(int)$match[2];
            if((string)($match[3]??'')!=='')$year=(int)$match[3];
            $hour=(int)$match[4];$minute=(int)$match[5];
        }elseif(preg_match('/^(\d{1,2})[: ](\d{2})(?: (\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?)?$/D',$text,$match)===1){
            $hour=(int)$match[1];$minute=(int)$match[2];
            if((string)($match[3]??'')!=='')$day=(int)$match[3];
            if((string)($match[4]??'')!=='')$month=(int)$match[4];
            if((string)($match[5]??'')!=='')$year=(int)$match[5];
        }elseif(preg_match('/^(\d{2})(\d{2})$/D',$text,$match)===1){
            $hour=(int)$match[1];$minute=(int)$match[2];
        }else{
            return $this->parseSchedule($userId,$text);
        }
        if($hour>23||$minute>59||!checkdate($month,$day,$year))return null;
        $localValue=sprintf('%04d-%02d-%02d %02d:%02d:00',$year,$month,$day,$hour,$minute);
        $when=new DateTimeImmutable($localValue,$timezone);
        if($when->format('Y-m-d H:i:s')!==$localValue)return null;
        if($when<=new DateTimeImmutable('now',$timezone)){
            throw new InvalidArgumentException('Это время уже прошло. Укажите будущую дату и время.');
        }
        return $when->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
    }

    private function scheduleInputExamples(int $userId,string $date,string $separator=', '):string
    {
        $selected=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$this->userTimezoneObject($userId));
        if(!$selected||$selected->format('Y-m-d')!==$date){
            $selected=new DateTimeImmutable('today',$this->userTimezoneObject($userId));
        }
        return $selected->format('d.m.Y').' 18:30'.$separator.$selected->format('d.m').' 18:30';
    }

    private function parseDeleteTimerMinutes(string $text): ?int
    {
        $text=trim(mb_strtolower($text));
        if(in_array($text,['нет','0','-'],true))return 0;
        $number=null;
        $multiplier=60;
        $extraMinutes=0;
        if(preg_match('/^(\d+)\s*[дd]$/u',$text,$match)===1){
            $number=$match[1];
            $multiplier=1440;
        }
        if(preg_match('/^(\d+)\s*(?::|\s)\s*(\d{1,2})$/u',$text,$match)===1){
            $number=$match[1];
            $extraMinutes=(int)$match[2];
            if($extraMinutes>=60)return null;
        }
        if(preg_match('/^\d+$/D',$text)===1)$number=$text;
        if($number===null)return null;
        // Compare decimal strings before casting: a long user input must never
        // saturate an integer or make multiplication produce a float.
        $number=ltrim($number,'0');
        if($number==='')$number='0';
        $limit=(string)intdiv(self::TELEGRAM_MAX_DELETE_TIMER_MINUTES-$extraMinutes,$multiplier);
        if(strlen($number)>strlen($limit)
            ||(strlen($number)===strlen($limit)&&strcmp($number,$limit)>0))return null;
        return $this->normalizeTelegramDeleteTimerMinutes((int)$number*$multiplier+$extraMinutes);
    }

    private function normalizeTelegramDeleteTimerMinutes(int $minutes): int
    {
        $minutes=max(0,$minutes);
        return $minutes===2880 ? self::TELEGRAM_MAX_AUTO_DELETE_MINUTES : $minutes;
    }

    private function allowedChannels(int $userId, string $ability): array
    {
        $column = match ($ability) {
            'schedule' => 'can_schedule',
            'manage_posts' => 'can_manage_posts',
            'manage_admins' => 'can_manage_admins',
            default => 'can_post',
        };
        $stmt = $this->pdo->prepare(
            "SELECT c.*
             FROM posted_channels c
             INNER JOIN posted_permissions p ON p.channel_id = c.id
             WHERE c.is_active = 1
               AND c.is_archived = 0
               AND p.user_id = :user_id
               AND p.{$column} = 1
             ORDER BY c.title"
        );
        $stmt->execute(['user_id' => $userId]);
        return array_values(array_filter($stmt->fetchAll(),fn(array $c):bool=>PostedTelegramRootedAccess::channel($this->pdo,$this->config,$userId,(int)$c['id'])));
    }

    private function allowedChannelIds(int $userId, string $ability): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->allowedChannels($userId, $ability));
    }

    private function storageRangeForUserDate(int $userId, string $date): array
    {
        $start=new DateTimeImmutable($date.' 00:00:00',$this->userTimezoneObject($userId));
        return [
            $start->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s'),
            $start->modify('+1 day')->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s'),
        ];
    }

    private function postsForDate(int $userId, array $channelIds, string $date): array
    {
        if ($channelIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($channelIds), '?'));
        [$start,$end]=$this->storageRangeForUserDate($userId,$date);
        $effectiveAt=PostedTelegramContentPlanUi::effectiveDateSql('p.');
        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.chat_id, c.title AS channel_title
             FROM posted_posts p
             INNER JOIN posted_channels c ON c.id = p.channel_id
             WHERE p.channel_id IN ({$placeholders})
               AND p.status IN ('scheduled','telegram_pending','sent','failed')
               AND {$effectiveAt}>=?
               AND {$effectiveAt}<?
             ORDER BY {$effectiveAt}, p.id"
        );
        $stmt->execute([...$channelIds,$start,$end]);
        return $stmt->fetchAll();
    }

    private function calendarKeyboard(int $userId, string $date): array
    {
        $channelIds = $this->allowedChannelIds($userId, 'post');
        $month = substr($date, 0, 7);
        $first = strtotime($month . '-01');
        $daysInMonth = (int) date('t', $first);
        $startWeekday = (int) date('N', $first);
        $counts=$this->postCountsByDate($userId,$channelIds,$month);
        $keyboard = [];
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
        $keyboard[] = [
            ['text' => '← ' . $this->monthName($prevMonth), 'callback_data' => 'cp:cal:' . $prevMonth],
            ['text' => $this->monthName($month), 'callback_data' => 'cp:cal:' . $month],
            ['text' => $this->monthName($nextMonth) . ' →', 'callback_data' => 'cp:cal:' . $nextMonth],
        ];
        $keyboard[] = array_map(static fn (string $d): array => ['text'=>$d,'callback_data'=>'noop'], ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']);
        $day = 1;
        for ($week = 0; $week < 6; $week++) {
            $row = [];
            for ($weekday = 1; $weekday <= 7; $weekday++) {
                if (($week === 0 && $weekday < $startWeekday) || $day > $daysInMonth) {
                    $row[]=['text'=>' ','callback_data'=>'noop'];
                    continue;
                }
                $current = $month . '-' . sprintf('%02d', $day);
                $marker = ($current === $date ? '◆ ' : (!empty($counts[$current]) ? '• ' : ''));
                $row[] = ['text' => $marker . (string) $day, 'callback_data' => 'cp:date:' . $current];
                $day++;
            }
            $keyboard[] = $row;
            if ($day > $daysInMonth) {
                break;
            }
        }
        return $keyboard;
    }

    private function calendarKeyboardForChannel(int $userId, int $channelId, string $date): array
    {
        $month = substr($date, 0, 7);
        $first = strtotime($month . '-01');
        $daysInMonth = (int) date('t', $first);
        $startWeekday = (int) date('N', $first);
        $counts=$this->postCountsByDate($userId,[$channelId],$month);
        $keyboard = [];
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
        $keyboard[] = [
            ['text' => '← ' . $this->monthName($prevMonth), 'callback_data' => 'cp:ccal:' . $channelId . ':' . $prevMonth],
            ['text' => $this->monthName($month), 'callback_data' => 'cp:ccal:' . $channelId . ':' . $month],
            ['text' => $this->monthName($nextMonth) . ' →', 'callback_data' => 'cp:ccal:' . $channelId . ':' . $nextMonth],
        ];
        $keyboard[] = array_map(static fn (string $d): array => ['text'=>$d,'callback_data'=>'noop'], ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']);
        $day = 1;
        for ($week = 0; $week < 6; $week++) {
            $row = [];
            for ($weekday = 1; $weekday <= 7; $weekday++) {
                if (($week === 0 && $weekday < $startWeekday) || $day > $daysInMonth) {
                    $row[] = ['text'=>' ','callback_data'=>'noop'];
                    continue;
                }
                $current = $month . '-' . sprintf('%02d', $day);
                $marker = $current === $date ? '🔸' : (!empty($counts[$current]) ? '💰 ' : '');
                $row[] = ['text' => $marker . (string) $day, 'callback_data' => 'cp:cdate:' . $channelId . ':' . $current];
                $day++;
            }
            $keyboard[] = $row;
            if ($day > $daysInMonth) {
                break;
            }
        }
        return $keyboard;
    }

    private function postCountsByDate(int $userId, array $channelIds, string $month): array
    {
        if ($channelIds === []) {
            return [];
        }
        $placeholders=implode(',',array_fill(0,count($channelIds),'?'));
        [$start,$ignored]=$this->storageRangeForUserDate($userId,$month.'-01');
        $monthEnd=(new DateTimeImmutable($month.'-01 00:00:00',$this->userTimezoneObject($userId)))->modify('+1 month')->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
        $effectiveAt=PostedTelegramContentPlanUi::effectiveDateSql('');
        $stmt = $this->pdo->prepare(
            "SELECT {$effectiveAt} AS effective_at
             FROM posted_posts
             WHERE channel_id IN ({$placeholders})
               AND status IN ('scheduled','telegram_pending','sent','failed')
               AND {$effectiveAt}>=?
               AND {$effectiveAt}<?"
        );
        $stmt->execute([...$channelIds,$start,$monthEnd]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $date=$this->formatStoredForUser($userId,(string)$row['effective_at'],'Y-m-d');
            if($date!=='')$result[$date]=($result[$date]??0)+1;
        }
        return $result;
    }

    private function can(int $userId, int $channelId, string $ability): bool
    {
        if(!PostedTelegramRootedAccess::channel($this->pdo,$this->config,$userId,$channelId))return false;
        $column = match ($ability) {
            'schedule' => 'can_schedule',
            'manage_posts' => 'can_manage_posts',
            'manage_admins' => 'can_manage_admins',
            default => 'can_post',
        };
        $stmt = $this->pdo->prepare(
            "SELECT permission.{$column}
             FROM posted_permissions permission
             JOIN posted_channels channel ON channel.id=permission.channel_id
             WHERE permission.user_id=:user_id
               AND permission.channel_id=:channel_id
               AND channel.is_active=1
               AND channel.added_by IS NOT NULL
               AND channel.added_by>0
             LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId, 'channel_id' => $channelId]);
        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    }

    private function canManagePost(int $userId, int $postId): bool
    {
        $post = $this->post($postId);
        if ($post === null) {
            return false;
        }
        $channelId = (int) $post['channel_id'];
        return $this->can($userId, $channelId, 'manage_posts')
            || (
                (int) $post['author_id'] === $userId
                && $this->can($userId, $channelId, 'post')
            );
    }

    private function mutableDraft(int $userId, int $postId, bool $needSchedule = false): ?array
    {
        $post=$this->post($postId);
        if(!$post||(string)($post['status']??'')!=='draft')return null;
        $owner=(int)$post['author_id']===$userId&&$this->can($userId,(int)$post['channel_id'],'post');
        $manager=$this->can($userId,(int)$post['channel_id'],'manage_posts');
        if(!$owner&&!$manager)return null;
        if($needSchedule&&!$this->can($userId,(int)$post['channel_id'],'schedule'))return null;
        return $post;
    }

    private function managedPostText(array $post): string
    {
        $lines = [];
        $lines[] = 'Канал: ' . (string) ($post['channel_title'] ?? '');
        $lines[] = 'Статус: ' . $this->statusLabel((string) $post['status']);
        $lines[] = 'Дата: ' . ($post['scheduled_at'] ? date('d.m.Y H:i', strtotime((string) $post['scheduled_at'])) : 'не запланирован');
        $lines[] = '';
        $lines[] = trim((string) ($post['text'] ?? '')) !== '' ? (string) $post['text'] : '[медиа без текста]';
        return implode("\n", $lines);
    }

    private function postShortTitle(array $post): string
    {
        $text = trim((string) ($post['text'] ?? ''));
        $first = strtok($text, "\n") ?: '[медиа]';
        $first = trim((string) $first);
        if (mb_strlen($first) > 45) {
            $first = mb_substr($first, 0, 42) . '...';
        }
        return (string) ($post['channel_title'] ?? '') . ' - ' . $first;
    }

    private function postPlanButtonTitle(int $userId, array $post): string
    {
        $time=$this->contentPlanRowTime($userId,$post);
        $settings=$this->user($userId)??[];
        $options=$this->telegramPostOptions($post);
        // Only the observed ordinary-copy layout changes. Paid and native-forward fallbacks remain below.
        if(empty($post['repost_chat_id'])&&empty($post['repost_message_id'])&&($options['paid_media_stars']??null)===null){
            $presentation=$this->contentPlanMediaPresentation($post);
            $compact=PostedTelegramContentPlanUi::compact($settings);
            $label=PostedTelegramContentPlanUi::capturedRow($post,$presentation['text'],$presentation['marker'],$this->storageTimezone(),$this->userTimezoneObject($userId),$compact);
            if($compact)return $label;
            // The meaning of show_plan_icons is still being probed; preserve existing option suffixes.
            $delete=(int)($post['delete_after_minutes']??0);$repeat=(int)($post['repeat_interval_minutes']??0);
            if($delete>0)$label.=' 🗑'.$this->telegramDeleteTimerLabel($delete);
            if($repeat>0)$label.=' 🔁'.$this->shortMinutesLabel($repeat);
            return $label;
        }
        $text = trim((string) ($post['text'] ?? ''));
        $first = strtok($text, "\n") ?: '[медиа]';
        $first = trim((string) $first);
        if (mb_strlen($first) > 28) {
            $first = mb_substr($first, 0, 25) . '...';
        }

        $status = match ((string) $post['status']) {
            'sent' => '✅',
            'telegram_pending' => '⌛',
            'failed' => '⚠️',
            'draft' => '📝',
            default => '⏳',
        };
        $parts = [$time, $status];
        $photoMarker=$this->contentPlanPhotoMarker($userId,$post);if($photoMarker!=='')$parts[]=$photoMarker;
        $parts[]=$first;
        $delete = (int) ($post['delete_after_minutes'] ?? 0);
        $repeat = (int) ($post['repeat_interval_minutes'] ?? 0);
        if ($delete > 0) {
            $parts[] = '🗑' . $this->telegramDeleteTimerLabel($delete);
        }
        if ($repeat > 0) {
            $parts[] = '🔁' . $this->shortMinutesLabel($repeat);
        }
        return implode(' ', $parts);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'черновик',
            'scheduled' => 'ожидает публикации',
            'telegram_pending' => 'обрабатывается Telegram',
            'sent' => 'опубликован',
            'failed' => 'ошибка',
            'deleted' => 'удалён',
            'cancelled' => 'отменён',
            default => $status,
        };
    }

    private function managedPostControlText(int $userId, array $post): string
    {
        $status = (string) ($post['status'] ?? '');
        if($status==='sent')return $this->contentPlanPublishedDetail($userId,$post)['text'];
        $icon = match ($status) {
            'sent' => '👀',
            'scheduled' => '⏱',
            'telegram_pending' => '⌛',
            'draft' => '📝',
            'failed' => '⚠️',
            default => '◌',
        };
        $label = match ($status) {
            'draft' => 'Черновик',
            'scheduled' => 'Ожидает публикации',
            'telegram_pending' => 'Обрабатывается Telegram',
            'sent' => 'Опубликован',
            'failed' => 'Ошибка',
            'deleted' => 'Удалён',
            'cancelled' => 'Отменён',
            default => $status,
        };
        $lines = ['Статус: ' . $icon . ' ' . $label];
        $link = $this->telegramPostLink($post);
        if ($link !== '') {
            $lines[] = 'Ссылка: ' . $link;
        }
        $date = (string) ($post['scheduled_at'] ?: $post['created_at'] ?? '');
        if ($date !== '') {
            $formatted=$this->formatStoredForUser($userId,$date,'j.m.Y H:i');
            if($formatted!=='')$lines[]='Дата: '.$formatted;
        }
        if($status==='telegram_pending'){
            $lines[]='Повторно отправлять этот пост не нужно.';
        }
        return implode("\n", $lines);
    }

    private function telegramPostLink(array $post): string
    {
        $messageId = (int) ($post['telegram_message_id'] ?? 0);
        if ($messageId <= 0) {
            $ids = $this->postMessageIds($post);
            $messageId = (int) ($ids[0] ?? 0);
        }
        if ($messageId <= 0) {
            return '';
        }
        $chatId = (string) ($post['chat_id'] ?? '');
        if (str_starts_with($chatId, '-100')) {
            return 't.me/c/' . substr($chatId, 4) . '/' . $messageId;
        }
        return '';
    }

    private function minutesKeyboard(string $prefix, array $values, string $backCallback): array
    {
        $keyboard = [];
        $row = [];
        foreach ($values as $value) {
            $row[] = ['text' => $this->minutesLabel((int) $value), 'callback_data' => $prefix . (int) $value];
            if (count($row) === 3) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $keyboard[] = $row;
        }
        $keyboard[] = [['text' => '← Назад', 'callback_data' => $backCallback]];
        return $keyboard;
    }

    private function minutesLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'Нет';
        }
        if ($minutes < 60) {
            return $minutes . ' мин';
        }
        if ($minutes % 1440 === 0) {
            return (int) ($minutes / 1440) . ' д';
        }
        if ($minutes % 60 === 0) {
            return (int) ($minutes / 60) . ' ч';
        }
        return $minutes . ' мин';
    }

    private function telegramDeleteTimerLabel(int $minutes): string
    {
        // The stored two-day preset deliberately leaves time for the minute
        // cron. This is presentation only; repeat intervals keep exact values.
        $minutes=$this->normalizeTelegramDeleteTimerMinutes($minutes);
        if($minutes===self::TELEGRAM_MAX_AUTO_DELETE_MINUTES)return '≈48 ч';
        $label=$this->minutesLabel($minutes);
        return $minutes>self::TELEGRAM_MAX_AUTO_DELETE_MINUTES?$label.' · вручную':$label;
    }

    private function telegramDeleteTimerHint(): string
    {
        return 'Автоудаление Telegram доступно только раньше 48 часов. '
            .'«≈48 ч» — это 47 ч 55 мин: 5 минут запаса на работу бота. '
            .'Большие сроки — напоминание удалить пост вручную, без автоудаления.';
    }

    private function telegramDeleteTimerKeyboard(string $prefix,array $values,string $backCallback,?int $selected=null):array
    {
        $selected=$selected===null?null:$this->normalizeTelegramDeleteTimerMinutes($selected);
        $keyboard=[];$row=[];
        foreach($values as$value){
            $value=(int)$value;
            $button=['text'=>$this->telegramDeleteTimerLabel($value),'callback_data'=>$prefix.$value];
            if($selected!==null&&$selected===$this->normalizeTelegramDeleteTimerMinutes($value))$button['style']='primary';
            $row[]=$button;
            if(count($row)===3){$keyboard[]=$row;$row=[];}
        }
        if($row!==[])$keyboard[]=$row;
        $keyboard[]=[['text'=>'← Назад','callback_data'=>$backCallback]];
        return $keyboard;
    }

    private function shortMinutesLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0';
        }
        if ($minutes % 1440 === 0) {
            return (string) (int) ($minutes / 1440);
        }
        if ($minutes % 60 === 0) {
            return (string) (int) ($minutes / 60);
        }
        return (string) $minutes;
    }

    private function normalizeDate(?string $date): ?string
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        return strtotime($date) !== false ? $date : null;
    }

    private function humanDate(string $date): string
    {
        $timestamp = strtotime($date);
        $weekdays = [1 => 'понедельник', 'вторник', 'среду', 'четверг', 'пятницу', 'субботу', 'воскресенье'];
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        return ($weekdays[(int) date('N', $timestamp)] ?? '') . ', ' . (int) date('j', $timestamp) . ' ' . ($months[(int) date('n', $timestamp)] ?? '') . ' ' . date('Y', $timestamp) . ' г.';
    }

    private function shortDate(string $date): string
    {
        $timestamp = strtotime($date);
        $weekdays = [1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $months = [1 => 'янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
        return ($weekdays[(int) date('N', $timestamp)] ?? '') . ', ' . (int) date('j', $timestamp) . ' ' . ($months[(int) date('n', $timestamp)] ?? '');
    }

    private function monthName(string $month): string
    {
        $names = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        $timestamp = strtotime($month . '-01');
        return $names[(int) date('n', $timestamp)] ?? $month;
    }

    private function pluralRu(int $count, string $one, string $few, string $many): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $count . ' ' . $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $count . ' ' . $few;
        }
        return $count . ' ' . $many;
    }

    private function grant(
        int $userId,
        int $channelId,
        bool $post,
        bool $schedule,
        bool $managePosts,
        bool $manageAdmins,
        int $expectedOwnerId
    ): void
    {
        if ($userId <= 0 || $channelId <= 0 || $expectedOwnerId <= 0) {
            throw new InvalidArgumentException('Некорректные данные доступа к каналу.');
        }
        PostedTelegramPolicy::requireOperator($this->config,$expectedOwnerId,$this->pdo);
        if(!PostedTelegramRootedAccess::enabled(PostedTelegramRootedAccess::current($this->config)))PostedTelegramPolicy::requireOperator($this->config,$userId,$this->pdo);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        }
        try {
            $channel = $this->pdo->prepare(
                'SELECT id
                 FROM posted_channels
                 WHERE id=:channel AND is_active=1 AND added_by=:owner
                 LIMIT 1
                 FOR UPDATE'
            );
            $channel->execute(['channel' => $channelId, 'owner' => $expectedOwnerId]);
            if ($channel->fetchColumn() === false) {
                throw new RuntimeException('Канал уже отключён или его владелец изменился.');
            }
            if (!$this->lockActiveTelegramActor($expectedOwnerId)) {
                throw new RuntimeException('Владелец канала заблокирован.');
            }
            if(PostedTelegramRootedAccess::enabled(PostedTelegramRootedAccess::current($this->config))){
                if(!PostedTelegramRootedAccess::channel($this->pdo,$this->config,$expectedOwnerId,$channelId)&&!PostedTelegramRootedAccess::canConnect($this->pdo,$this->config,$expectedOwnerId))throw new RuntimeException('Нет доступа к каналу.');
                $this->pdo->prepare("INSERT INTO posted_users(telegram_id,role) VALUES(?,'user') ON DUPLICATE KEY UPDATE telegram_id=VALUES(telegram_id)")->execute([$userId]);
                if(!$this->lockActiveTelegramActor($userId))throw new RuntimeException('Сотрудник заблокирован.');
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO posted_permissions
                    (user_id,channel_id,can_post,can_schedule,can_manage_posts,can_manage_admins)
                 VALUES
                    (:user_id,:channel_id,:can_post,:can_schedule,:can_manage_posts,:can_manage_admins)
                 ON DUPLICATE KEY UPDATE
                    can_post=VALUES(can_post),can_schedule=VALUES(can_schedule),
                    can_manage_posts=VALUES(can_manage_posts),
                    can_manage_admins=VALUES(can_manage_admins)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'channel_id' => $channelId,
                'can_post' => $post ? 1 : 0,
                'can_schedule' => $schedule ? 1 : 0,
                'can_manage_posts' => $managePosts ? 1 : 0,
                'can_manage_admins' => $manageAdmins ? 1 : 0,
            ]);
            if ($ownsTransaction) {
                PostedTelegramRootedAccess::commit($this->pdo);
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                PostedTelegramRootedAccess::rollback($this->pdo);
            }
            throw $exception;
        }
    }

    private function upsertUser(array $from): void
    {
        $id = (int) ($from['id'] ?? 0);
        if(!$this->telegramPolicyOperatorAllowed($id))return;
        if ($id <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO posted_users (telegram_id, username, first_name, role)
             VALUES (:id, :username, :first_name, 'user')
             ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                first_name = VALUES(first_name),
                role = 'user'"
        );
        $stmt->execute([
            'id' => $id,
            'username' => isset($from['username']) ? (string) $from['username'] : null,
            'first_name' => isset($from['first_name']) ? (string) $from['first_name'] : null,
        ]);
    }

    private function upsertBareUser(int $id, string $role = 'user'): void
    {
        if(!$this->telegramPolicyOperatorAllowed($id)&&!($this->pdo->inTransaction()&&PostedTelegramRootedAccess::enabled(PostedTelegramRootedAccess::current($this->config))))return;
        if ($id <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO posted_users (telegram_id, role)
             VALUES (:id, 'user')
             ON DUPLICATE KEY UPDATE role = 'user'"
        );
        $stmt->execute(['id' => $id]);
    }

    private function user(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posted_users WHERE telegram_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function userByUsername(string $username): ?array
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM posted_users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function channel(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posted_channels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function channelOwnerId(int $channelId): int
    {
        if ($channelId <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT added_by FROM posted_channels WHERE id=:channel LIMIT 1'
        );
        $stmt->execute(['channel' => $channelId]);
        return max(0, (int) ($stmt->fetchColumn() ?: 0));
    }

    private function isChannelOwner(int $userId, int $channelId): bool
    {
        if(!PostedTelegramRootedAccess::channel($this->pdo,$this->config,$userId,$channelId))return false;
        if (
            $userId <= 0
            || $channelId <= 0
            || $this->channelOwnerId($channelId) !== $userId
        ) {
            return false;
        }
        return $this->ensureTelegramChannelTenure($channelId, $userId);
    }

    private function telegramBotId(): int
    {
        if ($this->telegramBotIdCache !== null) {
            return $this->telegramBotIdCache;
        }
        try {
            $result = $this->telegram->call('getMe');
            $identity=$result['result']??[];
            $this->telegramBotIdCache=($result['ok']??null)===true&&is_int($identity['id']??null)&&$identity['id']>0&&($identity['is_bot']??null)===true?$identity['id']:0;
        } catch (Throwable $exception) {
            error_log('GitPosted could not verify Telegram bot identity: ' . $exception->getMessage());
            $this->telegramBotIdCache = 0;
        }
        return $this->telegramBotIdCache;
    }

    private function telegramChannelAdminCacheKey(string $chatId, int $userId): string
    {
        return $chatId . ':' . $userId;
    }

    private function telegramChannelAdministratorStatus(string $chatId,int $userId): ?bool
    {
        if($chatId===''||$userId<=0)return null;$key=$this->telegramChannelAdminCacheKey($chatId,$userId);
        if(array_key_exists($key,$this->telegramChannelAdminCache))return$this->telegramChannelAdminCache[$key];
        try{$response=$this->telegram->call('getChatMember',['chat_id'=>$chatId,'user_id'=>$userId]);$member=$response['result']??[];
            if(($response['ok']??null)!==true||($member['user']['id']??null)!==$userId||($member['user']['is_bot']??null)!==false
                ||!in_array($member['status']??null,['creator','administrator','member','restricted','left','kicked'],true))return$this->telegramChannelAdminCache[$key]=null;
            return$this->telegramChannelAdminCache[$key]=in_array($member['status'],['creator','administrator'],true);
        }catch(Throwable){return$this->telegramChannelAdminCache[$key]=null;}
    }


    private function telegramTransferredChannelOwnerReceipt(array $channel): ?array
    {
        $channelId = (int) ($channel['id'] ?? 0);
        if ($channelId <= 0) return null;
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1'
        );
        $statement->execute(['key'=>'telegram_channel_transfer_' . $channelId]);
        return PostedTelegramAccountTransfer::channelTransferMarker($channel, $statement->fetchColumn());
    }

    private function clearTelegramChannelTransferReceipt(int $channelId): void
    {
        if ($channelId <= 0) return;
        $this->pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=:key')
            ->execute(['key'=>'telegram_channel_transfer_' . $channelId]);
    }

    private function ensureTelegramTransferredChannelBotAuthority(array $channel, array $receipt): bool
    {
        try {
            if ($this->telegramTransferredBotIdCache === null) {
                $meResponse=$this->telegram->call('getMe');if(($meResponse['ok']??null)!==true)return false;
                $me = (array) ($meResponse['result'] ?? []);
                $this->telegramTransferredBotIdCache = is_int($me['id'] ?? null)
                    && $me['id'] > 0 && ($me['is_bot'] ?? null) === true ? $me['id'] : 0;
            }
            $botId = $this->telegramTransferredBotIdCache;
            if ($botId <= 0) return false;
            $memberResponse=$this->telegram->call('getChatMember', [
                'chat_id'=>(string) $channel['chat_id'], 'user_id'=>$botId,
            ]);if(($memberResponse['ok']??null)!==true)return false;
            $member = (array) ($memberResponse['result'] ?? []);
            $identity = (array) ($member['user'] ?? []);
            if (($identity['id'] ?? null) !== $botId || ($identity['is_bot'] ?? null) !== true) return false;
            $status = (string) ($member['status'] ?? '');
            if (in_array($status, ['left', 'kicked'], true)) {
                // A confirmed removal ends this delegated binding. Keep queued
                // content, but never let a later bot re-add revive an old receipt.
                $ownsTransaction = !$this->pdo->inTransaction();
                if ($ownsTransaction) PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
                try {
                    $statement = $this->pdo->prepare('SELECT * FROM posted_channels WHERE id=:id LIMIT 1 FOR UPDATE');
                    $statement->execute(['id'=>(int) $channel['id']]);
                    $current = $statement->fetch();
                    if (is_array($current) && $this->telegramTransferredChannelOwnerReceipt($current) === $receipt) {
                        $this->pdo->prepare(
                            'UPDATE posted_channels SET is_active=0,bot_status=:status,bot_can_post=0 WHERE id=:id'
                        )->execute(['status'=>$status,'id'=>(int) $channel['id']]);
                        PostedTelegramAuthorityPause::pauseLocked($this->pdo,$current,'bot_removed');
                        $this->clearTelegramChannelTransferReceipt((int) $channel['id']);
                    }
                    if ($ownsTransaction) PostedTelegramRootedAccess::commit($this->pdo);
                } catch (Throwable $exception) {
                    if ($ownsTransaction && $this->pdo->inTransaction()) PostedTelegramRootedAccess::rollback($this->pdo);
                    throw $exception;
                }
                return false;
            }
            if(!in_array($status,['creator','administrator','member','restricted','left','kicked'],true))return false;
            if($status==='administrator'&&!is_bool($member['can_post_messages']??null))return false;
            if($status!=='administrator'||($member['can_post_messages']??null)!==true){$this->telegramAuthorityPauseChannel((int)$channel['id'],(int)$channel['added_by'],'bot_rights_lost');return false;}
            return true;
        } catch (Throwable $exception) {
            // Unknown API outcomes do not revoke ownership or cancel schedules.
            error_log('GitPosted could not verify transferred channel bot authority: ' . get_class($exception));
            return false;
        }
    }

    private function ensureTelegramChannelTenure(int $channelId, ?int $expectedOwnerId = null): bool
    {
        if ($channelId <= 0) {
            return false;
        }
        $channel = $this->channel($channelId);
        if (!is_array($channel) || empty($channel['is_active'])) {
            return false;
        }
        if(PostedTelegramAuthorityPause::blocked($this->pdo,$channel))return false;
        $ownerId = (int) ($channel['added_by'] ?? 0);
        if ($ownerId <= 0 || ($expectedOwnerId !== null && $ownerId !== $expectedOwnerId)) {
            return false;
        }
        $receipt = $this->telegramTransferredChannelOwnerReceipt($channel);
        if ($receipt !== null) return $this->ensureTelegramTransferredChannelBotAuthority($channel, $receipt);
        $isAdministrator = $this->telegramChannelAdministratorStatus(
            (string) ($channel['chat_id'] ?? ''),
            $ownerId
        );
        if ($isAdministrator === true) {
            return true;
        }
        if ($isAdministrator === false) {
            $this->freezeTelegramChannelOwnership($channelId, $ownerId);
        }
        // Fail closed on a transient Telegram API error, but do not detach the
        // channel unless Telegram has explicitly confirmed the lost role.
        return false;
    }

    private function reconcileTelegramChannelOwners(int $limit): void
    {
        $limit=min(20,max(1,$limit));
        $firstRun=$this->pdo->query(
            "SELECT COUNT(*) FROM posted_app_settings
             WHERE setting_key='telegram_owner_reconcile'"
        )->fetchColumn()==0;
        $ageCondition=$firstRun?'':' AND updated_at<NOW()-INTERVAL 15 MINUTE';
        $stmt=$this->pdo->query(
            "SELECT id,chat_id,added_by
             FROM posted_channels
             WHERE is_active=1 AND added_by IS NOT NULL AND added_by>0
               {$ageCondition}
             ORDER BY updated_at,id
             LIMIT {$limit}"
        );
        foreach($stmt->fetchAll() as $channel){
            $channelId=(int)($channel['id']??0);
            $ownerId=(int)($channel['added_by']??0);
            if($channelId<=0||$ownerId<=0){
                continue;
            }
            $transferred = $this->telegramTransferredChannelOwnerReceipt($channel) !== null;
            $isAdministrator = $transferred
                ? $this->ensureTelegramChannelTenure($channelId, $ownerId)
                : $this->telegramChannelAdministratorStatus((string) ($channel['chat_id'] ?? ''), $ownerId);
            if(!$transferred && $isAdministrator===false){
                if(!$this->freezeTelegramChannelOwnership($channelId,$ownerId)){
                    error_log(
                        'GitPosted could not detach stale Telegram owner '
                        .$ownerId.' from channel '.$channelId.'.'
                    );
                }
                continue;
            }
            // A transient Telegram error must not detach the channel. Touching the
            // check timestamp also prevents a failing API from being hammered each minute.
            $this->pdo->prepare(
                'UPDATE posted_channels SET updated_at=NOW()
                 WHERE id=:channel AND added_by=:owner AND is_active=1'
            )->execute(['channel'=>$channelId,'owner'=>$ownerId]);
        }
        $this->pdo->prepare(
            "INSERT INTO posted_app_settings(setting_key,setting_value)
             VALUES('telegram_owner_reconcile',:value)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        )->execute(['value'=>$this->encodeJson(['checked_at'=>date(DATE_ATOM)])]);
    }

    private function freezeTelegramChannelOwnership(int $channelId,int $expectedOwnerId): bool
    {
        if($channelId<=0||$expectedOwnerId<=0)return false;
        return$this->telegramAuthorityPauseChannel($channelId,$expectedOwnerId,'owner_role_lost');
    }


    private function withOwnedTelegramChannelLock(
        int $ownerId,
        int $channelId,
        callable $operation
    ): mixed {
        if ($ownerId <= 0 || $channelId <= 0) {
            throw new InvalidArgumentException('Некорректный владелец или канал.');
        }
        if (!$this->ensureTelegramChannelTenure($channelId, $ownerId)) {
            throw new RuntimeException(
                'Telegram не подтвердил, что вы всё ещё владелец или администратор канала.'
            );
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        }
        try {
            PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$ownerId,$channelId);
            $channel = $this->pdo->prepare(
                'SELECT *
                 FROM posted_channels
                 WHERE id=:channel AND is_active=1 AND added_by=:owner
                 LIMIT 1
                 FOR UPDATE'
            );
            $channel->execute(['channel' => $channelId, 'owner' => $ownerId]);
            $lockedChannel = $channel->fetch();
            if (!is_array($lockedChannel)) {
                throw new RuntimeException('Канал отключён или его владелец изменился.');
            }
            if (!$this->lockActiveTelegramActor($ownerId)) {
                throw new RuntimeException('Пользователь заблокирован.');
            }
            $result = $operation($lockedChannel);
            if ($ownsTransaction) {
                PostedTelegramRootedAccess::commit($this->pdo);
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                PostedTelegramRootedAccess::rollback($this->pdo);
            }
            throw $exception;
        }
    }

    private function withTelegramChannelCapabilityLock(
        int $userId,
        int $channelId,
        string $ability,
        callable $operation
    ): mixed {
        if ($userId <= 0 || $channelId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь или канал.');
        }
        if (!$this->ensureTelegramChannelTenure($channelId)) {
            throw new RuntimeException('Владение каналом в Telegram больше не подтверждено.');
        }
        $column = match ($ability) {
            'schedule' => 'can_schedule',
            'manage_posts' => 'can_manage_posts',
            'manage_admins' => 'can_manage_admins',
            'post' => 'can_post',
            default => throw new InvalidArgumentException('Некорректное право канала.'),
        };
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            PostedTelegramRootedAccess::begin($this->pdo,$this->config);
        }
        try {
            PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$userId,$channelId);
            $channel = $this->pdo->prepare(
                'SELECT *
                 FROM posted_channels
                 WHERE id=:channel
                   AND is_active=1
                   AND added_by IS NOT NULL
                   AND added_by>0
                 LIMIT 1
                 FOR UPDATE'
            );
            $channel->execute(['channel' => $channelId]);
            $lockedChannel = $channel->fetch();
            if (!is_array($lockedChannel)) {
                throw new RuntimeException('Канал отключён или его владелец изменился.');
            }
            if (!$this->lockActiveTelegramActor($userId)) {
                throw new RuntimeException('Пользователь заблокирован.');
            }
            $permission = $this->pdo->prepare(
                'SELECT can_post,can_schedule,can_manage_posts,can_manage_admins,is_ad_manager
                 FROM posted_permissions
                 WHERE user_id=:user AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $permission->execute(['user' => $userId, 'channel' => $channelId]);
            $lockedPermission = $permission->fetch();
            if (!is_array($lockedPermission) || (int) ($lockedPermission[$column] ?? 0) !== 1) {
                throw new RuntimeException('Права пользователя на канал изменились.');
            }
            $result = $operation($lockedChannel, $lockedPermission);
            if ($ownsTransaction) {
                PostedTelegramRootedAccess::commit($this->pdo);
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                PostedTelegramRootedAccess::rollback($this->pdo);
            }
            throw $exception;
        }
    }

    private function withTelegramManagedPostLock(
        int $userId,
        int $postId,
        array $allowedStatuses,
        callable $operation,
        bool $needSchedule = false,
        bool $requireManager = false,
        bool $requirePost = false,
        bool $allowAuthorityReviewDate = false,
        array $planningExtraChannelIds = []
    ): mixed {
        if ($userId <= 0 || $postId <= 0) {
            return null;
        }
        $hint = $this->pdo->prepare(
            'SELECT channel_id FROM posted_posts WHERE id=:post LIMIT 1'
        );
        $hint->execute(['post' => $postId]);
        $channelId = (int) ($hint->fetchColumn() ?: 0);
        if ($channelId <= 0) {
            return null;
        }
        $planningHint=PostedTelegramTopHold::planningSnapshot($this->pdo,$channelId,$planningExtraChannelIds);
        $reviewChannel=$allowAuthorityReviewDate?$this->channel($channelId):null;
        $reviewEdit=is_array($reviewChannel)&&PostedTelegramAuthorityPause::canEditReview($this->pdo,$reviewChannel,$userId);
        if (!$reviewEdit&&!$this->ensureTelegramChannelTenure($channelId)) {
            return null;
        }

        $allowedStatuses = array_values(array_unique(array_filter(
            array_map(static fn(mixed $status): string => (string) $status, $allowedStatuses),
            static fn(string $status): bool => $status !== ''
        )));
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            PostedTelegramRootedAccess::begin($this->pdo,$this->config);
        }
        try {
            PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$userId,$channelId);
            // Keep this order identical for every user-driven post mutation:
            // channel tenure, post state, then the actor's current permissions.
            $channel=null;foreach($planningHint['channel_ids']as$planningId){
                $lock=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$planningId]);$locked=$lock->fetch();
                if(!is_array($locked))throw new PostedTelegramTopHoldException('stale_revision');if($planningId===$channelId)$channel=$locked;
            }
            PostedTelegramTopHold::verifyPlanningLocked($this->pdo,$planningHint);
            if(is_array($channel)&&(empty($channel['is_active'])||(int)($channel['added_by']??0)<=0))$channel=null;
            if (!is_array($channel)) {
                if ($ownsTransaction) {
                    PostedTelegramRootedAccess::commit($this->pdo);
                }
                return null;
            }

            if($reviewEdit&&!PostedTelegramAuthorityPause::canEditReview($this->pdo,$channel,$userId,true))throw new PostedTelegramAuthorityPauseException('stale_review');
            $postStmt = $this->pdo->prepare(
                'SELECT *
                 FROM posted_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postStmt->execute(['post' => $postId, 'channel' => $channelId]);
            $post = $postStmt->fetch();
            if (
                !is_array($post)
                || (
                    $allowedStatuses !== []
                    && !in_array((string) ($post['status'] ?? ''), $allowedStatuses, true)
                )
            ) {
                if ($ownsTransaction) {
                    PostedTelegramRootedAccess::commit($this->pdo);
                }
                return null;
            }

            if (!$this->lockActiveTelegramActor($userId)) {
                if ($ownsTransaction) {
                    PostedTelegramRootedAccess::commit($this->pdo);
                }
                return null;
            }
            $permissionStmt = $this->pdo->prepare(
                'SELECT can_post,can_schedule,can_manage_posts,can_manage_admins,is_ad_manager
                 FROM posted_permissions
                 WHERE user_id=:user AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $permissionStmt->execute(['user' => $userId, 'channel' => $channelId]);
            $permission = $permissionStmt->fetch();
            $isManager = is_array($permission)
                && (int) ($permission['can_manage_posts'] ?? 0) === 1;
            $isAuthor = is_array($permission)
                && (int) ($post['author_id'] ?? 0) === $userId
                && (int) ($permission['can_post'] ?? 0) === 1;
            $allowed = $requireManager ? $isManager : ($isManager || $isAuthor);
            if (
                !$allowed
                || ($needSchedule && (int) ($permission['can_schedule'] ?? 0) !== 1)
                || ($requirePost && (int) ($permission['can_post'] ?? 0) !== 1)
            ) {
                if ($ownsTransaction) {
                    PostedTelegramRootedAccess::commit($this->pdo);
                }
                return null;
            }

            $result = $operation($post, $channel, $permission);
            if ($ownsTransaction) {
                PostedTelegramRootedAccess::commit($this->pdo);
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                PostedTelegramRootedAccess::rollback($this->pdo);
            }
            throw $exception;
        }
    }

    private function lockActiveTelegramActor(int $userId): bool
    {
        return $this->lockActiveTelegramActors([$userId]);
    }

    private function lockActiveTelegramActors(array $userIds): bool
    {
        if (!$this->pdo->inTransaction()) {
            return false;
        }
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === [] || in_array(0, $userIds, true)) {
            return false;
        }
        sort($userIds, SORT_NUMERIC);
        $actor = $this->pdo->prepare(
            'SELECT is_active
             FROM posted_users
             WHERE telegram_id=:user
             LIMIT 1
             FOR UPDATE'
        );
        foreach ($userIds as $userId) {
            if ($userId <= 0) {
                return false;
            }
            $actor->execute(['user' => $userId]);
            if ((int) ($actor->fetchColumn() ?: 0) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function hasAnyChannelPermission(int $userId, int $channelId): bool
    {
        if ($userId <= 0 || $channelId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM posted_permissions
             WHERE user_id=:user AND channel_id=:channel
               AND (can_post=1 OR can_schedule=1 OR can_manage_posts=1 OR can_manage_admins=1)
             LIMIT 1'
        );
        $stmt->execute(['user' => $userId, 'channel' => $channelId]);
        return $stmt->fetchColumn() !== false;
    }

    private function ownedChannels(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM posted_channels
             WHERE added_by=:owner AND is_active=1 AND is_archived=0
             ORDER BY title,id'
        );
        $stmt->execute(['owner' => $userId]);
        return array_values(array_filter($stmt->fetchAll(),fn(array $c):bool=>PostedTelegramRootedAccess::channel($this->pdo,$this->config,$userId,(int)$c['id'])));
    }

    private function channelByChatId(string $chatId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posted_channels WHERE chat_id = :chat_id LIMIT 1');
        $stmt->execute(['chat_id' => $chatId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function channelTitle(int $channelId): string
    {
        $stmt = $this->pdo->prepare('SELECT title FROM posted_channels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $channelId]);
        $title = (string) ($stmt->fetchColumn() ?: '');
        return $title !== '' ? $title : ('Канал #' . $channelId);
    }

    private function channelPermissions(int $channelId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, u.username, u.first_name
             FROM posted_permissions p
             LEFT JOIN posted_users u ON u.telegram_id = p.user_id
             WHERE p.channel_id = :channel_id
             ORDER BY p.can_manage_admins DESC, p.can_manage_posts DESC, p.can_schedule DESC, p.can_post DESC, u.first_name, p.user_id"
        );
        $stmt->execute(['channel_id' => $channelId]);
        return $stmt->fetchAll();
    }

    private function channelPermission(int $userId, int $channelId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM posted_permissions
             WHERE user_id=:user AND channel_id=:channel
             LIMIT 1'
        );
        $stmt->execute(['user' => $userId, 'channel' => $channelId]);
        $permission = $stmt->fetch();
        return is_array($permission) ? $permission : null;
    }

    private function channelEditorCount(int $channelId): int
    {
        $ownerId = $this->channelOwnerId($channelId);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM posted_permissions
             WHERE channel_id=:channel
               AND user_id<>:owner
               AND is_ad_manager=0
               AND (can_post=1 OR can_schedule=1 OR can_manage_posts=1)'
        );
        $stmt->execute(['channel' => $channelId, 'owner' => $ownerId]);
        return max(0, (int) $stmt->fetchColumn());
    }

    private function channelManagerCount(int $channelId): int
    {
        $ownerId = $this->channelOwnerId($channelId);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM posted_permissions
             WHERE channel_id=:channel AND user_id<>:owner AND is_ad_manager=1'
        );
        $stmt->execute(['channel'=>$channelId,'owner'=>$ownerId]);
        return max(0, (int) $stmt->fetchColumn());
    }

    private function channelPermissionLevelKey(array $permission): string
    {
        if (!empty($permission['can_manage_posts'])) {
            return 'full';
        }
        if (!empty($permission['can_schedule'])) {
            return 'schedule';
        }
        return 'post';
    }

    private function channelPermissionLevelLabel(array $permission): string
    {
        return match ($this->channelPermissionLevelKey($permission)) {
            'full' => 'полный доступ',
            'schedule' => 'постинг + планирование',
            default => 'постинг',
        };
    }

    private function adminDisplayName(int $userId, ?array $user): string
    {
        if (is_array($user)) {
            $name = trim((string) ($user['first_name'] ?? ''));
            $username = trim((string) ($user['username'] ?? ''));
            if ($name !== '' && $username !== '') {
                return $name . ' (@' . $username . ')';
            }
            if ($username !== '') {
                return '@' . $username;
            }
            if ($name !== '') {
                return $name . ' (' . $userId . ')';
            }
        }
        return (string) $userId;
    }

    private function shortButtonText(string $text, int $limit): string
    {
        $text = trim($text);
        if ($limit <= 1 || mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    private function templatesForScope(int $userId, int $scopeId): array
    {
        if ($scopeId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT *
                 FROM posted_templates
                 WHERE owner_id = :owner
                   AND channel_id = :channel_id
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 20'
            );
            $stmt->execute(['owner' => $userId, 'channel_id' => $scopeId]);
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM posted_templates
             WHERE owner_id = :owner
               AND channel_id IS NULL
             ORDER BY updated_at DESC, id DESC
             LIMIT 20'
        );
        $stmt->execute(['owner' => $userId]);
        return $stmt->fetchAll();
    }

    private function template(int $templateId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posted_templates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $templateId]);
        $row = $stmt->fetch();
        return is_array($row) ? PostedTelegramTemplateMedia::hydrate($this->pdo,$row) : null;
    }

    private function templateTitle(string $text, string $mediaType): string
    {
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                return mb_strlen($line) > 80 ? mb_substr($line, 0, 77) . '...' : $line;
            }
        }

        return match ($mediaType) {
            'photo' => 'Фото-шаблон',
            'video' => 'Видео-шаблон',
            default => 'Новый шаблон',
        };
    }

    private function emojiSetPreviewHtml(array $item): string
    {
        return (string) $this->emojiSetDetails($item)['preview_html'];
    }

    private function emojiSetDetails(array $item): array
    {
        $fallback = htmlspecialchars((string) ($item['preview'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fallbackCount = max(0, (int) ($item['count'] ?? 0));
        $setName = trim((string) ($item['set_name'] ?? ''));
        if ($setName === '') {
            $setName = $this->emojiSetNameFromUrl((string) ($item['url'] ?? ''));
        }
        if ($setName === '') {
            return ['preview_html' => $fallback, 'count' => $fallbackCount];
        }

        $cached = $this->emojiSetCacheDetails($setName);
        if ($cached !== null) {
            $previewHtml = $cached['preview_html'] !== '' ? $cached['preview_html'] : $fallback;
            if ($setName === 'teleemojimy' && substr_count(strip_tags($previewHtml), '✅') === 5) {
                $previewHtml .= '✅✅';
            }
            return [
                'preview_html' => $previewHtml,
                'count' => $cached['count'] > 0 ? $cached['count'] : $fallbackCount,
            ];
        }

        try {
            $response = $this->telegram->call('getStickerSet', ['name' => $setName]);
            $result = (array) ($response['result'] ?? []);
            $stickers = is_array($result['stickers'] ?? null) ? (array) $result['stickers'] : [];
            $count = (int) ($result['sticker_count'] ?? count($stickers));
            $preview = $this->buildCustomEmojiPreviewHtml($stickers);
            $this->saveEmojiSetCache($setName, $preview, $count, null);
            $this->updateEmojiCatalogCount($setName, $count);
            return [
                'preview_html' => $preview !== '' ? $preview : $fallback,
                'count' => $count > 0 ? $count : $fallbackCount,
            ];
        } catch (Throwable $exception) {
            $this->saveEmojiSetCache($setName, '', null, $exception->getMessage());
            return ['preview_html' => $fallback, 'count' => $fallbackCount];
        }
    }

    private function emojiSetNameFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (preg_match('~/addemoji/([^/?#]+)~', $path, $matches)) {
            return (string) $matches[1];
        }
        return '';
    }

    private function emojiSetCache(string $setName): ?string
    {
        $cached = $this->emojiSetCacheDetails($setName);
        return $cached === null ? null : $cached['preview_html'];
    }

    private function emojiSetCacheDetails(string $setName): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT preview_html, sticker_count, fetched_at
             FROM posted_emoji_sets
             WHERE set_name = :set_name
             LIMIT 1"
        );
        $stmt->execute(['set_name' => $setName]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $fetchedAt = strtotime((string) ($row['fetched_at'] ?? ''));
        if ($fetchedAt > 0 && $fetchedAt < time() - 604800) {
            return null;
        }
        return [
            'preview_html' => (string) ($row['preview_html'] ?? ''),
            'count' => (int) ($row['sticker_count'] ?? 0),
        ];
    }

    private function saveEmojiSetCache(string $setName, string $previewHtml, ?int $count, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO posted_emoji_sets (set_name, preview_html, sticker_count, error_text, fetched_at)
             VALUES (:set_name, :preview_html, :sticker_count, :error_text, NOW())
             ON DUPLICATE KEY UPDATE
                preview_html = VALUES(preview_html),
                sticker_count = VALUES(sticker_count),
                error_text = VALUES(error_text),
                fetched_at = NOW()"
        );
        $stmt->execute([
            'set_name' => $setName,
            'preview_html' => $previewHtml !== '' ? $previewHtml : null,
            'sticker_count' => $count,
            'error_text' => $error,
        ]);
    }

    private function updateEmojiCatalogCount(string $setName, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE posted_emoji_catalog
             SET sticker_count = :sticker_count
             WHERE set_name = :set_name"
        );
        $stmt->execute([
            'set_name' => $setName,
            'sticker_count' => $count,
        ]);
    }

    private function buildCustomEmojiPreviewHtml(array $stickers, int $limit = 7): string
    {
        $parts = [];
        foreach ($stickers as $sticker) {
            if (!is_array($sticker)) {
                continue;
            }
            $emojiId = trim((string) ($sticker['custom_emoji_id'] ?? ''));
            $emoji = trim((string) ($sticker['emoji'] ?? ''));
            if ($emojiId === '' || $emoji === '') {
                continue;
            }
            $parts[] = '<tg-emoji emoji-id="' . htmlspecialchars($emojiId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($emoji, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</tg-emoji>';
            if (count($parts) >= $limit) {
                break;
            }
        }
        return implode('', $parts);
    }

    private function customEmojiHtml(string $emojiId, string $emoji): string
    {
        return '<tg-emoji emoji-id="'
            . htmlspecialchars($emojiId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">'
            . htmlspecialchars($emoji, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</tg-emoji>';
    }

    private function channelTitleHtml(array $channel): string
    {
        $title = htmlspecialchars((string) ($channel['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $username = ltrim(trim((string) ($channel['username'] ?? '')), '@');
        if ($username === '' || preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) !== 1) {
            return $title;
        }
        return '<a href="https://t.me/' . rawurlencode($username) . '">' . $title . '</a>';
    }

    private function emojiCategories(): array
    {
        return [
            'quality' => 'Качественные',
            'fonts' => 'Шрифты',
            'icons' => 'Иконки',
            'brands' => 'Бренды',
            'statuses' => 'Статусы',
            'memes' => 'Мемы',
        ];
    }

    private function emojiCatalog(): array
    {
        $catalog = [];
        foreach (array_keys($this->emojiCategories()) as $category) {
            $catalog[$category] = $this->emojiCatalogFromDatabase($category);
        }
        return $catalog;
    }

    private function emojiCatalogFromDatabase(string $category): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT set_name, preview, url, sticker_count
             FROM posted_emoji_catalog
             WHERE category = :category
               AND is_active = 1
             ORDER BY sort_order, id"
        );
        $stmt->execute(['category' => $category]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'set_name' => (string) ($row['set_name'] ?? ''),
                'preview' => (string) ($row['preview'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'count' => (int) ($row['sticker_count'] ?? 0),
            ];
        }
        return $items;
    }

    private function lastInsertedPostId(int $userId, int $channelId): int
    {
        $postId = (int) $this->pdo->lastInsertId();
        if ($postId > 0) {
            return $postId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id
             FROM posted_posts
             WHERE author_id = :author_id
               AND channel_id = :channel_id
               AND status = 'draft'
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute(['author_id' => $userId, 'channel_id' => $channelId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function post(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.chat_id, c.title AS channel_title
             FROM posted_posts p
             INNER JOIN posted_channels c ON c.id = p.channel_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function postIdByChannelMessage(int $channelId, int $messageId): int
    {
        if ($channelId <= 0 || $messageId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id
             FROM posted_posts
             WHERE channel_id = :channel_id
               AND status = 'sent'
               AND (
                    telegram_message_id = :message_id
                    OR external_message_id = :external_message_id
                    OR JSON_CONTAINS(COALESCE(telegram_message_ids, JSON_ARRAY()), CAST(:message_json AS JSON), '$')
               )
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'external_message_id' => $messageId,
            'message_json' => json_encode($messageId, JSON_THROW_ON_ERROR),
        ]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function postSummary(array $post): string
    {
        $text = trim((string) ($post['text'] ?? ''));
        $text = $text !== '' ? $text : '[медиа без текста]';
        return 'Канал: ' . (string) ($post['channel_title'] ?? '') . "\n\n" . $text;
    }

    private function telegramPreviewPlan(array $post,string $prefixText=''):array
    {
        $post=PostedTelegramPostOptions::freeze($this->pdo,$post);
        $post=PostedTelegramTransportLayout::preview($post);
        $post=PostedTelegramCaptionPlacement::preview($this->pdo,$post);
        $native=$this->telegramNativePreviewPlan($post,$prefixText);if($native!==null)return$native;
        $options=$this->telegramPostOptions($post);
        $payload=$this->effectivePostPayload($post);
        $text=(string)$payload['text'];
        $entities=(array)($payload['entities']??[]);
        $prefix=trim($prefixText);
        if($prefix!==''){
            $separator=$prefix."\n\n";
            $text=$separator.$text;
            $entities=$this->shiftTelegramEntities(
                $entities,
                $this->telegramUtf16Length($separator)
            );
        }
        PostedTelegramCaptionPlacement::assertCaption($post,$text);
        $media=$this->postMedia($post);
        if($text===''&&$media===[]){
            $text='[медиа без текста]';
            $entities=[];
        }
        $types=array_values(array_unique(array_column($media,'type')));
        $groupable=count($media)>1&&(
            count(array_diff($types,['photo','video']))===0
            ||$types===['document']
            ||$types===['audio']
        );
        $mode=$media===[]?'none':(count($media)===1?'single':($groupable?'group':'sequence'));
        $caption=$media!==[]&&$text!==''&&PostedTelegramTransportLayout::length($post,$text)<=self::PHOTO_CAPTION_LIMIT
            ?$text
            :'';
        $mediaItems=[];
        foreach($media as $index=>$item){
            $entry=[
                'type'=>(string)$item['type'],
                'media'=>(string)$item['file_id'],
            ];
            if($options['media_spoiler']&&in_array($item['type'],['photo','video','animation'],true))$entry['has_spoiler']=true;
            if($index===0&&$caption!==''){
                $entry['caption']=$caption;
                if($entities!==[])$entry['caption_entities']=$entities;
            }
            if($mode==='single')$entry=PostedTelegramCaptionPlacement::project($post,$entry);
            $mediaItems[]=($mode==='group'&&$options['paid_media_stars']===null)?PostedTelegramCaptionVector::project($post,$index,$entry):$entry;
        }
        $textParts=$caption===''&&$text!==''
            ?$this->splitTelegramFormattedTextForLayout($post,$text,$entities)
            :[];
        if($options['paid_media_stars']!==null){
            if(!$this->telegramMediaOptionCapabilities($post)['paid'])throw new InvalidArgumentException('Платное медиа недоступно для этого поста.');
            $mode='paid';
        }
        $fingerprintData=[
            'version'=>1,
            'mode'=>$mode,
            'media'=>$mediaItems,
            'text_parts'=>$textParts,
            'link_preview'=>$this->linkPreviewExtra($post),
        ];
        if($mode==='paid')$fingerprintData['paid_media_stars']=$options['paid_media_stars'];
        $fingerprint=hash('sha256',$this->encodeJson($fingerprintData));
        return [
            'mode'=>$mode,
            'media'=>$mediaItems,
            'text_parts'=>$textParts,
            'buttons'=>$this->telegramPostInlineButtonsAllowed($post)?$this->buttonsMarkup((array)$payload['buttons']):[],
            'fingerprint'=>$fingerprint,
        ];
    }

    private function dispatchTelegramPreviewPlan(
        int $userId,
        array $post,
        array $plan,
        array $finalExtra=[],
        ?Closure $observer=null
    ):array {
        $post=PostedTelegramPostOptions::freeze($this->pdo,$post);
        if(($plan['mode']??null)==='native')return$this->dispatchTelegramNativePreviewPlan($userId,$post,$plan,$finalExtra);
        $results=[];
        $media=(array)($plan['media']??[]);
        $textParts=(array)($plan['text_parts']??[]);
        $mode=(string)($plan['mode']??'none');
        $extraAttached=false;
        if($mode==='paid'){
            $payload=$this->effectivePostPayload($post);$caption='';$entities=[];
            if($textParts===[]&&isset($media[0])){$caption=(string)($media[0]['caption']??'');$entities=(array)($media[0]['caption_entities']??[]);}
            $paid=$this->telegramPaidMediaPayload($post,$userId,$caption,$entities,$textParts===[]?$finalExtra:[]);
            unset($paid['protect_content']);
            $results[]=$this->telegram->call('sendPaidMedia',$paid);if($observer!==null)$observer($results[count($results)-1]);$extraAttached=$textParts===[]&&$finalExtra!==[];
        }elseif($mode==='group'){
            $results[]=$this->telegram->sendMediaGroup($userId,$media);if($observer!==null)$observer($results[count($results)-1]);
        }elseif($mode==='single'||$mode==='sequence'){
            foreach($media as $index=>$item){
                $mediaExtra=[];
                if(($item['show_caption_above_media']??null)===true)$mediaExtra['show_caption_above_media']=true;
                if(!empty($item['has_spoiler']))$mediaExtra['has_spoiler']=true;
                if((array)($item['caption_entities']??[])!==[]){
                    $mediaExtra['caption_entities']=$item['caption_entities'];
                }
                if(
                    $finalExtra!==[]
                    &&$textParts===[]
                    &&$index===count($media)-1
                ){
                    $mediaExtra=array_merge($mediaExtra,$finalExtra);
                    $extraAttached=true;
                }
                $results[]=$this->sendMedia(
                    $userId,
                    (string)$item['type'],
                    (string)$item['media'],
                    (string)($item['caption']??''),
                    $mediaExtra
                );
                if($observer!==null)$observer($results[count($results)-1]);
            }
        }
        foreach($textParts as $index=>$part){
            $partExtra=$this->linkPreviewExtra($post);
            if((array)($part['entities']??[])!==[])$partExtra['entities']=$part['entities'];
            if($finalExtra!==[]&&$index===count($textParts)-1){
                $partExtra=array_merge($partExtra,$finalExtra);
                $extraAttached=true;
            }
            $results[]=$this->telegram->sendMessage(
                $userId,
                (string)($part['text']??''),
                $partExtra
            );
            if($observer!==null)$observer($results[count($results)-1]);
        }
        if($finalExtra!==[]&&!$extraAttached){
            $results[]=$this->telegram->sendMessage($userId,'‎',$finalExtra);if($observer!==null)$observer($results[count($results)-1]);
        }
        return $this->messageIdsFromResults($results);
    }

    private function sendPostPreview(int $userId, array $post, string $prefixText = ''): void
    {
        $repostSource = $this->repostForwardSource($post);
        if ($repostSource !== null) {
            $this->forwardTelegramRepost($post, (string) $userId, $userId, 'preview');
            if (trim($prefixText) !== '') {
                $this->telegram->sendMessage($userId, trim($prefixText));
            }
            return;
        }
        if(PostedTelegramNativeContent::isKind($post['media_type']??null)){$this->sendTelegramNativePreview($userId,$post,[],$prefixText);return;}
        $plan=$this->telegramPreviewPlan($post,$prefixText);
        $buttons=(array)($plan['buttons']??[]);
        $extra=$buttons!==[]?['reply_markup'=>['inline_keyboard'=>$buttons]]:[];
        $this->dispatchTelegramPreviewPlan($userId,$post,$plan,$extra);
    }

    private function sendDraftPreview(int $userId, array $post, array $controlKeyboard, string $prefixText = '', ?array $target = null): bool
    {
        if(PostedTelegramNativeContent::isKind($post['media_type']??null)){$controlKeyboard=$this->telegramWebDraftNextKeyboard($userId,$post,$controlKeyboard);return$this->sendTelegramNativePreview($userId,$post,$controlKeyboard,$prefixText);}
        $postId=(int)($post['id']??0);
        if($postId<=0)return false;
        if($this->telegramDraftPanelBlocksOuterSql($userId,$postId))return false;
        if(isset($target['album_key']))$controlKeyboard=$this->draftMainKeyboard($post);
        $controlKeyboard=$this->telegramWebDraftNextKeyboard($userId,$post,$controlKeyboard);
        $panel=$this->restoreTelegramDraftBoundPanel($userId,$post,$controlKeyboard,$prefixText,$target);
        if($panel!==null)return$panel;
        try{
            $obsolete=$this->withTelegramManagedPostLock(
                $userId,
                $postId,
                ['draft'],
                function (array $lockedPost) use ($userId, $controlKeyboard, $prefixText, $target): array {
                    $this->assertTelegramAlbumPreviewClaim($lockedPost,$target);
                    return $this->sendLockedDraftPreview(
                        $userId,
                        $lockedPost,
                        $controlKeyboard,
                        $prefixText,
                        $target
                    );
                }
            );
            if(!is_array($obsolete))return false;
            // The replacement pointers have COMMITTED before any old bot preview is retired.
            foreach(array_values(array_unique($obsolete))as$messageId){
                if(!is_int($messageId)||$messageId<=0)continue;
                try{$this->telegram->deleteMessage($userId,$messageId);}catch(Throwable){}
            }
            return true;
        }catch(Throwable $exception){
            error_log('GitPosted draft preview failed: '.$exception->getMessage());
            try {
                $this->telegram->sendMessage($userId, 'Не удалось показать предпросмотр. Сохранённые настройки остаются в черновике. Откройте карточку поста заново.');
            } catch (Throwable $noticeError) {
                error_log('GitPosted draft preview notice failed: ' . $noticeError->getMessage());
            }
        }
        return false;
    }

    private function sendLockedDraftPreview(int $userId, array $post, array $controlKeyboard, string $prefixText = '', ?array $target = null): array
    {
        $repostSource = $this->repostForwardSource($post);
        if ($repostSource !== null) {
            try {
                $this->forwardTelegramRepost($post, (string) $userId, $userId, 'draft-preview');
            } catch (PostedTelegramRequestException|InvalidArgumentException $exception) {
                if ($exception instanceof PostedTelegramRequestException && (
                    $exception->deliveryMayBeUnknown() || $exception->httpStatus() !== 400
                )) {
                    throw $exception;
                }
                $this->telegram->sendMessage(
                    $userId,
                    'Не удалось показать предпросмотр репоста. Режим репоста сохранён; пост не превращён в копию. '
                        . ($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Проверьте доступ бота к источнику и наличие исходного сообщения. Чтобы использовать сохранённое содержимое без шапки, выберите копию.'),
                    ['reply_markup' => ['inline_keyboard' => $controlKeyboard]]
                );
                error_log('GitPosted repost preview unavailable; native mode preserved: ' . $exception->getMessage());
                return [];
            }
            $controlText = trim($prefixText);
            $this->telegram->sendMessage(
                $userId,
                $controlText !== ''
                    ? $controlText
                    : 'Репост принят. Он будет опубликован без изменений. Чтобы изменить текст, медиа или добавить кнопки, выберите «Сделать копией для редактирования».',
                ['reply_markup' => ['inline_keyboard' => $controlKeyboard]]
            );
            return [];
        }
        $plan=$this->telegramPreviewPlan($post);
        $oldChatId=(string)($post['telegram_preview_chat_id']??'');
        $oldMessageIds=$oldChatId===(string)$userId
            ?$this->decodeMessageSlots((string)($post['telegram_preview_message_ids']??''))
            :[];
        $oldControlId=$oldChatId===(string)$userId
            ?max(0,(int)($post['telegram_preview_control_message_id']??0))
            :0;
        $samePreview=$oldMessageIds!==[]&&hash_equals(
            (string)($post['telegram_preview_fingerprint']??''),
            (string)$plan['fingerprint']
        );
        $postButtons=(array)($plan['buttons']??[]);
        $keyboard=array_merge($postButtons,$controlKeyboard);
        $controlExtra=['reply_markup'=>['inline_keyboard'=>$keyboard]];
        $controlsOnPreview=(string)($plan['mode']??'none')!=='group';
        $newMessageIds=$samePreview
            ?$oldMessageIds
            :$this->dispatchTelegramPreviewPlan(
                $userId,
                $post,
                $plan,
                $controlsOnPreview?$controlExtra:[]
            );

        $newMessageIds=PostedTelegramPreviewProof::requireKnownIds(
            $newMessageIds,PostedTelegramPreviewProof::expectedCount($plan,$controlsOnPreview)
        );
        if(!$samePreview&&array_intersect($newMessageIds,array_merge($oldMessageIds,$oldControlId>0?[$oldControlId]:[]))!==[])throw new RuntimeException('A replacement preview reused an old message ID.');
        $controlText=trim($prefixText);
        $controlText=$controlText!==''?$controlText:((string)($plan['mode']??'')==='group'?'Настройте пост перед публикацией.':'Управление постом');
        $controlId=0;
        if($controlsOnPreview){
            $candidate=max(0,(int)end($newMessageIds));
            if($candidate>0){
                if($samePreview){
                    try{
                        $this->telegram->call('editMessageReplyMarkup',[
                            'chat_id'=>(string)$userId,
                            'message_id'=>$candidate,
                            'reply_markup'=>$controlExtra['reply_markup'],
                        ]);
                    }catch(Throwable $exception){
                        if(!str_contains(mb_strtolower($exception->getMessage()),'message is not modified')){
                            throw $exception;
                        }
                    }
                }
                $controlId=$candidate;
            }
        }elseif($samePreview){
            $candidates=[];
            if($target!==null&&empty($target['has_media'])){
                $candidates[]=max(0,(int)($target['message_id']??0));
            }
            if($oldControlId>0&&!in_array($oldControlId,$candidates,true)){
                $candidates[]=$oldControlId;
            }
            foreach($candidates as $candidate){
                if($candidate<=0)continue;
                try{
                    $this->telegram->editMessageText($userId,$candidate,$controlText,$controlExtra);
                    $controlId=$candidate;
                    break;
                }catch(Throwable $exception){
                    if(str_contains(mb_strtolower($exception->getMessage()),'message is not modified')){
                        $controlId=$candidate;
                        break;
                    }
                }
            }
        }
        if($controlId<=0){
            $control=$this->telegram->sendMessage($userId,$controlText,$controlExtra);
            $controlId=(int)($control['result']['message_id']??0);
        }

        if($controlId<=0||(!$controlsOnPreview&&in_array($controlId,$newMessageIds,true)))throw new RuntimeException('Preview controls have no distinct known message ID.');

        $this->pdo->prepare(
            "UPDATE posted_posts
             SET telegram_preview_chat_id=:chat_id,
                 telegram_preview_message_ids=:message_ids,
                 telegram_preview_control_message_id=:control_id,
                 telegram_preview_fingerprint=:fingerprint
             WHERE id=:id AND status='draft'"
        )->execute([
            'chat_id'=>(string)$userId,
            'message_ids'=>$this->encodeMessageIds($newMessageIds),
            'control_id'=>$controlId>0?$controlId:null,
            'fingerprint'=>(string)$plan['fingerprint'],
            'id'=>(int)$post['id'],
        ]);

        $this->confirmTelegramAlbumPreviewLocked($post,$newMessageIds,$controlId,(string)$plan['fingerprint']);
        $retire=[];
        if(!$samePreview){
            $obsolete=array_merge($oldMessageIds,$oldControlId>0?[$oldControlId]:[]);
            if($target!==null&&!empty($target['has_media'])){
                $obsolete[]=max(0,(int)($target['message_id']??0));
            }
            $keep=array_merge($newMessageIds,$controlId>0?[$controlId]:[]);
            foreach(array_values(array_unique($obsolete)) as $messageId){
                if($messageId<=0||in_array($messageId,$keep,true))continue;
                $retire[]=$messageId;
            }
        }elseif($oldControlId>0&&$oldControlId!==$controlId){
            $retire[]=$oldControlId;
        }
        if(
            $samePreview
            &&$controlsOnPreview
            &&$target!==null
            &&empty($target['has_media'])
            &&(int)($target['message_id']??0)>0
            &&(int)$target['message_id']!==$controlId
        ){
            $retire[]=(int)$target['message_id'];
        }
        return array_values(array_unique($retire));
    }

    private function splitTelegramFormattedTextForLayout(array $post,string $text,array $entities):array
    {return PostedTelegramTransportLayout::scalar($post)?$this->splitTelegramScalarFormattedText($text,$entities):$this->splitTelegramFormattedText($text,$entities);}

    private function telegramTransportLayoutBehavior(array $post):array
    {
        if(!PostedTelegramTransportLayout::scalar($post))return[];
        $p=$this->effectivePostPayload($post);$text=(string)$p['text'];$entities=(array)$p['entities'];$media=$this->postMedia($post);
        $oldCaption=$media!==[]&&$this->telegramUtf16Length($text)<=self::PHOTO_CAPTION_LIMIT;
        $newCaption=$media!==[]&&PostedTelegramTransportLayout::characters($text)<=self::PHOTO_CAPTION_LIMIT;
        $changed=$oldCaption!==$newCaption;
        if(!$newCaption&&array_column($this->splitTelegramFormattedText($text,$entities),'text')!==array_column($this->splitTelegramScalarFormattedText($text,$entities),'text'))$changed=true;
        return$changed?['version'=>1,'metric'=>PostedTelegramTransportLayout::SCALAR]:[];
    }

    private function splitTelegramScalarFormattedText(string $text, array $entities): array
    {
        PostedTelegramTransportLayout::characters($text);
        if ($text === '') {
            return [['text'=>'','entities'=>[]]];
        }

        $entities = $this->normalizeTelegramEntities($text,$entities);
        $parts = [];
        $remaining = $text;
        $utf16Offset = 0;
        while (PostedTelegramTransportLayout::characters($remaining) > self::TEXT_LIMIT) {
            $prefixCharacters=self::TEXT_LIMIT;
            if($prefixCharacters<1){
                throw new RuntimeException('Unable to split Telegram text at a character boundary.');
            }
            $chunk = mb_substr($remaining, 0, $prefixCharacters, 'UTF-8');
            $newlineAt=mb_strrpos($chunk,"\n",0,'UTF-8');
            $spaceAt=mb_strrpos($chunk,' ',0,'UTF-8');
            $breakAt=max(
                $newlineAt===false?-1:(int)$newlineAt,
                $spaceAt===false?-1:(int)$spaceAt
            );
            if ($breakAt < 1000) {
                $breakAt = $prefixCharacters;
            } else {
                $breakAt++;
            }
            $partText = mb_substr($remaining, 0, $breakAt, 'UTF-8');
            $partLength = $this->telegramUtf16Length($partText);
            $parts[] = [
                'text'=>$partText,
                'entities'=>$this->telegramEntitiesForRange(
                    $entities,
                    $utf16Offset,
                    $partLength
                ),
            ];
            $utf16Offset += $partLength;
            $remaining = mb_substr($remaining, $breakAt, null, 'UTF-8');
        }
        if ($remaining !== '') {
            $remainingLength = $this->telegramUtf16Length($remaining);
            $parts[] = [
                'text'=>$remaining,
                'entities'=>$this->telegramEntitiesForRange(
                    $entities,
                    $utf16Offset,
                    $remainingLength
                ),
            ];
        }

        return $parts !== [] ? $parts : [['text'=>'','entities'=>[]]];
    }

    private function splitTelegramText(string $text): array
    {
        return array_map(
            static fn(array $part):string=>(string) ($part['text'] ?? ''),
            $this->splitTelegramFormattedText($text, [])
        );
    }

    private function splitTelegramFormattedText(string $text, array $entities): array
    {
        if ($text === '') {
            return [['text'=>'','entities'=>[]]];
        }

        $entities = $this->normalizeTelegramEntities($text,$entities);
        $parts = [];
        $remaining = $text;
        $utf16Offset = 0;
        while ($this->telegramUtf16Length($remaining) > self::TEXT_LIMIT) {
            $prefixCharacters=$this->telegramPrefixCharactersForUtf16Limit(
                $remaining,
                self::TEXT_LIMIT
            );
            if($prefixCharacters<1){
                throw new RuntimeException('Unable to split Telegram text at a UTF-16 boundary.');
            }
            $chunk = mb_substr($remaining, 0, $prefixCharacters);
            $newlineAt=mb_strrpos($chunk,"\n");
            $spaceAt=mb_strrpos($chunk,' ');
            $breakAt=max(
                $newlineAt===false?-1:(int)$newlineAt,
                $spaceAt===false?-1:(int)$spaceAt
            );
            if ($breakAt < 1000) {
                $breakAt = $prefixCharacters;
            } else {
                $breakAt++;
            }
            $partText = mb_substr($remaining, 0, $breakAt);
            $partLength = $this->telegramUtf16Length($partText);
            $parts[] = [
                'text'=>$partText,
                'entities'=>$this->telegramEntitiesForRange(
                    $entities,
                    $utf16Offset,
                    $partLength
                ),
            ];
            $utf16Offset += $partLength;
            $remaining = mb_substr($remaining, $breakAt);
        }
        if ($remaining !== '') {
            $remainingLength = $this->telegramUtf16Length($remaining);
            $parts[] = [
                'text'=>$remaining,
                'entities'=>$this->telegramEntitiesForRange(
                    $entities,
                    $utf16Offset,
                    $remainingLength
                ),
            ];
        }

        return $parts !== [] ? $parts : [['text'=>'','entities'=>[]]];
    }

    private function messageIdsFromResults(array $results): array
    {
        $ids = [];
        foreach ($results as $result) {
            $payload = $result['result'] ?? null;
            if (is_array($payload) && array_is_list($payload)) {
                foreach ($payload as $message) {
                    $id = (int) (is_array($message) ? ($message['message_id'] ?? 0) : 0);
                    if ($id > 0) $ids[] = $id;
                }
                continue;
            }
            $id = (int) (is_array($payload) ? ($payload['message_id'] ?? 0) : 0);
            if ($id > 0) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private function encodeMessageIds(array $messageIds): string
    {
        return $this->encodeMessageSlots($messageIds);
    }

    private function encodeMessageSlots(array $messageIds): string
    {
        $slots=array_values(array_map(
            static fn(mixed $messageId):int=>max(0,(int)$messageId),
            $messageIds
        ));
        return json_encode($slots,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'[]';
    }

    private function decodeMessageSlots(string $encoded): array
    {
        if(trim($encoded)==='')return [];
        $decoded=json_decode($encoded,true);
        if(!is_array($decoded)||!array_is_list($decoded))return [];
        return array_values(array_map(
            static fn(mixed $messageId):int=>max(0,(int)$messageId),
            $decoded
        ));
    }

    private function postMessageIds(array $post): array
    {
        $ids = [];
        $raw = (string) ($post['telegram_message_ids'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }

        $legacyId = (int) ($post['telegram_message_id'] ?? 0);
        if ($legacyId > 0 && !in_array($legacyId, $ids, true)) {
            array_unshift($ids, $legacyId);
        }

        return array_values(array_unique($ids));
    }

    private function setState(int $userId, string $state, array $data = []): void
    {
        $this->pdo->prepare('UPDATE posted_users SET state = :state, state_data = :data WHERE telegram_id = :id')
            ->execute(['state' => $state, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'id' => $userId]);
    }

    private function clearState(int $userId): void
    {
        $this->pdo->prepare('UPDATE posted_users SET state = NULL, state_data = NULL WHERE telegram_id = :id')
            ->execute(['id' => $userId]);
    }

    private function clearTelegramRepostInputIfCurrent(int $userId, string $state, array $data): void
    {
        // Called under the managed post/actor lock when copy input loses a race
        // to native mode. Clear that obsolete input, preserving a newer prompt.
        $this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL
            WHERE telegram_id=:user AND state=:state AND state_data=CAST(:data AS JSON)')
            ->execute(['user'=>$userId,'state'=>$state,'data'=>$this->encodeJson($data)]);
    }

    private function stateData(?array $user): array
    {
        return $this->decodeJson((string) ($user['state_data'] ?? ''));
    }

    private function callbackTarget(array $callback): ?array
    {
        $message = (array) ($callback['message'] ?? []);
        $chat = (array) ($message['chat'] ?? []);
        $chatId = (int) ($chat['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === 0 || $messageId <= 0) {
            return null;
        }
        return [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'has_media' => !empty($message['photo'])
                || !empty($message['video'])
                || !empty($message['animation'])
                || !empty($message['document'])
                || !empty($message['audio'])
                || !empty($message['voice'])
                || !empty($message['video_note']) || !empty($message['sticker']) || !empty($message['poll']) || !empty($message['location']) || !empty($message['contact']),
        ];
    }

    private function sendOrEditMessage(int $userId, string $text, array $extra = [], ?array $target = null): int
    {
        if ($target !== null) {
            if (!empty($target['has_media'])||!empty($target['preserve_message'])) {
                try {
                    $this->telegram->call('editMessageReplyMarkup',[
                        'chat_id'=>(string)$target['chat_id'],
                        'message_id'=>(int)$target['message_id'],
                        'reply_markup'=>['inline_keyboard'=>[]],
                    ]);
                } catch (Throwable $exception) {
                    error_log('GitPosted could not clear an obsolete callback keyboard: '.$exception->getMessage());
                }
                try {
                    $sent=$this->telegram->sendMessage($userId, $text, $extra);
                    return max(0,(int)($sent['result']['message_id']??0));
                } catch (Throwable $exception) {
                    error_log('GitPosted could not send a callback response: '.$exception->getMessage());
                    return 0;
                }
            }
            try {
                    $this->telegram->editMessageText((string) $target['chat_id'], (int) $target['message_id'], $text, $extra);
                    return max(0,(int)$target['message_id']);
            } catch (Throwable $editException) {
                try {
                    $sent=$this->telegram->sendMessage($userId, $text, $extra);
                    return max(0,(int)($sent['result']['message_id']??0));
                } catch (Throwable $sendException) {
                    error_log(
                        'GitPosted could neither edit nor send a callback response: '
                        .$editException->getMessage().' / '.$sendException->getMessage()
                    );
                    return 0;
                }
            }
        }
        $sent=$this->telegram->sendMessage($userId, $text, $extra);
        return max(0,(int)($sent['result']['message_id']??0));
    }

    private function beginDraftInput(
        int $userId,
        string $state,
        array $data,
        string $text,
        array $extra=[],
        ?array $target=null
    ):void {
        $menuMessageId=$this->sendOrEditMessage($userId,$text,$extra,$target);
        if($menuMessageId>0)$data['menu_message_id']=$menuMessageId;
        $this->setState($userId,$state,$data);
    }

    private function draftStateMenuTarget(int $userId,array $data):?array
    {
        $messageId=max(0,(int)($data['menu_message_id']??0));
        if($messageId<=0)return null;
        return [
            'chat_id'=>$userId,
            'message_id'=>$messageId,
            'has_media'=>false,
        ];
    }

    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function encodeJson(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function botUsername(): string
    {
        return ltrim(trim((string) ($this->config['bot_username'] ?? '')), '@') ?: 'your_bot';
    }

    private function serviceBaseUrl(): string
    {
        $url = rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');
        return $url !== '' ? $url : 'https://example.com';
    }

    private function telegramUiLanguage(int $userId): string
    {
        if (!isset($this->telegramUiLanguageCache[$userId])) {
            $this->telegramUiLanguageCache[$userId] = PostedTelegramUi::language($this->pdo, $userId);
        }
        return $this->telegramUiLanguageCache[$userId];
    }

    private function telegramUiText(int $userId, string $key, string $russian, array $parameters = []): string
    {
        return $this->telegramUiLanguage($userId) === 'en' ? PostedTelegramUi::englishHtml($key, $parameters) : $russian;
    }

    private function serviceNewsUsername(): string
    {
        $username = ltrim(trim((string) ($this->config['service_news_username'] ?? '')), '@');
        return preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username) === 1 ? $username : '';
    }

    private function serviceAdminUsername(): string
    {
        $username = ltrim(trim((string) ($this->config['service_admin_username'] ?? '')), '@');
        return preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username) === 1 ? $username : '';
    }

    private function serviceAdminLabel(): string
    {
        $username = $this->serviceAdminUsername();
        return $username !== '' ? '@' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'контакт владельца пока не указан';
    }

    private function serviceAdminUrl(): string
    {
        $url = trim((string) ($this->config['service_admin_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }
        $username = $this->serviceAdminUsername();
        return $username !== '' ? 'https://t.me/' . $username : $this->serviceBaseUrl();
    }

    private function isKnownActiveUser(?array $user): bool
    {
        return is_array($user) && (int) ($user['is_active'] ?? 0) === 1;
    }

    private function answerCallback(array $callback, string $text = ''): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', [
                'callback_query_id' => (string) ($callback['id'] ?? ''),
                'text' => $text,
                'show_alert' => false,
            ]);
        } catch (Throwable) {
        }
    }
}

function posted_app(): PostedBot
{
    $config = PostedConfig::get();
    $pdo = PostedDb::pdo($config);
    PostedDb::assertSchema($pdo);
    return new PostedBot($pdo, new PostedTelegram((string) $config['bot_token']), $config);
}
