<?php

declare(strict_types=1);

/** Only an explicit boolean false disables CAPTCHA; malformed settings fail closed. */
function gitpostedAdminCaptchaRequired(array $settings): bool
{
    return ($settings['enabled'] ?? true) !== false;
}

function gitpostedAdminPasswordValid(array $auth, string $login, string $password, bool $captchaVerified, bool $captchaRequired): bool
{
    return (!$captchaRequired || $captchaVerified)
        && trim((string) ($auth['username'] ?? '')) !== ''
        && hash_equals((string) ($auth['username'] ?? ''), $login)
        && password_verify($password, (string) ($auth['password_hash'] ?? ''));
}

/** First attachment has no retired client and must never authorize an empty token. */
function gitpostedAdminPreviousClient(array $config, string $tokenKey, callable $factory): mixed
{
    if (!in_array($tokenKey, ['bot_token', 'max_bot_token'], true)) throw new InvalidArgumentException('Invalid platform token key');
    return trim((string) ($config[$tokenKey] ?? '')) === '' ? null : $factory($config);
}
