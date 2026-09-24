<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use Loongs\Http\Application;
use Loongs\Process\ProcessInterface;
use Loongs\Process\WorkerPools;
use RuntimeException;

/**
 * Instantiates a user class implementing ProcessInterface and runs handle().
 */
final class CustomProcess implements ProcessInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function handle(string $name, array $config): void
    {
        $class = (string) ($config['class'] ?? '');
        if ($class === '' || !class_exists($class)) {
            throw new RuntimeException("Custom process [{$name}] requires a valid class, got [{$class}].");
        }

        WorkerPools::enableCoroutineHooks();
        $app = new Application($this->basePath);
        $container = $app->container();

        $bootPools = (bool) ($config['boot_pools'] ?? true);
        if ($bootPools) {
            WorkerPools::boot($container);
        }

        /** @var object $instance */
        $instance = $container->has($class) ? $container->make($class) : new $class();
        if (!$instance instanceof ProcessInterface) {
            throw new RuntimeException("Custom process class [{$class}] must implement ProcessInterface.");
        }

        echo sprintf("[%s] Custom process [%s] class=%s\n", date('Y-m-d H:i:s'), $name, $class);
        try {
            $instance->handle($name, $config);
        } finally {
            if ($bootPools) {
                WorkerPools::close($container);
            }
        }
    }
}
