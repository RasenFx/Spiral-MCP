<?php

namespace SpiralMcp\SPUpdater\WpClient;

use SpiralMcp\SPUpdater\WpClient\Contracts\StoreInterface;

/**
 * Decides whether an update should be offered, caching the server's answer
 * (including "no update") so WordPress admin page loads never hammer the API.
 */
class Updater
{
    public function __construct(
        private readonly Config $config,
        private readonly Api $api,
        private readonly StoreInterface $store,
    ) {}

    /**
     * Update info as served by the API (new_version, package_url, changelog,
     * requires_wp, requires_php, channel) or null when up to date.
     *
     * @return array<string, mixed>|null
     */
    public function getUpdate(string $domain, ?string $licenseKey, ?string $wpVersion = null): ?array
    {
        $cached = $this->store->getCached($this->cacheKey());

        if (is_array($cached) && array_key_exists('update', $cached)) {
            return $this->onlyNewer($cached['update']);
        }

        $response = $this->api->checkUpdate($domain, $licenseKey, $wpVersion);

        // Errors (server down, invalid license…) are not cached as "no
        // update" for the full TTL; retry sooner.
        if (! $response->ok) {
            $this->store->putCached($this->cacheKey(), ['update' => null], min($this->config->cacheTtl, 3600));

            return null;
        }

        $update = ($response->data['update_available'] ?? false) === true
            ? $response->data
            : null;

        $this->store->putCached($this->cacheKey(), ['update' => $update], $this->config->cacheTtl);

        return $this->onlyNewer($update);
    }

    /**
     * Bypass the cache and ask the server right now. Used at download time
     * to obtain a freshly signed package URL, so it does not matter how long
     * the user waited before clicking "Update".
     *
     * @return array<string, mixed>|null
     */
    public function freshUpdate(string $domain, ?string $licenseKey, ?string $wpVersion = null): ?array
    {
        $this->flush();

        return $this->getUpdate($domain, $licenseKey, $wpVersion);
    }

    /**
     * Package URL of the cached answer, without touching the network.
     */
    public function cachedPackageUrl(): ?string
    {
        $cached = $this->store->getCached($this->cacheKey());
        $url = is_array($cached) ? ($cached['update']['package_url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Drop the cached answer (e.g. right after activating a license or when
     * the user clicks "check again").
     */
    public function flush(): void
    {
        $this->store->forgetCached($this->cacheKey());
    }

    /**
     * The server's word alone is not enough to offer an update: a stale cache
     * entry or a server glitch must never re-offer the installed version.
     *
     * @param  array<string, mixed>|null  $update
     * @return array<string, mixed>|null
     */
    private function onlyNewer(?array $update): ?array
    {
        if ($update === null) {
            return null;
        }

        $newVersion = (string) ($update['new_version'] ?? '');

        return $newVersion !== '' && version_compare($newVersion, $this->config->pluginVersion, '>')
            ? $update
            : null;
    }

    private function cacheKey(): string
    {
        // Keyed by installed version so an upgrade starts from a cold cache
        // instead of re-serving the pre-upgrade "update available" answer.
        $version = str_replace('.', '-', $this->config->pluginVersion);

        return "spupdater_update_{$this->config->productSlug}_{$version}";
    }
}
