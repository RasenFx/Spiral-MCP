<?php

namespace SpiralMcp\SPUpdater\WpClient;

use SpiralMcp\SPUpdater\WpClient\Contracts\HttpClientInterface;

/**
 * Thin, typed wrapper over the SPUpdater REST endpoints.
 */
class Api
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $http,
    ) {}

    public function checkUpdate(string $domain, ?string $licenseKey, ?string $wpVersion = null): ApiResponse
    {
        $params = [
            'domain' => $domain,
            'version' => $this->config->pluginVersion,
            'channel' => $this->config->channel,
        ];

        if ($licenseKey !== null && $licenseKey !== '') {
            $params['license_key'] = $licenseKey;
        }

        if ($wpVersion !== null) {
            $params['wp_version'] = $wpVersion;
        }

        $response = $this->http->request('GET', $this->config->updateEndpoint(), $params);

        return ApiResponse::fromHttp($response['status'], $response['body']);
    }

    public function activate(string $licenseKey, string $domain, ?string $wpVersion = null): ApiResponse
    {
        return $this->licenseAction('activate', $licenseKey, $domain, [
            'wp_version' => $wpVersion,
            'plugin_version' => $this->config->pluginVersion,
        ]);
    }

    public function deactivate(string $licenseKey, string $domain): ApiResponse
    {
        return $this->licenseAction('deactivate', $licenseKey, $domain);
    }

    public function validate(string $licenseKey, string $domain): ApiResponse
    {
        return $this->licenseAction('validate', $licenseKey, $domain);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function licenseAction(string $action, string $licenseKey, string $domain, array $extra = []): ApiResponse
    {
        $params = array_filter([
            'license_key' => $licenseKey,
            'product_slug' => $this->config->productSlug,
            'domain' => $domain,
            ...$extra,
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->http->request('POST', $this->config->licenseEndpoint($action), $params);

        return ApiResponse::fromHttp($response['status'], $response['body']);
    }
}
