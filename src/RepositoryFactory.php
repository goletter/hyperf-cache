<?php

declare(strict_types=1);

namespace Goletter\Cache;

use Psr\Container\ContainerInterface;

/**
 * @internal
 */
class RepositoryFactory
{
    public function __invoke(ContainerInterface $container): Contract\Repository
    {
        return $container->get(CacheManager::class)->store();
    }
}
