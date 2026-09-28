<?php

declare(strict_types=1);

namespace Loongs\Rpc\Console;

use Loongs\Config\Repository;
use Loongs\Rpc\Discovery\ConfigServiceDiscovery;
use Loongs\Rpc\Discovery\ServiceInstance;
use Loongs\Rpc\HotReload\RpcServiceReloader;
use Loongs\Support\BasePath;
use Loongs\Support\Env;
use Throwable;

/**
 * `start rpc:*` — inspect / hot-switch rpc.services without restarting the server.
 *
 *   rpc:show   [service]                         effective config per service (+ override state)
 *   rpc:switch <service> <local|loopback|remote> [endpoint]
 *   rpc:set    <service> '<json service config>' (full config: instances, weights, metadata…)
 *   rpc:reset  <service>|--all                   drop runtime override(s) → back to config/rpc.php
 *
 * Writes go to rpc.hot_reload.override_file atomically; running workers pick the change
 * up within rpc.hot_reload.interval_ms. Every write is validated with the same strict
 * parser the workers use, so an invalid switch is refused before it reaches the file.
 */
final class RpcServiceCommand
{
    private readonly string $basePath;

    private ?RpcServiceReloader $reloader = null;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct(string $basePath, $out = null, $err = null)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
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
        $reloader = $this->reloader();
        $overrides = $reloader->overrides();

        $this->line(sprintf(
            'hot_reload: enabled=%s interval_ms=%d',
            $reloader->enabled() ? 'true' : 'false',
            $reloader->intervalMs(),
        ));
        $this->line('config_file: ' . $reloader->configFile());

        $overrideState = 'missing';
        $overrideOk = true;
        $overrideServices = [];
        if ($overrides->exists()) {
            try {
                $overrideServices = $overrides->services();
                $overrideState = sprintf('valid (%d service(s))', count($overrideServices));
            } catch (Throwable $e) {
                $overrideOk = false;
                $overrideState = 'INVALID — workers keep their previous map: ' . $e->getMessage();
            }
        }
        $this->line('override_file: ' . $overrides->path() . ' [' . $overrideState . ']');

        $configServices = $reloader->configServices();
        $services = $configServices;
        $sources = array_fill_keys(array_map('strval', array_keys($configServices)), 'config');
        foreach ($overrideServices as $name => $cfg) {
            $services[$name] = $cfg;
            $sources[$name] = 'override';
        }

        try {
            $map = ConfigServiceDiscovery::parseServices($services, true);
            $valid = true;
        } catch (Throwable $e) {
            $map = ConfigServiceDiscovery::parseServices($services, false);
            $valid = false;
            $this->line('effective: INVALID (workers would reject it): ' . $e->getMessage());
        }

        $this->line('services:');
        foreach ($map as $name => $instances) {
            if ($only !== null && $only !== $name) {
                continue;
            }
            $this->line(sprintf('  %s  source=%s', $name, $sources[$name] ?? 'config'));
            $this->line('    config: ' . json_encode($services[$name], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            foreach ($instances as $i => $instance) {
                $this->line('    #' . $i . ' ' . $this->describeInstance($instance));
            }
        }
        if ($only !== null && !isset($map[$only])) {
            $this->error("Service [{$only}] is not defined.");

            return 1;
        }

        return ($overrideOk && $valid) ? 0 : 1;
    }

    /** @param list<string> $args */
    private function switch(array $args): int
    {
        if (count($args) < 2) {
            return $this->usage(1, 'rpc:switch needs <service> <local|loopback|remote> [endpoint]');
        }
        [$service, $transport] = [$args[0], strtolower($args[1])];
        $endpoint = isset($args[2]) && $args[2] !== '' ? $args[2] : null;

        if (!in_array($transport, ['local', 'loopback', 'remote'], true)) {
            $this->error("Unknown transport [{$transport}] (expected local|loopback|remote).");

            return 1;
        }

        $reloader = $this->reloader();
        $effective = $reloader->effective();
        if (!array_key_exists($service, $effective['services'])) {
            $this->error("Service [{$service}] is not defined in config/rpc.php or overrides (use rpc:set to add one).");

            return 1;
        }

        /** @var mixed $current */
        $current = $effective['services'][$service];
        $current = is_array($current) ? $current : [];

        $new = ['transport' => $transport];
        foreach (['timeout_ms', 'metadata'] as $keep) {
            if (array_key_exists($keep, $current)) {
                $new[$keep] = $current[$keep];
            }
        }

        if ($transport === 'loopback' && $endpoint === null) {
            $endpoint = 'http://127.0.0.1:' . (int) Env::get('RPC_PORT', 9502);
        }
        if ($transport === 'remote' && $endpoint === null) {
            $this->error('rpc:switch <service> remote requires an endpoint, e.g. http://10.0.0.12:9502');

            return 1;
        }
        if ($transport !== 'local' && $endpoint !== null) {
            $new['endpoint'] = $endpoint;
        }

        ConfigServiceDiscovery::parseServices([$service => $new], true);
        $reloader->overrides()->set($service, $new);
        $this->applied($service, $new);

        return 0;
    }

    /** @param list<string> $args */
    private function set(array $args): int
    {
        if (count($args) < 2) {
            return $this->usage(1, "rpc:set needs <service> '<json>'");
        }
        $service = $args[0];
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($args[1], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('Invalid JSON: ' . $e->getMessage());

            return 1;
        }
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            $this->error('Service config must be a JSON object.');

            return 1;
        }

        /** @var array<string, mixed> $decoded */
        ConfigServiceDiscovery::parseServices([$service => $decoded], true);
        $this->reloader()->overrides()->set($service, $decoded);
        $this->applied($service, $decoded);

        return 0;
    }

    /** @param list<string> $args */
    private function reset(array $args): int
    {
        $target = $args[0] ?? null;
        if ($target === null || $target === '') {
            return $this->usage(1, 'rpc:reset needs <service> or --all');
        }

        $overrides = $this->reloader()->overrides();
        if ($target === '--all') {
            $overrides->resetAll();
            $this->line('reset: all runtime overrides removed → config/rpc.php services');
            $this->hint();

            return 0;
        }

        if ($overrides->reset($target)) {
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
            $service,
            json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
        $this->line('file: ' . $this->reloader()->overrides()->path());
        $this->hint();
    }

    private function hint(): void
    {
        $reloader = $this->reloader();
        if ($reloader->enabled()) {
            $this->line(sprintf('running workers apply it within ~%d ms (no restart).', $reloader->intervalMs()));
        } else {
            $this->line('NOTE: rpc.hot_reload.enabled=false — takes effect on next start/reload.');
        }
    }

    private function describeInstance(ServiceInstance $i): string
    {
        return sprintf(
            'transport=%s endpoint=%s weight=%d timeout_ms=%s%s',
            $i->transport,
            $i->endpoint ?? '-',
            $i->weight,
            $i->timeoutMs !== null ? (string) $i->timeoutMs : '-',
            $i->metadata !== [] ? ' metadata=' . json_encode($i->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        );
    }

    private function reloader(): RpcServiceReloader
    {
        if ($this->reloader === null) {
            BasePath::set($this->basePath);
            Env::load($this->basePath . '/.env');
            $config = new Repository($this->basePath . '/config');
            $this->reloader = RpcServiceReloader::fromConfig($config, $this->basePath, new ConfigServiceDiscovery());
        }

        return $this->reloader;
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
