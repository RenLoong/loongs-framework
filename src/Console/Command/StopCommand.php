<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Console\Command;
use Loongs\Process\ProcessManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'stop', description: 'Stop the running master (SIGTERM, SIGKILL after 30s)')]
final class StopCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::stopServer($this->processManager(), $this->io($input, $output));
    }

    public static function stopServer(ProcessManager $manager, SymfonyStyle $io): int
    {
        return $manager->stop(static function (string $event, array $ctx) use ($io): void {
            match ($event) {
                'not_running' => $io->note('Not running.'),
                'stopping' => $io->writeln(sprintf('Stopping master pid=<comment>%d</comment> ...', $ctx['pid'])),
                'stopped' => $io->success(sprintf('Stopped (pid %d, %.2fs).', $ctx['pid'], $ctx['seconds'])),
                'timeout' => $io->error(sprintf('Stop timed out; sent SIGKILL to pid %d.', $ctx['pid'])),
                default => null,
            };
        });
    }
}
