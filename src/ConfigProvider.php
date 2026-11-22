<?php

declare(strict_types=1);
/**
 * This file is part of goletter/hyperf-cache.
 */

namespace Goletter\Cache;

use Goletter\Cache\Lock\LockManager;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Contract\Factory::class => fn ($container) => $container->get(CacheManager::class),
                Contract\Repository::class => RepositoryFactory::class,
                LockManager::class => LockManager::class,
            ],
        ];
    }
}
