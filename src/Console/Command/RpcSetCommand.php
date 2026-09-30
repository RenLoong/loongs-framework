<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Rpc\Exception\RpcException;
use Loongs\Rpc\HotReload\RpcServiceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpc:set', description: 'Replace a whole service config (instances, weights, metadata…) from JSON')]
final class RpcSetCommand extends RpcCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::REQUIRED, 'Service name')
            ->addArgument('json', InputArgument::REQUIRED, 'Service config as a JSON object')
            ->setHelp(<<<'TXT'
  <info>./start rpc:set user '{"transport":"remote","instances":[{"endpoint":"http://10.0.0.1:9502","weight":1},{"endpoint":"http://10.0.0.2:9502","weight":3}]}'</info>
TXT);
    }

    protected function handle(InputInterface $input, OutputInterface $output, RpcServiceManager $rpc): int
    {
        $service = (string) $input->getArgument('service');
        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $input->getArgument('json'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw RpcException::badRequest('Invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw RpcException::badRequest('Service config must be a JSON object.');
        }
        /** @var array<string, mixed> $decoded */
        $this->applied($rpc, $service, $rpc->set($service, $decoded));

        return self::SUCCESS;
    }
}
