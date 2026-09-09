<?php

declare(strict_types=1);
/**
 * This file is part of goletter/hyperf-cache.
 */

namespace Goletter\Cache;

use Carbon\Carbon;
use Closure;
use DateInterval;
use DateTimeInterface;
use Goletter\Cache\Event\CacheFlushed;
use Goletter\Cache\Event\CacheFlushing;
use Goletter\Cache\Event\CacheHit;
use Goletter\Cache\Event\CacheMissed;
use Goletter\Cache\Event\ForgettingKey;
use Goletter\Cache\Event\KeyForgetFailed;
use Goletter\Cache\Event\KeyForgotten;
use Goletter\Cache\Event\KeyWriteFailed;
use Goletter\Cache\Event\KeyWritten;
use Goletter\Cache\Event\RetrievingKey;
use Goletter\Cache\Event\RetrievingManyKeys;
use Goletter\Cache\Event\WritingKey;
use Goletter\Cache\Event\WritingManyKeys;
use Hyperf\Cache\Driver\DriverInterface;
use Hyperf\Macroable\Macroable;
use Hyperf\Support\Traits\InteractsWithTime;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function Hyperf\Collection\value;
use function Hyperf\Tappable\tap;

class Repository implements Contract\Repository
{
    use InteractsWithTime;
    use Macroable;

    protected ?EventDispatcherInterface $eventDispatcher = null;

    public function __construct(
        protected ContainerInterface $container,
        protected DriverInterface $driver,
        protected string $name = 'default'
    ) {
        if ($container->has(EventDispatcherInterface::class)) {
            $this->eventDispatcher = $container->get(EventDispatcherInterface::class);
        }
    }

    public function __clone()
    {
        $this->driver = clone $this->driver;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param null|int|DateInterval $ttl
     */
    public function set($key, $value, $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
    }

    /**
     * @param string $key
     */
    public function delete($key): bool
    {
        return $this->forget($key);
    }

    public function clear(): bool
    {
        $this->event(new CacheFlushing($this->getName()));

        $result = $this->driver->clear();

        if ($result) {
            $this->event(new CacheFlushed($this->getName()));
        }

        return $result;
    }

    public function add($key, $value, $ttl = null): bool
    {
        if (is_null($this->get($key))) {
            return $this->put($key, $value, $ttl);
        }

        return false;
    }

    public function flexible($key, $ttl, $callback, $lock = null)
    {
        $keyPrefix = 'hyperf:cache:flexible:';
        $createdKey = "{$keyPrefix}{$key}";
        $lockKey = "{$keyPrefix}lock:{$key}";
        [
            $key => $value,
            $createdKey => $created,
        ] = $this->many([$key, $createdKey]);

        if (in_array(null, [$value, $created], true)) {
            return tap(value($callback), fn ($value) => $this->putMany([
                $key => $value,
                $createdKey => time(),
            ], $ttl[1]));
        }

        if (($created + $this->getSeconds($ttl[0])) > time()) {
            return $value;
        }

        $refresh = function () use ($key, $ttl, $callback, $lock, $created, $createdKey, $lockKey) {
            $write = function () use ($key, $callback, $created, $ttl, $createdKey) {
                if ($created !== $this->get($createdKey)) {
                    return;
                }

                $this->putMany([
                    $key => value($callback),
                    $createdKey => time(),
                ], $ttl[1]);
            };

            if (function_exists('FriendsOfHyperf\\Lock\\lock')) {
                \FriendsOfHyperf\Lock\lock(
                    $lockKey,
                    $lock['seconds'] ?? 0,
                    $lock['owner'] ?? null,
                )->get($write);

                return;
            }

            $write();
        };

        if (function_exists('Hyperf\\Coroutine\\defer')) {
            \Hyperf\Coroutine\defer($refresh);
        } else {
            $refresh();
        }

        return $value;
    }

    public function flush(): bool
    {
        return $this->clear();
    }

    public function forever($key, $value): bool
    {
        $this->event(new WritingKey($this->getName(), $key, $value));

        $result = $this->driver->set($key, $value);

        $this->event($result
            ? new KeyWritten($this->getName(), $key, $value)
            : new KeyWriteFailed($this->getName(), $key, $value));

        return $result;
    }

    public function forget($key): bool
    {
        $this->event(new ForgettingKey($this->getName(), $key));

        return tap($this->driver->delete($key), function ($result) use ($key) {
            $this->event($result
                ? new KeyForgotten($this->getName(), $key)
                : new KeyForgetFailed($this->getName(), $key));
        });
    }

    public function has($key): bool
    {
        return $this->driver->has($key);
    }

    public function missing($key): bool
    {
        return ! $this->has($key);
    }

    public function put($key, $value, $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        $this->event(new WritingKey($this->getName(), $key, $value, $seconds));

        $result = $this->driver->set($key, $value, $seconds);

        $this->event($result
            ? new KeyWritten($this->getName(), $key, $value, $seconds)
            : new KeyWriteFailed($this->getName(), $key, $value, $seconds));

        return $result;
    }

    public function putMany(array $values, $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->putManyForever($values);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->deleteMultiple(array_keys($values));
        }

        $this->event(new WritingManyKeys($this->getName(), array_keys($values), array_values($values), $seconds));

        $result = $this->driver->setMultiple($values, $seconds);

        $this->dispatchManyWriteEvents($values, $result, $seconds);

        return $result;
    }

    public function decrement($key, $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function increment($key, $value = 1): int|false
    {
        if (method_exists($this->driver, 'increment')) {
            return $this->driver->increment($key, $value);
        }

        $new = (int) $this->get($key, 0) + (int) $value;

        return $this->put($key, $new) ? $new : false;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @template TCacheValue
     *
     * @param string[]|string $key
     * @param (Closure(): TCacheValue)|TCacheValue $default
     * @return (TCacheValue is null ? mixed : TCacheValue)
     */
    public function get($key, $default = null): mixed
    {
        if (is_array($key)) {
            return $this->many($key);
        }

        $this->event(new RetrievingKey($this->getName(), $key));

        $value = $this->driver->get($key);

        if (is_null($value)) {
            $this->event(new CacheMissed($this->getName(), $key));

            return value($default);
        }

        $this->event(new CacheHit($this->getName(), $key, $value));

        return $value;
    }

    public function many(array $keys)
    {
        $this->event(new RetrievingManyKeys($this->getName(), $keys));

        $lookup = [];
        foreach ($keys as $key => $value) {
            $lookup[] = is_string($key) ? $key : $value;
        }

        $values = $this->driver->getMultiple($lookup);
        $result = [];

        foreach ($values as $key => $value) {
            $result[$key] = $this->handleManyResult($keys, (string) $key, $value);
        }

        return $result;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $defaults = [];

        foreach ($keys as $key) {
            $defaults[$key] = $default;
        }

        return $this->many($defaults);
    }

    public function pull($key, $default = null)
    {
        return tap($this->get($key, $default), fn () => $this->forget($key));
    }

    public function remember($key, $ttl, Closure $callback)
    {
        $value = $this->get($key);

        if (! is_null($value)) {
            return $value;
        }

        $this->put($key, $value = $callback(), $ttl);

        return $value;
    }

    public function rememberForever($key, Closure $callback)
    {
        $value = $this->get($key);

        if (! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback());

        return $value;
    }

    public function sear($key, Closure $callback)
    {
        return $this->rememberForever($key, $callback);
    }

    public function setMultiple($values, $ttl = null): bool
    {
        return $this->putMany(is_array($values) ? $values : iterator_to_array($values), $ttl);
    }

    /**
     * @param iterable $keys
     */
    public function deleteMultiple($keys): bool
    {
        $list = is_array($keys) ? $keys : iterator_to_array($keys, false);
        $name = $this->getName();

        foreach ($list as $key) {
            $this->event(new ForgettingKey($name, $key));
        }

        $result = $this->driver->deleteMultiple($list);

        foreach ($list as $key) {
            $this->event($result
                ? new KeyForgotten($name, $key)
                : new KeyForgetFailed($name, $key));
        }

        return $result;
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    public function getStore(): DriverInterface
    {
        return $this->getDriver();
    }

    protected function getName(): string
    {
        return $this->name;
    }

    /**
     * Handle a result for the "many" method.
     *
     * @param array $keys
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function handleManyResult($keys, $key, $value)
    {
        if (is_null($value)) {
            $this->event(new CacheMissed($this->getName(), $key));

            return (isset($keys[$key]) && ! array_is_list($keys)) ? value($keys[$key]) : null;
        }

        $this->event(new CacheHit($this->getName(), $key, $value));

        return $value;
    }

    protected function putManyForever(array $values): bool
    {
        $this->event(new WritingManyKeys($this->getName(), array_keys($values), array_values($values)));

        $result = $this->driver->setMultiple($values);

        $this->dispatchManyWriteEvents($values, $result);

        return $result;
    }

    protected function dispatchManyWriteEvents(array $values, bool $result, ?int $seconds = null): void
    {
        $name = $this->getName();

        foreach ($values as $key => $value) {
            $this->event($result
                ? new KeyWritten($name, $key, $value, $seconds)
                : new KeyWriteFailed($name, $key, $value, $seconds));
        }
    }

    /**
     * @param DateInterval|DateTimeInterface|int $ttl
     */
    protected function getSeconds($ttl): int
    {
        $duration = $this->parseDateInterval($ttl);

        if ($duration instanceof DateTimeInterface) {
            $duration = (int) ceil(
                Carbon::now()->diffInMilliseconds($duration, false) / 1000
            );
        }

        return max(0, (int) $duration);
    }

    protected function event(object $event): void
    {
        $this->eventDispatcher?->dispatch($event);
    }
}
