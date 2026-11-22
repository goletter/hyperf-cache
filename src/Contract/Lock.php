<?php

declare(strict_types=1);
/**
 * This file is part of goletter/hyperf-cache.
 */

namespace Goletter\Cache\Contract;

use Goletter\Cache\Exception\LockTimeoutException;

interface Lock
{
    /**
     * Attempt to acquire the lock.
     *
     * @param  null|callable(): mixed  $callback
     */
    public function get(?callable $callback = null): mixed;

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * @param  null|callable(): mixed  $callback
     * @throws LockTimeoutException
     */
    public function block(int $seconds, ?callable $callback = null): mixed;

    /**
     * Release the lock.
     */
    public function release(): bool;

    /**
     * Returns the current owner of the lock.
     */
    public function owner(): string;

    /**
     * Releases this lock in disregard of ownership.
     */
    public function forceRelease(): void;

    /**
     * Extend the lock TTL. Null uses the original seconds.
     */
    public function refresh(?int $ttl = null): bool;

    /**
     * Whether the locally tracked lock lifetime has elapsed.
     */
    public function isExpired(): bool;

    /**
     * Remaining lifetime in seconds, or null when the lock does not expire.
     */
    public function getRemainingTime(): ?float;
}
