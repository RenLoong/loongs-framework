<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Console\Command;
use Loongs\Rpc\Exception\RpcException;
use Loongs\Rpc\HotReload\RpcServiceManager;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared rendering for rpc:* (logic lives in RpcServiceManager — same code path as rpc_services()).
 * Validation errors (RpcException) → error block, exit 1, override file untouched.
 */
abstract class RpcCommand extends Command
{
    protected ?SymfonyStyle $style = null;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->style = $this->io($input, $output);
        try {
            return $this->handle($input, $output, $this->rpcServices());
        } catch (RpcException | \JsonException $e) {
            $this->style->error(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }
    }

    abstract protected function handle(InputInterface $input, OutputInterface $output, RpcServiceManager $rpc): int;

    /**
     * @param array<string, mixed> $config
     */
    protected function applied(RpcServiceManager $rpc, string $service, array $config): void
    {
        $this->style->success(sprintf('[%s] → %s', trim($service), json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $this->renderServices($rpc->show(trim($service)));
        $this->hint($rpc);
    }

    protected function hint(RpcServiceManager $rpc): void
    {
        $reloader = $rpc->reloader();
        $this->style->writeln(sprintf('  <fg=gray>override file:</> %s', $reloader->overrides()->path()));
        if ($reloader->enabled()) {
            $this->style->writeln(sprintf('  <fg=gray>running workers apply it within ~%d ms (no restart).</>', $reloader->intervalMs()));
        } else {
            $this->style->warning('rpc.hot_reload.enabled=false — takes effect on next start/reload.');
        }
    }

    /**
     * @param array<string, mixed> $state RpcServiceManager::show()
     */
    protected function renderServices(array $state): void
    {
        $rows = [];
        foreach ($state['services'] as $name => $svc) {
            $first = true;
            foreach ($svc['instances'] as $i => $inst) {
                $rows[] = [
                    $first ? '<info>' . $name . '</info>' : '',
                    $first ? ($svc['source'] === 'override' ? '<comment>override</comment>' : 'config') : '',
                    '#' . $i,
                    $inst['transport'],
                    $inst['endpoint'] ?? '-',
                    (string) $inst['weight'],
                    $inst['timeout_ms'] !== null ? (string) $inst['timeout_ms'] : '-',
                    $inst['metadata'] !== [] ? json_encode($inst['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '-',
                ];
                $first = false;
            }
        }
        $this->style->table(['service', 'source', '#', 'transport', 'endpoint', 'weight', 'timeout_ms', 'metadata'], $rows);
    }
}
