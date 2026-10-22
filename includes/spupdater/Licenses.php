<?php

namespace SpiralMcp\SPUpdater\WpClient;

use SpiralMcp\SPUpdater\WpClient\Contracts\StoreInterface;

/**
 * License key lifecycle on the WordPress side: the key lives in wp_options
 * and only persists once the server has accepted the activation.
 */
class Licenses
{
    public function __construct(
        private readonly Config $config,
        private readonly Api $api,
        private readonly StoreInterface $store,
        private readonly Updater $updater,
    ) {}

    public function storedKey(): ?string
    {
        return $this->store->getOption($this->optionKey());
    }

    public function activate(string $licenseKey, string $domain, ?string $wpVersion = null): ApiResponse
    {
        $response = $this->api->activate($licenseKey, $domain, $wpVersion);

        if ($response->ok) {
            $this->store->putOption($this->optionKey(), $licenseKey);
            $this->updater->flush();
        }

        return $response;
    }

    public function deactivate(string $domain): ApiResponse
    {
        $key = $this->storedKey();

        if ($key === null) {
            return new ApiResponse(ok: false, error: 'no_stored_key');
        }

        $response = $this->api->deactivate($key, $domain);

        if ($response->ok) {
            $this->store->deleteOption($this->optionKey());
            $this->updater->flush();
        }

        return $response;
    }

    public function validate(string $domain): ApiResponse
    {
        $key = $this->storedKey();

        if ($key === null) {
            return new ApiResponse(ok: false, error: 'no_stored_key');
        }

        return $this->api->validate($key, $domain);
    }

    private function optionKey(): string
    {
        return "spupdater_license_{$this->config->productSlug}";
    }
}
