<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Rpc\HotReload\RpcServiceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpc:show', description: 'Show effective rpc.services (source config|override) and override file state')]
final class RpcShowCommand extends RpcCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::OPTIONAL, 'Only this service')
            ->setHelp('Exit code 1 when the override file or the effective map is invalid (workers keep their previous map).');
    }

    protected function handle(InputInterface $input, OutputInterface $output, RpcServiceManager $rpc): int
    {
        $service = $input->getArgument('service');
        $state = $rpc->show(is_string($service) && $service !== '' ? $service : null);
        $io = $this->style;

        $io->title('RPC services');
        $o = $state['override_file'];
        $overrides = count(array_filter($state['services'], static fn (array $s): bool => $s['source'] === 'override'));
        $io->definitionList(
            ['hot reload' => $state['hot_reload']['enabled'] ? sprintf('<info>enabled</info>, every %d ms', $state['hot_reload']['interval_ms']) : '<comment>disabled</comment>'],
            ['config file' => $state['config_file']],
            ['override file' => $o['path'] . '  ' . match ($o['state']) {
                'valid' => sprintf('<info>valid</info> (%d override(s))', $overrides),
                'invalid' => '<error>INVALID</error>',
                default => '<fg=gray>none</>',
            }],
        );

        $this->renderServices($state);

        if ($o['state'] === 'invalid') {
            $io->error('Override file invalid — workers keep their previous map: ' . $o['error'] . "\nFix it or run: ./start rpc:reset --all");
        }
        if ($state['error'] !== null) {
            $io->error('Effective map invalid (workers would reject it): ' . $state['error']);
        }

        return $state['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
