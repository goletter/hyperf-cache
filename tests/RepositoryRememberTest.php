<?php

declare(strict_types=1);

namespace Goletter\Cache\Tests;

use Goletter\Cache\Lock\LockManager;
use Goletter\Cache\Lock\RedisLock;
use Goletter\Cache\Repository;
use Hyperf\Cache\Driver\DriverInterface;
use Hyperf\Redis\Redis;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class RepositoryRememberTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testRememberWithoutLockRunsCallbackOnMiss(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('get')->once()->with('users')->andReturn(null);
        $driver->shouldReceive('set')->once()->with('users', ['a'], 60)->andReturn(true);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->andReturn(false);

        $repo = new Repository($container, $driver);
        $calls = 0;

        $value = $repo->remember('users', 60, function () use (&$calls) {
            ++$calls;

            return ['a'];
        });

        $this->assertSame(['a'], $value);
        $this->assertSame(1, $calls);
    }

    public function testRememberReturnsCachedValue(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('get')->once()->with('users')->andReturn(['cached']);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->andReturn(false);

        $repo = new Repository($container, $driver);
        $value = $repo->remember('users', 60, function () {
            $this->fail('callback should not run');
        });

        $this->assertSame(['cached'], $value);
    }

    public function testRememberWithLockSerializesFill(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('get')
            ->with('users')
            ->andReturn(null, null);
        $driver->shouldReceive('set')->once()->with('users', ['locked'], 60)->andReturn(true);

        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('set')->once()->andReturn(true);
        $redis->shouldReceive('eval')->once()->andReturn(1);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(Redis::class)->andReturn(true);
        $container->shouldReceive('has')->with(LockManager::class)->andReturn(true);
        $container->shouldReceive('has')->with(\Psr\EventDispatcher\EventDispatcherInterface::class)->andReturn(false);
        $container->shouldReceive('get')->with(LockManager::class)->andReturn(
            new class($redis) extends LockManager {
                public function __construct(private Redis $redis)
                {
                }

                public function make(string $name, int $seconds = 0, ?string $owner = null): \Goletter\Cache\Contract\Lock
                {
                    return new RedisLock($this->redis, $name, $seconds, $owner);
                }
            }
        );

        $repo = new Repository($container, $driver);
        $value = $repo->remember('users', 60, fn () => ['locked'], true);

        $this->assertSame(['locked'], $value);
    }

    public function testRememberWithLockReusesValueWhenLockBusy(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('get')
            ->with('users')
            ->andReturn(null, ['from-other']);

        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('set')->andReturn(false);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(Redis::class)->andReturn(true);
        $container->shouldReceive('has')->with(LockManager::class)->andReturn(true);
        $container->shouldReceive('has')->with(\Psr\EventDispatcher\EventDispatcherInterface::class)->andReturn(false);
        $container->shouldReceive('get')->with(LockManager::class)->andReturn(
            new class($redis) extends LockManager {
                public function __construct(private Redis $redis)
                {
                }

                public function make(string $name, int $seconds = 0, ?string $owner = null): \Goletter\Cache\Contract\Lock
                {
                    return new RedisLock($this->redis, $name, $seconds, $owner);
                }
            }
        );

        $repo = new Repository($container, $driver);
        $value = $repo->remember('users', 60, function () {
            $this->fail('callback should not run when peer filled cache');
        }, ['seconds' => 5, 'wait' => 0]);

        $this->assertSame(['from-other'], $value);
    }
}
