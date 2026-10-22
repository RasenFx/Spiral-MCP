<?php

namespace SpiralMcp\SPUpdater\WpClient\WordPress;

use SpiralMcp\SPUpdater\WpClient\Contracts\StoreInterface;

/**
 * Transients for the TTL cache, wp_options for durable values.
 */
class WpStore implements StoreInterface
{
    public function getCached(string $key): mixed
    {
        $value = get_transient($key);

        return $value === false ? null : $value;
    }

    public function putCached(string $key, mixed $value, int $ttl): void
    {
        set_transient($key, $value, $ttl);
    }

    public function forgetCached(string $key): void
    {
        delete_transient($key);
    }

    public function getOption(string $key): ?string
    {
        $value = get_option($key, null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function putOption(string $key, string $value): void
    {
        update_option($key, $value, autoload: false);
    }

    public function deleteOption(string $key): void
    {
        delete_option($key);
    }
}
