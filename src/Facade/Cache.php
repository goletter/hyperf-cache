<?php

declare(strict_types=1);

namespace Goletter\Cache\Facade;

use Closure;
use DateInterval;
use DateTimeInterface;
use Goletter\Cache\CacheManager;
use Goletter\Cache\Contract\Repository;
use Hyperf\Context\ApplicationContext;

/**
 * @mixin Repository
 * @mixin CacheManager
 */
class Cache
{
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::store()->{$name}(...$arguments);
    }

    public static function store(?string $name = null): Repository
    {
        return self::manager()->store($name);
    }

    public static function driver(?string $name = null): Repository
    {
        return self::manager()->driver($name);
    }

    public static function resolve(string $name): Repository
    {
        return self::manager()->resolve($name);
    }

    public static function purge(?string $name = null): void
    {
        self::manager()->purge($name);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param null|DateInterval|DateTimeInterface|int $ttl
     */
    public static function add($key, $value, $ttl = null): bool
    {
        return self::store()->add($key, $value, $ttl);
    }

    /**
     * @template TCacheValue
     *
     * @param string $key
     * @param array{ 0: DateTimeInterface|DateInterval|int, 1: DateTimeInterface|DateInterval|int } $ttl
     * @param (callable(): TCacheValue) $callback
     * @param null|array{ seconds?: int, owner?: string } $lock
     * @return TCacheValue
     */
    public static function flexible($key, $ttl, $callback, $lock = null)
    {
        return self::store()->flexible($key, $ttl, $callback, $lock);
    }

    public static function flush(): bool
    {
        return self::store()->flush();
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public static function forever($key, $value): bool
    {
        return self::store()->forever($key, $value);
    }

    /**
     * @param string $key
     */
    public static function forget($key): bool
    {
        return self::store()->forget($key);
    }

    /**
     * @param string $key
     */
    public static function has($key): bool
    {
        return self::store()->has($key);
    }

    /**
     * @param string $key
     */
    public static function missing($key): bool
    {
        return self::store()->missing($key);
    }

    /**
     * @param array|string $key
     * @param mixed $value
     * @param null|DateInterval|DateTimeInterface|int $ttl
     */
    public static function put($key, $value, $ttl = null): bool
    {
        return self::store()->put($key, $value, $ttl);
    }

    public static function putMany(array $values, $ttl = null): bool
    {
        return self::store()->putMany($values, $ttl);
    }

    /**
     * @param string $key
     * @param int $value
     */
    public static function decrement($key, $value = 1): int|false
    {
        return self::store()->decrement($key, $value);
    }

    /**
     * @param string $key
     * @param int $value
     */
    public static function increment($key, $value = 1): int|false
    {
        return self::store()->increment($key, $value);
    }

    /**
     * @template TCacheValue
     *
     * @param array|string $key
     * @param (Closure(): TCacheValue)|TCacheValue $default
     * @return (TCacheValue is null ? mixed : TCacheValue)
     */
    public static function get($key, $default = null)
    {
        return self::store()->get($key, $default);
    }

    /**
     * @return iterable
     */
    public static function many(array $keys)
    {
        return self::store()->many($keys);
    }

    /**
     * @template TCacheValue
     *
     * @param array|string $key
     * @param (Closure(): TCacheValue)|TCacheValue $default
     * @return (TCacheValue is null ? mixed : TCacheValue)
     */
    public static function pull($key, $default = null)
    {
        return self::store()->pull($key, $default);
    }

    /**
     * @template TCacheValue
     *
     * @param string $key
     * @param null|DateInterval|DateTimeInterface|int $ttl
     * @param (Closure(): TCacheValue) $callback
     * @return TCacheValue
     */
    public static function remember($key, $ttl, Closure $callback)
    {
        return self::store()->remember($key, $ttl, $callback);
    }

    /**
     * @template TCacheValue
     *
     * @param string $key
     * @param (Closure(): TCacheValue) $callback
     * @return TCacheValue
     */
    public static function rememberForever($key, Closure $callback)
    {
        return self::store()->rememberForever($key, $callback);
    }

    /**
     * @template TCacheValue
     *
     * @param string $key
     * @param (Closure(): TCacheValue) $callback
     * @return TCacheValue
     */
    public static function sear($key, Closure $callback)
    {
        return self::store()->sear($key, $callback);
    }

    protected static function manager(): CacheManager
    {
        return ApplicationContext::getContainer()->get(CacheManager::class);
    }
}
