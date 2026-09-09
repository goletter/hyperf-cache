<?php

declare(strict_types=1);

namespace Goletter\Cache\Event;

abstract class CacheEvent
{
    public function __construct(
        public string $storeName,
        public string $key
    ) {
    }
}
