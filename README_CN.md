# Cache

[English](README.md)

## 简介

`goletter/hyperf-cache` 使用 Laravel 风格的仓库 API 封装 `hyperf/cache`
提供的驱动。支持 PSR-16、命名存储、门面、`cache()` 助手函数、Redis 分布式锁、
缓存事件、宏以及 stale-while-revalidate。

命名空间：`Goletter\Cache`。

## 安装

```shell
composer require goletter/hyperf-cache
```

要求 Hyperf 3.1 或 3.2。`ConfigProvider` 会注册
`Goletter\Cache\Contract\Factory`、
`Goletter\Cache\Contract\Repository` 与
`Goletter\Cache\Lock\LockManager`。

使用锁（以及 `flexible()` 刷新互斥）时需安装 Redis：

```shell
composer require hyperf/redis
```

## 配置

不发布独立配置。通过 `hyperf/cache` 配置驱动与命名存储；
`CacheManager::store($name)` 将名称交给 Hyperf 缓存管理器。默认仓库使用
`default`。

## 获取仓库

### 依赖注入

注入 `Contract\Repository` 使用默认存储：

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

注入 `Contract\Factory` 可取命名存储；对工厂的方法调用会转发到默认存储
（如 `$factory->remember(...)`）：

```php
use Goletter\Cache\Contract\Factory;

$cache = $factory->store('redis');
```

### 助手函数

```php
cache();                        // 默认 Repository
cache('users');                 // get
cache(['users' => $users], 60); // putMany
```

### 门面

```php
use Goletter\Cache\Facade\Cache;

$users = Cache::remember('users', 60, function () {
    return [];
});

$users = Cache::store('redis')->get('users');
```

`Cache::driver($name)` 是 `Cache::store($name)` 的别名。
`Cache::resolve($name)` 会新建仓库，不复用管理器缓存实例。
`Cache::purge($name)` 可丢弃已缓存的仓库实例。

## 核心操作

仓库实现了 `Psr\SimpleCache\CacheInterface`，另有：

| 方法 | 行为 |
| --- | --- |
| `get($key, $default = null)` | 获取；未命中时执行可调用默认值。传入数组转 `many()`。 |
| `put($key, $value, $ttl = null)` | 写入；`null` TTL 永久；非正 TTL 删除。关联数组转 `putMany()`。 |
| `putMany($values, $ttl = null)` | 批量写入。 |
| `forever($key, $value)` | 永久写入。 |
| `add($key, $value, $ttl = null)` | 仅当 `get($key)` 为 `null` 时写入。 |
| `many($keys)` | 批量读取；关联数组可为每键提供默认值。 |
| `pull($key, $default = null)` | 读取（含默认值）后删除。 |
| `remember($key, $ttl, Closure $callback)` | 有缓存则返回，否则写入回调结果。 |
| `rememberForever` / `sear` | 永久版 remember。 |
| `increment` / `decrement` | 优先用驱动 `increment`；否则读改写。 |
| `flush` | `clear` 别名。 |
| `missing` | `has` 取反。 |
| `getDriver` / `getStore` | 底层 `DriverInterface`。 |

TTL 可为秒、`DateInterval` 或 `DateTimeInterface`。

::: warning 行为边界
缓存的 `null` 视为未命中。`add()` 非原子。`increment` / `decrement` 在驱动无
原生递增时也非原子。
:::

## Stale-While-Revalidate

`flexible()` 的 TTL 数组：`[新鲜期, 存储 TTL]`。

```php
use Goletter\Cache\Facade\Cache;

$users = Cache::flexible('users', [30, 300], function () {
    return [];
}, [
    'seconds' => 10,
    'owner' => 'users-refresh',
]);
```

未命中时立即执行回调；新鲜期内直接返回；过期后返回旧值并在后台刷新。
优先使用包内 Redis 锁做刷新互斥；`friendsofhyperf/lock` 为可选回退。
无锁仍会刷新（无互斥），无 `defer` 则同步刷新。

```shell
composer require hyperf/redis hyperf/coroutine
```

可选锁参数 `seconds` / `owner`；内置锁默认 `seconds = 10`（强制正 TTL，避免死锁）。

### `remember` 防击穿

第 4 个参数传 `true` 或锁选项，缓存填充会串行化：

```php
$users = Cache::remember('users', 60, function () {
    return [];
}, true);

$users = Cache::remember('users', 60, function () {
    return [];
}, ['seconds' => 10, 'wait' => 5]);
```

无 Redis 时忽略锁参数，行为与不加锁的 `remember` 相同。

## 分布式锁

依赖 `hyperf/redis`。API 对齐 Laravel / `friendsofhyperf/lock`：

```php
use Goletter\Cache\Exception\LockTimeoutException;
use Goletter\Cache\Facade\Cache;
use function lock;

$lock = lock('foo', 10);
if ($lock->get()) {
    try {
        // 临界区
    } finally {
        $lock->release();
    }
}

lock('foo', 10)->get(function () {
    // 自动释放
});

try {
    lock('foo', 10)->block(5, function () {});
} catch (LockTimeoutException $e) {
    // 等待超时
}

Cache::lock('foo', 10)->get(function () {});

// 不等待，拿不到立刻失败（例如挡连点）
if (! lock($lockKey, 10)->get()) {
    // 系统繁忙 / 请勿重复操作
}
```

锁 key 前缀为 `goletter:cache:lock:`；`release()` 仅在 owner 匹配时删除。

`refresh(?int $ttl = null)` 可续期；`isExpired()` / `getRemainingTime()` 跟踪本地生命周期（`seconds = 0` 表示不过期，建议始终使用正 TTL）。

## 事件

容器提供 `EventDispatcherInterface` 时会分发：

- `CacheHit`、`CacheMissed`、`RetrievingKey`、`RetrievingManyKeys`
- `WritingKey`、`WritingManyKeys`、`KeyWritten`、`KeyWriteFailed`
- `ForgettingKey`、`KeyForgotten`、`KeyForgetFailed`
- `CacheFlushing`、`CacheFlushed`

`RetrievingKey` 在真正读取之前触发。

## 从 friendsofhyperf/cache 迁移

```diff
- use FriendsOfHyperf\Cache\...;
+ use Goletter\Cache\...;
```

依赖改为 `goletter/hyperf-cache`。

## 参考

API 受 Laravel Cache 启发，以本组件契约与实现为准。
