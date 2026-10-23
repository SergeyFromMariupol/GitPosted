<?php

declare(strict_types=1);

/** Parse the official event without treating its actor as a channel owner. */
final class GitPostedMaxRightsEvent
{
    public static function parse(array $update): ?array
    {
        if (($update['update_type'] ?? $update['type'] ?? '') !== 'bot_admin_permissions_changed') {
            return null;
        }
        $result = [];
        foreach (['chat_id', 'user_id', 'bot_id'] as $key) {
            $value = $update[$key] ?? null;
            if ((!is_int($value) && !is_string($value))
                || preg_match($key === 'chat_id' ? '/^-?[1-9][0-9]{0,18}$/D' : '/^[1-9][0-9]{0,18}$/D', (string) $value) !== 1) {
                throw new InvalidArgumentException('Некорректный идентификатор в событии прав МАКС.');
            }
            $result[$key] = (string) $value;
        }
        foreach (['is_channel', 'is_admin'] as $key) {
            if (!is_bool($update[$key] ?? null)) {
                throw new InvalidArgumentException('Некорректный статус в событии прав МАКС.');
            }
            $result[$key] = $update[$key];
        }
        $timestamp = $update['timestamp'] ?? null;
        if (!is_int($timestamp) || $timestamp < 0) {
            throw new InvalidArgumentException('Некорректное время события прав МАКС.');
        }
        $result['timestamp'] = $timestamp;
        $permissions = $update['permissions'] ?? null;
        if ($permissions !== null) {
            if (!is_array($permissions) || !array_is_list($permissions)) {
                throw new InvalidArgumentException('Некорректные права в событии МАКС.');
            }
            foreach ($permissions as $permission) {
                if (!is_string($permission) || $permission === '') {
                    throw new InvalidArgumentException('Некорректные права в событии МАКС.');
                }
            }
            $permissions = array_values(array_unique($permissions));
        }
        $result['permissions'] = $permissions;
        return $result;
    }
}
