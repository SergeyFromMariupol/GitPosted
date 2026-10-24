<?php

declare(strict_types=1);

/** The configured public URL is the only authority for the application mount. */
function gitpostedUrlMount(string $baseUrl, bool $allowHttp = false): string
{
    $parts = parse_url($baseUrl);
    if ($baseUrl === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $baseUrl)
        || filter_var($baseUrl, FILTER_VALIDATE_URL) === false || !is_array($parts)
        || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['scheme'] ?? '', $allowHttp ? ['http', 'https'] : ['https'], true)
        || !in_array($parts['path'] ?? '', ['', '/', '/GitPosted'], true)) {
        throw new InvalidArgumentException('Configured application URL is invalid.');
    }
    return ($parts['path'] ?? '') === '/GitPosted' ? '/GitPosted' : '';
}

/** Return a local absolute URL path; never consult request or forwarded headers. */
function gitpostedAppPath(array $config, string $path): string
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')
        || preg_match('/[\x00-\x20\x7f\\\\]/', $path)
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', explode('?', $path, 2)[0])) {
        throw new InvalidArgumentException('Application path is invalid.');
    }
    return gitpostedUrlMount((string) ($config['base_url'] ?? '')) . $path;
}
