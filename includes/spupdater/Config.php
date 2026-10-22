<?php

namespace SpiralMcp\SPUpdater\WpClient;

/**
 * Static configuration of one plugin against the SPUpdater server.
 *
 * Readonly properties instead of a readonly class to stay PHP 8.1 compatible.
 */
class Config
{
    public readonly string $serverUrl;

    /**
     * @param  string  $serverUrl  Base URL of the SPUpdater server, e.g. "https://updates.example.com"
     * @param  string  $productSlug  Product slug as registered on the server
     * @param  string  $pluginFile  WordPress plugin basename, e.g. "my-plugin/my-plugin.php"
     * @param  string  $pluginVersion  Currently installed version
     * @param  bool  $isFree  Free products skip the license screen and key entirely
     * @param  string  $channel  "stable" or "beta"
     * @param  int  $cacheTtl  Seconds to cache update checks (default 12 h)
     */
    public function __construct(
        string $serverUrl,
        public readonly string $productSlug,
        public readonly string $pluginFile,
        public readonly string $pluginVersion,
        public readonly bool $isFree = false,
        public readonly string $channel = 'stable',
        public readonly int $cacheTtl = 43200,
    ) {
        $this->serverUrl = rtrim($serverUrl, '/');
    }

    public function updateEndpoint(): string
    {
        return "{$this->serverUrl}/api/v1/products/{$this->productSlug}/update";
    }

    public function licenseEndpoint(string $action): string
    {
        return "{$this->serverUrl}/api/v1/licenses/{$action}";
    }

    /**
     * Directory-style slug WordPress uses in the plugin information popup.
     */
    public function wpSlug(): string
    {
        return explode('/', $this->pluginFile)[0];
    }
}
