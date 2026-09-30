<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Console\Command;
use Loongs\Process\ProcessManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'stop', description: 'Stop the running master (SIGTERM, SIGKILL after 30s); cleans up orphans of a dead master')]
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
                'timeout' => $io->error(sprintf('Stop timed out; sent SIGKILL to master pid %d and %d other process(es).', $ctx['pid'], max(0, count($ctx['killed'] ?? []) - 1))),
                'orphans' => $io->warning(sprintf(
                    'Master is gone; stopping %d orphaned process tree(s) (%d processes) with SIGTERM: %s',
                    count($ctx['processes']),
                    $ctx['count'],
                    implode(', ', array_map(static fn (array $o): string => sprintf('pid %d %s', $o['pid'], $o['title']), $ctx['processes'])),
                )),
                'orphans_stopped' => $io->success(sprintf('Orphaned processes stopped (%d, %.2fs).', $ctx['count'], $ctx['seconds'])),
                'orphans_killed' => $io->error(sprintf('Orphans ignored SIGTERM; sent SIGKILL to %s (%.2fs).', implode(',', $ctx['pids']), $ctx['seconds'])),
                default => null,
            };
        });
    }
}
