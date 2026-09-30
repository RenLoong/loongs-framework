<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Rpc\HotReload\RpcServiceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpc:switch', description: 'Hot-switch a service to local | loopback | remote (no restart)')]
final class RpcSwitchCommand extends RpcCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::REQUIRED, 'Service name from config/rpc.php (e.g. user)')
            ->addArgument('transport', InputArgument::REQUIRED, 'local | loopback | remote')
            ->addArgument('endpoint', InputArgument::OPTIONAL, 'http(s)://host:port — required for remote; loopback defaults to http://127.0.0.1:$RPC_PORT')
            ->setHelp(<<<'TXT'
Keeps timeout_ms / metadata of the current entry. Strictly validated before the atomic write;
running workers pick it up within rpc.hot_reload.interval_ms.

  <info>./loongs rpc:switch user loopback</info>
  <info>./loongs rpc:switch user remote http://10.0.0.12:9502</info>
  <info>./loongs rpc:switch user local</info>
TXT);
    }

    protected function handle(InputInterface $input, OutputInterface $output, RpcServiceManager $rpc): int
    {
        $service = (string) $input->getArgument('service');
        $endpoint = $input->getArgument('endpoint');
        $config = $rpc->switch($service, (string) $input->getArgument('transport'), is_string($endpoint) ? $endpoint : null);
        $this->applied($rpc, $service, $config);

        return self::SUCCESS;
    }
}
