<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'status', description: 'Show master state and the process table (exit 1 when stopped)')]
final class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Mark only these processes as selected (same syntax as start --only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->io($input, $output);
        $manager = $this->processManager(StartCommand::parseOnly($input->getOption('only')));
        $r = $manager->statusReport();

        $io->title('Loongs status');
        $io->writeln(sprintf('  app: <options=bold>%s</>  <fg=gray>(process titles %s: …)</>', $r['app_name'], $r['title_prefix']));
        if ($r['running']) {
            $io->writeln(sprintf('  master: <info>running</info>  pid=<comment>%d</comment>', $r['master_pid']));
            foreach ($r['notes'] ?? [] as $note) {
                $io->writeln('  <comment>note</comment>: ' . $note);
            }
        } else {
            $io->writeln('  master: <fg=red;options=bold>stopped</>');
            if ($r['orphans'] !== []) {
                $io->writeln(sprintf(
                    '  <fg=red;options=bold>orphaned</>: the master is gone but %d process(es) are still alive: %s',
                    count($r['orphans']),
                    implode(', ', array_map(static fn (array $o): string => sprintf('pid %d %s', $o['pid'], $o['title']), $r['orphans'])),
                ));
                $io->writeln('  → run <comment>./loongs stop</comment> to clean them up');
            }
        }
        $io->writeln(sprintf('  pid file: %s', $r['pid_file']));
        if ($output->isVerbose()) {
            $io->writeln(sprintf('  log file: %s', $r['log_file']));
            $io->writeln(sprintf('  daemonize: %s', $r['daemonize'] ? 'true' : 'false'));
        }
        $io->newLine();

        $rows = [];
        foreach ($r['processes'] as $p) {
            $state = match ($p['state']) {
                'running' => '<info>running</info>',
                'stopped' => '<comment>stopped</comment>',
                'not running' => '<error>not running</error>',
                'orphaned' => '<fg=red;options=bold>orphaned</>',
                default => '<fg=gray>disabled</>',
            };
            $rows[] = [
                $p['selected'] ? $p['name'] : '<fg=gray>' . $p['name'] . '</>',
                $p['type'],
                $p['app'],
                $p['listen'] ?? '-',
                $p['count'] . ($p['workers'] !== null ? ' × ' . $p['workers'] . 'w' : ''),
                $p['pids'] === [] ? '-' : implode(',', $p['pids']),
                $state,
            ];
        }
        if ($rows === []) {
            $io->warning('No processes configured (config/process.php).');
        } else {
            $io->table(['process', 'type', 'app', 'listen', 'count', 'pid', 'state'], $rows);
        }

        return $r['running'] ? self::SUCCESS : self::FAILURE;
    }
}
