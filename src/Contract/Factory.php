<?php

declare(strict_types=1);
/**
 * This file is part of goletter/hyperf-cache.
 */

namespace Goletter\Cache\Contract;

/**
 * @mixin Repository
 */
interface Factory
{
    public function store(?string $name = null): Repository;

    public function driver(?string $name = null): Repository;
}
