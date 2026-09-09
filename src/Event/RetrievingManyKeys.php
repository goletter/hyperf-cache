<?php

declare(strict_types=1);

namespace Goletter\Cache\Event;

class RetrievingManyKeys extends CacheEvent
{
    public function __construct(
        string $storeName,
        public array $keys
    ) {
        parent::__construct($storeName, (string) ($keys[0] ?? ''));
    }
}
