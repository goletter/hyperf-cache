<?php

declare(strict_types=1);

namespace Goletter\Cache\Event;

class KeyWritten extends CacheEvent
{
    public function __construct(
        string $storeName,
        string $key,
        public mixed $value,
        public ?int $seconds = null
    ) {
        parent::__construct($storeName, $key);
    }
}
