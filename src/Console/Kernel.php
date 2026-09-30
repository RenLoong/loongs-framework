<?php

declare(strict_types=1);

namespace Loongs\Console;

use Composer\InstalledVersions;
use Loongs\Console\Command\ReloadCommand;
use Loongs\Console\Command\RestartCommand;
use Loongs\Console\Command\RpcResetCommand;
use Loongs\Console\Command\RpcSetCommand;
use Loongs\Console\Command\RpcShowCommand;
use Loongs\Console\Command\RpcSwitchCommand;
use Loongs\Console\Command\StartCommand;
use Loongs\Console\Command\StatusCommand;
use Loongs\Console\Command\StopCommand;
use Loongs\Process\InvalidAppNameException;
use Loongs\Support\BasePath;
use Loongs\Support\Env;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `server/loongs` console (symfony/console). Pre-fork safe: never boots the HTTP Application.
 *
 *   ./loongs                      → start (default command, keeps `./loongs --only=http` working)
 *   ./loongs start|stop|restart|reload|status
 *   ./loongs rpc:show|rpc:switch|rpc:set|rpc:reset
 *   ./loongs list / help <cmd>
 *
 * Extra commands (Symfony Command classes, #[AsCommand]):
 *   - server:  config/console.php            → ['commands' => [MyCommand::class, ...]]
 *   - per app: apps/<App>/config/console.php → ['commands' => [...]]
 * Commands extending Loongs\Console\Command get basePath() + io() helpers.
 */
final class Kernel extends SymfonyApplication
{
    public const NAME = 'Loongs';

    public function __construct(private readonly string $basePath)
    {
        parent::__construct(self::NAME, self::frameworkVersion());
        BasePath::set(rtrim($basePath, '/\\'));

        foreach ([
            new StartCommand(),
            new StopCommand(),
            new RestartCommand(),
            new ReloadCommand(),
            new StatusCommand(),
            new RpcShowCommand(),
            new RpcSwitchCommand(),
            new RpcSetCommand(),
            new RpcResetCommand(),
        ] as $command) {
            $this->addLoongsCommand($command);
        }
        foreach ($this->extraCommandClasses() as $class) {
            $this->addLoongsCommand(new $class());
        }

        // Backwards compatible: bare `./loongs` (optionally with --only=...) starts the server.
        $this->setDefaultCommand('start');
    }

    public function basePath(): string
    {
        return rtrim($this->basePath, '/\\');
    }

    public static function frameworkVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('loongs/framework')) {
                $version = (string) InstalledVersions::getPrettyVersion('loongs/framework');
                $ref = (string) InstalledVersions::getReference('loongs/framework');
                if (str_starts_with($version, 'dev-')) {
                    // Path / symlinked checkouts: the lock's reference goes stale, prefer the checkout HEAD.
                    $ref = self::gitHead((string) InstalledVersions::getInstallPath('loongs/framework')) ?? $ref;
                }

                return $version . ($ref !== '' && str_starts_with($version, 'dev-') ? '@' . substr($ref, 0, 7) : '');
            }
        } catch (Throwable) {
        }

        return 'dev';
    }

    /** Commit sha of a git checkout (HEAD → loose ref → packed-refs), or null. */
    private static function gitHead(string $dir): ?string
    {
        $git = rtrim($dir, '/\\') . '/.git';
        if ($dir === '' || !is_dir($git)) {
            return null;
        }
        $head = trim((string) @file_get_contents($git . '/HEAD'));
        if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            return $head;
        }
        if (!str_starts_with($head, 'ref: ')) {
            return null;
        }
        $ref = substr($head, 5);
        $sha = trim((string) @file_get_contents($git . '/' . $ref));
        if (preg_match('/^[0-9a-f]{40}$/', $sha) === 1) {
            return $sha;
        }
        foreach (@file($git . '/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, ' ' . $ref) && preg_match('/^[0-9a-f]{40}/', $line) === 1) {
                return substr($line, 0, 40);
            }
        }

        return null;
    }

    /** Invalid APP_NAME → one clear error line + exit 1 (thrown before anything is forked / signalled). */
    protected function doRunCommand(SymfonyCommand $command, InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRunCommand($command, $input, $output);
        } catch (InvalidAppNameException $e) {
            (new SymfonyStyle($input, $output))->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }
    }

    private function addLoongsCommand(SymfonyCommand $command): void
    {
        if ($command instanceof Command) {
            $command->setBasePath($this->basePath());
        }
        $this->addCommand($command);
    }

    /**
     * @return list<class-string<SymfonyCommand>>
     */
    private function extraCommandClasses(): array
    {
        $base = $this->basePath();
        $files = array_merge([$base . '/config/console.php'], glob($base . '/apps/*/config/console.php') ?: []);
        $classes = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            Env::load($base . '/.env');
            /** @var mixed $cfg */
            $cfg = (static fn (): mixed => require $file)();
            foreach ((is_array($cfg) ? ($cfg['commands'] ?? []) : []) as $class) {
                if (!is_string($class) || !is_subclass_of($class, SymfonyCommand::class)) {
                    throw new \InvalidArgumentException("{$file}: console command [" . (is_string($class) ? $class : get_debug_type($class)) . '] must be a ' . SymfonyCommand::class . ' class.');
                }
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }
}
