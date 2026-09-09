<?php

declare(strict_types=1);

namespace Goletter\Cache\Event;

class CacheFlushed
{
    public function __construct(public ?string $storeName)
    {
    }
}
