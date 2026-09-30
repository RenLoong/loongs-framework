<?php

declare(strict_types=1);

namespace Loongs\Process;

/**
 * Runtime log lines for the master and every child, one consistent format:
 *
 *   [2026-09-30 11:15:56] INFO  [master] spawned http#0 type=http pid=422459
 *   [2026-09-30 11:15:56] INFO  [rpc#0]  RPC io_uring backend=uring_socket (active) ...
 *
 * - Tag = "master" in the supervisor, "<name>#<index>" in a child (set by ProcessManager::runChild()).
 * - Colours only when enabled (StartCommand: stdout is a TTY, no --no-ansi, not daemon). The flag is
 *   decided in the master before forking, so every child inherits it; log files / pipes stay plain.
 * - WARN / ERROR go to STDERR, the rest to STDOUT (daemon mode points both at process.log_file).
 */
final class ProcessLog
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARN = 'WARN';
    public const ERROR = 'ERROR';

    private const COLORS = [
        self::DEBUG => '90',
        self::INFO => '32',
        self::WARN => '33',
        self::ERROR => '1;31',
    ];

    private static bool $ansi = false;

    private static string $tag = 'master';

    private static int $tagWidth = 6;

    public static function setAnsi(bool $ansi): void
    {
        self::$ansi = $ansi;
    }

    public static function ansi(): bool
    {
        return self::$ansi;
    }

    public static function setTag(string $tag): void
    {
        self::$tag = $tag;
    }

    public static function tag(): string
    {
        return self::$tag;
    }

    /** Pad tags to this width so messages line up (master sets it from the longest child tag). */
    public static function setTagWidth(int $width): void
    {
        self::$tagWidth = max(1, min(24, $width));
    }

    public static function debug(string $message, ?string $tag = null): void
    {
        self::write(self::DEBUG, $message, $tag);
    }

    public static function info(string $message, ?string $tag = null): void
    {
        self::write(self::INFO, $message, $tag);
    }

    public static function warn(string $message, ?string $tag = null): void
    {
        self::write(self::WARN, $message, $tag);
    }

    public static function error(string $message, ?string $tag = null): void
    {
        self::write(self::ERROR, $message, $tag);
    }

    public static function write(string $level, string $message, ?string $tag = null): void
    {
        $stream = $level === self::WARN || $level === self::ERROR ? \STDERR : \STDOUT;
        @fwrite($stream, self::format($level, $message, $tag) . "\n");
    }

    public static function format(string $level, string $message, ?string $tag = null, ?bool $ansi = null): string
    {
        $ansi ??= self::$ansi;
        $tag ??= self::$tag;
        $time = '[' . date('Y-m-d H:i:s') . ']';
        $lvl = str_pad($level, 5);
        $tagText = str_pad('[' . $tag . ']', self::$tagWidth + 2);

        if (!$ansi) {
            return $time . ' ' . $lvl . ' ' . $tagText . ' ' . $message;
        }

        $tagColor = $tag === 'master' ? '35' : '36';
        // Dim "key=" prefixes so values stand out.
        $msg = (string) preg_replace('/(?<![\w\\\\-])([a-z_][a-z_-]*)=/', "\e[90m\$1=\e[0m", $message);

        return "\e[90m{$time}\e[0m \e[" . (self::COLORS[$level] ?? '0') . "m{$lvl}\e[0m \e[{$tagColor}m" . rtrim($tagText) . "\e[0m"
            . substr($tagText, strlen(rtrim($tagText))) . ' ' . $msg;
    }

    /** "12ms" / "1.24s" / "3m05s" */
    public static function duration(float $seconds): string
    {
        if ($seconds < 1) {
            return max(0, (int) round($seconds * 1000)) . 'ms';
        }
        if ($seconds < 60) {
            return sprintf('%.2fs', $seconds);
        }
        if ($seconds < 3600) {
            return sprintf('%dm%02ds', intdiv((int) $seconds, 60), (int) $seconds % 60);
        }

        return sprintf('%dh%02dm', intdiv((int) $seconds, 3600), intdiv((int) $seconds % 3600, 60));
    }
}
