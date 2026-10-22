<?php

namespace SpiralMcp\SPUpdater\WpClient\WordPress;

use SpiralMcp\SPUpdater\WpClient\Api;
use SpiralMcp\SPUpdater\WpClient\Config;
use SpiralMcp\SPUpdater\WpClient\Licenses;
use SpiralMcp\SPUpdater\WpClient\Updater;

/**
 * WordPress glue: registers the hooks that surface SPUpdater updates in the
 * standard WP updates UI and, for paid products, the license settings page.
 *
 * Usage from the plugin's main file:
 *
 *     Plugin::register(new Config(
 *         serverUrl: 'https://updates.example.com',
 *         productSlug: 'my-plugin',
 *         pluginFile: plugin_basename(__FILE__),
 *         pluginVersion: '1.2.3',
 *         isFree: true,
 *     ));
 */
class Plugin
{
    private Updater $updater;

    private Licenses $licenses;

    private function __construct(private readonly Config $config)
    {
        $store = new WpStore;
        $api = new Api($config, new WpHttpClient);

        $this->updater = new Updater($config, $api, $store);
        $this->licenses = new Licenses($config, $api, $store, $this->updater);
    }

    public static function register(Config $config): self
    {
        $plugin = new self($config);

        add_filter('pre_set_site_transient_update_plugins', [$plugin, 'injectUpdate']);
        add_filter('plugins_api', [$plugin, 'pluginInformation'], 10, 3);
        add_filter('upgrader_package_options', [$plugin, 'refreshPackageUrl']);
        add_action('upgrader_process_complete', [$plugin, 'flushAfterUpgrade'], 10, 2);

        // "Check again" on the Updates screen must reach the server, not the cache.
        add_action('load-update-core.php', function () use ($plugin) {
            if (isset($_GET['force-check'])) {
                $plugin->updater->flush();
            }
        });

        if (! $config->isFree) {
            add_action('admin_menu', [$plugin, 'registerLicensePage']);
            add_action('admin_post_spupdater_'.$config->productSlug, [$plugin, 'handleLicenseForm']);
        }

        return $plugin;
    }

    /**
     * Hook: upgrader_process_complete — after this plugin is updated the
     * cached "update available" answer refers to the version just replaced.
     */
    public function flushAfterUpgrade(mixed $upgrader, mixed $hookExtra): void
    {
        if (! is_array($hookExtra)
            || ($hookExtra['type'] ?? '') !== 'plugin'
            || ($hookExtra['action'] ?? '') !== 'update'
        ) {
            return;
        }

        $updated = (array) ($hookExtra['plugins'] ?? []);

        if (($hookExtra['plugin'] ?? '') === $this->config->pluginFile
            || in_array($this->config->pluginFile, $updated, true)
        ) {
            $this->updater->flush();
        }
    }

    /**
     * Hook: pre_set_site_transient_update_plugins.
     */
    public function injectUpdate(mixed $transient): mixed
    {
        if (! is_object($transient)) {
            return $transient;
        }

        $update = $this->updater->getUpdate($this->domain(), $this->licenseKey(), get_bloginfo('version'));

        if ($update === null) {
            // Tell WP explicitly that the plugin is up to date (auto-update UI).
            $transient->no_update[$this->config->pluginFile] = (object) [
                'slug' => $this->config->wpSlug(),
                'plugin' => $this->config->pluginFile,
                'new_version' => $this->config->pluginVersion,
                'url' => '',
                'package' => '',
            ];

            return $transient;
        }

        $transient->response[$this->config->pluginFile] = (object) [
            'slug' => $this->config->wpSlug(),
            'plugin' => $this->config->pluginFile,
            'new_version' => $update['new_version'] ?? '',
            'package' => $update['package_url'] ?? '',
            'url' => '',
            'requires' => $update['requires_wp'] ?? '',
            'requires_php' => $update['requires_php'] ?? '',
        ];

        return $transient;
    }

    /**
     * Hook: upgrader_package_options — runs right before WordPress downloads
     * the package. Swaps in a freshly signed URL so the cached one may be as
     * old as it wants (sites with broken WP-Cron included). On any failure
     * the original package is left untouched: trying the cached URL beats
     * breaking the download.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function refreshPackageUrl(array $options): array
    {
        if (! $this->ownsPackage($options)) {
            return $options;
        }

        $update = $this->updater->freshUpdate($this->domain(), $this->licenseKey(), get_bloginfo('version'));

        if (is_array($update) && ! empty($update['package_url'])) {
            $options['package'] = (string) $update['package_url'];
        }

        return $options;
    }

    /**
     * Whether the package about to be downloaded is this plugin's. WordPress
     * names the plugin being updated in hook_extra; only when it does not is
     * the package URL compared with the cached one. A bare server prefix is
     * not enough: other plugins can be served by the same SPUpdater server.
     *
     * @param  array<string, mixed>  $options
     */
    private function ownsPackage(array $options): bool
    {
        $hookPlugin = (string) ($options['hook_extra']['plugin'] ?? '');

        if ($hookPlugin !== '') {
            return $hookPlugin === $this->config->pluginFile;
        }

        $cached = $this->updater->cachedPackageUrl();
        $package = (string) ($options['package'] ?? '');

        return $cached !== null && $package !== ''
            && strtok($cached, '?') === strtok($package, '?');
    }

    /**
     * Hook: plugins_api — fills the "View details" popup with the changelog.
     */
    public function pluginInformation(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->config->wpSlug()) {
            return $result;
        }

        $update = $this->updater->getUpdate($this->domain(), $this->licenseKey(), get_bloginfo('version'));

        if ($update === null) {
            return $result;
        }

        return (object) [
            'name' => $this->config->wpSlug(),
            'slug' => $this->config->wpSlug(),
            'version' => $update['new_version'] ?? '',
            'requires' => $update['requires_wp'] ?? '',
            'requires_php' => $update['requires_php'] ?? '',
            'download_link' => $update['package_url'] ?? '',
            'sections' => [
                'changelog' => nl2br(esc_html((string) ($update['changelog'] ?? ''))),
            ],
        ];
    }

    /**
     * Hook: admin_menu — "Licencia" screen under Settings (paid products).
     */
    public function registerLicensePage(): void
    {
        add_options_page(
            'Licencia',
            'Licencia — '.$this->config->wpSlug(),
            'manage_options',
            'spupdater-'.$this->config->productSlug,
            [$this, 'renderLicensePage'],
        );
    }

    public function renderLicensePage(): void
    {
        $key = $this->licenses->storedKey();
        $action = 'spupdater_'.$this->config->productSlug;

        echo '<div class="wrap"><h1>Licencia</h1>';

        if (isset($_GET['spupdater_status'])) {
            $status = sanitize_text_field((string) $_GET['spupdater_status']);
            $isError = $status !== 'ok';
            printf(
                '<div class="notice %s"><p>%s</p></div>',
                $isError ? 'notice-error' : 'notice-success',
                esc_html($isError ? "Error: {$status}" : 'Operación completada.'),
            );
        }

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr($action).'">';
        wp_nonce_field($action);

        if ($key === null) {
            echo '<p><input type="text" name="license_key" class="regular-text" placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX" required></p>';
            echo '<p><button class="button button-primary" name="spupdater_action" value="activate">Activar licencia</button></p>';
        } else {
            echo '<p>Licencia activa en este sitio.</p>';
            echo '<p><button class="button" name="spupdater_action" value="deactivate">Desactivar en este sitio</button></p>';
        }

        echo '</form></div>';
    }

    /**
     * Hook: admin_post_spupdater_{slug} — processes the license form.
     */
    public function handleLicenseForm(): void
    {
        $action = 'spupdater_'.$this->config->productSlug;

        if (! current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }

        check_admin_referer($action);

        $operation = sanitize_text_field((string) ($_POST['spupdater_action'] ?? ''));

        $response = $operation === 'deactivate'
            ? $this->licenses->deactivate($this->domain())
            : $this->licenses->activate(
                sanitize_text_field((string) ($_POST['license_key'] ?? '')),
                $this->domain(),
                get_bloginfo('version'),
            );

        $status = $response->ok ? 'ok' : ($response->error ?? 'unknown_error');

        wp_safe_redirect(add_query_arg(
            ['page' => 'spupdater-'.$this->config->productSlug, 'spupdater_status' => $status],
            admin_url('options-general.php'),
        ));
        exit;
    }

    private function domain(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    private function licenseKey(): ?string
    {
        return $this->config->isFree ? null : $this->licenses->storedKey();
    }
}
