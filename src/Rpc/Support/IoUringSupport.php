<?php

declare(strict_types=1);

namespace Loongs\Rpc\Support;

/**
 * Detects / applies Swoole io_uring for the RPC stack.
 *
 * Swoole 6.2.2 source evidence (tag v6.2.2):
 * - include/swoole_socket_impl.h: when SW_USE_URING_SOCKET, SocketImpl = UringSocket
 *   else SocketImpl = CoSocket (compile-time; no runtime SWOOLE_SOCK_* switch).
 * - ext-src/swoole_http_server_coro.cc: Coroutine\Http\Server owns SocketImpl*
 *   (constructed with `new SocketImpl(type)` ~L79).
 * - ext-src/swoole_http_client_coro.cc / swoole_client_coro.cc / swoole_socket_coro.cc:
 *   all use SocketImpl → UringSocket when --enable-uring-socket.
 * - src/coroutine/uring_socket.cc: connect/accept/send/recv → Iouring::*
 * - Classic Swoole\Http\Server does NOT use SocketImpl / UringSocket (reactor/epoll).
 * - iouring_* settings via swoole_async_set (ext-src/swoole_async_coro.cc L62–72):
 *   iouring_entries / iouring_workers / iouring_flag (SWOOLE_IOURING_DEFAULT|SQPOLL).
 *
 * @see https://github.com/swoole/swoole-src/blob/v6.2.2/include/swoole_socket_impl.h
 * @see https://github.com/swoole/swoole-src/blob/v6.2.2/ext-src/swoole_http_server_coro.cc
 * @see https://github.com/swoole/docs/blob/main/public/zh-cn/coroutine/iouring.md
 */
final class IoUringSupport
{
    private static ?IoUringStatus $last = null;

    private static bool $logged = false;

    /**
     * @param array<string, mixed> $config rpc.iouring config slice
     */
    public static function bootstrap(array $config = []): IoUringStatus
    {
        $status = self::decide($config, apply: true);
        self::$last = $status;
        self::logStatusOnce($status);

        return $status;
    }

    /**
     * Probe without applying settings (CLI / smoke).
     *
     * @param array<string, mixed> $config
     */
    public static function probe(array $config = []): IoUringStatus
    {
        return self::decide($config, apply: false);
    }

    public static function lastStatus(): ?IoUringStatus
    {
        return self::$last;
    }

    /**
     * True when RPC should use Coroutine\Http\Server + Coroutine\Http\Client (UringSocket).
     */
    public static function usesNetworkUring(?IoUringStatus $status = null): bool
    {
        $status ??= self::$last;

        return $status !== null && $status->networkActive;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function mergeServerSettings(array $settings, ?IoUringStatus $status = null): array
    {
        $status ??= self::$last;
        if ($status === null || !$status->active || $status->appliedSettings === []) {
            return $settings;
        }

        return array_merge($settings, $status->appliedSettings);
    }

    public static function isFileIoUringCompiled(): bool
    {
        if (defined('SWOOLE_IOURING_DEFAULT') || defined('SWOOLE_IOURING_SQPOLL')) {
            return true;
        }

        return self::swooleInfoContains('io_uring');
    }

    public static function isUringSocketCompiled(): bool
    {
        return self::swooleInfoContains('uring_socket');
    }

    public static function isKernelIoUringAllowed(): bool
    {
        $path = '/proc/sys/kernel/io_uring_disabled';
        if (!is_readable($path)) {
            return \PHP_OS_FAMILY === 'Linux';
        }

        $raw = trim((string) @file_get_contents($path));

        return $raw === '0' || $raw === '';
    }

    /**
     * @return list<string>
     */
    public static function recompileHints(): array
    {
        return [
            '# Install liburing (apt example):',
            'sudo apt-get install -y liburing-dev pkg-config',
            '# Rebuild swoole 6.2.x:',
            'cd /tmp && pecl download swoole-6.2.2 && tar xf swoole-6.2.2.tgz && cd swoole-6.2.2',
            '/www/server/php/84/bin/phpize',
            './configure --enable-openssl --enable-sockets --enable-swoole-curl --enable-cares --enable-iouring --enable-uring-socket',
            'make -j"$(nproc)" && sudo make install',
            '# php --ri swoole must show io_uring + uring_socket => enabled',
            '# Then restart loong-swoole so RpcProcess picks Coroutine\\Http\\Server.',
        ];
    }

    public static function logStatusOnce(IoUringStatus $status): void
    {
        if (self::$logged) {
            return;
        }
        self::$logged = true;
        echo sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $status->statusLine());
    }

    /** @internal test helper */
    public static function resetLogFlag(): void
    {
        self::$logged = false;
        self::$last = null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function decide(array $config, bool $apply): IoUringStatus
    {
        $mode = IoUringMode::fromConfig($config['mode'] ?? 'auto');
        $fileSupported = self::isFileIoUringCompiled();
        $socketSupported = self::isUringSocketCompiled();
        $kernelAllowed = self::isKernelIoUringAllowed();

        $wanted = $mode !== IoUringMode::Off;

        // File ring settings require --enable-iouring + kernel.
        $fileActive = $wanted && $fileSupported && $kernelAllowed;
        // Network UringSocket requires --enable-uring-socket (+ iouring) + kernel.
        $networkActive = $wanted && $socketSupported && $kernelAllowed && $fileSupported;

        $settings = [];
        if ($fileActive) {
            $settings = self::buildSettings($config);
            // Do NOT call swoole_async_set here — it fatals once any event-loop exists
            // (queue/crontab/HTTP workers). RpcProcess applies tunables explicitly
            // before Coroutine\Http\Server starts via applyFileTunables().
        }

        if ($networkActive) {
            $backend = 'uring_socket';
            $networkBackend = 'uring_socket';
            $coverage = $fileActive ? 'file+network' : 'network';
            $active = true;
        } elseif ($fileActive) {
            $backend = 'io_uring(file)';
            $networkBackend = 'curl';
            $coverage = 'file';
            $active = true;
        } else {
            $backend = 'epoll';
            $networkBackend = 'curl';
            $coverage = 'none';
            $active = false;
        }

        return new IoUringStatus(
            mode: $mode,
            wanted: $wanted,
            active: $active,
            fileSupported: $fileSupported,
            socketSupported: $socketSupported,
            kernelAllowed: $kernelAllowed,
            networkActive: $networkActive,
            backend: $backend,
            networkBackend: $networkBackend,
            coverage: $coverage,
            reason: self::describeReason(
                mode: $mode,
                fileActive: $fileActive,
                networkActive: $networkActive,
                fileSupported: $fileSupported,
                socketSupported: $socketSupported,
                kernelAllowed: $kernelAllowed,
            ),
            appliedSettings: $settings,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, int>
     */
    private static function buildSettings(array $config): array
    {
        $entries = (int) ($config['entries'] ?? $config['iouring_entries'] ?? 8192);
        $workers = (int) ($config['workers'] ?? $config['iouring_workers'] ?? 0);
        $flagName = strtoupper((string) ($config['flag'] ?? $config['iouring_flag'] ?? 'default'));

        $flag = 0;
        if ($flagName === 'SQPOLL' || $flagName === 'SWOOLE_IOURING_SQPOLL' || $flagName === 'IORING_SETUP_SQPOLL') {
            $flag = defined('SWOOLE_IOURING_SQPOLL') ? (int) constant('SWOOLE_IOURING_SQPOLL') : 2;
        } elseif (defined('SWOOLE_IOURING_DEFAULT')) {
            $flag = (int) constant('SWOOLE_IOURING_DEFAULT');
        }

        $out = [
            'iouring_entries' => max(1, $entries),
            'iouring_flag' => $flag,
        ];

        if ($workers > 0) {
            $out['iouring_workers'] = $workers;
        }

        return $out;
    }

    /**
     * @param array<string, int> $settings
     */
    /**
     * Apply iouring_* file-AIO tunables. Safe to call only BEFORE any Swoole
     * event-loop / Coroutine\\run. RpcProcess calls this once at start.
     *
     * @param array<string, mixed> $config
     */
    public static function applyFileTunables(array $config = []): void
    {
        if (!self::isFileIoUringCompiled() || !self::isKernelIoUringAllowed()) {
            return;
        }

        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() >= 0) {
            return;
        }

        if (class_exists(\Swoole\Event::class) && method_exists(\Swoole\Event::class, 'isset')) {
            try {
                if (\Swoole\Event::isset()) {
                    return;
                }
            } catch (\Throwable) {
            }
        }

        $settings = self::buildSettings($config);
        if ($settings === []) {
            return;
        }

        if (function_exists('swoole_async_set')) {
            swoole_async_set($settings);
        } elseif (method_exists(\Swoole\Coroutine::class, 'set')) {
            \Swoole\Coroutine::set($settings);
        }
    }

    private static function applySettings(array $settings): void
    {
        // Deprecated path — kept as no-op so old call sites cannot fatal.
        unset($settings);
    }

    private static function swooleInfoContains(string $needle): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }

        ob_start();
        try {
            $ext = new \ReflectionExtension('swoole');
            $ext->info();
        } catch (\Throwable) {
            ob_end_clean();

            return false;
        }
        $info = (string) ob_get_clean();

        return $info !== '' && stripos($info, $needle) !== false;
    }

    private static function describeReason(
        IoUringMode $mode,
        bool $fileActive,
        bool $networkActive,
        bool $fileSupported,
        bool $socketSupported,
        bool $kernelAllowed,
    ): string {
        if ($mode === IoUringMode::Off) {
            return 'RPC_IOURING=off; classic Swoole\\Http\\Server + curl/epoll';
        }

        if (!$fileSupported) {
            return 'Swoole build lacks --enable-iouring; fallback epoll/curl';
        }

        if (!$kernelAllowed) {
            return 'kernel io_uring_disabled!=0; fallback epoll/curl';
        }

        if ($networkActive) {
            return 'Coroutine\\Http\\Server + Coroutine\\Http\\Client use UringSocket (compile-time SocketImpl)';
        }

        if ($fileActive && !$socketSupported) {
            return 'file io_uring on; uring_socket not compiled — RPC network remains Http\\Server + curl';
        }

        if ($fileActive) {
            return 'file io_uring settings applied';
        }

        return 'io_uring not activated; using epoll/curl';
    }
}
