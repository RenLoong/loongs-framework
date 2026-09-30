<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Rpc\HotReload\RpcServiceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rpc:reset', description: 'Drop runtime override(s) → back to config/rpc.php')]
final class RpcResetCommand extends RpcCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::OPTIONAL, 'Service to reset')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Remove every override (also repairs an invalid override file)');
    }

    protected function handle(InputInterface $input, OutputInterface $output, RpcServiceManager $rpc): int
    {
        $service = $input->getArgument('service');
        if ($input->getOption('all') || $service === '--all') {
            $rpc->resetAll();
            $this->style->success('All runtime overrides removed → config/rpc.php services.');
            $this->renderServices($rpc->show());
            $this->hint($rpc);

            return self::SUCCESS;
        }
        if (!is_string($service) || trim($service) === '') {
            $this->style->error('rpc:reset needs <service> or --all.');

            return self::FAILURE;
        }
        if (!$rpc->reset($service)) {
            $this->style->note(sprintf('[%s] had no runtime override (nothing to do).', trim($service)));

            return self::SUCCESS;
        }
        $this->style->success(sprintf('[%s] → config/rpc.php', trim($service)));
        $this->renderServices($rpc->show(trim($service)));
        $this->hint($rpc);

        return self::SUCCESS;
    }
}
