<?php

namespace SpiralMcp\SPUpdater\WpClient\Contracts;

/**
 * Persistence the client needs from the host platform: a TTL cache
 * (WordPress transients) and durable options (wp_options).
 */
interface StoreInterface
{
    public function getCached(string $key): mixed;

    public function putCached(string $key, mixed $value, int $ttl): void;

    public function forgetCached(string $key): void;

    public function getOption(string $key): ?string;

    public function putOption(string $key, string $value): void;

    public function deleteOption(string $key): void;
}
