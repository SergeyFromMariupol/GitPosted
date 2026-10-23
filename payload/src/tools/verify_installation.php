<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/max-bootstrap.php';

/** @param array<string,mixed> $context */
function verificationAssert(bool $condition, string $message, array $context = []): void
{
    if ($condition) {
        return;
    }
    $suffix = $context === []
        ? ''
        : ' ' . json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    throw new RuntimeException($message . $suffix);
}

/** @return int */
function verificationScalar(PDO $pdo, string $sql): int
{
    $value = $pdo->query($sql)->fetchColumn();
    return (int) ($value === false ? 0 : $value);
}

try {
    $config = PostedConfig::get();
    $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    verificationAssert(
        filter_var($baseUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($baseUrl, PHP_URL_SCHEME) === 'https',
        'base_url must be a valid HTTPS URL.'
    );
    foreach (['telegram' => ['bot_token', 'webhook_secret'], 'max' => ['max_bot_token', 'max_webhook_secret']] as $platform => $keys) {
        $enabledKey = $platform === 'telegram' ? 'telegram_bot_enabled' : 'max_bot_enabled';
        if (($config[$enabledKey] ?? false) !== true) continue;
        foreach ($keys as $key) verificationAssert(trim((string) ($config[$key] ?? '')) !== '', "Missing config key: {$key}.");
    }
    foreach (['notification_telegram_id', 'notification_max_id'] as $key) {
        $recipient = trim((string) ($config[$key] ?? ''));
        verificationAssert(
            $recipient === ''
                || $recipient === '0'
                || preg_match('/^[1-9][0-9]*$/D', $recipient) === 1,
            "Invalid technical notification recipient: {$key}."
        );
    }
    foreach (['database', 'admin_auth', 'hcaptcha', 'mtproto'] as $section) {
        verificationAssert(
            is_array($config[$section] ?? null),
            "Missing config section: {$section}."
        );
    }
    foreach (['yookassa', 'telegram_stars', 'payment', 'billing', 'commercial'] as $legacyKey) {
        verificationAssert(
            !array_key_exists($legacyKey, $config),
            "Legacy commercial config key is still present: {$legacyKey}."
        );
    }

    $pdo = PostedDb::pdo($config);
    PostedDb::assertSchema($pdo);

    $checks = [
        'telegram_non_user_roles' =>
            "SELECT COUNT(*) FROM posted_users WHERE role<>'user'",
        'max_non_user_roles' =>
            "SELECT COUNT(*) FROM posted_max_users WHERE role<>'user'",
        'telegram_active_channels_without_owner' =>
            'SELECT COUNT(*) FROM posted_channels
             WHERE is_active=1 AND (added_by IS NULL OR added_by<=0)',
        'telegram_admin_blocked_active_channels' =>
            'SELECT COUNT(*) FROM posted_channels
             WHERE admin_blocked=1 AND is_active=1',
        'max_active_channels_without_owner' =>
            "SELECT COUNT(*) FROM posted_max_channels
             WHERE is_active=1 AND (added_by IS NULL OR added_by='')",
        'max_admin_blocked_active_channels' =>
            'SELECT COUNT(*) FROM posted_max_channels
             WHERE admin_blocked=1 AND is_active=1',
        'duplicate_account_identities' =>
            "SELECT COUNT(*) FROM (
                SELECT platform,external_user_id
                FROM posted_account_identities
                GROUP BY platform,external_user_id
                HAVING COUNT(*)>1
             ) duplicate_identity",
        'duplicate_account_platforms' =>
            "SELECT COUNT(*) FROM (
                SELECT account_id,platform
                FROM posted_account_identities
                GROUP BY account_id,platform
                HAVING COUNT(*)>1
             ) duplicate_platform",
        'telegram_non_owner_role_managers' =>
            'SELECT COUNT(*)
             FROM posted_permissions permission
             JOIN posted_channels channel ON channel.id=permission.channel_id
             WHERE permission.can_manage_admins=1
               AND permission.user_id<>COALESCE(channel.added_by,0)',
        'telegram_owners_without_full_permissions' =>
            'SELECT COUNT(*)
             FROM posted_channels channel
             LEFT JOIN posted_permissions permission
               ON permission.channel_id=channel.id
              AND permission.user_id=channel.added_by
             WHERE channel.is_active=1
               AND channel.added_by IS NOT NULL
               AND (
                   permission.user_id IS NULL
                   OR permission.can_post<>1
                   OR permission.can_schedule<>1
                   OR permission.can_manage_posts<>1
                   OR permission.can_manage_admins<>1
               )',
        'max_owners_duplicated_as_editors' =>
            'SELECT COUNT(*)
             FROM posted_max_channels channel
             JOIN posted_max_permissions permission
               ON permission.channel_id=channel.id
              AND permission.max_user_id=channel.added_by
             WHERE channel.added_by IS NOT NULL',
        'max_zero_right_permissions' =>
            'SELECT COUNT(*)
             FROM posted_max_permissions
             WHERE can_post=0 AND can_schedule=0 AND can_manage=0',
        'telegram_active_categories_without_manager' =>
            'SELECT COUNT(*) FROM posted_manager_categories category_row
             LEFT JOIN posted_permissions permission
               ON permission.user_id=category_row.user_id
              AND permission.channel_id=category_row.channel_id
             WHERE category_row.is_active=1
               AND (permission.user_id IS NULL OR permission.is_ad_manager<>1)',
        'max_active_categories_without_manager' =>
            'SELECT COUNT(*) FROM posted_max_manager_categories category_row
             LEFT JOIN posted_max_permissions permission
               ON permission.max_user_id=category_row.max_user_id
              AND permission.channel_id=category_row.channel_id
             WHERE category_row.is_active=1
               AND (permission.max_user_id IS NULL OR permission.is_ad_manager<>1)',
        'telegram_posts_with_mismatched_category' =>
            'SELECT COUNT(*) FROM posted_posts post
             LEFT JOIN posted_manager_categories category_row
               ON category_row.id=post.manager_category_id
             WHERE post.manager_category_id IS NOT NULL
               AND (
                 category_row.id IS NULL
                 OR category_row.user_id<>post.author_id
                 OR category_row.channel_id<>post.channel_id
               )',
        'max_posts_with_mismatched_category' =>
            'SELECT COUNT(*) FROM posted_max_posts post
             LEFT JOIN posted_max_manager_categories category_row
               ON category_row.id=post.manager_category_id
             WHERE post.manager_category_id IS NOT NULL
               AND (
                 category_row.id IS NULL
                 OR category_row.max_user_id<>post.author_max_user_id
                 OR category_row.channel_id<>post.channel_id
               )',
        'telegram_charged_quota_without_current_manager' =>
            'SELECT COUNT(*)
             FROM posted_posts post
             LEFT JOIN posted_permissions permission
               ON permission.user_id=post.author_id
              AND permission.channel_id=post.channel_id
              AND permission.is_ad_manager=1
             LEFT JOIN posted_manager_categories category_row
               ON category_row.id=post.manager_category_id
              AND category_row.user_id=post.author_id
              AND category_row.channel_id=post.channel_id
              AND category_row.is_active=1
             WHERE (
                 post.manager_post_quota_charged<>0
                 OR post.manager_pin_quota_charged<>0
               )
               AND post.status IN (\'draft\',\'scheduled\',\'failed\')
               AND (
                 (post.manager_category_id IS NULL AND permission.user_id IS NULL)
                 OR
                 (post.manager_category_id IS NOT NULL AND category_row.id IS NULL)
               )',
        'telegram_cancelled_posts_with_charged_quota' =>
            "SELECT COUNT(*)
             FROM posted_posts
             WHERE status='cancelled'
               AND (
                 manager_post_quota_charged<>0
                 OR manager_pin_quota_charged<>0
               )",
        'max_charged_quota_without_current_manager' =>
            'SELECT COUNT(*)
             FROM posted_max_publish_attempts attempt
             JOIN posted_max_posts post ON post.id=attempt.post_id
             LEFT JOIN posted_max_permissions permission
               ON permission.max_user_id=post.author_max_user_id
              AND permission.channel_id=post.channel_id
              AND permission.is_ad_manager=1
             LEFT JOIN posted_max_manager_categories category_row
               ON category_row.id=post.manager_category_id
              AND category_row.max_user_id=post.author_max_user_id
              AND category_row.channel_id=post.channel_id
              AND category_row.is_active=1
             WHERE (
                 attempt.manager_post_quota_charged<>0
                 OR attempt.manager_pin_quota_charged<>0
               )
               AND attempt.status<>\'completed\'
               AND (
                 (post.manager_category_id IS NULL AND permission.max_user_id IS NULL)
                 OR
                 (post.manager_category_id IS NOT NULL AND category_row.id IS NULL)
               )',
        'max_failed_attempts_with_charged_quota' =>
            "SELECT COUNT(*)
             FROM posted_max_publish_attempts
             WHERE status='failed'
               AND (
                 manager_post_quota_charged<>0
                 OR manager_pin_quota_charged<>0
               )",
    ];
    $results = [];
    foreach ($checks as $name => $sql) {
        $results[$name] = verificationScalar($pdo, $sql);
        verificationAssert(
            $results[$name] === 0,
            'Database invariant failed.',
            ['check' => $name, 'count' => $results[$name]]
        );
    }

    $expectedTables = [
        'posted_account_identities',
        'posted_accounts',
        'posted_admin_passkeys',
        'posted_admin_tokens',
        'posted_app_settings',
        'posted_channels',
        'posted_edit_sessions',
        'posted_emoji_catalog',
        'posted_emoji_sets',
        'posted_emoji_submissions',
        'posted_folder_channels',
        'posted_folders',
        'posted_manager_categories',
        'posted_max_channels',
        'posted_max_deliveries',
        'posted_max_link_attempts',
        'posted_max_link_codes',
        'posted_max_manager_categories',
        'posted_max_permissions',
        'posted_max_posts',
        'posted_max_publish_attempts',
        'posted_max_reactions',
        'posted_max_schema_meta',
        'posted_max_templates',
        'posted_max_update_effects',
        'posted_max_updates',
        'posted_max_users',
        'posted_permissions',
        'posted_posts',
        'posted_reactions',
        'posted_telegram_publish_attempts',
        'posted_templates',
        'posted_users',
    ];
    $actualTables = $pdo->query(
        "SELECT TABLE_NAME
         FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $actualTables = array_map('strval', $actualTables);
    verificationAssert(
        $actualTables === $expectedTables,
        'Database table set does not match the free GitPosted schema.',
        [
            'missing' => array_values(array_diff($expectedTables, $actualTables)),
            'unexpected' => array_values(array_diff($actualTables, $expectedTables)),
        ]
    );
    $results['base_tables'] = count($actualTables);

    $requiredColumns = [
        'posted_permissions' => [
            'user_id',
            'channel_id',
            'is_ad_manager',
            'manager_description',
            'post_quota_remaining',
            'pin_quota_remaining',
        ],
        'posted_manager_categories' => [
            'id',
            'user_id',
            'channel_id',
            'name',
            'post_quota_remaining',
            'pin_quota_remaining',
            'is_active',
        ],
        'posted_posts' => [
            'id',
            'import_json',
            'channel_id',
            'author_id',
            'status',
            'manager_category_id',
            'manager_post_quota_charged',
            'manager_pin_quota_charged',
        ],
        'posted_telegram_publish_attempts' => [
            'post_id',
            'channel_id',
            'gate_channel_id',
            'submission_key',
            'state',
            'pin_started_at',
            'pin_completed_at',
            'pin_attempts',
            'pin_delivery_uncertain',
        ],
        'posted_max_channels' => [
            'id',
            'chat_id',
            'added_by',
            'is_active',
            'can_edit',
            'owner_checked_at',
        ],
        'posted_max_permissions' => [
            'max_user_id',
            'channel_id',
            'is_ad_manager',
            'manager_description',
            'post_quota_remaining',
            'pin_quota_remaining',
        ],
        'posted_max_manager_categories' => [
            'id',
            'max_user_id',
            'channel_id',
            'name',
            'post_quota_remaining',
            'pin_quota_remaining',
            'is_active',
        ],
        'posted_max_posts' => [
            'id',
            'channel_id',
            'author_max_user_id',
            'manager_category_id',
            'status',
        ],
        'posted_max_publish_attempts' => [
            'id',
            'post_id',
            'channel_id',
            'status',
            'gate_post_id',
            'gate_chat_id',
            'max_message_id',
            'pin_status',
            'pin_started_at',
            'pin_attempts',
            'manager_post_quota_charged',
            'manager_pin_quota_charged',
        ],
        'posted_max_updates' => [
            'update_key',
            'sequence_id',
            'actor_key',
            'status',
            'attempts',
            'updated_at',
        ],
        'posted_max_update_effects' => [
            'update_key',
            'effect_type',
            'result_json',
            'created_at',
        ],
        'posted_max_link_attempts' => [
            'telegram_user_id',
            'failed_attempts',
            'blocked_until',
            'updated_at',
        ],
        'posted_max_schema_meta' => ['id', 'schema_version'],
        'posted_max_reactions' => ['delivery_id', 'max_user_id', 'reaction_key', 'last_callback_id'],
    ];
    foreach (['posted_max_posts', 'posted_max_channels', 'posted_max_templates'] as $table) $requiredColumns[$table][] = 'options_json';
    $requiredColumns['posted_max_deliveries'][] = 'comment_json';
    $columnQuery = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
    );
    $requiredColumnCount = 0;
    foreach ($requiredColumns as $table => $columns) {
        $columnQuery->execute(['table' => $table]);
        $actualColumns = array_map('strval', $columnQuery->fetchAll(PDO::FETCH_COLUMN));
        $missingColumns = array_values(array_diff($columns, $actualColumns));
        verificationAssert(
            $missingColumns === [],
            'Required schema columns are missing.',
            ['table' => $table, 'missing' => $missingColumns]
        );
        $requiredColumnCount += count($columns);
    }
    $results['required_schema_columns'] = $requiredColumnCount;

    $requiredIndexes = [
        'posted_permissions' => [
            'PRIMARY' => [true, ['user_id', 'channel_id']],
            'idx_posted_permissions_channel' => [false, ['channel_id']],
        ],
        'posted_manager_categories' => [
            'PRIMARY' => [true, ['id']],
            'uq_posted_manager_category_name' => [
                true,
                ['user_id', 'channel_id', 'name'],
            ],
            'idx_posted_manager_categories_channel' => [false, ['channel_id']],
        ],
        'posted_posts' => [
            'idx_posted_posts_channel_status' => [
                false,
                ['channel_id', 'status', 'scheduled_at'],
            ],
            'idx_posted_posts_delete' => [false, ['status', 'delete_at']],
            'uq_posted_repeat_slot' => [true, ['copied_from_id', 'repeat_slot_at']],
        ],
        'posted_telegram_publish_attempts' => [
            'PRIMARY' => [true, ['post_id']],
            'uq_posted_telegram_gate_channel' => [true, ['gate_channel_id']],
            'uq_posted_telegram_submission' => [true, ['submission_key']],
            'idx_posted_telegram_attempt_state' => [false, ['state', 'deadline_at']],
            'idx_posted_telegram_attempt_chat' => [false, ['chat_id', 'state']],
        ],
        'posted_max_channels' => [
            'uq_posted_max_channels_chat' => [true, ['chat_id']],
            'idx_posted_max_channels_active' => [false, ['is_active']],
        ],
        'posted_max_permissions' => [
            'PRIMARY' => [true, ['max_user_id', 'channel_id']],
            'idx_posted_max_permissions_channel' => [false, ['channel_id']],
        ],
        'posted_max_manager_categories' => [
            'PRIMARY' => [true, ['id']],
            'uq_posted_max_manager_category_name' => [
                true,
                ['max_user_id', 'channel_id', 'name'],
            ],
            'idx_posted_max_manager_categories_channel' => [false, ['channel_id']],
        ],
        'posted_max_posts' => [
            'idx_posted_max_posts_due' => [false, ['status', 'scheduled_at']],
            'idx_posted_max_posts_repeat' => [false, ['status', 'repeat_next_at']],
            'idx_posted_max_posts_author' => [false, ['author_max_user_id', 'status']],
        ],
        'posted_max_publish_attempts' => [
            'uq_posted_max_attempt_key' => [true, ['attempt_key']],
            'uq_posted_max_attempt_message' => [true, ['max_message_id']],
            'uq_posted_max_attempt_gate_post' => [true, ['gate_post_id']],
            'uq_posted_max_attempt_gate_chat' => [true, ['gate_chat_id']],
            'idx_posted_max_attempt_queue' => [false, ['status', 'deadline_at']],
            'idx_posted_max_attempt_post' => [false, ['post_id', 'created_at']],
            'idx_posted_max_attempt_reconcile' => [
                false,
                ['status', 'last_checked_at', 'requested_at'],
            ],
        ],
        'posted_max_updates' => [
            'PRIMARY' => [true, ['update_key']],
            'uq_posted_max_updates_sequence' => [true, ['sequence_id']],
            'idx_posted_max_updates_queue' => [
                false,
                ['status', 'attempts', 'updated_at'],
            ],
            'idx_posted_max_updates_actor' => [
                false,
                ['actor_key', 'sequence_id', 'status'],
            ],
        ],
        'posted_max_update_effects' => [
            'PRIMARY' => [true, ['update_key']],
        ],
    ];
    $requiredIndexes['posted_max_reactions'] = [
        'PRIMARY' => [true, ['delivery_id', 'max_user_id']],
        'idx_posted_max_reactions_counts' => [false, ['delivery_id', 'reaction_key']],
    ];
    $actualIndexes = [];
    $indexRows = $pdo->query(
        'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE()
         ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX'
    )->fetchAll();
    foreach ($indexRows as $indexRow) {
        $table = (string) $indexRow['TABLE_NAME'];
        $index = (string) $indexRow['INDEX_NAME'];
        if (!isset($actualIndexes[$table][$index])) {
            $actualIndexes[$table][$index] = [
                'unique' => (int) $indexRow['NON_UNIQUE'] === 0,
                'columns' => [],
            ];
        }
        $actualIndexes[$table][$index]['columns'][] = (string) $indexRow['COLUMN_NAME'];
    }
    $requiredIndexCount = 0;
    foreach ($requiredIndexes as $table => $indexes) {
        foreach ($indexes as $index => [$unique, $columns]) {
            $actualIndex = $actualIndexes[$table][$index] ?? null;
            verificationAssert(
                is_array($actualIndex)
                    && $actualIndex['unique'] === $unique
                    && $actualIndex['columns'] === $columns,
                'Required schema index is missing or malformed.',
                [
                    'table' => $table,
                    'index' => $index,
                    'expected_unique' => $unique,
                    'expected_columns' => $columns,
                    'actual' => $actualIndex,
                ]
            );
            $requiredIndexCount++;
        }
    }
    $results['required_schema_indexes'] = $requiredIndexCount;

    $maxSchemaVersion = verificationScalar(
        $pdo,
        'SELECT schema_version FROM posted_max_schema_meta WHERE id=1'
    );
    verificationAssert(
        $maxSchemaVersion === 5,
        'MAX schema version does not match this release.',
        ['expected' => 5, 'actual' => $maxSchemaVersion]
    );
    $results['max_schema_version'] = $maxSchemaVersion;

    $legacySettings = [
        'access_mode',
        'billing_pricing_rules',
        'commercial_settings',
        'payment_methods',
        'payment_methods_by_surface',
        'subscription_prices_rub',
        'subscription_product_catalog',
        'telegram_stars_prices_xtr',
        'yookassa_method_rules',
        'yookassa_methods_by_surface',
    ];
    $settingPlaceholders = implode(',', array_fill(0, count($legacySettings), '?'));
    $legacySettingQuery = $pdo->prepare(
        "SELECT setting_key
         FROM posted_app_settings
         WHERE setting_key IN ({$settingPlaceholders})
         ORDER BY setting_key"
    );
    $legacySettingQuery->execute($legacySettings);
    $presentLegacySettings = array_map(
        'strval',
        $legacySettingQuery->fetchAll(PDO::FETCH_COLUMN)
    );
    verificationAssert(
        $presentLegacySettings === [],
        'Legacy commercial settings are still present.',
        ['setting_keys' => $presentLegacySettings]
    );
    $results['legacy_commercial_settings'] = count($presentLegacySettings);

    $results['legacy_referral_columns'] = verificationScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema=DATABASE()
           AND table_name='posted_accounts'
           AND column_name='referral_token'"
    );
    verificationAssert(
        $results['legacy_referral_columns'] === 0,
        'Legacy referral_token column is still present.'
    );
    $results['legacy_account_migration_tables'] = verificationScalar(
        $pdo,
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name='posted_account_migrations'"
    );
    verificationAssert(
        $results['legacy_account_migration_tables'] === 0,
        'Legacy posted_account_migrations table is still present.'
    );
    $results['canonical_accounts'] = verificationScalar(
        $pdo,
        'SELECT COUNT(*) FROM posted_accounts WHERE merged_into_account_id IS NULL'
    );
    $results['telegram_identities'] = verificationScalar(
        $pdo,
        "SELECT COUNT(*) FROM posted_account_identities WHERE platform='telegram'"
    );
    $results['max_identities'] = verificationScalar(
        $pdo,
        "SELECT COUNT(*) FROM posted_account_identities WHERE platform='max'"
    );

    echo json_encode(
        ['ok' => true, 'checks' => $results],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        json_encode(
            ['ok' => false, 'error' => $exception->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL
    );
    exit(1);
}
