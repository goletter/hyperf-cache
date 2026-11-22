<?php

declare(strict_types=1);

namespace Goletter\Cache\Tests;

use Goletter\Cache\Exception\LockTimeoutException;
use Goletter\Cache\Lock\RedisLock;
use Hyperf\Redis\Redis;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class RedisLockTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testGetAcquiresAndReleasesWithCallback(): void
    {
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('set')
            ->once()
            ->withArgs(function (string $key, string $owner, array $options) {
                return str_starts_with($key, 'goletter:cache:lock:foo')
                    && $options === ['nx', 'ex' => 10]
                    && $owner !== '';
            })
            ->andReturn(true);
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn(1);

        $lock = new RedisLock($redis, 'foo', 10);
        $result = $lock->get(fn () => 'ok');

        $this->assertSame('ok', $result);
    }

    public function testGetReturnsFalseWhenBusy(): void
    {
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('set')->once()->andReturn(false);

        $lock = new RedisLock($redis, 'foo', 10);

        $this->assertFalse($lock->get());
    }

    public function testReleaseOnlyDeletesMatchingOwner(): void
    {
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('set')->once()->andReturn(true);
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, array $args, int $numKeys) {
                return $numKeys === 1
                    && $args[0] === 'goletter:cache:lock:foo'
                    && is_string($args[1]);
            })
            ->andReturn(1);

        $lock = new RedisLock($redis, 'foo', 10, 'owner-a');
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->release());
    }

    public function testRefreshExtendsTtlWhenOwned(): void
    {
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('set')->once()->andReturn(true);
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, array $args, int $numKeys) {
                return str_contains($script, 'expire')
                    && $args[2] === '30'
                    && $numKeys === 1;
            })
            ->andReturn(1);

        $lock = new RedisLock($redis, 'foo', 10);
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->refresh(30));
        $this->assertFalse($lock->isExpired());
        $this->assertGreaterThan(0, $lock->getRemainingTime());
    }

    public function testRefreshFailsWithoutPositiveTtl(): void
    {
        $redis = Mockery::mock(Redis::class);
        $lock = new RedisLock($redis, 'foo', 0);

        $this->assertFalse($lock->refresh());
        $this->assertNull($lock->getRemainingTime());
        $this->assertFalse($lock->isExpired());
    }

    public function testBlockTimesOut(): void
    {
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('set')->andReturn(false);

        $lock = new RedisLock($redis, 'foo', 10);

        $this->expectException(LockTimeoutException::class);
        $lock->block(0);
    }

    public function testForceRelease(): void
    {
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('del')
            ->once()
            ->with('goletter:cache:lock:foo')
            ->andReturn(1);

        $lock = new RedisLock($redis, 'foo', 10);
        $lock->forceRelease();
        $this->assertTrue($lock->isExpired());
    }
}
