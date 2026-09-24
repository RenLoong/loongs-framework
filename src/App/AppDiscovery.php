<?php

declare(strict_types=1);

namespace Loongs\App;

use Loongs\Container\Container;
use Loongs\Http\Application;
use Loongs\Routing\Router;
use Loongs\Rpc\Server\HandlerRegistry;

/**
 * Discovers apps under {basePath}/apps/* and their one-level Modules/*.
 *
 * Convention:
 * - apps/<Name>/routes.php registers app routes (setCurrentApp; module=null)
 * - apps/<Name>/Modules/<Module>/routes.php registers module routes
 *   (setCurrentApp + setCurrentModule). Nested Modules/Modules are NEVER scanned.
 * - apps/<Name>/rpc.php registers RPC handlers on HandlerRegistry (optional).
 */
final class AppDiscovery
{
    public function __construct(
        private readonly string $appsPath,
    ) {
    }

    public function appsPath(): string
    {
        return $this->appsPath;
    }

    /**
     * @return list<string> Discovered app directory names (sorted)
     */
    public function listApps(): array
    {
        return $this->listDirectories($this->appsPath);
    }

    /**
     * One-level module dirs under apps/{App}/Modules/ (no nesting).
     *
     * @return list<string>
     */
    public function listModules(string $app): array
    {
        $path = $this->appsPath
            . DIRECTORY_SEPARATOR . $app
            . DIRECTORY_SEPARATOR . 'Modules';

        return $this->listDirectories($path);
    }

    /**
     * Load each app's routes.php, then each module's routes.php (one level).
     *
     * @return list<string> App directory names whose routes.php was loaded
     */
    /**
     * @param string|null $onlyApp When set, only load that app (and its modules)
     */
    public function registerRoutes(
        Router $router,
        Application $app,
        Container $container,
        ?string $onlyApp = null,
    ): array {
        $names = $this->listApps();
        if ($onlyApp !== null && $onlyApp !== '') {
            $names = array_values(array_filter(
                $names,
                static fn (string $n): bool => strcasecmp($n, $onlyApp) === 0,
            ));
        }
        $loaded = [];

        foreach ($names as $name) {
            $appRoutes = $this->appsPath
                . DIRECTORY_SEPARATOR . $name
                . DIRECTORY_SEPARATOR . 'routes.php';

            $router->setCurrentApp($name);
            $router->setCurrentModule(null);
            try {
                if (is_file($appRoutes)) {
                    // $router, $app, $container intentionally in scope
                    require $appRoutes;
                    $loaded[] = $name;
                }

                foreach ($this->listModules($name) as $module) {
                    $moduleRoutes = $this->appsPath
                        . DIRECTORY_SEPARATOR . $name
                        . DIRECTORY_SEPARATOR . 'Modules'
                        . DIRECTORY_SEPARATOR . $module
                        . DIRECTORY_SEPARATOR . 'routes.php';

                    if (!is_file($moduleRoutes)) {
                        continue;
                    }

                    $router->setCurrentModule($module);
                    try {
                        require $moduleRoutes;
                    } finally {
                        $router->setCurrentModule(null);
                    }
                }
            } finally {
                $router->setCurrentApp(null);
                $router->setCurrentModule(null);
            }
        }

        return $loaded;
    }


    /**
     * Load each app's rpc.php to register RPC handlers (optional per app).
     *
     * Scope inside rpc.php: $handlers, $app, $container.
     *
     * @return list<string> App directory names whose rpc.php was loaded
     */
    public function registerRpcHandlers(
        HandlerRegistry $handlers,
        Application $app,
        Container $container,
        ?string $onlyApp = null,
    ): array {
        $loaded = [];

        $apps = $this->listApps();
        if ($onlyApp !== null && $onlyApp !== '') {
            $apps = array_values(array_filter(
                $apps,
                static fn (string $n): bool => strcasecmp($n, $onlyApp) === 0,
            ));
        }

        foreach ($apps as $name) {
            $rpcFile = $this->appsPath
                . DIRECTORY_SEPARATOR . $name
                . DIRECTORY_SEPARATOR . 'rpc.php';

            if (!is_file($rpcFile)) {
                continue;
            }

            // $handlers, $app, $container intentionally in scope for rpc.php
            require $rpcFile;
            $loaded[] = $name;
        }

        return $loaded;
    }

    /**
     * @return list<string>
     */
    private function listDirectories(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $names = [];
        $entries = scandir($path);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names, SORT_STRING);

        return $names;
    }
}
