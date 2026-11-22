<?php

declare(strict_types=1);
/**
 * This file is part of goletter/hyperf-cache.
 */

namespace Goletter\Cache\Lock;

use Goletter\Cache\Contract\Lock;
use Goletter\Cache\Exception\LockTimeoutException;
use Hyperf\Redis\Redis;
use InvalidArgumentException;

use function Hyperf\Support\value;

class RedisLock implements Lock
{
    protected const KEY_PREFIX = 'goletter:cache:lock:';

    protected string $owner;

    protected int $sleepMilliseconds = 100;

    protected ?float $acquiredAt = null;

    public function __construct(
        protected Redis $redis,
        protected string $name,
        protected int $seconds = 0,
        ?string $owner = null
    ) {
        if ($this->seconds < 0) {
            throw new InvalidArgumentException('Lock TTL seconds must be >= 0.');
        }

        $this->owner = $owner ?? bin2hex(random_bytes(16));
    }

    public function get(?callable $callback = null): mixed
    {
        $result = $this->acquire();

        if ($callback && $result) {
            try {
                return value($callback);
            } finally {
                $this->release();
            }
        }

        return $result;
    }

    public function block(int $seconds, ?callable $callback = null): mixed
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Block timeout seconds must be >= 0.');
        }

        $starting = microtime(true);

        while (! $this->acquire()) {
            if ((microtime(true) - $starting) >= $seconds) {
                throw new LockTimeoutException(sprintf(
                    'Unable to acquire lock [%s] within %d seconds.',
                    $this->name,
                    $seconds
                ));
            }

            $this->sleep();
        }

        if ($callback) {
            try {
                return value($callback);
            } finally {
                $this->release();
            }
        }

        return true;
    }

    public function release(): bool
    {
        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end

return 0
LUA;

        $released = (bool) $this->redis->eval($script, [$this->key(), $this->owner], 1);

        if ($released) {
            $this->acquiredAt = null;
        }

        return $released;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function forceRelease(): void
    {
        $this->redis->del($this->key());
        $this->acquiredAt = null;
    }

    public function refresh(?int $ttl = null): bool
    {
        $ttl ??= $this->seconds;

        if ($ttl <= 0) {
            return false;
        }

        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("expire", KEYS[1], ARGV[2])
end

return 0
LUA;

        $refreshed = (bool) $this->redis->eval(
            $script,
            [$this->key(), $this->owner, (string) $ttl],
            1
        );

        if ($refreshed) {
            $this->seconds = $ttl;
            $this->acquiredAt = microtime(true);
        }

        return $refreshed;
    }

    public function isExpired(): bool
    {
        if ($this->seconds <= 0) {
            return false;
        }

        if ($this->acquiredAt === null) {
            return true;
        }

        return microtime(true) >= ($this->acquiredAt + $this->seconds);
    }

    public function getRemainingTime(): ?float
    {
        if ($this->seconds <= 0) {
            return null;
        }

        if ($this->acquiredAt === null) {
            return null;
        }

        $remaining = ($this->acquiredAt + $this->seconds) - microtime(true);

        return $remaining > 0 ? $remaining : 0.0;
    }

    protected function acquire(): bool
    {
        $key = $this->key();

        if ($this->seconds > 0) {
            $acquired = (bool) $this->redis->set($key, $this->owner, ['nx', 'ex' => $this->seconds]);
        } else {
            // TTL 0 = no expiry. Prefer a positive TTL so crashed holders cannot deadlock.
            $acquired = (bool) $this->redis->set($key, $this->owner, ['nx']);
        }

        if ($acquired) {
            $this->acquiredAt = microtime(true);
        }

        return $acquired;
    }

    protected function key(): string
    {
        return self::KEY_PREFIX . $this->name;
    }

    protected function sleep(): void
    {
        if (class_exists(\Hyperf\Coroutine\Coroutine::class)
            && \Hyperf\Coroutine\Coroutine::getCid() > 0
        ) {
            \Hyperf\Coroutine\usleep($this->sleepMilliseconds * 1000);

            return;
        }

        usleep($this->sleepMilliseconds * 1000);
    }
}
