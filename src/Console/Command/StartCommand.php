<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Loongs\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'start', description: 'Start the process manager (http / rpc / websocket / queue / crontab / custom)')]
class StartCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these processes (comma list; exact names or app wildcard like user.*)')
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in the background (overrides process.daemonize / PROCESS_DAEMONIZE)')
            ->setHelp(<<<'TXT'
Runs the master in the foreground until SIGTERM/SIGINT (<info>stop</info>), or in the background with <info>-d</info>.
Master/child log lines are plain text (no colours) so they stay readable in log files.

  <info>./start</info>                       same as <info>./start start</info>
  <info>./start start --only=http,rpc</info>
  <info>./start start --only='user.*' -d</info>
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->startServer($input, $output);
    }

    /**
     * Delegates to ProcessManager::start() (blocks until the master exits). Shared with restart.
     */
    protected function startServer(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->io($input, $output);
        $manager = $this->processManager(self::parseOnly($input->getOption('only')));
        if ($input->getOption('daemon')) {
            $manager->setDaemonize(true);
        }

        $report = $manager->statusReport();
        $enabled = array_values(array_filter($report['processes'], static fn (array $p): bool => $p['enabled'] && $p['selected']));
        if (!$report['running'] && $enabled !== []) {
            $io->writeln(sprintf(
                '<info>Loongs</info> starting <comment>%s</comment>%s  <fg=gray>(pid file %s)</>',
                implode(', ', array_map(static fn (array $p): string => $p['name'] . ($p['listen'] !== null ? '@' . $p['listen'] : ''), $enabled)),
                $manager->daemonize() ? ' <comment>[daemon]</comment>' : '',
                $report['pid_file'],
            ));
        }

        try {
            return $manager->start();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param mixed $raw  --only values (repeatable, comma separated)
     * @return list<string>|null
     */
    public static function parseOnly(mixed $raw): ?array
    {
        $names = [];
        foreach ((array) $raw as $chunk) {
            foreach (explode(',', (string) $chunk) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names === [] ? null : $names;
    }
}
