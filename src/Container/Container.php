<?php

declare(strict_types=1);

namespace Loongs\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var array<string, true> */
    private array $singletons = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @param Closure|string|object $concrete */
    public function bind(string $id, mixed $concrete, bool $singleton = false): void
    {
        $this->bindings[$id] = $concrete;
        if ($singleton) {
            $this->singletons[$id] = true;
        } else {
            unset($this->singletons[$id]);
        }
    }

    /** @param Closure|string|object $concrete */
    public function singleton(string $id, mixed $concrete): void
    {
        $this->bind($id, $concrete, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
        $this->singletons[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    public function make(string $id, array $parameters = []): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $concrete = $this->bindings[$id] ?? $id;

        if ($concrete instanceof Closure) {
            $object = $concrete($this, ...$parameters);
        } elseif (is_object($concrete)) {
            $object = $concrete;
        } elseif (is_string($concrete)) {
            $object = $this->build($concrete, $parameters);
        } else {
            throw new RuntimeException("Unable to resolve [{$id}].");
        }

        if (isset($this->singletons[$id])) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $parameters
     */
    private function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Class [{$class}] does not exist.");
        }

        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class [{$class}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $deps = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $parameters)) {
                $deps[] = $parameters[$name];
                continue;
            }

            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $deps[] = $this->make($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $deps[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Unable to resolve parameter [\${$name}] of [{$class}]."
            );
        }

        return $reflector->newInstanceArgs($deps);
    }
}
