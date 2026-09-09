<?php

declare(strict_types=1);

namespace Goletter\Cache;

use Goletter\Cache\Contract\Factory;
use Goletter\Cache\Contract\Repository;
use Goletter\Cache\Repository as CacheRepository;
use Hyperf\Cache\CacheManager as HyperfCacheManager;

use function Hyperf\Support\make;

class CacheManager implements Factory
{
    /**
     * @var array<string, Repository>
     */
    protected array $drivers = [];

    public function __construct(protected HyperfCacheManager $cacheManager)
    {
    }

    public function store(?string $name = null): Repository
    {
        $name ??= $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    public function driver(?string $name = null): Repository
    {
        return $this->store($name);
    }

    public function resolve(string $name): Repository
    {
        return make(CacheRepository::class, [
            'name' => $name,
            'driver' => $this->cacheManager->getDriver($name),
        ]);
    }

    /**
     * Forget resolved repository instance(s). Null clears all.
     */
    public function forgetDriver(?string $name = null): static
    {
        if ($name === null) {
            $this->drivers = [];

            return $this;
        }

        unset($this->drivers[$name]);

        return $this;
    }

    /**
     * Alias of forgetDriver().
     */
    public function purge(?string $name = null): static
    {
        return $this->forgetDriver($name);
    }

    public function getDefaultDriver(): string
    {
        return 'default';
    }

    /**
     * Forward undefined calls to the default store.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }
}
