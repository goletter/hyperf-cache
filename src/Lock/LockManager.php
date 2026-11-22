<?php

declare(strict_types=1);
/**
 * This file is part of goletter/hyperf-cache.
 */

namespace Goletter\Cache\Lock;

use Goletter\Cache\Contract\Lock;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use RuntimeException;

class LockManager
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function make(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new RedisLock($this->redis(), $name, $seconds, $owner);
    }

    protected function redis(): Redis
    {
        if (! $this->container->has(Redis::class)) {
            throw new RuntimeException(
                'Redis is required for locks. Install hyperf/redis and ensure it is registered.'
            );
        }

        return $this->container->get(Redis::class);
    }
}
