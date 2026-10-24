<?php
declare(strict_types=1);

// Extract only pure declarations; never include the HTTP/database entry point.
$tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/payload/src/control/passkeys.php'));
$wanted = ['passkeySite', 'passkeyCapabilities', 'requirePasskeySite'];
$functions = '';
for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    for ($j = $i + 1; $j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; ++$j) {}
    if (!is_array($tokens[$j] ?? null) || !in_array($tokens[$j][1], $wanted, true)) continue;
    $depth = 0; $opened = false;
    for ($j = $i; $j < $count; ++$j) {
        $token = $tokens[$j]; $functions .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { ++$depth; $opened = true; }
        if ($token === '}') --$depth;
        if ($opened && $depth === 0) break;
    }
    $functions .= "\n";
}
$fixture = tempnam(sys_get_temp_dir(), 'gitposted-passkey-check-');
if ($fixture === false) throw new RuntimeException('Fixture unavailable');
try {
    file_put_contents($fixture, '<?php declare(strict_types=1);'
        . 'final class PostedConfig { public static function get(): array { return ["base_url"=>$GLOBALS["base"]]; } }'
        . 'final class FixtureFailure extends RuntimeException { public function __construct(public int $status) { parent::__construct("domain required"); } }'
        . 'function fail(string $message, int $status=400): never { throw new FixtureFailure($status); }'
        . '$base=$argv[1];' . $functions
        . '$cap=passkeyCapabilities(); try {$site=requirePasskeySite(); $status=200;} catch(FixtureFailure $e){$site=null;$status=$e->status;}'
        . 'echo json_encode(["capabilities"=>$cap,"status"=>$status,"site"=>$site]);');
    $cases = [
        ['https://installer.example', true, 'installer.example'],
        ['https://installer.example/GitPosted', true, 'installer.example'],
        ['https://INSTALLER.EXAMPLE:443', true, 'installer.example'],
        ['https://installer.example:8443', true, 'installer.example'],
        ['https://8.8.8.8', false, null],
        ['http://installer.example', false, null],
        ['https://localhost', false, null],
        ['', false, null],
    ];
    foreach ($cases as [$base, $supported, $rp]) {
        $output = []; $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($base), $output, $code);
        $data = json_decode(implode("\n", $output), true, 16, JSON_THROW_ON_ERROR);
        if ($code !== 0 || $data['capabilities']['supported'] !== $supported || $data['status'] !== ($supported ? 200 : 409)) {
            throw new RuntimeException('Passkey capability contract failed');
        }
        if ($supported && $data['site']['rp_id'] !== $rp) throw new RuntimeException('RP ID mismatch');
        if (!$supported && ($data['site'] !== null || !str_contains($data['capabilities']['reason'], 'HTTPS-домен'))) throw new RuntimeException('Missing actionable domain hint');
        echo 'PASS passkey domain capability: ', $base === '' ? 'empty' : $base, "\n";
    }
} finally { unlink($fixture); }
