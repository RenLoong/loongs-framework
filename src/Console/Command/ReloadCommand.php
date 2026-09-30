<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'reload', description: 'Graceful reload: SIGUSR1 to the master; every child reloads with fresh code, in-flight requests finish')]
final class ReloadCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->io($input, $output);
        $pid = $this->processManager()->reload();
        if ($pid === null) {
            $io->error('Not running.');

            return self::FAILURE;
        }
        $io->success(sprintf('Reload signal (SIGUSR1) sent to master pid %d.', $pid));
        $io->writeln('  http / websocket: Swoole worker reload · rpc (uring): replacement, then the old one drains · queue / crontab / custom: graceful respawn. Progress: the master log (reload: …).');

        return self::SUCCESS;
    }
}
