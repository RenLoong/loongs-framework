<?php

declare(strict_types=1);

namespace Loongs\Rpc\Console;

use Loongs\Rpc\HotReload\RpcServiceManager;
use Throwable;

/**
 * `start rpc:*` — inspect / hot-switch rpc.services without restarting the server.
 *
 *   rpc:show   [service]                         effective config per service (+ override state)
 *   rpc:switch <service> <local|loopback|remote> [endpoint]
 *   rpc:set    <service> '<json service config>' (full config: instances, weights, metadata…)
 *   rpc:reset  <service>|--all                   drop runtime override(s) → back to config/rpc.php
 *
 * Thin renderer over RpcServiceManager (the same code API apps call via rpc_services()),
 * so validation + atomic override-file writes have exactly one code path. Running workers
 * pick CLI writes up within rpc.hot_reload.interval_ms.
 */
final class RpcServiceCommand
{
    private readonly string $basePath;

    private ?RpcServiceManager $manager = null;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct(string $basePath, $out = null, $err = null, ?RpcServiceManager $manager = null)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->manager = $manager;
    }

    public static function handles(string $command): bool
    {
        return str_starts_with($command, 'rpc:');
    }

    /**
     * @param list<string> $args  argv after the script name, first item = rpc:<cmd>
     */
    public function run(array $args): int
    {
        $command = (string) array_shift($args);

        try {
            return match ($command) {
                'rpc:show' => $this->show($args),
                'rpc:switch' => $this->switch($args),
                'rpc:set' => $this->set($args),
                'rpc:reset' => $this->reset($args),
                'rpc:help' => $this->usage(0),
                default => $this->usage(1, "Unknown command: {$command}"),
            };
        } catch (Throwable $e) {
            $this->error('ERROR: ' . $e->getMessage());

            return 1;
        }
    }

    /** @param list<string> $args */
    private function show(array $args): int
    {
        $only = $args[0] ?? null;
        $state = $this->manager()->show($only);

        $this->line(sprintf(
            'hot_reload: enabled=%s interval_ms=%d',
            $state['hot_reload']['enabled'] ? 'true' : 'false',
            $state['hot_reload']['interval_ms'],
        ));
        $this->line('config_file: ' . $state['config_file']);

        $o = $state['override_file'];
        $overrideCount = count(array_filter($state['services'], static fn (array $s): bool => $s['source'] === 'override'));
        $label = match ($o['state']) {
            'valid' => $only === null ? sprintf('valid (%d service(s))', $overrideCount) : 'valid',
            'invalid' => 'INVALID — workers keep their previous map: ' . $o['error'],
            default => 'missing',
        };
        $this->line('override_file: ' . $o['path'] . ' [' . $label . ']');
        if ($state['error'] !== null) {
            $this->line('effective: INVALID (workers would reject it): ' . $state['error']);
        }

        $this->line('services:');
        foreach ($state['services'] as $name => $svc) {
            $this->line(sprintf('  %s  source=%s', $name, $svc['source']));
            $this->line('    config: ' . json_encode($svc['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            foreach ($svc['instances'] as $i => $instance) {
                $this->line('    #' . $i . ' ' . $this->describeInstance($instance));
            }
        }

        return $state['valid'] ? 0 : 1;
    }

    /** @param list<string> $args */
    private function switch(array $args): int
    {
        if (count($args) < 2) {
            return $this->usage(1, 'rpc:switch needs <service> <local|loopback|remote> [endpoint]');
        }
        $config = $this->manager()->switch($args[0], $args[1], $args[2] ?? null);
        $this->applied($args[0], $config);

        return 0;
    }

    /** @param list<string> $args */
    private function set(array $args): int
    {
        if (count($args) < 2) {
            return $this->usage(1, "rpc:set needs <service> '<json>'");
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($args[1], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('ERROR: Invalid JSON: ' . $e->getMessage());

            return 1;
        }
        if (!is_array($decoded)) {
            $this->error('ERROR: Service config must be a JSON object.');

            return 1;
        }

        /** @var array<string, mixed> $decoded */
        $config = $this->manager()->set($args[0], $decoded);
        $this->applied($args[0], $config);

        return 0;
    }

    /** @param list<string> $args */
    private function reset(array $args): int
    {
        $target = $args[0] ?? null;
        if ($target === null || $target === '') {
            return $this->usage(1, 'rpc:reset needs <service> or --all');
        }

        if ($target === '--all') {
            $this->manager()->resetAll();
            $this->line('reset: all runtime overrides removed → config/rpc.php services');
            $this->hint();

            return 0;
        }

        if ($this->manager()->reset($target)) {
            $this->line("reset: [{$target}] → config/rpc.php");
            $this->hint();
        } else {
            $this->line("reset: [{$target}] had no runtime override (nothing to do)");
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applied(string $service, array $config): void
    {
        $this->line(sprintf(
            'override: [%s] = %s',
            trim($service),
            json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
        $this->line('file: ' . $this->manager()->reloader()->overrides()->path());
        $this->hint();
    }

    private function hint(): void
    {
        $reloader = $this->manager()->reloader();
        if ($reloader->enabled()) {
            $this->line(sprintf('running workers apply it within ~%d ms (no restart).', $reloader->intervalMs()));
        } else {
            $this->line('NOTE: rpc.hot_reload.enabled=false — takes effect on next start/reload.');
        }
    }

    /**
     * @param array<string, mixed> $i
     */
    private function describeInstance(array $i): string
    {
        return sprintf(
            'transport=%s endpoint=%s weight=%d timeout_ms=%s%s',
            (string) $i['transport'],
            $i['endpoint'] ?? '-',
            (int) $i['weight'],
            $i['timeout_ms'] !== null ? (string) $i['timeout_ms'] : '-',
            $i['metadata'] !== [] ? ' metadata=' . json_encode($i['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        );
    }

    private function manager(): RpcServiceManager
    {
        return $this->manager ??= RpcServiceManager::fromBasePath($this->basePath);
    }

    private function usage(int $code, string $message = ''): int
    {
        if ($message !== '') {
            $this->error($message);
        }
        $text = <<<TXT
Usage:
  ./start rpc:show   [service]
  ./start rpc:switch <service> <local|loopback|remote> [endpoint]
  ./start rpc:set    <service> '<json service config>'
  ./start rpc:reset  <service>|--all
TXT;
        $code === 0 ? $this->line($text) : $this->error($text);

        return $code;
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    private function error(string $text): void
    {
        fwrite($this->err, $text . "\n");
    }
}
