<?php

declare(strict_types=1);
/**
 * This file is part of goletter/hyperf-cache.
 */

use Goletter\Cache\Contract\Factory;
use Goletter\Cache\Contract\Repository;
use Hyperf\Context\ApplicationContext;

if (! function_exists('cache')) {
    /**
     * @param  null|array|string  $key
     * @param  mixed  $default
     * @return ($key is null ? Repository : ($key is array ? bool : mixed))
     */
    function cache(null|array|string $key = null, mixed $default = null): mixed
    {
        $repository = ApplicationContext::getContainer()->get(Factory::class)->store();

        if ($key === null) {
            return $repository;
        }

        if (is_array($key)) {
            return $repository->putMany($key, $default);
        }

        return $repository->get($key, $default);
    }
}
