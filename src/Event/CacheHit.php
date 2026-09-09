<?php

declare(strict_types=1);

namespace Goletter\Cache\Event;

class CacheHit extends CacheEvent
{
    public function __construct(
        string $storeName,
        string $key,
        public mixed $value
    ) {
        parent::__construct($storeName, $key);
    }
}
