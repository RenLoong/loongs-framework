<?php

declare(strict_types=1);

namespace Loongs\Console;

use Loongs\Process\ProcessManager;
use Loongs\Rpc\HotReload\RpcServiceManager;
use Loongs\Support\BasePath;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base for Loongs console commands (framework + app commands).
 * Business logic stays in ProcessManager / RpcServiceManager; commands only parse + render.
 */
abstract class Command extends SymfonyCommand
{
    private ?string $basePath = null;

    public function setBasePath(string $basePath): void
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    protected function basePath(): string
    {
        return $this->basePath ?? BasePath::get();
    }

    protected function io(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        return new SymfonyStyle($input, $output);
    }

    /**
     * @param list<string>|null $only
     */
    protected function processManager(?array $only = null): ProcessManager
    {
        return new ProcessManager($this->basePath(), $only);
    }

    protected function rpcServices(): RpcServiceManager
    {
        return RpcServiceManager::fromBasePath($this->basePath());
    }
}
