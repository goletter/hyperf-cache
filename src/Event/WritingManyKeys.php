<?php

declare(strict_types=1);

namespace Goletter\Cache\Event;

class WritingManyKeys extends CacheEvent
{
    public function __construct(
        string $storeName,
        public array $keys,
        public array $values,
        public ?int $seconds = null
    ) {
        parent::__construct($storeName, (string) ($keys[0] ?? ''));
    }
}
