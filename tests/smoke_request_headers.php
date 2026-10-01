<?php

declare(strict_types=1);

/**
 * Smoke test: duplicate request headers (Swoole hands them over as arrays).
 *
 *   php tests/smoke_request_headers.php [port=21190]
 *
 * Starts a Swoole\Http\Server child on 127.0.0.1:<port>, sends one raw request with duplicated
 * headers, wraps the Swoole request in Loongs\Http\Request and checks the combined values.
 * Any PHP warning/notice while building the request counts as a failure.
 */

use Loongs\Http\Request;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Loongs\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$port = (int) ($argv[1] ?? 21190);
$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " — $detail" : '') . "\n";
};

if (($argv[2] ?? '') === 'serve') {
    // Child: a real Swoole\Http\Server (same server type the framework's HttpProcess uses).
    $server = new Swoole\Http\Server('127.0.0.1', $port, SWOOLE_BASE);
    $server->set(['worker_num' => 1, 'log_level' => SWOOLE_LOG_WARNING]);
    $server->on('request', static function ($req, $res): void {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$warnings): bool {
            $warnings[] = "$msg at " . basename($file) . ":$line";
            return true;
        });
        $r = new Request($req);
        restore_error_handler();
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode([
            'warnings' => $warnings,
            'raw_is_array' => is_array($req->header['x-dup'] ?? null),
            'headers' => $r->headers(),
            'x-dup' => $r->header('X-Dup'),
            'authorization' => $r->header('Authorization'),
            'cookie' => $r->header('cookie'),
            'schema' => $r->header('x-render-schema'),
            'accept' => $r->header('accept'),
            'referer' => $r->header('referer'),
            'values' => method_exists($r, 'headerValues') ? $r->headerValues('x-dup') : null,
        ]));
    });
    $server->start();
    exit(0);
}

$proc = proc_open([PHP_BINARY, '-d', 'disable_functions=', __FILE__, (string) $port, 'serve'], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
$sock = false;
for ($i = 0; $i < 50 && $sock === false; $i++) {
    usleep(100_000);
    $sock = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 1);
}
$result = null;
if ($sock !== false) {
    fwrite($sock, "GET /t HTTP/1.1\r\nHost: 127.0.0.1:$port\r\n"
        . "X-Dup: a\r\nX-Dup: b\r\nX-Dup: c\r\n"
        . "Authorization: Bearer first\r\nAuthorization: Bearer second\r\n"
        . "Referer: http://a/\r\nReferer: http://b/\r\n"
        . "X-Render-Schema: 1\r\nX-Render-Schema: 0\r\n"
        . "Accept: application/json\r\nConnection: close\r\n\r\n");
    stream_set_timeout($sock, 5);
    $reply = stream_get_contents($sock);
    fclose($sock);
    echo 'response: ' . strtok($reply, "\r\n") . " (server on 127.0.0.1:$port)\n";
    $parts = explode("\r\n\r\n", $reply, 2);
    $result = json_decode($parts[1] ?? '', true);
} else {
    echo "could not connect to 127.0.0.1:$port\n";
}
proc_terminate($proc, SIGTERM);
$stderr = stream_get_contents($pipes[2]);
proc_close($proc);
if (trim($stderr) !== '') {
    echo "server stderr: " . trim($stderr) . "\n";
}
$warnings = $result['warnings'] ?? [];

$check('server received the request', is_array($result));
$result ??= [];
$check('Swoole delivers the duplicated header as an array (reproduces the bug input)', $result['raw_is_array'] ?? false);
$check('no PHP warnings while building Loongs\\Http\\Request', $warnings === [], implode(' | ', $warnings));
$check('list header joined with ", "', ($result['x-dup'] ?? null) === 'a, b, c', var_export($result['x-dup'] ?? null, true));
$check('single-value header delivered as array (referer) keeps the last value', ($result['referer'] ?? null) === 'http://b/', var_export($result['referer'] ?? null, true));
$check('authorization: last value (Swoole collapses it itself; same rule)', ($result['authorization'] ?? null) === 'Bearer second', var_export($result['authorization'] ?? null, true));
$check('other list header joined (x-render-schema)', ($result['schema'] ?? null) === '1, 0', var_export($result['schema'] ?? null, true));
$check('normal header untouched', ($result['accept'] ?? null) === 'application/json');
$check('headers() is array<string,string>', ($result['headers'] ?? []) !== [] && array_filter($result['headers'] ?? [], 'is_string') === ($result['headers'] ?? []));
$check('headerValues() returns every raw value in order', ($result['values'] ?? null) === ['a', 'b', 'c'], json_encode($result['values'] ?? null));

if (method_exists(Request::class, 'normalizeHeaders')) {
    [$h, $v] = Request::normalizeHeaders(['X-A' => ['1', 2], 'HOST' => ['a', 'b'], 'Cookie' => ['a=1', 'b=2'], 'x-empty' => '', 'x-bad' => [['nested']]]);
    $check('normalizeHeaders: lower-case names, list joined, single-value last, cookie "; ", empty kept', $h === ['x-a' => '1, 2', 'host' => 'b', 'cookie' => 'a=1; b=2', 'x-empty' => ''] && $v['x-a'] === ['1', '2'], json_encode($h));
    $check('normalizeHeaders: non-scalar items are ignored', !isset($h['x-bad']));
}

echo "== $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
