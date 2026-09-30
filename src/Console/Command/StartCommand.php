<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Composer\InstalledVersions;
use Loongs\Console\Command;
use Loongs\Console\Kernel;
use Loongs\Process\ProcessLog;
use Loongs\Process\ProcessManager;
use Loongs\Rpc\Support\IoUringStatus;
use Loongs\Rpc\Support\IoUringSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'start', description: 'Start the process manager (http / rpc / websocket / queue / crontab / custom)')]
class StartCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these processes (comma list; exact names or app wildcard like user.*)')
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in the background (overrides process.daemonize / PROCESS_DAEMONIZE)')
            ->setHelp(<<<'TXT'
Prints a banner (framework / app / versions / io_uring / paths) and, once the children are up,
a process table (pid, state, backend). Runs the master in the foreground until SIGTERM/SIGINT
(<info>stop</info> or Ctrl+C), or in the background with <info>-d</info> (output → process.log_file).

Runtime log lines: <comment>[time] LEVEL [tag] message</comment> (tag = master | <name>#<index>).
Colours only on a TTY; <info>--no-ansi</info>, pipes, log files and daemon mode get plain text.
<info>-q</info> hides the banner/table (runtime log lines still print).

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
        $daemon = $manager->daemonize();
        // Decided here (before fork) so every child inherits it.
        ProcessLog::setAnsi($output->isDecorated() && !$daemon);

        $iouring = $this->probeIoUring($manager);
        $report = $manager->statusReport();
        $planned = array_values(array_filter($report['processes'], static fn (array $p): bool => $p['enabled'] && $p['selected']));
        if (!$report['running'] && $report['orphans'] === [] && $planned !== []) {
            $this->renderBanner($io, $manager, $iouring, $daemon);
            if ($daemon) {
                $rows = [];
                foreach ($planned as $p) {
                    for ($i = 0; $i < $p['count']; $i++) {
                        $rows[] = ['name' => $p['name'], 'index' => $i, 'type' => $p['type'], 'app' => $p['app'], 'pid' => 0,
                            'listen' => $p['listen'], 'workers' => $p['workers'], 'state' => 'starting'];
                    }
                }
                $this->renderProcesses($io, $rows, $iouring);
                $io->writeln(sprintf(
                    ' <info>Running in the background.</info> Logs: <comment>%s</comment> · <comment>./start status</comment> · <comment>./start stop</comment>',
                    OutputFormatter::escape($this->relative($manager->logFile())),
                ));
                $io->newLine();
            }
        }

        if (!$daemon) {
            $manager->onStarted(function (array $rows) use ($io, $iouring): void {
                $this->renderProcesses($io, $rows, $iouring);
            });
        }

        try {
            return $manager->start();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function renderBanner(SymfonyStyle $io, ProcessManager $manager, ?IoUringStatus $iouring, bool $daemon): void
    {
        $config = $manager->config();
        $version = Kernel::frameworkVersion();
        $appName = trim((string) $config->get('app.name', ''));
        if ($appName === '') {
            $appName = self::rootPackageName();
        }
        $env = (string) $config->get('app.env', 'production');
        $debug = filter_var($config->get('app.debug', false), FILTER_VALIDATE_BOOLEAN);

        $e = static fn (string $v): string => OutputFormatter::escape($v);

        $io->newLine();
        $io->writeln(sprintf(' <fg=green;options=bold>%s</> <fg=gray>%s</>  ·  <options=bold>%s</>', Kernel::NAME, $e($version), $e($appName)));
        $io->newLine();

        $lines = [
            ['Framework', sprintf('%s %s <fg=gray>(loongs/framework)</>', Kernel::NAME, $e($version))],
            ['App', sprintf('<options=bold>%s</>  env=%s  debug=%s', $e($appName), $e($env), $debug ? '<comment>on</comment>' : 'off')],
            ['Runtime', sprintf('PHP %s  ·  Swoole %s', PHP_VERSION, $e((string) (phpversion('swoole') ?: 'not loaded')))],
            ['io_uring', $this->ioUringLine($iouring, $io->isVerbose())],
            ['Base path', $e($this->basePath())],
            ['Pid file', $e($this->relative($manager->pidFile()))],
            ['Log', $daemon
                ? $e($this->relative($manager->logFile()))
                : sprintf('stdout <fg=gray>(%s in daemon mode)</>', $e($this->relative($manager->logFile())))],
            ['Mode', $daemon ? '<comment>daemon</comment>' : 'foreground <fg=gray>(Ctrl+C or ./start stop)</>'],
        ];
        foreach ($lines as [$key, $value]) {
            $io->writeln(sprintf('  <fg=gray>%-10s</> %s', $key, $value));
        }
        $io->newLine();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderProcesses(SymfonyStyle $io, array $rows, ?IoUringStatus $iouring): void
    {
        if ($rows === []) {
            return;
        }
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r['name']] = ($counts[$r['name']] ?? 0) + 1;
        }

        $table = [];
        foreach ($rows as $r) {
            $state = (string) $r['state'];
            $table[] = [
                $counts[$r['name']] > 1 ? $r['name'] . '#' . $r['index'] : $r['name'],
                $r['type'],
                $r['app'],
                $r['listen'] ?? '-',
                $r['workers'] ?? '-',
                (int) $r['pid'] > 0 ? (string) $r['pid'] : '-',
                match ($state) {
                    'listening', 'running' => '<info>' . $state . '</info>',
                    'starting' => '<comment>' . $state . '</comment>',
                    default => '<fg=red;options=bold>' . $state . '</>',
                },
                self::backend((string) $r['type'], $iouring),
            ];
        }
        $io->table(['process', 'type', 'app', 'listen', 'workers', 'pid', 'state', 'backend'], $table);
    }

    private function ioUringLine(?IoUringStatus $s, bool $verbose): string
    {
        if ($s === null) {
            return '<fg=gray>n/a</>';
        }
        $reason = $verbose ? ' <fg=gray>(' . OutputFormatter::escape($s->reason) . ')</>' : '';
        if ($s->networkActive) {
            return sprintf('<info>on</info>  network=uring_socket  coverage=%s  mode=%s%s', $s->coverage, $s->mode->value, $reason);
        }
        if ($s->active) {
            return sprintf('<comment>file only</comment>  network=%s  coverage=%s  mode=%s%s', $s->networkBackend, $s->coverage, $s->mode->value, $reason);
        }

        return sprintf('off  network=%s  mode=%s <fg=gray>(%s)</>', $s->networkBackend, $s->mode->value, OutputFormatter::escape($s->reason));
    }

    private static function backend(string $type, ?IoUringStatus $iouring): string
    {
        return match ($type) {
            'rpc' => $iouring !== null && $iouring->networkActive ? 'uring_socket' : 'epoll',
            'http', 'websocket' => 'epoll',
            default => '-',
        };
    }

    private function probeIoUring(ProcessManager $manager): ?IoUringStatus
    {
        try {
            $cfg = $manager->config()->get('rpc.iouring', []);

            return IoUringSupport::probe(is_array($cfg) ? $cfg : []);
        } catch (Throwable) {
            return null;
        }
    }

    private function relative(string $path): string
    {
        $base = $this->basePath() . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private static function rootPackageName(): string
    {
        try {
            if (class_exists(InstalledVersions::class)) {
                $name = (string) (InstalledVersions::getRootPackage()['name'] ?? '');
                if ($name !== '' && $name !== '__root__') {
                    return $name;
                }
            }
        } catch (Throwable) {
        }

        return 'loongs';
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
