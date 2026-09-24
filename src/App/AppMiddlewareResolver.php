<?php

declare(strict_types=1);

namespace Loongs\App;

/**
 * Loads optional apps/<Name>/middleware.php and
 * apps/<Name>/Modules/<Module>/middleware.php (list of middleware class-strings).
 */
final class AppMiddlewareResolver
{
    /** @var array<string, list<class-string>> */
    private array $cache = [];

    public function __construct(
        private readonly string $appsPath,
    ) {
    }

    /**
     * @return list<class-string>
     */
    public function forApp(?string $app): array
    {
        if ($app === null || $app === '') {
            return [];
        }

        $key = 'app:' . $app;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $file = $this->appsPath . DIRECTORY_SEPARATOR . $app . DIRECTORY_SEPARATOR . 'middleware.php';

        return $this->cache[$key] = $this->loadList($file);
    }

    /**
     * @return list<class-string>
     */
    public function forModule(?string $app, ?string $module): array
    {
        if ($app === null || $app === '' || $module === null || $module === '') {
            return [];
        }

        $key = 'mod:' . $app . '/' . $module;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $file = $this->appsPath
            . DIRECTORY_SEPARATOR . $app
            . DIRECTORY_SEPARATOR . 'Modules'
            . DIRECTORY_SEPARATOR . $module
            . DIRECTORY_SEPARATOR . 'middleware.php';

        return $this->cache[$key] = $this->loadList($file);
    }

    /**
     * @return list<class-string>
     */
    private function loadList(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        /** @var mixed $list */
        $list = require $file;
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
