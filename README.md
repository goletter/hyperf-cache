# Cache

[中文说明](README_CN.md)

## Introduction

`goletter/hyperf-cache` wraps the drivers provided by `hyperf/cache` with a
Laravel-style repository API. It supports PSR-16 operations, named stores,
facade access, a `cache()` helper, Redis distributed locks, cache events,
macros, and stale-while-revalidate caching.

Namespace: `Goletter\Cache`.

## Installation

```shell
composer require goletter/hyperf-cache
```

Requires Hyperf 3.1 or 3.2. `ConfigProvider` registers
`Goletter\Cache\Contract\Factory`,
`Goletter\Cache\Contract\Repository`, and
`Goletter\Cache\Lock\LockManager` in the container.

For locks (and `flexible()` mutual exclusion), also install Redis:

```shell
composer require hyperf/redis
```

## Configuration

This component does not publish a separate configuration file. Configure
drivers and named stores through `hyperf/cache`; `CacheManager::store($name)`
passes the name to Hyperf's cache manager. The default repository resolves the
`default` store.

## Accessing a Repository

### Dependency Injection

Inject `Contract\Repository` for the default store:

```php
namespace App\Controller;

use Goletter\Cache\Contract\Repository;

class IndexController
{
    public function __construct(private Repository $cache)
    {
    }

    public function index(): mixed
    {
        return $this->cache->remember('users', 60, function () {
            return [];
        });
    }
}
```

Inject `Contract\Factory` when a named store is required. Calls on the factory
are forwarded to the default store (`$factory->remember(...)`).

```php
use Goletter\Cache\Contract\Factory;

$cache = $factory->store('redis');
```

### Helper

```php
cache();                       // default Repository
cache('users');                // get
cache(['users' => $users], 60); // putMany
```

### Facade

```php
use Goletter\Cache\Facade\Cache;

$users = Cache::remember('users', 60, function () {
    return [];
});

$users = Cache::store('redis')->get('users');
```

`Cache::driver($name)` is an alias of `Cache::store($name)`.
`Cache::resolve($name)` creates a new repository instead of returning the
manager's cached repository instance.
`Cache::purge($name)` forgets a cached repository instance.

## Core Operations

The repository implements `Psr\SimpleCache\CacheInterface`, so `get()`, `set()`,
`delete()`, `clear()`, `getMultiple()`, `setMultiple()`, `deleteMultiple()`,
and `has()` are available. It also provides these extensions:

| Method | Behavior |
| --- | --- |
| `get($key, $default = null)` | Retrieves one item; a callable default is evaluated on a miss. Passing an array delegates to `many()`. |
| `put($key, $value, $ttl = null)` | Stores one item; `null` means forever and a non-positive TTL deletes the key. Passing an associative array delegates to `putMany()`, with the second argument used as its TTL. |
| `putMany($values, $ttl = null)` | Stores multiple items; a non-positive TTL deletes their keys. |
| `forever($key, $value)` | Stores one item without a TTL. |
| `add($key, $value, $ttl = null)` | Stores the item only when `get($key)` returns `null`. |
| `many($keys)` | Retrieves multiple keys; associative input may provide per-key defaults. |
| `pull($key, $default = null)` | Retrieves (with default) and then deletes an item. |
| `remember($key, $ttl, Closure $callback)` | Returns the cached value or stores the callback result with a TTL. |
| `rememberForever($key, Closure $callback)` / `sear(...)` | Returns the cached value or stores the callback result forever. |
| `increment($key, $value = 1)` / `decrement(...)` | Prefer driver `increment` when available; otherwise read-modify-write without a TTL. |
| `flush()` | Alias of `clear()`. |
| `missing($key)` | Inverse of `has($key)`. |
| `getDriver()` / `getStore()` | Returns the underlying Hyperf `DriverInterface`. |

TTL values accepted by the extended repository methods may be seconds,
`DateInterval`, or `DateTimeInterface`.

::: warning Behavioral boundaries
The repository treats a cached `null` as a miss. `add()` is get-then-put, so it
is not atomic. `increment()` / `decrement()` use the driver `increment` method
when present; otherwise they are read-then-write and not atomic.
:::

## Stale-While-Revalidate

`flexible()` accepts a two-item TTL array: the first value is the fresh period
and the second is the storage TTL used for both the cached value and its
internal creation timestamp.

```php
use Goletter\Cache\Facade\Cache;

$users = Cache::flexible('users', [30, 300], function () {
    return [];
}, [
    'seconds' => 10,
    'owner' => 'users-refresh',
]);
```

On a miss, the callback runs immediately. During the fresh period, the cached
value is returned. After the fresh period, the stale value is returned and a
deferred callback attempts to refresh it under a lock. A refresh is skipped if
another process has already updated the creation timestamp.

Built-in Redis locks (via `hyperf/redis`) are preferred for refresh mutual
exclusion. `friendsofhyperf/lock` remains an optional fallback. Without either,
refresh still runs (without mutual exclusion). Without `hyperf/coroutine`
`defer`, refresh runs inline.

```shell
composer require hyperf/redis hyperf/coroutine
```

The optional lock array accepts `seconds` and `owner`; `seconds` defaults to
`10` (a positive TTL is enforced for built-in locks). Prefer a TTL so crashed
holders cannot deadlock.

### Stampede-safe `remember`

Pass `true` or lock options as the 4th argument to serialize cache fills:

```php
$users = Cache::remember('users', 60, function () {
    return [];
}, true);

$users = Cache::remember('users', 60, function () {
    return [];
}, ['seconds' => 10, 'wait' => 5]);
```

Without Redis, the lock flag is ignored and behavior matches unlocked `remember`.

## Distributed Locks

Requires `hyperf/redis`. API mirrors Laravel / `friendsofhyperf/lock`:

```php
use Goletter\Cache\Exception\LockTimeoutException;
use Goletter\Cache\Facade\Cache;
use function lock;

// Non-blocking
$lock = lock('foo', 10);
if ($lock->get()) {
    try {
        // critical section
    } finally {
        $lock->release();
    }
}

// Auto-release with callback
lock('foo', 10)->get(function () {
    // ...
});

// Wait up to 5 seconds
try {
    lock('foo', 10)->block(5, function () {
        // ...
    });
} catch (LockTimeoutException $e) {
    // unable to acquire
}

// Facade
Cache::lock('foo', 10)->get(function () {});

// Fail fast (no wait) — e.g. reject duplicate submits
if (! lock('order:create:'.$userId, 10)->get()) {
    // busy / duplicate
}
```

Lock keys are prefixed with `goletter:cache:lock:`. Owners are random tokens;
`release()` only deletes when the owner matches.

`refresh(?int $ttl = null)` extends TTL while held. `isExpired()` /
`getRemainingTime()` track the local lifetime (`seconds = 0` means no expiry —
prefer a positive TTL).

## Events

When the container provides `Psr\EventDispatcher\EventDispatcherInterface`,
the repository dispatches events for reads, writes, deletes, and flushes:

- `CacheHit`, `CacheMissed`, `RetrievingKey`, `RetrievingManyKeys`
- `WritingKey`, `WritingManyKeys`, `KeyWritten`, `KeyWriteFailed`
- `ForgettingKey`, `KeyForgotten`, `KeyForgetFailed`
- `CacheFlushing`, `CacheFlushed`

`RetrievingKey` is dispatched before the underlying read.
Each event includes the store name. Single-key events also include the key,
write events expose the value and TTL where applicable, and bulk events expose
their keys; `WritingManyKeys` also exposes the values. Bulk reads additionally
dispatch `CacheHit` or `CacheMissed` for each returned key.

## Migration from friendsofhyperf/cache

Replace imports:

```diff
- use FriendsOfHyperf\Cache\...;
+ use Goletter\Cache\...;
```

And require `goletter/hyperf-cache` instead of `friendsofhyperf/cache`.

## Reference

The API is inspired by Laravel Cache, but behavior should be verified against
this component's contracts and implementation.
